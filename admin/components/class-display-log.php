<?php

/**
 * Log Manager class to display WordPress debug.log contents.
 *
 * @package Fralenuvole
 */

// Exit if accessed directly.
if (! defined('ABSPATH')) {
	exit;
}

/**
 * Register AJAX handlers to follow the plugin's naming convention.
 * These functions will be auto-discovered by frl_autodiscover_admin_actions()
 */
function frl_post_ajax_debug_log_clear()
{
	$log_manager = new Frl_Log_Manager();
	$log_manager->clear_debug_log();
}

function frl_post_ajax_debug_log_refresh()
{
	$log_manager = new Frl_Log_Manager();
	$log_manager->ajax_get_log_entries();
}

function frl_post_ajax_debug_log_download()
{
	$log_manager = new Frl_Log_Manager();
	$log_manager->download_debug_log();
}

/**
 * Class Log_Manager
 *
 * Manages and displays WordPress debug.log entries in a table with filtering and controls.
 */
class Frl_Log_Manager
{

	/**
	 * Path to the debug.log file.
	 *
	 * @var string
	 */
	private $log_file;

	/**
	 * Number of log entries to show.
	 *
	 * @var int
	 */
	private $entries_limit = 20;

	/**
	 * Sort order for log entries.
	 *
	 * @var string
	 */
	private $sort_order = 'desc';

	/**
	 * Error type filter.
	 *
	 * @var string
	 */
	private $error_filter = 'all';

	/**
	 * Constructor.
	 */
	public function __construct()
	{
		// Set default path to WP debug.log.
		$this->log_file = WP_CONTENT_DIR . '/debug.log';

		// AJAX handlers are now registered via standalone functions
	}

	/**
	 * Set entries limit.
	 *
	 * @param int $limit Number of entries to show.
	 * @return void
	 */
	public function set_entries_limit($limit)
	{
		$this->entries_limit = absint($limit);
	}

	/**
	 * Set sort order.
	 *
	 * @param string $order Sort order ('asc' or 'desc').
	 * @return void
	 */
	public function set_sort_order($order)
	{
		$this->sort_order = in_array($order, array('asc', 'desc'), true) ? $order : 'desc';
	}

	/**
	 * Set error filter.
	 *
	 * @param string $filter Error type filter.
	 * @return void
	 */
	public function set_error_filter($filter)
	{
		$this->error_filter = sanitize_text_field($filter);
	}

	/**
	 * Get log entries from debug.log file.
	 *
	 * @return array Array of log entries.
	 */
	public function get_log_entries()
	{
		$entries = array();

		if (! file_exists($this->log_file)) {
			return $entries;
		}

		// Performance optimization: Check file size and use streaming for medium/large files
		$file_size = filesize($this->log_file);
		$use_streaming = $file_size > 256 * 1024; // 256KB threshold - optimized for active logs (500+ entries)

		if ($use_streaming) {
			return $this->get_log_entries_streaming();
		}

		// Fallback to original method for smaller files to maintain exact behavior
		$log_content = file_get_contents($this->log_file);
		if (empty($log_content)) {
			return $entries;
		}

		// Split the log file by lines.
		$log_lines = preg_split('/\r\n|\r|\n/', $log_content);

		// Regular expression to match the typical WordPress debug.log format.
		$timestamp_pattern = '/^\[(\d{2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2}) UTC\]/';

		$current_entry = null;

		foreach ($log_lines as $line) {
			if (empty($line)) {
				continue;
			}

			// Check if line starts with timestamp
			if (preg_match($timestamp_pattern, $line, $matches)) {
				// This is a new entry - store the previous one if it exists
				if ($current_entry !== null) {
					// Only add if it passes the filter
					if ($this->error_filter === 'all' || stripos($current_entry['type'], $this->error_filter) !== false) {
						$entries[] = $current_entry;
					}
				}

				// Start a new entry
				$date = $matches[1];
				$type = $this->determine_error_type($line);

				$current_entry = array(
					'date'    => $date,
					'type'    => $type,
					'message' => $line,
					'lines'   => [$line] // Keep the original lines for processing
				);
			} else {
				// This is a continuation of the previous entry
				if ($current_entry !== null) {
					// Append to the message and keep the original line
					$current_entry['message'] .= "\n" . $line;
					$current_entry['lines'][] = $line;
				} else {
					// If no current entry, create one with unknown type
					$current_entry = array(
						'date'    => '',
						'type'    => 'unknown',
						'message' => $line,
						'lines'   => [$line]
					);
				}
			}
		}

		// Add the last entry if it exists
		if ($current_entry !== null) {
			if ($this->error_filter === 'all' || stripos($current_entry['type'], $this->error_filter) !== false) {
				$entries[] = $current_entry;
			}
		}

		// Sort entries.
		if ($this->sort_order === 'desc') {
			$entries = array_reverse($entries);
		}

		// Limit entries.
		if ($this->entries_limit > 0) {
			$entries = array_slice($entries, 0, $this->entries_limit);
		}

		return $entries;
	}

	/**
	 * Streaming version for large log files - optimized for performance
	 * Maintains exact same behavior as original method but processes entries on-demand
	 */
	private function get_log_entries_streaming()
	{
		$entries = array();
		$target_count = $this->entries_limit > 0 ? $this->entries_limit : PHP_INT_MAX;

		// For performance, we'll read smart - if desc order (newest first), read from end
		if ($this->sort_order === 'desc') {
			$entries = $this->read_entries_reverse($target_count);
		} else {
			$entries = $this->read_entries_forward($target_count);
		}

		return $entries;
	}

	/**
	 * Read entries from beginning of file (for asc order)
	 */
	private function read_entries_forward($target_count)
	{
		$entries = array();
		$handle = fopen($this->log_file, 'r');
		if (!$handle) {
			return $entries;
		}

		$timestamp_pattern = '/^\[(\d{2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2}) UTC\]/';
		$current_entry = null;

		while (!feof($handle) && count($entries) < $target_count) {
			$line = fgets($handle);
			if ($line === false || empty(trim($line))) {
				continue;
			}

			$line = rtrim($line, "\r\n");

			if (preg_match($timestamp_pattern, $line, $matches)) {
				// Process previous entry
				if ($current_entry !== null) {
					if ($this->error_filter === 'all' || stripos($current_entry['type'], $this->error_filter) !== false) {
						$entries[] = $current_entry;
					}
				}

				// Start new entry
				$date = $matches[1];
				$type = $this->determine_error_type($line);

				$current_entry = array(
					'date'    => $date,
					'type'    => $type,
					'message' => $line,
					'lines'   => [$line]
				);
			} else {
				// Continuation line
				if ($current_entry !== null) {
					$current_entry['message'] .= "\n" . $line;
					$current_entry['lines'][] = $line;
				} else {
					$current_entry = array(
						'date'    => '',
						'type'    => 'unknown',
						'message' => $line,
						'lines'   => [$line]
					);
				}
			}
		}

		// Process final entry
		if ($current_entry !== null && count($entries) < $target_count) {
			if ($this->error_filter === 'all' || stripos($current_entry['type'], $this->error_filter) !== false) {
				$entries[] = $current_entry;
			}
		}

		fclose($handle);
		return $entries;
	}

	/**
	 * Read entries from end of file (for desc order) - more complex but efficient
	 */
	private function read_entries_reverse($target_count)
	{
		// For reverse reading, we'll use a more sophisticated approach
		// Read file in chunks from the end and process backwards
		$entries = array();
		$file_size = filesize($this->log_file);
		$handle = fopen($this->log_file, 'r');
		if (!$handle) {
			return $entries;
		}

		$chunk_size = 8192; // 8KB chunks
		$buffer = '';
		$pos = $file_size;
		$timestamp_pattern = '/^\[(\d{2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2}) UTC\]/';

		// Read file in reverse chunks
		while ($pos > 0 && count($entries) < $target_count) {
			$read_size = min($chunk_size, $pos);
			$pos -= $read_size;

			fseek($handle, $pos);
			$chunk = fread($handle, $read_size);
			$buffer = $chunk . $buffer;

			// Split into lines and process backwards
			$lines = preg_split('/\r\n|\r|\n/', $buffer);

			// Keep first line as partial (unless we're at start of file)
			if ($pos > 0) {
				$buffer = array_shift($lines);
			} else {
				$buffer = '';
			}

			// Process lines in reverse order
			$lines = array_reverse($lines);

			foreach ($lines as $line) {
				if (empty(trim($line))) {
					continue;
				}

				if (preg_match($timestamp_pattern, $line, $matches)) {
					// Found start of an entry - finalize current entry if exists
					if ($current_entry !== null) {
						if ($this->error_filter === 'all' || stripos($current_entry['type'], $this->error_filter) !== false) {
							$entries[] = $current_entry;
							if (count($entries) >= $target_count) {
								break 2; // Break out of both loops
							}
						}
					}

					// Start new entry (but we're going backwards, so this is actually the end)
					$date = isset($matches[1]) ? $matches[1] : '';
					$type = $this->determine_error_type($line);

					$current_entry = array(
						'date'    => $date,
						'type'    => $type,
						'message' => $line,
						'lines'   => [$line]
					);
				} else {
					// This is a continuation line (prepend since we're going backwards)
					if ($current_entry !== null) {
						$current_entry['message'] = $line . "\n" . $current_entry['message'];
						array_unshift($current_entry['lines'], $line);
					}
				}
			}
		}

		// Add final entry if exists
		if ($current_entry !== null && count($entries) < $target_count) {
			if ($this->error_filter === 'all' || stripos($current_entry['type'], $this->error_filter) !== false) {
				$entries[] = $current_entry;
			}
		}

		fclose($handle);

		// Since we collected in reverse order, reverse the array to get newest first
		return array_reverse($entries);
	}

	/**
	 * Determine the error type from a log message.
	 *
	 * @param string $message Log message.
	 * @return string Error type.
	 */
	private function determine_error_type($message)
	{
		// Check for FRL logs first (takes priority)
		if (str_contains($message, strtoupper(FRL_PREFIX) . '_LOG:')) {
			return FRL_PREFIX;
		}

		$message = strtolower($message);

		if (str_contains($message, 'fatal error')) {
			return 'fatal';
		} elseif (str_contains($message, 'parse error')) {
			return 'parse';
		} elseif (str_contains($message, 'warning')) {
			return 'warning';
		} elseif (str_contains($message, 'notice')) {
			return 'notice';
		} elseif (str_contains($message, 'deprecated')) {
			return 'deprecated';
		} elseif (str_contains($message, 'error')) {
			return 'error';
		}

		return 'info';
	}

	/**
	 * Clear debug.log file.
	 *
	 * @return void
	 */
	public function clear_debug_log()
	{
		check_ajax_referer('log_manager_nonce', 'nonce');

		if (file_exists($this->log_file)) {
			file_put_contents($this->log_file, '');
		}

		// Clear the count transient when log is cleared
		frl_delete_transient('debug_log_count');

		// Also clear any navigation cache that might store the count
		$user_id = frl_get_current_user()->ID;
		$lang = frl_get_language();
		$cache_key = "{$lang}_adminbar_uid{$user_id}";
		frl_cache_clear('admin', $cache_key);

		// Generate a new nonce for future requests
		$new_nonce = wp_create_nonce('log_manager_nonce');

		wp_send_json_success(array(
			'message' => 'Debug log cleared successfully.',
			'new_nonce' => $new_nonce
		));
	}

	/**
	 * Download debug.log file.
	 *
	 * @return void
	 */
	public function download_debug_log()
	{
		check_ajax_referer('log_manager_nonce', 'nonce');

		if (! file_exists($this->log_file)) {
			wp_send_json_error(array('message' => 'Debug log file not found.'));
			return;
		}

		$log_content = file_get_contents($this->log_file);

		// Generate a new nonce for future requests
		$new_nonce = wp_create_nonce('log_manager_nonce');

		wp_send_json_success(array(
			'content' => $log_content,
			'new_nonce' => $new_nonce
		));
	}

	/**
	 * Ajax handler for refreshing log entries.
	 *
	 * @return void
	 */
	public function ajax_get_log_entries()
	{
		check_ajax_referer('log_manager_nonce', 'nonce');

		if (isset($_POST['limit'])) {
			$this->set_entries_limit(intval($_POST['limit']));
		}

		// Validate against whitelist instead of just sanitizing
		$allowed_orders = ['asc', 'desc'];
		if (isset($_POST['order']) && in_array($_POST['order'], $allowed_orders, true)) {
			$this->set_sort_order($_POST['order']);
		}

		if (isset($_POST['filter'])) {
			$this->set_error_filter(sanitize_text_field($_POST['filter']));
		}

		$entries = $this->get_log_entries();
		$new_count = count($entries);

		frl_delete_transient('debug_log_count');
		frl_set_transient('debug_log_count', $new_count, 5 * MINUTE_IN_SECONDS);

		$total_count = $this->get_log_entry_count(true);
		$html = $this->render_table_rows($entries);
		$count_html = '';
		if ($total_count > 0) {
			$count_html = '<span class="log-count-bubble">' . number_format_i18n($total_count) . '</span>';
		}

		$new_nonce = wp_create_nonce('log_manager_nonce');

		wp_send_json_success(array(
			'html' => $html,
			'count' => $total_count,
			'count_html' => $count_html,
			'new_nonce' => $new_nonce
		));
	}

	/**
	 * Get count of log entries
	 *
	 * @param bool $force_recount Whether to force a recount even if the transient exists
	 * @return int Number of log entries
	 */
	public function get_log_entry_count($force_recount = false)
	{
		// Try to get count from transient first for performance
		$count = false;
		if (!$force_recount) {
			$count = frl_get_transient('debug_log_count');
		}

		// If transient doesn't exist or force recount is true, count entries
		if ($count === false) {
			$count = 0;

			if (file_exists($this->log_file)) {
				// Regular expression to match the timestamp pattern that starts a new entry
				$timestamp_pattern = '/^\[\d{2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2} UTC\]/';

				// Faster than loading entire file into memory
				$handle = fopen($this->log_file, 'r');
				if ($handle) {
					while (!feof($handle)) {
						$line = fgets($handle);
						// Only count lines that start with a timestamp (i.e., new entries)
						if (!empty(trim($line)) && preg_match($timestamp_pattern, $line)) {
							$count++;
						}
					}
					fclose($handle);
				}
			}

			// Cache for 5 minutes
			frl_set_transient('debug_log_count', $count, 5 * MINUTE_IN_SECONDS);
		}

		return (int)$count;
	}

	/**
	 * Renders table rows for log entries.
	 *
	 * @param array $entries Array of log entries.
	 * @return string The rendered table rows HTML.
	 */
	private function render_table_rows($entries): string
	{
		$rows = '';

		if (empty($entries)) {
			// For empty entries, create a "no entries" row
			return frl_ui_render_table_row('No log entries found.', '', false, 'no-entries');
		}

		foreach ($entries as $entry) {
			$type_class = 'log-row-' . sanitize_html_class($entry['type']);
			$message = $entry['message'];
			$file_info = '';
			$line_number = '';

			// Extract file path and line number from any of the lines
			$path_matches = [];
			// Updated regex pattern to better handle WordPress-specific error formats and only capture the actual file path
			if (preg_match('/\s+in\s+([\/\\\\][^\s\)]+|[A-Z]:[\/\\\\][^\s\)]+)\s+(?:on\s+line\s+(\d+))?/i', $message, $path_matches)) {
				$full_path = $path_matches[1];
				$line_number = $path_matches[2] ?? '';

				// Get the path relative to WordPress root
				$wp_root_path = ABSPATH;
				$relative_path = str_replace('\\', '/', $full_path); // Normalize path separators
				$wp_root_path = str_replace('\\', '/', $wp_root_path); // Normalize path separators

				if (str_starts_with($relative_path, $wp_root_path)) {
					// Path is within WordPress directory - show relative path with leading slash
					$relative_path = '/' . ltrim(substr($relative_path, strlen($wp_root_path)), '/');
				}

				// Create the file info display
				$file_info = '<div class="error-location">';
				$file_info .= '<div class="error-file">File: <span class="error-file-name">' . esc_html($relative_path) . '</span></div>';
				$file_info .= '<div class="error-line">Line: <span class="error-line-number">' . esc_html($line_number) . '</span></div>';
				$file_info .= '</div>';
			}

			// Clean up the first line by removing the timestamp and error type prefix
			$first_line = '';
			$remaining_content = $message;

			// Only process timestamp if we have a date
			if (!empty($entry['date'])) {
				// Split the message into lines
				$message_lines = explode("\n", $message);
				$first_line = array_shift($message_lines);

				// Remove timestamp pattern like [20-Mar-2025 17:13:38 UTC] from first line
				$first_line = preg_replace('/^\[\d{2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2} UTC\]\s*/', '', $first_line);

				// Remove PHP error type like "PHP Warning: ", "PHP Notice: ", etc. from first line
				$first_line = preg_replace('/^PHP\s+(Warning|Notice|Deprecated|Parse error|Fatal error|Error):\s*/i', '', $first_line);

				// The remaining content is the rest of the message
				$remaining_content = implode("\n", $message_lines);
			} else {
				// If no date, just use the whole message
				$first_line = $message;
				$remaining_content = '';
			}

		// First column: Type and date
		$type_label = ($entry['type'] === FRL_PREFIX) ? strtoupper(FRL_PREFIX) . ' Message' : ucfirst($entry['type']);
		$meta_content = '<span class="log-type">' . esc_html($type_label) . '</span>';
			if (!empty($entry['date'])) {
				// Format the date
				$date_parts = explode(' ', $entry['date']);
				$log_date = $date_parts[0]; // Date part (DD-MMM-YYYY)
				$log_time = isset($date_parts[1]) ? $date_parts[1] : ''; // Time part (HH:MM:SS)

				// Parse the date
				$timestamp = strtotime($entry['date']);
				$today = strtotime(date('Y-m-d')); // Midnight today

				// Format date based on whether it's today or not
				if ($timestamp >= $today) {
					// It's today
					$formatted_date = '<span class="log-day">Today</span> - ' . esc_html(date('H:i:s', $timestamp));
				} else {
					// It's another day - use full day name (l) instead of abbreviated (D)
					$day_name = date('l', $timestamp);
					$formatted_date = '<span class="log-day">' . esc_html($day_name) . '</span>, ' . esc_html(date('F j, Y', $timestamp)) . ' - ' . esc_html(date('H:i:s', $timestamp));
				}

				$meta_content .= '<span class="log-date">' . $formatted_date . '</span>';
			}

			// Second column: Message and file info
			// Create a formatted message with preserved whitespace
			$formatted_message = '<div class="message-content">';

			// Add the first line
			$formatted_message .= '<div class="message-line">' . esc_html($first_line) . '</div>';

			// If we have remaining content, add it as preformatted text to preserve formatting
			if (!empty($remaining_content)) {
				$formatted_message .= '<pre class="message-details">' . esc_html($remaining_content) . '</pre>';
			}

			// Add file info if we have it
			$formatted_message .= $file_info . '</div>';

			// Add copy button
			$formatted_message .= '<button class="copy-row" data-message="' . esc_attr($entry['message']) . '" title="Copy message"><span class="dashicons dashicons-clipboard"></span></button>';

			// Use the row class on the widget-table-row div
			$row_class = 'widget-table-row ' . $type_class;

			$rows .= '<div class="' . $row_class . '">';
			$rows .= '<div class="widget-table-cell-name">' . $meta_content . '</div>';
			$rows .= '<div class="widget-table-cell-value">' . $formatted_message . '</div>';
			$rows .= '</div>';
		}

		return $rows;
	}

	/**
	 * Render the log manager interface.
	 *
	 * @return string HTML output of the log manager interface.
	 */
	public function render()
	{
		// Assets are now handled in ui-scripts.php

		// Process form submissions.
		if (isset($_POST['log_manager_nonce']) && wp_verify_nonce($_POST['log_manager_nonce'], 'log_manager_settings')) {
			if (isset($_POST['entries_limit'])) {
				$this->set_entries_limit(intval($_POST['entries_limit']));
			}

			if (isset($_POST['sort_order'])) {
				$this->set_sort_order(sanitize_text_field($_POST['sort_order']));
			}

			if (isset($_POST['error_filter'])) {
				$this->set_error_filter(sanitize_text_field($_POST['filter']));
			}
		}

		// Get log entries.
		$entries = $this->get_log_entries();

		// Use output buffering to capture the HTML content
		ob_start();
?>
		<div class="wrap log-manager-wrap">
			<h2 class="section-title log-manager-title">
				Debug Log Viewer
				<?php
				$log_count = $this->get_log_entry_count(true);
				if ($log_count > 0) : ?>
					<span class="log-count-bubble"><?php echo number_format_i18n($log_count); ?></span>
				<?php endif; ?>
			</h2>

			<div class="log-manager-controls">
				<div class="controls-container">
					<div class="log-filter-controls">
						<div class="control-row">
							<div class="control-group">
								<label for="entries_limit">Entries</label>
								<select name="entries_limit" id="entries_limit">
									<option value="10" <?php selected($this->entries_limit, 10); ?>>10</option>
									<option value="20" <?php selected($this->entries_limit, 20); ?>>20</option>
									<option value="50" <?php selected($this->entries_limit, 50); ?>>50</option>
									<option value="100" <?php selected($this->entries_limit, 100); ?>>100</option>
									<option value="0" <?php selected($this->entries_limit, 0); ?>>All</option>
								</select>
							</div>

							<div class="control-group">
								<label for="sort_order">Order</label>
								<select name="sort_order" id="sort_order">
									<option value="desc" <?php selected($this->sort_order, 'desc'); ?>>Newest first</option>
									<option value="asc" <?php selected($this->sort_order, 'asc'); ?>>Oldest first</option>
								</select>
							</div>

							<div class="control-group">
								<label for="error_filter">Type</label>
								<select name="error_filter" id="error_filter">
									<option value="all" <?php selected($this->error_filter, 'all'); ?>>All logs</option>
									<option value="<?php echo FRL_PREFIX; ?>" <?php selected($this->error_filter, FRL_PREFIX); ?>><?php echo frl_name(); ?> Logs</option>
									<option value="error" <?php selected($this->error_filter, 'error'); ?>>Error</option>
									<option value="fatal" <?php selected($this->error_filter, 'fatal'); ?>>Fatal Error</option>
									<option value="parse" <?php selected($this->error_filter, 'parse'); ?>>Parse Error</option>
									<option value="warning" <?php selected($this->error_filter, 'warning'); ?>>Warning</option>
									<option value="notice" <?php selected($this->error_filter, 'notice'); ?>>Notice</option>
									<option value="deprecated" <?php selected($this->error_filter, 'deprecated'); ?>>Deprecated</option>
									<option value="info" <?php selected($this->error_filter, 'info'); ?>>Info</option>
								</select>
							</div>

							<div class="control-group">
								<button id="apply-filters" class="button button-secondary">Apply</button>
							</div>
						</div>
					</div>

					<div class="action-buttons">
						<button id="refresh-log" class="button">
							<span class="dashicons dashicons-update"></span> Refresh
						</button>
						<button id="copy-all" class="button" data-count="<?php echo count($entries); ?>">
							<span class="dashicons dashicons-clipboard"></span> Copy All
						</button>
						<button id="download-log" class="button">
							<span class="dashicons dashicons-download"></span> Download
						</button>
						<button id="clear-log" class="button button-secondary">
							<span class="dashicons dashicons-trash"></span> Clear Log
						</button>
					</div>
				</div>
			</div>

			<?php
			// Generate header row using UI Renderer
			$table_content = frl_ui_render_table_row(
				'Error Info',
				'Details',
				true,
				'log-header-row'
			);

			// Now add the log entries section with ID for AJAX compatibility
			$table_content .= '<div id="log-entries">' . $this->render_table_rows($entries) . '</div>';

			// Render the complete div-based table
			echo frl_ui_render_table(
				'log-entries-table',
				$table_content,
				'log-table',
				HOUR_IN_SECONDS,
				true
			);
			?>
		</div>
<?php

		// Return the captured HTML
		return ob_get_clean();
	}
}
