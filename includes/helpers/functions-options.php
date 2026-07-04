<?php

/**
 * Fralenuvole
 * functions-options.php - Plugin options functions
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Retrieves a plugin option, using caches and default instantiation if needed.
 *
 * The retrieval flow is as follows:
 * 1. Check request-local static cache.
 * 2. Load all options (from persistent cache or DB); values are normalized on load.
 * 3. If the key is still missing (handling stale 'all_options' cache), frl_handle_missing_option_key() checks the DB.
 *    - Returns the value if found (and clears the 'all_options' cache).
 *    - Returns '__missing_option__' if the option is truly not in the DB.
 * 4. If '__missing_option__' is received, frl_set_missing_option_default() saves and returns the default value.
 *    (Ensures the default is set only once per request via a 'write_attempted' flag).
 *
 * @param string $key The option key without prefix.
 * @param bool $bypass_cache Whether to bypass the static cache and fetch fresh from DB.
 * @return mixed The normalized option value, or null if not found and no default is defined.
 */
function frl_get_option($key, $bypass_cache = false)
{
    static $options = [];
    static $loaded = false;
    static $write_attempted = [];

    if ($key === '__reset__') {
        $options = [];
        $loaded = false;
        $write_attempted = []; // Cleared on reset
        frl_get_plugin_options_db(true);
        return null;
    }

    try {
        // --- 1. Request-local static cache check ---
        if (!$bypass_cache && $loaded && isset($options[$key])) {
            return $options[$key];
        }

        // --- 2. Populate static $options if not loaded or if bypassing cache ---
        if ($bypass_cache) {
            // Bypassing cache: fetch fresh from DB. $loaded state is not changed.
            $options = frl_get_plugin_options_db();
            if (isset($options[$key])) {
                return $options[$key];
            }
        } elseif (!$loaded) {
            // Not bypassing, and $options not loaded yet. Load from persistent cache.
            $options = frl_get_plugin_options('all');
            $loaded = true; // Mark as loaded for this request.
            if (isset($options[$key])) {
                return $options[$key];
            }
        }
        // If fall through:
        // - $key was not found in the $options populated above (either fresh from DB or from frl_cache_remember).
        // - OR ($bypass_cache was false AND $loaded was true initially), and initial static check $options[$key] failed.
        // The next block handles these cases.

        // --- 3. Key not in (loaded) $options: Check DB directly (handles stale 'all_options' cache) ---
        if (!isset($options[$key])) {
            $result = frl_handle_missing_option_key($key, $bypass_cache, $options);
            if ($result !== '__missing_option__') {
                return $result;
            }
            // If '__missing_option__' received, option is genuinely not in DB.
        }

        // --- 4. Option genuinely missing: Set and return its default value ---
        if (empty($write_attempted[$key])) {
            $write_attempted[$key] = true;
            return frl_set_missing_option_default($key, $bypass_cache, $options);
        } else {
            if (isset($options[$key])) {
                return $options[$key];
            }
            $default = frl_get_all_plugin_options_settings($key); // Changed from $option_default_info_final_fallback
            return $default !== null ? $default['value'] : null;
        }
    } finally {
        // Re-entrancy guard intentionally omitted: the $loaded static (line 32)
        // and $write_attempted array (line 33) provide the actual protection.
    }
}

/**
 * Set a plugin option by name, without prefix.
 *
 * The value is normalized based on the option's defined type before being saved.
 *
 * @param string $key Option key without prefix.
 * @param mixed $value_param Value to set.
 * @param bool $clear_cache Whether to clear the options cache, default = true.
 * @param string|null $autoload_param Optional. Whether to autoload the option. Accepts 'yes' or 'no'. Defaults to 'yes' if null.
 * @return bool True on success, false on failure.
 */
function frl_update_option($key, $value_param, $clear_cache = true, $autoload_param = null)
{
    if (!is_string($key)) {
        return false;
    }

    $default = frl_get_all_plugin_options_settings($key);
    $type = $default['type'] ?? 'text';

    $normalized_value = frl_normalize_option($value_param, $type);

    $prefixed_key = frl_prefix($key);
    $autoload = frl_normalize_autoload($autoload_param);

    // Strip stale anonymous closures from prior writes to the same key in this request.
    // Without this, multiple frl_update_option() calls accumulate priority-9999 filters
    // returning old values, overriding the latest write. Closures are anonymous and cannot
    // be referenced by name for targeted remove_filter().
    remove_all_filters('pre_option_' . $prefixed_key);
    $result = update_option($prefixed_key, $normalized_value, $autoload);

    if ($clear_cache) {
        // Only refresh the options cache entry itself – dependent groups must remain intact to avoid thrashing.
        frl_cache_clear('options', 'all_options', false);
        // HTML options are cached in the html group with 1-week TTL;
        // single-key clears do not cascade, so explicit invalidation is needed.
        if ($key === 'header_html' || $key === 'footer_html') {
            frl_cache_clear('html');
        }
    }

    add_filter('pre_option_' . $prefixed_key,
        function () use ($normalized_value) {
            return $normalized_value;
        },
        9999,
        1);

    return $result;
}

/**
 * Delete a plugin option by name, without prefix.
 *
 * @param string $key Option key without prefix.
 * @param string $cache_group Optional cache group to clear after deletion.
 * @return bool True on success, false on failure.
 */
function frl_delete_option($key, $cache_group = '')
{
    if (!is_string($key)) {
        return false;
    }

    $prefixed_key = frl_prefix($key);

    // Same stale-closure rationale as frl_update_option() — prevents a previously-written
    // priority-9999 filter from resurrecting a deleted option's value via pre_option_*.
    remove_all_filters('pre_option_' . $prefixed_key);

    // Update option and capture result
    $result = delete_option($prefixed_key);

    // Clear cache group
    if ($cache_group) {
        frl_cache_clear($cache_group);
    }

    return $result;
}

/**
 * Normalize an option value based on its defined type.
 *
 * @param mixed $value The raw value to normalize.
 * @param string $type The defined type of the option (e.g., 'checkbox', 'textlist', 'text').
 * @return mixed The normalized value.
 */
function frl_normalize_option($value, string $type = 'checkbox')
{
    // Early return if the type is 'checkbox' (which is also the default if $type is omitted)
    // or if the type is 'radio'.
    if ($type === 'checkbox' || $type === 'radio') {
        return frl_normalize_boolval($value);
    }

    // Pass through values for defined formatting fields without normalization.
    if (is_array(FRL_FIELD_FORMATTERS) && in_array($type, FRL_FIELD_FORMATTERS, true)) { // @phpstan-ignore-line
        return $value;
    }

    switch ($type) {
        case 'textlist':
            return frl_normalize_textlist($value);

        case 'text':
        case 'html':
        case 'wysiwyg':
        case 'select':
        case 'textarea':
        case 'email':
        case 'url':
        case 'number':
        case 'color':
        case 'date':
        case 'password':
        case 'custom':
            return $value;

        default:
            return frl_normalize_boolval($value);
    }
}

/**
 * Get multiple options at once
 *
 * @param array|string $keys Array of option keys without prefix, or 'all' to get all options
 * @param bool $bypass_cache Whether to bypass cache
 * @return array Associative array of option keys and their values
 */
function frl_get_plugin_options($keys = 'all', $bypass_cache = false)
{
    // First, retrieve all options (either from cache or DB)
    $all_options = null;

    if ($bypass_cache) {
        // Direct database query when bypassing cache
        $all_options = frl_get_plugin_options_db();
    } else {
        // Use frl_cache_remember for persistent caching
        $all_options = frl_cache_remember('options', 'all_options', function () {
            return frl_get_plugin_options_db();
        });
    }

    // Special case: return all options
    if ($keys === 'all') {
        return $all_options;
    }

    // Regular case: get specific options
    if (!frl_is_array_not_empty($keys)) {
        return [];
    }

    // Extract only the requested keys from all options
    $result = [];
    foreach ($keys as $key) {
        $result[$key] = isset($all_options[$key]) ? $all_options[$key] : frl_get_option($key, $bypass_cache);
    }

    return $result;
}

/**
 * Get all plugin options directly from the database..
 *
 * @param bool $reset Whether to reset the request-local cache and return null.
 * @return array|null Associative array of normalized options, or null if $reset is true.
 */
function frl_get_plugin_options_db($reset = false)
{
    // Static cache for current request
    static $request_cached_options = null;
    static $option_type_map = null;

    // Handle reset
    if ($reset === true) {
        $request_cached_options = null;
        $option_type_map = null; // Reset type map on full reset
        return null; // Return null consistent with frl_get_option reset
    }

    // Return cached results if available
    if ($request_cached_options !== null) {
        return $request_cached_options;
    }

    // Build the option type map once per request if not already built
    if ($option_type_map === null) {
        $option_type_map = [];
        // frl_get_all_plugin_options_settings(null) returns an array of full field definitions
        $all_default_definitions = frl_get_all_plugin_options_settings(null);
        if (frl_is_array_not_empty($all_default_definitions)) {
            foreach ($all_default_definitions as $field_def) {
                if (isset($field_def['id']) && isset($field_def['type'])) {
                    $option_type_map[$field_def['id']] = $field_def['type'];
                }
            }
        }
    }

    global $wpdb;
    $prefix = frl_prefix();
    $prefix_length = strlen($prefix);

    // Direct database query
    $results = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT option_name, option_value FROM {$wpdb->options}
             WHERE option_name LIKE %s",
            $wpdb->esc_like($prefix) . '%'
        )
    );

    $options = [];
    foreach ($results as $row) {
        // More efficient key extraction
        $key = substr($row->option_name, $prefix_length);
        $raw_value = maybe_unserialize($row->option_value);

        // Get the type for this option key, defaulting to 'text' if not found in our map
        // (e.g. for dynamically added options not in config-default-fields.php)
        $option_type = $option_type_map[$key] ?? 'text';

        $options[$key] = frl_normalize_option($raw_value, $option_type);
    }

    // Cache the results for this request
    $request_cached_options = $options;

    return $options;
}

/**
 * Get option settings (returns single option metadata, all option defaults, or all field definitions based on parameters)
 * @param string|null $key Optional specific option key to retrieve
 * @param bool $ui_fields Whether to return UI-only fields (includes formatting fields)
 * @return mixed Array of all settings or specific setting default
 */
function frl_get_all_plugin_options_settings($key = null, $ui_fields = false)
{
    // For single key lookups, get from the appropriate cached group and use fast array access
    if ($key !== null) {
        // First try the fields_data cache (most common for option defaults)
        $cache_key = 'option_default_' . $key;
        $fields_data = frl_cache_remember('options', $cache_key, function () {
            $options_defaults = frl_load_config_options_defaults();
            $modules_options_defaults = frl_modules_load_options_defaults();
            $runtime_options_defaults = frl_load_runtime_options_defaults();

            return array_merge(
                $options_defaults,
                $modules_options_defaults,
                $runtime_options_defaults
            );
        });

        return $fields_data[$key] ?? null;
    }

    // For full datasets, cache each type separately to reduce individual cache entry size
    if ($ui_fields) {
        return frl_cache_remember('adminui', 'all_options_fields_ui', function () {
            $fields_defaults = frl_load_config_options_fields();
            $modules_fields_defaults = frl_modules_load_options_fields();

            // To have module options appear first in the UI, merge module fields before config fields.
            return array_merge(
                $modules_fields_defaults,  // Module settings fields first
                $fields_defaults   // Main plugin settings fields after
            );
        });
    } else {
        return frl_cache_remember('adminui', 'all_options_fields', function () {
            $fields_defaults = frl_load_config_options_fields();
            $modules_fields_defaults = frl_modules_load_options_fields();
            $runtime_fields_defaults = frl_load_runtime_options_fields();
            $cpt_fields_defaults = frl_load_runtime_cpt_options_fields();

            return array_merge(
                frl_remove_formatter_fields($fields_defaults),
                frl_remove_formatter_fields($modules_fields_defaults),
                $runtime_fields_defaults,
                $cpt_fields_defaults
            );
        });
    }
}

/**
 * Retrieve section names
 * @return array Flat structure compatible with existing code
 */
function frl_get_default_fields_sections()
{
    static $sections = [];

    if (!empty($sections)) {
        return $sections;
    }

    foreach (FRL_DEFAULT_FIELDS as $section_id => $section) {
        $sections[$section_id] = empty($section['title']) ? 'Section Title' : $section['title'];
    }

    return $sections;
}

/**
 * Load option metadata from config-options.php (returns processed option defaults with value, autoload, type)
 * @return array Option metadata keyed by option ID
 */
function frl_load_config_options_defaults()
{
    $processed_fields = frl_cache_remember('options', 'config_options_defaults', function () {
        $flat_fields = frl_load_config_options_fields_flat();
        $internally_processed_fields = [];
        if (frl_is_array_not_empty($flat_fields)) {
            foreach ($flat_fields as $field_definition_item) {
                if (!isset($field_definition_item['id']) || empty($field_definition_item['id'])) {
                    continue;
                }
                $skip_due_to_formatter = false;
                if (defined('FRL_FIELD_FORMATTERS') && is_array(FRL_FIELD_FORMATTERS)) {
                    if (isset($field_definition_item['type']) && in_array($field_definition_item['type'], FRL_FIELD_FORMATTERS, true)) {
                        $skip_due_to_formatter = true;
                    }
                }
                if ($skip_due_to_formatter) {
                    continue;
                }
                $option_id_from_ui_def = $field_definition_item['id'];
                $field_type = $field_definition_item['type'] ?? 'text';
                $default_val_from_ui_def = $field_definition_item['default'] ?? null;
                $normalized_default = frl_normalize_option($default_val_from_ui_def, $field_type);
                $autoload_from_ui_def = frl_normalize_autoload($field_definition_item['autoload'] ?? 'yes');
                $internally_processed_fields[$option_id_from_ui_def] = [
                    'value' => $normalized_default,
                    'autoload' => $autoload_from_ui_def,
                    'type' => $field_type
                ];
            }
        }
        return $internally_processed_fields;
    });
    return $processed_fields;
}

/**
 * Load option metadata from FRL_OPTIONS_RUNTIME (returns processed option defaults with value, autoload, type)
 * @return array Option metadata keyed by option ID
 */
function frl_load_runtime_options_defaults()
{
    $all_defaults = frl_cache_remember('options', 'runtime_options_defaults', function () {
        $defaults = [];

        foreach (FRL_OPTIONS_RUNTIME as $key => $data) {
            $field_type = $data['type'] ?? 'text'; // @phpstan-ignore-line
            $field_default = $data['default'] ?? null; // @phpstan-ignore-line
            $field_default = frl_normalize_option($field_default, $field_type);
            $autoload = 'yes'; // Default autoload for runtime options

            // Build 'options' format
            $defaults[$key] = [
                'value' => $field_default,
                'autoload' => $autoload,
                'type' => $field_type
            ];
        }

        return $defaults;
    });

    return $all_defaults;
}

/**
 * Load field definitions from FRL_OPTIONS_RUNTIME (returns field configurations for admin UI)
 * @return array Field definition arrays with id, type, default, section, etc.
 */
function frl_load_runtime_options_fields()
{
    $all_defaults = frl_cache_remember('adminui', 'runtime_options_fields', function () {
        $fields = [];

        foreach (FRL_OPTIONS_RUNTIME as $key => $data) {
            /** @phpstan-ignore-next-line */
            $field_type = $data['type'] ?? 'text';
            /** @phpstan-ignore-next-line */
            $field_default = $data['default'] ?? null;
            $field_default = frl_normalize_option($field_default, $field_type);
            $autoload = 'yes'; // Default autoload for runtime options

            // Build 'fields' format
            $fields[] = [
                'id' => $key,
                'default' => $field_default,
                'type' => $field_type,
                'autoload' => $autoload,
                'section' => 'runtime',
                'label' => null,  // No UI metadata needed
                'description' => null
            ];
        }

        return $fields;
    });

    return $all_defaults;
}

/**
 * Load field definitions from FRL_OPTIONS_RUNTIME (returns field configurations for admin UI)
 * @return array Field definition arrays with id, type, default, section, etc.
 */
function frl_load_runtime_cpt_options_fields() {
    if (!defined('FRL_REWRITER_MULTILINGUAL_CPT') || !is_array(FRL_REWRITER_MULTILINGUAL_CPT)) {
        return [];
    }

    $cpt_fields = frl_cache_remember('adminui', 'runtime_translate_cpt_slugs', function () {
        $fields = [];
        $field_type = 'textlist';
        $autoload = 'yes'; // Default autoload for runtime options

        foreach (FRL_REWRITER_MULTILINGUAL_CPT as $slug) {
            $opt_key = 'translate_cpt_slugs_' . $slug;

            // Build 'fields' format
            $fields[] = [
                'id' => $opt_key,
                'default' => '',
                'type' => $field_type,
                'autoload' => $autoload,
                'section' => 'runtime',
                'label' => null,  // No UI metadata needed
                'description' => null
            ];
        }

        return $fields;
    });

    return $cpt_fields;
}

/**
 * Load field definitions from config-options.php (returns field configurations for admin UI)
 * @return array Field definition arrays with id, type, default, label, description, etc.
 */
function frl_load_config_options_fields()
{
    $config_fields = frl_cache_remember('adminui', 'config_options_fields', function () {
        // Flatten the hierarchical structure (config-default-fields.php only)
        $flat_fields = frl_load_config_options_fields_flat();

        $field_definitions = [];

        foreach ($flat_fields as $field) {
            // Skip if no ID - but keep formatting fields
            if (!isset($field['id'])) {
                continue;
            }

            $field_definitions[] = $field;
        }

        return $field_definitions;
    });

    return $config_fields;
}

/**
 * Load config field definitions in flat format (converts FRL_DEFAULT_FIELDS hierarchical structure to flat array)
 * @return array Flat field definitions from config-options.php
 */
function frl_load_config_options_fields_flat()
{
    $flat_fields = frl_cache_remember('adminui', 'config_options_fields_flat', function () {
        $result = [];

        foreach (FRL_DEFAULT_FIELDS as $section_id => $section) {
            if (!frl_is_array_not_empty($section, 'fields')) {
                continue;
            }

            foreach ($section['fields'] as $field_id => $field) {
                // Build the flat structure dynamically using FRL_FIELD_ATTRIBUTES
                $field_result = [];

                foreach (FRL_FIELD_ATTRIBUTES as $attribute => $default_value) {
                    if ($default_value === '__COMPUTED__') {
                        // Handle computed values
                        switch ($attribute) {
                            case 'section':
                                $field_result[$attribute] = $section_id;
                                break;
                            case 'id':
                                $field_result[$attribute] = $field_id;
                                break;
                        }
                    } else {
                        // Use field value if exists, otherwise use the default from FRL_FIELD_ATTRIBUTES
                        $field_result[$attribute] = $field[$attribute] ?? $default_value;
                    }
                }

                $result[] = $field_result;
            }
        }

        return $result;
    });

    return $flat_fields;
}

/**
 * Filter out formatting fields from field definitions array
 * @param array $fields Array of field definitions
 * @return array Filtered array without formatting fields
 */
function frl_remove_formatter_fields($fields)
{
    return array_filter($fields, function ($field) {
        return !(isset($field['type']) && in_array($field['type'], FRL_FIELD_FORMATTERS, true));
    });
}

/**
 * Get transient value with static caching
 * @param string $key Transient key without prefix
 * @param bool $bypass_cache Whether to bypass static cache
 * @return mixed Transient value or false if not found
 */
function frl_get_transient($key, $bypass_cache = false)
{
    // Get the shared cache
    $cache = &frl_transients_static_cache();

    // Check if we have a cached value (even if it's a sentinel value for false)
    if (!$bypass_cache && array_key_exists($key, $cache)) { // Check static cache first
        // Check if it's our sentinel value for false
        if ($cache[$key] === '__TRANSIENT_FALSE__') {
            return false;
        }
        return $cache[$key]; // Return from static cache
    }

    // Get from WordPress persistent transient if static cache missed or bypassed
    $value = get_transient(frl_prefix($key));

    // Store in static cache ONLY if not bypassing
    if (!$bypass_cache) {
        if ($value === false) {
            $cache[$key] = '__TRANSIENT_FALSE__';
        } else {
            $cache[$key] = $value;
        }
    }

    return $value;
}

/**
 * Set transient value with static caching
 * @param string $key Transient key without prefix
 * @param mixed $value Value to store
 * @param int $expiration Expiration time in seconds
 * @return bool Success
 */
function frl_set_transient($key, $value, $expiration = 0)
{
    // Get the shared cache
    $cache = &frl_transients_static_cache();

    // Normalize value
    $value = frl_normalize_boolval($value);

    // Update static cache with sentinel value if the value is false
    if ($value === false) {
        $cache[$key] = '__TRANSIENT_FALSE__';
    } else {
        $cache[$key] = $value;
    }

    // Update WordPress transient
    $result = set_transient(frl_prefix($key), $value, $expiration);

    return $result;
}

/**
 * Delete transient value and clear static cache
 * @param string $key Transient key without prefix
 * @return bool Success
 */
function frl_delete_transient($key)
{
    // Get the shared cache
    $cache = &frl_transients_static_cache();

    // Remove from static cache if it exists
    if (isset($cache[$key])) {
        unset($cache[$key]);
    }

    // Delete WordPress transient
    $result = delete_transient(frl_prefix($key));

    return $result;
}

/**
 * Shared transient cache storage.
 *
 * Provides a static cache for transients to avoid repeated calls to the
 * WordPress transient API within a single request.
 *
 * @return array Reference to the static transient cache array.
 */
function &frl_transients_static_cache()
{
    static $cache = [];
    return $cache;
}

/**
 * If key misses initial load, checks DB. Returns value & clears cache, or signals default needed.
 *
 * @param string $key Option key.
 * @param bool $bypass_cache Bypass flag.
 * @param array &$options Ref to frl_get_option\'s static [$options].
 * @return mixed Normalized value if found in DB; else, string \'__missing_option__\'.
 */
function frl_handle_missing_option_key(string $key, bool $bypass_cache, array &$options)
{
    $prefixed_key = frl_prefix($key);
    $db_value = get_option($prefixed_key, '__FRL_NOT_FOUND__');

    if ($db_value !== '__FRL_NOT_FOUND__') {
                    $default = frl_get_all_plugin_options_settings($key);
        $type = $default['type'] ?? 'text';
        $value = frl_normalize_option($db_value, $type);

        $options[$key] = $value;

        if (!$bypass_cache) {
            // Only refresh the options cache entry itself – dependent groups like
            // adminui must remain intact to avoid thrashing.
            frl_cache_clear('options', 'all_options', false);

        }
        return $value;
    }

    return '__missing_option__';
}

/**
 * Saves and returns the default for an option confirmed missing from DB.
 * Manages DB update (via frl_update_option), logging, and static cache update.
 *
 * @param string $key Option key.
 * @param bool $bypass_cache Bypass flag.
 * @param array &$options Ref to frl_get_option\'s static [$options].
 * @return mixed Normalized default value; null if no default defined.
 */
function frl_set_missing_option_default(string $key, bool $bypass_cache, array &$options)
{
    $default_option = frl_get_all_plugin_options_settings($key);
    if ($default_option === null) {
        // Log warning about unregistered option that could cause performance issues
        frl_log("WARNING: Option '{key}' not registered in any system (config-default-fields.php, FRL_OPTIONS_RUNTIME, or modules). This will cause cache bypass on every page load.", ['key' => $key]);
        return null;
    }

    $default_value = $default_option['value'];
    $autoload = $default_option['autoload'];
    $type = $default_option['type'] ?? 'text';

    // When setting a missing option to its default, do not clear the main options cache.
    // The operation that led to this (e.g., plugin reset) should handle bulk cache clearing.
    frl_update_option($key, $default_value, false, $autoload);

    // Invalidate the stale 'all_options' persistent cache so the next request picks up this newly written option.
    // $clear_cache=false prevents dependency cascade — single-key clear only.
    // Batched to once per request: when seeding multiple missing options on cold cache,
    // clearing all_options after the first write is sufficient; subsequent saves in the
    // same request would only re-clear the already-stale key.
    static $all_options_cleared = false;
    if (!$all_options_cleared) {
        frl_cache_clear('options', 'all_options', false);
        $all_options_cleared = true;
    }

    $prefixed_key = frl_prefix($key);
    $current_value = get_option($prefixed_key, null);
    $current_value = frl_normalize_option($current_value, $type);

    if ($current_value !== $default_value) {
        frl_log("frl_set_missing_option_default: Default for {key} set. Expected: {expected}. Actual: {actual}", ['key' => $key, 'expected' => $default_value, 'actual' => $current_value]);
    }

    if (!$bypass_cache) {
        $options[$key] = $default_value;
    }
    return $default_value;
}
