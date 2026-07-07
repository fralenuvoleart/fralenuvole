<?php

/**
 * Form Filters
 * Custom form filters for WS Form.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WS Form Stats - Dashboard Widget Functions
 */

// Data functions are included by wsform.php

/**
 * Render the form submissions dashboard widget for WordPress admin
 *
 * @param int|null   $form_id           Specific form ID, or null for combined view.
 * @param array|null $grouped_counts    Pre-computed grouped counts (used by combined widget).
 * @return string The HTML content for the widget.
 */
function frl_wsf_render_dashboard_widget( $form_id = null, $grouped_counts = null, $show_cta = false ) {
	ob_start();

	if ( $grouped_counts === null ) {
		$grouped_data   = frl_wsf_get_grouped_submission_data( 7, $form_id );
		$grouped_counts = $grouped_data['grouped_counts'] ?? array();
	}

	// Calculate the overall maximum count for scaling across all languages
	$global_max_count = 0;
	foreach ( $grouped_counts as $language => $group_values ) {
		foreach ( $group_values as $value => $count ) {
			if ( $count > $global_max_count ) {
				$global_max_count = $count;
			}
		}
	}
	$global_max_count = max( 1, $global_max_count ); // Ensure we don't divide by zero

	// Don't echo the outer wrapper here
	// echo '<div class="frl-wsf-dashboard-widget">';

	// No data case
	if ( ! frl_is_array_not_empty( $grouped_counts ) ) {
		echo '<p>No submission data found for the last 7 days.</p>';
	} else {
		// Create a table to display language/service groups with bars
		echo '<table class="widefat fixed" cellspacing="0">';
		echo '<thead>';
		echo '<tr>';
		echo '<th class="wsf-service-header">Language / Service</th>';
		if ( $show_cta ) {
			echo '<th class="wsf-cta-header">CTA</th>';
		}
		echo '<th class="wsf-submissions-header">Submissions</th>';
		echo '</tr>';
		echo '</thead>';
		echo '<tbody>';

		$grand_total = 0;

		// Calculate grand total first
		foreach ( $grouped_counts as $language => $group_values ) {
			$lang_total   = array_sum( $group_values );
			$grand_total += $lang_total;
		}

		// Add Grand Total row at the beginning
		if ( $grand_total > 0 ) {
			echo '<tr class="wsf-total-row">';
			echo '<td><strong>TOTAL</strong></td>';
			if ( $show_cta ) {
				echo '<td></td>'; }
			echo '<td><strong>' . esc_html( (string) $grand_total ) . '</strong></td>';
			echo '</tr>';
		}

		$row_class = '';

		$calc_log_percentage = function ( $value, $max_value ) {
			if ( $max_value <= 0 || $value <= 0 ) {
				return 0;
			}
			if ( $max_value === 1 ) {
				return $value === 1 ? 100 : 0;
			}
			$log_ratio = log( $value ) / log( $max_value );
			return max( 0, min( 100, $log_ratio * 100 ) );
		};

		// CTA subtotals — extract from composite keys or fetch separately for per-form widgets
		$cta_totals = array();
		foreach ( $grouped_counts as $lang => $groups ) {
			foreach ( $groups as $key => $count ) {
				if ( str_contains( (string) $key, '|||' ) ) {
					$cta                      = explode( '|||', $key, 2 )[1];
					$cta_label                = $cta !== '' ? $cta : '(No CTA)';
					$cta_totals[ $cta_label ] = ( $cta_totals[ $cta_label ] ?? 0 ) + $count;
				}
			}
		}
		if ( empty( $cta_totals ) && $form_id !== null ) {
			$cta_data = frl_wsf_get_grouped_submission_data( 7, $form_id, null, null, true );
			foreach ( ( $cta_data['grouped_counts'] ?? array() ) as $lang => $groups ) {
				foreach ( $groups as $key => $count ) {
					$cta                      = explode( '|||', $key, 2 )[1] ?? '';
					$cta_label                = $cta !== '' ? $cta : '(No CTA)';
					$cta_totals[ $cta_label ] = ( $cta_totals[ $cta_label ] ?? 0 ) + $count;
				}
			}
		}
		arsort( $cta_totals );
		if ( count( $cta_totals ) > 1 ) {
			foreach ( $cta_totals as $cta_label => $cta_count ) {
				$row_class = ( $row_class === '' ) ? 'alternate' : '';
				$cta_slug  = sanitize_html_class( strtolower( $cta_label ) );
				$bar_width = max( 3, $calc_log_percentage( $cta_count, $global_max_count ) );
				echo '<tr class="wsf-subtotal-row ' . $row_class . ' wsf-cta-' . $cta_slug . '">';
				echo '<td>' . esc_html( $cta_label ) . '</td>';
				if ( $show_cta ) {
					echo '<td></td>'; }
				echo '<td>';
				echo '<div class="wsf-bar-container">';
				echo '<div class="wsf-bar" style="width: ' . $bar_width . '%;">' . $cta_count . '</div>';
				echo '</div>';
				echo '</td>';
				echo '</tr>';
			}
		}

		// Loop through each language group
		foreach ( $grouped_counts as $language => $group_values ) {
			$lang_total = array_sum( $group_values );

			// Display language header row
			$row_class = ( $row_class === '' ) ? 'alternate' : '';
			echo '<tr class="' . $row_class . ' wsf-lang-header">';
			echo '<td><strong>' . esc_html( $language ) . '</strong></td>';
			if ( $show_cta ) {
				echo '<td></td>'; }
			echo '<td><strong>' . esc_html( (string) $lang_total ) . '</strong></td>';
			echo '</tr>';

			// Sort services by count in descending order
			arsort( $group_values );

			// Display each service with bar
			foreach ( $group_values as $value => $count ) {
				$row_class = ( $row_class === '' ) ? 'alternate' : '';

				// Calculate bar width percentage with logarithmic scaling
				// Minimum 3% for visibility, allow full 100% width
				$bar_width = $calc_log_percentage( $count, $global_max_count );
				$bar_width = max( 3, $bar_width );

				echo '<tr class="' . $row_class . '">';
				if ( $show_cta ) {
					$parts    = explode( '|||', $value, 2 );
					$cta_val  = $parts[1] ?? '';
					$cta_slug = sanitize_html_class( strtolower( $cta_val ) );
					echo '<td>' . esc_html( $parts[0] ) . '</td>';
					echo '<td class="wsf-cta-' . $cta_slug . '">' . esc_html( $cta_val ) . '</td>';
				} else {
					echo '<td>' . esc_html( $value ) . '</td>';
				}
				echo '<td>';
				echo '<div class="wsf-bar-container">';
				echo '<div class="wsf-bar" style="width: ' . $bar_width . '%;">' . $count . '</div>';
				echo '</div>';
				echo '</td>';
				echo '</tr>';
			}
		}

		echo '</tbody>';
		echo '</table>';
	}
	if ( frl_has_access( 'manage_options' ) ) {
		$link = $form_id !== null
			? '/wp-admin/admin.php?page=ws-form-submit&id=' . (int) $form_id . '&paged=1'
			: '/wp-admin/admin.php?page=ws-form-submit';
		echo '<p class="frl-dashboard-widget-footer">';
		echo '<a href="' . $link . '">Form Submissions Details</a>';
		echo '</p>';
	}

	// Don't echo the closing outer wrapper
	// echo '</div>';

	// Return the captured output
	return ob_get_clean();
}

/**
 * Render the combined dashboard widget aggregating all configured forms.
 * Fetches data per-form (correct field maps) then merges and re-sorts.
 *
 * @return string The HTML content for the widget.
 */
function frl_wsf_render_combined_dashboard_widget() {
	$all_configs = frl_wsf_get_all_webhook_configs();

	$form_ids = array();
	foreach ( $all_configs as $cfg ) {
		$fid = $cfg['form_id'] ?? null;
		if ( $fid !== null && ! in_array( (int) $fid, $form_ids, true ) ) {
			$form_ids[] = (int) $fid;
		}
	}

	$merged = array();

	foreach ( $form_ids as $fid ) {
		$data = frl_wsf_get_grouped_submission_data( 7, $fid, null, null, true );
		foreach ( ( $data['grouped_counts'] ?? array() ) as $lang => $groups ) {
			foreach ( $groups as $label => $count ) {
				$merged[ $lang ][ $label ] = ( $merged[ $lang ][ $label ] ?? 0 ) + $count;
			}
		}
	}

	$lang_totals = array();
	foreach ( $merged as $lang => &$groups ) {
		$lang_totals[ $lang ] = array_sum( $groups );
		arsort( $groups );
	}
	unset( $groups );
	arsort( $lang_totals );

	$sorted = array();
	foreach ( $lang_totals as $lang => $total ) {
		if ( isset( $merged[ $lang ] ) ) {
			$sorted[ $lang ] = $merged[ $lang ];
		}
	}

	return frl_wsf_render_dashboard_widget( null, $sorted, true );
}
