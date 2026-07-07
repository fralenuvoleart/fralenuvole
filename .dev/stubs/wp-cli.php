<?php
/**
 * PHPStan/intelephense stub for the WP_CLI class.
 *
 * This class only exists at runtime when the site is actually being run
 * through the real WP-CLI framework, which provides its own WP_CLI class.
 * This stub is for static analysis only.
 */

if ( ! class_exists( 'WP_CLI' ) ) {
	class WP_CLI {
		public static function log( $msg ) {}
		public static function success( $msg ) {}
		public static function add_command( $name, $callback ) {}
	}
}
