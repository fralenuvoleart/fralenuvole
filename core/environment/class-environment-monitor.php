<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-environment-config.php';
require_once __DIR__ . '/class-environment-utils.php';

class Frl_Environment_Monitor {

	use Frl_Environment_Host_Normalizer;

	/**
	 * Set up hooks for tracking option changes.
	 *
	 * @return void
	 */
	public static function setup_plugin_options_tracking() {
		$config = Frl_Environment_Config::get_domain_config();
		if ( ! $config || empty( $config['plugin_options'] ) ) {
			return;
		}

		// Build O(1) lookup map: prefixed_option_name → unprefixed config_key
		$managed_options = array();
		foreach ( array_keys( $config['plugin_options'] ) as $key ) {
			$managed_options[ frl_prefix( $key ) ] = $key;
		}

		// Single updated_option hook replaces 15+ individual update_option_{$name} hooks.
		// updated_option (WP 4.7+) passes 3 args: $option, $old_value, $new_value
		add_action(
			'updated_option',
			function ( $option, $old_value, $new_value ) use ( $managed_options ) {
				if ( isset( $managed_options[ $option ] ) ) {
					$config_key = $managed_options[ $option ];
					Frl_Environment_Monitor::track_plugin_options( $config_key, $old_value, $new_value );
				}
			},
			10,
			3
		);
	}

	/**
	 * Track manual option changes.
	 *
	 * @param string $option_name Option name without prefix.
	 * @param mixed $old_value Old option value.
	 * @param mixed $new_value New option value.
	 * @return void
	 */
	public static function track_plugin_options( $option_name, $old_value, $new_value ) {
		if ( $old_value === $new_value ) {
			return;
		}

		$config = Frl_Environment_Config::get_domain_config();
		if ( ! $config || empty( $config['plugin_options'] ) ) {
			return;
		}

		if ( ! array_key_exists( $option_name, $config['plugin_options'] ) ) {
			return;
		}

		$ignored_options = frl_get_option( Frl_Environment_Manager::IGNORE_OPTIONS_KEY ) ?? array();

		if ( is_array( $ignored_options ) && ! in_array( $option_name, $ignored_options, true ) ) {
			$ignored_options[] = $option_name;
			frl_update_option( Frl_Environment_Manager::IGNORE_OPTIONS_KEY, $ignored_options );
			frl_cache_set( Frl_Environment_Manager::CACHE_GROUP, Frl_Environment_Manager::IGNORE_OPTIONS_KEY, $ignored_options );
		}
	}

	/**
	 * Track manual plugin activation/deactivation changes.
	 *
	 * @param string $plugin Plugin basename (e.g., 'akismet/akismet.php').
	 * @return void
	 */
	public static function track_plugins_activation_status( $plugin ) {
		static $processed              = array();
		static $cached_ignored_plugins = null; // Local request cache to avoid redundant option lookups

		if ( isset( $processed[ $plugin ] ) ) {
			return;
		}
		$processed[ $plugin ] = true;

		$config = Frl_Environment_Config::get_domain_config();
		if ( ! $config || empty( $config['plugins'] ) ) {
			return;
		}

		// Optimization: Use a static variable to avoid repeating array_merge on managed plugins
		static $cached_managed_plugins = null;
		if ( $cached_managed_plugins === null ) {
			$cached_managed_plugins = array_merge(
				$config['plugins']['active'] ?? array(),
				$config['plugins']['inactive'] ?? array()
			);
		}

		if ( ! is_array( $cached_managed_plugins ) || ! in_array( $plugin, $cached_managed_plugins, true ) ) {
			return;
		}

		// Load ignored plugins from option only once per request
		if ( $cached_ignored_plugins === null ) {
			$cached_ignored_plugins = frl_get_option( Frl_Environment_Manager::IGNORE_PLUGINS_KEY ) ?? array();
		}

		if ( is_array( $cached_ignored_plugins ) && ! in_array( $plugin, $cached_ignored_plugins, true ) ) {
			$cached_ignored_plugins[] = $plugin;
			frl_update_option( Frl_Environment_Manager::IGNORE_PLUGINS_KEY, $cached_ignored_plugins );
			frl_cache_set( Frl_Environment_Manager::CACHE_GROUP, Frl_Environment_Manager::IGNORE_PLUGINS_KEY, $cached_ignored_plugins );
		}
	}

	/**
	 * Check and update URLs for the current environment.
	 * Compares current HTTP_HOST to siteurl/home and updates
	 * options if a mismatch is detected. Called on admin/migrate page loads.
	 *
	 * @return void
	 */
	public static function check_urls() {
		if ( frl_is_already_running( __METHOD__ ) ) {
			return;
		}

		$current_user_id = frl_get_current_user()->ID;
		$auth_cookie     = wp_parse_auth_cookie( '', 'logged_in' );

		$current_host = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : '';
		if ( empty( $current_host ) ) {
			return; // Skip URL checks in contexts without HTTP_HOST (CLI, cron)
		}

		$site_url = site_url();
		$home_url = home_url();

		$site_host = parse_url( $site_url, PHP_URL_HOST );
		$home_host = parse_url( $home_url, PHP_URL_HOST );
		if ( empty( $site_host ) || empty( $home_host ) ) {
			return; // Abort if URLs cannot be parsed safely
		}

		$config = Frl_Environment_Config::get_domain_config();
		if ( $config === null ) {
			return;
		}

		$current_host_norm = self::normalize_host_value( $current_host );
		$site_host_norm    = self::normalize_host_value( $site_host );
		$home_host_norm    = self::normalize_host_value( $home_host );

		if ( $current_host_norm !== $site_host_norm || $current_host_norm !== $home_host_norm ) {
			$target_host      = $config['current_host'] ?? $config['env_host'] ?? $current_host;
			$target_host_norm = self::normalize_host_value( $target_host );
			$new_siteurl      = preg_replace( '#^(https?://)' . preg_quote( $site_host, '#' ) . '#i', '$1' . $target_host_norm, $site_url );
			$new_home         = preg_replace( '#^(https?://)' . preg_quote( $home_host, '#' ) . '#i', '$1' . $target_host_norm, $home_url );

			if ( ! str_contains( $new_siteurl, '://' ) || ! str_contains( $new_home, '://' ) ) {
				frl_log( 'Invalid URL format detected during URL update' );
				return;
			}

			// Track if any changes were actually made to avoid redundant reloads/resets
			$changed_siteurl = update_option( 'siteurl', $new_siteurl );
			$changed_home    = update_option( 'home', $new_home );

			if ( $changed_siteurl || $changed_home ) {
				if ( isset( $config['wp_options']['blog_public'] ) ) {
					update_option( 'blog_public', $config['wp_options']['blog_public'] );
				}

				Frl_Environment_Manager::reset_customizations();

				if ( $current_user_id && $auth_cookie ) {
					wp_set_auth_cookie( $current_user_id, true );
				}

				// Set force reload flag (now using stable cookie-based helper)
				frl_set_force_reload_flag();

				Frl_Environment_Manager::enforce_environment_settings( true );
			}
		}
	}
}
