<?php
/**
 * Fralenuvole
 * functions-admin-import-export.php - Import/export handlers
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Process translations import via AJAX.
 *
 * @return void
 */
function frl_post_ajax_import_translations() {
	// Verify nonce
	if ( ! isset( $_POST['security'] ) || ! frl_verify_nonce( $_POST['security'], 'ajax_translation_nonce' ) ) {
		wp_send_json_error( array( 'message' => __( 'Security check failed.', FRL_PREFIX ) ) );
	}

	// Check for upload errors
	if ( ! isset( $_FILES['translation_file'] ) ) {
		wp_send_json_error( array( 'message' => __( 'No file data received.', FRL_PREFIX ) ) );
	}

	if ( $_FILES['translation_file']['error'] !== UPLOAD_ERR_OK ) {
		$upload_error_messages = array(
			UPLOAD_ERR_INI_SIZE   => 'The uploaded file exceeds the upload_max_filesize directive in php.ini.',
			UPLOAD_ERR_FORM_SIZE  => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.',
			UPLOAD_ERR_PARTIAL    => 'The uploaded file was only partially uploaded.',
			UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
			UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
			UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
			UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the file upload.',
		);

		$error_code    = $_FILES['translation_file']['error'];
		$error_message = isset( $upload_error_messages[ $error_code ] )
			? $upload_error_messages[ $error_code ]
			: 'Unknown upload error: ' . $error_code;

		wp_send_json_error( array( 'message' => $error_message ) );
	}

	if ( ! isset( $_FILES['translation_file']['tmp_name'] ) || empty( $_FILES['translation_file']['tmp_name'] ) ) {
		wp_send_json_error( array( 'message' => __( 'No file uploaded.', FRL_PREFIX ) ) );
	}

	$file = $_FILES['translation_file']['tmp_name'];

	try {
		$json = file_get_contents( $file );
		if ( empty( $json ) ) {
			wp_send_json_error( array( 'message' => __( 'File is empty.', FRL_PREFIX ) ) );
		}

		// Verify it's valid JSON
		$test_decode = json_decode( $json, true );
		if ( $test_decode === null ) {
			wp_send_json_error( array( 'message' => __( 'Invalid JSON file. JSON parsing error: ', FRL_PREFIX ) . json_last_error_msg() ) );
		}

		// Additional validation of JSON structure
		if ( ! is_array( $test_decode ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid file format: JSON content is not an object.', FRL_PREFIX ) ) );
		}

		// Check for required keys in the translation file
		if ( ! isset( $test_decode['termmeta'] ) && ! isset( $test_decode['wpml_strings'] ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid translation file structure. Missing required data.', FRL_PREFIX ),
				)
			);
		}

		$results = frl_import_translation_strings( $json );
		if ( ! $results ) {
			wp_send_json_error( array( 'message' => __( 'Error processing translations.', FRL_PREFIX ) ) );
		}

		// Check for critical errors
		$critical_errors = $results['strings']['errors'] + $results['translations']['errors'];
		if ( $critical_errors > 0 && $results['strings']['added'] === 0 && $results['translations']['updated'] === 0 ) {
			// If we have errors but no successful imports, treat as error
			$error_msg = frl_build_import_message( $results );
			wp_send_json_error(
				array(
					'message' => $error_msg,
					'results' => $results,
				)
			);
		}

		$message = frl_build_import_message( $results );

		// Clear translations cache and its dependencies
		frl_cache_clear( 'translations' );

		// Add success notice for when page reloads
		frl_add_admin_notice( $message, 'info' );

		wp_send_json_success(
			array(
				'message' => $message,
				'results' => $results,
			)
		);
	} catch ( Exception $e ) {
		wp_send_json_error( array( 'message' => __( 'Error during import: ', FRL_PREFIX ) . $e->getMessage() ) );
	}
}

/**
 * Direct handler for exporting plugin settings.
 *
 * @return void
 */
function frl_post_export_settings() {
	// Verify nonce
	if ( ! isset( $_GET['nonce'] ) || ! frl_verify_nonce( $_GET['nonce'], 'export_settings_nonce' ) ) {
		wp_die( __( 'Security check failed.', FRL_PREFIX ) );
	}

	// Make sure user has correct permissions
	if ( ! frl_has_access( 'manage_options' ) ) {
		wp_die( __( 'You do not have sufficient permissions to access this page.', FRL_PREFIX ) );
	}

	// Get plugin options and prepare for export.
	$plugin_settings = frl_get_plugin_options_db();
	$export_settings = frl_prepare_settings_for_export( $plugin_settings );

	// Create unique filename
	$filename = FRL_NAME . '-settings-' . gmdate( 'Ymd-His' ) . '-' . parse_url( get_site_url(), PHP_URL_HOST );

	// Clean all output buffers
	while ( ob_get_level() ) {
		ob_end_clean();
	}

	// Set download headers
	nocache_headers();
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . $filename . '.json"' );
	header( 'Content-Length: ' . strlen( json_encode( $export_settings, JSON_PRETTY_PRINT ) ) );

	// Output file data
	echo json_encode( $export_settings, JSON_PRETTY_PRINT );
	exit;
}

/**
 * Direct handler for exporting translations.
 *
 * @return void
 */
function frl_post_export_translations() {
	// Verify nonce
	if ( ! isset( $_GET['nonce'] ) || ! frl_verify_nonce( $_GET['nonce'], 'export_translations_nonce' ) ) {
		wp_die( __( 'Security check failed.', FRL_PREFIX ) );
	}

	// Make sure user has correct permissions
	if ( ! frl_has_access( 'manage_options' ) ) {
		wp_die( __( 'You do not have sufficient permissions to access this page.', FRL_PREFIX ) );
	}

	// Get translation strings
	$json = frl_export_translation_strings();

	// Create unique filename
	$filename = FRL_NAME . '-translations-' . gmdate( 'Ymd-His' ) . '-' . parse_url( get_site_url(), PHP_URL_HOST );

	// Clean all output buffers
	while ( ob_get_level() ) {
		ob_end_clean();
	}

	// Set download headers
	nocache_headers();
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . $filename . '.json"' );
	header( 'Content-Length: ' . strlen( $json ) );

	// Output file data
	echo $json;
	exit;
}

/**
 * Process a settings import via AJAX.
 *
 * @return void
 */
function frl_post_ajax_import_settings() {
	// Verify nonce
	if ( ! isset( $_POST['security'] ) || ! frl_verify_nonce( $_POST['security'], 'ajax_import_nonce' ) ) {
		wp_send_json_error( array( 'message' => __( 'Security check failed.', FRL_PREFIX ) ) );
	}

	// Check for upload errors
	if ( ! isset( $_FILES['import_file'] ) ) {
		wp_send_json_error( array( 'message' => __( 'No file data received.', FRL_PREFIX ) ) );
	}

	if ( $_FILES['import_file']['error'] !== UPLOAD_ERR_OK ) {
		$upload_error_messages = array(
			UPLOAD_ERR_INI_SIZE   => 'The uploaded file exceeds the upload_max_filesize directive in php.ini.',
			UPLOAD_ERR_FORM_SIZE  => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.',
			UPLOAD_ERR_PARTIAL    => 'The uploaded file was only partially uploaded.',
			UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
			UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
			UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
			UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the file upload.',
		);

		$error_code    = $_FILES['import_file']['error'];
		$error_message = isset( $upload_error_messages[ $error_code ] )
			? $upload_error_messages[ $error_code ]
			: 'Unknown upload error: ' . $error_code;

		wp_send_json_error( array( 'message' => $error_message ) );
	}

	if ( ! isset( $_FILES['import_file']['tmp_name'] ) || empty( $_FILES['import_file']['tmp_name'] ) ) {
		wp_send_json_error( array( 'message' => __( 'No file uploaded.', FRL_PREFIX ) ) );
	}

	$file = $_FILES['import_file']['tmp_name'];

	try {
		$file_content = file_get_contents( $file );
		if ( empty( $file_content ) ) {
			wp_send_json_error( array( 'message' => __( 'File is empty.', FRL_PREFIX ) ) );
		}

		// Verify it's valid JSON
		$settings = json_decode( $file_content, true );
		if ( $settings === null ) {
			wp_send_json_error( array( 'message' => __( 'Invalid JSON file. JSON parsing error: ', FRL_PREFIX ) . json_last_error_msg() ) );
		}

		// Additional validation of JSON structure
		if ( ! is_array( $settings ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid file format: JSON content is not an object.', FRL_PREFIX ) ) );
		}

		if ( empty( $settings ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid settings file format: No settings found.', FRL_PREFIX ) ) );
		}

		$updates = array();

		// Safe iteration over settings
		foreach ( $settings as $key => $setting ) {
			if ( ! is_string( $key ) ) {
				continue;
			}

			// Strip the prefix from the key if it exists - using simple str_replace
			$unprefixed_key = str_replace( frl_prefix(), '', $key );

			// Handle newline-separated lists
			if ( is_string( $setting ) && str_contains( $setting, '\r\n' ) ) {
				$setting = str_replace( '\r\n', "\n", $setting );
			}

			$updates[ $unprefixed_key ] = $setting;
		}

		if ( empty( $updates ) ) {
			wp_send_json_error( array( 'message' => __( 'No valid settings found in import file.', FRL_PREFIX ) ) );
		}

		// Use batch update function with force_update=true for imports
		$imported = frl_batch_update_options( $updates, true );

		if ( $imported === 0 ) {
			wp_send_json_error( array( 'message' => __( 'No settings were imported.', FRL_PREFIX ) ) );
		}

		$message = sprintf( __( '%d plugin options were successfully imported.', FRL_PREFIX ), $imported );

		// Add success notice for when page reloads
		frl_add_admin_notice( $message, 'success' );

		wp_send_json_success(
			array(
				'message' => $message,
				'count'   => $imported,
			)
		);
	} catch ( Exception $e ) {
		wp_send_json_error( array( 'message' => __( 'Error reading import file: ', FRL_PREFIX ) . $e->getMessage() ) );
	}
}

/**
 * Build a human-readable summary message for the import process.
 *
 * @param array<string, mixed> $results Import results containing counts of added/updated/error items.
 * @return string Formatted message string.
 */
function frl_build_import_message( $results ) {
	$parts = array();

	// Strings
	if ( $results['strings']['added'] > 0 ) {
		$parts[] = "{$results['strings']['added']} new strings";
	}

	// Translations
	if ( $results['translations']['updated'] > 0 ) {
		$parts[] = "{$results['translations']['updated']} translations updated";
	}
	if ( $results['translations']['skipped'] > 0 ) {
		$parts[] = "{$results['translations']['skipped']} translations unchanged";
	}

	// Errors
	$errors      = $results['strings']['errors'] + $results['translations']['errors'];
	$has_changes = ( $results['strings']['added'] + $results['translations']['updated'] ) > 0;

	// Build message
	$message = '';
	if ( $errors > 0 ) {
		$message = 'Import error';
		$parts[] = "{$errors} errors";
	} elseif ( $has_changes ) {
		$message = 'Import successful';
	} else {
		$message = 'No changes detected';
	}

	if ( ! empty( $parts ) ) {
		$message .= ': ' . implode( ', ', $parts );
	}
	$message .= ". File contained {$results['file_strings']} strings in {$results['file_languages']} languages.";

	return $message;
}

/**
 * Export translation strings to JSON.
 *
 * @return string JSON encoded translations.
 */
function frl_export_translation_strings() {
	global $wpdb;

	// Get termmeta translations
	$termmeta = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT term_id, meta_value
             FROM {$wpdb->termmeta}
             WHERE meta_key = %s",
			'_pll_strings_translations'
		),
		ARRAY_A
	);

	// Get WPML strings
	$wpml_strings = get_option( 'polylang_wpml_strings', array() );

	// Combine data
	$export = array(
		'termmeta'     => array(),
		'wpml_strings' => $wpml_strings,
	);

	foreach ( $termmeta as $row ) {
		$export['termmeta'][] = array(
			'term_id'      => $row['term_id'],
			'translations' => maybe_unserialize( $row['meta_value'] ),
		);
	}

	// Return the JSON data instead of outputting directly
	return json_encode( $export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
}

/**
 * Import translation strings from a JSON string.
 *
 * @param string $json JSON string to import.
 * @return array{file_strings: int, file_translations: int, file_languages: int, strings: array, translations: array, failed_strings: array, failed_translations: array} Import results.
 */
function frl_import_translation_strings( $json ) {
	$data = json_decode( $json, true );

	// First validate that we have a valid JSON structure
	if ( ! is_array( $data ) ) {
		return array(
			'file_strings'        => 0,
			'file_translations'   => 0,
			'file_languages'      => 0,
			'strings'             => array(
				'added'   => 0,
				'skipped' => 0,
				'errors'  => 1,
			),
			'translations'        => array(
				'updated' => 0,
				'skipped' => 0,
				'errors'  => 1,
			),
			'failed_strings'      => array( 'Invalid JSON structure' ),
			'failed_translations' => array( 'Invalid JSON structure' ),
		);
	}

	// Ensure required keys exist
	if ( ! isset( $data['termmeta'] ) ) {
		$data['termmeta'] = array();
	}

	if ( ! isset( $data['wpml_strings'] ) ) {
		$data['wpml_strings'] = array();
	}

	// Count unique language terms from termmeta
	$term_ids = array();
	if ( is_array( $data['termmeta'] ) ) {
		foreach ( $data['termmeta'] as $item ) {
			if ( isset( $item['term_id'] ) ) {
				$term_ids[] = $item['term_id'];
			}
		}
	}
	$file_languages = count( array_unique( $term_ids ) );

	$results = array(
		'file_strings'        => is_array( $data['wpml_strings'] ) ? count( $data['wpml_strings'] ) : 0,
		'file_translations'   => is_array( $data['termmeta'] ) ? count( $data['termmeta'] ) : 0,
		'file_languages'      => $file_languages,
		'strings'             => array(
			'added'   => 0,
			'skipped' => 0,
			'errors'  => 0,
		),
		'translations'        => array(
			'updated' => 0,
			'skipped' => 0,
			'errors'  => 0,
		),
		'failed_strings'      => array(),
		'failed_translations' => array(),
	);

	// Get existing strings
	$current_strings = get_option( 'polylang_wpml_strings', array() );
	$existing_keys   = array();
	foreach ( $current_strings as $s ) {
		if ( isset( $s['context'], $s['name'] ) ) {
			$key                   = "{$s['context']}::{$s['name']}";
			$existing_keys[ $key ] = true;
		}
	}

	// Process strings
	if ( is_array( $data['wpml_strings'] ) ) {
		foreach ( $data['wpml_strings'] as $string ) {
			if ( ! is_array( $string ) || ! isset( $string['string'], $string['context'], $string['name'] ) ) {
				++$results['strings']['errors'];
				continue;
			}

			$key = "{$string['context']}::{$string['name']}";
			if ( isset( $existing_keys[ $key ] ) ) {
				++$results['strings']['skipped'];
				continue;
			}

			$current_strings[] = array(
				'string'    => $string['string'],
				'context'   => $string['context'],
				'name'      => $string['name'],
				'multiline' => $string['multiline'] ?? false,
			);
			++$results['strings']['added'];
			$existing_keys[ $key ] = true; // Prevent duplicates in same import
		}
	} else {
		++$results['strings']['errors'];
		$results['failed_strings'][] = 'Invalid wpml_strings format';
	}

	// Only update if new strings were added
	if ( $results['strings']['added'] > 0 ) {
		update_option( 'polylang_wpml_strings', $current_strings );
	}

	// Process translations
	if ( is_array( $data['termmeta'] ) ) {
		foreach ( $data['termmeta'] as $item ) {
			if ( empty( $item['term_id'] ) ) {
				++$results['translations']['errors'];
				continue;
			}

			if ( ! is_numeric( $item['term_id'] ) ) {
				++$results['translations']['errors'];
				continue;
			}

			if ( ! isset( $item['translations'] ) || ! is_array( $item['translations'] ) ) {
				++$results['translations']['errors'];
				continue;
			}

			$term_id   = $item['term_id'];
			$existing  = get_term_meta( $term_id, '_pll_strings_translations', true );
			$new_trans = $item['translations'];

			if ( $existing === $new_trans ) {
				++$results['translations']['skipped'];
				continue;
			}

			// Count changed strings
			$changed = 0;
			foreach ( $new_trans as $string_id => $translation ) {
				if ( ! isset( $existing[ $string_id ] ) || $existing[ $string_id ] !== $translation ) {
					++$changed;
				}
			}

			// Validate the entire translations array survives serialization round-trip.
			// Prevents writing data that would cause an unserialize() error when read back.
			if ( $changed > 0 ) {
                // phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged
				// Intentional: this is a deliberate round-trip integrity test - a serialize/unserialize
				// failure here is an expected, explicitly-handled outcome (checked on the next line),
				// not an error condition that should surface a PHP warning/notice.
				$roundtrip_test = @unserialize( @serialize( $item['translations'] ) );
                // phpcs:enable
				if ( $roundtrip_test === false || $roundtrip_test !== $item['translations'] ) {
					++$results['translations']['errors'];
					$results['failed_translations'][] = sprintf(
						'Serialization integrity check failed for term_id %d. Skipping entry.',
						$term_id
					);
					continue;
				}

				update_term_meta(
					$term_id,
					'_pll_strings_translations',
					$item['translations']
				);
				$results['translations']['updated'] += $changed;
			}
		}
	} else {
		++$results['translations']['errors'];
		$results['failed_translations'][] = 'Invalid termmeta format';
	}

	return $results;
}

/**
 * Prepare plugin settings for export.
 *
 * Prefixes keys and cleans up whitespace in string values to ensure
 * compatibility with the import process.
 *
 * @param array $settings Raw settings array.
 * @return array Formatted settings ready for JSON export.
 */
function frl_prepare_settings_for_export( array $settings ): array {
	$export = array();
	foreach ( $settings as $key => $value ) {
		if ( is_string( $value ) ) {
			// Convert newlines to \n and remove extra whitespace
			$value = str_replace( array( "\r\n", "\r" ), "\n", $value );
			$lines = explode( "\n", $value );
			$lines = array_map( 'trim', $lines );
			$value = implode( "\n", array_filter( $lines ) );
		}
		$export[ frl_prefix( $key ) ] = $value;
	}
	return $export;
}

/**
 * Verify a nonce using the plugin's prefix.
 *
 * @param string $nonce  The nonce value to verify.
 * @param string $action The action name.
 * @return bool True if verified, false otherwise.
 */
function frl_verify_nonce( $nonce, $action ) {
	return wp_verify_nonce( $nonce, frl_prefix( $action ) );
}
