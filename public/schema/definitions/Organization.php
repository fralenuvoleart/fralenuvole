<?php
/**
 * Organization Schema
 *
 * @package Fralenuvole
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'_if'              => 'schema_organization',
	'@type'            => 'Organization',
	'@id'              => '{{schema_org_url}}#Organization',
	'name'             => '{{schema_org_name}}',
	'url'              => '{{schema_org_url}}',
	'legalName'        => '{{schema_org_name}}',
	'description'      => '{{schema_org_description}}',
	'sameAs'           => array(
		'@source' => 'organization_sameas',
	),
	'logo'             => array(
		'@source' => 'site_logo',
	),
	'address'          => array(
		'@type'           => 'PostalAddress',
		'addressCountry'  => '{{schema_org_addresscountry}}',
		'streetAddress'   => '{{schema_org_streetaddress}}',
		'addressLocality' => '{{schema_org_addresslocality}}',
		'postalCode'      => '{{schema_org_postalcode}}',
	),
	'areaServed'       => array(
		'@type'  => 'AdministrativeArea',
		'name'   => '{{schema_org_areaserved}}',
		'sameAs' => '{{schema_org_areaserved_sameas}}',
	),
	'contactPoint'     => array(
		'@type'             => 'ContactPoint',
		'contactType'       => 'customer support',
		'telephone'         => '{{schema_org_telephone}}',
		'url'               => '{{schema_contact_url}}',
		'availableLanguage' => array(
			'@source' => 'organization_availablelanguage',
		),
	),
	'founder'          => array(
		'@type' => 'Person',
		'@id'   => '{{schema_founder_url}}#Person',
		'url'   => '{{schema_founder_url}}',
		'name'  => '{{schema_founder_name}}',
	),
	'foundingDate'     => '{{schema_org_foundingdate}}',
	'foundingLocation' => array(
		'@type'  => 'Place',
		'name'   => '{{schema_org_foundinglocation}}',
		'sameAs' => '{{schema_org_foundinglocation_sameas}}',
	),
	'knowsAbout'       => array(
		'@source' => 'organization_knowsabout',
	),
);
