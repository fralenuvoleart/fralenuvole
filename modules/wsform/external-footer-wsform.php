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
	add_filter( 'autoptimize_filter_js_dontmove', 'frl_wsf_autoptimize_exclude_defer', 10, 1 );
}

/**
 * Keeps Autoptimize from deferring (and minifying) the footer file.
 * Deferred, it ran after WS Form's deferred scripts, so WS Form found no config and
 * fell back to REST (/ws-form/v1/form/<id>/full → 401). Not deferred, it runs during
 * parsing like the original inline script, before WS Form's deferred scripts.
 *
 * @param array $dontmove Autoptimize "don't move / don't defer" patterns.
 * @return array
 */
function frl_wsf_autoptimize_exclude_defer( $dontmove ) {
	$dontmove   = (array) $dontmove;
	$dontmove[] = basename( frl_wsf_footer_file( '', '' ), '.js' );
	return $dontmove;
}

/**
 * Replaces WS Form's inline footer script with an inline parse_variables script and a src
 * to the hashed file (not deferred: see frl_wsf_autoptimize_exclude_defer()). Any failure returns the original tag.
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
		'<script id="%5$s"%2$s src="%6$s" data-plugin="%3$s" data-noptimize="1" onerror="window.frl_wsf_footer_failed=1"></script>' . "\n",
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
 * Two kinds of values change from page to page and stay inline:
 * - parse_variables (dates, page, URL, user);
 * - field default values in the form JSON (for example a hidden "service" field that is
 *   "Webpage" on the home page and "Personal Bank" on the bank account page).
 * Without the second, every page produced its own file, so a first-time visitor downloaded
 * the same bytes as before and nothing was shared between pages. With it, one file serves
 * every page of a language.
 *
 * @param string $js Footer script content.
 * @return array{vars: string, static: string}|null Null if the config is not found or not parseable.
 */
function frl_wsf_split_footer( string $js ): ?array {
	if ( ! preg_match( '#window\.wsf_form_json_config\s*=\s*#', $js, $s, PREG_OFFSET_CAPTURE ) ) {
		return null;
	}
	$from = $s[0][1] + strlen( $s[0][0] );

	if ( ! preg_match( '#;\s*window\.wsf_form_json\s*=#', $js, $e, PREG_OFFSET_CAPTURE, $from ) ) {
		return null;
	}
	$end = $e[0][1];

	$config = json_decode( substr( $js, $from, $end - $from ) );

	if ( ! is_object( $config ) || ! isset( $config->parse_variables ) ) {
		return null;
	}

	// JSON_HEX_TAG: values may contain HTML; keeps '</script>' from closing the inline tag.
	$vars                    = wp_json_encode( $config->parse_variables, JSON_HEX_TAG );
	$config->parse_variables = null; // Keeps key order; set from the inline script.
	$static                  = wp_json_encode( $config );

	if ( false === $vars || false === $static ) {
		return null;
	}

	$forms = frl_wsf_split_form_defaults( substr( $js, $end ) );
	if ( null === $forms ) {
		return null;
	}

	$defaults = wp_json_encode( (object) $forms['defaults'], JSON_HEX_TAG );
	if ( false === $defaults ) {
		return null;
	}

	return array(
		'vars'   => 'window.frl_wsf_pv=' . $vars . ';window.frl_wsf_fd=' . $defaults . ';',
		'static' => substr( $js, 0, $from ) . $static . $forms['js'] .
			"\nwindow.wsf_form_json_config.parse_variables=window.frl_wsf_pv;\n" .
			frl_wsf_restore_defaults_js() . "\n",
	);
}

/**
 * Takes every non-empty field default_value out of each window.wsf_form_json[ID] = {...};
 * statement and blanks it, so the remaining form JSON is the same on every page.
 *
 * @param string $js The part of the footer script that holds the form JSON.
 * @return array{js: string, defaults: array}|null Null if a form object cannot be parsed.
 */
function frl_wsf_split_form_defaults( string $js ): ?array {
	$defaults = array();
	$out      = '';
	$pos      = 0;

	while ( preg_match( '#window\.wsf_form_json\[(\d+)\]\s*=\s*#', $js, $m, PREG_OFFSET_CAPTURE, $pos ) ) {
		$start = $m[0][1] + strlen( $m[0][0] );
		$stop  = frl_wsf_json_object_end( $js, $start );
		if ( null === $stop ) {
			return null;
		}

		$form = json_decode( substr( $js, $start, $stop - $start ) );
		if ( ! is_object( $form ) ) {
			return null;
		}

		$found = array();
		frl_wsf_take_defaults( $form, $found );

		$encoded = wp_json_encode( $form );
		if ( false === $encoded ) {
			return null;
		}

		if ( $found ) {
			$defaults[ $m[1][0] ] = (object) $found;
		}

		$out .= substr( $js, $pos, $start - $pos ) . $encoded;
		$pos  = $stop;
	}

	return array(
		'js'       => $out . substr( $js, $pos ),
		'defaults' => $defaults,
	);
}

/**
 * Walks a decoded form and moves non-empty field default values into $found (field id => value).
 *
 * @param mixed $node  Part of the decoded form.
 * @param array $found Collected defaults.
 */
function frl_wsf_take_defaults( $node, array &$found ): void {
	if ( is_array( $node ) ) {
		foreach ( $node as $child ) {
			frl_wsf_take_defaults( $child, $found );
		}
		return;
	}
	if ( ! is_object( $node ) ) {
		return;
	}

	if ( isset( $node->id, $node->type, $node->meta ) && is_object( $node->meta )
		&& isset( $node->meta->default_value ) && is_string( $node->meta->default_value )
		&& '' !== $node->meta->default_value
	) {
		$found[ (string) $node->id ] = $node->meta->default_value;
		$node->meta->default_value   = '';
	}

	foreach ( get_object_vars( $node ) as $key => $child ) {
		if ( 'meta' !== $key && ( is_array( $child ) || is_object( $child ) ) ) {
			frl_wsf_take_defaults( $child, $found );
		}
	}
}

/**
 * Position just after the JSON object that starts at $start (strings and escapes aware).
 *
 * @param string $js    Script text.
 * @param int    $start Offset of the opening brace.
 * @return int|null Offset after the closing brace, or null.
 */
function frl_wsf_json_object_end( string $js, int $start ): ?int {
	$len   = strlen( $js );
	$depth = 0;
	$in    = false;
	for ( $i = $start; $i < $len; $i++ ) {
		$c = $js[ $i ];
		if ( $in ) {
			if ( '\\' === $c ) {
				++$i;
			} elseif ( '"' === $c ) {
				$in = false;
			}
			continue;
		}
		if ( '"' === $c ) {
			$in = true;
		} elseif ( '{' === $c ) {
			++$depth;
		} elseif ( '}' === $c ) {
			--$depth;
			if ( 0 === $depth ) {
				return $i + 1;
			}
		}
	}
	return null;
}

/**
 * JS (in the static file) that puts the page's field defaults back before WS Form reads the JSON.
 *
 * @return string
 */
function frl_wsf_restore_defaults_js(): string {
	return '(function(d,j){if(!d||!j)return;function w(o,m){var k;if(!o||typeof o!=="object")return;if(Array.isArray(o)){for(k=0;k<o.length;k++)w(o[k],m);return}' .
		'if(o.meta&&o.type&&o.id!==undefined&&Object.prototype.hasOwnProperty.call(m,o.id))o.meta.default_value=m[o.id];' .
		'for(k in o)if(k!=="meta"&&Object.prototype.hasOwnProperty.call(o,k))w(o[k],m)}' .
		'for(var f in d)if(Object.prototype.hasOwnProperty.call(d,f)&&j[f])w(j[f],d[f])})(window.frl_wsf_fd,window.wsf_form_json);';
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
