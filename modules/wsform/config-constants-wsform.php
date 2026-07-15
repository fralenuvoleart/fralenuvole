<?php

/**
 * WS Form module settings
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Stats widgets: integer IDs get a per-form widget, 'all' adds a combined widget.
// Examples: [10, 9, 'all']  |  ['all']  |  [10]  |  []
const WSFORM_STATS_FORM_IDS = array( 'all' );

const WSFORM_ALL_WEBHOOKS_CONFIG = array(
	'default' => array(), // No webhooks for unknown domains
	// PBS Services
	'pbs'     => array(
		array(
			'form_id'     => 12, // Must match the WS Form ID exactly.
			'use_cron'    => true,
			'url'         => 'https://webhooks.integrately.com/a/webhooks/d3db87eb88ee48eeac177a49fc159070',
			'spam_filter' => array(
				'block_if_all_filled' => array(),
			),
			'fields_map'  => array(
				'Name'                 => 'field_225',
				'Email'                => 'field_226',
				'Phone'                => 'field_227',
				'Contact method'       => 'field_228',
				'Other contact method' => 'field_229',
				'Telegram ID'          => 'field_230',
				'Message'              => 'field_231',
				'Reference ID'         => 'field_232',
				'CTA'                  => 'field_233',
				'Service'              => 'field_234',
				'Language'             => 'field_235',
				'Page URL'             => 'field_236',
				'Refer URL'            => 'field_237',
				'User IP'              => 'field_238',
				'Channel Source'       => 'field_240',
				'Channel Medium'       => 'field_241',
				'Channel Campaign'     => 'field_242',
				'Channel Term'         => 'field_243',
				'Channel Content'      => 'field_249',
				'Channel GCLID'        => 'field_245',
				'Channel FBCLID'       => 'field_246',
				'Channel Landing'      => 'field_247',
			),
		),
	),
	// PB Property
	'pbp'     => array(
		array(
			'form_id'     => 2, // Must match the WS Form ID exactly.
			'use_cron'    => false,
			'url'         => 'https://webhooks.integrately.com/a/webhooks/70ace417574440f7b3835a71655b8a40',
			'spam_filter' => array(
				'block_if_all_filled' => array(),
			),
			'fields_map'  => array(
				'Name'                 => 'field_14',
				'Email'                => 'field_15',
				'Phone'                => 'field_16',
				'Contact method'       => 'field_17',
				'Other contact method' => 'field_18',
				'Telegram ID'          => 'field_19',
				'Message'              => 'field_20',
				'Reference ID'         => 'field_21',
				'CTA'                  => 'field_22',
				'Service'              => 'field_23',
				'Language'             => 'field_24',
				'Page URL'             => 'field_25',
				'Refer URL'            => 'field_26',
				'User IP'              => 'field_27',
				'Channel Source'       => 'field_29',
				'Channel Medium'       => 'field_30',
				'Channel Campaign'     => 'field_31',
				'Channel Term'         => 'field_32',
				'Channel Content'      => 'field_33',
				'Channel GCLID'        => 'field_34',
				'Channel FBCLID'       => 'field_35',
				'Channel Landing'      => 'field_36',

			),
		),
	),
);
