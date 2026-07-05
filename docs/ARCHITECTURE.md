# Fralenuvole — Architecture Reference

## Executive Summary

Fralenuvole is a comprehensive administrator/developer framework built on top of WordPress, engineered around multi-layer caching, domain-based multi-environment configuration, and multilingual URL handling. It combines frontend performance optimizations (critical CSS, image preloading, deferred assets), advanced URL rewriting (multilingual CPT slugs, category/CPT base removal), block-level translation, and backend tooling (log manager, custom error handling, environment switcher, plugin exclusion) into a single, modular codebase that is deployed unmodified across a small portfolio of related brand websites.

---

## 1. Directory Map

| Directory | Role |
|---|---|
| `core/` | Framework subsystems always available regardless of active modules: cache, environment manager, rewriter, translator, themekit, error handler. |
| `admin/` | WordPress admin UI: settings page, dashboard, widgets, metaboxes, action handlers. |
| `includes/` | Bootstrap, plugin lifecycle hooks, shared frontend/backend helpers, the MU-plugin's own helper file. |
| `public/` | Frontend-only hooks, shortcodes, and the JSON-LD schema subsystem. |
| `modules/` | Opt-in, per-environment feature modules (brand-specific CPTs, third-party integrations, subdomain adapter, WS Form integration, etc.). |
| `config/` | All PHP constants: cache groups/TTLs/dependencies, cache operation definitions, default option fields, environment map/templates, rewriter feature registry, translator delimiters. |
| `assets/mu/` | The single MU-plugin bootstrap file, synced into `wp-content/mu-plugins/` on activation. |
| `docs/` | This reference set — one file per subsystem. |

## 2. Load Order

```
wp-config.php loads (LOGGED_IN_KEY/LOGGED_IN_SALT and other constants become available)
  │
  ▼
mu-plugins/frl-mu-plugin.php   (muplugins_loaded)
  ├─ frl_maybe_throttle_user_agent()      — bot rate-limiting, runs before any output
  └─ frl_filter_plugin_exclusions()       — pre_option_active_plugins filter registration
  │
  ▼
fralenuvole.php   (plugins_loaded / 5)
  └─ frl_plugins_loaded()
       ├─ frl_load_core_components()
       │    ├─ Cache Manager (already loaded in bootstrap.php)
       │    ├─ Environment Manager  (unless disable_environment)
       │    ├─ Translator            (only if a multilingual plugin is active)
       │    ├─ Rewriter              (unless disable_rewriter)
       │    ├─ ThemeKit              (always)
       │    └─ includes/main.php, public/shortcodes.php
       ├─ frl_load_admin_components()     (admin context only)
       ├─ frl_load_public_components()    (valid frontend page requests only)
       └─ frl_modules_init()              — loads enabled modules from config/environment
  │
  ▼
init / 10 — frl_environment_enforce_settings()   ← MUST run before anything that reads options
init / 15 — Rewriter feature registration (add_rewrite_rule per feature)
init / 20 — ThemeKit init
```

See `docs/HOOKS.md` for the full priority table and the reasoning behind each reserved slot.

## 3. Core Subsystems

| Subsystem | Reference | One-line summary |
|---|---|---|
| Cache Manager | `docs/CACHE.md` | Unified 5-backend cache (Litespeed/Docket/Redis/Memcached/Transients) with LRU runtime tier, dependency cascading, language-aware keys, and a composite-operation orchestrator. |
| Environment Manager | `docs/ENVIRONMENT.md` | Domain → environment-profile mapping that auto-applies WP options, plugin options, and plugin/module activation state, with throttled re-enforcement and manual-override tracking. |
| Rewriter | `docs/REWRITER.md` | Feature-based URL rewriting (multilingual CPT slugs, taxonomy/CPT base removal) with priority-ordered, self-registering, mutually-independent features. |
| Translator | `docs/TRANSLATOR.md` | Adapter-pattern translation service decoupled from the multilingual plugin (Polylang implemented). Handles block tokens, field translation, and permalink translation with pattern-based caching. |
| Subdomain Adapter | `docs/SUBDOMAIN-ADAPTER.md` | Bidirectional URL transform between a main domain and language-specific subdomain mirrors, working *with* Polylang's own language-resolution filter rather than post-processing URLs. |
| ThemeKit | `docs/THEMEKIT.md` | Theme-independent body classes, base styles, and block-pattern/provider-style removal. |
| Plugin Exclusion | `docs/PLUGIN-EXCLUSIONS.md` | MU-plugin-based conditional plugin loading (frontend/backend-screen/capability rules) without deactivating the excluded plugin. |
| Admin UI | `docs/ADMIN-UI.md` | Tabbed settings page, dashboard widgets, log manager, tag validator, import/export. |
| Access Control | `docs/PERMISSIONS.md` | Capability model (`frl_has_access()`), superadmin/plugin-admin/administrator tiers. |
| Debug Modes | `docs/DEBUG-MODES.md` | `FRL_MODE` URL parameter / constant for disable/core/nocache/migrate modes. |

## 4. Design Conventions Used Throughout

- **Re-entrancy guard:** `frl_is_already_running($key)` — a static array keyed by function/method/class name — prevents duplicate execution of a routine within one request. Used pervasively across cache operations, environment enforcement, rewriter cache clearing, and hook registration.
- **Static-array request cache:** Cheap, request-scoped memoization (`static $cache = null;` inside a function) is used everywhere a value is guaranteed not to change mid-request (current user, current language, resolved environment config).
- **Cache-aside via `frl_cache_remember()`:** The standard pattern for anything backed by a DB query or external call — check cache, compute on miss under a short lock, store, return.
- **Adapter pattern for external integrations:** The Translator (`Frl_Translation_Adapter_Interface`) and, informally, the Cache Manager's provider-detection logic both isolate a third-party dependency behind a narrow interface so the concrete implementation (Polylang, a specific object-cache drop-in) can change without touching call sites.
- **Config as PHP constants, not filterable options:** Cache groups, TTLs, dependencies, environment maps, and rewriter feature registries are all plain PHP constants in `config/`. This is a deliberate choice for audit-friendliness (`grep`-able, diff-able) over runtime flexibility via `apply_filters()`.
- **Deferred/async side effects:** Where an operation can be safely delayed (rewrite-rule flush before `init`, WS Form webhook dispatch), the codebase prefers `wp_schedule_single_event()` over blocking the current request.

## 5. Performance Profile

**Frontend, warm cache:** Once the persistent cache layer is warm, a frontend page render performs **zero database queries for anything this plugin controls**. This is a direct, verified consequence of the layered caching design — `frl_get_option()`'s static-array tier, `Frl_Cache_Manager`'s runtime LRU layer, `auto_preload()` batching entire cache groups into a single lookup, and the Rewriter's per-request `transform_url()` cache all exist specifically to guarantee this outcome. This is the metric that matters for every normal visitor on every normal page view, and it is the primary basis for the plugin's performance claim.

**Admin "Hard Reset" cache-flush:** This specific, explicit, rarely-used admin action can take several seconds — and this is the expected cost of what it deliberately does, not a defect. It chains two inherently expensive operations in one click:
1. A full purge across every cache group, plus a broad sweep of *all* website transients (not just this plugin's own) across the entire `wp_options` table — the "hard" tier is intentionally comprehensive.
2. A full WordPress rewrite-rule regeneration (`flush_rewrite_rules(true)`), which is one of the slower single operations in WordPress core on any site with multiple custom post types and taxonomies — and the Rewriter subsystem adds further rule volume on top of that baseline (multilingual CPT slug variants, catch-all exclusion patterns capped at `FRL_REWRITER_PAGE_TOPLEVEL_CAP = 500` top-level page slugs).

This cost is correctly scoped: it is paid only when an admin explicitly requests a full reset, it provides visible feedback while it runs, and it never affects a visitor-facing request. Lighter cache-clear tiers (`light`, targeted single-group clears) do not pay this cost — see `docs/CACHE.md` §5.6 for the three clearing tiers and what each one actually touches.

## 6. Where to Start Reading

For a new developer, the recommended reading order is:

1. `includes/bootstrap.php` → `fralenuvole.php` → `includes/main.php` — see exactly what loads and when.
2. `docs/HOOKS.md` — understand the hook-priority contract before touching any `init`-time code.
3. `docs/CACHE.md` — nearly every subsystem depends on the cache layer; understand it before reading anything else.
4. `docs/ENVIRONMENT.md` — understand how option values can differ from what's actually in the database.
5. The subsystem doc relevant to the area you're changing.

## 7. Ecosystem Dependencies

| Dependency | Role |
|---|---|
| Polylang | Primary multilingual plugin (implemented adapter) |
| WPML | Alternative multilingual plugin (adapter interface is ready; no concrete adapter shipped yet) |
| ACF / SCF | Custom field translation targets; `acf-migration` module provides an ACPT→SCF migration path |
| WS Form | Form submission webhook dispatch and channel tracking |
| GeoDirectory | Property-listing query/translation filters (`pbproperty` module) |
| Litespeed Cache / Docket Cache / Redis / Memcached | Object-cache backends detected and used by the Cache Manager when active |
