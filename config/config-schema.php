<?php
/**
 * Schema Configuration
 *
 * Organization identity constants and translation key inclusion list.
 */
if (!defined('ABSPATH')) exit;


const FRL_SCHEMA_ORGANIZATION_NAME = 'PB Services Georgia';
const FRL_SCHEMA_ORGANIZATION_URL = 'https://pbservices.ge/';

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
	'streetAddress',
	'addressLocality',
];
