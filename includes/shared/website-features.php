<?php
/**
 * Website feature management.
 *
 * Provides functionality to disable unnecessary WordPress core features
 * such as comments, oEmbed, and emojis to improve performance and security.
 *
 * @package Fralenuvole
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Disables selected WordPress core features based on plugin options.
 *
 * This is the main entry point for feature disabling, typically called during initialization.
 * Also handles the removal of Dashicons for non-logged-in users on the frontend.
 */
function frl_disable_wp_core_features() {
	if ( frl_get_option( 'disable_oembed' ) ) {
		frl_disable_oembed();
	}

	if ( frl_get_option( 'disable_emojis' ) ) {
		frl_remove_emojis();
	}

	if ( frl_get_option( 'disable_comments' ) ) {
		frl_disable_comments();
	}

	if ( frl_get_option( 'heartbeat_control' ) ) {
		frl_heartbeat_init();
	}

	if ( ! frl_is_logged_in() && ! is_login() ) {
		wp_dequeue_style( 'dashicons' );
		wp_deregister_style( 'dashicons' );
	}
}

/**
 * Disables oEmbed discovery to reduce external requests.
 *
 * @return void
 */
function frl_disable_oembed_discovery(): void {
	add_filter( 'embed_oembed_discover', '__return_false', 10, 0 );
}

/**
 * Disables oEmbed discovery and parsing functionality.
 */
function frl_disable_oembed() {
	// Use standard WordPress removal functions for built-in hooks
	remove_action( 'rest_api_init', 'wp_oembed_register_route' );
	remove_filter( 'oembed_dataparse', 'wp_filter_oembed_result', 10 );
	remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
	remove_action( 'wp_head', 'wp_oembed_add_host_js' );

	add_filter( 'embed_oembed_discover', '__return_false', 10, 0 );
}

/**
 * Removes the wpemoji plugin from TinyMCE.
 *
 * @param string[] $plugins List of TinyMCE plugins.
 * @return string[] Filtered list of plugins.
 */
function frl_disable_emojis_tinymce( $plugins ) {
	if ( frl_is_array_not_empty( $plugins ) ) {
		return array_diff( $plugins, array( 'wpemoji' ) );
	} else {
		return array();
	}
}

/**
 * Removes the emoji CDN hostname from DNS prefetching hints.
 *
 * @param string[] $urls URLs to print for resource hints.
 * @param string $relation_type The relation type the URLs are printed for.
 * @return string[] Filtered list of URLs.
 */
function frl_disable_emojis_remove_dns_prefetch( $urls, $relation_type ) {
	if ( 'dns-prefetch' === $relation_type ) {
		/** This filter is documented in wp-includes/formatting.php */
		$emoji_svg_url = apply_filters( 'emoji_svg_url', 'https://s.w.org/images/core/emoji/2/svg/' );

		$urls = array_diff( $urls, array( $emoji_svg_url ) );
	}
	return $urls;
}

/**
 * Disables WordPress emoji support across the site and admin area.
 */
function frl_remove_emojis() {
	if ( ! frl_get_option( 'disable_emojis' ) ) {
		return;
	}

	remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
	remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
	remove_action( 'wp_print_styles', 'print_emoji_styles' );
	remove_action( 'admin_print_styles', 'print_emoji_styles' );
	remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
	remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
	remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );

	// Avoid registering callbacks that live in public components when the plugin front-end is disabled.
	if ( ! frl_get_option( 'disable_plugin' ) ) {
		add_filter( 'tiny_mce_plugins', 'frl_disable_emojis_tinymce', 10, 1 );
		add_filter( 'wp_resource_hints', 'frl_disable_emojis_remove_dns_prefetch', 10, 2 );
	}
}

/**
 * Completely disables WordPress comments and pings.
 *
 * This function performs several actions:
 * - Removes comment support from all post types.
 * - Closes comments/pings for all published posts in the database (one-time operation).
 * - Removes comment-related UI elements from the admin menu and admin bar.
 * - Disables comment REST API endpoints and feeds.
 * - Unregisters comment widgets and filters comment status.
 * - Removes the recent comments dashboard widget.
 */
function frl_disable_comments() {
	if ( frl_is_already_running( __FUNCTION__ ) ) {
		return;
	}

	// Remove comment support from all post types
	foreach ( get_post_types() as $post_type ) {
		if ( post_type_supports( $post_type, 'comments' ) ) {
			remove_post_type_support( $post_type, 'comments' );
			remove_post_type_support( $post_type, 'trackbacks' );
		}
	}

	// Schedule one-time cron to batch-close comments. Completion marker uses
	// the _frl_ internal-state convention (see systemPatterns.md). Skipped for REST/AJAX.
	if ( frl_is_admin() && ! frl_is_rest_api_request() && ! wp_doing_ajax() ) {
		$completed = get_option( '_frl_disable_comments_completed' );
		if ( $completed !== '1' && ! wp_next_scheduled( 'frl_disable_comments_batch' ) ) {
			wp_schedule_single_event( time() + 5, 'frl_disable_comments_batch' );
		}
	}

	// Handle the scheduled batch update
	if ( ! has_action( 'frl_disable_comments_batch', 'frl_run_disable_comments_batch' ) ) {
		add_action( 'frl_disable_comments_batch', 'frl_run_disable_comments_batch' );
	}

	// Admin-UI-only hooks (skipped during REST/AJAX).
	if ( ! frl_is_rest_api_request() && ! wp_doing_ajax() ) {
		add_action(
			'admin_menu',
			function () {
				remove_menu_page( 'edit-comments.php' );
			},
			10,
			0
		);

		add_action(
			'admin_bar_menu',
			function ( $wp_admin_bar ) {
				$wp_admin_bar->remove_node( 'comments' );
			},
			999,
			1
		);

		add_action(
			'widgets_init',
			function () {
				unregister_widget( 'WP_Widget_Recent_Comments' );
			},
			10,
			0
		);

		add_action(
			'admin_init',
			function () {
				remove_meta_box( 'dashboard_recent_comments', 'dashboard', 'normal' );
			},
			10,
			0
		);
	}

	// Unconditional filters: enforce "comments disabled" for REST/feed requests.
	add_filter(
		'rest_endpoints',
		function ( $endpoints ) {
			unset( $endpoints['/wp/v2/comments'] );
			unset( $endpoints['/wp/v2/comments/(?P<id>[\d]+)'] );
			return $endpoints;
		}
	);

	add_action(
		'template_redirect',
		function () {
			if ( is_comment_feed() ) {
				$redirect_url = home_url();
				if ( ! empty( $_GET ) ) {
					$redirect_url = add_query_arg( $_GET, $redirect_url );
				}
				wp_redirect( $redirect_url );
				exit;
			}
		},
		999,
		0
	);

	add_filter( 'comments_open', '__return_false', 20, 2 );
	add_filter( 'pings_open', '__return_false', 20, 2 );
	add_filter( 'comments_array', '__return_empty_array', 10, 2 );

	frl_is_already_running( __FUNCTION__, true );
}

/**
 * Cron handler: batch-close comments on all published posts.
 *
 * Scheduled background task to avoid a synchronous table-wide UPDATE on
 * admin_init. See frl_disable_comments() for the completion-marker rationale.
 *
 * @return void
 */
function frl_run_disable_comments_batch(): void {
	global $wpdb;

	$wpdb->update(
		$wpdb->posts,
		array(
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
		),
		array(
			'post_status'    => 'publish',
			'comment_status' => 'open',
		)
	);

	update_option( '_frl_disable_comments_completed', '1', false );
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

/**
	* Registers the heartbeat_settings filter when heartbeat control is enabled.
	*
	* @return void
	*/
function frl_heartbeat_init(): void {
	add_filter( 'heartbeat_settings', 'frl_heartbeat_settings', 10, 1 );
}

/**
	* Throttles or disables the WordPress Heartbeat API per context.
	*
	* @param array $settings Heartbeat settings from WordPress core.
	* @return array Modified heartbeat settings.
	*/
function frl_heartbeat_settings( array $settings ): array {
	// Frontend: throttle to configured interval.
	if ( ! is_admin() ) {
		$interval = (int) frl_get_option( 'heartbeat_frontend_interval' );

		if ( $interval <= 0 ) {
			// Disable: deregister the script on the next wp_enqueue_scripts.
			add_action( 'wp_enqueue_scripts', 'frl_deregister_heartbeat', 999, 0 );
			return $settings;
		}

		$settings['interval'] = max( 15, $interval );
		return $settings;
	}

	// Post edit screens: throttle moderately (autosave/locking still works).
	if ( frl_is_post_edit_screen() ) {
		$settings['interval'] = max( 15, (int) frl_get_option( 'heartbeat_editor_interval' ) );
		return $settings;
	}

	// Dashboard / other admin: throttle to configured interval.
	$settings['interval'] = max( 15, (int) frl_get_option( 'heartbeat_dashboard_interval' ) );
	return $settings;
}

/**
	* Deregisters the heartbeat script on the frontend.
	*
	* Hooked at priority 999 on wp_enqueue_scripts when heartbeat_frontend_interval <= 0.
	*
	* @return void
	*/
function frl_deregister_heartbeat(): void {
	wp_deregister_script( 'heartbeat' );
}
