<?php

/**
 * Rewrites slugs for custom post types and taxonomies using independent feature architecture
 *
 * @package Fralenuvole
 * @since 3.0.0
 */

/**
 * Priority-Based Feature Architecture
 * =====================================
 *
 * Each feature is self-contained with:
 * - Its own configuration validation
 * - Independent rewrite rule generation
 * - Isolated URL request handling
 * - Clear priority assignment
 *
 * Feature Priority Order:
 *   Priority 15: CPT Archive Base Translation
 *   Priority 25: CPT Single Base Translation
 *   Priority 35: Taxonomy Base Removal
 *   Priority 40: CPT Base Removal
 *   Priority 50+: WordPress Defaults (lowest priority)
 *
 * Benefits:
 * - No direct class-to-class coupling
 * - No pattern conflicts through priority-based resolution
 * - Easy feature addition/removal
 * - Clear debugging and maintenance
 * - Robust conflict detection
 *
 * Note on inter-feature coordination:
 * Features communicate exclusively through named WordPress filters (loose coupling).
 * Specifically, Frl_CPT_Archive_Base_Translation_Feature publishes its translated URL
 * prefixes via the 'frl_rewriter_url_prefixes' filter, which Frl_Taxonomy_Base_Removal_Feature
 * consumes to build correct catch-all exclusion lists. Absence of the CPT archive feature
 * degrades gracefully (incomplete exclusions) rather than causing errors.
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load dependencies
require_once __DIR__ . '/interface-rewriter.php';
require_once __DIR__ . '/class-rewriter-coordinator.php';
require_once __DIR__ . '/trait-cache-key-generator.php';

// Config validator is admin-only: heavy class with no frontend value.
if ( is_admin() ) {
	require_once __DIR__ . '/class-rewriter-config-validator.php';
}

/**
 * Main Rewriter class using independent feature architecture.
 *
 * This class acts as a facade for the coordinator-based system, which now
 * handles both incoming request parsing and outgoing URL transformation.
 *
 * @package Fralenuvole
 * @since 3.0.0
 */
final class Frl_Rewriter implements Frl_Rewriter_Interface {

	use Frl_Rewriter_Cache_Key_Trait;

	private Frl_Rewriter_Coordinator $coordinator;

	private static ?self $instance        = null;
	private static bool $hooks_registered = false;

	/**
	 * Private constructor - use init() to get instance
	 *
	 * @return void
	 */
	private function __construct() {
		$this->coordinator = Frl_Rewriter_Coordinator::init();
		$this->register_hooks();
		// Validation and feature caching are deferred to runtime to ensure CPTs and mappings are loaded.
	}

	/**
	 * Get the singleton instance
	 *
	 * @return self The rewriter instance
	 */
	public static function init(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register WordPress hooks for URL transformation and cache invalidation
	 *
	 * @return void
	 */
	private function register_hooks(): void {
		if ( self::$hooks_registered ) {
			return;
		}

		// URL transformation hooks (for generating URLs)
		add_filter( 'post_type_link', array( $this, 'filter_post_link' ), 10, 2 );
		add_filter( 'term_link', array( $this, 'filter_term_link' ), 10, 3 );

		// Wire cache invalidation so changes to permalink options flush rewriter caches.
		self::register_cache_invalidation_hooks();

		self::$hooks_registered = true;
	}


	/**
	 * Filter post link for URL transformation
	 *
	 * @param string $link The post link URL
	 * @param mixed $post The post object
	 * @return string The transformed post link
	 */
	public function filter_post_link( string $link, $post ): string {
		return $this->transform_url( $link, $post );
	}

	/**
	 * Filter term link for URL transformation
	 *
	 * @param string $link The term link URL
	 * @param mixed $term The term object or term ID
	 * @param string $taxonomy The taxonomy name (optional)
	 * @return string The transformed term link
	 */
	public function filter_term_link( string $link, $term, string $taxonomy = '' ): string {
		if ( is_int( $term ) ) {
			$term = get_term( $term, $taxonomy );
		}
		if ( ! is_object( $term ) || is_wp_error( $term ) ) {
			return $link;
		}
		return $this->transform_url( $link, $term );
	}

	/**
	 * Central URL transformation logic.
	 *
	 * Iterates through features in priority order and applies ALL
	 * matching transformations (composition). Caches the result.
	 *
	 * @param string $url The URL to transform
	 * @param mixed $obj The object (post/term) the URL belongs to
	 * @return string The transformed URL
	 */
	private function transform_url( string $url, $obj ): string {
		// Early exit if input is invalid
		if ( ! is_object( $obj ) ) {
			return $url;
		}

		// REST API guard must come BEFORE the cache check.
		// URL transformation is disabled for REST to preserve canonical, untransformed URLs for
		// API clients. Placing this first ensures REST requests NEVER read a cached transformed
		// URL that was stored by a prior frontend request — guaranteeing consistent REST output
		// regardless of cache state.
		if ( frl_is_rest_api_request() ) {
			return $url;
		}

		// Preview guard: skip URL transformation for preview requests to prevent
		// preview URLs from being transformed and cached incorrectly.
		if ( is_preview() ) {
			return $url;
		}

		// Fast-path cache check: generate the key and return immediately on hit.
		// This eliminates the get_enabled_features() array_filter call on every
		// post_type_link / term_link filter invocation when the result is already cached.
		$cache_key = $this->generate_cache_key( $url, $obj );
		$cached    = frl_cache_get( 'permalinks', $cache_key );
		if ( is_string( $cached ) ) {
			return $cached;
		}

		// Re-entrancy protection: prevent recursive URL processing during ACF relationship queries.
		// Use string concatenation for the key instead of md5 for maximum performance.
		static $processing_urls = array();
		$object_id              = isset( $obj->ID ) ? $obj->ID : ( isset( $obj->term_id ) ? $obj->term_id : 'unknown' );
		$re_entrancy_key        = $object_id . '_' . $url;

		if ( isset( $processing_urls[ $re_entrancy_key ] ) ) {
			// Cache was already checked above and was a miss; no result available yet.
			return $url;
		}

		$processing_urls[ $re_entrancy_key ] = true;

		// Get enabled features (only reached on cache miss — avoids array_filter overhead on hits).
		$features = $this->coordinator->get_enabled_features();
		if ( empty( $features ) ) {
			unset( $processing_urls[ $re_entrancy_key ] );
			return $url;
		}

		// cache_key already generated above.

		try {
			$result = frl_cache_remember(
				'permalinks',
				$cache_key,
				function () use ( $url, $obj, $features ) {
					$transformed_url = $url;

					// Dispatcher cache: map object signature -> applicable features (LRU pattern).
					// $cache_order is keyed by signature (value unused) so both the "touch"
					// (move-to-end) and "evict oldest" operations are O(1) via unset()/re-insert
					// and array_key_first(), instead of O(n) array_diff()/array_shift() on a
					// plain indexed array. PHP preserves associative-array insertion order.
					static $feature_match_cache = array();
					static $cache_order         = array();
					// Build a signature that normalizes to base WP classes to prevent
					// cache fragmentation from subclasses (e.g., WC_Product extends WP_Post).
					// Using spl_object_id ensures true identity even for different instances
					// of the same class with the same post_type.
					$base_class = ( $obj instanceof WP_Post ) ? 'WP_Post' : ( ( $obj instanceof WP_Term ) ? 'WP_Term' : get_class( $obj ) );
					$signature  = $base_class;
					if ( isset( $obj->post_type ) ) {
						$signature .= '_' . $obj->post_type;
					} elseif ( isset( $obj->taxonomy ) ) {
						$signature .= '_' . $obj->taxonomy;
					}

					// LRU memory guard: evict oldest entries when cache exceeds 1024
					if ( count( $feature_match_cache ) >= 1024 && ! isset( $feature_match_cache[ $signature ] ) ) {
						// Remove oldest 10% of entries
						$evict_count = (int) ceil( count( $cache_order ) * 0.1 );
						for ( $i = 0; $i < $evict_count && ! empty( $cache_order ); $i++ ) {
							$oldest = array_key_first( $cache_order );
							unset( $cache_order[ $oldest ], $feature_match_cache[ $oldest ] );
						}
					}

					if ( isset( $feature_match_cache[ $signature ] ) ) {
						$applicable = $feature_match_cache[ $signature ];
						// Move to end of LRU order — O(1) touch via unset + re-insert
						unset( $cache_order[ $signature ] );
						$cache_order[ $signature ] = true;
					} else {
						$applicable = array();
						foreach ( $features as $feature ) {
							if ( $feature->is_enabled() && $feature->applies_to( $obj ) ) {
								$applicable[] = $feature;
							}
						}
						$feature_match_cache[ $signature ] = $applicable;
						$cache_order[ $signature ]         = true;
					}

					// Early return if no features apply to this specific object
					if ( empty( $applicable ) ) {
						return $url;
					}

					// Apply transforms only from applicable list
					foreach ( $applicable as $feature ) {
						try {
							$next_url = $feature->transform( $transformed_url, $obj );

							if ( is_string( $next_url ) ) {
								$transformed_url = $next_url;
							}
						} catch ( Throwable $e ) {
							frl_log(
								'Rewriter feature {feature} failed during URL transformation: {error}',
								array(
									'feature' => $feature->get_name(),
									'error'   => $e->getMessage(),
								)
							);
						}
					}

					return $transformed_url;
				}
			);
		} finally {
			unset( $processing_urls[ $re_entrancy_key ] );
		}

		// Final safety check: Ensure we always return a string
		return is_string( $result ) ? $result : $url;
	}

	// generate_cache_key() now provided by trait


	/**
	 * Add rewrite rules (delegated to coordinator)
	 * Required by Frl_Rewriter_Interface
	 *
	 * @return void
	 */
	public function add_rewrite_rules(): void {
		// Rules are automatically registered when features initialize
		// This method satisfies the interface but work is delegated to features
		// To manually refresh rules, use force_rules_refresh() instead
	}

	/**
	 * Get the coordinator instance (for advanced usage)
	 *
	 * @return Frl_Rewriter_Coordinator The coordinator instance
	 */
	public function get_coordinator(): Frl_Rewriter_Coordinator {
		return $this->coordinator;
	}

	/**
	 * Check if any features are enabled
	 *
	 * @return bool True if any features are enabled
	 */
	public function has_any_features_enabled(): bool {
		return ! empty( $this->coordinator->get_enabled_features() );
	}

	/**
	 * Pre-populate permalink cache for ACF relationship objects.
	 *
	 * Calling get_permalink() for each post is sufficient: it fires the post_type_link
	 * filter which invokes transform_url(), which caches the transformed result via
	 * frl_cache_remember. No explicit transform_url() call is needed here — doing so
	 * would transform an already-transformed URL a second time, corrupting the cache.
	 *
	 * @param array $posts Array of WP_Post objects from ACF relationship fields
	 * @return void
	 */
	public function warm_cache_for_posts( array $posts ): void {
		if ( empty( $posts ) ) {
			return;
		}

		foreach ( $posts as $post ) {
			if ( ! is_object( $post ) || ! isset( $post->ID ) ) {
				continue;
			}

			// get_permalink() fires post_type_link → filter_post_link() → transform_url(),
			// which internally uses frl_cache_remember to store the result. A subsequent
			// call in the same request for the same post will return the cached value.
			get_permalink( $post );
		}
	}

	/**
	 * Returns a list of CPT slugs that have multilingual configurations defined.
	 *
	 * This method is kept as a static utility for the admin UI, as it does not
	 * depend on the rewriter's runtime state.
	 *
	 * @return array A simple array of CPT slugs (e.g., ['service']).
	 */
	public static function get_multilingual_cpts(): array {
		// Cache the multilingual CPTs list for admin UI performance
		return frl_cache_remember(
			'rewriter',
			'multilingual_cpts',
			function () {
				$cpts = array();

				if ( defined( 'FRL_REWRITER_MULTILINGUAL_CPT' ) && is_array( FRL_REWRITER_MULTILINGUAL_CPT ) ) {
					foreach ( FRL_REWRITER_MULTILINGUAL_CPT as $cpt_slug ) {
						$option = frl_get_option( "rewriter_translate_cpt_slugs_{$cpt_slug}" );
						if ( ! empty( trim( (string) $option ) ) ) {
							$cpts[] = $cpt_slug;
						}
					}
				}

				return $cpts;
			}
		);
	}

	/**
	 * Invalidate rewriter caches only — no rewrite rules flush.
	 *
	 * Hooked to 'flush_rewrite_rules' so cached exclusion patterns and option-derived
	 * config hashes are cleared whenever rules are rebuilt, even when the permalink
	 * structure itself hasn't changed (e.g., no-op Save on Settings → Permalinks).
	 *
	 * @return void
	 */
	public static function invalidate_rewriter_caches(): void {
		if ( frl_is_already_running( __METHOD__ ) ) {
			return;
		}

		// Clear only 'rewriter' (cascades to 'permalinks'): the full 'options' cascade
		// would also drop 'html' fragments on every rewrite-rules flush.
		frl_cache_clear( 'rewriter' );
		frl_delete_transient( Frl_Rewriter_Path_Utils::EXCLUSION_PATTERNS_TRANSIENT );
	}

	/**
	 * Clear all rewriter-related caches including plugin option caches, then flush rules.
	 *
	 * Called when plugin settings have changed (hooked to update_option_* actions).
	 * The 'options' clear is required because feature config hashes are derived from
	 * frl_get_option() values: without clearing the option cache first, generate_rules()
	 * would produce a stale hash key and serve old cached rules even after the option
	 * was saved.
	 *
	 * For flush operations not triggered by a settings change (button press, cron,
	 * code update), use frl_flush_rewrite_rules() which fires the same WordPress
	 * core actions and achieves the same result through the hook chain.
	 *
	 * Re-entrancy guard: multiple update_option_* hooks can fire in one request
	 * when a settings page saves several fields simultaneously. The guard ensures
	 * flush_rewrite_rules() and the third-party notification run exactly once.
	 */
	public static function clear_rewriter_caches(): void {
		if ( frl_is_already_running( __METHOD__ ) ) {
			return;
		}

		// Settings changed: option-derived config hashes must refresh before invalidating.
		frl_cache_clear( 'options' );

		// Invalidate caches first so the subsequent flush_rewrite_rules() call
		// regenerates rules with fresh exclusion patterns. When flush_rewrite_rules
		// fires the 'flush_rewrite_rules' hook, invalidate_rewriter_caches() runs
		// again but its own frl_is_already_running guard blocks the duplicate work.
		self::invalidate_rewriter_caches();

		flush_rewrite_rules( true );
	}

	/**
	 * Force a full rewrite rules refresh, resetting the coordinator's in-memory
	 * config hash so the validation cache also re-evaluates.
	 *
	 * @return void
	 */
	public static function force_rules_refresh(): void {
		$coordinator = Frl_Rewriter_Coordinator::init();
		$coordinator->invalidate_config_hash();
		frl_flush_rewrite_rules();
		// Validate all features after flush to catch pattern conflicts and routing issues.
		$coordinator->validate_all_features();
	}

	/**
	 * Clear rewriter caches when a rewriter-owned option changes.
	 *
	 * Plugin options are stored prefixed (frl_rewriter_*), so a single O(1)
	 * prefix check on 'updated_option' covers remove_cpt_base, remove_tax_base,
	 * translate_post_base, and every translate_cpt_slugs_{cpt}. This replaces the
	 * previous per-option hooks, which used unprefixed names and therefore never
	 * fired for frl_-prefixed option writes.
	 *
	 * @param string $option    Option name (prefixed).
	 * @param mixed  $old_value Previous value.
	 * @param mixed  $new_value New value.
	 * @return void
	 */
	public static function maybe_clear_rewriter_caches_on_option_change( $option, $old_value, $new_value ): void {
		if ( $old_value === $new_value ) {
			return;
		}
		if ( ! str_starts_with( (string) $option, frl_prefix( 'rewriter_' ) ) ) {
			return;
		}
		self::clear_rewriter_caches();
	}

	/**
	 * Register cache invalidation hooks.
	 * Called during plugin initialization.
	 *
	 * @return void
	 */
	public static function register_cache_invalidation_hooks(): void {
		// Reentrancy protection - ensure hooks registered only once
		static $cache_hooks_registered = false;
		if ( $cache_hooks_registered ) {
			return;
		}
		$cache_hooks_registered = true;

		// Defer hook registration until 'wp_loaded' to ensure all plugins and themes are loaded
		add_action(
			'wp_loaded',
			function () {
				// Invalidate cached exclusion patterns whenever rewrite rules are flushed,
				// even when the permalink structure hasn't changed (e.g., no-op Save).
				// Uses invalidate_rewriter_caches() — cache-only, no recursive flush.
				// Covers permalink_structure, category_base, and tag_base changes too,
				// since WordPress always calls $wp_rewrite->flush_rules() after updating
				// those options on the Permalink Settings page.
				add_action( 'flush_rewrite_rules', array( self::class, 'invalidate_rewriter_caches' ), 10, 1 );

				// Any rewriter-owned option (prefixed frl_rewriter_*) invalidates the
				// rewriter caches. Post base translation affects get_post_base_mappings(),
				// taxonomy rules, and catch-all exclusions; base removals and CPT slug
				// translations likewise. One O(1) prefix test replaces the old per-option hooks.
				add_action( 'updated_option', array( self::class, 'maybe_clear_rewriter_caches_on_option_change' ), 10, 3 );

				// Invalidate cached exclusion patterns when Polylang languages are
				// added, updated, or when the default language changes. Without these
				// hooks, rewrite rules referencing removed languages or missing rules
				// for new languages persist until the next permalink structure change.
				// Language deletion is covered by the 'deleted_term' hook below
				// (Polylang calls wp_delete_term() which fires 'deleted_term').
				add_action( 'pll_add_language', array( self::class, 'clear_rewriter_caches' ), 10, 1 );
				add_action( 'pll_update_language', array( self::class, 'clear_rewriter_caches' ), 10, 1 );
				add_action( 'pll_update_default_lang', array( self::class, 'clear_rewriter_caches' ), 10, 1 );

				// Page lifecycle: top-level page slugs are part of the exclusion
				// patterns, and routing uses the flushed rewrite_rules snapshot —
				// so bump the version AND schedule a debounced flush (fires within
				// the next cron run, ~5 min max). Ordinary post edits no longer
				// invalidate anything (the old posts:last_changed key did).
				add_action(
					'post_updated',
					function ( $post_id, $post_after, $post_before ) {
						if ( ! isset( $post_after->post_type ) || 'page' !== $post_after->post_type ) {
							return;
						}
						$routing_changed = $post_after->post_name !== $post_before->post_name
							|| $post_after->post_parent !== $post_before->post_parent
							|| ( 'publish' === $post_after->post_status ) !== ( 'publish' === $post_before->post_status );
						if ( $routing_changed ) {
							Frl_Rewriter_Path_Utils::invalidate_exclusion_patterns( true );
						}
					},
					10,
					3
				);
				// Trash/untrash change publish visibility without post_updated firing.
				add_action(
					'wp_trash_post',
					function ( $post_id ) {
						if ( get_post_type( $post_id ) === 'page' ) {
							Frl_Rewriter_Path_Utils::invalidate_exclusion_patterns( true );
						}
					}
				);
				add_action(
					'untrash_post',
					function ( $post_id ) {
						if ( get_post_type( $post_id ) === 'page' ) {
							Frl_Rewriter_Path_Utils::invalidate_exclusion_patterns( true );
						}
					}
				);
				add_action(
					'before_delete_post',
					function ( $post_id ) {
						if ( get_post_type( $post_id ) === 'page' ) {
							Frl_Rewriter_Path_Utils::invalidate_exclusion_patterns( true );
						}
					}
				);
				// Term events can change language slugs (patterns include lang-prefixed
				// variants): bump the version, but no flush needed — term archives have
				// their own rules; only the exclusion alternation changes.
				add_action(
					'created_term',
					function () {
						Frl_Rewriter_Path_Utils::invalidate_exclusion_patterns();
					}
				);
				add_action(
					'deleted_term',
					function ( $term_id, $tt_id, $taxonomy ) {
						if ( $taxonomy === 'language' ) {
							// Language deletion invalidates all cached language-dependent
							// rewrite rules. A full flush is required — merely bumping
							// the exclusion version is insufficient.
							self::clear_rewriter_caches();
						} else {
							Frl_Rewriter_Path_Utils::invalidate_exclusion_patterns();
						}
					},
					10,
					3
				);
				add_action(
					'edited_term',
					function () {
						Frl_Rewriter_Path_Utils::invalidate_exclusion_patterns();
					}
				);

				// Repair absent rewrite_rules (normal WP state during any flush cycle).
				// Exponential backoff prevents log flooding on persistent failure.
				// Retry count expires after 1 hour to allow recovery after temporary issues.
				$retry_count = (int) frl_get_transient( 'rewrite_flush_retry_count' ) ?: 0;
				if ( $retry_count > 5 ) {
					frl_log(
						'Rewrite flush failed after 5 attempts - stopping automatic repair',
						array(
							'retry_count' => $retry_count,
						)
					);
					return; // Stop retrying until retry count expires
				}
				if ( get_option( 'rewrite_rules' ) === false && ! frl_get_transient( 'rewrite_flush_cooldown' ) ) {
					frl_set_transient( 'rewrite_flush_cooldown', true, 60 );
					frl_set_transient( 'rewrite_flush_retry_count', $retry_count + 1, HOUR_IN_SECONDS );
					frl_flush_rewrite_rules();
				}
			},
			10,
			0
		);
	}
}
