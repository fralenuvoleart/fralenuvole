<?php
/**
 * Module Name: Call to Actions
 * Description: WhatsApp, Telegram, and Email CTA click handling with marketing webhook dispatch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load configuration
require_once __DIR__ . '/config-constants-call_to_actions.php';
require_once FRL_DIR_PATH . 'public/channel-tracking.php';

// Initialize shared channel tracking (guarded — no-op if already initialized by wsform)
frl_channel_tracking_init();

// Register CTA actions for the shared tracking config.
// Extracts the 'actions' list from CTA_WEBHOOK_CONFIG for the current environment.
add_filter(
	'frl_channel_tracking_cta_actions',
	function ( array $actions ): array {
		if ( ! defined( 'CTA_WEBHOOK_CONFIG' ) ) {
			return $actions;
		}
		$env_config  = frl_environment_get_config();
		$env_prefix  = $env_config['prefix'] ?? 'default';
		$env_actions = CTA_WEBHOOK_CONFIG[ $env_prefix ]['actions'] ?? array();

		// If webhook dispatch is disabled, strip webhook flag so JS doesn't fire sendBeacon.
		if ( ! frl_get_option( 'cta_webhook' ) ) {
			foreach ( $env_actions as &$action ) {
				$action['webhook'] = false;
			}
			unset( $action );
		}

		return array_merge( $actions, $env_actions );
	}
);

// Init webhook subsystem if enabled (mirrors wsform's wsform_webhook toggle pattern)
if ( frl_get_option( 'cta_webhook' ) ) {
	require_once __DIR__ . '/webhooks-call_to_actions.php';
	add_action( 'wp_ajax_frl_cta_webhook', 'frl_cta_webhook_handler', 10, 0 );
	add_action( 'wp_ajax_nopriv_frl_cta_webhook', 'frl_cta_webhook_handler', 10, 0 );
}
