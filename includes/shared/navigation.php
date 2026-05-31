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
 * Initialize nav menu shortcode URL processing.
 *
 * Hooks into wp_nav_menu_objects to evaluate [frl_*] shortcodes
 * in menu item URLs and replace them with the resolved URL.
 * Also bypasses admin URL validation for shortcode patterns.
 */
function frl_nav_menu_shortcodes_init()
{
    if (!frl_get_option('nav_menu_shortcodes')) {
        return;
    }

    // Restore shortcode URL from raw POST after WordPress sanitizes it
    add_action('wp_update_nav_menu_item', 'frl_restore_nav_menu_shortcode_url', 10, 3);

    // Process shortcodes on frontend
    add_filter('wp_nav_menu_objects', 'frl_process_nav_menu_shortcode_urls', 10, 2);
}
add_action('init', 'frl_nav_menu_shortcodes_init', 20);

/**
 * Restore [frl_*] shortcode URL after WordPress sanitizes it during save.
 *
 * esc_url_raw() strips [ and ] characters. This action hook restores
 * the original shortcode pattern from the raw POST data.
 *
 * @param int $menu_id ID of the updated menu
 * @param int $menu_item_db_id ID of the updated menu item
 * @param string $args Arguments used to update the menu item
 */
function frl_restore_nav_menu_shortcode_url($menu_id, $menu_item_db_id, $args)
{
    $raw_url = $_POST['menu-item-url'][$menu_item_db_id] ?? '';
    if (preg_match('/^\[frl_[^\]]+\]$/', trim($raw_url))) {
        global $wpdb;
        $wpdb->update(
            $wpdb->postmeta,
            ['meta_value' => trim($raw_url)],
            ['post_id' => $menu_item_db_id, 'meta_key' => '_menu_item_url']
        );
    }
}

/**
 * Detect [frl_*] shortcodes in nav menu item URLs and replace them.
 *
 * Only processes shortcodes starting with 'frl_' prefix.
 *
 * @param array $items Array of menu item objects
 * @param stdClass $args Menu arguments
 * @return array Modified menu items
 */
function frl_process_nav_menu_shortcode_urls($items, $args)
{
    foreach ($items as &$item) {
        // Check if the URL contains an frl_ shortcode pattern
        if (preg_match('/^\[frl_([^\]]+)\]$/', trim($item->url), $matches)) {
            $shortcode = $matches[0];
            $url = do_shortcode($shortcode);

            // Only replace if shortcode produced a valid URL different from the original
            if ($url && is_string($url) && $url !== $shortcode && filter_var($url, FILTER_VALIDATE_URL)) {
                $item->url = trim($url);
            }
        }
    }
    return $items;
}
