<?php

/**
 * WS Form — Who controls what (admin ALWAYS wins at runtime)
 *
 * Setting               | Admin option? | Constant fallback? | Default
 * ----------------------|---------------|--------------------|--------
 * Webhooks on/off       | YES           | prefix → constant  | ON
 * Cron vs sync dispatch | YES           | per-webhook true   | Cron
 * Channel tracking      | YES           | none               | ON
 * Dashboard widget      | YES           | none               | OFF
 *
 * Webhooks fire ONLY IF: admin switch is ON + this site's prefix
 * matches a key in WSFORM_ALL_WEBHOOKS_CONFIG below.
 *
 * WS Form module settings
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Stats widgets: integer IDs get a per-form widget, 'all' adds a combined widget.
// Examples: [10, 9, 'all']  |  ['all']  |  [10]  |  []
const WSFORM_STATS_FORM_IDS = array( 'all' );

// Weekend override message shown instead of the form's configured success message
// when a "Date" field contains "Sat" or "Sun". Translated via frl_get_translation().
const WSFORM_WEEKEND_MESSAGE = 'Thank you for your inquiry. We will answer you on Monday.';

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
				'Phone Raw'            => 'field_227',
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
			'use_cron'    => true,
			'url'         => 'https://webhooks.integrately.com/a/webhooks/70ace417574440f7b3835a71655b8a40',
			'spam_filter' => array(
				'block_if_all_filled' => array(),
			),
			'fields_map'  => array(
				'Name'                 => 'field_14',
				'Email'                => 'field_15',
				'Phone'                => 'field_16',
				'Phone Raw'            => 'field_16',
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

/**
 * frl_wsf_phone_sanitizer Extension markers
 *
 */
const PHONE_EXTENSION_PATTERN = '/
    (?:ext\.?|extension|x|\#)
    \s*[:.\-]?\s*
    (\d{1,6})
    \s*$
/ix';

// ---------------------------------------------------------------------
// 2. Invisible / unicode-variant characters that sneak in via copy-paste
// ---------------------------------------------------------------------
// 1. Captures extension markers (ext, x, #) trailing a phone number.
// 2. Invisible / unicode-variant characters that sneak in via copy-paste.
const PHONE_INVISIBLE_CHARS = "/[\x{200B}\x{200C}\x{200D}\x{2060}\x{FEFF}]/u"; // ZWSP, ZWNJ, ZWJ, word joiner, BOM
const PHONE_DASH_VARIANTS   = "/[\x{2010}-\x{2015}\x{2212}]/u"; // unicode hyphen/minus variants -> '-'
const PHONE_SPACE_VARIANTS  = "/[\x{00A0}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}]/u"; // unicode spaces -> ' '

/**
 * Minimum digits required in the national part for prefix-only
 * country entries (where pattern is null). Based on E.164 minimum
 * total length of 8 digits minus the longest country code (2).
 */
const PHONE_MIN_NATIONAL_DIGITS = 6;

/**
 * Country detection configs for bare national numbers (no '+', no
 * detected international prefix). Matched in order; first pattern that
 * matches wins — so list more-specific patterns first.
 *
 * Overlap note: Georgia mobile [345] sits inside SA landline [1-5], so
 * Georgia must be listed first to claim those shared leading digits.
 */
const PHONE_COUNTRY_CONFIGS = array(
	// ── Validated patterns (ordered, first-match-wins) ──────────
	array(
		'code'    => '995',
		'pattern' => '/^(?:0)?[345]\d{8}$/', // Georgia: mobile 3xx/4xx/5xx
	),
	array(
		'code'    => '27',
		'pattern' => '/^(?:0)?[6-8]\d{8}$/', // South Africa: mobile 06x/07x/08x
	),

	// ── Prefix-only entries (pattern = null) ────────────────────
	// All ITU-T E.164 country calling codes.  Sorted numerically.
	// A minimum 6-digit national-part length guard still applies.
	array(
		'code'    => '1',
		'pattern' => null,
	), // United States / Canada (NANP)
	array(
		'code'    => '7',
		'pattern' => null,
	), // Russia / Kazakhstan
	array(
		'code'    => '20',
		'pattern' => null,
	), // Egypt
	array(
		'code'    => '30',
		'pattern' => null,
	), // Greece
	array(
		'code'    => '31',
		'pattern' => null,
	), // Netherlands
	array(
		'code'    => '32',
		'pattern' => null,
	), // Belgium
	array(
		'code'    => '33',
		'pattern' => null,
	), // France
	array(
		'code'    => '34',
		'pattern' => null,
	), // Spain
	array(
		'code'    => '36',
		'pattern' => null,
	), // Hungary
	array(
		'code'    => '39',
		'pattern' => null,
	), // Italy
	array(
		'code'    => '40',
		'pattern' => null,
	), // Romania
	array(
		'code'    => '41',
		'pattern' => null,
	), // Switzerland
	array(
		'code'    => '43',
		'pattern' => null,
	), // Austria
	array(
		'code'    => '44',
		'pattern' => null,
	), // United Kingdom
	array(
		'code'    => '45',
		'pattern' => null,
	), // Denmark
	array(
		'code'    => '46',
		'pattern' => null,
	), // Sweden
	array(
		'code'    => '47',
		'pattern' => null,
	), // Norway
	array(
		'code'    => '48',
		'pattern' => null,
	), // Poland
	array(
		'code'    => '49',
		'pattern' => null,
	), // Germany
	array(
		'code'    => '51',
		'pattern' => null,
	), // Peru
	array(
		'code'    => '52',
		'pattern' => null,
	), // Mexico
	array(
		'code'    => '53',
		'pattern' => null,
	), // Cuba
	array(
		'code'    => '54',
		'pattern' => null,
	), // Argentina
	array(
		'code'    => '55',
		'pattern' => null,
	), // Brazil
	array(
		'code'    => '56',
		'pattern' => null,
	), // Chile
	array(
		'code'    => '57',
		'pattern' => null,
	), // Colombia
	array(
		'code'    => '58',
		'pattern' => null,
	), // Venezuela
	array(
		'code'    => '60',
		'pattern' => null,
	), // Malaysia
	array(
		'code'    => '61',
		'pattern' => null,
	), // Australia
	array(
		'code'    => '62',
		'pattern' => null,
	), // Indonesia
	array(
		'code'    => '63',
		'pattern' => null,
	), // Philippines
	array(
		'code'    => '64',
		'pattern' => null,
	), // New Zealand
	array(
		'code'    => '65',
		'pattern' => null,
	), // Singapore
	array(
		'code'    => '66',
		'pattern' => null,
	), // Thailand
	array(
		'code'    => '81',
		'pattern' => null,
	), // Japan
	array(
		'code'    => '82',
		'pattern' => null,
	), // South Korea
	array(
		'code'    => '84',
		'pattern' => null,
	), // Vietnam
	array(
		'code'    => '86',
		'pattern' => null,
	), // China
	array(
		'code'    => '90',
		'pattern' => null,
	), // Turkey
	array(
		'code'    => '91',
		'pattern' => null,
	), // India
	array(
		'code'    => '92',
		'pattern' => null,
	), // Pakistan
	array(
		'code'    => '93',
		'pattern' => null,
	), // Afghanistan
	array(
		'code'    => '94',
		'pattern' => null,
	), // Sri Lanka
	array(
		'code'    => '95',
		'pattern' => null,
	), // Myanmar
	array(
		'code'    => '98',
		'pattern' => null,
	), // Iran
	array(
		'code'    => '211',
		'pattern' => null,
	), // South Sudan
	array(
		'code'    => '212',
		'pattern' => null,
	), // Morocco
	array(
		'code'    => '213',
		'pattern' => null,
	), // Algeria
	array(
		'code'    => '216',
		'pattern' => null,
	), // Tunisia
	array(
		'code'    => '218',
		'pattern' => null,
	), // Libya
	array(
		'code'    => '220',
		'pattern' => null,
	), // Gambia
	array(
		'code'    => '221',
		'pattern' => null,
	), // Senegal
	array(
		'code'    => '222',
		'pattern' => null,
	), // Mauritania
	array(
		'code'    => '223',
		'pattern' => null,
	), // Mali
	array(
		'code'    => '224',
		'pattern' => null,
	), // Guinea
	array(
		'code'    => '225',
		'pattern' => null,
	), // Ivory Coast
	array(
		'code'    => '226',
		'pattern' => null,
	), // Burkina Faso
	array(
		'code'    => '227',
		'pattern' => null,
	), // Niger
	array(
		'code'    => '228',
		'pattern' => null,
	), // Togo
	array(
		'code'    => '229',
		'pattern' => null,
	), // Benin
	array(
		'code'    => '230',
		'pattern' => null,
	), // Mauritius
	array(
		'code'    => '231',
		'pattern' => null,
	), // Liberia
	array(
		'code'    => '232',
		'pattern' => null,
	), // Sierra Leone
	array(
		'code'    => '233',
		'pattern' => null,
	), // Ghana
	array(
		'code'    => '234',
		'pattern' => null,
	), // Nigeria
	array(
		'code'    => '235',
		'pattern' => null,
	), // Chad
	array(
		'code'    => '236',
		'pattern' => null,
	), // Central African Republic
	array(
		'code'    => '237',
		'pattern' => null,
	), // Cameroon
	array(
		'code'    => '238',
		'pattern' => null,
	), // Cape Verde
	array(
		'code'    => '239',
		'pattern' => null,
	), // Sao Tome and Principe
	array(
		'code'    => '240',
		'pattern' => null,
	), // Equatorial Guinea
	array(
		'code'    => '241',
		'pattern' => null,
	), // Gabon
	array(
		'code'    => '242',
		'pattern' => null,
	), // Republic of the Congo
	array(
		'code'    => '243',
		'pattern' => null,
	), // DR Congo
	array(
		'code'    => '244',
		'pattern' => null,
	), // Angola
	array(
		'code'    => '245',
		'pattern' => null,
	), // Guinea-Bissau
	array(
		'code'    => '246',
		'pattern' => null,
	), // British Indian Ocean Territory
	array(
		'code'    => '247',
		'pattern' => null,
	), // Ascension Island
	array(
		'code'    => '248',
		'pattern' => null,
	), // Seychelles
	array(
		'code'    => '249',
		'pattern' => null,
	), // Sudan
	array(
		'code'    => '250',
		'pattern' => null,
	), // Rwanda
	array(
		'code'    => '251',
		'pattern' => null,
	), // Ethiopia
	array(
		'code'    => '252',
		'pattern' => null,
	), // Somalia
	array(
		'code'    => '253',
		'pattern' => null,
	), // Djibouti
	array(
		'code'    => '254',
		'pattern' => null,
	), // Kenya
	array(
		'code'    => '255',
		'pattern' => null,
	), // Tanzania
	array(
		'code'    => '256',
		'pattern' => null,
	), // Uganda
	array(
		'code'    => '257',
		'pattern' => null,
	), // Burundi
	array(
		'code'    => '258',
		'pattern' => null,
	), // Mozambique
	array(
		'code'    => '260',
		'pattern' => null,
	), // Zambia
	array(
		'code'    => '261',
		'pattern' => null,
	), // Madagascar
	array(
		'code'    => '262',
		'pattern' => null,
	), // Reunion / Mayotte
	array(
		'code'    => '263',
		'pattern' => null,
	), // Zimbabwe
	array(
		'code'    => '264',
		'pattern' => null,
	), // Namibia
	array(
		'code'    => '265',
		'pattern' => null,
	), // Malawi
	array(
		'code'    => '266',
		'pattern' => null,
	), // Lesotho
	array(
		'code'    => '267',
		'pattern' => null,
	), // Botswana
	array(
		'code'    => '268',
		'pattern' => null,
	), // Eswatini
	array(
		'code'    => '269',
		'pattern' => null,
	), // Comoros
	array(
		'code'    => '290',
		'pattern' => null,
	), // Saint Helena
	array(
		'code'    => '291',
		'pattern' => null,
	), // Eritrea
	array(
		'code'    => '297',
		'pattern' => null,
	), // Aruba
	array(
		'code'    => '298',
		'pattern' => null,
	), // Faroe Islands
	array(
		'code'    => '299',
		'pattern' => null,
	), // Greenland
	array(
		'code'    => '350',
		'pattern' => null,
	), // Gibraltar
	array(
		'code'    => '351',
		'pattern' => null,
	), // Portugal
	array(
		'code'    => '352',
		'pattern' => null,
	), // Luxembourg
	array(
		'code'    => '353',
		'pattern' => null,
	), // Ireland
	array(
		'code'    => '354',
		'pattern' => null,
	), // Iceland
	array(
		'code'    => '355',
		'pattern' => null,
	), // Albania
	array(
		'code'    => '356',
		'pattern' => null,
	), // Malta
	array(
		'code'    => '357',
		'pattern' => null,
	), // Cyprus
	array(
		'code'    => '358',
		'pattern' => null,
	), // Finland
	array(
		'code'    => '359',
		'pattern' => null,
	), // Bulgaria
	array(
		'code'    => '370',
		'pattern' => null,
	), // Lithuania
	array(
		'code'    => '371',
		'pattern' => null,
	), // Latvia
	array(
		'code'    => '372',
		'pattern' => null,
	), // Estonia
	array(
		'code'    => '373',
		'pattern' => null,
	), // Moldova
	array(
		'code'    => '374',
		'pattern' => null,
	), // Armenia
	array(
		'code'    => '375',
		'pattern' => null,
	), // Belarus
	array(
		'code'    => '376',
		'pattern' => null,
	), // Andorra
	array(
		'code'    => '377',
		'pattern' => null,
	), // Monaco
	array(
		'code'    => '378',
		'pattern' => null,
	), // San Marino
	array(
		'code'    => '379',
		'pattern' => null,
	), // Vatican City
	array(
		'code'    => '380',
		'pattern' => null,
	), // Ukraine
	array(
		'code'    => '381',
		'pattern' => null,
	), // Serbia
	array(
		'code'    => '382',
		'pattern' => null,
	), // Montenegro
	array(
		'code'    => '383',
		'pattern' => null,
	), // Kosovo
	array(
		'code'    => '385',
		'pattern' => null,
	), // Croatia
	array(
		'code'    => '386',
		'pattern' => null,
	), // Slovenia
	array(
		'code'    => '387',
		'pattern' => null,
	), // Bosnia and Herzegovina
	array(
		'code'    => '389',
		'pattern' => null,
	), // North Macedonia
	array(
		'code'    => '420',
		'pattern' => null,
	), // Czech Republic
	array(
		'code'    => '421',
		'pattern' => null,
	), // Slovakia
	array(
		'code'    => '423',
		'pattern' => null,
	), // Liechtenstein
	array(
		'code'    => '500',
		'pattern' => null,
	), // Falkland Islands
	array(
		'code'    => '501',
		'pattern' => null,
	), // Belize
	array(
		'code'    => '502',
		'pattern' => null,
	), // Guatemala
	array(
		'code'    => '503',
		'pattern' => null,
	), // El Salvador
	array(
		'code'    => '504',
		'pattern' => null,
	), // Honduras
	array(
		'code'    => '505',
		'pattern' => null,
	), // Nicaragua
	array(
		'code'    => '506',
		'pattern' => null,
	), // Costa Rica
	array(
		'code'    => '507',
		'pattern' => null,
	), // Panama
	array(
		'code'    => '508',
		'pattern' => null,
	), // Saint Pierre and Miquelon
	array(
		'code'    => '509',
		'pattern' => null,
	), // Haiti
	array(
		'code'    => '590',
		'pattern' => null,
	), // Guadeloupe
	array(
		'code'    => '591',
		'pattern' => null,
	), // Bolivia
	array(
		'code'    => '592',
		'pattern' => null,
	), // Guyana
	array(
		'code'    => '593',
		'pattern' => null,
	), // Ecuador
	array(
		'code'    => '594',
		'pattern' => null,
	), // French Guiana
	array(
		'code'    => '595',
		'pattern' => null,
	), // Paraguay
	array(
		'code'    => '596',
		'pattern' => null,
	), // Martinique
	array(
		'code'    => '597',
		'pattern' => null,
	), // Suriname
	array(
		'code'    => '598',
		'pattern' => null,
	), // Uruguay
	array(
		'code'    => '599',
		'pattern' => null,
	), // Curacao / Caribbean Netherlands
	array(
		'code'    => '670',
		'pattern' => null,
	), // East Timor
	array(
		'code'    => '672',
		'pattern' => null,
	), // Australian External Territories
	array(
		'code'    => '673',
		'pattern' => null,
	), // Brunei
	array(
		'code'    => '674',
		'pattern' => null,
	), // Nauru
	array(
		'code'    => '675',
		'pattern' => null,
	), // Papua New Guinea
	array(
		'code'    => '676',
		'pattern' => null,
	), // Tonga
	array(
		'code'    => '677',
		'pattern' => null,
	), // Solomon Islands
	array(
		'code'    => '678',
		'pattern' => null,
	), // Vanuatu
	array(
		'code'    => '679',
		'pattern' => null,
	), // Fiji
	array(
		'code'    => '680',
		'pattern' => null,
	), // Palau
	array(
		'code'    => '681',
		'pattern' => null,
	), // Wallis and Futuna
	array(
		'code'    => '682',
		'pattern' => null,
	), // Cook Islands
	array(
		'code'    => '683',
		'pattern' => null,
	), // Niue
	array(
		'code'    => '685',
		'pattern' => null,
	), // Samoa
	array(
		'code'    => '686',
		'pattern' => null,
	), // Kiribati
	array(
		'code'    => '687',
		'pattern' => null,
	), // New Caledonia
	array(
		'code'    => '688',
		'pattern' => null,
	), // Tuvalu
	array(
		'code'    => '689',
		'pattern' => null,
	), // French Polynesia
	array(
		'code'    => '690',
		'pattern' => null,
	), // Tokelau
	array(
		'code'    => '691',
		'pattern' => null,
	), // Micronesia
	array(
		'code'    => '692',
		'pattern' => null,
	), // Marshall Islands
	array(
		'code'    => '800',
		'pattern' => null,
	), // International Freephone
	array(
		'code'    => '808',
		'pattern' => null,
	), // International Shared Cost
	array(
		'code'    => '850',
		'pattern' => null,
	), // North Korea
	array(
		'code'    => '852',
		'pattern' => null,
	), // Hong Kong
	array(
		'code'    => '853',
		'pattern' => null,
	), // Macau
	array(
		'code'    => '855',
		'pattern' => null,
	), // Cambodia
	array(
		'code'    => '856',
		'pattern' => null,
	), // Laos
	array(
		'code'    => '870',
		'pattern' => null,
	), // Inmarsat SNAC
	array(
		'code'    => '878',
		'pattern' => null,
	), // Universal Personal Telecom
	array(
		'code'    => '880',
		'pattern' => null,
	), // Bangladesh
	array(
		'code'    => '881',
		'pattern' => null,
	), // Global Mobile Satellite
	array(
		'code'    => '882',
		'pattern' => null,
	), // International Networks
	array(
		'code'    => '883',
		'pattern' => null,
	), // International Networks
	array(
		'code'    => '886',
		'pattern' => null,
	), // Taiwan
	array(
		'code'    => '960',
		'pattern' => null,
	), // Maldives
	array(
		'code'    => '961',
		'pattern' => null,
	), // Lebanon
	array(
		'code'    => '962',
		'pattern' => null,
	), // Jordan
	array(
		'code'    => '963',
		'pattern' => null,
	), // Syria
	array(
		'code'    => '964',
		'pattern' => null,
	), // Iraq
	array(
		'code'    => '965',
		'pattern' => null,
	), // Kuwait
	array(
		'code'    => '966',
		'pattern' => null,
	), // Saudi Arabia
	array(
		'code'    => '967',
		'pattern' => null,
	), // Yemen
	array(
		'code'    => '968',
		'pattern' => null,
	), // Oman
	array(
		'code'    => '970',
		'pattern' => null,
	), // Palestine
	array(
		'code'    => '971',
		'pattern' => null,
	), // United Arab Emirates
	array(
		'code'    => '972',
		'pattern' => null,
	), // Israel
	array(
		'code'    => '973',
		'pattern' => null,
	), // Bahrain
	array(
		'code'    => '974',
		'pattern' => null,
	), // Qatar
	array(
		'code'    => '975',
		'pattern' => null,
	), // Bhutan
	array(
		'code'    => '976',
		'pattern' => null,
	), // Mongolia
	array(
		'code'    => '977',
		'pattern' => null,
	), // Nepal
	array(
		'code'    => '992',
		'pattern' => null,
	), // Tajikistan
	array(
		'code'    => '993',
		'pattern' => null,
	), // Turkmenistan
	array(
		'code'    => '994',
		'pattern' => null,
	), // Azerbaijan
	array(
		'code'    => '996',
		'pattern' => null,
	), // Kyrgyzstan
	array(
		'code'    => '998',
		'pattern' => null,
	), // Uzbekistan

	// ── Fallback (must be last — overlaps with Georgia [345]) ──
	array(
		'code'    => '27',
		'pattern' => '/^(?:0)?[1-5]\d{8}$/', // South Africa: landline 01x-05x
	),
);

// Standard telephone keypad, for optional vanity-number conversion
// (1-800-FLOWERS -> 1-800-3569377).
const PHONE_KEYPAD_MAP = array(
	'A' => '2',
	'B' => '2',
	'C' => '2',
	'D' => '3',
	'E' => '3',
	'F' => '3',
	'G' => '4',
	'H' => '4',
	'I' => '4',
	'J' => '5',
	'K' => '5',
	'L' => '5',
	'M' => '6',
	'N' => '6',
	'O' => '6',
	'P' => '7',
	'Q' => '7',
	'R' => '7',
	'S' => '7',
	'T' => '8',
	'U' => '8',
	'V' => '8',
	'W' => '9',
	'X' => '9',
	'Y' => '9',
	'Z' => '9',
);
