<?php
/**
 * Page-load beacon receiver. Standalone (no WordPress load) so each hit costs a few ms.
 * Appends one tab-separated line per event to uploads/fralenuvole/logs/beacon-YYYY-MM-DD.tsv, kept 30 days.
 * The folder must match frl_get_uploads_location( 'logs' ) (default uploads location).
 *
 * Each new daily file starts with a header row naming the columns (see FRL_BEACON_COLUMNS).
 * Column meanings and how to read the log: docs/PLUGIN-DEBUG.md.
 *
 * @package Fralenuvole
 */

header( 'Cache-Control: no-store' );
header( 'X-Robots-Tag: noindex' );
http_response_code( 204 );

$frl_in = ! empty( $_POST ) ? $_POST : $_GET; // phpcs:ignore WordPress.Security.NonceVerification -- anonymous telemetry, no state change.

$frl_event = isset( $frl_in['e'] ) && is_string( $frl_in['e'] ) ? $frl_in['e'] : '';
if ( ! in_array( $frl_event, array( 'started', 'finished', 'hidden', 'abandoned', 'js_error' ), true ) ) {
	exit;
}

// <wp-content>/uploads/fralenuvole/logs, from core/debug/ (4 levels below wp-content).
$frl_dir = dirname( __DIR__, 4 ) . '/uploads/fralenuvole/logs';
if ( ! is_dir( $frl_dir ) && ! @mkdir( $frl_dir, 0755, true ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
	exit;
}

// Kill switch: beacon.php creates this marker while debugger_beacon is on and deletes it when turned off,
// so cached pages that still carry the inline script stop writing as soon as the option is off.
if ( ! is_file( $frl_dir . '/beacon.on' ) ) {
	exit;
}

$frl_file = $frl_dir . '/beacon-' . gmdate( 'Y-m-d' ) . '.tsv';

// Column names, written as the first row of each daily file. Meanings: docs/PLUGIN-DEBUG.md.
const FRL_BEACON_COLUMNS = array(
	'time_utc',
	'event',
	'view_id',
	'country',
	'browser',
	'mobile',
	'from_ad_click',
	'network_estimate',
	'ms_since_page_open',
	'html_complete',
	'path',
	'ip',
	'user_agent',
	'ga_client_id',
	'clarity_user_id',
	'reference_id',
	'msg',
	'src',
	'line',
);

// 'x' creates the file only if it does not exist yet, so the header is written exactly once.
$frl_new = @fopen( $frl_file, 'x' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
if ( $frl_new ) {
	fwrite( $frl_new, implode( "\t", FRL_BEACON_COLUMNS ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	fclose( $frl_new ); // phpcs:ignore WordPress.WP.AlternativeFunctions
}

// Hard cap per day so a flood cannot fill the disk.
if ( is_file( $frl_file ) && filesize( $frl_file ) > 20 * 1024 * 1024 ) {
	exit;
}

/**
 * Keeps a short value without tabs or newlines.
 *
 * @param string $value Raw value.
 * @param int    $max   Max length.
 * @return string
 */
function frl_beacon_clean( $value, $max ) {
	if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
		return '';
	}
	return substr( preg_replace( '/[^\w\-\/\.%]/', '', (string) $value ), 0, $max );
}

/**
 * Cuts a string at a UTF-8-safe boundary (no mbstring dependency).
 *
 * @param string $value Raw value.
 * @param int    $max   Max length in bytes.
 * @return string
 */
function frl_beacon_utf8_cut( $value, $max ) {
	$value = (string) $value;
	if ( function_exists( 'mb_convert_encoding' ) ) {
		$value = mb_convert_encoding( $value, 'UTF-8', 'UTF-8' ); // scrub invalid bytes
	}
	$value = substr( $value, 0, $max );
	$n     = strlen( $value );
	while ( $n > 0 && false === @preg_match( '//u', $value ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
		--$n;
		$value = substr( $value, 0, $n );
	}
	return $value;
}

/**
 * Cleans a free-text field: strips control characters, guards against spreadsheet formulas,
 * and cuts at a UTF-8-safe boundary.
 *
 * @param mixed $value Raw value.
 * @param int   $max   Max length in bytes.
 * @return string
 */
function frl_beacon_free_text( $value, $max ) {
	if ( ! is_string( $value ) ) {
		return '';
	}
	$value = preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $value );
	if ( isset( $value[0] ) && false !== strpos( '=+-@', $value[0] ) ) {
		$value = "'" . $value;
	}
	return frl_beacon_utf8_cut( $value, $max );
}

$frl_ua      = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
$frl_browser = 'other';
foreach ( array(
	'yandex'  => 'YaBrowser',
	'samsung' => 'SamsungBrowser',
	'edge'    => 'Edg/',
	'opera'   => 'OPR/',
	'inapp'   => 'FBAN|FBAV|Instagram|Telegram|; wv\)',
	'firefox' => 'Firefox/',
	'chrome'  => 'Chrome/|CriOS/',
	'safari'  => 'Safari/',
) as $frl_name => $frl_pattern ) {
	if ( preg_match( '#' . $frl_pattern . '#', $frl_ua ) ) {
		$frl_browser = $frl_name;
		break;
	}
}

// Kinsta IP Geolocation (country level) sets GEOIP_COUNTRY_CODE.
$frl_country = isset( $_SERVER['GEOIP_COUNTRY_CODE'] ) ? frl_beacon_clean( $_SERVER['GEOIP_COUNTRY_CODE'], 2 ) : '';

$frl_line = implode(
	"\t",
	array(
		gmdate( 'Y-m-d\TH:i:s\Z' ),
		$frl_event,
		frl_beacon_clean( isset( $frl_in['v'] ) ? $frl_in['v'] : '', 10 ),
		$frl_country,
		$frl_browser,
		preg_match( '/Mobi|Android|iPhone/', $frl_ua ) ? '1' : '0',
		isset( $frl_in['ad'] ) && '1' === $frl_in['ad'] ? '1' : '0',
		frl_beacon_clean( isset( $frl_in['n'] ) ? $frl_in['n'] : '', 8 ),
		( isset( $frl_in['t'] ) && is_scalar( $frl_in['t'] ) ? (string) (int) $frl_in['t'] : '-1' ),
		isset( $frl_in['c'] ) ? ( '1' === $frl_in['c'] ? '1' : '0' ) : '',
		// Kept percent-encoded: decoding would let frl_beacon_clean() strip non-ASCII slugs.
		frl_beacon_clean( isset( $frl_in['p'] ) ? $frl_in['p'] : '', 600 ),
		// REMOTE_ADDR is the visitor IP on Kinsta (verified on staging, same as CF-Connecting-IP).
		isset( $_SERVER['REMOTE_ADDR'] ) ? preg_replace( '/[^0-9a-fA-F:\.]/', '', (string) $_SERVER['REMOTE_ADDR'] ) : '',
		frl_beacon_free_text( $frl_ua, 400 ),
		frl_beacon_clean( isset( $_COOKIE['_ga'] ) ? $_COOKIE['_ga'] : '', 60 ),
		frl_beacon_clean( isset( $_COOKIE['_clck'] ) && is_string( $_COOKIE['_clck'] ) ? strtok( $_COOKIE['_clck'], '|' ) : '', 40 ),
		frl_beacon_clean( isset( $_COOKIE['_channel_reference_id'] ) ? $_COOKIE['_channel_reference_id'] : '', 40 ),
		frl_beacon_free_text( isset( $frl_in['msg'] ) ? $frl_in['msg'] : '', 300 ),
		frl_beacon_free_text( isset( $frl_in['src'] ) ? $frl_in['src'] : '', 200 ),
		( isset( $frl_in['line'] ) && is_scalar( $frl_in['line'] ) && '' !== (string) $frl_in['line'] ? (string) (int) $frl_in['line'] : '' ),
	)
) . "\n";

@file_put_contents( $frl_file, $frl_line, FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions

// About once per 500 hits, delete beacon files older than 30 days.
if ( 1 === random_int( 1, 500 ) ) {
	foreach ( (array) glob( $frl_dir . '/beacon-*.tsv' ) as $frl_old ) {
		if ( filemtime( $frl_old ) < time() - 30 * 86400 ) {
			@unlink( $frl_old ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}
}
