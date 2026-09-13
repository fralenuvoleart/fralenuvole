<?php
/**
 * Article Schema
 *
 * @package Fralenuvole
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'_if'              => 'schema_article',
	'@type'            => 'Article',
	'@id'              => '{{post_permalink}}#Article',
	'url'              => '{{post_permalink}}',
	'inLanguage'       => '{{language}}',
	'headline'         => '{{post_title}}',
	'description'      => '{{post_excerpt}}',
	'mainEntityOfPage' => '{{post_permalink}}',
	'datePublished'    => '{{post_date_published}}',
	'dateModified'     => '{{post_date_modified}}',
	'image'            => array(
		'@source' => 'featured_image',
	),
	'author'           => array(
		'@type' => 'Person',
		'@id'   => '{{post_permalink}}#Person',
	),
	'publisher'        => array(
		'@type' => 'Organization',
		'@id'   => '{{schema_org_url}}#Organization',
	),
	'mainEntity'       => array(
		'@source' => 'article_headings',
	),
	'speakable'        => array(
		'@type' => 'SpeakableSpecification',
		'xpath' => array(
			'/html/head/title',
			'/html/head/meta[@name=\'description\']/@content',
		),
	),
);
