<?php

// Exit if accessed directly.
if (!defined('ABSPATH')) {
	exit;
}

class FRL_Icon_Renderer {

	public static function render(string $mode, string $rel, string $class = '', string $title = ''): string
	{
		// Counter token bypass: render CSS counter bullet container
		if (defined('FRL_ICONS_COUNTER_TOKEN') && $rel === FRL_ICONS_COUNTER_TOKEN) {
			return self::render_counter_html($class, $title);
		}
		$m = ($mode === 'svg' || $mode === 'span') ? $mode : 'span';

		// Normalize and validate relative path early
		$normalizedRel = self::normalize_rel_path($rel);
		if ($normalizedRel === '') {
			return '';
		}

		// Include file mtime in cache key for inline SVG to auto-bust on file changes
		$mtime_part = '';
		if ($m === 'svg' && !self::is_absolute_url($normalizedRel)) {
			$full = FRL_DIR_PATH . FRL_ICONS_RELATIVE_PATH . $normalizedRel;
			$mtime = file_exists($full) ? filemtime($full) : 0;
			$mtime_part = '|' . $mtime;
		}

		$full = (!self::is_absolute_url($normalizedRel)) ? (FRL_DIR_PATH . FRL_ICONS_RELATIVE_PATH . $normalizedRel) : '';
		$size_part = '';
		if ($m === 'svg' && $full !== '' && file_exists($full)) {
			$size = filesize($full);
			$size_part = '|' . (is_int($size) ? $size : 0);
		}
		$cache_key = 'render_' . md5($m . '|' . $normalizedRel . '|' . $class . '|' . $title . $mtime_part . $size_part);
		return frl_cache_remember(FRL_ICONS_CACHE_GROUP, $cache_key, static function () use ($m, $normalizedRel, $class, $title) {
			if ($m === 'svg') {
				return self::render_inline_html($normalizedRel, $class, $title);
			}
			return self::render_span_html($normalizedRel, $class !== '' ? ('frl-icon ' . $class) : 'frl-icon', $title);
		});
	}

	public static function render_counter_html(string $class = '', string $title = ''): string
	{
		$classes = 'frl-icon frl-counter' . ($class !== '' ? (' ' . esc_attr($class)) : '');
		$title_attr = $title !== '' ? ' aria-label="' . esc_attr($title) . '"' : '';
		return '<span class="' . $classes . '"' . $title_attr . '></span>';
	}

	public static function render_span_html(string $rel, string $class = 'frl-icon', string $title = ''): string
	{
		$rel = self::normalize_rel_path($rel);
		if ($rel === '' || !self::is_svg_rel($rel)) return '';
		$src = self::resolve_src($rel);
		if ($src === '') return '';
		$iconClass = self::build_icon_filename_class($rel);
		$classes = $class !== '' ? $class : 'frl-icon';
		if ($iconClass !== '' && !preg_match('/(?:^|\s)'.preg_quote($iconClass, '/').'(?:\s|$)/', $classes)) {
			$classes .= ' ' . $iconClass;
		}
		$style = '--frl-icon-url: url(' . esc_url($src) . '); mask-image: var(--frl-icon-url); -webkit-mask-image: var(--frl-icon-url);';
		return '<span class="' . esc_attr($classes) . '" style="' . esc_attr($style) . '" role="img" aria-label="' . esc_attr($title ?: 'icon') . '"' . ($title !== '' ? ' title="' . esc_attr($title) . '"' : '') . '></span>';
	}

	public static function render_inline_html(string $rel, string $class = 'frl-icon', string $title = ''): string
	{
		$rel = self::normalize_rel_path($rel);
		if ($rel === '' || !self::is_svg_rel($rel)) return '';
		$svg = self::inline($rel);
		if ($svg === '') return '';
		$iconClass = self::build_icon_filename_class($rel);
		$classes = $class !== '' ? ('frl-icon ' . $class) : 'frl-icon';
		if ($iconClass !== '' && !preg_match('/(?:^|\s)'.preg_quote($iconClass, '/').'(?:\s|$)/', $classes)) {
			$classes .= ' ' . $iconClass;
		}
		$class_attr = 'class="' . esc_attr($classes) . '"';
		if (preg_match('/<svg([^>]*)\sclass=["\']([^"\']*)["\']/i', $svg, $matches)) {
			$existing_classes = $matches[2];
			$merged_classes = $existing_classes . ' ' . $classes;
			$svg = preg_replace('/<svg([^>]*)\sclass=["\'][^"\']*["\']/i', '<svg$1 class="' . esc_attr($merged_classes) . '"', $svg, 1);
		} else {
			$svg = preg_replace('/<svg(\b[^>]*)>/i', '<svg$1 ' . $class_attr . '>', $svg, 1);
		}
		// Accessibility: ensure <title>, role and aria-label
		if ($title !== '') {
			if (preg_match('/<title\b/i', $svg)) {
				$svg = preg_replace('/<title\b[^>]*>.*?<\/title>/', '<title>' . esc_html($title) . '</title>', $svg, 1);
			} else {
				$svg = preg_replace('/<svg(\b[^>]*)>/i', '<svg$1><title>' . esc_html($title) . '</title>', $svg, 1);
			}
			if (!preg_match('/\baria-label=/i', $svg)) {
				$svg = preg_replace('/<svg(\b[^>]*)>/i', '<svg$1 aria-label="' . esc_attr($title) . '">', $svg, 1);
			}
		}
		if (!preg_match('/\brole=/i', $svg)) {
			$svg = preg_replace('/<svg(\b[^>]*)>/i', '<svg$1 role="img">', $svg, 1);
		}
		return $svg;
	}

    /** Build full icon URL from relative path (e.g., set/icon.svg). */
	public static function url(string $relPath): string
	{
		if ($relPath === '') return '';
		$rel = self::normalize_rel_path($relPath);
		if ($rel === '') return '';
		if (self::is_absolute_url($rel)) return $rel;
		return FRL_DIR_URL . FRL_ICONS_RELATIVE_PATH . $rel;
	}

	/** Render inline sanitized SVG from relative path (cached). */
	public static function inline(string $relPath): string
	{
		if ($relPath === '') return '';
		$rel = self::normalize_rel_path($relPath);
		if ($rel === '') return '';

		// Include file mtime in cache key to auto-bust on file changes
		$full_for_key = FRL_DIR_PATH . FRL_ICONS_RELATIVE_PATH . $rel;
		$mtime = file_exists($full_for_key) ? filemtime($full_for_key) : 0;
		$size = file_exists($full_for_key) ? filesize($full_for_key) : 0;
		$cache_key = 'svg_' . md5($rel . '|' . $mtime . '|' . $size);
		$svg = frl_cache_remember(FRL_ICONS_CACHE_GROUP, $cache_key, static function () use ($rel) {
			$full = FRL_DIR_PATH . FRL_ICONS_RELATIVE_PATH . $rel;
			if (!file_exists($full) || is_link($full)) {
				return '';
			}
			$raw = file_get_contents($full);
			if ($raw === false || $raw === '') return '';

			// Minimal sanitization: strip scripts and potentially dangerous tags/attrs
			$raw = preg_replace('#<\\s*(script|foreignObject)[^>]*>.*?<\\s*/\\s*\1>#is', '', $raw);
			$raw = preg_replace('/on[a-zA-Z]+\s*=\s*"[^"]*"/i', '', $raw);
			$raw = preg_replace("/on[a-zA-Z]+\s*=\s*'[^']*'/i", '', $raw);
			$raw = preg_replace('/on[a-zA-Z]+\s*=\s*[^\s>]+/i', '', $raw);
			// Remove javascript: and external http(s) hrefs on <use>/<image> and generic href attributes
			$raw = preg_replace('/\s(xlink:href|href)\s*=\s*(["\'])\s*(javascript:[^\2>\s]*|https?:[^\2>\s]*)\2/i', ' ', $raw);
			// Neutralize style url() pointing to javascript: or http(s)
			$raw = preg_replace('/url\(\s*["\']?\s*(javascript:[^\)\s]+)\s*["\']?\s*\)/i', 'none', $raw);
			$raw = preg_replace('/url\(\s*["\']?\s*(https?:[^\)\s]+)\s*["\']?\s*\)/i', 'none', $raw);

			// Ensure it is an <svg>
			if (stripos($raw, '<svg') === false) {
				return '';
			}
			return $raw;
		});

		return is_string($svg) ? $svg : '';
	}

	public static function resolve_src(string $rel): string
	{
		$rel = self::normalize_rel_path($rel);
		return self::is_absolute_url($rel) ? $rel : self::url($rel);
	}

	public static function is_absolute_url(string $s): bool
	{
		return (bool)preg_match('/^https?:\/\//i', $s);
	}

	public static function is_svg_rel(string $rel): bool
	{
		return str_ends_with(strtolower($rel), '.svg');
	}

	/**
	 * Normalize a relative path to icons, rejecting traversal and absolute forms (except http/https).
	 */
	private static function normalize_rel_path(string $rel): string
	{
		$rel = trim($rel);
		if ($rel === '') return '';
		if (self::is_absolute_url($rel)) {
			return $rel;
		}
		if ($rel[0] === '/') {
			$rel = substr($rel, 1);
		}
		$segments = explode('/', $rel);
		$clean = [];
		foreach ($segments as $seg) {
			if ($seg === '' || $seg === '.') continue;
			if ($seg === '..') {
				return '';
			}
			$clean[] = $seg;
		}
		$normalized = implode('/', $clean);
		if (!self::is_absolute_url($normalized) && !self::is_svg_rel($normalized)) {
			return '';
		}
		return $normalized;
	}

	private static function build_icon_filename_class(string $rel): string
	{
		$filename = pathinfo($rel, PATHINFO_FILENAME);
		$slug = preg_replace('/[^a-z0-9_-]+/i', '-', $filename);
		$slug = strtolower(trim($slug, '-'));
		if ($slug === '') return '';
		return 'icon-' . $slug;
	}
}
