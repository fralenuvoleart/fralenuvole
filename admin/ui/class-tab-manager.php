<?php
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fralenuvole Tab Manager
 *
 * Main facade class that delegates to focused helper classes.
 * All public static methods are preserved for backward compatibility.
 */
class Frl_Tab_Manager {

	// Position constants (preserved for backward compatibility)
	const POSITION_FIRST   = 0;
	const POSITION_DEFAULT = 500;
	const POSITION_FORM    = 1000;
	const POSITION_LAST    = PHP_INT_MAX;

	/**
	 * Singleton instance
	 *
	 * @var Frl_Tab_Manager|null
	 */
	private static $instance = null;

	/**
	 * Internal registry helper
	 *
	 * @var Frl_Tab_Registry|null
	 */
	private $registry = null;

	/**
	 * Internal renderer helper
	 *
	 * @var Frl_Tab_Renderer|null
	 */
	private $renderer = null;

	/**
	 * Get the singleton instance
	 *
	 * @return Frl_Tab_Manager
	 */
	public static function get_instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor
	 */
	private function __construct() {
		// Lazy-load helpers
	}

	/**
	 * Get registry instance
	 *
	 * @return Frl_Tab_Registry
	 */
	private function get_registry() {
		if ( $this->registry === null ) {
			$this->registry = new Frl_Tab_Registry();
		}
		return $this->registry;
	}

	/**
	 * Get renderer instance
	 *
	 * @return Frl_Tab_Renderer
	 */
	private function get_renderer() {
		if ( $this->renderer === null ) {
			$this->renderer = new Frl_Tab_Renderer();
		}
		return $this->renderer;
	}

	// =========================================================================
	// REGISTRATION METHODS (delegate to Frl_Tab_Registry)
	// =========================================================================

	/**
	 * Register a tab via the registry facade.
	 *
	 * @param string $tab_id Unique identifier for the tab.
	 * @param array $args Tab configuration arguments.
	 * @param string $type Tab type (e.g., 'custom', 'core').
	 * @return void
	 */
	public static function register_tab( $tab_id, $args = array(), $type = 'custom' ): void {
		self::get_instance()->get_registry()->register( $tab_id, $args, $type );
	}

	/**
	 * Register a custom tab via the registry facade.
	 *
	 * @param string $tab_id Unique identifier for the tab.
	 * @param array $args Tab configuration arguments.
	 * @return void
	 */
	public static function register_custom_tab( $tab_id, $args = array() ): void {
		self::get_instance()->get_registry()->register_custom( $tab_id, $args );
	}

	// =========================================================================
	// QUERY METHODS (delegate to Frl_Tab_Registry)
	// =========================================================================

	/**
	 * Retrieve registered tabs, optionally filtered by type.
	 *
	 * @param string|null $type Tab type to filter by.
	 * @return array List of registered tabs.
	 */
	public static function get_registered_tabs( $type = null ) {
		return self::get_instance()->get_registry()->get_tabs( $type );
	}

	/**
	 * Retrieve tabs sorted by their defined position.
	 *
	 * @return array Sorted list of tabs.
	 */
	public static function get_sorted_tabs() {
		return self::get_instance()->get_registry()->get_sorted_tabs();
	}

	// =========================================================================
	// SECTION/FIELD METHODS (delegate to Frl_Tab_Registry)
	// =========================================================================

	/**
	 * Associate a set of sections with a specific tab.
	 *
	 * @param string $tab_id The tab identifier.
	 * @param string[] $sections Array of section identifiers.
	 * @return bool True on success.
	 */
	public static function associate_sections_with_tab( $tab_id, array $sections ): bool {
		return self::get_instance()->get_registry()->associate_sections( $tab_id, $sections );
	}

	/**
	 * Retrieve sections associated with a specific tab.
	 *
	 * @param string $tab_id The tab identifier.
	 * @return array|null List of sections or null if none found.
	 */
	public static function get_tab_sections( $tab_id ) {
		return self::get_instance()->get_registry()->get_sections( $tab_id );
	}

	/**
	 * Add a field group configuration to a specific tab.
	 *
	 * @param string $tab_id The tab identifier.
	 * @param array $field_group Field group configuration.
	 * @return bool True on success.
	 */
	public static function add_field_group( $tab_id, array $field_group ) {
		return self::get_instance()->get_registry()->add_field_group( $tab_id, $field_group );
	}

	/**
	 * Retrieve field groups associated with a specific tab.
	 *
	 * @param string $tab_id The tab identifier.
	 * @return array List of field groups.
	 */
	public static function get_tab_field_groups( $tab_id ) {
		return self::get_instance()->get_registry()->get_field_groups( $tab_id );
	}

	/**
	 * Define validation rules for a specific tab.
	 *
	 * @param string $tab_id The tab identifier.
	 * @param array $validation_rules Validation rules configuration.
	 * @return bool True on success.
	 */
	public static function set_tab_validation_rules( $tab_id, array $validation_rules ) {
		return self::get_instance()->get_registry()->set_validation_rules( $tab_id, $validation_rules );
	}

	/**
	 * Retrieve validation rules for a specific tab.
	 *
	 * @param string $tab_id The tab identifier.
	 * @return array|null Validation rules or null if none defined.
	 */
	public static function get_validation_rules( $tab_id ) {
		return self::get_instance()->get_registry()->get_validation_rules( $tab_id );
	}

	// =========================================================================
	// RENDERING METHODS (delegate to Frl_Tab_Renderer)
	// =========================================================================

	/**
	 * Render all registered custom tabs.
	 *
	 * @return void
	 */
	public static function render_all_custom_tabs(): void {
		$custom_tabs = self::get_instance()->get_registry()->get_tabs( 'custom' );
		self::get_instance()->get_renderer()->render_all_custom( $custom_tabs );
	}

	/**
	 * Render a specific custom tab using a provided or default action hook.
	 *
	 * @param string $tab_id The tab identifier.
	 * @param string $action_hook Optional custom action hook for content.
	 * @return void
	 */
	public static function render_custom_tab( $tab_id, $action_hook = '' ): void {
		if ( empty( $action_hook ) ) {
			$action_hook = FRL_PREFIX . '_' . $tab_id . '_content';
		}
		self::get_instance()->get_renderer()->render_custom_tab( $tab_id, $action_hook );
	}

	/**
	 * Render the opening HTML for the tab container.
	 *
	 * @param bool $vertical Whether the layout is vertical.
	 * @param string $additional_class Extra CSS classes for the container.
	 * @param int|null $active_tab The active tab index.
	 * @return void
	 */
	public static function render_tab_container_start( $vertical = true, $additional_class = '', $active_tab = null ) {
		if ( $active_tab === null ) {
			$active_tab = self::get_active_tab();
		}
		self::get_instance()->get_renderer()->render_container_start( $vertical, $additional_class, $active_tab );
	}

	/**
	 * Render the closing HTML for the tab container.
	 *
	 * @return void
	 */
	public static function render_tab_container_end() {
		self::get_instance()->get_renderer()->render_container_end();
	}

	/**
	 * Render the tab navigation menu, cached based on user access.
	 *
	 * @return string The generated HTML for the navigation.
	 */
	public static function render_tab_navigation() {
		$current_user_id = get_current_user_id();
		$has_access      = frl_has_access();
		$access_suffix   = $has_access ? '_admin' : '_user_' . $current_user_id;
		$cache_key       = 'ui_tabs' . $access_suffix;

		return frl_cache_remember(
			'adminui',
			$cache_key,
			function () {
				$all_tabs = self::get_instance()->get_registry()->get_sorted_tabs();
				return self::get_instance()->get_renderer()->generate_navigation( $all_tabs );
			}
		);
	}

	/**
	 * Register and render tabs based on a provided sections array.
	 *
	 * @param array $sections Array of section configurations.
	 * @param int $position_start Starting position for the tabs.
	 * @return void
	 */
	public static function render_tabs_from_sections( $sections, $position_start = 1000 ): void {
		$registry = self::get_instance()->get_registry();
		$renderer = self::get_instance()->get_renderer();

		$renderer->render_tabs_from_sections(
			$sections,
			$position_start,
			function ( $id, $args, $type ) use ( $registry ) {
				$registry->register( $id, $args, $type );
			},
			function () {
				return self::render_tab_navigation();
			}
		);
	}

	/**
	 * Render field groups for a specific tab using a provided callback.
	 *
	 * @param string $tab_id The tab identifier.
	 * @param callable $field_callback Callback function to render each field.
	 * @param array $args Additional arguments passed to the callback.
	 * @return void
	 */
	public static function render_tab_field_groups( $tab_id, $field_callback, $args = array() ): void {
		$field_groups = self::get_instance()->get_registry()->get_field_groups( $tab_id );
		self::get_instance()->get_renderer()->render_field_groups( $field_groups, $field_callback, $args );
	}

	// =========================================================================
	// STATE METHODS
	// =========================================================================

	/**
	 * Determine the currently active tab from request or transient storage.
	 *
	 * @return int The active tab index.
	 */
	public static function get_active_tab() {
		$active_tab = 0;

		if ( isset( $_GET['tab'] ) && is_numeric( $_GET['tab'] ) ) {
			$active_tab = max( 0, intval( $_GET['tab'] ) );
		}

		if ( isset( $_GET['settings-updated'] ) && $_GET['settings-updated'] === 'true' ) {
			$saved_tab = frl_get_transient( 'active_settings_tab' );
			if ( $saved_tab !== false ) {
				$active_tab = $saved_tab;
				frl_delete_transient( 'active_settings_tab' );
			}
		}

		return $active_tab;
	}

	/**
	 * Persist the active tab index in a transient for post-save redirection.
	 *
	 * @param mixed $active_tab The tab index to save.
	 * @return void
	 */
	public static function save_active_tab( $active_tab ) {
		frl_set_transient( 'active_settings_tab', intval( $active_tab ), HOUR_IN_SECONDS );
	}

	// =========================================================================
	// UTILITY METHODS
	// =========================================================================

	/**
	 * Attach content to a tab via a WordPress action hook.
	 *
	 * @param string $tab_id The tab identifier.
	 * @param string $content The HTML content to add.
	 * @param int $priority Execution priority.
	 * @return void
	 */
	public static function add_tab_content( $tab_id, $content, $priority = 10 ): void {
		add_action(
			FRL_PREFIX . '_' . $tab_id . '_content',
			function () use ( $content, $tab_id ) {
				echo apply_filters( FRL_PREFIX . '_' . $tab_id . '_content_html', $content );
			},
			$priority,
			0
		);
	}

	/**
	 * Get the action hook name associated with a specific tab.
	 *
	 * @param string $tab_id The tab identifier.
	 * @return string The action hook name.
	 */
	public static function get_tab_action_hook( $tab_id ) {
		return FRL_PREFIX . '_' . $tab_id . '_content';
	}

	/**
	 * Restrict access to specific tabs based on user capabilities.
	 *
	 * @param string[] $hidden_tabs Array of tab identifiers to hide.
	 * @param bool|null $has_access Optional override for access check.
	 * @return void
	 */
	public static function hide_tabs_by_capability( $hidden_tabs, $has_access = null ) {
		if ( $has_access === null ) {
			$has_access = frl_has_access();
		}

		foreach ( $hidden_tabs as $tab_id ) {
			if ( ! $has_access ) {
				add_filter(
					'frl_is_section_restricted',
					function ( $is_restricted, $section_id_to_check ) use ( $tab_id ) {
						if ( $section_id_to_check === $tab_id ) {
							return true;
						}
						return $is_restricted;
					},
					10,
					2
				);
			}
		}

		self::get_instance()->get_registry()->invalidate_cache();
	}
}
