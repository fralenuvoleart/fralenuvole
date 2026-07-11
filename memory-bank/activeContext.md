# Active Context

## Current Focus

Verified 6 reported code-quality findings — 2 confirmed real and fixed, 4 overstated or accurately low-severity.

## Changes Made

1. **Added `get_language_label()` to translation adapter chain** — new method on [`Frl_Translation_Adapter_Interface`](core/translator/adapters/interface.php), implemented in [`Frl_Polylang_Adapter`](core/translator/adapters/polylang.php) (uses `PLL()->model->get_language()`), exposed via [`Frl_Translation_Service::get_language_label()`](core/translator/class-translation-service.php), with a procedural helper [`frl_get_language_label()`](includes/helpers/functions-translator-helpers.php) that follows the same service/fallback pattern as `frl_get_home_url()`.
2. **Deduplicated PLL language label lookup in shortcodes** — [`frl_langswitcher_build_list()`](public/shortcodes.php) and [`frl_langswitcher_build_dropdown()`](public/shortcodes.php) both now call `frl_get_language_label($el['slug'])` instead of duplicating a 6-line `PLL()->model->get_language()` block.
3. **Deduplicated permalink resolution in [`frl_shortcode_permalink()`](public/shortcodes.php)** — introduced `$post_id = null` before the conditional chain, set it in numeric-ID and default branches, then a single unified block handles permalink/cache/anchor logic for both paths. ~14 lines removed, identical behavior.

## Prior Tasks (Completed)

- **`frl_process_deferred_writes()`** → `Frl_Cache_Manager::process_deferred_writes()` (with hook timing preserved)
- **`frl_add_page_excerpt_support`** moved to `includes/main/website.php`
- **Heartbeat review**: Our implementation correct, reference has 4 bugs
- **Intelephense stub** created at `.dev/stubs/_intelephense-globals.php`
- Cache Manager shape-mismatch fix, stampede-lock comment expansion, defensive comments for non-bugs
