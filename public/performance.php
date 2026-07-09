<?php

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fralenuvole
 * performance.php - Performance optimization logic and hooks.
 */

add_action( 'wp_head', 'frl_add_critical_css', -999, 0 );
add_action( 'wp_footer', 'frl_add_deferred_css', 1, 0 );
add_filter( 'style_loader_tag', 'frl_defer_css', 10, 4 );

add_action( 'wp_head', 'frl_preload_featured_image', 0, 0 );
add_action( 'wp_default_scripts', 'frl_remove_jquery_migrate', 10, 1 );


/**
 * Injects critical CSS into the document head.
 *
 * If enabled, retrieves minified critical CSS and outputs it in a style tag.
 *
 * @hook wp_head
 * @priority -999
 */
function frl_add_critical_css() {
	if ( ! frl_get_option( 'critical_css' ) ) {
		return;
	}

	$css = frl_get_critical_css_data();

	if ( frl_is_array_not_empty( $css ) ) {
		printf(
			'<style id="%s-critical-css" data-lastmod="%s" data-plugin="%s" data-parsing="critical-css" data-noptimize="1">%s</style>
	',
			FRL_PREFIX,
			gmdate( 'Y-m-d-H:i', $css['mtime'] ),
			FRL_NAME,
			$css['css']
		);
	}
}

/**
 * Outputs a deferred CSS link in the footer for non-critical styles.
 *
 * Reads 'deferred.css' from the active theme's stylesheet directory,
 * outputs a <link> with media="print" onload pattern to avoid render-blocking.
 *
 * @hook wp_footer
 * @priority 1
 */
function frl_add_deferred_css() {
	if ( ! frl_get_option( 'deferred_css' ) ) {
		return;
	}

	$css_path = get_stylesheet_directory() . '/deferred.css';

	if ( ! file_exists( $css_path ) ) {
		return;
	}

	$assets  = array( 'deferred-css' => $css_path );
	$version = frl_get_assets_versions( $assets, 'deferred_css', 'versions', false );

	if ( empty( $version ) ) {
		return;
	}

	$mtime = $version['deferred-css'];
	$url   = esc_url( get_stylesheet_directory_uri() . '/deferred.css?ver=' . $mtime );

	echo "<link rel='stylesheet' id='" . FRL_PREFIX . "-deferred-css' href='{$url}' media='print' onload=\"this.media='all'\" data-plugin='" . FRL_NAME . "' data-parsing='deferred-css' data-noptimize='1'>\n";

	echo "<noscript><link rel='stylesheet' href='{$url}'></noscript>\n";
}

/**
 * Retrieves and caches minified critical CSS data.
 *
 * Reads 'critical.css' from the stylesheet directory, minifies the content,
 * and caches the result using the file's modification time.
 *
 * @return array{css: string, mtime: int}|array{} Array with 'css' and 'mtime', or empty array if unavailable.
 */
function frl_get_critical_css_data() {
	$css_path = get_stylesheet_directory() . '/critical.css';
	$css_file = array( 'critical-css' => $css_path );
	// Retrieve asset versions for the critical CSS file
	$css_version = frl_get_assets_versions( $css_file, 'critical_css', 'versions', false );
	if ( empty( $css_version ) ) {
		return array();
	}

	$mtime = $css_version['critical-css'];

	if ( ! $mtime ) {
		return array();
	}

	$critical_css = frl_cache_remember(
		'html',
		"critical_css_{$mtime}",
		function () use ( $css_path, $mtime ) {
			// Single file read operation - more efficient than checking existence first
			$css_content = file_get_contents( $css_path );
			if ( $css_content === false || empty( $css_content ) ) {
				return '';
			}

			$minified = frl_minify_css( $css_content );

			// Prepare the data to be cached
			$data = array(
				'css'   => $minified,
				'mtime' => $mtime,
			);

			return $data;
		}
	);

	return $critical_css;
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
 * Preload the featured image of a singular post. For FRL_PRELOAD_IMAGE_MOBILE_POST_TYPES,
 * image_preload_featured_responsive controls desktop srcset vs single href + mobile-hero
 * override. All other post-types always get the responsive srcset, no mobile override.
 */
function frl_preload_featured_image() {
	if ( ! is_singular() || ! frl_get_option( 'image_preload_featured' ) ) {
		return;
	}

	global $post;
	if ( ! isset( $post->ID ) ) {
		return;
	}

	$allowed_types = apply_filters( 'frl_hero_mobile_post_types', FRL_PRELOAD_IMAGE_MOBILE_POST_TYPES );
	$is_hero_type  = in_array( $post->post_type, $allowed_types, true ) || ( in_array( 'home', $allowed_types, true ) && is_front_page() );

	// Only hero post-types honor the option; all others are always responsive, never mobile-split.
	$responsive = $is_hero_type ? (bool) frl_get_option( 'image_preload_featured_responsive' ) : true;
	$has_mobile = $is_hero_type && $responsive;

	$image_size  = frl_get_featured_image_size( $post );
	$mobile_size = $has_mobile ? (string) apply_filters( 'frl_hero_mobile_image_size', FRL_PRELOAD_IMAGE_MOBILE_SIZE, $post ) : '';

	// Desktop + mobile in one cache entry — one object-cache round-trip per page view
	// instead of two, on every visit (this is the hot path; cache-miss cost below only
	// happens once per post per cache-invalidation cycle).
	$cache_key = frl_generate_cache_key( 'featured_img', (string) $post->ID, $image_size, $responsive ? 'responsive' : 'single', $mobile_size );

	$data = frl_cache_remember(
		'postdata',
		$cache_key,
		function () use ( $post, $image_size, $mobile_size, $responsive ) {
			$thumbnail_id = get_post_thumbnail_id( $post->ID ) ?: 0;
			if ( ! $thumbnail_id ) {
				return array(
					'desktop' => null,
					'mobile'  => null,
				);
			}
			$extension = frl_resolve_featured_image_extension( $thumbnail_id );

			$desktop = $responsive
				? frl_build_responsive_featured_image_preload( $thumbnail_id, $extension, $image_size )
				: frl_build_single_featured_image_preload( $thumbnail_id, $image_size, $extension );

			$mobile = $mobile_size !== '' ? frl_build_single_featured_image_preload( $thumbnail_id, $mobile_size, $extension ) : null;

			return array(
				'desktop' => $desktop,
				'mobile'  => $mobile,
			);
		}
	);

	$desktop_media = $has_mobile ? '(min-width: 768px)' : '';
	$preload_data  = $data['desktop'];
	if ( $preload_data && ( ! empty( $preload_data['srcset'] ) || ! empty( $preload_data['href'] ) ) ) {
		frl_output_preload_link( $preload_data, $desktop_media );
	}

	$mobile_data = $data['mobile'];
	if ( $mobile_data && ! empty( $mobile_data['href'] ) ) {
		frl_output_preload_link( $mobile_data, '(max-width: 767px)', '-mobile' );
	}
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
			'<link id="%s" data-plugin="%s" rel="preload" fetchPriority="high" as="image" href="%s"%s />\n',
			$link_id,
			FRL_NAME,
			esc_url( $preload_data['href'] ),
			$media_attr
		);
	} elseif ( ! empty( $preload_data['srcset'] ) ) {
		printf(
			'<link id="%s" data-plugin="%s" rel="preload" fetchPriority="high" imagesrcset="%s" imagesizes="%s" as="image"%s />
	',
			$link_id,
			FRL_NAME,
			esc_attr( $preload_data['srcset'] ),
			esc_attr( $preload_data['sizes'] ),
			$media_attr
		);
	}
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
