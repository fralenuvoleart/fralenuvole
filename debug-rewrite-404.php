<?php
/**
 * TEMPORARY DEBUG: Diagnose 404 for team-member CPT URL
 *
 * Target URL: /ru/about/rati-iese-abashmadze/
 *
 * To use: include this file from fralenuvole.php or public/public.php
 * Remove after debugging is complete.
 *
 * Output: writes to PHP error log and /wp-content/debug-rewrite.log
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Only run on frontend requests matching our target URL pattern
add_action( 'parse_request', 'frl_debug_404_parse_request', 1, 1 );
add_filter( 'request', 'frl_debug_404_request_filter', 99999, 1 );
add_action( 'wp', 'frl_debug_404_wp_action', 1, 1 );
add_action( 'template_redirect', 'frl_debug_404_template_redirect', 0 );

/**
 * Log a debug message to both error log and a dedicated file.
 */
function frl_debug_404_log( string $message, array $data = array() ): void {
	$log_dir  = WP_CONTENT_DIR;
	$log_file = $log_dir . '/debug-rewrite.log';

	$timestamp = gmdate( 'Y-m-d H:i:s' );
	$entry     = "[{$timestamp}] {$message}";
	if ( ! empty( $data ) ) {
		$entry .= ' ' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
	}
	$entry .= "\n";

	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	error_log( $entry );

	// Also write to dedicated log file
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	@file_put_contents( $log_file, $entry, FILE_APPEND | LOCK_EX );
}

/**
 * Early parse_request: log what WP matched.
 */
function frl_debug_404_parse_request( WP $wp ): void {
	$request_uri = $_SERVER['REQUEST_URI'] ?? '';

	// Only log for URLs containing 'about' or 'team-member' or 'rati'
	if ( strpos( $request_uri, 'about' ) === false
		&& strpos( $request_uri, 'team-member' ) === false
		&& strpos( $request_uri, 'rati' ) === false ) {
		return;
	}

	frl_debug_404_log( '=== PARSE_REQUEST ===' );
	frl_debug_404_log( 'REQUEST_URI', array( 'uri' => $request_uri ) );
	frl_debug_404_log( 'Matched query vars', $wp->query_vars );
	frl_debug_404_log( 'Matched rule', array( 'matched_rule' => $wp->matched_rule ?? 'none' ) );
	frl_debug_404_log( 'Matched query', array( 'matched_query' => $wp->matched_query ?? 'none' ) );

	// Check if the post exists directly
	$path     = trim( (string) ( parse_url( $request_uri, PHP_URL_PATH ) ?? '' ), '/' );
	$segments = explode( '/', $path );

	frl_debug_404_log( 'Path segments', $segments );

	// Try to find the post by slug (last segment)
	$slug = end( $segments );
	if ( ! empty( $slug ) ) {
		// Check all public post types
		$post_types = get_post_types( array( 'public' => true ) );
		foreach ( $post_types as $pt ) {
			$posts = get_posts(
				array(
					'name'           => $slug,
					'post_type'      => $pt,
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'fields'         => 'ids',
				)
			);
			if ( ! empty( $posts ) ) {
				$post = get_post( $posts[0] );
				frl_debug_404_log(
					'Post found by slug',
					array(
						'ID'          => $post->ID,
						'post_type'   => $post->post_type,
						'post_name'   => $post->post_name,
						'post_status' => $post->post_status,
						'permalink'   => get_permalink( $post ),
					)
				);
			}
		}
	}

	// Check if 'team-member' CPT is registered
	if ( post_type_exists( 'team-member' ) ) {
		$pto = get_post_type_object( 'team-member' );
		frl_debug_404_log(
			'team-member CPT registered',
			array(
				'rewrite_slug' => $pto->rewrite['slug'] ?? 'none',
				'has_archive'  => $pto->has_archive,
				'public'       => $pto->public,
			)
		);
	} else {
		frl_debug_404_log( 'team-member CPT NOT registered', array() );
	}

	// Check if 'about' is a registered CPT
	if ( post_type_exists( 'about' ) ) {
		$pto = get_post_type_object( 'about' );
		frl_debug_404_log(
			'about CPT registered',
			array(
				'rewrite_slug' => $pto->rewrite['slug'] ?? 'none',
			)
		);
	}

	// Dump matching rewrite rules
	global $wp_rewrite;
	if ( $wp_rewrite instanceof WP_Rewrite ) {
		$matching_rules = array();
		foreach ( $wp_rewrite->wp_rewrite_rules() as $pattern => $query ) {
			if ( strpos( $pattern, 'about' ) !== false || strpos( $pattern, 'team-member' ) !== false || strpos( $pattern, 'team_member' ) !== false ) {
				$matching_rules[ $pattern ] = $query;
			}
		}
		if ( ! empty( $matching_rules ) ) {
			frl_debug_404_log( 'Rewrite rules matching "about" or "team-member"', $matching_rules );
		} else {
			frl_debug_404_log( 'NO rewrite rules found matching "about" or "team-member"', array() );
		}

		// Also check extra_rules_top
		$extra_top      = $wp_rewrite->extra_rules_top;
		$matching_extra = array();
		foreach ( $extra_top as $pattern => $query ) {
			if ( strpos( $pattern, 'about' ) !== false || strpos( $pattern, 'team-member' ) !== false ) {
				$matching_extra[ $pattern ] = $query;
			}
		}
		if ( ! empty( $matching_extra ) ) {
			frl_debug_404_log( 'Extra top rules matching "about" or "team-member"', $matching_extra );
		}
	}
}

/**
 * Late request filter: log final query vars after all plugins processed.
 */
function frl_debug_404_request_filter( array $query_vars ): array {
	$request_uri = $_SERVER['REQUEST_URI'] ?? '';

	if ( strpos( $request_uri, 'about' ) === false
		&& strpos( $request_uri, 'team-member' ) === false
		&& strpos( $request_uri, 'rati' ) === false ) {
		return $query_vars;
	}

	frl_debug_404_log( '=== REQUEST FILTER (final) ===' );
	frl_debug_404_log( 'Final query vars', $query_vars );

	// Check for rewriter-specific query vars
	if ( isset( $query_vars['frl_cpt_base_path'] ) ) {
		frl_debug_404_log( 'frl_cpt_base_path present', array( 'value' => $query_vars['frl_cpt_base_path'] ) );
	}
	if ( isset( $query_vars['frl_tax_base_path'] ) ) {
		frl_debug_404_log( 'frl_tax_base_path present', array( 'value' => $query_vars['frl_tax_base_path'] ) );
	}

	return $query_vars;
}

/**
 * wp action: log what WP_Query resolved.
 */
function frl_debug_404_wp_action( WP $wp ): void {
	$request_uri = $_SERVER['REQUEST_URI'] ?? '';

	if ( strpos( $request_uri, 'about' ) === false
		&& strpos( $request_uri, 'team-member' ) === false
		&& strpos( $request_uri, 'rati' ) === false ) {
		return;
	}

	frl_debug_404_log( '=== WP ACTION ===' );
	frl_debug_404_log( 'is_404', array( 'is_404' => is_404() ) );
	frl_debug_404_log( 'is_singular', array( 'is_singular' => is_singular() ) );
	frl_debug_404_log( 'is_single', array( 'is_single' => is_single() ) );
	frl_debug_404_log( 'is_page', array( 'is_page' => is_page() ) );

	global $wp_query;
	frl_debug_404_log(
		'WP_Query flags',
		array(
			'is_404'            => $wp_query->is_404,
			'is_singular'       => $wp_query->is_singular,
			'is_single'         => $wp_query->is_single,
			'is_page'           => $wp_query->is_page,
			'post_count'        => $wp_query->post_count,
			'found_posts'       => $wp_query->found_posts,
			'queried_object_id' => $wp_query->queried_object_id,
		)
	);

	if ( $wp_query->queried_object ) {
		frl_debug_404_log(
			'Queried object',
			array(
				'class'     => get_class( $wp_query->queried_object ),
				'ID'        => $wp_query->queried_object->ID ?? 'N/A',
				'post_type' => $wp_query->queried_object->post_type ?? 'N/A',
			)
		);
	}
}

/**
 * template_redirect: log final state before template loads.
 */
function frl_debug_404_template_redirect(): void {
	$request_uri = $_SERVER['REQUEST_URI'] ?? '';

	if ( strpos( $request_uri, 'about' ) === false
		&& strpos( $request_uri, 'team-member' ) === false
		&& strpos( $request_uri, 'rati' ) === false ) {
		return;
	}

	frl_debug_404_log( '=== TEMPLATE REDIRECT ===' );
	frl_debug_404_log( 'is_404', array( 'is_404' => is_404() ) );

	// Check Polylang language detection
	if ( function_exists( 'pll_current_language' ) ) {
		frl_debug_404_log( 'Polylang current language', array( 'lang' => pll_current_language() ) );
	}
	if ( function_exists( 'pll_default_language' ) ) {
		frl_debug_404_log( 'Polylang default language', array( 'lang' => pll_default_language() ) );
	}

	// Check rewriter feature states
	if ( class_exists( 'Frl_Rewriter_Coordinator' ) ) {
		$coordinator    = Frl_Rewriter_Coordinator::init();
		$features       = $coordinator->get_features();
		$feature_states = array();
		foreach ( $features as $feature ) {
			$feature_states[] = array(
				'name'     => $feature->get_name(),
				'enabled'  => $feature->is_enabled(),
				'priority' => $feature->get_priority(),
			);
		}
		frl_debug_404_log( 'Rewriter feature states', $feature_states );
	}

	frl_debug_404_log( '=== END DEBUG ===' . "\n", array() );
}
