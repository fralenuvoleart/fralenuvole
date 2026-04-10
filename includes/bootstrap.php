<?php

/**
 * Bootstrap - Plugin initialization
 *
 * @package Fralenuvole
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

// Ensure WP_CLI constant exists so linting tools detect it. Value is overridden by WP-CLI loader.
if (!defined('WP_CLI')) {
    define('WP_CLI', false);
}

/**
 * Plugin core constants
 */
// Define the plugin directory path
if (!defined('FRL_DIR_PATH')) {
    define('FRL_DIR_PATH', plugin_dir_path(dirname(__FILE__)));
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
 * Debug modes via 'frlmode' URL parameter.
 * - frlmode=disable: Disables the plugin entirely.
 * -?frlmode=core:    Mimics 'disable_plugin' option behavior.
 * - frlmode=nocache: Bypasses the plugin's cache system.
 */
if (isset($_GET['frlmode'])) {
    define('FRL_MODE', sanitize_key($_GET['frlmode']));
}

// FRL_MODE=disable: Stop loading the plugin entirely
if (defined('FRL_MODE') && FRL_MODE === 'disable') {
    return;
}

// Load core features: translation, environment and cache manager
require_once FRL_DIR_PATH . 'includes/translator/class-translation-service.php';
require_once FRL_DIR_PATH . 'includes/environment/class-environment-manager.php';

// Load and initialize the cache manager immediately.
require_once FRL_DIR_PATH . 'includes/cache/class-cache-manager.php';
Frl_Cache_Manager::init();

// Load lifecycle after core infrastructure is ready
require_once FRL_DIR_PATH . 'includes/lifecycle.php';

// Load and initialize the custom error handler immediately (original behavior)
$frl_error_handler_file = FRL_DIR_PATH . 'includes/error-handler.php';
require_once $frl_error_handler_file;
frl_errors_init();

// Register rewriter CLI commands (only under WP-CLI)
if (defined('WP_CLI') && WP_CLI) {
    require_once FRL_DIR_PATH . 'includes/rewriter/cli/class-rewriter-cli.php';
}
