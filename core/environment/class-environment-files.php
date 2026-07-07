<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-environment-config.php';

class Frl_Environment_Files {

	/**
	 * Get list of option keys configured as 'file' that have a physical file for the current env prefix.
	 *
	 * @return array List of option keys with existing environment files.
	 */
	public static function get_file_options_keys() {
		$config = Frl_Environment_Config::get_domain_config();
		if ( $config === null ) {
			return array();
		}

		if ( empty( $config['prefix'] ) ) {
			frl_log( 'Frl_Environment_Files::get_file_options_keys called with a config missing a prefix.' );
			return array();
		}

		$cache_key = $config['prefix'] . '_file_option_keys';

		return frl_cache_remember(
			Frl_Environment_Manager::CACHE_GROUP,
			$cache_key,
			function () use ( $config ) {
				$active_file_options = array();

				if ( ! frl_is_array_not_empty( $config, 'plugin_options' ) ) {
					return $active_file_options;
				}

				foreach ( $config['plugin_options'] as $key => $value ) {
					if ( $value === 'file' ) {
						$filename = $config['prefix'] . '_' . $key . '.php';
						if ( file_exists( Frl_Environment_Manager::ENV_FILES_PATH . $filename ) ) {
							$active_file_options[] = $key;
						}
					}
				}
				return array_unique( $active_file_options );
			}
		);
	}

	/**
	 * Load raw file content from environment includes, with caching.
	 *
	 * @param string $option_name The option name to load.
	 * @return string|null The file content or null if not found.
	 */
	public static function load_environment_file( $option_name ) {
		$config = Frl_Environment_Config::get_domain_config();
		if ( $config === null || empty( $config['prefix'] ) ) {
			return null;
		}

		$cache_key = $config['prefix'] . '_file_option_' . $option_name;

		return frl_cache_remember(
			Frl_Environment_Manager::CACHE_GROUP,
			$cache_key,
			function () use ( $config, $option_name ) {
				$filename = $config['prefix'] . '_' . $option_name . '.php';
				$filepath = Frl_Environment_Manager::ENV_FILES_PATH . $filename;

				if ( ! file_exists( $filepath ) ) {
					return null;
				}

				$content = file_get_contents( $filepath );

				if ( str_contains( $content, '<?php' ) ) {
					// Validate PHP syntax by evaluating in a sandbox.
					// Advisory only: the check never alters the returned $content —
					// it exists solely to surface a helpful log entry when an admin's
					// custom snippet has a syntax error.
					$has_syntax_error = false;
					$error_message    = '';

					// Guard against hosts that disable exec() via disable_functions
					// (common hardening on managed/shared WordPress hosting). Attempting
					// a call to a disabled function throws \Error (not \Exception), so
					// we both pre-check availability AND widen the catch below.
					if ( ! function_exists( 'exec' ) ) {
						frl_log( 'Skipping PHP syntax check for environment file (exec() unavailable on this host): {file}', array( 'file' => $filepath ) );
					} else {
						try {
							// Create a temporary file to test syntax
							$temp_file = tempnam( sys_get_temp_dir(), 'env_' );
							file_put_contents( $temp_file, $content );

							// Use php -l to check syntax
							$output      = array();
							$return_code = 0;
							exec( 'php -l ' . escapeshellarg( $temp_file ), $output, $return_code );

							if ( $return_code !== 0 ) {
								$has_syntax_error = true;
								$error_message    = implode( "\n", $output );
							}

							unlink( $temp_file );
						} catch ( \Throwable $e ) {
							// Catches both Exception and Error (e.g. exec() disabled at
							// runtime despite function_exists() reporting it callable,
							// or any other unexpected failure) — never let this advisory
							// check crash environment enforcement.
							$has_syntax_error = true;
							$error_message    = $e->getMessage();
						}

						if ( $has_syntax_error ) {
							frl_log(
								'PHP syntax error in environment file: {file} - {error}',
								array(
									'file'  => $filepath,
									'error' => $error_message,
								)
							);
						}
					}
				}

				return $content;
			}
		);
	}
}
