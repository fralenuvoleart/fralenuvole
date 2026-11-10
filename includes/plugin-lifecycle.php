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

// Autoloaded flag: a rewrite flush is waiting for the next admin/cron/CLI request.
const FRL_REWRITE_FLUSH_PENDING_OPTION = '_frl_rewrite_flush_pending';

// Register cron callback for scheduled rewrite flushes.
// The hook name matches the function name so wp_schedule_single_event()
// can use the function name directly as the action.
add_action( 'frl_flush_rewrite_rules', 'frl_flush_rewrite_rules', 10, 0 );
// Fallback wake-up for a pending flush: the flag consumer below does the flush.
add_action( 'frl_flush_pending_rewrite_rules', 'frl_maybe_flush_pending_rewrite_rules', 10, 0 );
// Next admin/cron/CLI request flushes with rules built from the new settings.
add_action( 'wp_loaded', 'frl_maybe_flush_pending_rewrite_rules', 20, 0 );
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

	// Activation fires before 'init': flush on the next admin/cron request.
	// frl_request_rewrite_flush() already schedules its own cron fallback, so the
	// legacy frl_schedule_rewrite_flush() event is not added (it would flush twice).
	frl_request_rewrite_flush();
	frl_cache_clear( 'light' );

	frl_update_option( 'plugin_version', FRL_VERSION );

	// Rename legacy rewriter options BEFORE seeding defaults: seeding creates any
	// missing field key with its default, so if it ran first it would create the
	// new (empty) rewriter_* keys, and the migration would then find the target
	// present and drop the legacy row — silently losing the saved value.
	frl_migrate_rewriter_option_names();
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
	* Rename legacy rewriter options into the rewriter_* namespace (one-time).
	*
	* Plugin options are stored prefixed (frl_<key>). Rewriter-owned options were
	* renamed so a single updated_option( frl_rewriter_* ) hook can invalidate the
	* rewriter caches precisely (instead of every options-group save). A direct
	* $wpdb rename is used to avoid firing update_option() hooks — and therefore
	* rewrite-rule flushes — during activation.
	*
	* Idempotent: a row is renamed only when the target does not already exist.
	*
	* @return void
	*/
function frl_migrate_rewriter_option_names(): void {
	$renames = array(
		'remove_cpt_base'             => 'rewriter_remove_cpt_base',
		'remove_tax_base'             => 'rewriter_remove_tax_base',
		'translate_post_base'         => 'rewriter_translate_post_base',
		'translate_cpt_slugs_service' => 'rewriter_translate_cpt_slugs_service',
	);

	if ( defined( 'FRL_REWRITER_MULTILINGUAL_CPT' ) && is_array( FRL_REWRITER_MULTILINGUAL_CPT ) ) {
		foreach ( FRL_REWRITER_MULTILINGUAL_CPT as $cpt_slug ) {
			$renames[ 'translate_cpt_slugs_' . $cpt_slug ] = 'rewriter_translate_cpt_slugs_' . $cpt_slug;
		}
	}

	foreach ( $renames as $old_key => $new_key ) {
		frl_rename_plugin_option( $old_key, $new_key );
	}

	// Drop request-static option snapshots so any later read in this request
	// (e.g. frl_seed_option_defaults()) sees the renamed keys, not a stale
	// pre-migration snapshot.
	frl_cache_clear( 'options' );
	frl_get_option( '__reset__' );
}

/**
	* Rename a single plugin option row in the DB, preserving its value and autoload.
	*
	* @param string $old_key Unprefixed legacy option key.
	* @param string $new_key Unprefixed target option key.
	* @return void
	*/
function frl_rename_plugin_option( $old_key, $new_key ): void {
	global $wpdb;

	$old_name = frl_prefix( $old_key );
	$new_name = frl_prefix( $new_key );

	if ( $old_name === $new_name ) {
		return;
	}

	// Nothing to migrate if the legacy row is absent.
	$old_exists = $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $old_name ) );
	if ( null === $old_exists ) {
		return;
	}

	// Target already present: this happens when the write-on-read fallback seeded
	// the new key with its default before activation (the rewriter config hash reads
	// these options on every request). The legacy row holds the authentic pre-rename
	// value, so copy it over the new row, then drop the legacy one.
	$new_exists = $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $new_name ) );
	if ( null !== $new_exists ) {
		$legacy_value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $old_name ) );
		$wpdb->update( $wpdb->options, array( 'option_value' => $legacy_value ), array( 'option_name' => $new_name ) );
		$wpdb->delete( $wpdb->options, array( 'option_name' => $old_name ) );
		return;
	}

	$wpdb->update( $wpdb->options, array( 'option_name' => $new_name ), array( 'option_name' => $old_name ) );
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
 * Clears light cache, drops the cached rewrite rules, and removes scheduled cleanup hooks.
 *
 * @return void
 */
function frl_deactivate_plugin(): void {
	frl_cache_clear( 'light' );

	// An absent option makes WP_Rewrite::wp_rewrite_rules() regenerate lazily without our rules.
	delete_option( 'rewrite_rules' );
	// Best-effort fallback: the cron callback does not run once the plugin is inactive.
	frl_schedule_rewrite_flush();
	wp_clear_scheduled_hook( 'frl_daily_cache_cleanup' );
	wp_clear_scheduled_hook( 'frl_flush_pending_rewrite_rules' );
}

/**
 * Handles plugin uninstallation.
 *
 * Deletes plugin data, removes MU-plugins, drops the cached rewrite rules, and clears scheduled hooks.
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

	// An absent option makes WP_Rewrite::wp_rewrite_rules() regenerate lazily without our rules.
	delete_option( 'rewrite_rules' );
	// Best-effort fallback: the cron callback does not run once the plugin is uninstalled.
	frl_schedule_rewrite_flush();
	wp_clear_scheduled_hook( 'frl_daily_cache_cleanup' );
	wp_clear_scheduled_hook( 'frl_flush_pending_rewrite_rules' );
}

/**
 * Flush WordPress rewrite rules, mirroring the relevant part of
 * WP_Rewrite::set_permalink_structure() (the "Save Permalinks" button).
 *
 * For before-init contexts (plugin activation), use frl_request_rewrite_flush() instead.
 *
 * Action chain (once wp_loaded has fired):
 *   1. update_option_permalink_structure → triggers:
 *        - Fralenuvole: Frl_Rewriter::on_permalink_structure_update() [class-rewriter.php]
 *          → clears options cache (→ rewriter → permalinks)
 *          → deletes exclusion patterns transient
 *          → does not schedule a flush: this function regenerates below
 *        - Polylang: clean_languages_cache() [polylang/src/model.php]
 *          → ensures fresh language data during rule regeneration
 *   2. permalink_structure_changed → notifies any other plugins
 *   3. one flush_rewrite_rules(true): the only regeneration of the call.
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
 * (after Polylang's priority-9 callback) instead of flushing immediately, and
 * record a pending flush for the next admin/cron request in case this request
 * ends before wp_loaded (an init-time admin action redirects and exits).
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
		// The caller may end this request before wp_loaded (the admin action dispatcher
		// redirects and exits on init/12), so record a pending flush that the next
		// admin/cron/WP-CLI request consumes and that the cron fallback also covers.
		frl_request_rewrite_flush();
		return;
	}

	// The flush below satisfies a pending request, including one raised in this request.
	frl_set_rewrite_flush_pending( false );

	$permastruct = get_option( 'permalink_structure' );

	// Same option hooks set_permalink_structure() fires: Polylang and third parties react to them.
	// The flag stops Frl_Rewriter's listener from adding a second regeneration.
	frl_rewrite_flush_is_direct( true );
	try {
		do_action( 'update_option_permalink_structure', $permastruct, $permastruct );
		do_action( 'permalink_structure_changed', $permastruct, $permastruct );
	} finally {
		frl_rewrite_flush_is_direct( false );
	}

	// One regeneration per call, from the rules registered during this request.
	flush_rewrite_rules( true );
}

/**
 * Schedules a single cron event to flush rewrite rules after 15 seconds.
 *
 * Used when a synchronous flush would be too expensive (page-slug changes,
 * frontend requests, third-party cache purge handlers).
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

/**
 * Whether a rewrite flush is waiting for the next admin/cron/CLI request.
 *
 * Seeds the autoloaded option once so later reads never miss.
 *
 * @return bool
 */
function frl_is_rewrite_flush_pending(): bool {
	$value = get_option( FRL_REWRITE_FLUSH_PENDING_OPTION, null );
	if ( null === $value ) {
		add_option( FRL_REWRITE_FLUSH_PENDING_OPTION, 0, '', true );
		return false;
	}
	return (bool) $value;
}

/**
 * Set or clear the pending-flush flag (writes only on change).
 *
 * @param bool $pending True to request a flush.
 * @return void
 */
function frl_set_rewrite_flush_pending( bool $pending ): void {
	if ( $pending ) {
		frl_rewrite_flush_set_this_request( true );
	}
	if ( frl_is_rewrite_flush_pending() !== $pending ) {
		update_option( FRL_REWRITE_FLUSH_PENDING_OPTION, $pending ? 1 : 0, true );
	}
}

/**
 * Whether the pending flag was raised during this request.
 *
 * @param bool|null $value Pass true to record, null to read.
 * @return bool
 */
function frl_rewrite_flush_set_this_request( ?bool $value = null ): bool {
	static $state = false;

	if ( null !== $value ) {
		$state = $value;
	}
	return $state;
}

/**
 * Whether frl_flush_rewrite_rules() is regenerating the rules itself.
 *
 * Read by Frl_Rewriter::on_permalink_structure_update() so the synthetic
 * permalink hooks invalidate caches without scheduling a second flush.
 *
 * @param bool|null $value Pass true/false to set, null to read.
 * @return bool
 */
function frl_rewrite_flush_is_direct( ?bool $value = null ): bool {
	static $direct = false;

	if ( null !== $value ) {
		$direct = $value;
	}
	return $direct;
}

/**
 * Request one rewrite flush from the next admin/cron/CLI request.
 *
 * Rules are registered at init from the old settings, so flushing in the
 * request that changed them would persist stale rules. Cron is only a fallback.
 *
 * @return void
 */
function frl_request_rewrite_flush(): void {
	frl_set_rewrite_flush_pending( true );

	if ( ! wp_next_scheduled( 'frl_flush_pending_rewrite_rules' ) ) {
		wp_schedule_single_event( time() + 15, 'frl_flush_pending_rewrite_rules' );
	}
}

/**
 * Flush once if a flush is pending. Runs on wp_loaded for admin, cron and WP-CLI
 * requests only, never for frontend visitors.
 *
 * @return void
 */
function frl_maybe_flush_pending_rewrite_rules(): void {
	$is_cli = defined( 'WP_CLI' ) && WP_CLI;
	if ( ! ( wp_doing_cron() || $is_cli || ( is_admin() && ! wp_doing_ajax() && is_user_logged_in() ) ) ) {
		return;
	}

	// A flag raised in this request waits for the next one: this request's rules are init-time.
	if ( frl_rewrite_flush_set_this_request() || ! frl_is_rewrite_flush_pending() ) {
		return;
	}

	frl_flush_rewrite_rules();
}
