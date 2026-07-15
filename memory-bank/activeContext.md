# Active Context

## Current Focus

CTA extraction from wsform module into standalone `call_to_actions` module. All 4 phases complete.

## Changes Made

1. **Extracted CTA logic from wsform into `modules/call_to_actions/`** — New standalone module for WhatsApp, Telegram, and Email CTA click handling with marketing webhook dispatch. Full plan in [`plans/EXTRACTION-call-to-actions.md`](plans/EXTRACTION-call-to-actions.md).
2. **Created shared webhook utilities** — [`includes/helpers/functions-webhook.php`](includes/helpers/functions-webhook.php): `frl_send_webhook()` (generic cURL dispatch) and `frl_should_dedupe_webhook()` (transient-based dedupe, polarity inverted from old `frl_wsf_should_send_webhook()`).
3. **Moved channel tracking to `public/`** — [`public/channel-tracking.php`](public/channel-tracking.php) with `CT_ATTR_*` constants, `frl_channel_tracking_init()` (guarded), `frl_channel_tracking_enqueue()`. JS split into [`assets/js/public-channel-tracking.js`](assets/js/public-channel-tracking.js) (attribution only) and [`assets/js/public-cta-actions.js`](assets/js/public-cta-actions.js) (CTA click handling with unconditional `preventDefault()` fix for anchor-tag safety).
4. **wsform webhooks thinned to delegates** — [`modules/wsform/webhooks-wsform.php`](modules/wsform/webhooks-wsform.php): `frl_wsf_should_send_webhook()` → 3-line `! frl_should_dedupe_webhook()` wrapper; `frl_wsf_execute_webhook_submission()` → 3-line `frl_send_webhook()` wrapper. AJAX hooks gated on `! frl_get_option('module_call_to_actions')`.
5. **🐛 Fixed module key hyphenation bug** — Module key `call-to-actions` contained hyphens which break PHP variable-name auto-discovery in [`functions-modules.php:251`](includes/helpers/functions-modules.php:251) (`$$var_name` where `$var_name = 'frl_call-to-actions_default_fields'` is invalid PHP). Renamed to `call_to_actions` across all files: directory, config-options filename/variable, env configs, and wsform gate check.
6. **Phase 4: Cleaned up wsform dead code** — Removed `WS_BUTTON_ACTIONS`, `WS_BUTTON_WEBHOOK_SERVICE_META`, `WS_ATTR_*` constants from [`config-constants-wsform.php`](modules/wsform/config-constants-wsform.php) (kept `WS_STATS_FORM_IDS`). Removed form_id 9 CTA workaround entry from [`config-constants-webhooks.php`](modules/wsform/config-constants-webhooks.php). Removed dead `wsform_channel_tracking` option from [`config-options-wsform.php`](modules/wsform/config-options-wsform.php). Removed `frl_wsf_button_webhook_handler()` and its AJAX gate block from [`webhooks-wsform.php`](modules/wsform/webhooks-wsform.php). `webhooks.php` renamed to `webhooks-wsform.php`.

## Prior Tasks (Completed)

- **Added `get_language_label()` to translation adapter chain** — new method on [`Frl_Translation_Adapter_Interface`](core/translator/adapters/interface.php), implemented in [`Frl_Polylang_Adapter`](core/translator/adapters/polylang.php) (uses `PLL()->model->get_language()`), exposed via [`Frl_Translation_Service::get_language_label()`](core/translator/class-translation-service.php), with a procedural helper [`frl_get_language_label()`](includes/helpers/functions-translator-helpers.php).
- **Deduplicated PLL language label lookup in shortcodes** — [`frl_langswitcher_build_list()`](public/shortcodes.php) and [`frl_langswitcher_build_dropdown()`](public/shortcodes.php) both now call `frl_get_language_label()`.
- **Deduplicated permalink resolution in [`frl_shortcode_permalink()`](public/shortcodes.php)** — unified conditional branches.
- **Fixed 2 adapter-bypass call sites** — breadcrumb term-language and navigation translation now use adapter methods.
- **`frl_process_deferred_writes()`** → `Frl_Cache_Manager::process_deferred_writes()` (with hook timing preserved)
- **`frl_add_page_excerpt_support`** moved to `includes/main/website.php`
- **Heartbeat review**: Our implementation correct, reference has 4 bugs
- **Intelephense stub** created at `.dev/stubs/_intelephense-globals.php`
- Cache Manager shape-mismatch fix, stampede-lock comment expansion, defensive comments for non-bugs
