# Diagnose: Redirect Blank Page After Host Migration

## Evidence From Server Logs

```
GET "...?frl_action=delete_orphan_options..." HTTP/2.0 200 ... 211B ... 0.317s
BYPASS KINSTAWP
```

- **HTTP 200** (not 302) — `Location` header was never received by Nginx
- **211 bytes body** — the blank page
- **0.317s** — request completed normally (no timeout)
- **No errors in error.log** — PHP didn't crash
- **Action executed** — admin notice visible when hitting Back

## Root Cause

`wp_safe_redirect()` at [`includes/helpers/functions.php:751`](includes/helpers/functions.php:751) was reached but `header("Location: ...")` failed silently because PHP response headers were already committed before `init/10` when [`frl_process_plugin_actions()`](includes/helpers/functions-action-handlers.php:24) fires.

The plugin's action handler path has zero output-producing code. The header commitment comes from outside this plugin — another plugin's code, a PHP-level byte, or PHP-FPM's buffer state.

## Environmental Trigger

| | ChemiCloud (working) | Kinsta (broken) |
|---|---|---|
| Web server | LiteSpeed (Apache SAPI) | Nginx + PHP-FPM |
| `output_buffering` | On (4096) | Off (default) |
| Effect of stray byte | Buffered → redirect works | Headers committed → redirect fails |

On ChemiCloud, the Apache SAPI buffered any stray output, so `header("Location: ...")` always worked. On Kinsta's PHP-FPM with `output_buffering = Off`, a single stray byte from any source commits headers before `init/10`, causing `wp_safe_redirect()` to silently fail.

## Proposed Fix: Diagnostic Instrumentation

Add `headers_sent()` logging in [`frl_safe_redirect()`](includes/helpers/functions.php:708) to identify the exact file and line causing header commitment:

### File: [`includes/helpers/functions.php`](includes/helpers/functions.php:750)

**Before** line 751 (`wp_safe_redirect`):

```php
// Diagnostic: log what committed headers before redirect
if (headers_sent($file, $line)) {
    error_log(sprintf(
        '[%s] REDIRECT BLOCKED: headers already sent by %s:%d',
        FRL_NAME,
        $file,
        $line
    ));
}
```

### What This Reveals

Once deployed, `error.log` will contain:
```
[Fralenuvole] REDIRECT BLOCKED: headers already sent by /path/to/some-plugin.php:42
```

This definitively identifies:
- Which file output content first
- The exact line number
- Whether it's a WordPress core file, another plugin, or this plugin

### Follow-up After Diagnosis

Depending on the logged file:
- **Another plugin's file** → that plugin has a stray byte or echo; fix or exclude it
- **WordPress core file** → may need `ob_start()` fallback
- **This plugin's file** → fix the specific line
- **`Unknown:0`** → PHP-FPM output (e.g., `X-Powered-By` header); needs `output_buffering = On` or `ob_start()` guard
