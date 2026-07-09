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
 * @method static string generate_key( string $group, $key )
 * @method static void set_runtime( string $cache_key, $value, ?string $group = null )
 * @property static array $loaded_groups
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
			$prefix         = '_transient_' . self::PREFIX . $group . '_';
			$timeout_prefix = '_transient_timeout_' . self::PREFIX . $group . '_';
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
		$db_results = self::safe_db_query( $query, array(), 'batch_preload_transients' );

		// Distribute results by group prefix
		$by_group = array();
		foreach ( $db_results as $row ) {
			foreach ( $groups as $group ) {
				$prefix         = '_transient_' . self::PREFIX . $group . '_';
				$timeout_prefix = '_transient_timeout_' . self::PREFIX . $group . '_';
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
				self::$loaded_groups[ $group ] = true;
				continue;
			}

			$group_prefix       = '_transient_' . self::PREFIX . $group . '_';
			$timeout_prefix     = '_transient_timeout_' . self::PREFIX . $group . '_';
			$prefix_len         = strlen( $group_prefix );
			$timeout_prefix_len = strlen( $timeout_prefix );

			$wp_cache   = array();
			$transients = array();

			foreach ( $rows as $row ) {
				$wp_cache[ $row->option_name ] = $row->option_value;

				if ( str_starts_with( $row->option_name, $timeout_prefix ) ) {
					// timeout row — tracked in wp_cache but not injected into runtime
					continue;
				}

				$key                = substr( $row->option_name, $prefix_len );
				$value              = maybe_unserialize( $row->option_value );
				$transients[ $key ] = $value;

				// Inject into runtime cache — mirrors get_multi() transient path
				$cache_key = self::generate_key( $group, $key );
				self::set_runtime( $cache_key, $value, $group );
			}

			// Inject into WordPress option cache — mirrors get_multi() transient path
			if ( ! empty( $wp_cache ) ) {
				wp_cache_add_multiple( $wp_cache, 'options' );
			}

			self::$loaded_groups[ $group ] = true;
		}
	}

	/**
	 * Execute database operations with transaction support.
	 *
	 * @param callable $operations Function containing database operations.
	 * @param string $operation_name Name for logging purposes.
	 * @return mixed Result from operations, or false on failure.
	 */
	private static function execute_with_transaction( callable $operations, $operation_name = 'cache_transaction' ) {
		global $wpdb;

		// Check if transactions are supported (mysqli driver — all modern MySQL/MariaDB with InnoDB)
		$supports_transactions = $wpdb->use_mysqli;

		if ( $supports_transactions ) {
			$wpdb->query( 'START TRANSACTION' );
		}

		try {
			// Execute the operations
			$result = $operations();

			if ( $supports_transactions ) {
				if ( $wpdb->last_error ) {
					throw new Exception( "Database error in {$operation_name}: " . $wpdb->last_error );
				}
				$wpdb->query( 'COMMIT' );
			}

			return $result;
		} catch ( Exception $e ) {
			if ( $supports_transactions ) {
				$wpdb->query( 'ROLLBACK' );
			}

			frl_log(
				'FRL Cache Transaction Error in {operation}: {error}',
				array(
					'operation' => $operation_name,
					'error'     => $e->getMessage(),
				)
			);
			return false;
		}
	}

	/**
	 * Safely delete multiple transients in batches with transaction support.
	 *
	 * @param array $transient_keys Array of full transient option names.
	 * @param int $batch_size Number of keys to process per batch.
	 * @return array{total_keys: int, deleted_count: int, batches_processed: int, errors: int} Stats about deletion.
	 */
	private static function safe_batch_delete_transients( array $transient_keys, $batch_size = 100 ) {
		global $wpdb;

		$stats = array(
			'total_keys'        => count( $transient_keys ),
			'deleted_count'     => 0,
			'batches_processed' => 0,
			'errors'            => 0,
		);

		if ( empty( $transient_keys ) ) {
			return $stats;
		}

		$batches = array_chunk( $transient_keys, $batch_size );

		foreach ( $batches as $batch ) {
			$batch_result = self::execute_with_transaction(
				function () use ( $wpdb, $batch ) {
					$placeholders = implode( ',', array_fill( 0, count( $batch ), '%s' ) );
					$query        = $wpdb->prepare(
						"DELETE FROM $wpdb->options WHERE option_name IN ($placeholders)",
						$batch
					);

					$deleted = $wpdb->query( $query );

					if ( $wpdb->last_error ) {
						throw new Exception( 'Batch delete failed: ' . $wpdb->last_error );
					}

					return $deleted;
				},
				'batch_delete_transients'
			);

			if ( $batch_result !== false ) {
				$stats['deleted_count'] += $batch_result;
				++$stats['batches_processed'];
			} else {
				++$stats['errors'];
				frl_log( 'FRL Cache: Batch delete failed for {count} keys', array( 'count' => count( $batch ) ) );
			}
		}

		return $stats;
	}
}
