<?php

/**
 * Module Name: WS Form
 * Description: WS Form specific functionalities and webhooks
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load WS Form configuration
require_once __DIR__ . '/config-constants-wsform.php';
require_once FRL_DIR_PATH . 'public/channel-tracking.php';

// Register WS Form Stats widget and tab
add_action(
	'plugins_loaded',
	'frl_wsf_init',
	10,
	0
);

// Pre-render fields for translation
add_filter(
	'wsf_pre_render',
	'frl_wsf_translate_fields',
	10,
	2
);

// Add filter for invalid feedback text
add_filter(
	'wsf_field_invalid_feedback_text',
	'frl_wsf_translate_invalid_text',
	10,
	1
);

// Translate action messages (like "Thank you") dynamically on submit
add_filter(
	'wsf_actions_post_submit',
	'frl_wsf_translate_submit_actions',
	10,
	3
);

/**
 * Initialize WS Form additional features
 */
function frl_wsf_init() {
	if ( frl_get_option( 'wsform_webhook' ) ) {
		frl_wsf_init_webhook();
	}

	if ( frl_get_option( 'wsform_dash_widget' ) ) {
		frl_wsf_init_stats();
	}

	if ( frl_get_option( 'wsform_channel_tracking' ) ) {
		frl_channel_tracking_init();
	}
}

/**
 * Returns the resolved webhook configs for the current environment.
 * Always available regardless of whether the webhook subsystem is active.
 *
 * Lookup order for the config key:
 *   1. env config 'webhook_config' (explicit, set per brand template)
 *   2. env config 'prefix'         (fallback — covers stale cache with old env config)
 *   3. false                        (no lookup, return empty)
 *
 * Applies any per-domain overrides from the env config 'webhooks' key
 * on top of the base WSFORM_ALL_WEBHOOKS_CONFIG entry.
 *
 * @return array
 */
function frl_wsf_get_all_webhook_configs(): array {
	$env_config = frl_environment_get_config();

	// If webhook_config is explicitly false, do not fall back to prefix
	if ( array_key_exists( 'webhook_config', $env_config ) && $env_config['webhook_config'] === false ) {
		return array();
	}

	$config_key = $env_config['webhook_config'] ?? $env_config['prefix'] ?? false;

	return ( $config_key && isset( WSFORM_ALL_WEBHOOKS_CONFIG[ $config_key ] ) )
		? WSFORM_ALL_WEBHOOKS_CONFIG[ $config_key ]
		: array();
}

/**
 * Init WS Form Webhook specific functionalities.
 */
function frl_wsf_init_webhook() {
	require_once __DIR__ . '/webhooks-wsform.php';
}


// Callback function for the wsf_field_invalid_feedback_text filter hook
function frl_wsf_translate_invalid_text( $text ) {
	return frl_get_translation( $text );
}

// Translate labels and options, and set field default values
function frl_wsf_translate_fields( $form, $preview ) {
	/** @disregard P1010 Undefined type */
	$fields = wsf_form_get_fields( $form );
	foreach ( $fields as $object ) {
		/** @disregard P1010 Undefined type */
		$field = wsf_field_get_object( $form, $object->id );

		// Skip if field not found
		if ( $field === null ) {
			continue;
		}

		// Translate Labels
		if ( empty( $field->meta->hidden ) ) {
			$label        = $field->label;
			$field->label = frl_get_translation( $label );
		}

		// Translate fields
		if ( isset( $field->meta->class_field ) && str_contains( $field->meta->class_field, FRL_PREFIX . '-translate' ) ) {

			// Translate select options
			if ( 'select' === $field->type ) {
				$field->meta->placeholder_row = frl_get_translation( $field->meta->placeholder_row );

				$rows = $field->meta->data_grid_select->groups[0]->rows;
				foreach ( $rows as $key => $row ) {
					$option = $row->data[0];
					$field->meta->data_grid_select->groups[0]->rows[ $key ]->data[0] = frl_get_translation( $option );
				}
			}
		}

		if ( isset( $field->meta->default_value ) ) {
			$default = $field->meta->default_value;

			if ( '#refer_url' === $default ) {
				$referrer                   = sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ?? '' ) );
				$field->meta->default_value = $referrer;
			} elseif ( '#user_ip' === $default ) {
				$field->meta->default_value = frl_get_client_ip();
			} elseif ( '#lang' === $default ) {
				$field->meta->default_value = strtoupper( frl_get_language() );
			}
		}
	}

	return $form;
}

/**
 * Translates the success message returned by WS Form via AJAX.
 * This avoids the issue where translated CSS content gets frozen in the page cache.
 *
 * @param array  $actions The actions array.
 * @param object $form    The form object.
 * @param object $submit  The submit object.
 * @return array The modified actions array.
 */
function frl_wsf_translate_submit_actions( $actions, $form, $submit ) {
	if ( ! is_array( $actions ) ) {
		return $actions;
	}

	// Check if we need the weekend message override
	// We look for a field with aria-label "Date" and value "Sat" or "Sun" in the submission data
	$is_weekend = false;
	if ( isset( $submit->meta ) ) {
		foreach ( $submit->meta as $key => $value ) {
			if ( str_starts_with( $key, 'field_' ) ) {
				$field_id = (int) str_replace( 'field_', '', $key );
				/** @disregard P1010 Undefined type */
				$field = wsf_field_get_object( $form, $field_id );
				if ( $field && isset( $field->label ) && $field->label === 'Date' ) {
					if ( $value === 'Sat' || $value === 'Sun' ) {
						$is_weekend = true;
						break;
					}
				}
			}
		}
	}

	// Derive language from the page the form is on, not from the request context.
	// WS Form submits via REST API (wp-json/ws-form/v1/submit) which hits the main
	// domain without a language prefix — pll_current_language() would return the
	// default, short-circuiting frl_get_translation() before pll_translate_string().
	$lang = frl_get_language( (int) ( $submit->post_id ?? 0 ) );

	foreach ( $actions as $key => $action ) {
		if ( isset( $action['id'] ) && $action['id'] === 'message' ) {
			if ( isset( $action['meta']['action_message_message'] ) ) {
				$message = $action['meta']['action_message_message'];

				if ( $is_weekend ) {
					$actions[ $key ]['meta']['action_message_message'] = frl_get_translation( WSFORM_WEEKEND_MESSAGE, $lang );
				} else {
					$actions[ $key ]['meta']['action_message_message'] = frl_get_translation( $message, $lang );
				}
			}
		}
	}

	return $actions;
}

/**
 * Provides the configuration array for the WS Form dashboard widget.
 * Hooked to the 'frl_add_dashboard_widgets' filter.
 *
 * @param array $widgets Existing dashboard widget configurations.
 * @return array Modified $widgets array with WS Form widget config added.
 */
function frl_wsf_add_dashboard_widget( $widgets ) {
	$entries = defined( 'WSFORM_STATS_FORM_IDS' ) ? WSFORM_STATS_FORM_IDS : array();
	// @phpstan-ignore function.alreadyNarrowedType
	if ( ! is_array( $entries ) ) {
		$entries = array( $entries );
	}

	$show_combined = in_array( 'all', $entries, true );
	$form_ids      = array_filter( $entries, 'is_int' );

	foreach ( $form_ids as $fid ) {
		$widgets[ 'wsform_' . $fid ] = array(
			'title'              => '#' . $fid . ' Form Submissions (last 7 days)',
			'cap'                => 'edit_posts',
			'render_callback'    => function () use ( $fid ) {
				return frl_wsf_render_dashboard_widget( $fid );
			},
			'enabled_option_key' => 'wsform_dash_widget',
			'refresh_button'     => true,
		);
	}

	if ( $show_combined ) {
		$widgets['wsform_combined'] = array(
			'title'              => 'All Forms Submissions (last 7 days)',
			'cap'                => 'edit_posts',
			'render_callback'    => 'frl_wsf_render_combined_dashboard_widget',
			'enabled_option_key' => 'wsform_dash_widget',
			'refresh_button'     => true,
		);
	}

	return $widgets;
}

/**
 * Initialize WS Form statistics functionality
 *
 * This function checks if WS Form statistics are enabled and
 * initializes them only when needed by:
 * 1. Loading dependencies
 * 2. Registering widgets on dashboard
 * 3. Setting up plugin tabs when applicable
 */
function frl_wsf_init_stats() {
	// Only run in dashboard if stats are enabled
	if ( ! frl_is_admin_page( 'index.php' ) ) {
		return;
	}

	// First, load stats functionality
	require_once __DIR__ . '/stats/wsform-submissions.php';
	require_once __DIR__ . '/stats/wsform-widget.php';

	// Use the central filter to add widget configuration
	add_filter(
		'frl_add_dashboard_widgets',
		'frl_wsf_add_dashboard_widget',
		10,
		1
	);
}
