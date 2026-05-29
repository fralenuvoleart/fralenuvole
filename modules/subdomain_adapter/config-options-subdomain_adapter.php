<?php

/**
 * Third-Party module settings
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

$frl_subdomain_adapter_default_fields = array(
    'section_title_subdomain_adapter' => array(
        'label'       => 'Subdomain Adapter Module',
        'type'        => 'section_title',
        'description' => 'Settings for subdomain adapter module',
    ),
    'subdomain_adapter_legacy_links' => array(
        'label'             => 'Fix Legacy Links',
        'description'       => 'Transforms all legacy links on both main website and subdomain.',
        'type'              => 'checkbox',
        'default'           => 1,
        'sanitize_callback' => 'absint',
        'restricted'        => true,
    ),
    'subdomain_adapter_robots_sitemap' => array(
        'label'             => 'List Slave Subdomain Sitemap in robots.txt',
        'description'       => 'When disabled, the slave subdomain sitemap URL (ie. https://ru.pbservices.ge/sitemap.xml) is removed from the main domain\'s robots.txt.',
        'type'              => 'checkbox',
        'default'           => 0,
        'sanitize_callback' => 'absint',
        'restricted'        => true,
    ),
);
