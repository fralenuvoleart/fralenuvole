<?php
/**
 * PHPStan stubs for ACF (Advanced Custom Fields) plugin functions
 *
 * These stubs provide type definitions for ACF functions
 * that are not included in wordpress-stubs.
 */

if (!class_exists('acf')) {
    /** @source https://www.advancedcustomfields.com/resources/acf-field-class/ */
    class acf {}
}

if (!class_exists('acf_field')) {
    /** @source https://www.advancedcustomfields.com/resources/acf-field-class/ */
    class acf_field {
        public string $name = '';
        public string $label = '';
        public string $category = '';
        /** @var array<string, mixed> */
        public array $defaults = [];
        /** @var array<string, string> */
        public array $l10n = [];
    }
}

if (!function_exists('acf_get_field')) {
    /**
     * @param int|string $id
     */
    function acf_get_field($id) {
        return false;
    }
}

if (!function_exists('acf_get_fields')) {
    /**
     * @param array<string, mixed> $args
     * @return array<int, array<string, mixed>>
     */
    function acf_get_fields($args) {
        return [];
    }
}

if (!function_exists('acf_update_field')) {
    /**
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    function acf_update_field($field) {
        return $field;
    }
}

if (!function_exists('acf_delete_field')) {
    /**
     * @param int|string $id
     * @return bool
     */
    function acf_delete_field($id) {
        return false;
    }
}

if (!function_exists('acf_get_field_groups')) {
    /**
     * @param array<string, mixed>|null $args
     * @return array<int, array<string, mixed>>
     */
    function acf_get_field_groups($args = null) {
        return [];
    }
}

if (!function_exists('acf_get_field_group')) {
    /**
     * @param int|string $id
     */
    function acf_get_field_group($id) {
        return false;
    }
}

if (!function_exists('acf_update_field_group')) {
    /**
     * @param array<string, mixed> $field_group
     * @return array<string, mixed>
     */
    function acf_update_field_group($field_group) {
        return $field_group;
    }
}

if (!function_exists('acf_delete_field_group')) {
    /**
     * @param int|string $id
     * @return bool
     */
    function acf_delete_field_group($id) {
        return false;
    }
}

if (!function_exists('acf_get_value')) {
    /**
     * @param int $post_id
     * @param array<string, mixed> $field
     * @return mixed
     */
    function acf_get_value($post_id, $field) {
        return null;
    }
}

if (!function_exists('acf_update_value')) {
    /**
     * @param int $post_id
     * @param mixed $value
     * @param array<string, mixed> $field
     * @return bool
     */
    function acf_update_value($post_id, $value, $field) {
        return false;
    }
}

if (!function_exists('acf_delete_value')) {
    /**
     * @param int $post_id
     * @param array<string, mixed> $field
     * @return bool
     */
    function acf_delete_value($post_id, $field) {
        return false;
    }
}

if (!function_exists('acf_format_value')) {
    /**
     * @param mixed $value
     * @param int $post_id
     * @param array<string, mixed> $field
     * @return mixed
     */
    function acf_format_value($value, $post_id, $field) {
        return $value;
    }
}

if (!function_exists('acf_validate_value')) {
    /**
     * @param mixed $value
     * @param array<string, mixed> $field
     * @param bool $validate
     * @return bool|array<int, string>
     */
    function acf_validate_value($value, $field, $validate = true) {
        return true;
    }
}

if (!function_exists('acf_register_field_type')) {
    /**
     * @param string $class_name
     * @return void
     */
    function acf_register_field_type($class_name) {}
}

if (!function_exists('acf_get_meta')) {
    /**
     * @param int $post_id
     * @return array<string, mixed>|false
     */
    function acf_get_meta($post_id) {
        return false;
    }
}

if (!function_exists('acf_update_meta')) {
    /**
     * @param mixed $value
     * @param int $post_id
     * @param string $name
     * @param bool $allow_duplicate
     * @return int|false
     */
    function acf_update_meta($value, $post_id, $name, $allow_duplicate = false) {
        return false;
    }
}

if (!function_exists('acf_delete_meta')) {
    /**
     * @param int $post_id
     * @param string $prefix
     * @return bool
     */
    function acf_delete_meta($post_id, $prefix = '') {
        return false;
    }
}

if (!function_exists('acf_get_options_page')) {
    /**
     * @param string $slug
     * @return array<string, mixed>|false
     */
    function acf_get_options_page($slug) {
        return false;
    }
}

if (!function_exists('acf_add_options_page')) {
    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    function acf_add_options_page($args) {
        return [];
    }
}

if (!function_exists('acf_add_options_sub_page')) {
    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    function acf_add_options_sub_page($args) {
        return [];
    }
}

if (!function_exists('acf_render_field_setting')) {
    /**
     * @param array<string, mixed> $field
     * @param array<string, mixed> $setting
     * @param bool $before
     * @return void
     */
    function acf_render_field_setting($field, $setting, $before = false) {}
}

if (!function_exists('get_field')) {
    /**
     * @param string $selector
     * @param int|string|false $post_id
     * @param bool $format_value
     * @return mixed
     */
    function get_field($selector, $post_id = false, $format_value = true) {
        return null;
    }
}

if (!function_exists('get_field_object')) {
    /**
     * @param string $selector
     * @param int|string|false $post_id
     * @param bool $format_value
     * @return array<string, mixed>|false
     */
    function get_field_object($selector, $post_id = false, $format_value = true) {
        return false;
    }
}

if (!function_exists('the_field')) {
    /**
     * @param string $selector
     * @param int|false $post_id
     * @param bool $format_value
     * @return void
     */
    function the_field($selector, $post_id = false, $format_value = true) {}
}

if (!function_exists('update_field')) {
    /**
     * @param string $selector
     * @param mixed $value
     * @param int|string|false $post_id
     * @return bool
     */
    function update_field($selector, $value, $post_id = false) {
        return false;
    }
}

if (!function_exists('delete_field')) {
    /**
     * @param string $selector
     * @param int|false $post_id
     * @return bool
     */
    function delete_field($selector, $post_id = false) {
        return false;
    }
}

if (!function_exists('acf_hidden_input')) {
    /**
     * @param array<string, mixed> $field
     * @return void
     */
    function acf_hidden_input($field) {}
}

if (!function_exists('acf_select_input')) {
    /**
     * @param array<string, mixed> $field
     * @param bool $echo
     * @return string|void
     */
    function acf_select_input($field, $echo = true) {}
}
