<?php
/**
 * Default Schema Properties Data
 *
 * Pure data file — no function calls. Dynamic values use placeholders:
 *   {{site_url}}          → resolved to site_url() at runtime
 *   {{site_url_local}}    → resolved to current-language homepage via frl_get_home_url()
 *   {{custom_logo}}       → resolved to the site logo URL
 *   {{schema_organization_url}}  → resolved from env plugin option 'schema_organization_url'
 *   {{schema_organization_name}} → resolved from env plugin option 'schema_organization_name'
 *   {{schema_founder_name}} → resolved from env plugin option 'schema_founder_name'
 *   {{post_title}}        → resolved at injection time to get_the_title($post_id)
 *
 * Translation is controlled by FRL_SCHEMA_TRANSLATE_KEYS in config-schema.php.
 * Only explicitly listed keys are translated; all others are kept as-is.
 * Use '_remove' as a value to strip a key from the output.
 *
 * @package Fralenuvole
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'Organization' => array(
		'@id'              => '{{schema_organization_url}}#Organization',
		'legalName'        => '{{schema_organization_name}}',
		'publisher'        => '_remove',
		'logo'             => array(
			'@type'  => 'ImageObject',
			'url'    => '{{custom_logo}}',
			'width'  => '150',
			'height' => '40',
		),
		'address'          => array(
			'@type'           => 'PostalAddress',
			'addressCountry'  => 'GE',
			'streetAddress'   => 'Zakaria Paliashvili Street 26',
			'addressLocality' => 'Tbilisi',
			'postalCode'      => '0179',
		),
		'areaServed'       => array(
			'@type'  => 'AdministrativeArea',
			'name'   => 'Worldwide',
			'sameAs' => 'https://www.wikidata.org/wiki/Q2',
		),
		'contactPoint'     => array(
			'availableLanguage' => array( 'en', 'ru', 'ka', 'ar', 'zh' ),
		),
		'founder'          => '{{schema_founder_name}}',
		'foundingDate'     => '2017',
		'foundingLocation' => array(
			'@type'  => 'Place',
			'name'   => 'Georgia',
			'sameAs' => 'https://www.wikidata.org/wiki/Q230',
		),
		'knowsAbout'       => array(
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
		),
	),
	'Service'      => array(
		'publisher' => '_remove',
		'audience'  => array(
			'@type'        => 'Audience',
			'audienceType' => 'Foreign investors, offshore companies, international entrepreneurs and expats',
		),
		'provider'  => array(
			'@type' => 'Organization',
			'@id'   => '{{schema_organization_url}}#Organization',
		),
	),
	'WebSite'      => array(
		'publisher' => array(
			'@type' => 'Organization',
			'@id'   => '{{schema_organization_url}}#Organization',
		),
	),
	'WebPage'      => array(
		'publisher'  => array(
			'@type' => 'Organization',
			'@id'   => '{{schema_organization_url}}#Organization',
		),
		'reviewedBy' => array(
			'@type' => 'Organization',
			'@id'   => '{{schema_organization_url}}#Organization',
		),
	),
	'AboutPage'    => array(
		'publisher'  => array(
			'@type' => 'Organization',
			'@id'   => '{{schema_organization_url}}#Organization',
		),
		'mainEntity' => array(
			'@type' => 'Organization',
			'@id'   => '{{schema_organization_url}}#Organization',
		),
	),
	'ContactPage'  => array(
		'publisher'  => array(
			'@type' => 'Organization',
			'@id'   => '{{schema_organization_url}}#Organization',
		),
		'mainEntity' => array(
			'@type' => 'Organization',
			'@id'   => '{{schema_organization_url}}#Organization',
		),
	),
);
