<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks if an operation is a duplicate based on a transient lock.
 *
 * @param string $key      Unique identifier for the operation.
 * @param int    $interval Lock duration in seconds (default 60).
 * @return bool True if it's a duplicate (locked), false otherwise.
 */
function frl_is_duplicate_operation( string $key, int $interval = 60 ): bool {
	if ( empty( $key ) || $interval <= 0 ) {
		return false;
	}

	$transient_key = 'dedup_' . md5( $key );

	if ( frl_get_transient( $transient_key ) ) {
		return true; // It's a duplicate, block it
	}

	frl_set_transient( $transient_key, true, $interval );
	return false; // Not a duplicate, proceed and lock
}

/**
 * Schedules a fire-and-forget webhook dispatch via WP-Cron.
 * The actual cURL call runs in a background cron job ('frl_webhook_dispatch' action).
 *
 * @param string      $url            Webhook endpoint URL.
 * @param array       $data           Payload to send.
 * @param string|null $dedup_key      Optional unique key for dedup.
 * @param int         $dedup_interval Lock duration in seconds (default 60).
 * @return bool True if the event was scheduled, false on failure.
 */
function frl_send_webhook_async( string $url, array $data, ?string $dedup_key = null, int $dedup_interval = 60 ): bool {
	if ( $dedup_key !== null && frl_is_duplicate_operation( $dedup_key, $dedup_interval ) ) {
		frl_log( 'WEBHOOK DEDUP: Async dispatch blocked for key {key}', array( 'key' => $dedup_key ) );
		return true; // Treat as success to avoid triggering error flows
	}

	// Dedup key: caller-supplied (e.g., wsform submit ID) or auto-generated UUID.
	// Lives as a sibling cron arg so it participates in WP-Cron's dedup hash
	// but never reaches the webhook payload.
	$_frl_uuid = $dedup_key ?? wp_generate_uuid4();

	$scheduled = wp_schedule_single_event(
		time(),
		'frl_webhook_dispatch',
		array(
			array(
				'url'       => $url,
				'data'      => $data,
				'_frl_uuid' => $_frl_uuid,
			),
		)
	);
	if ( false === $scheduled ) {
		frl_log( 'WEBHOOK ERROR: frl_send_webhook_async() — wp_schedule_single_event failed' );
		return false;
	}
	return true;
}
add_action(
	'frl_webhook_dispatch',
	function ( array $args ) {
		$url  = $args['url'] ?? '';
		$data = $args['data'] ?? array();
		// Pass null for dedup_key here because deduplication already happened at scheduling time
		frl_send_webhook( $url, $data );
	}
);


/**
 * Dispatches a webhook via cURL. Same cURL options/timeouts/failure-logging
 * behavior as the original frl_wsf_execute_webhook_submission() — only the
 * log message text now references frl_send_webhook() (cosmetic only).
 *
 * @param string      $url            Webhook endpoint URL.
 * @param array       $data           Payload to send.
 * @param string|null $dedup_key      Optional unique key for dedup.
 * @param int         $dedup_interval Lock duration in seconds (default 60).
 * @return array{success: bool, http_code: int, error: ?string}
 */
function frl_send_webhook( string $url, array $data, ?string $dedup_key = null, int $dedup_interval = 60 ): array {
	if ( $dedup_key !== null && frl_is_duplicate_operation( $dedup_key, $dedup_interval ) ) {
		frl_log( 'WEBHOOK DEDUP: Sync dispatch blocked for key {key}', array( 'key' => $dedup_key ) );
		return array(
			'success'   => true, // Treat as success to avoid triggering error flows
			'http_code' => 200,
			'error'     => null,
		);
	}

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
