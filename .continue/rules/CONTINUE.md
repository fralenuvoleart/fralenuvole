# Fralenuvole Project Guide

**Version:** 5.3.0  
**Type:** WordPress Admin Plugin (Swiss-Army Knife for Administrators)  
**Text Domain:** fralenuvole

---

## Project Overview

Fralenuvole is a high-performance WordPress plugin providing performance optimizations, default tweaks, and useful admin tools. It manages complex multi-environment configurations and multilingual URL structures with a modular, feature-based architecture.

### Key Technologies

- **PHP 8.0+** with strict typing patterns
- **WordPress 6.6.2+** (minimum required)
- **Multilingual Support:** Polylang & WPML integration
- **Caching:** Unified interface for Litespeed, Docket Cache, Redis, Memcached, and Transients
- **Theme JSON:** Full theme.json support via Themekit subsystem

### High-Level Architecture

```
fralenuvole.php (Plugin Bootstrap)
├── includes/bootstrap.php (Core initialization)
│   ├── includes/cache/class-cache-manager.php (5-backend cache system)
│   ├── includes/environment/class-environment-manager.php (Multi-env config)
│   ├── includes/translator/class-translation-service.php (Polylang/WPML)
│   └── includes/error-handler.php (Custom error handling)
├── includes/rewriter/ (URL rewriting subsystem)
├── includes/themekit/ (Theme JSON integration)
├── includes/helpers/ (Utility functions)
├── modules/ (Optional feature modules)
└── public/ (Frontend components)
```

---

## Getting Started

### Prerequisites

- **PHP:** 8.0 or higher
- **WordPress:** 6.6.2 or higher
- **Plugins:** Optional - Polylang or WPML for multilingual features

### Installation

1. Upload `fralenuvole` to `/wp-content/plugins/` directory
2. Activate via WordPress 'Plugins' menu
3. Access settings at `admin.php?page=fralenuvole`

### Debug Modes

Control plugin behavior via URL parameter `?frlmode=`:
- `?frlmode=disable` - Stop loading the plugin entirely
- `?frlmode=core` - Mimic 'disable_plugin' option behavior
- `?frlmode=nocache` - Bypass plugin's cache system

Or via `wp-config.php`:
```php
define('FRL_MODE', 'disable'); // or 'core', 'nocache', 'migrate'
```

### WP-CLI Commands

```bash
# Check rewriter status
wp frl rewriter status

# Force flush rewrite rules
wp frl rewriter flush
```

---

## Project Structure

### Core Directories

| Directory | Purpose |
|-----------|---------|
| `includes/` | Core plugin infrastructure |
| `includes/cache/` | Cache Manager with 5-backend support |
| `includes/environment/` | Environment Manager for multi-domain configs |
| `includes/rewriter/` | URL rewriting with feature-based architecture |
| `includes/themekit/` | Theme JSON/CSS integration |
| `includes/translator/` | Translation service (Polylang/WPML) |
| `includes/helpers/` | Utility functions and helpers |
| `modules/` | Optional feature modules |
| `public/` | Frontend components (shortcodes, schema, public) |
| `admin/` | Admin interface |
| `config/` | Configuration files |
| `assets/` | Static assets (JS, CSS, fonts) |
| `docs/` | Documentation |

### Key Files

| File | Purpose |
|------|---------|
| `fralenuvole.php` | Plugin entry point, bootstrap |
| `includes/bootstrap.php` | Core initialization and constants |
| `includes/main.php` | Main plugin logic |
| `config/config.php` | Config loader |
| `config/config-constants.php` | Plugin constants and defaults |
| `config/config-options.php` | Options/settings definitions |

### Configuration Files

| File | Purpose |
|------|---------|
| `config/config-constants.php` | Core constants (FRL_PREFIX, FRL_OPTIONS_RUNTIME, etc.) |
| `config/config-options.php` | FRL_DEFAULT_FIELDS - All plugin options |
| `config/config-rewriter.php` | Rewriter subsystem configuration |
| `config/config-translator.php` | Translator configuration |
| `config/config-themekit.php` | Themekit configuration |
| `config/environment/config-environment.php` | Multi-environment domain mappings |

---

## Development Workflow

### Critical Hook Priorities

**⚠️ CRITICAL: Violating hook priorities causes critical bugs.**

| Priority | Hook | Purpose |
|----------|------|---------|
| 5 | `plugins_loaded` | Bootstrap - loads core infrastructure |
| 10 | `init` | Environment enforcement (MUST be first) |
| 15 | `init` | Rewriter feature registration |
| 20 | `init` | Themekit initialization |
| 100 | `init` | Remove third-party block patterns |
| 200 | `init` | Deferred rewrite rules flush (MUST be last) |

**Reserved Priorities:** < 5, 8-9, 12-14, 15, 200

```php
// Correct - Adding new feature at priority 50
add_action('init', 'frl_my_feature_init', 50, 0);

// Wrong - Priority 8 runs BEFORE environment enforcement
add_action('init', 'frl_my_feature_init', 8, 0);

// Wrong - Priority 12 runs after env but before rewriter
add_action('init', 'frl_my_feature_init', 12, 0);
```

### Options System Constants

Defined in `config/config-options.php`:
- `FRL_FIELD_TYPES` - Allowed field types
- `FRL_FIELD_ATTRIBUTES` - Field attributes with defaults
- `FRL_FIELD_FORMATTERS` - Formatting-only types

### Cache Dependencies

Define in `config/config-cache.php`:
- `FRL_CACHE_DEPENDENCIES` - Groups that affect each other
- `FRL_CACHE_BROWSER_GROUPS` - Groups triggering browser cache clear
- `FRL_CACHE_LANGUAGE_GROUPS` - Language-specific cache groups

---

## Key Concepts

### 1. Rewriter Subsystem

URL rewriting with **feature-based architecture**. Key principle: *Any change to one feature must not alter runtime behavior of any other feature.*

**Active Features:**

| Priority | Class | Config Key |
|----------|-------|------------|
| 15 | `Frl_CPT_Archive_Base_Translation_Feature` | `translate_cpt_slugs_{cpt}` |
| 25 | `Frl_CPT_Single_Base_Translation_Feature` | `translate_cpt_slugs_{cpt}` |
| 35 | `Frl_Taxonomy_Base_Removal_Feature` | `remove_tax_base` |
| 40 | `Frl_CPT_Base_Removal_Feature` | `remove_cpt_base` |

**Key Files:**
- `includes/rewriter/class-rewriter.php` - Facade and URL transformation
- `includes/rewriter/class-rewriter-coordinator.php` - Feature lifecycle
- `includes/rewriter/class-rewriter-path-utils.php` - URL parsing utilities

### 2. Environment Manager

Domain-based configuration system for Dev/Staging/Production.

**Environment Map:** Defined in `config/environment/config-environment.php`
- `FRL_ENV_MAP` - Maps hostnames to environment constants
- `FRL_ENV_*` constants define per-environment settings

**Key Classes:**
- `Frl_Environment_Manager` - Main coordinator
- `Frl_Environment_Applier` - Applies settings
- `Frl_Environment_Monitor` - Tracks changes
- `Frl_Environment_State` - Manages state

### 3. Cache Manager

Unified interface supporting multiple backends:
- Litespeed Cache
- Docket Cache
- Redis
- Memcached
- WordPress Transients (fallback)

**Key Methods:**
```php
Frl_Cache_Manager::get($group, $key, $callback, $ttl);
Frl_Cache_Manager::set($group, $key, $value, $ttl);
Frl_Cache_Manager::remember($group, $key, $callback, $ttl); // With lock-based race condition prevention
Frl_Cache_Manager::delete($group, $key);
Frl_Cache_Manager::purge_all();
Frl_Cache_Manager::is_object_cache_truly_functional();
```

### 4. Translation Service

Concurrent Polylang and WPML support with deferred string registration.

**Key Classes:**
- `Frl_Translation_Service` - Singleton translation service

**Key Methods:**
```php
frl_get_language();              // Current language
frl_get_default_language();      // Default language
frl_get_translation($string);    // Get string translation
frl_get_translation_block($content, $block); // Block translation
frl_get_post_translations($id);  // Post translations
frl_get_term_translations($id);  // Term translations
```

### 5. Themekit

Theme JSON integration for settings, styles, colors, fonts, and block patterns.

**Components:**
- `themekit/theme-json/` - theme.json parts (settings, styles, colors, fonts)
- `themekit/patterns/` - Block patterns
- `themekit/styles/blocks/` - Block style variations

---

## Common Tasks

### Adding a New Option

1. Add field definition in `config/config-options.php` under appropriate section in `FRL_DEFAULT_FIELDS`:

```php
'my_new_option' => [
    'label' => 'My New Option',
    'description' => 'Description text',
    'type' => 'checkbox', // or text, textarea, select, etc.
    'default' => 1,
    'sanitize_callback' => 'absint',
],
```

2. Access via `frl_get_option('my_new_option')`

### Adding a Rewriter Feature

1. Create feature class extending `Frl_Base_Feature` in `includes/rewriter/features/`
2. Implement required interface methods
3. Add to `FRL_REWRITER_FEATURES` in `config/config-rewriter.php`
4. Set priority (15-40 range)

```php
class My_Custom_Feature extends Frl_Base_Feature {
    public function register(): void {
        // Add rewrite rules, filters
    }
}
```

### Adding a New Cache Group

1. Define in `config/config-cache.php`:
```php
const FRL_CACHE_TTL = [
    'my_group' => 3600, // TTL in seconds
    // ...
];
```

2. Add to appropriate groups:
- `FRL_CACHE_PERSISTENT_GROUPS` - Cross-request persistence
- `FRL_CACHE_LANGUAGE_GROUPS` - Language-specific keys
- `FRL_CACHE_DEPENDENCIES` - Cache dependency mappings

### Debugging Cache Issues

```php
// Dump URL parsing context
$debug = Frl_Rewriter_Path_Utils::get_debug_info();

// Clear all rewriter caches
Frl_Rewriter::clear_rewriter_caches();

// Force rules refresh
Frl_Rewriter::force_rules_refresh();

// Check rewriter status
frl_rewriter_is_loaded();

// Hard cache reset
Frl_Cache_Manager::hard_cache_reset();
```

### Creating a New Module

1. Create directory in `modules/` (e.g., `modules/my-module/`)
2. Create module file with:
```php
<?php
// modules/my-module/my-module.php

require_once __DIR__ . '/config-constants-my-module.php';
// Include other module files

add_action('wp_loaded', 'my_module_public_scripts', 10, 1);

function my_module_public_scripts() {
    if (!frl_is_valid_frontend_page_request()) {
        return;
    }
    // Enqueue scripts/styles
}
```

3. Add module key to environment config if needed:
```php
const FRL_ENV_*_TEMPLATE = [
    'modules' => [
        'my-module' => true,
    ],
    // ...
];
```

---

## Troubleshooting

### 404 Errors on Rewritten URLs

**Cause:** WordPress rewrite rules are stale  
**Fix:** 
- Go to Settings → Permalinks → Save
- Or run: `wp frl rewriter flush`

### Wrong Language in URLs

**Cause:** Option not saved, or cache stale  
**Fix:** Save option; cache clears automatically via `update_option_*` hook

### Catch-All Hijacking CPT Archive

**Cause:** New CPT added without flushing  
**Fix:** Save Permalinks; `clear_rewriter_caches()` regenerates exclusions

### Options Have Wrong Values

**Check:**
1. Is your hook running at correct priority (≥10)?
2. Is your hook running after `frl_environment_enforce_settings`?
3. Is your hook running before `frl_execute_rewrite_flush`?

### Environment Settings Not Applied

**Check:**
1. Is the domain in `FRL_ENV_MAP`?
2. Are the environment constants defined?
3. Is `disable_environment` option enabled?

---

## Coding Standards

### File Headers

All PHP files must have:
```php
<?php
/**
 * File Name
 * Description
 *
 * @package Fralenuvole
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}
```

### Naming Conventions

| Type | Convention | Example |
|------|------------|---------|
| Classes | PascalCase | `Frl_Cache_Manager` |
| Functions | snake_case | `frl_get_option()` |
| Constants | UPPER_SNAKE | `FRL_PREFIX` |
| Options | snake_case | `disable_cache` |
| Hooks | frl_prefix() | `frl_action_name` |
| Cache Groups | snake_case | `permalinks` |

### Key Helper Functions

| Function | Purpose |
|---------|---------|
| `frl_get_option($key)` | Get plugin option |
| `frl_update_option($key, $value)` | Update option |
| `frl_cache_remember($group, $key, $callback)` | Cache with lock |
| `frl_cache_get($group, $key)` | Get from cache |
| `frl_cache_set($group, $key, $value)` | Set cache |
| `frl_is_admin()` | Enhanced admin detection |
| `frl_has_access($capability)` | Access check with cache |
| `frl_safe_redirect($url)` | Safe redirect after actions |
| `frl_enqueue_scripts($assets, $key)` | Enqueue assets |

### Static Caching Pattern

Use `frl_cache_remember()` for cached operations to prevent race conditions:

```php
return frl_cache_remember('group', $cache_key, function () {
    // Expensive operation here
    return $result;
}, $ttl);
```

---

## Memory Bank System

The project uses a Markdown-based knowledge system in `memory-bank/`:

| File | Purpose |
|------|---------|
| `memory-bank/systemPatterns.md` | Core architecture and patterns |
| `memory-bank/productContext.md` | Product purpose and subsystems |
| `memory-bank/activeContext.md` | Current focus and recent changes |
| `memory-bank/progress.md` | Development progress tracking |

---

## References

- **WordPress Plugin Handbook:** https://developer.wordpress.org/plugins/
- **WordPress Hooks Reference:** https://developer.wordpress.org/reference/
- **Polylang Documentation:** https://polylang.pro/doc/
- **WPML Documentation:** https://wpml.org/documentation/
- **Theme JSON Schema:** https://developer.wordpress.org/block-editor/reference-guides/theme-json-reference/

---

*Last Updated: See plugin version in `fralenuvole.php` (constant `FRL_VERSION`)*