<?php

/**
 * WS Form — External footer config
 *
 * Serves WS Form's inline footer config (wsf_form_json_config + form JSON, ~150 KB)
 * as a content-hashed static file, so it leaves the HTML and is cached by browsers and the edge.
 * parse_variables (render-time values: dates, page, URL, user) stays inline, so the file
 * changes only when forms, settings or WS Form change.
 *
 * @package Fralenuvole
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( frl_is_valid_frontend_page_request() ) {
	add_filter(
		'script_loader_tag',
		'frl_wsf_externalize_footer',
		20,
		2
	);
}

/**
 * Replaces WS Form's inline footer script with an inline parse_variables script and a src
 * to the hashed file. Order is kept with or without defer. Any failure returns the original tag.
 *
 * @param mixed  $tag    Script tag HTML (another filter may have broken it).
 * @param string $handle Script handle ('ws-form-footer' for WS Form's footer config).
 * @return mixed Script tag HTML, or $tag unchanged.
 */
function frl_wsf_externalize_footer( $tag, string $handle ) {
	if ( 'ws-form-footer' !== $handle || ! is_string( $tag ) ) {
		return $tag;
	}

	// Logged-in output is never page-cached and may carry per-user data.
	if ( is_user_logged_in() ) {
		return $tag;
	}

	if ( ! preg_match( '#^\s*<script\b([^>]*)>(.*)</script>\s*$#is', $tag, $m ) || false !== stripos( $m[1], ' src=' ) ) {
		return $tag;
	}

	$location = frl_get_uploads_location( 'js' );
	if ( null === $location ) {
		return $tag;
	}

	$split = frl_wsf_split_footer( $m[2] );
	if ( null === $split ) {
		return $tag;
	}

	$file = frl_wsf_footer_file( $location['dir'], md5( $split['static'] ) );

	if ( ! frl_wsf_ensure_footer_file( $location['dir'], $file, $split['static'] ) ) {
		return $tag;
	}

	$attrs = preg_replace( '#\s(?:type|id)=(["\'])[^"\']*\1#i', '', $m[1] );

	return sprintf(
		'<script id="%1$s"%2$s data-plugin="%3$s" data-noptimize="1">%4$s</script>' . "\n" .
		'<script id="%5$s"%2$s src="%6$s" data-plugin="%3$s" data-noptimize="1"></script>' . "\n",
		esc_attr( FRL_PREFIX . '-wsform-footer-vars-js' ),
		$attrs,
		esc_attr( FRL_NAME ),
		$split['vars'],
		esc_attr( FRL_PREFIX . '-wsform-footer-inline-js' ),
		esc_url( $location['url'] . '/' . basename( $file ) )
	);
}

/**
 * Splits WS Form's footer JS into render-time values (inline) and static config (file).
 *
 * @param string $js Footer script content.
 * @return array{vars: string, static: string}|null Null if the config is not found or not parseable.
 */
function frl_wsf_split_footer( string $js ): ?array {
	$prefix = 'window.wsf_form_json_config=';
	$start  = strpos( $js, $prefix );
	$end    = false !== $start ? strpos( $js, ';window.wsf_form_json=', $start ) : false;

	if ( false === $end ) {
		return null;
	}

	$from   = $start + strlen( $prefix );
	$config = json_decode( substr( $js, $from, $end - $from ) );

	if ( ! is_object( $config ) || ! isset( $config->parse_variables ) ) {
		return null;
	}

	// JSON_HEX_TAG: values may contain HTML; keeps '</script>' from closing the inline tag.
	$vars = wp_json_encode( $config->parse_variables, JSON_HEX_TAG );
	$config->parse_variables = null; // Keeps key order; set from the inline script.
	$static = wp_json_encode( $config );

	if ( false === $vars || false === $static ) {
		return null;
	}

	return array(
		'vars'   => 'window.frl_wsf_pv=' . $vars . ';',
		'static' => substr( $js, 0, $from ) . $static . substr( $js, $end ) . "\nwindow.wsf_form_json_config.parse_variables=window.frl_wsf_pv;\n",
	);
}

/**
 * Ensures the hashed footer file exists; writes it atomically if missing.
 * Touches a file in use once a day so pruning never removes it.
 *
 * @param string $dir  Target directory.
 * @param string $file Target file path.
 * @param string $js   File contents.
 * @return bool True if the file is available.
 */
function frl_wsf_ensure_footer_file( string $dir, string $file, string $js ): bool {
	$mtime = is_file( $file ) ? filemtime( $file ) : false; // Same stat call: PHP caches it.

	if ( false !== $mtime ) {
		if ( $mtime < time() - DAY_IN_SECONDS ) {
			@touch( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		return true;
	}

	if ( ! wp_mkdir_p( $dir ) ) {
		return false;
	}

	$tmp = $file . '.' . wp_generate_password( 8, false ) . '.tmp';
	if ( false === file_put_contents( $tmp, $js, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return false;
	}

	// A concurrent request may have created the same file: identical content, either wins.
	if ( ! @rename( $tmp, $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return is_file( $file );
	}

	frl_wsf_prune_footer_files( $dir, $file );

	return true;
}

/**
 * Deletes footer files unused for 30 days (well beyond the 7-day edge cache TTL).
 * Runs only when a new file is written.
 *
 * @param string $dir  Directory holding footer files.
 * @param string $keep File to keep.
 */
function frl_wsf_prune_footer_files( string $dir, string $keep ): void {
	$cutoff = time() - 30 * DAY_IN_SECONDS;

	foreach ( (array) glob( frl_wsf_footer_file( $dir, '*' ) ) as $old ) {
		if ( $old !== $keep && @filemtime( $old ) < $cutoff ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $old ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}
}

/**
 * Footer file path for a content hash ('*' gives the glob pattern for pruning).
 *
 * @param string $dir  Directory holding footer files.
 * @param string $hash Content hash, or '*'.
 * @return string File path.
 */
function frl_wsf_footer_file( string $dir, string $hash ): string {
	return $dir . '/' . FRL_PREFIX . '-wsf-footer-' . $hash . '.js';
}
