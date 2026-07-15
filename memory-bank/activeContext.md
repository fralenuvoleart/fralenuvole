# Active Context

## Current Focus

CTA extraction from wsform module into standalone `call_to_actions` module. Implementation complete. Plan rewritten as as-built document in [`plans/EXTRACTION-call-to-actions.md`](plans/EXTRACTION-call-to-actions.md).

## Changes Made

1. **Extracted CTA logic from wsform into `modules/call_to_actions/`** — New standalone module for WhatsApp, Telegram, and Email CTA click handling with marketing webhook dispatch.
2. **Created shared webhook utilities** — [`includes/helpers/functions-webhook.php`](includes/helpers/functions-webhook.php): `frl_send_webhook()` (sync cURL dispatch) and `frl_send_webhook_async()` (fire-and-forget via WP-Cron `frl_webhook_dispatch` action). Webhook deduplication removed — all webhooks always dispatch.
3. **Moved channel tracking to `public/`** — [`public/channel-tracking.php`](public/channel-tracking.php) with `frl_channel_tracking_init()` (guarded) and `frl_channel_tracking_enqueue()`. `CT_ATTR_*` constants live in [`config/config-channel-tracking.php`](config/config-channel-tracking.php). JS split into [`assets/js/public-channel-tracking.js`](assets/js/public-channel-tracking.js) (attribution, field population via `fieldPrefix + key`) and [`assets/js/public-cta-actions.js`](assets/js/public-cta-actions.js) (CTA click handling, unconditional `preventDefault()` for anchor-tag safety).
4. **wsform webhooks simplified** — [`modules/wsform/webhooks-wsform.php`](modules/wsform/webhooks-wsform.php): calls `frl_send_webhook_async()` / `frl_send_webhook()` directly. Old dedupe wrapper `frl_wsf_should_send_webhook()` and cron wrapper `frl_wsf_execute_webhook_submission()` removed. Old `frl_wsf_button_webhook_handler()` AJAX handler removed.
5. **🐛 Fixed module key hyphenation bug** — Module key `call-to-actions` contained hyphens which break PHP variable-name auto-discovery in [`functions-modules.php:251`](includes/helpers/functions-modules.php:251). Renamed to `call_to_actions` across all files.
6. **Phase 4: Cleaned up wsform dead code** — Removed `WS_BUTTON_ACTIONS`, `WS_BUTTON_WEBHOOK_SERVICE_META`, `WS_ATTR_*` constants. Deleted `config-constants-webhooks.php` (content merged into `config-constants-wsform.php`). Removed `wsform_channel_tracking` option. `webhooks.php` renamed to `webhooks-wsform.php`.
7. **Renamed AJAX action `frl_button_webhook` → `frl_cta_webhook`** — updated in JS and PHP. Deploy with full cache flush.
8. **Renamed `WS_STATS_FORM_IDS` → `WSFORM_STATS_FORM_IDS`** — consistency with `WSFORM_ALL_WEBHOOKS_CONFIG`.
9. **CTA_WEBHOOK_CONFIG restructured to `{url, use_cron}` objects** — matches wsform's `WSFORM_ALL_WEBHOOKS_CONFIG` per-entry pattern. `use_cron` defaults to `false` (sync).
10. **CTA handler: per-IP rate limiting** — `CTA_WEBHOOK_RATE_LIMIT` (30) / `CTA_WEBHOOK_RATE_WINDOW` (60s) in [`config-constants-call_to_actions.php`](modules/call_to_actions/config-constants-call_to_actions.php). Payload built via declarative `CTA_WEBHOOK_FIELDS` constant with sentinel values.
11. **Removed dead `fieldMapping`** — JS now always uses `fieldPrefix + key` (e.g., `channel_source`). Matches `data-name` attributes on WS Form hidden fields.
12. **Removed dead `config-options-call_to_actions.php`** — module toggle via env config (`config-defaults.php` / `config-environment.php`), auto-created by `frl_modules_load_options_defaults()`.

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
