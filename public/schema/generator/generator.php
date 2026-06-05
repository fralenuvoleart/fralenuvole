<?php
/**
 * Schema Generator
 *
 * Config-driven system that generates complete JSON-LD @type blocks
 * from post data (ACF, ACPT, taxonomies, etc.).
 * Definitions are registered via data file + frl_schema_generators filter.
 *
 * Master toggle: frl_get_option('schema_generator') — defaults to 1 (enabled).
 * When 0, all generator output is suppressed.
 *
 * @package Fralenuvole
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_head', 'frl_output_generated_schemas', 10, 0);

/**
 * Output all generated schema blocks as a single <script> tag.
 *
 * Gated behind:
 * - Master toggle: frl_get_option('schema_generator')
 * - is_singular(), !frl_is_admin(), !frl_is_rest_api_request(), !is_preview(), !frl_is_cron_job_request()
 * - frl_is_already_running() re-entrancy guard
 */
function frl_output_generated_schemas(): void
{
    if (!frl_get_option('schema_generator')) {
        return;
    }

    if (!is_singular()) {
        return;
    }

    if (frl_is_admin() || frl_is_rest_api_request() || is_preview() || frl_is_cron_job_request()) {
        return;
    }

    if (function_exists('frl_is_already_running') && frl_is_already_running(__FUNCTION__)) {
        return;
    }

    $post_id = get_the_ID();
    if (!$post_id) {
        return;
    }

    $schemas = frl_get_generated_schemas($post_id);
    if (empty($schemas)) {
        return;
    }

    $output = count($schemas) === 1 ? $schemas[0] : ['@graph' => $schemas];
    $output['@context'] = 'https://schema.org';

    echo "\n" . '<script id="frl-schema" type="application/ld+json">' . "\n"
        . wp_json_encode($output, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        . "\n" . '</script>' . "\n";
}

/**
 * Get all generated schema blocks for a post, cached per-post.
 *
 * Definitions from the data file mirror Schema.org JSON structure.
 * The @type key selects the generator via frl_schema_generator_for_type().
 *
 * @param int $post_id Post ID.
 * @return array Array of schema arrays (each with @type).
 */
function frl_get_generated_schemas(int $post_id): array
{
    $cache_key = "post_{$post_id}_schema";

    return frl_cache_remember('postdata', $cache_key, function () use ($post_id) {
        $schemas = [];
        $definitions = frl_get_schema_generators($post_id);

        foreach ($definitions as $def) {
            $type = $def['@type'] ?? '';
            $generator_fn = frl_schema_generator_for_type($type);
            if ($generator_fn === null) {
                continue;
            }

            try {
                $schema = call_user_func($generator_fn, $post_id, $def);
            } catch (\Throwable $e) {
                if (defined('WP_DEBUG') && WP_DEBUG && function_exists('frl_log')) {
                    frl_log('Schema generator threw: {msg}', ['msg' => $e->getMessage()]);
                }
                continue;
            }

            if (is_array($schema) && !empty($schema)) {
                $schemas[] = $schema;
            }
        }

        return $schemas;
    });
}

/**
 * Map a Schema.org @type to its generator function.
 *
 * @param string $type Schema.org type (e.g. 'HowTo').
 * @return callable|null Generator function or null if unsupported.
 */
function frl_schema_generator_for_type(string $type): ?callable
{
    static $map = [
        'HowTo' => 'frl_schema_generate_howto',
    ];

    $fn = $map[$type] ?? null;
    if ($fn === null || !is_callable($fn)) {
        if (defined('WP_DEBUG') && WP_DEBUG && function_exists('frl_log')) {
            frl_log('No generator for schema type: {type}', ['type' => $type]);
        }
        return null;
    }

    return $fn;
}

/**
 * Build the definition registry for a given post.
 *
 * Loads from data file (filtered by post type), then applies
 * the frl_schema_generators filter for extensibility.
 *
 * Uses request-level static cache for the data file include.
 *
 * @param int $post_id Post ID.
 * @return array List of schema definition arrays.
 */
function frl_get_schema_generators(int $post_id): array
{
    $post_type = get_post_type($post_id);
    if (!$post_type) {
        return [];
    }

    // Request-level static cache — data file parsed once per request
    // Uses shared frl_get_schema_data_file() for brand-override support
    static $raw_map = null;
    if ($raw_map === null) {
        $file = frl_get_schema_data_file('default-schema.php', 'generators');
        $raw_map = file_exists($file) ? include $file : [];
        if (!is_array($raw_map)) {
            $raw_map = [];
        }
    }

    $definitions = $raw_map[$post_type] ?? [];

    /**
     * Filter the schema generator definitions for a post.
     *
     * @param array  $definitions Schema definition arrays (each mirrors Schema.org JSON).
     * @param int    $post_id     Post ID.
     * @param string $post_type   Post type.
     */
    return apply_filters('frl_schema_generators', $definitions, $post_id, $post_type);
}

// ─── Built-in Generators ─────────────────────────────────────────

/**
 * Generate a HowTo schema.
 *
 * Each definition value is resolved in a single pass:
 *   1. Replace {{placeholders}} using the shared properties subsystem convention.
 *      Supported: {{post_title}} → get_the_title($post_id)
 *   2. If the value changed after placeholder replacement (it was a placeholder),
 *      use the resolved value directly.
 *   3. Otherwise (it's a field name), resolve via frl_get_post_meta().
 *
 * {{index}} in step string values is replaced with 1-based step number.
 *
 * Missing/empty values → property omitted. Required `name` or `step`
 * missing → returns null → no schema output.
 *
 * @see https://schema.org/HowTo
 *
 * @param int   $post_id Post ID.
 * @param array $def     Schema definition from data file.
 * @return array|null HowTo schema array, or null if required data is missing.
 */
function frl_schema_generate_howto(int $post_id, array $def): ?array
{
    $step_def = $def['step'] ?? [];
    if (!is_array($step_def)) {
        return null;
    }

    $source     = $step_def['source'] ?? 'acf';
    $repeater   = $step_def['repeater'] ?? '';
    $name_field = $step_def['name'] ?? 'title';
    $text_field = $step_def['text'] ?? 'text';

    if (empty($repeater)) {
        return null;
    }

    // Build steps
    $steps = ($source === 'acpt')
        ? frl_schema_get_howto_steps_acpt($post_id, $repeater, $name_field, $text_field)
        : frl_schema_get_howto_steps_acf($post_id, $repeater, $name_field, $text_field);

    if (empty($steps)) {
        return null;
    }

    // Apply {{index}} placeholder across all step string values (1-based)
    for ($i = 0; $i < count($steps); $i++) {
        $index = (string) ($i + 1);
        foreach ($steps[$i] as $key => $value) {
            if (is_string($value) && str_contains($value, '{{index}}')) {
                $steps[$i][$key] = str_replace('{{index}}', $index, $value);
            }
        }
    }

    // Resolve all scalar definition values in a single pass
    // Placeholder map — shared with properties subsystem convention
    $placeholders = [
        '{{post_title}}' => get_the_title($post_id),
    ];

    $schema = ['@type' => 'HowTo', 'step' => $steps];
    $has_name = false;

    foreach ($def as $key => $raw) {
        // Skip structural keys
        if ($key === '@type' || $key === 'step' || !is_string($raw) || $raw === '') {
            continue;
        }

        // Replace placeholders in the raw value
        $resolved = str_replace(array_keys($placeholders), array_values($placeholders), $raw);

        // If placeholder was replaced, use it directly; otherwise resolve as field name
        if ($resolved !== $raw) {
            $value = $resolved;
        } else {
            $value = frl_extract_scalar_value(frl_get_post_meta($post_id, $raw, true));
            if ($value === null) {
                continue;
            }
        }

        $schema[$key] = $value;
        if ($key === 'name') {
            $has_name = true;
        }
    }

    if (!$has_name) {
        return null;
    }

    // Optional: image (featured image)
    $image_id = get_post_thumbnail_id($post_id);
    if ($image_id) {
        $img_size = apply_filters('frl_schema_thumbnail_size', 'medium');
        $img_url  = wp_get_attachment_image_url($image_id, $img_size);
        $img_data = wp_get_attachment_image_src($image_id, $img_size);
        if ($img_url) {
            $schema['image'] = [
                '@type'  => 'ImageObject',
                'url'    => $img_url,
                'width'  => $img_data[1] ?? 0,
                'height' => $img_data[2] ?? 0,
            ];
        }
    }

    return $schema;
}

/**
 * Read HowTo steps from an ACF repeater.
 *
 * Uses have_rows()/the_row()/get_sub_field() — the standard ACF repeater API.
 * Returns [] if ACF is not available or the repeater has no rows.
 *
 * @param int    $post_id    Post ID.
 * @param string $repeater   Repeater field name.
 * @param string $name_field Sub-field key for HowToStep.name.
 * @param string $text_field Sub-field key for HowToStep.text.
 * @return array List of HowToStep arrays.
 */
function frl_schema_get_howto_steps_acf(int $post_id, string $repeater, string $name_field, string $text_field): array
{
    if (!function_exists('have_rows')) {
        return [];
    }

    $steps = [];
    if (have_rows($repeater, $post_id)) {
        while (have_rows($repeater, $post_id)) {
            the_row();
            $name = get_sub_field($name_field);
            $text = get_sub_field($text_field);
            if (!empty($name) && !empty($text)) {
                $steps[] = [
                    '@type' => 'HowToStep',
                    'name'  => is_string($name) ? $name : '',
                    'text'  => is_string($text) ? $text : '',
                ];
            }
        }
    }

    return $steps;
}

/**
 * Read HowTo steps from an ACPT repeater.
 *
 * ACPT stores repeaters as columnar serialized arrays:
 *   meta_key = 'howto_steps' → ['title' => [...], 'answer' => [...]]
 *
 * Uses frl_get_post_meta() which handles both ACPT raw meta and ACF fallback.
 *
 * @param int    $post_id    Post ID.
 * @param string $repeater   Repeater meta key.
 * @param string $name_field Column key for HowToStep.name.
 * @param string $text_field Column key for HowToStep.text.
 * @return array List of HowToStep arrays.
 */
function frl_schema_get_howto_steps_acpt(int $post_id, string $repeater, string $name_field, string $text_field): array
{
    $data = frl_get_post_meta($post_id, $repeater, true);
    if (!is_array($data)) {
        return [];
    }

    $names = $data[$name_field] ?? [];
    $texts = $data[$text_field] ?? [];
    if (!is_array($names) || !is_array($texts)) {
        return [];
    }

    $steps = [];
    $count = min(count($names), count($texts));
    for ($i = 0; $i < $count; $i++) {
        $name = $names[$i] ?? '';
        $text = $texts[$i] ?? '';
        if ($name !== '' && $text !== '') {
            $steps[] = [
                '@type' => 'HowToStep',
                'name'  => (string) $name,
                'text'  => (string) $text,
            ];
        }
    }

    return $steps;
}
