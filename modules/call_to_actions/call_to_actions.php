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

add_action( 'plugins_loaded', 'frl_cta_init', 10, 0 );

/**
 * Initialize Call to Actions module
 */
function frl_cta_init() {
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
			$env_config = frl_environment_get_config();
			$env_prefix = $env_config['webhook_config'] ?? $env_config['prefix'] ?? 'default';

			if ( ! isset( CTA_WEBHOOK_CONFIG[ $env_prefix ] ) || ! is_array( CTA_WEBHOOK_CONFIG[ $env_prefix ] ) ) {
				return $actions;
			}

			$env_actions = CTA_WEBHOOK_CONFIG[ $env_prefix ]['actions'] ?? array();

			// Cache translations to avoid running frl_get_translation on every request
			$lang      = frl_get_language();
			$cache_key = 'cta_actions_trans_' . md5( wp_json_encode( $env_actions ) . '_' . $lang );

			$translated_actions = frl_cache_remember(
				'html',
				$cache_key,
				function () use ( $env_actions ) {
					// Translate template and subject via Polylang (falls back to original if inactive).
					foreach ( $env_actions as &$action ) {
						if ( ! empty( $action['template'] ) ) {
							// Preserve \r\n through translation via {br} sentinel.
							$action['template'] = str_replace( "\r\n", '{br}', $action['template'] );
							$action['template'] = frl_get_translation( $action['template'] );
							$action['template'] = str_replace( '{br}', "\r\n", $action['template'] );
						}
						if ( ! empty( $action['subject'] ) ) {
							$action['subject'] = frl_get_translation( $action['subject'] );
						}
					}
					unset( $action );
					return $env_actions;
				},
				DAY_IN_SECONDS
			);

			$env_actions = $translated_actions;

			// If webhook dispatch is disabled, strip webhook flag so JS doesn't fire sendBeacon.
			if ( ! frl_get_option( 'cta_webhook' ) ) {
				foreach ( $env_actions as &$action ) {
					$action['send_webhook'] = false;
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
}
