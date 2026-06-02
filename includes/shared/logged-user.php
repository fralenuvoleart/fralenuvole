<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Load post handlers for admin actions (only needed for logged users)
if (frl_is_administrator_action()) {
    require_once FRL_DIR_PATH . 'includes/helpers/functions-action-handlers.php';

    // Register the hook only when the function is available
    add_action('init', 'frl_process_plugin_actions', 10, 0);
}

add_action('wp_loaded',     'frl_load_logged_user_scripts',    10,   0);
add_action('admin_bar_menu', 'frl_admin_bar_custom_menu',      9999, 0);
add_action('admin_notices', 'frl_display_all_admin_notices',   10,   0);
add_action('wp_footer',     'frl_trace_logged_user_visits',    99,   0);
add_action('admin_footer',  'frl_trace_logged_user_visits',    99,   0);

/**
 * Enqueue scripts and styles for logged-in users.
 *
 * @return void
 */
function frl_load_logged_user_scripts()
{
    $assets = [
        'logged-user-css' => 'assets/css/shared-logged-user.css',
    ];

    if (frl_get_option('admin_theme_custom')) {
        $assets['admin-theme-css'] = 'assets/css/admin-theme.css';
    }

    frl_enqueue_scripts($assets, 'logged_user');
}


/**
 * Add custom menus and handle node removals in the WordPress admin bar.
 *
 * @return void
 */
function frl_admin_bar_custom_menu()
{
    if (!frl_get_option('custom_ab_menu') || !frl_has_access('install_plugins') ) {
        return;
    }

    $user_id = frl_get_current_user()->ID;
    $lang = frl_get_language();

    $cache_key = "{$lang}_adminbar_uid{$user_id}";

    // Cache menu configuration to avoid repeated processing
    $menu_data = frl_cache_remember('admin', $cache_key, function () {
        $data = [];

        $data = frl_admin_bar_add_menu_secondary($data);
        $data = frl_admin_bar_add_menu_primary($data);

        $data = frl_admin_bar_remove_menu($data);

        return $data;
    });

    global $wp_admin_bar;

    // Add Custom Post Type (CPT) links under "New Content"
    $cpt_list = FRL_AB_CPT_LIST;
    $cpt_group_id = 'cpt-menu-group';
    $wp_admin_bar->add_group([
        'id' => $cpt_group_id,
        'parent' => 'new-content',
    ]);

    foreach ($cpt_list as $key => $value) {
        if (frl_has_access($value['access'])) {
            $wp_admin_bar->add_node([
                'id' => 'cpt-menu-' . $key,
                'title' => $value['title'],
                'parent' => $cpt_group_id,
                'href' => $value['href'],
            ]);
        }
    }

    // Apply custom menu items from options
    $custom_menu = frl_get_option('custom_ab_menu');
    if ($custom_menu !== '' && $custom_menu !== false && $custom_menu !== null) {
        if (!empty($menu_data['menu_secondary'])) {
            foreach ($menu_data['menu_secondary'] as $item) {
                $wp_admin_bar->add_menu($item);
            }
        }

        if (!empty($menu_data['menu_primary'])) {
            foreach ($menu_data['menu_primary'] as $item) {
                $wp_admin_bar->add_menu($item);
            }
        }
    }

    // Remove specified admin bar nodes
    if (frl_get_option('ab_remove_links')) {

        if (!empty($menu_data['my_account_title'])) {
            $my_account = $wp_admin_bar->get_node('my-account');
            if (isset($my_account->title)) {
                $newtitle = str_replace('Howdy,', '', $my_account->title);
                $wp_admin_bar->add_node([
                    'id' => 'my-account',
                    'title' => $newtitle,
                ]);
            }
        }

        if (!empty($menu_data['ab_remove_links_handles'])) {
            foreach ($menu_data['ab_remove_links_handles'] as $handle) {
                if (is_string($handle)) {
                    $wp_admin_bar->remove_node($handle);
                }
            }
        }

        if (!frl_has_access() && !empty($menu_data['ab_remove_links_handles_user'])) {
            foreach ($menu_data['ab_remove_links_handles_user'] as $handle) {
                if (is_string($handle)) {
                    $wp_admin_bar->remove_node($handle);
                }
            }
        }
    }
}

/**
 * Prepare primary admin bar menu items.
 *
 * @param array $data Data array to populate with menu items.
 * @return array Updated data array containing primary menu configuration.
 */
function frl_admin_bar_add_menu_primary($data)
{
    // Check from transient log entries to determine if we should highlight the menu
    $log_count = frl_get_debug_log_count();
    $has_logs = $log_count > 0;

    // Parent menu - add alert class if there are logs
    $parent_id = FRL_PREFIX . '-menu-primary';
    $parent_class = $has_logs ? FRL_PREFIX . '-top-toolbar ' . FRL_PREFIX . '-has-logs' : FRL_PREFIX . '-top-toolbar';
    $alt_title = frl_name();

    $data['menu_primary']['parent'] = [
        'id'     => $parent_id,
        'title'  => '<span class="' . FRL_PREFIX . '-plugin-logo">' . frl_name('Plugin') . '</span>',
        'href'   => FRL_PLUGIN_ADMIN_URL,
        'meta'   => [
            'class' => $parent_class,
            'title' => $alt_title
        ],
    ];

    global $wp;
    $current_url = trailingslashit(frl_is_admin() ? home_url() : home_url($wp->request));
    
    // PageSpeed link
    $data['menu_primary']['pagespeed'] = [
        'id' => FRL_PREFIX . '-menu-child-pagespeed',
        'title' => __('PageSpeed'),
        'href' => 'https://pagespeed.web.dev/report?url=' . $current_url,
        'parent' => $parent_id,
        'meta' => ['target' => '_blank'],
    ];

    // Rich snippets link
    $data['menu_primary']['schema'] = [
        'id' => FRL_PREFIX . '-menu-child-schema',
        'title' => __('Schema Validator'),
        'href' => 'https://validator.schema.org/?hl=en-US#url=' . $current_url,
        'parent' => $parent_id,
        'meta' => ['target' => '_blank'],
    ];

    // only for plugin admin
    if (frl_has_access()) {
        // options.php link
        $data['menu_primary']['options_php'] = [
            'id' => FRL_PREFIX . '-menu-child-options-php',
            'title' => __('WordPress Options'),
            'href' => admin_url('options.php'),
            'parent' => $parent_id,
        ];
        
        // Clear Website Transients link
        $data['menu_primary']['clear_website_transients'] = [
            'id' => FRL_PREFIX . '-menu-child-clear-website-transients',
            'title' => __('Clear Website Transients'),
            'href' => add_query_arg(FRL_PREFIX . '_action', 'clear_website_transients'),
            'parent' => $parent_id,
        ];

        // Debug log entry count
        $data['menu_primary']['debug_log'] = [
            'id' => FRL_PREFIX . '-menu-child-debug-log',
            'href' => FRL_PLUGIN_ADMIN_URL . '#tabs-developer',
            'parent' => $parent_id,
            'meta' => ['class' => FRL_PREFIX . '-ab-debug-log']
        ];

        if ($log_count > 0) {
            $data['menu_primary']['debug_log']['title'] = '<span class="debug-log-text">' . __('Debug Log', FRL_PREFIX) . '</span><span class="' . FRL_PREFIX . '-count-bubble">' . number_format_i18n($log_count) . '</span>';
        } else {
            // Still add the menu item but without count
            $data['menu_primary']['debug_log']['title'] = __('Debug Log', FRL_PREFIX);
        }
    }
    
    // Separator
    $data['menu_primary']['separator'] = [
        'id' => FRL_PREFIX . '-primary-separator',
        'parent' => $parent_id,
        'title' => '',
        'meta' => ['class' => FRL_PREFIX . '-ab-separator']
    ];

    // Cache management links
    $cache_links = [
        'clear_scripts_tags' => array(
            'title' => __('Clear CSS/JS Caches', FRL_PREFIX),
            'alt' => 'Clear Critical CSS and all Scripts Tags Caches',
            'caps' => 'manage_options'
        ),
        'clear_shortcodes' => array(
            'title' => __('Clear Shortcodes Caches', FRL_PREFIX),
            'alt' => 'Clear all Shortcodes Caches',
            'caps' => 'manage_options'
        ),
        'clear_cache_light' => array(
            'title' => __('Clear Caches (Light)', FRL_PREFIX),
            'alt' => 'Clear all plugin caches except Heavy Groups',
            'caps' => 'manage_options'
        ),
        'clear_cache_all' => array(
            'title' => __('Clear Caches (All)', FRL_PREFIX),
            'alt' => 'Clear all plugin caches including: ' . implode(', ', array_map('ucfirst', FRL_CACHE_HEAVY_GROUPS)),
            'caps' => ''
        ),
    ];

    foreach ($cache_links as $action => $args) {
        if (!frl_has_access($args['caps'])) {
            continue;
        }
        $title = $args['title'];
        $alt = $args['alt'];

        $data['menu_primary']['cache_' . $action] = [
            'id' => FRL_PREFIX . '-menu-child-' . $action,
            'title' => $title,
            'href' => add_query_arg(FRL_PREFIX . '_action', $action),
            'parent' => $parent_id,
            'meta' => ['class' => FRL_PREFIX . '-ab-' . $action, 'title' => $alt]
        ];
    }

    return $data;
}

/**
 * Prepare secondary admin bar menu items.
 *
 * @param array $data Data array to populate with menu items.
 * @return array Updated data array containing secondary menu configuration.
 */
function frl_admin_bar_add_menu_secondary($data)
{
    static $links_custom = null;

    $secondary_id = FRL_PREFIX . '-menu-secondary';
    $data['menu_secondary']['parent'] = [
        'id'   => $secondary_id,
        'title' => '',
        'meta' => [
            'class'  => $secondary_id,
            'title'  => __('Website Links', FRL_PREFIX),
            'target' => '_blank',
        ],
    ];

    // Custom submenu links
    if ($links_custom === null) {
        $links_custom = frl_textlist_to_array(frl_get_option('custom_ab_links'));
    }

    if (frl_is_array_not_empty($links_custom)) {
        foreach ($links_custom as $key => $link) {
            if (frl_is_array_not_empty($link) && count($link) >= 2) {
                $data['menu_secondary']['custom_submenu_' . $key] = [
                    'id'     => FRL_PREFIX . '-menu-child-custom-' . $key,
                    'title'  => $link[0],
                    'href'   => $link[1],
                    'parent' => $secondary_id,
                    'meta'   => ['target' => (str_contains($link[1], 'wp-admin') ? '_self' : '_blank')],
                ];
            }
        }
    }

    return $data;
}

/**
 * Prepare data for admin bar node removals.
 *
 * @param array $data Data array to populate with removal handles.
 * @return array Updated data array.
 */
function frl_admin_bar_remove_menu($data)
{
    // Skip if feature is not enabled
    if (!frl_get_option('ab_remove_links')) {
        return $data;
    }

    // Data preparation
    $data['my_account_title'] = true;

    $raw_handles = frl_get_option('ab_remove_links_handles');
    $option_handles = array_unique(
        array_filter(
            array_map('trim', explode("\n", $raw_handles))
        )
    );
    $data['ab_remove_links_handles'] = array_merge($option_handles, ['wp-logo', 'search']);

    $handles_user_raw = frl_get_option('ab_remove_links_handles_user');
    $handles_user_parsed = frl_textlist_to_array($handles_user_raw);

    // Convert nested array back to flat array since these are simple strings
    $data['ab_remove_links_handles_user'] = array_map(function ($item) {
        return is_array($item) ? $item[0] : $item;
    }, $handles_user_parsed);

    return $data;
}

/**
 * Get the number of entries in the debug log.
 *
 * Uses transients for performance and supports an ignore list for specific log patterns.
 *
 * @return int Total count of non-ignored log entries.
 */
function frl_get_debug_log_count()
{
    // Try to get count from transient first for performance
    $count = frl_get_transient('debug_log_count');

    // If transient doesn't exist, count entries and store in transient
    if ($count === false) {
        $count = 0;
        $log_file = WP_CONTENT_DIR . '/debug.log';

        if (file_exists($log_file)) {
            // Faster than loading entire file into memory
            $handle = @fopen($log_file, 'r');
            if ($handle) {
                $ignore_list = defined('FRL_LOG_COUNT_IGNORE') && is_array(FRL_LOG_COUNT_IGNORE) ? FRL_LOG_COUNT_IGNORE : [];

                while (($line = fgets($handle)) !== false) {
                    $trimmed_line = trim($line);
                    if (empty($trimmed_line)) {
                        continue;
                    }

                    $ignore_line = false;
                    if (!empty($ignore_list)) {
                        foreach ($ignore_list as $ignore_string) {
                            if (str_contains($trimmed_line, $ignore_string)) {
                                $ignore_line = true;
                                break;
                            }
                        }
                    }

                    if (!$ignore_line) {
                        $count++;
                    }
                }
                fclose($handle);
            } else {
                // File exists but can't be opened - cache 0 to avoid repeated failed attempts
                $count = 0;
            }
        } else {
            // File doesn't exist - cache 0
            $count = 0;
        }

        // Cache for 5 minutes (including 0 counts)
        frl_set_transient('debug_log_count', $count, 5 * MINUTE_IN_SECONDS);
    }

    return (int)$count;
}

/**
 * Track and store the last 10 unique page visits for logged-in users.
 *
 * Visits are tracked in user meta and deduplicated if the same URL is visited within 5 minutes.
 *
 * @return void
 */
function frl_trace_logged_user_visits()
{
    // Check for specific actions FIRST
    // Note: clear_visits is now handled by frl_post_clear_visits via admin-post.php
    if (isset($_GET['refresh_visits'])) {
        return; // Don't track visits initiated by refresh button
    }

    // Only track for logged-in users and if option is enabled
    if (!frl_is_logged_in() || !frl_get_option('logged_user_visits')) {
        return;
    }

    // Skip invalid page requests OR administrator actions
    if (!frl_is_valid_page_request() || frl_is_administrator_action()) {
        return;
    }

    // Perform the main query check *here*, where it's reliable
    if (!is_main_query()) {
        // Don't track visits for secondary queries (like AJAX calls within a page)
        return;
    }

    // --- All checks passed, proceed with tracking ---
    // Get basic user info
    $user = frl_get_current_user();
    $user_id = $user->ID;

    // Get the current full URL
    $current_url = '';
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        $current_url .= "https";
    } else {
        $current_url .= "http";
    }
    $current_url .= "://";
    if (isset($_SERVER['HTTP_HOST'])) {
        $current_url .= $_SERVER['HTTP_HOST'];
    }
    if (isset($_SERVER['REQUEST_URI'])) {
        $current_url .= $_SERVER['REQUEST_URI'];
    }

    // Skip if URL couldn't be determined (should rarely happen)
    if (empty($current_url)) { // @phpstan-ignore-line emptyFunctionResult
        return;
    }

    // Get existing visit data from user meta
    $visits = frl_get_user_meta($user_id, 'user_visits') ?: [];

    // Check for duplicates (same user, same URL, within 5 minutes)
    foreach ($visits as $visit) {
        if (
            $visit['page'] === $current_url && // Compare against the full URL
            (strtotime($visit['timestamp']) > (time() - 300))
        ) {
            // Already tracked this page recently
            return;
        }
    }

    // Add current visit with full URL
    array_unshift($visits, [
        'page' => $current_url, // Store the full URL
        'timestamp' => current_time('mysql')
    ]);

    // Keep only the last 10 visits
    $visits = array_slice($visits, 0, 10);

    // Store in user meta
    frl_update_user_meta($user_id, 'user_visits', $visits);
}
