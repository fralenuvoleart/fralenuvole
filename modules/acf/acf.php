<?php

/**
 * Module Name: ACF Custom Fields
 * Description: Additional features for ACF Custom Fields
 */


// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load configuration and helpers always
require_once __DIR__ . '/config-constants-acf.php';

// Register always; the shortcode callback returns '' when ACF is unavailable.
require_once __DIR__ . '/acf-shortcodes.php';

// Compute and persist configured calculated ACF option fields on ACF Options save
add_action(
	'acf/save_post',
	'frl_acf_calculate_on_save',
	999,
	1
);

/**
 * Compute and persist each configured calculated option field.
 *
 * Config structure is defined in config-constants-acf.php.
 *
 * @param array<string, array{
 *     operation?: string,
 *     fields?: array,
 *     template?: string,
 *     urlencode?: bool,
 *     decimals?: int,
 *     separator?: string
 * }> $calc_options
 */
function frl_acf_process_calc_options( array $calc_options ): void {
	foreach ( $calc_options as $target => $cfg ) {
		$op           = strtoupper( trim( (string) ( $cfg['operation'] ?? '' ) ) );
		$src_fields   = $cfg['fields'] ?? array();
		$template     = $cfg['template'] ?? '';
		$has_template = is_string( $template ) && $template !== '';

		$new = '';

		// Default to TEMPLATE when no operation provided but fields/template info exists
		if ( $op === '' && ( ! empty( $src_fields ) || $has_template ) ) {
			$op = 'TEMPLATE';
		}

		// TEMPLATE support: template string with placeholders like {field}
		if ( $op === 'TEMPLATE' || $has_template ) {
			if ( ! $has_template ) {
				// fallback: build template dynamically from fields using separator if provided
				$template = implode(
					(string) ( $cfg['separator'] ?? '' ),
					array_map(
						static function ( $f ) {
							return '{' . $f . '}';
						},
						$src_fields
					)
				);
			}
			$urlencode = ! empty( $cfg['urlencode'] );
			$new       = frl_acf_render_template( $template, 'option', 0, $urlencode );
		} elseif ( $op !== '' && ! empty( $src_fields ) ) {
			// Numeric and concat operations
			// Read raw, unformatted values from ACF Options
			$values = array();
			foreach ( $src_fields as $name ) {
				$values[] = get_field( (string) $name, 'option', false );
			}

			$new = frl_acf_compute_value(
				$op,
				$values,
				array(
					'decimals'  => $cfg['decimals'] ?? null,
					'separator' => $cfg['separator'] ?? '',
				)
			);
		} else {
			continue;
		}

		$target_field = false;
		if ( function_exists( 'acf_maybe_get_field' ) ) {
			$target_field = call_user_func( 'acf_maybe_get_field', $target, 'option', false );
		} elseif ( function_exists( 'get_field_object' ) ) {
			$target_field = call_user_func( 'get_field_object', $target, 'option', false );
		} else {
			$target_field = true;
		}

		if ( ! $target_field ) {
			if ( function_exists( 'frl_log' ) ) {
				frl_log( 'ACF calculated option target field missing: {target}', array( 'target' => $target ), false );
			}
			continue;
		}

		// Compare normalized string representations so numeric float/int vs stored string ('10' vs 10.0) never false-triggers.
		$old     = get_field( $target, 'option', false );
		$old_str = ( false !== $old && is_scalar( $old ) ) ? (string) $old : null;
		$new_str = is_scalar( $new ) ? (string) $new : '';

		if ( $old_str !== $new_str ) {
			if ( function_exists( 'update_field' ) ) {
				call_user_func( 'update_field', $target, $new, 'option' );
			} elseif ( function_exists( 'frl_log' ) ) {
				frl_log( 'ACF update_field() not available when saving calculated option {target}', array( 'target' => $target ), false );
			}
		}
	}
}

/**
 * Recompute configured calculated fields when ACF Options are saved.
 *
 * @param string|int $post_id ACF post ID.
 * @return void
 */
function frl_acf_calculate_on_save( $post_id ) {
	if ( $post_id !== 'options' ) {
		return;
	}

	if ( ! defined( 'FRL_ACF_CALC_OPTIONS' ) ) {
		return;
	}

	$calc_options = FRL_ACF_CALC_OPTIONS;
	if ( ! is_array( $calc_options ) || empty( $calc_options ) ) { // @phpstan-ignore-line
		return;
	}

	frl_acf_process_calc_options( $calc_options );
}

/**
 * Compute a value from an array of inputs using an operation.
 * @param string $op Operation name
 * @param array $values Source values (mixed)
 * @param array $args Supported: decimals (int|null), separator (string)
 * @return mixed
 */
function frl_acf_compute_value( $op, array $values, array $args = array() ) {
	$op       = strtoupper( trim( (string) $op ) );
	$decimals = isset( $args['decimals'] ) && $args['decimals'] !== '' ? (int) $args['decimals'] : null;

	// Numeric helpers
	$to_num = static function ( $v ) {
		if ( $v === '' || $v === null ) {
			return null;
		}
		if ( is_numeric( $v ) ) {
			return (float) $v;
		}
		if ( is_string( $v ) ) {
			$cleaned = trim( str_replace( ',', '', $v ) );
			if ( ! is_numeric( $cleaned ) ) {
				return null;
			}
			$n = (float) $cleaned;
			return is_finite( $n ) ? $n : null;
		}
		return null;
	};

	if ( $op === 'CONCAT' ) {
		$sep   = (string) ( $args['separator'] ?? '' );
		$parts = array_filter(
			array_map( static fn( $v ) => is_scalar( $v ) ? (string) $v : '', $values ),
			static fn( $s ) => $s !== ''
		);
		return implode( $sep, $parts );
	}

	// Prepare numeric list
	$nums = array();
	foreach ( $values as $v ) {
		$n = $to_num( $v );
		if ( $n !== null ) {
			$nums[] = $n;
		}
	}

	if ( empty( $nums ) ) {
		return $decimals !== null ? round( 0.0, $decimals ) : 0;
	}

	$result = null;
	switch ( $op ) {
		case 'SUM':
			$result = array_sum( $nums );
			break;
		case 'SUB':
			$result = array_shift( $nums );
			foreach ( $nums as $n ) {
				$result -= $n; }
			break;
		case 'MUL':
			$result = 1.0;
			foreach ( $nums as $n ) {
				$result *= $n; }
			break;
		case 'DIV':
			$result = array_shift( $nums );
			foreach ( $nums as $n ) {
				if ( $n === 0.0 ) {
					continue; }
				$result /= $n;
			}
			break;
		case 'AVG':
			$result = array_sum( $nums ) / max( 1, count( $nums ) );
			break;
		case 'MIN':
			$result = min( $nums );
			break;
		case 'MAX':
			$result = max( $nums );
			break;
		default:
			return '';
	}

	if ( $decimals !== null ) {
		$result = round( (float) $result, $decimals );
	}
	return $result;
}

/**
 * Render a template by replacing {field} placeholders with ACF values.
 * @param string $template Template string
 * @param string $source 'option' or 'post'
 * @param int $post_id When source='post'
 * @param bool $urlencode URL-encode values
 * @return string
 */
function frl_acf_render_template( string $template, string $source = 'option', int $post_id = 0, bool $urlencode = false ): string {
	if ( $template === '' ) {
		return '';
	}
	// Normalize multi-line templates written for readability: remove newlines and surrounding spaces
	if ( str_contains( $template, "\n" ) || str_contains( $template, "\r" ) ) {
		$lines = preg_split( "/\r\n|\r|\n/", $template );
		if ( is_array( $lines ) ) {
			// Trim both ends per line to remove indentation and trailing spaces
			$template = implode( '', array_map( 'trim', $lines ) );
		}
	}
	$pid      = ( $source === 'post' && $post_id > 0 ) ? $post_id : 'option';
	$resolved = array();
	$cb       = static function ( $m ) use ( $pid, $urlencode, &$resolved ) {
		$name = $m[1];
		if ( array_key_exists( $name, $resolved ) ) {
			return $resolved[ $name ];
		}

		$val       = null;
		$has_value = false;
		// Reuse get_field_object()['value'] when available; fall back to get_field() when raw meta lacks a field-key reference.
		if ( function_exists( 'get_field_object' ) ) {
			$field_obj = call_user_func( 'get_field_object', $name, $pid, false );
			if ( is_array( $field_obj ) && array_key_exists( 'value', $field_obj ) ) {
				$val       = $field_obj['value'];
				$has_value = true;
			} elseif ( ! $field_obj && function_exists( 'frl_log' ) ) {
				frl_log(
					'ACF placeholder field missing',
					array(
						'placeholder' => $name,
						'source'      => is_string( $pid ) ? $pid : (string) $pid,
					)
				);
			}
		}

		if ( ! $has_value && function_exists( 'get_field' ) ) {
			$val = get_field( $name, $pid, false );
		}

		$out = is_scalar( $val ) ? (string) $val : '';
		if ( $urlencode && $out !== '' ) {
			$out = rawurlencode( $out );
		}
		$resolved[ $name ] = $out;
		return $out;
	};
	return preg_replace_callback( '/\{([a-zA-Z0-9_\-]+)\}/', $cb, $template ) ?? $template;
}
