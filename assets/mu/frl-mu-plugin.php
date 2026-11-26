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
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// MU plugin constants
const FRL_MU_NAME = 'fralenuvole';

// Isolated scope avoids leaking $plugin_dir into wp-settings.php global scope.
( static function (): void {
	$bootstrap = WP_PLUGIN_DIR . '/' . FRL_MU_NAME . '/includes/bootstrap.php';
	if ( ! is_file( $bootstrap ) ) {
		return;
	}

	// Load plugin bootstrap with config to initialize core, error handler.
	// @phpstan-ignore requireOnce.fileNotFound
	require_once $bootstrap;
} )();
