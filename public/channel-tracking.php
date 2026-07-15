<?php
/**
 * Shared Channel Tracking Infrastructure
 *
 * Captures traffic source attribution data via cookies and populates form fields.
 * Shared by wsform and call-to-actions modules. Guarded by frl_is_already_running()
 * so whichever module require_once's this file first gets the init.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Channel Tracking constants (migrated from WS_ATTR_*)
const CT_ATTR_PREFIX              = 'channel';
const CT_ATTR_COOKIE_DAYS         = 90;
const CT_ATTR_COOKIE_PATH         = '/';
const CT_ATTR_COOKIE_DOMAIN       = null;
const CT_ATTR_REFERENCE_ID_LENGTH = 8;
const CT_ATTR_FIELD_MAPPING       = array();
const CT_ATTR_KEYS                = array(
	'source',
	'medium',
	'campaign',
	'term',
	'content',
	'gclid',
	'fbclid',
	'landing',
	'reference_id',
);

/**
 * Initialize channel tracking. Guard ensures single init regardless
 * of which module requests it first.
 */
function frl_channel_tracking_init() {
	if ( frl_is_already_running( __FUNCTION__ ) ) {
		return;
	}
	add_action( 'wp_enqueue_scripts', 'frl_channel_tracking_enqueue' );
}

/**
 * Enqueue channel-tracking.js and cta-actions.js with a shared localized config.
 */
function frl_channel_tracking_enqueue() {
	$js_dir_url  = FRL_DIR_URL . 'assets/js/';
	$js_dir_path = FRL_DIR_PATH . 'assets/js/';

	$ct_version = file_exists( $js_dir_path . 'public-channel-tracking.js' )
		? filemtime( $js_dir_path . 'public-channel-tracking.js' )
		: '1.0.0';
	wp_enqueue_script(
		'frl-channel-tracking',
		$js_dir_url . 'public-channel-tracking.js',
		array(),
		$ct_version,
		array(
			'strategy'  => 'defer',
			'in_footer' => true,
		)
	);

	$cta_version = file_exists( $js_dir_path . 'public-cta-actions.js' )
		? filemtime( $js_dir_path . 'public-cta-actions.js' )
		: '1.0.0';
	wp_enqueue_script(
		'frl-cta-actions',
		$js_dir_url . 'public-cta-actions.js',
		array( 'frl-channel-tracking' ), // dependency guarantees load order + config-global availability
		$cta_version,
		array(
			'strategy'  => 'defer',
			'in_footer' => true,
		)
	);

	$actions = apply_filters( 'frl_channel_tracking_cta_actions', array() );

	$has_any_webhook = false;
	foreach ( $actions as &$action ) {
		$action['hasWebhook'] = ! empty( $action['webhook'] );
		if ( $action['hasWebhook'] ) {
			$has_any_webhook = true;
		}
		unset( $action['webhook'] ); // never expose the webhook URL to the client
	}
	unset( $action );

	$config = array(
		'cookiePrefix'      => '_' . CT_ATTR_PREFIX . '_',
		'fieldPrefix'       => CT_ATTR_PREFIX . '_',
		'cookieDays'        => CT_ATTR_COOKIE_DAYS,
		'cookiePath'        => CT_ATTR_COOKIE_PATH,
		'cookieDomain'      => CT_ATTR_COOKIE_DOMAIN,
		'fieldMapping'      => CT_ATTR_FIELD_MAPPING,
		'keys'              => CT_ATTR_KEYS,
		'ctaActions'        => $actions,
		'referenceIdLength' => CT_ATTR_REFERENCE_ID_LENGTH,
	);

	if ( $has_any_webhook ) {
		$config['ajaxUrl']  = admin_url( 'admin-ajax.php' );
		$config['language'] = function_exists( 'frl_get_language' ) ? strtoupper( frl_get_language() ) : '';
	}

	wp_localize_script( 'frl-channel-tracking', 'frlChannelTrackingConfig', $config );
}
