<?php

/**
 * Debug Display class to display WordPress debug configuration settings.
 *
 * @package Fralenuvole
 */

// Exit if accessed directly.
if (! defined('ABSPATH')) {
	exit;
}

/**
 * Class Debug_Display
 *
 * Displays WordPress debug configuration settings including WP_DEBUG constants
 * and PHP error reporting levels in a user-friendly interface.
 */
class Frl_Debug_Display
{
	/**
	 * Render Debug Constants and Error Reporting Settings in a two-column layout
	 *
	 * @return string HTML output of the debug configuration widget
	 */
	public function render()
	{
		// Get current debug settings
		$debug = defined('WP_DEBUG') ? WP_DEBUG : false;
		$debug_log = defined('WP_DEBUG_LOG') ? WP_DEBUG_LOG : false;
		$debug_display = defined('WP_DEBUG_DISPLAY') ? WP_DEBUG_DISPLAY : false;

		// Get current error reporting level
		$current_error_level = error_reporting();

		// Check if specific error types are enabled by seeing if they're excluded from the error level
		// For notices, check if E_NOTICE or E_USER_NOTICE are excluded
		$notices_masked = (($current_error_level & E_NOTICE) == 0) || (($current_error_level & E_USER_NOTICE) == 0);
		$notice_enabled = !$notices_masked;

		// For warnings, check if E_WARNING or E_USER_WARNING are excluded
		$warnings_masked = (($current_error_level & E_WARNING) == 0) || (($current_error_level & E_USER_WARNING) == 0);
		$warning_enabled = !$warnings_masked;

		// For deprecated, check if E_DEPRECATED or E_USER_DEPRECATED are excluded
		$deprecated_masked = (($current_error_level & E_DEPRECATED) == 0) || (($current_error_level & E_USER_DEPRECATED) == 0);
		$deprecated_enabled = !$deprecated_masked;

		// Debug Constants Table
		$debug_constants_rows = frl_ui_render_table_row('Constant', 'Status', true, 'constants-header-row');
		$debug_constants_rows .= frl_ui_render_status_row('WP_DEBUG', $debug, null);
		$debug_constants_rows .= frl_ui_render_status_row('WP_DEBUG_LOG', $debug_log, null);
		$debug_constants_rows .= frl_ui_render_status_row('WP_DEBUG_DISPLAY', $debug_display, null);


		// Get the raw wp-config.php file content
		$code_display = '';
		$config_path = ABSPATH . 'wp-config.php';
		if (file_exists($config_path) && is_readable($config_path)) {
			$config_content = file_get_contents($config_path);

			// Create the hidden file display but don't add it to the table yet
			$code_display = '<div id="wpconfig-full-code" style="display:none;">';

			// Start with table content - begin with header row
			$table_content = frl_ui_render_table_row('wp-config.php', '', true);

			// Create code block content without table row wrapping
			$table_content .= frl_ui_render_code_block(
				$config_content,
				'js',            // Language for syntax highlighting
				'',               // No separate ID needed
				false,            // Not initially hidden - parent div is hidden
				false             // Not in table row
			);

			// Render the complete table
			$code_display .= frl_ui_render_table('wpconfig-code', $table_content, 'wpconfig-code-table');

			$code_display .= '</div>';
		}

		$debug_constants_table = frl_ui_render_table('debug-constants', $debug_constants_rows, 'debug-constants-table');

		// Add toggle button for wp-config.php code
		$wp_config_toggle_button = frl_ui_render_toggle_button(
			'View wp-config.php',
			'wpconfig-full-code',
			'button-small align-right'
		);

		$debug_constants_table .= $wp_config_toggle_button;

		// Error Reporting Table
		$error_reporting_rows = frl_ui_render_table_row('Error Type', 'Status', true, 'error-types-header-row');
		$error_reporting_rows .= frl_ui_render_status_row('Notices', $notice_enabled, null);
		$error_reporting_rows .= frl_ui_render_status_row('Warnings', $warning_enabled, null);
		$error_reporting_rows .= frl_ui_render_status_row('Deprecated', $deprecated_enabled, null);

		$error_reporting_table = frl_ui_render_table('error-reporting', $error_reporting_rows, 'error-reporting-table');

		// Wrap each table in a widget section
		$debug_constants_section = '<div class="widget-section">';
		$debug_constants_section .= '<h3>WP Config Debug Constants</h3>';
		$debug_constants_section .= '<p class="description">WordPress debug constants in wp-config.php</p>';
		$debug_constants_section .= $debug_constants_table;
		$debug_constants_section .= '</div>';

		$error_reporting_section = '<div class="widget-section">';
		$error_reporting_section .= '<h3>Error Reporting</h3>';
		$error_reporting_section .= '<p class="description">PHP error reporting settings for custom error handler</p>';
		$error_reporting_section .= $error_reporting_table;

		// Add the current error level in a readable format
		$level_display = self::get_readable_error_level($current_error_level);
		$error_reporting_section .= frl_ui_render_code_block($level_display, 'js', 'current-error-level', false, false);


		$error_reporting_section .= '</div>';

		// Use the flexible layout renderer for a 2-column layout
		$columns = frl_ui_render_flex_layout(
			[$debug_constants_section, $error_reporting_section],
			2,
			'debug-settings'
		);

		// Build the complete HTML output
		$html = frl_ui_render_widget(
			'debug-config',
			$columns,
		);

		// Output the wp-config.php viewer after the widget
		$html .= $code_display;

		return $html;
	}

	/**
	 * Get current error reporting level in a readable format.
	 *
	 * This is a public wrapper for get_readable_error_level().
	 *
	 * @return string Human-readable representation of the current error level.
	 */
	public static function get_current_readable_error_level()
	{
		return self::get_readable_error_level(error_reporting());
	}

	/**
	 * Convert error reporting level to readable format
	 *
	 * @param int $level Error reporting level
	 * @return string Human-readable representation
	 */
	private static function get_readable_error_level($level)
	{
		// If level is E_ALL
		if ($level == E_ALL) {
			return 'E_ALL';
		}

		// If level includes all error types, it's E_ALL with specific exclusions
		if (($level & E_ALL) == $level) {
			$excluded = [];

			// Check which error types are excluded
			if (!($level & E_NOTICE)) $excluded[] = 'E_NOTICE';
			if (!($level & E_USER_NOTICE)) $excluded[] = 'E_USER_NOTICE';
			if (!($level & E_WARNING)) $excluded[] = 'E_WARNING';
			if (!($level & E_USER_WARNING)) $excluded[] = 'E_USER_WARNING';
			if (!($level & E_DEPRECATED)) $excluded[] = 'E_DEPRECATED';
			if (!($level & E_USER_DEPRECATED)) $excluded[] = 'E_USER_DEPRECATED';

			if (empty($excluded)) {
				return 'E_ALL';
			} else {
				return 'E_ALL & ~' . implode(' & ~', $excluded);
			}
		}

		// Otherwise, show the raw value
		return 'Custom: ' . $level;
	}
}
