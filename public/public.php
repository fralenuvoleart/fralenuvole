<?php

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fralenuvole
 * public.php - Frontend execution logic and hooks.
 */
add_action( 'wp_head', 'frl_wp_head', 0, 0 );
add_action( 'wp_footer', 'frl_wp_footer', 10, 0 );
add_action( 'pre_get_posts', 'frl_alter_query', 10, 1 );
add_filter( 'rest_endpoints', 'frl_disable_rest_endpoints', 10, 1 );
add_action( 'wp_loaded', 'frl_public_scripts', 10, 1 );
add_action( 'wp_default_scripts', 'frl_remove_jquery_migrate', 10, 1 );
add_filter( 'style_loader_tag', 'frl_defer_css', 10, 4 );
add_action( 'login_enqueue_scripts', 'frl_login_page_branding', 10, 0 );

/**
 * Inject critical assets and custom HTML into the document head.
 */
function frl_wp_head() {
	frl_preload_featured_image();
	frl_add_header_html();
	frl_add_header_scripts();
}

/**
 * Inject custom HTML and scripts into the document footer.
 */
function frl_wp_footer() {
	frl_add_footer_html();
	frl_add_footer_scripts();
}

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
 * Build responsive imagesrcset from attachment metadata. Skips sizes where the format variant doesn't exist on disk.
 *
 * @param int    $thumbnail_id Attachment ID.
 * @param string $extension    File extension (e.g., '.avif'), empty for original format.
 * @param string $upload_dir   Upload base directory.
 * @param string $upload_url   Upload base URL.
 * @return string Srcset string, or empty on failure.
 */
function frl_build_featured_image_srcset( int $thumbnail_id, string $extension, string $upload_dir, string $upload_url ): string {
	$metadata = wp_get_attachment_metadata( $thumbnail_id );
	if ( ! $metadata || empty( $metadata['file'] ) ) {
		return '';
	}

	$dirname = trailingslashit( dirname( $metadata['file'] ) );
	$entries = array();

	// Include full-size original
	$full_file  = $upload_dir . '/' . $metadata['file'];
	$full_width = $metadata['width'] ?? 0;
	if ( $full_width && ( ! $extension || file_exists( $full_file . $extension ) ) ) {
		$entries[ $full_width ] = $upload_url . '/' . $metadata['file'] . $extension;
	}

	// Include intermediate sizes
	$sizes = $metadata['sizes'] ?? array();
	foreach ( $sizes as $size_data ) {
		if ( empty( $size_data['file'] ) || empty( $size_data['width'] ) ) {
			continue;
		}
		$sized_path = $upload_dir . '/' . $dirname . $size_data['file'];
		if ( $extension && ! file_exists( $sized_path . $extension ) ) {
			continue; // Format variant doesn't exist for this size, skip it
		}
		$entries[ (int) $size_data['width'] ] = $upload_url . '/' . $dirname . $size_data['file'] . $extension;
	}

	if ( empty( $entries ) ) {
		return '';
	}

	// Sort by width ascending, build srcset string
	ksort( $entries, SORT_NUMERIC );
	$parts = array();
	foreach ( $entries as $width => $url ) {
		$parts[] = "{$url} {$width}w";
	}

	return implode( ', ', $parts );
}

/**
 * Resolve the first available next-gen format variant for an attachment's original file, else ''.
 *
 * @param int $thumbnail_id Attachment ID.
 * @return string Extension (e.g. '.avif') or '' if no candidate variant exists.
 */
function frl_resolve_featured_image_extension( int $thumbnail_id ): string {
	$original_path = get_attached_file( $thumbnail_id );
	if ( ! $original_path ) {
		return '';
	}
	foreach ( FRL_PRELOAD_IMAGE_EXT_CANDIDATES as $candidate ) {
		if ( file_exists( $original_path . $candidate ) ) {
			return $candidate;
		}
	}
	return '';
}

/**
 * Output a preload <link> tag for either responsive (imagesrcset/imagesizes) or single (href) preload.
 *
 * @param array  $preload_data Array with 'srcset'/'sizes' (desktop) or 'href' (mobile).
 * @param string $media        Optional media query attribute value.
 * @param string $id_suffix    Optional suffix appended to the <link> id attribute (e.g., '-mobile').
 */
function frl_output_preload_link( array $preload_data, string $media = '', string $id_suffix = '' ): void {
	$media_attr = $media ? ' media="' . esc_attr( $media ) . '"' : '';
	$link_id    = FRL_PREFIX . '-preload-img' . $id_suffix;

	if ( ! empty( $preload_data['href'] ) ) {
		printf(
			'<link id="%s" data-plugin="%s" rel="preload" fetchPriority="high" as="image" href="%s"%s />',
			$link_id,
			FRL_NAME,
			esc_url( $preload_data['href'] ),
			$media_attr
		);
	} elseif ( ! empty( $preload_data['srcset'] ) ) {
		printf(
			'<link id="%s" data-plugin="%s" rel="preload" fetchPriority="high" imagesrcset="%s" imagesizes="%s" as="image"%s />',
			$link_id,
			FRL_NAME,
			esc_attr( $preload_data['srcset'] ),
			esc_attr( $preload_data['sizes'] ),
			$media_attr
		);
	}
}

/**
 * Build a single-href featured-image preload (no srcset enumeration). Used for desktop
 * when responsive is off, and always for the mobile hero preload.
 *
 * @param int    $thumbnail_id Attachment ID.
 * @param string $size         WP image size name.
 * @param string $extension    File extension to prefer, empty for original format.
 * @return array{href: string}|null
 */
function frl_build_single_featured_image_preload( int $thumbnail_id, string $size, string $extension ): ?array {
	$img_src = wp_get_attachment_image_src( $thumbnail_id, $size );
	if ( ! $img_src || empty( $img_src[0] ) ) {
		return null;
	}

	$url = $img_src[0];

	// Apply extension if configured and a variant exists for this specific size
	if ( ! empty( $extension ) ) {
		$original_path = get_attached_file( $thumbnail_id );
		if ( $original_path && file_exists( $original_path . $extension ) ) {
			$metadata = wp_get_attachment_metadata( $thumbnail_id );
			if ( $metadata && ! empty( $metadata['file'] ) ) {
				$upload_dir = wp_upload_dir();
				$dirname    = trailingslashit( dirname( $metadata['file'] ) );
				$sizes      = $metadata['sizes'] ?? array();

				if ( isset( $sizes[ $size ] ) ) {
					$variant_path = $upload_dir['basedir'] . '/' . $dirname . $sizes[ $size ]['file'] . $extension;
					if ( file_exists( $variant_path ) ) {
						$url = $upload_dir['baseurl'] . '/' . $dirname . $sizes[ $size ]['file'] . $extension;
					}
				} else {
					// Size resolved to full/original — use original file path
					$variant_path = $original_path . $extension;
					if ( file_exists( $variant_path ) ) {
						$url = wp_get_attachment_url( $thumbnail_id ) . $extension;
					}
				}
			}
		}
	}

	return array( 'href' => $url );
}

/**
 * Build responsive (imagesrcset/imagesizes) featured-image preload data.
 *
 * @param int    $thumbnail_id Attachment ID.
 * @param string $extension    File extension to prefer, empty for original format.
 * @param string $image_size   WP image size name for the `sizes` attribute.
 * @return array{srcset: string, sizes: string}|null
 */
function frl_build_responsive_featured_image_preload( int $thumbnail_id, string $extension, string $image_size ): ?array {
	$upload_dir = wp_upload_dir();
	$srcset     = frl_build_featured_image_srcset(
		$thumbnail_id,
		$extension,
		$upload_dir['basedir'],
		$upload_dir['baseurl']
	);

	if ( empty( $srcset ) ) {
		return null;
	}

	$sizes = wp_get_attachment_image_sizes( $thumbnail_id, $image_size );

	return array(
		'srcset' => $srcset,
		'sizes'  => $sizes,
	);
}

/**
 * Preload the featured image of a singular post. Desktop: single href, or responsive
 * srcset when image_preload_featured_responsive is on. Mobile hero override (responsive
 * mode only) forces a fixed size to counter the srcset's viewport-based downscaling.
 */
function frl_preload_featured_image() {
	if ( ! is_singular() || ! frl_get_option( 'image_preload_featured' ) ) {
		return;
	}

	global $post;
	if ( ! isset( $post->ID ) ) {
		return;
	}

	// Lazily resolved and shared between the desktop/mobile cache-miss closures below so a
	// request that misses both caches for the same post only calls get_post_thumbnail_id()
	// once. Declared here (not inside either closure) so it stays unset — and therefore
	// free — on the common cache-hit path, where neither closure runs at all.
	$resolved_thumbnail_id = null;
	$resolve_thumbnail_id  = function () use ( $post, &$resolved_thumbnail_id ) {
		if ( $resolved_thumbnail_id === null ) {
			$resolved_thumbnail_id = get_post_thumbnail_id( $post->ID ) ?: 0;
		}
		return $resolved_thumbnail_id;
	};

	// Read once: used for desktop preload and to gate the mobile-hero override below.
	$responsive = (bool) frl_get_option( 'image_preload_featured_responsive' );

	// Cache processed images within a request
	static $preload_cache = array();
	if ( isset( $preload_cache[ $post->ID ] ) ) {
		$preload_data = $preload_cache[ $post->ID ];
	} else {
		$image_size = frl_get_featured_image_size( $post );

		// Cache key includes the responsive flag so toggling the option never serves a
		// stale, differently-shaped ('srcset' vs 'href') cached entry.
		$cache_key = frl_generate_cache_key( 'featured_img', (string) $post->ID, $image_size, $responsive ? 'responsive' : 'single' );

		$preload_data = frl_cache_remember(
			'postdata',
			$cache_key,
			function () use ( $image_size, $resolve_thumbnail_id, $responsive ) {
				$thumbnail_id = $resolve_thumbnail_id();
				if ( ! $thumbnail_id ) {
					return null;
				}
				$extension = frl_resolve_featured_image_extension( $thumbnail_id );

				return $responsive
					? frl_build_responsive_featured_image_preload( $thumbnail_id, $extension, $image_size )
					: frl_build_single_featured_image_preload( $thumbnail_id, $image_size, $extension );
			}
		);

		$preload_cache[ $post->ID ] = $preload_data;
	}

	// Mobile-hero override only applies in responsive mode — no-op otherwise.
	$has_mobile = false;

	if ( $responsive ) {
		$allowed_types = apply_filters( 'frl_hero_mobile_post_types', FRL_PRELOAD_IMAGE_MOBILE_POST_TYPES );
		if ( in_array( $post->post_type, $allowed_types, true ) || ( in_array( 'home', $allowed_types, true ) && is_front_page() ) ) {
			$has_mobile = true;
		}
	}

	$desktop_media = $has_mobile ? '(min-width: 768px)' : '';

	if ( $preload_data && ( ! empty( $preload_data['srcset'] ) || ! empty( $preload_data['href'] ) ) ) {
		frl_output_preload_link( $preload_data, $desktop_media );
	}

	// Mobile hero preload: forces a fixed size, overriding the responsive srcset's pick.
	if ( $has_mobile ) {
		$mobile_size = (string) apply_filters( 'frl_hero_mobile_image_size', FRL_PRELOAD_IMAGE_MOBILE_SIZE, $post );

		$mobile_cache_key = frl_generate_cache_key( 'featured_img_mobile', (string) $post->ID, $mobile_size );

		$mobile_data = frl_cache_remember(
			'postdata',
			$mobile_cache_key,
			function () use ( $mobile_size, $resolve_thumbnail_id ) {
				$thumbnail_id = $resolve_thumbnail_id();
				if ( ! $thumbnail_id ) {
					return null;
				}
				$extension = frl_resolve_featured_image_extension( $thumbnail_id );

				return frl_build_single_featured_image_preload( $thumbnail_id, $mobile_size, $extension );
			}
		);

		if ( $mobile_data && ! empty( $mobile_data['href'] ) ) {
			frl_output_preload_link( $mobile_data, '(max-width: 767px)', '-mobile' );
		}
	}
}

/**
 * Defer specified CSS files to improve page load performance.
 *
 * @param string $html   The link tag for the enqueued style.
 * @param string $handle The style's registered handle.
 * @param string $href   The stylesheet's source URL.
 * @param string $media  The stylesheet's media attribute.
 * @return string Modified HTML.
 */
function frl_defer_css( string $html, string $handle, string $href, string $media ): string {
	if ( ! frl_get_option( 'defer_css' ) ) {
		return $html;
	}

	static $defer_handles = null;

	if ( $defer_handles === null ) {
		$defer_handles = frl_textlist_to_array( frl_get_option( 'defer_css_handles' ) );
	}

	if ( empty( $defer_handles ) ) {
		return $html;
	}

	foreach ( $defer_handles as $script_parts ) {
		// Extract the script name (first element since these are simple strings)
		$script = $script_parts[0];
		if ( is_string( $script ) ) {
			if ( str_contains( $href, $script ) ) {
				// Quote-agnostic match on the media="all"/media='all' attribute — WP core
				// currently emits single quotes, but a literal str_replace("media='all'", ...)
				// would silently no-op (leaving the stylesheet un-deferred, with no error) if a
				// theme/plugin filtering style_loader_tag ever normalizes to double quotes.
				$deferred_html = preg_replace_callback(
					'/media=([\'"])all\1/',
					function () {
						return "data-plugin='" . FRL_NAME . "' data-parsing='defer-css' media='print' onload='this.media=\"all\"'";
					},
					$html,
					1
				);
				return $deferred_html ?? $html;
			}
		}
	}
	return $html;
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
 * Enqueue custom scripts defined in the header_scripts option.
 */
function frl_add_header_scripts() {
	$assets = frl_get_processed_assets_from_option( 'header_scripts' );
	frl_enqueue_scripts( $assets, 'header_scripts' );
}

/**
 * Enqueue custom scripts defined in the footer_scripts option.
 */
function frl_add_footer_scripts() {
	$assets = frl_get_processed_assets_from_option( 'footer_scripts' );
	frl_enqueue_scripts( $assets, 'footer_scripts' );
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
			if ( ! frl_get_option( $option_name ) ) {
				return '';
			}

			$html = frl_get_option( $option_name );
			if ( $php_enabled_option && frl_get_option( $php_enabled_option ) ) {
				$processed_html = frl_process_php_string( $html, $option_name );
				return $processed_html;
			}
			return $html;
		}
	);

	return $html_option;
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
 * Remove jquery-migrate.js from the enqueued scripts if enabled.
 *
 * @param WP_Scripts $scripts The WP_Scripts instance, passed by the
 *                            'wp_default_scripts' action.
 * @return void
 */
function frl_remove_jquery_migrate( WP_Scripts $scripts ): void {
	if ( ! frl_get_option( 'remove_jquery_mig' ) ) {
		return;
	}

	if ( ! empty( $scripts->registered['jquery'] ) ) {
		$jquery_dependencies                 = $scripts->registered['jquery']->deps;
		$scripts->registered['jquery']->deps = array_diff( $jquery_dependencies, array( 'jquery-migrate' ) );
	}
}


/**
 * Disable sensitive or non-essential REST API endpoints for unauthenticated users.
 *
 * The endpoint list is defined in the FRL_REST_ENDPOINTS constant in config/config-base.php.
 * /oembed/1.0 and /wp/v2/oembed are intentionally absent — the frl_disable_oembed() function
 * handles oEmbed REST route removal through its own toggle (disable_oembed).
 *
 * @see config/config-base.php:FRL_REST_ENDPOINTS
 * @see includes/shared/website-features.php:124
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
 * Optimize non-main queries and enforce menu_order for specific custom post types.
 */
function frl_alter_query( WP_Query $query ): void {
	if ( ! $query instanceof WP_Query || $query->is_main_query() ) {
		return;
	}

	$query->set( 'update_post_meta_cache', false );
	$query->set( 'update_post_term_cache', false );
	$query->set( 'no_found_rows', true );
	$query->set( 'ignore_sticky_posts', true );
	// Deliberate: secondary queries are scoped to public, published, non-password-protected content by design.
	$query->set( 'post_status', 'publish' );
	$query->set( 'has_password', false );

	static $cached_cpts = null;
	if ( $cached_cpts === null ) {
		$cached_cpts = frl_textlist_to_array( frl_get_option( 'custom_wp_query' ) );
	}

	if ( empty( $cached_cpts ) ) {
		return;
	}

	// Flatten the array to get just the CPT names (first element of each sub-array)
	$cpts_list = array_column( $cached_cpts, 0 );

	// Add post type check for any custom post type
	$post_type = $query->get( 'post_type' );

	if ( $post_type && in_array( $post_type, $cpts_list, true ) ) {
		$query->set( 'orderby', 'menu_order' );
		$query->set( 'order', 'ASC' );
	}
}
