# Environment Manager — Comprehensive Architecture Review

**Date:** 2026-04-29  
**Version:** Fralenuvole v5.6.0  
**Scope:** [`includes/core/environment/`](includes/core/environment/) (9 files) + [`config/environment/`](config/environment/) (config + snippets)

---

## 1. WHY — Problem the Environment Manager Solves

The **Environment Manager (EM)** solves a real, painful multi-site/multi-brand operational problem:

- **One plugin codebase** deployed across **6+ distinct websites** (`pbservices.ge`, `pbproperty.ge`, `pbnova.com`, `fralenuvole.art`, their staging mirrors, and the RU subdomain).
- Each website has **different plugin requirements, different WP options (`blog_public`), different header/footer HTML, different active modules, and different debug/reporting profiles**.
- A standard WordPress plugin cannot natively enforce domain-specific configuration — it would need manual per-site option changes.

**The EM solves this by:**

1. **Domain-based auto-configuration:** On every request, the incoming `HTTP_HOST` is matched against [`FRL_ENV_MAP`](config/environment/config-environment.php:8) to determine which environment profile to load.
2. **`pre_option_*` filter enforcement:** Instead of the user manually setting options in the DB, the EM *overrides* WP option reads at runtime, or *writes* the correct values to the DB when an admin visits.
3. **Cross-domain URL synchronization:** When a site is accessed via a wrong domain (e.g., production domain on staging server), [`Frl_Environment_Monitor::check_urls()`](includes/core/environment/class-environment-monitor.php:104) auto-updates `siteurl` and `home` to match the current host.
4. **Plugin management per environment:** Different plugins are active on staging (`query-monitor`) vs production (`litespeed-cache`), without requiring manual plugin (de)activation per environment.
5. **Environment switcher in admin bar:** Quick navigation between staging/production counterparts and all sibling domains.

**Assessment:** The WHY is well-motivated. The problem is real for the multi-brand setup. The architecture correctly uses domain-based detection as a reliable signal (as opposed to file-based or DB-based environment detection which can be stale).

---

## 2. Architecture

### 2.1 High-Level Structure

```
config/environment/
  ├── config-defaults.php          ← Base defaults + staging overrides + master template
  ├── config-environment.php        ← FRL_ENV_MAP + brand templates
  └── env-snippets/                 ← Per-brand header/footer PHP files

includes/core/environment/
  ├── environment-manager.php       ← Bootstrap loader (requires 7 sub-files)
  ├── class-environment-utils.php   ← Host normalizer trait + Frl_Environment_Utils (logging)
  ├── class-environment-config.php  ← Domain config builder + merge engine
  ├── class-environment-state.php   ← State persistence + change detection
  ├── class-environment-monitor.php ← URL checking + option/plugin tracking
  ├── class-environment-applier.php ← WP options, plugin options, modules application
  ├── class-environment-files.php   ← File-based option loading with PHP syntax validation
  ├── class-environment-plugin-manager.php ← Plugin activation/deactivation
  └── class-environment-manager.php ← Main facade: init(), enforce_settings(), get_config(), etc.
```

### 2.2 Init Sequence

```
fralenuvole.php
  └── plugins_loaded/5
       └── frl_load_core_components()
            ├── require environment-manager.php (all 7 classes loaded)
            ├── frl_environment_init()
            │    └── Frl_Environment_Manager::init()
            │         ├── if user has access → Frl_Environment_Monitor::check_urls()
            │         │    └── If host ≠ siteurl/home → update + auth cookie refresh + redirect
            │         ├── Frl_Environment_Monitor::setup_plugin_options_tracking()
            │         └── Register hooks: admin_bar_menu, activated/deactivated_plugin
            │
            └── add_action('init/10', 'frl_environment_enforce_settings')

init/10
  └── frl_environment_enforce_settings()
       └── Frl_Environment_Manager::enforce_environment_settings()
            ├── Guard: frl_has_access() – admins only (or migrate mode)
            ├── Guard: Throttle (60s admin, 300s non-admin, 0 if forced/migrate)
            ├── Guard: Skip if already running (frl_is_already_running / class flag)
            ├── Frl_Environment_Config::get_domain_config() → cached build
            ├── Frl_Environment_State::check_environment_state()
            │    └── Compare stored host/siteurl/home with current
            ├── If state changed OR forced:
            │    ├── Frl_Environment_Applier::apply_wordpress_options()
            │    ├── Frl_Environment_Applier::apply_plugin_options()
            │    ├── Frl_Environment_Plugin_Manager::apply_plugins_activation_status()
            │    ├── Frl_Environment_Applier::apply_modules_options()
            │    ├── frl_cache_clear('all')
            │    ├── Persist state + cache
            │    └── If state_changed && !force: clear website transients + log + redirect
            └── Return $results
```

### 2.3 Configuration Merge Stack (bottom wins)

```
1. FRL_ENV_DEFAULT              ← Universal baseline
2. FRL_ENV_DEFAULT_PRODUCTION   ← Production diffs (usually empty)
   OR FRL_ENV_DEFAULT_STAGING   ← Staging diffs (QM, blog_public=0, debug=1)
3. Brand template (extends)     ← FRL_ENV_PBS_TEMPLATE, etc. (optional, one-level only)
4. Instance constant            ← FRL_ENV_PBS_PRODUCTION, etc. (from FRL_ENV_MAP)
```

### 2.4 Class Responsibilities

| Class | Role | Key Methods |
|-------|------|-------------|
| `Frl_Environment_Config` | Config builder | `get_domain_config()`, `build_domain_config()`, `merge_environment_configs()` |
| `Frl_Environment_State` | State persistence | `get_current_state()`, `environment_host_changed()`, `check_environment_state()` |
| `Frl_Environment_Monitor` | Active monitoring | `check_urls()`, `setup_plugin_options_tracking()`, `track_plugin_options()`, `track_plugins_activation_status()` |
| `Frl_Environment_Applier` | Config application | `apply_wordpress_options()`, `apply_plugin_options()`, `apply_modules_options()`, `clear_website_transients_if_needed()` |
| `Frl_Environment_Plugin_Manager` | Plugin lifecycle | `apply_plugins_activation_status()`, `process_plugins_activation_status()`, |
| `Frl_Environment_Files` | File loading | `load_environment_file()`, `get_file_options_keys()` |
| `Frl_Environment_Utils` | Logging | `log_environment_change()` |
| `Frl_Environment_Manager` | Facade | `init()`, `enforce_environment_settings()`, `get_config()`, `reset_customizations()`, `add_environment_switcher()` |
| `Frl_Environment_Host_Normalizer` | Trait | `normalize_host_value()`, `extract_host_from_url()` |

### 2.5 Architecture Assessment

**Strengths:**
- Clean separation of concerns — each class has a single responsibility
- Facade pattern via [`Frl_Environment_Manager`](includes/core/environment/class-environment-manager.php) keeps the public API centralized
- Configuration is declarative (PHP constants) — no runtime parsing of JSON/YAML
- State persistence uses a hash-based change detection that works across host/siteurl/home changes
- Merge stack is well-defined with clear precedence rules

**Weaknesses:**
- The bootstrap file [`environment-manager.php`](includes/core/environment/environment-manager.php) uses procedural `require_once` ordering, which couples load order details to the main plugin — an autoloader would be cleaner
- [`Frl_Environment_Manager`](includes/core/environment/class-environment-manager.php) has mixed concerns: it's both a facade AND contains its own logic (e.g., throttling, result construction, response handling) — violates pure facade pattern
- The `extends` mechanism is documented as "one level only" but the code doesn't enforce this — it just calls `array_diff_key($extends_raw, ['extends' => true])` which silently drops nested extends
- The merge engine in [`class-environment-config.php`](includes/core/environment/class-environment-config.php:150) has duplicated `active_add`/`inactive_add` logic across 3 layers (type, extends, instance) — could be refactored with a helper

---

## 3. Features

### 3.1 Domain Configuration Mapping
- Maps 9 known domains in [`FRL_ENV_MAP`](config/environment/config-environment.php:8) to 9+ instance constants
- Supports `www.`-prefix stripping for matching
- Falls back to `site_url()` when `HTTP_HOST` is unavailable (CLI edge case)

### 3.2 Four-Layer Config Merge
- Base → Type (staging/production) → Extends (brand template) → Instance (specific domain)
- Plugin lists use both full-replacement (`active`/`inactive`) and additive (`active_add`/`inactive_add`) semantics
- Cross-layer consistency: active plugins are removed from inactive lists and vice versa

### 3.3 State Persistence & Change Detection
- Stores host/siteurl/home + MD5 hash in DB option + object cache
- Detects changes by comparing normalized hosts from stored vs current values
- Re-detects on `blog_public` mismatch for staging environments

### 3.4 Auto URL Correction
- On admin visits, if `siteurl`/`home` don't match the current host, they get auto-updated
- Handles auth cookie refresh after URL change to maintain session
- Sets a force-reload flag to ensure the browser navigates to the corrected domain

### 3.5 Plugin Management
- Activates/deactivates plugins based on environment config
- Tracks manual changes via `IGNORE_PLUGINS_KEY` — if an admin manually (de)activates a managed plugin, it's ignored on subsequent runs
- Temporarily removes its own tracking hooks during batch operations to prevent self-triggering

### 3.6 File-Based Options
- Supports loading HTML content from PHP files in [`env-snippets/`](config/environment/env-snippets/)
- Validates PHP syntax before loading using `php -l` CLI
- Caches file content and file option key lists

### 3.7 Option Tracking
- Monitors `update_option_{$prefixed_name}` hooks for managed plugin options
- If an admin manually changes a managed option, it's added to [`FRL_IGNORE_OPTIONS_KEY`](config/environment/config-defaults.php:12)
- Subsequent environment enforcements skip ignored options

### 3.8 Admin Bar Switcher
- Shows current environment type (prod/staging) with indexing status
- Links to the counterpart environment (staging ↔ production for the same brand)
- Submenus for all other sibling domains
- Cached per user via [`frl_cache_remember`](includes/helpers/functions-class-helpers.php:103)

### 3.9 Throttle System
- 60-second throttle for admins, 300-second for non-admins
- Zero throttle when `$force=true` or `FRL_MODE=migrate`
- Bypass: state change detection is checked *before* throttle, ensuring changes aren't missed

### 3.10 Disable Capabilities
- Can be disabled via `disable_environment` WP option
- Can be bypassed via `FRL_MODE=core` URL parameter
- `frl_environment_is_loaded()` gatekeeper function in [`functions-class-helpers.php`](includes/helpers/functions-class-helpers.php:247)

---

## 4. Modularity

### 4.1 Strengths

- **Single-file-per-class:** All 7 environment classes are in individual files under [`includes/core/environment/`](includes/core/environment/) — easy to locate, version, and test independently.
- **Trait for cross-cutting concern:** [`Frl_Environment_Host_Normalizer`](includes/core/environment/class-environment-utils.php:10) is a trait used by both `Frl_Environment_State` and `Frl_Environment_Monitor` — correct use of traits for shared logic.
- **Thin gateway functions:** [`functions-class-helpers.php`](includes/helpers/functions-class-helpers.php) provides 4 gateway functions (`frl_environment_is_loaded`, `frl_environment_init`, `frl_environment_enforce_settings`, `frl_environment_get_config`, `frl_environment_reset_ignored`) that shield the rest of the plugin from class names and option checks.
- **Gatekeeper pattern:** [`frl_environment_is_loaded()`](includes/helpers/functions-class-helpers.php:247) centralizes all "should EM run?" checks — request validity, disable options, class existence.
- **Dependency injection by require:** Sub-classes only `require_once` their direct dependencies (e.g., `class-environment-state.php` requires `class-environment-config.php`), making individual file loading possible.

### 4.2 Weaknesses

- **No autoloader:** All classes are loaded via procedural `require_once` in [`environment-manager.php`](includes/core/environment/environment-manager.php). The order matters. Adding a new class requires updating this file.
- **Mixed static/instance usage:** `Frl_Environment_Manager` uses all-static methods, but other classes like `Frl_Environment_Applier` and `Frl_Environment_Plugin_Manager` are also all-static. This makes unit testing difficult (no dependency injection, no mock substitutes).
- **Tight coupling via facade:** [`Frl_Environment_Manager`](includes/core/environment/class-environment-manager.php) directly references `Frl_Environment_Config::get_domain_config()` and other classes by class name — not interfaces. Swapping implementations requires code changes.
- **Result arrays passed by reference:** [`apply_wordpress_options()`](includes/core/environment/class-environment-applier.php:52) mutates `$results` by reference. This is a PHP 4 pattern that hides side effects. A fluent return or result object would make data flow explicit.

---

## 5. Best Practices

### 5.1 What's Done Well

- **`frl_is_already_running()` re-entrancy guard:** Prevents duplicate execution across the init chain, used in [`enforce_environment_settings()`](includes/core/environment/class-environment-manager.php:123), [`check_urls()`](includes/core/environment/class-environment-monitor.php:104), [`init()`](includes/core/environment/class-environment-manager.php:27), and [`check_environment_state()`](includes/core/environment/class-environment-manager.php:96).
- **Early returns with caching:** [`frl_is_valid_page_request()`](includes/helpers/functions-access-control.php:283) uses static `$is_valid` caching + early return chain — fast and readable.
- **Configuration as constants:** Environment definitions are PHP constants, not in the DB. This means they're under version control, immune to DB corruption, and parsed at opcode level.
- **Throttle with bypass for state changes:** The throttle logic correctly checks state change *before* applying throttle, ensuring environment migrations aren't delayed by the throttle.
- **Hook removal during batch operations:** [`process_plugins_activation_status()`](includes/core/environment/class-environment-plugin-manager.php:74) removes `activated_plugin`/`deactivated_plugin` hooks during batch operations to prevent self-triggering — excellent attention to detail.
- **Graceful degradation:** Every method checks for `null` config and returns safely. Config building logs detailed errors before returning `null`.
- **Cookie-based force reload:** Uses HTTP cookies instead of session or query params — stateless, works across redirects, auto-expires after 1 minute.

### 5.2 What Could Be Improved

- **Magic strings for option names:** `environment_ignore_plugins`, `environment_ignore_options`, `environment_state` are referenced as raw strings in multiple places. These should be constants.
- **Mixed `array_key_exists` vs `isset`:** In [`enforce_environment_settings()`](includes/core/environment/class-environment-manager.php:123), `$_GET[FRL_PREFIX . '_action']` is checked with `isset` (fine, but `$_SERVER['HTTP_REFERER']` is checked with `isset` then parsed — the `parse_url` result isn't validated before use in `str_contains`).
- **`$_SERVER` superglobal scattered across classes:** `$_SERVER['HTTP_HOST']` is accessed in at least 5 different classes. A centralized request abstraction would be cleaner and testable.
- **No type hints on `$results` parameter:** [`apply_wordpress_options()`](includes/core/environment/class-environment-applier.php:52) takes `&$results` without a type hint — both param and return type are implicit `array`.
- **`@phpstan-ignore-line` for constant condition:** [`FRL_ENV_CLEAR_WEBSITE_TRANSIENTS`](config/environment/config-defaults.php:17) is always `true`, but the `if` in `clear_website_transients_if_needed()` checks it regardless — the `// @phpstan-ignore-line alwaysTrue` comment indicates this is a known static analysis issue. Either remove the condition or add a legitimate use case for `false`.
- **No PSR-4 autoloading:** Classes are not namespaced, no `composer.json` autoload section for the environment classes (though the project does have a `composer.json`).

---

## 6. Performance (Very Important)

### 6.1 Critical Path Analysis

The environment manager touches **every admin page load** (and frontend valid page loads via `frl_is_valid_page_request()`). Its performance impact must be minimal.

### 6.2 Caching Strategy

| Cache Point | Type | TTL | Notes |
|-------------|------|-----|-------|
| Domain config [`get_domain_config()`](includes/core/environment/class-environment-config.php:13) | Object cache | 1 hour | MD5-hashed by host. Misses rebuild from constants (fast — no I/O) |
| Environment state [`CACHE_KEY`](includes/core/environment/class-environment-manager.php:191) | Object cache + DB option | 1 hour LRU | Two-tier: object cache first, DB fallback. Populated on first request |
| Last check timestamp [`CACHE_KEY_LAST_CHECK`](includes/core/environment/class-environment-manager.php:200) | Object cache | 1 hour | For throttle — `time()` values, cache hit means throttle active |
| Ignored plugins/options lists | Object cache + DB option | 1 hour | Written on manual changes, read on enforcement |
| File option keys [`get_file_options_keys()`](includes/core/environment/class-environment-files.php:21) | Object cache | 1 hour | Scans filesystem on miss |
| File content [`load_environment_file()`](includes/core/environment/class-environment-files.php:57) | Object cache | 1 hour | Reads PHP file + validates syntax on miss |
| Admin bar submenus | `frl_cache_remember('admin', ...)` | Default TTL | Per-user, per-domain cached |
| Transients cleared flag | Object cache | 1 year | One-time per destination host |

### 6.3 Performance Strengths

- **Config building is O(n) in `FRL_ENV_MAP` size** (currently 9 entries) — trivially fast. Constant lookups are O(1).
- **Early exit chain:** [`frl_environment_is_loaded()`](includes/helpers/functions-class-helpers.php:247) → calls `frl_is_valid_page_request()` which returns `false` for CLI, REST, CRON, non-mapped hosts, heartbeat, log-manager, AJAX — skipping ~80% of non-relevant requests.
- **Static caching:** Methods use `static $result` / `static $is_available` to avoid recomputation within a single request — critical for functions called multiple times during WordPress' lifecycle.
- **Throttle system:** Prevents redundant DB writes/cache clears on every page load. 60-second admin throttle means at most 1 enforcement run/min for a single admin.
- **`frl_cache_clear('options')` is deferred:** In [`apply_plugin_options()`](includes/core/environment/class-environment-applier.php:137) and [`apply_modules_options()`](includes/core/environment/class-environment-applier.php:235), cache clearing is done once **after** all options are written, not per-option.
- **`array_key_exists` over `in_array`:** In `track_plugin_options()`, the check `array_key_exists($option_name, $config['plugin_options'])` is O(1) vs O(n) for `in_array`.

### 6.4 Performance Concerns

1. **`frl_cache_clear('all')` on every change:** [`enforce_environment_settings()`](includes/core/environment/class-environment-manager.php:230) calls `frl_cache_clear('all')` after *any* environment change. This is a full cache flush (object cache, Litespeed, Docket, etc.) — potentially expensive. Consider:
   - If only `blog_public` changed, why clear all caches?
   - The `all` operation runs `hard_cache_reset()` which may notify third-party cache systems.
   - **Impact:** A single `blog_public` sync for staging detection triggers a full cache purge.

2. **Hooked option tracking adds overhead on every admin page:** [`setup_plugin_options_tracking()`](includes/core/environment/class-environment-monitor.php:22) registers `update_option_{$prefixed_name}` hooks for *every* key in `config['plugin_options']`. This means:
   - ~15+ hooks registered per admin request
   - Each hook fires on EVERY option update in the admin area, even if the option is not being changed
   - The callback has a guard (`$old_value === $new_value` → return), but the hook machinery itself has overhead
   - **Impact:** Adds per-update_option overhead to ~15+ managed options across all admin screens.

3. **`check_urls()` runs on every admin request with access:** [`init()`](includes/core/environment/class-environment-manager.php:34) unconditionally calls `check_urls()` for any user with access. This function:
   - Gets current user ID
   - Parses auth cookie
   - Runs `site_url()`, `home_url()` (both may hit DB/multisite tables)
   - Normalizes 3 hosts
   - Compares them
   - **Impact:** 6+ WordPress function calls + host normalization on every admin request, even when nothing changes. The re-entrancy guard prevents duplicate execution but doesn't prevent the initial cost.

4. **`frl_cache_remember` for config in `check_environment_state()`:** The state change check in [`Frl_Environment_State::check_environment_state()`](includes/core/environment/class-environment-state.php:66) calls `Frl_Environment_Config::get_domain_config()` on *every* state check. This is cached at the object cache level (1 hour), but the cache lookup itself has overhead.

5. **`site_url()`/`home_url()` called multiple times per request:** Both in `check_urls()`, `check_environment_state()`, `enforce_environment_settings()`, and the applier. These are not statically cached within the EM — every call hits WordPress' option loading layer.

6. **File syntax validation uses external process:** [`load_environment_file()`](includes/core/environment/class-environment-files.php:57) uses `exec('php -l ...')` for PHP syntax validation. While cached after first call, the cache miss involves:
   - Writing a temp file to disk
   - Spawning a subprocess
   - Parsing output
   - **Impact:** Heavy I/O on first load (mitigated by caching, but cache can be flushed/evicted).

7. **Admin bar submenu cache key varies by user ID:** The submenu cache key includes `$user_id`, meaning every unique admin gets their own cache entry. For sites with many admins, this multiplies cache storage.

### 6.5 Performance Score: **B+**

The EM is well-optimized for its core path (the common case where nothing changes), with caching and early exits covering ~95% of requests. The main concerns are the period full `cache_clear('all')` and the overhead of `check_urls()` on every admin request.

---

## 7. Issues, Bugs, and Logical Flaws

### 7.1 Critical Issues

| # | Issue | File:Line | Severity | Description |
|---|-------|-----------|----------|-------------|
| C1 | **`cache_clear('all')` on non-cache changes** | [`class-environment-manager.php:230`](includes/core/environment/class-environment-manager.php:230) | Medium | Full cache purge happens even if only `blog_public` changed. Destructive on production sites with warmed caches. |
| C2 | **Referer URL not validated in enforce_settings** | [`class-environment-manager.php:137`](includes/core/environment/class-environment-manager.php:137) | Low | `parse_url($_SERVER['HTTP_REFERER'])` returns `false` for malformed URLs → `str_contains(false, ...)` works but is fragile. Should check `is_string($referer)` first. |
| C3 | **`frl_is_valid_environment_host()` uses exact key match** | [`functions-access-control.php:420`](includes/helpers/functions-access-control.php:420) | Medium | `array_key_exists($current_host, FRL_ENV_MAP)` requires exact match including subdomain. The config builder strips `www.` for matching, but `frl_is_valid_environment_host()` does NOT — so `www.pbservices.ge` passes the config builder (matched after www-strip) but fails `frl_is_valid_environment_host()`. |
| C4 | **No cleanup when environment is disabled mid-flight** | [`functions-class-helpers.php:247`](includes/helpers/functions-class-helpers.php:247) | Low | If `disable_environment` is toggled on, the EM skips `init/10` enforcement. But if the EM already ran `plugins_loaded/5` (init), the option tracking hooks remain active. Stale hooks persist for the remainder of the request. |

### 7.2 Logical Flaws

| # | Issue | File:Line | Severity | Description |
|---|-------|-----------|----------|-------------|
| L1 | **State change check ignores `blog_public` → but only for staging** | [`class-environment-state.php:85`](includes/core/environment/class-environment-state.php:85) | Medium | If `blog_public` is manually changed on production (e.g., set to 0 for maintenance), `check_environment_state()` won't re-detect. The `blog_public` re-check is only for staging environments. On production, the EM trusts the config's `blog_public: 1` → but only writes it on state change. So a production `blog_public` change goes unenforced until another state change occurs. |
| L2 | **Throttle + state change interaction can drop events** | [`class-environment-manager.php:185-193`](includes/core/environment/class-environment-manager.php:185) | Low | State change is checked before throttle. If state changed, throttle is bypassed (good). But `last_check_timestamp` is updated AFTER the state check, on the non-forced path. If an admin's first access in 60s triggers a state change + enforcement, the throttle timer starts. If a second state change happens within 60s, the throttle blocks it because `!$force && !$is_migrate && !$state_changed` — wait, `$state_changed` would be true, so throttle is bypassed. Actually this is fine on re-analysis. |
| L3 | **`active_add`/`inactive_add` at type layer can create orphans** | [`class-environment-config.php:214`](includes/core/environment/class-environment-config.php:214) | Low | If type layer uses `active_add` to add plugins to the base list, and instance layer replaces `active` entirely (full replace), the `active_add` additions from type layer are lost. The merge order (instance > extends > type > base) means `active` replacement at a higher layer silently drops lower-layer `_add` additions. |

### 7.3 Documentation vs Code Mismatches

| # | Issue | Severity | Description |
|---|-------|----------|-------------|
| D1 | README says "one level only" for extends but code doesn't enforce | Low | [`config/environment/README.md`](config/environment/README.md) states "Templates cannot extend templates" but [`class-environment-config.php:179`](includes/core/environment/class-environment-config.php:179) only strips `extends` from the resolved array — it doesn't validate that the extends target itself has no `extends` key. A misconfigured nested extends would be silently flattened by `array_diff_key`. |
| D2 | `check_urls()` doc says "admin-only path" but guard is `frl_has_access()` | Low | [`class-environment-monitor.php:104`](includes/core/environment/class-environment-monitor.php:104) comment says "admin-only path" but the guard in [`class-environment-manager.php:34`](includes/core/environment/class-environment-manager.php:34) is `frl_has_access()` which can return true for non-admin users with `FRL_PLUGIN_ACCESS` capability. |

---

## 8. Areas of Improvement

### 8.1 Performance Improvements (Priority: High)

| # | Improvement | Impact | Effort | Description |
|---|-------------|--------|--------|-------------|
| P1 | **`frl_cache_clear('all')` → targeted clear per change type** | **High** | Medium | Replace the full cache purge with group-specific clears. If only `blog_public` changed → `frl_cache_clear('options')`. If plugins changed → cache clear + rewrite flush. If `siteurl`/`home` changed → full clear (justified). Add a change-type classifier to select the appropriate clear level. |
| P2 | **Static cache `site_url()` / `home_url()` within EM** | Medium | Low | Add a static cache for `site_url()` and `home_url()` calls within the EM lifecycle. Currently these are called 3-4 times per request from different classes. A single `EM_SITE_URL` static property would eliminate redundant option reads. |
| P3 | **`check_urls()` guard before expensive operations** | Medium | Low | Move the `site_url()`/`home_url()` + normalization AFTER an early check: compare `$_SERVER['HTTP_HOST']` with a cached/stored host first. If they match, skip all URL checking. 99% of requests will match. |
| P4 | **Optimize `setup_plugin_options_tracking()` hook registration** | Medium | Medium | Instead of registering individual `update_option_{$name}` hooks for each managed option (which creates 15+ closures), register a single `updated_option` hook and check `$option` against the config array. This reduces hook overhead from O(n) to O(1) per request. |
| P5 | **Reduce `frl_cache_remember` calls in state check path** | Low | Low | `check_environment_state()` calls `get_domain_config()` which triggers a cache lookup. Since state is also cached, this double-cache-hit could be consolidated. |

### 8.2 Architectural Improvements (Priority: Medium)

| # | Improvement | Impact | Effort | Description |
|---|-------------|--------|--------|-------------|
| A1 | **PSR-4 autoloader for EM classes** | Medium | Medium | Replace procedural `require_once` chain with Composer autoloader. Simplifies adding new classes, enables IDE autocompletion, aligns with PHP ecosystem standards. |
| A2 | **Interface-based contracts** | Medium | Medium | Define interfaces for `ConfigBuilder`, `StateManager`, `OptionApplier`, `PluginManager`. This would allow swapping implementations (e.g., a Redis-based state store vs DB-based). Currently all classes are tightly coupled by class name. |
| A3 | **Result object instead of by-reference arrays** | Low | High | Replace `&$results` pattern with a `EnvironmentResult` value object. Would make data flow explicit, enable immutable snapshots, and improve testability. However, this is a significant refactor across 4 classes. |
| A4 | **Centralize `$_SERVER['HTTP_HOST']` access** | Low | Low | Create a `RequestContext` class or static method that provides `get_host()`, `get_uri()`, etc. Currently `HTTP_HOST` is accessed raw in 5+ places. A single point of access would make testing (mock `HTTP_HOST`) possible. |

### 8.3 Functional Improvements (Priority: Medium)

| # | Improvement | Impact | Effort | Description |
|---|-------------|--------|--------|-------------|
| F1 | **Fix `frl_is_valid_environment_host()` www mismatch** | **High** | Low | See C3. Add `www.` stripping to the validation function to match `build_domain_config()` behavior. Currently, valid hosts like `www.pbservices.ge` (which the config builder handles) are rejected by the gatekeeper. |
| F2 | **Add `blog_public` monitoring for production** | Medium | Low | See L1. Extend the `blog_public` re-check in `check_environment_state()` to apply to production environments too. Currently, if someone manually sets `blog_public=0` on production, the EM won't re-apply until another state change occurs. |
| F3 | **Add CLI command for environment migration** | Low | Medium | Currently `FRL_MODE=migrate` requires URL parameter. A WP-CLI command (`wp fralenuvole env migrate`) would make automated deployments easier. |
| F4 | **Config validation on plugin activation** | Low | Low | Add a validation step during plugin activation that checks all `FRL_ENV_MAP` constants exist and are arrays. Currently, missing constants are only detected at runtime via `frl_log()`. |

### 8.4 Maintainability Improvements (Priority: Low)

| # | Improvement | Impact | Effort | Description |
|---|-------------|--------|--------|-------------|
| M1 | **Convert magic strings to class constants** | Low | Low | Replace `'environment_ignore_plugins'`, `'environment_ignore_options'`, `'environment_state'` with `Frl_Environment_Manager::IGNORE_PLUGINS_KEY` etc. |
| M2 | **Remove `@phpstan-ignore-line` for always-true constant** | Low | Low | Replace the `if (defined(...) && ...)` with just the body of the condition since `FRL_ENV_CLEAR_WEBSITE_TRANSIENTS` is always `true`. |
| M3 | **Validate extends depth** | Low | Low | Add a validation check in `build_domain_config()` that ensures extended templates don't themselves have `extends`. Add a runtime warning if nested extends are detected. |

### 8.5 Testing Improvements (Priority: Low)

| # | Improvement | Impact | Effort | Description |
|---|-------------|--------|--------|-------------|
| T1 | **Unit tests for merge logic** | Medium | Medium | The `merge_environment_configs()` function has complex logic with `active`/`inactive`/`active_add`/`inactive_add` across 4 layers. This is prime for unit test coverage. Currently untestable due to all-static pattern. |
| T2 | **Integration test for domain matching** | Medium | Medium | Test that `www.` stripping, staging prefix detection, and `FRL_ENV_MAP` matching work correctly across all 9 known domains. |
| T3 | **State change detection tests** | Medium | Medium | Test `environment_host_changed()` with various combinations of stored vs current host/siteurl/home. Ensure scheme/port/path differences are correctly ignored. |

---

## 9. Summary Assessment

### What's Excellent
- **Problem-solution fit:** The EM solves a genuine multi-brand operational challenge with domain-based auto-configuration.
- **Caching discipline:** Three-tier caching (static → object cache → DB), with appropriate TTLs for each data type.
- **Defensive programming:** `null` checks everywhere, re-entrancy guards, hook removal during batch operations.
- **Configuration merge stack:** Clean, well-documented precedence with `active_add`/`inactive_add` for granular plugin management.
- **Gatekeeper pattern:** `frl_environment_is_loaded()` centralizes all "should EM run?" logic, making it auditable.

### What Needs Attention
1. **`frl_cache_clear('all')` on every change** — the single biggest performance concern. Targeted cache clearing would prevent unnecessary full cache flushes.
2. **`frl_is_valid_environment_host()` vs config builder mismatch** — causes legitimate `www.`-prefixed requests to be rejected by the gatekeeper.
3. **`blog_public` monitoring only for staging** — production environments are blind to manual `blog_public` changes.
4. **`check_urls()` overhead on every admin request** — adds 6+ WordPress function calls even when URLs haven't changed.

### Overall Score: **B+** (Good, with clear improvement paths)

The environment manager is a well-architected solution for a complex multi-brand WordPress deployment. Its core design is sound, caching is thoughtful, and defensive patterns are consistently applied. The issues identified are primarily optimization opportunities (targeted cache clearing, reduced per-request overhead) and edge-case bugs (www-prefix matching, production blog_public monitoring) rather than fundamental architectural flaws.
