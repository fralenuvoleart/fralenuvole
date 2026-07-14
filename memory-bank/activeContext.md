# Active Context

## Current Focus

Plugin maintenance and hardening. Recent work includes null-guard fixes, translation adapter enhancements, and cache system improvements.

## Changes Made

1. **Fixed `FRL_MODE=disable` fatal error in [`fralenuvole.php`](fralenuvole.php:30-33)** — The disable guard was placed AFTER `require_once FRL_DIR_PATH . 'includes/plugin-lifecycle.php'` (line 33 old/line 36 new), but `FRL_DIR_PATH` is defined in [`config/config-base.php`](config/config-base.php:23) which is loaded in [`bootstrap.php`](includes/bootstrap.php:37) AFTER the disable check at line 28-30. Moved the guard to immediately after `require_once` of bootstrap, before any `FRL_DIR_PATH` usage. Also removed the now-redundant duplicate guard. Full analysis in [`plans/debug-mode-failure-points.md`](plans/debug-mode-failure-points.md).
2. **Added null guard to [`frl_get_plugin_options_db()`](includes/helpers/functions-options.php:308)** — `$wpdb->get_results()` can return `null` on DB failure; added `if (!is_array($results)) { return array(); }` before the `foreach` to prevent `foreach() argument must be of type array|object, null given` warning.

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
