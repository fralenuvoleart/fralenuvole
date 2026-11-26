<?php
/**
 * Call-to-Actions Module — Webhook Handler
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles CTA-click webhook requests sent via sendBeacon from the frontend.
 * Looks up the webhook URL server-side from CTA_WEBHOOK_URLS (never exposed to client).
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

	// Per-IP fixed-window rate limit: max 30 requests per minute and 100 per day.
	// Best-effort (TOCTOU): concurrent requests may both pass the gate.
	// Acceptable — CTA webhooks are low-stakes analytics, not security-critical.
	$now         = time();
	$client_ip   = frl_get_client_ip();
	$rate_key    = 'cta_rate_' . md5( $client_ip );
	$rate_window = CTA_WEBHOOK_RATE_WINDOW;
	$rate_max    = CTA_WEBHOOK_RATE_LIMIT;
	$rate_state  = frl_get_transient( $rate_key );
	$rate_count  = ( is_array( $rate_state ) && ( $rate_state['reset'] ?? 0 ) > $now ) ? (int) $rate_state['count'] : 0;
	$rate_reset  = ( is_array( $rate_state ) && ( $rate_state['reset'] ?? 0 ) > $now ) ? (int) $rate_state['reset'] : ( $now + $rate_window );

	$daily_key   = 'cta_daily_' . md5( $client_ip . gmdate( 'Ymd' ) );
	$daily_max   = 100;
	$daily_state = frl_get_transient( $daily_key );
	$daily_count = ( is_array( $daily_state ) && ( $daily_state['reset'] ?? 0 ) > $now ) ? (int) $daily_state['count'] : 0;
	$daily_reset = ( is_array( $daily_state ) && ( $daily_state['reset'] ?? 0 ) > $now ) ? (int) $daily_state['reset'] : ( $now + DAY_IN_SECONDS );

	if ( $rate_count >= $rate_max || $daily_count >= $daily_max ) {
		frl_log( 'CTA rate limit hit for IP: {ip}', array( 'ip' => $client_ip ) );
		wp_send_json_error( 'Too many requests', 429 );
	}

	frl_set_transient(
		$rate_key,
		array(
			'count' => $rate_count + 1,
			'reset' => $rate_reset,
		),
		max( 1, $rate_reset - $now )
	);
	frl_set_transient(
		$daily_key,
		array(
			'count' => $daily_count + 1,
			'reset' => $daily_reset,
		),
		max( 1, $daily_reset - $now )
	);

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

	// Allowlist action_id against configured CTA templates for this environment.
	if ( defined( 'FRL_CTA_TEMPLATES' ) && isset( FRL_CTA_TEMPLATES[ $env_prefix ] ) && is_array( FRL_CTA_TEMPLATES[ $env_prefix ] ) ) {
		$allowed_actions = array();
		foreach ( FRL_CTA_TEMPLATES[ $env_prefix ] as $tpl_def ) {
			if ( is_array( $tpl_def ) && ! empty( $tpl_def['action_id'] ) ) {
				$allowed_actions[] = (string) $tpl_def['action_id'];
			}
		}
		if ( ! empty( $allowed_actions ) && ! in_array( $action_id, $allowed_actions, true ) ) {
			wp_send_json_error( 'Unknown action', 400 );
		}
	}

	if ( ! defined( 'CTA_WEBHOOK_URLS' ) || empty( CTA_WEBHOOK_URLS[ $env_prefix ] ) ) {
		wp_send_json_error( 'No webhook configured', 404 );
	}

	$webhook_url = CTA_WEBHOOK_URLS[ $env_prefix ];
	// Layer 4 — Admin option only, no constant fallback.
	$use_cron = (bool) frl_get_option( 'cta_use_cron' );

	$service  = 'Webpage';
	$page_url = sanitize_url( wp_unslash( $_POST['page_url'] ?? '' ) );

	if ( ! empty( $page_url ) && defined( 'CTA_SERVICE_META' ) ) {
		// Only resolve same-host URLs and strip ad-click query strings (gclid/fbclid/utm_*) before caching.
		$parsed    = wp_parse_url( $page_url );
		$home_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$url_host  = strtolower( (string) ( $parsed['host'] ?? '' ) );

		if ( '' !== $url_host && $url_host === $home_host ) {
			$scheme     = $parsed['scheme'] ?? 'https';
			$path       = $parsed['path'] ?? '/';
			$lookup_url = $scheme . '://' . $url_host . $path;
			if ( ! empty( $parsed['query'] ) ) {
				parse_str( (string) $parsed['query'], $q_args );
				$keep = array_intersect_key(
					$q_args,
					array(
						'p'       => true,
						'page_id' => true,
					)
				);
				if ( ! empty( $keep ) ) {
					$lookup_url = add_query_arg( $keep, $lookup_url );
				}
			}

			$service_key = 'cta_service_' . md5( $lookup_url );
			$cached_meta = frl_get_transient( $service_key );
			if ( false !== $cached_meta ) {
				$service = $cached_meta;
			} else {
				$post_id = url_to_postid( $lookup_url );
				if ( $post_id > 0 ) {
					$meta = frl_get_post_meta( $post_id, CTA_SERVICE_META, true );
					if ( ! empty( $meta ) ) {
						$service = sanitize_text_field( $meta );
					}
				}
				frl_set_transient( $service_key, $service, HOUR_IN_SECONDS );
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
		// sendBeacon ignores the response, so a slow webhook must not hold the
		// PHP worker for the full 15s default — cap it at 5s.
		$result = frl_send_webhook( $webhook_url, $post_data, $dedup_key, $dedup_interval, 5 );
		if ( ! $result['success'] ) {
			wp_send_json_error( 'Webhook dispatch failed', 502 );
		}
	}

	wp_send_json_success( 'Webhook sent' );
}
