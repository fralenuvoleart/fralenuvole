<?php
/**
 * Schema Resolver
 *
 * Loads schema data files, resolves {{placeholder}} tokens, and translates
 * configurable keys. Cached per-language.
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
    if (!frl_get_option('schema_properties')) {
        return [];
    }

    $language = frl_get_language();
    $version = frl_get_option('translation_version') ?: 1;
    $cache_key = "schema_properties_{$language}_{$version}";

    return frl_cache_remember('html', $cache_key, function () {
        $file = frl_get_schema_data_file('default-schema.php');
        $raw = file_exists($file) ? include $file : [];
        $resolved = frl_resolve_schema_properties($raw, '', frl_get_schema_placeholders());

        /**
         * Filter the resolved schema properties.
         *
         * @param array $resolved Resolved schema properties.
         */
        return apply_filters('frl_schema_properties', $resolved);
    });
}

/**
 * Recursively resolve schema properties in a single pass.
 *
 * - Replaces all {{placeholder}} tokens via the shared frl_replace_placeholders
 * - Translates matching keys and handles '_remove' sentinel
 *
 * @param array  $props        Raw schema properties array.
 * @param string $path         Dot-path of the current nesting level (internal).
 * @param array  $replacements Map of {{placeholder}} => replacement string (built once).
 * @return array Resolved schema properties array.
 */
function frl_resolve_schema_properties(array $props, string $path = '', array $replacements = []): array
{
    if (empty($replacements)) {
        $replacements = frl_get_schema_placeholders();
    }
    $translate_keys = defined('FRL_SCHEMA_TRANSLATE_KEYS') ? FRL_SCHEMA_TRANSLATE_KEYS : [];
    $result = [];

    foreach ($props as $key => $value) {
        $current_path = $path ? "{$path}.{$key}" : $key;

        if (is_array($value)) {
            $result[$key] = frl_resolve_schema_properties($value, $current_path, $replacements);
        } elseif (is_string($value)) {
            $value = str_replace(array_keys($replacements), array_values($replacements), $value);

            if ($value === '_remove') {
                $value = null;
            } elseif (frl_schema_should_translate_key($key, $current_path, $translate_keys)
                && function_exists('frl_get_translation')) {
                $value = frl_get_translation($value);
            }
            $result[$key] = $value;
        } else {
            $result[$key] = $value;
        }
    }

    return $result;
}
