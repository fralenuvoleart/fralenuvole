<?php
/**
 * WebSite Schema
 *
 * @package Fralenuvole
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'_if'             => 'schema_website',
	'@type'           => 'WebSite',
	'@id'             => '{{schema_org_url}}#Website',
	'url'             => '{{schema_org_url}}',
	'name'            => '{{schema_org_name}}',
	'inLanguage'      => '{{language}}',
	'description'     => '{{schema_org_description}}',
	'publisher'       => array(
		'@type' => 'Organization',
		'@id'   => '{{schema_org_url}}#Organization',
	),
	'potentialAction' => array(
		'@type'       => 'SearchAction',
		'target'      => array(
			'@type'       => 'EntryPoint',
			'urlTemplate' => '{{site_url}}/?s={search_term_string}',
		),
		'query-input' => 'required name=search_term_string',
	),
);
