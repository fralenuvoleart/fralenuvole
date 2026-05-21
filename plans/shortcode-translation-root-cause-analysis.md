# Root Cause Analysis: `[frl]` Shortcode Returns English on `ru.pbservices.ge`

## Problem Statement

On `ru.pbservices.ge` (subdomain with default language RU, homepage in RU), the `[frl]english_string[/frl]` shortcode returns the **English string untranslated**. Block translations (`.frl-translate` blocks with `{{...}}` tokens) reportedly work correctly.

## Key Architectural Facts

1. **Main domain default language**: `en` (Polylang's configured default)
2. **Source language**: `en` (constant `FRL_TRANSLATOR_SOURCE_LANG`, never changes)
3. **Subdomain adapter**: Overrides `pll_default_language` to `'ru'` on `ru.pbservices.ge` for clean URL generation
4. **Polylang adapter hardcodes `'en'`**: [`Frl_Polylang_Adapter::get_current_language()`](includes/core/translator/adapters/polylang.php:16) falls back to `'en'` when `pll_current_language()` is unavailable — this is correct as a safety net but conflates "default" with "source"
5. **`get_language()` bug**: [`Frl_Translation_Service::get_language()`](includes/core/translator/class-translation-service.php:136-155) unconditionally overwrites `$language` with `$wp_query->query['lang']` when present, instead of using it as a fallback only when the adapter returns empty

## Architecture Summary

```
[frl] shortcode
  → frl_shortcode_translation() [shortcodes.php:104]
  → frl_get_translation($content) [functions-translation-helpers.php:91]
  → Frl_Translation_Service::get_translation($string, $lang=null) [class-translation-service.php:213]
  → $language = $lang ?: $this->get_language()  → 'ru'
  → $this->adapter->translate_string($string, 'ru') [class-translation-service.php:224]
  → Frl_Polylang_Adapter::translate_string() [adapters/polylang.php:32]
  → pll_translate_string($string, 'ru')
```

## Root Cause: Two Bugs

### Bug 1: `pll_translate_string()` Short-Circuit

**Polylang's `pll_translate_string()` returns the input string unchanged when the target language equals `pll_default_language()`.**

The Subdomain Adapter hooks [`pll_default_language`](modules/subdomain_adapter/class-subdomain-adapter.php:432) at priority 1 to return `'ru'` on `ru.pbservices.ge`. This is the module's core mechanism for generating clean URLs (no `/ru/` prefix) on the Russian subdomain.

However, this has a side effect on string translation:

```php
// Frl_Polylang_Adapter::translate_string() [adapters/polylang.php:32-38]
public function translate_string(string $string, string $language): ?string {
    if (function_exists('pll_translate_string')) {
        $translation = pll_translate_string($string, $language);
        return ($translation !== $string) ? $translation : null;  // ← returns null
    }
    return null;
}
```

When `pll_translate_string('english_string', 'ru')` is called:
1. Polylang internally checks: `is $language === pll_default_language()`?
2. `pll_default_language()` returns `'ru'` (subdomain adapter's filter)
3. Target language `'ru'` === default language `'ru'` → **Polylang returns the original string unchanged** (no DB lookup)
4. `$translation === $string` → adapter returns `null`
5. [`get_translation()`](includes/core/translator/class-translation-service.php:224) falls through to `return $string` — the **original English string**

### Bug 2: `get_language()` Unconditional Overwrite

```php
// Frl_Translation_Service::get_language() [class-translation-service.php:136-155]
public function get_language(): string {
    if ($this->language_cache === null) {
        $language = $this->adapter->get_current_language();  // 'ru' from pll_current_language()

        global $wp_query;
        if (isset($wp_query->query['lang']) && is_string($wp_query->query['lang']) && strlen($wp_query->query['lang']) === 2) {
            $language = $wp_query->query['lang'];  // ← UNCONDITIONALLY overwrites, even if adapter returned valid value
        }

        $this->language_cache = $language;
    }
    // ...
}
```

The `$wp_query->query['lang']` check was likely intended as a **fallback** when the adapter returns empty/invalid, but it unconditionally overwrites a valid adapter result. On the main domain this is harmless (both return `'en'`), but on subdomains it can cause unexpected behavior if `lang` query var is set by Polylang's URL parsing to a different value than `pll_current_language()`.

## Why Block Translations "Work" (But Actually Don't)

Block translations go through the **same code path** (`get_translation()` → `translate_string()` → `pll_translate_string()`). If blocks appear to work, it is because:

1. **WordPress post translations**: The block content is stored as separate translated posts in the database (one per language). Polylang serves the correct post based on the current language. This is **post-level translation**, not **string-level translation** — completely unaffected by `pll_translate_string()`.

2. **`{{...}}` token blocks**: These DO go through `translate_string()` and suffer the **same bug**. If the user perceives them as "working," it's likely because:
   - The block content is primarily post-level translations (separate translated posts per language)
   - The `{{...}}` tokens within blocks happen to be in English on both domains (no translation registered)
   - Or the cache was primed on the main domain before the subdomain adapter was active

## Why It Works on Main Domain (Subdomain Adapter Disabled)

On `pbservices.ge` (main domain, no subdomain adapter):
- `pll_default_language()` returns `'en'` (Polylang's real default)
- `pll_translate_string('english_string', 'en')` → Polylang sees target === default → returns `'english_string'` unchanged
- **But this is correct behavior**: the source language IS `'en'`, so the English string IS the translation
- The [`get_translation_batch_strings()`](includes/core/translator/class-translation-service.php:403) guard: `if ($language === $this->get_source_language())` returns `$strings` as-is, skipping translation entirely

On `ru.pbservices.ge` (subdomain adapter active):
- `pll_default_language()` returns `'ru'` (subdomain adapter's filter)
- `pll_translate_string('english_string', 'ru')` → Polylang sees target === default → returns `'english_string'` unchanged
- **This is WRONG**: the source language is `'en'`, the target is `'ru'`, a translation should be looked up

## The Fix: Two-Part Solution

### Part 1: Fix `Frl_Polylang_Adapter::translate_string()` (Primary Fix)

The adapter must detect when Polylang's short-circuit occurs and fall back to a direct lookup in the `pll_strings` option. This makes the translator service **self-contained and robust** — it works correctly regardless of whether the subdomain adapter is active.

**File**: `includes/core/translator/adapters/polylang.php`

```php
public function translate_string(string $string, string $language): ?string {
    if (!function_exists('pll_translate_string')) {
        return null;
    }

    $translation = pll_translate_string($string, $language);

    // Polylang returns the input unchanged when $language === pll_default_language().
    // On subdomains, pll_default_language() is overridden (e.g., 'ru'), but strings
    // are authored in FRL_TRANSLATOR_SOURCE_LANG ('en'). When the short-circuit
    // is detected, query the pll_strings option directly.
    if ($translation === $string) {
        $source_lang = defined('FRL_TRANSLATOR_SOURCE_LANG')
            ? FRL_TRANSLATOR_SOURCE_LANG
            : 'en';

        // Only attempt fallback when translating FROM source language TO a
        // different language that happens to match Polylang's (possibly filtered)
        // default language. If we're truly in the source language, no translation
        // is needed — return null to let the caller use the original string.
        if ($language !== $source_lang) {
            $pll_strings = get_option('pll_strings', []);
            if (is_array($pll_strings) && !empty($pll_strings)) {
                foreach ($pll_strings as $key => $translations) {
                    if (!is_array($translations)) {
                        continue;
                    }
                    // Check if this entry's source language value matches our input
                    if (isset($translations[$source_lang]) && $translations[$source_lang] === $string) {
                        // Found the string — return the target language translation
                        if (isset($translations[$language]) && $translations[$language] !== '') {
                            return $translations[$language];
                        }
                    }
                }
            }
        }
        return null;
    }

    return $translation;
}
```

### Part 2: Fix `Frl_Translation_Service::get_language()` (Secondary Fix)

The `$wp_query->query['lang']` check should be a **fallback**, not an unconditional overwrite:

**File**: `includes/core/translator/class-translation-service.php`

```php
public function get_language(): string {
    if ($this->language_cache === null) {
        $language = $this->adapter->get_current_language();

        // Only use $wp_query->query['lang'] as a fallback when the adapter
        // returns an empty or invalid language code.
        global $wp_query;
        if (empty($language) && isset($wp_query->query['lang']) && is_string($wp_query->query['lang']) && strlen($wp_query->query['lang']) === 2) {
            $language = $wp_query->query['lang'];
        }

        $this->language_cache = $language;
    }

    // Ensure we never return an empty string (e.g. from Polylang on AJAX)
    if (empty($this->language_cache)) {
        $this->language_cache = 'en';
    }

    return $this->language_cache;
}
```

## Design Principles

1. **Translator service independence**: The fix lives entirely in the Polylang adapter. The translator service does not need to know about the subdomain adapter. It works correctly in all scenarios:
   - Main domain (EN default, EN source) → no translation needed, returns original
   - Main domain with `/ru/` prefix (EN default, EN source, target RU) → Polylang translates normally
   - Subdomain (RU default via filter, EN source, target RU) → fallback lookup finds translation

2. **No filter manipulation**: The fix does not `remove_filter`/`add_filter` around Polylang calls, avoiding re-entrancy risks and race conditions.

3. **Defensive fallback**: The `pll_strings` option lookup only activates when the short-circuit is detected (`$translation === $string`) AND the target language differs from the source language.

4. **`get_language()` correctness**: The `$wp_query->query['lang']` check is now a true fallback, preserving the adapter's result when valid.

## Testing Checklist

- [ ] On `ru.pbservices.ge`, `[frl]Contact Us[/frl]` returns Russian translation "Свяжитесь с нами"
- [ ] On `pbservices.ge` (EN main domain), `[frl]Contact Us[/frl]` returns "Contact Us" (source language, no translation needed)
- [ ] On `pbservices.ge/ru/`, `[frl]Contact Us[/frl]` returns Russian translation
- [ ] Block translations with `{{Contact Us}}` tokens work on all domains
- [ ] No performance regression (fallback only fires on cache miss + short-circuit detection)
- [ ] `$wp_query->query['lang']` fallback works when adapter returns empty (edge case)
- [ ] Subdomain adapter's `pll_default_language` filter continues to work for URL generation
