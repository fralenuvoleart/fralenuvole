<?php

/**
 * Plugin UI helper functions
 * @package Fralenuvole
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get registered tabs.
 *
 * @param string|null $type Optional type filter (e.g., 'custom').
 * @return array<string, array> Registered tabs.
 */
function frl_tab_get_registered_tabs( $type = null ): array {
	if ( ! frl_class_exists( 'Frl_Tab_Manager', __FUNCTION__ ) ) {
		return array();
	}
	/** @var array */
	return Frl_Tab_Manager::get_registered_tabs( $type );
}

/**
 * Register a new tab in the Tab Manager.
 *
 * @param string $tab  Tab identifier.
 * @param array  $args Tab configuration (title, description, position).
 * @return void
 */
function frl_tab_register_tab( $tab, $args ) {
	if ( ! frl_class_exists( 'Frl_Tab_Manager', __FUNCTION__ ) ) {
		return;
	}
	if ( ! isset( Frl_Tab_Manager::get_registered_tabs( 'custom' )[ $tab ] ) ) {
		// Handle position value conversion
		$position = $args['position'] ?? Frl_Tab_Manager::POSITION_DEFAULT;
		if ( is_string( $position ) && defined( "Frl_Tab_Manager::{$position}" ) ) {
			$position = constant( "Frl_Tab_Manager::{$position}" );
		}

		Frl_Tab_Manager::register_tab(
			$tab,
			array(
				'title'       => $args['title'] ?? '',
				'description' => $args['description'] ?? '',
				'position'    => $position,
			)
		);
	}
}

/**
 * Get the currently active tab index (0-based).
 *
 * @return int Active tab index.
 */
function frl_tab_get_active_tab() {
	if ( ! frl_class_exists( 'Frl_Tab_Manager', __FUNCTION__ ) ) {
		return 0;
	}
	return Frl_Tab_Manager::get_active_tab();
}

/**
 * Save the active tab index.
 *
 * @param int $active_tab Tab index to save.
 * @return void
 */
function frl_tab_save_active_tab( $active_tab ) {
	if ( ! frl_class_exists( 'Frl_Tab_Manager', __FUNCTION__ ) ) {
		return;
	}
	Frl_Tab_Manager::save_active_tab( $active_tab );
}

/**
 * Get the list of registered tabs sorted by position.
 *
 * @return array<int, array> Sorted tabs.
 */
function frl_tab_get_sorted_tabs() {
	if ( ! frl_class_exists( 'Frl_Tab_Manager', __FUNCTION__ ) ) {
		return;
	}
	return Frl_Tab_Manager::get_sorted_tabs();
}

/**
 * Get validation rules for a specific tab.
 *
 * @param string $tab_id Tab identifier.
 * @return array|null Validation rules or null if not found.
 */
function frl_tab_get_validation_rules( $tab_id ) {
	if ( ! frl_class_exists( 'Frl_Tab_Manager', __FUNCTION__ ) ) {
		return;
	}
	return Frl_Tab_Manager::get_validation_rules( $tab_id );
}

/**
 * Hide specific tabs based on user capabilities.
 *
 * @param array  $tabs       Array of tabs to evaluate.
 * @param bool|null $has_access Whether the current user has the required access.
 * @return void
 */
function frl_tab_hide_tabs_by_capability( $tabs, $has_access ) {
	if ( ! frl_class_exists( 'Frl_Tab_Manager', __FUNCTION__ ) ) {
		return;
	}
	Frl_Tab_Manager::hide_tabs_by_capability( $tabs, $has_access );
}

/**
 * Render the start of the tab container.
 *
 * @param bool   $vertical         Whether to use vertical tabs.
 * @param string $additional_class Optional CSS class for the container.
 * @param int|null $active_tab     Optional active tab index.
 * @return void
 */
function frl_tab_render_tab_container_start(
	$vertical = true,
	$additional_class = '',
	$active_tab = null
) {
	if ( ! frl_class_exists( 'Frl_Tab_Manager', __FUNCTION__ ) ) {
		return;
	}
	Frl_Tab_Manager::render_tab_container_start(
		$vertical,
		$additional_class,
		$active_tab,
	);
}

/**
 * Render tabs based on provided section keys.
 *
 * @param array $sections Array of section keys.
 * @return void
 */
function frl_tab_render_tabs_from_sections( $sections ) {
	if ( ! frl_class_exists( 'Frl_Tab_Manager', __FUNCTION__ ) ) {
		return;
	}
	Frl_Tab_Manager::render_tabs_from_sections(
		$sections,
		Frl_Tab_Manager::POSITION_FORM,
	);
}

/**
 * Render all registered custom tabs.
 *
 * @return void
 */
function frl_tab_render_all_custom_tabs() {
	if ( ! frl_class_exists( 'Frl_Tab_Manager', __FUNCTION__ ) ) {
		return;
	}
	Frl_Tab_Manager::render_all_custom_tabs();
}

/**
 * Render the end of the tab container.
 *
 * @return void
 */
function frl_tab_render_tab_container_end() {
	if ( ! frl_class_exists( 'Frl_Tab_Manager', __FUNCTION__ ) ) {
		return;
	}
	Frl_Tab_Manager::render_tab_container_end();
}

/**
 * Render the admin dashboard
 */
function frl_admin_dashboard_render( $content = '' ) {
	if ( ! frl_class_exists( 'Frl_Admin_Dashboard', __FUNCTION__ ) ) {
		return;
	}
	$dashboard = new Frl_Admin_Dashboard();
	return $dashboard->render( $content );
}

/**
 * Render the environment display
 */
function frl_environment_display_render() {
	if ( ! frl_class_exists( 'Frl_Environment_Display', __FUNCTION__ ) ) {
		return;
	}
	$environment = new Frl_Environment_Display();
	return $environment->render();
}

/**
 * Get the environment stats
 */
function frl_environment_display_get_stats() {
	if ( ! frl_class_exists( 'Frl_Environment_Display', __FUNCTION__ ) ) {
		return;
	}
	$env_display = new Frl_Environment_Display();
	return $env_display->get_environment_stats( false );
}

/**
 * Render the environment configuration table
 */
function frl_environment_display_render_config() {
	if ( ! frl_class_exists( 'Frl_Environment_Display', __FUNCTION__ ) ) {
		return;
	}
	$env_display = new Frl_Environment_Display();
	return $env_display->render_full_config_table();
}

/**
 * Get the current readable error level
 */
function frl_get_current_readable_error_level() {
	if ( ! frl_class_exists( 'Frl_Debug_Display', __FUNCTION__ ) ) {
		return;
	}
	return Frl_Debug_Display::get_current_readable_error_level();
}

/**
 * Get the persistent and runtime cache labels for the dashboard.
 *
 * @return array An associative array with 'persistent' and 'runtime' labels.
 */
function frl_cache_display_get_dashboard_labels() {
	if ( ! frl_class_exists( 'Frl_Cache_Display', __FUNCTION__ ) ) {
		return array(
			'persistent' => 'N/A',
			'runtime'    => 'N/A',
		);
	}
	return Frl_Cache_Display::get_cache_dashboard_labels();
}

/**
 * Get comprehensive details about the active caching system for display.
 *
 * @return array System details.
 */
function frl_cache_display_get_system_details() {
	if ( ! frl_class_exists( 'Frl_Cache_Display', __FUNCTION__ ) ) {
		return array();
	}
	return Frl_Cache_Display::get_cache_system_details();
}

/**
 * Get the cache config
 */
function frl_cache_get_config() {
	if ( ! frl_class_exists( 'Frl_Cache_Manager', __FUNCTION__ ) ) {
		return;
	}
	return Frl_Cache_Manager::get_cache_config();
}

/**
 * Get the cache config
 */
function frl_cache_get_runtime_data() {
	if ( ! frl_class_exists( 'Frl_Cache_Manager', __FUNCTION__ ) ) {
		return;
	}
	return Frl_Cache_Manager::get_runtime_cache_data();
}

/**
 * Safe DB get var
 */
function frl_cache_safe_db_get_var( $query, $fallback = 0, $operation = 'cache_var_query' ) {
	if ( ! frl_class_exists( 'Frl_Cache_Manager', __FUNCTION__ ) ) {
		return;
	}
	return Frl_Cache_Manager::safe_db_get_var( $query, $fallback, $operation );
}


/**
 * Get the cache groups info
 */
function frl_cache_display_get_groups_info() {
	if ( ! frl_class_exists( 'Frl_Cache_Display', __FUNCTION__ ) ) {
		return;
	}
	return Frl_Cache_Display::get_groups_info();
}

/**
 * Render the tag validator
 */
function frl_tag_validator_render() {
	if ( ! frl_class_exists( 'Frl_Tag_Validator', __FUNCTION__ ) ) {
		return;
	}
	$validator = new Frl_Tag_Validator();
	return $validator->render();
}

/**
 * Render the log manager
 */
function frl_log_manager_render() {
	if ( ! frl_class_exists( 'Frl_Log_Manager', __FUNCTION__ ) ) {
		return;
	}
	$log_manager = new Frl_Log_Manager();
	return $log_manager->render();
}

/**
 * Render the debug display
 */
function frl_debug_display_render() {
	if ( ! frl_class_exists( 'Frl_Debug_Display', __FUNCTION__ ) ) {
		return;
	}
	$debug_display = new Frl_Debug_Display();
	return $debug_display->render();
}

/**
 * Render the widget import/export
 */
function frl_import_export_render() {
	if ( ! frl_class_exists( 'Frl_Import_Export', __FUNCTION__ ) ) {
		return;
	}
	$import_export = new Frl_Import_Export();
	return $import_export->render(
		'Import/Export',
		'Import and export plugin settings and translations.'
	);
}


/**
 * Render a UI plugin settings header
 */
function frl_ui_render_plugin_settings_header() {
	if ( ! frl_class_exists( 'Frl_UI_Renderer', __FUNCTION__ ) ) {
		return 'Frl_UI_Renderer not available';
	}
	return Frl_UI_Renderer::render_plugin_settings_header();
}

/**
 * Render a UI plugin settings header
 */
function frl_ui_render_section_header(
	$title,
	$class_name = ''
) {
	if ( ! frl_class_exists( 'Frl_UI_Renderer', __FUNCTION__ ) ) {
		return 'Frl_UI_Renderer not available';
	}
	return Frl_UI_Renderer::render_section_header(
		$title,
		$class_name
	);
}

/**
 * Render a UI plugin settings header
 */
function frl_ui_render_header_with_action(
	$title,
	$button,
	$class_name = ''
) {
	if ( ! frl_class_exists( 'Frl_UI_Renderer', __FUNCTION__ ) ) {
		return 'Frl_UI_Renderer not available';
	}
	return Frl_UI_Renderer::render_header_with_action(
		$title,
		$button,
		$class_name
	);
}

/**
 * Render a UI flex layout
 *
 * @param array $content_items Content items
 * @param int $columns Number of columns
 * @param string $class_name Class
 */
function frl_ui_render_flex_layout(
	$content_items,
	$columns = 2,
	$class_name = ''
) {
	if ( ! frl_class_exists( 'Frl_UI_Renderer', __FUNCTION__ ) ) {
		return 'Frl_UI_Renderer not available';
	}
	return Frl_UI_Renderer::render_flex_layout(
		$content_items,
		$columns,
		$class_name
	);
}

/**
 * Render a UI widget
 *
 * @param string $id Widget ID
 * @param string $content Widget content
 * @param string $title Widget title
 * @param string $class_name Widget class
 * @param int $ttl Widget TTL
 * @return string Rendered widget HTML
 */
function frl_ui_render_widget(
	$id,
	$content,
	$title = '',
	$class_name = '',
	$ttl = HOUR_IN_SECONDS,
	$bypass_cache = false,
	$group = 'adminui'
) {
	if ( ! frl_class_exists( 'Frl_UI_Renderer', __FUNCTION__ ) ) {
		return 'Frl_UI_Renderer not available';
	}
	return Frl_UI_Renderer::render_widget(
		$id,
		$content,
		$title,
		$class_name,
		$ttl,
		$bypass_cache,
		$group
	);
}

/**
 * Render a UI multi column header
 *
 * @param array $columns Columns
 * @param string $class_name Class
 */
function frl_ui_render_multi_column_header(
	$columns,
	$class_name = ''
) {
	if ( ! frl_class_exists( 'Frl_UI_Renderer', __FUNCTION__ ) ) {
		return 'Frl_UI_Renderer not available';
	}
	return Frl_UI_Renderer::render_multi_column_header(
		$columns,
		$class_name
	);
}

/**
 * Render a UI multi column row
 *
 * @param array $columns Columns
 * @param bool $is_header Is header
 * @param string $class_name Class
 */
function frl_ui_render_multi_column_row(
	$columns,
	$is_header = false,
	$class_name = ''
) {
	if ( ! frl_class_exists( 'Frl_UI_Renderer', __FUNCTION__ ) ) {
		return 'Frl_UI_Renderer not available';
	}
	return Frl_UI_Renderer::render_multi_column_row(
		$columns,
		$is_header,
		$class_name
	);
}

/**
 * Render a UI table
 *
 * @param string $id Widget ID
 * @param string $content Widget content
 * @param string $class_name Widget class
 * @param int $ttl Widget TTL
 * @param bool $bypass_cache Bypass cache flag
 * @param string $group Cache group
 * @return string Rendered widget HTML
 */
function frl_ui_render_table(
	$id,
	$content,
	$class_name = '',
	$ttl = HOUR_IN_SECONDS,
	$bypass_cache = false,
	$group = 'adminui'
) {
	if ( ! frl_class_exists( 'Frl_UI_Renderer', __FUNCTION__ ) ) {
		return 'Frl_UI_Renderer not available';
	}
	return Frl_UI_Renderer::render_table(
		$id,
		$content,
		$class_name,
		$ttl,
		$bypass_cache,
		$group
	);
}

/**
 * Render a UI table row
 *
 * @param string $name Row name
 * @param string $value Row value
 * @param bool $is_header Is header
 * @param string $class_name Row class
 * @return string Rendered row HTML
 */
function frl_ui_render_table_row(
	$name,
	$value = '',
	$is_header = false,
	$class_name = ''
) {
	if ( ! frl_class_exists( 'Frl_UI_Renderer', __FUNCTION__ ) ) {
		return 'Frl_UI_Renderer not available';
	}
	return Frl_UI_Renderer::render_table_row(
		$name,
		$value,
		$is_header,
		$class_name
	);
}

/**
 * Render a metadata row
 *
 * @param string $label Label
 * @param string $value Value
 * @param string $secondary_value Secondary value
 * @param bool $add_line_break Add line break
 * @param string $class_name Class
 */
function frl_ui_render_metadata_row(
	$label,
	$value,
	$secondary_value = '',
	$add_line_break = true,
	$class_name = ''
) {
	if ( ! frl_class_exists( 'Frl_UI_Renderer', __FUNCTION__ ) ) {
		return 'Frl_UI_Renderer not available';
	}
	return Frl_UI_Renderer::render_metadata_row(
		$label,
		$value,
		$secondary_value,
		$add_line_break,
		$class_name,
	);
}

/**
 * Render a status row
 *
 * @param string $label Label
 * @param bool|string $status Status
 * @param string|null $text Text
 */
function frl_ui_render_status_row(
	$label,
	$status,
	$text = null,
	$class_name = ''
) {
	if ( ! frl_class_exists( 'Frl_UI_Renderer', __FUNCTION__ ) ) {
		return 'Frl_UI_Renderer not available';
	}
	return Frl_UI_Renderer::render_status_row(
		$label,
		$status,
		$text,
		$class_name
	);
}


/**
 * Render a metadata field row
 *
 * @param string $label Field label
 * @param string $value Field value
 * @param string $class_name CSS class
 */
function frl_ui_render_metadata_field(
	$label,
	$value,
	$class_name = ''
) {
	if ( ! frl_class_exists( 'Frl_UI_Renderer', __FUNCTION__ ) ) {
		return 'Frl_UI_Renderer not available';
	}
	return Frl_UI_Renderer::render_metadata_field(
		$label,
		$value,
		$class_name
	);
}

/**
 * Render a items list
 *
 * @param array $items Items
 * @param string $label Label
 * @param string $class_name Class
 */
function frl_ui_render_items_list(
	$items,
	$label = '',
	$class_name = ''
) {
	if ( ! frl_class_exists( 'Frl_UI_Renderer', __FUNCTION__ ) ) {
		return 'Frl_UI_Renderer not available';
	}
	return Frl_UI_Renderer::render_items_list(
		$items,
		$label,
		$class_name
	);
}

/**
 * Render a status dot
 *
 * @param string $status Status
 * @param string $text Text
 * @param bool $text_inside Text inside
 */
function frl_ui_render_status_dot(
	$status,
	$text = '',
	$text_inside = false
) {
	if ( ! frl_class_exists( 'Frl_UI_Renderer', __FUNCTION__ ) ) {
		return 'Frl_UI_Renderer not available';
	}
	return Frl_UI_Renderer::render_status_dot(
		$status,
		$text,
		$text_inside,
	);
}

/**
 * Render a status dot boolean
 *
 * @param bool|string $value Value
 * @param string|null $custom_text Custom text
 * @param bool $text_inside Text inside
 */
function frl_ui_render_status_dot_boolean(
	$value,
	$custom_text = null,
	$text_inside = true
) {
	if ( ! frl_class_exists( 'Frl_UI_Renderer', __FUNCTION__ ) ) {
		return 'Frl_UI_Renderer not available';
	}
	return Frl_UI_Renderer::render_status_dot_boolean(
		$value,
		$custom_text,
		$text_inside
	);
}

/**
 * Render a code block
 *
 * @param string $code Code
 * @param string $language Language
 * @param string|null $id ID
 * @param bool $initially_hidden Initially hidden
 * @param bool $in_table_row In table row
 */
function frl_ui_render_code_block(
	$code,
	$language = 'js',
	$id = '',
	$initially_hidden = true,
	$in_table_row = false
) {
	if ( ! frl_class_exists( 'Frl_UI_Renderer', __FUNCTION__ ) ) {
		return 'Frl_UI_Renderer not available';
	}
	return Frl_UI_Renderer::render_code_block(
		$code,
		$language,
		$id,
		$initially_hidden,
		$in_table_row
	);
}

/**
 * Render a toggle button
 *
 * @param string $button_text Button text
 * @param string $target_id Target ID
 * @param string $button_class Button class
 */
function frl_ui_render_toggle_button(
	$button_text,
	$target_id,
	$button_class = '',
) {
	if ( ! frl_class_exists( 'Frl_UI_Renderer', __FUNCTION__ ) ) {
		return 'Frl_UI_Renderer not available';
	}
	return Frl_UI_Renderer::render_toggle_button(
		$button_text,
		$target_id,
		$button_class
	);
}

/**
 * Render a formatting field
 *
 * @param array $field Field
 * @param string $type Type
 */
function frl_ui_render_formatting_field(
	$field,
	$type = 'section_title',
) {
	if ( ! frl_class_exists( 'Frl_UI_Renderer', __FUNCTION__ ) ) {
		return 'Frl_UI_Renderer not available';
	}
	switch ( $type ) {
		case 'section_title':
			$content = Frl_UI_Renderer::render_section_title( $field );
			break;
		case 'heading':
			$content = Frl_UI_Renderer::render_heading( $field );
			break;
		case 'description':
			$content = Frl_UI_Renderer::render_description( $field );
			break;
		case 'divider':
			$content = Frl_UI_Renderer::render_divider( $field );
			break;
		default:
			$content = '';
	}
	return $content;
}

function frl_ui_render_field(
	$field,
	$value = '',
) {
	if ( ! frl_class_exists( 'Frl_UI_Renderer', __FUNCTION__ ) ) {
		return 'Frl_UI_Renderer not available';
	}
	return Frl_UI_Renderer::render_field( $field, $value );
}

/**
 * Render a validation messages
 *
 * @param array $validation Validation
 * @param string $id ID
 * @param bool $initially_hidden Initially hidden
 * @param bool $in_table_row In table row
 */
function frl_ui_render_validation_messages(
	$validation,
	$id = '',
	$initially_hidden = false,
	$in_table_row = false
) {
	if ( ! frl_class_exists( 'Frl_UI_Renderer', __FUNCTION__ ) ) {
		return 'Frl_UI_Renderer not available';
	}
	return Frl_UI_Renderer::render_validation_messages(
		$validation,
		$id,
		$initially_hidden,
		$in_table_row
	);
}

/**
 * Render a validation message
 *
 * @param string $message Message
 * @param string $type Type
 */
function frl_ui_render_validation_message(
	$message,
	$type = 'info'
) {
	if ( ! frl_class_exists( 'Frl_UI_Renderer', __FUNCTION__ ) ) {
		return 'Frl_UI_Renderer not available';
	}
	return Frl_UI_Renderer::render_validation_message(
		$message,
		$type
	);
}
