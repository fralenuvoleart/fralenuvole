<?php

/**
 * Fralenuvole
 * functions-action-handlers.php - Plugin _GET_action handler functions
 * Loaded in both logged frontend and admin contexts
 *
 * This file contains all the admin action handlers that process GET/POST requests for cache clearing, environment resets, and other administrative actions.
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handle admin actions dispatched via GET requests.
 *
 * Processes direct GET requests with the `frl_action` parameter.
 * It is completely independent from admin-post.php handlers registered by frl_autodiscover_admin_actions.
 *
 * @return void
 */
function frl_process_plugin_actions()
{
    static $is_processing = false;
    if ($is_processing) {
        return;
    }

    $action_param_name = frl_prefix('action');
    $action = isset($_GET[$action_param_name]) ? sanitize_key($_GET[$action_param_name]) : '';

    // Check capability and if action parameter exists
    $cap_check = !empty($action) && (
        frl_has_access('manage_options') || 
        (in_array($action, FRL_PUBLIC_ACTIONS) && is_user_logged_in())
    );

    if (!$cap_check) {
        return;
    }

    $is_processing = true;
    $result = [];

    // Define the action registry
    $action_registry = [
        'clear_dashboard'           => 'frl_handle_action_clear_dashboard',
        'clear_cache_light'         => 'frl_handle_action_clear_cache_light',
        'clear_cache_all'           => 'frl_handle_action_clear_cache_all',
        'clear_cache_hard'          => 'frl_handle_action_clear_cache_hard',
        'clear_cache_opcache'       => 'frl_handle_action_clear_cache_opcache',
        'clear_plugin_transients'   => 'frl_handle_action_clear_plugin_transients',
        'clear_website_transients'  => 'frl_handle_action_clear_website_transients',
        'clear_scripts_tags'        => 'frl_handle_action_clear_scripts_tags',
        'clear_shortcodes'        => 'frl_handle_action_clear_shortcodes',
        'reset_environment'         => 'frl_handle_action_reset_environment',
        'reset_environment_ignored' => 'frl_handle_action_reset_environment_ignored',
        'reset_debug_config'        => 'frl_handle_action_reset_debug_config',
        'reset_plugin'              => 'frl_handle_action_reset_plugin',
        'delete_mu_plugins'         => 'frl_handle_action_delete_mu_plugins',
        'delete_orphan_options'     => 'frl_handle_action_delete_orphan_options',
        'sync_mu_plugins'           => 'frl_handle_action_sync_mu_plugins',
        'flush_rewrite_rules'       => 'frl_handle_action_flush_rewrite_rules',
        // Add other static actions here if needed
    ];

    // Check if the action is in the registry
    if (isset($action_registry[$action])) {
        $handler_function = $action_registry[$action];
        // @phpstan-ignore function.alreadyNarrowedType
        if (function_exists($handler_function)) { 
            $result = $handler_function();
        } else {
            // Log error if handler function doesn't exist
            frl_log("Admin action handler function not found: {function}", ['function' => $handler_function]);
            $result = ['success' => false, 'message_parts' => [__('Action handler missing.', FRL_PREFIX)], 'notice_type' => 'error'];
        }
    }
    // Check for dynamic cache clear action pattern
    elseif (str_starts_with($action, 'clear_cache_')) {
        if (function_exists('frl_handle_action_clear_cache_group')) {
            $result = frl_handle_action_clear_cache_group($action);
        } else {
            frl_log("Admin action handler function not found: frl_handle_action_clear_cache_group");
            $result = ['success' => false, 'message_parts' => [__('Action handler missing.', FRL_PREFIX)], 'notice_type' => 'error'];
        }
    }
    // Handle unknown actions
    else {
        $result = ['success' => false, 'message_parts' => [__('Unknown action specified.', FRL_PREFIX)], 'notice_type' => 'warning'];
    }

    // Centralized feedback and redirect
    $notice_message = '';
    $notice_type = 'info'; // Default type

    // Format message based on handler result structure
    if (!empty($result['message_parts'])) {
        $notice_message = implode("<br>\n", $result['message_parts']);
        $notice_type = $result['notice_type'] ?? 'info';
    } // If handler returned empty message_parts (e.g., clear_dashboard), no notice is added here.

    if (!empty($notice_message)) {
        frl_add_admin_notice($notice_message, $notice_type, 45);
    }

    $is_processing = false; // Reset processing flag

    // Always redirect after processing an action
    frl_safe_redirect();
}

/**
 * Handle the 'clear_plugin_transients' action.
 *
 * @return array{success: bool, message_parts: string[], notice_type: string} Result array.
 */
function frl_handle_action_clear_plugin_transients()
{
    $orchestrated = Frl_Cache_Operations::run('action_clear_plugin_transients');

    $deleted = $orchestrated['steps'][0]['result'] ?? [];
    if (is_array($deleted) && isset($deleted['transients'])) {
        return ['success' => true, 'message_parts' => [sprintf(
            __('Cleared %d plugin transients successfully', FRL_PREFIX),
            $deleted['transients']
        )], 'notice_type' => 'success'];
    } else {
        return ['success' => false, 'message_parts' => [__('Failed to clear plugin transients.', FRL_PREFIX)], 'notice_type' => 'error'];
    }
}

/**
 * Handle the 'clear_website_transients' action.
 *
 * @return array{success: bool, message_parts: string[], notice_type: string} Result array.
 */
function frl_handle_action_clear_website_transients()
{
    $orchestrated = Frl_Cache_Operations::run('action_clear_website_transients');

    $deleted = $orchestrated['steps'][0]['result'] ?? [];
    if (is_array($deleted) && isset($deleted['transients'])) {
        return ['success' => true, 'message_parts' => [sprintf(
            __('Cleared %d website transients successfully', FRL_PREFIX),
            $deleted['transients']
        )], 'notice_type' => 'success'];
    } else {
        return ['success' => false, 'message_parts' => [__('Failed to clear website transients.', FRL_PREFIX)], 'notice_type' => 'error'];
    }
}

/**
 * Handle the 'clear_scripts_tags' action.
 *
 * Groups to clear are defined by FRL_CACHE_SCRIPTS_GROUPS in config-cache.php.
 *
 * @return array{success: bool, message_parts: string[], notice_type: string} Result array.
 */
function frl_handle_action_clear_scripts_tags()
{
    $orchestrated = Frl_Cache_Operations::run('action_clear_scripts_tags');
    $message_parts = [];
    $success = false;

    foreach ($orchestrated['steps'] as $step) {
        $group = $step['args'][0] ?? '';
        $stats = $step['result'] ?? [];

        if ($step['success'] && is_array($stats) && isset($stats['persistent'])) {
            $message_parts[] = sprintf(
                __('%s cache cleared: %d persistent items deleted.', FRL_PREFIX),
                ucfirst($group),
                $stats['persistent'] ?? 0
            );
            $success = true;
        } else {
            $message_parts[] = sprintf(
                __('Failed to clear %s cache or get stats.', FRL_PREFIX),
                $group
            );
        }
    }

    return [
        'success' => $success,
        'message_parts' => $message_parts,
        'notice_type' => $success ? 'success' : 'error'
    ];
}
/**
 * Handle the 'clear_shortcodes' action.
 *
 * @return array{success: bool, message_parts: string[], notice_type: string} Result array.
 */
function frl_handle_action_clear_shortcodes()
{
    $deleted = frl_cache_clear('shortcodes');
    if (is_array($deleted)) {
        $count = $deleted['persistent'] ?? 0;
        return ['success' => true, 'message_parts' => [sprintf(__('Shortcodes cleared successfully (%d items).', FRL_PREFIX), $count)], 'notice_type' => 'success'];
    } else {
        return ['success' => false, 
        'message_parts' => [__('Failed to clear shortcodes.', FRL_PREFIX)], 
        'notice_type' => 'error'];
    }
}
/**
 * Handle the 'clear_cache_light' action.
 *
 * @return array{success: bool, message_parts: string[], notice_type: string} Result array.
 */
function frl_handle_action_clear_cache_light()
{
    $orchestrated = Frl_Cache_Operations::run('clear_light');

    if (!$orchestrated['success']) {
        return ['success' => false, 'message_parts' => [__('Failed to clear cache or get stats.', FRL_PREFIX)], 'notice_type' => 'error'];
    }

    $stats = $orchestrated['steps'][0]['result'] ?? [];
    $message_parts = [];

    if (is_array($stats)) {
        $message_parts[] = sprintf(
            __('Light cache cleared: %d items removed from memory, %d items removed from cache storage.', FRL_PREFIX),
            $stats['runtime'] ?? 0,
            ($stats['object_cache'] ?? 0) + ($stats['transients'] ?? 0)
        );
        return ['success' => true, 'message_parts' => $message_parts, 'notice_type' => 'success'];
    }

    return ['success' => false, 'message_parts' => [__('Failed to clear cache or get stats.', FRL_PREFIX)], 'notice_type' => 'error'];
}

/**
 * Handle the 'clear_cache_all' action.
 *
 * @return array{success: bool, message_parts: string[], notice_type: string} Result array.
 */
function frl_handle_action_clear_cache_all()
{
    $orchestrated = Frl_Cache_Operations::run('clear_all');

    if (!$orchestrated['success']) {
        return ['success' => false, 'message_parts' => [__('Failed to clear cache or get stats.', FRL_PREFIX)], 'notice_type' => 'error'];
    }

    $stats = $orchestrated['steps'][0]['result'] ?? [];
    $message_parts = [];

    if (is_array($stats)) {
        $message_parts[] = sprintf(
            __('All caches cleared: %d items removed from memory, %d items removed from cache storage.', FRL_PREFIX),
            $stats['runtime'] ?? 0,
            ($stats['object_cache'] ?? 0) + ($stats['transients'] ?? 0)
        );
        return ['success' => true, 'message_parts' => $message_parts, 'notice_type' => 'success'];
    }

    return ['success' => false, 'message_parts' => [__('Failed to clear cache or get stats.', FRL_PREFIX)], 'notice_type' => 'error'];
}

/**
 * Handle the 'clear_cache_hard' action.
 *
 * Calls the most comprehensive cache reset in Frl_Cache_Manager.
 *
 * @return array{success: bool, message_parts: string[], notice_type: string} Result array.
 */
function frl_handle_action_clear_cache_hard()
{
    if (!frl_has_access()) { // Ensure user has capabilities
        return ['success' => false, 'message_parts' => [__('You are not authorized to perform this action.', FRL_PREFIX)], 'notice_type' => 'error'];
    }

    $orchestrated = Frl_Cache_Operations::run('action_hard');

    // Step 0: frl_cache_clear('hard') — provides detailed stats for the UI message.
    $stats = $orchestrated['steps'][0]['result'] ?? [];

    $message_parts = ['<strong>' . __('Hard Cache Reset', FRL_PREFIX) . '</strong>'];
    $notice_type = 'success';

    // Plugin Internal Purge (from purge_all)
    if (frl_is_array_not_empty($stats, 'plugin_internal_purge')) {
        $purge_all_stats = $stats['plugin_internal_purge'];
        $message_parts[] = sprintf(
            __('- Plugin Internal Caches: %d runtime, %d object/transient items (approx), %d WP Core items.', FRL_PREFIX),
            $purge_all_stats['runtime'] ?? 0,
            ($purge_all_stats['object_cache'] ?? 0) + ($purge_all_stats['transients'] ?? 0), // Combine object and transient count from purge_all
            $purge_all_stats['wordpress'] ?? 0
        );
    } else {
        $message_parts[] = __('- Plugin Internal Caches: Purged (details unavailable).', FRL_PREFIX);
    }

    // Plugin Transients (scoped to this plugin only — other plugins'/themes'
    // transients are intentionally left untouched by this action)
    if (isset($stats['plugin_transients_deleted']['transients'])) {
        $message_parts[] = sprintf(__('- Plugin Transients: %d deleted.', FRL_PREFIX), $stats['plugin_transients_deleted']['transients']);
    } else {
        $message_parts[] = __('- Plugin Transients: Cleared (details unavailable).', FRL_PREFIX);
    }

    $message_parts[] = __('- WordPress rewrite rules flushed.', FRL_PREFIX);

    return ['success' => true, 'message_parts' => $message_parts, 'notice_type' => $notice_type];
}

/**
 * Handle the 'flush_rewrite_rules' action.
 *
 * @return array{success: bool, message_parts: string[], notice_type: string} Result array.
 */
function frl_handle_action_flush_rewrite_rules()
{
    frl_flush_rewrite_rules();

    return [
        'success' => true,
        'message_parts' => [__('Rewrite rules flushed successfully. All caches cleared and third-party cache plugins notified.', FRL_PREFIX)],
        'notice_type' => 'success'
    ];
}
