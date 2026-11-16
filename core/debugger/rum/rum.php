<?php

/**
 * Page-load RUM beacon (printer).
 *
 * Prints a ~3.1 KB inline script as the first thing in <head> reporting, per page view:
 * started (head ran), finished (DOM parsed; c=1 if the end marker arrived, c=0 if the HTML was cut),
 * hidden (tab/app switched before finished; often finishes later), abandoned (page left before finished),
 * js_error (uncaught error or rejection, with stack) and vitals (LCP/CLS/INP/FCP when the page is
 * hidden or left). Receiver: rum-receiver.php.
 *
 * @package Fralenuvole
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Right after frl_add_critical_css (-999), which ends at ~2.8 KB: stays well within the first 16 KB.
add_action( 'wp_head', 'frl_debug_rum_print_head', -998, 0 );
// Last thing in the body: its presence proves the whole HTML arrived.
add_action( 'wp_footer', 'frl_debug_rum_print_end_marker', PHP_INT_MAX, 0 );

// Turning the option off removes the receiver's marker file (see rum-receiver.php).
add_action( 'update_option_' . frl_prefix( 'debugger_rum' ), 'frl_debug_rum_sync_marker', 10, 0 );
// Option deletion does not fire update_option, so it needs its own cleanup.
add_action( 'delete_option_' . frl_prefix( 'debugger_rum' ), 'frl_debug_rum_delete_marker', 10, 0 );

/**
 * Creates or deletes the marker that allows rum-receiver.php to write, to match the option.
 */
function frl_debug_rum_sync_marker() {
	$logs = frl_get_uploads_location( 'logs' );
	if ( null === $logs ) {
		return;
	}
	$marker = $logs['dir'] . '/beacon.on';
	if ( frl_get_option( 'debugger_rum', true ) ) {
		if ( ! is_file( $marker ) && ( is_dir( $logs['dir'] ) || wp_mkdir_p( $logs['dir'] ) ) ) {
			@touch( $marker ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	} elseif ( is_file( $marker ) ) {
		wp_delete_file( $marker );
	}
}

/**
 * Deletes the receiver's marker file (option deleted or plugin deactivated).
 */
function frl_debug_rum_delete_marker() {
	$logs = frl_get_uploads_location( 'logs' );
	if ( null === $logs ) {
		return;
	}
	$marker = $logs['dir'] . '/beacon.on';
	if ( is_file( $marker ) ) {
		wp_delete_file( $marker );
	}
}

/**
 * Whether the RUM beacon runs on this request: option on, frontend page view, visitor not logged in.
 *
 * @return bool
 */
function frl_debug_rum_is_active(): bool {
	static $active = null;
	if ( null === $active ) {
		$active = frl_get_option( 'debugger_rum' ) && frl_is_valid_frontend_page_request() && ! is_user_logged_in();
		if ( $active ) {
			frl_debug_rum_sync_marker(); // Recreates the marker if missing (e.g. after deploy).
		}
	}
	return $active;
}

/**
 * Prints the inline beacon. Wrapped in noptimize so Autoptimize does not defer or move it.
 */
function frl_debug_rum_print_head() {
	if ( ! frl_debug_rum_is_active() ) {
		return;
	}

	$url = esc_url( FRL_DIR_URL . 'core/debugger/rum/rum-receiver.php' );

	// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- static script, URL escaped above.
	echo '<!--noptimize--><script data-noptimize="1">(function(){var u="' . $url . '",'
		. 'v=Math.random().toString(36).slice(2,10),d=0,a=0,g=0,'
		. 'q=/[?&](gclid|gbraid|wbraid|yclid)=/.test(location.search)?1:0,'
		. 'n=(navigator.connection&&navigator.connection.effectiveType)||"",'
		. 'P=window.performance&&performance.now?function(){return Math.round(performance.now())}:function(){return -1};'
		. 'function s(e,x){var b="e="+e+"&v="+v+"&p="+encodeURIComponent(location.pathname)+"&ad="+q+"&n="+n+"&t="+P()+(x||"");'
		. 'try{if(navigator.sendBeacon&&navigator.sendBeacon(u,new Blob([b],{type:"application/x-www-form-urlencoded"})))return}catch(_){}'
		. '(new Image()).src=u+"?"+b}'
		. 's("started");'
		. 'var er=0,str=function(v){if(v==null)return "";try{return String(v)}catch(_){return ""}},fix=function(s){var o="",i=0,c;while(i<s.length){c=s.charCodeAt(i);if(c>=0xD800&&c<=0xDBFF&&s.charCodeAt(i+1)>=0xDC00&&s.charCodeAt(i+1)<=0xDFFF){o+=s.charAt(i)+s.charAt(i+1);i+=2}else if(c>=0xD800&&c<=0xDFFF){o+="\uFFFD";i++}else{o+=s.charAt(i);i++}}return o},enc=function(s){try{return encodeURIComponent(s)}catch(_){return encodeURIComponent(fix(s))}};'
		. 'window.addEventListener("error",function(e){if(er>=5)return;er++;var m=str(e.message)||(e.error&&str(e.error.name))||"(no message)",st=e.error&&e.error.stack?str(e.error.stack):"",f=str(e.filename).split("/").slice(-2).join("/");s("js_error","&msg="+enc(m.slice(0,300))+"&stack="+enc(st.slice(0,400))+"&src="+enc(f.slice(0,200))+"&line="+(e.lineno||0))},true);'
		. 'window.addEventListener("unhandledrejection",function(e){if(er>=5)return;er++;var r=e.reason,m=(r&&r.message)?str(r.message):str(r)||"(no message)",st=r&&r.stack?str(r.stack):"";s("js_error","&msg="+enc(m.slice(0,300))+"&stack="+enc(st.slice(0,400))+"&src=unhandledrejection&line=0")});'
		. 'var lcp=0,cls=0,inp=0,fcp=0,ob=function(t,x,f){try{var o={type:t,buffered:true};for(var k in x)o[k]=x[k];new PerformanceObserver(f).observe(o)}catch(_){}};'
		. 'ob("paint",0,function(l){for(var i=0,a=l.getEntries();i<a.length;i++){var e=a[i];if(e.name==="first-contentful-paint")fcp=Math.round(e.startTime)}});'
		. 'ob("largest-contentful-paint",0,function(l){var a=l.getEntries(),e=a[a.length-1];if(e&&e.startTime)lcp=Math.round(e.startTime)});'
		. 'ob("layout-shift",0,function(l){for(var i=0,a=l.getEntries();i<a.length;i++){var e=a[i];if(!e.hadRecentInput)cls+=e.value}});'
		. 'ob("event",{buffered:false,durationThreshold:16},function(l){for(var i=0,a=l.getEntries();i<a.length;i++){var e=a[i];if(e.duration>inp)inp=Math.round(e.duration)}});'
		. 'var utm=((location.search.match(/[?&]utm_source=([^&]*)/)||[])[1]||"").replace(/\+/g," "),vw=window.innerWidth||0,'
		. 'vit=function(){if(g)return;g=1;var x=utm;try{x=decodeURIComponent(utm)}catch(_){}s("vitals","&lcp="+lcp+"&cls="+(Math.round(cls*1000)/1000)+"&inp="+inp+"&fcp="+fcp+"&utm="+enc(x)+"&vw="+vw)};'
		. 'document.addEventListener("DOMContentLoaded",function(){if(d)return;d=1;s("finished","&c="+(document.getElementById("frl-rum-end")?1:0))});'
		. 'var h=0;document.addEventListener("visibilitychange",function(){if(document.visibilityState!=="hidden")return;if(!d&&!h){h=1;s("hidden")}vit()});'
		. 'addEventListener("pagehide",function(){if(!d&&!a){a=1;s("abandoned")}vit()})'
		. '})();</script><!--/noptimize-->' . "\n";
	// phpcs:enable
}

/**
 * Prints the end-of-page marker checked by the "finished" event.
 */
function frl_debug_rum_print_end_marker() {
	if ( ! frl_debug_rum_is_active() ) {
		return;
	}
	echo '<i id="frl-rum-end" hidden></i>' . "\n";
}
