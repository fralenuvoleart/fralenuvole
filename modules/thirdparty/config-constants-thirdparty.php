<?php

/**
 * Thirdparty module constants
 *
 * Bidirectional cache bridge configuration.
 * - Inbound: third-party purge → clear fralenuvole caches
 * - Outbound: fralenuvole purge → notify third-party cache plugins
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Inbound hooks: when these third-party actions fire, fralenuvole clears its own caches.
 *
 * Keys = WordPress action hook name fired by the external plugin.
 * Values = array of directives:
 *   'label' – human-readable plugin name (used in admin notices).
 *   'clear' – fralenuvole cache group(s) to clear (string or array).
 *             'light' already covers all script/asset groups (html, versions, shortcodes).
 *   'rewrite_flush' – whether to also schedule a rewrite-rules flush.
 */
const FRL_THIRDPARTY_INBOUND_HOOKS = [
    // LiteSpeed Cache — post-purge hook (litespeed_purged_all, not litespeed_purge_all)
    'litespeed_purged_all' => [
        'label' => 'LiteSpeed Cache',
        'clear' => 'light',
        'rewrite_flush' => true,
    ],
    // Breeze (Cloudways) — Breeze fires this action from settings saves, WP-CLI,
    // and compatibility hooks, but NOT from the admin-bar "Purge All" button
    // (that path calls internal methods directly without do_action).
    'breeze_clear_all_cache' => [
        'label' => 'Breeze',
        'clear' => 'light',
        'rewrite_flush' => true,
    ],
    // WP Rocket — post-purge hook fired after rocket_clean_domain() completes
    'after_rocket_clean_domain' => [
        'label' => 'WP Rocket',
        'clear' => 'light',
        'rewrite_flush' => true,
    ],
];

/**
 * Inbound query-based triggers: detect third-party cache purges via URL parameters.
 *
 * These are checked on admin_init when no inbound hook was fired.
 * Keys = unique identifier for the detection mechanism.
 * Values = array of simple directives:
 *   'label' – human-readable plugin name (used in admin notices).
 *   'clear' – fralenuvole cache group(s) to clear (string or array).
 *   'rewrite_flush' – whether to also schedule a rewrite-rules flush.
 *   'query_key' – the $_GET parameter key to detect (nonce is auto-verified if present).
 */
const FRL_THIRDPARTY_INBOUND_QUERIES = [
    // Breeze admin bar "Purge All" button uses query param, not action hook
    'breeze_admin_bar_purge' => [
        'label' => 'Breeze',
        'clear' => 'light',
        'rewrite_flush' => true,
        'query_key' => 'breeze_purge',
    ],
];

/**
 * Outbound hooks: when fralenuvole purges its caches, notify these third-party plugins.
 *
 * Each entry describes how to call the external plugin's purge API.
 * 'type' – 'action' (do_action) or 'function' (direct call).
 * 'target' – the action name or function name to invoke.
 * 'check' – function/class that must exist (prevents fatals when plugin is inactive).
 * 'triggers' – internal flush events that should notify this plugin.
 * Supported values: 'hard', 'all', 'light', 'rewrite_flush'.
 * Dispatched automatically by frl_cache_clear() and frl_execute_rewrite_flush().
 */
const FRL_THIRDPARTY_OUTBOUND_HOOKS = [
    'litespeed' => [
        'label'    => 'LiteSpeed Cache',
        'type'     => 'action',
        'target'   => 'litespeed_purge_all',
        'check'    => 'LiteSpeed\\Core',
        'triggers' => ['hard', 'rewrite_flush'],
    ],
    'breeze' => [
        'label'    => 'Breeze',
        'type'     => 'action',
        'target'   => 'breeze_clear_all_cache',
        'check'    => 'Breeze_Admin',
        'triggers' => ['hard', 'rewrite_flush'],
    ],
    'wp_rocket' => [
        'label'    => 'WP Rocket',
        'type'     => 'function',
        'target'   => 'rocket_clean_domain',
        'check'    => 'rocket_clean_domain',
        'triggers' => ['hard', 'rewrite_flush'],
    ],
];

/**
 * Filtered accessor for inbound hook configuration.
 *
 * Third-party code can add new inbound hooks via:
 *   add_filter('frl_thirdparty_inbound_hooks', function(array $hooks): array {
 *       $hooks['my_plugin_clear_cache'] = ['label' => 'My Plugin', 'clear' => 'light'];
 *       return $hooks;
 *   });
 *
 * The result is cached statically so the filter runs once per request.
 * Guards are function_exists-wrapped to prevent fatal errors if both thirdparty
 * modules happen to be loaded in the same request.
 */
if (!function_exists('frl_thirdparty_get_inbound_hooks')) {
    function frl_thirdparty_get_inbound_hooks(): array
    {
        static $hooks = null;
        if ($hooks === null) {
            $hooks = apply_filters('frl_thirdparty_inbound_hooks', FRL_THIRDPARTY_INBOUND_HOOKS);
        }
        return $hooks;
    }
}

/**
 * Filtered accessor for inbound query-trigger configuration.
 *
 * Third-party code can add new query-param triggers via:
 *   add_filter('frl_thirdparty_inbound_queries', function(array $queries): array {
 *       $queries['my_plugin_purge'] = ['label' => 'My Plugin', 'clear' => 'light', 'query_key' => 'myplugin_purge'];
 *       return $queries;
 *   });
 */
if (!function_exists('frl_thirdparty_get_inbound_queries')) {
    function frl_thirdparty_get_inbound_queries(): array
    {
        static $queries = null;
        if ($queries === null) {
            $queries = apply_filters('frl_thirdparty_inbound_queries', FRL_THIRDPARTY_INBOUND_QUERIES);
        }
        return $queries;
    }
}

/**
 * Filtered accessor for outbound hook configuration.
 *
 * Third-party code can add new outbound integrations via:
 *   add_filter('frl_thirdparty_outbound_hooks', function(array $hooks): array {
 *       $hooks['my_cache_plugin'] = [
 *           'type'     => 'action',
 *           'target'   => 'my_cache_plugin_purge_all',
 *           'check'    => 'My_Cache_Plugin',
 *           'triggers' => ['hard', 'rewrite_flush'],
 *       ];
 *       return $hooks;
 *   });
 */
if (!function_exists('frl_thirdparty_get_outbound_hooks')) {
    function frl_thirdparty_get_outbound_hooks(): array
    {
        static $hooks = null;
        if ($hooks === null) {
            $hooks = apply_filters('frl_thirdparty_outbound_hooks', FRL_THIRDPARTY_OUTBOUND_HOOKS);
        }
        return $hooks;
    }
}
