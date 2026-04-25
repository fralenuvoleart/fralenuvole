# System Patterns (Fralenuvole 5.4.0)

## 🏗️ Core Architecture & Init Sequence
- **P5 (plugins_loaded):** Translation Interception.
- **P10 (init):** Environment Enforcement (Domain-based).
- **P15 (init):** Rewriter Registration (Post-environment).

## 🛡️ Critical Logic (No Regressions)
- **Environment:** `pre_option_*` filters used for domain overrides.
- **Cache:** 5-backend unified interface; check `FRL_CACHE_DEPENDENCIES`.
- **Options:** 3-tier cascade (Static → Persistent → DB).
- **Race Conditions:** Use `frl_cache_remember` with lock-based prevention.
- **MU Plugin Caching:** `frl_cache_remember` is safe inside `pre_option_*` and `pre_site_option_*` filters because it uses object cache/transients (never `get_option()`/`get_site_option()`). The `should_bypass()` check only calls `get_option('frl_disable_plugin')` — a completely unrelated option. Cache invalidation via `activated_plugin`/`deactivated_plugin` hooks.
- **Cache Group Selection:** Data originating from `wp_options`/`wp_sitemeta` uses the `options` cache group. Use custom TTL (`WEEK_IN_SECONDS`) for stable configuration data that changes on a different cadence than typical plugin options.

## 🛠️ Developer Working Method
- **Standard:** Modular, Elegant, SEO-performant.
- **Reporting:** Use specific file/line references.
- **Verification:** Double-verify for regressions; No "Opinion as Fact."
- **Integrity:** Failing to follow = **LYING/GASLIGHTING**.

## 🔒 Rule Integrity Notice
The mandatory-rules.md in `memory-bank/` is the source of truth.

---
*Last Updated: 2026-04-25*
