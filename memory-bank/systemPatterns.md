# System Patterns

## 🏗️ Core Architecture & Init Sequence
- **`plugins_loaded`/5:** Translation interception + core components loaded (rewriter coordinator created, built-in features instantiated). Translation adapter interface and Polylang adapter loaded early in `translator.php` — available regardless of whether the service singleton is instantiated.
- **`plugins_loaded`/5:** `frl_modules_init()` — module entry points execute (can call `$coordinator->add_feature()`).
- **`plugins_loaded`/7:** `do_action('frl_rewriter_register_features')` + `usort()` — module-loaded rewriter features included in priority sort.
- **`init`/10:** Environment enforcement (domain-based).
- **`init`/15:** Rewriter registration (`feature->register()` for all features, including module-added ones).

## 🛡️ Critical Invariants (No Regressions)
- **Environment:** `pre_option_*` filters are used for domain overrides — these filters are strictly namespaced to `pre_option_frl_*`, never touching third-party option names.
- **Cache:** 5-backend unified interface; group config lives in `config/config-cache.php` (`FRL_CACHE_DEPENDENCIES`, TTLs, persistent groups).
- **Options:** 3-tier cascade — Static (per-request array) → Persistent (object cache/transient) → DB.
- **Race Conditions:** `frl_cache_remember()` uses lock-based prevention (`wp_cache_add()` short-TTL lock + exponential backoff).
- **MU-Plugin Caching:** `frl_cache_remember()` is safe inside `pre_option_*`/`pre_site_option_*` filters because it uses object cache/transients — never `get_option()`/`get_site_option()`. The only option read in the cache-bypass check is `get_option('frl_disable_plugin')`, a completely unrelated option. Cache invalidation is wired to `activated_plugin`/`deactivated_plugin` hooks.
- **MU-Plugin Early Auth Check:** `frl_get_auth_cookie_user_data()` (`includes/mu/functions-mu.php`) cryptographically verifies the WordPress `logged_in` auth cookie — HMAC + password-hash-fragment binding, replicating `wp_validate_auth_cookie()`'s algorithm — using only data available before `wp-includes/pluggable.php` loads (the `LOGGED_IN_KEY`/`LOGGED_IN_SALT` constants from `wp-config.php`, and native `hash_hmac()`). It intentionally does **not** check `WP_Session_Tokens` revocation. Used only for the capability-based plugin-exclusion decision, never for real authentication.
- **Access Control TTL Consistency:** Both [`frl_has_access()`](../includes/helpers/functions-access-control.php:25) and [`frl_get_auth_cookie_user_data()`](../includes/mu/functions-mu.php:135) use a **300s TTL** for their respective caches — one consistent rule for access-control caching across the standard and early-loading paths.
- **Cache Operations:** `Frl_Cache_Operations` (`core/cache/class-cache-operations.php`) dispatches multi-step cache operations defined in the `FRL_CACHE_OPERATIONS` constant (`config/config-cache-operations.php`). Three tiers:
  - **`clear_*`** — helper-level operations that `frl_cache_clear()` delegates to for composite groups (`hard`, `all`, `light`, `options`, `rewriter`).
  - **`action_*`** — admin-action-level operations called from action handlers.
  - **`env_*`** — Environment Manager enforcement-decision operations.
  - Sequential execution, no abort — every step always runs; the caller inspects per-step results.
  - `fn` supports callable arrays (`['Frl_Cache_Manager', 'hard_cache_reset']`), validated via `is_callable()`.
  - All existing standalone helper functions (`frl_cache_clear()`, `frl_flush_rewrite_rules()`) remain independently callable outside the orchestrator.
- **Cache Group Semantics:** Data originating from `wp_options`/`wp_sitemeta` uses the `options` group. Stable, infrequently-changing data (e.g., `active_plugins` list) uses a longer custom TTL (`WEEK_IN_SECONDS`) rather than the group's default.
- **Cache-Clear Diagnostics:** `Frl_Cache_Manager::purge_group_storage()` sets a canary key before calling `wp_cache_flush_group()` and checks afterward whether it survived — if so, it logs a diagnostic warning that the active object-cache backend doesn't actually support group-scoped flushing. This is diagnostic-only; it never changes the function's return value or caller-visible behavior.

## 🌐 Translation Fallback Architecture
- **Adapter self-contained fallbacks:** `Frl_Polylang_Adapter` encapsulates its own fallback logic — private methods `get_default_language_internal()` and `get_active_languages_internal()` read Polylang's DB options directly when Polylang's API is unavailable.
- **Global helpers delegate to adapter:** `frl_get_default_language_fallback()` and `frl_get_active_languages_fallback()` check `class_exists('Frl_Polylang_Adapter')` and instantiate the adapter directly if available.
- **Constant fallback:** `FRL_TRANSLATOR_DEFAULT_LANG` (default `'en'`) is the ultimate fallback when no adapter class exists (Polylang not installed).
- **Source language is separate from default language:** `FRL_TRANSLATOR_SOURCE_LANG` (default `'en'`) is the language content is authored in — semantically different from Polylang's "default language" and remains constant even when the default changes on a subdomain.
- **Adapter loading:** Adapter interface and implementation load early in `translator.php` (module entry point), so `class_exists` checks work regardless of whether `Frl_Translation_Service` is instantiated.
- **Subdomain Adapter default-language sync:** On first visit to a mapped subdomain, the adapter delegates to `Frl_Translation_Adapter_Interface::set_default_language()` via the `frl_environment_before_wp_options` action at `init`/10, then clears all caches and flushes rewrite rules. A generic `cache_cleared` flag in the Environment Manager's results array suppresses redundant cache operations from the change-type classifier. The adapter also hooks `frl_environment_state_changed` to trigger enforcement when `polylang['default_lang']` mismatches the subdomain language.

## 🔄 Post Cache Versioning Pattern

**Pattern:** When data originates from a mutable source (post meta, post fields) and the cache key contains dynamic/unknown segments (e.g., field names from shortcode attributes), use a per-object version number to auto-invalidate all cache keys on update, instead of enumerating every possible key.

**Implementation:**
1. Store a version number in post meta (`_frl_post_version`).
2. Read it via a statically-cached helper: `frl_get_post_cache_version()`.
3. Append `'_v' . $version` to any cache key that depends on post data.
4. Bump the version in `frl_clear_post_cache()` on `save_post` via `update_post_meta($post_id, '_frl_post_version', time())`.

**When to apply:** cache key contains post-specific data with dynamic/unknown segments (e.g., shortcode `field=` attribute values). **Not needed** for slug-based keys — a slug change naturally produces a different key.

## 🛠️ Developer Working Method
- **Standard:** Modular, elegant, SEO-performant.
- **Verification:** Trace every claim through the full call chain before asserting a pattern is followed or a regression is avoided — use `search_files`/grep, never assume from a function name or comment alone.
- **Zero Regression Policy:** Check this file before every file write to ensure changes don't violate an established architectural invariant above.

---

*This file describes durable architectural rules, not a changelog. When a pattern changes, update the entry in place.*
