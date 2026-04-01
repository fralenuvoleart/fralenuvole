<?php

/**
 * ACF Custom Fields module settings
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Add plugin options to the modules tab
 *
 * @param array $fields Existing settings fields
 * @return array Modified settings fields
 */
$frl_acf_default_fields = array(
    // Add a section title to the modules tab
    'section_title_acf' => array(
        'label' => 'ACF Custom Fields Module',
        'type' => 'section_title',
        'description' => 'ACF Custom Fields module settings',
    ),
    'acf_icon_field' => array(
        'label' => 'Enable ACF Icon Field',
        'description' => 'Enable ACF Icon to use icons in ACF fields',
        'type' => 'select',
        'default' => '0',
        'options' => [
            '0' => 'Disabled',
            'span' => 'Render as Span',
            'svg' => 'Render as SVG',
        ],
    ),
    'acf_icon_shortcode' => array(
        'label' => 'Enable ACF Icon Shortcode',
        'description' => 'Enable ACF Icon Shortcode to use icons in posts',
        'type' => 'select',
        'default' => '0',
        'options' => [
            '0' => 'Disabled',
            'span' => 'Render as Span',
            'svg' => 'Render as SVG',
        ],
    )
);
