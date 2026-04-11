<?php
/**
 * Main plugin functionality
 *
 * Contains core initialization and feature setup functions.
 * Feature implementations moved to includes/common/
 *
 * @package FRL
 * @since 1.0.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fralenuvole
 * main.php - Code executed in both frontend and backend pages
 */

// Load feature modules
require_once __DIR__ . '/common/website-features.php';
require_once __DIR__ . '/common/performance.php';
require_once __DIR__ . '/common/media.php';
require_once __DIR__ . '/common/navigation.php';

// Load logged-user features (only for logged-in users)
if (frl_is_logged_in()) {
    require_once __DIR__ . '/common/logged-user.php';
}

// ============================================================================
// HOOK REGISTRATIONS
// ============================================================================

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

// ============================================================================
// CORE FUNCTIONS
// ============================================================================

function frl_disable_oembed_discovery(): void
{
    add_filter('embed_oembed_discover', '__return_false', 10, 0);
}

/**
 * Initialize main plugin functionality
 *
 * @hook init
 */
function frl_main_init()
{
    add_post_type_support('page', 'excerpt');

    frl_enable_custom_avatar();
    frl_add_image_sizes();
    frl_disable_wp_core_features();
}

/**
 * Set Admin Cookie Expiration to 1 Year
 * @param int $expirein Expiration time in seconds
 * @return int One year expiration time in seconds
 */
function frl_extend_admin_cookie(int $expirein): int
{
    if (!frl_get_option('extend_admin_cookie')) {
        return $expirein;
    }

    return YEAR_IN_SECONDS;
}

/**
 * Process deferred cache writes on shutdown
 * Handles batching and error management for cache operations
 */
function frl_process_deferred_writes()
{
    // Ensure no pending results are lingering
    frl_flush_db();

    $writes = frl_cache_get_deferred_writes();
    if (empty($writes)) {
        return;
    }

    // Merge duplicate writes (last write wins)
    $merged = [];
    foreach ($writes as $group => $items) {
        foreach ($items as $key => $value) {
            $merged[$group][$key] = $value;
        }
    }

    // Process merged writes with error handling
    foreach ($merged as $group => $items) {
        try {
            // Process each group in a separate try-catch
            foreach ($items as $key => $value) {
                frl_cache_set($group, $key, $value);
            }
        } catch (Exception $e) {
            frl_log("Error processing deferred writes for group {group}: {error}", ['group' => $group, 'error' => $e->getMessage()]);
        }
    }

    // Clear deferred writes using helper
    frl_cache_clear_deferred_writes();

    // Final flush to ensure no lingering results
    frl_flush_db();
}
