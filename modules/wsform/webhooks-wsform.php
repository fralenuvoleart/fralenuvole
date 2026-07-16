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
	if ( ! empty( $submit->error_validation_actions ) && is_array( $submit->error_validation_actions ) && count( $submit->error_validation_actions ) > 0 ) {
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

		// Check if cron should be used — per-webhook default, overridable per env.
		$env_config = frl_environment_get_config();
		$use_cron   = $env_config['use_cron'] ?? $config['use_cron'] ?? true;

		if ( $use_cron ) {
			frl_send_webhook_async( $webhook_url, $post_data );
		} else {
			frl_send_webhook( $webhook_url, $post_data );
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
	$configs = frl_wsf_get_all_webhook_configs();

	foreach ( $configs as $webhook_config ) {
		// Skip if not matching form
		if ( (int) ( $webhook_config['form_id'] ?? -1 ) !== (int) $form_id ) {
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
			// Trim to handle whitespace-only values; guard against non-scalar (arrays/repeaters)
			if ( is_scalar( $value ) && ! empty( trim( (string) $value ) ) ) {
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
