<?php
/**
 * ProfilePage Schema
 *
 * @package Fralenuvole
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'_if'        => 'schema_profilepage',
	'@type'      => 'ProfilePage',
	'@id'        => '{{post_permalink}}#ProfilePage',
	'url'        => '{{post_permalink}}',
	'name'       => '{{post_title}}',
	'isPartOf'   => array(
		'@id' => '{{schema_org_url}}#Website',
	),
	'mainEntity' => array(
		'@type'         => 'Person',
		'@id'           => '{{post_permalink}}#Person',
		'url'           => '{{post_permalink}}',
		'worksFor'      => array(
			'@type' => 'Organization',
			'@id'   => '{{schema_org_url}}#Organization',
			'url'   => '{{schema_org_url}}',
		),
		'hasCredential' => array(
			'@type' => 'EducationalOccupationalCredential',
			'name'  => '{{team-settings_team-education}}',
		),
		'image'         => array(
			'@source' => 'featured_image',
		),
	),
);
