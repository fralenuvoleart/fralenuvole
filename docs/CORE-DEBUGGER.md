# Core - Debugger

Diagnostic tools in `core/debugger/`, loaded from `includes/bootstrap.php`. In Developer Tools, "Proprietary Plugin Debugging" has one switch per tool.

Options in this section use the `debugger_*` prefix. Do not name them `debug_*`: `assets/css/admin-ui.css` hides every row whose field name contains `debug_` while WP_DEBUG is unticked (meant for WP_DEBUG_LOG / WP_DEBUG_DISPLAY).

| Tool | Files | Switch |
|---|---|---|
| Error handler | `core/debugger/error-handler.php` | Always loaded; settings under "Error reporting" |
| Page-load RUM | `core/debugger/rum/rum.php`, `core/debugger/rum/rum-receiver.php` | `debugger_rum` ("Enable Page-Load RUM"), off by default |

## Page-load RUM

### Why it exists

Clarity and GA4 load through Tag Manager, which runs late in the page. A page view that never finishes loading (connection cut or stalled, visitor gives up) leaves no trace in them. The RUM beacon records every page view from its first bytes, so those page views become visible.

### How it works

- `rum.php` prints a ~5.2 KB inline script at `wp_head` priority -998, right after the critical CSS (about 7 KB into the HTML, well within the first 16 KB). It is wrapped in `<!--noptimize-->` so Autoptimize does not defer it.
- It prints an end marker `<i id="frl-rum-end" hidden>` as the last thing in the body (`wp_footer`, `PHP_INT_MAX`). If the marker is present when the page is parsed, the whole HTML arrived.
- Runs only on frontend page views (`frl_is_valid_frontend_page_request()`) for visitors who are not logged in.
- Each event is sent with `navigator.sendBeacon` to `core/debugger/rum/rum-receiver.php`, a standalone PHP file (no WordPress load, a few ms per hit) that answers `204` and appends one line to the day's log.
- Crawlers and headless renderers that do run JavaScript (search bots, Lighthouse, preview fetchers) are rejected in the receiver: they would inflate counts and pass for slow visitors. Real browsers whose user agent carries a crawler-like token stay in — `YandexSearch` (app), `baiduboxapp`, `SogouMobileBrowser`, and phones whose model name contains `BOT` (e.g. `CUBOT`) — so the filter matches whole tokens (`Googlebot/`, `bot;`, `spider`, `GoogleOther`, `google-inspectiontool`, `AdsBot`, `Mediapartners`, `FeedFetcher`, `yandex.com/bots`, `BingPreview`, …), never bare `bot`/`yandex`/`baidu`/`sogou`.

### Log files

- Location: `uploads/fralenuvole/logs/beacon-YYYY-MM-DD.tsv` (the folder from `frl_get_uploads_location( 'logs' )`). The receiver works the path out from its own location, so the two must match. File names keep the `beacon-` prefix although the feature is called RUM: existing files and the `kinsta-logs` skill read `beacon-*.tsv`.
- One file per UTC day, tab-separated, the first row names the columns.
- New columns are appended at the end, so existing columns never shift. On a deploy day the current file keeps its old header until the next UTC day; read by header name and ignore extra trailing fields.
- The UTF-8 scrub of `msg`/`src` needs the `mbstring` extension; without it the fallback truncates at the first invalid byte (safe, but lossy).
- Files older than 30 days are deleted automatically; each file is capped at 20 MB.
- Contains IP addresses and identifiers: protect the folder.

### Events

| Event | Meaning |
|---|---|
| `started` | The page's head arrived and the script ran. Every page view starts with this. |
| `finished` | The browser finished reading the whole HTML (`DOMContentLoaded`). Images and other files may still be loading: this is not "page fully loaded". |
| `hidden` | The visitor switched tab or app before `finished`. Not a problem by itself; the page often finishes afterwards. Sent at most once per page view. |
| `abandoned` | The visitor left the page (closed it, navigated away) before `finished`. This is the "gave up" signal. |
| `js_error` | An uncaught JavaScript error or unhandled promise rejection. At most 5 per view. |
| `vitals` | Web Vitals of that page view, sent once, when the page is hidden or left. A tab left open still reports when the visitor switches away, but the values then stop at that moment. |
| `form_start` | First `input` event inside a lead form: a form that contains an `input[type=email]` field. Once per page view, so only the first form touched is recorded. |
| `submit_attempt` | An attempt started inside a lead form: a click on a submit control (`button[type=submit]`, `input[type=submit]`, or a `<button>` without `type`), or Enter in a single-line text field. Once per attempt, whichever comes first: Enter also fires a click on the default button in Chrome and Safari, and that click is swallowed. |
| `submit_ok` | The form took the submission. On forms without `novalidate` it is sent the moment the browser fires `submit`. On a form with `novalidate` (WS Form sets it) the script waits and probes the result instead: 900 ms after submit it looks for a failure (below); if it finds none it waits another 1.6 s, then reports `submit_ok` — so a server-side rejection arriving up to ~2.5 s later is still caught. The wait ends early if the form is removed from the page, which is what a success message usually does. It still does not mean the server accepted it: a rejection that never reaches the page (silently dropped spam, email failure) is indistinguishable from success. |
| `submit_blocked` | A submit was attempted and validation stopped it. `field` and `msg` name the first failing field; at most 5 per page view. Three paths feed it: an `invalid` event while an attempt is pending (native validation), the 900 ms probe on `novalidate` forms, or the 2.5 s re-probe. Both probes look for `.wsf-invalid` (WS Form's own class) and then `:invalid`, taking the text from `.wsf-invalid-feedback` (inside `.wsf-field-wrapper`) or `validationMessage`. Measured on this host a WordPress AJAX round trip takes 1.0–1.4 s, which is why the first probe alone is not enough. |
| `submit_failed` | An attempt was recorded but no `submit` event followed within 500 ms. Nothing reached the browser's submit stage: the control was handled by script that posts the form itself, or a multi-step form's "Next" button is a submit control that only moves the form on. Sent once per attempt and it closes that attempt, so it is never paired with `submit_ok` or `submit_blocked`; a submit that fires later than 500 ms produces a second, separate verdict for the same attempt. |

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
| `user_agent` | Full user agent (control characters replaced by spaces, leading `= + - @` escaped). |
| `ga_client_id` | `_ga` cookie: links the row to GA4. Empty on a first visit before GA4 has set it. |
| `clarity_user_id` | User id part of the `_clck` cookie: find the visitor's session recordings in Clarity. |
| `reference_id` | `_channel_reference_id` cookie: the Reference ID that is saved with a WS Form submission and appears in Monday. |
| `msg` | On `js_error` the error message; on `submit_blocked` the field's error text (`.wsf-invalid-feedback`, else `validationMessage`; the script sends 120 bytes). Truncated to 300 bytes. |
| `src` | Only on `js_error`: last two path segments of the file that threw (truncated to 200 bytes), or `unhandledrejection`. |
| `line` | Only on `js_error`: the line number, `0` if unknown. |
| `stack` | Only on `js_error`: the first stack frames, truncated to 400 bytes. Empty when the browser gives none (cross-origin script without CORS, most mobile in-app webviews). |
| `lcp` | Only on `vitals`: largest contentful paint, ms from page open (`0` when the browser reports none, e.g. a page with no content element). |
| `cls` | Only on `vitals`: cumulative layout shift, 3 decimals. `0` is a real score, not `unknown`. |
| `inp` | Only on `vitals`: worst interaction duration in ms (`0` when there was no interaction). The `event` entry type exists in Chromium only; other browsers always report `0`. |
| `fcp` | Only on `vitals`: first contentful paint, ms from page open. |
| `utm_source` | Only on `vitals`: `utm_source` of the entry URL, decoded and cleaned to 50 bytes, spaces kept. Empty when the URL had none. |
| `viewport` | Only on `vitals`: `window.innerWidth` in px, to separate phone from desktop LCP/CLS. |
| `field` | Only on `submit_blocked`: `name` (or `id`) of the field that failed validation, 60 bytes. |

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

`vitals` values are a snapshot taken when the page was hidden or left, so they measure the loading phase, not later interactions: compare distributions (p75 of `lcp` per country, share of `cls` above 0.1, `inp` where it is non-zero), not single rows.

### Verify after deploy

1. `curl -d "e=started&v=test" https://<site>/wp-content/plugins/fralenuvole/core/debugger/rum/rum-receiver.php` returns `204` and adds a line to today's file.
2. A logged-out page source contains `rum-receiver.php` right after the `frl-critical-css` style, and `frl-rum-end` before `</body>`.
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
