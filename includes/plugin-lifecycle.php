<?php
/**
 * Plugin lifecycle callbacks and utilities.
 *
 * Handles activation, deactivation, uninstallation, and automatic backups
 * during version upgrades.
 *
 * @package Fralenuvole
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

// Register rewrite flush and admin maintenance hooks
add_action('frl_execute_rewrite_flush', 'frl_execute_rewrite_flush', 10, 0);
add_action('admin_init', 'frl_execute_scheduled_admin_flush', 99, 0);
add_action('admin_init', 'frl_auto_backup_on_upgrade', 5, 0);

/**
 * Handles plugin activation.
 *
 * Syncs MU-plugins, initializes translation version, flushes rewrite rules,
 * and stores the current plugin version.
 *
 * @return void
 */
function frl_activate_plugin(): void
{
    if (function_exists('frl_mu_plugins_sync')) {
        frl_mu_plugins_sync();
    }

    if (function_exists('frl_update_option')) {
        frl_update_option('translation_version', 1);
    }

    frl_flush_force_rewrite_rules();
    if (function_exists('frl_cache_clear')) {
        frl_cache_clear('light');
    }

    if (function_exists('frl_update_option')) {
        frl_update_option('plugin_version', FRL_VERSION);
    }
}

/**
 * Automatically backs up plugin settings when a version upgrade is detected.
 *
 * Maintains a rolling history of the last 5 backups in the plugin's backup directory.
 *
 * @return void
 */
function frl_auto_backup_on_upgrade(): void
{
    if (!function_exists('frl_has_access') || !frl_has_access()) {
        return;
    }

    $stored_version = frl_get_option('plugin_version') ?: '0.0.0';

    if (version_compare($stored_version, FRL_VERSION, '>=')) {
        return;
    }

    $backups_dir = FRL_DIR_PATH . 'backups';

    if (!is_dir($backups_dir)) {
        wp_mkdir_p($backups_dir);
        file_put_contents($backups_dir . '/.htaccess', "Order deny,allow\nDeny from all\n");
        file_put_contents($backups_dir . '/index.php', '<?php // Silence is golden.');
    }

    if (!is_writable($backups_dir)) {
        return;
    }

    if (!function_exists('frl_get_plugin_options_db') || !function_exists('frl_prepare_settings_for_export')) {
        return;
    }

    $settings = frl_prepare_settings_for_export(frl_get_plugin_options_db());

    $domain = sanitize_file_name(parse_url(get_site_url(), PHP_URL_HOST));
    $filename = $domain . '-frl-settings-' . date('Ymd-His') . '.json';
    $filepath = $backups_dir . '/' . $filename;

    $json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    file_put_contents($filepath, $json);

    // Maintain only the 5 most recent backups
    $backups = glob($backups_dir . '/*-frl-settings-*.json');
    if (is_array($backups) && count($backups) > 5) {
        usort($backups, function ($a, $b) {
            return filemtime($a) - filemtime($b);
        });
        $to_delete = array_slice($backups, 0, count($backups) - 5);
        foreach ($to_delete as $old_file) {
            if (is_file($old_file)) {
                unlink($old_file);
            }
        }
    }

    frl_update_option('plugin_version', FRL_VERSION);
    frl_schedule_admin_rewrite_flush();
}

/**
 * Handles plugin deactivation.
 *
 * Clears light cache, flushes rewrite rules, and removes scheduled cleanup hooks.
 *
 * @return void
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
 * Handles plugin uninstallation.
 *
 * Deletes plugin data, removes MU-plugins, flushes rewrite rules, and clears scheduled hooks.
 *
 * @return void
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
 * Ensures rewrite rules are flushed reliably.
 *
 * Schedules a cron event if called before 'init', defers to priority 200 if called
 * during 'init', or executes immediately otherwise.
 *
 * @return void
 */
function frl_flush_force_rewrite_rules(): void
{
    // Schedule cron event if called before init
    if (!did_action('init')) {
        frl_schedule_rewrite_flush();
        return;
    }

    // Defer flush to priority 200 if called during init to ensure all rules are registered
    if (doing_action('init') && current_filter() === 'init') {
        static $deferred_once = false;
        if (!$deferred_once) {
            $deferred_once = true;
            add_action('init', 'frl_execute_rewrite_flush', 200, 0);
        }
        return;
    }

    frl_execute_rewrite_flush();
}

/**
 * Schedules a single cron event to flush rewrite rules after 15 seconds.
 *
 * @return void
 */
function frl_schedule_rewrite_flush(): void
{
    if (!wp_next_scheduled('frl_execute_rewrite_flush')) {
        wp_schedule_single_event(time() + 15, 'frl_execute_rewrite_flush');
    }
}

/**
 * Executes the rewrite rules flush sequence.
 *
 * Purges third-party caches, clears internal permalink/rewriter caches,
 * and rebuilds WordPress rewrite rules.
 *
 * @return void
 */
function frl_execute_rewrite_flush(): void
{
    // Purge third-party caches first to prevent race conditions during rebuild
    if (function_exists('frl_thirdparty_maybe_notify')) {
        frl_thirdparty_maybe_notify('rewrite_flush');
    }

    // Rebuild rewrite rules
    if (class_exists('Frl_Rewriter')) {
        Frl_Rewriter::flush_rules(is_admin());
    } else {
        // Compatibility, the class already does all
        frl_cache_clear('rewriter');
        flush_rewrite_rules(is_admin());
    }
}

/**
 * Schedules a rewrite flush for the next admin page load using a transient.
 *
 * @return void
 */
function frl_schedule_admin_rewrite_flush(): void
{
    frl_set_transient('rewrite_flush_scheduled', true, 60);
}

/**
 * Checks for and executes a scheduled admin rewrite flush.
 *
 * @return void
 */
function frl_execute_scheduled_admin_flush(): void
{
    if (frl_get_transient('rewrite_flush_scheduled')) {
        frl_delete_transient('rewrite_flush_scheduled');
        frl_flush_force_rewrite_rules();
    }
}
