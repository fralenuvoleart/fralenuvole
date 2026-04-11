<?php
/**
 * Navigation features
 * - Makes WordPress navigation menus translatable (Polylang)
 * - Translates wp_navigation posts between languages
 *
 * @package FRL
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Make navigation menus translatable
 * @param array $post_types Post types
 * @param bool $is_settings Whether this is settings context
 * @return array Modified post types
 */
function frl_making_wp_navigation_translatable($post_types, $is_settings)
{
    if (!$is_settings) {
        $post_types['wp_navigation'] = 'wp_navigation';
    }
    return $post_types;
}

/**
 * Render block core navigation with translation
 * @param array $settings Block settings
 * @param array $metadata Block metadata
 * @return array Modified settings
 */
function frl_render_block_core_navigation_translation($settings, $metadata)
{
    // Only proceed for navigation blocks
    if ('core/navigation' !== $metadata['name'] || !frl_is_multilingual('pll_get_post')) {
        return $settings;
    }

    // Get languages
    $current_lang = frl_get_language();
    $default_lang = frl_get_default_language();

    // --- Install custom render callback for ALL languages ---
    $settings['render_callback'] = function ($attributes, $content, $block) use ($current_lang, $default_lang) {
        // If no ref attribute, render normally
        if (!isset($attributes['ref'])) {
            return render_block_core_navigation($attributes, $content, $block);
        }

        $nav_id = absint($attributes['ref']);
        $final_nav_id = $nav_id; // Default to original ID

        // Only attempt translation for non-default languages
        if (!empty($current_lang) && $current_lang !== $default_lang) {
            $cache_key = "wp_navigation_{$nav_id}";

            $translated_id = frl_cache_remember('permalinks', $cache_key, function () use ($nav_id, $current_lang) {
                // pll_get_post returns 0 for non-existing translations
                return pll_get_post($nav_id, $current_lang);
            });

            // Only use translated ID if it's positive (not 0) and differs from original
            if ($translated_id > 0 && $translated_id !== $nav_id) {
                $final_nav_id = absint($translated_id);
            }
        }

        // Update the ref attribute with the appropriate ID
        $attributes['ref'] = $final_nav_id;

        // Always call the original renderer to ensure assets are loaded
        return render_block_core_navigation($attributes, $content, $block);
    };

    return $settings;
}
