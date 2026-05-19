# Redirect Loop Fix — Using `pll_check_canonical_url` Filter

## The Problem

On `ru.pbservices.ge/novosti/`, a redirect loop occurs between two conflicting priorities:

| Priority | Hook | What it does |
|----------|------|-------------|
| **P4** | Polylang `check_canonical_url()` | Detects content language (RU), calls `switch_language_in_link()` which adds `/ru/` prefix → redirects to `/ru/novosti/` |
| **P6** | Legacy adapter `redirect_legacy_incoming_url()` | Sees `/ru/` prefix on `ru.pbservices.ge` → strips it → redirects to `/novosti/` |

The root cause: **Polylang's canonical checker doesn't know about subdomain mapping**. It operates in directory mode (`force_lang = 1`) and believes `/novosti/` without a prefix is the EN version. It adds `/ru/` because it detects the content is RU. But on the subdomain, clean URLs without prefix ARE correct for RU.

---

## Research Completed

### Requirement #1: Read Polylang source code ✓

Full source of `PLL_Canonical::check_canonical_url()` extracted from GitHub:

- **File**: `src/frontend/canonical.php` (Polylang plugin)
- **Hook**: `template_redirect` at priority 4
- **Key logic flow** (lines 53-147):
  1. Detect language from queried post/term/posts_page
  2. If `force_lang === 3` (domain), check domain matching
  3. If language found, call `$this->redirect_canonical()` then `$this->links_model->switch_language_in_link()`
  4. **Line 138**: `$redirect_url = apply_filters( 'pll_check_canonical_url', $redirect_url, $language );`
  5. If `$redirect_url === false` → cancel redirect (line 140-142)
  6. If `$redirect_url !== $requested_url` → perform 301 redirect (lines 143-147)

- **`redirect_canonical()` method** (lines 176-194): Temporarily sets `$this->curlang = $language` (hack for `page_for_posts`), calls WordPress's `redirect_canonical()`, restores.

- **`PLL_Frontend_Static_Pages::pll_check_canonical_url()`** (frontend-static-pages.php, line ~97): Hooked to the `pll_check_canonical_url` filter. Returns `false` when `redirect_lang && !force_lang && page_on_front`. This is Polylang's OWN usage of this filter to cancel redirects — exactly the same pattern we need.

### Requirement #2: Research all available Polylang filters ✓

Official Polylang filter reference (polylang.pro) lists **22 documented filters**. The `pll_check_canonical_url` filter is **not documented** — it's an internal API filter. However:

1. **It exists** in the source code at `canonical.php:138` (confirmed)
2. **Polylang itself uses it** in `frontend-static-pages.php` to prevent canonical redirects for static front pages
3. **Stack Overflow confirms working usage**: `add_filter('pll_check_canonical_url', '__return_false')` works (though globally — too broad)
4. The filter signature: `apply_filters( 'pll_check_canonical_url', $redirect_url, $language )`
   - Return `false` → **cancel the redirect**
   - Return a URL string → **redirect to that URL instead**
   - Return the same URL → **no redirect** (Polylang compares `$requested_url === $redirect_url`)

---

## Proposed Solution: `pll_check_canonical_url` Filter

### Why NOT `remove_action()`

Removing `check_canonical_url` entirely is a sledgehammer approach:
- Breaks Polylang's canonical URL enforcement for ALL content
- Could cause SEO issues (duplicate content, wrong canonical URLs)
- Removes functionality we don't understand the full implications of
- User called out: "superficial solution of 'lets just remove it', its not worthy"

### Why NOT priority changes

The loop is **cross-request** — P4 fires on one request (adds `/ru/`), P6 fires on the redirected request (strips `/ru/`). Priority changes within a single request can't fix an interaction across two requests. The only way to break the loop is to **prevent Polylang from issuing the redirect in the first place**.

### Why `pll_check_canonical_url` filter is the right solution

This filter is Polylang's OWN mechanism for conditionally canceling canonical redirects. It's used internally by `PLL_Frontend_Static_Pages` for exactly the same purpose (preventing a redirect on static front pages). The filter is **designed for this use case**.

| Aspect | Assessment |
|--------|-----------|
| **Scope** | Narrow — only fires during `check_canonical_url()` on `template_redirect` |
| **Control** | Per-request, per-language — can inspect the redirect URL and language |
| **Side effects** | Zero on main domain (gated behind `is_on_subdomain()`) |
| **Fail-safe** | Returns `$redirect_url` unchanged = Polylang's default behavior (no-op) |
| **Precedent** | Polylang itself uses this exact filter in `frontend-static-pages.php` |

### The Logic

On a mapped subdomain:
- If the content language matches the subdomain's language → redirect is WRONG (clean URL is already canonical on subdomain) → return `false` to cancel
- If the content language does NOT match → Polylang's redirect is legitimate (cross-language content on wrong subdomain, should redirect) → pass through
- On main domain → always pass through (no interference)

```php
public function filter_pll_check_canonical_url($redirect_url, $language) {
    // Only intervene on mapped subdomains.
    if (!$this->is_on_subdomain()) {
        return $redirect_url;  // Pass through on main domain.
    }
    
    // On subdomain: if the content's detected language matches the subdomain
    // language, the clean URL (without language prefix) is already canonical.
    // Cancel Polylang's redirect that would add the directory prefix back.
    if ($language->slug === $this->current_subdomain_lang) {
        return false;  // Cancel the redirect.
    }
    
    // Cross-language content on subdomain: Polylang's redirect is legitimate
    // (e.g., EN content on ru.pbservices.ge → redirect to pbservices.ge).
    return $redirect_url;
}
```

### Timing

The filter is `apply_filters()`'d inside `check_canonical_url()` at `template_redirect` priority 4. We need the filter registered BEFORE that fires.

Our `register_hooks()` runs during singleton construction, which is called from:
1. `Frl_Subdomain_Adapter::init()` — called from `register_hooks()` in the legacy adapter
2. Both modules are initialized at `plugins_loaded` priority 5

Since `plugins_loaded` runs well before `template_redirect`, a filter added in `register_hooks()` will be in place by the time P4 fires. **No timing issue.**

### Location

Add to [`modules/subdomain_adapter/class-subdomain-adapter.php`](modules/subdomain_adapter/class-subdomain-adapter.php:340) in `register_hooks()`:

```php
// --- Polylang canonical redirect filter ---
// Priority 10: cancels Polylang's canonical redirect on subdomains when the
// content language matches the subdomain language. Prevents the redirect loop
// where Polylang (P4) adds /ru/ prefix and legacy adapter (P6) strips it.
add_filter('pll_check_canonical_url', [$this, 'filter_pll_check_canonical_url'], 10, 2);
```

And add the callback method (e.g., somewhere in the filter methods section):

```php
/**
 * Filter: pll_check_canonical_url
 *
 * Prevents Polylang from issuing canonical redirects on mapped subdomains
 * when the content language matches the subdomain's language.
 *
 * On a subdomain like ru.pbservices.ge, Polylang operates in directory mode
 * (force_lang=1) and treats /novosti/ as the EN version. When it detects the
 * content is RU, it adds the /ru/ prefix via switch_language_in_link() and
 * issues a 301 redirect to ru.pbservices.ge/ru/novosti/. This conflicts with
 * the legacy adapter at P6 which strips the /ru/ prefix back, creating a loop.
 *
 * By returning false, we cancel Polylang's redirect when the content matches
 * the subdomain's language, because clean URLs are already canonical on the
 * subdomain. Cross-language content (e.g., EN on ru.pbservices.ge) still
 * gets redirected by Polylang as intended.
 *
 * @param  string|false $redirect_url The redirect URL proposed by Polylang.
 * @param  PLL_Language $language     The detected content language.
 * @return string|false
 */
public function filter_pll_check_canonical_url($redirect_url, $language) {
    if (!$this->is_on_subdomain()) {
        return $redirect_url;
    }
    
    if ($language->slug === $this->current_subdomain_lang) {
        return false;
    }
    
    return $redirect_url;
}
```

---

## Answer to: "Should Subdomain Adapter always be the last filter?"

This question was about changing all adapter filter priorities to run last (e.g., `PHP_INT_MAX`). Let's analyze where it matters and where it doesn't:

### Where `PHP_INT_MAX` is already used (correctly)
From [`register_hooks()`](modules/subdomain_adapter/class-subdomain-adapter.php:340):

| Hook | Priority | Rationale |
|------|----------|-----------|
| `post_link`, `post_type_link`, `page_link`, `term_link` | `PHP_INT_MAX` | Final authority on URL structure for SEO — no plugin should override subdomain URLs |
| `wpseo_canonical` | `PHP_INT_MAX` | Yoast canonical must get the subdomain-correct URL |
| `the_seo_framework_meta_render_data` | `PHP_INT_MAX` | Same for TSF |
| `the_content`, `render_block`, `wp_nav_menu_objects` (legacy) | `PHP_INT_MAX` | Content transformation is the last step |
| `redirect_legacy_incoming_url` (legacy) | **P6** | Must run BEFORE Polylang or WordPress sends a response, but AFTER Polylang's own redirects |

### Where lower priorities are intentional

| Hook | Priority | Why not `PHP_INT_MAX` |
|------|----------|----------------------|
| `pll_default_language` | **P1** | **Must run before Polylang's own setup.** If we run last, Polylang has already set default language internally — data structures are already built. This is the KEY mechanism. |
| `pll_current_language` | **P2** | Same reason — must run early in Polylang's language detection chain. |
| `pll_language_home_url` | **P20** | Runs after Polylang's own handler at p10 — correct priority ordering within Polylang's filter chain. |
| `home_url` | **P20** | WordPress core `home_url` filter — priority 20 is the module convention, well after core (p10) and any other plugin's default (p10). |
| `option_page_on_front` | **P20** | Runs before `template_redirect` where it's needed. No benefit from `PHP_INT_MAX`. |
| `template_redirect` (adapter) | **P5** | Must run BEFORE Polylang's `check_canonical_url` at P4? Wait — P5 runs AFTER P4. But `redirect_non_target_content` at P5 handles cross-language content redirects which should take precedence over Polylang's canonical redirect. Actually, let me re-examine... |

### The real priority issue: template_redirect P5 vs P4

The adapter's `redirect_non_target_content()` at P5 and the legacy adapter's `redirect_legacy_incoming_url()` at P6 both run AFTER Polylang's `check_canonical_url()` at P4. This means:

1. **The redirect loop exists because P4 fires first**: Polylang redirects `/novosti/` → `/ru/novosti/`, then P6 on the next request strips it back.
2. **If the adapter ran BEFORE Polylang (P3 or earlier)**, the redirect loop would not occur — the adapter would handle the redirect BEFORE Polylang's canonical check.
3. **But the adapter can't run before Polylang** because it depends on Polylang being loaded and configured.

**Conclusion**: There is no priority value that fixes this loop on a single request. The loop is fundamentally a cross-request conflict. The `pll_check_canonical_url` filter is the correct solution because it PREVENTS Polylang from issuing the wrong redirect in the first place — it's not about ordering, it's about data-aware decision making.

The existing priority design is correct:
- P1/P2: Pre-Polylang language setup (must run early)
- P5: template_redirect — cross-language redirect (adapter)
- P6: template_redirect — legacy URL redirect (legacy adapter)
- P20: URL filters (after rewriter at P10)
- `PHP_INT_MAX`: URL transformation (last resort)

This is a well-thought-out priority scheme. The redirect loop is a **gap in Polylang's canonical logic** (it doesn't understand subdomain mapping), not a priority-ordering problem.

---

## Summary of Changes

| File | Change | Purpose |
|------|--------|---------|
| [`class-subdomain-adapter.php`](modules/subdomain_adapter/class-subdomain-adapter.php) | Add `add_filter('pll_check_canonical_url', ...)` in `register_hooks()` | Register the filter early enough to intercept Polylang's P4 canonical check |
| [`class-subdomain-adapter.php`](modules/subdomain_adapter/class-subdomain-adapter.php) | Add `filter_pll_check_canonical_url()` method | The filter callback that cancels canonical redirects on subdomains when content matches subdomain language |
| None | No priority changes | Existing priority scheme is correct — this fix uses Polylang's own filter API |

## Risk Assessment

| Risk | Mitigation |
|------|-----------|
| **Legitimate canonical redirect suppressed** | Only on subdomains, and only when content language matches subdomain language. Cross-language content (e.g., EN on ru.pbservices.ge) still gets redirected by Polylang. |
| **SEO impact** | Positive — eliminates redirect chain (301 → 301 → 200 becomes 200 directly). Cleaner for search engines. |
| **Future Polylang changes** | If Polylang removes the `pll_check_canonical_url` filter, redirect loop returns (no worse than today). If Polylang changes the redirect logic, worst case: our filter is a no-op. |
| **Main domain regression** | Zero — `is_on_subdomain()` gates the entire callback, returns `$redirect_url` unchanged on main domain. |
| **Admin / REST / preview** | `template_redirect` doesn't fire in admin context. REST API and preview are gated by Polylang's own checks in `check_canonical_url()`. |
| **Static front page** | Polylang's own `PLL_Frontend_Static_Pages::pll_check_canonical_url()` hooks the same filter. Our filter and Polylang's filter both return `false` independently — no conflict. |
