<?php
/**
 * Schema Generator
 *
 * Config-driven system that generates complete JSON-LD @type blocks
 * from post data (ACF, ACPT, taxonomies, etc.).
 * Definitions are registered via data file + frl_schema_generators filter.
 *
 * All resolution delegates to shared helpers in includes/helpers/functions-schema.php.
 * Built-in generators are thin orchestrators — no data-fetching logic inlined.
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
 * Extend via filter 'frl_schema_generator_map' to register custom types.
 *
 * @param string $type Schema.org type (e.g. 'HowTo').
 * @return callable|null Generator function or null if unsupported.
 */
function frl_schema_generator_for_type(string $type): ?callable
{
    static $map = [
        'HowTo' => 'frl_schema_generate_howto',
    ];

    /**
     * Filter the type → generator map.
     *
     * @param array $map Schema.org @type => callable.
     */
    $map = apply_filters('frl_schema_generator_map', $map);

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
 * All property resolution delegates to shared helpers:
 *   - frl_get_schema_placeholders($post_id) → {{post_title}} etc.
 *   - frl_schema_resolve_value() → placeholder or field name → scalar
 *   - frl_get_repeater_rows() → ACF/ACPT repeater → associative rows
 *   - frl_build_image_object() → featured image → ImageObject
 *
 * @see https://schema.org/HowTo
 *
 * @param int   $post_id Post ID.
 * @param array $def     Schema definition from data file.
 * @return array|null HowTo schema array, or null if required data is missing.
 */
function frl_schema_generate_howto(int $post_id, array $def): ?array
{
    // Placeholder map (post-aware: includes {{post_title}})
    $placeholders = frl_get_schema_placeholders($post_id);

    // Required: name
    if (empty($def['name'])) {
        return null;
    }
    $name = frl_schema_resolve_value($post_id, $def['name'], $placeholders);
    if ($name === null) {
        return null;
    }

    // Required: step (repeater)
    $step_def = $def['step'] ?? [];
    if (!is_array($step_def) || empty($step_def['repeater'])) {
        return null;
    }

    $source    = $step_def['source'] ?? 'acf';
    $repeater  = $step_def['repeater'];
    $field_map = [];

    // Build field map from step definition (exclude structural keys)
    foreach ($step_def as $key => $field_name) {
        if (!is_string($field_name) || in_array($key, ['source', 'repeater'], true)) {
            continue;
        }
        $field_map[$key] = $field_name;
    }

    $rows = frl_get_repeater_rows($post_id, $repeater, $source, $field_map);
    if (empty($rows)) {
        return null;
    }

    // Build HowToStep objects with {{index}} replacement
    $steps = [];
    for ($i = 0; $i < count($rows); $i++) {
        $step = ['@type' => 'HowToStep'];
        $index = (string) ($i + 1);

        foreach ($rows[$i] as $prop => $value) {
            // Apply {{index}} placeholder
            if (str_contains($value, '{{index}}')) {
                $value = str_replace('{{index}}', $index, $value);
            }
            $step[$prop] = $value;
        }

        $steps[] = $step;
    }

    $schema = [
        '@type' => 'HowTo',
        'name'  => $name,
        'step'  => $steps,
    ];

    // Optional scalar properties — resolved via shared helper
    $optional_props = ['description', 'about', 'totalTime', 'estimatedCost'];
    foreach ($optional_props as $prop) {
        if (empty($def[$prop])) {
            continue;
        }
        $val = frl_schema_resolve_value($post_id, $def[$prop], $placeholders);
        if ($val !== null) {
            $schema[$prop] = $val;
        }
    }

    // Optional: image (featured image via shared helper)
    $image_id = get_post_thumbnail_id($post_id);
    if ($image_id) {
        $image = frl_build_image_object($image_id);
        if ($image !== null) {
            $schema['image'] = $image;
        }
    }

    return $schema;
}
