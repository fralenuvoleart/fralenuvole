# Plugin Exclusion Feature

## Overview

Prevents specified plugins from loading without deactivating them. Uses MU plugin to filter `active_plugins` before regular plugins load.

## How It Works

The MU loader (`assets/mu/frl-mu-plugin.php`) runs at `muplugins_loaded` hook and adds a `pre_option_active_plugins` filter to remove excluded plugins. All exclusion logic is defined in [`includes/helpers/functions-mu-plugin.php`](includes/helpers/functions-mu-plugin.php), required only by the MU plugin.

Both `active_plugins` and `cron` options are fetched in a **single DB query** via `frl_get_exclusion_options()` to minimize overhead.

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

> **Note on Cron**: During WP Cron, there is no authenticated user, so capability-based exclusion always blocks the target plugin. To prevent `invalid_schedule` errors (WordPress trying to reschedule events whose schedules were never registered), a `pre_option_cron` filter strips orphaned events from the cron array before WordPress processes them. This filter is non-destructive — it does not modify the database.

## DB Query Optimization

Both `pre_option_active_plugins` and `pre_option_cron` filters share a single database query through [`frl_get_exclusion_options()`](includes/helpers/functions-mu-plugin.php:37):

```php
$rows = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT option_name, option_value 
         FROM {$wpdb->options} 
         WHERE option_name IN (%s, %s)",
        'active_plugins',
        'cron'
    )
);
```

- On non-cron requests, only `active_plugins` is actually used (cron data is fetched but never accessed).
- On cron requests, both values are already in memory — zero additional DB queries.
- Results are cached in a static variable for per-request deduplication.

## Cron Event Cleanup

When a plugin is excluded from loading, its custom cron schedules never get registered. WordPress would otherwise log `invalid_schedule` errors when trying to reschedule those events. The [`frl_add_exclusion_filter_cron()`](includes/helpers/functions-mu-plugin.php:248) function adds a `pre_option_cron` filter that:

1. Retrieves the cron data (already fetched alongside active_plugins — no extra query).
2. Gets all currently registered schedules via `wp_get_schedules()`.
3. Removes events whose `schedule` name does not exist in the registered schedules.
4. Returns the filtered array for WordPress to process.

This filter is only added during WP Cron requests and is completely non-destructive — the database is never modified. If the exclusion is later removed, the plugin will load, register its schedules, and its cron events will work again.

Additionally, as a safety measure, the filter sanitizes `args` on every cron event to ensure it is always an array. This prevents `TypeError: count(): Argument #1 must be of type Countable|array, null given` in `class-wp-hook.php:325` when `do_action_ref_array` at `wp-cron.php:191` receives null args — a pre-existing WordPress issue that can occur when a cron event was stored with `args => null` in the database.

## Files

| File | Purpose |
|------|---------|
| `assets/mu/frl-mu-plugin.php` | MU loader bootstrap — defines `FRL_MU_NAME`, loads bootstrap + helpers, registers `muplugins_loaded` hook |
| `includes/helpers/functions-mu-plugin.php` | All exclusion logic — combined DB query, exclusion types, cron cleanup |
| `includes/helpers/functions-access-control.php` | Contains `frl_is_admin_page()` used by backend exclusion |
