# Block Editor Interference Report — Fralenuvole Plugin v5.7.3.8

**Date:** 2026-07-01  
**Scope:** All hooks, filters, and operations triggered during block editor content editing and custom field operations  
**Excluded:** `modules/pbnova` (deactivated)

---

## Executive Summary

After a thorough audit of all hooks, filters, and operations triggered during content editing and custom field operations, **the plugin has no critical show-stoppers for block editing**. However, there are several areas of concern ranging from **potential functional interference** to **performance overhead**. The severity depends on which features are enabled via plugin options.

---

## 🔴 HIGH CONCERN — Potential Functional Interference

### 1. `disable_oembed` option → Embed Block Breakage

- **File:** `includes/shared/website-features.php:157`
- **Hook:** `remove_action('rest_api_init', 'wp_oembed_register_route')`
- **Impact:** When enabled, the WordPress oEmbed REST route is unregistered globally. The Gutenberg **Embed block** relies on this endpoint (`/oembed/1.0/proxy`) to fetch embed previews. If this option is ON, Embed blocks will fail to render previews during editing.
- **Severity:** Only triggers if `disable_oembed` option is enabled.

### 2. `disable_comments` option → Removes Comment Post Type Support

- **File:** `includes/shared/website-features.php:234-302`
- **Hook:** `init` (via `frl_disable_wp_core_features` → `frl_main_init`)
- **Impact:** Calls `remove_post_type_support()` for ALL post types on every request, including admin. This strips the "Discussion" panel from the block editor sidebar. Also removes `/wp/v2/comments` REST endpoint globally (affects logged-in editors too).
- **Severity:** Only triggers if `disable_comments` option is enabled. The discussion panel loss is likely intentional, but the REST endpoint removal could affect comment-related block editor functionality.

### 3. `block_type_metadata_settings` Filter → Custom Navigation Block Render Callback

- **File:** `includes/shared/navigation.php:37-83`
- **Hook:** `block_type_metadata_settings` at priority 10
- **Impact:** Replaces the default `render_callback` for ALL `core/navigation` blocks with a custom function that resolves translated navigation IDs via Polylang. This fires on **every request** including block editor page loads and REST API calls. The custom callback calls `render_block_core_navigation()` directly but doesn't account for editor context (e.g., no `WP_Block` instance context, no editor-specific attributes).
- **Severity:** Medium. Only triggers if Polylang is active. Could cause navigation block preview issues in the editor if the translated ID resolution fails or returns unexpected results.

### 4. `render_block` Filter → Navigation URL Transforms

- **File:** `includes/shared/navigation.php:97-162`
- **Hook:** `render_block` at priority 10
- **Impact:** The `frl_process_nav_menu_url_transforms` function is registered via `frl_nav_menu_custom_urls_init()` (on `init` at priority 20). This filter is **NOT guarded** by REST API or admin checks — it fires on ALL requests including block editor operations. It performs regex matching and string replacement on rendered block HTML for `core/navigation-link` and `core/navigation-submenu` blocks.
- **Severity:** Low-Medium. Only triggers if `nav_menu_custom_urls` option is enabled. Could modify rendered HTML in ways the block editor doesn't expect, though the regex only matches `#frl_url_*` patterns.

---

## 🟡 MEDIUM CONCERN — Performance Overhead During Editing

### 5. Global `get_post_metadata` / `get_term_metadata` / `get_user_metadata` Filters

- **File:** `core/translator/field-translator.php:29-36`
- **Hook:** `get_post_metadata` priority 20, `get_term_metadata` priority 20, `get_user_metadata` priority 20
- **Impact:** These filters are registered globally on ALL requests when the translator is active. They intercept **every** metadata read. The guard `!frl_is_valid_frontend_page_request()` inside `frl_translator_should_skip_translation()` returns `true` on admin pages (correctly skipping translation), but the filter function itself still executes — it has to evaluate the guard condition for every metadata call.
- **Mitigation:** The guard is lightweight (just checks `frl_is_admin()` which is statically cached). On admin/block editor pages, the translator correctly bails out early.
- **Severity:** Low performance overhead on admin pages. The function call cost per metadata read is negligible but cumulative on pages with many meta reads.

### 6. ACF `acf/format_value/type=…` Filters

- **File:** `core/translator/field-translator.php:44-48`
- **Hook:** `acf/format_value/type={type}` at priority 20 (for each configured ACF field type)
- **Impact:** These fire during ACF field rendering, including in the block editor when ACF fields are displayed. However, they also have the `!frl_is_valid_frontend_page_request()` guard inside `frl_translator_apply()`.
- **Severity:** Same as #5 — early bail-out on admin pages, minimal overhead.

### 7. `save_post` Hook → Cache Cleanup

- **File:** `core/cache/cache-cleanup.php:16,42-97`
- **Hook:** `save_post` at priority 10
- **Impact:** On actual post publish/update (not autosave), this performs multiple cache clear operations AND calls `update_post_meta($post_id, '_frl_post_version', time())` which itself triggers another `save_post` iteration (though the re-entrant call would be caught by the autosave guard). The `frl_is_post_save_action()` guard correctly skips autosaves, revisions, auto-drafts, and trash.
- **Mitigation:** Gutenberg's autosave every ~60 seconds is properly guarded. Only actual "Publish"/"Update" clicks trigger cache clearing.
- **Severity:** Low. The guard is correct. The overhead on actual saves is expected and necessary.

### 8. Log Capture Hooks on Frontend Block Rendering

- **File:** `includes/main.php:43-46`, `includes/helpers/functions-error-log.php:455-544`
- **Hooks:** `render_block_data` priority 10, `render_block` priority 10, `pre_get_posts` priority 1, `do_shortcode_tag` priority 10
- **Impact:** These are guarded by `!frl_is_rest_api_request()` so they do NOT fire on REST API calls (Gutenberg's primary communication channel). However, they DO fire on **frontend preview pages** loaded by the block editor. The functions are lightweight (push/pop global stack), but `frl_log_capture_render_block_enter` processes block attributes through a whitelist filter on every block render.
- **Severity:** Low. Only affects frontend preview, not the editor itself. Functions are lightweight.

### 9. `wp_theme_json_data_user` Filter → Font Display Swap

- **File:** `core/themekit/themekit.php:54-56`
- **Hook:** `wp_theme_json_data_user`
- **Impact:** When `themekit_font_display_swap` is enabled, this filter modifies theme.json data on every request, including block editor loads. It iterates through custom font families and modifies `fontDisplay` values.
- **Severity:** Negligible. Only fires if option is enabled. Light iteration.

---

## 🟢 LOW CONCERN — Well-Guarded or Benign

### 10. `post_type_link` / `term_link` Filters (Rewriter)

- **File:** `core/rewriter/class-rewriter.php:111-112`
- **Impact:** Has explicit REST API guard (`frl_is_rest_api_request()` returns early) AND preview guard (`is_preview()` returns early). URL transformation is completely disabled for REST requests and previews. Safe for block editor.

### 11. REST Endpoint Disabling

- **File:** `public/public.php:409-428`
- **Impact:** Only fires for **unauthenticated** users (`frl_is_logged_in()` check). Does NOT affect logged-in editors. Safe.

### 12. `pre_get_posts` → `frl_alter_query`

- **File:** `public/public.php:14,433-461`
- **Impact:** Only loaded on frontend requests (`frl_load_public_components` is gated by `frl_is_valid_frontend_page_request()`). Does NOT run on admin/block editor pages. Also only affects non-main queries. Safe.

### 13. Theme Kit → Remove Core/Provider Block Patterns

- **File:** `core/themekit/themekit.php:59-71`
- **Impact:** `remove_theme_support('core-block-patterns')` and `unregister_block_pattern()` run on ALL requests (including admin). This affects the block editor's pattern inserter — fewer patterns available. This is **intentional** behavior controlled by options.

### 14. `frl_gutenberg_editor_css` — Theme CSS in Block Editor

- **File:** `admin/admin.php:226-247`
- **Hook:** `enqueue_block_editor_assets` at priority 9999
- **Impact:** Loads the theme's `style.css` into the block editor for visual consistency. Uses `frl_cache_remember` for file modification time. Benign and beneficial.

### 15. Metabox Registration

- **File:** `admin/metaboxes/class-metabox.php`
- **Impact:** Registers a "Guidelines" meta box on post/page/service screens. Standard WordPress metabox registration. Compatible with block editor (appears in sidebar). Benign.

### 16. Environment Manager

- **File:** `core/environment/class-environment-manager.php`
- **Impact:** Hooks into `admin_bar_menu`, `activated_plugin`, `deactivated_plugin`. The `enforce_environment_settings` runs on `init` but is throttled (60s for admins) and only checks for environment state changes. Benign for block editing.

### 17. ACF `acf/save_post` → Calculated Options

- **File:** `modules/acf/acf.php:26-30`
- **Impact:** Hooked at priority 999, only processes when `$post_id === 'options'`. Does NOT interfere with post/page saves in the block editor.

### 18. Shutdown Hooks

- **File:** `includes/main.php:40`, `core/translator/class-translation-service.php:619-624`, `core/translator/field-translator.php:424-427`
- **Impact:** `frl_process_deferred_writes`, `process_string_registration_queue`, and `frl_translator_process_tracking_queue` run on `shutdown`. These are deferred operations that run after the response is sent. No impact on block editor responsiveness.

---

## Summary Table

| # | Feature/Code | Option Toggle | Fires in Block Editor? | Impact |
|---|---|---|---|---|
| 1 | oEmbed REST removal | `disable_oembed` | Yes (global) | Embed block may break |
| 2 | Comments disabling | `disable_comments` | Yes (global) | Discussion panel removed |
| 3 | Nav block render callback | Always (if Polylang) | Yes | Nav block preview risk |
| 4 | Nav URL transforms | `nav_menu_custom_urls` | Yes (unguarded) | Minor HTML modification |
| 5 | get_post_metadata filter | Translator options | Yes, but early bail | Minimal overhead |
| 6 | ACF format_value filter | Translator options | Yes, but early bail | Minimal overhead |
| 7 | save_post cache cleanup | Always | On publish only | Guarded, expected |
| 8 | Render block logging | Always | Preview only | Lightweight |
| 9 | Font display swap | `themekit_font_display_swap` | Yes | Negligible |
| 10 | post_type_link/term_link | Rewriter options | REST guard: NO | Safe |
| 11 | REST endpoint disable | `disable_rest` | Auth guard: NO | Safe |
| 12 | pre_get_posts alter_query | Always | Frontend guard: NO | Safe |
| 13 | Remove block patterns | Themekit options | Yes | Intentional |
| 14 | Editor CSS | Always | Yes | Beneficial |
| 15 | Metabox (Guidelines) | `editor_metabox` | Yes | Benign |
| 16 | Environment Manager | Always | Throttled | Benign |
| 17 | ACF save_post calc | Always | Options only | Benign |
| 18 | Shutdown hooks | Always | After response | No impact |

---

## Guard Function Analysis

### `frl_is_valid_frontend_page_request()`
Returns `!frl_is_admin() && frl_is_valid_page_request()`. On admin/block editor pages, `frl_is_admin()` returns `true`, so this returns `false`. This correctly prevents frontend-only code from running during editing.

### `frl_is_rest_api_request()`
Checks for `/wp-json/` URI or `REST_REQUEST` constant. Block editor uses REST API for saves, so this correctly identifies Gutenberg communication channels. Hooks guarded by this check do NOT fire during editor REST calls.

### `frl_is_post_save_action()`
Checks `DOING_AUTOSAVE`, `wp_is_post_revision()`, `wp_is_post_autosave()`, and post status (`auto-draft`, `trash`, `inherit`). Correctly guards against Gutenberg's periodic autosaves (every ~60 seconds) to prevent unnecessary cache clearing.

---

## Recommendations

1. **No immediate action required** for block editing functionality.
2. **Monitor** the `disable_oembed` option — if enabled, document that Embed blocks will lose preview capability.
3. **Consider** adding a REST API guard to `frl_process_nav_menu_url_transforms()` in `navigation.php` if `nav_menu_custom_urls` is not needed for API responses.
4. **The translator metadata filters** are well-guarded but could be further optimized by skipping registration entirely on admin pages (currently they register but bail out early inside the callback).