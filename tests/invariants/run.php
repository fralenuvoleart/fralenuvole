<?php
/**
 * Runner for the dependency-free invariant tests.
 *
 * Each test file runs in its own PHP process so plugin files, stubs, and
 * function declarations cannot collide. No WordPress, database, or network
 * access is involved.
 *
 * Usage: php tests/invariants/run.php [--quiet]
 *
 * @package Fralenuvole
 */

$quiet = in_array( '--quiet', $argv, true );

$files = glob( __DIR__ . '/*-test.php' );
if ( empty( $files ) ) {
	fwrite( STDERR, "No invariant tests found.\n" );
	exit( 1 );
}

sort( $files );

$failed = 0;

foreach ( $files as $file ) {
	$output = array();
	$code   = 0;

	exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $file ) . ' 2>&1', $output, $code );

	if ( $quiet && 0 === $code ) {
		echo 'ok - ' . basename( $file ) . "\n";
	} else {
		echo "\n# " . basename( $file ) . "\n";
		echo implode( "\n", $output ) . "\n";
	}

	if ( 0 !== $code ) {
		++$failed;
	}
}

echo "\n" . str_repeat( '-', 60 ) . "\n";
if ( 0 === $failed ) {
	echo 'All invariant tests passed (' . count( $files ) . " files).\n";
	exit( 0 );
}

echo $failed . ' of ' . count( $files ) . " invariant test files failed.\n";
exit( 1 );
