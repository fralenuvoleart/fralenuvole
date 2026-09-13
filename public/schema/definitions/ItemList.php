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
	'itemListElement' => array(
		'@source' => 'archive_posts',
	),
);
