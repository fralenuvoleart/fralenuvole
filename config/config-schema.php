<?php
/**
 * Schema Translation Configuration
 *
 * Controls which schema keys and values are excluded from translation.
 */
if (!defined('ABSPATH')) exit;

/**
 * Only these keys are translated. All others kept as-is.
 * Bare name (no dot) → matches that key at any depth.
 * Dot-path → prefix match on the full path (includes all subkeys).
 * Use '_remove' in schema data to remove a key entirely.
 *
 * Examples:
 *   'name'                → any key named 'name' at any depth
 *   'Organization.address' → all keys under Organization.address
 */
const FRL_SCHEMA_TRANSLATE_KEYS = [
	'name',
	'streetAddress',
	'addressLocality',
];
