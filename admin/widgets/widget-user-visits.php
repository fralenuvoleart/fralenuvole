<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Render the 'User Visits' dashboard widget content.
 *
 * Aggregates recent user activity from user meta, sorting by timestamp
 * to show the most recent visits.
 *
 * @return string The generated HTML content for the widget.
 */
function frl_render_user_visits_widget()
{
    // Cache the rendered widget HTML for 1 hour to avoid per-request
    // get_users() scan + user-meta loops across the full user base.
    return frl_cache_remember('admin', 'user_visits_widget', function () {
        // Start output buffering to capture internal echos
        ob_start();

        // Wrap existing logic in try-catch for error handling
        try {
            // --- New logic: aggregate from user meta ---
            $display_visits = [];
            $total_to_show = 8;
            $user_latest_visits = [];
            $all_visits = [];

            // Get users with frl_user_visits meta (capped at 50 — full scan
            // is too expensive on large user bases; 50 users × ~8 visits each
            // provides enough data for a dashboard widget).
            $recent_users = get_users([
                'meta_key' => frl_prefix('user_visits'),
                'meta_compare' => 'EXISTS',
                'number' => 50,
            ]);

            foreach ($recent_users as $user) {
                $visits = frl_get_user_meta($user->ID, 'user_visits');
                if (frl_is_array_not_empty($visits)) {
                    // Most recent visit for this user
                    $latest = $visits[0];
                    $latest['username'] = $user->user_login;
                    $user_latest_visits[] = $latest;
                    // Collect all visits for fallback
                    foreach ($visits as $v) {
                        $v['username'] = $user->user_login;
                        $all_visits[] = $v;
                    }
                }
            }

            // Sort by timestamp (newest first)
            usort($user_latest_visits, function($a, $b) {
                $time_a = isset($a['timestamp']) ? strtotime($a['timestamp']) : 0;
                $time_b = isset($b['timestamp']) ? strtotime($b['timestamp']) : 0;
                return $time_b - $time_a;
            });
            usort($all_visits, function($a, $b) {
                $time_a = isset($a['timestamp']) ? strtotime($a['timestamp']) : 0;
                $time_b = isset($b['timestamp']) ? strtotime($b['timestamp']) : 0;
                return $time_b - $time_a;
            });

            // Get the top N unique user visits (most recent visit per user)
            $display_visits = array_slice($user_latest_visits, 0, $total_to_show);

            // If still not enough UNIQUE user visits, add more from the sorted list (most recent overall)
            if (count($display_visits) < $total_to_show) {
                $needed = $total_to_show - count($display_visits);
                $already = [];
                foreach ($display_visits as $v) {
                    $already[] = $v['username'] . $v['timestamp'] . $v['page'];
                }
                foreach ($all_visits as $v) {
                    $key = $v['username'] . $v['timestamp'] . $v['page'];
                    if (!in_array($key, $already)) {
                        $display_visits[] = $v;
                        $already[] = $key;
                        if (count($display_visits) >= $total_to_show) break;
                    }
                }
            }

            // --- Process data and generate HTML (using echo internally) ---
            if (empty($display_visits)) {
                echo '<p>' . __('No recent user activity recorded.', FRL_PREFIX) . '</p>';
            } else {
                // Render as before
?>
            <div class="frl-last-visits-list">
                <?php foreach ($display_visits as $visit): ?>
                    <?php
                    $username = esc_html($visit['username'] ?? 'Unknown User');
                    $raw_page_url = $visit['page'] ?? '';
                    $page_display_text = esc_html(frl_get_page_title_from_url($raw_page_url)); // Function already handles empty/invalid URLs
                    $time_ago = isset($visit['timestamp']) ? human_time_diff(strtotime($visit['timestamp']), current_time('timestamp')) . ' ' . __('ago', FRL_PREFIX) : 'Unknown time';
                    $datetime_attr = isset($visit['timestamp']) ? esc_attr(date('c', strtotime($visit['timestamp']))) : '';

                    // Check if the visit is within the last 5 minutes
                    $is_online = false;
                    if (isset($visit['timestamp'])) {
                        $visit_timestamp = strtotime($visit['timestamp']);
                        $current_timestamp = current_time('timestamp');
                        if (($current_timestamp - $visit_timestamp) < 300) { // 5 minutes = 300 seconds
                            $is_online = true;
                        }
                    }
                    ?>
                    <article class="post-item<?php echo $is_online ? ' online' : ''; ?>">
                        <div class="visit-url">
                            <?php if (!empty($raw_page_url)): ?>
                                <a href="<?php echo esc_url($raw_page_url); ?>" title="<?php echo esc_attr($raw_page_url); ?>">
                                    <?php echo $page_display_text; ?>
                                </a>
                            <?php else: ?>
                                <?php echo $page_display_text; // Display 'Unknown Page' if no URL ?>
                            <?php endif; ?>
                        </div>
                        <div class="visit-user">
                            <?php echo $username; ?>
                        </div>
                        <div class="visit-date">
                            <?php if ($datetime_attr): ?>
                                <time datetime="<?php echo $datetime_attr; ?>">
                                    <?php echo esc_html($time_ago); ?>
                                </time>
                            <?php else: ?>
                                <?php echo esc_html($time_ago); ?>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
<?php
            }
        } catch (Exception $e) {
            frl_log('User Visits Widget Error: {error}', ['error' => $e->getMessage()]);
            echo '<p class="error">' . esc_html__('Content unavailable.', FRL_PREFIX) . '</p>';
        }
        // Return the captured output
        return ob_get_clean();
    }, HOUR_IN_SECONDS);
}
