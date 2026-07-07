<?php
/**
 * WS Forms Attribution Tracking
 *
 * Captures traffic source attribution data and populates WS Forms hidden fields.
 * Implements GA4-compatible "last non-direct click" attribution model.
 *
 * Behavior Changes:
 * - Implemented strict segment-based hostname matching for Search/Social (v1.1)
 * - Added debounced form population to optimize performance on dynamic pages (v1.1)
 * - Added configurable WS Form field mapping in CONFIG object (v1.1)
 * - Refined MutationObserver to reduce redundant DOM traversals (v1.1)
 *
 * Performance Optimizations (v1.2):
 * - Moved inline JS to external file for browser caching
 * - Implemented cookie caching during population to avoid redundant parsing
 * - Switched to wp_enqueue_script with defer strategy
 *
 * Integration (v1.3):
 * - Configuration moved to global constants in config-constants-wsform.php
 *
 * Set to run on Frontend only
 */

add_action( 'wp_enqueue_scripts', 'ws_forms_attribution_tracking_enqueue' );

/**
 * Enqueue the attribution tracking script and provide configuration
 */
function ws_forms_attribution_tracking_enqueue() {
	// Determine script URL and path for versioning
	$script_url  = plugins_url( 'assets/js/channel-tracking.js', __FILE__ );
	$script_path = plugin_dir_path( __FILE__ ) . 'assets/js/channel-tracking.js';

	// Use file modification time as version for cache busting during development
	$version = file_exists( $script_path ) ? filemtime( $script_path ) : '1.3.3';

	wp_enqueue_script(
		'ws-forms-attribution-tracking',
		$script_url,
		array(),
		$version,
		array(
			'strategy'  => 'defer',
			'in_footer' => true,
		)
	);

	/**
	 * Configuration for the attribution tracking script
	 * Uses constants defined in config-constants-wsform.php
	 */
	$prefix = defined( 'WS_ATTR_PREFIX' ) ? WS_ATTR_PREFIX : 'ws_attr';

	$actions = defined( 'WS_BUTTON_ACTIONS' )
		? ws_forms_translate_button_actions( WS_BUTTON_ACTIONS )
		: array();

	$has_any_webhook = false;
	foreach ( $actions as &$action ) {
		$action['hasWebhook'] = ! empty( $action['webhook'] );
		if ( $action['hasWebhook'] ) {
			$has_any_webhook = true;
		}
		unset( $action['webhook'] );
	}
	unset( $action );

	$config = array(
		'cookiePrefix'      => '_' . $prefix . '_',
		'fieldPrefix'       => $prefix . '_',
		'cookieDays'        => defined( 'WS_ATTR_COOKIE_DAYS' ) ? WS_ATTR_COOKIE_DAYS : 90,
		'cookiePath'        => defined( 'WS_ATTR_COOKIE_PATH' ) ? WS_ATTR_COOKIE_PATH : '/',
		'cookieDomain'      => defined( 'WS_ATTR_COOKIE_DOMAIN' ) ? WS_ATTR_COOKIE_DOMAIN : null,
		'fieldMapping'      => defined( 'WS_ATTR_FIELD_MAPPING' ) ? WS_ATTR_FIELD_MAPPING : array(),
		'keys'              => defined( 'WS_ATTR_KEYS' ) ? WS_ATTR_KEYS : array(),
		'chatActions'       => $actions,
		'referenceIdLength' => defined( 'WS_ATTR_REFERENCE_ID_LENGTH' ) ? WS_ATTR_REFERENCE_ID_LENGTH : 10,
	);

	if ( $has_any_webhook ) {
		$config['ajaxUrl'] = admin_url( 'admin-ajax.php' );
		// Note: Nonce intentionally omitted. See webhooks.php frl_wsf_button_webhook_handler()
		// for explanation on why nonce verification was removed (CDN caching issues).
		$config['language'] = function_exists( 'frl_get_language' ) ? strtoupper( frl_get_language() ) : '';
	}

	wp_localize_script( 'ws-forms-attribution-tracking', 'wsfAttributionConfig', $config );
}

/**
 * Translate user-facing strings in button action configs for the current language.
 * Falls through to original strings when frl_get_translation is unavailable.
 */
function ws_forms_translate_button_actions( array $actions ): array {
	if ( ! function_exists( 'frl_get_translation' ) ) {
		return $actions;
	}
	foreach ( $actions as &$action ) {
		if ( ! empty( $action['template'] ) ) {
			$action['template'] = frl_get_translation( $action['template'] );
		}
		if ( ! empty( $action['subject'] ) ) {
			$action['subject'] = frl_get_translation( $action['subject'] );
		}
	}
	unset( $action );
	return $actions;
}
