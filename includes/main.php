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
require_once __DIR__ . '/main/website.php';
require_once __DIR__ . '/main/media.php';
require_once __DIR__ . '/main/navigation.php';

// Load logged-user features (only for logged-in users)
if ( frl_is_logged_in() ) {
	require_once __DIR__ . '/main/logged-user.php';
}

// Priority 12: after environment enforcement (10), before rewriter (15).
add_action( 'init', 'frl_log_capture', 12, 0 );
add_filter( 'auth_cookie_expiration', 'frl_extend_admin_cookie', 10, 1 );
add_action( 'shutdown', 'frl_cache_process_deferred_writes', 10, 0 );

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
