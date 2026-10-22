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

CTA templates and webhook URLs are split across two files:

- **Templates** — [`config/config-message-templates.php`](../../config/config-message-templates.php) (`FRL_CTA_TEMPLATES`).
  Shared with the `wsform` module (email `@@cta:...@@` directives), so neither module depends on the other.
- **Webhook URLs** — [`config-constants-call_to_actions.php`](config-constants-call_to_actions.php) (`CTA_WEBHOOK_URLS`).

Per-environment structure:

```php
// config/config-message-templates.php
const FRL_CTA_TEMPLATES = array(
    'pbs' => array(
        array(
            'action_id' => 'whatsapp',
            'url'       => 'https://wa.me/...?text={template}',
            'template'  => 'Support number: PIN-{reference_id}-PBS ...',
        ),
    ),
);

// config-constants-call_to_actions.php
const CTA_WEBHOOK_URLS = array(
    'pbs' => 'https://webhooks.integrately.com/...',
);
```

### `use_cron` resolution

Admin option only — `cta_use_cron` (Modules tab). There is no per-environment constant fallback.

### Admin toggles

| Option | Purpose |
|---|---|
| `cta_webhook` | Master kill switch for webhook dispatch (Modules tab). When disabled, `send_webhook` is stripped from all actions — only deep links work, no webhooks fire. |
| `cta_use_cron` | Async (WP-Cron) vs sync (inline cURL) dispatch. Defaults to off (sync). |

The module on/off toggle (`module_call_to_actions`) is managed by the Environment system
(`config-defaults.php` / `config-environment.php`).

### Per-action webhook toggle

`send_webhook` is set at runtime in [`call_to_actions.php`](call_to_actions.php) from the
`cta_webhook` admin option — it is not authored in the config. When enabled, every action
dispatches a webhook on click.

## Files

| File | Purpose |
|---|---|
| `call_to_actions.php` | Module entry point, admin toggle gate, action registration filter |
| `config-constants-call_to_actions.php` | Webhook URLs (`CTA_WEBHOOK_URLS`), field mapping, rate limit |
| `config-options-call_to_actions.php` | `cta_webhook` / `cta_use_cron` admin toggles |
| `webhooks-call_to_actions.php` | AJAX handler: rate limiting, payload assembly, webhook dispatch |

CTA templates live in [`config/config-message-templates.php`](../../config/config-message-templates.php).
