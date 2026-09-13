<?php
/**
 * Service Schema
 *
 * @package Fralenuvole
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'_if'             => 'schema_service',
	'@type'           => 'Service',
	'@id'             => '{{post_permalink}}#Service',
	'name'            => '{{post_title}}',
	'description'     => '{{post_excerpt}}',
	'url'             => '{{post_permalink}}',
	'provider'        => array(
		'@type'   => 'Organization',
		'@id'     => '{{schema_org_url}}#Organization',
		'name'    => '{{schema_org_name}}',
		'address' => array(
			'@type'          => 'PostalAddress',
			'streetAddress'  => '{{schema_org_streetaddress}}',
			'addressCountry' => array(
				'@type' => 'Country',
				'name'  => '{{schema_org_addresscountry}}',
			),
			'telephone'      => '{{schema_org_telephone}}',
		),
	),
	'audience'        => array(
		'@type'        => 'Audience',
		'audienceType' => 'Foreign investors, offshore companies, international entrepreneurs and expats',
	),
	'image'           => array(
		'@source' => 'featured_image',
	),
	'hasOfferCatalog' => '{{post_title}}',
);
