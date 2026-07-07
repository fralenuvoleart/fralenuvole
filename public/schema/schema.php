<?php
/**
 * Schema Service
 *
 * Provides dynamic, translatable schema properties for JSON-LD output.
 * Data is loaded from pure data files, resolved (placeholders + translation),
 * cached per-language, and made filterable for per-brand overrides.
 *
 * @package Fralenuvole
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once FRL_DIR_PATH . 'public/schema/properties/resolver.php';
require_once FRL_DIR_PATH . 'public/schema/properties/builders.php';
require_once FRL_DIR_PATH . 'public/schema/generator/generator.php';
