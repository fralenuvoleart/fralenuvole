<?php
// Exit if not in a WP-CLI context.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * WP-CLI commands for FRL rewriter diagnostics.
 *
 * @package Fralenuvole
 * @since 3.0.0
 */
class Frl_Rewriter_CLI {

	/**
	 * Print statistics about rewrite features and rules.
	 *
	 * ## EXAMPLES
	 *
	 *     wp frl-rewrites test
	 *
	 * @param array $args Array of positional arguments
	 * @param array $assoc_args Array of associative arguments
	 * @return void
	 */
	public function test( $args, $assoc_args ) {
		$rewriter    = Frl_Rewriter::init();
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

		WP_CLI::log( str_repeat( '-', 40 ) );
		WP_CLI::log(
			$coordinator->validate_all_features()
				? 'Feature validation: no pattern or routing conflicts'
				: 'Feature validation: FAILED - see the rewrite log for the conflicting feature'
		);

		WP_CLI::success( 'Rewriter diagnostics finished.' );
	}
}

WP_CLI::add_command( 'frl-rewrites', 'Frl_Rewriter_CLI' );
