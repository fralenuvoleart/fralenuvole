<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Hooks Display Class
 * Handles rendering of hooks information registered via Frl_Hook_Manager.
 *
 * Fetches hook data, groups it by hook name, sorts callbacks by priority,
 * and renders a table where hook groups are ordered based on the sequence
 * defined in wordpress-hook-reference.php.
 *
 * @see Frl_Hook_Manager For the data source of registered hooks.
 */
class Frl_Hooks_Display
{
    /**
     * Fetches and prepares hook data from Frl_Hook_Manager.
     * Groups hooks by hook name, sorts registrations, replaces Closures with metadata,
     * and caches the processed, serializable data.
     *
     * @return array Structured array of hooks, ready for display.
     */
    private function get_hooks_data_grouped_by_name()
    {
        return frl_cache_remember('staticdata', 'hooks_data', function () {
            $hooks_by_name = [];

            // Use the new persistent hooks data that combines current and historical hook registrations
            $categorized_hooks_from_manager = frl_hook_manager_get_all_registered_hooks();

            // If the persistent hooks helper function exists, use it instead
            $categorized_hooks_from_manager = frl_hook_get_all_persistent_hooks();

            if (!frl_is_array_not_empty($categorized_hooks_from_manager)) {
                return [];
            }

            // Group primarily by hook name ('tag')
            foreach ($categorized_hooks_from_manager as $context_name => $registrations_in_category) {
                if (!frl_is_array_not_empty($registrations_in_category)) continue;

                foreach ($registrations_in_category as $reg) {
                    if (!isset($reg['tag']) || !isset($reg['type']) || !isset($reg['callback'])) {
                        frl_log('Skipping malformed hook registration data: {data}', ['data' => $reg]);
                        continue;
                    }

                    $hook_name = $reg['tag'];
                    if (!isset($hooks_by_name[$hook_name])) {
                        $hooks_by_name[$hook_name] = [];
                    }

                    // --- Process Callback ---
                    $processed_callback = $reg['callback']; // Start with the original
                    if ($processed_callback instanceof \Closure) {
                        try {
                            $reflection = new ReflectionFunction($processed_callback);
                            $processed_callback = [
                                '__type' => 'closure',
                                'file' => $reflection->getFileName(),
                                'line' => $reflection->getStartLine()
                            ];
                        } catch (ReflectionException $e) {
                            // Fallback if reflection fails
                            $processed_callback = [
                                '__type' => 'closure',
                                'file' => 'N/A',
                                'line' => 'N/A'
                            ];
                            frl_log('ReflectionException for Closure: {error}', ['error' => $e->getMessage()]);
                        }
                    }
                    // --- End Process Callback ---

                    $hooks_by_name[$hook_name][] = [
                        'type'          => $reg['type'],
                        'priority'      => $reg['priority'] ?? 10,
                        'callback'      => $processed_callback, // Use the processed callback
                        'accepted_args' => $reg['accepted_args'] ?? ($reg['type'] === 'filter' ? 1 : 0),
                        'context'       => $reg['category'] ?? $context_name
                    ];
                }
            }

            // Sort registrations within each hook by priority
            foreach ($hooks_by_name as $hook_name => &$registrations) {
                usort($registrations, function ($a, $b) {
                    return $a['priority'] <=> $b['priority'];
                });
            }
            unset($registrations);

            // Final sweep: ensure there are no live Closures or object-method arrays left
            frl_sanitize_for_serialization($hooks_by_name);

            return $hooks_by_name;
        });
    }

    /**
     * Formats a hook callback into a readable string representation.
     * Handles various callback types including function names (strings),
     * static class methods (arrays), object methods (arrays), and Closures.
     *
     * @param mixed $callback The callback registered with the hook.
     * @param string|null $specific_hook_name Optional. The specific hook name if available (for dynamic hooks).
     * @return string A formatted string representing the callback.
     */
    private function format_callback($callback, $specific_hook_name = null)
    {
        if (is_string($callback)) {
            // Simple function name
            return $callback;
        } elseif (is_array($callback)) {
            // Check if it's our special closure metadata array
            if (isset($callback['__type']) && $callback['__type'] === 'closure') {
                $filename = basename($callback['file']);
                $line = $callback['line'];

                if ($filename !== 'N/A' && $line !== 'N/A') {
                    // Have file and line
                    if ($specific_hook_name) {
                        // Show specific name + location
                        return sprintf('Closure (%s) @ %s:%d', $specific_hook_name, $filename, $line);
                    } else {
                        // Show only location
                        return sprintf('Closure @ %s:%d', $filename, $line);
                    }
                } else {
                    // Missing file/line info
                    if ($specific_hook_name) {
                        // Show specific name + unavailable details
                        return sprintf('Closure (%s) (details unavailable)', $specific_hook_name);
                    } else {
                        // Show only unavailable details
                        return 'Closure (details unavailable)';
                    }
                }
            }
            // Otherwise, assume it's a Class method array: [ClassName|Object, MethodName]
            elseif (isset($callback[0]) && isset($callback[1])) {
                if (is_object($callback[0])) {
                    // Object method
                    return get_class($callback[0]) . '->' . $callback[1];
                } else {
                    // Static class method
                    return $callback[0] . '::' . $callback[1];
                }
            }
        } elseif ($callback instanceof \Closure) {
            // This case should ideally not be hit if pre-processing works,
            // but keep as a fallback during rendering just in case.
            return 'Closure (Live Object - Unexpected)';
        } elseif (is_object($callback) && method_exists($callback, '__invoke')) {
            // Object implementing __invoke
            return get_class($callback) . '::__invoke';
        }
        // Fallback for unknown callback types or malformed arrays
        return 'N/A';
    }

    /**
     * Gets the ordered list of hook names from BOTH reference data files.
     * Loads, merges, sorts, and caches the combined sequence.
     *
     * @return array An ordered list of hook tag names with metadata, including a 'source' key ('wordpress' or 'plugin').
     */
    private function get_ordered_hook_sequence()
    {
        // Cache key updated to reflect combined data
        return frl_cache_remember(
            'staticdata',
            'combined-hooks-reference',
            function () {
                $wordpress_reference_file = __DIR__ . '/reference-hooks-wordpress.php';
                $plugin_reference_file = __DIR__ . '/reference-hooks-plugins.php';

                $wordpress_hooks = [];
                $plugin_hooks = [];

                // Load WordPress hooks
                if (is_readable($wordpress_reference_file)) {
                    // Use require, not require_once, to allow re-evaluation if cache clears
                    $loaded_wp = require $wordpress_reference_file;
                    if (is_array($loaded_wp)) {
                        foreach ($loaded_wp as $name => $data) {
                            $data['source'] = 'wordpress'; // Add source identifier
                            $wordpress_hooks[$name] = $data;
                        }
                    } else {
                        frl_log('reference-hooks-wordpress.php did not return a valid array.');
                    }
                } else {
                    frl_log('reference-hooks-wordpress.php not found or not readable.');
                }

                // Load Plugin hooks
                if (is_readable($plugin_reference_file)) {
                    // Use require, not require_once
                    $loaded_plugin = require $plugin_reference_file;
                    if (is_array($loaded_plugin)) {
                        foreach ($loaded_plugin as $name => $data) {
                            if (isset($wordpress_hooks[$name])) {
                                // Handle potential name collision - log warning, perhaps prioritize plugin?
                                frl_log("Plugin hook '{name}' collides with a WordPress hook name in reference files.", ['name' => $name]);
                                // Decide on strategy: Overwrite with plugin version or skip? Let's overwrite for now.
                            }
                            $data['source'] = 'plugin'; // Add source identifier
                            $plugin_hooks[$name] = $data;
                        }
                    } else {
                        frl_log('reference-hooks-plugins.php did not return a valid array.');
                    }
                } else {
                    // Don't log an error if the plugin file doesn't exist, it might be intentional
                }

                // Merge the arrays (plugin hooks potentially overwrite WordPress hooks on collision)
                $combined_hooks = array_merge($wordpress_hooks, $plugin_hooks);

                // Sort the combined array by the 'order' key
                uasort($combined_hooks, function ($a, $b) {
                    $orderA = $a['order'] ?? PHP_INT_MAX; // Default high order if missing
                    $orderB = $b['order'] ?? PHP_INT_MAX;
                    return $orderA <=> $orderB;
                });

                return $combined_hooks; // Return the combined, sorted array
            }
        );
    }

    /**
     * Converts a wildcard hook pattern (e.g., 'prefix_{placeholder}') into a regex.
     *
     * @param string $pattern The wildcard pattern.
     * @return string|false The regex pattern (with delimiters) or false on error.
     */
    private function _wildcard_to_regex($pattern)
    {
        // Basic check for placeholder format
        if (!str_contains($pattern, '{') || !str_contains($pattern, '}')) {
            return false; // Not a valid wildcard pattern for this function
        }

        // Quote regex special characters in the pattern, EXCEPT for the placeholders
        $parts = preg_split('/(\{.*?\})/s', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE);
        $regex = '';
        foreach ($parts as $part) {
            if (preg_match('/^\{.*\}$/', $part)) {
                // It's a placeholder - replace with a non-greedy match for any character
                // Using [^/]+ might be safer if placeholders don't cross slashes
                // Or use a more specific pattern if needed, e.g., [a-zA-Z0-9_]+
                $regex .= '(.+?)'; // Non-greedy match for any character
            } else {
                // It's literal text - quote it
                $regex .= preg_quote($part, '/');
            }
        }

        // Add start/end anchors and delimiter
        return '/^' . $regex . '$/i'; // Case-insensitive
    }

    /**
     * Helper method to render a single table of hooks.
     *
     * @param string $title      The title to display above the table.
     * @param array  $hooks_data Array of hooks to display (grouped by hook name).
     * @param array  $headers    Array of table headers.
     * @param string $id         The ID for the table.
     * @param bool   $is_togglable Whether the table should be hidden and togglable.
     * @return void              Outputs HTML directly.
     */
    private function _render_hook_table($title, $hooks_data, $headers, $id, $is_togglable = false)
    {
        if (empty($hooks_data)) {
            return; // Don't render empty tables
        }

        // Use the provided ID for the toggle target if togglable
        $table_id = $is_togglable ? $id : ('hooks-table-' . sanitize_title($title));

        if ($is_togglable) {
            // --- Render Toggled Header ---
            $toggle_button = frl_ui_render_toggle_button(
                __('Show Details', FRL_PREFIX),
                $table_id,
                'button-small align-right'
            );
            echo frl_ui_render_header_with_action(
                $title,
                $toggle_button,
                'hooks-header'
            );
            // Start the togglable container div (initially hidden)
            echo '<div id="' . esc_attr($table_id) . '" class="widget-section frl-hooks-table-container" style="display:none;">';
        } else {
            // --- Render Simple Header ---
            echo '<h3>' . esc_html($title) . '</h3>';
            // Start a non-hidden container (optional, but good for structure)
            echo '<div class="widget-section frl-hooks-table-container">';
        }

        $table_content = '';

        // Add table header row
        $table_content .= frl_ui_render_multi_column_header($headers, 'hooks-header-row');

        foreach ($hooks_data as $hook_name => $registrations) {
            $first_row_for_hook = true;
            $total_registrations = count($registrations);
            $current_reg_index = 0;

            foreach ($registrations as $reg) {
                $current_reg_index++;
                $row_classes = []; // Array to hold classes

                // --- Grouping Classes ---
                if ($total_registrations === 1) {
                    // Single row group
                    $row_classes[] = 'hook-group-single';
                } else {
                    // Multi-row group
                    if ($first_row_for_hook) {
                        $row_classes[] = 'hook-group-start';
                    } elseif ($current_reg_index === $total_registrations) {
                        $row_classes[] = 'hook-group-end';
                    } else {
                        $row_classes[] = 'hook-group-member'; // Class for intermediate rows
                    }
                }

                // --- Other Classes ---
                // Add hook type class
                $hook_type = strtolower($reg['type'] ?? 'unknown');
                $row_classes[] = 'hook-type-' . sanitize_html_class($hook_type);
                // Add sequence context class (using injected data)
                $sequence_context = strtolower($reg['sequence_context'] ?? 'unknown');
                $row_classes[] = 'hook-context-' . sanitize_html_class($sequence_context);

                // Build 7-column row: Hook | Type | Callback | Δms | Priority | Args | Context
                // Build lookup key that matches the profiler storage
                $lookup_tag = $reg['original_tag'] ?? $hook_name; // Use real executed tag for wildcard hooks

                // Rebuild callback string if it was serialised for storage
                $lookup_cb = $reg['callback'];
                if (is_array($lookup_cb) && isset($lookup_cb['__type'])) {
                    switch ($lookup_cb['__type']) {
                        case 'object_method':
                            if (isset($lookup_cb['class'], $lookup_cb['method'])) {
                                $lookup_cb = $lookup_cb['class'] . '->' . $lookup_cb['method'];
                            }
                            break;
                        case 'object':
                            if (isset($lookup_cb['class'])) {
                                $lookup_cb = $lookup_cb['class'] . '::__invoke';
                            }
                            break;
                        case 'closure':
                            $lookup_cb = 'Closure';
                            break;
                        default:
                            $lookup_cb = Frl_Hook_Manager::callback_to_string($lookup_cb);
                    }
                }

                $avg = Frl_Hook_Manager::get_avg_exec_time_persisted($lookup_tag, $lookup_cb);
                $time_disp = ($avg !== null) ? sprintf('%.1f', $avg) : '—';

                $columns = [
                    '', // Hook name (filled later)
                    esc_html(ucfirst($reg['type'])),
                    '', // Callback (filled later)
                    esc_html($time_disp), // Δ ms
                    esc_html($reg['priority']),
                    '', // Args (filled later)
                    esc_html($reg['context'] ?? 'N/A')
                ];

                // --- Add Hook Name Column (Pattern on First Row, Truncated) ---
                $pattern_name = $hook_name; // The group key is the full pattern name
                $display_name = mb_strimwidth($pattern_name, 0, 32, '...'); // Truncate for display
                // Use full name for title, truncated name for display text
                $hook_cell_html = '<span title="' . esc_attr($pattern_name) . '">' . esc_html($display_name) . '</span>';
                // Assign the pattern name HTML to the first column, ONLY for the first row of the group.
                $columns[0] = ($first_row_for_hook) ? '<strong>' . $hook_cell_html . '</strong>' : '';

                // --- Add Callback Column ---
                $specific_name = $reg['original_tag'] ?? null; // Get the specific hook name if it exists (from wildcard match)
                $columns[2] = esc_html($this->format_callback($reg['callback'], $specific_name));

                // --- Add Args Column (Default(Passed)) ---
                $default_args = $reg['sequence_args'] ?? '?'; // Default args injected from render() or ? if unknown
                $passed_args = $reg['accepted_args'];
                $args_html = '<span class="hook-args-default" title="Default Args">' . esc_html($default_args) . '</span> <span class="hook-args-passed" title="Passed Args">(' . esc_html($passed_args) . ')</span>';
                // Check for mismatch, ignoring if default is unknown ('?')
                // Use loose comparison (!=) to handle potential type differences (int vs string)
                if ($default_args !== '?' && $default_args != $passed_args) {
                    $columns[5] = '<span class="hook-args-mismatch">' . $args_html . '</span>'; // Add wrapper span with mismatch class
                } else {
                    $columns[5] = $args_html; // Output without wrapper
                }

                $row_class = implode(' ', $row_classes);
                $table_content .= frl_ui_render_multi_column_row($columns, false, $row_class);
                $first_row_for_hook = false; // Set to false after the first iteration
            }
        }

        // Render the complete table wrapper using the provided ID
        echo frl_ui_render_table(
            $id,
            $table_content,
            'frl-hooks-table',
            WEEK_IN_SECONDS,
            false,
            'staticdata'
        );

        // Close the container div (hidden or not)
        echo '</div>';
    }

    /**
     * Helper method to render the WordPress Hook Reference table.
     * Filters the combined sequence data for WordPress hooks.
     *
     * @param string $title      The title to display above the table.
     * @param array  $combined_sequence_data Array of combined hook reference data.
     * @param bool   $is_togglable Whether the table should be hidden and togglable.
     * @return void              Outputs HTML directly.
     */
    private function _render_reference_table($title, $combined_sequence_data, $is_togglable = false)
    {
        // Filter for WordPress hooks
        $wordpress_hooks_data = array_filter($combined_sequence_data, function ($metadata) {
            // Include if source is 'wordpress' or missing (legacy compatibility)
            return !isset($metadata['source']) || $metadata['source'] === 'wordpress';
        });

        if (empty($wordpress_hooks_data)) {
            echo frl_ui_render_validation_message(__('WordPress Hook Reference data is missing or empty.', FRL_PREFIX), 'warning');
            return;
        }

        // Call the generic reference rendering method with filtered data
        $this->_render_generic_reference_table(
            $title,
            $wordpress_hooks_data,
            'wordpress-hooks',
            $is_togglable
        );
    }

    /**
     * Helper method to render the Plugin Hook Reference table.
     * Filters the combined sequence data for plugin-specific hooks.
     *
     * @param string $title      The title to display above the table.
     * @param array  $combined_sequence_data Array of combined hook reference data.
     * @param bool   $is_togglable Whether the table should be hidden and togglable.
     * @return void              Outputs HTML directly.
     */
    private function _render_plugin_reference_table($title, $combined_sequence_data, $is_togglable = false)
    {
        // Filter for plugin hooks
        $plugin_hooks_data = array_filter($combined_sequence_data, function ($metadata) {
            return isset($metadata['source']) && $metadata['source'] === 'plugin';
        });

        if (empty($plugin_hooks_data)) {
            // Optional: Display a message if no plugin hooks are defined in the reference
            // echo frl_ui_render_validation_message(__('No plugin-specific hooks found in reference data.', FRL_PREFIX), 'info');
            return;
        }

        // Call the generic reference rendering method with filtered data
        $this->_render_generic_reference_table(
            $title,
            $plugin_hooks_data,
            'plugin-hooks',
            $is_togglable
        );
    }

    /**
     * Generic helper method to render a reference table (WordPress or Plugin).
     * Contains the core rendering logic previously in _render_reference_table.
     *
     * @param string $title         The title to display above the table.
     * @param array  $sequence_data Array of hook reference data (filtered by source).
     * @param string $base_id       Base ID for the table and toggle button.
     * @param bool   $is_togglable  Whether the table should be hidden and togglable.
     * @return void                 Outputs HTML directly.
     */
    private function _render_generic_reference_table(
        $title,
        $sequence_data,
        $base_id,
        $is_togglable = false
    ) {
        // ** Start Copy from original _render_reference_table **
        if (empty($sequence_data)) {
            // This check might be redundant as filtering happens before, but good safety.
            echo frl_ui_render_validation_message(
                sprintf(__('Hook Reference data for "%s" is empty.', FRL_PREFIX), esc_html($base_id)),
                'warning'
            );
            return;
        }

        $table_id = $base_id . '-table'; // Use base_id for the table ID

        if ($is_togglable) {
            // --- Render Toggled Header ---
            $toggle_button = frl_ui_render_toggle_button(
                __('Show Details', FRL_PREFIX),
                $table_id, // Use derived table_id
                'button-small align-right'
            );
            echo frl_ui_render_header_with_action(
                $title,
                $toggle_button,
                $base_id . '-header' // Use base_id for header class/ID
            );
            // Start the togglable container div (initially hidden)
            echo '<div id="' . esc_attr($table_id) . '" class="widget-section frl-hooks-table-container" style="display:none;">';
        } else {
            // --- Render Simple Header ---
            echo '<h3>' . esc_html($title) . '</h3>';
            // Start a non-hidden container
            echo '<div class="widget-section frl-hooks-table-container">';
        }

        $table_content = '';

        // Define headers for the reference table based on available data
        $headers = [
            esc_html__('Hook', FRL_PREFIX),
            esc_html__('Type', FRL_PREFIX),
            esc_html__('Description', FRL_PREFIX),
            esc_html__('Context', FRL_PREFIX),
            esc_html__('Args', FRL_PREFIX),
            esc_html__('Order', FRL_PREFIX),
            esc_html__('Source', FRL_PREFIX) // Add Source column
        ];

        // Add table header row
        $table_content .= frl_ui_render_multi_column_header($headers, 'reference-hooks-header-row');

        // Iterate through the sequence data (already sorted)
        foreach ($sequence_data as $hook_name => $metadata) {
            // Construct dynamic classes
            $row_classes = [];
            $hook_type = strtolower($metadata['hook_type'] ?? 'unknown');
            $row_classes[] = 'hook-type-' . sanitize_html_class($hook_type);
            $sequence_context = strtolower($metadata['context'] ?? 'unknown');
            $row_classes[] = 'hook-context-' . sanitize_html_class($sequence_context);
            $source = strtolower($metadata['source'] ?? 'unknown'); // Get source
            $row_classes[] = 'hook-source-' . sanitize_html_class($source); // Add source class
            $row_class = implode(' ', $row_classes);

            $columns = [
                '', // Placeholder for Hook Name
                esc_html(ucfirst($metadata['hook_type'] ?? 'N/A')),
                esc_html($metadata['description'] ?? 'N/A'),
                esc_html($metadata['context'] ?? 'unknown'),
                esc_html($metadata['args'] ?? '?'),
                esc_html($metadata['order'] ?? 'N/A'), // Revert back to showing order
                esc_html(ucfirst($source)) // Display Source
            ];
            // --- Add Hook Name Column (Truncated) ---
            $display_name_ref = mb_strimwidth($hook_name, 0, 32, '...'); // Truncate
            $hook_cell_html_ref = '<span title="' . esc_attr($hook_name) . '">' . esc_html($display_name_ref) . '</span>'; // Create span
            $columns[0] = '<strong>' . $hook_cell_html_ref . '</strong>';
            // Use the dynamic row class string
            $table_content .= frl_ui_render_multi_column_row($columns, false, $row_class);
        }

        // Render the complete table wrapper using the derived $table_id
        echo frl_ui_render_table(
            $base_id,
            $table_content,
            'frl-hooks-table',
            WEEK_IN_SECONDS,
            false,
            'staticdata'
        );

        // Close the container div
        echo '</div>';
        // ** End Copy **
    }

    /**
     * Helper method to render the Profiler Data Table.
     *
     * @param string $title The title to display above the table.
     * @param array  $profiler_data Array of profiler data.
     * @param string $id The ID for the table.
     * @return void Outputs HTML directly.
     */
    private function _render_profiler_table($title, $profiler_data, $id = 'hook-profiler', $is_togglable = false, $initially_hidden = true)
    {
        if (empty($profiler_data)) {
            return;
        }

        // Build header / toggle
        $table_container_id = $is_togglable ? $id.'-table' : $id;

        if ($is_togglable) {
            $toggle_button = frl_ui_render_toggle_button(__('Show Details', FRL_PREFIX), $table_container_id, 'button-small align-right');
            echo frl_ui_render_header_with_action($title, $toggle_button, $id.'-header');
            $style = $initially_hidden ? ' style="display:none;"' : '';
            echo '<div id="'.esc_attr($table_container_id).'" class="widget-section frl-hooks-table-container"'.$style.'>';
        } else {
            echo '<h3>'.esc_html($title).'</h3><div class="widget-section frl-hooks-table-container">';
        }

        // Table headers: Hook | Type | Callback | Δ ms | Max ms | Calls
        $headers = [
            esc_html__('Hook', FRL_PREFIX),
            esc_html__('Type', FRL_PREFIX),
            esc_html__('Callback', FRL_PREFIX),
            esc_html__('Avg ms', FRL_PREFIX),
            esc_html__('Max ms', FRL_PREFIX),
            esc_html__('Calls', FRL_PREFIX),
        ];

        $table_content = frl_ui_render_multi_column_header($headers, 'profiler-header-row');

        foreach ($profiler_data as $entry) {
            $avgDisp = sprintf('%.2f', $entry['avg']);
            if ($entry['avg'] >= 10) {
                $avgDisp = '<span class="hook-above-avg">' . esc_html($avgDisp) . '</span>';
            } else {
                $avgDisp = esc_html($avgDisp);
            }

            $maxDisp = '—';
            if (isset($entry['max']) && $entry['max'] !== null) {
                $maxDisp = sprintf('%.2f', $entry['max']);
                if ($entry['max'] >= 25) {
                    $maxDisp = '<span class="hook-above-avg">' . esc_html($maxDisp) . '</span>';
                } else {
                    $maxDisp = esc_html($maxDisp);
                }
            }

            $columns = [
                esc_html($entry['tag']),
                esc_html(ucfirst($entry['type'])),
                esc_html($entry['callback']),
                $avgDisp,
                $maxDisp,
                esc_html($entry['calls']),
            ];

            $row_class = 'hook-type-' . sanitize_html_class(strtolower($entry['type']));
            $table_content .= frl_ui_render_multi_column_row($columns, false, $row_class);
        }

        echo frl_ui_render_table(
            $table_container_id,
            $table_content,
            'frl-hooks-table',
            WEEK_IN_SECONDS,
            false,
            'staticdata'
        );

        echo '</div>';
    }

    /**
     * Helper method to get profiler data.
     *
     * @param int $limit The number of entries to return.
     * @return array Array of profiler data.
     */
    private function _get_profiler_data(int $limit = 25): array
    {
        if (!function_exists('frl_cache_get')) {
            return [];
        }
        $map = frl_cache_get('staticdata', 'hook_profiler_avg', null);
        if (!$map || !is_array($map)) {
            return [];
        }

        // Build helper map tag|cb => type
        $type_map = [];
        if (class_exists('Frl_Hook_Manager')) {
            $all_hooks = Frl_Hook_Manager::get_all_persistent_hooks();
            foreach ($all_hooks as $cat => $hooks) {
                foreach ($hooks as $h) {
                    $tkey = $h['tag'] . '|' . Frl_Hook_Manager::callback_to_string($h['callback']);
                    $type_map[$tkey] = $h['type'] ?? 'unknown';
                }
            }
        }
        $data = [];
        foreach ($map as $key => $stats) {
            [$tag, $cb] = array_pad(explode('|', $key, 2), 2, '');
            $avg = $stats['total'] / max(1, $stats['count']);
            $ptype = $type_map[$key] ?? 'unknown';
            $maxv = isset($stats['max']) ? (float)$stats['max'] : null;
            $data[] = [
                'tag' => $tag,
                'callback' => $cb,
                'type' => $ptype,
                'avg' => $avg,
                'max' => $maxv,
                'calls' => $stats['count'],
            ];
        }
        // Sort by avg desc
        usort($data, function ($a, $b) {
            return $b['avg'] <=> $a['avg'];
        });
        return array_slice($data, 0, $limit);
    }

    private function _get_conflicts_data(): array
    {
        // Build detailed conflicts list tag=>prio=>[callbacks]
        $detailed = [];
        if (!class_exists('Frl_Hook_Manager')) return $detailed;

        $all = Frl_Hook_Manager::get_all_persistent_hooks();
        foreach ($all as $cat => $hooks) {
            foreach ($hooks as $h) {
                $tag = $h['tag'];
                $prio = $h['priority'] ?? 10;
                $cbstr = Frl_Hook_Manager::callback_to_string($h['callback']);
                $detailed[$tag][$prio][] = $cbstr;
            }
        }

        // Keep only entries where duplicates exist
        foreach ($detailed as $tag=>&$prioMap) {
            foreach ($prioMap as $prio=>&$list) {
                if (count($list) <= 1) {
                    unset($prioMap[$prio]);
                }
            }
            if (empty($prioMap)) unset($detailed[$tag]);
        }
        unset($prioMap);
        return $detailed;
    }

    private function _render_conflicts_table($title, $conflict_map, $id = 'hook-conflicts', $is_togglable = false, $initially_hidden = true)
    {
        if (empty($conflict_map)) return;
        $container_id = $is_togglable ? $id.'-table' : $id;

        if ($is_togglable) {
            $toggle_button = frl_ui_render_toggle_button(__('Show Details', FRL_PREFIX), $container_id, 'button-small align-right');
            echo frl_ui_render_header_with_action($title, $toggle_button, $id.'-header');
            $style = $initially_hidden ? ' style="display:none;"' : '';
            echo '<div id="'.esc_attr($container_id).'" class="widget-section frl-hooks-table-container"'.$style.'>';
        } else {
            echo '<h3>'.esc_html($title).'</h3><div class="widget-section frl-hooks-table-container">';
        }

        $headers = [
            esc_html__('Hook', FRL_PREFIX),
            esc_html__('Priority', FRL_PREFIX),
            esc_html__('Callbacks', FRL_PREFIX),
            esc_html__('Count', FRL_PREFIX),
        ];
        $table_content = frl_ui_render_multi_column_header($headers, 'conflict-header-row');
        foreach ($conflict_map as $tag => $prioMap) {
            foreach ($prioMap as $prio => $callbacks) {
                $cnt = count($callbacks);
                $cntDisp = ($cnt >= 7)
                    ? '<span class="hook-overlap-critical">' . esc_html($cnt) . '</span>'
                    : esc_html($cnt);

                $columns = [
                    esc_html($tag),
                    esc_html($prio),
                    esc_html(implode(', ', $callbacks)),
                    $cntDisp,
                ];
                $table_content .= frl_ui_render_multi_column_row($columns, false, 'hook-conflict-row');
            }
        }
        echo frl_ui_render_table(
            $container_id,
            $table_content,
            'frl-hooks-table',
            WEEK_IN_SECONDS,
            false,
            'staticdata'
        );
        echo '</div>';
    }

    /**
     * Renders the HTML display of registered plugins,
     * categorized into tables based on context (Admin, Frontend Logged-In, Frontend Logged-Out).
     * Hooks are ordered based on the sequence defined in wordpress-hook-reference.php.
     * Callbacks within each hook group are sorted by priority.
     * Uses the renderer's div-based structure for consistency.
     *
     * @return void Outputs HTML directly to the page.
     */
    public function render()
    {
        // Get combined data first
        $hooks_by_name = $this->get_hooks_data_grouped_by_name(); // All registered hooks, grouped
        $combined_hook_sequence_data = $this->get_ordered_hook_sequence(); // Combined & sorted sequence data

        if (empty($hooks_by_name) && empty($combined_hook_sequence_data)) {
            echo frl_ui_render_validation_message(
                __('No registered hooks found and reference data is missing.', FRL_PREFIX),
                'info'
            );
            return;
        } elseif (empty($hooks_by_name)) {
            echo frl_ui_render_validation_message(
                __('No hooks registered via Frl Hook Manager found.', FRL_PREFIX),
                'info'
            );
            // Still proceed to render reference tables if sequence data exists
        }

        if (empty($combined_hook_sequence_data)) {
            echo frl_ui_render_validation_message(
                __('Combined hook execution sequence reference data is missing or invalid. Hooks cannot be fully categorized by context.', FRL_PREFIX),
                'warning'
            );
            // Fallback: Render a single alphabetical table if sequence is missing and hooks exist
            if (!empty($hooks_by_name)) {
                ksort($hooks_by_name);
                $headers = [
                    esc_html__('Hook', FRL_PREFIX),
                    esc_html__('Type', FRL_PREFIX),
                    esc_html__('Callback', FRL_PREFIX),
                    esc_html__('Priority', FRL_PREFIX),
                    esc_html__('Args', FRL_PREFIX),
                    esc_html__('Context', FRL_PREFIX)
                ];
                $this->_render_hook_table(esc_html__('Registered Hooks (Alphabetical - Reference Missing)', FRL_PREFIX), $hooks_by_name, $headers, 'registered-hooks-fallback', false);
            }
            // Don't render reference tables if data is missing
            return;
        }

        // Define table headers (6 columns) - Same for all tables
        $headers = [
            esc_html__('Hook', FRL_PREFIX),
            esc_html__('Type', FRL_PREFIX),
            esc_html__('Callback', FRL_PREFIX),
            esc_html__('Δ ms', FRL_PREFIX),
            esc_html__('Priority', FRL_PREFIX),
            esc_html__('Args', FRL_PREFIX),
            esc_html__('Context', FRL_PREFIX)
        ];

        // Initialize categorized arrays
        $admin_hooks = [];
        $frontend_logged_in_hooks = [];
        $frontend_logged_out_hooks = [];
        $other_hooks = []; // For hooks not in sequence
        $processed_registrations = []; // Track specific registrations: [$hook_name => [$reg_index => true]]
        $wildcard_sequence_data = []; // Store wildcard patterns separately

        // --- Pass 1: Categorize Exact Matches & Separate Wildcards ---
        // Use the combined sequence data
        foreach ($combined_hook_sequence_data as $sequence_hook_name => $metadata) {
            // Check if it's likely a wildcard pattern
            if (str_contains($sequence_hook_name, '{') && str_contains($sequence_hook_name, '}')) {
                $regex = $this->_wildcard_to_regex($sequence_hook_name);
                if ($regex) {
                    // Store the original name, metadata, and the generated regex
                    $wildcard_sequence_data[$sequence_hook_name] = [
                        'metadata' => $metadata,
                        'regex'    => $regex
                    ];
                } // else: conversion failed, treat as non-wildcard
                continue; // Move to next item in sequence (don't process wildcards for exact matches here)
            }

            // Process exact matches using $hooks_by_name
            if (isset($hooks_by_name[$sequence_hook_name])) {
                $registrations = $hooks_by_name[$sequence_hook_name];
                // Ensure metadata has expected keys before using them
                $sequence_user_logged = $metadata['user_logged'] ?? 'unknown';
                $sequence_context_ref = $metadata['context'] ?? 'unknown'; // Context from reference
                $sequence_args_ref = $metadata['args'] ?? '?'; // Args from reference, default '?'

                foreach ($registrations as $reg_index => $reg) {
                    // Prioritize the category set during registration in Frl Hook Manager
                    $manager_category = $reg['context'] ?? 'unknown'; // e.g., 'admin', 'public', 'core'

                    // Inject reference data for potential display later
                    $registrations[$reg_index]['sequence_context'] = $sequence_context_ref;
                    $registrations[$reg_index]['sequence_user_logged'] = $sequence_user_logged;
                    $registrations[$reg_index]['sequence_args'] = $sequence_args_ref; // Inject Default Args

                    $added_to_category = false;
                    // Assign to categories based on reference context first, then consider manager_category
                    if ($sequence_context_ref === 'admin') {
                        // Admin hooks should always go to admin table
                        if (!isset($admin_hooks[$sequence_hook_name])) $admin_hooks[$sequence_hook_name] = [];
                        $admin_hooks[$sequence_hook_name][$reg_index] = $registrations[$reg_index];
                        $added_to_category = true;
                    } else if ($sequence_context_ref === 'public') {
                        // Public hooks go to frontend tables based on user logged status
                        if ($sequence_user_logged === 'logged-in' || $sequence_user_logged === 'both') {
                            if (!isset($frontend_logged_in_hooks[$sequence_hook_name])) $frontend_logged_in_hooks[$sequence_hook_name] = [];
                            $frontend_logged_in_hooks[$sequence_hook_name][$reg_index] = $registrations[$reg_index];
                            $added_to_category = true;
                        }
                        if ($sequence_user_logged === 'logged-out' || $sequence_user_logged === 'both') {
                            if (!isset($frontend_logged_out_hooks[$sequence_hook_name])) $frontend_logged_out_hooks[$sequence_hook_name] = [];
                            $frontend_logged_out_hooks[$sequence_hook_name][$reg_index] = $registrations[$reg_index];
                            $added_to_category = true;
                        }
                    } else if ($sequence_context_ref === 'core') {
                        // Core hooks can appear in multiple contexts - respect both reference and manager category
                        // First, check if the manager category gives us direction on where to place the hook
                        if (isset(FRL_HOOKS_CONTEXT_MAP[$manager_category])) {
                            foreach (FRL_HOOKS_CONTEXT_MAP[$manager_category] as $table) {
                                // Add to admin table if manager category includes admin
                                if ($table === 'admin') {
                                    if (!isset($admin_hooks[$sequence_hook_name])) $admin_hooks[$sequence_hook_name] = [];
                                    $admin_hooks[$sequence_hook_name][$reg_index] = $registrations[$reg_index];
                                    $added_to_category = true;
                                }
                                // Add to frontend logged in table if appropriate
                                if (
                                    $table === 'frontend_logged_in' &&
                                    ($sequence_user_logged === 'logged-in' || $sequence_user_logged === 'both')
                                ) {
                                    if (!isset($frontend_logged_in_hooks[$sequence_hook_name])) {
                                        $frontend_logged_in_hooks[$sequence_hook_name] = [];
                                    }
                                    $frontend_logged_in_hooks[$sequence_hook_name][$reg_index] = $registrations[$reg_index];
                                    $added_to_category = true;
                                }
                                // Add to frontend logged out table if appropriate
                                if (
                                    $table === 'frontend_logged_out' &&
                                    ($sequence_user_logged === 'logged-out' || $sequence_user_logged === 'both')
                                ) {
                                    if (!isset($frontend_logged_out_hooks[$sequence_hook_name])) {
                                        $frontend_logged_out_hooks[$sequence_hook_name] = [];
                                    }
                                    $frontend_logged_out_hooks[$sequence_hook_name][$reg_index] = $registrations[$reg_index];
                                    $added_to_category = true;
                                }
                            }
                        } else {
                            // If no specific manager category mapping, use the user_logged info
                            if ($sequence_user_logged === 'logged-in' || $sequence_user_logged === 'both') {
                                if (!isset($frontend_logged_in_hooks[$sequence_hook_name])) {
                                    $frontend_logged_in_hooks[$sequence_hook_name] = [];
                                }
                                $frontend_logged_in_hooks[$sequence_hook_name][$reg_index] = $registrations[$reg_index];
                                $added_to_category = true;
                            }
                            if ($sequence_user_logged === 'logged-out' || $sequence_user_logged === 'both') {
                                if (!isset($frontend_logged_out_hooks[$sequence_hook_name])) {
                                    $frontend_logged_out_hooks[$sequence_hook_name] = [];
                                }
                                $frontend_logged_out_hooks[$sequence_hook_name][$reg_index] = $registrations[$reg_index];
                                $added_to_category = true;
                            }
                        }
                    } else {
                        // Unknown context - fall back to manager_category logic
                        foreach (FRL_HOOKS_CONTEXT_MAP[$manager_category] ?? [] as $table) {
                            if ($table === 'admin') {
                                if (!isset($admin_hooks[$sequence_hook_name])) $admin_hooks[$sequence_hook_name] = [];
                                $admin_hooks[$sequence_hook_name][$reg_index] = $registrations[$reg_index];
                                $added_to_category = true;
                            }
                            if ($table === 'frontend_logged_in' && ($sequence_user_logged === 'logged-in' || $sequence_user_logged === 'both')) {
                                if (!isset($frontend_logged_in_hooks[$sequence_hook_name])) $frontend_logged_in_hooks[$sequence_hook_name] = [];
                                $frontend_logged_in_hooks[$sequence_hook_name][$reg_index] = $registrations[$reg_index];
                                $added_to_category = true;
                            }
                            if ($table === 'frontend_logged_out' && ($sequence_user_logged === 'logged-out' || $sequence_user_logged === 'both')) {
                                if (!isset($frontend_logged_out_hooks[$sequence_hook_name])) $frontend_logged_out_hooks[$sequence_hook_name] = [];
                                $frontend_logged_out_hooks[$sequence_hook_name][$reg_index] = $registrations[$reg_index];
                                $added_to_category = true;
                            }
                        }
                    }

                    if ($added_to_category) {
                        if (!isset($processed_registrations[$sequence_hook_name])) $processed_registrations[$sequence_hook_name] = [];
                        $processed_registrations[$sequence_hook_name][$reg_index] = true; // Mark specific registration as processed
                    }
                }
            }
        }

        // --- Pass 2: Categorize Wildcard Matches ---
        $wildcard_processed_registrations = []; // Format: [$original_hook_name => [$reg_index => true]]

        // Iterate through all registered hooks ($hooks_by_name) to check against wildcards
        if (!empty($hooks_by_name)) { // Check if there are registered hooks
            foreach ($hooks_by_name as $registered_hook_name => $registrations) {
                foreach ($registrations as $reg_index => $reg) {
                    // Skip if this registration was already processed by an exact match
                    if (isset($processed_registrations[$registered_hook_name][$reg_index])) {
                        continue;
                    }

                    foreach ($wildcard_sequence_data as $wildcard_name => $wildcard_info) {
                        if (preg_match($wildcard_info['regex'], $registered_hook_name)) {
                            $metadata = $wildcard_info['metadata'];
                            // Ensure metadata keys exist before use
                            $sequence_user_logged = $metadata['user_logged'] ?? 'unknown';
                            $sequence_context_ref = $metadata['context'] ?? 'unknown'; // Context from reference
                            $sequence_args_ref = $metadata['args'] ?? '?'; // Args from reference, default '?'
                            $manager_category = $reg['context'] ?? 'unknown'; // Category from Hook Manager

                            // Prepare a copy: Inject metadata and original tag
                            $reg_copy = $reg;
                            $reg_copy['sequence_context'] = $sequence_context_ref;
                            $reg_copy['sequence_user_logged'] = $sequence_user_logged;
                            $reg_copy['sequence_args'] = $sequence_args_ref; // Inject Default Args
                            $reg_copy['original_tag'] = $registered_hook_name; // Store the original hook name

                            $added_to_category = false;
                            // Categorize based on manager_category and reference user status
                            foreach (FRL_HOOKS_CONTEXT_MAP[$manager_category] ?? [] as $table) {
                                if ($table === 'admin') {
                                    if (!isset($admin_hooks[$wildcard_name])) $admin_hooks[$wildcard_name] = [];
                                    $admin_hooks[$wildcard_name][] = $reg_copy; // Append registration
                                    $added_to_category = true;
                                }
                                if ($table === 'frontend_logged_in' && ($sequence_user_logged === 'logged-in' || $sequence_user_logged === 'both')) {
                                    if (!isset($frontend_logged_in_hooks[$wildcard_name])) $frontend_logged_in_hooks[$wildcard_name] = [];
                                    $frontend_logged_in_hooks[$wildcard_name][] = $reg_copy; // Append registration
                                    $added_to_category = true;
                                }
                                if ($table === 'frontend_logged_out' && ($sequence_user_logged === 'logged-out' || $sequence_user_logged === 'both')) {
                                    if (!isset($frontend_logged_out_hooks[$wildcard_name])) $frontend_logged_out_hooks[$wildcard_name] = [];
                                    $frontend_logged_out_hooks[$wildcard_name][] = $reg_copy; // Append registration
                                    $added_to_category = true;
                                }
                            }

                            if ($added_to_category) {
                                if (!isset($wildcard_processed_registrations[$registered_hook_name])) $wildcard_processed_registrations[$registered_hook_name] = [];
                                $wildcard_processed_registrations[$registered_hook_name][$reg_index] = true; // Mark original registration as processed by wildcard
                                $matched_wildcard = true;
                                break; // Stop checking other wildcards for this registration
                            }
                        }
                    }
                }
            }
        }

        // --- Pass 3: Find Remaining Other Hooks ---
        // Hooks/registrations not processed by exact or wildcard match
        if (!empty($hooks_by_name)) { // Check if there are registered hooks
            foreach ($hooks_by_name as $hook_name => $registrations) {
                foreach ($registrations as $reg_index => $reg) {
                    $is_processed_exact = isset($processed_registrations[$hook_name][$reg_index]);
                    $is_processed_wildcard = isset($wildcard_processed_registrations[$hook_name][$reg_index]);

                    if (!$is_processed_exact && !$is_processed_wildcard) {
                        // This specific registration wasn't categorized yet
                        // Ensure sequence context is at least 'unknown' if hook wasn't in reference
                        $reg['sequence_context'] = $reg['sequence_context'] ?? 'unknown';
                        $reg['sequence_args'] = $reg['sequence_args'] ?? '?'; // Ensure sequence_args exists, default '?'
                        $reg['sequence_user_logged'] = $reg['sequence_user_logged'] ?? 'unknown';

                        // Add to other_hooks, group by hook name
                        if (!isset($other_hooks[$hook_name])) {
                            $other_hooks[$hook_name] = [];
                        }
                        $other_hooks[$hook_name][] = $reg; // Append the registration
                    }
                }
            }
            // Sort the final other_hooks alphabetically by name and priority
            if (!empty($other_hooks)) {
                ksort($other_hooks);
                foreach ($other_hooks as &$regs) { // Sort registrations within each hook by priority
                    if (is_array($regs)) { // Check if $regs is an array before sorting
                        usort($regs, function ($a, $b) {
                            return ($a['priority'] ?? 10) <=> ($b['priority'] ?? 10);
                        });
                    }
                }
                unset($regs);
            }
        }

        // --- Sorting Categorized Hooks ---
        // Sort the main categories by the reference sequence order
        // This helper function preserves the sequence order from the combined reference
        $sort_by_sequence = function ($hooks_array) use ($combined_hook_sequence_data) {
            if (empty($hooks_array)) return []; // Return early if empty

            // Create a sorted array to return
            $sorted = [];

            // First, get all hook names and their sequence orders
            $hook_orders = [];
            foreach (array_keys($hooks_array) as $hook_name) {
                // Get order from reference or use max value if not found
                $order = isset($combined_hook_sequence_data[$hook_name]['order'])
                    ? (float)$combined_hook_sequence_data[$hook_name]['order']
                    : PHP_INT_MAX;

                $hook_orders[$hook_name] = $order;
            }

            // Sort by order value
            asort($hook_orders, SORT_NUMERIC);

            // Build the sorted output array using the sorted order
            foreach (array_keys($hook_orders) as $hook_name) {
                $sorted[$hook_name] = $hooks_array[$hook_name];

                // Sort registrations within each hook by priority
                usort($sorted[$hook_name], function ($a, $b) {
                    return ($a['priority'] ?? 10) <=> ($b['priority'] ?? 10);
                });
            }

            return $sorted;
        };

        $frontend_logged_out_hooks = $sort_by_sequence($frontend_logged_out_hooks);
        $frontend_logged_in_hooks = $sort_by_sequence($frontend_logged_in_hooks);
        $admin_hooks = $sort_by_sequence($admin_hooks);
        // 'other_hooks' are already sorted alphabetically by name and priority


        echo frl_ui_render_section_header(__('Registered Hooks - Profiler & Firing Sequence', FRL_PREFIX), 'has-section-title');

        // --- Profiler Status Notice & Tables ---
        $profiler_enabled = $this->_render_profiler_status_notice();

        // Only show profiler and conflict tables if profiler is enabled
        if ($profiler_enabled) {
            $profiler_data = $this->_get_profiler_data();
            if (!empty($profiler_data)) {
                $this->_render_profiler_table(esc_html__('Hooks Profiler', FRL_PREFIX), $profiler_data, 'hook-profiler', true, false);
            }

            $conflict_map = $this->_get_conflicts_data();
            if (!empty($conflict_map)) {
                $this->_render_conflicts_table(esc_html__('Hooks Priority Overlap', FRL_PREFIX), $conflict_map, 'hook-overlaps', true, false);
            }
        }

        // --- Render the Registered Hooks Tables ---
        $this->_render_hook_table(esc_html__('Frontend Hooks (Logged-Out)', FRL_PREFIX), $frontend_logged_out_hooks, $headers, 'frontend-logged-out', true);
        $this->_render_hook_table(esc_html__('Frontend Hooks (Logged-In)', FRL_PREFIX), $frontend_logged_in_hooks, $headers, 'frontend-logged-in', true);
        $this->_render_hook_table(esc_html__('Admin Context Hooks', FRL_PREFIX), $admin_hooks, $headers, 'admin-hooks', true);
        $this->_render_hook_table(esc_html__('Other Registered Hooks (Not in Reference)', FRL_PREFIX), $other_hooks, $headers, 'other-hooks', true);

        // --- Reference Documentation at end ---
        echo frl_ui_render_section_header(__('Hooks Documentation', FRL_PREFIX));

        $this->_render_plugin_reference_table(esc_html__('Plugin Hooks Reference', FRL_PREFIX), $combined_hook_sequence_data, true);
        $this->_render_reference_table(esc_html__('WordPress Hook Reference', FRL_PREFIX), $combined_hook_sequence_data, true);

        $hook_links = '';
        $hook_references = [
            'action' => 'https://developer.wordpress.org/apis/hooks/action-reference/',
            'filter' => 'https://developer.wordpress.org/apis/hooks/filter-reference/'
        ];

        foreach ($hook_references as $hook_type => $reference_url) {
            $hook_links .= frl_ui_render_table_row(
                ucfirst($hook_type) . ' Reference',
                '<a href="' . $reference_url . '" target="_blank">' . $reference_url . '</a>'
            );
        }

        echo frl_ui_render_table('hook-reference-links', $hook_links);
    }

    /**
     * Render the profiler status notice.
     * Shows whether the hook profiler is enabled or disabled with instructions.
     *
     * @return bool True if profiler is enabled, false otherwise.
     */
    private function _render_profiler_status_notice(): bool
    {
        $profiler_enabled = class_exists('Frl_Hook_Manager') && Frl_Hook_Manager::profiler_enabled();
        $wp_debug_enabled = defined('WP_DEBUG') && WP_DEBUG;
        $constant_enabled = defined('FRL_HOOKS_PROFILER') && FRL_HOOKS_PROFILER;

        if (!$profiler_enabled) {
            // Determine why it's disabled
            $reasons = [];
            if (!$constant_enabled) {
                $reasons[] = 'FRL_HOOKS_PROFILER = false';
            }
            if (!$wp_debug_enabled) {
                $reasons[] = 'WP_DEBUG = false';
            }

            echo '<div class="frl-info-message">';
            echo esc_html__('Hook Profiler is', FRL_PREFIX) . ' <strong>' . esc_html__('DISABLED', FRL_PREFIX) . '</strong> ';
            echo '(<code>' . esc_html(implode('</code> ' . esc_html__('and', FRL_PREFIX) . ' <code>', $reasons)) . '</code>). ';
           echo '</div>';
        }

        return $profiler_enabled;
    }
}
