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

// Register CTA actions for the shared tracking config
add_filter(
	'frl_channel_tracking_cta_actions',
	function ( array $actions ): array {
		if ( ! defined( 'CTA_ACTIONS' ) ) {
			return $actions;
		}
		return array_merge( $actions, CTA_ACTIONS );
	}
);

// Init webhook subsystem if enabled (mirrors wsform's wsform_webhook toggle pattern)
if ( frl_get_option( 'cta_webhook' ) ) {
	require_once __DIR__ . '/webhooks-call_to_actions.php';
	add_action( 'wp_ajax_frl_cta_webhook', 'frl_cta_webhook_handler', 10, 0 );
	add_action( 'wp_ajax_nopriv_frl_cta_webhook', 'frl_cta_webhook_handler', 10, 0 );
}
