# Active Context

## Current Focus

Fixed two rounds of the same class of policy violation in [`.roo/skills/kinsta-log-analyzer/SKILL.md`](.roo/skills/kinsta-log-analyzer/SKILL.md): (1) old Step 6.5 explicitly instructed reading this plugin's `config/config-mu.php`/`FRL_MU_THROTTLE_USER_AGENT` and citing it by name in generated Kinsta log reports — contradicting the skill's own "Report Audience & Purpose"/Scope rules (no hosted-app source code involvement, ever). Fixed by removing the codebase-reading instruction entirely (not just its output) — Step 6.5 now forbids opening any plugin/theme file at all. (2) The Bot Traffic Strategy table template had a "Citation" column pointing to `bot-taxonomy.md#...` (an internal skill reference file, equally inaccessible to the report's reader) — removed that column from the template and from the already-generated report; `bot-taxonomy.md`/`site-context.md` now only inform reasoning, never appear as report citations. Also updated `references/bot-taxonomy.md` (Mitigation Tiers now 4 Kinsta/robots.txt-only tiers, no hosted-app-code tier) and `references/operational-playbook.md` similarly. Retroactively scrubbed and re-exported `report_pbservices.ge_live_202607120645.md`/`.pdf` twice.

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
