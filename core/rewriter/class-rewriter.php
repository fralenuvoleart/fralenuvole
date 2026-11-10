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
		// To refresh rules manually, call frl_flush_rewrite_rules()
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
	 * Hooked to 'generate_rewrite_rules', the action WP_Rewrite::rewrite_rules() fires
	 * (do_action_ref_array, passing the WP_Rewrite instance) whenever rules are rebuilt —
	 * including the no-op Save on Settings → Permalinks. The cache calls below never call
	 * flush_rules(), so the hook cannot recurse.
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
	 * Clear rewriter caches, then flush rules once.
	 *
	 * The 'options' clear is required because feature config hashes are derived from
	 * frl_get_option() values: a stale option cache would serve old cached rules.
	 *
	 * Deferred: rules are registered at init from the old settings, so flag one flush
	 * for the next admin/cron/CLI request. Not deferred: flush at shutdown (once per
	 * request), for callers whose rules are already current (button, cron, update).
	 *
	 * @param bool $deferred True to flush from a later request.
	 */
	public static function clear_rewriter_caches( bool $deferred = false ): void {
		if ( ! frl_is_already_running( __METHOD__ ) ) {
			// Settings changed: option-derived config hashes must refresh before invalidating.
			frl_cache_clear( 'options' );

			// Invalidate first so the flush regenerates rules with fresh exclusions.
			self::invalidate_rewriter_caches();
		}

		if ( $deferred ) {
			frl_request_rewrite_flush();
			return;
		}

		// One flush per request, after every option write of a settings save.
		static $flush_scheduled = false;
		if ( $flush_scheduled ) {
			return;
		}
		$flush_scheduled = true;

		add_action(
			'shutdown',
			static function (): void {
				flush_rewrite_rules( true );
			},
			0
		);
	}

	/**
	 * Hook callback: clear caches and flush from a later request.
	 *
	 * @return void
	 */
	public static function clear_rewriter_caches_deferred(): void {
		self::clear_rewriter_caches( true );
	}

	/**
	 * Hook callback for update_option_permalink_structure.
	 *
	 * A real change flushes from a later request. frl_flush_rewrite_rules() fires the
	 * same hook with equal values and flushes directly, so here we only invalidate.
	 *
	 * @param mixed $old_value Previous structure.
	 * @param mixed $new_value New structure.
	 * @return void
	 */
	public static function on_permalink_structure_update( $old_value = null, $new_value = null ): void {
		if ( frl_rewrite_flush_is_direct() ) {
			self::invalidate_rewriter_caches();
			return;
		}
		self::clear_rewriter_caches( $old_value !== $new_value );
	}

	/**
	 * Clear rewriter caches when a rewriter-owned option changes.
	 *
	 * Plugin options are stored prefixed (frl_rewriter_*), so a single O(1)
	 * prefix check on 'updated_option' covers remove_cpt_base, remove_tax_base,
	 * translate_post_base, and every translate_cpt_slugs_{cpt}. The exact-name
	 * check covers disable_comments, which gates the single-CPT feed rules.
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
		if ( ! str_starts_with( (string) $option, frl_prefix( 'rewriter_' ) ) && frl_prefix( 'disable_comments' ) !== $option ) {
			return;
		}
		// Rules were built at init from the old option values: flush from a later request.
		self::clear_rewriter_caches( true );
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

		// A new public CPT/taxonomy base changes the exclusion lists. Compare the
		// registered-slug hash ONCE per request, after all post types and taxonomies have
		// registered (init/99) and before the rewriter features build their rules
		// (init/115+). Comparing on every registration would hash a partial registry,
		// rewriting the hash and bumping the exclusion version on every request.
		if ( did_action( 'init' ) ) {
			Frl_Rewriter_Path_Utils::refresh_registered_slugs_hash();
		} else {
			add_action( 'init', array( Frl_Rewriter_Path_Utils::class, 'refresh_registered_slugs_hash' ), 99, 0 );
		}

		// Defer hook registration until 'wp_loaded' to ensure all plugins and themes are loaded
		add_action(
			'wp_loaded',
			function () {
				// Invalidate cached exclusion patterns whenever rewrite rules are rebuilt,
				// even when the permalink structure hasn't changed (e.g. no-op Save).
				// 'generate_rewrite_rules' is the action WP_Rewrite::rewrite_rules() really
				// fires (one WP_Rewrite arg, by reference); core has no 'flush_rewrite_rules'.
				// Uses invalidate_rewriter_caches() — cache-only, no recursive flush.
				add_action( 'generate_rewrite_rules', array( self::class, 'invalidate_rewriter_caches' ), 10, 1 );

				// Real change: flush later. frl_flush_rewrite_rules() fires it directly: invalidate only.
				add_action( 'update_option_permalink_structure', array( self::class, 'on_permalink_structure_update' ), 10, 2 );

				// Any rewriter-owned option (prefixed frl_rewriter_*) invalidates the
				// rewriter caches. Post base translation affects get_post_base_mappings(),
				// taxonomy rules, and catch-all exclusions; base removals and CPT slug
				// translations likewise. One O(1) prefix test replaces the old per-option hooks.
				add_action( 'updated_option', array( self::class, 'maybe_clear_rewriter_caches_on_option_change' ), 10, 3 );

				// Invalidate cached exclusion patterns when Polylang languages are
				// added, updated, or when the default language changes. Without these
				// hooks, rewrite rules referencing removed languages or missing rules
				// for new languages persist until the next permalink structure change.
				// Language deletion is covered by the 'delete_term' hook below
				// (Polylang calls wp_delete_term(), which fires it).
				add_action( 'pll_add_language', array( self::class, 'clear_rewriter_caches_deferred' ), 10, 0 );
				add_action( 'pll_update_language', array( self::class, 'clear_rewriter_caches_deferred' ), 10, 0 );
				add_action( 'pll_update_default_lang', array( self::class, 'clear_rewriter_caches_deferred' ), 10, 0 );

				// Page lifecycle: top-level page slugs are part of the exclusion
				// patterns, and routing uses the flushed rewrite_rules snapshot —
				// so bump the version AND schedule a debounced flush (fires within
				// the next cron run, ~15 s after the save). Ordinary post edits no longer
				// invalidate anything (the old posts:last_changed key did).
				add_action(
					'post_updated',
					function ( $post_id, $post_after, $post_before ) {
						if ( ! $post_after instanceof WP_Post || ! $post_before instanceof WP_Post || 'page' !== $post_after->post_type ) {
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
				// post_updated never fires for a page created already published.
				add_action(
					'wp_insert_post',
					function ( $post_id, $post, $update ) {
						if ( $update || ! $post instanceof WP_Post || 'page' !== $post->post_type || 'publish' !== $post->post_status ) {
							return;
						}
						Frl_Rewriter_Path_Utils::invalidate_exclusion_patterns( true );
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
				// Only language terms feed the patterns (lang-prefixed variants): bump the
				// version, no flush needed. Other taxonomies' terms never appear in them.
				add_action(
					'created_term',
					function ( $term_id, $tt_id, $taxonomy ) {
						if ( 'language' === $taxonomy ) {
							Frl_Rewriter_Path_Utils::invalidate_exclusion_patterns();
						}
					},
					10,
					3
				);
				add_action(
					'delete_term',
					function ( $term_id, $tt_id, $taxonomy ) {
						if ( 'language' === $taxonomy ) {
							// Language deletion needs a full flush, not just a version bump.
							self::clear_rewriter_caches( true );
						} else {
							Frl_Rewriter_Path_Utils::invalidate_exclusion_patterns();
						}
					},
					10,
					5
				);
				add_action(
					'edited_term',
					function ( $term_id, $tt_id, $taxonomy ) {
						if ( 'language' === $taxonomy ) {
							Frl_Rewriter_Path_Utils::invalidate_exclusion_patterns();
						}
					},
					10,
					3
				);

				// Repair absent rewrite_rules (normal WP state during any flush cycle):
				// one attempt per minute, five per hour, then automatic repair pauses.
				$retry_count = (int) frl_get_transient( 'rewrite_flush_retry_count' );

				// Rules present: a previous repair worked, so drop the backoff state.
				if ( get_option( 'rewrite_rules' ) !== false ) {
					if ( $retry_count > 0 ) {
						frl_delete_transient( 'rewrite_flush_retry_count' );
					}
					return;
				}

				if ( $retry_count >= 5 ) {
					frl_log(
						'Rewrite rules still absent after {count} repair attempts in the last hour - pausing automatic repair',
						array( 'count' => $retry_count )
					);
					return;
				}

				if ( ! frl_get_transient( 'rewrite_flush_cooldown' ) ) {
					frl_set_transient( 'rewrite_flush_cooldown', true, 60 );
					frl_flush_rewrite_rules();

					// A repair that stored rules worked: reset the counter, otherwise back off.
					if ( get_option( 'rewrite_rules' ) !== false ) {
						frl_delete_transient( 'rewrite_flush_retry_count' );
					} else {
						frl_set_transient( 'rewrite_flush_retry_count', $retry_count + 1, HOUR_IN_SECONDS );
					}
				}
			},
			10,
			0
		);
	}
}
