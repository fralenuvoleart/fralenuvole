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
add_filter('saswp_modify_organization_output', 'frl_thirdparty_schema_organization_properties', 10, 1);
//add_filter('saswp_modify_schema_output', 'frl_thirdparty_deduplicate_schemas', 9999, 1);
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
 * Inject third-party schema properties into SASWP Organization schema output.
 *
 * Hooks into the 'saswp_modify_organization_output' filter to inject
 * properties defined in FRL_THIRDPARTY_SCHEMA_PROPERTIES for Organization-type schemas.
 *
 * @param array $input The schema output array.
 * @return array Modified schema array with injected properties.
 */
function frl_thirdparty_schema_organization_properties(array $input): array
{
    // Reentrancy guard: execute only once per request, suppress duplicates
    if (frl_is_already_running(__FUNCTION__)) {
        return [];
    }

    // Early exit: not an Organization schema
    if (($input['@type'] ?? '') !== 'Organization') {
        return $input;
    }

    // Early exit: no properties defined for Organization
    $props = FRL_THIRDPARTY_SCHEMA_PROPERTIES['Organization'] ?? [];
    if (empty($props)) {
        return $input;
    }

    // Early exit: input schema has no Organization keys beyond JSON-LD structural ones
    // Only inject if SASWP has built an Organization schema structure (even with empty values)
    $has_organization_keys = false;
    foreach ($input as $key => $value) {
        if ($key === '@context' || $key === '@type' || $key === '@id') {
            continue;
        }
        $has_organization_keys = true;
        break;
    }
    if (!$has_organization_keys) {
        return $input;
    }

    // Early exit: Organization schema has no address field — destroy the schema
    // Only output Organization schemas that include an address structure
    if (!isset($input['address']) || !is_array($input['address'])) {
        return [];
    }

    foreach ($props as $key => $value) {
        // Scalar property: skip if already set
        if (!is_array($value)) {
            if (isset($input[$key])) {
                continue;
            }
            $input[$key] = $value;
            continue;
        }

        // Array property (e.g. 'address'): deep-merge without overwriting existing keys
        if (!isset($input[$key]) || !is_array($input[$key])) {
            $input[$key] = $value;
        } else {
            $input[$key] = array_replace_recursive($input[$key], $value);
        }
    }

    return $input;
}

/**
 * Deduplicate SASWP schema output by @id, keeping the most complete version.
 *
 * Hooks into 'saswp_modify_schema_output' to remove duplicate Organization
 * schemas that SASWP generates (one with address, one without).
 *
 * @param array $schemas Array of all schema output arrays.
 * @return array Deduplicated schema array.
 */
function frl_thirdparty_deduplicate_schemas(array $schemas): array
{
    static $done = false;
    if ($done) {
        return $schemas;
    }

    $seen_ids = [];
    $deduplicated = [];

    foreach ($schemas as $schema) {
        if (!is_array($schema) || empty($schema['@id'])) {
            $deduplicated[] = $schema;
            continue;
        }

        $id = $schema['@id'];
        $type = $schema['@type'] ?? '';

        // Only deduplicate Organization schemas
        if ($type !== 'Organization') {
            $deduplicated[] = $schema;
            continue;
        }

        // First occurrence: keep it
        if (!isset($seen_ids[$id])) {
            $seen_ids[$id] = $schema;
            $deduplicated[] = $schema;
            continue;
        }

        // Duplicate found: prefer the one with 'address' (most complete Organization)
        $existing = $seen_ids[$id];
        $existing_has_address = isset($existing['address']) && is_array($existing['address']);
        $new_has_address = isset($schema['address']) && is_array($schema['address']);

        if ($new_has_address && !$existing_has_address) {
            // New one has address, existing doesn't — replace
            $seen_ids[$id] = $schema;
            foreach ($deduplicated as $i => $item) {
                if (is_array($item) && ($item['@id'] ?? '') === $id) {
                    $deduplicated[$i] = $schema;
                    break;
                }
            }
        }
        // Otherwise: keep existing (either it has address, or neither does — keep first)
    }

    $done = true;

    return $deduplicated;
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
