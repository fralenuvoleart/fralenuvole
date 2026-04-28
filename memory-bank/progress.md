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
  - Updated `docs/PLUGIN-EXCLUSIONS-FEATURE.md` with new file references
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

### Fixes Applied (pending user confirmation)
- **Fixed `index.php` dashboard screen matching** — `$pagenow` is null during `muplugins_loaded` because `wp-includes/vars.php` loads at `wp-settings.php:524`, after `muplugins_loaded` at line 511. Added `$_SERVER['SCRIPT_NAME']` fallback to `frl_is_admin_page()`.
- **Added cron args sanitization** — Ensures `$event['args']` is always an array in `pre_option_cron` filter to prevent `TypeError: count(): Argument #1 must be of type Countable|array, null given` at `class-wp-hook.php:325`.
- **Fixed cron filter early-exit bug** — Cron filter was gated behind `if (!empty($excluded))`, so it was never added during cron when only backend exclusion was enabled (capability exclusion disabled). Moved cron filter addition before the empty-exclusion check so it always registers during WP Cron, ensuring args sanitization runs unconditionally.

### Refactored Early-Loading Access Check (2026-04-28)
- **Moved `!did_action('plugins_loaded')` logic out of [`frl_has_access()`](includes/helpers/functions-access-control.php:95)** — the early-loading code path was only used by the MU plugin's capability-based exclusion. Now handled by dedicated [`frl_mu_check_access()`](includes/helpers/functions-mu-plugin.php:91) in the MU plugin helper file.
- **Added cross-request caching to [`frl_get_auth_cookie_user_data()`](includes/helpers/functions-access-control.php:64)** — DB query wrapped in `frl_cache_remember('admin', 'auth_cookie_user_' . $username, ..., 300)`. Username-scoped key, 300s TTL aligned with `frl_has_access()` standard path.
- **Updated [`frl_plugins_exclusion_filter()`](includes/helpers/functions-mu-plugin.php:190)** — call site now uses `frl_mu_check_access()` instead of `frl_has_access()`.
- **Docblock updated** on `frl_has_access()` to reference `frl_mu_check_access()` for early-loading scenarios.
- **Zero regressions:** All 39 non-MU-plugin callers of `frl_has_access()` are in standard post-`plugins_loaded` contexts.

---
*Last Updated: 2026-04-28*
