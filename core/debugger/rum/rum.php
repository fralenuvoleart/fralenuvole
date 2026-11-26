<?php

/**
 * Page-load RUM beacon (printer).
 *
 * Prints a ~7.9 KB inline script as the first thing in <head> reporting, per page view:
 * started (head ran), finished (DOM parsed; c=1 if the end marker arrived, c=0 if the HTML was cut),
 * hidden (tab/app switched before finished; often finishes later), abandoned (page left before finished),
 * js_error (uncaught error or rejection, with stack and origin), vitals (LCP/CLS/INP/FCP, navigation
 * timings and dropped-error count when the page is hidden or left) and the contact-form funnel (form_start, submit_attempt, submit_ok, submit_blocked,
 * submit_failed, submit_unresolved) for forms that contain an email field only. WS Form forms are
 * judged by the plugin's own events (wsf-submit-success / -validate-fail / -error); other forms with
 * `novalidate` are probed at 900 ms and 2.5 s. Receiver: rum-receiver.php.
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

// Turning the option on/off syncs the receiver's marker file using the hook's $new_value directly.
add_action( 'update_option_' . frl_prefix( 'debugger_rum' ), 'frl_debug_rum_on_option_change', 10, 2 );
add_action( 'add_option_' . frl_prefix( 'debugger_rum' ), 'frl_debug_rum_on_option_change', 10, 2 );
// Option deletion does not fire update_option, so it needs its own cleanup.
add_action( 'delete_option_' . frl_prefix( 'debugger_rum' ), 'frl_debug_rum_delete_marker', 10, 0 );

/**
 * Syncs beacon.on from add_option_/update_option_ (2nd arg is always the new value).
 *
 * @param mixed $first     Option name (add_option) or old value (update_option).
 * @param mixed $new_value New option value.
 */
function frl_debug_rum_on_option_change( $first, $new_value ) {
	frl_debug_rum_sync_marker( (bool) $new_value );
}

/**
 * Creates or deletes the marker that allows rum-receiver.php to write, to match the option.
 *
 * @param bool|null $enabled Pre-resolved option state; falls back to cached option when null.
 */
function frl_debug_rum_sync_marker( ?bool $enabled = null ) {
	$logs = frl_get_uploads_location( 'logs' );
	if ( null === $logs ) {
		return;
	}
	$is_enabled = ( null !== $enabled ) ? $enabled : (bool) frl_get_option( 'debugger_rum' );
	$marker     = $logs['dir'] . '/beacon.on';
	if ( $is_enabled ) {
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
		$active = frl_get_option( 'debugger_rum' )
			&& frl_is_valid_frontend_page_request()
			&& ! is_user_logged_in()
			&& ! ( function_exists( 'is_404' ) && is_404() )
			&& ! ( function_exists( 'is_attachment' ) && is_attachment() );
		if ( $active ) {
			frl_debug_rum_sync_marker( true ); // Recreates the marker if missing without bypassing option cache.
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

	// Build id: the printer file's modification time, base36. Changes on every deploy, cannot be forgotten.
	$frl_build = preg_replace( '/[^a-z0-9]/', '', base_convert( (string) @filemtime( __FILE__ ), 10, 36 ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

	// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- static script, URL escaped above.
	echo '<!--noptimize--><script data-noptimize="1">(function(){var u="' . $url . '",'
		. 'bd="' . $frl_build . '",v=Math.random().toString(36).slice(2,10),d=0,a=0,g=0,dr=0,r=' . time() . ','
		. 'str=function(x){if(x==null)return "";try{return String(x)}catch(_){return ""}},'
		. 'fix=function(x){var o="",i=0,k;while(i<x.length){k=x.charCodeAt(i);if(k>=0xD800&&k<=0xDBFF&&x.charCodeAt(i+1)>=0xDC00&&x.charCodeAt(i+1)<=0xDFFF){o+=x.charAt(i)+x.charAt(i+1);i+=2}else if(k>=0xD800&&k<=0xDFFF){o+="\uFFFD";i++}else{o+=x.charAt(i);i++}}return o},'
		. 'enc=function(x){try{return encodeURIComponent(x)}catch(_){return encodeURIComponent(fix(x))}},'
		. 'qp=function(k){var m=location.search.match(new RegExp("[?&]"+k+"=([^&]*)","i"));if(!m||!m[1])return "";var x=m[1].replace(/\+/g," ");try{return decodeURIComponent(x)}catch(_){return x}},'
		. 'c=(location.search.match(/[?&](gclid|gbraid|wbraid|yclid|fbclid)=/i)||[0,""])[1].toLowerCase(),'
		. 'utm=qp("utm_source").slice(0,50),utmc=qp("utm_campaign").slice(0,80),'
		. 'uq=(utm?"&utm="+enc(utm):"")+(utmc?"&utmc="+enc(utmc):""),'
		. 'n=(navigator.connection&&navigator.connection.effectiveType)||"",'
		. 'P=window.performance&&performance.now?function(){return Math.round(performance.now())}:function(){return -1};'
		. 'function s(e,x){var b="e="+e+"&v="+v+"&p="+enc(location.pathname)+"&ad="+(c?1:0)+"&cid="+c+"&n="+n+"&t="+P()+"&r="+r+"&b="+bd+(x||"");'
		. 'try{if(navigator.sendBeacon&&navigator.sendBeacon(u,new Blob([b],{type:"application/x-www-form-urlencoded"})))return}catch(_){}'
		. 'if(typeof fetch==="function"){try{var q=fetch(u,{method:"POST",body:b,keepalive:!0,headers:{"Content-Type":"application/x-www-form-urlencoded"}});if(q&&q["catch"])q["catch"](function(){});return}catch(_){}}'
		. '(new Image()).src=u+"?"+b}'
		. 's("started",uq);'
		. 'var er=0,Es=function(f){if(!f)return "";try{var x=new URL(f),l=location;if(x.origin!==l.origin)return "third";return x.pathname===l.pathname?"inline":"own"}catch(_){return "third"}};'
		. 'window.addEventListener("error",function(e){var m=str(e.message)||(e.error&&str(e.error.name))||"",st=e.error&&e.error.stack?str(e.error.stack):"";if(!m&&!st)return;if(er>=5){dr++;return}er++;var f=str(e.filename).split("/").slice(-2).join("/");s("js_error","&msg="+enc((m||"(no message)").slice(0,300))+"&stack="+enc(st.slice(0,400))+"&src="+enc(f.slice(0,200))+"&es="+Es(e.filename?str(e.filename):"")+"&line="+(e.lineno||0))},true);'
		. 'window.addEventListener("unhandledrejection",function(e){var r=e.reason,m=(r&&r.message)?str(r.message):(typeof r==="string"?str(r):(r&&r.name?str(r.name):"")),st=r&&r.stack?str(r.stack):"";if(!m&&!st)return;if(er>=5){dr++;return}er++;s("js_error","&msg="+enc((m||"(no message)").slice(0,300))+"&stack="+enc(st.slice(0,400))+"&src=unhandledrejection&line=0")});'
		. 'var lcp=null,cls=null,inp=null,fcp=null,S=(window.PerformanceObserver&&PerformanceObserver.supportedEntryTypes)||[],'
		. 'H=function(t){return S.indexOf(t)>-1},N=function(v){return v===null?"":v},'
		. 'ob=function(t,x,f){try{var o={type:t,buffered:true};for(var k in x)o[k]=x[k];new PerformanceObserver(f).observe(o)}catch(_){}};'
		. 'if(H("paint")){fcp=0;ob("paint",0,function(l){for(var i=0,a=l.getEntries();i<a.length;i++){var e=a[i];if(e.name==="first-contentful-paint")fcp=Math.round(e.startTime)}});}'
		. 'if(H("largest-contentful-paint")){lcp=0;ob("largest-contentful-paint",0,function(l){var a=l.getEntries(),e=a[a.length-1];if(e&&e.startTime)lcp=Math.round(e.startTime)});}'
		. 'if(H("layout-shift")){cls=0;ob("layout-shift",0,function(l){for(var i=0,a=l.getEntries();i<a.length;i++){var e=a[i];if(!e.hadRecentInput)cls+=e.value}});}'
		. 'if(H("event")){ob("event",{buffered:false,durationThreshold:16},function(l){for(var i=0,a=l.getEntries();i<a.length;i++){var e=a[i];if(e.interactionId>0&&(inp===null||e.duration>inp))inp=Math.round(e.duration)}});}'
		. 'var vw=window.innerWidth||0,'
		. 'tk=function(){var p=window.performance,e=p&&p.getEntriesByType?(p.getEntriesByType("navigation")||[])[0]:0;return e&&typeof e.transferSize==="number"?"&tkb="+Math.round(e.transferSize/1024):""},'
		. 'sd=function(){var rs=str(document.readyState||""),hc=rs&&rs!=="loading"?"&c="+(document.getElementById("frl-rum-end")?1:0):"",h="",p=window.performance,a=p&&p.getEntriesByType?p.getEntriesByType("resource")||[]:[],now=P(),w=-1,mw=1000,u1="",u2="",i=0,e,it,nm,dt;'
		. 'for(;i<a.length;i++){e=a[i];nm=str(e&&e.name);it=str(e&&e.initiatorType);if(!nm||nm.indexOf("rum-receiver.php")>-1||it==="beacon"||it==="ping")continue;if(e.responseEnd===0){dt=now>=0&&typeof e.startTime==="number"?now-e.startTime:0;if(dt>w){w=dt;u1=nm}}else if(typeof e.duration==="number"&&e.duration>mw){mw=e.duration;u2=nm}}'
		. 'var pk=u1||u2;if(pk){try{h=str((new URL(pk,location.href)).hostname).toLowerCase().slice(0,60)}catch(_){var m=pk.match(/^https?:\/\/([^\/:?#]+)/i);h=m?m[1].toLowerCase().slice(0,60):""}}return(rs?"&rs="+enc(rs):"")+hc+(h?"&sph="+enc(h):"")},'
		. 'nt=function(){var p=window.performance,e=p&&p.getEntriesByType?(p.getEntriesByType("navigation")||[])[0]:0;if(!e||e.type==="back_forward"||!e.responseStart)return "";'
		. 'var dd=function(a,b){return a>0&&b>=a?Math.round(b-a):null},o=[Math.round(e.responseStart),dd(e.redirectStart,e.redirectEnd),dd(e.domainLookupStart,e.domainLookupEnd),dd(e.connectStart,e.connectEnd),dd(e.secureConnectionStart,e.connectEnd),dd(e.requestStart,e.responseStart)];'
		. 'return "&ttfb="+N(o[0])+"&redirect="+N(o[1])+"&dns="+N(o[2])+"&connect="+N(o[3])+"&tls="+N(o[4])+"&wait="+N(o[5])},'
		. 'vit=function(){if(g)return;g=1;s("vitals","&lcp="+N(lcp)+"&cls="+(cls===null?"":Math.round(cls*1000)/1000)+"&inp="+N(inp)+"&fcp="+N(fcp)+"&dr="+dr+uq+"&vw="+vw+nt()+tk())};'
		. 'document.addEventListener("DOMContentLoaded",function(){if(d)return;d=1;s("finished","&c="+(document.getElementById("frl-rum-end")?1:0)+tk())});'
		. 'var h=0;document.addEventListener("visibilitychange",function(){if(document.visibilityState!=="hidden")return;if(!d&&!h){h=1;s("hidden",sd())}vit()});'
		. 'var mc={},mh=function(x){return/^(?:tg:|https?:\/\/(?:[\w-]+\.)?(?:t|telegram)\.me(?:\/|$))/i.test(x)?"telegram":/^(?:whatsapp:|https?:\/\/(?:(?:api|chat|www|web)\.)?whatsapp\.com(?:\/|$)|https?:\/\/(?:www\.)?wa\.me(?:\/|$))/i.test(x)?"whatsapp":""};'
		. 'document.addEventListener("click",function(e){if(!e.isTrusted)return;var t=e.target;if(!t||!t.closest)return;var el=t.closest("[data-action=\"telegram\"],[data-action=\"whatsapp\"],[data-cta-url],a[href]");if(!el)return;var da=str(el.getAttribute("data-action")).toLowerCase(),ch=da==="telegram"||da==="whatsapp"?da:(mh(str(el.getAttribute("data-cta-url")))||mh(str(el.getAttribute("href"))));if(ch&&!mc[ch]){mc[ch]=1;s("messenger_click","&mc="+ch+uq)}},true);'
		. 'var f1=0,sc=0,ib=0,ft=0,st="",bt="",lead=function(f){if(!f||f.tagName!=="FORM")return 0;var n=f.elements?f.elements.length:0;if(f.__rumLead===void 0||(f.__rumLead===0&&f.__rumLen!==n)){f.__rumLead=f.querySelector("input[type=email]")?1:0;f.__rumLen=n}return f.__rumLead};'
		. 'var pf=function(t){return t?t.form||(t.closest&&t.closest("form"))||0:0};'
		. 'document.addEventListener("beforeinput",function(e){bt=str(e.inputType||"")},true);'
		. 'document.addEventListener("input",function(e){var k=bt;bt="";if(!e.isTrusted)return;if(f1){if(!ft&&k==="insertText"&&st!=="insertText"&&lead(pf(e.target))){ft=1;s("form_typed")}return}if(lead(pf(e.target))){f1=1;st=k||"none";s("form_start","&it="+st)}},true);'
		. 'document.addEventListener("click",function(e){if(sc)return;var t=e.target;if(!t||!t.closest)return;var b=t.closest("button[type=submit],input[type=submit],button:not([type])");if(lead(pf(b))){sc=1;s("submit_attempt");setTimeout(function(){if(sc){s("submit_failed");sc=0}},500)}},true);'
		. 'document.addEventListener("keydown",function(e){if(sc||(e.key!=="Enter"&&e.keyCode!==13))return;var t=e.target;if(!t||t.tagName!=="INPUT"||/^(checkbox|radio|submit|button|file|image|reset|range|color|hidden)$/.test((t.type||"text").toLowerCase()))return;if(lead(pf(t))){var k=e;setTimeout(function(){if(k.defaultPrevented||sc)return;sc=1;s("submit_attempt");setTimeout(function(){if(sc){s("submit_failed");sc=0}},500)},0)}},true);'
		. 'var pend=0,jd=function(f,fin){var b=f.querySelector(".wsf-invalid")||f.querySelector(":invalid");if(b&&b.querySelector&&!b.validationMessage){var x=b.querySelector(":invalid");if(x)b=x}if(!b){if(fin||f.isConnected===false){pend=0;s("submit_ok","&why=native"+uq)}else setTimeout(function(){if(pend&&pend.f===f)jd(f,1)},1600);return}pend=0;if(ib>=5)return;ib++;s("submit_blocked","&why=browser&field="+enc(str(b.name||b.id).slice(0,60)))};'
		. 'var wx=function(err,f){if(err)return[""];var b=f?f.querySelector("[aria-invalid=true]"):0,x=b?(b.querySelector(".wsf-invalid")||b.querySelector(":invalid")||b):0;return[x?str(x.name||x.id).slice(0,60):""]};'
		. 'var jj=function(ok,x,w){if(!pend||!pend.ws||pend.done)return;var k=pend;pend=0;k.done=1;clearTimeout(k.t);s(ok?"submit_ok":"submit_blocked",(ok?uq:"&why="+w+(x[0]?"&field="+enc(x[0]):""))+"&ref="+enc(k.ref||""))};'
		. 'var wsb=0,wbind=function(){if(wsb||!window.jQuery)return;wsb=1;var j=jQuery;'
		. 'j(document).on("wsf-submit-success",function(){jj(1,0)});'
		. 'j(document).on("wsf-submit-validate-fail",function(){jj(0,wx(0,pend&&pend.f),pend&&pend.posted?"server":"client")});'
		. 'j(document).on("wsf-submit-error",function(){jj(0,wx(1),"error")});'
		. 'j(document).on("wsf-submit-validate-success",function(){var k=pend;if(k&&k.ws&&!k.done){k.posted=1;clearTimeout(k.t);k.t=setTimeout(function(){if(pend===k&&!k.done){pend=0;k.done=1;s("submit_unresolved","&msg=timeout&ref="+enc(k.ref||""))}},15e3)}})};'
		. 'document.addEventListener("submit",function(e){var f=e.target;if(!lead(f))return;if(sc)sc=0;if(f.classList&&f.classList.contains("wsf-form")){var r=f.querySelector("[name=field_232]"),o=pend={f:f,ws:1,posted:0,ref:r?str(r.value).slice(0,20):""};o.t=setTimeout(function(){if(pend===o&&!o.done){pend=0;o.done=1;s("submit_unresolved","&msg=stale&ref="+enc(o.ref||""))}},2e4);wbind();return}if(!f.hasAttribute("novalidate")){s("submit_ok","&why=native"+uq);return}var k={f:f};pend=k;setTimeout(function(){if(pend===k)jd(f,0)},900)},true);'
		. 'document.addEventListener("invalid",function(e){if(!sc||ib>=5)return;var t=e.target;if(lead(pf(t))){ib++;sc=0;s("submit_blocked","&why=browser&field="+enc(str(t.name||t.id).slice(0,60)))}},true);'
		. 'addEventListener("pagehide",function(){if(pend){var p=pend;pend=0;p.ws?(p.done||(p.done=1,s("submit_unresolved","&msg=unload&ref="+enc(p.ref||"")))):jd(p.f,1)}if(!d&&!a){a=1;s("abandoned",sd())}vit()})'
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
