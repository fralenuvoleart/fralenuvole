# Project Progress

## Recent Updates (v5.4.0)
- Plugin Exclusion Feature: MU-based loader to prevent specified plugins from loading without deactivating them
  - **Frontend exclusion**: applies to all users in frontend context
  - **Backend exclusion**: applies to all users in admin context, filtered by admin screen via `plugin-path|admin-screen` format
  - **Capability exclusion**: applies in non-frontend contexts for users without required cap
- **Fixed: Cron `invalid_schedule` error when excluded plugins have cron events**
  - Added shared `frl_get_exclusion_options()` fetching both `active_plugins` and `cron` in one DB query
  - Refactored `pre_option_active_plugins` to use shared function (no behavior change)
  - Added `pre_option_cron` filter during WP Cron that removes orphaned events with unregistered schedules
- **Backend exclusion wired in** — reads `excluded_plugins_backend_enabled` / `excluded_plugins_backend` options
  - Uses existing `frl_is_admin_page()` helper for screen matching
  - Uses existing `frl_textlist_to_array()` helper (already parses `|` pipe format)
  - Admin screen after `|` is **required** (exclusion only activates on matching screen)
- **Refactored MU plugin structure:**
  - `assets/mu/frl-mu-plugin.php` → thin bootstrap (constant + bootstrap require + hook registration)
  - `includes/helpers/functions-mu-plugin.php` → all exclusion logic (moved from MU plugin)
  - Loaded only by the MU plugin, not polluting the main plugin's helper load
  - Updated `docs/PLUGIN-EXCLUSIONS-FEATURE.md` with new file references
- Translation Module Refactor:
  - Implemented Adapter Pattern for translation providers (Polylang/WPML).
  - Added strict typing to `field-translator.php`.
  - Introduced configurable delimiters and registration queue limits for stability.
  - Fixed language-scoping bugs in translation caching.
  - Optimized performance by deferring string registration to the `shutdown` hook.
- **MU Plugin Performance Optimization:**
  - Added persistent caching of `active_plugins` via `frl_cache_remember('options', 'mu_plugin_active_plugins', callback, WEEK_IN_SECONDS)`:
    - Split the combined DB query (formerly `active_plugins` + `cron` in one query)
    - `active_plugins` now cached in `options` group with WEEK_IN_SECONDS TTL (changes only on plugin activation/deactivation)
    - `cron` stays fetched fresh per request (too volatile — changes on every WP Cron execution)
  - Added persistent caching of network active plugins via `frl_cache_remember('options', 'mu_plugin_network_active_plugins', callback, WEEK_IN_SECONDS)`:
    - Wraps the existing `$wpdb->get_var()` to `wp_sitemeta` (safe — cache layer never touches the `pre_site_option` filter chain)
    - Direct DB callback still used as the cache miss fallback to prevent recursion
  - Added cache invalidation in `cache-cleanup.php` via `activated_plugin`/`deactivated_plugin` hooks
  - Verified: no recursion risk (`frl_cache_remember` uses object cache/transients, never `get_option()` on the filtered options)

### Fixes Applied (pending user confirmation)
- **Fixed `index.php` dashboard screen matching** — `$pagenow` is null during `muplugins_loaded` because `wp-includes/vars.php` loads at `wp-settings.php:524`, after `muplugins_loaded` at line 511. Added `$_SERVER['SCRIPT_NAME']` fallback to `frl_is_admin_page()`.
- **Added cron args sanitization** — Ensures `$event['args']` is always an array in `pre_option_cron` filter to prevent `TypeError: count(): Argument #1 must be of type Countable|array, null given` at `class-wp-hook.php:325`.
- **Fixed cron filter early-exit bug** — Cron filter was gated behind `if (!empty($excluded))`, so it was never added during cron when only backend exclusion was enabled (capability exclusion disabled). Moved cron filter addition before the empty-exclusion check so it always registers during WP Cron, ensuring args sanitization runs unconditionally.

---
*Last Updated: 2026-04-25*
