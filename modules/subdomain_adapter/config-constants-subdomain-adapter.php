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
 * Subdomain → { lang, main_domain } mapping.
 *
 * Key = full subdomain host (e.g., 'ru.pbservices.ge').
 * 'lang'        = Polylang language slug for this subdomain's content.
 * 'main_domain' = the primary domain this subdomain is a mirror of.
 *
 * Add entries here for new language subdomains, including cross-environment ones.
 *
 * @see FRL_SUBDOMAIN_ADAPTER_MAIN_DEFAULTS
 */
define('FRL_SUBDOMAIN_ADAPTER_MAP', [
    'ru.pbservices.ge' => [
        'lang'        => 'ru',
        'main_domain' => 'pbservices.ge',
    ],
    // Future same-env: 'ar.pbservices.ge' => ['lang' => 'ar', 'main_domain' => 'pbservices.ge'],
    // Future cross-env: 'ru.pbproperty.ge' => ['lang' => 'ru', 'main_domain' => 'pbproperty.ge'],
]);

/**
 * Main domain → default language slug (the one with NO URL prefix in Polylang).
 *
 * On the main domain, this language has "hide URL language information" enabled in
 * Polylang settings, so its URLs have no /lang/ prefix.
 *
 * Used by transform_url() to determine whether a language prefix needs to be added
 * when building cross-domain URLs.
 */
define('FRL_SUBDOMAIN_ADAPTER_MAIN_DEFAULTS', [
    'pbservices.ge'  => 'en',
    'pbproperty.ge'  => 'en',
]);
