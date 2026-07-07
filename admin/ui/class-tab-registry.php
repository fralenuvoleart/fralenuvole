<?php
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fralenuvole Tab Registry
 *
 * Handles tab registration, ordering, section/field association, and validation rules.
 * This is an internal helper class used by Frl_Tab_Manager.
 *
 * @internal Not intended for direct use - use Frl_Tab_Manager facade
 */
class Frl_Tab_Registry {

	// Position constants - reference Frl_Tab_Manager to avoid duplication
	// Using direct values to avoid class loading dependency
	const POSITION_FIRST   = 0;
	const POSITION_DEFAULT = 500;
	const POSITION_FORM    = 1000;
	const POSITION_LAST    = PHP_INT_MAX;

	/**
	 * Tab registry storage
	 *
	 * @var array
	 */
	private $tab_registry = array(
		'form'   => array(),
		'custom' => array(),
	);

	/**
	 * Cache for sorted tabs
	 *
	 * @var array|null
	 */
	private $sorted_tabs = null;

	/**
	 * Register a tab in the registry.
	 *
	 * @param string $tab_id Unique identifier for the tab.
	 * @param array $args Tab configuration arguments.
	 * @param string $type Tab type ('custom' or 'form').
	 * @return void
	 */
	public function register( $tab_id, $args = array(), $type = 'custom' ) {
		if ( $type === 'custom' ) {
			$this->register_custom( $tab_id, $args );
			return;
		}

		$defaults = array(
			'title'          => '',
			'description'    => '',
			'callback'       => null,
			'priority'       => 10,
			'title_priority' => 5,
			'position'       => self::POSITION_FORM + count( $this->tab_registry[ $type ] ),
		);

		$args                = wp_parse_args( $args, $defaults );
		$args['title']       = apply_filters( FRL_PREFIX . '_' . $tab_id . '_tab_title', $args['title'] );
		$args['description'] = apply_filters( FRL_PREFIX . '_' . $tab_id . '_tab_description', $args['description'] );

		$this->tab_registry[ $type ][ $tab_id ] = $args;
		$this->sorted_tabs                      = null;
	}

	/**
	 * Register a custom tab and set up its content action hooks.
	 *
	 * @param string $tab_id Unique identifier for the tab.
	 * @param array $args Tab configuration arguments.
	 * @return void
	 */
	public function register_custom( $tab_id, $args = array() ) {
		$defaults = array(
			'title'          => '',
			'description'    => '',
			'callback'       => null,
			'priority'       => 10,
			'title_priority' => 5,
			'position'       => self::POSITION_DEFAULT,
		);

		$args        = wp_parse_args( $args, $defaults );
		$title       = apply_filters( FRL_PREFIX . '_' . $tab_id . '_tab_title', $args['title'] );
		$description = apply_filters( FRL_PREFIX . '_' . $tab_id . '_tab_description', $args['description'] );
		$action_hook = FRL_PREFIX . '_' . $tab_id . '_content';

		if ( ! empty( $title ) ) {
			add_action(
				$action_hook,
				function () use ( $title, $description ) {
					echo '<h2>' . esc_html( $title ) . '</h2>';
					if ( ! empty( $description ) ) {
						echo '<p>' . esc_html( $description ) . '</p>';
					}
				},
				$args['title_priority'],
				0
			);
		}

		if ( ! empty( $args['callback'] ) ) {
			$callback = $args['callback'];
			if ( is_string( $callback ) && function_exists( $callback ) ) {
				add_action( $action_hook, $callback, $args['priority'], 1 );
			} else {
				add_action(
					$action_hook,
					function () use ( $callback, $tab_id ) {
						if ( is_callable( $callback ) ) {
							call_user_func( $callback );
						} elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
							echo '<p class="error">Error: Callback "' . esc_html( is_string( $callback ) ? $callback : 'Object' ) . '" for tab "' . esc_html( $tab_id ) . '" is not callable.</p>';
						}
					},
					$args['priority'],
					2
				);
			}
		}

		$this->tab_registry['custom'][ $tab_id ] = array_merge(
			$args,
			array(
				'title'       => $title,
				'description' => $description,
			)
		);
		$this->sorted_tabs                       = null;
	}

	/**
	 * Retrieve registered tabs, optionally filtered by type.
	 *
	 * @param string|null $type Tab type to filter by.
	 * @return array List of registered tabs.
	 */
	public function get_tabs( $type = null ) {
		if ( $type !== null && isset( $this->tab_registry[ $type ] ) ) {
			return $this->tab_registry[ $type ];
		}
		return $this->tab_registry;
	}

	/**
	 * Retrieve all registered tabs sorted by their position.
	 *
	 * @return array[] List of tabs with shape {id: string, type: string, config: array, position: int}.
	 */
	public function get_sorted_tabs() {
		if ( $this->sorted_tabs !== null ) {
			return $this->sorted_tabs;
		}

		$all_tabs = array();

		foreach ( $this->tab_registry['custom'] as $tab_id => $config ) {
			$position   = isset( $config['position'] ) ? (int) $config['position'] : self::POSITION_DEFAULT;
			$all_tabs[] = array(
				'id'       => $tab_id,
				'type'     => 'custom',
				'config'   => $config,
				'position' => $position,
			);
		}

		foreach ( $this->tab_registry['form'] as $tab_id => $config ) {
			$position   = isset( $config['position'] ) ? (int) $config['position'] : self::POSITION_FORM;
			$all_tabs[] = array(
				'id'       => $tab_id,
				'type'     => 'form',
				'config'   => $config,
				'position' => $position,
			);
		}

		usort(
			$all_tabs,
			function ( $a, $b ) {
				return $a['position'] - $b['position'];
			}
		);

		$this->sorted_tabs = $all_tabs;
		return $this->sorted_tabs;
	}

	/**
	 * Associate one or more sections with a specific tab.
	 *
	 * @param string $tab_id The tab identifier.
	 * @param string[] $sections Array of section identifiers.
	 * @return bool True if the tab exists and sections were associated.
	 */
	public function associate_sections( $tab_id, array $sections ) {
		$tab_exists = isset( $this->tab_registry['form'][ $tab_id ] ) ||
			isset( $this->tab_registry['custom'][ $tab_id ] );

		if ( ! $tab_exists ) {
			return false;
		}

		$tab_type = isset( $this->tab_registry['form'][ $tab_id ] ) ? 'form' : 'custom';

		if ( ! isset( $this->tab_registry[ $tab_type ][ $tab_id ]['sections'] ) ) {
			$this->tab_registry[ $tab_type ][ $tab_id ]['sections'] = $sections;
		} else {
			$this->tab_registry[ $tab_type ][ $tab_id ]['sections'] = array_unique(
				array_merge( $this->tab_registry[ $tab_type ][ $tab_id ]['sections'], $sections )
			);
		}

		return true;
	}

	/**
	 * Retrieve sections associated with a specific tab.
	 *
	 * @param string $tab_id The tab identifier.
	 * @return string[]|null List of section identifiers or null if tab not found.
	 */
	public function get_sections( $tab_id ) {
		if ( isset( $this->tab_registry['form'][ $tab_id ] ) ) {
			return $this->tab_registry['form'][ $tab_id ]['sections'] ?? array();
		}
		if ( isset( $this->tab_registry['custom'][ $tab_id ] ) ) {
			return $this->tab_registry['custom'][ $tab_id ]['sections'] ?? array();
		}
		return null;
	}

	/**
	 * Add a field group configuration to a specific tab.
	 *
	 * @param string $tab_id The tab identifier.
	 * @param array $field_group Field group configuration (must contain 'id' and 'fields').
	 * @return bool True if the tab exists and field group was added.
	 */
	public function add_field_group( $tab_id, array $field_group ) {
		$tab_exists = isset( $this->tab_registry['form'][ $tab_id ] ) ||
			isset( $this->tab_registry['custom'][ $tab_id ] );

		if ( ! $tab_exists ) {
			return false;
		}

		if ( ! isset( $field_group['id'] ) || ! frl_is_array_not_empty( $field_group, 'fields' ) ) {
			return false;
		}

		$tab_type = isset( $this->tab_registry['form'][ $tab_id ] ) ? 'form' : 'custom';

		if ( ! isset( $this->tab_registry[ $tab_type ][ $tab_id ]['field_groups'] ) ) {
			$this->tab_registry[ $tab_type ][ $tab_id ]['field_groups'] = array();
		}

		$this->tab_registry[ $tab_type ][ $tab_id ]['field_groups'][ $field_group['id'] ] = $field_group;
		return true;
	}

	/**
	 * Retrieve field groups associated with a specific tab.
	 *
	 * @param string $tab_id The tab identifier.
	 * @return array|null List of field groups or null if tab not found.
	 */
	public function get_field_groups( $tab_id ) {
		if ( isset( $this->tab_registry['form'][ $tab_id ] ) ) {
			return $this->tab_registry['form'][ $tab_id ]['field_groups'] ?? array();
		}
		if ( isset( $this->tab_registry['custom'][ $tab_id ] ) ) {
			return $this->tab_registry['custom'][ $tab_id ]['field_groups'] ?? array();
		}
		return null;
	}

	/**
	 * Define validation rules for a specific tab.
	 *
	 * @param string $tab_id The tab identifier.
	 * @param array $validation_rules Validation rules configuration.
	 * @return bool True if the tab exists and rules were set.
	 */
	public function set_validation_rules( $tab_id, array $validation_rules ) {
		$tab_exists = isset( $this->tab_registry['form'][ $tab_id ] ) ||
			isset( $this->tab_registry['custom'][ $tab_id ] );

		if ( ! $tab_exists ) {
			return false;
		}

		$tab_type = isset( $this->tab_registry['form'][ $tab_id ] ) ? 'form' : 'custom';
		$this->tab_registry[ $tab_type ][ $tab_id ]['validation_rules'] = $validation_rules;
		return true;
	}

	/**
	 * Retrieve validation rules for a specific tab.
	 *
	 * @param string $tab_id The tab identifier.
	 * @return array|null Validation rules or null if tab not found.
	 */
	public function get_validation_rules( $tab_id ) {
		if ( isset( $this->tab_registry['form'][ $tab_id ] ) ) {
			return $this->tab_registry['form'][ $tab_id ]['validation_rules'] ?? array();
		}
		if ( isset( $this->tab_registry['custom'][ $tab_id ] ) ) {
			return $this->tab_registry['custom'][ $tab_id ]['validation_rules'] ?? array();
		}
		return null;
	}

	/**
	 * Clear the internal cache of sorted tabs to force recalculation.
	 *
	 * @return void
	 */
	public function invalidate_cache() {
		$this->sorted_tabs = null;
	}
}
