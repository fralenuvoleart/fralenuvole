<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-environment-config.php';
require_once __DIR__ . '/class-environment-files.php';

class Frl_Environment_Applier
{
    /**
     * Optionally clear website transients once per destination host.
     */
    public static function clear_website_transients_if_needed($config, $dest_host)
    {
        $transients_status = 'skipped';
        $transients_deleted = 0;

        if (
            defined('FRL_ENV_CLEAR_WEBSITE_TRANSIENTS') && FRL_ENV_CLEAR_WEBSITE_TRANSIENTS // @phpstan-ignore-line alwaysTrue
            && frl_has_access()
            && !empty($dest_host)
        ) {
            $res = frl_cache_remember(
                Frl_Environment_Manager::CACHE_GROUP,
                'transients_cleared_for_' . $dest_host,
                function () {
                    $stats = frl_cache_clear('website_transients');
                    $deleted = (is_array($stats) && isset($stats['transients'])) ? (int)$stats['transients'] : 0;
                    return [
                        'transients' => $deleted,
                        'status' => (is_array($stats) && isset($stats['transients'])) ? 'success' : 'failed',
                        'ts' => time(),
                    ];
                },
                YEAR_IN_SECONDS
            );
            if (is_array($res)) {
                $transients_deleted = isset($res['transients']) ? (int)$res['transients'] : 0;
                $transients_status = $res['status'] ?? 'success';
            }
        }

        return [
            'transients_deleted' => $transients_deleted,
            'transients_status' => $transients_status,
        ];
    }
    /**
     * Apply WordPress options from the environment configuration
     */
    public static function apply_wordpress_options($config, &$results, $force)
    {
        if (!$config) {
            return;
        }

        $target_host = $config['current_host'] ?? $config['env_host'] ?? null;

        if ($target_host) {
            $expected_siteurl = 'https://' . $target_host;
            $expected_home    = 'https://' . $target_host;

            $current_siteurl = site_url();
            $current_home = home_url();

            if ($current_siteurl !== $expected_siteurl) {
                update_option('siteurl', $expected_siteurl);
                $results['wp_options']['updated'][] = 'siteurl';
            } else {
                $results['wp_options']['skipped'][] = 'siteurl';
            }

            if ($current_home !== $expected_home) {
                update_option('home', $expected_home);
                $results['wp_options']['updated'][] = 'home';
            } else {
                $results['wp_options']['skipped'][] = 'home';
            }
        }

        if (empty($config['wp_options'])) {
            return;
        }

        foreach ($config['wp_options'] as $option_name => $value) {
            $current_value = get_option($option_name);

            if ($current_value != $value) {
                update_option($option_name, $value);
                $results['wp_options']['updated'][] = $option_name;
            } else {
                $results['wp_options']['skipped'][] = $option_name;
            }
        }
    }

    /**
     * Apply plugin options from domain configuration
     */
    public static function apply_plugin_options($config, &$results, $force_mode = false)
    {
        if (!$config) {
            return;
        }

        $clear_cache_on_update = !$force_mode;
        $any_change = false;

        if (frl_is_array_not_empty($config, 'plugin_options')) {
            $ignored_options = frl_get_option(Frl_Environment_Manager::IGNORE_OPTIONS_KEY) ?? [];
            $active_file_options = Frl_Environment_Files::get_file_options_keys();

            foreach ($config['plugin_options'] as $key => $value_from_config) {
                if (is_array($ignored_options) && in_array($key, $ignored_options)) {
                    if (!isset($results['plugin_options']['ignored']) || !in_array($key, $results['plugin_options']['ignored'])) {
                        if (!isset($results['plugin_options']['ignored'])) {
                            $results['plugin_options']['ignored'] = [];
                        }
                        $results['plugin_options']['ignored'][] = $key;
                    }
                    continue;
                }

                $old_value_for_results = frl_get_option($key, true);

                if ($value_from_config === 'file') {
                    if (in_array($key, $active_file_options)) {
                        $raw_content = Frl_Environment_Files::load_environment_file($key);

                        if ($raw_content !== null) {
                            // Defer cache clearing for performance in loops
                            frl_update_option($key, $raw_content, false);
                            if ($old_value_for_results !== $raw_content) {
                                $any_change = true;
                                if (!isset($results['plugin_options']['file_loaded'])) {
                                    $results['plugin_options']['file_loaded'] = [];
                                }
                                array_push($results['plugin_options']['file_loaded'], $key);
                            }
                        } else {
                            frl_update_option($key, '', false);
                            if ($old_value_for_results !== '') {
                                $any_change = true;
                                if (!isset($results['plugin_options']['file_missing'])) {
                                    $results['plugin_options']['file_missing'] = [];
                                }
                                array_push($results['plugin_options']['file_missing'], $key);
                            }
                        }
                    } else {
                        frl_update_option($key, '', false);
                        if ($old_value_for_results !== '') {
                            $any_change = true;
                            if (!isset($results['plugin_options']['error'])) {
                                $results['plugin_options']['error'] = [];
                            }
                            array_push($results['plugin_options']['error'], $key . ' (misconfigured as \'file\', set to empty)');
                        }
                    }
                } else {
                    $processed_value = $value_from_config;
                    if (is_bool($value_from_config)) {
                        $processed_value = $value_from_config ? '1' : '0';
                    } else if ($value_from_config === '1' || $value_from_config === 1) {
                        $processed_value = '1';
                    } else if ($value_from_config === '0' || $value_from_config === 0) {
                        $processed_value = '0';
                    }
                    frl_update_option($key, $processed_value, false);
                    if ($old_value_for_results !== $processed_value) {
                        $any_change = true;
                        if (!isset($results['plugin_options']['updated'])) {
                            $results['plugin_options']['updated'] = [];
                        }
                        array_push($results['plugin_options']['updated'], $key);
                    }
                }
            }
        }

        // Perform a single cache clear at the end if any changes were made and clearing is requested
        if ($any_change && $clear_cache_on_update) {
            frl_cache_clear('options');
        }
    }

    /**
     * Manage module activation states based on environment config
     */
    public static function apply_modules_options($config, &$results, $force_mode = false)
    {
        if (!$config || empty($config['modules'])) {
            return;
        }

        $clear_cache_on_update = !$force_mode;
        $any_change = false;

        foreach ($config['modules'] as $module => $should_be_active) {
            $option_name = 'module_' . $module;
            $current_status_raw = frl_get_option($option_name);

            $target_status_bool = (bool) $should_be_active;
            $current_status_bool = filter_var($current_status_raw, FILTER_VALIDATE_BOOLEAN);

            $expected_raw_value = $target_status_bool ? '1' : '0';

            if ($target_status_bool !== $current_status_bool || (string)$current_status_raw !== $expected_raw_value) {
                // Defer cache clearing for performance
                frl_update_option($option_name, $expected_raw_value, false);
                $any_change = true;

                if ($target_status_bool) {
                    if (!isset($results['modules']['activated'])) $results['modules']['activated'] = [];
                    $results['modules']['activated'][] = $module;
                } else {
                    if (!isset($results['modules']['deactivated'])) $results['modules']['deactivated'] = [];
                    $results['modules']['deactivated'][] = $module;
                }
            } else {
                if (!isset($results['modules']['no_change'])) $results['modules']['no_change'] = [];
                $results['modules']['no_change'][] = $module;
            }
        }

        // Perform a single cache clear at the end if any changes were made and clearing is requested
        if ($any_change && $clear_cache_on_update) {
            frl_cache_clear('options');
        }
    }
}
