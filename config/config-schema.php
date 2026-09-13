<?php
/**
 * Schema Translation Configuration
 *
 * Controls which schema keys are translated (inclusion list).
 * Organization identity is managed via per-environment plugin options.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
const FRL_SCHEMA_TRANSLATE_KEYS = array(
	'address',
	'foundingLocation',
	'audienceType',
);

/**
 * Post-type-to-schema-data-file mapping.
 *
 * Maps WordPress post type slugs to their schema definition file names
 * (without .php extension). The orchestrator loads {value}.php from
 * the data/generators/ directory for each matching post type.
 *
 * Special contexts (_global, _home, _search) are handled separately.
 * Special page types (AboutPage, ContactPage) are detected by slug
 * via frl_schema_get_page_type().
 *
 * Add entries here when creating schema definitions for custom post types.
 */
/**
 * Global schema types loaded on every page.
 */
const FRL_SCHEMA_GLOBAL_TYPES = array(
	'Organization',
	'WebSite',
);

/**
 * Post-type-to-schema-type mapping.
 *
 * Maps WordPress post type slugs to the Schema.org types to output
 * when viewing a singular post of that type. Each value is a Schema.org
 * type name matching a definition file in definitions/.
 */
const FRL_SCHEMA_POST_TYPE_MAP = array(
	'post'        => array( 'Article', 'BreadcrumbList', 'HowTo' ),
	'page'        => array( 'WebPage', 'BreadcrumbList' ),
	'service'     => array( 'Service', 'BreadcrumbList' ),
	'team-member' => array( 'ProfilePage', 'BreadcrumbList' ),
);

/**
 * Page-slug-to-schema-type mapping for special pages.
 *
 * When a page slug matches a key, the corresponding schema types
 * are loaded in addition to the regular page schemas.
 */
const FRL_SCHEMA_SPECIAL_PAGES = array(
	'about'   => array( 'AboutPage' ),
	'contact' => array( 'ContactPage' ),
);
