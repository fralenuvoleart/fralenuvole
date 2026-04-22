<?php

/**
 * Module Name: Custom Fields
 * Description: Automatic translation for ACF and other custom fields.
 */

if (!defined('ABSPATH')) exit;

require_once FRL_DIR_PATH . 'core/translator/class-translation-service.php';
require_once FRL_DIR_PATH . 'core/translator/field-translator.php';

// Global queue for tracking meta keys to avoid re-entrant cache calls.
$frl_translator_tracking_queue = [];