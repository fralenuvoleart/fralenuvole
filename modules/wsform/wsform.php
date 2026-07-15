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
	$config_key = $env_config['webhook_config'] ?? $env_config['prefix'] ?? false;
	$configs    = ( $config_key && isset( WSFORM_ALL_WEBHOOKS_CONFIG[ $config_key ] ) )
						? WSFORM_ALL_WEBHOOKS_CONFIG[ $config_key ]
						: array();

	if ( ! empty( $env_config['webhooks'] ) && is_array( $env_config['webhooks'] ) ) {
		$configs = array_replace_recursive( $configs, $env_config['webhooks'] );
	}

	return $configs;
}

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
	// Ensure we add the wp_head style block at most once per request
	static $frl_wsf_success_style_added = false;
	static $frl_wsf_success_message     = null;

	$form_actions = $form->meta->action->groups[0]->rows;

	foreach ( $form_actions as $key => $action ) {

		if ( ! empty( $action->data ) && count( $action->data ) > 1 && str_contains( $action->data[0], 'Show Message' ) ) {
			$json = json_decode( $action->data[1] );

			if ( isset( $json->meta ) && isset( $json->meta->action_message_message ) ) {
				$message = $json->meta->action_message_message;
				// Capture the first available success message; avoid re-registering hooks
				if ( $frl_wsf_success_message === null ) {
					$frl_wsf_success_message = $message;
				}
			}
		}
	}

	// Register a single head hook once per request (skip previews to avoid duplicates)
	if ( ! $preview && ! $frl_wsf_success_style_added && $frl_wsf_success_message !== null ) {
		add_action(
			'wp_head',
			function () use ( $frl_wsf_success_message ) {
				frl_wsf_translate_form_success( $frl_wsf_success_message );
			},
			100,
			0
		);
		$frl_wsf_success_style_added = true;
	}
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
				$field->meta->default_value = get_locale();
			}
		}
	}

	return $form;
}

// Helper function to translate "Thank you" message
function frl_wsf_translate_form_success( $message ) {
	?>
	<style>
		.wsf-alert-success p {
			display: none;
		}

		.wsf-alert-success:before {
			content: "<?php echo frl_get_translation( $message ); ?>";
		}

		[data-wsf-message]:has( + form input[aria-label="Date"]:is([value="Sat"],[value="Sun"])) .wsf-alert-success:before {
			content: "<?php echo frl_get_translation( 'Thank you for your inquiry. We will answer you on Monday.' ); ?>";
		}
	</style>
	<?php
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
