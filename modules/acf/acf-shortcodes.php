<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fralenuvole
 * shortcodes.php - Plugin translation shortcodes and more
 */

// Register shortcodes
add_shortcode(FRL_PREFIX . '_acf_calculated', 'frl_shortcode_acf_calculated');

/**
 * [frl_acf_calculated] - Displays the calculated value of an ACF field.
 * Usage: [frl_acf_calculated source="option" fields="price,tax" "operation="SUM" target="total_budget" decimals="2"]
 * source: option (default), or post (defaults to current post, works also in loops),
 * target: field name to store the calculated value, default defined in config-constants-acf.php
* fields: comma separated list of fields, default defined in config-constants-acf.php
 * operation: CONCAT,SUM, SUB, MUL, DIV, AVG, MIN, MAX
 * decimals: number of decimals for number operations
 * separator: separator for CONCAT operation, default empty ""
 */

function frl_shortcode_acf_calculated($atts)
{
    $a = shortcode_atts([
        'source'    => 'option', // option|post
        'fields'    => '',       // CSV list
        'operation' => 'TEMPLATE',// CONCAT|SUM|SUB|MUL|DIV|AVG|MIN|MAX|TEMPLATE
        'target'    => '',       // precomputed target field name
        'decimals'  => '',       // for numeric ops
        'separator' => '',       // for CONCAT
        'template'  => '',       // for TEMPLATE op, e.g. ?q={field1}&f={field2}#a
        'urlencode' => '',       // 1/0 URL-encode values in TEMPLATE
        'id'        => '',       // post ID when source=post
    ], $atts, FRL_PREFIX . '_acf_calculated');

    // Fast path: if only target is provided (no template/fields/etc.), use cached value
    $target = trim((string)$a['target']);
    $has_overrides = ($a['template'] !== '') || ($a['fields'] !== '');
    if ($target !== '' && !$has_overrides) {
        $source = strtolower(trim((string)$a['source'])) ?: 'option';
        // Build cache key with post scope when source is 'post'
        if ($source === 'post') {
            $id_raw = trim((string)$a['id']);
            $post_id = (ctype_digit($id_raw) && (int)$id_raw > 0) ? (int)$id_raw : (int)(function_exists('frl_get_current_post_id') ? frl_get_current_post_id() : get_the_ID());
            $cache_key = 'acf_calc_' . sanitize_key($target) . '_post_' . $post_id . '_v' . (function_exists('frl_get_post_cache_version') ? frl_get_post_cache_version($post_id) : 1);
        } else {
            $cache_key = 'acf_calc_' . sanitize_key($target) . '_option';
        }
        return frl_cache_remember('shortcodes', $cache_key, static function () use ($target, $source, $a) {
            $pid = 'option';
            if ($source === 'post') {
                $id_raw = trim((string)$a['id']);
                $pid = (ctype_digit($id_raw) && (int)$id_raw > 0) ? (int)$id_raw : (int)(function_exists('frl_get_current_post_id') ? frl_get_current_post_id() : get_the_ID());
            }
            $val = get_field($target, $pid, false);
            return is_scalar($val) ? (string)$val : '';
        });
    }

    $source = strtolower(trim((string)$a['source'])) ?: 'option';
    $operation = strtoupper(trim((string)$a['operation']));
    $fields_csv = (string)$a['fields'];
    if ($fields_csv === '' && $operation !== 'TEMPLATE') {
        return '';
    }

    // TEMPLATE mode
    if ($operation === 'TEMPLATE' || $a['template'] !== '') {
        if (!function_exists('frl_acf_render_template')) {
            return '';
        }
        $template = (string)$a['template'];
        $post_id = 0;
        if ($source === 'post') {
            $id_raw = trim((string)$a['id']);
            if ($id_raw !== '' && ctype_digit($id_raw)) {
                $post_id = (int)$id_raw;
            } else {
                $post_id = function_exists('frl_get_current_post_id') ? frl_get_current_post_id() : (int)get_the_ID();
            }
            if ($post_id <= 0) return '';
        }
        $urlencode = filter_var($a['urlencode'], FILTER_VALIDATE_BOOLEAN);
        $rendered = frl_acf_render_template($template, $source, $post_id, $urlencode);

        // Persist to target if provided and exists as an ACF field
        if ($target !== '' && function_exists('update_field')) {
            $pid = ($source === 'post' && $post_id > 0) ? $post_id : 'option';
            $field_exists = false;
            if (function_exists('get_field_object')) {
                $field_obj = call_user_func('get_field_object', $target, $pid, false);
                $field_exists = (bool)$field_obj;
            }
            if ($field_exists) {
                call_user_func('update_field', $target, $rendered, $pid);
                // Warm shortcode cache for this target (must match key format from fast path)
                if ($source === 'post' && $post_id > 0) {
                    $warm_cache_key = 'acf_calc_' . sanitize_key($target) . '_post_' . $post_id . '_v' . (function_exists('frl_get_post_cache_version') ? frl_get_post_cache_version($post_id) : 1);
                } else {
                    $warm_cache_key = 'acf_calc_' . sanitize_key($target) . '_option';
                }
                frl_cache_set('shortcodes', $warm_cache_key, (string)$rendered);
            } else {
                if (function_exists('frl_log')) {
                    frl_log('ACF shortcode target field missing – no-op persist', ['target' => $target]);
                }
            }
        }
        return $rendered;
    }

    // Numeric / CONCAT paths
    // Normalize field names
    $field_names = array_values(array_filter(array_map(static function ($s) {
        return sanitize_key(trim($s));
    }, explode(',', $fields_csv))));

    if (!$field_names) {
        return '';
    }

    // Load values
    $values = [];
    if ($source === 'option') {
        foreach ($field_names as $name) {
            $values[] = get_field($name, 'option', false);
        }
    } else {
        // Post source: use provided id or current post
        $post_id = 0;
        $id_raw = trim((string)$a['id']);
        if ($id_raw !== '' && ctype_digit($id_raw)) {
            $post_id = (int)$id_raw;
        }
        if ($post_id <= 0) {
            $post_id = function_exists('frl_get_current_post_id') ? frl_get_current_post_id() : get_the_ID();
            $post_id = (int)$post_id;
        }
        if ($post_id <= 0) {
            return '';
        }
        foreach ($field_names as $name) {
            $values[] = get_field($name, $post_id, false);
        }
    }

    if (!function_exists('frl_acf_compute_value')) {
        return '';
    }

    $result = frl_acf_compute_value($operation, $values, [
        'decimals'  => $a['decimals'],
        'separator' => $a['separator'],
    ]);

    $result_str = is_scalar($result) ? (string)$result : '';

    // Persist to target if provided and exists as an ACF field
    if ($target !== '' && function_exists('update_field')) {
        $pid = ($source === 'post' && isset($post_id) && $post_id > 0) ? (int)$post_id : 'option';
        $field_exists = false;
        if (function_exists('get_field_object')) {
            $field_obj = call_user_func('get_field_object', $target, $pid, false);
            $field_exists = (bool)$field_obj;
        }
        if ($field_exists) {
            call_user_func('update_field', $target, $result_str, $pid);
            // Warm shortcode cache for this target (must match key format from fast path)
            if ($source === 'post' && isset($post_id) && $post_id > 0) {
                $warm_cache_key = 'acf_calc_' . sanitize_key($target) . '_post_' . $post_id . '_v' . (function_exists('frl_get_post_cache_version') ? frl_get_post_cache_version($post_id) : 1);
            } else {
                $warm_cache_key = 'acf_calc_' . sanitize_key($target) . '_option';
            }
            frl_cache_set('shortcodes', $warm_cache_key, (string)$result_str);
        } else {
            if (function_exists('frl_log')) {
                frl_log('ACF shortcode target field missing – no-op persist', ['target' => $target]);
            }
        }
    }

    return $result_str;
}
