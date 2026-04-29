# Plugin Exclusion Feature

## Overview

Prevents specified plugins from loading without deactivating them. Uses MU plugin to filter `active_plugins` before regular plugins load.

## How It Works

The MU loader (`assets/mu/frl-mu-plugin.php`) runs at `muplugins_loaded` hook and adds a `pre_option_active_plugins` filter to remove excluded plugins. All exclusion logic is defined in [`includes/helpers/functions-mu-plugin.php`](includes/helpers/functions-mu-plugin.php), required only by the MU plugin.

## Exclusion Types

| Type | Option | When Applied | Format |
|------|--------|--------------|--------|
| **Frontend** | `excluded_plugins_frontend` | Frontend context (HTML + AJAX) — all users | Plugin path per line (e.g., `hello.php/hello.php`) |
| **Backend** | `excluded_plugins_backend` | Admin context — only on specific admin screens | `plugin-path\|admin-screen` per line — the screen after `\|` is **required** (e.g., `ai-engine/ai-engine.php\|post.php`) |
| **Capability** | `excluded_plugins_bycap` | Non-frontend context (admin, REST, WP Cron) — users without required cap | Plugin path per line (e.g., `hello.php/hello.php`) |

### Backend Exclusion Format

The `excluded_plugins_backend` option uses a `|` separator format:

```
plugin-folder/plugin.php|admin-screen
```

- **plugin-path**: The plugin handle as stored in `active_plugins` (e.g., `ai-engine/ai-engine.php`).
- **admin-screen** (required): The admin page to match. Uses [`frl_is_admin_page()`](includes/helpers/functions-access-control.php:222) under the hood:
  - Filename-based: `post.php`, `edit.php`, `users.php`, `plugins.php`, etc.
  - Page slug-based: `my-custom-page` (matches `admin.php?page=my-custom-page`).
  - The screen after the pipe is **required** — without it, the exclusion does not activate. To exclude on multiple screens, add one line per screen.

Examples:

```
ai-engine/ai-engine.php|post.php                          # Excluded only on Edit Post screen
wp-seopress/seopress.php|seopress                         # Excluded on SEOPress settings page
hello.php/hello.php|users.php                             # Excluded only on Users screen
```

## Behavior

| Context | Exclusion | Result |
|----------|-----------|--------|
| Frontend | Frontend list | BLOCKED |
| Admin — screen matches a backend entry | Backend list | BLOCKED |
| Admin — screen does not match any backend entry | Backend list | LOADS |
| Admin — has cap | Capability list | LOADS |
| Admin — no cap | Capability list | BLOCKED |
| REST — has cap | Capability list | LOADS |
| REST — no cap | Capability list | BLOCKED |
| Cron | Capability list | BLOCKED — orphaned cron events silently filtered |

> **Note on Cron**: During WP Cron, there is no authenticated user, so capability-based exclusion always blocks the target plugin. To prevent `invalid_schedule` errors (WordPress trying to reschedule events whose schedules were never registered), an `option_cron` filter strips orphaned events from the cron array before WordPress processes them. This filter is non-destructive — it does not modify the database.

## DB Query Optimization

The exclusion system uses a two-tier caching strategy to minimize database overhead:

### Tier 1: Persistent Cache (cross-request)

The `active_plugins` option from `wp_options` is cached via [`frl_cache_remember()`](includes/helpers/functions-class-helpers.php:108) in the `options` cache group with `WEEK_IN_SECONDS` TTL:

```php
$options['active_plugins'] = frl_cache_remember(
    'options',
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
```

For multisite, network active plugins (`wp_sitemeta`) have their own separate cache entry:

```php
$plugins = frl_cache_remember(
    'options',
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
```

**Why `WEEK_IN_SECONDS`?** The `active_plugins` list changes only on plugin activation/deactivation — a relatively rare admin action. The default `options` group TTL of `HOUR_IN_SECONDS` (1 hour) would cause unnecessary cache churn for this stable data.

**Why the `options` group?** The data IS a WordPress option stored in `wp_options` / `wp_sitemeta`. The `options` group is the correct semantic fit for option-originating data.

### Tier 2: Static Per-Request Dedup (3 levels)

Within a single request, three levels of static caching prevent redundant work:

1. **`frl_get_exclusion_options()` `static $options`** — Guards the entire function, ensuring both the `frl_cache_remember` call and the cron DB query run at most once per request.

2. **Closure `static $cache`** in each `pre_option_*` filter — Stores the **filtered** result (after applying the per-request `$excluded` array), preventing duplicate `array_filter` work.

3. **`frl_cache_remember` internal runtime cache** — `Frl_Cache_Manager::get()` stores all fetched values in `self::$runtime_cache` (a static LRU array), avoiding redundant persistent cache lookups.

### Why Cron Is Not Cached Persistently

The `cron` option in `wp_options` changes on every WP Cron execution (events removed after execution, new events added). Persistent caching would risk:
- **Stale data** — missed scheduled events or orphaned events running when they shouldn't
- **TTL mismatch** — too short equals no benefit, too long equals data corruption

Cron is fetched fresh via a separate `$wpdb->get_var()` each request, but the `static $options` guard in `frl_get_exclusion_options()` ensures this runs at most once per request.

The cron filter only executes during WP Cron requests (`frl_is_cron_job_request()`), a low-traffic code path where a single fresh DB query per request is negligible.

### Cache Invalidation

When plugins are activated or deactivated, the persistent cache is purged via hooks in [`cache-cleanup.php`](includes/core/cache/cache-cleanup.php):

```php
add_action('activated_plugin',   'frl_purge_mu_plugin_exclusion_cache', 10, 2);
add_action('deactivated_plugin', 'frl_purge_mu_plugin_exclusion_cache', 10, 2);

function frl_purge_mu_plugin_exclusion_cache($plugin = '', $network_wide = false): void {
    frl_cache_clear('options', 'mu_plugin_active_plugins');
    frl_cache_clear('options', 'mu_plugin_network_active_plugins');
}
```

This covers all activation paths: admin UI, WP-CLI (`wp plugin activate`), programmatic calls (`activate_plugin()`), and network-wide activation in multisite.

### Recursion Safety

`frl_cache_remember` is safe inside `pre_option_*` and `pre_site_option_*` filters because:
- It uses object cache (`wp_cache_get`) and transients (`get_transient`) — never `get_option()` or `get_site_option()`
- The only option call in the cache layer is `should_bypass()` → `get_option('frl_disable_plugin')` — a completely different option unrelated to `active_plugins` or `cron`
- The `$wpdb->get_var()` fallback in the callback directly queries the database, bypassing the WordPress option filter chain entirely

## Cron Event Cleanup

When a plugin is excluded from loading, its custom cron schedules never get registered. WordPress would otherwise log `invalid_schedule` errors when trying to reschedule those events. The [`frl_add_exclusion_filter_cron()`](includes/helpers/functions-mu-plugin.php:404) function adds an `option_cron` filter that:

1. Receives the cron data directly from WordPress (via the `option_cron` filter parameter).
2. Gets all currently registered schedules via `wp_get_schedules()`.
3. Removes events whose `schedule` name does not exist in the registered schedules.
4. Sanitizes `args` to always be an array, preventing TypeError from null args.
5. Returns the filtered array for WordPress to process.

This filter is added **before** the empty-exclusion early return, so it runs even when no plugins are being actively excluded during the cron request. This ensures args sanitization (null→array) is always applied to protect against `TypeError` in `class-wp-hook.php:325`.

The filter is completely non-destructive — the database is never modified. If the exclusion is later removed, the plugin will load, register its schedules, and its cron events will work again.

Additionally, as a safety measure, the filter sanitizes `args` on every cron event to ensure it is always an array. This prevents `TypeError: count(): Argument #1 must be of type Countable|array, null given` in `class-wp-hook.php:325` when `do_action_ref_array` at `wp-cron.php:191` receives null args — a pre-existing WordPress issue that can occur when a cron event was stored with `args => null` in the database.

## Early-Loading Access Check

Capability-based exclusion runs during `muplugins_loaded` (before `plugins_loaded`), when WordPress user functions are not yet available. The dedicated [`frl_mu_check_access()`](includes/helpers/functions-mu-plugin.php:91) function handles this:

1. If `plugins_loaded` has fired → delegates to standard [`frl_has_access()`](includes/helpers/functions-access-control.php:95)
2. If early loading → uses [`frl_get_auth_cookie_user_data()`](includes/helpers/functions-mu-plugin.php:90) to read the auth cookie and query the DB directly

The DB query in `frl_get_auth_cookie_user_data()` is cached cross-request via `frl_cache_remember` with a **300s TTL** (aligned with `frl_has_access()` standard path), keyed by username to prevent cross-user pollution.

## Files

| File | Purpose |
|------|---------|
| `assets/mu/frl-mu-plugin.php` | MU loader bootstrap — defines `FRL_MU_NAME`, loads bootstrap + helpers, registers `muplugins_loaded` hook |
| `includes/helpers/functions-mu-plugin.php` | All exclusion logic — combined DB query, exclusion types, cron cleanup, `frl_get_auth_cookie_user_data()`, `frl_mu_check_access()` |
| `includes/helpers/functions-access-control.php` | Contains `frl_has_access()`, `frl_is_admin_page()` |
| `includes/core/cache/cache-cleanup.php` | Cache invalidation hooks for `activated_plugin`/`deactivated_plugin` |
| `config/config-cache.php` | Cache group configuration — data stored in `options` group |
