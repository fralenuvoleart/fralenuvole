<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/config-defaults.php';

// --- Environment Map ---
const FRL_ENV_MAP = [
    'pbservices.ge'             => 'FRL_ENV_PBS_PRODUCTION',
    'ru.pbservices.ge'          => 'FRL_ENV_PBS_RU_SUBDOMAIN',
    'pbproperty.ge'             => 'FRL_ENV_PBP_PRODUCTION',
    'pbnova.com'                => 'FRL_ENV_PBNOVA_PRODUCTION',
    'fralenuvole.art'           => 'FRL_ENV_FRALENUVOLE_PRODUCTION',
    'master.fralenuvole.art'    => 'FRL_ENV_MASTER_TEMPLATE',
    'staging.pbservices.ge'     => 'FRL_ENV_PBS_STAGING',
    'staging.pbproperty.ge'     => 'FRL_ENV_PBP_STAGING',
    'staging.pbnova.com'        => 'FRL_ENV_PBNOVA_STAGING',
];

// --- PBS ---
const FRL_ENV_PBS_TEMPLATE = [
    'prefix' => 'pbs',
    'webhook_config' => 'pbs',
    'modules' => [
        'pbs' => true,
        'subdomain_adapter' => true,
    ],
    'plugin_options' => [
        'wsform_webhook' => true,
    ],
];

const FRL_ENV_PBS_PRODUCTION = [
    'extends' => 'FRL_ENV_PBS_TEMPLATE',
];

/** RU Subdomain - PBS replica on Russian server */
const FRL_ENV_PBS_RU_SUBDOMAIN = [
    'extends' => 'FRL_ENV_PBS_TEMPLATE',
    'prefix' => 'pbs_ru',
];

const FRL_ENV_PBS_STAGING = [
    'extends' => 'FRL_ENV_PBS_TEMPLATE',
    'type' => 'staging',
];

// --- PBP ---
const FRL_ENV_PBP_TEMPLATE = [
    'prefix' => 'pbp',
    'webhook_config' => 'pbp',
    'modules' => [
        'pbproperty' => true,
    ],
    'plugin_options' => [
        'wsform_webhook' => true,
        'header_html' => 'file',
        'header_html_php' => true,
    ]
];

const FRL_ENV_PBP_PRODUCTION = [
    'extends' => 'FRL_ENV_PBP_TEMPLATE',
];

const FRL_ENV_PBP_STAGING = [
    'extends' => 'FRL_ENV_PBP_TEMPLATE',
    'type' => 'staging',
];

// --- PB Nova ---
const FRL_ENV_PBNOVA_TEMPLATE = [
    'prefix' => 'pbnova',
    'modules' => [
        'pbnova' => true,
    ],
    'wp_options' => [
        'blog_public' => 0,
    ],
    'plugin_options' => [
    ]
];

const FRL_ENV_PBNOVA_PRODUCTION = [
    'extends' => 'FRL_ENV_PBNOVA_TEMPLATE',
    'type' => 'staging', // Temporary: treated as staging until site is ready for production
];

const FRL_ENV_PBNOVA_STAGING = [
    'extends' => 'FRL_ENV_PBNOVA_TEMPLATE',
    'type' => 'staging',
];

// --- Fralenuvole ---
const FRL_ENV_FRALENUVOLE_PRODUCTION = [
    'extends' => 'FRL_ENV_MASTER_TEMPLATE',
    'prefix' => 'frl',
    'modules' => [
        'frl' => true,
    ],
];
