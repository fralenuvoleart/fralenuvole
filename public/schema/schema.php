<?php
/**
 * Schema Service
 *
 * Provides dynamic, translatable schema properties for JSON-LD output.
 * Data is loaded from pure data files, resolved (placeholders + translation),
 * cached per-language, and made filterable for per-brand overrides.
 *
 * @package Fralenuvole
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get resolved schema properties.
 *
 * Loads raw data, resolves placeholders and translations, caches per-language,
 * and applies the 'frl_schema_properties' filter for extensibility.
 *
 * @return array Resolved schema properties array.
 */
function frl_get_schema_properties(): array
{
    $language = frl_get_language();
    $version = frl_get_option('translation_version') ?: 1;
    $cache_key = "schema_properties_{$language}_{$version}";

    return frl_cache_remember('html', $cache_key, function () {
        // Per-brand file resolution: load {prefix}-schema.php if it exists
        $prefix = '';
        if (function_exists('frl_environment_get_config')) {
            $env_config = frl_environment_get_config();
            $prefix = $env_config['prefix'] ?? '';
        }

        $file = FRL_DIR_PATH . 'public/schema/default-schema.php';
        if ($prefix) {
            $brand_file = FRL_DIR_PATH . "public/schema/{$prefix}-schema.php";
            if (file_exists($brand_file)) {
                $file = $brand_file;
            }
        }

        $raw = file_exists($file) ? include $file : [];
        $resolved = frl_resolve_schema_properties($raw);

        /**
         * Filter the resolved schema properties.
         *
         * @param array $resolved Resolved schema properties.
         */
        return apply_filters('frl_schema_properties', $resolved);
    });
}

/**
 * Recursively resolve schema properties.
 *
 * - Replaces {{site_url}} placeholders with site_url()
 * - Translates non-structural string values via frl_get_translation()
 * - Skips @type and @id keys (structural JSON-LD identifiers)
 *
 * @param array $props Raw schema properties array.
 * @return array Resolved schema properties array.
 */
function frl_resolve_schema_properties(array $props): array
{
    $site_url = site_url();
    $result = [];

    foreach ($props as $key => $value) {
        if (is_array($value)) {
            $result[$key] = frl_resolve_schema_properties($value);
        } elseif (is_string($value)) {
            $value = str_replace('{{site_url}}', $site_url, $value);

            if ($key !== '@type' && $key !== '@id' && function_exists('frl_get_translation')) {
                $value = frl_get_translation($value);
            }

            $result[$key] = $value;
        } else {
            $result[$key] = $value;
        }
    }

    return $result;
}

/**
 * Get the post-term schema mapping configuration.
 *
 * Loads the schema term data file (same brand-file resolution as
 * frl_get_schema_properties()), transforms simple "property => taxonomy"
 * pairs into full field definitions, then applies the filter.
 *
 * Data file format:
 *   'SchemaType' => [
 *       'schemaProperty' => 'taxonomy_slug',
 *   ]
 *
 * Expanded field defaults: field => 'name', format => 'csv'
 *
 * @return array Filterable map of schema @type => field definitions.
 */
function frl_get_schema_term_map(): array
{
    // Per-brand file resolution (same pattern as frl_get_schema_properties())
    $prefix = '';
    if (function_exists('frl_environment_get_config')) {
        $env_config = frl_environment_get_config();
        $prefix = $env_config['prefix'] ?? '';
    }

    $file = FRL_DIR_PATH . 'public/schema/default-schema-terms.php';
    if ($prefix) {
        $brand_file = FRL_DIR_PATH . "public/schema/{$prefix}-schema-terms.php";
        if (file_exists($brand_file)) {
            $file = $brand_file;
        }
    }

    $raw = file_exists($file) ? include $file : [];

    // Transform simple pairs into full field definitions
    $map = [];
    foreach ($raw as $type => $pairs) {
        if (!is_array($pairs)) {
            continue;
        }
        foreach ($pairs as $property => $taxonomy) {
            if (!is_string($property) || !is_string($taxonomy) || empty($property) || empty($taxonomy)) {
                continue;
            }
            $map[$type][] = [
                'taxonomy' => $taxonomy,
                'property' => $property,
                'field'    => 'name',
                'format'   => 'csv',
            ];
        }
    }

    /**
     * Filter the post-term schema property map.
     *
     * @param array $map Schema type => field definition array.
     */
    return apply_filters('frl_schema_term_map', $map);
}

/**
 * Build post-term schema properties from a type map.
 *
 * Resolves term values once per taxonomy (cached in $taxonomy_cache), then
 * formats them according to each field definition's format setting.
 * Called once per schema type that has a mapping, but term data is fetched
 * only once per unique taxonomy across all types.
 *
 * @param int   $post_id        Post ID.
 * @param array $type_map       Array of field definitions from frl_get_schema_term_map().
 * @param array &$taxonomy_cache Internal cache of resolved taxonomy => term values.
 * @return array Schema properties to inject (property key => formatted value).
 */
function frl_build_schema_term_properties(int $post_id, array $type_map, array &$taxonomy_cache): array
{
    $props = [];

    foreach ($type_map as $def) {
        $taxonomy = $def['taxonomy'] ?? '';
        $field    = $def['field'] ?? 'name';
        $property = $def['property'] ?? '';
        $format   = $def['format'] ?? 'string';

        if (empty($taxonomy) || empty($property)) {
            continue;
        }

        // Fetch terms once per taxonomy, reuse across type maps
        if (!isset($taxonomy_cache[$taxonomy])) {
            $terms = frl_cf_get_post_terms($post_id, $taxonomy, $field);
            $taxonomy_cache[$taxonomy] = (is_array($terms)) ? array_values(array_filter($terms, 'is_string')) : [];
        }

        $values = $taxonomy_cache[$taxonomy];

        if (empty($values)) {
            continue;
        }

        switch ($format) {
            case 'string':
                $props[$property] = $values[0];
                break;

            case 'csv':
                $props[$property] = implode(', ', $values);
                break;

            case 'array':
                $props[$property] = $values;
                break;

            case 'thing':
                $things = [];
                foreach ($values as $val) {
                    $things[] = [
                        '@type' => 'Thing',
                        'name'  => $val,
                    ];
                }
                $props[$property] = count($things) === 1 ? $things[0] : $things;
                break;
        }
    }

    return $props;
}

/**
 * Get the Person reference mapping configuration.
 *
 * Keys: '_ref' = ACF field name for CPT ref IDs, '_default' = fallback (int CPT ID or static array),
 * '_remove' = true to omit the property when no refs found.
 *
 * @return array Filterable map of schema @type => person field defs.
 */
function frl_get_schema_person_map(): array
{
    $prefix = '';
    if (function_exists('frl_environment_get_config')) {
        $env_config = frl_environment_get_config();
        $prefix = $env_config['prefix'] ?? '';
    }

    $file = FRL_DIR_PATH . 'public/schema/default-schema-person.php';
    if ($prefix) {
        $brand_file = FRL_DIR_PATH . "public/schema/{$prefix}-schema-person.php";
        if (file_exists($brand_file)) {
            $file = $brand_file;
        }
    }

    $map = file_exists($file) ? include $file : [];

    /**
     * Filter the person reference map.
     *
     * @param array $map Schema type => person field defs.
     */
    return apply_filters('frl_schema_person_map', $map);
}

/**
 * Build Person reference properties from a type map.
 *
 * Resolution order: ACF ref IDs → _default (int CPT ID or static array) → _remove (unset) → nothing.
 *
 * @param int   $post_id    Post ID.
 * @param array $type_map   Person field defs from frl_get_schema_person_map().
 * @param array &$ref_cache Cache of resolved property => Person arrays.
 * @return array Schema properties to inject.
 */
function frl_build_schema_person_properties(int $post_id, array $type_map, array &$ref_cache): array
{
    $props = [];

    foreach ($type_map as $property => $field_def) {
        if (!is_string($property) || empty($property) || !is_array($field_def)) {
            continue;
        }

        $cache_key = "{$post_id}_{$property}";

        if (!isset($ref_cache[$cache_key])) {
            $ref_ids = [];
            $ref_source = $field_def['_ref'] ?? null;

            // Try ACF field on current post for reference IDs
            if ($ref_source && function_exists('get_field')) {
                $raw = get_field($ref_source, $post_id, false);
                if (is_numeric($raw)) {
                    $ref_ids = [(int) $raw];
                } elseif ($raw instanceof \WP_Post) {
                    // ACF Post Object field set to return post objects
                    $ref_ids = [$raw->ID];
                } elseif (is_array($raw)) {
                    foreach ($raw as $item) {
                        if ($item instanceof \WP_Post) {
                            $ref_ids[] = $item->ID;
                        } elseif (is_numeric($item)) {
                            $ref_ids[] = (int) $item;
                        }
                    }
                }
            }

            // Build Person objects from reference IDs
            $persons = [];
            foreach ($ref_ids as $rid) {
                $person = frl_build_person_from_ref($rid, $field_def);
                if ($person !== null) {
                    $persons[] = $person;
                }
            }

            $ref_cache[$cache_key] = $persons;
        }

        $persons = $ref_cache[$cache_key];

        if (!empty($persons)) {
            $props[$property] = count($persons) === 1 ? $persons[0] : $persons;
        } elseif (isset($field_def['_default'])) {
            // _default can be a CPT post ID (int) or a static Person array
            $default = $field_def['_default'];
            if (is_int($default)) {
                $person = frl_build_person_from_ref($default, $field_def);
                if ($person !== null) {
                    $props[$property] = $person;
                } elseif (!empty($field_def['_remove'])) {
                    // _default CPT not found — remove the property
                    $props[$property] = null;
                }
            } elseif (is_array($default)) {
                $props[$property] = $default;
            }
        } elseif (!empty($field_def['_remove'])) {
            // Remove the property from schema when no refs found
            $props[$property] = null;
        }
    }

    return $props;
}

/**
 * Build a Person schema object from a CPT post ID and field map.
 *
 * Source resolution: 'post_' prefix = WP-native (post_permalink, post_thumbnail, post_{field}),
 * anything else = ACF get_field().
 *
 * @param int   $ref_id    CPT post ID.
 * @param array $field_def Field definition (_ref + Person property map).
 * @return array|null Person array or null if post not found.
 */
function frl_build_person_from_ref(int $ref_id, array $field_def): ?array
{
    $post = get_post($ref_id);

    if (!$post) {
        return null;
    }

    $person = ['@type' => 'Person'];

    foreach ($field_def as $sub_field => $source) {
        // Skip reserved key — it's the ref source, not a Person property
        if ($sub_field === '_ref') {
            continue;
        }

        $value = null;

        // Single convention: 'post_' prefix = WP-native functionality
        if (str_starts_with($source, 'post_')) {
            if ($source === 'post_permalink') {
                $value = get_permalink($ref_id);
            } elseif ($source === 'post_thumbnail') {
                $id = get_post_thumbnail_id($ref_id);
                if ($id) {
                    $size = apply_filters('frl_schema_thumbnail_size', 'medium');
                    $url = wp_get_attachment_image_url($id, $size);
                    $data = wp_get_attachment_image_src($id, $size);
                    $value = [
                        '@type'  => 'ImageObject',
                        'url'    => $url ?: '',
                        'height' => $data[2] ?? 0,
                        'width'  => $data[1] ?? 0,
                    ];
                }
            } elseif ($source === 'post_thumbnail_url') {
                $value = get_the_post_thumbnail_url($ref_id, 'full');
            } else {
                // Native WP field: $post->{field}
                $value = $post->{$source} ?? null;
            }
        } elseif (function_exists('get_field')) {
            $value = get_field($source, $ref_id, false);
        }

        if ($value !== null && $value !== false && $value !== '') {
            if (is_array($value) && isset($value['label'])) {
                $value = $value['label'];
            }
            $person[$sub_field] = is_scalar($value) ? (string) $value : $value;
        }
    }

    /**
     * Filter the resolved Person schema object.
     *
     * @param array $person Person schema array.
     * @param int   $ref_id Referenced post ID.
     */
    return apply_filters('frl_schema_person_fields', $person, $ref_id);
}
