# Fralenuvole Production Audit

**Date:** 2026-07-02  
**Scope:** Rewrite rule integrity with Polylang, hidden bugs, performance bottlenecks

---

## Rewriter/Polylang: No Rewrite Rule Corruption Possible

The rewriter cannot store corrupted rewrite rules for pages in other languages. Seven independent safeguards:

1. **Config-driven rule generation.** Rules built from `FRL_REWRITER_MULTILINGUAL_CPT` + option values.
2. **Config hash invalidation.** [`class-rewriter-coordinator.php:309-335`](core/rewriter/class-rewriter-coordinator.php:309).
3. **`clear_rewriter_caches()` on all `update_option_*` hooks.** [`class-rewriter.php:464-476`](core/rewriter/class-rewriter.php:464).
4. **Polylang hook integration.** [`class-rewriter.php:484-486`](core/rewriter/class-rewriter.php:484) — `pll_add_language`, `pll_update_language`, `pll_update_default_lang`.
5. **Duplicate pattern detection.** [`abstract-base-feature.php:242-283`](core/rewriter/features/abstract-base-feature.php:242).
6. **Subdomain adapter flush depth guard.** [`class-subdomain-adapter.php:81`](modules/subdomain_adapter/class-subdomain-adapter.php:81) — reference counter with `try/finally` prevents translated page IDs in `rewrite_rules`.
7. **`frl_flush_rewrite_rules()` mirrors `WP_Rewrite::set_permalink_structure()`.** [`plugin-lifecycle.php:184-218`](includes/plugin-lifecycle.php:184).

---

## Finding

### Missing `try/finally` in `process_string_registration_queue()`

**File:** [`core/translator/class-translation-service.php:639`](core/translator/class-translation-service.php:639)  
**Severity:** LOW

`pll_set_current_language($source_lang)` is called before a loop over queued strings. If an exception propagates from `register_translation()`, `PLL()->curlang` is not restored. The codebase uses `try/finally` for this pattern elsewhere ([`thirdparty.php:529-574`](modules/thirdparty/thirdparty.php:529)); this method is the outlier.

**Likelihood:** Low — `register_translation()` is a simple DB insert.

---

## Confirmed Correct

- **`frl_alter_query()`** — intentional performance optimization, configurable via `custom_wp_query`
- **`remove_all_filters()` in `frl_update_option()`/`frl_delete_option()`** — prevents same-request stale anonymous closures from prior writes; necessary because closures can't be referenced by name. Documented at [`functions-options.php:119-124`](includes/helpers/functions-options.php:119).
- **`remove_all_filters()` in `reset_options_caches()`** — redundant with the static cache reset that follows but harmless. EM writes via `update_option()`, not `pre_option_*` filters.
- **`late_rescue()` tax_query** — `build_query_vars()` sets `lang`; `parse_query()` re-adds correct Polylang filters
- **`sync_default_lang()`** — runtime filter is the primary mechanism; DB write is for edge cases
- **REST endpoint removals** — intentional feature toggles
