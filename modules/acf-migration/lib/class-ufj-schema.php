<?php
/**
 * Universal Field JSON (UFJ) Schema Validator
 *
 * Validates that UFJ data conforms to the expected structure before
 * it is consumed by the SCF Importer. This is the contract between
 * the ACPT Parser (export) and SCF Importer (import).
 *
 * @package Fralenuvole
 * @since  5.9.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates UFJ data structure.
 *
 * Standalone class — zero WordPress or fralenuvole dependencies.
 * Pure PHP array/structural validation.
 */
class Frl_Ufj_Schema {

	/**
	 * Supported universal field types.
	 *
	 * @var array<string>
	 */
	private const VALID_TYPES = array(
		'text',
		'textarea',
		'number',
		'email',
		'url',
		'wysiwyg',
		'select',
		'radio',
		'checkbox',
		'true_false',
		'image',
		'file',
		'gallery',
		'oembed',
		'post_object',
		'relationship',
		'taxonomy',
		'user',
		'date_picker',
		'date_time_picker',
		'time_picker',
		'color_picker',
		'repeater',
		'group',
		'message',
		'range',
		'password',
	);

	/**
	 * Expected top-level keys in a UFJ document.
	 *
	 * @var array<string, bool>
	 */
	private const TOP_KEYS = array(
		'version'      => true,
		'source'       => true,
		'exported_at'  => true,
		'groups'       => true,
		'option_pages' => true,
		'data_map'     => true,
	);

	/**
	 * Validate a UFJ document.
	 *
	 * @param array $ufj The UFJ data to validate.
	 * @return array{valid: bool, errors?: array<string>}
	 */
	public function validate( array $ufj ): array {
		$errors = array();

		// 1. Top-level required keys
		foreach ( self::TOP_KEYS as $key => $_ ) {
			if ( ! array_key_exists( $key, $ufj ) ) {
				$errors[] = "Missing required top-level key: '{$key}'";
			}
		}

		if ( ! empty( $errors ) ) {
			return array(
				'valid'  => false,
				'errors' => $errors,
			);
		}

		// 2. Version
		if ( ! is_string( $ufj['version'] ) || $ufj['version'] === '' ) {
			$errors[] = "Invalid or missing 'version'. Must be a non-empty string.";
		}

		// 3. Groups
		if ( ! is_array( $ufj['groups'] ) ) {
			$errors[] = "'groups' must be an array.";
		} else {
			foreach ( $ufj['groups'] as $i => $group ) {
				$group_errors = $this->validate_group( $group, $i );
				$errors       = array_merge( $errors, $group_errors );
			}
		}

		// 4. Option pages
		if ( ! is_array( $ufj['option_pages'] ) ) {
			$errors[] = "'option_pages' must be an array.";
		} else {
			foreach ( $ufj['option_pages'] as $i => $page ) {
				$page_errors = $this->validate_option_page( $page, $i );
				$errors      = array_merge( $errors, $page_errors );
			}
		}

		// 5. Data map
		if ( ! is_array( $ufj['data_map'] ) ) {
			$errors[] = "'data_map' must be an array.";
		}

		return empty( $errors )
			? array( 'valid' => true )
			: array(
				'valid'  => false,
				'errors' => $errors,
			);
	}

	/**
	 * Validate a single field group.
	 *
	 * @param array $group Group data.
	 * @param int   $index Index in the groups array (for error messages).
	 * @return array<string> Error messages.
	 */
	private function validate_group( array $group, int $index ): array {
		$errors = array();
		$prefix = "groups[{$index}]";

		if ( empty( $group['name'] ) || ! is_string( $group['name'] ) ) {
			$errors[] = "{$prefix}: Missing or invalid 'name' (must be non-empty string).";
		}
		if ( empty( $group['label'] ) || ! is_string( $group['label'] ) ) {
			$errors[] = "{$prefix}: Missing or invalid 'label' (must be non-empty string).";
		}
		if ( empty( $group['location'] ) || ! is_array( $group['location'] ) ) {
			$errors[] = "{$prefix}: Missing or invalid 'location' (must be non-empty array).";
		}
		if ( ! isset( $group['boxes'] ) || ! is_array( $group['boxes'] ) ) {
			$errors[] = "{$prefix}: Missing or invalid 'boxes' (must be array).";
		} else {
			foreach ( $group['boxes'] as $bi => $box ) {
				$box_errors = $this->validate_box( $box, "{$prefix}.boxes[{$bi}]" );
				$errors     = array_merge( $errors, $box_errors );
			}
		}

		return $errors;
	}

	/**
	 * Validate a single meta box within a field group.
	 *
	 * @param array  $box    Box data.
	 * @param string $prefix Path prefix for error messages.
	 * @return array<string> Error messages.
	 */
	private function validate_box( array $box, string $prefix ): array {
		$errors = array();

		if ( empty( $box['name'] ) || ! is_string( $box['name'] ) ) {
			$errors[] = "{$prefix}: Missing or invalid 'name'.";
		}
		if ( ! isset( $box['fields'] ) || ! is_array( $box['fields'] ) ) {
			$errors[] = "{$prefix}: Missing or invalid 'fields' (must be array).";
		} else {
			foreach ( $box['fields'] as $fi => $field ) {
				$field_errors = $this->validate_field( $field, "{$prefix}.fields[{$fi}]" );
				$errors       = array_merge( $errors, $field_errors );
			}
		}

		return $errors;
	}

	/**
	 * Validate a single field definition.
	 *
	 * @param array  $field  Field data.
	 * @param string $prefix Path prefix for error messages.
	 * @return array<string> Error messages.
	 */
	public function validate_field( array $field, string $prefix ): array {
		$errors = array();

		if ( empty( $field['name'] ) || ! is_string( $field['name'] ) ) {
			$errors[] = "{$prefix}: Missing or invalid 'name'.";
		}
		if ( empty( $field['label'] ) || ! is_string( $field['label'] ) ) {
			$errors[] = "{$prefix}: Missing or invalid 'label'.";
		}
		if ( empty( $field['type'] ) || ! is_string( $field['type'] ) ) {
			$errors[] = "{$prefix}: Missing or invalid 'type'.";
		} elseif ( ! in_array( $field['type'], self::VALID_TYPES, true ) ) {
			$errors[] = "{$prefix}: Unknown field type '{$field['type']}'. Valid types: " . implode( ', ', self::VALID_TYPES );
		}

		// Repeater sub-fields
		if ( ( $field['type'] ?? '' ) === 'repeater' ) {
			if ( empty( $field['sub_fields'] ) || ! is_array( $field['sub_fields'] ) ) {
				$errors[] = "{$prefix}: Repeater field missing 'sub_fields' array.";
			} else {
				foreach ( $field['sub_fields'] as $si => $sub ) {
					$sub_errors = $this->validate_field( $sub, "{$prefix}.sub_fields[{$si}]" );
					$errors     = array_merge( $errors, $sub_errors );
				}
			}
		}

		// Select/radio/checkbox require choices
		if ( in_array( ( $field['type'] ?? '' ), array( 'select', 'radio', 'checkbox' ), true ) ) {
			if ( ! isset( $field['choices'] ) || ! is_array( $field['choices'] ) ) {
				$errors[] = "{$prefix}: Field type '{$field['type']}' requires 'choices' array.";
			}
		}

		return $errors;
	}

	/**
	 * Validate a single option page definition.
	 *
	 * @param array $page Option page data.
	 * @param int   $index Index in the array.
	 * @return array<string> Error messages.
	 */
	private function validate_option_page( array $page, int $index ): array {
		$errors = array();
		$prefix = "option_pages[{$index}]";

		if ( empty( $page['slug'] ) || ! is_string( $page['slug'] ) ) {
			$errors[] = "{$prefix}: Missing or invalid 'slug'.";
		}
		if ( empty( $page['title'] ) || ! is_string( $page['title'] ) ) {
			$errors[] = "{$prefix}: Missing or invalid 'title'.";
		}

		return $errors;
	}

	/**
	 * Return the supported field types (for documentation/testing).
	 *
	 * @return array<string>
	 */
	public function get_valid_types(): array {
		return self::VALID_TYPES;
	}
}
