<?php
declare(strict_types=1);
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Batch-oriented DB operations for Frl_Cache_Manager: bulk transient preload,
 * transactional execution, batched transient deletion.
 * Extracted from Frl_Cache_Manager for maintainability. Coupled to host class
 * members below (not reusable elsewhere).
 *
 * @method static array safe_db_query( string $query, array $fallback = array(), string $operation = 'cache_query', string $output = OBJECT )
 * @method static string generate_key( string $group, $key, ?string $language = null )
 * @method static void set_runtime( string $cache_key, $value, ?string $group = null )
 * @method static mixed unwrap_transient_value( mixed $value )
 * @package Fralenuvole
 * @since 5.7.4.5
 */
trait Frl_Cache_Batch_Trait {

	/**
	 * Batch-load all preload groups from transients in a single DB query.
	 *
	 * Replaces N separate wp_options LIKE scans (one per group) with a single
	 * combined OR-chain query. Results are distributed by group prefix and
	 * injected into the runtime cache identically to the per-group path in
	 * get_multi().
	 *
	 * @param string[] $groups Cache groups to preload.
	 * @return void
	 */
	private static function batch_preload_transients( array $groups ): void {
		global $wpdb;
		frl_flush_db();

		$or_clauses = array();
		foreach ( $groups as $group ) {
			$prefix         = '_transient_' . Frl_Cache_Manager::PREFIX . $group . '_';
			$timeout_prefix = '_transient_timeout_' . Frl_Cache_Manager::PREFIX . $group . '_';
			$or_clauses[]   = $wpdb->prepare(
				'option_name LIKE %s',
				$wpdb->esc_like( $prefix ) . '%'
			);
			$or_clauses[]   = $wpdb->prepare(
				'option_name LIKE %s',
				$wpdb->esc_like( $timeout_prefix ) . '%'
			);
		}

		$query      = "SELECT option_name, option_value FROM {$wpdb->options} WHERE " . implode( ' OR ', $or_clauses );
		$db_results = self::safe_db_query( $query, null, 'batch_preload_transients' );
		if ( $db_results === null ) {
			return;
		}

		// Distribute results by group prefix
		$by_group = array();
		foreach ( $db_results as $row ) {
			foreach ( $groups as $group ) {
				$prefix         = '_transient_' . Frl_Cache_Manager::PREFIX . $group . '_';
				$timeout_prefix = '_transient_timeout_' . Frl_Cache_Manager::PREFIX . $group . '_';
				if ( str_starts_with( $row->option_name, $prefix ) || str_starts_with( $row->option_name, $timeout_prefix ) ) {
					$by_group[ $group ][] = $row;
					break;
				}
			}
		}

		// Process each group: separate values/timeouts, populate runtime cache
		foreach ( $groups as $group ) {
			$rows = $by_group[ $group ] ?? array();

			if ( empty( $rows ) ) {
				Frl_Cache_Manager::$loaded_groups[ $group ] = true;
				continue;
			}

			$group_prefix       = '_transient_' . Frl_Cache_Manager::PREFIX . $group . '_';
			$timeout_prefix     = '_transient_timeout_' . Frl_Cache_Manager::PREFIX . $group . '_';
			$prefix_len         = strlen( $group_prefix );
			$timeout_prefix_len = strlen( $timeout_prefix );

			$wp_cache   = array();
			$transients = array();
			$timeouts   = array();

			foreach ( $rows as $row ) {
				$wp_cache[ $row->option_name ] = $row->option_value;

				if ( str_starts_with( $row->option_name, $timeout_prefix ) ) {
					// timeout row — tracked in wp_cache but not injected into runtime
					$timeouts[ substr( $row->option_name, $timeout_prefix_len ) ] = $row->option_value;
					continue;
				}

				$key                = substr( $row->option_name, $prefix_len );
				$value              = self::unwrap_transient_value( maybe_unserialize( $row->option_value ) );
				$transients[ $key ] = $value;
			}

			// Drop rows whose timeout has passed; get_transient() would treat them as misses.
			$now = time();
			foreach ( $transients as $key => $value ) {
				if ( isset( $timeouts[ $key ] ) && (int) $timeouts[ $key ] < $now ) {
					unset( $transients[ $key ] );
				}
			}

			// Inject into runtime cache — mirrors get_multi() transient path
			foreach ( $transients as $key => $value ) {
				// Key already carries group + language: generate_key() would prefix them twice.
				self::set_runtime( $group . '_' . $key, $value, $group );
			}

			// Inject into WordPress option cache — mirrors get_multi() transient path
			wp_cache_add_multiple( $wp_cache, 'options' );

			Frl_Cache_Manager::$loaded_groups[ $group ] = true;
		}
	}
}
