<?php
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Field and Options Constants
 */

// Allowed field types
const FRL_FIELD_TYPES = array(
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
	'description',
);

// Field attributes with default values
const FRL_FIELD_ATTRIBUTES = array(
	'section'     => '__COMPUTED__',  // Auto-generated: section id (from loop)
	'id'          => '__COMPUTED__',  // Auto-generated: field id (from loop)
	'type'        => 'text',          // field type (from field data)
	'restricted'  => false,           // field restricted
	'autoload'    => 'yes',           // field autoload
	'rows'        => 5,               // textarea rows
	'label'       => '',              // Field label (optional)
	'default'     => '',              // field default value
	'description' => '',              // field description
	'placeholder' => '',              // field placeholder
	'callback'    => '',              // field callback
	'classes'     => '',              // field classes
	'save_option' => '',              // field save option
	'level'       => '',              // field level
);

// Field formatters
const FRL_FIELD_FORMATTERS = array(
	'divider',
	'heading',
	'section_title',
	'description',
);
