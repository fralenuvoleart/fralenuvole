<?php

/**
 * WS Form — External footer config
 *
 * Serves WS Form's inline footer config (wsf_form_json_config + form JSON, ~150 KB)
 * as a content-hashed static file, so it leaves the HTML and is cached by browsers and the edge.
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
 * Replaces WS Form's inline footer script with a src to its hashed file.
 * No defer: execution order is unchanged. Any failure returns the original inline tag.
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

	$file = frl_wsf_footer_file( $location['dir'], md5( $m[2] ) );

	if ( ! frl_wsf_ensure_footer_file( $location['dir'], $file, $m[2] ) ) {
		return $tag;
	}

	$attrs = preg_replace( '#\s(?:type|id)=(["\'])[^"\']*\1#i', '', $m[1] );

	return sprintf(
		"<script id=\"%s\"%s src=\"%s\" data-plugin=\"%s\" data-noptimize=\"1\"></script>\n",
		esc_attr( FRL_PREFIX . '-wsform-footer-inline-js' ),
		$attrs,
		esc_url( $location['url'] . '/' . basename( $file ) ),
		esc_attr( FRL_NAME )
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
	$mtime = @filemtime( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

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
