<?php

/**
 * Module Name: Custom Fields
 * Description: Automatic translation for ACF and other custom fields.
 */

if (!defined('ABSPATH')) exit;

// Load adapter interface and implementation early so the adapter class is
// always available after the module loads, regardless of whether the
// Frl_Translation_Service singleton is instantiated (e.g. when translator
// is disabled but Polylang is active and fallbacks are needed).
require_once FRL_DIR_PATH . 'core/translator/adapters/interface.php';
require_once FRL_DIR_PATH . 'core/translator/adapters/polylang.php';
require_once FRL_DIR_PATH . 'core/translator/class-translation-service.php';
require_once FRL_DIR_PATH . 'core/translator/field-translator.php';

// Global queue for tracking meta keys to avoid re-entrant cache calls.
$frl_translator_tracking_queue = [];