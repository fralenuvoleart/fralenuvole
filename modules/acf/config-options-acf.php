<?php

/**
 * ACF Custom Fields module settings
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
$frl_acf_default_fields = array(
	// Add a section title to the modules tab
	'section_title_acf' => array(
		'label'       => 'ACF Custom Fields Module',
		'type'        => 'section_title',
		'description' => 'ACF Custom Fields module settings',
	),
);
