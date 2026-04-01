<?php

if (!defined('ABSPATH')) {
    exit;
}

class Frl_Environment_Plugin_Manager
{
    /**
     * Apply plugins activation state
     */
    public static function apply_plugins_activation_status($config, &$results)
    {
        if (!$config || empty($config['plugins'])) {
            return;
        }

        $ignored_plugins = frl_get_option(Frl_Environment_Manager::IGNORE_PLUGINS_KEY) ?? [];

        if (!empty($config['plugins']['active'])) {
            self::process_plugins_activation_status(
                $config['plugins']['active'],
                false,
                $ignored_plugins,
                $results
            );
        }
        if (!empty($config['plugins']['inactive'])) {
            self::process_plugins_activation_status(
                $config['plugins']['inactive'],
                true,
                $ignored_plugins,
                $results
            );
        }
    }

    /**
     * Process plugins activation/deactivation based on environment
     */
    public static function process_plugins_activation_status($plugins, $should_deactivate, $ignored_plugins, &$results)
    {
        if (empty($plugins)) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        remove_action('activated_plugin', [Frl_Environment_Manager::class, 'track_plugins_activation_status'], 10);
        remove_action('deactivated_plugin', [Frl_Environment_Manager::class, 'track_plugins_activation_status'], 10);

        $plugins_to_change = [];

        foreach ($plugins as $plugin) {
            if (is_array($ignored_plugins) && in_array($plugin, $ignored_plugins)) {
                if (!in_array($plugin, $results['plugins']['ignored'])) {
                    $results['plugins']['ignored'][] = $plugin;
                }
                continue;
            }

            $is_active = is_plugin_active($plugin);

            if (($should_deactivate && $is_active) || (!$should_deactivate && !$is_active)) {
                $plugins_to_change[] = $plugin;
            } else {
                if (!in_array($plugin, $results['plugins']['no_change'])) {
                    $results['plugins']['no_change'][] = $plugin;
                }
            }
        }

        if (!empty($plugins_to_change)) {
            if ($should_deactivate) {
                foreach ($plugins_to_change as $plugin) {
                    deactivate_plugins([$plugin], false);
                    if (is_plugin_active($plugin)) {
                        $results['plugins']['update_error'][] = "{$plugin}: failed to deactivate";
                    } else {
                        $results['plugins']['deactivated'][] = $plugin;
                    }
                }
            } else {
                foreach ($plugins_to_change as $plugin) {
                    $result = activate_plugin($plugin, '', false, false);
                    if (is_wp_error($result)) {
                        $results['plugins']['update_error'][] = "{$plugin}: " . $result->get_error_message();
                    } else {
                        $results['plugins']['activated'][] = $plugin;
                    }
                }
            }
        }

        frl_hook_add('action', 'activated_plugin', [Frl_Environment_Manager::class, 'track_plugins_activation_status'], 10, 1);
        frl_hook_add('action', 'deactivated_plugin', [Frl_Environment_Manager::class, 'track_plugins_activation_status'], 10, 1);
    }
}
