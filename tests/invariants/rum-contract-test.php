<?php
/**
 * Invariant: the RUM beacon's event and column contract.
 *
 * The printer (rum.php) and the receiver (rum-receiver.php) are two halves of one wire format.
 * Every event the printer sends must be accepted by the receiver, every allowed event must still
 * be sent, and every column named in FRL_RUM_COLUMNS must be written in that exact order, because
 * the kinsta-logs skill reads the TSV by position.
 *
 * Regressions guarded: an event renamed on one side only (the receiver exits silently, the event
 * vanishes from the log), a column added to the header but not to the row (every later field
 * shifts), and the docs table drifting from the code.
 *
 * @package Fralenuvole
 */

require __DIR__ . '/lib/assert.php';

$plugin   = dirname( __DIR__, 2 );
$printer  = $plugin . '/core/debugger/rum/rum.php';
$receiver = $plugin . '/core/debugger/rum/rum-receiver.php';
$docs     = $plugin . '/docs/CORE-DEBUGGER.md';

$printer_src  = (string) file_get_contents( $printer );
$receiver_src = (string) file_get_contents( $receiver );
$docs_src     = (string) file_get_contents( $docs );

// 1. Events the printer emits: s("name") inside the inline script.
preg_match_all( '/\bs\(\s*"([a-z_]+)"/', $printer_src, $sent_match );
$sent = array_values( array_unique( $sent_match[1] ) );
sort( $sent );

// 2. Events the receiver accepts.
preg_match( '/in_array\(\s*\$frl_event,\s*array\(([^)]*)\)/s', $receiver_src, $allow_match );
preg_match_all( "/'([a-z_]+)'/", $allow_match[1] ?? '', $allow_names );
$allowed = $allow_names[1]; // source order = the receiver's own order.
$sorted  = $allowed;
sort( $sorted );

frl_test_ok( array() !== $sent, 'printer emits at least one event' );
frl_test_ok( array() !== $allowed, 'receiver declares an allowlist' );
frl_test_same( $sorted, $sent, 'receiver allowlist and printed events are the same set' );

$sent_only = array_diff( $sent, $sorted );
frl_test_ok( array() === $sent_only, 'no printed event is silently dropped by the receiver: ' . implode( ', ', $sent_only ) );
$allowed_only = array_diff( $sorted, $sent );
frl_test_ok( array() === $allowed_only, 'no allowed event is dead code: ' . implode( ', ', $allowed_only ) );

// 3. Columns: FRL_RUM_COLUMNS is the header contract.
preg_match( '/FRL_RUM_COLUMNS\s*=\s*array\((.*?)\);/s', $receiver_src, $col_match );
preg_match_all( "/'([a-z_]+)'/", $col_match[1] ?? '', $col_names );
$columns = $col_names[1];
frl_test_ok( count( $columns ) > 10, 'FRL_RUM_COLUMNS parsed (' . count( $columns ) . ' columns)' );
frl_test_same( count( $columns ), count( array_unique( $columns ) ), 'column names are unique' );

// 4. Docs tables must list the same events and columns, in order.
preg_match_all( '/^\| `([a-z_]+)` \|/m', $docs_src, $row_match );
$rows      = $row_match[1];
$col_start = array_search( 'time_utc', $rows, true );
frl_test_ok( false !== $col_start, 'docs have a column table starting at time_utc' );

if ( false !== $col_start ) {
	$doc_events = array_slice( $rows, 0, $col_start );
	// Later tables (FRL_MODE flags) follow, so only compare the first N rows.
	$doc_columns = array_slice( $rows, $col_start, count( $columns ) );
	frl_test_same( $allowed, $doc_events, 'docs events table matches the allowlist, in order' );
	frl_test_same( $columns, $doc_columns, 'docs column table matches FRL_RUM_COLUMNS, in order' );
}

// 5. Behaviour: the receiver writes a header and a row with exactly those columns.
$tmp   = sys_get_temp_dir() . '/frl-rum-contract-' . getmypid();
$tree  = $tmp . '/wp-content/plugins/fralenuvole/core/debugger/rum';
$logs  = $tmp . '/wp-content/uploads/fralenuvole/logs';
$cases = array();

/**
 * Runs one receiver request in a child process and returns its log directory.
 *
 * @param string $tmp  Temp root.
 * @param bool   $armed Whether the kill-switch marker exists.
 * @param array  $post Post payload.
 * @return string
 */
function frl_test_rum_request( string $tmp, bool $armed, array $post ): string {
	$tree = $tmp . '/wp-content/plugins/fralenuvole/core/debugger/rum';
	$logs = $tmp . '/wp-content/uploads/fralenuvole/logs';

	if ( is_dir( $logs ) ) {
		foreach ( (array) glob( $logs . '/*' ) as $f ) {
			unlink( $f );
		}
	} else {
		mkdir( $logs, 0777, true );
	}
	if ( $armed ) {
		touch( $logs . '/beacon.on' );
	}
	copy( FRL_TEST_RUM_RECEIVER, $tree . '/rum-receiver.php' );

	$runner = $tmp . '/case.php';
	file_put_contents(
		$runner,
		'<?php $_SERVER["HTTP_USER_AGENT"]="Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) AppleWebKit/605.1.15 Safari/604.1";'
		. '$_SERVER["REMOTE_ADDR"]="203.0.113.9"; $_COOKIE=array(); $_POST=' . var_export( $post, true ) . '; $_GET=array();'
		. 'require ' . var_export( $tree . '/rum-receiver.php', true ) . ';'
	);
	exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $runner ) . ' 2>&1' );

	return $logs;
}

if ( ! is_dir( $tree ) ) {
	mkdir( $tree, 0777, true );
}
define( 'FRL_TEST_RUM_RECEIVER', $receiver );

// 5a. Disabled: the kill switch must stop the receiver before it touches the filesystem.
$empty = frl_test_rum_request( $tmp, false, array( 'e' => 'started' ) );
frl_test_same( array(), (array) glob( $empty . '/*' ), 'kill switch off writes nothing' );

// 5b. Armed: header + one row, aligned with FRL_RUM_COLUMNS.
$armed_logs = frl_test_rum_request(
	$tmp,
	true,
	array(
		'e'     => 'submit_blocked',
		'v'     => 'abc123',
		'p'     => '/contact/',
		'field' => 'email',
		'msg'   => 'Please enter an email address',
		't'     => '1234',
	)
);
$files      = (array) glob( $armed_logs . '/beacon-*.tsv' );
frl_test_same( 1, count( $files ), 'armed request creates one daily file' );

$lines = $files ? file( $files[0], FILE_IGNORE_NEW_LINES ) : array();
frl_test_same( 2, count( $lines ), 'file holds a header and one row' );
frl_test_same( implode( "\t", $columns ), $lines[0] ?? '', 'header equals FRL_RUM_COLUMNS' );

$fields = isset( $lines[1] ) ? explode( "\t", $lines[1] ) : array();
frl_test_same( count( $columns ), count( $fields ), 'row has exactly one field per column' );
frl_test_same( 'submit_blocked', $fields[1] ?? '', 'event lands in the event column' );
frl_test_same( 'errors_dropped', $columns[ count( $columns ) - 1 ], 'last column is errors_dropped' );
$field_at = array_search( 'field', $columns, true );
frl_test_same( 'email', false !== $field_at ? ( $fields[ $field_at ] ?? '' ) : '', 'field value lands in the field column' );
frl_test_ok( 1 === preg_match( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $fields[0] ?? '' ), 'timestamp column is ISO-8601 UTC' );

// 7. New columns: derived values and the whitelists that keep crafted input out.
$new_logs = frl_test_rum_request(
	$tmp,
	true,
	array(
		'e'    => 'vitals',
		'v'    => 'vit0',
		'p'    => '/contact/',
		't'    => '1500',
		'r'    => (string) ( time() - 3600 ),
		'ad'   => '1',
		'cid'  => 'fbclid',
		'ttfb' => '820',
		'dns'  => '',
		'tls'  => '90',
		'dr'   => '2',
	)
);
$new_files = (array) glob( $new_logs . '/beacon-*.tsv' );
$new_lines = $new_files ? file( $new_files[0], FILE_IGNORE_NEW_LINES ) : array();
$new_row   = explode( "\t", $new_lines[1] ?? '' );
$by_name   = array_combine( $columns, array_pad( $new_row, count( $columns ), '' ) );
frl_test_same( '820', $by_name['ttfb'], 'ttfb lands in its column' );
frl_test_same( '', $by_name['dns'], 'an empty timing part stays empty' );
frl_test_same( '', $by_name['redirect'], 'a missing timing part stays empty' );
frl_test_same( '2', $by_name['errors_dropped'], 'dropped error count lands in its column' );
frl_test_same( 'fbclid', $by_name['click_id'], 'click id name lands in its column' );
frl_test_ok( (int) $by_name['page_age'] >= 3500, 'page age = receive time - render time - elapsed (' . $by_name['page_age'] . ')' );
$bad_logs  = frl_test_rum_request( $tmp, true, array( 'e' => 'vitals', 'v' => 'hack0', 'cid' => 'evil', 'es' => 'bogus' ) );
$bad_files = (array) glob( $bad_logs . '/beacon-*.tsv' );
$bad_lines = $bad_files ? file( $bad_files[0], FILE_IGNORE_NEW_LINES ) : array();
$bad_by    = array_combine( $columns, array_pad( explode( "\t", $bad_lines[1] ?? '' ), count( $columns ), '' ) );
frl_test_same( '', $bad_by['click_id'], 'an unknown click id is rejected' );
frl_test_same( '', $bad_by['error_source'], 'an unknown error source is rejected' );
frl_test_same( '', $bad_by['page_age'], 'no render time means no page age' );

// Zero and negative numbers are not measurements: they stay empty, so "unknown" never reads as "none".
$zero_logs  = frl_test_rum_request( $tmp, true, array( 'e' => 'vitals', 'v' => 'zero0', 'dr' => '0', 'ttfb' => '0', 'tls' => '-5', 'r' => '0' ) );
$zero_files = (array) glob( $zero_logs . '/beacon-*.tsv' );
$zero_lines = $zero_files ? file( $zero_files[0], FILE_IGNORE_NEW_LINES ) : array();
$zero_by    = array_combine( $columns, array_pad( explode( "\t", $zero_lines[1] ?? '' ), count( $columns ), '' ) );
frl_test_same( '', $zero_by['errors_dropped'], 'a zero dropped count stays empty' );
frl_test_same( '', $zero_by['ttfb'], 'a zero timing stays empty' );
frl_test_same( '', $zero_by['tls'], 'a negative timing stays empty' );
frl_test_same( '', $zero_by['page_age'], 'a zero render time means no page age' );

// 8. An unlisted event writes nothing.
$rejected = frl_test_rum_request( $tmp, true, array( 'e' => 'bogus_event' ) );
frl_test_same( array(), (array) glob( $rejected . '/beacon-*.tsv' ), 'unlisted event is rejected' );

// Cleanup.
foreach ( (array) glob( $tmp . '/wp-content/uploads/fralenuvole/logs/*' ) as $f ) {
	unlink( $f );
}
@unlink( $tmp . '/case.php' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

frl_test_done();
