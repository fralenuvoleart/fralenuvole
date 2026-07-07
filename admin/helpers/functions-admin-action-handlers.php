<?php

/**
 * Fralenuvole
 * functions-admin-action-handlers.php - Post action handler functions
 *
 * This file contains all the admin action handlers that process GET/POST requests
 * for cache clearing, environment resets, and other administrative actions.
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Auto-discover and register admin-post and AJAX handlers.
 *
 * Functions with the `frl_post_` prefix are auto-registered. This function registers handlers
 * that process form submissions through WordPress's admin-post.php system.
 *
 * @return void
 */
function frl_autodiscover_admin_actions() {
	// Only proceed if we're in admin context and processing actions
	if ( ! frl_is_admin() || ! frl_is_administrator_action() ) {
		return;
	}

	static $discovered = null;
	if ( $discovered !== null ) {
		return;
	}
	$discovered = true;

	// Register critical handler explicitly
	add_action( 'admin_post_frl_save_options', 'frl_settings_fields_handle_save_options', 10, 0 );

	// action handler like dashboard widget
	// frl_post_action_dashboard_widgets is auto-discovered

	// Get all defined user functions
	$user_functions = get_defined_functions()['user'];

	foreach ( $user_functions as $func ) {
		if ( str_starts_with( $func, frl_prefix( 'post_' ) ) ) {
			// Register all frl_post_ functions to admin-post system
			$hook_name = 'admin_post_' . $func;
			add_action( $hook_name, $func, 10, 0 );

			// Check if this is also an AJAX handler (has "ajax_" after the prefix)
			if ( str_starts_with( $func, frl_prefix( 'post_ajax_' ) ) ) {
				$ajax_hook = 'wp_ajax_' . $func;
				add_action( $ajax_hook, $func, 10, 0 );
			}
		}
	}
	// Allow extensions
	do_action( 'frl_autodiscover_admin_actions' );
}

/**
 * Handle dashboard widgets action.
 *
 * @return void
 */
function frl_post_action_dashboard_widgets() {
	// 1. Verify Capability & Nonce
	// Nonce action should ideally depend on the specific action being performed
	// We need to retrieve the action type *before* checking nonce.
	$action_type  = isset( $_REQUEST['type'] ) ? sanitize_key( $_REQUEST['type'] ) : '';
	$widget_key   = isset( $_REQUEST['widget_key'] ) ? sanitize_text_field( $_REQUEST['widget_key'] ) : '';
	$widget_group = isset( $_REQUEST['widget_group'] ) ? sanitize_key( $_REQUEST['widget_group'] ) : '';

	// Verify dynamic nonce and capability for widget action
	frl_verify_dynamic_nonce(
		'frl_dashboard_widget_{type}_{group}_{key}',
		array(
			'type'  => $action_type,
			'group' => $widget_group,
			'key'   => $widget_key,
		),
		'_wpnonce',
		'REQUEST',
		'manage_options'
	);

	// 2. Determine the specific action
	$result       = false;
	$message      = '';
	$message_type = 'warning'; // Default to warning
	$key_label    = ucwords( str_replace( '_', ' ', $widget_key ) );

	switch ( $action_type ) {

		case 'refresh_cache': // Generic cache clear
			if ( empty( $widget_group ) || empty( $widget_key ) ) {
				$message      = sprintf( __( 'Missing %1$s group or %2$s key for cache refresh.', 'fralenuvole' ), $widget_group, $key_label );
				$message_type = 'error';
				break;
			}
			$result = frl_cache_clear( $widget_group, $widget_key );

			$message = $result ?
				sprintf(
					__( '%1$s cleared from %2$s cache', FRL_PREFIX ),
					$key_label,
					$widget_group
				)
				:
				sprintf(
					__( '%1$s widget could not be cleared from %2$s cache', FRL_PREFIX ),
					$key_label,
					$widget_group
				);

			$message_type = $result ? 'success' : 'warning';
			break;

		default:
			$message      = __( 'Invalid or missing widget action type specified.', FRL_PREFIX );
			$message_type = 'error';
			break;
	}

	// 3. Add admin notice
	frl_add_admin_notice( $message, $message_type );

	// 4. Redirect back to the referring page (dashboard)
	frl_safe_redirect();
	exit;
}

/**
 * Handle settings update.
 *
 * @param array|null $updated_options      Updated options to apply.
 * @param bool       $show_no_changes_notice Whether to show a notice if no changes were detected.
 * @return void
 */
function frl_handle_settings_update( $updated_options = null, $show_no_changes_notice = false ) {
	frl_apply_debug_settings( $updated_options, $show_no_changes_notice );
	frl_clear_dashboard();
}

/**
 * Handle clear dashboard action.
 *
 * @return array{success: bool, message_parts: array, notice_type: string} Result of the clear operation.
 */
function frl_handle_action_clear_dashboard() {
	// Note: frl_clear_dashboard adds its own admin notice internally.
	// We still call it but don't need to generate a message here for the central handler.
	frl_clear_dashboard();
	// Return success but empty message parts as the notice is already added.
	return array(
		'success'       => true,
		'message_parts' => array(),
		'notice_type'   => 'success',
	);
}

/**
 * Handle clear cache group action.
 *
 * @param string $action Action name containing the group to clear.
 * @return array{success: bool, message_parts: array, notice_type: string} Result of the clear operation.
 */
function frl_handle_action_clear_cache_group( $action ) {
	// Extract group name
	$group_to_clear = substr( $action, strlen( 'clear_cache_' ) );

	// Validate group name
	if ( ! preg_match( '/^[a-z0-9_-]+$/', $group_to_clear ) ) {
		return array(
			'success'       => false,
			'message_parts' => array(
				sprintf(
					__( 'Invalid cache group name specified: %s', FRL_PREFIX ),
					esc_html( $group_to_clear )
				),
			),
			'notice_type'   => 'error',
		);
	}

	$stats         = frl_cache_clear( $group_to_clear );
	$message_parts = array();
	if ( is_array( $stats ) ) {
		$persistent_label = wp_using_ext_object_cache() ? 'object cache items' : 'transients';
		$runtime_count    = $stats['runtime'] ?? 0;
		$persistent_count = $stats['persistent'] ?? 0;
		$wp_core_count    = $stats['wordpress'] ?? 0;
		$dependencies     = $stats['dependencies'] ?? array();

		// Primary group message
		$message_parts[] = sprintf(
			__( 'Cache group %1$s: %2$d runtime items, %3$d persistent %4$s cleared.', FRL_PREFIX ),
			strtoupper( esc_html( $group_to_clear ) ),
			$runtime_count,
			$persistent_count,
			esc_html( $persistent_label )
		);

		// Add WordPress core cache info if cleared
		if ( $wp_core_count > 0 ) {
			$message_parts[] = sprintf(
				__( '%d related WordPress core cache(s) cleared (e.g., alloptions).', FRL_PREFIX ),
				$wp_core_count
			);
		}

		// Add dependency info if cleared
		if ( ! empty( $dependencies ) ) {
			$dep_runtime_total    = 0;
			$dep_persistent_total = 0;
			$dep_wp_total         = 0;
			$dep_group_names      = array();

			foreach ( $dependencies as $dep_group => $dep_stats ) {
				$dep_group_names[]     = esc_html( $dep_group );
				$dep_runtime_total    += $dep_stats['runtime'] ?? 0;
				$dep_persistent_total += $dep_stats['persistent'] ?? 0;
				$dep_wp_total         += $dep_stats['wordpress'] ?? 0;
			}

			$message_parts[] = sprintf(
				__( 'Dependencies cleared (%1$s): %2$d runtime items, %3$d persistent %4$s, %5$d WP core items.', FRL_PREFIX ),
				implode( ', ', $dep_group_names ),
				$dep_runtime_total,
				$dep_persistent_total,
				esc_html( $persistent_label ),
				$dep_wp_total
			);
		}

		return array(
			'success'       => true,
			'message_parts' => $message_parts,
			'notice_type'   => 'success',
		);
	} else {
		$message_parts[] = sprintf(
			__( 'Cache group "%s" cleared (statistics unavailable).', FRL_PREFIX ),
			esc_html( $group_to_clear )
		);
		// Consider this success even without stats, as the clear likely happened.
		return array(
			'success'       => true,
			'message_parts' => $message_parts,
			'notice_type'   => 'info',
		);
	}
}

/**
 * Handle clear OPcache action.
 *
 * @return array{success: bool, message_parts: array, notice_type: string} Result of the OPcache reset.
 */
function frl_handle_action_clear_cache_opcache() {
	if ( ! frl_has_access() ) { // Ensure user has capabilities
		return array(
			'success'       => false,
			'message_parts' => array( __( 'You are not authorized to perform this action.', FRL_PREFIX ) ),
			'notice_type'   => 'error',
		);
	}

	$stats = frl_cache_clear( 'opcache' );

	$message_parts = array( '<strong>' . __( 'OPcache Reset:', FRL_PREFIX ) . '</strong>' );
	$notice_type   = 'success';

	// OPcache Reset
	$opcache_status = $stats['opcache_reset'] ?? 'not_attempted_in_manager'; // Default if key doesn't exist because it's commented out
	if ( $opcache_status !== 'not_attempted_in_manager' ) {
		$message_parts[] = __( '- PHP OPcache Reset:', FRL_PREFIX ) . ' ' . esc_html( ucfirst( str_replace( '_', ' ', $opcache_status ) ) );
	} else {
		$message_parts[] = __( '- PHP OPcache Reset: Not currently performed by Cache Manager.', FRL_PREFIX );
	}

	return array(
		'success'       => true,
		'message_parts' => $message_parts,
		'notice_type'   => $notice_type,
	);
}

/**
 * Handle reset environment action.
 *
 * @return array{success: bool, message_parts: array, notice_type: string} Detailed results of the environment reset.
 */
function frl_handle_action_reset_environment() {
	$results       = frl_environment_enforce_settings( true );
	$message_parts = array();
	$notice_type   = 'success'; // Assume success

	if ( is_array( $results ) ) {
		$message_parts[] = '<strong>' . __( 'Environment Reset', FRL_PREFIX ) . '</strong>';
		if ( isset( $results['environment_prefix'] ) && isset( $results['environment_type'] ) ) {
			$message_parts[] = sprintf(
				__( '<strong>Config</strong>: %1$s %2$s', FRL_PREFIX ),
				strtoupper( esc_html( $results['environment_prefix'] ) ),
				strtoupper( esc_html( $results['environment_type'] ) )
			);
		} else {
			$message_parts[] = __( 'Environment details unavailable.', FRL_PREFIX );
			$notice_type     = 'warning';
		}

		// Helper closure to format count/list messages
		$format_list = function ( $label, $items, $prefix_keys = false ) use ( &$message_parts ) {
			if ( ! empty( $items ) ) {
				$count           = count( $items );
				$keys_to_display = $prefix_keys ? array_map(
					function ( $k ) {
						return frl_prefix( $k );
					},
					$items
				) : $items;
				$message_parts[] = sprintf(
					'<strong>%d %s</strong>: %s',
					$count,
					$label,
					implode( ', ', array_map( 'esc_html', $keys_to_display ) )
				);
			}
		};

		// WP Options
		$format_list( __( 'WP Options Updated', FRL_PREFIX ), $results['wp_options']['updated'] ?? array() );
		if ( ! empty( $results['wp_options']['skipped'] ) ) {
			$skipped_count   = count( $results['wp_options']['skipped'] );
			$message_parts[] = sprintf(
				'%d %s (%s: %s)',
				$skipped_count,
				__( 'WP Options Skipped', FRL_PREFIX ),
				__( 'already correct', FRL_PREFIX ),
				implode( ', ', array_map( 'esc_html', $results['wp_options']['skipped'] ) )
			);
		}

		// Plugin Options
		$format_list( __( 'Plugin Options Updated', FRL_PREFIX ), $results['plugin_options']['updated'] ?? array(), true );
		$format_list( __( 'Plugin Options Loaded from File', FRL_PREFIX ), $results['plugin_options']['file_loaded'] ?? array(), true );
		$format_list( __( 'Plugin Options File Missing', FRL_PREFIX ), $results['plugin_options']['file_missing'] ?? array(), true );

		// Plugins
		$format_list( __( 'Plugins Activated', FRL_PREFIX ), $results['plugins']['activated'] ?? array() );
		$format_list( __( 'Plugins Deactivated', FRL_PREFIX ), $results['plugins']['deactivated'] ?? array() );
		$format_list( __( 'Plugins Ignored', FRL_PREFIX ), $results['plugins']['ignored'] ?? array() );
		if ( ! empty( $results['plugins']['update_error'] ) ) {
			$message_parts[] = sprintf(
				'<strong style="color: red;">%s</strong>: %s',
				__( 'Plugin Update Issue', FRL_PREFIX ),
				esc_html( $results['plugins']['update_error'] )
			);
			$notice_type     = 'warning'; // Downgrade to warning if there was a plugin update issue
		}

		// Modules
		$format_list( __( 'Modules Activated', FRL_PREFIX ), $results['modules']['activated'] ?? array() );
		$format_list( __( 'Modules Deactivated', FRL_PREFIX ), $results['modules']['deactivated'] ?? array() );

		// Reset Customizations (if forced)
		if ( frl_is_array_not_empty( $results, 'reset_customizations' ) ) {
			if ( ! empty( $results['reset_customizations']['ignored_plugins_cleared'] ) ) {
				$message_parts[] = __( 'Ignored plugins cleared.', FRL_PREFIX );
			}
			if ( ! empty( $results['reset_customizations']['ignored_options_cleared'] ) ) {
				$message_parts[] = __( 'Ignored options cleared.', FRL_PREFIX );
			}
		}

		// Cache - Note: This now uses purge_light, so message might need adjustment
		$message_parts[] = __( 'Light Caches Cleared.', FRL_PREFIX );
	} else {
		/** @var array|null $results */
		$message_parts[] = is_array( $results ) && array_key_exists( 'message', $results ) // @phpstan-ignore-line argument.type
			? (string) $results['message']
			: __( 'Environment reset executed, but no detailed results were returned.', FRL_PREFIX );
		$notice_type     = 'warning';
	}

	return array(
		'success'       => $notice_type === 'success',
		'message_parts' => $message_parts,
		'notice_type'   => $notice_type,
	);
}

/**
 * Handle reset environment ignored list action.
 *
 * @return array{success: bool, message_parts: array, notice_type: string} Result of clearing ignored items.
 */
function frl_handle_action_reset_environment_ignored() {
	$results         = frl_environment_reset_ignored();
	$message_parts   = array();
	$message_parts[] = '<strong>' . __( 'Environment Ignored List', FRL_PREFIX ) . '</strong>';
	$notice_type     = 'success';

	/** @var array $results */
	if ( ! empty( $results ) ) {
		$cleared_any = false;
		if ( ! empty( $results['ignored_plugins_cleared'] ) ) {
			$message_parts[] = __( 'Manually ignored Plugins cleared.', FRL_PREFIX );
			$cleared_any     = true;
		}
		if ( ! empty( $results['ignored_options_cleared'] ) ) {
			$message_parts[] = __( 'Manually ignored Options cleared.', FRL_PREFIX );
			$cleared_any     = true;
		}
		if ( ! $cleared_any ) {
			$message_parts[] = __( 'No manually ignored items found to clear.', FRL_PREFIX );
			$notice_type     = 'info';
		}
	} else {
		$message_parts[] = __( 'Failed to clear manually ignored items.', FRL_PREFIX );
		$notice_type     = 'error';
	}

	return array(
		'success'       => $notice_type === 'success',
		'message_parts' => $message_parts,
		'notice_type'   => $notice_type,
	);
}

/**
 * Handle reset debug configuration action.
 *
 * @return array{success: bool, message_parts: array, notice_type: string} Result of the debug config reset.
 */
function frl_handle_action_reset_debug_config() {
	// Verify nonce for security
	if ( ! frl_verify_plugin_action_nonce( 'reset_debug_config' ) ) {
		return array(
			'success'       => false,
			'message_parts' => array( __( 'Security check failed. Please try again.', FRL_PREFIX ) ),
			'notice_type'   => 'error',
		);
	}

	// Apply debug settings and force admin notice display, even if no changes are applied
	frl_apply_debug_settings( null, true );
	// Clear dashboard cache (adds its own notice)
	frl_clear_dashboard();

	// Return success, but empty message parts as notices are handled internally
	return array(
		'success'       => true,
		'message_parts' => array(),
		'notice_type'   => 'success',
	);
}

/**
 * Handle full plugin reset action.
 *
 * @return array{success: bool, message_parts: array, notice_type: string} Result of the plugin reset.
 */
function frl_handle_action_reset_plugin() {
	if ( ! frl_has_access() ) {
		return array(
			'success'       => false,
			'message_parts' => array( __( 'You are not authorized to reset the plugin.', FRL_PREFIX ) ),
			'notice_type'   => 'error',
		);
	}
	// Verify nonce first
	if ( ! frl_verify_plugin_action_nonce( 'reset_plugin' ) ) {
		return array(
			'success'       => false,
			'message_parts' => array( __( 'Security check failed. Please try again.', FRL_PREFIX ) ),
			'notice_type'   => 'error',
		);
	}

	// STEP 1: Delete plugin options from DB
	$results = frl_delete_plugin();

	// STEP 2: Reset any environment-specific ignored states.
	frl_environment_reset_ignored();

	// STEP 3: Restore defaults from unified option system
	// update_option() handles individual WP option cache updates.
	$defaults = frl_get_all_plugin_options_settings( null );

	if ( frl_is_array_not_empty( $defaults ) ) {
		foreach ( $defaults as $field_definition ) {
			// $field_definition is an associative array from $full_fields, e.g.:
			// ['id'=>'my_option', 'default'=>'val', 'type'=>'text', 'autoload'=>'yes', ...]
			// $full_fields is already filtered for formatters by frl_get_all_plugin_options_settings.

			$option_key_to_use = $field_definition['id'] ?? null;
			// 'default' key should exist in field definitions from $full_fields.
			// If it might be missing for some, a fallback or stricter check is needed.
			$value_to_restore       = $field_definition['default'] ?? null;
			$autoload_from_config   = $field_definition['autoload'] ?? 'yes';
			$type_for_normalization = $field_definition['type'] ?? 'text';

			if ( empty( $option_key_to_use ) ) {

				frl_log( FRL_NAME . ': Field definition encountered without an ID during plugin reset. Field: ' . print_r( $field_definition, true ) );

				continue;
			}

			$autoload            = frl_normalize_autoload( $autoload_from_config );
			$prefixed_key_to_use = frl_prefix( $option_key_to_use );
			$db_safe_value       = frl_normalize_option( $value_to_restore, $type_for_normalization );

			// No frl_update_option here to avoid cache clearing temporarily.
			$update_result = update_option( $prefixed_key_to_use, $db_safe_value, $autoload );

			if ( $update_result ) {
				++$results['options_restored'];
			}
		}
	}

	// Flush after bulk option writes.
	frl_flush_db();

	// STEP 4: CRITICAL - Ensure WordPress and plugin option caches reflect DB defaults.
	// This clear is ESSENTIAL before environment/debug settings are applied, as those
	// functions read option values to update wp-config.php. Stale cache data could
	// result in incorrect values being written to wp-config.
	frl_cache_clear( 'all' );

	// STEP 5: Run functions that rely on the new default options to set up plugin state.
	// frl_environment_enforce_settings(true) also calls frl_cache_clear('all') internally,
	// but that occurs AFTER it applies settings. The Step 4 clear above ensures clean
	// caches before any settings are read.
	frl_environment_enforce_settings( true );

	// frl_apply_debug_settings should run after environment settings are enforced
	// to reflect the final option state. It reads options to update wp-config.php.
	frl_apply_debug_settings( null, true );

	// STEP 6: Final comprehensive cache clear (good for a full reset).
	// This ensures the *next* request starts with completely fresh plugin caches.
	// It clears any runtime caches that may have been populated by Steps 5-6.
	frl_cache_clear( 'all' );
	frl_flush_db();

	frl_flush_rewrite_rules();
	// Format response
	$message_parts = array();
	$notice_type   = 'success';

	/** @var array $results */
	if ( isset( $results['cache_cleared'] ) ) {
		$message_parts[] = sprintf(
			__( 'Plugin reset successfully. Deleted: %1$d options, restored: %2$d options. All Caches cleared: %3$d runtime, %4$d object cache, %5$d transients', FRL_PREFIX ),
			$results['options_deleted'] ?? 0,
			$results['options_restored'] ?? 0,
			$results['cache_cleared']['runtime'] ?? 0,
			$results['cache_cleared']['object_cache'] ?? 0,
			$results['cache_cleared']['transients'] ?? 0
		);
	} else {
		$message_parts[] = __( 'Plugin reset completed, but detailed stats are unavailable.', FRL_PREFIX );
		$notice_type     = 'warning';
	}

	return array(
		'success'       => true,
		'message_parts' => $message_parts,
		'notice_type'   => $notice_type,
	);
}

/**
 * Handle MU-plugins synchronization action.
 *
 * @return array{success: bool, message_parts: array, notice_type: string} Result of the MU-plugins sync.
 */
function frl_handle_action_sync_mu_plugins() {
	// Verify nonce for security
	if ( ! frl_verify_plugin_action_nonce( 'sync_mu_plugins' ) ) {
		return array(
			'success'       => false,
			'message_parts' => array( __( 'Security check failed. Please try again.', FRL_PREFIX ) ),
			'notice_type'   => 'error',
		);
	}

	// Sync mu-plugins
	$sync_result = frl_mu_plugins_sync();

	// Clear dashbaord
	frl_cache_clear( 'adminui' );

	// Handle result
	if ( is_wp_error( $sync_result ) ) {
		return array(
			'success'       => false,
			'message_parts' => array( __( 'MU plugins sync failed: ', FRL_PREFIX ) . $sync_result->get_error_message() ),
			'notice_type'   => 'error',
		);
	}

	// Success - use the detailed message from sync result
	return array(
		'success'       => true,
		'message_parts' => array( $sync_result['message'] ),
		'notice_type'   => 'success',
	);
}

/**
 * Handle MU-plugins deletion action.
 *
 * @return array{success: bool, message_parts: array, notice_type: string} Result of the MU-plugins deletion.
 */
function frl_handle_action_delete_mu_plugins() {
	// Verify nonce for security
	if ( ! frl_verify_plugin_action_nonce( 'delete_mu_plugins' ) ) {
		return array(
			'success'       => false,
			'message_parts' => array( __( 'Security check failed. Please try again.', FRL_PREFIX ) ),
			'notice_type'   => 'error',
		);
	}

	// Delete mu-plugins
	$delete_result = frl_mu_plugins_delete();

	// Clear UI cache
	frl_cache_clear( 'adminui' );

	// Handle result
	if ( is_wp_error( $delete_result ) ) {
		return array(
			'success'       => false,
			'message_parts' => array( __( 'MU plugins deletion failed: ', FRL_PREFIX ) . $delete_result->get_error_message() ),
			'notice_type'   => 'error',
		);
	}

	// Success - use the detailed message from delete result
	return array(
		'success'       => true,
		'message_parts' => array( $delete_result['message'] ),
		'notice_type'   => 'success',
	);
}

/**
 * Handle deletion of orphaned plugin options.
 *
 * @return array{success: bool, message_parts: array, notice_type: string} Result of the orphan cleanup.
 */
function frl_handle_action_delete_orphan_options() {
	// Verify nonce for security
	if ( ! frl_verify_plugin_action_nonce( 'delete_orphan_options' ) ) {
		return array(
			'success'       => false,
			'message_parts' => array( __( 'Security check failed. Please try again.', FRL_PREFIX ) ),
			'notice_type'   => 'error',
		);
	}

	global $wpdb;
	$prefix        = frl_prefix();
	$message_parts = array();
	$notice_type   = 'success';

	// STEP 1: Get all plugin options currently in database
	$db_options_query = $wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( $prefix ) . '%'
	);

	$db_options_raw = $wpdb->get_col( $db_options_query );

	if ( empty( $db_options_raw ) ) {
		$message_parts[] = __( 'No plugin options found in database.', FRL_PREFIX );
		return array(
			'success'       => true,
			'message_parts' => $message_parts,
			'notice_type'   => 'info',
		);
	}

	$options_scanned = count( $db_options_raw );

	// Extract unprefixed option names from database
	$prefix_length  = strlen( $prefix );
	$db_option_keys = array();
	foreach ( $db_options_raw as $prefixed_name ) {
		$unprefixed_key                    = substr( $prefixed_name, $prefix_length );
		$db_option_keys[ $unprefixed_key ] = $prefixed_name; // Map unprefixed -> prefixed
	}

	// STEP 2: Get all defined plugin options (the whitelist)
	$defined_options = frl_get_all_plugin_options_settings( null );

	if ( ! frl_is_array_not_empty( $defined_options ) ) {
		frl_log( 'WARNING: No defined plugin options found during orphan cleanup. Aborting to prevent accidental deletion.' );
		$message_parts[] = __( 'No defined options found - aborting for safety.', FRL_PREFIX );
		return array(
			'success'       => false,
			'message_parts' => $message_parts,
			'notice_type'   => 'error',
		);
	}

	// Extract option IDs from defined options to create whitelist
	$defined_option_keys = array();
	foreach ( $defined_options as $field_definition ) {
		if ( isset( $field_definition['id'] ) && ! empty( $field_definition['id'] ) ) {
			$defined_option_keys[] = $field_definition['id'];
		}
	}

	if ( empty( $defined_option_keys ) ) {
		frl_log( 'WARNING: No valid option IDs extracted from defined options during orphan cleanup. Aborting.' );
		$message_parts[] = __( 'No valid option IDs found - aborting for safety.', FRL_PREFIX );
		return array(
			'success'       => false,
			'message_parts' => $message_parts,
			'notice_type'   => 'error',
		);
	}

	// STEP 3: Identify orphaned options (in DB but not in defined options)
	$orphaned_keys  = array();
	$preserved_keys = array();

	foreach ( $db_option_keys as $unprefixed_key => $prefixed_name ) {
		if ( in_array( $unprefixed_key, $defined_option_keys, true ) ) {
			// Option is defined in system - preserve it
			$preserved_keys[] = $unprefixed_key;
		} else {
			// Option is not defined - mark as orphaned
			$orphaned_keys[ $unprefixed_key ] = $prefixed_name;
		}
	}

	$options_preserved = count( $preserved_keys );

	// STEP 4: Safety check - abort if too many options would be deleted
	$orphan_count      = count( $orphaned_keys );
	$total_count       = count( $db_option_keys );
	$orphan_percentage = $total_count > 0 ? ( $orphan_count / $total_count ) * 100 : 0;

	// Safety threshold: abort if more than 50% of options would be deleted
	if ( $orphan_percentage > 50 ) {
		frl_log(
			'WARNING: Orphan cleanup would delete {percentage}% of plugin options ({orphan_count}/{total_count}). Aborting for safety.',
			array(
				'percentage'   => round( $orphan_percentage, 1 ),
				'orphan_count' => $orphan_count,
				'total_count'  => $total_count,
			)
		);
		$message_parts[] = sprintf(
			__( 'Safety check failed: Would delete %1$d%% of options (%2$d/%3$d) - aborting.', FRL_PREFIX ),
			round( $orphan_percentage, 1 ),
			$orphan_count,
			$total_count
		);
		return array(
			'success'       => false,
			'message_parts' => $message_parts,
			'notice_type'   => 'error',
		);
	}

	// STEP 5: Delete orphaned options if any found
	if ( empty( $orphaned_keys ) ) {
		$message_parts[] = sprintf(
			__( 'No orphaned options found - all %d options are properly defined.', FRL_PREFIX ),
			$options_scanned
		);
		return array(
			'success'       => true,
			'message_parts' => $message_parts,
			'notice_type'   => 'info',
		);
	}

	// Log what we're about to delete
	frl_log(
		'Deleting {count} orphaned plugin options: {options}',
		array(
			'count'   => $orphan_count,
			'options' => array_keys( $orphaned_keys ),
		),
		false
	);

	// Delete each orphaned option individually for better error handling
	$deleted_count   = 0;
	$deleted_options = array();

	foreach ( $orphaned_keys as $unprefixed_key => $prefixed_name ) {
		$delete_result = delete_option( $prefixed_name );
		if ( $delete_result ) {
			++$deleted_count;
			$deleted_options[] = $unprefixed_key;
		} else {
			frl_log( 'Failed to delete orphaned option: {option}', array( 'option' => $prefixed_name ) );
		}
	}

	// STEP 6: Clear relevant caches after deletion
	if ( $deleted_count > 0 ) {
		frl_cache_clear( 'options', 'all_options' );
		frl_cache_clear( 'adminui' );
		frl_flush_db();
	}

	$message_parts[] = sprintf(
		__( 'Orphan cleanup completed: %1$d options deleted, %2$d preserved.', FRL_PREFIX ),
		$deleted_count,
		$options_preserved
	);

	return array(
		'success'       => true,
		'message_parts' => $message_parts,
		'notice_type'   => $notice_type,
	);
}

/**
 * Verify the nonce for a specific plugin action.
 *
 * This function checks if the action is a public action (requiring only 'read' capability)
 * or a restricted admin action (requiring 'manage_options').
 *
 * @param string $action_name The action name to verify the nonce for.
 * @return bool True if the nonce is valid or verification is skipped for public actions, false otherwise.
 */
function frl_verify_plugin_action_nonce( $action_name ) {
	// Check if this action is registered as a low-security/public action
	// Ideally this registry would be shared, but for now we define the logic here:
	// If the action is known to be allowed for 'read' users in the dispatcher, we can skip strict nonce checks.
	// However, frl_verify_simple_nonce defaults to 'manage_options'.

	$capability              = 'manage_options';
	$skip_nonce_verification = false;

	// Modular check: If action is meant for logged-in users (emergency access), adjust cap and skip nonce
	if ( in_array( $action_name, FRL_PUBLIC_ACTIONS, true ) && is_user_logged_in() ) {
		$capability              = 'read';
		$skip_nonce_verification = true;
	}

	if ( $skip_nonce_verification ) {
		return true;
	}

	return frl_verify_simple_nonce(
		$action_name,                               // Will be converted to (frl_prefix($action_name) = frl_action_name)
		frl_prefix( $action_name ) . '_nonce',       // Field name matches frl_render_action_button format
		'GET',                                      // Data source
		$capability,                                // Capability check
		false                                       // Return bool, don't die
	);
}

/**
 * Verify admin action nonce
 *
 * @param string $nonce_field Nonce field name
 * @param string $nonce_action Nonce action name
 * @param string $redirect_url_base Base URL for redirect
 * @param array $redirect_args Additional redirect arguments
 * @return bool True if nonce is valid
 */
function frl_verify_admin_action_nonce( $nonce_field = '_wpnonce', $nonce_action = FRL_PREFIX . '_save_options', $redirect_url_base = '', $redirect_args = array() ) {
	// Use simple nonce for basic verification (no capability check, return false on failure)
	$verified = frl_verify_simple_nonce(
		$nonce_action,    // Use the full action as-is
		$nonce_field,
		'POST',
		null,             // No capability check
		false,            // Don't die, let us handle errors
		true              // raw_action = true (don't prefix)
	);

	if ( ! $verified ) {
		// Custom error handling with admin notices and redirects
		frl_add_admin_notice(
			__( 'Security check failed. The form may have expired. Please try again.', FRL_PREFIX ),
			'error',
			30
		);

		// Redirect if URL provided
		if ( ! empty( $redirect_url_base ) ) {
			$redirect_url = add_query_arg(
				array_merge( array( 'error' => 'nonce' ), $redirect_args ),
				$redirect_url_base
			);

			frl_safe_redirect( $redirect_url );
			exit;
		}

		// Otherwise, just die
		wp_die( __( 'Security check failed. Please go back and try again.', FRL_PREFIX ) );
	}

	return true;
}

/**
 * Verify a simple static nonce with an optional capability check.
 *
 * Helper for standard nonce verification patterns with static action names and managing error handling (either returning false or calling wp_die()).
 *
 * @param string $action Action name (will be prefixed with the plugin prefix unless $raw_action is true).
 * @param string $nonce_field Field name containing the nonce (default: 'nonce').
 * @param string $source Data source: 'GET', 'POST', or 'REQUEST' (default: 'GET').
 * @param string|null $cap Required capability for access (default: 'manage_options').
 * @param bool $should_die Whether to call wp_die() on failure (default: true).
 * @param bool $raw_action Whether to use $action as-is without prefixing (default: false).
 * @return bool True if verified, false if $should_die is false and verification failed.
 */
function frl_verify_simple_nonce( $action, $nonce_field = 'nonce', $source = 'GET', $cap = 'manage_options', $should_die = true, $raw_action = false ) {
	// Build the nonce action
	$nonce_action = $raw_action ? $action : frl_prefix( $action );

	// Get the appropriate superglobal
	$data = array();
	switch ( strtoupper( $source ) ) {
		case 'GET':
			$data = $_GET;
			break;
		case 'POST':
			$data = $_POST;
			break;
		case 'REQUEST':
		default:
			$data = $_REQUEST;
			break;
	}

	// Check if nonce field exists
	if ( ! isset( $data[ $nonce_field ] ) ) {
		if ( $should_die ) {
			wp_die( __( 'Security check failed: Nonce not found.', FRL_PREFIX ) );
		}
		return false;
	}

	// Verify the nonce
	$nonce_verified = wp_verify_nonce( sanitize_text_field( $data[ $nonce_field ] ), $nonce_action );

	if ( ! $nonce_verified ) {
		if ( $should_die ) {
			wp_die( __( 'Security check failed.', FRL_PREFIX ) );
		}
		return false;
	}

	// Check capability if required
	if ( $cap !== null && ! frl_has_access( $cap ) ) {
		if ( $should_die ) {
			wp_die( __( 'You do not have sufficient permissions to access this page.', FRL_PREFIX ) );
		}
		return false;
	}

	return true;
}

/**
 * Verify dynamic nonce
 *
 * @param string $pattern Nonce pattern
 * @param array $replacements Replacements for pattern
 * @param string $nonce_field Nonce field name
 * @param string $source Source of nonce (REQUEST, GET, POST)
 * @param string|null $cap Required capability
 * @param bool $should_die Whether to die on failure
 * @return bool True if nonce is valid
 */
function frl_verify_dynamic_nonce( $pattern, $replacements = array(), $nonce_field = '_wpnonce', $source = 'REQUEST', $cap = null, $should_die = true ) {
	// Build the nonce action by replacing placeholders
	$nonce_action = $pattern;
	foreach ( $replacements as $key => $value ) {
		$nonce_action = str_replace( '{' . $key . '}', $value, $nonce_action );
	}

	// Get the appropriate superglobal
	$data = array();
	switch ( strtoupper( $source ) ) {
		case 'GET':
			$data = $_GET;
			break;
		case 'POST':
			$data = $_POST;
			break;
		case 'REQUEST':
		default:
			$data = $_REQUEST;
			break;
	}

	// Check if nonce field exists
	if ( ! isset( $data[ $nonce_field ] ) ) {
		if ( $should_die ) {
			wp_die( __( 'Security check failed: Nonce not found.', FRL_PREFIX ) );
		}
		return false;
	}

	// Verify the nonce
	$nonce_verified = wp_verify_nonce( sanitize_text_field( $data[ $nonce_field ] ), $nonce_action );

	if ( ! $nonce_verified ) {
		if ( $should_die ) {
			wp_die( __( 'Security check failed.', FRL_PREFIX ) );
		}
		return false;
	}

	// Check capability if required
	if ( $cap !== null && ! frl_has_access( $cap ) ) {
		if ( $should_die ) {
			wp_die( __( 'You do not have permission to perform this action.', FRL_PREFIX ) );
		}
		return false;
	}

	return true;
}
