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
 * Same request contract as the original frl_wsf_button_webhook_handler().
 * Direct (non-inverted) use of frl_should_dedupe_webhook() — new call site, no polarity pitfall.
 */
function frl_cta_webhook_handler() {
	// Public analytics endpoint (nopriv). Protected by sanitization, deduplication,
	// and rate limiting. No nonce: Cloudflare CDN caching causes nonce expiration.

	// wp_unslash() before sanitizing: these free-text values (reference_id, UTM params, etc.)
	// are sent verbatim to a third-party webhook — without unslashing, any submitted value
	// containing a quote or backslash would arrive at the webhook with a spurious extra
	// backslash (sanitize_text_field()/sanitize_url() do not strip WP's added magic-quote slash).
	$action_id = sanitize_text_field( wp_unslash( $_POST['action_id'] ?? '' ) );
	if ( empty( $action_id ) || ! defined( 'CTA_ACTIONS' ) ) {
		wp_send_json_error( 'Invalid action', 400 );
	}

	$env_config  = frl_environment_get_config();
	$env_prefix  = $env_config['prefix'] ?? 'default';
	$webhook_url = '';

	if ( defined( 'CTA_WEBHOOK_CONFIG' ) && isset( CTA_WEBHOOK_CONFIG[ $env_prefix ][ $action_id ] ) ) {
		$webhook_url = CTA_WEBHOOK_CONFIG[ $env_prefix ][ $action_id ];
	}

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

	$post_data = array(
		'Reference ID'     => sanitize_text_field( wp_unslash( $_POST['reference_id'] ?? '' ) ),
		'CTA'              => ucfirst( $action_id ),
		'Service'          => $service,
		'Language'         => sanitize_text_field( wp_unslash( $_POST['language'] ?? '' ) ),
		'Referer'          => sanitize_url( wp_unslash( $_POST['referer'] ?? '' ) ),
		'User IP'          => sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' ),
		'Page URL'         => $page_url,
		'Channel Source'   => sanitize_text_field( wp_unslash( $_POST['source'] ?? '' ) ),
		'Channel Medium'   => sanitize_text_field( wp_unslash( $_POST['medium'] ?? '' ) ),
		'Channel Campaign' => sanitize_text_field( wp_unslash( $_POST['campaign'] ?? '' ) ),
		'Channel Term'     => sanitize_text_field( wp_unslash( $_POST['term'] ?? '' ) ),
		'Channel Content'  => sanitize_text_field( wp_unslash( $_POST['content'] ?? '' ) ),
		'Channel GCLID'    => sanitize_text_field( wp_unslash( $_POST['gclid'] ?? '' ) ),
		'Channel FBCLID'   => sanitize_text_field( wp_unslash( $_POST['fbclid'] ?? '' ) ),
		'Channel Landing'  => sanitize_text_field( wp_unslash( $_POST['landing'] ?? '' ) ),
	);

	if ( frl_should_dedupe_webhook( $post_data, array( 'Reference ID', 'CTA' ), 6 * HOUR_IN_SECONDS ) ) {
		wp_send_json_success( 'Deduplicated' );
	}

	$result = frl_send_webhook( $webhook_url, $post_data );
	if ( ! $result['success'] ) {
		wp_send_json_error( 'Webhook dispatch failed', 502 );
	}

	wp_send_json_success( 'Webhook sent' );
}
