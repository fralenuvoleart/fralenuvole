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

$frl_event = isset( $frl_in['e'] ) ? (string) $frl_in['e'] : '';
if ( ! in_array( $frl_event, array( 'started', 'finished', 'hidden', 'abandoned' ), true ) ) {
	exit;
}

// <wp-content>/uploads/fralenuvole/logs, from core/debug/ (4 levels below wp-content).
$frl_dir = dirname( __DIR__, 4 ) . '/uploads/fralenuvole/logs';
if ( ! is_dir( $frl_dir ) && ! @mkdir( $frl_dir, 0755, true ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
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
	return substr( preg_replace( '/[^\w\-\/\.%]/', '', (string) $value ), 0, $max );
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
		(string) ( isset( $frl_in['t'] ) ? (int) $frl_in['t'] : -1 ),
		isset( $frl_in['c'] ) ? ( '1' === $frl_in['c'] ? '1' : '0' ) : '',
		frl_beacon_clean( isset( $frl_in['p'] ) ? rawurldecode( $frl_in['p'] ) : '', 200 ),
		// REMOTE_ADDR is the visitor IP on Kinsta (verified on staging, same as CF-Connecting-IP).
		isset( $_SERVER['REMOTE_ADDR'] ) ? preg_replace( '/[^0-9a-fA-F:\.]/', '', (string) $_SERVER['REMOTE_ADDR'] ) : '',
		substr( preg_replace( '/[\t\r\n]+/', ' ', $frl_ua ), 0, 400 ),
		frl_beacon_clean( isset( $_COOKIE['_ga'] ) ? $_COOKIE['_ga'] : '', 60 ),
		frl_beacon_clean( isset( $_COOKIE['_clck'] ) ? strtok( (string) $_COOKIE['_clck'], '|' ) : '', 40 ),
		frl_beacon_clean( isset( $_COOKIE['_channel_reference_id'] ) ? $_COOKIE['_channel_reference_id'] : '', 40 ),
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
