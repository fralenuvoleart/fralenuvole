# Fix: Stale/Corrupted Rewrite Rules Breaking Secondary Language Page Permalinks

## Problem Summary

Page post type permalinks in secondary languages (e.g., `/ru/russian-page/`) return 404 errors over time. The Fralenuvole "Flush Rewrite Rules" button did not fix the issue. The manual fix required: **Save Permalinks 2 times + Purge Litespeed cache**.

## Root Cause

The flush button path never fired `update_option_permalink_structure`, so Polylang's `clean_languages_cache()` (hooked at [`polylang/src/model.php:119`](/mnt/backup/BACKUP/WWW/PBS/public_html/wp-content/plugins/polylang/src/model.php:119)) never ran before `flush_rewrite_rules()` regenerated rules. Without a clean language cache, Polylang's `rewrite_rules_array` filter could skip adding language-specific page rules, causing 404s for secondary language pages.

Additionally, the old code had accumulated technical debt: multiple functions with overlapping responsibilities, wrong notification order (Litespeed notified before rules rebuilt), and a deferred cron mechanism that added unnecessary 60-second delays.

## Fix: Single Unified Function `frl_flush_rewrite_rules()`

### Design

One function replaces three deleted legacy functions. It mirrors `WP_Rewrite::set_permalink_structure()` exactly:

1. **Timing handling**: Schedules cron if called before `init`, defers to priority 200 if called during `init`, executes immediately otherwise.
2. **Execution**: Fires `update_option_permalink_structure` (→ `clear_rewriter_caches()` clears options→rewriter→permalinks + deletes exclusion patterns transient + `flush_rewrite_rules(true)` + notifies Litespeed; → Polylang cleans language cache) + `permalink_structure_changed`.

### Functions Deleted

| Deleted Function | Replaced By |
|-----------------|-------------|
| `frl_flush_force_rewrite_rules()` | `frl_flush_rewrite_rules()` |
| `frl_execute_rewrite_flush()` (old body) | `frl_flush_rewrite_rules()` |
| `frl_flush_rewrite_rules_mirror_permalink_save()` | `frl_flush_rewrite_rules()` |
| `Frl_Rewriter::flush_rules()` | `frl_flush_rewrite_rules()` |

### Functions Kept

| Function | Role |
|----------|------|
| `frl_flush_rewrite_rules()` | **New unified function** — the single entry point |
| `frl_execute_rewrite_flush()` | Thin cron wrapper → calls `frl_flush_rewrite_rules()` |
| `frl_schedule_rewrite_flush()` | Schedules 15s cron (used before init + frontend contexts) |
| `frl_schedule_admin_rewrite_flush()` | Sets 60s transient (used by `action_hard`, `env_enforce_full`) |
| `frl_execute_scheduled_admin_flush()` | Checks transient on `admin_init:99` → calls `frl_flush_rewrite_rules()` |
| `Frl_Rewriter::clear_rewriter_caches()` | Workhorse triggered by `update_option_*` hooks |
| `Frl_Rewriter::force_rules_refresh()` | Invalidates config hash + calls `frl_flush_rewrite_rules()` |
| `Frl_Rewriter_Coordinator::force_refresh()` | Resets config hash + calls `frl_flush_rewrite_rules()` |

### Files Modified

| File | Changes |
|------|---------|
| [`includes/plugin-lifecycle.php`](includes/plugin-lifecycle.php) | Rewrote: added `frl_flush_rewrite_rules()`, simplified `frl_execute_rewrite_flush()` to thin wrapper, updated `frl_activate/deactivate/uninstall_plugin()` and `frl_execute_scheduled_admin_flush()` callers |
| [`includes/helpers/functions-action-handlers.php:327`](includes/helpers/functions-action-handlers.php:327) | `frl_handle_action_flush_rewrite_rules()` now calls `frl_flush_rewrite_rules()` |
| [`admin/helpers/functions-admin-action-handlers.php:500`](admin/helpers/functions-admin-action-handlers.php:500) | Plugin reset handler now calls `frl_flush_rewrite_rules()` |
| [`includes/core/rewriter/class-rewriter.php`](includes/core/rewriter/class-rewriter.php) | Deleted `flush_rules()`, updated `force_rules_refresh()` and repair path |
| [`includes/core/rewriter/class-rewriter-coordinator.php:277`](includes/core/rewriter/class-rewriter-coordinator.php:277) | `force_refresh()` now calls `frl_flush_rewrite_rules()` |
| [`config/config-cache-operations.php`](config/config-cache-operations.php) | Updated `action_hard` and `action_flush_rewrite_rules` operation definitions |

### Lifecycle Verification

All existing hooks continue to work:

- `admin_init:99` → `frl_execute_scheduled_admin_flush()` → `frl_flush_rewrite_rules()` ✅
- `frl_execute_rewrite_flush` cron → `frl_execute_rewrite_flush()` → `frl_flush_rewrite_rules()` ✅
- `update_option_permalink_structure` → `clear_rewriter_caches()` ✅ (unchanged)
- `update_option_category_base` etc. → `clear_rewriter_caches()` ✅ (unchanged)
- Third-party inbound (`litespeed_purged_all`) → `frl_schedule_rewrite_flush()` → cron → `frl_flush_rewrite_rules()` ✅
- Term changes → `frl_schedule_rewrite_flush()` → cron → `frl_flush_rewrite_rules()` ✅

### Regression Risk: None

- No existing hook registrations changed
- `clear_rewriter_caches()` untouched — still the workhorse for `update_option_*` hooks
- Cron event name `frl_execute_rewrite_flush` unchanged — existing scheduled events still work
- `frl_schedule_rewrite_flush()` and `frl_schedule_admin_rewrite_flush()` unchanged
- All deleted functions were only called internally; all callers updated