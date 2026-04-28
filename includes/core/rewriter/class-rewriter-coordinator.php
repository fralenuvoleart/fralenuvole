<?php

/**
 * Rewriter Coordinator - Manages all independent rewriter features
 *
 * @package Fralenuvole
 * @since 3.0.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Load the feature configuration
require_once FRL_DIR_PATH . 'config/config-rewriter.php';

// Autoload all feature classes
foreach (glob(__DIR__ . '/features/*.php') as $feature_file) {
    require_once $feature_file;
}

/**
 * Central coordinator for all rewriter features
 *
 * Ensures features operate independently while handling:
 * - Feature registration in priority order
 * - Conflict detection and resolution
 * - Catch-all rule generation with proper exclusions
 * - Request routing to appropriate features
 *
 * @package Fralenuvole
 * @since 3.0.0
 */
class Frl_Rewriter_Coordinator
{

    private array $features = [];

    private static ?self $instance = null;

    /** Computed lazily on first access, after init/20 config loaders have run. */
    private ?string $config_hash = null;

    /**
     * Private constructor - use init() to get instance
     *
     * @return void
     */
    private function __construct()
    {
        $this->register_features();
        $this->register_hooks();
    }

    /**
     * Get the singleton instance
     *
     * @return self The coordinator instance
     */
    public static function init(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Register all features with the coordinator
     *
     * Creates features, allows external registration via hook,
     * and sorts features by priority.
     *
     * @return void
     */
    private function register_features(): void
    {
        // Create and self-register all features first
        $this->create_all_features();

        // Allow external features to self-register via hook
        do_action('frl_rewriter_register_features', $this);

        // Sort features by priority
        usort($this->features, function ($a, $b) {
            return $a->get_priority() <=> $b->get_priority();
        });
        // Config hash is computed lazily on first access (after init/20 config loaders run).
    }

    /**
     * Add a feature to the coordinator (used by self-registering features)
     *
     * @param Frl_Rewriter_Feature_Base $feature The feature to add
     * @return void
     */
    public function add_feature(Frl_Rewriter_Feature_Base $feature): void
    {
        $this->features[] = $feature;
    }

    /**
     * Dynamically create and self-register all features from the configuration.
     * Includes safety nets for robust error handling.
     *
     * @return void
     */
    private function create_all_features(): void
    {
        // Safety net: Check if configuration exists
        if (!defined('FRL_REWRITER_FEATURES') || !is_array(FRL_REWRITER_FEATURES)) {
            return;
        }

        foreach (FRL_REWRITER_FEATURES as $feature_class) {
            try {
                // Safety net: Check if class exists
                if (!class_exists($feature_class)) {
                    continue;
                }

                (new $feature_class())->self_register();
            } catch (Throwable $e) {
                // Safety net: Continue if feature registration fails (catches Exception and Error)
                frl_log('Rewriter feature registration failed: {class} - {error}', [
                    'class' => $feature_class,
                    'error' => $e->getMessage()
                ]);
                continue;
            }
        }

        // CPT translation features: instantiate Archive + Single features per CPT slug.
        if (defined('FRL_REWRITER_MULTILINGUAL_CPT') && is_array(FRL_REWRITER_MULTILINGUAL_CPT)) {
            $feature_classes = [
                Frl_CPT_Archive_Base_Translation_Feature::class,
                Frl_CPT_Single_Base_Translation_Feature::class,
            ];
            foreach (FRL_REWRITER_MULTILINGUAL_CPT as $cpt_slug) {
                foreach ($feature_classes as $feature_class) {
                    try {
                        if (!class_exists($feature_class)) {
                            continue;
                        }
                        // Instantiate with the CPT slug as constructor argument
                        (new $feature_class($cpt_slug))->self_register();
                    } catch (Throwable $e) {
                        frl_log('Rewriter factory feature registration failed: {class}({cpt}) - {error}', [
                            'class' => $feature_class,
                            'cpt' => $cpt_slug,
                            'error' => $e->getMessage(),
                        ]);
                        continue;
                    }
                }
            }
        }
    }

    /**
     * Register WordPress hooks for feature registration
     *
     * @return void
     */
    private function register_hooks(): void
    {
        // Delay feature registration until after CPTs are registered (on init)
        add_action('init', function () {
            foreach ($this->features as $feature) {
                $feature->register();
            }
        }, 15, 0);
    }

    /**
     * Return the config hash, computing it on first call (after init/20 config loaders have run).
     * This ensures features that load their enabled-state from the DB are correctly included.
     *
     * @return string The computed config hash
     */
    private function get_config_hash(): string
    {
        if ($this->config_hash === null) {
            $this->config_hash = $this->generate_config_hash();
        }
        return $this->config_hash;
    }

    /**
     * Validate that all features have non-conflicting patterns
     *
     * @return bool True if validation passes, false otherwise
     */
    public function validate_all_features(): bool
    {
        // Cache validation results based on configuration hash
        return frl_cache_remember('rewriter', 'features_validation_' . $this->get_config_hash(), function () {
            $all_patterns = [];

            foreach ($this->features as $feature) {
                if (!$feature->is_enabled()) {
                    continue;
                }

                try {
                    $feature->validate_patterns(array_keys($all_patterns));
                    $feature_patterns = $feature->generate_rules();

                    // Validate routing: each pattern should produce valid query vars
                    foreach (array_keys($feature_patterns) as $pattern) {
                        $test_uri = $this->generate_test_uri_from_pattern($pattern);
                        if ($test_uri !== null) {
                            $vars = $feature->resolve_request($test_uri);
                            if (empty($vars)) {
                                frl_log(
                                    'Routing validation failed for feature {feature}: pattern `{pattern}` produces empty query vars for test URI `{uri}`',
                                    ['feature' => $feature->get_name(), 'pattern' => $pattern, 'uri' => $test_uri]
                                );
                                throw new Exception(
                                    'Routing validation failed: pattern `' . $pattern . '` produces empty query vars'
                                );
                            }
                        }
                    }

                    $all_patterns = array_merge($all_patterns, $feature_patterns);
                } catch (Throwable $e) {
                    // Catches both Exception and PHP Error (TypeError, ArgumentCountError, etc.)
                    frl_log('Feature validation error: {error}', ['error' => $e->getMessage()]);
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * Get all registered features
     *
     * @return array Array of feature instances
     */
    public function get_features(): array
    {
        return $this->features;
    }

    /**
     * Get all enabled features
     *
     * @return array Array of enabled feature instances
     */
    public function get_enabled_features(): array
    {
        return array_filter($this->features, function ($feature) {
            return $feature->is_enabled();
        });
    }

    /**
     * Force a complete rewrite rules refresh.
     *
     * Resets the in-memory config hash so the next validate_all_features() call
     * recomputes with current option values, then delegates to
     * Frl_Rewriter::flush_rules(true) which clears the 'permalinks' and 'rewriter'
     * cache groups, deletes the exclusion-patterns transient, and calls
     * flush_rewrite_rules(true) to also rewrite .htaccess.
     *
     * Uses flush_rules() rather than clear_rewriter_caches() because this is not
     * triggered by a settings change — option caches are still valid and must not
     * be cleared (which would delete WP's alloptions from object cache and create
     * a race window for concurrent requests). Kept public for external callers.
     *
     * @return void
     */
    public function force_refresh(): void
    {
        $this->config_hash = null;
        if (class_exists('Frl_Rewriter')) {
            Frl_Rewriter::flush_rules(true);
        } else {
            flush_rewrite_rules(true);
        }
    }

    /**
     * Invalidate the in-memory config hash so the next validate_all_features() call
     * recomputes with the current option values. Used by force_rules_refresh() which
     * delegates the full cache + WP-rules purge to clear_rewriter_caches().
     *
     * @return void
     */
    public function invalidate_config_hash(): void
    {
        $this->config_hash = null;
    }

    /**
     * Generate unique hash of feature configurations.
     *
     * Includes the actual option values (not just enabled/priority) so the
     * validate_all_features() cache invalidates when configuration changes —
     * e.g., when CPT slugs are added to remove_cpt_base without toggling any feature.
     *
     * @return string MD5 hash of configuration data
     */
    /**
     * Generate unique hash of feature configurations.
     *
     * Includes the actual option values (not just enabled/priority) so the
     * validate_all_features() cache invalidates when configuration changes.
     *
     * @return string MD5 hash of configuration data
     */
    private function generate_config_hash(): string
    {
        $config_data = [];

        foreach ($this->features as $feature) {
            $config_data[] = [
                'name'     => $feature->get_name(),
                'enabled'  => $feature->is_enabled(),
                'priority' => $feature->get_priority(),
            ];
        }

        // Include the option values that drive feature behaviour.
        $config_data['options'] = [
            'remove_cpt_base'     => frl_get_option('remove_cpt_base'),
            'remove_tax_base'     => frl_get_option('remove_tax_base'),
            'translate_post_base' => frl_get_option('translate_post_base'),
        ];

        if (defined('FRL_REWRITER_MULTILINGUAL_CPT') && is_array(FRL_REWRITER_MULTILINGUAL_CPT)) {
            foreach (FRL_REWRITER_MULTILINGUAL_CPT as $cpt_slug) {
                $config_data['options']['translate_cpt_slugs_' . $cpt_slug] = frl_get_option('translate_cpt_slugs_' . $cpt_slug);
            }
        }

        return md5(json_encode($config_data, JSON_THROW_ON_ERROR));
    }

    /**
     * Generate a test URI from a rewrite pattern for routing validation.
     *
     * Converts regex patterns like `^(?:en|it)/news/(.+?)/?$` to test URIs like `en/news/test-slug`.
     * Returns null if the pattern cannot be converted to a valid test URI.
     *
     * @param string $pattern The rewrite pattern (regex)
     * @return string|null Test URI or null if conversion fails
     */
    private function generate_test_uri_from_pattern(string $pattern): ?string
    {
        $test_uri = $pattern;

        // Remove regex anchors
        $test_uri = preg_replace('#^\\(\\?:#', '', $test_uri);
        $test_uri = preg_replace('#\\)\\?\\$#', '', $test_uri);
        $test_uri = preg_replace('#\\^#', '', $test_uri);
        $test_uri = preg_replace('#\\?\\$#', '', $test_uri);

        // Replace language alternation patterns like (?:en|it) with first option
        $test_uri = preg_replace('#\\(\\?:([^|]+)\\|[^)]+\\)#', '$1', $test_uri);

        // Replace non-capturing groups with sample values
        $test_uri = preg_replace('#\\(\\?:[^)]+\\)#', 'test', $test_uri);

        // Replace captured groups with sample slug values
        $test_uri = preg_replace('#\\(\\.[^)]+\\)\\)#', 'test)', $test_uri);
        $test_uri = preg_replace('#\\.\\+#', 'test-slug', $test_uri);
        $test_uri = preg_replace('#\\(\\.[^)]+\\)#', 'test', $test_uri);

        // Handle numeric quantifiers like (?:([^/]+)/?)
        $test_uri = preg_replace('#\\d\\+#', '1', $test_uri);

        // Clean up remaining regex escaping
        $test_uri = str_replace(['/', '\/'], '/', $test_uri);
        $test_uri = trim($test_uri, '/');

        // If we have something reasonable, return it
        if (!empty($test_uri) && strlen($test_uri) > 2 && strpos($test_uri, '(') === false) {
            return $test_uri;
        }

        return null;
    }
}