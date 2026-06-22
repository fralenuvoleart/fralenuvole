# Webhook Architecture Trade-offs: Shared Utility vs. Standalone Module

**Date:** 2026-06-22  
**Context:** User wants deeper analysis before deciding between Option A (shared utility) and Option B (standalone module).

---

## The Core Question

> Should webhook dispatch be a standalone module, extensible so any module can consume it?

The plugin already has a precedent for this exact design tension: the Cache Operations orchestrator ([`Frl_Cache_Operations`](core/cache/class-cache-operations.php)) was created to replace scattered `frl_cache_clear()` / `frl_thirdparty_maybe_notify()` calls across Environment Manager, admin actions, and the Rewriter. Before the orchestrator, each subsystem did its own cache clearing. After, everything routes through one `FRL_CACHE_OPERATIONS` registry.

The webhook situation is structurally identical.

---

## Option A: Shared Utility (Helper Functions)

```
wsform module          chat-buttons module     future module
     │                       │                      │
     ▼                       ▼                      ▼
  config-constants       config-constants       config-constants
  (webhook URLs)         (webhook URLs)         (webhook URLs)
     │                       │                      │
     ▼                       ▼                      ▼
  webhooks.php           class-chat-button      own handler
  (payload build,        (payload build,        (payload build,
   trigger logic)         trigger logic)         trigger logic)
     │                       │                      │
     └───────────────────────┼──────────────────────┘
                             ▼
                    frl_send_webhook()
                    frl_should_dedupe_webhook()
                    (in includes/helpers/)
```

### Pros

| Pro | Evidence |
|-----|----------|
| **KISS** | Two functions, ~80 lines total. No module bootstrap, no config files, no option toggles. |
| **Minimal abstraction** | Each module owns its domain. wsform knows about form fields; chat-buttons knows about button clicks. No central registry needs to understand both. |
| **No new module to maintain** | One less entry in `FRL_ENV_DEFAULT['modules']`, one less toggle in admin UI. |
| **Fits today's scope** | Only 2 consumers exist. A module is overkill for 2 call sites. |
| **Zero risk of premature abstraction** | If a 3rd consumer never appears, we haven't built infrastructure for nothing. |

### Cons

| Con | Evidence |
|-----|----------|
| **Config duplication** | `WSFORM_ALL_WEBHOOKS_CONFIG` and `CHAT_BUTTON_WEBHOOK_CONFIG` both define per-environment URLs. If a 3rd module needs webhooks, it adds a 3rd constant. |
| **Payload construction duplicated** | Each module builds its own `$post_data` array. `frl_wsf_button_webhook_handler()` manually constructs 15 fields; any new module would repeat this pattern. |
| **No central visibility** | To find all webhook URLs, you grep the codebase. No single registry. |
| **Error handling inconsistency risk** | Each module decides how to handle cURL failures. Today both use `frl_log()`, but a future module might not. |
| **Dedupe logic tied to module concepts** | `frl_wsf_should_send_webhook()` uses `Reference ID` + `CTA` keys from the form field map. A generic dedupe needs its own key strategy per caller. |

---

## Option B: Standalone Webhook Module

```
                 FRL_WEBHOOK_ENDPOINTS (per-environment URLs)
                 FRL_WEBHOOK_CONFIGS    (per-module: endpoint + dedupe keys + field map)
                              │
                  ┌───────────┼───────────┐
                  │           │           │
             wsform      chat-buttons   future module
          "submit form"  "button click"  "any event"
                  │           │           │
                  └───────────┼───────────┘
                              ▼
              Frl_Webhook_Dispatcher::send($event_key, $context_data)
                              │
                    ┌─────────┼─────────┐
                    ▼         ▼         ▼
               resolve URL  build    dedupe
               per env      payload  check
                    │         │         │
                    └─────────┼─────────┘
                              ▼
                       cURL dispatch
                       frl_log() on failure
```

### Pros

| Pro | Evidence |
|-----|----------|
| **Single source of truth** | All webhook URLs live in one `FRL_WEBHOOK_ENDPOINTS` constant. One place to update when Integrately URLs change. |
| **Consistent payload construction** | The dispatcher knows the standard field set (reference_id, source, medium, campaign, etc.). Callers only provide context-specific overrides. |
| **Central visibility** | `Frl_Webhook_Dispatcher::get_registered_endpoints()` returns all configs. Admin UI could display active webhooks. |
| **Extensible via filters** | `frl_webhook_before_send`, `frl_webhook_after_send` — any module can intercept or augment without modifying the dispatcher. |
| **Follows proven pattern** | `Frl_Cache_Operations` solved the same problem for cache clearing. It now handles `clear_*`, `action_*`, and `env_*` operations from 6+ call sites. |
| **Environment-aware by design** | The dispatcher resolves URLs via `frl_environment_get_config()` — callers don't need to know about environments. |
| **Future-proof** | A 3rd module (e.g., "booking calendar", "live chat") just registers a config entry and calls `::send()`. No new infrastructure. |

### Cons

| Con | Evidence |
|-----|----------|
| **Heavier abstraction for 2 consumers** | A full module (entry point, config files, option toggle, class file) for what is essentially a cURL wrapper. |
| **Payload variance is real** | Form webhooks send 15+ fields mapped from form submission data. Button webhooks send 12 fields from cookies + server vars. A generic dispatcher needs a flexible payload builder — which adds complexity. |
| **Module bootstrap overhead** | New entry in `FRL_ENV_DEFAULT['modules']`, new option toggle, new admin UI section. More moving parts. |
| **Risk of over-engineering** | If no 3rd consumer ever appears, the module is abstraction without payoff. |
| **Refactor cost** | Moving `WSFORM_ALL_WEBHOOKS_CONFIG` into a new constant format touches `webhooks.php`, `config-constants-webhooks.php`, and environment configs. Non-trivial. |

---

## The Cache Operations Precedent

The plugin faced this exact decision with cache clearing. Before `Frl_Cache_Operations`:

| Before (scattered) | After (orchestrator) |
|---|---|
| `frl_cache_clear('all')` in EM | `Frl_Cache_Operations::run('env_enforce_full')` |
| `frl_thirdparty_maybe_notify('hard')` in admin | Same orchestrator step |
| `frl_flush_rewrite_rules()` in rewriter | Same orchestrator step |
| No visibility into what runs when | `get_operation_map()` returns full registry |
| 6 legacy flush functions | 1 consolidated function |

**The refactor was justified because:**
1. Cache clearing had 6+ call sites across 3 subsystems
2. Each caller was doing slightly different things (some notified third parties, some didn't)
3. Debugging cache issues required grepping the entire codebase
4. The orchestrator added ~200 lines but eliminated ~400 lines of duplicated logic

**Webhooks today:**
1. Only 2 call sites (form submit, button click)
2. Both already use identical cURL logic (`frl_wsf_execute_webhook_submission`)
3. Both already use identical dedupe logic (`frl_wsf_should_send_webhook`)
4. Debugging requires grepping 2 files — manageable

**The webhook situation is simpler than cache clearing was.**

---

## Hybrid Option C: Core Helper + Config Registry (No Module)

A middle ground: keep the dispatcher as a core class (not a module), with a config registry constant, but without the full module bootstrap overhead.

```
config/config-webhooks.php          # FRL_WEBHOOK_ENDPOINTS + FRL_WEBHOOK_CONFIGS
includes/helpers/class-webhook-dispatcher.php  # Frl_Webhook_Dispatcher
```

No module entry point, no option toggle, no admin UI. The dispatcher is always available (like `Frl_Cache_Manager`). Modules call it directly.

### Pros
- Single source of truth for URLs (like Option B)
- No module bootstrap overhead (like Option A)
- Follows the `Frl_Cache_Manager` pattern (core class, not module)

### Cons
- Still requires refactoring `WSFORM_ALL_WEBHOOKS_CONFIG` into new format
- Still adds a class file and config file to core
- Payload variance problem remains

---

## Decision Matrix

| Criterion | Option A (Helper) | Option B (Module) | Option C (Core Class) |
|-----------|-------------------|-------------------|----------------------|
| Lines of new code | ~80 | ~300 | ~200 |
| Files added | 1 | 5+ | 2 |
| Config consolidation | No | Yes | Yes |
| Future extensibility | Low | High | Medium |
| Refactor risk | Low | Medium | Medium |
| Admin UI complexity | None | New section | None |
| Alignment with Cache Ops pattern | Weak | Strong | Medium |
| Payoff at 2 consumers | High (KISS) | Low (overkill) | Medium |
| Payoff at 3+ consumers | Low (duplication) | High (extensible) | Medium |

---

## Recommendation

**Option A (shared utility) for now, with Option B (module) as a documented future path.**

Rationale:
1. **YAGNI:** With only 2 consumers, a module is premature abstraction. The Cache Operations refactor was justified at 6+ call sites; webhooks are at 2.
2. **KISS:** Two helper functions (`frl_send_webhook`, `frl_should_dedupe_webhook`) solve the immediate problem with ~80 lines and zero bootstrap.
3. **Zero refactor of existing config:** `WSFORM_ALL_WEBHOOKS_CONFIG` stays as-is. The chat-buttons module adds its own `CHAT_BUTTON_WEBHOOK_CONFIG`. No migration needed.
4. **Easy upgrade path:** When a 3rd consumer appears, the helpers can be promoted into a `Frl_Webhook_Dispatcher` class without breaking existing callers. The function signatures (`frl_send_webhook($url, $data)`) map directly to a class method.
5. **The real win is module separation, not webhook centralization.** Extracting chat buttons from wsform is the high-value change. Whether webhooks are centralized is secondary.

**If you still prefer Option B or C, the implementation is straightforward** — but it adds ~200 lines of infrastructure for a problem that 80 lines solves today.
