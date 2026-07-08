<?php

/**
 * Module Name: Custom Fields
 * Description: Automatic translation for ACF and other custom fields.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// This module only loads when frl_is_multilingual_plugin_active() is true
// (see fralenuvole.php), i.e. when a real adapter exists for the detected
// plugin — see frl_get_translation_adapter_class() in
// functions-translator-helpers.php. Each adapter file owns and requires
// its own companion files (e.g. polylang.php requires
// polylang-admin-access.php).
require_once FRL_DIR_PATH . 'core/translator/adapters/interface.php';
require_once FRL_DIR_PATH . 'core/translator/adapters/polylang.php';
require_once FRL_DIR_PATH . 'core/translator/class-translation-service.php';
require_once FRL_DIR_PATH . 'core/translator/field-translator.php';

// Global queue for tracking meta keys to avoid re-entrant cache calls.
$frl_translator_tracking_queue = array();
