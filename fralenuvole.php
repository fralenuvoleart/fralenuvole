<?php

/**
 * Plugin Name: Fralenuvole
 * Description: Comprehensive multi-environment and performance management framework with all-in-one backend suite for admins and devs.
 * Author: Francesco Castronovo
 * Author URI: https://fralenuvole.art
 * Plugin URI: https://fralenuvole.art
 * Version: 5.5.1
 * Text Domain: fralenuvole
 * License: GPL2+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.txt
 *
 * @package Fralenuvole
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

const FRL_VERSION = '5.5.1';

// Load required core files and constants
require_once __DIR__ . '/includes/bootstrap.php';

// Load core infrastructure

// Load lifecycle hooks after bootstrap
require_once FRL_DIR_PATH . 'includes/plugin-lifecycle.php';

// FRL_MODE=disable: Stop loading the plugin entirely
if (defined('FRL_MODE') && FRL_MODE === 'disable') {
    return;
}

// Register lifecycle hooks (callbacks defined in includes/lifecycle.php)
register_activation_hook(__FILE__, 'frl_activate_plugin');
register_deactivation_hook(__FILE__, 'frl_deactivate_plugin');
register_uninstall_hook(__FILE__, 'frl_uninstall_plugin');

add_action('plugins_loaded',
    'frl_plugins_loaded',
    5,
    0);


// Security headers for all requests
add_action('send_headers', function () {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
});

/**
 * Initialize plugin and register hooks
 * @return void
 */
function frl_plugins_loaded()
{
    // Load core components
    frl_load_core_components();

    // Use enhanced admin detection function
    if (frl_is_admin()) {
        frl_load_admin_components();
    }

    // Exit early if plugin is disabled or request context is invalid
    if (frl_get_option('disable_plugin') || (defined('FRL_MODE') && FRL_MODE === 'core')) {
        return;
    }

    // Load public components strictly for frontend requests
    if (frl_is_valid_frontend_page_request()) {
        frl_load_public_components();
    }

    // Load modules file and apply filters to FRL_DEFAULT_FIELDS
    frl_modules_init();
}

/**
 * Load the Core Components
 */
function frl_load_core_components()
{
    // Load core files first (cache-manager already loadd in bootstrap)
    require_once FRL_DIR_PATH . 'core/cache/cache-cleanup.php';   
    require_once FRL_DIR_PATH . 'core/environment/environment-manager.php';
    require_once FRL_DIR_PATH . 'core/translator/translator.php';
    require_once FRL_DIR_PATH . 'core/rewriter/class-rewriter.php';
    require_once FRL_DIR_PATH . 'core/themekit/themekit.php';

    // Load always-active features
    require_once FRL_DIR_PATH . 'includes/main.php';
    require_once FRL_DIR_PATH . 'public/shortcodes.php';

    // Explicitly initialize components
    frl_environment_init();
    frl_translator_init();
    frl_rewriter_init();

    add_action('init',
        'frl_environment_enforce_settings',
        10,
        0);

    add_action('init',
        'frl_shortcodes_init',
        10,
        0);

    add_action('init',
        'frl_themekit_init',
        20,
        0);
}

/**
 * Load admin components
 */
function frl_load_admin_components()
{
    // Critical hooks for immediate registration: admin_menu and admin_post_frl_save_options
    require_once FRL_DIR_PATH . 'admin/admin.php';
}

/**
 * Load public components
 */
function frl_load_public_components()
{
    require_once FRL_DIR_PATH . 'public/public.php';
    require_once FRL_DIR_PATH . 'public/schema.php';
}

/**
 * Apply module settings filters to default fields
 * This allows modules to register their settings
 */
function frl_modules_init()
{
    // Use the module list from the base default configuration
    $modules = frl_modules_get_keys();
    if (!$modules) {
        return;
    }

    // Iterate through the KEYS (module names) from the default config
    foreach ($modules as $module_key) {
        $option_name = 'module_' . $module_key;

        // Check the WP option to see if this module should be loaded
        if (frl_get_option($option_name) === '1') {
            $module_file = frl_modules_module_get_file_path($module_key);
            if ($module_file) {
                include_once $module_file;
            }
        }
    }
}
