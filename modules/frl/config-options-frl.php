<?php

/**
 * FRL module settings
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Add plugin options to the modules tab
 *
 * @param array $fields Existing settings fields
 * @return array Modified settings fields
 */
$frl_frl_default_fields = array(
	// Add a section title to the modules tab
	'section_title_frl' => array(
		'label'       => 'FRL Module',
		'type'        => 'section_title',
		'description' => 'FRL module settings',
	),
	'frl_bible_api_key' => array(
		'label'             => 'Bible API Key',
		'description'       => 'Insert your Bible API Key',
		'type'              => 'text',
		'default'           => '',
		'sanitize_callback' => 'sanitize_text_field',
		'restricted'        => true,
	),
);
