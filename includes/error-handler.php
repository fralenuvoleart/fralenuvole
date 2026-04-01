<?php
/**
 * Custom error handling to filter specific notices.
 */

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Initialize the error handler.
 *
 * @return void
 */
function frl_errors_init()
{
// Hook into `doing_it_wrong` with the highest possible priority to intercept notices.
frl_hook_add(
    'action',
    'doing_it_wrong_run',
    'frl_errors_handle_doing_it_wrong',
    -999,
    3
);

    // Temporary diagnostics: confirm init and WP_DEBUG_LOG state (writes to current error channel)
    // (debug lines removed)

    // 1. Set dynamic error reporting level based on plugin options
    frl_errors_set_level();

    // 2. Set custom error handler if debug logging is enabled
    if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
        // Install userland handler for runtime PHP notices/warnings
        set_error_handler('frl_errors_handle_error');
    }

    // Re-bind the handler at key load phases to survive overrides by other plugins
    // Only if debug logging is truly enabled to minimize bootstrap overhead.
    $rebind = function () {
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            set_error_handler('frl_errors_handle_error');
        }
    };

    frl_hook_add('action', 'muplugins_loaded', $rebind, PHP_INT_MAX, 0);
    frl_hook_add('action', 'plugins_loaded', $rebind, PHP_INT_MAX, 0);
}

/**
 * Build and apply error reporting level based on plugin options
 *
 * @return void
 */
function frl_errors_set_level()
{
    // Start with all error types enabled
    $error_level = E_ALL;

    try {
        $notice_enabled = frl_get_option('error_reporting_notice');
        $warning_enabled = frl_get_option('error_reporting_warning');
        $deprecated_enabled = frl_get_option('error_reporting_deprecated');
    } catch (Exception $e) {
        $notice_enabled = true;
        $warning_enabled = true;
        $deprecated_enabled = true;
    }

    if (!$notice_enabled) {
        $error_level &= ~E_NOTICE & ~E_USER_NOTICE;
    }
    if (!$warning_enabled) {
        $error_level &= ~E_WARNING & ~E_USER_WARNING;
    }
    if (!$deprecated_enabled) {
        $error_level &= ~E_DEPRECATED & ~E_USER_DEPRECATED;
    }

    error_reporting($error_level);
}

/**
 * Get and cache the parsed suppression rules.
 *
 * This internal function centralizes fetching and parsing the suppression rules
 * from the database to avoid redundant processing.
 *
 * @return array The array of parsed suppression rules.
 */
function _frl_errors_get_rules()
{
    // Static caching for suppression rules to avoid repeated parsing
    static $cached_rules = null;
    static $last_option_value = null;

    // Get current option value
    $current_option = frl_get_option('error_reporting_suppressed');

    // Parse rules only if cache is empty or option changed
    if ($cached_rules === null || $last_option_value !== $current_option) {
        $cached_rules = frl_textlist_to_array($current_option);
        $last_option_value = $current_option;
    }

    return $cached_rules ?: [];
}

/**
 * Custom error handler to selectively suppress specific notices.
 *
 * @param int    $errlevel   The error level.
 * @param string $errstring  The error message.
 * @param string $errfile The filename that the error was raised in.
 * @param int    $errline The line number the error was raised at.
 * @return bool True to prevent the PHP internal error handler from being called, false otherwise.
 */
function frl_errors_handle_error($errlevel, $errstring, $errfile, $errline)
{
    // In-request cache to avoid re-calculating detailed log messages for repeated errors
    static $detailed_log_cache = [];
    static $backtrace_cache = [];

    $error_signature = md5($errlevel . $errstring . $errfile . $errline);
    if (isset($detailed_log_cache[$error_signature])) {
        error_log($detailed_log_cache[$error_signature]); // Log the cached message
        return true; // Suppress default handler
    }

    // Avoid recursion if an error is triggered inside this handler
    static $is_handling_error = false;
    if ($is_handling_error) {
        return false; // Let the standard handler run, do not suppress
    }
    $is_handling_error = true;

    try {
        if (! defined('WP_DEBUG_LOG') || ! WP_DEBUG_LOG) {
            return false;
        }

        // Best-effort backtrace when file/line are missing
        if (($errfile === '' || !$errline)) {
            $msg_signature = md5($errstring);
            if (isset($backtrace_cache[$msg_signature])) {
                $errfile = $backtrace_cache[$msg_signature]['file'];
                $errline = $backtrace_cache[$msg_signature]['line'];
            } else {
                $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6);
                foreach ($bt as $frame) {
                    if (!empty($frame['file']) && !empty($frame['line'])) {
                        $errfile = (string) $frame['file'];
                        $errline = (int) $frame['line'];
                        $backtrace_cache[$msg_signature] = ['file' => $errfile, 'line' => $errline];
                        break;
                    }
                }
            }
        }

        // Check if error was suppressed with @ operator
        // In PHP 8.0+, error_reporting() returns 4437 when @ is used
        if (error_reporting() === 4437) {
            return true; // Silently suppress the @ error
        }

        // --- Plugin-only filter ---
        // If enabled, we suppress any error that does NOT originate from this plugin's directory.
        if (frl_get_option('error_reporting_plugin', false)) {
             // If the error file path does NOT contain our plugin path, return true to suppress/hide it.
             if (!empty($errfile) && !str_contains($errfile, FRL_DIR_PATH)) {
                  return true; 
             }
        }
        // --------------------------

        $suppress = frl_errors_is_suppression_match($errstring);
        if ($suppress) {
            return true; // Suppress per rules
        }

        // Emit a single enriched line with level label and suppress native handler
        $label = '';
        switch ($errlevel) {
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
        }

        $line = $label . (string) $errstring;
        if ($errfile !== '' && $errline) {
            $line .= ' in ' . $errfile . ' on line ' . (int) $errline;
        }
        if (function_exists('frl_log_add_details')) {
            $line = frl_log_add_details($line);
        }

        // Cache the fully-formed message before logging
        $detailed_log_cache[$error_signature] = $line;

        error_log($line);
        return true;
    } finally {
        $is_handling_error = false;
    }
}

/**
 * Handle WordPress _doing_it_wrong() calls using the same error handler logic
 *
 * @param string $function_name The function that was called incorrectly
 * @param string $message The error message
 * @param string $version WordPress version when this was added
 */
function frl_errors_handle_doing_it_wrong($function_name, $message, $version)
{
    // Avoid recursion if an error is triggered inside this handler
    static $is_handling = false;
    static $bt_cache = [];

    if ($is_handling) {
        return;
    }
    $is_handling = true;

    try {
        // Recreate the full error message so suppression rules can match on function name
        $error = "WordPress doing it wrong: Function {$function_name} was called incorrectly. {$message}";

        // Derive a best-effort file/line from a short backtrace for tooling compatibility
        $msg_hash = md5($error);
        if (isset($bt_cache[$msg_hash])) {
            $file = $bt_cache[$msg_hash]['file'];
            $line = $bt_cache[$msg_hash]['line'];
        } else {
            $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6);
            $file = '';
            $line = 0;
            if (is_array($bt)) {
                foreach ($bt as $frame) {
                    if (!empty($frame['file']) && !empty($frame['line'])) {
                        $file = (string) $frame['file'];
                        $line = (int) $frame['line'];
                        $bt_cache[$msg_hash] = ['file' => $file, 'line' => $line];
                        break;
                    }
                }
            }
        }

        // Check if this should be suppressed by calling the main error handler
        if (frl_errors_handle_error(E_USER_NOTICE, $error, $file, $line)) {
            // If suppressed, prevent WordPress from triggering the actual error
            frl_hook_add(
                'filter',
                'doing_it_wrong_trigger_error',
                '__return_false'
            );
        }
    } finally {
        $is_handling = false;
    }
}

/**
 * Check if a given error message should be suppressed based on plugin settings.
 *
 * This is the core suppression logic, designed to be called by multiple handlers.
 *
 * @param string $errstring The error message to check.
 * @return bool True if the error should be suppressed, false otherwise.
 */
function frl_errors_is_suppression_match($errstring)
{
    $suppress_rules_config = _frl_errors_get_rules();

    if (empty($suppress_rules_config)) {
        return false;
    }

    $clean_errstr = strip_tags($errstring);

    foreach ($suppress_rules_config as $rule) {
        if (!frl_is_array_not_empty($rule)) {
            continue;
        }

        $all_message_parts_match = true;
        foreach ($rule as $message_part) {
            $message_part_to_find = trim($message_part);

            if (!empty($message_part_to_find)) {
                if (!str_contains($clean_errstr, $message_part_to_find)) {
                    $all_message_parts_match = false;
                    break;
                }
            }
        }

        if ($all_message_parts_match) {
            return true; // Suppress this error.
        }
    }

    return false; // Do not suppress.
}
