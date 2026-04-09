<?php

if (!defined('ABSPATH')) {
    exit;
}

class Frl_Environment_Config
{
    /**
     * Get domain configuration for current domain, with caching.
     */
    public static function get_domain_config()
    {
        $cache_key_base = isset($_SERVER['HTTP_HOST']) ? strtolower($_SERVER['HTTP_HOST']) : site_url();
        $cache_key = 'domain_config_' . md5($cache_key_base);

        return frl_cache_remember(
            Frl_Environment_Manager::CACHE_GROUP,
            $cache_key,
            function () {
                return self::build_domain_config();
            }
        );
    }

    /**
     * Builds the domain configuration from constants.
     * This contains the original logic for constructing the configuration when not found in cache.
     *
     * @return array|null The built configuration or null on failure.
     */
    public static function build_domain_config()
    {
        $raw_request_domain = isset($_SERVER['HTTP_HOST']) ? strtolower($_SERVER['HTTP_HOST']) : '';
        if (empty($raw_request_domain)) {
            $site_url_option = site_url();
            if (!empty($site_url_option)) {
                $parsed_host = parse_url($site_url_option, PHP_URL_HOST);
                if (!empty($parsed_host)) {
                    $raw_request_domain = strtolower($parsed_host);
                }
            }
        }
        if (empty($raw_request_domain)) {
            frl_log('Could not determine domain.');
            return null;
        }

        $current_domain_for_lookup = preg_replace('/^www\./', '', $raw_request_domain);
        $instance_partial_name = null;
        $matched_map_key = null; // To store the actual key from FRL_ENV_MAP

        foreach (FRL_ENV_MAP as $domain_in_map => $const_name) {
            $map_domain_for_lookup = preg_replace('/^www\./', '', $domain_in_map);
            if ($current_domain_for_lookup === $map_domain_for_lookup) {
                $instance_partial_name = $const_name;
                $matched_map_key = $domain_in_map; // Store the matched key
                break;
            }
        }

        if ($instance_partial_name === null || !is_string($instance_partial_name)) { // Check if name is valid string
            frl_log("No valid constant name mapping found for domain: '{domain}'.", ['domain' => $raw_request_domain]);
            return null;
        }
        if (!defined('FRL_ENV_DEFAULT') || !defined($instance_partial_name)) {
            frl_log("Required constants not defined (FRL_ENV_DEFAULT or {constant}).", ['constant' => $instance_partial_name]);
            return null;
        }

        $base_config = FRL_ENV_DEFAULT;
        $instance_partial_config_raw = constant($instance_partial_name);
        // Ensure fetched constants are arrays
        if (!is_array($base_config)) {
            frl_log("FRL_ENV_DEFAULT is not an array.");
            return null;
        }
        if (!is_array($instance_partial_config_raw)) {
            frl_log("Constant {constant} is not an array.", ['constant' => $instance_partial_name]);
            return null;
        }
        $instance_partial_config = $instance_partial_config_raw;

        // Determine type and get type partial config (ensure it's an array)
        $type_hint = $instance_partial_config['type'] ?? null;
        // Use substr for potentially broader compatibility and to check linter issue
        if ($type_hint === 'staging' || ($type_hint === null && (substr($instance_partial_name, -strlen('_STAGING')) === '_STAGING'))) {
            $type = 'staging';
        } else {
            $type = 'production'; // Default to production if not explicitly staging
        }

        $type_partial_config_raw = [];
        $type_const_name = '';
        if ($type === 'staging') {
            $type_const_name = 'FRL_ENV_DEFAULT_STAGING';
            $type_partial_config_raw = FRL_ENV_DEFAULT_STAGING;
        } elseif ($type === 'production') {
            $type_const_name = 'FRL_ENV_DEFAULT_PRODUCTION';
            $type_partial_config_raw = FRL_ENV_DEFAULT_PRODUCTION;
        }

        // Ensure the fetched type partial config is an array
        if (!is_array($type_partial_config_raw)) {
            frl_log("Constant {constant} is not an array.", ['constant' => $type_const_name]);
            $type_partial_config = [];
        } else {
            $type_partial_config = $type_partial_config_raw;
        }

        // Resolve single-level 'extends' inheritance from instance constant.
        // Merge order: default → type → extends template → instance (bottom wins).
        // Templates cannot themselves extend — any nested extends key is stripped.
        $extends_partial_config = [];
        $extends_const_name = $instance_partial_config['extends'] ?? null;
        if ($extends_const_name !== null) {
            if (!is_string($extends_const_name) || !defined($extends_const_name)) {
                frl_log("Environment 'extends' constant '{constant}' is not defined.", ['constant' => (string) $extends_const_name]);
            } else {
                $extends_raw = constant($extends_const_name);
                if (is_array($extends_raw)) {
                    $extends_partial_config = array_diff_key($extends_raw, ['extends' => true]);
                } else {
                    frl_log("Environment 'extends' constant '{constant}' is not an array.", ['constant' => $extends_const_name]);
                }
            }
            unset($instance_partial_config['extends']);
        }

        $final_config = self::merge_environment_configs(
            $base_config,
            $type_partial_config,
            $instance_partial_config,
            $extends_partial_config
        );
        $final_config['type'] = $type; // Ensure final type is set correctly
        $final_config['current_host'] = $raw_request_domain; // Add the current host (from request/siteurl option)
        $final_config['env_host'] = $matched_map_key;     // Add the environment host (from FRL_ENV_MAP key)
        $final_config['current_environment'] = $instance_partial_name; // Add the name of the instance-specific constant used

        return $final_config;
    }

    /**
     * Merges environment configuration arrays with specific logic for plugins.
     * Merge order (bottom wins): base → type_partial → extends_partial → instance_partial.
     *
     * @param array $extends_partial Optional template config from the 'extends' key.
     */
    public static function merge_environment_configs(array $base, array $type_partial, array $instance_partial, array $extends_partial = []): array
    {
        // Step 1: Merge associative arrays using array_replace_recursive for precedence.
        $merged = array_replace_recursive($base, $type_partial, $extends_partial, $instance_partial);

        // Step 2: Handle 'plugins' array with custom logic.
        // Ensure we start with arrays, even if keys are missing in source configs.
        $plugins_base = isset($base['plugins']) && is_array($base['plugins']) ? $base['plugins'] : ['active' => [], 'inactive' => []];
        $plugins_type = isset($type_partial['plugins']) && is_array($type_partial['plugins']) ? $type_partial['plugins'] : [];
        $plugins_extends = isset($extends_partial['plugins']) && is_array($extends_partial['plugins']) ? $extends_partial['plugins'] : [];
        $plugins_instance = isset($instance_partial['plugins']) && is_array($instance_partial['plugins']) ? $instance_partial['plugins'] : [];

        // Refined plugin logic based on potential override keys:
        $final_active = $plugins_base['active'] ?? [];
        $final_inactive = $plugins_base['inactive'] ?? [];
        if (!is_array($final_active)) $final_active = [];
        if (!is_array($final_inactive)) $final_inactive = [];

        // Apply Type Overrides
        if (isset($plugins_type['active']) && is_array($plugins_type['active'])) {
            $final_active = $plugins_type['active'];
        }
        if (isset($plugins_type['inactive']) && is_array($plugins_type['inactive'])) {
            $final_inactive = $plugins_type['inactive'];
        }
        if (frl_is_array_not_empty($plugins_type, 'active_add')) {
            $final_active = array_unique(array_merge($final_active, $plugins_type['active_add']));
        }
        if (frl_is_array_not_empty($plugins_type, 'inactive_add')) {
            $final_inactive = array_unique(array_merge($final_inactive, $plugins_type['inactive_add']));
        }

        // Apply Extends Overrides (template layer, between type and instance)
        if (isset($plugins_extends['active']) && is_array($plugins_extends['active'])) {
            $final_active = $plugins_extends['active'];
        }
        if (isset($plugins_extends['inactive']) && is_array($plugins_extends['inactive'])) {
            $final_inactive = $plugins_extends['inactive'];
        }
        if (frl_is_array_not_empty($plugins_extends, 'active_add')) {
            $final_active = array_unique(array_merge($final_active, $plugins_extends['active_add']));
        }
        if (frl_is_array_not_empty($plugins_extends, 'inactive_add')) {
            $final_inactive = array_unique(array_merge($final_inactive, $plugins_extends['inactive_add']));
        }

        // Apply Instance Overrides (highest precedence)
        if (isset($plugins_instance['active']) && is_array($plugins_instance['active'])) {
            $final_active = $plugins_instance['active'];
        }
        if (frl_is_array_not_empty($plugins_instance, 'active_add')) {
            $final_active = array_unique(array_merge($final_active, $plugins_instance['active_add']));
        }
        if (isset($plugins_instance['inactive']) && is_array($plugins_instance['inactive'])) {
            $final_inactive = $plugins_instance['inactive'];
        }
        if (frl_is_array_not_empty($plugins_instance, 'inactive_add')) {
            $final_inactive = array_unique(array_merge($final_inactive, $plugins_instance['inactive_add']));
        }

        // Ensure consistency and final types
        // First get unique elements in each potentially modified list
        $final_active_temp = array_values(array_unique(is_array($final_active) ? $final_active : []));
        $final_inactive_temp = array_values(array_unique(is_array($final_inactive) ? $final_inactive : []));

        // Remove active plugins from the inactive list
        $final_inactive = array_diff($final_inactive_temp, $final_active_temp);
        // Remove inactive plugins from the active list
        $final_active = array_diff($final_active_temp, $final_inactive_temp);

        // Final cleanup (re-index) and sorting
        $final_active = array_values($final_active);
        $final_inactive = array_values($final_inactive);
        sort($final_active);
        sort($final_inactive);

        $merged['plugins'] = [
            'active' => $final_active,
            'inactive' => $final_inactive,
        ];

        return $merged;
    }
}
