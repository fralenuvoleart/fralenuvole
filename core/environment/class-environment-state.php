<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-environment-utils.php';
require_once __DIR__ . '/class-environment-config.php';

class Frl_Environment_State
{
    use Frl_Environment_Host_Normalizer;

    /**
     * Get current state snapshot.
     */
    public static function get_current_state($include_timestamp = false)
    {
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
        $site_url = get_site_url();
        $home_url = get_home_url();

        $state = [
            'host' => $host,
            'siteurl' => $site_url,
            'home' => $home_url,
            'hash' => md5(($host ?: 'localhost') . $site_url)
        ];

        if ($include_timestamp) {
            $state['last_updated'] = current_time('mysql');
        }

        return $state;
    }

    /**
     * Compare stored snapshot hosts vs current request/runtime URL hosts.
     */
    public static function environment_host_changed($stored_state)
    {
        $current_host_norm = self::normalize_host_value($_SERVER['HTTP_HOST'] ?? '');
        $current_siteurl_host = self::extract_host_from_url(get_site_url());
        $current_home_host = self::extract_host_from_url(get_home_url());

        $stored_host_norm = self::normalize_host_value(isset($stored_state['host']) ? $stored_state['host'] : '');
        $stored_siteurl_host = self::extract_host_from_url(isset($stored_state['siteurl']) ? $stored_state['siteurl'] : '');
        $stored_home_host = self::extract_host_from_url(isset($stored_state['home']) ? $stored_state['home'] : '');

        return $stored_host_norm !== $current_host_norm ||
            $stored_siteurl_host !== $current_siteurl_host ||
            $stored_home_host !== $current_home_host;
    }

    /**
     * Check if environment state has changed (host-only), with throttle handled by caller.
     */
    public static function check_environment_state()
    {
        static $result = false;

        if (!isset($_SERVER['HTTP_HOST']) || empty($_SERVER['HTTP_HOST'])) {
            return $result = false;
        }

        $stored_state = frl_cache_get(Frl_Environment_Manager::CACHE_GROUP, Frl_Environment_Manager::CACHE_KEY) ?:
            frl_get_option(Frl_Environment_Manager::ENVIRONMENT_STATE);

        $state_changed = !$stored_state || self::environment_host_changed($stored_state);

        if (!$state_changed) {
            $config = Frl_Environment_Config::get_domain_config();
            if ($config && isset($config['type']) && $config['type'] === 'staging') {
                $current_blog_public = get_option('blog_public');
                if ($current_blog_public != 0) {
                    $state_changed = true;
                }
            }
        }

        if ($state_changed) {
            $current_state = self::get_current_state();
            frl_update_option(Frl_Environment_Manager::ENVIRONMENT_STATE, $current_state, false);
            frl_cache_set(Frl_Environment_Manager::CACHE_GROUP, Frl_Environment_Manager::CACHE_KEY, $current_state);
            return $result = true;
        }

        return $result = false;
    }
}
