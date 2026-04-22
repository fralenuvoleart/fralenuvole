<?php
/**
 * Navigation features
 * - Makes WordPress navigation menus translatable (Polylang)
 * - Translates wp_navigation posts between languages
 *
 * @package Fralenuvole
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers the 'wp_navigation' post type as translatable.
 *
 * @param array $post_types List of translatable post types.
 * @param bool  $is_settings Whether the current context is the settings page.
 * @return array Modified list of post types.
 */
function frl_making_wp_navigation_translatable($post_types, $is_settings)
{
    if (!$is_settings) {
        $post_types['wp_navigation'] = 'wp_navigation';
    }
    return $post_types;
}

/**
 * Injects a custom render callback into the core navigation block to handle language-specific menus.
 *
 * @param array $settings  Block settings.
 * @param array $metadata  Block metadata.
 * @return array Modified settings with the render callback.
 */
function frl_render_block_core_navigation_translation($settings, $metadata)
{
    // Only target core navigation blocks in multilingual environments
    if ('core/navigation' !== $metadata['name'] || !frl_is_multilingual('pll_get_post')) {
        return $settings;
    }

    // Retrieve current and default language codes
    $current_lang = frl_get_language();
    $default_lang = frl_get_default_language();

    // Define a custom render callback to resolve translated navigation IDs
    $settings['render_callback'] = function ($attributes, $content, $block) use ($current_lang, $default_lang) {
        // Render normally if no reference ID is provided
        if (!isset($attributes['ref'])) {
            return render_block_core_navigation($attributes, $content, $block);
        }

        $nav_id = absint($attributes['ref']);
        $final_nav_id = $nav_id; // Default to original ID

        // Resolve translated navigation ID for non-default languages
        if (!empty($current_lang) && $current_lang !== $default_lang) {
            $cache_key = "wp_navigation_{$nav_id}";

            $translated_id = frl_cache_remember('permalinks', $cache_key, function () use ($nav_id, $current_lang) {
                // Fetch translated post ID using Polylang
                return pll_get_post($nav_id, $current_lang);
            });

            // Use translated ID if valid and different from original
            if ($translated_id > 0 && $translated_id !== $nav_id) {
                $final_nav_id = absint($translated_id);
            }
        }

        // Update reference ID for the original renderer
        $attributes['ref'] = $final_nav_id;

        // Delegate to the original renderer to maintain core functionality and assets
        return render_block_core_navigation($attributes, $content, $block);
    };

    return $settings;
}
