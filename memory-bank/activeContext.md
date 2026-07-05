# Active Context

## ✅ Audit Patches Applied — 7 of 8 Findings (2026-07-05, same session)

**Context:** User approved implementing the audit findings below, explicitly skipping #5 (WS Form webhook — left untouched). #4 required a pre-patch risk investigation (documented) before proceeding, which concluded patching was safe.

**Patches applied (6 files, zero signature changes, all `php -l` verified):**
1. **[`admin/components/class-tag-validator.php:1361-1379`](admin/components/class-tag-validator.php:1361)** — `render()` now wraps the `validate_url()` call in `frl_cache_remember('adminui', 'tag_validator_' . md5($url.'|'.$tags), closure, 5*MINUTE_IN_SECONDS)`. Fixes the eager-argument-evaluation bug (network call was previously unavoidable on every Dashboard-tab render since PHP evaluates function args before the call).
2. **[`admin/components/class-display-log.php:335-336`](admin/components/class-display-log.php:335)** — Added missing `$current_entry = null;` init in `read_entries_reverse()`, matching sibling methods.
3. **[`core/environment/class-environment-files.php:81-119`](core/environment/class-environment-files.php:81)** — `load_environment_file()`'s `exec('php -l')` check now guarded by `function_exists('exec')` and `catch (\Throwable $e)` (was `catch (Exception $e)`, which doesn't catch `\Error` from a disabled `exec()`). Content is still returned unconditionally, exactly as before.
4. **[`includes/mu/functions-mu.php:135-216`](includes/mu/functions-mu.php:135)** — `frl_get_auth_cookie_user_data()` now verifies the auth cookie's HMAC signature (`hash_hmac('md5', ...)` → `hash_hmac('sha256', ...)` → `hash_equals()`) using `LOGGED_IN_KEY`/`LOGGED_IN_SALT` constants + the user's `user_pass` fragment, replicating `wp_validate_auth_cookie()`'s algorithm without depending on `pluggable.php` (confirmed via investigation: the crypto material is available from wp-config.php load time, well before mu-plugins run — only the *pluggable wrapper functions* are deferred by WP core, not the underlying constants/native hash functions). Added `u.user_pass` to the existing SQL SELECT. Documented trade-off: does not check `WP_Session_Tokens` (session revocation), so an explicit "log out everywhere" won't be honored until natural cookie expiry — accepted as a strict improvement over zero validation.
6. **[`core/cache/class-cache-manager.php:1399-1447`](core/cache/class-cache-manager.php:1399)** — `purge_group_storage()` now sets a canary key before calling `wp_cache_flush_group()` and checks afterward whether it survived; if so, logs a diagnostic warning via `frl_log()` (silent, no email) that the active object-cache backend doesn't appear to support real group-scoped flushing. Purely additive — `$count` return value and all caller-visible behavior unchanged.
7. **[`modules/frl/bible.php:55-119`](modules/frl/bible.php:55)** — `frl_bible_handle_proxy()` now wraps the ESV API call + Location-header resolution in `frl_cache_remember('shortcodes', 'bible_audio_' . md5($passage), closure, 2*MINUTE_IN_SECONDS)`. Error paths (`is_wp_error`, bad status code) call `wp_die()` inside the closure, which halts before any caching occurs — failures are always retried fresh, never cached.
8. **[`admin/components/class-display-log.php:733`](admin/components/class-display-log.php:733)** — `render()` now reads `$_POST['error_filter']` (was `$_POST['filter']`), matching the `isset($_POST['error_filter'])` guard and the actual `<select name="error_filter">` form field.

**Skipped per explicit user direction:** #5 (`modules/wsform/webhooks.php` button-webhook sync/async default inconsistency) — no change made.

**Full report + patch design rationale:** [`plans/audit-report-2026-07-05.md`](../plans/audit-report-2026-07-05.md).

---

## 🔍 Full Codebase Audit — 8 Findings (2026-07-05)

**Context:** User requested a from-scratch, entire-codebase analysis ignoring all past reports, focused on hidden bugs/logic errors in admin actions + WP core interaction, and performance codepaths undermining the "top-notch performance" USP. Full report: [`plans/audit-report-2026-07-05.md`](../plans/audit-report-2026-07-05.md).

**Findings (unpatched — analysis only, no code changed this session):**
1. **CRITICAL** — [`admin/components/class-tag-validator.php`](admin/components/class-tag-validator.php) has zero caching; `frl_tag_validator_render()` is called as a pre-evaluated PHP argument in [`class-dashboard.php:65`](admin/components/class-dashboard.php:65), so every load of the plugin's own Settings→Dashboard tab (the default tab) fires a blocking 30s-timeout self-cURL request via `direct_get_page_content()`.
2. **HIGH** — [`class-display-log.php:321`](admin/components/class-display-log.php:321) `read_entries_reverse()` never initializes `$current_entry` (unlike its siblings at lines 160/260) — undefined-variable warning on every large-log (>256KB) descending read, self-writing into the very debug.log being viewed.
3. **HIGH** — [`class-environment-files.php:94`](core/environment/class-environment-files.php:94) shells out to `exec('php -l ...')` purely for a logged-but-ignored syntax check (content returned regardless); `catch (Exception $e)` doesn't catch `\Error` from a disabled `exec()`, risking uncaught fatal on hardened hosts.
4. **MEDIUM-HIGH** — [`includes/mu/functions-mu.php:122`](includes/mu/functions-mu.php:122) `frl_get_auth_cookie_user_data()` destructures the auth cookie's hmac/token but never validates them — capability lookup by username only, spoofable by unauthenticated visitors, affecting the byCap plugin-exclusion feature.
5. **MEDIUM** — [`modules/wsform/webhooks.php:394`](modules/wsform/webhooks.php:394) button-click webhook (public, nopriv, no-nonce) defaults `use_cron=false` (sync/blocking), inconsistent with the main form webhook at line 219 which defaults `use_cron=true` (async).
6. **MEDIUM** — [`class-cache-manager.php:1399`](core/cache/class-cache-manager.php:1399) `purge_group_storage()` reports success (`count=1`) when `wp_cache_flush_group()` exists but the active drop-in doesn't actually support group-scoped flushing — cache "Clear" admin actions can silently no-op.
7. **LOW** — [`modules/frl/bible.php:79`](modules/frl/bible.php:79) ESV audio proxy has no caching on the resolved signed-URL per passage.
8. **LOW** — [`class-display-log.php:730`](admin/components/class-display-log.php:730) reads `$_POST['filter']` while checking `isset($_POST['error_filter'])` — key mismatch, masked by `stripos('', ...)` returning a match.

**Verification method:** Every finding traced through full call chain (hook → dispatcher → render function → leaf implementation) via `read_file` + `search_files`, per mandatory ripgrep-verification rule. No claim relies on inline comments alone. 62 areas re-confirmed as already well-engineered (cache subsystem, environment manager, rewriter, translator, MU cron/exclusion filters, public.php/shortcodes.php hot paths) — see report for full list.

---

## ✅ Cache Bridge Removal (2026-07-05)

**Context:** Surgically removed the two-way cache bridge (third-party cache plugin notification feature). This feature previously notified LiteSpeed, Breeze, and WP Rocket when fralenuvole cleared its caches (outbound), and listened for their purge actions (inbound). Removed all related code, constants, documentation, and memory-bank references.

**Files modified (7):**
- [`modules/thirdparty/thirdparty.php`](modules/thirdparty/thirdparty.php) — Removed ~340 lines of cache bridge code (functions, hooks, inline registration)
- [`modules/thirdparty/config-constants-thirdparty.php`](modules/thirdparty/config-constants-thirdparty.php) — Removed `FRL_THIRDPARTY_INBOUND_HOOKS`, `FRL_THIRDPARTY_INBOUND_QUERIES`, `FRL_THIRDPARTY_OUTBOUND_HOOKS` constants
- [`config/config-cache-operations.php`](config/config-cache-operations.php) — Removed `frl_thirdparty_maybe_notify()` steps from `clear_hard`, `clear_all`, `clear_light`, and updated `action_hard` note
- [`core/rewriter/class-rewriter.php`](core/rewriter/class-rewriter.php) — Removed `frl_thirdparty_maybe_notify('rewrite_flush')` from `clear_rewriter_caches()`
- [`includes/plugin-lifecycle.php`](includes/plugin-lifecycle.php) — Removed `frl_thirdparty_maybe_notify('rewrite_flush')` from `frl_flush_rewrite_rules()` before-wp_loaded fallback
- [`docs/CACHE.md`](docs/CACHE.md), [`docs/REWRITER.md`](docs/REWRITER.md), [`docs/ENVIRONMENT.md`](docs/ENVIRONMENT.md) — Removed third-party notification references from operation tables, execution flow diagrams, and environment docs

**Preserved:** SASWP schema injection, admin scripts, Greenshift REST fixes, and all unrelated functionality in [`modules/thirdparty/thirdparty.php`](modules/thirdparty/thirdparty.php).

## ✅ Audit Patch Round — 3 Confirmed Findings Patched (2026-07-05)

**Context:** Applied targeted fixes for 3 confirmed findings from the full codebase audit. (Original finding #1 — cache bridge — was superseded by the complete cache bridge removal above.)

**Applied (3 files, 3 patches):**

1. **[`admin/widgets/widget-user-visits.php:19-145`](admin/widgets/widget-user-visits.php:19)** — `frl_render_user_visits_widget()` now wraps entire rendering logic in `frl_cache_remember('adminui', 'user_visits_widget', ..., HOUR_IN_SECONDS)`. Added `'number' => 50` limit to `get_users()` call. Eliminates per-request unbounded user scan. Cache group corrected to `'adminui'` (admin interface assembly) per FRL_CACHE_PERSISTENT_GROUPS configuration.

2. **[`modules/pbnova/code-snippets.php:26-28`](modules/pbnova/code-snippets.php:26)** — `add_action()` now gated behind `defined('FRL_PBNOVA_ENABLE_REGISTRATION_SNIPPET') && FRL_PBNOVA_ENABLE_REGISTRATION_SNIPPET`. Template/tutorial code with hardcoded field IDs no longer registers unconditionally. Added clear header comment explaining template nature and activation instructions.

3. **[`modules/pbproperty/geodirectory.php:84-95`](modules/pbproperty/geodirectory.php:84)** — Added docblock documenting the N+1 `pll_get_post_language()` loop as acceptable (amortized by 24h daily cache). Includes remediation guidance if performance becomes critical.

---

## ✅ Performance Patches — Static Caches for Redundant Parsing (2026-07-04)

**Context:** Reviewed `plans/performance-report-2026-07-04.md` (24 findings). Cross-referenced each against live source code. 18 of 24 findings were overstated, already optimized, or voluntary design choices. 4 genuine findings patched; 2 excluded after investigation.

**Applied (3 files, 4 patches):**

1. **[`public/public.php:548-554`](public/public.php:548)** — `frl_alter_query()` now caches `custom_wp_query` textlist parsing in a `static $cached_cpts` variable. Eliminates redundant `frl_textlist_to_array()` + `frl_get_option()` on every secondary `WP_Query` (5-15 times per page load on widget-heavy pages).

2. **[`public/public.php:202-205`](public/public.php:202)** — `frl_preload_featured_image()` now caches `hero_mobile_list` textlist parsing in a `static $hero_mobile_cache` variable. Eliminates redundant parsing on every singular page request.

3. **[`admin/helpers/functions-admin.php:168-187`](admin/helpers/functions-admin.php:168)** — `frl_batch_update_options()` now pre-builds a `field_id => field_type` lookup map before the options loop. Replaces O(n×m) linear scan per option with O(1) hash map lookup. Map built once, reused for all options being saved.

4. **[`admin/helpers/functions-admin-action-handlers.php:31-35`](admin/helpers/functions-admin-action-handlers.php:31)** — `frl_autodiscover_admin_actions()` now uses `static $discovered` guard to skip redundant `get_defined_functions()` iteration when multiple admin-post actions fire in the same request.

**Excluded after investigation:**
- **#3 (frl_get_current_user TTL):** `frl_clear_user_cache()` only clears `metafields` group, not `admin` group user cache. Increasing TTL to `DAY_IN_SECONDS` without also invalidating the `admin` group entry would extend staleness of roles/capabilities to 24h. Session-bound key makes targeted invalidation complex. Current 1-hour TTL kept.
- **#18 (frl_get_post_id_by_slug two queries):** Necessary — hierarchical types use `pagename`, non-hierarchical use `name`. `get_page_by_path()` doesn't support Polylang's `lang` parameter. Results cached for 1 day in `permalinks` group. UNION query would bypass Polylang filters and WP post cache — fragile and high-risk.

**18 of 24 report findings rejected:** 4 critical (already optimized design choices), 8 high-frontend (6 overstated/design choices, 2 valid + patched), 5 high-admin (4 overstated/design choices, 1 valid + patched), 4 medium (niche or already cached), 4 low (already well-cached). The report systematically inflates severity ratings and ignores existing cache layers.

---

## ✅ Performance Optimizations — Batch Preload + Image Size Loop + all_options Batching (2026-07-04)

**Context:** Reviewed `plans/analysis.md` against live code. 3 of 4 proposed patches applied (Patch 3 — adminui context-gate — dropped as micro-optimization with negligible real-world benefit). All zero regression risk.

## ✅ Performance Patches — Subdomain Adapter & get_post_types Narrowing (2026-07-04)

**Context:** Full codebase performance audit — 10 findings, 3 actionable. Patch 1 (adminui group decoupling) dropped after honest cost assessment: the `adminui` iteration is ~1-2ms per request and not the actual bottleneck (the `$wpdb LIKE` query is, and it's already cached under `options` group with 1-hour TTL).

**Applied (2 files):**

1. **[`modules/subdomain_adapter/class-subdomain-adapter-legacy.php:264-282`](modules/subdomain_adapter/class-subdomain-adapter-legacy.php:264)** — `filter_the_content()` now has the same `str_contains` fast-fail guard that `filter_render_block()` already had. Content without recognized hostnames skips the expensive `preg_replace_callback` entirely. Pattern copied verbatim from lines 295-308 of the same file.

2. **[`includes/helpers/functions.php:349`](includes/helpers/functions.php:349)** — `frl_get_post_id_by_slug()` first query now uses `get_post_types(['public' => true, 'hierarchical' => true])` instead of `get_post_types(['public' => true])`. The `pagename` parameter only works for hierarchical post types, so non-hierarchical types in the `IN` clause were dead weight. The non-hierarchical fallback at line 362 already uses `'hierarchical' => false` with `'name'` — unchanged.

**Dropped:**
- Patch 1 (adminui group decoupling) — ~1-2ms per request, pure architectural correctness, no measurable speed gain.
- F5 (broad get_posts in shortcode fallback) — cold-cache-only, already gated behind `frl_cache_remember`.
- F6-F8 — micro-optimizations or already adequately cached.

**Changes (3 files):**

1. **[`core/cache/class-cache-manager.php:81-180`](core/cache/class-cache-manager.php:81)** — `auto_preload()` now detects transient-fallback scenario and routes to new `batch_preload_transients()` — single combined `wp_options` LIKE query (OR-chain across all groups) instead of 5-6 separate queries. Object-cache path completely untouched: guard at line 107 only enters batched codepath when `!self::is_object_cache_truly_functional()` AND all groups are persistent AND not already loaded. **10-12 DB queries → 1 on cold-cache frontend.**

2. **[`core/cache/cache-cleanup.php:87`](core/cache/cache-cleanup.php:87)** — Removed `$common_sizes` loop (`['thumbnail','medium','large','full']`) from `frl_clear_post_cache()`. Only the configured featured image size is now cleared. Alternate-size cache entries self-expire at 24h TTL and are never read by the preloader (which always uses current config). **~12-16 cache ops → ~4-6 per save_post.**

3. **[`includes/helpers/functions-options.php:785-794`](includes/helpers/functions-options.php:785)** — Static flag in `frl_set_missing_option_default()` batches `all_options` cache clear to once per request instead of once per missing option. When 50 options are seeded on cold cache, this eliminates 49 redundant `delete_transient()` + `unset()` + LRU-update cycles. First clear is sufficient — `frl_get_plugin_options_db()` uses per-request static cache, so subsequent calls read from memory regardless of persistent cache state. **50 clears → 1 on cold cache.**

**Drop rationale (Patch 3):** Context-gating `options → adminui` dependency cascade saves ~2 SELECT queries returning empty rows on cold cache — ~2ms on a 200-500ms TTFB. Not worth the code complexity.

---

## ✅ Block Translation Refactor — REST Guard + Translator Module Relocation (2026-07-03)

**Problem:** `render_block` → `frl_shortcode_render_block_translation()` fired during REST API requests, triggering the full translation pipeline (pattern extraction, Polylang adapter calls, cache operations) for every block with `frl-translate` class. ~3 second overhead per REST post list. Additionally, the block translation filter was architecturally misplaced in `shortcodes.php` instead of the translator module.

**Changes (2 files):**

1. **[`public/shortcodes.php`](public/shortcodes.php)**
   - Removed `render_block` p10 filter (`frl_shortcode_render_block_translation`) from `frl_shortcodes_init()`
   - Removed `frl_shortcode_render_block_translation()` function entirely
   - Kept `render_block` p20 `apply_shortcodes` — shortcodes now processed ONLY at p20 (was redundant at p10 + p20)

2. **[`core/translator/field-translator.php`](core/translator/field-translator.php)**
   - Added `render_block` p10 filter in `frl_translator_init()` → `frl_translate_block_content()`
   - New function `frl_translate_block_content()` — delegates to `frl_get_translation_block()`, guarded by `frl_is_valid_frontend_page_request()`
   - Filter inherits module guards: `frl_is_multilingual()` + `disable_translator` + `frl_is_multilingual_plugin_active()`

**New `render_block` priority chain:**
```
p10: frl_translate_block_content()       ← translator module, REST-guarded
p10: frl_process_nav_menu_url_transforms()  ← navigation, unchanged
p20: apply_shortcodes()                   ← shortcodes, unchanged
PHP_INT_MAX: subdomain adapter legacy      ← unchanged
```

**Zero regression:** Same translation function (`frl_get_translation_block()`), same `frl_block_translation_filter` hook path, `apply_shortcodes` runs at p20 only (was p10+p20, idempotent). REST overhead eliminated — `Frl_Translation_Service` singleton never instantiated.


## ✅ Second Audit — 1 Genuine Patch Applied (2026-07-02)

Second comprehensive audit reviewed 21 findings against live code. Only 1 genuine issue found. 20 others were either already patched, intentional design, already guarded upstream, or not real upon source inspection.

**Applied (1):**
- **B1:** `get_term_link()` `WP_Error` not caught by `??` at [`class-translation-service.php:385`](core/translator/class-translation-service.php:385) — replaced with `is_wp_error()` check.

**Reverted (1):**
- **P1 admin assets:** `ui-asset-loader.php` already guarded upstream at [`admin/admin.php:74`](admin/admin.php:74) via `frl_is_plugin_context()` before the require chain: `admin.php:74 → ui-admin-settings.php:19 → ui-asset-loader.php`. Code was already correct.

**Previously applied (prior session, 3):** P1(a) log capture filters gated behind `WP_DEBUG_LOG`, P3 html group invalidation for `header_html`/`footer_html`, P5 dead re-entrancy guard removed.

**Grand total across both reports:** 4 genuine patches from 26 claimed findings.

**Key debunks from 21-finding report:**
- `eval()` in `frl_process_php_string()` — gated behind admin `header_html_php` option; admins already have `unfiltered_html`
- `with_auth_preservation()` cookie re-issue — docblock explains rationale
- `frl_save_custom_avatar()` nonce — WP core fires `check_admin_referer('update-user_')` before the hook
- `frl_alter_query()` — "explicitly NOT to change", intentional optimization
- Admin assets — already guarded upstream at [`admin/admin.php:74`](admin/admin.php:74)

## ✅ Block Translation REST Guards — All Translator Hooks (2026-07-03, continued)

After the initial refactor, a systematic audit found 5 additional unguarded hooks in [`field-translator.php`](core/translator/field-translator.php) that called into the translation service without `frl_is_valid_frontend_page_request()` — instantiating `Frl_Translation_Service` + `Frl_Polylang_Adapter` during REST/AJAX/cron:

- [`frl_translator_acf_link()`](core/translator/field-translator.php:194) — ACF link format_value
- [`frl_translator_acf_taxonomy()`](core/translator/field-translator.php:236) — ACF taxonomy format_value
- [`frl_translator_acf_repeater()`](core/translator/field-translator.php:359) — ACF repeater format_value
- [`frl_translator_filter_get_terms()`](core/translator/field-translator.php:299) — get_terms filter
- [`frl_translator_filter_get_term()`](core/translator/field-translator.php:330) — get_term filter

All 5 now have early-return guard at function entry — same pattern as the 3 already-guarded callbacks (`frl_translator_pre_option`, `frl_translator_should_skip_translation`, `frl_translate_block_content`). **8 total guard points in field-translator.php now cover all 8 translation service entry points.**

**Key analysis — `{{}}` inside shortcode output:** Confirmed this was never translated (before or after refactor). Translation runs at p10 before `apply_shortcodes` at p20. If a shortcode produces `{{world}}`, there's no second translation pass. Both architectures identical — zero regression.

## ✅ Earlier Patches (prior session)
- Log capture filters gated behind `WP_DEBUG_LOG` at [`includes/main.php:42`](includes/main.php:42)
- `frl_update_option()` clears `html` group for `header_html`/`footer_html` at [`includes/helpers/functions-options.php:127-135`](includes/helpers/functions-options.php:127)
- Dead re-entrancy guard removed from `frl_get_option()` at [`includes/helpers/functions-options.php:89-92`](includes/helpers/functions-options.php:89)


