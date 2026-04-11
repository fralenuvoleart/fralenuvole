<?php
/**
 * Website features
 * - Disable WordPress comments
 * - Disable oEmbed functionality
 * - Disable emoji support
 *
 * @package FRL
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Disable selected WordPress core features
 * Called from frl_main_init()
 */
function frl_disable_wp_core_features()
{
    if (frl_get_option('disable_oembed')) {
        frl_disable_oembed();
    }

    if (frl_get_option('disable_emojis')) {
        frl_remove_emojis();
    }

    if (frl_get_option('disable_comments')) {
        frl_disable_comments();
    }

    if (!frl_is_logged_in() && !is_login()) {
        wp_dequeue_style('dashicons');
        wp_deregister_style('dashicons');
    }
}

/**
 * Disable oEmbed functionality
 */
function frl_disable_oembed()
{
    // Use standard WordPress removal functions for built-in hooks
    remove_action('rest_api_init', 'wp_oembed_register_route');
    remove_filter('oembed_dataparse', 'wp_filter_oembed_result', 10);
    remove_action('wp_head', 'wp_oembed_add_discovery_links');
    remove_action('wp_head', 'wp_oembed_add_host_js');

    add_filter('embed_oembed_discover', '__return_false', 10, 0);
}

/**
 * Filter function used to remove the tinymce emoji plugin.
 * @param array $plugins
 * @return array
 */
function frl_disable_emojis_tinymce($plugins)
{
    if (frl_is_array_not_empty($plugins)) {
        return array_diff($plugins, array('wpemoji'));
    } else {
        return array();
    }
}

/**
 * Remove emoji CDN hostname from DNS prefetching hints.
 * @param array $urls URLs to print for resource hints.
 * @param string $relation_type The relation type the URLs are printed for.
 * @return array
 */
function frl_disable_emojis_remove_dns_prefetch($urls, $relation_type)
{
    if ('dns-prefetch' == $relation_type) {
        /** This filter is documented in wp-includes/formatting.php */
        $emoji_svg_url = apply_filters('emoji_svg_url', 'https://s.w.org/images/core/emoji/2/svg/');

        $urls = array_diff($urls, array($emoji_svg_url));
    }
    return $urls;
}

/**
 * Disable emojis functionality
 */
function frl_remove_emojis()
{
    if (!frl_get_option('disable_emojis')) {
        return;
    }

    remove_action('wp_head', 'print_emoji_detection_script', 7);
    remove_action('admin_print_scripts', 'print_emoji_detection_script');
    remove_action('wp_print_styles', 'print_emoji_styles');
    remove_action('admin_print_styles', 'print_emoji_styles');
    remove_filter('the_content_feed', 'wp_staticize_emoji');
    remove_filter('comment_text_rss', 'wp_staticize_emoji');
    remove_filter('wp_mail', 'wp_staticize_emoji_for_email');

    // Avoid registering callbacks that live in public components when the plugin front-end is disabled.
    if (!frl_get_option('disable_plugin')) {
        add_filter('tiny_mce_plugins',  'frl_disable_emojis_tinymce',             10, 1);
        add_filter('wp_resource_hints', 'frl_disable_emojis_remove_dns_prefetch', 10, 2);
    }
}

/**
 * Completely disable WordPress comments
 */
function frl_disable_comments()
{
    // Remove comment support from all post types
    foreach (get_post_types() as $post_type) {
        if (post_type_supports($post_type, 'comments')) {
            remove_post_type_support($post_type, 'comments');
            remove_post_type_support($post_type, 'trackbacks');
        }
    }

    // Perform the one-time DB update using frl_cache_remember with the 'options' group
    frl_cache_remember(
        'options',
        'disable_comments',
        function () {
            global $wpdb;
            $wpdb->update(
                $wpdb->posts,
                ['comment_status' => 'closed', 'ping_status' => 'closed'],
                ['post_status' => 'publish'] // WHERE condition - original was ['post_type' => 'post']
            );
            // Always return '1' to set the flag, preventing retries.
            return '1';
        },
        YEAR_IN_SECONDS,
    );

    // Hide existing comments menu and admin bar items
    add_action('admin_menu', function () {
        remove_menu_page('edit-comments.php');
    }, 10, 0);

    add_action('admin_bar_menu', function ($wp_admin_bar) {
        $wp_admin_bar->remove_node('comments');
    }, 999, 1);

    // Disable comments REST API endpoints
    add_filter('rest_endpoints', function ($endpoints) {
        unset($endpoints['/wp/v2/comments']);
        unset($endpoints['/wp/v2/comments/(?P<id>[\d]+)']);
        return $endpoints;
    });

    // Disable comment feed
    add_action('template_redirect', function () {
        if (is_comment_feed()) {
            $redirect_url = home_url();
            if (!empty($_GET)) {
                $redirect_url = add_query_arg($_GET, $redirect_url);
            }
            wp_redirect($redirect_url);
            exit;
        }
    }, 999, 0);

    // Remove comment-related widgets
    add_action('widgets_init', function () {
        unregister_widget('WP_Widget_Recent_Comments');
    }, 10, 0);

    // Disable comment form and display
    add_filter('comments_open',  '__return_false',       20, 2);
    add_filter('pings_open',     '__return_false',       20, 2);
    add_filter('comments_array', '__return_empty_array', 10, 2);

    // Remove comments from admin dashboard
    add_action('admin_init', function () {
        remove_meta_box('dashboard_recent_comments', 'dashboard', 'normal');
    }, 10, 0);
}
