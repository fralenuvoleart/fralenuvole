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
	// Guard: skip admin/CLI/REST/cron/AJAX/non-HTML requests, matching frl_public_scripts().
	if ( ! frl_is_valid_frontend_page_request() ) {
		return;
	}

	// frl_enqueue_scripts() handles cached filemtime() versioning + dedupe internally.
	// Resulting handles are 'frl-channel-tracking' / 'frl-cta-actions' (FRL_PREFIX + key).
	frl_enqueue_scripts(
		array(
			'channel-tracking' => 'assets/js/public-channel-tracking.js',
			'cta-actions'      => 'assets/js/public-cta-actions.js',
		),
		'channel_tracking',
		array(
			'cta-actions' => array( 'frl-channel-tracking' ), // load-order + config-global dependency
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
		'fieldMapping'      => array(),
		'keys'              => CT_ATTR_KEYS,
		'ctaActions'        => $actions,
		'referenceIdLength' => CT_ATTR_REFERENCE_ID_LENGTH,
	);

	if ( $has_any_webhook ) {
		$config['ajaxUrl']  = admin_url( 'admin-ajax.php' );
		$config['language'] = function_exists( 'frl_get_language' ) ? strtoupper( frl_get_language() ) : '';
	}

	$localized = wp_localize_script( 'frl-channel-tracking', 'frlChannelTrackingConfig', $config );
	if ( ! $localized ) {
		frl_log( 'Channel tracking: wp_localize_script() failed — handle may not be registered' );
	}
}
