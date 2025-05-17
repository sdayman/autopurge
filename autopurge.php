<?php
/**
 * Plugin Name: AutoPurge
 * Description: Collects every URL that can change when a post is created, updated, or deleted and purges them from Cloudflare cache.
 * Version:     1.0.0
 * Author:      Scott Dayman
 * License:     GPL-2.0-or-later
 */

/* INSTRUCTIONS *
Add the following to your wp-config.php file and replace the PUT_YOUR values:
define( 'CF_API_TOKEN', 'PUT_YOUR_TOKEN_HERE' ); 
define( 'CF_ZONE_ID', 'PUT_YOUR_ZONE_ID_HERE' );
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don’t run from the browser.
}

/**
 * Main hook – runs on post save / update *and* when a post is permanently deleted.
 */
add_action( 'save_post',      'puc_collect_urls_for_purge', 10, 3 );
add_action( 'wp_trash_post',  'puc_collect_urls_for_purge', 10, 2 ); // $update isn’t available here
add_action( 'before_delete_post', 'puc_collect_urls_for_purge', 10, 2 );

/**
 * Build the full list of affected URLs and hand them off to your purge helper.
 *
 * @param int          $post_id ID of the post.
 * @param WP_Post|null $post     The post object (null on `before_delete_post`)
 * @param bool         $update   true when updating an existing post.
 */
function puc_collect_urls_for_purge( $post_id, $post = null, $update = true ) {

	// Ignore autosaves, revisions, or if the post type isn’t public.
	if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
		return;
	}
	$post = $post ?: get_post( $post_id );
	if ( ! $post || ! is_post_type_viewable( $post->post_type ) ) {
		return;
	}

	$urls   = [];

	// 1. The post itself
	$urls[] = get_permalink( $post_id );

	// 2. Post-type archive
	if ( $archive = get_post_type_archive_link( $post->post_type ) ) {
		$urls[] = $archive;
	}

	// 3. Taxonomy archives (+ feeds)
	foreach ( get_object_taxonomies( $post->post_type ) as $tax ) {
		foreach ( wp_get_post_terms( $post_id, $tax ) as $term ) {
			$urls[] = get_term_link( $term );
			$urls[] = get_term_feed_link( $term->term_id, $tax );
		}
	}

	// 4. Author archive (+ feed)
	$urls[] = get_author_posts_url( $post->post_author );
	$urls[] = get_author_feed_link( $post->post_author );

	// 5. Date archives
	$t       = strtotime( $post->post_date_gmt ?: $post->post_date );
	$urls[]  = get_year_link( gmdate( 'Y', $t ) );
	$urls[]  = get_month_link( gmdate( 'Y', $t ), gmdate( 'm', $t ) );
	$urls[]  = get_day_link( gmdate( 'Y', $t ), gmdate( 'm', $t ), gmdate( 'd', $t ) );

	// 6. Front page and the main site feeds
	$urls[] = home_url( '/' );
	$urls[] = get_feed_link();

	// Remove duplicates, then purge
	puc_purge_urls( array_unique( $urls ) );
}

/**
 * Replace this with the real purge call (Cloudflare API, WP Rocket, etc.).
 *
 * @param string[] $urls List of absolute URLs.
 */
function puc_purge_urls( array $urls ) {

	// Cloudflare API call (token-based, single zone)
	$zone_id = 'd29361a8f2647587a46785e2a1fc0aa3';
	$response = wp_remote_post(
		"https://api.cloudflare.com/client/v4/zones/$zone_id/purge_cache",
		[
			'headers' => [
				'Authorization' => 'Bearer 1BeScRQdq42yCox9F2fpjxztir_2qvHwag4qSmoj',
				'Content-Type'  => 'application/json',
			],
			'body'    => wp_json_encode( [ 'files' => array_values( $urls ) ] ),
			'timeout' => 15,
		]
	);
}
