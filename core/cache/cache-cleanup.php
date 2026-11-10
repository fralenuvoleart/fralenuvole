<?php
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cache cleanup hooks for posts, terms, users, options, and translations.
 *
 * NOTE: category/post_tag rewrite-related invalidation (exclusion-pattern
 * transient on term rename, create/delete via generic created_term/deleted_term)
 * lives in Frl_Rewriter::register_cache_invalidation_hooks() (core/rewriter/class-rewriter.php),
 * not here — that class already owns every other rewriter-cache-invalidation
 * hook, so keeping this one alongside its siblings avoids a split source of
 * truth and reuses the class's existing wp_loaded-deferred registration and
 * frl_rewriter_is_loaded() gating instead of a duplicate, ad-hoc guard here.
 */

add_action( 'pll_save_strings_translations', 'frl_clear_translation_cache', 10, 0 );
add_action( 'edit_terms', 'frl_capture_term_route', 10, 2 );
add_action( 'edited_term', 'frl_clear_term_permalink_cache', 10, 3 );
add_action( 'created_term', 'frl_clear_permalinks_on_term_event', 10, 3 );
add_action( 'delete_term', 'frl_clear_permalinks_on_term_event', 10, 3 );
add_action( 'delete_term', 'frl_clear_metafields_on_language_delete', 10, 3 );
add_action( 'post_updated', 'frl_clear_permalinks_on_post_route_change', 10, 3 );
add_action( 'transition_post_status', 'frl_clear_permalinks_on_publish_change', 10, 3 );
add_action( 'transition_post_status', 'frl_clear_shortcodes_on_page_publish', 10, 3 );
add_action( 'deleted_post', 'frl_clear_permalinks_on_post_delete', 10, 2 );
add_action( 'set_object_terms', 'frl_clear_permalinks_on_terms_change', 10, 6 );
add_action( 'save_post', 'frl_clear_post_cache', 10, 1 );
add_action( 'save_post_wp_navigation', 'frl_clear_navigation_cache', 10, 1 );
add_action( 'wp_update_nav_menu', 'frl_clear_menu_cache', 10, 0 );
add_action( 'wp_delete_nav_menu', 'frl_clear_menu_cache', 10, 0 );
add_action( 'profile_update', 'frl_clear_user_cache', 10, 1 );
add_action( 'updated_option', 'frl_clear_option_cache', 10, 1 );
// Invalidation for unversioned keys (TTL was their only safety net).
add_action( 'acf/save_post', 'frl_clear_acf_option_shortcode_cache', 1000, 1 );
add_action( 'customize_save_after', 'frl_clear_login_inline_style_cache', 10, 0 );
add_action( 'wp_update_attachment_metadata', 'frl_clear_svg_dims_cache', 10, 2 );

/**
 * Bump the post cache version and clear tracked translated meta caches.
 *
 * Post-data key families (translations, schema, langswitcher, featured image) are
 * versioned via `_frl_post_version` — the bump below invalidates them in every
 * language, replacing the old key-level purge that only reached the admin's
 * default-language copy (Frl_Cache_Manager::generate_key() language prefix).
 *
 * @param int $post_id Post ID.
 * @return void
 */
function frl_clear_post_cache( $post_id ) {
	// Validate post ID - empty() catches falsy values and 0; (int) cast sanitizes
	if ( empty( $post_id ) ) {
		return;
	}
	$post_id = (int) $post_id;

	// Skip cache clearing for autosaves, revisions, auto-drafts, and trash.
	// Gutenberg triggers save_post every ~60 seconds during editing for autosaves,
	// so this guard prevents unnecessary cache clearing on every keystroke.
	if ( ! frl_is_post_save_action( $post_id ) ) {
		return;
	}

	// Skip types whose data this plugin never caches under post keys
	// (media uploads and menu edits would otherwise trigger a full purge).
	$post_type = get_post_type( $post_id );
	if ( in_array( $post_type, array( 'attachment', 'nav_menu_item', 'customize_changeset' ), true ) ) {
		return;
	}

	// Bump the post cache version on every real save (each Polylang sibling needs its own bump).
	// Monotonic: two saves in the same second must not share a version.
	$previous = (int) get_post_meta( $post_id, '_frl_post_version', true );
	update_post_meta( $post_id, '_frl_post_version', max( time(), $previous + 1 ) );
	frl_get_post_cache_version( $post_id, true );

	// Every sibling has its own meta keys, so each one is cleared (no per-group dedupe).
	// The translations/schema/langswitcher/featured_img key families carry '_v{version}'
	// and the bump above already invalidated them; old entries expire via group TTL.
	frl_clear_tracked_meta_cache( 'post', $post_id );
}

/**
 * Clear translated option cache for all active languages.
 *
 * @param string $option_name The updated option name.
 * @return void
 */
function frl_clear_option_cache( $option_name ) {
	// Narrow to plugin-owned options only
	$prefix = frl_prefix( '' );
	if ( ! str_starts_with( $option_name, $prefix ) ) {
		return; // Not our option – leave caches intact
	}

	// No translated option caches exist when the translator is disabled —
	// return early to avoid per-language cache clears (one per active language).
	if ( ! frl_translator_is_enabled() ) {
		return;
	}

	// This function must clear the cache for all possible languages.
	/** @disregard P1010 Undefined type */
	$active_languages = frl_get_active_languages();

	if ( empty( $active_languages ) ) {
		// Fallback to default if no languages are returned.
		$default_language = function_exists( 'frl_get_default_language' ) ? frl_get_default_language() : null;
		if ( $default_language ) {
			$active_languages = array( $default_language );
		} else {
			return; // Cannot proceed without language context.
		}
	}

	$version = frl_get_option( 'translation_version' ) ?: 1;

	// Collect all per-language keys and clear them in one batch (one round-trip
	// instead of one per language). Dependency cascades are skipped for key-level clears.
	$cache_keys = array();
	foreach ( $active_languages as $language ) {
		$cache_keys[] = "translation_option_{$option_name}_{$language}_{$version}";
	}
	frl_cache_clear_keys( 'options', $cache_keys );
}

/**
 * Clear tracked meta cache for a user.
 *
 * @param int $user_id User ID.
 * @return void
 */
function frl_clear_user_cache( $user_id ) {
	frl_clear_tracked_meta_cache( 'user', $user_id );
}

/**
 * Invalidate translation caches when Polylang translations are saved.
 *
 * @return void
 */
function frl_clear_translation_cache() {
	// Use the plugin's option setter for consistency & automatic cache handling
	frl_update_option( 'translation_version', time() );

	// Clear translations cache group
	// Dependencies will automatically clear metafields group
	frl_cache_clear( 'translations' );
}

/**
 * Clear navigation cache when a navigation post (wp_navigation) is saved.
 *
 * @param int $post_id Post ID of the wp_navigation post.
 * @return void
 */
function frl_clear_navigation_cache( $post_id ) {
	// Clear the wp_navigation key within the permalinks group
	$cache_key = "wp_navigation_{$post_id}";

	// 'permalinks' is language-prefixed: clear the key in every language.
	frl_cache_clear_keys( 'permalinks', array( $cache_key ), true );

	// Menu-sitemap shortcode keys embed hashed args and are not enumerable —
	// purge the whole shortcodes group (menu edits are rare admin events).
	frl_cache_clear( 'shortcodes' );
}

/**
 * Purge menu-sitemap shortcode output when a classic menu (nav_menu term) is updated or deleted.
 *
 * Shortcode keys embed hashed args and are not enumerable, so the group is purged.
 *
 * @return void
 */
function frl_clear_menu_cache() {
	frl_cache_clear( 'shortcodes' );
}

/**
 * Term signatures (fields cached in the permalinks group) captured before an update, keyed by term ID.
 *
 * @return array<int, string>
 */
function &frl_term_routes(): array {
	static $routes = array();
	return $routes;
}

/**
 * Signature of the term fields cached in the permalinks group (links and frl_get_post_terms()).
 *
 * @param WP_Term $term Term.
 * @return string
 */
function frl_term_signature( WP_Term $term ): string {
	// "\0" separates name from description so the link fields can be compared alone.
	return $term->slug . '|' . $term->parent . '|' . $term->name . "\0" . $term->description;
}

/**
 * Capture a term's signature before wp_update_term() writes it.
 *
 * @param int    $term_id  Term ID.
 * @param string $taxonomy Taxonomy slug.
 * @return void
 */
function frl_capture_term_route( $term_id, $taxonomy ) {
	if ( ! is_taxonomy_viewable( $taxonomy ) ) {
		return;
	}
	$term = get_term( $term_id, $taxonomy );
	if ( $term instanceof WP_Term ) {
		$routes             = &frl_term_routes();
		$routes[ $term_id ] = frl_term_signature( $term );
	}
}

/**
 * Clear permalink and tracked meta caches when a term is saved.
 *
 * Skips taxonomies without public URLs (e.g. Polylang's post_translations,
 * rewritten on every post save) and clears permalinks only when a cached
 * term field (slug, parent, name, description) actually changed.
 *
 * @param int    $term_id  Term ID.
 * @param int    $tt_id    Term taxonomy ID.
 * @param string $taxonomy Taxonomy slug.
 * @return void
 */
function frl_clear_term_permalink_cache( $term_id, $tt_id = 0, $taxonomy = '' ) {
	if ( ! is_taxonomy_viewable( $taxonomy ) ) {
		return;
	}
	$term = get_term( $term_id, $taxonomy );
	if ( ! $term instanceof WP_Term ) {
		return;
	}

	$routes = &frl_term_routes();
	$before = $routes[ $term_id ] ?? null;
	unset( $routes[ $term_id ] );

	$after = frl_term_signature( $term );

	// Unknown previous state: clear to be safe.
	if ( null === $before || $before !== $after ) {
		frl_cache_clear( 'permalinks' );

		// Shortcode output (e.g. category links) embeds name/slug/parent only.
		$link_fields = substr( $after, 0, (int) strpos( $after, "\0" ) + 1 );
		if ( null === $before || ! str_starts_with( $before, $link_fields ) ) {
			frl_cache_clear( 'shortcodes' );
		}
	}

	// Also clear any tracked meta fields for this term.
	frl_clear_tracked_meta_cache( 'term', $term->term_id );
}

/**
 * Clear permalinks when a term with public URLs is created or deleted.
 *
 * @param int    $term_id  Term ID.
 * @param int    $tt_id    Term taxonomy ID.
 * @param string $taxonomy Taxonomy slug.
 * @return void
 */
function frl_clear_permalinks_on_term_event( $term_id, $tt_id = 0, $taxonomy = '' ) {
	if ( is_taxonomy_viewable( $taxonomy ) ) {
		frl_cache_clear( 'permalinks' );
		// Shortcodes cache term lookups, including misses.
		frl_cache_clear( 'shortcodes' );
	}
}

/**
 * Purge translated-meta caches when a language is deleted.
 *
 * A deleted language's tracking list and cached values are stored under that
 * language's key, so frl_clear_tracked_meta_cache() can no longer reach them once
 * the language is inactive. Purging the whole group (a rare admin action) clears
 * every language's stale entries and costs nothing per request.
 *
 * @param int    $term_id  Term ID.
 * @param int    $tt_id    Term taxonomy ID.
 * @param string $taxonomy Taxonomy slug.
 * @return void
 */
function frl_clear_metafields_on_language_delete( $term_id, $tt_id = 0, $taxonomy = '' ) {
	if ( 'language' === $taxonomy ) {
		frl_cache_clear( 'metafields' );
	}
}

/**
 * Clear permalinks when a post enters or leaves 'publish' (incl. scheduled publishing and trash).
 *
 * @param string  $new_status New status.
 * @param string  $old_status Old status.
 * @param WP_Post $post       Post.
 * @return void
 */
function frl_clear_permalinks_on_publish_change( $new_status, $old_status, $post ) {
	if ( ( 'publish' === $new_status ) !== ( 'publish' === $old_status )
		&& $post instanceof WP_Post && is_post_type_viewable( $post->post_type ) ) {
		frl_cache_clear( 'permalinks' );
	}
}

/**
 * Clear shortcode output when a page is published (core/page-list enumerates pages).
 *
 * @param string  $new_status New status.
 * @param string  $old_status Old status.
 * @param WP_Post $post       Post.
 * @return void
 */
function frl_clear_shortcodes_on_page_publish( $new_status, $old_status, $post ) {
	if ( 'publish' === $new_status && 'publish' !== $old_status && $post instanceof WP_Post && 'page' === $post->post_type ) {
		frl_cache_clear( 'shortcodes' );
	}
}

/**
 * Clear permalinks when a published post is deleted without going through the trash.
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Deleted post.
 * @return void
 */
function frl_clear_permalinks_on_post_delete( $post_id, $post = null ) {
	if ( $post instanceof WP_Post && 'publish' === $post->post_status && is_post_type_viewable( $post->post_type ) ) {
		frl_cache_clear( 'permalinks' );
	}
}

/**
 * Clear permalinks when a published post's route changes (slug or parent).
 *
 * @param int     $post_id     Post ID.
 * @param WP_Post $post_after  Post after the update.
 * @param WP_Post $post_before Post before the update.
 * @return void
 */
function frl_clear_permalinks_on_post_route_change( $post_id, $post_after, $post_before ) {
	if ( ! $post_after instanceof WP_Post || ! $post_before instanceof WP_Post || ! is_post_type_viewable( $post_after->post_type ) ) {
		return;
	}

	// Status changes are handled by frl_clear_permalinks_on_publish_change().
	if ( 'publish' !== $post_before->post_status && 'publish' !== $post_after->post_status ) {
		return;
	}

	if ( $post_before->post_name !== $post_after->post_name
		|| (int) $post_before->post_parent !== (int) $post_after->post_parent ) {
		frl_cache_clear( 'permalinks' );
	}

	// Hierarchical titles/slugs/parents appear in descendants' breadcrumbs.
	if ( is_post_type_hierarchical( $post_after->post_type )
		&& ( $post_before->post_title !== $post_after->post_title
			|| $post_before->post_name !== $post_after->post_name
			|| (int) $post_before->post_parent !== (int) $post_after->post_parent ) ) {
		frl_cache_clear( 'shortcodes' );
	}
}

/**
 * Clear permalinks when an object's terms actually change (frl_get_post_terms() caches them).
 *
 * Skips taxonomies without public URLs (Polylang's translation groups), except
 * 'language', whose assignment sets the post's URL prefix.
 *
 * @param int    $object_id  Object ID.
 * @param array  $terms      Terms passed in.
 * @param array  $tt_ids     Term taxonomy IDs set.
 * @param string $taxonomy   Taxonomy slug.
 * @param bool   $append     Whether terms were appended.
 * @param array  $old_tt_ids Previous term taxonomy IDs.
 * @return void
 */
function frl_clear_permalinks_on_terms_change( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ) {
	if ( 'language' !== $taxonomy && ! is_taxonomy_viewable( $taxonomy ) ) {
		return;
	}

	// Unpublished posts are not on the frontend; publishing clears permalinks anyway.
	$status = get_post_status( $object_id );
	if ( false !== $status && 'publish' !== $status ) {
		return;
	}

	$new = array_map( 'intval', (array) $tt_ids );
	$old = array_map( 'intval', (array) $old_tt_ids );
	sort( $new );
	sort( $old );

	$changed = $append ? (bool) array_diff( $new, $old ) : $new !== $old;
	if ( $changed ) {
		frl_cache_clear( 'permalinks' );
	}
}

/**
 * Clear all tracked translated meta fields for an object.
 *
 * @param string $type Object type ('post', 'term', 'user').
 * @param int    $id   Object ID.
 * @return void
 */
function frl_clear_tracked_meta_cache( string $type, int $id ) {
	// Skip on zero ID; $id is already typed int
	if ( empty( $id ) ) {
		return;
	}

	// Get the current translation version to build the correct cache key.
	$version      = frl_get_option( 'translation_version' ) ?: 1;
	$tracking_key = "translation_{$type}meta_keys_{$id}";

	$languages = frl_get_active_languages();
	$current   = frl_get_language();
	if ( ! in_array( $current, $languages, true ) ) {
		$languages[] = $current;
	}

	// Union of every language's list: a value cached under another language must still be cleared.
	$tracked_keys = array();
	foreach ( $languages as $language ) {
		$language_keys = frl_cache_get_in_language( 'metafields', $tracking_key, $language );
		if ( frl_is_array_not_empty( $language_keys ) ) {
			foreach ( $language_keys as $meta_key ) {
				$tracked_keys[ $meta_key ] = true;
			}
		}
	}

	// No list in any language: nothing tracked, so issue no deletes at all.
	if ( empty( $tracked_keys ) ) {
		return;
	}

	$cache_keys = array( $tracking_key );
	foreach ( array_keys( $tracked_keys ) as $meta_key ) {
		$cache_keys[] = "translation_{$type}meta_{$id}_{$meta_key}_{$version}";
	}
	frl_cache_clear_keys( 'metafields', $cache_keys, $languages );
}

/**
 * Purge shortcode caches when an ACF options page is saved.
 *
 * ACF options saves use post_id 'option'/'options' — they never fire save_post,
 * so acf_calc_*_option shortcode keys (unversioned) would otherwise go stale
 * until the group TTL. Key targets are unknown at this point, so purge the
 * whole shortcodes group (options-page saves are rare admin events).
 *
 * @param string|int $post_id ACF post ID ('option'/'options' for options pages).
 * @return void
 */
function frl_clear_acf_option_shortcode_cache( $post_id ) {
	if ( $post_id !== 'option' && $post_id !== 'options' ) {
		return;
	}
	frl_cache_clear( 'shortcodes' );
}

/**
 * Clear the cached login-page inline CSS after a Customizer save.
 *
 * login_inline_style (html group) embeds the custom_logo theme mod; Customizer
 * saves touch no other frl hook, so the key would go stale until TTL.
 *
 * @return void
 */
function frl_clear_login_inline_style_cache() {
	frl_cache_clear( 'html', 'login_inline_style' );
}

/**
 * Clear cached SVG dimensions when attachment metadata is regenerated.
 *
 * svg_dims_{id} keys (html group) are skipped by frl_clear_post_cache()
 * (attachments are excluded there), so replacing an image on the same
 * attachment ID would leave stale dimensions until TTL.
 *
 * @param array $data    Attachment metadata.
 * @param int   $post_id Attachment ID.
 * @return array Unmodified metadata (this is a filter).
 */
function frl_clear_svg_dims_cache( $data, $post_id ) {
	frl_cache_clear( 'html', 'svg_dims_' . (int) $post_id );
	return $data;
}

/**
 * Purge the cached active-plugin lists on (de)activation.
 *
 * frl_is_thirdparty_plugin_active() caches active_plugins + active_sitewide_plugins
 * for a week; without this they'd go stale after any activation change.
 */
function frl_purge_active_plugins_cache(): void {
	frl_cache_clear( 'options', 'thirdparty_active_plugins' );
}
add_action( 'activated_plugin', 'frl_purge_active_plugins_cache' );
add_action( 'deactivated_plugin', 'frl_purge_active_plugins_cache' );
