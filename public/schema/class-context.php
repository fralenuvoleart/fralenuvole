<?php
/**
 * Schema Context Detector
 *
 * Determines the current request context for schema selection.
 * Pure detection — no side effects, no output.
 *
 * @package Fralenuvole
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schema context detector.
 *
 * Maps WordPress request state to schema contexts:
 *   'global'    — schemas that appear on every page (Organization, WebSite)
 *   'home'      — homepage-specific schemas
 *   'singular'  — single post/page/CPT schemas
 *   'archive'   — archive/listing page schemas
 *   'search'    — search results page schemas
 *   '404'       — 404 page schemas
 */
class Frl_Schema_Context {

	/**
	 * Get the primary context for the current request.
	 *
	 * @return string One of: 'global', 'home', 'singular', 'archive', 'search', '404'.
	 */
	public function get_context(): string {
		if ( is_404() ) {
			return '404';
		}

		if ( is_search() ) {
			return 'search';
		}

		if ( is_singular() ) {
			return 'singular';
		}

		if ( is_home() || is_front_page() ) {
			return 'home';
		}

		if ( is_archive() || is_post_type_archive() ) {
			return 'archive';
		}

		return 'global';
	}

	/**
	 * Get the post type for the current singular request.
	 *
	 * @return string|null Post type slug, or null if not singular.
	 */
	public function get_post_type(): ?string {
		if ( ! is_singular() ) {
			return null;
		}

		$post_type = get_post_type();
		return $post_type ?: null;
	}

	/**
	 * Get the post ID for the current singular request.
	 *
	 * @return int|null Post ID, or null if not singular.
	 */
	public function get_post_id(): ?int {
		if ( ! is_singular() ) {
			return null;
		}

		$post_id = get_the_ID();
		return $post_id ? (int) $post_id : null;
	}

	/**
	 * Get the queried object for archive contexts.
	 *
	 * @return object|null Queried object (WP_Term, WP_Post_Type, etc.), or null.
	 */
	public function get_queried_object(): ?object {
		$obj = get_queried_object();
		return $obj instanceof \WP_Term || $obj instanceof \WP_Post_Type ? $obj : null;
	}
}
