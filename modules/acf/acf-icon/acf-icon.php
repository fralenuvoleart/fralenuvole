<?php
/**
 * ACF Icon module
 * - Field formatter for 'frl_icon' (span|svg)
 * - [frl_icon] shortcode
 * - Shared helpers (resolution, render, caching)
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

// Requires first
require_once __DIR__ . '/class-frl-icon-renderer.php';
require_once __DIR__ . '/class-frl-icon-resolver.php';

// Admin UI; keep REST available also on REST requests
if ( is_admin() || frl_is_doing_ajax() || frl_is_rest_api_request() ) {
    require_once __DIR__ . '/acf-icon-admin.php';

    // Add compatibility with ACF QuickEdit Fields
    add_filter('acf_quick_edit_fields_types',
        function ($types) {
            $types['frl_icon'] = [
                'column' => true,
                'quickedit' => true,
                'bulkedit' => true,
                'filter' => true,
                'backendsearch' => false,
            ];
            return $types;
        },
        10,
        1);
}

// Hooks (use hook manager helper)
add_action('acf/init',
    'frl_register_icon_field_type',
    10,
    0,);

// Auto-format 'frl_icon' values (returns span|svg per FRL_ICONS_RENDER_VALUE)
add_filter('acf/format_value/type=' . FRL_ACF_FIELD_TYPE_ICON, 'frl_acf_format_value_for_icon_field',
    20,
    3);

// Register the custom ACF field type
function frl_register_icon_field_type() {
    include_once __DIR__ . '/class-frl-acf-field-icon.php';

    acf_register_field_type('frl_acf_field_icon');
}

// Register shortcode and usage
// Usage (concise):
// [frl_icon]                              // uses 'frl_icon' from current post
// [frl_icon id="@counter"]                // CSS counter bullet
// [frl_icon field="my_icon"]              // ACF field (relative .svg)
// [frl_icon field="my_icon" mode="inline"] // inline SVG
// [frl_icon field="my_icon" mode="url"]    // return URL
// [frl_icon id="set/icon.svg"]            // direct relative path
// [frl_icon postmeta="posts_0_icon_frl_icon"] // raw post meta
// [frl_icon scope="parent_row" repeater="lists" parent="group" subfield="frl_icon"]
// [frl_icon postmeta="frl_icon" postid="123"]
// Mode: default = FRL_ICONS_RENDER_SHORTCODE (span). Overrides: inline | url. 
add_shortcode(FRL_PREFIX . '_icon', 'frl_shortcode_icon');

// Auto-format 'frl_icon' values (returns span|svg per FRL_ICONS_RENDER_VALUE)
function frl_acf_format_value_for_icon_field($value, $post_id, $field) {
    if (is_admin()) {
        return $value;
    }
    // (Guard removed) Always format in frontend
    if (!is_string($value) || $value === '') return $value;
    $rel = trim($value);

    // Counter token bypass: render CSS counter via span
    if (defined('FRL_ICONS_COUNTER_TOKEN') && $rel === FRL_ICONS_COUNTER_TOKEN) {
        return FRL_Icon_Renderer::render_counter_html();
    }

    if (!FRL_Icon_Renderer::is_svg_rel($rel)) return '';

    $mode = (defined('FRL_ICONS_RENDER_VALUE') && (FRL_ICONS_RENDER_VALUE === 'svg' || FRL_ICONS_RENDER_VALUE === 'span'))
        ? FRL_ICONS_RENDER_VALUE
        : 'span';

    // Per-request memoization to avoid repeated cache lookups for identical values
    static $memo = [];
    $memo_key = $mode . '|' . $rel;
    if (isset($memo[$memo_key])) {
        return $memo[$memo_key];
    }
    if ($mode === 'svg') {
        $out = FRL_Icon_Renderer::render('svg', $rel);
        $memo[$memo_key] = $out;
        return $out;
    }
    $out = FRL_Icon_Renderer::render('span', $rel);
    $memo[$memo_key] = $out;
    return $out;
}

/**
 * Shortcode handler for [frl_icon]
 *
 * Resolves an icon relative path from provided attributes and renders it
 * according to the selected mode.
 *
 * Supported attributes:
 * - id: direct relative path (e.g., "set/icon.svg")
 * - field: ACF field name on current post (e.g., "my_icon")
 * - parent: ACF group field name (optionally with subfield)
 * - subfield: ACF subfield name inside parent group
 * - meta_key: direct post meta key (raw, bypasses ACF formatting)
 * - scope: "parent_row" for repeater context (requires repeater and parent)
 * - repeater: repeater field name for parent_row scope
 * - row_index: row index (0-based) for parent_row scope
 * - class: extra CSS class(es) added to rendered element
 * - title: accessible title/aria-label
 * - mode: "span" (default), "inline" (svg), or "url" (return URL string)
 *
 * @param array $atts Shortcode attributes.
 * @return string Rendered HTML (span|svg) or URL when mode=url; empty if unresolved.
 */
function frl_shortcode_icon($atts)
{
    static $html_memo = [];

    $a = shortcode_atts([
		'id'     => '',
		'field'  => '',
		'parent' => '',
        'subfield' => '',
        'class'  => '',
		'title'  => '',
		'mode'   => '',
        'scope'  => '',
        'repeater' => '',
		'postmeta' => '',
		'postid' => '',
		'default' => '',
		'row_index' => '',
    ], $atts, FRL_PREFIX . '_icon');

    if (!class_exists('FRL_Icon_Resolver')) { return ''; }
	$rel = FRL_Icon_Resolver::resolve_from_shortcode($a);

    if ($rel === '') {
        $fallback = trim((string)$a['default']);
        if ($fallback !== '') {
            $rel = $fallback;
        } else {
            return '';
        }
    }

    // Counter token: return bullet span immediately
    if (defined('FRL_ICONS_COUNTER_TOKEN') && $rel === FRL_ICONS_COUNTER_TOKEN) {
        $rawClass = trim((string)$a['class']);
        if ($rawClass !== '') {
            $tokens = preg_split('/\s+/', $rawClass);
            $sanitized = array_filter(array_map('sanitize_html_class', is_array($tokens) ? $tokens : [$rawClass]));
            $class = implode(' ', array_unique($sanitized));
        } else {
            $class = '';
        }
        $title = trim((string)$a['title']);
        $out = FRL_Icon_Renderer::render_counter_html($class, $title);
        $html_memo['counter|' . $class . '|' . $title] = $out;
        return $out;
    }

    if (!FRL_Icon_Renderer::is_svg_rel($rel)) {
        return '';
    }

    $rawClass = trim((string)$a['class']);
    if ($rawClass !== '') {
        $tokens = preg_split('/\s+/', $rawClass);
        $sanitized = array_filter(array_map('sanitize_html_class', is_array($tokens) ? $tokens : [$rawClass]));
        $class = implode(' ', array_unique($sanitized));
    } else {
        $class = '';
    }
    $title = trim((string)$a['title']);

    $effective_mode = FRL_ICONS_RENDER_SHORTCODE;
    if ($a['mode'] !== '') {
        $mode = strtolower(trim($a['mode']));
        if ($mode === 'url' || $mode === 'inline') {
            $effective_mode = $mode;
        }
    }


    $html_key = $effective_mode . '|' . $rel . '|' . $class . '|' . $title;
	if (isset($html_memo[$html_key])) {
		return $html_memo[$html_key];
    }

    // Special mode to return the icon URL instead of rendered HTML
    if ($effective_mode === 'url') {
        return FRL_Icon_Renderer::url($rel);
    }
    if ($effective_mode === 'inline' || $effective_mode === 'svg') {
        $svg = FRL_Icon_Renderer::render('svg', $rel, $class, $title);
        $html_memo[$html_key] = $svg;
        return $svg;
    }

    $out = FRL_Icon_Renderer::render('span', $rel, $class, $title);

    $html_memo[$html_key] = $out;
    return $out;
}
