# Plugin Permissions

Documents the plugin's access control model — capabilities, nonce verification, admin bar gating, action dispatch, and `FRL_MODE` behavior.


## Constants

| Constant | Value | Defined in |
|----------|-------|------------|
| `FRL_PLUGIN_SUPERADMIN_ID` | `1` | `config/config-base.php:40` |
| `FRL_PLUGIN_ACCESS` | `'delete_plugins'` | `config/config-base.php:41` |
| `FRL_MODE` | `null` / `'disable'` / `'core'` / `'nocache'` / `'migrate'` | URL param `?frlmode=` or `wp-config.php` constant |


## `FRL_MODE` Behavior

Defined via `?frlmode=<mode>` URL parameter or `define('FRL_MODE', '<mode>')` in `wp-config.php`. URL parameter takes precedence.

| Mode | Effect on Access Control |
|------|------------------------|
| *(not set)* | Normal operation — all capability checks enforced |
| `disable` | Plugin stops loading entirely (early return in `fralenuvole.php`) |
| `core` | Mimics `disable_plugin` option — most features disabled |
| `nocache` | Bypasses internal cache system; access control unchanged |
| `migrate` | `frl_has_access()` returns **true** for everyone; environment enforcement runs for all users |


## Access Tiers

### Superadmin (User ID 1)

User ID 1 bypasses **all** capability checks. `frl_has_access()` returns `true` immediately for this user regardless of the `$capability` argument.

- Full access to every plugin feature
- Admin bar: sees the plugin menu, debug log count, cache links, and CPT quick-links
- The `'superadmin'` pseudo-capability is the **only** check that is exclusive to User ID 1

### Plugin Admin (`delete_plugins` capability)

Any WordPress role that holds the `delete_plugins` capability. By default this means **Administrators** (the built-in `administrator` role includes `delete_plugins`).

- Plugin settings page (all tabs)
- Debug log viewing/clearing/download
- Cache management (most buttons)
- Environment switcher
- Import/export
- Admin bar: plugin menu, debug log, cache links (except Light/All — superadmin only)

### Not Logged In

No access to any plugin features.


## Core Function: `frl_has_access()`

**File:** `includes/helpers/functions-access-control.php:25`

```php
function frl_has_access( $capability = FRL_PLUGIN_ACCESS ): bool
```

**Decision flow:**

1. `FRL_MODE === 'migrate'` → **true** (break-glass bypass)
2. `current_user_can()` not available → **false** (too early in WP load)
3. `$capability` is falsy (empty string, `null`, `0`) → falls back to `FRL_PLUGIN_ACCESS`
4. User ID === `FRL_PLUGIN_SUPERADMIN_ID` (1) → **true**
5. `$capability === 'superadmin'` → **true** only for User ID 1
6. Otherwise → `$user->has_cap($capability)`, cached **300 seconds** in the `admin` cache group

**Usage patterns:**

```php
frl_has_access()                  // Default: delete_plugins
frl_has_access( '' )              // Same as above (falsy → falls back)
frl_has_access( 'superadmin' )    // User ID 1 only
frl_has_access( 'manage_options' ) // Specific capability
frl_has_access( 'publish_pages' )  // CPT-specific (admin bar links)
```

## Context Detection Functions

All in `includes/helpers/functions-access-control.php`:

| Function | Returns | Purpose |
|----------|---------|---------|
| `frl_is_admin()` | `bool` | Enhanced `is_admin()` — covers `WP_ADMIN`, `admin-post.php`, admin-originated AJAX, login/register pages |
| `frl_is_admin_page($page, $param)` | `bool` | Checks if current request is a specific admin page (by slug or filename) |
| `frl_is_plugin_context()` | `bool` | True when on plugin settings page (`?page=fralenuvole`) or processing a `frl_`-prefixed action |
| `frl_is_administrator_action()` | `bool` | True for `admin-post.php` requests, `?frl_action=...` with access, or AJAX with `?action=...` and access |
| `frl_is_logged_in()` | `bool` | Cached current-user check |
| `frl_is_valid_page_request()` | `bool` | False for CLI, REST, CRON, invalid hosts, heartbeat, log manager, non-HTML |
| `frl_is_valid_frontend_page_request()` | `bool` | `!frl_is_admin() && frl_is_valid_page_request()` |


## Nonce System

### Creating Nonces

```php
// Prefixed nonce (action becomes "frl_my_action")
frl_create_nonce( 'my_action' );              // → wp_create_nonce('frl_my_action')

// Prefixed nonce field (hidden input)
frl_nonce_field( 'save_options', '_wpnonce' ); // → wp_nonce_field('frl_save_options', '_wpnonce')
```

Both defined in `includes/helpers/functions.php:864`.

### Verifying Nonces

All verification flows through `_frl_verify_nonce_core()`, which:
1. Picks the correct superglobal (`$_GET`, `$_POST`, or `$_REQUEST`)
2. Checks the nonce field exists
3. Calls `wp_verify_nonce()`
4. Optionally checks capability via `frl_has_access()`

**Public wrappers:**

| Function | File:Line | Use Case |
|----------|-----------|----------|
| `frl_verify_simple_nonce()` | `:966` | Static action name, auto-prefixed. Default cap: `FRL_PLUGIN_ACCESS` |
| `frl_verify_dynamic_nonce()` | `:992` | Pattern-based action with `{placeholder}` replacement |
| `frl_verify_plugin_action_nonce()` | `:816` | Plugin action dispatch — requires `FRL_PLUGIN_ACCESS` |
| `frl_verify_admin_action_nonce()` | `:853` | Settings form save — no capability check, custom redirect on failure |

## Action Button Rendering

**File:** `admin/helpers/functions-admin-ui.php:59`

```php
frl_render_action_button(
    $action,        // Action name (e.g., 'clear_cache_all')
    $class_name,    // CSS classes
    $label,         // Button text
    $description,   // Tooltip
    $url,           // Target URL (default: plugin admin page)
    $cap            // Required capability (default: FRL_PLUGIN_ACCESS)
): string
```

**Capability parameter behavior:**

| `$cap` value | Effect |
|-------------|--------|
| `FRL_PLUGIN_ACCESS` (default) | Requires `delete_plugins` |
| `''` (empty string) | Falls back to `FRL_PLUGIN_ACCESS` |
| `'skip_nonce'` | Renders for any logged-in user, **no nonce** added to URL |
| `'manage_options'` | Requires that specific capability |
| `'superadmin'` | User ID 1 only |

Buttons with `button-primary` class get a JavaScript `confirm()` dialog.


## Admin Bar

**File:** `includes/main/logged-user.php:45`

The admin bar menu is gated by **both**:
- `frl_has_access()` (plugin admin or better)
- `custom_ab_menu` option enabled

### Menu Structure

**Primary menu** (always visible when gated):
- Plugin name → links to plugin settings page
- Debug Log entry (with count bubble if logs exist)
- PageSpeed link (per-page, never cached)
- Schema Validator link (per-page, never cached)

**Cache action links** (capability-gated):

| Action | Capability Required |
|--------|-------------------|
| Clear CSS/JS Caches | `''` → `delete_plugins` |
| Clear Shortcodes Caches | `''` → `delete_plugins` |
| Clear Caches (Light) | `'superadmin'` |
| Clear Caches (All) | `'superadmin'` |
| Warm Server Cache | `''` → `delete_plugins` |

**CPT quick-links** (under "New" menu):
- All Pages → `publish_pages`
- All Posts → `edit_posts`
- All Media → `upload_files`

**Node removals:** When `ab_remove_links` is enabled, non-plugin-admin users get additional nodes removed (configured via `ab_remove_links_handles_user` option).


## Action Dispatch

### GET Actions (`?frl_action=...`)

**File:** `includes/helpers/functions-action-handlers.php:24`

Hooked at `init:12`. Processes actions from admin bar cache links and other GET-based triggers.

**Access check:**
```php
frl_has_access()
```

Registered actions: `clear_dashboard`, `clear_cache_light`, `clear_cache_all`, `clear_wp_alloptions`, `clear_plugin_transients`, `clear_website_transients`, `clear_scripts_tags`, `clear_shortcodes`, `reset_environment`, `reset_environment_ignored`, `reset_debug_config`, `reset_plugin`, `delete_mu_plugins`, `delete_orphan_options`, `sync_mu_plugins`, `flush_rewrite_rules`, `trigger_cache_warmer`, plus dynamic `clear_cache_{group}`.

### Admin-Post Actions (`frl_post_*`)

**File:** `admin/helpers/functions-admin-action-handlers.php:24`

Auto-discovered: any function named `frl_post_*` is registered as an `admin_post_` handler. Functions with `frl_post_ajax_*` prefix are also registered as `wp_ajax_` handlers.

Gated by: `frl_is_admin() && frl_is_administrator_action()`.

Each handler is responsible for its own nonce + capability verification (typically via `frl_verify_plugin_action_nonce()` or direct `frl_has_access()` calls).


## Extension Points

### Filter: `frl_is_section_restricted`

**File:** `admin/components/class-settings-fields.php:300`

```php
apply_filters( 'frl_is_section_restricted', false, $section_id ): bool
```

Return `true` to hide a settings section from the current user. Used to gate sensitive configuration sections behind additional capability checks.

### Filter: `frl_admin_dashboard_links`

**File:** `admin/widgets/widget-administrator.php:16`

```php
apply_filters( 'frl_admin_dashboard_links', $links ): array
```

Add or modify the administrator widget's quick-link sections.

### Action: `frl_autodiscover_admin_actions`

**File:** `admin/helpers/functions-admin-action-handlers.php:74`

Fires after auto-discovery registers all `frl_post_*` handlers. Use to register additional custom handlers.


## Security Notes

- All capability checks use `frl_has_access()`, which caches results for 300 seconds in the `admin` cache group
- The `'superadmin'` pseudo-capability is the only check exclusive to User ID 1 — it is **not** a real WordPress capability
- `frl_is_admin()` requires **both** `WP_ADMIN` constant AND `/wp-admin/` in the request URI. This is intentional and load-bearing across 9+ call sites. Do not change without a confirmed reproduction case
- Nonce verification always happens **before** the protected operation, and capability checks are embedded in the nonce verification flow via `_frl_verify_nonce_core()`