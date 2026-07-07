<?php

/**
 * WS Form module settings
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
$frl_wsform_default_fields = array(
	// Add a section title to the modules tab
	'section_title_wsform'    => array(
		'label'       => 'WS Form Module',
		'type'        => 'section_title',
		'description' => 'WS Form module settings',
	),
	'wsform_webhook'          => array(
		'label'             => 'Enable WS Form Webhook',
		'description'       => 'Enable WS Form Webhook to send form data to your server',
		'type'              => 'checkbox',
		'default'           => 1,
		'sanitize_callback' => 'absint',
		'restricted'        => true,
	),
	'wsform_dash_widget'      => array(
		'label'             => 'Enable WS Form Widget',
		'description'       => 'Enable WS Form Submissions widget in dashboard',
		'type'              => 'checkbox',
		'default'           => 0,
		'sanitize_callback' => 'absint',
	),
	'wsform_channel_tracking' => array(
		'label'             => 'Enable Channel Tracking',
		'description'       => 'Enable Attribution Channel Tracking for WS Forms',
		'type'              => 'checkbox',
		'default'           => 1,
		'sanitize_callback' => 'absint',
		'restricted'        => true,
	),
);
