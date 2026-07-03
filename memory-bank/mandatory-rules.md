# Mandatory Operational Rules

## 🧠 MEMORY & PERSISTENCE
- **Memory Retrieval:** Read `memory-bank/activeContext.md` and `memory-bank/productContext.md` at session start. These markdown files are the canonical source of truth over your general training data. Use `search_nodes` on the knowledge graph (`github.com/modelcontextprotocol/servers/tree/main/src/memory` MCP server) for quick cross-entity lookups during the session — the graph is a derived queryable index, not a replacement for the markdown files.
- **Memory Update:** Write changes to `memory-bank/activeContext.md` and `progress.md` per the existing auto-update protocol. Whenever you write to those files, sync the knowledge graph afterward via `create_entities`/`add_observations`. No separate trigger — if the change wasn't worth documenting in markdown, it's not worth syncing to the graph. Markdown is canonical; the graph is complementary.
- **Deep Scan Initialization:** If no `memory-bank/` exists, you must offer to initialize it by scanning `docs/` and the codebase to preserve legacy architectural decisions.
- **Documentation:** When implementing a new subsystem, adapter, or significant feature, create a corresponding `docs/FEATURE.md` document. Update existing docs when architectural changes affect documented behavior.

## 🔍 ANALYSIS & REASONING
- **Problem "Why":** Identify the underlying problem before proposing code. Do not rely solely on comments or assumptions.
- **Chain of Thought:** Before writing code, explicitly state which `systemPatterns.md` rule you are following.
- **Evidence:** All feedback and suggestions must include specific file/line references.
- **Verification via Ripgrep:** Before asserting that a pattern is followed or a regression is avoided, you MUST use `grep` or `ripgrep` to search the codebase for conflicting logic or existing implementations. Never rely on your internal "guess" of the file structure.

## 🛠️ DEVELOPMENT & QUALITY
- **Zero Regression Policy:** This is production code. Before modifying any file in `core/`, `includes/`, or `modules/`, verify the change does not violate `systemPatterns.md` architecture constraints. For admin/UI changes, a lighter check is sufficient.
- **Validation Loop:** Verify work a second time before presenting it. Re-verify specifically for side effects after every patch.
- **Design Principles:** Prioritize KISS, Modularity, Performance, and SEO.

## 🛑 ABSOLUTE CONSTRAINTS (ANTI-HALLUCINATION)
- **Honesty Protocol:** Failing to follow directives, making up "best practices," or presenting opinions as facts is **LYING**. 
- **Gaslighting:** Deflecting errors with apologies instead of fixes is **GASLIGHTING**. 
- **The "I Don't Know" Rule:** If context is missing or you are unsure, you must say "I don't know" rather than hallucinating a solution.
- **No Placeholders:** Never use `// ... rest of code here`. Provide complete, functional snippets or targeted diffs.
- **Security Files:** NEVER delete, modify, or expose files containing API keys, tokens, passwords, or credentials. If flagged as security issues, RESTORE them immediately and do not proceed without explicit user approval.

## ⚖️ SELF-AUDIT PROTOCOL
- **Task Completion Check:** Before declaring a task finished, you must perform a self-audit against this checklist:
  - [ ] Zero regressions? No existing signatures/filters/hooks changed
  - [ ] Evidence provided? Specific file:line references in all claims
  - [ ] Ripgrep verified? Codebase searched for conflicts before asserting patterns
  - [ ] No hallucinations? All claims backed by source inspection, not assumptions
  - [ ] Security intact? No API keys, tokens, or credentials exposed
  - [ ] Memory updated? If activeContext.md / progress.md were written, knowledge graph synced
- **Correction:** If a "Fail" is identified, you must immediately correct the work before the session ends.

## ⚖️ CHAT PROTOCOL
Follow these steps for each interaction:
1. Read `memory-bank/activeContext.md` and `productContext.md` at session start. Use `search_nodes` on the knowledge graph for cross-entity lookups during the session.
2. While conversing, be attentive to new information about user preferences, coding patterns, project structure.
3. When updating `activeContext.md` or `progress.md`, sync the knowledge graph afterward.
