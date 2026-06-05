<?php
/**
 * Schema Builders
 *
 * Build schema properties from WordPress data: post terms and Person references.
 *
 * @package Fralenuvole
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get the post-term schema mapping configuration.
 *
 * Loads the schema term data file, transforms simple "property => taxonomy"
 * pairs into full field definitions, then applies the filter.
 *
 * @return array Filterable map of schema @type => field definitions.
 */
function frl_get_schema_term_map(): array
{
    if (!frl_get_option('schema_properties')) {
        return [];
    }

    $file = frl_get_schema_data_file('default-schema-terms.php');
    $raw = file_exists($file) ? include $file : [];
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
            $terms = frl_get_post_terms($post_id, $taxonomy, $field);
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
 * Keys: '_ref' = ACF field for CPT ref IDs, '_force' = always use (skip _ref),
 * '_fallback' = use when _ref is empty, '_remove' = omit when no refs.
 *
 * @return array Filterable map of schema @type => person field defs.
 */
function frl_get_schema_person_map(): array
{
    if (!frl_get_option('schema_properties')) {
        return [];
    }

    $file = frl_get_schema_data_file('default-schema-person.php');
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
 * Resolution order: _force → ACF ref IDs → _fallback → _remove → nothing.
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
            // _force: always use this value, skip _ref entirely
            if (isset($field_def['_force'])) {
                $force = $field_def['_force'];
                if (is_int($force)) {
                    $person = frl_build_person_from_ref($force, $field_def);
                    $ref_cache[$cache_key] = $person !== null ? [$person] : [];
                } elseif (is_array($force)) {
                    $ref_cache[$cache_key] = [$force];
                } else {
                    $ref_cache[$cache_key] = [];
                }
            } else {
                $ref_ids = [];
                $ref_source = $field_def['_ref'] ?? null;

                // Resolve ref IDs via helper (handles ACF + ACPT)
                $raw = $ref_source ? frl_get_post_meta($post_id, $ref_source, true) : null;

                if ($raw !== null) {
                    if (is_numeric($raw)) {
                        $ref_ids = [(int) $raw];
                    } elseif ($raw instanceof \WP_Post) {
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
        }

        $persons = $ref_cache[$cache_key];

        if (!empty($persons)) {
            $props[$property] = count($persons) === 1 ? $persons[0] : $persons;
        } elseif (isset($field_def['_fallback'])) {
            // _fallback: CPT post ID (int) or static Person array
            $fallback = $field_def['_fallback'];
            if (is_int($fallback)) {
                $person = frl_build_person_from_ref($fallback, $field_def);
                if ($person !== null) {
                    $props[$property] = $person;
                } elseif (!empty($field_def['_remove'])) {
                    $props[$property] = null;
                }
            } elseif (is_array($fallback)) {
                $props[$property] = $fallback;
            }
        } elseif (!empty($field_def['_remove'])) {
            $props[$property] = null;
        }
    }

    return $props;
}

/**
 * Build a Person schema object from a CPT post ID and field map.
 *
 * Source resolution: 'post_' prefix = WP-native (post_permalink, post_thumbnail, post_{field}),
 * anything else = frl_get_post_meta($ref_id, $source, true).
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

        // Array of meta keys → resolve each, collect non-empty values (e.g. sameAs)
        if (is_array($source)) {
            $values = [];
            foreach ($source as $meta_key) {
                $v = frl_extract_scalar_value(frl_get_post_meta($ref_id, (string) $meta_key, true));
                if ($v !== null) {
                    $values[] = $v;
                }
            }
            if (!empty($values)) {
                $person[$sub_field] = count($values) === 1 ? $values[0] : $values;
            }
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
        } else {
            $value = frl_get_post_meta($ref_id, $source, true);
        }

        $scalar = frl_extract_scalar_value($value);
        if ($scalar !== null) {
            $person[$sub_field] = $scalar;
        } elseif (is_array($value) && !empty($value)) {
            $person[$sub_field] = $value;
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
