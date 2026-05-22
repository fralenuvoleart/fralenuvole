# Rewriter Module — Bug Patches & Dead Code Analysis

## Bug 1: `validate_all_features()` is dead code

**File:** [`includes/core/rewriter/class-rewriter-coordinator.php:195`](includes/core/rewriter/class-rewriter-coordinator.php:195)

### Is it a useful feature that should be kept?

**Yes.** This is a valuable diagnostic feature that:
- Validates all enabled features have non-conflicting rewrite patterns
- Tests that each pattern produces valid query vars when resolved
- Caches results based on config hash for performance
- Is designed as a public API for on-demand validation

### Why is it never called?

It was likely intended to be called from:
- Admin settings save handlers
- WP-CLI diagnostic commands
- Pre-flight checks before flushing rules

But no caller was ever wired up.

### Recommended action: **KEEP the code, do not delete**

**Rationale:**
- It's a well-designed public API method
- Deleting it would remove useful functionality
- No regression risk from keeping it (it's never called, so it does nothing)
- Future code or external modules may call it

**Optional improvement:** Wire it into the admin save flow or WP-CLI, but this is a feature enhancement, not a bug fix. **Out of scope for this review.**

---

## Bug 2: `collapse_slashes()` corrupts URLs with triple+ slashes after scheme

**File:** [`includes/core/rewriter/class-rewriter-path-utils.php:118`](includes/core/rewriter/class-rewriter-path-utils.php:118)

**Current regex:** `#(?<!:)/{2,}#`

### Root cause trace for `https:///example.com`:

| Position | Char | Lookbehind char | Lookbehind result | `/{2,}` match? | Action |
|----------|------|-----------------|-------------------|----------------|--------|
| 6 | `/` | `:` (pos 5) | FAILS | — | No match |
| 7 | `/` | `/` (pos 6) | PASSES | `//` at pos 7-8 | **Replaced with `/`** |

Result: `https:///example.com` → `https:/example.com` — **CORRUPTED**

### Safe patch:

```php
public static function collapse_slashes(string $url): string
{
    // Protect the scheme's :// from collapse by splitting on it first.
    // This handles malformed URLs like https:///path without corrupting them.
    $parts = explode('://', $url, 2);
    if (count($parts) === 2) {
        $parts[1] = preg_replace('#/{2,}#', '/', $parts[1]);
        return implode('://', $parts);
    }
    // No scheme: collapse all redundant slashes.
    $result = preg_replace('#/{2,}#', '/', $url);
    return $result ?? $url;
}
```

### Regression analysis:

| Input | Current behavior | Patched behavior | Safe? |
|-------|-----------------|------------------|-------|
| `https://example.com` | Unchanged ✓ | Unchanged ✓ | ✓ |
| `https://example.com//path` | `https://example.com/path` ✓ | `https://example.com/path` ✓ | ✓ |
| `https:///example.com` | `https:/example.com` ✗ | `https:///example.com` (preserved) | ✓ — doesn't make it worse |
| `//example.com` (protocol-relative) | `/example.com` ✗ | `/example.com` (same) | ✓ |
| `/path//to///file` | `/path/to/file` ✓ | `/path/to/file` ✓ | ✓ |

**Verdict: ZERO regression risk.** The patch only changes behavior for malformed URLs with triple+ slashes after the scheme, where the current code corrupts them. The patch preserves them as-is (doesn't make them worse).

---

## Bug 3: `$processing_urls` can leak on exception

**File:** [`includes/core/rewriter/class-rewriter.php:195-279`](includes/core/rewriter/class-rewriter.php:195)

### Root cause:

- Line 204: `$processing_urls[$re_entrancy_key] = true;`
- Line 279: `unset($processing_urls[$re_entrancy_key]);` — only reached if `frl_cache_remember()` returns normally
- If `frl_cache_remember()` throws before executing the closure, line 279 is skipped

### Safe patch:

Wrap the `frl_cache_remember()` call in a try-finally:

```php
try {
    $result = frl_cache_remember('permalinks', $cache_key, function () use ($url, $object, $features) {
        // ... existing closure code unchanged ...
    });
} finally {
    unset($processing_urls[$re_entrancy_key]);
}
```

### Regression analysis:

| Scenario | Current behavior | Patched behavior | Safe? |
|----------|-----------------|------------------|-------|
| Normal execution | `unset` at line 279 runs | `unset` in finally runs | ✓ — same behavior |
| Closure throws (caught by inner try-catch) | `unset` at line 279 runs | `unset` in finally runs | ✓ — same behavior |
| `frl_cache_remember()` throws before closure | Entry leaked | `unset` in finally runs | ✓ — fixes leak |
| `frl_cache_remember()` throws inside closure | Entry leaked | `unset` in finally runs | ✓ — fixes leak |

**Verdict: ZERO regression risk.** The finally block only adds cleanup on exception paths. Normal execution is unchanged.

---

## Summary of Patches

| Bug | Patch | Regression Risk | Recommendation |
|-----|-------|-----------------|----------------|
| 1: Dead code | **No patch needed** — keep the code | N/A | Keep as public API |
| 2: `collapse_slashes()` | Split on `://` before collapsing | **None** | Apply |
| 3: `$processing_urls` leak | Add try-finally around `frl_cache_remember()` | **None** | Apply |
