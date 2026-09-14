<?php
/**
 * CollectionPage Schema — Archive Pages
 *
 * Wraps ItemList with page context. Dynamic properties (name, url, about)
 * are injected by the orchestrator based on the archive type.
 *
 * @package Fralenuvole
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'_if'   => 'schema_collectionpage',
	'@type' => 'CollectionPage',
);

