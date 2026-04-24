# Project Progress

## Recent Updates (v5.4.0)
- Plugin Exclusion Feature: MU-based loader to prevent specified plugins from loading without deactivating them
  - Frontend exclusion: applies to all users
  - Capability exclusion: applies only in admin for users without required cap
- Translation Module Refactor:
  - Implemented Adapter Pattern for translation providers (Polylang/WPML).
  - Added strict typing to `field-translator.php`.
  - Introduced configurable delimiters and registration queue limits for stability.
  - Fixed language-scoping bugs in translation caching.
  - Optimized performance by deferring string registration to the `shutdown` hook.

---
*Last Updated: 2026-04-21*