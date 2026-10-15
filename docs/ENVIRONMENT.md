# Environment Manager

Domain-based auto-configuration that maps incoming HTTP hosts to environment profiles and automatically applies WordPress options, plugin options, plugin activation states, and module states — all defined via PHP constants.

## Architecture

```
Frl_Environment_Manager (Facade)
    ├── Frl_Environment_Config      — Domain config builder; FRL_ENV_MAP lookup; merge engine
    ├── Frl_Environment_State       — State persistence; host change detection
    ├── Frl_Environment_Monitor     — URL correction; option/plugin change tracking hooks
    ├── Frl_Environment_Applier     — Applies WP options, plugin options, module states
    ├── Frl_Environment_Plugin_Manager — Plugin activation/deactivation
    ├── Frl_Environment_Files       — File-based option loading with PHP syntax validation
    ├── Frl_Environment_Utils       — Structured logging
    └── Frl_Environment_Host_Normalizer (trait) — Host normalization helpers
```

### File Map

| File | Class/Trait | Responsibility |
|------|-------------|----------------|
| [`core/environment/environment-manager.php`](core/environment/environment-manager.php) | — | Bootstrap loader; requires all sub-files |
| [`core/environment/class-environment-manager.php`](core/environment/class-environment-manager.php) | `Frl_Environment_Manager` | Public facade; init, enforce, config access, admin bar |
| [`core/environment/class-environment-config.php`](core/environment/class-environment-config.php) | `Frl_Environment_Config` | Domain config builder; merge engine |
| [`core/environment/class-environment-state.php`](core/environment/class-environment-state.php) | `Frl_Environment_State` | State persistence; host change detection |
| [`core/environment/class-environment-monitor.php`](core/environment/class-environment-monitor.php) | `Frl_Environment_Monitor` | URL correction; option/plugin change tracking |
| [`core/environment/class-environment-applier.php`](core/environment/class-environment-applier.php) | `Frl_Environment_Applier` | Applies WP options, plugin options, module states |
| [`core/environment/class-environment-plugin-manager.php`](core/environment/class-environment-plugin-manager.php) | `Frl_Environment_Plugin_Manager` | Plugin activation/deactivation |
| [`core/environment/class-environment-files.php`](core/environment/class-environment-files.php) | `Frl_Environment_Files` | File-based option loading |
| [`core/environment/class-environment-utils.php`](core/environment/class-environment-utils.php) | `Frl_Environment_Utils` + trait | Logging; host normalization |

## Configuration System

### Environment Map

Defined in [`config/environment/config-environment.php`](config/environment/config-environment.php):

```php
const FRL_ENV_MAP = [
    'pbservices.ge'                         => 'FRL_ENV_PBS_PRODUCTION',
    'pbproperty.ge'                         => 'FRL_ENV_PBP_PRODUCTION',
    'pbnova.com'                            => 'FRL_ENV_PBNOVA_PRODUCTION',
    'fralenuvole.art'                       => 'FRL_ENV_FRALENUVOLE_PRODUCTION',
    'master.fralenuvole.art'                => 'FRL_ENV_MASTER_TEMPLATE',
    'stg-pbservicesge-staging.kinsta.cloud' => 'FRL_ENV_PBS_STAGING',
    'stg-pbproperty-staging.kinsta.cloud'   => 'FRL_ENV_PBP_STAGING',
    'stg-pbnovacom-staging.kinsta.cloud'    => 'FRL_ENV_PBNOVA_STAGING',
];
```

**Matching:** `HTTP_HOST` is lowercased, `www.` prefix stripped, matched against map keys (also `www.`-stripped). Falls back to `parse_url(site_url(), PHP_URL_HOST)` when `HTTP_HOST` is unavailable.

### Configuration Merge Stack

Four layers merged in order (bottom wins):

```
Layer 1: FRL_ENV_DEFAULT          ← Universal baseline
Layer 2: Type partial             ← FRL_ENV_DEFAULT_PRODUCTION or FRL_ENV_DEFAULT_STAGING
Layer 3: Brand template (extends) ← Optional one-level template
Layer 4: Instance constant        ← The specific constant from FRL_ENV_MAP
```

- Associative arrays use `array_replace_recursive()`
- The `plugins` key uses custom merge logic (see below)
- The `extends` key is resolved by looking up the named constant, then stripped. Templates cannot extend — nested `extends` is silently dropped
- After merge, `type` is set based on explicit `type` hint or constant name suffix (`_STAGING`)

### Plugin Merge Logic

| Key | Behavior |
|-----|----------|
| `active` / `inactive` | Full replacement at this layer |
| `active_add` / `inactive_add` | Additive: merged with current list |

After all layers resolve, active plugins are removed from the inactive list and vice versa.

### Default Configuration Sections

| Section | Description |
|---------|-------------|
| `prefix` | Brand prefix for option naming and file lookups |
| `type` | `'production'` or `'staging'` |
| `webhook_config` | Webhook configuration key for WS Form |
| `plugins` | `{active: [], inactive: []}` |
| `modules` | `{module_name: bool}` |
| `wp_options` | WordPress core options (e.g., `blog_public`) |
| `plugin_options` | Plugin-specific options (value or `'file'` for snippet loading) |

## Init & Enforcement Sequence

### Init (`plugins_loaded/5`)

`frl_load_core_components()` → `frl_environment_init()` → `Frl_Environment_Manager::init()`:

1. Guard: `frl_is_already_running(__METHOD__)`
2. If user has access (`frl_has_access()`):
   - Check & clear force-reload cookie → injects `window.location.reload(true)` script
   - `Frl_Environment_Monitor::check_urls()` — compares `HTTP_HOST` vs `siteurl`/`home`, corrects if mismatch
   - `Frl_Environment_Monitor::setup_plugin_options_tracking()` — registers `update_option_*` hooks
3. Register hooks: `admin_bar_menu` (switcher), `activated_plugin`, `deactivated_plugin`

### Enforcement (`init/10`)

`frl_environment_enforce_settings()` → `Frl_Environment_Manager::enforce_environment_settings()`:

1. Guard: `frl_has_access()` (admins or migrate mode)
2. Skip if: `?frl_action=...` request, redirect from `reset_environment`, already running
3. Get domain config (cached 1 hour)
4. Check throttle: 60s for admins, 300s for others (bypassed on state change, force, or migrate mode)
5. Check state change via `Frl_Environment_State::check_environment_state()`
6. If state changed OR force:
   - `apply_wordpress_options()` — siteurl, home, blog_public, etc.
   - `apply_plugin_options()` — plugin-specific options + file-based HTML
   - `apply_plugins_activation_status()` — activate/deactivate plugins
   - `apply_modules_options()` — enable/disable modules
   - Change-type classifier selects `env_*` operation → `Frl_Cache_Operations::run()`
   - Persist state to DB + cache
   - If state change (non-force): clear website transients (once per host, 1yr TTL), log, redirect to plugin admin

### Gatekeeper: `frl_environment_is_loaded()`

Defined in [`includes/helpers/functions-class-helpers.php`](includes/helpers/functions-class-helpers.php). Returns `false` if:
- Not a valid page request (CLI, REST, CRON, non-mapped hosts, heartbeat, log-manager, AJAX)
- `disable_plugin` or `disable_environment` option enabled
- `FRL_MODE === 'core'`
- `Frl_Environment_Manager` class doesn't exist

## Core Classes

### Frl_Environment_Config — Configuration Builder

**`get_domain_config()`:** Cached in `environment` group (1 hour). On miss: calls `build_domain_config()` which matches `HTTP_HOST` against `FRL_ENV_MAP`, resolves `extends`, merges 4 layers, adds metadata (`current_host`, `env_host`, `current_environment`).

### Frl_Environment_State — State Persistence

Stored state: `{host, siteurl, home, hash, last_updated?}`. Persisted to DB option (`frl_environment_state`) + object cache (`environment` group, key `state`).

**Change detection:** Compares current `HTTP_HOST`, `site_url()`, `home_url()` against stored values. Hosts are normalized (lowercase, trailing dot removed, port stripped). Scheme, port, and path differences are ignored — only domain-level changes trigger enforcement. On staging, also re-checks `blog_public` — if != 0, triggers re-enforcement.

### Frl_Environment_Monitor — Active Monitoring

**`check_urls()` (admin-only):** If `HTTP_HOST` differs from `siteurl`/`home` host: updates both options, syncs `blog_public`, resets ignore lists, refreshes auth cookie, sets force-reload cookie, runs `enforce_environment_settings(true)`.

**`setup_plugin_options_tracking()`:** Registers `update_option_{$prefixed_name}` hooks for all managed plugin options. Each hook calls `track_plugin_options()`.

**`track_plugin_options()`:** If a managed option is manually changed by an admin, adds it to `environment_ignore_options` list. Subsequent enforcement skips ignored options.

**`track_plugins_activation_status()`:** Same pattern for plugins — adds to `environment_ignore_plugins` list.

### Frl_Environment_Applier — Config Application

**`apply_wordpress_options()`:** Computes expected `siteurl`/`home` from config's `current_host`, updates if different. Iterates `wp_options` from config.

**`apply_plugin_options()`:** For each key in `plugin_options`: skips if ignored, loads file content if value is `'file'`, normalizes booleans to `'1'`/`'0'`, calls `frl_update_option()` with deferred cache clearing (`$clear_cache = false`). All cache clearing is centralized in the change-type classifier in `enforce_environment_settings()`.

**`apply_modules_options()`:** Iterates `modules` config, updates `module_{name}` options. Same deferred cache clearing pattern.

**`clear_website_transients_if_needed()`:** Guarded by `FRL_ENV_CLEAR_WEBSITE_TRANSIENTS` (always `true`). Uses `frl_cache_remember()` with `YEAR_IN_SECONDS` TTL to clear only once per destination host.

### Frl_Environment_Plugin_Manager — Plugin Lifecycle

**`apply_plugins_activation_status()`:** Reads ignore list, activates plugins in `active` list, deactivates plugins in `inactive` list. Temporarily removes tracking hooks to prevent self-triggering. Uses `deactivate_plugins($silent=true)` and `activate_plugin($silent=false)`.

### Frl_Environment_Files — File-Based Options

Files in [`config/environment/env-snippets/`](config/environment/env-snippets/) named `{prefix}_{option_name}.php`. When config sets an option to `'file'`, the file content is loaded and stored as the option value. PHP syntax is validated via `php -l` (advisory only — content is stored as-is, never executed).

## Throttle System

| User Type | Throttle | Bypass Conditions |
|-----------|----------|-------------------|
| Admin (`frl_has_access()`) | 60 seconds | `$force=true`, `FRL_MODE=migrate`, state changed |
| Non-admin | 300 seconds | Same as above |

State change detection always runs before the throttle check — domain migrations are never delayed.

## Ignored Customizations

Two DB options track manual overrides:

| Option Key | Content | Tracked By |
|------------|---------|------------|
| `frl_environment_ignore_plugins` | Array of plugin paths | `track_plugins_activation_status()` |
| `frl_environment_ignore_options` | Array of option keys | `track_plugin_options()` |

Reset via admin action "Reset Ignored Plugins" → `reset_customizations()` clears both lists.

## Admin Bar Switcher

Added at `admin_bar_menu` priority 9999. Shows current environment (`prod`/`staging`), color-coded (green/yellow), with search engine indexing status. Links to counterpart domain. Lists sibling domains (staging hidden for non-admins, author domain hidden for non-superadmins). Results cached per user in `admin` cache group.

## Cache Interaction

### Cache Points

| Data | Cache Group | TTL |
|------|-------------|-----|
| Domain config | `environment` | 1 hour |
| Environment state | `environment` + DB option | 1 hour |
| Throttle last check | `environment` | 1 hour |
| Ignored plugins/options | `environment` + DB option | 1 hour |
| File option keys/content | `environment` | 1 hour |
| Transients-cleared flag | `environment` | 1 year |
| Admin bar submenus | `admin` | Default |

### Cache Clearing on Enforcement

All cache clearing is centralized through the orchestrator via `env_*` operations:

| Trigger | Operation | Steps |
|---------|-----------|-------|
| Plugin/module change | `env_enforce_full` | `frl_cache_clear('all')` + `frl_flush_rewrite_rules()` |
| URL change (siteurl/home) | `env_enforce_url_change` | `frl_cache_clear('all')` |
| Options only | `env_enforce_options` | `frl_cache_clear('options')` |
| Force mode | `env_enforce_full` | Same as plugin/module change |

`apply_plugin_options()` and `apply_modules_options()` defer cache clearing — all writes use `frl_update_option($key, $value, false)`. The single orchestrated clear runs after all apply methods complete.

## Disable Mechanisms

| Method | Effect |
|--------|--------|
| `disable_plugin` option | All plugin features disabled |
| `disable_environment` option | EM only disabled |
| `FRL_MODE=core` | Mimics `disable_plugin` |
| `FRL_MODE=disable` | Plugin stops loading entirely |
| `FRL_MODE=migrate` | `frl_has_access()` returns `true` for all users |

## Extension Points

### Adding a New Domain

1. Define a brand template in [`config/environment/config-environment.php`](config/environment/config-environment.php) if new
2. Define an instance constant with `'extends' => 'TEMPLATE_NAME'`
3. Add domain → constant mapping to `FRL_ENV_MAP`
4. Optionally: create snippet files in `env-snippets/` as `{prefix}_{option}.php`

### Gateway Functions

| Function | Purpose |
|----------|---------|
| `frl_environment_is_loaded()` | Check if EM should run for this request |
| `frl_environment_init()` | Initialize EM (call from `plugins_loaded`) |
| `frl_environment_enforce_settings($force)` | Run enforcement (call from `init/10`) |
| `frl_environment_get_config()` | Get current resolved config |
| `frl_environment_reset_ignored()` | Clear ignore lists |

### Hooks

| Hook | Context |
|------|---------|
| `frl_environment_before_wp_options` | Before WP options are applied |
| `frl_environment_state_changed` | Filter to force state change detection |
| `frl_before_env_enforce_full` / `frl_after_env_enforce_full` | Around full enforcement cache operation |
| `frl_before_env_enforce_url_change` / `frl_after_env_enforce_url_change` | Around URL change cache operation |
| `frl_before_env_enforce_options` / `frl_after_env_enforce_options` | Around options-only cache operation |