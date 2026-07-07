<?php
/**
 * ACPT Parser — Export Phase
 *
 * Reads an ACPT export JSON file and converts it to the
 * Universal Field JSON (UFJ) intermediate format.
 *
 * Standalone class — zero WordPress or fralenuvole dependencies.
 * Requires only the UFJ Schema class for optional validation.
 *
 * @package Fralenuvole
 * @since  5.9.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parses ACPT export data into UFJ format.
 */
class Frl_Acpt_Parser {

	/**
	 * Raw ACPT export data.
	 *
	 * @var array
	 */
	private array $acpt;

	/**
	 * Built UFJ data.
	 *
	 * @var array
	 */
	private array $ufj;

	// ─── Type mapping: ACPT → UFJ universal types ──────────────

	/**
	 * Maps ACPT field type strings to UFJ universal type strings.
	 *
	 * @var array<string, string>
	 */
	private const TYPE_MAP = array(
		'Text'            => 'text',
		'Textarea'        => 'textarea',
		'Number'          => 'number',
		'Email'           => 'email',
		'Url'             => 'url',
		'Editor'          => 'wysiwyg',
		'Select'          => 'select',
		'Radio'           => 'radio',
		'Checkbox'        => 'checkbox',
		'Toggle'          => 'true_false',
		'Date'            => 'date_picker',
		'DateTime'        => 'date_time_picker',
		'Time'            => 'time_picker',
		'Color'           => 'color_picker',
		'Image'           => 'image',
		'File'            => 'file',
		'Video'           => 'oembed',
		'Embed'           => 'oembed',
		'Gallery'         => 'gallery',
		'PostObject'      => 'post_object',
		'PostObjectMulti' => 'relationship',
		'Relationship'    => 'relationship',
		'Term'            => 'taxonomy',
		'TermMulti'       => 'taxonomy',
		'User'            => 'user',
		'UserMulti'       => 'user',
		'Repeater'        => 'repeater',
		'Group'           => 'group',
		'Html'            => 'message',
		'Range'           => 'range',
		'Password'        => 'password',
		// Fallback for unknown types
		'Address'         => 'text',
		'Country'         => 'select',
		'Phone'           => 'text',
		'Rating'          => 'number',
		'Currency'        => 'number',
		'Weight'          => 'number',
		'Length'          => 'number',
		'Icon'            => 'text',
	);

	// ─── Reverse mapping: ACPT → UFJ → ACPT display name ──────

	/**
	 * Maps UFJ types back to ACPT display names (used by the compat shim).
	 *
	 * @var array<string, string>
	 */
	public const TYPE_REVERSE_MAP = array(
		'text'     => 'Text',
		'textarea' => 'Textarea',
		'number'   => 'Number',
		'email'    => 'Email',
		'url'      => 'Url',
		'wysiwyg'  => 'Editor',
		'select'   => 'Select',
		'radio'    => 'Radio',
		'checkbox' => 'Checkbox',
	);

	// ─── Constructor ────────────────────────────────────────────

	/**
	 * @param string $json_path Absolute path to the ACPT export JSON file.
	 * @throws RuntimeException If the file cannot be read or parsed.
	 */
	public function __construct( string $json_path ) {
		if ( ! file_exists( $json_path ) || ! is_readable( $json_path ) ) {
			throw new RuntimeException(
				"ACPT export file not found or not readable: {$json_path}"
			);
		}

		$raw = file_get_contents( $json_path );
		if ( $raw === false ) {
			throw new RuntimeException(
				"Failed to read ACPT export file: {$json_path}"
			);
		}

		$data = json_decode( $raw, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			throw new RuntimeException(
				'ACPT export JSON parse error: ' . json_last_error_msg()
			);
		}

		if ( ! is_array( $data ) ) {
			throw new RuntimeException( 'ACPT export JSON root must be an object/array.' );
		}

		$this->acpt = $data;
		$this->ufj  = array(
			'version'      => ACF_MIGRATION_UFJ_VERSION,
			'source'       => 'ACPT',
			'exported_at'  => gmdate( 'c' ),
			'groups'       => array(),
			'option_pages' => array(),
			'data_map'     => array(
				'post_types'   => array(),
				'option_pages' => array(),
			),
		);
	}

	// ─── Public API ─────────────────────────────────────────────

	/**
	 * Parse the ACPT data into UFJ format.
	 *
	 * @return array The UFJ data.
	 */
	public function parse(): array {
		$this->parse_option_pages();
		$this->parse_meta_groups();
		return $this->ufj;
	}

	/**
	 * Parse and return UFJ as a JSON string.
	 *
	 * @param int $flags JSON encoding flags (default: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).
	 * @return string
	 */
	public function to_json( int $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ): string {
		return (string) json_encode( $this->ufj, $flags );
	}

	/**
	 * Get the raw UFJ array for inspection.
	 *
	 * @return array
	 */
	public function get_ufj(): array {
		return $this->ufj;
	}

	// ─── Option pages ───────────────────────────────────────────

	/**
	 * Parse ACPT option page definitions.
	 */
	private function parse_option_pages(): void {
		$pages = $this->acpt['optionPage'] ?? array();
		if ( empty( $pages ) ) {
			return;
		}

		foreach ( $pages as $page ) {
			$slug = $page['menuSlug'] ?? '';
			if ( $slug === '' ) {
				continue;
			}

			$this->ufj['option_pages'][] = array(
				'slug'       => $slug,
				'title'      => $page['pageTitle'] ?? $page['menuTitle'] ?? $slug,
				'menu_title' => $page['menuTitle'] ?? $page['pageTitle'] ?? $slug,
				'capability' => $page['capability'] ?? 'manage_options',
				'position'   => $page['position'] ?? null,
				'icon'       => $page['icon'] ?? 'dashicons-admin-generic',
				'parent'     => $page['parentId'] ?? null,
			);

			$this->ufj['data_map']['option_pages'][ $slug ] = $slug;
		}
	}

	// ─── Meta groups → UFJ groups ───────────────────────────────

	/**
	 * Parse ACPT meta (field group) definitions.
	 */
	private function parse_meta_groups(): void {
		$meta_groups = $this->acpt['meta'] ?? array();
		if ( empty( $meta_groups ) ) {
			return;
		}

		foreach ( $meta_groups as $meta ) {
			$group = $this->convert_meta_group( $meta );
			if ( $group !== null ) {
				$this->ufj['groups'][] = $group;
			}
		}
	}

	/**
	 * Convert a single ACPT meta group to UFJ group format.
	 *
	 * @param array $meta ACPT meta group data.
	 * @return array|null UFJ group data, or null if invalid.
	 */
	private function convert_meta_group( array $meta ): ?array {
		$name = $meta['name'] ?? '';
		if ( $name === '' ) {
			return null;
		}

		$group = array(
			'name'     => $name,
			'label'    => $meta['label'] ?? $name,
			'position' => $meta['context'] ?? 'normal',
			'style'    => $meta['display'] ?? 'default',
			'location' => $this->convert_location_rules( $meta['belongs'] ?? array() ),
			'boxes'    => array(),
		);

		// Parse boxes (tabs/panels within the field group)
		$boxes = $meta['boxes'] ?? array();
		foreach ( $boxes as $box ) {
			$converted = $this->convert_box( $box );
			if ( $converted !== null ) {
				$group['boxes'][] = $converted;
			}
		}

		// Skip groups with no boxes (should not happen, but defensive)
		if ( empty( $group['boxes'] ) ) {
			return null;
		}

		return $group;
	}

	/**
	 * Convert ACPT box (tab/panel) to UFJ box format.
	 *
	 * @param array $box ACPT box data.
	 * @return array|null UFJ box data, or null if invalid.
	 */
	private function convert_box( array $box ): ?array {
		$name = $box['name'] ?? '';
		if ( $name === '' ) {
			return null;
		}

		$result = array(
			'name'   => $name,
			'label'  => $box['label'] ?? $name,
			'sort'   => (int) ( $box['sort'] ?? 1 ),
			'fields' => array(),
		);

		$fields = $box['fields'] ?? array();
		foreach ( $fields as $field ) {
			$converted = $this->convert_field( $field );
			if ( $converted !== null ) {
				$result['fields'][] = $converted;
			}
		}

		return $result;
	}

	/**
	 * Convert a single ACPT field to UFJ field format.
	 *
	 * @param array $field ACPT field data.
	 * @return array|null UFJ field data, or null if invalid.
	 */
	public function convert_field( array $field ): ?array {
		$name = $field['name'] ?? '';
		if ( $name === '' ) {
			return null;
		}

		$acpt_type = $field['type'] ?? 'Text';
		$ufj_type  = self::TYPE_MAP[ $acpt_type ] ?? 'text';

		$result = array(
			'name'         => $name,
			'label'        => $field['label'] ?? $name,
			'type'         => $ufj_type,
			'required'     => ! empty( $field['isRequired'] ),
			'default'      => $field['defaultValue'] ?? '',
			'instructions' => $field['description'] ?? '',
			'sort'         => (int) ( $field['sort'] ?? 1 ),
		);

		// Width from advanced options
		$result['width'] = $this->extract_advanced_option( $field, 'width', '' );

		// Choices for select/radio/checkbox
		$options = $field['options'] ?? array();
		if ( ! empty( $options ) && is_array( $options ) ) {
			$result['choices'] = array();
			foreach ( $options as $opt ) {
				$label = $opt['label'] ?? '';
				$value = $opt['value'] ?? $label;
				if ( $label !== '' ) {
					$result['choices'][ $value ] = $label;
				}
			}
		}

		// Conditionals
		$conditions = $field['visibilityConditions'] ?? array();
		if ( ! empty( $conditions ) ) {
			$result['conditional_logic'] = $this->convert_conditions( $conditions );
		} else {
			$result['conditional_logic'] = 0;
		}

		// Repeater sub-fields
		if ( $ufj_type === 'repeater' ) {
			$result['sub_fields'] = array();
			$result['layout']     = $this->extract_advanced_option( $field, 'layout', 'row' );
			$result['min']        = (int) $this->extract_advanced_option( $field, 'minimum_blocks', 0 );
			$result['max']        = (int) $this->extract_advanced_option( $field, 'maximum_blocks', 0 );

			$children = $field['children'] ?? array();
			foreach ( $children as $child ) {
				$converted = $this->convert_field( $child );
				if ( $converted !== null ) {
					$result['sub_fields'][] = $converted;
				}
			}
		}

		// Post/Taxonomy/User object filter settings
		$filter_post_type = $this->extract_advanced_option( $field, 'filter_post_type', '' );
		if ( $filter_post_type !== '' ) {
			$result['post_type_filter'] = $filter_post_type;
		}

		return $result;
	}

	// ─── Location rules ─────────────────────────────────────────

	/**
	 * Convert ACPT belong rules to UFJ location rules.
	 *
	 * ACPT format:
	 *   { belongsTo: "customPostType", operator: "=", find: "service" }
	 *
	 * UFJ format:
	 *   [{ param: "post_type", operator: "==", value: "service" }]
	 *
	 * @param array $belongs ACPT belongs array.
	 * @return array UFJ location rules.
	 */
	private function convert_location_rules( array $belongs ): array {
		$location = array();

		foreach ( $belongs as $rule ) {
			$belongs_to = $rule['belongsTo'] ?? '';
			$operator   = $rule['operator'] ?? '=';
			$find       = $rule['find'] ?? '';

			// Normalize operator: ACPT uses "=", UFJ uses "=="
			$ufj_operator = ( $operator === '=' ) ? '==' : $operator;

			switch ( $belongs_to ) {
				case 'customPostType':
					$location[] = array(
						'param'    => 'post_type',
						'operator' => $ufj_operator,
						'value'    => $find,
					);
					// Track in data_map
					$this->ufj['data_map']['post_types'][ $find ] = $find;
					break;

				case 'optionPage':
					$location[] = array(
						'param'    => 'options_page',
						'operator' => $ufj_operator,
						'value'    => $find,
					);
					break;

				case 'taxonomy':
					$location[] = array(
						'param'    => 'taxonomy',
						'operator' => $ufj_operator,
						'value'    => $find,
					);
					break;

				default:
					// Unknown belongs type — create a generic rule
					$location[] = array(
						'param'    => $belongs_to,
						'operator' => $ufj_operator,
						'value'    => $find,
					);
					break;
			}
		}

		return $location;
	}

	// ─── Conditionals ───────────────────────────────────────────

	/**
	 * Convert ACPT visibility conditions to UFJ conditional logic format.
	 *
	 * @param array $conditions ACPT visibility conditions.
	 * @return array UFJ conditional logic array.
	 */
	private function convert_conditions( array $conditions ): array {
		$rules = array();

		foreach ( $conditions as $cond ) {
			$rules[] = array(
				'field'    => $cond['field'] ?? '',
				'operator' => $cond['operator'] ?? '==',
				'value'    => $cond['value'] ?? '',
			);
		}

		return $rules;
	}

	// ─── Advanced options helper ─────────────────────────────────

	/**
	 * Extract a value from ACPT's advancedOptions array.
	 *
	 * @param array  $field   The field data.
	 * @param string $key     The advanced option key to look for.
	 * @param mixed  $default_value Default value if not found.
	 * @return mixed
	 */
	private function extract_advanced_option( array $field, string $key, $default_value = '' ) {
		$opts = $field['advancedOptions'] ?? array();
		if ( ! is_array( $opts ) ) {
			return $default_value;
		}

		foreach ( $opts as $opt ) {
			if ( ( $opt['key'] ?? '' ) === $key ) {
				return $opt['value'] ?? $default_value;
			}
		}

		return $default_value;
	}

	// ─── Statistics ─────────────────────────────────────────────

	/**
	 * Return a summary of what was parsed.
	 *
	 * @return array
	 */
	public function get_stats(): array {
		$total_fields    = 0;
		$total_repeaters = 0;

		foreach ( $this->ufj['groups'] as $group ) {
			foreach ( $group['boxes'] as $box ) {
				foreach ( $box['fields'] as $field ) {
					++$total_fields;
					if ( ( $field['type'] ?? '' ) === 'repeater' ) {
						++$total_repeaters;
					}
				}
			}
		}

		return array(
			'groups'          => count( $this->ufj['groups'] ),
			'option_pages'    => count( $this->ufj['option_pages'] ),
			'total_fields'    => $total_fields,
			'total_repeaters' => $total_repeaters,
			'post_types'      => array_keys( $this->ufj['data_map']['post_types'] ),
			'option_slugs'    => array_keys( $this->ufj['data_map']['option_pages'] ),
		);
	}
}
