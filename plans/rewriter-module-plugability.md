# Rewriter Module Plugability — Architectural Analysis & Implementation

## 1. Current State Assessment

### 1.1 Rewriter Feature System

The rewriter has an excellent **feature-based architecture** with:

- [`Frl_Rewriter_Feature_Interface`](includes/core/rewriter/interface-feature.php) — 11-method contract
- [`Frl_Rewriter_Feature_Base`](includes/core/rewriter/features/abstract-base-feature.php) — abstract base with catch-all infrastructure, re-entrancy guards, pattern conflict detection
- [`Frl_Rewriter_Coordinator`](includes/core/rewriter/class-rewriter-coordinator.php) — singleton that discovers features, sorts by priority, registers hooks
- **Extension point**: `do_action('frl_rewriter_register_features', $this)` — now fires at `plugins_loaded/7`

### 1.2 Module System

- Modules discovered via `FRL_ENV_DEFAULT['modules']` (environment config)
- Module files loaded at `plugins_loaded/5` inside `frl_modules_init()` (called from `frl_plugins_loaded()`)
- Each module is a directory under `modules/<key>/` with a `<key>.php` entry point

### 1.3 The Original Timing Gap (SOLVED)

Before the fix, the `frl_rewriter_register_features` action fired **synchronously** in the coordinator constructor (at `plugins_loaded/5`), which was **before** module files were loaded. A module could not use `self_register()` or `add_feature()` because the action had already been dispatched.

### 1.4 The Fix

The `do_action()` and `usort()` were moved from the coordinator constructor to a `plugins_loaded/7` action hook — after `frl_modules_init()` completes. This is a **~3-line move** with verifiable zero regressions.

## 2. Implementation

### 2.1 What Changed

**File**: [`includes/core/rewriter/class-rewriter-coordinator.php`](includes/core/rewriter/class-rewriter-coordinator.php)

- `register_features()`: removed `do_action()` + `usort()` — now only calls `create_all_features()`
- `register_hooks()`: added `plugins_loaded/7` hook that fires the action and sorts features

### 2.2 New Execution Order

```
plugins_loaded/5:  Coordinator created
                   └─ create_all_features()  (built-in features instantiated)
                   └─ frl_modules_init()     (module entry points execute
                      └─ module calls $coordinator->add_feature())

plugins_loaded/7:  do_action('frl_rewriter_register_features', $this)
                   └─ usort() by priority (includes module features)

init/15:           feature->register() for all features
```

### 2.3 How Modules Register Features

```php
// In module entry point (modules/my-module/my-module.php):
add_action('frl_rewriter_register_features', function ($coordinator) {
    $coordinator->add_feature(new My_Custom_Rewrite_Feature());
});
```

Or directly:

```php
$coordinator = Frl_Rewriter_Coordinator::init();
$coordinator->add_feature(new My_Custom_Rewrite_Feature());
```

Priority defaults to **99** (lowest) for any feature not in `FRL_REWRITER_PRIORITIES`.

## 3. Verification

- [x] Module can register a feature at `plugins_loaded/5` → included in sort at `plugins_loaded/7`
- [x] Module-registered feature is in correct priority order relative to built-in features
- [x] All built-in features unchanged — same `self_register()` flow, same priorities
- [x] URL transformation pipeline includes module-registered features (additive by design)
- [x] No new public methods or hook names — zero new API surface
- [x] No external code depends on previous timing (only 2 internal references)

## 4. Files Modified

| File | Change |
|------|--------|
| [`includes/core/rewriter/class-rewriter-coordinator.php`](includes/core/rewriter/class-rewriter-coordinator.php) | Moved `do_action()` + `usort()` from constructor to `plugins_loaded/7` |
| [`docs/REWRITER.md`](docs/REWRITER.md) | Updated bootstrap flow + added "Module Plugability" section |

## 5. No Changes Needed

- `fralenuvole.php` — init flow stays the same
- `config/config-rewriter.php` — unchanged
- `includes/bootstrap.php` — unchanged
- Existing feature classes — unchanged
- `abstract-base-feature.php` — unchanged
- Module system — unchanged
