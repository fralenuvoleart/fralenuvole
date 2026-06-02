<?php
/**
 * Schema Service
 *
 * Provides dynamic, translatable schema properties for JSON-LD output.
 * Data is loaded from a pure data file, resolved (placeholders + translation),
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

    return frl_cache_remember('options', $cache_key, function () {
        $file = FRL_DIR_PATH . 'public/schema/default-schema.php';
        $raw = file_exists($file) ? include $file : [];

        $resolved = frl_resolve_schema_properties($raw);

        /**
         * Filter the resolved schema properties.
         *
         * Allows modules (e.g., environment, thirdparty) to add, remove,
         * or override schema properties before they are injected into output.
         *
         * @param array $resolved Resolved schema properties.
         * @return array Modified schema properties.
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
            // Replace {{site_url}} placeholder
            $value = str_replace('{{site_url}}', $site_url, $value);

            // Translate non-structural values (guard for when translator module is not loaded)
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
