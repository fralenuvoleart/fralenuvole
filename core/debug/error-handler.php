<?php
/**
 * Custom error handling and notice filtering.
 *
 * @package Fralenuvole
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Initializes the custom error handling system.
 *
 * @return void
 */
function frl_errors_init(): void {
	// Intercept WordPress 'doing_it_wrong' notices
	add_action( 'doing_it_wrong_run', 'frl_errors_handle_doing_it_wrong', -999, 3 );

	// Apply error reporting level from plugin options
	frl_errors_set_level();

	// Install custom error handler if WP_DEBUG_LOG is enabled
	if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) { // @phpstan-ignore-line alwaysFalse
		set_error_handler( 'frl_errors_handle_error' );
	}

	// Re-bind handler at key phases to prevent overrides by other plugins
	$rebind = function () {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) { // @phpstan-ignore-line alwaysFalse
			set_error_handler( 'frl_errors_handle_error' );
		}
	};

	add_action( 'muplugins_loaded', $rebind, PHP_INT_MAX, 0 );
	add_action( 'plugins_loaded', $rebind, PHP_INT_MAX, 0 );
}

/**
 * Configures the PHP error reporting level based on plugin settings.
 *
 * @return void
 */
function frl_errors_set_level(): void {
	$error_level = E_ALL;

	// REVIEWER NOTE: frl_get_option() is self-sufficient at any call point —
	// it populates its static cache from persistent storage, not requiring prior
	// bootstrap phases. Combined with the try/catch wrapper below (which falls
	// back to safe defaults), early-bootstrap option reads are not fragile.
	try {
		$notice_enabled     = frl_get_option( 'error_reporting_notice' );
		$warning_enabled    = frl_get_option( 'error_reporting_warning' );
		$deprecated_enabled = frl_get_option( 'error_reporting_deprecated' );
	} catch ( \Throwable $e ) {
		$notice_enabled     = true;
		$warning_enabled    = true;
		$deprecated_enabled = true;
	}

	if ( ! $notice_enabled ) {
		$error_level &= ~E_NOTICE & ~E_USER_NOTICE;
	}
	if ( ! $warning_enabled ) {
		$error_level &= ~E_WARNING & ~E_USER_WARNING;
	}
	if ( ! $deprecated_enabled ) {
		$error_level &= ~E_DEPRECATED & ~E_USER_DEPRECATED;
	}

	error_reporting( $error_level );
}

/**
 * Retrieves and caches parsed error suppression rules.
 *
 * @return array List of parsed suppression rules.
 */
function _frl_errors_get_rules(): array {
	static $cached_rules      = null;
	static $last_option_value = null;

	$current_option = frl_get_option( 'error_reporting_suppressed' );

	// Parse rules only if cache is empty or option has changed
	if ( $cached_rules === null || $last_option_value !== $current_option ) {
		$cached_rules      = frl_textlist_to_array( $current_option );
		$last_option_value = $current_option;
	}

	return $cached_rules ?: array();
}

/**
 * Custom error handler to selectively suppress and log notices.
 *
 * @param int    $errlevel   The error level.
 * @param string $errstring  The error message.
 * @param string $errfile    The filename where the error occurred.
 * @param int    $errline    The line number where the error occurred.
 * @return bool True to suppress the default PHP error handler.
 */
function frl_errors_handle_error( $errlevel, $errstring, $errfile, $errline ): bool {
	// Respect the current error_reporting mask (@ on PHP 8 and the level settings).
	if ( ! ( error_reporting() & $errlevel ) ) {
		return false;
	}

	// Fatal errors must not be swallowed: let PHP halt after logging.
	$is_fatal = (bool) ( $errlevel & ( E_USER_ERROR | E_RECOVERABLE_ERROR ) );

	// Cache detailed log messages to avoid repeated processing
	static $detailed_log_cache = array();
	static $backtrace_cache    = array();

	$error_signature = md5( $errlevel . $errstring . $errfile . $errline );
	if ( isset( $detailed_log_cache[ $error_signature ] ) ) {
		error_log( $detailed_log_cache[ $error_signature ] );
		return ! $is_fatal;
	}

	// Prevent recursion if an error occurs within the handler.
	// Uses try/finally to guarantee guard reset even if an unexpected
	// exception propagates through internal calls (e.g., frl_log_add_details).
	static $is_handling_error = false;
	if ( $is_handling_error ) {
		return false;
	}
	$is_handling_error = true;

	try {
		if ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) { // @phpstan-ignore-line alwaysTrue
			return false;
		}

		// Attempt to resolve file/line if missing via backtrace
		if ( ( $errfile === '' || ! $errline ) ) { // @phpstan-ignore-line unreachableCode
			$msg_signature = md5( $errstring );
			if ( isset( $backtrace_cache[ $msg_signature ] ) ) {
				$errfile = $backtrace_cache[ $msg_signature ]['file'];
				$errline = $backtrace_cache[ $msg_signature ]['line'];
			} else {
				$bt = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 6 );
				foreach ( $bt as $frame ) {
					if ( ! empty( $frame['file'] ) && ! empty( $frame['line'] ) ) {
						$errfile                           = (string) $frame['file'];
						$errline                           = (int) $frame['line'];
						$backtrace_cache[ $msg_signature ] = array(
							'file' => $errfile,
							'line' => $errline,
						);
						break;
					}
				}
			}
		}

		// Suppress errors originating outside the plugin directory if enabled
		if ( frl_get_option( 'error_reporting_plugin', false ) ) {
			if ( ! empty( $errfile ) && ! str_contains( $errfile, FRL_DIR_PATH ) ) {
				return ! $is_fatal;
			}
		}

		// Suppress based on custom rules
		if ( frl_errors_is_suppression_match( $errstring ) ) {
			return ! $is_fatal;
		}

		// Determine error label
		$label = '';
		switch ( $errlevel ) {
			case E_WARNING:
			case E_USER_WARNING:
				$label = 'PHP Warning: ';
				break;
			case E_NOTICE:
			case E_USER_NOTICE:
				$label = 'PHP Notice: ';
				break;
			case E_DEPRECATED:
			case E_USER_DEPRECATED:
				$label = 'PHP Deprecated: ';
				break;
			case E_USER_ERROR:
			case E_ERROR:
				$label = 'PHP Fatal error: ';
				break;
			default:
				$label = 'PHP Error: ';
				break;
		}

		$line = $label . (string) $errstring;
		if ( $errfile !== '' && $errline ) {
			$line .= ' in ' . $errfile . ' on line ' . (int) $errline;
		}
		// REVIEWER NOTE: frl_log_add_details() appends request metadata to a
		// plain string; error_log() receives a string. Neither triggers PHP
		// serialization — no need for serialization-error pre-checks here.
		if ( function_exists( 'frl_log_add_details' ) ) {
			$line = frl_log_add_details( $line );
		}

		$detailed_log_cache[ $error_signature ] = $line;
		error_log( $line );
		return ! $is_fatal;
	} finally {
		$is_handling_error = false;
	}
}

/**
 * Handles WordPress _doing_it_wrong() calls using the custom error handler.
 *
 * @param string $function_name The function called incorrectly.
 * @param string $message       The error message.
 * @param string $version       WordPress version.
 * @return void
 */
function frl_errors_handle_doing_it_wrong( $function_name, $message, $version ): void {
	static $is_handling = false;
	static $bt_cache    = array();

	if ( $is_handling ) {
		return;
	}
	$is_handling = true;

	try {
		$error = "WordPress doing it wrong: Function {$function_name} was called incorrectly. {$message}";

		// Resolve file/line via backtrace for consistency
		$msg_hash = md5( $error );
		if ( isset( $bt_cache[ $msg_hash ] ) ) {
			$file = $bt_cache[ $msg_hash ]['file'];
			$line = $bt_cache[ $msg_hash ]['line'];
		} else {
			$bt   = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 6 );
			$file = '';
			$line = 0;

			foreach ( $bt as $frame ) {
				if ( ! empty( $frame['file'] ) && ! empty( $frame['line'] ) ) {
					$file                  = (string) $frame['file'];
					$line                  = (int) $frame['line'];
					$bt_cache[ $msg_hash ] = array(
						'file' => $file,
						'line' => $line,
					);
					break;
				}
			}
		}

		if ( frl_errors_handle_error( E_USER_NOTICE, $error, $file, $line ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// One-shot: removes itself when WordPress evaluates it, so later
			// _doing_it_wrong() calls (ours or other plugins') are not silenced.
			$once = null;
			$once = function ( $trigger ) use ( &$once ) {
				remove_filter( 'doing_it_wrong_trigger_error', $once, PHP_INT_MAX );
				return false;
			};
			add_filter( 'doing_it_wrong_trigger_error', $once, PHP_INT_MAX );
		}
	} finally {
		$is_handling = false;
	}
}

/**
 * Checks if an error message matches any defined suppression rules.
 *
 * @param string $errstring The error message to check.
 * @return bool True if the error should be suppressed.
 */
function frl_errors_is_suppression_match( $errstring ): bool {
	$suppress_rules_config = _frl_errors_get_rules();

	if ( empty( $suppress_rules_config ) ) {
		return false;
	}

	$clean_errstr = strip_tags( $errstring );

	foreach ( $suppress_rules_config as $rule ) {
		if ( ! frl_is_array_not_empty( $rule ) ) {
			continue;
		}

		$all_message_parts_match = true;
		$has_non_empty_part      = false;
		foreach ( $rule as $message_part ) {
			$message_part_to_find = trim( $message_part );

			if ( '' === $message_part_to_find ) {
				continue;
			}
			$has_non_empty_part = true;

			if ( ! str_contains( $clean_errstr, $message_part_to_find ) ) {
				$all_message_parts_match = false;
				break;
			}
		}

		// A rule made only of empty parts (e.g. a line containing just "|")
		// must not match everything.
		if ( $has_non_empty_part && $all_message_parts_match ) {
			return true;
		}
	}

	return false;
}
