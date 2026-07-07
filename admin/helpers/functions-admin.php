<?php

/**
 * Fralenuvole Admin Utilities
 *
 * Shared helper functions for admin save handlers and common operations.
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load core helpers
require_once FRL_DIR_PATH . 'admin/helpers/functions-admin-class-helpers.php';

// Load action handlers
require_once FRL_DIR_PATH . 'admin/helpers/functions-admin-action-handlers.php';

/**
 * Display admin notices stored in transients.
 *
 * @return void
 */
function frl_show_admin_notices() {
	$notices = frl_get_transient( 'admin_notices' );

	if ( ! frl_is_array_not_empty( $notices ) ) {
		return;
	}

	foreach ( $notices as $notice ) {
		if ( ! isset( $notice['message'] ) || empty( $notice['message'] ) ) {
			continue; // Skip invalid notices
		}
		$type = isset( $notice['type'] ) && in_array( $notice['type'], array( 'success', 'warning', 'error', 'info' ), true )
			? $notice['type']
			: 'info'; // Default to info

		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible frl-notice"><p>' .
			'<span class="frl-notice-title">' . frl_name() . ':</span> ' . wp_kses_post( $notice['message'] )
			. '</p></div>';
	}

	frl_delete_transient( 'admin_notices' );
}

/**
 * Display all admin notices, including transient and persistent warnings.
 *
 * @return void
 */
function frl_display_all_admin_notices() {
	// 1. Display regular transient notices
	frl_show_admin_notices();

	// 2. Display persistent warnings for specifically disabled managers
	// Only show to users who can manage settings
	if ( ! frl_has_access() ) {
		return;
	}
	$message = array();

	// Check Plugin status.
	if ( frl_get_option( 'disable_plugin' ) ) {
		// Display Env warning
		$message[] = __(
			'The plugin is currently disabled. Modules and frontend features are inactive
        (except category rewrites, translations, shortcodes and core features).',
			FRL_PREFIX
		);
	}

	// Check Environment Manager status via helper function.
	if ( frl_get_option( 'disable_environment' ) ) {
		// Display Env warning
		$message[] = __( 'Environment Manager is currently disabled.', FRL_PREFIX );
	}

	// Check Cache Manager status using the dedicated helper function
	if ( frl_get_option( 'disable_cache' ) ) {
		// Display a generic cache inactive warning
		$message[] = __( 'Cache Manager is currently disabled.', FRL_PREFIX );
	}

	if ( ! empty( $message ) ) {
		frl_display_persistent_warning( implode( '<br>', $message ) );
	}
}

/**
 * Display a standard persistent warning notice in the admin area.
 *
 * @param string $message           The main warning message text.
 * @param string $settings_link_text Text for the link to the settings page.
 * @return void
 */
function frl_display_persistent_warning( $message, $settings_link_text = '' ) {
	// Default settings link text if not provided
	if ( empty( $settings_link_text ) ) {
		$settings_link_text = esc_html__( 'Go to settings', FRL_PREFIX );
	}

	printf(
		'<div class="notice notice-warning frl-notice"><p><strong>%s</strong><br>%s<br><a href="%s">%s</a></p></div>',
		__( 'Warning:' ),
		wp_kses_post( $message ), // Ensure message is escaped HTML
		FRL_PLUGIN_ADMIN_URL,
		esc_html( $settings_link_text )
	);
}

/**
 * Normalize HTML content for storage or comparison.
 *
 * @param string|null $content      HTML content to normalize.
 * @param bool        $for_comparison Whether to normalize whitespace for comparison.
 * @return string Normalized content.
 */
function frl_normalize_html_content( $content, $for_comparison = false ) {
	// Check if content is null or not a string
	if ( $content === null || ! is_string( $content ) ) {
		return '';
	}

	// Always strip slashes to avoid accumulation of backslashes with each save
	$content = stripslashes( $content );

	// For comparison purposes, normalize whitespace
	if ( $for_comparison ) {
		$content = trim( preg_replace( '/\s+/', ' ', $content ) );
	}

	return $content;
}

/**
 * Normalize text content for comparison.
 *
 * Normalizes whitespace to detect actual content changes versus formatting changes.
 *
 * @param mixed $content The text content to normalize.
 * @return string The normalized text for comparison.
 */
function frl_normalize_text_for_comparison( $content ) {
	return trim( preg_replace( '/\s+/', ' ', (string) $content ) );
}

/**
 * Batch update plugin options.
 *
 * Compares submitted values with current database state and updates only changed options.
 *
 * @param array<string, mixed> $options     Associative array of [option_key => value] to update.
 * @param bool                 $force_update Whether to force update all provided options.
 * @return int Number of options actually updated in the database.
 */
function frl_batch_update_options( $options, $force_update = false ) {
	$updated      = 0;
	$should_clear = false;

	// Query directly from database instead of using cache
	$current_options = frl_get_plugin_options_db();

	// Build field_id => field_type lookup map once (O(n) instead of O(n×m))
	$all_fields     = frl_get_all_plugin_options_settings( null );
	$field_type_map = array();
	foreach ( $all_fields as $field ) {
		if ( isset( $field['id'], $field['type'] ) ) {
			$field_type_map[ $field['id'] ] = $field['type'];
		}
	}

	foreach ( $options as $key => $value ) {
		// Skip if option key is not valid
		if ( ! is_string( $key ) || empty( $key ) ) {
			continue;
		}

		// Current value from provided options array
		$current_value = $current_options[ $key ] ?? null;

		// O(1) field type lookup via pre-built map
		$field_type = $field_type_map[ $key ] ?? null;

		$should_update = $force_update;

		// Skip comparison if forcing update
		if ( ! $force_update ) {
			// Compare values based on field type
			$compare_current = $current_value;
			$compare_new     = $value;

			// For HTML content, normalize for comparison
			if ( $field_type === 'html' ) {
				$compare_current = frl_normalize_html_content( $current_value, true );
				$compare_new     = frl_normalize_html_content( $value, true );
			} elseif ( in_array( $field_type, array( 'textarea', 'textlist' ), true ) ) {
				// For text-based fields, normalize whitespace for comparison
				$compare_current = frl_normalize_text_for_comparison( $current_value );
				$compare_new     = frl_normalize_text_for_comparison( $value );
			} elseif ( $field_type === 'number' ) {
				// For numbers, compare them as floats to handle integers and decimals correctly.
				$compare_current = floatval( $current_value );
				$compare_new     = floatval( $value );
			} else {
				// Default string comparison for other types
				// Simple, elegant solution to prevent array to string conversion
				if ( is_array( $current_value ) || is_array( $value ) ) {
					// Use serialized representation of arrays for reliable comparison
					$compare_current = serialize( $current_value );
					$compare_new     = serialize( $value );
				} else {
					// Normal string comparison for non-array values
					$compare_current = (string) $current_value;
					$compare_new     = (string) $value;
				}
			}

			// Only update if changed
			$should_update = ( $compare_new !== $compare_current );
		}

		// Update if forced or values differ
		if ( $should_update ) {
			// Update the option using the without_cache_clear function
			frl_update_option( $key, $value, false );

			// Increment counter and set flag to clear cache
			++$updated;
			$should_clear = true;
		}
	}

	// Clear cache just once after all updates, but only if at least one option was actually updated
	if ( $should_clear ) {
		// Use the standard cache clear function which will handle all cache layers
		frl_cache_clear( 'options' );
	}

	return $updated;
}
