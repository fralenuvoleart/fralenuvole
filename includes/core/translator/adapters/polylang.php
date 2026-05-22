<?php
/**
 * Polylang Translation Adapter
 *
 * Implements the Frl_Translation_Adapter_Interface for Polylang.
 */

if (!defined('ABSPATH')) exit;

require_once FRL_DIR_PATH . 'includes/core/translator/adapters/interface.php';

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
        return frl_get_default_language_fallback();
    }

    public function get_default_language(): string
    {
        if (function_exists('pll_default_language')) {
            $lang = pll_default_language();
            if (!empty($lang)) {
                return $lang;
            }
        }
        return frl_get_default_language_fallback();
    }

    public function get_active_languages(): array
    {
        if (function_exists('pll_languages_list')) {
            $langs = pll_languages_list(['fields' => 'slug']);
            if (!empty($langs)) {
                return $langs;
            }
        }
        return frl_get_active_languages_fallback();
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
}
