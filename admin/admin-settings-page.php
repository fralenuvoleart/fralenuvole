<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fralenuvole
 * options-page.php - Creates plugin options page
 */

// Load Plugin UI Class Helpers
require_once FRL_DIR_PATH . 'admin/helpers/functions-admin-ui.php';

// Load UI components
require_once(__DIR__ . '/ui/asset-loader.php');
require_once(__DIR__ . '/ui/class-tab-manager.php');
require_once(__DIR__ . '/ui/class-ui-renderer.php');

require_once(__DIR__ . '/components/class-dashboard.php');
require_once(__DIR__ . '/components/class-display-environment.php');
require_once(__DIR__ . '/components/class-display-cache.php');
require_once(__DIR__ . '/components/class-tag-validator.php');
require_once(__DIR__ . '/components/class-settings-fields.php');
require_once(__DIR__ . '/components/class-display-log.php');
require_once(__DIR__ . '/components/class-display-debug.php');

// Functions with frl_post_ prefix are auto-registered in frl_autodiscover_admin_actions() in admin.php
require_once(__DIR__ . '/helpers/functions-admin-import-export.php');
require_once(__DIR__ . '/components/class-import-export.php');

// Preload admin caches immediately
//before any hooks or components are initialized
frl_cache_preload_groups(['staticdata', 'adminui']);

// Register dashboard tab if not already registered
Frl_Tab_Manager::register_tab(
    'dashboard',
    [
        'title' => 'Dashboard',
        'description' => '',
        'position' => 'POSITION_FIRST'
    ]
);

// Hide tabs if user is not plugin admin
Frl_Tab_Manager::hide_tabs_by_capability(['developer'], frl_has_access());

// Critical hooks for immediate registration
add_action('frl_dashboard_content',
    'frl_dashboard_content_render',
    20,
    0);

add_action('frl_after_section_settings_content',
    'frl_settings_content_render',
    10,
    0);

add_action('frl_before_section_developer_content',
    'frl_before_developer_content_render',
    10,
    0);

add_action('frl_after_section_developer_content',
    'frl_after_developer_content_render',
    10,
    0);

/**
 * Add a custom widgets to the dashboard tab
 */
function frl_dashboard_content_render()
{
    echo frl_admin_dashboard_render();
}

/**
 * Render the Logs tab content
 * Used as a callback for the logs tab action hook.
 * @return void
 */
function frl_settings_content_render()
{
    echo frl_import_export_render();
}

/**
 * Render the Logs tab content
 * Used as a callback for the logs tab action hook.
 * @return void
 */
function frl_before_developer_content_render()
{
    echo frl_log_manager_render();
    echo frl_debug_display_render();
}

function frl_after_developer_content_render()
{
}
