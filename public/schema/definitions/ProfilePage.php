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
	'image'      => array(
		'@source' => 'featured_image',
	),
	'mainEntity' => array(
		'@type'         => 'Person',
		'@id'           => '{{post_permalink}}#Person',
		'name'          => '{{post_title}}',
		'url'           => '{{post_permalink}}',
		'description'   => '{{post_excerpt}}',
		'jobTitle'      => '@field:team-settings_team-role',
		'worksFor'      => array(
			'@type' => 'Organization',
			'@id'   => '{{schema_org_url}}#Organization',
			'url'   => '{{schema_org_url}}',
		),
		'hasCredential' => array(
			'@type' => 'EducationalOccupationalCredential',
			'name'  => '@field:team-settings_team-education',
		),
		'sameAs'        => array(
			'@field:team-settings_team-linkedin',
			'@field:team-settings_team-facebook',
			'@field:team-settings_team-website',
			'@field:team-settings_team-whatsapp',
		),
		'image'         => array(
			'@source' => 'featured_image',
		),
	),
);
