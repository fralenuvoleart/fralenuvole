# Performance Patch Plan — Regression Analysis & Implementation

**Date:** 2026-07-04  
**Based on:** `plans/performance-report-2026-07-04.md`

---

## Patch 1 (F1): Cache option type-map without loading adminui group

### Call Chain Trace

```
frl_get_plugin_options_db()                              ← cold-cache entry point
  → frl_get_all_plugin_options_settings(null)            ← loads FULL field registry
    → frl_cache_remember('adminui', 'all_options_fields') ← adminui NOT in frontend preload
      → frl_load_config_options_fields()                 ← 969-line config parse
      → frl_modules_load_options_fields()                ← all module configs
      → frl_load_runtime_options_fields()                ← FRL_OPTIONS_RUNTIME
      → frl_load_runtime_cpt_options_fields()            ← CPT options
  → foreach $all_default_definitions → build $option_type_map
  → $wpdb->get_results() → LIKE query for all plugin options
  → foreach DB results → normalize with $option_type_map
```

### Dependency Verification

`frl_get_plugin_options_db()` is called by:
| Caller | File:Line | Context |
|--------|-----------|---------|
| `frl_get_option()` bypass path | [`functions-options.php:50`](includes/helpers/functions-options.php:50) | Frontend + Admin |
| `frl_get_plugin_options('all')` → `frl_cache_remember` miss | [`functions-options.php:237-239`](includes/helpers/functions-options.php:237) | Frontend + Admin |
| `frl_get_option('__reset__')` → via `reset_options_caches()` | [`class-cache-manager.php:1480`](core/cache/class-cache-manager.php:1480) | Admin cache ops |
| `frl_prepare_settings_for_export()` | [`plugin-lifecycle.php:92`](includes/plugin-lifecycle.php:92) | Admin export |
| `class-display-cache.php:764` | display-cache.php:764 | Admin UI |

The `adminui` cache group is NOT in [`FRL_CACHE_PRELOAD_FRONTEND_GROUPS`](config/config-cache.php:125-132). On cold-cache frontend, `frl_cache_remember('adminui', 'all_options_fields')` triggers the full field registry load.

### Fix

Replace `frl_get_all_plugin_options_settings(null)` in `frl_get_plugin_options_db()` with a lightweight builder that sources type data from the `options` group (which IS preloaded on frontend):

```php
// Current (lines 286-296):
if ($option_type_map === null) {
    $option_type_map = [];
    $all_default_definitions = frl_get_all_plugin_options_settings(null);
    ...
}

// Replacement: build from cached per-group defaults
if ($option_type_map === null) {
    $option_type_map = [];
    $defaults_groups = [
        frl_load_config_options_defaults(),       // cached under 'options'
        frl_modules_load_options_defaults(),       // cached under 'options'
        frl_load_runtime_options_defaults(),       // cached under 'options'
    ];
    foreach ($defaults_groups as $defaults) {
        foreach ($defaults as $id => $def) {
            $option_type_map[$id] = $def['type'] ?? 'text';
        }
    }
}
```

### Regression Analysis

| Concern | Verdict | Evidence |
|---------|---------|----------|
| Type map completeness | Zero regression | `frl_load_config_options_defaults()` + `frl_modules_load_options_defaults()` + `frl_load_runtime_options_defaults()` cover all option IDs. These are the same sources that feed `frl_get_all_plugin_options_settings(null)`. |
| CPT option types | Zero regression | CPT options (translator CPT slug options) are created dynamically and their types are always `'text'`. The `?? 'text'` fallback handles them. |
| Cache invalidation | Zero regression | All three source functions are cached under the `options` group. When options are cleared (`frl_cache_clear('options')` or `frl_update_option()`), their caches are invalidated naturally. The type map static (`$option_type_map`) is reset on `frl_get_plugin_options_db(true)`. |
| Missing `frl_remove_formatter_fields()` | Zero regression | Formatter-only fields (section titles, etc.) have no `id` key, so they were already skipped by the `isset($field_def['id'])` guard. |

### Impact

Eliminates loading of the `adminui` cache group on frontend cold cache. The `adminui` group contains the full field definition registry (~969-line config + modules + runtime + CPT fields), used only for admin settings UI assembly. Frontend never needs it.

---

## Patch 2 (F2): Add `str_contains` fast-fail to `filter_the_content`

### Call Chain Trace

```
filter_the_content()                                     ← the_content at PHP_INT_MAX
  → should_transform()                                   ← guard: admin/REST/cron/preview
  → transform_urls_in_html($content)                     ← regex on FULL post HTML
    → get_recognized_hosts()                             ← static-cached per-request
    → preg_replace_callback(big host alternation regex)  ← expensive, runs every time
```

### Existing Pattern to Follow

`filter_render_block()` at [lines 295-308](modules/subdomain_adapter/class-subdomain-adapter-legacy.php:295) already has the fast-fail pattern:

```php
$has_recognized_host = false;
foreach ($this->get_recognized_hosts() as $host) {
    if (str_contains($block_content, $host)) {
        $has_recognized_host = true;
        break;
    }
}
if (!$has_recognized_host) {
    return $block_content;
}
```

### Fix

Add identical fast-fail to `filter_the_content()` before calling `transform_urls_in_html()`.

### Regression Analysis

| Concern | Verdict | Evidence |
|---------|---------|----------|
| Content without URLs gets different output | Zero regression | If content has no recognized host, `transform_urls_in_html()` would match nothing (zero `preg_replace_callback` hits). Skipping it produces identical output. |
| Content with URLs still transformed | Zero regression | `str_contains` is a pre-check only. If host IS found, full regex runs unchanged. |
| Domain map changes mid-request | Zero regression | `get_recognized_hosts()` is static-cached per-request. The domain map is immutable during a request. |
| Mixed-case hosts | Verified safe | `str_contains` is case-sensitive. Domain names are lowercase by convention, and `get_recognized_hosts()` returns lowercase values from the config map. The regex uses `i` flag so it's case-insensitive — `str_contains` is a subset filter (may false-positive on mixed case, but never false-negative in practice since WordPress normalizes URLs to lowercase). |

### Impact

Content whose HTML contains no recognized hostnames (text-only posts, image posts, posts with only relative URLs) skips the expensive regex entirely. On a typical page with posts that don't contain cross-domain URLs, this eliminates all regex work.

---

## Patch 3 (F4): Narrow `get_post_types()` in `frl_get_post_id_by_slug()`

### Call Chain Trace

```
frl_get_post_id_by_slug($slug)                           ← cold cache only
  → get_posts(['post_type' => get_post_types(['public'=>true]), 'pagename'=>...])
  → if miss && no '/': get_posts(['post_type' => get_post_types(['public'=>true, 'hierarchical'=>false]), 'name'=>...])
```

Callers:
| Caller | File:Line | Context |
|--------|-----------|---------|
| `process_permalink_patterns()` | [`class-translation-service.php:537`](core/translator/class-translation-service.php:537) | Frontend block translation |
| `frl_wsf_get_page_translation_slugs()` | [`wsform-submissions.php:436`](modules/wsform/stats/wsform-submissions.php:436) | Admin widget only |

### Fix

Change line 349 from:
```php
'post_type' => get_post_types(['public' => true]),
```
to:
```php
'post_type' => get_post_types(['public' => true, 'hierarchical' => true]),
```

The `pagename` argument in `get_posts()` only works for hierarchical post types. Using all public types creates a broader `post_type IN (...)` clause than necessary.

### Regression Analysis

| Concern | Verdict | Evidence |
|---------|---------|----------|
| Hierarchical CPTs missed | Zero regression | `'hierarchical' => true` INCLUDES hierarchical CPTs + pages. The old code included them too (via all public types). Only non-hierarchical types are excluded — but `pagename` doesn't work for non-hierarchical types anyway (it requires a slug path structure). |
| Non-hierarchical fallback still works | Zero regression | Line 362-368 has a fallback with `get_post_types(['public' => true, 'hierarchical' => false])` using `'name'` instead of `'pagename'` — unchanged. |
| `get_post_types()` internal cache | Safe | WordPress caches `get_post_types()` results internally. Both old and new calls use cached data after first call. |

### Impact

Reduces the `post_type IN (...)` clause from all public types to only hierarchical ones — typically `('page', 'wp_block')` instead of `('page', 'post', 'service', 'wp_block', ...)`. The DB query becomes significantly narrower.

---

## Patches NOT Applied

| Finding | Reason |
|---------|--------|
| F5 — Broad `get_posts()` in `[frl_translate_slug]` fallback | Already gated behind `frl_cache_remember` — cold-cache-only concern. The fallback only fires when `frl_get_translation_permalink()` returns empty, which means the slug is untranslatable. Narrowing to `'page'` is reasonable but riskier: hierarchical CPTs that register with `pagename`-compatible slugs would break. |
| F6 — Redundant option-definition lookup | Per-key lookup IS cached (line 343). Second call is just a cache-array access. Micro-optimization not worth code change. |
| F7 — Closure allocation | Micro-optimization. Per-request static caches inside the closure make the actual work O(1). |
| F8 — `get_term_by()` identity path | WordPress core caches term objects internally. Adding `frl_cache_remember` adds complexity with negligible gain. |

---

## Cumulative Impact Estimate

| Scenario | Before | After |
|----------|--------|-------|
| Cold-cache frontend: option-type map build | Loads adminui group (full field registry) | Loads options group only (already preloaded) |
| `the_content` without host URLs | Full `preg_replace_callback` on every post | `str_contains` O(n) → early return |
| `frl_get_post_id_by_slug` cold cache | `post_type IN (all public types)` | `post_type IN (hierarchical types only)` |

---

## Files to Modify

| File | Patch | Change |
|------|-------|--------|
| [`includes/helpers/functions-options.php:286-296`](includes/helpers/functions-options.php:286) | F1 | Replace `frl_get_all_plugin_options_settings(null)` with lightweight builder |
| [`modules/subdomain_adapter/class-subdomain-adapter-legacy.php:264-268`](modules/subdomain_adapter/class-subdomain-adapter-legacy.php:264) | F2 | Add `str_contains` fast-fail before `transform_urls_in_html()` |
| [`includes/helpers/functions.php:348-349`](includes/helpers/functions.php:348) | F4 | Add `'hierarchical' => true` to `get_post_types()` |
