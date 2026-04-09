<?php

/**
 * WS Form Stats - Data Retrieval Functions
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

// Widget functions moved to wsform-stats-widget.php

/**
 * Get WS Form submission data for the specified number of days
 *
 * @param int $days Number of days to retrieve data for
 * @return array Structured data with submissions by date and form information
 */
function frl_wsf_get_submission_data($days = 30) {
    global $wpdb;

    $stats_form_ids = defined('WS_STATS_FORM_IDS') ? WS_STATS_FORM_IDS : null;
    if (is_array($stats_form_ids)) {
        $stats_form_ids = array_filter($stats_form_ids, 'is_int');
        if (empty($stats_form_ids)) {
            $stats_form_ids = null;
        }
    } elseif ($stats_form_ids !== null) {
        $stats_form_ids = [(int) $stats_form_ids];
    }
    $cache_suffix = is_array($stats_form_ids) ? implode('_', $stats_form_ids) : 'all';
    $cache_key = "submission_data_{$days}_{$cache_suffix}";
    return frl_cache_remember('admin', $cache_key, function () use ($wpdb, $days, $stats_form_ids) {
        // Calculate date range
        $end_date = current_time('Y-m-d');
        $start_date = date('Y-m-d', strtotime("-{$days} days", strtotime($end_date)));

        // Get submissions from WS Form
        $submissions_table = $wpdb->prefix . 'wsf_submit';

        // Check if the table exists
        $table_exists = $wpdb->get_var(
            $wpdb->prepare("SHOW TABLES LIKE %s", $submissions_table)
        ) === $submissions_table;

        $results = [];

        if ($table_exists) {
            // Get form submissions within date range, optionally filtered by WS_STATS_FORM_IDS
            $query_sql = "SELECT
                    DATE(s.date_added) as submit_date,
                    s.form_id,
                    COUNT(*) as submission_count
                FROM
                    $submissions_table s
                WHERE
                    s.date_added BETWEEN %s AND %s";

            $query_params = [$start_date . ' 00:00:00', $end_date . ' 23:59:59'];

            if (is_array($stats_form_ids) && !empty($stats_form_ids)) {
                $placeholders = implode(', ', array_fill(0, count($stats_form_ids), '%d'));
                $query_sql .= " AND s.form_id IN ($placeholders)";
                $query_params = array_merge($query_params, array_map('intval', $stats_form_ids));
            }

            $query_sql .= " GROUP BY submit_date, s.form_id ORDER BY submit_date DESC";

            $query = $wpdb->prepare($query_sql, $query_params);

            $results = $wpdb->get_results($query);
        }

        // Get form names
        $form_ids = array_unique(wp_list_pluck($results, 'form_id'));
        $form_names = [];

        foreach ($form_ids as $form_id) {
            $form_names[$form_id] = frl_wsf_get_form_name($form_id);
        }

        // Process results into a structured format
        $submissions_by_date = [];

        // Initialize dates array for the entire date range
        $date_range = new DatePeriod(
            new DateTime($start_date),
            new DateInterval('P1D'),
            new DateTime($end_date . ' +1 day')
        );

        foreach ($date_range as $date) {
            $date_str = $date->format('Y-m-d');
            $submissions_by_date[$date_str] = [];
        }

        // Populate with actual data
        foreach ($results as $row) {
            // Add to submissions by date
            if (!isset($submissions_by_date[$row->submit_date][$row->form_id])) {
                $submissions_by_date[$row->submit_date][$row->form_id] = 0;
            }
            $submissions_by_date[$row->submit_date][$row->form_id] += $row->submission_count;
        }

        return [
            'submissions_by_date' => $submissions_by_date,
            'form_names' => $form_names,
            'date_range' => [
                'start' => $start_date,
                'end' => $end_date
            ]
        ];
    });
}

/**
 * Get form name by ID
 *
 * @param int $form_id WS Form ID
 * @return string Form name or ID if not found
 */
function frl_wsf_get_form_name($form_id) {
    global $wpdb;

    $form_table = $wpdb->prefix . 'posts';
    $form_name = $wpdb->get_var($wpdb->prepare(
        "SELECT post_title FROM $form_table WHERE ID = %d AND post_type = 'wsf-form'",
        $form_id
    ));

    return $form_name ? $form_name : 'Form #' . $form_id;
}

/**
 * Helper function to extract a two-letter language slug from a URL path.
 * Assumes language slug is the first segment and exactly two characters long (e.g., /en/, /ru/, /ka/, /ar/, /zh/).
 * Defaults to 'EN' if the structure doesn't match or format is unexpected.
 *
 * @param string $url The URL string.
 * @return string The extracted two-letter language code (uppercase) or 'EN'.
 */
function frl_wsf_extract_lang_from_url($url) {
    if (empty($url) || !is_string($url)) {
        return 'EN'; // Default for empty or invalid input
    }

    $path = wp_parse_url($url, PHP_URL_PATH);
    if (empty($path) || $path === '/') {
        return 'EN'; // No path or just root path
    }

    // Remove leading/trailing slashes and split the path
    $path_segments = explode('/', trim($path, '/'));

    // Check if the first segment exists and is exactly two characters long
    if (!empty($path_segments) && isset($path_segments[0]) && strlen($path_segments[0]) === 2) {
        // Assume it's a language code, return it uppercase
        return strtoupper($path_segments[0]);
    }

    // Default if the first segment isn't a two-letter code
    return 'EN';
}

/**
 * Get submission data grouped by language (from field_20) and field_8 value.
 *
 * @param int $days Number of days of data to retrieve (used if start/end date not provided).
 * @param int|null $form_id Optional specific Form ID to filter by.
 * @param string|null $start_date Specific start date (YYYY-MM-DD). Overrides $days.
 * @param string|null $end_date Specific end date (YYYY-MM-DD). Overrides $days. Defaults to start_date if only start_date is provided.
 * @return array Array of grouped submission data: [lang][field_8_value] => count.
 */
function frl_wsf_get_grouped_submission_data($days = 30, $form_id = null, $start_date = null, $end_date = null, $include_cta = false) {
    $cache_key = "grouped_submission_data_{$days}_{$form_id}_{$start_date}_{$end_date}" . ($include_cta ? '_cta' : '');
    return frl_cache_remember('admin', $cache_key, function () use ($start_date, $end_date, $form_id, $days, $include_cta) {
        // Determine final start and end dates based on inputs
        if (!empty($start_date)) {
            if (!preg_match("/^\d{4}-\d{2}-\d{2}$/", $start_date)) {
                $start_date = current_time('Y-m-d');
            }
            if (empty($end_date)) {
                $end_date = $start_date;
            } elseif (!preg_match("/^\d{4}-\d{2}-\d{2}$/", $end_date)) {
                $end_date = $start_date;
            }
        } else {
            $end_date_time = current_time('timestamp');
            $end_date = date('Y-m-d', $end_date_time);
            $start_date = date('Y-m-d', strtotime("-{$days} days", $end_date_time));
        }

        // Get dynamic field mappings from modular config
        $all_configs = frl_wsf_get_all_webhook_configs();

        $fields_map = [];
        foreach ($all_configs as $webhook_config) {
            $target_id = $webhook_config['form_id'] ?? null;
            if ($target_id !== null && ((int)$target_id === (int)$form_id || $form_id === null)) {
                $fields_map = $webhook_config['fields_map'] ?? [];
                break;
            }
        }

        if (empty($fields_map) && !empty($all_configs)) {
            $fields_map = $all_configs[0]['fields_map'] ?? [];
        }

        // Get the actual field IDs for the labels we need
        $language_field = $fields_map['Language'] ?? null;
        $service_field = $fields_map['Service'] ?? null;
        $page_url_field = $fields_map['Page URL'] ?? null;
        $cta_field = $include_cta ? ($fields_map['CTA'] ?? null) : null;

        if (empty($language_field) || empty($service_field) || empty($page_url_field)) {
            return [
                'grouped_counts' => [],
                'date_range' => [
                    'start' => $start_date,
                    'end' => $end_date
                ],
                'form_id' => $form_id
            ];
        }

        // Define the meta keys needed for this specific grouping
        $required_meta_keys = [$language_field, $service_field, $page_url_field];
        if ($cta_field !== null) {
            $required_meta_keys[] = $cta_field;
        }

        // Fetch raw data using the reusable function
        $raw_submissions = frl_wsf_get_raw_submission_meta($required_meta_keys, $start_date, $end_date, $form_id);

        // Pre-fetch the list of contact page slugs once
        $contact_slugs = frl_wsf_get_page_translation_slugs('contact');

        // Process raw data into grouped counts
        $grouped_counts = [];
        if (frl_is_array_not_empty($raw_submissions)) {
            foreach ($raw_submissions as $submission) {
                // Get language from dynamic language field, default to '(No Language)' if missing or empty
                $language = isset($submission['meta'][$language_field]) && !empty($submission['meta'][$language_field]) ?
                           $submission['meta'][$language_field] :
                           '(No Language)';

                // Get service field value, default to '(empty)' if missing
                if (isset($submission['meta'][$service_field]) && !empty($submission['meta'][$service_field])) {
                    $group_value = 'Service: ' . $submission['meta'][$service_field];
                } else {
                    // Service is empty, check page URL
                    $page_url = $submission['meta'][$page_url_field] ?? '';

                    if (frl_string_contains_item_from_array($page_url, $contact_slugs)) {
                        $group_value = 'Contact';
                    } elseif (frl_is_homepage_url($page_url)) {
                        $group_value = 'Homepage';
                    } else {
                        $group_value = 'Other Webpages';
                    }
                }

                $group_key = $group_value;
                if ($cta_field !== null) {
                    $cta_value = (isset($submission['meta'][$cta_field]) && !empty($submission['meta'][$cta_field]))
                        ? $submission['meta'][$cta_field] : '';
                    $group_key = $group_value . '|||' . $cta_value;
                }

                if (!isset($grouped_counts[$language])) {
                    $grouped_counts[$language] = [];
                }
                if (!isset($grouped_counts[$language][$group_key])) {
                    $grouped_counts[$language][$group_key] = 0;
                }
                $grouped_counts[$language][$group_key]++;
            }
        }

        // --- Sorting Logic (remains the same) ---
        $language_totals = [];
        foreach ($grouped_counts as $lang => $groups) {
            $language_totals[$lang] = array_sum($groups);
        }

        // 2. Sort languages by total submissions (descending)
        arsort($language_totals);

        // 3. Sort field_8 values within each language by count (descending)
        foreach ($grouped_counts as $lang => &$groups) {
            arsort($groups);
        }
        unset($groups); // Unset reference after loop

        // 4. Rebuild the array in the new language order
        $sorted_grouped_counts = [];
        foreach ($language_totals as $lang => $total) {
            if (isset($grouped_counts[$lang])) {
                $sorted_grouped_counts[$lang] = $grouped_counts[$lang]; // Add the already sorted inner array
            }
        }
        // --- End New Sorting Logic ---

        return [
            'grouped_counts' => $sorted_grouped_counts,
            'date_range' => [
                'start' => $start_date,
                'end' => $end_date
            ],
            'form_id' => $form_id
        ];
    });
}

/**
 * Get raw submission metadata for specified keys and date range
 *
 * @param array $meta_keys Array of meta keys to retrieve
 * @param string $start_date Start date in YYYY-MM-DD format
 * @param string $end_date End date in YYYY-MM-DD format
 * @param int|null $form_id Optional specific Form ID to filter by
 * @return array Structured array of submissions with metadata
 */
function frl_wsf_get_raw_submission_meta(array $meta_keys, $start_date, $end_date, $form_id = null) {
    global $wpdb;

    if (!frl_is_array_not_empty($meta_keys) || empty($start_date) || empty($end_date)) {
        return []; // Need keys and dates
    }

    $submit_table = $wpdb->prefix . 'wsf_submit';
    $meta_table = $wpdb->prefix . 'wsf_submit_meta';

    // Check if tables exist
    $submit_table_exists = $wpdb->get_var(
        $wpdb->prepare("SHOW TABLES LIKE %s", $submit_table)
    ) === $submit_table;
    $meta_table_exists = $wpdb->get_var(
        $wpdb->prepare("SHOW TABLES LIKE %s", $meta_table)
    ) === $meta_table;

    if (!$submit_table_exists || !$meta_table_exists) {
        return [];
    }

    // Prepare placeholders for meta keys in the IN clause
    $meta_key_placeholders = implode(', ', array_fill(0, count($meta_keys), '%s'));

    // Base query parts
    $query = "
        SELECT
            s.id AS submit_id,
            s.date_added,
            meta.meta_key,
            meta.meta_value
        FROM
            $submit_table s
        LEFT JOIN -- Use LEFT JOIN to get submission even if some meta keys are missing for it
            $meta_table meta ON s.id = meta.parent_id AND meta.meta_key IN ($meta_key_placeholders)
        WHERE
            s.date_added BETWEEN %s AND %s";

    // Prepare parameters
    // Note: Parameters for IN clause must be passed individually
    $params = $meta_keys;
    $params[] = $start_date . ' 00:00:00';
    $params[] = $end_date . ' 23:59:59';

    // Add form ID condition if specified
    if (!empty($form_id) && is_numeric($form_id)) {
        $query .= " AND s.form_id = %d";
        $params[] = $form_id;
    }

    $query .= " ORDER BY s.id, meta.id"; // Order by submit_id then meta id

    // Prepare and execute the query
    $prepared_query = $wpdb->prepare($query, $params);
    $results = $wpdb->get_results($prepared_query);

    // Process results into structured array: [submit_id => {submit_id, date_added, meta => [key=>value]}]
    $submissions = [];
    if (frl_is_array_not_empty($results)) {
        foreach ($results as $row) {
            $submit_id = $row->submit_id;
            if (!isset($submissions[$submit_id])) {
                $submissions[$submit_id] = [
                    'submit_id' => $submit_id,
                    'date_added' => $row->date_added,
                    'meta' => [] // Initialize meta array for this submission
                ];
            }
            if ($row->meta_key !== null) {
                $meta_value = maybe_unserialize($row->meta_value);
                if (is_array($meta_value)) {
                    if (isset($meta_value['value'])) {
                        $meta_value = $meta_value['value'];
                    }
                    if (is_array($meta_value)) {
                        $meta_value = empty($meta_value[0]) ? '' : $meta_value[0];
                    }
                }
                $submissions[$submit_id]['meta'][$row->meta_key] = $meta_value;
            }
        }
    }

    return array_values($submissions); // Return as a simple array of submission objects
}

/**
 * Gets all translated slugs for a page based on its default language slug.
 *
 * @param string $base_slug The slug of the page in the site's default language.
 * @return array An array of all translation slugs for the given page.
 */
function frl_wsf_get_page_translation_slugs($base_slug)
{
    $slugs = [];
    $base_page_id = frl_get_post_id_by_slug($base_slug);

    if ($base_page_id) {
        $translations = frl_get_post_translations($base_page_id);
        if (!empty($translations)) {
            foreach ($translations as $post_id) {
                $slug = get_post_field('post_name', $post_id);
                if ($slug) {
                    $slugs[] = $slug;
                }
            }
        }
    }
    return $slugs;
}

/**
 * Calculate a percentage value based on a logarithmic scale.
 * Useful for scaling chart bars where data has a wide range.
 *
 * @param int $count      The current value.
 * @param int $max_count  The maximum value in the dataset (used for scaling).
 * @param int $base       The logarithm base (default 10).
 * @return float The calculated percentage (0-100).
 */
function frl_wsf_calculate_log_percentage($count, $max_count, $base = 10) {
    // Ensure non-negative values and max_count is at least 1
    $count = max(0, (int) $count);
    $max_count = max(1, (int) $max_count);
    $base = max(2, (int) $base); // Ensure base is valid

    // Add 1 to handle log(0) case and ensure a 0-1 range output for the ratio
    $log_count = log($count + 1, $base);
    $log_max_count = log($max_count + 1, $base);

    // Avoid division by zero if somehow log_max_count is 0 (shouldn't happen if max_count >= 1 and base >= 2)
    if ($log_max_count <= 0) {
        return 0.0;
    }

    $percentage = ($log_count / $log_max_count) * 100;

    return max(0.0, min(100.0, $percentage)); // Clamp between 0 and 100
}
