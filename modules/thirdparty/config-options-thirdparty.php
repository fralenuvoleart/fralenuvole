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
    'thirdparty_cache_bridge' => array(
        'label'             => 'Enable Cache Bridge',
        'description'       => 'Two-way cache sync: clears fralenuvole caches when LiteSpeed, Breeze, or WP Rocket purge, and notifies them when fralenuvole flushes.',
        'type'              => 'checkbox',
        'default'           => 1,
        'sanitize_callback' => 'absint',
        'restricted'        => true,
    ),
);
