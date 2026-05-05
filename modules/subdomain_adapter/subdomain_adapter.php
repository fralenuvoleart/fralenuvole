<?php

/**
 * Module Name: Subdomain Adapter
 * Description: Maps subdomains to Polylang languages and bidirectionally transforms URLs between main domain and its language-specific subdomain mirrors.
 *
 * @package Fralenuvole
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

// Load configuration constants.
require_once __DIR__ . '/config-constants-subdomain-adapter.php';

// Defensive: bail if the map constant was not defined.
if (!defined('FRL_SUBDOMAIN_ADAPTER_MAP')) {
    return;
}

// Load the handler class.
require_once __DIR__ . '/class-subdomain-adapter.php';

// Initialize the singleton. detect() runs once; hooks register only when
// on a configured domain (main_domain or mapped subdomain).
Frl_Subdomain_Adapter::init();
