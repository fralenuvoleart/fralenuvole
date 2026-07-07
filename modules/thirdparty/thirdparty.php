<?php

/**
 * Module Name: Third-Party
 * Description: Cache Bridge for caching plugins, and tweaks for third-party plugins
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/config-constants-thirdparty.php';

add_action( 'wp_enqueue_scripts', 'frl_thirdparty_public_scripts', FRL_THEMEKIT_STYLE_PRIORITY['modules'], 1 );
add_action( 'admin_enqueue_scripts', 'frl_thirdparty_admin_scripts', 0, 0 );
add_filter( 'emr/feature/background', '__return_false', 10, 0 );
add_filter( 'rest_endpoints', 'frl_greenshift_fix_rest_schemas', 10, 1 );
add_filter( 'wp_rest_cache/display_clear_cache_button', '__return_false', 10, 1 );

// SASWP schema injection hooks — only register when the schema subsystem is loaded conditionally via frl_is_valid_frontend_page_request() in frl_load_public_components().
if ( frl_is_valid_frontend_page_request() ) {
	add_filter( 'saswp_modify_organization_output', 'frl_thirdparty_inject_schema_properties_filter', 10, 1 );
	add_filter( 'saswp_modify_about_page_output', 'frl_thirdparty_inject_schema_properties_filter', 10, 1 );
	add_filter( 'saswp_modify_contact_page_output', 'frl_thirdparty_inject_schema_properties_filter', 10, 1 );
	add_filter( 'saswp_modify_author_output', 'frl_thirdparty_inject_schema_properties_filter', 10, 1 );
	add_filter( 'saswp_modify_website_output', 'frl_thirdparty_inject_schema_properties_filter', 10, 1 );
	add_filter( 'saswp_modify_profile_page_schema_output', 'frl_thirdparty_inject_schema_properties_filter', 10, 1 );
	add_filter( 'saswp_modify_schema_output', 'frl_thirdparty_sanitize_schemas', 9999, 1 );
}

/**
 * Enqueue thirdparty-specific styles and scripts
 */
function frl_thirdparty_public_scripts() {
	if ( ! frl_is_valid_frontend_page_request() ) {
		return;
	}
	$assets = array(
		'thirdparty-public-css' => 'modules/thirdparty/assets/css/public.css',
	);

	frl_enqueue_scripts( $assets, 'thirdparty_public' );
}

/**
 * Enqueue thirdparty-specific styles and scripts
 */
function frl_thirdparty_admin_scripts() {
	$assets = array(
		'thirdparty-admin-css' => 'modules/thirdparty/assets/css/admin.css',
	);

	// Load Meow-specific styles only when any Meow plugin is active.
	// Array of known plugin paths — the same admin-meow.css applies to all.
	$meow_plugins = array(
		'ai-engine/ai-engine.php',
		'seo-engine/seo-engine.php',
	);

	foreach ( $meow_plugins as $plugin_path ) {
		if ( frl_is_thirdparty_plugin_active( $plugin_path ) ) {
			$assets['thirdparty-admin-meow-css'] = 'modules/thirdparty/assets/css/admin-meow.css';
			break;
		}
	}

	frl_enqueue_scripts( $assets, 'thirdparty_admin' );
}

/**
 * Fix invalid schema types in third-party REST endpoints.
 * - Greenshift: ensure post_id uses a valid 'integer' type to satisfy WP schema.
 *
 * @param array $endpoints
 * @return array
 */
function frl_greenshift_fix_rest_schemas( $endpoints ) {
	static $done = false;
	if ( $done ) {
		return $endpoints;
	}

	$route = '/greenshift/v1/get-post-part';
	if ( ! isset( $endpoints[ $route ] ) || ! is_array( $endpoints[ $route ] ) ) {
		return $endpoints;
	}

	foreach ( $endpoints[ $route ] as $i => $endpoint ) {
		if ( ! isset( $endpoint['args'] ) || ! is_array( $endpoint['args'] ) ) {
			continue;
		}
		if ( isset( $endpoint['args']['post_id'] ) ) {
			$type = $endpoint['args']['post_id']['type'] ?? null;
			if ( $type !== 'integer' ) {
				$endpoints[ $route ][ $i ]['args']['post_id']['type'] = 'integer';
			}
			if ( ! isset( $endpoints[ $route ][ $i ]['args']['post_id']['sanitize_callback'] ) ) {
				$endpoints[ $route ][ $i ]['args']['post_id']['sanitize_callback'] = 'absint';
			}
			if ( ! isset( $endpoints[ $route ][ $i ]['args']['post_id']['validate_callback'] ) ) {
				$endpoints[ $route ][ $i ]['args']['post_id']['validate_callback'] = static function ( $value ) {
					return is_numeric( $value );
				};
			}
		}
	}
	$done = true;
	return $endpoints;
}

/**
 * Inject third-party properties into a single SASWP schema.
 *
 * Generic per-schema filter — checks frl_schema_resolver_get()
 * for the schema's @type and injects matching properties.
 *
 * Hooks into 'saswp_modify_organization_output' (and can be reused for
 * other per-schema filters if SASWP exposes them).
 *
 * @param array $input The schema output array.
 * @return array Modified schema array.
 */
function frl_thirdparty_inject_schema_properties_filter( array $input ): array {
	if ( ! frl_get_option( 'thirdparty_schema_properties' ) ) {
		return $input;
	}

	$type  = $input['@type'] ?? '';
	$props = frl_schema_resolver_get()[ $type ] ?? array();

	if ( empty( $props ) ) {
		return $input;
	}

	return frl_thirdparty_inject_schema_properties( $input, $props );
}

/**
 * Recursively trim whitespace-contaminated keys in a schema array.
 *
 * Only processes keys that contain leading/trailing whitespace.
 * This is a targeted fix for third-party bugs (e.g., SASWP's 'name ' key).
 *
 * @param array $array_value The schema array to process.
 * @return array Array with trimmed keys (only where needed).
 */
function frl_trim_schema_keys( array $array_value ): array {
	$result        = array();
	$needs_rebuild = false;

	foreach ( $array_value as $key => $value ) {
		$trimmed_key = trim( $key );
		if ( $key !== '' && $key !== $trimmed_key ) {
			$needs_rebuild = true;
		}
		if ( is_array( $value ) ) {
			$trimmed_value = frl_trim_schema_keys( $value );
			if ( $trimmed_value !== $value ) {
				$needs_rebuild = true;
			}
			$result[ $trimmed_key ] = $trimmed_value;
		} else {
			$result[ $trimmed_key ] = $value;
		}
	}

	// Only return a new array if we actually found contaminated keys
	return $needs_rebuild ? $result : $array_value;
}

/**
 * Sanitize and deduplicate SASWP schema output.
 *
 * Hooks into 'saswp_modify_schema_output' to:
 * - Deduplicate by @id, keep first occurrence
 * - Inject static props from frl_schema_resolver_get()
 * - Inject post-term props from frl_schema_builder_get_term_map()
 * - Inject person reference props from frl_schema_builder_get_person_map()
 * - Trim whitespace-contaminated keys
 *
 * @param array $schemas Array of all schema output arrays.
 * @return array Sanitized, deduplicated, and enhanced schema array.
 */
function frl_thirdparty_sanitize_schemas( array $schemas ): array {
	static $done = false;
	if ( $done ) {
		return $schemas;
	}

	if ( ! frl_get_option( 'thirdparty_schema_properties' ) ) {
		return $schemas;
	}

	$all_props    = frl_schema_resolver_get();
	$seen_ids     = array();
	$deduplicated = array();

	// Pre-resolve post data once, outside the loop
	$post_id           = get_the_ID();
	$schema_term_map   = frl_schema_builder_get_term_map();
	$taxonomy_cache    = array();
	$schema_person_map = frl_schema_builder_get_person_map();
	$ref_cache         = array();

	foreach ( $schemas as $schema ) {
		if ( ! is_array( $schema ) || empty( $schema['@id'] ) ) {
			$deduplicated[] = $schema;
			continue;
		}

		$id         = $schema['@id'];
		$type       = $schema['@type'] ?? '';
		$props      = $all_props[ $type ] ?? array();
		$type_map   = $schema_term_map[ $type ] ?? null;
		$person_map = $schema_person_map[ $type ] ?? null;

		// Skip types with no enrichment defined
		if ( empty( $props ) && empty( $type_map ) && empty( $person_map ) ) {
			$deduplicated[] = $schema;
			continue;
		}

		// First occurrence: inject static props, post-term props, and person props
		if ( ! isset( $seen_ids[ $id ] ) ) {
			if ( ! empty( $props ) ) {
				$props  = frl_schema_resolve_post_placeholders( $props, $post_id );
				$schema = frl_thirdparty_inject_schema_properties( $schema, $props );
			}

			if ( $post_id && ! empty( $type_map ) ) {
				$post_props = frl_schema_builder_build_term_properties( $post_id, $type_map, $taxonomy_cache );
				if ( ! empty( $post_props ) ) {
					$schema = frl_thirdparty_inject_schema_properties( $schema, $post_props );
				}
			}

			if ( $post_id && ! empty( $person_map ) ) {
				$person_props = frl_schema_builder_build_person_properties( $post_id, $person_map, $ref_cache );
				if ( ! empty( $person_props ) ) {
					$schema = frl_thirdparty_inject_schema_properties( $schema, $person_props );
				}
			}

			$seen_ids[ $id ] = true;
			$deduplicated[]  = $schema;
			continue;
		}

		// Duplicate found: discard, keep first occurrence
	}

	$done = true;

	// Single trim pass on the final output — catches all contaminated keys
	// from any source (SASWP bugs, nested schemas, etc.) in one O(n) walk.
	return array_map(
		function ( $s ) {
			return is_array( $s ) ? frl_trim_schema_keys( $s ) : $s;
		},
		$deduplicated
	);
}

/**
 * Inject third-party properties into a single schema.
 *
 * Array values (Person objects, term collections, etc.) are deep-merged
 * via array_replace_recursive to preserve unset sub-keys from the source
 * (e.g. sameAs). Scalar values overwrite the existing value unconditionally.
 *
 * Special sentinel: if $value is null, the property is removed from the schema.
 *
 * @param array $schema The schema array.
 * @param array $props Properties to inject (property key => value).
 * @return array Modified schema array.
 */
function frl_thirdparty_inject_schema_properties( array $schema, array $props ): array {
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
