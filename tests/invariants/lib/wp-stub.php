<?php
/**
 * WordPress and plugin-helper stubs for the dependency-free invariant tests.
 *
 * Load this file, then only the specific plugin file under test. Never load
 * WordPress or the plugin bootstrap here: these tests exercise pure logic
 * (option writes, cron scheduling, cache keys) without a database.
 *
 * @package Fralenuvole
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/frl-invariants/' );
}
if ( ! defined( 'FRL_PREFIX' ) ) {
	define( 'FRL_PREFIX', 'frl' );
}
if ( ! defined( 'FRL_NAME' ) ) {
	define( 'FRL_NAME', 'fralenuvole' );
}
if ( ! defined( 'FRL_VERSION' ) ) {
	define( 'FRL_VERSION', 'tests' );
}
if ( ! defined( 'FRL_TEST_PLUGIN_DIR' ) ) {
	define( 'FRL_TEST_PLUGIN_DIR', dirname( __DIR__, 3 ) . '/' );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'WEEK_IN_SECONDS' ) ) {
	define( 'WEEK_IN_SECONDS', 604800 );
}
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}

$GLOBALS['frl_test'] = array(
	'options'            => array(),
	'option_writes'      => array(),
	'transients'         => array(),
	'transient_deletes'  => array(),
	'cron'               => array(),
	'actions'            => array(),
	'did'                => array(),
	'flushes'            => 0,
	'cache'              => array(),
	'clears'             => array(),
	'clear_keys_calls'   => array(),
	'logs'               => array(),
	'post_types'         => array(),
	'taxonomies'         => array(),
	'pages_by_path'      => array(),
	'by_name'            => array(),
	'posts_by_id'        => array(),
	'terms_by_slug'      => array(),
	'terms_by_id'        => array(),
	'valid_page_request' => true,
	'languages'          => array( 'en', 'it' ),
	'language'           => 'en',
	'is_admin'           => true,
	'doing_ajax'         => false,
	'doing_cron'         => false,
	'logged_in'          => true,
);

/**
 * Reference to the mutable test state.
 *
 * @return array
 */
function &frl_test_state() {
	return $GLOBALS['frl_test'];
}

/**
 * Reset all state to a clean request.
 *
 * @return void
 */
function frl_test_reset(): void {
	$GLOBALS['frl_test'] = array(
		'options'            => array(),
		'option_writes'      => array(),
		'transients'         => array(),
		'transient_deletes'  => array(),
		'cron'               => array(),
		'actions'            => array(),
		'did'                => array(),
		'flushes'            => 0,
		'cache'              => array(),
		'clears'             => array(),
		'clear_keys_calls'   => array(),
		'logs'               => array(),
		'post_types'         => array(),
		'taxonomies'         => array(),
		'pages_by_path'      => array(),
		'by_name'            => array(),
		'posts_by_id'        => array(),
		'terms_by_slug'      => array(),
		'terms_by_id'        => array(),
		'valid_page_request' => true,
		'languages'          => array( 'en', 'it' ),
		'language'           => 'en',
		'is_admin'           => true,
		'doing_ajax'         => false,
		'doing_cron'         => false,
		'logged_in'          => true,
	);
}

/**
 * Seed an option without recording it as a write.
 *
 * @param string $name  Option name.
 * @param mixed  $value Option value.
 * @return void
 */
function frl_test_set_option( string $name, $value ): void {
	$GLOBALS['frl_test']['options'][ $name ] = $value;
}

/**
 * Number of option writes recorded since the last reset.
 *
 * @return int
 */
function frl_test_write_count(): int {
	return count( $GLOBALS['frl_test']['option_writes'] );
}

/**
 * Clear the recorded option writes.
 *
 * @return void
 */
function frl_test_reset_writes(): void {
	$GLOBALS['frl_test']['option_writes'] = array();
}

/**
 * Seed the registered post types (name => object).
 *
 * @param array $types Post type objects keyed by name.
 * @return void
 */
function frl_test_set_post_types( array $types ): void {
	$GLOBALS['frl_test']['post_types'] = $types;
}

/**
 * Seed the registered taxonomies (name => object).
 *
 * @param array $taxonomies Taxonomy objects keyed by name.
 * @return void
 */
function frl_test_set_taxonomies( array $taxonomies ): void {
	$GLOBALS['frl_test']['taxonomies'] = $taxonomies;
}

/**
 * Record an option write.
 *
 * @param string $name Option name.
 * @return void
 */
function frl_test_record_write( string $name ): void {
	$GLOBALS['frl_test']['option_writes'][] = $name;
}

// ---------------------------------------------------------------------------
// WordPress stubs.
// ---------------------------------------------------------------------------

/**
 * Get an option from the stub store.
 *
 * @param string $name     Option name.
 * @param mixed  $fallback Value when the option is absent.
 * @return mixed
 */
function get_option( $name, $fallback = false ) {
	return array_key_exists( $name, $GLOBALS['frl_test']['options'] )
		? $GLOBALS['frl_test']['options'][ $name ]
		: $fallback;
}

/**
 * Update an option and record the write.
 *
 * @param string $name     Option name.
 * @param mixed  $value    Option value.
 * @param mixed  $autoload Autoload flag (unused).
 * @return bool
 */
function update_option( $name, $value, $autoload = null ) {
	frl_test_record_write( $name );
	$GLOBALS['frl_test']['options'][ $name ] = $value;
	return true;
}

/**
 * Add a missing option and record the write.
 *
 * @param string $name       Option name.
 * @param mixed  $value      Option value.
 * @param string $deprecated Unused.
 * @param string $autoload   Autoload flag (unused).
 * @return bool
 */
function add_option( $name, $value = '', $deprecated = '', $autoload = 'yes' ) {
	if ( array_key_exists( $name, $GLOBALS['frl_test']['options'] ) ) {
		return false;
	}
	frl_test_record_write( $name );
	$GLOBALS['frl_test']['options'][ $name ] = $value;
	return true;
}

/**
 * Delete an option.
 *
 * @param string $name Option name.
 * @return bool
 */
function delete_option( $name ) {
	unset( $GLOBALS['frl_test']['options'][ $name ] );
	return true;
}

/**
 * Register a hook callback.
 *
 * @param string $hook          Hook name.
 * @param mixed  $callback      Callback.
 * @param int    $priority      Priority.
 * @param int    $accepted_args Accepted args.
 * @return bool
 */
function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['frl_test']['actions'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
	return true;
}

/**
 * Alias of add_action for the stub registry.
 *
 * @param string $hook          Hook name.
 * @param mixed  $callback      Callback.
 * @param int    $priority      Priority.
 * @param int    $accepted_args Accepted args.
 * @return bool
 */
function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	return add_action( $hook, $callback, $priority, $accepted_args );
}

/**
 * Fire a hook, incrementing did_action before the callbacks run (as WordPress does).
 *
 * @param string $hook Hook name.
 * @param mixed  ...$args Hook arguments.
 * @return void
 */
function do_action( $hook, ...$args ): void {
	$state                 = &frl_test_state();
	$state['did'][ $hook ] = ( $state['did'][ $hook ] ?? 0 ) + 1;

	if ( empty( $state['actions'][ $hook ] ) ) {
		return;
	}

	ksort( $state['actions'][ $hook ] );
	foreach ( $state['actions'][ $hook ] as $callbacks ) {
		foreach ( $callbacks as $entry ) {
			call_user_func_array( $entry[0], array_slice( $args, 0, $entry[1] ) );
		}
	}
}

/**
 * Number of times a hook has fired.
 *
 * @param string $hook Hook name.
 * @return int
 */
function did_action( $hook ) {
	return (int) ( $GLOBALS['frl_test']['did'][ $hook ] ?? 0 );
}

/**
 * Apply filters through the stub registry.
 *
 * @param string $hook  Hook name.
 * @param mixed  $value Initial value.
 * @param mixed  ...$args Extra args.
 * @return mixed
 */
function apply_filters( $hook, $value, ...$args ) {
	if ( empty( $GLOBALS['frl_test']['actions'][ $hook ] ) ) {
		return $value;
	}

	ksort( $GLOBALS['frl_test']['actions'][ $hook ] );
	foreach ( $GLOBALS['frl_test']['actions'][ $hook ] as $callbacks ) {
		foreach ( $callbacks as $entry ) {
			$value = call_user_func_array( $entry[0], array_merge( array( $value ), array_slice( $args, 0, $entry[1] - 1 ) ) );
		}
	}

	return $value;
}

/**
 * Next scheduled timestamp for a hook.
 *
 * @param string $hook Hook name.
 * @return int|false
 */
function wp_next_scheduled( $hook ) {
	return $GLOBALS['frl_test']['cron'][ $hook ] ?? false;
}

/**
 * Schedule a single event.
 *
 * @param int    $timestamp Timestamp.
 * @param string $hook      Hook name.
 * @return bool
 */
function wp_schedule_single_event( $timestamp, $hook ) {
	$GLOBALS['frl_test']['cron'][ $hook ] = $timestamp;
	return true;
}

/**
 * Clear scheduled events for a hook.
 *
 * @param string $hook Hook name.
 * @return void
 */
function wp_clear_scheduled_hook( $hook ): void {
	unset( $GLOBALS['frl_test']['cron'][ $hook ] );
}

/**
 * Record a rewrite-rules flush.
 *
 * @param bool $hard Whether the flush is hard.
 * @return void
 */
function flush_rewrite_rules( $hard = true ): void {
	++$GLOBALS['frl_test']['flushes'];
}

/**
 * Delete a cache key (no-op).
 *
 * @param string $key   Cache key.
 * @param string $group Cache group.
 * @return bool
 */
function wp_cache_delete( $key, $group = '' ) {
	return true;
}

/**
 * Whether the current request is an admin request.
 *
 * @return bool
 */
function is_admin() {
	return (bool) $GLOBALS['frl_test']['is_admin'];
}

/**
 * Whether the current request is an AJAX request.
 *
 * @return bool
 */
function wp_doing_ajax() {
	return (bool) $GLOBALS['frl_test']['doing_ajax'];
}

/**
 * Whether the current request is a cron request.
 *
 * @return bool
 */
function wp_doing_cron() {
	return (bool) $GLOBALS['frl_test']['doing_cron'];
}

/**
 * Whether a user is logged in.
 *
 * @return bool
 */
function is_user_logged_in() {
	return (bool) $GLOBALS['frl_test']['logged_in'];
}

/**
 * Build a home URL.
 *
 * @param string $path Path.
 * @return string
 */
function home_url( $path = '' ) {
	return 'https://example.test' . $path;
}

/**
 * Parse a URL.
 *
 * @param string $url       URL.
 * @param int    $component Component.
 * @return mixed
 */
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

/**
 * Whether a persistent object cache is in use.
 *
 * @return bool
 */
function wp_using_ext_object_cache() {
	return true;
}

/**
 * Pages query stub.
 *
 * @param array $args Query args.
 * @return array
 */
function get_pages( $args = array() ) {
	return array();
}

/**
 * Registered post types stub.
 *
 * @param array  $args   Query args.
 * @param string $output Output format.
 * @return array
 */
function get_post_types( $args = array(), $output = 'names' ) {
	return $GLOBALS['frl_test']['post_types'];
}

/**
 * Registered taxonomies stub.
 *
 * @param array  $args   Query args.
 * @param string $output Output format.
 * @return array
 */
function get_taxonomies( $args = array(), $output = 'names' ) {
	return $GLOBALS['frl_test']['taxonomies'];
}

/**
 * Post type object stub.
 *
 * @param string $name Post type name.
 * @return object|false
 */
function get_post_type_object( $name ) {
	return $GLOBALS['frl_test']['post_types'][ $name ] ?? false;
}

/**
 * Taxonomy object stub.
 *
 * @param string $name Taxonomy name.
 * @return object|false
 */
function get_taxonomy( $name ) {
	return $GLOBALS['frl_test']['taxonomies'][ $name ] ?? false;
}

/**
 * Whether a post type exists.
 *
 * @param string $name Post type name.
 * @return bool
 */
function post_type_exists( $name ) {
	return isset( $GLOBALS['frl_test']['post_types'][ $name ] );
}

/**
 * Whether a taxonomy exists.
 *
 * @param string $name Taxonomy name.
 * @return bool
 */
function taxonomy_exists( $name ) {
	return isset( $GLOBALS['frl_test']['taxonomies'][ $name ] );
}

/**
 * Whether a post type is hierarchical.
 *
 * @param string $name Post type name.
 * @return bool
 */
function is_post_type_hierarchical( $name ) {
	$object = get_post_type_object( $name );
	return $object && ! empty( $object->hierarchical );
}

/**
 * Sanitize a key.
 *
 * @param string $key Key.
 * @return string
 */
function sanitize_key( $key ) {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) );
}

/**
 * Sanitize a title into a slug.
 *
 * @param string $title Title.
 * @return string
 */
function sanitize_title( $title ) {
	return trim( strtolower( preg_replace( '/[^a-z0-9]+/i', '-', (string) $title ) ), '-' );
}

/**
 * Sanitize a text field.
 *
 * @param string $value Value.
 * @return string
 */
function sanitize_text_field( $value ) {
	return trim( (string) $value );
}

/**
 * Escape HTML.
 *
 * @param string $value Value.
 * @return string
 */
function esc_html( $value ) {
	return (string) $value;
}

/**
 * Escape a URL.
 *
 * @param string $value Value.
 * @return string
 */
function esc_url( $value ) {
	return (string) $value;
}

/**
 * Escape an attribute.
 *
 * @param string $value Value.
 * @return string
 */
function esc_attr( $value ) {
	return (string) $value;
}

/**
 * JSON encode.
 *
 * @param mixed $value Value.
 * @param int   $flags Flags.
 * @return string|false
 */
function wp_json_encode( $value, $flags = 0 ) {
	return json_encode( $value, $flags );
}

/**
 * Current time.
 *
 * @param string $format Format.
 * @return string
 */
function current_time( $format = 'mysql' ) {
	return gmdate( $format );
}

/**
 * WP_Error check.
 *
 * @param mixed $value Value.
 * @return bool
 */
function is_wp_error( $value ) {
	return false;
}

/**
 * Term lookup stub (seeded through the terms_by_slug state map).
 *
 * @param string $field    Field.
 * @param mixed  $value    Value.
 * @param string $taxonomy Taxonomy.
 * @return object|null
 */
function get_term_by( $field, $value, $taxonomy ) {
	if ( 'slug' !== $field ) {
		return null;
	}
	return $GLOBALS['frl_test']['terms_by_slug'][ $taxonomy . '|' . $value ] ?? null;
}

/**
 * Terms query stub (slug lookups only).
 *
 * @param array $args Query args.
 * @return array
 */
function get_terms( $args = array() ) {
	$slug     = isset( $args['slug'] ) ? (string) $args['slug'] : '';
	$taxonomy = isset( $args['taxonomy'] ) ? (string) $args['taxonomy'] : '';
	if ( '' === $slug ) {
		return array();
	}

	$term = $GLOBALS['frl_test']['terms_by_slug'][ $taxonomy . '|' . $slug ] ?? null;
	return null === $term ? array() : array( $term );
}

/**
 * Term stub.
 *
 * @param int    $term_id  Term ID.
 * @param string $taxonomy Taxonomy.
 * @return object|null
 */
function get_term( $term_id, $taxonomy ) {
	return $GLOBALS['frl_test']['terms_by_id'][ (int) $term_id ] ?? null;
}

/**
 * Ancestors stub.
 *
 * @param int    $term_id  Term ID.
 * @param string $taxonomy Taxonomy.
 * @param string $type     Type.
 * @return array
 */
function get_ancestors( $term_id, $taxonomy, $type = '' ) {
	return array();
}

/**
 * Posts query stub, driven by the by_name / pages_by_path state maps.
 *
 * @param array $args Query args.
 * @return array
 */
function get_posts( $args = array() ) {
	$post_types = isset( $args['post_type'] ) ? (array) $args['post_type'] : array( 'post' );

	if ( ! empty( $args['pagename'] ) ) {
		foreach ( $post_types as $post_type ) {
			$post = $GLOBALS['frl_test']['pages_by_path'][ $post_type . '|' . $args['pagename'] ] ?? null;
			if ( null !== $post ) {
				return array( $post );
			}
		}
		return array();
	}

	if ( ! empty( $args['name'] ) ) {
		foreach ( $post_types as $post_type ) {
			$post = $GLOBALS['frl_test']['by_name'][ $post_type . '|' . $args['name'] ] ?? null;
			if ( null !== $post ) {
				return array( $post );
			}
		}
	}

	return array();
}

/**
 * Page path lookup stub, driven by the pages_by_path state map.
 *
 * @param string $path      Page path.
 * @param string $output    Output format.
 * @param string $post_type Post type.
 * @return object|null
 */
function get_page_by_path( $path, $output = OBJECT, $post_type = 'page' ) {
	return $GLOBALS['frl_test']['pages_by_path'][ $post_type . '|' . $path ] ?? null;
}

/**
 * Post lookup stub.
 *
 * @param int $post_id Post ID.
 * @return object|null
 */
function get_post( $post_id ) {
	return $GLOBALS['frl_test']['posts_by_id'][ (int) $post_id ] ?? null;
}

/**
 * Whether the request is a valid public page request.
 *
 * @return bool
 */
function frl_is_valid_page_request() {
	return (bool) $GLOBALS['frl_test']['valid_page_request'];
}

/**
 * Whether the request is a valid frontend page request.
 *
 * @return bool
 */
function frl_is_valid_frontend_page_request() {
	return (bool) $GLOBALS['frl_test']['valid_page_request'];
}

// ---------------------------------------------------------------------------
// Plugin helper stubs (not defined by the plugin files these tests load).
// ---------------------------------------------------------------------------

/**
 * Prefix a plugin name.
 *
 * @param string $name Name.
 * @return string
 */
function frl_prefix( $name = '' ) {
	return '' === $name ? FRL_PREFIX . '_' : FRL_PREFIX . '_' . $name;
}

/**
 * Record a log entry.
 *
 * @param string $message Message.
 * @param array  $context Context.
 * @param bool   $email   Email flag.
 * @return void
 */
function frl_log( $message, $context = array(), $email = false ): void {
	$GLOBALS['frl_test']['logs'][] = $message;
}

/**
 * Read a plugin option.
 *
 * @param string $key          Option key.
 * @param bool   $bypass_cache Bypass flag.
 * @return mixed
 */
function frl_get_option( $key, $bypass_cache = false ) {
	return get_option( frl_prefix( $key ), null );
}

/**
 * Write a plugin option.
 *
 * @param string $key          Option key.
 * @param mixed  $value        Value.
 * @param bool   $clear_cache  Clear-cache flag.
 * @param mixed  $autoload     Autoload flag.
 * @return bool
 */
function frl_update_option( $key, $value, $clear_cache = true, $autoload = null ) {
	return update_option( frl_prefix( $key ), $value );
}

/**
 * Read a plugin transient.
 *
 * @param string $key    Key.
 * @param bool   $bypass Bypass flag.
 * @return mixed
 */
function frl_get_transient( $key, $bypass = false ) {
	return $GLOBALS['frl_test']['transients'][ $key ] ?? false;
}

/**
 * Write a plugin transient.
 *
 * @param string $key   Key.
 * @param mixed  $value Value.
 * @param int    $ttl   TTL.
 * @return bool
 */
function frl_set_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['frl_test']['transients'][ $key ] = $value;
	return true;
}

/**
 * Delete a plugin transient.
 *
 * @param string $key Key.
 * @return bool
 */
function frl_delete_transient( $key ) {
	unset( $GLOBALS['frl_test']['transients'][ $key ] );
	$GLOBALS['frl_test']['transient_deletes'][] = $key;
	return true;
}

/**
 * Normalize a cache key the same way the stub store does.
 *
 * @param string $group Group.
 * @param mixed  $key   Key.
 * @param string $lang  Language.
 * @return string
 */
function frl_test_cache_key( string $group, $key, string $lang = '' ): string {
	$key = is_array( $key ) ? md5( (string) json_encode( $key ) ) : (string) $key;
	return '' === $lang ? $group . '|' . $key : $group . '|' . $lang . '|' . $key;
}

/**
 * Remember a value in the stub cache.
 *
 * @param string   $group    Group.
 * @param mixed    $key      Key.
 * @param callable $callback Builder.
 * @param int|null $ttl      TTL.
 * @return mixed
 */
function frl_cache_remember( $group, $key, $callback, $ttl = null ) {
	$cache_key = frl_test_cache_key( $group, $key );
	if ( array_key_exists( $cache_key, $GLOBALS['frl_test']['cache'] ) ) {
		return $GLOBALS['frl_test']['cache'][ $cache_key ];
	}

	$value = $callback();
	if ( null !== $value ) {
		$GLOBALS['frl_test']['cache'][ $cache_key ] = $value;
	}
	return $value;
}

/**
 * Read a value from the stub cache.
 *
 * @param string        $group    Group.
 * @param mixed         $key      Key.
 * @param callable|null $callback Unused.
 * @return mixed|null
 */
function frl_cache_get( $group, $key, $callback = null ) {
	return $GLOBALS['frl_test']['cache'][ frl_test_cache_key( $group, $key ) ] ?? null;
}

/**
 * Write a value to the stub cache.
 *
 * @param string   $group Group.
 * @param mixed    $key   Key.
 * @param mixed    $value Value.
 * @param int|null $ttl   TTL.
 * @return bool
 */
function frl_cache_set( $group, $key, $value, $ttl = null ) {
	$GLOBALS['frl_test']['cache'][ frl_test_cache_key( $group, $key ) ] = $value;
	return true;
}

/**
 * Clear a group or key and record the call.
 *
 * @param string      $group Group.
 * @param string|null $key   Key.
 * @param bool        $deps  Dependencies flag.
 * @return array
 */
function frl_cache_clear( $group, $key = null, $deps = true ) {
	$GLOBALS['frl_test']['clears'][] = array( $group, $key, $deps );

	$prefix = null === $key ? $group . '|' : frl_test_cache_key( $group, $key );
	foreach ( array_keys( $GLOBALS['frl_test']['cache'] ) as $cache_key ) {
		if ( str_starts_with( (string) $cache_key, (string) $prefix ) ) {
			unset( $GLOBALS['frl_test']['cache'][ $cache_key ] );
		}
	}
	return array();
}

/**
 * Clear several keys and record the call.
 *
 * @param string      $group     Group.
 * @param array       $keys      Keys.
 * @param bool|array  $languages Languages flag.
 * @return int
 */
function frl_cache_clear_keys( $group, $keys, $languages = false ) {
	$GLOBALS['frl_test']['clear_keys_calls'][] = array( $group, $keys, $languages );
	return count( $keys );
}

/**
 * Read a language-group key from the stub cache.
 *
 * @param string $group    Group.
 * @param mixed  $key      Key.
 * @param string $language Language.
 * @return mixed|null
 */
function frl_cache_get_in_language( $group, $key, $language ) {
	return $GLOBALS['frl_test']['cache'][ frl_test_cache_key( $group, $key, $language ) ] ?? null;
}

/**
 * Active languages stub.
 *
 * @return array
 */
function frl_get_active_languages(): array {
	return $GLOBALS['frl_test']['languages'];
}

/**
 * Active languages fallback stub.
 *
 * @return array
 */
function frl_get_active_languages_fallback(): array {
	return $GLOBALS['frl_test']['languages'];
}

/**
 * Current language stub.
 *
 * @param int|null $id   Object ID.
 * @param string   $type Object type.
 * @return string
 */
function frl_get_language( $id = null, $type = 'post' ) {
	return $GLOBALS['frl_test']['language'];
}

/**
 * Default language stub.
 *
 * @return string
 */
function frl_get_default_language() {
	return $GLOBALS['frl_test']['languages'][0];
}

/**
 * Parse a text list.
 *
 * @param string $input Text list.
 * @return array
 */
function frl_textlist_to_array( $input ) {
	$input = trim( (string) $input );
	if ( '' === $input ) {
		return array();
	}

	$lines = preg_split( '/\r\n|\r|\n/', $input );
	return array_values(
		array_map(
			static function ( $line ) {
				$line = trim( $line );
				return str_contains( $line, '|' ) ? array_map( 'trim', explode( '|', $line ) ) : array( $line );
			},
			$lines
		)
	);
}

/**
 * Whether an array (or one of its keys) is non-empty.
 *
 * @param mixed       $value Value.
 * @param string|null $key   Key.
 * @return bool
 */
function frl_is_array_not_empty( $value, $key = null ) {
	if ( null !== $key ) {
		return is_array( $value ) && ! empty( $value[ $key ] );
	}
	return is_array( $value ) && ! empty( $value );
}
