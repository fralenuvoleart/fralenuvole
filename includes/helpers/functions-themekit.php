<?php

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fralenuvole
 * functions-themekit.php - Helper functions for the Themekit module.
 */


/**
 * Orchestrates the intelligent merging of all presets and applies font-specific style overwrites.
 *
 * This function modifies the merged theme.json data in place, applying two main stages of logic:
 * 1. It discovers and intelligently merges all preset arrays (e.g., color palettes, font families).
 * 2. It conditionally applies a surgical overwrite of font-related styles.
 *
 * @param array &$merged_json  The result of the initial recursive theme.json merge (passed by reference).
 * @param array $theme_json   The original theme.json data from the active theme.
 * @param array $plugin_json  The original theme.json data from this plugin.
 */
function frl_themekit_orchestrate_merging(array &$merged_json, array $theme_json, array $plugin_json): void
{
    // Stage 1: Discover and intelligently merge all presets.
    if (isset($merged_json['settings']) && is_array($merged_json['settings'])) {
        frl_themekit_discover_and_merge_presets_recursive($merged_json['settings'], $theme_json, $plugin_json, ['settings']);
    }
}

/**
 * Loads and merges theme.json from a parent and potentially a child theme.
 *
 * This function correctly resolves the theme's final theme.json data by manually
 * loading the parent's file and merging the child's file on top if it exists.
 * This is the source of truth for the theme's intended configuration, bypassing
 * any potential race conditions with WordPress hook execution.
 *
 * @return array The fully resolved theme.json data from the active theme hierarchy.
 */
function frl_themekit_get_fully_merged_theme_json(): array
{
    $cache_key = 'parent_child_jsons_' . get_stylesheet();

    return frl_cache_remember('theme', $cache_key, function () {
        $parent_theme_path = get_template_directory() . '/theme.json';
        $child_theme_path  = get_stylesheet_directory() . '/theme.json';

        // Start with the parent theme's data, or an empty array if it doesn't exist.
        $theme_json_array = file_exists($parent_theme_path) ? frl_json_decode_file($parent_theme_path) : [];

        // If a child theme is active and has its own theme.json,
        // load it and merge it on top of the parent's data.
        if (is_child_theme() && file_exists($child_theme_path)) {
            $child_theme_json = frl_json_decode_file($child_theme_path);
            if (is_array($child_theme_json)) {
                // The child theme's data takes precedence over the parent's.
                $theme_json_array = wp_array_recursive_merge($theme_json_array, $child_theme_json);
            }
        }

        return $theme_json_array;
    });
}

/**
 * Recursively discovers and merges preset arrays within a settings array.
 *
 * This function walks through a settings array, identifies preset lists by their structure,
 * and then performs a robust, slug-aware merge on them.
 *
 * @param array &$settings_node The current node of the settings array to scan (passed by reference).
 * @param array $theme_json     The original theme.json data.
 * @param array $plugin_json    The original plugin.json data.
 * @param array $current_path   An array representing the path to the current node.
 */
function frl_themekit_discover_and_merge_presets_recursive(array &$settings_node, array $theme_json, array $plugin_json, array $current_path): void
{
    foreach ($settings_node as $key => &$value) {
        if (!is_array($value)) {
            continue;
        }

        // Heuristic: A preset array is a numerically-indexed list of associative arrays, each with a 'slug'.
        $is_preset_array = isset($value[0]) && is_array($value[0]) && isset($value[0]['slug']);

        if ($is_preset_array) {
            $preset_path = array_merge($current_path, [$key]);

            // Extract original presets from both sources using the discovered path.
            $theme_presets = frl_themekit_get_value_from_path($theme_json, $preset_path);
            $plugin_presets = frl_themekit_get_value_from_path($plugin_json, $preset_path);

            // Correctly handle presets that might already be nested under a 'theme' key.
            $theme_presets = is_array($theme_presets) ? ($theme_presets['theme'] ?? $theme_presets) : [];
            $plugin_presets = is_array($plugin_presets) ? ($plugin_presets['theme'] ?? $plugin_presets) : [];

            // Add dynamic fonts only to the fontFamilies preset.
            $dynamic_presets = ($key === 'fontFamilies') ? frl_themekit_fonts_get_dynamic_fonts() : [];

            // Intelligently merge the presets. Since plugin presets are namespaced, we can now
            // simply combine all sources. The merge function handles slug-based deduplication,
            // with dynamic presets taking final precedence for system font adjustments.
            $final_presets = frl_themekit_merge_presets_by_slug(
                $theme_presets,
                $plugin_presets,
                $dynamic_presets
            );

            // Inject the final, clean list back into the merged data, wrapping it in the standard WP structure.
            $value = ['theme' => $final_presets, 'user' => [], 'core' => []];

        } else {
            // If it's not a preset array, continue scanning deeper.
            frl_themekit_discover_and_merge_presets_recursive($value, $theme_json, $plugin_json, array_merge($current_path, [$key]));
        }
    }
}


/**
 * Intelligently merges presets from multiple sources, ensuring no duplicate slugs.
 * Presets appearing later in the argument list will overwrite earlier ones with the same slug.
 *
 * @param array|null ...$preset_arrays A variable number of preset arrays to merge.
 * @return array The final, merged array of presets.
 */
function frl_themekit_merge_presets_by_slug(?array ...$preset_arrays): array
{
    $map = [];

    foreach ($preset_arrays as $presets) {
        $presets = is_array($presets) ? $presets : [];
        foreach ($presets as $item) {
            if (is_array($item) && isset($item['slug'])) {
                $map[$item['slug']] = $item;
            }
        }
    }

    return array_values($map);
}

/**
 * Helper function to get a value from a nested array using a path array.
 *
 * @param array $array The array to search in.
 * @param array $path  The path to the desired value.
 * @return mixed The value at the specified path or null if not found.
 */
function frl_themekit_get_value_from_path(array $array, array $path): mixed
{
    $current = $array;
    foreach ($path as $segment) {
        if (!isset($current[$segment])) {
            return null;
        }
        $current = $current[$segment];
    }
    return $current;
}

/**
 * Renders font assets in the site's <head>.
 * This includes preloading main font files and injecting inline <style>
 * tags for metric-adjusted fallback fonts to prevent layout shifts.
 *
 * @return void
 */
function frl_themekit_fonts_render_markup(): void
{
    $output_html = '';

    // Logic for System Fonts (size adjustment)
    if (frl_get_option('themekit_fonts_system_fonts')) {
        $output_html = frl_themekit_fonts_get_system_markup();
        // Logic for Plugin Fonts (preloading fallbacks)
    } elseif (frl_get_option('themekit_fonts_plugin_fonts')) {
        $output_html = frl_themekit_fonts_get_plugin_markup();
    }

    // Echo the final, cached HTML.
    if (!empty($output_html)) {
        echo $output_html;
    }
}

/**
 * Generates the complete HTML for custom plugin font styles.
 * This includes <link rel="preload"> tags and fallback @font-face rules.
 *
 * @return string The complete HTML block, or an empty string.
 */
function frl_themekit_fonts_get_plugin_markup(): string
{
    // Gather all relevant options for the cache key and processing.
    $font_options = [
        'primary_font'      => frl_get_option('themekit_fonts_primary'),
        'secondary_font'    => frl_get_option('themekit_fonts_secondary'),
        'primary_size'      => frl_get_option('themekit_fonts_primary_size_adjust'),
        'primary_ascent'    => frl_get_option('themekit_fonts_primary_ascend_override'),
        'primary_descent'   => frl_get_option('themekit_fonts_primary_descend_override'),
        'secondary_size'    => frl_get_option('themekit_fonts_secondary_size_adjust'),
        'secondary_ascent'  => frl_get_option('themekit_fonts_secondary_ascend_override'),
        'secondary_descent' => frl_get_option('themekit_fonts_secondary_descend_override'),
    ];

    $cache_key = 'fonts_plugin_markup_' . implode('_', array_filter($font_options));

    // Get the final HTML from cache or generate it.
    return frl_cache_remember('theme', $cache_key, function () use ($font_options) {
        // Restructure the flat options into a nested array for cleaner processing.
        $font_settings = [
            'frl-primary' => [
                'font_data' => frl_textlist_to_array($font_options['primary_font'])[0] ?? null,
                'size'      => $font_options['primary_size'],
                'ascent'    => $font_options['primary_ascent'],
                'descent'   => $font_options['primary_descent'],
            ],
            'frl-secondary' => [
                'font_data' => frl_textlist_to_array($font_options['secondary_font'])[0] ?? null,
                'size'      => $font_options['secondary_size'],
                'ascent'    => $font_options['secondary_ascent'],
                'descent'   => $font_options['secondary_descent'],
            ],
        ];

        // Check if any fonts are actually configured before proceeding.
        if (empty(array_filter($font_settings, fn($s) => !empty($s['font_data'])))) {
            return '';
        }

        $preload_links = [];
        $fallback_css_rules = [];

        foreach ($font_settings as $key => $settings) {
            $font = $settings['font_data'];
            if (!frl_is_array_not_empty($font) || count($font) < 2) {
                continue;
            }

            [$font_name, $font_file] = $font;
            $fallback_name = $font_name . '-Fallback';

            $preload_links[] = sprintf(
                "\n<link id=\"%s-themekit-fonts-%s\" rel=\"preload\" type=\"font/woff2\" href=\"%s\" as=\"font\" data-plugin=\"%s\" crossorigin />",
                FRL_PREFIX,
                $key,
                esc_url($font_file),
                FRL_NAME,
            );

            $size_adjust = $settings['size'];
            $ascent_override = $settings['ascent'];
            $descent_override = $settings['descent'];

            // Build an array of valid metrics for the @font-face rule.
            $metrics = [];
            if (is_numeric($ascent_override)) {
                $metrics[] = "ascent-override: {$ascent_override}%;";
            }
            if (is_numeric($descent_override)) {
                $metrics[] = "descent-override: {$descent_override}%;";
            }
            if (is_numeric($size_adjust)) {
                $metrics[] = "size-adjust: {$size_adjust}%;";
            }

            // If metrics are available, create the fallback rule.
            if (frl_is_array_not_empty($metrics)) {
                // Use a simple system font stack for the fallback source.
                $system_stack = ($key === 'frl-primary') ? frl_themekit_fonts_get_system_stack('frl-sans-serif') : frl_themekit_fonts_get_system_stack('frl-serif');

                // Assemble the final @font-face rule.
                $fallback_css_rules[] = sprintf(
                    "@font-face {\n\tfont-family: \"%s\";\n\tsrc: %s;\n\t%s\n}",
                    $fallback_name,
                    $system_stack,
                    implode("\n\t", $metrics)
                );
            }
        }

        // Combine all parts into the final HTML output.
        $output_html = implode("\n", $preload_links);
        if (!empty($fallback_css_rules)) {
            $output_html .= "\n<style id=\"" . FRL_PREFIX . "-themekit-fonts-fallback\">\n" . implode("\n", $fallback_css_rules) . "\n</style>";
        }

        return $output_html;
    });
}

/**
 * Generates the complete HTML for system font adjustments.
 * This includes the @font-face rules with size-adjust.
 *
 * @return string The complete <style> tag as an HTML string, or an empty string.
 */
function frl_themekit_fonts_get_system_markup(): string
{
    $font_options = [
        'primary_size'   => frl_get_option('themekit_fonts_primary_size_adjust'),
        'secondary_size' => frl_get_option('themekit_fonts_secondary_size_adjust'),
    ];

    // Only proceed if at least one adjustment is set and is numeric.
    if (!is_numeric($font_options['primary_size']) && !is_numeric($font_options['secondary_size'])) {
        return '';
    }

    $cache_key = 'fonts_system_markup_' . implode('_', array_filter($font_options));

    return frl_cache_remember('theme', $cache_key, function () use ($font_options) {
        $system_css_rules = [];

        $system_sans_serif_stack = frl_themekit_fonts_get_system_stack('frl-sans-serif');
        $system_serif_stack = frl_themekit_fonts_get_system_stack('frl-serif');

        if (is_numeric($font_options['primary_size'])) {
            $system_css_rules[] = sprintf(
                "@font-face {\n\tfont-family: \"System Sans Adjusted\";\n\tsrc: %s;\n\tsize-adjust: %s;\n}",
                $system_sans_serif_stack,
                $font_options['primary_size'] . '%'
            );
        }

        if (is_numeric($font_options['secondary_size'])) {
            $system_css_rules[] = sprintf(
                "@font-face {\n\tfont-family: \"System Serif Adjusted\";\n\tsrc: %s;\n\tsize-adjust: %s;\n}",
                $system_serif_stack,
                $font_options['secondary_size'] . '%'
            );
        }

        if (empty($system_css_rules)) {
            return '';
        }

        $formatted_css = implode("\n", $system_css_rules);
        return sprintf(
            "\n<style id=\"%s-themekit-fonts-system-adjust\">\n%s\n</style>\n",
            FRL_PREFIX,
            $formatted_css
        );
    });
}

/**
 * Generates an array of font family definitions based on plugin options.
 * This is used to dynamically inject font presets into the theme.json.
 *
 * @return array An array of font family definitions.
 */
function frl_themekit_fonts_get_dynamic_fonts(): array
{
    // Define the options this function depends on to build a cache key.
    $font_options = [
        'system_fonts'   => frl_get_option('themekit_fonts_system_fonts'),
        'plugin_fonts'   => frl_get_option('themekit_fonts_plugin_fonts'),
        'primary_font'   => frl_get_option('themekit_fonts_primary'),
        'secondary_font' => frl_get_option('themekit_fonts_secondary'),
        'primary_size'   => frl_get_option('themekit_fonts_primary_size_adjust'),
        'secondary_size' => frl_get_option('themekit_fonts_secondary_size_adjust'),
    ];

    $cache_key = 'fonts_dynamic_' . implode('_', array_filter($font_options));

    return frl_cache_remember('theme', $cache_key, function () use ($font_options) {
        $dynamic_families = [];

        // --- LOGIC 1: Handle System Font Size Adjustments ---
        if ($font_options['system_fonts']) {
            // This logic remains the same: create virtual adjusted system fonts.
            if (is_numeric($font_options['primary_size'])) {
                $dynamic_families[] = [
                    'name'       => 'Frl Sans-serif (Adjusted)',
                    'slug'       => 'frl-sans-serif',
                    'fontFamily' => '"System Sans Adjusted"',
                ];
            }
            if (is_numeric($font_options['secondary_size'])) {
                $dynamic_families[] = [
                    'name'       => 'Frl Serif (Adjusted)',
                    'slug'       => 'frl-serif',
                    'fontFamily' => '"System Serif Adjusted"',
                ];
            }
        }

        // --- LOGIC 2: Handle Custom Plugin Web Fonts ---
        if ($font_options['plugin_fonts']) {
            $font_definitions = [
                'frl-primary'   => [
                    'font_data' => frl_textlist_to_array($font_options['primary_font'])[0] ?? null,
                    'name'      => 'Frl Primary',
                ],
                'frl-secondary' => [
                    'font_data' => frl_textlist_to_array($font_options['secondary_font'])[0] ?? null,
                    'name'      => 'Frl Secondary',
                ],
            ];

            foreach ($font_definitions as $slug => $definition) {
                $font = $definition['font_data'];
                if (!frl_is_array_not_empty($font) || count($font) < 2) {
                    continue;
                }
                [$font_name, $font_file] = $font;
                $name = $definition['name'];

                // Re-declare the entire font family preset, this time with a populated fontFace array.
                // This will overwrite the static preset from fonts.json during the merge process.
                $dynamic_families[] = [
                    'name'       => $name,
                    'slug'       => $slug,
                    'fontFamily' => "\"$font_name\"",
                    'fontFace'   => [
                        [
                            'fontFamily' => "\"$font_name\"",
                            'fontWeight' => '400',
                            'fontStyle'  => 'normal',
                            'src'        => ["file:$font_file"],
                        ],
                    ],
                ];
            }
        }

        return $dynamic_families;
    });
}

/**
 * Retrieves the default system font stack.
 * Uses a constant for the base value and makes it filterable for extensibility.
 *
 * @param string $type The type of font stack to retrieve ('sans-serif' or 'serif').
 * @return string The font stack.
 */
function frl_themekit_fonts_get_system_stack(string $type = 'frl-sans-serif'): string
{
    $cache_key = 'fonts_system_stack_' . $type;
    return frl_cache_remember('theme', $cache_key, function () use ($type) {
        $stacks = FRL_THEMEKIT_DEFAULT_SYSTEM_FONTS;
        $stack_array = $stacks[$type] ?? [];

        if (empty($stack_array) || !is_array($stack_array)) {
            // Return an empty string if the stack is not a valid, non-empty array.
            return '';
        }

        $formatted_items = [];
        foreach ($stack_array as $font) {
            // Wrap font names containing spaces in quotes, as per CSS spec.
            $formatted_font = str_contains($font, ' ') ? "\"$font\"" : $font;
            $formatted_items[] = "local($formatted_font)";
        }

        $result = implode(', ', $formatted_items);


        // Allow themes or other plugins to override the default stacks.
        return apply_filters("frl_default_system_font_stack_src_{$type}", $result, $stack_array);
    });
}
