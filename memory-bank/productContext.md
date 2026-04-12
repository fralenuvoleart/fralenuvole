# Product Context: Fralenuvole Plugin (v5.3.0)

## 🎯 Purpose
A high-performance, "Swiss-army knife" WordPress administrator plugin designed for reliability, modularity, and SEO optimization. It manages complex multi-environment configurations and multilingual URL structures.

## 🧩 Core Subsystems
- **Rewriter Subsystem:** Manages URL rewriting for multilingual CPTs using a feature-based architecture.
- **Environment Manager:** Enforces domain-based configurations (Dev/Staging/Prod) using `pre_option_*` filters.
- **Cache Manager:** A unified interface supporting Litespeed, Docket, Redis, Memcached, and Transients.
- **Options System:** A three-tier fallback (Static → Persistent → DB) with value normalization.
- **Translation Service:** Concurrent support for Polylang and WPML with deferred string registration.

## 🛠️ Critical Workflows
- **Environment Enforcement:** Must happen at `init/10`.
- **Cache Clearing:** Uses a cascade system via the `FRL_CACHE_DEPENDENCIES` constant.
- **Navigation:** Translates `wp_navigation` posts specifically between languages.