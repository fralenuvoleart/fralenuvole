<?php

/**
 * Fralenuvole MU-Plugin Helper Functions
 *
 * Contains all exclusion logic for the MU plugin loader.
 * Required only by assets/mu/frl-mu-plugin.php after bootstrap is loaded,
 * so all core helpers (frl_get_option, frl_is_admin, frl_textlist_to_array, etc.)
 * are available when these functions are called.
 *
 * @package Fralenuvole
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fetches exclusion-relevant WordPress options in a single DB query.
 *
 * Used by both pre_option_active_plugins and pre_option_cron filters to
 * avoid separate DB round-trips while still bypassing WordPress option cache
 * (necessary to prevent infinite recursion inside pre_option_* filters).
 *
 * Results are cached in a static variable for per-request deduplication.
 *
 * @return array{active_plugins: string[], cron: array} Associative array with both options.
 */
function frl_get_exclusion_options(): array
{
    static $options = null;

    if ($options !== null) {
        return $options;
    }

    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT option_name, option_value 
             FROM {$wpdb->options} 
             WHERE option_name IN (%s, %s)",
            'active_plugins',
            'cron'
        )
    );

    $options = [
        'active_plugins' => [],
        'cron'           => [],
    ];

    foreach ((array) $rows as $row) {
        if ($row->option_name === 'active_plugins') {
            $options['active_plugins'] = (array) maybe_unserialize($row->option_value);
        } elseif ($row->option_name === 'cron') {
            $cron_value = maybe_unserialize($row->option_value);
            $options['cron'] = is_array($cron_value) ? $cron_value : [];
        }
    }

    return $options;
}

/**
 * Sets up plugin exclusion filters based on frontend, backend, and capability settings.
 *
 * @return void
 */
function frl_plugins_exclusion_filter(): void
{
    // Get exclusion settings
    $frontend_enabled = frl_get_option('excluded_plugins_frontend_enabled');
    $backend_enabled = frl_get_option('excluded_plugins_backend_enabled');
    $cap_enabled = frl_get_option('excluded_plugins_bycap_enabled');

    // Nothing enabled - skip
    if (!$frontend_enabled && !$backend_enabled && !$cap_enabled) {
        return;
    }

    // Determine if we're in a frontend context (HTML page or AJAX from frontend)
    // This is true for: frontend HTML pages + frontend AJAX requests
    // This is false for: admin pages, REST API, MCP, cron
    $is_frontend_context = !frl_is_admin()
        && !frl_is_rest_api_request()
        && !frl_is_cron_job_request();

    $excluded = [];

    // FRONTEND EXCLUSION: applies to ALL users in frontend context (supersedes cap check)
    if ($frontend_enabled && $is_frontend_context) {
        $frontend_list = frl_textlist_to_array(frl_get_option('excluded_plugins_frontend'));
        if (!empty($frontend_list)) {
            // Flatten nested array (frl_textlist_to_array returns nested arrays)
            $flat_list = [];
            foreach ($frontend_list as $items) {
                if (is_array($items)) {
                    $flat_list = array_merge($flat_list, $items);
                } else {
                    $flat_list[] = $items;
                }
            }
            $excluded = array_merge($excluded, $flat_list);
        }
    }

    // BACKEND EXCLUSION: applies in admin context only on specific admin pages.
    // Format: plugin-path|admin-screen (e.g., "ai-engine/ai-engine.php|post.php").
    // The admin screen after the pipe is required — without it, exclusion does not activate.
    if ($backend_enabled && !$is_frontend_context && frl_is_admin()) {
        $backend_list = frl_textlist_to_array(frl_get_option('excluded_plugins_backend'));
        if (!empty($backend_list)) {
            foreach ($backend_list as $items) {
                if (is_array($items) && !empty($items)) {
                    $plugin_path = $items[0];
                    $admin_screen = $items[1] ?? '';

                    // Screen condition is required — only exclude when current screen matches
                    if (!empty($admin_screen) && frl_is_admin_page($admin_screen)) {
                        $excluded[] = $plugin_path;
                    }
                }
            }
        }
    }

    // CAPABILITY EXCLUSION: applies in non-frontend contexts (admin, REST, cron) when user lacks cap
    // In frontend context, cap check is skipped (frontend exclusion takes precedence)
    if ($cap_enabled && !$is_frontend_context) {
        $required_cap = frl_get_option('excluded_plugins_bycap_cap') ?: 'delete_plugins';
        if (!frl_has_access($required_cap)) {
            $cap_list = frl_textlist_to_array(frl_get_option('excluded_plugins_bycap'));
            if (!empty($cap_list)) {
                // Flatten nested array (frl_textlist_to_array returns nested arrays)
                $flat_list = [];
                foreach ($cap_list as $items) {
                    if (is_array($items)) {
                        $flat_list = array_merge($flat_list, $items);
                    } else {
                        $flat_list[] = $items;
                    }
                }
                $excluded = array_merge($excluded, $flat_list);
            }
        }
    }

    // Remove duplicates and check if we have anything to exclude
    $excluded = array_unique(array_filter($excluded, 'is_string'));

    // Safeguard: Ensure the plugin itself is never excluded to avoid inconsistent state
    $plugin_handle = FRL_MU_NAME . '/' . FRL_MU_NAME . '.php';
    $excluded = array_diff($excluded, [$plugin_handle]);

    if (empty($excluded)) {
        return;
    }

    // Add filter to remove excluded plugins before they load
    frl_add_exclusion_filter_active_plugins($excluded);

    // Also handle network active plugins for multisite
    frl_add_exclusion_filter_network_active_plugins($excluded);

    // During WP Cron, also filter orphaned cron events that reference schedules
    // that were never registered (because the plugin that owns them was excluded).
    // This prevents WordPress from logging invalid_schedule errors.
    if (frl_is_cron_job_request()) {
        frl_add_exclusion_filter_cron($excluded);
    }
}

/**
 * Filters active plugins to exclude specified plugins.
 *
 * @param string[] $excluded List of plugin paths to exclude.
 * @return void
 */
function frl_add_exclusion_filter_active_plugins(array $excluded): void
{
    add_filter('pre_option_active_plugins', function ($pre, $option) use ($excluded) {
        // Only handle 'active_plugins' option, pass through for all others
        if ($option !== 'active_plugins') {
            return $pre;
        }

        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        // Get exclusion options via shared single-query helper
        $exclusion_options = frl_get_exclusion_options();
        $plugins = $exclusion_options['active_plugins'];

        $filtered = array_filter($plugins, function ($plugin) use ($excluded) {
            return !in_array($plugin, $excluded);
        });

        $cache = array_values($filtered);
        return $cache;
    }, 10, 2);
}

/**
 * Filters network active plugins for multisite to exclude specified plugins.
 *
 * @param string[] $excluded List of plugin paths to exclude.
 * @return void
 */
function frl_add_exclusion_filter_network_active_plugins(array $excluded): void
{
    add_filter('pre_site_option_active_plugins', function ($pre, $option) use ($excluded) {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        // Bypass the pre_site_option filter by accessing cache/db directly to prevent infinite recursion
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedQuery
        $plugins = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT meta_value FROM ' . $wpdb->sitemeta . ' WHERE meta_key = %s LIMIT 1',
                'active_plugins'
            )
        );
        $plugins = $plugins ? maybe_unserialize($plugins) : [];

        $filtered = array_filter((array) $plugins, function ($plugin) use ($excluded) {
            return !in_array($plugin, $excluded);
        });

        $cache = array_values($filtered);
        return $cache;
    }, 10, 2);
}

/**
 * Filters cron option during WP Cron to remove events with unregistered schedules.
 *
 * When a plugin is excluded from loading, its custom cron schedules never get
 * registered. WordPress would otherwise log invalid_schedule errors when trying
 * to reschedule those events. This filter silently removes such orphaned events
 * from the cron array that WordPress processes, preventing error log noise.
 *
 * Note: This is a read-time filter only — it does not modify the database.
 * If the exclusion is later removed, the plugin will load, register its
 * schedules, and its cron events will work again.
 *
 * @param string[] $excluded List of plugin paths to exclude (unused directly, kept for consistency).
 * @return void
 */
function frl_add_exclusion_filter_cron(array $excluded): void
{
    add_filter('pre_option_cron', function ($pre, $option) use ($excluded) {
        // Only handle 'cron' option, pass through for all others
        if ($option !== 'cron') {
            return $pre;
        }

        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        // Get exclusion options via shared single-query helper.
        // cron data was already fetched alongside active_plugins — zero extra DB queries.
        $exclusion_options = frl_get_exclusion_options();
        $cron = $exclusion_options['cron'];

        // If cron is empty or not an array, return as-is
        if (empty($cron) || !is_array($cron)) {
            $cache = $cron;
            return $cache;
        }

        // Get all registered schedules.
        // At this point (during WP Cron processing), all non-excluded plugins
        // have already loaded and registered their schedules via cron_schedules filter.
        $schedules = wp_get_schedules();

        $filtered = [];

        foreach ($cron as $timestamp => $hooks) {
            if (!is_array($hooks)) {
                continue;
            }

            $filtered_hooks = [];

            foreach ($hooks as $hook => $events) {
                if (!is_array($events)) {
                    continue;
                }

                $filtered_events = [];

                foreach ($events as $hash => $event) {
                    // If event has a schedule name, verify it's registered
                    if (!empty($event['schedule']) && !isset($schedules[$event['schedule']])) {
                        // Schedule doesn't exist — skip this orphaned event
                        continue;
                    }
                    $filtered_events[$hash] = $event;
                }

                if (!empty($filtered_events)) {
                    $filtered_hooks[$hook] = $filtered_events;
                }
            }

            if (!empty($filtered_hooks)) {
                $filtered[$timestamp] = $filtered_hooks;
            }
        }

        $cache = $filtered;
        return $cache;
    }, 10, 2);
}
