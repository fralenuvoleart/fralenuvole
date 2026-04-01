<?php

/**
 * Fralenuvole
 * functions-plugin-actions.php - Plugin _GET_action handler functions
 * Loaded in both logged frontend and admin contexts
 *
 * This file contains all the admin action handlers that process GET/POST requests
 * for cache clearing, environment resets, and other administrative actions.
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handle admin actions dispatched via GET requests.
 *
 * This function processes direct GET requests with frl_action parameter.
 * It is completely independent from admin-post.php handlers registered by frl_autodiscover_admin_actions.
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
 * Verify nonce for plugin actions
 *
 * @param string $action_name The action name to verify nonce for
 * @return bool True if nonce is valid, false otherwise
 */
function frl_verify_plugin_action_nonce($action_name)
{
    // Check if this action is registered as a low-security/public action
    // Ideally this registry would be shared, but for now we define the logic here:
    // If the action is known to be allowed for 'read' users in the dispatcher, we can skip strict nonce checks.
    // However, frl_verify_simple_nonce defaults to 'manage_options'.
    
    $capability = 'manage_options';
    $skip_nonce_verification = false;

    // Modular check: If action is meant for logged-in users (emergency access), adjust cap and skip nonce
    if (in_array($action_name, FRL_PUBLIC_ACTIONS) && is_user_logged_in()) {
        $capability = 'read';
        $skip_nonce_verification = true;
    }

    if ($skip_nonce_verification) {
        return true;
    }

    return frl_verify_simple_nonce(
        $action_name,                               // Will be converted to (frl_prefix($action_name) = frl_action_name)
        frl_prefix($action_name) . '_nonce',       // Field name matches frl_render_action_button format
        'GET',                                      // Data source
        $capability,                                // Capability check
        false                                       // Return bool, don't die
    );
}

/**
 * Verify simple static nonce with optional capability check
 *
 * Helper for standard nonce verification patterns with static action names.
 * Handles the common pattern of checking nonce + capability + error handling.
 *
 * @param string $action Action name (will be prefixed with plugin prefix unless $raw_action is true)
 * @param string $nonce_field Field name containing the nonce (default: 'nonce')
 * @param string $source 'GET', 'POST', or 'REQUEST' (default: 'GET')
 * @param string|null $cap Required capability for access (default: 'manage_options')
 * @param bool $die Whether to wp_die() on failure (default: true)
 * @param bool $raw_action Whether to use $action as-is without prefixing (default: false)
 * @return bool True if verified, false if $die is false
 */
function frl_verify_simple_nonce($action, $nonce_field = 'nonce', $source = 'GET', $cap = 'manage_options', $die = true, $raw_action = false)
{
    // Build the nonce action
    $nonce_action = $raw_action ? $action : frl_prefix($action);

    // Get the appropriate superglobal
    $data = [];
    switch (strtoupper($source)) {
        case 'GET':
            $data = $_GET;
            break;
        case 'POST':
            $data = $_POST;
            break;
        case 'REQUEST':
        default:
            $data = $_REQUEST;
            break;
    }

    // Check if nonce field exists
    if (!isset($data[$nonce_field])) {
        if ($die) {
            wp_die(__('Security check failed: Nonce not found.', FRL_PREFIX));
        }
        return false;
    }

    // Verify the nonce
    $nonce_verified = wp_verify_nonce(sanitize_text_field($data[$nonce_field]), $nonce_action);

    if (!$nonce_verified) {
        if ($die) {
            wp_die(__('Security check failed.', FRL_PREFIX));
        }
        return false;
    }

    // Check capability if required
    if ($cap !== null && !frl_has_access($cap)) {
        if ($die) {
            wp_die(__('You do not have sufficient permissions to access this page.', FRL_PREFIX));
        }
        return false;
    }

    return true;
}

/**
 * Handle 'clear_plugin_transients' action.
 * @return array Result array ['success' => bool, 'message_parts' => array, 'notice_type' => string]
 */
function frl_handle_action_clear_plugin_transients()
{
    $deleted = frl_cache_clear('plugin_transients');
    if (is_array($deleted) && isset($deleted['transients'])) {
        frl_cache_clear('adminui');

        return ['success' => true, 'message_parts' => [sprintf(
            __('Cleared %d plugin transients successfully', FRL_PREFIX),
            $deleted['transients']
        )], 'notice_type' => 'success'];
    } else {
        return ['success' => false, 'message_parts' => [__('Failed to clear plugin transients.', FRL_PREFIX)], 'notice_type' => 'error'];
    }
}

/**
 * Handle 'clear_website_transients' action.
 * @return array Result array ['success' => bool, 'message_parts' => array, 'notice_type' => string]
 */
function frl_handle_action_clear_website_transients()
{
    $deleted = frl_cache_clear('website_transients');
    if (is_array($deleted) && isset($deleted['transients'])) {
        frl_cache_clear('adminui');

        return ['success' => true, 'message_parts' => [sprintf(
            __('Cleared %d website transients successfully', FRL_PREFIX),
            $deleted['transients']
        )], 'notice_type' => 'success'];
    } else {
        return ['success' => false, 'message_parts' => [__('Failed to clear website transients.', FRL_PREFIX)], 'notice_type' => 'error'];
    }
}

/**
 * Handle 'clear_scripts_tags' action.
 * Groups to clear are defined by FRL_CACHE_SCRIPTS_GROUPS in config-cache.php.
 * @return array Result array ['success' => bool, 'message_parts' => array, 'notice_type' => string]
 */
function frl_handle_action_clear_scripts_tags()
{
    $message_parts = [];
    $success = false;

    foreach (FRL_CACHE_SCRIPTS_GROUPS as $group) {
        $stats = frl_cache_clear($group);

        if (is_array($stats) && isset($stats['persistent'])) {
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
 * Handle 'clear_cache_all' action.
 * @return array Result array ['success' => bool, 'message_parts' => array, 'notice_type' => string]
 */
function frl_handle_action_clear_cache_light()
{
    $stats = frl_cache_clear('light');
    $message_parts = [];
    if (is_array($stats)) {
        $message_parts[] = sprintf(
            __('Light Caches cleared: %d runtime, %d object cache, %d plugin transients, %d key cache, %d deferred writes', FRL_PREFIX),
            $stats['runtime'] ?? 0,
            $stats['object_cache'] ?? 0,
            $stats['transients'] ?? 0,
            $stats['key_cache'] ?? 0,
            $stats['deferred'] ?? 0
        );
        return ['success' => true, 'message_parts' => $message_parts, 'notice_type' => 'success'];
    } else {
        return ['success' => false, 'message_parts' => [__('Failed to clear cache or get stats.', FRL_PREFIX)], 'notice_type' => 'error'];
    }
}

/**
 * Handle 'clear_cache_all' action.
 * @return array Result array ['success' => bool, 'message_parts' => array, 'notice_type' => string]
 */
function frl_handle_action_clear_cache_all()
{
    $stats = frl_cache_clear('all');
    $message_parts = [];
    if (is_array($stats)) {
        $message_parts[] = sprintf(
            __('All Caches cleared: %d runtime, %d object cache, %d plugin transients, %d key cache, %d deferred writes', FRL_PREFIX),
            $stats['runtime'] ?? 0,
            $stats['object_cache'] ?? 0,
            $stats['transients'] ?? 0,
            $stats['key_cache'] ?? 0,
            $stats['deferred'] ?? 0
        );
        return ['success' => true, 'message_parts' => $message_parts, 'notice_type' => 'success'];
    } else {
        return ['success' => false, 'message_parts' => [__('Failed to clear cache or get stats.', FRL_PREFIX)], 'notice_type' => 'error'];
    }
}

/**
 * Handle 'clear_cache_hard_reset' action.
 * Calls the most comprehensive cache reset in Frl_Cache_Manager.
 *
 * @return array Result array ['success' => bool, 'message_parts' => array, 'notice_type' => string]
 */
function frl_handle_action_clear_cache_hard()
{
    if (!frl_has_access()) { // Ensure user has capabilities
        return ['success' => false, 'message_parts' => [__('You are not authorized to perform this action.', FRL_PREFIX)], 'notice_type' => 'error'];
    }

    $stats = frl_cache_clear('hard');
    frl_schedule_admin_rewrite_flush();

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

    // Global WP Object Cache Flush
    $oc_flush_status = $stats['wp_object_cache_global_flush'] ?? 'unknown';
    $message_parts[] = __('- Global WP Object Cache Flush:', FRL_PREFIX) . ' ' . esc_html(ucfirst(str_replace('_', ' ', $oc_flush_status)));

    // All Website Transients
    if (isset($stats['all_website_transients_deleted']['transients'])) {
        $message_parts[] = sprintf(__('- All Website Transients: %d deleted.', FRL_PREFIX), $stats['all_website_transients_deleted']['transients']);
    } else {
        $message_parts[] = __('- All Website Transients: Cleared (details unavailable).', FRL_PREFIX);
    }

    $message_parts[] = __('- WordPress rewrite rules flushed.', FRL_PREFIX);

    return ['success' => true, 'message_parts' => $message_parts, 'notice_type' => $notice_type];
}

/**
 * Handle 'flush_rewrite_rules' action.
 * @return array Result array ['success' => bool, 'message_parts' => array, 'notice_type' => string]
 */
function frl_handle_action_flush_rewrite_rules()
{
    // Schedule the flush to avoid redirect race conditions. The actual flush
    // and cache clearing will happen on the next admin page load.
    frl_schedule_admin_rewrite_flush();

    return [
        'success' => true,
        'message_parts' => [__('Rewrite rules have been scheduled for flushing. They will be applied on the next page load.', FRL_PREFIX)],
        'notice_type' => 'success'
    ];
}
