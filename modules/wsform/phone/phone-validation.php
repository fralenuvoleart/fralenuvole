<?php
/**
 * International Phone Numbers validation
 *
 * @package Fralenuvole
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/phone-config-constants.php';

/**
 * Phone sanitizer
 *
 * A phone-number sanitiser for real-world user input.
 *
 * Usage:
 *   $result = frl_phone_number_sanitize('Call me at (415) 555-2671 ext. 23');
 *   // [
 *   //   'raw'         => 'Call me at (415) 555-2671 ext. 23',
 *   //   'clean'       => '4155552671',
 *   //   'code'        => null,
 *   //   'digit_count' => 10,
 *   //   'valid'       => true,
 *   // ]
 */

/**
 * Sanitise a single free-text phone number.
 *
 * @param string|null $raw                Raw user input.
 * @param bool        $convert_vanity_letters Convert letters to keypad digits (1-800-FLOWERS -> 18003569377) instead of just dropping them.
 * @return array{raw: mixed, clean: string, code: ?string, digit_count: int, valid: bool}
 */
function frl_phone_number_sanitize(
	?string $raw,
	bool $convert_vanity_letters = false
): array {
	if ( null === $raw || '' === trim( $raw ) ) {
		return array(
			'raw'         => $raw,
			'clean'       => '',
			'code'        => null,
			'digit_count' => 0,
			'valid'       => false,
		);
	}

	$text = frl_phone_prepare_text( $raw, $convert_vanity_letters );

	$extracted    = frl_phone_extract_digits( $text );
	$digits       = $extracted['digits'];
	$leading_plus = $extracted['leading_plus'];

	// Explicit international assertion ('+', '00', '011') — captured before
	// bare-number detection can flip $leading_plus.
	$explicit_intl = $leading_plus;

	$code = null;
	// True when the country could not be confidently determined (either a
	// recognised prefix had a malformed national part, or no pattern
	// matched at all) — the number must be marked invalid.
	$invalid = false;

	// No '+' and no international prefix — try to detect the country.
	if ( ! $leading_plus ) {
		$result = frl_phone_maybe_prepend_country_code( $digits );
		if ( $result['prepended'] ) {
			$digits       = $result['digits'];
			$leading_plus = true;
		} elseif ( ! $result['valid'] ) {
			$invalid = true;
		}
		$code = $result['code'];
	}

	// Default-country fallback for unrecognized bare numbers.
	if ( ! $leading_plus && null === $code && '' !== PHONE_DEFAULT_COUNTRY_CODE ) {
		$default_code = PHONE_DEFAULT_COUNTRY_CODE;
		$national     = frl_phone_strip_trunk( $digits );
		$candidate    = $default_code . $national;
		if ( frl_phone_valid_length( $candidate, $default_code ) ) {
			$digits       = $candidate;
			$code         = $default_code;
			$leading_plus = true;
			$invalid      = false;
		}
	}

	// For explicit international numbers, extract the country code from
	// the leading digits so the caller knows which country was identified.
	if ( null === $code && $leading_plus ) {
		$code = frl_phone_find_country_code( $digits );
	}

	// Pattern gate for explicit international input: a number whose country
	// code has patterns must match one of them, so junk that respond.io would
	// reject is blanked here (the raw value survives in 'Phone Raw') instead
	// of killing the whole lead downstream.
	if ( $explicit_intl && null !== $code && ! frl_phone_intl_pattern_valid( $digits, $code ) ) {
		$invalid = true;
	}

	$clean       = $leading_plus ? ( '+' . $digits ) : $digits;
	$digit_count = strlen( $digits );

	// Per-country national length when configured, generic E.164 otherwise.
	$valid = $invalid
		? false
		: frl_phone_valid_length( $digits, $code );

	return array(
		'raw'         => $raw,
		'clean'       => $clean,
		'code'        => $code,
		'digit_count' => $digit_count,
		'valid'       => $valid,
	);
}

/**
 * Extract digits from a phone text and detect international format.
 *
 * @param string $text Normalised phone text.
 * @return array{digits: string, leading_plus: bool}
 */
function frl_phone_extract_digits( string $text ): array {
	$digits_preview      = preg_replace( '/\D/', '', $text );
	$looks_international = strpos( $text, '+' ) !== false
		|| strncmp( $digits_preview, '00', 2 ) === 0
		|| ( strncmp( $digits_preview, '011', 3 ) === 0 && strlen( $digits_preview ) > 11 );

	if ( $looks_international ) {
		$text = preg_replace( '/\(\s*0\s*\)/', '', $text );
	}

	$leading_plus = (bool) preg_match( '/^\D*\+/', $text );
	$digits       = preg_replace( '/\D/', '', $text );

	if ( strncmp( $digits, '00', 2 ) === 0 ) {
		$digits       = substr( $digits, 2 );
		$leading_plus = true;
	} elseif ( strncmp( $digits, '011', 3 ) === 0 && strlen( $digits ) > 11 ) {
		$digits       = substr( $digits, 3 );
		$leading_plus = true;
	}

	return array(
		'digits'       => $digits,
		'leading_plus' => $leading_plus,
	);
}

/**
 * Normalise, strip extensions and convert vanity letters.
 *
 * @param string $raw Raw user input.
 * @param bool   $convert_vanity_letters Convert letters to keypad digits.
 * @return string
 */
function frl_phone_prepare_text( string $raw, bool $convert_vanity_letters ): string {
	$text = trim( frl_phone_normalize_unicode( $raw ) );

	// Strip trailing extension markers before processing — their digits
	// must not get merged into the main number.
	$text = preg_replace( PHONE_EXTENSION_PATTERN, '', $text );

	if ( $convert_vanity_letters ) {
		$text = frl_phone_convert_vanity_letters( $text );
	}

	return $text;
}

/**
 * Normalise unicode: fold full-width / Arabic-Indic digits etc. down to
 * plain ASCII, and clean up invisible characters and separator variants.
 *
 * @param string $text Text to normalise.
 * @return string
 */
function frl_phone_normalize_unicode( string $text ): string {
	if ( class_exists( 'Normalizer' ) ) {
		// NFKC folds full-width digits (１２３), Arabic-Indic digits (١٢٣),
		// superscripts, etc. down to plain ASCII equivalents.
		$normalized = Normalizer::normalize( $text, Normalizer::FORM_KC );
		if ( false !== $normalized ) {
			$text = $normalized;
		}
	} else {
		// No php-intl on this host — cover the most common real-world
		// case (full-width digits, e.g. pasted from CJK input methods)
		// by hand so the sanitiser still works without the extension.
		static $full_width_digits = array(
			"\u{FF10}" => '0',
			"\u{FF11}" => '1',
			"\u{FF12}" => '2',
			"\u{FF13}" => '3',
			"\u{FF14}" => '4',
			"\u{FF15}" => '5',
			"\u{FF16}" => '6',
			"\u{FF17}" => '7',
			"\u{FF18}" => '8',
			"\u{FF19}" => '9',
		);
		$text                     = strtr( $text, $full_width_digits );
	}

	$text = preg_replace( PHONE_INVISIBLE_CHARS, '', $text );
	$text = preg_replace( PHONE_DASH_VARIANTS, '-', $text );
	$text = preg_replace( PHONE_SPACE_VARIANTS, ' ', $text );

	return $text;
}

/**
 * Convert alphabetic vanity-number characters to their keypad digit
 * (1-800-FLOWERS -> 1-800-3569377). Off by default — call explicitly via
 * the $convert_vanity_letters flag on sanitize_phone_number().
 *
 * @param string $text Text to convert.
 * @return string
 */
function frl_phone_convert_vanity_letters( string $text ): string {
	return preg_replace_callback(
		'/[A-Za-z]/',
		function ( $m ) {
			$upper = strtoupper( $m[0] );
			return PHONE_KEYPAD_MAP[ $upper ] ?? $m[0];
		},
		$text
	);
}

/**
 * Strip a leading trunk prefix from a digit-only string.
 *
 * @param string $digits Digit-only string.
 * @param string $trunk  Regex matching the trunk prefix (default '/^0/').
 * @return string
 */
function frl_phone_strip_trunk( string $digits, string $trunk = '/^0/' ): string {
	return (string) preg_replace( $trunk, '', $digits, 1 );
}

/**
 * Find the first country calling code that prefixes the digit string.
 *
 * @param string $digits Digit-only string.
 * @return string|null
 */
function frl_phone_find_country_code( string $digits ): ?string {
	foreach ( PHONE_COUNTRY_CONFIGS as $config ) {
		$code_len = strlen( $config['code'] );
		if ( strncmp( $digits, $config['code'], $code_len ) === 0 ) {
			return $config['code'];
		}
	}
	return null;
}

/**
 * Pattern gate for explicit international numbers ('+', '00', '011').
 *
 * When the resolved country code has at least one patterned config, the
 * national part must match one of their patterns (union — e.g. Russia and
 * Kazakhstan share code 7). Codes with no patterned config keep the
 * length-only behaviour.
 *
 * @param string $digits Digit string including the country code (no '+').
 * @param string $code   Resolved country code.
 * @return bool True when the national part passes the gate or none applies.
 */
function frl_phone_intl_pattern_valid( string $digits, string $code ): bool {
	$national  = substr( $digits, strlen( $code ) );
	$patterned = false;

	foreach ( PHONE_COUNTRY_CONFIGS as $config ) {
		if ( $config['code'] !== $code || null === $config['pattern'] ) {
			continue;
		}
		$patterned = true;
		if ( preg_match( $config['pattern'], $national ) ) {
			return true;
		}
	}

	return ! $patterned;
}

/**
 * Get the configured national-number length range for a country code.
 *
 * @param string $code Country calling code.
 * @return array{min: int, max: int}|null
 */
function frl_phone_country_length( string $code ): ?array {
	foreach ( PHONE_COUNTRY_CONFIGS as $config ) {
		if ( $config['code'] === $code && isset( $config['min_digits'], $config['max_digits'] ) ) {
			return array(
				'min' => $config['min_digits'],
				'max' => $config['max_digits'],
			);
		}
	}
	return null;
}

/**
 * Validate the national-number length, falling back to a generic E.164
 * length check when the country has no configured range.
 *
 * @param string  $digits Digit string including the country code (no '+').
 * @param ?string $code   Detected country code, or null.
 * @return bool
 */
function frl_phone_valid_length( string $digits, ?string $code ): bool {
	if ( null !== $code ) {
		$range = frl_phone_country_length( $code );
		if ( null !== $range ) {
			$national_len = strlen( $digits ) - strlen( $code );
			return $national_len >= $range['min'] && $national_len <= $range['max'];
		}
	}
	return (bool) preg_match( '/^[1-9]\d{6,14}$/', $digits );
}

/**
 * Prepend a country calling code to a bare national number.
 *
 * Confidence-tiered detection over PHONE_COUNTRY_CONFIGS (first-match-wins):
 *   1. Validated patterns — matched by country-code prefix OR bare pattern.
 *      All configs sharing a recognised code prefix are tried before the
 *      national part is declared malformed (e.g. Kazakhstan under code 7).
 *   2. Prefix-only entries — matched by country-code prefix, with a length
 *      guard that is stricter for short (1-2 digit) codes.
 *
 * Strips a leading trunk prefix.
 *
 * @param string $digits Digit-only string (no '+', no formatting).
 * @return array{digits: string, prepended: bool, code: ?string, valid: bool}
 */
function frl_phone_maybe_prepend_country_code( string $digits ): array {
	if ( '' === $digits ) {
		return array(
			'digits'    => $digits,
			'prepended' => false,
			'code'      => null,
			'valid'     => false,
		);
	}

	// First code whose prefix matched but whose pattern failed — remembered
	// so the number is still declared malformed if no same-code candidate
	// matches later in the loop.
	$pending_code = null;

	// Tier 1: validated patterns (high confidence) — checked first.
	foreach ( PHONE_COUNTRY_CONFIGS as $config ) {
		if ( null === $config['pattern'] ) {
			continue;
		}

		$code     = $config['code'];
		$code_len = strlen( $code );

		// Country code already present without a leading '+'.
		if ( strncmp( $digits, $code, $code_len ) === 0 ) {
			$national_part = substr( $digits, $code_len );

			if ( preg_match( $config['pattern'], $national_part ) ) {
				$without_trunk = frl_phone_strip_trunk( $national_part, $config['trunk'] ?? '/^0/' );

				return array(
					'digits'    => $code . $without_trunk,
					'prepended' => true,
					'code'      => $code,
					'valid'     => true,
				);
			}

			if ( null === $pending_code ) {
				$pending_code = $code;
			}
			continue;
		}

		// Bare national number matching the country pattern.
		if ( preg_match( $config['pattern'], $digits ) ) {
			$without_trunk = frl_phone_strip_trunk( $digits, $config['trunk'] ?? '/^0/' );

			return array(
				'digits'    => $code . $without_trunk,
				'prepended' => true,
				'code'      => $code,
				'valid'     => true,
			);
		}
	}

	if ( null !== $pending_code ) {
		// Country code recognised but no patterned config for it matched.
		return array(
			'digits'    => $digits,
			'prepended' => false,
			'code'      => $pending_code,
			'valid'     => false,
		);
	}

	// Tier 2/3: prefix-only entries (no pattern) — matched by code prefix.
	foreach ( PHONE_COUNTRY_CONFIGS as $config ) {
		if ( null !== $config['pattern'] ) {
			continue;
		}

		$code     = $config['code'];
		$code_len = strlen( $code );

		if ( strncmp( $digits, $code, $code_len ) !== 0 ) {
			continue;
		}

		$national_part = substr( $digits, $code_len );
		$without_trunk = frl_phone_strip_trunk( $national_part, $config['trunk'] ?? '/^0/' );

		// Short codes collide with bare national numbers, so require a
		// longer national part before accepting them.
		$min = ( $code_len <= 2 )
			? PHONE_MIN_LOCAL_DIGITS
			: PHONE_MIN_LOCAL_DIGITS_SHORT;

		if ( strlen( $without_trunk ) < $min ) {
			return array(
				'digits'    => $digits,
				'prepended' => false,
				'code'      => $code,
				'valid'     => false,
			);
		}

		return array(
			'digits'    => $code . $without_trunk,
			'prepended' => true,
			'code'      => $code,
			'valid'     => true,
		);
	}

	// Nothing matched — the country could not be determined.
	return array(
		'digits'    => $digits,
		'prepended' => false,
		'code'      => null,
		'valid'     => false,
	);
}

/**
 * Sanitise a list of raw strings.
 *
 * @param array<int, string|null> $raw_list Raw phone strings to sanitise.
 * @param bool                    $convert_vanity_letters Convert letters to keypad digits.
 * @return array<int, array>
 */
function frl_phone_number_sanitize_batch(
	array $raw_list,
	bool $convert_vanity_letters = false
): array {
	return array_map(
		fn( $raw ) => frl_phone_number_sanitize( $raw, $convert_vanity_letters ),
		$raw_list
	);
}

/**
 * Some fields contain more than one number, e.g.
 * "415-555-2671 / 415-555-9999" or "Home: 555-1234, Cell: 555-5678".
 * Splits on common separators and sanitises each piece.
 *
 * @param string $raw Raw input possibly containing several numbers.
 * @return array<int, array>
 */
function frl_phone_split_multiple_numbers( string $raw ): array {
	// Splits on '/', ';', '|', "or", "and", and commas — except a comma
	// immediately followed by an extension marker (", ext. 23", ", x23"),
	// which is one number, not two.
	$pattern = '/\s*(?:\/|;|\||\bor\b|\band\b|,(?!\s*(?:ext\.?|extension|x|\#)))\s*/i';
	$parts   = preg_split( $pattern, $raw );
	$parts   = array_filter( $parts, fn( $p ) => trim( $p ) !== '' );
	return array_map(
		fn( $p ) => frl_phone_number_sanitize( $p, false ),
		$parts
	);
}
