<?php
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fralenuvole
 * shortcodes.php - Plugin translation shortcodes and more
 */

// Register shortcodes
add_shortcode( FRL_PREFIX . '_acf_calculated', 'frl_shortcode_acf_calculated' );

/**
 * [frl_acf_calculated] - Displays the calculated value of an ACF field.
 * Usage: [frl_acf_calculated source="option" fields="price,tax" "operation="SUM" target="total_budget" decimals="2"]
 * source: option (default), or post (defaults to current post, works also in loops),
 * target: field name to store the calculated value, default defined in config-constants-acf.php
* fields: comma separated list of fields, default defined in config-constants-acf.php
 * operation: CONCAT,SUM, SUB, MUL, DIV, AVG, MIN, MAX
 * decimals: number of decimals for number operations
 * separator: separator for CONCAT operation, default empty ""
 */

function frl_shortcode_acf_calculated( $atts ) {
	if ( ! function_exists( 'get_field' ) ) {
		if ( function_exists( 'frl_log' ) ) {
			frl_log( 'ACF unavailable: [frl_acf_calculated] returns empty' );
		}
		return '';
	}

	$a = shortcode_atts(
		array(
			'source'    => 'option', // option|post
			'fields'    => '',       // CSV list
			'operation' => 'TEMPLATE', // CONCAT|SUM|SUB|MUL|DIV|AVG|MIN|MAX|TEMPLATE
			'target'    => '',       // precomputed target field name
			'decimals'  => '',       // for numeric ops
			'separator' => '',       // for CONCAT
			'template'  => '',       // for TEMPLATE op, e.g. ?q={field1}&f={field2}#a
			'urlencode' => '',       // 1/0 URL-encode values in TEMPLATE
			'id'        => '',       // post ID when source=post
		),
		$atts,
		FRL_PREFIX . '_acf_calculated'
	);

	// Fast path: if only target is provided (no template/fields/etc.), use cached value
	$target        = trim( (string) $a['target'] );
	$source        = strtolower( trim( (string) $a['source'] ) ) ?: 'option';
	$id_raw        = trim( (string) $a['id'] );
	$has_overrides = ( $a['template'] !== '' ) || ( $a['fields'] !== '' );

	if ( $target !== '' && ! $has_overrides ) {
		$scope = frl_acf_calc_resolve_scope( $source, $id_raw, $target );
		if ( $scope === null ) {
			return '';
		}
		return frl_cache_remember(
			'shortcodes',
			$scope['cache_key'],
			static function () use ( $target, $scope ) {
				$val = get_field( $target, $scope['pid'], false );
				return is_scalar( $val ) ? (string) $val : '';
			}
		);
	}

	$operation  = strtoupper( trim( (string) $a['operation'] ) );
	$fields_csv = trim( (string) $a['fields'] );
	$template   = (string) $a['template'];

	// Explicit template= always enters TEMPLATE mode; otherwise fields= without operation= defaults to CONCAT.
	$is_template = ( '' !== $template ) || ( 'TEMPLATE' === $operation && '' === $fields_csv );
	if ( ! $is_template && '' === $fields_csv ) {
		return '';
	}
	if ( ! $is_template && 'TEMPLATE' === $operation ) {
		$operation = 'CONCAT';
	}

	// TEMPLATE mode — cached by computation signature; no frontend GET DB writes.
	if ( $is_template ) {
		if ( ! function_exists( 'frl_acf_render_template' ) || '' === $template ) {
			return '';
		}
		$scope = frl_acf_calc_resolve_scope( $source, $id_raw, $target );
		if ( $scope === null ) {
			return '';
		}
		$urlencode  = filter_var( $a['urlencode'], FILTER_VALIDATE_BOOLEAN );
		$sig_hash   = md5( wp_json_encode( array( 'TEMPLATE', $template, $urlencode ) ) );
		$compute_ck = $scope['cache_key'] . '_tpl_' . $sig_hash;

		return frl_cache_remember(
			'shortcodes',
			$compute_ck,
			static function () use ( $template, $source, $scope, $urlencode ) {
				return (string) frl_acf_render_template( $template, $source, $scope['post_id'], $urlencode );
			}
		);
	}

	// Numeric / CONCAT paths — cached by computation signature; no frontend GET DB writes.
	$field_names = array_values(
		array_filter(
			array_map(
				static function ( $s ) {
					return sanitize_key( trim( $s ) );
				},
				explode( ',', $fields_csv )
			)
		)
	);

	if ( ! $field_names ) {
		return '';
	}

	$scope = frl_acf_calc_resolve_scope( $source, $id_raw, $target );
	if ( $scope === null ) {
		return '';
	}

	if ( ! function_exists( 'frl_acf_compute_value' ) ) {
		return '';
	}

	$sig_hash   = md5( wp_json_encode( array( $operation, $field_names, $a['decimals'], $a['separator'] ) ) );
	$compute_ck = $scope['cache_key'] . '_op_' . $sig_hash;

	return frl_cache_remember(
		'shortcodes',
		$compute_ck,
		static function () use ( $field_names, $scope, $operation, $a ) {
			$values = array();
			foreach ( $field_names as $name ) {
				$values[] = get_field( $name, $scope['pid'], false );
			}
			$result = frl_acf_compute_value(
				$operation,
				$values,
				array(
					'decimals'  => $a['decimals'],
					'separator' => $a['separator'],
				)
			);
			return is_scalar( $result ) ? (string) $result : '';
		}
	);
}

/**
 * Resolve post/option scope and shortcode cache key for [frl_acf_calculated].
 *
 * @param string $source 'option' or 'post'.
 * @param string $id_raw Explicit post ID attribute or empty string.
 * @param string $target Target field name.
 * @return array{pid: int|string, post_id: int, cache_key: string}|null Null when source='post' has no valid post ID.
 */
function frl_acf_calc_resolve_scope( string $source, string $id_raw, string $target ): ?array {
	if ( 'post' === $source ) {
		$post_id = ( '' !== $id_raw && ctype_digit( $id_raw ) && (int) $id_raw > 0 )
			? (int) $id_raw
			: frl_get_current_post_id();
		if ( $post_id <= 0 ) {
			return null;
		}
		return array(
			'pid'       => $post_id,
			'post_id'   => $post_id,
			'cache_key' => 'acf_calc_' . sanitize_key( $target ) . '_post_' . $post_id . '_v' . frl_get_post_cache_version( $post_id ),
		);
	}

	return array(
		'pid'       => 'option',
		'post_id'   => 0,
		'cache_key' => 'acf_calc_' . sanitize_key( $target ) . '_option',
	);
}

/**
 * Persist a calculated value to its ACF target field (when present) and warm the shortcode cache.
 *
 * @param string $target Target field name.
 * @param array  $scope  Scope array from frl_acf_calc_resolve_scope().
 * @param string $value  Calculated string value.
 * @return void
 */
function frl_acf_calc_persist_and_warm( string $target, array $scope, string $value ): void {
	if ( ! function_exists( 'update_field' ) ) {
		return;
	}

	$field_exists = false;
	if ( function_exists( 'get_field_object' ) ) {
		$field_exists = (bool) call_user_func( 'get_field_object', $target, $scope['pid'], false );
	}

	if ( $field_exists ) {
		call_user_func( 'update_field', $target, $value, $scope['pid'] );
		frl_cache_set( 'shortcodes', $scope['cache_key'], $value );
	} elseif ( function_exists( 'frl_log' ) ) {
		frl_log( 'ACF shortcode target field missing – no-op persist', array( 'target' => $target ) );
	}
}
