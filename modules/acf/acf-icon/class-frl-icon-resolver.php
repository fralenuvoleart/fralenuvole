<?php

// Exit if accessed directly.
if (!defined('ABSPATH')) {
	exit;
}

class FRL_Icon_Resolver {

	// Helpers for resolving an icon relative path from shortcode attributes
	public static function get_post_id(array $a): int
	{
		$post_id = 0;
		// Explicit override via shortcode attribute 'postid'
		if (isset($a['postid'])) {
			if (is_numeric($a['postid'])) {
				return (int)$a['postid'];
			}
			if (is_object($a['postid']) && isset($a['postid']->ID) && is_numeric($a['postid']->ID)) {
				return (int)$a['postid']->ID;
			}
		}
		if (!empty($GLOBALS['frl_block_stack']) && is_array($GLOBALS['frl_block_stack'])) {
			$stack = $GLOBALS['frl_block_stack'];
			$last = end($stack);
			if (is_array($last)) {
				// no-op
			}
			if ($last && !empty($last['attrs']['postId']) && is_numeric($last['attrs']['postId'])) {
				$post_id = (int)$last['attrs']['postId'];
			}
			// Try to infer current post from repeater context (IDs or objects)
			if ($post_id <= 0 && $last && !empty($last['repeaterArray'])) {
				$rep = $last['repeaterArray'];
				if (is_array($rep)) {
					// Common patterns: direct numeric id, ID key, id key, postId key, or WP_Post-like object
					if (isset($rep['postId']) && is_numeric($rep['postId'])) {
						$post_id = (int)$rep['postId'];
					} elseif (isset($rep['ID']) && is_numeric($rep['ID'])) {
						$post_id = (int)$rep['ID'];
					} elseif (isset($rep['id']) && is_numeric($rep['id'])) {
						$post_id = (int)$rep['id'];
					} elseif (isset($rep['post']) && is_object($rep['post']) && isset($rep['post']->ID) && is_numeric($rep['post']->ID)) {
						$post_id = (int)$rep['post']->ID;
					} elseif (isset($rep['post']) && is_numeric($rep['post'])) {
						$post_id = (int)$rep['post'];
					}
				}
			}
		}
		if ($post_id <= 0) {
			if (isset($GLOBALS['post']) && is_object($GLOBALS['post']) && isset($GLOBALS['post']->ID)) {
				$post_id = (int)$GLOBALS['post']->ID;
			} elseif (function_exists('frl_get_current_post_id')) {
				$post_id = (int)frl_get_current_post_id();
			} else {
				$pid = get_the_ID();
				if (is_numeric($pid)) { $post_id = (int)$pid; }
			}
		}
		return $post_id;
	}

	public static function load_repeater(string $repeater, int $post_id)
	{
		$cache_key = 'repeater_' . $post_id . '_' . $repeater;
		return frl_cache_remember(FRL_ICONS_CACHE_GROUP, $cache_key, static function() use ($repeater, $post_id) {
			// Load with formatting enabled so subkeys use field NAMES
			$rows = get_field($repeater, $post_id, true);
			return $rows;
		});
	}

	public static function resolve_from_shortcode(array $a): string
	{
		// Per-request memo: avoid repeated resolution work for identical attrs and context
		static $memo = [];
		$post_id = self::get_post_id($a);
		$mk = md5(json_encode([
			$a['id'] ?? null,
			$a['postmeta'] ?? null,
			$a['field'] ?? null,
			$a['parent'] ?? null,
			$a['subfield'] ?? null,
			$a['scope'] ?? null,
			$a['repeater'] ?? null,
			$a['row_index'] ?? null,
			'pid' => $post_id,
		]));
		if (isset($memo[$mk])) {
			return $memo[$mk];
		}
		// Prioritize direct ID and meta_key resolution
		$rel = self::_resolve_by_id($a);
		if ($rel !== '') return $rel;

		$rel = self::_resolve_by_postmeta($a, $post_id);
		if ($rel !== '') return $rel;

		// Scope-based resolution
		if (($a['scope'] ?? '') === 'parent_row') {
			$rel = self::_resolve_from_parent_row($a, $post_id);
			if ($rel !== '') return $rel;
		}

		// Fallback to generic field/subfield resolution
		$rel = self::_resolve_from_field_attributes($a, $post_id);
		$memo[$mk] = $rel;
		return $rel;
	}

	/**
	 * Resolves an icon path directly from the 'id' shortcode attribute.
	 * @param array $a Shortcode attributes.
	 * @return string The icon path or an empty string.
	 */
	private static function _resolve_by_id(array $a): string
	{
		$rel = trim((string)($a['id'] ?? ''));
		if ($rel !== '') {
			$dec = html_entity_decode($rel, ENT_QUOTES | ENT_HTML5);
			if ($dec !== '' && $dec[0] === '<') {
				return ''; // Treat as not provided, continue resolution
			}
			return $rel; // Valid ID found
		}
		return '';
	}

	/**
	 * Resolves an icon path from a direct meta_key lookup.
	 * @param array $a Shortcode attributes.
	 * @param int   $post_id The current post ID.
	 * @return string The icon path or an empty string.
	 */
	private static function _resolve_by_postmeta(array $a, int $post_id): string
	{
		if (!empty($a['postmeta'])) {
			if ($post_id > 0) {
				$meta_key = (string)$a['postmeta'];
				$meta_value = frl_get_post_meta($post_id, $meta_key, true);
				if (is_string($meta_value) && $meta_value !== '') {
					return $meta_value;
				}
			}
		}
		return '';
	}

	/**
	 * Resolves an icon path from top-level field attributes ('parent' or 'field').
	 * @param array $a Shortcode attributes.
	 * @param int   $post_id The current post ID.
	 * @return string The icon path or an empty string.
	 */
	private static function _resolve_from_field_attributes(array $a, int $post_id): string
	{
		if (!function_exists('get_field')) {
			return '';
		}

		// Post/global field access
		$parent = (string)($a['parent'] ?? '');
		$subfield = (string)($a['subfield'] ?? '');
		if ($parent !== '') {
			$parent_value = get_field($parent, $post_id, false);
			if (is_array($parent_value)) {
				// 1) Exact subfield if provided
				if ($subfield !== '' && isset($parent_value[$subfield]) && is_string($parent_value[$subfield])) {
					$norm = self::normalize_icon_rel((string)$parent_value[$subfield]);
					if ($norm !== '') return $norm;
				}

				// 2) Abstract scan: first value that normalizes to .svg
				foreach ($parent_value as $v) {
					if (is_string($v)) {
						$norm = self::normalize_icon_rel($v);
						if ($norm !== '') return $norm;
					}
				}
			}
		} else {
			$field = (string)($a['field'] ?? '');
			if ($field !== '') {
				$raw = get_field($field, $post_id, false);
				if (is_string($raw)) {
					$norm = self::normalize_icon_rel($raw);
					return $norm !== '' ? $norm : trim($raw);
				}
				return '';
			}
		}

		// Bare shortcode fallback: try default field name when no field/parent provided
		if ($parent === '' && ($a['field'] ?? '') === '') {
			$default_field = FRL_ACF_FIELD_TYPE_ICON; // 'frl_icon'
			$raw = get_field($default_field, $post_id, false);
			if (is_string($raw)) {
				$norm = self::normalize_icon_rel($raw);
				if ($norm !== '') {
					return $norm;
				}
				return trim($raw);
			}
		}

		return '';
	}

	/**
	 * Orchestrates icon resolution from within a parent repeater context.
	 * @param array $a Shortcode attributes.
	 * @param int   $post_id The current post ID.
	 * @return string The icon path or an empty string.
	 */
	private static function _resolve_from_parent_row(array $a, int $post_id): string
	{
		$repeater = trim((string)$a['repeater']);
		$parent_group = trim((string)$a['parent']);
		$subfield = (string)($a['subfield'] ?? '');
		$rows = ($repeater !== '') ? self::load_repeater($repeater, $post_id) : null;

		if (!is_array($rows) || $parent_group === '') {
			return '';
		}

		// 1. Prioritize explicit index resolution via 'row_index'
		$explicit_index = self::_resolve_parent_row_by_index($a, $rows, $parent_group);
		if ($explicit_index !== '') {
			return $explicit_index;
		}

		// 2. Final resort: legacy fallback (first non-empty)
		$rel = self::_resolve_parent_row_by_fallback($rows, $parent_group, $subfield);
		return $rel;
	}

	/**
	 * Resolves an icon from a repeater row by an explicit index ('row_index').
	 * @param array  $a The shortcode attributes.
	 * @param array  $rows The repeater rows.
	 * @param string $parent_group The name of the parent group field.
	 * @return string The icon path or an empty string.
	 */
	private static function _resolve_parent_row_by_index(array $a, array $rows, string $parent_group): string
	{
		$index = -1;
		if (isset($a['row_index']) && ctype_digit((string)$a['row_index'])) {
			$index = (int)$a['row_index'];
		}

		if ($index >= 0 && isset($rows[$index])) {
			$entry = $rows[$index];
			if (is_array($entry)) {
				$val = self::_get_icon_value_from_row($entry, $parent_group);
				return $val;
			}
			if (is_string($entry)) {
				$norm = self::normalize_icon_rel($entry);
				if ($norm !== '') {
					return $norm;
				}
			}
		}
		return '';
	}

	/**
	 * Finds the first non-empty icon value as a fallback when no specific index is provided.
	 * @param array  $rows The repeater rows.
	 * @param string $parent_group The name of the parent group field.
	 * @return string The icon path or an empty string.
	 */
	private static function _resolve_parent_row_by_fallback(array $rows, string $parent_group, string $subfield): string
	{
		foreach ($rows as $i => $row) {
			if (is_array($row)) {
				$val = self::_get_icon_value_from_row($row, $parent_group, $subfield);
				if ($val !== '') {
					return $val;
				}
			} elseif (is_string($row)) {
				$norm = self::normalize_icon_rel($row);
				if ($norm !== '') {
					return $norm;
				}
			}
		}
		return '';
	}

	/**
	 * Extracts the icon value from a single repeater row array.
	 * Checks for both nested (group) and flattened (group_field) key structures.
	 * @param array  $row A single repeater row.
	 * @param string $parent_group The name of the parent group field.
	 * @return string The icon path or an empty string.
	 */
	private static function _get_icon_value_from_row(array $row, string $parent_group, string $subfield = ''): string
	{
		// Nested group structure: try provided subfield, then legacy key, then scan
		if (isset($row[$parent_group]) && is_array($row[$parent_group])) {
			if ($subfield !== '' && isset($row[$parent_group][$subfield]) && is_string($row[$parent_group][$subfield])) {
				$val = (string)$row[$parent_group][$subfield];
				$norm = self::normalize_icon_rel($val);
				if ($norm !== '') {
					return $norm;
				}
			}

			// Abstract scan: any string inside group that normalizes to .svg
			foreach ($row[$parent_group] as $k => $v) {
				if (is_string($v)) {
					$norm = self::normalize_icon_rel($v);
					if ($norm !== '') {
						return $norm;
					}
				}
			}
		}

		// Flattened structure: try provided subfield, then legacy key, then scan
		if ($subfield !== '') {
			$flat_key = $parent_group . '_' . $subfield;
			if (isset($row[$flat_key]) && is_string($row[$flat_key])) {
				$val = (string)$row[$flat_key];
				$norm = self::normalize_icon_rel($val);
				if ($norm !== '') {
					return $norm;
				}
			}
		}

		// Abstract scan on flattened row: any string that normalizes to .svg
		foreach ($row as $k => $v) {
			if (is_string($v)) {
				$norm = self::normalize_icon_rel($v);
				if ($norm !== '') {
					return $norm;
				}
			}
		}

		return '';
	}

	/**
	 * Normalize a possibly formatted value (HTML span with mask-image) to a relative .svg path.
	 */
	private static function normalize_icon_rel(string $value): string
	{
		$val = trim($value);
		if ($val === '') {
			return '';
		}
		// Already a relative .svg path
		if (FRL_Icon_Renderer::is_svg_rel($val)) {
			return ltrim($val, '/');
		}
		// Try to extract from a span with mask-image:url(...) or CSS variable --frl-icon-url
		if (str_contains($val, '<span')) {
			// New pattern: CSS variable with URL
			if (preg_match('/--frl-icon-url\s*:\s*url\(([^\)]+)\)/i', $val, $mVar)) {
				$rel = self::normalize_src_to_rel(trim($mVar[1], "'\""));
				if ($rel !== '') return $rel;
			}
			// Legacy pattern: mask-image: url(...)
			if (str_contains($val, 'mask-image')) {
				if (preg_match('/mask-image:\s*url\(([^\)]+)\)/i', $val, $m)) {
					$rel = self::normalize_src_to_rel(trim($m[1], "'\""));
					if ($rel !== '') return $rel;
				}
			}
		}
		// Inline <svg> cannot be normalized to a file path; return empty to continue fallback
		return '';
	}

	/**
	 * Convert an absolute or relative icon src into a normalized relative .svg path
	 */
	private static function normalize_src_to_rel(string $src): string
	{
		$prefix = FRL_DIR_URL . FRL_ICONS_RELATIVE_PATH;
		if (str_starts_with($src, $prefix)) {
			return ltrim(substr($src, strlen($prefix)), '/');
		}
		if (FRL_Icon_Renderer::is_svg_rel($src)) {
			return ltrim($src, '/');
		}
		return '';
	}

	/**
	 * Create a human-readable label from a relative icon path
	 */
	public static function label_from_rel(string $value): string
	{
		if (empty($value)) {
			return '';
		}
		$parts = explode('/', $value);
		$filename = pathinfo(end($parts), PATHINFO_FILENAME);
		$folder_parts = array_slice($parts, 0, -1);
		$label_parts = [];
		foreach ($folder_parts as $part) {
			$label_parts[] = ucwords(str_replace(['-', '_'], ' ', $part));
		}
		$label_parts[] = ucwords(str_replace(['-', '_'], ' ', $filename));
		return implode(' / ', $label_parts);
	}
}
