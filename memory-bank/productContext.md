# Product Context: Fralenuvole Plugin (v5.8.0)

## 🎯 Purpose
A high-performance, "Swiss-army knife" WordPress administrator plugin designed for reliability, modularity, and SEO optimization. It manages complex multi-environment configurations and multilingual URL structures.

## 🧩 Core Subsystems
- **Rewriter Subsystem:** Manages URL rewriting for multilingual CPTs using a feature-based architecture.
- **Environment Manager:** Enforces domain-based configurations (Dev/Staging/Prod) using `pre_option_*` filters.
- **Cache Manager:** A unified interface supporting Litespeed, Docket, Redis, Memcached, and Transients.
- **Options System:** A three-tier fallback (Static → Persistent → DB) with value normalization.
- **Translation Service:** Adapter-based architecture with self-contained fallbacks. Decoupled from translation providers (Polylang/WPML) via `Frl_Translation_Adapter_Interface`. Deferred string registration via `shutdown` hook.
- **Subdomain Adapter:** Bidirectional URL transformation between main domains and language-specific subdomain mirrors. Uses `pll_get_current_language` filter to override Polylang's current language on subdomains.
- **Plugin Exclusion:** MU-based loader to prevent specified plugins from loading without deactivating them.

## 🛠️ Critical Workflows
- **Environment Enforcement:** Must happen at `init/10`.
- **Cache Clearing:** Uses a cascade system via the `FRL_CACHE_DEPENDENCIES` constant.
- **Navigation:** Translates `wp_navigation` posts specifically between languages.
- **Plugin Exclusion:** Runs at `muplugins_loaded`, filters `active_plugins` via `pre_option_active_plugins`.

## 🏗️ Architecture
- **main.php:** Modular structure loading shared/ modules
- **pre_option_frl_* filters:** Plugin-specific, no other plugin uses them
- **Re-entrancy Pattern:** Static `$initialized[]` array keyed by function/method/class name
- **Language Detection Priority:** Adapter API → Query var fallback → `FRL_TRANSLATOR_DEFAULT_LANG` constant (default: `'en'`)
- **Translation Fallbacks:** Adapter-encapsulated — `Frl_Polylang_Adapter` contains private internal fallback methods that read Polylang's DB options directly. Global helpers delegate to adapter via `class_exists` check. Ultimate fallback is `FRL_TRANSLATOR_DEFAULT_LANG` constant.
- **Source Language:** `FRL_TRANSLATOR_SOURCE_LANG` (default: `'en'`) — the language content is authored in. Semantically different from default language; remains constant even when Polylang's default changes on subdomains.

---
*Last Updated: 2026-05-25*