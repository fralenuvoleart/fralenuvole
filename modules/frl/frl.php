<?php

/**
 * Module Name: FRL Module
 * Description: Custom features
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/config-constants-frl.php';
require_once __DIR__ . '/bible.php';
require_once __DIR__ . '/menu-sitemap.php';

add_action('wp_loaded', 'frl_module_public_scripts', 10, 1);

/**
 * Enqueue frl-specific styles and scripts
 */
function frl_module_public_scripts()
{
    if (!frl_is_valid_frontend_page_request()) {
        return;
    }
    $assets = ['frl-public-css' => 'modules/frl/assets/css/public.css'];
    frl_enqueue_scripts($assets, 'frl_public', [], true);
}
