<?php



/*
 * Contains all exclusion logic and throttle functions for the MU plugin loader.
 * Required only by assets/mu/frl-mu-plugin.php after bootstrap is loaded,
 * so all core helpers (frl_get_option, frl_is_admin, frl_textlist_to_array, etc.)
 * are available when these functions are called.
 *
 * @package Fralenuvole
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks User-Agent against throttled bot patterns and drops the connection
 * with a configured HTTP status code (default 429) if the rate limit is exceeded.
 *
 * Must be called at top-level during MU plugin loading — before any WordPress
 * output begins — to ensure header() calls succeed.
 *
 * Uses frl_get_transient / frl_set_transient which add static caching and
 * consistent prefixing (frl_) on top of WordPress transients.
 *
 * @return void
 */
function frl_maybe_throttle_user_agent(): void {
	if ( empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
		return;
	}

	$is_throttled_bot = false;
	foreach ( FRL_MU_THROTTLE_USER_AGENT as $ua_pattern ) {
		if ( stripos( $_SERVER['HTTP_USER_AGENT'], $ua_pattern ) !== false ) {
			$is_throttled_bot = true;
			break;
		}
	}

	if ( ! $is_throttled_bot ) {
		return;
	}

	// Determine Real IP (Cloudflare Optimized)
	$ip = $_SERVER['REMOTE_ADDR'];
	if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
		$ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
	} elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
		$ip = trim( explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] )[0] );
	}

	$transient_key = 'bot_throttle_' . md5( $ip );
	$request_count = (int) frl_get_transient( $transient_key );

	if ( $request_count >= FRL_MU_THROTTLE_LIMIT ) {
		http_response_code( FRL_MU_THROTTLE_STATUS_CODE );
		header( 'Content-Type: text/plain' );
		header( 'Retry-After: ' . (string) FRL_MU_THROTTLE_PERIOD );
		exit( 'Rate limit exceeded for AI Assistant bots.' );
	}

	// Increment count and refresh the block window
	frl_set_transient( $transient_key, $request_count + 1, FRL_MU_THROTTLE_PERIOD );
}

/**
 * Checks user access during early WordPress loading (before plugins_loaded).
 *
 * Used by the MU plugin's capability-based exclusion to verify user permissions
 * before WordPress user functions are available. Falls back to frl_has_access()
 * once plugins_loaded has fired.
 *
 * @param string $capability The capability to check for.
 * @return bool True if the user has access, false otherwise.
 */
function frl_mu_check_access( string $capability ): bool {
	// Once plugins_loaded has fired, delegate to standard frl_has_access()
	if ( did_action( 'plugins_loaded' ) ) {
		return frl_has_access( $capability );
	}

	// Early loading: use auth cookie directly
	$user_data = frl_get_auth_cookie_user_data();
	if ( ! $user_data ) {
		return false;
	}

	if ( $capability === 'superadmin' ) {
		return $user_data['id'] === FRL_PLUGIN_SUPERADMIN_ID;
	}

	if ( $user_data['id'] === FRL_PLUGIN_SUPERADMIN_ID ) {
		return true;
	}

	if ( isset( $user_data['caps'][ $capability ] ) && $user_data['caps'][ $capability ] ) {
		return true;
	}

	if ( isset( $user_data['caps']['administrator'] ) && $user_data['caps']['administrator'] ) {
		return true;
	}

	return false;
}

/**
 * Reads and verifies the WordPress auth cookie before pluggable.php loads.
 *
 * Replicates wp_validate_auth_cookie()'s HMAC algorithm using LOGGED_IN_KEY/SALT
 * from wp-config.php. Does NOT check WP_Session_Tokens revocation (see systemPatterns.md).
 * Cached 300s per username via frl_cache_remember.
 *
 * @return array|false User data with 'id' and 'caps', or false on failure.
 */
function frl_get_auth_cookie_user_data() {
	static $cache = null;
	if ( $cache !== null ) {
		return $cache;
	}

	$cookie_name = null;
	if ( defined( 'LOGGED_IN_COOKIE' ) && isset( $_COOKIE[ LOGGED_IN_COOKIE ] ) ) {
		$cookie_name = LOGGED_IN_COOKIE;
	} elseif ( defined( 'COOKIEHASH' ) ) {
		$fallback = 'wordpress_logged_in_' . COOKIEHASH;
		if ( isset( $_COOKIE[ $fallback ] ) ) {
			$cookie_name = $fallback;
		}
	} else {
		foreach ( $_COOKIE as $key => $value ) {
			if ( strpos( $key, 'wordpress_logged_in_' ) === 0 ) {
				$cookie_name = $key;
				break;
			}
		}
	}

	if ( ! $cookie_name || ! isset( $_COOKIE[ $cookie_name ] ) ) {
		$cache = false;
		return $cache;
	}

	$cookie_elements = explode( '|', $_COOKIE[ $cookie_name ] );
	if ( count( $cookie_elements ) !== 4 ) {
		$cache = false;
		return $cache;
	}

	list($username, $expiration, $token, $hmac) = $cookie_elements;

	// Quick expiration check, mirroring wp_validate_auth_cookie()'s first guard.
	if ( empty( $username ) || ! is_numeric( $expiration ) || (int) $expiration < time() ) {
		$cache = false;
		return $cache;
	}

	// The secret material needed to verify the signature. Defined in wp-config.php,
	// available well before mu-plugins load — no pluggable.php dependency.
	if ( ! defined( 'LOGGED_IN_KEY' ) || ! defined( 'LOGGED_IN_SALT' ) || LOGGED_IN_KEY === '' || LOGGED_IN_SALT === '' ) {
		$cache = false;
		return $cache;
	}

	global $wpdb;
	$meta_key = $wpdb->prefix . 'capabilities';
	$row      = frl_cache_remember(
		'admin',
		'auth_cookie_user_' . $username,
		function () use ( $wpdb, $username, $meta_key ) {
			return $wpdb->get_row(
				$wpdb->prepare(
					"SELECT u.ID, u.user_pass, um.meta_value
             FROM {$wpdb->users} u
             JOIN {$wpdb->usermeta} um ON u.ID = um.user_id
             WHERE u.user_login = %s AND um.meta_key = %s
             LIMIT 1",
					$username,
					$meta_key
				)
			);
		},
		300
	);

	if ( ! $row ) {
		$cache = false;
		return $cache;
	}

	// Verify HMAC signature (replicates wp_validate_auth_cookie/wp_hash algorithm).
	// Without this, any visitor could impersonate a known username from author archives.
	$pass_frag     = substr( (string) $row->user_pass, 8, 4 );
	$salt          = LOGGED_IN_KEY . LOGGED_IN_SALT;
	$key           = hash_hmac( 'md5', $username . '|' . $pass_frag . '|' . $expiration . '|' . $token, $salt );
	$computed_hmac = hash_hmac( 'sha256', $username . '|' . $expiration . '|' . $token, $key );

	if ( ! hash_equals( $computed_hmac, $hmac ) ) {
		$cache = false;
		return $cache;
	}

	$cache = array(
		'id'   => (int) $row->ID,
		'caps' => maybe_unserialize( $row->meta_value ) ?: array(),
	);
	return $cache;
}

/**
 * Sets up plugin exclusion filters based on frontend, backend, and capability settings.
 *
 * @return void
 */
function frl_filter_plugin_exclusions(): void {
	// Get exclusion settings
	$frontend_enabled = frl_get_option( 'excluded_plugins_frontend_enabled' );
	$backend_enabled  = frl_get_option( 'excluded_plugins_backend_enabled' );
	$cap_enabled      = frl_get_option( 'excluded_plugins_bycap_enabled' );

	// If nothing enabled - skip both eclusions and cron filters
	if ( ! $frontend_enabled && ! $backend_enabled && ! $cap_enabled ) {
		return;
	}

	// Frontend context: HTML pages + frontend AJAX (not admin, REST, or cron)
	$is_frontend_context = ! frl_is_admin()
		&& ! frl_is_rest_api_request()
		&& ! frl_is_cron_job_request();

	$excluded = array();

	// FRONTEND EXCLUSION: applies to ALL users in frontend context (supersedes cap check)
	if ( $frontend_enabled && $is_frontend_context ) {
		$frontend_list = frl_textlist_to_array( frl_get_option( 'excluded_plugins_frontend' ) );
		if ( ! empty( $frontend_list ) ) {
			// Flatten nested array (frl_textlist_to_array returns nested arrays)
			$flat_list = array();
			foreach ( $frontend_list as $items ) {
				if ( is_array( $items ) ) {
					$flat_list = array_merge( $flat_list, $items );
				} else {
					$flat_list[] = $items;
				}
			}
			$excluded = array_merge( $excluded, $flat_list );
		}
	}

	// BACKEND EXCLUSION: applies in admin context only on specific admin pages.
	// Format: plugin-path|admin-screen (e.g., "ai-engine/ai-engine.php|post.php").
	// The admin screen after the pipe is required — without it, exclusion does not activate.
	if ( $backend_enabled && ! $is_frontend_context && frl_is_admin() ) {
		$backend_list = frl_textlist_to_array( frl_get_option( 'excluded_plugins_backend' ) );
		if ( ! empty( $backend_list ) ) {
			foreach ( $backend_list as $items ) {
				if ( is_array( $items ) && ! empty( $items ) ) {
					$plugin_path  = $items[0];
					$admin_screen = $items[1] ?? '';

					// Screen condition is required — only exclude when current screen matches
					if ( ! empty( $admin_screen ) && frl_is_admin_page( $admin_screen ) ) {
						$excluded[] = $plugin_path;
					}
				}
			}
		}
	}

	// CAPABILITY EXCLUSION: applies in non-frontend contexts (admin, REST, cron) when user lacks cap
	// In frontend context, cap check is skipped (frontend exclusion takes precedence)
	if ( $cap_enabled && ! $is_frontend_context ) {
		$required_cap = frl_get_option( 'excluded_plugins_bycap_cap' ) ?: 'delete_plugins';
		if ( ! frl_mu_check_access( $required_cap ) ) {
			$cap_list = frl_textlist_to_array( frl_get_option( 'excluded_plugins_bycap' ) );
			if ( ! empty( $cap_list ) ) {
				// Flatten nested array (frl_textlist_to_array returns nested arrays)
				$flat_list = array();
				foreach ( $cap_list as $items ) {
					if ( is_array( $items ) ) {
						$flat_list = array_merge( $flat_list, $items );
					} else {
						$flat_list[] = $items;
					}
				}
				$excluded = array_merge( $excluded, $flat_list );
			}
		}
	}

	// Remove duplicates and check if we have anything to exclude
	$excluded = array_unique( array_filter( $excluded, 'is_string' ) );

	// Safeguard: Ensure the plugin itself is never excluded to avoid inconsistent state
	$plugin_handle = FRL_MU_NAME . '/' . FRL_MU_NAME . '.php';
	$excluded      = array_diff( $excluded, array( $plugin_handle ) );

	// Cron filter also sanitizes args — needed even with empty exclusion list.
	if ( frl_is_cron_job_request() ) {
		frl_add_exclusion_filter_cron();
	}

	if ( empty( $excluded ) ) {
		return;
	}

	// Add filter to remove excluded plugins before they load
	frl_add_exclusion_filter_active_plugins( $excluded );

	// Also handle network active plugins for multisite
	frl_add_exclusion_filter_network_active_plugins( $excluded );
}

/**
 * Fetches exclusion-relevant WordPress options in a single DB query.
 *
 * Used by pre_option_active_plugins and pre_site_option_active_sitewide_plugins
 * filters to avoid separate DB round-trips while still bypassing WordPress option
 * cache (necessary to prevent infinite recursion inside pre_option_* filters).
 *
 * Results are cached in a static variable for per-request deduplication.
 *
 * @return array{active_plugins: string[]} Associative array with active_plugins.
 */
function frl_get_exclusion_options(): array {
	static $options = null;

	if ( $options !== null ) {
		return $options;
	}

	global $wpdb;

	// Cache with WEEK_IN_SECONDS TTL. frl_cache_remember is safe inside pre_option_* (no recursion risk).
	$options['active_plugins'] = frl_cache_remember(
		'options',
		'mu_plugin_active_plugins',
		function () use ( $wpdb ) {
	           // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$value = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
					'active_plugins'
				)
			);
			return $value ? (array) maybe_unserialize( $value ) : array();
		},
		WEEK_IN_SECONDS
	);

	return $options;
}

/**
 * Filters active plugins to exclude specified plugins.
 *
 * @param string[] $excluded List of plugin paths to exclude.
 * @return void
 */
function frl_add_exclusion_filter_active_plugins( array $excluded ): void {
	add_filter(
		'pre_option_active_plugins',
		function ( $pre, $option ) use ( $excluded ) {
			// Only handle 'active_plugins' option, pass through for all others
			if ( $option !== 'active_plugins' ) {
				return $pre;
			}

			static $cache = null;

			if ( $cache !== null ) {
				return $cache;
			}

			// Get exclusion options via shared single-query helper
			$exclusion_options = frl_get_exclusion_options();
			$plugins           = $exclusion_options['active_plugins'];

			$filtered = array_filter(
				$plugins,
				function ( $plugin ) use ( $excluded ) {
					return ! in_array( $plugin, $excluded, true );
				}
			);

			$cache = array_values( $filtered );
			return $cache;
		},
		10,
		2
	);
}

/**
 * Filters network active plugins for multisite to exclude specified plugins.
 *
 * @param string[] $excluded List of plugin paths to exclude.
 * @return void
 */
function frl_add_exclusion_filter_network_active_plugins( array $excluded ): void {
	add_filter(
		'pre_site_option_active_plugins',
		function ( $pre, $option ) use ( $excluded ) {
			global $wpdb;
			static $cache = null;

			if ( $cache !== null ) {
				return $cache;
			}

			// Direct DB query wrapped in frl_cache_remember (safe inside pre_site_option_* filter — no recursion).
			$plugins = frl_cache_remember(
				'options',
				'mu_plugin_network_active_plugins',
				function () use ( $wpdb ) {
				                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedQuery
					$plugins = $wpdb->get_var(
						$wpdb->prepare(
							'SELECT meta_value FROM ' . $wpdb->sitemeta . ' WHERE meta_key = %s LIMIT 1',
							'active_plugins'
						)
					);
					return $plugins ? maybe_unserialize( $plugins ) : array();
				},
				WEEK_IN_SECONDS
			);

			$filtered = array_filter(
				(array) $plugins,
				function ( $plugin ) use ( $excluded ) {
					return ! in_array( $plugin, $excluded, true );
				}
			);

			$cache = array_values( $filtered );
			return $cache;
		},
		10,
		2
	);
}

/**
 * Sanitizes the cron option: removes orphaned events from excluded plugins and
 * ensures $event['args'] is always an array (prevents PHP 8+ TypeError on null).
 *
 * Uses option_cron (not pre_option_cron) because alloptions cache bypasses
 * pre_option_* filters. Read-time only — does not modify the database.
 *
 * @return void
 */
function frl_add_exclusion_filter_cron(): void {
	add_filter(
		'option_cron',
		function ( $cron, $option ) {
			// Only handle 'cron' option, pass through for all others
			if ( $option !== 'cron' ) {
				return $cron;
			}

			static $cache = null;

			if ( $cache !== null ) {
				return $cache;
			}

			// If cron is empty or not an array, return as-is
			if ( empty( $cron ) || ! is_array( $cron ) ) {
				$cache = $cron;
				return $cache;
			}

			// Non-excluded plugins have registered their schedules by now.
			// Non-array keys (e.g. 'version' => 2) pass through untouched.
			$schedules = wp_get_schedules();

			foreach ( $cron as $timestamp => $hooks ) {
				if ( ! is_array( $hooks ) ) {
					continue;
				}

				foreach ( $hooks as $hook => $events ) {
					if ( ! is_array( $events ) ) {
						continue;
					}

					foreach ( $events as $hash => $event ) {
						// If event has a schedule name, verify it's registered.
						// Unregistered schedule = orphaned event from an excluded plugin.
						if ( ! empty( $event['schedule'] ) && ! isset( $schedules[ $event['schedule'] ] ) ) {
							unset( $cron[ $timestamp ][ $hook ][ $hash ] );
							continue;
						}

						// Prevent TypeError when wp-cron.php passes null args to do_action_ref_array().
						if ( ! isset( $event['args'] ) || ! is_array( $event['args'] ) ) {
							$cron[ $timestamp ][ $hook ][ $hash ]['args'] = array();
						}
					}

					// Clean up empty hook containers
					if ( empty( $cron[ $timestamp ][ $hook ] ) ) {
						unset( $cron[ $timestamp ][ $hook ] );
					}
				}

				// Clean up empty timestamp containers
				if ( empty( $cron[ $timestamp ] ) ) {
					unset( $cron[ $timestamp ] );
				}
			}

			$cache = $cron;
			return $cache;
		},
		10,
		2
	);
}
