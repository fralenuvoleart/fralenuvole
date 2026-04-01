<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}
/**
 * Fralenuvole
 * config-themekit.php - Constants for themekit config
 */

 /**
  * Themekit relative path
  */
define('FRL_THEMEKIT_RELATIVE_PATH', 'includes/themekit/');
define('FRL_THEMEKIT_DIR_PATH', FRL_DIR_PATH . FRL_THEMEKIT_RELATIVE_PATH);

/**
 * Default system font stacks for the themekit.
 * This constant is used exclusively to build the `src: local(...)` attribute for advanced
 * @font-face configurations.
 * It intentionally duplicates the fontFamily definitions in theme.json,
 * which serve as the default system fonts declarations.
 */
const FRL_THEMEKIT_DEFAULT_SYSTEM_FONTS = [
    'frl-sans-serif' => [
        '-apple-system',
        'BlinkMacSystemFont',
        'avenir next',
        'avenir',
        'segoe ui',
        'helvetica neue',
        'Cantarell',
        'Ubuntu',
        'roboto',
        'noto',
        'helvetica',
        'arial',
        'sans-serif',
        'Liberation Sans'
    ],
    'frl-serif' => [
        'Iowan Old Style',
        'Apple Garamond',
        'Baskerville',
        'Times New Roman',
        'Droid Serif',
        'Times',
        'Source Serif Pro',
        'serif',
        'Apple Color Emoji',
        'Segoe UI Emoji',
        'Segoe UI Symbol',
        'Liberation Serif'
    ]
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
 * Themekit defined Patterns
 */
const FRL_THEMEKIT_PATTERNS = [
    [
        'slug' => 'main-cta',
        'label' => 'Main CTA Button',
        'categories' => [
            'editorial',
        ],
    ],
    [
        'slug' => 'section-title',
        'label' => 'Section Title',
        'categories' => [
            'editorial'
        ],
    ],
];

/**
 * Post-merge color override configuration
 *
 * Single list of setting keys to force-override from the plugin settings.json
 * after the main merge when "Override Theme settings.json" is enabled.
 *
 * Behavior:
 * - If the plugin value is a scalar (bool/string/number), it overwrites directly.
 * - If the plugin value is an array (preset list), it overwrites using WP's
 *   structured format: ['theme' => <array>, 'user' => [], 'core' => []].
 */
const FRL_THEMEKIT_FORCE_OVERRIDES = [
    'defaultGradients',
    'customGradient',
    'defaultDuotone',
    'customDuotone',
    'gradients',
    'duotone'
];

/**
 * Themekit defined Blocks
 */
const FRL_THEMEKIT_BLOCK_STYLES = [
    'button-primary',
    'button-secondary',
    'button-cta',
    'button-elegant',
	'button-small'
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
