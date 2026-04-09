<?php

/**
 * Environment Manager Class
 * @package FRL
 */

if (!defined('ABSPATH')) {
    exit;
}

// Host normalization utilities for environment comparisons
require_once __DIR__ . '/class-environment-utils.php';
require_once __DIR__ . '/class-environment-config.php';
require_once __DIR__ . '/class-environment-state.php';
require_once __DIR__ . '/class-environment-monitor.php';
require_once __DIR__ . '/class-environment-applier.php';
require_once __DIR__ . '/class-environment-plugin-manager.php';

class Frl_Environment_Manager
{
    use Frl_Environment_Host_Normalizer;
    const PREFIX = FRL_PREFIX . '_';
    const CACHE_GROUP = FRL_ENV_CACHE_GROUP;
    const CACHE_KEY = FRL_ENV_CACHE_KEY;
    const ENVIRONMENT_STATE = FRL_ENV_CACHE_GROUP . '_' . FRL_ENV_CACHE_KEY;

    const CACHE_KEY_LAST_CHECK = 'last_check';

    const IGNORE_PLUGINS_KEY = FRL_ENV_CACHE_GROUP . '_' . FRL_IGNORE_PLUGINS_KEY;
    const IGNORE_OPTIONS_KEY = FRL_ENV_CACHE_GROUP . '_' . FRL_IGNORE_OPTIONS_KEY;

    const ENV_FILES_PATH = FRL_DIR_PATH . FRL_ENV_FILES_PATH;

    /**
     * Initialize environment manager
     */
    public static function init()
    {
        if (!frl_is_already_running(__METHOD__)) {
            // Check URLs for environment with proper access control
            // Note: frl_has_access() now returns true if FRL_MODE === 'migrate'
            if (frl_has_access()) {
                // If force reload flag is set (now using stable cookie-based check)
                if (frl_check_and_clear_force_reload_flag()) {
                    // Add script to force reload
                    add_action('wp_footer',
                        function () {
                            echo '<script>window.location.reload(true);</script>';
                        },
                        99999,
                        0);
                }

                // Trigger URL check for admins or migrate mode
                Frl_Environment_Monitor::check_urls();

                // Set up option tracking only for admins
                Frl_Environment_Monitor::setup_plugin_options_tracking();
            }

            // Register hooks needed even if initialized? Revisit this maybe.
            add_action('admin_bar_menu',
                [self::class, 'add_environment_switcher'],
                9999,
                1);
            add_action('activated_plugin',
                [self::class, 'track_plugins_activation_status'],
                10,
                1);
            add_action('deactivated_plugin',
                [self::class, 'track_plugins_activation_status'],
                10,
                1);
        }
    }

    /**
     * Get current state
     * @param bool $include_timestamp Whether to include last_updated timestamp
     * @return array Current state
     */
    private static function get_current_state($include_timestamp = false)
    {
        return Frl_Environment_State::get_current_state($include_timestamp);
    }

    /**
     * Determine whether stored state hosts differ from current request
     * and runtime URL hosts. Ignores scheme, port, and path.
     */
    private static function environment_host_changed($stored_state)
    {
        return Frl_Environment_State::environment_host_changed($stored_state);
    }

    /**
     * Check if environment state has changed.
     *
     * Detection rule (explicit): Only host changes across request/siteurl/home
     * trigger a state change. Scheme, port, and path differences are ignored.
     * This focuses on main domain vs subdomain changes.
     */
    private static function check_environment_state()
    {
        static $result = false;
        if (frl_is_already_running(__METHOD__)) {
            return $result;
        }

        $stored_state = frl_cache_get(self::CACHE_GROUP, self::CACHE_KEY) ?:
            frl_get_option(self::ENVIRONMENT_STATE);
        $from_options = $stored_state && !frl_cache_get(self::CACHE_GROUP, self::CACHE_KEY);

        $state_changed = Frl_Environment_State::check_environment_state();

        if ($state_changed) {
            return $result = true;
        } else if ($from_options && $stored_state) {
            frl_cache_set(self::CACHE_GROUP, self::CACHE_KEY, $stored_state);
        }

        return $result = false;
    }

    /**
     * Check and enforce environment-specific settings
     *
     * @param bool $force Whether to force applying settings regardless of state change
     */
    public static function enforce_environment_settings($force = false)
    {
        // Restrict environment enforcement to admins only (or migrate mode via updated frl_has_access)
        if (!frl_has_access()) {
            return null;
        }

        // Skip if we're in an admin action request but not forcing
        if (!$force && isset($_GET[FRL_PREFIX . '_action'])) {
            return null;
        }

        // Skip if this is a redirect after an action
        if (!$force && isset($_SERVER['HTTP_REFERER'])) {
            $referer = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_QUERY);
            if ($referer && str_contains($referer, FRL_PREFIX . '_action=reset_environment')) {
                return null;
            }
        }

        // Get current action
        $current_action = current_action();
        $doing_action = doing_action(FRL_PREFIX . '_action');

        // If this is an init check but we're in the middle of processing a reset action, skip it
        if ($current_action === 'init' && $doing_action) {
            return null;
        }

        // Prevent recursion/re-entry within the same request unless forced
        if (frl_is_already_running(__METHOD__) || (!$force && frl_is_already_running(__CLASS__))) {
            return null;
        }

        $results = [
            'wp_options' => ['updated' => [], 'skipped' => []],
            'plugin_options' => ['updated' => [], 'file_loaded' => [], 'file_missing' => [], 'error' => [], 'ignored' => []],
            'plugins' => ['activated' => [], 'deactivated' => [], 'ignored' => [], 'no_change' => [], 'update_error' => []],
            'modules' => ['activated' => [], 'deactivated' => [], 'no_change' => []],
            'config_found' => false,
            'message' => '',
        ];

        // Get domain config - this must happen before throttle logic if throttle depends on config (it doesn't currently)
        // and before result population using config.
        $config = self::get_domain_config();

        // If config is null, log the issue (already logged in get_domain_config) and exit early.
        if ($config === null) {
            $results['message'] = __('Could not determine environment configuration.', FRL_PREFIX);
            frl_is_already_running(__METHOD__, true); // Reset recursion flag
            return $force ? $results : null; // Return results if forced
        }

        // Populate results that depend on config *after* checking config is not null.
        $results['config_found'] = true;
        $results['environment_type'] = $config['type'] ?? 'unknown';
        $results['environment_prefix'] = $config['prefix'] ?? 'unknown';

        $last_check_timestamp = frl_cache_get(self::CACHE_GROUP, self::CACHE_KEY_LAST_CHECK);

        // Check if environment state has changed before throttling
        $state_changed = self::check_environment_state();

        // Different throttle periods based on user type and force flag
        // Note: frl_has_access() returns true for migrate mode, so we rely on explicit check for throttle bypass if needed,
        // but since migrate mode implies manual intervention, we can treat it as 'admin' (60s) or force it.
        // If we want zero throttle for migrate, we check FRL_MODE explicitly here or just let the force flag handle it if passed.
        // However, checking FRL_MODE is safer for the specific migrate use case.
        $is_migrate = defined('FRL_MODE') && FRL_MODE === 'migrate';
        $throttle_seconds = ($force || $is_migrate) ? 0 : (frl_has_access() ? 60 : 300);

        // Apply throttle only when not forced AND no state change
        if (!$force && !$is_migrate && !$state_changed && $throttle_seconds > 0 && $last_check_timestamp && (time() - $last_check_timestamp < $throttle_seconds)) {
            frl_is_already_running(__METHOD__, true); // Reset recursion flag
            return null;
        }

        // Update last check time for non-forced path (regardless of state change). Safe and idempotent.
        if (!$force && !$is_migrate) {
            frl_cache_set(self::CACHE_GROUP, self::CACHE_KEY_LAST_CHECK, time());
        }

        // Apply settings if state changed or force is true
        if ($state_changed || $force || $is_migrate) {
            // Prepare context and results for a single synthetic log at the end
            $origin_host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
            $dest_host = $config['env_host'] ?? ($config['current_host'] ?? '');
            $dest_siteurl = !empty($dest_host) ? ('https://' . $dest_host) : '';
            $dest_home = $dest_siteurl;

            // Website transients purge moved to final state_changed block after operations

            // Apply WordPress options from config
            Frl_Environment_Applier::apply_wordpress_options($config, $results, $force); // Pass force

            // Apply domain-specific configuration
            Frl_Environment_Applier::apply_plugin_options($config, $results, $force); // Pass force

            // Apply plugin management
            Frl_Environment_Plugin_Manager::apply_plugins_activation_status($config, $results);

            // Apply module management
            Frl_Environment_Applier::apply_modules_options($config, $results, $force); // Pass force

            // After all environment changes, update the timestamp
            // Use full purge to ensure clean slate for all environment changes
            frl_cache_clear('all');

            $current_state = self::get_current_state(true);

            // Store final state with timestamp
            frl_update_option(self::ENVIRONMENT_STATE, $current_state, false);

            frl_cache_set(self::CACHE_GROUP, self::CACHE_KEY, $current_state);

            // Clear throttle on state change or force
            frl_cache_clear(self::CACHE_GROUP, self::CACHE_KEY_LAST_CHECK);

            $results['message'] = __('Environment settings applied.', FRL_PREFIX);

            // Finalize: optional website transients purge and single log at the end of the routine
            if (($state_changed && !$force) || $is_migrate) {
                $transients = Frl_Environment_Applier::clear_website_transients_if_needed($config, $dest_host);

                Frl_Environment_Utils::log_environment_change(
                    $config,
                    $origin_host,
                    $dest_host,
                    $dest_siteurl,
                    $dest_home,
                    $transients
                );

                // Redirect to plugin admin page if user has access (and not already there/doing action)
                // This helps confirm the migration visually.
                if (frl_has_access()) {
                     nocache_headers();
                     frl_safe_redirect(FRL_PLUGIN_URL);
                }
            }
        } else {
            $results['message'] = __('Environment settings check skipped (no state change detected).', FRL_PREFIX);
        }

        // Mark as complete
        if ($force) {
            // Reset the has_run flag when forcing
            frl_is_already_running(__CLASS__, true);
        }

        // Reset method recursion flag
        frl_is_already_running(__METHOD__, true);

        // Store result in transient cache for 5 minutes unless forced (only when state changed)
        if (!$force && $state_changed) {
            $cache_key = frl_prefix('env_check') . '_' . md5(isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'default');
            set_transient($cache_key, $results, 5 * MINUTE_IN_SECONDS);
        }

        // Always return results if forced, otherwise only return if there were changes
        return $force || $state_changed ? $results : null;
    }

    /**
     * Reset all customization tracking
     * This clears the list of ignored plugins and options
     * but does not immediately apply environment settings
     */
    public static function reset_customizations()
    {
        $results = ['ignored_plugins_cleared' => false, 'ignored_options_cleared' => false];
        // Clear ignored plugins
        frl_update_option(self::IGNORE_PLUGINS_KEY, []);
        $results['ignored_plugins_cleared'] = true;

        // Clear ignored options
        frl_update_option(self::IGNORE_OPTIONS_KEY, []);
        $results['ignored_options_cleared'] = true;

        return $results; // Return results
    }

    /**
     * Track manual option changes
     * @param string $option_name Option name without prefix
     * @param mixed $old_value Old option value
     * @param mixed $new_value New option value
     */
    public static function track_plugin_options($option_name, $old_value, $new_value)
    {
        Frl_Environment_Monitor::track_plugin_options($option_name, $old_value, $new_value);
    }

    /**
     * Track manual plugin changes
     * @param string $plugin Plugin path
     */
    public static function track_plugins_activation_status($plugin)
    {
        Frl_Environment_Monitor::track_plugins_activation_status($plugin);
    }

    /**
     * Add environment switcher to admin bar
     * @param WP_Admin_Bar $wp_admin_bar
     */
    public static function add_environment_switcher($wp_admin_bar)
    {
        if (!frl_has_access('manage_options')) {
            return;
        }

        $config = self::get_domain_config();
        // If config cannot be determined, don't add the switcher.
        if (!$config) {
            return;
        }

        // Get the counterpart environment URL from the config
        // Safely get HTTP_HOST
        if (!isset($_SERVER['HTTP_HOST']) || empty($_SERVER['HTTP_HOST'])) {
            return; // Skip in contexts without HTTP_HOST (CLI, cron)
        }

        $current_domain = strtolower($_SERVER['HTTP_HOST']);

        $env_link = '';
        $current_env_type = $config['type'] ?? 'unknown';

        foreach (FRL_ENV_MAP as $domain => $env_const_name) {
            // Get the partial config array for the potential counterpart environment
            if (!defined($env_const_name)) continue; // Skip if constant not defined
            $partial_config = constant($env_const_name);
            if (!is_array($partial_config)) continue; // Skip if not an array

            // Determine the type of this potential counterpart environment
            // Check for explicit 'type' key first, otherwise infer from name
            $counterpart_env_type = $partial_config['type'] ?? (str_ends_with($env_const_name, '_STAGING') ? 'staging' : 'production');

            // Compare with the current environment's type to find the opposite type
            if ($counterpart_env_type !== $current_env_type) {

                $base_domain_current = preg_replace('/^(staging\\.|dev\\.|www\\.)/i', '', $current_domain); // Case-insensitive prefix removal
                $base_domain_counterpart = preg_replace('/^(staging\\.|dev\\.|www\\.)/i', '', $domain); // Case-insensitive prefix removal

                if ($base_domain_current === $base_domain_counterpart) {
                    // Found the counterpart domain (e.g., staging found production or vice-versa)
                    $env_link = "https://" . $domain;
                    break; // Found the counterpart, no need to check further
                }
            }
        }

        if (!$env_link || !frl_has_access()) {
            $env_link = '';
        }

        // Get search engine indexing status
        $is_public = get_option('blog_public');
        $indexing_class = $is_public ? 'indexing-on' : 'indexing-off';
        $type = $config['type'] ?? '';
        $title = 'production' == $type ? 'www' : $type;

        $wp_admin_bar->add_menu([
            'id'    => FRL_PREFIX . '-server',
            'title' => $title,
            'href'  => $env_link,
            'meta'  => [
                'class' => FRL_PREFIX . '-server ' . $config['type'] . ' ' . $indexing_class,
                'title' => ucwords($type) . ' - Search Engines ' . ($is_public ? 'Allowed' : 'Blocked'),
            ],
        ]);
    }

    /**
     * Get domain configuration for current domain (public access)
     * @return array Domain configuration
     */
    public static function get_config()
    {
        // Retrieve domain configuration
        $config = self::get_domain_config();
        // Log if configuration could not be determined
        if ($config === null) {
            frl_log('Environment configuration is null in Frl_Environment_Manager::get_config().');
        }
        return $config;
    }

    /**
     * Get domain configuration for current domain, with caching.
     */
    private static function get_domain_config()
    {
        return Frl_Environment_Config::get_domain_config();
    }

    /**
     * Builds the domain configuration from constants.
     * This contains the original logic for constructing the configuration when not found in cache.
     *
     * @return array|null The built configuration or null on failure.
     */
    private static function build_domain_config()
    {
        return Frl_Environment_Config::build_domain_config();
    }

    /**
     * Merges environment configuration arrays with specific logic for plugins.
     *
     * @param array $base Base configuration (e.g., FRL_ENV_DEFAULT).
     * @param array $type_partial Type-specific partial overrides (e.g., FRL_ENV_DEFAULT_PRODUCTION).
     * @param array $instance_partial Instance-specific partial overrides (e.g., FRL_ENV_PBS_PRODUCTION).
     * @return array The fully merged configuration.
     */
    private static function merge_environment_configs(array $base, array $type_partial, array $instance_partial, array $extends_partial = []): array
    {
        return Frl_Environment_Config::merge_environment_configs($base, $type_partial, $instance_partial, $extends_partial);
    }
}
