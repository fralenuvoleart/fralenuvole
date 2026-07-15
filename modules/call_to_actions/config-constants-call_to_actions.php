<?php
/**
 * Call-to-Actions Module — Constants
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Post meta key for service resolution from the current page
const CTA_SERVICE_META = 'service-settings_service-type';

// CTA action definitions. Each action produces a [data-action="{id}"] click handler.
// Use {reference_id} as a placeholder in templates; it is replaced at click time.
// Webhook URLs are NOT embedded here — they live in CTA_WEBHOOK_CONFIG below.
const CTA_ACTIONS = array(
	array(
		'id'       => 'whatsapp',
		'url'      => 'https://wa.me/995522220776?text={template}',
		'template' => "Hello,\r\nI'd like to enquire about your services.\r\n\r\n\r\n---\r\nSupport number: PIN-{reference_id}-PBS\r\n(Please don't delete your support number)",
	),
	array(
		'id'       => 'telegram',
		'url'      => 'https://t.me/PBSERVICES_bot?start={template}',
		'template' => '{reference_id}',
	),
	array(
		'id'       => 'email',
		'url'      => 'mailto:info@pbservices.ge?subject={subject}&body={template}',
		'subject'  => 'PB Services Enquiry',
		'template' => "Hello,\r\nI'd like to enquire about your services.\r\n\r\n\r\n---\r\nSupport number: PIN-{reference_id}-PBS\r\n(Please don't delete your support number)",
		'webhook'  => 'https://webhooks.integrately.com/a/webhooks/171f3cf7dd074bc08c0ad004a245c5d7',
	),
);

// Per-environment CTA webhook URLs. Flat action_id → URL mapping.
// Resolved via environment prefix at runtime.
const CTA_WEBHOOK_CONFIG = array(
	'default' => array(),
	'pbs'     => array(
		'email' => 'https://webhooks.integrately.com/a/webhooks/171f3cf7dd074bc08c0ad004a245c5d7',
	),
);

// Webhook field → POST key mapping. Mirrors wsform's fields_map pattern.
// Sentinels (prefixed __) are resolved specially in the handler:
//   __action_id__   → ucfirst($action_id)
//   __service__     → resolved from CTA_SERVICE_META post meta
//   __remote_addr__ → $_SERVER['REMOTE_ADDR']
//   __page_url__    → pre-resolved $page_url (sanitize_url)
//   __referer__     → sanitize_url (not sanitize_text_field)
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
