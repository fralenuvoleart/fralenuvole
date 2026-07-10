<?php
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin UI Utilities
 *
 * Shared helper functions for admin UI components.
 *
 * @package Fralenuvole
 * @since 1.0.0
 */

/**
 * Clear the dashboard cache and add an admin notice.
 *
 * @return void
 */
function frl_clear_dashboard() {
	frl_cache_clear( 'staticdata', 'all_transients' );
	$stats = frl_cache_clear( 'adminui' );

	// Check if stats is an array
	if ( is_array( $stats ) ) {
		// Determine the type of persistent storage used
		$persistent_label = wp_using_ext_object_cache() ? __( 'object cache items', FRL_PREFIX ) : __( 'transients', FRL_PREFIX );

		// Get counts, providing defaults if missing
		$runtime_count    = $stats['runtime'] ?? 0;
		$persistent_count = $stats['persistent'] ?? 0;

		// Format the success message
		$message = sprintf(
			__( 'Admin UI Cache cleared: %1$d runtime items, %2$d persistent %3$s. <br>all_transients cleared from staticdata cache.', FRL_PREFIX ),
			$runtime_count,
			$persistent_count,
			$persistent_label
		);
		frl_add_admin_notice( $message, 'success' );
	} else {
		// Format the fallback message
		$message = __( 'Admin UI Cache cleared, but statistics are unavailable.', FRL_PREFIX );
		frl_add_admin_notice( $message, 'warning' );
	}
}

/**
 * Render action button
 * @param string $action Action name
 * @param string $class_name CSS classes
 * @param string $label Button label
 * @param string $description Button description
 * @param string $url Button URL
 * @param string $cap Required capability
 * @return string HTML button
 */
function frl_render_action_button(
	$action,
	$class_name = '',
	$label = '',
	$description = '',
	$url = '',
	$cap = 'manage_options',
) {
	// If cap is 'skip_nonce', we assume it's for logged-in users (basic check)
	// Otherwise check the specific capability
	if ( $cap !== 'skip_nonce' && ! frl_has_access( $cap ) ) {
		return '';
	}
	// If 'skip_nonce', verify at least logged in status to render
	if ( $cap === 'skip_nonce' && ! is_user_logged_in() ) {
		return '';
	}

	$button_class  = 'button ';
	$button_class .= $class_name ?? 'button-small';

	$label = empty( $label ) ? ucwords( str_replace( '_', ' ', $action ) ) : $label;
	$url   = empty( $url ) ? FRL_PLUGIN_ADMIN_URL : $url;

	// Build query args
	$query_args = array(
		frl_prefix( 'action' ) => $action,
	);

	// Only add nonce if capability is not explicitly set to 'skip_nonce'
	// 'skip_nonce' is used for actions that need to bypass nonce verification and if user is logged in (for caching compatibility)
	if ( $cap !== 'skip_nonce' ) {
		$query_args[ frl_prefix( $action ) . '_nonce' ] = frl_create_nonce( $action );
	}

	// Add a row with action buttons to the statistics table
	$button_url = add_query_arg( $query_args, $url );

	// Add confirmation for primary buttons
	$onclick_attr = '';
	if ( str_contains( $class_name, 'button-primary' ) ) {
		$confirmation_message = sprintf(
			__( 'Are you sure you want to %s? This action cannot be undone.', FRL_PREFIX ),
			strtolower( $label )
		);
		$onclick_attr         = ' onclick="return confirm(\'' . esc_js( $confirmation_message ) . '\')"';
	}

	$button = '<span class="button-container">
        <a title="' . $description . '" href="' . esc_url( $button_url ) . '" class="' . $button_class . '"' . $onclick_attr . '>' . $label . '</a>
        <span class="button-description">' . $description . '</span>
        </span>';

	return $button;
}

/**
 * Render the clear cache buttons
 * Used as a callback for the developer tab action hook.
 * @return string HTML for the clear cache buttons
 */
function frl_render_clear_cache_buttons() {
	$output = '';
	foreach ( FRL_CACHE_PERSISTENT_GROUPS as $group ) {
		$output .= frl_render_action_button(
			'clear_cache_' . $group,
			'button-secondary',
			'Clear ' . ucwords( $group )
		);
	}

	$output .= frl_render_action_button(
		'clear_cache_light',
		'clear_cache_light button-secondary',
		'Clear Caches (Light)',
		'Clear all plugin caches except Heavy Groups'
	);

	$output .= frl_render_action_button(
		'clear_cache_all',
		'clear_cache_all button-secondary secondary-important',
		'Clear Caches (All)',
		'Clear all plugin caches including Heavy Groups: ' . implode( ', ', array_map( 'ucfirst', FRL_CACHE_HEAVY_GROUPS ) ),
		'',
		''
	);

	return frl_ui_render_widget( 'cache-clear-buttons', $output, 'Clear Cache Groups', 'widget-admin-actions' );
}

/**
 * Render the admin actions buttons
 * @return string HTML for the admin actions buttons
 */
function frl_render_admin_actions_buttons() {
	$output = '';

	// Plugin Admin only (delete_plugins via empty string)
	$output .= frl_render_action_button(
		'clear_cache_hard',
		'reset_plugin button-primary',
		'Clear Caches (Hard)',
		'Clears all Plugin Caches, Plugin Transients, and flushes rewrite rules.',
		'',
		''
	);

	$output .= frl_render_action_button(
		'reset_plugin',
		'reset_plugin button-primary',
		'Reset Plugin',
		'WARNING: Resets all plugin settings to their default values.',
		'',
		''
	);

	$output .= frl_render_action_button(
		'reset_debug_config',
		'reset_debug_config button-secondary',
		'Sync wp-config.php',
		'Writes WP_DEBUG config constants to wp-config.php.',
		'',
		''
	);

	$output .= frl_render_action_button(
		'flush_rewrite_rules',
		'flush_rewrite_rules button-secondary',
		'Flush Rewrite Rules',
		'Resets rewrite rules, plugin and thirdparty caches.'
	);

	$output .= frl_render_action_button(
		'reset_environment',
		'reset_environment button-secondary',
		'Reset Environment',
		'Resets current environment to its default configuration'
	);

	$output .= frl_render_action_button(
		'reset_environment_ignored',
		'reset_environment_ignored button-secondary',
		'Reset Ignored Plugins',
		'Resets manually ignored plugins.'
	);

	$output .= frl_render_action_button(
		'delete_orphan_options',
		'delete_orphan_options button-secondary',
		'Delete Orphan Options',
		'Delete orphaned options from the database'
	);

	$output .= frl_render_action_button(
		'clear_plugin_transients',
		'clear_plugin_transients button-secondary',
		'Clear Plugin Transients',
		'Delete all ' . frl_name() . ' plugin options from the database'
	);

	$output .= frl_render_action_button(
		'clear_website_transients',
		'clear_website_transients button-secondary',
		'Clear Website Transients',
		'Clear all Website Transients from the database'
	);

	return frl_ui_render_widget( 'admin-action-buttons', $output, 'Reset Actions', 'widget-admin-actions' );
}

/**
 * Renders a generic UI component that lists items with a "Copy" button.
 *
 * This is a reusable helper to create informational widgets for the settings page,
 * allowing users to easily copy slugs or other values into textarea fields.
 *
 * @param string $id A unique ID for the main container element.
 * @param string $description A short text to display above the list.
 * @param array $items An associative array where keys are the display labels and values are the text to be copied.
 * @return string The generated HTML for the copyable list.
 */
function frl_render_copyable_list_ui( $id, $description, $items ) {
	if ( empty( $items ) ) {
		return sprintf( '<p><em>%s</em></p>', __( 'No items found.', FRL_PREFIX ) );
	}

	// Use a cache key based on the ID and a hash of the items to prevent unnecessary regeneration.
	$items_hash = substr( md5( serialize( $items ) ), 0, 8 );
	$cache_key  = "copyable_list_{$id}_{$items_hash}";

	return frl_cache_remember(
		'adminui',
		$cache_key,
		function () use ( $id, $description, $items ) {
			$output  = sprintf( '<div class="frl-admin-menu-info" id="frl-copy-ui-%s">', esc_attr( $id ) );
			$output .= sprintf( '<p>%s</p>', esc_html( $description ) );
			$output .= '<div class="frl-menu-slug-list">';

			$output .= '<div class="frl-menu-item-row row-title">
			<span>Name</span>
			<span></span>
			<span>Slug</span>
			<span></span>
		</div>';

			foreach ( $items as $label => $slug ) {
				$output .= sprintf(
					'<div class="frl-menu-item-row">
					<span>%s</span>
					<span></span>
					<span><input type="text" value="%s" readonly="readonly" class="frl-slug-input" /></span>
					<span><button type="button" class="button button-small frl-copy-single-item" data-copy-text="%s">Copy</button></span>
				</div>',
					esc_html( $label ),
					esc_attr( $slug ),
					esc_attr( $slug )
				);
			}

			$output .= '</div>';
			ob_start();
			?>
		<script>
			document.addEventListener('DOMContentLoaded', function() {
				const container = document.getElementById('frl-copy-ui-<?php echo esc_js( $id ); ?>');
				if (!container) return;

				container.addEventListener('click', function(e) {
					if (e.target.classList.contains('frl-copy-single-item')) {
						const textToCopy = e.target.dataset.copyText;
						navigator.clipboard.writeText(textToCopy).then(() => {
							const originalText = e.target.textContent;
							e.target.textContent = 'Copied!';
							setTimeout(() => { e.target.textContent = originalText; }, 1500);
						});
					}
				});
			});
		</script>
			<?php
			$output .= ob_get_clean();
			$output .= '</div>';

			return $output;
		}
	);
}

/**
 * Renders a helper UI that lists all public taxonomies.
 *
 * @see frl_render_copyable_list_ui()
 * @return string The HTML for the helper UI.
 */
function frl_render_rewrite_taxonomies() {
	$taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );
	$items      = array();
	if ( ! empty( $taxonomies ) ) {
		foreach ( $taxonomies as $slug => $tax ) {
			$items[ $tax->label ] = $slug;
		}
	}
	return frl_render_copyable_list_ui(
		'taxonomies',
		__( 'Click an item to copy its slug.', FRL_PREFIX ),
		$items
	);
}

/**
 * Renders a helper UI that lists all public Custom Post Types.
 *
 * @see frl_render_copyable_list_ui()
 * @return string The HTML for the helper UI.
 */
function frl_render_rewrite_cpts() {
	$post_types = get_post_types( array( 'public' => true ), 'objects' );
	$items      = array();
	if ( ! empty( $post_types ) ) {
		foreach ( $post_types as $slug => $cpt ) {
			// Skip built-in types such as post, page, attachment, etc.
			if ( ! empty( $cpt->_builtin ) ) {
				continue;
			}
			$items[ $cpt->label ] = $slug;
		}
	}
	return frl_render_copyable_list_ui(
		'cpts',
		__( 'Click an item to copy its slug.', FRL_PREFIX ),
		$items
	);
}

/**
 * Renders the checkbox UI for enabling multilingual CPT slugs.
 *
 * This function gets the available CPTs from Frl_Rewriter and compares them
 * against the currently saved options to render a dynamic list of checkboxes.
 *
 * @return string The HTML for the checkbox fieldset.
 */
function frl_render_rewrite_multilingual_cpts() {
	$configurable_cpts = defined( 'FRL_REWRITER_MULTILINGUAL_CPT' ) ? FRL_REWRITER_MULTILINGUAL_CPT : array();
	$configurable_cpts = array_filter( $configurable_cpts, 'post_type_exists' );

	if ( empty( $configurable_cpts ) ) {
		return sprintf( '<p><em>%s</em></p>', __( 'No CPTs available for multilingual slugs.', FRL_PREFIX ) );
	}

	$output = '';
	foreach ( $configurable_cpts as $cpt_slug ) {
		$cpt_object = get_post_type_object( $cpt_slug );
		$cpt_name   = $cpt_object ? $cpt_object->labels->singular_name : $cpt_slug;

		$field_key  = 'translate_cpt_slugs_' . esc_attr( $cpt_slug );
		$field_name = frl_prefix( $field_key ); // direct option field
		$field_id   = esc_attr( frl_prefix( $field_key ) );
		$value      = frl_get_option( $field_key );
		if ( $value === null ) {
			$value = '';
		}

		$output .= '<div style="margin-bottom:1em">';
		$output .= sprintf( '<label for="%s"><strong>%s</strong></label><br>', $field_id, esc_html( $cpt_name ) );
		$output .= sprintf(
			'<textarea id="%s" name="%s" rows="3" cols="50" class="textlist" placeholder="%s">%s</textarea>',
			$field_id,
			$field_name,
			'en|' . $cpt_slug,
			esc_textarea( $value )
		);
		$output .= '</div>';
	}
	return $output;
}

/**
 * Get all plugin transients directly from database or cache
 * as they exist in the wp_options table (or cached by Frl_Cache_Manager)
 *
 * @return array Raw transient data (array of objects from wp_options results) or empty array on failure.
 */
function frl_get_all_plugin_transients() {
	// Use frl_cache_remember to cache the combined results of a single query
	$all_transients = frl_cache_remember(
		'staticdata',
		'all_transients',
		function () {
			global $wpdb;

			$t0 = microtime( true );

			$groups_to_query = array_diff( FRL_CACHE_PERSISTENT_GROUPS, array( 'transients' ) );

			// Single consolidated query replaces 16 individual per-group + base queries.
			// Limit: (15 groups + 1 base slot) × FRL_CACHE_MAX_TRANSIENTS_PER_GROUP.
			$limit = ( ( count( $groups_to_query ) + 1 ) * FRL_CACHE_MAX_TRANSIENTS_PER_GROUP );

			$prefix      = frl_prefix();
			$t_pattern   = $prefix . '%';
			$to_pattern  = $prefix . '%';
			$st_pattern  = $prefix . '%';
			$sto_pattern = $prefix . '%';

			$query = $wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options}
			          WHERE option_name LIKE %s OR option_name LIKE %s
			             OR option_name LIKE %s OR option_name LIKE %s
			          ORDER BY option_name
			          LIMIT %d",
				'_transient_' . $t_pattern,
				'_transient_timeout_' . $to_pattern,
				'_site_transient_' . $st_pattern,
				'_site_transient_timeout_' . $sto_pattern,
				$limit
			);

			$t1          = microtime( true );
			$all_results = $wpdb->get_results( $query );
			$t2          = microtime( true );
			$query_ms    = round( ( $t2 - $t1 ) * 1000, 1 );

			if ( empty( $all_results ) ) {
				frl_log( 'get_all_plugin_transients: query={query_ms}ms rows=0', array( 'query_ms' => $query_ms ) );
				return array();
			}

			// Deduplicate by option_name (single query naturally avoids duplicates,
			// but keep for safety).
			$unique = array();
			foreach ( $all_results as $result ) {
				$unique[ $result->option_name ] = $result;
			}

			// Sort alphabetically for consistent display.
			$all_results = array_values( $unique );
			usort(
				$all_results,
				function ( $a, $b ) {
					return strcmp( $a->option_name, $b->option_name );
				}
			);

			$t3        = microtime( true );
			$php_ms    = round( ( $t3 - $t2 ) * 1000, 1 );
			$total_ms  = round( ( $t3 - $t0 ) * 1000, 1 );
			$row_count = count( $all_results );
			frl_log(
				'get_all_plugin_transients: query={query_ms}ms php={php_ms}ms total={total_ms}ms rows={row_count}',
				array(
					'query_ms'  => $query_ms,
					'php_ms'    => $php_ms,
					'total_ms'  => $total_ms,
					'row_count' => $row_count,
				)
			);

			return $all_results;
		}
	);

	return $all_transients;
}


/**
 * Get status information about mu-plugins
 *
 * @return array Status information including managed, orphaned, missing, and out-of-sync files
 */
function frl_get_mu_plugins_status() {
	$template_dir  = FRL_DIR_PATH . 'assets/mu/';
	$mu_plugin_dir = WPMU_PLUGIN_DIR;

	$status = array(
		'managed'         => array(),      // Files that exist in both places and are in sync
		'missing'         => array(),      // Files in templates but not in mu-plugins
		'orphaned'        => array(),     // Files in mu-plugins but not in templates
		'out_of_sync'     => array(),  // Files that exist but content differs
		'total_expected'  => 0,
		'total_installed' => 0,
		'needs_sync'      => false, // Overall sync status indicator
	);

	// Get template files
	$template_files = glob( $template_dir . '*.php' );
	$expected_files = array();

	foreach ( $template_files as $template_file ) {
		$filename         = basename( $template_file );
		$expected_files[] = $filename;
		$mu_plugin_file   = $mu_plugin_dir . '/' . $filename;

		if ( file_exists( $mu_plugin_file ) ) {
			// Fast comparison: check file size first, then hash if sizes match
			if ( filesize( $template_file ) !== filesize( $mu_plugin_file ) ) {
				$status['out_of_sync'][] = $filename;
				$status['needs_sync']    = true;
			} else {
				// Sizes match, compare content hash
				$template_hash  = hash_file( 'md5', $template_file );
				$mu_plugin_hash = hash_file( 'md5', $mu_plugin_file );

				if ( $template_hash === $mu_plugin_hash ) {
					$status['managed'][] = $filename;
				} else {
					$status['out_of_sync'][] = $filename;
					$status['needs_sync']    = true;
				}
			}
		} else {
			$status['missing'][]  = $filename;
			$status['needs_sync'] = true;
		}
	}

	// Check for orphaned files
	$existing_mu_files = glob( $mu_plugin_dir . '/frl-*.php' );
	foreach ( $existing_mu_files as $existing_file ) {
		$filename = basename( $existing_file );
		if ( ! in_array( $filename, $expected_files, true ) ) {
			$status['orphaned'][] = $filename;
			$status['needs_sync'] = true;
		}
	}

	$status['total_expected']  = count( $expected_files );
	$status['total_installed'] = count( $status['managed'] ) + count( $status['out_of_sync'] ) + count( $status['orphaned'] );

	return $status;
}

/**
 * Apply debug settings
 * @param array|null $updated_options Updated options
 * @param bool $show_no_changes_notice Show notice if no changes
 */
function frl_apply_debug_settings( $updated_options = null, $show_no_changes_notice = false ) {
	// Define debug keys to check
	$debug_keys = array(
		'debug',
		'debug_log',
		'debug_display',
	);

	// If null is passed, create an array with all debug keys to force update
	if ( $updated_options === null ) {
		$updated_options = array();
		foreach ( $debug_keys as $key ) {
			$updated_options[ $key ] = true; // Mark all keys as "updated"
		}
	}

	// Check if any debug settings were updated
	$debug_updated = false;
	foreach ( $debug_keys as $key ) {
		if ( isset( $updated_options[ $key ] ) ) {
			$debug_updated = true;
			break;
		}
	}

	// Only update wp-config if debug settings were changed
	if ( $debug_updated ) {
		$notice_parts   = array();
		$overall_status = 'success'; // Assume success initially
		$changes_made   = false;

		// Get current debug settings - only core WordPress constants
		$debug_options = array(
			'debug'         => frl_get_option( 'debug', true ),
			'debug_log'     => frl_get_option( 'debug_log', true ),
			'debug_display' => frl_get_option( 'debug_display', true ),
		);

		// Update debug constants in wp-config.php - directly call the specific function
		$constants_result = frl_update_wp_config_file( $debug_options );

		// Add notice about wp-config update result
		if ( is_wp_error( $constants_result ) ) {
			if ( $constants_result->get_error_code() !== 'no_changes' ) {
				$notice_parts[] = sprintf(
					'<strong>%s</strong>: %s',
					'wp-config.php',
					esc_html( $constants_result->get_error_message() )
				);
				$overall_status = 'error'; // Set status to error
			} elseif ( $show_no_changes_notice ) {
				$notice_parts[] = sprintf(
					'<strong>%s</strong>: %s',
					'wp-config.php',
					__( 'No changes were needed, settings are already applied', FRL_PREFIX )
				);
				if ( $overall_status !== 'error' ) { // @phpstan-ignore-line notEqual.alwaysTrue
					$overall_status = 'info';
				}
			}
		} else {
			$notice_parts[] = sprintf(
				'<strong>%s</strong>: %s',
				'wp-config.php',
				__( 'Debug settings applied successfully', FRL_PREFIX )
			);
			$changes_made   = true;
		}

		// Note: Error reporting levels (notice/warning/deprecated) are handled
		// dynamically by the error handler - no wp-config.php writing needed

		// Add the consolidated notice if there are parts to show
		if ( ! empty( $notice_parts ) ) {
			// Add title
			array_unshift( $notice_parts, '<strong>' . __( 'Debug Settings', FRL_PREFIX ) . '</strong>' );

			// If no errors occurred and no changes were made, status is info
			if ( $overall_status === 'success' && ! $changes_made ) {
				$overall_status = 'info';
			}

			// Add the single, consolidated notice
			frl_add_admin_notice( implode( '<br>', $notice_parts ), $overall_status );
		}
	}
}

/**
 * Safely modify WordPress debug constants in wp-config.php
 *
 * @param array $options {
 * Debug configuration options
 *
 *  @type bool|null $debug Enable/disable WP_DEBUG, null to leave unchanged
 * @type bool|null $debug_log Enable/disable WP_DEBUG_LOG, null to leave unchanged
 * @type bool|null $debug_display Enable/disable WP_DEBUG_DISPLAY, null to leave unchanged
 * @type bool $force_backup  Whether to force backup creation even if one exists
 *
 * @return bool|WP_Error True on success, WP_Error on failure
 */
function frl_update_wp_config_file( $options = array() ) {
	// Default options
	$default_options = array(
		'debug'         => null,
		'debug_log'     => null,
		'debug_display' => null,
		'force_backup'  => true, // Always create a backup, even if one exists
	);

	// Merge with user options
	$options = wp_parse_args( $options, $default_options );

	// File paths
	$config_path = ABSPATH . 'wp-config.php';
	$temp_path   = ABSPATH . 'wp-config.php.temp';
	$backup_path = ABSPATH . 'wp-config.php.' . FRL_PREFIX;

	// Ensure wp-config.php exists and is readable
	if ( ! file_exists( $config_path ) || ! is_readable( $config_path ) ) {
		return frl_error( 'config_read', 'Cannot read wp-config.php' );
	}

	// Check if we have write permissions
	if ( ! is_writable( ABSPATH ) || ! is_writable( $config_path ) ) {
		return frl_error( 'write_permission', 'Insufficient permissions to modify wp-config.php' );
	}

	// Create backup - overwrite if exists when force_backup is true
	if ( ! file_exists( $backup_path ) || $options['force_backup'] ) {
		if ( ! copy( $config_path, $backup_path ) ) {
			return frl_error( 'backup_failed', 'Could not create backup of wp-config.php' );
		}
	}

	// Read wp-config.php
	$config_content = file_get_contents( $config_path );
	if ( $config_content === false ) {
		return frl_error( 'read_failed', 'Failed to read wp-config.php' );
	}

	// Store original content to check if changes were made
	$original_content = $config_content;

	// Modify WP_DEBUG if requested
	if ( $options['debug'] !== null ) {
		$debug_value = $options['debug'] ? 'true' : 'false';

		// Check if WP_DEBUG already exists
		if ( preg_match( '/define\s*\(\s*[\'"]WP_DEBUG[\'"]\s*,\s*(.*?)\s*\)\s*;/i', $config_content ) ) {
			// Update existing WP_DEBUG
			$config_content = preg_replace(
				'/define\s*\(\s*[\'"]WP_DEBUG[\'"]\s*,\s*(.*?)\s*\)\s*;/i',
				"define('WP_DEBUG', $debug_value);",
				$config_content
			);
		} else {
			// Add WP_DEBUG if it doesn't exist - after the last define or before the table prefix
			if ( preg_match( '/define\s*\(\s*[^\)]+\)\s*;/i', $config_content ) ) {
				// Find the last define statement
				preg_match_all( '/define\s*\(\s*[^\)]+\)\s*;/i', $config_content, $matches, PREG_OFFSET_CAPTURE );
				$last_define = end( $matches[0] );
				$pos         = $last_define[1] + strlen( $last_define[0] );

				// Insert after the last define
				$config_content = substr_replace(
					$config_content,
					"\n\ndefine('WP_DEBUG', $debug_value);",
					$pos,
					0
				);
			} else {
				// If no define statements found, insert before table prefix
				$config_content = preg_replace(
					'/(.*?)(\$table_prefix\s*=\s*[\'"][^\'"]*[\'"];)/s',
					"$1\ndefine('WP_DEBUG', $debug_value);\n$2",
					$config_content
				);
			}
		}
	}

	// Modify WP_DEBUG_LOG if requested
	if ( $options['debug_log'] !== null ) {
		$debug_log_value = $options['debug_log'] ? 'true' : 'false';

		// Check if WP_DEBUG_LOG already exists
		if ( preg_match( '/define\s*\(\s*[\'"]WP_DEBUG_LOG[\'"]\s*,\s*(.*?)\s*\)\s*;/i', $config_content ) ) {
			// Update existing WP_DEBUG_LOG
			$config_content = preg_replace(
				'/define\s*\(\s*[\'"]WP_DEBUG_LOG[\'"]\s*,\s*(.*?)\s*\)\s*;/i',
				"define('WP_DEBUG_LOG', $debug_log_value);",
				$config_content
			);
		} elseif ( str_contains( $config_content, 'WP_DEBUG' ) ) {
			// Add after WP_DEBUG if it exists
			$config_content = preg_replace(
				'/(define\s*\(\s*[\'"]WP_DEBUG[\'"]\s*,\s*(.*?)\s*\)\s*;)/i',
				"$1\ndefine('WP_DEBUG_LOG', $debug_log_value);",
				$config_content
			);
		} else {
			// Add at the appropriate place if WP_DEBUG doesn't exist
			if ( $options['debug'] !== null ) {
				// We already added WP_DEBUG, so add after it (it will be handled by the previous checks)
				$config_content = preg_replace(
					'/(define\s*\(\s*[\'"]WP_DEBUG[\'"]\s*,\s*(.*?)\s*\)\s*;)/i',
					"$1\ndefine('WP_DEBUG_LOG', $debug_log_value);",
					$config_content
				);
			} else {
				// If WP_DEBUG still doesn't exist, add before table prefix
				$config_content = preg_replace(
					'/(.*?)(\$table_prefix\s*=\s*[\'"][^\'"]*[\'"];)/s',
					"$1\ndefine('WP_DEBUG_LOG', $debug_log_value);\n$2",
					$config_content
				);
			}
		}
	}

	// Modify WP_DEBUG_DISPLAY if requested
	if ( $options['debug_display'] !== null ) {
		$debug_display_value = $options['debug_display'] ? 'true' : 'false';

		// Check if WP_DEBUG_DISPLAY already exists
		if ( preg_match( '/define\s*\(\s*[\'"]WP_DEBUG_DISPLAY[\'"]\s*,\s*(.*?)\s*\)\s*;/i', $config_content ) ) {
			// Update existing WP_DEBUG_DISPLAY
			$config_content = preg_replace(
				'/define\s*\(\s*[\'"]WP_DEBUG_DISPLAY[\'"]\s*,\s*(.*?)\s*\)\s*;/i',
				"define('WP_DEBUG_DISPLAY', $debug_display_value);",
				$config_content
			);
		} else {
			// Add after WP_DEBUG_LOG if it exists
			if ( str_contains( $config_content, 'WP_DEBUG_LOG' ) ) {
				$config_content = preg_replace(
					'/(define\s*\(\s*[\'"]WP_DEBUG_LOG[\'"]\s*,\s*(.*?)\s*\)\s*;)/i',
					"$1\ndefine('WP_DEBUG_DISPLAY', $debug_display_value);",
					$config_content
				);
			} elseif ( str_contains( $config_content, 'WP_DEBUG' ) ) {
				// Or add after WP_DEBUG if it exists
				$config_content = preg_replace(
					'/(define\s*\(\s*[\'"]WP_DEBUG[\'"]\s*,\s*(.*?)\s*\)\s*;)/i',
					"$1\ndefine('WP_DEBUG_DISPLAY', $debug_display_value);",
					$config_content
				);
			} else {
				// Add before table prefix if neither exist
				$config_content = preg_replace(
					'/(.*?)(\$table_prefix\s*=\s*[\'"][^\'"]*[\'"];)/s',
					"$1\ndefine('WP_DEBUG_DISPLAY', $debug_display_value);\n$2",
					$config_content
				);
			}
		}
	}

	// Check if content was actually modified
	if ( $config_content === $original_content ) {
		return frl_error( 'no_changes', 'No changes were needed in wp-config.php' );
	}

	// Write file safely with atomic operations
	$temp_path = $config_path . '.temp';

	// Save the changes to a temp file first
	if ( file_put_contents( $temp_path, $config_content, LOCK_EX ) === false ) {
		return frl_error( 'temp_write_failed', 'Failed to write temporary file: wp-config.php' );
	}

	// Verify temp file was written correctly
	$verify_content = file_get_contents( $temp_path );
	if ( $verify_content !== $config_content ) {
		// Clean up and return error
		@unlink( $temp_path );
		return frl_error( 'verify_failed', 'File verification failed: wp-config.php' );
	}

	// Copy original file permissions
	$perms = fileperms( $config_path );
	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Intentional: best-effort permission copy; a chmod() failure (e.g. restricted hosting/ownership) is non-critical and must not block the wp-config.php update that follows.
	@chmod( $temp_path, $perms );

	// Atomically replace the original with the new file
	if ( ! rename( $temp_path, $config_path ) ) {
		// If rename fails, try copy + delete as fallback
		if ( ! copy( $temp_path, $config_path ) ) {
			@unlink( $temp_path );
			return frl_error( 'rename_failed', 'Failed to update file: wp-config.php' );
		}
		@unlink( $temp_path ); // Clean up temp file after successful copy
	}

	// Always clean up the temp file if it still exists
	if ( file_exists( $temp_path ) ) {
		@unlink( $temp_path );
	}

	return true;
}
