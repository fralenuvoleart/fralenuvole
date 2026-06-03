<?php

/**
 * Module Name: ACF Custom Fields
 * Description: Additional features for ACF Custom Fields
 */


// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

// Load configuration and helpers always
require_once __DIR__ . '/config-constants-acf.php';
require_once __DIR__ . '/acf-icon/acf-icon.php';

// Shortcodes work without ACF when id=... is used
require_once __DIR__ . '/acf-shortcodes.php';

// If ACF is not available, skip ACF-only integrations
if (!function_exists('get_field')) {
    return;
}

// Compute and persist configured calculated ACF option fields on ACF Options save
add_action('acf/save_post',
    'frl_acf_calculate_on_save',
    999,
    1
);

add_action('acf/save_post',
    'frl_acf_flush_shortcodes_cache_on_acf_save',
    99,
    1
);

/**
 * Compute configured calculated ACF fields when saving ACF Options.
 *
 * Config structure (defined in config-constants-acf.php):
 */
/**
 * @param array<string, array{
 *     operation?: string,
 *     fields?: array,
 *     template?: string,
 *     urlencode?: bool,
 *     decimals?: int,
 *     separator?: string
 * }> $calc_options
 */
function frl_acf_process_calc_options(array $calc_options): void
{
    foreach ($calc_options as $target => $cfg) {
        $op = strtoupper(trim((string)($cfg['operation'] ?? '')));
        $src_fields = $cfg['fields'] ?? [];
        $template = $cfg['template'] ?? '';
        $has_template = is_string($template) && $template !== '';

        $new = '';

        // Default to TEMPLATE when no operation provided but fields/template info exists
        if ($op === '' && (!empty($src_fields) || $has_template)) {
            $op = 'TEMPLATE';
        }

        // TEMPLATE support: template string with placeholders like {field}
        if ($op === 'TEMPLATE' || $has_template) {
            if (!$has_template) {
                // fallback: build template dynamically from fields using separator if provided
                $template = implode('', array_map(static function ($f) { return '{' . $f . '}'; }, $src_fields));
            }
            $urlencode = !empty($cfg['urlencode']);
            $new = frl_acf_render_template($template, 'option', 0, $urlencode);
        }
        // Numeric and concat operations
        elseif ($op !== '' && !empty($src_fields)) {
            // Read raw, unformatted values from ACF Options
            $values = [];
            foreach ($src_fields as $name) {
                $values[] = get_field((string)$name, 'option', false);
            }

            $new = frl_acf_compute_value($op, $values, [
                'decimals'  => $cfg['decimals'] ?? null,
                'separator' => $cfg['separator'] ?? '',
            ]);
        } else {
            continue;
        }

        $old = get_field($target, 'option', false);
        if ($old !== $new) {
            if (function_exists('update_field')) {
                $ok = (bool) call_user_func('update_field', $target, $new, 'option');
                if (!$ok && function_exists('frl_log')) {
                    frl_log('ACF calculated option update failed for {target}', ['target' => $target]);
                }
            } else {
                if (function_exists('frl_log')) {
                    frl_log('ACF update_field() not available when saving calculated option {target}', ['target' => $target]);
                }
            }
        }
    }
}

function frl_acf_calculate_on_save($post_id)
{
    if ($post_id !== 'options') {
        return;
    }

    if (!defined('FRL_ACF_CALC_OPTIONS')) {
        return;
    }

    $calc_options = FRL_ACF_CALC_OPTIONS;
    if (!is_array($calc_options) || empty($calc_options)) { // @phpstan-ignore-line
        return;
    }

    frl_acf_process_calc_options($calc_options);
}

/**
 * Compute a value from an array of inputs using an operation.
 * @param string $op Operation name
 * @param array $values Source values (mixed)
 * @param array $args Supported: decimals (int|null), separator (string)
 * @return mixed
 */
function frl_acf_compute_value($op, array $values, array $args = [])
{
    $op = strtoupper(trim((string)$op));
    $decimals = isset($args['decimals']) && $args['decimals'] !== '' ? (int)$args['decimals'] : null;

    // Numeric helpers
    $to_num = static function ($v) {
        if ($v === '' || $v === null) return null;
        if (is_numeric($v)) return (float)$v;
        if (is_string($v)) {
            $n = (float)str_replace(',', '', $v);
            return is_finite($n) ? $n : null;
        }
        return null;
    };

    if ($op === 'CONCAT') {
        $sep = (string)($args['separator'] ?? '');
        $parts = array_filter(array_map('strval', $values), static fn($s) => $s !== '');
        return implode($sep, $parts);
    }

    // Prepare numeric list
    $nums = [];
    foreach ($values as $v) {
        $n = $to_num($v);
        if ($n !== null) $nums[] = $n;
    }

    if (empty($nums)) {
        return $decimals !== null ? round(0.0, $decimals) : 0;
    }

    $result = null;
    switch ($op) {
        case 'SUM':
            $result = array_sum($nums);
            break;
        case 'SUB':
            $result = array_shift($nums);
            foreach ($nums as $n) { $result -= $n; }
            break;
        case 'MUL':
            $result = 1.0;
            foreach ($nums as $n) { $result *= $n; }
            break;
        case 'DIV':
            $result = array_shift($nums);
            foreach ($nums as $n) {
                if ($n == 0.0) { continue; }
                $result /= $n;
            }
            break;
        case 'AVG':
            $result = array_sum($nums) / max(1, count($nums));
            break;
        case 'MIN':
            $result = min($nums);
            break;
        case 'MAX':
            $result = max($nums);
            break;
        default:
            return '';
    }

    if ($decimals !== null) {
        $result = round((float)$result, $decimals);
    }
    return $result;
}

/**
 * Render a template by replacing {field} placeholders with ACF values.
 * @param string $template Template string
 * @param string $source 'option' or 'post'
 * @param int $post_id When source='post'
 * @param bool $urlencode URL-encode values
 * @return string
 */
function frl_acf_render_template(string $template, string $source = 'option', int $post_id = 0, bool $urlencode = false): string
{
    if ($template === '') {
        return '';
    }
    // Normalize multi-line templates written for readability: remove newlines and surrounding spaces
    if (str_contains($template, "\n") || str_contains($template, "\r")) {
        $lines = preg_split("/\r\n|\r|\n/", $template);
        if (is_array($lines)) {
            // Trim both ends per line to remove indentation and trailing spaces
            $template = implode('', array_map('trim', $lines));
        }
    }
    $cb = static function ($m) use ($source, $post_id, $urlencode) {
        $name = $m[1];
        $pid = ($source === 'post' && $post_id > 0) ? $post_id : 'option';

        // Log missing placeholder fields (only failures)
        if (function_exists('get_field_object')) {
            $field_obj = call_user_func('get_field_object', $name, $pid, false);
            if (!$field_obj && function_exists('frl_log')) {
                frl_log('ACF placeholder field missing', ['placeholder' => $name, 'source' => is_string($pid) ? $pid : (string)$pid]);
            }
        }

        $val = get_field($name, $pid, false);
        $out = is_scalar($val) ? (string)$val : '';
        if ($urlencode && $out !== '') {
            $out = rawurlencode($out);
        }
        return $out;
    };
    return preg_replace_callback('/\{([a-zA-Z0-9_\-]+)\}/', $cb, $template);
}

/**
 * Flush shortcode cache when ACF saves any post/options.
 */
function frl_acf_flush_shortcodes_cache_on_acf_save($post_id)
{
    // Clear only keys with our prefix
    $keys = frl_cache_get_multi('shortcodes');
    if ( empty($keys) ) {
        return;
    }
    foreach (array_keys($keys) as $key) {
        if (str_starts_with($key, 'acf_calc_')) {
            frl_cache_clear('shortcodes', $key, false);
        }
    }
}
