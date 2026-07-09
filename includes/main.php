<?php
/**
 * Main plugin functionality.
 *
 * Handles core initialization, hook registrations, and shared feature loading.
 *
 * @package Fralenuvole
 * @since 1.0.0
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core execution logic for both frontend and backend.
 */

// Load feature modules
require_once __DIR__ . '/shared/website-features.php';
require_once __DIR__ . '/shared/media.php';
require_once __DIR__ . '/shared/navigation.php';

// Load logged-user features (only for logged-in users)
if ( frl_is_logged_in() ) {
	require_once __DIR__ . '/shared/logged-user.php';
}

add_action( 'init', 'frl_main_init', 10, 0 );

add_filter( 'auth_cookie_expiration', 'frl_extend_admin_cookie', 10, 1 );
add_action( 'shutdown', 'frl_process_deferred_writes', 10, 0 );


/**
 * Initializes core plugin features on the 'init' hook.
 *
 * @return void
 */
function frl_main_init(): void {
	add_post_type_support( 'page', 'excerpt' );

	frl_disable_wp_core_features();
	frl_add_image_sizes();
	frl_enable_custom_avatar();
	frl_log_capture();
}

/**
 * Trigegrs to capture debug log diagnostic data
 */
function frl_log_capture() {
	// Debug logging Hooks
	if ( ! frl_is_rest_api_request() && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
		add_filter( 'render_block_data', 'frl_log_capture_render_block_enter', 10, 1 );
		add_filter( 'render_block', 'frl_log_capture_render_block_exit', 10, 2 );
		add_action( 'pre_get_posts', 'frl_log_capture_query', 1, 1 );
		add_filter( 'do_shortcode_tag', 'frl_log_capture_shortcode', 10, 4 );
	}
}

/**
 * Extends the WordPress admin authentication cookie expiration.
 *
 * @param int $expirein Original expiration time in seconds.
 * @return int New expiration time (1 year if enabled, otherwise original).
 */
function frl_extend_admin_cookie( int $expirein ): int {
	if ( ! frl_get_option( 'extend_admin_cookie' ) ) {
		return $expirein;
	}

	return YEAR_IN_SECONDS;
}

/**
 * Processes and flushes deferred cache writes during the shutdown sequence.
 *
 * Merges duplicate writes and handles errors by re-queuing failed items.
 *
 * @return void
 */
function frl_process_deferred_writes(): void {
	frl_flush_db();

	$writes = frl_cache_get_deferred_writes();
	if ( empty( $writes ) ) {
		return;
	}

	// Merge duplicate writes: last write wins
	$merged = array();
	foreach ( $writes as $group => $items ) {
		foreach ( $items as $key => $value ) {
			$merged[ $group ][ $key ] = $value;
		}
	}

	// Process merged writes as one batch per group (via frl_cache_set_multi()) instead of
	// N individual frl_cache_set() calls — collapses N object-cache round-trips (or N
	// set_transient() DB writes) per group into a single batched operation.
	// On failure, the whole group's items are re-queued for the next cycle; re-queuing a
	// few already-succeeded keys alongside the failed one is harmless since cache writes
	// are idempotent, and avoids requiring per-key granularity from the batch call.
	$failed_items = array();
	foreach ( $merged as $group => $items ) {
		try {
			if ( ! frl_cache_set_multi( $group, $items ) ) {
				$failed_items[ $group ] = $items;
			}
		} catch ( Exception $e ) {
			frl_log(
				'Error processing deferred write batch for group {group}: {error}',
				array(
					'group' => $group,
					'error' => $e->getMessage(),
				)
			);
			$failed_items[ $group ] = $items;
		}
	}

	// Re-queue failed items for the next cycle
	if ( ! empty( $failed_items ) ) {
		foreach ( $failed_items as $group => $items ) {
			foreach ( $items as $key => $value ) {
				frl_cache_add_deferred_write( $group, $key, $value );
			}
		}
	}

	frl_cache_clear_deferred_writes();
	frl_flush_db();
}
