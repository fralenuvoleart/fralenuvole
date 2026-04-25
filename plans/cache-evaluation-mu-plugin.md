# Cache Evaluation: `functions-mu-plugin.php`

## Bootstrap Order Verification

**Confirmed:** Both `Frl_Cache_Manager::init()` and `frl_cache_remember()` are available when `functions-mu-plugin.php` loads.

Loading sequence in [`assets/mu/frl-mu-plugin.php`](../assets/mu/frl-mu-plugin.php):
1. Config loaded
2. `bootstrap.php` loaded → loads all helpers (including `functions-class-helpers.php` where `frl_cache_remember` is defined at line 108)
3. `Frl_Cache_Manager::init()` called via `bootstrap.php`
4. THEN `functions-mu-plugin.php` loaded

---

## Recursion Risk Assessment

**No recursion risk.** The concern was whether using `frl_cache_remember` inside `pre_option_*` / `pre_site_option_*` filters could trigger infinite recursion.

**Why it's safe:** [`frl_cache_remember()`](../includes/helpers/functions-class-helpers.php:108) → [`Frl_Cache_Manager::remember()`](../includes/core/cache/class-cache-manager.php:626) → [`get()`](../includes/core/cache/class-cache-manager.php:551) uses:
- `wp_cache_get()` / `get_transient()` — object cache layer
- The ONLY option call in the cache layer is [`should_bypass()`](../includes/core/cache/class-cache-manager.php:101) which calls `get_option('frl_disable_plugin')` — a completely different option that has nothing to do with `active_plugins` or `cron`
- **Never calls `get_option('active_plugins')` or `get_site_option('active_plugins')`** — no filter recursion

---

## Function-by-Function Evaluation

### 1. `frl_get_exclusion_options()` (line 30)

**Current behavior:**
- Combined DB query fetching `active_plugins` + `cron` in one SQL statement
- Static `$cache` variable for per-request dedup
- Used by both `pre_option_active_plugins` and `pre_option_cron` filters

**Caching decision: CACHE `active_plugins` only.**
- `active_plugins` changes only on plugin activation/deactivation — stable, ideal for `staticdata` group (TTL = 1 week)
- `cron` changes on every cron execution — volatile, **cannot be safely cached** (stale data could cause missed or duplicated scheduled events)
- **Must split the combined query** so `active_plugins` can be cached independently

**Proposed implementation:**
```php
function frl_get_exclusion_options(): array
{
    static $options = null;
    if ($options !== null) {
        return $options;
    }

    $options = [
        'active_plugins' => [],
        'cron'           => [],
    ];

    // Cache active_plugins (stable data) — only changes on plugin activation/deactivation
    $options['active_plugins'] = frl_cache_remember(
        'staticdata',
        'mu_plugin_active_plugins',
        function () {
            global $wpdb;
            $value = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
                    'active_plugins'
                )
            );
            return $value ? (array) maybe_unserialize($value) : [];
        },
        WEEK_IN_SECONDS
    );

    // Fetch cron fresh (volatile data) — changes every cron execution
    global $wpdb;
    $cron_value = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            'cron'
        )
    );
    $options['cron'] = $cron_value ? (array) maybe_unserialize($cron_value) : [];

    return $options;
}
```

**Cache invalidation:** Hook `activated_plugin` / `deactivated_plugin` in [`cache-cleanup.php`](../includes/core/cache/cache-cleanup.php) to purge `frl_cache_clear('staticdata', 'mu_plugin_active_plugins')`.

---

### 2. `frl_plugins_exclusion_filter()` (line 73)

**Current behavior:** Orchestrator — builds `$excluded` array from frontend/backend/capability settings, then registers the filter closures.

**Caching decision: NO CHANGES NEEDED.** This is pure logic (building `$excluded` array from settings), no DB queries. It will automatically benefit from the cached data returned by the modified `frl_get_exclusion_options()`.

---

### 3. `frl_add_exclusion_filter_active_plugins()` (line 183)

**Current behavior:**
- `pre_option_active_plugins` filter
- Calls `frl_get_exclusion_options()` → gets `active_plugins` data (which will now be cached)
- Filters using the `$excluded` array
- Static `$cache` for per-request L1 dedup

**Caching decision: NO DIRECT CHANGES.** It already benefits from `frl_get_exclusion_options()` returning cached data. The static `$cache` variable provides L1 dedup (faster than persistent cache lookup for subsequent calls within same request).

---

### 4. `frl_add_exclusion_filter_network_active_plugins()` (line 216)

**Current behavior:**
- `pre_site_option_active_plugins` filter
- Does its OWN `$wpdb->get_var()` to `wp_sitemeta` — does NOT use `frl_get_exclusion_options()`
- Uses direct DB to avoid filter recursion (comment at line 225)
- Static `$cache` for per-request dedup

**Caching decision: CACHE this query.**
- Network active plugins change **even less frequently** than regular active plugins (only on network-wide activation/deactivation)
- The direct DB approach is for recursion avoidance, NOT data freshness
- `frl_cache_remember` does NOT touch the option system → no recursion

**Proposed implementation:**
```php
function frl_add_exclusion_filter_network_active_plugins(array $excluded): void
{
    add_filter('pre_site_option_active_plugins', function ($pre, $option) use ($excluded) {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        $plugins = frl_cache_remember(
            'staticdata',
            'mu_plugin_network_active_plugins',
            function () {
                global $wpdb;
                $plugins = $wpdb->get_var(
                    $wpdb->prepare(
                        'SELECT meta_value FROM ' . $wpdb->sitemeta . ' WHERE meta_key = %s LIMIT 1',
                        'active_plugins'
                    )
                );
                return $plugins ? maybe_unserialize($plugins) : [];
            },
            WEEK_IN_SECONDS
        );

        $filtered = array_filter((array) $plugins, function ($plugin) use ($excluded) {
            return !in_array($plugin, $excluded);
        });

        $cache = array_values($filtered);
        return $cache;
    }, 10, 2);
}
```

**Cache invalidation for multisite:** Hook network-level `activated_plugin` / `deactivated_plugin`.

---

### 5. `frl_add_exclusion_filter_cron()` (line 261)

**Current behavior:**
- `pre_option_cron` filter, runs only during WP Cron (`frl_is_cron_job_request()` at line 162)
- Calls `frl_get_exclusion_options()` → gets `cron` data (fetched fresh)
- Iterates all cron events, calls `wp_get_schedules()`, removes orphaned events
- Also sanitizes `args` to prevent `TypeError: count(): Argument #1 must be of type Countable|array, null given`

**Caching decision: DO NOT CACHE.** 
- Cron data changes on every `wp-cron.php` execution
- Serving stale cron data → missed events, orphaned events still running
- WP Cron requests are a small fraction of traffic (low performance impact)
- The `args` sanitization requires the actual current cron state

---

## Cache Invalidation Plan

Add cleanup hooks in [`includes/core/cache/cache-cleanup.php`](../includes/core/cache/cache-cleanup.php):

```php
// Standard plugin activation/deactivation
add_action('activated_plugin', 'frl_purge_mu_plugin_cache');
add_action('deactivated_plugin', 'frl_purge_mu_plugin_cache');

// Multisite network-level activation/deactivation
add_action('activate_plugin', 'frl_purge_mu_plugin_cache');
add_action('deactivate_plugin', 'frl_purge_mu_plugin_cache');

function frl_purge_mu_plugin_cache(): void {
    frl_cache_clear('staticdata', 'mu_plugin_active_plugins');
    frl_cache_clear('staticdata', 'mu_plugin_network_active_plugins');
}
```

---

## Summary of Changes

| File | Function | Change |
|------|----------|--------|
| `includes/helpers/functions-mu-plugin.php` | `frl_get_exclusion_options()` | Split combined query; cache `active_plugins` via `frl_cache_remember`; fetch `cron` fresh |
| `includes/helpers/functions-mu-plugin.php` | `frl_add_exclusion_filter_network_active_plugins()` | Wrap `$wpdb->get_var()` with `frl_cache_remember` |
| `includes/core/cache/cache-cleanup.php` | New hook handler | Purge cache on plugin activation/deactivation |
