# Environment Manager — Developer Reference

**Source:** [`core/environment/`](../core/environment/) (9 files)
**Configuration:** [`config/environment/`](../config/environment/) (constants + snippets)

---

## 1. Overview

The Environment Manager (EM) provides **domain-based auto-configuration** for the Fralenuvole plugin. It maps incoming HTTP hosts to environment profiles and automatically applies the correct set of WordPress options, plugin options, plugin activation states, and module states — all defined via PHP constants.

**Why it exists:** The plugin codebase is deployed across 6+ distinct websites (`pbservices.ge`, `pbproperty.ge`, `pbnova.com`, staging mirrors, and an RU subdomain) each with different plugin requirements, WP options, header/footer HTML, debug profiles, and enabled modules. Manual per-site configuration is error-prone and doesn't scale.

**Key capabilities:**
- Domain-based environment detection via [`FRL_ENV_MAP`](../config/environment/config-environment.php:8)
- Four-layer configuration merge (Base → Type → Template → Instance)
- Automatic `siteurl`/`home` correction when the wrong domain is used
- Per-environment plugin activation/deactivation
- Per-environment module enable/disable
- File-based HTML snippets per brand prefix
- Automatic re-detection when `blog_public` is manually changed on staging
- "Ignore" tracking for options/plugins manually overridden by an admin

---

## 2. High-Level Architecture

```
                      ┌─────────────────────────────────┐
                      │      Frl_Environment_Manager     │
                      │         (Facade Class)           │
                      │                                  │
                      │  init()                          │
                      │  enforce_environment_settings()  │
                      │  get_config()                    │
                      │  reset_customizations()          │
                      │  add_environment_switcher()      │
                      └──────┬──────────────────────┬────┘
                             │                      │
              ┌──────────────┼──────────┬───────────┼──────────────┐
              ▼              ▼          ▼           ▼              ▼
   ┌────────────────┐ ┌──────────┐ ┌────────┐ ┌──────────┐ ┌──────────────┐
   │Environment_     │ │Environment│ │Environ-│ │Environ-  │ │Environ-      │
   │Config           │ │_State    │ │ment_   │ │ment_     │ │ment_Files    │
   │                 │ │          │ │Monitor │ │Applier   │ │              │
   │·get_domain_     │ │·get_     │ │        │ │          │ │·load_        │
   │ config()        │ │ current_ │ │·check_ │ │·apply_   │ │ environment_ │
   │·build_domain_   │ │ state()  │ │ urls() │ │ wordpress_│ │ file()       │
   │ config()        │ │·environ- │ │·setup_ │ │ options()│ │·get_file_    │
   │·merge_environment││ ment_host │ │ plugin_ │ │·apply_   │ │ option_keys()│
   │ configs()       │ │ _changed()│ │options_ │ │ plugin_  │ │              │
   └────────────────┘ │·check_   │ │tracking│ │ options()│ └──────────────┘
                       │ environ- │ │·track_ │ │·apply_   │
                       │ ment_    │ │ plugin_ │ │ modules_ │
                       │ state()  │ │options()│ │ options()│
                       └──────────┘ │·track_  │ │·clear_   │
                                    │ plugins_│ │ website_ │
                                    │activation││transients│
                                    │ _status()│ │_if_needed│
                                    └─────────┘ └──────────┘

┌──────────────────────┐
│Environment_Plugin_   │
│Manager               │
│                      │
│·apply_plugins_       │
│ activation_status()  │
│·process_plugins_     │
│ activation_status()  │
└──────────────────────┘

Trait:
┌──────────────────────────────┐
│ Frl_Environment_Host_        │
│ Normalizer                   │
│                              │
│·normalize_host_value()       │
│·extract_host_from_url()      │
└──────────────────────────────┘

Utility:
┌──────────────────────────────┐
│ Frl_Environment_Utils        │
│                              │
│·log_environment_change()     │
└──────────────────────────────┘
```

### 2.1 File Map

| File | Class/Trait | Responsibility |
|------|-------------|----------------|
| [`environment-manager.php`](../core/environment/environment-manager.php) | — | Bootstrap loader; requires all sub-files |
| [`class-environment-manager.php`](../core/environment/class-environment-manager.php) | `Frl_Environment_Manager` | Public facade; init, enforce, config access, admin bar |
| [`class-environment-config.php`](../core/environment/class-environment-config.php) | `Frl_Environment_Config` | Domain config builder; FRL_ENV_MAP lookup; merge engine |
| [`class-environment-state.php`](../core/environment/class-environment-state.php) | `Frl_Environment_State` | State persistence; host change detection |
| [`class-environment-monitor.php`](../core/environment/class-environment-monitor.php) | `Frl_Environment_Monitor` | URL correction; option/plugin change tracking hooks |
| [`class-environment-applier.php`](../core/environment/class-environment-applier.php) | `Frl_Environment_Applier` | Applies WP options, plugin options, module states |
| [`class-environment-plugin-manager.php`](../core/environment/class-environment-plugin-manager.php) | `Frl_Environment_Plugin_Manager` | Plugin activation/deactivation |
| [`class-environment-files.php`](../core/environment/class-environment-files.php) | `Frl_Environment_Files` | File-based option loading with PHP syntax validation |
| [`class-environment-utils.php`](../core/environment/class-environment-utils.php) | `Frl_Environment_Host_Normalizer` (trait), `Frl_Environment_Utils` | Host normalization helpers; structured logging |

---

## 3. Configuration System

### 3.1 Environment Map

Defined in [`config/environment/config-environment.php`](../config/environment/config-environment.php):

```php
const FRL_ENV_MAP = [
    'pbservices.ge'             => 'FRL_ENV_PBS_PRODUCTION',
    'ru.pbservices.ge'          => 'FRL_ENV_PBS_RU_SUBDOMAIN',
    'pbproperty.ge'             => 'FRL_ENV_PBP_PRODUCTION',
    'pbnova.com'                => 'FRL_ENV_PBNOVA_PRODUCTION',
    'fralenuvole.art'           => 'FRL_ENV_FRALENUVOLE_PRODUCTION',
    'master.fralenuvole.art'    => 'FRL_ENV_MASTER_TEMPLATE',
    'staging.pbservices.ge'     => 'FRL_ENV_PBS_STAGING',
    'staging.pbproperty.ge'     => 'FRL_ENV_PBP_STAGING',
    'staging.pbnova.com'        => 'FRL_ENV_PBNOVA_STAGING',
];
```

**Matching logic:**
1. Current `HTTP_HOST` is lowercased and `www.`-prefix is stripped
2. Each map key is also `www.`-stripped for comparison
3. First exact match wins
4. If `HTTP_HOST` is unavailable (CLI, edge cases), falls back to `parse_url(site_url(), PHP_URL_HOST)`

### 3.2 Configuration Merge Stack

The final environment configuration is built by merging **four layers** in order (bottom wins):

```
Layer 1: FRL_ENV_DEFAULT          ← Universal baseline applied to every site
Layer 2: Type partial             ← FRL_ENV_DEFAULT_PRODUCTION or FRL_ENV_DEFAULT_STAGING
Layer 3: Brand template (extends)  ← Optional one-level template (e.g., FRL_ENV_PBS_TEMPLATE)
Layer 4: Instance constant         ← The specific constant from FRL_ENV_MAP
```

**Merge rules:**
- Associative arrays use `array_replace_recursive()` — deeper keys from higher layers override lower ones.
- The `plugins` key uses custom logic (see §3.3).
- The `extends` key is resolved by looking up the named constant, then stripped from the instance config. Templates cannot themselves extend — any nested `extends` key is silently dropped.
- After merge, the `type` key is set based on explicit `type` hint or constant name suffix (`_STAGING`).

### 3.3 Plugin Merge Logic

The `plugins` merge is not a simple recursive merge — it uses a dedicated algorithm with two replace modes:

| Key | Behavior | Example |
|-----|----------|---------|
| `active` | Full replacement: replaces the entire active list at this layer | Base has `[plugin-a, plugin-b]`, instance sets `active: [plugin-c]` → result is `[plugin-c]` |
| `inactive` | Full replacement: replaces the entire inactive list | Same as above for deactivation list |
| `active_add` | Additive: merged with current active list | Base has `[plugin-a]`, type sets `active_add: [plugin-b]` → result is `[plugin-a, plugin-b]` |
| `inactive_add` | Additive: merged with current inactive list | Same as above for deactivation additions |

**Cross-layer consistency:** After all layers are resolved, active plugins are removed from the inactive list and vice versa, preventing duplicates.

**Merge order (layer precedence):**
1. Base plugin config → initial `active`/`inactive` lists
2. Type layer: `active`/`inactive` acts as full replacement; `active_add`/`inactive_add` adds to current lists
3. Extends layer: same semantics as type layer
4. Instance layer: `active`/`inactive` acts as full replacement; `active_add`/`inactive_add` adds to current lists

### 3.4 Default Configuration Sections

| Section | Key | Description |
|---------|-----|-------------|
| `prefix` | string | Brand prefix used for option naming and file lookups |
| `type` | `'production'` or `'staging'` | Environment type |
| `webhook_config` | string or `false` | Webhook configuration key for WS Form |
| `plugins` | `{active: [], inactive: []}` | Plugin management lists |
| `modules` | `{module_name: bool}` | Module enable/disable map |
| `wp_options` | `{option: value}` | WordPress core options (e.g., `blog_public`) |
| `plugin_options` | `{option: value or 'file'}` | Plugin-specific options |

---

## 4. Init Sequence

### 4.1 Timeline

```
plugins_loaded/5
  │
  ├── frl_load_core_components()
  │     │
  │     ├── require environment-manager.php         ← All 7 classes loaded
  │     │
  │     └── frl_environment_init()
  │            │
  │            └── Frl_Environment_Manager::init()
  │                  │
  │                  ├── Guard: frl_is_already_running(__METHOD__)
  │                  │
  │                  ├── [If user has access]
  │                  │    ├── Check & clear force-reload cookie
  │                  │    ├── Frl_Environment_Monitor::check_urls()
  │                  │    │     └── Compares HTTP_HOST vs siteurl/home
  │                  │    │         └── If mismatch → update + cookie + enforce + redirect
  │                  │    └── Frl_Environment_Monitor::setup_plugin_options_tracking()
  │                  │          └── Registers update_option_* hooks for managed options
  │                  │
  │                  └── Register hooks:
  │                       ├── admin_bar_menu (add_environment_switcher)
  │                       ├── activated_plugin (track_plugins_activation_status)
  │                       └── deactivated_plugin (track_plugins_activation_status)
  │
  └── add_action('init', 'frl_environment_enforce_settings', 10)

init/10
  │
  └── frl_environment_enforce_settings()
        │
        └── Frl_Environment_Manager::enforce_environment_settings()
              │
              ├── Guard: frl_has_access()             ← Admins or migrate mode only
              ├── Guard: Not in admin action handler   ← Skip $_GET[frl_action] requests
              ├── Guard: Not redirecting from reset    ← Skip HTTP_REFERER with reset_environment
              ├── Guard: Not already running           ← Re-entrancy prevention
              │
              ├── Get domain config (cached)           ← Frl_Environment_Config::get_domain_config()
              ├── Check throttle                        ← 60s for admins, 300s for others
              ├── Check state change                    ← Frl_Environment_State::check_environment_state()
              │
              └── [If state changed OR force]
                    ├── apply_wordpress_options()       ← siteurl, home, blog_public, etc.
                    ├── apply_plugin_options()          ← Plugin-specific options + file-based HTML
                    ├── apply_plugins_activation_status() ← Activate/deactivate plugins
                    ├── apply_modules_options()         ← Enable/disable modules
                    ├── frl_cache_clear('all')          ← Full cache purge
                    ├── Persist state                    ← DB option + object cache
                    ├── [If state change without force]
                    │    ├── Clear website transients    ← Once per destination host (1yr TTL)
                    │    ├── Log environment change      ← Structured log entry
                    │    └── Redirect to plugin admin    ← With nocache headers
                    └── Return $results
```

### 4.2 Gatekeeper: `frl_environment_is_loaded()`

Defined in [`includes/helpers/functions-class-helpers.php:247`](../includes/helpers/functions-class-helpers.php:247):

```php
function frl_environment_is_loaded() {
    // 1. frl_is_valid_page_request() → rejects CLI, REST, CRON,
    //    non-mapped hosts, heartbeat, log-manager, AJAX
    // 2. Checks disable_plugin, disable_environment options
    // 3. Checks FRL_MODE !== 'core'
    // 4. Verifies Frl_Environment_Manager class exists
}
```

This function is the single gatekeeper for all EM features. If it returns `false`, EM does not initialize and does not enforce.

---

## 5. Core Classes — Detailed Behavior

### 5.1 `Frl_Environment_Config` — Configuration Builder

**`get_domain_config()`:**
- Cache key: `'domain_config_' . md5(strtolower(HTTP_HOST))` in the `environment` cache group
- TTL: 1 hour (defined in `FRL_CACHE_PERSISTENT_GROUPS`)
- On cache miss: calls `build_domain_config()`

**`build_domain_config()`:**
1. Get `HTTP_HOST` (fallback to `site_url()` host)
2. Strip `www.` prefix
3. Match against `FRL_ENV_MAP` keys
4. Look up the matched constant
5. Determine `type` (staging/production) from explicit `type` key or constant name suffix
6. Resolve `extends` reference (one level only)
7. Call `merge_environment_configs()` with all 4 layers
8. Add metadata keys: `current_host`, `env_host`, `current_environment`

**`merge_environment_configs()`:**
- Generic merge: `array_replace_recursive($base, $type, $extends, $instance)`
- Plugin merge: custom algorithm (see §3.3)
- Returns fully resolved config array

### 5.2 `Frl_Environment_State` — State Persistence

**State data stored:**
```php
[
    'host'     => 'example.com',       // $_SERVER['HTTP_HOST']
    'siteurl'  => 'https://example.com', // get_site_url()
    'home'     => 'https://example.com', // get_home_url()
    'hash'     => 'abc123...',          // md5 of host + siteurl
    'last_updated' => '2026-04-29 ...'  // Only when include_timestamp=true
]
```

**State storage:**
- Persistent: DB option with key `FRL_PREFIX . '_environment_state'`
- Cache: object cache in the `environment` group, key `'state'`

**Change detection (`check_environment_state()`):**
1. Retrieve stored state (cache → DB fallback)
2. Compare current `HTTP_HOST`, `get_site_url()`, `get_home_url()` against stored values
3. Hosts are normalized: lowercase, trailing dot removed, port stripped
4. **Staging re-check:** If the current environment is staging, also checks `blog_public` — if != 0, triggers re-enforcement
5. If changed: persists new state to DB + cache

**Why ignore scheme/port/path:** The detection is deliberately host-only. Scheme (http vs https), port, and URL path differences should not trigger environment re-enforcement. Only domain-level changes (production ↔ staging, or domain migration) trigger a state change.

### 5.3 `Frl_Environment_Monitor` — Active Monitoring

**`check_urls()` (admin-only):**
1. Get current `HTTP_HOST`, `site_url()`, `home_url()`
2. Normalize all three hosts
3. If `HTTP_HOST` differs from either `site_url` or `home_url` host:
   a. Construct new `siteurl`/`home` using the config's `current_host` or `env_host`
   b. Validate the new URLs contain `://`
   c. Call `update_option('siteurl', ...)` and/or `update_option('home', ...)`
   d. Sync `blog_public` from config
   e. Reset all customization ignore lists
   f. Refresh auth cookie for the user
   g. Set force-reload cookie
   h. Run `enforce_environment_settings(true)` to re-apply all config

**`setup_plugin_options_tracking()`:**
- Iterates all keys in the resolved config's `plugin_options` array
- Registers `update_option_{$prefixed_name}` hooks for each
- Each hook calls `track_plugin_options()` when the option is updated

**`track_plugin_options()`:**
- Compares old vs new value (no-op if identical)
- Checks if option is managed by the current environment config
- If manually changed by an admin: adds option name to the `environment_ignore_options` list
- Subsequent enforcement runs **skip** ignored options

**`track_plugins_activation_status()`:**
- Fires on `activated_plugin`/`deactivated_plugin` hooks
- Checks if the plugin is in the managed list (active ∪ inactive from config)
- If manually toggled: adds plugin path to `environment_ignore_plugins` list
- Uses request-level static cache to avoid redundant option lookups

### 5.4 `Frl_Environment_Applier` — Config Application

**`apply_wordpress_options()`:**
1. Computes expected `siteurl` and `home` from `config['current_host']`
2. Updates if current differs from expected (tracks in `$results['wp_options']['updated']`)
3. Iterates `config['wp_options']` (e.g., `blog_public`) — updates each if value differs

**`apply_plugin_options()`:**
1. Reads `environment_ignore_options` list
2. For each key in `config['plugin_options']`:
   - Skipped if in ignore list
   - If value is `'file'`: loads content from env-snippet file (or sets empty if file missing)
   - If value is boolean/truthy: normalizes to `'1'` or `'0'`
   - Otherwise: uses value as-is
   - Calls `frl_update_option()` with deferred cache clearing
3. If any changes: calls `frl_cache_clear('options')` once after all writes

**`apply_modules_options()`:**
1. Iterates `config['modules']`
2. For each module: converts target status to `'1'`/`'0'`, compares with current
3. Updates `module_{name}` option if different
4. If any changes: calls `frl_cache_clear('options')` once after all writes

**Cache clearing behavior:** Both methods use `$clear_cache_on_update = !$force_mode` to decide whether to purge the `options` cache group after writes. In non-force mode (state-change enforcement), the targeted clear runs and is followed by the parent `frl_cache_clear('all')` — making the targeted clear architecturally redundant but harmless. In force mode, the targeted clear is skipped entirely, relying on the parent `frl_cache_clear('all')` for a single comprehensive purge.

**`clear_website_transients_if_needed()`:**
- Guards: `FRL_ENV_CLEAR_WEBSITE_TRANSIENTS` must be true (always), user must have access, host must be non-empty
- Uses `frl_cache_remember()` with `YEAR_IN_SECONDS` TTL to ensure transients are cleared only once per destination host
- Returns count of deleted transients and status

### 5.5 `Frl_Environment_Plugin_Manager` — Plugin Lifecycle

**`apply_plugins_activation_status()`:**
1. Reads `environment_ignore_plugins` list
2. Processes `config['plugins']['active']` first (activate plugins not currently active)
3. Then processes `config['plugins']['inactive']` (deactivate plugins currently active)
4. Skips ignored plugins entirely

**`process_plugins_activation_status()`:**
- Temporarily **removes** `activated_plugin`/`deactivated_plugin` tracking hooks to prevent self-triggering
- Batch processes plugins that need to change state
- Validates results: checks `is_plugin_active()` after deactivation, checks `is_wp_error()` after activation
- Re-adds tracking hooks when done
- Uses `deactivate_plugins()` with `$silent=true` and `activate_plugin()` with `$silent=false`

### 5.6 `Frl_Environment_Files` — File-Based Options

**`get_file_options_keys()`:**
- Scans `config/environment/env-snippets/` for files matching `{prefix}_{option_name}.php`
- Returns list of option keys that have physical files
- Results cached in the `environment` cache group

**`load_environment_file()`:**
- Loads raw content from `{prefix}_{option_name}.php`
- If content contains `<?php`, validates PHP syntax as an advisory-only check (the result never changes what's returned):
  - Guarded by `function_exists('exec')` — skips gracefully with a log entry on hosts where `exec()` is disabled (common hardening on managed/shared hosting)
  - When available: writes content to a temp file, runs `php -l` (lint) via `exec()`, logs syntax errors
  - Wrapped in `catch (\Throwable $e)` so any unexpected failure (including `exec()` being disabled at runtime) is caught rather than propagating as a fatal error
- Results cached in the `environment` cache group

### 5.7 `Frl_Environment_Host_Normalizer` (Trait)

Used by `Frl_Environment_State` and `Frl_Environment_Monitor`:

- `normalize_host_value($host)`: lowercase, trim, strip trailing dot, strip `:port` suffix
- `extract_host_from_url($url)`: parse URL, extract host, normalize it. Handles URLs without scheme by prepending `https://`.

### 5.8 `Frl_Environment_Utils` — Logging

Provides `log_environment_change()` which logs a structured message with:
- Environment type, constant name, prefix
- Origin and destination hosts, site URLs, home URLs
- Transients deletion count and status

---

## 6. Throttle System

The throttle prevents redundant enforcement runs on consecutive requests.

| User Type | Throttle Window | Bypass Conditions |
|-----------|-----------------|-------------------|
| Admin (`frl_has_access()`) | 60 seconds | `$force=true`, `FRL_MODE=migrate`, State changed |
| Non-admin | 300 seconds | Same as above |

**Mechanism:**
- Stores a `last_check` timestamp in the `environment` cache group under key `'last_check'`
- On each `enforce_environment_settings()` call:
  1. State change is detected **before** throttle check — ensures migrations are never throttled
  2. If state changed → full enforcement (throttle bypassed)
  3. If state unchanged → check throttle window
  4. If within throttle window → return `null` (skip)
  5. If outside throttle window → run enforcement (no changes expected, but blog_public re-check may trigger)

**Important:** State change detection always runs, even during throttled periods. This ensures that a domain migration is never delayed by the throttle.

---

## 7. Ignored Customizations

The EM tracks manual changes to managed options and plugins, storing them in two DB options:

| Option Key | Content | Tracked By |
|------------|---------|------------|
| `{prefix}_environment_ignore_plugins` | Array of plugin paths | `track_plugins_activation_status()` |
| `{prefix}_environment_ignore_options` | Array of option keys | `track_plugin_options()` |

**When a managed option/plugin is manually changed:**
1. The tracking hook fires
2. The item is added to the ignore list
3. On subsequent enforcement runs, the item is skipped
4. The admin UI shows "Ignored" status for these items

**Reset:**
- Admin action "Reset Ignored Plugins" calls `reset_customizations()`
- This clears both ignore lists to empty arrays
- Next enforcement run will re-apply environment defaults

---

## 8. Admin Bar Switcher

Added at `admin_bar_menu` priority 9999.

**Current environment display:**
- Shows `prod` or `staging` in the admin bar
- Color-coded: production (green), staging (yellow)
- Shows search engine indexing status (allowed/blocked)

**Counterpart link:**
- Finds the staging counterpart of the current production domain (or vice versa)
- Links directly to the counterpart domain
- Opens in the same window

**Sibling domain submenus:**
- Lists all FRL_ENV_MAP domains except:
  - Current host
  - Direct counterpart (already accessible via parent link)
  - Staging domains for non-admins
  - Domain containing the plugin author's name (hidden for non-superadmins)
- Opens in new tabs (`target="_blank"`)
- Results cached per user per domain via `frl_cache_remember('admin')`

---

## 9. Cache Interaction

### 9.1 Cache Points

| Data | Cache Group | TTL | Type |
|------|-------------|-----|------|
| Domain config | `environment` | 1 hour | Object cache |
| Environment state | `environment` + DB option | 1 hour (LRU) | Two-tier |
| Throttle last check | `environment` | 1 hour (LRU) | Object cache |
| Ignored plugins/options | `environment` + DB option | 1 hour (LRU) | Two-tier |
| File option keys | `environment` | 1 hour | Object cache |
| File content | `environment` | 1 hour | Object cache |
| Transients-cleared flag | `environment` | 1 year | Object cache |
| Admin bar submenus | `admin` | Default | Object cache |
| Plugin options (post-apply) | `options` | HOUR_IN_SECONDS | Object cache |

### 9.2 Cache Clearing on Enforcement

All cache clearing during environment enforcement is executed through the **cache orchestrator** ([`FRL_CACHE_OPERATIONS`](../../config/config-cache-operations.php)) for centralized visibility. The change-type classifier in [`enforce_environment_settings()`](../../core/environment/class-environment-manager.php:236) inspects `$results` after all apply methods run, selects the appropriate `env_*` operation, and dispatches it via `Frl_Cache_Operations::run()` (guarded — skipped when `$env_op` is empty).

**Three tiers of operations in the orchestrator:**

| Tier | Prefix | Purpose | Operations |
|------|--------|---------|------------|
| Helper | `clear_*` | Delegated from `frl_cache_clear()` | `clear_hard`, `clear_all`, `clear_light`, `clear_options`, `clear_rewriter` |
| Action | `action_*` | Admin action handlers | `action_hard`, `action_flush_rewrite_rules`, `action_clear_plugin_transients`, `action_clear_website_transients`, `action_clear_scripts_tags` |
| Environment | `env_*` | EM enforcement decisions | `env_enforce_full`, `env_enforce_url_change`, `env_enforce_options` |

**Cache clear behavior by mode (via `env_*` operations):**

| Mode | Operation | Steps |
|------|-----------|-------|
| State change (plugin/module) | `env_enforce_full` | `frl_cache_clear('all')` + `frl_flush_rewrite_rules()` |
| State change (URL change) | `env_enforce_url_change` | `frl_cache_clear('all')` |
| State change (options only) | `env_enforce_options` | `frl_cache_clear('options')` → delegates to `clear_options` operation in orchestrator |
| Force mode | `env_enforce_full` | `frl_cache_clear('all')` + `frl_flush_rewrite_rules()` |

**Targeted clears inside apply methods:**

`apply_plugin_options()` and `apply_modules_options()` do **not** call `frl_cache_clear('options')` directly. All cache clearing is centralized in the change-type classifier described above.

### 9.3 URL Correction Caching

When `check_urls()` updates `siteurl`/`home`, it:
1. Sets a force-reload cookie (1-minute TTL, httponly, secure)
2. Calls `enforce_environment_settings(true)` which clears all caches
3. Redirects to the plugin admin page with `nocache_headers()`

---

## 10. Disable Mechanisms

| Method | Effect | Mechanism |
|--------|--------|-----------|
| `disable_plugin` option | Disables ALL plugin features | `frl_environment_is_loaded()` returns `false` |
| `disable_environment` option | Disables EM only | Same gatekeeper check |
| `FRL_MODE=core` URL param | Mimics disable_plugin | Same gatekeeper check |
| `FRL_MODE=disable` URL param | Stops plugin loading entirely | Early return in `bootstrap.php` |
| `FRL_MODE=migrate` URL param | Forces enforcement for all users | `frl_has_access()` returns `true` regardless of capabilities |

---

## 11. Environment Snippet Files

Located in [`config/environment/env-snippets/`](../config/environment/env-snippets/):

Files are named `{prefix}_{option_name}.php` where:
- `{prefix}` is the brand prefix (e.g., `pbs`, `pbp`, `pbnova`, `frl`)
- `{option_name}` is the plugin option key (e.g., `footer_html`, `header_html`)

**Example:** `pbs_footer_html.php` contains the PBS footer HTML.

**Loading behavior:**
1. Config sets the option value to `'file'`
2. EM resolves `get_file_options_keys()` to check which files exist
3. On enforcement: if file exists → loads content → stores as option value
4. If file missing → stores empty string
5. If option is misconfigured as `'file'` but not in the known file keys → stores empty string + logs error
6. PHP syntax is validated via `php -l` but the file is NOT executed — content is stored as-is for later rendering

---

## 12. Developer Extension Points

### 12.1 Adding a New Domain

1. Define a **brand template** in `config/environment/config-environment.php` if the brand is new
2. Define an **instance constant** with `'extends' => 'TEMPLATE_NAME'` and only the delta
3. Add the domain → constant mapping to `FRL_ENV_MAP`
4. Optionally: create snippet files in `env-snippets/` as `{prefix}_{option}.php`

### 12.2 Hook Reference

| Hook | Action | Location |
|------|--------|----------|
| `frl_before_cache_operation_clear_all` | Before `clear_all` cache operation | `Frl_Cache_Operations::run()` |
| `frl_after_cache_operation_clear_all` | After `clear_all` cache operation | Same |
| `update_option_{$prefixed_option}` | When a managed plugin option is updated | WordPress core |
| `activated_plugin` | When any plugin is activated | WordPress core |
| `deactivated_plugin` | When any plugin is deactivated | WordPress core |
| `updated_option` | When any WordPress option is updated (WP 4.7+); fires with `($option_name, $old_value, $new_value)` | [`core/environment/class-environment-monitor.php:37`](../../core/environment/class-environment-monitor.php:37) |

### 12.3 Gateway Functions

Defined in [`includes/helpers/functions-class-helpers.php`](../includes/helpers/functions-class-helpers.php):

| Function | Purpose |
|----------|---------|
| `frl_environment_is_loaded()` | Check if EM should run for this request |
| `frl_environment_init()` | Initialize EM (call from `plugins_loaded`) |
| `frl_environment_enforce_settings($force)` | Run enforcement (call from `init/10`) |
| `frl_environment_get_config()` | Get current resolved config (bypasses `is_loaded` check) |
| `frl_environment_reset_ignored()` | Clear ignore lists |

---

## 13. Frequently Asked Questions

**Q: What happens on CLI/cron/REST requests?**
A: The EM is completely disabled. `frl_is_valid_page_request()` returns `false` for these contexts, so `frl_environment_is_loaded()` returns `false`.

**Q: What if HTTP_HOST is not in FRL_ENV_MAP?**
A: `build_domain_config()` returns `null`. `frl_is_valid_environment_host()` also returns `false`, so `frl_is_valid_page_request()` returns `false`, and the EM never initializes. The rest of the plugin runs normally without environment-specific settings.

**Q: Does the EM run on every page load?**
A: It initializes on every valid page request, but the throttle ensures `enforce_environment_settings()` only runs at most once per 60s (admins) or 300s (non-admins). Most page loads hit the throttle guard and exit early.

**Q: What happens when both staging and production are configured for the same domain base?**
A: The `FRL_ENV_MAP` keys must be unique. `pbservices.ge` and `staging.pbservices.ge` are separate entries. The matching logic uses the full hostname.

**Q: How does the `www.` prefix work?**
A: `www.` is stripped for **matching** only (so `www.pbservices.ge` matches `pbservices.ge` in the map). However, `www.` is **preserved** in the `current_host` metadata for URL construction. The matcher is case-insensitive.

   Note: `www.` stripping is handled by `build_domain_config()` at [`class-environment-config.php:49`](../../core/environment/class-environment-config.php:49) — the actual config builder. The gatekeeper function `frl_is_valid_environment_host()` at [`functions-access-control.php:414`](../../includes/helpers/functions-access-control.php:414) does **not** strip `www.` before checking `array_key_exists()` on `$_SERVER['HTTP_HOST']`, but this has no practical impact because `build_domain_config()` performs the authoritative match. If you need to check host validity against `FRL_ENV_MAP`, prefer using `Frl_Environment_Config::get_domain_config()` rather than `frl_is_valid_environment_host()`.

**Q: Can templates extend templates?**
A: No. The `extends` mechanism is strictly one level (instance → template). Any `extends` key inside a template is silently stripped via `array_diff_key()`. The README documents this constraint.

**Q: What about multisite?**
A: The EM uses `get_option()`/`update_option()` which are site-scoped. For multisite network-level options, only the plugin exclusion feature (via MU plugin) uses `get_site_option()`/`update_site_option()`. The EM itself is site-scoped.

---

*For internal cache system details, see [`docs/CACHE.md`](CACHE.md). For the rewriter subsystem, see [`docs/REWRITER.md`](REWRITER.md).*
