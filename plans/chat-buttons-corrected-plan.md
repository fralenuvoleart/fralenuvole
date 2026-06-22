# Chat Buttons Module — Corrected Implementation Plan

**Date:** 2026-06-22  
**Clarification:** The user wants the BEST solution (modular, extensible), not the safest-by-avoidance. Regression risk is to be evaluated and managed, not used as an excuse to skip the refactor.

---

## The Correct Approach

The form submission webhook code in `webhooks.php` **SHOULD** be refactored to use shared utilities. This is not a sacrifice — it is the **correct architecture**. The regression risk is **LOW** because the refactor is a pure code move with zero behavioral change.

### What Changes in `webhooks.php`

| Function | Current | After Refactor | Risk |
|----------|---------|----------------|------|
| `frl_wsf_execute_webhook_submission()` | ~55 lines of cURL logic | **3-line wrapper** calling `frl_send_webhook()` | **ZERO** — same cURL logic, same error handling, same logging |
| `frl_wsf_should_send_webhook()` | ~23 lines of dedupe logic | **3-line wrapper** calling `frl_should_dedupe_webhook()` | **ZERO** — same transient logic, same TTL |
| `frl_wsf_submit_webhook()` | Orchestrates form → payload → dispatch | **Unchanged** | **ZERO** |
| `frl_wsf_spam_filter_submission()` | Spam filter logic | **Unchanged** | **ZERO** |

### The Refactor is a Pure Extraction

```php
// BEFORE (in webhooks.php)
function frl_wsf_execute_webhook_submission($args) {
    $webhook_url = $args['url'] ?? '';
    $post_data   = $args['data'] ?? [];
    
    if (empty($webhook_url) || !filter_var($webhook_url, FILTER_VALIDATE_URL)) {
        frl_log('WEBHOOK ERROR: ...', ['url' => $webhook_url]);
        return;
    }
    
    $json_payload = json_encode($post_data);
    if ($json_payload === false) {
        frl_log('WEBHOOK ERROR: Failed to encode...', ...);
        return;
    }
    
    $ch = curl_init($webhook_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json', 'Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json_payload);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if ($response === false) {
        $error = curl_error($ch);
        frl_log('WEBHOOK ERROR: cURL execution failed...', ...);
    } elseif ($http_code < 200 || $http_code >= 300) {
        frl_log('WEBHOOK ERROR: Received non-2xx...', ...);
    }
}

// AFTER (in webhooks.php)
function frl_wsf_execute_webhook_submission($args) {
    $result = frl_send_webhook($args['url'] ?? '', $args['data'] ?? []);
    // frl_send_webhook handles all logging internally
}
```

The cURL logic moves to `includes/helpers/functions-webhook.php` as `frl_send_webhook()` — **identical code, identical behavior**.

### Why This is BETTER Architecture

1. **Single source of truth for cURL dispatch** — One function handles all webhook HTTP calls
2. **Consistent error handling** — All webhook consumers get the same logging, timeout, and retry behavior
3. **Testable in isolation** — `frl_send_webhook()` can be unit-tested without loading WS Form
4. **Follows the Cache Operations precedent** — `Frl_Cache_Manager::hard_cache_reset()` is called by EM, admin actions, and the rewriter. Same pattern.

---

## Full Implementation Plan (With wsform Refactor)

### Phase 1: Extract Shared Utilities

1. Create `includes/helpers/functions-webhook.php`
   - `frl_send_webhook(string $url, array $data): array` — extracted from `frl_wsf_execute_webhook_submission()`
   - `frl_should_dedupe_webhook(array $data, array $keys, int $ttl = 21600): bool` — extracted from `frl_wsf_should_send_webhook()`
2. Refactor `webhooks.php`:
   - `frl_wsf_execute_webhook_submission()` → thin wrapper
   - `frl_wsf_should_send_webhook()` → thin wrapper
3. **Verification:** Test form submission webhooks. Behavior must be identical.

### Phase 2: Create `modules/chat-buttons/`

4. Create module skeleton
5. Create config constants (`CHAT_BUTTON_ACTIONS`, `CHAT_BUTTON_WEBHOOK_CONFIG`)
6. Create `chat-buttons.js` (rebuilt from `channel-tracking.js` button logic)
7. Create `class-chat-button-webhook.php` (new AJAX handler)
8. Register module in environment config (default: `false`)

### Phase 3: Enable and Verify

9. Enable on staging, test button clicks
10. Enable on production
11. Old button code in wsform continues to work as fallback

### Phase 4: Cleanup (After Verification)

12. Remove `WS_BUTTON_ACTIONS` from `config-constants-wsform.php`
13. Remove button handlers from `channel-tracking.js`
14. Remove `frl_wsf_button_webhook_handler()` from `webhooks.php`
15. Result: `webhooks.php` contains ONLY form submission logic

---

## Regression Risk Reassessment

| Change | Risk | Mitigation |
|--------|------|------------|
| Extract `frl_send_webhook()` | **LOW** | Pure code move. Same cURL options, same error handling, same logging. Wrapper preserves exact call signature. |
| Extract `frl_should_dedupe_webhook()` | **LOW** | Pure code move. Same transient logic, same TTL. Wrapper preserves exact call signature. |
| Create new module | **ZERO** | New files only. No existing code paths affected. |
| Enable new module | **LOW** | Gated by option toggle. Old code still exists as fallback. |
| Cleanup old code | **ZERO** | Only after new module is proven. |

**The form submission webhook refactor is a pure extraction with zero behavioral change.** The risk is not in the code — it is in the deployment process. Mitigation: test one form submission on staging after Phase 1.
