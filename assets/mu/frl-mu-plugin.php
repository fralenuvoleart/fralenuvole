<?php

/**
 * Fralenuvole - Early Error Handler Loader
 *
 * This file should be placed in the /wp-content/mu-plugins/ directory.
 * It ensures the custom error handler is loaded before all other plugins,
 * allowing it to intercept errors that occur very early in the WordPress lifecycle.
 *
 * @package Fralenuvole
 */

const FRL_MU_NAME = 'fralenuvole';

$plugin_dir = WP_PLUGIN_DIR . '/' . FRL_MU_NAME . '/';
$bootstrap_file = $plugin_dir . 'includes/bootstrap.php';

if (file_exists($bootstrap_file)) {
    // @phpstan-ignore requireOnce.fileNotFound
    require_once $bootstrap_file;
}
