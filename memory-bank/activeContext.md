# Active Context

## 📚 Memory Configuration
- Rules loaded from: `memory-bank/mandatory-rules.md`
- Design principles stored in memory-mcp entity: UserDesignPrinciples

## 🔄 Current Focus
Fralenuvole v5.4.0 - WordPress multilingual administrator plugin with URL rewriting, multilingual support, multi-backend caching, and environment-based configuration.

## 🏗️ Architecture Overview
- **Feature-based rewriter:** Independent feature classes that self-register
- **5-backend cache system:** Litespeed, Docket Cache, Redis, Memcached, Transients
- **3-tier options cascade:** Static → Persistent → DB with value normalization
- **Hook priority discipline:** plugins_loaded/5, init/10, init/15, init/20
- **MU Plugin Loader:** `assets/mu/frl-mu-plugin.php` (bootstrap) → `includes/helpers/functions-mu-plugin.php` (logic) — plugin exclusion feature with persistent caching of `active_plugins` via `frl_cache_remember` in the `options` group (WEEK_IN_SECONDS TTL). Cron data fetched fresh (too volatile to cache). Network active plugins also cached via a separate `frl_cache_remember` key. Cache invalidation on `activated_plugin`/`deactivated_plugin` hooks.
- **Translation Module:** Adapter-based architecture decoupling the service from translation providers (Polylang/WPML), utilizing deferred registration via `shutdown` hook and multi-level caching.

## ⚠️ Active Considerations
- Ensure `init/15` rewriter registration stays strictly after `init/10` environment enforcement.
- Monitor `write_attempted` flag in Options System to ensure zero duplicate DB writes.
- MU plugin `pre_option_cron` filter removes orphaned cron events (unregistered schedules) during WP Cron to prevent `invalid_schedule` errors. Also sanitizes `args` to array to prevent `TypeError` on null args.
- Backend exclusion in MU plugin uses `frl_is_admin_page()` to match screens; `frl_textlist_to_array()` already handles `|` pipe format. **Timing**: `$pagenow` is null during `muplugins_loaded` (vars.php loads after), so `frl_is_admin_page()` falls back to `$_SERVER['SCRIPT_NAME']`.
- Three-tier exclusion: Frontend (context) → Backend (screen) → Capability (user) — applied in priority order.
- **Cache recursion safety:** `frl_cache_remember` uses object cache/transients (never the option system), so it is safe inside `pre_option_*` / `pre_site_option_*` filters. The `$wpdb->get_var()` fallback remains as the callback to avoid the `pre_site_option` filter chain.

## 📁 Documentation
- `docs/ARCHITECTURAL-REVIEW.md` - Plugin overview
- `docs/HOOKS.md` - Critical hook priorities
- `docs/REWRITER.md` - Rewriter subsystem architecture
- `docs/PLUGIN-EXCLUSIONS-FEATURE.md` - Plugin exclusion feature (updated with cron cleanup)

---
*Last Updated: 2026-04-25*
