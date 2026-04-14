<?php
/**
 * Performance features
 * - Critical CSS loading
 *
 * @package FRL
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Add critical CSS functionality
 *
 * @hook wp_head
 */
function frl_add_critical_css()
{
    if (!frl_get_option('critical_css')) {
        return;
    }

    $css = frl_get_critical_css_data();

    if (frl_is_array_not_empty($css)) {
        printf(
            '<style id="%s-critical-css" data-lastmod="%s" data-no-defer="1" data-plugin="%s" data-parsing="critical-css">%s</style>',
            FRL_PREFIX,
            date('Y-m-d-H:i', $css['mtime']),
            FRL_NAME,
            $css['css']
        );
    }
}

/**
 * Get critical CSS data with caching
 *
 * @return array CSS data with mtime or empty array
 */
function frl_get_critical_css_data()
{
    $css_path = get_stylesheet_directory() . '/critical.css';
    $css_file = ['critical-css' => $css_path];
    // Get version with $absolute_path = true
    $css_version = frl_get_assets_versions($css_file, 'critical_css', 'versions', false);
    if (empty($css_version)) {
        return [];
    }

    $mtime = $css_version['critical-css'];

    if (!$mtime) {
        return [];
    }

    $critical_css = frl_cache_remember('html', "critical_css_{$mtime}",
        function () use ($css_path, $mtime) {
            // Single file read operation - more efficient than checking existence first
            $css_content = file_get_contents($css_path);
            if ($css_content === false || empty($css_content)) {
                return '';
            }

            $minified = frl_minify_css($css_content);

            // Cache the minified content with mtime
            $data = [
                'css' => $minified,
                'mtime' => $mtime
            ];

            return $data;
        }
    );

    return $critical_css;
}
