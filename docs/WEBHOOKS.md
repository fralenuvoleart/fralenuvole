# Webhook System

Generic webhook dispatch infrastructure shared by wsform and call_to_actions modules.
Lives in [`includes/helpers/functions-webhook.php`](includes/helpers/functions-webhook.php).

## API

### `frl_send_webhook( string $url, array $data ): array`

Synchronous cURL dispatch. Blocks until the remote server responds (15s timeout).

```php
$result = frl_send_webhook( 'https://webhooks.example.com/endpoint', array(
    'Name'  => 'John',
    'Email' => 'john@example.com',
) );

// $result = array( 'success' => true, 'http_code' => 200, 'error' => null );
```

Returns `{success: bool, http_code: int, error: ?string}`. Caller decides how to handle failures.
Logs errors via `frl_log()` on invalid URL, JSON encode failure, cURL error, or non-2xx response.

### `frl_send_webhook_async( string $url, array $data ): bool`

Fire-and-forget via WP-Cron. Returns immediately, dispatches in a background cron job.

```php
frl_send_webhook_async( 'https://webhooks.example.com/endpoint', $data );
```

Schedules a single `frl_webhook_dispatch` cron event. The actual cURL call runs in the cron job.
Returns `false` if scheduling fails (logs error), `true` otherwise. No response/error feedback to caller.

## Cron Hook

### `frl_webhook_dispatch`

```php
add_action( 'frl_webhook_dispatch', function ( array $args ) {
    $url  = $args['url'] ?? '';
    $data = $args['data'] ?? array();
    frl_send_webhook( $url, $data );
} );
```

Internal cron handler. Not intended for direct use by other code. Scheduled by `frl_send_webhook_async()`.

## How Modules Use It

### wsform

[`modules/wsform/webhooks-wsform.php:182-188`](modules/wsform/webhooks-wsform.php:182):

```php
$use_cron = $config['use_cron'] ?? true;
if ( $use_cron ) {
    frl_send_webhook_async( $webhook_url, $post_data );
} else {
    frl_send_webhook( $webhook_url, $post_data );
}
```

Per-webhook-entry `use_cron` toggle from `WSFORM_ALL_WEBHOOKS_CONFIG`. Defaults to async.

### call_to_actions

[`modules/call_to_actions/webhooks-call_to_actions.php:49-50`](modules/call_to_actions/webhooks-call_to_actions.php:49):

```php
$use_cron = $env_entry['use_cron'] ?? false;
if ( $use_cron ) {
    frl_send_webhook_async( $webhook_url, $post_data );
} else {
    $result = frl_send_webhook( $webhook_url, $post_data );
    if ( ! $result['success'] ) {
        wp_send_json_error( 'Webhook dispatch failed', 502 );
    }
}
```

Per-environment `use_cron` toggle from `CTA_WEBHOOK_CONFIG`. Defaults to sync. Checks result and returns error to client on failure.

## Adding a New Consumer

1. Require the helper: `require_once FRL_DIR_PATH . 'includes/helpers/functions-webhook.php';`
   (Already loaded via bootstrap in `includes/helpers/functions.php` — available everywhere.)

2. Choose sync or async:
   - **Sync:** Call `frl_send_webhook()`, check `$result['success']`, handle failures.
   - **Async:** Call `frl_send_webhook_async()`, no feedback — fire-and-forget.

3. Follow the existing pattern: put `use_cron` in your config so each webhook entry can choose.

```php
// Recommended pattern
$use_cron = $config['use_cron'] ?? true;
if ( $use_cron ) {
    frl_send_webhook_async( $url, $data );
} else {
    $result = frl_send_webhook( $url, $data );
    if ( ! $result['success'] ) {
        // Handle failure — log, retry, return error, etc.
    }
}
```

## cURL Configuration

| Setting | Value | Location |
|---|---|---|
| Timeout | 15s | [`functions-webhook.php:87`](includes/helpers/functions-webhook.php:87) |
| Connect timeout | 5s | [`functions-webhook.php:88`](includes/helpers/functions-webhook.php:88) |
| Headers | `Accept: application/json`, `Content-Type: application/json` | [`functions-webhook.php:84`](includes/helpers/functions-webhook.php:84) |
| Method | POST | [`functions-webhook.php:85`](includes/helpers/functions-webhook.php:85) |
| Encoding | Accept-Encoding: (any) | [`functions-webhook.php:90`](includes/helpers/functions-webhook.php:90) |
| NOSIGNAL | Enabled (safe in multi-threaded PHP) | [`functions-webhook.php:89`](includes/helpers/functions-webhook.php:89) |

## Error Handling

| Condition | Return | Logged |
|---|---|---|
| Empty/invalid URL | `{success: false, error: 'invalid_url'}` | Yes |
| JSON encode failure | `{success: false, error: '<json_error_msg>'}` | Yes |
| cURL execution error | `{success: false, error: '<curl_error>'}` | Yes |
| Non-2xx HTTP status | `{success: false, error: 'http_<code>'}` | Yes |
| Success (2xx) | `{success: true}` | No |
