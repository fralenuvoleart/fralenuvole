<?php

/**
 * Fralenuvole Dashboard Renderer Class
 *
 * Provides reusable methods for rendering dashboard widgets and UI elements.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Frl_Dashboard_Renderer {


	/**
	 * Initialize the dashboard renderer.
	 *
	 * @return void
	 */
	public function __construct() {
		// Initialization, if needed
	}

	/**
	 * Render a standard widget section with a title.
	 *
	 * @param string $title The title/header for the section.
	 * @param string $content_html The HTML content for the section.
	 * @param string $section_class Optional CSS class for the section wrapper.
	 * @return void
	 */
	public function render_section( $title, $content_html, $section_class = 'frl-widget-section' ) {
		echo '<div class="' . esc_attr( $section_class ) . '">';
		if ( ! empty( $title ) ) {
			// Use h4 consistent with previous widget structure
			echo '<h4>' . wp_kses_post( $title ) . '</h4>';
		}
		// Use wp_kses_post for allowing reasonable HTML in content
		// Filter removed as inline styles are no longer part of the content
		echo wp_kses_post( $content_html );
		echo '</div>';
	}

	/**
	 * Render a complete dashboard widget with caching and orchestration.
	 *
	 * @param array $widget_config Widget configuration.
	 *                             Required: 'key' (string), 'render_callback' (callable).
	 *                             Optional: 'title' (string), 'render_file' (string), 'cache_ttl' (int), 'refresh_button' (bool).
	 * @return void
	 */
	public static function render_widget( array $widget_config ) {
		$id = $widget_config['key'] ?? 'unknown_' . uniqid();

		$cache_key      = 'widget_' . $id;
		$refresh_button = $widget_config['refresh_button'] ?? false;
		$cache_ttl      = $widget_config['cache_ttl'] ?? 15 * MINUTE_IN_SECONDS;

		$widget_html = frl_cache_remember(
			'admin',
			$cache_key,
			function () use ( $widget_config, $id, $cache_key, $refresh_button ) {
				// Lazy load core widget file if path is provided
				if ( ! empty( $widget_config['render_file'] ) ) {
					if ( is_readable( $widget_config['render_file'] ) ) {
						require_once $widget_config['render_file'];
					} else {
						frl_log(
							"Could not read render file for widget '{id}': {file}",
							array(
								'id'   => $id,
								'file' => $widget_config['render_file'],
							)
						);
						// Return error HTML string
						return "<div class='widget-error'><p class='error'>Error loading widget '{$id}'.</p></div>";
					}
				}

				// Check if the specific render callback is callable
				if ( isset( $widget_config['render_callback'] ) && is_callable( $widget_config['render_callback'] ) ) {
					// Execute the designated callback function and RETURN its string output
					$content = call_user_func( $widget_config['render_callback'] );

					if ( $refresh_button ) {
						$refresh_button_html = '';

						// Add refresh widget button
						$refresh_button_html = self::_render_refresh_button( 'admin', $cache_key );

						$refresh_button_html = '<div class="frl-widget-action">' . $refresh_button_html . '</div>';

						$content .= '<div class="frl-dashboard-widget-footer">' . $refresh_button_html . '</div>';
					}
				} else {
					// Log error or display message if callback is invalid/missing
					frl_log(
						"Invalid or missing render_callback for widget '{id}'. Provided: {callback}",
						array(
							'id'       => $id,
							'callback' => $widget_config['render_callback'] ?? 'N/A',
						)
					);
					// Return error HTML string
					return "<div class='widget-error'><p class='error'>Error rendering widget '{$id}': Invalid callback.</p></div>";
				}

				$final_html = '<div class="frl-dashboard-widget">' . $content . '</div>';
				return $final_html;
			},
			$cache_ttl
		);

		echo $widget_html;
	}

	/**
	 * Render the refresh button form for a cacheable widget.
	 *
	 * @param string $group The widget cache group.
	 * @param string $key The widget cache key.
	 * @param string $button_class Optional CSS classes for the button.
	 * @return string HTML for the button form, or empty string if user lacks permissions.
	 */
	private static function _render_refresh_button( string $group, string $key, string $button_class = 'button-small frl-widget-refresh' ) {
		if ( ! frl_has_access( 'manage_options' ) ) { // Capability check
			return '';
		}

		$type         = 'refresh_cache';
		$label        = __( 'Refresh', FRL_PREFIX );
		$action       = 'frl_post_action_dashboard_widgets'; // The admin-post action hook
		$nonce_action = "dashboard_widget_{$type}_{$group}_{$key}"; // Unique nonce action

		$output  = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="frl-widget-action-form">';
		$output .= '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		$output .= '<input type="hidden" name="type" value="' . esc_attr( $type ) . '">';
		$output .= '<input type="hidden" name="widget_group" value="' . esc_attr( $group ) . '">';
		$output .= '<input type="hidden" name="widget_key" value="' . esc_attr( $key ) . '">';
		$output .= frl_nonce_field( $nonce_action, '_wpnonce', true, false );
		$output .= '<button type="submit" class="button ' . esc_attr( $button_class ) . '">' . esc_html( $label ) . '</button>';
		$output .= '</form>';

		return $output;
	}
}
