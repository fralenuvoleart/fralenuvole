<?php
/**
 * Fralenuvole Configuration Bootstrap
 *
 * Source of truth for mandatory plugin constants.
 * These constants are required to load the rest of the plugin and initialize core services regardless of the entry point.
 *
 * @package Fralenuvole
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core Path & Identity Constants
 */
const FRL_PREFIX      = 'frl';
const FRL_NAME        = 'fralenuvole';
const FRL_PLUGIN_FILE = FRL_NAME . '.php';

if ( ! defined( 'FRL_DIR_PATH' ) ) {
	define( 'FRL_DIR_PATH', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'FRL_DIR_URL' ) ) {
	define( 'FRL_DIR_URL', plugin_dir_url( __DIR__ ) );
}

if ( ! defined( 'FRL_PLUGIN_ADMIN_URL' ) ) {
	define( 'FRL_PLUGIN_ADMIN_URL', get_admin_url() . 'admin.php?page=' . FRL_NAME );
}

const FRL_MODULES_SECTION = 'modules';
if ( ! defined( 'FRL_MODULES_DIR_PATH' ) ) {
	define( 'FRL_MODULES_DIR_PATH', FRL_DIR_PATH . FRL_MODULES_SECTION . '/' );
}

const FRL_PLUGIN_SUPERADMIN_ID = 1;
const FRL_PLUGIN_ACCESS        = 'delete_plugins';


// Default team-member fallback for blog author and editor
const FRL_DEFAULT_AUTHOR_CPT_ID = 18765;
const FRL_DEFAULT_EDITOR_CPT_ID = 18765;

// Hooks context map
const FRL_ADMINBAR_CPT_LIST = array(
	'page'  => array(
		'title'  => 'All Pages',
		'href'   => '/wp-admin/edit.php?post_type=page',
		'access' => 'publish_pages',
	),
	'post'  => array(
		'title'  => 'All Posts',
		'href'   => '/wp-admin/edit.php',
		'access' => 'edit_posts',
	),
	'media' => array(
		'title'  => 'All Media',
		'href'   => '/wp-admin/upload.php',
		'access' => 'upload_files',
	),
);

// Directory containing custom SVG flag files for the language switcher. `{slug}.svg` (e.g., en.svg, ru.svg).
const FRL_LANGSWITCHER_FLAGS_DIR = __DIR__ . '/../assets/images/flags';

// Arguments for default langs switcher
const FRL_LANGSWITCHER_ARGS = array(
	'dropdown'               => 0,       // 0 = flags, 1 = dropdown
	'display_names_as'       => 'slug',  // Dropdown only: option text: 'name' or 'slug'
	'hide_current'           => 0,       // Flag only: default 0
	'hide_if_no_translation' => 0,       // Both: default 0
	'hide_languages'         => '',      // Both: comma-separated list of lang slugs
);

// Email notifications
const FRL_EMAIL_NOTIFICATIONS = array(
	'rate_key'      => 'email_rate_limit',
	'rate_limit'    => 5,
	'rate_interval' => MINUTE_IN_SECONDS,
	'to'            => 'francesco.csto@gmail.com',
);

// Async webhook dispatch: bounded retry-with-backoff on failure
const FRL_WEBHOOK_RETRY = array(
	'max_attempts' => 3,
	'delays'       => array( 60, 300, 900 ), // 1 min, 5 min, 15 min
);

// Strings to ignore for debug log count bubble
const FRL_LOG_COUNT_IGNORE = array(
	'Automatic updates',
);

// List of actions that can be executed without a nonce check if the user is logged in
const FRL_PUBLIC_ACTIONS = array(
	'clear_website_transients',
);

/**
 * REST API endpoints disabled for unauthenticated users.
 *
 * /oembed/1.0 and /wp/v2/oembed are intentionally excluded. The frl_disable_oembed() function already removes oEmbed when disable_oembed is enabled.
 *
 * @see includes/main/website.php:124
 * @see public/public.php:frl_disable_rest_endpoints()
 */
const FRL_REST_ENDPOINTS = array(
	'/wp/v2/users',
	'/wp/v2/settings',
	'/wp/v2/themes',
	'/wp/v2/plugins',
	'/wp/v2/types',
	'/wp/v2/statuses',
	'/wp/v2/taxonomies',
	'/wp/v2/categories',
	'/wp/v2/tags',
	'/wp/v2/media',
	'/wp/v2/comments',
);

// Used only when image_preload_featured_responsive is on. Size fixed by breakpoint/DPR math; override via frl_hero_mobile_image_size filter.
const FRL_PRELOAD_IMAGE_MOBILE_SIZE = '1536x1536';

// Used only when image_preload_featured_responsive is on. Post types getting the mobile-hero override; override via frl_hero_mobile_post_types filter.
const FRL_PRELOAD_IMAGE_MOBILE_POST_TYPES = array( 'home', 'service' );

// Next-gen format variants tried (in order) for preloaded featured images, before falling back to the original file.
const FRL_PRELOAD_IMAGE_EXT_CANDIDATES = array( '.avif', '.webp' );
