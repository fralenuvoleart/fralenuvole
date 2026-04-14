<?php
/**
 * PHPStan stubs for WPML (WordPress Multilingual) plugin functions
 *
 * These stubs provide type definitions for WPML functions
 * that are not included in wordpress-stubs.
 */

if (!function_exists('icl_object_id')) {
    /**
     * @param int|string $element_id
     * @param string $element_type
     * @param bool $return_original_if_missing
     * @param int|string|null $language_code
     */
    function icl_object_id($element_id, $element_type, $return_original_if_missing = false, $language_code = null) {
        return false;
    }
}

if (!function_exists('icl_get_element_language_details')) {
    /**
     * @param int|string $element_id
     * @param string $element_type
     * @param bool $return_original_if_missing
     * @param string|null $language_code
     * @return mixed
     */
    function icl_get_element_language_details($element_id, $element_type, $return_original_if_missing = false, $language_code = null) {
        return null;
    }
}

if (!function_exists('icl_translate')) {
    /**
     * @param string $package_name
     * @param string $name
     * @param string $value
     * @param string|null $lang
     * @param bool $only_original
     * @return string
     */
    function icl_translate($package_name, $name, $value, $lang = null, $only_original = false) {
        return $value;
    }
}

if (!function_exists('icl_register_string')) {
    /**
     * @param string $context
     * @param string $name
     * @param string $value
     * @param bool $allow_empty_value
     * @return void
     */
    function icl_register_string($context, $name, $value, $allow_empty_value = false) {}
}

if (!function_exists('icl_unregister_string')) {
    /**
     * @param string $context
     * @param string $name
     * @return void
     */
    function icl_unregister_string($context, $name) {}
}

if (!function_exists('icl_get_languages')) {
    /**
     * @param string $orderby
     * @param string $order
     * @param bool $skip_missing
     * @param int $link_empty_to
     * @return array<string, array<string, mixed>>
     */
    function icl_get_languages($orderby = 'id', $order = 'asc', $skip_missing = false, $link_empty_to = 0) {
        return [];
    }
}

if (!function_exists('icl_get_default_language')) {
    /** @return string */
    function icl_get_default_language() {
        return '';
    }
}

if (!function_exists('icl_get_current_language')) {
    /** @return string */
    function icl_get_current_language() {
        return '';
    }
}

if (!function_exists('icl_get_language_code')) {
    /**
     * @param int|string|null $element_id
     * @param string $element_type
     * @return mixed
     */
    function icl_get_language_code($element_id = null, $element_type = 'post') {
        return null;
    }
}

if (!function_exists('icl_get_language_name')) {
    /**
     * @param string $lang_code
     * @return string
     */
    function icl_get_language_name($lang_code) {
        return '';
    }
}

if (!function_exists('icl_is_translated_post_type')) {
    /**
     * @param string $post_type
     * @return bool
     */
    function icl_is_translated_post_type($post_type) {
        return false;
    }
}

if (!function_exists('icl_is_translated_taxonomy')) {
    /**
     * @param string $taxonomy
     * @return bool
     */
    function icl_is_translated_taxonomy($taxonomy) {
        return false;
    }
}

if (!function_exists('wpml_object_id')) {
    /**
     * @param int|string $element_id
     * @param string $type
     * @param bool $return_original_if_missing
     * @param string|null $language
     */
    function wpml_object_id($element_id, $type, $return_original_if_missing = false, $language = null) {
        return false;
    }
}
