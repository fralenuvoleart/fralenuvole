<?php
/**
 * Rewriter Configuration Validator
 *
 * Provides admin-only validation warnings for potential configuration conflicts.
 * DOES NOT affect URL generation or resolution - purely diagnostic.
 *
 * @package Fralenuvole
 * @since 3.0.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Validates rewriter configuration for potential conflicts
 *
 * This class only provides warnings and diagnostics.
 * It never blocks functionality or changes URL behavior.
 */
class Frl_Rewriter_Config_Validator {

    /**
     * Get all configuration validation warnings
     *
     * @return array Array of warning messages
     */
    public static function get_validation_warnings(): array {
        $warnings = [];

        // Only run in admin context
        if (!is_admin()) {
            return $warnings;
        }

        $warnings = array_merge($warnings, self::check_duplicate_translations());
        $warnings = array_merge($warnings, self::check_semantic_overlaps());
        $warnings = array_merge($warnings, self::check_language_conflicts());
        $warnings = array_merge($warnings, self::warn_missing_cpt_mappings_when_post_base_set());
        $warnings = array_merge($warnings, self::warn_when_top_level_pages_exceed_cap());

        return $warnings;
    }

    /**
     * Warn when translate_post_base is configured but corresponding CPT mappings are missing,
     * which often leads to 404 expectations like /lang/base/<cpt-slug>/.
     */
    private static function warn_missing_cpt_mappings_when_post_base_set(): array {
        $warnings = [];
        $post_config = (string) frl_get_option('translate_post_base');
        if (empty(trim($post_config))) {
            return $warnings;
        }

        if (!defined('FRL_REWRITER_MULTILINGUAL_CPT') || !is_array(FRL_REWRITER_MULTILINGUAL_CPT)) {
            return $warnings;
        }

        foreach (FRL_REWRITER_MULTILINGUAL_CPT as $cpt_slug) {
            $cpt_cfg = (string) frl_get_option("translate_cpt_slugs_{$cpt_slug}");
            if (empty(trim($cpt_cfg))) {
                $warnings[] = "CPT '{$cpt_slug}' has no translations set (translate_cpt_slugs_{$cpt_slug}) while post base translation is enabled. URLs like /lang/base/<{$cpt_slug}-slug>/ will not resolve.";
            }
        }

        return $warnings;
    }

    /**
     * Warn when the number of top-level pages exceeds the configured cap used in exclusion generation.
     * This is admin-only and does not change runtime behaviour.
     */
    private static function warn_when_top_level_pages_exceed_cap(): array {
        $warnings = [];
        if (!is_admin()) {
            return $warnings;
        }

        $cap = defined('FRL_REWRITER_PAGE_TOPLEVEL_CAP') ? (int) constant('FRL_REWRITER_PAGE_TOPLEVEL_CAP') : 500;

        // Get the total count of top-level published pages, cached to avoid repeated queries
        $total = (int) frl_cache_remember('rewriter', 'top_level_pages_total', function () {
            if (!class_exists('WP_Query')) {
                return 0;
            }
            $q = new \WP_Query([
                'post_type'      => 'page',
                'post_status'    => 'publish',
                'post_parent'    => 0,
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'no_found_rows'  => false,
            ]);
            $total = (int) ($q->found_posts ?? 0);
            wp_reset_postdata();
            return $total;
        });

        if ($total > $cap) {
            $warnings[] = sprintf(
                'Top-level pages %d exceed the configured cap (%d). Catch-all exclusion alternation may grow large; consider reducing top-level pages, increasing object-cache persistence, or adjusting FRL_REWRITER_PAGE_TOPLEVEL_CAP.',
                $total,
                $cap
            );
        }

        return $warnings;
    }

    /**
     * Check for duplicate translated slugs across features
     */
    private static function check_duplicate_translations(): array {
        $warnings = [];
        $all_translations = [];

        // Collect post base translations
        $post_config = frl_get_option('translate_post_base');
        if (!empty($post_config)) {
            $post_mappings = frl_textlist_to_array($post_config);
            foreach ($post_mappings as $mapping) {
                if (count($mapping) >= 2) {
                    $lang = $mapping[0];
                    $slug = $mapping[1];
                    $all_translations[$lang][$slug][] = 'Post Base Translation';
                }
            }
        }

        // Collect CPT translations
        if (defined('FRL_REWRITER_MULTILINGUAL_CPT') && is_array(FRL_REWRITER_MULTILINGUAL_CPT)) {
            foreach (FRL_REWRITER_MULTILINGUAL_CPT as $cpt_slug) {
                $cpt_config = frl_get_option("translate_cpt_slugs_{$cpt_slug}");
                if (!empty($cpt_config)) {
                    $cpt_mappings = frl_textlist_to_array($cpt_config);
                    foreach ($cpt_mappings as $mapping) {
                        if (count($mapping) >= 2) {
                            $lang = $mapping[0];
                            $slug = $mapping[1];
                            $all_translations[$lang][$slug][] = "CPT Translation ({$cpt_slug})";
                        }
                    }
                }
            }
        }

        // Check for duplicates
        foreach ($all_translations as $lang => $slugs) {
            foreach ($slugs as $slug => $features) {
                if (count($features) > 1) {
                    $feature_list = implode(', ', $features);
                    $warnings[] = "Duplicate translation slug '{$slug}' for language '{$lang}' used by: {$feature_list}. This may cause URL conflicts.";
                }
            }
        }

        return $warnings;
    }

    /**
     * Check for potential semantic overlaps
     */
    private static function check_semantic_overlaps(): array {
        $warnings = [];

        // Check if category names match CPT slugs
        $remove_tax_base = frl_get_option('remove_tax_base');
        if (str_contains($remove_tax_base, 'category')) {
            $categories = get_categories(['hide_empty' => false]);

            if (defined('FRL_REWRITER_MULTILINGUAL_CPT') && is_array(FRL_REWRITER_MULTILINGUAL_CPT)) {
                foreach (FRL_REWRITER_MULTILINGUAL_CPT as $cpt_slug) {
                    foreach ($categories as $category) {
                        if ($category->slug === $cpt_slug) {
                            $warnings[] = "Category slug '{$category->slug}' matches CPT slug '{$cpt_slug}'. This may cause semantic URL conflicts.";
                        }
                    }
                }
            }
        }

        return $warnings;
    }

    /**
     * Check for language code conflicts with actual slugs
     */
    private static function check_language_conflicts(): array {
        $warnings = [];

        $active_languages = Frl_Rewriter_Path_Utils::get_active_languages_safe();

        // Check if any language codes match category slugs
        $categories = get_categories(['hide_empty' => false]);
        foreach ($categories as $category) {
            if (in_array($category->slug, $active_languages, true)) {
                $warnings[] = "Category slug '{$category->slug}' matches language code. This may cause URL parsing conflicts.";
            }
        }

        // Check if any language codes match CPT slugs
        if (defined('FRL_REWRITER_MULTILINGUAL_CPT') && is_array(FRL_REWRITER_MULTILINGUAL_CPT)) {
            foreach (FRL_REWRITER_MULTILINGUAL_CPT as $cpt_slug) {
                if (in_array($cpt_slug, $active_languages, true)) {
                    $warnings[] = "CPT slug '{$cpt_slug}' matches language code. This may cause URL parsing conflicts.";
                }
            }
        }

        return $warnings;
    }

    /**
     * Display validation warnings in admin
     */
    public static function display_admin_warnings(): void {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        $warnings = self::get_validation_warnings();

        if (!empty($warnings)) {
            echo '<div class="notice notice-warning"><div>
            <p><strong>Rewriter Configuration Warnings:</strong></p>';
            foreach ($warnings as $warning) {
                echo '<p>' . esc_html($warning) . '</p>';
            }
            echo '<p><em>These are diagnostic warnings only. Your URLs will continue to work normally.</em></p>
            </div></div>';
        }
    }
}

// Hook to display warnings in admin (only where relevant)
if (is_admin()) {
    add_action('admin_notices', [Frl_Rewriter_Config_Validator::class, 'display_admin_warnings'], 10, 0);
}
