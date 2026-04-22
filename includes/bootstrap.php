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
 * Core plugin constants.
 */
// Define plugin directory path (respects PHPStan bootstrap if available)
if (!defined('FRL_DIR_PATH')) {
    define('FRL_DIR_PATH', dirname(__DIR__) . '/');
}
if (!defined('FRL_DIR_URL')) {
    define('FRL_DIR_URL', plugin_dir_url(dirname(__FILE__)));
}
// Define the plugin core constants
const FRL_PREFIX = 'frl';
const FRL_NAME = 'fralenuvole';
const FRL_PLUGIN_FILE = FRL_NAME . '.php';

// Load required settings and functions
require_once FRL_DIR_PATH . 'config/config.php';
require_once FRL_DIR_PATH . 'includes/helpers/functions.php';

/**
 * Debug modes via 'frlmode' URL parameter:
 * - disable: Disables the plugin entirely.
 * - core:    Mimics 'disable_plugin' option behavior.
 * - nocache: Bypasses the plugin's cache system.
 */
if (isset($_GET['frlmode'])) {
    define('FRL_MODE', sanitize_key($_GET['frlmode']));
}

// Stop loading if plugin is disabled via FRL_MODE
if (defined('FRL_MODE') && FRL_MODE === 'disable') {
    return;
}

// Load core infrastructure services
require_once FRL_DIR_PATH . 'core/translator/class-translation-service.php';
require_once FRL_DIR_PATH . 'core/environment/class-environment-manager.php';

// Initialize cache and error handlers (skipped during PHPStan analysis)
if (!defined('PHPSTAN_RUNNING') || !PHPSTAN_RUNNING) { // @phpstan-ignore-line duplicateIfStatments
    require_once FRL_DIR_PATH . 'core/cache/class-cache-manager.php';
    Frl_Cache_Manager::init();

    // Initialize custom error handler
    $frl_error_handler_file = FRL_DIR_PATH . 'core/error-handler.php';
    require_once $frl_error_handler_file;
    frl_errors_init();
}

// Load lifecycle hooks after core infrastructure is ready
require_once FRL_DIR_PATH . 'includes/lifecycle.php';

// Register rewriter CLI commands for WP-CLI
if (defined('WP_CLI') && WP_CLI) { // @phpstan-ignore-line alwaysFalse
    require_once FRL_DIR_PATH . 'core/rewriter/cli/class-rewriter-cli.php';
}