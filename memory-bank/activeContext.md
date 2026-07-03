# Active Context

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

**Plan:** [`plans/rest-block-translation-bottleneck.md`](plans/rest-block-translation-bottleneck.md)

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


