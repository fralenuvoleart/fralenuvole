<?php

/**
 * Rewriter Coordinator - Manages all independent rewriter features
 *
 * @package FRL
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
 */
class Frl_Rewriter_Coordinator
{

    private array $features = [];

    private static ?self $instance = null;

    /** Computed lazily on first access, after init/20 config loaders have run. */
    private ?string $config_hash = null;

    private function __construct()
    {
        $this->register_features();
        $this->register_hooks();
    }

    public static function init(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

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
     */
    public function add_feature(Frl_Rewriter_Feature_Base $feature): void
    {
        $this->features[] = $feature;
    }

    /**
     * Dynamically create and self-register all features from the configuration.
     * Includes safety nets for robust error handling.
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

        // Special handling for CPT features that require constructor arguments
        if (defined('FRL_REWRITER_MULTILINGUAL_CPT') && is_array(FRL_REWRITER_MULTILINGUAL_CPT)) {
            foreach (FRL_REWRITER_MULTILINGUAL_CPT as $cpt_slug) {
                try {
                    // Create features; each will verify post_type_exists() during init.
                    (new Frl_CPT_Archive_Base_Translation_Feature($cpt_slug))->self_register();
                    (new Frl_CPT_Single_Base_Translation_Feature($cpt_slug))->self_register();
                } catch (Throwable $e) {
                    // Safety net: Continue if CPT feature registration fails (catches Exception and Error)
                    frl_log('Rewriter CPT feature registration failed: {cpt_slug} - {error}', [
                        'cpt_slug' => $cpt_slug,
                        'error' => $e->getMessage()
                    ]);
                    continue;
                }
            }
        }
    }

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
     */
    public function validate_all_features(): bool
    {
        // Cache validation results based on configuration hash
        return frl_cache_remember('rewriter', "features_validation_{$this->get_config_hash()}", function () {
            $all_patterns = [];

            foreach ($this->features as $feature) {
                if (!$feature->is_enabled()) {
                    continue;
                }

                try {
                    $feature->validate_patterns(array_keys($all_patterns));
                    $feature_patterns = $feature->generate_rules();
                    $all_patterns = array_merge($all_patterns, $feature_patterns);
                } catch (Throwable $e) {
                    // Catches both Exception and PHP Error (TypeError, ArgumentCountError, etc.)
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * Get all registered features
     */
    public function get_features(): array
    {
        return $this->features;
    }

    public function get_enabled_features(): array
    {
        return array_filter($this->features, function ($feature) {
            return $feature->is_enabled();
        });
    }

    /**
     * Force a complete rewrite rules refresh.
     *
     * Delegates to Frl_Rewriter::clear_rewriter_caches() which performs the full
     * consistent purge: both cache groups, the exclusion-patterns DB transient, and
     * flush_rewrite_rules(). The in-memory config hash is also reset so the next
     * validate_all_features() call recomputes with fresh option values.
     *
     * The previous implementation called delete_option + flush_rewrite_rules directly,
     * bypassing all frl_cache_clear() calls and leaving persistent caches stale.
     * Kept public for backwards compatibility with any external callers.
     */
    public function force_refresh(): void
    {
        $this->config_hash = null;
        if (class_exists('Frl_Rewriter')) {
            Frl_Rewriter::clear_rewriter_caches();
        } else {
            // Fallback if called before Frl_Rewriter is loaded (should not happen in normal use).
            delete_option('rewrite_rules');
            flush_rewrite_rules(false);
        }
    }

    /**
     * Invalidate the in-memory config hash so the next validate_all_features() call
     * recomputes with the current option values. Used by force_rules_refresh() which
     * delegates the full cache + WP-rules purge to clear_rewriter_caches().
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
                $config_data['options']["translate_cpt_slugs_{$cpt_slug}"] = frl_get_option("translate_cpt_slugs_{$cpt_slug}");
            }
        }

        return md5(json_encode($config_data, JSON_THROW_ON_ERROR));
    }
}
