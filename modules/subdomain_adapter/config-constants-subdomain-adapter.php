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

/**
 * Block types that commonly contain site URLs.
 *
 * These block names skip the per-block `str_contains` host-scan guard and proceed
 * directly to URL transformation. Missing a block type here is not a correctness
 * issue — the `str_contains` fallback in the render_block filter catches URLs in
 * any block, regardless of type. This list exists purely as a performance shortcut.
 *
 * Empty string entry matches dynamic/reusable blocks (blockName === '').
 * The 'acf/' prefix matches all ACF blocks (str_starts_with check).
 *
 * Extend this list to add third-party block types that frequently contain
 * site URLs (e.g., Kadence Blocks, GenerateBlocks) to skip the host-scan
 * guard for those blocks.
 *
 * @see Frl_Subdomain_Adapter_Legacy::filter_render_block()
 */
define('FRL_SUBDOMAIN_ADAPTER_LEGACY_URL_BLOCKS', [
    'core/navigation',
    'core/navigation-link',
    'core/navigation-submenu',
    'core/button',
    'core/image',
    'core/custom-html',
]);
