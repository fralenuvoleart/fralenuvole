# Cron Corruption Cycle — Analysis & Fix Plan

## Error Summary

```
[01-May-2026 10:24:34 UTC] Cron unschedule event error for hook: wp_site_health_scheduled_check,
Error code: could_not_set, Error message: The cron event list could not be saved.,
Data: {"dcca48101505dd86b703689a604fe3c4":{"dcca48101505dd86b703689a604fe3c4":{...100+ levels...
```

## Root Cause Analysis

### The Corruption Mechanism

The error data shows exponential nesting of the hash `dcca48101505dd86b703689a604fe3c4`, which is `md5(serialize(null))` = `md5('N;')`. This is the classic WordPress `_upgrade_cron_array()` corruption pattern.

**How it starts and propagates:**

1. WordPress v2 cron format is: `$cron[timestamp][hook_name][md5(serialize(args))] = $event_data`
2. A `version => 2` key at the top level tells `_get_cron_array()` that the data is already in v2 format
3. If the `version` key is **missing**, `_get_cron_array()` calls `_upgrade_cron_array()` (see [`_get_cron_array()`](https://developer.wordpress.org/reference/functions/_get_cron_array/))
4. `_upgrade_cron_array()` misinterprets v2 hash keys as v1 hook names and each event hash-group as `$args`. It wraps each group in a new md5 layer and **saves the corruption back to DB** via `update_option('cron', ...)` (see [`_upgrade_cron_array()`](https://developer.wordpress.org/reference/functions/_upgrade_cron_array/))
5. On the next request, the corrupt data lacks `version` → cycle repeats → **exponential data growth**

### The `could_not_set` Error

The error comes from [`_set_cron_array()`](https://developer.wordpress.org/reference/functions/_set_cron_array/) when `update_option('cron', ...)` returns `false`. This happens because:

1. `_upgrade_cron_array()` already saved the corrupt data to DB just milliseconds earlier
2. `_set_cron_array()` tries to save the same corrupt data (after an unschedule operation that found no matching event in the deeply nested structure)
3. `update_option()` compares new value with DB value → they match → returns `false`

### Previous Bug: Rebuilding the Array from Scratch

The original [`frl_add_exclusion_filter_cron()`](includes/helpers/functions-mu-plugin.php:387) rebuilt the entire cron array from scratch using a `$filtered` accumulator. This required:
1. Iterating all top-level keys (including `'version' => 2`)
2. Skipping `version` because it's not an array (`!is_array($hooks)`)
3. **Explicitly re-adding** `$filtered['version']` after the loop

This was fragile — if the version preservation step was conditional on the input having a `version` key, corrupt data (which lacks it) would cause the output to also lack it, perpetuating the corruption cycle.

### The Fix: In-Place Modification

The root insight — **"what goes in, goes out"** — led to a simpler approach:

1. **Don't rebuild the array** — modify `$cron` in-place using `unset()` for orphaned events
2. **Only skip non-array values** — `!is_array($hooks)` check skips `'version' => 2` naturally (2 is not an array), and the key stays in `$cron` since we never remove it
3. **No explicit version handling needed** — the `version` key passes through because the foreach never processes it
4. **No corrupted data cleanup** — corrupted entries from existing DB corruption pass through unchanged. The filter only removes orphaned events and fixes null args. Clean existing corruption with `wp option delete cron`

## What Changed

### File: [`includes/helpers/functions-mu-plugin.php`](includes/helpers/functions-mu-plugin.php)

**Before:** Rebuilt `$filtered` array from scratch, with conditional version preservation
**After:** In-place modification of `$cron` with `!is_numeric($timestamp)` guard to skip metadata keys

Key differences:

| Aspect | Before | After |
|---|---|---|
| Approach | Rebuild `$filtered = []` accumulator | Modify `$cron` in-place with `unset()` |
| Version handling | `if (isset($cron['version'])) { $filtered['version'] = $cron['version']; }` | Not needed — `version` is never touched (`!is_numeric` skips it) |
| Orphan removal | Event skipped in accumulator | `unset($cron[$timestamp][$hook][$hash])` |
| Args fix | Copied with modified `$event['args']` to accumulator | Direct assignment: `$cron[$timestamp][$hook][$hash]['args'] = []` |
| Empty cleanup | Accumulator naturally skips empty containers | Explicit `unset` for empty hooks/timestamps |
| Robustness | Version can be accidentally dropped if preservation logic is wrong | Version is never processed, never dropped |

### Manual DB Cleanup

The existing corrupt data in the database must be deleted. After that, the filter keeps clean data clean:

```bash
wp option delete cron
```

Or via SQL:
```sql
DELETE FROM wp_options WHERE option_name = 'cron';
```
