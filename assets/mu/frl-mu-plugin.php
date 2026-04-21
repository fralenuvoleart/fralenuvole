<?php

/**
 * Fralenuvole - Early Loader
 *
 * This file should be placed in the /wp-content/mu-plugins/ directory.
 * It loads before regular plugins and can set up early filters.
 *
 * @package Fralenuvole
 */

const FRL_MU_NAME = 'fralenuvole';

/**
 * Setup plugin exclusion filter before other plugins load.
 * This runs at muplugins_loaded, before the regular plugins_loaded hook.
 */
add_action('muplugins_loaded', function () {
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
            $excluded = array_merge($excluded, $frontend_list);
        }
    }
    
    // CAPABILITY EXCLUSION: applies ONLY in non-frontend contexts (admin) when user lacks cap
    // In frontend context, cap check is skipped (frontend exclusion takes precedence)
    if ($cap_enabled && !$is_frontend_context) {
        $required_cap = frl_get_option('excluded_plugins_bycap_cap') ?: 'delete_plugins';
        if (!frl_has_access($required_cap)) {
            $cap_list = frl_textlist_to_array(frl_get_option('excluded_plugins_bycap'));
            if (!empty($cap_list)) {
                $excluded = array_merge($excluded, $cap_list);
            }
        }
    }
    
    // Remove duplicates and check if we have anything to exclude
    $excluded = array_unique(array_filter($excluded));
    
    if (empty($excluded)) {
        return;
    }
    
    // Add filter to remove excluded plugins before they load
    add_filter('pre_option_active_plugins', function ($pre, $option) use ($excluded) {
        static $cache = null;
        
        if ($cache !== null) {
            return $cache;
        }
        
        // Bypass the pre_option filter by accessing cache/db directly to prevent infinite recursion
        global $wpdb;
        $plugins = $wpdb->get_col(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedQuery
                // @phpstan-ignore-next-line
                'SELECT option_value FROM %s WHERE option_name = %s LIMIT 1',
                $wpdb->options,
                'active_plugins'
            )
        );
        $plugins = $plugins ? maybe_unserialize($plugins[0]) : [];
        
        $filtered = array_filter((array) $plugins, function ($plugin) use ($excluded) {
            return !in_array($plugin, $excluded);
        });
        
        $cache = array_values($filtered);
        return $cache;
    }, 10, 2);
    
    // Also handle network active plugins for multisite
    add_filter('pre_site_option_active_plugins', function ($pre, $option) use ($excluded) {
        static $cache = null;
        
        if ($cache !== null) {
            return $cache;
        }
        
        // Bypass the pre_option filter by accessing cache/db directly to prevent infinite recursion
        global $wpdb;
        $plugins = $wpdb->get_col(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedQuery
                // @phpstan-ignore-next-line
                'SELECT option_value FROM %s WHERE option_name = %s LIMIT 1',
                $wpdb->sitemeta,
                'active_plugins'
            )
        );
        $plugins = $plugins ? maybe_unserialize($plugins[0]) : [];
        
        $filtered = array_filter((array) $plugins, function ($plugin) use ($excluded) {
            return !in_array($plugin, $excluded);
        });
        
        $cache = array_values($filtered);
        return $cache;
    }, 10, 2);
}, 5);

// Load plugin bootstrap AFTER setting up the exclusion filter
$plugin_dir = WP_PLUGIN_DIR . '/' . FRL_MU_NAME . '/';
$bootstrap_file = $plugin_dir . 'includes/bootstrap.php';

if (file_exists($bootstrap_file)) {
    // @phpstan-ignore requireOnce.fileNotFound
    require_once $bootstrap_file;
}