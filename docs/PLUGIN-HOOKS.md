# Plugin Hook Priorities

Critical hook priority contract for Fralenuvole. Violating these priorities causes bugs: environment settings applied after rewriter reads options, rewrite rules registered before CPTs exist, options read before plugin is fully initialized.

## Critical Hook Sequence

### `plugins_loaded` (Priority 5)

`fralenuvole.php:43` — `frl_plugins_loaded()`:
1. `frl_load_core_components()` — Cache Manager, Environment Manager, Translator, Rewriter, ThemeKit, webhooks, shortcodes
2. `frl_load_admin_components()` — if `frl_is_admin()`
3. `frl_load_public_components()` — if `frl_is_valid_frontend_page_request()` and plugin not disabled
4. `frl_modules_init()` — conditional module loading

### `init` (Priority 10)

`fralenuvole.php:134` — `frl_environment_enforce_settings()`: Environment enforcement MUST run first. After this, `pre_option_*` filters are active and all option reads return environment-overridden values.

**⚠️ Any hook at `init` with priority < 10 reads RAW database options, NOT environment-overridden values.**

Also at priority 10: `frl_shortcodes_init()`.

### `init` (Priority 12)

`includes/main/logged-user.php:13` — `frl_process_plugin_actions()`: Processes `?frl_action=...` GET requests from admin bar cache links.

### `init` (Priority 15)

Rewriter features register via `Frl_Rewriter_Coordinator`. Features read options (now correctly intercepted by `pre_option_*` filters) and call `add_rewrite_rule()`.

### `init` (Priority 20)

`fralenuvole.php:148` — `frl_themekit_init()`: Theme kit initialization.

### `init` (Priority 100)

`frl_themekit_remove_provider_block_patterns()`: Remove third-party block patterns.

### `init` (Priority 200)

`frl_flush_rewrite_rules()`: Deferred rewrite rules flush. **MUST be the last `init` hook** — flushes after all features have registered their rules.

## Reserved Priorities

| Priority | Status | Reason |
|----------|--------|--------|
| < 5 | Reserved | WordPress core |
| 5 | RESERVED | Fralenuvole bootstrap |
| 8-9 | Forbidden | Before environment enforcement |
| 10 | RESERVED | Environment enforcement + shortcodes init |
| 12 | RESERVED | Plugin action processing |
| 12-14 | Forbidden | After env, before rewriter |
| 15 | RESERVED | Rewriter registration |
| 20 | RESERVED | ThemeKit init |
| 200 | RESERVED | Rewrite flush |

## Quick Reference

```php
defined('FRL_INIT_BOOTSTRAP')   || define('FRL_INIT_BOOTSTRAP', 5);
defined('FRL_INIT_ENV_ENFORCE') || define('FRL_INIT_ENV_ENFORCE', 10);
defined('FRL_INIT_REWRITER')    || define('FRL_INIT_REWRITER', 15);
defined('FRL_INIT_THEMEKIT')    || define('FRL_INIT_THEMEKIT', 20);
defined('FRL_INIT_FLUSH')       || define('FRL_INIT_FLUSH', 200);
```

## Adding New Hooks

**✅ Correct — priority 50:**
```php
add_action('init', 'frl_my_feature_init', 50, 0);
```

**❌ Wrong — priority 8:** Runs BEFORE environment enforcement. Options will have wrong values.

**❌ Wrong — priority 12:** Runs after env but BEFORE rewriter. Feature won't be part of rewrite rules.