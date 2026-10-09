<?php
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the 'User Visits' dashboard widget content.
 *
 * Aggregates recent user activity from user meta, sorting by timestamp
 * to show the most recent visits. Supports single-user filtering via
 * ?frl_visit_user=ID query parameter.
 *
 * @return string The generated HTML content for the widget.
 */
function frl_render_user_visits_widget() {
	// Start output buffering to capture internal echos
	ob_start();

	// Wrap existing logic in try-catch for error handling
	try {
		// Check for single-user filter mode
		$filter_user_id = isset( $_GET['frl_visit_user'] ) ? (int) $_GET['frl_visit_user'] : 0;
		$is_filtered    = $filter_user_id > 0;

		if ( $is_filtered ) {
			// Fetch only this user's visits directly from their meta
			$filter_user = get_userdata( $filter_user_id );
			if ( $filter_user ) {
				$visits = frl_get_user_meta( $filter_user_id, 'user_visits' ) ?: array();
				foreach ( $visits as &$v ) {
					$v['username'] = $filter_user->user_login;
					$v['user_id']  = $filter_user_id;
				}
				unset( $v );
				$display_visits = $visits;
			} else {
				$display_visits = array();
			}
		} else {
			// --- Normal logic: aggregate from user meta ---
			$display_visits     = array();
			$total_to_show      = 8;
			$user_latest_visits = array();
			$all_visits         = array();

			// Get users with frl_user_visits meta (capped at 50 — full scan
			// is too expensive on large user bases; 50 users × ~8 visits each
			// provides enough data for a dashboard widget).
			$recent_users = get_users(
				array(
					'meta_key'     => frl_prefix( 'user_visits' ),
					'meta_compare' => 'EXISTS',
					'number'       => 50,
				)
			);

			foreach ( $recent_users as $user ) {
				$visits = frl_get_user_meta( $user->ID, 'user_visits' );
				if ( frl_is_array_not_empty( $visits ) ) {
					// Most recent visit for this user
					$latest               = $visits[0];
					$latest['username']   = $user->user_login;
					$latest['user_id']    = $user->ID;
					$user_latest_visits[] = $latest;
					// Collect all visits for fallback
					foreach ( $visits as $v ) {
						$v['username'] = $user->user_login;
						$v['user_id']  = $user->ID;
						$all_visits[]  = $v;
					}
				}
			}

			// Sort by timestamp (newest first)
			usort(
				$user_latest_visits,
				function ( $a, $b ) {
					$time_a = isset( $a['timestamp'] ) ? strtotime( $a['timestamp'] ) : 0;
					$time_b = isset( $b['timestamp'] ) ? strtotime( $b['timestamp'] ) : 0;
					return $time_b - $time_a;
				}
			);
			usort(
				$all_visits,
				function ( $a, $b ) {
					$time_a = isset( $a['timestamp'] ) ? strtotime( $a['timestamp'] ) : 0;
					$time_b = isset( $b['timestamp'] ) ? strtotime( $b['timestamp'] ) : 0;
					return $time_b - $time_a;
				}
			);

			// Get the top N unique user visits (most recent visit per user)
			$display_visits = array_slice( $user_latest_visits, 0, $total_to_show );

			// If still not enough UNIQUE user visits, add more from the sorted list (most recent overall)
			if ( count( $display_visits ) < $total_to_show ) {
				$needed  = $total_to_show - count( $display_visits );
				$already = array();
				foreach ( $display_visits as $v ) {
					$already[] = $v['username'] . $v['timestamp'] . $v['page'];
				}
				foreach ( $all_visits as $v ) {
					$key = $v['username'] . $v['timestamp'] . $v['page'];
					if ( ! in_array( $key, $already, true ) ) {
						$display_visits[] = $v;
						$already[]        = $key;
						if ( count( $display_visits ) >= $total_to_show ) {
							break;
						}
					}
				}
			}
		}

		// --- Process data and generate HTML (using echo internally) ---
		if ( $is_filtered ) {
			$filter_username = ! empty( $filter_user ) ? esc_html( $filter_user->user_login ) : __( 'Unknown User', FRL_PREFIX );
			$back_url        = remove_query_arg( 'frl_visit_user' );
			echo '<p class="frl-visit-filter-header">';
			echo '<a href="' . esc_url( $back_url ) . '" class="frl-back-to-all">← ' . __( 'Back to all users', FRL_PREFIX ) . '</a>';
			echo ' <span class="frl-filtered-user">' . sprintf( __( 'Visits for: %s', FRL_PREFIX ), '<strong>' . $filter_username . '</strong>' ) . '</span>';
			echo '</p>';
		}

		if ( empty( $display_visits ) ) {
			echo '<p>' . __( 'No recent user activity recorded.', FRL_PREFIX ) . '</p>';
		} else {
			// Render as before
			?>
			<div class="frl-last-visits-list">
					<?php foreach ( $display_visits as $visit ) : ?>
						<?php
						$username          = esc_html( $visit['username'] ?? 'Unknown User' );
						$raw_page_url      = $visit['page'] ?? '';
						$page_display_text = esc_html( frl_get_page_title_from_url( $raw_page_url ) ); // Function already handles empty/invalid URLs
						// phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- Intentional: $visit['timestamp'] is stored via current_time('mysql') (local-site-time string), so current_time('timestamp') (same local-time basis) is required here to stay consistent with it. Using time() (true UTC) would introduce an offset bug equal to the site's GMT offset.
						$time_ago      = isset( $visit['timestamp'] ) ? human_time_diff( strtotime( $visit['timestamp'] ), current_time( 'timestamp' ) ) . ' ' . __( 'ago', FRL_PREFIX ) : 'Unknown time';
						$datetime_attr = isset( $visit['timestamp'] ) ? esc_attr( gmdate( 'c', strtotime( $visit['timestamp'] ) ) ) : '';

						// Check if the visit is within the last 5 minutes
						$is_online = false;
						if ( isset( $visit['timestamp'] ) ) {
							$visit_timestamp = strtotime( $visit['timestamp'] );
							// phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- Intentional: must match the local-time basis of $visit['timestamp'] (see note above). time() would be off by the site's GMT offset.
							$current_timestamp = current_time( 'timestamp' );
							if ( ( $current_timestamp - $visit_timestamp ) < 300 ) { // 5 minutes = 300 seconds
								$is_online = true;
							}
						}

						// Build username link for non-filtered mode
						$user_id = isset( $visit['user_id'] ) ? (int) $visit['user_id'] : 0;
						?>
					<article class="post-item<?php echo $is_online ? ' online' : ''; ?>">
						<div class="visit-url">
							<?php if ( ! empty( $raw_page_url ) ) : ?>
								<a href="<?php echo esc_url( $raw_page_url ); ?>" title="<?php echo esc_attr( $raw_page_url ); ?>">
									<?php echo $page_display_text; ?>
								</a>
							<?php else : ?>
								<?php echo $page_display_text; // Display 'Unknown Page' if no URL ?>
							<?php endif; ?>
						</div>
						<div class="visit-user">
							<?php if ( ! $is_filtered && $user_id > 0 ) : ?>
								<a href="<?php echo esc_url( add_query_arg( 'frl_visit_user', $user_id ) ); ?>" title="<?php echo esc_attr( sprintf( __( 'Show all visits for %s', FRL_PREFIX ), $username ) ); ?>">
									<?php echo $username; ?>
								</a>
							<?php else : ?>
								<?php echo $username; ?>
							<?php endif; ?>
						</div>
						<div class="visit-date">
							<?php if ( $datetime_attr ) : ?>
								<time datetime="<?php echo $datetime_attr; ?>">
									<?php echo esc_html( $time_ago ); ?>
								</time>
							<?php else : ?>
								<?php echo esc_html( $time_ago ); ?>
							<?php endif; ?>
						</div>
					</article>
				<?php endforeach; ?>
			</div>
					<?php
		}
	} catch ( Exception $e ) {
		frl_log( 'User Visits Widget Error: {error}', array( 'error' => $e->getMessage() ) );
		echo '<p class="error">' . esc_html__( 'Content unavailable.', FRL_PREFIX ) . '</p>';
	}
	// Return the captured output
	return ob_get_clean();
}
