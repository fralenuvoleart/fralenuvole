<?php
// Ensure WP_CLI constant exists for static analysis; define false for non-CLI requests.
if (!defined('WP_CLI')) {
    define('WP_CLI', false);
}

// Exit if not in WP-CLI context
if (!WP_CLI) {
    return;
}

// Provide minimal stub for static analysers when WP-CLI framework is absent (runtime ignores).
if (!class_exists('WP_CLI')) {
    class WP_CLI {
        public static function log($msg) {}
        public static function success($msg) {}
        public static function add_command($name, $callable) {}
    }
}

/**
 * WP-CLI commands for FRL rewriter diagnostics.
 */
class Frl_Rewriter_CLI {

    /**
     * Print statistics about rewrite features and rules.
     *
     * ## EXAMPLES
     *
     *     wp frl rewrites test
     */
    public function test( $args, $assoc_args ) {
        $rewriter = Frl_Rewriter::init();
        $coordinator = $rewriter->get_coordinator();

        /** @var WP_Rewrite $wp_rewrite */
        global $wp_rewrite;
        $rules_count = is_array( $wp_rewrite->wp_rewrite_rules() ) ? count( $wp_rewrite->wp_rewrite_rules() ) : 0;

        WP_CLI::log( "Total WP rewrite rules: {$rules_count}" );
        WP_CLI::log( str_repeat( '-', 40 ) );

        foreach ( $coordinator->get_features() as $feature ) {
            if ( ! method_exists( $feature, 'generate_rules' ) ) {
                continue;
            }
            /** @var object $feature Ensure PHPStan knows this is an object, not class-string */
            $patterns = array_keys( $feature->generate_rules() );
            $count    = count( $patterns );
            WP_CLI::log( sprintf( '%-40s %4d rules', $feature->get_name(), $count ) );
        }

        WP_CLI::success( 'Rewriter diagnostics finished.' );
    }
}

WP_CLI::add_command( 'frl rewrites', 'Frl_Rewriter_CLI' );
