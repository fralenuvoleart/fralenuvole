<?php

/**
 * WS Form module settings
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WS Form Attribution Tracking Configuration
 */
const WS_ATTR_PREFIX        = 'channel';
const WS_ATTR_COOKIE_DAYS   = 90;
const WS_ATTR_COOKIE_PATH   = '/';
const WS_ATTR_COOKIE_DOMAIN = null;

// Reference ID generation settings
const WS_ATTR_REFERENCE_ID_LENGTH = 8;  // Length of generated reference ID (e.g., PIN-XXXXXXXXXXXX-PBS)

// Stats widgets: integer IDs get a per-form widget, 'all' adds a combined widget.
// Examples: [10, 9, 'all']  |  ['all']  |  [10]  |  []
const WS_STATS_FORM_IDS = array( 'all' );

// Use this for specific field name overrides if they don't follow the {prefix}_key pattern
const WS_ATTR_FIELD_MAPPING = array();

// Keys to populate from cookies into WS Form fields
const WS_ATTR_KEYS = array(
	'source',
	'medium',
	'campaign',
	'term',
	'content',
	'gclid',
	'fbclid',
	'landing',
	'reference_id',
);

// Post meta key used to resolve Service for button-click webhooks (from ACF field on the page)
const WS_BUTTON_WEBHOOK_SERVICE_META = 'service-settings_service-type';

// Chat button configuration (use %s as reference_id placeholder)
// The selector is derived as [data-action="{id}"] for each entry.
const WS_BUTTON_ACTIONS = array(
	array(
		'id'       => 'whatsapp',
		'url'      => 'https://wa.me/995522220776?text={template}',
		'template' => "Hello,\r\nI'd like to enquire about your services.\r\n\r\n\r\n---\r\nSupport number: PIN-{reference_id}-PBS\r\n(Please don't delete your support number)",
	),
	array(
		'id'       => 'telegram',
		'url'      => 'https://t.me/PBSERVICES_bot?start={template}',
		// Example:
		// 'template' => 'ID-{reference_id} {field-data-name:message}',
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
