<?php
/**
 * Invariant: pure contracts hardened during the 2026-11-24 audit.
 *
 * Guards:
 *  - Rewriter slash collapsing preserves protocol-relative (//) and schemed URLs.
 *  - Schema dot-path translation rules match exact/child paths, never sibling prefixes.
 *  - Module file-path helpers reject path traversal (.., /, \).
 *  - Cache config groups stay consistent across persistent, heavy, TTL, and language lists.
 *  - Admin option-save nonce verification enforces FRL_PLUGIN_ACCESS via frl_has_access().
 *  - Numeric filename nicename normalization does not strip purely numeric basenames to empty.
 *
 * @package Fralenuvole
 */

require __DIR__ . '/lib/wp-stub.php';
require __DIR__ . '/lib/assert.php';

if ( ! defined( 'FRL_MODULES_PATH' ) ) {
	define( 'FRL_MODULES_PATH', FRL_TEST_PLUGIN_DIR . 'modules/' );
}
if ( ! defined( 'FRL_MODULES_CONFIG_FILENAME' ) ) {
	define( 'FRL_MODULES_CONFIG_FILENAME', 'config-options-' );
}
if ( ! function_exists( 'frl_log' ) ) {
	function frl_log( $message, $context = array(), $force_email = false ) {}
}

require FRL_TEST_PLUGIN_DIR . 'config/config-cache.php';
require FRL_TEST_PLUGIN_DIR . 'core/rewriter/class-rewriter-path-utils.php';
require FRL_TEST_PLUGIN_DIR . 'includes/helpers/functions-schema.php';
require FRL_TEST_PLUGIN_DIR . 'includes/helpers/functions-modules.php';

frl_test_reset();

// 1. Rewriter path utils: collapse_slashes() preserves protocol-relative & schemed URLs.
frl_test_same(
	'//cdn.example.com/assets/app.js',
	Frl_Rewriter_Path_Utils::collapse_slashes( '//cdn.example.com//assets///app.js' ),
	'collapse_slashes preserves protocol-relative // prefix'
);
frl_test_same(
	'https://example.com/a/b/',
	Frl_Rewriter_Path_Utils::collapse_slashes( 'https://example.com//a///b/' ),
	'collapse_slashes preserves https:// scheme'
);
frl_test_same(
	'/a/b/c',
	Frl_Rewriter_Path_Utils::collapse_slashes( '/a//b///c' ),
	'collapse_slashes collapses relative paths'
);

// 2. Schema dot-path matching: exact or child prefix only, never sibling prefix.
frl_test_same(
	false,
	frl_schema_should_translate_key( 'name', 'author.name', array( '!author.name' ) ),
	'dot-path skip rule matches exact path'
);
frl_test_same(
	false,
	frl_schema_should_translate_key( 'given', 'author.name.given', array( '!author.name' ) ),
	'dot-path skip rule matches child path'
);
frl_test_same(
	true,
	frl_schema_should_translate_key( 'namespace', 'author.namespace', array( '!author.name', 'namespace' ) ),
	'dot-path skip rule does not match sibling prefix (author.namespace vs author.name)'
);
frl_test_same(
	true,
	frl_schema_should_translate_key( 'name', 'author.name', array( 'author.name' ) ),
	'dot-path include rule matches exact path'
);
frl_test_same(
	false,
	frl_schema_should_translate_key( 'namespace', 'author.namespace', array( 'author.name' ) ),
	'dot-path include rule does not match sibling prefix'
);

// 3. Module path traversal guards reject .., /, and \.
foreach ( array( '..', '../etc', 'foo/bar', 'foo\\bar', 'foo..bar', '' ) as $bad_key ) {
	frl_test_same(
		false,
		frl_modules_module_get_file_path( $bad_key ),
		'frl_modules_module_get_file_path rejects unsafe key: ' . var_export( $bad_key, true )
	);
	frl_test_same(
		false,
		frl_modules_module_get_config_file_path( $bad_key ),
		'frl_modules_module_get_config_file_path rejects unsafe key: ' . var_export( $bad_key, true )
	);
}

// 4. Cache config consistency: every heavy & language group is in persistent groups and has a TTL.
frl_test_ok( in_array( 'shortcodes', FRL_CACHE_HEAVY_GROUPS, true ), 'FRL_CACHE_HEAVY_GROUPS contains shortcodes' );
frl_test_ok( ! in_array( 'shortodes', FRL_CACHE_HEAVY_GROUPS, true ), 'FRL_CACHE_HEAVY_GROUPS has no shortodes typo' );

foreach ( FRL_CACHE_HEAVY_GROUPS as $group ) {
	frl_test_ok(
		in_array( $group, FRL_CACHE_PERSISTENT_GROUPS, true ),
		"heavy group '{$group}' is listed in FRL_CACHE_PERSISTENT_GROUPS"
	);
	frl_test_ok(
		isset( FRL_CACHE_TTL[ $group ] ),
		"heavy group '{$group}' has a TTL in FRL_CACHE_TTL"
	);
}
foreach ( FRL_CACHE_LANGUAGE_GROUPS as $group ) {
	frl_test_ok(
		in_array( $group, FRL_CACHE_PERSISTENT_GROUPS, true ),
		"language group '{$group}' is listed in FRL_CACHE_PERSISTENT_GROUPS"
	);
}

// 5. Source-level contract: frl_verify_admin_action_nonce() enforces frl_has_access() + FRL_PLUGIN_ACCESS.
$ac_src = file_get_contents( FRL_TEST_PLUGIN_DIR . 'includes/helpers/functions-access-control.php' );
frl_test_ok( is_string( $ac_src ), 'functions-access-control.php readable' );
preg_match( '/function frl_verify_admin_action_nonce\(.*?\n\}/s', (string) $ac_src, $ac_match );
frl_test_ok( ! empty( $ac_match[0] ), 'frl_verify_admin_action_nonce() body found' );
frl_test_ok(
	str_contains( $ac_match[0], 'frl_has_access()' ),
	'frl_verify_admin_action_nonce() checks frl_has_access()'
);
frl_test_ok(
	str_contains( $ac_match[0], 'FRL_PLUGIN_ACCESS' ),
	'frl_verify_admin_action_nonce() passes FRL_PLUGIN_ACCESS to frl_verify_simple_nonce()'
);

// 6. Source-level contract: frl_get_file_nicename() preserves purely numeric basenames.
$admin_src = file_get_contents( FRL_TEST_PLUGIN_DIR . 'admin/admin.php' );
frl_test_ok( is_string( $admin_src ), 'admin/admin.php readable' );
preg_match( '/function frl_get_file_nicename\(.*?\n\}/s', (string) $admin_src, $nicename_match );
frl_test_ok( ! empty( $nicename_match[0] ), 'frl_get_file_nicename() body found' );
frl_test_ok(
	str_contains( $nicename_match[0], "\$stripped !== ''" ),
	'frl_get_file_nicename() keeps purely numeric basenames non-empty'
);

frl_test_done();
