# System Patterns (Fralenuvole 5.3.0)

## 🏗️ Core Architecture & Init Sequence
- **P5 (plugins_loaded):** Translation Interception.
- **P10 (init):** Environment Enforcement (Domain-based).
- **P15 (init):** Rewriter Registration (Post-environment).

## 🛡️ Critical Logic (No Regressions)
- **Environment:** `pre_option_*` filters used for domain overrides.
- **Cache:** 5-backend unified interface; check `FRL_CACHE_DEPENDENCIES`.
- **Options:** 3-tier cascade (Static → Persistent → DB).
- **Race Conditions:** Use `frl_cache_remember` with lock-based prevention.

## 🛠️ Developer Working Method
- **Standard:** Modular, Elegant, SEO-performant.
- **Reporting:** Use specific file/line references.
- **Verification:** Double-verify for regressions; No "Opinion as Fact."
- **Integrity:** Failing to follow = **LYING/GASLIGHTING**.

## 🔒 Rule Integrity Notice
The mandatory-rules.md in `memory-bank/` is the source of truth.

---
*Last Updated: 2026-04-14*
