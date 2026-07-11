# Active Context

## Current Focus

Audited 5 reported findings in `Frl_Cache_Manager` and `shortcodes.php` — 2 confirmed real, 3 overstated. Applied targeted fixes with zero regression risk.

## Changes Made

1. **Fixed latent shape-mismatch bug in [`get_cached_value()`](core/cache/class-cache-manager.php:376)** — removed dead `is_array($key)` → `get_multi()` branch that returned a map when callers expect a scalar. The `$cache_key` parameter is always a string from `generate_key()`, so the single-key `get_transient()` path handles both scalar and array `$key` inputs. Zero existing callers hit this path (facade type-hints `string $key`).
2. **Expanded stampede-lock skip comment in [`remember()`](core/cache/class-cache-manager.php:468)** — documents why transient-based locking is not viable (TOCTOU race) and why MySQL `GET_LOCK()` is too heavy for 110+ call sites. All callbacks are idempotent reads, so double-execution wastes CPU but cannot corrupt data.
3. **Added defensive comments** for 3 non-bugs to prevent future misdiagnosis: `purge_all()` `'default'` skip guard, `public $deferred_writes` facade design, and `serialize()` key-materialization pattern (3 occurrences in shortcodes.php).

## Architecture: `Frl_Cache_Manager` facade pattern

`includes/helpers/functions-class-helpers.php` is the **only runtime file** that references `Frl_Cache_Manager`. All external access goes through `frl_cache_*()` helpers. This is now complete — zero class-name references in hook registrations anywhere.

## Prior Tasks (Completed)

- **`frl_process_deferred_writes()`** → `Frl_Cache_Manager::process_deferred_writes()` (with hook timing preserved)
- **`frl_add_page_excerpt_support`** moved to `includes/main/website.php`
- **Heartbeat review**: Our implementation correct, reference has 4 bugs
- **Intelephense stub** created at `.dev/stubs/_intelephense-globals.php`
