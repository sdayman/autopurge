<?php
/**
 * Plugin Name: AutoPurge
 * Description: Auto-purges Cloudflare when posts change or plugin/theme/core updates. Includes dashboard tool for manual purges (Everything, URLs, or Cache Tags).
 * Version:     1.4.0
 * Author:      Scott Dayman
 * License:     GPL-2.0-or-later
 */

/* ---------- CONFIG ---------- *
 * Put these in wp-config.php:
 *
 * define( 'CF_API_TOKEN', 'PUT_YOUR_TOKEN_HERE' );
 * define( 'CF_ZONE_ID',   'PUT_YOUR_ZONE_ID_HERE' );
 *
 * Set these if you want debugging output to wp-content/debug.log:
 *
 * define( 'WP_DEBUG', true );
 * define( 'WP_DEBUG_LOG', true );
 * define( 'WP_DEBUG_DISPLAY', false ); // Optional, hides on-screen errors
 * -------------------------------- */

if (!defined("ABSPATH")) {
    exit();
}

// Cloudflare API limits
define("PUC_CF_BATCH_LIMIT", 30);

/* ===== 1.  AUTOMATIC PURGES ======================================= */
add_action("save_post", "puc_collect_urls_for_purge", 10, 3);
add_action("wp_trash_post", "puc_collect_urls_for_purge", 10, 2);
add_action("before_delete_post", "puc_collect_urls_for_purge", 10, 2);

function puc_collect_urls_for_purge($post_id, $post = null, $update = true)
{
    if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
        return;
    }
    $post = $post ?: get_post($post_id);
    if (!$post || !is_post_type_viewable($post->post_type)) {
        return;
    }

    // Debounce: collect URLs in transient, purge on shutdown
    $transient_key = "puc_pending_urls";
    $pending = get_transient($transient_key) ?: [];

    $urls = [get_permalink($post_id), home_url("/"), get_feed_link()];

    if ($archive = get_post_type_archive_link($post->post_type)) {
        $urls[] = $archive;
        // Add paginated archive URLs
        $urls = array_merge($urls, puc_get_paginated_urls($archive));
    }

    foreach (get_object_taxonomies($post->post_type) as $tax) {
        $terms = wp_get_post_terms($post_id, $tax);
        if (is_wp_error($terms)) {
            continue;
        }
        foreach ($terms as $term) {
            $term_link = get_term_link($term);
            if (!is_wp_error($term_link)) {
                $urls[] = $term_link;
                // Add paginated term URLs
                $urls = array_merge($urls, puc_get_paginated_urls($term_link));
            }
            $urls[] = get_term_feed_link($term->term_id, $tax);
        }
    }

    $urls[] = get_author_posts_url($post->post_author);
    $urls[] = get_author_feed_link($post->post_author);

    $t = strtotime($post->post_date_gmt ?: $post->post_date);
    $year_link = get_year_link(gmdate("Y", $t));
    $month_link = get_month_link(gmdate("Y", $t), gmdate("m", $t));
    $day_link = get_day_link(gmdate("Y", $t), gmdate("m", $t), gmdate("d", $t));
    $urls[] = $year_link;
    $urls[] = $month_link;
    $urls[] = $day_link;
    // Add paginated date archive URLs
    $urls = array_merge($urls, puc_get_paginated_urls($year_link));
    $urls = array_merge($urls, puc_get_paginated_urls($month_link));

    // Merge with pending URLs and store
    $pending = array_unique(array_merge($pending, $urls));
    set_transient($transient_key, $pending, 60);

    // Schedule purge on shutdown (runs once even with multiple saves)
    if (!has_action("shutdown", "puc_flush_pending_urls")) {
        add_action("shutdown", "puc_flush_pending_urls");
    }
}

function puc_get_paginated_urls($base_url, $max_pages = 5)
{
    $urls = [];
    for ($i = 2; $i <= $max_pages; $i++) {
        $urls[] = trailingslashit($base_url) . "page/" . $i . "/";
    }
    return $urls;
}

function puc_flush_pending_urls()
{
    $transient_key = "puc_pending_urls";
    $urls = get_transient($transient_key);
    if ($urls && is_array($urls)) {
        delete_transient($transient_key);
        // Filter out any non-string values (WP_Error objects, etc.)
        $urls = array_filter($urls, "is_string");
        puc_purge_urls(array_unique($urls));
    }
}

/* ===== 2.  MANUAL PURGE DASHBOARD PAGE ========================= */
add_action("admin_menu", function () {
    add_management_page(
        "AutoPurge Cache",
        "AutoPurge Cache",
        "manage_options",
        "puc-cloudflare",
        "puc_render_admin_page"
    );
});

function puc_render_admin_page()
{
    if (!current_user_can("manage_options")) {
        wp_die(__("Cheatin’ huh?"));
    }

    // Handle form submit
    if (isset($_POST["puc_action"]) && check_admin_referer("puc_purge")) {
        $notice_type = "success";
        switch ($_POST["puc_action"]) {
            case "purge_everything":
                puc_purge_everything();
                $notice = "Cloudflare "Purge Everything" request sent.";
                error_log(
                    'AutoPurge: Manual "Purge Everything" triggered from dashboard.'
                );
                break;

            case "purge_urls":
                $raw = sanitize_textarea_field(
                    wp_unslash($_POST["puc_urls"] ?? "")
                );
                $urls = array_filter(
                    array_map("trim", preg_split("/\R+/", $raw)),
                    function ($url) {
                        return filter_var($url, FILTER_VALIDATE_URL) !== false;
                    }
                );
                if ($urls) {
                    puc_purge_urls($urls);
                    error_log(
                        "AutoPurge: Manual URL purge triggered: " .
                            implode(", ", $urls)
                    );
                    $notice = sprintf(
                        "%d URL(s) sent for purge.",
                        count($urls)
                    );
                } else {
                    $notice = "No valid URLs found.";
                    $notice_type = "warning";
                }
                break;

            case "purge_cachetags":
                $raw = sanitize_textarea_field(
                    wp_unslash($_POST["puc_cachetags"] ?? "")
                );
                $cachetags = array_filter(
                    array_map("trim", preg_split("/\R+/", $raw))
                );
                if ($cachetags) {
                    puc_purge_cachetags($cachetags);
                    error_log(
                        "AutoPurge: Manual cache tag purge triggered: " .
                            implode(", ", $cachetags)
                    );
                    $notice = sprintf(
                        "%d cachetag(s) sent for purge.",
                        count($cachetags)
                    );
                } else {
                    $notice = "No valid cache tags found.";
                    $notice_type = "warning";
                }
                break;
        }
        echo '<div class="notice notice-' . esc_attr($notice_type) . '"><p>' .
            esc_html($notice) .
            "</p></div>";
    }
    ?>
	<div class="wrap">
		<h1>AutoPurge Cache</h1>
		<form method="post">
			<?php wp_nonce_field("puc_purge"); ?>

			<h2>Purge Everything</h2>
			<p><button class="button button-primary" name="puc_action" value="purge_everything">
				Purge Entire Cache
			</button></p>

			<hr>

			<h2>Purge Specific URLs</h2>
			<p>Enter one absolute URL per line.</p>
			<textarea name="puc_urls" rows="6" style="width: 100%;"></textarea>
			<p><button class="button" name="puc_action" value="purge_urls">Purge Listed URLs</button></p>

			<hr>

			<h2>Purge by Cache Tag</h2>
			<p>Enter one URL cache tag per line (File extensions without leading dot, "html", or "home").</p>
			<textarea name="puc_cachetags" rows="6" style="width: 100%;"></textarea>
			<p><button class="button" name="puc_action" value="purge_cachetags">Purge Listed Cache Tags</button></p>
		</form>
	</div>
	<?php
}

/* ===== 3.  CLOUDFLARE API HELPERS =============================== */
function puc_cf_request(array $payload)
{
    $token = defined("CF_API_TOKEN") ? CF_API_TOKEN : "";
    $zone_id = defined("CF_ZONE_ID") ? CF_ZONE_ID : "";
    if (!$token || !$zone_id) {
        error_log("AutoPurge: CF_API_TOKEN or CF_ZONE_ID not defined.");
        return false;
    }

    if (defined("WP_DEBUG") && WP_DEBUG) {
        error_log("AutoPurge payload: " . wp_json_encode($payload));
    }

    $response = wp_remote_post(
        "https://api.cloudflare.com/client/v4/zones/{$zone_id}/purge_cache",
        [
            "headers" => [
                "Authorization" => "Bearer {$token}",
                "Content-Type" => "application/json",
            ],
            "body" => wp_json_encode($payload),
            "timeout" => 15,
        ]
    );

    if (is_wp_error($response)) {
        error_log("AutoPurge error: " . $response->get_error_message());
        return false;
    } elseif (200 !== wp_remote_retrieve_response_code($response)) {
        error_log("AutoPurge error: " . wp_remote_retrieve_body($response));
        return false;
    }

    return true;
}

function puc_purge_everything()
{
    return puc_cf_request(["purge_everything" => true]);
}

function puc_purge_urls(array $urls)
{
    $urls = array_values($urls);
    $success = true;

    // Cloudflare limits to 30 URLs per request
    foreach (array_chunk($urls, PUC_CF_BATCH_LIMIT) as $batch) {
        if (!puc_cf_request(["files" => $batch])) {
            $success = false;
        }
    }

    return $success;
}

function puc_purge_cachetags(array $cachetags)
{
    $cachetags = array_values($cachetags);
    $success = true;

    // Cloudflare limits to 30 tags per request
    foreach (array_chunk($cachetags, PUC_CF_BATCH_LIMIT) as $batch) {
        if (!puc_cf_request(["tags" => $batch])) {
            $success = false;
        }
    }

    return $success;
}

add_action(
    "upgrader_process_complete",
    function ($upgrader_object, $options) {
        $type = $options["type"] ?? "";
        $action = $options["action"] ?? "";

        if (
            $action === "update" &&
            in_array($type, ["plugin", "theme", "core"], true)
        ) {
            puc_purge_cachetags(["html"]);
            error_log("AutoPurge: Cache tag 'html' purged due to $type update.");
        }
    },
    10,
    2
);
