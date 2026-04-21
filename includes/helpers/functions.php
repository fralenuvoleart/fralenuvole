<?php

/**
 * Fralenuvole
 * functions.php - Plugin Bootstrap and Core Functions
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// =============================================================================
// BOOTSTRAP: Load Helper Files
// =============================================================================

require_once FRL_DIR_PATH . 'includes/helpers/utilities.php';
require_once FRL_DIR_PATH . 'includes/helpers/functions-options.php';
require_once FRL_DIR_PATH . 'includes/helpers/functions-class-helpers.php';
require_once FRL_DIR_PATH . 'includes/helpers/functions-modules.php';
require_once FRL_DIR_PATH . 'includes/helpers/functions-plugin-actions.php';

// =============================================================================
// CORE FUNCTIONS: Basic Utilities
// =============================================================================

/**
 * Return prefixed string.
 * @param  string $name The name to prefix
 */
function frl_prefix($name = '')
{
    $prefixed = FRL_PREFIX . '_';

    if (!empty($name)) {
        $prefixed .= $name;
    }
    return $prefixed;
}

/**
 * Get the formatted plugin name.
 * @return string Formatted plugin name (e.g., 'Fralenuvole')
 */
function frl_name($name_prefix = '')
{
    $base_name = ucfirst(FRL_NAME);
    // Add a space only if a prefix is provided
    if (!empty($name_prefix)) {
        return $name_prefix . ' ' . $base_name;
    }
    // Otherwise, return just the base name
    return $base_name;
}

// =============================================================================
// CORE FUNCTIONS: User Authentication & Access Control
// =============================================================================

/**
 * Check if user has access
 * @param string $capability Capability to check for
 * @return bool True if user has access, false otherwise
 */
/**
 * Get user object from auth cookie during early WordPress loading (before plugins_loaded).
 * Used when $current_user is not yet available.
 *
 * @return WP_User|false User object on success, false on failure
 */
function frl_get_auth_cookie_user()
{
    // During muplugins_loaded, LOGGED_IN_COOKIE and COOKIEHASH may not be defined
    // Try to find the logged-in cookie by checking common patterns
    $cookie_name = null;
    
    if (defined('LOGGED_IN_COOKIE') && isset($_COOKIE[LOGGED_IN_COOKIE])) {
        $cookie_name = LOGGED_IN_COOKIE;
    } elseif (defined('COOKIEHASH')) {
        $fallback = 'wordpress_logged_in_' . COOKIEHASH;
        if (isset($_COOKIE[$fallback])) {
            $cookie_name = $fallback;
        }
    } else {
        // Scan cookies for WordPress auth cookie pattern
        foreach ($_COOKIE as $key => $value) {
            if (strpos($key, 'wordpress_logged_in_') === 0) {
                $cookie_name = $key;
                break;
            }
        }
    }
    
    if (!$cookie_name || !isset($_COOKIE[$cookie_name])) {
        return false;
    }
    
    $cookie = wp_parse_auth_cookie($_COOKIE[$cookie_name], 'logged_in');
    
    if (empty($cookie['username'])) {
        return false;
    }
    
    $user = get_user_by('login', $cookie['username']);
    
    return ($user && !is_wp_error($user)) ? $user : false;
}

/**
 * Check if user has access
 * @param string $capability Capability to check for
 * @return bool True if user has access, false otherwise
 */
function frl_has_access($capability = FRL_PLUGIN_ACCESS)
{
    // During muplugins_loaded (before plugins_loaded):
    // Parse auth cookie directly since $current_user not set up yet
    if (!did_action('plugins_loaded')) {
        $user = frl_get_auth_cookie_user();
       frl_log($user);
        
        if ($user) {
            return user_can($user, $capability);
        }
        
        // No valid cookie or not logged in during early loading
        return false;
    }
    
    // After plugins_loaded: current_user_can is available
    if (!function_exists('current_user_can')) {
        return false;
    }

    if (!$capability) {
        $capability = FRL_PLUGIN_ACCESS;
    }

    // Bypass access check if in migrate mode (break-glass mechanism)
    if (defined('FRL_MODE') && FRL_MODE === 'migrate') {
        return true;
    }

    // Get the current user (already cached by frl_get_current_user)
    $user = frl_get_current_user();

    // Dedicated superadmin check - ONLY user ID 1
    if ($capability === 'superadmin') {
        return $user->ID === 1;
    }

    // Check for user ID 1 (first admin) - bypass for all other capabilities
    if ($user->ID === 1) {
        return true;
    }

    // Use remember pattern for plugin cache
    $cache_key = "user_uid{$user->ID}_can_{$capability}";

    $result = frl_cache_remember('admin', $cache_key, function () use ($user, $capability) {
        return $user->has_cap($capability);
    }, 300);

    return $result;
}

/**
 * ===================================================================
 * CORE FUNCTIONS: Context Detection (frl_is_* functions
 * ===================================================================
*/

/**
 * Enhanced is_admin() function that handles edge cases
 *
 * This function provides a more reliable way to detect admin contexts by accounting
 * for various edge cases that the standard is_admin() function might miss:
 * - admin-post.php processing
 * - admin-ajax.php requests (differentiates between admin and frontend origins)
 * - Login/Register pages
 * - WordPress cron jobs (optional inclusion)
 *
 * It implicitly uses the default behavior where admin-originated AJAX is considered TRUE.
 *
 * @param string|null $page Optional page to check against $_GET['page']
 * @return bool True if the current request is considered an admin request
 */
function frl_is_admin()
{
    static $is_admin = null;
    if ($is_admin !== null) {
        return $is_admin;
    }

    $include_login = true;
    $include_post = true;
    // $include_cron = false; // Original state had these commented or not present
    // $include_rest = false;

    $is_standard_admin_constant = defined('WP_ADMIN') && WP_ADMIN;

    $current_url = $_SERVER['REQUEST_URI'] ?? '';
    // Fix: Use str_contains to support subdirectory installations
    $is_request_targeting_admin_area = str_contains($current_url, '/wp-admin/');

    $is_true_admin_page_load = $is_standard_admin_constant && $is_request_targeting_admin_area;

    if ($is_true_admin_page_load) {
        return $is_admin = true;
    }

    $is_admin_post = $include_post && str_contains($current_url, 'admin-post.php');
    if ($is_admin_post) {
        return $is_admin = true;
    }

    $is_ajax = frl_is_doing_ajax();
    if ($is_ajax) {
        $referer = wp_get_referer();
        // Optimization: Avoid calling admin_url() and its associated filters
        if (!empty($referer) && str_contains($referer, '/wp-admin/')) {
            return $is_admin = true;
        }
        $action = $_REQUEST['action'] ?? '';
        if (str_starts_with($action, 'admin_') || str_starts_with($action, 'settings_')) {
            return $is_admin = true;
        }
        return $is_admin = false;
    }

    if ($include_login) {
        global $pagenow;
        $login_pages = ['wp-login.php', 'wp-register.php'];
        $is_login_page = isset($pagenow) && in_array($pagenow, $login_pages);
        if ($is_login_page) {
            return $is_admin = true;
        }
    }

    return $is_admin = false;
}

/**
 * Check if we're on a specific admin page
 *
 * @param string $page Page slug to check for
 * @param string $param URL parameter to check against (default: 'page')
 * @return bool True if we're on the specified admin page
 */
function frl_is_admin_page($page, $param = 'page')
{
    // First check if we're in admin area
    if (!frl_is_admin()) {
        return false;
    }

    global $pagenow;
    if (is_string($page) && str_contains($page, '.php')) {
        return $pagenow === $page;
    }

    return isset($_GET[$param]) && $_GET[$param] === $page;
}

/**
 * Check if user is logged in with caching
 * @return bool True if user is logged in
 */
function frl_is_logged_in()
{
    // Leverage frl_get_current_user which is already cached
    return frl_get_current_user()->ID > 0;
}

/**
 * Check if a function has already been initialized during this request
 * Use __FUNCTION__ or a more specific key if needed. For methods use __METHOD__ in classes use __CLASS__.
 * @param string $function_key Unique identifier for the function (typically __FUNCTION__)
 * @param bool $reset Optional. Whether to reset the initialization state
 * @return bool True if already initialized, false otherwise
 */
function frl_is_already_running($function_key, $reset = false)
{
    static $initialized = array();

    if ($reset) {
        $initialized[$function_key] = false;
        return false;
    }

    if (isset($initialized[$function_key]) && $initialized[$function_key]) {
        return true; // Already initialized
    }

    $initialized[$function_key] = true;
    return false; // First initialization
}

/**
 * Check if we're on the plugin's settings page or handling plugin requests
 *
 * Detects plugin-related contexts in two ways:
 * 1. Direct settings page detection via 'page' parameter
 * 2. Plugin actions in any admin context (admin-post.php, admin-ajax.php, or regular admin pages)
 *    Note: This includes admin-post.php and admin-ajax.php requests where action starts with 'frl_'
 *
 * @return bool True if on plugin page or handling plugin requests
 */
function frl_is_plugin_context()
{
    static $is_plugin_context = null;
    if ($is_plugin_context !== null) {
        return $is_plugin_context;
    }

    if (isset($_GET['page']) && $_GET['page'] === FRL_NAME) {
        return $is_plugin_context = true;
    }

    if (!frl_is_admin()) {
        return $is_plugin_context = false;
    }

    $action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';
    return $is_plugin_context = str_starts_with($action, frl_prefix());
}

/**
 * Checks if a given URL corresponds to a homepage.
 *
 * A URL is considered a homepage if its path is the root ('/') or a two-letter language code
 * (e.g., '/en/', '/ru/'). It handles URLs with or without a trailing slash.
 *
 * @param string $url The URL to check.
 * @return bool True if the URL is a homepage, false otherwise.
 */
function frl_is_homepage_url($url)
{
    if (empty($url) || !is_string($url)) {
        return false;
    }

    $path = wp_parse_url($url, PHP_URL_PATH);

    // Root URL (e.g., https://example.com or https://example.com/)
    if (empty($path) || $path === '/') {
        return true;
    }

    // Trim slashes to handle both '/en' and '/en/'
    $trimmed_path = trim($path, '/');

    // Language-specific homepage (e.g., /en, /ru, /zh)
    // Checks if the path is exactly two characters long and contains no other slashes.
    if (strlen($trimmed_path) === 2 && strpos($trimmed_path, '/') === false) {
        return true;
    }

    return false;
}

/**
 * Check if the current request is VALID for standard page processing/tracking
 * or explicit admin action (admin-post.php or administrator AJAX request)
 * It is considered NOT VALID in contexts such as:
 * - CLI (Command Line Interface) execution.
 * - Requests to unmapped hostnames or invalid FRL_ENV_MAP configurations.
 * - WordPress REST API requests.
 * - WordPress CRON job executions.
 * - Specific AJAX requests like those from the Log Manager.
 * - Other AJAX requests that are not classified as administrator actions (considered background tasks).
 *
 * @return bool True if the request is considered valid for processing, false otherwise.
 */
function frl_is_valid_page_request(): bool
{
    static $is_valid = null;
    if ($is_valid !== null) return $is_valid;

    if (frl_is_cli_request()) return $is_valid = false;
    if (frl_is_rest_api_request()) return $is_valid = false;
    if (frl_is_cron_job_request()) return $is_valid = false;
    if (!frl_is_valid_environment_host()) return $is_valid = false;
    if (frl_is_heartbeat_ajax_request()) return $is_valid = false;
    if (frl_is_log_manager_request()) return $is_valid = false;
    if (!frl_is_html_document_request()) return $is_valid = false;

    if (frl_is_administrator_action()) return $is_valid = true;
    if (frl_is_doing_ajax()) return $is_valid = false;

    return $is_valid = true;
}

/**
 * Checks if the current context is a valid frontend page request.
 *
 * This function ensures that frontend-specific logic (like field translation)
 * only runs during a standard page view and is skipped during admin, REST,
 * CLI, AJAX, or cron requests.
 *
 * @return bool True if it's a valid frontend page request, false otherwise.
 */
function frl_is_valid_frontend_page_request(): bool
{
    // A request is a valid frontend request if it is not in the admin backend
    // AND it passes all the general validation checks.
    return !frl_is_admin() && frl_is_valid_page_request();
}

/**
 * Check if the current request is an administrator action (admin-post or has an 'action' parameter).
 *
 * This covers admin-post.php links, standard admin pages with an 'action' query param,
 * and AJAX requests with an 'action' parameter.
 *
 * @return bool True if the request is an administrator action, false otherwise.
 */
function frl_is_administrator_action()
{
    static $is_action = null;
    if ($is_action !== null) {
        return $is_action;
    }

    // Check for admin-post.php first
    $current_url = $_SERVER['REQUEST_URI'] ?? '';
    if (str_contains($current_url, 'admin-post.php')) {
        return $is_action = true;
    }

    // Check if an 'action' parameter is present (standard or prefixed)
    // This covers AJAX actions and actions on standard admin pages
    $action_param = frl_prefix('action');
    if (isset($_REQUEST['action']) || isset($_REQUEST[$action_param])) {

        // Check if the current user has administrator-level access
        if (frl_has_access('manage_options')) {
            return $is_action = true;
        }
    }

    return $is_action = false;
}

/**
 * Check if the current request is an AJAX request
 *
 * A simple helper function to detect if we're currently processing an AJAX request
 * using the standard WordPress methods.
 *
 * @return bool True if the current request is an AJAX request
 */
function frl_is_doing_ajax()
{
    // Static cache
    static $is_ajax = null;
    if ($is_ajax !== null) {
        return $is_ajax;
    }

    // Use modern WP function if available, otherwise fallback to constant
    if (function_exists('wp_doing_ajax')) {
        $is_ajax = wp_doing_ajax();
    } else {
        $is_ajax = defined('DOING_AJAX') && DOING_AJAX;
    }

    return $is_ajax;
}

/**
 * @internal Checks if the current request is a CLI request.
 * @return bool True if CLI, false otherwise.
 */
function frl_is_cli_request(): bool
{
    return PHP_SAPI === 'cli';
}

/**
 * @internal Checks if the current request is a REST API request.
 * @return bool True if REST API, false otherwise.
 */
function frl_is_rest_api_request(): bool
{
    $current_url_for_rest_check = $_SERVER['REQUEST_URI'] ?? '';
    if (str_starts_with($current_url_for_rest_check, '/wp-json/')) {
        return true; // REST API request detected by URI
    }
    return defined('REST_REQUEST') && REST_REQUEST;
}

/**
 * @internal Checks if the current request is a CRON job.
 * @return bool True if CRON job, false otherwise.
 */
function frl_is_cron_job_request(): bool
{
    if (defined('DOING_CRON') && DOING_CRON) {
        return true; // WP Cron request
    }
    return function_exists('wp_doing_cron') && wp_doing_cron();
}

/**
 * @internal Checks if the current request is a WordPress Heartbeat AJAX request.
 * @return bool True if Heartbeat AJAX, false otherwise.
 */
function frl_is_heartbeat_ajax_request(): bool
{
    $is_wp_doing_ajax = function_exists('wp_doing_ajax') && wp_doing_ajax();
    $request_action = $_REQUEST['action'] ?? 'NOT_SET';

    if ($is_wp_doing_ajax && $request_action === 'heartbeat') {
        return true;
    }
    return false;
}

/**
 * @internal Checks if the current request's hostname is considered valid and properly mapped in the environment configuration.
 *
 * The function returns true (indicating a valid setup) if:
 * 1. The `$_SERVER['HTTP_HOST']` (current hostname) is set and not empty.
 * 2. The `FRL_ENV_MAP` constant is defined. This constant is expected to hold an
 *    array mapping known hostnames to specific environment configurations for the plugin.
 * 3. The `FRL_ENV_MAP` constant is actually an array.
 * 4. The current hostname (`$_SERVER['HTTP_HOST']`) is found as a key within the `FRL_ENV_MAP` array.
 *
 * @return bool True if the host is valid and mapped, false otherwise.
 */
function frl_is_valid_environment_host(): bool
{
    $current_host = $_SERVER['HTTP_HOST'] ?? null;
    return !empty($current_host) && defined('FRL_ENV_MAP') && is_array(FRL_ENV_MAP) && array_key_exists($current_host, FRL_ENV_MAP);
}

/**
 * @internal Checks if the current request is a HTML document request.
 * @return bool True if document, false otherwise.
 */
function frl_is_html_document_request(): bool
{
    global $wp_query;
    if (isset($wp_query)) {
        if (is_404()) return false;
        if (is_attachment()) return false;
    }
    return true;
}

/**
 * @internal Checks if the current request is a log manager AJAX request.
 * @return bool True if log manager AJAX, false otherwise.
 */
function frl_is_log_manager_request(): bool
{
    if (isset($_REQUEST['action'])) {
        $action = $_REQUEST['action'];
        if (
            $action === 'frl_post_ajax_debug_log_refresh' ||
            $action === 'frl_post_ajax_debug_log_clear' ||
            $action === 'frl_post_ajax_debug_log_download'
        ) {
            return true;
        }
    }
    return false;
}

/**
 * Enqueue scripts and styles
 * @param array $assets The assets to enqueue
 * @param string $assets_key The key of the assets to enqueue
 * @param array $deps The dependencies of the assets
 * @return void
 */
function frl_enqueue_scripts($assets, $assets_key, $deps = [])
{
	// Re-entrancy guard per assets group
	static $enqueued_groups = [];
    if (!frl_is_array_not_empty($assets)) {
        return;
    }
	if (isset($enqueued_groups[$assets_key])) {
		return;
	}
	$enqueued_groups[$assets_key] = true;

    $assets_key .= '_scripts';

    $versions = frl_get_assets_versions($assets, $assets_key);

    if (empty($versions)) {
        return;
    }

    $prefix = FRL_PREFIX . '-';

    foreach ($assets as $handle => $path) {
        if (!isset($versions[$handle]) || empty($path)) {
            continue;
        }

		// Normalize handle to avoid duplicate "-css"/"-js" in final tag id
        $normalized_handle = $handle;
        if (str_ends_with($handle, '-js')) {
            $normalized_handle = substr($handle, 0, -3);
        } elseif (str_ends_with($handle, '-css')) {
            $normalized_handle = substr($handle, 0, -4);
        }
		
		$full_handle = $prefix . $normalized_handle;

        // URL creation for browser loading:
        // - Absolute URL (https://cdn.com/file.js) → used as-is
        // - Relative path (assets/file.js) → prepend FRL_DIR_URL
        // Optimization: Use str_contains for much faster protocol detection than filter_var
        $url = str_contains($path, '://') ? $path : FRL_DIR_URL . $path;

        $version = $versions[$handle];
        $dependencies = $deps[$handle] ?? [];

		$is_js = str_ends_with($path, '.js');
		$is_css = str_ends_with($path, '.css');

		if ($is_js) {
            wp_enqueue_script($full_handle, $url, $dependencies, $version, true);
		} elseif ($is_css) {
            wp_enqueue_style($full_handle, $url, $dependencies, $version);
        }
    }
}

/**
 * Add plugin admin notice
 *
 * Centralized function to add an admin notice using the plugin's transient system.
 *
 * @param string $message The notice message
 * @param string $type The notice type ('success', 'error', 'warning', 'info')
 * @param int $timeout Seconds before the notice expires (default 30)
 */
function frl_add_admin_notice($message, $type = 'info', $timeout = 30)
{
    $notices = frl_get_transient('admin_notices') ?: [];
    $notices[] = [
        'message' => $message,
        'type' => $type
    ];
    frl_set_transient('admin_notices', $notices, $timeout);
}

// =============================================================================
// CORE FUNCTIONS: Nonce Utilities
// =============================================================================

/**
 * Create a prefixed nonce
 * @param string $action Action name (will be prefixed automatically)
 * @return string The nonce value
 */
function frl_create_nonce($action)
{
    return wp_create_nonce(frl_prefix($action));
}

/**
 * Verify a prefixed nonce
 * @param string $nonce The nonce value to verify
 * @param string $action Action name (will be prefixed automatically)
 * @return bool|int False on failure, 1 if generated 0-12 hours ago, 2 if 12-24 hours ago
 */
function frl_verify_nonce($nonce, $action)
{
    return wp_verify_nonce($nonce, frl_prefix($action));
}

/**
 * Create a prefixed nonce field
 * @param string $action Action name (will be prefixed automatically)
 * @param string $name Nonce field name (default: '_wpnonce')
 * @param bool $referer Whether to add referer field (default: true)
 * @param bool $display Whether to echo or return (default: true)
 * @return string The nonce field HTML if $echo is false
 */
function frl_nonce_field($action, $name = '_wpnonce', $referer = true, $display = true)
{
    return wp_nonce_field(frl_prefix($action), $name, $referer, $display);
}

/**
 * Create a prefixed nonce URL
 * @param string $actionurl URL to add nonce to
 * @param string $action Action name (will be prefixed automatically)
 * @param string $name Nonce parameter name (default: '_wpnonce')
 * @return string The URL with nonce added
 */
function frl_nonce_url($actionurl, $action, $name = '_wpnonce')
{
    return wp_nonce_url($actionurl, frl_prefix($action), $name);
}

// =============================================================================
// UTILITY FUNCTIONS: MU Plugins & Redirection
// =============================================================================

/**
 * Safely redirect after an admin action
 *
 * Handles redirection after admin actions in both admin and frontend contexts.
 * - Preserves the current page user was on
 * - Removes action and nonce parameters
 * - Special case handling for admin redirect actions
 *
 * @param string $redirect_url Optional URL to redirect to (if not provided, will determine automatically)
 * @return void
 */
function frl_safe_redirect($redirect_url = '')
{
    // Get the action parameter name
    $action = '';
    $action_param = frl_prefix('action');
    $is_plugin_action = isset($_GET[$action_param]) ? true : false;

    // Get the action if not provided
    if ($is_plugin_action) {
        $action = $_GET[$action_param];
    }

    // Get the referer for potential use as fallback
    $referer = wp_get_referer() ?? $_SERVER['REQUEST_URI'];

    // Only determine default redirect URL if none was provided
    if (empty($redirect_url)) {
        // Default redirect: Current URL or plugin admin for significant admin actions
        $redirect_url = ($is_plugin_action && str_contains($action, 'reset_')) ? FRL_PLUGIN_URL : $referer;
    }

    // Gather all nonce parameters to remove
    $params_to_remove = [$action_param];

    // Find and add any nonce parameters (they follow the pattern: prefix_ACTION_nonce)
    if (!empty($action)) {
        $params_to_remove[] = $action_param . '_nonce';
    } else {
        // If no specific action, scan for any nonce parameters
        foreach ($_GET as $key => $value) {
            if (str_contains($key, '_nonce') && str_starts_with($key, frl_prefix())) {
                $params_to_remove[] = $key;
            }
        }
    }

    // Sanitize the redirect URL
    $redirect_url = wp_validate_redirect($redirect_url, admin_url());

    // Remove all identified parameters
    $redirect_url = remove_query_arg($params_to_remove, $redirect_url);

    // Perform the redirect
    wp_safe_redirect($redirect_url);
    exit;
}

/**
 * Get current user with caching
 * @return WP_User Current user object (ID=0 if not logged in)
 */
function frl_get_current_user()
{
    // Simple static caching
    static $current_user = null;

    // Return cached result if available
    if ($current_user !== null) {
        return $current_user;
    }

    // Skip execution if WordPress is in an early loading stage
    // Allow during plugins_loaded action (doing_action) or after it completed (did_action)
    if (!function_exists('wp_get_current_user') || (!doing_action('plugins_loaded') && !did_action('plugins_loaded'))) {
        return $current_user = new WP_User(0);
    }

    // Use auth cookie for cache key to avoid triggering wp_get_current_user() early
    $auth_cookie = isset($_COOKIE[LOGGED_IN_COOKIE]) ? $_COOKIE[LOGGED_IN_COOKIE] : 'anonymous';
    $cache_key = 'user_' . strtok($auth_cookie, '|');

    // Use remember pattern to cache the user lookup itself
    $current_user = frl_cache_remember('admin', $cache_key, function () {
        $user = wp_get_current_user();

        // Make sure we have a valid WP_User object
        if (!($user instanceof WP_User)) {
            return new WP_User(0);
        }

        return $user;
    });

    return $current_user;
}

/**
 * Get user meta with prefix
 * @param int|WP_User $user_id User ID or user object
 * @param string $key Meta key, if empty, the function will return all user meta
 * @param bool $single Whether to return a single value or an array
 * @return mixed array, string or false
 * An array of values if `$single` is false.
 * The value of meta data field if `$single` is true.
 * False for an invalid `$user_id` (non-numeric, zero, or negative value).
 * An empty string if a valid but non-existing user ID is passed.
 */
function frl_get_user_meta($user_id, $key = '', $single = true)
{
    if (empty($key)) {
        return get_user_meta($user_id, $key, $single);
    }

    return get_user_meta($user_id, frl_prefix($key), $single);
}

/**
 * Update user meta with prefix
 * @param int|WP_User $user_id User ID or user object
 * @param string $key Meta key
 * @param mixed $value Meta value
 * @param mixed $prev_value Optional. Previous value to check against
 * @return bool True on success, false on failure
 */
function frl_update_user_meta($user_id, $key, $value, $prev_value = '')
{
    return update_user_meta($user_id, frl_prefix($key), $value, $prev_value);
}


/**
 *
 * Get the appropriate image size for featured images
 * Centralizes the logic for determining featured image size based on post type
 *
 * @param WP_Post|int $post Post object or post ID
 * @return string Image size name
 */
function frl_get_featured_image_size($post)
{
    if (is_numeric($post)) {
        $post = get_post($post);
    }

    if (!$post instanceof WP_Post) {
        return 'full'; // Fallback
    }

    // Determine base size: 'large' for posts, 'full' for other post types
    $default_size = ($post->post_type === 'post') ? 'large' : 'full';

    // Apply the same filter used in preload function
    return apply_filters('preload_post_thumbnail_image_size', $default_size, $post);
}

/**
 * Returns the current post ID, works inside and outside the loop.
 */
function frl_get_current_post_id(): int
{
    $loop_id = get_the_ID();
    if ($loop_id) {
        return (int) $loop_id;
    }
    global $post;
    return ($post && isset($post->ID)) ? (int) $post->ID : 0;
}

/**
 * Get post ID by slug
 * @param string $slug Slug
 * @return int Post ID. Returns a positive integer for a post ID or 0 if not found.
 */
function frl_get_post_id_by_slug($slug)
{
    $lang = frl_get_language();
    // Use sanitize_key for a readable but safe cache key part
    $cache_key = "postslug2id_" . sanitize_key($slug);

    return frl_cache_remember('permalinks', $cache_key, function () use ($slug, $lang) {
        // First, attempt to get post using 'pagename'. This works for hierarchical slugs
        // and is filtered by Polylang thanks to the 'lang' parameter.
        $posts = get_posts([
            'post_type' => get_post_types(['public' => true]),
            'pagename' => $slug,
            'post_status' => 'publish',
            'numberposts' => 1,
            'lang' => $lang,
        ]);

        if (!empty($posts)) {
            return $posts[0]->ID;
        }

        // Fallback for non-hierarchical post types that might not resolve with pagename
        if (!str_contains($slug, '/')) {
            $posts = get_posts([
                'post_type' => get_post_types(['public' => true, 'hierarchical' => false]),
                'name' => $slug,
                'post_status' => 'publish',
                'numberposts' => 1,
                'lang' => $lang,
            ]);

            if (!empty($posts)) {
                return $posts[0]->ID;
            }
        }

        return 0;
    });
}

/**
 * Get term ID by slug with caching
 * Mirrors frl_get_post_id_by_slug but for taxonomy terms.
 *
 * @param string $slug      Term slug (full path if hierarchical)
 * @param string $taxonomy  Taxonomy slug – default 'category'
 * @return int              Term ID or 0 when not found
 */
function frl_get_term_id_by_slug($slug, $taxonomy = 'category')
{
    if (empty($slug)) {
        return 0;
    }

    $cache_key = 'termslug2id_' . sanitize_key($taxonomy) . '_' . sanitize_key($slug);

    return frl_cache_remember('permalinks', $cache_key, function () use ($slug, $taxonomy) {
        $term = get_term_by('slug', $slug, $taxonomy);
        return ($term && !is_wp_error($term)) ? (int) $term->term_id : 0;
    });
}

/**
 * Get CPT post ID by slug with caching
 * Used by rewriter CPT base-removal feature to avoid repeated DB look-ups.
 *
 * @param string $slug  Post slug or hierarchical path
 * @param string $cpt   Custom post-type slug
 * @return int          Post ID or 0 when not found
 */
function frl_get_cpt_id_by_slug($slug, $cpt)
{
    if (empty($slug) || empty($cpt)) {
        return 0;
    }

    $cache_key = 'cptslug2id_' . sanitize_key($cpt) . '_' . md5($slug);

    return frl_cache_remember('permalinks', $cache_key, function () use ($slug, $cpt) {
        $post = get_page_by_path($slug, OBJECT, $cpt);
        return ($post) ? (int) $post->ID : 0;
    });
}

/**
 * Get page title from URL for admin display
 *
 * Takes a URL and attempts to create a concise, readable label
 * suitable for admin interfaces like widgets. It handles various
 * admin URL structures and simplifies frontend URLs based on their slugs.
 *
 * @param string $url The URL to get title for
 * @return string The generated page title/label
 */
function frl_get_page_title_from_url($url)
{

    if (!is_string($url) || empty($url)) {
        return 'Unknown Page';
    }

    $path = parse_url($url, PHP_URL_PATH);
    $query_str = parse_url($url, PHP_URL_QUERY);
    parse_str($query_str ?? '', $params);

    // Handle Admin URLs
    if (str_contains($url, admin_url())) {
        // Calculate path relative to the admin directory more reliably
        $admin_url_path = trailingslashit(parse_url(admin_url(), PHP_URL_PATH) ?? '/wp-admin/');
        $current_path_relative = ltrim(str_replace($admin_url_path, '', $path ?? ''), '/');

        // -- Specific Check for Post Edit Screen --
        // Check for post.php?action=edit&post=ID first
        if ($current_path_relative === 'post.php' && isset($params['action']) && $params['action'] === 'edit' && isset($params['post'])) {
            $post_id = absint($params['post']);
            if ($post_id > 0) {
                $post_title = get_the_title($post_id);
                if (!empty($post_title)) {
                    // Return immediately if title is found
                    return sprintf('Edit: %s', esc_html($post_title));
                } else {
                    // Fallback if title is empty/not found, but we know it's the edit screen
                    return sprintf('Edit: Post (ID: %d)', $post_id);
                }
            }
            // Fall through if post_id is invalid, might be 'post-new.php' handled below
        }
        // -- End Post Edit Screen Check --

        // Handle other admin pages (including plugin pages)
        $page_param = $params['page'] ?? null;

        if ($page_param) {
            // Format plugin/menu slugs for better readability
            $admin_page_title = ucfirst(str_replace(['-', '_'], ' ', $page_param));
            return sprintf('Admin Page: %s', esc_html($admin_page_title));
        } else {
            // Handle pages identified by filename (e.g., edit.php, users.php, index.php)
            $filename = basename($current_path_relative); // Use relative path here
            // Specifically check for dashboard (index.php or empty path)
            if (empty($filename) || $filename === 'index.php') {
                return 'Admin Dashboard'; // Return dashboard title directly
            }
            // Format other filenames
            $admin_page_title = frl_format_file_name($filename);
            return sprintf('Admin Screen: %s', esc_html($admin_page_title));
        }
    }

    // Handle Frontend URLs

            // Check for language-specific homepage BEFORE calling url_to_postid
    $path = parse_url($url, PHP_URL_PATH);
    $parts = explode('/', trim($path ?? '', '/'));
    $last_part = end($parts);

    if (empty($last_part)) {
        return 'Homepage'; // Default homepage
    }

    // Check if it's a language-specific homepage (e.g., /ru/, /ka/, /en/)
    if (count($parts) === 1 && strlen($last_part) === 2 && ctype_alpha($last_part)) {
        return 'Homepage ' . strtoupper($last_part);
    }

    // Now try WordPress post resolution for other URLs
    $post_id = url_to_postid($url); // Attempt to get Post ID from URL

    if ($post_id > 0) {
        $post_title = get_the_title($post_id);
        if (!empty($post_title)) {
            return esc_html($post_title);
        }
        // Fall through to slug formatting if title is empty for some reason
    }

    // Fallback: Original slug formatting logic if url_to_postid fails or title is empty

    // Format slug-like paths
    $formatted_title = ucfirst(str_replace(['-', '_'], ' ', $last_part));
    return esc_html($formatted_title);
}

/**
 * Format a plugin path to display in a user-friendly way
 * @param string $plugin_path Full plugin path (e.g. 'better-search-replace/better-search-replace.php')
 * @return string Formatted plugin name
 */
function frl_get_plugin_name_from_path($plugin_path)
{
    $name = explode('/', $plugin_path)[0];
    $name = frl_format_file_name($name);

    return $name;
}

/**
 * Synchronize mu-plugins with templates from assets/mu/
 * Always overwrites existing files and cleans up orphaned files
 *
 * @return array|WP_Error Operation results with detailed statistics, or WP_Error on failure
 */
function frl_mu_plugins_sync()
{
    // Define paths
    $mu_plugin_dir = WPMU_PLUGIN_DIR;
    $template_dir = FRL_DIR_PATH . 'assets/mu/';

    // Check if template directory exists
    if (!is_dir($template_dir)) {
        return frl_error('missing_template_dir', 'Template directory not found: ' . $template_dir);
    }

    // Get all PHP files from the template directory
    $template_files = glob($template_dir . '*.php');

    if (empty($template_files)) {
        return frl_error('no_template_files', 'No PHP template files found in: ' . $template_dir);
    }

    // Ensure mu-plugins directory exists
    if (!is_dir($mu_plugin_dir)) {
        if (!wp_mkdir_p($mu_plugin_dir)) {
            return frl_error('create_dir_failed', 'Could not create mu-plugins directory');
        }
    }

    // Check if we have write permissions to the directory
    if (!is_writable($mu_plugin_dir)) {
        return frl_error('write_permission', 'Insufficient permissions to write to mu-plugins directory');
    }

    $copied_files = [];
    $overwritten_files = [];
    $removed_files = [];

    // Get list of expected filenames for cleanup
    $expected_files = [];
    foreach ($template_files as $template_file) {
        $expected_files[] = basename($template_file);
    }

    // Process each template file - always copy/overwrite
    foreach ($template_files as $template_file) {
        $filename = basename($template_file);
        $mu_plugin_file = $mu_plugin_dir . '/' . $filename;

        // Track if file existed before copy
        $file_existed = file_exists($mu_plugin_file);

        // Copy the template file to mu-plugins directory (overwrite if exists)
        if (!copy($template_file, $mu_plugin_file)) {
            return frl_error('copy_failed', 'Failed to copy ' . $filename . ' to mu-plugins directory');
        }

        // Preserve the original modification time
        $template_mtime = filemtime($template_file);
        if ($template_mtime !== false) {
            touch($mu_plugin_file, $template_mtime);
        }

        // Verify file was copied correctly
        if (!file_exists($mu_plugin_file)) {
            return frl_error('verify_failed', 'Failed to verify ' . $filename . ' exists after copy');
        }

        // Track operation type
        if ($file_existed) {
            $overwritten_files[] = $filename;
        } else {
            $copied_files[] = $filename;
        }
    }

    // Cleanup orphaned files
    $existing_mu_files = glob($mu_plugin_dir . '/frl-*.php');

    foreach ($existing_mu_files as $existing_file) {
        $filename = basename($existing_file);

        // If this file is not in our expected list, it's orphaned
        if (!in_array($filename, $expected_files)) {
            if (unlink($existing_file)) {
                $removed_files[] = $filename;
            } else {
                return frl_error('cleanup_failed', 'Failed to remove orphaned file: ' . $filename);
            }
        }
    }

    // Return detailed operation results
    return [
        'success' => true,
        'copied' => $copied_files,
        'overwritten' => $overwritten_files,
        'removed' => $removed_files,
        'total_expected' => count($expected_files),
        'total_processed' => count($copied_files) + count($overwritten_files),
        'message' => sprintf(
            'MU plugins sync completed: %d copied, %d overwritten, %d removed',
            count($copied_files),
            count($overwritten_files),
            count($removed_files)
        )
    ];
}

/**
 * Remove all plugin mu-plugins from the mu-plugins directory
 *
 * @return array|WP_Error Operation results with detailed statistics, or WP_Error on failure
 */
function frl_mu_plugins_delete()
{
    $mu_plugin_dir = WPMU_PLUGIN_DIR;

    // Check if mu-plugins directory exists
    if (!is_dir($mu_plugin_dir)) {
        return [
            'success' => true,
            'removed' => [],
            'total_found' => 0,
            'total_removed' => 0,
            'message' => 'MU plugins directory does not exist - nothing to remove'
        ];
    }

    // Get all plugin mu-plugins (frl-*.php)
    $existing_mu_files = glob($mu_plugin_dir . '/frl-*.php');

    if (empty($existing_mu_files)) {
        return [
            'success' => true,
            'removed' => [],
            'total_found' => 0,
            'total_removed' => 0,
            'message' => 'No MU plugins found - nothing to remove'
        ];
    }

    $removed_files = [];

    foreach ($existing_mu_files as $existing_file) {
        $filename = basename($existing_file);

        if (unlink($existing_file)) {
            $removed_files[] = $filename;
        } else {
            return frl_error('cleanup_failed', 'Failed to remove mu-plugin file: ' . $filename);
        }
    }

    // Return detailed operation results
    return [
        'success' => true,
        'removed' => $removed_files,
        'total_found' => count($existing_mu_files),
        'total_removed' => count($removed_files),
        'message' => sprintf(
            'MU plugins deletion completed: %d files removed',
            count($removed_files)
        )
    ];
}

/**
 * Delete all plugin options from database
 *
 * Used by:
 * - Plugin reset in frl_handle_action_reset_plugin (as step 1)
 * - Plugin uninstall process
 *
 * @return array Results of deletion operations
 */
function frl_delete_plugin()
{
    global $wpdb;
    $prefix = frl_prefix();
    $results = [
        'options_deleted' => 0,
        'options_restored' => 0,
        'options_preserved' => 0,
        'cache_cleared' => [
            'runtime' => 0,
            'object_cache' => 0,
            'transients' => 0
        ]
    ];

    // Defensive flush - ensures clean database state at start
    frl_flush_db();

    // STEP 1: Clear all plugin caches FIRST to ensure fresh state
    frl_cache_clear('all');

    // STEP 2: Delete plugin options from DB
    $query = "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s";
    $args = [$wpdb->esc_like($prefix) . '%'];
    if ($wpdb instanceof wpdb) {
        $results['options_deleted'] = $wpdb->query($wpdb->prepare($query, $args));
    } else {
        frl_log("ERROR - $wpdb is not a valid wpdb object before deleting options during reset.");
    }

    // Flush after significant DB delete.
    frl_flush_db();

    return $results;
}

if (!function_exists('wp_array_recursive_merge')) {
    /**
     * Recursively merge two arrays.
     *
     * An alternative to array_merge_recursive that doesn't convert values
     * with duplicate keys to arrays.
     *
     * @param array $array1 The first array.
     * @param array $array2 The second array.
     * @return array The merged array.
     */
    function wp_array_recursive_merge($array1, $array2)
    {
        $merged = $array1;

        foreach ($array2 as $key => &$value) {
            if (is_array($value) && isset($merged[$key]) && is_array($merged[$key])) {
                $merged[$key] = wp_array_recursive_merge($merged[$key], $value);
            } else {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }
}

/**
 * Optimize URL generation for ACF relationship fields by pre-warming the rewriter cache.
 * Use this function when displaying multiple related posts to prevent repeated URL processing.
 *
 * @param array $posts Array of WP_Post objects from ACF get_field() relationships
 * @return array The same posts array (for chaining)
 */
function frl_optimize_acf_urls(array $posts): array
{
	if (empty($posts) || !frl_rewriter_is_loaded()) {
		return $posts;
	}

	$rewriter = Frl_Rewriter::init();
	$rewriter->warm_cache_for_posts($posts);

	return $posts;
}
