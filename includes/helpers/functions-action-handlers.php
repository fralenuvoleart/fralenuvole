<?php

/**
 * Fralenuvole
 * functions-action-handlers.php - Plugin _GET_action handler functions
 * Loaded in both logged frontend and admin contexts
 *
 * This file contains all the admin action handlers that process GET/POST requests for cache clearing, environment resets, and other administrative actions.
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handle admin actions dispatched via GET requests.
 *
 * Processes direct GET requests with the `frl_action` parameter.
 * It is completely independent from admin-post.php handlers registered by frl_autodiscover_admin_actions.
 *
 * @return void
 */
function frl_process_plugin_actions() {
	static $is_processing = false;
	if ( $is_processing ) {
		return;
	}

	$action_param_name = frl_prefix( 'action' );
	$action            = isset( $_GET[ $action_param_name ] ) ? sanitize_key( $_GET[ $action_param_name ] ) : '';

	// Check capability and if action parameter exists
	$cap_check = ! empty( $action ) && frl_has_access();

	if ( ! $cap_check ) {
		return;
	}

	$is_processing = true;
	$result        = array();

	// Define the action registry
	$action_registry = array(
		'clear_dashboard'           => 'frl_handle_action_clear_dashboard',
		'clear_cache_light'         => 'frl_handle_action_clear_cache_light',
		'clear_cache_all'           => 'frl_handle_action_clear_cache_all',
		'clear_wp_alloptions'        => 'frl_handle_action_clear_wp_alloptions',
		'clear_plugin_transients'   => 'frl_handle_action_clear_plugin_transients',
		'clear_website_transients'  => 'frl_handle_action_clear_website_transients',
		'clear_scripts_tags'        => 'frl_handle_action_clear_scripts_tags',
		'clear_shortcodes'          => 'frl_handle_action_clear_shortcodes',
		'reset_environment'         => 'frl_handle_action_reset_environment',
		'reset_environment_ignored' => 'frl_handle_action_reset_environment_ignored',
		'reset_debug_config'        => 'frl_handle_action_reset_debug_config',
		'reset_plugin'              => 'frl_handle_action_reset_plugin',
		'delete_mu_plugins'         => 'frl_handle_action_delete_mu_plugins',
		'delete_orphan_options'     => 'frl_handle_action_delete_orphan_options',
		'sync_mu_plugins'           => 'frl_handle_action_sync_mu_plugins',
		'flush_rewrite_rules'       => 'frl_handle_action_flush_rewrite_rules',
		// Add other static actions here if needed
	);

	/**
	 * Filter the action registry so modules can register their own actions.
	 *
	 * @param array<string,string> $action_registry Map of action key => handler function.
	 */
	$action_registry = apply_filters( 'frl_action_registry', $action_registry );

	// Check if the action is in the registry
	if ( isset( $action_registry[ $action ] ) ) {
		$handler_function = $action_registry[ $action ];
		// @phpstan-ignore function.alreadyNarrowedType
		if ( function_exists( $handler_function ) ) {
			$result = $handler_function();
		} else {
			// Log error if handler function doesn't exist
			frl_log( 'Admin action handler function not found: {function}', array( 'function' => $handler_function ) );
			$result = array(
				'success'       => false,
				'message_parts' => array( __( 'Action handler missing.', FRL_PREFIX ) ),
				'notice_type'   => 'error',
			);
		}
	} elseif ( str_starts_with( $action, 'clear_cache_' ) ) {
		// Check for dynamic cache clear action pattern
		if ( function_exists( 'frl_handle_action_clear_cache_group' ) ) {
			$result = frl_handle_action_clear_cache_group( $action );
		} else {
			frl_log( 'Admin action handler function not found: frl_handle_action_clear_cache_group' );
			$result = array(
				'success'       => false,
				'message_parts' => array( __( 'Action handler missing.', FRL_PREFIX ) ),
				'notice_type'   => 'error',
			);
		}
	} else {
		// Handle unknown actions
		$result = array(
			'success'       => false,
			'message_parts' => array( __( 'Unknown action specified.', FRL_PREFIX ) ),
			'notice_type'   => 'warning',
		);
	}

	// Centralized feedback and redirect
	$notice_message = '';
	$notice_type    = 'info'; // Default type

	// Format message based on handler result structure
	if ( ! empty( $result['message_parts'] ) ) {
		$notice_message = implode( "<br>\n", $result['message_parts'] );
		$notice_type    = $result['notice_type'] ?? 'info';
	} // If handler returned empty message_parts (e.g., clear_dashboard), no notice is added here.

	if ( ! empty( $notice_message ) ) {
		frl_add_admin_notice( $notice_message, $notice_type, 45 );
	}

	$is_processing = false; // Reset processing flag

	// Always redirect after processing an action
	frl_safe_redirect();
}

/**
 * Handle the 'clear_plugin_transients' action.
 *
 * @return array{success: bool, message_parts: string[], notice_type: string} Result array.
 */
function frl_handle_action_clear_plugin_transients() {
	$orchestrated = Frl_Cache_Operations::run( 'action_clear_plugin_transients' );

	$deleted = $orchestrated['steps'][0]['result'] ?? array();
	if ( is_array( $deleted ) && isset( $deleted['transients'] ) ) {
		return array(
			'success'       => true,
			'message_parts' => array(
				sprintf(
					__( 'Cleared %d plugin transients successfully', FRL_PREFIX ),
					$deleted['transients']
				),
			),
			'notice_type'   => 'success',
		);
	} else {
		return array(
			'success'       => false,
			'message_parts' => array( __( 'Failed to clear plugin transients.', FRL_PREFIX ) ),
			'notice_type'   => 'error',
		);
	}
}

/**
 * Handle the 'clear_website_transients' action.
 *
 * @return array{success: bool, message_parts: string[], notice_type: string} Result array.
 */
function frl_handle_action_clear_website_transients() {
	$orchestrated = Frl_Cache_Operations::run( 'action_clear_website_transients' );

	$deleted = $orchestrated['steps'][0]['result'] ?? array();
	if ( is_array( $deleted ) && isset( $deleted['transients'] ) ) {
		return array(
			'success'       => true,
			'message_parts' => array(
				sprintf(
					__( 'Cleared %d website transients successfully', FRL_PREFIX ),
					$deleted['transients']
				),
			),
			'notice_type'   => 'success',
		);
	} else {
		return array(
			'success'       => false,
			'message_parts' => array( __( 'Failed to clear website transients.', FRL_PREFIX ) ),
			'notice_type'   => 'error',
		);
	}
}

/**
 * Handle the 'clear_scripts_tags' action.
 *
 * Groups to clear are defined by FRL_CACHE_SCRIPTS_GROUPS in config-cache-operations.php.
 *
 * @return array{success: bool, message_parts: string[], notice_type: string} Result array.
 */
function frl_handle_action_clear_scripts_tags() {
	$orchestrated  = Frl_Cache_Operations::run( 'action_clear_scripts_tags' );
	$message_parts = array();
	$success       = false;

	// Single batched step: result carries aggregate stats + per-group breakdown.
	$step  = $orchestrated['steps'][0] ?? array();
	$stats = $step['result'] ?? array();

	if ( ( $step['success'] ?? false ) && is_array( $stats ) && isset( $stats['persistent'] ) ) {
		$groups          = array_map( 'ucfirst', array_keys( $stats['groups'] ?? array() ) );
		$message_parts[] = sprintf(
			__( '%1$s caches cleared: %2$d persistent items deleted.', FRL_PREFIX ),
			implode( ', ', $groups ),
			$stats['persistent']
		);
		$success         = true;
	} else {
		$message_parts[] = __( 'Failed to clear scripts caches or get stats.', FRL_PREFIX );
	}

	return array(
		'success'       => $success,
		'message_parts' => $message_parts,
		'notice_type'   => $success ? 'success' : 'error',
	);
}
/**
 * Handle the 'clear_shortcodes' action.
 *
 * @return array{success: bool, message_parts: string[], notice_type: string} Result array.
 */
function frl_handle_action_clear_shortcodes() {
	$deleted = frl_cache_clear( 'shortcodes' );
	if ( is_array( $deleted ) ) {
		$count = $deleted['persistent'] ?? 0;
		return array(
			'success'       => true,
			'message_parts' => array( sprintf( __( 'Shortcodes cleared successfully (%d items).', FRL_PREFIX ), $count ) ),
			'notice_type'   => 'success',
		);
	} else {
		return array(
			'success'       => false,
			'message_parts' => array( __( 'Failed to clear shortcodes.', FRL_PREFIX ) ),
			'notice_type'   => 'error',
		);
	}
}
/**
 * Handle the 'clear_cache_light' action.
 *
 * @return array{success: bool, message_parts: string[], notice_type: string} Result array.
 */
function frl_handle_action_clear_cache_light() {
	$orchestrated = Frl_Cache_Operations::run( 'clear_light' );

	if ( ! $orchestrated['success'] ) {
		return array(
			'success'       => false,
			'message_parts' => array( __( 'Failed to clear cache or get stats.', FRL_PREFIX ) ),
			'notice_type'   => 'error',
		);
	}

	$stats         = $orchestrated['steps'][0]['result'] ?? array();
	$message_parts = array();

	if ( is_array( $stats ) ) {
		$message_parts[] = sprintf(
			__( 'Light cache cleared: %1$d items removed from memory, %2$d items removed from cache storage.', FRL_PREFIX ),
			$stats['runtime'] ?? 0,
			( $stats['object_cache'] ?? 0 ) + ( $stats['transients'] ?? 0 )
		);
		return array(
			'success'       => true,
			'message_parts' => $message_parts,
			'notice_type'   => 'success',
		);
	}

	return array(
		'success'       => false,
		'message_parts' => array( __( 'Failed to clear cache or get stats.', FRL_PREFIX ) ),
		'notice_type'   => 'error',
	);
}

/**
 * Handle the 'clear_cache_all' action.
 *
 * @return array{success: bool, message_parts: string[], notice_type: string} Result array.
 */
function frl_handle_action_clear_cache_all() {
	$orchestrated = Frl_Cache_Operations::run( 'clear_all' );

	if ( ! $orchestrated['success'] ) {
		return array(
			'success'       => false,
			'message_parts' => array( __( 'Failed to clear cache or get stats.', FRL_PREFIX ) ),
			'notice_type'   => 'error',
		);
	}

	$stats         = $orchestrated['steps'][0]['result'] ?? array();
	$message_parts = array();

	if ( is_array( $stats ) ) {
		$message_parts[] = sprintf(
			__( 'All caches cleared: %1$d items removed from memory, %2$d items removed from cache storage.', FRL_PREFIX ),
			$stats['runtime'] ?? 0,
			( $stats['object_cache'] ?? 0 ) + ( $stats['transients'] ?? 0 )
		);
		return array(
			'success'       => true,
			'message_parts' => $message_parts,
			'notice_type'   => 'success',
		);
	}

	return array(
		'success'       => false,
		'message_parts' => array( __( 'Failed to clear cache or get stats.', FRL_PREFIX ) ),
		'notice_type'   => 'error',
	);
}

/**
 * Handle the 'clear_wp_alloptions' action.
 *
 * Deletes WordPress's cached `alloptions` entry only, so the next
 * wp_load_alloptions() call rebuilds it from the database.
 *
 * @return array{success: bool, message_parts: string[], notice_type: string} Result array.
 */
function frl_handle_action_clear_wp_alloptions() {
	if ( ! frl_has_access() ) { // Ensure user has capabilities
		return array(
			'success'       => false,
			'message_parts' => array( __( 'You are not authorized to perform this action.', FRL_PREFIX ) ),
			'notice_type'   => 'error',
		);
	}

	// Clear only WordPress's autoloaded-options cache; plugin caches,
	// transients, and rewrite rules are intentionally left untouched.
	wp_cache_delete( 'alloptions', 'options' );

	return array(
		'success'       => true,
		'message_parts' => array(
			'<strong>' . __( 'Alloptions Cache', FRL_PREFIX ) . '</strong>',
			__( '- WordPress alloptions cache cleared; it will reload from the database.', FRL_PREFIX ),
		),
		'notice_type'   => 'success',
	);
}

/**
 * Handle the 'flush_rewrite_rules' action.
 *
 * @return array{success: bool, message_parts: string[], notice_type: string} Result array.
 */
function frl_handle_action_flush_rewrite_rules() {
	frl_flush_rewrite_rules();

	return array(
		'success'       => true,
		'message_parts' => array( __( 'Rewrite rules flushed successfully. All caches cleared and third-party cache plugins notified.', FRL_PREFIX ) ),
		'notice_type'   => 'success',
	);
}
