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

	// Lightweight origin check to prevent trivial cross-site abuse
	$referer = $_SERVER['HTTP_REFERER'] ?? '';
	if ( ! empty( $referer ) ) {
		$referer_host = wp_parse_url( $referer, PHP_URL_HOST );
		$home_host    = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( $referer_host && $home_host && $referer_host !== $home_host ) {
			wp_send_json_error( 'Invalid origin', 403 );
		}
	}

	// Per-IP rate limit: max 30 requests per minute from the same IP.
	// Best-effort (TOCTOU): concurrent requests may both pass the gate.
	// Acceptable — CTA webhooks are low-stakes analytics, not security-critical.
	$client_ip   = frl_get_client_ip() ?: '0.0.0.0';
	$rate_key    = 'frl_cta_rate_' . md5( $client_ip );
	$rate_count  = (int) frl_get_transient( $rate_key );
	$rate_window = CTA_WEBHOOK_RATE_WINDOW;
	$rate_max    = CTA_WEBHOOK_RATE_LIMIT;

	// Daily cap per IP
	$daily_key   = 'frl_cta_daily_' . md5( $client_ip . gmdate( 'Ymd' ) );
	$daily_count = (int) frl_get_transient( $daily_key );
	$daily_max   = 100;

	if ( $rate_count >= $rate_max || $daily_count >= $daily_max ) {
		frl_log( 'CTA rate limit hit for IP: {ip}', array( 'ip' => $client_ip ) );
		wp_send_json_error( 'Too many requests', 429 );
	}

	frl_set_transient( $rate_key, $rate_count + 1, $rate_window );
	frl_set_transient( $daily_key, $daily_count + 1, DAY_IN_SECONDS );

	// wp_unslash() before sanitizing: these free-text values (reference_id, UTM params, etc.)
	// are sent verbatim to a third-party webhook — without unslashing, any submitted value
	// containing a quote or backslash would arrive at the webhook with a spurious extra
	// backslash (sanitize_text_field()/sanitize_url() do not strip WP's added magic-quote slash).
	$action_id = sanitize_text_field( wp_unslash( $_POST['action_id'] ?? '' ) );
	if ( empty( $action_id ) ) {
		wp_send_json_error( 'Invalid action', 400 );
	}

	$env_config = frl_environment_get_config();
	$env_prefix = $env_config['prefix'] ?? 'default';

	if ( ! defined( 'CTA_WEBHOOK_CONFIG' ) || empty( CTA_WEBHOOK_CONFIG[ $env_prefix ] ) ) {
		wp_send_json_error( 'No webhook configured', 404 );
	}

	$env_entry   = CTA_WEBHOOK_CONFIG[ $env_prefix ];
	$webhook_url = $env_entry['webhook_url'] ?? '';
	// Admin option wins, falls back to per-env constant entry.
	$use_cron = filter_var( frl_get_option( 'cta_use_cron' ), FILTER_VALIDATE_BOOLEAN ) ?? $env_entry['use_cron'] ?? true;

	if ( empty( $webhook_url ) ) {
		wp_send_json_error( 'No webhook configured', 404 );
	}

	$service  = 'Webpage';
	$page_url = sanitize_url( wp_unslash( $_POST['page_url'] ?? '' ) );

	if ( ! empty( $page_url ) && defined( 'CTA_SERVICE_META' ) ) {
		$post_id = url_to_postid( $page_url );
		if ( $post_id > 0 ) {
			$meta = frl_get_post_meta( $post_id, CTA_SERVICE_META, true );
			if ( ! empty( $meta ) ) {
				$service = sanitize_text_field( $meta );
			}
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

	$dedup_key      = 'cta_' . md5( $client_ip . $action_id );
	$dedup_interval = 5; // 5 seconds

	if ( $use_cron ) {
		if ( ! frl_send_webhook_async( $webhook_url, $post_data, $dedup_key, $dedup_interval ) ) {
			wp_send_json_error( 'Webhook scheduling failed', 502 );
		}
	} else {
		$result = frl_send_webhook( $webhook_url, $post_data, $dedup_key, $dedup_interval );
		if ( ! $result['success'] ) {
			wp_send_json_error( 'Webhook dispatch failed', 502 );
		}
	}

	wp_send_json_success( 'Webhook sent' );
}
