<?php

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fralenuvole Themekit
 * This file orchestrates theme modifications.
 */

/**
 * Themekit defined Patterns Categories
 */
const FRL_THEMEKIT_PATTERNS_CATEGORIES = [
    'sections',
    'queries',
    'ACF',
    'editorial'
];

/**
 * Query parameters that trigger body class additions
 *
 * When these query parameters are present in the URL, a corresponding
 * body class "has-{param}" will be added (e.g. "has-frlq").
 */
const FRL_THEMEKIT_TRACKED_QUERY_PARAMS = [
    'frlq',   // Bible reference query param
];

/**
 * Initialize themekit
 */
function frl_themekit_init()
{
    // NOTE: frl_is_already_running() has a side effect - it sets the flag on first call
    // So we only call it once and store the result
    if (frl_is_already_running(__FUNCTION__)) {
        return;
    }

    frl_themekit_register_patterns_categories();
    
    // Register admin body class hook
    if (frl_get_option('themekit_body_classes')) {
        add_filter('admin_body_class',
            'frl_themekit_admin_body_classes',
            9999,
            1
        );

        // Register frontend body class hook
        add_filter('body_class',
            'frl_themekit_frontend_body_classes',
            10,
            1
        );
    }

    // Enqueue themekit base styles.
    if (frl_get_option('themekit_base_css')) {
        add_action('wp_enqueue_scripts',
            'frl_themekit_enqueue_base_styles',
            9, // Right after WO global styles with priority 8
            0
        );
    }

    // Remove WP Block Patterns conditionally (Frontend/Backend)
    if (frl_get_option('themekit_remove_wp_patterns')) {
        frl_themekit_remove_core_block_patterns();
    }

	// Dequeue global styles added by theme.json file
	if (frl_get_option('themekit_remove_wp_default_colors')) {
        add_filter('wp_theme_json_data_default',
            'frl_themekit_remove_wp_colors',
            10,
            1
        );
    }

	// Remove provider-specific block patterns (themes/plugins) if configured
	$provider_patterns_raw = frl_get_option('themekit_remove_provider_patterns') ?: '';
	if (!empty($provider_patterns_raw)) {
		add_action('init',
			'frl_themekit_remove_provider_block_patterns',
			999,
			0
        );
	}

	// Remove provider-specific styles (themes/plugins/global-styles) if configured
	$provider_styles_raw = frl_get_option('themekit_remove_provider_styles') ?: '';
	if (!empty($provider_styles_raw)) {
		add_action('wp_enqueue_scripts',
			'frl_themekit_remove_provider_styles',
			99999,
			0
        );
	}
}

/**
 * Enqueue themekit base stylesheet.
 */
function frl_themekit_enqueue_base_styles()
{
    $assets = ['themekit-base-css' => 'assets/css/themekit-styles.css'];
    frl_enqueue_scripts($assets, 'themekit');
}

/**
 * Add classes to body tag
 * @param string $classes A space-separated string of body classes.
 * @return string The modified string of classes.
 */
function frl_themekit_admin_body_classes($classes)
{
    // Normalize classes to string in case other filters returned an array
    if (is_array($classes)) {
        $classes = implode(' ', $classes);
    } elseif (!is_string($classes)) {
        $classes = (string) $classes;
    }

    $custom_classes = [];

    // Admin-specific logic to get user and role classes
    $current_user = frl_get_current_user();
    if ($current_user && $current_user->ID > 0) {
        $custom_classes[] = 'uid-' . $current_user->ID;
        if (!empty($current_user->roles)) {
            $custom_classes[] = 'role-' . implode('-', $current_user->roles);
        }
    }

    if (!empty($custom_classes)) {
        // The admin_body_class hook provides a string. Append new classes.
        $classes .= ' ' . implode(' ', $custom_classes);
    }

    return $classes;
}

/**
 * Add classes to body tag
 * @return array Modified array of allowed file types.
 */
function frl_themekit_frontend_body_classes($classes)
{
    if (!frl_is_valid_frontend_page_request()) {
        return $classes;
    }

    $cache_key = 'body_classes';

    $current_user = frl_get_current_user();
    if ($current_user->ID > 0) {
        $cache_key .= '_uid' . $current_user->ID;
    }

    $object_id = (int) get_queried_object_id();
    $cache_key .= '_id' . $object_id;

    // Avoid key collisions for routes where object_id is 0 (home, search, 404, etc.)
    if ($object_id === 0) {
        $request_path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
        $cache_key .= '_path_' . md5($request_path);
    }

    // Add query parameter signature to cache key if present
    $query_signature = frl_themekit_get_query_signature();
    if ($query_signature) {
        $cache_key .= '_themekit_body_classes_' . $query_signature;
    }

    return frl_cache_remember('postdata', $cache_key, function () use ($classes) {
        $queried_object = get_queried_object();
        $custom_classes = [];

        // Add simplified page classes
        if (is_singular()) {
            // Simple classes for individual posts - minimal queries
            $post = $queried_object;

            // Add parent class if hierarchical
            if ($post->post_parent) {
                $parent = get_post($post->post_parent);
                if ($parent) {
                    $custom_classes[] = 'parent-' . sanitize_html_class($parent->post_name);
                }
            }

            // Add current post slug
            $custom_classes[] = 'slug-' . sanitize_html_class($post->post_name);
        } elseif (is_category() || is_tag() || is_tax()) {
            // Archive pages - term already loaded, no extra queries
            $term = $queried_object;
            $custom_classes[] = 'tax-' . sanitize_html_class($term->taxonomy);
            $custom_classes[] = 'tax-' . sanitize_html_class($term->slug);
        } else {
            // Fallback to URL path parsing for other cases
            $request_uri = $_SERVER['REQUEST_URI'] ?? '';
            if (!empty($request_uri)) {
                $path = trim(parse_url($request_uri, PHP_URL_PATH), '/');
                if (!empty($path)) {
                    $segments = explode('/', $path);
                    $custom_classes[] = 'path-' . sanitize_html_class(end($segments));
                }
            }
        }

        $current_user = frl_get_current_user();
        if ($current_user->ID > 0) {
            $custom_classes[] = 'uid-' . $current_user->ID;
            $custom_classes[] = 'role-' . implode('-', $current_user->roles);
        }

        // Add specific body classes for tracked query parameters
        foreach (FRL_THEMEKIT_TRACKED_QUERY_PARAMS as $param) {
            if (!empty($_GET[$param])) {
                $custom_classes[] = 'has-' . sanitize_html_class($param);
            }
        }

        return array_merge($classes, $custom_classes);
    }, HOUR_IN_SECONDS);
}

/**
 * Register patterns categories for blocks
 */
function frl_themekit_register_patterns_categories()
{
    foreach (FRL_THEMEKIT_PATTERNS_CATEGORIES as $category) {
        $category_label = ucfirst($category);
        register_block_pattern_category(
            $category,
            array('label' => __($category_label, FRL_PREFIX))
        );
    }
}

/**
 * Remove WordPress Core Blocks Patterns
 */
function frl_themekit_remove_core_block_patterns()
{
    // Remove theme support for core patterns from the Dotorg pattern directory.
    // See https://developer.wordpress.org/themes/patterns/registering-patterns/#removing-core-patterns
    remove_theme_support('core-block-patterns');

    // Remove and unregister patterns from core, Dotcom, and plugins.
    // See https://github.com/Automattic/jetpack/blob/d032fbb807e9cd69891e4fcbc0904a05508a1c67/projects/packages/jetpack-mu-wpcom/src/features/block-patterns/block-patterns.php#L107

    add_filter('should_load_remote_block_patterns', '__return_false', 10, 0);
}

/**
 * Remove WP Global Styles
 */
function frl_themekit_remove_wp_colors($theme_json)
{
    $data = $theme_json->get_data();

    $data['settings']['color']['palette']['default'] = [];
    $data['settings']['color']['duotone']['default'] = [];
    $data['settings']['color']['gradients']['default'] = [];

    $theme_json->update_with($data);
    return $theme_json;
}

/**
 * Remove block patterns registered by specific providers (themes/plugins).
 */
function frl_themekit_remove_provider_block_patterns()
{
    $raw = frl_get_option('themekit_remove_provider_patterns');
    $list = frl_textlist_to_array($raw);
    if (empty($list)) return;

    // Flatten [['ollie'], ['greenshift']] -> ['ollie','greenshift'] and normalize
    $providers = array_values(array_filter(array_map(function ($row) {
        return isset($row[0]) ? strtolower(trim($row[0])) : '';
    }, $list), fn($v) => $v !== ''));

    if (empty($providers)) return;

    $registry = WP_Block_Patterns_Registry::get_instance();
    $patterns = $registry->get_all_registered();
    if (empty($patterns)) return;

    foreach ($patterns as $pattern) {
        if (!isset($pattern['name']) || !is_string($pattern['name'])) continue;
        $name = strtolower($pattern['name']); // e.g., 'ollie/hero'
        foreach ($providers as $provider) {
            if ($provider !== '' && str_starts_with($name, $provider . '/')) {
                unregister_block_pattern($pattern['name']);
                break;
            }
        }
    }
}

/**
 * Dequeue/deregister styles enqueued by specific providers (themes/plugins) or exact handles.
 * Runs late so removals win.
 */
function frl_themekit_remove_provider_styles()
{
    $raw = frl_get_option('themekit_remove_provider_styles');
    $list = frl_textlist_to_array($raw);
    if (empty($list)) return;

    $tokens = array_values(array_filter(array_map(function ($row) {
        return isset($row[0]) ? strtolower(trim($row[0])) : '';
    }, $list), fn($v) => $v !== ''));

    if (empty($tokens)) return;

    $wp_styles = wp_styles();
    if (!$wp_styles || empty($wp_styles->registered)) return;

    foreach ($wp_styles->registered as $handle => $style) {
        $handle_l = strtolower((string)$handle);
        $src = strtolower((string)($style->src ?? ''));

        foreach ($tokens as $t) {
            if ($t === '') continue;
            // Exact handle match (e.g., 'global-styles')
            $match = ($handle_l === $t)
                // Handle contains token (some providers prefix handles)
                || str_contains($handle_l, $t)
                // URL path contains provider slug under plugins/themes
                || ($src !== '' && (
                    str_contains($src, '/plugins/' . $t . '/')
                    || str_contains($src, '/themes/' . $t . '/')
                ));

            if ($match) {
                wp_dequeue_style($handle);
                wp_deregister_style($handle);
                break;
            }
        }
    }
}

/**
 * Generate a cache-safe signature from tracked query parameters
 * Returns empty string if no relevant params are present
 *
 * @return string Query signature for cache key
 */
function frl_themekit_get_query_signature()
{
    $parts = [];
    foreach (FRL_THEMEKIT_TRACKED_QUERY_PARAMS as $param) {
        if (!empty($_GET[$param])) {
            $parts[] = $param . '_' . md5(sanitize_text_field($_GET[$param]));
        }
    }

    return empty($parts) ? '' : md5(implode('_', $parts));
}