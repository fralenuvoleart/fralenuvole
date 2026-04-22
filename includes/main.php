<?php
/**
 * Main plugin functionality.
 *
 * Handles core initialization, hook registrations, and shared feature loading.
 *
 * @package Fralenuvole
 * @since 1.0.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Core execution logic for both frontend and backend.
 */

// Load feature modules
require_once __DIR__ . '/shared/website-features.php';
require_once __DIR__ . '/shared/media.php';
require_once __DIR__ . '/shared/navigation.php';

// Load logged-user features (only for logged-in users)
if (frl_is_logged_in()) {
    require_once __DIR__ . '/shared/logged-user.php';
}

add_action('init', 'frl_main_init', 10, 0);
add_action('init', 'frl_register_icon_block', 10, 0);
add_action('wp_head', 'frl_add_critical_css', -999, 1);

add_filter('render_block_data', 'frl_log_capture_render_block_enter', 10, 1);
add_filter('render_block', 'frl_log_capture_render_block_exit', 10, 2);
add_action('pre_get_posts', 'frl_log_capture_query', 1, 1);
add_filter('do_shortcode_tag', 'frl_log_capture_shortcode', 10, 4);

add_action('init', 'frl_disable_oembed_discovery', 5, 0);
add_filter('pll_get_post_types', 'frl_making_wp_navigation_translatable', 10, 2);
add_filter('block_type_metadata_settings', 'frl_render_block_core_navigation_translation', 10, 2);
add_filter('auth_cookie_expiration', 'frl_extend_admin_cookie', 10, 1);
add_action('shutdown', 'frl_process_deferred_writes', 10, 0);

/**
 * Disables oEmbed discovery to reduce external requests.
 *
 * @return void
 */
function frl_disable_oembed_discovery(): void
{
    add_filter('embed_oembed_discover', '__return_false', 10, 0);
}

/**
 * Initializes core plugin features on the 'init' hook.
 *
 * @return void
 */
function frl_main_init(): void
{
    add_post_type_support('page', 'excerpt');

    frl_enable_custom_avatar();
    frl_add_image_sizes();
    frl_disable_wp_core_features();
}

/**
 * Extends the WordPress admin authentication cookie expiration.
 *
 * @param int $expirein Original expiration time in seconds.
 * @return int New expiration time (1 year if enabled, otherwise original).
 */
function frl_extend_admin_cookie(int $expirein): int
{
    if (!frl_get_option('extend_admin_cookie')) {
        return $expirein;
    }

    return YEAR_IN_SECONDS;
}

/**
 * Processes and flushes deferred cache writes during the shutdown sequence.
 *
 * Merges duplicate writes and handles errors by re-queuing failed items.
 *
 * @return void
 */
function frl_process_deferred_writes(): void
{
    frl_flush_db();

    $writes = frl_cache_get_deferred_writes();
    if (empty($writes)) {
        return;
    }

    // Merge duplicate writes: last write wins
    $merged = [];
    foreach ($writes as $group => $items) {
        foreach ($items as $key => $value) {
            $merged[$group][$key] = $value;
        }
    }

    // Process merged writes and track failures for re-queuing
    $failed_items = [];
    foreach ($merged as $group => $items) {
        foreach ($items as $key => $value) {
            try {
                frl_cache_set($group, $key, $value);
            } catch (Exception $e) {
                frl_log("Error processing deferred write for group {group}, key {key}: {error}", [
                    'group' => $group,
                    'key' => $key,
                    'error' => $e->getMessage()
                ]);
                $failed_items[$group][$key] = $value;
            }
        }
    }

    // Re-queue failed items for the next cycle
    if (!empty($failed_items)) {
        foreach ($failed_items as $group => $items) {
            foreach ($items as $key => $value) {
                frl_cache_add_deferred_write($group, $key, $value);
            }
        }
    }

    frl_cache_clear_deferred_writes();
    frl_flush_db();
}
