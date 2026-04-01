<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// --- Constants ---
const FRL_ENV_PREFIX = FRL_PREFIX . '_';
const FRL_ENV_CACHE_GROUP = 'environment';
const FRL_ENV_CACHE_KEY = 'state';
const FRL_IGNORE_PLUGINS_KEY = 'ignore_plugins';
const FRL_IGNORE_OPTIONS_KEY = 'ignore_options';
const FRL_ENV_FILES_PATH = 'config/environment-options/';
// Clear all website transients on environment migration on admin visits.
// Default true (safe with per-host throttle and admin-only guard).
const FRL_ENV_CLEAR_WEBSITE_TRANSIENTS = true;

// --- Environment Map ---
const FRL_ENV_MAP = [
    'pbservices.ge'             => 'FRL_ENV_PBS_PRODUCTION',
    'pbproperty.ge'             => 'FRL_ENV_PBP_PRODUCTION',
    'pbnova.com'                => 'FRL_ENV_PBNOVA_PRODUCTION',
    'fralenuvole.art'           => 'FRL_ENV_FRALENUVOLE_PRODUCTION',
    'staging.pbservices.ge'     => 'FRL_ENV_PBS_STAGING',
    'staging.pbproperty.ge'     => 'FRL_ENV_PBP_STAGING',
    'staging.pbnova.com'        => 'FRL_ENV_PBNOVA_STAGING',
    'staging.fralenuvole.art'   => 'FRL_ENV_FRALENUVOLE_STAGING',
];

// --- Default Configurations ---
/** Complete Base Default Configuration (Likely resembles Staging) */
const FRL_ENV_DEFAULT = [
    'prefix' => 'default', // Requires override
    'type' => 'production',   // Base type
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
        'pbs' => true,
        'pbproperty' => false,
        'frl' => false,
    ],
    'wp_options' => [
        'blog_public' => 1,
    ],
    'plugin_options' => [
        'wsform_webhook' => false,
        'disable_themekit' => true,
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
    ]
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
        'disable_themekit' => false,
        'footer_html' => 'file',
        'debug' => true,
        'error_reporting_email' => false,
        ]
];

/** Overrides for PBS Production */
const FRL_ENV_PBS_PRODUCTION = [
    'prefix' => 'pbs',
    'plugin_options' => [
        'wsform_webhook' => true,
    ]
];

/** Overrides for PBS Staging */
const FRL_ENV_PBS_STAGING = [
    'prefix' => 'pbs',
    'plugin_options' => [
        'wsform_webhook' => true,
        'footer_html' => '',
        'footer_html_php' => false,
    ]
];

/** Overrides for PBP Production */
const FRL_ENV_PBP_PRODUCTION = [
    'prefix' => 'pbp',
    'modules' => [
        'pbs' => false,
        'pbproperty' => true,
    ],
    'plugin_options' => [
        'wsform_webhook' => true,
        'header_html' => 'file',
        'header_html_php' => true,
    ]
];

/** Overrides for PBP Staging */
const FRL_ENV_PBP_STAGING = [
    'prefix' => 'pbp',
    'modules' => [
        'pbs' => false,
        'pbproperty' => true,
    ],
    'plugin_options' => [
        'wsform_webhook' => true,
        'header_html' => 'file',
        'header_html_php' => true,
    ]
];

// --- Environment-Specific Configurations ---
/** Overrides for PB Nova Production */
const FRL_ENV_PBNOVA_PRODUCTION = [
    'prefix' => 'pbnova',
    'plugins' => [
        'active' => [
            'query-monitor/query-monitor.php',
            'better-search-replace/better-search-replace.php',
        ],
        'inactive' => [
            'docket-cache/docket-cache.php',
            'litespeed-cache/litespeed-cache.php',
        ]
    ],
    'modules' => [
        'pbnova' => true,
        'pbs' => false,
    ],
    'wp_options' => [
        'blog_public' => 0,
    ],
    'plugin_options' => [
        'disable_themekit' => false,
        'debug' => true
    ]
];

// --- Environment-Specific Configurations ---
/** Overrides for PB Nova Production */
const FRL_ENV_PBNOVA_STAGING = [
    'prefix' => 'pbnova',
    'modules' => [
        'pbnova' => true,
        'pbs' => false,
    ],
];

// --- Environment-Specific Configurations ---
/** Overrides for FRL Production */
const FRL_ENV_FRALENUVOLE_PRODUCTION = [
    'prefix' => 'frl',
    'plugins' => [
        'active' => [
            'query-monitor/query-monitor.php',
        ],
    ],
    'modules' => [
        'pbs' => false,
        'frl' => true,
    ],
    'plugin_options' => [
        'disable_themekit' => false,
        'debug' => true
    ]
];

// --- Environment-Specific Configurations ---
/** Overrides for FRL Production */
const FRL_ENV_FRALENUVOLE_STAGING = [
    'prefix' => 'frl',
    'modules' => [
        'pbs' => false,
    ],
];
