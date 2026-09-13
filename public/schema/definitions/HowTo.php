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
	'@type'         => 'HowTo',
	'name'          => 'service-howtos_title',
	'description'   => 'service-howtos_description',
	'about'         => '{{post_title}}',
	'totalTime'     => 'service-howtos_time',
	'estimatedCost' => 'service-howtos_cost',
	'step'          => array(
		'@type'    => 'HowToStep',
		'repeater' => 'service-howtos_howto',
		'source'   => 'acpt',
		'position' => '{{index}}',
		'name'     => 'title',
		'text'     => 'answer',
	),
);
