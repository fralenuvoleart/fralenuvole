# Fix: `option_cron` Filter Strips `version` Key — Exponential Corruption + Thousands of Warnings

## Corrected Root Cause Analysis

### The Bug

Both [`frl_add_cron_stability_filter()`](includes/mu/functions-mu-plugin.php:471) and [`frl_add_exclusion_filter_cron()`](includes/mu/functions-mu-plugin.php:387) register an `option_cron` filter that iterates the cron array with:

```php
foreach ($cron as $timestamp => $hooks) {
    if (!is_array($hooks)) {
        continue;    // ← drops 'version' => 2 (integer, not array)
    }
    ...
}
```

Because `'version' => 2` is an integer, the `!is_array($hooks)` check causes it to be silently dropped from the filtered output.

### The Exact Warning + Corruption Chain

WordPress core [`_get_cron_array()`](https://raw.githubusercontent.com/WordPress/WordPress/master/wp-includes/cron.php) (around line 1270):

```php
function _get_cron_array() {
    $cron = get_option('cron');               // ← option_cron filter strips version here
    if (!isset($cron['version'])) {            // ← TRUE because filter stripped it
        $cron = _upgrade_cron_array($cron);    // ← runs EVERY request!
    }
    unset($cron['version']);
    return $cron;
}
```

When version is missing, `_upgrade_cron_array()` (around line 1286) does:

```php
function _upgrade_cron_array($cron) {
    if (isset($cron['version']) && 2 === $cron['version']) {
        return $cron;   // ← would return immediately if version were present
    }

    $new_cron = array();
    foreach ((array) $cron as $timestamp => $hooks) {
        foreach ((array) $hooks as $hook => $args) {
            $key = md5(serialize($args['args']));  // ← LINE 1298 WARNING
            $new_cron[$timestamp][$hook][$key] = $args;
        }
    }
    $new_cron['version'] = 2;
    update_option('cron', $new_cron, true);   // ← SAVES corrupted data to DB!
    return $new_cron;
}
```

#### Why This Is NOT About Events Missing `args`

In **v2-format** cron data (the standard format since WP 5.1), the structure is:

```php
$cron = [
    1620000000 => [
        'my_hook' => [
            '40cd750bba9870f18aada2478b24840a' => [   // ← md5 hash KEY
                'schedule' => 'hourly',
                'args'     => [],                      // ← event data VALUE
                'interval' => 3600,
            ],
        ],
    ],
    'version' => 2,
];
```

The inner `foreach ((array) $hooks as $hook => $args)` iterates `$hooks` where **`$args` is the hash-to-event MAP** (`['40cd75...' => $event_data]`), NOT the event data itself.

So `$args['args']` accesses key `'args'` on a map that only has md5 hash keys. Since the hash `'40cd75...'` is not `'args'`, PHP returns `null` with the warning.

| Hash | Value | Meaning |
|------|-------|---------|
| `40cd750bba9870f18aada2478b24840a` | `a:0:{}` | `md5(serialize([]))` — proper empty args |
| `dcca48101505dd86b703689a604fe3c4` | `N;` | `md5(serialize(null))` — **corruption marker** |

#### The Exponential Corruption

1. `$args['args']` returns `null` → warning at line 1298
2. `$key = md5(serialize(null))` = `md5('N;')` = **`dcca48101505dd86b703689a604fe3c4`**
3. `$new_cron[$timestamp][$hook]['dcca4810...'] = $args` → wraps the hash map in ANOTHER hash layer
4. `update_option('cron', $new_cron)` → saves corrupted+version data to DB
5. **Next request**: filter strips version → `_upgrade_cron_array()` sees deeper nesting → same cycle → **another `dcca4810...` wrapper added**

Each page load adds one nesting level. The `cron` option grows exponentially.

### Why "Thousands" of Warnings

- `_get_cron_array()` is called on EVERY WordPress page load
- Each corrupted event (including the nested wrappers) generates a warning
- The corrupted data grows bigger each request, creating more iterations
- The MU plugin's error handler (loaded via [`bootstrap.php:48`](includes/bootstrap.php:48)) logs everything with full context

### Why It Only Happens When the MU Plugin Is Enabled

The MU plugin at [`assets/mu/frl-mu-plugin.php:33`](assets/mu/frl-mu-plugin.php:33) calls `frl_add_cron_stability_filter()` unconditionally. Without it, `_get_cron_array()` receives the cron option intact with `version = 2`, and `_upgrade_cron_array()` never fires.

---

## Fix Plan

### Issue 1: Preserve `version` key in `frl_add_cron_stability_filter()` — CRITICAL

**File:** [`includes/mu/functions-mu-plugin.php`](includes/mu/functions-mu-plugin.php:490)

After the `foreach` loop (around line 521), before `$cache = $filtered;`, add:

```php
// Preserve the 'version' metadata key that WordPress core relies on.
// Without it, _get_cron_array() calls _upgrade_cron_array() on every request,
// which misinterprets the v2 hash-to-event map as v1 event data,
// triggering an "Undefined array key args" warning and causing exponential
// data corruption (wrapping events in md5(serialize(null)) hash layers).
if (isset($cron['version'])) {
    $filtered['version'] = $cron['version'];
}
```

### Issue 2: Preserve `version` key in `frl_add_exclusion_filter_cron()` — CRITICAL

**File:** [`includes/mu/functions-mu-plugin.php`](includes/mu/functions-mu-plugin.php:414)

Same fix — after the `foreach` loop (around line 455), before `$cache = $filtered;`, add:

```php
if (isset($cron['version'])) {
    $filtered['version'] = $cron['version'];
}
```

### Issue 3: Clean up already-corrupted cron data in the database — CRITICAL

**✅ Applied** — Old `frl_add_cron_stability_filter()` function replaced with `frl_cleanup_corrupted_cron()` + `add_action('admin_init', ...)` hook in [`functions-mu-plugin.php`](../includes/mu/functions-mu-plugin.php).

See the function below for the implementation.

The cron option in the DB already has layered `dcca4810...` wrappers. After the code fix is deployed, the cron option will still be bloated. Two approaches:

**Approach A (Recommended — safest):** Add a one-time cleanup hook that runs on the first request after the fix:

```php
// In functions-mu-plugin.php or a suitable location
add_action('admin_init', function () {
    $option_updated = get_option('frl_cron_cleanup_done', false);
    if ($option_updated) {
        return;
    }

    $cron = get_option('cron');
    if (!is_array($cron)) {
        update_option('frl_cron_cleanup_done', true);
        return;
    }

    $changed = false;
    foreach ($cron as $timestamp => $hooks) {
        if ($timestamp === 'version') {
            continue;
        }
        if (!is_array($hooks)) {
            continue;
        }
        foreach ($hooks as $hook => $events) {
            if (!is_array($events)) {
                continue;
            }
            // Flatten one level of dcca48... corruption wrappers
            foreach ($events as $hash => $event) {
                if ($hash === 'dcca48101505dd86b703689a604fe3c4' && is_array($event)) {
                    // This event is wrapped in a corruption layer — unwrap it
                    foreach ($event as $real_hash => $real_event) {
                        $cron[$timestamp][$hook][$real_hash] = $real_event;
                    }
                    unset($cron[$timestamp][$hook][$hash]);
                    $changed = true;
                }
            }
        }
    }

    if ($changed) {
        // Re-run the cleanup recursively until no more corruption layers
        // (handles multiple layers from multiple requests)
        for ($i = 0; $i < 100; $i++) {
            $again = false;
            foreach ($cron as $timestamp => $hooks) {
                if ($timestamp === 'version' || !is_array($hooks)) continue;
                foreach ($hooks as $hook => $events) {
                    if (!is_array($events)) continue;
                    foreach ($events as $hash => $event) {
                        if ($hash === 'dcca48101505dd86b703689a604fe3c4' && is_array($event)) {
                            foreach ($event as $real_hash => $real_event) {
                                $cron[$timestamp][$hook][$real_hash] = $real_event;
                            }
                            unset($cron[$timestamp][$hook][$hash]);
                            $again = true;
                        }
                    }
                }
            }
            if (!$again) break;
        }

        $cron['version'] = 2; // ensure version is preserved
        update_option('cron', $cron, true);
    }

    update_option('frl_cron_cleanup_done', true);
}, 99);
```

**Approach B (Manual):** Run SQL to inspect the cron option size, then manually repair.

**Approach C (WP-CLI):** Use WP-CLI to inspect and repair:
```bash
wp option get cron --format=json | python3 -c "
import sys, json
data = json.load(sys.stdin)
# Recursively unwrap dcca48... corruption layers
...
"
```

---

## Verification

After the fix:
1. `_get_cron_array()` receives `version = 2` intact → `_upgrade_cron_array()` NOT called
2. Line 1298 never reached → warnings eliminated
3. No more DB writes from `_upgrade_cron_array()` on every page load
4. Once repaired, the cron option stays clean

---

## Security & Regression Considerations

| Concern | Analysis |
|---------|----------|
| Cron integrity | `version` is preserved as-is, no modification |
| Args sanitization | Still works for events — only the `version` bypass is fixed |
| `_upgrade_cron_array()` | Never triggered during normal operation since version is preserved |
| Static cache | Both filters use static cache; cached value includes `version`, so subsequent calls within same request also preserve it |
| DB write reduction | Eliminates `update_option('cron')` on every page load — was happening via `_upgrade_cron_array()` |
| Corruption repair | One-time cleanup needed; after that, cron stays clean |

---

## Emergency Severity: CRITICAL

This is not "thousands of warnings" — it's **active data corruption on every page load** that compounds exponentially. The cron option will grow until it exceeds MySQL's `max_allowed_packet` or exhausts memory. Immediate fix required.
