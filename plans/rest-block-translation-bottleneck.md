# REST API Block Translation — Refactor Plan

## Problem Summary

Block translation (`{{text}}` / `[[permalink]]` delimiters) runs during REST API requests because the `render_block` filter is registered in [`shortcodes.php`](public/shortcodes.php) without a request context guard. The full translation pipeline (pattern extraction, Polylang adapter calls, cache operations) executes for every block with `frl-translate` class during REST `get_items()` responses — pure waste.

Additionally, the block translation filter is **architecturally misplaced** — it lives in `shortcodes.php` instead of the translator module, making it load unconditionally and bypass the module's guards (`disable_translator`, `frl_is_multilingual_plugin_active()`).

## Root Cause Architecture

```
fralenuvole.php (init:10)
  ├── require_once 'public/shortcodes.php'     ← UNCONDITIONAL (line 121)
  │     └── frl_shortcodes_init() at init:10
  │           ├── render_block p10: frl_shortcode_render_block_translation()  ← NO GUARD
  │           │     ├── frl_get_translation_block()  → Frl_Translation_Service singleton
  │           │     └── apply_shortcodes()
  │           └── render_block p20: apply_shortcodes()  ← redundant second run
  │
  └── if (multilingual && !disable_translator):         ← GUARDED (line 105)
        ├── require_once 'core/translator/translator.php'
        └── frl_translator_init()                       ← field-translator.php:15
              └── get_post_metadata, get_term_metadata, etc.  ← CORRECTLY guarded
```

The translator module is conditionally loaded, but the block translation filter that calls into it is loaded unconditionally from `shortcodes.php`.

## Why Not a Custom Plugin Hook

WordPress `render_block` with priority ordering IS the extension point. Adding a custom `do_action('frl_after_block_translation')` would:
- Add indirection without new lifecycle semantics
- Make filter chain opaque (another developer sees `add_filter('render_block', ...)` and can trace it; a custom hook hidden inside a method is invisible without reading source)
- Violate precedent — this codebase already uses `render_block` priority tiers for ordering (navigation p10, subdomain adapter `PHP_INT_MAX`)

**Standard WordPress pattern:** each subsystem registers independently on `render_block` at its required priority.

## Refactored Architecture

```
render_block priority chain (after refactor):

  p10: frl_translate_block_content()          [NEW — translator module]
       ├── frl_is_valid_frontend_page_request() guard  ← REST skip
       └── frl_get_translation_block() → Frl_Translation_Service

  p10: frl_process_nav_menu_url_transforms()  [navigation.php — unchanged]

  p20: apply_shortcodes()                     [shortcodes.php — unchanged]

  PHP_INT_MAX: subdomain adapter legacy       [unchanged]
```

Key: `apply_shortcodes` at p20 processes ALL block shortcodes (`[frl_meta]`, `[frl_repeater]`, etc.) AFTER translation at p10 has resolved `{{}}` patterns. The p10 translation callback does NOT call `apply_shortcodes` — that's the p20 callback's job. This eliminates the current double-`apply_shortcodes` (once in `frl_shortcode_render_block_translation` at p10, again at p20).

## Changes — 2 files

### File 1: [`public/shortcodes.php`](public/shortcodes.php)

**Remove** the `render_block` p10 filter from `frl_shortcodes_init()` (line 49-53):

```diff
 function frl_shortcodes_init()
 {
-    // Process shortcodes BEFORE translation with higher priority (lower number)
-    add_filter('render_block',
-        'frl_shortcode_render_block_translation',
-        10,
-        2);
     // Process shortcodes AFTER translation with lower priority (higher number)
     add_filter('render_block',
         'apply_shortcodes',
         20,
         2);
```

**Remove** the `frl_shortcode_render_block_translation()` function (lines 1101-1112):

```diff
-/**
- * Processes block content by applying translation and then evaluating shortcodes.
- ...
- */
-function frl_shortcode_render_block_translation($block_content, $block)
-{
-    $translated_content = frl_get_translation_block($block_content, $block);
-    return apply_shortcodes($translated_content);
-}
```

### File 2: [`core/translator/field-translator.php`](core/translator/field-translator.php)

**Add** filter registration inside `frl_translator_init()`:

```diff
 function frl_translator_init(): void
 {
     if (!frl_is_multilingual()) {
         return;
     }
 
     // ... existing code unchanged ...
 
+    // Register block translation on render_block for {{}} delimiters.
+    // Guarded by frl_is_valid_frontend_page_request() to skip REST/CLI/cron.
+    add_filter('render_block', 'frl_translate_block_content', 10, 2);
 }
```

**Add** new function `frl_translate_block_content()` at end of file:

```php
/**
 * Translates {{text}} and [[permalink]] delimiters in block content.
 *
 * Registered on 'render_block' at priority 10 — runs before 'apply_shortcodes'
 * at priority 20, ensuring shortcode output is not re-processed for delimiters.
 *
 * @param string $block_content The rendered block HTML.
 * @param array  $block         The block object.
 * @return string Block content with delimiters translated, or unchanged.
 */
function frl_translate_block_content(string $block_content, array $block): string
{
    // Skip translation in non-frontend contexts (REST, admin, CLI, cron, etc.)
    if (!frl_is_valid_frontend_page_request()) {
        return $block_content;
    }

    return frl_get_translation_block($block_content, $block);
}
```

## Why This is Safe (Zero-Regression Proof)

### 1. Priority ordering preserved

| Before refactor | After refactor |
|---|---|
| p10: `frl_shortcode_render_block_translation` (translation + apply_shortcodes) | p10: `frl_translate_block_content` (translation only) |
| p20: `apply_shortcodes` (redundant second run) | p20: `apply_shortcodes` (only run, processes ALL shortcodes) |

The p20 `apply_shortcodes` processes shortcodes in the content returned by p10. Previously, p10 called `apply_shortcodes` internally, then p20 ran it again as a no-op. Now only p20 runs it — same behavior, fewer calls.

### 2. Translation behavior identical

`frl_translate_block_content()` calls `frl_get_translation_block()` — the same function that `frl_shortcode_render_block_translation()` called. Identical translation output.

### 3. `frl_block_translation_filter` still fires

This filter is applied inside `Frl_Translation_Service::get_translation_block()` at [lines 281 and 298](core/translator/class-translation-service.php:281). The new wrapper calls `frl_get_translation_block()` → same internal path → filter still fires identically.

### 4. Module guards still apply

`frl_translator_init()` already checks `frl_is_multilingual()` at [line 18](core/translator/field-translator.php:18). And `fralenuvole.php` only calls `frl_translator_init()` when `frl_is_multilingual_plugin_active() && !frl_get_option('disable_translator')` at [line 105](fralenuvole.php:105). If translator is disabled, the `render_block` filter is never registered.

### 5. Navigation menu URL transforms unaffected

`frl_process_nav_menu_url_transforms()` at p10 in [`navigation.php:103`](includes/shared/navigation.php:103) is independently registered and unchanged. Both p10 callbacks run in registration order (shortcodes.php loads before navigation.php in the `init:10` vs `init:20` hook order, but both register on `render_block` at p10, so the order depends on which `init` callback runs first). The refactor's new p10 callback is registered from `frl_translator_init()` which fires at `init:10` via [`fralenuvole.php`](fralenuvole.php) (the translator is loaded at `plugins_loaded`, so `frl_translator_init` runs immediately, registering the filter before any `init` callbacks). Same priority, same behavior.

### 6. Subdomain adapter legacy unaffected

Registered at `PHP_INT_MAX` in [`class-subdomain-adapter-legacy.php:85`](modules/subdomain_adapter/class-subdomain-adapter-legacy.php:85). Unchanged.

### 7. `apply_shortcodes` no longer runs twice

Removing the redundant p10 `apply_shortcodes()` call eliminates a wasted function call per block. `apply_shortcodes` is idempotent, so this is performance-only — zero behavioral change.

### 8. REST requests: zero translation overhead

`frl_is_valid_frontend_page_request()` returns `false` during REST ([`functions-access-control.php:281`](includes/helpers/functions-access-control.php:281)). The `Frl_Translation_Service` singleton is never instantiated during REST. No pattern extraction, no adapter calls, no cache operations.

## What Stays in `shortcodes.php`

The file remains responsible for:
- `[frl]` `[frl_meta]` `[frl_repeater]` `[frl_permalink]` `[frl_slug]` etc. — all shortcode registrations via `add_shortcode()`
- `the_title`, `the_excerpt`, TSF filters — `apply_shortcodes` on text fields
- `render_block` p20 — `apply_shortcodes` for block content
- `[frl_breadcrumbs]` `[frl_langswitcher]` `[frl_readtime]` `[frl_featured]` `[frl_year]` `[frl_excerpt]`

Shortcode output like `[frl_meta field=title]` uses `frl_translator_apply()` internally which already has its own `frl_is_valid_frontend_page_request()` guard via [`frl_translator_should_skip_translation()`](core/translator/field-translator.php:609-612). So during REST, shortcodes still render — they just return untranslated values. This is correct: REST consumers get the raw data.

## What Moves to Translator Module

Only one concern: `{{text}}` / `[[permalink]]` delimiter resolution in rendered blocks. This is a translation service concern and belongs in the translator module alongside field translation, term translation, and option translation hooks.

## Verification Checklist

- [ ] `[frl]Translate this{{text}}[/frl]` — translation shortcode + delimiter: translation runs first (p10), then `apply_shortcodes` (p20) processes `[frl]`
- [ ] `[frl_meta field=title]` — shortcode alone: p10 is no-op (no `frl-translate` class), p20 processes normally
- [ ] Block with `className: "frl-translate"` containing `{{Bonjour}}` and `[[post-slug]]` — all delimiters resolved
- [ ] Block without `frl-translate` class — fast-exit in `get_translation_block()`, unchanged
- [ ] REST API `GET /wp/v2/posts` — no translation service instantiation, blocks return as-is
- [ ] Admin block editor — `frl_is_valid_frontend_page_request()` returns `false` (is_admin), translation skipped
- [ ] Navigation menu `#frl_url_*` transforms — separate p10 callback, unchanged
- [ ] Subdomain adapter legacy URL transforms — `PHP_INT_MAX`, unchanged
- [ ] `frl_block_translation_filter` — still fires inside `get_translation_block()`
