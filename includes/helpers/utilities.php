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
 * Minify CSS by removing comments, unnecessary whitespace, and trailing semicolons.
 *
 * @param string $css The CSS string to minify.
 * @return string The minified CSS.
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
 * Get the modification timestamps of assets to use as version strings.
 *
 * @param array $assets Associative array of asset handles and their paths.
 * @param string $key Cache key for the versions.
 * @param string $group Cache group for the versions.
 * @param bool $log_errors Whether to log errors if an asset file is missing.
 * @return array Associative array of asset handles and their modification times.
 */
function frl_get_assets_versions($assets, $key = 'general', $group = 'versions', $log_errors = true)
{
    $versions = frl_cache_remember($group, $key, function () use ($assets, $key, $log_errors) {
        $versions_array = [];
        foreach ($assets as $handle => $path) {
            // Determine if path is absolute or relative.
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
 * Format a filename for display.
 *
 * Replaces hyphens with spaces, capitalizes words, and removes the .php extension.
 *
 * @param string $filename Filename to format.
 * @return string The formatted filename.
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
 * Check if a variable (or a specific key within it) is a non-empty array.
 *
 * @param mixed $var The variable to check.
 * @param string|null $key Optional key to check within $var.
 * @return bool True if the target is a non-empty array, false otherwise.
 */
function frl_is_array_not_empty($var, $key = null)
{
    if ($key !== null) {
        return isset($var[$key]) && is_array($var[$key]) && !empty($var[$key]);
    }
    return !empty($var) && is_array($var);
}

/**
 * Convert a newline-separated text list into an array.
 *
 * Handles simple lines and lines with pipe (|) separators.
 * Pipe-separated lines are converted into sub-arrays.
 *
 * @param string $input The textlist string content.
 * @return array Processed array. Empty if input is empty or not a string.
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
 * Group an array of associative arrays by a specific key.
 *
 * @param string $key The property to group by.
 * @param array $data The array of associative arrays to group.
 * @return array The grouped array.
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
 * Convert a value to a WordPress-friendly '1' or '0' string.
 *
 * Supports booleans, integers (0, 1), and truthy/falsy strings
 * (e.g., 'true'/'false', 'yes'/'no', 'on'/'off').
 *
 * @param mixed $value The value to convert.
 * @return string '1' for truthy, '0' for falsy, or the original value if not boolean-like.
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
 * Coerce mixed values to a string using optional selectors.
 *
 * - Scalars are cast to string.
 * - For arrays/objects, the first matching scalar key/property from $preferredSelectors is returned.
 * - The ':first' selector returns the first scalar value found.
 *
 * @param mixed $value The value to coerce.
 * @param array $preferredSelectors Priority list of keys/properties; may include ':first'.
 * @return string The coerced string, or an empty string if no match is found.
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
            // Ensure value is scalar.
            if (is_scalar($v)) {
                return (string)$v;
            }
            return null;
        }
        if (is_object($container) && isset($container->{$key})) {
            $v = $container->{$key};
            // Ensure value is scalar.
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
 * Normalize a text list by trimming lines and removing empty ones.
 *
 * @param mixed $input The input string to normalize.
 * @return string Normalized text with Unix line endings (\n), or an empty string if input is not a string.
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
 * Match a string against an array of patterns using '%' as a wildcard.
 *
 * - '%' matches any sequence of characters (including empty).
 * - All other characters are treated literally.
 *
 * @param string $string The string to test.
 * @param array $patterns Array of patterns to match against.
 * @return bool True if the string matches any of the patterns, false otherwise.
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
 * Normalize any input into a non-empty array.
 *
 * Converts arrays, objects, and JSON strings to arrays. Wraps scalar values
 * in an array. Returns an empty array for null or empty strings.
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
 * Check if a string contains any of the substrings provided in an array.
 *
 * @param string $string The string to check.
 * @param array $substrings Array of substrings to search for.
 * @return bool True if any substring is found, false otherwise.
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
 * Process a string containing PHP code and return the rendered output.
 *
 * @param string $string The string to process.
 * @param string|null $context The context for error logging (e.g., function name).
 * @return string The processed HTML output.
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
 * Internal recursive helper to convert unserialisable values into plain data.
 *
 * Converts Closures and object-method arrays into descriptive strings or arrays
 * to ensure the structure can be safely cached or serialized.
 *
 * @param mixed $v The value to sanitize (passed by reference).
 * @param int $depth Current recursion depth to prevent infinite loops.
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
 * Sanitize a value for serialization by removing closures and limiting depth.
 *
 * Ensures that the data structure can be safely stored in a persistent cache.
 *
 * @param mixed $value The value to sanitize (passed by reference).
 * @return void
 */
function frl_sanitize_for_serialization(&$value): void
{
    _frl_sanitize_recursive($value);
}

/**
 * Validate and correct an HTML fragment to ensure it is well-formed.
 *
 * Uses DOMDocument to automatically correct issues like unclosed tags.
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
 * Normalize an autoload setting value to a boolean.
 *
 * @param mixed $value The input value (e.g., 'yes', 'no', null).
 * @return bool True if autoload is enabled, false otherwise.
 */
function frl_normalize_autoload($value)
{
    return $value !== 'no';
}

/**
 * Get the client's IP address.
 *
 * Checks REMOTE_ADDR, HTTP_CLIENT_IP, and HTTP_X_FORWARDED_FOR.
 *
 * @return string The client IP address, or 'UNKNOWN' if not found.
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
 * Build the full request URL (scheme://host/path?query).
 *
 * Returns 'CLI_REQUEST' for CLI/cron environments or an empty string if the host cannot be determined.
 *
 * @return string The full request URL.
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
 * Strip known environment and canonical prefixes from a domain.
 *
 * Strips in order:
 * 1. Staging prefixes defined in FRL_ENV_STAGING_PREFIXES (e.g., staging., dev.).
 * 2. The 'www.' prefix.
 *
 * @param string $domain The domain to strip.
 * @return string The base domain.
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
 * Extract the domain from a URL or string.
 *
 * @param string $domain The string to extract the domain from.
 * @return string The extracted domain, or the original string if no match is found.
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
 * Extract the subdomain from a domain string.
 *
 * @param string $domain The domain to extract the subdomain from.
 * @return string The extracted subdomain, or 'www' if none is found.
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
 * Filter out domains that start with defined staging or environment prefixes.
 *
 * Uses FRL_ENV_STAGING_PREFIXES for consistent prefix detection.
 *
 * @param array $domains Array of domain strings.
 * @return array Filtered array of domains.
 */
function frl_filter_staging_domains(array $domains): array
{
    return array_filter(
        $domains,
        function ($domain) {
            // If domain starts with any staging prefix, filter it out (return false)
            foreach (FRL_ENV_STAGING_PREFIXES as $prefix) {
                if (stripos($domain, $prefix) === 0) {
                    return false;
                }
            }
            return true;
        }
    );
}

/**
 * Set a cookie-based flag to force a page reload on the next admin load.
 *
 * Typically used during environment switching to ensure the browser refreshes.
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
 * Check for the force reload flag and clear it if present.
 *
 * @return bool True if the flag was set and cleared, false otherwise.
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
 * Decode a JSON file into an associative array.
 *
 * Reads the file content and decodes it. Logs an error and returns an empty
 * array if the file is missing or decoding fails.
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
 * Decode a JSON string into an associative array.
 *
 * A wrapper for frl_json_decode that guarantees an array return value.
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
 * Decode a JSON string with error logging.
 *
 * Core JSON decoding helper. Returns the decoded data (array, string, int, etc.)
 * or null on failure/empty input. Logs errors only for non-empty, malformed strings.
 *
 * @param string $json The JSON string to decode.
 * @param string $function The calling function name for logging context.
 * @return mixed The decoded data, or null on failure/empty input.
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

