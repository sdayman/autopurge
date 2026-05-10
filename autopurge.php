<?php
/**
 * Plugin Name: AutoPurge
 * Plugin URI:  https://github.com/scottdayman/autopurge
 * Description: Automatically purges Cloudflare cache when WordPress content changes. Emits a Cache-Tag response header so related content (term archives, paginations, feeds) is purged with a single API call. Includes a manual purge dashboard (Everything, URLs, Tags, Prefixes).
 * Version:     2.0.0
 * Author:      Scott Dayman
 * Author URI:  https://github.com/scottdayman
 * Requires at least: 5.5
 * Requires PHP: 7.4
 * License:     GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: autopurge
 */

/* ---------- SETUP ----------
 * No wp-config.php changes are required. After activating, visit
 * Tools -> AutoPurge Cache and paste a Cloudflare API token. The plugin
 * verifies the token and auto-detects the zone ID for this site's host.
 *
 * Create a token with both permissions on the target zone:
 *   - Zone -> Zone -> Read    (used once, to detect the zone ID)
 *   - Zone -> Cache Purge -> Purge
 *
 * Optional debug logging to wp-content/debug.log:
 *   define( 'WP_DEBUG', true );
 *   define( 'WP_DEBUG_LOG', true );
 *   define( 'WP_DEBUG_DISPLAY', false );
 *
 * Backward compatibility: if CF_API_TOKEN and CF_ZONE_ID are defined as
 * constants, they are used instead of stored credentials.
 * --------------------------- */

if (!defined('ABSPATH')) {
    exit;
}

const AUTOPURGE_VERSION         = '2.0.0';
const AUTOPURGE_OPTIONS_KEY     = 'autopurge_options';
const AUTOPURGE_CREDS_KEY       = 'autopurge_credentials';
const AUTOPURGE_QUEUE_KEY       = 'autopurge_queue';
const AUTOPURGE_BATCH_LIMIT     = 100; // Cloudflare: max ops per request
const AUTOPURGE_PREFIX_LIMIT    = 30;  // Cloudflare: max prefixes per request
const AUTOPURGE_CF_API          = 'https://api.cloudflare.com/client/v4';

/* ===== OPTIONS ==================================================== */

function autopurge_default_options() {
    return [
        'auto_purge'    => true,
        'edit_mode'     => 'smart',  // smart | wide
        'comment_purge' => true,
        'wide_mode'     => false,    // purge_everything on every change
        'tag_prefix'    => '',
    ];
}

function autopurge_get_option($key) {
    static $opts = null;
    if ($opts === null) {
        $opts = wp_parse_args(get_option(AUTOPURGE_OPTIONS_KEY, []), autopurge_default_options());
    }
    return $opts[$key] ?? null;
}

function autopurge_sanitize_options($input) {
    $defaults = autopurge_default_options();
    $out = [];
    $out['auto_purge']    = !empty($input['auto_purge']);
    $out['comment_purge'] = !empty($input['comment_purge']);
    $out['wide_mode']     = !empty($input['wide_mode']);
    $mode = $input['edit_mode'] ?? '';
    $out['edit_mode']     = in_array($mode, ['smart', 'wide'], true) ? $mode : $defaults['edit_mode'];
    $out['tag_prefix']    = preg_replace('/[^a-z0-9_\-]/', '', strtolower((string)($input['tag_prefix'] ?? '')));
    return $out;
}

/* ===== CREDENTIALS ================================================ */

function autopurge_get_credentials() {
    // Constants in wp-config.php take precedence (backward compatibility).
    if (defined('CF_API_TOKEN') && defined('CF_ZONE_ID') && CF_API_TOKEN && CF_ZONE_ID) {
        return [
            'token'       => (string) CF_API_TOKEN,
            'zone_id'     => (string) CF_ZONE_ID,
            'zone_name'   => '',
            'token_last4' => substr((string) CF_API_TOKEN, -4),
            'verified_at' => 0,
            'source'      => 'constant',
        ];
    }
    $stored = get_option(AUTOPURGE_CREDS_KEY, []);
    if (!is_array($stored) || empty($stored['token']) || empty($stored['zone_id'])) {
        return null;
    }
    $stored['source'] = 'option';
    return $stored;
}

function autopurge_save_credentials($token, $zone_id, $zone_name = '') {
    $payload = [
        'token'       => (string) $token,
        'zone_id'     => (string) $zone_id,
        'zone_name'   => (string) $zone_name,
        'token_last4' => substr((string) $token, -4),
        'verified_at' => time(),
    ];
    update_option(AUTOPURGE_CREDS_KEY, $payload, false);
    return $payload;
}

function autopurge_clear_credentials() {
    delete_option(AUTOPURGE_CREDS_KEY);
}

function autopurge_verify_token($token) {
    $token = trim((string) $token);
    if ($token === '') {
        return new WP_Error('autopurge_empty_token', 'Token is empty.');
    }
    $resp = wp_remote_get(AUTOPURGE_CF_API . '/user/tokens/verify', [
        'headers' => [
            'Authorization' => "Bearer {$token}",
            'Content-Type'  => 'application/json',
        ],
        'timeout' => 15,
    ]);
    if (is_wp_error($resp)) {
        return $resp;
    }
    $code = wp_remote_retrieve_response_code($resp);
    $body = json_decode(wp_remote_retrieve_body($resp), true);
    if ($code !== 200 || empty($body['success'])) {
        $msg = isset($body['errors'][0]['message']) ? $body['errors'][0]['message'] : "HTTP {$code}";
        return new WP_Error('autopurge_token_invalid', 'Token verification failed: ' . $msg);
    }
    $status = $body['result']['status'] ?? '';
    if ($status !== 'active') {
        return new WP_Error('autopurge_token_inactive', 'Token status is not active: ' . $status);
    }
    return true;
}

function autopurge_resolve_zone($token, $host = '') {
    $token = trim((string) $token);
    if ($host === '') {
        $host = parse_url(home_url('/'), PHP_URL_HOST);
    }
    if (!$host) {
        return new WP_Error('autopurge_no_host', 'Could not determine site host.');
    }
    $host = strtolower($host);

    // Walk subdomain hierarchy: blog.example.co.uk -> example.co.uk -> co.uk.
    // Cloudflare returns 0 results for non-zone names, so walking past root is harmless.
    $candidates = [];
    $parts = explode('.', $host);
    while (count($parts) >= 2) {
        $candidates[] = implode('.', $parts);
        array_shift($parts);
    }

    foreach ($candidates as $name) {
        $resp = wp_remote_get(AUTOPURGE_CF_API . '/zones?' . http_build_query(['name' => $name, 'status' => 'active']), [
            'headers' => [
                'Authorization' => "Bearer {$token}",
                'Content-Type'  => 'application/json',
            ],
            'timeout' => 15,
        ]);
        if (is_wp_error($resp)) {
            return $resp;
        }
        $code = wp_remote_retrieve_response_code($resp);
        $body = json_decode(wp_remote_retrieve_body($resp), true);
        if ($code !== 200 || empty($body['success'])) {
            $msg = isset($body['errors'][0]['message']) ? $body['errors'][0]['message'] : "HTTP {$code}";
            return new WP_Error('autopurge_zone_lookup_failed', 'Zone lookup failed: ' . $msg);
        }
        if (!empty($body['result']) && is_array($body['result'])) {
            foreach ($body['result'] as $zone) {
                if (!empty($zone['id']) && !empty($zone['name'])) {
                    return ['zone_id' => $zone['id'], 'zone_name' => $zone['name']];
                }
            }
        }
    }
    return new WP_Error('autopurge_zone_not_found', sprintf('No active zone found in this Cloudflare account for %s. Verify the token has access and the domain is added to Cloudflare.', $host));
}

/* ===== CACHE-TAG RESPONSE HEADER ================================== */

add_action('template_redirect', 'autopurge_emit_cache_tag_header', 1);

function autopurge_emit_cache_tag_header() {
    if (headers_sent()) {
        return;
    }
    if (is_admin()) {
        return;
    }
    if (defined('DOING_AJAX') && DOING_AJAX) {
        return;
    }
    if (defined('REST_REQUEST') && REST_REQUEST) {
        return;
    }
    if (is_user_logged_in()) {
        return;
    }

    $tags = autopurge_compute_request_tags();
    if (!$tags) {
        return;
    }

    $tags = apply_filters('autopurge_response_tags', $tags);
    $tags = autopurge_normalize_tags($tags);
    if (!$tags) {
        return;
    }

    header('Cache-Tag: ' . implode(',', $tags), false);
}

function autopurge_compute_request_tags() {
    if (is_search() || is_404()) {
        return [];
    }

    $tags = [];

    if (is_feed()) {
        $tags[] = 'feed';
    } else {
        $tags[] = 'html';
    }

    if (is_front_page() || is_home()) {
        $tags[] = 'home';
    }

    if (is_singular()) {
        $oid = get_queried_object_id();
        if ($oid) {
            $tags[] = 'post-' . $oid;
        }
    }

    if (is_category() || is_tag() || is_tax()) {
        $term = get_queried_object();
        if ($term && !empty($term->term_id)) {
            $tags[] = 'term-' . $term->taxonomy . '-' . $term->term_id;
        }
    }

    if (is_post_type_archive()) {
        $pt = get_query_var('post_type');
        if (is_array($pt)) {
            $pt = reset($pt);
        }
        if ($pt) {
            $tags[] = 'post_type-' . $pt;
        }
    }

    if (is_author()) {
        $author = get_queried_object();
        if ($author && !empty($author->ID)) {
            $tags[] = 'author-' . $author->ID;
        }
    }

    if (is_year() || is_month() || is_day()) {
        $year     = (int) get_query_var('year');
        $monthnum = (int) get_query_var('monthnum');
        $day      = (int) get_query_var('day');
        if ($year) {
            $tags[] = sprintf('date-%04d', $year);
        }
        if ($year && $monthnum) {
            $tags[] = sprintf('date-%04d-%02d', $year, $monthnum);
        }
        if ($year && $monthnum && $day) {
            $tags[] = sprintf('date-%04d-%02d-%02d', $year, $monthnum, $day);
        }
    }

    if (is_attachment()) {
        $tags[] = 'attachment-' . get_queried_object_id();
    }

    return $tags;
}

function autopurge_normalize_tags(array $tags) {
    $prefix = (string) autopurge_get_option('tag_prefix');
    $out = [];
    foreach ($tags as $t) {
        if (!is_string($t) || $t === '') {
            continue;
        }
        $t = preg_replace('/\s+/', '-', $t);
        $t = preg_replace('/[^\x21-\x7E]/', '', $t); // printable ASCII
        $t = str_replace(',', '', $t);                // comma is the separator
        $t = strtolower($t);
        if ($t === '') {
            continue;
        }
        if ($prefix !== '') {
            $t = $prefix . $t;
        }
        $out[] = substr($t, 0, 1024);
    }
    return array_values(array_unique(array_filter($out)));
}

/* ===== HOOK REGISTRATION ========================================== */

add_action('plugins_loaded', 'autopurge_register_hooks');

function autopurge_register_hooks() {
    if (autopurge_get_option('auto_purge')) {
        add_action('set_object_terms',     'autopurge_capture_old_terms',         10, 6);
        add_action('post_updated',         'autopurge_handle_post_updated',       10, 3);
        add_action('transition_post_status', 'autopurge_handle_transition',       10, 3);
        add_action('before_delete_post',   'autopurge_handle_delete',             10, 2);
        add_action('delete_attachment',    'autopurge_handle_attachment_delete',  10, 1);
        add_action('updated_post_meta',    'autopurge_handle_meta_update',        10, 4);
        add_action('switch_theme',         'autopurge_purge_html_tag');
        add_action('customize_save_after', 'autopurge_purge_html_tag');

        if (autopurge_get_option('comment_purge')) {
            add_action('comment_post',              'autopurge_handle_new_comment',         10, 2);
            add_action('transition_comment_status', 'autopurge_handle_comment_transition',  10, 3);
        }
    }

    add_action('upgrader_process_complete', 'autopurge_handle_upgrader', 10, 2);
}

/* ===== HOOK HANDLERS ============================================== */

function autopurge_capture_old_terms($object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids) {
    if (!is_array($old_tt_ids)) {
        return;
    }
    $changed = array_merge(array_diff($old_tt_ids, $tt_ids), array_diff($tt_ids, $old_tt_ids));
    if (!$changed) {
        return;
    }

    if (!isset($GLOBALS['autopurge_old_terms'])) {
        $GLOBALS['autopurge_old_terms'] = [];
    }
    if (!isset($GLOBALS['autopurge_old_terms'][$object_id])) {
        $GLOBALS['autopurge_old_terms'][$object_id] = [];
    }
    if (!isset($GLOBALS['autopurge_old_terms'][$object_id][$taxonomy])) {
        $GLOBALS['autopurge_old_terms'][$object_id][$taxonomy] = [];
    }

    foreach ($old_tt_ids as $tt_id) {
        $term = get_term_by('term_taxonomy_id', $tt_id);
        if ($term && !is_wp_error($term)) {
            $GLOBALS['autopurge_old_terms'][$object_id][$taxonomy][] = (int) $term->term_id;
        }
    }
    $GLOBALS['autopurge_old_terms'][$object_id][$taxonomy] = array_values(
        array_unique($GLOBALS['autopurge_old_terms'][$object_id][$taxonomy])
    );
}

function autopurge_handle_post_updated($post_id, $post_after, $post_before) {
    if (autopurge_should_skip_post($post_id, $post_after)) {
        return;
    }
    // Only handle publish->publish edits here; status changes go to transition handler.
    if ($post_after->post_status !== 'publish' || $post_before->post_status !== 'publish') {
        return;
    }

    $significant = autopurge_change_is_significant($post_after, $post_before);
    $wide = $significant || autopurge_get_option('edit_mode') === 'wide';

    autopurge_purge_for_post($post_after, $wide);
}

function autopurge_handle_transition($new_status, $old_status, $post) {
    if (autopurge_should_skip_post($post->ID, $post)) {
        return;
    }
    if ($new_status === $old_status) {
        return; // post_updated handles same-status edits
    }
    if ($new_status !== 'publish' && $old_status !== 'publish') {
        return; // never made public, never cached
    }

    autopurge_purge_for_post($post, true);
}

function autopurge_handle_delete($post_id, $post) {
    if (autopurge_should_skip_post($post_id, $post)) {
        return;
    }
    if ($post->post_status !== 'publish' && $post->post_status !== 'trash') {
        return;
    }
    autopurge_purge_for_post($post, true);
}

function autopurge_handle_attachment_delete($post_id) {
    $post = get_post($post_id);
    if (!$post) {
        return;
    }
    $url = wp_get_attachment_url($post_id);
    if ($url) {
        autopurge_queue_urls([$url]);
    }
    autopurge_queue_tags(['attachment-' . $post_id]);
    autopurge_register_flush();
}

function autopurge_handle_meta_update($meta_id, $object_id, $meta_key, $meta_value) {
    if ($meta_key !== '_thumbnail_id') {
        return;
    }
    $post = get_post($object_id);
    if (!$post || $post->post_status !== 'publish') {
        return;
    }
    if (autopurge_should_skip_post($object_id, $post)) {
        return;
    }
    autopurge_purge_for_post($post, true);
}

function autopurge_handle_new_comment($comment_id, $approved) {
    if (1 !== (int) $approved) {
        return;
    }
    $comment = get_comment($comment_id);
    if (!$comment || empty($comment->comment_post_ID)) {
        return;
    }
    $post = get_post($comment->comment_post_ID);
    if (!$post || $post->post_status !== 'publish') {
        return;
    }
    autopurge_purge_for_post($post, false);
}

function autopurge_handle_comment_transition($new_status, $old_status, $comment) {
    if ($new_status === $old_status) {
        return;
    }
    if ($new_status !== 'approved' && $old_status !== 'approved') {
        return;
    }
    if (empty($comment->comment_post_ID)) {
        return;
    }
    $post = get_post($comment->comment_post_ID);
    if (!$post || $post->post_status !== 'publish') {
        return;
    }
    autopurge_purge_for_post($post, false);
}

function autopurge_handle_upgrader($upgrader, $options) {
    $type   = $options['type']   ?? '';
    $action = $options['action'] ?? '';
    if ($action === 'update' && in_array($type, ['plugin', 'theme', 'core'], true)) {
        autopurge_purge_html_tag();
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("AutoPurge: 'html' tag purged due to {$type} update.");
        }
    }
}

function autopurge_purge_html_tag() {
    autopurge_queue_tags(['html']);
    autopurge_register_flush();
}

function autopurge_should_skip_post($post_id, $post) {
    if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
        return true;
    }
    if (!$post || !is_post_type_viewable($post->post_type)) {
        return true;
    }
    if (apply_filters('autopurge_skip_post', false, $post)) {
        return true;
    }
    return false;
}

/* ===== SMART EDIT DETECTION ======================================= */

function autopurge_change_is_significant($after, $before) {
    if ($after->post_status     !== $before->post_status)     return true;
    if ($after->post_title      !== $before->post_title)      return true;
    if ($after->post_excerpt    !== $before->post_excerpt)    return true;
    if ($after->post_name       !== $before->post_name)       return true; // permalink slug
    if ($after->post_parent     !== $before->post_parent)     return true;
    if ($after->post_author     !== $before->post_author)     return true;
    if ($after->post_password   !== $before->post_password)   return true;
    if ($after->menu_order      !== $before->menu_order)      return true;
    if ($after->post_date       !== $before->post_date)       return true;
    if ($after->comment_status  !== $before->comment_status)  return true;

    if (!empty($GLOBALS['autopurge_old_terms'][$after->ID])) {
        return true;
    }

    return apply_filters('autopurge_change_is_significant', false, $after, $before);
}

/* ===== PURGE ASSEMBLY ============================================= */

function autopurge_purge_for_post($post, $wide = true) {
    if (autopurge_get_option('wide_mode')) {
        autopurge_queue_everything();
        autopurge_register_flush();
        return;
    }

    $post_id = (int) $post->ID;

    // Narrow purge: post tag + home tag + permalink + home URL.
    // Including 'home' covers homepage pagination if it shows excerpts.
    $tags = ['post-' . $post_id, 'home'];
    $urls = array_filter([get_permalink($post_id), home_url('/')]);

    if ($wide) {
        $tags[] = 'feed';
        $tags[] = 'post_type-' . $post->post_type;
        $tags[] = 'author-' . $post->post_author;

        $t = strtotime($post->post_date_gmt ?: $post->post_date);
        if ($t) {
            $tags[] = sprintf('date-%s',       gmdate('Y',     $t));
            $tags[] = sprintf('date-%s-%s',    gmdate('Y',     $t), gmdate('m', $t));
            $tags[] = sprintf('date-%s-%s-%s', gmdate('Y',     $t), gmdate('m', $t), gmdate('d', $t));
        }

        // Posts page (when show_on_front=page)
        if (get_option('show_on_front') === 'page') {
            $posts_page_id = (int) get_option('page_for_posts');
            if ($posts_page_id) {
                $posts_page_url = get_permalink($posts_page_id);
                if ($posts_page_url) {
                    $urls[] = $posts_page_url;
                }
                $tags[] = 'post-' . $posts_page_id;
            }
        }

        // Taxonomies: current + previously-attached (captured via set_object_terms)
        foreach (get_object_taxonomies($post->post_type) as $tax) {
            $current = wp_get_post_terms($post_id, $tax, ['fields' => 'ids']);
            if (!is_wp_error($current)) {
                foreach ($current as $tid) {
                    $tags[] = 'term-' . $tax . '-' . (int) $tid;
                }
            }
            $old = $GLOBALS['autopurge_old_terms'][$post_id][$tax] ?? [];
            foreach ($old as $tid) {
                $tags[] = 'term-' . $tax . '-' . (int) $tid;
            }
        }

        // Related posts via filter (e.g. for sites with related-posts widgets)
        $related_ids = apply_filters('autopurge_related_post_ids', [], $post);
        foreach ((array) $related_ids as $rid) {
            $tags[] = 'post-' . (int) $rid;
        }
    }

    $tags = apply_filters('autopurge_post_tags', $tags, $post, $wide);
    $urls = apply_filters('autopurge_post_urls', $urls, $post, $wide);

    if (!empty($tags)) {
        autopurge_queue_tags((array) $tags);
    }
    if (!empty($urls)) {
        autopurge_queue_urls((array) $urls);
    }
    autopurge_register_flush();
}

/* ===== PURGE QUEUE (debounced via shutdown) ======================= */

function autopurge_queue_get() {
    $q = get_transient(AUTOPURGE_QUEUE_KEY);
    if (!is_array($q)) {
        $q = ['tags' => [], 'urls' => [], 'prefixes' => [], 'everything' => false];
    }
    return $q;
}

function autopurge_queue_save($q) {
    set_transient(AUTOPURGE_QUEUE_KEY, $q, 300);
}

function autopurge_queue_tags(array $tags) {
    $tags = autopurge_normalize_tags($tags);
    if (!$tags) {
        return;
    }
    $q = autopurge_queue_get();
    $q['tags'] = array_values(array_unique(array_merge($q['tags'], $tags)));
    autopurge_queue_save($q);
}

function autopurge_queue_urls(array $urls) {
    $urls = array_filter($urls, function ($u) {
        return is_string($u) && filter_var($u, FILTER_VALIDATE_URL);
    });
    if (!$urls) {
        return;
    }
    $q = autopurge_queue_get();
    $q['urls'] = array_values(array_unique(array_merge($q['urls'], $urls)));
    autopurge_queue_save($q);
}

function autopurge_queue_prefixes(array $prefixes) {
    $prefixes = array_filter($prefixes, function ($p) {
        return is_string($p) && $p !== '';
    });
    if (!$prefixes) {
        return;
    }
    $q = autopurge_queue_get();
    $q['prefixes'] = array_values(array_unique(array_merge($q['prefixes'], $prefixes)));
    autopurge_queue_save($q);
}

function autopurge_queue_everything() {
    $q = autopurge_queue_get();
    $q['everything'] = true;
    autopurge_queue_save($q);
}

function autopurge_register_flush() {
    if (!has_action('shutdown', 'autopurge_flush_queue')) {
        add_action('shutdown', 'autopurge_flush_queue', 99);
    }
}

function autopurge_flush_queue() {
    $q = autopurge_queue_get();
    delete_transient(AUTOPURGE_QUEUE_KEY);

    if (!empty($q['everything'])) {
        autopurge_purge_everything();
        return;
    }
    if (!empty($q['tags'])) {
        autopurge_purge_tags($q['tags']);
    }
    if (!empty($q['urls'])) {
        autopurge_purge_urls($q['urls']);
    }
    if (!empty($q['prefixes'])) {
        autopurge_purge_prefixes($q['prefixes']);
    }
}

/* ===== CLOUDFLARE API ============================================= */

function autopurge_cf_request(array $payload) {
    $creds = autopurge_get_credentials();
    if (!$creds) {
        error_log('AutoPurge: no credentials configured. Visit Tools -> AutoPurge Cache to set a token.');
        return false;
    }
    $token = $creds['token'];
    $zone  = $creds['zone_id'];

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('AutoPurge payload: ' . wp_json_encode($payload));
    }

    $resp = wp_remote_post(
        AUTOPURGE_CF_API . "/zones/{$zone}/purge_cache",
        [
            'headers' => [
                'Authorization' => "Bearer {$token}",
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode($payload),
            'timeout' => 15,
        ]
    );

    if (is_wp_error($resp)) {
        error_log('AutoPurge error: ' . $resp->get_error_message());
        return false;
    }
    $code = wp_remote_retrieve_response_code($resp);
    if (200 !== $code) {
        error_log("AutoPurge error (HTTP {$code}): " . wp_remote_retrieve_body($resp));
        return false;
    }
    return true;
}

function autopurge_purge_everything() {
    return autopurge_cf_request(['purge_everything' => true]);
}

function autopurge_purge_urls(array $urls) {
    $ok = true;
    foreach (array_chunk(array_values($urls), AUTOPURGE_BATCH_LIMIT) as $batch) {
        if (!autopurge_cf_request(['files' => $batch])) {
            $ok = false;
        }
    }
    return $ok;
}

function autopurge_purge_tags(array $tags) {
    $ok = true;
    foreach (array_chunk(array_values($tags), AUTOPURGE_BATCH_LIMIT) as $batch) {
        if (!autopurge_cf_request(['tags' => $batch])) {
            $ok = false;
        }
    }
    return $ok;
}

function autopurge_purge_prefixes(array $prefixes) {
    $ok = true;
    foreach (array_chunk(array_values($prefixes), AUTOPURGE_PREFIX_LIMIT) as $batch) {
        if (!autopurge_cf_request(['prefixes' => $batch])) {
            $ok = false;
        }
    }
    return $ok;
}

/* ===== ADMIN PAGE ================================================= */

add_action('admin_menu', function () {
    add_management_page(
        'AutoPurge Cache',
        'AutoPurge Cache',
        'manage_options',
        'autopurge',
        'autopurge_render_admin_page'
    );
});

function autopurge_render_admin_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('Insufficient permissions.'));
    }

    $notice = '';
    $notice_type = 'success';

    // Cloudflare credentials actions
    if (isset($_POST['autopurge_cf_action']) && check_admin_referer('autopurge_cf')) {
        switch ($_POST['autopurge_cf_action']) {
            case 'save_token':
                $token = sanitize_text_field(wp_unslash($_POST['autopurge_cf_token'] ?? ''));
                if ($token === '') {
                    $notice = 'Token is empty.';
                    $notice_type = 'error';
                    break;
                }
                $verify = autopurge_verify_token($token);
                if (is_wp_error($verify)) {
                    $notice = $verify->get_error_message();
                    $notice_type = 'error';
                    break;
                }
                $resolved = autopurge_resolve_zone($token);
                if (is_wp_error($resolved)) {
                    $notice = $resolved->get_error_message();
                    $notice_type = 'error';
                    break;
                }
                autopurge_save_credentials($token, $resolved['zone_id'], $resolved['zone_name']);
                $notice = sprintf('Token saved. Zone detected: %s.', $resolved['zone_name']);
                break;

            case 'redetect_zone':
                $existing = autopurge_get_credentials();
                if (!$existing || empty($existing['token'])) {
                    $notice = 'No saved token. Save a token first.';
                    $notice_type = 'error';
                    break;
                }
                if (($existing['source'] ?? '') === 'constant') {
                    $notice = 'Credentials are defined as constants in wp-config.php; cannot re-detect from here.';
                    $notice_type = 'warning';
                    break;
                }
                $resolved = autopurge_resolve_zone($existing['token']);
                if (is_wp_error($resolved)) {
                    $notice = $resolved->get_error_message();
                    $notice_type = 'error';
                    break;
                }
                autopurge_save_credentials($existing['token'], $resolved['zone_id'], $resolved['zone_name']);
                $notice = sprintf('Zone re-detected: %s.', $resolved['zone_name']);
                break;

            case 'clear_creds':
                autopurge_clear_credentials();
                $notice = 'Stored credentials cleared.';
                break;
        }
    }

    // Settings save
    if (isset($_POST['autopurge_save_settings']) && check_admin_referer('autopurge_settings')) {
        $opts = autopurge_sanitize_options($_POST['autopurge_options'] ?? []);
        update_option(AUTOPURGE_OPTIONS_KEY, $opts);
        $notice = 'Settings saved.';
    }

    // Manual purge action
    if (isset($_POST['autopurge_action']) && check_admin_referer('autopurge_purge')) {
        switch ($_POST['autopurge_action']) {
            case 'purge_everything':
                autopurge_purge_everything();
                $notice = 'Cloudflare "Purge Everything" request sent.';
                error_log('AutoPurge: Manual "Purge Everything" triggered.');
                break;

            case 'purge_urls':
                $raw  = sanitize_textarea_field(wp_unslash($_POST['autopurge_urls'] ?? ''));
                $urls = array_filter(
                    array_map('trim', preg_split('/\R+/', $raw)),
                    function ($u) { return filter_var($u, FILTER_VALIDATE_URL) !== false; }
                );
                if ($urls) {
                    autopurge_purge_urls($urls);
                    error_log('AutoPurge: Manual URL purge: ' . implode(', ', $urls));
                    $notice = sprintf('%d URL(s) sent for purge.', count($urls));
                } else {
                    $notice = 'No valid URLs found.';
                    $notice_type = 'warning';
                }
                break;

            case 'purge_tags':
                $raw  = sanitize_textarea_field(wp_unslash($_POST['autopurge_tags'] ?? ''));
                $tags = autopurge_normalize_tags(array_map('trim', preg_split('/\R+/', $raw)));
                if ($tags) {
                    autopurge_purge_tags($tags);
                    error_log('AutoPurge: Manual tag purge: ' . implode(', ', $tags));
                    $notice = sprintf('%d tag(s) sent for purge.', count($tags));
                } else {
                    $notice = 'No valid tags found.';
                    $notice_type = 'warning';
                }
                break;

            case 'purge_prefixes':
                $raw      = sanitize_textarea_field(wp_unslash($_POST['autopurge_prefixes'] ?? ''));
                $prefixes = array_filter(array_map('trim', preg_split('/\R+/', $raw)));
                if ($prefixes) {
                    autopurge_purge_prefixes($prefixes);
                    error_log('AutoPurge: Manual prefix purge: ' . implode(', ', $prefixes));
                    $notice = sprintf('%d prefix(es) sent for purge.', count($prefixes));
                } else {
                    $notice = 'No valid prefixes found.';
                    $notice_type = 'warning';
                }
                break;
        }
    }

    if ($notice !== '') {
        echo '<div class="notice notice-' . esc_attr($notice_type) . '"><p>' . esc_html($notice) . '</p></div>';
    }

    $opts = wp_parse_args(get_option(AUTOPURGE_OPTIONS_KEY, []), autopurge_default_options());
    $creds = autopurge_get_credentials();
    $creds_ok = (bool) $creds;
    $site_host = parse_url(home_url('/'), PHP_URL_HOST);
    ?>
    <div class="wrap">
        <h1>AutoPurge Cache</h1>

        <?php if (!$creds_ok): ?>
            <div class="notice notice-error"><p>
                <strong>Configuration required.</strong> Paste a Cloudflare API token below before purges will work.
            </p></div>
        <?php endif; ?>

        <h2>Cloudflare Setup</h2>
        <?php if ($creds_ok && ($creds['source'] ?? '') === 'constant'): ?>
            <p>Using <code>CF_API_TOKEN</code> and <code>CF_ZONE_ID</code> from <code>wp-config.php</code>.
            Zone ID: <code><?php echo esc_html($creds['zone_id']); ?></code>.
            Remove those constants from <code>wp-config.php</code> if you want to manage credentials here.</p>
        <?php else: ?>
            <p>Paste a Cloudflare API token. The plugin will verify it and auto-detect the zone for <code><?php echo esc_html($site_host); ?></code>.
            Create a token with these two permissions on the target zone:</p>
            <ul style="list-style:disc; margin-left:2em;">
                <li><strong>Zone &rarr; Zone &rarr; Read</strong> (used once, to detect the zone ID)</li>
                <li><strong>Zone &rarr; Cache Purge &rarr; Purge</strong></li>
            </ul>
            <p><a href="https://dash.cloudflare.com/profile/api-tokens" target="_blank" rel="noopener">Create an API token &raquo;</a></p>

            <form method="post">
                <?php wp_nonce_field('autopurge_cf'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="autopurge_cf_token">API Token</label></th>
                        <td>
                            <input type="password" id="autopurge_cf_token" name="autopurge_cf_token"
                                   class="regular-text" autocomplete="off"
                                   placeholder="<?php echo $creds_ok ? 'Token saved (ends ...' . esc_attr($creds['token_last4']) . '). Paste a new one to replace.' : 'Paste your Cloudflare API token'; ?>">
                            <?php if ($creds_ok): ?>
                                <p class="description">
                                    Active token ends in <code>...<?php echo esc_html($creds['token_last4']); ?></code>.
                                    Zone: <code><?php echo esc_html($creds['zone_name'] ?: $creds['zone_id']); ?></code>
                                    (ID <code><?php echo esc_html($creds['zone_id']); ?></code>).
                                    <?php if (!empty($creds['verified_at'])): ?>
                                        Verified <?php echo esc_html(human_time_diff($creds['verified_at']) . ' ago'); ?>.
                                    <?php endif; ?>
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
                <p>
                    <button class="button button-primary" name="autopurge_cf_action" value="save_token">Save &amp; Verify</button>
                    <?php if ($creds_ok): ?>
                        <button class="button" name="autopurge_cf_action" value="redetect_zone">Re-detect Zone</button>
                        <button class="button" name="autopurge_cf_action" value="clear_creds"
                                onclick="return confirm('Remove the saved token and zone from this site?');">Clear Credentials</button>
                    <?php endif; ?>
                </p>
            </form>
        <?php endif; ?>

        <hr>

        <h2>Manual Purge</h2>
        <form method="post">
            <?php wp_nonce_field('autopurge_purge'); ?>

            <h3>Purge Everything</h3>
            <p><button class="button button-primary" name="autopurge_action" value="purge_everything"
                onclick="return confirm('Purge ALL cached content for this zone?');">
                Purge Entire Cache
            </button></p>

            <h3>Purge Specific URLs</h3>
            <p>One absolute URL per line.</p>
            <textarea name="autopurge_urls" rows="5" style="width:100%;"></textarea>
            <p><button class="button" name="autopurge_action" value="purge_urls">Purge Listed URLs</button></p>

            <h3>Purge by Cache Tag</h3>
            <p>One tag per line. See <a href="#tag-schema">tag schema</a> below.</p>
            <textarea name="autopurge_tags" rows="5" style="width:100%;"></textarea>
            <p><button class="button" name="autopurge_action" value="purge_tags">Purge Listed Tags</button></p>

            <h3>Purge by Prefix</h3>
            <p>One URL prefix per line (e.g. <code>https://example.com/2026/</code>). Up to 30 per request.</p>
            <textarea name="autopurge_prefixes" rows="5" style="width:100%;"></textarea>
            <p><button class="button" name="autopurge_action" value="purge_prefixes">Purge Listed Prefixes</button></p>
        </form>

        <hr>

        <h2>Settings</h2>
        <form method="post">
            <?php wp_nonce_field('autopurge_settings'); ?>
            <input type="hidden" name="autopurge_save_settings" value="1">

            <table class="form-table">
                <tr>
                    <th scope="row">Auto-purge</th>
                    <td>
                        <label><input type="checkbox" name="autopurge_options[auto_purge]" value="1" <?php checked($opts['auto_purge']); ?>>
                        Automatically purge related URLs and tags when content changes.</label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Edit detection</th>
                    <td>
                        <label><input type="radio" name="autopurge_options[edit_mode]" value="smart" <?php checked($opts['edit_mode'], 'smart'); ?>>
                        <strong>Smart</strong> &mdash; narrow purge for body-only edits; wide purge when status, title, slug, terms, author, date, or featured image changes.</label><br>
                        <label><input type="radio" name="autopurge_options[edit_mode]" value="wide" <?php checked($opts['edit_mode'], 'wide'); ?>>
                        <strong>Always wide</strong> &mdash; purge all related tags on any edit to a published post.</label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Comment purges</th>
                    <td>
                        <label><input type="checkbox" name="autopurge_options[comment_purge]" value="1" <?php checked($opts['comment_purge']); ?>>
                        Narrow-purge a post when a comment is approved or posted.</label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Wide mode</th>
                    <td>
                        <label><input type="checkbox" name="autopurge_options[wide_mode]" value="1" <?php checked($opts['wide_mode']); ?>>
                        Use <code>purge_everything</code> on every change. Enable only if your site shows widely-related content (related-posts widgets, "popular" lists, etc.) on many pages.</label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Tag prefix</th>
                    <td>
                        <input type="text" name="autopurge_options[tag_prefix]" value="<?php echo esc_attr($opts['tag_prefix']); ?>" class="regular-text">
                        <p class="description">Optional prefix prepended to every tag (e.g. <code>wp-</code>) to namespace tags within a shared zone. Lowercase, alphanumeric, hyphen, underscore only.</p>
                    </td>
                </tr>
            </table>
            <p><button class="button button-primary" type="submit">Save Settings</button></p>
        </form>

        <hr>

        <h2 id="tag-schema">Cache Tag Schema</h2>
        <p>The plugin emits a <code>Cache-Tag</code> response header on cacheable pages with these tags:</p>
        <ul>
            <li><code>html</code> &mdash; any HTML page</li>
            <li><code>feed</code> &mdash; any feed (RSS/Atom/RDF)</li>
            <li><code>home</code> &mdash; front page or posts page (covers all pagination)</li>
            <li><code>post-{ID}</code> &mdash; singular post / page / custom post type</li>
            <li><code>post_type-{type}</code> &mdash; post type archive (covers all pagination)</li>
            <li><code>term-{taxonomy}-{term_id}</code> &mdash; term archive (covers all pagination)</li>
            <li><code>author-{user_id}</code> &mdash; author archive (covers all pagination)</li>
            <li><code>date-{Y}</code>, <code>date-{Y}-{M}</code>, <code>date-{Y}-{M}-{D}</code> &mdash; date archives</li>
            <li><code>attachment-{ID}</code> &mdash; attachment</li>
        </ul>
        <p>Cloudflare honors the <code>Cache-Tag</code> response header on all plan tiers. After activating the plugin, run <strong>Purge Everything</strong> once so subsequently-served content gets tagged.</p>
    </div>
    <?php
}
