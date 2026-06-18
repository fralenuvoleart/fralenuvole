<?php

/**
 * Module Name: PB Property Module
 * Description: Compatibility for Polylang and Geodirectory
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/config-constants-pbproperty.php';

// Load GeoDirectory-specific integration
require_once __DIR__ . '/geodirectory.php';

// General search redirect by language
add_action("template_redirect",
    "frl_pbproperty_redirect_search_by_language",
    5,
    0);

// Register GeoDirectory CPT with Polylang
add_filter("pll_get_post_types",
    "add_cpt_to_pll",
    10,
    2);

// Block translation filter (shared with GeoDirectory output)
add_filter('frl_block_translation_filter',
    'frl_pbproperty_block_translation_filter');

function add_cpt_to_pll($post_types, $is_settings)
{
    $post_types['gd_place'] = 'gd_place';
    return $post_types;
}

function frl_pbproperty_redirect_search_by_language()
{
    $current_slug = get_query_var('name') ?: get_post_field('post_name', get_queried_object_id());

    if ($current_slug) {
        foreach (PBP_SEARCH_SLUGS as $lang => $search_slug) {
            if (str_contains($current_slug, $search_slug . '-' . $lang)) {
                $localized_url = '/' . $lang . '/' . $search_slug;
                wp_redirect(home_url($localized_url), 302);
                exit;
            }
        }

        if (in_array($current_slug, PBP_SEARCH_SLUGS)) {
            $referer = frl_wp_get_referer();
            $source_lang = null;

            if ($referer) {
                foreach (PBP_SEARCH_SLUGS as $lang => $slug) {
                    if (str_contains($referer, '/' . $lang . '/')) {
                        $source_lang = $lang;
                        break;
                    }
                }
            }

            if ($source_lang && !str_contains($_SERVER['REQUEST_URI'], '/' . $source_lang . '/')) {
                $localized_url = '/' . $source_lang . $_SERVER['REQUEST_URI'];
                wp_redirect(home_url($localized_url), 302);
                exit;
            }
        }
    }
}

/**
 * Block translation filter for hardcoded strings
 */
function frl_pbproperty_block_translation_filter($content)
{
    $has_translate_class = false;
    foreach (PBP_TRANSLATE_CLASSES as $class) {
        if (str_contains($content, $class)) {
            $has_translate_class = true;
            break;
        }
    }
    if (!$has_translate_class) {
        return $content;
    }

    foreach (PBP_TRANSLATE_STRINGS as $string) {
        $translation = frl_get_translation($string);
        if ($translation !== $string) {
            $content = str_replace($string, $translation, $content);
        }
    }

    return $content;
}
