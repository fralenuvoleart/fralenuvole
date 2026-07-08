<?php
/**
 * Translation Adapter Loader
 *
 * Requires the concrete adapter file matching the detected plugin.
 * This mapping lives here, inside the adapters package, so translator.php
 * (the module entry point) never needs to know a specific adapter's
 * filename or be updated when a new adapter is added.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/interface.php';

if ( frl_is_polylang_active() ) {
	require_once __DIR__ . '/polylang.php';
}
// elseif ( frl_is_wpml_active() ) { require_once __DIR__ . '/wpml.php'; }
