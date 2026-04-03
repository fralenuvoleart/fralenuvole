<?php

/**
 * Module Name: GeoDirectory Integration
 * Description: GeoDirectory-specific filters for queries, AJAX output, and custom field translation
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Initialize GeoDirectory integration
 */
function frl_pbproperty_geodir_init(): void
{
    // Guard: Only load if GeoDirectory is active
    if (!function_exists('geodir_get_cf_value')) {
        return;
    }

    // Filter: Query modifications for language-specific listings
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

    // Action: AJAX listings output translation
    frl_hook_add(
        'action',
        'geodir_widget_ajax_listings_after',
        'frl_pbproperty_translate_ajax_listings_output',
        10,
        1
    );

    // Filter: Widget content translation via the_content
    frl_hook_add(
        'filter',
        'the_content',
        'frl_pbproperty_translate_geodir_the_content',
        999,
        1
    );

    // Filter: Custom field output translation for select/multiselect fields (labels, not values)
    if (!empty(FRL_GEODIR_TRANSLATOR_FIELDS)) {
        $field_types = ['select', 'multiselect', 'radio'];
        foreach ($field_types as $type) {
            frl_hook_add(
                'filter',
                "geodir_custom_field_output_{$type}",
                'frl_pbproperty_geodir_translate_option_label',
                20,
                5
            );
        }
    }
}

/**
 * Filter: Restrict widget listings to current language properties
 */
function frl_pbproperty_geodir_filter_widget_listings_where($where, $post_type)
{
    $property_ids = frl_pbproperty_get_properties_ids();

    if (!empty($property_ids)) {
        $properties_by_lang = implode(',', $property_ids);
        $where .= ' AND pbp_posts.ID IN (' . $properties_by_lang . ') ';
    }

    return $where;
}

/**
 * Filter: Restrict search queries to current language properties
 */
function frl_pbproperty_geodir_posts_where($where, $query)
{
    $property_ids = frl_pbproperty_get_properties_ids();

    if (!empty($property_ids)) {
        $properties_by_lang = implode(',', $property_ids);

        if (isset($query->get_queried_object()->post_name) && $query->get_queried_object()->post_name === 'search') {
            $where .= ' AND pbp_posts.ID IN (' . $properties_by_lang . ') ';
        }
    }

    return $where;
}

/**
 * Helper: Get property IDs for current language
 */
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

            foreach ($posts as $post) {
                $post_language = pll_get_post_language($post->ID);

                if ($post_language && $post_language === frl_get_language()) {
                    $post_ids[] = $post->ID;
                }
            }
            return $post_ids;
        }
    );

    return $property_ids;
}

/**
 * Action: Translate GeoDirectory AJAX listings output
 */
function frl_pbproperty_translate_ajax_listings_output($data)
{
    $output = ob_get_contents();

    if (empty($output)) {
        return;
    }

    foreach (PBP_TRANSLATE_STRINGS as $string) {
        $translation = frl_get_translation($string);
        if ($translation !== $string) {
            $output = str_replace($string, $translation, $output);
        }
    }

    ob_clean();
    echo $output;
}

/**
 * Filter: Translate GeoDirectory widget content in the_content
 */
function frl_pbproperty_translate_geodir_the_content($content)
{
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

    foreach (PBP_TRANSLATE_STRINGS as $string) {
        $translation = frl_get_translation($string);
        if ($translation !== $string) {
            $content = str_replace($string, $translation, $content);
        }
    }

    return $content;
}

/**
 * Filter: Translate option labels for select/multiselect/radio fields
 *
 * This translates the DISPLAY LABEL (e.g., "Apartment") not the stored value (e.g., "32")
 */
function frl_pbproperty_geodir_translate_option_label($html, $location, $cf, $p = '', $output = '')
{
    global $gd_post;

    $htmlvar_name = $cf['htmlvar_name'] ?? '';

    // Only process if field is configured for translation
    if (!frl_string_matches_pattern($htmlvar_name, FRL_GEODIR_TRANSLATOR_FIELDS)) {
        return $html;
    }

    // Get the stored value from post
    $stored_value = $gd_post->{$htmlvar_name} ?? '';
    if (empty($stored_value)) {
        return $html;
    }

    // Parse option values to find the label
    if (empty($cf['option_values'])) {
        return $html;
    }

    $options = geodir_string_to_options($cf['option_values'], false);
    if (empty($options) || !is_array($options)) {
        return $html;
    }

    // Find the label for the stored value
    $original_label = '';
    foreach ($options as $option) {
        if (isset($option['value']) && $option['value'] == $stored_value && !empty($option['label'])) {
            $original_label = $option['label'];
            break;
        }
    }

    if (empty($original_label)) {
        return $html;
    }

    // Translate the label
    $translated_label = frl_get_translation($original_label);

    // If translation changed, replace in HTML output
    if ($translated_label !== $original_label) {
        $html = str_replace(
            ['>' . $original_label . '<', '>' . esc_html($original_label) . '<'],
            ['>' . $translated_label . '<', '>' . esc_html($translated_label) . '<'],
            $html
        );
    }

    return $html;
}

// Initialize
frl_pbproperty_geodir_init();
