<?php
/**
 * Website feature management.
 *
 * Provides functionality to disable unnecessary WordPress core features
 * such as comments, oEmbed, and emojis to improve performance and security.
 *
 * @package Fralenuvole
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Injects critical CSS into the document head.
 *
 * If enabled, retrieves minified critical CSS and outputs it in a style tag.
 *
 * @hook wp_head
 * @priority -999
 */
function frl_add_critical_css()
{
    if (!frl_get_option('critical_css')) {
        return;
    }

    $css = frl_get_critical_css_data();

    if (frl_is_array_not_empty($css)) {
        printf(
            '<style id="%s-critical-css" data-lastmod="%s" data-plugin="%s" data-parsing="critical-css">%s</style>',
            FRL_PREFIX,
            date('Y-m-d-H:i', $css['mtime']),
            FRL_NAME,
            $css['css']
        );
    }
}

/**
 * Outputs a deferred CSS link in the footer for non-critical styles.
 *
 * Reads 'deferred.css' from the active theme's stylesheet directory,
 * outputs a <link> with media="print" onload pattern to avoid render-blocking.
 *
 * @hook wp_footer
 * @priority 1
 */
function frl_add_deferred_css()
{
    if (!frl_get_option('deferred_css')) {
        return;
    }

    $css_path = get_stylesheet_directory() . '/deferred.css';

    if (!file_exists($css_path)) {
        return;
    }

    $assets  = ['deferred-css' => $css_path];
    $version = frl_get_assets_versions($assets, 'deferred_css', 'versions', false);

    if (empty($version)) {
        return;
    }

    $mtime = $version['deferred-css'];
    $url   = esc_url(get_stylesheet_directory_uri() . '/deferred.css?ver=' . $mtime);

    echo "<link rel='stylesheet' id='" . FRL_PREFIX . "-deferred-css' href='{$url}' media='print' onload=\"this.media='all'\" data-plugin='" . FRL_NAME . "' data-parsing='deferred-css'>\n";
    
    echo "<noscript><link rel='stylesheet' href='{$url}'></noscript>\n";
}

/**
 * Retrieves and caches minified critical CSS data.
 *
 * Reads 'critical.css' from the stylesheet directory, minifies the content,
 * and caches the result using the file's modification time.
 *
 * @return array{css: string, mtime: int}|array{} Array with 'css' and 'mtime', or empty array if unavailable.
 */
function frl_get_critical_css_data()
{
    $css_path = get_stylesheet_directory() . '/critical.css';
    $css_file = ['critical-css' => $css_path];
    // Retrieve asset versions for the critical CSS file
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

            // Prepare the data to be cached
            $data = [
                'css' => $minified,
                'mtime' => $mtime
            ];

            return $data;
        }
    );

    return $critical_css;
}

/**
 * Disables selected WordPress core features based on plugin options.
 *
 * This is the main entry point for feature disabling, typically called during initialization.
 * Also handles the removal of Dashicons for non-logged-in users on the frontend.
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
 * Disables oEmbed discovery and parsing functionality.
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
 * Removes the wpemoji plugin from TinyMCE.
 *
 * @param string[] $plugins List of TinyMCE plugins.
 * @return string[] Filtered list of plugins.
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
 * Removes the emoji CDN hostname from DNS prefetching hints.
 *
 * @param string[] $urls URLs to print for resource hints.
 * @param string $relation_type The relation type the URLs are printed for.
 * @return string[] Filtered list of URLs.
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
 * Disables WordPress emoji support across the site and admin area.
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
 * Completely disables WordPress comments and pings.
 *
 * This function performs several actions:
 * - Removes comment support from all post types.
 * - Closes comments/pings for all published posts in the database (one-time operation).
 * - Removes comment-related UI elements from the admin menu and admin bar.
 * - Disables comment REST API endpoints and feeds.
 * - Unregisters comment widgets and filters comment status.
 * - Removes the recent comments dashboard widget.
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

    // Perform a one-time DB update to close comments on all published posts
    frl_cache_remember(
        'options',
        'disable_comments',
        function () {
            global $wpdb;
            $wpdb->update(
                $wpdb->posts,
                ['comment_status' => 'closed', 'ping_status' => 'closed'],
                ['post_status' => 'publish', 'comment_status' => 'open'] // WHERE condition: only affect posts with open comments
            );
            // Return '1' to mark the operation as completed in cache
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
