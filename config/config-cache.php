<?php

/**
 * Cache settings and constants
 * @package Fralenuvole
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Cache operation definitions (multi-step composite operations).
require_once __DIR__ . '/config-cache-operations.php';

// Cache prefix for all operations
const FRL_CACHE_PREFIX = FRL_PREFIX . '_cache_';

// Cache groups that should persist across requests
const FRL_CACHE_PERSISTENT_GROUPS = array(
	'staticdata',   // Used for heavy/infrequently changing data
	'theme',        // Used for stable/infrequently changing data
	'html',         // Used for HTML fragments
	'versions',     // Used for asset file versions
	'postdata',     // Used for post_id-specific data
	'blocks',       // Used in cleanup hooks
	'shortcodes',   // Used for shortcodes
	'translations', // Used for string translations
	'permalinks',   // Used for URL generation
	'rewriter',     // Used for rewriter data
	'metafields',   // Used for meta fields data
	'options',      // Used for plugin options
	'environment',  // Add environment group
	'adminui',      // Used for admin interface assembly
	'admin',        // Used for admin UI
);

// Default TTL values for different cache groups
const FRL_CACHE_TTL = array(
	'staticdata'   => WEEK_IN_SECONDS,     // 1 week (for heavy data)
	'theme'        => WEEK_IN_SECONDS,     // 1 week (for stable data)
	'rewriter'     => WEEK_IN_SECONDS,     // 1 week (rewriter data is stable)
	'translations' => WEEK_IN_SECONDS,     // 1 week
	'permalinks'   => WEEK_IN_SECONDS,     // 1 week
	'postdata'     => WEEK_IN_SECONDS,     // 1 week (post-specific data
	'metafields'   => WEEK_IN_SECONDS,     // 1 week
	'html'         => WEEK_IN_SECONDS,     // 1 week
	'blocks'       => WEEK_IN_SECONDS,     // 1 week
	'shortcodes'   => WEEK_IN_SECONDS,     // 1 week
	// 'options' actively invalidated by frl_update_option() + cache-cleanup hooks;
	// TTL is only a safety net for DB edits.
	'options'      => WEEK_IN_SECONDS,     // 1 week
	'adminui'      => DAY_IN_SECONDS,      // 1 day (UI assembly data)
	'versions'     => HOUR_IN_SECONDS,     // 1 hour
	'environment'  => HOUR_IN_SECONDS,     // 1 hour
	'admin'        => HOUR_IN_SECONDS,     // 1 hour
	'default'      => HOUR_IN_SECONDS,     // 1 hour default
);

// Groups that should have language prefix in their cache keys
const FRL_CACHE_LANGUAGE_GROUPS = array(
	'postdata',     // Post-specific data (language-dependent)
	'metafields',   // Meta fields data (language-dependent)
	'permalinks',   // Permalinks
	'translations', // Translation strings
	'shortcodes',   // Shortcodes
	'blocks',       // Translated blocks
);

// Plugin options that must stay autoloaded: read via native get_option() on.
const FRL_AUTOLOAD_OPTIONS = array(
	'disable_plugin',
	'disable_cache',
	'disable_environment',
	'disable_rewriter',
	'disable_translator',
);

// Define Heavy Groups to exclude from light purge
const FRL_CACHE_HEAVY_GROUPS = array(
	'staticdata',
	'blocks',
	'translations',
	'permalinks',
	'postdata',
	'rewriter',
);

// Cache dependencies between groups
const FRL_CACHE_DEPENDENCIES = array(
	// for 'options', 'rewriter' is intentionally NOT a dependency: they are invalidated by the rewriter-owned option keys via the 'updated_option' hook in Frl_Rewriter.
	'options'      => array(
		'theme',
		'html',
		'environment',
		'admin',
		'adminui',
	),
	// Rewriter needs to refresh permalinks
	'rewriter'     => array(
		'permalinks',

	),
	// Translations affect metafields
	'translations' => array(
		'metafields',
	),
	// Environment and staticdata affect admin UI
	'environment'  => array(
		'adminui',
		'admin',
	),
	'staticdata'   => array(
		'adminui',
	),
	// Terminal groups (no dependencies)
	'admin'        => array(),
	'adminui'      => array(),
	'theme'        => array(),
	'postdata'     => array(),
	'permalinks'   => array(),
	'metafields'   => array(),
	'html'         => array(),
	'versions'     => array(),
	'blocks'       => array(),  // Translations dependency handled by versioned keys
	'shortcodes'   => array(),  // Translations dependency handled by versioned keys
);

// Groups eagerly warmed at init by Frl_Cache_Manager::auto_preload().
// This is a TRANSIENT-path optimization: on sites without a persistent object
// cache, auto_preload() collapses these groups into one batched LIKE query.
const FRL_CACHE_PRELOAD_FRONTEND_GROUPS = array(
	'options',      // Plugin settings
	'rewriter',     // Rewriter data
	'environment',  // Environment data
	'theme',        // Theme data
	'versions',     // Asset versions
	'html',         // HTML fragments
);

const FRL_CACHE_PRELOAD_BACKEND_GROUPS = array(
	'options',      // Site settings
	'environment',  // Environment data
	'theme',        // Theme data
	'versions',     // Plugin versions
	'admin',        // Admin metadata
);

// Groups that affect page rendering and need immediate browser refresh
const FRL_CACHE_RUNTIME_MAX_ITEMS = 1000;

const FRL_CACHE_BROWSER_GROUPS = array(
	'html',         // HTML fragments
	'permalinks',   // Permalinks
	'options',      // Plugin settings
	'shortcodes',   // Shortcodes
);

// Lock TTL for atomic operations
const FRL_CACHE_LOCK_TTL = 2;

// Maximum number of transients to fetch per group in admin UI (/2 for transients timeout)
const FRL_CACHE_MAX_TRANSIENTS_PER_GROUP = 50;
