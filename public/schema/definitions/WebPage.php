<?php
/**
 * WebPage Schema
 *
 * @package Fralenuvole
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'_if'           => 'schema_webpage',
	'@type'         => 'WebPage',
	'@id'           => '{{post_permalink}}#WebPage',
	'name'          => '{{post_title}}',
	'url'           => '{{post_permalink}}',
	'inLanguage'    => '{{language}}',
	'description'   => '{{post_excerpt}}',
	'datePublished' => '{{post_date_published}}',
	'dateModified'  => '{{post_date_modified}}',
	'reviewedBy'    => array(
		'@type' => 'Organization',
		'@id'   => '{{schema_org_url}}#Organization',
	),
	'publisher'     => array(
		'@type' => 'Organization',
		'@id'   => '{{schema_org_url}}#Organization',
	),
);