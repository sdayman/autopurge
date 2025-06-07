<?php
/**
 * Plugin Name: AutoPurge
 * Description: Auto-purges Cloudflare when posts change or plugin/theme/core updates. Includes dashboard tool for manual purges (Everything, URLs, or Cache Tags).
 * Version:     1.3.0
 * Author:      Scott Dayman
 * License:     GPL-2.0-or-later
 */

/* ---------- CONFIG ---------- *
 * Put these in wp-config.php:
 *
 * define( 'CF_API_TOKEN', 'PUT_YOUR_TOKEN_HERE' );
 * define( 'CF_ZONE_ID',   'PUT_YOUR_ZONE_ID_HERE' );
 * -------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ===== 1.  AUTOMATIC PURGES ======================================= */
add_action( 'save_post',          'puc_collect_urls_for_purge', 10, 3 );
add_action( 'wp_trash_post',      'puc_collect_urls_for_purge', 10, 2 );
add_action( 'before_delete_post', 'puc_collect_urls_for_purge', 10, 2 );

function puc_collect_urls_for_purge( $post_id, $post = null, $update = true ) {

	if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
		return;
	}
	$post = $post ?: get_post( $post_id );
	if ( ! $post || ! is_post_type_viewable( $post->post_type ) ) {
		return;
	}

	$urls = [
		get_permalink( $post_id ),
		home_url( '/' ),
		get_feed_link(),
	];

	if ( $archive = get_post_type_archive_link( $post->post_type ) ) {
		$urls[] = $archive;
	}

	foreach ( get_object_taxonomies( $post->post_type ) as $tax ) {
		foreach ( wp_get_post_terms( $post_id, $tax ) as $term ) {
			$urls[] = get_term_link( $term );
			$urls[] = get_term_feed_link( $term->term_id, $tax );
		}
	}

	$urls[] = get_author_posts_url( $post->post_author );
	$urls[] = get_author_feed_link( $post->post_author );

	$t = strtotime( $post->post_date_gmt ?: $post->post_date );
	$urls[] = get_year_link( gmdate( 'Y', $t ) );
	$urls[] = get_month_link( gmdate( 'Y', $t ), gmdate( 'm', $t ) );
	$urls[] = get_day_link( gmdate( 'Y', $t ),  gmdate( 'm', $t ), gmdate( 'd', $t ) );

	puc_purge_urls( array_unique( $urls ) );
}

/* ===== 2.  MANUAL PURGE DASHBOARD PAGE ========================= */
add_action( 'admin_menu', function () {
	add_management_page(
		'AutoPurge Cache',
		'AutoPurge Cache',
		'manage_options',
		'puc-cloudflare',
		'puc_render_admin_page'
	);
} );

function puc_render_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( __( 'Cheatin’ huh?' ) );
	}

	// Handle form submit
	if ( isset( $_POST['puc_action'] ) && check_admin_referer( 'puc_purge' ) ) {
		switch ( $_POST['puc_action'] ) {
			case 'purge_everything':
				puc_purge_everything();
				$notice = 'Cloudflare “Purge Everything” request sent.';
				break;

			case 'purge_urls':
				$raw  = sanitize_textarea_field( wp_unslash( $_POST['puc_urls'] ?? '' ) );
				$urls = array_filter( array_map( 'trim', preg_split( '/\R+/', $raw ) ) );
				if ( $urls ) {
					puc_purge_urls( $urls );
					$notice = sprintf( '%d URL(s) sent for purge.', count( $urls ) );
				} else {
					$notice = 'No valid URLs found.';
				}
				break;

			case 'purge_cachetags':
				$raw      = sanitize_textarea_field( wp_unslash( $_POST['puc_cachetags'] ?? '' ) );
				$cachetags = array_filter( array_map( 'trim', preg_split( '/\R+/', $raw ) ) );
				if ( $cachetags ) {
					puc_purge_cachetags( $cachetags );
					$notice = sprintf( '%d cachetag(s) sent for purge.', count( $cachetags ) );
				} else {
					$notice = 'No valid cache tags found.';
				}
				break;
		}
		echo '<div class="notice notice-success"><p>' . esc_html( $notice ) . '</p></div>';
	}

	?>
	<div class="wrap">
		<h1>AutoPurge Cache</h1>
		<form method="post">
			<?php wp_nonce_field( 'puc_purge' ); ?>

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

/* ===== 3.  CLOUDLFARE API HELPERS =============================== */
function puc_cf_request( array $payload ) {
	$token   = defined( 'CF_API_TOKEN' ) ? CF_API_TOKEN : '';
	$zone_id = defined( 'CF_ZONE_ID' )   ? CF_ZONE_ID   : '';
	if ( ! $token || ! $zone_id ) {
		error_log( 'AutoPurge: CF_API_TOKEN or CF_ZONE_ID not defined.' );
		return;
	}

	$response = wp_remote_post(
		"https://api.cloudflare.com/client/v4/zones/{$zone_id}/purge_cache",
		[
			'headers' => [
				'Authorization' => "Bearer {$token}",
				'Content-Type'  => 'application/json',
			],
			'body'    => wp_json_encode( $payload ),
			'timeout' => 15,
		]
	);

	if ( is_wp_error( $response ) ) {
		error_log( 'AutoPurge error: ' . $response->get_error_message() );
	} elseif ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
		error_log( 'AutoPurge error: ' . wp_remote_retrieve_body( $response ) );
	}
}

function puc_purge_everything() {
	puc_cf_request( [ 'purge_everything' => true ] );
}

function puc_purge_urls( array $urls ) {
	puc_cf_request( [ 'files' => array_values( $urls ) ] );
}

function puc_purge_cachetags( array $cachetags ) {
	// Cloudflare accepts "cachetags" for granular purges.  [oai_citation:0‡developers.cloudflare.com](https://developers.cloudflare.com/api/resources/cache/methods/purge/?utm_source=chatgpt.com)
	puc_cf_request( [ 'tags' => array_values( $cachetags ) ] );
}

add_action('upgrader_process_complete', function( $upgrader_object, $options ) {
	$type = $options['type'] ?? '';
	$action = $options['action'] ?? '';

	if ( $action === 'update' && in_array( $type, ['plugin', 'theme'], true ) ) {
		if ( defined( 'CF_API_TOKEN' ) && defined( 'CF_ZONE_ID' ) ) {
			wp_remote_post( "https://api.cloudflare.com/client/v4/zones/" . CF_ZONE_ID . "/purge_cache", [
				'headers' => [
					'Authorization' => 'Bearer ' . CF_API_TOKEN,
					'Content-Type'  => 'application/json',
				],
				'body' => json_encode([
					'tags' => ['html']
				]),
			]);

			error_log("Cache tag 'html' purged due to $type update.");
		}
	}
}, 10, 2 );