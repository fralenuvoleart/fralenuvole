<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin functionality
 *
 * Handles core admin-specific hooks and functionality.
 *
 * @package Fralenuvole
 * @since 1.0.0
 */

// Include admin utilities
require_once FRL_DIR_PATH . 'admin/helpers/functions-admin.php';

/**
 * ======================================================================
 * HOOK REGISTRATIONS
 * ======================================================================
 */
add_action('plugins_loaded',          'frl_admin_plugins_loaded',          10,   0);
add_action('admin_menu',              'frl_set_custom_admin_menu',          999,  0);
add_action('init',                    'frl_admin_init',                     10,   0);
add_action('current_screen',          'frl_maybe_load_metabox_class',       10,   1);
add_action('admin_enqueue_scripts',   'frl_admin_scripts',                  -999, 0);
add_action('enqueue_block_editor_assets', 'frl_gutenberg_editor_css',       9999, 0);
add_filter('sanitize_file_name',      'frl_get_file_nicename',              10,   1);
add_action('add_attachment',          'frl_update_image_metadata',          10,   1);
add_filter('upload_mimes',            'frl_enable_mime_support',            10,   1);
add_action('wp_dashboard_setup',      'frl_custom_dashboard_widgets',       9999, 0);
add_filter('plugin_action_links_' . FRL_NAME . '/' . FRL_PLUGIN_FILE, 'frl_plugin_settings_link', 10, 1);


/**
 * Register admin hooks
 *
 * @return void
 */
function frl_admin_plugins_loaded()
{
    static $initialized = false;

    // Only initialize once
    if ($initialized) {
        return;
    }

    // 1. FIRST: Load required files
    frl_load_plugin_ui();

    // 2. SECOND: Register posthandlers now that we have the UI functions loaded
    frl_autodiscover_admin_actions();

    // Mark as initialized
    $initialized = true;

}

/**
 * Load plugin UI components
 *
 * @return void
 */
function frl_load_plugin_ui()
{
    // Only initialize once
    if (!frl_is_plugin_context()) {
        return;
    }

    // Load required files
    require_once FRL_DIR_PATH . 'admin/ui/ui-admin-settings.php';

    add_action('admin_init', 'frl_get_settings_page', 10, 0);
    // The hook MUST be available on admin-post.php:
    // Settings form submissions go through admin-post.php
    // frl_settings_updated hook is fired during the admin-post processing
    add_action('frl_settings_updated',
        'frl_handle_settings_update',
        10,
        1);
}

/**
 * Admin init hook.
 *
 * @return void
 */
function frl_admin_init()
{
    if (!frl_get_option('admin_featured_post_list')) {
        return;
    }
    add_filter('manage_posts_columns', 'frl_add_column_featured', 10, 1);
    add_filter('manage_pages_columns', 'frl_add_column_featured', 10, 1);

    add_filter('manage_posts_custom_column', 'frl_add_column_featured_image', 10, 2);
    add_filter('manage_pages_custom_column', 'frl_add_column_featured_image', 10, 2);
}

/**
 * Add featured image column to posts list
 *
 * @param array $columns Existing columns
 * @return array Modified columns
 */
function frl_add_column_featured($columns)
{
    $columns = array_slice($columns, 0, 1, true) + array(FRL_PREFIX . "-featured" => __("Featured", FRL_PREFIX)) + array_slice($columns, 1, count($columns) - 1, true);
    return $columns;
}

/**
 * Add featured image to posts list.
 *
 * @param string $column_name Current column name.
 * @param int $post_id Post ID.
 * @return string The column name.
 */
function frl_add_column_featured_image($column_name, $post_id)
{
    if ($column_name == FRL_PREFIX . "-featured") {
        echo get_the_post_thumbnail($post_id, 'thumbnail');
    }
    return $column_name;
}

/**
 * Main function to set up all admin menus.
 * Acts as an entry point for various menu-related operations.
 *
 * @return void
 */
function frl_set_custom_admin_menu()
{
    // 1. Capture the menu state as it exists when this function is called.
    frl_capture_original_admin_menu();

    // 2. Add the main plugin settings page
    frl_add_plugin_menu();
    // 3. Add translation menu items if relevant
    frl_add_translation_menu();
    // 4. Handle menu item removal for non-admin users
    frl_remove_admin_menus();
    // 5. Reorder menu items based on custom order
    frl_reorder_admin_menu();
}

/**
 * Capture the admin menu as it exists when called.
 * This populates a global variable for the UI to use.
 *
 * @return void
 */
function frl_capture_original_admin_menu() {
    global $menu, $frl_original_admin_menu;
    if (empty($frl_original_admin_menu)) {
        $frl_original_admin_menu = $menu;
    }
}

/**
 * Reorder the main admin menu items based on a custom order from plugin options.
 *
 * This function is called by frl_set_custom_admin_menu and uses usort()
 * to reorder the global $menu array. The desired order is fetched from the
 * 'am_menu_order' option. Items not in the custom order maintain their original position.
 *
 * @return void
 */
function frl_reorder_admin_menu()
{
    global $menu, $frl_original_admin_menu;

    $option = frl_get_option('am_menu_order');
    if (empty($option) || empty($frl_original_admin_menu)) {
        return; // Exit if no custom order is set or original menu wasn't captured.
    }

    $user_id = frl_get_current_user()->ID;
    $option_hash = substr(md5($option), 0, 8);
    $cache_key = "menuorder_uid{$user_id}_{$option_hash}";

    $processed_order = frl_cache_remember('admin', $cache_key, function() use ($option) {
        $order_list = frl_textlist_to_array($option);
        if (empty($order_list)) {
            return [];
        }

        // Create the user's desired order map.
        $desired_order = [];
        foreach ($order_list as $item) {
            if (count($item) === 2) {
                $slug = trim($item[0]);
                $order = floatval(trim($item[1]));
                $desired_order[$slug] = $order;
            }
        }
        return $desired_order;
    });

    if (empty($processed_order)) {
        return;
    }

    // Create a lookup map of original menu positions.
    $original_order_map = [];
    foreach ($frl_original_admin_menu as $original_order => $original_item) {
        if (isset($original_item[2])) {
            $original_order_map[$original_item[2]] = $original_order;
        }
    }

    usort($menu, function ($a, $b) use ($processed_order, $original_order_map) {
        // Ensure the menu items are valid arrays.
        if (!isset($a[2]) || !isset($b[2])) {
            return 0;
        }

        $slug_a = $a[2];
        $slug_b = $b[2];

        // Use the custom order if it exists, otherwise fall back to the item's original order.
        $pos_a = $processed_order[$slug_a] ?? $original_order_map[$slug_a] ?? 999;
        $pos_b = $processed_order[$slug_b] ?? $original_order_map[$slug_b] ?? 999;

        // If positions are different, sort by position.
        if ($pos_a !== $pos_b) {
            return $pos_a <=> $pos_b;
        }

        // If positions are the same, use original position as a tie-breaker for a stable sort.
        $original_pos_a = $original_order_map[$slug_a] ?? 999;
        $original_pos_b = $original_order_map[$slug_b] ?? 999;

        return $original_pos_a <=> $original_pos_b;
    });
}

/**
 * Register the main plugin settings page.
 *
 * PERFORMANCE OPTIMIZATION:
 * - Now uses selective component loading to only load settings UI files.
 *
 * @return void
 */
function frl_add_plugin_menu()
{
    $page_title = frl_name('Plugin');
    $menu_title = frl_name();
    $capability = 'manage_options';
    $slug = FRL_NAME;
    $callback = 'frl_render_admin_ui';

    add_submenu_page(
        'options-general.php',
        $page_title,
        $menu_title,
        $capability,
        $slug,
        $callback
    );
}


/**
 * Render the admin UI settings page.
 *
 * @return bool True upon successful rendering.
 */
function frl_render_admin_ui()
{
    static $rendered = false;

    // Only render page if not already rendered
    if (!$rendered) {
        // Render the page
        frl_settings_fields_render_settings_page();
        $rendered = true;
    }

    return true;
}

/**
 * Enqueue admin-specific styles and scripts.
 *
 * This function is hooked to admin_enqueue_scripts (see line 17) and loads
 * the basic CSS needed for all admin pages. UI-specific assets for the
 * settings page are loaded separately by Frl Settings_Fields::load_ui_assets()
 * to avoid loading them on all admin pages.
 *
 * @return void
 */
function frl_admin_scripts()
{
    $assets = ['admin-css' => 'assets/css/admin.css'];
    frl_enqueue_scripts($assets, 'admin');
}

/**
 * Load theme stylesheets in the Gutenberg editor
 *
 * This function is hooked to enqueue_block_editor_assets and
 * loads the theme's main stylesheet in the block editor to ensure styles are
 * consistent between the editor and the frontend.
 *
 */
function frl_gutenberg_editor_css()
{
    // Get theme stylesheet path and URL
    $theme_style_path = get_theme_file_path('style.css');
    $theme_style_url = get_stylesheet_uri(); // Theme's main stylesheet URL

    $version = frl_cache_remember('admin', 'gutenberg_style', function () use ($theme_style_path) {
        return file_exists($theme_style_path) ? filemtime($theme_style_path) : false;
    });

    if ($version === false) {
        return;
    }

    // If on a post edit screen, load the theme stylesheet
    wp_enqueue_style(
        FRL_PREFIX . '-editor',
        $theme_style_url, // Use URL variable
        [],
        $version
    );
}

/**
 * Remove menu items for non-admin users based on plugin settings.
 *
 * @return void
 */
function frl_remove_admin_menus()
{
    if (!frl_get_option('am_remove_links')) {
        return;
    }

    $handles = frl_textlist_to_array(
        frl_get_option('am_remove_links_handles') ?: ''
    );

    if (!empty($handles)) {
        foreach ($handles as $handle_parts) {
            // Handle both single strings and pipe-separated arrays
            $handle = count($handle_parts) > 1 ? $handle_parts : $handle_parts[0];
            frl_remove_admin_menus_item($handle);
        }
    }

    if (frl_has_access()) {
        return;
    }

    $handles_notadmin = frl_textlist_to_array(
        frl_get_option('am_remove_links_handles_user') ?: ''
    );

    if (!empty($handles_notadmin)) {
        foreach ($handles_notadmin as $handle_parts) {
            // Handle both single strings and pipe-separated arrays
            $handle_notadmin = count($handle_parts) > 1 ? $handle_parts : $handle_parts[0];
            frl_remove_admin_menus_item($handle_notadmin);
        }
    }
}

/**
 * Remove a specific menu item for non-admin users based on plugin settings.
 *
 * @param string|array $handle Menu handle (string for main menu, array for submenu).
 * @return void
 */
function frl_remove_admin_menus_item($handle)
{
    $style = '';
    $menu = '';

    if (is_array($handle)) {
        $menu = $handle[0] ?? '';
        $submenu = $handle[1] ?? '';

        $submenu_removed = remove_submenu_page($menu, $submenu);
        if (!$submenu_removed) {;
            $style .= frl_generate_style_remove_admin_menu( $submenu );
        }
    } elseif (is_string($handle)) {
        $menu = $handle;
        $menu_removed = remove_menu_page($menu);
        if (!$menu_removed) {
            $style .= frl_generate_style_remove_admin_menu( $menu, true );
        }
    }
    if (!empty($style)) {
        add_action('admin_print_styles',
            function () use ($style, $menu) {
                echo '<style id="' . FRL_PREFIX . '-remove-adminmenu-' . $menu . '">' . $style . '</style>';
            },
            10,
            0);
    }
}


/**
 * Generate style to remove admin menu item based on plugin settings.
 *
 * @param string $target The menu handle or target to hide.
 * @param bool $is_class Whether to target by class instead of href.
 * @return string The CSS style string.
 */
function frl_generate_style_remove_admin_menu($target, $is_class = false)
{
    $style = '#adminmenu li:has(>a[href*="' . $target . '"]) {display:none;}';

    if ($is_class) {
        $style .= '#adminmenu li[class*="' . $target . '"] {display:none;}';
        $style .= '#adminmenu li>a[class*="' . $target . '"] {display:none;}';
    }

    return $style;
}

/**
 * Add translation-related menu items when Polylang is active.
 *
 * @return void
 */
function frl_add_translation_menu()
{
    if (frl_is_multilingual('pll_get_post')) {
        $parent_slug = 'mlang';
        $page_title = __('Menu Translation', FRL_PREFIX);
        $menu_title = __('Menu Translation', FRL_PREFIX);
        $capability = 'edit_pages';
        $menu_slug = 'edit.php?post_type=wp_navigation';

        add_submenu_page($parent_slug, $page_title, $menu_title, $capability, $menu_slug);
    }
}

/**
 * Maybe load the metabox class if we're on a post edit screen.
 *
 * This function is hooked to current_screen (see line 15) and conditionally loads
 * the metabox class only when viewing a post edit screen. This improves performance
 * by avoiding loading unnecessary code on other admin pages.
 *
 * PERFORMANCE OPTIMIZATION:
 * - Added early check for the custom_metaboxes option to avoid unnecessary loading
 * - Added caching of the option check to avoid repeated DB queries
 *
 * @param WP_Screen $screen Current admin screen.
 * @return void
 */
function frl_maybe_load_metabox_class($screen)
{
    static $metabox_enabled = null;

    // Early exit if metaboxes are disabled
    if ($metabox_enabled === null) {
        $metabox_enabled = frl_get_option('editor_metabox');
    }

    if (!$metabox_enabled) {
        return;
    }

    // Check if we're on a post edit or post add new screen
    if ($screen && ($screen->base === 'post' || $screen->action === 'add')) {
        // Load for all post types - this ensures custom post types are supported
        require_once FRL_DIR_PATH . 'admin/metaboxes/class-metabox.php';
    }
}

/**
 * Allow additional file types to be uploaded
 *
 * This function is hooked to upload_mimes (see line 20) and adds support for
 * WebP and SVG file uploads based on plugin settings. These formats are disabled
 * by default in WordPress but can be safely enabled with proper validation.
 *
 * @param array $mimes An associative array of allowed file types
 * @return array Modified array of allowed file types
 */
function frl_enable_mime_support(array $mimes): array
{
    if (frl_get_option('webp_support')) {
        $mimes['webp'] = 'image/webp';
    }

    if (frl_get_option('svg_support')) {
        $mimes['svg'] = 'image/svg+xml';
    }

    return $mimes;
}

/**
 * Sanitize a filename with options for different output formats
 *
 * @param string $filename The original filename to sanitize
 * @param bool $as_title Whether to format as a title (true) or filename (false)
 * @return string Sanitized filename or title
 */
function frl_get_file_nicename($filename, $as_title = false)
{
    if (!frl_get_option('sanitize_filename')) {
        return $filename;
    }

    // Get file extension if present
    $extension = '';
    $basename = $filename;

    // Always remove extension for processing
    if (str_contains($filename, '.')) {
        $extension = !$as_title ? '.' . pathinfo($filename, PATHINFO_EXTENSION) : '';
        $basename = pathinfo($filename, PATHINFO_FILENAME);
    }

    // Convert accented characters to ASCII
    if (function_exists('transliterator_transliterate')) {
        // Use intl extension if available (more comprehensive)
        $basename = transliterator_transliterate('Any-Latin; Latin-ASCII', $basename);
    } else {
        // Fallback to WordPress function
        $basename = remove_accents($basename);
    }

    // Convert to lowercase
    $basename = strtolower($basename);

    // Remove numbers at the end of the basename
    $basename = preg_replace('/-?\d+$/', '', $basename);

    if ($as_title) {
        // For title format: replace underscores and hyphens with spaces
        $title = str_replace(['-', '_'], ' ', $basename);

        // Remove any remaining non-alphanumeric characters except spaces
        $title = preg_replace('/[^a-z0-9 ]/', '', $title);

        // Remove multiple spaces
        $title = preg_replace('/\s+/', ' ', $title);

        // Capitalize words
        $title = ucwords(trim($title));

        return $title;
    } else {
        // For filename format: replace underscores with hyphens
        $clean_name = str_replace('_', '-', $basename);

        // Remove any non-alphanumeric characters except hyphens
        $clean_name = preg_replace('/[^a-z0-9-]/', '', $clean_name);

        // Remove multiple hyphens
        $clean_name = preg_replace('/-+/', '-', $clean_name);

        // Add extension back if it existed
        return trim($clean_name, '-') . $extension;
    }
}

/**
 * Dynamically modify image alt text during upload.
 *
 * @param int $attachment_id The ID of the newly uploaded attachment.
 * @return void
 */
function frl_update_image_metadata($attachment_id)
{
    // Make sure it's an image
    if (!wp_attachment_is_image($attachment_id)) {
        return;
    }

    // Get the image filename
    $filename = basename(get_attached_file($attachment_id));

    // Generate title based on filename
    $img_title = frl_get_file_nicename($filename, true);

    // Update the Alt text
    update_post_meta($attachment_id, '_wp_attachment_image_alt', $img_title);

    // Create image object
    $image = array(
        'ID'            => $attachment_id,
        'post_title'    => $img_title,  // Image Title
        'post_excerpt'  => $img_title, // Image Caption
        'post_content'  => $img_title, // Image Description
    );

    wp_update_post($image);
}

/**
 * Add and customize dashboard widgets.
 *
 * This function is hooked to wp_dashboard_setup (see line 21) and handles both
 * adding custom dashboard widgets and removing default WordPress widgets based
 * on plugin settings. It uses capability checks to ensure the right widgets
 * are shown to the right users.
 *
 * PERFORMANCE OPTIMIZATION: Widget content is now loaded only when the widget
 * is actually rendered rather than loading all widget content up front.
 *
 * @return void
 */
function frl_custom_dashboard_widgets()
{
    // Define widget configurations including render_file and render_callback
    $widgets = [
        'editor' => [
            'title' => __('Editor Panel'),
            'cap' => 'edit_posts',
            'render_file' => FRL_DIR_PATH . 'admin/widgets/widget-editor.php',
            'render_callback' => 'frl_render_editor_widget', // Assumes this function exists/will be created in the file
        ],
        'administrator' => [
            'title' => __('Admin Panel'),
            'cap' => 'manage_options',
            'render_file' => FRL_DIR_PATH . 'admin/widgets/widget-administrator.php',
            'render_callback' => 'frl_render_administrator_widget', // Assumes this function exists/will be created in the file
        ],
        'last_posts' => [
            'title' => __('Last updates'),
            'cap' => 'edit_posts',
            'render_file' => FRL_DIR_PATH . 'admin/widgets/widget-last-posts.php',
            'render_callback' => 'frl_render_last_posts_widget',
            'refresh_button' => true,
        ],
        'user_visits' => [
            'title' => __('User Visits', FRL_PREFIX),
            'cap' => '',
            'render_file' => FRL_DIR_PATH . 'admin/widgets/widget-user-visits.php',
            'render_callback' => 'frl_render_user_visits_widget',
            'refresh_button' => true,
        ],
        'custom_html_1' => [
            'title' => frl_get_option('dash_widget_custom_html_label_1') ?: __('Custom Widget 1', FRL_PREFIX),
            'cap' => frl_get_option('dash_widget_custom_html_cap_1') ?: 'delete_plugins',
            'render_file' => FRL_DIR_PATH . 'admin/widgets/widget-custom-html.php',
            'render_callback' => 'frl_render_custom_html_widget_1',
            'enabled_option_key' => 'dash_widget_custom_html_enabled',
        ],
        'custom_html_2' => [
            'title' => frl_get_option('dash_widget_custom_html_label_2') ?: __('Custom Widget 2', FRL_PREFIX),
            'cap' => frl_get_option('dash_widget_custom_html_cap_2') ?: 'delete_plugins',
            'render_file' => FRL_DIR_PATH . 'admin/widgets/widget-custom-html.php',
            'render_callback' => 'frl_render_custom_html_widget_2',
            'enabled_option_key' => 'dash_widget_custom_html_enabled',
        ],
        'custom_html_3' => [
            'title' => frl_get_option('dash_widget_custom_html_label_3') ?: __('Custom Widget 3', FRL_PREFIX),
            'cap' => frl_get_option('dash_widget_custom_html_cap_3') ?: 'delete_plugins',
            'render_file' => FRL_DIR_PATH . 'admin/widgets/widget-custom-html.php',
            'render_callback' => 'frl_render_custom_html_widget_3',
            'enabled_option_key' => 'dash_widget_custom_html_enabled',
        ],
    ];

    // Allow other modules to add their widgets configuration to the array
    $widgets = apply_filters('frl_add_dashboard_widgets', $widgets);

    // Add custom dashboard widgets using configurations
    foreach ($widgets as $id => $widget_config) {
        // Basic validation of configuration
        if (empty($widget_config['title']) || !isset($widget_config['cap'])) {
            frl_log("Invalid configuration for dashboard widget '{$id}'. Skipping.");
            continue;
        }

        // Determine the option key to check for enabling the widget
        $enable_option = !empty($widget_config['enabled_option_key'])
            ? $widget_config['enabled_option_key']
            : "dash_widget_{$id}"; // Fallback for core widgets

        // Check options (using determined key) and capability before registering
        if (frl_get_option($enable_option) && frl_has_access($widget_config['cap'])) {

            // For custom HTML widgets, skip if content is empty
            if (str_starts_with($id, 'custom_html_')) {
                $widget_num = (int) substr($id, -1);
                $content_key = "dash_widget_custom_html_content_{$widget_num}";
                if (empty(trim(frl_get_option($content_key) ?: ''))) {
                    continue;
                }
            }

            // Ensure the Renderer class is loaded before trying to use its static method
            // We can do this once before the loop, or check within
            static $renderer_class_loaded = null;
            if ($renderer_class_loaded === null) {
                $renderer_path = FRL_DIR_PATH . 'admin/ui/class-dashboard-renderer.php';
                if (is_readable($renderer_path)) {
                    require_once $renderer_path;
                    $renderer_class_loaded = true;
                } else {
                    frl_log('Dashboard Renderer class not found. Cannot render widgets.');
                    $renderer_class_loaded = false;
                }
            }

            // Only add widget if renderer class is available
            if ($renderer_class_loaded) {
                // Construct the DOM ID for HTML, replacing underscores with hyphens
                $widget_dom_id = FRL_PREFIX . "-widget-" . str_replace('_', '-', $id);

                // Centralized registration, passing the widget config to the Renderer's static method
                wp_add_dashboard_widget(
                    $widget_dom_id,
                    $widget_config['title'],
                    function () use ($id, $widget_config) {
                        $widget_config['key'] = $id;
                        frl_dashboard_widget_render($widget_config);
                    }
                );
            }
        }
    }

    // Remove dashboard widgets
    if (frl_get_option('remove_dash_widg')) {
        remove_action('welcome_panel', 'wp_welcome_panel');
        remove_meta_box('dashboard_primary', 'dashboard', 'side');
        remove_meta_box('dashboard_quick_press', 'dashboard', 'side');

        $dash_widg_handles = frl_get_option('remove_dash_widg_handles');
        $handles = frl_textlist_to_array($dash_widg_handles);

        if (!empty($handles)) {
            foreach ($handles as $handle_parts) {
                // Extract the first element as the handle (since these are just simple strings)
                $handle = $handle_parts[0];
                if (is_string($handle)) {
                    remove_meta_box($handle, 'dashboard', 'normal');
                    remove_meta_box($handle, 'dashboard', 'side');
                }
            }
        }
    }
}

/**
 * Add Settings link to plugin action links in the Plugins list table
 *
 * This function adds a "Settings" link to the plugin's row in the plugins list table,
 * making it easier for users to access the plugin settings page directly.
 *
 * @param array $links Array of plugin action links
 * @return array Modified array of plugin action links
 */
function frl_plugin_settings_link(array $links)
{
    static $settings_link = null;

    if ($settings_link === null) {
        $settings_link = '<a href="' . esc_url(FRL_PLUGIN_ADMIN_URL) . '">'
            . esc_html__('Settings', FRL_PREFIX)
            . '</a>';
    }
    $links[] = $settings_link;
    return $links;
}
