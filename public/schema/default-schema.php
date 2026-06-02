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
    ],
    'Service' => [
        'publisher' => [
            '@type' => 'Organization',
            '@id' => '{{site_url}}#Organization',
        ],
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
