# Kinsta MU-Plugin Reference

Third-party reference for Kinsta's MU plugin (v3.7.0) caching behavior. This plugin runs on Kinsta infrastructure and interacts with Kinsta's cache purge system.

## Cache Purge Types

| Type | Mechanism |
|------|-----------|
| Selective URL (Immediate) | POST to `https://localhost/kinsta-clear-cache/v2/immediate` |
| Selective URL (Throttled) | POST to `https://localhost/kinsta-clear-cache/v2/throttled` |
| Site (Full Page) | GET to `https://localhost/kinsta-clear-cache-all` |
| CDN/Edge | GET to `https://localhost/kinsta-clear-cache-cdn` |
| Object Cache | `wp_cache_flush()` |

Manual single URL: `https://pbservices.ge/kinsta-clear-cache/my-page/`

## Key Constants

| Constant | Effect |
|----------|--------|
| `KINSTAMU_DISABLE_AUTOPURGE` | When `true`, disables all automatic cache purges globally |
| `KINSTAMU_CACHE_PURGE_TIMEOUT` | cURL timeout for purge endpoints (default: 5s) |
| `KINSTAMU_CAPABILITY` | WP capability required for cache purge options (default: `manage_options`) |

### Autopurge Gate

`is_autopurge_enabled()` checks in order:
1. `KINSTAMU_DISABLE_AUTOPURGE === true` → disabled (hard kill-switch)
2. `kinsta-autopurge-status` option = `'disabled'` → disabled
3. Otherwise → enabled

Manual purges (admin AJAX, admin bar, WP-CLI with `--all`) bypass this gate via `$force = true`.

## Automatic Complete Cache Purges

Triggered by: navigation menu updates, term edits/deletes, option changes (`blogname`, `blogdescription`, `date_format`, `time_format`, `language`), theme switches, theme updates, custom header changes, ACF options page saves, Elementor cache clears.

All gated by global autopurge and per-controller toggle. Deduplicated once per request.

## Selective URL-Based Purges

Triggered by: post publish/unpublish/update/trash, comment insert/edit/status change, WooCommerce stock changes.

Purges: post permalink, home page, blog page, author/term/date/post type archives (first page immediate, remaining pages throttled at depth 3), RSS feed, sitemap, AMP variants, custom paths.

## Custom Action Hooks (Fired by Kinsta)

| Hook | When |
|------|------|
| `kinsta_purge_complete_caches_happened` | After Object + Site + CDN all purged (once per request) |
| `kinsta_initiate_purge_happened` | After selective URL-based purge sent |

## Third-Party Plugin Compatibility

Kinsta's MU plugin disables WP Rocket page caching, Swift Performance caching, and Wordfence live traffic logging to prevent conflicts with its own caching layer.