<?php

/**
 * Fralenuvole
 * utilities.php - Utility functions
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}


/**
 * Minify CSS with optimized regex patterns
 */
function frl_minify_css($css)
{
    // Early return for empty CSS
    if (empty($css)) {
        return '';
    }

    // Single-pass minification with optimized regex patterns
    $minified = preg_replace([
        '!/\*[^*]*\*+([^/][^*]*\*+)*/!',  // Remove comments
        '/\s*([{}|:;,>~+^])\s*/',         // Remove spaces around delimiters (combined pattern)
        '/\s+/',                          // Normalize whitespace
        '/;\s*}/',                        // Remove trailing semicolons before closing braces
    ], [
        '',
        '$1',
        ' ',
        '}',
    ], $css);

    // Final cleanup with single str_replace call
    return str_replace([' {', ' }', '; }'], ['{', '}', '}'], trim($minified));
}

/**
 * Get the versions of the assets
 * @param array $assets The assets to get the versions of
 * @param string $group The group to cache the versions in
 * @param string $key The key to cache the versions in
 * @return array The versions of the assets, or an empty array if the assets are not found or are empty
 */
function frl_get_assets_versions($assets, $key = 'general', $group = 'versions', $log_errors = true)
{
    $versions = frl_cache_remember($group, $key, function () use ($assets, $key, $log_errors) {
        $versions_array = [];
        foreach ($assets as $handle => $path) {
            // Intelligently determine if the path is absolute or relative.
            $is_absolute = str_starts_with($path, '/');
            $full_path = $is_absolute ? $path : FRL_DIR_PATH . $path;

            if (file_exists($full_path)) {
                if (filesize($full_path) > 0) {
                    $versions_array[$handle] = filemtime($full_path);
                }
            } else {
                if ($log_errors) {
                    frl_log("Asset file does not exist: {$path} (for assets key: {$key})");
                }
            }
        }
        return $versions_array;
    });
    return $versions;
}

/**
 * Checks if a variable (or nested key) is a non-empty array.
 *
 * @param mixed $var The variable to check.
 * @param string|null $key Optional key to check within $var.
 * @return bool True if $var (or $var[$key]) is a non-empty array, false otherwise.
 */
function frl_is_array_not_empty($var, $key = null)
{
    if ($key !== null) {
        return isset($var[$key]) && is_array($var[$key]) && !empty($var[$key]);
    }
    return !empty($var) && is_array($var);
}

/**
 * Convert textlist string (often from a textarea) to an array.
 * Handles both simple lines (each becoming an array element) and lines with pipe separators
 * (where each part of the pipe-separated line becomes an element in a sub-array).
 *
 * @param string $input The textlist string content.
 * @return array Processed array. Empty if input is empty or not a string.
 *
 * Example inputs:
 * "line1
 *  line2"
 * Returns: ['line1', 'line2']
 *
 * "key1|value1
 *  key2|value2"
 * Returns: [['key1', 'value1'], ['key2', 'value2']]
 */
function frl_textlist_to_array($input)
{
    // Handle empty or non-string input
    if (empty($input) || !is_string($input)) {
        return [];
    }

    // Normalize line endings and split into lines
    $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $input));

    // Clean and filter lines
    $lines = array_filter($lines, function ($line) {
        return trim($line) !== '';
    });

    return array_map(function ($line) {
        $trimmed_line = trim($line);
        if (str_contains($trimmed_line, '|')) {
            // Line contains pipes, explode into an array of all parts
            return array_map('trim', explode('|', $trimmed_line));
        } else {
            // Line does not contain pipes, wrap in array for consistency
            return [$trimmed_line];
        }
    }, $lines);
}

/**
 * Group array by key
 * @param string $key Property to sort by.
 * @param array $data Array that stores multiple associative arrays.
 */
function frl_group_array_by_key($key, $data)
{
    $result = [];
    foreach ($data as $val) {
        if (isset($val[$key])) {
            $result[$val[$key]][] = $val;
        }
    }
    return $result;
}

/**
 * Remove array elements containing a specific value substring
 * @param array $array The array to filter
 * @param string $needle The substring to search for in array values
 * @return array Filtered array
 *
 */
function frl_filter_array_by_substring($array, $needle)
{
    return array_filter(
        $array,
        fn($v) => !str_contains($v, $needle)
    );
}

/**
 * Returns the base domain by stripping known prefixes, for staging/production sibling comparison.
 *
 * Strips in order:
 *   1. Staging prefixes defined in FRL_ENV_STAGING_PREFIXES (config-driven, e.g. staging., dev.)
 *   2. www. — universal canonical alias convention, not a staging indicator
 *
 * @param string $domain
 * @return string
 */
function frl_strip_env_prefix(string $domain): string
{
    foreach (FRL_ENV_STAGING_PREFIXES as $prefix) {
        if (stripos($domain, $prefix) === 0) {
            $domain = substr($domain, strlen($prefix));
            break;
        }
    }

    // www. is the only conventional canonical prefix; not config-driven
    if (stripos($domain, 'www.') === 0) {
        $domain = substr($domain, 4);
    }

    return $domain;
}

/**
 * Check if a string contains any of the substrings in an array
 * @param string $string The string to check
 * @param array $substrings Array of potential substrings to look for
 * @return bool True if any substring is found in the string
 */
function frl_string_contains_item_from_array($string, $substrings)
{
    if (!is_string($string)) return false;

    foreach ($substrings as $substring) {
        if (is_string($substring) && str_contains($string, $substring)) {
            return true;
        }
    }
    return false;
}

/**
 * Match a string against an array of patterns with % as wildcard.
 * - % matches any sequence (including empty)
 * - All other characters (including '_') are treated literally
 * Examples:
 *  - 'cards_title' matches exactly 'cards_title'
 *  - '%_title' matches any string ending with the literal '_title'
 *  - 'card%title' matches strings starting with 'card' and later containing 'title'
 * @param string $string The string to test
 * @param array $patterns Array of patterns
 * @return bool True if any pattern matches
 */
function frl_string_matches_pattern($string, $patterns)
{
    if (!is_string($string)) return false;

    foreach ($patterns as $pattern) {
        if (!is_string($pattern) || $pattern === '') continue;

        // Escape regex special chars, then replace % wildcard only
        $regex = preg_quote($pattern, '/');
        $regex = str_replace('%', '.*', $regex);
        // Anchor full string match
        $regex = '/^' . $regex . '$/u';

        if (preg_match($regex, $string) === 1) {
            return true;
        }
    }
    return false;
}

/**
 * Normalizes any input into a safe, non-null array.
 *
 * Guarantees an array return value. Converts arrays/objects/JSON. Wraps scalar
 * values in an array. Converts null and empty strings to an empty array.
 *
 * @param mixed $data The input data to normalize.
 * @return array The resulting array.
 */
function frl_normalize_to_array($data): array
{
    // Handle null and empty strings by returning an empty array.
    if ($data === null || $data === '') {
        return [];
    }

    // Handle existing arrays or objects.
    if (is_array($data) || is_object($data)) {
        return (array)$data;
    }

    // Handle string inputs, which could be JSON or a simple string.
    if (is_string($data)) {
        $decoded = json_decode($data, true);
        // Check if it was valid JSON.
        if (json_last_error() === JSON_ERROR_NONE) {
            // If it decoded to an array, return it. Otherwise, wrap the scalar.
            return is_array($decoded) ? $decoded : [$decoded];
        } else {
            // It was not valid JSON, treat as a literal string and wrap it.
            return [$data];
        }
    }

    // Handle any other scalar type (int, bool, float) by wrapping it.
    if (is_scalar($data)) {
        return [$data];
    }

    // Fallback for unexpected types (e.g., resources), return empty array.
    return [];
}

/**
 * Converts value to WordPress-friendly '1' or '0' string including:
 * - Booleans (true/false)
 * - Integers (0, 1)
 * - Strings ('0', '1', 'true', 'false', 'yes', 'no', 'on', 'off')
 *
 * @param mixed $value The value to convert
 * @return string '1' for truthy values, '0' for falsy values, or original value if not boolean-like.
 */
function frl_normalize_boolval($value)
{
    // Fast path: return already normalized values immediately
    if ($value === '0' || $value === '1') {
        return $value;
    }

    // Check for explicit false strings
    if ($value === false || $value === 0 || $value === 'false' || $value === 'no' || $value === 'off' || $value === 'null') {
        return '0';
    }

    // Check for explicit true strings
    if ($value === 1 || $value === true || $value === 'true' || $value === 'yes' || $value === 'on') {
        return '1';
    }

    return $value;
}

/**
 * Coerce mixed values (scalars/arrays/objects) to a string using optional selectors.
 * - Scalars: cast to string
 * - Arrays/Objects: if $preferredSelectors provided, return the first matching scalar key/property
 * - Special selector ':first' selects the first scalar found (depth-first, shallow-first)
 * - If nothing matches, return empty string
 *
 * @param mixed $value
 * @param array $preferredSelectors Keys/props in priority order; may include ':first'
 * @return string
 */
function frl_coerce_to_string($value, array $preferredSelectors = []): string
{
    // Fast paths
    if (is_string($value)) return $value;
    if (is_scalar($value)) return (string)$value;

    // Helper to extract by selector from array/object
    $extractSelector = function ($container, string $key) {
        if (is_array($container) && array_key_exists($key, $container)) {
            $v = $container[$key];
            // After is_scalar check, $v can only be object/array/resource/null - not string
            if (is_scalar($v)) {
                return (string)$v;
            }
            return null;
        }
        if (is_object($container) && isset($container->{$key})) {
            $v = $container->{$key};
            // After is_scalar check, $v can only be object/array/resource/null - not string
            if (is_scalar($v)) {
                return (string)$v;
            }
            return null;
        }
        return null;
    };

    // Objects
    if (is_object($value)) {
        if (!empty($preferredSelectors)) {
            foreach ($preferredSelectors as $sel) {
                if ($sel === ':first') continue; // handled below
                $res = $extractSelector($value, (string)$sel);
                if ($res !== null && $res !== '') return $res;
            }
        }
        if (method_exists($value, '__toString')) return (string)$value;
        if (in_array(':first', $preferredSelectors, true)) {
            foreach (get_object_vars($value) as $v) {
                if (is_string($v)) return $v;
                if (is_scalar($v)) return (string)$v;
            }
        }
        return '';
    }

    // Arrays
    if (is_array($value)) {
        if (!empty($preferredSelectors)) {
            foreach ($preferredSelectors as $sel) {
                if ($sel === ':first') continue; // handled below
                $res = $extractSelector($value, (string)$sel);
                if ($res !== null && $res !== '') return $res;
            }
        }
        
        if (in_array(':first', $preferredSelectors, true)) {
            foreach ($value as $v) {
                if (is_string($v)) return $v;
                if (is_scalar($v)) return (string)$v;
            }
        }
        // No guessing by default
        return '';
    }

    // Unsupported types
    return '';
}

/**
 * Normalize a textlist string by trimming lines and removing empty lines.
 *
 * @param mixed $input The input to normalize (converted to string, or empty string if not)
 * @return string Normalized text with Unix line endings
 */
function frl_normalize_textlist($input)
{
    // Ensure input is a string
    if (!is_string($input)) {
        return '';
    }

    // Normalize line endings to Unix style (\n)
    $text = str_replace(["\r\n", "\r"], "\n", $input);

    // Split into lines, trim each line, and join back with Unix newlines
    $lines = explode("\n", $text);
    $trimmed_lines = array_map('trim', $lines);
    // Filter out empty lines that might result from trimming lines containing only whitespace
    $trimmed_lines = array_filter($trimmed_lines, function ($line) {
        return $line !== '';
    });
    $text = implode("\n", $trimmed_lines);

    return $text;
}

/**
 * Recursively convert unserialisable values (Closures, object-method arrays) into
 * plain data so that the structure can be safely cached / serialized.
 *
 * @param mixed $value Variable passed by reference – transformed in place.
 * @return void
 */
/**
 * Internal recursive helper for frl_sanitize_for_serialization.
 * 
 * @param mixed $v The value to sanitize.
 * @param int $depth Current recursion depth.
 * @return void
 */
function _frl_sanitize_recursive(&$v, int $depth = 0): void
{
    // Prevent infinite recursion and excessive processing
    if ($depth > 5) {
        if (is_object($v)) {
            $v = 'Object(' . get_class($v) . ') [depth limit]';
        } elseif (is_array($v)) {
            $v = '[array] [depth limit]';
        }
        return;
    }

    if ($v instanceof \Closure) {
        try {
            $rf = new ReflectionFunction($v);
            $v = [
                '__type' => 'closure',
                'file'   => $rf->getFileName(),
                'line'   => $rf->getStartLine()
            ];
        } catch (ReflectionException $e) {
            $v = ['__type' => 'closure', 'file' => 'N/A', 'line' => 0];
        }
        return;
    }
    
    // Handle generic objects by converting to class name to ensure serializability
    if (is_object($v)) {
        $v = 'Object(' . get_class($v) . ')';
        return;
    }

    if (is_array($v)) {
        // Handle array-based callables [object, 'method']
        if (isset($v[0]) && is_object($v[0]) && isset($v[1]) && is_string($v[1])) {
            $v[0] = get_class($v[0]);
        }
        foreach ($v as &$inner) {
            _frl_sanitize_recursive($inner, $depth + 1);
        }
    }
}

/**
 * Sanitize a value for serialization, removing closures and limiting depth.
 * This ensures data can be safely stored in persistent cache.
 *
 * @param mixed $value The value to sanitize (passed by reference).
 * @return void
 */
function frl_sanitize_for_serialization(&$value): void
{
    _frl_sanitize_recursive($value);
}

/**
 * Set a transient cookie-based flag to force a page reload on next admin load.
 * Used during environment switching.
 * 
 * @return void
 */
function frl_set_force_reload_flag(): void
{
    $name = frl_prefix('force_reload');
    // Set a cookie that expires in 1 minute
    setcookie($name, '1', time() + 60, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true);
}

/**
 * Check if the force reload flag is set and clear it if it is.
 * 
 * @return bool True if the flag was set, false otherwise.
 */
function frl_check_and_clear_force_reload_flag(): bool
{
    $name = frl_prefix('force_reload');
    if (!isset($_COOKIE[$name])) {
        return false;
    }

    // Clear the cookie immediately
    setcookie($name, '', time() - 3600, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true);
    return true;
}

/**
 * Validates and corrects an HTML fragment to ensure it is well-formed.
 *
 * This function uses DOMDocument to parse an HTML string, which automatically
 * corrects issues like unclosed tags. It is designed to be minimal and
 * not alter correctly-formed HTML.
 *
 * @param string $html The HTML fragment to validate.
 * @return string The validated and potentially corrected HTML fragment.
 */
function frl_validate_html_fragment(string $html): string
{
    // Return early for empty or whitespace-only strings
    if (trim($html) === '') {
        return $html;
    }

	// If DOM extension is unavailable, skip validation to avoid fatal errors
	if (!class_exists('DOMDocument')) {
		frl_log('DOMDocument class not available; skipping HTML fragment validation');
		return $html;
	}

    // Use libxml to suppress warnings from malformed HTML during parsing
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();

    // Load the HTML, wrapping it in a div and specifying UTF-8
    // The wrapping div ensures that fragments are parsed correctly
    $dom->loadHTML('<?xml encoding="UTF-8"><div id="frl-validator-wrapper">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

    // Clear libxml errors after parsing
    libxml_clear_errors();
    libxml_use_internal_errors(false);

    // Find the wrapper element
    $wrapper = $dom->getElementById('frl-validator-wrapper');

    // If the wrapper is not found, something is deeply wrong.
    // Fallback to a safe version by stripping tags.
    if (!$wrapper) {
        return strip_tags($html);
    }

    // Extract the inner HTML from our wrapper
    $inner_html = '';
    foreach ($wrapper->childNodes as $child) {
        $inner_html .= $dom->saveHTML($child);
    }

    return $inner_html;
}

/**
 * Builds a cache key.
 */
function frl_build_cache_key(string $key, string $type = 'slug'): string
{
    if ($type === 'slug') {
        return 'slug_' . md5($key);
    }
    return $type . '_' . sanitize_key($key);
}

/**
 * Process PHP string
 * @param string $string The string to process
 * @param string $context The context of the string (function name)
 * @return string processed HTML string
 */
function frl_process_php_string($string, $context = null): string
{
    if (empty($string) || !str_contains($string, '<?'))
        return $string;

    if (null === $context) {
        $context = __FUNCTION__;
    }

    ob_start();
    try {
        $tmp = ' ?>' . $string . '<?php ';
        @token_get_all($tmp, TOKEN_PARSE); // Syntax check - triggers parsing errors
        eval($tmp);
    } catch (ParseError $e) {
        frl_log("{context} HTML PHP syntax error: {error}", ['context' => $context, 'error' => $e->getMessage()]);
        ob_end_clean();
        return "<!-- {$context} PHP error: check logs -->";
    } catch (Throwable $e) {
        frl_log("{context} HTML runtime error: {error}", ['context' => $context, 'error' => $e->getMessage()]);
        ob_end_clean();
        return "<!-- {$context} PHP error: " . $e->getMessage() . " -->";
    }

    return ob_get_clean();
}

/**
 * Normalize an autoload setting value to boolean.
 *
 * @param mixed $value The input value ('yes', 'no', null, etc.)
 * @return bool True for autoload enabled, false otherwise.
 */
function frl_normalize_autoload($value)
{
    return $value !== 'no';
}

/**
 * Decodes a JSON file into an associative array.
 *
 * Reads the file content, decodes it, and returns an associative array.
 * Logs an error and returns an empty array if the file doesn't exist or if decoding fails.
 *
 * @param string $path Full path to the JSON file.
 * @return array The decoded associative array, or an empty array on failure.
 */
function frl_json_decode_file($path)
{
    if (!file_exists($path)) {
        frl_log('Missing .json file in frl_json_decode for path: {path}', ['path' => $path]);
        return [];
    }

    $json_content = file_get_contents($path);

    $json_data = frl_json_decode($json_content, __FUNCTION__);
    if ($json_data === null) {
        return [];
    }

    return $json_data;
}

/**
 * Decodes a JSON string into an associative array.
 *
 * A wrapper for frl_json_decode that ensures an array is always returned.
 *
 * @param string $json The JSON string to decode.
 * @return array The decoded associative array, or an empty array on failure.
 */
function frl_json_decode_string($json)
{
    $json_data = frl_json_decode($json, __FUNCTION__);
    if ($json_data === null) {
        return [];
    }

    return $json_data;
}

/**
 * Decodes a JSON string into an associative array, with error logging.
 *
 * This is the core JSON decoding helper. It attempts to decode a string
 * and returns the result (which can be an array, string, int, etc.), or null.
 * It does NOT enforce that the result must be an array.
 * It will only log an error for a non-empty, malformed JSON string.
 *
 * @param string $json The JSON string to decode.
 * @param string $function The name of the calling function, for logging context.
 * @return mixed The decoded data (array, string, int, etc.), or null on failure/empty input.
 */
function frl_json_decode($json, $function)
{
    if (!is_string($json)) {
        frl_log('Provided content is not a string in {function}', ['function' => $function]);
        return null;
    }

    // An empty string is a valid state, not a JSON error. Silently return null.
    if (trim($json) === '') {
        return null;
    }

    $json_data = json_decode($json, true);

    // After decoding, check only for actual syntax errors.
    $error_code = json_last_error();
    if ($error_code !== JSON_ERROR_NONE) {
        // This now correctly only logs for non-empty, malformed strings.
        frl_log('JSON decode error in {function}: {error}', [
            'function' => $function,
            'error'    => json_last_error_msg()
        ]);
        return null;
    }

    // Return the decoded data, whatever type it is.
    return $json_data;
}

/**
 * Format a filename for display
 * @param string $filename Filename to format
 * @return string Formatted filename
 */
function frl_format_file_name($filename)
{
    if (empty($filename)) {
        return '';
    }

    $formatted_name = str_replace('-', ' ', $filename);
    $formatted_name = ucwords($formatted_name);
    $formatted_name = str_replace('.php', '', $formatted_name);

    return $formatted_name;
}

/**
 * Extract domain from URL
 * @param string $domain Domain to extract
 * @return string Extracted domain
 */
function frl_extract_domain($domain)
{
    if (preg_match("/(?P<domain>[a-z0-9][a-z0-9\-]{1,63}\.[a-z\.]{2,6})$/i", $domain, $matches)) {
        return $matches['domain'];
    } else {
        return $domain;
    }
}

/**
 * Extract subdomain from URL
 * @param string $domain Domain to extract
 * @return string Extracted subdomain
 */
function frl_extract_subdomain($domain)
{
    $subdomain = $domain;
    $domain = frl_extract_domain($subdomain);

    $subdomain = rtrim(strstr($subdomain, $domain, true), '.');

    if (!$subdomain) {
        $subdomain = 'www';
    }

    return $subdomain;
}

/**
 * Get client IP address
 * @return string Client IP address
 */
function frl_get_client_ip()
{
    $ip = filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP);
    if ($ip) return $ip;

    foreach (['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR'] as $key) {
        if ($ip = filter_var($_SERVER[$key] ?? '', FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    return 'UNKNOWN';
}

/**
 * Safely flush the WordPress database connection
 *
 * Use this function to prevent "Commands out of sync" errors when performing
 * multiple sequential database operations. It ensures all result sets are properly
 * freed before proceeding with new queries.
 *
 * @return void
 */
function frl_flush_db()
{
    global $wpdb;
    if (isset($wpdb) && is_object($wpdb) && method_exists($wpdb, 'flush')) {
        $wpdb->flush();
    }
}

/**
 * Log messages with automatic formatting and optional email notification
 *
 * Supports three usage patterns:
 * 1. Variable dump: frl_log($my_array);
 * 2. Message with placeholders: frl_log('User {id} created', ['id' => 123]);
 * 3. Message with full context dump: frl_log('Debug info', ['key' => 'value', 'data' => $obj]);
 *
 * @example frl_log($my_array); // Dumps any variable for inspection
 * @example frl_log('User {id} created', ['id' => 123]); // Interpolates {id} placeholder
 * @example frl_log('Debug data', ['foo' => 1, 'bar' => 2]); // Appends full context array
 * @example frl_log('Critical error', [], true); // Logs and emails admin
 * @example frl_log('Silent log', [], false); // Logs without email
 *
 * @param string|array $message Message string or variable to dump
 * @param array $context Additional context data (supports placeholder interpolation or full dump)
 * @param bool $force_email Whether to email admin (requires plugin option enabled, default true)
 */
function frl_log($message, $context = [], $force_email = true)
{
    // Suppress logging during Log manager requests
    if (frl_is_log_manager_request()) {
        return; // Early exit
    }
    $is_admin_debug = !is_scalar($message) && empty($context) && frl_has_access();

    $debug_send_email = !$is_admin_debug && $force_email && frl_get_option('error_reporting_email');

    // Unified throttling for expensive operations (format + email)
    $message_hash = md5(serialize([$message, $context]));
    $context_suffix = frl_is_admin() ? 'admin' : 'frontend';

    $cache_key = 'logs_' . $context_suffix . '_' . $message_hash;

    $formatted_message = frl_cache_remember(
        'admin',
        $cache_key,
        function () use ($message, $context, $debug_send_email) {
            $formatted = frl_format_log_message($message, $context);

            // Handle email inside unified cache (replaces old email throttling)
            if ($debug_send_email) {
                frl_email_error_notification($formatted);
            }

            return $formatted;
        }
    );

    // Always log to preserve error frequency information
    error_log($formatted_message);
}

/**
 * Create WP_Error with automatic logging for actual problems
 *
 * @param string $code Error code for WP_Error
 * @param string $message Error message (used for both log and WP_Error)
 * @param array $context Additional context for logging
 * @param mixed $error_data Optional data for WP_Error
 * @return WP_Error
 */
function frl_error($code, $message, $context = [], $error_data = '')
{
    // Only skip logging for expected conditions (not actual problems)
    $expected_conditions = ['no_changes'];

    $is_problem = !in_array($code, $expected_conditions);

    if ($is_problem) {
        frl_log($message, $context); // Log + email all actual problems
    }

    return new WP_Error($code, $message, $error_data);
}

/**
 * Dump variables to screen (plugin admin only)
 * Buffers output and displays at end of body via wp_footer hook
 * @param mixed $var Data to dump to screen
 */
function frl_dump($var)
{
    // Security check: only allow plugin admin access
    if (!frl_has_access()) {
        return;
    }

    // Buffer debug output instead of immediate echo
    static $debug_buffer = [];
    static $hook_added = false;

    $formatted_message = frl_format_log_message($var, []);
    $dump_message = str_replace('Frl_LOG:', strtoupper(FRL_PREFIX) . '_DUMP:', $formatted_message);

    // Store in buffer
    $debug_buffer[] = $dump_message;

    // Add footer hook only once
    if (!$hook_added && !frl_is_doing_ajax()) {
        add_action('wp_footer', function () use (&$debug_buffer) {
            if (empty($debug_buffer)) return;

            echo '<div id="frl-debug-panel" style="position: fixed; bottom: 10px; right: 10px; width: 420px; max-height: 420px; overflow-y: auto; z-index: 999999; background: white; border: 2px solid var(--admin-accent-color); border-radius: 6px; box-shadow: 0 4px 12px rgba(0,0,0,0.3);">';
            echo '<div style="background: var(--admin-accent-color); color: white; padding: 8px 12px; font-weight: bold; font-size: 13px; display: flex; justify-content: space-between; align-items: center;">';
            echo '<span>🐛 FRL Debug (' . count($debug_buffer) . ' items)</span>';
            echo '<button onclick="document.getElementById(\'frl-debug-content\').style.display = document.getElementById(\'frl-debug-content\').style.display === \'none\' ? \'block\' : \'none\'; this.innerHTML = this.innerHTML === \'−\' ? \'+\' : \'−\';" style="background: none; border: none; color: white; font-size: 16px; cursor: pointer; padding: 0; width: 20px; height: 20px;">−</button>';
            echo '</div>';
            echo '<div id="frl-debug-content" style="max-height: 320px; overflow-y: auto;">';

            foreach ($debug_buffer as $index => $message) {
                echo '<div style="background: #f9f9f9; border-bottom: 1px solid #ddd; padding: 12px; font-family: \'Courier New\', monospace; font-size: 13px; line-height: 1.5; color: #333; white-space: pre-wrap;">' . esc_html($message) . '</div>';
            }

            echo '</div>';
            echo '<div style="text-align: center; padding: 8px; background: #f1f1f1; border-top: 1px solid #ddd; font-size: 11px; color: #666;">Debug panel (plugin admin only)</div>';
            echo '</div>';
        }, 999);
        $hook_added = true;
    }
}

/**
 * Format log messages with context and caller information
 *
 * @param string|array $message Message or data to format
 * @param array $context Additional context data
 * @return string Formatted log message
 */
function frl_format_log_message($message, $context = [])
{
    $log_message = strtoupper(FRL_PREFIX) . '_LOG: ';

    // --- Determine Context ---
    $caller_info = '';
    // Get a limited backtrace for performance, but deep enough for context.
    $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6);

    // List of internal utility functions to skip over to find the real caller.
    $internal_helpers = [
        'frl_log',
        'frl_dump',
        'frl_format_log_message',
        'frl_json_decode',
        'frl_json_decode_string',
        'frl_json_decode_file',
        'frl_normalize_to_array',
        'frl_cache_remember',
        '{closure}',            // skip anonymous closures
        'call_user_func_array', // skip wrapper used by frl_cache_remember
        'call_user_func',       // skip wrapper used by frl_cache_remember
    ];

    // Find the first call in the stack that is NOT one of our internal helpers.
    foreach ($trace as $trace_item) {
        if (!isset($trace_item['function'])) {
            continue;
        }
        $function_name = $trace_item['function'];
        $class_name    = $trace_item['class'] ?? null;

        // Skip internal helpers and cache wrappers
        $skip = in_array($function_name, $internal_helpers, true);

        // Also skip cache manager remember layer to surface the real caller
        if (!$skip && $function_name === 'remember' && $class_name !== null) {
            if (str_contains($class_name, 'Cache_Manager')) {
                $skip = true;
            }
        }

        // Skip any frame belonging to the cache manager class (static helpers)
        if (!$skip && $class_name === 'Frl_Cache_Manager') {
            $skip = true;
        }

        // Skip the wrapper call site when it shows up as ::remember with wrapper file
        if (!$skip && $function_name === 'remember') {
            $file_path = $trace_item['file'] ?? '';
            if ($file_path && basename($file_path) === 'functions-class-helpers.php') {
                $skip = true;
            }
        }

        if (!$skip) {
            $caller_name = $class_name ? "{$class_name}::{$function_name}" : $function_name;
            $file = isset($trace_item['file']) ? basename($trace_item['file']) : 'unknown file';
            $line = isset($trace_item['line']) ? (int)$trace_item['line'] : 0;
            $caller_info = "\n  ↳ Called from: {$caller_name} in {$file}:{$line}";
            break; // Found the source, stop searching.
        }
    }

    $processed_message = $message;

    // Smart interpolation: If the message is a string with {placeholders}, replace them.
    if (is_string($processed_message) && str_contains($processed_message, '{')) {
        $replacements = [];
        foreach ($context as $key => $val) {
            if (str_contains($processed_message, '{' . $key . '}')) {
                // Ensure value is suitable for string replacement
                if (is_scalar($val) || (is_object($val) && method_exists($val, '__toString'))) {
                    $replacements['{' . $key . '}'] = $val;
                } elseif (is_array($val) || is_object($val)) {
                    // For arrays/objects, replace with a compact JSON string
                    $replacements['{' . $key . '}'] = json_encode($val, JSON_PARTIAL_OUTPUT_ON_ERROR);
                }
            }
        }

        if (!empty($replacements)) {
            $processed_message = strtr($processed_message, $replacements);
        }
    }

    // Handle message based on type
    if (is_object($processed_message)) {
        // Default: dump object data with nice formatting
        $class_name = get_class($processed_message);
        $log_message .= "Object ({$class_name}):\n";

        try {
            // First attempt: direct JSON encode (works for stdClass/JsonSerializable)
            $json = json_encode($processed_message, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_PRETTY_PRINT);

            // Fallback: encode public properties
            if ($json === false || $json === '{}' || $json === 'null') {
                $props = get_object_vars($processed_message);
                $json = json_encode($props, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_PRETTY_PRINT);
                if ($json === false) {
                    // Last resort: readable print_r
                    $json = print_r($props, true);
                }
            }

            $log_message .= $json;
        } catch (Throwable $e) {
            $log_message .= '<unserializable object: ' . $e->getMessage() . '>';
        }
    } elseif (is_array($processed_message)) {
        // Handle arrays with better formatting and error handling
        $json = json_encode($processed_message, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_PRETTY_PRINT);
        if ($json === false) {
            $log_message .= "Array (failed to encode: " . json_last_error_msg() . ")";
        } else {
            $log_message .= "Array:\n" . $json;
        }
    } else {
        // Simple types
        $log_message .= is_null($processed_message) ? "NULL" : (is_bool($processed_message) ? ($processed_message ? "TRUE" : "FALSE") : (is_string($processed_message) && $processed_message === "" ? "<empty string>" : (string)$processed_message));
    }

    // Append request URL and caller info as the last lines
    $log_message = frl_log_add_details($log_message, '  ');
    $log_message .= $caller_info;

    return $log_message;
}

/**
 * Append common diagnostic details to a log line (URL, etc.)
 *
 * @param string $message Base log message
 * @param string $indent Optional indentation before details markers (e.g., two spaces for UI alignment)
 * @return string Message with details appended
 */
function frl_log_add_details(string $message, string $indent = ''): string
{
    // URL
    $url = frl_get_request_url();
    if ($url !== '') {
        $message .= "\n" . $indent . '↳ URL: ' . $url;
    }

    $current_query_line = '';
    // Current (non-main) query vars when inside a block render
    if (!empty($GLOBALS['frl_block_stack']) && !empty($GLOBALS['frl_current_query_vars'])) {
        $qv = $GLOBALS['frl_current_query_vars'];
        $subset_keys = ['posts_per_page', 'post_type', 'post__in', 'orderby', 'order', 'tax_query'];
        $subset = array_intersect_key($qv, array_flip($subset_keys));
        if (!empty($subset)) {
            $json = json_encode($subset, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json) {
                $current_query_line = "\n" . $indent . '↳ CurrentQueryVars: ' . $json;
            }
        }
    }

    // Current block from stack
    if (!empty($GLOBALS['frl_block_stack'])) {
        $blk = end($GLOBALS['frl_block_stack']);
        if (is_array($blk)) {
            $blk_line = $blk['name'] ?? '';
            if (!empty($blk['attrs'])) {
                $pairs = [];
                foreach ($blk['attrs'] as $k => $v) {
                    if (is_scalar($v) && $v !== '') {
                        $pairs[] = $k . '=' . (string)$v;
                    }
                }
                if (!empty($pairs)) {
                    $blk_line .= ' ' . implode(' ', $pairs);
                }
            }
            if ($blk_line !== '') {
                $message .= "\n" . $indent . '↳ CurrentBlock: ' . $blk_line;
            }

            // Collect the full chain of ancestor patterns for a clear hierarchy
            $pattern_chain = [];
            if (!empty($GLOBALS['frl_block_stack'])) {
                foreach ($GLOBALS['frl_block_stack'] as $ancestor_block) {
                    if (!empty($ancestor_block['pattern'])) {
                        $pattern_chain[] = (string) $ancestor_block['pattern'];
                    }
                }
            }
            if (!empty($pattern_chain)) {
                $message .= "\n" . $indent . '↳ Pattern: ' . implode(' > ', $pattern_chain);
            }
        }
    }

    // Hook name
    if (function_exists('current_filter') && ($hook = current_filter())) {
        $message .= "\n" . $indent . '↳ Hook: ' . $hook;
    }

    // Last executed shortcode
    if (!empty($GLOBALS['frl_last_shortcode']) && is_array($GLOBALS['frl_last_shortcode'])) {
        $sc = $GLOBALS['frl_last_shortcode'];
        $tag = $sc['tag'] ?? '';
        if ($tag) {
            $attrs_line = '';
            if (!empty($sc['attrs'])) {
                $pairs = [];
                foreach ($sc['attrs'] as $k => $v) {
                    $pairs[] = sprintf('%s="%s"', $k, esc_attr($v));
                }
                $attrs_line = ' ' . implode(' ', $pairs);
            }
            $message .= "\n" . $indent . '↳ Shortcode: [' . $tag . $attrs_line . ']';
        }
    }

    // Append CurrentQueryVars at the very end
    if ($current_query_line !== '') {
        $message .= $current_query_line;
    }

    return $message;
}

function frl_log_capture_render_block_enter($block)
{
    if (!is_array($block)) {
        return $block;
    }

    $name = $block['blockName'] ?? '';
    $attrs = $block['attrs'] ?? [];

    // Use array_flip for O(m) iteration instead of O(n) - iterate actual attributes instead of whitelist
    static $whitelist_flip = null;
    if ($whitelist_flip === null) {
        $whitelist_flip = array_flip(['id', 'className', 'tag', 'type', 'slug', 'area', 'theme', 'ref', 'anchor', 'uniqueId', 'blockId', 'clientId', 'queryId', 'perPage', 'order', 'orderby', 'taxonomy', 'terms', 'repeaterField', 'postId', 'sourceType', 'dynamicField']);
    }

    $filtered = [];
    foreach ($attrs as $key => $val) {
        if (isset($whitelist_flip[$key])) {
            if (is_scalar($val)) {
                $filtered[$key] = (string)$val;
            } elseif (is_array($val)) {
				// Safely stringify nested arrays/objects for logging without PHP warnings
				$converted = array_map(function ($item) {
					if (is_scalar($item) || (is_object($item) && method_exists($item, '__toString'))) {
						return (string) $item;
					}
					if (is_array($item)) {
						$json = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
						return ($json !== false) ? $json : '[array]';
					}
					return '[' . gettype($item) . ']';
				}, $val);
				$filtered[$key] = implode(',', $converted);
            }
        }
    }

    // Preserve repeaterArray for shortcode context resolution (Greenshift, Jet Engine, etc.)
    $repeater_array = null;
    if (!empty($attrs['repeaterArray']) && is_array($attrs['repeaterArray'])) {
        $repeater_array = $attrs['repeaterArray'];
    }

    if (!isset($GLOBALS['frl_block_stack'])) {
        $GLOBALS['frl_block_stack'] = [];
    }
    $pattern_info = $attrs['metadata']['name'] ?? '';

    $GLOBALS['frl_block_stack'][] = [
        'name' => $name,
        'attrs' => $filtered,
        'pattern' => (string)$pattern_info,
        'repeaterArray' => $repeater_array,
    ];
    return $block;
}

function frl_log_capture_render_block_exit($block_content, $block)
{
    if (isset($GLOBALS['frl_block_stack']) && !empty($GLOBALS['frl_block_stack'])) {
        array_pop($GLOBALS['frl_block_stack']);
    }
    return $block_content;
}

function frl_log_capture_query($query)
{
    if (is_object($query) && method_exists($query, 'is_main_query') && !$query->is_main_query()) {
        if (!empty($GLOBALS['frl_block_stack']) && isset($query->query_vars)) {
            $GLOBALS['frl_current_query_vars'] = $query->query_vars;
        }
    }
}

function frl_log_capture_shortcode($output, $tag, $attr, $m)
{
    $attrs = is_array($attr) ? $attr : [];
    $safe_attrs = [];
    foreach ($attrs as $key => $value) {
        if (is_scalar($value)) {
            $safe_attrs[$key] = (string) $value;
        }
    }

    $GLOBALS['frl_last_shortcode'] = [
        'tag'   => (string) $tag,
        'attrs' => $safe_attrs,
    ];
    return $output;
}

/**
 * Build full request URL (scheme://host/path?query) or empty string when unavailable.
 */
function frl_get_request_url(): string
{
    // CLI/cron marker
    if (PHP_SAPI === 'cli') {
        return 'CLI_REQUEST';
    }

    // Host detection
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '' && isset($_SERVER['SERVER_NAME'])) {
        $host = (string) $_SERVER['SERVER_NAME'];
    }

    // Path detection
    $path = $_SERVER['REQUEST_URI'] ?? '';
    if ($path === '') {
        $script = $_SERVER['SCRIPT_NAME'] ?? ($_SERVER['PHP_SELF'] ?? '/');
        $qs = $_SERVER['QUERY_STRING'] ?? '';
        $path = $script . ($qs !== '' ? '?' . $qs : '');
    }

    // Scheme and final fallbacks using WordPress context when available
    $scheme = 'http';
    if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
    ) {
        $scheme = 'https';
    } elseif (function_exists('is_ssl') && is_ssl()) {
        $scheme = 'https';
    }

    if (function_exists('home_url')) {
        $home = home_url('/');
        $parsed = @parse_url($home);
        if (is_array($parsed)) {
            if ($host === '' && !empty($parsed['host'])) {
                $host = (string) $parsed['host'];
            }
            if ($scheme === 'http' && !empty($parsed['scheme'])) {
                $scheme = (string) $parsed['scheme'];
            }
        }
    }

    if ($host === '') {
        // Last resort: return path if present, else empty string
        return $path !== '' ? $path : '';
    }

    return $scheme . '://' . $host . $path;
}

/**
 * Send error notification email to admin (throttling now handled by frl_log)
 *
 * @param string $formatted_message The formatted error message to send
 */
function frl_email_error_notification($formatted_message)
{
    // Rate limiting check (preserves original rate limiting)
    $email_to = FRL_EMAIL_NOTIFICATIONS['to'];
    $rate_key = FRL_EMAIL_NOTIFICATIONS['rate_key'];
    $rate_limit = FRL_EMAIL_NOTIFICATIONS['rate_limit'];
    $rate_interval = FRL_EMAIL_NOTIFICATIONS['rate_interval'];

    $current_count = frl_get_transient($rate_key) ?: 0;
    if ($current_count >= $rate_limit) {
        if ($current_count === $rate_limit) {
            error_log(
                sprintf(
                    'FRL_LOGS: Rate limit of %s emails per minute exceeded',
                    $rate_limit
                )
            );
        }
        return false; // Rate limit exceeded
    }

    // Prepare email content
    $site_name = get_bloginfo('name');
    $site_url = home_url();
    $timestamp = current_time('Y-m-d H:i:s');

    $subject = sprintf(
        '%s notification on %s',
        strtoupper(FRL_PREFIX) . '_LOG: ',
        $site_url
    );

    $body = sprintf(
        "Plugin log notification on %s\nSite: %s\nTime: %s\n\n%s",
        $site_name,
        $site_url,
        $timestamp,
        $formatted_message
    );

    $header = 'From: ' . frl_name() . ' Plugin';

    // Send email
    $sent = frl_email($email_to, $subject, $body, $header);

    if ($sent) {
        // Update rate limit counter
        frl_set_transient($rate_key, $current_count + 1, $rate_interval);
    }

    return $sent;
}

/**
 * Email utility to use WordPress mail or PHP mail directly
 *
 * @param string $email_to The email address to send to
 * @param string $subject The subject of the email
 * @param string $body The body of the email
 * @param string $header The header of the email
 */
function frl_email($email_to, $subject, $body, $header)
{
    if (function_exists('wp_mail')) {
        // Use WordPress mail
        $headers = [$header . ' <' . $email_to . '>'];
        return wp_mail($email_to, $subject, $body, $headers);
    } else {
        // Use PHP mail directly
        $headers = $header . " <{$email_to}>\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "\n\nEmail sent by mail()";
        return mail($email_to, $subject, $body, $headers);
    }
}

/**
 * Prepare plugin settings for export.
 *
 * Adds prefix to keys and cleans up whitespace in values.
 * Matches the format used by manual export for import compatibility.
 *
 * @param array $settings Raw settings from frl_get_plugin_options_db()
 * @return array Formatted settings ready for JSON export
 */
function frl_prepare_settings_for_export(array $settings): array
{
    $export = [];
    foreach ($settings as $key => $value) {
        if (is_string($value)) {
            // Convert newlines to \n and remove extra whitespace
            $value = str_replace(["\r\n", "\r"], "\n", $value);
            $lines = explode("\n", $value);
            $lines = array_map('trim', $lines);
            $value = implode("\n", array_filter($lines));
        }
        $export[frl_prefix($key)] = $value;
    }
    return $export;
}
