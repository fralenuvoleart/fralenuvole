# Root Cause Analysis: Intermittent 404 Errors on Non-EN Pages After Litespeed Cache Purge

## CORRECTED ANALYSIS (After User Feedback and DB Inspection)

### Key Facts from User
1. The rewriter DOES handle pages and posts (via `post_type_link` filter)
2. Issue is in translator and Polylang interactions
3. All language fallbacks are 'en'
4. When 404 errors occur, need to flush permalinks AND Litespeed MULTIPLE times
5. Issue appears mostly during **automatic updates** that trigger Litespeed purge, not manual purge_all
6. Possible that fralenuvole cache purge is not triggered during automatic updates due to guards

### Polylang Language Storage (from DB inspection)

Languages are stored as terms in the `language` taxonomy:
- `wp_terms.slug`: 2-letter language code (en, ru, ar, zh)
- `wp_term_taxonomy.description`: serialized locale data (e.g., `a:3:{s:6:"locale";s:5:"en_US";...}`)

Note: Polylang may also create terms with slugs like `pll_en`. The correct language terms have exactly 2-character slugs.

### Root Cause: `get_active_languages()` Returns `['en']` During Automatic Updates

When Litespeed purges cache during automatic updates:
1. Litespeed calls `wp_cache_flush()` → clears ALL object cache
2. `litespeed_purged_all` fires → plugin's inbound handler clears 'light' cache groups
3. `purge_light()` clears `rewriter` group (it's NOT in `FRL_CACHE_HEAVY_GROUPS`)
4. Next request: `compute_exclusion_patterns()` is called to regenerate exclusion patterns
5. `compute_exclusion_patterns()` calls `get_active_languages_safe()`
6. `get_active_languages_safe()` calls `frl_get_active_languages()` → `Frl_Translation_Service::get_active_languages()` → `Frl_Polylang_Adapter::get_active_languages()`
7. **If Polylang hasn't fully initialized** (e.g., during CLI/cron/early AJAX during automatic updates), `pll_languages_list()` returns empty array
8. Fallback in adapter: `return [$this->get_default_language()]` → `pll_default_language()` may also return empty → fallback to `'en'`
9. Exclusion patterns are generated with only `['en']` as active languages
10. Non-EN page URLs (e.g., `/ru/page-slug/`) are NOT excluded from catch-all rules
11. Catch-all rules hijack non-EN page URLs → 404 errors

### Why Only Non-EN Pages?

The exclusion patterns include language-prefixed patterns:
```php
foreach ($langs as $lang) {
    $patterns[] = self::escape_for_regex("{$lang}/{$slug}");
}
```

If `$langs` is only `['en']`, only `/en/page-slug/` is excluded. Non-EN patterns like `/ru/page-slug/`, `/ar/page-slug/`, `/zh/page-slug/` are NOT excluded.

Catch-all rules (CPT Base Removal, Taxonomy Base Removal) then intercept these URLs, setting `frl_cpt_base_path` or `frl_tax_base_path` query vars instead of `pagename`. WordPress looks for a CPT or taxonomy term instead of a page → 404.

### Why Homepages Work?

Homepages are handled by WordPress's default front-page logic, not by the rewriter's catch-all rules. They don't go through the exclusion pattern system.

### Why EN Pages Work?

EN pages ARE excluded from catch-all rules (because 'en' is always in the active languages list). So they resolve correctly.

### Why Multiple Flushes Needed?

After the first flush, the exclusion patterns are regenerated. But if Polylang still hasn't fully initialized (e.g., during the same request), the patterns are still wrong. Multiple flushes eventually trigger a request where Polylang IS initialized, and the patterns are regenerated correctly.

### Why Automatic Updates Trigger This?

During automatic updates, WordPress runs in CLI/cron context where Polylang may not fully initialize. The `frl_is_valid_page_request()` guard returns false for CLI/cron/AJAX, so the plugin's cache operations may be skipped or behave differently.

## Proposed Fix

### Option 1: Add New Translator Fallback Method (Recommended)

Add a new method `get_active_languages_fallback()` to `Frl_Translation_Service` that queries the database directly:

```php
// In includes/core/translator/class-translation-service.php

/**
 * Get active languages by querying the database directly.
 * Used as fallback when Polylang's pll_languages_list() returns empty
 * (e.g., during CLI/cron/early AJAX requests when Polylang isn't fully initialized).
 *
 * @return array Array of 2-letter language codes (e.g., ['en', 'ru', 'ar', 'zh'])
 */
public function get_active_languages_fallback(): array
{
    global $wpdb;
    // Query language terms directly, filtering by 2-character slugs to exclude pll_en style terms
    $langs = $wpdb->get_col("SELECT t.slug FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id WHERE tt.taxonomy = 'language' AND CHAR_LENGTH(t.slug) = 2");
    return !empty($langs) ? $langs : ['en'];
}
```

Then update the Polylang adapter to use this fallback:

```php
// In includes/core/translator/adapters/polylang.php

public function get_active_languages(): array
{
    if (function_exists('pll_languages_list')) {
        $langs = pll_languages_list(['fields' => 'slug']);
        if (!empty($langs)) {
            return $langs;
        }
    }
    // Fallback: use the translation service's database query method
    return Frl_Translation_Service::get_instance()->get_active_languages_fallback();
}
```

### Option 2: Add `permalinks` to Litespeed Inbound Hook

Ensure the `permalinks` cache is explicitly cleared when Litespeed purges:

```php
'litespeed_purged_all' => [
    'label' => 'LiteSpeed Cache',
    'clear' => ['light', 'permalinks'],
    'rewrite_flush' => true,
],
```

### Option 3: Add Guard to Prevent Exclusion Pattern Regeneration During CLI/Cron

In `compute_exclusion_patterns()`, add a guard that prevents regeneration during CLI/cron requests:

```php
public static function compute_exclusion_patterns(): array
{
    // Don't regenerate exclusion patterns during CLI/cron - use cached values
    if (PHP_SAPI === 'cli' || defined('DOING_CRON')) {
        $cached = frl_get_transient(self::EXCLUSION_PATTERNS_TRANSIENT);
        if ($cached !== false) {
            return $cached;
        }
    }
    // ... existing code ...
}
```

### Recommended: Option 1

Option 1 is the most robust fix because it addresses the root cause: the Polylang adapter returning empty languages during automatic updates. It ensures the exclusion patterns are always generated with the correct languages, regardless of Polylang's initialization state.
