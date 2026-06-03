<?php
/**
 * Default Schema Properties Data
 *
 * Pure data file — no function calls. Dynamic values use placeholders:
 *   {{site_url}} → resolved to site_url() at runtime
 *
 * Translatable strings are resolved via frl_get_translation() in the resolver.
 * Structural keys (@type, @id) are excluded from translation.
 *
 * @package Fralenuvole
 */

if (!defined('ABSPATH')) {
    exit;
}

return [
    'Organization' => [
        'address' => [
            '@type' => 'PostalAddress',
            'addressCountry' => 'GE',
            'streetAddress' => 'Zakaria Paliashvili Street 26',
            'addressLocality' => 'Tbilisi',
            'postalCode' => '0179',
        ],
        'areaServed' => 'Worldwide',
        'logo' => [
            '@type' => 'ImageObject',
            'url' => '{{custom_logo}}',
            'width' => '150',
            'height' => '40',
       ],
    ],
    'Service' => [
        'provider' => [
            '@type' => 'Organization',
            '@id' => '{{site_url}}#Organization',
        ],
        'publisher' => '_remove',
    ],
    'WebSite' => [
        'publisher' => [
            '@type' => 'Organization',
            '@id' => '{{site_url}}#Organization',
        ],
    ],
    'WebPage' => [
        'publisher' => [
            '@type' => 'Organization',
            '@id' => '{{site_url}}#Organization',
        ],
        'reviewedBy' => [
            '@type' => 'Organization',
            '@id' => '{{site_url}}#Organization',
        ],
    ],
    'AboutPage' => [
        'publisher' => [
            '@type' => 'Organization',
            '@id' => '{{site_url}}#Organization',
        ],
        'mainEntity' => [
            '@type' => 'Organization',
            '@id' => '{{site_url}}#Organization',
        ],
    ],
    'ContactPage' => [
        'publisher' => [
            '@type' => 'Organization',
            '@id' => '{{site_url}}#Organization',
        ],
        'mainEntity' => [
            '@type' => 'Organization',
            '@id' => '{{site_url}}#Organization',
        ],
    ],
];
