<?php
declare(strict_types=1);
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait providing generate_cache_key() utility used by classes that transform URLs.
 * No dependencies on the consuming class except method invocation context.
 *
 * @package Fralenuvole
 * @since 3.0.0
 */
trait Frl_Rewriter_Cache_Key_Trait {

	/**
	 * Generate stable cache key for transformed URLs.
	 * Extracted from Frl_Rewriter::generate_cache_key() unchanged.
	 */
	private function generate_cache_key( string $url, $obj ): string {
		// Guard against non-object inputs (e.g., archive slugs).
		if ( ! is_object( $obj ) ) {
			$object_type    = is_scalar( $obj ) ? 'scalar_' . (string) $obj : gettype( $obj );
			$unique_id      = 'non_object';
			$cache_modifier = '';
		} else {
			$object_type = get_class( $obj );

			// Generate unique identifier to prevent post/term ID collisions
			if ( isset( $obj->ID ) ) {
				$unique_id = "post_{$obj->ID}";
				// Include post_modified for cache invalidation when content changes
				// Optimization: Use crc32 for much faster hashing of the date string.
				$cache_modifier = isset( $obj->post_modified ) ? dechex( crc32( (string) $obj->post_modified ) ) : '';
			} elseif ( isset( $obj->term_id ) ) {
				$unique_id = "term_{$obj->term_id}";
				// Use term count as cache modifier for terms
				$cache_modifier = isset( $obj->count ) ? $obj->count : '0';
			} else {
				$unique_id      = 'unknown_0';
				$cache_modifier = '';
			}
		}

		// Optimize URL hash - only hash the path portion (domain-agnostic)
		$relative  = wp_make_link_relative( $url );
		$path_only = wp_parse_url( $relative, PHP_URL_PATH ) ?: $relative;
		// Optimization: Use crc32 for much faster hashing of the URL path.
		$url_hash = dechex( crc32( (string) $path_only ) );

		// Cache keys for group permalink are already language-aware
		return "rewriter_key_{$object_type}_{$unique_id}_{$cache_modifier}_{$url_hash}";
	}
}
