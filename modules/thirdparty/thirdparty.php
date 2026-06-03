<?php

/**
 * Module Name: Third-Party
 * Description: Cache Bridge for caching plugins, and tweaks for third-party plugins
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/config-constants-thirdparty.php';

add_action('wp_enqueue_scripts',     'frl_thirdparty_public_scripts',    FRL_THEMEKIT_STYLE_PRIORITY['modules'], 1);
add_action('admin_enqueue_scripts',  'frl_thirdparty_admin_scripts',      0,   0);
add_filter('emr/feature/background', '__return_false',                    10,  0);
add_filter('saswp_modify_organization_output', 'frl_thirdparty_inject_schema_properties_filter', 10, 1);
add_filter('saswp_modify_about_page_output', 'frl_thirdparty_inject_schema_properties_filter', 10, 1);
add_filter('saswp_modify_contact_page_output', 'frl_thirdparty_inject_schema_properties_filter', 10, 1);
add_filter('saswp_modify_author_output', 'frl_thirdparty_inject_schema_properties_filter', 10, 1);
add_filter('saswp_modify_website_output', 'frl_thirdparty_inject_schema_properties_filter', 10, 1);
add_filter('saswp_modify_profile_page_schema_output', 'frl_thirdparty_inject_schema_properties_filter', 10, 1);
add_filter('saswp_modify_schema_output', 'frl_thirdparty_sanitize_schemas', 9999, 1);
add_action('add_meta_boxes',         'frl_remove_litespeed_meta_boxes',   999, 0);
add_filter('rest_endpoints',         'frl_greenshift_fix_rest_schemas',   10,  1);

/**
 * Enqueue thirdparty-specific styles and scripts
 */
function frl_thirdparty_public_scripts()
{
    if(!frl_is_valid_frontend_page_request()) {
        return;
    }
    $assets = [
        'thirdparty-public-css' => 'modules/thirdparty/assets/css/public.css'
    ];

    frl_enqueue_scripts($assets, 'thirdparty_public');
}

/**
 * Enqueue thirdparty-specific styles and scripts
 */
function frl_thirdparty_admin_scripts()
{
    $assets = [
        'thirdparty-admin-css' => 'modules/thirdparty/assets/css/admin.css'
    ];
    
    // Load Meow-specific styles only when any Meow plugin is active.
    // Array of known plugin paths — the same admin-meow.css applies to all.
    $meow_plugins = [
        'ai-engine/ai-engine.php',
        'seo-engine/seo-engine.php',
    ];
    
    foreach ($meow_plugins as $plugin_path) {
        if (frl_is_thirdparty_plugin_active($plugin_path)) {
            $assets['thirdparty-admin-meow-css'] = 'modules/thirdparty/assets/css/admin-meow.css';
            break;
        }
    }
    
    frl_enqueue_scripts($assets, 'thirdparty_admin');
}

function frl_remove_litespeed_meta_boxes()
{
    if (frl_is_admin()) {
        $args = array(
            'public' => true,
        );

        $post_types = get_post_types($args);
        foreach ($post_types as $post_type) {
            remove_meta_box('litespeed_meta_boxes', $post_type, 'side');
        }
    }
}

/**
 * Fix invalid schema types in third-party REST endpoints.
 * - Greenshift: ensure post_id uses a valid 'integer' type to satisfy WP schema.
 *
 * @param array $endpoints
 * @return array
 */
function frl_greenshift_fix_rest_schemas($endpoints)
{
	static $done = false;
	if ($done) {
		return $endpoints;
	}

	$route = '/greenshift/v1/get-post-part';
	if (!isset($endpoints[$route]) || !is_array($endpoints[$route])) {
		return $endpoints;
	}

	foreach ($endpoints[$route] as $i => $endpoint) {
		if (!isset($endpoint['args']) || !is_array($endpoint['args'])) {
			continue;
		}
		if (isset($endpoint['args']['post_id'])) {
			$type = $endpoint['args']['post_id']['type'] ?? null;
			if ($type !== 'integer') {
				$endpoints[$route][$i]['args']['post_id']['type'] = 'integer';
			}
			if (!isset($endpoints[$route][$i]['args']['post_id']['sanitize_callback'])) {
				$endpoints[$route][$i]['args']['post_id']['sanitize_callback'] = 'absint';
			}
			if (!isset($endpoints[$route][$i]['args']['post_id']['validate_callback'])) {
				$endpoints[$route][$i]['args']['post_id']['validate_callback'] = static function ($value) {
					return is_numeric($value);
				};
			}
		}
	}
	$done = true;
	return $endpoints;
}

/**
 * Inject third-party properties into a single SASWP schema.
 *
 * Generic per-schema filter — checks frl_get_schema_properties()
 * for the schema's @type and injects matching properties.
 *
 * Hooks into 'saswp_modify_organization_output' (and can be reused for
 * other per-schema filters if SASWP exposes them).
 *
 * @param array $input The schema output array.
 * @return array Modified schema array.
 */
function frl_thirdparty_inject_schema_properties_filter(array $input): array
{
    if (!frl_get_option('thirdparty_schema_properties')) {
        return $input;
    }

    $type = $input['@type'] ?? '';
    $props = frl_get_schema_properties()[$type] ?? [];

    if (empty($props)) {
        return $input;
    }

    return frl_thirdparty_inject_schema_properties($input, $props);
}

/**
 * Recursively trim whitespace-contaminated keys in a schema array.
 *
 * Only processes keys that contain leading/trailing whitespace.
 * This is a targeted fix for third-party bugs (e.g., SASWP's 'name ' key).
 *
 * @param array $array The schema array to process.
 * @return array Array with trimmed keys (only where needed).
 */
function frl_trim_schema_keys(array $array): array
{
    $result = [];
    $needs_rebuild = false;

    foreach ($array as $key => $value) {
        $trimmed_key = $key;
        if ($key !== '' && $key !== ($trimmed_key = trim($key))) {
            $needs_rebuild = true;
        }
        if (is_array($value)) {
            $trimmed_value = frl_trim_schema_keys($value);
            if ($trimmed_value !== $value) {
                $needs_rebuild = true;
            }
            $result[$trimmed_key] = $trimmed_value;
        } else {
            $result[$trimmed_key] = $value;
        }
    }

    // Only return a new array if we actually found contaminated keys
    return $needs_rebuild ? $result : $array;
}

/**
 * Sanitize and deduplicate SASWP schema output.
 *
 * Hooks into 'saswp_modify_schema_output' to:
 * - Deduplicate by @id, keep first occurrence
 * - Inject static props from frl_get_schema_properties()
 * - Inject post-term props from frl_get_schema_term_map()
 * - Inject person reference props from frl_get_schema_person_map()
 * - Trim whitespace-contaminated keys
 *
 * @param array $schemas Array of all schema output arrays.
 * @return array Sanitized, deduplicated, and enhanced schema array.
 */
function frl_thirdparty_sanitize_schemas(array $schemas): array
{
    static $done = false;
    if ($done) {
        return $schemas;
    }

    if (!frl_get_option('thirdparty_schema_properties')) {
        return $schemas;
    }

    $all_props = frl_get_schema_properties();
    $seen_ids = [];
    $deduplicated = [];

    // Pre-resolve post data once, outside the loop
    $post_id = get_the_ID();
    $schema_term_map = frl_get_schema_term_map();
    $taxonomy_cache = [];
    $schema_person_map = frl_get_schema_person_map();
    $ref_cache = [];

    foreach ($schemas as $schema) {
        if (!is_array($schema) || empty($schema['@id'])) {
            $deduplicated[] = $schema;
            continue;
        }

        $id = $schema['@id'];
        $type = $schema['@type'] ?? '';
        $props = $all_props[$type] ?? [];
        $type_map = $schema_term_map[$type] ?? null;
        $person_map = $schema_person_map[$type] ?? null;

        // Skip types with no enrichment defined
        if (empty($props) && empty($type_map) && empty($person_map)) {
            $deduplicated[] = $schema;
            continue;
        }

        // First occurrence: inject static props, post-term props, and person props
        if (!isset($seen_ids[$id])) {
            if (!empty($props)) {
                $schema = frl_thirdparty_inject_schema_properties($schema, $props);
            }

            if ($post_id && !empty($type_map)) {
                $post_props = frl_build_schema_term_properties($post_id, $type_map, $taxonomy_cache);
                if (!empty($post_props)) {
                    $schema = frl_thirdparty_inject_schema_properties($schema, $post_props);
                }
            }

            if ($post_id && !empty($person_map)) {
                $person_props = frl_build_schema_person_properties($post_id, $person_map, $ref_cache);
                if (!empty($person_props)) {
                    $schema = frl_thirdparty_inject_schema_properties($schema, $person_props);
                }
            }

            $seen_ids[$id] = true;
            $deduplicated[] = $schema;
            continue;
        }

        // Duplicate found: discard, keep first occurrence
    }

    $done = true;

    // Single trim pass on the final output — catches all contaminated keys
    // from any source (SASWP bugs, nested schemas, etc.) in one O(n) walk.
    return array_map(function ($s) {
        return is_array($s) ? frl_trim_schema_keys($s) : $s;
    }, $deduplicated);
}

/**
 * Inject third-party properties into a single schema.
 *
 * Array values (Person objects, term collections, etc.) are deep-merged
 * via array_replace_recursive to preserve unset sub-keys from the source
 * (e.g. sameAs). Scalar values overwrite the existing value unconditionally.
 *
 * Special sentinel: if $value is null, the property is removed from the schema.
 *
 * @param array $schema The schema array.
 * @param array $props Properties to inject (property key => value).
 * @return array Modified schema array.
 */
function frl_thirdparty_inject_schema_properties(array $schema, array $props): array
{
    if (empty($props)) {
        return $schema;
    }

    foreach ($props as $key => $value) {
        // Sentinel: null means remove the property
        if ($value === null) {
            unset($schema[$key]);
            continue;
        }

        if (is_array($value)) {
            if (!isset($schema[$key]) || !is_array($schema[$key])) {
                $schema[$key] = $value;
            } else {
                $schema[$key] = array_replace_recursive($schema[$key], $value);
            }
            continue;
        }

        // Scalar property: overwrite unconditionally
        $schema[$key] = $value;
    }

    return $schema;
}

// =============================================================================
// Cache Bridge (Two-Way Sync)
// =============================================================================

if (frl_get_option('thirdparty_cache_inbound')) {
    // --- Inbound: third-party purge → clear fralenuvole caches ---
    foreach (array_keys(frl_thirdparty_get_inbound_hooks()) as $hook) {
        add_action($hook, 'frl_thirdparty_inbound_cache_clear', 10, 0);
    }

    // --- Query-param based purge detection (for plugins that don't fire actions) ---
    add_action('admin_init', 'frl_thirdparty_check_query_triggers', 5, 0);
}

/**
 * Check for query-param based cache purges that don't fire action hooks.
 * This catches Breeze's admin bar "Purge All" button which bypasses do_action.
 *
 * Priority 5 ensures this runs early, but only processes if no inbound hook
 * was already triggered (guarded by frl_is_already_running).
 */
function frl_thirdparty_check_query_triggers(): void
{
    if (frl_is_already_running('frl_thirdparty_inbound_cache_clear')) {
        return; // Already handled by action hook
    }

    foreach (frl_thirdparty_get_inbound_queries() as $key => $config) {
        $query_key = $config['query_key'] ?? null;
        if (empty($query_key) || !isset($_GET[$query_key])) {
            continue;
        }

        // Verify nonce using standard pattern: {query_key}_cache
        $nonce_action = $query_key . '_cache';
        if (!isset($_GET['_wpnonce'])) {
            continue;
        }
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), $nonce_action)) {
            continue;
        }

        // Trigger detected - execute cache clear
        $groups = (array) ($config['clear'] ?? []);
        foreach ($groups as $group) {
            frl_cache_clear($group);
        }

        if (!empty($config['rewrite_flush']) && function_exists('frl_schedule_rewrite_flush')) {
            frl_schedule_rewrite_flush();
        }

        $label = $config['label'] ?? $key;
        $clear_groups = (array) ($config['clear'] ?? []);
        $parts = [sprintf(
            __('%s purge detected: %s scheduled.', FRL_PREFIX), 
            '<strong>' . esc_html($label) . '</strong>', 
            '<strong>'.ucfirst(esc_html(implode(', ', $clear_groups))).' flush</strong>'
        )];

        if (!empty($config['rewrite_flush'])) {
            $parts[] = __('<strong>Rewrite rules</strong> flush scheduled.', FRL_PREFIX);
        }
        frl_add_admin_notice(implode(' ', $parts), 'info', 30);

        // Mark as processed to prevent duplicate handling
        frl_is_already_running('frl_thirdparty_inbound_cache_clear', true);
        break; // Only process one trigger per request
    }
}

/**
 * Triggered when a third-party caching plugin clears its cache.
 * Clears relevant fralenuvole caches based on the hook's config.
 *
 * Per-function guard: all configured inbound hooks carry equivalent
 * clear directives, so the first third-party purge in a request is
 * processed and any subsequent ones (even from different plugins
 * cascading among themselves) are skipped.
 *
 * Cascade prevention: a 60-second cooldown transient blocks rescheduling a
 * rewrite flush that was already triggered by a prior third-party purge, breaking
 * the cross-request bidirectional loop. The cooldown lives here so that legitimate
 * callers — term changes, activation, settings saves — are never rate-limited.
 */
function frl_thirdparty_inbound_cache_clear(): void
{
    if (frl_is_already_running(__FUNCTION__)) {
        return;
    }

    $hook = current_filter();
    $config = frl_thirdparty_get_inbound_hooks()[$hook] ?? null;
    if (!$config) {
        return;
    }

    $groups = (array) ($config['clear'] ?? []);
    foreach ($groups as $group) {
        frl_cache_clear($group);
    }

    if (!empty($config['rewrite_flush']) && function_exists('frl_schedule_rewrite_flush')) {
        if (!frl_get_transient('rewrite_flush_cooldown')) {
            frl_set_transient('rewrite_flush_cooldown', true, 60);
            frl_schedule_rewrite_flush();
        }
    }

    $label = $config['label'] ?? $hook;
    $clear_groups = (array) ($config['clear'] ?? []);
    $parts = [sprintf(__('%s triggered a purge: fralenuvole %s cache cleared.', FRL_PREFIX), '<strong>' . esc_html($label) . '</strong>', esc_html(implode(', ', $clear_groups)))];
    if (!empty($config['rewrite_flush'])) {
        $parts[] = __('Rewrite rules flush scheduled.', FRL_PREFIX);
    }
    frl_add_admin_notice(implode(' ', $parts), 'info', 30);
}

// --- Outbound: fralenuvole purge → notify third-party cache plugins ---

/**
 * Filtered accessor for inbound hook configuration.
 *
 * Third-party code can add new inbound hooks via:
 *   add_filter('frl_thirdparty_inbound_hooks', function(array $hooks): array {
 *       $hooks['my_plugin_clear_cache'] = ['label' => 'My Plugin', 'clear' => 'light'];
 *       return $hooks;
 *   });
 *
 * The result is cached statically so the filter runs once per request.
 *
 * @return array
 */
function frl_thirdparty_get_inbound_hooks(): array
{
    static $hooks = null;
    if ($hooks === null) {
        $hooks = apply_filters('frl_thirdparty_inbound_hooks', FRL_THIRDPARTY_INBOUND_HOOKS);
    }
    return $hooks;
}

/**
 * Filtered accessor for inbound query-trigger configuration.
 *
 * Third-party code can add new query-param triggers via:
 *   add_filter('frl_thirdparty_inbound_queries', function(array $queries): array {
 *       $queries['my_plugin_purge'] = ['label' => 'My Plugin', 'clear' => 'light', 'query_key' => 'myplugin_purge'];
 *       return $queries;
 *   });
 *
 * @return array
 */
function frl_thirdparty_get_inbound_queries(): array
{
    static $queries = null;
    if ($queries === null) {
        $queries = apply_filters('frl_thirdparty_inbound_queries', FRL_THIRDPARTY_INBOUND_QUERIES);
    }
    return $queries;
}

/**
 * Filtered accessor for outbound hook configuration.
 *
 * Third-party code can add new outbound integrations via:
 *   add_filter('frl_thirdparty_outbound_hooks', function(array $hooks): array {
 *       $hooks['my_cache_plugin'] = [
 *           'type'     => 'action',
 *           'target'   => 'my_cache_plugin_purge_all',
 *           'check'    => 'My_Cache_Plugin',
 *           'triggers' => ['hard', 'rewrite_flush'],
 *       ];
 *       return $hooks;
 *   });
 *
 * @return array
 */
function frl_thirdparty_get_outbound_hooks(): array
{
    static $hooks = null;
    if ($hooks === null) {
        $hooks = apply_filters('frl_thirdparty_outbound_hooks', FRL_THIRDPARTY_OUTBOUND_HOOKS);
    }
    return $hooks;
}

/**
 * Conditionally notify third-party cache plugins based on the trigger event.
 * Only plugins whose 'triggers' config includes the given $trigger are called.
 *
 * Temporarily removes inbound listeners before firing outbound actions to
 * prevent self-triggering loops (e.g. Breeze uses the same action name for
 * both purge and post-purge; WP Rocket's rocket_clean_domain() internally
 * fires after_rocket_clean_domain which we listen on inbound).
 *
 * @param string $trigger Internal flush event name (e.g. 'hard', 'rewrite_flush', 'all', 'light').
 * @return array Array of plugin labels that were notified (with status info).
 */
function frl_thirdparty_maybe_notify(string $trigger): array
{
    if (!frl_get_option('thirdparty_cache_outbound')) {
        return [];
    }

    if (frl_is_already_running(__FUNCTION__)) {
        return [];
    }

    $notified = [];

    // Suspend inbound listeners so outbound actions don't re-enter our own handler.
    $inbound_hooks = frl_thirdparty_get_inbound_hooks();
    $outbound_hooks = frl_thirdparty_get_outbound_hooks();

    foreach (array_keys($inbound_hooks) as $inbound_hook) {
        remove_action($inbound_hook, 'frl_thirdparty_inbound_cache_clear', 10);
    }

    foreach ($outbound_hooks as $key => $entry) {
        $triggers = $entry['triggers'] ?? [];
        if (!in_array($trigger, $triggers, true)) {
            continue;
        }

        $check = $entry['check'] ?? '';
        $label = $entry['label'] ?? $key;

        $has_handler =
            (!empty($check) && (function_exists($check) || class_exists($check))) ||
            ($entry['type'] === 'action' && has_action($entry['target']));

        if (!$has_handler) {
            continue;
        }

        if ($entry['type'] === 'action') {
            $callback_count = has_action($entry['target']);
            if ($callback_count !== false) {
                do_action($entry['target']);
                $notified[] = [
                    'label'    => $label,
                    'status'   => 'notified',
                    'handlers' => $callback_count,
                    'target'   => $entry['target'],
                ];
            }
        } elseif ($entry['type'] === 'function' && function_exists($entry['target'])) {
            call_user_func($entry['target']);
            $notified[] = [
                'label'  => $label,
                'status' => 'called',
                'target' => $entry['target'],
            ];
        }
    }

    // Restore inbound listeners for any subsequent genuine third-party purges.
    foreach (array_keys($inbound_hooks) as $inbound_hook) {
        add_action($inbound_hook, 'frl_thirdparty_inbound_cache_clear', 10, 0);
    }

    frl_is_already_running(__FUNCTION__, true);

    // Store notification results for display (if in admin context)
    if (!empty($notified) && frl_is_admin()) {
        frl_thirdparty_store_notification_notice($notified, $trigger);
    }

    return $notified;
}

/**
 * Store notification results to display as admin notice on next page load.
 *
 * @param array  $notified_plugins Array of plugins that were notified.
 * @param string $trigger          The trigger event name.
 */
function frl_thirdparty_store_notification_notice(array $notified_plugins, string $trigger): void
{
    if (empty($notified_plugins)) {
        return;
    }

    $notice_data = [
        'plugins' => $notified_plugins,
        'trigger' => $trigger,
        'time' => time(),
    ];

    // Store in transient for display on next page load
    frl_set_transient('thirdparty_notification_pending', $notice_data, 60);
}

/**
 * Display admin notice confirming third-party plugins were notified.
 * Registered on admin_init to check for pending notifications.
 */
function frl_thirdparty_display_notification_notice(): void
{
    $pending = frl_get_transient('thirdparty_notification_pending');
    if (empty($pending) || !is_array($pending)) {
        return;
    }

    // Clear the transient so notice only shows once
    frl_delete_transient('thirdparty_notification_pending');

    $plugins = $pending['plugins'] ?? [];
    if (empty($plugins)) {
        return;
    }

    // Build notice message - all plugins were successfully notified
    $plugin_names = [];
    foreach ($plugins as $plugin) {
        $plugin_names[] = esc_html($plugin['label'] ?? 'Unknown');
    }

    $message = sprintf(
        __('Third-party cache plugins notified: <strong>%s</strong>', FRL_PREFIX),
        implode(', ', $plugin_names)
    );

    frl_add_admin_notice($message, 'success', 30);
}

if (frl_get_option('thirdparty_cache_outbound')) {
    add_action('admin_init', 'frl_thirdparty_display_notification_notice', 20, 0);
}
