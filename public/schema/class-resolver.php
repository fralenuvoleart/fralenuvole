<?php
/**
 * Schema Resolver
 *
 * Translates schema keys matching FRL_SCHEMA_TRANSLATE_KEYS and handles
 * the '_remove' sentinel. Called as a post-build pass by the orchestrator.
 *
 * @package Fralenuvole
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Recursively resolve schema properties in a single pass.
 *
 * - Replaces all {{placeholder}} tokens via the shared frl_schema_replace_placeholders
 * - Translates matching keys and handles '_remove' sentinel
 *
 * @param array  $props        Raw schema properties array.
 * @param string $path         Dot-path of the current nesting level (internal).
 * @param array  $replacements Map of {{placeholder}} => replacement string (built once).
 * @return array Resolved schema properties array.
 */
function frl_schema_resolver_resolve( array $props, string $path = '', array $replacements = array() ): array {
	$translate_keys = defined( 'FRL_SCHEMA_TRANSLATE_KEYS' ) ? FRL_SCHEMA_TRANSLATE_KEYS : array();

	// Fast path: no translation keys configured, nothing to do
	if ( empty( $translate_keys ) ) {
		return $props;
	}

	if ( empty( $replacements ) ) {
		$replacements = frl_schema_get_placeholders();
	}
	$result         = array();

	foreach ( $props as $key => $value ) {
		$current_path = $path ? "{$path}.{$key}" : $key;

		if ( is_array( $value ) ) {
			$result[ $key ] = frl_schema_resolver_resolve( $value, $current_path, $replacements );
		} elseif ( is_string( $value ) ) {
			$value = str_replace( array_keys( $replacements ), array_values( $replacements ), $value );

			if ( $value === '_remove' ) {
				$value = null;
			} elseif ( frl_schema_should_translate_key( $key, $current_path, $translate_keys )
				&& function_exists( 'frl_get_translation' ) ) {
				$value = frl_get_translation( $value );
			}
			$result[ $key ] = $value;
		} else {
			$result[ $key ] = $value;
		}
	}

	return $result;
}
