# Kinsta MU-Plugins: Cache Purge Trigger Reference

> **Source:** `/wp-content/mu-plugins/kinsta-mu-plugins/` — Version 3.7.0
> **Generated:** 2026-09-09

---

## Manual single URL purges
https:/pbservices.ge/kinsta-clear-cache/my-page/

## Cache Purge Types

| Type | Mechanism | Endpoint / Method |
|------|-----------|-------------------|
| **Selective URL (Immediate)** | POST to localhost | `https://localhost/kinsta-clear-cache/v2/immediate` |
| **Selective URL (Throttled)** | POST to localhost | `https://localhost/kinsta-clear-cache/v2/throttled` |
| **Site (Full Page)** | GET to localhost | `https://localhost/kinsta-clear-cache-all` |
| **CDN/Edge** | GET to localhost | `https://localhost/kinsta-clear-cache-cdn` |
| **Object Cache** | PHP function | `wp_cache_flush()` |

---

## Constants That Control Cache Purging

| Constant | Type | Effect | Default | Source |
|----------|------|--------|---------|--------|
| `KINSTAMU_DISABLE_AUTOPURGE` | `bool` | When `true`, **disables all automatic cache purges** globally. Overrides the `kinsta-autopurge-status` option. Manual/admin/WP-CLI purges with `$force = true` still work. | Not defined (autopurge enabled) | [`inc/functions.php:44`](inc/functions.php:44) |
| `KINSTAMU_CACHE_PURGE_TIMEOUT` | `int` | Timeout in seconds for cURL requests to the cache purge endpoints. | `5` | [`class-cache-purge.php:452`](cache/class-cache-purge.php:452) |
| `KINSTAMU_DEBUG_LOG` | `bool` | When `true`, writes debug log entries to `wp-content/kinsta-mu-plugins.log` for every purge event. | Not defined (logging off) | [`inc/functions.php:65`](inc/functions.php:65) |
| `KINSTAMU_CAPABILITY` | `string` | WordPress capability required to view/use cache purge options in admin. | `manage_options` | [`utils/utils.php:56-58`](utils/utils.php:56) |
| `KINSTAMU_ROLE` | `string` | WordPress role required to view/use cache purge options (fallback if `KINSTAMU_CAPABILITY` not set). | `manage_options` | [`utils/utils.php:59-61`](utils/utils.php:59) |
| `KINSTAMU_WHITELABEL` | `bool` | When `true`, removes Kinsta branding from admin pages, toolbar, and notices. | Not defined (branding shown) | [`inc/functions.php:25`](inc/functions.php:25) |
| `KINSTAMU_DISABLE_WPROCKET_NOTICE` | `bool` | When `true`, suppresses the WP Rocket upgrade notice on the dashboard. | Not defined (notice shown) | [`wp-rocket.php:20`](compat/third-party/wp-rocket.php:20) |
| `KINSTAMU_CUSTOM_MUPLUGIN_URL` | `string` | Custom base URL for mu-plugin static assets (CSS, JS, images). | `WPMU_PLUGIN_URL` | [`class-kmp-admin.php:160-162`](admin/class-kmp-admin.php:160) |

### How `KINSTAMU_DISABLE_AUTOPURGE` Works

The function [`is_autopurge_enabled()`](inc/functions.php:42-51) is called before every automatic purge:

```php
function is_autopurge_enabled(): bool
{
    if (defined('KINSTAMU_DISABLE_AUTOPURGE') && KINSTAMU_DISABLE_AUTOPURGE === true) {
        return false;  // ← Hard kill-switch for all automatic purges
    }

    $status = get_option('kinsta-autopurge-status', null);

    return $status === 'enabled' || $status === null;
}
```

**Evaluation order:**
1. If `KINSTAMU_DISABLE_AUTOPURGE` is `true` → autopurge is **disabled** (no further checks)
2. If `kinsta-autopurge-status` option is `'disabled'` → autopurge is **disabled**
3. If `kinsta-autopurge-status` option is `'enabled'` or not set (`null`) → autopurge is **enabled**

**What it does NOT block:**
- Manual purges via the admin AJAX buttons (they pass `$force = true`)
- Admin bar "Clear All Caches" (passes `$force = true`)
- WP-CLI `wp kinsta cache purge --all` (passes `$force = true`)
- WP-CLI `wp kinsta cache purge --site|--object|--cdn` (call `purge_complete_*_cache()` directly, bypassing the gate)

---

## Global Autopurge Gate

All automatic purges are gated by three levels:

1. **`KINSTAMU_DISABLE_AUTOPURGE` constant** ([`is_autopurge_enabled()`](inc/functions.php:42-51)): Hard kill-switch. When `true`, all automatic purges are suppressed regardless of option settings.
2. **`kinsta-autopurge-status` option**: Stored in the database. Values: `'enabled'`, `'disabled'`, or `null` (default = enabled). Toggled via the admin settings page or WP-CLI.
3. **Per-controller toggle**: Each controller's `isOn()` checks `kinsta_kmp_cache_autopurge` option array. Default for most = **on** (`null` → on). Exception: `WPThemeWidgetController` defaults to **off**.
4. **Per-request deduplication**: `$purge_single_happened` and `$purge_all_happened` flags prevent multiple purges within one PHP request.

---

## 1. Summary: Automatic Complete Cache Purges (Object + Site + CDN)

All gated by global autopurge and per-controller toggle. Deduplicated once per request.

| # | Hook | Triggering Action | Controller | Default | Source |
|---|---|---|---|---|---|
| 1 | `wp_update_nav_menu` | Any navigation menu updated | Legacy (`Cache_Purge`) | Always on | [`class-cache-purge.php:132`](cache/class-cache-purge.php:132) |
| 2 | `edited_term` | Any term edited (not created) | Legacy (`Cache_Purge`) | Always on | [`class-cache-purge.php:133`](cache/class-cache-purge.php:133) |
| 3 | `delete_term` | Any term deleted | Legacy (`Cache_Purge`) | Always on | [`class-cache-purge.php:134`](cache/class-cache-purge.php:134) |
| 4 | `updated_option` | Option updated: `blogname`, `blogdescription`, `date_format`, `time_format`, `language` (filterable) | `WPOptionController` | On | [`WPOptionController.php:36`](app/Cache/Autopurge/WPOptionController.php:36) |
| 5 | `switch_theme` | Active theme switched | `WPThemeController` | On | [`WPThemeController.php:16`](app/Cache/Autopurge/WPThemeController.php:16) |
| 6 | `upgrader_process_complete` | **Active** theme updated via upgrader | `WPThemeController` | On | [`WPThemeController.php:17`](app/Cache/Autopurge/WPThemeController.php:17) |
| 7 | `update_option_theme_mods_{$theme}` | Theme mods updated (custom header) — only if theme supports `custom-header` | `WPThemeHeaderController` | On | [`WPThemeHeaderController.php:26`](app/Cache/Autopurge/WPThemeHeaderController.php:26) |
| 8 | `widget_update_callback` (filter) | Widget instance updated — only if theme supports `widgets` | `WPThemeWidgetController` | **Off** | [`WPThemeWidgetController.php:26`](app/Cache/Autopurge/WPThemeWidgetController.php:26) |
| 9 | `update_option_sidebars_widgets` | Widgets added/removed/moved — only if theme supports `widgets` | `WPThemeWidgetController` | **Off** | [`WPThemeWidgetController.php:42`](app/Cache/Autopurge/WPThemeWidgetController.php:42) |
| 10 | `acf/options_page/save` | ACF options page saved — only if ACF is active | `ACFController` | On | [`ACFController.php:17`](app/Cache/Autopurge/ACFController.php:17) |
| 11 | `elementor/core/files/clear_cache` | Elementor regenerates CSS/files — only if Elementor is active | `ElementorController` | On | [`ElementorController.php:13`](app/Cache/Autopurge/ElementorController.php:13) |
| 12 | `elementor/maintenance_mode/mode_changed` | Elementor maintenance mode toggled — only if Elementor is active | `ElementorController` | On | [`ElementorController.php:14`](app/Cache/Autopurge/ElementorController.php:14) |

---

## 2. Granular: Selective URL-Based Cache Purges (`initiate_purge`)

These purge a targeted set of URLs: post permalink, home page, blog page, author archive, term archives, date archives, post type archive, RSS feed, sitemap, custom paths, and AMP variants. Split into **immediate** (single URLs + first page of archives) and **throttled** (remaining archive pages, depth = 3).

| # | Hook | Context/Conditions | Cache Purge Type | Source |
|---|---|---|---|---|
| 1 | `pre_post_update` | Post transitioning **from** `publish` to non-publish. Only if `$purge_single_happened` is false and autopurge enabled. | Selective URL (Immediate + Throttled) | [`class-cache-purge.php:126`](cache/class-cache-purge.php:126) — `post_unpublished()` |
| 2 | `wp_insert_post` | Existing published post updated (`$update === true`, `post_status === 'publish'`). Skips autosaves/revisions. | Selective URL (Immediate + Throttled) | [`class-cache-purge.php:127`](cache/class-cache-purge.php:127) — `post_updated()` |
| 3 | `wp_trash_post` | Any post sent to trash. Does NOT check post status. | Selective URL (Immediate + Throttled) | [`class-cache-purge.php:128`](cache/class-cache-purge.php:128) — `post_trashed()` |
| 4 | `save_post` | Post updated AND published. **Yoast Duplicate Post**: if `_dp_is_rewrite_republish_copy` = `1`, purges the **original** post URL. | Selective URL (Immediate + Throttled) | [`WPPostController.php:22`](app/Cache/Autopurge/WPPostController.php:22) — `onSavePost()` |
| 5 | `transition_post_status` | Status changes (`$newStatus !== $oldStatus`) AND (post is or was published). Same Yoast handling as #4. | Selective URL (Immediate + Throttled) | [`WPPostController.php:23`](app/Cache/Autopurge/WPPostController.php:23) — `onPostStatusChange()` |
| 6 | `edit_comment` | Comment edited AND `comment_approved` = 1. | Selective URL for associated post | [`class-cache-purge.php:121`](cache/class-cache-purge.php:121) — `comment_edit_actions()` |
| 7 | `transition_comment_status` | Comment status transitions where **either** new or old status is `approved`. | Selective URL for associated post | [`class-cache-purge.php:122`](cache/class-cache-purge.php:122) — `comment_transition_actions()` |
| 8 | `wp_insert_comment` | New comment inserted AND `comment_approved` = 1 (pre-approved). | Selective URL for associated post | [`class-cache-purge.php:123`](cache/class-cache-purge.php:123) — `comment_insert_actions()` |
| 9 | `woocommerce_product_set_stock` | Product stock quantity changed. Only if WooCommerce active, controller on, product published. | Selective URL for product | [`WooCommerceController.php:17`](app/Cache/Autopurge/WooCommerceController.php:17) — `onStockChange()` |
| 10 | `woocommerce_variation_set_stock` | Variation stock changed. Purges **parent** product URL. | Selective URL for parent product | [`WooCommerceController.php:18`](app/Cache/Autopurge/WooCommerceController.php:18) — `onStockChange()` |
| 11 | `woocommerce_product_set_stock_status` | Product stock status changed (in_stock ↔ out_of_stock). | Selective URL for product | [`WooCommerceController.php:19`](app/Cache/Autopurge/WooCommerceController.php:19) — `onStockStatusChange()` |
| 12 | `woocommerce_variation_set_stock_status` | Variation stock status changed. Purges **parent** product URL. | Selective URL for parent product | [`WooCommerceController.php:20`](app/Cache/Autopurge/WooCommerceController.php:20) — `onStockStatusChange()` |

### Selective Purge URL Assembly

When [`initiate_purge()`](cache/class-cache-purge.php:331-441) fires:

**Immediate (single URLs):**
- Post permalink
- Home page — if posts page is set, otherwise combined as `home_blog_page`
- Blog posts page (`page_for_posts` permalink) — if set
- Post type archive first page — if different from home/blog
- Custom paths of type `single` from `kinsta-cache-additional-paths`
- AMP variants of all immediate single URLs (`amp/` appended)

**Immediate (group/path-prefix URLs):**
- First page of each archive (author, term, date, post type)
- RSS feed (`/feed/`)
- Custom paths of type `group` from `kinsta-cache-additional-paths`

**Throttled (group URLs):**
- Author archive
- Term archives (all taxonomies except `nav_menu`, `link_category`)
- Date archives (year, month, day)
- Post type archive
- Sitemap (`/sitemap`)

**Filters:** [`KinstaCache/purgeImmediate`](cache/class-cache-purge.php:419), [`KinstaCache/purgeThrottled`](cache/class-cache-purge.php:420)

---

## 3. Granular: Manual / Admin-Triggered Cache Purges

| # | Hook | Context/Conditions | Cache Purge Type | Source |
|---|---|---|---|---|
| 1 | `wp_ajax_kinsta_clear_all_cache` | AJAX: "Clear All Caches" button. **Forces purge** (`$force = true`). | Object + Site + CDN (All) | [`class-cache-purge.php:111`](cache/class-cache-purge.php:111) |
| 2 | `wp_ajax_kinsta_clear_site_cache` | AJAX: "Clear Site Cache" button. | Site (Full Page) only | [`class-cache-purge.php:112`](cache/class-cache-purge.php:112) |
| 3 | `wp_ajax_kinsta_clear_object_cache` | AJAX: "Clear Object Cache" button. | Object Cache only | [`class-cache-purge.php:113`](cache/class-cache-purge.php:113) |
| 4 | `wp_ajax_kinsta_clear_cdn_cache` | AJAX: "Clear CDN Cache" button. | CDN/Edge only | [`class-cache-purge.php:114`](cache/class-cache-purge.php:114) |
| 5 | `admin_init` → Admin Bar | Admin bar "Clear Caches" submenu clicked. **Forces purge** for "all" type. | All, Site, Object, or CDN | [`class-cache-purge.php:117`](cache/class-cache-purge.php:117) — `clear_cache_admin_bar()` |
| 6 | `admin_init` → Autopurge Toggle | Admin page autopurge enable/disable. | **No purge** — updates option only | [`class-cache-purge.php:118`](cache/class-cache-purge.php:118) — `set_autopurge_option()` |
| 7 | `wp_ajax_kinsta_cache_save_settings` | AJAX: "Save Settings" on cache page. | **No purge** — updates `kinsta-autopurge-status` | [`class-kmp-admin.php:112`](admin/class-kmp-admin.php:112) |
| 8 | `wp_ajax_kinsta_save_custom_path` | AJAX: Add custom URL path. | **No immediate purge** — stored for future selective purges | [`class-kmp-admin.php:110`](admin/class-kmp-admin.php:110) |
| 9 | `wp_ajax_kinsta_remove_custom_path` | AJAX: Remove custom URL path. | **No immediate purge** — removes from stored paths | [`class-kmp-admin.php:111`](admin/class-kmp-admin.php:111) |

---

## 4. Granular: WP-CLI Triggered Cache Purges

| # | Command | Context | Cache Purge Type | Source |
|---|---|---|---|---|
| 1 | `wp kinsta cache purge --all` | WP-CLI. **Forces purge**. | Object + Site + CDN (All) | [`class-cache-purge-command.php:162-166`](wp-cli/commands/class-cache-purge-command.php:162) |
| 2 | `wp kinsta cache purge --site` | WP-CLI. | Site (Full Page) only | [`class-cache-purge-command.php:91-109`](wp-cli/commands/class-cache-purge-command.php:91) |
| 3 | `wp kinsta cache purge --object` | WP-CLI. | Object Cache only | [`class-cache-purge-command.php:142-151`](wp-cli/commands/class-cache-purge-command.php:142) |
| 4 | `wp kinsta cache purge --cdn` | WP-CLI. | CDN/Edge only | [`class-cache-purge-command.php:116-134`](wp-cli/commands/class-cache-purge-command.php:116) |
| 5 | `wp kinsta cache purge` (no args) | WP-CLI (backward compat). | Site (Full Page) only | [`class-cache-purge-command.php:80-83`](wp-cli/commands/class-cache-purge-command.php:80) |
| 6 | `wp cache flush` → `after_invoke:cache flush` | Intercepts native WP-CLI cache flush. **Forces purge**. | Object + Site + CDN (All) | [`class-kmp-wpcli.php:69-77`](wp-cli/class-kmp-wpcli.php:69) |

---

## 5. Custom Action Hooks (Fired BY Kinsta)

| # | Hook | When Fired | Source |
|---|---|---|---|
| 1 | `kinsta_purge_complete_caches_happened` | After Object + Site + CDN are all purged. Once per request. | [`class-cache-purge.php:321`](cache/class-cache-purge.php:321) |
| 2 | `kinsta_initiate_purge_happened` | After selective URL-based purge is sent. | [`class-cache-purge.php:430`](cache/class-cache-purge.php:430) |

---

## 6. Cache-Related Filters (Configuration)

| # | Filter | Purpose | Source |
|---|---|---|---|
| 1 | `kinsta/kmp/cache/autopurge` | Per-controller override of autopurge status. Params: `$status`, `$name`. | [`Controller.php:43`](app/Cache/Autopurge/Controller.php:43) |
| 2 | `kinsta/kmp/cache/autopurge/wp/options` | Filter option names that trigger full purge on update. Default: `['blogname','blogdescription','date_format','time_format','language']`. | [`WPOptionController.php:34`](app/Cache/Autopurge/WPOptionController.php:34) |
| 3 | `kinsta/kmp/cache/autopurge/acf/options_page_slugs` | Filter which ACF options page slugs trigger purge. `null` = all. | [`ACFController.php:15`](app/Cache/Autopurge/ACFController.php:15) |
| 4 | `KinstaCache/purgeImmediate` | Modify immediate purge request array before sending. | [`class-cache-purge.php:419`](cache/class-cache-purge.php:419) |
| 5 | `KinstaCache/purgeThrottled` | Modify throttled purge request array before sending. | [`class-cache-purge.php:420`](cache/class-cache-purge.php:420) |
| 6 | `default_option_kinsta-autopurge-status` | Default for global autopurge option. Set to `null` (enabled). | [`Autopurge.php:103`](app/Cache/Autopurge.php:103) |
| 7 | `default_option_kinsta_kmp_cache_autopurge` | Default values for per-controller toggles. | [`Autopurge.php:104-111`](app/Cache/Autopurge.php:104) |
| 8 | `option_kinsta_kmp_cache_autopurge` | Forces filter-based status into option at `PHP_INT_MAX` priority. Per-controller. | [`Controller.php:49-55`](app/Cache/Autopurge/Controller.php:49) |
| 9 | `kinsta_admin_disabled` | Hide entire Kinsta admin menu/toolbar. Returns `bool`. | [`class-kmp-admin.php:188`](admin/class-kmp-admin.php:188) |
| 10 | `site_status_page_cache_supported_cache_headers` | Adds `x-kinsta-cache` header to Site Health page cache detection. | [`class-kmp-admin.php:118-127`](admin/class-kmp-admin.php:118) |

---

## 7. Third-Party Plugin Compatibility

| # | Mechanism | Effect | Source |
|---|---|---|---|
| 1 | `do_rocket_generate_caching_files` → `__return_false` | Disables WP Rocket page caching entirely. | [`wp-rocket.php:83`](compat/third-party/wp-rocket.php:83) |
| 2 | `SWIFT_PERFORMANCE_DISABLE_CACHE` constant → `true` | Disables Swift Performance caching. | [`swift-performance.php:13`](compat/third-party/swift-performance.php:13) |
| 3 | `WORDFENCE_DISABLE_LIVE_TRAFFIC` constant → `true` | Disables Wordfence live traffic logging. | [`wordfence.php:12`](compat/third-party/wordfence.php:12) |
| 4 | `CDN_Enabler` stub class | No-op stub preventing fatal errors with old WP Rocket versions. | [`class-cdn-enabler.php:19-57`](compat/third-party/class-cdn-enabler.php:19) |

---

## 8. Controller Architecture

```
Kinsta\KMP\Cache\Autopurge (manager)
├── Legacy: Kinsta\Cache_Purge (class-cache-purge.php)
│   ├── pre_post_update, wp_insert_post, wp_trash_post
│   ├── edit_comment, transition_comment_status, wp_insert_comment
│   └── wp_update_nav_menu, edited_term, delete_term
│
└── Modular Controllers (app/Cache/Autopurge/)
    ├── WPPostController        → save_post, transition_post_status
    ├── WPOptionController       → updated_option
    ├── WPThemeController        → switch_theme, upgrader_process_complete
    ├── WPThemeHeaderController  → update_option_theme_mods_{$theme}
    ├── WPThemeWidgetController  → widget_update_callback, update_option_sidebars_widgets
    ├── WooCommerceController    → woocommerce_product_set_stock, woocommerce_variation_set_stock,
    │                               woocommerce_product_set_stock_status, woocommerce_variation_set_stock_status
    ├── ACFController            → acf/options_page/save
    └── ElementorController      → elementor/core/files/clear_cache, elementor/maintenance_mode/mode_changed
```

All controllers implement [`Autopurgable`](app/Contracts/Autopurgable.php) (extends `Nameable`, `Describable`, `Purgeable`, `Hookable`). The base [`Controller`](app/Cache/Autopurge/Controller.php) provides `purge()` (calls `purge_complete_caches()`), `isOn()`, `shouldProceed()`, and filter-based status overrides.