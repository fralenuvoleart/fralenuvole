<?php
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/'); 
}
/**
 * Plugin bootstrap.
 *
 * Handles constant definitions, core service loading, and initial system setup.
 *
 * @package Fralenuvole
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Debug modes via 'frlmode' URL parameter:
 * - disable: Disables the plugin entirely.
 * - core:    Mimics 'disable_plugin' option behavior.
 * - nocache: Bypasses the plugin's cache system.
 * - migrate: Applies Environment Manager Config to current environment.
 */
if (isset($_GET['frlmode'])) {
    define('FRL_MODE', sanitize_key($_GET['frlmode']));
}

// Stop loading if plugin is disabled via FRL_MODE
if (defined('FRL_MODE') && FRL_MODE === 'disable') {
    return;
}

/**
 * Core plugin constants.
 */

// Load required settings and functions
require_once dirname(__DIR__) . '/config/config.php';
require_once FRL_DIR_PATH . 'includes/helpers/functions.php';

require_once FRL_DIR_PATH . 'includes/core/cache/class-cache-manager.php';
Frl_Cache_Manager::init();

require_once FRL_DIR_PATH . 'includes/core/cache/class-cache-operations.php';

require_once FRL_DIR_PATH . 'includes/core/error-handler.php';
frl_errors_init();

// Register rewriter CLI commands for WP-CLI
if (defined('WP_CLI') && WP_CLI) { // @phpstan-ignore-line alwaysFalse
    require_once FRL_DIR_PATH . 'includes/core/rewriter/cli/class-rewriter-cli.php';
}