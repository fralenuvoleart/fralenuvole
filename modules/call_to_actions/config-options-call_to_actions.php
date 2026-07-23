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
	// Add a section title to the modules tab
	'section_title_cta' => array(
		'label'       => 'Call to Actions Module',
		'type'        => 'section_title',
		'description' => 'WhatsApp, Telegram, and Email CTA click tracking + webhooks',
	),
	'cta_webhook'       => array(
		'label'             => 'Enable CTA Webhooks',
		'description'       => 'Fire marketing webhooks on CTA clicks (WhatsApp, Telegram, Email)',
		'type'              => 'checkbox',
		'default'           => 1,
		'sanitize_callback' => 'absint',
		'restricted'        => true,
	),
	'cta_use_cron'      => array(
		'label'             => 'Use Cron for CTA Webhooks',
		'description'       => 'Send CTA webhooks via WP-Cron (async). Disable for sync dispatch.',
		'type'              => 'checkbox',
		'default'           => 0,
		'sanitize_callback' => 'absint',
	),
);
