<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-environment-config.php';

class Frl_Environment_Files
{
    /**
     * Get list of option keys configured as 'file' that have a physical file for the current env prefix.
     */
    public static function get_file_options_keys()
    {
        $config = Frl_Environment_Config::get_domain_config();
        if ($config === null) {
            return [];
        }

        if (empty($config['prefix'])) {
            frl_log('Frl_Environment_Files::get_file_options_keys called with a config missing a prefix.');
            return [];
        }

        $cache_key =  $config['prefix'] . '_file_option_keys';

        return frl_cache_remember(
            Frl_Environment_Manager::CACHE_GROUP,
            $cache_key,
            function () use ($config) {
                $active_file_options = [];

                if (!frl_is_array_not_empty($config, 'plugin_options')) {
                    return $active_file_options;
                }

                foreach ($config['plugin_options'] as $key => $value) {
                    if ($value === 'file') {
                        $filename = $config['prefix'] . '_' . $key . '.php';
                        if (file_exists(Frl_Environment_Manager::ENV_FILES_PATH . $filename)) {
                            $active_file_options[] = $key;
                        }
                    }
                }
                return array_unique($active_file_options);
            }
        );
    }

    /**
     * Load raw file content from environment includes, with caching.
     */
    public static function load_environment_file($option_name)
    {
        $config = Frl_Environment_Config::get_domain_config();
        if ($config === null || empty($config['prefix'])) {
            return null;
        }

        $cache_key = $config['prefix'] . '_file_option_' . $option_name;

        return frl_cache_remember(
            Frl_Environment_Manager::CACHE_GROUP,
            $cache_key,
            function () use ($config, $option_name) {
                $filename = $config['prefix'] . '_' . $option_name . '.php';
                $filepath = Frl_Environment_Manager::ENV_FILES_PATH . $filename;

                if (!file_exists($filepath)) {
                    return null;
                }

                $content = file_get_contents($filepath);

                if (str_contains($content, '<?php')) {
                    $current_error_level = error_reporting();
                    error_reporting(0);
                    set_error_handler(function () { return true; }, E_ALL);

                    $has_syntax_error = false;
                    $error_message = '';

                    restore_error_handler();
                    error_reporting($current_error_level);

                    if ($has_syntax_error) {
                        frl_log('PHP syntax error in environment file: {file} - {error}', ['file' => $filepath, 'error' => $error_message]);
                    }
                }

                return $content;
            }
        );
    }
}
