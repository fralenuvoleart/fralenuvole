<?php
/**
 * Import map polyfill for older browsers.
 *
 * Browsers without import map support (Chrome < 89, Safari < 16.4, for example
 * VivoBrowser on Chrome 87 and iOS 16.2-16.3) cannot resolve '@wordpress/interactivity',
 * so the block navigation (mobile menu) does not work for them. This loads
 * es-module-shims 2.8.4 (self-hosted, MIT) only in those browsers; modern browsers
 * run one feature check and download nothing.
 *
 * @package Fralenuvole
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_head', 'frl_thirdparty_print_importmap_polyfill_loader', 1, 0 );

/**
 * Prints the tiny inline loader as early as possible in <head>, before the import map.
 * Gated by the `thirdparty_importmap_polyfill` option (read via the 3-tier options cache).
 */
function frl_thirdparty_print_importmap_polyfill_loader(): void {
	if ( ! frl_is_valid_frontend_page_request() || ! frl_get_option( 'thirdparty_importmap_polyfill' ) ) {
		return;
	}

	$src = FRL_DIR_URL . 'modules/thirdparty/importmap-polyfill/es-module-shims.js?ver=2.8.4';

	printf(
		'<!--noptimize--><script data-noptimize="1">(function(){var S=window.HTMLScriptElement;if(!S||!S.prototype||!("noModule" in S.prototype)||(S.supports&&S.supports("importmap")))return;var e=document.createElement("script");e.async=true;e.src=%s;document.head.appendChild(e)})();</script><!--/noptimize-->' . "\n",
		wp_json_encode( esc_url_raw( $src ), JSON_HEX_TAG )
	);
}
