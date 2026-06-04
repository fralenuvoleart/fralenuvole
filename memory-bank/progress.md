# Project Progress

## Shortcode Translation Fix — Subdomain Adapter Compatibility (2026-05-21 — CORRECTED)

### Root Cause: Subdomain Adapter Hooks Into Non-Existent Polylang Filters
- **Original (incorrect) analysis:** Claimed `pll_translate_string()` short-circuits on `pll_default_language()` and that the Subdomain Adapter's `pll_default_language`/`pll_current_language` hooks were effective.
- **CORRECTED root cause:** In Polylang 3.7+, [`pll_default_language()`](/mnt/backup/BACKUP/WWW/PBS/public_html/wp-content/plugins/polylang/src/api.php:93) and [`pll_current_language()`](/mnt/backup/BACKUP/WWW/PBS/public_html/wp-content/plugins/polylang/src/api.php:62) are **function names**, not `apply_filters()` hooks. They read directly from `PLL()->model->get_default_language()` and `PLL()->curlang` respectively. The Subdomain Adapter's `add_filter('pll_default_language', ...)` and `add_filter('pll_current_language', ...)` registered callbacks on **non-existent filters that never fire**.
- **The REAL filter:** [`pll_get_current_language` at choose-lang.php:103](/mnt/backup/BACKUP/WWW/PBS/public_html/wp-content/plugins/polylang/src/frontend/choose-lang.php:103) — fires inside `PLL_Choose_Lang::set_language()` and takes a `PLL_Language` object.
- **Why DB default change fixes everything:** Changing `pll_default_language` to `'ru'` in the DB makes `PLL()->model->get_default_language()` return RU, cascading to `PLL()->curlang = RU` → `pll_current_language()` = `'ru'` → correct MO loaded → string translations work.
- **Why `$wp_query->query['lang']` seemed to help:** The original `get_language()` unconditional overwrite with `$wp_query->query['lang']` also returned 'en' on subdomains (since Polylang in directory mode sets it from `$this->curlang->slug` which was EN). It was never actually helping — both sources returned the same wrong value.

### Fix: Hook Subdomain Adapter Into the REAL Polylang Filter
- **What changed:** Replaced two dead hooks (`pll_default_language` and `pll_current_language`) with the one real Polylang filter [`pll_get_current_language`](modules/subdomain_adapter/class-subdomain-adapter.php:360).
- **New method [`filter_pll_get_current_language()`](modules/subdomain_adapter/class-subdomain-adapter.php:439):** Returns a proper `PLL_Language` object (obtained via `PLL()->model->get_language($slug)`) instead of a string. The filter fires inside `set_language()` BEFORE the default-language fallback.
- **Result:** `PLL()->curlang = RU` on `ru.pbservices.ge` → `pll_current_language()` = `'ru'` → `load_strings_translations()` loads RU MO → `pll_translate_string('string', 'ru')` short-circuits to `pll__()` which reads from correct RU MO.
- **`get_language()` reverted:** The conditional fallback is correct design — adapter now returns 'ru' properly via the working filter.
- **`translate_string()` unchanged:** Reverted back to original — no `pll_strings` option workaround needed since Polylang now loads the correct MO.

### Plan: [`plans/shortcode-translation-root-cause-analysis.md`](plans/shortcode-translation-root-cause-analysis.md)

## Hotfix — Flush Rewrite Rules Litespeed Notification (2026-05-12)

### Timing Bug Fix — [`frl_flush_rewrite_rules()`](includes/plugin-lifecycle.php:179)
- **Problem:** "Flush Rewrite Rules" button showed no Litespeed admin notification, while "Clear Caches (Hard)" did.
- **Root cause:** [`register_cache_invalidation_hooks()`](core/rewriter/class-rewriter.php:446) registers `update_option_permalink_structure` → `clear_rewriter_caches()` inside a `wp_loaded` callback. The button action handler runs at `init:10` (before `wp_loaded`), so the hook listener was never registered and `frl_thirdparty_maybe_notify('rewrite_flush')` never executed. `flush_rewrite_rules(true)` also silently failed.
- **Why "Clear Caches (Hard)" worked:** Its operation has `frl_thirdparty_maybe_notify('hard')` as a **direct step**, not via the rewriter's deferred hook chain.
- **Fix:** Added `did_action('wp_loaded')` fallback in [`frl_flush_rewrite_rules()`](includes/plugin-lifecycle.php:188) — if `wp_loaded` hasn't fired yet, `flush_rewrite_rules(true)` and `frl_thirdparty_maybe_notify('rewrite_flush')` run directly. After `wp_loaded`, hooks handle it as before (no double-fire).

### Config-Constants Cleanup — [`thirdparty.php`](modules/thirdparty/thirdparty.php)
- **Problem:** Three getter functions (`frl_thirdparty_get_inbound_hooks`, `frl_thirdparty_get_inbound_queries`, `frl_thirdparty_get_outbound_hooks`) were defined in [`config-constants-thirdparty.php`](modules/thirdparty/config-constants-thirdparty.php) wrapped in redundant `function_exists` guards.
- **Fix:** Moved all three functions (without `function_exists` wrappers) to [`thirdparty.php`](modules/thirdparty/thirdparty.php:251,271,296), placed before `frl_thirdparty_maybe_notify()`. Config-constants file now only contains the `const` definitions.


## Recent Updates (v5.7.0 — 2026-05-11)

### Flush Rewrite Rules Consolidation — Implemented (2026-05-11)
- **Problem:** Page permalinks in secondary languages (e.g., `/ru/russian-page/`) return 404 over time. Fralenuvole "Flush Rewrite Rules" button didn't fix it. Manual fix: Save Permalinks 2 times + Purge Litespeed.
- **Root cause:** Flush button path never fired `update_option_permalink_structure`, so Polylang's `clean_languages_cache()` (hooked at `polylang/src/model.php:119`) never ran before `flush_rewrite_rules()` regenerated rules.
- **Fix:** Single function [`frl_flush_rewrite_rules()`](includes/plugin-lifecycle.php:172) replaces 6 deleted legacy functions. Mirrors `WP_Rewrite::set_permalink_structure()`: handles timing (before/during/after init), fires `update_option_permalink_structure` (→ `clear_rewriter_caches()` + Polylang cache clean) + `permalink_structure_changed`. Immediate execution.
- **Deleted (6):** `frl_flush_force_rewrite_rules()`, `frl_flush_rewrite_rules_mirror_permalink_save()`, `Frl_Rewriter::flush_rules()`, `frl_execute_rewrite_flush()`, `frl_schedule_admin_rewrite_flush()`, `frl_execute_scheduled_admin_flush()`.
- **Kept (2):** `frl_schedule_rewrite_flush()` (schedules cron for before-init calls), `Frl_Rewriter::clear_rewriter_caches()` (reaction hooked to `update_option_*` actions — distinct purpose from the trigger function).
- **Files changed (7):**
  - [`includes/plugin-lifecycle.php`](includes/plugin-lifecycle.php) — rewrote: added `frl_flush_rewrite_rules()`, deleted 3 functions, cron hook now points directly to `frl_flush_rewrite_rules`
  - [`includes/helpers/functions-action-handlers.php:327`](includes/helpers/functions-action-handlers.php:327) — calls `frl_flush_rewrite_rules()`
  - [`admin/helpers/functions-admin-action-handlers.php:500`](admin/helpers/functions-admin-action-handlers.php:500) — calls `frl_flush_rewrite_rules()`
  - [`core/rewriter/class-rewriter.php`](core/rewriter/class-rewriter.php) — deleted `flush_rules()`, updated `force_rules_refresh()` and repair path
  - [`core/rewriter/class-rewriter-coordinator.php:277`](core/rewriter/class-rewriter-coordinator.php:277) — `force_refresh()` calls `frl_flush_rewrite_rules()`
  - [`config/config-cache-operations.php`](config/config-cache-operations.php) — `action_hard`, `action_flush_rewrite_rules`, `env_enforce_full` all call `frl_flush_rewrite_rules`
  - [`modules/thirdparty/config-constants-thirdparty.php:79`](modules/thirdparty/config-constants-thirdparty.php:79) — updated stale comment
- **Zero regression:** Cron event name preserved for backward compatibility, `clear_rewriter_caches()` untouched.
- **Plan:** [`plans/fix-stale-rewrite-rules-litespeed.md`](plans/fix-stale-rewrite-rules-litespeed.md)

### Subdomain Adapter Module — Implemented & Documented
- **New module** [`modules/subdomain_adapter/`](modules/subdomain_adapter/) for bidirectional URL transformation between main domains and language-specific subdomains
- **Documentation:** [`docs/SUBDOMAIN-ADAPTER.md`](docs/SUBDOMAIN-ADAPTER.md)
- **Config:** Main-domain-keyed `FRL_SUBDOMAIN_ADAPTER_MAP` with `'default_lang'` key; `FRL_SUBDOMAIN_ADAPTER_MAIN_DEFAULTS` eliminated
- **Key mechanism:** `pll_get_current_language` filter (p10) — the ONLY real filter in Polylang 3.7+ that controls `PLL()->curlang`. Returns `PLL_Language` object, making Polylang treat subdomain's language as default — zero-cost clean URLs
- **URL transformation:** `transform_url()` uses `wp_parse_url()` for robust component manipulation (handles query strings, fragments, mixed case, any scheme)
- **Redirects:** `template_redirect` (p5) handles `WP_Post`, `WP_Term`, and archive queries; 404s served locally
- **Staging support:** Add staging domain as top-level key in config — zero code changes needed
- **`home_url` filter:** WordPress core `home_url()` returns correct subdomain URL
- **Public API:** `is_on_main_domain()`, `is_on_subdomain()`, `is_configured()`
- **TypeError fixes:** Polylang/Yoast filter callbacks handle `false` gracefully

## Previous Updates (v5.4.0)
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
  - `includes/mu/functions-mu.php` → all exclusion logic (moved from MU plugin)
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
  - **Cron query fix (confirmed applied):** The cron DB query in [`frl_get_exclusion_options()`](includes/mu/functions-mu.php:30) is now guarded behind [`frl_is_cron_job_request()`](includes/helpers/functions-access-control.php:475). On non-cron requests, `$options['cron'] = []` without touching the DB. Cron data is intentionally NOT cached via `frl_cache_remember` — stale cron data would cause duplicate event execution during WP-Cron cycles. Request-level static cache in `frl_get_exclusion_options():32` handles per-request dedup.

### Cache Operation Orchestrator (v5.4.0)
- **Added `Frl_Cache_Operations`** (`core/cache/class-cache-operations.php`):
  - **Two-tier `FRL_CACHE_OPERATIONS` constant** defined in `config/config-cache-operations.php` (loaded via `config/config.php`):
    - `clear_hard`, `clear_all`, `clear_light` — Helper-level operations that `frl_cache_clear()` delegates to. Each step enumerates granular calls (e.g., `[ 'Frl_Cache_Manager', 'hard_cache_reset' ]` + `frl_thirdparty_maybe_notify('hard')`).
    - `action_hard`, `action_flush_rewrite_rules`, `action_clear_plugin_transients`, `action_clear_website_transients`, `action_clear_scripts_tags` — Admin-action-level operations called by action handlers. Compose helper calls with additional steps (e.g., rewrite flush).
    - No `action_all`/`action_light` (redundant with `clear_all`/`clear_light` — action handlers call those directly).
  - **No critical flag:** Sequential execution only. All steps always run; caller inspects per-step results and decides how to report.
  - **`fn` supports callable arrays:** `[ 'Frl_Cache_Manager', 'hard_cache_reset' ]` for static method calls, validated by `is_callable()` (replaces `function_exists()`).
  - **`note` fields** on each step document deferred chains inline. **Superseded 2026-05-11:** the deferred transient chain (`frl_schedule_admin_rewrite_flush` → `admin_init:99` → `frl_execute_scheduled_admin_flush()` → `Frl_Rewriter::flush_rules()`) has been replaced by direct calls to `frl_flush_rewrite_rules()`.
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
- **Moved `!did_action('plugins_loaded')` logic out of [`frl_has_access()`](includes/helpers/functions-access-control.php:95)** — the early-loading code path was only used by the MU plugin's capability-based exclusion. Now handled by dedicated [`frl_mu_check_access()`](includes/mu/functions-mu.php:91) in the MU plugin helper file.
- **Added cross-request caching to [`frl_get_auth_cookie_user_data()`](includes/helpers/functions-access-control.php:64)** — DB query wrapped in `frl_cache_remember('admin', 'auth_cookie_user_' . $username, ..., 300)`. Username-scoped key, 300s TTL aligned with `frl_has_access()` standard path.
- **Updated [`frl_plugins_exclusion_filter()`](includes/mu/functions-mu.php:190)** — call site now uses `frl_mu_check_access()` instead of `frl_has_access()`.
- **Docblock updated** on `frl_has_access()` to reference `frl_mu_check_access()` for early-loading scenarios.
- **Zero regressions:** All 39 non-MU-plugin callers of `frl_has_access()` are in standard post-`plugins_loaded` contexts.

### Fixed `@` Suppression Detection in Error Handler (2026-04-28)
- **Bug:** [`frl_errors_handle_error()`](core/error-handler.php:101) only checked `error_reporting() === 4437` to detect `@`-suppressed errors. In PHP 8.0+, `@` sets `error_reporting()` to `0`, not `4437`, so suppressed warnings (like `unserialize()` errors from excluded plugins' corrupted term meta) were being logged.
- **Fix:** Added `$current_reporting === 0` check alongside the existing `4437` check at [line 148](core/error-handler.php:148). Now correctly suppresses `@`-silenced errors on both PHP < 8.0 and PHP 8.0+.

### Cache System PHPDoc & Comment Audit (2026-04-28)
- **Audited** all PHPDocs, `@param`/`@return` blocks, and internal comments in [`core/cache/`](core/cache/) (3 files)
- **7 inaccuracies corrected:**
  1. [`cache-cleanup.php:11`](core/cache/cache-cleanup.php:11) — "Register term-change hooks" → "On init, register term-change hooks that trigger rewrite flush"
  2. [`cache-cleanup.php:198`](core/cache/cache-cleanup.php:198) — "nav and blocks groups" → "metafields group" (matched to actual `FRL_CACHE_DEPENDENCIES`)
  3. [`cache-cleanup.php:203-205`](core/cache/cache-cleanup.php:203) — Navigation cache doc/param updated to reflect both `save_post_wp_navigation` and `wp_update_nav_menu` usage
  4. [`cache-cleanup.php:210`](core/cache/cache-cleanup.php:210) — "Simply clear the navigation cache group" → "Clear the wp_navigation key within the permalinks group"
  5. [`cache-cleanup.php:245`](core/cache/cache-cleanup.php:245) — Tracked meta guard comment simplified for typed `int` param
  6. [`class-cache-manager.php`](core/cache/class-cache-manager.php) — 3 stale comments updated (`"New tracking"`, `"New feature"`, `"REMOVED"`); `purge_all()` `@return` updated for early-return case
  7. [`class-cache-operations.php:150`](core/cache/class-cache-operations.php:150) — `get_operation_map()` `@return` from generic `array` to typed shape
- **2 code bugs patched:**
  - [`atomic_clear_group()`](core/cache/class-cache-manager.php:1478) return type normalized — maps `clear_group_with_dependencies()` output to documented `$stats` shape for consistent return regardless of cache backend (lines 1488-1496)
  - [`frl_clear_navigation_cache()`](core/cache/cache-cleanup.php:208) ID namespace collision — split into separate `frl_clear_navigation_cache()` (wp_navigation post) and new `frl_clear_menu_cache()` (nav_menu term) with distinct `wp_navigation_`/`wp_menu_` key prefixes (lines 17-18, 202-230)

### Cache System Review & Fixes Applied (2026-04-28)
- **Comprehensive review** of `core/cache/` system written to `plans/cache-system-review.md`
- **6 fixes applied:**
  1. ✅ `'all_options, false'` string-as-parameter bug at [`functions-options.php:124`](includes/helpers/functions-options.php:124) & [:726](includes/helpers/functions-options.php:726) — changed to separate arguments. Dependency skipping now works.
  2. ✅ Auth cookie side-effect extracted — [`with_auth_preservation()`](core/cache/class-cache-manager.php:828) wrapper method with docblock
  3. ✅ Double `serialize()` eliminated — [`frl_sanitize_for_serialization()`](core/cache/class-cache-manager.php:504) output used directly
  4. ✅ `purge_all()` double work eliminated — [`$transients_batch_deleted`](core/cache/class-cache-manager.php:47) flag skips redundant per-group transient deletion
  5. ✅ LRU size made configurable — [`FRL_CACHE_RUNTIME_MAX_ITEMS = 1000`](config/config-cache.php:148) constant
  6. ✅ Unrecognized group warning — [`frl_log()`](core/cache/class-cache-manager.php:1195) fires when group is in no config array
- **Issues retracted after code review:** pre_option filter removal (plugin's own namespace only), $loaded_groups reset (only on full clears), auto_preload overhead (batching is optimal), all-static design and non-filterable constants (by user direction)
- **Remaining:** `metadata` group not in `FRL_CACHE_PERSISTENT_GROUPS` — affects transient-only sites only
- (Review content archived in [`docs/CACHE.md §11`](docs/CACHE.md:565) — plan file deleted as obsolete)

---
### Cache System Architectural Reference Written (2026-04-28)
- **Wrote comprehensive [`docs/CACHE.md`](docs/CACHE.md)** — synthesized from source files (`core/cache/`), config files (`config/config-cache*.php`), and existing plan reviews (`plans/cache-system-review.md`, `plans/cache-orchestrator-implementation.md`)
- Covers: architecture overview, file map, group configuration, `Frl_Cache_Manager` internals (LRU, persistent cache, provider detection, language keys, dependency cascading, clearing tiers, atomic ops, race prevention, auth preservation), `Frl_Cache_Operations` orchestrator, cleanup hooks, helper API reference, clearing behavior reference, performance considerations, and complete bug/fix history
- Serves as a future synthetic reference for developers to understand caching features and behavior without reading every source file

---

## Rewriter Module Plugability (2026-04-28)

- **Problem:** `frl_rewriter_register_features` action fired at coordinator construction time (`plugins_loaded/5`), before module files were loaded — modules couldn't register features.
- **Fix:** Moved `do_action()` + `usort()` from `register_features()` (constructor) to a `plugins_loaded/7` hook in `register_hooks()`. Now modules load at `plugins_loaded/5` → action fires at `plugins_loaded/7` → features sorted → registered at `init:15`.
- **Scope:** ~3 lines moved in [`class-rewriter-coordinator.php`](core/rewriter/class-rewriter-coordinator.php). Zero regressions verified.
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

### Orchestrator Integration — EM cache clears now use `Frl_Cache_Operations`
- **What:** All EM-triggered cache clears now go through `Frl_Cache_Operations::run()` for centralized visibility.
- **Changes:**
  - **Added `clear_options` and `clear_rewriter`** as orchestrated `clear_*` operations in [`FRL_CACHE_OPERATIONS`](../config/config-cache-operations.php) — previously these bypassed the orchestrator (direct `Frl_Cache_Manager::clear_group_with_dependencies()`).
  - **Added both to `$orchestrated_groups`** in [`frl_cache_clear()`](../includes/helpers/functions-class-helpers.php:178) — now `frl_cache_clear('options')` and `frl_cache_clear('rewriter')` route through the orchestrator, giving full visibility in `get_operation_map()`.
  - **Added 3 `env_*` operations** — `env_enforce_full`, `env_enforce_url_change`, `env_enforce_options` — mapping to each decision path in the change-type classifier. `env_enforce_none` was removed per user direction (guard pattern: orchestrator call is skipped entirely when `$env_op` is empty).
  - **Refactored classifier** in [`class-environment-manager.php`](../core/environment/class-environment-manager.php) — selects the `env_*` operation key and dispatches via `Frl_Cache_Operations::run($env_op)` (guarded: only runs when `$env_op` is non-empty) instead of calling `frl_cache_clear()` / `frl_schedule_admin_rewrite_flush()` directly.
- **Result:** The orchestrator now has three tiers — `clear_*` (helpers), `action_*` (admin actions), `env_*` (environment manager). Every cache clearing path is documented in one place.
- **Reference:** [`plans/env-manager-orchestrator-integration.md`](../plans/env-manager-orchestrator-integration.md)

### C1 — Change-type classifier for cache clears
- **Problem:** `enforce_environment_settings()` always called `frl_cache_clear('all')` after *any* environment change, regardless of what changed. This was wasteful for option-only changes and missed `flush_rewrite_rules()` for plugin/module changes.
- **Findings:**
  - `frl_thirdparty_maybe_notify('all')` notifies **zero** plugins — no outbound hook listens for `'all'` trigger (only `'hard'` and `'rewrite_flush'`)
  - Module changes DO require rewrite flush (modules register features via `add_feature()` at `plugins_loaded/5`)
  - The monolithic `frl_cache_clear('all')` was redundant with targeted clears inside apply methods (double-clearing)
- **Fix:** Replaced the monolithic clear with a change-type classifier at [`class-environment-manager.php:236`](../core/environment/class-environment-manager.php:236):
  - Plugin/module changes → full purge + rewrite flush
  - `siteurl`/`home` changes → full purge
  - Option-only changes → options group clear
  - Force mode → full purge (preserved)
  - Nothing changed → no clear at all
  - *Now dispatches via `Frl_Cache_Operations::run('env_enforce_*')`*

### C1+ — Remove redundant targeted clears
- **Problem:** `apply_plugin_options()` and `apply_modules_options()` each independently called `frl_cache_clear('options')` when changes were made (non-force mode), but these were immediately overwritten by the parent `frl_cache_clear('all')`.
- **Fix:** Removed `$clear_cache_on_update` variable and `frl_cache_clear('options')` calls from both methods in [`class-environment-applier.php`](../core/environment/class-environment-applier.php). All cache clearing is now centralized in the change-type classifier in `enforce_environment_settings()`.

### P4 — Consolidate 15+ `update_option_{$name}` hooks
- **Problem:** `setup_plugin_options_tracking()` registered individual `update_option_{$prefixed_name}` hooks for each managed option — creating N closures and N hook bucket entries per admin page load.
- **Fix:** Replaced with a single `updated_option` hook using an O(1) lookup map at [`class-environment-monitor.php:16`](../core/environment/class-environment-monitor.php:16):
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

---

## Performance Audit Optimizations (2026-04-29)

### Initial Audit
- Performed comprehensive codebase performance audit — 60+ files reviewed
- Initial report: [`plans/performance-audit-report.md`](plans/performance-audit-report.md) with 8 findings
- User challenged 4 findings; all re-investigated with evidence

### Revised Findings (after user feedback)
- **Retracted:** Re-entrancy guard in `frl_get_option()` — confirmed intentional exception-safety pattern (`try/finally` with `$reset=true`). Also used in `class-cache-manager.php:765-767` for `cleanup_expired_transients()`, confirming deliberate convention.
- **Retracted:** Polylang `icl_register_string` — confirmed via official Polylang docs: `icl_register_string` stores strings permanently in DB (WPML-compatible), while `pll_register_string` registers for runtime translation with auto-cleanup on deactivation. Both are officially supported Polylang functions.
- **Retracted:** Admin widget caching — architecture is intentionally designed with outer widget cached via `frl_cache_remember` and inner tables using `$bypass_cache=true` to avoid double-caching. Documented in `ADMIN-UI.md` and inline comments.
- **Validated:** `frl_disable_comments()` WHERE clause — patch applied (see below)
- **Confirmed no issue:** `frl_get_critical_css_data()` disk reads (cached at multiple levels), module `file_exists()` calls (behind static request-level caches), error handler block capture (triple caching, only activates with `WP_DEBUG_LOG`), EM config merge (triple-guarded)
- Full revised report: [`plans/revised-performance-audit.md`](plans/revised-performance-audit.md)

### Performance Rating Analysis
- Current rating: **90.2/100 (B+/A-)** across 7 weighted categories
- Identified 4 optimization paths to reach A (95+)
- Full analysis: [`plans/performance-rating-analysis.md`](plans/performance-rating-analysis.md)

### Patches Applied
1. **frl_disable_comments() WHERE clause** — [`includes/shared/website-features.php:219`](includes/shared/website-features.php:219): Added `'comment_status' => 'open'` to `$wpdb->update()` WHERE clause. Prevents unnecessary DB writes on subsequent calls.
2. **Autoload optimizations** — [`config/config-options.php`](config/config-options.php): Changed 8 admin-only options to `autoload='no'` (login_branding, debug_display, error_reporting_email/notice/warning/deprecated/plugin/suppressed). Reduces `wp_load_alloptions()` memory footprint.
3. **Lazy-load subsystems** — [`fralenuvole.php:86-134`](fralenuvole.php:86): Refactored `frl_load_core_components()` to conditionally load EM/translator/rewriter based on their disable options. Saves PHP parse time, memory, and init overhead when subsystems are disabled.

### Analysis Performed (No Code Change)
- **Query optimization by autoload:** Analyzed [`frl_get_plugin_options_db()`](includes/helpers/functions-options.php:280). Recommended against filtering by autoload column — gain is only on cold cache (rarest scenario), LIKE on indexed column is already ~0.1ms, lazy-load mechanism would increase admin DB queries.

## Thirdparty Helper + CSS Extraction (2026-04-30)
- **New helper:** [`frl_is_thirdparty_plugin_active()`](includes/helpers/functions.php:776) — checks if a plugin is active (site-wide or network-wide) using `frl_cache_remember('options', 'thirdparty_active_plugins', callback, WEEK_IN_SECONDS)`.
- **Invalidation:** Cache cleared in [`frl_purge_mu_plugin_exclusion_cache()`](core/cache/cache-cleanup.php:296) on `activated_plugin`/`deactivated_plugin` hooks.
- **Cache manager refactored:** [`_is_plugin_globally_active()`](core/cache/class-cache-manager.php:146) delegates to the public helper, eliminating duplicated logic.
- **Meow CSS extracted** from [`admin.css`](modules/thirdparty/assets/css/admin.css) into [`admin-meow.css`](modules/thirdparty/assets/css/admin-meow.css) — ~46% of the original file now conditionally enqueued.
- **Array-based plugin detection:** [`frl_thirdparty_admin_scripts()`](modules/thirdparty/thirdparty.php:39) loops through `['ai-engine/ai-engine.php', 'seo-engine/seo-engine.php']` and enqueues Meow CSS when any match is found.

## Exception Handler Patch — Catch PHP 7+ Throwable types (2026-04-30)

### Root Cause
- `set_error_handler()` cannot intercept PHP 7+ uncaught `Throwable` types: `TypeError`, `ValueError`, `DivisionByZeroError`, `ArgumentCountError`. These slip through to PHP's default exception handler regardless of suppression rules.
- The plugin previously only registered `set_error_handler()` — no `set_exception_handler()` existed.

### Changes Applied

#### [`core/error-handler.php`](core/error-handler.php)
1. **`frl_errors_init()` line 27** — Added `set_exception_handler('frl_errors_handle_exception')` alongside existing `set_error_handler()`
2. **`$rebind` closure line 34** — Added `set_exception_handler()` to the re-bind closure (protects both `muplugins_loaded/PHP_INT_MAX` and `plugins_loaded/PHP_INT_MAX` hooks)
3. **New function [`frl_errors_handle_exception(Throwable $e): void`](core/error-handler.php:304)** — Maps exception types to error levels, delegates to existing [`frl_errors_handle_error()`](core/error-handler.php:105) for consistent formatting, suppression rules, and logging. Has independent recursion guard.

### Verification
- ✅ PHP syntax valid (`php -l core/error-handler.php` — no errors)
- ✅ No `set_exception_handler()` calls existed previously — confirmed via codebase grep
- ✅ All 6 `frl_errors_handle_error` references accounted for — no signatures changed
- ✅ Zero regressions: No existing function signatures changed, no hooks removed/modified, suppression rules apply identically
- ✅ Both error and exception handlers have independent `static $is_handling` recursion guards

### Re-Entrancy Hardening (2026-04-30)
- Refactored both [`frl_errors_handle_error()`](core/error-handler.php:105) and [`frl_errors_handle_exception()`](core/error-handler.php:304) from manual `$guard = false` at each return point to `try/finally` pattern.
- **Before:** 5 manual reset points in error handler — brittle: if a new early return was added without reset, the guard would permanently disable the handler.
- **After:** Single `finally` block guarantees reset regardless of exit path or unexpected exceptions from internal calls (`frl_log_add_details`, etc.).
- **Consistent with** [`frl_errors_handle_doing_it_wrong()`](core/error-handler.php:214) which already used `try/finally`.
- ✅ PHP syntax valid
- ✅ No behavioral changes — all return values identical

## Cron Filter Simplified — Removed `!is_numeric` Guard (2026-05-01)

### Context
- The 2026-04-30 fix used an accumulator pattern that required explicit version preservation: `if (isset($cron['version'])) { $filtered['version'] = $cron['version']; }`.
- This was correct for clean data but fragile — if corrupt data lacked the version key, `_upgrade_cron_array()` would re-corrupt it.
- Refactored to **in-place modification** (2026-05-01) to eliminate the need for version preservation (skipped keys stay in `$cron`).

### Regression
- Accidentally added `!is_numeric($timestamp)` guard thinking it was needed for version protection.
- This caused corrupted entries (where md5 hashes like `40cd750bba9870f18aada2478b24840a` sit at the timestamp level) to be **skipped** instead of processed. The old accumulator code processed them and dropped them from the output.
- Errors persisted: `could_not_set` for hooks `wp_update_user_counts`, `greenshift_check_cron_hook`, `docketcache_optimizedb`, `wp_update_themes`, `wp_update_plugins`.

### Fix Applied
- **Removed** `|| !is_numeric($timestamp)` from the first-level foreach guard. Now only `!is_array($hooks)` checks remain.
- **Rationale:** `!is_array($hooks)` already handles `version => 2` — 2 is not an array, so it's skipped. The key stays in `$cron` naturally. No version-specific handling needed.
- **Scope narrowed:** Filter now does **only** what it should:
  1. Remove events with unregistered schedules (orphaned from excluded plugins)
  2. Set null args to `[]`
  3. Return — no corrupted data cleanup, no structure fixing
- Existing DB corruption still requires manual cleanup: `wp option delete cron`

## Cron Filter Refactored — In-Place Modification (2026-05-01)

### Root Cause (User Feedback)
- The user challenged the philosophical correctness of the filter approach: "what goes in, goes out. Why do we remove the version from the array, if we only need to remove the excluded events?"
- The previous fix (2026-04-30) rebuilt the array from scratch (`$filtered = []` accumulator) and then conditionally preserved the version: `if (isset($cron['version'])) { $filtered['version'] = $cron['version']; }`.
- This was fragile: **if corrupt data entered without a `version` key, the conditional would skip it, and the corruption cycle would perpetuate.** The fix was correct for clean data but not robust for corrupted data.

### Changes Applied

#### [`includes/mu/functions-mu.php`](includes/mu/functions-mu.php:387)
- **Changed `frl_add_exclusion_filter_cron()`** from accumulator pattern to in-place modification:
  - **Before:** `$filtered = []; foreach ($cron as $ts => $hooks) { if (!is_array($hooks)) continue; ... $filtered[$ts] = ... } if (isset($cron['version'])) { $filtered['version'] = $cron['version']; } return $filtered;`
  - **After:** `foreach ($cron as $ts => $hooks) { if (!is_array($hooks) || !is_numeric($ts)) continue; ... unset($cron[$ts][$hook][$hash]); ... } return $cron;`
- **Key change:** `!is_numeric($timestamp)` guard ensures `version` and any future metadata keys are never iterated over, never touched, never evaluated. The filter literally does not see them.
- **Orphan cleanup:** Changed from "skip in accumulator" to `unset()` on the original array — same effect, simpler code.
- **Args sanitization:** Changed from "copy with modified args to accumulator" to direct assignment `$cron[$ts][$hook][$hash]['args'] = []`.
- **Removed:** The explicit `isset($cron['version'])` preservation block (lines 457-464 old) — no longer needed because version is never removed.

### Verification
- ✅ PHP syntax valid (`php -l includes/mu/functions-mu.php` — no errors)
- ✅ "What goes in, goes out" — filter returns the same array structure, only removing orphaned events and sanitizing null args
- ✅ Version and any metadata keys pass through completely untouched — no conditional preservation logic to fail
- ✅ Existing DB corruption still requires manual cleanup: `wp option delete cron`

## Subdomain Adapter Module (2026-05-04)

### Context
- The plugin operates on `pbservices.ge` with Polylang and 4 languages as subfolders. A mirror copy of the site runs on `ru.pbservices.ge`.
- Need: bidirectional URL transformation between main domain and subdomain — Russian content URLs from `pbservices.ge/ru/*` to `ru.pbservices.ge/*`, and cross-language links back to main domain from the subdomain.
- Must be extensible for future subdomains (e.g., `ar.pbservices.ge`, `ru.pbproperty.ge`).

### Architecture Decision
- **Not** registered as a rewriter feature — rewriter's path utils hardcode `home_url()` for domain base, have no concept of domain swapping.
- **Composition** at `PHP_INT_MAX` (after rewriter's p10 and Polylang's p20) — receives already-path-transformed URLs and applies domain-level transformation.
- **`pll_get_current_language` filter** at p10 on subdomain — the ONLY real filter in Polylang 3.7+ that controls `PLL()->curlang` during language resolution. Returns `PLL_Language` object, making Polylang treat subdomain's language as default, generating clean URLs with zero `str_replace` cost.
- Uses plugin's own `frl_get_language()` and `frl_translator_is_enabled()` helpers instead of raw Polylang calls.

### Files Created
1. **`modules/subdomain_adapter/config-constants-subdomain-adapter.php`** — `FRL_SUBDOMAIN_ADAPTER_MAP` (main_domain → {lang → subdomain, default_lang → lang})
2. **`modules/subdomain_adapter/class-subdomain-adapter.php`** — `Frl_Subdomain_Adapter` singleton with:
   - `detect()` — reads HTTP_HOST, O(1) map lookups, sets instance properties
   - `is_configured()`, `is_on_subdomain()` — public query methods
   - `register_hooks()` — lazy, only when on a configured domain; `frl_is_already_running()` guard
   - `filter_pll_get_current_language()` — key mechanism, returns `PLL_Language` object for subdomain's language
   - `filter_pll_language_home_url()` — correct home URLs for hreflang/switcher (non-cached path)
   - `filter_pll_additional_language_data()` — correct home URLs in language object's cached `home_url`
   - `filter_pll_check_canonical_url()` — prevents Polylang canonical redirects on subdomains
   - `filter_post_link()`, `filter_post_type_link()`, `filter_page_link()`, `filter_term_link()`, `filter_canonical_url()`, `filter_tsf_canonical_url()` — URL transformation at PHP_INT_MAX
   - `transform_url()` — 4 cases: main+default (no-op), main+mapped (swap domain), subdomain+target (no-op), subdomain+cross (swap to main + add prefix)
   - `redirect_non_target_content()` — 301 redirect on subdomain for non-target content
   - Guard pattern on all filters: `is_admin()`, `frl_is_rest_api_request()`, `is_preview()`, `!frl_translator_enabled()`, `!frl_get_language()`
3. **`modules/subdomain_adapter/subdomain_adapter.php`** — Module entry point with header metadata, defensive constant check, singleton init

### Files Modified
4. **`config/environment/config-defaults.php`** — Added `'subdomain_adapter' => false` to `FRL_ENV_DEFAULT['modules']`
5. **`config/environment/config-environment.php`** — Added `'subdomain_adapter' => true` to `FRL_ENV_PBS_TEMPLATE['modules']` (inherited by PBS production, RU subdomain, and staging)

### Key Design Properties
- **Zero-cost target URLs:** On subdomain, `pll_get_current_language` filter makes Polylang generate clean URLs natively — no `str_replace` needed for the most common case.
- **Early exits:** Hooks only register when on a configured domain. Each filter bails on admin/REST/preview.
- **Re-entrancy:** `frl_is_already_running()` guard in `register_hooks()`.
- **Extensibility:** Add entries to `FRL_SUBDOMAIN_ADAPTER_MAP` + env config for new subdomains. Zero class changes.
- **Cross-environment support:** Per-entry `main_domain` enables `ru.pbproperty.ge` → `pbproperty.ge` mappings.
- **Performance:** No DB queries, no regex. Pure `wp_parse_url()` + string operations with per-request static transform cache.

*Last Updated: 2026-05-04*

### Subdomain Adapter — page_link Fix (2026-05-12)
- **Problem:** Subdomain adapter transformed permalinks for posts but NOT for pages. CPT 'service' also not transforming (under investigation with debug logging).
- **Root cause (pages):** WordPress `page_link` filter passes `$post->ID` (int) as the second argument, but [`filter_page_link()`](modules/subdomain_adapter/class-subdomain-adapter.php:557) expected a `WP_Post` object. The `instanceof` check failed silently, returning the link unchanged.
- **Fix:** [`filter_page_link()`](modules/subdomain_adapter/class-subdomain-adapter.php:557) now normalizes integer `$post` to `WP_Post` via `get_post()` before the `instanceof` check.
- **Debug logging added:** [`filter_post_link_internal()`](modules/subdomain_adapter/class-subdomain-adapter.php:505) now logs when `frl_get_language()` returns empty (WP_DEBUG-gated) to help diagnose CPT 'service' issue.
- **Filter execution order verified correct:** Polylang registers at `plugins_loaded/1`, Subdomain Adapter at `plugins_loaded/5`. Both use priority 20 for `post_link`/`post_type_link`/`page_link`. Polylang runs FIRST → Subdomain Adapter runs SECOND. This is the intended order.
- **File changed:** [`modules/subdomain_adapter/class-subdomain-adapter.php`](modules/subdomain_adapter/class-subdomain-adapter.php)

### MU Plugin Throttle — Modularity Refactor (2026-05-12)
- **Problem:** Inline throttle logic in [`mu-plugin.php:28-65`](includes/mu/mu.php) broke the established pattern (logic → functions file, orchestration → loader). Used raw WordPress transients without the plugin's static caching layer. Hardcoded `429` magic number.
- **Solution:** Extracted into [`frl_maybe_throttle_user_agent()`](includes/mu/functions-mu.php:31) in `functions-mu.php`, called inline from `mu-plugin.php`. Preserves early top-level execution before any WordPress output.
- **Changes:**
  - Added [`FRL_MU_THROTTLE_STATUS_CODE`](config/config-mu.php:43) constant (429) to `config/config-mu.php`
  - Switched from `get_transient`/`set_transient` to `frl_get_transient`/`frl_set_transient` for static caching + consistent prefixing
  - `mu-plugin.php` reduced from 60 lines to 24 lines — purely orchestrator (require + hook + function call)
- **Files:** [`config/config-mu.php`](config/config-mu.php), [`includes/mu/functions-mu.php`](includes/mu/functions-mu.php), [`includes/mu/mu.php`](includes/mu/mu.php)

### Subdomain Adapter — Homepage Language Switcher URL Fix (2026-05-12)
- **Bug:** On `staging.pbservices.ge` EN homepage, Polylang language switcher generated `https://staging.pbservices.ge/ru/` instead of `https://ru.pbservices.ge/` for the RU link.
- **Root cause (two-part):**
  1. **Dead `pll_get_home_url` hook** — this filter doesn't exist in Polylang 3.7+. The real mechanism is `pll_additional_language_data` (sets `PLL_Language::$home_url` at creation time) and `pll_language_home_url` (only when caching disabled).
  2. **All URL hooks at p20** — same priority as Polylang's handlers. Since Subdomain Adapter loads first (`plugins_loaded/5` vs `plugins_loaded/10`), same-priority hooks executed in wrong order. Polylang's `PLL_Static_Pages::page_link()` overrode already-transformed URLs.
- **Fix (two-part):**
  - **Part A:** Replaced `pll_get_home_url` with [`pll_additional_language_data`](modules/subdomain_adapter/class-subdomain-adapter.php:328) (p20) to set correct subdomain URL in language object's cached `home_url`. Also added [`pll_language_home_url`](modules/subdomain_adapter/class-subdomain-adapter.php:319) (p20) for non-cached path. Added methods [`filter_pll_additional_language_data()`](modules/subdomain_adapter/class-subdomain-adapter.php:482) and [`filter_pll_language_home_url()`](modules/subdomain_adapter/class-subdomain-adapter.php:417). Removed dead `filter_pll_get_home_url()`.
  - **Part B:** Changed all URL hooks from p20 to **`PHP_INT_MAX`** at [`register_hooks()`](modules/subdomain_adapter/class-subdomain-adapter.php:340-345): `post_link`, `post_type_link`, `page_link`, `term_link`, `wpseo_canonical`, `the_seo_framework_meta_render_data`.
- **File:** [`modules/subdomain_adapter/class-subdomain-adapter.php`](modules/subdomain_adapter/class-subdomain-adapter.php)
- **Plan:** [`plans/fix-subdomain-adapter-homepage-link.md`](plans/fix-subdomain-adapter-homepage-link.md)
- **Status:** Applied. Awaiting staging verification.

*Last Updated: 2026-05-14*

## Subdomain Adapter — Legacy URL Handling (2026-05-14)

### Problem
Hardcoded legacy URLs (e.g., `pbservices.ge/ru/services/`) exist in post content, block content, and navigation menus. These need runtime transformation to match the current domain context (main domain or subdomain) without DB search-replace operations.

### Changes Applied

#### [`modules/subdomain_adapter/class-subdomain-adapter.php`](modules/subdomain_adapter/class-subdomain-adapter.php)
1. **`transform_url()` visibility** (line 792): `private` → `public` — enables the legacy class to transform menu item URLs using the object's language.
2. **4 public accessor methods** added after `is_on_main_domain()` (lines 277-311):
   - `get_domain_map(): array` — returns the `$domain_map` property
   - `get_subdomain_info(): array` — returns the `$subdomain_info` property
   - `get_current_host(): string` — returns the `$current_host` property
   - `get_subdomain_lang(): ?string` — returns the `$current_subdomain_lang` property

#### [`modules/subdomain_adapter/class-subdomain-adapter-legacy.php`](modules/subdomain_adapter/class-subdomain-adapter-legacy.php) (NEW, ~350 lines)
- **Class:** `Frl_Subdomain_Adapter_Legacy`
- **Singleton** with `init()`, private `__construct()`, `register_hooks()`
- **4 hooks registered:**
  - `template_redirect` (p6) → `redirect_legacy_incoming_url()` — 301-redirects legacy URLs with language prefix to canonical domain
  - `the_content` (PHP_INT_MAX) → `filter_the_content()` — transforms hardcoded URLs in post HTML
  - `render_block` (PHP_INT_MAX) → `filter_render_block()` — transforms block URLs with fast-fail + cache
  - `wp_nav_menu_objects` (PHP_INT_MAX) → `filter_nav_menu_objects()` — transforms menu item URLs
- **Supporting methods:** `should_transform()`, `get_target_host_for_language()`, `build_redirect_target()`, `transform_urls_in_html()`, `transform_single_content_url()`, `resolve_target_host()`, `get_recognized_hosts()`
- **Defensive gates:** `is_admin`, `frl_is_rest_api_request`, `is_preview`, `frl_is_cron_job_request`, `frl_is_already_running`
- **Static caches:** `$block_cache` (render_block dedup), `$hosts` (get_recognized_hosts, computed once per request)
- **Fast-fail strategies:** `str_contains('pbservices.ge')` guard in render_block, `$likely_has_urls` block name pre-check

#### [`modules/subdomain_adapter/subdomain_adapter.php`](modules/subdomain_adapter/subdomain_adapter.php)
- Added require + init for the legacy class after `Frl_Subdomain_Adapter::init()` (lines 30-32)

### Regression Prevention
- Zero existing hooks modified — all legacy hooks run at PHP_INT_MAX after existing filters
- `transform_url()` made public but signature unchanged — existing private callers unaffected
- All new hooks have the same defensive gates as the parent class
- Redirect loop prevention via URL comparison before `wp_redirect()`
- Relative URLs untouched (regex only matches absolute URLs with recognized hosts)
- Regex only matches `href` and `action` attributes — `src` excluded (matched assets resolve identically on both domains)

### Feature Toggle (2026-05-14)
- Legacy handler gated behind `frl_get_option('subdomain_adapter_legacy_links')` in [`subdomain_adapter.php:31`](modules/subdomain_adapter/subdomain_adapter.php:31)
- Option defined in [`config-options-subdomain_adapter.php:18`](modules/subdomain_adapter/config-options-subdomain_adapter.php:18): default `1` (enabled), `restricted => true`

### Code Review Bug Fix (2026-05-14)
- **Bug:** `transform_urls_in_html()` hardcoded `href=` in the `preg_replace_callback` replacement, corrupting `src=` and `action=` attributes.
- **Fix:** Attribute name now captured (`(href|action)`) and used in the replacement string. `src` removed from pattern entirely per user direction (mirrored assets don't need cross-domain URLs).

### Cross-Environment Fix — Hardcoded Domain (2026-05-14)
- **Problem:** `filter_render_block()` in [`class-subdomain-adapter-legacy.php:290`](modules/subdomain_adapter/class-subdomain-adapter-legacy.php:290) hardcoded `'pbservices.ge'` in `str_contains` fast-fail guard. Broke cross-environment portability (staging, future `pbproperty.ge`).
- **Fix:** Replaced with dynamic loop over `$this->get_recognized_hosts()` — O(h) where h ≤ 10, still faster than the regex alternative. Works across all configured environments without code changes.

### Block List Extracted to Constant (2026-05-14)
- **Change:** `$likely_has_urls` inline array in `filter_render_block()` moved to `FRL_SUBDOMAIN_ADAPTER_LEGACY_URL_BLOCKS` in [`config-constants-subdomain-adapter.php`](modules/subdomain_adapter/config-constants-subdomain-adapter.php:47).
- **Rationale:** A constant is immediately extensible — add block names to the array, done. No `add_filter()` calls, no module code, no hook registration. Third-party block types (Kadence, GenerateBlocks, etc.) can be added by editing one file.
- **Backward-compatible:** Same default values, same behavior. The constant is loaded before the class file by [`subdomain_adapter.php`](modules/subdomain_adapter/subdomain_adapter.php:16).

- Plan: [`plans/subdomain-adapter-legacy-url-handling.md`](plans/subdomain-adapter-legacy-url-handling.md)

### Redirect Loop Fix — `pll_check_canonical_url` Filter (2026-05-19)
- **Problem:** `ru.pbservices.ge/ru/novosti/` ↔ `ru.pbservices.ge/novosti/` cross-request redirect loop between Polylang P4 (adds `/ru/`) and legacy adapter P6 (strips `/ru/`).
- **Fix:** Added `add_filter('pll_check_canonical_url', ...)` to [`register_hooks()`](modules/subdomain_adapter/class-subdomain-adapter.php:385) and [`filter_pll_check_canonical_url()`](modules/subdomain_adapter/class-subdomain-adapter.php:493) callback.
- **Why not `remove_action()`:** The `pll_check_canonical_url` filter is Polylang's own API for conditionally canceling canonical redirects (same pattern used internally by `PLL_Frontend_Static_Pages` for static front pages).
- **Scope:** Only cancels redirects when on a subdomain AND content language matches subdomain language. Zero impact on main domain.
- **Plan:** [`plans/fix-redirect-loop-pll-check-canonical-url.md`](plans/fix-redirect-loop-pll-check-canonical-url.md)

## Translation Fallback Refactoring (2026-05-25)

### Problem
`frl_get_default_language_fallback()` and `frl_get_active_languages_fallback()` were hardcoded to Polylang's internal DB schema, violating the adapter pattern's plugin-independence goal.

### Solution — Adapter Self-Contained Fallbacks
- **Added `FRL_TRANSLATOR_DEFAULT_LANG`** constant in [`config/config-translator.php:18`](config/config-translator.php:18)
- **Moved adapter `require_once`** to [`translator.php:14`](core/translator/translator.php:14) — adapter always available after module loads
- **Added private internal fallbacks** to [`Frl_Polylang_Adapter`](core/translator/adapters/polylang.php:111): `get_default_language_internal()`, `get_active_languages_internal()`
- **Updated global helpers** in [`functions-translator-helpers.php:241`](includes/helpers/functions-translator-helpers.php:241) to delegate to adapter via `class_exists` check
- **Removed duplicate `require_once`** from [`class-translation-service.php:62`](core/translator/class-translation-service.php:62)
- **Zero regression:** All 10 call sites traced, 8 edge cases verified, subdomain adapter behavior unchanged

### Documentation Updated
- [`docs/TRANSLATOR.md`](docs/TRANSLATOR.md) — Added fallback architecture, constants
- [`docs/SUBDOMAIN-ADAPTER.md`](docs/SUBDOMAIN-ADAPTER.md) — Fixed incorrect filter names
- [`memory-bank/`](memory-bank/) — All 4 files updated

### Plans Directory Cleaned
- Deleted entire `plans/` directory (10 obsolete files)
