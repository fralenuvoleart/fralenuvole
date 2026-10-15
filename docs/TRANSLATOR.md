# Translator

Adapter-pattern translation system decoupled from the multilingual plugin. Handles block content tokens, custom field translation, term translation, and option translation.

## Architecture

```
frl_translator_init()  [field-translator.php]
  → registers hooks for: post meta, term meta, user meta, options, ACF fields, terms, blocks

Frl_Translation_Service  [final, singleton]
  → Frl_Translation_Adapter_Interface
       → Frl_Polylang_Adapter  [adapters/polylang.php]
```

**Key files:**

| File | Role |
|------|------|
| [`core/translator/translator.php`](core/translator/translator.php) | Module entry point — requires loader, service, field-translator |
| [`core/translator/class-translation-service.php`](core/translator/class-translation-service.php) | Central hub — caching, batching, translation lifecycle |
| [`core/translator/field-translator.php`](core/translator/field-translator.php) | Hook-based layer intercepting WordPress metadata calls |
| [`core/translator/adapters/interface.php`](core/translator/adapters/interface.php) | `Frl_Translation_Adapter_Interface` — 14-method contract |
| [`core/translator/adapters/loader.php`](core/translator/adapters/loader.php) | Detection-to-file mapping — only place that knows adapter filenames |
| [`core/translator/adapters/polylang.php`](core/translator/adapters/polylang.php) | Polylang adapter implementation |
| [`core/translator/adapters/polylang-admin-access.php`](core/translator/adapters/polylang-admin-access.php) | Polylang-only `admin_menu` patch |
| [`includes/helpers/functions-translator-helpers.php`](includes/helpers/functions-translator-helpers.php) | Public API — thin wrappers around `Frl_Translation_Service` |
| [`config/config-translator.php`](config/config-translator.php) | All translator constants |

## Adapter Selection

Single source of truth in [`frl_get_translation_adapter_class()`](includes/helpers/functions-translator-helpers.php:52):

```php
function frl_get_translation_adapter_class(): ?string {
    if (frl_is_polylang_active()) return 'Frl_Polylang_Adapter';
    if (frl_is_wpml_active())   return null; // No adapter yet
    return null;
}
```

- `frl_is_polylang_active()` — checks `function_exists('pll_the_languages') || defined('PLL')`
- `frl_is_wpml_active()` — checks `defined('ICL_SITEPRESS_VERSION')`
- `frl_is_multilingual_plugin_active()` — true iff `frl_get_translation_adapter_class()` returns non-null
- `frl_translator_is_enabled()` — `frl_is_multilingual_plugin_active() && !frl_get_option('disable_translator')`

[`adapters/loader.php`](core/translator/adapters/loader.php) handles file loading — the only place that maps "which plugin" to "which file."

**Adding WPML support (3 changes, 2 files):** write `Frl_Wpml_Adapter` in `adapters/wpml.php`; add `elseif` to `frl_get_translation_adapter_class()`; add `elseif` to `adapters/loader.php`.

## Frl_Translation_Service

[`Frl_Translation_Service`](core/translator/class-translation-service.php) is a `final` singleton class. Constructor reads delimiter config from [`FRL_TRANSLATOR_DELIMITER_TEXT`](config/config-translator.php:102) (`{{`/`}}`) and [`FRL_TRANSLATOR_DELIMITER_LINK`](config/config-translator.php:107) (`##`/`##`), then instantiates the adapter.

**Caching strategy:**
- **Request-immutable values** (language, default language, active languages): stored in instance properties — never change during a request
- **Persistent values** (translations, permalinks, block translations, post/term maps): stored via `frl_cache_remember()` in `translations`, `blocks`, `permalinks`, `postdata` groups

**Key methods:**
- `get_language()` — adapter → `$wp_query->query['lang']` fallback → `FRL_TRANSLATOR_DEFAULT_LANG`
- `get_default_language()` — delegates to adapter
- `get_source_language()` — returns `FRL_TRANSLATOR_SOURCE_LANG` (always `'en'`, independent of Polylang's default)
- `get_active_languages()` — adapter → filtered via `frl_active_languages`
- `get_translation($str, $lang)` — translates a string
- `get_translation_block($content, $block)` — processes `{{text}}` and `##permalink##` tokens
- `multilingual_function_exists($name)` — checks if a specific Polylang function exists

## Field Translation

[`frl_translator_init()`](core/translator/field-translator.php:17) registers hooks only when `frl_translator_is_enabled()` AND `frl_is_valid_frontend_page_request()`:

| Hook | Function | Priority |
|------|----------|----------|
| `render_block` | `frl_translate_block_content` | 10 (before `apply_shortcodes` at 20) |
| `get_post_metadata` | `frl_translator_post_meta` | 20 |
| `get_term_metadata` | `frl_translator_term_meta` | 20 |
| `get_user_metadata` | `frl_translator_user_meta` | 20 |
| `pre_option_{name}` | `frl_translator_pre_option` | 10 |
| `acf/format_value/type={type}` | Per-type handler | 20 |
| `get_terms` | `frl_translator_filter_get_terms` | 20 |
| `get_term` | `frl_translator_filter_get_term` | 20 |

Metadata hooks are gated by admin options: `translator_posts`, `translator_terms`, `translator_users`, `translator_options`, `translator_taxonomies`.

## Block Translation

Blocks with `{{text}}` or `##permalink##` delimiters are processed on `render_block` at priority 10 (before `apply_shortcodes` at 20). Three-tier guard:

1. **Fully disabled** (`disable_translator` option) — zero overhead, return as-is
2. **Safe Mode** (no multilingual plugin active) — strips delimiters: `{{Hello}}` → `Hello`, `##slug##` → `#`
3. **Full translation** (Polylang active) — translates tokens via `Frl_Translation_Service`

Safe Mode uses request-level static dedup only (no persistent cache — block content may contain per-request dynamic values).

## Deferred String Registration

Strings discovered during the request are queued and processed on WordPress `shutdown` hook. Capped at [`FRL_TRANSLATOR_MAX_QUEUE_SIZE = 500`](config/config-translator.php:113) to prevent memory exhaustion.

## Configuration Constants

[`config/config-translator.php`](config/config-translator.php):

| Constant | Value | Purpose |
|----------|-------|---------|
| `FRL_TRANSLATOR_DEFAULT_LANG` | `'en'` | Fallback when no plugin active |
| `FRL_TRANSLATOR_SOURCE_LANG` | `'en'` | Language content is authored in |
| `FRL_TRANSLATOR_EXCLUDE` | `['CONTENT*', 'TITLE*', 'FEATURE*', 'PRICE*', 'BADGE*']` | Tokens excluded from translation |
| `FRL_TRANSLATOR_TAXONOMIES` | `['flag-category', 'service_category']` | Taxonomies to auto-translate |
| `FRL_TRANSLATOR_FIELDS` | `[]` | Meta keys to translate (supports `%` wildcard) |
| `FRL_TRANSLATOR_FIELDS_TERMS` | `[]` | Term meta keys to translate |
| `FRL_TRANSLATOR_FIELDS_USERS` | `[]` | User meta keys to translate |
| `FRL_TRANSLATOR_OPTIONS` | `[]` | Option keys to translate (exact match) |
| `FRL_TRANSLATOR_FIELDS_ACF` | `['repeater', 'taxonomy', 'link']` | Complex ACF field handlers |
| `FRL_TRANSLATOR_REPEATER_SUBFIELDS` | `['subheading', 'title', 'content', 'link', 'footer']` | Allowed repeater subfields |
| `FRL_TRANSLATOR_REPEATER_SUBFIELD_TYPES` | `['text', 'textarea', 'wysiwyg']` | Text-like ACF types in repeaters |
| `FRL_TRANSLATOR_DELIMITER_TEXT` | `['start' => '{{', 'end' => '}}']` | Text token delimiters |
| `FRL_TRANSLATOR_DELIMITER_LINK` | `['start' => '##', 'end' => '##']` | Link token delimiters |
| `FRL_TRANSLATOR_MAX_QUEUE_SIZE` | `500` | Max strings queued for registration |
| `FRL_TRANSLATOR_LOG_MISSING_TRANSLATION` | `false` | Debug: log missing functions |

## Public API

All in [`functions-translator-helpers.php`](includes/helpers/functions-translator-helpers.php). Thin wrappers around `Frl_Translation_Service::get_instance()`:

- `frl_get_translation($str, $lang)` — translate a string
- `frl_get_translation_block($content, $block)` — process block for tokens
- `frl_get_language($id, $type)` — get current or object-specific language
- `frl_get_default_language()` — get default site language
- `frl_get_active_languages()` — get all active languages
- `frl_translator_is_enabled()` — central guard
- `frl_multilingual_function_exists($name)` — check specific function
- `frl_is_polylang_active()` / `frl_is_wpml_active()` — plugin detection
- `frl_is_multilingual_plugin_active()` — adapter available check