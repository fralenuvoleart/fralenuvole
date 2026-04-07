<?php

// Exit if accessed directly
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Plugin constants
 */

// Define the plugin application URLs
define('FRL_PLUGIN_URL', get_admin_url() . 'admin.php?page=' . FRL_NAME);

// Define the plugin modules directory path
define('FRL_MODULES_DIR_PATH', FRL_DIR_PATH . 'modules/');

// Define the plugin access level
const FRL_PLUGIN_ACCESS = 'delete_plugins';

// Strings to ignore for debug log count bubble
const FRL_LOG_COUNT_IGNORE = [
	'Automatic updates',
];

// Runtime plugin options
const FRL_OPTIONS_RUNTIME = [
	'environment_state' => [
		'type' => 'text',
		'default' => ''
	],
	'environment_ignore_plugins' => [
		'type' => 'text',
		'default' => ''
	],
	'environment_ignore_options' => [
		'type' => 'text',
		'default' => ''
	],
	'translation_version' => [
		'type' => 'text',
		'default' => 1
	],
	'translate_cpt_slugs_service' => [
		'type' => 'textlist',
		'default' => ''
	],
	'plugin_version' => [
		'type' => 'text',
		'default' => '0.0.0'
	],
];

// Email notifications
const FRL_EMAIL_NOTIFICATIONS = [
	'rate_key' => 'email_rate_limit',
	'rate_limit' => 5,
	'rate_interval' => MINUTE_IN_SECONDS,
	'to' => 'francesco.csto@gmail.com',
];

// Define the PHP 8.0+ error code for errors suppressed with the @ operator.
const FRL_PHP8_SUPPRESSED_ERROR_CODE = 4437;

// Schema types
const FRL_SCHEMA_TYPES = [
	'person',
	'organization',
	'article',
	'service',
	'portfolio'
];

// Allowed field types
const FRL_FIELD_TYPES = [
	'custom',
	'text',
	'number',
	'checkbox',
	'radio',
	'textarea',
	'textlist',
	'select',
	'checkboxes',
	'wysiwyg',
	'html',
	// Add formatting-only field types
	'divider',
	'heading',
	'section_title',
	'description'
];

// Field attributes with default values
const FRL_FIELD_ATTRIBUTES = [
	'section' 		=> '__COMPUTED__',	// Auto-generated: section id (from loop)
	'id' 			=> '__COMPUTED__',	// Auto-generated: field id (from loop)
	'type' 			=> 'text',			// field type (from field data)
	'restricted'	=> false,			// field restricted
	'autoload' 		=> 'yes',			// field autoload
	'rows' 			=> 5,				// textarea rows
	'label' 		=> '',				// Field label (optional)
	'default' 		=> '',				// field default value
	'description' 	=> '',				// field description
	'placeholder'	=> '',				// field placeholder
	'callback' 		=> '',				// field callback
	'classes' 		=> '',				// field classes
	'save_option' 	=> '',				// field save option
	'level' 		=> '',				// field level
];

// Field formatters
const FRL_FIELD_FORMATTERS = [
	'divider',
	'heading',
	'section_title',
	'description'
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

// List of actions that can be executed without a nonce check if the user is logged in
const FRL_PUBLIC_ACTIONS = [
    'clear_website_transients',
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
	// 'raw' 				=> 0,  		// default 0, returns array if 1
	// 'post_id' 			=> null, 	// default current post ID
];
