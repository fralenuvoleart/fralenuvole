# Cache Orchestrator — Implementation Plan

## Design Goal

Create a [`Frl_Cache_Orchestrator`](includes/core/cache/class-cache-orchestrator.php) that centralizes the **sequencing** of composite cache operations, while preserving the existing helper functions (`frl_cache_clear()`, `frl_schedule_admin_rewrite_flush()`, etc.) as the routing layer to their respective subsystems.

The orchestrator answers: **"What happens step-by-step when I run operation X?"** — in one place, at runtime.

---

## 1. What the Orchestrator IS and IS NOT

| IS | IS NOT |
|---|---|
| A central registry of operation step sequences | A replacement for `frl_cache_clear()` or `Frl_Cache_Manager` |
| A runtime dispatcher that calls existing helper functions in order | A new cache storage mechanism |
| A provider of before/after lifecycle hooks for each operation | A replacement for `Frl_Rewriter::flush_rules()` |
| An aggregator of step results for caller consumption | A change to how individual cache groups are cleared |

The existing helpers remain untouched. The orchestrator just sequences them.

---

## 2. File Location

```
includes/core/cache/class-cache-orchestrator.php
```

Loaded from [`includes/bootstrap.php`](includes/bootstrap.php) alongside the existing cache subsystem.

---

## 3. Class Structure

```php
final class Frl_Cache_Orchestrator {
    
    /**
     * Operation definitions.
     * Each operation is an ordered list of steps.
     * Each step calls an existing helper function.
     * 'critical' = true means a step failure aborts the remaining steps.
     */
    const FRL_CACHE_OPERATIONS = [
        'hard' => [
            'label' => 'Hard Cache Reset',
            'steps' => [
                ['fn' => 'frl_cache_clear',              'args' => ['hard'], 'critical' => true],
                ['fn' => 'frl_schedule_admin_rewrite_flush', 'args' => [],     'critical' => false],
            ],
            'hooks' => [
                'before' => 'frl_before_cache_operation_hard',
                'after'  => 'frl_after_cache_operation_hard',
            ],
        ],

        'flush_rewrite_rules' => [
            'label' => 'Rewrite Rules Flush',
            'steps' => [
                ['fn' => 'frl_execute_rewrite_flush',    'args' => [],        'critical' => true],
            ],
            'hooks' => [
                'before' => 'frl_before_cache_operation_flush_rewrite_rules',
                'after'  => 'frl_after_cache_operation_flush_rewrite_rules',
            ],
        ],

        'all' => [
            'label' => 'Purge All Caches',
            'steps' => [
                ['fn' => 'frl_cache_clear',              'args' => ['all'],   'critical' => true],
            ],
            'hooks' => [
                'before' => 'frl_before_cache_operation_all',
                'after'  => 'frl_after_cache_operation_all',
            ],
        ],

        'light' => [
            'label' => 'Light Cache Purge',
            'steps' => [
                ['fn' => 'frl_cache_clear',              'args' => ['light'], 'critical' => true],
            ],
            'hooks' => [
                'before' => 'frl_before_cache_operation_light',
                'after'  => 'frl_after_cache_operation_light',
            ],
        ],
    ];

    /**
     * Run a named cache operation.
     *
     * @param string $operation Key in self::FRL_CACHE_OPERATIONS.
     * @return array{operation: string, steps: array, success: bool}
     */
    public static function run(string $operation): array {
        // ...
    }

    /**
     * Get all registered operations with their step descriptions.
     * For documentation, debugging, and admin UI.
     */
    public static function get_operation_map(): array {
        // ...
    }
}
```

---

## 4. The `run()` Method — Detailed Logic

```php
public static function run(string $operation): array {
    // 1. Validate operation exists
    $config = self::FRL_CACHE_OPERATIONS[$operation] ?? null;
    if (!$config) {
        frl_log('Unknown cache operation: {op}', ['op' => $operation]);
        return ['operation' => $operation, 'success' => false, 'error' => 'Unknown operation'];
    }

    // 2. Re-entrancy guard (exactly like Frl_Cache_Manager uses)
    if (frl_is_already_running(__METHOD__ . '_' . $operation)) {
        frl_log('Cache operation already running: {op}', ['op' => $operation]);
        return ['operation' => $operation, 'success' => false, 'error' => 'Already running'];
    }

    $results = [
        'operation' => $operation,
        'label'     => $config['label'],
        'steps'     => [],
        'success'   => true,
    ];

    // 3. Before hook
    if (isset($config['hooks']['before'])) {
        do_action($config['hooks']['before'], $operation);
    }

    // 4. Execute steps in order
    foreach ($config['steps'] as $index => $step) {
        $fn   = $step['fn'];
        $args = $step['args'] ?? [];
        $critical = $step['critical'] ?? false;

        $step_result = [
            'step'  => $index + 1,
            'function' => $fn,
            'args'     => $args,
            'success'  => false,
        ];

        if (function_exists($fn)) {
            try {
                $returned = call_user_func_array($fn, $args);
                $step_result['success'] = true;
                $step_result['result']  = $returned;
            } catch (Throwable $e) {
                $step_result['error'] = $e->getMessage();
                frl_log('Cache orchestrator step failed: {fn} - {error}', [
                    'fn'    => $fn,
                    'error' => $e->getMessage(),
                ]);
            }
        } else {
            $step_result['error'] = "Function {$fn} not found";
            frl_log('Cache orchestrator step function missing: {fn}', ['fn' => $fn]);
        }

        $results['steps'][] = $step_result;

        // Abort on critical failure
        if (!$step_result['success'] && $critical) {
            $results['success'] = false;
            break;
        }
    }

    // 5. After hook
    if (isset($config['hooks']['after'])) {
        do_action($config['hooks']['after'], $operation, $results);
    }

    frl_is_already_running(__METHOD__ . '_' . $operation, true);

    return $results;
}
```

---

## 5. What Changes in the Action Handlers

### Before (current) — [`frl_handle_action_clear_cache_hard()`](includes/helpers/functions-action-handlers.php:263)

```php
function frl_handle_action_clear_cache_hard() {
    if (!frl_has_access()) { ... }

    $stats = frl_cache_clear('hard');
    frl_schedule_admin_rewrite_flush();

    // Build message from $stats
    $message_parts = ['<strong>' . __('Hard Cache Reset', FRL_PREFIX) . '</strong>'];
    if (frl_is_array_not_empty($stats, 'plugin_internal_purge')) { ... }
    $oc_flush_status = $stats['wp_object_cache_global_flush'] ?? 'unknown';
    ...
    $message_parts[] = __('- WordPress rewrite rules flushed.', FRL_PREFIX);

    return ['success' => true, 'message_parts' => $message_parts, 'notice_type' => 'success'];
}
```

### After

```php
function frl_handle_action_clear_cache_hard() {
    if (!frl_has_access()) { ... }

    $orchestrated = Frl_Cache_Orchestrator::run('hard');

    if (!$orchestrated['success']) {
        return ['success' => false, 'message_parts' => [__('Hard cache reset failed.', FRL_PREFIX)], 'notice_type' => 'error'];
    }

    // Extract stats from the hard step result
    $stats = $orchestrated['steps'][0]['result'] ?? [];

    // Build message from $stats (same logic as before)
    $message_parts = ['<strong>' . __('Hard Cache Reset', FRL_PREFIX) . '</strong>'];
    if (frl_is_array_not_empty($stats, 'plugin_internal_purge')) { ... }
    ...

    return ['success' => true, 'message_parts' => $message_parts, 'notice_type' => 'success'];
}
```

### Before (current) — [`frl_handle_action_flush_rewrite_rules()`](includes/helpers/functions-action-handlers.php:309)

```php
function frl_handle_action_flush_rewrite_rules() {
    frl_schedule_admin_rewrite_flush();
    return [ ... ];
}
```

### After

```php
function frl_handle_action_flush_rewrite_rules() {
    Frl_Cache_Orchestrator::run('flush_rewrite_rules');
    return [ ... ];
}
```

---

## 6. What Changes in the Lifecycle Functions

### `frl_auto_backup_on_upgrade()` — [`includes/plugin-lifecycle.php:108`](includes/plugin-lifecycle.php:108)

Currently calls `frl_schedule_admin_rewrite_flush()` directly. This is a **single-step** operation, so it doesn't need orchestrator routing. But it COULD if we define an `upgrade_complete` operation. Let's keep it calling the helper directly for now — the orchestrator is for composite operations (multi-step).

Actually, let me reconsider. The orchestrator's value is in making multi-step sequences visible. Single-step operations don't benefit. So:

- `hard` → orchestrator (2 steps: cache_clear + schedule_flush)
- `flush_rewrite_rules` → orchestrator (1 step: frl_execute_rewrite_flush, but includes before/after hooks for observability)
- `all` → orchestrator (1 step, but adds lifecycle hooks)
- `light` → orchestrator (1 step, but adds lifecycle hooks)
- Standalone `frl_schedule_admin_rewrite_flush()` calls → keep calling helper directly

---

## 7. Where the Orchestrator is Called

| Current call | New call |
|---|---|
| [`frl_handle_action_clear_cache_hard()`](includes/helpers/functions-action-handlers.php:263) | `Frl_Cache_Orchestrator::run('hard')` |
| [`frl_handle_action_flush_rewrite_rules()`](includes/helpers/functions-action-handlers.php:309) | `Frl_Cache_Orchestrator::run('flush_rewrite_rules')` |
| [`frl_handle_action_clear_cache_all()`](includes/helpers/functions-action-handlers.php:237) | `Frl_Cache_Orchestrator::run('all')` |
| [`frl_handle_action_clear_cache_light()`](includes/helpers/functions-action-handlers.php:213) | `Frl_Cache_Orchestrator::run('light')` |
| `frl_auto_backup_on_upgrade()` line 108 | Keep calling `frl_schedule_admin_rewrite_flush()` directly (single step) |
| Third-party inbound hooks calling `frl_schedule_rewrite_flush()` | Keep calling directly (single step) |

---

## 8. Benefits Summary

| Concern | How the orchestrator solves it |
|---|---|
| **Sequence visibility** | `FRL_CACHE_OPERATIONS` constant lists every step in order |
| **Cross-file orchestration** | One class, one file to read |
| **Debugging** | Lifecycle hooks (`frl_before_*/frl_after_*`) allow logging/interception |
| **Step failure isolation** | `critical` flag per step; non-critical failures don't abort |
| **Code duplication** | `hard` sequence defined once, not re-declared in the action handler |
| **Future operations** | Add a new entry to `FRL_CACHE_OPERATIONS` + wire the UI button |
| **Existing helpers preserved** | `frl_cache_clear()`, `frl_schedule_admin_rewrite_flush()` etc. unchanged |

---

## 9. Migration Steps (for implementation)

1. **Create** [`includes/core/cache/class-cache-orchestrator.php`](includes/core/cache/class-cache-orchestrator.php) with the class above
2. **Load** it in [`includes/bootstrap.php`](includes/bootstrap.php) after the cache manager load
3. **Update** [`frl_handle_action_clear_cache_hard()`](includes/helpers/functions-action-handlers.php:263) to use orchestrator
4. **Update** [`frl_handle_action_flush_rewrite_rules()`](includes/helpers/functions-action-handlers.php:309)
5. **Update** [`frl_handle_action_clear_cache_all()`](includes/helpers/functions-action-handlers.php:237)
6. **Update** [`frl_handle_action_clear_cache_light()`](includes/helpers/functions-action-handlers.php:213)
7. **Verify** message building logic still works with the new result format
8. **Test** each action handler path end-to-end
