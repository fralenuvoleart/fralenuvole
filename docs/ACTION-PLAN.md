# Fralenuvole Action Plan

This document tracks all identified issues, their priority, and recommended actions.

---

## Tier 0: Critical (Fix Immediately)

### 0.1 Hook Priority Documentation

**File:** `docs/HOOKS.md`

**Problem:** No central documentation of critical hook priorities. Adding code at wrong priority causes environment/rewriter conflicts.

**Action:** See `docs/HOOKS.md` for complete hook priority matrix.

**Status:** Created

---

### 0.2 Schema.org Output Validation

**File:** `public/schema.php`

**Problem:** Schema templates loaded from JSON files with minimal validation. Malformed JSON could break structured data and cause Google Search Console errors.

**Current Code (lines 89-102):**
```php
$raw_schema = strtr($schema_templates[$schema_type], $replacements);
$decoded = json_decode($raw_schema);
// Only checks json_last_error()
```

**Recommended Action:**
```php
$decoded = json_decode($raw_schema, JSON_THROW_ON_ERROR);
$schema = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
// Add: Validate against Schema.org vocabulary
// Add: Check required fields for each schema type
```

**Impact:** SEO - prevents rich snippet errors

---

## Tier 1: High Priority (This Sprint)

### 1.1 Rewrite Rule Correctness Validation

**File:** `includes/rewriter/class-rewriter-coordinator.php`

**Problem:** `validate_all_features()` only checks pattern conflicts, not semantic correctness.

**Current (lines 152-175):**
```php
public function validate_all_features(): bool {
    foreach ($this->features as $feature) {
        $feature->validate_patterns(array_keys($all_patterns));
        // Only checks "pattern A != pattern B"
        // Does NOT check "does pattern A actually route correctly?"
    }
}
```

**Recommended Action:** Add integration test that verifies URL routing:
```php
/**
 * Test: After flush, make HEAD request to each generated URL pattern
 * Expected: Returns 200 or 301 (not 404)
 * If 404: Feature generated invalid rewrite rule
 */
```

**Impact:** Reliability - prevents 404 errors from misconfigured rules

---

### 1.2 Shortcode Cache Busting

**File:** `public/shortcodes.php`, `config/config-cache.php`

**Problem:** Shortcodes cached with `DAY_IN_SECONDS` TTL. If content changes, old shortcodes persist.

**Current (`config/config-cache.php`):**
```php
'shortcodes' => DAY_IN_SECONDS,
```

**Recommended Action:** Add cache-busting on post update:
```php
// In post update hook, clear shortcode cache for that post
add_action('save_post', function($post_id) {
    frl_cache_clear('shortcodes', "meta_{$post_id}_*");
});
```

**Impact:** Content accuracy - prevents stale content display

---

### 1.3 Font-display: swap

**File:** `includes/themekit/`

**Problem:** Even with preload, fallback fonts can cause layout shift (CLS).

**Current:** Generated `@font-face` rules may not have explicit `font-display`.

**Recommended Action:** Add `font-display: swap` explicitly:
```css
@font-face {
    font-family: 'Fallback';
    src: url('/wp-content/fonts/fallback.woff2') format('woff2');
    font-display: swap;  /* Ensures text visible during load */
}
```

**Impact:** Core Web Vitals - improves CLS score

---

## Tier 2: Medium Priority (Next Sprint)

### 2.1 Split includes/main.php

**Files:** `includes/main.php`

**Problem:** 733 lines doing 10+ different things. Hard to debug, maintain, and test.

**Proposed Structure:**
```
includes/
├── main.php                    (core hooks + glue)
└── common/
    ├── website-features.php   (comments, embeds, emoji)
    ├── performance.php        (critical CSS, preloads, fonts)
    ├── media.php              (image sizes, avatars)
    └── navigation.php         (navigation translation)
```

**What Stays in main.php:**
- `frl_main_init()` - main initialization
- `frl_register_icon_block()` - icon block
- `frl_extend_admin_cookie()` - cookie extension
- `frl_add_image_sizes()` + `frl_add_image_size_names_choice()` - image sizes
- `frl_process_deferred_writes()` - deferred cache writes
- All `add_action()` and `add_filter()` calls that register hooks

**What Moves:**
| Function | Destination |
|----------|-------------|
| `frl_disable_comments()` | `website-features.php` |
| `frl_disable_embeds()` | `website-features.php` |
| `frl_disable_emojis()` | `website-features.php` |
| `frl_add_critical_css()` | `performance.php` |
| Avatar functions | `media.php` |
| `frl_render_block_core_navigation_translation()` | `navigation.php` |

**Why This Structure:**
- Grouped by domain concern (website vs performance) rather than individual features
- Easier to find related code
- Fewer files to navigate

**Impact:** Maintainability, testability, onboarding

---

### 2.2 Rewrite Flush Backoff

**File:** `includes/rewriter/class-rewriter.php`

**Problem:** If flush fails repeatedly, no backoff. Every 60s another attempt.

**Current:**
```php
if (get_option('rewrite_rules') === false) {
    frl_set_transient('rewrite_flush_cooldown', true, 60);
    frl_flush_force_rewrite_rules();  // Retry
}
```

**Recommended Action:** Add exponential backoff with max retries:
```php
$retry_count = frl_get_transient('rewrite_flush_retry_count') ?: 0;
if ($retry_count > 5) {
    frl_log('Rewrite flush failed after 5 attempts', [...]);
    return;  // Stop retrying
}
frl_set_transient('rewrite_flush_retry_count', $retry_count + 1, HOUR_IN_SECONDS);
```

**Impact:** Reliability - prevents log flooding

---

### 2.3 Critical CSS Dependency Documentation

**File:** `includes/main.php`

**Problem:** Critical CSS injected at `wp_head` priority -999. No documentation of dependency on theme CSS loading.

**Current:**
```php
add_action('wp_head', 'frl_add_critical_css', -999, 1);
```

**Recommended Action:** Add documentation comment:
```php
/**
 * Critical CSS must load BEFORE theme CSS.
 * If theme uses wp_head without priority (default 10), 
 * critical CSS at -999 will load first. OK.
 * If theme uses wp_head with negative priority, conflict.
 */
add_action('wp_head', 'frl_add_critical_css', -999, 1);
```

**Impact:** Prevents CLS (Cumulative Layout Shift)

---

## Tier 3: Backlog

### 3.1 Feature Flags System

**Problem:** Binary on/off only. No A/B testing, no gradual rollout.

**Recommended Action:** Add percentage-based feature flags:
```php
function frl_is_feature_enabled($feature, $user_id = null) {
    $option = frl_get_option("feature_{$feature}_enabled");
    if ($option !== 'percentage') {
        return $option === '1';
    }
    $percentage = frl_get_option("feature_{$feature}_percentage");
    $bucket = crc32($user_id ?: wp_get_current_user()->ID) % 100;
    return $bucket < $percentage;
}
```

**Impact:** Flexibility, safe rollouts

---

### 3.2 WPML Support for Navigation Translation

**Problem:** `frl_making_wp_navigation_translatable()` only supports Polylang.

**Current:**
```php
add_filter('pll_get_post_types', 'frl_making_wp_navigation_translatable', 10, 2);
```

**Recommended Action:** Add WPML compatibility:
```php
if (function_exists('pll_get_post_types')) {
    add_filter('pll_get_post_types', ...);
} elseif (defined('ICL_PLUGIN_PATH')) {
    // WPML equivalent using icl_get_post_types or similar
}
```

**Impact:** Internationalization completeness

---

## What NOT to Refactor

These systems are complex for GOOD reasons:

| System | Why Complexity is Justified |
|--------|---------------------------|
| Cache Manager | 5 different backends with functional detection |
| Options System | Stale WP alloptions handling prevents infinite loops |
| Environment Config | Config inheritance across multiple deployments |
| Rewriter Features | Priority-based, independent, testable architecture |

---

## Quantified Impact Summary

| Action | Impact | Effort | Priority |
|--------|--------|--------|----------|
| Hook priority documentation | Prevents bugs | 1 day | P0 |
| Schema.org JSON validation | SEO fixes | 1 day | P0 |
| Shortcode cache busting | Content accuracy | 2 hours | P1 |
| Rewrite rule correctness | Reliability | 2 days | P1 |
| Font-display: swap | Core Web Vitals | 1 hour | P2 |
| Rewrite flush backoff | Reliability | 2 hours | P2 |
| Split main.php | Maintainability | 1 week | P3 |
| Feature flags | Flexibility | 1 week | P3 |

---

## Immediate Next Steps

1. **Create `docs/HOOKS.md`** ✅ Done
2. **Add schema.org JSON validation** - 4 hours
3. **Document critical CSS dependency** - 1 hour
4. **Start main.php split** - plan structure first

---

*Last Updated: 2026-04-11*
*Analysis Version: 5.3.0*
