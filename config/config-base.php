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
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Core Path & Identity Constants
 */
const FRL_PREFIX = 'frl';
const FRL_NAME = 'fralenuvole';
const FRL_PLUGIN_FILE = FRL_NAME . '.php';

if (!defined('FRL_DIR_PATH')) {
	define('FRL_DIR_PATH', dirname(__DIR__) . '/');
}

if (!defined('FRL_DIR_URL')) {
	define('FRL_DIR_URL', plugin_dir_url(dirname(__FILE__)));
}

if (!defined('FRL_PLUGIN_ADMIN_URL')) {
	define('FRL_PLUGIN_ADMIN_URL', get_admin_url() . 'admin.php?page=' . FRL_NAME);
}

const FRL_MODULES_SECTION = 'modules';
if (!defined('FRL_MODULES_DIR_PATH')) {
	define('FRL_MODULES_DIR_PATH', FRL_DIR_PATH . FRL_MODULES_SECTION . '/');
}

const FRL_PLUGIN_SUPERADMIN_ID = 1;
const FRL_PLUGIN_ACCESS = 'delete_plugins';


// Default team-member fallback for blog author and editor
const FRL_DEFAULT_AUTHOR_CPT_ID = 18765;
const FRL_DEFAULT_EDITOR_CPT_ID = 18765;

// Schema translation: value prefixes to exclude from translation
const FRL_SCHEMA_EXCLUDE_TRANSLATIONS = [
	'@', 
	'_',
	'knowsAbout',
];

// Hooks context map
const FRL_AB_CPT_LIST = [
	'page' 		=> [
		'title' 	=> 'All Pages',
		'href' 		=> '/wp-admin/edit.php?post_type=page',
		'access' 	=> 'publish_pages',
	],
	'post' 		=> [
		'title' 	=> 'All Posts',
		'href' 		=> '/wp-admin/edit.php',
		'access' 	=> 'edit_posts',
	],
	'media' 	=> [
		'title' 	=> 'All Media',
		'href' 		=> '/wp-admin/upload.php',
		'access' 	=> 'upload_files',
	],
];

// Arguments for default flags langswitcher
const FRL_LANGSWITCHER_ARGS = [
	'dropdown' 				=> 0,  		// default 0
	'show_flags' 			=> 1,		// default 0, depenndency on dropdown
	'show_names' 			=> 0,		// default 1, depenndency on dropdown
	'display_names_as' 		=> 'slug', 	// default name, depenndency on dropdown
	'echo' 					=> 0,  		// default 1
	// configurable arguments
	'hide_current' 			=> 0,  		// default 0, hide current language
	'hide_if_no_translation'=> 0,  		// default 0, hide if no translation
	'hide_languages' 		=> '', 		// comma-separated list of lang slugs
];

// Email notifications
const FRL_EMAIL_NOTIFICATIONS = [
	'rate_key' => 'email_rate_limit',
	'rate_limit' => 5,
	'rate_interval' => MINUTE_IN_SECONDS,
	'to' => 'francesco.csto@gmail.com',
];

// Strings to ignore for debug log count bubble
const FRL_LOG_COUNT_IGNORE = [
	'Automatic updates',
];

// List of actions that can be executed without a nonce check if the user is logged in
const FRL_PUBLIC_ACTIONS = [
    'clear_website_transients',
];

// PHP 8.0+ error code for errors suppressed with the @ operator.
const FRL_PHP8_SUPPRESSED_ERROR_CODE = 4437;