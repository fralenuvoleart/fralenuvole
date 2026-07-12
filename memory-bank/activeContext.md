# Active Context

## Current Focus

Fixed a policy violation in [`.roo/skills/kinsta-log-analyzer/SKILL.md`](.roo/skills/kinsta-log-analyzer/SKILL.md): Step 6.5 explicitly instructed citing this plugin's own `config/config-mu.php`/`FRL_MU_THROTTLE_USER_AGENT` by name in generated Kinsta log reports, directly contradicting the skill's own "Report Audience & Purpose" rule (reports must never reference the hosted app's codebase — reader manages Kinsta infra, not this repo). Rewrote Step 6.5, and the corresponding sections in `references/bot-taxonomy.md` ("Internal-Reference-Only: Not for Report Text") and `references/operational-playbook.md` (Mitigation Tiers), so the internal throttle-check is used only to calibrate severity judgment, never surfaced by file/constant/function name in report text. Also retroactively scrubbed the already-generated `report_pbservices.ge_live_202607120645.md`/`.pdf` of all such references and re-exported the PDF.

Reviewed and hardened [`deploy.sh`](deploy.sh) with `--dry-run` flag and interactive confirmation prompt.

## Changes Made

1. **Added null guard to [`frl_get_plugin_options_db()`](includes/helpers/functions-options.php:308)** — `$wpdb->get_results()` can return `null` on DB failure; added `if (!is_array($results)) { return array(); }` before the `foreach` to prevent `foreach() argument must be of type array|object, null given` warning.
2. **Added `--dry-run` flag to [`deploy.sh`](deploy.sh)** — fetches, shows commit preview, exits 0 without touching the working tree.
3. **Added confirmation prompt to [`deploy.sh`](deploy.sh)** — prompts "Proceed with deploy? (y/N)" after commit preview, before `git reset --hard`. Skipped when `-y`/`--yes` passed or stdin is not a TTY (CI-safe).

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
