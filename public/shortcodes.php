<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fralenuvole
 * shortcodes.php - Frontend shortcodes for translation, metadata, and utility.
 */

/**
 * Shortcodes overview
 *
 * - [frl]…[/frl]                    T
 * Translate enclosed content
 * - [frl_lang lang=en]…[/frl_lang]
 * Show content only for a language
 * - [frl_permalink]slug[#anchor][/frl_permalink]
 * Translated permalink
 *   [frl_permalink id=slug|123 anchor=form]
 * Translated permalink via slug or post ID
 *   [frl_permalink] / [frl_permalink anchor=form]
 * Current post permalink (optional anchor)
 * - [frl_slug id=slug|123] or [frl_slug]  Translated slug (from URL, post ID or current post)
 * - [frl_meta field=key select=":first" default=""]
 *   Current post meta value. For complex values (arrays/objects), select allows keys separated by '|'.
 *   Special ':first' returns the first scalar found. default provides a fallback if empty/not found.
 * - [frl_meta_rel field=key output=title|id|permalink|slug index=0|all anchor=#]
 * - [frl_user_meta field=key]     Current author meta (content can provide field)
 * - [frl_category_link]slug|id[/frl_category_link]  Category link
 * - [frl_breadcrumbs separator=/ class=frl-breadcrumbs home=1 current=1]
 * - [frl_langswitcher]              Polylang language switcher
 * - [frl_readtime words_per_min=275 prefix=pre_fix postfix=min read]
 * - [frl_featured size=full]     Featured image URL
 * - [frl_year]                      Current year
 * - [frl_excerpt id=123]          Post excerpt (current post if omitted)
 */

/**
 * Register all public shortcodes and attach filters for content rendering.
 */
function frl_shortcodes_init()
{
    // Process shortcodes BEFORE translation with higher priority (lower number)
    add_filter('render_block',
        'frl_shortcode_render_block_translation',
        10,
        2);
    // Process shortcodes AFTER translation with lower priority (higher number)
    add_filter('render_block',
        'apply_shortcodes',
        20,
        2);

    // Title and description filters
    add_filter('the_title',
        'apply_shortcodes',
        10,
        2);

    add_filter('the_excerpt',
        'apply_shortcodes',
        10,
        2);

    add_filter('the_seo_framework_title_from_generation',
        'apply_shortcodes',
        10,
        2);

    add_filter('the_seo_framework_custom_field_description',
        'frl_shortcode_apply_excerpt',
        10,
        2);

    // Register shortcodes
    add_shortcode('frl', 'frl_shortcode_translation');
    add_shortcode('frl_lang', 'frl_shortcode_language');
    add_shortcode('frl_meta', 'frl_shortcode_meta');
    add_shortcode('frl_meta_rel', 'frl_shortcode_meta_rel');
    add_shortcode('frl_permalink', 'frl_shortcode_permalink');
    add_shortcode('frl_slug', 'frl_shortcode_slug');
    add_shortcode('frl_user_meta', 'frl_shortcode_user_meta');
    add_shortcode('frl_category_link', 'frl_shortcode_category_link');

    // Breadcrumbs
    add_shortcode('frl_breadcrumbs', 'frl_shortcode_breadcrumbs');
    add_shortcode(FRL_PREFIX . '_langswitcher', 'frl_shortcode_langswitcher');
    add_shortcode(FRL_PREFIX . '_readtime', 'frl_shortcode_readtime');
    add_shortcode(FRL_PREFIX . '_featured', 'frl_shortcode_featured_image');
    add_shortcode(FRL_PREFIX . '_year', 'frl_shortcode_current_year');
    // Excerpt output
    add_shortcode(FRL_PREFIX . '_excerpt', 'frl_shortcode_excerpt');
}

/**
 * [frl] - Translates the enclosed content using the translation service.
 *
 * @param array $atts    Shortcode attributes.
 * @param string|null $content Content to be translated.
 * @return string Translated content or empty string.
 */
function frl_shortcode_translation($atts, $content = null)
{
    if (empty($content)) {
        return '';
    }
    return frl_get_translation($content);
}

/**
 * [frl_year] - Returns the current four-digit year.
 *
 * @return string Current year.
 */
function frl_shortcode_current_year()
{
    return date("Y");
}

/**
 * [frl_readtime] - Calculates and renders the estimated reading time of the post.
 *
 * @param array $atts Attributes: words_per_min (int), prefix (string), postfix (string).
 * @return string Formatted reading time HTML.
 */
function frl_shortcode_readtime($atts)
{
    global $post;
    if (!$post) {
        return '';
    }

    $cache_key = "readtime_{$post->ID}_" . md5(serialize($atts));

    return frl_cache_remember('shortcodes', $cache_key, function() use ($post, $atts) {
        $a = shortcode_atts(
            [
                'words_per_min' => 275,
                'prefix' => '',
                'postfix' => __('min read', FRL_NAME)
            ],
            $atts,
            'frl_readtime'
        );

        $content = wp_strip_all_tags($post->post_content);
        if (empty($content)) {
            return '';
        }

        $words = str_word_count($content);
        $mins = max(1, round($words / absint($a['words_per_min'])));

        $prefix = wp_kses_post(frl_get_translation($a['prefix']));
        $postfix = frl_get_translation($a['postfix']);

        return '<span class="reading-time">'
            . ($prefix ? $prefix . ' ' : '')
            . $mins
            . ($postfix ? ' ' . $postfix : '')
            . '</span>';
    });
}

/**
 * [frl_lang] - Displays the enclosed content only if the current language matches the specified one.
 *
 * @param array $atts    Attributes: lang (string).
 * @param string|null $content Content to display.
 * @return string Rendered content or empty string.
 */
function frl_shortcode_language($atts, $content = null)
{
    $a = shortcode_atts(['lang' => ''], $atts);
    if (empty($a['lang']) || empty($content)) {
        return '';
    }
    return $a['lang'] === frl_get_language() ? do_shortcode($content) : '';
}

/**
 * [frl_langswitcher] - Renders the Polylang language switcher as flags or a dropdown.
 *
 * @param array $atts Attributes: exclude (comma-separated slugs).
 * @return string HTML for the language switcher.
 */
function frl_shortcode_langswitcher($atts = [])
{
	// Early exit if multilingual support (Polylang) is not available
	if (!frl_is_multilingual('pll_the_languages')) {
		return '';
	}

	// Arguments for default flags langswitcher
	$args = FRL_LANGSWITCHER_ARGS;

	// Option-based overrides (only when provided)
	$opt_hide_current = frl_get_option('langswitcher_hide_current');
	if (!empty($opt_hide_current)) {
		$args['hide_current'] = absint($opt_hide_current) ? 1 : 0;
	}
	$opt_hide_if_no_translation = frl_get_option('langswitcher_hide_if_no_translation');
	if (!empty($opt_hide_if_no_translation)) {
		$args['hide_if_no_translation'] = absint($opt_hide_if_no_translation) ? 1 : 0;
	}

	// Parse option-based hidden language slugs (merged with shortcode exclude later)
	$opt_hide_langs_raw = (string) frl_get_option('langswitcher_hide_languages');
	$opt_exclude_slugs = [];
	if (trim($opt_hide_langs_raw) !== '') {
		$rows = frl_textlist_to_array($opt_hide_langs_raw);
		if (is_array($rows)) {
			foreach ($rows as $row) {
				if (is_array($row) && isset($row[0])) {
					$slug = sanitize_key(trim((string) $row[0]));
					if ($slug !== '') {
						$opt_exclude_slugs[] = $slug;
					}
				}
			}
		}
		$opt_exclude_slugs = array_values(array_unique($opt_exclude_slugs));
	}

	// Optional shortcode-level exclusions
	$a = shortcode_atts(['exclude' => !empty($opt_exclude_slugs) ? implode(',', $opt_exclude_slugs) : ''], $atts, 'frl_langswitcher');
	$exclude_slugs = array_values(array_filter(array_map('sanitize_key', array_map('trim', explode(',', (string)$a['exclude'])))));

	$dropdown_enabled = (bool) frl_get_option('langswitcher_dropdown');
	$cache_key = 'langswitcher_v2_' . ($dropdown_enabled ? 'dd' : 'fl') . '_post_' . get_queried_object_id() . (empty($exclude_slugs) ? '' : '_x_' . md5(implode(',', $exclude_slugs)));

	return frl_cache_remember('shortcodes', $cache_key, function () use ($args, $exclude_slugs, $dropdown_enabled) {

		// Raw call: get all elements with native names for title attributes.
		// hide_current and hide_if_no_translation are set to 0 — we filter manually below.
		$raw_args = [
			'show_flags'             => 1,
			'show_names'             => 0,
			'display_names_as'       => 'name',
			'echo'                   => 0,
			'raw'                    => 1,
			'hide_current'           => 0,
			'hide_if_no_translation' => 0,
		];

		/** @disregard P1010 Undefined type */
		$elements = pll_the_languages($raw_args);
		if (!is_array($elements) || empty($elements)) {
			return '';
		}

		// Apply hide_current (not in dropdown mode — dropdown always shows current as selected)
		if (!empty($args['hide_current']) && !$dropdown_enabled) {
			$elements = array_filter($elements, function ($el) {
				return empty($el['current_lang']);
			});
		}

		// Apply hide_if_no_translation
		if (!empty($args['hide_if_no_translation'])) {
			$elements = array_filter($elements, function ($el) {
				return empty($el['no_translation']);
			});
		}

		// Apply slug exclusions (from option + shortcode)
		if (!empty($exclude_slugs)) {
			$elements = array_filter($elements, function ($el) use ($exclude_slugs) {
				return !in_array($el['slug'], $exclude_slugs, true);
			});
		}

		if (empty($elements)) {
			return '';
		}

		if ($dropdown_enabled) {
			return frl_langswitcher_build_dropdown($elements);
		}

		return frl_langswitcher_build_list($elements);
	});
}

/**
 * Builds language switcher list HTML from raw Polylang elements.
 *
 * Mirrors PLL_Walker_List output exactly, adding a title attribute to each <a> tag
 * with the native language name for accessibility and SEO.
 *
 * @since 5.8.1
 *
 * @param array $elements Raw language elements from pll_the_languages( [ 'raw' => 1 ] ).
 * @return string HTML <li> items wrapped in <div class="widget_polylang"><ul>…</ul></div>.
 */
function frl_langswitcher_build_list(array $elements): string
{
	$items = '';
	foreach ($elements as $el) {
		$link_atts = sprintf(
			'lang="%1$s" hreflang="%1$s" href="%2$s"',
			esc_attr($el['locale']),
			esc_url($el['url'])
		);

		if (!empty($el['link_classes'])) {
			$link_atts .= sprintf(' class="%s"', esc_attr(implode(' ', $el['link_classes'])));
		}
		if (!empty($el['current_lang'])) {
			$link_atts .= ' aria-current="true"';
		}
		if (!empty($el['name'])) {
			$link_atts .= sprintf(' title="%s"', esc_attr($el['name']));
		}

		$items .= sprintf(
			"\t<li class=\"%1\$s\"><a %2\$s>%3\$s</a></li>\n",
			esc_attr(implode(' ', $el['classes'])),
			$link_atts,
			$el['flag'] // Pre-rendered flag HTML from Polylang
		);
	}

	return sprintf('<div class="widget_polylang"><ul>%s</ul></div>', $items);
}

/**
 * Builds language switcher dropdown HTML from raw Polylang elements.
 *
 * Mirrors PLL_Walker_Dropdown output exactly, including the inline <script> for
 * language switching. Option text displays the language slug (matching the current
 * display_names_as='slug' behavior). Each <option> receives a title attribute
 * with the native language name.
 *
 * @since 5.8.1
 *
 * @param array $elements Raw language elements from pll_the_languages( [ 'raw' => 1 ] ).
 * @return string HTML <select> element with inline <script>.
 */
function frl_langswitcher_build_dropdown(array $elements): string
{
	$name = 'lang_choice_1';

	$options = '';
	foreach ($elements as $el) {
		$data_lang = wp_json_encode([
			'id'   => $el['id'],
			'name' => $el['name'],
			'slug' => $el['slug'],
			'dir'  => $el['is_rtl'] ?? '',
		]);

		$selected   = !empty($el['current_lang']) ? ' selected="selected"' : '';
		$lang_attr  = !empty($el['locale']) ? sprintf(' lang="%s"', esc_attr($el['locale'])) : '';

		$options .= sprintf(
			"\t" . '<option value="%1$s"%2$s%3$s data-lang="%4$s" title="%5$s">%6$s</option>' . "\n",
			esc_url($el['url']),
			$lang_attr,
			$selected,
			esc_html($data_lang),
			esc_attr($el['name']),
			esc_html($el['slug'])
		);
	}

	$output = sprintf(
		'<select name="%1$s" id="%1$s" class="pll-switcher-select">' . "\n" . '%2$s' . "\n" . '</select>' . "\n",
		esc_attr($name),
		$options
	);

	$output .= sprintf(
		'<script%1$s>
					document.getElementById( "%2$s" ).addEventListener( "change", function ( event ) { location.href = event.currentTarget.value; } )
				</script>',
		current_theme_supports('html5', 'script') ? '' : ' type="text/javascript"',
		esc_js($name)
	);

	return $output;
}

/**
 * [frl_featured] - Returns the URL of the post's featured image.
 *
 * @param array $atts Attributes: size (string, default 'full').
 * @return string Image URL or empty string.
 */
function frl_shortcode_featured_image($atts)
{
    global $post;
    if (!$post) {
        return '';
    }

    $size = shortcode_atts(['size' => 'full'], $atts)['size'];
    $cache_key = "featured_{$post->ID}_{$size}";

    return frl_cache_remember('shortcodes', $cache_key, function () use ($post, $size) {
        $url = get_the_post_thumbnail_url($post->ID, $size);
        return $url ? esc_url($url) : '';
    });
}

/**
 * [frl_meta] - Displays a custom field value for the current post.
 *
 * @param array $atts Attributes: field (required), select (selector for complex values), default (fallback).
 * @return string Meta value or default.
 */
function frl_shortcode_meta($atts)
{
    global $post;
    if (!$post || !is_a($post, 'WP_Post')) {
        return '';
    }
    $a = shortcode_atts(['field' => '', 'select' => ':first', 'default' => ''], $atts, 'frl_meta');
    $meta_key = sanitize_key($a['field']);
    if (empty($meta_key)) {
        return '';
    }

    // Normalize selectors
    $selectors = array_values(array_filter(array_map('trim', explode('|', (string)$a['select'])), function ($s) { return $s !== ''; }));
    if (empty($selectors)) { $selectors = [':first']; }

    $default_value = (string) $a['default'];
    $cache_key = 'meta_' . $post->ID . '_' . $meta_key . '_' . md5(implode('|', $selectors) . '|' . $default_value);
    return frl_cache_remember('shortcodes', $cache_key, function () use ($post, $meta_key, $selectors, $default_value) {
        $raw = frl_get_post_meta($post->ID, $meta_key, true);
        $value = frl_coerce_to_string($raw, $selectors);
        if ($value === '') {
            return $default_value;
        }
        return do_shortcode($value);
    });
}

/**
 * [frl_meta_rel] - Displays values from a relational meta field (array of post IDs).
 *
 * @param array $atts Attributes: field (required), index (0 or 'all'), output ('title'|'id'|'permalink'|'slug'), sep (separator), anchor (fragment), id (source post ID).
 * @return string Rendered relational data.
 */
function frl_shortcode_meta_rel($atts)
{
    global $post;

    $a = shortcode_atts([
        'field'  => '',
        'index'  => '0',   // 0-based index or 'all'
        'output' => 'title', // 'title' | 'id' | 'permalink' | 'slug'
        'sep'    => ', ',
        'anchor' => '',     // '' | 'cpt'|'service'|'current' | literal string
        'id'     => '',     // Optional: source post ID for reading meta
    ], $atts, 'frl_meta_rel');

    $field = sanitize_key($a['field']);
    if (empty($field)) {
        return '';
    }

    // Determine source post ID
    $target_post_id = 0;
    if ($a['id'] !== '' && ctype_digit((string) $a['id'])) {
        $target_post_id = (int) $a['id'];
    } else {
        $target_post_id = frl_get_current_post_id();
    }
    if ($target_post_id <= 0) {
        return '';
    }

    $cache_key = 'meta_rel_' . $target_post_id . '_' . $field . '_' . md5(serialize([
        (string) $a['index'], (string) $a['output'], (string) $a['sep'], (string) $a['anchor']
    ]));

    return frl_cache_remember('shortcodes', $cache_key, function () use ($target_post_id, $field, $a, $post) {
        $raw = frl_get_post_meta($target_post_id, $field, true);
        if (empty($raw)) {
            return '';
        }

        $ids = is_array($raw) ? $raw : (array) $raw;
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($v) {
            return $v > 0;
        })));
        if (empty($ids)) {
            return '';
        }

        $output = sanitize_key($a['output']);
        $sep    = (string) $a['sep'];
        $wrapWithSpan = (strtolower(trim($sep)) === 'span');

        // Prepare optional anchor fragment
        $anchorFragment = '';
        $anchorRaw = trim((string) $a['anchor']);
        if ($output === 'permalink' && $anchorRaw !== '') {
            $anchorKey = strtolower($anchorRaw);
            if (in_array($anchorKey, ['cpt', 'service', 'current', 'post'], true)) {
                if (is_object($post) && isset($post->ID)) {
                    $slug = (string) get_post_field('post_name', (int) $post->ID);
                    if ($slug !== '') {
                        $anchorFragment = '#' . sanitize_title($slug);
                    }
                }
            } else {
                $anchorFragment = '#' . sanitize_title($anchorRaw);
            }
        }

        $render_one = function (int $id) use ($output, $anchorFragment) {
            if ($output === 'id') {
                return (string) $id;
            }
            if ($output === 'slug') {
                $slug = (string) get_post_field('post_name', $id);
                return $slug !== '' ? esc_html($slug) : '';
            }
            if ($output === 'permalink') {
                $url = get_permalink($id);
                if (!$url) {
                    return '';
                }
                // Append anchor if provided
                if ($anchorFragment !== '' && strpos($url, '#') === false) {
                    $url .= $anchorFragment;
                }
                return esc_url($url);
            }
            $title = get_the_title($id);
            return $title !== '' ? esc_html($title) : '';
        };

        $index_raw = strtolower((string) $a['index']);
        if ($index_raw === 'all') {
            $pieces = [];
            foreach ($ids as $id) {
                $val = $render_one($id);
                if ($val !== '') {
                    $pieces[] = $wrapWithSpan ? ('<span>' . $val . '</span>') : $val;
                }
            }
            if (!$pieces) {
                return '';
            }
            return $wrapWithSpan ? implode('', $pieces) : implode($sep, $pieces);
        }

        $i = (int) $a['index'];
        if ($i < 0 || $i >= count($ids)) {
            return '';
        }
        $single = $render_one($ids[$i]);
        if ($single === '') {
            return '';
        }
        return $wrapWithSpan ? ('<span>' . $single . '</span>') : $single;
    });
}

/**
 * [frl_user_meta] - Displays metadata for the post author.
 *
 * @param array $atts    Attributes: field (meta key), select (selector), default (fallback).
 * @param string|null $content Optional field name provided as content.
 * @return string User meta value.
 */
function frl_shortcode_user_meta($atts, $content = null)
{
    $field_from_content = trim(wp_strip_all_tags($content ?? ''));
    $a = shortcode_atts(['field' => '', 'select' => ':first', 'default' => ''], $atts, 'frl_user_meta');

    $field = !empty($a['field']) ? $a['field'] : $field_from_content;
    if ($field === '') {
        $field = 'display_name';
    }

    $raw = get_the_author_meta(sanitize_key($field));
    $selectors = array_values(array_filter(array_map('trim', explode('|', (string)$a['select'])), function ($s) { return $s !== ''; }));
    if (empty($selectors)) { $selectors = [':first']; }
    $value = frl_coerce_to_string($raw, $selectors);

    if ($value === '') {
        return esc_html((string)$a['default']);
    }
    return esc_html($value);
}

/**
 * [frl_excerpt] - Displays the excerpt of a specified or current post.
 *
 * @param array $atts Attributes: id (post ID).
 * @return string Post excerpt.
 */
function frl_shortcode_excerpt($atts)
{
    global $post;

    $a = shortcode_atts(['id' => 0], $atts, 'frl_excerpt');

    // Determine target post ID
    $post_id = absint($a['id']);
    if (!$post_id) {
        $post_id = frl_get_current_post_id();
    }

    if (!$post_id) {
        return '';
    }

    $cache_key = "excerpt_post_{$post_id}";

    return frl_cache_remember('shortcodes', $cache_key, function () use ($post_id) {
        $excerpt = trim(get_the_excerpt($post_id));
        return $excerpt ? apply_shortcodes($excerpt) : '';
    });
}

/**
 * [frl_permalink] - Returns a translated permalink for a given slug or post ID.
 *
 * @param array $atts    Attributes: id (slug or ID), anchor (fragment).
 * @param string|null $content Optional slug provided as content.
 * @return string Translated URL.
 */
function frl_shortcode_permalink($atts, $content = null)
{
	$a = shortcode_atts(['id' => '', 'anchor' => ''], $atts, 'frl_permalink');

	// Backward-compatible: prefer content when provided; otherwise use id attribute
	$raw = '';
	if (!empty($content)) {
		$raw = trim(wp_strip_all_tags($content));
	} elseif (!empty($a['id'])) {
		$raw = trim((string) $a['id']);
	} elseif (!empty($a['id']) && ctype_digit((string) $a['id'])) {
		$post_id = (int) $a['id'];
		if ($post_id <= 0) {
			return '#';
		}
		$slug = (string) get_post_field('post_name', $post_id);
		$key_slug = $slug !== '' ? $slug : ('post-' . $post_id);
		$curr_cache_key = 'permalink_' . sanitize_key($key_slug);
		$permalink = frl_cache_remember('shortcodes', $curr_cache_key, function () use ($post_id) {
			$url = get_permalink($post_id);
			return $url ? $url : '#';
		});
		if (!empty($a['anchor']) && !str_contains($permalink, '#')) {
			$permalink .= '#' . sanitize_title((string) $a['anchor']);
		}
		return esc_url($permalink);
	} else {
        // Default: current post permalink
        $post_id = frl_get_current_post_id();
		if ($post_id <= 0) {
			return '#';
		}
		// Unify cache key naming with slug-based lookups: use slug when available
		$slug = (string) get_post_field('post_name', $post_id);
		$key_slug = $slug !== '' ? $slug : ('post-' . $post_id);
		$curr_cache_key = 'permalink_' . sanitize_key($key_slug);
		$permalink = frl_cache_remember('shortcodes', $curr_cache_key, function () use ($post_id) {
			$url = get_permalink($post_id);
			return $url ? $url : '#';
		});
		if (!empty($a['anchor']) && !str_contains($permalink, '#')) {
			$permalink .= '#' . sanitize_title((string) $a['anchor']);
		}
		return esc_url($permalink);
	}

	$link_parts = explode('#', $raw, 2);
	$slug_part = $link_parts[0];

	$cache_key = frl_build_cache_key($slug_part, 'permalink');
	$permalink = frl_cache_remember('shortcodes', $cache_key, function () use ($slug_part) {
		return frl_get_translation_permalink($slug_part);
	});

	if (isset($link_parts[1]) && $link_parts[1] !== '') {
		$permalink = frl_sc_append_anchor($permalink, $link_parts[1]);
	} elseif (!empty($a['anchor'])) {
		$permalink = frl_sc_append_anchor($permalink, (string) $a['anchor']);
	}
	return esc_url($permalink);
}

/**
 * [frl_slug] - Returns the translated slug of a post.
 *
 * @param array $atts Attributes: id (slug or post ID).
 * @return string Translated slug.
 */
function frl_shortcode_slug($atts)
{
    $a = shortcode_atts(['id' => ''], $atts, 'frl_slug');
    $id_attr_raw = trim((string) $a['id']);
    $pid_attr = 0;

    // Single id attribute can be numeric (post ID) or slug
    if ($id_attr_raw !== '' && ctype_digit($id_attr_raw)) {
        $pid_attr = (int) $id_attr_raw;
        $id_attr_raw = '';
    }

    if ($pid_attr > 0) {
        $slug = (string) get_post_field('post_name', $pid_attr);
        if ($slug === '') {
            return '';
        }
        $curr_cache_key = frl_build_cache_key($slug, 'slug');
        return frl_cache_remember('shortcodes', $curr_cache_key, function () use ($slug) {
            return esc_html($slug);
        });
    }

    if ($id_attr_raw === '') {
        // Default: return current post slug
        $post_id = frl_get_current_post_id();
        if ($post_id > 0) {
            $slug = (string) get_post_field('post_name', $post_id);
            if ($slug === '') {
                return '';
            }
            $curr_cache_key = frl_build_cache_key($slug, 'slug');
            return frl_cache_remember('shortcodes', $curr_cache_key, function () use ($slug) {
                return esc_html($slug);
            });
        }
        return '';
    }

    $slug_to_translate = $id_attr_raw;

    // Early return if current language is the default: input slug is already correct
    $default_lang = frl_get_default_language();
    $current_lang = frl_get_language();

    if ($current_lang === $default_lang) {
        return esc_html($slug_to_translate);
    }

    // Use a hash for hierarchical paths to avoid collisions (slashes get stripped by sanitize_key)
    $cache_key = frl_build_cache_key($slug_to_translate, 'slug');

    $translated_slug = frl_cache_remember('shortcodes', $cache_key, function () use ($slug_to_translate) {
        $url = frl_get_translation_permalink($slug_to_translate);
        if ($url === '' || $url === '#') {
            // Fallback: search hierarchical posts by name when only child segment is provided
            $lang = frl_get_language();
            $posts = get_posts([
                'post_type' => get_post_types(['public' => true, 'hierarchical' => true]),
                'name' => $slug_to_translate,
                'post_status' => 'publish',
                'numberposts' => 1,
                'lang' => $lang,
            ]);
            if (!empty($posts)) {
                $url = get_permalink($posts[0]->ID) ?: '';
            }
            if ($url === '' || $url === '#') {
                return '';
            }
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        if ($path === '') {
            $path = $url;
        }
        $path = rtrim($path, '/');
        if ($path === '') {
            return '';
        }
        $segments = explode('/', $path);
        $last = (string) end($segments);
        if ($last === '') {
            return '';
        }
        $segment = trim(rawurldecode($last), '/');
        if ($segment === '') {
            return '';
        }
        return esc_html($segment);
    });

    return $translated_slug !== '' ? esc_html($translated_slug) : '';
}

/**
 * [frl_category_link] - Returns the translated permalink for a category.
 *
 * @param array $atts    Shortcode attributes.
 * @param string|null $content Category slug or ID.
 * @return string Category URL.
 */
function frl_shortcode_category_link($atts, $content = null)
{
    if (empty($content)) {
        return '';
    }
    $identifier = trim(wp_strip_all_tags($content));
    $cache_key = "cat_link_" . sanitize_key($identifier);

    return frl_cache_remember('shortcodes', $cache_key, function () use ($identifier) {
        $term_id = 0;
        if (frl_is_multilingual('icl_object_id')) {
            $lang = frl_get_language();
            if (is_numeric($identifier)) {
                $term_id = icl_object_id((int) $identifier, 'category', true, $lang);
            } else {
                $term = get_term_by('slug', sanitize_title($identifier), 'category');
                if ($term) {
                    $term_id = icl_object_id($term->term_id, 'category', true, $lang);
                }
            }
        } elseif (is_numeric($identifier)) {
            $term_id = (int) $identifier;
        } else {
            $term = get_term_by('slug', sanitize_title($identifier), 'category');
            $term_id = $term ? $term->term_id : 0;
        }

        if ($term_id && term_exists($term_id, 'category')) {
            return esc_url(get_category_link($term_id));
        }
        return '';
    });
}

/**
 * [frl_breadcrumbs] - Renders a breadcrumb trail for the current page.
 *
 * @param array $atts Attributes: separator, class, home (bool), current (bool).
 * @return string Breadcrumbs HTML.
 */
function frl_shortcode_breadcrumbs($atts)
{
    if (is_front_page()) {
        return '';
    }

    $a = shortcode_atts([
        'separator' => ' / ',
        'class'     => 'frl-breadcrumbs',
        'home'      => '1', // 1 to show Home link, 0 to hide
        'current'   => '1', // 1 to show current item, 0 to hide
    ], $atts);

    $show_home    = filter_var($a['home'], FILTER_VALIDATE_BOOLEAN);
    $show_current = filter_var($a['current'], FILTER_VALIDATE_BOOLEAN);

    // Cache key must vary with object + language.
    $object_id = get_queried_object_id();

    $cache_key = 'breadcrumbs_' . $object_id . '_' .
             md5( serialize( [ $a['separator'], $a['class'], $show_home, $show_current ] ) );

    return frl_cache_remember('shortcodes', $cache_key, function () use ($a, $show_home, $show_current) {
        $links = [];

        // Home link (optional)
        if ($show_home) {
            $links[] = sprintf('<a href="%s">%s</a>', esc_url(home_url('/')), esc_html__('Home', FRL_NAME));
        }

        if (is_singular()) {
            $post = get_queried_object();
            if (!$post) {
                return '';
            }

            $ancestors = get_post_ancestors($post);
            $ancestors = array_reverse($ancestors);

 			// Insert first jurisdiction (if any) only for the 'service' CPT
			if (get_post_type($post) === 'service') {
				$jur_id_raw = frl_shortcode_meta_rel([
					'field' => 'jurisdiction',
					'output' => 'id'
				]);
				$first_jur_id = (int) trim((string) $jur_id_raw);
				if ($first_jur_id > 0 && !in_array($first_jur_id, $ancestors, true)) {
					$jur_title = get_the_title($first_jur_id);
					$jur_link  = get_permalink($first_jur_id);
					if ($jur_title && $jur_link) {
						$links[] = sprintf('<a href="%s">%s</a>', esc_url($jur_link), esc_html($jur_title));
					}
				}
			}

			// Prepend the 'jurisdictions' page for the 'jurisdiction' CPT
			if (get_post_type($post) === 'jurisdiction') {
                $pg_title = frl_get_translation('Jurisdictions');
                $pg_link = frl_get_translation_permalink('jurisdictions');
                if ($pg_link && $pg_title) {
                    $links[] = sprintf('<a href="%s">%s</a>', esc_url($pg_link), esc_html($pg_title));
                }
			}

           foreach ($ancestors as $ancestor_id) {
                $links[] = sprintf('<a href="%s">%s</a>', esc_url(get_permalink($ancestor_id)), esc_html(get_the_title($ancestor_id)));
            }

            // Current item (optional)
            if ($show_current) {
                $links[] = esc_html(get_the_title($post));
            }

        } elseif (is_category() || is_tag() || is_tax()) {
            $term = get_queried_object();
            if (!$term) {
                return '';
            }

            $ancestors = get_ancestors($term->term_id, $term->taxonomy);
            $ancestors = array_reverse($ancestors);
            foreach ($ancestors as $ancestor_id) {
                $links[] = sprintf('<a href="%s">%s</a>', esc_url(get_term_link($ancestor_id, $term->taxonomy)), esc_html(get_term_field('name', $ancestor_id, $term->taxonomy)));
            }

            if ($show_current) {
                $links[] = esc_html($term->name);
            }
        }

        // Fallback for other views (archives, etc.) – just home
        if (count($links) <= 1 && $show_home) {
            return '';
        }

        return sprintf('<nav class="%s">%s</nav>', esc_attr($a['class']), implode(esc_html($a['separator']), $links));
    });
}

/**
 * Helper to process shortcodes within an SEO excerpt.
 *
 * @param string $description The excerpt text.
 * @return string Processed text.
 */
function frl_shortcode_apply_excerpt($description)
{
    return apply_shortcodes($description);
}

/**
 * Processes block content by applying translation and then evaluating shortcodes.
 *
 * @param string $block_content The raw block content.
 * @param mixed  $block         The block object.
 * @return string Processed content.
 */
function frl_shortcode_render_block_translation($block_content, $block)
{
    $translated_content = frl_get_translation_block($block_content, $block);
    return apply_shortcodes($translated_content);
}

/**
 * Appends a sanitized anchor fragment to a URL if not already present.
 *
 * @param string $url    The base URL.
 * @param string $anchor The anchor text.
 * @return string URL with anchor.
 */
function frl_sc_append_anchor(string $url, string $anchor): string
{
    if ($anchor === '' || str_contains($url, '#')) {
        return $url;
    }
    return $url . '#' . sanitize_title($anchor);
}

/**
 * Generates a standardized cache key for shortcode results.
 *
 * @param string $key  The base identifier.
 * @param string $type The key type (e.g., 'slug').
 * @return string Formatted cache key.
 */
function frl_build_cache_key(string $key, string $type = 'slug'): string
{
    if ($type === 'slug') {
        return 'slug_' . md5($key);
    }
    return $type . '_' . sanitize_key($key);
}