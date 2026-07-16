<?php
/**
 * Call-to-Actions Module — Constants
 *
 * Unified per-environment CTA webhook config matching wsform's
 * WSFORM_ALL_WEBHOOKS_CONFIG pattern.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Post meta key for service resolution from the current page
const CTA_SERVICE_META = 'service-settings_service-type';

// Per-environment CTA definitions. Each env has a shared webhook_url + use_cron
// and a list of action entries. Each action produces a [data-action="{action_id}"]
// click handler. {reference_id} and {template} placeholders replaced at click time.
const CTA_WEBHOOK_CONFIG = array(
	'default' => array(),
	'pbs'     => array(
		'use_cron'    => false,
		'webhook_url' => 'https://webhooks.integrately.com/a/webhooks/171f3cf7dd074bc08c0ad004a245c5d7',
		'actions'     => array(
			array(
				'action_id'    => 'whatsapp',
				'url'          => 'https://wa.me/995522220776?text={template}',
				'template'     => "Hello,\r\nI'd like to enquire about your services.\r\n\r\n\r\n---\r\nSupport number: PIN-{reference_id}-PBS\r\n(Please don't delete your support number)",
				'send_webhook' => true,
			),
			array(
				'action_id'    => 'telegram',
				'url'          => 'https://t.me/PBSERVICES_bot?start={template}',
				'template'     => '{reference_id}',
				'send_webhook' => true,
			),
			array(
				'action_id'    => 'email',
				'url'          => 'mailto:info@pbservices.ge?subject={subject}&body={template}',
				'subject'      => 'PB Services Enquiry',
				'template'     => "Hello,\r\nI'd like to enquire about your services.\r\n\r\n\r\n---\r\nSupport number: PIN-{reference_id}-PBS\r\n(Please don't delete your support number)",
				'send_webhook' => true,
			),
		),
	),
);

// Webhook field → POST key mapping. Sentinels (prefixed __) resolved in handler.
const CTA_WEBHOOK_FIELDS = array(
	'Reference ID'     => 'reference_id',
	'CTA'              => '__action_id__',
	'Service'          => '__service__',
	'Language'         => 'language',
	'Referer'          => '__referer__',
	'User IP'          => '__remote_addr__',
	'Page URL'         => '__page_url__',
	'Channel Source'   => 'source',
	'Channel Medium'   => 'medium',
	'Channel Campaign' => 'campaign',
	'Channel Term'     => 'term',
	'Channel Content'  => 'content',
	'Channel GCLID'    => 'gclid',
	'Channel FBCLID'   => 'fbclid',
	'Channel Landing'  => 'landing',
);

// CTA webhook per-IP rate limiting.
const CTA_WEBHOOK_RATE_LIMIT  = 30;
const CTA_WEBHOOK_RATE_WINDOW = 60;
