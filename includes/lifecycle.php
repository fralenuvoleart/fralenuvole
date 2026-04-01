<?php
/**
 * Lifecycle callbacks and utilities for the Fralenuvole plugin.
 *
 * This file is loaded by the main plugin file immediately after the bootstrap so its
 * functions are available when WordPress registers the activation,
 * deactivation and uninstall hooks.
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

// Register the deferred flush hook for rewrite rules.
frl_hook_add(
    'action',
    'frl_execute_rewrite_flush',
    'frl_execute_rewrite_flush',
    10,
    0
);

// Register the checker for the safe admin flush.
frl_hook_add(
    'action',
    'admin_init',
    'frl_execute_scheduled_admin_flush',
    99,
    0
);

/**
 * Plugin activation callback.
 */
function frl_activate_plugin(): void
{
    // Ensure MU-plugins are in sync.
    if (function_exists('frl_mu_plugins_sync')) {
        frl_mu_plugins_sync();
    }

    // Guarantee the translation version option exists.
    if (function_exists('frl_update_option')) {
        frl_update_option('translation_version', 1);
    }

    // Force a rewrite-rule refresh and drop light cache.
    frl_flush_force_rewrite_rules();
    if (function_exists('frl_cache_clear')) {
        frl_cache_clear('light');
    }
}

/**
 * Plugin deactivation callback.
 */
function frl_deactivate_plugin(): void
{
    if (function_exists('frl_cache_clear')) {
        frl_cache_clear('light');
    }

    frl_flush_force_rewrite_rules();
    wp_clear_scheduled_hook('frl_daily_cache_cleanup');
}

/**
 * Plugin uninstall callback.
 */
function frl_uninstall_plugin(): void
{
    if (function_exists('frl_delete_plugin')) {
        frl_delete_plugin();
    }

    if (function_exists('frl_mu_plugins_delete')) {
        frl_mu_plugins_delete();
    }

    frl_flush_force_rewrite_rules();
    wp_clear_scheduled_hook('frl_daily_cache_cleanup');
}

/**
 * Flush rewrite rules reliably.
 *
 * – If called before `init`, schedule a single cron event so the flush happens
 *   after all CPTs and custom rules are registered (mirrors WP Permalinks UI).
 * – Otherwise perform an immediate hard flush.
 */
function frl_flush_force_rewrite_rules(): void
{
    // 1. Called before init: schedule a single cron event (unchanged behaviour)
    if (!did_action('init')) {
        frl_schedule_rewrite_flush();
        return;
    }

    /* 2. Called during the init hook *before* custom rewrite rules are registered.
       The manual admin-action runs at priority 10, while custom rules are added at
       priority 20 by Frl_Rewriter.  We defer the actual flush to priority 99 of
       the same init cycle so that every rule is present. */
    if (doing_action('init') && current_filter() === 'init') {
        static $deferred_once = false;
        if (!$deferred_once) {
            $deferred_once = true;
            frl_hook_add('action', 'init', 'frl_execute_rewrite_flush', 99, 0);
        }
        return;
    }

    // 3. Safe to flush immediately (already after init or in cron context)
    frl_execute_rewrite_flush();
}

/**
 * Schedule a single cron event to flush rewrite rules after 15 seconds.
 */
function frl_schedule_rewrite_flush()
{
    if (!wp_next_scheduled('frl_execute_rewrite_flush')) {
        wp_schedule_single_event(time() + 15, 'frl_execute_rewrite_flush');
    }
}

/**
 * Cron-safe hard flush executed by the deferred event.
 */
function frl_execute_rewrite_flush(): void
{
    // flush_rewrite_rules(false) already deletes the rewrite_rules option internally
    // and regenerates the rules. No need for redundant delete_option() call.
    flush_rewrite_rules(false);

    if (function_exists('frl_cache_clear')) {
        // Clear all caches related to URL structure and resolution
        frl_cache_clear('permalinks');

        // Clearing 'options' also clears the 'rewriter' group due to dependencies.
        // This ensures all derived configs are rebuilt.
        frl_cache_clear('options');
    }

    // Outbound: notify third-party cache plugins that rewrite rules changed.
    if (function_exists('frl_thirdparty_maybe_notify')) {
        frl_thirdparty_maybe_notify('rewrite_flush');
    }
}

/**
 * Sets a transient to safely trigger a rewrite flush on the next admin page load.
 * This is the recommended function to call from admin UI buttons.
 */
function frl_schedule_admin_rewrite_flush(): void
{
    // Use a transient (plugin helper) to decouple the flush from any redirects.
    // TTL gives a safety window; it does NOT delay execution (runs on next admin_init).
    frl_set_transient('rewrite_flush_scheduled', true, 60);
}

/**
 * Checks for the flush transient on admin_init and executes the flush if needed.
 */
function frl_execute_scheduled_admin_flush(): void
{
    if (frl_get_transient('rewrite_flush_scheduled')) {
        frl_delete_transient('rewrite_flush_scheduled');
        frl_flush_force_rewrite_rules();
    }
}
