<?php
/**
 * Schema Helpers
 *
 * Generic helper functions shared by the schema properties
 * and generator subsystems.
 *
 * @package Fralenuvole
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Normalize a meta value: extract 'label' from arrays, cast scalars to string.
 *
 * @param mixed $value The raw meta value.
 * @return string|null Extracted scalar string or null if invalid.
 */
function frl_schema_extract_scalar_value($value): ?string
{
    if ($value === null || $value === false || $value === '') {
        return null;
    }
    if (is_array($value) && isset($value['label'])) {
        $value = $value['label'];
    }
    return is_scalar($value) ? (string) $value : null;
}

/**
 * Resolve the file path for a schema data file, supporting per-brand overrides.
 *
 * Tries {prefix}-variant first; falls back to the default filename.
 *
 * @param string $default_filename The default filename (e.g. 'default-schema.php').
 * @param string $subdir           Data subdirectory: 'properties' or 'generators'.
 * @return string Resolved file path.
 */
function frl_schema_get_data_file(string $default_filename, string $subdir = 'properties'): string
{
    $prefix = '';
    if (function_exists('frl_environment_get_config')) {
        $env_config = frl_environment_get_config();
        $prefix = $env_config['prefix'] ?? '';
    }

    $base = FRL_DIR_PATH . 'public/schema/data/' . $subdir . '/';

    $file = $base . $default_filename;
    if ($prefix) {
        $brand_filename = str_replace('default-', $prefix . '-', $default_filename);
        $brand_file = $base . $brand_filename;
        if (file_exists($brand_file)) {
            return $brand_file;
        }
    }
    return $file;
}

/**
 * Replace {{placeholder}} tokens in a string or array (recursive).
 *
 * @param string|array $data         String or nested array.
 * @param array        $replacements Map of {{placeholder}} => replacement.
 * @return string|array Data with placeholders replaced.
 */
function frl_schema_replace_placeholders(string|array $data, array $replacements): string|array
{
    if (empty($replacements)) {
        return $data;
    }

    if (is_string($data)) {
        return str_replace(array_keys($replacements), array_values($replacements), $data);
    }

    $result = [];
    foreach ($data as $key => $value) {
        if (is_array($value)) {
            $result[$key] = frl_schema_replace_placeholders($value, $replacements);
        } elseif (is_string($value)) {
            $result[$key] = str_replace(array_keys($replacements), array_values($replacements), $value);
        } else {
            $result[$key] = $value;
        }
    }
    return $result;
}

/**
 * Build the standard placeholder map.
 *
 * Site-wide placeholders (site_url, org name, etc.) are always available.
 * Post-aware placeholders require $post_id.
 *
 * @param int|null $post_id Post ID for post-aware placeholders, or null to skip.
 * @return array Map of {{placeholder}} => replacement string.
 */
function frl_schema_get_placeholders(?int $post_id = null): array
{
    $logo = wp_get_attachment_image_src(get_theme_mod('custom_logo'), 'full');

    $map = [
        '{{site_url}}'                => site_url(),
        '{{site_url_local}}'          => frl_get_home_url(),
        '{{custom_logo}}'             => $logo[0] ?? '',
        '{{schema_organization_url}}'  => frl_get_option('schema_organization_url') ?: site_url(),
        '{{schema_organization_name}}' => frl_get_option('schema_organization_name') ?: get_bloginfo('name'),
        '{{schema_founder_name}}'      => frl_get_option('schema_founder_name') ?: '',
    ];

    if ($post_id !== null) {
        $map['{{post_title}}'] = get_the_title($post_id);
    }

    return $map;
}

/**
 * Build a Schema.org ImageObject from a WordPress attachment ID.
 *
 * @param int    $attachment_id Attachment/thumbnail ID.
 * @param string $size          Image size (default: 'medium').
 * @return array|null ImageObject array, or null if image not found.
 */
function frl_schema_build_image_object(int $attachment_id, string $size = 'medium'): ?array
{
    $size   = apply_filters('frl_schema_thumbnail_size', $size);
    $url    = wp_get_attachment_image_url($attachment_id, $size);
    $data   = wp_get_attachment_image_src($attachment_id, $size);

    if (!$url) {
        return null;
    }

    return [
        '@type'  => 'ImageObject',
        'url'    => $url,
        'width'  => $data[1] ?? 0,
        'height' => $data[2] ?? 0,
    ];
}

/**
 * Read repeater rows from a post using ACF or ACPT.
 *
 * Each row is returned as an associative array built from $field_map.
 * $field_map maps output keys → source field names.
 *
 * @param int    $post_id   Post ID.
 * @param string $repeater  Repeater field name.
 * @param string $source    'acf' or 'acpt'.
 * @param array  $field_map Output key → source field name (e.g. ['name' => 'title', 'text' => 'answer']).
 * @return array List of associative arrays (one per row).
 */
function frl_schema_get_repeater_rows(int $post_id, string $repeater, string $source, array $field_map): array
{
    if ($source === 'acpt') {
        return frl_schema_get_repeater_rows_acpt($post_id, $repeater, $field_map);
    }
    return frl_schema_get_repeater_rows_acf($post_id, $repeater, $field_map);
}

/**
 * Read repeater rows via ACF (have_rows / get_sub_field).
 *
 * @param int    $post_id   Post ID.
 * @param string $repeater  Repeater field name.
 * @param array  $field_map Output key → source field name.
 * @return array List of associative arrays.
 */
function frl_schema_get_repeater_rows_acf(int $post_id, string $repeater, array $field_map): array
{
    if (!function_exists('have_rows')) {
        return [];
    }

    $rows = [];
    if (!have_rows($repeater, $post_id)) {
        return [];
    }

    while (have_rows($repeater, $post_id)) {
        the_row();
        $row = [];
        foreach ($field_map as $out_key => $field_name) {
            $val = get_sub_field($field_name);
            if ($val !== null && $val !== false && $val !== '') {
                $row[$out_key] = is_string($val) ? $val : '';
            }
        }
        if (!empty($row)) {
            $rows[] = $row;
        }
    }

    return $rows;
}

/**
 * Read repeater rows via ACPT (columnar serialized array).
 *
 * @param int    $post_id   Post ID.
 * @param string $repeater  Repeater field name.
 * @param array  $field_map Output key → source column name.
 * @return array List of associative arrays.
 */
function frl_schema_get_repeater_rows_acpt(int $post_id, string $repeater, array $field_map): array
{
    $data = frl_get_post_meta($post_id, $repeater, true);
    if (!is_array($data)) {
        return [];
    }

    // Determine row count from the first key
    $first_key = array_key_first($field_map);
    if ($first_key === null) {
        return [];
    }

    $first_column = $data[$field_map[$first_key]] ?? [];
    if (!is_array($first_column)) {
        return [];
    }

    $count = count($first_column);
    $rows  = [];

    for ($i = 0; $i < $count; $i++) {
        $row = [];
        foreach ($field_map as $out_key => $col_name) {
            $val = $data[$col_name][$i] ?? null;
            if ($val !== null && $val !== '') {
                $row[$out_key] = (string) $val;
            }
        }
        if (!empty($row)) {
            $rows[] = $row;
        }
    }

    return $rows;
}

/**
 * Resolve a definition value: placeholder → field name → literal.
 *
 * Resolution order:
 *   1. Replace {{placeholders}} via frl_schema_replace_placeholders
 *   2. If value changed → use resolved result
 *   3. Otherwise → treat as field name, resolve via frl_get_post_meta
 *
 * @param int    $post_id      Post ID.
 * @param string $raw          Raw definition value.
 * @param array  $placeholders Placeholder map (from frl_schema_get_placeholders).
 * @return string|null Resolved value, or null if unresolvable.
 */
function frl_schema_resolve_value(int $post_id, string $raw, array $placeholders): ?string
{
    $resolved = frl_schema_replace_placeholders($raw, $placeholders);

    // Placeholder was replaced → use directly
    if ($resolved !== $raw) {
        return ($resolved !== '') ? $resolved : null;
    }

    // Treat as field name → resolve via post meta
    return frl_schema_extract_scalar_value(frl_get_post_meta($post_id, $raw, true));
}

/**
 * Determine if a key should be translated based on the translate keys config.
 *
 * @param string $key           The bare key name.
 * @param string $current_path  The full dot-path of the current key.
 * @param array  $translate_keys FRL_SCHEMA_TRANSLATE_KEYS entries ('!' prefix = skip).
 * @return bool True if this key should be translated.
 */
function frl_schema_should_translate_key(string $key, string $current_path, array $translate_keys): bool
{
    $should_translate = false;

    foreach ($translate_keys as $entry) {
        $is_skip = str_starts_with($entry, '!');
        $rule = $is_skip ? substr($entry, 1) : $entry;

        $matches = str_contains($rule, '.')
            ? str_starts_with($current_path, $rule)  // dot-path prefix match
            : $key === $rule;                         // bare key exact match

        if ($matches) {
            if ($is_skip) {
                return false;         // '!' trumps immediately
            }
            $should_translate = true; // match found; keep checking for '!' overrides
        }
    }

    return $should_translate;
}

/**
 * Replace post-aware placeholders in resolved schema props.
 *
 * @param array $props   Resolved schema properties array.
 * @param int   $post_id Current post ID.
 * @return array Props with post placeholders resolved.
 */
function frl_schema_resolve_post_placeholders(array $props, int $post_id): array
{
    return frl_schema_replace_placeholders($props, frl_schema_get_placeholders($post_id));
}
