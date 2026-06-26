<?php
/**
 * Schema Generator
 *
 * Generic recursive builder that walks schema definition arrays
 * and produces Schema.org JSON-LD from post data.
 *
 * No hardcoded types — the @type comes from the data config.
 * No hardcoded property resolution — strings resolve via placeholder or field name.
 * Arrays with a 'repeater' key expand into repeated item arrays.
 * Arrays without 'repeater' are treated as nested sub-objects.
 *
 * @package Fralenuvole
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_head', 'frl_schema_generator_output', 10, 0);

/**
 * Output all generated schema blocks as a single <script> tag.
 */
function frl_schema_generator_output(): void
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

    $schemas = frl_schema_generator_get($post_id);
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
function frl_schema_generator_get(int $post_id): array
{
    return frl_cache_remember('postdata', frl_generate_cache_key('post', (string)$post_id, 'schema'), function () use ($post_id) {
        $schemas = [];
        $definitions = frl_schema_generator_get_definitions($post_id);

        foreach ($definitions as $def) {
            try {
                $schema = frl_schema_generator_build($post_id, $def);
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
 * Build the definition registry for a given post.
 *
 * @param int $post_id Post ID.
 * @return array List of schema definition arrays.
 */
function frl_schema_generator_get_definitions(int $post_id): array
{
    $post_type = get_post_type($post_id);
    if (!$post_type) {
        return [];
    }

    static $raw_map = null;
    if ($raw_map === null) {
        $file = frl_schema_get_data_file('default-schema.php', 'generators');
        $raw_map = file_exists($file) ? include $file : [];
        if (!is_array($raw_map)) {
            $raw_map = [];
        }
    }

    $definitions = $raw_map[$post_type] ?? [];

    /**
     * Filter the schema generator definitions for a post.
     *
     * @param array  $definitions Schema definition arrays.
     * @param int    $post_id     Post ID.
     * @param string $post_type   Post type.
     */
    return apply_filters('frl_schema_generators', $definitions, $post_id, $post_type);
}

// ─── Generic Recursive Builder ───────────────────────────────────

/**
 * Build a Schema.org object from a definition array.
 *
 * Rules:
 *   - '@type'     → passed through as-is
 *   - 'repeater'  → key in an array triggers row expansion
 *   - 'source'    → data source for repeater ('acf' or 'acpt')
 *   - 'image'     → if '@source' => 'featured_image', built from thumbnail
 *   - Scalar      → resolved via frl_schema_resolve_value
 *   - Array       → recursed into (sub-object)
 *   - null/empty  → key omitted from output
 *
 * @param int   $post_id      Post ID.
 * @param array $def          Definition array from data file.
 * @param array $placeholders Pre-built placeholder map.
 * @return array|null Built schema array, or null if no content.
 */
function frl_schema_generator_build(int $post_id, array $def, ?array $placeholders = null): ?array
{
    if ($placeholders === null) {
        $placeholders = frl_schema_get_placeholders($post_id);
    }

    $result = [];

    foreach ($def as $key => $value) {
        // Skip structural keys
        if ($key === 'source') {
            continue;
        }

        // @type → pass through
        if ($key === '@type') {
            $result[$key] = $value;
            continue;
        }

        // Array with 'repeater' → expand rows
        if (is_array($value) && isset($value['repeater'])) {
            $items = frl_schema_generator_build_repeater($post_id, $value, $placeholders);
            if (!empty($items)) {
                $result[$key] = $items;
            }
            continue;
        }

        // @source special keys
        if (is_array($value) && isset($value['@source'])) {
            $sub = frl_schema_generator_build_sourced($post_id, $value, $placeholders);
            if ($sub !== null) {
                $result[$key] = $sub;
            }
            continue;
        }

        // Array → recurse as sub-object
        if (is_array($value)) {
            $sub = frl_schema_generator_build($post_id, $value, $placeholders);
            if ($sub !== null) {
                $result[$key] = $sub;
            }
            continue;
        }

        // Scalar → resolve
        if (is_string($value) && $value !== '') {
            $resolved = frl_schema_resolve_value($post_id, $value, $placeholders);
            if ($resolved !== null) {
                $result[$key] = $resolved;
            }
            continue;
        }
    }

    return !empty($result) ? $result : null;
}

/**
 * Expand a repeater definition into an array of row objects.
 *
 * @param int   $post_id      Post ID.
 * @param array $def          Repeater definition (must contain 'repeater' key).
 * @param array $placeholders Placeholder map.
 * @return array Array of built row arrays.
 */
function frl_schema_generator_build_repeater(int $post_id, array $def, array $placeholders): array
{
    $repeater = $def['repeater'];
    $source   = $def['source'] ?? 'acf';

    // Build field map from non-structural keys
    $field_map = [];
    foreach ($def as $key => $field_name) {
        if (in_array($key, ['repeater', 'source', '@type'], true)) {
            continue;
        }
        $field_map[$key] = $field_name;
    }

    $rows = frl_schema_get_repeater_rows($post_id, $repeater, $source, $field_map);
    if (empty($rows)) {
        return [];
    }

    $items = [];
    foreach ($rows as $i => $row) {
        $item = [];
        // Inject @type if defined
        if (!empty($def['@type'])) {
            $item['@type'] = $def['@type'];
        }

        $index = (string) ($i + 1);
        foreach ($row as $prop => $value) {
            // {{index}} replacement
            if (str_contains($value, '{{index}}')) {
                $value = str_replace('{{index}}', $index, $value);
            }
            // Placeholder replacement
            $value = frl_schema_replace_placeholders($value, $placeholders);
            if ($value !== '' && $value !== null) {
                $item[$prop] = $value;
            }
        }

        if (!empty($item)) {
            $items[] = $item;
        }
    }

    return $items;
}

/**
 * Build a sourced object (e.g., image from featured image).
 *
 * Currently supports '@source' => 'featured_image'.
 *
 * @param int   $post_id      Post ID.
 * @param array $def          Source definition.
 * @param array $placeholders Placeholder map (unused here but consistent signature).
 * @return array|null Built object or null.
 */
function frl_schema_generator_build_sourced(int $post_id, array $def, array $placeholders): ?array
{
    $source = $def['@source'] ?? '';

    if ($source === 'featured_image') {
        $image_id = get_post_thumbnail_id($post_id);
        if (!$image_id) {
            return null;
        }
        $image = frl_schema_build_image_object($image_id);
        if ($image === null) {
            return null;
        }
        // Merge any additional properties from config
        foreach ($def as $key => $value) {
            if ($key === '@source') {
                continue;
            }
            if (is_string($value)) {
                $resolved = frl_schema_resolve_value($post_id, $value, $placeholders);
                if ($resolved !== null) {
                    $image[$key] = $resolved;
                }
            }
        }
        return $image;
    }

    return null;
}
