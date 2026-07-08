<?php
/**
 * Repeater Data Transformer
 *
 * Converts ACPT's columnar serialized array repeater format into
 * SCF/ACF's row-indexed individual meta row format.
 *
 * Backups are stored in the frl_acpt_backup custom table —
 * never in wp_postmeta.
 *
 * @package Fralenuvole
 * @since  5.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

class Frl_Repeater_Transformer {

	private array $repeater_configs;
	private bool $dry_run;
	private string $migration_key;

	private array $stats = array(
		'posts_processed' => 0,
		'rows_created'    => 0,
		'backups_created' => 0,
		'errors'          => array(),
	);

	public function __construct( array $repeater_configs, bool $dry_run = false, string $migration_key = '' ) {
		$this->repeater_configs = $repeater_configs;
		$this->dry_run          = $dry_run;
		$this->migration_key    = $migration_key;
	}

	// ─── Public API ─────────────────────────────────────────────

	public function transform_all(): array {
		foreach ( $this->repeater_configs as $repeater_name => $config ) {
			$this->transform_single_repeater( $repeater_name, $config );
		}
		return array(
			'success' => empty( $this->stats['errors'] ),
			'dry_run' => $this->dry_run,
			'stats'   => $this->stats,
		);
	}

	public function transform_post( string $repeater_name, int $post_id ): int {
		$config = $this->repeater_configs[ $repeater_name ] ?? null;
		if ( $config === null ) {
			$this->stats['errors'][] = "Repeater '{$repeater_name}' not found in config.";
			return 0;
		}
		return $this->do_transform( $repeater_name, $post_id, $config );
	}

	public function undo_post( string $repeater_name, int $post_id ): bool {
		if ( $this->dry_run ) {
			return true; }
		$this->delete_row_indexed_rows( $repeater_name, $post_id );

		// Restore from frl_acpt_backup table
		$original = $this->get_backup( $post_id, $repeater_name );
		if ( $original !== null ) {
			update_post_meta( $post_id, $repeater_name, $original );
			$this->delete_backup( $post_id, $repeater_name );
		}
		return true;
	}

	public function get_stats(): array {
		return $this->stats; }

	// ─── Per-repeater transformation ────────────────────────────

	private function transform_single_repeater( string $repeater_name, array $config ): void {
		global $wpdb;
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != ''",
				$repeater_name
			)
		);
		if ( empty( $post_ids ) ) {
			return; }

		$total  = count( $post_ids );
		$offset = 0;
		$batch  = ACF_MIGRATION_BATCH_SIZE;

		while ( $offset < $total ) {
			$slice = array_slice( $post_ids, $offset, $batch );
			foreach ( $slice as $post_id ) {
				$this->do_transform( $repeater_name, (int) $post_id, $config );
			}
			$offset += $batch;
		}
	}

	private function do_transform( string $repeater_name, int $post_id, array $config ): int {
		// 1. Read ACPT columnar data
		$acpt_data = get_post_meta( $post_id, $repeater_name, true );
		if ( empty( $acpt_data ) || ! is_array( $acpt_data ) ) {
			return 0; }

		// 2. Find columns (skip metadata keys)
		$columns = array();
		foreach ( $acpt_data as $key => $value ) {
			if ( is_array( $value ) ) {
				$columns[ $key ] = $value; }
		}
		if ( empty( $columns ) ) {
			return 0; }

		$first_col = reset( $columns );
		$row_count = count( $first_col );

		if ( $row_count === 0 ) {
			return $this->write_empty_repeater( $repeater_name, $post_id, $config );
		}

		++$this->stats['posts_processed'];

		// 3. Backup original ACPT data to custom table BEFORE overwriting
		if ( ! $this->dry_run ) {
			$this->save_backup( $post_id, $repeater_name, $acpt_data );
			++$this->stats['backups_created'];
		}

		$main_key   = $config['key'] ?? '';
		$sub_fields = $config['sub_fields'] ?? array();
		$created    = 0;

		// 4. Write ACF row-count and field key reference
		if ( ! $this->dry_run ) {
			update_post_meta( $post_id, $repeater_name, $row_count );
			if ( $main_key !== '' ) {
				update_post_meta( $post_id, "_{$repeater_name}", $main_key );
			}
		}
		$created += 2;

		// 5. Write each row's sub-field values
		for ( $i = 0; $i < $row_count; $i++ ) {
			foreach ( $columns as $sub_name => $column_values ) {
				$sub_config = $sub_fields[ $sub_name ] ?? null;
				if ( $sub_config === null ) {
					continue; }

				$sub_key = $sub_config['key'] ?? '';
				$value   = $this->extract_value( $column_values, $i );
				$row_key = "{$repeater_name}_{$i}_{$sub_name}";

				if ( $this->dry_run ) {
					$created                     += 2;
					$this->stats['rows_created'] += 2;
					continue;
				}

				$meta_id = add_post_meta( $post_id, $row_key, $value, true );
				if ( $meta_id && ! is_wp_error( $meta_id ) ) {
					++$created;
					++$this->stats['rows_created'];
				}

				if ( $sub_key !== '' ) {
					add_post_meta( $post_id, "_{$row_key}", $sub_key, true );
					++$created;
					++$this->stats['rows_created'];
				}
			}
		}

		return $created;
	}

	private function write_empty_repeater( string $repeater_name, int $post_id, array $config ): int {
		$main_key = $config['key'] ?? '';
		if ( $this->dry_run ) {
			return 2; }
		update_post_meta( $post_id, $repeater_name, 0 );
		if ( $main_key !== '' ) {
			update_post_meta( $post_id, "_{$repeater_name}", $main_key );
		}
		return 2;
	}

	private function extract_value( array $column_values, int $index ): string {
		$entry = $column_values[ $index ] ?? null;
		if ( $entry === null ) {
			return ''; }
		if ( is_array( $entry ) && isset( $entry['value'] ) ) {
			return (string) $entry['value'];
		}
		return (string) $entry;
	}

	// ─── Custom table: frl_acpt_backup ─────────────────────────

	private function save_backup( int $post_id, string $field_name, array $data ): void {
		global $wpdb;
		$table      = $wpdb->prefix . ACF_MIGRATION_TABLE_BACKUP;
		$serialized = serialize( $data );

		// UPSERT: one backup per (post_id, field_name)
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO `{$table}` (`post_id`, `field_name`, `acpt_data`, `migration_key`)
             VALUES (%d, %s, %s, %s)
             ON DUPLICATE KEY UPDATE `acpt_data` = VALUES(`acpt_data`), `migration_key` = VALUES(`migration_key`)",
				$post_id,
				$field_name,
				$serialized,
				$this->migration_key
			)
		);
	}

	private function get_backup( int $post_id, string $field_name ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . ACF_MIGRATION_TABLE_BACKUP;
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT `acpt_data` FROM `{$table}` WHERE `post_id` = %d AND `field_name` = %s",
				$post_id,
				$field_name
			)
		);
		if ( ! $row ) {
			return null; }
		$data = @unserialize( $row->acpt_data, array( 'allowed_classes' => false ) );
		return is_array( $data ) ? $data : null;
	}

	private function delete_backup( int $post_id, string $field_name ): void {
		global $wpdb;
		$table = $wpdb->prefix . ACF_MIGRATION_TABLE_BACKUP;
		$wpdb->delete(
			$table,
			array(
				'post_id'    => $post_id,
				'field_name' => $field_name,
			)
		);
	}

	// ─── Rollback helpers ───────────────────────────────────────

	private function delete_row_indexed_rows( string $repeater_name, int $post_id ): void {
		global $wpdb;
		$prefix = $wpdb->esc_like( "{$repeater_name}_" );
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s",
				$post_id,
				$prefix . '%'
			)
		);
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s",
				$post_id,
				'_' . $prefix . '%'
			)
		);
		delete_post_meta( $post_id, $repeater_name );
		delete_post_meta( $post_id, "_{$repeater_name}" );
	}
}
