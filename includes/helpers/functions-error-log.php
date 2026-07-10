<?php

/**
 * Fralenuvole Error Log functions
 *
 * This file contains the core helper functions for error log management.
 *
 * @package Fralenuvole
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Log messages with automatic formatting and optional email notification
 *
 * Supports three usage patterns:
 * 1. Variable dump: frl_log($my_array);
 * 2. Message with placeholders: frl_log('User {id} created', ['id' => 123]);
 * 3. Message with full context dump: frl_log('Debug info', ['key' => 'value', 'data' => $obj]);
 *
 * @example frl_log($my_array); // Dumps any variable for inspection
 * @example frl_log('User {id} created', ['id' => 123]); // Interpolates {id} placeholder
 * @example frl_log('Debug data', ['foo' => 1, 'bar' => 2]); // Appends full context array
 * @example frl_log('Critical error', [], true); // Logs and emails admin
 * @example frl_log('Silent log', [], false); // Logs without email
 *
 * @param string|array $message Message string or variable to dump
 * @param array $context Additional context data (supports placeholder interpolation or full dump)
 * @param bool $force_email Whether to email admin (requires plugin option enabled, default true)
 */
function frl_log( $message, $context = array(), $force_email = true ) {
	// Suppress logging during Log manager requests
	if ( frl_is_log_manager_request() ) {
		return; // Early exit
	}
	$is_admin_debug = ! is_scalar( $message ) && empty( $context ) && frl_has_access();

	$debug_send_email = ! $is_admin_debug && $force_email && frl_get_option( 'error_reporting_email' );

	// Throttle formatting via cache.
	$message_hash   = md5( serialize( array( $message, $context ) ) );
	$context_suffix = frl_is_admin() ? 'admin' : 'frontend';
	$cache_key      = 'logs_' . $context_suffix . '_' . $message_hash;

	$formatted_message = frl_cache_remember(
		'admin',
		$cache_key,
		function () use ( $message, $context ) {
			return frl_format_log_message( $message, $context );
		}
	);

	// Email throttling: independent of formatting cache to avoid blocking
	// cache population on slow SMTP connections. Uses a per-request static
	// hash set to prevent duplicate emails within the same request.
	if ( $debug_send_email ) {
		static $emailed_hashes = array();
		if ( ! isset( $emailed_hashes[ $message_hash ] ) ) {
			$emailed_hashes[ $message_hash ] = true;
			frl_email_error_notification( $formatted_message );
		}
	}

	// Always log to preserve error frequency information
	error_log( $formatted_message );
}

/**
 * Create WP_Error with automatic logging for actual problems
 *
 * @param string $code Error code for WP_Error
 * @param string $message Error message (used for both log and WP_Error)
 * @param array $context Additional context for logging
 * @param mixed $error_data Optional data for WP_Error
 * @return WP_Error
 */
function frl_error( $code, $message, $context = array(), $error_data = '' ) {
	// Only skip logging for expected conditions (not actual problems)
	$expected_conditions = array( 'no_changes' );

	$is_problem = ! in_array( $code, $expected_conditions, true );

	if ( $is_problem ) {
		frl_log( $message, $context ); // Log + email all actual problems
	}

	return new WP_Error( $code, $message, $error_data );
}

/**
 * Dump variables to screen (plugin admin only)
 * Buffers output and displays at end of body via wp_footer hook
 * @param mixed $variable Data to dump to screen
 */
function frl_dump( $variable ) {
	// Security check: only allow plugin admin access
	if ( ! frl_has_access() ) {
		return;
	}

	// Buffer debug output instead of immediate echo
	static $debug_buffer = array();
	static $hook_added   = false;

	$formatted_message = frl_format_log_message( $variable, array() );
	$dump_message      = str_replace( 'Frl_LOG:', strtoupper( FRL_PREFIX ) . '_DUMP:', $formatted_message );

	// Store in buffer
	$debug_buffer[] = $dump_message;

	// Add footer hook only once
	if ( ! $hook_added && ! frl_is_doing_ajax() ) {
		add_action(
			'wp_footer',
			function () use ( &$debug_buffer ) {
				if ( empty( $debug_buffer ) ) {
					return;
				}

				echo '<div id="frl-debug-panel" style="position: fixed; bottom: 10px; right: 10px; width: 420px; max-height: 420px; overflow-y: auto; z-index: 999999; background: white; border: 2px solid var(--admin-accent-color); border-radius: 6px; box-shadow: 0 4px 12px rgba(0,0,0,0.3);">';
				echo '<div style="background: var(--admin-accent-color); color: white; padding: 8px 12px; font-weight: bold; font-size: 13px; display: flex; justify-content: space-between; align-items: center;">';
				echo '<span>🐛 FRL Debug (' . count( $debug_buffer ) . ' items)</span>';
				echo '<button onclick="document.getElementById(\'frl-debug-content\').style.display = document.getElementById(\'frl-debug-content\').style.display === \'none\' ? \'block\' : \'none\'; this.innerHTML = this.innerHTML === \'−\' ? \'+\' : \'−\';" style="background: none; border: none; color: white; font-size: 16px; cursor: pointer; padding: 0; width: 20px; height: 20px;">−</button>';
				echo '</div>';
				echo '<div id="frl-debug-content" style="max-height: 320px; overflow-y: auto;">';

				foreach ( $debug_buffer as $index => $message ) {
					echo '<div style="background: #f9f9f9; border-bottom: 1px solid #ddd; padding: 12px; font-family: \'Courier New\', monospace; font-size: 13px; line-height: 1.5; color: #333; white-space: pre-wrap;">' . esc_html( $message ) . '</div>';
				}

				echo '</div>';
				echo '<div style="text-align: center; padding: 8px; background: #f1f1f1; border-top: 1px solid #ddd; font-size: 11px; color: #666;">Debug panel (plugin admin only)</div>';
				echo '</div>';
			},
			999
		);
		$hook_added = true;
	}
}

/**
 * Format log messages with context and caller information
 *
 * @param string|array $message Message or data to format
 * @param array $context Additional context data
 * @return string Formatted log message
 */
function frl_format_log_message( $message, $context = array() ) {
	$log_message = strtoupper( FRL_PREFIX ) . '_LOG: ';

	// --- Determine Context ---
	$caller_info = '';
	// Get a limited backtrace for performance, but deep enough for context.
	$trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 6 );

	// List of internal utility functions to skip over to find the real caller.
	$internal_helpers = array(
		'frl_log',
		'frl_dump',
		'frl_format_log_message',
		'frl_json_decode',
		'frl_json_decode_string',
		'frl_json_decode_file',
		'frl_normalize_to_array',
		'frl_cache_remember',
		'{closure}',            // skip anonymous closures
		'call_user_func_array', // skip wrapper used by frl_cache_remember
		'call_user_func',       // skip wrapper used by frl_cache_remember
	);

	// Find the first call in the stack that is NOT one of our internal helpers.
	foreach ( $trace as $trace_item ) {
		if ( ! isset( $trace_item['function'] ) ) {
			continue;
		}
		$function_name = $trace_item['function'];
		$class_name    = $trace_item['class'] ?? null;

		// Skip internal helpers and cache wrappers
		$skip = in_array( $function_name, $internal_helpers, true );

		// Also skip cache manager remember layer to surface the real caller
		if ( ! $skip && $function_name === 'remember' && $class_name !== null ) {
			if ( str_contains( $class_name, 'Cache_Manager' ) ) {
				$skip = true;
			}
		}

		// Skip any frame belonging to the cache manager class (static helpers)
		if ( ! $skip && $class_name === 'Frl_Cache_Manager' ) {
			$skip = true;
		}

		// Skip the wrapper call site when it shows up as ::remember with wrapper file
		if ( ! $skip && $function_name === 'remember' ) {
			$file_path = $trace_item['file'] ?? '';
			if ( $file_path && basename( $file_path ) === 'functions-class-helpers.php' ) {
				$skip = true;
			}
		}

		if ( ! $skip ) {
			$caller_name = $class_name ? "{$class_name}::{$function_name}" : $function_name;
			$file        = isset( $trace_item['file'] ) ? basename( $trace_item['file'] ) : 'unknown file';
			$line        = isset( $trace_item['line'] ) ? (int) $trace_item['line'] : 0;
			$caller_info = "\n  ↳ Called from: {$caller_name} in {$file}:{$line}";
			break; // Found the source, stop searching.
		}
	}

	$processed_message = $message;

	// Smart interpolation: If the message is a string with {placeholders}, replace them.
	if ( is_string( $processed_message ) && str_contains( $processed_message, '{' ) ) {
		$replacements = array();
		foreach ( $context as $key => $val ) {
			if ( str_contains( $processed_message, '{' . $key . '}' ) ) {
				// Ensure value is suitable for string replacement
				if ( is_scalar( $val ) || ( is_object( $val ) && method_exists( $val, '__toString' ) ) ) {
					$replacements[ '{' . $key . '}' ] = $val;
				} elseif ( is_array( $val ) || is_object( $val ) ) {
					// For arrays/objects, replace with a compact JSON string
					$replacements[ '{' . $key . '}' ] = json_encode( $val, JSON_PARTIAL_OUTPUT_ON_ERROR );
				}
			}
		}

		if ( ! empty( $replacements ) ) {
			$processed_message = strtr( $processed_message, $replacements );
		}
	}

	// Handle message based on type
	if ( is_object( $processed_message ) ) {
		// Default: dump object data with nice formatting
		$class_name   = get_class( $processed_message );
		$log_message .= "Object ({$class_name}):\n";

		try {
			// First attempt: direct JSON encode (works for stdClass/JsonSerializable)
			$json = json_encode( $processed_message, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_PRETTY_PRINT );

			// Fallback: encode public properties
			if ( $json === false || $json === '{}' || $json === 'null' ) {
				$props = get_object_vars( $processed_message );
				$json  = json_encode( $props, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_PRETTY_PRINT );
				if ( $json === false ) {
					// Last resort: readable print_r
					$json = print_r( $props, true );
				}
			}

			$log_message .= $json;
		} catch ( Throwable $e ) {
			$log_message .= '<unserializable object: ' . $e->getMessage() . '>';
		}
	} elseif ( is_array( $processed_message ) ) {
		// Handle arrays with better formatting and error handling
		$json = json_encode( $processed_message, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_PRETTY_PRINT );
		if ( $json === false ) {
			$log_message .= 'Array (failed to encode: ' . json_last_error_msg() . ')';
		} else {
			$log_message .= "Array:\n" . $json;
		}
	} else {
		// Simple types
		$log_message .= is_null( $processed_message ) ? 'NULL' : ( is_bool( $processed_message ) ? ( $processed_message ? 'TRUE' : 'FALSE' ) : ( is_string( $processed_message ) && $processed_message === '' ? '<empty string>' : (string) $processed_message ) );
	}

	// Append request URL and caller info as the last lines
	$log_message  = frl_log_add_details( $log_message, '  ' );
	$log_message .= $caller_info;

	return $log_message;
}

/**
 * Append common diagnostic details to a log line (URL, etc.)
 *
 * @param string $message Base log message
 * @param string $indent Optional indentation before details markers (e.g., two spaces for UI alignment)
 * @return string Message with details appended
 */
function frl_log_add_details( string $message, string $indent = '' ): string {
	// URL
	$url = frl_get_request_url();
	if ( $url !== '' ) {
		$message .= "\n" . $indent . '↳ URL: ' . $url;
	}

	$current_query_line = '';
	// Current (non-main) query vars when inside a block render
	if ( ! empty( $GLOBALS['frl_block_stack'] ) && ! empty( $GLOBALS['frl_current_query_vars'] ) ) {
		$qv          = $GLOBALS['frl_current_query_vars'];
		$subset_keys = array( 'posts_per_page', 'post_type', 'post__in', 'orderby', 'order', 'tax_query' );
		$subset      = array_intersect_key( $qv, array_flip( $subset_keys ) );
		if ( ! empty( $subset ) ) {
			$json = json_encode( $subset, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( $json ) {
				$current_query_line = "\n" . $indent . '↳ CurrentQueryVars: ' . $json;
			}
		}
	}

	// Current block from stack
	if ( ! empty( $GLOBALS['frl_block_stack'] ) ) {
		$blk = end( $GLOBALS['frl_block_stack'] );
		if ( is_array( $blk ) ) {
			$blk_line = $blk['name'] ?? '';
			if ( ! empty( $blk['attrs'] ) ) {
				$pairs = array();
				foreach ( $blk['attrs'] as $k => $v ) {
					if ( is_scalar( $v ) && $v !== '' ) {
						$pairs[] = $k . '=' . (string) $v;
					}
				}
				if ( ! empty( $pairs ) ) {
					$blk_line .= ' ' . implode( ' ', $pairs );
				}
			}
			if ( $blk_line !== '' ) {
				$message .= "\n" . $indent . '↳ CurrentBlock: ' . $blk_line;
			}

			// Collect the full chain of ancestor patterns for a clear hierarchy
			$pattern_chain = array();
			if ( ! empty( $GLOBALS['frl_block_stack'] ) ) {
				foreach ( $GLOBALS['frl_block_stack'] as $ancestor_block ) {
					if ( ! empty( $ancestor_block['pattern'] ) ) {
						$pattern_chain[] = (string) $ancestor_block['pattern'];
					}
				}
			}
			if ( ! empty( $pattern_chain ) ) {
				$message .= "\n" . $indent . '↳ Pattern: ' . implode( ' > ', $pattern_chain );
			}
		}
	}

	// Hook name
	if ( function_exists( 'current_filter' ) ) {
		$hook = current_filter();
		if ( $hook ) {
			$message .= "\n" . $indent . '↳ Hook: ' . $hook;
		}
	}

	// Last executed shortcode
	if ( ! empty( $GLOBALS['frl_last_shortcode'] ) && is_array( $GLOBALS['frl_last_shortcode'] ) ) {
		$sc  = $GLOBALS['frl_last_shortcode'];
		$tag = $sc['tag'] ?? '';
		if ( $tag ) {
			$attrs_line = '';
			if ( ! empty( $sc['attrs'] ) ) {
				$pairs = array();
				foreach ( $sc['attrs'] as $k => $v ) {
					$pairs[] = sprintf( '%s="%s"', $k, esc_attr( $v ) );
				}
				$attrs_line = ' ' . implode( ' ', $pairs );
			}
			$message .= "\n" . $indent . '↳ Shortcode: [' . $tag . $attrs_line . ']';
		}
	}

	// Append CurrentQueryVars at the very end
	if ( $current_query_line !== '' ) {
		$message .= $current_query_line;
	}

	return $message;
}

/**
 * Send an error notification email to the administrator.
 *
 * Includes rate limiting to prevent email flooding.
 *
 * @param string $formatted_message The formatted error message to send.
 * @return bool True if the email was sent, false otherwise.
 */
function frl_email_error_notification( $formatted_message ) {
	// Rate limiting check (preserves original rate limiting)
	$email_to      = FRL_EMAIL_NOTIFICATIONS['to'];
	$rate_key      = FRL_EMAIL_NOTIFICATIONS['rate_key'];
	$rate_limit    = FRL_EMAIL_NOTIFICATIONS['rate_limit'];
	$rate_interval = FRL_EMAIL_NOTIFICATIONS['rate_interval'];

	$current_count = frl_get_transient( $rate_key ) ?: 0;
	if ( $current_count >= $rate_limit ) {
		if ( $current_count === $rate_limit ) {
			error_log(
				sprintf(
					'FRL_LOGS: Rate limit of %s emails per minute exceeded',
					$rate_limit
				)
			);
		}
		return false; // Rate limit exceeded
	}

	// Prepare email content
	$site_name = get_bloginfo( 'name' );
	$site_url  = home_url();
	$timestamp = current_time( 'Y-m-d H:i:s' );

	$subject = sprintf(
		'%s notification on %s',
		strtoupper( FRL_PREFIX ) . '_LOG: ',
		$site_url
	);

	$body = sprintf(
		"Plugin log notification on %s\nSite: %s\nTime: %s\n\n%s",
		$site_name,
		$site_url,
		$timestamp,
		$formatted_message
	);

	$header = 'From: ' . frl_name() . ' Plugin';

	// Send email
	$sent = frl_email( $email_to, $subject, $body, $header );

	if ( $sent ) {
		// Update rate limit counter
		frl_set_transient( $rate_key, $current_count + 1, $rate_interval );
	}

	return $sent;
}

/**
 * Send an email using wp_mail if available, otherwise falling back to PHP mail().
 *
 * @param string $email_to The recipient email address.
 * @param string $subject The email subject.
 * @param string $body The email body.
 * @param string $header The email header.
 * @return bool True if the email was sent successfully, false otherwise.
 */
function frl_email( $email_to, $subject, $body, $header ) {
	if ( function_exists( 'wp_mail' ) ) {
		// Use WordPress mail
		$headers = array( $header . ' <' . $email_to . '>' );
		return wp_mail( $email_to, $subject, $body, $headers );
	} else {
		// Use PHP mail directly
		$headers  = $header . " <{$email_to}>\r\n";
		$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
		$body    .= "\n\nEmail sent by mail()";
		return mail( $email_to, $subject, $body, $headers );
	}
}

function frl_log_capture_render_block_enter( $block ) {
	if ( ! is_array( $block ) ) {
		return $block;
	}

	$name  = $block['blockName'] ?? '';
	$attrs = $block['attrs'] ?? array();

	// Use array_flip for O(m) iteration instead of O(n) - iterate actual attributes instead of whitelist
	static $whitelist_flip = null;
	if ( $whitelist_flip === null ) {
		$whitelist_flip = array_flip( array( 'id', 'className', 'tag', 'type', 'slug', 'area', 'theme', 'ref', 'anchor', 'uniqueId', 'blockId', 'clientId', 'queryId', 'perPage', 'order', 'orderby', 'taxonomy', 'terms', 'repeaterField', 'postId', 'sourceType', 'dynamicField' ) );
	}

	$filtered = array();
	foreach ( $attrs as $key => $val ) {
		if ( isset( $whitelist_flip[ $key ] ) ) {
			if ( is_scalar( $val ) ) {
				$filtered[ $key ] = (string) $val;
			} elseif ( is_array( $val ) ) {
				// Safely stringify nested arrays/objects for logging without PHP warnings
				$converted        = array_map(
					function ( $item ) {
						if ( is_scalar( $item ) || ( is_object( $item ) && method_exists( $item, '__toString' ) ) ) {
							return (string) $item;
						}
						if ( is_array( $item ) ) {
							$json = json_encode( $item, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR );
							return ( $json !== false ) ? $json : '[array]';
						}
						return '[' . gettype( $item ) . ']';
					},
					$val
				);
				$filtered[ $key ] = implode( ',', $converted );
			}
		}
	}

	// Preserve repeaterArray for shortcode context resolution (Greenshift, Jet Engine, etc.)
	$repeater_array = null;
	if ( ! empty( $attrs['repeaterArray'] ) && is_array( $attrs['repeaterArray'] ) ) {
		$repeater_array = $attrs['repeaterArray'];
	}

	if ( ! isset( $GLOBALS['frl_block_stack'] ) ) {
		$GLOBALS['frl_block_stack'] = array();
	}
	$pattern_info = $attrs['metadata']['name'] ?? '';

	$GLOBALS['frl_block_stack'][] = array(
		'name'          => $name,
		'attrs'         => $filtered,
		'pattern'       => (string) $pattern_info,
		'repeaterArray' => $repeater_array,
	);
	return $block;
}

function frl_log_capture_render_block_exit( $block_content, $block ) {
	if ( isset( $GLOBALS['frl_block_stack'] ) && ! empty( $GLOBALS['frl_block_stack'] ) ) {
		array_pop( $GLOBALS['frl_block_stack'] );
	}
	return $block_content;
}

function frl_log_capture_query( $query ) {
	if ( is_object( $query ) && method_exists( $query, 'is_main_query' ) && ! $query->is_main_query() ) {
		if ( ! empty( $GLOBALS['frl_block_stack'] ) && isset( $query->query_vars ) ) {
			$GLOBALS['frl_current_query_vars'] = $query->query_vars;
		}
	}
}

function frl_log_capture_shortcode( $output, $tag, $attr, $m ) {
	$attrs      = is_array( $attr ) ? $attr : array();
	$safe_attrs = array();
	foreach ( $attrs as $key => $value ) {
		if ( is_scalar( $value ) ) {
			$safe_attrs[ $key ] = (string) $value;
		}
	}

	$GLOBALS['frl_last_shortcode'] = array(
		'tag'   => (string) $tag,
		'attrs' => $safe_attrs,
	);
	return $output;
}

/**
 * Determine the error type/category from a debug.log message line.
 *
 * Shared by frl_count_debug_log_entries() and Frl_Log_Manager::determine_error_type()
 * -- always loaded, unlike Frl_Log_Manager which only loads on the plugin's own page.
 *
 * @param string $message Log message (typically the entry's first/timestamp line).
 * @return string One of: FRL_PREFIX, 'fatal', 'parse', 'warning', 'notice', 'deprecated', 'error', or 'info'.
 */
function frl_determine_log_error_type( $message ) {
	// FRL logs take priority.
	if ( str_contains( $message, strtoupper( FRL_PREFIX ) . '_LOG:' ) ) {
		return FRL_PREFIX;
	}

	$message = strtolower( $message );

	if ( str_contains( $message, 'fatal error' ) ) {
		return 'fatal';
	} elseif ( str_contains( $message, 'parse error' ) ) {
		return 'parse';
	} elseif ( str_contains( $message, 'warning' ) ) {
		return 'warning';
	} elseif ( str_contains( $message, 'notice' ) ) {
		return 'notice';
	} elseif ( str_contains( $message, 'deprecated' ) ) {
		return 'deprecated';
	} elseif ( str_contains( $message, 'error' ) ) {
		return 'error';
	}

	return 'info';
}

/**
 * Count non-ignored, non-Info entries in the debug.log file.
 *
 * Shared by frl_get_debug_log_count() (admin bar, byte-capped) and
 * Frl_Log_Manager::get_log_entry_count() (Log Manager, full scan). Counts
 * entries (via the timestamp line pattern), not raw lines, so multi-line
 * stack traces count once.
 *
 * @param int $max_bytes Bytes to read from the end of the file (0 = full scan).
 * @return int Count of non-ignored, non-Info log entries.
 */
function frl_count_debug_log_entries( $max_bytes = 0 ) {
	$count    = 0;
	$log_file = WP_CONTENT_DIR . '/debug.log';

	if ( ! file_exists( $log_file ) ) {
		return 0;
	}

	$handle = @fopen( $log_file, 'r' );
	if ( ! $handle ) {
		return 0;
	}

	if ( $max_bytes > 0 ) {
		$file_size = filesize( $log_file );
		$offset    = ( $file_size > $max_bytes ) ? $file_size - $max_bytes : 0;
		if ( $offset > 0 ) {
			// Seek to near the end of the file, then align to next newline.
			fseek( $handle, $offset );
			fgets( $handle ); // Discard partial first line.
		}
	}

	$ignore_list       = defined( 'FRL_LOG_COUNT_IGNORE' ) && is_array( FRL_LOG_COUNT_IGNORE ) ? FRL_LOG_COUNT_IGNORE : array();
	$timestamp_pattern = '/^\[\d{2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2} UTC\]/';

	// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- Canonical PHP idiom for reading a file line-by-line until EOF; fgets() returning false is the only way to detect end-of-file, there is no assignment-free alternative.
	while ( ( $line = fgets( $handle ) ) !== false ) {
		$trimmed_line = trim( $line );
		if ( empty( $trimmed_line ) ) {
			continue;
		}

		// Continuation lines (stack traces, etc.) belong to the entry above.
		if ( ! preg_match( $timestamp_pattern, $trimmed_line ) ) {
			continue;
		}

		$ignore_line = false;
		foreach ( $ignore_list as $ignore_string ) {
			if ( str_contains( $trimmed_line, $ignore_string ) ) {
				$ignore_line = true;
				break;
			}
		}

		if ( $ignore_line ) {
			continue;
		}

		if ( frl_determine_log_error_type( $trimmed_line ) === 'info' ) {
			continue;
		}

		++$count;
	}
	fclose( $handle );

	return $count;
}
