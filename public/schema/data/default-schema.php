<?php
/**
 * Default Schema Properties Data
 *
 * Pure data file — no function calls. Dynamic values use placeholders:
 *   {{site_url}}          → resolved to site_url() at runtime
 *   {{site_url_local}}    → resolved to current-language homepage via frl_get_home_url()
 *   {{custom_logo}}       → resolved to the site logo URL
 *   {{organization_url}}  → resolved from env plugin option 'schema_organization_url'
 *   {{organization_name}} → resolved from env plugin option 'schema_organization_name'
 *   {{schema_founder_name}} → resolved from env plugin option 'schema_founder_name'
 *   {{post_title}}        → resolved at injection time to get_the_title($post_id)
 *
 * Translation is controlled by FRL_SCHEMA_TRANSLATE_KEYS in config-schema.php.
 * Only explicitly listed keys are translated; all others are kept as-is.
 * Use '_remove' as a value to strip a key from the output.
 *
 * @package Fralenuvole
 */

if (!defined('ABSPATH')) {
    exit;
}

return [
    'Organization' => [
        '@id' => '{{organization_url}}#Organization',
        'legalName' => '{{organization_name}}',
        'publisher' => '_remove',
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
            'sameAs' => 'https://www.wikidata.org/wiki/Q2',
        ],
        'contactPoint' => [
            'availableLanguage' => ['en', 'ru', 'ka', 'ar', 'zh'],
        ],
        'founder' => '{{schema_founder_name}}',
        'foundingDate' => '2017',
        'foundingLocation' => [
            '@type' => 'Place',
            'name' => 'Georgia',
            'sameAs' => 'https://www.wikidata.org/wiki/Q230'
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
        'publisher' => '_remove',
        'hasOfferCatalog' => [
            '@type' => 'OfferCatalog',
            'name' => '{{post_title}}',
        ],
        'audience' => [
            '@type' => 'Audience',
            'audienceType' => 'Foreign investors, offshore companies, international entrepreneurs and expats',
        ],
        'provider' => [
            '@type' => 'Organization',
            '@id' => '{{organization_url}}#Organization',
        ],
    ],
    'WebSite' => [
        'publisher' => [
            '@type' => 'Organization',
            '@id' => '{{organization_url}}#Organization',
        ],
    ],
    'WebPage' => [
        'publisher' => [
            '@type' => 'Organization',
            '@id' => '{{organization_url}}#Organization',
        ],
        'reviewedBy' => [
            '@type' => 'Organization',
            '@id' => '{{organization_url}}#Organization',
        ],
    ],
    'AboutPage' => [
        'publisher' => [
            '@type' => 'Organization',
            '@id' => '{{organization_url}}#Organization',
        ],
        'mainEntity' => [
            '@type' => 'Organization',
            '@id' => '{{organization_url}}#Organization',
        ],
    ],
    'ContactPage' => [
        'publisher' => [
            '@type' => 'Organization',
            '@id' => '{{organization_url}}#Organization',
        ],
        'mainEntity' => [
            '@type' => 'Organization',
            '@id' => '{{organization_url}}#Organization',
        ],
    ],
];
