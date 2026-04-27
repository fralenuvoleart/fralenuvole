# Fix: Unnecessary Cron DB Query in `frl_get_exclusion_options()`

## Root Cause

In [`frl_get_exclusion_options()`](includes/helpers/functions-mu-plugin.php:30), the `cron` option was fetched via a **fresh `$wpdb->get_var()` query + `maybe_unserialize()` on every single request** — regardless of whether the request was a WP-Cron execution or a regular page load.

The cron data is **only consumed** by the cron filter closure at [`frl_add_exclusion_filter_cron()`](includes/helpers/functions-mu-plugin.php:280), which is **only registered during cron requests** (guarded by `if (frl_is_cron_job_request())` at line 170). On all non-cron requests, the fetched cron data was silently discarded.

When any exclusion tier was enabled, `frl_get_exclusion_options()` was called (inside `pre_option_active_plugins` filter), triggering this unnecessary query on every page load — causing ~5 seconds of slowdown.

## Fix

Guard the cron fetch behind `frl_is_cron_job_request()` — only query cron during actual cron runs:

```php
// In frl_get_exclusion_options(), replace lines 61-72:

if (frl_is_cron_job_request()) {
    // Fetch cron fresh (volatile data) — changes on every cron execution.
    // Only fetched during actual cron runs — not on regular page loads.
    $cron_value = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            'cron'
        )
    );
    $options['cron'] = $cron_value ? (array) maybe_unserialize($cron_value) : [];
} else {
    $options['cron'] = [];
}
```

## Why This Is Safe

1. **Cron data is never read on non-cron requests** — The only consumer is `frl_add_exclusion_filter_cron()`, which is registered only when `frl_is_cron_job_request()` is true (line 170).
2. **During cron runs, the data is still fetched fresh** — preserving the original intent of "no stale cron data."
3. **Zero behavior change** — The code path for cron requests is identical. The code path for non-cron requests now skips unnecessary work.

## Impact

| Before | After |
|--------|-------|
| Every page load: 1× `$wpdb->get_var()` for `cron` + `maybe_unserialize()` | Zero extra cost on non-cron requests |
| 5-second slowdown with exclusions enabled | No measurable overhead |

## Files to Modify

- [`includes/helpers/functions-mu-plugin.php`](includes/helpers/functions-mu-plugin.php:61) — Guard cron fetch behind `frl_is_cron_job_request()`

## Revert Debug Code

Before applying the fix, remove the debug lines (61-73) that currently comment out the cron query and set `$options['cron'] = [];`
