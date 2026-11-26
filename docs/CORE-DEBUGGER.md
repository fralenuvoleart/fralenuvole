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

- `rum.php` prints a ~7.9 KB inline script at `wp_head` priority -998, right after the critical CSS (about 10.7 KB into the HTML, well within the first 16 KB). It is wrapped in `<!--noptimize-->` so Autoptimize does not defer it.
- It prints an end marker `<i id="frl-rum-end" hidden>` as the last thing in the body (`wp_footer`, `PHP_INT_MAX`). If the marker is present when the page is parsed, the whole HTML arrived.
- Runs only on frontend page views (`frl_is_valid_frontend_page_request()`) for visitors who are not logged in.
- Each event is sent with `navigator.sendBeacon` to `core/debugger/rum/rum-receiver.php`, a standalone PHP file (no WordPress load, a few ms per hit) that answers `204` and appends one line to the day's log.
- Crawlers and headless renderers that do run JavaScript (search bots, Lighthouse, preview fetchers) are rejected in the receiver: they would inflate counts and pass for slow visitors. Real browsers whose user agent carries a crawler-like token stay in — `YandexSearch` (app), `baiduboxapp`, `SogouMobileBrowser`, and phones whose model name contains `BOT` (e.g. `CUBOT`) — so the filter matches whole tokens (`Googlebot/`, `bot;`, `spider`, `GoogleOther`, `google-inspectiontool`, `AdsBot`, `Mediapartners`, `FeedFetcher`, `yandex.com/bots`, `BingPreview`, …), never bare `bot`/`yandex`/`baidu`/`sogou`.

### Log files

- Location: `uploads/fralenuvole/logs/beacon-YYYY-MM-DD.tsv` (the folder from `frl_get_uploads_location( 'logs' )`). The receiver works the path out from its own location, so the two must match. File names keep the `beacon-` prefix although the feature is called RUM: existing files and the `kinsta-logs` skill read `beacon-*.tsv`.
- One file per UTC day, tab-separated, the first row names the columns.
- New columns are appended at the end, so existing columns never shift. On a deploy day the current file keeps its old header until the next UTC day; read by header name and ignore extra trailing fields. Columns 28 to 41 (`ttfb` to `build`) were added in November 2026, followed by columns 42 to 46 (`messenger_channel` to `transfer_kb`); `transfer_kb` stays last because the contract test pins it.
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
| `js_error` | An uncaught JavaScript error or unhandled promise rejection. An error that carries neither a message nor a stack is not sent and does not count toward the limit: that is the cross-origin "Script error." class, where the browser blanks both and nothing can be acted on. At most 5 per view; later errors are counted in `errors_dropped` and reported with `vitals`. |
| `vitals` | Web Vitals of that page view, sent once, when the page is hidden or left. A tab left open still reports when the visitor switches away, but the values then stop at that moment. |
| `form_start` | First `input` event inside a lead form: a form that contains an `input[type=email]` field. Only real input counts (`isTrusted`): a value set by a script and dispatched as a synthetic event is ignored, which is what the channel-tracking autofill does on every page. `input_type` says how that first fill happened. Once per page view, so only the first form touched is recorded. |
| `form_typed` | Once per page view, only when it adds something: the visitor typed (`input_type` of that keystroke is `insertText`) after a `form_start` whose own `input_type` was not `insertText` — i.e. the view began with autofill or a paste and the visitor then typed. “Really typed” is `form_typed`, or a `form_start` whose `input_type` is `insertText`; `form_start` alone only means a trusted input happened. |
| `submit_attempt` | An attempt started inside a lead form: a click on a submit control (`button[type=submit]`, `input[type=submit]`, or a `<button>` without `type`), or Enter in a single-line text field. Once per attempt, whichever comes first: Enter also fires a click on the default button in Chrome and Safari, and that click is swallowed. If the page cancels the Enter keypress (`defaultPrevented`), nothing is logged — WS Form does this when the form's `submit_on_enter` is off, and Enter then does not submit at all. |
| `submit_ok` | The submission was accepted. On a WS Form form (a `<form>` whose class contains `wsf-form`) the script listens for the plugin's own `wsf-submit-success` event, which the plugin fires only after the server accepted the POST, and reports it once; nothing is inferred from the page. On other forms it is sent as soon as the browser fires `submit`, unless the form carries `novalidate` (then the probes below decide). |
| `submit_blocked` | A submit was attempted and did not go through. `submit_why` says who stopped it: `browser` (the browser's own validation refused to send it, from an `invalid` event or the 900 ms / 2.5 s probes on `.wsf-invalid` / `:invalid`), `client` (WS Form's own client-side validation failed before the request was sent), `server` (the request was sent and the reply re-entered the validation path), `error` (the request itself failed). `field` names the control that failed, when the plugin or the browser reports one. At most 5 per page view. |
| `submit_failed` | An attempt was recorded but no `submit` event followed within 500 ms. Nothing reached the browser's submit stage: the control was handled by script that posts the form itself, or a multi-step form's "Next" button is a submit control that only moves the form on. Sent once per attempt and it closes that attempt, so it is never paired with `submit_ok` or `submit_blocked`; a submit that fires later than 500 ms produces a second, separate verdict for the same attempt. |
| `submit_unresolved` | A WS Form submission passed validation but no answer arrived, so it counts neither as submitted nor as failed. `msg=unload`: the visitor left (or the tab was discarded) before the server responded. `msg=timeout`: the page stayed open 15 s after validation with neither `wsf-submit-success` nor `wsf-submit-error`, i.e. a hung request or stalled scripts. `msg=stale`: 20 s passed with no verdict at all, so the submission was dropped and the next one starts clean. |
| `messenger_click` | Trusted click on a Telegram or WhatsApp CTA (`[data-action="telegram"\|"whatsapp"]`, `[data-cta-url]`, or `<a href>` targeting `t.me`/`telegram.me`/`tg:` or `wa.me`/`whatsapp.com`/`whatsapp:`). Deduplicated to at most one event per `(view_id, messenger_channel)`. |

### Columns

| Column | Meaning |
|---|---|
| `time_utc` | When the server received the event (UTC). |
| `event` | One of the events above. |
| `view_id` | Random id per page view. All events of one page view share it. |
| `country` | Two-letter country from Kinsta IP Geolocation (`GEOIP_COUNTRY_CODE`). Empty if Geolocation is off (it is on for live only). |
| `browser` | Family guessed from the user agent: `chrome`, `safari`, `firefox`, `edge`, `opera`, `yandex`, `samsung`, `inapp` (Facebook, Instagram, Telegram, Android webviews), `other`. |
| `mobile` | `1` if the user agent looks like a phone, else `0`. |
| `from_ad_click` | `1` if the page URL carried an ad-click id (`gclid`, `gbraid`, `wbraid`, `yclid`, `fbclid`), else `0`. |
| `network_estimate` | Chrome's own rough connection estimate (`slow-2g`, `2g`, `3g`, `4g`). Empty in other browsers. Approximate: desktop connections often report `3g`. |
| `ms_since_page_open` | Milliseconds from when the visitor opened the URL to when the event was sent. On `finished`, this is how long the HTML took. `-1` if the browser cannot tell (this is also what the receiver treats it as: 0). |
| `html_complete` | On `finished`, and on `hidden`/`abandoned` once `document.readyState !== "loading"`: `1` the whole HTML arrived (`#frl-rum-end` present), `0` the HTML was cut short. Empty while `readyState === "loading"` and on other events. |
| `path` | Page path, without the query string. |
| `ip` | Visitor IP (`REMOTE_ADDR`, which on Kinsta is the real visitor IP). |
| `user_agent` | Full user agent (control characters replaced by spaces, leading `= + - @` escaped). |
| `ga_client_id` | `_ga` cookie: links the row to GA4. Empty on a first visit before GA4 has set it. |
| `clarity_user_id` | User id part of the `_clck` cookie: find the visitor's session recordings in Clarity. |
| `reference_id` | `_channel_reference_id` cookie: the Reference ID that is saved with a WS Form submission and appears in Monday. |
| `msg` | On `js_error` the error message; on `submit_unresolved` `unload`, `timeout` or `stale`. Truncated to 300 bytes. Never used on `submit_blocked`: `submit_why` and `field` carry that, without the prose. |
| `src` | Only on `js_error`: last two path segments of the file that threw (truncated to 200 bytes), or `unhandledrejection`. |
| `line` | Only on `js_error`: the line number, `0` if unknown. |
| `stack` | Only on `js_error`: the first stack frames, truncated to 400 bytes. Empty when the browser gives none (cross-origin script without CORS, most mobile in-app webviews). |
| `lcp` | Only on `vitals`: largest contentful paint, ms from page open. Empty when the browser does not report LCP entries (Safari and every iPhone browser before Safari 26.2); `0` only if the entry type exists but no entry arrived. |
| `cls` | Only on `vitals`: cumulative layout shift, 3 decimals. `0` is a real score, not `unknown`. Empty when the browser does not report layout shifts. |
| `inp` | Only on `vitals`: worst interaction duration in ms, from interactions only (`interactionId` above 0, so scrolling and hovering do not count). Empty when the view had no interaction, and empty in every browser without the Event Timing API (Safari before 26.2). Never `0`. |
| `fcp` | Only on `vitals`: first contentful paint, ms from page open. Empty when the browser does not report paint entries. |
| `utm_source` | On `started`, `vitals`, `messenger_click`, and `submit_ok`: `utm_source` of the entry URL, UTF-8 cleaned to 50 bytes, spaces kept. Empty when the URL had none. |
| `viewport` | Only on `vitals`: `window.innerWidth` in px, to separate phone from desktop LCP/CLS. |
| `field` | Only on `submit_blocked`: `name` (or `id`) of the field that failed validation, 60 bytes. On WS Form forms the plugin marks the section wrapper rather than the input, so the printer goes down to the `.wsf-invalid` / `:invalid` control inside it; when even that is missing the value is the section id (`wsf-1-section-25`) and the row still says which form. |
| `ttfb` | Only on `vitals`: ms from the navigation start to the first byte of the page, i.e. the whole server wait including a redirect. Empty on back/forward navigations, when the browser has no navigation timing, or when `responseStart` is 0. |
| `redirect` | Only on `vitals`: ms spent in redirects. Empty when there was none, or when it crossed origins and the browser hides it (`redirectStart` is 0). |
| `dns` | Only on `vitals`: ms looking up the host name. Empty when the browser reused a connection (nothing to look up). |
| `connect` | Only on `vitals`: ms opening the connection, TLS included. Empty when the connection was reused. |
| `tls` | Only on `vitals`: ms of the TLS handshake, part of `connect`. Empty on plain `http://` and reused connections. |
| `wait` | Only on `vitals`: ms from the request leaving to the first response byte, i.e. the server without network setup. Empty when `requestStart` is 0. |
| `page_age` | On `started` and `vitals`: seconds between the server rendering the page and receiving the event (both clocks are the server's). A large value means the HTML was cached; 0-2 s means it was built for that request. Read cache status from the `started` row: the `vitals` row also contains however long the visitor kept the page. Empty on the other events. |
| `click_id` | Which ad-click id the URL carried (`gclid`, `gbraid`, `wbraid`, `yclid`, `fbclid`), on every event of that view. Only the name is sent, never the value. Empty when there was none. |
| `error_source` | Only on `js_error`: `own` (a file from this site other than the page), `inline` (the page's own HTML), `third` (another origin). Empty on `unhandledrejection` (the origin is not reported) and when the browser gives no filename. |
| `errors_dropped` | Only on `vitals`: how many errors were discarded after the 5-per-view limit, so a row that errored 200 times is still visible. Empty when none were dropped. |
| `submit_why` | Only on submit events. `submit_ok`: `native` when the form carried no `novalidate` and the browser's validation let it through (a plain form, not confirmed by any server); empty on a WS Form answer that really succeeded. `submit_blocked`: `browser`, `client`, `server` or `error` (see the event above). Empty elsewhere. |
| `form_ref` | Only on submit events: the WS Form Reference ID the form carried (`field_232`), which is the id saved with the submission and shown in Monday. It joins a row to the item it produced. Empty on pages with no such field. |
| `input_type` | Only on `form_start`: the `beforeinput` type of the first fill — `insertText` (typing), `insertFromPaste`, `insertReplacementText` (autofill, autocorrect or a suggestion), `insertCompositionText` (IME), `insertFromDrop`, `insertFromYank`, `insertLineBreak`. `none` means no `beforeinput` preceded the event, which is how browser autofill usually arrives (Chrome, Safari) and also how it looks on a browser without the `beforeinput` event. Empty elsewhere. |
| `build` | Every row: the printer file's modification time in base36, so a row written by a cached page still running an older script can be told from a fresh one. Changes on every upload of `rum.php`, without anyone having to remember to bump it. |
| `messenger_channel` | Only on `messenger_click`: `telegram` or `whatsapp` (whitelisted). Empty elsewhere. |
| `ready_state` | Only on `hidden` and `abandoned`: `document.readyState` (`loading`, `interactive`, or `complete`). Empty elsewhere. |
| `slowest_pending_host` | Only on `hidden` and `abandoned`: hostname (max 60 bytes, no path/query, excluding `rum-receiver.php` and `beacon`/`ping` initiators) of the longest-waiting in-flight resource (`responseEnd === 0`), or if none, the slowest completed resource over 1,000 ms. Empty when none qualifies. |
| `utm_campaign` | On `started`, `vitals`, `messenger_click`, and `submit_ok`: raw `utm_campaign` query parameter, UTF-8 cleaned to 80 bytes (tabs/newlines stripped, leading formula chars escaped). Empty when absent. |
| `transfer_kb` | Only on `finished` and `vitals`: integer KB of the document navigation entry (`Math.round(nav.transferSize / 1024)`), `0` when served from browser cache, empty when unsupported. |

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

`vitals` values are a snapshot taken when the page was hidden or left, so they measure the loading phase, not later interactions: compare distributions (p75 of `lcp` per country, share of `cls` above 0.1, `inp` over the rows where it is present), not single rows. An empty `inp` means "no interaction" or "browser cannot measure", not zero, and an empty `cls` means the browser cannot measure it: neither counts as a good score. Compare `page_age` of a few seconds against the rest for cached versus freshly built pages (use the `started` row; the `vitals` row adds the time the visitor kept the page open), and compare INP across browsers only as a hint (Safari has measured it since 26.2 only).

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
