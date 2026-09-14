<?php
/**
 * ItemList Schema — Archive Pages
 *
 * @package Fralenuvole
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'_if'             => 'schema_itemlist',
	'@type'           => 'ItemList',
	'@id'             => '{{site_url}}#ItemList',
	'itemListElement' => array(
		'@source' => 'archive_posts',
	),
);
