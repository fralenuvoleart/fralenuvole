<?php
/**
 * BreadcrumbList Schema
 *
 * @package Fralenuvole
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'_if'             => 'schema_breadcrumb',
	'@type'           => 'BreadcrumbList',
	'@id'             => '{{post_permalink}}#BreadcrumbList',
	'itemListElement' => array(
		array(
			'@type'    => 'ListItem',
			'position' => '1',
			'item'     => array(
				'@id'  => '{{site_url}}',
				'name' => '{{schema_org_name}}',
			),
		),
		array(
			'@type'    => 'ListItem',
			'position' => '2',
			'item'     => array(
				'@id'  => '{{post_permalink}}',
				'name' => '{{post_title}}',
			),
		),
	),
);
