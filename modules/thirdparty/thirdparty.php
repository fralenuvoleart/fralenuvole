<?php

/**
 * Module Name: Third-Party
 * Description: Cache Bridge for caching plugins, and tweaks for third-party plugins
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_enqueue_scripts', 'frl_thirdparty_public_scripts', FRL_THEMEKIT_STYLE_PRIORITY['modules'], 1 );

add_action( 'admin_enqueue_scripts', 'frl_thirdparty_admin_scripts', 0, 0 );

add_action( 'wp_print_scripts', 'frl_thirdparty_dequeue_acpt_conditional_rules', 100, 0 );

add_filter( 'emr/feature/background', '__return_false', 10, 0 );

add_filter( 'rest_endpoints', 'frl_greenshift_fix_rest_schemas', 10, 1 );

add_filter( 'wp_rest_cache/display_clear_cache_button', '__return_false', 10, 1 );

// Kinsta full purge → schedule the external cache warmer.
add_action( 'kinsta_purge_complete_caches_happened', 'frl_thirdparty_schedule_cache_warmer', 10, 0 );
// Mirror Kinsta purges: a plugin-initiated hard reset (Frl_Cache_Manager::hard_cache_reset)
// should schedule the warmer too. Hook name mirrors the firing code's self::PREFIX (FRL_CACHE_PREFIX).
add_action( FRL_CACHE_PREFIX . '_after_hard_cache_reset', 'frl_thirdparty_schedule_cache_warmer', 10, 0 );

add_action( 'frl_thirdparty_trigger_cache_warmer', 'frl_thirdparty_run_cache_warmer', 10, 0 );

// Cache warmer admin-bar link + action handler (module-owned).
add_filter( 'frl_admin_bar_menu_data', 'frl_thirdparty_admin_bar_cache_warmer', 10, 1 );
add_filter( 'frl_action_registry', 'frl_thirdparty_register_action', 10, 1 );

/*
add_filter(
	'wp_script_modules_import_map_html',
	function ( $html ) {
		return str_replace( '<script id="wp-importmap"', '<script id="wp-importmap" data-noptimize="1"', $html );
	}
);
*/

/**
 * Enqueue thirdparty-specific styles and scripts
 */
function frl_thirdparty_public_scripts() {
	if ( ! frl_is_valid_frontend_page_request() ) {
		return;
	}
	$assets = array(
		'thirdparty-public-css' => 'modules/thirdparty/assets/css/public.css',
	);

	frl_enqueue_scripts( $assets, 'thirdparty_public' );
}

/**
 * Enqueue thirdparty-specific styles and scripts
 */
function frl_thirdparty_admin_scripts() {
	$assets = array(
		'thirdparty-admin-css' => 'modules/thirdparty/assets/css/admin.css',
	);

	// Load Meow-specific styles only when any Meow plugin is active.
	// Array of known plugin paths — the same admin-meow.css applies to all.
	$meow_plugins = array(
		'ai-engine/ai-engine.php',
		'seo-engine/seo-engine.php',
	);

	foreach ( $meow_plugins as $plugin_path ) {
		if ( frl_is_thirdparty_plugin_active( $plugin_path ) ) {
			$assets['thirdparty-admin-meow-css'] = 'modules/thirdparty/assets/css/admin-meow.css';
			break;
		}
	}

	frl_enqueue_scripts( $assets, 'thirdparty_admin' );
}

/**
 * Dequeue ACPT's conditional-rules script on block-editor screens.
 *
 * ACPT enqueues ACPTConditionalRules unconditionally whenever a meta box
 * renders, even when no field has conditional rules. The script then fires
 * the checkIsVisibleAction AJAX endpoint on every field change and around
 * every save; the server handler (FieldsVisibilityLiveChecker) runs an
 * unbatched MetaRepository::getMetaFieldById() query per field — an N+1
 * that costs multiple seconds per call on field-heavy post types.
 *
 * Hooked to wp_print_scripts (not admin_enqueue_scripts) because ACPT
 * enqueues the script during metabox generation, which runs after
 * admin_enqueue_scripts — wp_print_scripts is the last point before the
 * head scripts are printed where the dequeue still takes effect.
 *
 * This site defines no ACPT conditional visibility rules, so the script is
 * pure overhead. NOTE: if conditional visibility rules are ever added to
 * ACPT fields, remove this dequeue or they will not toggle live in the
 * editor (front-end rendering is unaffected).
 *
 * @return void
 */
function frl_thirdparty_dequeue_acpt_conditional_rules(): void {
	if ( ! frl_is_thirdparty_plugin_active( 'advanced-custom-post-type/advanced-custom-post-type.php' ) ) {
		return;
	}

	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || ! $screen->is_block_editor() ) {
		return;
	}

	wp_dequeue_script( 'ACPTConditionalRules' );
	wp_dequeue_script( 'ACPTConditionalRules-run' );
}

/**
 * Fix invalid schema types in third-party REST endpoints.
 * - Greenshift: ensure post_id uses a valid 'integer' type to satisfy WP schema.
 *
 * @param array $endpoints
 * @return array
 */
function frl_greenshift_fix_rest_schemas( $endpoints ) {
	static $done = false;
	if ( $done ) {
		return $endpoints;
	}

	$route = '/greenshift/v1/get-post-part';
	if ( ! isset( $endpoints[ $route ] ) || ! is_array( $endpoints[ $route ] ) ) {
		return $endpoints;
	}

	foreach ( $endpoints[ $route ] as $i => $endpoint ) {
		if ( ! isset( $endpoint['args'] ) || ! is_array( $endpoint['args'] ) ) {
			continue;
		}
		if ( isset( $endpoint['args']['post_id'] ) ) {
			$type = $endpoint['args']['post_id']['type'] ?? null;
			if ( $type !== 'integer' ) {
				$endpoints[ $route ][ $i ]['args']['post_id']['type'] = 'integer';
			}
			if ( ! isset( $endpoints[ $route ][ $i ]['args']['post_id']['sanitize_callback'] ) ) {
				$endpoints[ $route ][ $i ]['args']['post_id']['sanitize_callback'] = 'absint';
			}
			if ( ! isset( $endpoints[ $route ][ $i ]['args']['post_id']['validate_callback'] ) ) {
				$endpoints[ $route ][ $i ]['args']['post_id']['validate_callback'] = static function ( $value ) {
					return is_numeric( $value );
				};
			}
		}
	}
	$done = true;
	return $endpoints;
}

/**
 * Schedule the external cache warmer 10 minutes after a Kinsta full purge.
 *
 * Gated by the `thirdparty_cache_warmer` option and `thirdparty_cache_warmer_url`.
 * Uses a single cron event so repeated purges within the window do not stack.
 *
 * @return void
 */
function frl_thirdparty_schedule_cache_warmer(): void {
	if ( ! frl_get_option( 'thirdparty_cache_warmer' ) ) {
		return;
	}

	if ( empty( frl_get_option( 'thirdparty_cache_warmer_url' ) ) ) {
		return;
	}

	if ( ! wp_next_scheduled( 'frl_thirdparty_trigger_cache_warmer' ) ) {
		wp_schedule_single_event( time() + 600, 'frl_thirdparty_trigger_cache_warmer' );
	}
}

/**
 * Cron callback: run the external cache warmer.
 *
 * The handler's result is logged and stored as an admin notice for the next
 * admin page load.
 *
 * @return void
 */
function frl_thirdparty_run_cache_warmer(): void {
	if ( ! frl_get_option( 'thirdparty_cache_warmer' ) ) {
		return;
	}

	if ( empty( frl_get_option( 'thirdparty_cache_warmer_url' ) ) ) {
		return;
	}

	$result = frl_thirdparty_handle_trigger_cache_warmer();

	if ( ! is_array( $result ) || empty( $result['message_parts'] ) ) {
		return;
	}

	$message = implode( ' ', $result['message_parts'] );
	$type    = $result['notice_type'] ?? 'info';

	frl_log( 'CACHE WARMER (scheduled): {message}', array( 'message' => $message ), true );
	frl_add_admin_notice( $message, $type, 300 );
}

/**
 * Add the "Warm Server Cache" admin-bar link.
 *
 * Contributes to the core admin-bar menu data via the `frl_admin_bar_menu_data`
 * filter, so it inherits the core gating (custom_ab_menu + access) and the
 * `ab_remove_links` removal logic.
 *
 * @param array $data Admin bar menu data.
 * @return array Modified menu data.
 */
function frl_thirdparty_admin_bar_cache_warmer( $data ) {
	if ( empty( frl_get_option( 'thirdparty_cache_warmer_url' ) ) ) {
		return $data;
	}

	$parent_id = FRL_PREFIX . '-menu-primary';

	$data['menu_primary']['warmer_separator'] = array(
		'id'     => FRL_PREFIX . '-warmer-separator',
		'parent' => $parent_id,
		'title'  => '',
		'meta'   => array( 'class' => FRL_PREFIX . '-ab-separator' ),
	);

	$data['menu_primary']['cache_trigger_cache_warmer'] = array(
		'id'     => FRL_PREFIX . '-menu-child-trigger_cache_warmer',
		'title'  => __( 'Warm Server Cache', FRL_PREFIX ),
		'href'   => add_query_arg( FRL_PREFIX . '_action', 'trigger_cache_warmer' ),
		'parent' => $parent_id,
		'meta'   => array(
			'class' => FRL_PREFIX . '-ab-trigger_cache_warmer',
			'title' => 'Trigger the external cache warmer',
		),
	);

	return $data;
}

/**
 * Register the module's admin action with the core dispatcher.
 *
 * @param array<string,string> $registry Action key => handler function.
 * @return array<string,string> Modified registry.
 */
function frl_thirdparty_register_action( $registry ) {
	$registry['trigger_cache_warmer'] = 'frl_thirdparty_handle_trigger_cache_warmer';

	return $registry;
}

/**
 * Handle the 'trigger_cache_warmer' action.
 *
 * Pings the cache warmer URL via a synchronous GET request. The URL is
 * self-contained (token in query string), so no POST body is needed.
 * Synchronous so we can report the actual HTTP response to the user.
 *
 * Gated by the `thirdparty_cache_warmer_url` option — if empty, the admin bar
 * entry is hidden and this handler is unreachable.
 *
 * @return array{success: bool, message_parts: string[], notice_type: string} Result array.
 */
function frl_thirdparty_handle_trigger_cache_warmer() {
	$cache_warmer_url = frl_get_option( 'thirdparty_cache_warmer_url' );
	if ( empty( $cache_warmer_url ) ) {
		return array(
			'success'       => false,
			'message_parts' => array( __( 'Cache warmer URL not configured.', FRL_PREFIX ) ),
			'notice_type'   => 'error',
		);
	}

	if ( frl_is_already_running( __FUNCTION__ ) ) {
		return array(
			'success'       => true,
			'message_parts' => array( __( 'Cache warmer already triggered in this request.', FRL_PREFIX ) ),
			'notice_type'   => 'info',
		);
	}

	$response = wp_remote_get(
		$cache_warmer_url,
		array(
			'timeout'    => 10,
			'user-agent' => 'Fralenuvole Cache Warmer',
		)
	);

	if ( is_wp_error( $response ) ) {
		frl_log( 'CACHE WARMER ERROR: {error}', array( 'error' => $response->get_error_message() ) );
		return array(
			'success'       => false,
			'message_parts' => array(
				sprintf(
					__( 'Cache warmer request failed: %s', FRL_PREFIX ),
					$response->get_error_message()
				),
			),
			'notice_type'   => 'error',
		);
	}

	$http_code = wp_remote_retrieve_response_code( $response );

	if ( $http_code < 200 || $http_code >= 300 ) {
		frl_log( 'CACHE WARMER ERROR: HTTP {code}', array( 'code' => $http_code ) );
		return array(
			'success'       => false,
			'message_parts' => array(
				sprintf(
					__( 'Cache warmer returned HTTP %d.', FRL_PREFIX ),
					$http_code
				),
			),
			'notice_type'   => 'error',
		);
	}

	return array(
		'success'       => true,
		'message_parts' => array(
			sprintf(
				__( 'Cache warmer triggered (HTTP %d).', FRL_PREFIX ),
				$http_code
			),
		),
		'notice_type'   => 'success',
	);
}
