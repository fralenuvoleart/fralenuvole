# Fallback Streamlining Analysis

## Current State: Hardcoded 'en' Fallbacks

| Location | Line | Hardcoded Value | Context |
|----------|------|-----------------|---------|
| `frl_get_language()` | 49, 53 | `'en'` | Translator disabled / empty language |
| `frl_get_default_language()` | 66 | `'en'` | Translator disabled |
| `frl_get_active_languages()` | 79 | `['en']` | Translator disabled |
| `frl_get_post_translations()` | 208 | `['en' => $post_id]` | Translator disabled |
| `frl_get_term_translations()` | 222 | `['en' => $term_id]` | Translator disabled |
| `Frl_Translation_Service::get_language()` | 159 | `'en'` | Empty language cache |
| `Frl_Rewriter_Path_Utils::get_active_languages_safe()` | 381 | `['en']` | Empty languages array |
| `Subdomain_Adapter::FALLBACK_LANG` | 55 | `'en'` | Constant for domain mapping |

## Proposed Architecture

### Existing Fallback Methods (Already Implemented)

```php
// Frl_Translation_Service
public function get_active_languages_fallback(): array
public function get_default_language_fallback(): string
```

### Update Plan

#### 1. Wrapper Functions (`functions-translation-helpers.php`)

**`frl_get_language()`** - Replace `'en'` with `get_default_language_fallback()`:
```php
function frl_get_language(?int $id = null, string $type = 'post'): string
{
    if (!frl_translator_is_enabled()) {
        return Frl_Translation_Service::get_instance()->get_default_language_fallback();
    }
    if ($id === null) {
        $language = Frl_Translation_Service::get_instance()->get_language();
        return !empty($language) ? $language : Frl_Translation_Service::get_instance()->get_default_language_fallback();
    }
    return Frl_Translation_Service::get_instance()->get_object_language($id, $type);
}
```

**`frl_get_default_language()`** - Replace `'en'` with `get_default_language_fallback()`:
```php
function frl_get_default_language(): string
{
    if (!frl_translator_is_enabled()) {
        return Frl_Translation_Service::get_instance()->get_default_language_fallback();
    }
    return Frl_Translation_Service::get_instance()->get_default_language();
}
```

**`frl_get_active_languages()`** - Replace `['en']` with `get_active_languages_fallback()`:
```php
function frl_get_active_languages(): array
{
    if (!frl_translator_is_enabled()) {
        return Frl_Translation_Service::get_instance()->get_active_languages_fallback();
    }
    return Frl_Translation_Service::get_instance()->get_active_languages();
}
```

**`frl_get_post_translations()`** - Replace `'en'` key with `get_default_language_fallback()`:
```php
function frl_get_post_translations(int $post_id): array
{
    if (!frl_translator_is_enabled()) {
        $default_lang = Frl_Translation_Service::get_instance()->get_default_language_fallback();
        return [$default_lang => $post_id];
    }
    return Frl_Translation_Service::get_instance()->get_post_translations($post_id);
}
```

**`frl_get_term_translations()`** - Replace `'en'` key with `get_default_language_fallback()`:
```php
function frl_get_term_translations(int $term_id): array
{
    if (!frl_translator_is_enabled()) {
        $default_lang = Frl_Translation_Service::get_instance()->get_default_language_fallback();
        return [$default_lang => $term_id];
    }
    return Frl_Translation_Service::get_instance()->get_term_translations($term_id);
}
```

#### 2. `Frl_Translation_Service::get_language()`

Replace `'en'` with `get_default_language_fallback()`:
```php
public function get_language(): string
{
    if ($this->language_cache === null) {
        $language = $this->adapter->get_current_language();
        if (empty($language)) {
            global $wp_query;
            if (isset($wp_query->query['lang']) && is_string($wp_query->query['lang']) && strlen($wp_query->query['lang']) === 2) {
                $language = $wp_query->query['lang'];
            }
        }
        $this->language_cache = $language;
    }
    if (empty($this->language_cache)) {
        $this->language_cache = $this->get_default_language_fallback();
    }
    return $this->language_cache;
}
```

#### 3. `Frl_Rewriter_Path_Utils::get_active_languages_safe()`

Replace `['en']` with `get_active_languages_fallback()`:
```php
public static function get_active_languages_safe(): array
{
    return frl_cache_remember('rewriter', 'active_languages', function () {
        $languages = frl_get_active_languages();
        return !empty($languages) ? $languages : Frl_Translation_Service::get_instance()->get_active_languages_fallback();
    });
}
```

#### 4. Subdomain Adapter `FALLBACK_LANG`

Replace constant with `frl_get_default_language()` call:
```php
// Remove: private const FALLBACK_LANG = 'en';

// Replace all occurrences of self::FALLBACK_LANG with:
frl_get_default_language()
```

## Benefits

1. **Single source of truth** - All fallbacks go through `Frl_Translation_Service`
2. **Database-level fallbacks** - Works even when Polylang isn't initialized
3. **Subdomain-aware** - Reads from `get_option('polylang')['default_lang']`
4. **No duplicate code** - All hardcoded `'en'` values replaced with helper calls
5. **Consistent behavior** - All components use the same fallback logic

## Regression Risk

**None.** The fallback methods read from the database directly, which is always available. The `'en'` fallback is preserved as the final fallback if the database query fails.
