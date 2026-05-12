<?php

/**
 * Fralenuvole - MU Plugin Loader.
 *
 * @package Fralenuvole
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

// Load MU-plugin-specific helpers
// @phpstan-ignore requireOnce.fileNotFound
require_once FRL_DIR_PATH . 'includes/mu/functions-mu.php';

/**
 * Setup plugin exclusion filter before other plugins load.
 */
add_action('muplugins_loaded', 'frl_filter_plugin_exclusions', 5);

// Throttle AI assistant bots before any WordPress output starts
frl_maybe_throttle_user_agent();
