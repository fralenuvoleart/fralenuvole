<?php

/**
 * Subdomain Adapter — Legacy URL Handler
 *
 * Handles hardcoded legacy URLs in post content, block content, and navigation
 * menus by transforming them at runtime to match the current domain context.
 * Also 301-redirects legacy incoming URLs with language prefixes to their
 * canonical subdomain/main-domain destinations.
 *
 * ## What it does
 *
 * - **Post content** (`the_content`): Scans rendered HTML for absolute site URLs
 *   and transforms them to the correct domain for the viewing context.
 * - **Block content** (`render_block`): Same transformation for individual blocks,
 *   with a fast-fail guard (`str_contains`) and a per-request static cache.
 * - **Navigation menus** (`wp_nav_menu_objects`): Transforms menu item URLs using
 *   either the linked object's language (post/term) or URL path extraction (custom links).
 * - **Incoming redirects** (`template_redirect`): 301-redirects URLs with language
 *   prefixes (e.g., `/ru/services/`) on the wrong domain to their canonical location.
 *
 * ## Design
 *
 * All transformations delegate to the existing `Frl_Subdomain_Adapter::transform_url()`
 * where possible, ensuring consistent behavior with the core URL transformation logic.
 * Custom link URLs without a linked object fall back to path-based language extraction.
 *
 * @package Fralenuvole
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Frl_Subdomain_Adapter_Legacy
 */
class Frl_Subdomain_Adapter_Legacy {

	// -------------------------------------------------------------------------
	// Singleton / Init
	// -------------------------------------------------------------------------

	private static ?self $instance = null;

	public static function init(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
			self::$instance->register_hooks();
		}
		return self::$instance;
	}

	private function __construct() {}

	// -------------------------------------------------------------------------
	// Hook Registration
	// -------------------------------------------------------------------------

	private function register_hooks(): void {
		$adapter = Frl_Subdomain_Adapter::init();

		// Guard: only register if adapter is configured and on a recognized domain.
		if ( ! $adapter->is_configured() ) {
			return;
		}
		if ( ! $adapter->is_on_main_domain() && ! $adapter->is_on_subdomain() ) {
			return;
		}
		if ( ! frl_translator_is_enabled() ) {
			return;
		}
		if ( frl_is_already_running( __CLASS__ . '::register_hooks' ) ) {
			return;
		}

		// 1. Legacy incoming URL redirect (main domain and subdomain).
		add_action( 'template_redirect', array( $this, 'redirect_legacy_incoming_url' ), 6 );

		// 2. Post content transformation.
		add_filter( 'the_content', array( $this, 'filter_the_content' ), PHP_INT_MAX, 1 );

		// 3. Block content transformation.
		add_filter( 'render_block', array( $this, 'filter_render_block' ), PHP_INT_MAX, 2 );

		// 4. Classic navigation menu transformation.
		add_filter( 'wp_nav_menu_objects', array( $this, 'filter_nav_menu_objects' ), PHP_INT_MAX, 1 );
	}

	// -------------------------------------------------------------------------
	// Gate
	// -------------------------------------------------------------------------

	private function should_transform(): bool {
		if ( frl_is_admin() || frl_is_rest_api_request() || is_preview() || frl_is_cron_job_request() ) {
			return false;
		}

		// Intentionally does NOT exclude is_feed() — RSS feeds for legacy content
		// URLs should point to the correct canonical domain, consistent with the
		// parent class should_transform() design (see class-subdomain-adapter.php).
		// is_robots() is also not excluded: each domain/subdomain serves its own
		// robots.txt naturally via WordPress's default behavior.
		return frl_translator_is_enabled();
	}

	// -------------------------------------------------------------------------
	// 1. Legacy Incoming URL Redirect
	// -------------------------------------------------------------------------

	/**
	 * 301-redirect legacy URLs to their canonical domain.
	 *
	 * Handles:
	 * - Main domain: /{lang}/post-slug/ → {subdomain}/post-slug/
	 * - Subdomain: /{same-lang}/post-slug/ → {subdomain}/post-slug/ (strip redundant prefix)
	 */
	public function redirect_legacy_incoming_url(): void {
		if ( ! $this->should_transform() ) {
			return;
		}

		$adapter = Frl_Subdomain_Adapter::init();

		if ( is_404() ) {
			return;
		}

		$uri  = $_SERVER['REQUEST_URI'] ?? '/';
		$uri  = preg_replace( '/[\x00-\x1F\x7F]/', '', $uri );
		$path = parse_url( $uri, PHP_URL_PATH ) ?: '/';

		// Extract language from first path segment.
		$segments      = explode( '/', trim( $path, '/' ) );
		$first_segment = strtolower( $segments[0] ?? '' );

		$active_langs = frl_get_active_languages();
		if ( empty( $first_segment ) || ! in_array( $first_segment, $active_langs, true ) ) {
			return;
		}

		$lang = $first_segment;

		// Determine target domain for this language.
		$target_host = $this->get_target_host_for_language( $adapter, $lang );
		if ( $target_host === null ) {
			return; // Language has no mapped subdomain and is not default — nothing to redirect.
		}

		$target_url = $this->build_redirect_target( $path, $lang, $target_host );

		// Avoid redirect loops: if the target equals current URL, don't redirect.
		$current_full = home_url( $uri );
		if ( rtrim( $current_full, '/' ) === rtrim( $target_url, '/' ) ) {
			return;
		}

		// Preserve query string.
		if ( ! empty( $_GET ) ) {
			$target_url = add_query_arg( $_GET, $target_url );
		}

		add_filter(
			'x_redirect_by',
			function () {
				return 'Frl_Subdomain_Adapter::redirect_legacy_incoming_url';
			},
			999
		);
		wp_redirect( $target_url, 301 );
		exit;
	}

	/**
	 * Determine the target host for content of a given language.
	 *
	 * @param Frl_Subdomain_Adapter $adapter The subdomain adapter instance.
	 * @param string                $lang    The language slug.
	 * @return string|null The target host, or null if unresolvable.
	 */
	private function get_target_host_for_language( Frl_Subdomain_Adapter $adapter, string $lang ): ?string {
		$map          = $adapter->get_domain_map();
		$current_host = $adapter->get_current_host();

		// Determine resolution context: which main domain's config should we use?
		if ( $adapter->is_on_subdomain() ) {
			// Find the primary main domain that registered this subdomain.
			$subdomain_info = $adapter->get_subdomain_info();
			$info           = $subdomain_info[ $current_host ] ?? null;
			if ( $info === null ) {
				return null;
			}
			$resolve_domain = $info['main_domains'][0];
		} else {
			$resolve_domain = $current_host;
		}

		$config = $map[ $resolve_domain ] ?? array();
		if ( empty( $config ) ) {
			return null;
		}

		// If language has a mapped subdomain → target is that subdomain.
		if ( isset( $config[ $lang ] ) && $config[ $lang ] !== '' ) {
			return $config[ $lang ];
		}

		// If language is the default → target is main domain (no prefix).
		$default_lang = $config['default_lang'] ?? frl_get_default_language_fallback();
		if ( $lang === $default_lang ) {
			return $resolve_domain;
		}

		// Language has no mapped subdomain and is not default → target is main domain with prefix.
		// Return main domain (prefix will be added by the caller).
		return $resolve_domain;
	}

	/**
	 * Build the redirect destination URL.
	 *
	 * @param string $path        The request path (with language prefix).
	 * @param string $lang        The language slug.
	 * @param string $target_host The target host.
	 * @return string The full redirect URL.
	 */
	private function build_redirect_target( string $path, string $lang, string $target_host ): string {
		$scheme = is_ssl() ? 'https' : 'http';

		// Strip the language prefix from the path.
		$prefix     = '/' . $lang . '/';
		$path_lower = strtolower( $path );
		if ( str_starts_with( $path_lower, $prefix ) ) {
			$path = '/' . substr( $path, strlen( $prefix ) );
		} elseif ( strtolower( rtrim( $path, '/' ) ) === '/' . $lang ) {
			// Handle case where path is exactly /{lang} (homepage)
			$path = '/';
		}

		// Determine if we need to add a prefix to the target.
		$adapter        = Frl_Subdomain_Adapter::init();
		$map            = $adapter->get_domain_map();
		$target_is_main = isset( $map[ $target_host ] );
		if ( $target_is_main ) {
			$config       = $map[ $target_host ] ?? array();
			$default_lang = $config['default_lang'] ?? frl_get_default_language_fallback();
			// If language is not the default for this main domain AND not mapped to a subdomain,
			// add the language prefix.
			if ( $lang !== $default_lang && ! isset( $config[ $lang ] ) ) {
				$path = '/' . $lang . $path;
			}
		}

		return "{$scheme}://{$target_host}{$path}";
	}

	// -------------------------------------------------------------------------
	// 2. Post Content Transformation
	// -------------------------------------------------------------------------

	/**
	 * Transform hardcoded site URLs in post content.
	 *
	 * @param string $content The post content HTML.
	 * @return string The transformed content.
	 */
	public function filter_the_content( string $content ): string {
		if ( ! $this->should_transform() ) {
			return $content;
		}

		// Fast-fail: skip regex when content contains no recognized hosts.
		// Mirrors the filter_render_block() pattern — avoids preg_replace_callback
		// on the full post HTML when there are no cross-domain URLs to transform.
		$has_recognized_host = false;
		foreach ( $this->get_recognized_hosts() as $host ) {
			if ( str_contains( $content, $host ) ) {
				$has_recognized_host = true;
				break;
			}
		}
		if ( ! $has_recognized_host ) {
			return $content;
		}

		return $this->transform_urls_in_html( $content );
	}

	// -------------------------------------------------------------------------
	// 3. Block Content Transformation
	// -------------------------------------------------------------------------

	/**
	 * Transform hardcoded site URLs in rendered block HTML.
	 *
	 * @param string $block_content The rendered block HTML.
	 * @param array  $block         The block data.
	 * @return string The transformed block content.
	 */
	public function filter_render_block( string $block_content, array $block ): string {
		if ( ! $this->should_transform() ) {
			return $block_content;
		}

		// Fast-fail: skip blocks unlikely to contain URLs.
		// The block type list is defined in FRL_SUBDOMAIN_ADAPTER_LEGACY_URL_BLOCKS
		// so it can be extended without editing this class file.
		$block_name      = $block['blockName'] ?? '';
		$likely_has_urls = in_array( $block_name, FRL_SUBDOMAIN_ADAPTER_LEGACY_URL_BLOCKS, true )
			|| $block_name === ''
			|| str_starts_with( $block_name, 'acf/' );

		if ( ! $likely_has_urls ) {
			// Check if block HTML contains any recognized host from the domain map.
			// This avoids hardcoding a domain name and works across all configured
			// environments (production, staging, cross-domain setups).
			$has_recognized_host = false;
			foreach ( $this->get_recognized_hosts() as $host ) {
				if ( str_contains( $block_content, $host ) ) {
					$has_recognized_host = true;
					break;
				}
			}
			if ( ! $has_recognized_host ) {
				return $block_content;
			}
		}

		// Static per-request block cache: avoid re-processing identical block HTML.
		static $block_cache = array();
		$sig                = md5( $block_content );
		if ( isset( $block_cache[ $sig ] ) ) {
			return $block_cache[ $sig ];
		}

		$block_cache[ $sig ] = $this->transform_urls_in_html( $block_content );
		return $block_cache[ $sig ];
	}

	// -------------------------------------------------------------------------
	// Core: HTML URL Transformation
	// -------------------------------------------------------------------------

	/**
	 * Scan HTML for absolute site URLs in href/src/action attributes and transform them.
	 *
	 * @param string $html The HTML content.
	 * @return string The transformed HTML.
	 */
	private function transform_urls_in_html( string $html ): string {
		$adapter = Frl_Subdomain_Adapter::init();

		// Build a set of recognized hosts from the domain map.
		$hosts = $this->get_recognized_hosts();
		if ( empty( $hosts ) ) {
			return $html;
		}

		// Build regex alternation of recognized hosts.
		$hosts_pattern = implode( '|', array_map( 'preg_quote', $hosts, array_fill( 0, count( $hosts ), '#' ) ) );

		// Match URLs in href and action attributes only.
		// src attributes (images, scripts) are intentionally excluded: the site
		// is fully mirrored, so static assets resolve identically on both domains.
		$pattern = '#\b(href|action)=(["\'])(https?://(?:' . $hosts_pattern . ')(?:/[^"\'>\s]*)?)\2#i';

		return preg_replace_callback(
			$pattern,
			function ( $matches ) use ( $adapter ) {
				$attr_name  = $matches[1]; // attribute name: href or action
				$attr_quote = $matches[2]; // quote character
				$url        = $matches[3]; // the URL

				$transformed = $this->transform_single_content_url( $adapter, $url );
				return "{$attr_name}={$attr_quote}{$transformed}{$attr_quote}";
			},
			$html
		);
	}

	/**
	 * Transform a single hardcoded content URL to the correct domain context.
	 *
	 * Core algorithm: extracts language from URL path, determines the correct
	 * target host for that language, and rebuilds the URL with the appropriate
	 * domain and prefix.
	 *
	 * @param Frl_Subdomain_Adapter $adapter The subdomain adapter instance.
	 * @param string                $url     The absolute URL to transform.
	 * @return string The transformed URL.
	 */
	private function transform_single_content_url( Frl_Subdomain_Adapter $adapter, string $url ): string {
		// Static per-request result cache: avoid re-computing when the same URL
		// appears multiple times in the same HTML (e.g., nav links in header + footer).
		static $url_cache = array();
		if ( isset( $url_cache[ $url ] ) ) {
			return $url_cache[ $url ];
		}

		$parsed = wp_parse_url( $url );
		if ( empty( $parsed['host'] ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				frl_log(
					'Subdomain Adapter Legacy: Failed to parse URL {url}',
					array(
						'url' => $url,
					)
				);
			}
			$url_cache[ $url ] = $url;
			return $url_cache[ $url ];
		}

		$host   = strtolower( $parsed['host'] );
		$path   = $parsed['path'] ?? '/';
		$scheme = $parsed['scheme'] ?? 'https';

		// Determine if this host is a main domain or subdomain.
		$map            = $adapter->get_domain_map();
		$subdomain_info = $adapter->get_subdomain_info();
		$is_main_domain = isset( $map[ $host ] );
		$is_subdomain   = isset( $subdomain_info[ $host ] );

		// Defensive guard: the regex in transform_urls_in_html() already filters to
		// recognized hosts only, so this branch should never be reached. If it does,
		// the host wasn't in the domain map — return unchanged.
		if ( ! $is_main_domain && ! $is_subdomain ) {
			$url_cache[ $url ] = $url;
			return $url_cache[ $url ]; // Not a recognized domain.
		}

		// Extract language from path prefix.
		$segments      = explode( '/', trim( $path, '/' ) );
		$first_segment = strtolower( $segments[0] ?? '' );
		$active_langs  = frl_get_active_languages();
		$lang          = in_array( $first_segment, $active_langs, true ) ? $first_segment : null;

		// If no language in path, try to determine from context.
		if ( $lang === null ) {
			if ( $is_subdomain ) {
				$lang = $subdomain_info[ $host ]['lang'] ?? null;
			} elseif ( $is_main_domain ) {
				$lang = $map[ $host ]['default_lang'] ?? null;
			}
		}

		if ( $lang === null ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				frl_log(
					'Subdomain Adapter Legacy: Cannot determine language for URL {url} (host: {host})',
					array(
						'url'  => $url,
						'host' => $host,
					)
				);
			}
			$url_cache[ $url ] = $url;
			return $url_cache[ $url ]; // Cannot determine language.
		}

		// Look up where this language's content should live.
		$target_host = $this->resolve_target_host( $map, $subdomain_info, $host, $lang );
		if ( $target_host === null ) {
			$url_cache[ $url ] = $url;
			return $url_cache[ $url ];
		}

		// Strip the language prefix from the path (if present).
		$prefix     = '/' . $lang . '/';
		$path_lower = strtolower( $path );
		if ( str_starts_with( $path_lower, $prefix ) ) {
			$path = '/' . substr( $path, strlen( $prefix ) );
		} elseif ( strtolower( rtrim( $path, '/' ) ) === '/' . $lang ) {
			$path = '/';
		}

		// Determine if target is a main domain.
		$target_is_main = isset( $map[ $target_host ] );

		// Add language prefix if needed.
		if ( $target_is_main ) {
			$default_lang = $map[ $target_host ]['default_lang'] ?? frl_get_default_language_fallback();
			if ( $lang !== $default_lang && ! isset( $map[ $target_host ][ $lang ] ) ) {
				$path = '/' . $lang . $path;
			}
		}

		$query    = isset( $parsed['query'] ) ? '?' . $parsed['query'] : '';
		$fragment = isset( $parsed['fragment'] ) ? '#' . $parsed['fragment'] : '';

		$result = "{$scheme}://{$target_host}{$path}{$query}{$fragment}";

		// Avoid re-processing: if same as input, return as-is.
		if ( $result === $url ) {
			$url_cache[ $url ] = $url;
			return $url_cache[ $url ];
		}

		$url_cache[ $url ] = $result;
		return $url_cache[ $url ];
	}

	/**
	 * Determine the target host for content of a given language, given a source host.
	 *
	 * @param array  $map              The domain map (main → config).
	 * @param array  $subdomain_info   The subdomain reverse index.
	 * @param string $current_url_host The host of the URL being processed.
	 * @param string $lang             The language slug.
	 * @return string|null The target host, or null if unresolvable.
	 */
	private function resolve_target_host( array $map, array $subdomain_info, string $current_url_host, string $lang ): ?string {
		// If the current URL host IS a subdomain for this language → target is that subdomain.
		if ( isset( $subdomain_info[ $current_url_host ] ) && $subdomain_info[ $current_url_host ]['lang'] === $lang ) {
			return $current_url_host;
		}

		// If the current URL host is a main domain, find the target from its config.
		if ( isset( $map[ $current_url_host ] ) ) {
			if ( isset( $map[ $current_url_host ][ $lang ] ) && $map[ $current_url_host ][ $lang ] !== '' ) {
				return $map[ $current_url_host ][ $lang ]; // Mapped subdomain.
			}
			return $current_url_host; // Default or unmapped language → stays on main.
		}

		// Current URL host is a subdomain for a different language.
		// Find the primary main domain for this subdomain and resolve from there.
		if ( isset( $subdomain_info[ $current_url_host ] ) ) {
			$primary_main = $subdomain_info[ $current_url_host ]['main_domains'][0];
			$config       = $map[ $primary_main ] ?? array();
			if ( isset( $config[ $lang ] ) && $config[ $lang ] !== '' ) {
				return $config[ $lang ]; // Mapped subdomain.
			}
			return $primary_main; // Default or unmapped → primary main domain.
		}

		return null;
	}

	// -------------------------------------------------------------------------
	// 4. Navigation Menu Transformation
	// -------------------------------------------------------------------------

	/**
	 * Transform hardcoded site URLs in navigation menu items.
	 *
	 * For menu items pointing to actual objects (posts, terms), uses the
	 * object's language with the core transform_url() method. For custom
	 * links, falls back to path-based URL extraction.
	 *
	 * @param array $menu_items Array of WP_Post objects representing menu items.
	 * @return array The transformed menu items.
	 */
	public function filter_nav_menu_objects( array $menu_items ): array {
		if ( ! $this->should_transform() ) {
			return $menu_items;
		}

		$adapter = Frl_Subdomain_Adapter::init();

		foreach ( $menu_items as $item ) {
			if ( ! $item instanceof \WP_Post ) {
				continue;
			}

			$url = $item->url ?? '';
			if ( empty( $url ) ) {
				continue;
			}

			$transformed = null;

			// Best case: menu item points to a post or term — use the object.
			if ( $item->object === 'post' || $item->object === 'page' || $item->object === 'custom' ) {
				$object_id = (int) ( $item->object_id ?? 0 );
				if ( $object_id > 0 && in_array( $item->object, array( 'post', 'page' ), true ) ) {
					$lang = frl_get_language( $object_id, 'post' );
					if ( ! empty( $lang ) ) {
						$transformed = $adapter->transform_url( $url, $lang );
					}
				}
			}

			if ( $item->object === 'category' || $item->object === 'post_tag' ) {
				$object_id = (int) ( $item->object_id ?? 0 );
				if ( $object_id > 0 ) {
					$lang = frl_get_language( $object_id, 'term' );
					if ( ! empty( $lang ) ) {
						$transformed = $adapter->transform_url( $url, $lang );
					}
				}
			}

			// Fallback: custom link or unknown object → extract language from URL path.
			if ( $transformed === null ) {
				$transformed = $this->transform_single_content_url( $adapter, $url );
			}

			if ( $transformed !== null && $transformed !== $url ) {
				$item->url = $transformed;
			}
		}

		return $menu_items;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Build a flat list of all recognized hosts (main domains + subdomains).
	 *
	 * Cached in a static variable for the lifetime of the request.
	 *
	 * @return string[] List of recognized hostnames.
	 */
	private function get_recognized_hosts(): array {
		static $hosts = null;
		if ( $hosts !== null ) {
			return $hosts;
		}

		$adapter = Frl_Subdomain_Adapter::init();
		$map     = $adapter->get_domain_map();
		$hosts   = array_keys( $map );

		foreach ( $map as $config ) {
			foreach ( $config as $key => $value ) {
				if ( $key !== 'default_lang' && is_string( $value ) && $value !== '' ) {
					$hosts[] = $value;
				}
			}
		}

		$hosts = array_unique( $hosts );
		return $hosts;
	}
}
