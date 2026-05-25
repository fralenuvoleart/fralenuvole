# Subdomain Adapter: Automatic Polylang Default Language Sync

## Overview

Automatically set Polylang's `default_lang` in the database when the subdomain adapter detects a mapped subdomain. This eliminates the manual step of changing Polylang's default language on the subdomain replica.

## Architecture

### Design Decision

The Environment Manager (EM) provides an extensibility action that the Subdomain Adapter hooks into. The adapter owns the full logic for reading the `polylang` option, merging `default_lang`, writing back, flushing Polylang's cache, and clearing fralenuvole caches. A generic `cache_cleared` flag in the results array suppresses redundant cache clearing by the EM's change-type classifier, allowing any hooked code to signal that cache operations have already been handled.

**Why this approach:**
- EM gets a general-purpose extensibility point (benefits future modules)
- Subdomain adapter owns its own logic (correct boundary, no EM knowledge of Polylang)
- No nested array support needed in EM — the adapter does the full read-modify-write
- Separates runtime override (`pll_get_current_language` filter) from DB persistence (EM hook)
- Generic `cache_cleared` flag prevents redundant cache operations from the classifier — any hooked callback can set it

### Flow

```
Request arrives on ru.pbservices.ge
  │
  ├── plugins_loaded/5
  │     └── Subdomain Adapter::detect()
  │           ├── Builds subdomain_info from FRL_SUBDOMAIN_ADAPTER_MAP
  │           ├── Sets $this->current_subdomain_lang = 'ru'
  │           └── Registers pll_get_current_language filter (runtime override)
  │
  └── init/10
        └── EM::enforce_environment_settings()
              └── apply_wordpress_options()
                    └── do_action('frl_environment_before_wp_options', $config, $results)
                          └── Subdomain Adapter callback:
                                ├── Checks is_on_subdomain() → true
                                ├── Reads polylang option from DB
                                ├── Merges default_lang = 'ru'
                                ├── update_option() only if changed
                                ├── Flushes Polylang language cache
                                ├── frl_cache_clear('all') — clears ALL language-dependent groups
                                ├── frl_flush_rewrite_rules() — Litespeed notification + rewrite flush
                                └── Sets $results['cache_cleared'] = true (generic flag)
                    └── Generic wp_options loop runs (skipped for polylang since already set)
              └── Change-type classifier (line 239)
                    └── Checks $results['cache_cleared'] → true
                          └── Skips env_enforce_options cache operation (redundant)
```

## Implementation Plan

### Phase 1: Environment Manager — Add Extensibility Action + Generic Cache Cleared Flag

#### 1.1 Add action hook in `apply_wordpress_options()`

**File:** [`includes/core/environment/class-environment-applier.php`](includes/core/environment/class-environment-applier.php:62)

**Change:** Add `do_action('frl_environment_before_wp_options', $config, $results)` before the `wp_options` loop (after siteurl/home handling). Pass `$results` by reference so hooked callbacks can report their changes.

**Location:** After line 90 (end of siteurl/home block), before line 92 (`if (empty($config['wp_options']))`).

```php
// Allow modules to inject custom wp_options handling before the generic loop.
// $results is passed by reference so callbacks can report changes for the classifier.
do_action('frl_environment_before_wp_options', $config, $results);
```

#### 1.2 Add generic `cache_cleared` flag check in `enforce_environment_settings()`

**File:** [`includes/core/environment/class-environment-manager.php`](includes/core/environment/class-environment-manager.php:239)

**Change:** Before the change-type classifier selects a cache operation, check for the generic `cache_cleared` flag. If set, skip the cache operation. This flag can be set by any hooked callback that has already handled cache clearing.

**Location:** Around line 239, before the `$env_op = ''` block.

```php
// Check if a hooked callback already handled cache clearing.
// This generic flag can be set by any module that performed its own cache operations
// during the frl_environment_before_wp_options action, preventing redundant clears.
$cache_already_cleared = !empty($results['cache_cleared']);

// --- Change-type classifier: select cache operation by what changed ---
// All cache clearing is now executed through the orchestrator (FRL_CACHE_OPERATIONS)
// for centralized visibility in get_operation_map().
$env_op = '';

// Skip cache operation if already cleared by a hooked callback.
if ($cache_already_cleared) {
    $env_op = ''; // No-op — cache already cleared by hooked callback.
} elseif ($force) {
    // ... existing logic ...
}
```

### Phase 2: Subdomain Adapter — Hook Into EM Action

#### 2.1 Register EM action hook in `register_hooks()`

**File:** [`modules/subdomain_adapter/class-subdomain-adapter.php`](modules/subdomain_adapter/class-subdomain-adapter.php:343)

**Change:** Add action registration in `register_hooks()`:

```php
// Hook into EM's wp_options apply phase to set Polylang default_lang.
add_action('frl_environment_before_wp_options', [$this, 'sync_polylang_default_lang'], 10, 2);
```

#### 2.2 Implement `sync_polylang_default_lang(array $config, array &$results)` method

**File:** [`modules/subdomain_adapter/class-subdomain-adapter.php`](modules/subdomain_adapter/class-subdomain-adapter.php)

**New method:**

```php
/**
 * Sync Polylang's default_lang to the subdomain's language.
 *
 * Hooked to `frl_environment_before_wp_options` at init/10.
 * Reads the polylang option, merges default_lang from the subdomain
 * config, writes back only if changed, flushes Polylang's language
 * cache, and clears all fralenuvole caches. Sets the generic
 * `cache_cleared` flag to suppress redundant EM cache clearing.
 *
 * @param array $config  The environment configuration array.
 * @param array &$results Reference to results array for reporting changes.
 * @return void
 */
public function sync_polylang_default_lang(array $config, array &$results): void {
    // Only run on mapped subdomains.
    if (!$this->is_on_subdomain() || empty($this->current_subdomain_lang)) {
        return;
    }

    // Read current polylang option.
    $pll_options = get_option('polylang', []);
    if (!is_array($pll_options)) {
        return; // Polylang not configured.
    }

    // Fast-path: already correct.
    $current_default = $pll_options['default_lang'] ?? '';
    if ($current_default === $this->current_subdomain_lang) {
        return;
    }

    // Merge and write.
    $pll_options['default_lang'] = $this->current_subdomain_lang;
    update_option('polylang', $pll_options);

    // Report the change so the classifier knows something changed.
    $results['wp_options']['updated'][] = 'polylang';

    // Flush Polylang's internal language cache.
    $this->flush_polylang_cache();

    // Clear all fralenuvole caches (includes language-dependent groups).
    frl_cache_clear('all');

    // Flush rewrite rules (handles Litespeed notification + Polylang cache clean).
    frl_flush_rewrite_rules();

    // Set generic flag to suppress redundant cache clearing by EM's classifier.
    // Any hooked callback can set this to signal that cache operations are complete.
    $results['cache_cleared'] = true;

    if (defined('WP_DEBUG') && WP_DEBUG) {
        frl_log('Subdomain Adapter: Set Polylang default_lang to {lang} for subdomain {host}', [
            'lang' => $this->current_subdomain_lang,
            'host' => $this->current_host,
        ]);
    }
}
```

#### 2.3 Implement `flush_polylang_cache()` method

**File:** [`modules/subdomain_adapter/class-subdomain-adapter.php`](modules/subdomain_adapter/class-subdomain-adapter.php)

**New method:**

```php
/**
 * Flush Polylang's internal language cache.
 *
 * Called after updating polylang['default_lang'] to ensure Polylang
 * rebuilds its language model with the new default on the next request.
 *
 * @return void
 */
private function flush_polylang_cache(): void {
    if (!function_exists('PLL')) {
        return;
    }

    $pll = PLL();
    if (!$pll || !isset($pll->model)) {
        return;
    }

    // Polylang caches languages in a transient. Deleting it forces rebuild.
    if (method_exists($pll->model, 'clean_languages_cache')) {
        $pll->model->clean_languages_cache();
    } else {
        // Fallback: delete all PLL-related transients.
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_pll_%'");
        if (is_multisite()) {
            $wpdb->query("DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE '_site_transient_pll_%'");
        }
    }
}
```

### Phase 3: Testing & Verification

#### 3.1 Test scenarios

| Scenario | Expected Behavior |
|----------|------------------|
| First visit to `ru.pbservices.ge` (DB has `default_lang = 'en'`) | `polylang['default_lang']` updated to `'ru'`, Polylang cache flushed, `frl_cache_clear('all')` runs, `frl_flush_rewrite_rules()` runs, `$results['cache_cleared'] = true`, EM classifier skips cache op, debug log entry |
| Second visit to `ru.pbservices.ge` (DB already has `default_lang = 'ru'`) | Fast-path returns early, zero DB writes, zero cache clears |
| Visit to `pbservices.ge` (main domain) | Hook fires but `is_on_subdomain()` returns false, zero DB writes |
| Visit on non-mapped domain | Hook fires but `is_on_subdomain()` returns false, zero DB writes |
| Polylang not active | `get_option('polylang')` returns empty/false, method returns early |

#### 3.2 Verification steps

1. On `ru.pbservices.ge`, check `wp_options` table: `polylang` option should have `default_lang = 'ru'`
2. Visit Polylang admin settings on subdomain: default language should show as Russian
3. Check debug log for sync entry on first visit
4. Verify no duplicate DB writes on subsequent visits (check query count)
5. Verify no redundant cache clearing (check that `env_op` stays empty when `cache_cleared` is set)

## Files Modified

| File | Change |
|------|--------|
| `includes/core/environment/class-environment-applier.php` | Add `do_action('frl_environment_before_wp_options', $config, $results)` at line 91 |
| `includes/core/environment/class-environment-manager.php` | Add generic `cache_cleared` flag check in classifier at line 239 |
| `modules/subdomain_adapter/class-subdomain-adapter.php` | Add `register_hooks()` action, `sync_polylang_default_lang()`, `flush_polylang_cache()` |
| `docs/SUBDOMAIN-ADAPTER.md` | Document new automatic default_lang sync behavior |
| `memory-bank/systemPatterns.md` | Update translation fallback architecture section |
| `memory-bank/activeContext.md` | Add entry for this change |

## Risk Assessment

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| EM action hook breaks existing behavior | Low | Hook fires before existing loop, doesn't modify loop behavior |
| Polylang cache flush is too aggressive | Medium | Use `clean_languages_cache()` if available, fallback to transient deletion |
| Race condition on concurrent first visits | Low | `update_option` is atomic; both writes set same value |
| Adapter runs on non-subdomain requests | None | `is_on_subdomain()` guard prevents execution |
| Redundant cache clearing if flag not set | Low | Flag is set in the same callback; if callback fails, EM's cache clear is a safe fallback |

## Rollback Plan

If issues arise:
1. Remove the `add_action()` call in `register_hooks()` — the adapter reverts to manual-only behavior
2. The EM action hook is harmless if no one listens to it — can remain for future use
3. The classifier flag check is a no-op if the flag is never set — safe to keep
