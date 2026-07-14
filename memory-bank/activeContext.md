# Active Context

## Current Focus

Kinsta log analysis performed for pbservices.ge (live) — 24h report generated via the `kinsta-log-analyzer` skill: `~/Downloads/kinsta-logs/reports/report_pbservicesge_live_202607140703.md` (+ PDF). Key findings: cache HIT rate 39% (below >50% target, explained by post-midnight-UTC purge cold-start + 4-language structure, confirmed by live probe re-check); no active security incidents (existing xmlrpc/URI-keyword blocks confirmed working); Bytespider/ClaudeBot flagged for monitoring only; misspelled Russian URL slug (`individualnyj-predprinimatel` vs. live `individualnyj-predprinematel`) causing repeat 404s; stale `/wp-content/litespeed/js/*.js` 404s from a past optimizer-plugin switch. No plugin code was touched (log-analysis skill is Kinsta-ops-only, does not read/modify this codebase).

## Prior Focus

Kinsta Log Analyzer Skill enhancement: extracted platform tribal knowledge and action history from Kinsta support transcripts, created two new reference files ([`kinsta-tribal-knowledge.md`](.roo/skills/kinsta-log-analyzer/references/kinsta-tribal-knowledge.md), [`kinsta-history.md`](.roo/skills/kinsta-log-analyzer/references/kinsta-history.md)), added Steps 6.6b/6.6c to SKILL.md workflow, and applied label/template refinements (Incident→Event, Actor→Source, Actions bullet-list format, 404/Error card format, Status column width, IP country/flag in Slowest Pages + Bursts tables).

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
