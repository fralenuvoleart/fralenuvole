<?php
/**
 * Call-to-Actions Module — Constants
 *
 * Per-environment webhook destinations, field mapping, and rate limits.
 * CTA templates (URL/template/subject) live in config/config-message-templates.php
 * (FRL_CTA_TEMPLATES) — shared with the wsform module. All behavioral toggles
 * (channel enable/disable, webhook dispatch, cron) live in admin options —
 * see config-options-call_to_actions.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Post meta key for service resolution from the current page
const CTA_SERVICE_META = 'service-settings_service-type';

// Per-environment CTA webhook destinations. The CTA templates themselves live
// in config/config-message-templates.php (FRL_CTA_TEMPLATES) — shared with the
// wsform module so neither module depends on the other.
const CTA_WEBHOOK_URLS = array(
	'default' => '',
	'pbs'     => 'https://webhooks.integrately.com/a/webhooks/171f3cf7dd074bc08c0ad004a245c5d7',
	'pbp'     => 'https://webhooks.integrately.com/a/webhooks/171f3cf7dd074bc08c0ad004a245c5d7',
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
