<?php

/**
 * Fralenuvole
 * functions-modules.php - Modules functions
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get available module keys from environment configuration
 * This is a lightweight helper that only returns module keys without file operations
 * Used to avoid circular dependencies in configuration building
 *
 * @return array|false Array of module keys or false if no modules available
 */
function frl_modules_get_keys()
{
    // Use the module list from the base default configuration
    if (!isset(FRL_ENV_DEFAULT['modules']) || !is_array(FRL_ENV_DEFAULT['modules'])) {
        frl_log('Modules Error: FRL_ENV_DEFAULT["modules"] is not defined or not an array.');
        return false; // Cannot proceed without the module list
    }

    $available_modules = FRL_ENV_DEFAULT['modules'];


    // Return just the module keys (no expensive file operations)
    $module_keys = array_keys($available_modules);
    return empty($module_keys) ? false : $module_keys;
}

/**
 * Retrieves key metadata for all available modules.
 *
 * For each module, this includes its name, description (extracted from file headers),
 * and the path to its main PHP file. This information is typically used to list
 * modules in the UI or to understand the basic properties of each module.
 *
 * @return array An associative array where keys are module keys, and values are
 *         arrays containing 'name', 'description', and 'file'.
 *         Returns false if no valid modules are found.
 * @see frl_modules_module_get_header_data()
 */
function frl_modules_get_all_metadata()
{
    return frl_cache_remember(
        'adminui',
        'modules_default_metadata',
        function () {
            $available_modules = frl_modules_get_keys();

            $valid_modules = array();

            // Iterate through the KEYS (module keys) from the default config
            if (frl_is_array_not_empty($available_modules)) { // Ensure $available_modules is an array
                foreach ($available_modules as $module_key) {
                    $module_file = frl_modules_module_get_file_path($module_key);
                    if ($module_file) {
                        $header_data = frl_modules_module_get_header_data($module_file);
                        $valid_modules[$module_key] = [
                            'name' => $header_data['name'],
                            'description' => $header_data['description'],
                            'file' => $module_file,
                        ];
                    } else {
                        frl_log('Module file not found for key: {key}', ['key' => $module_key]);
                    }
                }
            }

            return empty($valid_modules) ? false : $valid_modules;
        }
    );
}

/**
 * Load option metadata from modules (returns processed option defaults with value, autoload, type)
 * @return array Option metadata keyed by option ID
 */
function frl_modules_load_options_defaults()
{
    return frl_cache_remember(
        'options',
        'modules_options_defaults',
        function () {
            $computed_defaults = [];

            foreach (frl_modules_get_combined_data_iterator() as $module_key => $data) {
                // $module_info = $data['info']; // Available if needed
                $module_config_options = $data['fields'];

                // Add standard module enable/disable option for this module
                $option_key_toggle = 'module_' . $module_key;
                $computed_defaults[$option_key_toggle] = [
                    'value'    => frl_normalize_option(0, 'checkbox'),
                    'autoload' => frl_normalize_autoload('yes'),
                    'type'     => 'checkbox'
                ];

                // Add this module's specific configuration options
                foreach ($module_config_options as $option_key => $option_config) {
                    if (!isset($option_config['type'])) {
                        continue;
                    }

                    $field_type    = $option_config['type'];
                    $field_default = $option_config['default'] ?? null;
                    $field_default = frl_normalize_option($field_default, $field_type);
                    $autoload      = frl_normalize_autoload($option_config['autoload'] ?? 'yes');

                    $computed_defaults[$option_key] = [
                        'value'    => $field_default,
                        'autoload' => $autoload,
                        'type'     => $field_type
                    ];
                }
            }
            return $computed_defaults;
        }
    );
}


/**
 * Load field definitions from modules (returns field configurations for admin UI)
 * @return array Field definition arrays with id, type, default, label, description, etc.
 */
function frl_modules_load_options_fields()
{
    return frl_cache_remember(
        'adminui',
        'modules_options_fields',
        function () {
            $final_module_fields = [];
            $module_toggle_fields = [];
            $module_specific_settings_fields = [];

            // Add section title first
            $final_module_fields[] = [
                'id'          => 'section_title_modules',
                'label'       => 'Additional Modules',
                'description' => 'Enable and configure plugin modules',
                'type'        => 'section_title',
                'default'     => '',
                'section'     => FRL_MODULES_SECTION, // Ensure it has a section
                'restricted'  => false,
                'autoload'    => 'no'
            ];

            foreach (frl_modules_get_combined_data_iterator() as $module_key => $data) {
                $module_info = $data['info'];
                $module_specific_config = $data['fields'];

                // Collect module toggle field for the current module
                $module_toggle_fields[] = [
                    'id'          => 'module_' . $module_key,
                    'label'       => $module_info['name'],
                    'description' => $module_info['description'],
                    'type'        => 'checkbox',
                    'default'     => 0,
                    'section'     => FRL_MODULES_SECTION, // Group under section
                    'restricted'  => true,
                    'autoload'    => 'yes'
                ];

                // Collect specific settings fields for the current module
                foreach ($module_specific_config as $field_id => $field_config) {
                    $field_config['id']      = $field_id; // Add the field_id from the array key
                    $field_config['section'] = $field_config['section'] ?? FRL_MODULES_SECTION; // Ensure 'section' is set
                    $module_specific_settings_fields[] = $field_config;
                }
            }

            // Merge them in the desired order: Title (already added), Toggles, Specific Settings
            $final_module_fields = array_merge($final_module_fields, $module_toggle_fields, $module_specific_settings_fields);

            return $final_module_fields;
        }
    );
}

/**
 * Iterates through modules, yielding combined metadata and default fields for each.
 *
 * Relies on `frl_modules_get_all_metadata()` and `frl_modules_module_get_default_config()`which are expected to be cached
 *
 * @return Generator Yields [module_key => ['key' => string, 'info' => array, 'fields' => array]]
 *    'key'   : The module's unique key.
 *    'info'  : General module metadata (name, description, file).
 *    'fields': Module's default field configurations.
 *    Yields nothing if no modules are found.
 */
function frl_modules_get_combined_data_iterator()
{
    // Fetch metadata for all available modules.
    // This function call is expected to be internally cached per request.
    $all_modules_metadata = frl_modules_get_all_metadata();

    if (empty($all_modules_metadata)) {
        // If there's no module metadata, there's nothing to process or yield.
        return;
    }

    // Iterate through each module identified by its metadata.
    foreach ($all_modules_metadata as $module_key => $module_info) {
        // For each module, fetch its specific default fields.
        // This function call is also expected to be internally cached per request.
        $module_default_fields = frl_modules_module_get_default_config($module_key);

        // Yield the combined data for the current module.
        yield $module_key => [
            'key'    => $module_key,         // The unique key for the module
            'info'   => $module_info,        // General metadata (name, description, etc.)
            'fields' => $module_default_fields // Associated default fields/settings
        ];
    }
}

/**
 * INTERNAL HELPER: Load raw config variables from a specific module's config file.
 *
 * @param string $module_key The key of the module.
 * @return array The raw options/fields array from the module's config, or empty array.
 */
function frl_modules_module_get_default_config($module_key)
{
    static $loaded_module_configs = [];

    // Check if we already loaded and processed this module's config in the current request.
    if (array_key_exists($module_key, $loaded_module_configs)) {
        return $loaded_module_configs[$module_key];
    }

    // Get the validated path to the module's config file.
    // This helper also handles static caching for the file_exists check.
    $module_config_file_path = frl_modules_module_get_config_file_path($module_key);

    if ($module_config_file_path) {
        // File exists, proceed to include and extract variables.
        $config_data = (function () use ($module_config_file_path, $module_key) {
            include $module_config_file_path; // Use the validated path

            $var_name = frl_prefix($module_key . '_default_fields');
            if (frl_is_array_not_empty($$var_name)) {
                return $$var_name;
            }
            return [];
        })();

        $loaded_module_configs[$module_key] = $config_data;
        return $config_data;
    }

    // Config file does not exist or module key was invalid.
    $loaded_module_configs[$module_key] = []; // Cache empty result.
    return [];
}

/**
 * Retrieves specific header data from a module file.
 *
 * @param string $module_file_path Absolute path to the module PHP file. The file is expected to exist.
 * @return array An array containing 'name' and 'description' from the module header,
 *               or default/fallback values if not found.
 */
function frl_modules_module_get_header_data($module_file_path)
{
    // The $module_file_path is already expected to be valid and exist,
    // as it comes from frl_modules_module_get_file_path()

    // Define the headers we want to extract
    $default_headers = array(
        'ModuleName' => 'Module Name',
        'Description' => 'Description',
    );

    $module_data = get_file_data($module_file_path, $default_headers, 'module');

    // Prepare the return array with fallbacks
    $return_data = [];

    // Fallback for Module Name
    if (!empty($module_data['ModuleName'])) {
        $return_data['name'] = $module_data['ModuleName'];
    } else {
        // Fallback: try to generate a name from the file path if Module Name is missing
        $file_name = basename($module_file_path, '.php');
        $return_data['name'] = frl_format_file_name($file_name) . ' Module';
    }

    // Fallback for Description
    if (!empty($module_data['Description'])) {
        $return_data['description'] = $module_data['Description'];
    } else {
        $return_data['description'] = 'Enable the ' . $return_data['name'] . '.'; // Generic description
    }

    return $return_data;
}

/**
 * Gets the full path to a module's main PHP file if it exists.
 *
 * The main file is assumed to be named after the module key and located
 * in a directory also named after the module key (e.g., modules/my-module/my-module.php).
 *
 * @param string $module_key The key of the module (e.g., 'my-module').
 * @return string|false The full, validated file path, or false if the module key is invalid
 *                      or the file does not exist.
 */
function frl_modules_module_get_file_path($module_key)
{
    static $main_file_paths = [];

    if (array_key_exists($module_key, $main_file_paths)) {
        return $main_file_paths[$module_key];
    }

    if (empty($module_key) || !is_string($module_key) || str_contains($module_key, '/') || str_contains($module_key, '\\')) {
        // Basic validation for module key to prevent path traversal issues.
        // Module keys should be simple directory/file names.
        frl_log('Modules: Invalid module key provided for frl_modules_module_get_file_path: {key}', ['key' => $module_key]);
        $main_file_paths[$module_key] = false;
        return false;
    }

    $module_file = FRL_MODULES_DIR_PATH . $module_key . '/' . $module_key . '.php';

    if (file_exists($module_file)) {
        $main_file_paths[$module_key] = $module_file;
        return $module_file;
    }

    $main_file_paths[$module_key] = false;
    return false;
}

/**
 * Gets the full path to a module's specific configuration file (config-KEY.php) if it exists.
 *
 * The config file is assumed to be named 'config-fields-MODULE_KEY.php' and located
 * in the module's directory (e.g. modules/mymodule/config-fields-mymodule.php).
 *
 * @param string $module_key The key of the module (e.g., 'mymodule').
 * @return string|false The full, validated file path to the config file, or false if the module key
 *                      is invalid or the config file does not exist.
 */
function frl_modules_module_get_config_file_path($module_key)
{
    static $config_file_paths = [];

    if (array_key_exists($module_key, $config_file_paths)) {
        return $config_file_paths[$module_key];
    }

    if (empty($module_key) || !is_string($module_key) || str_contains($module_key, '/') || str_contains($module_key, '\\')) {
        // Basic validation for module key to prevent path traversal issues.
        frl_log('Modules: Invalid module key provided in frl_modules_module_get_config_file_path for: {key}', ['key' => $module_key]);
        $config_file_paths[$module_key] = false;
        return false;
    }

    $config_file = FRL_MODULES_DIR_PATH . $module_key . '/config-options-' . $module_key . '.php';

    if (file_exists($config_file)) {
        $config_file_paths[$module_key] = $config_file;
        return $config_file;
    }

    $config_file_paths[$module_key] = false;
    return false;
}
