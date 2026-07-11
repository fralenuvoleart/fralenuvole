<?php

/**
 * Plugin UI helper functions — generic class dispatcher + thin wrappers.
 *
 * Replaces repetitive frl_class_exists() guard boilerplate across 30+ facade
 * functions. Each wrapper delegates to _frl_ui_dispatch() with explicit class,
 * method, args, fallback, and __FUNCTION__ context.
 *
 * @package Fralenuvole
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generic UI class method dispatcher.
 *
 * Guards the call behind frl_class_exists() using the caller's function name
 * as context, then routes to a static or instance method.
 *
 * @param string $class    Target class name.
 * @param string $method   Method to call.
 * @param array  $args     Positional arguments for the method.
 * @param mixed  $fallback Return value when class is unavailable.
 * @param string $caller   Calling function name (pass __FUNCTION__).
 * @param bool   $static   True for static call, false for new instance call.
 * @return mixed
 */
function _frl_ui_dispatch( $class, $method, $args, $fallback, $caller, $static = true ) {
	if ( ! frl_class_exists( $class, $caller ) ) {
		return $fallback;
	}
	if ( $static ) {
		return $class::$method( ...$args );
	}
	$instance = new $class();
	return $instance->$method( ...$args );
}

// ─── Frl_Tab_Manager wrappers ────────────────────────────────────────────

function frl_tab_get_registered_tabs( $type = null ): array {
	return _frl_ui_dispatch( 'Frl_Tab_Manager', 'get_registered_tabs', array( $type ), array(), __FUNCTION__ );
}

/**
 * Register a new tab in the Tab Manager.
 *
 * Contains unique position-conversion logic — intentionally not dispatched generically.
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

function frl_tab_get_active_tab() {
	return _frl_ui_dispatch( 'Frl_Tab_Manager', 'get_active_tab', array(), 0, __FUNCTION__ );
}

function frl_tab_save_active_tab( $active_tab ) {
	return _frl_ui_dispatch( 'Frl_Tab_Manager', 'save_active_tab', array( $active_tab ), null, __FUNCTION__ );
}

function frl_tab_get_sorted_tabs() {
	return _frl_ui_dispatch( 'Frl_Tab_Manager', 'get_sorted_tabs', array(), null, __FUNCTION__ );
}

function frl_tab_get_validation_rules( $tab_id ) {
	return _frl_ui_dispatch( 'Frl_Tab_Manager', 'get_validation_rules', array( $tab_id ), null, __FUNCTION__ );
}

function frl_tab_hide_tabs_by_capability( $tabs, $has_access ) {
	return _frl_ui_dispatch( 'Frl_Tab_Manager', 'hide_tabs_by_capability', array( $tabs, $has_access ), null, __FUNCTION__ );
}

function frl_tab_render_tab_container_start( $vertical = true, $additional_class = '', $active_tab = null ) {
	return _frl_ui_dispatch( 'Frl_Tab_Manager', 'render_tab_container_start', array( $vertical, $additional_class, $active_tab ), null, __FUNCTION__ );
}

function frl_tab_render_tabs_from_sections( $sections ) {
	return _frl_ui_dispatch( 'Frl_Tab_Manager', 'render_tabs_from_sections', array( $sections, Frl_Tab_Manager::POSITION_FORM ), null, __FUNCTION__ );
}

function frl_tab_render_all_custom_tabs() {
	return _frl_ui_dispatch( 'Frl_Tab_Manager', 'render_all_custom_tabs', array(), null, __FUNCTION__ );
}

function frl_tab_render_tab_container_end() {
	return _frl_ui_dispatch( 'Frl_Tab_Manager', 'render_tab_container_end', array(), null, __FUNCTION__ );
}

// ─── Dashboard / Environment / Debug display wrappers ────────────────────

function frl_admin_dashboard_render( $content = '' ) {
	return _frl_ui_dispatch( 'Frl_Admin_Dashboard', 'render', array( $content ), null, __FUNCTION__, false );
}

function frl_environment_display_render() {
	return _frl_ui_dispatch( 'Frl_Environment_Display', 'render', array(), null, __FUNCTION__, false );
}

function frl_environment_display_get_stats() {
	return _frl_ui_dispatch( 'Frl_Environment_Display', 'get_environment_stats', array( false ), null, __FUNCTION__, false );
}

function frl_environment_display_render_config() {
	return _frl_ui_dispatch( 'Frl_Environment_Display', 'render_full_config_table', array(), null, __FUNCTION__, false );
}

function frl_get_current_readable_error_level() {
	return _frl_ui_dispatch( 'Frl_Debug_Display', 'get_current_readable_error_level', array(), null, __FUNCTION__ );
}

function frl_cache_display_get_dashboard_labels() {
	return _frl_ui_dispatch(
		'Frl_Cache_Display',
		'get_cache_dashboard_labels',
		array(),
		array(
			'persistent' => 'N/A',
			'runtime'    => 'N/A',
		),
		__FUNCTION__
	);
}

function frl_cache_display_get_system_details() {
	return _frl_ui_dispatch( 'Frl_Cache_Display', 'get_cache_system_details', array(), array(), __FUNCTION__ );
}

function frl_cache_get_config() {
	return _frl_ui_dispatch( 'Frl_Cache_Manager', 'get_cache_config', array(), null, __FUNCTION__ );
}

function frl_cache_get_runtime_data() {
	return _frl_ui_dispatch( 'Frl_Cache_Manager', 'get_runtime_cache_data', array(), null, __FUNCTION__ );
}

function frl_cache_safe_db_get_var( $query, $fallback = 0, $operation = 'cache_var_query' ) {
	return _frl_ui_dispatch( 'Frl_Cache_Manager', 'safe_db_get_var', array( $query, $fallback, $operation ), null, __FUNCTION__ );
}

function frl_cache_display_get_groups_info() {
	return _frl_ui_dispatch( 'Frl_Cache_Display', 'get_groups_info', array(), null, __FUNCTION__ );
}

function frl_tag_validator_render() {
	return _frl_ui_dispatch( 'Frl_Tag_Validator', 'render', array(), null, __FUNCTION__, false );
}

function frl_log_manager_render() {
	return _frl_ui_dispatch( 'Frl_Log_Manager', 'render', array(), null, __FUNCTION__, false );
}

function frl_debug_display_render() {
	return _frl_ui_dispatch( 'Frl_Debug_Display', 'render', array(), null, __FUNCTION__, false );
}

function frl_import_export_render() {
	return _frl_ui_dispatch( 'Frl_Import_Export', 'render', array( 'Import/Export', 'Import and export plugin settings and translations.' ), null, __FUNCTION__, false );
}

// ─── Frl_UI_Renderer wrappers ────────────────────────────────────────────

function frl_ui_render_plugin_settings_header() {
	return _frl_ui_dispatch( 'Frl_UI_Renderer', 'render_plugin_settings_header', array(), 'Frl_UI_Renderer not available', __FUNCTION__ );
}

function frl_ui_render_section_header( $title, $class_name = '' ) {
	return _frl_ui_dispatch( 'Frl_UI_Renderer', 'render_section_header', array( $title, $class_name ), 'Frl_UI_Renderer not available', __FUNCTION__ );
}

function frl_ui_render_header_with_action( $title, $button, $class_name = '' ) {
	return _frl_ui_dispatch( 'Frl_UI_Renderer', 'render_header_with_action', array( $title, $button, $class_name ), 'Frl_UI_Renderer not available', __FUNCTION__ );
}

function frl_ui_render_flex_layout( $content_items, $columns = 2, $class_name = '' ) {
	return _frl_ui_dispatch( 'Frl_UI_Renderer', 'render_flex_layout', array( $content_items, $columns, $class_name ), 'Frl_UI_Renderer not available', __FUNCTION__ );
}

function frl_ui_render_widget( $id, $content, $title = '', $class_name = '', $ttl = HOUR_IN_SECONDS, $bypass_cache = false, $group = 'adminui' ) {
	return _frl_ui_dispatch( 'Frl_UI_Renderer', 'render_widget', array( $id, $content, $title, $class_name, $ttl, $bypass_cache, $group ), 'Frl_UI_Renderer not available', __FUNCTION__ );
}

function frl_ui_render_multi_column_header( $columns, $class_name = '' ) {
	return _frl_ui_dispatch( 'Frl_UI_Renderer', 'render_multi_column_header', array( $columns, $class_name ), 'Frl_UI_Renderer not available', __FUNCTION__ );
}

function frl_ui_render_multi_column_row( $columns, $is_header = false, $class_name = '' ) {
	return _frl_ui_dispatch( 'Frl_UI_Renderer', 'render_multi_column_row', array( $columns, $is_header, $class_name ), 'Frl_UI_Renderer not available', __FUNCTION__ );
}

function frl_ui_render_table( $id, $content, $class_name = '', $ttl = HOUR_IN_SECONDS, $bypass_cache = false, $group = 'adminui' ) {
	return _frl_ui_dispatch( 'Frl_UI_Renderer', 'render_table', array( $id, $content, $class_name, $ttl, $bypass_cache, $group ), 'Frl_UI_Renderer not available', __FUNCTION__ );
}

function frl_ui_render_table_row( $name, $value = '', $is_header = false, $class_name = '' ) {
	return _frl_ui_dispatch( 'Frl_UI_Renderer', 'render_table_row', array( $name, $value, $is_header, $class_name ), 'Frl_UI_Renderer not available', __FUNCTION__ );
}

function frl_ui_render_metadata_row( $label, $value, $secondary_value = '', $add_line_break = true, $class_name = '' ) {
	return _frl_ui_dispatch( 'Frl_UI_Renderer', 'render_metadata_row', array( $label, $value, $secondary_value, $add_line_break, $class_name ), 'Frl_UI_Renderer not available', __FUNCTION__ );
}

function frl_ui_render_status_row( $label, $status, $text = null, $class_name = '' ) {
	return _frl_ui_dispatch( 'Frl_UI_Renderer', 'render_status_row', array( $label, $status, $text, $class_name ), 'Frl_UI_Renderer not available', __FUNCTION__ );
}

function frl_ui_render_metadata_field( $label, $value, $class_name = '' ) {
	return _frl_ui_dispatch( 'Frl_UI_Renderer', 'render_metadata_field', array( $label, $value, $class_name ), 'Frl_UI_Renderer not available', __FUNCTION__ );
}

function frl_ui_render_items_list( $items, $label = '', $class_name = '' ) {
	return _frl_ui_dispatch( 'Frl_UI_Renderer', 'render_items_list', array( $items, $label, $class_name ), 'Frl_UI_Renderer not available', __FUNCTION__ );
}

function frl_ui_render_status_dot( $status, $text = '', $text_inside = false ) {
	return _frl_ui_dispatch( 'Frl_UI_Renderer', 'render_status_dot', array( $status, $text, $text_inside ), 'Frl_UI_Renderer not available', __FUNCTION__ );
}

function frl_ui_render_status_dot_boolean( $value, $custom_text = null, $text_inside = true ) {
	return _frl_ui_dispatch( 'Frl_UI_Renderer', 'render_status_dot_boolean', array( $value, $custom_text, $text_inside ), 'Frl_UI_Renderer not available', __FUNCTION__ );
}

function frl_ui_render_code_block( $code, $language = 'js', $id = '', $initially_hidden = true, $in_table_row = false ) {
	return _frl_ui_dispatch( 'Frl_UI_Renderer', 'render_code_block', array( $code, $language, $id, $initially_hidden, $in_table_row ), 'Frl_UI_Renderer not available', __FUNCTION__ );
}

function frl_ui_render_toggle_button( $button_text, $target_id, $button_class = '' ) {
	return _frl_ui_dispatch( 'Frl_UI_Renderer', 'render_toggle_button', array( $button_text, $target_id, $button_class ), 'Frl_UI_Renderer not available', __FUNCTION__ );
}

/**
 * Render a formatting field.
 *
 * Contains unique switch dispatch logic — intentionally not delegated generically.
 *
 * @param array  $field Field definition.
 * @param string $type  Formatting type.
 * @return string
 */
function frl_ui_render_formatting_field( $field, $type = 'section_title' ) {
	if ( ! frl_class_exists( 'Frl_UI_Renderer', __FUNCTION__ ) ) {
		return 'Frl_UI_Renderer not available';
	}
	$method_map = array(
		'section_title' => 'render_section_title',
		'heading'       => 'render_heading',
		'description'   => 'render_description',
		'divider'       => 'render_divider',
	);
	if ( isset( $method_map[ $type ] ) ) {
		return Frl_UI_Renderer::{$method_map[ $type ]}( $field );
	}
	return '';
}

function frl_ui_render_field( $field, $value = '' ) {
	return _frl_ui_dispatch( 'Frl_UI_Renderer', 'render_field', array( $field, $value ), 'Frl_UI_Renderer not available', __FUNCTION__ );
}

function frl_ui_render_validation_messages( $validation, $id = '', $initially_hidden = false, $in_table_row = false ) {
	return _frl_ui_dispatch( 'Frl_UI_Renderer', 'render_validation_messages', array( $validation, $id, $initially_hidden, $in_table_row ), 'Frl_UI_Renderer not available', __FUNCTION__ );
}

function frl_ui_render_validation_message( $message, $type = 'info' ) {
	return _frl_ui_dispatch( 'Frl_UI_Renderer', 'render_validation_message', array( $message, $type ), 'Frl_UI_Renderer not available', __FUNCTION__ );
}
