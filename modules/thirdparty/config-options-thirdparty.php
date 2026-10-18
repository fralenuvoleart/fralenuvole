<?php

/**
 * Third-Party module settings
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
$frl_thirdparty_default_fields = array(
	// Add a section title to the modules tab
	'thirdparty_section_title' => array(
		'label'       => 'Third-Party Module',
		'type'        => 'section_title',
		'description' => 'Third-Party module settings',
	),
	'thirdparty_cache_warmer'     => array(
		'label'             => 'Warm cache after full purge',
		'description'       => 'Schedule the external cache warmer 10 minutes after a full cache purge.',
		'type'              => 'checkbox',
		'default'           => 0,
		'sanitize_callback' => 'absint',
		'autoload'          => 'no',
	),
	'thirdparty_cache_warmer_url' => array(
		'label'             => 'Cache Warmer URL',
		'description'       => 'External cache warmer endpoint. If empty, the "Warm Server Cache" admin bar action is hidden.',
		'type'              => 'text',
		'default'           => '',
		'sanitize_callback' => 'esc_url_raw',
		'autoload'          => 'no',
	),
);
