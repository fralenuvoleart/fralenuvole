<?php
/**
 * Call-to-Actions Module — Webhook Handler
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles CTA-click webhook requests sent via sendBeacon from the frontend.
 * Looks up the webhook URL server-side from CTA_WEBHOOK_CONFIG (never exposed to client).
 */
function frl_cta_webhook_handler() {
	// Public analytics endpoint (nopriv). Protected by sanitization
	// and per-IP rate limiting. No nonce: Cloudflare CDN caching causes nonce expiration.

	// Per-IP rate limit: max 30 requests per minute from the same IP.
	// Best-effort (TOCTOU): concurrent requests may both pass the gate.
	// Acceptable — CTA webhooks are low-stakes analytics, not security-critical.
	$client_ip   = frl_get_client_ip() ?: '0.0.0.0';
	$rate_key    = 'frl_cta_rate_' . md5( $client_ip );
	$rate_count  = (int) frl_get_transient( $rate_key );
	$rate_window = CTA_WEBHOOK_RATE_WINDOW;
	$rate_max    = CTA_WEBHOOK_RATE_LIMIT;

	if ( $rate_count >= $rate_max ) {
		frl_log( 'CTA rate limit hit for IP: {ip}', array( 'ip' => $client_ip ) );
		wp_send_json_error( 'Too many requests', 429 );
	}

	frl_set_transient( $rate_key, $rate_count + 1, $rate_window );

	// wp_unslash() before sanitizing: these free-text values (reference_id, UTM params, etc.)
	// are sent verbatim to a third-party webhook — without unslashing, any submitted value
	// containing a quote or backslash would arrive at the webhook with a spurious extra
	// backslash (sanitize_text_field()/sanitize_url() do not strip WP's added magic-quote slash).
	$action_id = sanitize_text_field( wp_unslash( $_POST['action_id'] ?? '' ) );
	if ( empty( $action_id ) ) {
		wp_send_json_error( 'Invalid action', 400 );
	}

	$env_config = frl_environment_get_config();
	$env_prefix = $env_config['webhook_config'] ?? $env_config['prefix'] ?? 'default';

	if ( ! defined( 'CTA_WEBHOOK_CONFIG' ) || empty( CTA_WEBHOOK_CONFIG[ $env_prefix ] ) ) {
		wp_send_json_error( 'No webhook configured', 404 );
	}

	$env_entry   = CTA_WEBHOOK_CONFIG[ $env_prefix ];
	$webhook_url = $env_entry['webhook_url'] ?? '';
	// Per-webhook default from constants, overridable per env.
	$use_cron = $env_config['use_cron'] ?? $env_entry['use_cron'] ?? false;

	if ( empty( $webhook_url ) ) {
		wp_send_json_error( 'No webhook configured', 404 );
	}

	$service  = 'Webpage';
	$page_url = sanitize_url( wp_unslash( $_POST['page_url'] ?? '' ) );
	$post_id  = url_to_postid( $page_url );
	if ( $post_id > 0 && defined( 'CTA_SERVICE_META' ) ) {
		$meta = frl_get_post_meta( $post_id, CTA_SERVICE_META, true );
		if ( ! empty( $meta ) ) {
			$service = sanitize_text_field( $meta );
		}
	}

	$post_data = array();
	foreach ( CTA_WEBHOOK_FIELDS as $field => $source ) {
		switch ( $source ) {
			case '__action_id__':
				$post_data[ $field ] = ucfirst( $action_id );
				break;
			case '__service__':
				$post_data[ $field ] = $service;
				break;
			case '__remote_addr__':
				$post_data[ $field ] = frl_get_client_ip();
				break;
			case '__page_url__':
				$post_data[ $field ] = $page_url;
				break;
			case '__referer__':
				$post_data[ $field ] = sanitize_url( wp_unslash( $_POST['referer'] ?? '' ) );
				break;
			default:
				$post_data[ $field ] = sanitize_text_field( wp_unslash( $_POST[ $source ] ?? '' ) );
		}
	}

	if ( $use_cron ) {
		if ( ! frl_send_webhook_async( $webhook_url, $post_data ) ) {
			wp_send_json_error( 'Webhook scheduling failed', 502 );
		}
	} else {
		$result = frl_send_webhook( $webhook_url, $post_data );
		if ( ! $result['success'] ) {
			wp_send_json_error( 'Webhook dispatch failed', 502 );
		}
	}

	wp_send_json_success( 'Webhook sent' );
}
