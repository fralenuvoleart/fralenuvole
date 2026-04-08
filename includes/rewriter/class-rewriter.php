<?php

/**
 * Rewrites slugs for custom post types and taxonomies using independent feature architecture
 *
 * @package FRL
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
if (!defined('ABSPATH')) {
    exit;
}

// Load dependencies
require_once __DIR__ . '/interface-rewriter.php';
require_once __DIR__ . '/class-rewriter-coordinator.php';
require_once __DIR__ . '/trait-cache-key-generator.php';

// Config validator is admin-only: heavy class with no frontend value.
if (is_admin()) {
    require_once __DIR__ . '/class-rewriter-config-validator.php';
}

/**
 * Main Rewriter class using independent feature architecture.
 *
 * This class acts as a facade for the coordinator-based system, which now
 * handles both incoming request parsing and outgoing URL transformation.
 */
final class Frl_Rewriter implements Frl_Rewriter_Interface
{
    use Frl_Rewriter_Cache_Key_Trait;
    private Frl_Rewriter_Coordinator $coordinator;

    private static ?self $instance = null;
    private static bool $hooks_registered = false;

    private function __construct()
    {
        $this->coordinator = Frl_Rewriter_Coordinator::init();
        $this->register_hooks();
        // Validation and feature caching are deferred to runtime to ensure CPTs and mappings are loaded.
    }

    public static function init(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function register_hooks(): void
    {
        if (self::$hooks_registered) {
            return;
        }

        // URL transformation hooks (for generating URLs)
        add_filter('post_type_link', [$this, 'filter_post_link'], 10, 2);
        add_filter('term_link', [$this, 'filter_term_link'], 10, 3);

        // Wire cache invalidation so changes to permalink options flush rewriter caches.
        self::register_cache_invalidation_hooks();

        self::$hooks_registered = true;
    }


    public function filter_post_link(string $link, $post): string
    {
        return $this->transform_url($link, $post);
    }

    public function filter_term_link(string $link, $term, string $taxonomy = ''): string
    {
        if (is_int($term)) {
            $term = get_term($term, $taxonomy);
        }
        if (!is_object($term) || is_wp_error($term)) {
            return $link;
        }
        return $this->transform_url($link, $term);
    }

    /**
     * Central URL transformation logic.
     *
     * Iterates through features in priority order and applies ALL
     * matching transformations (composition). Caches the result.
     */
    private function transform_url(string $url, $object): string
    {
        // Early exit if input is invalid
        if (!is_object($object)) {
            return $url;
        }

        // REST API guard must come BEFORE the cache check.
        // URL transformation is disabled for REST to preserve canonical, untransformed URLs for
        // API clients. Placing this first ensures REST requests NEVER read a cached transformed
        // URL that was stored by a prior frontend request — guaranteeing consistent REST output
        // regardless of cache state.
        if (frl_is_rest_api_request()) {
            return $url;
        }

        // Fast-path cache check: generate the key and return immediately on hit.
        // This eliminates the get_enabled_features() array_filter call on every
        // post_type_link / term_link filter invocation when the result is already cached.
        $cache_key = $this->generate_cache_key($url, $object);
        $cached = frl_cache_get('permalinks', $cache_key);
        if (is_string($cached)) {
            return $cached;
        }

        // Re-entrancy protection: prevent recursive URL processing during ACF relationship queries.
        // Use string concatenation for the key instead of md5 for maximum performance.
        static $processing_urls = [];
        $object_id = isset($object->ID) ? $object->ID : (isset($object->term_id) ? $object->term_id : 'unknown');
        $re_entrancy_key = $object_id . '_' . $url;

        if (isset($processing_urls[$re_entrancy_key])) {
            // Cache was already checked above and was a miss; no result available yet.
            return $url;
        }

        $processing_urls[$re_entrancy_key] = true;

        // Get enabled features (only reached on cache miss — avoids array_filter overhead on hits).
        $features = $this->coordinator->get_enabled_features();
        if (empty($features)) {
            unset($processing_urls[$re_entrancy_key]);
            return $url;
        }

        // cache_key already generated above.

        $result = frl_cache_remember('permalinks', $cache_key, function () use ($url, $object, $features) {
            $transformed_url = $url;

            // Dispatcher cache: map object signature -> applicable features
            static $feature_match_cache = [];
            // Build a signature that distinguishes post types and taxonomies but remains stable.
            $signature = get_class($object);
            if (isset($object->post_type)) {
                $signature .= '_' . $object->post_type;
            } elseif (isset($object->taxonomy)) {
                $signature .= '_' . $object->taxonomy;
            }

            if (isset($feature_match_cache[$signature])) {
                $applicable = $feature_match_cache[$signature];
            } else {
                $applicable = [];
                foreach ($features as $feature) {
                    if ($feature->is_enabled() && $feature->applies_to($object)) {
                        $applicable[] = $feature;
                    }
                }
                $feature_match_cache[$signature] = $applicable;
                // Memory guard – reset after large number of distinct signatures to avoid memory leaks
                if (count($feature_match_cache) > 1024) {
                    $feature_match_cache = [];
                }
            }

            // Early return if no features apply to this specific object
            if (empty($applicable)) {
                return $url;
            }

            // Apply transforms only from applicable list
            foreach ($applicable as $feature) {
                try {
                    $next_url = $feature->transform($transformed_url, $object);

                    if (is_string($next_url)) {
                        $transformed_url = $next_url;
                    }
                } catch (Throwable $e) {
                    frl_log('Rewriter feature {feature} failed during URL transformation: {error}', [
                        'feature' => $feature->get_name(),
                        'error'   => $e->getMessage(),
                    ]);
                }
            }

            return $transformed_url;
        });

        unset($processing_urls[$re_entrancy_key]);
        
        // Final safety check: Ensure we always return a string
        return is_string($result) ? $result : $url;
    }

    // generate_cache_key() now provided by trait


    /**
     * Add rewrite rules (delegated to coordinator)
     * Required by Frl_Rewriter_Interface
     */
    public function add_rewrite_rules(): void
    {
        // Rules are automatically registered when features initialize
        // This method satisfies the interface but work is delegated to features
        // To manually refresh rules, use force_rules_refresh() instead

    }

    /**
     * Get the coordinator instance (for advanced usage)
     */
    public function get_coordinator(): Frl_Rewriter_Coordinator
    {
        return $this->coordinator;
    }

    /**
     * Check if any features are enabled
     */
    public function has_any_features_enabled(): bool
    {
        return !empty($this->coordinator->get_enabled_features());
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
    public function warm_cache_for_posts(array $posts): void
    {
        if (empty($posts)) {
            return;
        }

        foreach ($posts as $post) {
            if (!is_object($post) || !isset($post->ID)) {
                continue;
            }

            // get_permalink() fires post_type_link → filter_post_link() → transform_url(),
            // which internally uses frl_cache_remember to store the result. A subsequent
            // call in the same request for the same post will return the cached value.
            get_permalink($post);
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
    public static function get_multilingual_cpts(): array
    {
        // Cache the multilingual CPTs list for admin UI performance
        return frl_cache_remember('rewriter', 'multilingual_cpts', function () {
            $cpts = [];

            if (defined('FRL_REWRITER_MULTILINGUAL_CPT') && is_array(FRL_REWRITER_MULTILINGUAL_CPT)) {
                foreach (FRL_REWRITER_MULTILINGUAL_CPT as $cpt_slug) {
                    $option = frl_get_option("translate_cpt_slugs_{$cpt_slug}");
                    if (!empty(trim((string)$option))) {
                        $cpts[] = $cpt_slug;
                    }
                }
            }

            return $cpts;
        });
    }

    /**
     * Clear rewriter caches when permalink structure changes.
     *
     * Hooked to relevant WordPress actions to ensure cache consistency.
     */
    public static function clear_rewriter_caches(): void
    {
        // Clear permalink-related caches using plugin's cache manager
        frl_cache_clear('permalinks');
        frl_cache_clear('rewriter');

        // Clear options cache to refresh configuration
        frl_cache_clear('options');

        // Delete the exclusion-patterns DB transient used on sites without persistent object cache.
        frl_delete_transient(Frl_Rewriter_Path_Utils::EXCLUSION_PATTERNS_TRANSIENT);

        // Force WordPress to regenerate rewrite rules
        flush_rewrite_rules(false);
    }

    /**
     * Force rewrite rules refresh for the new independent feature architecture.
     *
     * Delegates to clear_rewriter_caches() which performs a complete, consistent
     * purge: both cache groups, the DB transient, and flush_rewrite_rules(). The
     * previous implementation called coordinator->force_refresh() directly, which
     * only reset the in-memory config hash and flushed WP rules while leaving all
     * persistent frl_cache_remember() entries and the exclusion-patterns transient
     * stale.
     */
    public static function force_rules_refresh(): void
    {
        // Also reset coordinator's in-memory config hash so validation cache re-evaluates.
        $coordinator = Frl_Rewriter_Coordinator::init();
        $coordinator->invalidate_config_hash();

        self::clear_rewriter_caches();
    }

    /**
     * Register cache invalidation hooks.
     * Called during plugin initialization.
     */
    public static function register_cache_invalidation_hooks(): void
    {
        // Reentrancy protection - ensure hooks registered only once
        static $cache_hooks_registered = false;
        if ($cache_hooks_registered) {
            return;
        }
        $cache_hooks_registered = true;

        // Defer hook registration until 'wp_loaded' to ensure all plugins and themes are loaded
        add_action('wp_loaded', function () {
            add_action('update_option_permalink_structure', [self::class, 'clear_rewriter_caches'], 10, 1);
            add_action('update_option_category_base',       [self::class, 'clear_rewriter_caches'], 10, 1);
            add_action('update_option_tag_base',            [self::class, 'clear_rewriter_caches'], 10, 1);
            add_action('update_option_remove_cpt_base',     [self::class, 'clear_rewriter_caches'], 10, 1);
            add_action('update_option_remove_tax_base',     [self::class, 'clear_rewriter_caches'], 10, 1);
            // Post base translation affects get_post_base_mappings(), taxonomy rules, and catch-all exclusions.
            add_action('update_option_translate_post_base', [self::class, 'clear_rewriter_caches'], 10, 1);

            if (defined('FRL_REWRITER_MULTILINGUAL_CPT') && is_array(FRL_REWRITER_MULTILINGUAL_CPT)) {
                foreach (FRL_REWRITER_MULTILINGUAL_CPT as $cpt_slug) {
                    add_action("update_option_translate_cpt_slugs_{$cpt_slug}", [self::class, 'clear_rewriter_caches'], 10, 1);
                }
            }

            if (get_option('rewrite_rules') === false) {
                self::clear_rewriter_caches();
            }
        }, 10, 0);
    }
}
