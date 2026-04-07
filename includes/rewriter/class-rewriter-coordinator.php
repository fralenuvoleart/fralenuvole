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

    private string $config_hash = '';

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

        // Generate config hash for change detection
        $this->config_hash = $this->generate_config_hash();
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
            } catch (Exception $e) {
                // Safety net: Continue if feature registration fails
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
                } catch (Exception $e) {
                    // Safety net: Continue if CPT feature registration fails
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
     * Validate that all features have non-conflicting patterns
     */
    public function validate_all_features(): bool
    {
        // Cache validation results based on configuration hash
        return frl_cache_remember('rewriter', "features_validation_{$this->config_hash}", function () {
            $all_patterns = [];

            foreach ($this->features as $feature) {
                if (!$feature->is_enabled()) {
                    continue;
                }

                try {
                    $feature->validate_patterns(array_keys($all_patterns));
                    $feature_patterns = $feature->generate_rules();
                    $all_patterns = array_merge($all_patterns, $feature_patterns);
                } catch (Exception $e) {

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
     * Force a complete rewrite rules refresh
     */
    public function force_refresh(): void
    {
        delete_option('rewrite_rules');
        flush_rewrite_rules(false);
    }

    /**
     * Generate unique hash of feature configurations
     */
    private function generate_config_hash(): string
    {
        $config_data = [];

        foreach ($this->features as $feature) {
            $config_data[] = [
                'name' => $feature->get_name(),
                'enabled' => $feature->is_enabled(),
                'priority' => $feature->get_priority()
            ];
        }

        return md5(json_encode($config_data, JSON_THROW_ON_ERROR));
    }
}
