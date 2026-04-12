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

## 📋 Supermemory Integration Rules (CRITICAL - Always Follow)

### Session Start Rule
At the beginning of EVERY new session, BEFORE starting any task:
```
Use searchSupermemory to check for relevant context with the query: "current project status and recent changes"
```

### Post-Task Sync Rule
After EVERY significant task completion, you MUST:
1. Call `addToSupermemory` with a summary of what was done
2. Display a notification to the user: "💾 Synced to Supermemory"

### What to Sync (Significant Tasks)
- Major bug fixes
- New features implemented
- Important architectural decisions
- Project context changes
- Code refactoring
- Documentation updates

### What NOT to Sync
- Minor typos fixed
- Read-only analysis
- Quick questions answered without code changes

## 🔒 Rule Integrity Notice
The mandatory-rules.md in `/home/francesco/Documents/Cline/Rules/` is the source of truth.
This file serves as a backup. If rules seem inconsistent, the source file takes precedence.
