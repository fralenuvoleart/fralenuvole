<?php

/**
 * Core cache manager: runtime (LRU), persistent (object cache/transients),
 * batch loading, dependency cascading, and deferred writes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/trait-cache-diagnostics.php';
require_once __DIR__ . '/trait-cache-batch.php';
require_once __DIR__ . '/trait-cache-lru.php';

class Frl_Cache_Manager {

	use Frl_Cache_Diagnostics_Trait;
	use Frl_Cache_Batch_Trait;
	use Frl_Cache_Lru_Trait;

	/** Cache key prefix. */
	const PREFIX                        = FRL_CACHE_PREFIX;
	private static array $runtime_cache = array();
	private static array $key_cache     = array();
	private static array $group_keys    = array(); // Index of keys per group for efficient clearing
	private static array $loaded_groups = array(); // Tracks which groups have been fully loaded this request

	public static array $deferred_writes = array();

	/** Groups persisted across requests. */
	private static array $persistent_groups = FRL_CACHE_PERSISTENT_GROUPS;

	/** Default TTL per group (seconds). */
	private static array $default_ttls = FRL_CACHE_TTL;

	const LOCK_TTL = FRL_CACHE_LOCK_TTL;

	/** Groups that trigger browser cache-clear headers. */
	private const BROWSER_CACHE_GROUPS = FRL_CACHE_BROWSER_GROUPS;

	private static array $cache_dependencies = FRL_CACHE_DEPENDENCIES;

	/** Groups cleared this request (dedup). */
	private static array $groups_cleared = array();

	/** Flag: batch transient deletion already performed this request. */
	private static bool $transients_batch_deleted = false;

	/** O(1) lookup maps. */
	private static array $persistent_groups_map = array();
	private static array $language_groups_map   = array();
	private static array $heavy_groups_map      = array();
	private static array $browser_groups_map    = array();

	/** Cached provider details. */
	private static ?array $cached_provider_details = null;

	/**
	 * Initialize lookup maps and auto-preload.
	 *
	 * @return void
	 */
	public static function init() {
		// Initialize lookup maps for O(1) performance
		self::$persistent_groups_map = array_flip( self::$persistent_groups );
		self::$language_groups_map   = array_flip( FRL_CACHE_LANGUAGE_GROUPS );
		self::$heavy_groups_map      = array_flip( FRL_CACHE_HEAVY_GROUPS );
		self::$browser_groups_map    = array_flip( self::BROWSER_CACHE_GROUPS );

		// Auto-preload cache groups when cache manager is first loaded
		self::auto_preload();
	}

	/**
	 * Preload groups for current context (admin/frontend).
	 *
	 * When object cache is not functional and transients are in use,
	 * batches all group queries into a single DB query to avoid
	 * 5-6 separate LIKE scans on wp_options per cold-cache request.
	 *
	 * @return bool True if preloaded, false if skipped (e.g., during AJAX).
	 */
	public static function auto_preload() {
		// Skip preloading during AJAX requests to prevent potential issues.
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return false;
		}

		$groups_to_preload = frl_is_admin()
			? FRL_CACHE_PRELOAD_BACKEND_GROUPS
			: FRL_CACHE_PRELOAD_FRONTEND_GROUPS;

		// When all groups are persistent and object cache is not functional,
		// batch them into a single DB query instead of N separate LIKE scans.
		$all_persistent = true;
		foreach ( $groups_to_preload as $group ) {
			if ( ! isset( self::$persistent_groups_map[ $group ] ) || isset( self::$loaded_groups[ $group ] ) ) {
				$all_persistent = false;
				break;
			}
		}

		if ( $all_persistent && ! self::is_object_cache_truly_functional() ) {
			self::batch_preload_transients( $groups_to_preload );
			return true;
		}

		// Object cache functional or mixed groups — per-group preload (no DB queries needed)
		foreach ( $groups_to_preload as $group ) {
			self::preload_multi( $group );
		}

		return true;
	}

	/**
	 * Check if caching is bypassed (disable_plugin, disable_cache, nocache/core mode).
	 *
	 * @return bool True if caching should be bypassed.
	 */
	private static function should_bypass() {
		static $should_bypass = null;

		if ( $should_bypass !== null ) {
			return $should_bypass;
		}

		// Combine checks: for disable_plugin or disable_cache
		// Calls directly get_option to avoid circularity with frl_get_option
		$disable_plugin = ( get_option( 'frl_disable_plugin', '0' ) === '1' );
		$disable_cache  = ( get_option( 'frl_disable_cache', '0' ) === '1' );

		$nocache_url = ( defined( 'FRL_MODE' ) && FRL_MODE === 'nocache' );
		$core_mode   = ( defined( 'FRL_MODE' ) && FRL_MODE === 'core' );

		if ( $disable_plugin || $disable_cache || $nocache_url || $core_mode ) {
			$should_bypass = true;
		} else {
			$should_bypass = false;
		}

		return $should_bypass;
	}

	/**
	 * Whether to use transients for a persistent group when object cache is not functional.
	 *
	 * @param string $group Cache group name.
	 * @return bool True if transient fallback should be used.
	 */
	private static function use_transient_fallback( string $group ): bool {
		return ! self::is_object_cache_truly_functional() && isset( self::$persistent_groups_map[ $group ] );
	}

	/**
	 * Check if a plugin is active site-wide or network-wide (cached per request).
	 *
	 * @param string $plugin_path Plugin path relative to plugins directory.
	 * @return bool True if plugin is globally active.
	 */
	private static function _is_plugin_globally_active( $plugin_path ) {
		return frl_is_thirdparty_plugin_active( $plugin_path );
	}

	/**
	 * Check if a recognized object cache (Litespeed, Docket, Redis, Memcached) is functional.
	 *
	 * @return bool True if object cache is functional.
	 */
	public static function is_object_cache_truly_functional() {
		static $is_truly_functional = null;

		if ( $is_truly_functional !== null ) {
			return $is_truly_functional;
		}

		if ( self::is_bypass_active() ) {
			$is_truly_functional = false;
			return $is_truly_functional;
		}

		if ( ! wp_using_ext_object_cache() ) {
			$is_truly_functional = false;
			return $is_truly_functional;
		}

		$provider_details    = self::get_provider_details();
		$is_truly_functional = $provider_details['is_effectively_functional'];
		return $is_truly_functional;
	}

	/**
	 * Alias for should_bypass().
	 *
	 * @return bool True if caching is bypassed.
	 */
	public static function is_bypass_active() {
		// Simply return the result of the internal check
		return self::should_bypass();
	}

	/**
	 * Generate a cache key. Adds language prefix for language-specific groups.
	 *
	 * @param string $group Cache group name.
	 * @param string|array $key Cache key (string or array to be hashed).
	 * @return string Generated cache key.
	 */
	private static function generate_key( $group, $key ) {
		// Process array keys first to avoid duplication
		if ( is_array( $key ) ) {
			$key_str = json_encode( $key );
			if ( ! isset( self::$key_cache[ $key_str ] ) ) {
				// Use faster hashing if available
				if ( function_exists( 'xxh3_hash64' ) ) {
					/** @disregard P1010 Undefined type */
					self::$key_cache[ $key_str ] = dechex( xxh3_hash64( $key_str ) );
				} else {
					self::$key_cache[ $key_str ] = md5( $key_str ); // Fallback
				}
			}
			$key = self::$key_cache[ $key_str ];
		}

		// For language-specific groups, add language prefix (O(1) lookup)
		if ( isset( self::$language_groups_map[ $group ] ) ) {
			$lang = frl_get_language();
			return $group . '_' . $lang . '_' . $key;
		}

		// Standard key generation for other groups
		return $group . '_' . $key;
	}

	/**
	 * Set a value in cache (runtime and persistent).
	 *
	 * @param string $group Cache group name.
	 * @param string|array $key Cache key.
	 * @param mixed $value Value to cache.
	 * @param int|null $ttl Time to live in seconds (uses group default if null).
	 * @return bool True on success.
	 */
	public static function set( $group, $key, $value, $ttl = null ) {
		if ( self::should_bypass() ) {
			return true;
		}

		$cache_key = self::generate_key( $group, $key );

		// Sanitize lazily (only on failure) — identical output, no wasted work
		// on the common case where $value already serializes fine.
		try {
			serialize( $value );
		} catch ( \Throwable $e ) {
			if ( function_exists( 'frl_sanitize_for_serialization' ) ) {
				frl_sanitize_for_serialization( $value );
			} else {
				// Skip caching unserializable values when sanitizer unavailable
				return true;
			}
		}

		// Store in runtime cache
		self::set_runtime( $cache_key, $value, $group );

		// Get TTL
		$ttl = $ttl ?? ( self::$default_ttls[ $group ] ?? self::$default_ttls['default'] );

		// Store in persistent cache
		if ( self::is_object_cache_truly_functional() ) {
			return wp_cache_set( $cache_key, $value, self::PREFIX . $group, $ttl );
		}

		if ( self::use_transient_fallback( $group ) ) {
			return set_transient( self::PREFIX . $cache_key, $value, $ttl );
		}

		return true;
	}

	/**
	 * Set multiple values in a single cache group in one batch.
	 *
	 * For object-cache backends, this collapses N network round-trips into one via
	 * wp_cache_set_multiple() (WP 6.0+; falls back to a per-item loop on older cores or
	 * drop-ins that don't implement it). Intended for callers writing several keys to the
	 * same group at once (e.g. shutdown-time deferred writes) where the individual TTL is
	 * uniform. Transient-fallback storage still writes one row-pair per item via
	 * set_transient() — batching that path would require bypassing WordPress's option
	 * hooks/autoload/cache-invalidation machinery, which is not done here to avoid
	 * introducing behavioral regressions for a comparatively rare code path (no external
	 * object cache configured).
	 *
	 * @param string $group Cache group name.
	 * @param array<string, mixed> $items Map of key => value to store.
	 * @param int|null $ttl Time to live in seconds (uses group default if null).
	 * @return bool True on success (or when bypassed/empty).
	 */
	public static function set_multi( string $group, array $items, ?int $ttl = null ): bool {
		if ( self::should_bypass() || empty( $items ) ) {
			return true;
		}

		$ttl = $ttl ?? ( self::$default_ttls[ $group ] ?? self::$default_ttls['default'] );

		$sanitized_items = array();
		foreach ( $items as $key => $value ) {
			// Sanitize lazily (only on failure) — mirrors set()'s handling.
			try {
				serialize( $value );
			} catch ( \Throwable $e ) {
				if ( function_exists( 'frl_sanitize_for_serialization' ) ) {
					frl_sanitize_for_serialization( $value );
				} else {
					// Skip caching unserializable values when sanitizer unavailable.
					continue;
				}
			}

			$cache_key                     = self::generate_key( $group, $key );
			$sanitized_items[ $cache_key ] = $value;

			// Store in runtime cache individually (cheap, in-memory).
			self::set_runtime( $cache_key, $value, $group );
		}

		if ( empty( $sanitized_items ) ) {
			return true;
		}

		if ( self::is_object_cache_truly_functional() ) {
			if ( function_exists( 'wp_cache_set_multiple' ) ) {
				$results = wp_cache_set_multiple( $sanitized_items, self::PREFIX . $group, $ttl );
				return ! in_array( false, $results, true );
			}
			// Backend/core doesn't support batch set — fall back to individual calls.
			$all_ok = true;
			foreach ( $sanitized_items as $cache_key => $value ) {
				$all_ok = wp_cache_set( $cache_key, $value, self::PREFIX . $group, $ttl ) && $all_ok;
			}
			return $all_ok;
		}

		if ( self::use_transient_fallback( $group ) ) {
			$all_ok = true;
			foreach ( $sanitized_items as $cache_key => $value ) {
				$all_ok = set_transient( self::PREFIX . $cache_key, $value, $ttl ) && $all_ok;
			}
			return $all_ok;
		}

		return true;
	}

	/**
	 * Check runtime + persistent cache layers for an existing value (no callback generation).
	 *
	 * Callers MUST verify should_bypass() themselves before calling this — extracted so that
	 * get() and remember() can share the lookup logic without each re-checking should_bypass()
	 * (already confirmed false by the caller) on every invocation.
	 *
	 * @param string $group Cache group name.
	 * @param string|array $key Original cache key (pre-generate_key()), needed for is_array() check.
	 * @param string $cache_key Pre-generated cache key.
	 * @return mixed|null Cached value, or null if not found in any layer.
	 */
	private static function get_cached_value( $group, $key, $cache_key ) {
		// Check runtime cache
		$data = self::get_runtime( $cache_key );
		if ( $data !== null ) {
			return $data;
		}

		// Handle batch loading for persistent groups (transient fallback)
		if ( self::use_transient_fallback( $group ) ) {
			if ( is_array( $key ) ) {
				// For array keys, use get_multi directly
				$result = self::get_multi( $group, $key );
				if ( ! empty( $result ) ) {
					return $result;
				}
			} else {
				// For single keys, check transient
				$data = get_transient( self::PREFIX . $cache_key );
				if ( $data !== false ) {
					self::set_runtime( $cache_key, $data, $group );
					return $data;
				}
			}
		} elseif ( self::is_object_cache_truly_functional() ) {
			// Object cache for all data when available
			$data = wp_cache_get( $cache_key, self::PREFIX . $group );
			if ( $data !== false ) {
				self::set_runtime( $cache_key, $data, $group );
				return $data;
			}
		}

		return null;
	}

	/**
	 * Get a value from cache, optionally generating it via callback.
	 *
	 * @param string $group Cache group name.
	 * @param string|array $key Cache key.
	 * @param callable|null $callback Callback to generate value if not found.
	 * @param int|null $ttl Time to live in seconds (uses group default if null).
	 * @return mixed|null Cached value, callback result, or null.
	 */
	public static function get( $group, $key, $callback = null, $ttl = null ) {
		$cache_key = self::generate_key( $group, $key );

		// Early bypass check
		if ( self::should_bypass() ) {
			return is_callable( $callback ) ? $callback() : null;
		}

		$data = self::get_cached_value( $group, $key, $cache_key );
		if ( $data !== null ) {
			return $data;
		}

		// Generate new value if needed
		if ( ! is_callable( $callback ) ) {
			return null;
		}

		$data = $callback();
		if ( $data === null ) {
			return null;
		}

		// Store in runtime cache
		self::set_runtime( $cache_key, $data, $group );

		// Persist data if needed
		$ttl = $ttl ?? ( self::$default_ttls[ $group ] ?? self::$default_ttls['default'] );

		if ( self::is_object_cache_truly_functional() ) {
			wp_cache_set( $cache_key, $data, self::PREFIX . $group, $ttl );
		} elseif ( self::use_transient_fallback( $group ) ) {
			set_transient( self::PREFIX . $cache_key, $data, $ttl );
		}

		return $data;
	}

	/**
	 * Remember a value in cache with callback generation and locking.
	 *
	 * @param string $group Cache group name.
	 * @param string|array $key Cache key.
	 * @param callable $callback Callback to generate value if not found.
	 * @param int|null $ttl Time to live in seconds (uses group default if null).
	 * @return mixed|null Cached/generated value, or null if callback not callable.
	 */
	public static function remember( $group, $key, $callback, $ttl = null ) {
		// Early bypass check
		if ( self::should_bypass() ) {
			return is_callable( $callback ) ? $callback() : null;
		}

		// Check cache first. Uses the shared lookup helper directly (rather than self::get())
		// since should_bypass() was already confirmed false above — avoids re-evaluating it
		// a second time on every remember() call, which is the hottest cache entry point.
		$cache_key = self::generate_key( $group, $key );
		$value     = self::get_cached_value( $group, $key, $cache_key );
		if ( $value !== null ) {
			return $value;
		}

		// Stampede lock requires a real object-cache backend; skip when absent.
		if ( ! self::is_object_cache_truly_functional() ) {
			$value = $callback();
			if ( $value !== null ) {
				self::set( $group, $key, $value, $ttl );
			}
			return $value;
		}

		// Locking mechanism to prevent race conditions
		$lock_key     = self::generate_key( 'lock', $group . $key );
		$max_attempts = 3;
		$wait_time    = 50000; // 50ms initially

		for ( $attempt = 0; $attempt < $max_attempts; $attempt++ ) {
			// Try to get lock
			if ( wp_cache_add( $lock_key, 1, 'locks', self::LOCK_TTL ) ) {
				try {
					// Generate value with callback
					$value = $callback();
					if ( $value !== null ) {
						self::set( $group, $key, $value, $ttl );
					}
					return $value;
				} finally {
					// Always release lock
					wp_cache_delete( $lock_key, 'locks' );
				}
			}

			// Wait with exponential backoff
			if ( $attempt < $max_attempts - 1 ) {
				usleep( $wait_time );
				$wait_time *= 2; // Double wait time each attempt

				// Check if another process generated the value while waiting
				$value = self::get( $group, $key, null );
				if ( $value !== null ) {
					return $value;
				}
			}
		}

		// After all retries, generate the value anyway
		// Better to have multiple processes generate it than none
		$value = $callback();
		if ( $value !== null ) {
			self::set( $group, $key, $value, $ttl );
		}
		return $value;
	}

	/**
	 * Delete a cached item.
	 *
	 * @param string $group Cache group name.
	 * @param string|array $key Cache key.
	 * @return array Stats about what was cleared.
	 */
	public static function delete( $group, $key ) {
		return self::clear_group_with_dependencies( $group, $key, false );
	}

	/**
	 * Delete transients from database.
	 *
	 * @param string|null $prefix Optional prefix to target specific transients.
	 * @return array{transients: int} Stats with number of transients deleted.
	 */
	private static function delete_transients_from_db( $prefix = null ) {
		if ( frl_is_already_running( __METHOD__ ) ) {
			return array( 'transients' => 0 );
		}

		global $wpdb;
		$stats = array( 'transients' => 0 );

		try {
			// Ensure no pending results are lingering
			frl_flush_db();

			// Prepare patterns
			if ( $prefix ) {
				$like_pattern_transient = $wpdb->esc_like( '_transient_' . $prefix ) . '%';
				$like_pattern_timeout   = $wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%';
			} else {
				$like_pattern_transient = $wpdb->esc_like( '_transient_' ) . '%';
				$like_pattern_timeout   = $wpdb->esc_like( '_transient_timeout_' ) . '%';
			}

			// Exclude admin notices from deletion
			$exclude_pattern = $wpdb->esc_like( '_transient_' . FRL_PREFIX . '_admin_notices' );

			// Count transients first (only count main transients, not timeouts)
			$count_query = $wpdb->prepare(
				"SELECT COUNT(*) FROM $wpdb->options
                WHERE option_name LIKE %s
                AND option_name NOT LIKE %s",
				$like_pattern_transient,
				$exclude_pattern . '%'
			);

			$count = self::safe_db_get_var( $count_query, 0, 'count_transients_for_deletion' );

			// Delete transients and their timeouts
			$delete_query = $wpdb->prepare(
				"DELETE FROM $wpdb->options
                WHERE (option_name LIKE %s OR option_name LIKE %s)
                AND option_name NOT LIKE %s",
				$like_pattern_transient,
				$like_pattern_timeout,
				$exclude_pattern . '%'
			);

			// Use direct wpdb query for DELETE operations but with error checking
			frl_flush_db();
			$delete_result = $wpdb->query( $delete_query );

			if ( $wpdb->last_error ) {
				frl_log( 'FRL Cache DB Error in delete_transients: {error}', array( 'error' => $wpdb->last_error ) );
				frl_log( 'FRL Cache Delete Query: {query}', array( 'query' => $delete_query ) );
				return array( 'transients' => 0 );
			}

			// Store accurate count (only main transients, not timeouts)
			$stats['transients'] = (int) $count;

			return $stats;
		} catch ( Exception $e ) {
			frl_log( 'FRL Cache Exception in delete_transients_from_db: {error}', array( 'error' => $e->getMessage() ) );
			return array( 'transients' => 0 );
		} finally {
			frl_is_already_running( __METHOD__, true );
		}
	}

	/**
	 * Clear transients from database.
	 *
	 * @param string|null $group The cache group, or null for all.
	 * @return array Stats with transients cleared.
	 */
	public static function clear_transients( $group = null ) {
		if ( frl_is_already_running( __METHOD__ ) ) {
			return array( 'transients' => 0 );
		}

		$prefix = self::PREFIX;
		if ( $group ) {
			$prefix .= $group . '_';
		}

		$stats = self::delete_transients_from_db( $prefix );

		// Clear the runtime static cache used by frl_get_transient/frl_set_transient
		$runtime_transient_cache = &frl_transients_static_cache();
		if ( $group === null ) {
			$runtime_transient_cache     = array();
			$stats['runtime_transients'] = 'all';
			// Also clear 'all_transients' key cache
			// which stores the result of frl_get_all_plugin_transients().
			self::clear_group_with_dependencies( 'staticdata', 'all_transients' );
		} else {
			// For specific $group DB deletions, frl_transients_static_cache() (global static) is not cleared here.
			// Individual frl_delete_transient() calls manage their entries.
			$stats['runtime_transients'] = 'group ' . $group . ' DB clear; frl_transients_static_cache() not reset for subgroup';
		}

		frl_is_already_running( __METHOD__, true );
		return $stats;
	}

	/**
	 * Clear all website transients from the database.
	 *
	 * @return array{transients: int} Stats with transients cleared.
	 */
	public static function clear_all_website_transients() {
		$stats = self::delete_transients_from_db();

		return $stats;
	}

	/**
	 * Purge an entire cache group.
	 *
	 * @param string $group Cache group name.
	 * @return array Stats about what was cleared.
	 */
	public static function purge_group( $group ) {
		return self::clear_group_with_dependencies( $group, null, false );
	}

	/**
	 * Execute a callback while preserving the current user's authentication state.
	 *
	 * Some cache operations (particularly those that touch the object cache or
	 * options table) can interfere with WordPress's auth cookie validation.
	 * This wrapper snapshots the auth state before the operation and restores
	 * it afterward, preventing unexpected logout during cache maintenance.
	 *
	 * @param callable $callback Callback to execute with auth preservation.
	 * @return mixed The return value of the callback.
	 */
	private static function with_auth_preservation( callable $callback ) {
		// Use wp_get_current_user() directly (uncached) to snapshot the authentic
		// user from WordPress' own session, NOT frl_get_current_user() which pulls
		// from the plugin's persistent 'admin' cache group. Using the cached user
		// can cause cross-user session hijack: if the persistent cache returns a
		// stale WP_User object for the wrong user ID, wp_set_auth_cookie() below
		// would re-issue an auth cookie for that wrong user, permanently logging
		// User B in as User A (UID 1).
		$current_user    = wp_get_current_user();
		$current_user_id = $current_user->ID;
		$auth_cookie     = wp_parse_auth_cookie( '', 'logged_in' );

		$result = $callback();

		if ( $current_user_id && $auth_cookie ) {
			wp_set_auth_cookie( $current_user_id, true );
			wp_set_current_user( $current_user_id );
		}

		return $result;
	}

	/**
	 * Purge all cache groups.
	 *
	 * @return array|array{runtime: int, persistent: int, WordPress: int, key_cache: int, deferred: int, transients: int, object_cache: int, groups: array} Cache clearing stats, or empty array if already running.
	 */
	public static function purge_all() {
		if ( frl_is_already_running( __METHOD__ ) ) {
			return array();
		}

		return self::with_auth_preservation(
			function () {
				// Reset the cleared groups tracker for this batch operation
				self::$groups_cleared = array();

				$stats = array(
					'runtime'      => 0,
					'persistent'   => 0,
					'wordpress'    => 0,
					'key_cache'    => count( self::$key_cache ),
					'deferred'     => count( self::$deferred_writes ),
					'transients'   => 0,     // Add missing key expected by logged-user.php
					'object_cache' => 0,    // Add missing key expected by logged-user.php
				);

				// 1. Start with a clean slate - clear all runtime variables
				self::$runtime_cache       = array();
				self::$key_cache           = array();
				self::$lru['access_order'] = array();
				self::$deferred_writes     = array();
				self::$loaded_groups       = array(); // Reset loaded groups tracking

				// Optimization: Perform a single batch transient deletion for all plugin transients
				// if object cache is not functional, avoiding multiple DB calls in the loop.
				if ( ! self::is_object_cache_truly_functional() ) {
					$transient_stats                = self::delete_transients_from_db( self::PREFIX );
					$stats['transients']            = $transient_stats['transients'];
					self::$transients_batch_deleted = true;
				}

				// 3. Flush each cache group using the comprehensive method
				// This ensures consistent behavior across all clearing operations
				foreach ( array_keys( self::$default_ttls ) as $group ) {
					// Skip TTL fallback key — not a real cache group.
					if ( $group === 'default' ) {
						continue;
					}
					$group_stats = self::clear_group_with_dependencies( $group, null, false );

					// Aggregate statistics
					$stats['runtime'] += $group_stats['runtime'];

					// If object cache is functional, aggregate persistent stats as object_cache
					if ( self::is_object_cache_truly_functional() ) {
						$stats['persistent']   += $group_stats['persistent'];
						$stats['object_cache'] += $group_stats['persistent'];
					} else {
						// If using transients, the persistent stats from the loop might be 0
						// because we already cleared them in batch, or they might be redundant.
						// We rely on the batch count above for 'transients' total.
						$stats['persistent'] += $group_stats['persistent'];
					}

					$stats['wordpress'] += $group_stats['wordpress'];

					// Store group-specific stats too
					$stats['groups'][ $group ] = $group_stats;
				}

				// Reset batch-delete flag so subsequent calls (e.g., clear_transients for a specific group)
				// are not incorrectly skipped.
				self::$transients_batch_deleted = false;

				frl_is_already_running( __METHOD__, true );
				return $stats;
			}
		);
	}

	/**
	 * Hydrate a result array from the runtime cache for all known keys of a group.
	 *
	 * Shared by get_multi()'s "already loaded" and "object cache, keys unknown"
	 * paths, which both need to rebuild a [key => value] map from the group's
	 * tracked cache keys and whatever currently lives in the runtime cache.
	 *
	 * @param string $group Cache group name.
	 * @return array Associative array of found values keyed by the unprefixed key.
	 */
	private static function hydrate_result_from_group_keys( string $group ): array {
		$result           = array();
		$group_prefix     = $group . '_';
		$prefix_len       = strlen( $group_prefix );
		$group_cache_keys = self::$group_keys[ $group ] ?? array();

		foreach ( array_keys( $group_cache_keys ) as $cache_key ) {
			if ( isset( self::$runtime_cache[ $cache_key ] ) ) {
				$key            = substr( $cache_key, $prefix_len );
				$result[ $key ] = self::$runtime_cache[ $cache_key ];
			}
		}

		return $result;
	}

	/**
	 * Get multiple values from cache.
	 *
	 * @param string $group Cache group name.
	 * @param array|null $keys Array of keys to fetch, or null to fetch all keys in group.
	 * @param bool $return_values Whether to build and return the values array (default true).
	 * @return array|null Associative array of found values, or null if $return_values is false.
	 */
	public static function get_multi( $group, $keys = null, $return_values = true ) {
		if ( self::should_bypass() ) {
			return $return_values ? array() : null;
		}

		$result = array();

		// If $keys is null, retrieve all keys for the group
		if ( $keys === null ) {
			// Check if we've already loaded all keys for this group in this request
			if ( isset( self::$loaded_groups[ $group ] ) ) {
				if ( $return_values ) {
					$result = self::hydrate_result_from_group_keys( $group );
				}
				return $return_values ? $result : null;
			}

			// For persistent groups
			if ( isset( self::$persistent_groups_map[ $group ] ) ) {
				// Transient case - can query the database
				if ( ! self::is_object_cache_truly_functional() ) {
					global $wpdb;
					$prefix         = '_transient_' . self::PREFIX . $group . '_';
					$timeout_prefix = '_transient_timeout_' . self::PREFIX . $group . '_';

					// Ensure clean database state
					frl_flush_db();

					// Query all transients for this group, INCLUDING TIMEOUT VALUES
					$query = $wpdb->prepare(
						"SELECT option_name, option_value
                         FROM $wpdb->options
                         WHERE option_name LIKE %s OR option_name LIKE %s",
						$wpdb->esc_like( $prefix ) . '%',
						$wpdb->esc_like( $timeout_prefix ) . '%'
					);

						$db_results = self::safe_db_query( $query, array(), 'load_all_group_transients' );

						// Prepare arrays for both values and WordPress's option cache
						$wp_cache   = array();
						$transients = array();
						$timeouts   = array();

						// Separate transient values from timeout values
					foreach ( $db_results as $row ) {
						// Add all results to WordPress option cache
						$wp_cache[ $row->option_name ] = $row->option_value;

						// Organize by value or timeout
						if ( str_starts_with( $row->option_name, $timeout_prefix ) ) {
							$key              = str_replace( $timeout_prefix, '', $row->option_name );
							$timeouts[ $key ] = $row->option_value;
						} else {
							$key                = str_replace( $prefix, '', $row->option_name );
							$transients[ $key ] = maybe_unserialize( $row->option_value );
						}
					}

						// Store values in our result (not including timeout values)
					if ( $return_values ) {
						foreach ( $transients as $key => $value ) {
							$result[ $key ] = $value;

							// Store in runtime cache - matching the pattern from get()
							$cache_key = self::generate_key( $group, $key );
							self::set_runtime( $cache_key, $value, $group );
						}
					} else {
						// Still populate runtime cache if not returning values
						foreach ( $transients as $key => $value ) {
							$cache_key = self::generate_key( $group, $key );
							self::set_runtime( $cache_key, $value, $group );
						}
					}

						// Inject into WordPress option cache - both values and timeouts
					if ( ! empty( $wp_cache ) ) {
						wp_cache_add_multiple( $wp_cache, 'options' );
					}

						// Mark this group as fully loaded
						self::$loaded_groups[ $group ] = true;
				} else {
					// Object cache case - we still need to initialize runtime cache
					// Since we can't get all keys efficiently from object cache,
					// we rely on the runtime cache for any existing entries
					if ( $return_values ) {
						$result = self::hydrate_result_from_group_keys( $group );
					}

					// Still mark as loaded to prevent repeated access attempts
					self::$loaded_groups[ $group ] = true;
				}
			}

			return $return_values ? $result : null;
		}

		// Original behavior for non-null $keys
		if ( empty( $keys ) ) {
			return $return_values ? array() : null;
		}

		// --- Step 1: Pre-generate keys and check runtime cache ---
		$missing_keys_map = array(); // [original_key => generated_cache_key]
		foreach ( $keys as $key ) {
			$cache_key = self::generate_key( $group, $key );
			$data      = self::get_runtime( $cache_key );

			if ( $data !== null ) {
				if ( $return_values ) {
					$result[ $key ] = $data;
				}
			} else {
				$missing_keys_map[ $key ] = $cache_key;
			}
		}

		if ( empty( $missing_keys_map ) ) {
			return $return_values ? $result : null;
		}

		// --- Step 2: Batch load from Object Cache if functional ---
		if ( self::is_object_cache_truly_functional() ) {
			$generated_keys = array_values( $missing_keys_map );
			$wp_cache_group = self::PREFIX . $group;

			// Use wp_cache_get_multiple for modern WP, fallback to sequential for older versions
			$batch_results = function_exists( 'wp_cache_get_multiple' )
				? wp_cache_get_multiple( $generated_keys, $wp_cache_group )
				: array_combine( $generated_keys, array_map( fn( $k ) => wp_cache_get( $k, $wp_cache_group ), $generated_keys ) );

			foreach ( $missing_keys_map as $original_key => $cache_key ) {
				$data = $batch_results[ $cache_key ] ?? false;
				if ( $data !== false ) {
					if ( $return_values ) {
						$result[ $original_key ] = $data;
					}
					self::set_runtime( $cache_key, $data, $group );
					unset( $missing_keys_map[ $original_key ] );
				}
			}
		}

		// --- Step 3: Batch load from Transients (with chunking) if fallback enabled ---
		if ( ! empty( $missing_keys_map ) && self::use_transient_fallback( $group ) ) {
			global $wpdb;
			frl_flush_db();

			$missing_original_keys = array_keys( $missing_keys_map );
			$chunks                = array_chunk( $missing_original_keys, 100 );

			foreach ( $chunks as $chunk ) {
				$all_db_keys          = array();
				$chunk_generated_keys = array();

				foreach ( $chunk as $original_key ) {
					$cache_key                             = $missing_keys_map[ $original_key ];
					$chunk_generated_keys[ $original_key ] = $cache_key;
					$all_db_keys[]                         = '_transient_' . self::PREFIX . $cache_key;
					$all_db_keys[]                         = '_transient_timeout_' . self::PREFIX . $cache_key;
				}

				$placeholders = implode( ',', array_fill( 0, count( $all_db_keys ), '%s' ) );
				$query        = $wpdb->prepare(
					"SELECT option_name, option_value FROM $wpdb->options WHERE option_name IN ($placeholders)",
					$all_db_keys
				);

				$db_results         = self::safe_db_query( $query, array(), 'batch_load_transients', OBJECT_K );
				$wp_cache_to_inject = array();

				foreach ( $chunk as $original_key ) {
					$cache_key = $chunk_generated_keys[ $original_key ];
					$t_key     = '_transient_' . self::PREFIX . $cache_key;
					$to_key    = '_transient_timeout_' . self::PREFIX . $cache_key;

					if ( isset( $db_results[ $t_key ] ) ) {
						$value = maybe_unserialize( $db_results[ $t_key ]->option_value );
						if ( $return_values ) {
							$result[ $original_key ] = $value;
						}
						self::set_runtime( $cache_key, $value, $group );
						$wp_cache_to_inject[ $t_key ] = $db_results[ $t_key ]->option_value;

						if ( isset( $db_results[ $to_key ] ) ) {
							$wp_cache_to_inject[ $to_key ] = $db_results[ $to_key ]->option_value;
						}
					}
				}

				if ( ! empty( $wp_cache_to_inject ) ) {
					wp_cache_add_multiple( $wp_cache_to_inject, 'options' );
				}
			}
		}

		return $return_values ? $result : null;
	}

	/**
	 * Preload multiple cache keys without returning their values.
	 *
	 * @param string $group Cache group name.
	 * @param array|null $keys Array of keys to preload, or null to preload all.
	 * @return void
	 */
	public static function preload_multi( $group, $keys = null ) {
		self::get_multi( $group, $keys, false );
	}

	/**
	 * Send browser cache control headers.
	 *
	 * @return void
	 */
	public static function clear_browser_cache() {
		if ( ! headers_sent() ) {
			// Clear only cache and storage, not cookies
			header( 'Clear-Site-Data: "cache", "storage"' );
			header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private' );
			header( 'Pragma: no-cache' );
			header( 'Expires: Wed, 11 Jan 1984 05:00:00 GMT' );
		}
	}

	/**
	 * Clear cache group with its dependencies.
	 *
	 * @param string $group Cache group name.
	 * @param string|null $key Optional specific key to clear (null clears entire group).
	 * @param bool $include_dependencies Whether to clear dependent caches too.
	 * @return array{runtime: int, persistent: int, WordPress: int, dependencies: array} Stats about what was cleared.
	 */
	public static function clear_group_with_dependencies(
		string $group,
		?string $key = null,
		bool $include_dependencies = true
	) {
		// Warn if group is not recognized in any configuration array
		if ( ! isset( self::$default_ttls[ $group ] ) && ! isset( self::$persistent_groups_map[ $group ] ) ) {
			if ( function_exists( 'frl_log' ) ) {
				frl_log( "Cache: unrecognized group '{group}' — using defaults", array( 'group' => $group ) );
			}
		}

		// Only track full group clears (not single-key clears)
		if ( $key === null ) {
			if ( isset( self::$groups_cleared[ $group ] ) ) {
				// Already cleared this group in this request
				return array(
					'runtime'      => 0,
					'persistent'   => 0,
					'wordpress'    => 0,
					'dependencies' => array(),
				);
			}
			self::$groups_cleared[ $group ] = true;
		}

		$stats = array(
			'runtime'      => 0,
			'persistent'   => 0,
			'wordpress'    => 0,
			'dependencies' => array(),
		);

		// 1. Clear persistent storage (transients or object cache)
		if ( $key !== null ) {
			// Clear specific key
			$cache_key = self::generate_key( $group, $key );
			// Clear from all storage mechanisms
			if ( self::is_object_cache_truly_functional() ) {
				wp_cache_delete( $cache_key, self::PREFIX . $group );
				++$stats['persistent'];
			} elseif ( self::use_transient_fallback( $group ) ) {
				delete_transient( self::PREFIX . $cache_key );
				++$stats['persistent'];
			}

			// Clear from runtime cache
			if ( isset( self::$runtime_cache[ $cache_key ] ) ) {
				self::remove_runtime_item( $cache_key, $group );
				++$stats['runtime'];
			}
		} else {
			// Clear entire group
			$stats['persistent'] = self::purge_group_storage( $group );
			$stats['runtime']   += self::purge_group_runtime( $group );

			// Also clear the group's "fully loaded" status
			if ( isset( self::$loaded_groups[ $group ] ) ) {
				unset( self::$loaded_groups[ $group ] );
				++$stats['runtime'];
			}
		}

		// 2. Handle WordPress built-in caches (for common cases)
		if ( $group === 'options' ) {
			self::reset_options_caches( $stats );
		}

		// 3. Handle dependencies only for full-group clears
		if ( $key === null && $include_dependencies && isset( self::$cache_dependencies[ $group ] ) ) {
			foreach ( self::$cache_dependencies[ $group ] as $dependent_group ) {
				$stats['dependencies'][ $dependent_group ] = self::clear_group_with_dependencies(
					$dependent_group,
					null,
					true
				);
			}
		}

		// 4. Handle browser cache for specific groups
		if ( $key === null && isset( self::$browser_groups_map[ $group ] ) ) {
			self::clear_browser_cache();
		}

		return $stats;
	}

	/**
	 * Clear persistent storage for a group.
	 *
	 * @param string $group Cache group name.
	 * @return int Number of items cleared.
	 */
	private static function purge_group_storage( $group ) {
		$count = 0;

		if ( self::is_object_cache_truly_functional() ) {
			$wp_cache_group = self::PREFIX . $group;

			// Canary check (diagnostic only, never alters $count/behavior): detects
			// drop-ins where wp_cache_flush_group() is a no-op. Runs once per
			// request, not once per group — backend support doesn't vary per
			// group, so re-checking on every purge_all() group would add
			// 30-45 redundant round-trips for no extra signal.
			static $canary_checked = false;
			$run_canary_check      = ! $canary_checked;
			$canary_key            = '__frl_flush_canary__';

			if ( $run_canary_check ) {
				$canary_checked = true;
				wp_cache_set( $canary_key, 1, $wp_cache_group, MINUTE_IN_SECONDS );
			}

			// Use WP's group flush if available
			if ( function_exists( 'wp_cache_flush_group' ) ) {
				$result = wp_cache_flush_group( $wp_cache_group );
				$count  = is_numeric( $result ) ? $result : 1;
			} else {
				// No group-level flush available; mark as cleared (count approximate)
				$count = 1; // Indicate that *something* was likely cleared, though not precisely countable
			}

			if ( $run_canary_check && wp_cache_get( $canary_key, $wp_cache_group ) !== false ) {
				if ( function_exists( 'frl_log' ) ) {
					$provider = self::get_provider_details();
					frl_log(
						'Cache group flush appears non-functional for the active object-cache backend — group "{group}" may still contain stale data. Backend: {backend}',
						array(
							'group'   => $group,
							'backend' => $provider['label'] ?? 'unknown',
						),
						false
					);
				}
				// Best-effort cleanup of our own canary so it doesn't linger.
				wp_cache_delete( $canary_key, $wp_cache_group );
			}
		} elseif ( self::use_transient_fallback( $group ) ) {
			// Skip per-group transient deletion if a batch delete already ran this request
			if ( self::$transients_batch_deleted ) {
				return 0;
			}
			// Clear transients
			$result = self::clear_transients( $group );
			$count  = $result['transients'];
		}

		return $count;
	}

	/**
	 * Clear runtime cache for a group.
	 *
	 * @param string $group Cache group name.
	 * @return int Number of items cleared.
	 */
	private static function purge_group_runtime( $group ) {
		if ( ! isset( self::$group_keys[ $group ] ) ) {
			return 0;
		}

		$count = count( self::$group_keys[ $group ] );
		$keys  = array_keys( self::$group_keys[ $group ] );

		foreach ( $keys as $cache_key ) {
			// Unset from main storage
			unset( self::$runtime_cache[ $cache_key ] );

			// Unset from LRU tracking
			unset( self::$lru['access_order'][ $cache_key ] );
		}

		// Remove the entire group index at once
		unset( self::$group_keys[ $group ] );

		return $count;
	}

	/**
	 * Reset WordPress and plugin option caches.
	 *
	 * @param array &$stats Stats array to update with cleared counts.
	 * @return void
	 */
	private static function reset_options_caches( &$stats ) {
		// If already reset in this request, skip redundant operations.
		// __CLASS__ is valid here (method of this class). If switching to
		// __METHOD__, first confirm no other method reuses __CLASS__ as a guard key.
		if ( frl_is_already_running( __CLASS__ ) ) {
			return;
		}

		// Reset the static cache in frl_get_option only for options group.
		// Intentionally NOT clearing wp_cache_delete('alloptions', 'options')
		// because that invalidates WordPress's entire autoloaded-options cache,
		// forcing wp_load_alloptions() to re-read every option from wp_options
		// on the very next page load — a site-wide performance hit.
		// Our plugin's options are cached in the frl_cache_* Redis group (cleared
		// above by clear_group_with_dependencies), and frl_get_option has its own
		// static cache reset below. WordPress's alloptions cache belongs to WP
		// core; clearing it is unnecessary and harmful.
		++$stats['wordpress'];

		// Reset the static cache in frl_get_option only for options group
		frl_get_option( '__reset__' );
		++$stats['runtime'];

		// No pre_option_frl_* filter removal needed here — frl_update_option()
		// and frl_delete_option() already call remove_all_filters() before each
		// write, and frl_get_plugin_options_db() reads directly from $wpdb,
		// bypassing pre_option_* filters entirely.

		// If reset_options_caches are called multiple times
		// for the 'options' group in a single request, the operations
		// wp_cache_delete('alloptions', 'options') and frl_get_option('__reset__') are idempotent and relatively cheap, with minimal impact.
		frl_is_already_running( __CLASS__, true );
	}

	/**
	 * Atomic cache group clearing with rollback support.
	 *
	 * @param string $group Cache group to clear.
	 * @param array|null $specific_keys Optional array of specific keys to clear.
	 * @return array{group: string, runtime_cleared: int, persistent_cleared: int, transaction_used: bool, success: bool} Operation statistics.
	 */
	public static function atomic_clear_group( $group, ?array $specific_keys = null ) {
		$stats = array(
			'group'              => $group,
			'runtime_cleared'    => 0,
			'persistent_cleared' => 0,
			'transaction_used'   => false,
			'success'            => false,
		);

		// For object cache, use existing methods (no transaction needed)
		if ( self::is_object_cache_truly_functional() ) {
			$cg_result                   = self::clear_group_with_dependencies( $group, null, false );
			$stats['runtime_cleared']    = $cg_result['runtime'] ?? 0;
			$stats['persistent_cleared'] = $cg_result['persistent'] ?? 0;
			$stats['success']            = true;
			$stats['group']              = $group;
			return $stats;
		}

		// For transients, use transaction-based clearing
		if ( isset( self::$persistent_groups_map[ $group ] ) ) {
			$stats['transaction_used'] = true;

			$result = self::execute_with_transaction(
				function () use ( $group, $specific_keys ) {
					global $wpdb;

					if ( $specific_keys !== null ) {
						// Clear specific keys
						$transient_keys = array();
						$timeout_keys   = array();

						foreach ( $specific_keys as $key ) {
							$cache_key        = self::generate_key( $group, $key );
							$transient_keys[] = '_transient_' . self::PREFIX . $cache_key;
							$timeout_keys[]   = '_transient_timeout_' . self::PREFIX . $cache_key;
						}

						$all_keys = array_merge( $transient_keys, $timeout_keys );
						return self::safe_batch_delete_transients( $all_keys );
					} else {
						// Clear entire group
						$prefix         = '_transient_' . self::PREFIX . $group . '_';
						$timeout_prefix = '_transient_timeout_' . self::PREFIX . $group . '_';

						// Get all keys for this group
						$query = $wpdb->prepare(
							"SELECT option_name FROM $wpdb->options
                         WHERE option_name LIKE %s OR option_name LIKE %s",
							$wpdb->esc_like( $prefix ) . '%',
							$wpdb->esc_like( $timeout_prefix ) . '%'
						);

						frl_flush_db(); // Ensure clean database state
						$keys_to_delete = $wpdb->get_col( $query );

						if ( ! empty( $keys_to_delete ) ) {
							return self::safe_batch_delete_transients( $keys_to_delete );
						}

						return array( 'deleted_count' => 0 );
					}
				},
				'atomic_clear_group_' . $group
			);

			if ( $result !== false ) {
				$stats['persistent_cleared'] = $result['deleted_count'] ?? 0;
				$stats['success']            = true;
			}
		}

		// Clear runtime cache regardless of transaction result
		$stats['runtime_cleared'] = self::purge_group_runtime( $group );

		// Set success for runtime-only groups
		if ( ! isset( self::$persistent_groups_map[ $group ] ) ) {
			$stats['success'] = true;
		}

		return $stats;
	}

	/**
	 * Safe database query wrapper with error handling and fallback.
	 *
	 * @param string $query The SQL query to execute.
	 * @param mixed $fallback Fallback value to return on error.
	 * @param string $operation Description of operation for logging.
	 * @param string $output Optional output type (OBJECT, ARRAY_A, ARRAY_N, OBJECT_K).
	 * @return mixed Query results or fallback on error.
	 */
	public static function safe_db_query( $query, $fallback = array(), $operation = 'cache_query', $output = OBJECT ) {
		global $wpdb;

		// Execute query with output type (callers handle frl_flush_db() strategically)
		$results = $wpdb->get_results( $query, $output );

		// Check for database errors
		if ( $wpdb->last_error ) {
			frl_log(
				'FRL Cache DB Error in {operation}: {error}',
				array(
					'operation' => $operation,
					'error'     => $wpdb->last_error,
				)
			);
			frl_log( 'FRL Cache Query: {query}', array( 'query' => $query ) );
			return $fallback;
		}

		// Check for null results (connection issues, etc.)
		if ( $results === null ) {
			frl_log( 'FRL Cache DB Warning in {operation}: Query returned null', array( 'operation' => $operation ) );
			return $fallback;
		}

		return $results;
	}

	/**
	 * Safe database variable query wrapper.
	 *
	 * @param string $query The SQL query to execute.
	 * @param mixed $fallback Fallback value to return on error.
	 * @param string $operation Description of operation for logging.
	 * @return mixed Query result or fallback on error.
	 */
	public static function safe_db_get_var( $query, $fallback = 0, $operation = 'cache_var_query' ) {
		global $wpdb;

		// Execute query (callers handle frl_flush_db() strategically)
		$result = $wpdb->get_var( $query );

		// Check for database errors
		if ( $wpdb->last_error ) {
			frl_log(
				'FRL Cache DB Error in {operation}: {error}',
				array(
					'operation' => $operation,
					'error'     => $wpdb->last_error,
				)
			);
			frl_log( 'FRL Cache Query: {query}', array( 'query' => $query ) );
			return $fallback;
		}

		return $result;
	}

	/**
	 * Purge cache lightly, excluding heavy groups and dependencies.
	 *
	 * @return array{runtime: int, persistent: int, WordPress: int, skipped_groups: array, groups: array} Cache clearing stats.
	 */
	public static function purge_light() {
		if ( frl_is_already_running( __METHOD__ ) ) {
			return array();
		}

		// Reset the cleared groups tracker for this batch operation
		self::$groups_cleared = array();

		$stats = array(
			'runtime'        => 0,
			'persistent'     => 0,
			'wordpress'      => 0,
			'skipped_groups' => FRL_CACHE_HEAVY_GROUPS,
		);

		// Get all defined groups from TTL config
		$all_groups = array_keys( self::$default_ttls );

		foreach ( $all_groups as $group ) {
			// Skip heavy groups
			if ( isset( self::$heavy_groups_map[ $group ] ) ) {
				continue;
			}

			// Clear group WITHOUT dependencies
			$group_stats = self::clear_group_with_dependencies( $group, null, false );

			// Aggregate statistics
			$stats['runtime']    += $group_stats['runtime'];
			$stats['persistent'] += $group_stats['persistent'];
			$stats['wordpress']  += $group_stats['wordpress'];

			// Store group-specific stats too
			$stats['groups'][ $group ] = $group_stats;
		}

		// Explicitly clear key/deferred caches if needed, but not full runtime
		self::$key_cache       = array();
		self::$deferred_writes = array();

		frl_is_already_running( __METHOD__, true );
		return $stats;
	}

	/**
	 * Get runtime cache data for display purposes.
	 *
	 * @return array{runtime_cache: array, key_cache: array, group_keys: array, lru: array, loaded_groups: array, groups_cleared: array, max_runtime_items: int} Runtime cache data.
	 */
	public static function get_runtime_cache_data() {
		// Maintain UI compatibility by converting associative access_order back to indexed keys
		$lru_for_ui = self::$lru;
		if ( is_array( $lru_for_ui['access_order'] ) ) {
			$lru_for_ui['access_order'] = array_keys( $lru_for_ui['access_order'] );
		}

		return array(
			'runtime_cache'     => self::$runtime_cache,
			'key_cache'         => self::$key_cache,
			'group_keys'        => self::$group_keys,
			'lru'               => $lru_for_ui,
			'loaded_groups'     => self::$loaded_groups,
			'groups_cleared'    => self::$groups_cleared,
			'max_runtime_items' => self::$max_runtime_items,
		);
	}

	/**
	 * Perform the most comprehensive cache reset possible by this plugin.
	 *
	 * Scoped entirely to this plugin's own data — it never touches transients
	 * belonging to other plugins/themes. Site-wide transient cleanup remains
	 * available separately via the dedicated "Clear Website Transients" action
	 * (Frl_Cache_Manager::clear_all_website_transients()), which the admin can
	 * invoke explicitly and independently when a full-site transient purge is
	 * actually intended.
	 *
	 * @return array{plugin_internal_purge: array, plugin_transients_deleted: array, browser_cache_headers_sent_via_purge_all: string} Statistics about the cache clearing operations.
	 */
	public static function hard_cache_reset() {
		$stats = array();

		// 1. Purge everything the Cache Manager directly controls.
		// This handles its runtime state, specific WP options caches,
		// browser cache (per group config), and its own persistent items.
		$stats['plugin_internal_purge'] = self::purge_all();

		// 2. Clear this plugin's own transients only (prefix-scoped), not
		// every transient on the site. Other plugins'/themes' transients are
		// intentionally left untouched — use the separate "Clear Website
		// Transients" action for a full-site purge.
		$stats['plugin_transients_deleted'] = self::clear_transients();

		$stats['browser_cache_headers_sent_via_purge_all'] = 'dependent_on_group_config';

		// 3. Action hook for other plugins/themes to hook into.
		// Use the defined PREFIX to make the hook unique and discoverable.
		do_action( self::PREFIX . '_after_hard_cache_reset', $stats );

		return $stats;
	}

	/**
		* Processes and flushes deferred cache writes during the shutdown sequence.
		*
		* Merges duplicate writes and handles errors by re-queuing failed items.
		*
		* @return void
		*/
	public static function process_deferred_writes(): void {
		frl_flush_db();

		$writes = frl_cache_get_deferred_writes();
		if ( empty( $writes ) ) {
			return;
		}

		// Merge duplicate writes: last write wins
		$merged = array();
		foreach ( $writes as $group => $items ) {
			foreach ( $items as $key => $value ) {
				$merged[ $group ][ $key ] = $value;
			}
		}

		// Process merged writes as one batch per group (via frl_cache_set_multi()) instead of
		// N individual frl_cache_set() calls — collapses N object-cache round-trips (or N
		// set_transient() DB writes) per group into a single batched operation.
		// On failure, the whole group's items are re-queued for the next cycle; re-queuing a
		// few already-succeeded keys alongside the failed one is harmless since cache writes
		// are idempotent, and avoids requiring per-key granularity from the batch call.
		$failed_items = array();
		foreach ( $merged as $group => $items ) {
			try {
				if ( ! frl_cache_set_multi( $group, $items ) ) {
					$failed_items[ $group ] = $items;
				}
			} catch ( Exception $e ) {
				frl_log(
					'Error processing deferred write batch for group {group}: {error}',
					array(
						'group' => $group,
						'error' => $e->getMessage(),
					)
				);
				$failed_items[ $group ] = $items;
			}
		}

		// Re-queue failed items for the next cycle
		if ( ! empty( $failed_items ) ) {
			foreach ( $failed_items as $group => $items ) {
				foreach ( $items as $key => $value ) {
					frl_cache_add_deferred_write( $group, $key, $value );
				}
			}
		}

		frl_cache_clear_deferred_writes();
		frl_flush_db();
	}
}
