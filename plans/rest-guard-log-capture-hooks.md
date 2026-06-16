# Plan: REST API Guard for Log Capture Hooks

## Problem

Four log capture hooks in [`includes/main.php:33-36`](includes/main.php:33) fire on every block render and shortcode execution during block editor REST API requests (preview, autosave, save). These hooks maintain `$GLOBALS['frl_block_stack']`, `$GLOBALS['frl_current_query_vars']`, and `$GLOBALS['frl_last_shortcode']` — globals that are only consumed by [`frl_log_add_details()`](includes/helpers/functions-error-log.php:283) during frontend error logging. During REST API requests, these hooks perform zero-value work while adding measurable per-block overhead.

## Root Cause

The 4 hook registrations have no REST API guard. They fire unconditionally:

```php
// includes/main.php:33-36 (current)
add_filter('render_block_data', 'frl_log_capture_render_block_enter', 10, 1);
add_filter('render_block', 'frl_log_capture_render_block_exit', 10, 2);
add_action('pre_get_posts', 'frl_log_capture_query', 1, 1);
add_filter('do_shortcode_tag', 'frl_log_capture_shortcode', 10, 4);
```

A post with 200 blocks generates ~400 hook invocations (enter+exit per block). Posts using Greenshift generate hundreds more via `do_shortcode_tag`. Each invocation does array_flip lookups, attribute filtering, array_map stringification, and global stack manipulation.

## Fix

Wrap the 4 hook registrations in a REST API guard:

```php
// includes/main.php:33-36 (new)
// Log capture hooks for enriched error context.
// Skipped for REST API (block editor preview/save) where per-block stack
// manipulation provides zero value and adds measurable per-block overhead.
if (!frl_is_rest_api_request()) {
    add_filter('render_block_data', 'frl_log_capture_render_block_enter', 10, 1);
    add_filter('render_block', 'frl_log_capture_render_block_exit', 10, 2);
    add_action('pre_get_posts', 'frl_log_capture_query', 1, 1);
    add_filter('do_shortcode_tag', 'frl_log_capture_shortcode', 10, 4);
}
```

## Safety Analysis

1. [`frl_is_rest_api_request()`](includes/helpers/functions-access-control.php:366) checks `str_starts_with($_SERVER['REQUEST_URI'], '/wp-json/')` first — available at `plugins_loaded` time before the `REST_REQUEST` constant is defined.

2. The globals (`frl_block_stack`, `frl_current_query_vars`, `frl_last_shortcode`) are only read by [`frl_log_add_details()`](includes/helpers/functions-error-log.php:283) which is only invoked during error logging, gated behind `WP_DEBUG_LOG` in the error handler. During REST, error logging still works — it just won't have block/shortcode context (irrelevant to API calls).

3. Hook registrations happen on every request (not persisted). Not registering them on REST requests has no effect on non-REST requests.

4. Zero data loss, zero behavioral change for frontend rendering or error logging.

## Files Changed

| File | Change |
|---|---|
| [`includes/main.php`](includes/main.php:33-36) | Wrap 4 hook registrations in `if (!frl_is_rest_api_request())` |

## Regression Risk

**None.** The REST guard is purely subtractive — it removes hooks during REST requests where they serve no purpose. The guards used elsewhere in this codebase for REST bypass (`class-rewriter.php:174`, `class-subdomain-adapter.php:709`) use the same pattern with the same `frl_is_rest_api_request()` function.
