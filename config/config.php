<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Load all config files
 */
require_once __DIR__ . '/config-base.php';
require_once __DIR__ . '/config-options.php';
require_once __DIR__ . '/config-fields.php';
require_once __DIR__ . '/config-cache.php';
require_once __DIR__ . '/config-rewriter.php';
require_once __DIR__ . '/environment/config-environment.php';
require_once __DIR__ . '/config-translator.php';
