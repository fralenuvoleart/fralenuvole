# Product Context: Fralenuvole Plugin

## 🎯 Purpose
A high-performance, "Swiss-army knife" WordPress administrator plugin designed for reliability, modularity, and SEO optimization. It manages complex multi-environment configurations and multilingual URL structures for a portfolio of related brand websites deployed from a single codebase.

## 🧩 Core Subsystems
- **Rewriter Subsystem:** Manages URL rewriting for multilingual CPTs and taxonomy/CPT base removal using a feature-based, self-registering architecture.
- **Environment Manager:** Enforces domain-based configurations (production/staging, per-brand) using `pre_option_*` filters and direct option writes.
- **Cache Manager:** A unified interface supporting Litespeed, Docket Cache, Redis, Memcached, and WordPress Transients, with dependency cascading and language-aware keys.
- **Options System:** A three-tier fallback (Static → Persistent → DB) with per-type value normalization.
- **Translation Service:** Adapter-based architecture with self-contained fallbacks, decoupled from the translation provider (Polylang implemented; WPML-ready) via `Frl_Translation_Adapter_Interface`. Deferred string registration via the `shutdown` hook.
- **Subdomain Adapter:** Bidirectional URL transformation between main domains and language-specific subdomain mirrors. Uses the `pll_get_current_language` filter to make Polylang treat a subdomain's language as its default.
- **Plugin Exclusion:** MU-plugin-based loader that prevents specified plugins from loading (without deactivating them), gated by frontend/backend-screen/capability rules.

## 🛠️ Critical Workflows
- **Environment Enforcement:** Runs at `init`/10, throttled (60s for admins, 300s for others) except when a host/state change is detected, which always bypasses the throttle.
- **Cache Clearing:** Cascades via the `FRL_CACHE_DEPENDENCIES` constant and is orchestrated through `Frl_Cache_Operations` for centralized visibility of every multi-step clear/flush sequence.
- **Navigation Translation:** Translates `wp_navigation` block-editor menus between languages via dedicated `pll_get_post_types`/`block_type_metadata_settings` filters.
- **Plugin Exclusion:** Runs at `muplugins_loaded`, filters `active_plugins`/`active_sitewide_plugins` via `pre_option_*`/`pre_site_option_*`.

## 🏗️ Architecture Conventions
- **`pre_option_frl_*` filters:** Strictly namespaced to this plugin's own prefixed options — never intercepts third-party option reads.
- **Re-entrancy Pattern:** A static `$initialized[]` array (via `frl_is_already_running($key)`) keyed by function/method/class name, used throughout the codebase to prevent duplicate execution within a single request.
- **Language Detection Priority:** Adapter API → query-var fallback → `FRL_TRANSLATOR_DEFAULT_LANG` constant.
- **Translation Fallbacks:** Adapter-encapsulated — `Frl_Polylang_Adapter` contains private internal fallback methods that read Polylang's DB options directly. Global helpers delegate to the adapter via a `class_exists` check.
- **Source Language vs. Default Language:** `FRL_TRANSLATOR_SOURCE_LANG` (the language content is authored in) is deliberately a separate constant from Polylang's "default language" setting, since the latter changes per-subdomain while authored content does not.

---

*This file describes durable product context, not a changelog. When the product's purpose or a core subsystem's role changes, update the relevant section in place.*
