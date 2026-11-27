<?php

/**
 * Module Name: Third-Party
 * Description: Cache Bridge for caching plugins, and tweaks for third-party plugins
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/importmap-polyfill/importmap-polyfill.php';

add_action( 'wp_enqueue_scripts', 'frl_thirdparty_public_scripts', FRL_THEMEKIT_STYLE_PRIORITY['modules'], 1 );

add_action( 'admin_enqueue_scripts', 'frl_thirdparty_admin_scripts', 0, 0 );

add_action( 'wp_print_scripts', 'frl_thirdparty_dequeue_acpt_conditional_rules', 100, 0 );

add_filter( 'emr/feature/background', '__return_false', 10, 0 );

add_filter( 'rest_endpoints', 'frl_greenshift_fix_rest_schemas', 10, 1 );

add_filter( 'pre_render_block', 'frl_greenshift_shortcircuit_hidden_blocks', 10, 2 );

add_filter( 'render_block', 'frl_greenshift_preserve_colliding_head_css', 11, 2 );

add_filter( 'wp_kses_allowed_html', 'frl_greenshift_allow_svg_in_kses', 10, 2 );

add_filter( 'wp_rest_cache/display_clear_cache_button', '__return_false', 10, 1 );

add_filter( 'autoptimize_filter_css_fixurl_newurl', 'frl_thirdparty_autoptimize_absolute_css_urls', 10, 1 );

// Kinsta full purge → schedule the external cache warmer.
add_action( 'kinsta_purge_complete_caches_happened', 'frl_thirdparty_schedule_cache_warmer', 10, 0 );

add_action( 'frl_thirdparty_trigger_cache_warmer', 'frl_thirdparty_run_cache_warmer', 10, 0 );

// Cache warmer admin-bar link + action handler (module-owned).
add_filter( 'frl_admin_bar_menu_data', 'frl_thirdparty_admin_bar_cache_warmer', 10, 1 );
add_filter( 'frl_action_registry', 'frl_thirdparty_register_action', 10, 1 );

add_filter(
	'wp_inline_script_attributes',
	function ( $attributes ) {
		if ( is_array( $attributes ) && ( $attributes['id'] ?? '' ) === 'wp-importmap' ) {
			$attributes['data-noptimize'] = '1';
		}
		return $attributes;
	}
);

/**
 * Force absolute https:// URLs in Autoptimize's rewritten CSS url() values.
 *
 * @param string $url Rewritten URL from Autoptimize.
 * @return string URL with an explicit https: scheme.
 */
function frl_thirdparty_autoptimize_absolute_css_urls( $url ) {
	if ( ! is_string( $url ) || ! str_starts_with( $url, '//' ) ) {
		return $url;
	}

	return 'https:' . $url;
}

add_filter( 'autoptimize_filter_css_fixurl_newurl', 'frl_thirdparty_autoptimize_absolute_css_urls', 10, 1 );

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
	$route = '/greenshift/v1/get-post-part';
	if ( ! isset( $endpoints[ $route ] ) || ! is_array( $endpoints[ $route ] ) ) {
		return $endpoints;
	}

	foreach ( $endpoints[ $route ] as $i => $endpoint ) {
		if ( ! isset( $endpoint['args']['post_id'] ) || ! is_array( $endpoint['args']['post_id'] ) ) {
			continue;
		}
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

	return $endpoints;
}

/**
 * Short-circuit hidden Greenshift blocks before inner blocks render or push CSS to GreenShiftStyleStore.
 *
 * @param string|null $pre_render   Short-circuit return value.
 * @param array       $parsed_block Parsed block array.
 * @return string|null Empty string if hidden, otherwise unchanged $pre_render.
 */
function frl_greenshift_shortcircuit_hidden_blocks( $pre_render, $parsed_block ) {
	if ( null !== $pre_render || is_admin() ) {
		return $pre_render;
	}

	if (
		empty( $parsed_block['attrs']['is_visibility_set'] )
		|| true !== $parsed_block['attrs']['is_visibility_set']
		|| ! function_exists( 'visibility_conditions_check' )
	) {
		return $pre_render;
	}

	if ( '' === visibility_conditions_check( '1', $parsed_block ) ) {
		return '';
	}

	return $pre_render;
}

/**
 * Prevent GreenShiftStyleStore from dropping CSS when two blocks on a page share a cloned ID.
 *
 * @param string $block_content Rendered block HTML.
 * @param array  $block         Parsed block array.
 * @return string Unchanged block HTML.
 */
function frl_greenshift_preserve_colliding_head_css( $block_content, $block ) {
	if (
		is_admin()
		|| empty( $block['attrs']['inlineCssStyles'] )
		|| empty( $block['attrs']['id'] )
		|| ! empty( $block['attrs']['CSSRender'] )
		|| ! class_exists( 'GreenShiftStyleStore' )
		|| ! function_exists( 'greenshift_sanitize_id_key' )
		|| ! function_exists( 'gspb_get_final_css' )
		|| ! function_exists( 'gspb_quick_minify_css' )
	) {
		return $block_content;
	}

	$store       = GreenShiftStyleStore::getInstance();
	$key         = greenshift_sanitize_id_key( $block['attrs']['id'] );
	$classstyles = $store->getClassStyles();

	if ( ! isset( $classstyles[ $key ] ) ) {
		return $block_content;
	}

	$dynamic_style = gspb_get_final_css( $block['attrs']['inlineCssStyles'] );
	$dynamic_style = gspb_quick_minify_css( $dynamic_style );
	$dynamic_style = htmlspecialchars_decode( $dynamic_style );
	$dynamic_style = wp_strip_all_tags( $dynamic_style );

	if (
		function_exists( 'GSPB_make_dynamic_image' )
		&& ! empty( $block['attrs']['background']['dynamicEnable'] )
		&& isset( $block['attrs']['background']['image'] )
	) {
		$dynamic_style = GSPB_make_dynamic_image(
			$dynamic_style,
			$block['attrs'],
			$block,
			$block['attrs']['background'],
			$block['attrs']['background']['image']
		);
	}

	if ( '' !== $dynamic_style && $dynamic_style !== $classstyles[ $key ] ) {
		$store->addClassStyle( $key . '_' . md5( $dynamic_style ), $dynamic_style );
	}

	return $block_content;
}

/**
 * Preserve Greenshift SVG elements and attributes during wp_kses_post() saves.
 *
 * @param array  $tags    Allowed HTML tags and attributes.
 * @param string $context KSES context name.
 * @return array Modified allowed HTML map.
 */
function frl_greenshift_allow_svg_in_kses( $tags, $context ) {
	if ( 'post' !== $context ) {
		return $tags;
	}

	$common = array(
		'id'                => true,
		'class'             => true,
		'style'             => true,
		'fill'              => true,
		'fill-opacity'      => true,
		'fill-rule'         => true,
		'clip-rule'         => true,
		'clip-path'         => true,
		'stroke'            => true,
		'stroke-width'      => true,
		'stroke-linecap'    => true,
		'stroke-linejoin'   => true,
		'stroke-dasharray'  => true,
		'stroke-dashoffset' => true,
		'stroke-miterlimit' => true,
		'stroke-opacity'    => true,
		'opacity'           => true,
		'color'             => true,
		'transform'         => true,
		'data-*'            => true,
		'aria-*'            => true,
		'role'              => true,
		'focusable'         => true,
	);

	$svg_tags = array(
		'svg'            => array_merge(
			$common,
			array(
				'xmlns'               => true,
				'xmlns:xlink'         => true,
				'xmlns-xlink'         => true,
				'xml:space'           => true,
				'version'             => true,
				'viewbox'             => true,
				'preserveaspectratio' => true,
				'width'               => true,
				'height'              => true,
				'x'                   => true,
				'y'                   => true,
			)
		),
		'g'              => $common,
		'path'           => array_merge( $common, array( 'd' => true ) ),
		'circle'         => array_merge(
			$common,
			array(
				'cx' => true,
				'cy' => true,
				'r'  => true,
			)
		),
		'ellipse'        => array_merge(
			$common,
			array(
				'cx' => true,
				'cy' => true,
				'rx' => true,
				'ry' => true,
			)
		),
		'rect'           => array_merge(
			$common,
			array(
				'x'      => true,
				'y'      => true,
				'width'  => true,
				'height' => true,
				'rx'     => true,
				'ry'     => true,
			)
		),
		'line'           => array_merge(
			$common,
			array(
				'x1' => true,
				'y1' => true,
				'x2' => true,
				'y2' => true,
			)
		),
		'polyline'       => array_merge( $common, array( 'points' => true ) ),
		'polygon'        => array_merge( $common, array( 'points' => true ) ),
		'defs'           => $common,
		'lineargradient' => array_merge(
			$common,
			array(
				'x1'                => true,
				'y1'                => true,
				'x2'                => true,
				'y2'                => true,
				'gradientunits'     => true,
				'gradienttransform' => true,
			)
		),
		'radialgradient' => array_merge(
			$common,
			array(
				'cx'                => true,
				'cy'                => true,
				'r'                 => true,
				'fx'                => true,
				'fy'                => true,
				'gradientunits'     => true,
				'gradienttransform' => true,
			)
		),
		'stop'           => array_merge(
			$common,
			array(
				'offset'       => true,
				'stop-color'   => true,
				'stop-opacity' => true,
			)
		),
		'clippath'       => $common,
		'mask'           => $common,
		'use'            => array_merge(
			$common,
			array(
				'href'       => true,
				'xlink:href' => true,
				'x'          => true,
				'y'          => true,
				'width'      => true,
				'height'     => true,
			)
		),
		'title'          => $common,
	);

	foreach ( $svg_tags as $tag => $attrs ) {
		$existing     = isset( $tags[ $tag ] ) && is_array( $tags[ $tag ] ) ? $tags[ $tag ] : array();
		$tags[ $tag ] = array_merge( $existing, $attrs );
	}

	return $tags;
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
