<?php
/**
 * Call-to-Actions Module — Options
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Add plugin options to the modules tab.
 *
 * @param array $fields Existing settings fields.
 * @return array Modified settings fields.
 */
$frl_call_to_actions_default_fields = array(
	'section_title_cta'      => array(
		'label'       => 'Call to Actions Module',
		'type'        => 'section_title',
		'description' => 'WhatsApp, Telegram, and Email CTA click tracking + webhooks',
	),
	'module_call_to_actions' => array(
		'label'             => 'Enable Call-to-Actions Module',
		'description'       => 'Enable CTA click handling (WhatsApp, Telegram, Email deep links) and marketing webhook dispatch',
		'type'              => 'checkbox',
		'default'           => 0,
		'sanitize_callback' => 'absint',
		'restricted'        => true,
	),
);
