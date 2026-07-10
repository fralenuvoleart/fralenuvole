<?php
/**
 * Navigation features
 * - Makes WordPress navigation menus translatable (Polylang)
 * - Translates wp_navigation posts between languages
 *
 * @package Fralenuvole
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'pll_get_post_types', 'frl_making_wp_navigation_translatable', 10, 2 );
add_filter( 'block_type_metadata_settings', 'frl_render_block_core_navigation_translation', 10, 2 );
add_action( 'init', 'frl_nav_menu_custom_urls_init', 20 );

/**
 * Registers the 'wp_navigation' post type as translatable.
 *
 * @param array $post_types List of translatable post types.
 * @param bool  $is_settings Whether the current context is the settings page.
 * @return array Modified list of post types.
 */
function frl_making_wp_navigation_translatable( $post_types, $is_settings ) {
	if ( ! $is_settings ) {
		$post_types['wp_navigation'] = 'wp_navigation';
	}
	return $post_types;
}

/**
 * Injects a custom render callback into the core navigation block to handle language-specific menus.
 *
 * @param array $settings  Block settings.
 * @param array $metadata  Block metadata.
 * @return array Modified settings with the render callback.
 */
function frl_render_block_core_navigation_translation( $settings, $metadata ) {
	// Only target core navigation blocks in multilingual environments
	if ( 'core/navigation' !== $metadata['name'] || ! frl_multilingual_function_exists( 'pll_get_post' ) ) {
		return $settings;
	}

	// Guard: Skip custom render callback in REST API and admin context. Using the default renderer
	// prevents navigation block preview issues in the editor.
	if ( frl_is_rest_api_request() || is_admin() ) {
		return $settings;
	}

	// Retrieve current language code
	$current_lang = frl_get_language();

	// Define a custom render callback to resolve translated navigation IDs
	$settings['render_callback'] = function ( $attributes, $content, $block ) use ( $current_lang ) {
		// Render normally if no reference ID is provided
		if ( ! isset( $attributes['ref'] ) ) {
			return render_block_core_navigation( $attributes, $content, $block );
		}

		$nav_id       = (int) $attributes['ref'];
		$final_nav_id = $nav_id; // Default to original ID

		// Resolve translated navigation ID — always attempt translation regardless of
		// default language. pll_get_post() returns the original ID when no translation
		// exists, so the guard would be redundant and broke subdomain adapter setups
		// where default_lang is overridden at runtime to match current_lang.
		if ( ! empty( $current_lang ) ) {
			$cache_key = "wp_navigation_{$nav_id}";

			$translated_id = frl_cache_remember(
				'permalinks',
				$cache_key,
				function () use ( $nav_id, $current_lang ) {
					// Fetch translated post ID using Polylang
					/** @disregard P1010 Undefined function — Polylang API */
					return pll_get_post( $nav_id, $current_lang );
				}
			);

			// Use translated ID if valid and different from original
			if ( $translated_id > 0 && $translated_id !== $nav_id ) {
				$final_nav_id = (int) $translated_id;
			}
		}

		// Update reference ID for the original renderer
		$attributes['ref'] = $final_nav_id;

		// Delegate to the original renderer to maintain core functionality and assets
		return render_block_core_navigation( $attributes, $content, $block );
	};

	return $settings;
}

/**
 * Initialize nav menu URL transform processing.
 *
 * Hooks into block rendering to transform #frl_url_* fragment URLs
 * in navigation-link blocks into real URLs.
 */
function frl_nav_menu_custom_urls_init() {
	if ( ! frl_get_option( 'nav_menu_custom_urls' ) ) {
		return;
	}

	add_filter( 'render_block', 'frl_process_nav_menu_url_transforms', 10, 2 );
}

/**
 * Transform #frl_url_* fragment URLs in navigation-link blocks.
 *
 * Pattern: #frl_url_{type}={value}
 * Collects handlers via 'frl_nav_menu_url_transforms' filter.
 *
 * @param string $block_content Rendered block HTML
 * @param array  $block         Block data
 * @return string Modified block HTML
 */
function frl_process_nav_menu_url_transforms( $block_content, $block ) {
	// Guard: Skip URL transforms in REST API and admin contexts to prevent
	// modifying block content in responses consumed by the block editor.
	if ( frl_is_rest_api_request() || is_admin() ) {
		return $block_content;
	}

	// Only target navigation-link and navigation-submenu blocks
	if ( ! in_array( $block['blockName'], array( 'core/navigation-link', 'core/navigation-submenu' ), true ) ) {
		return $block_content;
	}

	/**
	 * Collect URL transform handlers.
	 *
	 * Each handler adds a type => callback pair.
	 * Callback receives $value, returns URL string or false.
	 *
	 * @param array $handlers Empty array to populate.
	 */
	$handlers = apply_filters( 'frl_nav_menu_url_transforms', array() );

	if ( empty( $handlers ) ) {
		return $block_content;
	}

	// Extract URL from block attributes
	$raw_url = $block['attrs']['url'] ?? '';
	if ( empty( $raw_url ) ) {
		return $block_content;
	}

	// Decode URL-encoded characters for pattern matching
	$url = str_replace( '%7C', '|', $raw_url );

	// Match #frl_url_{type}={value} pattern (# is optional)
	if ( ! preg_match( '/^#?frl_url_([a-z0-9_]+)=(.+)$/', $url, $matches ) ) {
		return $block_content;
	}

	$type  = $matches[1];
	$value = $matches[2];

	if ( ! isset( $handlers[ $type ] ) ) {
		return $block_content;
	}

	$resolved = call_user_func( $handlers[ $type ], $value );
	if ( $resolved && is_string( $resolved ) && filter_var( $resolved, FILTER_VALIDATE_URL ) ) {
		// Replace href in rendered HTML (search both raw and esc_url'd versions)
		$search_raw    = 'href="' . $raw_url . '"';
		$search_esc    = 'href="' . esc_url( $raw_url ) . '"';
		$block_content = str_replace( $search_raw, 'href="' . esc_url( $resolved ) . '"', $block_content );
		$block_content = str_replace( $search_esc, 'href="' . esc_url( $resolved ) . '"', $block_content );
	}

	return $block_content;
}
