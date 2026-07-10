# Active Context

## Current Focus

Wrapped `Frl_Cache_Manager::process_deferred_writes()` in a facade helper `frl_cache_process_deferred_writes()` — restoring the established pattern where all `Frl_Cache_Manager` references go through helpers in `functions-class-helpers.php`.

## Changes Made

1. **Added [`frl_cache_process_deferred_writes()`](includes/helpers/functions-class-helpers.php:251)** — facade helper with `frl_cache_is_loaded()` guard, delegates to `Frl_Cache_Manager::process_deferred_writes()`.
2. **Updated [`includes/main.php:33`](includes/main.php:33)** — hook callable from `array('Frl_Cache_Manager', 'process_deferred_writes')` to `'frl_cache_process_deferred_writes'` — no class name exposed in runtime hooks.

## Architecture: `Frl_Cache_Manager` facade pattern

`includes/helpers/functions-class-helpers.php` is the **only runtime file** that references `Frl_Cache_Manager`. All external access goes through `frl_cache_*()` helpers. This is now complete — zero class-name references in hook registrations anywhere.

## Prior Tasks (Completed)

- **`frl_process_deferred_writes()`** → `Frl_Cache_Manager::process_deferred_writes()` (with hook timing preserved)
- **`frl_add_page_excerpt_support`** moved to `includes/main/website.php`
- **Heartbeat review**: Our implementation correct, reference has 4 bugs
- **Intelephense stub** created at `.dev/stubs/_intelephense-globals.php`
