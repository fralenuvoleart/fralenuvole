<?php
/**
 * Fralenuvole ThemeKit Configuration
 *
 * Configuration constants for ThemeKit.
 *
 * @package Fralenuvole
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Style loading priorities for frontend assets.
 *
 * Used to ensure a deterministic CSS loading order:
 * 1. WP Core Styles (default priority 10)
 * 2. ThemeKit Styles (priority 11)
 * 3. Theme Stylesheet (priority 12)
 * 4. Module Styles (priority 15)
 * 
 */
const FRL_THEMEKIT_STYLE_PRIORITY = [
    'themekit' => 11,
    'modules'  => 15,
];

/**
 * Themekit defined Patterns Categories
 */
const FRL_THEMEKIT_PATTERNS_CATEGORIES = [
    'sections',
    'queries',
    'ACF',
    'editorial'
];

/**
 * Query parameters that trigger body class additions
 *
 * When these query parameters are present in the URL, a corresponding
 * body class "has-{param}" will be added (e.g. "has-frlq").
 */
const FRL_THEMEKIT_TRACKED_QUERY_PARAMS = [
    'frlq',   // Bible reference query param
];