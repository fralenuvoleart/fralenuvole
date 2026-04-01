<?php

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fralenuvole Themekit
 * This file orchestrates all theme.json modifications and font handling.
 * 1. Merges a base plugin theme.json with the active theme's, optionally preserving theme settings.
 * 2. Injects dynamic font family presets from plugin options into the final theme.json data.
 * 3. Renders <link rel="preload"> tags and metric-adjusted @font-face rules in the <head>.
 */

/*
* FONT MERGING LOGIC: Base (Plugin) -> Theme -> Dynamic (Plugin Options)
  */

// Invalidate theme.json cache when theme is switched or updated,
frl_hook_add(
    'action',
    'switch_theme',
    'frl_themekit_invalidate_theme_json_cache',
    10,
    1,
    'core',
    false
);

frl_hook_add(
    'action',
    'upgrader_process_complete',
    'frl_themekit_invalidate_theme_json_cache',
    10,
    2,
    'core',
    false
);

/**
 * Initialize themekit features
 */
function frl_themekit_init()
{
    if (frl_is_already_running(__FUNCTION__)) {
        return;
    }

    // Register admin body class hook
    if (frl_get_option('themekit_body_classes')) {
        frl_hook_add(
            'filter',
            'admin_body_class',
            'frl_themekit_admin_body_classes',
            9999,
            1,
            'admin'
        );

        // Register frontend body class hook
        frl_hook_add(
            'filter',
            'body_class',
            'frl_themekit_frontend_body_classes',
            10,
            1,
            'public'
        );
    }

    // Exit if themekit is disabled or request context is invalid
    if (frl_get_option('disable_themekit')) {
        return;
    }

    // Enqueue themekit base styles.
    // We hook this independently of other options because if themekit is active,
    // its base styles should likely apply.
    if (frl_get_option('themekit_base_css')) {
        frl_hook_add(
            'action',
            'wp_enqueue_scripts',
            'frl_themekit_enqueue_base_styles',
            9, // Right after WO global styles with priority 8
            0,
            'public'
        );
    }

    // HOOK 1: Merges the plugin's static theme.json file into the theme's.
    if (frl_get_option('themekit_settings') || frl_get_option('themekit_styles') || frl_get_option('themekit_colors') || frl_get_option('themekit_fonts')) {
        frl_hook_add(
            'filter',
            'wp_theme_json_data_theme',
            'frl_themekit_apply_theme_json',
            100,
            1
        );
    }

    // HOOKS 2 & 3: Handle all dynamic font functionality.
    if (frl_get_option('themekit_fonts_system_fonts') || frl_get_option('themekit_fonts_plugin_fonts')) {
        // Renders font preloads and metric-adjusted fallback styles in the <head>.
        frl_hook_add(
            'action',
            'wp_head',
            'frl_themekit_fonts_render_markup',
            0, // Run early in the head
            0,
            'public'
        );
    }

    // Load base patterns
    if (frl_get_option('themekit_patterns')) {
        frl_themekit_register_patterns_categories();
        frl_themekit_register_patterns();
    }

    // Register base blocks
    if (frl_get_option('themekit_block_styles')) {
        frl_themekit_register_block_styles();
    }

    // Remove WP Block Patterns conditionally (Frontend/Backend)
    if (frl_get_option('themekit_remove_wp_patterns')) {
        frl_themekit_remove_core_block_patterns();
    }

	// Dequeue global styles added by theme.json file
	if (frl_get_option('themekit_remove_wp_default_colors')) {
        frl_hook_add(
            'filter',
            'wp_theme_json_data_default',
            'frl_themekit_remove_wp_colors',
            10,
            1,
            'public'
        );
    }

	// Remove provider-specific styles (themes/plugins/global-styles) if configured
	$provider_styles_raw = frl_get_option('themekit_remove_provider_styles') ?: '';
	if (!empty($provider_styles_raw)) {
		frl_hook_add(
			'action',
			'wp_enqueue_scripts',
			'frl_themekit_remove_provider_styles',
			99999,
			0,
			'public'
		);
	}

	// Remove provider-specific block patterns (themes/plugins) if configured
	$provider_patterns_raw = frl_get_option('themekit_remove_provider_patterns') ?: '';
	if (!empty($provider_patterns_raw)) {
		frl_hook_add(
			'action',
			'init',
			'frl_themekit_remove_provider_block_patterns',
			999,
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
 * Applies the plugin's theme.json modifications to the active theme's data.
 *
 * This function orchestrates the entire theme.json merging process. It ensures a
 * correct and predictable result by following a precise, multi-step procedure:
 *
 * 1.  **Load Correct Theme Data:** It bypasses the potentially incomplete data from the
 *     `wp_theme_json_data_theme` hook by calling a dedicated helper,
 *     `frl_themekit_get_fully_merged_theme_json()`. This helper manually loads the
 *     parent and child theme.json files from the filesystem, guaranteeing a correct
 *     and complete starting point.
 *
 * 2.  **Perform Base Merge:** It performs a deep, recursive merge of the plugin's
 *     theme.json and the now-correct theme data. The `themekit_theme_json_overwrite`
 *     option controls whether the plugin or theme takes precedence in this initial merge.
 *
 * 3.  **Conditionally Apply Body & Heading Fonts:** If the corresponding options
 *     are enabled, it injects the primary and secondary font families into the
 *     body and heading styles, respectively. This is done after the main merge
 *     to ensure these act as definitive overrides.
 *
 * 4.  **Orchestrate Granular Merging:** It passes the result to the main orchestrator
 *     function, `frl_themekit_orchestrate_merging()`. This powerful helper handles all
 *     complex logic, including the intelligent, slug-based merging of all presets.
 *
 * 5.  **Update WordPress:** Finally, it uses the `update_with()` method on the original
 *     hook object to inject the final, correctly processed data back into WordPress.
 *
 * @param WP_Theme_JSON_Data $theme_json_data The original theme JSON data object from the hook.
 * @return WP_Theme_JSON_Data The modified theme JSON data object.
 */
function frl_themekit_apply_theme_json($theme_json_data)
{
    // Build cache key from all factors that affect the final merged JSON
    $themekit_options = [
        'themekit_settings' => frl_get_option('themekit_settings'),
        'themekit_settings_overwrite' => frl_get_option('themekit_settings_overwrite'),
        'themekit_styles' => frl_get_option('themekit_styles'),
        'themekit_styles_overwrite' => frl_get_option('themekit_styles_overwrite'),
        'themekit_colors' => frl_get_option('themekit_colors'),
        'themekit_fonts' => frl_get_option('themekit_fonts'),
        'themekit_fonts_body' => frl_get_option('themekit_fonts_body'),
        'themekit_fonts_headings' => frl_get_option('themekit_fonts_headings'),
    ];

    // Get JSON file versions for cache invalidation
    $theme_json_path = FRL_THEMEKIT_DIR_PATH . 'theme-json/';
    $json_files = [
        'settings' => $theme_json_path . 'settings.json',
        'styles' => $theme_json_path . 'styles.json',
        'colors' => $theme_json_path . 'colors.json',
        'fonts' => $theme_json_path . 'fonts.json',
    ];
    $json_versions = frl_get_assets_versions($json_files, 'themekit_jsons');

    $cache_key = 'themekit_jsons_' . md5(serialize($themekit_options) . serialize($json_versions));

    // Cache all the processing logic
    $merged_json_array = frl_cache_remember('theme', $cache_key, function () use ($themekit_options, $theme_json_path) {
        // Load plugin JSON files first to allow early exit without loading base theme.json
        $settings_plugin_json = $themekit_options['themekit_settings'] ? (frl_json_decode_file($theme_json_path . 'settings.json') ?? []) : [];
        $styles_plugin_json = $themekit_options['themekit_styles'] ? (frl_json_decode_file($theme_json_path . 'styles.json') ?? []) : [];
        $colors_plugin_json = $themekit_options['themekit_colors'] ? (frl_json_decode_file($theme_json_path . 'colors.json') ?? []) : [];
        $fonts_plugin_json = $themekit_options['themekit_fonts'] ? (frl_json_decode_file($theme_json_path . 'fonts.json') ?? []) : [];

        // This will hold the complete plugin configuration for the preset orchestrator.
        $plugin_json_for_orchestration = [];

        // Early exit if no plugin JSONs contribute anything
        if (empty($settings_plugin_json) && empty($styles_plugin_json) && empty($colors_plugin_json) && empty($fonts_plugin_json)) {
            return null;
        }

        // STEP 1: Get the correctly merged theme.json from the active theme. This is our base.
        $theme_json_array = frl_themekit_get_fully_merged_theme_json();
        $merged_json_array = $theme_json_array;

        // --- Merge SETTINGS ---
        if (!empty($settings_plugin_json)) {
            $merged_json_array = $themekit_options['themekit_settings_overwrite']
                ? wp_array_recursive_merge($merged_json_array, $settings_plugin_json) // Plugin wins
                : wp_array_recursive_merge($settings_plugin_json, $merged_json_array); // Theme wins
            $plugin_json_for_orchestration = wp_array_recursive_merge($plugin_json_for_orchestration, $settings_plugin_json);
        }

        // --- Merge STYLES ---
        if (!empty($styles_plugin_json)) {
            $merged_json_array = $themekit_options['themekit_styles_overwrite']
                ? wp_array_recursive_merge($merged_json_array, $styles_plugin_json) // Plugin wins
                : wp_array_recursive_merge($styles_plugin_json, $merged_json_array); // Theme wins
            $plugin_json_for_orchestration = wp_array_recursive_merge($plugin_json_for_orchestration, $styles_plugin_json);
        }

        // --- Merge ADDITIVE parts (Colors & Fonts) ---
        $additive_parts = [];
        if (!empty($colors_plugin_json)) {
            $additive_parts = wp_array_recursive_merge($additive_parts, $colors_plugin_json);
        }
        if (!empty($fonts_plugin_json)) {
            $additive_parts = wp_array_recursive_merge($additive_parts, $fonts_plugin_json);
        }

        if (!empty($additive_parts)) {
            $merged_json_array = wp_array_recursive_merge($merged_json_array, $additive_parts);
            $plugin_json_for_orchestration = wp_array_recursive_merge($plugin_json_for_orchestration, $additive_parts);
        }

        // If no plugin JSON was processed, return null to signal early exit
        if (empty($plugin_json_for_orchestration)) {
            return null;
        }

        // Conditionally apply primary font to body.
        if ($themekit_options['themekit_fonts_body']) {
            $merged_json_array['styles']['typography']['fontFamily'] = 'var:preset|font-family|frl-primary';
        }

        // Conditionally apply secondary font to headings.
        if ($themekit_options['themekit_fonts_headings']) {
            $merged_json_array['styles']['elements']['heading']['typography']['fontFamily'] = 'var:preset|font-family|frl-secondary';
        }

        // Run the preset orchestrator on the final data. This intelligently de-duplicates
        // all preset lists (colors, fonts, etc.) using their slugs as unique IDs.
        frl_themekit_orchestrate_merging($merged_json_array, $theme_json_array, $plugin_json_for_orchestration);

        // Post-merge hard overrides for specific settings when theme settings overwrite is enabled.
        // This ensures empty arrays in plugin settings can truly wipe parent arrays like gradients.
        if (!empty($themekit_options['themekit_settings_overwrite'])
            && isset($settings_plugin_json['settings']['color'])
            && is_array($settings_plugin_json['settings']['color'])) {

            $plugin_color = $settings_plugin_json['settings']['color'];
            if (!isset($merged_json_array['settings']['color']) || !is_array($merged_json_array['settings']['color'])) {
                $merged_json_array['settings']['color'] = [];
            }

            // Force-apply configured overrides from plugin settings (scalars vs lists handled automatically)
            $keys_to_force = defined('FRL_THEMEKIT_FORCE_OVERRIDES')
                ? FRL_THEMEKIT_FORCE_OVERRIDES
                : ['defaultGradients','customGradient','defaultDuotone','customDuotone','gradients','duotone'];

            foreach ($keys_to_force as $key) {
                if (!array_key_exists($key, $plugin_color)) {
                    continue;
                }

                $value = $plugin_color[$key];
                if (is_array($value)) {
                    // Preset list: enforce WP structured format
                    $merged_json_array['settings']['color'][$key] = [
                        'theme' => $value,
                        'user'  => [],
                        'core'  => [],
                    ];
                } else {
                    // Scalar flag or simple value
                    $merged_json_array['settings']['color'][$key] = $value;
                }
            }
        }

        return $merged_json_array;
    });

    // Handle early exit case (no plugin JSON loaded)
    if ($merged_json_array === null) {
        return $theme_json_data;
    }

    // Update the main theme.json object with the final, correctly merged data.
    // This must run on every request and cannot be cached
    return $theme_json_data->update_with($merged_json_array);
}

/**
 * This function registers custom block styles.
 *
 * @param object $theme_json The original theme JSON data.
 * @return object The modified theme JSON data.
 */
function frl_themekit_register_block_styles()
{
    if (frl_is_already_running(__FUNCTION__)) {
        return;
    }
    // Build an array of all block style files for version tracking.
    $style_files = [];
    foreach (FRL_THEMEKIT_BLOCK_STYLES as $block_name) {
        $style_files[$block_name] = FRL_THEMEKIT_DIR_PATH . 'styles/blocks/' . $block_name . '.json';
    }

    // Get a version hash for all style files. This will be our cache key.
    $versions = frl_get_assets_versions($style_files, 'block_all_styles');
    $cache_key = 'block_styles_' . implode('_', array_values($versions));

    // Get the processed block style data from cache, or generate it if not present.
    $blocks_to_register = frl_cache_remember('theme', $cache_key, function () {
        $processed_blocks = [];
        foreach (FRL_THEMEKIT_BLOCK_STYLES as $block_name) {
            $block_data = frl_themekit_convert_block_style_json($block_name);
            if (frl_is_array_not_empty($block_data, 'block_types')) {
                $processed_blocks[] = $block_data;
            }
        }
        return $processed_blocks;
    });

    // Register the styles using the cached data. This part runs on every request.
    if (!frl_is_array_not_empty($blocks_to_register)) {
        return;
    }

    foreach ($blocks_to_register as $block) {
        foreach ($block['block_types'] as $block_type) {
            register_block_style(
                $block_type,
                $block['styles']
            );
        }
    }
}

/**
 * Register patterns for blocks
 */
function frl_themekit_register_patterns()
{

    // Register each pattern - this must happen on every request
    $pattern_files = [];
    $pattern_files_path = FRL_THEMEKIT_RELATIVE_PATH . 'patterns/';

    // Build array of pattern files for version checking
    foreach (FRL_THEMEKIT_PATTERNS as $pattern) {
        $file_key = 'pattern-' . $pattern['slug'];
        $pattern_files[$file_key] = $pattern_files_path . $pattern['slug'] . '.php';
    }

    // Get all file modification times in a single cache operation (hourly)
    $file_versions = frl_get_assets_versions($pattern_files, 'pattern_files');

    // Now register each pattern with its content cached using file version in key
    $pattern_files_dir = FRL_THEMEKIT_DIR_PATH . 'patterns/';

    foreach (FRL_THEMEKIT_PATTERNS as $pattern) {
        $pattern_slug = FRL_NAME . '/' . $pattern['slug'];
        $file_key = 'pattern-' . $pattern['slug'];
        $pattern_path = $pattern_files_dir . $pattern['slug'] . '.php';

        // Use file version in cache key for automatic invalidation
        $version = $file_versions[$file_key] ?? FRL_VERSION;
        $content_cache_key = 'pattern_' . $pattern['slug'] . '_' . $version;

        // Cache the pattern content with the versioned key
        $pattern_content = frl_cache_remember('theme', $content_cache_key, function () use ($pattern_path) {
            if (file_exists($pattern_path)) {
                return file_get_contents($pattern_path);
            }
            return '';
        });

        // Only register if we have content
        if (!empty($pattern_content)) {
            register_block_pattern(
                $pattern_slug,
                array(
                    'title'      => __($pattern['label'], FRL_PREFIX),
                    'categories' => $pattern['categories'],
                    'content'    => $pattern_content,
                )
            );
        }
    }
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

    frl_hook_add('filter', 'should_load_remote_block_patterns', '__return_false', 10, 0);
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

    return frl_cache_remember('postdata', $cache_key, function () use ($classes, $query_signature) {
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

/**
 * Converts a block style JSON file to a register_block_style-compatible array.
 * Uses the 'style_data' property for theme.json-like style definitions as per WordPress documentation.
 *
 * @param string $path Path to the JSON file.
 * @return array|null
 */
function frl_themekit_convert_block_style_json($block_name)
{
    $path = FRL_THEMEKIT_DIR_PATH . 'styles/blocks/' . $block_name . '.json';
    $block_key = 'block_' . $block_name;
    $assets = [$block_key => $path];

    $version = frl_get_assets_versions($assets, $block_key, 'versions', true)[$path] ?? '';

    $cache_key = $block_key . '_style_' . $version;
    return frl_cache_remember('theme', $cache_key, function () use ($path) {
        $json_data = frl_json_decode_file($path);
        if (empty($json_data)) {
            return null;
        }

        $block_types = [];
        $block_style = []; // This will be the array for register_block_style's 2nd argument

        // --- Configuration for special keys from JSON ---
        $block_types_key = 'blockTypes';
        // 'slug', 'title', and 'styles' (the object containing style rules)
        // will be accessed directly by their string names.
        // --- End Configuration ---

        if (isset($json_data[$block_types_key])) {
            $block_types = $json_data[$block_types_key];
        }
        if (isset($json_data['slug'])) {
            $block_style['name'] = $json_data['slug'];
        }
        if (isset($json_data['title'])) {
            $block_style['label'] = $json_data['title'];
        }

        // Assign the JSON 'styles' object directly to 'style_data'
        // This is the theme.json-like array of rules WordPress expects.
        if (frl_is_array_not_empty($json_data, 'styles')) {
            $block_style['style_data'] = $json_data['styles'];
        }

        // The calling function frl_themekit_register_block_styles expects 'block_types' and 'styles' keys in the returned array.
        return [
            'block_types' => $block_types,
            'styles'      => $block_style,
        ];
    });
}

/**
 * Invalidate theme.json cache when theme is switched or updated
 */
function frl_themekit_invalidate_theme_json_cache($upgrader = null, $options = null)
{
    // Self-disable if the corresponding feature option is turned off.
    // This check is necessary because the hooks are called unconditionally.
    if (!frl_get_option('themekit_settings') && !frl_get_option('themekit_styles') && !frl_get_option('themekit_colors') && !frl_get_option('themekit_fonts')) {
        return;
    }

    // For theme update/install or switch_theme action
    frl_cache_clear('theme');
}
