# Chat Buttons Module — Implementation Guide

**Date:** 2026-06-22  
**Status:** Approved for implementation  
**Next step:** Switch to Code mode and execute Phase 1-4

---

## 1. WHY We Are Doing This

### The Problem

The `modules/wsform/` module currently handles THREE unrelated concerns:

1. **WS Form integration** — translating form fields, setting defaults, form success messages
2. **Attribution tracking** — capturing UTM params, referrers, gclid/fbclid into browser cookies
3. **Chat button click handling** — building WA/TG deep links, firing marketing webhooks on button click

The chat button functionality (concern #3) has **zero dependency on WS Form**. It reads cookies set by the attribution tracker and fires webhooks via generic WordPress AJAX. Keeping it inside the `wsform` module violates the plugin's modular architecture and creates maintenance confusion.

### The User's Request

> "Create a button for WA/TG, that on click at the same time sends marketing data in background via webhook, then opens WA/TG link with custom params to initiate native app/bot."

This functionality is **already partially implemented** in `modules/wsform/` but is tangled with form-specific code. The goal is to extract it into a clean, standalone module.

### The bot.php Misunderstanding

The attached `bot.php` is a **Telegram bot webhook endpoint** — it handles what happens AFTER a user arrives at the Telegram bot (inline keyboards, topic selection, CRM dispatch). It is **NOT** a website button system. The website button system is what we are building/extracting.

---

## 2. WHAT Already Exists (The Prototype)

### File Map of Current Implementation

```
modules/wsform/
├── config-constants-wsform.php         # WS_BUTTON_ACTIONS (lines 46-68)
│   └── Defines: whatsapp, telegram, email buttons with URLs + templates
├── channel-tracking.php                # Enqueues JS + localizes config
│   └── Lines 49-89: chat button config sent to JS
├── assets/js/channel-tracking.js     # Client-side attribution + button handling
│   └── Lines 188-231: attachChatButtonHandlers(), buildChatUrl(), fireButtonWebhook()
├── webhooks.php                        # Server-side webhook dispatch
│   └── Lines 368-440: frl_wsf_button_webhook_handler() + AJAX hooks
│   └── Lines 235-289: frl_wsf_execute_webhook_submission() (cURL dispatch)
│   └── Lines 109-131: frl_wsf_should_send_webhook() (dedupe logic)
└── config-constants-webhooks.php       # WSFORM_ALL_WEBHOOKS_CONFIG
    └── Per-environment webhook URLs for form submissions
```

### Data Flow (Current)

```
User clicks [data-action="whatsapp"]
        │
        ▼
[channel-tracking.js] attachChatButtonHandlers()
        │
        ├─► buildChatUrl(actionConfig, referenceId)
        │       └─► Reads cookies (_channel_reference_id, _channel_source, etc.)
        │           Interpolates {reference_id}, {field-data-name:xxx}
        │           Returns: https://wa.me/995522220776?text=Hello...PIN-ABC123-PBS
        │
        ├─► fireButtonWebhook(actionId)   [if actionConfig.hasWebhook]
        │       └─► navigator.sendBeacon(admin-ajax.php, FormData)
        │               └─► action=frl_button_webhook
        │                   action_id=whatsapp
        │                   reference_id=ABC123
        │                   source=google, medium=cpc, campaign=summer26 ...
        │
        └─► window.open(targetUrl, '_blank')
                └─► Opens WA/TG app
```

```
[Beacon arrives at WordPress]
        │
        ▼
[webhooks.php] frl_wsf_button_webhook_handler()
        │
        ├─► Looks up WS_BUTTON_ACTIONS by action_id → gets webhook URL
        ├─► Builds post_data (reference_id, cta, service, language, referer, IP, page_url, channel_*)
        ├─► Dedupe check: frl_wsf_should_send_webhook() (6h transient)
        ├─► Dispatches: frl_wsf_execute_webhook_submission($args)
        │       └─► cURL POST to Integrately/CRM webhook URL
        └─► wp_send_json_success()
```

---

## 3. The Target Architecture

### Module Separation

```
modules/wsform/                          modules/chat-buttons/
├── config-constants-wsform.php          ├── config-constants-chat-buttons.php
│   └── WS_ATTR_PREFIX, cookie settings   │   └── CHAT_BUTTON_ACTIONS
│   └── WS_ATTR_KEYS, field mapping       │   └── CHAT_BUTTON_WEBHOOK_CONFIG
│   └── WS_STATS_FORM_IDS                 │   └── CHAT_BUTTON_SERVICE_META
│   └── WS_BUTTON_WEBHOOK_SERVICE_META     │
├── channel-tracking.php                   ├── chat-buttons.php
│   └── Enqueues attribution JS           │   └── Module entry point
│   └── Localizes attribution config      │   └── Enqueues chat-buttons.js
├── assets/js/channel-tracking.js        │   └── Registers AJAX hooks
│   └── captureAttribution()              │
│   └── populateFormFields()              ├── assets/js/chat-buttons.js
│   └── (NO button handlers)              │   └── attachChatButtonHandlers()
│                                         │   └── buildChatUrl()
├── webhooks.php                          │   └── fireButtonWebhook()
│   └── frl_wsf_submit_webhook()        │
│   └── frl_wsf_spam_filter_submission()  ├── includes/
│   └── frl_wsf_execute_webhook()        │   └── class-chat-button-webhook.php
│       └─► frl_send_webhook()            │       └── handle() — AJAX handler
│   └── frl_wsf_should_send_webhook()    │
│       └─► frl_should_dedupe_webhook()   └── config-options-chat-buttons.php
│                                           └── Admin toggle
```

### Shared Utilities (Core)

```
includes/helpers/functions-webhook.php
├── frl_send_webhook(string $url, array $data): array
│   └── Extracted from frl_wsf_execute_webhook_submission()
│   └── cURL dispatch, JSON encoding, error handling, frl_log()
│   └── Returns: ['success' => bool, 'http_code' => int, 'error' => ?string]
│
└── frl_should_dedupe_webhook(array $data, array $keys, int $ttl = 21600): bool
    └── Extracted from frl_wsf_should_send_webhook()
    └── Transient-based dedupe with configurable keys
    └── Uses frl_get_transient() / frl_set_transient() (plugin cache layer)
```

---

## 4. Decision Rationale

### Why Extract Chat Buttons from wsform?

| Reason | Evidence |
|--------|----------|
| Zero WS Form dependency | Button click handlers use `[data-action]` selectors and `navigator.sendBeacon` — no WS Form API |
| Attribution is separate concern | `channel-tracking.js` sets cookies; buttons consume cookies. Producer/consumer relationship. |
| Module name mismatch | `wsform` implies form functionality. Chat buttons are not forms. |
| Config sprawl | Button definitions, webhook URLs, and attribution settings are split across 3 files in wsform namespace. |

### Why Shared Utilities (Not Standalone Webhook Module)?

| Factor | Assessment |
|--------|------------|
| Consumer count | Only 2 webhook consumers exist (form submissions, button clicks). Cache Operations was justified at 6+. |
| YAGNI | A full module for cURL dispatch is overkill for 2 call sites. |
| Upgrade path | When a 3rd consumer appears, helpers can be promoted into `Frl_Webhook_Dispatcher` without breaking existing callers. |
| KISS | Two functions (~80 lines) solve the problem. A module adds ~300 lines of infrastructure. |

### Why Refactor wsform to Use Shared Utilities?

The user explicitly requested the **best solution**, not the safest-by-avoidance. The refactor is a **pure code extraction** with zero behavioral change:

- `frl_wsf_execute_webhook_submission()` becomes a 3-line wrapper
- `frl_wsf_should_send_webhook()` becomes a 3-line wrapper
- The cURL logic and dedupe logic move to shared helpers **identically**
- Form submission webhooks continue to work exactly as before

---

## 5. File-by-File Implementation

### NEW: `includes/helpers/functions-webhook.php`

```php
<?php
/**
 * Generic webhook dispatch utilities.
 *
 * Extracted from modules/wsform/webhooks.php to enable reuse across modules.
 * Used by: wsform (form submissions), chat-buttons (button clicks)
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Send a webhook payload via cURL POST.
 *
 * @param string $url  The webhook URL.
 * @param array  $data The payload data (will be JSON-encoded).
 * @return array {
 *     @type bool   $success   Whether the request succeeded (2xx HTTP code).
 *     @type int    $http_code The HTTP response code.
 *     @type string $error     cURL error message, if any.
 * }
 */
function frl_send_webhook(string $url, array $data): array
{
    $result = [
        'success'   => false,
        'http_code' => 0,
        'error'     => '',
    ];

    if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        frl_log('WEBHOOK ERROR: frl_send_webhook() - Invalid or missing webhook URL.', ['url' => $url]);
        $result['error'] = 'Invalid or missing webhook URL.';
        return $result;
    }

    $json_payload = json_encode($data);

    if ($json_payload === false) {
        frl_log(
            'WEBHOOK ERROR: Failed to encode data to JSON in frl_send_webhook(). Error: {error}. Data: {data}',
            [
                'error' => json_last_error_msg(),
                'data'  => print_r($data, true)
            ]
        );
        $result['error'] = 'JSON encoding failed: ' . json_last_error_msg();
        return $result;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Content-Type: application/json'],
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $json_payload,
        CURLOPT_TIMEOUT        => 15,
    ]);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    $result['http_code'] = $http_code;

    if ($response === false) {
        frl_log(
            'WEBHOOK ERROR: cURL execution failed for frl_send_webhook(). Error: {error}. Payload: {payload}',
            ['error' => $curl_error, 'payload' => $json_payload]
        );
        $result['error'] = $curl_error;
    } elseif ($http_code < 200 || $http_code >= 300) {
        frl_log(
            'WEBHOOK ERROR: Received non-2xx HTTP status code ({status}) in frl_send_webhook(). Response: {response}. Payload: {payload}',
            ['status' => $http_code, 'response' => $response, 'payload' => $json_payload]
        );
        $result['error'] = 'HTTP ' . $http_code;
    } else {
        $result['success'] = true;
    }

    return $result;
}

/**
 * Determine whether a webhook should be sent based on dedupe rules.
 *
 * Uses transient-based deduplication. If a webhook with the same dedupe key
 * was sent within the TTL window, returns false.
 *
 * @param array $data The payload data.
 * @param array $keys The keys from $data to use for dedupe hash (e.g., ['Reference ID', 'CTA']).
 * @param int   $ttl  Time-to-live in seconds (default: 6 hours).
 * @return bool True if the webhook should be sent, false if deduplicated.
 */
function frl_should_dedupe_webhook(array $data, array $keys, int $ttl = 21600): bool
{
    if (is_user_logged_in()) {
        return true;
    }

    $values = [];
    foreach ($keys as $key) {
        $values[] = isset($data[$key]) ? trim((string) $data[$key]) : '';
    }

    $reference_id = $values[0] ?? '';
    $channel      = $values[1] ?? '';

    if ($reference_id === '' || $channel === '') {
        return true;
    }

    $dedupe_key = 'frl_webhook_dedupe_' . md5($reference_id . '|' . strtolower($channel));

    if (frl_get_transient($dedupe_key)) {
        return false;
    }

    frl_set_transient($dedupe_key, 1, $ttl);

    return true;
}
```

### MODIFIED: `modules/wsform/webhooks.php`

**Lines 235-289:** Replace `frl_wsf_execute_webhook_submission()` with thin wrapper:

```php
/**
 * Executes the actual webhook cURL request.
 * Delegates to frl_send_webhook() for generic dispatch.
 *
 * @param array $args An array containing 'url' and 'data'.
 */
function frl_wsf_execute_webhook_submission($args)
{
    $webhook_url = $args['url'] ?? '';
    $post_data   = $args['data'] ?? [];

    frl_send_webhook($webhook_url, $post_data);
}
```

**Lines 109-131:** Replace `frl_wsf_should_send_webhook()` with thin wrapper:

```php
/**
 * Determines whether a webhook should be sent based on dedupe rules.
 * Delegates to frl_should_dedupe_webhook() for generic dedupe.
 *
 * @param array $post_data
 * @return bool
 */
function frl_wsf_should_send_webhook(array $post_data)
{
    return frl_should_dedupe_webhook($post_data, ['Reference ID', 'CTA']);
}
```

**Add at top of file (after ABSPATH check):**
```php
require_once FRL_DIR_PATH . 'includes/helpers/functions-webhook.php';
```

### NEW: `modules/chat-buttons/config-constants-chat-buttons.php`

```php
<?php
/**
 * Chat Buttons module configuration.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Chat button definitions.
 *
 * Each entry defines a button that can be placed on the frontend.
 * The selector is derived as [data-action="{id}"].
 *
 * @var array
 */
const CHAT_BUTTON_ACTIONS = [
    [
        'id'       => 'whatsapp',
        'url'      => 'https://wa.me/995522220776?text={template}',
        'template' => "Hello,\r\nI'd like to enquire about your services.\r\n\r\n\r\n---\r\nSupport number: PIN-{reference_id}-PBS\r\n(Please don't delete your support number)",
    ],
    [
        'id'       => 'telegram',
        'url'      => 'https://t.me/PBSERVICES_bot?start={template}',
        'template' => '{reference_id}',
    ],
];

/**
 * Per-environment webhook configuration for chat buttons.
 *
 * Each button ID can have its own webhook URL and dispatch settings.
 * Environment overrides are applied on top of the base config.
 *
 * @var array
 */
const CHAT_BUTTON_WEBHOOK_CONFIG = [
    'default' => [],
    'pbs' => [
        'whatsapp' => [
            'url'      => '', // Add Integrately URL here
            'use_cron' => false,
        ],
        'telegram' => [
            'url'      => '', // Add Integrately URL here
            'use_cron' => false,
        ],
    ],
];

/**
 * Post meta key used to resolve the Service type for a page.
 *
 * @var string
 */
const CHAT_BUTTON_SERVICE_META = 'service-settings_service-type';
```

### NEW: `modules/chat-buttons/config-options-chat-buttons.php`

```php
<?php
/**
 * Chat Buttons module admin options.
 */

if (!defined('ABSPATH')) {
    exit;
}

$frl_chat_buttons_default_fields = [
    'section_title_chat_buttons' => [
        'label'       => 'Chat Buttons Module',
        'type'        => 'section_title',
        'description' => 'WhatsApp/Telegram chat button configuration with marketing webhook tracking.',
    ],
    'chat_buttons_enabled' => [
        'label'             => 'Enable Chat Buttons',
        'description'       => 'Enable WhatsApp/Telegram chat buttons with marketing webhook tracking.',
        'type'              => 'checkbox',
        'default'           => 1,
        'sanitize_callback' => 'absint',
        'restricted'        => true,
    ],
];
```

### NEW: `modules/chat-buttons/chat-buttons.php`

```php
<?php
/**
 * Module Name: Chat Buttons
 * Description: WhatsApp/Telegram chat buttons with marketing webhook tracking.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/config-constants-chat-buttons.php';

add_action('plugins_loaded', 'frl_chat_buttons_init', 10, 0);

/**
 * Initialize the chat buttons module.
 */
function frl_chat_buttons_init()
{
    if (!frl_get_option('chat_buttons_enabled')) {
        return;
    }

    frl_chat_buttons_register_hooks();
}

/**
 * Register frontend hooks.
 */
function frl_chat_buttons_register_hooks()
{
    if (frl_is_already_running(__FUNCTION__)) {
        return;
    }

    add_action('wp_enqueue_scripts', 'frl_chat_buttons_enqueue_assets');

    add_action('wp_ajax_frl_chat_button_webhook', 'frl_chat_button_webhook_handler');
    add_action('wp_ajax_nopriv_frl_chat_button_webhook', 'frl_chat_button_webhook_handler');
}

/**
 * Enqueue the chat buttons script and provide configuration.
 */
function frl_chat_buttons_enqueue_assets()
{
    $script_url  = plugins_url('assets/js/chat-buttons.js', __FILE__);
    $script_path = plugin_dir_path(__FILE__) . 'assets/js/chat-buttons.js';
    $version     = file_exists($script_path) ? filemtime($script_path) : '1.0.0';

    // Depend on channel-tracking.js so cookies are set before buttons read them
    wp_enqueue_script(
        'frl-chat-buttons',
        $script_url,
        ['ws-forms-attribution-tracking'],
        $version,
        [
            'strategy'  => 'defer',
            'in_footer' => true,
        ]
    );

    $actions = defined('CHAT_BUTTON_ACTIONS') ? CHAT_BUTTON_ACTIONS : [];

    $has_any_webhook = false;
    foreach ($actions as &$action) {
        $action['hasWebhook'] = frl_chat_buttons_get_webhook_url($action['id']) !== '';
        if ($action['hasWebhook']) {
            $has_any_webhook = true;
        }
    }
    unset($action);

    $config = [
        'ajaxUrl'   => $has_any_webhook ? admin_url('admin-ajax.php') : '',
        'language'  => function_exists('frl_get_language') ? strtoupper(frl_get_language()) : '',
        'actions'   => $actions,
        'cookiePrefix' => '_' . (defined('WS_ATTR_PREFIX') ? WS_ATTR_PREFIX : 'ws_attr') . '_',
    ];

    wp_localize_script('frl-chat-buttons', 'frlChatButtonsConfig', $config);
}

/**
 * Get the webhook URL for a given button ID in the current environment.
 *
 * @param string $button_id The button ID (e.g., 'whatsapp').
 * @return string The webhook URL, or empty string if not configured.
 */
function frl_chat_buttons_get_webhook_url(string $button_id): string
{
    $env_config = frl_environment_get_config();
    $config_key = $env_config['webhook_config'] ?? $env_config['prefix'] ?? 'default';

    $configs = defined('CHAT_BUTTON_WEBHOOK_CONFIG') ? CHAT_BUTTON_WEBHOOK_CONFIG : [];
    $base    = $configs[$config_key] ?? $configs['default'] ?? [];

    if (!empty($env_config['chat_buttons_webhooks']) && is_array($env_config['chat_buttons_webhooks'])) {
        $base = array_replace_recursive($base, $env_config['chat_buttons_webhooks']);
    }

    return $base[$button_id]['url'] ?? '';
}

/**
 * Handle button-click webhook requests via AJAX.
 */
function frl_chat_button_webhook_handler()
{
    require_once FRL_DIR_PATH . 'includes/helpers/functions-webhook.php';
    require_once __DIR__ . '/includes/class-chat-button-webhook.php';

    Frl_Chat_Button_Webhook::handle();
}
```

### NEW: `modules/chat-buttons/includes/class-chat-button-webhook.php`

```php
<?php
/**
 * Chat Button Webhook Handler
 *
 * Processes button-click webhook requests and dispatches to CRM.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Frl_Chat_Button_Webhook
{
    /**
     * Handle the AJAX request.
     */
    public static function handle(): void
    {
        $action_id = sanitize_text_field($_POST['action_id'] ?? '');
        if (empty($action_id) || !defined('CHAT_BUTTON_ACTIONS')) {
            wp_send_json_error('Invalid action', 400);
        }

        $webhook_url = frl_chat_buttons_get_webhook_url($action_id);
        if (empty($webhook_url)) {
            wp_send_json_error('No webhook configured', 404);
        }

        $service = 'Webpage';
        $page_url = sanitize_url($_POST['page_url'] ?? '');
        $post_id  = url_to_postid($page_url);
        if ($post_id > 0 && defined('CHAT_BUTTON_SERVICE_META')) {
            $meta = frl_get_post_meta($post_id, CHAT_BUTTON_SERVICE_META, true);
            if (!empty($meta)) {
                $service = sanitize_text_field($meta);
            }
        }

        $post_data = [
            'Reference ID'     => sanitize_text_field($_POST['reference_id'] ?? ''),
            'CTA'              => ucfirst($action_id),
            'Service'          => $service,
            'Language'         => sanitize_text_field($_POST['language'] ?? ''),
            'Referer'          => sanitize_url($_POST['referer'] ?? ''),
            'User IP'          => sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? ''),
            'Page URL'         => sanitize_url($_POST['page_url'] ?? ''),
            'Channel Source'   => sanitize_text_field($_POST['source'] ?? ''),
            'Channel Medium'   => sanitize_text_field($_POST['medium'] ?? ''),
            'Channel Campaign' => sanitize_text_field($_POST['campaign'] ?? ''),
            'Channel Term'     => sanitize_text_field($_POST['term'] ?? ''),
            'Channel Content'  => sanitize_text_field($_POST['content'] ?? ''),
            'Channel GCLID'    => sanitize_text_field($_POST['gclid'] ?? ''),
            'Channel FBCLID'   => sanitize_text_field($_POST['fbclid'] ?? ''),
            'Channel Landing'  => sanitize_text_field($_POST['landing'] ?? ''),
        ];

        if (!frl_should_dedupe_webhook($post_data, ['Reference ID', 'CTA'])) {
            wp_send_json_success('Deduplicated');
        }

        $result = frl_send_webhook($webhook_url, $post_data);

        if ($result['success']) {
            wp_send_json_success('Webhook sent');
        } else {
            wp_send_json_error('Webhook failed: ' . $result['error'], 500);
        }
    }
}
```

### NEW: `modules/chat-buttons/assets/js/chat-buttons.js`

```javascript
(function() {
    'use strict';

    var CONFIG = window.frlChatButtonsConfig;
    if (!CONFIG) return;

    function getCookie(name) {
        var nameEQ = CONFIG.cookiePrefix + name + '=';
        var ca = document.cookie.split(';');
        for (var i = 0; i < ca.length; i++) {
            var c = ca[i];
            while (c.charAt(0) === ' ') c = c.substring(1, c.length);
            if (c.indexOf(nameEQ) === 0) return decodeURIComponent(c.substring(nameEQ.length, c.length));
        }
        return null;
    }

    function buildChatUrl(actionConfig) {
        if (!actionConfig || !actionConfig.url) return '';
        var url = actionConfig.url;
        var refId = getCookie('reference_id') || '';

        if (actionConfig.template) {
            var template = actionConfig.template;
            template = template.replace(/{reference_id}/g, refId);
            template = template.replace(/{field-data-name:([^}]+)}/g, function(match, fieldName) {
                var el = document.querySelector('[data-name="' + fieldName + '"]');
                return el ? (el.value || '') : '';
            });
            url = url.replace('{template}', encodeURIComponent(template));
        }

        return url;
    }

    function fireButtonWebhook(actionId) {
        if (!CONFIG.ajaxUrl) return;
        var data = new FormData();
        data.append('action', 'frl_chat_button_webhook');
        data.append('action_id', actionId);
        data.append('page_url', window.location.href);
        data.append('referer', document.referrer || '');
        data.append('language', CONFIG.language || '');

        var keys = ['source', 'medium', 'campaign', 'term', 'content', 'gclid', 'fbclid', 'landing', 'reference_id'];
        for (var i = 0; i < keys.length; i++) {
            data.append(keys[i], getCookie(keys[i]) || '');
        }

        navigator.sendBeacon(CONFIG.ajaxUrl, data);
    }

    function attachChatButtonHandlers() {
        if (!Array.isArray(CONFIG.actions) || !CONFIG.actions.length) return;

        for (var i = 0; i < CONFIG.actions.length; i++) {
            var action = CONFIG.actions[i];
            if (!action || !action.id || !action.url) continue;

            var elements = document.querySelectorAll('[data-action="' + action.id + '"]');
            if (!elements.length) continue;

            for (var e = 0; e < elements.length; e++) {
                var el = elements[e];
                if (el.getAttribute('data-button-bounded') === '1') continue;
                el.setAttribute('data-button-bounded', '1');

                (function(element, actionConfig) {
                    element.addEventListener('click', function(evt) {
                        var targetUrl = buildChatUrl(actionConfig);
                        if (targetUrl) {
                            if (targetUrl.indexOf('mailto:') === 0) {
                                evt.preventDefault();
                                window.location.href = targetUrl;
                            } else {
                                window.open(targetUrl, '_blank');
                            }
                        }
                        if (actionConfig.hasWebhook) {
                            fireButtonWebhook(actionConfig.id);
                        }
                    });
                })(el, action);
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', attachChatButtonHandlers);
    } else {
        attachChatButtonHandlers();
    }

    // Retry in case DOM is dynamically modified
    setTimeout(attachChatButtonHandlers, 1000);
    setTimeout(attachChatButtonHandlers, 3000);
})();
```

### MODIFIED: `config/environment/config-defaults.php`

Add to `FRL_ENV_DEFAULT['modules']`:
```php
'chat_buttons' => false,
```

### MODIFIED: `config/environment/config-environment.php`

Add to PBS template (and any other environment that needs chat buttons):
```php
'chat_buttons' => true,
```

---

## 6. Phase-by-Phase Execution

### Phase 1: Extract Shared Utilities

1. Create `includes/helpers/functions-webhook.php` with `frl_send_webhook()` and `frl_should_dedupe_webhook()`
2. Modify `modules/wsform/webhooks.php`:
   - Add `require_once` for the new helper file
   - Replace `frl_wsf_execute_webhook_submission()` with thin wrapper
   - Replace `frl_wsf_should_send_webhook()` with thin wrapper
3. **Test:** Submit a WS Form on staging. Verify webhook still fires to Integrately with identical payload.

### Phase 2: Create Chat Buttons Module

4. Create `modules/chat-buttons/` directory and all files listed above
5. Add `chat_buttons` to `FRL_ENV_DEFAULT['modules']` (default: `false`)
6. Add `chat_buttons` to environment-specific configs where needed

### Phase 3: Enable and Verify

7. Enable `chat_buttons` on staging
8. Add `[data-action="whatsapp"]` and `[data-action="telegram"]` buttons to a test page
9. Click buttons, verify:
   - Deep link opens correctly
   - Webhook fires to CRM with correct payload
   - Dedupe works (second click within 6h is suppressed)
10. Enable on production after staging verification

### Phase 4: Cleanup (After Production Verification)

11. Remove `WS_BUTTON_ACTIONS` from `config-constants-wsform.php`
12. Remove button handlers from `channel-tracking.js`
13. Remove `frl_wsf_button_webhook_handler()` and AJAX hooks from `webhooks.php`
14. Remove chat button config localization from `channel-tracking.php`

---

## 7. Testing Checklist

### Phase 1 Verification

- [ ] WS Form submission fires webhook to Integrately
- [ ] Payload contains all expected fields (Name, Email, Phone, Reference ID, CTA, Service, Channel Source, etc.)
- [ ] Dedupe works (same Reference ID + CTA within 6h = suppressed)
- [ ] `frl_log()` shows no new errors

### Phase 3 Verification

- [ ] Chat buttons render on frontend
- [ ] Clicking WhatsApp button opens `https://wa.me/...` with correct template
- [ ] Clicking Telegram button opens `https://t.me/...` with reference_id
- [ ] `navigator.sendBeacon` fires to `admin-ajax.php?action=frl_chat_button_webhook`
- [ ] CRM receives payload with all channel data
- [ ] Second click within 6h is deduplicated
- [ ] No JavaScript errors in console

---

## 8. Key Design Decisions (For Future Maintainers)

| Decision | Rationale |
|----------|-----------|
| **Shared utilities, not standalone module** | Only 2 webhook consumers. Cache Operations pattern was justified at 6+. Upgrade path documented when 3rd consumer appears. |
| **Attribution stays in wsform** | `channel-tracking.js` is the cookie producer. Chat buttons are a consumer. Producer stays with its primary consumer (WS Form fields). |
| **Same cookie prefix** | `chat-buttons.js` reads `_channel_*` cookies set by `channel-tracking.js`. No duplication. |
| **JS depends on channel-tracking.js** | `frl-chat-buttons` enqueues with `['ws-forms-attribution-tracking']` dependency. Ensures cookies exist before buttons read them. |
| **Environment-aware webhook URLs** | `CHAT_BUTTON_WEBHOOK_CONFIG` follows the `WSFORM_ALL_WEBHOOKS_CONFIG` pattern. Per-brand URLs via environment config. |
| **Nonce intentionally omitted** | Same rationale as existing wsform button handler: CDN caching causes nonce expiration. Protection via dedupe + input sanitization. |
| **Module toggle defaults to false** | Safe deployment: module is opt-in per environment. No accidental production activation. |

---

## 9. Files Created / Modified Summary

### New Files (7)

1. `includes/helpers/functions-webhook.php`
2. `modules/chat-buttons/chat-buttons.php`
3. `modules/chat-buttons/config-constants-chat-buttons.php`
4. `modules/chat-buttons/config-options-chat-buttons.php`
5. `modules/chat-buttons/assets/js/chat-buttons.js`
6. `modules/chat-buttons/includes/class-chat-button-webhook.php`

### Modified Files (4)

1. `modules/wsform/webhooks.php` — thin wrappers for shared utilities
2. `config/environment/config-defaults.php` — add `chat_buttons` module key
3. `config/environment/config-environment.php` — enable for PBS template

### Files to Delete in Phase 4 Cleanup (4)

1. `modules/wsform/config-constants-wsform.php` — remove `WS_BUTTON_ACTIONS`
2. `modules/wsform/assets/js/channel-tracking.js` — remove button handlers
3. `modules/wsform/webhooks.php` — remove `frl_wsf_button_webhook_handler()`
4. `modules/wsform/channel-tracking.php` — remove button config localization
