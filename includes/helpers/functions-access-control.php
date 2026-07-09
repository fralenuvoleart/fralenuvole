<?php

/**
 * Fralenuvole User Authentication & Access Control
 *
 * This file contains the core helper functions for user access controls.
 *
 * @package Fralenuvole
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks if the current user has the required access capability.
 *
 * Uses standard WordPress capability checks via current_user_can().
 * For early-loading scenarios (before plugins_loaded), use frl_mu_check_access() instead.
 *
 * @param string $capability The capability to check for. Defaults to FRL_PLUGIN_ACCESS.
 * @return bool True if the user has access, false otherwise.
 */
function frl_has_access( $capability = FRL_PLUGIN_ACCESS ) {
	// Bypass access check in migrate mode (break-glass)
	if ( defined( 'FRL_MODE' ) && FRL_MODE === 'migrate' ) {
		return true;
	}

	// Standard loading: current_user_can is available
	if ( ! function_exists( 'current_user_can' ) ) {
		return false;
	}

	$capability = $capability ?: FRL_PLUGIN_ACCESS;
	$user       = frl_get_current_user();

	if ( $user->ID === FRL_PLUGIN_SUPERADMIN_ID ) {
		return true;
	}

	if ( $capability === 'superadmin' ) {
		return $user->ID === FRL_PLUGIN_SUPERADMIN_ID;
	}

	$cache_key = "user_uid{$user->ID}_can_{$capability}";
	return frl_cache_remember(
		'admin',
		$cache_key,
		function () use ( $user, $capability ) {
			return $user->has_cap( $capability );
		},
		300
	);
}

/**
 * ===================================================================
 * CORE FUNCTIONS: Context Detection (frl_is_* functions
 * ===================================================================
*/

/**
 * Enhanced version of is_admin() that handles common edge cases.
 *
 * Detects admin contexts including admin-post.php, admin-originated AJAX,
 * and login/registration pages.
 *
 * @return bool True if the current request is considered an admin request.
 */
function frl_is_admin() {
	static $is_admin = null;
	if ( $is_admin !== null ) {
		return $is_admin;
	}

	$include_login = true;
	$include_post  = true;

	$is_standard_admin_constant = defined( 'WP_ADMIN' ) && WP_ADMIN;

	$current_url = $_SERVER['REQUEST_URI'] ?? '';
	// Support subdirectory installations
	$is_request_targeting_admin_area = str_contains( $current_url, '/wp-admin/' );

	$is_true_admin_page_load = $is_standard_admin_constant && $is_request_targeting_admin_area;

	if ( $is_true_admin_page_load ) {
		$is_admin = true;
		return $is_admin;
	}

	$is_admin_post = $include_post && str_contains( $current_url, 'admin-post.php' );
	if ( $is_admin_post ) {
		$is_admin = true;
		return $is_admin;
	}

	$is_ajax = frl_is_doing_ajax();
	if ( $is_ajax ) {
		$referer = frl_wp_get_referer();
		// Avoid admin_url() filters for performance
		if ( ! empty( $referer ) && str_contains( $referer, '/wp-admin/' ) ) {
			$is_admin = true;
			return $is_admin;
		}
		$action = $_REQUEST['action'] ?? '';
		if ( str_starts_with( $action, 'admin_' ) || str_starts_with( $action, 'settings_' ) ) {
			$is_admin = true;
			return $is_admin;
		}
		$is_admin = false;
		return $is_admin;
	}

	if ( $include_login ) {
		global $pagenow;
		$login_pages   = array( 'wp-login.php', 'wp-register.php' );
		$is_login_page = isset( $pagenow ) && in_array( $pagenow, $login_pages, true );
		if ( $is_login_page ) {
			$is_admin = true;
			return $is_admin;
		}
	}

	$is_admin = false;
	return $is_admin;
}

/**
 * Checks if the current request is for a specific admin page.
 *
 * @param string $page The page slug or filename to check for.
 * @param string $param The URL parameter to check against. Defaults to 'page'.
 * @return bool True if the current page matches the specified page.
 */
function frl_is_admin_page( $page, $param = 'page' ) {
	// Ensure we are in admin area
	if ( ! frl_is_admin() ) {
		return false;
	}

	global $pagenow;

	// Handle filename-based pages (e.g. post.php, edit.php, index.php, etc.)
	if ( is_string( $page ) && str_contains( $page, '.php' ) ) {
		// $pagenow may not be set yet during early hooks (muplugins_loaded),
		// because wp-includes/vars.php loads after muplugins_loaded fires.
		// Fall back to the script filename from the request.
		$current_page = $pagenow;
		if ( empty( $current_page ) ) {
			$current_page = basename( $_SERVER['SCRIPT_NAME'] ?? '' );
		}
		return $current_page === $page;
	}

	// Handle page slug-based screens (e.g. admin.php?page=my-plugin-slug)
	return isset( $_GET[ $param ] ) && $_GET[ $param ] === $page;
}

/**
 * Checks if the current screen is a post edit or post creation screen.
 *
 * Detects post.php (edit) and post-new.php (add new) screens.
 * Useful for gating logic that only needs to run on post editing interfaces.
 *
 * @return bool True if on a post edit or post creation screen, false otherwise.
 */
function frl_is_post_edit_screen(): bool {
	if ( ! function_exists( 'get_current_screen' ) ) {
		return false;
	}
	$screen = get_current_screen();
	return $screen && $screen->base === 'post' && in_array( $screen->action, array( 'add', 'edit' ), true );
}

/**
 * Checks if the current user is logged in.
 *
 * @return bool True if the user is logged in.
 */
function frl_is_logged_in() {
	// Use cached current user
	return frl_get_current_user()->ID > 0;
}

/**
 * Checks if a specific function or process has already run during the current request.
 *
 * Useful for preventing duplicate execution of initialization logic.
 *
 * @param string $function_key Unique identifier for the function (e.g., __FUNCTION__).
 * @param bool $reset Whether to reset the initialization state. Defaults to false.
 * @return bool True if already initialized, false otherwise.
 */
function frl_is_already_running( $function_key, $reset = false ) {
	static $initialized = array();

	if ( $reset ) {
		$initialized[ $function_key ] = false;
		return $initialized[ $function_key ];
	}

	if ( isset( $initialized[ $function_key ] ) && $initialized[ $function_key ] ) {
		return true; // Already initialized
	}

	$initialized[ $function_key ] = true;
	return false; // First initialization
}

/**
 * Checks if the current request is within the plugin's context.
 *
 * Detects if the user is on the plugin settings page or if the request is
 * an admin action prefixed with the plugin's prefix.
 *
 * @return bool True if in plugin context, false otherwise.
 */
function frl_is_plugin_context() {
	static $is_plugin_context = null;
	if ( $is_plugin_context !== null ) {
		return $is_plugin_context;
	}

	if ( isset( $_GET['page'] ) && $_GET['page'] === FRL_NAME ) {
		$is_plugin_context = true;
		return $is_plugin_context;
	}

	if ( ! frl_is_admin() ) {
		$is_plugin_context = false;
		return $is_plugin_context;
	}

	$action            = isset( $_REQUEST['action'] ) ? $_REQUEST['action'] : '';
	$is_plugin_context = str_starts_with( $action, frl_prefix() );
	return $is_plugin_context;
}

/**
 * Checks if a given URL corresponds to a homepage.
 *
 * A URL is considered a homepage if its path is the root ('/') or a two-letter
 * language code (e.g., '/en/').
 *
 * @param string $url The URL to check.
 * @return bool True if the URL is a homepage, false otherwise.
 */
function frl_is_homepage_url( $url ) {
	if ( empty( $url ) || ! is_string( $url ) ) {
		return false;
	}

	$path = wp_parse_url( $url, PHP_URL_PATH );

	// Root URL
	if ( empty( $path ) || $path === '/' ) {
		return true;
	}

	// Handle both '/en' and '/en/'
	$trimmed_path = trim( $path, '/' );

	// Language-specific homepage (e.g., /en)
	if ( strlen( $trimmed_path ) === 2 && strpos( $trimmed_path, '/' ) === false ) {
		return true;
	}

	return false;
}

/**
 * Checks if the current request is valid for standard page processing and tracking.
 *
 * Returns false for CLI, REST API, CRON, invalid environment hosts, heartbeat AJAX,
 * log manager requests, or non-HTML document requests.
 *
 * @return bool True if the request is valid for processing, false otherwise.
 */
function frl_is_valid_page_request(): bool {
	static $is_valid = null;
	if ( $is_valid !== null ) {
		return $is_valid;
	}

	if ( frl_is_cli_request() ) {
		$is_valid = false;
		return $is_valid;
	}
	if ( frl_is_rest_api_request() ) {
		$is_valid = false;
		return $is_valid;
	}
	if ( frl_is_cron_job_request() ) {
		$is_valid = false;
		return $is_valid;
	}
	if ( ! frl_is_valid_environment_host() ) {
		$is_valid = false;
		return $is_valid;
	}
	if ( frl_is_heartbeat_ajax_request() ) {
		$is_valid = false;
		return $is_valid;
	}
	if ( frl_is_log_manager_request() ) {
		$is_valid = false;
		return $is_valid;
	}
	if ( ! frl_is_html_document_request() ) {
		$is_valid = false;
		return $is_valid;
	}

	if ( frl_is_administrator_action() ) {
		$is_valid = true;
		return $is_valid;
	}
	if ( frl_is_doing_ajax() ) {
		$is_valid = false;
		return $is_valid;
	}

	$is_valid = true;
	return $is_valid;
}

/**
 * Checks if the current request is a valid frontend page request.
 *
 * Ensures frontend-specific logic only runs during standard page views,
 * skipping admin, REST, CLI, AJAX, or cron requests.
 *
 * @return bool True if it's a valid frontend page request, false otherwise.
 */
function frl_is_valid_frontend_page_request(): bool {
	// A request is a valid frontend request if it is not in the admin backend
	// AND it passes all the general validation checks.
	return ! frl_is_admin() && frl_is_valid_page_request();
}

/**
 * Checks if the current request is an administrator action.
 *
 * Covers admin-post.php, admin pages with an 'action' parameter, and
 * AJAX requests with an 'action' parameter, provided the user has 'manage_options'.
 *
 * @return bool True if the request is an administrator action, false otherwise.
 */
function frl_is_administrator_action() {
	static $is_action = null;
	if ( $is_action !== null ) {
		return $is_action;
	}

	// Check admin-post.php
	$current_url = $_SERVER['REQUEST_URI'] ?? '';
	if ( str_contains( $current_url, 'admin-post.php' ) ) {
		$is_action = true;
		return $is_action;
	}

	// Check for 'action' parameter (AJAX or admin pages)
	$action_param = frl_prefix( 'action' );
	if ( isset( $_REQUEST['action'] ) || isset( $_REQUEST[ $action_param ] ) ) {

		// Check for administrator access
		if ( frl_has_access( 'manage_options' ) ) {
			$is_action = true;
			return $is_action;
		}
	}

	$is_action = false;
	return $is_action;
}

/**
 * Checks if the current request is an AJAX request.
 *
 * @return bool True if the current request is an AJAX request.
 */
function frl_is_doing_ajax() {
	// Cache result
	static $is_ajax = null;
	if ( $is_ajax !== null ) {
		return $is_ajax;
	}

	// Use wp_doing_ajax() or fallback to constant
	if ( function_exists( 'wp_doing_ajax' ) ) {
		$is_ajax = wp_doing_ajax();
	} else {
		$is_ajax = defined( 'DOING_AJAX' ) && DOING_AJAX;
	}

	return $is_ajax;
}

/**
 * Checks if the current request is a CLI request.
 *
 * @internal
 * @return bool True if CLI, false otherwise.
 */
function frl_is_cli_request(): bool {
	return PHP_SAPI === 'cli';
}

/**
 * Checks if the current request is a REST API request.
 *
 * @internal
 * @return bool True if REST API, false otherwise.
 */
function frl_is_rest_api_request(): bool {
	$current_url_for_rest_check = $_SERVER['REQUEST_URI'] ?? '';
	if ( str_starts_with( $current_url_for_rest_check, '/wp-json/' ) ) {
		return true; // Detected by URI
	}
	return defined( 'REST_REQUEST' ) && REST_REQUEST;
}

/**
 * Checks if the current request is a CRON job.
 *
 * @internal
 * @return bool True if CRON job, false otherwise.
 */
function frl_is_cron_job_request(): bool {
	if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
		return true; // Cron request
	}
	return function_exists( 'wp_doing_cron' ) && wp_doing_cron();
}

/**
 * Checks if the current request is a WordPress Heartbeat AJAX request.
 *
 * @internal
 * @return bool True if Heartbeat AJAX, false otherwise.
 */
function frl_is_heartbeat_ajax_request(): bool {
	$is_wp_doing_ajax = function_exists( 'wp_doing_ajax' ) && wp_doing_ajax();
	$request_action   = $_REQUEST['action'] ?? 'NOT_SET';

	if ( $is_wp_doing_ajax && $request_action === 'heartbeat' ) {
		return true;
	}
	return false;
}

/**
 * Checks if the current request's hostname is valid and mapped in the environment configuration.
 *
 * Verifies that HTTP_HOST is set and exists as a key in the FRL_ENV_MAP array.
 *
 * @internal
 * @return bool True if the host is valid and mapped, false otherwise.
 */
function frl_is_valid_environment_host(): bool {
	$current_host = $_SERVER['HTTP_HOST'] ?? null;
	return ! empty( $current_host ) && defined( 'FRL_ENV_MAP' ) && is_array( FRL_ENV_MAP ) && array_key_exists( $current_host, FRL_ENV_MAP );
}

/**
 * Checks if the current request is an HTML document request.
 *
 * @internal
 * @return bool True if document, false otherwise.
 */
function frl_is_html_document_request(): bool {
	global $wp_query;
	if ( isset( $wp_query ) ) {
		if ( is_404() ) {
			return false;
		}
		if ( is_attachment() ) {
			return false;
		}
	}
	return true;
}

/**
 * Checks if the current request is a log manager AJAX request.
 *
 * @internal
 * @return bool True if log manager AJAX, false otherwise.
 */
function frl_is_log_manager_request(): bool {
	if ( isset( $_REQUEST['action'] ) ) {
		$action = $_REQUEST['action'];
		if (
			$action === 'frl_post_ajax_debug_log_refresh' ||
			$action === 'frl_post_ajax_debug_log_clear' ||
			$action === 'frl_post_ajax_debug_log_download'
		) {
			return true;
		}
	}
	return false;
}

/**
 * Checks if the current save_post action is a real post save that should trigger
 * cache clearing and other post-save side effects.
 *
 * Returns false for autosaves, revisions, auto-drafts, and trash operations.
 *
 * @param int $post_id Post ID.
 * @return bool True if this is a real post save (not autosave/revision/auto-draft/trash).
 */
function frl_is_post_save_action( $post_id ): bool {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return false;
	}
	if ( wp_is_post_revision( $post_id ) ) {
		return false;
	}
	if ( wp_is_post_autosave( $post_id ) ) {
		return false;
	}
	$status = get_post_status( $post_id );
	if ( in_array( $status, array( 'auto-draft', 'trash', 'inherit' ), true ) ) {
		return false;
	}
	return true;
}

/**
 * Checks if a third-party plugin is active (site-wide or network-wide).
 *
 * Cached via frl_cache_remember in the 'options' group with WEEK_IN_SECONDS TTL,
 * since the active plugins list changes only on plugin activation/deactivation.
 * Invalidated via frl_purge_mu_plugin_exclusion_cache() on activated_plugin/
 * deactivated_plugin hooks.
 *
 * @param string $plugin_path Plugin path relative to plugins directory, e.g. 'mwai/mwai.php'.
 * @return bool True if the plugin is active (site-wide or network-wide).
 */
function frl_is_thirdparty_plugin_active( string $plugin_path ): bool {
	$active_plugins = frl_cache_remember(
		'options',
		'thirdparty_active_plugins',
		function () {
			return array(
				'site'    => (array) get_option( 'active_plugins', array() ),
				'network' => is_multisite()
					? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) )
					: array(),
			);
		},
		WEEK_IN_SECONDS
	);

	if ( in_array( $plugin_path, $active_plugins['site'], true ) ) {
		return true;
	}
	if ( in_array( $plugin_path, $active_plugins['network'], true ) ) {
		return true;
	}
	return false;
}
