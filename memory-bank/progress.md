# Project Progress — Current Feature Inventory

This file is a **snapshot of what is implemented today**, organized by subsystem. It is not a changelog — for historical change history, use `git log`. When a feature changes, update its entry in place; do not append a dated narrative.

---

## Core Subsystems (always loaded)

| Subsystem | Entry Point | Status |
|---|---|---|
| Cache Manager | `core/cache/class-cache-manager.php` (+ `trait-cache-lru.php`, `trait-cache-batch.php`, `trait-cache-diagnostics.php`) | 5-backend unified cache (Litespeed, Docket, Redis, Memcached, Transients) with LRU runtime tier, dependency cascading, language-aware keys. See `docs/CACHE.md`. |
| Cache Operations Orchestrator | `core/cache/class-cache-operations.php` | Composite multi-step cache/rewrite operations with lifecycle hooks, defined in `FRL_CACHE_OPERATIONS`. |
| Environment Manager | `core/environment/` (7 files) | Domain-based auto-configuration: maps HTTP host → environment profile → WP options, plugin options, plugin/module activation state. See `docs/ENVIRONMENT.md`. |
| Rewriter | `core/rewriter/` | Feature-based URL rewriting system (CPT/taxonomy base translation and removal) with priority-ordered, self-registering features. See `docs/REWRITER.md`. |
| Translator | `core/translator/` | Adapter-pattern translation service (Polylang implemented; WPML-ready via `Frl_Translation_Adapter_Interface`). Block token translation (`{{text}}`, `##slug##`), field translation, deferred string registration, language label resolution (`get_language_label()` on adapter, `frl_get_language_label()` helper). See `docs/TRANSLATOR.md`. |
| ThemeKit | `core/themekit/themekit.php` | Theme-independent body classes, base styles, block pattern/provider-style removal, font-display optimization. See `docs/THEMEKIT.md`. |
| Error Handler | `core/error-handler.php` | Custom `set_error_handler()`/`set_exception_handler()` with suppression rules, `doing_it_wrong` interception, `@`-suppression detection (PHP 7 and 8+ compatible). |

## Admin Subsystems

| Subsystem | Entry Point | Status |
|---|---|---|
| Settings UI | `admin/ui/`, `admin/components/class-settings-fields.php` | Tabbed settings page (jQuery UI tabs), dynamic field rendering, widget injection hooks per section. |
| Dashboard | `admin/components/class-dashboard.php` + `admin/widgets/` | Plugin's own Dashboard tab (Environment/Cache summary, Tag Validator, Import/Export, Admin Actions) plus native WP dashboard widgets (Admin Panel, Editor Panel, Last Updates, User Visits, Custom HTML). |
| Cache/Environment Display | `admin/components/class-display-cache.php`, `class-display-environment.php` | Read-only diagnostic tables (cache groups, transients, options DB-vs-cache comparison, managed plugins/modules status). |
| Log Manager | `admin/components/class-display-log.php` | Streaming debug.log viewer (chunked forward/reverse reads for files >256KB), AJAX refresh/clear/download. Entry count and admin-bar bubble share classification/counting logic (`frl_determine_log_error_type()`, `frl_count_debug_log_entries()` in `functions-error-log.php`), excluding Info-level entries; cached separately (`debug_log_count_fast`/`_full`) since the admin bar caps its scan to 100KB while this page scans the full file. |
| Tag Validator | `admin/components/class-tag-validator.php` | On-demand HTML tag presence checker for any URL (via cURL fetch + regex extraction), used to verify critical-CSS/preload/schema tags render correctly. Result cached 5 minutes per URL+tag-set. |
| Import/Export | `admin/components/class-import-export.php`, `admin/helpers/functions-admin-import-export.php` | JSON export/import of plugin settings and Polylang string translations. |
| Metaboxes | `admin/metaboxes/` | "Guidelines" metabox on post/page/service edit screens (opt-in via `editor_metabox` option). |
| Action Handler Auto-Discovery | `admin/helpers/functions-admin-action-handlers.php` | Any `frl_post_*` function is auto-registered as an `admin_post_frl_post_*` handler (and `wp_ajax_*` if prefixed `frl_post_ajax_*`). |

## Frontend/Shared Subsystems

| Subsystem | Entry Point | Status |
|---|---|---|
| Public Hooks | `public/public.php` | Critical CSS injection, deferred CSS, featured-image preload (responsive srcset + mobile hero variant), header/footer HTML+scripts, REST endpoint pruning, secondary-query optimization, login page branding. |
| Shortcodes | `public/shortcodes.php` | `[frl]`, `[frl_lang]`, `[frl_meta]`, `[frl_repeater]`, `[frl_meta_rel]`, `[frl_permalink]`, `[frl_slug]`, `[frl_user_meta]`, `[frl_category_link]`, `[frl_breadcrumbs]`, `[frl_langswitcher]`, `[frl_readtime]`, `[frl_featured]`, `[frl_year]`, `[frl_excerpt]`. |
| Schema | `public/schema/` | Two independent JSON-LD subsystems: static property injection (SASWP integration) and dynamic `wp_head` generator driven by config-mapped post types. |
| Website Features | `includes/shared/website.php` | Disable comments/oEmbed/emojis, critical CSS caching, Dashicons removal for logged-out frontend. |
| Logged-User Features | `includes/shared/logged-user.php` | Admin bar customization (plugin menu, cache actions, CPT quick-links), admin notices display, plugin action dispatch (`frl_process_plugin_actions`), user-visit tracking. |
| Navigation | `includes/shared/navigation.php` | `wp_navigation` block translation support, custom URL transforms for nav menu items (extensible via `frl_nav_menu_url_transforms` filter). |
| Media | `includes/shared/media.php` | Custom image sizes, MIME support extension (WebP/SVG), attachment metadata auto-fill from filename. |

## MU-Plugin (Early Loader)

| Subsystem | Entry Point | Status |
|---|---|---|
| Plugin Exclusion | `assets/mu/frl-mu-plugin.php` → `includes/mu/functions-mu.php` | Prevents specified plugins from loading (without deactivating them) via `pre_option_active_plugins`/`pre_site_option_active_plugins` filters, gated by frontend/backend-screen/capability rules. See `docs/PLUGIN-EXCLUSIONS.md`. |
| Bot Throttle | `includes/mu/functions-mu.php` → `frl_maybe_throttle_user_agent()` | Rate-limits configured User-Agent patterns (429 response) before any WordPress output begins. |
| Cron Sanitization | `includes/mu/functions-mu.php` → `frl_add_exclusion_filter_cron()` | Strips orphaned cron events (from excluded plugins) and normalizes null `args` to prevent `TypeError` during WP-Cron execution. |
| Early Auth Cookie Check | `includes/mu/functions-mu.php` → `frl_get_auth_cookie_user_data()` | Reads and cryptographically verifies (HMAC + password-hash fragment, replicating WordPress's own `wp_validate_auth_cookie()` algorithm) the logged-in auth cookie before `pluggable.php` loads, for the capability-based exclusion check. |

## Modules (opt-in, per-environment)

| Module | Purpose |
|---|---|
| `subdomain_adapter` | Bidirectional URL transformation between a main domain and language-specific subdomain mirrors. See `docs/SUBDOMAIN-ADAPTER.md`. |
| `thirdparty` | SASWP schema property injection, Greenshift REST schema fixes, third-party plugin admin asset conditionals. |
| `wsform` | WS Form language pre-fill, webhook dispatch (async via WP-Cron by default for form submissions; button-click tracking endpoint), channel-tracking UTM capture, spam filter, submission stats widget. |
| `pbnova`, `pbs`, `pbproperty` | Brand-specific custom post types, config constants, and third-party integrations (GeoDirectory query/translation filters) for individual deployments. |
| `acf` / `acf-migration` | ACF shortcode helpers; standalone ACPT → SCF/ACF field migration toolset (parser, importer, repeater transformer, compat shim, WP-CLI commands). |
| `frl` | House brand module: Bible passage audio proxy (ESV API, cached signed-URL redirect), menu sitemap shortcode. |

## Known, Accepted Design Trade-Offs

These are deliberate choices, not defects — documented so future maintainers don't "fix" them into regressions:

- **`frl_get_auth_cookie_user_data()` (MU-plugin) does not check session-token revocation** (`WP_Session_Tokens`). It verifies the cryptographic signature but not explicit "log out everywhere" revocation. Acceptable because this function only gates a soft plugin-visibility decision, not real authentication.
- **`frl_get_post_id_by_slug()` runs two queries** (hierarchical via `pagename`, then non-hierarchical via `name`) rather than one UNION query — `pagename` resolution requires parent→child path structure that non-hierarchical post types don't support, and a UNION would bypass Polylang's `lang` filtering.
- **GeoDirectory language filtering** (`modules/pbproperty/geodirectory.php`) calls `pll_get_post_language()` once per post in a loop — an N+1 pattern amortized by a 24-hour cache; acceptable at the scale of a property listing.
- **The Rewriter's `Frl_Cache_Manager` and `Frl_Environment_Manager` are fully static classes** — not unit-testable in isolation, but this is the established convention across the codebase and consistent with how WordPress itself is typically extended.
- **No automated test suite (PHPUnit or otherwise) — deliberate, not an oversight.** `composer.json` intentionally scopes `require-dev` to static analysis (`phpstan`, `phpstan-wordpress`) and coding-standards tooling (`phpcs`/`wpcs`) only. This is a considered project-philosophy decision, not a gap to flag in future reviews — do not recommend adding a test suite unless explicitly asked to scope one.
- **`Frl_Cache_Manager::remember()` stampede lock requires an object-cache backend (Redis/Memcached).** On transient-only sites, the lock is skipped because `wp_cache_add()` degrades to per-process memory — no cross-process atomicity. Adding a transient-based lock would introduce a TOCTOU race worse than the problem it solves, and MySQL `GET_LOCK()` is too heavy for 110+ call sites. All `remember()` callbacks are idempotent reads, so concurrent double-execution during rare race windows is a known, accepted performance tradeoff, not a correctness bug.
- **`Frl_Cache_Manager::get_cached_value()` no longer has an `is_array($key)` branch.** The old path routed array keys through `get_multi()`, which treated them as a key LIST instead of the hashed compound identity `generate_key()` produced. This was dead code (facade helpers type-hint `string $key`) and has been removed; `$cache_key` is always a string, so the single-key `get_transient()` path handles all `$key` shapes.
- **Brand-specific modules (`modules/pbnova`, `modules/pbs`, `modules/pbproperty`, `modules/wsform`, `modules/frl`, etc.) are disposable by design.** Any of them can be deleted in their entirety at any time at the maintainer's discretion — they exist to serve one deployment's specific needs and are not core plugin surface. Their presence, absence, or eventual removal is never itself a code-quality finding; do not flag "module X could be removed/consolidated/is site-specific" as an issue.

---

*This file describes the current implementation only. For the reasoning behind specific architectural decisions, see `docs/ARCHITECTURE.md` and the subsystem-specific docs it links to.*
