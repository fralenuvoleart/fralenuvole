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
