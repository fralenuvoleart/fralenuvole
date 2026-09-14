<?php
/**
 * HowTo Schema
 *
 * @package Fralenuvole
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'_if'           => 'schema_howto',
	'_requires'     => 'name',
	'@type'         => 'HowTo',
	'name'          => '@field:service-howtos_title',
	'description'   => '@field:service-howtos_description',
	'about'         => '{{post_title}}',
	'totalTime'     => '@field:service-howtos_time',
	'estimatedCost' => '@field:service-howtos_cost',
	'step'          => array(
		'@type'    => 'HowToStep',
		'repeater' => 'service-howtos_howto',
		'source'   => 'acpt',
		'position' => '{{index}}',
		'name'     => '@field:title',
		'text'     => '@field:answer',
	),
);
