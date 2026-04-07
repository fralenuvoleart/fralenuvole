<?php

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fralenuvole
 * public.php - Code executed only on frontend pages
 */
add_action('wp_head',               'frl_wp_head',                   0,  0);
add_action('wp_footer',             'frl_wp_footer',                 10, 0);
add_action('pre_get_posts',         'frl_alter_query',               10, 1);
add_filter('rest_endpoints',        'frl_disable_rest_endpoints',    10, 1);
add_action('wp_loaded',             'frl_public_scripts',            10, 1);
add_action('wp_default_scripts',    'frl_remove_jquery_migrate',     10, 1);
add_filter('style_loader_tag',      'frl_defer_css',                 10, 4);
add_action('login_enqueue_scripts', 'frl_login_page_branding',       10, 0);
add_action('wp_footer',             'frl_add_schema',                10, 0);

/**
 * Add critical CSS, fonts, and preload featured image to header
 */
function frl_wp_head()
{
    frl_preload_featured_image();
    frl_add_header_html();
    frl_add_header_scripts();
}

/**
 * Add schema, footer HTML, and footer scripts to footer
 */
function frl_wp_footer()
{
    frl_add_footer_html();
    frl_add_footer_scripts();
}

/**
 * Enqueue public JS assets for frontend
 */
function frl_public_scripts()
{
	if (!frl_is_valid_frontend_page_request()) {
		return;
	}
	$assets = ['public-js' => 'assets/js/public.js'];
	frl_enqueue_scripts($assets, 'public_assets');
}

/**
 * Preload featured image with webp existence check
 */
function frl_preload_featured_image()
{
    if (!is_singular() || !frl_get_option('preload_featured')) {
        return;
    }

    global $post;
    if (!isset($post->ID)) {
        return;
    }

    // Cache processed images within a request
    static $src_cache = [];
    if (isset($src_cache[$post->ID])) {
        $src = $src_cache[$post->ID];
    } else {
        $image_size = frl_get_featured_image_size($post);

        // Cache key includes post ID and image size
        $cache_key = "featured_img_post_{$post->ID}_{$image_size}";

        $src = frl_cache_remember('postdata', $cache_key, function () use ($post, $image_size) {
            $thumbnail_id = get_post_thumbnail_id($post->ID);
            if (!$thumbnail_id) {
                return '';
            }

            $image = wp_get_attachment_image_src($thumbnail_id, $image_size);
            if (!$image || !isset($image[0])) {
                return '';
            }

            $src = $image[0];
            $original_path = get_attached_file($thumbnail_id);

            // Check for webp version
            if ($src && $original_path) {
                $webp_path = $original_path . '.webp';

                if (file_exists($webp_path)) {
                    $upload_dir = wp_upload_dir();
                    $src = str_replace(
                        $upload_dir['basedir'] ?? '',
                        $upload_dir['baseurl'] ?? '',
                        $webp_path
                    );
                }
            }

            return $src;
        });

        // Store in request cache
        $src_cache[$post->ID] = $src;
    }

    if ($src) {
        printf(
            '<link id="%s-preload-img" data-plugin="%s" rel="preload" fetchPriority="high" href="%s" as="image" />',
            FRL_PREFIX,
            FRL_NAME,
            esc_url($src)
        );
    }
}

/**
 * Defer CSS to footer
 * @param string $html The link tag for the enqueued style
 * @param string $handle The style's registered handle
 * @param string $href The stylesheet's source URL
 * @param string $media The stylesheet's media attribute
 * @return string Modified HTML
 */
function frl_defer_css($html, $handle, $href, $media)
{
    if (!frl_get_option('defer_css')) {
        return $html;
    }

    static $defer_handles = null;

    if ($defer_handles === null) {
        $defer_handles = frl_textlist_to_array(frl_get_option('defer_css_handles'));
    }

    if (empty($defer_handles)) return $html;

    foreach ($defer_handles as $script_parts) {
        // Extract the script name (first element since these are simple strings)
        $script = $script_parts[0];
        if (is_string($script)) {
            if (str_contains($href, $script)) {
                return str_replace(
                    "media='all'",
                    "data-no-defer='1' data-plugin='" . FRL_NAME . "' data-parsing='defer-css' media='print' onload='this.media=\"all\"'",
                    $html
                );
            }
        }
    }
    return $html;
}

/**
 * Add custom HTML in header
 * @return html
 */
function frl_add_header_html()
{
    $cache_key = frl_is_logged_in() ? 'header_html_user' : 'header_html_visitor';
    $id = str_replace('_', '-', $cache_key);

    $header_html = frl_get_html_option('header_html', 'header_html_php', $cache_key);

    if (!empty($header_html)) {
        echo "
<!-- {$id} data-plugin='" . FRL_NAME . "' data-parsing='add-header-html' -->
    {$header_html}
<!-- End {$id} -->
";
    }
}

/**
 * Add custom HTML in footer
 * @return html
 */
function frl_add_footer_html()
{
    $cache_key = frl_is_logged_in() ? 'footer_html_user' : 'footer_html_visitor';
    $id = str_replace('_', '-', $cache_key);

    $footer_html = frl_get_html_option('footer_html', 'footer_html_php', $cache_key);

    if (!empty($footer_html)) {
        echo "
<!-- {$id} data-plugin='" . FRL_NAME . "' data-parsing='add-footer-html' -->
    {$footer_html}
<!-- End {$id} -->
";
    }
}

/**
 * Add header scripts
 * @return void
 */
function frl_add_header_scripts()
{
    $assets = frl_get_processed_assets_from_option('header_scripts');
    frl_enqueue_scripts($assets, 'header_scripts');
}

/**
 * Add footer scripts
 * @return void
 */
function frl_add_footer_scripts()
{
    $assets = frl_get_processed_assets_from_option('footer_scripts');
    frl_enqueue_scripts($assets, 'footer_scripts');
}

/**
 * Get HTML content from an option, with caching and optional PHP processing.
 *
 * @param string $option_name The name of the plugin option (without prefix) storing the asset list.
 * @param bool   $in_footer   Default footer value for scripts in this list.
 * @param string $handle_prefix A prefix for the generated asset handles.
 * @return array An array of asset details (handle, url, version, type, in_footer).
 */
function frl_get_processed_assets_from_option($option_name)
{
    $assets = frl_textlist_to_array(frl_get_option($option_name));

    if (!frl_is_array_not_empty($assets)) {
        return [];
    }

    $processed_assets = [];
    $index = 0;

    foreach ($assets as $asset_parts) {
        if (!frl_is_array_not_empty($asset_parts) || empty($asset_parts[0])) {
            continue;
        }

        $url = $asset_parts[0];
		$handle = str_replace('_', '-', $option_name) . '-' . $index;

        $processed_assets[$handle] = $url;
        $index++;
    }

    return $processed_assets;
}

/**
 * New helper function to reduce duplication
 * @param string $group The group of the HTML (header or footer)
 * @param string $option_name The option name of the HTML
 * @param string $php_enabled_option The option name of the PHP enabled option
 * @return html final html output
 */
function frl_get_html_option($option_name, $php_enabled_option = null, $cache_key = null)
{
    $cache_key = $cache_key ?? $option_name;

    $html_option = frl_cache_remember('html', $cache_key, function () use ($option_name, $php_enabled_option) {
        if (!frl_get_option($option_name)) {
            return '';
        }

        $html = frl_get_option($option_name);
        if ($php_enabled_option && frl_get_option($php_enabled_option)) {
            $processed_html = frl_process_php_string($html, $option_name);
            return $processed_html;
        }
        return $html;
    });

    return $html_option;
}

/**
 * Add custom CSS to the login page with caching
 */
function frl_login_page_branding(): void
{
    if (!frl_get_option('login_branding')) {
        return;
    }

    add_filter('login_headerurl',
        'frl_login_headerurl',
        10,
        1);

    $assets = ['login-css' => 'assets/css/public-login.css'];
    frl_enqueue_scripts($assets, 'login_page');


    // --- Cache Inline CSS ---
    $cache_key_inline = 'login_inline_style';
    $output = frl_cache_remember('html', $cache_key_inline, function () {
        // Get logo data (expensive part)
        $logo = wp_get_attachment_image_src(get_theme_mod('custom_logo'), 'full') ?: [null, null, null];
        $width = ($logo && isset($logo[1]) && $logo[1]) ? ($logo[1] . 'px') : 'auto';
        $height = ($logo && isset($logo[2]) && $logo[2]) ? $logo[2] : '50'; // Default height
        $height .= 'px';

        // Build CSS string
        $css = ':root {';
        $css .= '--login-logo-url: url(' . esc_url($logo[0] ?? '') . ');';
        $css .= '--login-logo-width: ' . esc_attr($width) . ';'; // Use esc_attr for safety
        $css .= '--login-logo-height: ' . esc_attr($height) . ';'; // Use esc_attr for safety
        $css .= '}';

        return $css;
    });

    // Add the cached inline style if not empty
    if (!empty($output)) {
        wp_add_inline_style(FRL_PREFIX . '-login', $output);
    }
}

/**
 * Custom login URL
 */
function frl_login_headerurl($url)
{
    return home_url();
}

/**
 * Remove jQuery.migrate.js
 */
function frl_remove_jquery_migrate($scripts)
{
    if (!frl_get_option('remove_jqery_mig')) {
        return;
    }

    if (!empty($scripts->registered['jquery'])) {
        $jquery_dependencies = $scripts->registered['jquery']->deps;
        $scripts->registered['jquery']->deps = array_diff($jquery_dependencies, array('jquery-migrate'));
    }
}

/**
 * Filter function used to remove the tinymce emoji plugin.
 * @param array $plugins
 * @return array Difference betwen the two arrays
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
 * @return array Difference betwen the two arrays.
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
 * Disable some REST endpoints for unauthenticated users
 */
function frl_disable_rest_endpoints($endpoints)
{
    if (frl_is_logged_in() || !frl_get_option('disable_rest')) {
        return $endpoints;
    }



    // Default list of endpoints to remove for non-logged-in users.
    // Focus on security (users, settings) and non-essential info.
    // Avoid blocking potentially needed content endpoints (posts, pages, search, blocks) by default.
    static $endpoints_to_remove = array(
        // Security related:
        '/wp/v2/users',
        '/wp/v2/settings',
        // Non-essential info:
        '/wp/v2/themes',
        '/wp/v2/plugins', // Added: Generally good to hide plugin list too
        '/wp/v2/types',
        '/wp/v2/statuses',
        // Taxonomies (Generally safe, unlikely needed publicly by API):
        '/wp/v2/taxonomies',
        '/wp/v2/categories',
        '/wp/v2/tags',
        // Other:
        '/wp/v2/media', // Usually safe, hides media library structure
        '/wp/v2/comments', // Safe if comments not needed via API
        // OEmbed (Redundant if oEmbed globally disabled, but safe to keep here):
        '/oembed/1.0',
        '/wp/v2/oembed'
        // --- Problematic endpoints REMOVED from default list: ---
        // '/wp/v2', // Too broad
        // '/wp/v2/posts', // Might break headless/JS themes
        // '/wp/v2/pages', // Might break headless/JS themes
        // '/wp/v2/search', // Might break API-based search
        // '/wp/v2/blocks', // Might break block themes
        // '/wp/v2/block-renderer', // Might break dynamic blocks
    );

    // Allow themes/plugins to filter this list if needed
    $endpoints_to_remove = apply_filters('frl_rest_endpoints_to_remove', $endpoints_to_remove);

    if (!frl_is_logged_in()) {
        // --- Use the more efficient loop ---
        foreach ($endpoints as $route => $data) {
            foreach ($endpoints_to_remove as $prefix_to_remove) {
                // Use str_starts_with for precise prefix matching (PHP 8+)
                // Using str_starts_with() since we're on PHP 8+
                if (str_starts_with($route, $prefix_to_remove)) {
                    unset($endpoints[$route]);
                    break; // Move to the next endpoint once a match is found
                }
            }
        }
        // --- End efficient loop ---
    }
    return $endpoints;
}

/**
 * Optimize secondary queries and order service type posts by menu_order
 * @return void
 */
function frl_alter_query($query)
{
    if (!$query instanceof WP_Query || $query->is_main_query()) {
        return;
    }

    $query->set('update_post_meta_cache', false);
    $query->set('update_post_term_cache', false);
    $query->set('no_found_rows', true);
    $query->set('ignore_sticky_posts', true);
    $query->set('post_status', 'publish');
    $query->set( 'has_password', false );

    $cpts_list = frl_textlist_to_array(frl_get_option('custom_wp_query'));

    if (empty($cpts_list)) {
        return;
    }

    // Flatten the array to get just the CPT names (first element of each sub-array)
    $cpts_list = array_column($cpts_list, 0);

    // Add post type check for any custom post type
    $post_type = $query->get('post_type');

    if ($post_type && in_array($post_type, $cpts_list, true)) {
        $query->set('orderby', 'menu_order');
        $query->set('order', 'ASC');
    }
}
