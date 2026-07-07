<?php
/**
 * Default Schema Term Mapping Data
 *
 * Pure data file — no function calls.
 * Maps schema @type properties to WordPress taxonomy slugs.
 * Post terms are resolved at injection time, per-request.
 *
 * Format:
 *   'SchemaType' => [
 *       'schemaProperty' => 'taxonomy_slug',
 *   ]
 *
 * @package Fralenuvole
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'Service' => array(
		'serviceType' => 'service_category',
	),
	'Article' => array(
		'articleSection' => 'category',
	),
);
