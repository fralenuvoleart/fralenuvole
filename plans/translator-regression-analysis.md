# Translator Refactor: Regression & Bug Analysis

## Executive Summary

A thorough review of the refactored translator subsystem reveals **7 items**, ranging from the reported bug to deeper architectural regressions. The primary reported error (`Undefined array key ""` in `pbs.php:37`) is a **symptom** of a broader regression: the refactored [`get_language()`](includes/core/translator/class-translation-service.php:115) method no longer guarantees a non-empty string return.

---

## 🔴 Issue 1 (Reported): `PBS_JS_REMOVE_HTML_STRINGS[""]` — Undefined Array Key

**File:** [`modules/pbs/pbs.php:37`](modules/pbs/pbs.php:37)
**Error:** `Undefined array key ""`

### Call Chain

```
admin-ajax.php request (init hook)
  → frl_pbs_load_public_scripts()             [pbs.php:29]
    → frl_get_language()                       [functions-translation-helpers.php:46]
      → Frl_Translation_Service::get_language() [class-translation-service.php:115]
        → Frl_Polylang_Adapter::get_current_language() [polylang.php:14]
          → pll_current_language() → returns false (AJAX context)
```

### Root Cause

1. On [`admin-ajax.php`](https://staging.pbservices.ge/wp-admin/admin-ajax.php), Polylang's [`pll_current_language()`](https://polylang.pro/doc/pll_current_language/) returns `false` — it cannot determine a language from the AJAX request URL.

2. The [Polylang adapter](includes/core/translator/adapters/polylang.php:14) passes this through:
   ```php
   return function_exists('pll_current_language') ? pll_current_language() : 'en';
   ```
   Since `pll_current_language()` exists (Polylang is active), it returns whatever Polylang returns — which is `false`.

3. The return type is `: string` but there is **no `declare(strict_types=1)`** anywhere in the chain. PHP coerces `false` → `""` (empty string).

4. Back in [`get_language()`](includes/core/translator/class-translation-service.php:115):
   ```php
   $language = $this->adapter->get_current_language(); // false → ""
   
   global $wp_query;
   if (isset($wp_query->query['lang']) && ...) {
       $language = $wp_query->query['lang']; // not set on admin-ajax
   }
   
   $this->language_cache = $language; // cached as ""
   return $language; // returns ""
   ```

5. [`PBS_JS_REMOVE_HTML_STRINGS[""]`](modules/pbs/config-constants-pbs.php:19) — no `""` key exists → **warning**.

### Why This Is a Refactor Regression

**Before the refactor:** The old code likely had a guard that returned `'en'` when no language was detected. The refactored code relied on the adapter contract alone, but the Polylang adapter's fallback (`: 'en'`) only triggers when `pll_current_language()` doesn't exist — not when it returns `false`.

---

## 🔴 Issue 2: `frl_get_language()` Return Type Inconsistency

**File:** [`includes/helpers/functions-translation-helpers.php:46`](includes/helpers/functions-translation-helpers.php:46)

```php
function frl_get_language(?int $id = null, string $type = 'post'): string
{
    if (!frl_translator_is_enabled()) {
        return 'en';               // ✅ Guarded
    }
    if ($id === null) {
        return Frl_Translation_Service::get_instance()->get_language();
        // ^^^ Can return "" (empty string) — UNGUARDED
    }
    return Frl_Translation_Service::get_instance()->get_object_language($id, $type);
}
```

### Problem

The function guarantees `: string` in its signature, but can return `""` via the service path. All callers that use the return value as an array key (like [`pbs.php:37`](modules/pbs/pbs.php:37), [`field-translator.php:134`](includes/core/translator/field-translator.php:134)) will silently break.

### All Affected Callers of `frl_get_language()`

| Location | Usage | Risk |
|----------|-------|------|
| [`modules/pbs/pbs.php:37`](modules/pbs/pbs.php:37) | Array key | 🔴 CRASH |
| [`includes/core/translator/field-translator.php:134`](includes/core/translator/field-translator.php:134) | Cache key component | 🟡 Cache pollution |
| [`includes/core/translator/class-translation-service.php:168`](includes/core/translator/class-translation-service.php:168) | Language for translation | 🟡 Wrong translation |
| [`includes/core/translator/class-translation-service.php:348`](includes/core/translator/class-translation-service.php:348) | Comparison against default | 🟡 Logic error |
| [`includes/core/translator/class-translation-service.php:520`](includes/core/translator/class-translation-service.php:520) | Registration logic | 🟡 Missed registration |

---

## 🟢 Issue 3: `frl_get_translation_block()` Guard — Design Review

**File:** [`includes/helpers/functions-translation-helpers.php:105-138`](includes/helpers/functions-translation-helpers.php:105)

**Original analysis flagged this as 🔴 "inconsistent guard logic"** — but after hearing your rationale, this is **correct by design**.

### The Three-Tier Architecture

The function uses a **three-tier guard** instead of `frl_translator_is_enabled()`:

```php
function frl_get_translation_block(string $block_content, array $block): string
{
    // Tier 1: Fully disabled — zero overhead
    if ( frl_get_option('disable_translator') ) {
        return $block_content;
    }
    // Tier 2: Polylang off but not disabled — Safe Mode (strip delimiters)
    elseif ( !frl_is_multilingual_active() ) {
        // Lightweight regex to strip {{...}} and ##...## without booting the service
        ...
    }
    // Tier 3: Full translation processing
    return Frl_Translation_Service::get_instance()->get_translation_block(...);
}
```

| Scenario | Guard Hit | Behavior |
|----------|-----------|----------|
| Single-language site, `disable_translator`=on | `frl_get_option('disable_translator')` → `true` | Returns block unchanged — **zero overhead** ✅ |
| Multilingual site, Polylang temporarily off | `!frl_is_multilingual_active()` → `true` | **Safe Mode** — strips delimiters, caches result. Site stays **usable** without raw `{{}}` tokens visible ✅ |
| Multilingual site, Polylang active | Falls through to service | Full translation processing ✅ |

### Why This Is Correct

The other helpers (`frl_get_translation()`, `frl_get_language()` etc.) use `frl_translator_is_enabled()` which is `frl_is_multilingual_active() && !frl_get_option('disable_translator')`. That's appropriate for **single strings** — there's no "Safe Mode" concept there.

But **blocks** have delimiters (`{{text}}`, `##slug##`) baked into their content. If Polylang is deactivated mid-operation:
- Without Safe Mode: users would see raw `{{Some Text}}` on the frontend — **bad UX**
- With Safe Mode: delimiters are stripped, content is readable — **good UX**

### Verdict: ✅ Design is sound. Only a documentation comment is missing.

Add an inline comment (e.g., at line 107) explaining the three-tier rationale so a future developer doesn't "fix" it back to `frl_translator_is_enabled()` and break Safe Mode.

**Severity demoted: 🔴 High → 🟢 Documentation gap only**

---

## 🟢 Issue 4: `frl_is_valid_frontend_page_request()` — Design Review

**Files:**
- Guard: [`functions-access-control.php:370`](includes/helpers/functions-access-control.php:370)
- Usage: [`field-translator.php:598`](includes/core/translator/field-translator.php:598) (`frl_translator_should_skip_translation`)
- Usage: [`field-translator.php:129`](includes/core/translator/field-translator.php:129) (`frl_translator_pre_option`)

**Original analysis flagged this as 🟡 "defense-in-depth gap"** — re-examining, this is **correctly designed**.

### How the Guard Resolves

```
frl_is_valid_frontend_page_request()
  → !frl_is_admin()          [Is this NOT an admin request?]
  AND
  → frl_is_valid_page_request()
      → !frl_is_cli_request()
      → !frl_is_rest_api_request()
      → !frl_is_cron_job_request()
      → !frl_is_heartbeat_ajax()
      → !frl_is_log_manager_ajax()
      → !frl_is_html_document_request()  [skips 404, attachments]
      → frl_is_administrator_action()     [allows admin-post.php for admins]
      → frl_is_doing_ajax() → false       [blocks generic AJAX]
```

The AJAX resolution inside `frl_is_admin()`:

```php
$is_ajax = frl_is_doing_ajax();
if ($is_ajax) {
    $referer = wp_get_referer();
    if (!empty($referer) && str_contains($referer, '/wp-admin/')) {
        return true;  // Admin AJAX (referer from /wp-admin/) → IS admin → guard blocks
    }
    $action = $_REQUEST['action'] ?? '';
    if (str_starts_with($action, 'admin_') || str_starts_with($action, 'settings_')) {
        return true;  // Admin-prefixed action → IS admin → guard blocks
    }
    return false;  // Frontend AJAX (e.g., form submission) → NOT admin
}
```

### Request Type Matrix

| Request Type | `frl_is_admin()` | `frl_is_valid_frontend_page_request()` | Meta translation runs? |
|---|---|---|---|
| Frontend page load | `false` | `true` | ✅ Yes |
| Frontend AJAX form submit | `false` | `false` (blocked by `frl_is_valid_page_request()` → line 357) | ❌ No |
| Admin AJAX with `/wp-admin/` referer | `true` | `false` | ❌ No |
| Admin page load | `true` | `false` | ❌ No |

### Why Frontend AJAX Is Correctly Excluded

Meta translation is a **read-time filter** — it applies when meta values are displayed on a rendered page. AJAX form handlers typically read raw data (e.g., saving a form field), not displayed content. Translating on AJAX would:
1. Waste cache space with one-off request results
2. Risk double-translating content that's already stored translated

### What I Got Wrong Originally

I wrote: *"The shallow callers (like pbs.php, wsform.php) bypass this guard"* — but that's **not a bug**. `frl_get_language()` is a general-purpose function meant to be used in any context. The fix for `pbs.php` shouldn't be to add this guard (which would be semantically wrong — `pbs.php` is on `init`, not meta filtering). The fix belongs in `get_language()` itself (Issue 1).

### Verdict: ✅ The guard is correctly designed and properly layered.

**Severity demoted: 🟡 Medium → 🟢 Not an issue**

---

## 🟡 Issue 5: `register_translation()` Has a Logic Inconsistency

**File:** [`includes/core/translator/class-translation-service.php:512`](includes/core/translator/class-translation-service.php:512)

```php
public function register_translation(string $string): void
{
    if (empty($string) || !$this->is_multilingual('icl_register_string')) {
        return;
    }

    $current_language = $this->get_language();   // Could be ""
    $default_language = $this->get_default_language();  // Likely 'en'

    if ($current_language !== $default_language) {   // "" !== 'en' → TRUE
        $translation = $this->adapter->translate_string($string, $current_language);
        // ^^^ Called with language = "" — Polylang will likely return null or garbage
        if ($translation !== null) {
            return;  // Early return if "translation found"
        }
    }
    // ... proceeds to register
}
```

### Problem

If `get_language()` returns `""`:
1. The guard `$current_language !== $default_language` is `true` (`"" !== "en"`)
2. `translate_string($string, "")` is called with an empty language
3. Polylang may return unexpected results or null
4. If null, the code proceeds to register the string with a potentially wrong language context

### Verdict: 🟡 Needs fix — add `empty($current_language)` guard before the comparison.

---

## 🟢 Issue 6: Adapter Interface Contract Lacks Non-Empty Specification

**File:** [`includes/core/translator/adapters/interface.php:17`](includes/core/translator/adapters/interface.php:17)

The interface says `@return string` but doesn't specify non-empty guarantee. A future WPML adapter could have the same issue.

### Verdict: 🟢 Documentation gap — update the docblock to specify non-empty return.

---

## 🟢 Issue 7: `get_translation_batch_strings()` Sorting — Design Review

**File:** [`includes/core/translator/class-translation-service.php:339-381`](includes/core/translator/class-translation-service.php:339)

**Original analysis flagged this as 🟢 "unnecessary sorting"** — after deeper analysis, the sorting **IS intentional** and serves a cache key normalization purpose.

### Why `sort()` Exists

```php
public function get_translation_batch_strings(array $strings): array
{
    static $request_cache = [];

    $language = $this->get_language();
    if ($language === $this->get_default_language()) {
        return array_combine($strings, $strings);
    }

    $sorted_strings = $strings;
    sort($sorted_strings);  // Cache key normalization: order-independent key
    $batch_key = $language . '|' . implode('|', $sorted_strings);
```

**Purpose:** `$request_cache` is a **static request-level cache**. Consider two calls within the same request:

```php
// Call 1: from block A processing
$result = $service->get_translation_batch_strings(['hello', 'world', 'foo']);

// Call 2: from block B processing (same strings, different order)
$result = $service->get_translation_batch_strings(['foo', 'hello', 'world']);
```

**Without sorting:**
- Batch key 1: `en|hello|world|foo`
- Batch key 2: `en|foo|hello|world`
- **Cache miss** — Polylang queried twice for identical strings

**With sorting:**
- Batch key 1: `en|foo|hello|world`
- Batch key 2: `en|foo|hello|world` (identical!)
- **Cache hit** — Polylang queried once

### Is This Optimization Meaningful in Practice?

Currently, `get_translation_batch_strings()` is called from exactly one place: [`_process_all_patterns()`](includes/core/translator/class-translation-service.php:707):

```php
$translated_strings = !empty($strings_to_translate) 
    ? $this->get_translation_batch_strings(array_unique($strings_to_translate)) 
    : [];
```

Since `array_unique()` preserves the order of first occurrence, strings will be in the order they appeared in the block — which is **deterministic per-block**. So within a single request, the same block produces the same order, making `sort()` technically redundant for the current call path.

BUT — if another feature starts calling `get_translation_batch_strings()` directly with different orderings, the sort becomes valuable. It's **defensive programming** against future usage patterns.

### Risk of Removing `sort()`

If removed:
1. ✅ Current behavior: unchanged (single caller, deterministic order via `array_unique`)
2. ⚠️ Future risk: multiple callers with different orderings would cause cache misses for equivalent string sets
3. 💥 **No functional breakage** — just potential performance regression from cache misses

### Verdict: ✅ Keep `sort()` as-is. It's valid cache key normalization, not a bug.

**Severity demoted: 🟢 Low → 🟢 Trivial / No action needed**

---

## 📋 Final Summary of Findings

| # | Sev | Issue | Verdict | Action |
|---|-----|-------|---------|--------|
| 1 | 🔴 Critical | `get_language()` returns `""` on AJAX — no fallback for `false` from adapter | **Confirmed regression** | Add `empty()` fallback |
| 2 | 🔴 Critical | `frl_get_language()` passes `""` through without normalization | **Confirmed regression** | Add `empty()` normalization |
| 3 | 🟢 Low | `frl_get_translation_block()` inconsistent guard | **Design is intentional** — three-tier Safe Mode architecture. Add documentation comment only | Comment only |
| 4 | 🟢 Low | `frl_is_valid_frontend_page_request()` defense gap | **Not a real gap** — guards are correctly layered per-context | No action |
| 5 | 🟡 Medium | `register_translation()` may call `translate_string()` with `""` | **Confirmed** — secondary effect of Issue 1 | Add `empty()` check |
| 6 | 🟢 Low | Adapter interface lacks non-empty contract | Documentation gap | Update docblock |
| 7 | 🟢 N/A | `get_translation_batch_strings()` sorting | **Intentional** — cache key normalization | No action |

---

## Recommended Fix Plan

### Fix 1 — [`class-translation-service.php:115`](includes/core/translator/class-translation-service.php:115)

After the `$wp_query` check in `get_language()`, add an empty fallback:
```php
if (empty($language)) {
    $language = 'en';
}
```

### Fix 2 — [`functions-translation-helpers.php:46`](includes/helpers/functions-translation-helpers.php:46)

Normalize the service return value in `frl_get_language()`:
```php
$language = Frl_Translation_Service::get_instance()->get_language();
return !empty($language) ? $language : 'en';
```

### Fix 3 — [`functions-translation-helpers.php:105`](includes/helpers/functions-translation-helpers.php:105)

Add a documentation comment explaining the three-tier guard rationale in `frl_get_translation_block()`.

### Fix 4 — [`class-translation-service.php:512-526`](includes/core/translator/class-translation-service.php:512)

Add `empty($current_language)` guard in `register_translation()`:
```php
$current_language = $this->get_language();
if (empty($current_language)) {
    return;
}
```
