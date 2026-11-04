<?php
/**
 * Plugin Name: Stall diagnostics (temporary)
 * Description: Logs full object-cache flushes and slow requests (where the time went: load phases, longest DB gaps, DB errors, slow outbound HTTP, cron hooks) to the PHP error log. Remove once the cause is found.
 *
 * Production impact:
 * - Log lines are written only when: a request exceeds the threshold, a full cache flush happens,
 *   or ACPT falls back to its Files cache driver (rate-limited to one line per 5 minutes per
 *   container; slow requests always report the ACPT driver in their summary line).
 * - Per DB query: one microtime() call and a few comparisons (no SAVEQUERIES, no query copies kept beyond the top 5).
 * - Per request at shutdown: one static getter call + class-name check for the ACPT driver (~1 µs).
 *   Only on the fallback path: one filemtime() on a flag file in the system temp dir.
 * - No DB writes, no option/transient reads, no extra HTTP requests.
 * - URLs are logged with their query string; values of credential-like parameters
 *   (pass, pwd, token, secret, key, auth, code, signature, nonce) are masked as ***.
 *
 * Optional constants (wp-config.php):
 *   define( 'FRL_DIAG_DISABLE', true );          // turn everything off without deleting the file
 *   define( 'FRL_DIAG_SLOW_THRESHOLD', 10 );     // seconds, default 10
 *
 * Output: lines prefixed [cache-flush], [slow-req] and [acpt-files] in MyKinsta > Logs > error.log.
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'FRL_DIAG_DISABLE' ) && FRL_DIAG_DISABLE ) {
	return;
}

if ( ! defined( 'FRL_DIAG_SLOW_THRESHOLD' ) ) {
	define( 'FRL_DIAG_SLOW_THRESHOLD', 10 );
}

final class FRL_Diag {

	const TOP_GAPS   = 5;    // longest DB gaps kept.
	const MIN_GAP    = 0.5;  // seconds; shorter gaps are ignored.
	const MIN_HTTP   = 0.5;  // seconds; faster outbound requests are ignored.
	const MAX_ITEMS  = 20;   // cap for HTTP / cron / error lists.
	const LINE_LIMIT = 900;  // PHP-FPM truncates log lines at 1024 bytes by default.

	/** @var string */
	private static $id;
	/** @var float */
	private static $start;
	private static $marks       = array();
	private static $query_count = 0;
	private static $last_q_time = 0.0;
	private static $last_q_sql  = '';
	private static $gaps        = array();
	private static $db_errors   = array();
	private static $http_start  = array();
	private static $http        = array();
	private static $cron        = array();

	/* ------------------------------------------------------------------ */
	/* Setup                                                               */
	/* ------------------------------------------------------------------ */

	public static function init() {
		self::$id    = substr( md5( uniqid( '', true ) ), 0, 8 );
		self::$start = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime( true );

		// Full object-cache flushes (Redis Object Cache drop-in), including WP-CLI.
		add_action( 'redis_object_cache_flush', array( __CLASS__, 'on_flush' ), 10, 5 );

		// Slow-request monitor: web requests only (long WP-CLI jobs are expected).
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}

		self::mark( 'mu' );
		foreach ( array( 'plugins_loaded', 'init', 'wp_loaded', 'admin_init', 'template_redirect', 'wp_footer' ) as $hook ) {
			add_action(
				$hook,
				static function () use ( $hook ) {
					FRL_Diag::mark( $hook );
				},
				PHP_INT_MIN
			);
		}

		add_filter( 'query', array( __CLASS__, 'on_query' ), PHP_INT_MAX );
		add_filter( 'pre_http_request', array( __CLASS__, 'on_http_start' ), PHP_INT_MAX, 3 );
		add_action( 'http_api_debug', array( __CLASS__, 'on_http_end' ), PHP_INT_MAX, 5 );

		if ( wp_doing_cron() ) {
			// wp-cron.php unschedules every due event right before running its hook.
			add_filter( 'pre_unschedule_event', array( __CLASS__, 'on_cron_event' ), PHP_INT_MAX, 3 );
		}

		add_action( 'shutdown', array( __CLASS__, 'on_shutdown' ), PHP_INT_MAX );
	}

	/** @param string $name */
	public static function mark( $name ) {
		if ( ! isset( self::$marks[ $name ] ) ) {
			self::$marks[ $name ] = microtime( true ) - self::$start;
		}
	}

	/* ------------------------------------------------------------------ */
	/* Collectors (hot path: keep cheap)                                   */
	/* ------------------------------------------------------------------ */

	/** @param string $sql */
	public static function on_query( $sql ) {
		$now = microtime( true );

		if ( self::$last_q_time > 0 ) {
			$gap = $now - self::$last_q_time;
			if ( $gap >= self::MIN_GAP ) {
				self::add_gap( $gap, self::$last_q_sql, 'next query' );
			}

			// wpdb only clears last_error after this filter, so it still holds the previous query's error.
			global $wpdb;
			if ( isset( $wpdb->last_error ) && '' !== $wpdb->last_error && count( self::$db_errors ) < self::MAX_ITEMS ) {
				self::$db_errors[] = $wpdb->last_error . ' <- ' . self::short( self::$last_q_sql, 200 );
			}
		}

		self::$last_q_time = $now;
		self::$last_q_sql  = $sql;
		++self::$query_count;

		return $sql;
	}

	/**
	 * @param float  $seconds
	 * @param string $sql
	 * @param string $until
	 */
	private static function add_gap( $seconds, $sql, $until ) {
		self::$gaps[] = array( $seconds, self::short( $sql, 300 ), $until );
		if ( count( self::$gaps ) > self::TOP_GAPS ) {
			usort(
				self::$gaps,
				static function ( $a, $b ) {
					return $b[0] <=> $a[0];
				}
			);
			array_pop( self::$gaps );
		}
	}

	/**
	 * @param mixed  $pre
	 * @param array  $args
	 * @param string $url
	 */
	public static function on_http_start( $pre, $args, $url ) {
		self::$http_start[ $url ] = microtime( true );
		return $pre;
	}

	/**
	 * @param mixed  $response
	 * @param string $context
	 * @param string $class
	 * @param array  $args
	 * @param string $url
	 */
	public static function on_http_end( $response, $context, $class, $args, $url ) {
		if ( ! isset( self::$http_start[ $url ] ) ) {
			return;
		}
		$took = microtime( true ) - self::$http_start[ $url ];
		unset( self::$http_start[ $url ] );

		if ( $took < self::MIN_HTTP || count( self::$http ) >= self::MAX_ITEMS ) {
			return;
		}

		$status = is_wp_error( $response ) ? 'ERR ' . $response->get_error_code() : (string) wp_remote_retrieve_response_code( $response );

		self::$http[] = sprintf( '%.2fs %s %s', $took, self::redact( preg_replace( '#^https?://#i', '', $url ) ), $status );
	}

	/**
	 * @param mixed  $pre
	 * @param int    $timestamp
	 * @param string $hook
	 */
	public static function on_cron_event( $pre, $timestamp, $hook ) {
		if ( count( self::$cron ) < self::MAX_ITEMS ) {
			self::$cron[] = sprintf( '%s@%.1fs', $hook, microtime( true ) - self::$start );
		}
		return $pre;
	}

	/* ------------------------------------------------------------------ */
	/* Reporters                                                           */
	/* ------------------------------------------------------------------ */

	public static function on_shutdown() {
		$total = microtime( true ) - self::$start;
		$acpt  = self::acpt_driver();
		$slow  = $total >= (float) FRL_DIAG_SLOW_THRESHOLD;

		if ( 'files' === $acpt ) {
			self::log_acpt_files( $total, $slow );
		}

		if ( ! $slow ) {
			return;
		}

		// Time from the last query to shutdown (catches a final blocking query or PHP work).
		if ( self::$last_q_time > 0 ) {
			$tail = microtime( true ) - self::$last_q_time;
			if ( $tail >= self::MIN_GAP ) {
				self::add_gap( $tail, self::$last_q_sql, 'shutdown' );
			}
		}

		usort(
			self::$gaps,
			static function ( $a, $b ) {
				return $b[0] <=> $a[0];
			}
		);

		$marks = array();
		foreach ( self::$marks as $name => $t ) {
			$marks[] = $name . '=' . number_format( $t, 2 );
		}

		self::log(
			'slow-req',
			sprintf(
				'%.2fs | %s %s | action=%s | user=%d | cron=%d | ajax=%d | rest=%d | queries=%d | peak_mem=%dMB | acpt=%s | phases: %s',
				$total,
				$_SERVER['REQUEST_METHOD'] ?? '-',
				self::request_path(),
				isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '-',
				function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0,
				wp_doing_cron() ? 1 : 0,
				wp_doing_ajax() ? 1 : 0,
				( defined( 'REST_REQUEST' ) && REST_REQUEST ) ? 1 : 0,
				self::$query_count,
				(int) round( memory_get_peak_usage( true ) / 1048576 ),
				'' === $acpt ? '-' : $acpt,
				implode( ' ', $marks )
			)
		);

		foreach ( self::$gaps as $i => $gap ) {
			self::log( 'slow-req', sprintf( 'gap#%d %.2fs (until %s) after: %s', $i + 1, $gap[0], $gap[2], $gap[1] ) );
		}
		if ( self::$db_errors ) {
			self::log( 'slow-req', 'db_errors: ' . implode( ' || ', self::$db_errors ) );
		}
		if ( self::$http ) {
			self::log( 'slow-req', 'http: ' . implode( ' | ', self::$http ) );
		}
		if ( self::$cron ) {
			self::log( 'slow-req', 'cron_hooks: ' . implode( ' ', self::$cron ) );
		}
	}

	/**
	 * @param array  $results
	 * @param int    $delay
	 * @param bool   $selective
	 * @param string $salt
	 * @param float  $execute_time
	 */
	public static function on_flush( $results, $delay, $selective, $salt, $execute_time ) {
		$trace = array();
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) {
			if ( empty( $frame['file'] ) ) {
				continue;
			}
			$fn      = isset( $frame['class'] ) ? $frame['class'] . '::' . $frame['function'] : ( $frame['function'] ?? '' );
			$trace[] = str_replace( ABSPATH, '', $frame['file'] ) . ':' . $frame['line'] . ' ' . $fn;
		}

		self::log(
			'cache-flush',
			sprintf(
				'%s %s | action=%s | user=%d | cron=%d | cli=%d | selective=%s | trace: %s',
				$_SERVER['REQUEST_METHOD'] ?? '-',
				self::request_path(),
				isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '-',
				function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0,
				( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) ? 1 : 0,
				( defined( 'WP_CLI' ) && WP_CLI ) ? 1 : 0,
				var_export( $selective, true ),
				implode( ' <- ', array_slice( $trace, 0, 20 ) )
			)
		);
	}

	/**
	 * ACPT is set to Redis but silently falls back to the Files driver when its
	 * settings query fails or Redis throws. Returns 'files', 'redis', 'other', or ''
	 * (ACPT not loaded / cache disabled). ACPT_Plugin::getCache() is a static getter.
	 */
	private static function acpt_driver() {
		if ( ! is_callable( array( '\ACPT\Includes\ACPT_Plugin', 'getCache' ) ) ) {
			return '';
		}
		try {
			$cache = call_user_func( array( '\ACPT\Includes\ACPT_Plugin', 'getCache' ) );
		} catch ( \Throwable $e ) {
			return '';
		}
		if ( ! is_object( $cache ) ) {
			return '';
		}
		$class = get_class( $cache );
		if ( false !== stripos( $class, 'Files' ) ) {
			return 'files';
		}
		return ( false !== stripos( $class, 'Redis' ) ) ? 'redis' : 'other';
	}

	/**
	 * Logs a Files-driver fallback. Slow requests always log it (they are rare by
	 * definition); otherwise at most one line per 5 minutes per container, so a
	 * persistent fallback cannot flood the log.
	 *
	 * @param float $total
	 * @param bool  $slow
	 */
	private static function log_acpt_files( $total, $slow ) {
		if ( ! $slow ) {
			$flag = rtrim( sys_get_temp_dir(), '/\\' ) . '/frl-diag-acpt-files-' . md5( ABSPATH );
			$last = @filemtime( $flag ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( $last && ( time() - $last ) < 300 ) {
				return;
			}
			@touch( $flag ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		global $wpdb;
		self::log(
			'acpt-files',
			sprintf(
				'ACPT using Files cache driver | %s %s | %.2fs | last_db_error=%s%s',
				$_SERVER['REQUEST_METHOD'] ?? '-',
				self::request_path(),
				$total,
				isset( $wpdb->last_error ) && '' !== $wpdb->last_error ? $wpdb->last_error : '-',
				$slow ? '' : ' | further non-slow occurrences suppressed for 5 min'
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	private static function request_path() {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return ( defined( 'WP_CLI' ) && WP_CLI ) ? 'wp-cli' : '-';
		}
		return self::redact( (string) wp_unslash( $_SERVER['REQUEST_URI'] ) );
	}

	/**
	 * Keeps the full query string (page=, action=, post= ... are useful for admin
	 * operations) but masks the values of credential-like parameters, e.g. OAuth
	 * tokens in outbound requests or passwords in login URLs. Only runs when a
	 * line is actually logged.
	 *
	 * @param string $url
	 * @return string
	 */
	private static function redact( $url ) {
		if ( false === strpos( $url, '?' ) ) {
			return $url;
		}
		return preg_replace_callback(
			'/([?&])([^=&#]+)=([^&#]*)/',
			static function ( $m ) {
				$sensitive = preg_match( '/(?:^|_)(?:pass|password|pwd|token|secret|key|apikey|auth|code|signature|sig|nonce|wpnonce)(?:$|_)|token|secret|password|nonce/i', $m[2] );
				return $m[1] . $m[2] . '=' . ( $sensitive ? '***' : $m[3] );
			},
			$url
		);
	}

	/**
	 * @param string $sql
	 * @param int    $len
	 * @return string
	 */
	private static function short( $sql, $len ) {
		$sql = preg_replace( '/\s+/', ' ', trim( (string) $sql ) );
		return strlen( $sql ) > $len ? substr( $sql, 0, $len ) . '…' : $sql;
	}

	/**
	 * @param string $tag
	 * @param string $message
	 */
	private static function log( $tag, $message ) {
		$prefix = '[' . $tag . '] ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC #' . self::$id . ' ';
		$room   = self::LINE_LIMIT - strlen( $prefix );
		foreach ( str_split( $message, max( 100, $room ) ) as $i => $chunk ) {
			error_log( $prefix . ( $i ? '(cont) ' : '' ) . $chunk ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}

FRL_Diag::init();
