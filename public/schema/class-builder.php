<?php
/**
 * Schema Generator
 *
 * Generic recursive builder that walks schema definition arrays
 * and produces Schema.org JSON-LD from post data.
 *
 * No hardcoded types — the @type comes from the data config.
 * No hardcoded property resolution — strings resolve via placeholder or field name.
 * Arrays with a 'repeater' key expand into repeated item arrays.
 * Arrays without 'repeater' are treated as nested sub-objects.
 *
 * NOTE: Output is handled by Frl_Schema_Orchestrator, not by a direct wp_head hook.
 * The functions below are pure builders — no side effects, no output.
 *
 * @package Fralenuvole
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Generic Recursive Builder ───────────────────────────────────

/**
 * Build a Schema.org object from a definition array.
 *
 * Rules:
 *   - '@type'     → passed through as-is
 *   - 'repeater'  → key in an array triggers row expansion
 *   - 'source'    → data source for repeater ('acf' or 'acpt')
 *   - 'image'     → if '@source' => 'featured_image', built from thumbnail
 *   - Scalar      → resolved via frl_schema_resolve_value
 *   - Array       → recursed into (sub-object)
 *   - null/empty  → key omitted from output
 *
 * @param int   $post_id      Post ID.
 * @param array $def          Definition array from data file.
 * @param array $placeholders Pre-built placeholder map.
 * @return array|null Built schema array, or null if no content.
 */
function frl_schema_generator_build( int $post_id, array $def, ?array $placeholders = null ): ?array {
	if ( $placeholders === null ) {
		$placeholders = frl_schema_get_placeholders( $post_id );
	}

	$result = array();

	foreach ( $def as $key => $value ) {
		// Skip structural keys
		if ( $key === 'source' || $key === '_if' ) {
			continue;
		}

		// @type → pass through
		if ( $key === '@type' ) {
			$result[ $key ] = $value;
			continue;
		}

		// Array with 'repeater' → expand rows
		if ( is_array( $value ) && isset( $value['repeater'] ) ) {
			$items = frl_schema_generator_build_repeater( $post_id, $value, $placeholders );
			if ( ! empty( $items ) ) {
				$result[ $key ] = $items;
			}
			continue;
		}

		// @source special keys
		if ( is_array( $value ) && isset( $value['@source'] ) ) {
			$sub = frl_schema_generator_build_sourced( $post_id, $value, $placeholders );
			if ( $sub !== null ) {
				$result[ $key ] = $sub;
			}
			continue;
		}

		// Array → recurse as sub-object
		if ( is_array( $value ) ) {
			$sub = frl_schema_generator_build( $post_id, $value, $placeholders );
			if ( $sub !== null ) {
				$result[ $key ] = $sub;
			}
			continue;
		}

		// Scalar → resolve
		if ( is_string( $value ) && $value !== '' ) {
			$resolved = frl_schema_resolve_value( $post_id, $value, $placeholders );
			if ( $resolved !== null ) {
				$result[ $key ] = $resolved;
			}
			continue;
		}
	}

	return ! empty( $result ) ? $result : null;
}

/**
 * Expand a repeater definition into an array of row objects.
 *
 * @param int   $post_id      Post ID.
 * @param array $def          Repeater definition (must contain 'repeater' key).
 * @param array $placeholders Placeholder map.
 * @return array Array of built row arrays.
 */
function frl_schema_generator_build_repeater( int $post_id, array $def, array $placeholders ): array {
	$repeater = $def['repeater'];
	$source   = $def['source'] ?? 'acf';

	// Build field map from non-structural keys
	$field_map = array();
	foreach ( $def as $key => $field_name ) {
		if ( in_array( $key, array( 'repeater', 'source', '@type', '_if' ), true ) ) {
			continue;
		}
		// Strip @field: prefix from field names
		if ( is_string( $field_name ) && str_starts_with( $field_name, '@field:' ) ) {
			$field_name = substr( $field_name, 7 );
		}
		$field_map[ $key ] = $field_name;
	}

	$rows = frl_schema_get_repeater_rows( $post_id, $repeater, $source, $field_map );
	if ( empty( $rows ) ) {
		return array();
	}

	$items = array();
	foreach ( $rows as $i => $row ) {
		$item = array();
		// Inject @type if defined
		if ( ! empty( $def['@type'] ) ) {
			$item['@type'] = $def['@type'];
		}

		$index = (string) ( $i + 1 );
		foreach ( $row as $prop => $value ) {
			// {{index}} replacement
			if ( str_contains( $value, '{{index}}' ) ) {
				$value = str_replace( '{{index}}', $index, $value );
			}
			// Placeholder replacement
			$value = frl_schema_replace_placeholders( $value, $placeholders );
			if ( $value !== '' && $value !== null ) {
				$item[ $prop ] = $value;
			}
		}

		if ( ! empty( $item ) ) {
			$items[] = $item;
		}
	}

	return $items;
}

/**
 * Build a sourced object (e.g., image from featured image).
 *
 * Currently supports '@source' => 'featured_image'.
 *
 * @param int   $post_id      Post ID.
 * @param array $def          Source definition.
 * @param array $placeholders Placeholder map (unused here but consistent signature).
 * @return array|null Built object or null.
 */
function frl_schema_generator_build_sourced( int $post_id, array $def, array $placeholders ): ?array {
	$source = $def['@source'] ?? '';

	if ( $source === 'organization_sameas' || $source === 'organization_availablelanguage' || $source === 'organization_knowsabout' ) {
		static $option_map = null;
		if ( $option_map === null ) {
			$option_map = array(
				'organization_sameas'            => 'schema_org_sameas',
				'organization_availablelanguage' => 'schema_org_availablelanguage',
				'organization_knowsabout'        => 'schema_org_knowsabout',
			);
		}
		$raw  = frl_get_option( $option_map[ $source ] );
		$list = frl_textlist_to_array( $raw );
		// Flatten: frl_textlist_to_array returns array of arrays
		$values = array();
		foreach ( $list as $item ) {
			if ( ! empty( $item[0] ) ) {
				$values[] = $item[0];
			}
		}
		return ! empty( $values ) ? $values : null;
	}

	if ( $source === 'site_logo' ) {
		$logo_id = get_theme_mod( 'custom_logo' );
		if ( ! $logo_id ) {
			return null;
		}
		return frl_schema_build_image_object( (int) $logo_id );
	}

	if ( $source === 'featured_image' ) {
		$image_id = get_post_thumbnail_id( $post_id );
		if ( ! $image_id ) {
			return null;
		}
		$image = frl_schema_build_image_object( $image_id );
		if ( $image === null ) {
			return null;
		}
		// Merge any additional properties from config
		foreach ( $def as $key => $value ) {
			if ( $key === '@source' || $key === '_if' ) {
				continue;
			}
			if ( is_string( $value ) ) {
				$resolved = frl_schema_resolve_value( $post_id, $value, $placeholders );
				if ( $resolved !== null ) {
					$image[ $key ] = $resolved;
				}
			}
		}
		return $image;
	}

	return null;
}
