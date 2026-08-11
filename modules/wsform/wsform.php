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
			} elseif ( '#service_type' === $default ) {
				global $post;
				$service_type               = $post ? frl_get_post_meta( $post->ID, 'service-settings_service-type', true ) : '';
				$field->meta->default_value = $service_type ?: 'Webpage';
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
	$lang = frl_get_language( (int) ( $submit->meta['post_id'] ?? 0 ) );

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

/**
 * phone_sanitizer
 * ---------------------------------------------------------------------
 * A phone-number sanitiser for real-world user input.
 *
 * Usage:
 *   $result = frl_wsf_sanitize_phone_number('Call me at (415) 555-2671 ext. 23');
 *   // [
 *   //   'raw'         => 'Call me at (415) 555-2671 ext. 23',
 *   //   'clean'       => '4155552671',
 *   //   'extension'   => '23',
 *   //   'digit_count' => 10,
 *   //   'valid'       => true,
 *   // ]
 */

/**
 * Sanitise a single free-text phone number.
 *
 * @param string|null $raw                Raw user input.
 * @param bool        $convert_vanity_letters Convert letters to keypad digits (1-800-FLOWERS -> 18003569377) instead of just dropping them.
 * @param string|null $default_country_code  Country calling code (no '+') to prepend when the input is a bare national number — i.e. no'+' and no detected 00/011 international prefix. Defaults to Georgia (995). Pass null to disable and get a bare national number back instead.
 * @return array{raw: mixed, clean: string, extension: ?string, digit_count: int, valid: bool}
 */
function frl_wsf_sanitize_phone_number(
	?string $raw,
	bool $convert_vanity_letters = false,
	?string $default_country_code = PHONE_DEFAULT_COUNTRY_CODE
): array {
	if ( $raw === null || trim( $raw ) === '' ) {
		return array(
			'raw'         => $raw,
			'clean'       => '',
			'extension'   => null,
			'digit_count' => 0,
			'valid'       => false,
		);
	}

	$text = trim( frl_wsf_phone_normalize_unicode( $raw ) );

	// Pull off a trailing extension before anything else touches the
	// string, since the extension's own digits must not get merged into
	// the main number.
	$extension = null;
	if ( preg_match( PHONE_EXTENSION_PATTERN, $text, $m, PREG_OFFSET_CAPTURE ) ) {
		$extension = $m[1][0];
		$text      = substr( $text, 0, $m[0][1] );
	}

	if ( $convert_vanity_letters ) {
		$text = frl_wsf_phone_convert_vanity_letters( $text );
	}

	// Detect "this is international format" via EITHER an explicit '+' OR
	// a 00/011 access prefix — checked at the digit level so it doesn't
	// matter where spaces/punctuation land — so we know whether a "(0)"
	// is a droppable trunk marker (e.g. "0044 (0) 20 7946 0958") rather
	// than a real digit.
	$digits_preview      = preg_replace( '/\D/', '', $text );
	$looks_international = strpos( $text, '+' ) !== false
		|| strncmp( $digits_preview, '00', 2 ) === 0
		|| ( strncmp( $digits_preview, '011', 3 ) === 0 && strlen( $digits_preview ) > 11 );

	if ( $looks_international ) {
		$text = preg_replace( '/\(\s*0\s*\)/', '', $text );
	}

	// Keep a leading '+' (even if preceded by junk like a stray bracket),
	// but nothing else non-numeric survives.
	$leading_plus = (bool) preg_match( '/^\D*\+/', $text );
	$digits       = preg_replace( '/\D/', '', $text );

	// Collapse accidental international dialing prefixes, e.g. someone
	// typed "0044 20 ..." or "011 44 20 ...".
	if ( strncmp( $digits, '00', 2 ) === 0 ) {
		$digits       = substr( $digits, 2 );
		$leading_plus = true;
	} elseif ( strncmp( $digits, '011', 3 ) === 0 && strlen( $digits ) > 11 ) {
		$digits       = substr( $digits, 3 );
		$leading_plus = true;
	}

	// No '+' and no international prefix was found — treat this as a bare
	// national number and prepend the default country code.
	if ( ! $leading_plus && $digits !== '' && $default_country_code !== null ) {
		// Strip a single leading trunk zero, a common convention
		// (e.g. "0555 12 34 56" -> "555 12 34 56").
		$without_trunk = ( strncmp( $digits, '0', 1 ) === 0 )
			? substr( $digits, 1 )
			: $digits;

		// Avoid double-prefixing if the caller already typed the country
		// code digits without a leading '+' (e.g. "995555123456") — but
		// only when what's left after the code still looks like a full
		// national number (>= 7 digits). Otherwise a local number that
		// merely happens to start with the same digits as the country
		// code (e.g. Georgian "099 512 3456") would get miscounted as
		// "already coded" and come out too short.
		$code_len         = strlen( $default_country_code );
		$already_has_code = strncmp( $without_trunk, $default_country_code, $code_len ) === 0;
		if ( $already_has_code && strlen( $without_trunk ) >= $code_len + 7 ) {
			$digits = $without_trunk;
		} else {
			$digits = $default_country_code . $without_trunk;
		}
		$leading_plus = true;
	}

	$clean       = $leading_plus ? ( '+' . $digits ) : $digits;
	$digit_count = strlen( $digits );

	// E.164 allows 7-15 digits and the first digit can't be 0.
	$valid = (bool) preg_match( '/^\+?[1-9]\d{6,14}$/', $clean );

	return array(
		'raw'         => $raw,
		'clean'       => $clean,
		'extension'   => $extension,
		'digit_count' => $digit_count,
		'valid'       => $valid,
	);
}

/**
 * Normalise unicode: fold full-width / Arabic-Indic digits etc. down to
 * plain ASCII, and clean up invisible characters and separator variants.
 */
function frl_wsf_phone_normalize_unicode( string $text ): string {
	if ( class_exists( 'Normalizer' ) ) {
		// NFKC folds full-width digits (１２３), Arabic-Indic digits (١٢٣),
		// superscripts, etc. down to plain ASCII equivalents.
		$normalized = Normalizer::normalize( $text, Normalizer::FORM_KC );
		if ( $normalized !== false ) {
			$text = $normalized;
		}
	} else {
		// No php-intl on this host — cover the most common real-world
		// case (full-width digits, e.g. pasted from CJK input methods)
		// by hand so the sanitiser still works without the extension.
		static $full_width_digits = array(
			"\u{FF10}" => '0',
			"\u{FF11}" => '1',
			"\u{FF12}" => '2',
			"\u{FF13}" => '3',
			"\u{FF14}" => '4',
			"\u{FF15}" => '5',
			"\u{FF16}" => '6',
			"\u{FF17}" => '7',
			"\u{FF18}" => '8',
			"\u{FF19}" => '9',
		);
		$text                     = strtr( $text, $full_width_digits );
	}

	$text = preg_replace( PHONE_INVISIBLE_CHARS, '', $text );
	$text = preg_replace( PHONE_DASH_VARIANTS, '-', $text );
	$text = preg_replace( PHONE_SPACE_VARIANTS, ' ', $text );

	return $text;
}

/**
 * Convert alphabetic vanity-number characters to their keypad digit
 * (1-800-FLOWERS -> 1-800-3569377). Off by default — call explicitly via
 * the $convert_vanity_letters flag on sanitize_phone_number().
 */
function frl_wsf_phone_convert_vanity_letters( string $text ): string {
	return preg_replace_callback(
		'/[A-Za-z]/',
		function ( $m ) {
			$upper = strtoupper( $m[0] );
			return PHONE_KEYPAD_MAP[ $upper ] ?? $m[0];
		},
		$text
	);
}

/**
 * Sanitise a list of raw strings.
 *
 * @param array<int, string|null> $raw_list
 * @return array<int, array>
 */
function frl_wsf_sanitize_phone_numbers_batch(
	array $raw_list,
	bool $convert_vanity_letters = false,
	?string $default_country_code = PHONE_DEFAULT_COUNTRY_CODE
): array {
	return array_map(
		fn( $raw ) => frl_wsf_sanitize_phone_number( $raw, $convert_vanity_letters, $default_country_code ),
		$raw_list
	);
}

/**
 * Some fields contain more than one number, e.g.
 * "415-555-2671 / 415-555-9999" or "Home: 555-1234, Cell: 555-5678".
 * Splits on common separators and sanitises each piece.
 *
 * @return array<int, array>
 */
function frl_wsf_split_multiple_phone_numbers(
	string $raw,
	?string $default_country_code = PHONE_DEFAULT_COUNTRY_CODE
): array {
	// Splits on '/', ';', '|', "or", "and", and commas — except a comma
	// immediately followed by an extension marker (", ext. 23", ", x23"),
	// which is one number, not two.
	$pattern = '/\s*(?:\/|;|\||\bor\b|\band\b|,(?!\s*(?:ext\.?|extension|x|\#)))\s*/i';
	$parts   = preg_split( $pattern, $raw );
	$parts   = array_filter( $parts, fn( $p ) => trim( $p ) !== '' );
	return array_map(
		fn( $p ) => frl_wsf_sanitize_phone_number( $p, false, $default_country_code ),
		$parts
	);
}
