<?php
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render a Custom HTML dashboard widget based on a widget number.
 *
 * Retrieves content from plugin options and processes shortcodes.
 *
 * @param int $widget_number The widget index (typically 1, 2, or 3).
 * @return string The processed HTML content for the widget.
 */
function frl_render_custom_html_widget( $widget_number ) {
	$content_key = "dash_widget_custom_html_content_{$widget_number}";
	$content     = frl_get_option( $content_key );

	if ( empty( trim( $content ) ) ) {
		return '<p>' . esc_html__( 'No content configured.', FRL_PREFIX ) . '</p>';
	}

	// Allow shortcodes to be processed
	$html = do_shortcode( $content );

	return $html;
}

/**
 * Render the first Custom HTML dashboard widget.
 *
 * @return string The HTML content for widget 1.
 */
function frl_render_custom_html_widget_1() {
	return frl_render_custom_html_widget( 1 );
}

/**
 * Render the second Custom HTML dashboard widget.
 *
 * @return string The HTML content for widget 2.
 */
function frl_render_custom_html_widget_2() {
	return frl_render_custom_html_widget( 2 );
}

/**
 * Render the third Custom HTML dashboard widget.
 *
 * @return string The HTML content for widget 3.
 */
function frl_render_custom_html_widget_3() {
	return frl_render_custom_html_widget( 3 );
}
