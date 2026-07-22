<?php

/**
 * Form Filters
 * Custom form filters for WS Form.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
 * Fix: Remove validation errors for channel tracking fields.
 * These fields are populated by channel tracking and should never block form submission.
 * Hook runs at priority 100 (after WS Form's validation at 10) to filter out errors.
 *
 * @param mixed $field_error_action_array Array of error actions.
 * @param mixed $post_mode                'submit', 'save', or 'action'.
 * @param mixed $submit                    Submit object/ID.
 * @return mixed
 */
add_filter(
	'wsf_submit_validate',
	'frl_wsf_clear_channel_tracking_errors',
	100,
	3
);

function frl_wsf_clear_channel_tracking_errors( mixed $field_error_action_array, mixed $post_mode, mixed $submit ) {
	// Only process on submit
	if ( $post_mode !== 'submit' ) {
		return $field_error_action_array;
	}

	// Ensure we have an array to work with
	if ( ! is_array( $field_error_action_array ) ) {
		return $field_error_action_array;
	}

	$form_id = $submit->form_id ?? 0;
	$configs = frl_wsf_get_matching_configs( $form_id );

	$fields_to_clear = array();
	foreach ( $configs as $config ) {
		$fields_map = $config['fields_map'] ?? array();
		foreach ( $fields_map as $key => $field_id ) {
			// Clear validation errors for all channel tracking fields.
			// We check against the known keys defined in CT_ATTR_KEYS.
			$is_channel_field = false;
			if ( defined( 'CT_ATTR_KEYS' ) && is_array( CT_ATTR_KEYS ) ) {
				foreach ( CT_ATTR_KEYS as $ct_key ) {
					// Match keys like 'Channel Source', 'Channel Medium', etc.
					// Also match 'Reference ID' as it's populated by the same script.
					if ( strcasecmp( $key, 'Channel ' . $ct_key ) === 0 || strcasecmp( $key, 'Reference ID' ) === 0 ) {
						$is_channel_field = true;
						break;
					}
				}
			} else {
				// Fallback if CT_ATTR_KEYS is not defined
				if ( str_starts_with( $key, 'Channel ' ) || $key === 'Reference ID' ) {
					$is_channel_field = true;
				}
			}

			if ( $is_channel_field ) {
				$fields_to_clear[] = (int) str_replace( 'field_', '', $field_id );
			}
		}
	}

	if ( empty( $fields_to_clear ) ) {
		return $field_error_action_array;
	}

	// Filter out any validation errors for channel tracking fields
	$filtered_errors = array_filter(
		$field_error_action_array,
		function ( $error ) use ( $fields_to_clear ) {
			// Keep errors that are NOT for channel tracking fields
			// phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- Intentional loose comparison: $error['field_id'] originates from a third-party WS Form filter payload and its exact type (int vs numeric string) is not guaranteed at this boundary.
			if ( isset( $error['field_id'] ) && in_array( (int) $error['field_id'], $fields_to_clear, true ) ) {
				return false; // Remove channel tracking field errors
			}
			return true; // Keep all other errors
		}
	);

	// Re-index array to maintain consistent structure
	return array_values( $filtered_errors );
}

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
 * Schedules the webhook to be sent in a background process (non-blocking).
 * This function is hooked to 'wsf_submit_post_complete'.
 *
 * @param object $submit The form submission object from WS Form.
 */
function frl_wsf_submit_webhook( $submit ) {
	// Defensive: Ensure submit object is valid
	if ( ! is_object( $submit ) || ! isset( $submit->form_id ) ) {
		frl_log( 'WSFORM WEBHOOK: invalid submit object', array( 'submit' => $submit ), true );
		return;
	}

	// Defensive: Don't send webhook if submission has validation errors
	// (e.g., spam filter blocked it)
	if ( ! empty( $submit->error_validation_actions ) && is_array( $submit->error_validation_actions ) && count( $submit->error_validation_actions ) > 0 ) {
		return;
	}

	$form_id          = $submit->form_id;
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
				}

				// Preserve arrays where possible, join if needed
				if ( is_array( $value ) ) {
					if ( count( $value ) === 1 ) {
						$value = reset( $value );
					} else {
						// Safely implode arrays, handling potential nested objects/arrays
						$safe_values = array();
						foreach ( $value as $v ) {
							if ( is_scalar( $v ) ) {
								$safe_values[] = (string) $v;
							} else {
								$safe_values[] = wp_json_encode( $v );
							}
						}
						$value = implode( ' | ', $safe_values );
					}
				}

				// Normalize/sanitize value
				if ( is_scalar( $value ) ) {
					$post_data[ $key ] = (string) $value;
				} else {
					// Fallback for complex nested structures that weren't caught above
					$post_data[ $key ] = wp_json_encode( $value );
				}
			} else {
				$post_data[ $key ] = '';
			}
		}

		if ( array_key_exists( 'Service', $fields_map ) && empty( $post_data['Service'] ) ) {
			$post_data['Service'] = 'Webpage';
		}

		// Admin option wins. Falls back to per-webhook constant, then hardcoded true.
		$use_cron = filter_var( frl_get_option( 'wsform_use_cron' ), FILTER_VALIDATE_BOOLEAN ) ?? $config['use_cron'] ?? true;

		// Include webhook_url: multiple configs can share a form_id, and dedup must be per-destination.
		$dedup_key      = 'wsf_' . ( $submit->id ?? wp_generate_uuid4() ) . '_' . md5( $webhook_url );
		$dedup_interval = 60; // 1 minute

		if ( $use_cron ) {
			$scheduled = frl_send_webhook_async( $webhook_url, $post_data, $dedup_key, $dedup_interval );
			if ( ! $scheduled ) {
				frl_log(
					'WSFORM WEBHOOK SCHEDULE FAILED: submit_id={submit_id} form_id={form_id} url={url}',
					array(
						'submit_id' => $submit->id ?? 'unknown',
						'form_id'   => $form_id,
						'url'       => $webhook_url,
					),
					true
				);
				// Fallback to sync dispatch — pass null dedup_key to avoid being
				// blocked by the transient lock already set by frl_send_webhook_async().
				frl_send_webhook( $webhook_url, $post_data );
			}
		} else {
			frl_send_webhook( $webhook_url, $post_data, $dedup_key, $dedup_interval );
		}
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

	// Guard against non-array input from other plugins/filters
	if ( ! is_array( $field_error_action_array ) ) {
		$field_error_action_array = array();
	}

	$form_id = $submit->form_id ?? 0;
	$configs = frl_wsf_get_matching_configs( $form_id );

	if ( empty( $configs ) ) {
		return $field_error_action_array;
	}

	foreach ( $configs as $webhook_config ) {
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
			// Trim to handle whitespace-only values; guard against non-scalar (arrays/repeaters)
			if ( is_scalar( $value ) && ! empty( trim( (string) $value ) ) ) {
				++$filled_count;
			}
		}

		// All filled only if count matches total fields (Innocent until proven guilty)
		$all_filled = ( $filled_count === count( $fields_to_check ) );

		if ( $all_filled ) {
			frl_log(
				'WSFORM SPAM BLOCKED: form_id={form_id} submit_id={submit_id}',
				array(
					'form_id'   => $form_id,
					'submit_id' => $submit->id ?? 'unknown',
					'fields'    => $fields_to_check,
				),
				true
			);

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
