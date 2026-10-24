<?php

/**
 * Fralenuvole - Early Loader (MU Plugin Bootstrap)
 *
 * This file should be placed in the /wp-content/mu-plugins/ directory.
 * It loads before regular plugins and sets up early filters.
 *
 * @package Fralenuvole
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

// MU plugin constants
const FRL_MU_NAME = 'fralenuvole';
$plugin_dir = WP_PLUGIN_DIR . '/' . FRL_MU_NAME . '/';

// Load plugin bootstrap with config to initialize core, error handler
// @phpstan-ignore requireOnce.fileNotFound
require_once $plugin_dir . 'includes/bootstrap.php';
