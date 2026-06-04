<?php
/**
 * Polylang Translation Adapter
 *
 * Implements the Frl_Translation_Adapter_Interface for Polylang.
 * All fallback logic is self-contained — the adapter knows its own
 * plugin's database schema and can provide fallbacks independently
 * of the global helper functions.
 */

if (!defined('ABSPATH')) exit;

class Frl_Polylang_Adapter implements Frl_Translation_Adapter_Interface
{
    public function get_current_language(): string
    {
        if (function_exists('pll_current_language')) {
            $lang = pll_current_language();
            if (!empty($lang)) {
                return $lang;
            }
        }
        return $this->get_default_language_internal();
    }

    public function get_default_language(): string
    {
        if (function_exists('pll_default_language')) {
            $lang = pll_default_language();
            if (!empty($lang)) {
                return $lang;
            }
        }
        return $this->get_default_language_internal();
    }

    public function get_active_languages(): array
    {
        if (function_exists('pll_languages_list')) {
            $langs = pll_languages_list(['fields' => 'slug']);
            if (!empty($langs)) {
                return $langs;
            }
        }
        return $this->get_active_languages_internal();
    }

    public function translate_string(string $string, string $language): ?string
    {
        if (function_exists('pll_translate_string')) {
            $translation = pll_translate_string($string, $language);
            return ($translation !== $string) ? $translation : null;
        }
        return null;
    }

    public function register_string(string $domain, string $identifier, string $string): void
    {
        if (function_exists('icl_register_string')) {
            icl_register_string($domain, $identifier, $string);
        }
    }

    public function get_post_translation(int $post_id, string $language)
    {
        return function_exists('pll_get_post') ? pll_get_post($post_id, $language) : false;
    }

    public function get_post_translations(int $post_id): array
    {
        if (function_exists('pll_get_post_translations')) {
            $translations = pll_get_post_translations($post_id);
            if (!empty($translations)) {
                return $translations;
            }
        }
        return [$this->get_default_language() => $post_id];
    }

    public function get_term_translations(int $term_id): array
    {
        if (function_exists('pll_get_term_translations')) {
            $translations = pll_get_term_translations($term_id);
            if (!empty($translations)) {
                return $translations;
            }
        }
        return [$this->get_default_language() => $term_id];
    }

    public function get_post_language(int $post_id): ?string
    {
        return function_exists('pll_get_post_language') ? pll_get_post_language($post_id) : null;
    }

    public function get_term_language(int $term_id): ?string
    {
        return function_exists('pll_get_term_language') ? pll_get_term_language($term_id) : null;
    }

    public function get_object_id(int $id, string $taxonomy, bool $fallback, string $language): int
    {
        // Polylang doesn't have a generic object_id like WPML,
        // so we rely on the specific translation functions.
        if (function_exists('icl_object_id')) {
            return icl_object_id($id, $taxonomy, $fallback, $language);
        }
        return $id;
    }

    // ------------------------------------------------------------------
    // Internal Fallback Methods
    // ------------------------------------------------------------------

    /**
     * Internal fallback: read default language from Polylang options.
     * Used when Polylang API is not yet initialized (early AJAX, CLI, cron).
     *
     * @return string 2-letter language code
     */
    private function get_default_language_internal(): string
    {
        $pll_options = get_option('polylang');
        return !empty($pll_options['default_lang']) ? $pll_options['default_lang'] : FRL_TRANSLATOR_DEFAULT_LANG;
    }

    /**
     * Internal fallback: query active languages from DB directly.
     * Used when Polylang's pll_languages_list() returns empty
     * (e.g., during CLI/cron/early AJAX requests when Polylang isn't fully initialized).
     *
     * @return array Array of 2-letter language codes
     */
    private function get_active_languages_internal(): array
    {
        return frl_cache_remember('translations', 'active_languages_fallback', function () {
            global $wpdb;
            // Query language terms directly, filtering by 2-character slugs to exclude pll_en style terms
            $langs = $wpdb->get_col("SELECT t.slug FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id WHERE tt.taxonomy = 'language' AND CHAR_LENGTH(t.slug) = 2");
            return !empty($langs) ? $langs : [$this->get_default_language_internal()];
        });
    }

    /**
     * Set the default language in the database.
     *
     * Reads the polylang option, merges default_lang, and writes back
     * only if changed.
     *
     * @param string $lang 2-letter language code.
     * @return bool True if the default language was updated, false otherwise.
     */
    public function set_default_language(string $lang): bool
    {
        $pll_options = get_option('polylang', []);
        if (!is_array($pll_options)) {
            return false;
        }

        $current_default = $pll_options['default_lang'] ?? '';
        if ($current_default === $lang) {
            return false; // Already correct.
        }

        $pll_options['default_lang'] = $lang;
        update_option('polylang', $pll_options);
        return true;
    }

    public function get_home_url(string $language): string
    {
        if (function_exists('pll_home_url')) {
            return pll_home_url($language);
        }
        return home_url();
    }

    /**
     * Flush Polylang's internal language cache.
     *
     * @return void
     */
    public function flush_cache(): void
    {
        if (!function_exists('PLL')) {
            return;
        }

        $pll = PLL();
        if (!$pll || !isset($pll->model)) {
            return;
        }

        if (method_exists($pll->model, 'clean_languages_cache')) {
            $pll->model->clean_languages_cache();
        } else {
            // Fallback: delete all PLL-related transients.
            global $wpdb;
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_pll_%'");
            if (is_multisite()) {
                $wpdb->query("DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE '_site_transient_pll_%'");
            }
        }
    }
}
