<?php
/**
 * ContactPage Schema
 *
 * @package Fralenuvole
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'_if'             => 'schema_contactpage',
	'@type'           => 'ContactPage',
	'url'             => '{{post_permalink}}',
	'headline'        => '{{post_title}}',
	'description'     => '{{post_excerpt}}',
	'mainEntityOfPage' => array(
		'@type' => 'WebPage',
		'@id'   => '{{post_permalink}}',
	),
	'image'           => array(
		'@source' => 'featured_image',
	),
	'publisher'       => array(
		'@type' => 'Organization',
		'@id'   => '{{schema_org_url}}#Organization',
	),
	'mainEntity'      => array(
		'@type' => 'Organization',
		'@id'   => '{{schema_org_url}}#Organization',
	),
);
