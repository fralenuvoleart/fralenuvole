<?php

/**
 * Rewrites slugs for custom post types and taxonomies using independent feature architecture
 *
 * @package FRL
 * @since 3.0.0
 */

/**
 * New Independent Architecture (Strategy 1: Priority-Based Feature Architecture)
 * ============================================================================
 *
 * Each feature operates completely independently with:
 * - Its own configuration validation
 * - Independent rewrite rule generation
 * - Isolated URL request handling
 * - Clear priority assignment
 * - No cross-feature dependencies
 *
 * Feature Priority Order:
 *   Priority 10: Post Base Translation (highest specificity)
 *   Priority 20: CPT Slug Translation
 *   Priority 30: Taxonomy Base Removal
 *   Priority 40: CPT Base Removal
 *   Priority 50+: WordPress Defaults (lowest priority)
 *
 * Benefits:
 * - 100% feature independence
 * - No pattern conflicts through priority-based resolution
 * - Easy feature addition/removal
 * - Clear debugging and maintenance
 * - Robust conflict detection
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Load dependencies
require_once __DIR__ . '/interface-rewriter.php';
require_once __DIR__ . '/class-rewriter-coordinator.php';
require_once __DIR__ . '/class-rewriter-config-validator.php';
require_once __DIR__ . '/trait-cache-key-generator.php';

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
    /**
     * Cache enabled features list to avoid repeated array_filter calls on every URL.
     */
    private array $enabled_features = [];

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

    /**
     * Initializes the rewriter subsystem using the new independent feature architecture
     *
     * @return Frl_Rewriter_Interface The fully initialized rewriter instance
     */
    public static function init_with_di(): Frl_Rewriter_Interface
    {
        return self::init();
    }

    private function register_hooks(): void
    {
        if (self::$hooks_registered) {
            return;
        }

        // URL transformation hooks (for generating URLs)
        frl_hook_add('filter', 'post_link', [$this, 'filter_post_link'], 10, 2);
        frl_hook_add('filter', 'post_type_link', [$this, 'filter_post_link'], 10, 2);
        frl_hook_add('filter', 'term_link', [$this, 'filter_term_link'], 10, 3);

        // Archive and feed link hooks
        frl_hook_add('filter', 'post_type_archive_link', [$this, 'filter_generic_link'], 10, 2);
        frl_hook_add('filter', 'get_post_type_archive_link', [$this, 'filter_generic_link'], 10, 2);
        frl_hook_add('filter', 'post_type_archive_feed_link', [$this, 'filter_generic_link'], 10, 2);
        frl_hook_add('filter', 'term_feed_link', [$this, 'filter_generic_link'], 10, 3);

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
     * Generic link filter for archive and feed links
     */
    public function filter_generic_link(string $link, $object = null): string
    {
        return $this->transform_url($link, $object);
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

        // Get enabled features dynamically to ensure system state (CPTs/Mappings) is ready
        $features = $this->coordinator->get_enabled_features();
        if (empty($features)) {
            return $url;
        }

        // Re-entrancy protection: prevent recursive URL processing during ACF relationship queries
        // Use string concatenation for the key instead of md5 for maximum performance.
        static $processing_urls = [];
        $object_id = isset($object->ID) ? $object->ID : (isset($object->term_id) ? $object->term_id : 'unknown');
        $re_entrancy_key = $object_id . '_' . $url;

        if (isset($processing_urls[$re_entrancy_key])) {
            // Return cached result if available, otherwise return original URL
            $cache_key = $this->generate_cache_key($url, $object);
            $cached = frl_cache_get('permalinks', $cache_key);
            return (is_string($cached)) ? $cached : $url;
        }

        $processing_urls[$re_entrancy_key] = true;

        // Performance guard: Do not transform URLs in REST API contexts to preserve canonical URLs for clients.
        if (frl_is_rest_api_request()) {
            unset($processing_urls[$re_entrancy_key]);
            return $url;
        }

        // Performance optimization: Enhanced cache key for ACF relationship contexts
        $cache_key = $this->generate_cache_key($url, $object);

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
     * Pre-populate cache for ACF relationship objects to optimize performance.
     * This method can be called in templates to warm the cache for known relationship objects.
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

            // Generate the URL that WordPress would normally generate
            $original_url = get_permalink($post);
            if (!$original_url) {
                continue;
            }

            // Check if already cached
            $cache_key = $this->generate_cache_key($original_url, $post);
            $cached_value = frl_cache_get('permalinks', $cache_key);
            if ($cached_value !== false && $cached_value !== null) {
                continue; // Already cached
            }

            // Pre-transform and cache the URL
            $this->transform_url($original_url, $post);
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

        // Clear options cache to refresh configuration
        frl_cache_clear('options');

        // Also clear any transients related to rewriter
        frl_delete_transient('rewrite_rules');

        // Force WordPress to regenerate rewrite rules
        flush_rewrite_rules(false);

        // Log cache clear for monitoring

    }

    /**
     * Force rewrite rules refresh for the new independent feature architecture
     */
    public static function force_rules_refresh(): void
    {
        $coordinator = Frl_Rewriter_Coordinator::init();
        $coordinator->force_refresh();
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
        frl_hook_add(
            'action',
            'wp_loaded',
            function () {
                frl_hook_add('action', 'update_option_permalink_structure', [self::class, 'clear_rewriter_caches'], 10, 1, 'core', false);
                frl_hook_add('action', 'update_option_category_base', [self::class, 'clear_rewriter_caches'], 10, 1, 'core', false);
                frl_hook_add('action', 'update_option_tag_base', [self::class, 'clear_rewriter_caches'], 10, 1, 'core', false);

                // Hooks for rewriter option changes (cache invalidation)
                frl_hook_add('action', 'update_option_translate_post_base', [self::class, 'clear_rewriter_caches'], 10, 1, 'core', false);
                frl_hook_add('action', 'update_option_remove_cpt_base', [self::class, 'clear_rewriter_caches'], 10, 1, 'core', false);
                frl_hook_add('action', 'update_option_remove_tax_base', [self::class, 'clear_rewriter_caches'], 10, 1, 'core', false);
                frl_hook_add('action', 'update_option_integrate_cpt_with_blog', [self::class, 'clear_rewriter_caches'], 10, 1, 'core', false);

                // Hook CPT translation option changes
                if (defined('FRL_REWRITER_MULTILINGUAL_CPT') && is_array(FRL_REWRITER_MULTILINGUAL_CPT)) {
                    foreach (FRL_REWRITER_MULTILINGUAL_CPT as $cpt_slug) {
                        frl_hook_add('action', "update_option_translate_cpt_slugs_{$cpt_slug}", [self::class, 'clear_rewriter_caches'], 10, 1, 'core', false);
                    }
                }

                // Additional check for flushed rules
                if (get_option('rewrite_rules') === false) {
                    self::clear_rewriter_caches();
                }
            },
            10,
            1,
            'core',
            false
        );
    }
}
