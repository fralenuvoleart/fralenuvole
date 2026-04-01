<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renders the 'Admin Panel' dashboard widget content.
 * @return string The HTML content for the widget.
 */
function frl_render_administrator_widget()
{
    // Define link data in an array, grouped by section
    $admin_links = [
        'Marketing' => [
            ['url' => 'https://lookerstudio.google.com/s/miZY1EoWyMo', 'text' => 'PBS Marketing Dashboard'],
            ['url' => 'https://search.google.com/search-console', 'text' => 'Google Search Console'],
            ['url' => 'https://analytics.google.com/analytics/web/', 'text' => 'Google Analytics'],
            ['url' => 'https://ads.google.com/aw/overview?ocid=256107330', 'text' => 'Google Adwords'],
            ['url' => 'https://clarity.microsoft.com/projects/view/njfczopmu0/dashboard', 'text' => 'Clarity'],
            ['url' => 'https://www.semrush.com/projects/', 'text' => 'SEMrush'],
        ],
        'Administration' => [
            ['url' => 'https://tagmanager.google.com/#/home', 'text' => 'Google Tag Manager'],
            ['url' => 'https://dash.cloudflare.com', 'text' => 'Cloudflare'],
            ['url' => 'https://my.quic.cloud/', 'text' => 'Quickcloud'],
            ['url' => 'https://app.integrately.com/my-automations', 'text' => 'Integrately'],
        ],
    ];

    // Return only the inner content (headings and lists)
    $output = '';

    // Loop through each section
    foreach ($admin_links as $section_title => $links) {
        $output .= '<h3>' . esc_html($section_title) . '</h3>';
        $output .= '<ol>';

        // Loop through links in the current section
        foreach ($links as $link) {
            $output .= '<li><a href="' . esc_url($link['url']) . '" target="_blank" rel="noopener">' . esc_html($link['text']) . '</a></li>';
        }

        $output .= '</ol>';
    }

    return $output;
}
