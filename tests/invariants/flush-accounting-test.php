<?php
/**
 * Invariant: one rewrite-rules flush per requested change, never from a
 * frontend request, and an init-time flush request survives a request that
 * exits before wp_loaded.
 *
 * Regressions guarded:
 * - a settings change flushing in the same request (stale init-time rules);
 * - an init-time flush (admin action) being lost because the dispatcher
 *   redirects and exits before wp_loaded;
 * - a frontend visitor triggering a flush;
 * - activation scheduling two flush events.
 *
 * @package Fralenuvole
 */

require __DIR__ . '/lib/wp-stub.php';
require __DIR__ . '/lib/assert.php';

require FRL_TEST_PLUGIN_DIR . 'includes/plugin-lifecycle.php';

/**
 * Start a fresh request with the hooks a real request registers.
 *
 * @param bool $admin     Admin request.
 * @param bool $cron      Cron request.
 * @param bool $logged_in Logged-in user.
 * @return void
 */
function frl_test_begin_request( bool $admin, bool $cron, bool $logged_in ): void {
	$state = &frl_test_state();

	$state['did']        = array();
	$state['actions']    = array();
	$state['is_admin']   = $admin;
	$state['doing_cron'] = $cron;
	$state['logged_in']  = $logged_in;

	// A new process starts with both one-shot flags cleared.
	frl_rewrite_flush_set_this_request( false );
	frl_rewrite_flush_is_direct( false );

	add_action( 'wp_loaded', 'frl_maybe_flush_pending_rewrite_rules', 20, 0 );
	add_action( 'frl_flush_pending_rewrite_rules', 'frl_maybe_flush_pending_rewrite_rules', 10, 0 );
	add_action( 'frl_flush_rewrite_rules', 'frl_flush_rewrite_rules', 10, 0 );
}

/**
 * Number of rule regenerations performed so far.
 *
 * @return int
 */
function frl_test_flush_count(): int {
	$state = &frl_test_state();
	return (int) $state['flushes'];
}

frl_test_reset();

// 1. A settings change at init requests a flush for the next request.
frl_test_begin_request( true, false, true );
frl_request_rewrite_flush();
frl_test_ok( frl_is_rewrite_flush_pending(), 'setting change records a pending flush' );
frl_test_same( 0, frl_test_flush_count(), 'setting change does not flush in the same request' );
do_action( 'wp_loaded' );
frl_test_same( 0, frl_test_flush_count(), 'same-request wp_loaded still does not flush the change' );
frl_test_ok( frl_is_rewrite_flush_pending(), 'pending flag survives the request that raised it' );

// 2. The next admin request consumes it exactly once.
frl_test_begin_request( true, false, true );
do_action( 'wp_loaded' );
frl_test_same( 1, frl_test_flush_count(), 'next admin request flushes once' );
frl_test_ok( ! frl_is_rewrite_flush_pending(), 'pending flag cleared after the flush' );

// 3. A frontend request never flushes a pending change.
frl_test_set_option( '_frl_rewrite_flush_pending', 1 );
frl_test_begin_request( false, false, false );
do_action( 'wp_loaded' );
frl_test_same( 1, frl_test_flush_count(), 'frontend request does not flush' );
frl_test_ok( frl_is_rewrite_flush_pending(), 'frontend request keeps the flag for the next admin/cron request' );

// 4. An init-time flush request (the admin action) is not lost when the request
// exits before wp_loaded: the next admin request performs it.
frl_test_set_option( '_frl_rewrite_flush_pending', 0 );
frl_test_begin_request( true, false, true );
frl_flush_rewrite_rules();
frl_test_same( 1, frl_test_flush_count(), 'init-time flush does not regenerate rules in place' );
frl_test_ok( frl_is_rewrite_flush_pending(), 'init-time flush leaves a pending flush behind' );
frl_test_ok( wp_next_scheduled( 'frl_flush_pending_rewrite_rules' ), 'init-time flush schedules the cron fallback' );
// The dispatcher redirects and exits here: wp_loaded never fires.

frl_test_begin_request( true, false, true );
do_action( 'wp_loaded' );
frl_test_same( 2, frl_test_flush_count(), 'next admin request performs the init-time flush' );

// 5. When wp_loaded does fire in the same request, the early call still results
// in exactly one regeneration.
frl_test_begin_request( true, false, true );
frl_flush_rewrite_rules();
do_action( 'wp_loaded' );
frl_test_same( 3, frl_test_flush_count(), 'early call plus wp_loaded regenerates once, not twice' );
frl_test_ok( ! frl_is_rewrite_flush_pending(), 'wp_loaded flush clears the pending flag' );

// 6. A cron request consumes the pending flag once; the cron callback that
// follows does not flush again.
frl_test_set_option( '_frl_rewrite_flush_pending', 1 );
frl_test_begin_request( false, true, false );
do_action( 'wp_loaded' );
frl_test_same( 4, frl_test_flush_count(), 'cron request flushes a pending change once' );
do_action( 'frl_flush_pending_rewrite_rules' );
frl_test_same( 4, frl_test_flush_count(), 'cron fallback does not flush again after wp_loaded' );

// 7. Activation requests the flush without scheduling the legacy event too.
$state         = &frl_test_state();
$state['cron'] = array();
frl_request_rewrite_flush();
frl_test_same( array( 'frl_flush_pending_rewrite_rules' ), array_keys( $state['cron'] ), 'one cron event per requested flush' );

$lifecycle_source = (string) file_get_contents( FRL_TEST_PLUGIN_DIR . 'includes/plugin-lifecycle.php' );
preg_match( '/function frl_activate_plugin\(\): void \{(.*?)\n\}/s', $lifecycle_source, $matches );
$activation_body = $matches[1] ?? '';
// Ignore comments so prose cannot satisfy (or fail) a code assertion.
$activation_code = (string) preg_replace( '#/\*.*?\*/|//[^\n]*#s', '', $activation_body );
frl_test_ok( '' !== $activation_body, 'activation function body found' );
frl_test_ok( str_contains( $activation_code, 'frl_request_rewrite_flush()' ), 'activation requests the next-request flush' );
frl_test_ok( ! str_contains( $activation_code, 'frl_schedule_rewrite_flush()' ), 'activation does not also schedule the legacy flush event' );

frl_test_done();
