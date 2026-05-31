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

    // Retrieve current language code
    $current_lang = frl_get_language();

    // Define a custom render callback to resolve translated navigation IDs
    $settings['render_callback'] = function ($attributes, $content, $block) use ($current_lang) {
        // Render normally if no reference ID is provided
        if (!isset($attributes['ref'])) {
            return render_block_core_navigation($attributes, $content, $block);
        }

        $nav_id = absint($attributes['ref']);
        $final_nav_id = $nav_id; // Default to original ID

        // Resolve translated navigation ID — always attempt translation regardless of
        // default language. pll_get_post() returns the original ID when no translation
        // exists, so the guard would be redundant and broke subdomain adapter setups
        // where default_lang is overridden at runtime to match current_lang.
        if (!empty($current_lang)) {
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

/**
 * Initialize nav menu URL transform processing.
 *
 * Hooks into wp_nav_menu_objects to transform #frl_url_* fragment URLs
 * in menu item URLs into real URLs.
 */
function frl_nav_menu_custom_urls_init()
{
    if (!frl_get_option('nav_menu_custom_urls')) {
        return;
    }

    add_filter('wp_nav_menu_objects', 'frl_process_nav_menu_url_transforms', 10, 2);
}

/**
 * Transform #frl_url_* fragment URLs in nav menu items.
 *
 * Pattern: #frl_url_{type}={value}
 * Collects handlers via 'frl_nav_menu_url_transforms' filter.
 *
 * @param array $items Array of menu item objects
 * @param stdClass $args Menu arguments
 * @return array Modified menu items
 */
function frl_process_nav_menu_url_transforms($items, $args)
{
    /**
     * Collect URL transform handlers.
     *
     * Each handler adds a type => callback pair.
     * Callback receives $value, returns URL string or false.
     *
     * @param array $handlers Empty array to populate.
     */
    $handlers = apply_filters('frl_nav_menu_url_transforms', []);

    if (empty($handlers)) {
        return $items;
    }

    foreach ($items as &$item) {
        $url = trim($item->url);

        // Match #frl_url_{type}={value} pattern
        if (!preg_match('/^#frl_url_([a-z0-9_]+)=(.+)$/', $url, $matches)) {
            continue;
        }

        $type = $matches[1];
        $value = $matches[2];

        if (!isset($handlers[$type])) {
            continue;
        }

        $resolved = call_user_func($handlers[$type], $value);
        if ($resolved && is_string($resolved) && filter_var($resolved, FILTER_VALIDATE_URL)) {
            $item->url = $resolved;
        }
    }
    return $items;
}
