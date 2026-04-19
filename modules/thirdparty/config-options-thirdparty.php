<?php

/**
 * Third-Party module settings
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

$frl_thirdparty_default_fields = array(
    'section_title_thirdparty' => array(
        'label'       => 'Third-Party Integrations',
        'type'        => 'section_title',
        'description' => 'Settings for third-party plugin integrations',
    ),
    'thirdparty_cache_inbound' => array(
        'label'             => 'Enable Inbound Cache Bridge',
        'description'       => 'Clears fralenuvole caches when LiteSpeed, Breeze, WP Rocket or other thirdparty plugins are flushed.',
        'type'              => 'checkbox',
        'default'           => 1,
        'sanitize_callback' => 'absint',
        'restricted'        => true,
    ),
    'thirdparty_cache_outbound' => array(
        'label'             => 'Enable Outbound Cache Bridge',
        'description'       => 'Notifies LiteSpeed, Breeze, WP Rocket or other thirdparty caches, when fralenuvole cache is flushed.',
        'type'              => 'checkbox',
        'default'           => 0,
        'sanitize_callback' => 'absint',
        'restricted'        => true,
    ),
);
