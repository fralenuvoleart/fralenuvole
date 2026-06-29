# System Patterns (Fralenuvole 5.8.0)

## 🏗️ Core Architecture & Init Sequence
- **P5 (plugins_loaded):** Translation Interception + Core components (rewriter coordinator created, built-in features instantiated). Adapter interface and Polylang adapter loaded early in `translator.php` — available regardless of service singleton instantiation.
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
- **Access Control TTL Consistency:** Both [`frl_has_access()`](includes/helpers/functions-access-control.php:95) standard path and [`frl_get_auth_cookie_user_data()`](includes/mu/functions-mu.php:135) DB query use **300s TTL** — one consistent rule for all access-control caching. Early-loading access checks (before `plugins_loaded`) are handled by [`frl_mu_check_access()`](includes/mu/functions-mu.php:162), not `frl_has_access()`. All MU-plugin-specific early-loading functions are co-located in [`functions-mu.php`](includes/mu/functions-mu.php).
- **Cache Operations:** `Frl_Cache_Operations` (`core/cache/class-cache-operations.php`) dispatches multi-step cache operations defined in `FRL_CACHE_OPERATIONS` constant (`config/config-cache-operations.php`, loaded via `config/config.php`). Two-tier design:
  - **`clear_*` tier** (`clear_hard`, `clear_all`, `clear_light`): Helper-level operations that `frl_cache_clear()` delegates to for composite cache groups. Each step documented with inline `note` fields.
  - **`action_*` tier** (`action_hard`, `action_flush_rewrite_rules`, `action_clear_plugin_transients`, `action_clear_website_transients`, `action_clear_scripts_tags`): Admin-action-level operations called from action handlers.
  - **Sequential execution, no abort:** All steps run regardless of intermediate failures. Caller inspects per-step results.
  - **`fn` supports callable arrays:** `[ 'Frl_Cache_Manager', 'hard_cache_reset' ]` validated via `is_callable()`.
  - **Preserved backwards compatibility:** All existing helper functions (`frl_cache_clear`, `frl_flush_rewrite_rules`) remain independently callable. The `FRL_CACHE_OPERATIONS` constant is the single source of truth for multi-step execution order.
  - **Cache Group Selection:** Data originating from `wp_options`/`wp_sitemeta` uses the `options` cache group. Use custom TTL (`WEEK_IN_SECONDS`) for stable configuration data that changes on a different cadence than typical plugin options.
  
  ## 🌐 Translation Fallback Architecture
  - **Adapter self-contained fallbacks:** `Frl_Polylang_Adapter` encapsulates its own fallback logic — private methods `get_default_language_internal()` and `get_active_languages_internal()` read Polylang's DB options directly when Polylang's API is unavailable.
  - **Global helpers delegate to adapter:** `frl_get_default_language_fallback()` and `frl_get_active_languages_fallback()` check `class_exists('Frl_Polylang_Adapter')` and instantiate the adapter directly if available.
  - **Constant fallback:** `FRL_TRANSLATOR_DEFAULT_LANG` (default: `'en'`) is the ultimate fallback when no adapter class exists (Polylang not installed).
  - **Source language is separate:** `FRL_TRANSLATOR_SOURCE_LANG` (default: `'en'`) is the language content is authored in — semantically different from the default language and remains constant even when Polylang's default changes on subdomains.
  - **Adapter loading:** Adapter interface and implementation loaded early in `translator.php` (module entry point), ensuring `class_exists` checks work regardless of whether `Frl_Translation_Service` singleton is instantiated.
  - **Subdomain Adapter default_lang sync:** On first visit to a mapped subdomain, the adapter delegates to the translation adapter ([`Frl_Translation_Adapter_Interface::set_default_language()`](core/translator/adapters/interface.php:108)) via the [`frl_environment_before_wp_options`](core/environment/class-environment-applier.php:93) action at `init/10`. Polylang-specific logic (read-modify-write on `polylang` option, cache flushing) lives in [`Frl_Polylang_Adapter`](core/translator/adapters/polylang.php:143). The subdomain adapter then clears all fralenuvole caches (`frl_cache_clear('all')`) and flushes rewrite rules (`frl_flush_rewrite_rules()`). Polylang's language cache is cleaned automatically via `update_option_permalink_structure` hook — no separate `flush_cache()` call needed. A generic `cache_cleared` flag in the EM results array suppresses redundant cache operations from the change-type classifier. The adapter also hooks [`frl_environment_state_changed`](core/environment/class-environment-state.php:90) to trigger EM enforcement when `polylang['default_lang']` mismatches the subdomain language.

## 🛠️ Developer Working Method
- **Standard:** Modular, Elegant, SEO-performant.
- **Reporting:** Use specific file/line references.
- **Verification:** Double-verify for regressions; No "Opinion as Fact."
- **Integrity:** Failing to follow = **LYING/GASLIGHTING**.

## 🔄 Post Cache Versioning Pattern (2026-06-29)

### Pattern: Version-Based Cache Invalidation
When data originates from a mutable source (post meta, post fields) and the cache key contains dynamic/unknown segments (e.g., field names from shortcode attributes), use a per-object version number to auto-invalidate all cache keys on update.

**Implementation:**
1. Store a version number in post meta (`_frl_post_version`)
2. Read it via a statically-cached helper: [`frl_get_post_cache_version()`](includes/helpers/functions.php:297)
3. Append `'_v' . $version` to any cache key that depends on post data
4. Bump the version in `frl_clear_post_cache()` on `save_post` via `update_post_meta($post_id, '_frl_post_version', time())`

**Advantages:**
- No explicit key enumeration — version bump invalidates all keys
- Surgical scope — only the edited post affected
- Old keys expire naturally (24h TTL) — no cleanup needed
- Zero signature changes — `frl_generate_cache_key()` unchanged

**When to apply:**
- Cache key contains post-specific data (meta, fields, title, slug)
- Cache key segments are dynamic (unknown at save time)
- NOT for slug-based keys (slug changes produce different key naturally)

## 🔒 Rule Integrity Notice
The mandatory-rules.md in `memory-bank/` is the source of truth.

---
*Last Updated: 2026-06-29*
