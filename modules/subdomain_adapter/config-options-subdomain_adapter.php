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
);
