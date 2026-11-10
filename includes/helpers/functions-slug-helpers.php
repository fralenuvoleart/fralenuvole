<?php
/**
 * Slug lookups with caching.
 *
 * @package Fralenuvole
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bounded negative-lookup index.
 *
 * Answers repeated misses without a query and without one cache entry per URL.
 */

/** Shard key for a negative-lookup entry. */
function frl_slug_negative_bucket( string $context, string $key ): string {
	return 'negidx_' . md5( $context ) . '_' . ( abs( crc32( $key ) ) % 8 );
}

/** Whether a slug is already known to be missing. */
function frl_slug_negative_get( string $context, string $key ): bool {
	$map = frl_cache_get( 'permalinks', frl_slug_negative_bucket( $context, $key ) );

	return is_array( $map ) && isset( $map[ $key ] );
}

/** Record a slug as missing. 8 shards x 128 entries, 1h TTL. */
function frl_slug_negative_mark( string $context, string $key ): void {
	$bucket = frl_slug_negative_bucket( $context, $key );
	$map    = frl_cache_get( 'permalinks', $bucket );
	if ( ! is_array( $map ) ) {
		$map = array();
	}
	if ( isset( $map[ $key ] ) ) {
		return;
	}

	$map[ $key ] = time();
	if ( count( $map ) > 128 ) {
		$map = array_slice( $map, -128, null, true );
	}

	frl_cache_set( 'permalinks', $bucket, $map, HOUR_IN_SECONDS );
}

/**
 * Get a post ID from a slug or hierarchical path.
 *
 * @param string      $slug Post slug or path.
 * @param string|null $lang Language override.
 * @return int Post ID, or 0 when not found.
 */
function frl_get_post_id_by_slug( $slug, ?string $lang = null ) {
	$lang = $lang ?? frl_get_language();

	$context   = 'post';
	$index_key = $lang . '|' . $slug;

	// Known miss: no query, no per-URL key.
	if ( frl_slug_negative_get( $context, $index_key ) ) {
		return 0;
	}

	$cache_key = 'postslug2id_' . sanitize_key( $lang ) . '_' . md5( $slug );
	$cached    = frl_cache_get( 'permalinks', $cache_key );

	// Only positive hits are cached per slug; misses go to the bounded index.
	if ( null !== $cached ) {
		return (int) $cached;
	}

	// pagename covers hierarchical types; name covers the rest.
	$posts = get_posts(
		array(
			'post_type'   => get_post_types(
				array(
					'public'       => true,
					'hierarchical' => true,
				)
			),
			'pagename'    => $slug,
			'post_status' => 'publish',
			'numberposts' => 1,
			'lang'        => $lang,
		)
	);

	if ( empty( $posts ) && ! str_contains( $slug, '/' ) ) {
		$posts = get_posts(
			array(
				'post_type'   => get_post_types(
					array(
						'public'       => true,
						'hierarchical' => false,
					)
				),
				'name'        => $slug,
				'post_status' => 'publish',
				'numberposts' => 1,
				'lang'        => $lang,
			)
		);
	}

	if ( ! empty( $posts ) ) {
		$post_id = (int) $posts[0]->ID;
		frl_cache_set( 'permalinks', $cache_key, $post_id );
		return $post_id;
	}

	frl_slug_negative_mark( $context, $index_key );

	return 0;
}

/**
 * Get a term ID from a slug or parent/child path.
 *
 * @param string $slug     Term slug or path.
 * @param string $taxonomy Taxonomy name.
 * @return int Term ID, or 0 when not found.
 */
function frl_get_term_id_by_slug( $slug, $taxonomy = 'category' ) {
	if ( empty( $slug ) ) {
		return 0;
	}

	$context   = 'term_' . $taxonomy;
	$index_key = frl_get_language() . '|' . $slug;

	// Known miss: no query, no per-URL key.
	if ( frl_slug_negative_get( $context, $index_key ) ) {
		return 0;
	}

	// md5: sanitize_key() strips '/', so 'a/b' and 'ab' would share a key.
	$cache_key = 'termslug2id_' . sanitize_key( $taxonomy ) . '_' . md5( $slug );
	$cached    = frl_cache_get( 'permalinks', $cache_key );
	if ( null !== $cached ) {
		return (int) $cached;
	}

	$term = frl_find_term_by_path( (string) $slug, $taxonomy );
	if ( $term ) {
		$term_id = (int) $term->term_id;
		frl_cache_set( 'permalinks', $cache_key, $term_id );
		return $term_id;
	}

	frl_slug_negative_mark( $context, $index_key );

	return 0;
}

/**
 * Resolve a term from a slug or a parent/child path.
 *
 * @param string $path     Slug or hierarchical path.
 * @param string $taxonomy Taxonomy name.
 * @return WP_Term|null Term on success, null when not found.
 */
function frl_find_term_by_path( string $path, string $taxonomy ) {
	$parts = array_values( array_filter( explode( '/', trim( $path, '/' ) ) ) );
	if ( empty( $parts ) ) {
		return null;
	}

	$term = get_term_by( 'slug', end( $parts ), $taxonomy );
	if ( ! $term ) {
		return null;
	}
	if ( count( $parts ) === 1 ) {
		return $term;
	}

	// The parent chain must match the rest of the path.
	$expected  = array_slice( $parts, 0, -1 );
	$ancestors = array_reverse( get_ancestors( $term->term_id, $taxonomy, 'taxonomy' ) );
	$actual    = array();
	foreach ( $ancestors as $ancestor_id ) {
		$ancestor = get_term( $ancestor_id, $taxonomy );
		if ( $ancestor && ! is_wp_error( $ancestor ) ) {
			$actual[] = $ancestor->slug;
		}
	}

	return $actual === $expected ? $term : null;
}

/**
 * Get a CPT post ID from a slug, with caching.
 *
 * @param string $slug Post slug or path.
 * @param string $cpt  Custom post type.
 * @return int Post ID, or 0 when not found.
 */
function frl_get_cpt_id_by_slug( $slug, $cpt ) {
	if ( empty( $slug ) || empty( $cpt ) ) {
		return 0;
	}

	$context   = 'cpt_' . $cpt;
	$index_key = frl_get_language() . '|' . $slug;

	// Known miss: no query, no per-URL key.
	if ( frl_slug_negative_get( $context, $index_key ) ) {
		return 0;
	}

	$cache_key = 'cptslug2id_' . sanitize_key( $cpt ) . '_' . md5( $slug );
	$cached    = frl_cache_get( 'permalinks', $cache_key );

	// Only positive hits are cached per slug; misses go to the bounded index.
	if ( null !== $cached ) {
		return (int) $cached;
	}

	$post = get_page_by_path( $slug, OBJECT, $cpt );
	if ( $post ) {
		$post_id = (int) $post->ID;
		frl_cache_set( 'permalinks', $cache_key, $post_id );
		return $post_id;
	}

	frl_slug_negative_mark( $context, $index_key );

	return 0;
}
