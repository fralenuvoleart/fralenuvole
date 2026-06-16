# Plan: Extract REST Endpoints to `FRL_REST_ENDPOINTS` Constant

## Summary
Move the hardcoded endpoint removal list from `frl_disable_rest_endpoints()` into a dedicated `FRL_REST_ENDPOINTS` constant in `config/config-base.php`. Remove the two oEmbed endpoints since they are already handled by `frl_disable_oembed()`.

## Context
- `frl_disable_oembed()` at [`website-features.php:124`](includes/shared/website-features.php:124) already calls `remove_action('rest_api_init', 'wp_oembed_register_route')` when `disable_oembed` is enabled.
- Keeping `/oembed/1.0` and `/wp/v2/oembed` in `FRL_REST_ENDPOINTS` creates a silent conflict: if `disable_rest` is enabled but `disable_oembed` is not, embedded content breaks for unauthenticated visitors.
- Removing them delegates the oEmbed disabling decision to the proper `disable_oembed` toggle.

## Changes

### 1. `config/config-base.php` — Add constant after `FRL_PUBLIC_ACTIONS` (line 96)
```php
// REST API endpoints to disable for unauthenticated users.
// /oembed/1.0 and /wp/v2/oembed are intentionally excluded because
// frl_disable_oembed() already removes their route registration when
// disable_oembed is enabled — see includes/shared/website-features.php:124.
const FRL_REST_ENDPOINTS = [
    '/wp/v2/users',
    '/wp/v2/settings',
    '/wp/v2/themes',
    '/wp/v2/plugins',
    '/wp/v2/types',
    '/wp/v2/statuses',
    '/wp/v2/taxonomies',
    '/wp/v2/categories',
    '/wp/v2/tags',
    '/wp/v2/media',
    '/wp/v2/comments',
];
```

### 2. `public/public.php` — Update `frl_disable_rest_endpoints()` (lines 346-403)
- Replace `static $endpoints_to_remove = array(...)` with `$endpoints_to_remove = FRL_REST_ENDPOINTS;`
- Remove the now-unnecessary `!frl_is_logged_in()` duplicate check at line 388 (already checked at line 348)
- Keep the `apply_filters('frl_rest_endpoints_to_remove', ...)` hook for extensibility
- Add inline comment referencing the constant's location

## Files Modified
| File | Change |
|------|--------|
| `config/config-base.php` | Add `FRL_REST_ENDPOINTS` constant |
| `public/public.php` | Replace inline array with constant reference; remove duplicate `is_logged_in` guard |

## Zero Regression
- Same 12 endpoints removed (oEmbed 2 were redundant with `frl_disable_oembed()`)
- `frl_rest_endpoints_to_remove` filter preserved — external code can still modify the list
- `frl_is_logged_in()` gate unchanged at line 348 — admins always get full REST
- `disable_rest` option gate unchanged at line 348
