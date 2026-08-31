<?php
/**
 * Regression test for the phone sanitizer explicit-international pattern
 * gate and the bare-path multi-candidate fix.
 * Run: php tests/phone-pattern-gate-test.php
 *
 * Self-contained — does not require WordPress. Exercises the REAL module
 * files (phone-sanitizer-audit.php snapshots inline copies instead).
 */

define( 'ABSPATH', true );

require_once __DIR__ . '/../modules/wsform/phone/phone-validation.php';

$pass = 0;
$fail = 0;

/**
 * Expected sanitize() result shape.
 *
 * @param string      $clean  Expected clean value.
 * @param string|null $code   Expected country code.
 * @param int         $digits Expected digit count.
 * @param bool        $valid  Expected validity.
 * @return array
 */
function exp_sanitize( string $clean, ?string $code, int $digits, bool $valid ): array {
	return array(
		'clean'       => $clean,
		'code'        => $code,
		'digit_count' => $digits,
		'valid'       => $valid,
	);
}

/**
 * Expected frl_phone_maybe_prepend_country_code() result shape.
 *
 * @param string      $digits    Expected digit string.
 * @param bool        $prepended Expected prepended flag.
 * @param string|null $code      Expected country code.
 * @param bool        $valid     Expected validity.
 * @return array
 */
function exp_prepend( string $digits, bool $prepended, ?string $code, bool $valid ): array {
	return array(
		'digits'    => $digits,
		'prepended' => $prepended,
		'code'      => $code,
		'valid'     => $valid,
	);
}

/**
 * Compare selected keys of a result array against expectations.
 *
 * @param string $label    Test name.
 * @param array  $expected Key => value pairs to assert.
 * @param array  $actual   Result array under test.
 */
function check( string $label, array $expected, array $actual ): void {
	global $pass, $fail;

	$diff = array();
	foreach ( $expected as $key => $value ) {
		if ( ! array_key_exists( $key, $actual ) ) {
			$diff[] = "{$key}: missing from result";
		} elseif ( $actual[ $key ] !== $value ) {
			$diff[] = sprintf( '%s: expected %s, got %s', $key, json_encode( $value ), json_encode( $actual[ $key ] ) );
		}
	}

	if ( empty( $diff ) ) {
		++$pass;
		echo "  ✅ {$label}\n";
	} else {
		++$fail;
		echo "  ❌ {$label}\n";
		foreach ( $diff as $line ) {
			echo "     {$line}\n";
		}
	}
}

/**
 * Assert a boolean result.
 *
 * @param string $label    Test name.
 * @param bool   $expected Expected value.
 * @param bool   $actual   Value under test.
 */
function check_bool( string $label, bool $expected, bool $actual ): void {
	check( $label, array( 'v' => $expected ), array( 'v' => $actual ) );
}

echo "=== 1. GATE UNIT CHECKS (frl_phone_intl_pattern_valid) ===\n";

check_bool( 'junk +7 rejected (neither RU nor KZ pattern)', false, frl_phone_intl_pattern_valid( '76464968946', '7' ) );
check_bool( 'RU mobile +7 passes via union', true, frl_phone_intl_pattern_valid( '79001234567', '7' ) );
check_bool( 'KZ mobile +7 passes via union', true, frl_phone_intl_pattern_valid( '77012345678', '7' ) );
check_bool( 'RU landline +7 rejected (mobile-only patterns)', false, frl_phone_intl_pattern_valid( '74951234567', '7' ) );
check_bool( 'GE mobile passes', true, frl_phone_intl_pattern_valid( '995599654454', '995' ) );
check_bool( 'GE national starting 9 rejected (policy flip)', false, frl_phone_intl_pattern_valid( '995995595525', '995' ) );
check_bool( 'UK landline rejected (policy flip)', false, frl_phone_intl_pattern_valid( '442079460958', '44' ) );
check_bool( 'UK mobile passes', true, frl_phone_intl_pattern_valid( '447911123456', '44' ) );
check_bool( 'Egypt has no patterned config: not gated', true, frl_phone_intl_pattern_valid( '201001234567', '20' ) );
check_bool( 'Israel has no patterned config: not gated', true, frl_phone_intl_pattern_valid( '972501234567', '972' ) );
check_bool( 'US NANP passes', true, frl_phone_intl_pattern_valid( '14155552671', '1' ) );
check_bool( 'SA mobile passes', true, frl_phone_intl_pattern_valid( '27821234567', '27' ) );
check_bool( 'SA landline passes via union', true, frl_phone_intl_pattern_valid( '27211234567', '27' ) );

echo "\n=== 2. SANITIZE — EXPLICIT INTERNATIONAL ('+' / '00') ===\n";

/**
 * Test cases: label, input, expected sanitize() shape.
 *
 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}> $cases
 */
$cases = array(
	array( 'junk +7 blanked (the respond.io leak)', '+76464968946', exp_sanitize( '+76464968946', '7', 11, false ) ),
	array( 'RU mobile', '+7 900 123 45 67', exp_sanitize( '+79001234567', '7', 11, true ) ),
	array( 'KZ mobile', '+7 701 234 56 78', exp_sanitize( '+77012345678', '7', 11, true ) ),
	array( 'RU landline blanked, survives in Phone Raw', '+7 495 123 45 67', exp_sanitize( '+74951234567', '7', 11, false ) ),
	array( 'KZ via 00 prefix', '007 701 234 56 78', exp_sanitize( '+77012345678', '7', 11, true ) ),
	array( 'GE mobile', '+995 599 654 454', exp_sanitize( '+995599654454', '995', 12, true ) ),
	array( 'GE junk national now gated (policy flip)', '+995995595525', exp_sanitize( '+995995595525', '995', 12, false ) ),
	array( 'UK landline now gated (policy flip)', '+44 (0) 20 7946 0958', exp_sanitize( '+442079460958', '44', 12, false ) ),
	array( 'UK mobile', '+44 7911 123456', exp_sanitize( '+447911123456', '44', 12, true ) ),
	array( 'Egypt prefix-only length-only', '+20 100 123 4567', exp_sanitize( '+201001234567', '20', 12, true ) ),
	array( 'Israel prefix-only length-only', '+972 50 123 45 67', exp_sanitize( '+972501234567', '972', 12, true ) ),
	array( 'US NANP', '+1 415 555 2671', exp_sanitize( '+14155552671', '1', 11, true ) ),
	array( 'SA mobile', '+27 82 123 4567', exp_sanitize( '+27821234567', '27', 11, true ) ),
);

foreach ( $cases as $case ) {
	list( $label, $input, $expected ) = $case;
	check( "{$label} [{$input}]", $expected, frl_phone_number_sanitize( $input ) );
}

echo "\n=== 3. SANITIZE — BARE PATH (multi-candidate fix) ===\n";

/**
 * Test cases: label, input, expected sanitize() shape.
 *
 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}> $cases
 */
$cases = array(
	array( 'bare KZ rescued (was false negative)', '77012345678', exp_sanitize( '+77012345678', '7', 11, true ) ),
	array( 'bare junk +7 still malformed', '76464968946', exp_sanitize( '76464968946', '7', 11, false ) ),
	array( 'GE prefix recognised, malformed national', '995995595525', exp_sanitize( '995995595525', '995', 12, false ) ),
	array( 'GE bare national', '555123456', exp_sanitize( '+995555123456', '995', 12, true ) ),
	array( 'SA mobile with trunk', '0821234567', exp_sanitize( '+27821234567', '27', 11, true ) ),
	array( 'Georgia wins over SA landline (overlap order)', '0311234567', exp_sanitize( '+995311234567', '995', 12, true ) ),
);

foreach ( $cases as $case ) {
	list( $label, $input, $expected ) = $case;
	check( "{$label} [{$input}]", $expected, frl_phone_number_sanitize( $input ) );
}

echo "\n=== 4. PREPEND DIRECT (result shape) ===\n";

check(
	'bare KZ: prepended, RU candidate did not short-circuit',
	exp_prepend( '77012345678', true, '7', true ),
	frl_phone_maybe_prepend_country_code( '77012345678' )
);
check(
	'bare junk +7: pending code reported, malformed',
	exp_prepend( '76464968946', false, '7', false ),
	frl_phone_maybe_prepend_country_code( '76464968946' )
);
check(
	'bare GE malformed national: pending code 995',
	exp_prepend( '995995595525', false, '995', false ),
	frl_phone_maybe_prepend_country_code( '995995595525' )
);

echo "\n=== SUMMARY ===\n";
echo "  Passed: {$pass}\n";
echo "  Failed: {$fail}\n";

if ( $fail > 0 ) {
	echo "\n  ❌ PATTERN-GATE TEST FAILED — do not ship.\n";
	exit( 1 );
}

echo "\n  ✅ PATTERN-GATE TEST PASSED.\n";
exit( 0 );
