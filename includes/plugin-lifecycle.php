<?php
/**
 * Plugin lifecycle callbacks and utilities.
 *
 * Handles activation, deactivation, uninstallation, automatic backups
 * during version upgrades, and rewrite rules flushing.
 *
 * @package Fralenuvole
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Register cron callback for scheduled rewrite flushes.
// The hook name matches the function name so wp_schedule_single_event()
// can use the function name directly as the action.
add_action( 'frl_flush_rewrite_rules', 'frl_flush_rewrite_rules', 10, 0 );
add_action( 'admin_init', 'frl_auto_backup_on_upgrade', 5, 0 );

/**
 * Handles plugin activation.
 *
 * Syncs MU-plugins, initializes translation version, flushes rewrite rules,
 * and stores the current plugin version.
 *
 * @return void
 */
function frl_activate_plugin(): void {
	if ( function_exists( 'frl_mu_plugins_sync' ) ) {
		frl_mu_plugins_sync();
	}

	if ( function_exists( 'frl_update_option' ) ) {
		frl_update_option( 'translation_version', 1 );
	}

	// Activation fires before 'init' — rewrite rules cannot be flushed yet.
	// Schedule a cron event to flush them after post types are registered.
	frl_schedule_rewrite_flush();
	if ( function_exists( 'frl_cache_clear' ) ) {
		frl_cache_clear( 'light' );
	}

	if ( function_exists( 'frl_update_option' ) ) {
		frl_update_option( 'plugin_version', FRL_VERSION );
	}
}

/**
 * Automatically backs up plugin settings when a version upgrade is detected.
 *
 * Maintains a rolling history of the last 5 backups in the plugin's backup directory.
 *
 * @return void
 */
function frl_auto_backup_on_upgrade(): void {
	if ( ! function_exists( 'frl_has_access' ) || ! frl_has_access() ) {
		return;
	}

	$stored_version = frl_get_option( 'plugin_version' ) ?: '0.0.0';

	// Compare only the first 3 segments (major.minor.patch).
	// A 4th segment (hotfix) should not trigger upgrade routines.
	$stored_core  = implode( '.', array_slice( explode( '.', $stored_version ), 0, 3 ) );
	$current_core = implode( '.', array_slice( explode( '.', FRL_VERSION ), 0, 3 ) );

	if ( version_compare( $stored_core, $current_core, '>=' ) ) {
		return;
	}

	$backups_dir = FRL_DIR_PATH . 'backups';

	if ( ! is_dir( $backups_dir ) ) {
		wp_mkdir_p( $backups_dir );
		// Dual syntax: 'Require all denied' is Apache 2.4+; the IfModule fallback
		// keeps the directory protected on Apache 2.2 (mod_authz_core absent).
		file_put_contents(
			$backups_dir . '/.htaccess',
			"<IfModule mod_authz_core.c>\n" .
			"\tRequire all denied\n" .
			"</IfModule>\n" .
			"<IfModule !mod_authz_core.c>\n" .
			"\tOrder deny,allow\n" .
			"\tDeny from all\n" .
			"</IfModule>\n"
		);
		file_put_contents( $backups_dir . '/index.php', '<?php // Silence is golden.' );
	}

	if ( ! is_writable( $backups_dir ) ) {
		return;
	}

	if ( ! function_exists( 'frl_get_plugin_options_db' ) || ! function_exists( 'frl_prepare_settings_for_export' ) ) {
		return;
	}

	$settings = frl_prepare_settings_for_export( frl_get_plugin_options_db() );

	// parse_url() can return null for a malformed URL; fall back to 'unknown'
	// rather than passing null to sanitize_file_name() (deprecated in PHP 8.1+).
	$domain   = sanitize_file_name( parse_url( get_site_url(), PHP_URL_HOST ) ?: 'unknown' );
	$filename = $domain . '-frl-settings-' . gmdate( 'Ymd-His' ) . '.json';
	$filepath = $backups_dir . '/' . $filename;

	$json = json_encode( $settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
	file_put_contents( $filepath, $json );

	// Maintain only the 5 most recent backups
	$backups = glob( $backups_dir . '/*-frl-settings-*.json' );
	if ( is_array( $backups ) && count( $backups ) > 5 ) {
		usort(
			$backups,
			function ( $a, $b ) {
				return filemtime( $a ) - filemtime( $b );
			}
		);
		$to_delete = array_slice( $backups, 0, count( $backups ) - 5 );
		foreach ( $to_delete as $old_file ) {
			if ( is_file( $old_file ) ) {
				unlink( $old_file );
			}
		}
	}

	frl_update_option( 'plugin_version', FRL_VERSION );
}

/**
 * Handles plugin deactivation.
 *
 * Clears light cache, flushes rewrite rules, and removes scheduled cleanup hooks.
 *
 * @return void
 */
function frl_deactivate_plugin(): void {
	if ( function_exists( 'frl_cache_clear' ) ) {
		frl_cache_clear( 'light' );
	}

	// Deactivation fires before 'init' — schedule a deferred flush.
	frl_schedule_rewrite_flush();
	wp_clear_scheduled_hook( 'frl_daily_cache_cleanup' );
}

/**
 * Handles plugin uninstallation.
 *
 * Deletes plugin data, removes MU-plugins, flushes rewrite rules, and clears scheduled hooks.
 *
 * @return void
 */
function frl_uninstall_plugin(): void {
	if ( function_exists( 'frl_delete_plugin' ) ) {
		frl_delete_plugin();
	}

	// Not in frl_delete_plugin(): that's shared with "Reset Plugin", which must
	// stay options-only. `_frl_post_version` is a harmless cache-busting
	// timestamp (no user content), safe to purge only on a real uninstall.
	global $wpdb;
	if ( isset( $wpdb ) && $wpdb instanceof wpdb ) {
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_frl_post_version'" );
	}

	if ( function_exists( 'frl_mu_plugins_delete' ) ) {
		frl_mu_plugins_delete();
	}

	// Uninstall fires before 'init' — schedule a deferred flush.
	frl_schedule_rewrite_flush();
	wp_clear_scheduled_hook( 'frl_daily_cache_cleanup' );
}

/**
 * Flush WordPress rewrite rules, mirroring the exact behaviour of
 * WP_Rewrite::set_permalink_structure() (the "Save Permalinks" button).
 *
 * For before-init contexts (plugin activation/deactivation), use frl_schedule_rewrite_flush() instead.
 *
 * Action chain:
 *   1. update_option_permalink_structure → triggers:
 *        - Fralenuvole: clear_rewriter_caches() [class-rewriter.php:471]
 *          → clears options cache (→ rewriter → permalinks)
 *          → deletes exclusion patterns transient
 *          → calls flush_rewrite_rules(true)
 *        - Polylang: clean_languages_cache() [polylang/src/model.php:119]
 *          → ensures fresh language data during rule regeneration
 *   2. permalink_structure_changed → notifies any other plugins
 *
 * NOTE: Frl_Rewriter::register_cache_invalidation_hooks() defers hook
 * registration to wp_loaded, so when this function is called before
 * wp_loaded (e.g. admin action button at init:10), the action chain
 * silently does nothing. The did_action('wp_loaded') fallback below
 * ensures essential operations execute regardless of hook timing.
 *
 * @return void
 */
function frl_flush_rewrite_rules(): void {
	// Delete Docket's cached alloptions BEFORE regenerating rewrite rules.
	// Docket defers alloptions invalidation to shutdown (cache.php:2065),
	// so the cached file survives the current request. Without this,
	// wp_load_alloptions() returns stale rewrite_rules during flush,
	// causing 404 errors on secondary-language permalinks.
	wp_cache_delete( 'alloptions', 'options' );

	$permastruct = get_option( 'permalink_structure' );
	do_action( 'update_option_permalink_structure', $permastruct, $permastruct );
	do_action( 'permalink_structure_changed', $permastruct, $permastruct );

	// When called before wp_loaded, the rewriter's deferred cache invalidation
	// hooks (registered in register_cache_invalidation_hooks()) are not yet set
	// up. Run flush_rewrite_rules(true) directly.
	if ( ! did_action( 'wp_loaded' ) ) {
		// Prevent the Subdomain Adapter from translating page_on_front /
		// page_for_posts during rule generation. On a subdomain request,
		// translated IDs would be baked into the global rewrite_rules option.
		if ( class_exists( 'Frl_Subdomain_Adapter' ) ) {
			++Frl_Subdomain_Adapter::$flush_depth;
		}
		try {
			flush_rewrite_rules( true );
		} finally {
			if ( class_exists( 'Frl_Subdomain_Adapter' ) ) {
				--Frl_Subdomain_Adapter::$flush_depth;
			}
		}
	}
}

/**
 * Schedules a single cron event to flush rewrite rules after 15 seconds.
 *
 * Used when a flush is needed before 'init' (plugin activation/deactivation)
 * or from contexts where a synchronous flush would be too expensive
 * (frontend requests, third-party cache purge handlers).
 *
 * The cron callback is frl_flush_rewrite_rules().
 *
 * @return void
 */
function frl_schedule_rewrite_flush(): void {
	if ( ! wp_next_scheduled( 'frl_flush_rewrite_rules' ) ) {
		wp_schedule_single_event( time() + 15, 'frl_flush_rewrite_rules' );
	}
}
