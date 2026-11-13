# Plugin Debug

Diagnostic tools in `core/debug/`, loaded from `includes/bootstrap.php`. In Developer Tools, "Proprietary Plugin Debugging" has one switch per tool.

Options in this section use the `debugger_*` prefix. Do not name them `debug_*`: `assets/css/admin-ui.css` hides every row whose field name contains `debug_` while WP_DEBUG is unticked (meant for WP_DEBUG_LOG / WP_DEBUG_DISPLAY).

| Tool | Files | Switch |
|---|---|---|
| Error handler | `core/debug/error-handler.php` | Always loaded; settings under "Error reporting" |
| Page-load beacon | `core/debug/beacon.php`, `core/debug/beacon-receiver.php` | `debugger_beacon` ("Enable Page-Load Beacon"), off by default |

## Page-load beacon

### Why it exists

Clarity and GA4 load through Tag Manager, which runs late in the page. A page view that never finishes loading (connection cut or stalled, visitor gives up) leaves no trace in them. The beacon records every page view from its first bytes, so those page views become visible.

### How it works

- `beacon.php` prints a ~1 KB inline script at `wp_head` priority -998, right after the critical CSS (about 3–4 KB into the HTML, well within the first 16 KB). It is wrapped in `<!--noptimize-->` so Autoptimize does not defer it.
- It prints an end marker `<i id="frl-beacon-end" hidden>` as the last thing in the body (`wp_footer`, `PHP_INT_MAX`). If the marker is present when the page is parsed, the whole HTML arrived.
- Runs only on frontend page views (`frl_is_valid_frontend_page_request()`) for visitors who are not logged in.
- Each event is sent with `navigator.sendBeacon` to `core/debug/beacon-receiver.php`, a standalone PHP file (no WordPress load, a few ms per hit) that answers `204` and appends one line to the day's log.

### Log files

- Location: `uploads/fralenuvole/logs/beacon-YYYY-MM-DD.tsv` (the folder from `frl_get_uploads_location( 'logs' )`). The receiver works the path out from its own location, so the two must match.
- One file per UTC day, tab-separated, the first row names the columns.
- Files older than 30 days are deleted automatically; each file is capped at 20 MB.
- Contains IP addresses and identifiers: protect the folder.

### Events

| Event | Meaning |
|---|---|
| `started` | The page's head arrived and the script ran. Every page view starts with this. |
| `finished` | The browser finished reading the whole HTML (`DOMContentLoaded`). Images and other files may still be loading: this is not "page fully loaded". |
| `hidden` | The visitor switched tab or app before `finished`. Not a problem by itself; the page often finishes afterwards. Sent at most once per page view. |
| `abandoned` | The visitor left the page (closed it, navigated away) before `finished`. This is the "gave up" signal. |

### Columns

| Column | Meaning |
|---|---|
| `time_utc` | When the server received the event (UTC). |
| `event` | One of the events above. |
| `view_id` | Random id per page view. All events of one page view share it. |
| `country` | Two-letter country from Kinsta IP Geolocation (`GEOIP_COUNTRY_CODE`). Empty if Geolocation is off (it is on for live only). |
| `browser` | Family guessed from the user agent: `chrome`, `safari`, `firefox`, `edge`, `opera`, `yandex`, `samsung`, `inapp` (Facebook, Instagram, Telegram, Android webviews), `other`. |
| `mobile` | `1` if the user agent looks like a phone, else `0`. |
| `from_ad_click` | `1` if the page URL carried an ad-click id (`gclid`, `gbraid`, `wbraid`, `yclid`), else `0`. |
| `network_estimate` | Chrome's own rough connection estimate (`slow-2g`, `2g`, `3g`, `4g`). Empty in other browsers. Approximate: desktop connections often report `3g`. |
| `ms_since_page_open` | Milliseconds from when the visitor opened the URL to when the event was sent. On `finished`, this is how long the HTML took. `-1` if the browser cannot tell. |
| `html_complete` | Only on `finished`: `1` the whole HTML arrived, `0` the HTML was cut short. Empty on other events. |
| `path` | Page path, without the query string. |
| `ip` | Visitor IP (`REMOTE_ADDR`, which on Kinsta is the real visitor IP). |
| `user_agent` | Full user agent (tabs and line breaks replaced by spaces). |
| `ga_client_id` | `_ga` cookie: links the row to GA4. Empty on a first visit before GA4 has set it. |
| `clarity_user_id` | User id part of the `_clck` cookie: find the visitor's session recordings in Clarity. |
| `reference_id` | `_channel_reference_id` cookie: the Reference ID that is saved with a WS Form submission and appears in Monday. |

### Reading the log

Group rows by `view_id`. Typical page views:

| Events | Reading |
|---|---|
| started → finished (complete 1) | Normal. |
| started → finished (complete 0) | The HTML arrived cut short; the form further down the page may be missing. |
| started → hidden → finished | Visitor switched away while loading; the page finished anyway. Normal. |
| started → abandoned (with or without hidden) | Visitor left before the page finished. |
| started → hidden, nothing after | Ambiguous: the phone may have closed the background tab without telling the page. |
| started, nothing after | The page never finished and no leave signal arrived (connection stalled, browser killed). |

Useful comparisons: the share of page views without `finished` per country, page language (`/ru/`, `/zh/`, `/ar/`, others English), browser, and ad clicks against the rest, on days with few leads against normal days.

### Verify after deploy

1. `curl -d "e=started&v=test" https://<site>/wp-content/plugins/fralenuvole/core/debug/beacon-receiver.php` returns `204` and adds a line to today's file.
2. A logged-out page source contains `beacon-receiver.php` right after the `frl-critical-css` style, and `frl-beacon-end` before `</body>`.
3. Kinsta bot protection has an Allow exception for the receiver path, otherwise some events may be dropped silently.

## Plugin Debug Modes

Debug and troubleshooting modes activated via URL parameter or `wp-config.php` constant.

### Activation

**URL parameter** (temporary, per-request): `?frlmode=<mode>` appended to any page URL. Defines `FRL_MODE` constant in `includes/bootstrap.php:23`.

**wp-config.php** (persistent): `define('FRL_MODE', '<mode>')`. URL parameter takes precedence.

### Modes

| Mode | Effect | Checked In |
|------|--------|------------|
| `disable` | Plugin stops loading entirely. Early return in `includes/bootstrap.php:28` and `fralenuvole.php:31`. Emergency kill-switch. | Before any `FRL_DIR_PATH` usage |
| `core` | Mimics `disable_plugin` option. Core components load but public components and modules are skipped. Cache bypassed via `Frl_Cache_Manager::should_bypass()`. | `fralenuvole.php:84` |
| `nocache` | Bypasses internal cache system. `Frl_Cache_Manager::should_bypass()` returns `true`. WordPress transients and object cache unaffected. | `Frl_Cache_Manager::should_bypass()` |
| `migrate` | Break-glass for domain migrations. `frl_has_access()` returns `true` for all users. Environment Manager enforcement runs for everyone (throttle bypassed). | `frl_has_access()`, `Frl_Environment_Manager::enforce_environment_settings()` |

### Notes

- `disable` is the only mode that stops plugin loading entirely — checked before any `FRL_DIR_PATH` usage
- `migrate` allows environment detection and enforcement without requiring a logged-in admin
- `nocache` only affects the plugin's own cache layer; WordPress transients and object cache are unaffected
