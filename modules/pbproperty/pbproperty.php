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

frl_hook_add(
    'filter',
    'geodir_filter_widget_listings_where',
    'frl_pbproperty_geodir_filter_widget_listings_where',
    10,
    2,
    'core',
    false
);

frl_hook_add(
    'filter',
    'geodir_posts_where',
    'frl_pbproperty_geodir_posts_where',
    10,
    2,
    'core',
    false
);

frl_hook_add(
    "action",
    "template_redirect",
    "frl_pbproperty_redirect_search_by_language",
    5,
    0
);

frl_hook_add(
    "filter",
    "pll_get_post_types",
    "add_cpt_to_pll",
    10,
    2
);

// Add a direct filter to add your modules field
frl_hook_add(
    'filter',
    'frl_block_translation_filter',
    'frl_pbproperty_block_translation_filter'
);

// Hook into GeoDirectory AJAX listings output to translate custom field values
frl_hook_add(
    'action',
    'geodir_widget_ajax_listings_after',
    'frl_pbproperty_translate_ajax_listings_output',
    10,
    1
);

// Hook into the_content to translate GeoDirectory widget output on search pages
frl_hook_add(
    'filter',
    'the_content',
    'frl_pbproperty_translate_geodir_the_content',
    999,
    1
);

function add_cpt_to_pll( $post_types, $is_settings ) {
    // enables language and translation management for 'my_cpt'
    $post_types['gd_place'] = 'gd_place';
    return $post_types;
}

/**
 * Filter geodirectory queries
 * @return string
 */
function frl_pbproperty_geodir_posts_where($where, $query)
{
    $property_ids = frl_pbproperty_get_properties_ids();

    if(!empty($property_ids)) {
        $properties_by_lang = implode(',', $property_ids);

        if(isset($query->get_queried_object()->post_name) && $query->get_queried_object()->post_name === 'search') {
            $where .= ' AND pbp_posts.ID IN (' . $properties_by_lang . ') ';
        }
    }

    return $where;
}

/**
 * Filter geodirectory queries
 * @return string
 */
function frl_pbproperty_geodir_filter_widget_listings_where($where, $post_type)
{
    $property_ids = frl_pbproperty_get_properties_ids();

    if(!empty($property_ids)) {
        $properties_by_lang = implode(',', $property_ids);
        $where .= ' AND pbp_posts.ID IN (' . $properties_by_lang . ') ';
    }

    return $where;
}

function frl_pbproperty_get_properties_ids()
{
    $property_ids = frl_cache_remember(
        'blocks',
        'places_by_lang',
        function () {
            $posts = get_posts(
                array(
                    'post_type' => 'gd_place',
                    'posts_per_page' => -1
                )
            );

            $post_ids = array();

            foreach($posts as $post) {
                $post_language = pll_get_post_language($post->ID);

                if($post_language && $post_language === frl_get_language()) {
                    $post_ids[] = $post->ID;
                }
            }
            return $post_ids;
        }
    );

    return $property_ids;
}

function frl_pbproperty_redirect_search_by_language()
{
    // Only process if this is a search page (check slug against PBP_SEARCH_SLUGS)

    $current_slug = get_query_var('name') ?: get_post_field('post_name', get_queried_object_id());

    if ($current_slug) {

        foreach (PBP_SEARCH_SLUGS as $lang => $search_slug) {
            if (str_contains($current_slug, $search_slug .'-' . $lang)) {
                $localized_url = '/' . $lang . '/' . $search_slug;
                wp_redirect(home_url($localized_url), 302);
                exit;
            }
        }

        if (in_array($current_slug, PBP_SEARCH_SLUGS)) {

            // Check referer to determine source language
            $referer = wp_get_referer();
            $source_lang = null;

            if ($referer) {
                // Check if referer contains any of the configured language slugs
                foreach (PBP_SEARCH_SLUGS as $lang => $slug) {
                    if (str_contains($referer, '/' . $lang . '/')) {
                        $source_lang = $lang;
                        break;
                    }
                }
            }

            // If source language found and current URL doesn't match that language
            if ($source_lang && !str_contains($_SERVER['REQUEST_URI'], '/' . $source_lang . '/')) {
                $localized_url = '/' . $source_lang . $_SERVER['REQUEST_URI'];
                wp_redirect(home_url($localized_url), 302);
                exit;
            }
        }
    }
}

/**
 * Add plugin options to the modules tab
 *
 * @param array $fields Existing settings fields
 * @return array Modified settings fields
 */
function frl_pbproperty_block_translation_filter($content)
{
    // Check if any of the translate classes exist in content
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
        if($translation !== $string) {
            $content = str_replace($string, $translation, $content);
        }
    }

    return $content;
}

/**
 * Translate GeoDirectory AJAX listings output.
 * Hooks into geodir_widget_ajax_listings_after to translate custom field values
 * in AJAX-loaded results (e.g., location filter).
 *
 * @param array $data Widget data passed by GeoDirectory
 * @return void
 */
function frl_pbproperty_translate_ajax_listings_output($data)
{
    // Get current output buffer contents before it's captured
    $output = ob_get_contents();

    if (empty($output)) {
        return;
    }

    // Apply same translation logic as the block filter
    foreach (PBP_TRANSLATE_STRINGS as $string) {
        $translation = frl_get_translation($string);
        if ($translation !== $string) {
            $output = str_replace($string, $translation, $output);
        }
    }

    // Clear the buffer and echo translated content
    ob_clean();
    echo $output;
}
/**
 * Translate GeoDirectory widget content in the_content filter.
 * Restricted to content containing GeoDirectory widget markers.
 *
 * @param string $content The content to filter
 * @return string Translated content
 */
function frl_pbproperty_translate_geodir_the_content($content)
{
    // Only process if content contains GeoDirectory widget markers
    $is_geodir_widget = false;
    foreach (PBP_GEODIR_MARKERS as $marker) {
        if (str_contains($content, $marker)) {
            $is_geodir_widget = true;
            break;
        }
    }

    if (!$is_geodir_widget) {
        return $content;
    }

    // Apply same translation logic as the block filter
    foreach (PBP_TRANSLATE_STRINGS as $string) {
        $translation = frl_get_translation($string);
        if ($translation !== $string) {
            $content = str_replace($string, $translation, $content);
        }
    }

    return $content;
}

