<?php

/**
 * Core Admin Class helper functions
 * @package Fralenuvole
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handle the saving of plugin settings.
 *
 * Proxies the request to Frl_Settings_Fields::handle_save_options().
 *
 * @return void
 */
function frl_settings_fields_handle_save_options() {
	// Call the static method
	if ( frl_class_exists( 'Frl_Settings_Fields', __FUNCTION__ ) ) {
		Frl_Settings_Fields::handle_save_options();
	} else {
		wp_die( 'Frl_Settings_Fields class not found.' );
	}
}

/**
 * Render the plugin settings page.
 *
 * Proxies the request to Frl_Settings_Fields::render_settings_page().
 *
 * @return void
 */
function frl_settings_fields_render_settings_page() {
	if ( ! frl_class_exists( 'Frl_Settings_Fields', __FUNCTION__ ) ) {
		return;
	}
	Frl_Settings_Fields::render_settings_page();
}

/**
 * Render a WordPress dashboard widget.
 *
 * @param array $widget_config Configuration for the widget to render.
 * @return void
 */
function frl_dashboard_widget_render( $widget_config ) {
	if ( ! frl_class_exists( 'Frl_Dashboard_Renderer', __FUNCTION__ ) ) {
		return;
	}
	Frl_Dashboard_Renderer::render_widget( $widget_config );
}
