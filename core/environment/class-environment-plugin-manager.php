<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Frl_Environment_Plugin_Manager {

	/**
	 * Apply plugins activation state.
	 *
	 * @param array $config The environment configuration array.
	 * @param array $results Reference to results array to populate.
	 * @return void
	 */
	public static function apply_plugins_activation_status( $config, &$results ) {
		if ( ! $config || empty( $config['plugins'] ) ) {
			return;
		}

		$ignored_plugins = frl_get_option( Frl_Environment_Manager::IGNORE_PLUGINS_KEY ) ?? array();

		if ( ! empty( $config['plugins']['active'] ) ) {
			self::process_plugins_activation_status(
				$config['plugins']['active'],
				false,
				$ignored_plugins,
				$results
			);
		}
		if ( ! empty( $config['plugins']['inactive'] ) ) {
			self::process_plugins_activation_status(
				$config['plugins']['inactive'],
				true,
				$ignored_plugins,
				$results
			);
		}
	}

	/**
	 * Callbacks that rewrite every programmatic `active_plugins` write.
	 *
	 * Freesoul Deactivate Plugins hooks `pre_update_option_active_plugins` to answer with the
	 * list it captured at bootstrap, which silently reverts this reset.
	 */
	private const BLOCKING_ACTIVE_PLUGIN_FILTERS = array( 'eos_dp_return_all_plugins' );

	/**
	 * Suspend the third-party filters that revert `active_plugins` writes.
	 *
	 * @return array<string, int> Suspended callbacks mapped to their priority.
	 */
	private static function suspend_blocking_filters(): array {
		$suspended = array();
		foreach ( self::BLOCKING_ACTIVE_PLUGIN_FILTERS as $callback ) {
			$priority = has_filter( 'pre_update_option_active_plugins', $callback );
			if ( false === $priority ) {
				continue;
			}
			remove_filter( 'pre_update_option_active_plugins', $callback, $priority );
			$suspended[ $callback ] = $priority;
		}
		return $suspended;
	}

	/**
	 * Re-attach the filters suspended by suspend_blocking_filters().
	 *
	 * @param array<string, int> $suspended Suspended callbacks mapped to their priority.
	 * @return void
	 */
	private static function restore_blocking_filters( array $suspended ): void {
		foreach ( $suspended as $callback => $priority ) {
			add_filter( 'pre_update_option_active_plugins', $callback, $priority );
		}
	}

	/**
	 * Process plugins activation/deactivation based on environment.
	 *
	 * @param array $plugins Array of plugin paths to process.
	 * @param bool $should_deactivate True to deactivate, false to activate.
	 * @param array $ignored_plugins Array of ignored plugin paths.
	 * @param array $results Reference to results array to populate.
	 * @return void
	 */
	public static function process_plugins_activation_status( $plugins, $should_deactivate, $ignored_plugins, &$results ) {
		if ( empty( $plugins ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		remove_action( 'activated_plugin', array( Frl_Environment_Manager::class, 'track_plugins_activation_status' ), 10 );
		remove_action( 'deactivated_plugin', array( Frl_Environment_Manager::class, 'track_plugins_activation_status' ), 10 );

		$plugins_to_change = array();

		foreach ( $plugins as $plugin ) {
			if ( is_array( $ignored_plugins ) && in_array( $plugin, $ignored_plugins, true ) ) {
				if ( ! in_array( $plugin, $results['plugins']['ignored'], true ) ) {
					$results['plugins']['ignored'][] = $plugin;
				}
				continue;
			}

			$is_active = is_plugin_active( $plugin );

			if ( ( $should_deactivate && $is_active ) || ( ! $should_deactivate && ! $is_active ) ) {
				$plugins_to_change[] = $plugin;
			} else {
				if ( ! in_array( $plugin, $results['plugins']['no_change'], true ) ) {
					$results['plugins']['no_change'][] = $plugin;
				}
			}
		}

		if ( ! empty( $plugins_to_change ) ) {
			$suspended_filters = self::suspend_blocking_filters();
			try {
				if ( $should_deactivate ) {
					foreach ( $plugins_to_change as $plugin ) {
						deactivate_plugins( array( $plugin ), false );
						if ( is_plugin_active( $plugin ) ) {
							$results['plugins']['update_error'][] = "{$plugin}: failed to deactivate";
						} else {
							$results['plugins']['deactivated'][] = $plugin;
						}
					}
				} else {
					foreach ( $plugins_to_change as $plugin ) {
						$result = activate_plugin( $plugin, '', false, false );
						if ( is_wp_error( $result ) ) {
							$results['plugins']['update_error'][] = "{$plugin}: " . $result->get_error_message();
						} else {
							$results['plugins']['activated'][] = $plugin;
						}
					}
				}
			} finally {
				self::restore_blocking_filters( $suspended_filters );
			}
		}

		add_action( 'activated_plugin', array( Frl_Environment_Manager::class, 'track_plugins_activation_status' ), 10, 1 );
		add_action( 'deactivated_plugin', array( Frl_Environment_Manager::class, 'track_plugins_activation_status' ), 10, 1 );
	}
}
