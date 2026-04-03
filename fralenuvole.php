<?php

/**
 * Plugin Name: Fralenuvole
 * Description: Swiss-Army Knife for Administrators.
 * Author: Francesco Castronovo
 * Author URI: https://fralenuvole.art
 * Plugin URI: https://fralenuvole.art
 * Version: 4.1.0
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

// Load required core files and constants
require_once __DIR__ . '/includes/bootstrap.php';

// FRL_MODE=disable: Stop loading the plugin entirely
if (defined('FRL_MODE') && FRL_MODE === 'disable') {
    return;
}

// Register lifecycle hooks (callbacks defined in includes/lifecycle.php)
register_activation_hook(__FILE__, 'frl_activate_plugin');
register_deactivation_hook(__FILE__, 'frl_deactivate_plugin');
register_uninstall_hook(__FILE__, 'frl_uninstall_plugin');

// Register core hooks with the Hook Manager
frl_hook_add(
    'action',
    'plugins_loaded',
    'frl_plugins_loaded',
    5,
    0
);
frl_hook_add(
    'action',
    'init',
    'frl_environment_enforce_settings',
    10,
    0
);

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
        // Register hooks before plugin exit
        frl_hook_register_hooks();
        return;
    }

    // Initialize the Environment Manager
    frl_environment_init();

    // Load public components strictly for frontend requests
    if (frl_is_valid_frontend_page_request()) {
        frl_load_public_components();
    }

    // Load modules file and apply filters to FRL_DEFAULT_FIELDS
    frl_modules_init();

    // Register all hooks with WordPress after all files and modules have been loaded
    frl_hook_register_hooks();
}

/**
 * Load the Core Components
 */
function frl_load_core_components()
{
    // Load logged user features
    if (frl_is_logged_in()) {
        require_once FRL_DIR_PATH . 'includes/logged-user.php';
    }

    // Load required unconditional files first
    require_once FRL_DIR_PATH . 'includes/cache/cache-cleanup.php';
    require_once FRL_DIR_PATH . 'includes/main.php';
    require_once FRL_DIR_PATH . 'public/shortcodes.php';

    require_once FRL_DIR_PATH . 'includes/rewriter/class-rewriter.php';
    require_once FRL_DIR_PATH . 'includes/translator/field-translator.php';
    require_once FRL_DIR_PATH . 'includes/themekit/themekit.php';

    // Explicitly initialize components
    frl_translator_init();

    if (!frl_get_option('disable_rewriter')) {
        Frl_Rewriter::init_with_di();
    }

    frl_hook_add(
        'action',
        'init',
        'frl_shortcodes_init',
        10,
        0
    );

    frl_hook_add(
        'action',
        'init',
        'frl_themekit_init',
        20,
        0
    );
}

/**
 * Load admin components
 */
function frl_load_admin_components()
{
    /**
     * Critical hooks marked for immediate registration:
     * - admin_menu: for frl_custom_admin_menu() in admin.php
     * - admin_post_frl_save_options: for frl_save_options() in admin.php
     */
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
