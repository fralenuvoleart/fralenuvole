<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cache Display Class
 * Handles rendering and display of cache statistics and information
 *
 * This class acts as a specialized presenter for cache data,
 * translating cache manager data structures into UI components.
 *
 * @see Frl_Cache_Manager For the data provider
 */
class Frl_Cache_Display
{
    /**
     * Constructor
     */
    public function __construct()
    {
        // No initialization needed
    }

    /**
     * Get the persistent and runtime cache labels for the dashboard.
     *
     * @return array An associative array with 'persistent' and 'runtime' labels.
     */
    public static function get_cache_dashboard_labels()
    {
        $cache_details = self::get_cache_system_details();

        // Determine Persistent Cache Label, including a descriptive title attribute.
        $persistent_label = '<span title="' . esc_attr($cache_details['description']) . '">' . esc_html($cache_details['label']) . '</span>';

        // Determine Object Cache and Runtime Cache labels
        if ($cache_details['is_functional']) {
            // A high-performance object cache is active and working.
            // All labels correctly reflect the active backend.
            $object_cache_label = esc_html($cache_details['label']);
            $runtime_label = esc_html($cache_details['label']);
        } else {
            // A drop-in is not functional OR no drop-in at all.
            if ($cache_details['is_dropin']) {
                // A drop-in exists but is not functional (inactive, broken, etc.)
                $object_cache_label = esc_html($cache_details['label']);
            } else {
                // No object-cache.php drop-in is present.
                $object_cache_label = 'Not Configured';
            }
            // In all non-functional cases, the runtime is WP's default in-memory cache.
            $runtime_label = 'WP Object Cache (in-memory)';
        }

        return [
            'persistent'    => $persistent_label,
            'object_cache'  => $object_cache_label,
            'runtime'       => $runtime_label,
        ];
    }

    /**
     * Provides comprehensive details about the active caching system.
     * This method fetches raw data from the Cache Manager and enriches it with
     * descriptive text and status labels suitable for display.
     *
     * @return array An array with 'slug', 'label', 'is_functional', 'is_persistent', 'is_dropin', 'backend_class', and 'description'.
     */
    public static function get_cache_system_details()
    {
        static $cached_system_details = null;
        if ($cached_system_details !== null) {
            return $cached_system_details;
        }

        // Get the raw provider details from the single source of truth.
        $provider = Frl_Cache_Manager::get_provider_details();

        // --- Initial Defaults & Helpers ---
        $details = [
            'slug'          => $provider['slug'],
            'label'         => $provider['label'],
            'is_functional' => false, // Start with false, will be determined.
            'is_persistent' => true,  // Assume true, will be adjusted.
            'is_dropin'     => $provider['is_dropin'],
            'backend_class' => $provider['backend_class_override'] ?: $provider['original_class_name'],
            'description'   => 'Default description.'
        ];

        // === Early exit for bypass ===
        if (Frl_Cache_Manager::is_bypass_active()) {
            $details['is_functional'] = false;
            $details['is_persistent'] = false;
            $details['description'] = 'Plugin caching is currently bypassed (plugin disabled or cache disabled setting). No data is being persistently cached by this plugin.';
            $cached_system_details = $details;
            return $cached_system_details;
        }

        // === Determine true functional and persistence status ===
        $details['is_functional'] = $provider['is_effectively_functional'];

        // Persistence logic:
        // By default, this plugin *will* use transients if a high-performance cache is not functional.
        // So, is_persistent is almost always true unless caching is totally bypassed.
        $non_persistent_slugs = []; // Add any non-persistent slugs here if they exist in the future.
        if (in_array($details['slug'], $non_persistent_slugs)) {
            $details['is_persistent'] = false;
        } else {
            $details['is_persistent'] = true;
        }

        // === Append "(Fallback)" to label for inactive/broken/disabled drop-ins ===
        $inactive_or_broken_dropin_slugs = [
            'docket_cache_inactive_dropin',
            'docket_cache_broken',
            'docket_cache_force_disabled',
            'litespeed_inactive_dropin'
        ];

        if (in_array($details['slug'], $inactive_or_broken_dropin_slugs, true)) {
            if (!str_ends_with($details['label'], '(Fallback)')) {
                $details['label'] .= ' (Fallback)';
            }
        }

        // === Refine Description based on final state ===
        if ($details['is_functional']) {
            $details['description'] = 'Persistent cache active with ' . $details['label'] . ' backend.';
        } elseif ($details['slug'] === 'transients') {
            $details['description'] = 'Using WordPress Transients for caching. No external object cache drop-in configured.';
        } elseif ($details['is_dropin']) { // Drop-in present, but not functional
            if ($details['slug'] === 'docket_cache_force_disabled') {
                $details['description'] = 'Docket Cache is forcefully disabled by constant. Plugin is using WordPress Transients for fallback persistence.';
            } elseif ($details['slug'] === 'docket_cache_broken') {
                $details['description'] = 'Docket Cache drop-in detected, but it appears broken. Plugin is using WordPress Transients for fallback persistence.';
            } elseif (in_array($details['slug'], ['litespeed_inactive_dropin', 'docket_cache_inactive_dropin'])) {
                $provider_name = str_replace(['_inactive_dropin', '_'], ['', ' '], $details['slug']);
                $provider_name = ucwords(trim($provider_name));
                $details['description'] = $provider_name . ' drop-in detected but not active/functional. Plugin is using WordPress Transients for fallback persistence.';
            } elseif ($details['slug'] === 'wp_object_cache_dropin') {
                $details['description'] = 'Generic WP Object Cache drop-in detected; not a recognized high-performance cache. Plugin is using WordPress Transients for fallback persistence.';
            } elseif (str_starts_with($details['slug'], 'unknown_dropin')) {
                $details['description'] = 'Unknown object cache drop-in (' . $details['label'] . ') detected. Plugin is using WordPress Transients for fallback persistence.';
            } else { // General case for non-functional drop-in with transient fallback
                $details['description'] = 'Object cache drop-in (' . $details['label'] . ') detected but not functional. Plugin is using WordPress Transients for fallback persistence.';
            }
        }

        $cached_system_details = $details;
        return $cached_system_details;
    }

    /**
     * Renders the full "Options & Cache" widget section for the dashboard.
     *
     * @param array $env_data Environment data passed from the dashboard.
     * @return array An array containing two keys: 'main' for the visible widget HTML
     *               and 'hidden' for the toggleable, full-width sections.
     */
    public function render_dashboard_widget($env_data)
    {
        // --- Build Cache Section ---
        $cache_rows = '';
        $cache_rows .= frl_ui_render_table_row('Options & Cache', '', true);

        $cache_details = self::get_cache_system_details();
        $cache_labels = self::get_cache_dashboard_labels();

        // Create a unique ID for the options toggle
        $options_table_id = 'multicolumn-table';
        $options_toggle = ' ' . frl_ui_render_toggle_button(
            'Details',
            $options_table_id,
            'button-small align-right'
        );

        // Add Cache Details toggle button
        $cache_details_toggle_id = 'cache-details-toggle';
        $cache_details_toggle = ' ' . frl_ui_render_toggle_button(
            'Details',
            $cache_details_toggle_id,
            'button-small align-right'
        );

        // Display Active Options without toggle button
        $cache_rows .= frl_ui_render_table_row(
            'Active Options',
            ($env_data['options_count'] ?? 'N/A') .
                ' (cached ' . ($env_data['cached_options_count'] ?? 'N/A') . ')' .
                $options_toggle,
            false,
            'options-count-row'
        );

        // Add Persistent Cache row with toggle button
        $cache_rows .= frl_ui_render_table_row('Persistent Cache', $cache_labels['persistent'] . $cache_details_toggle, false);

        // Add Object Cache Is Functional row
        $cache_rows .= frl_ui_render_table_row('Object Cache', $cache_labels['object_cache'], false);

        // Add Runtime Cache row
        $cache_rows .= frl_ui_render_table_row('Runtime Cache', $cache_labels['runtime'], false);

        // --- Transients Table Toggle ---
        $transients_table_toggle_id = 'transients-details-toggle';
        $transients_data = $this->get_transients_details();

        $transient_count = $transients_data['count'];
        $total_size = '';
        $transients_toggle_button = '';

        if ($transient_count > 0) {
            // Render header once - update to 'Size (KB)'
            $total_size = array_sum(array_column($transients_data['details'], 'size')) / 1024;
            $total_size = ' (' . number_format($total_size, 1) . ' KB)';
            $transients_toggle_button = ' ' . frl_ui_render_toggle_button(
                'Details',
                $transients_table_toggle_id,
                'button-small align-right'
            );
        }

        $transient_row_value = $transient_count . $total_size . $transients_toggle_button;

        $cache_rows .= frl_ui_render_table_row(
            'Plugin Transients',
            $transient_row_value,
            false,
            'transient-count-row'
        );

        $cache_table = frl_ui_render_table('dashboard-cache', $cache_rows);
        $main_section = '<div class="widget-section">' . $cache_table . '</div>';

        // --- Hidden Sections ---
        // Options Table
        $options_table_content = $this->render_options_table();
        $options_table = '<div id="' . $options_table_id . '" class="widget-section multicolumn-section" style="display:none;">' . $options_table_content . '</div>';

        // Transients Table
        $transients_table_content = $this->render_transients_details_table($transients_data['details']);
        $transients_table = '<div id="' . $transients_table_toggle_id . '" class="widget-section multicolumn-section" style="display:none;">' . $transients_table_content . '</div>';

        // Cache Details Table
        $cache_info_table_content = $this->render_cache_info_table($cache_details);
        $cache_groups_table_content = $this->render_cache_groups_table();
        $cache_details_table = '<div id="' . $cache_details_toggle_id . '" class="widget-section multicolumn-section" style="display:none;">' .
            $cache_info_table_content .
            $cache_groups_table_content .
            '</div>';

        $hidden_content = $options_table . $transients_table . $cache_details_table;

        return [
            'main'   => $main_section,
            'hidden' => $hidden_content,
        ];
    }

    /**
     * Render cache info table
     *
     * @param array $cache_info The cache details from frl_cache_display_get_system_details()
     * @return string HTML for the cache info table
     */
    private function render_cache_info_table($cache_info)
    {
        // get_cache_system_details() is designed to always return an array.
        // A basic check for non-empty array can be done for robustness.
        if (!frl_is_array_not_empty($cache_info)) {
            $no_info_message = frl_ui_render_table_row(esc_html__('Cache system details are unavailable or the system returned an empty response.', 'fralenuvole'), '');
            return frl_ui_render_table('cache-info-table', $no_info_message, 'multicolumn-table');
        }

        $table_rows = frl_ui_render_table_row('Cache System Details', '', true, 'cache-system-header-row');

        $details_to_display = [
            'Cache Slug'       => $cache_info['slug'] ?? 'N/A',
            'Cache Provider'   => $cache_info['label'] ?? 'N/A',
            'Persistent Cache' => $cache_info['is_persistent'] ?? null,
            'Is Functional'    => $cache_info['is_functional'] ?? null,
            'Drop-in Present'  => $cache_info['is_dropin'] ?? null,
            'Backend Class'    => $cache_info['backend_class'] ?? 'N/A',
            'Details'          => $cache_info['description'] ?? 'N/A',
        ];

        foreach ($details_to_display as $label => $value) {
            $formatted_value = '';

            if ($label === 'Drop-in Present') {
                $formatted_value = $value ? frl_ui_render_status_dot_boolean(true, 'Yes') : 'Not Configured';
            } elseif ($label === 'Persistent Cache' || $label === 'Is Functional') {
                $text = $value ? 'Yes' : 'No';
                $formatted_value = frl_ui_render_status_dot_boolean((bool) $value, $text);
            } elseif (is_null($value)) {
                $formatted_value = '<em>N/A</em>';
            } elseif ($label === 'Cache Slug' || $label === 'Backend Class') {
                $formatted_value = '<code>' . esc_html((string)$value) . '</code>';
            } else {
                $formatted_value = esc_html((string)$value);
            }
            $table_rows .= frl_ui_render_table_row(esc_html($label), $formatted_value);
        }

        return frl_ui_render_table('cache-info-table', $table_rows, 'multicolumn-table');
    }

    /**
     * Render cache groups table with 7 columns
     *
     * @return string HTML for the cache groups table
     */
    private function render_cache_groups_table()
    {
        $groups_info = self::get_groups_info();
        $groups_table_rows = '';

        // Calculate total count for header
        $total_count = array_sum(array_column($groups_info, 'count'));

        // Header for groups table - now 7 columns with total in Count column
        $groups_table_rows .= frl_ui_render_multi_column_header(
            ['Cache Group', 'Count (' . number_format($total_count) . ')', 'Heavy Group', 'Language Group', 'Browser Group', 'TTL', 'Size'],
            'groups-header-row'
        );

        // Show individual group data
        foreach ($groups_info as $group_info) {
            $group_name = $group_info['name'];

            // Build group name with dependencies
            $group_display = ' <span class="group-name">' . esc_html($group_name) . '</span>';
            if (isset($group_info['dependencies']) && !empty($group_info['dependencies'])) {
                $deps = implode(', ', $group_info['dependencies']);
                $group_display .= ' <span class="group-dependencies">→  ' . esc_html($deps) . '</span>';
            }

            $groups_table_rows .= frl_ui_render_multi_column_row(
                [
                    $group_display,
                    number_format($group_info['count']),
                    frl_ui_render_status_dot_boolean($group_info['is_heavy'], '', false),
                    frl_ui_render_status_dot_boolean($group_info['is_language'], '', false),
                    frl_ui_render_status_dot_boolean($group_info['is_browser'], '', false),
                    $this->format_ttl($group_info['ttl']),
                    $group_info['size']
                ],
                false,
                "group-{$group_name}-row"
            );
        }

        // Create the separate groups table
        return frl_ui_render_table('cache-groups-table', $groups_table_rows, 'multicolumn-table');
    }

    /**
     * Render the options comparison table
     *
     * @return string HTML for the options comparison table
     */
    private function render_options_table()
    {
        $options_data = $this->get_options_comparison();

        // Create table header with 4 columns (Add Autoload back)
        $table_rows = frl_ui_render_multi_column_header(
            ['Plugin Option', 'DB Value', 'Cached Value', 'Autoload'], // Added 'Autoload'
            'options-header-row' // Removed div_row arg
        );

        // Add table rows for each option
        foreach ($options_data as $option) {
            // Set row class based on status - prioritize 'not cached' over 'different'
            $row_class = '';
            if (!$option['is_cached']) {
                // If not cached, always use this class regardless of difference
                $row_class = 'option-not-cached';
            } elseif ($option['is_different']) {
                // Only mark as different if it IS cached
                $row_class = 'option-different';
            }

            // Create a row with three separate columns
            $table_rows .= frl_ui_render_multi_column_row(
                [
                    $option['key'],
                    $option['db_value'],
                    $option['cached_value'],
                    // Render Autoload status using boolean dot renderer with custom text
                    frl_ui_render_status_dot_boolean(
                        ($option['autoload'] === 'yes'), // Convert to boolean
                        ($option['autoload'] === 'yes' ? __('Yes') : __('No')) // Pass custom text
                    )
                ],
                false,
                $row_class // Removed last arg
            );
        }

        // Render the complete table
        return frl_ui_render_table('options-table', $table_rows, 'multicolumn-table');
    }


    /**
     * Render the transients details table, grouped by cache group.
     *
     * @param array $transients_data The transient details from get_transients_details()
     * @return string HTML for the transients details table.
     */
    private function render_transients_details_table($transients_data)
    {
        // Group transients by their cache group
        $grouped_transients = [];
        foreach ($transients_data as $transient) {
            $grouped_transients[$transient['group']][] = $transient;
        }

        // Sort groups by size (descending), ensuring 'general' comes FIRST
        uksort($grouped_transients, function ($a, $b) use ($grouped_transients) {
            if ($a === 'general') return -1; // Put general first
            if ($b === 'general') return 1;  // Put general first

            // Calculate total size for each group
            $size_a = array_sum(array_column($grouped_transients[$a], 'size'));
            $size_b = array_sum(array_column($grouped_transients[$b], 'size'));

            return $size_b <=> $size_a; // Sort by size descending (largest first)
        });

        $table_rows = '';

        if (empty($grouped_transients)) {
            // Use simple array structure for content
            $table_rows .= frl_ui_render_multi_column_row(
                [
                    esc_html__('No plugin transients found.', FRL_PREFIX), // First col content
                    '', // Empty cols to match structure
                    '',
                    ''
                ],
                false,
                'no-transients-row' // Removed last arg
            );
        } else {
            // Render header once - update to 'Size (KB)'
            $total_size = array_sum(array_column($transients_data, 'size')) / 1024;

            $table_rows .= frl_ui_render_multi_column_header(
                ['Transients', 'Total Size ('. number_format($total_size, 1) . ' KB)', 'Expires'],
                'transients-header-row' // Removed div_row arg
            );

            // Iterate through each group
            foreach ($grouped_transients as $group_name => $transients_in_group) {

                // Render the group header row for groups OTHER than 'general', styled as a header
                if ($group_name !== 'general') {
                    // Calculate count and total size (in KB) for this group
                    $group_count = count($transients_in_group);
                    $group_size = array_sum(array_column($transients_in_group, 'size')) / 1024;
                    $count_display = $group_count . ' (' . number_format($group_size, 1) . ' KB)';

                    $table_rows .= frl_ui_render_multi_column_row(
                        [
                            'Cache Group: ' . esc_html(ucfirst($group_name)), // Group name in first column
                            $count_display, // Add count and size to the second column
                            ''  // Empty third column
                        ],
                        true, // SET TO TRUE for header styling
                        'transient-group-header ' . sanitize_title($group_name) . '-group' // Removed last arg
                    );
                }

                // Sort transients within the group by SIZE descending
                usort($transients_in_group, function ($a, $b) {
                    return $b['size'] <=> $a['size']; // Compare size descending
                });

                // Render rows for transients in this group
                foreach ($transients_in_group as $transient) {
                    $row_class = ($transient['expires_display'] === 'Expired') ? 'transient-expired' : '';
                    $row_class .= $transient['is_site'] ? ' site-transient' : ' standard-transient';

                    // --- Truncate Name & Add Title Attribute ---
                    $full_name = FRL_PREFIX . '_' . $transient['name'];
                    $display_content = esc_html($full_name); // Default to escaped full name

                    if (strlen($full_name) > 50) {
                        $truncated_name = esc_html(substr($full_name, 0, 50)) . '...';
                        // Since renderer now accepts HTML in first col, wrap in span with title
                        $display_content = '<span title="' . esc_attr($full_name) . '">' . $truncated_name . '</span>';
                    }
                    // --- End Truncate Name & Title ---

                    $size_kb = ($transient['size'] > 1024 ? number_format($transient['size'] / 1024, 1) : number_format($transient['size'] / 1024, 3)) . ' KB';

                    // Use simple array structure for row content - REMOVE group name element
                    $table_rows .= frl_ui_render_multi_column_row(
                        [
                            $display_content, // Pass potentially truncated name with title span, or just full name
                            $size_kb, // Show size in KB with 2 decimals
                            $transient['expires_display'] // String
                        ],
                        false, // Not a header row
                        trim($row_class) // Removed last arg
                    );
                }
            }
        }

        // Render the complete table
        return frl_ui_render_table('transients-table', $table_rows, 'multicolumn-table'); // Removed widget-table
    }


    /**
     * Get comprehensive information about cache groups
     *
     * @param string|null $group The cache group, or null to get all groups
     * @return array|null Group info array or array of all groups with comprehensive info when $group is null
     */
    public static function get_groups_info(?string $group = null)
    {
        if (!frl_cache_is_loaded()) {
            return $group === null ? [] : null;
        }

        $persistent_groups = FRL_CACHE_PERSISTENT_GROUPS;

        $config = frl_cache_get_config();
        $cache_functional = frl_is_object_cache_functional();

        // If no specific group requested, return all groups with comprehensive info
        if ($group === null) {
            $groups_info = [];

            // Get group metadata once
            $heavy_groups = FRL_CACHE_HEAVY_GROUPS;
            $language_groups = FRL_CACHE_LANGUAGE_GROUPS;
            $browser_groups = FRL_CACHE_BROWSER_GROUPS;
            $dependencies = $config['cache_dependencies'];

            foreach ($persistent_groups as $group_name) {
                $group_info = self::get_group_info($group_name, $config, $cache_functional);
                if ($group_info) {
                    // Add metadata to each group
                    $group_info['is_heavy'] = in_array($group_name, $heavy_groups);
                    $group_info['is_language'] = in_array($group_name, $language_groups);
                    $group_info['is_browser'] = in_array($group_name, $browser_groups);
                    $group_info['dependencies'] = $dependencies[$group_name] ?? [];
                    $groups_info[] = $group_info;
                }
            }

            // Sort first by count (descending), then by TTL (descending)
            usort($groups_info, function ($a, $b) {
                // Primary sort: count descending
                $count_comparison = $b['count'] <=> $a['count'];
                if ($count_comparison !== 0) {
                    return $count_comparison;
                }
                // Secondary sort: TTL descending
                return $b['ttl'] <=> $a['ttl'];
            });

            return $groups_info;
        }

        // Fallback for single group, though the main path is for all groups.
        return self::get_group_info($group, $config, $cache_functional);
    }

    /**
     * Get comprehensive info for a single specific group
     *
     * @param string $group The cache group
     * @param array  $config The cache configuration
     * @param bool   $cache_functional Whether the object cache is functional
     * @return array Group info array
     */
    private static function get_group_info(string $group, array $config, bool $cache_functional)
    {
        // Get comprehensive info for specific group
        $runtime_data = frl_cache_get_runtime_data();

        // Count keys in runtime cache
        $runtime_count = 0;
        $prefix = $group . '_';
        foreach (array_keys($runtime_data['runtime_cache']) as $key) {
            if (str_starts_with($key, $prefix)) {
                $runtime_count++;
            }
        }

        // Get actual count
        $count = $runtime_count;
        if (!$cache_functional && in_array($group, $config['persistent_groups'])) {
            global $wpdb;
            $transient_prefix = '_transient_' . $config['PREFIX'] . $group . '_';

            $persistent_count = frl_cache_safe_db_get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $wpdb->options
                WHERE option_name LIKE %s
                AND option_name NOT LIKE %s",
                $wpdb->esc_like($transient_prefix) . '%',
                $wpdb->esc_like('_transient_timeout_') . '%'
            ), 0, 'count_group_keys');

            $count = $persistent_count > 0 ? $persistent_count : $runtime_count;
        }

        // Get TTL
        $ttl = $config['TTL'][$group] ?? $config['TTL']['default'] ?? 0;

        // Get size (only for transients)
        $size = '';
        if (!$cache_functional && in_array($group, $config['persistent_groups'])) {
            global $wpdb;
            $transient_prefix = '_transient_' . $config['PREFIX'] . $group . '_';

            $size_bytes = frl_cache_safe_db_get_var($wpdb->prepare(
                "SELECT SUM(LENGTH(option_value)) FROM $wpdb->options
                WHERE option_name LIKE %s AND option_name NOT LIKE %s",
                $wpdb->esc_like($transient_prefix) . '%',
                $wpdb->esc_like('_transient_timeout_') . '%'
            ), 0, 'get_group_size');

            $size = $size_bytes ? round($size_bytes / 1024, 1) . ' KB' : '0 KB';
        }

        return [
            'name' => $group,
            'count' => $count,
            'ttl' => $ttl,
            'size' => $size
        ];
    }

    /**
     * @var array|null Cached transients data to avoid multiple queries
     */
    private static $cached_transients_data = null;

    /**
     * Get details for all plugin-specific transients.
     *
     * @return array An array of transient details, each containing name, type, size, expires.
     */
    private function get_transients_details()
    {
        // Return from static cache if available
        if (self::$cached_transients_data !== null) {
            return self::$cached_transients_data;
        }

        // Define prefixes needed for processing keys later
        $plugin_prefix = FRL_PREFIX . '_';
        $transient_prefix = '_transient_' . $plugin_prefix;
        $timeout_prefix = '_transient_timeout_' . $plugin_prefix;
        $site_transient_prefix = '_site_transient_' . $plugin_prefix;
        $site_timeout_prefix = '_site_transient_timeout_' . $plugin_prefix;

        // Fetch raw transient data using the new cached helper function
        $plugin_transients_raw = frl_get_all_plugin_transients();

        // Process raw transients to pair values with timeouts and determine groups
        $transients = [];
        $timeouts = [];
        foreach ($plugin_transients_raw as $row) {
            if (str_contains($row->option_name, '_timeout_')) {
                // Extract base key from timeout option name
                $key = str_replace([$timeout_prefix, $site_timeout_prefix], '', $row->option_name);
                $timeouts[$key] = (int)$row->option_value;
            } else {
                // Extract base key and determine if site transient
                $is_site = str_starts_with($row->option_name, $site_transient_prefix);
                $base_key = str_replace([$transient_prefix, $site_transient_prefix], '', $row->option_name);

                // --- Corrected Group Detection ---
                $group = 'general'; // Default fallback group
                $cache_prefix = 'cache_';

                if (str_starts_with($base_key, $cache_prefix)) {
                    $remaining_key = substr($base_key, strlen($cache_prefix));
                    $next_underscore_pos = str_contains($remaining_key, '_') ? strpos($remaining_key, '_') : false;

                    if ($next_underscore_pos !== false) {
                        $extracted_group = substr($remaining_key, 0, $next_underscore_pos);
                        if (!empty($extracted_group)) {
                            $group = $extracted_group; // Assign the extracted group name
                        }
                    }
                    // If no underscore after cache_ prefix, or extracted group is empty, it stays 'general'
                } // Keep specific check for environment cache as potential override/different pattern
                elseif (defined('FRL_ENV_CACHE_KEY') && str_contains($base_key, FRL_ENV_CACHE_KEY)) {
                    $group = FRL_ENV_CACHE_GROUP;
                }
                // --- End Corrected Group Detection ---

                $transients[$base_key] = [
                    'name' => $base_key, // Store the name without prefix
                    'value' => $row->option_value,
                    'size' => strlen($row->option_value),
                    'expires_timestamp' => null, // Store timestamp for sorting
                    'expires_display' => 'N/A', // Default display
                    'is_site' => $is_site,
                    'group' => $group // Use the determined group
                ];
            }
        }

        // Match timeouts to values and calculate display strings
        foreach ($transients as $base_key => &$transient) {
            if (isset($timeouts[$base_key])) {
                $expiry_timestamp = $timeouts[$base_key];
                $transient['expires_timestamp'] = $expiry_timestamp;

                if ($expiry_timestamp) {
                    $time_left = $expiry_timestamp - time();
                    if ($time_left > 0) {
                        $human_diff = human_time_diff(time(), $expiry_timestamp); // Get relative time difference

                        // Always display only the relative time difference
                        $transient['expires_display'] = $human_diff;
                    } else {
                        $transient['expires_display'] = 'Expired';
                    }
                } else {
                    $transient['expires_display'] = 'Does not expire';
                }
            }
        }
        unset($transient); // Unset reference

        // Cache and return both details and count
        self::$cached_transients_data = [
            'details' => $transients,
            'count' => count($transients)
        ];

        return self::$cached_transients_data;
    }

    /**
     * Get options comparison data (DB vs cached values)
     *
     * @return array Associative array with option data
     */
    private function get_options_comparison()
    {
        // Fetch all options set to autoload='yes' (uses WP cache)
        $all_autoload_options = wp_load_alloptions();

        // Get options directly from database (no cache) - Using UNPREFIXED keys from helper
        $db_options_unprefixed = frl_get_plugin_options_db();

        // Get cached options using the plugin options function - Using UNPREFIXED keys from helper
        $cached_options_unprefixed = frl_get_plugin_options();

        // Get the plugin prefix once
        $plugin_prefix = frl_prefix();

        // Combine all UNPREFIXED option keys to ensure we include all options
        $all_keys = array_unique(array_merge(array_keys($db_options_unprefixed), array_keys($cached_options_unprefixed)));

        // Prepare arrays for sorting: booleans, arrays/objects, others
        $bool_options = [];
        $array_options = [];
        $other_options = [];

        foreach ($all_keys as $display_key) { // Use $display_key for unprefixed key
            $db_value = $db_options_unprefixed[$display_key] ?? 'N/A';
            $cached_value = $cached_options_unprefixed[$display_key] ?? 'N/A';

            // Determine difference (handles arrays internally)
            $is_different = $this->frl_values_are_different($db_value, $cached_value);

            // Determine if the value is cached
            $is_cached = isset($cached_options_unprefixed[$display_key]);

            // Determine Autoload Status
            $prefixed_key = $plugin_prefix . $display_key;
            $db_autoload = isset($all_autoload_options[$prefixed_key]) ? 'yes' : 'no';

            // Prioritize DB value for type checking, fallback to cached if DB is N/A
            $value_for_type_check = ($db_value !== 'N/A') ? $db_value : $cached_value;

            // 1. Check for Boolean
            if ($this->is_boolean_value($value_for_type_check)) {
                // Format boolean values
                if ($is_different && $this->is_boolean_value($db_value) && $this->is_boolean_value($cached_value)) {
                    $warning_text = ($db_value ? 'enabled' : 'disabled') . ' ⚠️';
                    $db_formatted = frl_ui_render_status_dot_boolean((bool) $db_value, $warning_text);
                } else {
                    $db_formatted = frl_ui_render_status_dot_boolean((bool) $db_value);
                }

                $cached_formatted = $is_cached
                    ? frl_ui_render_status_dot_boolean((bool) $cached_value)
                    : '<span class="not-cached">not cached</span>';

                $option_data = [
                    'key' => $display_key,
                    'db_value' => $db_formatted,
                    'cached_value' => $cached_formatted,
                    'is_different' => $is_different,
                    'is_cached' => $is_cached,
                    'autoload' => $db_autoload
                ];
                $bool_options[] = $option_data;

                // 2. Check if the raw value is structurally an array/object, not a wrapped scalar.
            } elseif (is_array($value_for_type_check) || is_object($value_for_type_check) || (is_string($value_for_type_check) && (str_starts_with(trim($value_for_type_check), '{') || str_starts_with(trim($value_for_type_check), '[')))) {
                // Format using format_option_value which handles arrays/objects/JSON
                $db_formatted = $this->format_option_value($db_value);
                $cached_formatted = $is_cached
                    ? $this->format_option_value($cached_value)
                    : '<em class="not-cached">not cached</em>';

                $option_data = [
                    'key' => $display_key,
                    'db_value' => $db_formatted,
                    'cached_value' => $cached_formatted,
                    'is_different' => $is_different,
                    'is_cached' => $is_cached,
                    'autoload' => $db_autoload
                ];
                $array_options[] = $option_data;

                // 3. All Others (strings, numbers, etc.)
            } else {
                // Format using format_option_value which handles other scalars
                $db_formatted = $this->format_option_value($db_value);
                $cached_formatted = $is_cached
                    ? $this->format_option_value($cached_value)
                    : '<em class="not-cached">not cached</em>';

                $option_data = [
                    'key' => $display_key,
                    'db_value' => $db_formatted,
                    'cached_value' => $cached_formatted,
                    'is_different' => $is_different,
                    'is_cached' => $is_cached,
                    'autoload' => $db_autoload
                ];
                $other_options[] = $option_data;
            }
        }

        // Sort each category by key
        /** @var array<int, array{key: string, db_value: string, cached_value: string, is_different: bool, is_cached: bool, autoload: string}> $bool_options */
        /** @var array<int, array{key: string, db_value: string, cached_value: string, is_different: bool, is_cached: bool, autoload: string}> $array_options */
        /** @var array<int, array{key: string, db_value: string, cached_value: string, is_different: bool, is_cached: bool, autoload: string}> $other_options */
        $sort_by_key = function ($a, $b) {
            return strcmp($a['key'], $b['key']);
        };
        usort($bool_options, $sort_by_key);
        usort($array_options, $sort_by_key);
        usort($other_options, $sort_by_key);

        // Combine the sorted arrays in the desired order
        return array_merge($bool_options, $array_options, $other_options);
    }

    /**
     * Format option value for display
     *
     * @param mixed $value The option value to format
     * @return string Formatted value
     */
    private function format_option_value($value)
    {
        // Helper function for HTML formatting of key-value pairs
        $format_complex = function ($data) {
            $output_lines = [];
            // Assumes $data is already an associative array here
            foreach ($data as $key => $val) {
                // Skip the 'hash' key
                if ($key === 'hash') {
                    continue;
                }

                $formatted_val = '';
                if (is_array($val)) {
                    $formatted_val = '[array]';
                } elseif (is_object($val)) {
                    $formatted_val = '[object]';
                } elseif (is_bool($val)) {
                    $formatted_val = $val ? 'true' : 'false';
                } elseif (is_null($val)) {
                    $formatted_val = '<em>null</em>';
                } elseif (is_scalar($val)) {
                    $string_val = (string)$val;
                    if (strlen($string_val) > 100) {
                        $formatted_val = esc_html(substr($string_val, 0, 100)) . '...';
                    } else {
                        $formatted_val = esc_html($string_val);
                    }
                } else {
                    $formatted_val = '[unknown type]';
                }
                $output_lines[] = '<strong>' . esc_html($key) . ':</strong> ' . $formatted_val;
            }
            return '<code>' . implode('<br>', $output_lines) . '</code>';
        };

        if ($this->is_boolean_value($value)) {
            return frl_ui_render_status_dot_boolean($value);
        }

        // Normalize the value. This will always return an array.
        $normalized_value = frl_normalize_to_array($value);

        // Check if the normalized value represents a structured array or a wrapped scalar.
        // A wrapped scalar will be a single-element array with a key of 0.
        // A true array/object will have more than one element, or one element with a non-zero key.
        if (count($normalized_value) > 1 || (count($normalized_value) === 1 && !array_key_exists(0, $normalized_value))) {
            // It's a structured array. Format its key-value pairs.
            return $format_complex($normalized_value);
        }

        // It's a scalar (or empty array). Proceed with simple formatting.
        if (is_null($value) || $value === 'N/A') {
            return '<em>null</em>';
        } elseif (is_string($value)) {
            // Handle non-JSON strings (or JSON that wasn't array/object)
            if (strlen($value) > 50) {
                return '<code>' . esc_html(substr($value, 0, 50)) . '...</code>';
            }
            // Fall through for short strings
        }

        // Handle simple arrays that fell through (e.g., [] or ['one_item'])
        if (is_array($value)) {
            return '<code>' . esc_html(json_encode($value)) . '</code>';
        }

        // Default fallback for other scalar types or short strings
        return '<code>' . esc_html((string)$value) . '</code>';
    }

    // Add a helper function to compare values, handling arrays/objects
    // This simplifies the difference check in the main loop
    private function frl_values_are_different($val1, $val2): bool
    {
        // Handle N/A comparison first, as it's a special display case.
        if ($val1 === 'N/A' && $val2 === 'N/A') return false;
        if ($val1 === 'N/A' || $val2 === 'N/A') return true;

        // Normalize both values for a consistent, safe comparison.
        $arr1 = frl_normalize_to_array($val1);
        $arr2 = frl_normalize_to_array($val2);

        // json_encode provides a reliable, order-sensitive comparison for arrays.
        return json_encode($arr1) !== json_encode($arr2);
    }

    /**
     * Check if a value is boolean
     *
     * @param mixed $value The value to check
     * @return bool True if the value is boolean or boolean-like
     */
    private function is_boolean_value($value)
    {
        return $value === true ||
            $value === false ||
            $value === '1' ||
            $value === '0' ||
            $value === 1 ||
            $value === 0;
    }

    /**
     * Convert TTL seconds to human-readable format
     *
     * @param int $seconds TTL in seconds
     * @return string Human-readable time format
     */
    private function format_ttl($seconds)
    {
        if ($seconds < 60) {
            return $seconds . ' sec';
        } elseif ($seconds < 3600) {
            $minutes = round($seconds / 60);
            return $minutes . ' min';
        } elseif ($seconds < 86400) {
            $hours = round($seconds / 3600);
            return $hours . ' hour' . ($hours > 1 ? 's' : '');
        } elseif ($seconds < 604800) {
            $days = round($seconds / 86400);
            return $days . ' day' . ($days > 1 ? 's' : '');
        } else {
            $weeks = round($seconds / 604800);
            return $weeks . ' week' . ($weeks > 1 ? 's' : '');
        }
    }
}
