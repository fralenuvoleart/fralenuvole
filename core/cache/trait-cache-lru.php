<?php
declare(strict_types=1);
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runtime-cache LRU tracking/eviction for Frl_Cache_Manager (hottest read/write path).
 * Extracted from Frl_Cache_Manager for maintainability. $runtime_cache/$group_keys
 * stay on the host class since other non-LRU methods also touch them directly.
 *
 * @property static array $runtime_cache
 * @property static array $group_keys
 * @package Fralenuvole
 * @since 5.7.4.5
 */
trait Frl_Cache_Lru_Trait {

	// Consolidated LRU tracking
	private static array $lru = array(
		'access_order' => array(),
	);

	private static int $max_runtime_items = FRL_CACHE_RUNTIME_MAX_ITEMS;

	/**
	 * Store a value in runtime cache with LRU tracking and group indexing.
	 *
	 * @param string $cache_key The generated cache key.
	 * @param mixed $value The value to store.
	 * @param string|null $group The cache group (extracted from key if null).
	 * @return void
	 */
	private static function set_runtime( $cache_key, $value, $group = null ) {
		// Store in runtime cache
		self::$runtime_cache[ $cache_key ] = $value;

		// Index the key by group for efficient clearing
		if ( $group === null ) {
			$parts = explode( '_', $cache_key, 2 );
			$group = $parts[0] ?? 'default';
		}
		self::$group_keys[ $group ][ $cache_key ] = 1;

		// Update access order (O(1) update via associative assignment)
		// We use associative array to move the key to the end of the array (most recently used)
		unset( self::$lru['access_order'][ $cache_key ] );
		self::$lru['access_order'][ $cache_key ] = 1;

		// Prune if over limit (True LRU)
		if ( count( self::$runtime_cache ) > self::$max_runtime_items ) {
			reset( self::$lru['access_order'] );
			$oldest_key = key( self::$lru['access_order'] );

			if ( $oldest_key !== null ) {
				self::remove_runtime_item( $oldest_key );
			}
		}
	}

	/**
	 * Remove an item from runtime cache and all its indices.
	 *
	 * @param string $cache_key The generated cache key.
	 * @param string|null $group The cache group (extracted from key if null).
	 * @return void
	 */
	private static function remove_runtime_item( $cache_key, $group = null ) {
		// Remove from main storage
		unset( self::$runtime_cache[ $cache_key ] );

		// Remove from LRU tracking
		unset( self::$lru['access_order'][ $cache_key ] );

		// Remove from group index
		if ( $group === null ) {
			$parts = explode( '_', $cache_key, 2 );
			$group = $parts[0] ?? 'default';
		}
		if ( isset( self::$group_keys[ $group ] ) ) {
			unset( self::$group_keys[ $group ][ $cache_key ] );
			if ( empty( self::$group_keys[ $group ] ) ) {
				unset( self::$group_keys[ $group ] );
			}
		}
	}

	/**
	 * Get an item from runtime cache.
	 *
	 * @param string $cache_key Cache key.
	 * @return mixed|null Cached value or null if not found.
	 */
	private static function get_runtime( $cache_key ) {
		if ( isset( self::$runtime_cache[ $cache_key ] ) ) {
			// Move to end of access order (O(1) update)
			unset( self::$lru['access_order'][ $cache_key ] );
			self::$lru['access_order'][ $cache_key ] = 1;

			return self::$runtime_cache[ $cache_key ];
		}
		return null;
	}
}
