<?php
/**
 * PHPStan stubs for WSForm Pro plugin functions
 *
 * These stubs provide type definitions for WSForm functions
 * that are not included in wordpress-stubs.
 */

if (!function_exists('wsf_form_get_fields')) {
    /**
     * @param int $form_id
     * @return array<int, object>
     */
    function wsf_form_get_fields($form_id) {
        return [];
    }
}

if (!function_exists('wsf_field_get_object')) {
    /**
     * @param int $form_id
     * @param int $field_id
     */
    function wsf_field_get_object($form_id, $field_id) {
        return null;
    }
}

if (!function_exists('wsf_form_get_submit_count')) {
    /**
     * @param int $form_id
     * @param array<string, mixed> $args
     * @return int
     */
    function wsf_form_get_submit_count($form_id, $args = []) {
        return 0;
    }
}

if (!function_exists('wsf Form_get_visible_count')) {
    /**
     * @param int $form_id
     * @param array<string, mixed> $args
     * @return int
     */
    function wsf_form_get_visible_count($form_id, $args = []) {
        return 0;
    }
}

if (!function_exists('wsf_submit')) {
    /**
     * @param int $form_id
     * @param array<string, mixed> $post_data
     */
    function wsf_submit($form_id, $post_data = []) {
        return false;
    }
}

if (!function_exists('wsf_form_get_status_labels')) {
    /**
     * @param int $form_id
     * @return array<int, string>
     */
    function wsf_form_get_status_labels($form_id) {
        return [];
    }
}

if (!function_exists('wsf_setting')) {
    /**
     * @param string $key
     * @param mixed $default
     * @param int $form_id
     * @return mixed
     */
    function wsf_setting($key, $default = null, $form_id = null) {
        return $default;
    }
}
