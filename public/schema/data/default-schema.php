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
        'logo' => [
            '@type' => 'ImageObject',
            'url' => '{{custom_logo}}',
            'width' => '150',
            'height' => '40',
        ],
        'address' => [
            '@type' => 'PostalAddress',
            'addressCountry' => 'GE',
            'streetAddress' => 'Zakaria Paliashvili Street 26',
            'addressLocality' => 'Tbilisi',
            'postalCode' => '0179',
        ],
        'areaServed' => [
            '@type' => 'AdministrativeArea',
            'name' => 'Worldwide',
        ],
        'contactPoint' => [
            'availableLanguage' => ['en', 'ru', 'ka', 'ar', 'zh'],
        ],
        'foundingLocation' => [
            '@type' => 'Place',
            'name' => 'Georgia',
        ],
       'knowsAbout' => [
            'https://en.wikipedia.org/wiki/Company_formation',
            'https://en.wikipedia.org/wiki/Sole_proprietorship',
            'https://en.wikipedia.org/wiki/Small_business',
            'https://en.wikipedia.org/wiki/Corporation',
            'https://en.wikipedia.org/wiki/Special_economic_zone',
            'https://en.wikipedia.org/wiki/Bank_account',
            'https://en.wikipedia.org/wiki/Corporate_tax',
            'https://en.wikipedia.org/wiki/Tax_incentive',
            'https://en.wikipedia.org/wiki/Tax_residence',       
            'https://en.wikipedia.org/wiki/Accounting',
            'https://en.wikipedia.org/wiki/Outsourcing',
            'https://en.wikipedia.org/wiki/Legal_services',
            'https://en.wikipedia.org/wiki/Notary_public',
            'https://en.wikipedia.org/wiki/Apostille_Convention',
            'https://en.wikipedia.org/wiki/Travel_visa',
            'https://en.wikipedia.org/wiki/Work_permit',
            'https://en.wikipedia.org/wiki/Residence_permit',
            'https://en.wikipedia.org/wiki/Taxation_in_Georgia_(country)',
            'https://en.wikipedia.org/wiki/Expatriate',
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
