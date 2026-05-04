# Active Context

## 📚 Memory Configuration
- Rules loaded from: `memory-bank/mandatory-rules.md`
- Design principles stored in memory-mcp entity: UserDesignPrinciples

## 🔄 Current Focus
Fralenuvole v5.7.0 - WordPress multilingual administrator plugin with URL rewriting, multilingual support, multi-backend caching, environment-based configuration, and subdomain adapter.

## ✅ Subdomain Adapter Module (2026-05-04)
- **New module `subdomain_adapter`** implemented at [`modules/subdomain_adapter/`](modules/subdomain_adapter/)
- **Purpose:** Bidirectional URL transformation between main domain and language-specific subdomain mirrors
- **Key mechanism:** `pll_default_language` filter (p1) on subdomain makes Polylang treat the subdomain's language as default, generating clean URLs with zero `str_replace` cost
- **Files created:**
  - [`modules/subdomain_adapter/config-constants-subdomain-adapter.php`](modules/subdomain_adapter/config-constants-subdomain-adapter.php) — `FRL_SUBDOMAIN_ADAPTER_MAP` and `FRL_SUBDOMAIN_ADAPTER_MAIN_DEFAULTS`
  - [`modules/subdomain_adapter/class-subdomain-adapter.php`](modules/subdomain_adapter/class-subdomain-adapter.php) — `Frl_Subdomain_Adapter` singleton handler with `transform_url()`, filter methods, `pll_default_language` switch
  - [`modules/subdomain_adapter/subdomain_adapter.php`](modules/subdomain_adapter/subdomain_adapter.php) — Module entry point
- **Files modified:**
  - [`config/environment/config-defaults.php`](config/environment/config-defaults.php:46) — Added `'subdomain_adapter' => false` to `FRL_ENV_DEFAULT['modules']`
  - [`config/environment/config-environment.php`](config/environment/config-environment.php:27) — Added `'subdomain_adapter' => true` to `FRL_ENV_PBS_TEMPLATE['modules']`
- **Hook architecture:**
  - `pll_default_language` at p1 — switch default language on subdomain
  - `pll_current_language` at p2 — safety net for language detection
  - `pll_get_home_url` at p20 — correct home URLs for hreflang/switcher
  - `post_link`, `post_type_link`, `page_link`, `term_link`, `wpseo_canonical` at p20 — URL transformation (after rewriter at p10)
  - `template_redirect` at p5 — 301 redirect non-target content on subdomain
- **Early exits:** Guards for `is_admin()`, `frl_is_rest_api_request()`, `is_preview()`, `!frl_translator_enabled()`, `!frl_get_language()`. Hooks only register when on a configured `main_domain` or mapped subdomain.
- **Re-entrancy:** `frl_is_already_running()` guard in `register_hooks()`
- **Performance:** Target-language URLs on subdomain have zero `str_replace` cost — the `pll_default_language` filter handles clean URL generation natively.
- **Extensibility:** Add entries to `FRL_SUBDOMAIN_ADAPTER_MAP` + env config for new subdomains. Zero class code changes needed.

## 🏗️ Architecture Overview
- **Error Handler:** Dual interception via `set_error_handler()` (PHP errors: `E_WARNING`, `E_NOTICE`, `E_DEPRECATED`, etc.) and `set_exception_handler()` (PHP 7+ uncaught Throwable: `TypeError`, `ValueError`, `DivisionByZeroError`, `ArgumentCountError`). Both registered at the earliest possible moment during MU plugin bootstrap. The exception handler [`frl_errors_handle_exception()`](includes/core/error-handler.php:304) delegates to the existing [`frl_errors_handle_error()`](includes/core/error-handler.php:105) for consistent formatting, suppression rules, and logging.
- **Feature-based rewriter:** Independent feature classes that self-register. The extension hook (`frl_rewriter_register_features`) fires at `plugins_loaded/7` (after module init at `plugins_loaded/5`) so module-loaded features participate in priority sorting. Module features not listed in `FRL_REWRITER_PRIORITIES` default to priority 99.
- **5-backend cache system:** Litespeed, Docket Cache, Redis, Memcached, Transients
- **3-tier options cascade:** Static → Persistent → DB with value normalization
- **Hook priority discipline:** plugins_loaded/5, init/10, init/15, init/20
- **MU Plugin Loader:** `assets/mu/frl-mu-plugin.php` (bootstrap) → `includes/helpers/functions-mu-plugin.php` (logic) — plugin exclusion feature with persistent caching of `active_plugins` via `frl_cache_remember` in the `options` group (WEEK_IN_SECONDS TTL). Network active plugins also cached via a separate `frl_cache_remember` key. Cache invalidation on `activated_plugin`/`deactivated_plugin` hooks. Capability-based exclusion uses [`frl_mu_check_access()`](includes/helpers/functions-mu-plugin.php:91) for early-loading access checks (before `plugins_loaded`), which delegates to [`frl_has_access()`](includes/helpers/functions-access-control.php:95) once WordPress is fully loaded.
- **Access Control Caching:** [`frl_get_auth_cookie_user_data()`](includes/helpers/functions-mu-plugin.php:90) DB query now cached via `frl_cache_remember('admin', 'auth_cookie_user_' . $username, ..., 300)` — 300s TTL aligned with [`frl_has_access()`](includes/helpers/functions-access-control.php:95) standard path. Consistent "access control decisions cached for 5 minutes" rule across both functions. Both functions now live in [`functions-mu-plugin.php`](includes/helpers/functions-mu-plugin.php) alongside their sole consumer.
- **Cron query fix (applied):** In [`frl_get_exclusion_options()`](includes/helpers/functions-mu-plugin.php:30), the cron DB query is now guarded behind [`frl_is_cron_job_request()`](includes/helpers/functions-access-control.php:475). On non-cron requests, `$options['cron'] = []` is set without touching the DB. On cron requests, fresh cron data is fetched via `$wpdb->get_var()`. Cron data is intentionally NOT cached via `frl_cache_remember` because stale cron data would cause duplicate event execution — the `cron` option changes on every WP-Cron execution cycle. Request-level static cache in `frl_get_exclusion_options():32` already deduplicates within a single request.
- **Translation Module:** Adapter-based architecture decoupling the service from translation providers (Polylang/WPML), utilizing deferred registration via `shutdown` hook and multi-level caching.
- **Cache Operations:** `Frl_Cache_Operations` (`includes/core/cache/class-cache-operations.php`) — runtime dispatcher for composite cache operations. The operation definitions live in `FRL_CACHE_OPERATIONS` constant (`config/config-cache-operations.php`, loaded via `config/config.php`). **Three-tier design:**
  - **`clear_*` operations** (`clear_hard`, `clear_all`, `clear_light`, `clear_options`, `clear_rewriter`): Helper-level operations that `frl_cache_clear()` delegates to for composite cache groups. Each enumerates granular steps with inline `note` fields documenting deferred chains. `clear_options` and `clear_rewriter` added 2026-04-29 to give `clear_group_with_dependencies('options'/'rewriter')` full orchestrator visibility.
  - **`action_*` operations** (`action_hard`, `action_flush_rewrite_rules`, `action_clear_plugin_transients`, `action_clear_website_transients`, `action_clear_scripts_tags`): Admin-action-level operations called from action handlers. Compose `clear_*` ops with additional steps (e.g., rewrite flush).
  - **`env_*` operations** (`env_enforce_full`, `env_enforce_url_change`, `env_enforce_options`): Environment Manager operations triggered by `enforce_environment_settings()`. Added 2026-04-29 — each maps to a specific decision path in the change-type classifier. The classifier now dispatches via `Frl_Cache_Operations::run($env_op)` (guarded: only runs when `$env_op` is non-empty) instead of calling `frl_cache_clear()` / `frl_schedule_admin_rewrite_flush()` directly. `env_enforce_none` was removed — when nothing changed, the orchestrator call is skipped entirely.
  - **No critical flag:** All steps execute sequentially regardless of failure; caller inspects per-step results.
  - **`fn` supports callable arrays:** `[ 'Frl_Cache_Manager', 'hard_cache_reset' ]` for static method calls, checked via `is_callable()`.
  - **All existing helper functions preserved** (`frl_cache_clear`, `frl_schedule_admin_rewrite_flush` remain independently callable). `frl_cache_clear('hard'/'all'/'light'/'options'/'rewriter')` returns `$result['steps'][0]['result']` for backward compatibility with external callers.

## ⚠️ Active Considerations
- **Exception handler added (2026-04-30):** `set_exception_handler('frl_errors_handle_exception')` installed at all three registration points — [`frl_errors_init()`](includes/core/error-handler.php:27), `muplugins_loaded/PHP_INT_MAX` re-bind, and `plugins_loaded/PHP_INT_MAX` re-bind. Catches `TypeError`, `ValueError`, `DivisionByZeroError`, `ArgumentCountError` that previously bypassed `set_error_handler()`. Delegates to existing [`frl_errors_handle_error()`](includes/core/error-handler.php:105) so all suppression rules (textlist, `@` operator, `error_reporting_plugin`) apply consistently. Each handler has an independent recursion guard.
- **All three handlers now use `try/finally` for recursion guards** (2026-04-30): [`frl_errors_handle_error()`](includes/core/error-handler.php:105), [`frl_errors_handle_exception()`](includes/core/error-handler.php:304), and [`frl_errors_handle_doing_it_wrong()`](includes/core/error-handler.php:214). Guarantees guard reset even if an unexpected exception propagates through internal calls (e.g., `frl_log_add_details`). Eliminates the brittle manual-reset-at-each-return pattern that previously had 5 exit points in the error handler alone.
- Ensure `init/15` rewriter registration stays strictly after `init/10` environment enforcement.
- Monitor `write_attempted` flag in Options System to ensure zero duplicate DB writes.
- MU plugin `option_cron` filter removes orphaned cron events during WP Cron and sanitizes `args` to array via **in-place modification** (refactored 2026-05-01). Changed from `pre_option_cron` 2026-04-28 because `pre_option_cron` is bypassed when `cron` is in WordPress' autoloaded `alloptions` cache.
- **Filter does only what it should:** Remove events with unregistered schedules (orphaned from excluded plugins) and fix null args to `[]`. The `!is_array($hooks)` check naturally skips `version => 2` (2 is not an array) — no explicit version handling needed. Existing DB corruption passes through unchanged; clean it with `wp option delete cron`.
- Cron `args = NULL` in DB are pre-existing — placed by plugins calling `wp_schedule_event()` with null. The exclusion system is read-only (never writes to DB). The NULL errors were previously masked by excluded plugins' error handlers.
- Backend exclusion in MU plugin uses `frl_is_admin_page()` to match screens; `frl_textlist_to_array()` already handles `|` pipe format. **Timing**: `$pagenow` is null during `muplugins_loaded` (vars.php loads after), so `frl_is_admin_page()` falls back to `$_SERVER['SCRIPT_NAME']`.
- Three-tier exclusion: Frontend (context) → Backend (screen) → Capability (user) — applied in priority order.
- **Cache recursion safety:** `frl_cache_remember` uses object cache/transients (never the option system), so it is safe inside `pre_option_*` / `pre_site_option_*` filters. The `$wpdb->get_var()` fallback remains as the callback to avoid the `pre_site_option` filter chain.
- **Static keys with explicit invalidation** — MU plugin cache keys are static strings (`mu_plugin_active_plugins`, `mu_plugin_network_active_plugins`) with `activated_plugin`/`deactivated_plugin` invalidation. No version-based auto-invalidation needed since hooks cover all activation paths.
- **Caching hierarchy in MU plugin:**
  - L1: Closure `static $cache` — dedup filtering step (per-request)
  - L2: `frl_get_exclusion_options()` `static $options` — dedup entire function (per-request, protects cron query)
  - L3: `frl_cache_remember` runtime cache — dedup persistent cache lookup (per-request)
  - L4: `frl_cache_remember` persistent cache — avoid DB query (cross-request)

## 📁 Documentation
- `docs/ARCHITECTURE.md` - Plugin overview
- `docs/CACHE.md` - Cache system architectural reference (synthesized from source + plans)
- `docs/HOOKS.md` - Critical hook priorities
- `docs/REWRITER.md` - Rewriter subsystem architecture
- `docs/PLUGIN-EXCLUSIONS.md` - Plugin exclusion feature (updated with caching details)
- `plans/translator-regression-analysis.md` - Translator regression bugs (unfixed — keep)

## ✅ Cache Documentation (2026-04-28)

### Recent Audit — Cache Docs Reviewed & Fixed
- **Audited** all PHPDocs, internal comments, and `@param`/`@return` blocks in [`includes/core/cache/`](includes/core/cache/)
- **7 inaccuracies corrected** across 3 files:
  - [`cache-cleanup.php`](includes/core/cache/cache-cleanup.php): stale dependency comment, navigation param description, init-registration phrasing, tracked-meta guard comment
  - [`class-cache-manager.php`](includes/core/cache/class-cache-manager.php): stale "New" tracking comment, `purge_all()` early-return `@return` type, stale "REMOVED" fallback comment, stale "New feature" comment
  - [`class-cache-operations.php`](includes/core/cache/class-cache-operations.php): `get_operation_map()` `@return` updated from generic `array` to specific shape
- **2 code bugs patched:**
  - [`atomic_clear_group()`](includes/core/cache/class-cache-manager.php:1478) return type normalized — maps `clear_group_with_dependencies()` output to documented `$stats` shape for consistent return regardless of cache backend
  - [`frl_clear_navigation_cache()`](includes/core/cache/cache-cleanup.php:208) ID namespace collision — split into separate `frl_clear_navigation_cache()` (wp_navigation post) and new `frl_clear_menu_cache()` (nav_menu term) with distinct `wp_navigation_`/`wp_menu_` key prefixes
- Principle: **code is truth, comments reflect the code**

## ✅ Rewriter Module Plugability (2026-04-28)

### Applied
- **Moved `do_action()` + `usort()`** from coordinator constructor to `plugins_loaded/7` hook in [`register_hooks()`](includes/core/rewriter/class-rewriter-coordinator.php:174)
- Now: modules load at `plugins_loaded/5` → `frl_rewriter_register_features` fires at `plugins_loaded/7` → features sorted → registered at `init:15`
- Zero regressions verified — only 2 internal references to the hook, no external dependents
- Added clear module plugability documentation in [`docs/REWRITER.md`](docs/REWRITER.md) and code comments in the coordinator
- Updated [`plans/rewriter-module-plugability.md`](plans/rewriter-module-plugability.md) with final approach

---

## ✅ Environment Manager Patches Applied (2026-04-29)

### C1 — Change-type classifier for cache clears
- **File:** [`class-environment-manager.php:236-268`](../includes/core/environment/class-environment-manager.php:236)
- **What:** Replaced monolithic `frl_cache_clear('all')` with a change-type classifier that inspects `$results` after all apply methods run.
- **Logic (original):**
  - Force mode → `frl_cache_clear('all')`
  - Plugin or module changes → `frl_cache_clear('all')` + `frl_schedule_admin_rewrite_flush()`
  - `siteurl`/`home` changes → `frl_cache_clear('all')`
  - Option-only changes → `frl_cache_clear('options')`
  - Nothing changed → no clear at all

### C1+ — Removed redundant targeted clears from apply methods
- **Files:** [`class-environment-applier.php:183-186`](../includes/core/environment/class-environment-applier.php:183), [`class-environment-applier.php:228-231`](../includes/core/environment/class-environment-applier.php:228)
- **What:** Removed the `$clear_cache_on_update` variable and `frl_cache_clear('options')` calls from both `apply_plugin_options()` and `apply_modules_options()`.
- **Rationale:** These were architecturally redundant with the central change-type classifier. They caused double-clearing in non-force mode (targeted clear + parent `all` clear). Centralizing all cache clearing in `enforce_environment_settings()` simplifies the apply methods and eliminates wasted I/O.

### P4 — Consolidated 15+ `update_option_{$name}` hooks into single `updated_option`
- **File:** [`class-environment-monitor.php:16-40`](../includes/core/environment/class-environment-monitor.php:16)
- **What:** Replaced per-option `add_action("update_option_{$prefixed_name}", ...)` with a single `add_action('updated_option', ...)` using an O(1) lookup map (`prefixed_name → config_key`).
- **Key detail:** `updated_option` passes 3 args (`$option, $old_value, $new_value`) — the old hooks passed 2 (`$old_value, $new_value`). The `add_action()` call specifies `3` as `$accepted_args`.
- **Win:** Reduces closure allocation from N (~15) to 1; simplifies code; adapts automatically to config changes.

### C1 Orchestrator Integration (2026-04-29)
- **What:** Refactored EM cache clears to go through `Frl_Cache_Operations::run()` instead of calling `frl_cache_clear()` / `frl_schedule_admin_rewrite_flush()` directly.
- **Changes:**
  - Added `clear_options` and `clear_rewriter` as proper `clear_*` operations in [`FRL_CACHE_OPERATIONS`](../config/config-cache-operations.php) — previously these bypassed the orchestrator (direct `Frl_Cache_Manager::clear_group_with_dependencies()` calls).
  - Added both to the `$orchestrated_groups` map in [`frl_cache_clear()`](../includes/helpers/functions-class-helpers.php:178) so all `frl_cache_clear('options'/'rewriter')` calls now route through the orchestrator.
  - Added 3 new `env_*` operations (`env_enforce_full`, `env_enforce_url_change`, `env_enforce_options`) that map to the classifier's decision paths. `env_enforce_none` was removed — the orchestrator call is now guarded (skipped when `$env_op` is empty).
  - Refactored the classifier in [`class-environment-manager.php`](../includes/core/environment/class-environment-manager.php) to select an `env_*` operation key and dispatch via `Frl_Cache_Operations::run($env_op)` (guarded).
  - The orchestrator now covers **all** cache clearing paths in a single `FRL_CACHE_OPERATIONS` registry — `clear_*` (helpers), `action_*` (admin actions), and `env_*` (environment manager).
- **Zero regression risk:** Return values from `Frl_Cache_Operations::run()` are not used by the EM (only `$results` is used for UI). `frl_cache_clear()` return format unchanged via `$result['steps'][0]['result']` passthrough.
- **Reference:** [`plans/env-manager-orchestrator-integration.md`](../plans/env-manager-orchestrator-integration.md)

### Key research finding — Third-party notification is a no-op during enforcement
- [`frl_thirdparty_maybe_notify('all')`](../modules/thirdparty/thirdparty.php:230) notifies **zero** plugins because no outbound hook in [`FRL_THIRDPARTY_OUTBOUND_HOOKS`](../modules/thirdparty/config-constants-thirdparty.php:81) listens for the `'all'` trigger. Only `'hard'` and `'rewrite_flush'` have listeners (LiteSpeed, Breeze, WP Rocket).
- This means the current `frl_cache_clear('all')` at the old line 230 never actually notified third-party caches. The C1 change removes this no-op call for option-only changes without introducing any regression.

---

---

## ✅ Performance Audit Patches Applied (2026-04-29)

### Autoload Optimizations
- **8 admin-only options** changed from `autoload='yes'` to `autoload='no'` in [`config/config-options.php`](config/config-options.php):
  - `login_branding` — only checked on `login_enqueue_scripts`
  - `debug_display` — admin-only debug setting
  - `error_reporting_email`, `error_reporting_notice`, `error_reporting_warning`, `error_reporting_deprecated`, `error_reporting_plugin`, `error_reporting_suppressed` — only active when `WP_DEBUG_LOG` is enabled
- **Benefit:** Reduces WordPress `wp_load_alloptions()` memory footprint on every request. Options are lazy-loaded via `frl_get_option()` on first access.

### Query Optimization — Recommended Against
- Analyzed [`frl_get_plugin_options_db()`](includes/helpers/functions-options.php:280) which uses `LIKE {prefix}%` query without autoload filter.
- **Decision:** Do NOT filter by autoload column. Gain is only on cold cache (rarest scenario). LIKE on indexed `option_name` column is already ~0.1ms. Lazy-load mechanism would increase DB queries on admin pages.
- Real benefit is already achieved at WordPress core level via `wp_load_alloptions()` being smaller after autoload changes above.

### Lazy-Load Subsystems
- **File:** [`fralenuvole.php:86-134`](fralenuvole.php:86) — `frl_load_core_components()` refactored:
  - **EM:** Gated behind `!frl_get_option('disable_environment')` — skips require_once + init when disabled
  - **Translator:** Gated behind `frl_is_multilingual_active() && !frl_get_option('disable_translator')` — skips entire translator.php load when no multilingual plugin active or translator disabled. `frl_is_multilingual_active()` is safe to call early (checks WP/PLL constants defined during plugin file inclusion, before `plugins_loaded`)
  - **Rewriter:** Gated behind `!frl_get_option('disable_rewriter')` — skips require_once + init when disabled
  - **Themekit:** Kept unconditional (no master disable toggle exists)
- **Benefit:** On frontend with defaults (EM+translator+rewriter all enabled), zero change. On sites that disable any subsystem, saves PHP parse time, memory, and init overhead.
- **Safety:** All `add_action('init', ...)` hooks remain registered unconditionally — each init function has internal guards (`frl_environment_is_loaded()`, `frl_is_already_running()`) that handle the case where the subsystem file wasn't loaded.

### frl_disable_comments() WHERE Clause
- **File:** [`includes/shared/website-features.php:219`](includes/shared/website-features.php:219)
- **Patch:** Added `'comment_status' => 'open'` to the `$wpdb->update()` WHERE clause
- **Before:** Updated ALL publish posts every time, even those already `closed`
- **After:** Only updates posts where comments are still `open` — zero DB writes on subsequent calls

## ✅ Thirdparty Helper + CSS Extraction (2026-04-30)

### New Helper: [`frl_is_thirdparty_plugin_active()`](includes/helpers/functions.php:776)
- **Location:** [`includes/helpers/functions.php:776`](includes/helpers/functions.php:776)
- **Purpose:** Checks if a third-party plugin is active (site-wide or network-wide).
- **Caching:** Uses [`frl_cache_remember()`](includes/helpers/functions-class-helpers.php:108) with `WEEK_IN_SECONDS` TTL in the `'options'` group — persistent caching since `get_option('active_plugins')` only changes on activate/deactivate.
- **Invalidation:** Key `'thirdparty_active_plugins'` is cleared in [`frl_purge_mu_plugin_exclusion_cache()`](includes/core/cache/cache-cleanup.php:296) on `activated_plugin`/`deactivated_plugin` hooks.
- **Multisite-safe:** Handles both `get_option('active_plugins')` and `get_site_option('active_sitewide_plugins')`.
- **Refactored:** [`_is_plugin_globally_active()`](includes/core/cache/class-cache-manager.php:146) in the cache manager now delegates to this public helper, eliminating duplicated logic.

### Meow CSS Extraction
- **Extracted** Meow-specific CSS from [`admin.css`](modules/thirdparty/assets/css/admin.css) into dedicated [`admin-meow.css`](modules/thirdparty/assets/css/admin-meow.css) (~164 lines).
- **Conditional enqueue:** [`frl_thirdparty_admin_scripts()`](modules/thirdparty/thirdparty.php:39) uses an array of known Meow plugin paths (`ai-engine/ai-engine.php`, `seo-engine/seo-engine.php`) and loops until one is found active — Meow CSS only enqueued when a match is hit.
- **Benefit:** ~46% of original admin.css is now conditionally loaded. Maintainability improved (separate file for Meow tweaks).

*Last Updated: 2026-04-30*