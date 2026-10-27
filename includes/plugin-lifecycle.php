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
	// MU-plugin functions live in the early-loading stack; the sync guards
	// against the MU file not being installed yet on a first activation.
	if ( function_exists( 'frl_mu_plugins_sync' ) ) {
		frl_mu_plugins_sync();
	}

	frl_update_option( 'translation_version', 1 );

	// Activation fires before 'init' — rewrite rules cannot be flushed yet.
	// Schedule a cron event to flush them after post types are registered.
	frl_schedule_rewrite_flush();
	frl_cache_clear( 'light' );

	frl_update_option( 'plugin_version', FRL_VERSION );

	frl_seed_option_defaults();
	frl_migrate_option_autoload();
}

/**
	* Seed all registered option defaults that are missing from the database.
	*
	* Called on activation so the first page load does not pay a write-per-option
	* burst via frl_set_missing_option_default(). The write-on-read fallback remains
	* for options added by later plugin versions.
	*
	* @return void
	*/
function frl_seed_option_defaults(): void {
	// One LIKE scan for all existing plugin options, instead of one get_option()
	// per field (~100 queries on a fresh install).
	$existing = frl_get_plugin_options_db();

	foreach ( frl_get_all_plugin_options_settings( null ) as $field ) {
		if ( ! isset( $field['id'] ) ) {
			continue;
		}
		if ( ! array_key_exists( $field['id'], $existing ) ) {
			frl_update_option( $field['id'], $field['default'] ?? '', false, $field['autoload'] ?? 'no' );
		}
	}
}

/**
	* Apply the configured autoload flag to existing plugin options.
	*
	* Options written before the autoload default changed to 'no' still carry
	* autoload='yes' in the DB. update_option() short-circuits on an unchanged value
	* (so it would not touch the autoload column), hence the direct column update.
	*
	* The keep-list is FRL_AUTOLOAD_OPTIONS (config-cache.php) — the options read via
	* native get_option() on the bootstrap hot path. Keep it in sync with that const.
	*
	* @return void
	*/
function frl_migrate_option_autoload(): void {
	global $wpdb;

	$like = $wpdb->esc_like( frl_prefix() ) . '%';

	// Keep the bootstrap-gate reads autoloaded (FRL_AUTOLOAD_OPTIONS); everything else opts out.
	$keep = array();
	foreach ( FRL_AUTOLOAD_OPTIONS as $option_key ) {
		$keep[] = frl_prefix( $option_key );
	}
	$in = implode( ',', array_fill( 0, count( $keep ), '%s' ) );

	// 1. Every other plugin option opts out of autoload.
	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$wpdb->options} SET autoload = 'no'
			 WHERE option_name LIKE %s AND option_name NOT IN ($in)",
			array_merge( array( $like ), $keep )
		)
	);

	// 2. The keep-list is forced back to autoload = 'yes'.
	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$wpdb->options} SET autoload = 'yes'
			 WHERE option_name IN ($in)",
			$keep
		)
	);

	// Invalidate WordPress's alloptions cache so the change takes effect.
	wp_cache_delete( 'alloptions', 'options' );
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
	frl_cache_clear( 'light' );

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
 * Action chain (once wp_loaded has fired):
 *   1. update_option_permalink_structure → triggers:
 *        - Fralenuvole: clear_rewriter_caches() [class-rewriter.php]
 *          → clears options cache (→ rewriter → permalinks)
 *          → deletes exclusion patterns transient
 *          → calls flush_rewrite_rules(true)
 *        - Polylang: clean_languages_cache() [polylang/src/model.php]
 *          → ensures fresh language data during rule regeneration
 *   2. permalink_structure_changed → notifies any other plugins
 *
 * TIMING — why this defers instead of flushing immediately when called early:
 * Both Frl_Rewriter::register_cache_invalidation_hooks() (this plugin) AND
 * Polylang's own rewrite-rule filters (PLL_Links_Permalinks::do_prepare_rewrite_rules(),
 * hooked at wp_loaded priority 9 — see polylang/src/links-permalinks.php and
 * polylang/src/links-directory.php::prepare_rewrite_rules(), which explicitly
 * checks did_action('wp_loaded') and returns early otherwise) are deferred
 * until wp_loaded. If this function is called before wp_loaded (e.g. an admin
 * action button, or Frl_Environment_Manager's env_enforce_full, both of which
 * run on 'init') and used to call flush_rewrite_rules(true) directly at that
 * point, WordPress would regenerate the `rewrite_rules` option with NEITHER
 * set of filters registered — silently stripping Polylang's language-prefixed
 * page/post rules and producing 404s on non-default-language Pages (CPTs are
 * unaffected: they resolve via this plugin's own request-filter/catch-all
 * mechanisms, independent of the cached `rewrite_rules` option).
 *
 * Fix: when called too early, defer the entire flush to wp_loaded priority 20
 * (after Polylang's priority-9 callback) instead of flushing immediately.
 * add_action() with the same callback/hook/priority is idempotent, so multiple
 * early calls within one request only result in a single deferred execution.
 *
 * @return void
 */
function frl_flush_rewrite_rules(): void {
	// Clear the cached 'alloptions' bucket BEFORE regenerating rewrite rules,
	// so wp_load_alloptions() can't return a stale rewrite_rules value during
	// the flush. Safe/cheap to run on every call, including the deferred
	// re-entry at wp_loaded/20 below.
	wp_cache_delete( 'alloptions', 'options' );

	if ( ! did_action( 'wp_loaded' ) ) {
		// Too early: neither this plugin's nor Polylang's rewrite-rule filters
		// are registered yet. Defer the actual flush instead of running it now.
		add_action( 'wp_loaded', 'frl_flush_rewrite_rules', 20, 0 );
		return;
	}

	$permastruct = get_option( 'permalink_structure' );
	do_action( 'update_option_permalink_structure', $permastruct, $permastruct );
	do_action( 'permalink_structure_changed', $permastruct, $permastruct );
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
