# Fralenuvole Performance Audit Report

**Date:** 2026-07-04
**Plugin Version:** 5.7.4.1
**Analysis Method:** Full execution flow tracing from entry point through every hook registration and callback

---

## Execution Flow

```
fralenuvole.php
├── bootstrap.php
│   ├── config/config.php → ALL 10 config files (every request)
│   ├── includes/helpers/functions.php → ALL 7 helper files (every request)
│   ├── Frl_Cache_Manager::init() → auto_preload() (every request)
│   └── core/error-handler.php (every request)
├── plugins_loaded:5 → frl_plugins_loaded()
│   ├── frl_load_core_components()
│   │   ├── core/cache/cache-cleanup.php
│   │   ├── core/environment/environment-manager.php (if not disabled)
│   │   ├── core/translator/translator.php (if multilingual + not disabled)
│   │   ├── core/rewriter/class-rewriter.php (if not disabled)
│   │   ├── core/themekit/themekit.php (always)
│   │   ├── includes/main.php (always) → registers init/wp_head/wp_footer/shutdown hooks
│   │   └── public/shortcodes.php (always)
│   ├── frl_load_admin_components() [if admin]
│   │   └── admin/admin.php → registers admin_menu/init/current_screen/admin_enqueue_scripts hooks
│   ├── frl_load_public_components() [if frontend]
│   │   ├── public/public.php → registers wp_head/wp_footer/pre_get_posts/rest_endpoints hooks
│   │   └── public/schema/schema.php
│   └── frl_modules_init() → loads enabled modules
```

---

## 🔴 CRITICAL — Runs on EVERY request

### 1. All 10 config files loaded unconditionally
**File:** `config/config.php:10-19`, called from `includes/bootstrap.php:39`

Every request — frontend, admin, AJAX, REST, cron — parses all 10 config files. This includes `config-cache-operations.php` which defines a massive `FRL_CACHE_OPERATIONS` constant with deeply nested arrays and callable references.

**Impact:** Unnecessary I/O and PHP parse cost on requests that don't need all config (e.g., AJAX, REST, cron).

**Fix:** Split config loading by request context. Only load cache-operations when cache operations are actually performed.

---

### 2. `Frl_Cache_Manager::init()` → `auto_preload()` on every request
**File:** `core/cache/class-cache-manager.php:64-73`, called from `includes/bootstrap.php:43`

- Calls `is_object_cache_truly_functional()` (line 107) which calls `get_provider_details()` (line 304) which reads the `object-cache.php` drop-in file content and does `filemtime()` on it — every request, cached only in a 1-week transient.
- When object cache is NOT functional: `batch_preload_transients()` (line 107) executes a single DB query with multiple `LIKE` clauses on `wp_options` — one `LIKE` pair per preload group (6 frontend, 5 backend).
- When object cache IS functional: `preload_multi()` per group — no DB queries, just `wp_cache_get_multiple()`.

**Impact:** On sites without a persistent object cache (Redis, Memcached, Litespeed), every cold-cache request hits `wp_options` with a multi-LIKE query. The `get_provider_details()` method also reads the `object-cache.php` file from disk on cache miss.

**Fix:** The batch preload is already an optimization (replacing N separate LIKE scans with 1). The remaining issue is that `get_provider_details()` does filesystem I/O. Cache the provider details more aggressively or detect the provider once at plugin activation.

---

### 3. `frl_get_current_user()` called extensively, 1-hour cache TTL
**File:** `includes/helpers/functions.php:111-162`

Uses `frl_cache_remember('admin', ...)` with 1-hour TTL. On cache miss: `wp_get_current_user()` hits the DB. Called from `frl_is_logged_in()`, `frl_has_access()`, `frl_admin_bar_menu_render()`, `frl_themekit_admin_body_classes()`, `frl_themekit_frontend_body_classes()`, `frl_trace_logged_user_visits()`, and more.

**Impact:** The 1-hour TTL means this re-queries the DB frequently throughout the day for every logged-in user. The `admin` group TTL is 1 hour by design, but user objects change rarely (only on profile update).

**Fix:** Increase TTL for user object cache to `DAY_IN_SECONDS` and invalidate on `profile_update` and `password_reset` hooks.

---

### 4. `frl_has_access()` — 5-minute cache, DB on miss
**File:** `includes/helpers/functions-access-control.php:25-52`

Uses `frl_cache_remember('admin', ...)` with 300s TTL. On cache miss: `$user->has_cap($capability)` which queries user meta. Called from admin bar rendering, dashboard widgets, admin menu removal, admin notices, and action handlers.

**Impact:** Multiple capability checks per page load, each with a 5-minute cache window. On a busy admin page with multiple widgets and menu items, this can result in several `has_cap()` calls.

**Fix:** Batch capability checks or increase TTL. Capabilities rarely change during a session.

---

## 🟠 HIGH — Frontend request path

### 5. `frl_add_critical_css()` on `wp_head` — filesystem read + CSS minification
**File:** `includes/shared/website-features.php:24-41`, hooked at `includes/main.php:31`

Reads `critical.css` from disk via `file_get_contents()`, minifies CSS. Cached via `frl_cache_remember('html', ...)` with 1-week TTL. On cache miss: synchronous file read + CPU-bound minification on the critical rendering path.

**Impact:** On cache miss (first request after cache clear, or after CSS file change), the `wp_head` action blocks on file I/O and CSS minification. This delays the first byte of HTML content.

**Fix:** Pre-minify critical CSS at save time (in the admin options page) rather than at request time. Store the minified version in the option itself or in a separate transient.

---

### 6. `frl_add_deferred_css()` on `wp_footer` — `file_exists()` every request
**File:** `includes/shared/website-features.php:52-77`, hooked at `includes/main.php:32`

Calls `file_exists(get_stylesheet_directory() . '/deferred.css')` on every page load. Then calls `frl_get_assets_versions()` which does another `filemtime()`. The `file_exists` check is not cached.

**Impact:** Unnecessary filesystem stat call on every page load. While `file_exists` is fast, it's still a syscall on the critical rendering path.

**Fix:** Cache the result of `file_exists` in a static variable or use `frl_cache_remember` with the file's mtime as part of the key.

---

### 7. `frl_preload_featured_image()` on `wp_head` — multiple filesystem checks
**File:** `public/public.php:144-277`, hooked at `public/public.php:12`

Only on singular posts. Uses `frl_cache_remember('postdata', ...)`. On cache miss: `get_post_thumbnail_id()`, `wp_upload_dir()`, `wp_get_attachment_metadata()`, multiple `file_exists()` checks for format variants (e.g., `.avif`). Also calls `frl_textlist_to_array()` to parse mobile hero option on every request (line 203).

**Impact:** On cache miss for a post with a featured image, this does multiple filesystem checks and metadata lookups on `wp_head`. The `frl_textlist_to_array()` call on line 203 is also uncached and runs on every request.

**Fix:** Cache the `hero_mobile_list` parsing result. The `frl_textlist_to_array(frl_get_option('image_preload_hero_mobile'))` on line 203 should be cached since the option rarely changes.

---

### 8. `frl_alter_query()` on `pre_get_posts` — option parsing on every secondary query
**File:** `public/public.php:535-563`, hooked at `public/public.php:14`

Runs on EVERY non-main query. Calls `frl_textlist_to_array(frl_get_option('custom_wp_query'))` on every invocation. The option value doesn't change between queries within a request, but it's re-parsed every time.

**Impact:** On a page with many secondary queries (widgets, related posts, navigation), this parses the same option string repeatedly. Each call goes through the cache layer and textlist parser.

**Fix:** Cache the parsed result in a static variable within the function. The option value won't change during a single request.

---

### 9. `frl_themekit_enqueue_base_styles()` — CSS on every frontend page
**File:** `core/themekit/themekit.php:117-121`, hooked at `core/themekit/themekit.php:46-51`

Enqueues `themekit-styles.css` (8.2 KB) on ALL frontend pages. No conditional loading — even on pages that don't use any themekit features.

**Impact:** 8.2 KB of CSS loaded on every page, including pages that may not use any themekit styles. This is render-blocking CSS.

**Fix:** This is by design (base styles). If the CSS is truly needed everywhere, ensure it's minified. If not, add conditional loading based on page content.

---

### 10. `frl_public_scripts()` — JS on every frontend page
**File:** `public/public.php:43-50`, hooked at `public/public.php:16`

Enqueues `public.js` (6.1 KB) on ALL frontend pages.

**Impact:** 6.1 KB of JavaScript loaded on every page. Enqueued in footer (line 250 of `frl_enqueue_scripts` uses `true` for `$in_footer`), so not render-blocking, but still adds to page weight.

**Fix:** Review if `public.js` is needed on all pages. If it only handles specific interactions, conditionally load it.

---

## 🟠 HIGH — Admin request path

### 11. `frl_admin_scripts()` — CSS on every admin page
**File:** `admin/admin.php:212-216`, hooked at `admin/admin.php:28`

Enqueues `admin.css` (9.4 KB) on ALL admin pages — including pages where the plugin has no UI.

**Impact:** 9.4 KB of CSS loaded on every admin page, including pages like the main dashboard, post list, plugin list, etc. where the plugin's admin styles may not be needed.

**Fix:** Restrict to plugin pages only, or split into a minimal global stylesheet and a full stylesheet for plugin pages.

---

### 12. `frl_load_logged_user_scripts()` — CSS on every page for logged-in users
**File:** `includes/shared/logged-user.php:26-37`, hooked at `includes/shared/logged-user.php:15`

Enqueues `shared-logged-user.css` (5.9 KB) on ALL pages (frontend + admin) for logged-in users. Also enqueues `admin-theme.css` (6.9 KB) if the option is enabled.

**Impact:** Up to 12.8 KB of additional CSS on every page for every logged-in user. This affects both frontend and admin.

**Fix:** The `shared-logged-user.css` is for admin bar styling and logged-in user indicators — this is legitimate. The `admin-theme.css` should only load on admin pages, not frontend.

---

### 13. `frl_trace_logged_user_visits()` — DB write on every page view
**File:** `includes/shared/logged-user.php:477-563`, hooked at `includes/shared/logged-user.php:18-19`

Runs on `wp_footer` and `admin_footer` for every logged-in user. Has a fast-path dedup check via `frl_cache_get('visits', ...)`. On cache miss: `frl_get_user_meta()` (DB read), iterates stored visits, `frl_update_user_meta()` (DB write).

**Impact:** A DB write on every unique page visit by a logged-in user. The fast-path dedup prevents writes on repeat visits to the same URL within 5 minutes, but every new URL still triggers a write.

**Fix:** This is by design for the user visits tracking feature. The dedup already mitigates the worst case. Consider batching writes or using a more efficient storage mechanism (e.g., a custom table instead of user meta).

---

### 14. `frl_get_debug_log_count()` — reads debug.log file
**File:** `includes/shared/logged-user.php:405-468`, called from `frl_admin_bar_add_menu_primary()` at line 153

Reads the last 100KB of `wp-content/debug.log`, counts non-ignored lines. Cached via transient for 5 minutes. On cache miss: synchronous file read + line-by-line iteration on the admin bar rendering path.

**Impact:** On cache miss, the admin bar rendering blocks on reading up to 100KB from disk and iterating line-by-line. This happens on the `admin_bar_menu` hook (priority 9999).

**Fix:** The 100KB cap and 5-minute transient are already good optimizations. Consider moving this to a cron job that updates the transient periodically, so the admin bar never blocks on file I/O.

---

### 15. `frl_batch_update_options()` — O(n×m) field type lookup
**File:** `admin/helpers/functions-admin.php:160-248`

When saving options, calls `frl_get_all_plugin_options_settings(null)` (line 181) which returns ALL plugin fields, then iterates them to find each option's type. This is O(n×m) where n = options being saved, m = total fields defined.

**Impact:** On every settings save, this iterates all plugin fields for each option being saved. With many fields and many options, this can be slow.

**Fix:** Build a `field_id => field_type` lookup map once, then use it for O(1) lookups instead of O(m) scans per option.

---

### 16. `frl_autodiscover_admin_actions()` — iterates all defined functions
**File:** `admin/helpers/functions-admin-action-handlers.php:24-50`, called from `frl_admin_plugins_loaded()` at `admin/admin.php:57`

Calls `get_defined_functions()['user']` and iterates ALL user-defined PHP functions to find those with the `frl_post_` prefix. Runs on every admin request where `frl_is_administrator_action()` is true.

**Impact:** On a WordPress site with many plugins, `get_defined_functions()['user']` can return thousands of functions. Iterating all of them on every admin action request is wasteful.

**Fix:** Cache the discovered handler list in a static variable or transient. The list of `frl_post_*` functions only changes when plugin files are modified.

---

## 🟡 MEDIUM — Conditional but expensive when triggered

### 17. `frl_disable_comments()` — schedules cron, registers multiple hooks
**File:** `includes/shared/website-features.php:234-304`, called from `frl_main_init()` → `frl_disable_wp_core_features()` at line 143

When enabled: iterates all post types, checks cache for completion status, potentially schedules a cron event, registers 7 anonymous function hooks. The cron handler (`frl_run_disable_comments_batch`, line 315) does a direct `$wpdb->update()` on the posts table.

**Impact:** The `$wpdb->update()` on the posts table in the cron handler could lock the table on sites with millions of posts. The code already acknowledges this and uses a cron job instead of running synchronously.

**Fix:** The current implementation is already optimized (cron instead of synchronous). Consider adding a `LIMIT` clause to the UPDATE query for very large tables.

---

### 18. `frl_get_post_id_by_slug()` — two `get_posts()` queries on cache miss
**File:** `includes/helpers/functions.php:340-377`

On cache miss: first `get_posts()` with all public post types + `pagename`, then a second `get_posts()` with non-hierarchical post types + `name`. Two DB queries for a single slug lookup.

**Impact:** Two `get_posts()` queries on cache miss. The results are cached in the `permalinks` group (1-day TTL), so this only affects the first lookup per slug per day.

**Fix:** Use a single `get_page_by_path()` call which handles both hierarchical and non-hierarchical post types. Or use a direct `$wpdb` query with a UNION.

---

### 19. `frl_get_post_terms()` — per-term language check in loop
**File:** `includes/helpers/functions.php:876-927`

When translator is enabled: iterates all terms and calls `pll_get_term_language()` for each term individually. This is N function calls for N terms.

**Impact:** For posts with many terms, this does N `pll_get_term_language()` calls. Each call may hit the cache or database.

**Fix:** Batch the language checks if Polylang provides a bulk function. Or cache the language of each term individually so subsequent calls are fast.

---

### 20. `frl_custom_dashboard_widgets()` — multiple `frl_get_option()` calls
**File:** `admin/admin.php:530-663`, hooked at `admin/admin.php:33`

For each of 7 widgets: calls `frl_get_option()` 2-3 times (enable check, capability check, content check). That's ~20 `frl_get_option()` calls just to render the dashboard. Each call goes through the cache layer.

**Impact:** ~20 cache lookups on every admin dashboard load. While each is fast individually, the cumulative effect is measurable.

**Fix:** The `options` group is preloaded on backend requests, so these should all be runtime cache hits. Verify that the preload is working correctly.

---

## 🟢 LOW — Minor but cumulative

### 21. `frl_add_image_sizes()` — parses textlist on cache miss
**File:** `includes/shared/media.php:20-47`, called from `frl_main_init()` at `includes/main.php:68`

On cache miss: parses the `image_sizes_list` option through `frl_textlist_to_array()`, filters valid sizes. Cached for 1 week.

**Impact:** Only on cache miss (first request after cache clear). The 1-week TTL is appropriate.

---

### 22. `frl_get_avatar_data()` — per-comment avatar lookup
**File:** `includes/shared/media.php:120-152`, hooked at `includes/shared/media.php:110`

Runs on every `get_avatar_data` filter call (every comment, every author box). Uses `frl_cache_remember('options', ...)` with 1-day TTL. On cache miss: `frl_get_user_meta()` + `wp_get_attachment_image_url()`.

**Impact:** On pages with many comments, this filter fires many times. The 1-day cache means most calls are runtime cache hits after the first lookup per user.

---

### 23. `frl_login_page_branding()` — `wp_get_attachment_image_src()` on cache miss
**File:** `public/public.php:437-475`, hooked at `public/public.php:19`

Only on login page. On cache miss: `wp_get_attachment_image_src(get_theme_mod('custom_logo'), 'full')`. Cached via `frl_cache_remember('html', ...)`.

**Impact:** Only affects the login page, which is low-traffic. The caching is appropriate.

---

### 24. `frl_defer_css()` — string matching on every stylesheet
**File:** `public/public.php:288-316`, hooked at `public/public.php:18`

Runs on every `style_loader_tag` filter. Parses the `defer_css_handles` option via `frl_textlist_to_array()` (cached statically), then does `str_contains()` on every enqueued stylesheet's href.

**Impact:** For pages with many stylesheets, this does `str_contains()` on each one. The static cache for the parsed option is good, but the per-stylesheet string matching adds up.

**Fix:** Build a hash map of handles to defer for O(1) lookup instead of iterating the list for each stylesheet.

---

## Summary

| Severity | Count | Runs On |
|----------|-------|---------|
| 🔴 Critical | 4 | Every request |
| 🟠 High (Frontend) | 6 | Every frontend page |
| 🟠 High (Admin) | 6 | Every admin page |
| 🟡 Medium | 4 | Conditional features |
| 🟢 Low | 4 | Specific contexts |
| **Total** | **24** | |

---

## Top 5 Highest-Impact Issues

1. **`auto_preload()` DB query on every cold-cache request** — when no object cache is active, a multi-LIKE query hits `wp_options` on every request. This is already optimized (batched into 1 query instead of N), but still hits the DB on every request without object cache.

2. **`frl_trace_logged_user_visits()` DB write on every page** — user meta read + write on every unique page visit for logged-in users. The fast-path dedup helps, but every new URL still triggers a write.

3. **`frl_alter_query()` re-parses option on every secondary query** — `frl_textlist_to_array()` called on every non-main `WP_Query`. A static cache would eliminate this entirely.

4. **`frl_batch_update_options()` O(n×m) field lookup** — iterates all plugin fields for every option being saved. A pre-built lookup map would make this O(n).

5. **`frl_autodiscover_admin_actions()` iterates all PHP functions** — `get_defined_functions()['user']` on qualifying admin requests. A cached handler list would eliminate this.

---

## Quick Wins (Low Effort, High Impact)

| # | Issue | Fix | Effort |
|---|-------|-----|--------|
| 1 | `frl_alter_query()` re-parses option | Add static cache for parsed `custom_wp_query` | 1 line |
| 2 | `frl_preload_featured_image()` re-parses mobile hero | Cache `hero_mobile_list` in static variable | 2 lines |
| 3 | `frl_batch_update_options()` O(n×m) | Build field type lookup map once | ~10 lines |
| 4 | `frl_autodiscover_admin_actions()` iterates all functions | Cache discovered handlers in static variable | ~5 lines |
| 5 | `frl_defer_css()` iterates handles per stylesheet | Build hash map for O(1) lookup | ~5 lines |