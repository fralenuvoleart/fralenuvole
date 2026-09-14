<?php
/**
 * Schema Helpers
 *
 * Generic helper functions shared by the schema properties
 * and generator subsystems.
 *
 * @package Fralenuvole
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalize a meta value: extract 'label' from arrays, cast scalars to string.
 *
 * @param mixed $value The raw meta value.
 * @return string|null Extracted scalar string or null if invalid.
 */
function frl_schema_extract_scalar_value( $value ): ?string {
	if ( $value === null || $value === false || $value === '' ) {
		return null;
	}
	if ( is_array( $value ) && isset( $value['label'] ) ) {
		$value = $value['label'];
	}
	return is_scalar( $value ) ? (string) $value : null;
}

/**
 * Resolve a post_*-prefixed source string to its WordPress value.
 *
 * Centralizes the post_* resolution logic shared by Person builders.
 * - 'post_permalink' / 'post_permalink#fragment' → get_permalink()
 * - 'post_thumbnail' → ImageObject via frl_schema_build_image_object()
 * - 'post_thumbnail_url' → get_the_post_thumbnail_url()
 * - 'post_{field}' → $post->{field}
 * - anything else → frl_get_post_meta()
 *
 * @param int     $post_id Post ID.
 * @param string  $source  Source string (e.g. 'post_title', 'post_permalink#Person').
 * @param \WP_Post $post   Pre-fetched post object.
 * @return mixed Resolved value (string, array, or null).
 */
function frl_schema_resolve_post_source( int $post_id, string $source, \WP_Post $post ): mixed {
	if ( str_starts_with( $source, 'post_' ) ) {
		if ( str_starts_with( $source, 'post_permalink' ) ) {
			$value    = get_permalink( $post_id );
			$fragment = strstr( $source, '#' );
			if ( $fragment !== false ) {
				$value .= $fragment;
			}
			return $value;
		}

		if ( $source === 'post_thumbnail' ) {
			$id = get_post_thumbnail_id( $post_id );
			return $id ? frl_schema_build_image_object( $id ) : null;
		}

		if ( $source === 'post_thumbnail_url' ) {
			return get_the_post_thumbnail_url( $post_id, 'full' );
		}

		// Native WP field: $post->{field}
		return $post->{$source} ?? null;
	}

	// Meta field fallback
	return frl_get_post_meta( $post_id, $source, true );
}

/**
 * Get the contact page URL.
 *
 * Looks up the page with slug 'contact' and returns its permalink.
 * Falls back to site_url/contact/ if no page found.
 *
 * @return string Contact page URL.
 */
function frl_get_contact_page_url(): string {
	$page = get_page_by_path( 'contact' );
	if ( $page ) {
		return get_permalink( $page );
	}
	return frl_get_home_url() . '/contact/';
}

/**
 * Resolve the file path for a schema data file, supporting per-brand overrides.
 *
 * Tries {prefix}-variant first; falls back to the default filename.
 *
 * @param string $default_filename The default filename (e.g. 'default-schema.php').
 * @param string $subdir           Data subdirectory: 'properties' or 'generators'.
 * @return string Resolved file path.
 */
function frl_schema_get_data_file( string $default_filename, string $subdir = 'definitions' ): string {
	$prefix = '';
	if ( function_exists( 'frl_environment_get_config' ) ) {
		$env_config = frl_environment_get_config();
		$prefix     = $env_config['prefix'] ?? '';
	}

	$base = FRL_DIR_PATH . 'public/schema/' . $subdir . '/';

	$file = $base . $default_filename;
	if ( $prefix ) {
		$brand_filename = str_replace( 'default-', $prefix . '-', $default_filename );
		$brand_file     = $base . $brand_filename;
		if ( file_exists( $brand_file ) ) {
			return $brand_file;
		}
	}
	return $file;
}

/**
 * Replace {{placeholder}} tokens in a string or array (recursive).
 *
 * @param string|array $data         String or nested array.
 * @param array        $replacements Map of {{placeholder}} => replacement.
 * @return string|array Data with placeholders replaced.
 */
function frl_schema_replace_placeholders( string|array $data, array $replacements ): string|array {
	if ( empty( $replacements ) ) {
		return $data;
	}

	if ( is_string( $data ) ) {
		return str_replace( array_keys( $replacements ), array_values( $replacements ), $data );
	}

	$result = array();
	foreach ( $data as $key => $value ) {
		if ( is_array( $value ) ) {
			$result[ $key ] = frl_schema_replace_placeholders( $value, $replacements );
		} elseif ( is_string( $value ) ) {
			$result[ $key ] = str_replace( array_keys( $replacements ), array_values( $replacements ), $value );
		} else {
			$result[ $key ] = $value;
		}
	}
	return $result;
}

/**
 * Build the standard placeholder map.
 *
 * Site-wide placeholders (site_url, org name, etc.) are always available.
 * Post-aware placeholders require $post_id.
 *
 * @param int|null $post_id Post ID for post-aware placeholders, or null to skip.
 * @return array Map of {{placeholder}} => replacement string.
 */
function frl_schema_get_placeholders( ?int $post_id = null ): array {
	static $cache = array();
	$cache_key    = $post_id ?? '_global';

	if ( isset( $cache[ $cache_key ] ) ) {
		return $cache[ $cache_key ];
	}

	$logo = wp_get_attachment_image_src( get_theme_mod( 'custom_logo' ), 'full' );

	$map = array(
		'{{site_url}}'                           => site_url(),
		'{{site_url_local}}'                     => frl_get_home_url(),
		'{{language}}'                           => frl_get_language(),
		'{{custom_logo}}'                        => $logo[0] ?? '',
		'{{schema_org_url}}'                     => trailingslashit( site_url() ),
		'{{schema_org_name}}'                    => get_bloginfo( 'name' ),
		'{{schema_org_description}}'             => get_bloginfo( 'description' ),
		'{{schema_org_addresscountry}}'          => frl_get_option( 'schema_org_addresscountry' ) ?: '',
		'{{schema_org_streetaddress}}'           => frl_get_option( 'schema_org_streetaddress' ) ?: '',
		'{{schema_org_addresslocality}}'         => frl_get_option( 'schema_org_addresslocality' ) ?: '',
		'{{schema_org_postalcode}}'              => frl_get_option( 'schema_org_postalcode' ) ?: '',
		'{{schema_org_areaserved}}'              => frl_get_option( 'schema_org_areaserved' ) ?: '',
		'{{schema_org_areaserved_sameas}}'       => frl_get_option( 'schema_org_areaserved_sameas' ) ?: '',
		'{{schema_org_foundingdate}}'            => frl_get_option( 'schema_org_foundingdate' ) ?: '',
		'{{schema_org_foundinglocation}}'        => frl_get_option( 'schema_org_foundinglocation' ) ?: '',
		'{{schema_org_foundinglocation_sameas}}' => frl_get_option( 'schema_org_foundinglocation_sameas' ) ?: '',
		'{{schema_founder_name}}'                => frl_get_option( 'schema_founder_name' ) ?: '',
		'{{schema_founder_url}}'                 => frl_get_option( 'schema_founder_url' ) ?: '',
		'{{schema_org_telephone}}'               => frl_get_option( 'schema_org_telephone' ) ?: '',
		'{{schema_contact_url}}'                 => frl_get_contact_page_url(),
		'{{schema_service_audiencetype}}'        => frl_get_option( 'schema_service_audiencetype' ) ?: 'Foreign investors, offshore companies, international entrepreneurs and expats',
	);

	if ( $post_id !== null ) {
		$map['{{post_title}}']            = get_the_title( $post_id );
		$map['{{post_permalink}}']        = get_permalink( $post_id );
		$map['{{post_date_published}}']   = get_the_date( 'c', $post_id );
		$map['{{post_date_modified}}']    = get_the_modified_date( 'c', $post_id );
		$map['{{post_excerpt}}']          = get_the_excerpt( $post_id ) ?: '';
		$thumb_id                         = get_post_thumbnail_id( $post_id );
		$thumb                            = $thumb_id ? wp_get_attachment_image_src( $thumb_id, 'large' ) : false;
		$map['{{post_thumbnail_url}}']    = $thumb[0] ?? '';
		$map['{{post_thumbnail_width}}']  = $thumb[1] ?? '';
		$map['{{post_thumbnail_height}}'] = $thumb[2] ?? '';
	}

	$cache[ $cache_key ] = $map;
	return $map;
}

/**
 * Build a Schema.org ImageObject from a WordPress attachment ID.
 *
 * @param int    $attachment_id Attachment/thumbnail ID.
 * @param string $size          Image size (default: 'medium').
 * @return array|null ImageObject array, or null if image not found.
 */
function frl_schema_build_image_object( int $attachment_id, string $size = 'medium' ): ?array {
	$size = apply_filters( 'frl_schema_thumbnail_size', $size );
	$url  = wp_get_attachment_image_url( $attachment_id, $size );
	$data = wp_get_attachment_image_src( $attachment_id, $size );

	if ( ! $url ) {
		return null;
	}

	$width  = $data[1] ?? 0;
	$height = $data[2] ?? 0;

	// SVG images: wp_get_attachment_image_src returns 0 or 1 — read from file
	if ( ( $width <= 1 || $height <= 1 ) && str_ends_with( strtolower( $url ), '.svg' ) ) {
		$svg_dims = frl_schema_get_svg_dimensions( $attachment_id );
		if ( $svg_dims ) {
			$width  = $svg_dims[0];
			$height = $svg_dims[1];
		}
	}

	return array(
		'@type'  => 'ImageObject',
		'url'    => $url,
		'width'  => $width,
		'height' => $height,
	);
}

/**
 * Read width and height from an SVG file.
 *
 * Parses width/height attributes or viewBox from the SVG source.
 * Result is cached per attachment ID for the request lifetime.
 *
 * @param int $attachment_id Attachment ID.
 * @return array{int, int}|null [width, height] or null if unreadable.
 */
function frl_schema_get_svg_dimensions( int $attachment_id ): ?array {
	return frl_cache_remember(
		'html',
		'svg_dims_' . $attachment_id,
		function () use ( $attachment_id ) {
			$file = get_attached_file( $attachment_id );
			if ( ! $file || ! file_exists( $file ) ) {
				return null;
			}

			// Read first 4KB — enough for the <svg> tag
			$handle = fopen( $file, 'r' );
			if ( ! $handle ) {
				return null;
			}
			$head = fread( $handle, 4096 );
			fclose( $handle );

			if ( ! $head ) {
				return null;
			}

			// Try width/height attributes
			if ( preg_match( '/<svg[^>]*\bwidth\s*=\s*["\']?(\d+(?:\.\d+)?)(?:px)?["\']?/i', $head, $w_match )
				&& preg_match( '/<svg[^>]*\bheight\s*=\s*["\']?(\d+(?:\.\d+)?)(?:px)?["\']?/i', $head, $h_match ) ) {
				return array( (int) $w_match[1], (int) $h_match[1] );
			}

			// Fallback: viewBox="min-x min-y width height"
			if ( preg_match( '/<svg[^>]*\bviewBox\s*=\s*["\']?\d+(?:\.\d+)?\s+\d+(?:\.\d+)?\s+(\d+(?:\.\d+)?)\s+(\d+(?:\.\d+)?)["\']?/i', $head, $vb_match ) ) {
				return array( (int) $vb_match[1], (int) $vb_match[2] );
			}

			return null;
		}
	);
}

/**
 * Read repeater rows from a post using ACF or ACPT.
 *
 * Each row is returned as an associative array built from $field_map.
 * $field_map maps output keys → source field names.
 *
 * @param int    $post_id   Post ID.
 * @param string $repeater  Repeater field name.
 * @param string $source    'acf' or 'acpt'.
 * @param array  $field_map Output key → source field name (e.g. ['name' => 'title', 'text' => 'answer']).
 * @return array List of associative arrays (one per row).
 */
function frl_schema_get_repeater_rows( int $post_id, string $repeater, string $source, array $field_map ): array {
	if ( $source === 'acpt' ) {
		return frl_schema_get_repeater_rows_acpt( $post_id, $repeater, $field_map );
	}
	return frl_schema_get_repeater_rows_acf( $post_id, $repeater, $field_map );
}

/**
 * Read repeater rows via ACF (have_rows / get_sub_field).
 *
 * @param int    $post_id   Post ID.
 * @param string $repeater  Repeater field name.
 * @param array  $field_map Output key → source field name.
 * @return array List of associative arrays.
 */
function frl_schema_get_repeater_rows_acf( int $post_id, string $repeater, array $field_map ): array {
	if ( ! function_exists( 'have_rows' ) ) {
		return array();
	}

	$rows = array();

	while ( have_rows( $repeater, $post_id ) ) {
		$row = array();
		foreach ( $field_map as $out_key => $field_name ) {
			$val = function_exists( 'get_sub_field' ) ? get_sub_field( $field_name ) : null;
			if ( $val !== null && $val !== false && $val !== '' ) {
				$row[ $out_key ] = is_string( $val ) ? $val : '';
			}
		}
		if ( ! empty( $row ) ) {
			$rows[] = $row;
		}
	}

	return $rows;
}

/**
 * Read repeater rows via ACPT (columnar serialized array).
 *
 * @param int    $post_id   Post ID.
 * @param string $repeater  Repeater field name.
 * @param array  $field_map Output key → source column name.
 * @return array List of associative arrays.
 */
function frl_schema_get_repeater_rows_acpt( int $post_id, string $repeater, array $field_map ): array {
	$data = frl_get_post_meta( $post_id, $repeater, true );
	if ( ! is_array( $data ) ) {
		return array();
	}

	// Determine row count from the first key
	$first_key = array_key_first( $field_map );
	if ( $first_key === null ) {
		return array();
	}

	$first_column = $data[ $field_map[ $first_key ] ] ?? array();
	if ( ! is_array( $first_column ) ) {
		return array();
	}

	$count = count( $first_column );
	$rows  = array();

	for ( $i = 0; $i < $count; $i++ ) {
		$row = array();
		foreach ( $field_map as $out_key => $col_name ) {
			$val = $data[ $col_name ][ $i ] ?? null;
			if ( $val !== null && $val !== '' ) {
				$row[ $out_key ] = (string) $val;
			}
		}
		if ( ! empty( $row ) ) {
			$rows[] = $row;
		}
	}

	return $rows;
}

/**
 * Resolve a definition value: placeholder → field name → literal.
 *
 * Resolution order:
 *   1. Replace {{placeholders}} via frl_schema_replace_placeholders
 *   2. If value changed → use resolved result
 *   3. Otherwise → treat as field name, resolve via frl_get_post_meta
 *
 * @param int    $post_id      Post ID.
 * @param string $raw          Raw definition value.
 * @param array  $placeholders Placeholder map (from frl_schema_get_placeholders).
 * @return string|null Resolved value, or null if unresolvable.
 */
function frl_schema_resolve_value( int $post_id, string $raw, array $placeholders ): ?string {
	// Fast path: no {{placeholder}} syntax
	if ( ! str_contains( $raw, '{{' ) ) {
		// @field: prefix → explicit field name, resolve via post meta
		if ( str_starts_with( $raw, '@field:' ) ) {
			return frl_schema_extract_scalar_value( frl_get_post_meta( $post_id, substr( $raw, 7 ), true ) );
		}
		// Bare string → literal value
		return $raw;
	}

	$resolved = frl_schema_replace_placeholders( $raw, $placeholders );

	// Placeholder was replaced → use directly
	if ( $resolved !== $raw ) {
		return ( $resolved !== '' ) ? $resolved : null;
	}

	// Treat as field name → resolve via post meta
	return frl_schema_extract_scalar_value( frl_get_post_meta( $post_id, $raw, true ) );
}

/**
 * Determine if a key should be translated based on the translate keys config.
 *
 * @param string $key           The bare key name.
 * @param string $current_path  The full dot-path of the current key.
 * @param array  $translate_keys FRL_SCHEMA_TRANSLATE_KEYS entries ('!' prefix = skip).
 * @return bool True if this key should be translated.
 */
function frl_schema_should_translate_key( string $key, string $current_path, array $translate_keys ): bool {
	$should_translate = false;

	foreach ( $translate_keys as $entry ) {
		$is_skip = str_starts_with( $entry, '!' );
		$rule    = $is_skip ? substr( $entry, 1 ) : $entry;

		$matches = str_contains( $rule, '.' )
			? str_starts_with( $current_path, $rule )  // dot-path prefix match
			: $key === $rule;                         // bare key exact match

		if ( $matches ) {
			if ( $is_skip ) {
				return false;         // '!' trumps immediately
			}
			$should_translate = true; // match found; keep checking for '!' overrides
		}
	}

	return $should_translate;
}

/**
 * Walk schema props and apply a callback to any {{token}} in string values.
 *
 * @param array    $props    Schema properties array.
 * @param callable $callback Callable(string $token): string.
 * @return array Props with {{…}} tokens replaced.
 */
function frl_schema_replace_placeholders_callback( array $props, callable $callback ): array {
	$result = array();
	foreach ( $props as $key => $value ) {
		if ( is_array( $value ) ) {
			$result[ $key ] = frl_schema_replace_placeholders_callback( $value, $callback );
		} elseif ( is_string( $value ) && str_contains( $value, '{{' ) ) {
			$result[ $key ] = preg_replace_callback(
				'/\{\{([^}]+)\}\}/',
				$callback,
				$value
			);
		} else {
			$result[ $key ] = $value;
		}
	}
	return $result;
}

/**
 * Merge properties into a schema array.
 *
 * Array values are deep-merged via array_replace_recursive to preserve
 * unset sub-keys from the source. Scalar values overwrite unconditionally.
 * Null sentinel removes the property from the schema.
 *
 * @param array $schema The schema array.
 * @param array $props  Properties to inject (property key => value).
 * @return array Modified schema array.
 */
function frl_schema_merge_properties( array $schema, array $props ): array {
	if ( empty( $props ) ) {
		return $schema;
	}

	foreach ( $props as $key => $value ) {
		// Sentinel: null means remove the property
		if ( $value === null ) {
			unset( $schema[ $key ] );
			continue;
		}

		if ( is_array( $value ) ) {
			if ( ! isset( $schema[ $key ] ) || ! is_array( $schema[ $key ] ) ) {
				$schema[ $key ] = $value;
			} else {
				$schema[ $key ] = array_replace_recursive( $schema[ $key ], $value );
			}
			continue;
		}

		// Scalar property: overwrite unconditionally
		$schema[ $key ] = $value;
	}

	return $schema;
}

/**
 * Recursively trim whitespace-contaminated keys in a schema array.
 *
 * Only processes keys that contain leading/trailing whitespace.
 * Targeted fix for third-party bugs (e.g., SASWP's 'name ' key).
 *
 * @param array $array_value The schema array to process.
 * @return array Array with trimmed keys (only where needed).
 */
function frl_schema_trim_keys( array $array_value ): array {
	$result        = array();
	$needs_rebuild = false;

	foreach ( $array_value as $key => $value ) {
		$trimmed_key = trim( $key );
		if ( $key !== '' && $key !== $trimmed_key ) {
			$needs_rebuild = true;
		}
		if ( is_array( $value ) ) {
			$trimmed_value = frl_schema_trim_keys( $value );
			if ( $trimmed_value !== $value ) {
				$needs_rebuild = true;
			}
			$result[ $trimmed_key ] = $trimmed_value;
		} else {
			$result[ $trimmed_key ] = $value;
		}
	}

	return $needs_rebuild ? $result : $array_value;
}

/**
 * Recursively remove empty properties from a schema array.
 *
 * Strips empty strings, nulls, and empties cleared by the recursion itself.
 * List arrays (numeric, zero-indexed) are re-indexed to preserve JSON [] output.
 * Never removes @type — required for Schema.org validity.
 * Preserves falsy-but-valid values (0, false, "0").
 *
 * @param array $schema The schema array or sub-array.
 * @return array Schema with empty properties stripped.
 */
function frl_schema_strip_empty( array $schema ): array {
	$result = array();

	foreach ( $schema as $key => $value ) {
		if ( is_array( $value ) ) {
			$cleaned = frl_schema_strip_empty( $value );
			$is_list = $value !== array() && array_key_first( $value ) === 0;
			if ( $is_list ) {
				$cleaned = array_values( $cleaned );
			}

			// Drop sub-objects that only have @type (no meaningful properties)
			if ( ! $is_list && isset( $cleaned['@type'] ) && count( $cleaned ) === 1 ) {
				continue;
			}

			if ( ! empty( $cleaned ) ) {
				$result[ $key ] = $cleaned;
			}
			continue;
		}

		// Drop empty strings and nulls; keep 0, false, "0"
		if ( $value === '' || $value === null ) {
			continue;
		}

		$result[ $key ] = $value;
	}

	return $result;
}
