<?php
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the 'Admin Panel' dashboard widget content.
 *
 * Uses the 'frl_admin_dashboard_links' filter to allow customization of the links.
 *
 * @return string The generated HTML content for the widget.
 */
function frl_render_administrator_widget() {
	// Define link data in an array, grouped by section
	// Filterable via 'frl_admin_dashboard_links' for customization
	$admin_links = apply_filters(
		'frl_admin_dashboard_links',
		array(
			'Marketing'      => array(
				array(
					'url'  => 'https://lookerstudio.google.com/s/miZY1EoWyMo',
					'text' => 'PBS Marketing Dashboard',
				),
				array(
					'url'  => 'https://search.google.com/search-console',
					'text' => 'Google Search Console',
				),
				array(
					'url'  => 'https://analytics.google.com/analytics/web/',
					'text' => 'Google Analytics',
				),
				array(
					'url'  => 'https://ads.google.com/aw/overview?ocid=256107330',
					'text' => 'Google Adwords',
				),
			),
			'Administration' => array(
				array(
					'url'  => 'https://tagmanager.google.com/#/home',
					'text' => 'Google Tag Manager',
				),
				array(
					'url'  => 'https://dash.cloudflare.com',
					'text' => 'Cloudflare',
				),
				array(
					'url'  => 'https://app.integrately.com/my-automations',
					'text' => 'Integrately',
				),
			),
		)
	);

	// Return only the inner content (headings and lists)
	$output = '';

	// Loop through each section
	foreach ( $admin_links as $section_title => $links ) {
		$output .= '<h3>' . esc_html( $section_title ) . '</h3>';
		$output .= '<ol>';

		// Loop through links in the current section
		foreach ( $links as $link ) {
			$output .= '<li><a href="' . esc_url( $link['url'] ) . '" target="_blank" rel="noopener">' . esc_html( $link['text'] ) . '</a></li>';
		}

		$output .= '</ol>';
	}

	return $output;
}
