# Translation System Documentation

## Overview
The Translation System is designed to handle translation requirements across the entire site, including custom fields, site options, and dynamic block content.

## 🎯 Purpose
The system solves the following problems:
- **Plugin Independence:** Decouples the site from specific translation plugins (e.g., Polylang, WPML) via an Adapter pattern.
- **Deep Translation:** Extends translation capabilities to ACF fields, user meta, and site options.
- **Token Protection:** Prevents translation delimiters (e.g., `{{...}}`, `##...##`) from leaking to the frontend via "Safe Mode".
- **Performance:** Minimizes database overhead through aggressive caching and batch processing.

## 🏗️ Architecture

### Component Diagram
`Developer/Theme` $\rightarrow$ `Procedural Helpers` $\rightarrow$ `Frl_Translation_Service (Singleton)` $\rightarrow$ `Translation Adapter` $\rightarrow$ `Multilingual Plugin (Polylang/WPML)`

### Key Components
- **`Frl_Translation_Service`**: The central hub. Manages caching, batching, and the translation lifecycle.
- **`Frl_Translation_Adapter_Interface`**: Defines the contract for translation plugins.
- **`Frl_Polylang_Adapter`**: The current implementation for Polylang. Fallback logic is self-contained — the adapter knows its own plugin's database schema and provides fallbacks independently of global helper functions.
- **`field-translator.php`**: The hook-based layer that intercepts WordPress metadata calls to provide transparent translations.
- **`functions-translator-helpers.php`**: The public API for developers.
- **`translator.php`**: Module entry point. Loads adapter interface and implementation early so the adapter class is always available after the module loads, regardless of whether the service singleton is instantiated.

### Fallback Architecture

When the Translator service is not enabled or Polylang isn't fully initialized (e.g., CLI, cron, early AJAX), the system uses adapter-encapsulated fallbacks:

1. **Global helpers** (`frl_get_default_language_fallback()`, `frl_get_active_languages_fallback()`) check if `Frl_Polylang_Adapter` class exists
2. If available, they instantiate the adapter directly and call its public methods
3. The adapter tries Polylang's API functions first (`pll_default_language()`, `pll_languages_list()`)
4. If the API returns empty, the adapter uses its **private internal fallback methods** which read Polylang's database options directly
5. If no adapter class exists (Polylang not installed), the system falls back to `FRL_TRANSLATOR_DEFAULT_LANG` constant

**Configuration Constants:**
- `FRL_TRANSLATOR_DEFAULT_LANG` — Default language fallback when no multilingual plugin is active (default: `'en'`)
- `FRL_TRANSLATOR_SOURCE_LANG` — The language in which content is authored (default: `'en'`). This is semantically different from the default language and remains constant even when Polylang's default changes on subdomains.

## 🚀 Key Features

### 1. Block Translation
Blocks marked with the `.frl-translate` CSS class are processed for:
- **Text Tokens**: `{{String}}` $\rightarrow$ Translated String.
- **Link Tokens**: `##slug##` $\rightarrow$ Translated Permalink.

### 2. Field Translation
The system intercepts `get_post_meta`, `get_term_meta`, `get_user_meta`, and `get_option` to translate values automatically if the meta key is included in the allowed lists (defined in config).

### 3. Safe Mode
If no translation plugin is active, the system enters **Safe Mode**. Instead of attempting translation, it simply strips the delimiters:
- `{{Hello}}` $\rightarrow$ `Hello`
- `##my-slug##` $\rightarrow$ `#`

### 4. Deferred Registration
To optimize page load speed, strings discovered during the request are not registered immediately. They are added to a queue and processed during the WordPress `shutdown` hook.

## ⚡ Performance Optimizations

### Caching Strategy
- **Persistent Cache**: Translations are stored in the `translations` and `permalinks` cache groups.
- **Versioned Cache**: All cache keys include a `translation_version`. Incrementing this version in settings flushes all translations globally.
- **Batching**: `get_translation_batch_strings()` and `get_translation_batch_permalinks()` reduce the number of calls to the translation plugin.

### Resource Management
- **Lazy Loading**: The `Frl_Translation_Service` is only instantiated when a translation is actually requested.
- **Central Guard**: `frl_translator_is_enabled()` short-circuits all helper functions if the translator is disabled in settings or no plugin is installed.

## 🛠️ Developer API

### Common Helpers
- `frl_get_translation($string, $lang)`: Translates a simple string.
- `frl_get_translation_block($content, $block)`: Processes a block for tokens.
- `frl_get_language($id, $type)`: Gets the current or object-specific language.
- `frl_process_permalink_patterns($content)`: Resolves `##slug##` tokens in a string.

## ⚠️ Maintenance Notes
- **Adding New Fields**: To enable translation for a new ACF field, add the field name to the corresponding array in the translation config.
- **Flushing Cache**: Use the "Reset Translation Version" button in the admin to invalidate all cached translations.
