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
function frl_schema_resolver_resolve( array $props, string $path = '', array $replacements = array(), bool $translate_subtree = false ): array {
	$translate_keys = defined( 'FRL_SCHEMA_TRANSLATE_KEYS' ) ? FRL_SCHEMA_TRANSLATE_KEYS : array();

	// Fast path: no translation keys configured, nothing to do
	if ( empty( $translate_keys ) ) {
		return $props;
	}

	if ( empty( $replacements ) ) {
		$replacements = frl_schema_get_placeholders();
	}
	$non_translatable = array( '@type', '@id', '@context', 'url', 'sameAs', 'inLanguage' );
	$result           = array();

	foreach ( $props as $key => $value ) {
		$key_str      = (string) $key;
		$current_path = $path ? "{$path}.{$key_str}" : $key_str;
		$key_trans    = $translate_subtree || frl_schema_should_translate_key( $key_str, $current_path, $translate_keys );

		if ( is_array( $value ) ) {
			$result[ $key ] = frl_schema_resolver_resolve( $value, $current_path, $replacements, $key_trans );
		} elseif ( is_string( $value ) ) {
			$value = frl_schema_replace_placeholders( $value, $replacements );

			if ( $value === '_remove' ) {
				$value = null;
			} elseif ( $key_trans
				&& ! in_array( $key_str, $non_translatable, true )
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
