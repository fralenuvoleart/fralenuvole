<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fralenuvole
 * options-page.php - Creates plugin options page
 */

// Load UI Helpers
require_once FRL_DIR_PATH . 'admin/helpers/functions-admin-ui.php';
require_once FRL_DIR_PATH . 'admin/helpers/admin-class-helpers-ui.php';
require_once(FRL_DIR_PATH . 'admin/helpers/functions-admin-import-export.php');

// Load UI interface
require_once(FRL_DIR_PATH . 'admin/ui/ui-asset-loader.php');
require_once(FRL_DIR_PATH . 'admin/ui/class-tab-registry.php');
require_once(FRL_DIR_PATH . 'admin/ui/class-tab-renderer.php');
require_once(FRL_DIR_PATH . 'admin/ui/class-tab-manager.php');
require_once(FRL_DIR_PATH . 'admin/ui/class-ui-renderer.php');

// Load UI components
require_once(FRL_DIR_PATH . 'admin/components/class-dashboard.php');
require_once(FRL_DIR_PATH . 'admin/components/class-display-environment.php');
require_once(FRL_DIR_PATH . 'admin/components/class-display-cache.php');
require_once(FRL_DIR_PATH . 'admin/components/class-tag-validator.php');
require_once(FRL_DIR_PATH . 'admin/components/class-settings-fields.php');
require_once(FRL_DIR_PATH . 'admin/components/class-display-log.php');
require_once(FRL_DIR_PATH . 'admin/components/class-display-debug.php');
require_once(FRL_DIR_PATH . 'admin/components/class-import-export.php');

// Preload admin caches immediately
//before any hooks or components are initialized
frl_cache_preload_groups(['staticdata', 'adminui']);

// Register dashboard tab if not already registered
frl_tab_register_tab(
    'dashboard',
    [
        'title' => 'Dashboard',
        'description' => '',
        'position' => 'POSITION_FIRST'
    ]
);

// Hide tabs if user is not plugin admin
frl_tab_hide_tabs_by_capability(['developer'], frl_has_access());

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
