# Active Context

## 📚 Memory Configuration
- Rules loaded from: `memory-bank/mandatory-rules.md`
- Design principles stored in memory-mcp entity: UserDesignPrinciples

## 🔄 Current Focus
Fralenuvole v5.4.0 - WordPress multilingual administrator plugin with URL rewriting, multilingual support, multi-backend caching, and environment-based configuration.

## 🏗️ Architecture Overview
- **Feature-based rewriter:** Independent feature classes that self-register
- **5-backend cache system:** Litespeed, Docket Cache, Redis, Memcached, Transients
- **3-tier options cascade:** Static → Persistent → DB with value normalization
- **Hook priority discipline:** plugins_loaded/5, init/10, init/15, init/20
- **MU Plugin Loader:** `assets/mu/frl-mu-plugin.php` (bootstrap) → `includes/helpers/functions-mu-plugin.php` (logic) — plugin exclusion feature with persistent caching of `active_plugins` via `frl_cache_remember` in the `options` group (WEEK_IN_SECONDS TTL). Network active plugins also cached via a separate `frl_cache_remember` key. Cache invalidation on `activated_plugin`/`deactivated_plugin` hooks. Capability-based exclusion uses [`frl_mu_check_access()`](includes/helpers/functions-mu-plugin.php:91) for early-loading access checks (before `plugins_loaded`), which delegates to [`frl_has_access()`](includes/helpers/functions-access-control.php:95) once WordPress is fully loaded.
- **Access Control Caching:** [`frl_get_auth_cookie_user_data()`](includes/helpers/functions-mu-plugin.php:90) DB query now cached via `frl_cache_remember('admin', 'auth_cookie_user_' . $username, ..., 300)` — 300s TTL aligned with [`frl_has_access()`](includes/helpers/functions-access-control.php:95) standard path. Consistent "access control decisions cached for 5 minutes" rule across both functions. Both functions now live in [`functions-mu-plugin.php`](includes/helpers/functions-mu-plugin.php) alongside their sole consumer.
- **Cron query fix (applied):** In [`frl_get_exclusion_options()`](includes/helpers/functions-mu-plugin.php:30), the cron DB query is now guarded behind [`frl_is_cron_job_request()`](includes/helpers/functions-access-control.php:475). On non-cron requests, `$options['cron'] = []` is set without touching the DB. On cron requests, fresh cron data is fetched via `$wpdb->get_var()`. Cron data is intentionally NOT cached via `frl_cache_remember` because stale cron data would cause duplicate event execution — the `cron` option changes on every WP-Cron execution cycle. Request-level static cache in `frl_get_exclusion_options():32` already deduplicates within a single request.
- **Translation Module:** Adapter-based architecture decoupling the service from translation providers (Polylang/WPML), utilizing deferred registration via `shutdown` hook and multi-level caching.
- **Cache Operations:** `Frl_Cache_Operations` (`includes/core/cache/class-cache-operations.php`) — runtime dispatcher for composite cache operations. The operation definitions live in `FRL_CACHE_OPERATIONS` constant (`config/config-cache-operations.php`, loaded via `config/config.php`). **Two-tier design:**
  - **`clear_*` operations** (`clear_hard`, `clear_all`, `clear_light`): Helper-level operations that `frl_cache_clear()` delegates to for the three composite cache groups. Each enumerates granular steps (e.g., `hard_cache_reset()` + `frl_thirdparty_maybe_notify()`) with inline `note` fields documenting deferred chains.
  - **`action_*` operations** (`action_hard`, `action_flush_rewrite_rules`, `action_clear_plugin_transients`, `action_clear_website_transients`, `action_clear_scripts_tags`): Admin-action-level operations called from action handlers. Compose `clear_*` ops with additional steps (e.g., rewrite flush). No `action_all` or `action_light` exists — those action handlers call `clear_all`/`clear_light` directly since they don't add extra steps.
  - **No critical flag:** All steps execute sequentially regardless of failure; caller inspects per-step results.
  - **`fn` supports callable arrays:** `[ 'Frl_Cache_Manager', 'hard_cache_reset' ]` for static method calls, checked via `is_callable()`.
  - **All existing helper functions preserved** (`frl_cache_clear`, `frl_schedule_admin_rewrite_flush` remain independently callable). `frl_cache_clear('hard'/'all'/'light')` returns `$result['steps'][0]['result']` for backward compatibility with external callers.

## ⚠️ Active Considerations
- Ensure `init/15` rewriter registration stays strictly after `init/10` environment enforcement.
- Monitor `write_attempted` flag in Options System to ensure zero duplicate DB writes.
- MU plugin `pre_option_cron` filter removes orphaned cron events (unregistered schedules) during WP Cron to prevent `invalid_schedule` errors. Also sanitizes `args` to array to prevent `TypeError` on null args.
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
- `docs/ARCHITECTURAL-REVIEW.md` - Plugin overview
- `docs/CACHE.md` - Cache system architectural reference (synthesized from source + plans)
- `docs/HOOKS.md` - Critical hook priorities
- `docs/REWRITER.md` - Rewriter subsystem architecture
- `docs/PLUGIN-EXCLUSIONS-FEATURE.md` - Plugin exclusion feature (updated with caching details)
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

---
*Last Updated: 2026-04-28*
