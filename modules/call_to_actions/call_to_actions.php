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
	// Extracts the 'actions' list from CTA_WEBHOOK_CONFIG for the current environment,
	// applies per-channel admin toggles (Layer 2) and webhook flag (Layer 3).
	add_filter(
		'frl_channel_tracking_cta_actions',
		function ( array $actions ): array {
			if ( ! defined( 'CTA_WEBHOOK_CONFIG' ) ) {
				return $actions;
			}
			$env_config = frl_environment_get_config();
			$env_prefix = $env_config['prefix'] ?? 'default';

			if ( ! isset( CTA_WEBHOOK_CONFIG[ $env_prefix ] ) || ! is_array( CTA_WEBHOOK_CONFIG[ $env_prefix ] ) ) {
				return $actions;
			}

			$env_actions = CTA_WEBHOOK_CONFIG[ $env_prefix ]['actions'] ?? array();

			// Layer 2 — Per-channel admin toggles: strip disabled channels entirely
			$channel_option_map = array(
				'whatsapp' => 'cta_whatsapp_enabled',
				'telegram' => 'cta_telegram_enabled',
				'email'    => 'cta_email_enabled',
			);
			$env_actions        = array_values(
				array_filter(
					$env_actions,
					function ( array $action ) use ( $channel_option_map ): bool {
						$option = $channel_option_map[ $action['action_id'] ] ?? null;
						return $option ? (bool) frl_get_option( $option ) : true;
					}
				)
			);

			if ( empty( $env_actions ) ) {
				return $actions;
			}

			// Cache translations to avoid running frl_get_translation on every request
			$lang          = frl_get_language();
			$trans_version = frl_get_translation_version();
			$cache_key     = 'cta_actions_trans_' . md5( wp_json_encode( $env_actions ) . '_' . $lang . '_' . $trans_version );

			$translated_actions = frl_cache_remember(
				'html',
				$cache_key,
				function () use ( $env_actions ) {
					// Translate template and subject via Polylang (falls back to original if inactive).
					foreach ( $env_actions as &$action ) {
						if ( ! empty( $action['template'] ) ) {
							$action['template'] = frl_get_translation( $action['template'] );
							// Restore \r\n from {br} sentinels added by translator.
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

			// Layer 3 — Webhook flag: set send_webhook based on admin toggle
			$webhook_enabled    = (bool) frl_get_option( 'cta_webhook' );
			$translated_actions = array_map(
				function ( array $action ) use ( $webhook_enabled ): array {
					$action['send_webhook'] = $webhook_enabled;
					return $action;
				},
				$translated_actions
			);
			$env_actions        = $translated_actions;

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
