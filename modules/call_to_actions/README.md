# Call-to-Actions Module

WhatsApp, Telegram, and Email CTA click handling with marketing webhook dispatch.

## Usage

Add `data-action` attributes to any clickable element:

```html
<a href="#" data-action="whatsapp">Chat on WhatsApp</a>
<button data-action="telegram">Open Telegram</button>
<span data-action="email">Send Email</span>
```

The JS binds click handlers to `[data-action="whatsapp"]`, `[data-action="telegram"]`,
and `[data-action="email"]` selectors. Works on `<a>`, `<button>`, or any element.

On click:
1. `e.preventDefault()` — stops native navigation
2. Builds the deep link URL, replacing `{reference_id}` with the cookie value
3. Opens WhatsApp/Telegram in a new tab, or triggers mailto for email
4. If `send_webhook` is enabled for the action, fires `sendBeacon` → `admin-ajax.php?action=frl_cta_webhook` → Integrately

## Configuration

CTA definitions and webhook URLs live in [`config-constants-call_to_actions.php`](config-constants-call_to_actions.php).

Per-environment structure:
```php
const CTA_WEBHOOK_CONFIG = array(
    'pbs' => array(
        'webhook_url' => 'https://webhooks.integrately.com/...',
        'use_cron'    => false,
        'actions'     => array(
            array(
                'action_id'    => 'whatsapp',
                'url'          => 'https://wa.me/...',
                'template'     => '...',
                'send_webhook' => true,   // fire marketing webhook on click
            ),
        ),
    ),
);
```

### `use_cron` resolution order

1. `$env_config['use_cron']` — env-level override (e.g., `false` on staging)
2. Per-env constant entry in `CTA_WEBHOOK_CONFIG`
3. Hard default: `false` (sync)

### Admin toggles

| Option | Purpose |
|---|---|
| `module_call_to_actions` | Enable/disable the entire module (env config) |
| `cta_webhook` | Master kill switch for webhook dispatch (Modules tab). When disabled, `send_webhook` is stripped from all actions — only deep links work, no webhooks fire. |

### Per-action webhook toggle

Each action in `CTA_WEBHOOK_CONFIG` has a `send_webhook` boolean. Set to `false` on individual actions to skip webhook dispatch while keeping the deep link functional (e.g., email CTA that only opens mailto).

### Staging

Staging env templates set `'use_cron' => false` to force synchronous dispatch for testability. Production inherits the per-webhook constant defaults.

## Files

| File | Purpose |
|---|---|
| `call_to_actions.php` | Module entry point, admin toggle gate, action registration filter |
| `config-constants-call_to_actions.php` | CTA definitions, webhook URL, `send_webhook` flags, field mapping, rate limit |
| `config-options-call_to_actions.php` | `cta_webhook` admin toggle |
| `webhooks-call_to_actions.php` | AJAX handler: rate limiting, payload assembly, webhook dispatch |
