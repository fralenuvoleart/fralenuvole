<?php

/**
 * Fralenuvole - Early Loader
 *
 * This file should be placed in the /wp-content/mu-plugins/ directory.
 * It loads before regular plugins and can set up early filters.
 *
 * @package Fralenuvole
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

// MU plugin constants
const FRL_MU_NAME = 'fralenuvole';

/**
 * Setup plugin exclusion filter before other plugins load.
 * This runs at muplugins_loaded, before the regular plugins_loaded hook.
 */
add_action('muplugins_loaded', 'frl_plugins_exclusion_filter', 5);

// Load plugin bootstrap to initialize helpers, error handler, and cache
// @phpstan-ignore requireOnce.fileNotFound
require_once WP_PLUGIN_DIR . '/' . FRL_MU_NAME . '/includes/bootstrap.php';

/**
 * Sets up plugin exclusion filters based on frontend and capability settings.
 *
 * @return void
 */
function frl_plugins_exclusion_filter(): void
{
    // Get exclusion settings
    $frontend_enabled = frl_get_option('excluded_plugins_frontend_enabled');
    $cap_enabled = frl_get_option('excluded_plugins_bycap_enabled');

    // Nothing enabled - skip
    if (!$frontend_enabled && !$cap_enabled) {
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

    // CAPABILITY EXCLUSION: applies ONLY in non-frontend contexts (admin) when user lacks cap
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

        // Bypass the pre_option filter by accessing cache/db directly to prevent infinite recursion
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedQuery
        $plugins = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT option_value FROM ' . $wpdb->options . ' WHERE option_name = %s LIMIT 1',
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