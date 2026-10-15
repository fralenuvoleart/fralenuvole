# Thirdparty Exclusions

Prevents specified plugins from loading without deactivating them. Uses the MU plugin to filter `active_plugins` before regular plugins load.

**Files:**
- [`assets/mu/frl-mu-plugin.php`](assets/mu/frl-mu-plugin.php) — MU loader bootstrap
- [`includes/mu/functions-mu.php`](includes/mu/functions-mu.php) — All exclusion logic
- [`config/config-mu.php`](config/config-mu.php) — Bot throttle constants

## How It Works

The MU loader runs at `muplugins_loaded` and adds a `pre_option_active_plugins` filter. The filter removes excluded plugins from the `active_plugins` array before WordPress loads them.

## Exclusion Types

| Type | Option | When Applied | Format |
|------|--------|--------------|--------|
| Frontend | `excluded_plugins_frontend` | Frontend context (HTML + AJAX) — all users | Plugin path per line |
| Backend | `excluded_plugins_backend` | Admin context — only on specific admin screens | `plugin-path\|admin-screen` per line |
| Capability | `excluded_plugins_bycap` | Non-frontend context (admin, REST, Cron) — users without required cap | Plugin path per line |

### Backend Format

`plugin-folder/plugin.php|admin-screen` — the screen after `|` is **required**. Uses [`frl_is_admin_page()`](includes/helpers/functions-access-control.php:138) for matching (filename-based like `post.php` or page slug-based).

## Behavior Matrix

| Context | Exclusion | Result |
|----------|-----------|--------|
| Frontend | Frontend list | BLOCKED |
| Admin — screen matches | Backend list | BLOCKED |
| Admin — screen doesn't match | Backend list | LOADS |
| Admin — has cap | Capability list | LOADS |
| Admin — no cap | Capability list | BLOCKED |
| REST — has cap | Capability list | LOADS |
| REST — no cap | Capability list | BLOCKED |
| Cron | Capability list | BLOCKED — orphaned events filtered via `option_cron` |

## Early-Loading Access Check

Capability-based exclusion runs during `muplugins_loaded` (before `plugins_loaded`). [`frl_mu_check_access()`](includes/mu/functions-mu.php:75) handles this:

1. If `plugins_loaded` has fired → delegates to [`frl_has_access()`](includes/helpers/functions-access-control.php:25)
2. If early loading → uses [`frl_get_auth_cookie_user_data()`](includes/mu/functions-mu.php:115) to verify the auth cookie via HMAC (replicates `wp_validate_auth_cookie()` algorithm using `LOGGED_IN_KEY`/`LOGGED_IN_SALT`), then queries DB directly

Does NOT check `WP_Session_Tokens` revocation — accepted limitation since this only gates a soft plugin-visibility decision.

## Caching

- `active_plugins` cached via `frl_cache_remember()` in `options` group with `WEEK_IN_SECONDS` TTL
- Network active plugins have separate cache entry (`mu_plugin_network_active_plugins`)
- Three levels of static per-request dedup
- Invalidated on `activated_plugin`/`deactivated_plugin` via [`cache-cleanup.php`](core/cache/cache-cleanup.php)

## Bot Throttling

[`frl_maybe_throttle_user_agent()`](includes/mu/functions-mu.php:31) checks User-Agent against [`FRL_MU_THROTTLE_USER_AGENT`](config/config-mu.php:23) patterns. Rate-limited by IP via transients. Configured via:

```php
FRL_MU_THROTTLE_USER_AGENT = [];  // Empty = disabled
FRL_MU_THROTTLE_LIMIT = 10;       // Max requests per window
FRL_MU_THROTTLE_PERIOD = 60;      // Window in seconds
FRL_MU_THROTTLE_STATUS_CODE = 429; // HTTP status on limit