# Root Cause Analysis: `[frl]` Shortcode Returns English on `ru.pbservices.ge`

## Problem Statement

On `ru.pbservices.ge` (subdomain with default language RU, homepage in RU), the `[frl]english_string[/frl]` shortcode returns the **English string untranslated**. Block translations (`.frl-translate` blocks with `{{...}}` tokens) were also reported as affected.

## Key Architectural Facts

1. **Main domain default language**: `en` (Polylang's DB-configured default, `pll_default_language()` returns `'en'`)
2. **Source language**: `en` (constant `FRL_TRANSLATOR_SOURCE_LANG`, never changes)
3. **Polylang `force_lang=1`**: Configured in **directory mode**, not subdomain mode — so URL parsing looks for `/ru/` prefixes
4. **Subdomain adapter**: Previously attempted to override `pll_default_language` and `pll_current_language` via `add_filter` — but these are **function names in Polylang 3.7+**, not `apply_filters` hooks

## The Mechanics of String Translation

### `pll_translate_string()` in Polylang 3.7+ ([`api.php:288-307`](/mnt/backup/BACKUP/WWW/PBS/public_html/wp-content/plugins/polylang/src/api.php:288))

```php
function pll_translate_string( $string, $lang ) {
    // SHORT-CIRCUIT: if current language == target language, use MO
    if ( PLL() instanceof PLL_Frontend && pll_current_language() === $lang ) {
        return pll__( $string );
    }
    // Otherwise: load the target language's MO from DB
    $lang = PLL()->model->get_language( $lang );
    $mo = new PLL_MO();
    $mo->import_from_db( $lang );
    return $mo->translate( $string );
}
```

**Critical insight**: The short-circuit checks `pll_current_language() === $lang` (not `pll_default_language()`). It compares the **current** language against the target.

When the short-circuit fires:
- Returns `pll__($string)` = `__($string, 'pll_string')` — reads from `$GLOBALS['l10n']['pll_string']`
- This MO is loaded by [`load_strings_translations()`](/mnt/backup/BACKUP/WWW/PBS/public_html/wp-content/plugins/polylang/src/base.php:137) which fires on `pll_language_defined`
- The MO is loaded FOR `pll_current_language()` — so if current = 'en', it loads EN MO

### The Real Flow

```
[frl]shortcode[/frl] on ru.pbservices.ge
  → frl_get_language()
     → Frl_Polylang_Adapter::get_current_language() → pll_current_language()
        → PLL()->curlang->slug → 'en' (because PLL()->curlang was set to EN!)
  → $language = 'en'
  → frl_get_translation($string, 'en')
     → since language = source ('en'), returns English string unchanged ← WRONG!
```

## The Real Root Cause: Dead Hooks

### What the Subdomain Adapter Was Trying To Do

The Subdomain Adapter registered two hooks:

```php
// Line 358 in original code — hooks into NON-EXISTENT filter
add_filter('pll_default_language', [$this, 'filter_pll_default_language'], 1, 1);

// Line 362 in original code — hooks into NON-EXISTENT filter
add_filter('pll_current_language', [$this, 'filter_pll_current_language'], 2, 1);
```

### Why These Hooks Never Fire

In Polylang 3.7+, [`pll_default_language()`](/mnt/backup/BACKUP/WWW/PBS/public_html/wp-content/plugins/polylang/src/api.php:93) and [`pll_current_language()`](/mnt/backup/BACKUP/WWW/PBS/public_html/wp-content/plugins/polylang/src/api.php:62) are **function names**, not filter names:

```php
// api.php:93 — NO apply_filters call
function pll_default_language( $field = 'slug' ) {
    $lang = PLL()->model->get_default_language();
    return $lang ? $lang->$field : false;
}

// api.php:62 — NO apply_filters call
function pll_current_language( $field = 'slug' ) {
    $lang = PLL()->curlang;
    return $lang ? $lang->$field : false;
}
```

Both read directly from Polylang's internal state without any `apply_filters()` call. The `add_filter()` calls in the Subdomain Adapter registered callbacks on filters that **never fire**.

### How Language Resolution Actually Happens

Polylang's [`PLL_Choose_Lang::set_language()`](/mnt/backup/BACKUP/WWW/PBS/public_html/wp-content/plugins/polylang/src/frontend/choose-lang.php:81) is where `PLL()->curlang` is set:

```php
protected function set_language( $curlang = false ): void {
    if ( isset( $this->curlang ) ) { return; }  // ← Already set!

    if ( ! $curlang instanceof PLL_Language ) {
        $curlang = $this->get_current_language();
    }
    // THE REAL FILTER — this is the ONLY hook point
    $curlang = apply_filters( 'pll_get_current_language', $curlang ?? false );

    if ( ! $curlang instanceof PLL_Language ) {
        $curlang = $this->model->get_default_language();  // ← falls back to 'en'
    }
    $this->curlang = $curlang;
}
```

In directory mode (`force_lang=1`), [`PLL_Choose_Lang_Url::get_current_language()`](/mnt/backup/BACKUP/WWW/PBS/public_html/wp-content/plugins/polylang/src/frontend/choose-lang-url.php:48) detects the language from the URL. On `ru.pbservices.ge`, with `hide_default` enabled:
- No `/ru/` prefix in the URL (because subdomain adapter strips it)
- No recognizable language slug
- Returns `null` or default language → `$this->model->get_default_language()` = `'en'`

Then without the `pll_get_current_language` filter firing correctly, `PLL()->curlang` stays as `'en'` on the subdomain.

### Why Changing DB Default to RU Fixes Everything

When `pll_default_language` is changed to `'ru'` in the database:
1. `PLL()->model->get_default_language()` returns the RU `PLL_Language` object
2. `set_language()` falls back to default → `PLL()->curlang = RU`
3. `pll_current_language()` → `'ru'`
4. `load_strings_translations()` fires on `pll_language_defined` → loads RU MO into `$GLOBALS['l10n']['pll_string']`
5. `pll_translate_string('string', 'ru')` → short-circuit fires → `pll__()` reads from RU MO → returns Russian translation

### Why `$wp_query->query['lang']` Was the Accidental Bandage

[`PLL_Choose_Lang_Url::request()`](/mnt/backup/BACKUP/WWW/PBS/public_html/wp-content/plugins/polylang/src/frontend/choose-lang-url.php:102):

```php
public function request( $qv ) {
    if ( isset( $this->curlang ) && empty( $qv['lang'] ) ) {
        $qv['lang'] = $this->curlang->slug;  // ← sets 'en' on subdomain
    }
    return $qv;
}
```

The original `get_language()` unconditionally overwrite:

```php
$language = $wp_query->query['lang'];  // ← 'en'
```

This was overwriting `$this->adapter->get_current_language()` (which also returned 'en' because of the dead hooks) — so it was never the real fix either. Both the adapter and `$wp_query->query['lang']` returned `'en'` on the subdomain.

## The Correct Fix

### Fix: Hook Into the REAL Polylang Filter

The Subdomain Adapter must hook into [`pll_get_current_language`](/mnt/backup/BACKUP/WWW/PBS/public_html/wp-content/plugins/polylang/src/frontend/choose-lang.php:103) with a callback that returns a **`PLL_Language` object** (not a string):

```php
add_filter('pll_get_current_language', [$this, 'filter_pll_get_current_language'], 10, 1);

public function filter_pll_get_current_language($curlang) {
    if ($this->is_on_subdomain()) {
        $subdomain_lang = PLL()->model->get_language($this->current_subdomain_lang);
        if ($subdomain_lang instanceof \PLL_Language) {
            return $subdomain_lang;  // ← PLL_Language object for 'ru'
        }
    }
    return $curlang;
}
```

This filter fires inside `set_language()` **before** the default-language fallback. Returning a valid `PLL_Language` object for 'ru' causes:
1. `PLL()->curlang = RU` (the 'ru' `PLL_Language` object)
2. `pll_current_language()` → `'ru'`
3. `load_strings_translations()` loads RU MO → `$GLOBALS['l10n']['pll_string']` has Russian translations
4. `pll_translate_string('string', 'ru')` → short-circuit fires → `pll__()` reads from RU MO → returns Russian translation ✓

**Remove the two dead hooks** (`pll_default_language` and `pll_current_language` — non-existent filters).

### Why `get_language()` Conditional Fallback Is Correct

With the real filter now working, `Frl_Polylang_Adapter::get_current_language()` returns `'ru'` on the subdomain. The [`get_language()`](includes/core/translator/class-translation-service.php:136) method's conditional fallback:

```php
$language = $this->adapter->get_current_language();  // ← 'ru' (now works!)
if (empty($language)) {
    global $wp_query;
    if (isset($wp_query->query['lang']) && ...) {
        $language = $wp_query->query['lang'];  // ← only used as fallback now
    }
}
```

This is exactly right. The adapter returns the correct language, and `$wp_query->query['lang']` is only a safety net for AJAX/edge cases.

## Files Modified

| File | Change |
|------|--------|
| [`modules/subdomain_adapter/class-subdomain-adapter.php:355`](modules/subdomain_adapter/class-subdomain-adapter.php:355) | Replaced `add_filter('pll_default_language', ...)` + `add_filter('pll_current_language', ...)` with `add_filter('pll_get_current_language', ...)` |
| [`modules/subdomain_adapter/class-subdomain-adapter.php:439`](modules/subdomain_adapter/class-subdomain-adapter.php:439) | New method `filter_pll_get_current_language()` returning `PLL_Language` object |
| [`includes/core/translator/class-translation-service.php:147`](includes/core/translator/class-translation-service.php:147) | `get_language()` uses `$wp_query->query['lang']` as **conditional fallback** only |

## Testing Checklist

- [ ] On `ru.pbservices.ge`, `[frl]Contact Us[/frl]` returns Russian translation "Свяжитесь с нами"
- [ ] On `pbservices.ge` (EN main domain), `[frl]Contact Us[/frl]` returns "Contact Us" (source language, no translation needed)
- [ ] On `pbservices.ge/ru/`, `[frl]Contact Us[/frl]` returns Russian translation (standard Polylang directory mode)
- [ ] Block translations with `{{Contact Us}}` tokens work on all domains
- [ ] Navigation menus display in RU on `ru.pbservices.ge` and EN on `pbservices.ge`
- [ ] No canonical redirect loops on subdomains (`pll_check_canonical_url` still in place)
- [ ] Homepage URL generation remains correct on subdomains
- [ ] `pll_get_current_language` filter passes through unchanged on main domain (not on subdomain)
