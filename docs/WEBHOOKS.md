# Webhooks

Generic webhook dispatch infrastructure with sync/async dispatch, deduplication, and retry-with-backoff.

**File:** [`includes/helpers/functions-webhook.php`](includes/helpers/functions-webhook.php)  
**Config:** [`config/config-base.php`](config/config-base.php:88) (`FRL_WEBHOOK_RETRY`)

## API

### `frl_send_webhook( string $url, array $data, ?string $dedup_key = null, int $dedup_interval = 60 ): array`

Synchronous cURL dispatch. Blocks until remote server responds (15s timeout, 5s connect timeout).

```php
$result = frl_send_webhook('https://example.com/webhook', ['Name' => 'John']);
// ['success' => true, 'http_code' => 200, 'error' => null]
```

Returns `{success: bool, http_code: int, error: ?string}`. Logs via `frl_log()` on invalid URL, JSON encode failure, cURL error, or non-2xx response. Optional `$dedup_key` prevents duplicate dispatches within the interval window.

### `frl_send_webhook_async( string $url, array $data, ?string $dedup_key = null, int $dedup_interval = 60 ): bool`

Fire-and-forget via WP-Cron. Schedules a single `frl_webhook_dispatch` cron event with a unique `_frl_uuid` to defeat WP-Cron's 10-minute dedup. Returns `false` if scheduling fails.

## Cron Handler

[`frl_webhook_dispatch_handler()`](includes/helpers/functions-webhook.php:82) processes the `frl_webhook_dispatch` action. On failure, retries with backoff up to [`FRL_WEBHOOK_RETRY['max_attempts']`](config/config-base.php:88) (3 attempts, delays: 60s, 300s, 900s).

## cURL Configuration

| Setting | Value |
|---------|-------|
| Timeout | 15s |
| Connect timeout | 5s |
| Headers | `Accept: application/json`, `Content-Type: application/json` |
| Method | POST |
| NOSIGNAL | Enabled |

## Error Handling

| Condition | Return | Logged |
|-----------|--------|--------|
| Empty/invalid URL | `{success: false, error: 'invalid_url'}` | Yes |
| JSON encode failure | `{success: false, error: '<json_error_msg>'}` | Yes |
| cURL error | `{success: false, error: '<curl_error>'}` | Yes |
| Non-2xx HTTP status | `{success: false, error: 'http_<code>'}` | Yes |
| Success (2xx) | `{success: true}` | No |

## Module Usage

- **wsform:** [`modules/wsform/webhooks-wsform.php`](modules/wsform/webhooks-wsform.php) — per-webhook `use_cron` toggle, defaults async
- **call_to_actions:** [`modules/call_to_actions/webhooks-call_to_actions.php`](modules/call_to_actions/webhooks-call_to_actions.php) — per-environment `use_cron` toggle, defaults sync, checks result

## Adding a Consumer

```php
$use_cron = $config['use_cron'] ?? true;
if ($use_cron) {
    frl_send_webhook_async($url, $data);
} else {
    $result = frl_send_webhook($url, $data);
    if (!$result['success']) { /* handle failure */ }
}