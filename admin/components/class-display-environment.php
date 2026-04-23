<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Environment Display Class
 * Handles rendering of environment information in admin
 *
 * This class acts as a specialized presenter for environment configuration data,
 * translating environment data structures into UI components.
 *
 * @see Frl Environment Manager Class For the data provider
 */
class Frl_Environment_Display
{
    // Add class-level static cache property
    private static $class_cached_data = null;
    private static $cached_stored_state = null;
    private static $cached_transient_exists = null;
    private static $cached_db_options_count = null;

    // New static caches for the two remaining queries
    private static $request_cached_data = null; // Cache for the entire method across all instances in a request
    // Add a new class variable to store transients data
    private static $cached_all_transients = null;

    /**
     * Constructor
     */
    public function __construct()
    {
        // No initialization needed
    }

    /**
     * Main render method
     */
    public function render()
    {
        $env_data = $this->get_environment_stats();
        $output = $this->render_environment_table($env_data);

        // Add script to trigger the centralized Prism initialization
        $output .= '<script>
            jQuery(document).ready(function($) {
                // Trigger content loaded event for the centralized initialization
                $(document).trigger("frl_content_loaded");
            });
        </script>';

        return $output;
    }


    /**
     * Renders the full "Environment" widget section for the dashboard.
     *
     * @param array $env_data The environment data from get_environment_stats().
     * @return array An array containing 'main' and 'hidden' HTML strings.
     */
    public function render_dashboard_widget($env_data)
    {
        // Extract config from the data provided by Frl Environment Display
        $env_config = $env_data['config'] ?? null;
        // Use the type already determined in $env_data
        $env_type = strtoupper(($env_config['prefix'] ?? '') . ' ' . $env_data['type']);

        // --- Build Environment Section ---
        $env_rows = '';
        $env_rows .= frl_ui_render_table_row('Environment', $env_type, true);

        $live_siteurl = get_site_url();
        $env_host_key = $env_config['env_host'] ?? 'N/A';

        // Add toggle for full env config
        $env_config_toggle_id = 'env-config-details-toggle';
        $env_config_toggle_button = frl_ui_render_toggle_button(
            'Details',
            $env_config_toggle_id,
            'button-small align-right'
        );

        $env_rows .= frl_ui_render_table_row('Configured Host', $env_host_key . $env_config_toggle_button, false);
        $env_rows .= frl_ui_render_table_row('Site URL', $live_siteurl, false);

        // Show wp_options if defined in config
        if ($env_config && isset($env_config['wp_options']) && !empty($env_config['wp_options'])) {
            foreach ($env_config['wp_options'] as $key => $value) {
                $current_value = get_option($key);

                if (is_bool($current_value) || $current_value === '0' || $current_value === '1') {
                    $env_rows .= frl_ui_render_status_row($key, boolval($current_value), null);
                } else {
                    $env_rows .= frl_ui_render_table_row($key, $current_value, false);
                }
            }
        }

        $env_last_updated = $env_data['stored_state']['last_updated'] ?? 'Unknown';
        $env_rows .= frl_ui_render_table_row('State Last Updated', $env_last_updated, false);

        $debug_dot = frl_ui_render_status_dot_boolean(WP_DEBUG);
        $error_level = '<div class="env-debug">' . $debug_dot . '</div>';
        $env_rows .= frl_ui_render_table_row('WP_DEBUG', $error_level, false);

        $env_table = frl_ui_render_table('dashboard_overview', $env_rows);
        $env_section_content = $env_table;
        $main_section = '<div class="widget-section">' . $env_section_content . '</div>';

        // --- Hidden Section ---
        $hidden_section = '';
        if ($env_config) {
            $env_config_table_content = $this->render_full_config_table();
            $hidden_section = '<div id="' . $env_config_toggle_id . '" class="widget-section multicolumn-section" style="display:none;">' . $env_config_table_content . '</div>';
        }

        return [
            'main'   => $main_section,
            'hidden' => $hidden_section,
        ];
    }

    /**
     * Render environment template
     */
    private function render_environment_table($data)
    {
        $output = '';
        // Handle empty state case
        if (!$data['stored_state']) {
            $warning = $this->get_warning_message();
            $output .= frl_ui_render_widget('env-warning', $warning, 'Warning');
            return $output;
        }

        // Plugins Status section
        // Safely get HTTP_HOST
        if (!isset($_SERVER['HTTP_HOST']) || empty($_SERVER['HTTP_HOST'])) {
            return $output; // Return early without plugin status in CLI/cron contexts
        }

        // Use the config passed within the data array
        $env_config = $data['config'] ?? null;

        if ($env_config) {
            // Plugin Options section
            if (isset($env_config['plugin_options'])) {
                $options_content = $this->render_plugin_options($env_config);
                $output .= frl_ui_render_widget('env-options', $options_content, 'Managed Options', '', 0, true);
            }

            // MU Plugins Status widget
            $mu_plugins_status = $this->get_mu_plugins_status();

            // Add MU Plugins action buttons
            $delete_mu_plugins_button = frl_render_action_button('delete_mu_plugins', 'button-small', 'Delete MU Plugins', '', '', 'delete_plugins');

            $sync_mu_plugins_button = frl_render_action_button('sync_mu_plugins', 'button-small', 'Syncronise MU Plugins', '', '', 'delete_plugins');

            $mu_plugins_action_rows = frl_ui_render_table_row(
                $delete_mu_plugins_button,
                $sync_mu_plugins_button,
                false,
                'action-row'
            );

            $output .= frl_ui_render_widget('env-mu-plugins', $mu_plugins_status .$mu_plugins_action_rows, 'Managed MU Plugins');

            // Modules Status widget
            $modules_status = $this->get_modules_status($env_config);
            $output .= frl_ui_render_widget('env-modules', $modules_status, 'Managed Modules');

            // Plugins Status widget
            $plugins_status = $this->get_plugins_status($env_config);

            $output .= frl_ui_render_widget('env-plugins', $plugins_status, 'Managed Plugins');

            // State Mismatch Warning
            $mismatch_warning = $this->get_state_mismatch_warning($data['stored_state']);
            if ($mismatch_warning) {
                $output .= frl_ui_render_widget('env-warning', $mismatch_warning, 'State Mismatch Warning');
            }
        }

        return $output;
    }


    /**
     * Renders the current full environment configuration as a single JSON code block.
     *
     * @return string HTML for the environment configuration code block.
     */
    public function render_full_config_table()
    {
        // Use cached data instead of forcing a refresh - also ensure we reuse the same
        // cached data from other instances that might have already loaded it
        $env_data = self::$request_cached_data ?? $this->get_environment_stats(false);
        $env_config = $env_data['config'] ?? null;

        if (!frl_is_array_not_empty($env_config)) {
            // Use UI renderer for consistency
            $no_config_message = frl_ui_render_table_row(esc_html__('No environment configuration data available.', FRL_PREFIX), '');
            return $no_config_message; // Return only the message
        }

        $config_for_json_display = $env_config;

        $current_env_source_name = '';
        if (isset($env_config['current_environment'])) {
            $current_env_source_name = esc_html($env_config['current_environment']);
            unset($config_for_json_display['current_environment']);
        }

        $json_part = json_encode($config_for_json_display, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json_part === false) {
            $error_message = 'Error encoding configuration to JSON: ' . json_last_error_msg();
            // Use UI renderer for the error message row if source name wasn't added
            $error_content = frl_ui_render_table_row(esc_html($error_message), '');
            return $error_content;
        }

        $code_block_html = frl_ui_render_table_row($current_env_source_name, '', true);

        $code_block_html .= frl_ui_render_code_block(
            $json_part,
            'js',
            null,
            false,
            false
        );
        return $code_block_html;
    }

    /**
     * Render plugin options in a structured format
     */
    private function render_plugin_options($env_config)
    {
        // Data collection arrays
        $active_options_data = [];
        $inactive_options_data = [];

        // --- Collect data for Managed Options ---
        if (!empty($env_config['plugin_options'])) {
            foreach (array_keys($env_config['plugin_options']) as $key) {

                $is_intended_file_option = ($env_config['plugin_options'][$key] ?? null) === 'file';
                $actual_value = frl_get_option($key);
                $display_value = '';
                $code_block = '';
                $is_live_active = false;

                if ($is_intended_file_option) {
                    $has_content = !empty($actual_value);
                    $status_value = $has_content ? 'present' : 'empty';
                    $toggle_id = 'code-' . sanitize_key($key);
                    $display_value = frl_ui_render_status_dot_boolean($has_content, $status_value);
                    if ($has_content) {
                        $display_value .= ' ' . frl_ui_render_toggle_button('Show Code', $toggle_id, 'button-small');
                        $lang = 'js';
                        $code_block = frl_ui_render_code_block($actual_value, $lang, $toggle_id, true);
                    }

                    $control_key = $key . '_php';
                    if (isset($env_config['plugin_options'][$control_key])) {
                        $control_actual_value = frl_get_option($control_key);
                        $is_live_active = filter_var($control_actual_value, FILTER_VALIDATE_BOOLEAN);
                    } else {
                        $is_live_active = $has_content;
                    }
                } else {
                    $is_live_active = filter_var($actual_value, FILTER_VALIDATE_BOOLEAN);
                    $display_value = frl_ui_render_status_dot_boolean($is_live_active);
                }

                // Store collected data based on live status
                $option_data = ['key' => $key, 'display' => $display_value, 'code' => $code_block];

                // Categorize based on the default state in env_config, not live status
                $default_value_in_config = $env_config['plugin_options'][$key] ?? false; // Default to false if not explicitly set

                if ($default_value_in_config === 'file' || filter_var($default_value_in_config, FILTER_VALIDATE_BOOLEAN)) {
                    // This option is configured to be active by default (e.g. true, 'on', '1') or is a 'file' type option
                    $active_options_data[] = $option_data;
                } else {
                    // This option is configured to be inactive by default (e.g. false, 'off', '0')
                    $inactive_options_data[] = $option_data;
                }
            }
        }



        // --- Render the final HTML ---
        $output = '';

        // Render Active Section
        $output .= frl_ui_render_table_row('Enabled by default', '', true);
        if (!empty($active_options_data)) {
            // Optional: Sort $active_options_data by key if needed: usort($active_options_data, fn($a, $b) => strcmp($a['key'], $b['key']));
            foreach ($active_options_data as $item) {
                $output .= frl_ui_render_table_row($item['key'], $item['display']);
                if (!empty($item['code'])) {
                    $output .= $item['code'];
                }
            }
        } else {
            $output .= frl_ui_render_table_row('(None Currently Active)', '');
        }

        // Render Inactive Section
        $output .= frl_ui_render_table_row('Disabled by default', '', true);
        if (!empty($inactive_options_data)) {
            // Optional: Sort $inactive_options_data by key if needed
            foreach ($inactive_options_data as $item) {
                $output .= frl_ui_render_table_row($item['key'], $item['display']);
                if (!empty($item['code'])) {
                    $output .= $item['code'];
                }
            }
        } else {
            $output .= frl_ui_render_table_row('(None Currently Inactive)', '');
        }



        $table_id = 'env-plugin-options-table';
        return frl_ui_render_table($table_id, $output, 'widget-options');
    }

    /**
     * Get environment statistics for display
     *
     * @param bool $force_refresh Whether to force fresh data from database
     * @return array Environment data including stored state, type, transient count, and plugin options count
     */
    public function get_environment_stats($force_refresh = false)
    {
        // NEW: Use request-level caching for the entire method's result
        if (!$force_refresh && self::$request_cached_data !== null) {
            return self::$request_cached_data;
        }

        // Use class-level static cache when not forcing refresh
        if (!$force_refresh && self::$class_cached_data !== null) {
            // Store in the request cache for future calls from any instance
            self::$request_cached_data = self::$class_cached_data;
            return self::$class_cached_data;
        }

        global $wpdb;

        // Use cached stored_state if available and not forcing refresh
        if (!$force_refresh && self::$cached_stored_state !== null) {
            $stored_state = self::$cached_stored_state;
        } else {
            $environment_state_key = FRL_ENV_CACHE_GROUP . '_' . FRL_ENV_CACHE_KEY;

            $stored_state = frl_cache_remember(
                FRL_ENV_CACHE_GROUP,
                FRL_ENV_CACHE_KEY,
                fn() => frl_get_option($environment_state_key, true),
                DAY_IN_SECONDS
            );
            // Cache the stored state for subsequent calls
            self::$cached_stored_state = $stored_state;
        }

        // Get the plugin prefix
        $prefix = frl_prefix();

        // Get all transients in a single query for multiple uses
        // Use cached transients data if available, or fetch once and cache for reuse
        if (!$force_refresh && self::$cached_all_transients !== null) {
            $all_transients = self::$cached_all_transients;
        } else {
            $all_transients = frl_get_all_plugin_transients();
            self::$cached_all_transients = $all_transients;
        }

        // Use cached db options count if available and not forcing refresh
        if (!$force_refresh && self::$cached_db_options_count !== null) {
            $db_options_count = self::$cached_db_options_count;
        } else {
            // Count plugin options in the database
            $db_options_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $wpdb->options
                WHERE option_name LIKE %s",
                $wpdb->esc_like($prefix) . '%'
            ));

            // Cache for subsequent calls
            self::$cached_db_options_count = $db_options_count;
        }

        // Count cached options by looking for individual option cache entries
        $cached_options_count = 0;
        if (frl_cache_is_loaded()) {
            // Use frl_get_plugin_options directly to get cached options count
            $all_options = frl_get_plugin_options();
            $cached_options_count = frl_is_array_not_empty($all_options) ? count($all_options) : 0;
        }

        // Check for cache/db mismatch
        $options_mismatch = ($cached_options_count > 0 && $cached_options_count !== $db_options_count);

        // Use cached value if available and not forcing refresh
        if (!$force_refresh && self::$cached_transient_exists !== null) {
            $transient_exists = self::$cached_transient_exists;
        } else {
            // Check if the specific environment transient exists using the data we already have
            $transient_name = '_transient_' . FRL_CACHE_PREFIX . FRL_ENV_CACHE_GROUP . '_' . FRL_ENV_CACHE_KEY;
            $transient_exists = false;

            foreach ($all_transients as $transient) {
                if ($transient->option_name === $transient_name) {
                    $transient_exists = true;
                    break;
                }
            }

            // Cache for subsequent calls
            self::$cached_transient_exists = $transient_exists;
        }

        // Comprehensive source determination - ordered by retrieval priority
        if ($stored_state) {
            // Get cache information using consolidated method
            $cache_details = frl_cache_display_get_system_details();

            // Use the persistent cache type from the consolidated method
            $source = $cache_details['label'] ?? 'Unknown Cache Type';
        } else {
            $source = 'WordPress Options';
        }

        // Get environment config to determine type and include in result
        $env_config = frl_environment_get_config(); // Use helper
        $env_type = ($env_config && isset($env_config['type'])) ? $env_config['type'] : 'production'; // Default to production if config missing

        $result = [
            'config' => $env_config, // Include the full config
            'type' => $env_type,
            'stored_state' => $stored_state,
            'source' => $source,
            'options_count' => (int) $db_options_count,
            'cached_options_count' => (int) $cached_options_count,
            'options_mismatch' => $options_mismatch,
        ];

        // Always store in class-level cache, even when forced refresh was requested
        // This ensures subsequent calls get this fresh data
        self::$class_cached_data = $result;

        // Store in the request cache for future calls from any instance
        self::$request_cached_data = $result;

        return $result;
    }

    private function get_mu_plugins_status()
    {
        // Initialize output sections
        $output_mu = '';

        // --- Check for MU Plugins ---
        $mu_status = frl_get_mu_plugins_status();

        $output_mu .= frl_ui_render_table_row('Must-Use Plugins', '', true); // Section header

        // Show managed (in sync) files
        if (!empty($mu_status['managed'])) {
            foreach ($mu_status['managed'] as $filename) {
                $output_mu .= frl_ui_render_status_row(
                    $filename,
                    true,
                    null,
                    true
                );
            }
        }

        // Show out-of-sync files
        if (!empty($mu_status['out_of_sync'])) {
            foreach ($mu_status['out_of_sync'] as $filename) {
                $output_mu .= frl_ui_render_status_row(
                    $filename . ' (Out of sync)',
                    'warning',
                    'warning',
                    true
                );
            }
        }

        // Show missing files
        if (!empty($mu_status['missing'])) {
            foreach ($mu_status['missing'] as $filename) {
                $output_mu .= frl_ui_render_status_row(
                    $filename . ' (Missing)',
                    false,
                    'missing',
                    true
                );
            }
        }

        // Show orphaned files
        if (!empty($mu_status['orphaned'])) {
            foreach ($mu_status['orphaned'] as $filename) {
                $output_mu .= frl_ui_render_status_row(
                    $filename . ' (Orphaned)',
                    'uninstalled',
                    'uninstalled',
                    true
                );
            }
        }

        // Show message if no plugins configured
        if (empty($mu_status['managed']) && empty($mu_status['missing']) && empty($mu_status['orphaned']) && empty($mu_status['out_of_sync'])) {
            $output_mu .= frl_ui_render_status_row(
                'No MU plugins configured',
                false,
                null,
                true
            );
        }

        $mu_plugins_table = frl_ui_render_table('env-mu-plugins-table', $output_mu, 'widget-mu-plugins');

        return $mu_plugins_table;
    }

    private function get_plugins_status($env_config)
    {
        // Initialize output sections
        $output_active = '';
        $output_inactive = '';
        $output_ignored = '';

        // --- Process Managed Plugins from Config ---
        if (empty($env_config['plugins'])) {
            // If no managed plugins, we need to wrap it in a table for the widget
            $table_id = 'env-plugins-table'; // Define ID
            return frl_ui_render_table($table_id,'', 'widget-plugins');
        }

        $active_plugins_from_db = get_option('active_plugins', []);

        // Prepare data arrays
        $active_plugins_data = [];
        $inactive_plugins_data = [];

        // --- Process Active by Default Plugins ---
        if (frl_is_array_not_empty($env_config['plugins'], 'active')) {
            foreach ($env_config['plugins']['active'] as $plugin_path) {
                $plugin_file = WP_PLUGIN_DIR . '/' . $plugin_path;
                $is_installed = is_readable($plugin_file);
                $is_active = in_array($plugin_path, $active_plugins_from_db);
                $status = 'inactive'; // Default status

                if (!$is_installed) {
                    $status = 'uninstalled';
                    $is_active = false; // Uninstalled cannot be active
                } elseif ($is_active) {
                    $status = 'active';
                }

                $active_plugins_data[] = [
                    'name' => frl_get_plugin_name_from_path($plugin_path),
                    'status' => $status,
                    'is_active' => $is_active // Keep boolean for rendering logic
                ];
            }
        }

        // --- Process Inactive by Default Plugins ---
        if (frl_is_array_not_empty($env_config['plugins'], 'inactive')) {
            foreach ($env_config['plugins']['inactive'] as $plugin_path) {
                $plugin_file = WP_PLUGIN_DIR . '/' . $plugin_path;
                $is_installed = is_readable($plugin_file);
                $is_active = in_array($plugin_path, $active_plugins_from_db);
                $status = 'inactive'; // Default status

                if (!$is_installed) {
                    $status = 'uninstalled';
                    $is_active = false; // Uninstalled cannot be active
                } elseif ($is_active) {
                    // This case might indicate a mismatch if it was expected inactive
                    $status = 'active';
                }

                $inactive_plugins_data[] = [
                    'name' => frl_get_plugin_name_from_path($plugin_path),
                    'status' => $status,
                    'is_active' => $is_active // Keep boolean for rendering logic
                ];
            }
        }

        // --- Sort Plugin Data Arrays ---
        $sort_plugins = function ($a, $b) {
            $a_uninstalled = ($a['status'] === 'uninstalled');
            $b_uninstalled = ($b['status'] === 'uninstalled');

            if ($a_uninstalled && !$b_uninstalled) {
                return 1; // Uninstalled $a goes after $b
            }
            if (!$a_uninstalled && $b_uninstalled) {
                return -1; // Non-uninstalled $a goes before $b
            }
            // If both same installation status, sort by name
            return strcmp($a['name'], $b['name']);
        };

        usort($active_plugins_data, $sort_plugins);
        usort($inactive_plugins_data, $sort_plugins);

        // --- Render Sorted Data ---
        // Active plugins
        $output_active = frl_ui_render_table_row('Enabled by default', '', true);
        foreach ($active_plugins_data as $plugin) {
            $status_class = ($plugin['status'] === 'uninstalled') ? 'uninstalled' : null;
            // Pass 'uninstalled' string as value if status is uninstalled, otherwise pass the boolean
            $render_value = ($plugin['status'] === 'uninstalled') ? 'uninstalled' : $plugin['is_active'];
            $output_active .= frl_ui_render_status_row($plugin['name'], $render_value, $status_class, true);
        }

        // Inactive plugins
        $output_inactive = frl_ui_render_table_row('Disabled by default', '', true);
        foreach ($inactive_plugins_data as $plugin) {
            $status_class = ($plugin['status'] === 'uninstalled') ? 'uninstalled' : null;
            // Pass 'uninstalled' string as value if status is uninstalled, otherwise pass the boolean
            $render_value = ($plugin['status'] === 'uninstalled') ? 'uninstalled' : $plugin['is_active'];
            $output_inactive .= frl_ui_render_status_row($plugin['name'], $render_value, $status_class, true);
        }

        // Ignored Plugins section
        $ignored_plugins = frl_get_option('environment_ignore_plugins') ?? [];
        if (frl_is_array_not_empty($ignored_plugins)) {
            $output_ignored = frl_ui_render_table_row('Ignored Manually', '', true);

            foreach ($ignored_plugins as $plugin_path) {
                // Check if ignored plugin is installed/active for info
                $plugin_file = WP_PLUGIN_DIR . '/' . $plugin_path;
                $is_installed = is_readable($plugin_file);
                $is_active = in_array($plugin_path, $active_plugins_from_db);
                $status_text = 'Ignored';
                if (!$is_installed) {
                    $status_text .= ' (Uninstalled)';
                } elseif ($is_active) {
                    $status_text .= ' (Currently Active)';
                }
                $output_ignored .= frl_ui_render_table_row(frl_get_plugin_name_from_path($plugin_path), $status_text);
            }
        } else {
            $output_ignored .= frl_ui_render_table_row('No manually ignored plugins', '', false, 'info-text');
        }

        $output_plugins = $output_active . $output_inactive . $output_ignored;

        $plugins_table = frl_ui_render_table('env-plugins-table', $output_plugins, 'widget-plugins');

        return $plugins_table;
    }

    /**
     * Get plugin exclusions status for display in dashboard.
     *
     * Displays two sections:
     * - "Unloaded in Frontend": Plugins excluded from frontend context
     * - "Unloaded in Backend": Plugins excluded in admin context for users without required capability
     *
     * @return string HTML table with exclusion status
     */
    public function get_plugins_exclusions_status()
    {
        $output_frontend = '';
        $output_backend = '';
        $active_plugins_from_db = get_option('active_plugins', []);

        // --- Process Frontend Exclusion List ---
        $frontend_enabled = frl_get_option('excluded_plugins_frontend_enabled');
        if ($frontend_enabled) {
            $frontend_list = frl_textlist_to_array(frl_get_option('excluded_plugins_frontend'));
            if (!empty($frontend_list)) {
                // Flatten nested array
                $flat_list = [];
                foreach ($frontend_list as $items) {
                    if (is_array($items)) {
                        $flat_list = array_merge($flat_list, $items);
                    } else {
                        $flat_list[] = $items;
                    }
                }
                $flat_list = array_filter($flat_list, 'is_string');

                if (!empty($flat_list)) {
                    $output_frontend = frl_ui_render_table_row('Unloaded in Frontend', '', true);
                    foreach ($flat_list as $plugin_path) {
                        $plugin_file = WP_PLUGIN_DIR . '/' . $plugin_path;
                        $is_installed = is_readable($plugin_file);
                        $is_active = in_array($plugin_path, $active_plugins_from_db);
                        $status_text = 'Excluded';
                        if (!$is_installed) {
                            $status_text .= ' (Uninstalled)';
                        }
                        $output_frontend .= frl_ui_render_table_row(frl_get_plugin_name_from_path($plugin_path), $status_text);
                    }
                }
            }
        }

        if (empty($output_frontend)) {
            $output_frontend = frl_ui_render_table_row('Unloaded in Frontend', '', true);
            $output_frontend .= frl_ui_render_table_row('(None configured)', '');
        }

        // --- Process Backend (Capability-based) Exclusion List ---
        $cap_enabled = frl_get_option('excluded_plugins_bycap_enabled');
        $required_cap = frl_get_option('excluded_plugins_bycap_cap') ?: 'delete_plugins';

        if ($cap_enabled) {
            $cap_list = frl_textlist_to_array(frl_get_option('excluded_plugins_bycap'));
            if (!empty($cap_list)) {
                // Flatten nested array
                $flat_list = [];
                foreach ($cap_list as $items) {
                    if (is_array($items)) {
                        $flat_list = array_merge($flat_list, $items);
                    } else {
                        $flat_list[] = $items;
                    }
                }
                $flat_list = array_filter($flat_list, 'is_string');

                if (!empty($flat_list)) {
                    // Header row with columns: Unloaded in Backend, Required Capability
                    $header_columns = [
                        'Unloaded in Backend',
                        'Required Capability'
                    ];
                    $output_backend = frl_ui_render_multi_column_header($header_columns, 'frl-exclusions-header');

                    foreach ($flat_list as $plugin_path) {
                        $plugin_file = WP_PLUGIN_DIR . '/' . $plugin_path;
                        $is_installed = is_readable($plugin_file);
                        $plugin_name = frl_get_plugin_name_from_path($plugin_path);
                        $status_text = $is_installed ? 'Excluded' : '(Uninstalled)';

                        // Render multi-column row: [Plugin Name, Status]
                        $output_backend .= frl_ui_render_multi_column_row([
                            $plugin_name,
                            $status_text
                        ], false, 'frl-exclusion-row');
                    }
                }
            }
        }

        if (empty($output_backend)) {
            $header_columns = [
                'Unloaded in Backend',
                'Required Capability'
            ];
            $output_backend = frl_ui_render_multi_column_header($header_columns, 'frl-exclusions-header');
            $output_backend .= frl_ui_render_multi_column_row(['(None configured)', ''], false, 'info-text');
        }

        $output_exclusions = $output_frontend . $output_backend;

        $exclusions_table = frl_ui_render_table('env-plugins-exclusions-table', $output_exclusions, 'widget-plugins-exclusions');

        return $exclusions_table;
    }

    private function get_modules_status($env_config)
    {
        $output = '';

        // Check if modules array exists in env_config
        if (frl_is_array_not_empty($env_config, 'modules')) {
            // Get modules (which is a flat array with module name => boolean values)
            $modules = $env_config['modules'];

            // If no modules defined
            if (empty($modules)) {
                $output .= frl_ui_render_table_row('No modules configured', '');
            } else {
                // Separate modules into enabled and disabled arrays
                $enabled_modules = [];
                $disabled_modules = [];
                foreach ($modules as $module => $default_status) {
                    $option_name = 'module_' . $module;
                    $actual_status = frl_get_option($option_name);
                    $boolean_status = filter_var($actual_status, FILTER_VALIDATE_BOOLEAN);

                    $module_name = ucwords(str_replace('-', ' ', $module));
                    $module_name = strlen($module_name) <= 3 ? strtoupper($module_name) . ' module' : $module_name;

                    $row_html = frl_ui_render_status_row($module_name, $boolean_status, null, true);
                    if ($boolean_status) {
                        $enabled_modules[] = $row_html;
                    } else {
                        $disabled_modules[] = $row_html;
                    }
                }
                // Render enabled modules first, then disabled
                if (!empty($enabled_modules)) {
                    $output .= frl_ui_render_table_row('Enabled by default', '', true);
                    foreach ($enabled_modules as $row_html) {
                        $output .= $row_html;
                    }
                }
                if (!empty($disabled_modules)) {
                    $output .= frl_ui_render_table_row('Disabled by default', '', true);
                    foreach ($disabled_modules as $row_html) {
                        $output .= $row_html;
                    }
                }
            }
        } else {
            $output .= frl_ui_render_table_row('No modules defined', '');
        }

        $table_id = 'env-modules-table'; // Generate specific ID
        return frl_ui_render_table($table_id, $output, 'widget-modules');
    }

    /**
     * Generate a warning message for empty environment state
     *
     * @return string Warning message HTML
     */
    private function get_warning_message()
    {
        $message = "No environment state found!\n" .
            "This could mean:\n" .
            "1. First run after activation\n" .
            "2. Cache was cleared\n" .
            "3. Object cache is not working\n\n" .
            "State will be initialized on next page load.";

        return frl_ui_render_validation_message($message, 'warning');
    }

    /**
     * Generate warning message for environment state mismatch
     *
     * @param array $stored_state The stored environment state
     * @return string Warning message HTML or empty string if no mismatch
     */
    private function get_state_mismatch_warning($stored_state)
    {
        $current_state = [
            'host' => $_SERVER['HTTP_HOST'],
            'siteurl' => get_site_url(),
            'home' => get_home_url()
        ];

        if (
            $stored_state['host'] !== $current_state['host'] ||
            $stored_state['siteurl'] !== $current_state['siteurl'] ||
            $stored_state['home'] !== $current_state['home']
        ) {
            $message = "⚠️ Unexpected State Mismatch!\n" .
                "This shouldn't happen as environment check runs before this view.\n" .
                "Please check if 'init' hook is running correctly.";

            return frl_ui_render_validation_message($message, 'warning');
        }
        return '';
    }
}
