<?php
/**
 * Call-to-Actions Module — Options
 *
 * Layer 2 (Link processing): per-channel enable/disable.
 * Layer 3 (Webhooks): global webhook dispatch toggle.
 * Layer 4 (Async): sync vs WP-Cron dispatch.
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
	'cta_section_title'    => array(
		'label'       => 'Call to Actions Module',
		'type'        => 'section_title',
		'description' => 'WhatsApp, Telegram, and Email CTA click handling',
	),
	// Layer 2 — Per-channel toggles
	'cta_whatsapp_enabled' => array(
		'label'             => 'WhatsApp CTA',
		'description'       => 'Enable WhatsApp click-to-chat custom link',
		'type'              => 'checkbox',
		'default'           => 1,
		'sanitize_callback' => 'absint',
		'restricted'        => true,
	),
	'cta_telegram_enabled' => array(
		'label'             => 'Telegram CTA',
		'description'       => 'Enable Telegram click-to-chat custom link',
		'type'              => 'checkbox',
		'default'           => 1,
		'sanitize_callback' => 'absint',
		'restricted'        => true,
	),
	'cta_email_enabled'    => array(
		'label'             => 'Email CTA',
		'description'       => 'Enable mailto click-to-email custom link',
		'type'              => 'checkbox',
		'default'           => 1,
		'sanitize_callback' => 'absint',
		'restricted'        => true,
	),
	// Layer 3 — Webhook dispatch
	'cta_webhook'          => array(
		'label'             => 'Fire webhook on CTA click',
		'description'       => 'Send marketing webhook when a CTA is clicked',
		'type'              => 'checkbox',
		'default'           => 0,
		'sanitize_callback' => 'absint',
		'restricted'        => true,
	),
	// Layer 4 — Async dispatch
	'cta_use_cron'         => array(
		'label'             => 'Use Cron for webhooks',
		'description'       => 'Send webhooks via WP-Cron (async). Disable for sync dispatch.',
		'type'              => 'checkbox',
		'default'           => 0,
		'sanitize_callback' => 'absint',
		'restricted'        => true,
	),
);
