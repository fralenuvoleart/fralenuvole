<?php
/**
 * Invariant: the registered-slug hash is compared once per request, and a
 * steady request performs no option writes.
 *
 * Regression guarded: hashing the registry on every registered_post_type /
 * registered_taxonomy call made the hash partial, so every request rewrote the
 * hash and bumped the exclusion version once per type (and the exclusion cache
 * never hit).
 *
 * @package Fralenuvole
 */

require __DIR__ . '/lib/wp-stub.php';
require __DIR__ . '/lib/assert.php';

require FRL_TEST_PLUGIN_DIR . 'core/rewriter/class-rewriter-path-utils.php';

frl_test_reset();

$state = &frl_test_state();

// The plugin's own public types plus the core public taxonomies.
frl_test_set_post_types(
	array(
		'service'     => (object) array(
			'public'  => true,
			'rewrite' => array( 'slug' => 'service' ),
		),
		'lp'          => (object) array(
			'public'  => true,
			'rewrite' => array( 'slug' => 'lp' ),
		),
		'team-member' => (object) array(
			'public'  => true,
			'rewrite' => array( 'slug' => 'about' ),
		),
		'university'  => (object) array(
			'public'  => true,
			'rewrite' => array( 'slug' => 'university' ),
		),
	)
);
frl_test_set_taxonomies(
	array(
		'category'         => (object) array(
			'public'  => true,
			'rewrite' => array( 'slug' => 'category' ),
		),
		'post_tag'         => (object) array(
			'public'  => true,
			'rewrite' => array( 'slug' => 'tag' ),
		),
		'service_category' => (object) array(
			'public'  => true,
			'rewrite' => array( 'slug' => 'service_category' ),
		),
		'service_tag'      => (object) array(
			'public'  => true,
			'rewrite' => array( 'slug' => 'service_tag' ),
		),
		'team_category'    => (object) array(
			'public'  => true,
			'rewrite' => array( 'slug' => 'team_category' ),
		),
		'team_tag'         => (object) array(
			'public'  => true,
			'rewrite' => array( 'slug' => 'team_tag' ),
		),
	)
);

$full_hash = Frl_Rewriter_Path_Utils::compute_registered_slugs_hash();
frl_test_same( $full_hash, Frl_Rewriter_Path_Utils::compute_registered_slugs_hash(), 'registered-slug hash is deterministic' );

// Seed the options a completed request would have stored.
frl_test_set_option( '_frl_rewriter_slugs_hash', $full_hash );
frl_test_set_option( '_frl_rewriter_excl_version', 10 );

// 1. Steady request: one compare, zero writes.
frl_test_reset_writes();
Frl_Rewriter_Path_Utils::refresh_registered_slugs_hash();
frl_test_same( 0, frl_test_write_count(), 'steady request performs no option writes' );
frl_test_same( 10, (int) get_option( '_frl_rewriter_excl_version' ), 'steady request does not bump the exclusion version' );

// 2. The following request is steady too.
frl_test_reset_writes();
Frl_Rewriter_Path_Utils::refresh_registered_slugs_hash();
frl_test_same( 0, frl_test_write_count(), 'second steady request performs no option writes' );
frl_test_same( 10, (int) get_option( '_frl_rewriter_excl_version' ), 'second steady request keeps the version' );

// 3. A new public CPT with a rewrite slug changes the set: exactly one hash
// write and one version bump.
$state['post_types']['gd_place'] = (object) array(
	'public'  => true,
	'rewrite' => array( 'slug' => 'places' ),
);
$changed_hash                    = Frl_Rewriter_Path_Utils::compute_registered_slugs_hash();
frl_test_ok( $changed_hash !== $full_hash, 'added post type changes the registered-slug hash' );

frl_test_reset_writes();
Frl_Rewriter_Path_Utils::refresh_registered_slugs_hash();
frl_test_same( 2, frl_test_write_count(), 'changed set writes the hash and the version once each' );
frl_test_same( 11, (int) get_option( '_frl_rewriter_excl_version' ), 'changed set bumps the version once' );
frl_test_same( $changed_hash, get_option( '_frl_rewriter_slugs_hash' ), 'changed set stores the new hash' );

// 4. Back to steady.
frl_test_reset_writes();
Frl_Rewriter_Path_Utils::refresh_registered_slugs_hash();
frl_test_same( 0, frl_test_write_count(), 'request after the change performs no option writes' );
frl_test_same( 11, (int) get_option( '_frl_rewriter_excl_version' ), 'request after the change keeps the version' );

// 5. The per-registration hooks are gone: they would hash a partial registry.
$rewriter_source = (string) file_get_contents( FRL_TEST_PLUGIN_DIR . 'core/rewriter/class-rewriter.php' );
// Either quote style; a hook name built from a variable is not covered.
frl_test_ok( ! preg_match( '/[\'"]registered_post_type[\'"]/', $rewriter_source ), 'no per-post-type hash refresh hook' );
frl_test_ok( ! preg_match( '/[\'"]registered_taxonomy[\'"]/', $rewriter_source ), 'no per-taxonomy hash refresh hook' );
frl_test_ok( str_contains( $rewriter_source, "'refresh_registered_slugs_hash'" ), 'the single hash refresh callback is registered' );

frl_test_done();
