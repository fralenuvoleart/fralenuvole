<?php
/**
 * Schema Translation Configuration
 *
 * Controls which schema keys and values are excluded from translation.
 */
if (!defined('ABSPATH')) exit;

/**
 * Values starting with these prefixes are not translated (kept as-is).
 * Use '_remove' in schema data to remove a key entirely.
 */
const FRL_SCHEMA_SKIP_TRANSLATION_VALUES = [
    '_',
];

/**
 * Keys matching these entries are not translated (kept as-is).
 * Key prefix match (str_starts_with). With '.' = exact dot-path match.
 * Examples:
 *   '@'                 → any key starting with '@' at any depth
 *   'Organization.name' → only that exact nested key
 */
const FRL_SCHEMA_SKIP_TRANSLATION_KEYS = [
	'@',
	'knowsAbout',
];
