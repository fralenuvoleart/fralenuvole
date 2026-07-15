<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Determines whether a webhook call should be SUPPRESSED as a duplicate.
 *
 * ⚠ Polarity note: this is the INVERSE of the old frl_wsf_should_send_webhook()
 * ("should send" = true means proceed). This function answers "should dedupe"
 * (true means suppress). Callers migrating from the old function MUST negate
 * the return value — see the wsform thin wrapper below.
 *
 * Logged-in users are NEVER deduped (preserves the original staff/QA bypass).
 * Any empty identity component means no reliable dedupe key can be formed,
 * so the call is NEVER deduped in that case either (fail open, matches original).
 *
 * @param array $data Payload data (already-built post_data array).
 * @param array $keys Which $data keys to combine into the dedupe identity, in order.
 * @param int   $ttl  Suppression window in seconds. Default 21600 (6h) — matches the
 *                    previous hardcoded `6 * HOUR_IN_SECONDS`.
 * @return bool True = suppress (duplicate). False = proceed.
 */
function frl_should_dedupe_webhook( array $data, array $keys, int $ttl = 21600 ): bool {
	if ( is_user_logged_in() ) {
		return false;
	}

	$parts = array();
	foreach ( $keys as $key ) {
		$parts[] = strtolower( trim( (string) ( $data[ $key ] ?? '' ) ) );
	}
	if ( in_array( '', $parts, true ) ) {
		return false;
	}

	$dedupe_key = 'webhook_dedupe_' . md5( implode( '|', $parts ) );
	if ( frl_get_transient( $dedupe_key ) ) {
		return true;
	}
	frl_set_transient( $dedupe_key, 1, $ttl );
	return false;
}

/**
 * Dispatches a webhook via cURL. Same cURL options/timeouts/failure-logging
 * behavior as the original frl_wsf_execute_webhook_submission() — only the
 * log message text now references frl_send_webhook() (cosmetic only).
 *
 * @return array{success: bool, http_code: int, error: ?string}
 */
function frl_send_webhook( string $url, array $data ): array {
	if ( empty( $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
		frl_log( 'WEBHOOK ERROR: frl_send_webhook() - Invalid or missing webhook URL.', array( 'url' => $url ) );
		return array(
			'success'   => false,
			'http_code' => 0,
			'error'     => 'invalid_url',
		);
	}

	$json_payload = json_encode( $data );
	if ( $json_payload === false ) {
		$error = json_last_error_msg();
		frl_log(
			'WEBHOOK ERROR: Failed to encode data to JSON in frl_send_webhook(). Error: {error}. Data: {data}',
			array(
				'error' => $error,
				'data'  => print_r( $data, true ),
			)
		);
		return array(
			'success'   => false,
			'http_code' => 0,
			'error'     => $error,
		);
	}

	$ch     = curl_init( $url );
	$result = array(
		'success'   => false,
		'http_code' => 0,
		'error'     => null,
	);

	try {
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, array( 'Accept: application/json', 'Content-Type: application/json' ) );
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $json_payload );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 15 );
		curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 5 );
		curl_setopt( $ch, CURLOPT_NOSIGNAL, true );
		curl_setopt( $ch, CURLOPT_ENCODING, '' );

		$response            = curl_exec( $ch );
		$http_code           = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$result['http_code'] = $http_code;

		if ( $response === false ) {
			$error           = curl_error( $ch );
			$result['error'] = $error;
			frl_log(
				'WEBHOOK ERROR: cURL execution failed for frl_send_webhook(). Error: {error}. Payload: {payload}',
				array(
					'error'   => $error,
					'payload' => $json_payload,
				)
			);
		} elseif ( $http_code < 200 || $http_code >= 300 ) {
			$result['error'] = 'http_' . $http_code;
			frl_log(
				'WEBHOOK ERROR: Received non-2xx HTTP status code ({status}) in frl_send_webhook(). Response: {response}. Payload: {payload}',
				array(
					'status'   => $http_code,
					'response' => $response,
					'payload'  => $json_payload,
				)
			);
		} else {
			$result['success'] = true;
		}
	} finally {
		$ch = null; // curl_close() deprecated since PHP 8.5; no-op since 8.0
	}

	return $result;
}
