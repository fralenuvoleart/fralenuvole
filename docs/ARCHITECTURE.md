# Architecture

Fralenuvole is a WordPress administration and performance framework combining multi-layer caching, domain-based multi-environment configuration, multilingual URL handling, frontend performance optimizations, and backend tooling into a single modular codebase. Version 5.8.4, PHP 8.3+, zero runtime dependencies.

## Bootstrap Sequence

Both the main plugin and the MU plugin share the same bootstrap:

```
includes/bootstrap.php
  ├─ ?frlmode= → defines FRL_MODE constant
  ├─ FRL_MODE=disable → return (stop loading)
  ├─ config/config.php → loads all config constants (11 files in order)
  ├─ includes/helpers/functions.php → loads all helper files (9 files in order)
  ├─ core/cache/class-cache-manager.php → Frl_Cache_Manager::init()
  ├─ core/cache/class-cache-operations.php
  ├─ core/error-handler.php → frl_errors_init()
  └─ WP-CLI → core/rewriter/cli/class-rewriter-cli.php
```

Then `fralenuvole.php`:
```
fralenuvole.php
  ├─ FRL_MODE=disable → return (load-bearing: must precede any FRL_DIR_PATH usage)
  ├─ includes/plugin-lifecycle.php → activation/deactivation/uninstall hooks, auto-backup
  ├─ Security headers: CSP, X-Content-Type-Options, X-Frame-Options
  └─ plugins_loaded/5 → frl_plugins_loaded()
```

## Directory Map

| Directory | Role |
|---|---|
| `core/` | Framework subsystems: cache, environment manager, rewriter, translator, themekit, error handler |
| `admin/` | WordPress admin UI: settings page, dashboard, widgets, metaboxes, action handlers |
| `includes/` | Bootstrap, plugin lifecycle, shared helpers, main feature modules (website, media, navigation, logged-user), MU-plugin helpers |
| `public/` | Frontend-only: hooks (`public.php`), shortcodes (`shortcodes.php`), JSON-LD schema subsystem (`schema/`), channel tracking, performance |
| `modules/` | Opt-in, per-environment feature modules (brand-specific CPTs, WS Form, third-party integrations, call-to-actions) |
| `config/` | All PHP constants loaded by `config/config.php` in order: MU, base, options, fields, cache, rewriter, environment, translator, schema, themekit, channel-tracking |
| `assets/` | Static assets: CSS (`admin.css`, `admin-ui.css`, `public-login.css`, etc.), JS (`admin.js`, `public.js`, `scroll-listener.js`, etc.), lib (`parsedown/`, `prism/`), MU plugin (`mu/frl-mu-plugin.php`) |
| `docs/` | Reference documentation — one file per subsystem |
| `memory-bank/` | Architectural invariants (`systemPatterns.md`), product context, active context, progress log |

## Load Order

```
wp-config.php (LOGGED_IN_KEY/LOGGED_IN_SALT available)
  │
  ▼
mu-plugins/frl-mu-plugin.php   (muplugins_loaded)
  ├─ require includes/bootstrap.php     ← shared bootstrap
  ├─ require includes/mu/mu.php         ← MU-specific helpers
  ├─ frl_maybe_throttle_user_agent()    ← bot rate-limiting (429)
  └─ frl_filter_plugin_exclusions()     ← pre_option_active_plugins filter
  │
  ▼
fralenuvole.php   (plugins_loaded / 5)
  ├─ require includes/bootstrap.php     ← shared bootstrap (idempotent via require_once)
  ├─ FRL_MODE=disable → return         ← load-bearing guard position
  ├─ require includes/plugin-lifecycle.php
  ├─ register_activation_hook / register_deactivation_hook / register_uninstall_hook
  ├─ Security headers: CSP, X-Content-Type-Options, X-Frame-Options
  └─ frl_plugins_loaded()
       ├─ frl_load_core_components()
       │    ├─ Cache Manager (loaded in bootstrap)
       │    ├─ cache-cleanup.php
       │    ├─ Environment Manager  (unless disable_environment)
       │    ├─ Translator            (only if multilingual plugin active)
       │    ├─ Rewriter              (unless disable_rewriter)
       │    ├─ Webhooks              (always)
       │    ├─ ThemeKit              (always)
       │    ├─ includes/main.php → website.php, media.php, navigation.php, logged-user.php
       │    └─ public/shortcodes.php
       ├─ frl_load_admin_components()     (admin context only)
       │    └─ admin/admin.php
       ├─ frl_load_public_components()    (valid frontend only, unless disabled/core mode)
       │    ├─ public/public.php
       │    └─ public/schema/schema.php
       └─ frl_modules_init()              — loads enabled modules from config
  │
  ▼
init / 10 — frl_environment_enforce_settings()   ← MUST run before anything reads options
init / 10 — frl_shortcodes_init()
init / 12 — frl_process_plugin_actions()         ← admin bar cache links
init / 12 — frl_log_capture()                    ← debug log diagnostics
init / 15 — Rewriter feature registration
init / 20 — frl_themekit_init()
init / 100 — frl_themekit_remove_provider_block_patterns()
init / 200 — frl_flush_rewrite_rules (deferred)
```

See `docs/PLUGIN-HOOKS.md` for the full priority table and the reasoning behind each reserved slot.

## Core Subsystems

| Subsystem | Reference | Summary |
|---|---|---|
| Cache Manager | `docs/CACHE.md` | Unified multi-backend cache with LRU runtime tier, dependency cascading, language-aware keys, composite operation orchestrator |
| Environment Manager | `docs/ENVIRONMENT.md` | Domain→environment mapping that auto-applies WP options, plugin options, and plugin/module activation state |
| Rewriter | `docs/REWRITER.md` | Feature-based URL rewriting (multilingual CPT slugs, taxonomy/CPT base removal) with priority-ordered, self-registering features |
| Translator | `docs/TRANSLATOR.md` | Adapter-pattern translation service (Polylang implemented, WPML-ready). Block tokens, field translation, pattern-based caching |
| ThemeKit | `docs/THEMEKIT.md` | Theme-independent body classes, base styles, block pattern/provider-style removal, font optimization |
| Plugin Exclusion | `docs/THIRDPARTY-EXCLUSIONS.md` | MU-plugin-based conditional plugin loading (frontend/backend-screen/capability rules) with bot throttling |
| Admin UI | `docs/PLUGIN-ADMINUI.md` | Tabbed settings page, dashboard widgets, log manager, tag validator, import/export, docs browser |
| Access Control | `docs/PLUGIN-PERMISSIONS.md` | Capability model (`frl_has_access()`), superadmin/plugin-admin tiers, nonce system, `FRL_MODE` behavior |
| Debug Modes | `docs/PLUGIN-DEBUG-MODES.md` | `FRL_MODE` URL parameter / constant for disable/core/nocache/migrate modes |
| Webhooks | `docs/WEBHOOKS.md` | Generic webhook dispatch (sync + async via WP-Cron with retry backoff) |
| Kinsta MU-Plugin | `docs/KINSTA-MUPLUGIN.md` | Third-party reference for Kinsta's caching behavior |

### Additional Components (no dedicated doc)

| Component | Location | Purpose |
|---|---|---|
| Error Handler | `core/error-handler.php` | Custom error handling initialized in bootstrap via `frl_errors_init()` |
| Schema / JSON-LD | `public/schema/` | Orchestrator-based JSON-LD generation (Organization, WebSite, WebPage, BreadcrumbList, BlogPosting, Service, HowTo, etc.) |
| Shortcodes | `public/shortcodes.php` | 15+ shortcodes (`[frl]`, `[frl_meta]`, `[frl_permalink]`, `[frl_breadcrumbs]`, etc.) |
| Website Features | `includes/main/website.php` | Disable comments/oEmbed/emojis, critical CSS caching, Dashicons removal, REST endpoint pruning |
| Media | `includes/main/media.php` | Custom image sizes, MIME support (WebP/SVG), attachment metadata auto-fill |
| Navigation | `includes/main/navigation.php` | `wp_navigation` block translation, custom URL transforms for nav menu items |
| Logged-User Features | `includes/main/logged-user.php` | Admin bar customization, plugin action dispatch, user-visit tracking, admin notices |
| Plugin Lifecycle | `includes/plugin-lifecycle.php` | Activation (MU sync, translation version, rewrite flush), deactivation, uninstall, auto-backup on version upgrade |
| Channel Tracking | `public/channel-tracking.php` | UTM/channel attribution tracking for WS Form submissions |
| Performance | `public/performance.php` | Critical CSS injection, deferred CSS, featured-image preload, font optimization |

## Module System

Modules live in `modules/{name}/`. Each module has a main entry file (`{name}.php`) and optional config/helper files. Modules are:

1. **Discovered** via `frl_modules_get_keys()` — reads module keys from `FRL_ENV_DEFAULT['modules']`
2. **Enabled/disabled** per environment via `module_{name}` option (set by Environment Manager)
3. **Loaded** by `frl_modules_init()` at `plugins_loaded/5` — iterates enabled modules, calls `include_once` on the entry file

Modules are disposable by design — any module can be deleted without affecting core plugin functionality.

## Design Conventions

- **Re-entrancy guard:** `frl_is_already_running($key)` — static array preventing duplicate execution within one request. Used across cache operations, environment enforcement, rewriter cache clearing, and hook registration.
- **Static-array request cache:** `static $cache = null` inside functions — used everywhere a value is guaranteed not to change mid-request (current user, current language, resolved environment config).
- **Cache-aside via `frl_cache_remember()`:** Standard pattern for anything backed by a DB query — check cache, compute on miss under lock, store, return.
- **Adapter pattern:** Translator (`Frl_Translation_Adapter_Interface`) and Cache Manager's provider detection isolate third-party dependencies behind narrow interfaces.
- **Config as PHP constants:** Cache groups, TTLs, dependencies, environment maps, rewriter feature registries — `grep`-able and diff-able. Not filterable via `apply_filters()`.
- **Deferred/async side effects:** Where safe (rewrite-rule flush, WS Form webhook dispatch), prefers `wp_schedule_single_event()` over blocking the current request.
- **`FRL_MODE=disable` guard position is load-bearing:** Must precede any `FRL_DIR_PATH` usage. The constant is defined in `config/config-base.php`, loaded by `bootstrap.php` AFTER the disable early-return. Placing the guard after a `require_once` that references `FRL_DIR_PATH` causes a fatal error.

## Security

Security headers added in `fralenuvole.php:53-66` via `wp_headers` filter on every request:

- `Content-Security-Policy: object-src 'none'; base-uri 'self';`
- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: SAMEORIGIN`

All admin actions are capability-gated via `frl_has_access()` and nonce-verified. All `$_GET`/`$_POST` access goes through `sanitize_text_field(wp_unslash(...))`. Direct DB queries use `$wpdb->prepare()`.

## Performance Profile

**Frontend, warm cache:** Zero database queries for anything this plugin controls — consequence of layered caching (static array → runtime LRU → persistent cache → batched preload via `auto_preload()`).

**Admin "Hard Reset":** Can take several seconds — expected cost of chaining `purge_all()` + `clear_transients()` + `flush_rewrite_rules(true)`. Paid only on explicit admin request, never on visitor-facing pages.

## Where to Start Reading

1. `includes/bootstrap.php` → `fralenuvole.php` → `includes/main.php` — see exactly what loads and when
2. `docs/PLUGIN-HOOKS.md` — understand the hook-priority contract before touching any `init`-time code
3. `docs/CACHE.md` — nearly every subsystem depends on the cache layer; understand it before reading anything else
4. `docs/ENVIRONMENT.md` — understand how option values can differ from what's actually in the database
5. The subsystem doc relevant to the area you're changing

## Ecosystem Dependencies

| Dependency | Role |
|---|---|
| Polylang | Primary multilingual plugin (implemented adapter) |
| WPML | Alternative (adapter interface ready; no concrete adapter shipped) |
| ACF / SCF | Custom field translation targets |
| WS Form | Form submission webhook dispatch and channel tracking |
| GeoDirectory | Property-listing query/translation filters (`pbproperty` module) |
| Litespeed Cache / Docket Cache / Redis / Memcached | Object-cache backends detected by Cache Manager |
| Kinsta | Hosting platform — MU plugin interacts with Kinsta's cache purge system |