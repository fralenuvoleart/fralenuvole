<?php
/**
 * Page-load RUM beacon receiver. Standalone (no WordPress load) so each hit costs a few ms.
 * Appends one tab-separated line per event to uploads/fralenuvole/logs/beacon-YYYY-MM-DD.tsv, kept 30 days.
 * File names keep the `beacon-` prefix: the kinsta-logs skill and the existing files depend on it.
 * The folder must match frl_get_uploads_location( 'logs' ) (default uploads location).
 *
 * Each new daily file starts with a header row naming the columns (see FRL_RUM_COLUMNS).
 * Column meanings and how to read the log: docs/CORE-DEBUGGER.md.
 *
 * Loaded exactly once per request: it declares functions and a constant, so it must be included
 * with `require_once`.
 *
 * @package Fralenuvole
 */

header( 'Cache-Control: no-store' );
header( 'X-Robots-Tag: noindex' );
http_response_code( 204 );

$frl_in = ! empty( $_POST ) ? $_POST : $_GET; // phpcs:ignore WordPress.Security.NonceVerification -- anonymous telemetry, no state change.

$frl_event = isset( $frl_in['e'] ) && is_string( $frl_in['e'] ) ? $frl_in['e'] : '';
if ( ! in_array( $frl_event, array( 'started', 'finished', 'hidden', 'abandoned', 'js_error', 'vitals', 'form_start', 'form_typed', 'submit_attempt', 'submit_ok', 'submit_blocked', 'submit_failed', 'submit_unresolved' ), true ) ) {
	exit;
}

$frl_ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';

// Crawlers and headless renderers still run the beacon; reject them so they cost no disk write.
// Tokens are anchored (`bot/`, `bot;`, `bot)`, `bot-`, named crawlers, `spider`, …), never bare
// `bot`/`yandex`/`baidu`/`sogou`, because real browsers carry them (CUBOT phones, YandexSearch,
// baiduboxapp, SogouMobileBrowser).
if ( preg_match( '#(?:apple|bing|bytes|dot|duckduck|google|gpt|msn|petal|semrush|ahrefs|yandex)bot|bot[-/;)]|spider|crawl|slurp|scrap|facebookexternalhit|yisou|mj12|dataprovider|preview|headless|lighthouse|pagespeed|googleother|google-inspectiontool|adsbot|mediapartners|feedfetcher|yandex\.com/bots|bingpreview#i', $frl_ua ) ) {
	exit;
}

// <wp-content>/uploads/fralenuvole/logs, from core/debugger/rum/ (5 levels below wp-content).
$frl_dir = dirname( __DIR__, 5 ) . '/uploads/fralenuvole/logs';

// Kill switch: rum.php creates this marker while the RUM option is on and deletes it when turned off,
// so cached pages that still carry the inline script stop writing as soon as the option is off.
// Checked before the mkdir below, so a disabled feature never touches the filesystem.
if ( ! is_file( $frl_dir . '/beacon.on' ) ) {
	exit;
}
if ( ! is_dir( $frl_dir ) && ! @mkdir( $frl_dir, 0755, true ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
	exit;
}

$frl_file = $frl_dir . '/beacon-' . gmdate( 'Y-m-d' ) . '.tsv';

// Column names, written as the first row of each daily file. Meanings: docs/CORE-DEBUGGER.md.
const FRL_RUM_COLUMNS = array(
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
	'stack',
	'lcp',
	'cls',
	'inp',
	'fcp',
	'utm_source',
	'viewport',
	'field',
	'ttfb',
	'redirect',
	'dns',
	'connect',
	'tls',
	'wait',
	'page_age',
	'click_id',
	'error_source',
	'errors_dropped',
	'submit_why',
	'form_ref',
	'input_type',
	'build',
);

// Hard cap per day so a flood cannot fill the disk.
if ( is_file( $frl_file ) && filesize( $frl_file ) > 20 * 1024 * 1024 ) {
	exit;
}

/**
 * Keeps a short value without tabs or newlines; spaces survive, so `utm_source=news letter` stays readable.
 *
 * @param string $value Raw value.
 * @param int    $max   Max length.
 * @return string
 */
function frl_rum_clean( $value, $max ) {
	if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
		return '';
	}
	return substr( trim( preg_replace( '/[^\w\-\/\.% ]/', '', (string) $value ) ), 0, $max );
}

/**
 * Reads a millisecond/count parameter as a clamped non-negative integer; empty when absent or out of range.
 *
 * @param array  $in         Request array (POST or GET).
 * @param string $key        Parameter name.
 * @param bool   $allow_zero Whether 0 is accepted as a valid value.
 * @param int    $max        Upper clamp bound (default 1 hour in ms).
 * @return string
 */
function frl_rum_int( $in, $key, $allow_zero = false, $max = 3600000 ) {
	if ( ! isset( $in[ $key ] ) || ! is_scalar( $in[ $key ] ) || '' === (string) $in[ $key ] ) {
		return '';
	}
	$n = (int) $in[ $key ];
	if ( $allow_zero ? ( $n < 0 ) : ( $n <= 0 ) ) {
		return '';
	}
	return (string) min( $n, $max );
}

/**
 * Cuts a string at a UTF-8-safe boundary (uses mbstring when available, falls back without it).
 *
 * @param string $value Raw value.
 * @param int    $max   Max length in bytes.
 * @return string
 */
function frl_rum_utf8_cut( $value, $max ) {
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
function frl_rum_free_text( $value, $max ) {
	if ( ! is_string( $value ) ) {
		return '';
	}
	$value = preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $value );
	if ( isset( $value[0] ) && false !== strpos( '=+-@', $value[0] ) ) {
		$value = "'" . $value;
	}
	return frl_rum_utf8_cut( $value, $max );
}

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
$frl_country = isset( $_SERVER['GEOIP_COUNTRY_CODE'] ) ? frl_rum_clean( $_SERVER['GEOIP_COUNTRY_CODE'], 2 ) : '';

// Ad-click id name, whitelisted: the printer sends a name, never the value.
$frl_click = isset( $frl_in['cid'] ) && is_string( $frl_in['cid'] ) ? strtolower( $frl_in['cid'] ) : '';
if ( ! in_array( $frl_click, array( 'gclid', 'gbraid', 'wbraid', 'yclid', 'fbclid' ), true ) ) {
	$frl_click = '';
}

// Script that threw: own, inline (the page itself) or third (another origin).
$frl_es = isset( $frl_in['es'] ) && is_string( $frl_in['es'] ) ? $frl_in['es'] : '';
if ( ! in_array( $frl_es, array( 'own', 'inline', 'third' ), true ) ) {
	$frl_es = '';
}

// How the form was first filled: a real keystroke or paste, or an autofill that arrives with no
// beforeinput event at all (`none`).
$frl_it = isset( $frl_in['it'] ) && is_string( $frl_in['it'] ) ? $frl_in['it'] : '';
if ( ! in_array( $frl_it, array( 'none', 'insertText', 'insertFromPaste', 'insertReplacementText', 'insertCompositionText', 'insertFromDrop', 'insertFromYank', 'insertLineBreak' ), true ) ) {
	$frl_it = '';
}

// Printer build: the printer file's modification time in base36, so rows written by a cached old script can
// be told from fresh ones without relying on the deploy time.
$frl_build = isset( $frl_in['b'] ) && is_string( $frl_in['b'] ) && preg_match( '/^[a-z0-9]{1,8}$/', $frl_in['b'] ) ? $frl_in['b'] : '';

// Why a submit ended: browser blocked it, the client refused it, the request failed, the server refused it,
// or a plain form passed the browser's own validation (native).
$frl_why = isset( $frl_in['why'] ) && is_string( $frl_in['why'] ) ? $frl_in['why'] : '';
if ( ! in_array( $frl_why, array( 'browser', 'client', 'server', 'error', 'native' ), true ) ) {
	$frl_why = '';
}

// Page age: receive time minus the printer's render time, minus the ms the visitor spent before sending.
// Both clocks are the server's, so a cached page shows a large age and a fresh one 0-2 s. `t = -1` means
// the browser cannot measure, and is treated as 0.
$frl_age    = '';
$frl_has_rt = isset( $frl_in['r'] ) && is_scalar( $frl_in['r'] ) && (int) $frl_in['r'] > 0;
if ( $frl_has_rt && in_array( $frl_event, array( 'started', 'vitals' ), true ) ) {
	$frl_t   = isset( $frl_in['t'] ) && is_scalar( $frl_in['t'] ) ? max( 0, (int) $frl_in['t'] ) : 0;
	$frl_age = (string) max( 0, time() - (int) $frl_in['r'] - (int) round( $frl_t / 1000 ) );
}

$frl_line = implode(
	"\t",
	array(
		gmdate( 'Y-m-d\TH:i:s\Z' ),
		$frl_event,
		frl_rum_clean( isset( $frl_in['v'] ) ? $frl_in['v'] : '', 10 ),
		$frl_country,
		$frl_browser,
		preg_match( '/Mobi|Android|iPhone/', $frl_ua ) ? '1' : '0',
		isset( $frl_in['ad'] ) && '1' === $frl_in['ad'] ? '1' : '0',
		frl_rum_clean( isset( $frl_in['n'] ) ? $frl_in['n'] : '', 8 ),
		( isset( $frl_in['t'] ) && is_scalar( $frl_in['t'] ) ? (string) (int) $frl_in['t'] : '-1' ),
		isset( $frl_in['c'] ) ? ( '1' === $frl_in['c'] ? '1' : '0' ) : '',
		// Kept percent-encoded: decoding would let frl_rum_clean() strip non-ASCII slugs.
		frl_rum_clean( isset( $frl_in['p'] ) ? $frl_in['p'] : '', 600 ),
		// REMOTE_ADDR is the visitor IP on Kinsta (verified on staging, same as CF-Connecting-IP).
		// Deliberate: private diagnostics file; the IP column joins visits with server logs.
		isset( $_SERVER['REMOTE_ADDR'] ) ? preg_replace( '/[^0-9a-fA-F:\.]/', '', (string) $_SERVER['REMOTE_ADDR'] ) : '',
		frl_rum_free_text( $frl_ua, 400 ),
		frl_rum_clean( isset( $_COOKIE['_ga'] ) ? $_COOKIE['_ga'] : '', 60 ),
		frl_rum_clean( isset( $_COOKIE['_clck'] ) && is_string( $_COOKIE['_clck'] ) ? strtok( $_COOKIE['_clck'], '|' ) : '', 40 ),
		frl_rum_clean( isset( $_COOKIE['_channel_reference_id'] ) ? $_COOKIE['_channel_reference_id'] : '', 40 ),
		frl_rum_free_text( isset( $frl_in['msg'] ) ? $frl_in['msg'] : '', 300 ),
		frl_rum_free_text( isset( $frl_in['src'] ) ? $frl_in['src'] : '', 200 ),
		frl_rum_int( $frl_in, 'line', true ),
		frl_rum_free_text( isset( $frl_in['stack'] ) ? $frl_in['stack'] : '', 400 ),
		frl_rum_int( $frl_in, 'lcp', true ),
		frl_rum_clean( isset( $frl_in['cls'] ) ? $frl_in['cls'] : '', 8 ),
		frl_rum_int( $frl_in, 'inp', true ),
		frl_rum_int( $frl_in, 'fcp', true ),
		frl_rum_clean( isset( $frl_in['utm'] ) ? $frl_in['utm'] : '', 50 ),
		frl_rum_int( $frl_in, 'vw', true ),
		frl_rum_clean( isset( $frl_in['field'] ) ? $frl_in['field'] : '', 60 ),
		frl_rum_int( $frl_in, 'ttfb' ),
		frl_rum_int( $frl_in, 'redirect' ),
		frl_rum_int( $frl_in, 'dns' ),
		frl_rum_int( $frl_in, 'connect' ),
		frl_rum_int( $frl_in, 'tls' ),
		frl_rum_int( $frl_in, 'wait' ),
		$frl_age,
		$frl_click,
		$frl_es,
		frl_rum_int( $frl_in, 'dr' ),
		$frl_why,
		frl_rum_clean( isset( $frl_in['ref'] ) ? $frl_in['ref'] : '', 20 ),
		$frl_it,
		$frl_build,
	)
) . "\n";

// Atomic header creation + row append under LOCK_EX prevents UTC-midnight header/row races.
$frl_fp = @fopen( $frl_file, 'a+' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
if ( $frl_fp ) {
	if ( flock( $frl_fp, LOCK_EX ) ) {
		$frl_stat    = fstat( $frl_fp );
		$frl_payload = ( is_array( $frl_stat ) && 0 === (int) $frl_stat['size'] )
			? implode( "\t", FRL_RUM_COLUMNS ) . "\n" . $frl_line
			: $frl_line;
		fwrite( $frl_fp, $frl_payload ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fflush( $frl_fp );
		flock( $frl_fp, LOCK_UN );
	}
	fclose( $frl_fp ); // phpcs:ignore WordPress.WP.AlternativeFunctions
}

// About once per 500 hits, delete beacon files older than 30 days.
try {
	$frl_prune = 1 === random_int( 1, 500 );
} catch ( \Throwable $e ) {
	$frl_prune = false;
}
if ( $frl_prune ) {
	$frl_matches = glob( $frl_dir . '/beacon-*.tsv' );
	if ( is_array( $frl_matches ) ) {
		$frl_cutoff = time() - 30 * 86400;
		foreach ( $frl_matches as $frl_old ) {
			$frl_mtime = @filemtime( $frl_old ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( false !== $frl_mtime && $frl_mtime < $frl_cutoff ) {
				@unlink( $frl_old ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
	}
}
