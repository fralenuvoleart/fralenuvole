<?php
/**
 * Invariant: the cache-key contract every writer and clearer shares.
 *
 * Language groups prefix the key with the language; non-language groups ignore
 * it; array keys are hashed (so paths with slashes cannot collide).
 *
 * Regressions guarded: a writer and a clearer computing different keys, and
 * sanitize_key() collapsing hierarchical paths.
 *
 * @package Fralenuvole
 */

require __DIR__ . '/lib/wp-stub.php';
require __DIR__ . '/lib/assert.php';

require FRL_TEST_PLUGIN_DIR . 'config/config-cache.php';
require FRL_TEST_PLUGIN_DIR . 'core/cache/class-cache-manager.php';

frl_test_reset();

$state              = &frl_test_state();
$state['language']  = 'it';
$state['languages'] = array( 'en', 'it' );

$language_groups = new ReflectionProperty( Frl_Cache_Manager::class, 'language_groups_map' );
$language_groups->setAccessible( true );
$language_groups->setValue( null, array_flip( FRL_CACHE_LANGUAGE_GROUPS ) );

$generate_key = new ReflectionMethod( Frl_Cache_Manager::class, 'generate_key' );
$generate_key->setAccessible( true );

frl_test_ok( in_array( 'permalinks', FRL_CACHE_LANGUAGE_GROUPS, true ), 'permalinks is a language group' );
frl_test_ok( ! in_array( 'rewriter', FRL_CACHE_LANGUAGE_GROUPS, true ), 'rewriter is not a language group' );

// 1. A language-group writer uses the current language.
$written = $generate_key->invoke( null, 'permalinks', 'abc', null );
frl_test_same( 'permalinks_it_abc', $written, 'language-group key uses the current language' );

// 2. A clearer that passes the language explicitly matches the writer's key.
$cleared = $generate_key->invoke( null, 'permalinks', 'abc', 'it' );
frl_test_same( $written, $cleared, 'explicit-language clear key equals the writer key' );

// 3. Another language is a different entry.
$other = $generate_key->invoke( null, 'permalinks', 'abc', 'en' );
frl_test_same( 'permalinks_en_abc', $other, 'other language uses its own prefix' );
frl_test_ok( $written !== $other, 'languages never share a key' );

// 4. A non-language group ignores the language argument.
$non_language = $generate_key->invoke( null, 'rewriter', 'k', 'en' );
frl_test_same( 'rewriter_k', $non_language, 'non-language group ignores the language argument' );

// 5. Hierarchical paths are hashed, so "a/b" and "ab" cannot collide.
$path_slug = $generate_key->invoke( null, 'shortcodes', array( 'a/b' ), null );
$flat_slug = $generate_key->invoke( null, 'shortcodes', array( 'ab' ), null );
frl_test_ok( $path_slug !== $flat_slug, 'hashed array keys keep "a/b" and "ab" distinct' );
frl_test_same(
	$generate_key->invoke( null, 'shortcodes', array( 'a/b' ), null ),
	$path_slug,
	'array-key hashing is stable'
);

frl_test_done();
