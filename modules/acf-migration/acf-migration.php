<?php
/**
 * Module Name: ACF Migration
 * Description: Migrate ACPT custom fields to SCF/ACF with two-phase export/import, repeater transformation, rollback, and backward-compatibility shim.
 * Version: 1.0.0
 * Requires: WordPress 6.0+, SCF or ACF (PRO), WP-CLI (for CLI commands)
 *
 * @package Fralenuvole
 * @since  5.9.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Re-entrancy guard ─────────────────────────────────────────

if ( defined( 'FRL_ACF_MIGRATION_LOADED' ) ) {
	return;
}
define( 'FRL_ACF_MIGRATION_LOADED', true );

// ─── Constants ─────────────────────────────────────────────────

require_once __DIR__ . '/config-constants-acf-migration.php';

// ─── Library (core migration logic — zero fralenuvole deps) ────

require_once __DIR__ . '/lib/class-ufj-schema.php';
require_once __DIR__ . '/lib/class-acpt-parser.php';
require_once __DIR__ . '/lib/class-scf-importer.php';
require_once __DIR__ . '/lib/class-repeater-transformer.php';
require_once __DIR__ . '/lib/class-acpt-compat-shim.php';
require_once __DIR__ . '/lib/class-migration-validator.php';

// ─── CLI — loaded only in WP-CLI context ──────────────────────

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/cli/class-acpt-migrate-command.php';
}

// ─── Custom tables — created once, never on every page load ────

/**
 * Create the frl_ custom tables used by the migration module.
 *
 * Guarded by a static flag — runs SQL DDL ONLY on the first request
 * after module activation, then becomes a no-op for all subsequent
 * requests. Per-request cost when disabled: zero.
 */
function frl_acf_migration_create_tables(): void {
	static $created = false;
	if ( $created ) {
		return; }
	$created = true;

	global $wpdb;
	$charset = $wpdb->get_charset_collate();
	$engine  = 'InnoDB';

	// Use a lightweight cache key to check if tables already exist,
	// avoiding even the DDL parse cost on subsequent requests.
	$cache_key = 'frl_acpt_tables_exist';
	if ( wp_cache_get( $cache_key, ACF_MIGRATION_CACHE_GROUP ) ) {
		return;
	}

	$table_log = $wpdb->prefix . ACF_MIGRATION_TABLE_LOG;
	$wpdb->query(
		"
        CREATE TABLE IF NOT EXISTS `{$table_log}` (
            `id`             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `migration_key`  VARCHAR(20)   NOT NULL,
            `created_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `status`         VARCHAR(30)   NOT NULL DEFAULT 'in_progress',
            `ufj_file`       VARCHAR(500)  DEFAULT '',
            `summary`        LONGTEXT      DEFAULT NULL,
            `rollback_data`  LONGTEXT      DEFAULT NULL,
            `errors`         LONGTEXT      DEFAULT NULL,
            INDEX `idx_status` (`status`),
            INDEX `idx_created` (`created_at`)
        ) ENGINE={$engine} {$charset}
    "
	);

	$table_backup = $wpdb->prefix . ACF_MIGRATION_TABLE_BACKUP;
	$wpdb->query(
		"
        CREATE TABLE IF NOT EXISTS `{$table_backup}` (
            `id`             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `post_id`        BIGINT UNSIGNED NOT NULL,
            `field_name`     VARCHAR(255)    NOT NULL,
            `acpt_data`      LONGTEXT        NOT NULL,
            `created_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `migration_key`  VARCHAR(20)     NOT NULL,
            UNIQUE INDEX `uk_backup` (`post_id`, `field_name`),
            INDEX `idx_migration` (`migration_key`)
        ) ENGINE={$engine} {$charset}
    "
	);

	$table_map = $wpdb->prefix . ACF_MIGRATION_TABLE_FIELD_MAP;
	$wpdb->query(
		"
        CREATE TABLE IF NOT EXISTS `{$table_map}` (
            `id`             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `field_name`     VARCHAR(255)    NOT NULL,
            `field_key`      VARCHAR(30)     NOT NULL,
            `field_type`     VARCHAR(30)     DEFAULT 'text',
            `group_key`      VARCHAR(30)     DEFAULT '',
            `is_repeater`    TINYINT(1)      DEFAULT 0,
            `parent_name`    VARCHAR(255)    DEFAULT '',
            `created_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE INDEX `uk_field_name` (`field_name`),
            INDEX `idx_field_key` (`field_key`)
        ) ENGINE={$engine} {$charset}
    "
	);

	// Persist existence in object cache (survives within a request +
	// persistent backends like Redis across requests)
	wp_cache_set( $cache_key, 1, ACF_MIGRATION_CACHE_GROUP, HOUR_IN_SECONDS );
}

// Create tables early — based on object cache check, runs DDL only once
frl_acf_migration_create_tables();

// ─── Admin UI (Fralenuvole integration) ────────────────────────

// Only register admin features if fralenuvole is loaded and admin area
if ( ! function_exists( 'frl_get_option' ) ) {
	return;
}

// Early return if the module is disabled via environment config
if ( frl_get_option( 'disable_acf_migration' ) ) {
	return;
}

/**
 * Register the migration admin page.
 */
function frl_acf_migration_admin_menu(): void {
	add_submenu_page(
		'fralenuvole',
		__( 'ACF Migration', 'fralenuvole' ),
		__( 'ACF Migration', 'fralenuvole' ),
		'manage_options',
		'frl-acf-migration',
		'frl_acf_migration_admin_page'
	);
}
add_action( 'admin_menu', 'frl_acf_migration_admin_menu', 30 );

/**
 * Enqueue admin assets.
 *
 * @param string $hook Current admin page hook.
 */
function frl_acf_migration_admin_scripts( string $hook ): void {
	if ( $hook !== 'fralenuvole_page_frl-acf-migration' ) {
		return;
	}

	wp_enqueue_script(
		'frl-acf-migration-admin',
		FRL_DIR_URL . 'modules/acf-migration/assets/admin-acf-migration.js',
		array( 'jquery' ),
		'1.0.0',
		true
	);

	wp_localize_script(
		'frl-acf-migration-admin',
		'FRL_ACF_MIGRATION',
		array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'frl_acf_migration' ),
			'i18n'    => array(
				'confirmCleanup'  => __( 'WARNING: This will DROP ACPT database tables. Back up your DB first. Continue?', 'fralenuvole' ),
				'confirmRollback' => __( 'Rollback will delete all created SCF field groups and meta rows. Continue?', 'fralenuvole' ),
			),
		)
	);

	wp_enqueue_style(
		'frl-acf-migration-admin',
		FRL_DIR_URL . 'modules/acf-migration/assets/admin-acf-migration.css',
		array(),
		'1.0.0'
	);
}
add_action( 'admin_enqueue_scripts', 'frl_acf_migration_admin_scripts' );

/**
 * Helper: query the migration log table.
 *
 * @return array<int, array>
 */
function frl_acf_migration_get_log_entries(): array {
	global $wpdb;
	$table = $wpdb->prefix . ACF_MIGRATION_TABLE_LOG;
	$rows  = $wpdb->get_results( "SELECT * FROM `{$table}` ORDER BY `created_at` DESC", ARRAY_A );
	return is_array( $rows ) ? $rows : array();
}

/**
 * Helper: get the most recent log entry.
 *
 * @return array|null
 */
function frl_acf_migration_get_latest_entry(): ?array {
	$entries = frl_acf_migration_get_log_entries();
	return ! empty( $entries ) ? $entries[0] : null;
}

/**
 * Helper: get repeater configs from the most recent migration's field map.
 *
 * @return array
 */
function frl_acf_migration_get_repeater_configs(): array {
	// Cache in a static variable — the field map doesn't change between imports
	static $configs = null;
	if ( $configs !== null ) {
		return $configs;
	}

	global $wpdb;
	$table_map = $wpdb->prefix . ACF_MIGRATION_TABLE_FIELD_MAP;

	// Get all repeater entries from field_map
	$rows = $wpdb->get_results(
		"SELECT field_name, field_key, field_type, group_key FROM `{$table_map}` WHERE is_repeater = 1",
		ARRAY_A
	);

	if ( empty( $rows ) ) {
		$configs = array();
		return $configs;
	}

	$configs = array();
	foreach ( $rows as $row ) {
		$name = $row['field_name'];
		// Fetch sub-fields with their field_type (parent_name matches this repeater name)
		$subs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT field_name, field_key, field_type FROM `{$table_map}` WHERE parent_name = %s ORDER BY id ASC",
				$name
			),
			ARRAY_A
		);

		$sub_fields = array();
		if ( ! empty( $subs ) ) {
			foreach ( $subs as $sub ) {
				// Strip repeater prefix to get sub-field name: "service-faqs_service-faq_question" -> "question"
				$sub_name                = substr( $sub['field_name'], strlen( $name ) + 1 );
				$sub_fields[ $sub_name ] = array(
					'key'  => $sub['field_key'],
					'type' => $sub['field_type'] ?? 'text',
				);
			}
		}

		$configs[ $name ] = array(
			'name'       => $name,
			'label'      => $name,
			'key'        => $row['field_key'],
			'sub_fields' => $sub_fields,
		);
	}

	return $configs;
}

/**
 * Render the migration admin page.
 */
function frl_acf_migration_admin_page(): void {
	$shim_enabled   = get_option( ACF_MIGRATION_SHIM_OPTION, 0 );
	$entries        = frl_acf_migration_get_log_entries();
	$last_migration = ! empty( $entries ) ? $entries[0] : null;
	?>
	<div class="wrap frl-acf-migration-wrap">
		<h1><?php esc_html_e( 'ACPT → SCF/ACF Migration', 'fralenuvole' ); ?></h1>

		<!-- Status -->
		<div class="frl-migration-card">
			<h2><?php esc_html_e( 'Status', 'fralenuvole' ); ?></h2>
			<table class="widefat striped">
				<tr>
					<th><?php esc_html_e( 'SCF/ACF Active', 'fralenuvole' ); ?></th>
					<td>
						<?php if ( function_exists( 'get_field' ) ) : ?>
							<span class="frl-status-ok">✓ <?php esc_html_e( 'Yes', 'fralenuvole' ); ?></span>
						<?php else : ?>
							<span class="frl-status-warn">✗ <?php esc_html_e( 'No — install SCF or ACF first', 'fralenuvole' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Compat Shim', 'fralenuvole' ); ?></th>
					<td>
						<?php if ( $shim_enabled ) : ?>
							<span class="frl-status-ok">✓ <?php esc_html_e( 'Enabled — get_post_meta() returns ACPT format', 'fralenuvole' ); ?></span>
						<?php else : ?>
							<span><?php esc_html_e( 'Disabled', 'fralenuvole' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<?php if ( $last_migration ) : ?>
				<tr>
					<th><?php esc_html_e( 'Last Migration', 'fralenuvole' ); ?></th>
					<td>
						<?php echo esc_html( $last_migration['created_at'] ?? 'Unknown' ); ?>
						(<?php echo esc_html( $last_migration['status'] ?? 'unknown' ); ?>)
					</td>
				</tr>
				<?php endif; ?>
			</table>
		</div>

		<!-- Actions -->
		<div class="frl-migration-card">
			<h2><?php esc_html_e( 'Actions', 'fralenuvole' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Run migration commands via WP-CLI for production use:', 'fralenuvole' ); ?>
			</p>
			<pre class="frl-cli-examples">
# Export ACPT definitions
wp acpt-migrate export --source=/path/to/acpt-export.json --output=/path/to/fields.ufj.json

# Dry-run import (preview only)
wp acpt-migrate import --file=/path/to/fields.ufj.json --dry-run

# Real import with shim
wp acpt-migrate import --file=/path/to/fields.ufj.json

# Validate
wp acpt-migrate validate --file=/path/to/fields.ufj.json --sample=50

# Rollback last migration
wp acpt-migrate rollback

# Cleanup ACPT data (after validation)
wp acpt-migrate cleanup

# Toggle shim
wp acpt-migrate shim on
wp acpt-migrate shim off</pre>
		</div>

		<!-- Shim Toggle -->
		<div class="frl-migration-card">
			<h2><?php esc_html_e( 'Compatibility Shim', 'fralenuvole' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'When enabled, get_post_meta() calls for repeater fields return ACPT-format data so third-party plugins continue working during migration.', 'fralenuvole' ); ?>
			</p>
			<form method="post" action="">
				<?php wp_nonce_field( 'frl_acf_migration_action', 'frl_acf_migration_nonce' ); ?>
				<?php if ( $shim_enabled ) : ?>
					<button type="submit" name="frl_acf_migration_shim" value="0" class="button button-secondary">
						<?php esc_html_e( 'Disable Shim', 'fralenuvole' ); ?>
					</button>
				<?php else : ?>
					<button type="submit" name="frl_acf_migration_shim" value="1" class="button button-primary">
						<?php esc_html_e( 'Enable Shim', 'fralenuvole' ); ?>
					</button>
				<?php endif; ?>
			</form>
		</div>

		<!-- Migration Log -->
		<?php if ( ! empty( $entries ) ) : ?>
		<div class="frl-migration-card">
			<h2><?php esc_html_e( 'Migration History', 'fralenuvole' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Key', 'fralenuvole' ); ?></th>
						<th><?php esc_html_e( 'Date', 'fralenuvole' ); ?></th>
						<th><?php esc_html_e( 'Status', 'fralenuvole' ); ?></th>
						<th><?php esc_html_e( 'UFJ File', 'fralenuvole' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $entries as $entry ) : ?>
					<tr>
						<td><?php echo esc_html( $entry['migration_key'] ); ?></td>
						<td><?php echo esc_html( $entry['created_at'] ); ?></td>
						<td><?php echo esc_html( $entry['status'] ); ?></td>
						<td><?php echo esc_html( $entry['ufj_file'] ?? '' ); ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Handle admin form submissions (shim toggle).
 */
function frl_acf_migration_handle_actions(): void {
	if ( ! isset( $_POST['frl_acf_migration_shim'] ) || ! isset( $_POST['frl_acf_migration_nonce'] ) ) {
		return;
	}
	if ( ! wp_verify_nonce( $_POST['frl_acf_migration_nonce'], 'frl_acf_migration_action' ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$enable = $_POST['frl_acf_migration_shim'] === '1';
	update_option( ACF_MIGRATION_SHIM_OPTION, $enable ? 1 : 0 );

	if ( $enable ) {
		$configs = frl_acf_migration_get_repeater_configs();
		if ( ! empty( $configs ) && class_exists( 'Frl_Acpt_Compat_Shim' ) ) {
			$shim = new Frl_Acpt_Compat_Shim( $configs );
			$shim->enable();
		}
	}
}
add_action( 'admin_init', 'frl_acf_migration_handle_actions' );

/**
 * Auto-enable shim on plugin load if enabled in options.
 */
function frl_acf_migration_auto_enable_shim(): void {
	if ( ! get_option( ACF_MIGRATION_SHIM_OPTION, 0 ) ) {
		return;
	}

	$configs = frl_acf_migration_get_repeater_configs();
	if ( ! empty( $configs ) && class_exists( 'Frl_Acpt_Compat_Shim' ) ) {
		$shim = new Frl_Acpt_Compat_Shim( $configs );
		$shim->enable();
	}
}
add_action( 'init', 'frl_acf_migration_auto_enable_shim', 50 ); // After SCF init

/**
 * Drop custom tables on module deactivation (DELETES ALL MIGRATION DATA).
 *
 * Called via register_deactivation_hook or manually.
 */
function frl_acf_migration_drop_tables(): void {
	global $wpdb;
	$tables = array(
		ACF_MIGRATION_TABLE_LOG,
		ACF_MIGRATION_TABLE_BACKUP,
		ACF_MIGRATION_TABLE_FIELD_MAP,
	);
	foreach ( $tables as $table ) {
		$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}{$table}`" );
	}
	delete_option( ACF_MIGRATION_SHIM_OPTION );
}
