# Plugin Debug Modes

Debug and troubleshooting modes activated via URL parameter or `wp-config.php` constant.

## Activation

**URL parameter** (temporary, per-request): `?frlmode=<mode>` appended to any page URL. Defines `FRL_MODE` constant in [`includes/bootstrap.php:23`](includes/bootstrap.php:23).

**wp-config.php** (persistent): `define('FRL_MODE', '<mode>')`. URL parameter takes precedence.

## Modes

| Mode | Effect | Checked In |
|------|--------|------------|
| `disable` | Plugin stops loading entirely. Early return in [`bootstrap.php:28`](includes/bootstrap.php:28) and [`fralenuvole.php:31`](fralenuvole.php:31). Emergency kill-switch. | Before any `FRL_DIR_PATH` usage |
| `core` | Mimics `disable_plugin` option. Core components load but public components and modules are skipped. Cache bypassed via [`Frl_Cache_Manager::should_bypass()`](core/cache/class-cache-manager.php:139). | [`fralenuvole.php:82`](fralenuvole.php:82) |
| `nocache` | Bypasses internal cache system. [`Frl_Cache_Manager::should_bypass()`](core/cache/class-cache-manager.php:138) returns `true`. WordPress transients and object cache unaffected. | [`Frl_Cache_Manager::should_bypass()`](core/cache/class-cache-manager.php:138) |
| `migrate` | Break-glass for domain migrations. [`frl_has_access()`](includes/helpers/functions-access-control.php:27) returns `true` for all users. Environment Manager enforcement runs for everyone (throttle bypassed). | [`frl_has_access()`](includes/helpers/functions-access-control.php:27), [`Frl_Environment_Manager::enforce_environment_settings()`](core/environment/class-environment-manager.php:221) |

## Notes

- `disable` is the only mode that stops plugin loading entirely — checked before any `FRL_DIR_PATH` usage
- `migrate` allows environment detection and enforcement without requiring a logged-in admin
- `nocache` only affects the plugin's own cache layer; WordPress transients and object cache are unaffected