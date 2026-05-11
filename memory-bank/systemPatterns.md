# System Patterns (Fralenuvole 5.4.0)

## 🏗️ Core Architecture & Init Sequence
- **P5 (plugins_loaded):** Translation Interception + Core components (rewriter coordinator created, built-in features instantiated).
- **P5 (plugins_loaded):** `frl_modules_init()` — Module entry points execute (can call `$coordinator->add_feature()`).
- **P7 (plugins_loaded):** `do_action('frl_rewriter_register_features')` + `usort()` — Module-loaded features included in priority sort.
- **P10 (init):** Environment Enforcement (Domain-based).
- **P15 (init):** Rewriter Registration (feature->register() for all features, including module-added).

## 🛡️ Critical Logic (No Regressions)
- **Environment:** `pre_option_*` filters used for domain overrides.
- **Cache:** 5-backend unified interface; check `FRL_CACHE_DEPENDENCIES`.
- **Options:** 3-tier cascade (Static → Persistent → DB).
- **Race Conditions:** Use `frl_cache_remember` with lock-based prevention.
- **MU Plugin Caching:** `frl_cache_remember` is safe inside `pre_option_*` and `pre_site_option_*` filters because it uses object cache/transients (never `get_option()`/`get_site_option()`). The `should_bypass()` check only calls `get_option('frl_disable_plugin')` — a completely unrelated option. Cache invalidation via `activated_plugin`/`deactivated_plugin` hooks.
- **Access Control TTL Consistency:** Both [`frl_has_access()`](includes/helpers/functions-access-control.php:95) standard path and [`frl_get_auth_cookie_user_data()`](includes/helpers/functions-mu-plugin.php:135) DB query use **300s TTL** — one consistent rule for all access-control caching. Early-loading access checks (before `plugins_loaded`) are handled by [`frl_mu_check_access()`](includes/helpers/functions-mu-plugin.php:162), not `frl_has_access()`. All MU-plugin-specific early-loading functions are co-located in [`functions-mu-plugin.php`](includes/helpers/functions-mu-plugin.php).
- **Cache Operations:** `Frl_Cache_Operations` (`includes/core/cache/class-cache-operations.php`) dispatches multi-step cache operations defined in `FRL_CACHE_OPERATIONS` constant (`config/config-cache-operations.php`, loaded via `config/config.php`). Two-tier design:
  - **`clear_*` tier** (`clear_hard`, `clear_all`, `clear_light`): Helper-level operations that `frl_cache_clear()` delegates to for composite cache groups. Each step documented with inline `note` fields.
  - **`action_*` tier** (`action_hard`, `action_flush_rewrite_rules`, `action_clear_plugin_transients`, `action_clear_website_transients`, `action_clear_scripts_tags`): Admin-action-level operations called from action handlers.
  - **Sequential execution, no abort:** All steps run regardless of intermediate failures. Caller inspects per-step results.
  - **`fn` supports callable arrays:** `[ 'Frl_Cache_Manager', 'hard_cache_reset' ]` validated via `is_callable()`.
  - **Preserved backwards compatibility:** All existing helper functions (`frl_cache_clear`, `frl_flush_rewrite_rules`) remain independently callable. The `FRL_CACHE_OPERATIONS` constant is the single source of truth for multi-step execution order.
- **Cache Group Selection:** Data originating from `wp_options`/`wp_sitemeta` uses the `options` cache group. Use custom TTL (`WEEK_IN_SECONDS`) for stable configuration data that changes on a different cadence than typical plugin options.

## 🛠️ Developer Working Method
- **Standard:** Modular, Elegant, SEO-performant.
- **Reporting:** Use specific file/line references.
- **Verification:** Double-verify for regressions; No "Opinion as Fact."
- **Integrity:** Failing to follow = **LYING/GASLIGHTING**.

## 🔒 Rule Integrity Notice
The mandatory-rules.md in `memory-bank/` is the source of truth.

---
*Last Updated: 2026-04-26*
