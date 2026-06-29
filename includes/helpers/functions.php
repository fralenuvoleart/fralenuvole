<?php

/**
 * Fralenuvole Core Functions
 *
 * This file contains the plugin bootstrap logic and a collection of core helper functions
 * used across the plugin for context detection, user access control, and general utilities.
 *
 * @package Fralenuvole
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/*
 * BOOTSTRAP: Load Helper Files
 */
require_once FRL_DIR_PATH . 'includes/helpers/utilities.php';
require_once FRL_DIR_PATH . 'includes/helpers/functions-error-log.php';require_once FRL_DIR_PATH . 'includes/helpers/functions-access-control.php';
require_once FRL_DIR_PATH . 'includes/helpers/functions-options.php';
require_once FRL_DIR_PATH . 'includes/helpers/functions-class-helpers.php';
require_once FRL_DIR_PATH . 'includes/helpers/functions-translator-helpers.php';
require_once FRL_DIR_PATH . 'includes/helpers/functions-modules.php';
require_once FRL_DIR_PATH . 'includes/helpers/functions-schema.php';

/*
 * Nginx-COMPATIBLE HELPERS
 *
 * wp_get_referer() returns false when no Referer header is present or
 * when wp_validate_redirect() fails — common on Nginx/PHP-FPM hosts
 * like Kinsta where the reverse proxy may strip or alter HTTP_REFERER.
 * The elvis operator (?:) correctly handles both false and null returns.
 *
 * Nginx also sets $_SERVER['HTTPS'] to '' (empty string), not 'on',
 * unlike Apache. frl_is_https() handles both plus port-based detection.
 */

/**
 * Returns the HTTP referer with a safe fallback to REQUEST_URI.
 *
 * @return string The referer URL, or the current request URI as fallback.
 */
function frl_wp_get_referer(): string
{
    $referer = wp_get_referer();
    return $referer ?: ($_SERVER['REQUEST_URI'] ?? '');
}

/**
 * Detects HTTPS consistently across Apache and Nginx reverse proxies.
 *
 * @return bool True if the current request is over HTTPS.
 */
function frl_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    if (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
        return true;
    }
    if (function_exists('is_ssl') && is_ssl()) {
        return true;
    }
    return false;
}

/**
 * Returns a string prefixed with the plugin's defined prefix.
 *
 * @param string $name The name to prefix.
 * @return string The prefixed string.
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
 * Returns the formatted plugin name, optionally with a prefix.
 *
 * @param string $name_prefix Optional prefix to prepend to the plugin name.
 * @return string The formatted plugin name.
 */
function frl_name($name_prefix = '')
{
    $base_name = ucfirst(FRL_NAME);
    // Add space only if prefix is provided
    if (!empty($name_prefix)) {
        return $name_prefix . ' ' . $base_name;
    }
    // Return base name
    return $base_name;
}

/**
 * Retrieves the current user object with static and persistent caching.
 *
 * Handles early WordPress loading stages by returning a dummy user if
 * wp_get_current_user is not yet available.
 *
 * @return WP_User The current user object (ID=0 if not logged in).
 */
function frl_get_current_user()
{
    // Static cache
    static $current_user = null;

    // Return cached user
    if ($current_user !== null) {
        return $current_user;
    }

    // Return dummy user if WP is loading early
    if (!function_exists('wp_get_current_user') || (!doing_action('plugins_loaded') && !did_action('plugins_loaded'))) {
        return $current_user = new WP_User(0);
    }

    // Use auth cookie for cache key.
    // Include a short hash of the full cookie value (token portion) to bind the
    // cache entry to the specific session, preventing cross-session cache hits.
    // Otherwise, stale WP_User objects from the persistent 'admin' cache group
    // (1-hour TTL) could be served to a different user's session.
    $auth_cookie = isset($_COOKIE[LOGGED_IN_COOKIE]) ? $_COOKIE[LOGGED_IN_COOKIE] : 'anonymous';
    $cookie_username = strtok($auth_cookie, '|');
    $cookie_token   = substr(md5($auth_cookie), 0, 8);
    $cache_key      = 'user_' . $cookie_username . '_' . $cookie_token;

    // Cache user lookup
    $current_user = frl_cache_remember('admin', $cache_key, function () {
        $user = wp_get_current_user();

        // Ensure valid WP_User object
        if (!($user instanceof WP_User)) {
            return new WP_User(0);
        }

        return $user;
    });

    // 2. Ensure the cached value is actually a WP_User object
    if (!($current_user instanceof WP_User)) {
        $current_user = new WP_User(0);
    }

    // 3. Cross-session safety: verify the cached user's login matches the
    //    current request's cookie username. If the persistent cache returned a
    //    stale WP_User for a different user, invalidate and re-fetch.
    if ($current_user->ID > 0 && $current_user->user_login !== $cookie_username) {
        frl_cache_delete('admin', $cache_key);
        $current_user = new WP_User(0);
    }
    
    return $current_user;
}

/**
 * Retrieves user metadata using a prefixed key.
 *
 * @param int|WP_User $user_id The user ID or user object.
 * @param string      $key     The meta key. If empty, all user meta is returned.
 * @param bool        $single  Whether to return a single value or an array. Defaults to true.
 * @return mixed The meta value(s), or false on failure.
 */
function frl_get_user_meta($user_id, $key = '', $single = true)
{
    if (empty($key)) {
        return get_user_meta($user_id, $key, $single);
    }

    return get_user_meta($user_id, frl_prefix($key), $single);
}

/**
 * Updates user metadata using a prefixed key.
 *
 * @param int|WP_User $user_id   The user ID or user object.
 * @param string      $key       The meta key.
 * @param mixed       $value     The value to set.
 * @param mixed       $prev_value Optional. Previous value to check against.
 * @return bool True on success, false on failure.
 */
function frl_update_user_meta($user_id, $key, $value, $prev_value = '')
{
    return update_user_meta($user_id, frl_prefix($key), $value, $prev_value);
}

/**
 * Enqueues a group of scripts and styles with versioning and dependency management.
 *
 * @param array  $assets    Associative array of assets where key is handle and value is path.
 * @param string $assets_key Unique key for the asset group, used for caching and versioning.
 * @param array  $deps      Associative array of dependencies for each asset handle.
 * @return void
 */
function frl_enqueue_scripts($assets, $assets_key, $deps = [])
{
	// Prevent duplicate enqueuing per group
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

		// Normalize handle to avoid duplicate extensions in ID
        $normalized_handle = $handle;
        if (str_ends_with($handle, '-js')) {
            $normalized_handle = substr($handle, 0, -3);
        } elseif (str_ends_with($handle, '-css')) {
            $normalized_handle = substr($handle, 0, -4);
        }
		
		$full_handle = $prefix . $normalized_handle;

        // Use absolute URL or prepend FRL_DIR_URL
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
 * Determines the appropriate image size for featured images based on post type.
 *
 * @param WP_Post|int $post The post object or post ID.
 * @return string The image size name (e.g., 'large', 'full').
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
 * Build a cache key from prefix + segments joined by '_'. Empty segments are ignored.
 *
 * @param string $prefix      Key prefix (without trailing '_').
 * @param string ...$segments Segments to join.
 * @return string Cache key.
 */
/**
 * Returns the post cache version for shortcode invalidation.
 *
 * Cached statically per-request. Defaults to 1 for posts without a version.
 *
 * @since 5.8.3
 *
 * @param int $post_id Post ID.
 * @return int Cache version.
 */
function frl_get_post_cache_version(int $post_id): int
{
    static $versions = [];
    if (!isset($versions[$post_id])) {
        $versions[$post_id] = (int) get_post_meta($post_id, '_frl_post_version', true) ?: 1;
    }
    return $versions[$post_id];
}

/**
 * Generates a standardized cache key by joining a prefix with non-empty segments.
 *
 * @param string $prefix   Key prefix.
 * @param string ...$segments Additional segments to append.
 * @return string Formatted cache key.
 */
function frl_generate_cache_key(string $prefix, string ...$segments): string
{
    $filtered = array_filter($segments, fn(string $s) => $s !== '');
    return $prefix . ($filtered ? '_' . implode('_', $filtered) : '');
}

/**
 * Returns the current post ID, working both inside and outside the loop.
 *
 * @return int The current post ID, or 0 if not found.
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
 * Retrieves a post ID based on its slug, with caching.
 *
 * @param string $slug The post slug or hierarchical path.
 * @return int The post ID, or 0 if not found.
 */
function frl_get_post_id_by_slug($slug)
{
    $lang = frl_get_language();
    // Safe cache key
    $cache_key = "postslug2id_" . sanitize_key($slug);

    return frl_cache_remember('permalinks', $cache_key, function () use ($slug, $lang) {
        // Try 'pagename' for hierarchical slugs
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

        // Fallback for non-hierarchical post types
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
 * Retrieves a term ID based on its slug, with caching.
 *
 * @param string $slug     The term slug.
 * @param string $taxonomy The taxonomy slug. Defaults to 'category'.
 * @return int The term ID, or 0 if not found.
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
 * Retrieves a Custom Post Type (CPT) post ID based on its slug, with caching.
 *
 * @param string $slug The post slug or hierarchical path.
 * @param string $cpt  The custom post-type slug.
 * @return int The post ID, or 0 if not found.
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
 * Generates a concise, readable page title from a URL for admin display.
 *
 * Handles admin URL structures (including post edit screens and plugin pages)
 * and simplifies frontend URLs based on their slugs or WordPress post titles.
 *
 * @param string $url The URL to process.
 * @return string The generated page title or label.
 */
function frl_get_page_title_from_url($url)
{

    if (!is_string($url) || empty($url)) {
        return 'Unknown Page';
    }

    $path = parse_url($url, PHP_URL_PATH);
    $query_str = parse_url($url, PHP_URL_QUERY);
    parse_str($query_str ?? '', $params);

    // Admin URLs
    if (str_contains($url, admin_url())) {
        // Path relative to admin directory
        $admin_url_path = trailingslashit(parse_url(admin_url(), PHP_URL_PATH) ?? '/wp-admin/');
        $current_path_relative = ltrim(str_replace($admin_url_path, '', $path ?? ''), '/');

        // Post Edit Screen check
        if ($current_path_relative === 'post.php' && isset($params['action']) && $params['action'] === 'edit' && isset($params['post'])) {
            $post_id = (int) $params['post'];
            if ($post_id > 0) {
                $post_title = get_the_title($post_id);
                if (!empty($post_title)) {
                    // Return immediately if title is found
                    return sprintf('Edit: %s', esc_html($post_title));
                } else {
                    // Fallback for empty title
                    return sprintf('Edit: Post (ID: %d)', $post_id);
                }
            }
            // Fall through if post_id is invalid, might be 'post-new.php' handled below
        }

        // Other admin pages
        $page_param = $params['page'] ?? null;

        if ($page_param) {
            // Format slugs for readability
            $admin_page_title = ucfirst(str_replace(['-', '_'], ' ', $page_param));
            return sprintf('Admin Page: %s', esc_html($admin_page_title));
        } else {
            // Filename-based pages
            $filename = basename($current_path_relative); // Use relative path here
            // Dashboard check
            if (empty($filename) || $filename === 'index.php') {
                return 'Admin Dashboard'; // Return dashboard title directly
            }
            // Format other filenames
            $admin_page_title = frl_format_file_name($filename);
            return sprintf('Admin Screen: %s', esc_html($admin_page_title));
        }
    }

    // Frontend URLs

            // Check language-specific homepage
    $path = parse_url($url, PHP_URL_PATH);
    $parts = explode('/', trim($path ?? '', '/'));
    $last_part = end($parts);

    if (empty($last_part)) {
        return 'Homepage'; // Default homepage
    }

    // Language-specific homepage check
    if (count($parts) === 1 && strlen($last_part) === 2 && ctype_alpha($last_part)) {
        return 'Homepage ' . strtoupper($last_part);
    }

    // WordPress post resolution
    $post_id = url_to_postid($url); // Attempt to get Post ID from URL

    if ($post_id > 0) {
        $post_title = get_the_title($post_id);
        if (!empty($post_title)) {
            return esc_html($post_title);
        }
        // Fall through to slug formatting if title is empty for some reason
    }

    // Fallback slug formatting

    // Format slug paths
    $formatted_title = ucfirst(str_replace(['-', '_'], ' ', $last_part));
    return esc_html($formatted_title);
}

/**
 * Formats a plugin path into a user-friendly display name.
 *
 * @param string $plugin_path The full plugin path (e.g., 'plugin-folder/plugin-file.php').
 * @return string The formatted plugin name.
 */
function frl_get_plugin_name_from_path($plugin_path)
{
    $name = explode('/', $plugin_path)[0];
    $name = frl_format_file_name($name);

    return $name;
}

/**
 * Synchronizes MU plugins with templates from the assets/mu/ directory.
 *
 * Overwrites existing MU plugins with templates and removes any orphaned
 * plugin files that are no longer present in the template directory.
 *
 * @return array|WP_Error Operation results with statistics, or WP_Error on failure.
 */
function frl_mu_plugins_sync()
{
    // Paths
    $mu_plugin_dir = WPMU_PLUGIN_DIR;
    $template_dir = FRL_DIR_PATH . 'assets/mu/';

    // Template directory check
    if (!is_dir($template_dir)) {
        return frl_error('missing_template_dir', 'Template directory not found: ' . $template_dir);
    }

    // Get template PHP files
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

    // Write permissions check
    if (!is_writable($mu_plugin_dir)) {
        return frl_error('write_permission', 'Insufficient permissions to write to mu-plugins directory');
    }

    $copied_files = [];
    $overwritten_files = [];
    $removed_files = [];

    // Expected filenames for cleanup
    $expected_files = [];
    foreach ($template_files as $template_file) {
        $expected_files[] = basename($template_file);
    }

    // Copy/overwrite template files
    foreach ($template_files as $template_file) {
        $filename = basename($template_file);
        $mu_plugin_file = $mu_plugin_dir . '/' . $filename;

        // Track existence
        $file_existed = file_exists($mu_plugin_file);

        // Copy template to mu-plugins
        if (!copy($template_file, $mu_plugin_file)) {
            return frl_error('copy_failed', 'Failed to copy ' . $filename . ' to mu-plugins directory');
        }

        // Preserve modification time
        $template_mtime = filemtime($template_file);
        if ($template_mtime !== false) {
            touch($mu_plugin_file, $template_mtime);
        }

        // Verify copy
        if (!file_exists($mu_plugin_file)) {
            return frl_error('verify_failed', 'Failed to verify ' . $filename . ' exists after copy');
        }

        // Track operation
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

        // Remove orphaned files
        if (!in_array($filename, $expected_files)) {
            if (unlink($existing_file)) {
                $removed_files[] = $filename;
            } else {
                return frl_error('cleanup_failed', 'Failed to remove orphaned file: ' . $filename);
            }
        }
    }

    // Return results
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
 * Deletes all plugin-specific MU plugins from the mu-plugins directory.
 *
 * @return array|WP_Error Operation results with statistics, or WP_Error on failure.
 */
function frl_mu_plugins_delete()
{
    $mu_plugin_dir = WPMU_PLUGIN_DIR;

    // Directory check
    if (!is_dir($mu_plugin_dir)) {
        return [
            'success' => true,
            'removed' => [],
            'total_found' => 0,
            'total_removed' => 0,
            'message' => 'MU plugins directory does not exist - nothing to remove'
        ];
    }

    // Get plugin MU plugins
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

    // Return results
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
 * Deletes all plugin options from the database.
 *
 * Used during plugin reset or uninstall processes to ensure a clean state.
 *
 * @return array Results of the deletion operations.
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

    // Initial DB flush
    frl_flush_db();

    // Clear all caches
    frl_cache_clear('all');

    // Delete options from DB
    $query = "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s";
    $args = [$wpdb->esc_like($prefix) . '%'];
    if ($wpdb instanceof wpdb) {
        $results['options_deleted'] = $wpdb->query($wpdb->prepare($query, $args));
    } else {
        frl_log("ERROR - $wpdb is not a valid wpdb object before deleting options during reset.");
    }

    // Final DB flush
    frl_flush_db();

    return $results;
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
 * Adds a plugin admin notice using the transient system.
 *
 * @param string $message The notice message.
 * @param string $type    The notice type ('success', 'error', 'warning', 'info'). Defaults to 'info'.
 * @param int    $timeout Seconds before the notice expires. Defaults to 30.
 * @return void
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

/**
 * Safely redirects the user after an admin action.
 *
 * Handles redirection in both admin and frontend contexts, preserving the referer
 * while removing action and nonce parameters from the URL.
 *
 * @param string $redirect_url Optional URL to redirect to. If empty, the referer or plugin URL is used.
 * @return void
 */
function frl_safe_redirect($redirect_url = '')
{
    // Action parameter name
    $action = '';
    $action_param = frl_prefix('action');
    $is_plugin_action = isset($_GET[$action_param]) ? true : false;

    // Get action if present
    if ($is_plugin_action) {
        $action = $_GET[$action_param];
    }

    // Referer as fallback — frl_wp_get_referer() handles Nginx false return
    $referer = frl_wp_get_referer();

    // Determine default redirect URL
    if (empty($redirect_url)) {
        // Redirect to plugin admin for resets, otherwise referer
        $redirect_url = ($is_plugin_action && str_contains($action, 'reset_')) ? FRL_PLUGIN_ADMIN_URL : $referer;
    }

    // Parameters to remove
    $params_to_remove = [$action_param];

    // Add nonce parameters
    if (!empty($action)) {
        $params_to_remove[] = $action_param . '_nonce';
    } else {
        // Scan for any prefixed nonce parameters
        foreach ($_GET as $key => $value) {
            if (str_contains($key, '_nonce') && str_starts_with($key, frl_prefix())) {
                $params_to_remove[] = $key;
            }
        }
    }

    // Sanitize URL
    $redirect_url = wp_validate_redirect($redirect_url, admin_url());

    // Remove parameters
    $redirect_url = remove_query_arg($params_to_remove, $redirect_url);

    // Redirect
    wp_safe_redirect($redirect_url);
    exit;
}

/**
 * Creates a nonce with the plugin's prefix.
 *
 * @param string $action The action name.
 * @return string The generated nonce.
 */
function frl_create_nonce($action)
{
    return wp_create_nonce(frl_prefix($action));
}

/**
 * Generates a prefixed nonce field.
 *
 * @param string $action  The action name.
 * @param string $name    The nonce field name. Defaults to '_wpnonce'.
 * @param bool   $referer Whether to add a referer field. Defaults to true.
 * @param bool   $display Whether to echo the field or return it. Defaults to true.
 * @return string The nonce field HTML if $display is false.
 */
function frl_nonce_field($action, $name = '_wpnonce', $referer = true, $display = true)
{
    return wp_nonce_field(frl_prefix($action), $name, $referer, $display);
}

/**
 * Retrieves terms for any post type and taxonomy, with language filtering and caching.
 *
 * Filters terms by the current language when a translation plugin is active.
 * The 'id' field is automatically mapped to 'term_id' for WP_Term compatibility.
 * All results (including empty/false) are normalized to an array for safe caching.
 *
 * @param int|WP_Post $post     Post ID or object. Defaults to global $post.
 * @param string      $taxonomy Taxonomy slug. Defaults to 'category'.
 * @param string      $field    Specific field to return. Accepts 'all' (WP_Term[]),
 *                              'term_id', 'name', 'slug', 'id' (mapped to 'term_id'),
 *                              or any WP_Term property.
 * @return WP_Term[]|string[]|int[]|false Array of WP_Term objects (field='all'),
 *                                       array of field values, or false if no valid
 *                                       post ID or taxonomy was provided.
 */
function frl_get_post_terms( $post = 0, $taxonomy = 'category', $field = 'all' ) {
    $post_id = ( $post instanceof WP_Post ) ? $post->ID : (int) $post;
    if ( ! $post_id ) {
        $post_id = get_the_ID();
    }
    if ( ! $post_id || empty( $taxonomy ) ) {
        return false;
    }

    // Include language in cache key for multilingual compatibility
    $lang      = frl_get_language();
    $cache_key = 'cf_terms_' . $post_id . '_' . sanitize_key( $taxonomy ) . '_' . $lang;

    return frl_cache_remember( 'permalinks', $cache_key, function () use ( $post_id, $taxonomy, $field, $lang ) {
        $terms = get_the_terms( $post_id, $taxonomy );

        // Normalize false and WP_Error to empty array so the result is
        // safely cacheable across requests. get_the_terms() returns false
        // when no terms exist, but caching 'false' via transient fallback
        // is unreliable (get_transient returns false for both "key missing"
        // and "stored value is false"). WP_Error should not be cached
        // because it may represent a transient database issue.
        if ( ! $terms || is_wp_error( $terms ) ) {
            return [];
        }

        // Filter by current language when a translation plugin is active
        if ( frl_translator_is_enabled() && function_exists( 'pll_get_term_language' ) ) {
            $filtered = [];
            foreach ( $terms as $term ) {
                $term_lang_obj = pll_get_term_language( $term->term_id );
                $term_lang     = ( $term_lang_obj instanceof PLL_Language ) ? $term_lang_obj->slug : '';
                if ( $term_lang === $lang || '' === $term_lang ) {
                    $filtered[] = $term;
                }
            }
            $terms = ! empty( $filtered ) ? $filtered : [];
        }

        if ( empty( $terms ) ) {
            return [];
        }

        if ( 'all' === $field ) {
            return $terms;
        }

        // Map 'id' to 'term_id' for WP_Term compatibility
        $pluck_field = ( 'id' === $field ) ? 'term_id' : $field;

        return wp_list_pluck( $terms, $pluck_field );
    } );
}

/**
 * Get post meta with a single entry point for custom field retrieval.
 *
 * Centralized accessor so the retrieval method can be changed in one place
 * (e.g., switching between ACPT, ACF, or raw post meta).
 *
 * @param int    $post_id Post ID.
 * @param string $key     Meta key.
 * @param bool   $single  Whether to return a single value.
 * @return mixed Meta value.
 */
function frl_get_post_meta(int $post_id, string $key, bool $single = true)
{
    // Primary: raw post meta (ACPT or standard WP)
    $value = get_post_meta($post_id, $key, $single);
    if ($value !== null && $value !== '' && $value !== false) {
        return $value;
    }
    // Fallback: ACF (handles serialization, repeaters, etc.)
    if (function_exists('get_field')) {
        $acf_value = get_field($key, $post_id, $single);
        if ($acf_value !== null && $acf_value !== false) {
            return $acf_value;
        }
    }
    return $value;
}

/**
 * Retrieves a repeater field, normalizing ACPT columnar and SCF/ACF row-indexed formats.
 *
 * Without $index and $subfield, returns the entire repeater as a row-indexed array
 * [{subfield: value}, ...]. With both specified, drills down to the scalar subfield value.
 *
 * @since 5.9.0
 *
 * @param int         $post_id  Post ID.
 * @param string      $field    Repeater meta key.
 * @param int|null    $index    Optional row index (0-based). Requires $subfield.
 * @param string|null $subfield Optional subfield key. Requires $index.
 * @return mixed Row-indexed array, scalar string, or null if not found.
 */
function frl_get_repeater_field(int $post_id, string $field, ?int $index = null, ?string $subfield = null)
{
    $raw = get_post_meta($post_id, $field, true);

    // ACF/SCF: get_post_meta returns row count (int), get_field returns the array
    if (!is_array($raw) && function_exists('get_field')) {
        $raw = get_field($field, $post_id, false);
    }

    if (!is_array($raw) || empty($raw)) {
        return null;
    }

    // Detect ACPT columnar format: first column's first row has 'original_name' envelope key.
    // ACPT: {subfield_name: [{original_name, type, value}, ...]}
    // SCF:  already [{subfield: val}, ...] — passthrough.
    $first_col = reset($raw);
    $first_cell = is_array($first_col) ? reset($first_col) : null;
    if (is_array($first_cell) && array_key_exists('original_name', $first_cell)) {
        $max = 0;
        foreach ($raw as $col) {
            if (is_array($col)) {
                $max = max($max, count($col));
            }
        }
        $rows = [];
        for ($i = 0; $i < $max; $i++) {
            $row = [];
            foreach ($raw as $sub_name => $col) {
                if (isset($col[$i]['value'])) {
                    $val = $col[$i]['value'];
                    $row[$sub_name] = is_scalar($val) ? (string) $val : $val;
                }
            }
            $rows[] = $row;
        }
        $raw = $rows;
    }

    // Drill down to subfield if requested
    if ($index !== null && $subfield !== null) {
        if (!isset($raw[$index][$subfield])) {
            return null;
        }
        $val = $raw[$index][$subfield];
        return is_scalar($val) ? (string) $val : $val;
    }

    return $raw;
}
