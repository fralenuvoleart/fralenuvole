# Project Progress

## Recent Updates (v5.4.0)
- Plugin Exclusion Feature: MU-based loader to prevent specified plugins from loading without deactivating them
  - **Frontend exclusion**: applies to all users in frontend context
  - **Backend exclusion**: applies to all users in admin context, filtered by admin screen via `plugin-path|admin-screen` format
  - **Capability exclusion**: applies in non-frontend contexts for users without required cap
- **Fixed: Cron `invalid_schedule` error when excluded plugins have cron events**
  - Added shared `frl_get_exclusion_options()` fetching both `active_plugins` and `cron` in one DB query
  - Refactored `pre_option_active_plugins` to use shared function (no behavior change)
  - Added `pre_option_cron` filter during WP Cron that removes orphaned events with unregistered schedules
- **Backend exclusion wired in** — reads `excluded_plugins_backend_enabled` / `excluded_plugins_backend` options
  - Uses existing `frl_is_admin_page()` helper for screen matching
  - Uses existing `frl_textlist_to_array()` helper (already parses `|` pipe format)
  - Admin screen after `|` is **required** (exclusion only activates on matching screen)
- **Refactored MU plugin structure:**
  - `assets/mu/frl-mu-plugin.php` → thin bootstrap (constant + bootstrap require + hook registration)
  - `includes/helpers/functions-mu-plugin.php` → all exclusion logic (moved from MU plugin)
  - Loaded only by the MU plugin, not polluting the main plugin's helper load
  - Updated `docs/PLUGIN-EXCLUSIONS.md` with new file references
- Translation Module Refactor:
  - Implemented Adapter Pattern for translation providers (Polylang/WPML).
  - Added strict typing to `field-translator.php`.
  - Introduced configurable delimiters and registration queue limits for stability.
  - Fixed language-scoping bugs in translation caching.
  - Optimized performance by deferring string registration to the `shutdown` hook.
- **MU Plugin Performance Optimization:**
  - Added persistent caching of `active_plugins` via `frl_cache_remember('options', 'mu_plugin_active_plugins', callback, WEEK_IN_SECONDS)`:
    - Split the combined DB query (formerly `active_plugins` + `cron` in one query)
    - `active_plugins` now cached in `options` group with WEEK_IN_SECONDS TTL (changes only on plugin activation/deactivation)
    - `cron` stays fetched fresh per request (too volatile — changes on every WP Cron execution)
  - Added persistent caching of network active plugins via `frl_cache_remember('options', 'mu_plugin_network_active_plugins', callback, WEEK_IN_SECONDS)`:
    - Wraps the existing `$wpdb->get_var()` to `wp_sitemeta` (safe — cache layer never touches the `pre_site_option` filter chain)
    - Direct DB callback still used as the cache miss fallback to prevent recursion
  - Added cache invalidation in `cache-cleanup.php` via `activated_plugin`/`deactivated_plugin` hooks
  - Verified: no recursion risk (`frl_cache_remember` uses object cache/transients, never `get_option()` on the filtered options)
  - **Cron query fix (confirmed applied):** The cron DB query in [`frl_get_exclusion_options()`](includes/helpers/functions-mu-plugin.php:30) is now guarded behind [`frl_is_cron_job_request()`](includes/helpers/functions-access-control.php:475). On non-cron requests, `$options['cron'] = []` without touching the DB. Cron data is intentionally NOT cached via `frl_cache_remember` — stale cron data would cause duplicate event execution during WP-Cron cycles. Request-level static cache in `frl_get_exclusion_options():32` handles per-request dedup.

### Cache Operation Orchestrator (v5.4.0)
- **Added `Frl_Cache_Operations`** (`includes/core/cache/class-cache-operations.php`):
  - **Two-tier `FRL_CACHE_OPERATIONS` constant** defined in `config/config-cache-operations.php` (loaded via `config/config.php`):
    - `clear_hard`, `clear_all`, `clear_light` — Helper-level operations that `frl_cache_clear()` delegates to. Each step enumerates granular calls (e.g., `[ 'Frl_Cache_Manager', 'hard_cache_reset' ]` + `frl_thirdparty_maybe_notify('hard')`).
    - `action_hard`, `action_flush_rewrite_rules`, `action_clear_plugin_transients`, `action_clear_website_transients`, `action_clear_scripts_tags` — Admin-action-level operations called by action handlers. Compose helper calls with additional steps (e.g., rewrite flush).
    - No `action_all`/`action_light` (redundant with `clear_all`/`clear_light` — action handlers call those directly).
  - **No critical flag:** Sequential execution only. All steps always run; caller inspects per-step results and decides how to report.
  - **`fn` supports callable arrays:** `[ 'Frl_Cache_Manager', 'hard_cache_reset' ]` for static method calls, validated by `is_callable()` (replaces `function_exists()`).
  - **`note` fields** on each step document deferred chains inline (e.g., `frl_schedule_admin_rewrite_flush` → 60s transient → `admin_init:99` → `frl_execute_scheduled_admin_flush()` → `Frl_Rewriter::flush_rules()` + `frl_cache_clear('rewriter')`).
  - **Lifecycle hooks:** `before`/`after` hooks per operation (e.g., `frl_before_cache_operation_clear_hard`, `frl_after_cache_operation_clear_hard`).
  - **Re-entrancy guard** via `frl_is_already_running()` prevents duplicate execution.
  - **`get_operation_map()`** returns full operation registry for debugging and admin UI.
- **Modified `includes/bootstrap.php`** — loaded orchestrator after cache-manager.
- **Modified `includes/helpers/functions-class-helpers.php`** (`frl_cache_clear()`):
  - `'hard'/'all'/'light'` delegated to operations: `$result = Frl_Cache_Operations::run($orchestrated_groups[$group]); return $result['steps'][0]['result'] ?? [];`
  - Removed `frl_thirdparty_maybe_notify()` call from `frl_cache_clear()` for these groups (now in orchestrator steps).
  - Non-orchestrated groups (`opcache`, `plugin_transients`, `website_transients`, arbitrary groups) still handled with direct `Frl_Cache_Manager` calls (unchanged).
- **Modified `includes/helpers/functions-action-handlers.php`** — All 6 action handlers updated:
  - `frl_handle_action_clear_cache_hard()` → `Frl_Cache_Operations::run('action_hard')`
  - `frl_handle_action_flush_rewrite_rules()` → `Frl_Cache_Operations::run('action_flush_rewrite_rules')`
  - `frl_handle_action_clear_cache_all()` → `Frl_Cache_Operations::run('clear_all')`
  - `frl_handle_action_clear_cache_light()` → `Frl_Cache_Operations::run('clear_light')`
  - `frl_handle_action_clear_plugin_transients()` → `Frl_Cache_Operations::run('action_clear_plugin_transients')`
  - `frl_handle_action_clear_website_transients()` → `Frl_Cache_Operations::run('action_clear_website_transients')`
  - `frl_handle_action_clear_scripts_tags()` → `Frl_Cache_Operations::run('action_clear_scripts_tags')`
- **Zero regressions:** Orchestrator is purely additive. All `frl_cache_*` helpers remain independently callable. External callers of `frl_cache_clear('all')` (in `functions.php`, `class-environment-manager.php`, `functions-admin-action-handlers.php`) and `frl_cache_clear('light')` (in `plugin-lifecycle.php`) use return values for side effects only — compatible with `$result['steps'][0]['result']` passthrough.
- **All 6 action handlers plus frl_cache_clear() composite groups are now fully visible** in one source: the `FRL_CACHE_OPERATIONS` constant. No more searching the codebase to understand multi-step flows.

### Fixes Applied (2026-04-28)
- **Fixed `index.php` dashboard screen matching** — `$pagenow` is null during `muplugins_loaded` because `wp-includes/vars.php` loads at `wp-settings.php:524`, after `muplugins_loaded` at line 511. Added `$_SERVER['SCRIPT_NAME']` fallback to `frl_is_admin_page()`.
- **Added cron args sanitization** — Ensures `$event['args']` is always an array in `pre_option_cron` filter to prevent `TypeError: count(): Argument #1 must be of type Countable|array, null given` at `class-wp-hook.php:325`. **(Later changed to `option_cron` — see below)**
- **Fixed cron filter early-exit bug** — Cron filter was gated behind `if (!empty($excluded))`, so it was never added during cron when only backend exclusion was enabled (capability exclusion disabled). Moved cron filter addition before the empty-exclusion check so it always registers during WP Cron, ensuring args sanitization runs unconditionally.
- **Fixed `pre_option_cron` bypassed by alloptions cache** — Changed from `pre_option_cron` to `option_cron` in `frl_add_exclusion_filter_cron()`. `pre_option_*` filters are skipped when the option exists in WordPress' autoloaded `alloptions` cache. `option_*` fires unconditionally. This was why the cron args sanitization was ineffective — it was never actually running.
- **Added import serialization validation** — Added `@unserialize(@serialize($item['translations']))` round-trip check in `frl_import_translation_strings()` before `update_term_meta()`. Prevents writing `_pll_strings_translations` data that would cause `unserialize()` errors when read back by Polylang.

### Refactored Early-Loading Access Check (2026-04-28)
- **Moved `!did_action('plugins_loaded')` logic out of [`frl_has_access()`](includes/helpers/functions-access-control.php:95)** — the early-loading code path was only used by the MU plugin's capability-based exclusion. Now handled by dedicated [`frl_mu_check_access()`](includes/helpers/functions-mu-plugin.php:91) in the MU plugin helper file.
- **Added cross-request caching to [`frl_get_auth_cookie_user_data()`](includes/helpers/functions-access-control.php:64)** — DB query wrapped in `frl_cache_remember('admin', 'auth_cookie_user_' . $username, ..., 300)`. Username-scoped key, 300s TTL aligned with `frl_has_access()` standard path.
- **Updated [`frl_plugins_exclusion_filter()`](includes/helpers/functions-mu-plugin.php:190)** — call site now uses `frl_mu_check_access()` instead of `frl_has_access()`.
- **Docblock updated** on `frl_has_access()` to reference `frl_mu_check_access()` for early-loading scenarios.
- **Zero regressions:** All 39 non-MU-plugin callers of `frl_has_access()` are in standard post-`plugins_loaded` contexts.

### Fixed `@` Suppression Detection in Error Handler (2026-04-28)
- **Bug:** [`frl_errors_handle_error()`](includes/core/error-handler.php:101) only checked `error_reporting() === 4437` to detect `@`-suppressed errors. In PHP 8.0+, `@` sets `error_reporting()` to `0`, not `4437`, so suppressed warnings (like `unserialize()` errors from excluded plugins' corrupted term meta) were being logged.
- **Fix:** Added `$current_reporting === 0` check alongside the existing `4437` check at [line 148](includes/core/error-handler.php:148). Now correctly suppresses `@`-silenced errors on both PHP < 8.0 and PHP 8.0+.

### Cache System PHPDoc & Comment Audit (2026-04-28)
- **Audited** all PHPDocs, `@param`/`@return` blocks, and internal comments in [`includes/core/cache/`](includes/core/cache/) (3 files)
- **7 inaccuracies corrected:**
  1. [`cache-cleanup.php:11`](includes/core/cache/cache-cleanup.php:11) — "Register term-change hooks" → "On init, register term-change hooks that trigger rewrite flush"
  2. [`cache-cleanup.php:198`](includes/core/cache/cache-cleanup.php:198) — "nav and blocks groups" → "metafields group" (matched to actual `FRL_CACHE_DEPENDENCIES`)
  3. [`cache-cleanup.php:203-205`](includes/core/cache/cache-cleanup.php:203) — Navigation cache doc/param updated to reflect both `save_post_wp_navigation` and `wp_update_nav_menu` usage
  4. [`cache-cleanup.php:210`](includes/core/cache/cache-cleanup.php:210) — "Simply clear the navigation cache group" → "Clear the wp_navigation key within the permalinks group"
  5. [`cache-cleanup.php:245`](includes/core/cache/cache-cleanup.php:245) — Tracked meta guard comment simplified for typed `int` param
  6. [`class-cache-manager.php`](includes/core/cache/class-cache-manager.php) — 3 stale comments updated (`"New tracking"`, `"New feature"`, `"REMOVED"`); `purge_all()` `@return` updated for early-return case
  7. [`class-cache-operations.php:150`](includes/core/cache/class-cache-operations.php:150) — `get_operation_map()` `@return` from generic `array` to typed shape
- **2 code bugs patched:**
  - [`atomic_clear_group()`](includes/core/cache/class-cache-manager.php:1478) return type normalized — maps `clear_group_with_dependencies()` output to documented `$stats` shape for consistent return regardless of cache backend (lines 1488-1496)
  - [`frl_clear_navigation_cache()`](includes/core/cache/cache-cleanup.php:208) ID namespace collision — split into separate `frl_clear_navigation_cache()` (wp_navigation post) and new `frl_clear_menu_cache()` (nav_menu term) with distinct `wp_navigation_`/`wp_menu_` key prefixes (lines 17-18, 202-230)

### Cache System Review & Fixes Applied (2026-04-28)
- **Comprehensive review** of `includes/core/cache/` system written to `plans/cache-system-review.md`
- **6 fixes applied:**
  1. ✅ `'all_options, false'` string-as-parameter bug at [`functions-options.php:124`](includes/helpers/functions-options.php:124) & [:726](includes/helpers/functions-options.php:726) — changed to separate arguments. Dependency skipping now works.
  2. ✅ Auth cookie side-effect extracted — [`with_auth_preservation()`](includes/core/cache/class-cache-manager.php:828) wrapper method with docblock
  3. ✅ Double `serialize()` eliminated — [`frl_sanitize_for_serialization()`](includes/core/cache/class-cache-manager.php:504) output used directly
  4. ✅ `purge_all()` double work eliminated — [`$transients_batch_deleted`](includes/core/cache/class-cache-manager.php:47) flag skips redundant per-group transient deletion
  5. ✅ LRU size made configurable — [`FRL_CACHE_RUNTIME_MAX_ITEMS = 1000`](config/config-cache.php:148) constant
  6. ✅ Unrecognized group warning — [`frl_log()`](includes/core/cache/class-cache-manager.php:1195) fires when group is in no config array
- **Issues retracted after code review:** pre_option filter removal (plugin's own namespace only), $loaded_groups reset (only on full clears), auto_preload overhead (batching is optimal), all-static design and non-filterable constants (by user direction)
- **Remaining:** `metadata` group not in `FRL_CACHE_PERSISTENT_GROUPS` — affects transient-only sites only
- (Review content archived in [`docs/CACHE.md §11`](docs/CACHE.md:565) — plan file deleted as obsolete)

---
### Cache System Architectural Reference Written (2026-04-28)
- **Wrote comprehensive [`docs/CACHE.md`](docs/CACHE.md)** — synthesized from source files (`includes/core/cache/`), config files (`config/config-cache*.php`), and existing plan reviews (`plans/cache-system-review.md`, `plans/cache-orchestrator-implementation.md`)
- Covers: architecture overview, file map, group configuration, `Frl_Cache_Manager` internals (LRU, persistent cache, provider detection, language keys, dependency cascading, clearing tiers, atomic ops, race prevention, auth preservation), `Frl_Cache_Operations` orchestrator, cleanup hooks, helper API reference, clearing behavior reference, performance considerations, and complete bug/fix history
- Serves as a future synthetic reference for developers to understand caching features and behavior without reading every source file

---

## Rewriter Module Plugability (2026-04-28)

- **Problem:** `frl_rewriter_register_features` action fired at coordinator construction time (`plugins_loaded/5`), before module files were loaded — modules couldn't register features.
- **Fix:** Moved `do_action()` + `usort()` from `register_features()` (constructor) to a `plugins_loaded/7` hook in `register_hooks()`. Now modules load at `plugins_loaded/5` → action fires at `plugins_loaded/7` → features sorted → registered at `init:15`.
- **Scope:** ~3 lines moved in [`class-rewriter-coordinator.php`](includes/core/rewriter/class-rewriter-coordinator.php). Zero regressions verified.
- **Module DX:** Call `$coordinator->add_feature()` from module entry point. Default priority is 99 for features not in `FRL_REWRITER_PRIORITIES`.
- **Docs:** [`docs/REWRITER.md`](docs/REWRITER.md) updated with bootstrap flow, timeline, and "Module Plugability" section.
- **Plan:** [`plans/rewriter-module-plugability.md`](plans/rewriter-module-plugability.md) updated with final approach.

---

---

## Fixed: `frl_get_current_user()` returning non-WP_User from persistent cache (2026-04-28)

- **Bug:** [`frl_get_current_user()`](includes/helpers/functions.php:68) could return a string value (non-`WP_User`) when the persistent cache (Redis, Memcached, or transients) returned corrupted or improperly deserialized data. This caused:
  - `PHP Warning: Attempt to read property "ID" on string` at [`functions-access-control.php:44`](includes/helpers/functions-access-control.php:44), [:48](includes/helpers/functions-access-control.php:48), [:161](includes/helpers/functions-access-control.php:161)
  - `PHP Fatal error: Call to a member function has_cap() on string` at [`functions-access-control.php:50`](includes/helpers/functions-access-control.php:50)
- **Root cause:** `frl_cache_remember()` (called without explicit TTL in `frl_get_current_user()`) could return a stale/corrupted persistent cache value. While the cache miss callback has a `WP_User` type guard (`!($user instanceof WP_User) → new WP_User(0)`), the cache retrieval path (`Frl_Cache_Manager::get()`) returns whatever the persistent cache has stored without type validation.
- **Fix:** Added a safety type guard in `frl_get_current_user()` at [`functions.php:101-103`](includes/helpers/functions.php:101):
  ```php
  if (!($current_user instanceof WP_User)) {
      $current_user = new WP_User(0);
  }
  ```
  This ensures `frl_get_current_user()` ALWAYS returns a `WP_User` instance, regardless of what the persistent cache returns. All 13 callers are safe with `WP_User(0)` — they access `->ID` or check `->ID > 0`.
- **Stack trace:** Triggered during `plugins_loaded` via `frl_environment_init()` → `Frl_Environment_Manager::init()` → `frl_has_access()`.

## Environment Manager Patches (2026-04-29)

### C1 — Change-type classifier for cache clears
- **Problem:** `enforce_environment_settings()` always called `frl_cache_clear('all')` after *any* environment change, regardless of what changed. This was wasteful for option-only changes and missed `flush_rewrite_rules()` for plugin/module changes.
- **Findings:**
  - `frl_thirdparty_maybe_notify('all')` notifies **zero** plugins — no outbound hook listens for `'all'` trigger (only `'hard'` and `'rewrite_flush'`)
  - Module changes DO require rewrite flush (modules register features via `add_feature()` at `plugins_loaded/5`)
  - The monolithic `frl_cache_clear('all')` was redundant with targeted clears inside apply methods (double-clearing)
- **Fix:** Replaced the monolithic clear with a change-type classifier at [`class-environment-manager.php:228`](../includes/core/environment/class-environment-manager.php:228):
  - Plugin/module changes → `frl_cache_clear('all')` + `frl_schedule_admin_rewrite_flush()`
  - `siteurl`/`home` changes → `frl_cache_clear('all')`
  - Option-only changes → `frl_cache_clear('options')`
  - Force mode → `frl_cache_clear('all')` (preserved)

### C1+ — Remove redundant targeted clears
- **Problem:** `apply_plugin_options()` and `apply_modules_options()` each independently called `frl_cache_clear('options')` when changes were made (non-force mode), but these were immediately overwritten by the parent `frl_cache_clear('all')`.
- **Fix:** Removed `$clear_cache_on_update` variable and `frl_cache_clear('options')` calls from both methods in [`class-environment-applier.php`](../includes/core/environment/class-environment-applier.php). All cache clearing is now centralized in the change-type classifier in `enforce_environment_settings()`.

### P4 — Consolidate 15+ `update_option_{$name}` hooks
- **Problem:** `setup_plugin_options_tracking()` registered individual `update_option_{$prefixed_name}` hooks for each managed option — creating N closures and N hook bucket entries per admin page load.
- **Fix:** Replaced with a single `updated_option` hook using an O(1) lookup map at [`class-environment-monitor.php:16`](../includes/core/environment/class-environment-monitor.php:16):
  - Builds `prefixed_name → config_key` map once
  - `updated_option` passes 3 args (`$option, $old_value, $new_value`) — `add_action()` specifies `3` as `$accepted_args`
  - `isset($managed_options[$option])` guard for O(1) check
  - Reduces closure allocation from N (~15) to 1

### Plan updated
- [`plans/environment-manager-patches.md`](../plans/environment-manager-patches.md) revised per codebase investigation:
  - C3 downgraded from P0→P3 (user feedback accepted)
  - A1 (PSR-4), T1 (unit tests), T2 (integration tests) removed per user direction
  - Third-party notification coverage gap documented (Appendix D)
  - Module changes reclassified to require rewrite flush (Scenario B corrected)
  - Orchestrator compatibility confirmed (Scenario C)
  - Regression analysis added (Appendix C)

*Last Updated: 2026-04-29*
