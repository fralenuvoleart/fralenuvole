<?php
/**
 * Channel Tracking Configuration
 *
 * Cookie/attribution constants shared by wsform and call_to_actions modules.
 *
 * @package Fralenuvole
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const CT_ATTR_PREFIX              = 'channel';
const CT_ATTR_COOKIE_DAYS         = 90;
const CT_ATTR_COOKIE_PATH         = '/';
const CT_ATTR_COOKIE_DOMAIN       = null;
const CT_ATTR_REFERENCE_ID_LENGTH = 8;
const CT_ATTR_KEYS                = array(
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
