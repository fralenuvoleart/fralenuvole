<?php

/**
 * Third-Party module settings
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$frl_thirdparty_default_fields = array(
	'section_title_thirdparty'     => array(
		'label'       => 'Third-Party Module',
		'type'        => 'section_title',
		'description' => 'Settings for third-party module integrations',
	),
	'thirdparty_schema_properties' => array(
		'label'             => 'Add Schema Properties',
		'description'       => 'Injects address, publisher, and other properties into SASWP Schema JSON-LD.',
		'type'              => 'checkbox',
		'default'           => 1,
		'sanitize_callback' => 'absint',
	),
);
