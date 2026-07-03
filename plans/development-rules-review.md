# Development Rules — Review & Improvement Suggestions

## Current Rules (from mandatory-rules.md → knowledge graph Development_Rules)

### Issues Identified

#### 1. 🟡 OUTDATED: Memory Retrieval Rule (#1)
> "Always begin by retrieving relevant memories from the memory-bank"

**Problem:** Since the memory MCP server (`memory.jsonl`) is now installed, there are now TWO memory systems. The rule needs to clarify which is authoritative vs. complementary.

**Analysis:**
- **Markdown memory-bank** (`*.md` files): ✅ Git-tracked, human-editable, narrative organization, always available via filesystem. **Source of truth.**
- **Knowledge Graph** (`memory.jsonl`): ✅ Semantic search, cross-entity queries, shared across Cline + Roo. **Derived queryable index.**

**Suggested improvement:**
> "Read `memory-bank/activeContext.md` and `memory-bank/productContext.md` at session start for authoritative context. Use `search_nodes` on the knowledge graph for quick cross-entity lookups during the session. When a discrepancy exists, the markdown files are the source of truth."

#### 2. 🟡 DUPLICATED: Architecture Check Rule (#8)
> "Check systemPatterns.md before every file write to ensure zero violations of established architecture"

**Problem:** "Before every file write" is impractical and causes friction. The systemPatterns.md rules are also now in the knowledge graph.

**Suggested improvement:**
> "Before modifying any file in `core/`, `includes/`, or `modules/`, verify the change does not violate `systemPatterns.md` architecture constraints. For admin/UI changes, a lighter check is sufficient."

#### 3. 🟡 INCOMPLETE: Memory Update Rule (#16)
> "At the end of each response, update memory-bank with any new information gathered"

**Problem:** Doesn't specify what to update — the markdown files, the knowledge graph, or both. Also, "at the end of each response" is too frequent for most interactions.

**Suggested improvement:**
> "Write changes to `memory-bank/*.md` files first (canonical). Sync the knowledge graph afterward as a derived queryable index. Minor edits or conversation-only sessions do not require updates. The markdown is authoritative; the graph is complementary."

#### 4. 🟡 MISSING: Knowledge Graph Usage Rule
**There is no rule about the relationship between markdown files and the knowledge graph.**

**Analysis:** The knowledge graph is a **complementary search index**, not a replacement for the markdown memory-bank. Good for `search_nodes` lookups during a session, but secondary to the authoritative markdown files.

**Suggested addition:**
> "The knowledge graph is a derived queryable index. Use `search_nodes`/`open_nodes` for quick cross-entity lookups during a session. The `memory-bank/*.md` files remain the canonical source of truth. Sync the graph after updating markdown files."

#### 5. 🟡 VAGUE: Self-Audit Protocol (#16)
> "Before declaring a task finished, you must perform a self-audit. List each 'Mandatory' rule and state 'Pass/Fail' based on your performance in this session."

**Problem:** "List each rule" is unclear — there are 16 rules. A structured checklist with the most critical items would be more actionable.

**Suggested improvement:**
```
Self-Audit Checklist (verify at task completion):
- [ ] Zero regressions? No existing signatures/filters/hooks changed
- [ ] Evidence provided? Specific file:line references in all claims
- [ ] Ripgrep verified? Codebase searched for conflicts before asserting patterns
- [ ] No hallucinations? All claims backed by source inspection, not assumptions
- [ ] Security intact? No API keys, tokens, or credentials exposed
- [ ] Memory updated? Knowledge graph and/or memory-bank updated for significant changes
```

#### 6. 🟡 MISSING: Documentation Rule
**No rule about when to create/update `docs/` files.**

**Suggested addition:**
> "When implementing a new subsystem, adapter, or significant feature, create a corresponding `docs/FEATURE.md` document. Update existing docs when architectural changes affect documented behavior."

#### 7. 🟢 MINOR: Rule #7 (Verification via Ripgrep)
> "Use grep or ripgrep to search the codebase for conflicting logic or existing implementations before asserting a pattern is followed."

**Suggestion:** This is solid but could specify _what_ to search for. Good as-is, just a note that it's well-defined.

---

## Summary

| # | Rule | Status | Action |
|---|------|--------|--------|
| 1 | Memory retrieval | 🟡 Outdated | Clarify: markdown = canonical, KG = search index |
| 8 | Architecture check | 🟡 Impractical | Scope to core/includes/modules only |
| 16a | Memory update | 🟡 Incomplete | Write to markdown first, sync KG after |
| 16b | Self-audit protocol | 🟡 Vague | Replace with concrete 6-item checklist |
| — | KG usage | 🟡 Missing | Add rule: KG as complementary search index |
| — | Documentation | 🟡 Missing | Add rule for docs/ creation/updates |
| 7 | Ripgrep verification | 🟢 Good | Keep as-is |
| 2-6, 9-15 | All others | 🟢 Good | Keep as-is |

## Proposed Updated Rules (condensed)

1. **Memory Retrieval** — Read `memory-bank/activeContext.md` and `productContext.md` at session start. Use `search_nodes` on the knowledge graph for cross-entity lookups during the session. Markdown is canonical; the graph is a complementary search index.
2. **Memory Update** — Write changes to `memory-bank/*.md` files first. Sync the knowledge graph afterward. Minor edits don't require updates.
3. **Zero Regression** — Before modifying `core/`, `includes/`, or `modules/`, verify no violation of `systemPatterns.md` architecture. Admin/UI changes: lighter check.
4. **Problem "Why"** — Identify underlying problem before proposing code. Don't rely on assumptions.
5. **Evidence Required** — All feedback must include specific file:line references.
6. **Ripgrep Verify** — Search codebase for conflicts before asserting a pattern is followed.
7. **Double Verify** — Check work twice; re-verify for side effects after every patch.
8. **No Hallucination** — Say "I don't know" rather than guessing. No placeholders. Full code only.
9. **Security Immutable** — NEVER touch files with API keys, tokens, passwords, or credentials.
10. **Self-Audit** — At task completion, verify: zero regressions, evidence provided, ripgrep used, no hallucinations, security intact, memory updated.
11. **Documentation** — New subsystems/adapters/features require a `docs/FEATURE.md` file.

---

*Review date: 2026-07-04*