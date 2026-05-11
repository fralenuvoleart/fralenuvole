# Fralenuvole Hook Priorities

This document defines the critical hook priorities for Fralenuvole. **Violating these priorities can cause critical bugs** including:
- Environment settings applied AFTER rewriter reads options
- Rewrite rules registered before CPTs exist
- Options read before plugin is fully initialized

## Critical Hook Sequence

### plugins_loaded (Priority 5)
```php
add_action('plugins_loaded', 'frl_plugins_loaded', 5, 0);
```
**Purpose:** Loads core infrastructure before any components.

**What happens:**
1. `frl_cache_is_loaded()` check
2. `frl_load_core_components()` - loads translator, rewriter, themekit
3. `frl_environment_init()` - registers hooks only
4. `frl_modules_init()` - conditional module loading

---

### init (Priority 10)
```php
add_action('init', 'frl_environment_enforce_settings', 10, 0);
```
**Purpose:** Environment enforcement MUST run first.

**What happens:**
1. `pre_option_*` filters activate
2. Plugin activation/deactivation enforced
3. All option reads now return environment-overridden values

**⚠️ CRITICAL:** Any hook at `init` with priority < 10 will read RAW database options, NOT environment-overridden values.

---

### init (Priority 15)
```php
// Internal: Frl_Rewriter_Coordinator registers features
add_action('init', function() {
    foreach ($this->features as $feature) {
        $feature->register();
    }
}, 15, 0);
```
**Purpose:** Rewriter features register AFTER environment enforcement.

**What happens:**
1. Features read options (now correctly intercepted by `pre_option_*` filters)
2. `add_rewrite_rule()` calls execute
3. Features store their configuration hash

---

### init (Priority 20)
```php
add_action('init', 'frl_themekit_init', 20, 0);
```
**Purpose:** Theme kit initialization.

---

### init (Priority 100)
```php
add_action('init', 'frl_themekit_remove_provider_block_patterns', 100, 0);
```
**Purpose:** Remove third-party block patterns.

---

### init (Priority 200)
```php
add_action('init', 'frl_flush_rewrite_rules', 200, 0);
```
**Purpose:** Deferred rewrite rules flush (used when `frl_flush_rewrite_rules()` is called during `init`).

**⚠️ CRITICAL:** This MUST be the last `init` hook. It flushes WordPress rewrite rules after all features have registered their rules.

---

## Reserved Priorities

| Priority | Status | Reason |
|----------|--------|--------|
| < 5 | Reserved | WordPress core |
| 5 | RESERVED | Fralenuvole bootstrap |
| 8-9 | Forbidden | Before environment enforcement |
| 10 | RESERVED | Environment enforcement |
| 12-14 | Forbidden | After env, before rewriter |
| 15 | RESERVED | Rewriter registration |
| 200 | RESERVED | Rewrite flush |

---

## Adding New Hooks

### ✅ CORRECT - Adding a new feature at priority 50
```php
add_action('init', 'frl_my_feature_init', 50, 0);
```

### ❌ WRONG - Adding at priority 8
```php
// This runs BEFORE environment enforcement!
// Options will have wrong values
add_action('init', 'frl_my_feature_init', 8, 0);
```

### ❌ WRONG - Adding at priority 12
```php
// This runs AFTER environment enforcement BUT BEFORE rewriter
// Options correct, but feature won't be part of rewrite rules
add_action('init', 'frl_my_feature_init', 12, 0);
```

---

## Quick Reference

```php
// Use these constants for clarity
defined('FRL_INIT_BOOTSTRAP')      || define('FRL_INIT_BOOTSTRAP', 5);
defined('FRL_INIT_ENV_ENFORCE')    || define('FRL_INIT_ENV_ENFORCE', 10);
defined('FRL_INIT_REWRITER')       || define('FRL_INIT_REWRITER', 15);
defined('FRL_INIT_THEMEKIT')       || define('FRL_INIT_THEMEKIT', 20);
defined('FRL_INIT_FLUSH')          || define('FRL_INIT_FLUSH', 200);
```

---

## Debugging Hook Timing Issues

If you're experiencing issues where:
- Options have unexpected values
- Rewrite rules not being applied
- Features not working on staging/production

**Check:**
1. Is your hook running at the correct priority?
2. Is your hook running after `frl_environment_enforce_settings`?
3. Is your hook running before `frl_flush_rewrite_rules`?

**Debug code:**
```php
add_action('init', function() {
    frl_dump([
        'hook' => current_filter(),
        'priority' => current_priority(),
        'env_enforced' => did_action('init') >= 1, // Check if init/10 ran
    ]);
}, 999, 0);
```
