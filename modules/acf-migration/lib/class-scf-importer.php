<?php
/**
 * SCF/ACF Importer — Import Phase
 *
 * Consumes Universal Field JSON (UFJ) and creates SCF/ACF field groups,
 * field definitions, and reference metadata rows.
 *
 * Field key mappings persist to the frl_acpt_field_map custom table
 * for idempotent re-imports and shim/rollback lookups.
 *
 * @package Fralenuvole
 * @since  5.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

class Frl_Scf_Importer {

	private array $ufj;
	private bool $dry_run;

	/** @var array<string, string> Field key -> UFJ name */
	private array $field_key_map = array();

	/** @var array<string, string> Group key -> UFJ name */
	private array $group_key_map = array();

	/** @var array<string, array> Repeater configs */
	private array $repeater_configs = array();

	private array $log_entry = array(
		'group_ids'          => array(),
		'field_ids'          => array(),
		'reference_meta_ids' => array(),
		'field_key_map'      => array(),
		'group_key_map'      => array(),
		'errors'             => array(),
		'status'             => 'in_progress',
	);

	public function __construct( array $ufj, bool $dry_run = false ) {
		$this->ufj     = $ufj;
		$this->dry_run = $dry_run;
	}

	// ─── Public API ─────────────────────────────────────────────

	public function run(): array {
		$this->import_groups();
		$this->import_option_pages();
		$this->generate_reference_rows();

		$this->log_entry['status'] = empty( $this->log_entry['errors'] )
			? 'completed'
			: 'completed_with_errors';

		return array(
			'success'   => empty( $this->log_entry['errors'] ),
			'dry_run'   => $this->dry_run,
			'log_entry' => $this->log_entry,
			'groups'    => $this->group_key_map,
			'fields'    => $this->field_key_map,
			'repeaters' => $this->repeater_configs,
		);
	}

	public function get_repeater_configs(): array {
		return $this->repeater_configs; }
	public function get_field_key_map(): array {
		return $this->field_key_map; }
	public function get_log_entry(): array {
		return $this->log_entry; }

	// ─── Group import ───────────────────────────────────────────

	private function import_groups(): void {
		$groups = $this->ufj['groups'] ?? array();
		if ( empty( $groups ) ) {
			return; }
		foreach ( $groups as $group_data ) {
			$this->import_single_group( $group_data );
		}
	}

	private function import_single_group( array $group_data ): void {
		$group_name = $group_data['name'];
		$group_key  = $this->generate_key( ACF_MIGRATION_GROUP_KEY_PREFIX );

		if ( $this->dry_run ) {
			$this->group_key_map[ $group_key ] = $group_name;
			return;
		}

		$group_id = wp_insert_post(
			array(
				'post_type'    => ACF_MIGRATION_POST_TYPE_GROUP,
				'post_title'   => $group_data['label'] ?? $group_name,
				'post_name'    => $group_key,
				'post_status'  => 'publish',
				'post_content' => '',
			)
		);

		if ( is_wp_error( $group_id ) || ! $group_id ) {
			$this->log_entry['errors'][] = sprintf(
				'Failed to create field group "%s": %s',
				$group_name,
				is_wp_error( $group_id ) ? $group_id->get_error_message() : 'Unknown'
			);
			return;
		}

		$this->log_entry['group_ids'][]    = $group_id;
		$this->group_key_map[ $group_key ] = $group_name;
		$this->write_group_postmeta( $group_id, $group_data, $group_key );
		$this->import_fields( $group_id, $group_data, $group_key );
	}

	private function write_group_postmeta( int $group_id, array $group_data, string $group_key ): void {
		$location = $group_data['location'] ?? array();
		if ( ! empty( $location ) ) {
			$rules = array();
			foreach ( $location as $rule ) {
				$rules[] = array(
					'param'    => $rule['param'] ?? 'post_type',
					'operator' => $rule['operator'] ?? '==',
					'value'    => $rule['value'] ?? '',
				);
			}
			$this->add_postmeta( $group_id, 'rule', $rules );
		}
		$this->add_postmeta( $group_id, 'position', $group_data['position'] ?? 'normal' );
		$this->add_postmeta( $group_id, 'style', $group_data['style'] ?? 'default' );
		$this->add_postmeta( $group_id, 'label_placement', 'top' );
		$this->add_postmeta( $group_id, 'instruction_placement', 'label' );
		$this->add_postmeta( $group_id, 'hide_on_screen', '' );
		$this->add_postmeta( $group_id, 'active', true );
		$this->add_postmeta( $group_id, 'menu_order', 0 );
	}

	// ─── Field import ───────────────────────────────────────────

	private function import_fields( int $group_id, array $group_data, string $group_key ): void {
		$boxes = $group_data['boxes'] ?? array();
		$order = 0;
		foreach ( $boxes as $box ) {
			foreach ( ( $box['fields'] ?? array() ) as $field_data ) {
				$this->import_single_field( $group_id, $field_data, ++$order, $group_key );
			}
		}
	}

	private function import_single_field( int $group_id, array $field_data, int $menu_order, string $group_key ): void {
		$field_name = $field_data['name'];
		$field_key  = $this->generate_key( ACF_MIGRATION_FIELD_KEY_PREFIX );

		$this->field_key_map[ $field_name ] = $field_key;

		// Persist to frl_acpt_field_map (idempotent via ON DUPLICATE KEY)
		if ( ! $this->dry_run ) {
			$this->save_field_map(
				$field_name,
				$field_key,
				$group_key,
				$field_data['type'] ?? 'text',
				false,
				''
			);
		}

		if ( $this->dry_run ) {
			return; }

		$field_id = wp_insert_post(
			array(
				'post_type'    => ACF_MIGRATION_POST_TYPE_FIELD,
				'post_title'   => $field_data['label'] ?? $field_name,
				'post_name'    => $field_key,
				'post_parent'  => $group_id,
				'post_status'  => 'publish',
				'menu_order'   => $menu_order,
				'post_content' => '',
			)
		);

		if ( is_wp_error( $field_id ) || ! $field_id ) {
			$this->log_entry['errors'][] = sprintf(
				'Failed to create field "%s": %s',
				$field_name,
				is_wp_error( $field_id ) ? $field_id->get_error_message() : 'Unknown'
			);
			return;
		}

		$this->log_entry['field_ids'][] = $field_id;
		$this->write_field_postmeta( $field_id, $field_data, $field_key );

		if ( ( $field_data['type'] ?? '' ) === 'repeater' ) {
			$this->import_repeater_sub_fields( $field_id, $field_data, $field_name, $group_key );
		}
	}

	private function write_field_postmeta( int $field_id, array $field_data, string $field_key ): void {
		$this->add_postmeta( $field_id, 'name', $field_data['name'] );
		$this->add_postmeta( $field_id, 'label', $field_data['label'] );
		$this->add_postmeta( $field_id, 'type', $field_data['type'] );
		$this->add_postmeta( $field_id, 'instructions', $field_data['instructions'] ?? '' );
		$this->add_postmeta( $field_id, 'required', ! empty( $field_data['required'] ) ? 1 : 0 );
		$this->add_postmeta( $field_id, 'default_value', $field_data['default'] ?? '' );
		$this->add_postmeta( $field_id, 'placeholder', $field_data['placeholder'] ?? '' );
		$this->add_postmeta( $field_id, 'prepend', '' );
		$this->add_postmeta( $field_id, 'append', '' );
		$this->add_postmeta(
			$field_id,
			'wrapper',
			array(
				'width' => (string) ( $field_data['width'] ?? '' ),
				'class' => '',
				'id'    => '',
			)
		);
		$cond = $field_data['conditional_logic'] ?? array();
		$this->add_postmeta(
			$field_id,
			'conditional_logic',
			is_array( $cond ) && ! empty( $cond ) ? array( $cond ) : 0
		);
		if ( ! empty( $field_data['choices'] ) ) {
			$this->add_postmeta( $field_id, 'choices', $field_data['choices'] );
		}
		if ( ! empty( $field_data['post_type_filter'] ) ) {
			$this->add_postmeta( $field_id, 'post_type', $field_data['post_type_filter'] );
			$this->add_postmeta( $field_id, 'multiple', 1 );
		}

		$type = $field_data['type'] ?? '';
		if ( $type === 'taxonomy' ) {
			$this->add_postmeta( $field_id, 'taxonomy', 'category' );
			$this->add_postmeta( $field_id, 'field_type', 'select' );
			$this->add_postmeta( $field_id, 'allow_null', 0 );
			$this->add_postmeta( $field_id, 'add_term', 1 );
			$this->add_postmeta( $field_id, 'save_terms', 0 );
			$this->add_postmeta( $field_id, 'load_terms', 0 );
			$this->add_postmeta( $field_id, 'return_format', 'id' );
		}
		if ( $type === 'user' ) {
			$this->add_postmeta( $field_id, 'role', '' );
			$this->add_postmeta( $field_id, 'allow_null', 0 );
			$this->add_postmeta( $field_id, 'multiple', 1 );
		}
		if ( $type === 'repeater' ) {
			$this->add_postmeta( $field_id, 'layout', $field_data['layout'] ?? 'row' );
			$this->add_postmeta( $field_id, 'button_label', 'Add Row' );
			$this->add_postmeta( $field_id, 'min', (int) ( $field_data['min'] ?? 0 ) );
			$this->add_postmeta( $field_id, 'max', (int) ( $field_data['max'] ?? 0 ) );
			$this->add_postmeta( $field_id, 'collapsed', '' );
		}
	}

	private function import_repeater_sub_fields( int $parent_id, array $field_data, string $parent_name, string $group_key ): void {
		$sub_fields  = $field_data['sub_fields'] ?? array();
		$sub_menu    = 0;
		$sub_configs = array();

		foreach ( $sub_fields as $sub ) {
			++$sub_menu;
			$sub_name  = $sub['name'];
			$sub_key   = $this->generate_key( ACF_MIGRATION_FIELD_KEY_PREFIX );
			$composite = "{$parent_name}_{$sub_name}";

			$this->field_key_map[ $composite ] = $sub_key;

			if ( ! $this->dry_run ) {
				$this->save_field_map(
					$composite,
					$sub_key,
					'',
					$sub['type'] ?? 'text',
					false,
					$parent_name
				);
			}

			$sub_configs[ $sub_name ] = array(
				'key'  => $sub_key,
				'type' => $sub['type'] ?? 'text',
			);

			if ( $this->dry_run ) {
				continue; }

			$sub_id = wp_insert_post(
				array(
					'post_type'    => ACF_MIGRATION_POST_TYPE_FIELD,
					'post_title'   => $sub['label'] ?? $sub_name,
					'post_name'    => $sub_key,
					'post_parent'  => $parent_id,
					'post_status'  => 'publish',
					'menu_order'   => $sub_menu,
					'post_content' => '',
				)
			);

			if ( is_wp_error( $sub_id ) || ! $sub_id ) {
				$this->log_entry['errors'][] = sprintf(
					'Failed to create sub-field "%s" for repeater "%s": %s',
					$sub_name,
					$parent_name,
					is_wp_error( $sub_id ) ? $sub_id->get_error_message() : 'Unknown'
				);
				continue;
			}
			$this->log_entry['field_ids'][] = $sub_id;
			$this->write_field_postmeta( $sub_id, $sub, $sub_key );
		}

		// Mark parent as repeater in field_map
		if ( ! $this->dry_run ) {
			$this->save_field_map(
				$parent_name,
				$this->field_key_map[ $parent_name ] ?? '',
				'',
				'repeater',
				true,
				''
			);
		}

		$this->repeater_configs[ $parent_name ] = array(
			'name'       => $parent_name,
			'label'      => $field_data['label'] ?? $parent_name,
			'key'        => $this->field_key_map[ $parent_name ] ?? '',
			'sub_fields' => $sub_configs,
		);
	}

	// ─── Reference rows ─────────────────────────────────────────

	private function generate_reference_rows(): void {
		global $wpdb;
		foreach ( $this->field_key_map as $field_name => $field_key ) {
			if ( str_contains( $field_name, '_' )
				&& ! empty( $this->repeater_configs )
				&& isset( $this->repeater_configs[ explode( '_', $field_name, 2 )[0] ] ) ) {
				continue;
			}
			if ( $this->dry_run ) {
				continue; }

			$post_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key = %s AND meta_value IS NOT NULL AND meta_value != ''",
					$field_name
				)
			);
			if ( empty( $post_ids ) ) {
				continue; }
			foreach ( $post_ids as $post_id ) {
				$meta_id = add_post_meta( (int) $post_id, "_{$field_name}", $field_key, true );
				if ( $meta_id ) {
					$this->log_entry['reference_meta_ids'][] = $meta_id;
				}
			}
		}
	}

	// ─── Options pages ──────────────────────────────────────────

	private function import_option_pages(): void {
		$pages = $this->ufj['option_pages'] ?? array();
		if ( empty( $pages ) || $this->dry_run || ! function_exists( 'acf_add_options_page' ) ) {
			return; }
		foreach ( $pages as $page ) {
			$args = array(
				'page_title' => $page['title'] ?? 'Options',
				'menu_title' => $page['menu_title'] ?? $page['title'] ?? 'Options',
				'menu_slug'  => $page['slug'] ?? '',
				'capability' => $page['capability'] ?? 'manage_options',
				'redirect'   => false,
			);
			if ( ! empty( $page['position'] ) ) {
				$args['position'] = (int) $page['position']; }
			if ( ! empty( $page['icon'] ) ) {
				$args['icon_url'] = $page['icon']; }
			if ( ! empty( $page['parent'] ) ) {
				$args['parent_slug'] = $page['parent']; }
			acf_add_options_page( $args );
		}
	}

	// ─── Field map table ───────────────────────────────────────

	private function save_field_map( string $field_name, string $field_key, string $group_key, string $field_type, bool $is_repeater, string $parent_name ): void {
		global $wpdb;
		$table = $wpdb->prefix . ACF_MIGRATION_TABLE_FIELD_MAP;
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO `{$table}` (`field_name`, `field_key`, `field_type`, `group_key`, `is_repeater`, `parent_name`)
             VALUES (%s, %s, %s, %s, %d, %s)
             ON DUPLICATE KEY UPDATE `field_key` = VALUES(`field_key`), `field_type` = VALUES(`field_type`), `group_key` = VALUES(`group_key`)",
				$field_name,
				$field_key,
				$field_type,
				$group_key,
				$is_repeater ? 1 : 0,
				$parent_name
			)
		);
	}

	// ─── Helpers ────────────────────────────────────────────────

	private function generate_key( string $prefix ): string {
		try {
			$random = bin2hex( random_bytes( ACF_MIGRATION_KEY_ENTROPY_BYTES ) );
		} catch ( \Throwable $t ) {
			if ( function_exists( 'openssl_random_pseudo_bytes' ) ) {
				$random = bin2hex( openssl_random_pseudo_bytes( ACF_MIGRATION_KEY_ENTROPY_BYTES ) );
			} else {
				$random = substr( md5( uniqid( (string) mt_rand(), true ) ), 0, 12 );
			}
		}
		return $prefix . $random;
	}

	private function add_postmeta( int $post_id, string $key, $value ): void {
		$meta_id = add_post_meta( $post_id, $key, $value, true );
		if ( $meta_id && ! is_wp_error( $meta_id ) ) {
			$this->log_entry['reference_meta_ids'][] = $meta_id;
		} elseif ( is_wp_error( $meta_id ) ) {
			error_log(
				sprintf(
					'ACF Migration: add_post_meta failed for post %d, key "%s": %s',
					$post_id,
					$key,
					$meta_id->get_error_message()
				)
			);
		}
	}
}
