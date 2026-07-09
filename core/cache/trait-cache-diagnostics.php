<?php
declare(strict_types=1);
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Object-cache provider detection and config introspection for Frl_Cache_Manager.
 * Extracted from Frl_Cache_Manager for maintainability. Coupled to host class
 * members below (not reusable elsewhere).
 *
 * @method static bool _is_plugin_globally_active( string $plugin_path )
 * @property static ?array $cached_provider_details
 * @property static array $default_ttls
 * @property static array $persistent_groups
 * @property static array $cache_dependencies
 * @package Fralenuvole
 * @since 5.7.4.5
 */
trait Frl_Cache_Diagnostics_Trait {

	/**
	 * Detect the active object-cache provider (slug, label, functional status).
	 *
	 * @return array{slug: string, label: string, is_effectively_functional: bool, backend_class_override: string|null, is_dropin: bool, original_class_name: string|null} Provider details.
	 */
	public static function get_provider_details() {
		if ( self::$cached_provider_details !== null ) {
			return self::$cached_provider_details;
		}

		// Use core WP transient to avoid recursion with self::get()
		// v2: live connectivity test added for Kinsta-style WP_Object_Cache wrappers.
		$transient_key = self::PREFIX . 'object_cache_provider_details_v2';
		$cached        = get_transient( $transient_key );
		if ( $cached !== false && is_array( $cached ) ) {
			self::$cached_provider_details = $cached;
			return $cached;
		}

		global $wp_object_cache;
		$cache_class_name = ( is_object( $wp_object_cache ) ) ? get_class( $wp_object_cache ) : null;

		$provider_info = array(
			'slug'                      => 'unknown_dropin',
			'label'                     => 'Unknown Drop-in',
			'is_effectively_functional' => false,
			'backend_class_override'    => null,
			'is_dropin'                 => false,
			'original_class_name'       => $cache_class_name,
		);

		$has_drop_in                = wp_using_ext_object_cache();
		$provider_info['is_dropin'] = $has_drop_in;

		// Set early to prevent infinite recursion: _is_plugin_globally_active()
		// → frl_is_thirdparty_plugin_active() → frl_cache_remember() → get()
		// → is_object_cache_truly_functional() → get_provider_details()
		self::$cached_provider_details = $provider_info;

		if ( ! $has_drop_in ) {
			$provider_info['slug']  = 'transients';
			$provider_info['label'] = 'WordPress Transients';
			return self::finalize_provider_details( $provider_info, $transient_key );
		}

		// --- Read object-cache.php content ONCE ---
		$file_content_for_check = '';
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$object_cache_file_path = WP_CONTENT_DIR . '/object-cache.php';
			if ( file_exists( $object_cache_file_path ) ) {
				// Read up to 2KB, which should be plenty for identification.
				$file_content_for_check = @file_get_contents( $object_cache_file_path, false, null, 0, 2048 );
			}
		}
		$file_content_lower = strtolower( $file_content_for_check ?: '' );

		// Plugin paths
		$docket_cache_plugin_main_file = 'docket-cache/docket-cache.php';
		$litespeed_plugin_main_file    = 'litespeed-cache/litespeed-cache.php';

		// Litespeed Cache Detection
		$is_litespeed_class = $cache_class_name && str_contains( strtolower( $cache_class_name ), 'litespeed' );
		$is_litespeed_file  = str_contains( $file_content_lower, 'litespeed' );

		if ( $is_litespeed_class || $is_litespeed_file ) {
			$provider_info['label'] = 'Litespeed Cache';
			$is_lscwp_plugin_active = self::_is_plugin_globally_active( $litespeed_plugin_main_file );

			if ( defined( 'LSCWP_V' ) && $is_lscwp_plugin_active ) {
				$provider_info['slug']                      = 'litespeed_active';
				$provider_info['is_effectively_functional'] = true;
			} else {
				$provider_info['slug']                      = 'litespeed_inactive_dropin';
				$provider_info['is_effectively_functional'] = false;
			}
			return self::finalize_provider_details( $provider_info, $transient_key );
		}

		// Docket Cache Detection
		$docket_method_found = ( is_object( $wp_object_cache ) && method_exists( $wp_object_cache, 'dc_save' ) ) ||
			( isset( $wp_object_cache->_object_cache ) && is_object( $wp_object_cache->_object_cache ) && method_exists( $wp_object_cache->_object_cache, 'dc_save' ) );
		$is_docket_file      = str_contains( $file_content_lower, 'docket cache' );

		if ( $docket_method_found || $is_docket_file ) {
			$provider_info['label'] = 'Docket Cache';
			if ( isset( $wp_object_cache->_object_cache ) && is_object( $wp_object_cache->_object_cache ) && $cache_class_name === 'WP_Object_Cache' ) {
				$provider_info['backend_class_override'] = get_class( $wp_object_cache->_object_cache );
			}

			$is_docket_plugin_active     = self::_is_plugin_globally_active( $docket_cache_plugin_main_file );
			$is_docket_disabled_by_const = ( defined( 'DOCKET_CACHE_DISABLED' ) && constant( 'DOCKET_CACHE_DISABLED' ) === true );

			if ( $is_docket_disabled_by_const ) {
				$provider_info['slug']                      = 'docket_cache_force_disabled';
				$provider_info['is_effectively_functional'] = false;
			} elseif ( $is_docket_plugin_active ) {
				// If methods are missing but plugin is active, it could be a broken state
				$provider_info['slug']                      = $docket_method_found ? 'docket_cache_active' : 'docket_cache_broken';
				$provider_info['is_effectively_functional'] = $docket_method_found;
			} else {
				// Plugin is not active, so it's an inactive drop-in regardless of methods
				$provider_info['slug']                      = 'docket_cache_inactive_dropin';
				$provider_info['is_effectively_functional'] = false;
			}
			return self::finalize_provider_details( $provider_info, $transient_key );
		}

		// Redis Detection
		if ( $cache_class_name && str_contains( strtolower( $cache_class_name ), 'redis' ) ) {
			$provider_info['slug']                      = 'redis_active';
			$provider_info['label']                     = 'Redis';
			$provider_info['is_effectively_functional'] = true;
			return self::finalize_provider_details( $provider_info, $transient_key );
		}

		// Memcached Detection
		if ( $cache_class_name && str_contains( strtolower( $cache_class_name ), 'memcached' ) ) {
			$provider_info['slug']                      = 'memcached_active';
			$provider_info['label']                     = 'Memcached';
			$provider_info['is_effectively_functional'] = true;
			return self::finalize_provider_details( $provider_info, $transient_key );
		}

		// Final Fallbacks for generic or unknown drop-ins
		// This part runs only if no specific provider was detected above.
		if ( $cache_class_name === 'WP_Object_Cache' ) {
			// Kinsta/Cloudways/GridPane: WP_Object_Cache subclass wraps Redis.
			// Test live connectivity instead of relying on class name detection.
			if ( function_exists( 'wp_cache_set' ) && wp_cache_set( '_frl_redis_test', 1, 'default', 10 ) ) {
				wp_cache_delete( '_frl_redis_test', 'default' );
				$provider_info['slug']                      = 'redis_active';
				$provider_info['label']                     = 'Redis (WP_Object_Cache)';
				$provider_info['is_effectively_functional'] = true;
			} else {
				$provider_info['slug']  = 'wp_object_cache_dropin';
				$provider_info['label'] = 'WP Object Cache (Drop-in)';
			}
		} elseif ( $cache_class_name ) {
			$provider_info['slug']  = 'unknown_dropin';
			$provider_info['label'] = $cache_class_name;
		} else {
			$provider_info['slug']  = 'unknown_dropin_no_class';
			$provider_info['label'] = 'Unknown Drop-in (No Class)';
		}
		// is_effectively_functional remains false for these generic/unknown cases.
		return self::finalize_provider_details( $provider_info, $transient_key );
	}

	/**
	 * Store resolved provider details in-memory and persist them to a transient.
	 *
	 * Single write path for get_provider_details() so every detection branch
	 * (transients/Litespeed/Docket/Redis/Memcached/generic-fallback) persists
	 * identically — a future branch cannot forget the transient write and
	 * silently lose the week-long cache.
	 *
	 * @param array $provider_info Resolved provider details.
	 * @param string $transient_key Transient key to persist under.
	 * @return array The same $provider_info, for convenient `return` chaining.
	 */
	private static function finalize_provider_details( array $provider_info, string $transient_key ): array {
		self::$cached_provider_details = $provider_info;

		// Store in transient to avoid recursion with self::set()
		set_transient( $transient_key, $provider_info, WEEK_IN_SECONDS );

		return self::$cached_provider_details;
	}

	/**
	 * Get cache configuration for display purposes.
	 *
	 * @return array{PREFIX: string, TTL: array, persistent_groups: array, cache_dependencies: array, LOCK_TTL: int} Cache configuration.
	 */
	public static function get_cache_config() {
		return array(
			'PREFIX'             => self::PREFIX,
			'TTL'                => self::$default_ttls,
			'persistent_groups'  => self::$persistent_groups,
			'cache_dependencies' => self::$cache_dependencies,
			'LOCK_TTL'           => self::LOCK_TTL,
		);
	}
}
