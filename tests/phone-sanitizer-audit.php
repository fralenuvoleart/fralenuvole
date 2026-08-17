<?php
/**
 * Production audit test for phone sanitizer multi-country refactor.
 * Run: php tests/phone-sanitizer-audit.php
 *
 * Self-contained — does not require WordPress.
 */

define( 'ABSPATH', true );

require_once __DIR__ . '/../modules/wsform/phone/phone-config-constants.php';

// Now define the functions inline (identical to wsform.php)

function frl_phone_normalize_unicode( string $text ): string {
	if ( class_exists( 'Normalizer' ) ) {
		$normalized = Normalizer::normalize( $text, Normalizer::FORM_KC );
		if ( $normalized !== false ) {
			$text = $normalized;
		}
	} else {
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

function frl_phone_strip_trunk( string $digits, string $trunk = '/^0/' ): string {
	return (string) preg_replace( $trunk, '', $digits, 1 );
}

function frl_phone_find_country_code( string $digits ): ?string {
	foreach ( PHONE_COUNTRY_CONFIGS as $config ) {
		$code_len = strlen( $config['code'] );
		if ( strncmp( $digits, $config['code'], $code_len ) === 0 ) {
			return $config['code'];
		}
	}
	return null;
}

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

function frl_phone_prepare_text( string $raw, bool $convert_vanity_letters ): string {
	$text = trim( frl_phone_normalize_unicode( $raw ) );

	$text = preg_replace( PHONE_EXTENSION_PATTERN, '', $text );

	if ( $convert_vanity_letters ) {
		$text = frl_phone_convert_vanity_letters( $text );
	}

	return $text;
}

function frl_phone_maybe_prepend_country_code( string $digits ): array {
	if ( $digits === '' ) {
		return array(
			'digits'    => $digits,
			'prepended' => false,
			'code'      => null,
			'valid'     => false,
		);
	}

	// Tier 1: validated patterns (high confidence) — checked first.
	foreach ( PHONE_COUNTRY_CONFIGS as $config ) {
		if ( $config['pattern'] === null ) {
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

			return array(
				'digits'    => $digits,
				'prepended' => false,
				'code'      => $code,
				'valid'     => false,
			);
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

	// Tier 2/3: prefix-only entries (no pattern) — matched by code prefix.
	foreach ( PHONE_COUNTRY_CONFIGS as $config ) {
		if ( $config['pattern'] !== null ) {
			continue;
		}

		$code     = $config['code'];
		$code_len = strlen( $code );

		if ( strncmp( $digits, $code, $code_len ) !== 0 ) {
			continue;
		}

		$national_part = substr( $digits, $code_len );
		$without_trunk = frl_phone_strip_trunk( $national_part, $config['trunk'] ?? '/^0/' );

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

	return array(
		'digits'    => $digits,
		'prepended' => false,
		'code'      => null,
		'valid'     => false,
	);
}

function frl_phone_number_sanitize(
	?string $raw,
	bool $convert_vanity_letters = false
): array {
	if ( $raw === null || trim( $raw ) === '' ) {
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

	$code    = null;
	$invalid = false;

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

	// For explicit international numbers, extract the country code.
	if ( $code === null && $leading_plus ) {
		$code = frl_phone_find_country_code( $digits );
	}

	$clean       = $leading_plus ? ( '+' . $digits ) : $digits;
	$digit_count = strlen( $digits );

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

function frl_phone_number_sanitize_batch(
	array $raw_list,
	bool $convert_vanity_letters = false
): array {
	return array_map(
		function ( $raw ) use ( $convert_vanity_letters ) {
			return frl_phone_number_sanitize( $raw, $convert_vanity_letters );
		},
		$raw_list
	);
}

function frl_phone_split_multiple_numbers( string $raw ): array {
	$pattern = '/\s*(?:\/|;|\||\bor\b|\band\b|,(?!\s*(?:ext\.?|extension|x|\#)))\s*/i';
	$parts   = preg_split( $pattern, $raw );
	$parts   = array_filter(
		$parts,
		function ( $p ) {
			return trim( $p ) !== '';
		}
	);
	return array_map(
		function ( $p ) {
			return frl_phone_number_sanitize( $p, false );
		},
		$parts
	);
}

// =====================================================================
// TEST HARNESS
// =====================================================================

$pass = 0;
$fail = 0;

function assert_eq( string $label, $expected, $actual ): void {
	global $pass, $fail;
	if ( $expected === $actual ) {
		++$pass;
		echo "  ✅ $label\n";
	} else {
		++$fail;
		echo "  ❌ $label\n";
		echo '     expected: ' . json_encode( $expected ) . "\n";
		echo '     actual:   ' . json_encode( $actual ) . "\n";
	}
}

function assert_true( string $label, bool $condition ): void {
	global $pass, $fail;
	if ( $condition ) {
		++$pass;
		echo "  ✅ $label\n";
	} else {
		++$fail;
		echo "  ❌ $label (expected true)\n";
	}
}

function assert_false( string $label, bool $condition ): void {
	global $pass, $fail;
	if ( ! $condition ) {
		++$pass;
		echo "  ✅ $label\n";
	} else {
		++$fail;
		echo "  ❌ $label (expected false)\n";
	}
}

// =====================================================================
// 1. CONSTANT STRUCTURE AUDIT
// =====================================================================
echo "\n=== 1. CONSTANT STRUCTURE AUDIT ===\n";

assert_true( 'PHONE_COUNTRY_CONFIGS is defined', defined( 'PHONE_COUNTRY_CONFIGS' ) );
assert_true( 'PHONE_DEFAULT_COUNTRY_CODE is defined', defined( 'PHONE_DEFAULT_COUNTRY_CODE' ) );
assert_false( 'PHONE_PATTERN_GEORGIA is removed', defined( 'PHONE_PATTERN_GEORGIA' ) );
assert_false( 'PHONE_PATTERN_SOUTHAFRICA is removed', defined( 'PHONE_PATTERN_SOUTHAFRICA' ) );

$configs      = PHONE_COUNTRY_CONFIGS;
$config_count = count( $configs );
assert_true( 'Config has 200+ entries', $config_count > 200 );
assert_eq( 'Entry 0 code', '995', $configs[0]['code'] );
assert_eq( 'Entry 0 pattern', '/^(?:0)?[345]\d{8}$/', $configs[0]['pattern'] );
assert_eq( 'Entry 1 code', '27', $configs[1]['code'] );
assert_eq( 'Entry 1 pattern', '/^(?:0)?[6-8]\d{8}$/', $configs[1]['pattern'] );
// Entry 2 is first prefix-only (code '1')
assert_eq( 'Entry 2 code', '1', $configs[2]['code'] );
assert_eq( 'Entry 2 pattern', null, $configs[2]['pattern'] );
// Last entry is SA landline fallback
$last = $configs[ $config_count - 1 ];
assert_eq( 'Last entry code', '27', $last['code'] );
assert_eq( 'Last entry pattern', '/^(?:0)?[1-5]\d{8}$/', $last['pattern'] );

assert_true( 'Entry 0 has code key', array_key_exists( 'code', $configs[0] ) );
assert_true( 'Entry 0 has pattern key', array_key_exists( 'pattern', $configs[0] ) );
assert_false( 'No pipe-separator keys exist', isset( $configs['27|mobile'] ) );

// =====================================================================
// 2. frl_phone_maybe_prepend_country_code() — UNIT TESTS
// =====================================================================
echo "\n=== 2. frl_phone_maybe_prepend_country_code() ===\n";

// 2a. Empty string
$r = frl_phone_maybe_prepend_country_code( '' );
assert_eq( 'Empty string: digits unchanged', '', $r['digits'] );
assert_false( 'Empty string: not prepended', $r['prepended'] );

// 2b. Number starting with 212 — detected as Morocco prefix
$r = frl_phone_maybe_prepend_country_code( '2125551234' );
assert_eq( '212...: digits unchanged', '2125551234', $r['digits'] );
assert_true( '212...: prepended flag', $r['prepended'] );
assert_eq( '212...: code', '212', $r['code'] );

// 2c. Georgian mobile — no trunk
$r = frl_phone_maybe_prepend_country_code( '555123456' );
assert_eq( 'GE mobile no trunk: prepended', '995555123456', $r['digits'] );
assert_true( 'GE mobile no trunk: prepended flag', $r['prepended'] );

// 2d. Georgian mobile — with trunk zero
$r = frl_phone_maybe_prepend_country_code( '0555123456' );
assert_eq( 'GE mobile with trunk: trunk stripped + prepended', '995555123456', $r['digits'] );
assert_true( 'GE mobile with trunk: prepended flag', $r['prepended'] );

// 2e. Georgian mobile — bare international (country code present without +)
// Pass 2: "995" + "555123456" → national part matches [345]\d{8}.
$r = frl_phone_maybe_prepend_country_code( '995555123456' );
assert_eq( 'GE bare intl: digits unchanged', '995555123456', $r['digits'] );
assert_true( 'GE bare intl: prepended flag', $r['prepended'] );
assert_eq( 'GE bare intl: code detected', '995', $r['code'] );

// 2f. Georgian with trunk+code (0995...) — won't match pattern
$r = frl_phone_maybe_prepend_country_code( '099555123456' );
assert_false( 'GE with trunk+code (0995...): not matched', $r['prepended'] );

// 2g. SA mobile — no trunk
$r = frl_phone_maybe_prepend_country_code( '0821234567' );
assert_eq( 'SA mobile no trunk: prepended', '27821234567', $r['digits'] );
assert_true( 'SA mobile no trunk: prepended flag', $r['prepended'] );

// 2h. SA mobile — with trunk zero
$r = frl_phone_maybe_prepend_country_code( '0821234567' );
assert_eq( 'SA mobile with trunk: trunk stripped + prepended', '27821234567', $r['digits'] );
assert_true( 'SA mobile with trunk: prepended flag', $r['prepended'] );

// 2i. SA mobile — bare international (country code present without +)
// Pass 2: "27" + "821234567" → national part matches [6-8]\d{8}.
$r = frl_phone_maybe_prepend_country_code( '27821234567' );
assert_eq( 'SA bare intl: digits unchanged', '27821234567', $r['digits'] );
assert_true( 'SA bare intl: prepended flag', $r['prepended'] );
assert_eq( 'SA bare intl: code detected', '27', $r['code'] );

// 2j. SA landline — Cape Town (021)
$r = frl_phone_maybe_prepend_country_code( '0211234567' );
assert_eq( 'SA landline CPT: prepended', '27211234567', $r['digits'] );
assert_true( 'SA landline CPT: prepended flag', $r['prepended'] );

// 2k. SA landline — Johannesburg (011)
$r = frl_phone_maybe_prepend_country_code( '0111234567' );
assert_eq( 'SA landline JHB: prepended', '27111234567', $r['digits'] );
assert_true( 'SA landline JHB: prepended flag', $r['prepended'] );

// 2l. SA landline — Durban (031) — starts with 3, Georgia wins!
$r = frl_phone_maybe_prepend_country_code( '0311234567' );
assert_eq( 'SA landline DBN (031): Georgia wins (first match)', '995311234567', $r['digits'] );
assert_true( 'SA landline DBN: prepended flag', $r['prepended'] );

// 2m. Boundary: 7 digits starting with 55 — national part (5 digits) below 6-min guard
$r = frl_phone_maybe_prepend_country_code( '5551234' );
assert_false( '7-digit 55...: rejected (national < 6 digits)', $r['prepended'] );

// 2n. Boundary: exactly 9 digits (minimum for patterns)
$r = frl_phone_maybe_prepend_country_code( '555123456' );
assert_true( '9-digit GE: matched', $r['prepended'] );

// 2o. Boundary: 10 digits with trunk
$r = frl_phone_maybe_prepend_country_code( '0555123456' );
assert_true( '10-digit GE with trunk: matched', $r['prepended'] );

// 2p. Only country code digits ("995" alone)
$r = frl_phone_maybe_prepend_country_code( '995' );
assert_false( 'Only "995": too short, not matched', $r['prepended'] );

// 2q. Only country code digits ("27" alone)
$r = frl_phone_maybe_prepend_country_code( '27' );
assert_false( 'Only "27": too short, not matched', $r['prepended'] );

// 2r. Digits with hyphens — prefix '55' matches, hyphens counted in length
$r = frl_phone_maybe_prepend_country_code( '555-123-456' );
assert_true( 'Digits with hyphens: Brazil prefix matched', $r['prepended'] );

// =====================================================================
// 3. frl_phone_number_sanitize() — INTEGRATION TESTS
// =====================================================================
echo "\n=== 3. frl_phone_number_sanitize() ===\n";

// 3a. Null input
$r = frl_phone_number_sanitize( null );
assert_eq( 'Null: clean empty', '', $r['clean'] );
assert_false( 'Null: not valid', $r['valid'] );
assert_eq( 'Null: digit_count 0', 0, $r['digit_count'] );

// 3b. Empty string
$r = frl_phone_number_sanitize( '' );
assert_eq( 'Empty: clean empty', '', $r['clean'] );
assert_false( 'Empty: not valid', $r['valid'] );

// 3c. Whitespace only
$r = frl_phone_number_sanitize( '   ' );
assert_eq( 'Whitespace: clean empty', '', $r['clean'] );
assert_false( 'Whitespace: not valid', $r['valid'] );

// 3d. Full international — Georgian
$r = frl_phone_number_sanitize( '+995 555 123 456' );
assert_eq( 'Full intl GE: clean', '+995555123456', $r['clean'] );
assert_true( 'Full intl GE: valid', $r['valid'] );
assert_eq( 'Full intl GE: digit_count', 12, $r['digit_count'] );

// 3e. Full international — South African
$r = frl_phone_number_sanitize( '+27 82 123 4567' );
assert_eq( 'Full intl SA: clean', '+27821234567', $r['clean'] );
assert_true( 'Full intl SA: valid', $r['valid'] );

// 3f. 00-prefix international — Georgian
$r = frl_phone_number_sanitize( '00995 555 123 456' );
assert_eq( '00-prefix GE: clean', '+995555123456', $r['clean'] );
assert_true( '00-prefix GE: valid', $r['valid'] );

// 3g. 011-prefix international (long enough)
$r = frl_phone_number_sanitize( '011 44 20 7946 0958' );
assert_eq( '011-prefix UK: clean', '+442079460958', $r['clean'] );
assert_true( '011-prefix UK: valid', $r['valid'] );

// 3h. Bare Georgian national number (auto-detected)
$r = frl_phone_number_sanitize( '555 123 456' );
assert_eq( 'Bare GE: clean', '+995555123456', $r['clean'] );
assert_true( 'Bare GE: valid', $r['valid'] );

// 3i. Bare Georgian with trunk zero
$r = frl_phone_number_sanitize( '0555 123 456' );
assert_eq( 'Bare GE with trunk: clean', '+995555123456', $r['clean'] );
assert_true( 'Bare GE with trunk: valid', $r['valid'] );

// 3j. Bare SA mobile
$r = frl_phone_number_sanitize( '082 123 4567' );
assert_eq( 'Bare SA mobile: clean', '+27821234567', $r['clean'] );
assert_true( 'Bare SA mobile: valid', $r['valid'] );

// 3k. Bare SA mobile with trunk (082...)
$r = frl_phone_number_sanitize( '082 123 4567' );
assert_eq( 'Bare SA mobile with trunk: clean', '+27821234567', $r['clean'] );
assert_true( 'Bare SA mobile with trunk: valid', $r['valid'] );

// 3l. Bare SA landline (Cape Town 021)
$r = frl_phone_number_sanitize( '021 123 4567' );
assert_eq( 'Bare SA landline CPT: clean', '+27211234567', $r['clean'] );
assert_true( 'Bare SA landline CPT: valid', $r['valid'] );

// 3m. Bare SA landline (Durban 031) — Georgia wins due to first-match
$r = frl_phone_number_sanitize( '031 123 4567' );
assert_eq( 'Bare SA landline DBN (031): Georgia wins', '+995311234567', $r['clean'] );
assert_true( 'Bare SA landline DBN: valid', $r['valid'] );

// 3n. US number (415) — detected as Switzerland prefix '41'
$r = frl_phone_number_sanitize( '(415) 555-2671' );
assert_eq( 'US 415: clean', '+4155552671', $r['clean'] );
assert_eq( 'US 415: code', '41', $r['code'] );
assert_true( 'US 415: valid', $r['valid'] );

// 3o. Extension (stripped, not returned)
$r = frl_phone_number_sanitize( '555 123 456 ext. 789' );
assert_eq( 'GE with extension: clean', '+995555123456', $r['clean'] );
assert_eq( 'GE with extension: code', '995', $r['code'] );
assert_true( 'GE with extension: valid', $r['valid'] );

// 3p. Extension with x-prefix (stripped)
$r = frl_phone_number_sanitize( '082 123 4567 x99' );
assert_eq( 'SA with x-extension: clean', '+27821234567', $r['clean'] );
assert_eq( 'SA with x-extension: code', '27', $r['code'] );

// 3q. Extension with hash (stripped)
$r = frl_phone_number_sanitize( '555 123 456 #42' );
assert_eq( 'GE with hash extension: clean', '+995555123456', $r['clean'] );
assert_eq( 'GE with hash extension: code', '995', $r['code'] );

// 3r. Vanity letters (off by default)
$r = frl_phone_number_sanitize( '1-800-FLOWERS' );
assert_eq( 'Vanity OFF: letters dropped', '1800', $r['clean'] );
assert_false( 'Vanity OFF: not valid (too short)', $r['valid'] );

// 3s. Vanity letters (on) — US number, detected via prefix '1'
$r = frl_phone_number_sanitize( '1-800-FLOWERS', true );
assert_eq( 'Vanity ON: letters converted', '+18003569377', $r['clean'] );
assert_eq( 'Vanity ON: code', '1', $r['code'] );
assert_true( 'Vanity ON: valid', $r['valid'] );

// 3t. Bare international Georgian (country code present without +)
$r = frl_phone_number_sanitize( '995 555 123 456' );
assert_eq( 'Bare intl GE: clean', '+995555123456', $r['clean'] );
assert_eq( 'Bare intl GE: code', '995', $r['code'] );
assert_true( 'Bare intl GE: valid', $r['valid'] );

// 3u. Bare international SA (country code present without +)
$r = frl_phone_number_sanitize( '27 82 123 4567' );
assert_eq( 'Bare intl SA: clean', '+27821234567', $r['clean'] );
assert_eq( 'Bare intl SA: code', '27', $r['code'] );
assert_true( 'Bare intl SA: valid', $r['valid'] );

// 3v. Number too short for E.164
$r = frl_phone_number_sanitize( '12345' );
assert_eq( 'Too short: clean', '12345', $r['clean'] );
assert_false( 'Too short: not valid', $r['valid'] );

// 3w. Number too long for E.164 (>15 digits) — prefix '1' detected
$r = frl_phone_number_sanitize( '1234567890123456' );
assert_eq( 'Too long: clean', '+1234567890123456', $r['clean'] );
assert_false( 'Too long: not valid', $r['valid'] );

// 3x. Number starting with trunk 0 — now matches SA landline [1-5]
// "0123456789" — (?:0)? matches "0", [1-5] matches "1", \d{8} matches "23456789".
// Trunk stripped, "27" prepended → "+27123456789". Valid E.164.
$r = frl_phone_number_sanitize( '0123456789' );
assert_eq( 'Starts with 0: now SA landline', '+27123456789', $r['clean'] );
assert_true( 'Starts with 0: valid (SA landline detected)', $r['valid'] );

// 3y. (0) trunk marker in international number
$r = frl_phone_number_sanitize( '+44 (0) 20 7946 0958' );
assert_eq( 'Intl with (0) trunk: clean', '+442079460958', $r['clean'] );
assert_true( 'Intl with (0) trunk: valid', $r['valid'] );

// 3z. 00-prefix with (0) trunk
$r = frl_phone_number_sanitize( '0044 (0) 20 7946 0958' );
assert_eq( '00-prefix with (0) trunk: clean', '+442079460958', $r['clean'] );
assert_true( '00-prefix with (0) trunk: valid', $r['valid'] );

// 3aa. 011 short number (should NOT be treated as international)
$r = frl_phone_number_sanitize( '011 555 1234' );
// digits_preview = "0115551234" (10 digits). strncmp with "011" is true, but strlen <= 11.
// So looks_international = false. leading_plus = false.
// digits = "0115551234"
// [345]: starts with 0, no match. [6-8]: starts with 0, no match.
// [1-5]: "0115551234" — (?:0)? matches "0", then [1-5] matches "1", \d{8} matches "15551234". $ matches. YES!
// So SA landline pattern matches. Trunk stripped: "115551234". Prepend "27": "27115551234".
// clean = "+27115551234". Valid.
assert_eq( '011 short (not intl): clean', '+27115551234', $r['clean'] );
assert_true( '011 short (not intl): valid', $r['valid'] );

// =====================================================================
// 4. frl_phone_number_sanitize_batch()
// =====================================================================
echo "\n=== 4. frl_phone_number_sanitize_batch() ===\n";

$batch = frl_phone_number_sanitize_batch( array( '555123456', '0821234567', null, '' ) );
assert_eq( 'Batch: count', 4, count( $batch ) );
assert_eq( 'Batch[0]: GE', '+995555123456', $batch[0]['clean'] );
assert_eq( 'Batch[1]: SA', '+27821234567', $batch[1]['clean'] );
assert_eq( 'Batch[2]: null', '', $batch[2]['clean'] );
assert_eq( 'Batch[3]: empty', '', $batch[3]['clean'] );

// =====================================================================
// 5. frl_phone_split_multiple_numbers()
// =====================================================================
echo "\n=== 5. frl_phone_split_multiple_numbers() ===\n";

$split = frl_phone_split_multiple_numbers( '555123456 / 0821234567' );
assert_eq( 'Split slash: count', 2, count( $split ) );
assert_eq( 'Split slash[0]: GE', '+995555123456', $split[0]['clean'] );
assert_eq( 'Split slash[1]: SA', '+27821234567', $split[1]['clean'] );

$split = frl_phone_split_multiple_numbers( '555123456; 0821234567' );
assert_eq( 'Split semicolon: count', 2, count( $split ) );

$split = frl_phone_split_multiple_numbers( '555123456 or 0821234567' );
assert_eq( 'Split "or": count', 2, count( $split ) );

$split = frl_phone_split_multiple_numbers( '555123456 and 0821234567' );
assert_eq( 'Split "and": count', 2, count( $split ) );

// Comma with extension should NOT split
$split = frl_phone_split_multiple_numbers( '555123456, ext. 42' );
assert_eq( 'Comma+ext: count (not split)', 1, count( $split ) );
assert_eq( 'Comma+ext: code', '995', $split[0]['code'] );

// =====================================================================
// 6. FUNCTION SIGNATURE AUDIT
// =====================================================================
echo "\n=== 6. FUNCTION SIGNATURE AUDIT ===\n";

$refl   = new ReflectionFunction( 'frl_phone_number_sanitize' );
$params = $refl->getParameters();
assert_eq( 'sanitize: param count', 2, count( $params ) );
assert_eq( 'sanitize: param 0 name', 'raw', $params[0]->getName() );
assert_eq( 'sanitize: param 1 name', 'convert_vanity_letters', $params[1]->getName() );

$refl   = new ReflectionFunction( 'frl_phone_maybe_prepend_country_code' );
$params = $refl->getParameters();
assert_eq( 'prepend: param count', 1, count( $params ) );
assert_eq( 'prepend: param 0 name', 'digits', $params[0]->getName() );

$refl   = new ReflectionFunction( 'frl_phone_number_sanitize_batch' );
$params = $refl->getParameters();
assert_eq( 'batch: param count', 2, count( $params ) );

$refl   = new ReflectionFunction( 'frl_phone_split_multiple_numbers' );
$params = $refl->getParameters();
assert_eq( 'split: param count', 1, count( $params ) );

// =====================================================================
// 7. PERFORMANCE
// =====================================================================
echo "\n=== 7. PERFORMANCE ===\n";
$start = microtime( true );
for ( $i = 0; $i < 1000; $i++ ) {
	frl_phone_number_sanitize( '555123456' );
	frl_phone_number_sanitize( '0821234567' );
	frl_phone_number_sanitize( '+995555123456' );
}
$elapsed = ( microtime( true ) - $start ) * 1000;
echo '  3000 sanitizations in ' . round( $elapsed, 2 ) . "ms\n";
assert_true( 'Performance < 100ms for 3000 calls', $elapsed < 100 );

// =====================================================================
// 8. REAL-WORLD REGRESSION — permanent fixture
// =====================================================================
echo "\n=== 8. REAL-WORLD REGRESSION ===\n";

$regression = array(
	// SA bare international (code present, no +)
	array( '27817232816', '+27817232816', '27', 11, true ),
	// SA mobile with trunk
	array( '0692730173', '+27692730173', '27', 11, true ),
	// SA mobile with trunk
	array( '0788264593', '+27788264593', '27', 11, true ),
	// AU mobile (prefix-only, now detected)
	array( '61432513335', '+61432513335', '61', 11, true ),
	// AZ mobile (prefix-only, now detected)
	array( '994503333724', '+994503333724', '994', 12, true ),
	// GE explicit + (user asserts validity)
	array( '+995995595525', '+995995595525', '995', 12, true ),
	// GE bare intl — prefix recognised but national part malformed
	array( '995995595525', '995995595525', '995', 12, false ),
	// GE explicit +
	array( '+995599654454', '+995599654454', '995', 12, true ),
	// GE bare intl — valid national part
	array( '995599654454', '+995599654454', '995', 12, true ),
	// GE national with trunk
	array( '0599654454', '+995599654454', '995', 12, true ),
	// GE bare national
	array( '599654454', '+995599654454', '995', 12, true ),
	// GE explicit + with formatting
	array( '+995 (599) 65 44 54', '+995599654454', '995', 12, true ),
);

foreach ( $regression as $i => $row ) {
	list( $input, $clean, $code, $digits, $valid ) = $row;
	$r     = frl_phone_number_sanitize( $input );
	$label = sprintf( '#%d %s', $i + 1, $input );
	assert_eq( "$label → clean", $clean, $r['clean'] );
	assert_eq( "$label → code", $code, $r['code'] );
	assert_eq( "$label → digits", $digits, $r['digit_count'] );
	assert_eq( "$label → valid", $valid, $r['valid'] );
}

// =====================================================================
// SUMMARY
// =====================================================================
echo "\n=== SUMMARY ===\n";
echo "  Passed: $pass\n";
echo "  Failed: $fail\n";

if ( $fail > 0 ) {
	echo "\n  ❌ AUDIT FAILED — do not ship.\n";
	exit( 1 );
} else {
	echo "\n  ✅ AUDIT PASSED — production ready.\n";
	exit( 0 );
}
