<?php
/**
 * Schema Translation Configuration
 *
 * Controls which schema keys are translated (inclusion list).
 * Organization identity is managed via per-environment plugin options.
 */
if (!defined('ABSPATH')) exit;

/**
 * Only these keys are translated. All others kept as-is.
 * Bare name (no dot) → matches that key at any depth.
 * Dot-path → prefix match on the full path (includes all subkeys).
 * Prefix '!' → exclude this key even if a parent rule matches.
 * Use '_remove' in schema data to remove a key entirely.
 *
 * Examples:
 *   'Organization'        → all keys under Organization
 *   '!Organization.name'  → but skip name under Organization
 *   '!name'               → skip any key named 'name' at any depth
 */
const FRL_SCHEMA_TRANSLATE_KEYS = [
	'streetAddress',
	'addressLocality',
];
