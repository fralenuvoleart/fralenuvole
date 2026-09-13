<?php
/**
 * Schema Service
 *
 * Self-contained structured data (JSON-LD) module.
 * Single output point via Frl_Schema_Orchestrator — no external dependencies.
 *
 * Directory structure:
 *   class-orchestrator.php  — Single output point, context → build → enrich → output
 *   class-context.php       — Request context detection (global, singular, archive, etc.)
 *   class-builder.php       — Recursive schema builder from definition arrays
 *   class-resolver.php      — Static property resolution (placeholders + translation)
 *   class-enricher.php      — Term and Person property builders from WordPress data
 *   definitions/            — Schema structure definitions (one file per context/post-type)
 *   properties/             — Static enrichment property data (injected into built schemas)
 *
 * @package Fralenuvole
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once FRL_DIR_PATH . 'public/schema/class-context.php';
require_once FRL_DIR_PATH . 'public/schema/class-resolver.php';
require_once FRL_DIR_PATH . 'public/schema/class-enricher.php';
require_once FRL_DIR_PATH . 'public/schema/class-builder.php';
require_once FRL_DIR_PATH . 'public/schema/class-schema-orchestrator.php';

Frl_Schema_Orchestrator::init();
