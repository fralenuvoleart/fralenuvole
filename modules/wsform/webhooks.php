<?php

/**
 * Form Filters
 * Custom form filters for WS Form.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter(
	'wsf_pre_render',
	'frl_wsf_set_language',
	10,
	2
);

add_action(
	'wsf_submit_post_complete',
	'frl_wsf_submit_webhook',
	10,
	1
);

add_filter(
	'wsf_submit_validate',
	'frl_wsf_spam_filter_submission',
	10,
	3
);

/**
 * Fix: Remove validation errors for field 249 (channel_content/utm_content).
 * This field is populated by channel tracking and should never block form submission.
 * Hook runs at priority 100 (after WS Form's validation at 10) to filter out errors.
 *
 * @param mixed $field_error_action_array Array of error actions.
 * @param mixed $post_mode                'submit', 'save', or 'action'.
 * @param mixed $submit                    Submit object/ID.
 * @return mixed
 */
add_filter(
	'wsf_submit_validate',
	'frl_wsf_clear_field_249_errors',
	100,
	3
);

function frl_wsf_clear_field_249_errors( mixed $field_error_action_array, mixed $post_mode, mixed $submit ) {
	// Only process on submit
	if ( $post_mode !== 'submit' ) {
		return $field_error_action_array;
	}

	// Ensure we have an array to work with
	if ( ! is_array( $field_error_action_array ) ) {
		return $field_error_action_array;
	}

	// Filter out any validation errors for field 249
	$filtered_errors = array_filter(
		$field_error_action_array,
		function ( $error ) {
			// Keep errors that are NOT for field 249
			// phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- Intentional loose comparison: $error['field_id'] originates from a third-party WS Form filter payload and its exact type (int vs numeric string) is not guaranteed at this boundary.
			if ( isset( $error['field_id'] ) && $error['field_id'] == 249 ) {
				return false; // Remove field 249 errors
			}
			return true; // Keep all other errors
		}
	);

	// Re-index array to maintain consistent structure
	return array_values( $filtered_errors );
}

// This action hook will be triggered by WP-Cron to process the webhook in the background.
add_action(
	'frl_wsf_send_form_submission_webhook',
	'frl_wsf_execute_webhook_submission',
	10,
	1
);

// Button-click webhook AJAX handlers (logged-in and logged-out users)
add_action(
	'wp_ajax_frl_button_webhook',
	'frl_wsf_button_webhook_handler',
	10,
	0
);
add_action(
	'wp_ajax_nopriv_frl_button_webhook',
	'frl_wsf_button_webhook_handler',
	10,
	0
);

/**
 * Gets the matching webhook configurations based on the environment and form ID.
 *
 * @param int $form_id The ID of the form submitted.
 * @return array An array of matching webhook configurations.
 */
function frl_wsf_get_matching_configs( $form_id ) {
	$all_configs      = frl_wsf_get_all_webhook_configs();
	$matching_configs = array();

	foreach ( $all_configs as $webhook_config ) {
		$target_form_id = $webhook_config['form_id'] ?? null;

		// Execution must be a deliberate choice: target_form_id must be set and match the form_id.
		// If target_form_id is null, it will not execute.
		if ( $target_form_id !== null && (int) $target_form_id === (int) $form_id ) {
			$matching_configs[] = $webhook_config;
		}
	}

	return $matching_configs;
}

/**
 * Determines whether a webhook should be sent based on dedupe rules.
 *
 * @param array $post_data
 * @return bool
 */
function frl_wsf_should_send_webhook( array $post_data ) {
	if ( is_user_logged_in() ) {
		return true;
	}

	$reference_id = isset( $post_data['Reference ID'] ) ? trim( (string) $post_data['Reference ID'] ) : '';
	$channel      = isset( $post_data['CTA'] ) ? trim( (string) $post_data['CTA'] ) : '';

	if ( $reference_id === '' || $channel === '' ) {
		return true;
	}

	// Unprefixed key: frl_get_transient()/frl_set_transient() apply the plugin's
	// frl_ prefix and static per-request cache layer themselves (same convention
	// used by every other subsystem), so no manual "frl_" prefix is added here.
	$dedupe_key = 'wsf_webhook_dedupe_' . md5( $reference_id . '|' . strtolower( $channel ) );
	if ( frl_get_transient( $dedupe_key ) ) {
		return false;
	}

	$ttl = 6 * HOUR_IN_SECONDS;
	frl_set_transient( $dedupe_key, 1, $ttl );

	return true;
}

/**
 * Translate labels and options, and set field default values
 *
 * @param mixed $form    Form ID or form object.
 * @param bool  $preview Whether the form is in preview mode.
 * @return mixed
 */
function frl_wsf_set_language( $form, $preview ) {
	/** @disregard P1010 Undefined type */
	$fields = wsf_form_get_fields( $form );

	foreach ( $fields as $object ) {
		/** @disregard P1010 Undefined type */
		$field = wsf_field_get_object( $form, $object->id );

		// Skip if field not found
		if ( $field === null ) {
			continue;
		}

		if ( isset( $field->meta->default_value ) && 'Language' === $field->label ) {
			$field->meta->default_value = strtoupper( frl_get_language() );
		}
	}

	return $form;
}

/**
 * Schedules the webhook to be sent in a background process (non-blocking).
 * This function is hooked to 'wsf_submit_post_complete'.
 *
 * @param object $submit The form submission object from WS Form.
 */
function frl_wsf_submit_webhook( $submit ) {
	// Defensive: Don't send webhook if submission has validation errors
	// (e.g., spam filter blocked it)
	if ( ! empty( $submit->error_validation_actions ) && count( $submit->error_validation_actions ) > 0 ) {
		return;
	}

	$form_id          = $submit->form_id ?? 0;
	$matching_configs = frl_wsf_get_matching_configs( $form_id );

	if ( empty( $matching_configs ) ) {
		return;
	}

	foreach ( $matching_configs as $config ) {
		$fields_map  = $config['fields_map'] ?? array();
		$webhook_url = $config['url'] ?? '';

		if ( empty( $webhook_url ) ) {
			continue;
		}

		// 1. Prepare the data payload
		$post_data = array();
		foreach ( $fields_map as $key => $field ) {
			if ( isset( $submit->meta[ $field ] ) ) {
				$value = $submit->meta[ $field ];
				if ( is_array( $value ) && isset( $value['value'] ) ) {
					$value = $value['value'];
					if ( is_array( $value ) ) {
						$value = empty( $value[0] ) ? '' : $value[0];
					}
				}
				$post_data[ $key ] = $value;
			} else {
				$post_data[ $key ] = '';
			}
		}

		if ( array_key_exists( 'Service', $fields_map ) && empty( $post_data['Service'] ) ) {
			$post_data['Service'] = 'Webpage';
		}

		if ( ! frl_wsf_should_send_webhook( $post_data ) ) {
			continue;
		}

		// 2. Schedule or execute the webhook.
		$args = array(
			'url'  => $webhook_url,
			'data' => $post_data,
		);

		// Check if cron should be used (defaults to true for backward compatibility)
		$use_cron = $config['use_cron'] ?? true;

		if ( $use_cron ) {
			wp_schedule_single_event( time(), 'frl_wsf_send_form_submission_webhook', array( $args ) );
		} else {
			frl_wsf_execute_webhook_submission( $args );
		}
	}
}

/**
 * Executes the actual webhook cURL request in the background via WP-Cron.
 * This function is hooked to 'frl_wsf_send_form_submission_webhook'.
 *
 * @param array $args An array containing 'url' and 'data'.
 */
function frl_wsf_execute_webhook_submission( $args ) {
	$webhook_url = $args['url'] ?? '';
	$post_data   = $args['data'] ?? array();

	if ( empty( $webhook_url ) || ! filter_var( $webhook_url, FILTER_VALIDATE_URL ) ) {
		frl_log( 'WEBHOOK ERROR: frl_wsf_execute_webhook_submission() - Invalid or missing webhook URL.', array( 'url' => $webhook_url ) );
		return;
	}

	// Attempt to encode the data to JSON, checking for errors.
	$json_payload = json_encode( $post_data );

	if ( $json_payload === false ) {
		frl_log(
			'WEBHOOK ERROR: Failed to encode data to JSON in frl_wsf_execute_webhook_submission(). Error: {error}. Data: {data}',
			array(
				'error' => json_last_error_msg(),
				'data'  => print_r( $post_data, true ),
			)
		);
		return;
	}

	// Initialize cURL session with Webhook URL
	$ch = curl_init( $webhook_url );

	try {
		// Set cURL options
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt(
			$ch,
			CURLOPT_HTTPHEADER,
			array(
				'Accept: application/json',
				'Content-Type: application/json',
			)
		);
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $json_payload );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 15 );
		curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 5 );
		curl_setopt( $ch, CURLOPT_NOSIGNAL, true );
		curl_setopt( $ch, CURLOPT_ENCODING, '' );

		// Execute the request and get the response
		$response  = curl_exec( $ch );
		$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );

		// Check for errors
		if ( $response === false ) {
			$error = curl_error( $ch );
			frl_log(
				'WEBHOOK ERROR: cURL execution failed for frl_wsf_execute_webhook_submission(). Error: {error}. Payload: {payload}',
				array(
					'error'   => $error,
					'payload' => $json_payload,
				)
			);
		} elseif ( $http_code < 200 || $http_code >= 300 ) {
			frl_log(
				'WEBHOOK ERROR: Received non-2xx HTTP status code ({status}) in frl_wsf_execute_webhook_submission(). Response: {response}. Payload: {payload}',
				array(
					'status'   => $http_code,
					'response' => $response,
					'payload'  => $json_payload,
				)
			);
		}
	} finally {
		$ch = null; // curl_close() deprecated since PHP 8.5; no-op since 8.0
	}
}

/**
 * Spam filter: Blocks submission if spam indicators are present.
 * Checks webhook config for spam_filter rules and blocks if conditions match.
 *
 * @param array  $field_error_action_array Array of error actions.
 * @param string $post_mode                'submit', 'save', or 'action'.
 * @param object $submit                   Submit object.
 * @return array Modified error array.
 */
function frl_wsf_spam_filter_submission( $field_error_action_array, $post_mode, $submit ) {
	// Only filter spam on submit, not save
	if ( $post_mode !== 'submit' ) {
		return $field_error_action_array;
	}

	$form_id = $submit->form_id ?? 0;
	$configs = frl_wsf_get_all_webhook_configs();

	foreach ( $configs as $webhook_config ) {
		// Skip if not matching form
		if ( ( $webhook_config['form_id'] ?? null ) !== (int) $form_id ) {
			continue;
		}

		// Skip if no spam filter configured
		$spam_filter = $webhook_config['spam_filter'] ?? array();
		if ( empty( $spam_filter ) ) {
			continue;
		}

		// Block if ALL specified spam indicator fields are filled (not empty)
		$fields_to_check = $spam_filter['block_if_all_filled'] ?? array();

		// Defensive: skip if no fields configured (prevent vacuous truth blocking)
		if ( empty( $fields_to_check ) || ! is_array( $fields_to_check ) ) {
			continue;
		}

		$filled_count = 0;

		foreach ( $fields_to_check as $field ) {
			$value = $submit->meta[ $field ] ?? '';
			if ( is_array( $value ) && isset( $value['value'] ) ) {
				$value = $value['value'];
			}
			if ( is_array( $value ) ) {
				$value = empty( $value[0] ) ? '' : $value[0];
			}
			// Trim to handle whitespace-only values
			if ( ! empty( trim( (string) $value ) ) ) {
				++$filled_count;
			}
		}

		// All filled only if count matches total fields (Innocent until proven guilty)
		$all_filled = ( $filled_count === count( $fields_to_check ) );

		if ( $all_filled ) {
			$field_error_action_array[] = array(
				'action'  => 'message',
				'message' => __( 'Submission blocked: spam detected.', 'fralenuvole' ),
				'type'    => 'danger',
				'clear'   => false,
			);
			return $field_error_action_array;
		}
	}

	return $field_error_action_array;
}

/**
 * Handles button-click webhook requests sent via sendBeacon from the frontend.
 * Looks up the webhook URL server-side from WS_BUTTON_ACTIONS (never exposed to client).
 * Reuses frl_wsf_should_send_webhook() for dedupe and frl_wsf_execute_webhook_submission() for dispatch.
 */
function frl_wsf_button_webhook_handler() {
	// Public analytics endpoint (nopriv). Protected by sanitization, deduplication,
	// and rate limiting. No nonce: Cloudflare CDN caching causes nonce expiration.

	// wp_unslash() before sanitizing: these free-text values (reference_id, UTM params, etc.)
	// are sent verbatim to a third-party webhook — without unslashing, any submitted value
	// containing a quote or backslash would arrive at the webhook with a spurious extra
	// backslash (sanitize_text_field()/sanitize_url() do not strip WP's added magic-quote slash).
	$action_id = sanitize_text_field( wp_unslash( $_POST['action_id'] ?? '' ) );
	if ( empty( $action_id ) || ! defined( 'WS_BUTTON_ACTIONS' ) ) {
		wp_send_json_error( 'Invalid action', 400 );
	}

	$webhook_url = '';
	$use_cron    = false;
	foreach ( WS_BUTTON_ACTIONS as $btn ) {
		if ( ( $btn['id'] ?? '' ) === $action_id && ! empty( $btn['webhook'] ) ) { // @phpstan-ignore-line
			$webhook_url = $btn['webhook'];
			$use_cron    = $btn['use_cron'] ?? false; // @phpstan-ignore-line Offset 'use_cron' on array does not exist
			break;
		}
	}

	if ( empty( $webhook_url ) ) {
		wp_send_json_error( 'No webhook configured', 404 );
	}

	$service  = 'Webpage';
	$page_url = sanitize_url( wp_unslash( $_POST['page_url'] ?? '' ) );
	$post_id  = url_to_postid( $page_url );
	if ( $post_id > 0 && defined( 'WS_BUTTON_WEBHOOK_SERVICE_META' ) ) {
		$meta = frl_get_post_meta( $post_id, WS_BUTTON_WEBHOOK_SERVICE_META, true );
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

	if ( ! frl_wsf_should_send_webhook( $post_data ) ) {
		wp_send_json_success( 'Deduplicated' );
	}

	$args = array(
		'url'  => $webhook_url,
		'data' => $post_data,
	);

	if ( $use_cron ) {
		wp_schedule_single_event( time(), 'frl_wsf_send_form_submission_webhook', array( $args ) );
	} else {
		frl_wsf_execute_webhook_submission( $args );
	}

	wp_send_json_success( 'Webhook sent' );
}
