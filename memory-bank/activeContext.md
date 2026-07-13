# Active Context

## Current Focus

Added Cloudflare/Nginx caching chain clarification to [`.roo/skills/kinsta-log-analyzer/SKILL.md`](.roo/skills/kinsta-log-analyzer/SKILL.md): new bullet in "How Logs Are Retrieved" documents the `kinsta-cache-perf` log scope — data pulled from Cloudflare logs, ~85% of requests served by Cloudflare Edge cache (never reach Nginx), ~15% pass through (dynamic ~13.5%, miss ~1%, bypass ~0.5%), Nginx handles page caching for the 15% subset. Fixed misleading "Edge Cache Health" label → "Nginx Page Cache Health" in Step 3 output description. Updated Step 9a scope note to include ~85%/15% figures.

## Changes Made

1. **Kinsta Log Analyzer SKILL.md — Cloudflare/Edge caching chain documentation:**
   - Added new bullet in "How Logs Are Retrieved" explaining `kinsta-cache-perf` log scope and the Cloudflare→Nginx caching chain with specific percentages (~85% Edge, ~15% Nginx: dynamic ~13.5%, miss ~1%, bypass ~0.5%).
   - Renamed "📊 Edge Cache Health" → "📊 Nginx Page Cache Health" with clarification that data covers only the ~15% subset reaching Nginx.
   - Updated Step 9a scope note to include ~85%/15% figures.

Reviewed and hardened [`deploy.sh`](tools/deploy-remote.sh) with `--dry-run` flag and interactive confirmation prompt.

## Changes Made

1. **Added null guard to [`frl_get_plugin_options_db()`](includes/helpers/functions-options.php:308)** — `$wpdb->get_results()` can return `null` on DB failure; added `if (!is_array($results)) { return array(); }` before the `foreach` to prevent `foreach() argument must be of type array|object, null given` warning.
2. **Added `--dry-run` flag to [`deploy.sh`](tools/deploy-remote.sh)** — fetches, shows commit preview, exits 0 without touching the working tree.
3. **Added confirmation prompt to [`deploy.sh`](tools/deploy-remote.sh)** — prompts "Proceed with deploy? (y/N)" after commit preview, before `git reset --hard`. Skipped when `-y`/`--yes` passed or stdin is not a TTY (CI-safe).

## Prior Tasks (Completed)

- **Added `get_language_label()` to translation adapter chain** — new method on [`Frl_Translation_Adapter_Interface`](core/translator/adapters/interface.php), implemented in [`Frl_Polylang_Adapter`](core/translator/adapters/polylang.php) (uses `PLL()->model->get_language()`), exposed via [`Frl_Translation_Service::get_language_label()`](core/translator/class-translation-service.php), with a procedural helper [`frl_get_language_label()`](includes/helpers/functions-translator-helpers.php).
- **Deduplicated PLL language label lookup in shortcodes** — [`frl_langswitcher_build_list()`](public/shortcodes.php) and [`frl_langswitcher_build_dropdown()`](public/shortcodes.php) both now call `frl_get_language_label()`.
- **Deduplicated permalink resolution in [`frl_shortcode_permalink()`](public/shortcodes.php)** — unified conditional branches.
- **Fixed 2 adapter-bypass call sites** — breadcrumb term-language and navigation translation now use adapter methods.
- **`frl_process_deferred_writes()`** → `Frl_Cache_Manager::process_deferred_writes()` (with hook timing preserved)
- **`frl_add_page_excerpt_support`** moved to `includes/main/website.php`
- **Heartbeat review**: Our implementation correct, reference has 4 bugs
- **Intelephense stub** created at `.dev/stubs/_intelephense-globals.php`
- Cache Manager shape-mismatch fix, stampede-lock comment expansion, defensive comments for non-bugs

## Prior Tasks (Completed)

- **`frl_process_deferred_writes()`** → `Frl_Cache_Manager::process_deferred_writes()` (with hook timing preserved)
- **`frl_add_page_excerpt_support`** moved to `includes/main/website.php`
- **Heartbeat review**: Our implementation correct, reference has 4 bugs
- **Intelephense stub** created at `.dev/stubs/_intelephense-globals.php`
- Cache Manager shape-mismatch fix, stampede-lock comment expansion, defensive comments for non-bugs
