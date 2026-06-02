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
 * Dispatched automatically by frl_cache_clear() and frl_flush_rewrite_rules().
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

const FRL_THIRDPARTY_SCHEMA_PROPERTIES = [
    'Organization' => [
        'address'    => [
            '@type' => 'PostalAddress',
            'addressCountry' => 'GE',
            'streetAddress' => 'Zakaria Paliashvili Street 26',
            'addressLocality' => 'Tbilisi',
            'postalCode' => '0179',
        ],
        'areaServed' => 'Worldwide',
    ],
    'Service' => [
        'publisher' => [
            "@type" => "Organization",
            "@id" => "https://pbservices.ge#Organization",
        ]
    ],
    'WebSite' => [
        'publisher' => [
            "@type" => "Organization",
            "@id" => "https://pbservices.ge#Organization",
        ]
    ],
    'WebPage' => [
        'publisher' => [
            "@type" => "Organization",
            "@id" => "https://pbservices.ge#Organization",
        ]
    ],
    'AboutPage' => [
        'publisher' => [
            "@type" => "Organization",
            "@id" => "https://pbservices.ge#Organization",
        ]
    ],
];
