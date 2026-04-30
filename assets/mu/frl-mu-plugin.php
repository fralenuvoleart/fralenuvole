<?php

/**
 * Fralenuvole - Early Loader (MU Plugin Bootstrap)
 *
 * This file should be placed in the /wp-content/mu-plugins/ directory.
 * It loads before regular plugins and sets up early filters.
 *
 * All exclusion logic lives in includes/helpers/functions-mu-plugin.php.
 *
 * @package Fralenuvole
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

// MU plugin constants
const FRL_MU_NAME = 'fralenuvole';

// Load plugin bootstrap to initialize helpers, error handler, and cache
// @phpstan-ignore requireOnce.fileNotFound
require_once WP_PLUGIN_DIR . '/' . FRL_MU_NAME . '/includes/bootstrap.php';

// Load MU-plugin-specific helpers
// @phpstan-ignore requireOnce.fileNotFound
require_once FRL_DIR_PATH . 'includes/helpers/functions-mu-plugin.php';

/**
 * Setup plugin exclusion filter before other plugins load.
 */
add_action('muplugins_loaded', 'frl_filter_plugin_exclusions', 5);
