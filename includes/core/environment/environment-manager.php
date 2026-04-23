<?php

/**
 * Environment Manager
 * @package Fralenuvole
 */

if (!defined('ABSPATH')) {
    exit;
}

// Host normalization utilities for environment comparisons
require_once __DIR__ . '/class-environment-utils.php';
require_once __DIR__ . '/class-environment-config.php';
require_once __DIR__ . '/class-environment-state.php';
require_once __DIR__ . '/class-environment-monitor.php';
require_once __DIR__ . '/class-environment-applier.php';
require_once __DIR__ . '/class-environment-plugin-manager.php';
require_once __DIR__ . '/class-environment-manager.php';