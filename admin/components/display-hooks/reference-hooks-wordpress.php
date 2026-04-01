<?php
/**
 * Comprehensive WordPress Hooks List - Master Reference
 *
 * This file contains a single master list of WordPress hooks with:
 * - Global execution order
 * - Context (admin, public, all)
 * - Hook type (action, filter)
 * - User login status (logged-in, logged-out, both)
 * - Number of default arguments
 * - Short description of what the hook does
 *
 * Based on WordPress 6.5.5
 */

$wordpress_hooks_master_sequence = [
    // Core initialization hooks
    // Admin-specific early hooks
    // Front-end request handling
    // Admin assets and output
    // Front-end template handling
    // Admin notices and content
    // Front-end content loop
    // Sidebar and widget handling
    // Admin columns hooks
    // Comments handling
    // Footer handling for both contexts
    // Footer scripts
    // Editor and resource hooks
    // Admin bar rendering
    // Final shutdown hook
    // Common filter hooks
    // Plugin action links hooks
    // User profile hooks
    // Comments hooks
    // Authentication hooks
    // Plugin and theme hooks
    // REST API hooks
    // AJAX hooks
    // Admin post hooks
    // Comment hooks
    // Media hooks
    // Cron hooks
    // Database hooks
    // User hooks
    // Menu hooks
    // Customizer hooks
    // Permalink hooks
    // Editor hooks
    // Heartbeat API hooks
    // Block editor hooks
    // Site health hooks
    // Privacy hooks
    // Multisite hooks
    // Update hooks
    // Rewrite hooks
    // HTTP API hooks
    // Shortcode hooks
    // Embed hooks
    // Translation hooks
    // Cache hooks
    // Transient hooks
    // Metadata hooks
    // Term hooks
    // Taxonomy hooks
    // Admin bar hooks
    // Post hooks
    // Theme JSON hooks

    // Core initialization hooks
    'doing_it_wrong_run' => [
        'order' => 0.5,
        'context' => 'core',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 3,
        'description' => 'Fires when a function is used incorrectly and triggers _doing_it_wrong()'
    ],
    'doing_it_wrong_trigger_error' => [
        'order' => 0.6,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 1,
        'description' => 'Filters whether to trigger an error for _doing_it_wrong() calls'
    ],
    'mu_plugin_loaded' => [
        'order' => 1,
        'context' => 'core',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 1,
        'description' => 'Fires after a single must-use plugin is loaded before any other hook is called'
    ],
    'network_plugin_loaded' => [
        'order' => 2,
        'context' => 'core',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 1,
        'description' => 'Fires as each network-activated plugin is loaded'
    ],
    'muplugins_loaded' => [
        'order' => 3,
        'context' => 'core',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 0,
        'description' => 'Fires once all must-use plugins are loaded'
    ],
    'registered_taxonomy' => [
        'order' => 4,
        'context' => 'core',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 3,
        'description' => 'Fires after a taxonomy is registered'
    ],
    'registered_post_type' => [
        'order' => 5,
        'context' => 'core',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 2,
        'description' => 'Fires after a post type is registered'
    ],
    'plugins_loaded' => [
        'order' => 6,
        'context' => 'core',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 0,
        'description' => 'Fires once activated plugins have loaded'
    ],
    'sanitize_comment_cookies' => [
        'order' => 7,
        'context' => 'core',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 0,
        'description' => 'Fires when comment cookies are sanitized'
    ],
    'setup_theme' => [
        'order' => 8,
        'context' => 'core',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 0,
        'description' => 'Fires before the theme is loaded'
    ],
    'load_textdomain' => [
        'order' => 9,
        'context' => 'core',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 2,
        'description' => 'Fires when a textdomain is loaded'
    ],
    'after_setup_theme' => [
        'order' => 10,
        'context' => 'core',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 0,
        'description' => 'Fires after the theme is loaded and theme features can be registered'
    ],
    'auth_cookie_malformed' => [
        'order' => 11,
        'context' => 'core',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 1,
        'description' => 'Fires when an authentication cookie is malformed'
    ],
    'auth_cookie_valid' => [
        'order' => 12,
        'context' => 'core',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 2,
        'description' => 'Fires when an authentication cookie is validated'
    ],
    'set_current_user' => [
        'order' => 13,
        'context' => 'core',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 2,
        'description' => 'Fires after the current user is set'
    ],
    'init' => [
        'order' => 14,
        'context' => 'core',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 0,
        'description' => 'Fires after WordPress has finished loading but before any headers are sent'
    ],
    'widgets_init' => [
        'order' => 15,
        'context' => 'core',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 0,
        'description' => 'Fires after all default WordPress widgets have been registered'
    ],
    'register_sidebar' => [
        'order' => 16,
        'context' => 'core',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 1,
        'description' => 'Fires once a sidebar has been registered'
    ],
    'wp_register_sidebar_widget' => [
        'order' => 17,
        'context' => 'core',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 1,
        'description' => 'Fires once a widget has been registered'
    ],
    'wp_default_scripts' => [
        'order' => 18,
        'context' => 'core',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 1,
        'description' => 'Fires when the default scripts are registered'
    ],
    'wp_default_styles' => [
        'order' => 19,
        'context' => 'core',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 1,
        'description' => 'Fires when the default styles are registered'
    ],
    'admin_bar_init' => [
        'order' => 20,
        'context' => 'core',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 0,
        'description' => 'Fires when the admin bar is initialized'
    ],
    'add_admin_bar_menus' => [
        'order' => 21,
        'context' => 'core',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 0,
        'description' => 'Fires when admin bar menus are added'
    ],
    'wp_loaded' => [
        'order' => 22,
        'context' => 'core',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 0,
        'description' => 'Fires once WordPress core, plugins, and theme are fully loaded and instantiated'
    ],
    'parse_request' => [
        'order' => 29,
        'context' => 'public',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 1,
        'description' => 'Fires once the WordPress environment has been set up'
    ],
    'rest_api_init' => [
        'order' => 400,
        'context' => 'core',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 1,
        'description' => 'Fires when preparing to serve a REST API request'
    ],
    'send_headers' => [
        'order' => 30,
        'context' => 'public',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 1,
        'description' => 'Fires when custom HTTP headers should be sent'
    ],
    'parse_query' => [
        'order' => 31,
        'context' => 'public',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 1,
        'description' => 'Fires after the main query vars have been parsed'
    ],
    'pre_get_posts' => [
        'order' => 32,
        'context' => 'public',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 1,
        'description' => 'Fires after the query variable object is created, but before the query is run'
    ],
    'posts_selection' => [
        'order' => 33,
        'context' => 'public',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 1,
        'description' => 'Fires after the query has been processed but before results are retrieved'
    ],
    'wp' => [
        'order' => 34,
        'context' => 'public',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 1,
        'description' => 'Fires once the WordPress environment has been set up'
    ],
    'template_redirect' => [
        'order' => 42,
        'context' => 'public',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 0,
        'description' => 'Fires before WordPress determines which template to load'
    ],
    'get_header' => [
        'order' => 43,
        'context' => 'public',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 1,
        'description' => 'Fires before the header template file is loaded'
    ],
    'wp_head' => [
        'order' => 45,
        'context' => 'public',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 0,
        'description' => 'Fires in the head section of the front end and admin'
    ],
    'wp_enqueue_scripts' => [
        'order' => 44,
        'context' => 'public',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 0,
        'description' => 'Fires when scripts and styles are enqueued for the front end'
    ],
    'wp_print_styles' => [
        'order' => 46,
        'context' => 'public',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 0,
        'description' => 'Fires when styles are printed for the front end'
    ],
    'wp_print_scripts' => [
        'order' => 47,
        'context' => 'public',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 0,
        'description' => 'Fires when scripts are printed for the front end'
    ],
    'loop_start' => [
        'order' => 53,
        'context' => 'public',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 1,
        'description' => 'Fires when the loop is started'
    ],
    'the_post' => [
        'order' => 54,
        'context' => 'public',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 1,
        'description' => 'Fires after the post data is set up'
    ],
    'loop_end' => [
        'order' => 56,
        'context' => 'public',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 1,
        'description' => 'Fires when the loop has ended'
    ],
    'get_sidebar' => [
        'order' => 57,
        'context' => 'public',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 1,
        'description' => 'Fires before the sidebar template file is loaded'
    ],
    'dynamic_sidebar_before' => [
        'order' => 58,
        'context' => 'public',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 1,
        'description' => 'Fires before widgets are rendered in a sidebar'
    ],
    'dynamic_sidebar' => [
        'order' => 59,
        'context' => 'public',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 1,
        'description' => 'Fires after widgets are rendered in a sidebar'
    ],
    'dynamic_sidebar_after' => [
        'order' => 60,
        'context' => 'public',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 1,
        'description' => 'Fires after widgets are rendered in a sidebar'
    ],
    'pre_get_comments' => [
        'order' => 61,
        'context' => 'public',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 1,
        'description' => 'Fires after the comment query variables are set, but before the query is run'
    ],
    'wp_meta' => [
        'order' => 62,
        'context' => 'public',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 0,
        'description' => 'Fires at the end of the widgets section of the sidebar'
    ],
    'get_footer' => [
        'order' => 63,
        'context' => 'public',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 1,
        'description' => 'Fires before the footer template file is loaded'
    ],
    'wp_footer' => [
        'order' => 67,
        'context' => 'public',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 0,
        'description' => 'Fires at the end of the body section in the front end'
    ],
    'wp_print_footer_scripts' => [
        'order' => 70,
        'context' => 'public',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 0,
        'description' => 'Fires when footer scripts are printed'
    ],
    'admin_bar_menu' => [
        'order' => 71,
        'context' => 'core',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 1,
        'description' => 'Fires when admin bar nodes are added'
    ],
    'wp_before_admin_bar_render' => [
        'order' => 72,
        'context' => 'core',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 0,
        'description' => 'Fires before the admin bar is rendered'
    ],
    'wp_after_admin_bar_render' => [
        'order' => 73,
        'context' => 'core',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 0,
        'description' => 'Fires after the admin bar is rendered'
    ],
    'shutdown' => [
        'order' => 74,
        'context' => 'core',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 0,
        'description' => 'Fires just before PHP shuts down execution'
    ],
    // Admin hooks
    'admin_init' => [
        'order' => 26,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 0,
        'description' => 'Fires as an admin screen or script is being initialized'
    ],
    'admin_menu' => [
        'order' => 25,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 0,
        'description' => 'Fires when the admin menu is being built'
    ],
    'admin_enqueue_scripts' => [
        'order' => 35,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 0,
        'description' => 'Fires when scripts and styles are enqueued for admin pages'
    ],
	// Login page assets
	'login_enqueue_scripts' => [
		'order' => 36,
		'context' => 'public',
		'hook_type' => 'action',
		'user_logged' => 'logged-out',
		'args' => 0,
		'description' => 'Fires when scripts and styles are enqueued for the login page'
	],
    'admin_print_styles' => [
        'order' => 37,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 0,
        'description' => 'Fires when admin styles should be printed'
    ],
    'admin_print_scripts' => [
        'order' => 39,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 0,
        'description' => 'Fires when admin scripts should be printed'
    ],
    'admin_head' => [
        'order' => 41,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 0,
        'description' => 'Fires in the head section of an admin page'
    ],
    'admin_footer' => [
        'order' => 65,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 0,
        'description' => 'Fires in the admin footer'
    ],
    'wp_dashboard_setup' => [
        'order' => 26.5,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 0,
        'description' => 'Fires when the dashboard widgets are initialized'
    ],
    'add_meta_boxes' => [
        'order' => 26.7,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 2,
        'description' => 'Fires when meta boxes are added to the post editing screen, passes post type and post object'
    ],
    'manage_posts_columns' => [
        'order' => 60.1,
        'context' => 'admin',
        'hook_type' => 'filter',
        'user_logged' => 'logged-in',
        'args' => 1,
        'description' => 'Filters the columns displayed in the Posts list table'
    ],
    'manage_posts_custom_column' => [
        'order' => 60.2,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 2,
        'description' => 'Fires for each custom column in the Posts list table'
    ],
    'manage_pages_columns' => [
        'order' => 60.3,
        'context' => 'admin',
        'hook_type' => 'filter',
        'user_logged' => 'logged-in',
        'args' => 1,
        'description' => 'Filters the columns displayed in the Pages list table'
    ],
    'manage_pages_custom_column' => [
        'order' => 60.4,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 2,
        'description' => 'Fires for each custom column in the Pages list table'
    ],
    // Filter hooks
    'pre_option' => [
        'order' => 2000,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 3,
        'description' => 'Filters the value of all existing options before they are retrieved.'
    ],
    'update_option' => [
        'order' => 2001,
        'context' => 'core',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 3,
        'description' => 'Fires immediately before an option value is updated.'
    ],
    'updated_option' => [
        'order' => 2002,
        'context' => 'core',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 3,
        'description' => 'Fires after the value of an option has been successfully updated.'
    ],
    'tiny_mce_plugins' => [
        'order' => 70.1,
        'context' => 'admin',
        'hook_type' => 'filter',
        'user_logged' => 'logged-in',
        'args' => 1,
        'description' => 'Filters the list of TinyMCE plugins'
    ],
    'wp_resource_hints' => [
        'order' => 45.5,
        'context' => 'public',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 2,
        'description' => 'Filters domains and URLs for resource hints of the given relation type'
    ],
    // Other hooks
    'pre_option_{$option}' => [
        'order' => 1,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 3,
        'description' => 'Filters the value of an existing option before it is retrieved.'
    ],
    'wp_theme_json_data_default' => [
        'order' => 10.2,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 1,
        'description' => 'Filters the data provided by the theme for global styles and settings after WordPress defaults'
    ],
    'wp_theme_json_data_theme' => [
        'order' => 10.5,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 1,
        'description' => 'Filters the data provided by the theme for global styles and settings after current theme'
    ],
    'auth_redirect' => [
        'order' => 23,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 0,
        'description' => 'Fires before the authentication redirect takes place in admin'
    ],
    '_admin_menu' => [
        'order' => 24,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 0,
        'description' => 'Fires before the admin menu is built'
    ],
    'current_screen' => [
        'order' => 27,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 1,
        'description' => 'Fires after the current screen has been set'
    ],
    'load-{$page_hook}' => [
        'order' => 28,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 0,
        'description' => 'Dynamic action fired when a specific admin page is loaded'
    ],
    'admin_print_styles-{$hook_suffix}' => [
        'order' => 36,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 0,
        'description' => 'Dynamic action fired before styles are printed for a specific admin page'
    ],
    'admin_print_scripts-{$hook_suffix}' => [
        'order' => 38,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 0,
        'description' => 'Dynamic action fired before scripts are printed for a specific admin page'
    ],
    'admin_head-{$hook_suffix}' => [
        'order' => 40,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 0,
        'description' => 'Dynamic action fired in the head section for a specific admin page'
    ],
    'style_loader_tag' => [
        'order' => 48,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 4,
        'description' => 'Filters the HTML link tag of an enqueued style.'
    ],
    'script_loader_tag' => [
        'order' => 48,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 3,
        'description' => 'Filters the HTML script tag of an enqueued script.'
    ],
    'in_admin_header' => [
        'order' => 48,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 0,
        'description' => 'Fires at the end of the admin header'
    ],
    'admin_notices' => [
        'order' => 49,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 0,
        'description' => 'Fires for displaying admin notices'
    ],
    'network_admin_notices' => [
        'order' => 50,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 0,
        'description' => 'Fires for displaying network admin notices'
    ],
    'user_admin_notices' => [
        'order' => 51,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 0,
        'description' => 'Fires for displaying user admin notices'
    ],
    'all_admin_notices' => [
        'order' => 52,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 0,
        'description' => 'Fires for displaying all admin notices'
    ],
    'get_template_part_{$slug}' => [
        'order' => 55,
        'context' => 'public',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 2,
        'description' => 'Dynamic action fired when a specific template part is loaded'
    ],
    'admin_footer-{$hook_suffix}' => [
        'order' => 64,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 0,
        'description' => 'Dynamic action fired before the admin footer for a specific admin page'
    ],
    'in_admin_footer' => [
        'order' => 66,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 0,
        'description' => 'Fires at the end of the admin footer'
    ],
    'admin_print_footer_scripts-{$hook_suffix}' => [
        'order' => 68,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 0,
        'description' => 'Dynamic action fired when footer scripts are printed for a specific admin page'
    ],
    'admin_print_footer_scripts' => [
        'order' => 69,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 0,
        'description' => 'Fires when footer scripts are printed in the admin'
    ],
    'the_content' => [
        'order' => 100,
        'context' => 'public',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 1,
        'description' => 'Filters the post content'
    ],
    'the_title' => [
        'order' => 101,
        'context' => 'public',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 2,
        'description' => 'Filters the post title'
    ],
    'the_excerpt' => [
        'order' => 102,
        'context' => 'public',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 2,
        'description' => 'Filters the post excerpt'
    ],
    'wp_get_attachment_url' => [
        'order' => 103,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 2,
        'description' => 'Filters the attachment URL'
    ],
    'body_class' => [
        'order' => 104,
        'context' => 'public',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 2,
        'description' => 'Filters the list of CSS body classes for the current post or page'
    ],
    'post_class' => [
        'order' => 105,
        'context' => 'public',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 3,
        'description' => 'Filters the list of CSS classes for the current post'
    ],
    'admin_body_class' => [
        'order' => 106,
        'context' => 'admin',
        'hook_type' => 'filter',
        'user_logged' => 'logged-in',
        'args' => 1,
        'description' => 'Filters the CSS classes for the body tag in the admin'
    ],
    'author_link' => [
        'order' => 107,
        'context' => 'public',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 3,
        'description' => 'Filters the URL for the author page of the current author'
    ],
    'query_vars' => [
        'order' => 108,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 1,
        'description' => 'Filters the query variables whitelist before processing.'
    ],
    'request' => [
        'order' => 108.5,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 1,
        'description' => 'Filters the query variables whitelist before processing like query_vars, but applied after "extra" and private query variables have been added.'
    ],
    'category_rewrite_rules' => [
        'order' => 109,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 1,
        'description' => 'Filters the category rewrite rules'
    ],
    'block_type_metadata_settings' => [
        'order' => 110,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 2,
        'description' => 'Filters the settings determined from block type metadata'
    ],
    'plugin_action_links' => [
        'order' => 111,
        'context' => 'admin',
        'hook_type' => 'filter',
        'user_logged' => 'logged-in',
        'args' => 4,
        'description' => 'Filters the action links displayed for each plugin in the Plugins list table'
    ],
    'plugin_action_links_{$plugin_file}' => [
        'order' => 112,
        'context' => 'admin',
        'hook_type' => 'filter',
        'user_logged' => 'logged-in',
        'args' => 4,
        'description' => 'Filters the action links displayed for a specific plugin in the Plugins list table'
    ],
    'show_user_profile' => [
        'order' => 113,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 1,
        'description' => 'Fires after the "About Yourself" settings table on the Profile editing screen for the current user'
    ],
    'edit_user_profile' => [
        'order' => 114,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 1,
        'description' => 'Fires after the "About the User" settings table on the Edit User screen for other users'
    ],
    'personal_options_update' => [
        'order' => 115,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 1,
        'description' => 'Fires before the page loads on the Profile editing screen for the current user'
    ],
    'edit_user_profile_update' => [
        'order' => 116,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 1,
        'description' => 'Fires before the page loads on the Edit User screen for other users'
    ],
    'get_avatar_data' => [
        'order' => 117,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 2,
        'description' => 'Filters the avatar data before it is processed'
    ],
    'comments_array' => [
        'order' => 118,
        'context' => 'public',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 2,
        'description' => 'Filters the array of comments for the current post'
    ],
    'comments_open' => [
        'order' => 119,
        'context' => 'public',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 2,
        'description' => 'Filters whether the current post is open for comments'
    ],
    'pings_open' => [
        'order' => 120,
        'context' => 'public',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 2,
        'description' => 'Filters whether the current post is open for pings'
    ],
    'authenticate' => [
        'order' => 200,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 3,
        'description' => 'Filters whether a set of user login credentials are valid'
    ],
    'login_form' => [
        'order' => 201,
        'context' => 'public',
        'hook_type' => 'action',
        'user_logged' => 'logged-out',
        'args' => 0,
        'description' => 'Fires in the middle of the login form'
    ],
    'wp_login' => [
        'order' => 202,
        'context' => 'core',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 2,
        'description' => 'Fires after a user has successfully logged in'
    ],
    'wp_logout' => [
        'order' => 203,
        'context' => 'core',
        'hook_type' => 'action',
        'user_logged' => 'logged-out',
        'args' => 1,
        'description' => 'Fires after a user is logged out'
    ],
    'auth_cookie_expiration' => [
        'order' => 204,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 3,
        'description' => 'Filters the duration of the authentication cookie expiration period'
    ],
    'activate_plugin' => [
        'order' => 300,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 2,
        'description' => 'Fires before a plugin is activated'
    ],
    'activated_plugin' => [
        'order' => 301,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 2,
        'description' => 'Fires after a plugin has been activated'
    ],
    'deactivate_plugin' => [
        'order' => 302,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 2,
        'description' => 'Fires before a plugin is deactivated'
    ],
    'deactivated_plugin' => [
        'order' => 303,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 2,
        'description' => 'Fires after a plugin has been deactivated'
    ],
    'switch_theme' => [
        'order' => 304,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 3,
        'description' => 'Fires after the theme is switched'
    ],
    'rest_authentication_errors' => [
        'order' => 401,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 1,
        'description' => 'Filters authentication errors encountered during REST API requests'
    ],
    'rest_endpoints' => [
        'order' => 402,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 1,
        'description' => 'Filters the available REST API endpoints'
    ],
    'wp_ajax_{action}' => [
        'order' => 500,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 0,
        'description' => 'Dynamic action fired during AJAX requests from logged-in users'
    ],
    'wp_ajax_nopriv_{action}' => [
        'order' => 501,
        'context' => 'public',
        'hook_type' => 'action',
        'user_logged' => 'logged-out',
        'args' => 0,
        'description' => 'Dynamic action fired during AJAX requests from non-logged-in users'
    ],
    'admin_post_{action}' => [
        'order' => 502,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 0,
        'description' => 'Dynamic action fired on an authenticated admin post request for the given action'
    ],
    'admin_post_nopriv_{action}' => [
        'order' => 503,
        'context' => 'public',
        'hook_type' => 'action',
        'user_logged' => 'logged-out',
        'args' => 0,
        'description' => 'Dynamic action fired on a non-authenticated admin post request for the given action'
    ],
    'comment_form' => [
        'order' => 600,
        'context' => 'public',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 1,
        'description' => 'Fires at the bottom of the comment form'
    ],
    'pre_comment_approved' => [
        'order' => 601,
        'context' => 'public',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 2,
        'description' => 'Filters whether a comment should be approved'
    ],
    'comment_post' => [
        'order' => 602,
        'context' => 'public',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 3,
        'description' => 'Fires after a comment is saved in the database'
    ],
    'upload_mimes' => [
        'order' => 700,
        'context' => 'admin',
        'hook_type' => 'filter',
        'user_logged' => 'logged-in',
        'args' => 2,
        'description' => 'Filters the list of allowed mime types and file extensions'
    ],
    'wp_handle_upload' => [
        'order' => 701,
        'context' => 'admin',
        'hook_type' => 'filter',
        'user_logged' => 'logged-in',
        'args' => 2,
        'description' => 'Filters the data for a file after it has been uploaded'
    ],
    'add_attachment' => [
        'order' => 702,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 1,
        'description' => 'Fires once an attachment has been added'
    ],
    'sanitize_file_name' => [
        'order' => 703,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 1,
        'description' => 'Filters the filename of the uploaded file'
    ],
    'cron_schedules' => [
        'order' => 800,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 1,
        'description' => 'Filters the list of scheduled cron events'
    ],
    'query' => [
        'order' => 900,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 1,
        'description' => 'Filters the database query'
    ],
    'posts_where' => [
        'order' => 901,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 2,
        'description' => 'Filters the WHERE clause of the query'
    ],
    'posts_join' => [
        'order' => 902,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 2,
        'description' => 'Filters the JOIN clause of the query'
    ],
    'posts_orderby' => [
        'order' => 903,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 2,
        'description' => 'Filters the ORDER BY clause of the query'
    ],
    'user_register' => [
        'order' => 1000,
        'context' => 'core',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 1,
        'description' => 'Fires immediately after a new user is registered'
    ],
    'profile_update' => [
        'order' => 1001,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 2,
        'description' => 'Fires immediately after a user is updated'
    ],
    'delete_user' => [
        'order' => 1002,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 2,
        'description' => 'Fires immediately before a user is deleted'
    ],
    'wp_nav_menu_args' => [
        'order' => 1100,
        'context' => 'public',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 1,
        'description' => 'Filters the arguments used to display a navigation menu'
    ],
    'wp_nav_menu_items' => [
        'order' => 1101,
        'context' => 'public',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 2,
        'description' => 'Filters the HTML list content for navigation menus'
    ],
    'wp_update_nav_menu' => [
        'order' => 1102,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 2,
        'description' => 'Fires after a navigation menu has been successfully updated'
    ],
    'customize_register' => [
        'order' => 1200,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 1,
        'description' => 'Fires once the Customizer has been registered'
    ],
    'customize_preview_init' => [
        'order' => 1201,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 1,
        'description' => 'Fires once the Customizer preview has initialized'
    ],
    'post_link' => [
        'order' => 1300,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 3,
        'description' => 'Filters the permalink for a post'
    ],
    'page_link' => [
        'order' => 1301,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 3,
        'description' => 'Filters the permalink for a page'
    ],
    'term_link' => [
        'order' => 1302,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 3,
        'description' => 'Filters the permalink for a term'
    ],
    'post_type_link' => [
        'order' => 1303,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 4,
        'description' => 'Filters the permalink for a post of a custom post type.'
    ],
    // Added archive-related link filters
    'get_post_type_archive_link' => [
        'order' => 1304,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 2,
        'description' => 'Filters the archive URL for a post type before it is returned.'
    ],
    'post_type_archive_link' => [
        'order' => 1305,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 2,
        'description' => 'Filters the archive URL for a post type after it is generated.'
    ],
    'post_type_archive_feed_link' => [
        'order' => 1306,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 2,
        'description' => 'Filters the feed link for a post type archive.'
    ],
    'term_feed_link' => [
        'order' => 1307,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 2,
        'description' => 'Filters the feed link for a taxonomy term.'
    ],
    'wp_editor_settings' => [
        'order' => 1400,
        'context' => 'admin',
        'hook_type' => 'filter',
        'user_logged' => 'logged-in',
        'args' => 2,
        'description' => 'Filters the settings for the WordPress editor'
    ],
    'tiny_mce_before_init' => [
        'order' => 1401,
        'context' => 'admin',
        'hook_type' => 'filter',
        'user_logged' => 'logged-in',
        'args' => 2,
        'description' => 'Filters the TinyMCE settings before init'
    ],
    'should_load_remote_block_patterns' => [
        'order' => 1402,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 1,
        'description' => 'Filters whether WordPress should fetch and register remote block patterns.'
    ],
    'heartbeat_received' => [
        'order' => 1500,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 3,
        'description' => 'Filters the Heartbeat response received'
    ],
    'heartbeat_send' => [
        'order' => 1501,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 3,
        'description' => 'Filters the Heartbeat response sent'
    ],
    'enqueue_block_editor_assets' => [
        'order' => 1600,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 0,
        'description' => 'Fires when scripts and styles are enqueued for the block editor'
    ],
    'block_categories' => [
        'order' => 1601,
        'context' => 'admin',
        'hook_type' => 'filter',
        'user_logged' => 'logged-in',
        'args' => 2,
        'description' => 'Filters the default block categories'
    ],
	'render_block_data' => [
		'order' => 1610,
		'context' => 'core',
		'hook_type' => 'filter',
		'user_logged' => 'both',
		'args' => 2,
		'description' => 'Filters the parsed block data before it is rendered'
	],
    'render_block' => [
        'order' => 1611,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 2,
        'description' => 'Filters the output of a registered block'
    ],
    'site_status_tests' => [
        'order' => 1700,
        'context' => 'admin',
        'hook_type' => 'filter',
        'user_logged' => 'logged-in',
        'args' => 1,
        'description' => 'Filters the site health tests'
    ],
    'wp_privacy_personal_data_exporters' => [
        'order' => 1800,
        'context' => 'admin',
        'hook_type' => 'filter',
        'user_logged' => 'logged-in',
        'args' => 1,
        'description' => 'Filters the list of registered personal data exporters'
    ],
    'wp_privacy_personal_data_erasers' => [
        'order' => 1801,
        'context' => 'admin',
        'hook_type' => 'filter',
        'user_logged' => 'logged-in',
        'args' => 1,
        'description' => 'Filters the list of registered personal data erasers'
    ],
    'wpmu_new_blog' => [
        'order' => 1900,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 6,
        'description' => 'Fires when a new site is created in a multisite network'
    ],
    'wpmu_delete_blog' => [
        'order' => 1901,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 2,
        'description' => 'Fires before a site is deleted in a multisite network'
    ],
    'upgrader_process_complete' => [
        'order' => 2000,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 2,
        'description' => 'Fires when the upgrader process is complete'
    ],
    'update_option_{$option}' => [
        'order' => 2001.1,
        'context' => 'core',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 3,
        'description' => 'Dynamic action fired after a specific option has been updated'
    ],
    'generate_rewrite_rules' => [
        'order' => 2100,
        'context' => 'core',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 1,
        'description' => 'Fires when rewrite rules are generated'
    ],
    'redirect_canonical' => [
        'order' => 2101,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 2,
        'description' => 'Filters the canonical redirect URL.'
    ],
    'rewrite_rules_array' => [
        'order' => 2102,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 1,
        'description' => 'Filters the list of rewrite rules'
    ],
    'http_request_args' => [
        'order' => 2200,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 2,
        'description' => 'Filters the arguments used in an HTTP request'
    ],
    'http_response' => [
        'order' => 2201,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 3,
        'description' => 'Filters the HTTP response'
    ],
    'shortcode_atts' => [
        'order' => 2300,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 4,
        'description' => 'Filters shortcode attributes'
    ],
	'do_shortcode_tag' => [
		'order' => 2301,
		'context' => 'core',
		'hook_type' => 'filter',
		'user_logged' => 'both',
		'args' => 4,
		'description' => 'Filters the output created by a shortcode callback'
	],
    'embed_oembed_html' => [
        'order' => 2400,
        'context' => 'public',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 4,
        'description' => 'Filters the HTML output of the oEmbed provider'
    ],
    'oembed_result' => [
        'order' => 2401,
        'context' => 'public',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 3,
        'description' => 'Filters the oEmbed result'
    ],
    'embed_oembed_discover' => [
        'order' => 2402,
        'context' => 'public',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 2,
        'description' => 'Filters whether to enable oEmbed discovery'
    ],
    'gettext' => [
        'order' => 2500,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 3,
        'description' => 'Filters translations of text'
    ],
    'locale' => [
        'order' => 2501,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 1,
        'description' => 'Filters the locale for the current user'
    ],
    'wp_cache_get' => [
        'order' => 2600,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 5,
        'description' => 'Filters the value retrieved from the cache'
    ],
    'wp_cache_set' => [
        'order' => 2601,
        'context' => 'core',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 4,
        'description' => 'Fires after data is added to the cache'
    ],
    'pre_transient_{transient}' => [
        'order' => 2700,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 1,
        'description' => 'Dynamic filter fired before retrieving a specific transient'
    ],
    'set_transient_{transient}' => [
        'order' => 2701,
        'context' => 'core',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 3,
        'description' => 'Dynamic action fired after setting a specific transient'
    ],
    'add_post_metadata' => [
        'order' => 2800,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 5,
        'description' => 'Filters whether to add metadata of a specific type'
    ],
    'update_post_metadata' => [
        'order' => 2801,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 5,
        'description' => 'Filters whether to update metadata of a specific type'
    ],
    'delete_post_metadata' => [
        'order' => 2802,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 5,
        'description' => 'Filters whether to delete metadata of a specific type'
    ],
    'get_post_metadata' => [
        'order' => 2803,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 4,
        'description' => 'Filters the value of an existing meta key'
    ],
    'get_term_metadata' => [
        'order' => 2804,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 4,
        'description' => 'Filters the value of metadata for a term before it is returned.'
    ],
    'pre_term_name' => [
        'order' => 2900,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 2,
        'description' => 'Filters the term name before it is sanitized'
    ],
    'pre_term_slug' => [
        'order' => 2901,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 3,
        'description' => 'Filters the term slug before it is sanitized'
    ],
    'wp_terms_checklist_args' => [
        'order' => 2902,
        'context' => 'admin',
        'hook_type' => 'filter',
        'user_logged' => 'logged-in',
        'args' => 2,
        'description' => 'Filters the arguments for the terms checklist'
    ],
    'created_category' => [
        'order' => 2903,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 2,
        'description' => 'Fires after a category is created or updated'
    ],
    'edited_category' => [
        'order' => 2904,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 2,
        'description' => 'Fires after a category is edited'
    ],
    'deleted_category' => [
        'order' => 2905,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 1,
        'description' => 'Fires after a category is deleted'
    ],
    'created_post_tag' => [
        'order' => 2906,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 2,
        'description' => 'Fires after a post tag is created'
    ],
    'edited_post_tag' => [
        'order' => 2907,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 2,
        'description' => 'Fires after a post tag is edited'
    ],
    'deleted_post_tag' => [
        'order' => 2908,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 1,
        'description' => 'Fires after a post tag is deleted'
    ],
    'edited_term' => [
        'order' => 2909,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 3,
        'description' => 'Fires after a term is edited'
    ],
    'register_taxonomy_args' => [
        'order' => 3000,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 3,
        'description' => 'Filters the arguments for registering a taxonomy'
    ],
    'register_post_type_args' => [
        'order' => 3001,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 2,
        'description' => 'Filters the arguments for registering a post type'
    ],
    'show_admin_bar' => [
        'order' => 3100,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 1,
        'description' => 'Filters whether to show the admin bar'
    ],
    'save_post' => [
        'order' => 3200,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 3,
        'description' => 'Fires once a post has been saved'
    ],
    'save_post_{post_type}' => [
        'order' => 3201,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 3,
        'description' => 'Dynamic action fired after a specific post type is saved'
    ],
	'wp_after_insert_post' => [
		'order' => 3202,
		'context' => 'core',
		'hook_type' => 'action',
		'user_logged' => 'both',
		'args' => 4,
		'description' => 'Fires once a post, its terms, and meta data have been saved'
	],
];

// Return the array directly using the correct name
return $wordpress_hooks_master_sequence;

/**
 * Research sources used for determining the correct WordPress hook execution order:
 *
 * 1. RachieVee's empirical testing with Debug Bar Plugin on WordPress 4.2.2
 *    https://rachievee.com/the-wordpress-hooks-firing-sequence/
 *
 * 2. wp-kama.com's comprehensive hooks order documentation
 *    https://wp-kama.com/hooks/actions-order
 *
 * 3. Official WordPress Developer documentation on action reference
 *    https://developer.wordpress.org/apis/hooks/action-reference/
 *
 * 4. WordPress StackExchange real-world testing data
 *    https://wordpress.stackexchange.com/questions/162862/how-to-get-wordpress-hooks-actions-run-sequence
 *
 * 5. Phil Kurth's WordPress core hooks execution order reference
 *    https://philkurth.com.au/wordpress-core-hooks-execution-order/
 */
