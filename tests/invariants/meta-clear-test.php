<?php
/**
 * Invariant: translated-meta invalidation covers every language and costs
 * nothing when no list was tracked.
 *
 * Regressions guarded:
 * - a tracking list cached under another language not being read;
 * - issuing deletes when nothing was tracked;
 * - a deleted language leaving its list and values behind until TTL.
 *
 * @package Fralenuvole
 */

require __DIR__ . '/lib/wp-stub.php';
require __DIR__ . '/lib/assert.php';

require FRL_TEST_PLUGIN_DIR . 'core/cache/cache-cleanup.php';

frl_test_reset();

$state              = &frl_test_state();
$state['languages'] = array( 'en', 'it' );
$state['language']  = 'en';
frl_test_set_option( 'frl_translation_version', 3 );

/**
 * Reset recorded cache operations.
 *
 * @return void
 */
function frl_test_reset_cache_calls(): void {
	$state                     = &frl_test_state();
	$state['clears']           = array();
	$state['clear_keys_calls'] = array();
}

// 1. No tracking list in any language: zero deletes.
frl_test_reset_cache_calls();
frl_clear_tracked_meta_cache( 'post', 5 );
frl_test_same( 0, count( $state['clear_keys_calls'] ), 'no tracked list issues no key deletes' );
frl_test_same( 0, count( $state['clears'] ), 'no tracked list issues no group clears' );

// 2. A list cached under another language is found and unioned.
$state['cache']['metafields|it|translation_postmeta_keys_5'] = array( 'field_a', 'field_b' );
frl_test_reset_cache_calls();
frl_clear_tracked_meta_cache( 'post', 5 );
frl_test_same( 1, count( $state['clear_keys_calls'] ), 'one batch clear per tracked object' );
frl_test_same(
	array( 'translation_postmeta_keys_5', 'translation_postmeta_5_field_a_3', 'translation_postmeta_5_field_b_3' ),
	$state['clear_keys_calls'][0][1],
	'clears the list and every tracked data key'
);
frl_test_same( array( 'en', 'it' ), $state['clear_keys_calls'][0][2], 'clears in every active language' );

// 3. Overlapping lists across languages are de-duplicated.
$state['cache']['metafields|en|translation_postmeta_keys_5'] = array( 'field_a' );
$state['cache']['metafields|it|translation_postmeta_keys_5'] = array( 'field_a', 'field_c' );
frl_test_reset_cache_calls();
frl_clear_tracked_meta_cache( 'post', 5 );
frl_test_same(
	array( 'translation_postmeta_keys_5', 'translation_postmeta_5_field_a_3', 'translation_postmeta_5_field_c_3' ),
	$state['clear_keys_calls'][0][1],
	'overlapping language lists are unioned without duplicates'
);

// 4. A list stored under a language that is no longer active is unreachable
// from the per-object clear (by design), so deleting the language purges the
// whole group instead.
$state['cache'] = array( 'metafields|fr|translation_postmeta_keys_5' => array( 'field_a' ) );
frl_test_reset_cache_calls();
frl_clear_tracked_meta_cache( 'post', 5 );
frl_test_same( 0, count( $state['clear_keys_calls'] ), 'removed language is not reachable per object' );

frl_clear_metafields_on_language_delete( 12, 34, 'language' );
frl_test_same( 1, count( $state['clears'] ), 'deleting a language purges the metafields group' );
frl_test_same( array( 'metafields', null, true ), $state['clears'][0], 'purge clears the whole group across languages' );

// 5. Deleting any other term leaves metafields alone.
frl_test_reset_cache_calls();
frl_clear_metafields_on_language_delete( 12, 34, 'category' );
frl_test_same( 0, count( $state['clears'] ), 'deleting a non-language term does not purge metafields' );

frl_test_done();
