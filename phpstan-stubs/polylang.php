<?php
/**
 * PHPStan stubs for Polylang plugin functions
 *
 * These stubs provide type definitions for Polylang functions
 * that are not included in wordpress-stubs.
 */

if (!function_exists('pll_the_languages')) {
    /**
     * @param array<string, mixed>|string $args
     * @return string
     */
    function pll_the_languages($args = []) {
        return '';
    }
}

if (!function_exists('pll_get_post_types')) {
    /**
     * @param array<string, mixed>|null $args
     * @param bool $flip
     * @return array<string, string>
     */
    function pll_get_post_types($args = null, $flip = false) {
        return [];
    }
}

if (!function_exists('pll_register_string')) {
    /**
     * @param string $context
     * @param string $name
     * @param string|null $string
     * @return void
     */
    function pll_register_string($context, $name, $string = null) {}
}

if (!function_exists('pll__')) {
    /**
     * @param string $string
     * @return string
     */
    function pll__($string) {
        return $string;
    }
}

if (!function_exists('pll_e')) {
    /**
     * @param string $string
     * @return void
     */
    function pll_e($string) {}
}

if (!function_exists('pll_get_language')) {
    /**
     * @param int|null $post_id
     * @return mixed
     */
    function pll_get_language($post_id = null) {
        return null;
    }
}

if (!function_exists('pll_set_language')) {
    /**
     * @param string $slug
     * @return void
     */
    function pll_set_language($slug) {}
}

if (!function_exists('pll_home_url')) {
    /**
     * @param mixed $language
     * @return string
     */
    function pll_home_url($language = null) {
        return '';
    }
}

if (!function_exists('pll_get_post_language')) {
    /**
     * @param int $post_id
     * @param string $field
     * @return mixed
     */
    function pll_get_post_language($post_id, $field = 'slug') {
        return null;
    }
}

if (!function_exists('pll_get_term_language')) {
    /**
     * @param int $term_id
     * @param string $field
     * @return mixed
     */
    function pll_get_term_language($term_id, $field = 'slug') {
        return null;
    }
}

if (!function_exists('pll_set_post_language')) {
    /**
     * @param int $post_id
     * @param string $language
     * @return void
     */
    function pll_set_post_language($post_id, $language) {}
}

if (!function_exists('pll_count_posts')) {
    /**
     * @param mixed $language
     * @return array<string, int>
     */
    function pll_count_posts($language) {
        return [];
    }
}

if (!function_exists('pll_translate_string')) {
    /**
     * @param string $string
     * @param string $lang
     * @param string $context
     * @return string
     */
    function pll_translate_string($string, $lang, $context = 'polylang') {
        return $string;
    }
}

if (!function_exists('pll_switch_lang')) {
    /**
     * @param mixed $lang
     * @return void
     */
    function pll_switch_lang($lang) {}
}

if (!function_exists('pll_current_language')) {
    /**
     * @param bool $value
     */
    function pll_current_language($value = false) {
        return null;
    }
}

if (!function_exists('pll_default_language')) {
    /**
     * @param bool $value
     */
    function pll_default_language($value = false) {
        return null;
    }
}

if (!function_exists('pll_languages_list')) {
    /**
     * @param array<string, mixed>|null $args
     * @return array<int, string>
     */
    function pll_languages_list($args = null) {
        return [];
    }
}

if (!function_exists('pll_get_post')) {
    /**
     * @param int|WP_Post $post
     * @param string|null $lang
     */
    function pll_get_post($post, $lang = null) {
        return null;
    }
}

if (!function_exists('pll_get_post_translations')) {
    /**
     * @param int|WP_Post $post
     * @return array<string, WP_Post>
     */
    function pll_get_post_translations($post) {
        return [];
    }
}

if (!function_exists('pll_get_term_translations')) {
    /**
     * @param int|WP_Term $term
     * @return array<string, WP_Term>
     */
    function pll_get_term_translations($term) {
        return [];
    }
}
