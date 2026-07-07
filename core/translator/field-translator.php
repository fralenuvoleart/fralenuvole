<?php

/**
 * Module Name: Custom Fields
 * Description: Automatic translation for ACF and other custom fields.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers hooks for automatic field translation.
 *
 * @return void
 */
function frl_translator_init(): void {
	// Abort if the master switch is disabled or not multilingual website.
	if ( ! frl_is_multilingual() ) {
		return;
	}

	// Only register hooks for frontend page requests.
	// REST API, admin, CLI, and cron requests do not need field or
	// block translation — skip all hook registration entirely.
	if ( ! frl_is_valid_frontend_page_request() ) {
		return;
	}

	// Register block translation on render_block at priority 10.
	// This resolves {{text}} and [[permalink]] delimiters before
	// apply_shortcodes runs at priority 20. Guarded by
	// frl_is_valid_frontend_page_request() inside the callback to
	// skip REST, admin, CLI, and cron requests.
	add_filter( 'render_block', 'frl_translate_block_content', 10, 2 );

	$is_posts_enabled   = frl_get_option( 'translator_posts' ) === '1';
	$is_terms_enabled   = frl_get_option( 'translator_terms' ) === '1';
	$is_users_enabled   = frl_get_option( 'translator_users' ) === '1';
	$is_options_enabled = frl_get_option( 'translator_options' ) === '1';

	// Add hooks for enabled sub-features.
	if ( $is_posts_enabled ) {
		add_filter( 'get_post_metadata', 'frl_translator_post_meta', 20, 4 );
	}
	if ( $is_terms_enabled ) {
		add_filter( 'get_term_metadata', 'frl_translator_term_meta', 20, 4 );
	}
	if ( $is_users_enabled ) {
		add_filter( 'get_user_metadata', 'frl_translator_user_meta', 20, 4 );
	}
	if ( $is_options_enabled ) {
		foreach ( FRL_TRANSLATOR_OPTIONS as $option_name ) { // @phpstan-ignore-line
			add_filter( "pre_option_{$option_name}", 'frl_translator_pre_option', 10, 2 );
		}
	}

	// Register hooks for complex ACF field types if any metadata type is enabled.
	if ( $is_posts_enabled || $is_terms_enabled || $is_users_enabled || $is_options_enabled ) {
		foreach ( FRL_TRANSLATOR_FIELDS_ACF as $type => $handler ) {
			add_filter( "acf/format_value/type={$type}", $handler, 20, 3 );
		}
	}

	// Auto-translate taxonomy term name/description when enabled
	if ( frl_get_option( 'translator_taxonomies' ) === '1' ) {
		// Lists of terms
		add_filter( 'get_terms', 'frl_translator_filter_get_terms', 20, 3 );
		// Single term fetch
		add_filter( 'get_term', 'frl_translator_filter_get_term', 20, 2 );
	}
}

/**
 * Translates {{text}} and [[permalink]] delimiters in block content.
 *
 * Registered on 'render_block' at priority 10 — runs before
 * 'apply_shortcodes' at priority 20, ensuring shortcode output is not
 * re-processed for delimiters. Guarded by frl_is_valid_frontend_page_request()
 * to skip REST, admin, CLI, and cron requests where block translation is
 * unnecessary and expensive.
 *
 * @param string $block_content The rendered block HTML.
 * @param array  $block         The block object.
 * @return string Block content with delimiters translated, or unchanged.
 */
function frl_translate_block_content( string $block_content, array $block ): string {
	if ( ! frl_is_valid_frontend_page_request() ) {
		return $block_content;
	}

	return frl_get_translation_block( $block_content, $block );
}

/**
 * Core translation dispatcher.
 * Central entry point for all custom field string translations.
 *
 * @param mixed $value Value to translate.
 * @return mixed Original or translated value.
 */
function frl_translator_apply( mixed $value ): mixed {
	// Only process non-empty strings.
	if ( ! is_string( $value ) || empty( $value ) ) {
		return $value;
	}

	// Process permalink patterns (##...##) first, then translate the result.
	return frl_get_translation( frl_process_permalink_patterns( $value ) );
}

/**
 * Intercepts calls to get_post_meta to translate fields that bypass ACF hooks.
 *
 * @param mixed $value The meta value.
 * @param int $post_id The post ID.
 * @param string $meta_key The meta key.
 * @param bool $single Whether a single value is being requested.
 * @return mixed The original or translated value.
 */
function frl_translator_post_meta( mixed $value, int $post_id, ?string $meta_key, ?bool $single ): mixed {
	if ( $meta_key === null ) {
		return $value;
	}
	return frl_translator_get_cached_meta( 'post', $value, $post_id, $meta_key, $single );
}

/**
 * Intercepts calls to get_term_meta to translate fields.
 *
 * @param mixed $value The meta value.
 * @param int $term_id The term ID.
 * @param string $meta_key The meta key.
 * @param bool $single Whether a single value is being requested.
 * @return mixed The original or translated value.
 */
function frl_translator_term_meta( mixed $value, int $term_id, ?string $meta_key, ?bool $single ): mixed {
	if ( $meta_key === null ) {
		return $value;
	}
	return frl_translator_get_cached_meta( 'term', $value, $term_id, $meta_key, $single );
}

/**
 * Intercepts calls to get_user_meta to translate fields.
 *
 * @param mixed $value The meta value.
 * @param int $user_id The user ID.
 * @param string $meta_key The meta key.
 * @param bool $single Whether a single value is being requested.
 * @return mixed The original or translated value.
 */
function frl_translator_user_meta( mixed $value, int $user_id, ?string $meta_key, ?bool $single ): mixed {
	if ( $meta_key === null ) {
		return $value;
	}
	return frl_translator_get_cached_meta( 'user', $value, $user_id, $meta_key, $single );
}

/**
 * Intercepts calls to get_option for specific options.
 *
 * @param mixed $pre_value The short-circuit value. Default false.
 * @param string $option_name The name of the option.
 * @return mixed The original or translated value.
 */
function frl_translator_pre_option( mixed $pre_value, string $option_name ): mixed {
	// Don't run if another filter has already returned a value or if not a valid request.
	if ( false !== $pre_value || ! frl_is_valid_frontend_page_request() ) {
		return $pre_value;
	}

	// Options are language-dependent, so the language and version must be part of the cache key.
	$language  = frl_get_language();
	$version   = frl_get_option( 'translation_version' ) ?: 1; // Use helper to get version
	$cache_key = "translation_option_{$option_name}_{$language}_{$version}";

	// Use a dedicated cache group for options.
	return frl_cache_remember(
		'options',
		$cache_key,
		frl_translator_get_cache_callback( 'option', array( 'name' => $option_name ) )
	);
}

/**
 * Dispatcher for ACF Link fields.
 *
 * @param mixed $value Link field value.
 * @param int $post_id Post ID.
 * @param array $field Field configuration.
 * @return mixed Translated link value.
 */
function frl_translator_acf_link( mixed $value, int $post_id, array $field ): mixed {
	if ( ! frl_is_valid_frontend_page_request() ) {
		return $value;
	}

	// Determine the correct list of fields to check against based on context.
	$translatable_fields = frl_translator_get_contextual_fields( $post_id );

	// Check if the field itself is marked for translation.
	if ( ! frl_string_matches_pattern( $field['name'], $translatable_fields ) ) {
		return $value;
	}

	if ( empty( $value ) || ! is_array( $value ) ) {
		frl_log(
			'Translator: ACF link field \'{field_name}\' has a malformed value (not an array or empty) and could not be translated.',
			array( 'field_name' => $field['name'] )
		);
		return $value;
	}
	// Translate the link's title text.
	if ( ! empty( $value['title'] ) ) {
		$value['title'] = frl_get_translation( $value['title'] );
	}
	// Process the URL for permalink patterns.
	if ( ! empty( $value['url'] ) && is_string( $value['url'] ) ) {
		$value['url'] = frl_process_permalink_patterns( $value['url'] );
	}

	return $value;
}

/**
 * Dispatcher for ACF Taxonomy fields.
 * Translates term name/description for enabled fields; preserves return format.
 *
 * @param mixed $value Taxonomy field value.
 * @param int $post_id Post ID.
 * @param array $field Field configuration.
 * @return mixed Translated taxonomy value.
 */
function frl_translator_acf_taxonomy( mixed $value, int $post_id, array $field ): mixed {
	if ( ! frl_is_valid_frontend_page_request() ) {
		return $value;
	}

	if ( frl_get_option( 'translator_taxonomies' ) !== '1' || empty( FRL_TRANSLATOR_TAXONOMIES ) ) { // @phpstan-ignore-line
		return $value;
	}
	// Determine the correct list of fields to check against based on context.
	$translatable_fields = frl_translator_get_contextual_fields( $post_id );

	// Check if the field itself is marked for translation.
	if ( ! frl_string_matches_pattern( $field['name'] ?? '', $translatable_fields ) ) {
		return $value;
	}

	if ( empty( $value ) ) {
		return $value;
	}

	$translate_term = function ( $term ) {
		if ( is_object( $term ) && isset( $term->name ) ) {
			if ( ! empty( FRL_TRANSLATOR_TAXONOMIES ) && ! frl_string_matches_pattern( $term->taxonomy ?? '', FRL_TRANSLATOR_TAXONOMIES ) ) { // @phpstan-ignore-line
				return $term;
			}
			$term->name = frl_get_translation( $term->name );
			if ( isset( $term->description ) && is_string( $term->description ) ) {
				$term->description = frl_get_translation( $term->description );
			}
			return $term;
		}
		if ( is_array( $term ) ) {
			if ( isset( $term['name'] ) && is_string( $term['name'] ) ) {
				$term['name'] = frl_get_translation( $term['name'] );
			}
			if ( isset( $term['description'] ) && is_string( $term['description'] ) ) {
				$term['description'] = frl_get_translation( $term['description'] );
			}
			return $term;
		}
		// IDs or other formats: leave unchanged.
		return $term;
	};

	if ( is_array( $value ) ) {
		foreach ( $value as $k => $v ) {
			$value[ $k ] = $translate_term( $v );
		}
		return $value;
	}

	return $translate_term( $value );
}

/**
 * Filter: translate term objects from get_terms when enabled.
 *
 * @param array $terms List of terms.
 * @param array $taxonomies Taxonomies to fetch.
 * @param array $args Query arguments.
 * @return array Translated terms.
 */
function frl_translator_filter_get_terms( array $terms, array $taxonomies, array $args ): array {
	if ( ! frl_is_valid_frontend_page_request() ) {
		return $terms;
	}

	if ( frl_get_option( 'translator_taxonomies' ) !== '1' || empty( FRL_TRANSLATOR_TAXONOMIES ) || empty( $terms ) ) { // @phpstan-ignore-line
		return $terms;
	}
	foreach ( $terms as $i => $term ) {
		if ( is_object( $term ) && isset( $term->name ) ) {
			if ( ! empty( FRL_TRANSLATOR_TAXONOMIES ) && ! frl_string_matches_pattern( $term->taxonomy ?? '', FRL_TRANSLATOR_TAXONOMIES ) ) { // @phpstan-ignore-line
				continue;
			}
			$term->name = frl_get_translation( $term->name );
			if ( isset( $term->description ) && is_string( $term->description ) ) {
				$term->description = frl_get_translation( $term->description );
			}
			$terms[ $i ] = $term;
		}
	}
	return $terms;
}

/**
 * Filter: translate single term from get_term when enabled.
 *
 * @param mixed $term Term object or null.
 * @param string $taxonomy Taxonomy name.
 * @return mixed Translated term.
 */
function frl_translator_filter_get_term( mixed $term, string $taxonomy ): mixed {
	if ( ! frl_is_valid_frontend_page_request() ) {
		return $term;
	}

	if ( frl_get_option( 'translator_taxonomies' ) !== '1' || empty( FRL_TRANSLATOR_TAXONOMIES ) || ! is_object( $term ) ) { // @phpstan-ignore-line
		return $term;
	}
	if ( ! empty( FRL_TRANSLATOR_TAXONOMIES ) && ! frl_string_matches_pattern( $term->taxonomy ?? '', FRL_TRANSLATOR_TAXONOMIES ) ) { // @phpstan-ignore-line
		return $term;
	}
	if ( isset( $term->name ) && is_string( $term->name ) ) {
		$term->name = frl_get_translation( $term->name );
	}
	if ( isset( $term->description ) && is_string( $term->description ) ) {
		$term->description = frl_get_translation( $term->description );
	}
	return $term;
}

/**
 * Dispatcher for ACF Repeater fields.
 *
 * @param mixed $value Repeater field value.
 * @param int $post_id Post ID.
 * @param array $field Field configuration.
 * @return mixed Translated repeater value.
 */
function frl_translator_acf_repeater( mixed $value, int $post_id, array $field ): mixed {
	if ( ! frl_is_valid_frontend_page_request() ) {
		return $value;
	}

	if ( ! frl_is_array_not_empty( $value ) ) {
		return $value;
	}

	// Determine the correct list of fields to check against based on context.
	$translatable_fields = frl_translator_get_contextual_fields( $post_id );
	$repeater_name       = $field['name'];

	// Only process repeaters that are on the translatable list for the current context.
	if ( ! frl_string_matches_pattern( $repeater_name, $translatable_fields ) ) {
		return $value;
	}

	// Map sub-field names to their types for efficient lookup.
	$sub_field_types = array();
	if ( frl_is_array_not_empty( $field['sub_fields'] ) ) {
		foreach ( $field['sub_fields'] as $sub_field ) {
			if ( ! empty( $sub_field['name'] ) ) {
				$sub_field_types[ $sub_field['name'] ] = $sub_field['type'];
			}
		}
	}

	// Per-repeater override list: if present, only these subfields are eligible (exact match)
	$override_sub_fields = FRL_TRANSLATOR_REPEATER_SUBFIELDS_OVERRIDE[ $repeater_name ] ?? null; // @phpstan-ignore-line

	// Loop through rows and programmatically translate text-based sub-fields.
	foreach ( $value as &$row ) {
		if ( is_array( $row ) ) {
			foreach ( $row as $sub_field_name => &$sub_field_value ) {
				// If override list is defined for this repeater, allow only listed patterns
				if ( is_array( $override_sub_fields ) ) { // @phpstan-ignore-line
					if ( ! frl_string_matches_pattern( $sub_field_name, $override_sub_fields ) ) {
						continue;
					}
				}

				$sub_field_type = $sub_field_types[ $sub_field_name ] ?? '';

				if ( empty( $sub_field_type ) ) {
					frl_log(
						'Translator: Sub-field \'{sub_field_name}\' in repeater \'{repeater_name}\' has data but is not defined in the ACF field group. It will not be translated.',
						array(
							'sub_field_name' => $sub_field_name,
							'repeater_name'  => $repeater_name,
						)
					);
					continue;
				}

				// Translate only if type is text-like AND subfield name is explicitly allowed (exact match),
				// unless a per-repeater exclusion applies.
				if (
					in_array( $sub_field_type, FRL_TRANSLATOR_REPEATER_SUBFIELD_TYPES, true )
					&& ( empty( FRL_TRANSLATOR_REPEATER_SUBFIELDS ) // @phpstan-ignore-line
						|| frl_string_matches_pattern( $sub_field_name, FRL_TRANSLATOR_REPEATER_SUBFIELDS )
					)
				) {
					$sub_field_value = frl_translator_apply( $sub_field_value );
				}
			}
		}
	}
	unset( $row, $sub_field_value ); // Unset references.

	return $value;
}

/**
 * Determines which list of translatable fields to use based on the ACF object ID.
 *
 * @param string|int $object_id The ACF object ID (e.g., 123, 'post_123', 'term_45', 'user_6', 'options').
 * @return array The corresponding array of field names to translate.
 */
function frl_translator_get_contextual_fields( string|int $object_id ): array {
	if ( is_string( $object_id ) ) {
		if ( str_starts_with( $object_id, 'term_' ) ) {
			return FRL_TRANSLATOR_FIELDS_TERMS;
		}
		if ( str_starts_with( $object_id, 'user_' ) ) {
			return FRL_TRANSLATOR_FIELDS_USERS;
		}
		if ( in_array( $object_id, array( 'option', 'options' ), true ) ) {
			return FRL_TRANSLATOR_OPTIONS;
		}
	}
	// Default to post fields for numeric IDs or 'post_123' style IDs.
	return FRL_TRANSLATOR_FIELDS;
}

/**
 * Helper to track a cached meta field for later invalidation.
 *
 * @param string $type Object type ('post', 'term', 'user').
 * @param int $id Object ID.
 * @param string $key Meta key being cached.
 * @return void
 */
function frl_translator_track_cached_meta( string $type, int $id, string $key ): void {
	static $shutdown_hook_added = false;
	global $frl_translator_tracking_queue;

	// Use a consistent prefix for all tracking keys for the queue.
	$queue_key = "{$type}_{$id}";
	if ( ! isset( $frl_translator_tracking_queue[ $queue_key ] ) ) {
		$frl_translator_tracking_queue[ $queue_key ] = array();
	}
	// Use the meta key as the array key for automatic deduplication within the request.
	$frl_translator_tracking_queue[ $queue_key ][ $key ] = true;

	// The shutdown action only needs to be added once per request.
	if ( ! $shutdown_hook_added ) {
		add_action( 'shutdown', 'frl_translator_process_tracking_queue', 10, 0 );
		$shutdown_hook_added = true;
	}
}

/**
 * Processes the queued tracking keys at the end of the request.
 * Hooked to 'shutdown' to prevent re-entrant cache calls.
 *
 * @return void
 */
function frl_translator_process_tracking_queue(): void {
	global $frl_translator_tracking_queue;

	if ( empty( $frl_translator_tracking_queue ) ) {
		return;
	}

	foreach ( $frl_translator_tracking_queue as $queue_key => $keys_to_add ) {
		// Reconstruct type and id from the queue key.
		list($type, $id_str) = explode( '_', $queue_key, 2 );
		$id                  = (int) $id_str;

		if ( $id <= 0 ) {
			continue;
		}

		$tracking_key = "translation_{$type}meta_keys_{$id}";
		$tracked_keys = frl_cache_get( 'metafields', $tracking_key, null ) ?? array();

		$newly_tracked_keys = array_keys( $keys_to_add );
		$updated_keys       = array_unique( array_merge( $tracked_keys, $newly_tracked_keys ) );

		// Only write to cache if the list has actually changed.
		if ( count( $updated_keys ) > count( $tracked_keys ) ) {
			frl_cache_set( 'metafields', $tracking_key, $updated_keys );
		}
	}
}

/**
 * Returns a generalized callback for fetching and translating values.
 * Handles both meta and option types.
 *
 * @param string $type The callback type ('meta' or 'option').
 * @param array $args The arguments needed for the specific callback.
 * @param bool|null &$is_cache_miss A flag passed by reference for meta types.
 * @return callable The callback function.
 */
function frl_translator_get_cache_callback( string $type, array $args, ?bool &$is_cache_miss = null ): callable {
	return function () use ( $type, $args, &$is_cache_miss ) {
		if ( $type === 'meta' ) {
			if ( $is_cache_miss !== null ) {
				$is_cache_miss = true;
			}

			$meta_type   = $args['type'] ?? 'post';
			$filter      = "get_{$meta_type}_metadata";
			$callback    = "frl_translator_{$meta_type}_meta";
			$getter      = "get_{$meta_type}_meta";
			$priority    = 20;
			$num_args    = 4;
			$getter_args = array( $args['id'], $args['key'], $args['single'] );
		} elseif ( $type === 'option' ) {
			$option_name = $args['name'];
			$filter      = "pre_option_{$option_name}";
			$callback    = 'frl_translator_pre_option';
			$getter      = 'get_option';
			$priority    = 10;
			$num_args    = 2;
			$getter_args = array( $option_name );
		} else {
			return null; // Unsupported type
		}

		// Centralized unhook/re-hook logic
		remove_filter( $filter, $callback, $priority );
		$raw_value = ( $getter )( ...$getter_args );
		add_filter( $filter, $callback, $priority, $num_args );

		if ( is_string( $raw_value ) && ! empty( $raw_value ) ) {
			return frl_translator_apply( $raw_value );
		}

		/**
		 * No logging if:
		 * 1. false: Not Found - meta field or option does not exist in database
		 * 2. null: Unfiltered - No other plugin filtered data
		 * before our get_post_metadata or pre_option_* filters
		 * 3. '': Empty string - Post is saved with field intentionally left blank
		 */
		if ( $raw_value === false || $raw_value === null || $raw_value === '' ) {
			return $raw_value; // short-circuit with actual empty/not-found values
		}

		$object_id   = $args['id'] ?? false;
		$object_info = $object_id ? " (Object ID: $object_id)" : '';

		// Log only for options, not for meta fields.
		if ( $type !== 'meta' ) {
			frl_log(
				'Translator: A value for {type} with key "{key}"{object_info} is configured for translation but is not a translatable string. Translation skipped.',
				array(
					'type'        => $type,
					'key'         => $args['name'] ?? $args['key'],
					'object_info' => $object_info,
				)
			);
		}

		// For meta, return null to avoid short-circuiting get_*_meta for non-string values (arrays/objects).
		// This lets core proceed to default retrieval (and ACF format filters) without interference.
		return ( $type === 'meta' ) ? null : false;
	};
}

/**
 * Generic handler for retrieving and caching translated meta values.
 *
 * @param string $meta_type   The type of meta ('post', 'term', 'user').
 * @param mixed  $value       The original meta value from the filter.
 * @param int    $object_id   The ID of the object.
 * @param string $meta_key    The meta key.
 * @param bool   $single      Whether a single value is requested.
 * @return mixed The original or translated value.
 */
function frl_translator_get_cached_meta( string $meta_type, mixed $value, int $object_id, ?string $meta_key, ?bool $single ): mixed {
	// When meta_key is null or empty (e.g. get_post_meta without a key), we cannot
	// determine if the field is translatable – bail out early.
	if ( $meta_key === null || $meta_key === '' ) {
		return $value;
	}

	// Define the list of translatable keys based on meta type.
	$allowed_keys = array();
	if ( $meta_type === 'post' ) {
		$allowed_keys = FRL_TRANSLATOR_FIELDS;
	} elseif ( $meta_type === 'term' ) {
		$allowed_keys = FRL_TRANSLATOR_FIELDS_TERMS;
	} elseif ( $meta_type === 'user' ) {
		$allowed_keys = FRL_TRANSLATOR_FIELDS_USERS;
	}

	// Skip translation if conditions are not met.
	if ( frl_translator_should_skip_translation( $value, $single, $meta_key, $allowed_keys ) ) {
		return $value;
	}

	$version       = frl_get_option( 'translation_version' ) ?: 1;
	$cache_key     = "translation_{$meta_type}meta_{$object_id}_{$meta_key}_{$version}";
	$is_cache_miss = false;

	$translated_meta = frl_cache_remember(
		'metafields',
		$cache_key,
		frl_translator_get_cache_callback(
			'meta',
			array(
				'id'     => $object_id,
				'key'    => $meta_key,
				'single' => $single,
				'type'   => $meta_type,
			),
			$is_cache_miss
		)
	);

	// If the value was generated, queue its key for tracking on shutdown.
	if ( $is_cache_miss ) {
		frl_translator_track_cached_meta( $meta_type, $object_id, $meta_key );
	}

	return $translated_meta;
}

/**
 * Helper to determine if metadata translation should be skipped.
 *
 * @param mixed  $value        The meta value.
 * @param bool   $single       Whether a single value is requested.
 * @param string $meta_key     The meta key.
 * @param array  $allowed_keys The list of keys allowed for translation.
 * @return bool True to skip translation, false to proceed.
 */
function frl_translator_should_skip_translation( mixed $value, bool $single, string $meta_key, array $allowed_keys ): bool {
	return null === $value
		|| ! $single
		|| ! frl_is_valid_frontend_page_request()
		|| ! frl_string_matches_pattern( $meta_key, $allowed_keys );
}
