# Performance Patches — Implementation Plan

**Date:** 2026-07-04
**Based on:** `plans/performance-report-2026-07-04.md`
**Scope:** #7, #8, #15, #16 only (excluding #3 and #18 per user decision)

---

## Patch #8 — `frl_alter_query()`: static cache for `custom_wp_query` parsing

**File:** [`public/public.php:548`](public/public.php:548)
**Effort:** 3 lines
**Mechanism:** Add a `static` variable to cache the parsed result of `frl_textlist_to_array(frl_get_option('custom_wp_query'))` for the duration of the request. This avoids re-parsing the same option string on every secondary `WP_Query`.

**Current code (lines 535-564):**
```php
function frl_alter_query($query)
{
    if (!$query instanceof WP_Query || $query->is_main_query()) {
        return;
    }

    $query->set('update_post_meta_cache', false);
    $query->set('update_post_term_cache', false);
    $query->set('no_found_rows', true);
    $query->set('ignore_sticky_posts', true);
    $query->set('post_status', 'publish');
    $query->set( 'has_password', false );

    $cpts_list = frl_textlist_to_array(frl_get_option('custom_wp_query'));

    if (empty($cpts_list)) {
        return;
    }

    $cpts_list = array_column($cpts_list, 0);

    $post_type = $query->get('post_type');

    if ($post_type && in_array($post_type, $cpts_list, true)) {
        $query->set('orderby', 'menu_order');
        $query->set('order', 'ASC');
    }
}
```

**Patch:**
```php
function frl_alter_query($query)
{
    if (!$query instanceof WP_Query || $query->is_main_query()) {
        return;
    }

    $query->set('update_post_meta_cache', false);
    $query->set('update_post_term_cache', false);
    $query->set('no_found_rows', true);
    $query->set('ignore_sticky_posts', true);
    $query->set('post_status', 'publish');
    $query->set( 'has_password', false );

    static $cached_cpts = null;
    if ($cached_cpts === null) {
        $cached_cpts = frl_textlist_to_array(frl_get_option('custom_wp_query'));
    }

    if (empty($cached_cpts)) {
        return;
    }

    $cpts_list = array_column($cached_cpts, 0);

    $post_type = $query->get('post_type');

    if ($post_type && in_array($post_type, $cpts_list, true)) {
        $query->set('orderby', 'menu_order');
        $query->set('order', 'ASC');
    }
}
```

---

## Patch #15 — `frl_batch_update_options()`: pre-build field type lookup map

**File:** [`admin/helpers/functions-admin.php:179-187`](admin/helpers/functions-admin.php:179)
**Effort:** ~12 lines
**Mechanism:** Before the `foreach ($options as $key => $value)` loop, iterate `$all_fields` once to build a `field_id => field_type` map. Use O(1) array lookup inside the loop instead of O(m) linear scan per option.

**Patch (replace lines 168-187):**
```php
    // Build field_id => field_type lookup map once (O(n) instead of O(n×m))
    $all_fields = frl_get_all_plugin_options_settings(null);
    $field_type_map = [];
    foreach ($all_fields as $field) {
        if (isset($field['id'], $field['type'])) {
            $field_type_map[$field['id']] = $field['type'];
        }
    }

    foreach ($options as $key => $value) {
        // Skip if option key is not valid
        if (!is_string($key) || empty($key)) {
            continue;
        }

        // Current value from provided options array
        $current_value = $current_options[$key] ?? null;

        // O(1) field type lookup via pre-built map
        $field_type = $field_type_map[$key] ?? null;
```

Note: The original lines 181-187 (`$all_fields = ...` + inner foreach) are replaced by the pre-built map and the single `$field_type = $field_type_map[$key] ?? null;` line. The `$field_type` variable continues to be used in the comparison logic below (lines 191-228).

---

## Patch #7 — `frl_preload_featured_image()`: static cache for `hero_mobile_list`

**File:** [`public/public.php:202-203`](public/public.php:202)
**Effort:** 3 lines
**Mechanism:** Add a `static` variable to cache the parsed result of `frl_textlist_to_array(frl_get_option('image_preload_hero_mobile'))`. This parsing currently runs on every singular page request, even when the rest of the function's data comes from cache hits.

**Current code (lines 200-213):**
```php
    $hero_mobile_raw  = frl_get_option('image_preload_hero_mobile');
    $hero_mobile_list = frl_textlist_to_array($hero_mobile_raw);
    $has_mobile       = false;

    if (!empty($hero_mobile_list)) {
        $allowed_types = array_column($hero_mobile_list, 0);
        if (in_array($post->post_type, $allowed_types, true)) {
            $has_mobile = true;
        } elseif (in_array('home', $allowed_types, true) && is_front_page()) {
            $has_mobile = true;
        }
    }
```

**Patch:**
```php
    static $hero_mobile_cache = null;
    if ($hero_mobile_cache === null) {
        $hero_mobile_raw    = frl_get_option('image_preload_hero_mobile');
        $hero_mobile_cache  = frl_textlist_to_array($hero_mobile_raw);
    }
    $hero_mobile_list = $hero_mobile_cache;
    $has_mobile       = false;

    if (!empty($hero_mobile_list)) {
        $allowed_types = array_column($hero_mobile_list, 0);
        if (in_array($post->post_type, $allowed_types, true)) {
            $has_mobile = true;
        } elseif (in_array('home', $allowed_types, true) && is_front_page()) {
            $has_mobile = true;
        }
    }
```

---

## Patch #16 — `frl_autodiscover_admin_actions()`: static cache for discovered handlers

**File:** [`admin/helpers/functions-admin-action-handlers.php:24-54`](admin/helpers/functions-admin-action-handlers.php:24)
**Effort:** 5 lines
**Mechanism:** Add a `static $discovered = null;` flag. The list of `frl_post_*` functions cannot change during a single request, and since this only runs on `admin-post.php` actions (not every admin page), this eliminates redundant iteration when multiple admin-post actions fire in the same request.

**Patch (wrap the function body):**
```php
function frl_autodiscover_admin_actions()
{
    // Only proceed if we're in admin context and processing actions
    if (!frl_is_admin() || !frl_is_administrator_action()) {
        return;
    }

    static $discovered = null;
    if ($discovered !== null) {
        return;
    }
    $discovered = true;

    // Register critical handler explicitly
    add_action('admin_post_frl_save_options', 'frl_settings_fields_handle_save_options', 10, 0);

    // Get all defined user functions
    $user_functions = get_defined_functions()['user'];

    foreach ($user_functions as $func) {
        if (str_starts_with($func, frl_prefix('post_'))) {
            $hook_name = 'admin_post_' . $func;
            add_action($hook_name, $func, 10, 0);

            if (str_starts_with($func, frl_prefix('post_ajax_'))) {
                $ajax_hook = 'wp_ajax_' . $func;
                add_action($ajax_hook, $func, 10, 0);
            }
        }
    }
    // Allow extensions
    do_action('frl_autodiscover_admin_actions');
}
```

---

## Summary

| # | File | Lines Changed | Risk |
|---|------|--------------|------|
| #8 | [`public/public.php`](public/public.php) | +3 | None — static cache, same-request immutability |
| #15 | [`admin/helpers/functions-admin.php`](admin/helpers/functions-admin.php) | ~+12, -5 | None — lookup map produces identical results |
| #7 | [`public/public.php`](public/public.php) | +3 | None — static cache, option rarely changes |
| #16 | [`admin/helpers/functions-admin-action-handlers.php`](admin/helpers/functions-admin-action-handlers.php) | +3 | None — function list is request-immutable |

**Total:** ~18 lines added, ~5 removed across 3 files. All changes are additive static caches or pre-built lookup maps — no behavioral changes, no new dependencies, no regression risk.
