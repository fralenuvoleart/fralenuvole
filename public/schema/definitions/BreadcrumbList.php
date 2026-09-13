<?php
/**
 * BreadcrumbList Schema
 *
 * Dynamically built from WordPress categories + current post.
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
		'@source' => 'breadcrumb',
	),
);
