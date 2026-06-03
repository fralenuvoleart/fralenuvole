<?php
/**
 * Cache Operations executor.
 *
 * Runtime dispatcher for the composite cache operations defined in
 * FRL_CACHE_OPERATIONS (config/config-cache-operations.php).
 * Executes steps sequentially, fires lifecycle hooks, and returns
 * per-step results for the caller to build UI messages from.
 *
 * @package Fralenuvole
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cache Operations executor.
 *
 * Usage:
 *   $results = Frl_Cache_Operations::run( 'action_hard' );
 *
 * Operation results are keyed by step index so callers can extract
 * the specific result they need for UI messages.
 *
 * @since 5.4.0
 */
final class Frl_Cache_Operations {

	/**
	 * Run a named cache operation.
	 *
	 * Operation definitions live in FRL_CACHE_OPERATIONS (config/config-cache-operations.php).
	 * Each step is executed in order; all steps always run (no early abort).
	 * The caller inspects per-step results and decides how to report.
	 *
	 * @param  string $operation  Key in FRL_CACHE_OPERATIONS.
	 * @return array{
	 *   operation: string,
	 *   label:     string,
	 *   success:   bool,
	 *   steps:     array,
	 *   error?:    string
	 * }
	 */
	public static function run( string $operation ): array {
		$config = \FRL_CACHE_OPERATIONS[ $operation ] ?? null;

		if ( null === $config ) {
			$msg = sprintf( 'Unknown cache operation: %s', $operation );
			frl_log( $msg );
			return [
				'operation' => $operation,
				'label'     => '',
				'success'   => false,
				'error'     => $msg,
				'steps'     => [],
			];
		}

		// Re-entrancy guard.
		$guard_key = __METHOD__ . '_' . $operation;
		if ( frl_is_already_running( $guard_key ) ) {
			$msg = sprintf( 'Cache operation already running: %s', $operation );
			frl_log( $msg );
			return [
				'operation' => $operation,
				'label'     => $config['label'],
				'success'   => false,
				'error'     => $msg,
				'steps'     => [],
			];
		}

		$results = [
			'operation' => $operation,
			'label'     => $config['label'],
			'steps'     => [],
			'success'   => true,
		];

		// Before hook.
		if ( ! empty( $config['hooks']['before'] ) ) {
			do_action( $config['hooks']['before'], $operation );
		}

		// Execute all steps sequentially (no early abort).
		foreach ( $config['steps'] as $index => $step ) {
			$fn   = $step['fn'];
			$args = $step['args'] ?? [];

			$step_result = [
				'step'     => $index + 1,
				'function' => is_array( $fn ) ? implode( '::', $fn ) : $fn,
				'args'     => $args,
				'success'  => false,
			];

			if ( is_callable( $fn ) ) {
				try {
					$returned                  = call_user_func_array( $fn, $args );
					$step_result['success']    = true;
					$step_result['result']     = $returned;
				} catch ( Throwable $e ) {
					$step_result['error'] = $e->getMessage();
					frl_log(
						'Cache operation step failed: {fn} - {error}',
						[
							'fn'    => $step_result['function'],
							'error' => $e->getMessage(),
						]
					);
				}
			} else {
				$step_result['error'] = sprintf(
					'Required function/method is not callable: %s',
					$step_result['function']
				);
				frl_log(
					'Cache operation step not callable: {fn}',
					[ 'fn' => $step_result['function'] ]
				);
			}

			$results['steps'][] = $step_result;

			if ( ! $step_result['success'] ) {
				$results['success'] = false;
				// Continue to next step — no abort.
			}
		}

		// After hook.
		if ( ! empty( $config['hooks']['after'] ) ) {
			do_action( $config['hooks']['after'], $operation, $results );
		}

		frl_is_already_running( $guard_key, true );

		return $results;
	}

	/**
	 * Get the full operation map for documentation and debugging.
	 *
	 * Returns every registered operation with its label, step descriptions,
	 * and notes. Useful for admin UI, logging, and developer reference.
	 *
	 * @return array<string, array{label: string, steps: array, hooks: array}>
	 */
	public static function get_operation_map(): array {
		$map = [];

		foreach ( \FRL_CACHE_OPERATIONS as $key => $config ) {
			$steps = [];
			foreach ( $config['steps'] as $step ) {
				$steps[] = [
					'function' => is_array( $step['fn'] ) ? implode( '::', $step['fn'] ) : $step['fn'],
					'args'     => $step['args'],
					'note'     => $step['note'] ?? '',
				];
			}

			$map[ $key ] = [
				'label' => $config['label'],
				'steps' => $steps,
				'hooks' => $config['hooks'] ?? [],
			];
		}

		return $map;
	}
}
