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
 * Build the map of placeholder → value for schema resolution.
 *
 * @return array Map of {{placeholder}} => replacement string.
 */
function frl_get_schema_placeholders(): array
{
    $logo = wp_get_attachment_image_src(get_theme_mod('custom_logo'), 'full');

    return [
        '{{site_url}}'          => site_url(),
        '{{site_url_local}}'    => frl_get_home_url(),
        '{{custom_logo}}'       => $logo[0] ?? '',
        '{{schema_organization_url}}'  => frl_get_option('schema_organization_url') ?: site_url(),
        '{{schema_organization_name}}' => frl_get_option('schema_organization_name') ?: get_bloginfo('name'),
        '{{schema_founder_name}}' => frl_get_option('schema_founder_name') ?: '',
    ];
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
 * Recursively resolve schema properties in a single pass.
 *
 * - Replaces all {{placeholder}} tokens via the pre-built $replacements map
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

/**
 * Replace {{placeholder}} tokens in schema props with given values.
 *
 * @param array $props        Resolved schema properties array.
 * @param array $replacements Map of placeholder → replacement string.
 * @return array Props with placeholders resolved.
 */
function frl_resolve_placeholders(array $props, array $replacements): array
{
    if (empty($replacements)) {
        return $props;
    }

    $result = [];

    foreach ($props as $key => $value) {
        if (is_array($value)) {
            $result[$key] = frl_resolve_placeholders($value, $replacements);
        } elseif (is_string($value)) {
            $result[$key] = str_replace(array_keys($replacements), array_values($replacements), $value);
        } else {
            $result[$key] = $value;
        }
    }

    return $result;
}

/**
 * Replace post-aware placeholders in resolved schema props.
 *
 * Delegates to frl_resolve_placeholders() with post-derived replacements.
 * Replaces: {{post_title}} → get_the_title($post_id)
 *
 * @param array $props   Resolved schema properties array.
 * @param int   $post_id Current post ID.
 * @return array Props with post placeholders resolved.
 */
function frl_resolve_post_placeholders(array $props, int $post_id): array
{
    return frl_resolve_placeholders($props, [
        '{{post_title}}' => get_the_title($post_id),
    ]);
}
