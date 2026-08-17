<?php
/**
 * Phone sanitizer constants.
 *
 * @package Fralenuvole
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Frl_wsf_phone_sanitizer extension markers.
 */
const PHONE_EXTENSION_PATTERN = '/
    (?:ext\.?|extension|x|\#)
    \s*[:.\-]?\s*
    (\d{1,6})
    \s*$
/ix';

// 1. Captures extension markers (ext, x, #) trailing a phone number.
// 2. Invisible / unicode-variant characters that sneak in via copy-paste.
const PHONE_INVISIBLE_CHARS = "/[\x{200B}\x{200C}\x{200D}\x{2060}\x{FEFF}]/u"; // ZWSP, ZWNJ, ZWJ, word joiner, BOM.
const PHONE_DASH_VARIANTS   = "/[\x{2010}-\x{2015}\x{2212}]/u"; // unicode hyphen/minus variants -> '-'.
const PHONE_SPACE_VARIANTS  = "/[\x{00A0}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}]/u"; // unicode spaces -> ' '.

/**
 * Minimum digits required in the local number (after the country code)
 * for prefix-only country entries (where pattern is null).
 */
const PHONE_MIN_LOCAL_DIGITS = 8;

/**
 * Minimum digits required in the local number for prefix-only entries
 * with a short (1-2 digit) country code.
 */
const PHONE_MIN_LOCAL_DIGITS_SHORT = 6;

/**
 * Default country code used as a fallback for bare national numbers
 * that could not be matched to any country pattern. Set to empty
 * string to disable the fallback.
 */
const PHONE_DEFAULT_COUNTRY_CODE = '995';

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
	// Asia, Middle East & Americas.
	array(
		'code'       => '995',
		'pattern'    => '/^(?:0)?[345]\d{8}$/', // Georgia: mobile 3xx/4xx/5xx.
		'min_digits' => 9,
		'max_digits' => 9,
	),
	array(
		'code'       => '7',
		'pattern'    => '/^(?:[80])?9\d{9}$/', // Russia: mobile 9xx.
		'trunk'      => '/^[08]/',
		'min_digits' => 10,
		'max_digits' => 10,
	),
	array(
		'code'       => '98',
		'pattern'    => '/^(?:0)?9\d{9}$/',
		'min_digits' => 10,
		'max_digits' => 10,
	), // Iran.
	array(
		'code'       => '970',
		'pattern'    => '/^(?:0)?5\d{8}$/',
		'min_digits' => 9,
		'max_digits' => 9,
	), // Palestine.
	array(
		'code'       => '7',
		'pattern'    => '/^(?:[80])?7(?:0[0-25-8]|47|5[01]|6[0-4]|7[15-8]|80)\d{7}$/', // Kazakhstan: mobile 700-708, 747, 750-751, 760-764, 771-778, 780.
		'trunk'      => '/^[08]/',
		'min_digits' => 10,
		'max_digits' => 10,
	),
	array(
		'code'       => '92',
		'pattern'    => '/^(?:0)?3\d{9}$/', // Pakistan: mobile 3xx.
		'min_digits' => 10,
		'max_digits' => 10,
	),
	array(
		'code'       => '966',
		'pattern'    => '/^(?:0)?5\d{8}$/', // Saudi Arabia: mobile 5xx.
		'min_digits' => 9,
		'max_digits' => 9,
	),
	array(
		'code'       => '27',
		'pattern'    => '/^(?:0)?[6-8]\d{8}$/', // South Africa: mobile 06x/07x/08x.
		'min_digits' => 9,
		'max_digits' => 9,
	),
	// ── South Africa landline (overlaps with Georgia [345]) ──
	array(
		'code'       => '27',
		'pattern'    => '/^(?:0)?[1-5]\d{8}$/', // South Africa: landline 01x-05x.
		'min_digits' => 9,
		'max_digits' => 9,
	),
	array(
		'code'       => '66',
		'pattern'    => '/^(?:0)?[689]\d{8}$/', // Thailand: mobile 6x, 8x, 9x.
		'min_digits' => 9,
		'max_digits' => 9,
	),
	array(
		'code'       => '971',
		'pattern'    => '/^(?:0)?5[024-68]\d{7}$/', // United Arab Emirates: mobile 50, 52, 54, 55, 56, 58.
		'min_digits' => 9,
		'max_digits' => 9,
	),
	array(
		'code'       => '1',
		'pattern'    => '/^(?:1)?[2-9]\d{2}[2-9]\d{6}$/', // United States: NANP format (10 digits).
		'min_digits' => 10,
		'max_digits' => 10,
	),
	// Europe.
	array(
		'code'       => '43',
		'pattern'    => '/^(?:0)?6(?:50|60|64|7[6-8]|8[018]|9[09])\d{7,10}$/', // Austria: mobile 650-699 (10-13 digits).
		'min_digits' => 10,
		'max_digits' => 13,
	),
	array(
		'code'       => '33',
		'pattern'    => '/^(?:0)?[67]\d{8}$/', // France: mobile 6x, 7x.
		'min_digits' => 9,
		'max_digits' => 9,
	),
	array(
		'code'       => '49',
		'pattern'    => '/^(?:0)?1[5-7]\d{8,9}$/', // Germany: mobile 15x, 16x, 17x.
		'min_digits' => 10,
		'max_digits' => 11,
	),
	array(
		'code'       => '30',
		'pattern'    => '/^(?:0)?69\d{8}$/', // Greece: mobile 69x.
		'min_digits' => 10,
		'max_digits' => 10,
	),
	array(
		'code'       => '39',
		'pattern'    => '/^(?:0)?3\d{8,9}$/', // Italy: mobile 3xx.
		'min_digits' => 9,
		'max_digits' => 10,
	),
	array(
		'code'       => '31',
		'pattern'    => '/^(?:0)?6\d{8}$/', // Netherlands: mobile 6x.
		'min_digits' => 9,
		'max_digits' => 9,
	),
	array(
		'code'       => '47',
		'pattern'    => '/^(?:0)?[49]\d{7}$/', // Norway: mobile 4xx, 9xx.
		'min_digits' => 8,
		'max_digits' => 8,
	),
	array(
		'code'       => '351',
		'pattern'    => '/^(?:0)?9[1-36]\d{7}$/', // Portugal: mobile 91, 92, 93, 96.
		'min_digits' => 9,
		'max_digits' => 9,
	),
	array(
		'code'       => '34',
		'pattern'    => '/^(?:0)?[67]\d{8}$/', // Spain: mobile 6x, 7x.
		'min_digits' => 9,
		'max_digits' => 9,
	),
	array(
		'code'       => '46',
		'pattern'    => '/^(?:0)?7[02369]\d{7}$/', // Sweden: mobile 70, 72, 73, 76, 79.
		'min_digits' => 9,
		'max_digits' => 9,
	),
	array(
		'code'       => '41',
		'pattern'    => '/^(?:0)?7[5-9]\d{7}$/', // Switzerland: mobile 75-79.
		'min_digits' => 9,
		'max_digits' => 9,
	),
	array(
		'code'       => '90',
		'pattern'    => '/^(?:0)?5\d{9}$/', // Türkiye: mobile 5xx.
		'min_digits' => 10,
		'max_digits' => 10,
	),
	array(
		'code'       => '44',
		'pattern'    => '/^(?:0)?7[1-9]\d{8}$/', // United Kingdom: mobile 71-79.
		'min_digits' => 10,
		'max_digits' => 10,
	),
	// CIS, Eastern Europe, Baltics & Balkans.
	array(
		'code'       => '374',
		'pattern'    => '/^(?:0)?(?:33|4[13479]|5[05]|66|77|9[13-9])\d{6}$/', // Armenia: mobile 33, 41, 43, 44, 47, 49, 50, 55, 66, 77, 91-99.
		'min_digits' => 8,
		'max_digits' => 8,
	),
	array(
		'code'       => '994',
		'pattern'    => '/^(?:0)?(?:40|5[015]|60|7[07]|99)\d{7}$/', // Azerbaijan mobile prefixes.
		'min_digits' => 9,
		'max_digits' => 9,
	),
	array(
		'code'       => '375',
		'pattern'    => '/^(?:80|0)?(?:25|29|33|44)\d{7}$/', // Belarus: mobile 25, 29, 33, 44.
		'trunk'      => '/^(?:80|0)/',
		'min_digits' => 9,
		'max_digits' => 9,
	),
	array(
		'code'       => '996',
		'pattern'    => '/^(?:0)?(?:2[02]|5\d|7\d|88|9\d)\d{7}$/', // Kyrgyzstan: mobile 20, 22, 5x, 7x, 88, 9x.
		'min_digits' => 9,
		'max_digits' => 9,
	),
	array(
		'code'       => '371',
		'pattern'    => '/^(?:0)?2\d{7}$/', // Latvia: mobile 2xxxxxxx.
		'min_digits' => 8,
		'max_digits' => 8,
	),
	array(
		'code'       => '40',
		'pattern'    => '/^(?:0)?7\d{8}$/', // Romania: mobile 7xx.
		'min_digits' => 9,
		'max_digits' => 9,
	),
	array(
		'code'       => '381',
		'pattern'    => '/^(?:0)?6\d{7,8}$/', // Serbia: mobile 6xx.
		'min_digits' => 8,
		'max_digits' => 9,
	),
	array(
		'code'       => '380',
		'pattern'    => '/^(?:0)?(?:39|50|6[3678]|73|9[1-9])\d{7}$/', // Ukraine: mobile 39, 50, 63, 66, 67, 68, 73, 91-99.
		'min_digits' => 9,
		'max_digits' => 9,
	),
	array(
		'code'       => '998',
		'pattern'    => '/^(?:0)?(?:20|33|5[05]|77|88|9\d)\d{7}$/', // Uzbekistan: mobile 20, 33, 50, 55, 77, 88, 90-99.
		'min_digits' => 9,
		'max_digits' => 9,
	),
	array(
		'code'       => '32',
		'pattern'    => '/^(?:0)?4[5-9]\d{7}$/',
		'min_digits' => 9,
		'max_digits' => 9,
	), // Belgium.
	array(
		'code'       => '36',
		'pattern'    => '/^(?:0)?(?:20|30|31|50|70)\d{7}$/',
		'min_digits' => 9,
		'max_digits' => 9,
	), // Hungary.
	array(
		'code'       => '45',
		'pattern'    => '/^(?:0)?(?:2\d|3[01]|4[0-2]|5[0-3]|6[01]|71|81|9[1-3])\d{6}$/',
		'min_digits' => 8,
		'max_digits' => 8,
	), // Denmark.
	array(
		'code'       => '48',
		'pattern'    => '/^(?:0)?(?:45|5[0137]|6[069]|7[2389]|88)\d{7}$/',
		'min_digits' => 9,
		'max_digits' => 9,
	), // Poland.
	array(
		'code'       => '51',
		'pattern'    => '/^(?:0)?9\d{8}$/',
		'min_digits' => 9,
		'max_digits' => 9,
	), // Peru.
	array(
		'code'       => '52',
		'pattern'    => '/^(?:0)?[2-9]\d{9}$/',
		'min_digits' => 10,
		'max_digits' => 10,
	), // Mexico.
	array(
		'code'       => '53',
		'pattern'    => '/^(?:0)?5\d{7}$/',
		'min_digits' => 8,
		'max_digits' => 8,
	), // Cuba.
	array(
		'code'       => '54',
		'pattern'    => '/^(?:0)?(?:9)?[1-9]\d{9}$/',
		'min_digits' => 10,
		'max_digits' => 11,
	), // Argentina.
	array(
		'code'       => '55',
		'pattern'    => '/^(?:0)?[1-9]{2}9\d{8}$/',
		'min_digits' => 11,
		'max_digits' => 11,
	), // Brazil.
	array(
		'code'       => '56',
		'pattern'    => '/^(?:0)?9\d{8}$/',
		'min_digits' => 9,
		'max_digits' => 9,
	), // Chile.
	array(
		'code'       => '57',
		'pattern'    => '/^(?:0)?3\d{9}$/',
		'min_digits' => 10,
		'max_digits' => 10,
	), // Colombia.
	array(
		'code'       => '58',
		'pattern'    => '/^(?:0)?4\d{9}$/',
		'min_digits' => 10,
		'max_digits' => 10,
	), // Venezuela.
	array(
		'code'       => '60',
		'pattern'    => '/^(?:0)?1(?:1\d{8}|[02-9]\d{7})$/',
		'min_digits' => 9,
		'max_digits' => 10,
	), // Malaysia.
	array(
		'code'       => '61',
		'pattern'    => '/^(?:0)?4\d{8}$/',
		'min_digits' => 9,
		'max_digits' => 9,
	), // Australia.
	array(
		'code'       => '62',
		'pattern'    => '/^(?:0)?8\d{8,11}$/',
		'min_digits' => 9,
		'max_digits' => 12,
	), // Indonesia.
	array(
		'code'       => '63',
		'pattern'    => '/^(?:0)?9\d{9}$/',
		'min_digits' => 10,
		'max_digits' => 10,
	), // Philippines.
	array(
		'code'       => '64',
		'pattern'    => '/^(?:0)?2\d{7,9}$/',
		'min_digits' => 8,
		'max_digits' => 10,
	), // New Zealand.
	array(
		'code'       => '65',
		'pattern'    => '/^(?:0)?[89]\d{7}$/',
		'min_digits' => 8,
		'max_digits' => 8,
	), // Singapore.
	array(
		'code'       => '81',
		'pattern'    => '/^(?:0)?[789]0\d{8}$/',
		'min_digits' => 10,
		'max_digits' => 10,
	), // Japan.
	array(
		'code'       => '82',
		'pattern'    => '/^(?:0)?1\d{9}$/',
		'min_digits' => 10,
		'max_digits' => 10,
	), // South Korea.
	array(
		'code'       => '84',
		'pattern'    => '/^(?:0)?[35789]\d{8}$/',
		'min_digits' => 9,
		'max_digits' => 9,
	), // Vietnam.
	array(
		'code'       => '86',
		'pattern'    => '/^(?:0)?1[3-9]\d{9}$/',
		'min_digits' => 11,
		'max_digits' => 11,
	), // China.
	array(
		'code'       => '93',
		'pattern'    => '/^(?:0)?7\d{8}$/',
		'min_digits' => 9,
		'max_digits' => 9,
	), // Afghanistan.
	array(
		'code'       => '350',
		'pattern'    => '/^(?:0)?5\d{7}$/',
		'min_digits' => 8,
		'max_digits' => 8,
	), // Gibraltar.
	array(
		'code'       => '352',
		'pattern'    => '/^(?:0)?6\d{8}$/',
		'min_digits' => 9,
		'max_digits' => 9,
	), // Luxembourg.
	array(
		'code'       => '353',
		'pattern'    => '/^(?:0)?8\d{8}$/',
		'min_digits' => 9,
		'max_digits' => 9,
	), // Ireland.
	array(
		'code'       => '354',
		'pattern'    => '/^(?:0)?[6-8]\d{6}$/',
		'min_digits' => 7,
		'max_digits' => 7,
	), // Iceland.
	array(
		'code'       => '355',
		'pattern'    => '/^(?:0)?6\d{7}$/',
		'min_digits' => 8,
		'max_digits' => 8,
	), // Albania.
	array(
		'code'       => '356',
		'pattern'    => '/^(?:0)?[79]\d{7}$/',
		'min_digits' => 8,
		'max_digits' => 8,
	), // Malta.
	array(
		'code'       => '357',
		'pattern'    => '/^(?:0)?9\d{7}$/',
		'min_digits' => 8,
		'max_digits' => 8,
	), // Cyprus.
	array(
		'code'       => '358',
		'pattern'    => '/^(?:0)?[45]\d{8}$/',
		'min_digits' => 9,
		'max_digits' => 9,
	), // Finland.
	array(
		'code'       => '359',
		'pattern'    => '/^(?:0)?[89]\d{8}$/',
		'min_digits' => 9,
		'max_digits' => 9,
	), // Bulgaria.
	array(
		'code'       => '372',
		'pattern'    => '/^(?:0)?[58]\d{6,7}$/',
		'min_digits' => 7,
		'max_digits' => 8,
	), // Estonia.
	array(
		'code'       => '852',
		'pattern'    => '/^(?:0)?[4-9]\d{7}$/',
		'min_digits' => 8,
		'max_digits' => 8,
	), // Hong Kong.
	array(
		'code'       => '886',
		'pattern'    => '/^(?:0)?9\d{8}$/',
		'min_digits' => 9,
		'max_digits' => 9,
	), // Taiwan.
	array(
		'code'       => '967',
		'pattern'    => '/^(?:0)?7\d{8}$/',
		'min_digits' => 9,
		'max_digits' => 9,
	), // Yemen.
	// India.
	array(
		'code'       => '91',
		'pattern'    => '/^(?:0)?[6-9]\d{9}$/', // India: mobile 6x-9x.
		'min_digits' => 10,
		'max_digits' => 10,
	),
	// ── Prefix-only entries (pattern = null) ────────────────────
	// All ITU-T E.164 country calling codes.  Sorted numerically.
	// A minimum 6-digit national-part length guard still applies.
	array(
		'code'    => '20',
		'pattern' => null,
	), // Egypt.
	array(
		'code'    => '94',
		'pattern' => null,
	), // Sri Lanka.
	array(
		'code'    => '95',
		'pattern' => null,
	), // Myanmar.
	array(
		'code'    => '211',
		'pattern' => null,
	), // South Sudan.
	array(
		'code'    => '212',
		'pattern' => null,
	), // Morocco.
	array(
		'code'    => '213',
		'pattern' => null,
	), // Algeria.
	array(
		'code'    => '216',
		'pattern' => null,
	), // Tunisia.
	array(
		'code'    => '218',
		'pattern' => null,
	), // Libya.
	array(
		'code'    => '220',
		'pattern' => null,
	), // Gambia.
	array(
		'code'    => '221',
		'pattern' => null,
	), // Senegal.
	array(
		'code'    => '222',
		'pattern' => null,
	), // Mauritania.
	array(
		'code'    => '223',
		'pattern' => null,
	), // Mali.
	array(
		'code'    => '224',
		'pattern' => null,
	), // Guinea.
	array(
		'code'    => '225',
		'pattern' => null,
	), // Ivory Coast.
	array(
		'code'    => '226',
		'pattern' => null,
	), // Burkina Faso.
	array(
		'code'    => '227',
		'pattern' => null,
	), // Niger.
	array(
		'code'    => '228',
		'pattern' => null,
	), // Togo.
	array(
		'code'    => '229',
		'pattern' => null,
	), // Benin.
	array(
		'code'    => '230',
		'pattern' => null,
	), // Mauritius.
	array(
		'code'    => '231',
		'pattern' => null,
	), // Liberia.
	array(
		'code'    => '232',
		'pattern' => null,
	), // Sierra Leone.
	array(
		'code'    => '233',
		'pattern' => null,
	), // Ghana.
	array(
		'code'    => '234',
		'pattern' => null,
	), // Nigeria.
	array(
		'code'    => '235',
		'pattern' => null,
	), // Chad.
	array(
		'code'    => '236',
		'pattern' => null,
	), // Central African Republic.
	array(
		'code'    => '237',
		'pattern' => null,
	), // Cameroon.
	array(
		'code'    => '238',
		'pattern' => null,
	), // Cape Verde.
	array(
		'code'    => '239',
		'pattern' => null,
	), // Sao Tome and Principe.
	array(
		'code'    => '240',
		'pattern' => null,
	), // Equatorial Guinea.
	array(
		'code'    => '241',
		'pattern' => null,
	), // Gabon.
	array(
		'code'    => '242',
		'pattern' => null,
	), // Republic of the Congo.
	array(
		'code'    => '243',
		'pattern' => null,
	), // DR Congo.
	array(
		'code'    => '244',
		'pattern' => null,
	), // Angola.
	array(
		'code'    => '245',
		'pattern' => null,
	), // Guinea-Bissau.
	array(
		'code'    => '246',
		'pattern' => null,
	), // British Indian Ocean Territory.
	array(
		'code'    => '247',
		'pattern' => null,
	), // Ascension Island.
	array(
		'code'    => '248',
		'pattern' => null,
	), // Seychelles.
	array(
		'code'    => '249',
		'pattern' => null,
	), // Sudan.
	array(
		'code'    => '250',
		'pattern' => null,
	), // Rwanda.
	array(
		'code'    => '251',
		'pattern' => null,
	), // Ethiopia.
	array(
		'code'    => '252',
		'pattern' => null,
	), // Somalia.
	array(
		'code'    => '253',
		'pattern' => null,
	), // Djibouti.
	array(
		'code'    => '254',
		'pattern' => null,
	), // Kenya.
	array(
		'code'    => '255',
		'pattern' => null,
	), // Tanzania.
	array(
		'code'    => '256',
		'pattern' => null,
	), // Uganda.
	array(
		'code'    => '257',
		'pattern' => null,
	), // Burundi.
	array(
		'code'    => '258',
		'pattern' => null,
	), // Mozambique.
	array(
		'code'    => '260',
		'pattern' => null,
	), // Zambia.
	array(
		'code'    => '261',
		'pattern' => null,
	), // Madagascar.
	array(
		'code'    => '262',
		'pattern' => null,
	), // Reunion / Mayotte.
	array(
		'code'    => '263',
		'pattern' => null,
	), // Zimbabwe.
	array(
		'code'    => '264',
		'pattern' => null,
	), // Namibia.
	array(
		'code'    => '265',
		'pattern' => null,
	), // Malawi.
	array(
		'code'    => '266',
		'pattern' => null,
	), // Lesotho.
	array(
		'code'    => '267',
		'pattern' => null,
	), // Botswana.
	array(
		'code'    => '268',
		'pattern' => null,
	), // Eswatini.
	array(
		'code'    => '269',
		'pattern' => null,
	), // Comoros.
	array(
		'code'    => '290',
		'pattern' => null,
	), // Saint Helena.
	array(
		'code'    => '291',
		'pattern' => null,
	), // Eritrea.
	array(
		'code'    => '297',
		'pattern' => null,
	), // Aruba.
	array(
		'code'    => '298',
		'pattern' => null,
	), // Faroe Islands.
	array(
		'code'    => '299',
		'pattern' => null,
	), // Greenland.
	array(
		'code'    => '370',
		'pattern' => null,
	), // Lithuania.
	array(
		'code'    => '373',
		'pattern' => null,
	), // Moldova.
	array(
		'code'    => '376',
		'pattern' => null,
	), // Andorra.
	array(
		'code'    => '377',
		'pattern' => null,
	), // Monaco.
	array(
		'code'    => '378',
		'pattern' => null,
	), // San Marino.
	array(
		'code'    => '379',
		'pattern' => null,
	), // Vatican City.
	array(
		'code'    => '382',
		'pattern' => null,
	), // Montenegro.
	array(
		'code'    => '383',
		'pattern' => null,
	), // Kosovo.
	array(
		'code'    => '385',
		'pattern' => null,
	), // Croatia.
	array(
		'code'    => '386',
		'pattern' => null,
	), // Slovenia.
	array(
		'code'    => '387',
		'pattern' => null,
	), // Bosnia and Herzegovina.
	array(
		'code'    => '389',
		'pattern' => null,
	), // North Macedonia.
	array(
		'code'    => '420',
		'pattern' => null,
	), // Czech Republic.
	array(
		'code'    => '421',
		'pattern' => null,
	), // Slovakia.
	array(
		'code'    => '423',
		'pattern' => null,
	), // Liechtenstein.
	array(
		'code'    => '500',
		'pattern' => null,
	), // Falkland Islands.
	array(
		'code'    => '501',
		'pattern' => null,
	), // Belize.
	array(
		'code'    => '502',
		'pattern' => null,
	), // Guatemala.
	array(
		'code'    => '503',
		'pattern' => null,
	), // El Salvador.
	array(
		'code'    => '504',
		'pattern' => null,
	), // Honduras.
	array(
		'code'    => '505',
		'pattern' => null,
	), // Nicaragua.
	array(
		'code'    => '506',
		'pattern' => null,
	), // Costa Rica.
	array(
		'code'    => '507',
		'pattern' => null,
	), // Panama.
	array(
		'code'    => '508',
		'pattern' => null,
	), // Saint Pierre and Miquelon.
	array(
		'code'    => '509',
		'pattern' => null,
	), // Haiti.
	array(
		'code'    => '590',
		'pattern' => null,
	), // Guadeloupe.
	array(
		'code'    => '591',
		'pattern' => null,
	), // Bolivia.
	array(
		'code'    => '592',
		'pattern' => null,
	), // Guyana.
	array(
		'code'    => '593',
		'pattern' => null,
	), // Ecuador.
	array(
		'code'    => '594',
		'pattern' => null,
	), // French Guiana.
	array(
		'code'    => '595',
		'pattern' => null,
	), // Paraguay.
	array(
		'code'    => '596',
		'pattern' => null,
	), // Martinique.
	array(
		'code'    => '597',
		'pattern' => null,
	), // Suriname.
	array(
		'code'    => '598',
		'pattern' => null,
	), // Uruguay.
	array(
		'code'    => '599',
		'pattern' => null,
	), // Curacao / Caribbean Netherlands.
	array(
		'code'    => '670',
		'pattern' => null,
	), // East Timor.
	array(
		'code'    => '672',
		'pattern' => null,
	), // Australian External Territories.
	array(
		'code'    => '673',
		'pattern' => null,
	), // Brunei.
	array(
		'code'    => '674',
		'pattern' => null,
	), // Nauru.
	array(
		'code'    => '675',
		'pattern' => null,
	), // Papua New Guinea.
	array(
		'code'    => '676',
		'pattern' => null,
	), // Tonga.
	array(
		'code'    => '677',
		'pattern' => null,
	), // Solomon Islands.
	array(
		'code'    => '678',
		'pattern' => null,
	), // Vanuatu.
	array(
		'code'    => '679',
		'pattern' => null,
	), // Fiji.
	array(
		'code'    => '680',
		'pattern' => null,
	), // Palau.
	array(
		'code'    => '681',
		'pattern' => null,
	), // Wallis and Futuna.
	array(
		'code'    => '682',
		'pattern' => null,
	), // Cook Islands.
	array(
		'code'    => '683',
		'pattern' => null,
	), // Niue.
	array(
		'code'    => '685',
		'pattern' => null,
	), // Samoa.
	array(
		'code'    => '686',
		'pattern' => null,
	), // Kiribati.
	array(
		'code'    => '687',
		'pattern' => null,
	), // New Caledonia.
	array(
		'code'    => '688',
		'pattern' => null,
	), // Tuvalu.
	array(
		'code'    => '689',
		'pattern' => null,
	), // French Polynesia.
	array(
		'code'    => '690',
		'pattern' => null,
	), // Tokelau.
	array(
		'code'    => '691',
		'pattern' => null,
	), // Micronesia.
	array(
		'code'    => '692',
		'pattern' => null,
	), // Marshall Islands.
	array(
		'code'    => '800',
		'pattern' => null,
	), // International Freephone.
	array(
		'code'    => '808',
		'pattern' => null,
	), // International Shared Cost.
	array(
		'code'    => '850',
		'pattern' => null,
	), // North Korea.
	array(
		'code'    => '853',
		'pattern' => null,
	), // Macau.
	array(
		'code'    => '855',
		'pattern' => null,
	), // Cambodia.
	array(
		'code'    => '856',
		'pattern' => null,
	), // Laos.
	array(
		'code'    => '870',
		'pattern' => null,
	), // Inmarsat SNAC.
	array(
		'code'    => '878',
		'pattern' => null,
	), // Universal Personal Telecom.
	array(
		'code'    => '880',
		'pattern' => null,
	), // Bangladesh.
	array(
		'code'    => '881',
		'pattern' => null,
	), // Global Mobile Satellite.
	array(
		'code'    => '882',
		'pattern' => null,
	), // International Networks.
	array(
		'code'    => '883',
		'pattern' => null,
	), // International Networks.
	array(
		'code'    => '960',
		'pattern' => null,
	), // Maldives.
	array(
		'code'    => '961',
		'pattern' => null,
	), // Lebanon.
	array(
		'code'    => '962',
		'pattern' => null,
	), // Jordan.
	array(
		'code'    => '963',
		'pattern' => null,
	), // Syria.
	array(
		'code'    => '964',
		'pattern' => null,
	), // Iraq.
	array(
		'code'    => '965',
		'pattern' => null,
	), // Kuwait.
	array(
		'code'    => '968',
		'pattern' => null,
	), // Oman.
	array(
		'code'    => '972',
		'pattern' => null,
	), // Israel.
	array(
		'code'    => '973',
		'pattern' => null,
	), // Bahrain.
	array(
		'code'    => '974',
		'pattern' => null,
	), // Qatar.
	array(
		'code'    => '975',
		'pattern' => null,
	), // Bhutan.
	array(
		'code'    => '976',
		'pattern' => null,
	), // Mongolia.
	array(
		'code'    => '977',
		'pattern' => null,
	), // Nepal.
	array(
		'code'    => '992',
		'pattern' => null,
	), // Tajikistan.
	array(
		'code'    => '993',
		'pattern' => null,
	), // Turkmenistan.
);


// Standard telephone keypad, for optional vanity-number conversion.
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
