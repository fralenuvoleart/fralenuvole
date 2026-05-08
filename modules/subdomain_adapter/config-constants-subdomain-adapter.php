<?php

/**
 * Subdomain Adapter — Configuration Constants
 *
 * @package Fralenuvole
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main domain → language-to-subdomain mapping.
 *
 * Top-level keys are recognized main domains (production, staging, etc.).
 * Inner keys are Polylang language slugs mapped to their subdomain hosts.
 * 'default_lang' key specifies the default language for that main domain
 * (the one with no URL prefix in Polylang).
 *
 * @example
 *   On pbservices.ge, RU content → ru.pbservices.ge, AR → ar.pbservices.ge.
 *   On staging.pbservices.ge, only RU is mapped (to same production subdomain).
 *   EN is the default on both (no prefix on main domain).
 *
 * @see Frl_Subdomain_Adapter::detect()
 */
define('FRL_SUBDOMAIN_ADAPTER_MAP', [
    'pbservices.ge' => [
        'ru'      => 'ru.pbservices.ge',
        // Future: 'ar' => 'ar.pbservices.ge',
        'default_lang' => 'en',
    ],
    'staging.pbservices.ge' => [
        'ru'      => 'ru.pbservices.ge',
        'default_lang' => 'en',
    ],
    // Future cross-env example:
    // 'pbproperty.ge' => [
    //     'ru'      => 'ru.pbproperty.ge',
    //     'default_lang' => 'en',
    // ],
]);
