<?php

/**
 * Plugin Name: Fralenuvole
 * Description: Multi-environment and performance management framework with comprehensive backend suite for admins and devs.
 * Version: 5.7.5
 * Requires at least: 7.0
 * Requires PHP: 8.3
 * Text Domain: fralenuvole
 * Author: Francesco Castronovo
 * Author URI: https://fralenuvole.art
 * Plugin URI: https://fralenuvole.art
 * License: GPL2+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.txt
 *
 * @package Fralenuvole
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Upgrade routines trigger only when the first 3 version numbers change: 1.1.1 -> 1.1.2
const FRL_VERSION = '5.7.5';

// Load required core files and constants
require_once __DIR__ . '/includes/bootstrap.php';

// FRL_MODE=disable: Stop loading the plugin entirely
if ( defined( 'FRL_MODE' ) && FRL_MODE === 'disable' ) {
	return;
}

// Load lifecycle hooks after bootstrap
require_once FRL_DIR_PATH . 'includes/plugin-lifecycle.php';

// Register lifecycle hooks (callbacks defined in includes/lifecycle.php)
register_activation_hook( __FILE__, 'frl_activate_plugin' );
register_deactivation_hook( __FILE__, 'frl_deactivate_plugin' );
register_uninstall_hook( __FILE__, 'frl_uninstall_plugin' );

add_action(
	'plugins_loaded',
	'frl_plugins_loaded',
	5,
	0
);


// Security headers for all requests
// Uses wp_headers filter instead of send_headers action.
add_filter(
	'wp_headers',
	function ( $headers ) {
		$headers['X-Content-Type-Options'] = 'nosniff';
		$headers['X-Frame-Options']        = 'SAMEORIGIN';
		// Replaces the obsolete X-XSS-Protection header (deprecated, removed from
		// Chromium, ignored by all modern browsers). object-src/base-uri are safe,
		// non-breaking directives — they don't restrict script-src, so custom
		// header/footer scripts (frl_get_option('header_scripts'/'footer_scripts'))
		// keep working unmodified.
		$headers['Content-Security-Policy'] = "object-src 'none'; base-uri 'self';";
		return $headers;
	}
);

/**
 * Initialize plugin and register hooks
 * @return void
 */
function frl_plugins_loaded() {
	// Load core components
	frl_load_core_components();

	// Use enhanced admin detection function
	if ( frl_is_admin() ) {
		frl_load_admin_components();
	}

	// Exit early if plugin is disabled or request context is invalid
	if ( frl_get_option( 'disable_plugin' ) || ( defined( 'FRL_MODE' ) && FRL_MODE === 'core' ) ) {
		return;
	}

	// Load public components strictly for frontend requests
	if ( frl_is_valid_frontend_page_request() ) {
		frl_load_public_components();
	}

	// Load modules file and apply filters to FRL_DEFAULT_FIELDS
	frl_modules_init();
}

/**
 * Load the Core Components
 */
function frl_load_core_components() {
	// Load core files first (cache-manager already loaded in bootstrap)
	require_once FRL_DIR_PATH . 'core/cache/cache-cleanup.php';

	// Environment Manager — only load + init if not explicitly disabled.
	if ( ! frl_get_option( 'disable_environment' ) ) {
		require_once FRL_DIR_PATH . 'core/environment/environment-manager.php';
		frl_environment_init();
	}

	// Translator checks
	// Polylang/WPML constants and the disable_translator option.
	if ( frl_translator_is_enabled() ) {
		require_once FRL_DIR_PATH . 'core/translator/translator.php';
		frl_translator_init();
	}

	// Rewriter — only load + init if not explicitly disabled
	if ( ! frl_get_option( 'disable_rewriter' ) ) {
		require_once FRL_DIR_PATH . 'core/rewriter/class-rewriter.php';
		frl_rewriter_init();
	}

	// Webhook dispatch: always loaded, no disable toggle.
	frl_webhook_init();

	// Themekit — always loaded (no master disable toggle)
	require_once FRL_DIR_PATH . 'core/themekit/themekit.php';

	// Load always-active features
	require_once FRL_DIR_PATH . 'includes/main.php';
	require_once FRL_DIR_PATH . 'public/shortcodes.php';

	// These init functions handle their own internal guards:
	// - frl_environment_enforce_settings() calls frl_environment_is_loaded() which returns false if EM file wasn't loaded
	// - frl_shortcodes_init() / frl_themekit_init() are always safe (files loaded unconditionally above)
	add_action(
		'init',
		'frl_environment_enforce_settings',
		10,
		0
	);

	add_action(
		'init',
		'frl_shortcodes_init',
		10,
		0
	);

	add_action(
		'init',
		'frl_themekit_init',
		20,
		0
	);
}

/**
 * Load admin components
 */
function frl_load_admin_components() {
	// Critical hooks for immediate registration: admin_menu and admin_post_frl_save_options
	require_once FRL_DIR_PATH . 'admin/admin.php';
}

/**
 * Load public components
 */
function frl_load_public_components() {
	require_once FRL_DIR_PATH . 'public/public.php';
	require_once FRL_DIR_PATH . 'public/schema/schema.php';
}

/**
 * Apply module settings filters to default fields
 * This allows modules to register their settings
 */
function frl_modules_init() {
	// Use the module list from the base default configuration
	$modules = frl_modules_get_keys();
	if ( ! $modules ) {
		return;
	}

	// Iterate through the KEYS (module names) from the default config
	foreach ( $modules as $module_key ) {
		$option_name = 'module_' . $module_key;

		// Check the WP option to see if this module should be loaded
		if ( frl_get_option( $option_name ) === '1' ) {
			$module_file = frl_modules_module_get_file_path( $module_key );
			if ( $module_file ) {
				include_once $module_file;
			}
		}
	}
}
