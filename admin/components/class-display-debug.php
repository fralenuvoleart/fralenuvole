<?php

/**
 * Debug Display class to display WordPress debug configuration settings.
 *
 * @package Fralenuvole
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Debug_Display
 *
 * Displays WordPress debug configuration settings including WP_DEBUG constants
 * and PHP error reporting levels in a user-friendly interface.
 */
class Frl_Debug_Display {

	/**
	 * Render Debug Constants and Error Reporting Settings in a two-column layout.
	 *
	 * @return string HTML output of the debug configuration widget.
	 */
	public function render() {
		// Get current debug settings
		$debug         = defined( 'WP_DEBUG' ) ? WP_DEBUG : false;
		$debug_log     = defined( 'WP_DEBUG_LOG' ) ? WP_DEBUG_LOG : false;
		$debug_display = defined( 'WP_DEBUG_DISPLAY' ) ? WP_DEBUG_DISPLAY : false;

		// Get current error reporting level
		$current_error_level = error_reporting();

		// Check if specific error types are enabled by seeing if they're excluded from the error level
		// For notices, check if E_NOTICE or E_USER_NOTICE are excluded
		$notices_masked = ( ( $current_error_level & E_NOTICE ) === 0 ) || ( ( $current_error_level & E_USER_NOTICE ) === 0 );
		$notice_enabled = ! $notices_masked;

		// For warnings, check if E_WARNING or E_USER_WARNING are excluded
		$warnings_masked = ( ( $current_error_level & E_WARNING ) === 0 ) || ( ( $current_error_level & E_USER_WARNING ) === 0 );
		$warning_enabled = ! $warnings_masked;

		// For deprecated, check if E_DEPRECATED or E_USER_DEPRECATED are excluded
		$deprecated_masked  = ( ( $current_error_level & E_DEPRECATED ) === 0 ) || ( ( $current_error_level & E_USER_DEPRECATED ) === 0 );
		$deprecated_enabled = ! $deprecated_masked;

		// Debug Constants Table
		$debug_constants_rows  = frl_ui_render_table_row( 'Constant', 'Status', true, 'constants-header-row' );
		$debug_constants_rows .= frl_ui_render_status_row( 'WP_DEBUG', $debug, null );
		$debug_constants_rows .= frl_ui_render_status_row( 'WP_DEBUG_LOG', $debug_log, null );
		$debug_constants_rows .= frl_ui_render_status_row( 'WP_DEBUG_DISPLAY', $debug_display, null );

		// Cached per wp-config.php mtime, so a manual edit refreshes the viewer.
		$code_display = '';
		$config_path  = ABSPATH . 'wp-config.php';
		$config_mtime = is_readable( $config_path ) ? filemtime( $config_path ) : false;
		if ( false !== $config_mtime ) {
			$code_display = frl_cache_remember(
				'adminui',
				'wpconfig_viewer_' . $config_mtime,
				function () use ( $config_path ) {
					$table_content  = frl_ui_render_table_row( 'wp-config.php', '', true );
					$table_content .= frl_ui_render_code_block( (string) file_get_contents( $config_path ), 'js', '', false, false );

					return '<div id="wpconfig-full-code" style="display:none;">'
						. frl_ui_render_table( 'wpconfig-code', $table_content, 'wpconfig-code-table', 0, true )
						. '</div>';
				},
				HOUR_IN_SECONDS
			);
		}

		$debug_constants_table = frl_ui_render_table( 'debug-constants', $debug_constants_rows, 'debug-constants-table' );

		// Only offer the toggle when the viewer has content to show.
		if ( '' !== (string) $code_display ) {
			$debug_constants_table .= frl_ui_render_toggle_button(
				'View wp-config.php',
				'wpconfig-full-code',
				'button-small align-right'
			);
		}

		// Error Reporting Table
		$error_reporting_rows  = frl_ui_render_table_row( 'Error Type', 'Status', true, 'error-types-header-row' );
		$error_reporting_rows .= frl_ui_render_status_row( 'Notices', $notice_enabled, null );
		$error_reporting_rows .= frl_ui_render_status_row( 'Warnings', $warning_enabled, null );
		$error_reporting_rows .= frl_ui_render_status_row( 'Deprecated', $deprecated_enabled, null );

		$error_reporting_table = frl_ui_render_table( 'error-reporting', $error_reporting_rows, 'error-reporting-table' );

		// Wrap each table in a widget section
		$debug_constants_section  = '<div class="widget-section">';
		$debug_constants_section .= '<h3>WP Config Debug Constants</h3>';
		$debug_constants_section .= '<p class="description">WordPress debug constants in wp-config.php</p>';
		$debug_constants_section .= $debug_constants_table;
		$debug_constants_section .= '</div>';

		$error_reporting_section  = '<div class="widget-section">';
		$error_reporting_section .= '<h3>Error Reporting</h3>';
		$error_reporting_section .= '<p class="description">PHP error reporting settings for custom error handler</p>';
		$error_reporting_section .= $error_reporting_table;

		// Add the current error level in a readable format
		$level_display            = self::get_readable_error_level( $current_error_level );
		$error_reporting_section .= frl_ui_render_code_block( $level_display, 'js', 'current-error-level', false, false );

		$error_reporting_section .= '</div>';

		// Use the flexible layout renderer for a 2-column layout
		$columns = frl_ui_render_flex_layout(
			array( $debug_constants_section, $error_reporting_section ),
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
	 * Convert error reporting level to readable format.
	 *
	 * @param int $level Error reporting level.
	 * @return string Human-readable representation.
	 */
	private static function get_readable_error_level( $level ) {
		$level = (int) $level;

		if ( E_ALL === $level ) {
			return 'E_ALL';
		}

		// E_STRICT is deliberately absent: the constant is deprecated since PHP 8.4.
		$names = array(
			E_ERROR             => 'E_ERROR',
			E_WARNING           => 'E_WARNING',
			E_PARSE             => 'E_PARSE',
			E_NOTICE            => 'E_NOTICE',
			E_CORE_ERROR        => 'E_CORE_ERROR',
			E_CORE_WARNING      => 'E_CORE_WARNING',
			E_COMPILE_ERROR     => 'E_COMPILE_ERROR',
			E_COMPILE_WARNING   => 'E_COMPILE_WARNING',
			E_USER_ERROR        => 'E_USER_ERROR',
			E_USER_WARNING      => 'E_USER_WARNING',
			E_USER_NOTICE       => 'E_USER_NOTICE',
			E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
			E_DEPRECATED        => 'E_DEPRECATED',
			E_USER_DEPRECATED   => 'E_USER_DEPRECATED',
		);

		$enabled = array();
		$known   = 0;
		foreach ( $names as $bit => $name ) {
			$known |= $bit;
			if ( $level & $bit ) {
				$enabled[] = $name;
			}
		}

		// Bits with no constant name (e.g. -1, or E_STRICT on PHP < 8.4) cannot be listed.
		if ( 0 !== ( $level & ~$known ) ) {
			return 'Custom: ' . $level;
		}

		return $enabled ? implode( ' | ', $enabled ) : 'None (0)';
	}
}
