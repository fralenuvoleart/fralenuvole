<?php
/**
 * Schema Helpers
 *
 * Shared helper functions used by both the schema properties
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
function frl_extract_scalar_value($value): ?string
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
 * Used by both the properties and generator subsystems via the $subdir parameter.
 *
 * @param string $default_filename The default filename (e.g. 'default-schema.php').
 * @param string $subdir           Data subdirectory: 'properties' or 'generators'.
 * @return string Resolved file path.
 */
function frl_get_schema_data_file(string $default_filename, string $subdir = 'properties'): string
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
