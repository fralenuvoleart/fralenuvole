<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// --- System Constants ---
const FRL_ENV_PREFIX = FRL_PREFIX . '_';
const FRL_ENV_CACHE_GROUP = 'environment';
const FRL_ENV_CACHE_KEY = 'state';
const FRL_IGNORE_PLUGINS_KEY = 'ignore_plugins';
const FRL_IGNORE_OPTIONS_KEY = 'ignore_options';
const FRL_ENV_FILES_PATH = 'config/environment/env-snippets/';

// Clear all website transients on environment migration on admin visits.
// Default true (safe with per-host throttle and admin-only guard).
const FRL_ENV_CLEAR_WEBSITE_TRANSIENTS = true; 

// Subdomain prefixes that identify a staging environment.
// Used for sibling-domain detection (switcher button and secondary links filter).
// www. is NOT listed here — it is a canonical alias convention, handled separately.
const FRL_ENV_STAGING_PREFIXES = ['staging.', 'stage', 'dev.', 'test'];

// --- Base Default Configuration ---
/** Universal baseline applied to every site. Override per brand via templates. */
const FRL_ENV_DEFAULT = [
    'prefix' => 'default',         // Requires override — display label only
    'type' => 'production',        // Base type
    'webhook_config' => false,     // Requires override — false = no webhook lookup
    'plugins' => [
        'active' => [
            'litespeed-cache/litespeed-cache.php',
            'docket-cache/docket-cache.php',
        ],
        'inactive' => [
            'query-monitor/query-monitor.php',
            'better-search-replace/better-search-replace.php',
        ],
    ],
    'modules' => [
        'acf' => false,
        'wsform' => true,
        'thirdparty' => true,
        'pbnova' => false,
        'pbs' => false,
        'pbproperty' => false,
        'frl' => false,
        'subdomain_adapter' => false,
    ],
    'wp_options' => [
        'blog_public' => 1,
    ],
    'plugin_options' => [
        'wsform_webhook' => false,
        'schema_organization' => true,
        'schema_service' => true,
        'schema_person' => false,
        'schema_portfolio' => false,
        'header_html' => '',
        'header_html_php' => false,
        'footer_html' => 'file',
        'footer_html_php' => true,
        'debug' => false,
        'error_reporting_email' => true,
        'error_reporting_notice' => true,
        'error_reporting_warning' => true,
        'error_reporting_deprecated' => true,
    ],
];

/** Production Diffs from FRL_ENV_DEFAULT */
const FRL_ENV_DEFAULT_PRODUCTION = [
    // FRL_ENV_DEFAULT represents default production
];

/** Staging Diffs from FRL_ENV_DEFAULT */
const FRL_ENV_DEFAULT_STAGING = [
    'type' => 'staging',
    'plugins' => [
        'active' => [
            'query-monitor/query-monitor.php',
            'better-search-replace/better-search-replace.php',
        ],
        'inactive' => [
            'litespeed-cache/litespeed-cache.php',
            'docket-cache/docket-cache.php',
        ]
    ],
    'wp_options' => [
        'blog_public' => 0,
    ],
    'plugin_options' => [
        'footer_html' => 'file',
        'debug' => true,
        'error_reporting_email' => false,
    ]
];

// --- Generic Template ---
/** Base template for new websites with no dedicated brand template yet. */
const FRL_ENV_MASTER_TEMPLATE = [
    'prefix' => 'master',
    'plugins' => [
        'active' => [
            'query-monitor/query-monitor.php',
        ],
    ],
    'plugin_options' => [
        'debug' => true
    ]
];
