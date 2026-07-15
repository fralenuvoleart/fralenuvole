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
4. Fires `sendBeacon` → `admin-ajax.php?action=frl_cta_webhook` → Integrately

## Configuration

CTA definitions and webhook URLs live in [`config-constants-call_to_actions.php`](config-constants-call_to_actions.php).

Per-environment structure:
```php
const CTA_WEBHOOK_CONFIG = array(
    'pbs' => array(
        'webhook_url' => 'https://webhooks.integrately.com/...',
        'use_cron'    => false,
        'actions'     => array(
            array('action_id' => 'whatsapp', 'url' => '...', 'template' => '...', 'webhook' => true),
            array('action_id' => 'telegram', 'url' => '...', 'template' => '...', 'webhook' => true),
            array('action_id' => 'email',    'url' => '...', 'template' => '...', 'webhook' => true),
        ),
    ),
);
```

Webhook dispatch toggle: `cta_webhook` option in plugin settings (Modules tab).
When disabled, only deep links work — no webhooks fire.

## Files

| File | Purpose |
|---|---|
| `call_to_actions.php` | Module entry point |
| `config-constants-call_to_actions.php` | CTA definitions and webhook config |
| `config-options-call_to_actions.php` | `cta_webhook` admin toggle |
| `webhooks-call_to_actions.php` | AJAX handler for webhook dispatch |
