<?php
/**
 * Tiny TAP-style assertion helpers for the invariant tests.
 *
 * @package Fralenuvole
 */

$GLOBALS['frl_test_assert'] = array(
	'pass' => 0,
	'fail' => 0,
);

/**
 * Assert a condition.
 *
 * @param bool   $condition Condition.
 * @param string $message   Human-readable description.
 * @return void
 */
function frl_test_ok( $condition, string $message ): void {
	if ( $condition ) {
		++$GLOBALS['frl_test_assert']['pass'];
		echo 'ok - ' . $message . "\n";
		return;
	}

	++$GLOBALS['frl_test_assert']['fail'];
	echo 'NOT OK - ' . $message . "\n";
}

/**
 * Assert strict equality and print both values on failure.
 *
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 * @param string $message  Human-readable description.
 * @return void
 */
function frl_test_same( $expected, $actual, string $message ): void {
	$pass = $expected === $actual;
	frl_test_ok( $pass, $message );

	if ( ! $pass ) {
		echo '  expected: ' . frl_test_render( $expected ) . "\n";
		echo '  actual:   ' . frl_test_render( $actual ) . "\n";
	}
}

/**
 * Render a value for failure output.
 *
 * @param mixed $value Value.
 * @return string
 */
function frl_test_render( $value ): string {
	if ( is_bool( $value ) ) {
		return $value ? 'true' : 'false';
	}
	if ( null === $value ) {
		return 'null';
	}
	if ( is_scalar( $value ) ) {
		return (string) $value;
	}

	$encoded = json_encode( $value );
	return false === $encoded ? gettype( $value ) : $encoded;
}

/**
 * Print the summary and exit non-zero when anything failed.
 *
 * @return void
 */
function frl_test_done(): void {
	$pass = (int) $GLOBALS['frl_test_assert']['pass'];
	$fail = (int) $GLOBALS['frl_test_assert']['fail'];

	echo "\n" . $pass . ' passed, ' . $fail . " failed\n";
	exit( $fail > 0 ? 1 : 0 );
}
