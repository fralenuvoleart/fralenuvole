# Kinsta MU Plugin

**Version:** v3.7.0.

**Kinsta SSH:** `ssh pbservicesge@89.168.108.245 -p 51137`

**Manual single-URL purge:** `https://example.com/kinsta-clear-cache/path/`.

**Hierarchical posts**: `https://example.com/kinsta-clear-cache/page-slug/subpage-slug/`.

## Cache types

| Type | Mechanism |
|---|---|
| **Object** | `wp_cache_flush()` |
| **Site** (full-page, includes Edge) | `GET https://localhost/kinsta-clear-cache-all` |
| **CDN** | `GET https://localhost/kinsta-clear-cache-cdn` |
| **Selective** | `POST` to `/kinsta-clear-cache/v2/immediate` + `/v2/throttled` for specific URLs |

**Naming note:** the `kinsta-clear-cache-all` endpoint clears the **site/full-page cache** (all pages, including Edge) — "all" means all pages, not all cache types. It does **not** clear the object cache or the CDN. A full purge calls all three separately.

**Global gate:** automatic purges run only when global autopurge is ON (`KINSTAMU_DISABLE_AUTOPURGE` ≠ `true`). Controller-based triggers also require that controller ON.

**Selective purge** sends two requests:
- **Immediate** (cleared right away): the post's own URL, the home page, the blog page, the first page of each related archive (author, each term, year/month/day, post-type), the RSS feed, custom single paths, and AMP variants.
- **Throttled** (cleared after a short delay): the remaining pages of those archives, the sitemap, and custom group paths.

---

## Automatic purge triggers

### Full purge — Object + Site + CDN

| Trigger (hook) | Controller / condition |
|---|---|
| Term edited (`edited_term`) | `WPTermController` |
| Term deleted (`delete_term`) | `WPTermController` |
| Option updated: `blogname`, `blogdescription`, `date_format`, `time_format`, `language` (`updated_option`) | `WPOptionController` |
| Theme switched (`switch_theme`) | `WPThemeController` |
| Active theme updated (`upgrader_process_complete`) | `WPThemeController` |
| Theme custom header changed (`update_option_theme_mods_{stylesheet}`) | `WPThemeHeaderController` (theme supports `custom-header`) |
| Nav menu updated (`wp_update_nav_menu`) | `Cache_Purge::purge_complete_caches` — **not gated by any controller** |

### Selective purge — specific URLs

| Trigger (hook) | Controller / condition |
|---|---|
| Published post set to non-published (`pre_post_update`) | `Cache_Purge::post_unpublished` |
| Published post updated (`wp_insert_post`) | `Cache_Purge::post_updated` |
| Post trashed (`wp_trash_post`) | `Cache_Purge::post_trashed` |
| Published post saved (`save_post`) | `WPPostController` (Yoast "Rewrite & Republish" clones purge the original) |
| Post status changed (`transition_post_status`) | `WPPostController` |

All selective purges are gated by `wp_post_controller`.

### CDN only

| Trigger (hook) | Controller / condition |
|---|---|
| Media permanently deleted (`delete_attachment`) | `WPAttachmentController` — default OFF; debounced 30s (a burst of deletions produces one CDN purge) |

---

## Autopurge controllers

| Controller | Description | Live | Default |
|---|---|---|---|
| `wp_post_controller` | Posts updated | on | ON |
| `wp_term_controller` | Terms edited/deleted | off | ON |
| `wp_attachment_controller` | Media permanently deleted (CDN) | off | OFF |
| `wp_option_controller` | Options updated | off | ON |
| `wp_theme_controller` | Theme updated/switched | off | ON |
| `wp_theme_header_controller` | Theme headers updated | — | ON |
| `acf_controller` | ACF options updated | — | ON |

---

## Key constants

| Constant | Effect |
|---|---|
| `KINSTAMU_DISABLE_AUTOPURGE` | `true` = disable all automatic purges (hard kill-switch) |
| `KINSTAMU_CACHE_PURGE_TIMEOUT` | cURL timeout per purge request (default 5s) |
| `KINSTAMU_CAPABILITY` / `KINSTAMU_ROLE` | Capability/role required for cache options (default `manage_options`) |
| `KINSTAMU_DEBUG_LOG` | `true` = log to `wp-content/kinsta-mu-plugins.log` |
| `KINSTAMU_WHITELABEL` | Whitelabel menu configuration |

---

## Hooks

**Actions fired (no arguments):**

| Cache flushed | Hook |
|---|---|
| **Selective** (single post) | `kinsta_initiate_purge_happened` |
| **Full** (Object + Site + CDN) | `kinsta_purge_complete_caches_happened` |

No action fires for site-only, edge-only, CDN-only, or object-only purges.

---

## FAQ

### 1. Do site-editor edits (header, patterns, block menus) trigger a purge?

**No — nothing happens.** The post hooks aborts on `is_post_type_viewable()`. Core registers `wp_template`, `wp_template_part`, `wp_block`, `wp_global_styles`, `wp_navigation` with `'public' => false`, so `is_post_type_viewable()` is `false`. Neither the pattern/template nor the site is purged.

| Site-editor item | Post type | Purge? |
|---|---|---|
| Website header / footer (template part) | `wp_template_part` | No |
| Template | `wp_template` | No |
| Synced pattern | `wp_block` | No |
| Block navigation menu | `wp_navigation` | No |
| Global styles | `wp_global_styles` | No |

**Exception — classic navigation menus:** editing a classic menu (Appearance → Menus) fires `wp_update_nav_menu` → `purge_complete_caches()` → **full purge (Object + Site + CDN)**. Block-based navigation menus do not fire that hook.

**Implication:** site-editor edits leave the Kinsta page cache stale; purge manually (admin-bar "Clear Caches" / "Clear Site Cache", or `wp kinsta cache purge`).

### 2. When a single post is purged, is only that page flushed, or are its terms flushed too?

**Both.** `get_post_archives_list()` includes every attached term via `wp_get_object_terms()` → `get_term_link()`, plus the other targets listed under **Selective purge** above.

### 3. How do I allow only single-post updates and disable all other automatic purges?

Global autopurge ON, `wp_post_controller` ON, all other controllers OFF

**Caveats**: `wp_update_nav_menu` triggers a full purge that is **not** gated by any controller — so strict manual-only is not achievable while global autopurge is on.

### 4. If only `wp_post_controller` is enabled, are the post's terms and Polylang translations flushed?

- **Terms: yes** — flushed as part of the single-post purge (term archives are included). `wp_term_controller` only governs purges triggered by editing/deleting a term itself.
- **Polylang: no** — the plugin has **no Polylang integration**.

### 5. Does flushing the object cache (including Redis) trigger a Kinsta purge?

**No.** The plugin does not hook `wp_cache_flush`; the object purge does not cascade.

### 6. Does the "Clear Site Cache" button also clear the object cache and the edge cache?

**It clears only the site cache and the edge cache**, but not the object cache or the CDN. The button calls only (`GET /kinsta-clear-cache-all`); that endpoint covers the site and Edge cache. The object cache and CDN are separate and are cleared only by "Clear All Caches".

### 7. Do plugin or WP core updates trigger a cache purge?

**No.** There is no `Plugin_Upgrader` or `Core_Upgrader` handling.

### 8. Which hook can be used to warm the cache after a specific cache is flushed?

See the **Hooks** table above: 
- **selective purge**: `kinsta_initiate_purge_happened`; 
- **full purge**: `kinsta_purge_complete_caches_happened`. 

No hooks exist for site-only, edge-only, CDN-only, or object-only purges.

### 9. Does the ACPT plugin satisfy the `class_exists('ACF')` check?

**No.** `ACFController::isSupported()` checks `class_exists('ACF')`; ACPT defines no `class ACF`.

### 10. Is updating a single custom post with custom terms and complex ACPT fields a heavy server operation?

**Not heavy on CPU/DB, but blocking.** `initiate_purge()` does a term lookup (cheap) then sends two synchronous cURL POSTs bounded by `KINSTAMU_CACHE_PURGE_TIMEOUT` (default 5s each). ACPT fields do not affect the Kinsta purge.

### 11. Does enabling `KINSTAMU_DEBUG_LOG` affect performance?

**No meaningful front-end impact.** `debug_log()` does one synchronous file append per purge event (not per request). Caveats: unbounded log growth; per-event disk I/O.