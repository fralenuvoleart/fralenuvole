<?php
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cache cleanup hooks for posts, terms, users, options, and translations.
 */

// On init, register term-change hooks that trigger rewrite flush
add_action( 'init', 'frl_register_hooks_rewrite_flush', 10, 0 );
add_action( 'update_option', 'frl_clear_option_transient', 10, 1 );
add_action( 'pll_save_strings_translations', 'frl_clear_translation_cache', 10, 0 );
add_action( 'edited_term', 'frl_clear_term_permalink_cache', 10, 1 );
add_action( 'save_post', 'frl_clear_post_cache', 10, 1 );
add_action( 'save_post_wp_navigation', 'frl_clear_navigation_cache', 10, 1 );
add_action( 'wp_update_nav_menu', 'frl_clear_menu_cache', 10, 1 );
add_action( 'profile_update', 'frl_clear_user_cache', 10, 1 );
add_action( 'updated_option', 'frl_clear_option_cache', 10, 1 );

/**
 * Register term-change hooks that require a rewrite flush.
 *
 * @return void
 */
function frl_register_hooks_rewrite_flush(): void {
	foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
		add_action( "created_{$taxonomy}", 'frl_schedule_rewrite_flush', 10, 0 );
		add_action( "edited_{$taxonomy}", 'frl_schedule_rewrite_flush', 10, 0 );
		add_action( "deleted_{$taxonomy}", 'frl_schedule_rewrite_flush', 10, 0 );
	}
}

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

	// Bump the post cache version on every real save (each Polylang sibling
	// needs its own bump) BEFORE the dedup guard below.
	update_post_meta( $post_id, '_frl_post_version', time() );

	// Polylang meta sync fires save_post once per translation sibling in the
	// same request. Purge once per translation group; skipping repeats is safe
	// because cache clears are idempotent (keys are already absent).
	static $purged_groups = array();
	$group_id             = 'solo_' . $post_id;
	if ( function_exists( 'pll_get_post_translations' ) ) {
		$translations = pll_get_post_translations( $post_id );
		if ( is_array( $translations ) && count( $translations ) > 1 ) {
			$group_id = 'pll_' . md5( (string) wp_json_encode( $translations ) );
		}
	}
	if ( isset( $purged_groups[ $group_id ] ) ) {
		return;
	}
	$purged_groups[ $group_id ] = true;

	// Clear all tracked translated meta fields for this post.
	// The translations/schema/langswitcher/featured_img key families are intentionally
	// NOT purged here: they carry the '_v{version}' suffix and the bump above already invalidated
	// them across all languages. Old entries expire via group TTL.
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

	foreach ( $active_languages as $language ) {
		$cache_key = "translation_option_{$option_name}_{$language}_{$version}";

		// Clear only this option-specific key (dependency cascades are skipped by the cache manager for key-level clears)
		frl_cache_clear( 'options', $cache_key );
	}
}

/**
 * Delete plugin transient matching an updated option name.
 *
 * @param string $option Name of the updated option.
 * @return void
 */
function frl_clear_option_transient( $option ) {
	// Extract the unprefixed option name
	$prefix = frl_prefix( '' );
	if ( str_starts_with( $option, $prefix ) ) {
		$unprefixed = substr( $option, strlen( $prefix ) );

		// Skip the delete round-trip when the transient was never set this request
		$cache = &frl_transients_static_cache();
		if ( ! isset( $cache[ $unprefixed ] ) ) {
			return;
		}

		// Delete any transient with the same name as the option
		frl_delete_transient( $unprefixed );
	}
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

	frl_cache_clear( 'permalinks', $cache_key );
}

/**
 * Clear navigation cache when a classic menu (nav_menu term) is updated.
 *
 * Uses a separate cache key prefix (wp_menu_) to avoid ID namespace
 * collisions with wp_navigation post IDs.
 *
 * @param int $menu_id Menu term ID.
 * @return void
 */
function frl_clear_menu_cache( $menu_id ) {
	$cache_key = "wp_menu_{$menu_id}";

	frl_cache_clear( 'permalinks', $cache_key );
}

/**
 * Clear permalink and tracked meta caches when a term is saved.
 *
 * @param int $term_id Term ID.
 * @return void
 */
function frl_clear_term_permalink_cache( $term_id ) {
	// Get term data
	$term = get_term( $term_id );
	if ( ! $term || is_wp_error( $term ) ) {
		return;
	}

	frl_cache_clear( 'permalinks' );

	// Also clear any tracked meta fields for this term.
	frl_clear_tracked_meta_cache( 'term', $term->term_id );
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
	$version = frl_get_option( 'translation_version' ) ?: 1;

	// Construct the tracking key and retrieve the list of cached meta keys.
	$tracking_key = "translation_{$type}meta_keys_{$id}";
	$tracked_keys = frl_cache_get( 'metafields', $tracking_key, null );

	if ( frl_is_array_not_empty( $tracked_keys ) ) {
		foreach ( $tracked_keys as $meta_key ) {
			// Construct the data key for each meta field, including the version, and clear it.
			$cache_key = "translation_{$type}meta_{$id}_{$meta_key}_{$version}";
			frl_cache_clear( 'metafields', $cache_key );
		}
		// After clearing all individual entries, remove the tracking key itself.
		frl_cache_clear( 'metafields', $tracking_key );
	}
}

/**
 * Invalidate MU plugin exclusion caches when plugins are activated or deactivated.
 *
 * The MU plugin caches the active_plugins list (both site and network) in the
 * 'options' cache group. When plugins are activated/deactivated, these caches
 * must be purged so the exclusion filters use the new plugin list.
 *
 * @param string $plugin              Plugin basename (unused, kept for hook signature).
 * @param bool   $network_wide        Whether the plugin is activated network-wide (unused).
 * @return void
 */
function frl_purge_mu_plugin_exclusion_cache( $plugin = '', $network_wide = false ): void {
	frl_cache_clear( 'options', 'mu_plugin_active_plugins' );
	frl_cache_clear( 'options', 'mu_plugin_network_active_plugins' );
	frl_cache_clear( 'options', 'thirdparty_active_plugins' );
}
add_action( 'activated_plugin', 'frl_purge_mu_plugin_exclusion_cache', 10, 2 );
add_action( 'deactivated_plugin', 'frl_purge_mu_plugin_exclusion_cache', 10, 2 );
