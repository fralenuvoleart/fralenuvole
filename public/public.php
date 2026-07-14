<?php

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fralenuvole
 * public.php - Frontend execution logic and hooks.
 */

require_once __DIR__ . '/performance.php';

add_action( 'wp_head', 'frl_add_header_html', 0, 0 );
add_action( 'wp_head', 'frl_add_header_scripts', 0, 0 );
add_action( 'wp_footer', 'frl_add_footer_html', 10, 0 );
add_action( 'wp_footer', 'frl_add_footer_scripts', 10, 0 );
add_action( 'wp_loaded', 'frl_public_scripts', 10, 1 );

add_action( 'login_enqueue_scripts', 'frl_login_page_branding', 10, 0 );
add_filter( 'rest_endpoints', 'frl_disable_rest_endpoints', 10, 1 );
add_filter( 'robots_txt', 'frl_append_custom_robots', 99, 2 );

// The SEO Framework bypasses WordPress's `robots_txt` filter and uses its own
// `the_seo_framework_robots_txt_sections` filter. Register a handler for it so
// custom robots.txt content works when TSF is active.
add_filter( 'the_seo_framework_robots_txt_sections', 'frl_append_custom_robots_tsf', 99, 2 );

/**
 * Enqueue public JavaScript assets for the frontend.
 */
function frl_public_scripts() {
	if ( ! frl_is_valid_frontend_page_request() ) {
		return;
	}
	$assets = array( 'public-js' => 'assets/js/public.js' );
	frl_enqueue_scripts( $assets, 'public_assets' );
}

/**
 * Inject custom HTML into the header, cached by user login status.
 */
function frl_add_header_html(): void {
	$cache_key = frl_is_logged_in() ? 'header_html_user' : 'header_html_visitor';
	$id        = str_replace( '_', '-', $cache_key );

	$header_html = frl_get_html_option( 'header_html', 'header_html_php', $cache_key );

	if ( ! empty( $header_html ) ) {
		echo "
<!-- {$id} data-plugin='" . FRL_NAME . "' data-parsing='add-header-html' -->
    " . $header_html . "
<!-- End {$id} -->
";
	}
}

/**
 * Enqueue custom scripts defined in the header_scripts option.
 */
function frl_add_header_scripts() {
	$assets = frl_get_processed_assets_from_option( 'header_scripts' );
	frl_enqueue_scripts( $assets, 'header_scripts' );
}

/**
 * Inject custom HTML into the footer, cached by user login status.
 */
function frl_add_footer_html(): void {
	$cache_key = frl_is_logged_in() ? 'footer_html_user' : 'footer_html_visitor';
	$id        = str_replace( '_', '-', $cache_key );

	$footer_html = frl_get_html_option( 'footer_html', 'footer_html_php', $cache_key );

	if ( ! empty( $footer_html ) ) {
		echo "
<!-- {$id} data-plugin='" . FRL_NAME . "' data-parsing='add-footer-html' -->
    " . $footer_html . "
<!-- End {$id} -->
";
	}
}

/**
 * Enqueue custom scripts defined in the footer_scripts option.
 */
function frl_add_footer_scripts() {
	$assets = frl_get_processed_assets_from_option( 'footer_scripts' );
	frl_enqueue_scripts( $assets, 'footer_scripts' );
}

/**
 * Apply custom branding and styles to the WordPress login page.
 */
function frl_login_page_branding(): void {
	if ( ! frl_get_option( 'login_branding' ) ) {
		return;
	}

	add_filter(
		'login_headerurl',
		'frl_login_headerurl',
		10,
		1
	);

	$assets = array( 'login-css' => 'assets/css/public-login.css' );
	frl_enqueue_scripts( $assets, 'login_page' );

	// --- Cache Inline CSS ---
	$cache_key_inline = 'login_inline_style';
	$output           = frl_cache_remember(
		'html',
		$cache_key_inline,
		function () {
			// Get logo data (expensive part)
			$logo    = wp_get_attachment_image_src( get_theme_mod( 'custom_logo' ), 'full' ) ?: array( null, null, null );
			$width   = ( $logo && isset( $logo[1] ) && $logo[1] ) ? ( $logo[1] . 'px' ) : 'auto'; // @phpstan-ignore-line booleanAnd.leftAlwaysTrue
			$height  = ( $logo && isset( $logo[2] ) && $logo[2] ) ? $logo[2] : '50'; // @phpstan-ignore-line booleanAnd.leftAlwaysTrue
			$height .= 'px';

			// Build CSS string
			$css  = ':root {';
			$css .= '--login-logo-url: url(' . esc_url( $logo[0] ?? '' ) . ');';
			$css .= '--login-logo-width: ' . esc_attr( $width ) . ';'; // Use esc_attr for safety
			$css .= '--login-logo-height: ' . esc_attr( $height ) . ';'; // Use esc_attr for safety
			$css .= '}';

			return $css;
		}
	);

	// Add the cached inline style if not empty
	if ( ! empty( $output ) ) {
		wp_add_inline_style( FRL_PREFIX . '-login', $output );
	}
}

/**
 * Redirect the login page header logo link to the home URL.
 */
function frl_login_headerurl( string $url ): string {
	return home_url();
}

/**
 * Disable sensitive or non-essential REST API endpoints for unauthenticated users.
 *
 * The endpoint list is defined in the FRL_REST_ENDPOINTS constant in config/config-base.php.
 * /oembed/1.0 and /wp/v2/oembed are intentionally absent — the frl_disable_oembed() function
 * handles oEmbed REST route removal through its own toggle (disable_oembed).
 *
 * @see config/config-base.php:FRL_REST_ENDPOINTS
 * @see includes/main/website.php:124
 */
function frl_disable_rest_endpoints( array $endpoints ): array {
	if ( frl_is_logged_in() || ! frl_get_option( 'disable_rest' ) ) {
		return $endpoints;
	}

	// Allow themes/plugins to modify the removal list
	$endpoints_to_remove = apply_filters( 'frl_rest_endpoints_to_remove', FRL_REST_ENDPOINTS );

	foreach ( $endpoints as $route => $data ) {
		foreach ( $endpoints_to_remove as $prefix_to_remove ) {
			if ( str_starts_with( $route, $prefix_to_remove ) ) {
				unset( $endpoints[ $route ] );
				break;
			}
		}
	}

	return $endpoints;
}

/**
 * Convert a text list of assets from a plugin option into a handle-mapped array.
 *
 * @param string $option_name The name of the plugin option storing the asset list.
 * @return array Associative array of [handle => url].
 */
function frl_get_processed_assets_from_option( string $option_name ): array {
	$assets = frl_textlist_to_array( frl_get_option( $option_name ) );

	if ( ! frl_is_array_not_empty( $assets ) ) {
		return array();
	}

	$processed_assets = array();
	$index            = 0;

	foreach ( $assets as $asset_parts ) {
		if ( ! frl_is_array_not_empty( $asset_parts ) || empty( $asset_parts[0] ) ) {
			continue;
		}

		$url    = $asset_parts[0];
		$handle = str_replace( '_', '-', $option_name ) . '-' . $index;

		$processed_assets[ $handle ] = $url;
		++$index;
	}

	return $processed_assets;
}

/**
 * Retrieve and optionally process HTML from a plugin option with caching.
 *
 * @param string       $option_name       The name of the option storing the HTML.
 * @param string|null  $php_enabled_option The name of the option that enables PHP processing.
 * @param string|null  $cache_key         Optional cache key override.
 * @return string The processed HTML content.
 */
function frl_get_html_option( string $option_name, ?string $php_enabled_option = null, ?string $cache_key = null ): string {
	$cache_key = $cache_key ?? $option_name;

	$html_option = frl_cache_remember(
		'html',
		$cache_key,
		function () use ( $option_name, $php_enabled_option ) {
			$html = frl_get_option( $option_name );
			if ( ! $html ) {
				return '';
			}

			if ( $php_enabled_option && frl_get_option( $php_enabled_option ) ) {
				return frl_process_php_string( $html, $option_name );
			}
			return $html;
		}
	);

	return $html_option;
}

/**
 * Append custom robots.txt content from plugin options.
 *
 * @param string $output    The robots.txt output.
 * @param bool   $is_public Whether the site is public.
 * @return string
 */
function frl_append_custom_robots( string $output, bool $is_public ): string {
	if ( ! frl_get_option( 'enable_custom_robots' ) ) {
		return $output;
	}

	$custom = frl_get_option( 'custom_robots_txt' );
	if ( empty( $custom ) ) {
		return $output;
	}

	return $output . "\n# Custom Rules\n" . $custom . "\n";
}

/**
	* Append custom robots.txt content via The SEO Framework's filter.
	*
	* TSF bypasses WordPress's `robots_txt` filter — this handler injects the
	* same custom rules into TSF's section-based output instead.
	*
	* @param array  $robots_sections TSF robots.txt sections.
	* @param string $site_path       Site path prefix.
	* @return array
	*/
function frl_append_custom_robots_tsf( array $robots_sections, string $site_path ): array {
	if ( ! frl_get_option( 'enable_custom_robots' ) ) {
		return $robots_sections;
	}

	$custom = frl_get_option( 'custom_robots_txt' );
	if ( empty( $custom ) ) {
		return $robots_sections;
	}

	$robots_sections['frl_custom'] = '# Custom rules (Fralenuvole)' . "\n" . $custom;

	return $robots_sections;
}
