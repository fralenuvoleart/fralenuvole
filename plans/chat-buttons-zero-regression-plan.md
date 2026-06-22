# Chat Buttons Module — Zero-Regression Implementation Plan

**Date:** 2026-06-22  
**Constraint:** Form submission webhooks in `modules/wsform/webhooks.php` are CRITICAL and must not be touched. Button-click webhooks can be rebuilt.

---

## The Safe Path

Since button-click webhooks are NOT critical, we can **leave the existing wsform code completely untouched** and build the new chat-buttons module as a **greenfield implementation** that happens to reuse the same patterns.

### What Stays EXACTLY As-Is in wsform

| File | Lines | Content | Action |
|------|-------|---------|--------|
| `webhooks.php` | 1-289 | `frl_wsf_execute_webhook_submission()`, `frl_wsf_should_send_webhook()`, `frl_wsf_submit_webhook()`, `frl_wsf_spam_filter_submission()` | **NO CHANGES** |
| `webhooks.php` | 368-440 | `frl_wsf_button_webhook_handler()` + AJAX hooks | **NO CHANGES** (deprecated but functional) |
| `config-constants-webhooks.php` | All | `WSFORM_ALL_WEBHOOKS_CONFIG` | **NO CHANGES** |
| `config-constants-wsform.php` | 46-68 | `WS_BUTTON_ACTIONS` | **NO CHANGES** (deprecated but functional) |
| `channel-tracking.js` | 188-231 | `attachChatButtonHandlers()`, `buildChatUrl()`, `fireButtonWebhook()` | **NO CHANGES** (deprecated but functional) |
| `channel-tracking.php` | 49-89 | Chat button config localization | **NO CHANGES** (deprecated but functional) |

### What Gets Built NEW

| File | Purpose |
|------|---------|
| `includes/helpers/functions-webhook.php` | Generic `frl_send_webhook()` + `frl_should_dedupe_webhook()` — NEW code, no existing callers |
| `modules/chat-buttons/chat-buttons.php` | Module entry point, enqueue JS, register AJAX hooks |
| `modules/chat-buttons/config-constants-chat-buttons.php` | `CHAT_BUTTON_ACTIONS`, `CHAT_BUTTON_WEBHOOK_CONFIG` |
| `modules/chat-buttons/config-options-chat-buttons.php` | Admin toggle: `chat_buttons_enabled` |
| `modules/chat-buttons/assets/js/chat-buttons.js` | Button click handler (rebuilt from scratch, same logic) |
| `modules/chat-buttons/includes/class-chat-button-webhook.php` | AJAX handler `frl_chat_button_webhook_handler()` |

---

## Regression Risk Analysis

### Risk: ZERO for Form Submission Webhooks

**Why:** The form submission webhook code (`frl_wsf_submit_webhook()`, `frl_wsf_execute_webhook_submission()`, `frl_wsf_spam_filter_submission()`) lives in `webhooks.php` lines 1-289. We do not modify this file at all. The new module does not load `webhooks.php` differently. The `wsf_submit_post_complete` hook continues to fire `frl_wsf_submit_webhook()` exactly as before.

**Verification:** After deployment, test a WS Form submission. The webhook should fire to Integrately with identical payload. No code path changes.

### Risk: LOW for Existing Button Clicks (Deprecated but Functional)

**Why:** The old button-click code (`frl_wsf_button_webhook_handler()` at lines 368-440) remains in `webhooks.php`. Old pages with `[data-action]` buttons and the old `channel-tracking.js` will continue to work. The new `chat-buttons.js` uses the same `[data-action]` selectors but is only enqueued when the new module is active. If both old and new JS run on the same page, both will attach handlers — but the `data-button-bounded="1"` guard prevents double-attachment in the old code. The new code should use the same guard.

**Mitigation:** The new module is gated behind `frl_get_option('module_chat_buttons') === '1'`. It is disabled by default. You enable it per-environment after testing.

### Risk: ZERO for Attribution Tracking

**Why:** `channel-tracking.js` is not modified. It continues to set cookies and populate WS Form fields. The new `chat-buttons.js` only **reads** those cookies — it is a consumer, not a producer.

---

## Migration Strategy (No-Break)

### Step 1: Deploy New Module (Disabled)

1. Create all new files
2. Add `chat_buttons => false` to `FRL_ENV_DEFAULT['modules']`
3. The module does not load. Zero impact.

### Step 2: Enable on Staging

1. Set `chat_buttons => true` in staging environment config
2. Test button clicks on staging
3. Verify webhooks arrive at CRM
4. If broken, set back to `false` — production unaffected

### Step 3: Gradual Cutover on Production

1. Enable `chat_buttons` on production
2. The new `chat-buttons.js` is enqueued alongside the old `channel-tracking.js`
3. Both can coexist — the old button handlers are still functional
4. Once verified, remove `WS_BUTTON_ACTIONS` and old button code from wsform (Phase 2 cleanup)

---

## What the New `chat-buttons.js` Looks Like

```javascript
// Same logic as old attachChatButtonHandlers() + buildChatUrl() + fireButtonWebhook()
// But:
// - Own CONFIG object (from wp_localize_script in chat-buttons.php)
// - Reads _channel_* cookies set by channel-tracking.js
// - Fires to action=frl_chat_button_webhook (new AJAX endpoint)
// - Uses [data-action] selectors (unchanged)
```

## What the New AJAX Handler Looks Like

```php
// class-chat-button-webhook.php
class Frl_Chat_Button_Webhook {
    public static function handle(): void {
        // 1. Validate action_id
        // 2. Resolve webhook URL from CHAT_BUTTON_WEBHOOK_CONFIG per environment
        // 3. Build payload (reference_id, cta, service, language, channel_*, ...)
        // 4. Dedupe via frl_should_dedupe_webhook()
        // 5. Dispatch via frl_send_webhook()
        // 6. Return JSON response
    }
}
```

## What `frl_send_webhook()` Looks Like

```php
// includes/helpers/functions-webhook.php
function frl_send_webhook(string $url, array $data): array {
    // Extracted from frl_wsf_execute_webhook_submission() lines 235-289
    // Identical cURL logic, identical error handling, identical frl_log() calls
    // Returns: ['success' => bool, 'http_code' => int, 'error' => ?string]
}
```

## What `frl_should_dedupe_webhook()` Looks Like

```php
// includes/helpers/functions-webhook.php
function frl_should_dedupe_webhook(array $data, array $keys, int $ttl = 21600): bool {
    // Extracted from frl_wsf_should_send_webhook() lines 109-131
    // Generic: caller provides the field keys to use for dedupe (e.g., ['Reference ID', 'CTA'])
    // Uses frl_get_transient() / frl_set_transient() instead of raw get_transient()
}
```

---

## Phase 2 Cleanup (After New Module is Verified)

Once the new chat-buttons module is proven on production:

1. **Remove from `config-constants-wsform.php`:**
   - `WS_BUTTON_ACTIONS` constant
   - `WS_BUTTON_WEBHOOK_SERVICE_META` constant

2. **Remove from `channel-tracking.js`:**
   - `attachChatButtonHandlers()`
   - `buildChatUrl()`
   - `fireButtonWebhook()`
   - `chatActions` from CONFIG

3. **Remove from `channel-tracking.php`:**
   - Chat button config localization (lines 49-89)

4. **Remove from `webhooks.php`:**
   - `frl_wsf_button_webhook_handler()` (lines 368-440)
   - `wp_ajax_frl_button_webhook` hooks (lines 70-77)

5. **Result:** `webhooks.php` shrinks to only form submission logic. `channel-tracking.js` becomes pure attribution tracking.

**This cleanup is safe because the new module has already replaced the functionality.**

---

## Summary

| Phase | Action | Regression Risk |
|-------|--------|-----------------|
| 1 | Create new module (disabled) | **ZERO** — no code paths change |
| 2 | Enable on staging, test | **ZERO** — production still disabled |
| 3 | Enable on production | **LOW** — old code still exists as fallback |
| 4 | Cleanup old code from wsform | **ZERO** — new module already proven |

**The form submission webhook code in `webhooks.php` lines 1-289 is never modified.**
