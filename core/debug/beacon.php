<?php

/**
 * Page-load beacon (printer).
 *
 * Prints a ~1 KB inline script as the first thing in <head> reporting, per page view:
 * started (head ran), finished (DOM parsed; c=1 if the end marker arrived, c=0 if the HTML was cut),
 * hidden (tab/app switched before finished; often finishes later), abandoned (page left before finished).
 * Receiver: beacon-receiver.php.
 *
 * @package Fralenuvole
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Right after frl_add_critical_css (-999), which ends at ~2.8 KB: stays well within the first 16 KB.
add_action( 'wp_head', 'frl_debug_beacon_print_head', -998, 0 );
// Last thing in the body: its presence proves the whole HTML arrived.
add_action( 'wp_footer', 'frl_debug_beacon_print_end_marker', PHP_INT_MAX, 0 );

// Turning the option off removes the receiver's marker file (see beacon-receiver.php).
add_action( 'update_option_' . frl_prefix( 'debugger_beacon' ), 'frl_debug_beacon_sync_marker', 10, 0 );

/**
 * Creates or deletes the marker that allows beacon-receiver.php to write, to match the option.
 */
function frl_debug_beacon_sync_marker() {
	$logs = frl_get_uploads_location( 'logs' );
	if ( null === $logs ) {
		return;
	}
	$marker = $logs['dir'] . '/beacon.on';
	if ( frl_get_option( 'debugger_beacon', true ) ) {
		if ( ! is_file( $marker ) && ( is_dir( $logs['dir'] ) || wp_mkdir_p( $logs['dir'] ) ) ) {
			@touch( $marker ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	} elseif ( is_file( $marker ) ) {
		wp_delete_file( $marker );
	}
}

/**
 * Whether the beacon runs on this request: option on, frontend page view, visitor not logged in.
 *
 * @return bool
 */
function frl_debug_beacon_is_active(): bool {
	static $active = null;
	if ( null === $active ) {
		$active = frl_get_option( 'debugger_beacon' ) && frl_is_valid_frontend_page_request() && ! is_user_logged_in();
		if ( $active ) {
			frl_debug_beacon_sync_marker(); // Recreates the marker if missing (e.g. after deploy).
		}
	}
	return $active;
}

/**
 * Prints the inline beacon. Wrapped in noptimize so Autoptimize does not defer or move it.
 */
function frl_debug_beacon_print_head() {
	if ( ! frl_debug_beacon_is_active() ) {
		return;
	}

	$url = esc_url( FRL_DIR_URL . 'core/debug/beacon-receiver.php' );

	// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- static script, URL escaped above.
	echo '<!--noptimize--><script data-noptimize="1">(function(){var u="' . $url . '",'
		. 'v=Math.random().toString(36).slice(2,10),d=0,a=0,'
		. 'q=/[?&](gclid|gbraid|wbraid|yclid)=/.test(location.search)?1:0,'
		. 'n=(navigator.connection&&navigator.connection.effectiveType)||"",'
		. 'P=window.performance&&performance.now?function(){return Math.round(performance.now())}:function(){return -1};'
		. 'function s(e,x){var b="e="+e+"&v="+v+"&p="+encodeURIComponent(location.pathname)+"&ad="+q+"&n="+n+"&t="+P()+(x||"");'
		. 'try{if(navigator.sendBeacon&&navigator.sendBeacon(u,new Blob([b],{type:"application/x-www-form-urlencoded"})))return}catch(_){}'
		. '(new Image()).src=u+"?"+b}'
		. 's("started");'
		. 'document.addEventListener("DOMContentLoaded",function(){if(d)return;d=1;s("finished","&c="+(document.getElementById("frl-beacon-end")?1:0))});'
		. 'var h=0;document.addEventListener("visibilitychange",function(){if(document.visibilityState==="hidden"&&!d&&!h){h=1;s("hidden")}});'
		. 'addEventListener("pagehide",function(){if(!d&&!a){a=1;s("abandoned")}})'
		. '})();</script><!--/noptimize-->' . "\n";
	// phpcs:enable
}

/**
 * Prints the end-of-page marker checked by the "finished" event.
 */
function frl_debug_beacon_print_end_marker() {
	if ( ! frl_debug_beacon_is_active() ) {
		return;
	}
	echo '<i id="frl-beacon-end" hidden></i>' . "\n";
}
