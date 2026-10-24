# Active Context

## Latest: Plugin-exclusions removal — audit + 5 fixes (2026-10-24)

Audited the staged removal of the MU plugin-exclusions system (`includes/mu/`, `config/config-mu.php`, `docs/MU-THIRDPARTY-EXCLUSIONS.md`, options, dashboard widget, CSS, docs, memory-bank) — sweep clean except the items below, all now fixed (unstaged, on top of the staged set):

- **Fatal:** stale `require_once .../config-mu.php` removed from [`config/config.php`](config/config.php:10) — PHPStan missed it via its broad `require_once` ignore pattern.
- **Regression:** re-added [`frl_purge_active_plugins_cache()`](core/cache/cache-cleanup.php:254) — the removed MU purge hook also invalidated `thirdparty_active_plugins`, the cache key of the SURVIVING `frl_is_thirdparty_plugin_active()` (WEEK TTL); without it, plugin (de)activations went undetected for up to a week.
- **Stale text:** removed dead `frl_mu_check_access()` reference + restored the invalidation clause in [`functions-access-control.php`](includes/helpers/functions-access-control.php:19) docblocks.
- **Invariant:** "MU-Plugin Caching" in systemPatterns.md rewritten as "`frl_cache_remember()` Never Reads Options" (the MU `pre_option_*` premise is gone).
- **Deploy step (NOT code):** the installed `wp-content/mu-plugins/frl-mu-plugin.php` still `require`s the deleted `includes/mu/mu.php`; the stale copy fatals before wp-admin loads, so the new `assets/mu/frl-mu-plugin.php` must be copied over the installed one as part of the deploy.
- **Minor:** orphan `frl_excluded_plugins_*` DB rows keep appearing in Import/Export until manually deleted (uninstall `LIKE 'frl_%'` covers them).

## Latest: Trait static-analysis cleanup extended to batch + LRU traits (2026-10-24)

Applied the Trait Static-Analysis Contract (Option A, user-approved) to the two remaining cache traits. Zero behavior change.

- [`class-cache-manager.php:28`](core/cache/class-cache-manager.php:28): `$runtime_cache`, `$group_keys`, `$loaded_groups` widened `private static` → `public static` with a "public by design" comment (same rationale as `$deferred_writes`: traits reference them cross-file; the facade remains the ONLY external accessor). `$key_cache` stays private (no trait refs).
- [`trait-cache-lru.php`](core/cache/trait-cache-lru.php): `self::$runtime_cache`/`self::$group_keys` → `Frl_Cache_Manager::$…` (10 sites); dropped the two `@property static` docblocks.
- [`trait-cache-batch.php`](core/cache/trait-cache-batch.php): `self::PREFIX` → `Frl_Cache_Manager::PREFIX` (6 sites), `self::$loaded_groups` → `Frl_Cache_Manager::$loaded_groups` (2 sites); dropped the `@property static` docblock.
- Verified: `php -l`, `phpcs` exit 0 (phpcbf re-aligned the property block), `phpstan` exit 0 on core/cache.

## Latest: Diagnostics-trait Intelephense cleanup — trait-owned memo + qualified host constants (2026-10-24)

Lint-only, zero behavior change. Resolved 13 Intelephense diagnostics in [`trait-cache-diagnostics.php`](../core/cache/trait-cache-diagnostics.php) (root cause: Intelephense analyzes traits in isolation; `@property static` docblocks don't resolve `self::$prop` and there's no virtual-constant mechanism — see `systemPatterns.md` "Trait Static-Analysis Contract").

- Moved `private static ?array $cached_provider_details` from `Frl_Cache_Manager` into the trait (used only there) — matches the "traits own their state" pattern (`$lru` in the LRU trait).
- `get_cache_config()` + provider transient key now read `Frl_Cache_Manager::PREFIX`/`::LOCK_TTL` (public consts) and source constants `FRL_CACHE_TTL`/`FRL_CACHE_PERSISTENT_GROUPS`/`FRL_CACHE_DEPENDENCIES` (grep-verified never mutated — the class props were pure mirrors).
- Removed the four ineffective `@property static` docblocks (also cleared the P1129 parse error); kept the working `@method` for `_is_plugin_globally_active()`.
- Verified: `php -l`, `phpcs` clean (phpcbf fixed alignment), `phpstan` exit 0.
- Known remaining (resolved same day — see entry above): same latent pattern in [`trait-cache-batch.php`](../core/cache/trait-cache-batch.php) and [`trait-cache-lru.php`](../core/cache/trait-cache-lru.php).

## Latest: auto_preload — skip no-op loop on object cache + document transient-only preload (2026-10-24)

Clarification + micro-optimization of the preload path (behavior-preserving).

- [`auto_preload()`](core/cache/class-cache-manager.php:92) now returns early when `is_object_cache_truly_functional()` is true, skipping the per-group `preload_multi()` loop. On a functional object cache `get_multi($group, null)` cannot enumerate keys, so the loop only hydrated the empty runtime index and loaded nothing — pure no-op. Keys warm lazily on first access instead.
- [`FRL_CACHE_PRELOAD_FRONTEND_GROUPS`/`_BACKEND_GROUPS`](config/config-cache.php:127) documented as a **transient-path** optimization (one batched `LIKE` query when there is no persistent object cache); inert on Redis/Memcached.
- Behavior-preserving across all four branches: (OC functional, all-persistent) and (OC functional, mixed) both previously ran the no-op loop → now early-return; (transient, all-persistent) still uses `batch_preload_transients()`; (transient, mixed) still runs the per-group loop. `php -l` + `phpstan` clean.

## Latest: Rewriter — deterministic exclusions + page-event invalidation (2026-10-24)

Two verified patches to the catch-all exclusion pipeline. All claims checked against Polylang source (`src/filters.php`, `src/frontend/frontend.php`, `src/query.php`, `src/admin/admin-static-pages.php`) and WP core (`get_pages()` does not pass `fields` to its query; `WP_Query` supports only `ids`/`id=>parent` — so no slug-only API path exists; raw SQL rejected as a micro-optimization of a cold path).

- **Determinism (A):** [`compute_exclusion_patterns()`](core/rewriter/class-rewriter-path-utils.php) now calls `get_pages( array( 'lang' => '', ... ) )` — the same escape hatch Polylang's own admin code uses (`admin-static-pages.php:88`). Previously the list was language-filtered when computed by a frontend request but all-language when computed by cron/admin, so routing depended on which context last warmed the cache — the likely root cause of the recurring non-English 404s. The 500 cap now scales with language count (`cap × langs`, hard ceiling 1000) to keep per-language coverage without unbounded regex growth.
- **Invalidation (B):** the `posts:last_changed` cache key is replaced by an autoloaded `_frl_rewriter_excl_version` counter ([`invalidate_exclusion_patterns()`](core/rewriter/class-rewriter-path-utils.php)). Ordinary post saves no longer force a full recompute on the next request (cold-cache spike). Page lifecycle events (`post_updated` with routing-relevant diff, `wp_trash_post`, `untrash_post`, `before_delete_post`) bump the version AND schedule the debounced `frl_schedule_rewrite_flush()` (fires within ~5 min) — closing the real gap: routing uses the flushed `rewrite_rules` snapshot, and previously nothing flushed on page create/rename. Term events bump the version only (no flush). Language deletion still triggers the full `clear_rewriter_caches()`.

**Deploy:** re-activate or trigger any rewrite flush once so the saved rules pick up the all-language exclusions. Verify: create a top-level page in a non-default language → URL must resolve within ~5 min without a manual flush.

## Latest: Cache/options hardening — Phase 4 (2026-10-24)

Applied all Phase 4 items from the option/cache review. No per-request round-trip or query was added.

- **4.1** New [`frl_seed_option_defaults()`](includes/plugin-lifecycle.php:62), called from [`frl_activate_plugin()`](includes/plugin-lifecycle.php:30): seeds all registered option defaults at activation, so the first page load after install/reset does not pay a write-per-option burst. The write-on-read fallback remains for options added by later versions.
- **4.2** Autoload is now opt-in: [`FRL_FIELD_ATTRIBUTES['autoload']`](config/config-fields.php:37) and [`frl_normalize_autoload()`](includes/helpers/utilities.php:678) default to `'no'`; the runtime loaders' hardcoded `'yes'` fallbacks were flipped to `'no'`; the 50 redundant explicit `'autoload' => 'no'` entries in [`config-options.php`](config/config-options.php) were removed. `disable_plugin` and `disable_cache` are explicitly `'autoload' => 'yes'` (the only hot `get_option()` reads, in [`should_bypass()`](core/cache/class-cache-manager.php:139)). This shrinks WordPress's `alloptions` payload per request with no added query for the plugin. [`frl_migrate_option_autoload()`](includes/plugin-lifecycle.php:77) (called from [`frl_activate_plugin()`](includes/plugin-lifecycle.php:30)) refreshes existing rows via a direct `$wpdb` column update — `update_option()` does not change autoload when the value is unchanged.
- **4.3** New [`Frl_Cache_Manager::clear_keys()`](core/cache/class-cache-manager.php:547) + [`frl_cache_clear_keys()`](includes/helpers/functions-class-helpers.php:210); [`frl_clear_option_cache()`](core/cache/cache-cleanup.php:93) now clears all per-language keys in one `wp_cache_delete_multiple()` call instead of one per language.
- **4.4** [`frl_update_option()`](includes/helpers/functions-options.php:103) docblock notes that `false` means "not written" (unchanged value), not "failed".
- **TTL bump** — `options` group TTL `HOUR` → `DAY` in [`config-cache.php`](config/config-cache.php:50): `all_options` is actively invalidated by `frl_update_option()`/cleanup hooks, so the TTL is only a safety net for out-of-band DB edits; replaces the rejected refresh-ahead proposal with zero new machinery. Caveat: manual DB edits to `frl_*` options now propagate within 24 h, not 1 h.

**Deploy:** re-activate the plugin once to run `frl_migrate_option_autoload()` (refreshes existing rows' autoload). No cache key shape changed.

## Latest: Cache/options hardening — Phase 0 & 3 (2026-10-24)

Applied Phase 0 (documentation) and Phase 3 (architecture) from the option/cache review. Warm-on-flush (3.2) and refresh-ahead (3.4) were explicitly excluded.

- **0.1** [`frl_get_option()`](includes/helpers/functions-options.php:26) docblock corrected: `$bypass_cache` bypasses the *persistent* cache and returns the request's DB snapshot (the request-static DB cache is not re-read).
- **0.2** Single-site design documented in [`systemPatterns.md`](memory-bank/systemPatterns.md) and [`docs/CORE-CACHE.md`](docs/CORE-CACHE.md): cache keys are intentionally not blog-scoped.
- **0.3** [`frl_transients_static_cache()`](includes/helpers/functions-options.php:751) docblock notes the `frl_*` single-writer assumption.
- **0.4** [`frl_update_option()`](includes/helpers/functions-options.php:120) / [`frl_delete_option()`](includes/helpers/functions-options.php:161) comments note the `remove_all_filters()` namespace assumption.
- **3.1** **REVERTED.** The generation-token approach added one object-cache round-trip per request for no benefit — Redis supports `wp_cache_flush_group()`. [`purge_group_storage()`](core/cache/class-cache-manager.php:1155) keeps the original `wp_cache_flush_group()` + canary behavior.
- **3.3** [`get_multi()`](core/cache/class-cache-manager.php:826) no longer marks a group as "fully loaded" on the object-cache path (keys can't be enumerated there), so the flag now means "the complete set was loaded" (transient path only).

**Deploy:** none (no cache key or group-name shape changed).

## Latest: Cache/options hardening — Phase 1 & 2 (2026-10-24)

Applied the approved Phase 1 (correctness) and Phase 2 (performance) items from the option/cache review. No cache key shapes changed except the defaults-map key (one-time invalidation).

- **1.2** [`frl_get_plugin_options_db()`](includes/helpers/functions-options.php:302) now checks `$wpdb->last_error` and bails (logging) instead of caching an empty option set on a failed query.
- **1.3** [`generate_key()`](core/cache/class-cache-manager.php:214) guards `json_encode() === false` (invalid UTF-8) with a `serialize()` fallback, preventing all such array keys collapsing to `md5('')`.
- **2.5** [`frl_get_option()`](includes/helpers/functions-options.php:51) sets `$loaded = true` after a bypass load, so a later non-bypass read reuses the fresh DB snapshot instead of discarding it.
- **2.1** [`frl_get_all_plugin_options_settings()`](includes/helpers/functions-options.php:340) caches the merged defaults map **once** under `options_defaults_map` (was one full copy per key under `option_default_{key}`).
- **2.3** `$key_cache` is now capped at `$max_runtime_items` to bound growth in long-running processes.
- **2.2** Removed the no-op stampede lock from [`remember()`](core/cache/class-cache-manager.php:457) (see `systemPatterns.md`).
- **2.4** [`hard_cache_reset()`](core/cache/class-cache-manager.php:1544) skips the redundant transient DB delete when `purge_all()` already ran it (object cache non-functional), while still resetting the per-request transient static cache.
- **1.1** Falsey values (`false`/`null`) are now cacheable: object-cache reads use `wp_cache_get(..., $found)`; transient reads/writes use a `__frl_falsey__` sentinel wrapper ([`wrap_transient_value()`](core/cache/class-cache-manager.php:241) / [`unwrap_transient_value()`](core/cache/class-cache-manager.php:257)).

**Deploy:** flush plugin caches once (the `option_default_*` → `options_defaults_map` key change invalidates the old per-key entries).

## Latest: Gate filename sanitization to media uploads (2026-10-22)

`frl_get_file_nicename` was showing up as a slow span during plugin uploads (`update.php?action=upload-plugin`). Root cause: it is hooked to the core `sanitize_file_name` filter ([`admin/admin.php:30`](admin/admin.php:30)), which fires on **every** `sanitize_file_name()` call — including the uploaded plugin ZIP — and its first line calls `frl_get_option('image_filename_sanitize')`, forcing the plugin's full options snapshot to load on the upload request.

- **New helper** — [`frl_is_media_upload_request()`](includes/helpers/functions-access-control.php): whitelist of the three standard media-upload entry points — media modal (`$_REQUEST['action'] === 'upload-attachment'`), `media-new.php`, and REST `/wp/v2/media` (block editor + programmatic uploads). Static-cached per request.
- **Guard** — [`frl_get_file_nicename()`](admin/admin.php:418) now returns the filename unchanged when `! $as_title && ! frl_is_media_upload_request()`, so the `frl_get_option()` call is skipped entirely for plugin/theme installs. The `$as_title === true` path (from `frl_update_image_metadata`, already media-gated via `add_attachment`) is untouched.
- **Verified coverage:** post edit screen (classic → `async-upload.php?action=upload-attachment`; Gutenberg → REST `/wp/v2/media`) and Greenshift (`wp.media` modal + `wp.apiFetch /wp/v2/media`). Greenshift has no custom media-upload endpoint.
- **Deploy:** none (no cache key or option shape changed).

## Latest: Shared message templates + WS Form email directives (2026-10-22)

Decoupled `wsform` from `call_to_actions` and added `@@...@@` email directives.

- **New shared config** — [`config/config-message-templates.php`](config/config-message-templates.php): `FRL_CTA_TEMPLATES` (per-env CTA url/template/subject, moved out of the CTA module) + `FRL_EMAIL_TEMPLATES` (email template whitelist). Registered in [`config/config.php`](config/config.php). Both modules now read from `config/` — neither depends on the other.
- **CTA module split** — [`config-constants-call_to_actions.php`](modules/call_to_actions/config-constants-call_to_actions.php) now holds `CTA_WEBHOOK_URLS` (per-env webhook destinations) + field map + rate limits. `CTA_WEBHOOK_CONFIG` removed; [`call_to_actions.php`](modules/call_to_actions/call_to_actions.php) and [`webhooks-call_to_actions.php`](modules/call_to_actions/webhooks-call_to_actions.php) updated.
- **Email templates moved** — `assets/html/email-confirmation.html` → [`assets/templates/emails/confirmation_email_weekday.html`](assets/templates/emails/confirmation_email_weekday.html) + `confirmation_email_weekend.html` (split; links now `@@cta:whatsapp@@` / `@@cta:telegram@@`).
- **New email directives** — [`modules/wsform/email-directives.php`](modules/wsform/email-directives.php): `@@include:<name>@@` (loads a whitelisted template, cached by `filemtime`) and `@@cta:<action_id>@@` (builds the CTA URL from `FRL_CTA_TEMPLATES`). Two hooks on `wsf_actions_post_submit`: priority 5 (includes, before translation) and priority 15 (CTA links, after translation — keeps the translation cache key stable). `frl_encode_uri_component()` reproduces JS `encodeURIComponent()` (leaves `! ' ( ) *` literal). Reference ID read from the webhook field map via `frl_wsf_get_all_webhook_configs()`.
- **WS Form setup** — email body is now a single directive: `@@include:confirmation_email_weekday@@` / `@@include:confirmation_email_weekend@@` (weekday/weekend selected by WS Form's existing conditional logic).
- **Deploy:** flush plugin caches (new config constant + template paths).

## Latest: WS Form email action translation (2026-10-19)

Extended [`frl_wsf_translate_submit_actions()`](modules/wsform/wsform.php:177) (hook `wsf_actions_post_submit`, raw action configs before `WS_Form_Action_Email::post()` runs) to translate WS Form Pro email actions, verified against `ws-form-pro/includes/actions/class-ws-form-action-email.php`:

- **Action id** `email`; keys `action_email_subject`, `action_email_message_html_editor`, `action_email_message_text_editor` (plain `action_email_message_textarea` intentionally left untouched — it carries submission data, matching WS Form's own translatable meta keys).
- **Subject**: whole-string via `frl_get_translation()`.
- **HTML body**: new [`frl_wsf_translate_html_tokens()`](modules/wsform/wsform.php) resolves `{{...}}` text tokens (reusing [`frl_is_token_match()`](includes/helpers/utilities.php:395) exclusions) and `##slug##` permalink tokens in a single alternation pass, leaving HTML/CSS and `#variables` (`#email_submission`, `#field_225`) intact. Cached in `translations` group, keyed by `md5(content) + lang + frl_get_translation_version()`. Translator-disabled path strips `{{...}}` and neutralises `##slug##` (Safe-Mode parity).
- **Robust slug lookup**: `##slug##` resolves via `frl_get_translation_batch_permalinks( $slugs, $lang, FRL_TRANSLATOR_SOURCE_LANG )` using a new optional `$lookup_language` param threaded through [`Frl_Translation_Service::get_translation_batch_permalinks()`](core/translator/class-translation-service.php:446) and [`frl_get_post_id_by_slug()`](includes/helpers/functions.php:344) (cache key now includes the lookup language). This forces source-language slug resolution and never depends on the request/Polylang-default language. All new params are optional → existing callers (block engine, shortcodes, stats) unchanged.
- **Language** derived once from `submit->meta['post_id']` (REST submit has no language prefix), same as the existing message-action path. Zero new hooks. `php -l` + phpstan + phpcs clean. **Deploy:** flush caches (the `postslug2id_*` key shape changed).

## Latest: Environment display — content-type option rows (2026-10-18)

"Managed Options" in the Environment widget now distinguishes content-bearing field types in [`Frl_Environment_Display::render_plugin_options()`](admin/components/class-display-environment.php:253):

- **Single map** drives both detection and label: `$content_type_labels = array( 'text' => 'text', 'textarea' => 'textarea', 'textlist' => 'textlist', 'html' => 'html' )`. Adding a content type is a one-line change; detection is `isset( $content_type_labels[ $field_type ] )`, label is `$content_type_labels[ $field_type ]`.
- **Section placement** stays keyed on the current environment config value (`$env_config['plugin_options'][$key]`): for a content-type field, a non-empty configured string counts as "Enabled by default". Previously such fields always fell to "Disabled by default" because `filter_var( ..., FILTER_VALIDATE_BOOLEAN )` returns false for ordinary text.
- **Dot + label** reflect the live stored value (`frl_get_option`): filled → green dot labelled with the type (`text`/`textarea`/`textlist`/`html`); empty → red dot labelled `empty <type>`. File-typed options are caught by the `'file'` branch first and now show a green dot labelled `file` (present) or a red dot labelled `no file` (empty). Boolean rows are unchanged.
- Field type resolved once per option via `frl_get_all_plugin_options_settings( $key )['type']`.

Section and dot can legitimately disagree (config vs live value), matching the existing boolean behaviour.

## Latest: Fix Last Updates Widget LP Queries and Author Tracking (2026-10-17)

**Bug:** In the Last Updates dashboard widget, recent updates to custom post type `lp` (and other multilingual posts) did not appear, showing only edits from several days ago. Additionally, the last editor displayed was stale or defaulted to the original post author rather than the editor who made the changes yesterday. In contrast, the User Visits widget showed yesterday's activity accurately.

**Root causes:**
1. Polylang automatically filtered the widget's `WP_Query` by the currently active admin language because `'lang' => ''` was omitted.
2. The widget relied on `_edit_last` postmeta, but the Block Editor (Gutenberg) updates posts via the WordPress REST API which does not set or update `_edit_last` (WordPress Core Trac #50255).
3. Post saves did not invalidate the widget's 15-minute `'admin'` cache key (`widget_last_posts`).

**Fix:**
- [`admin/widgets/widget-last-posts.php`](admin/widgets/widget-last-posts.php:28) — Added `'lang' => ''` to query all languages.
- [`admin/widgets/widget-last-posts.php`](admin/widgets/widget-last-posts.php:52) — Implemented editor resolution: 1) First retrieve by `_edit_last` postmeta; 2) Fallback to latest non-autosave revision (`wp_is_post_autosave`); 3) Fallback to post creator (`post_author`).
- [`admin/widgets/widget-user-visits.php`](admin/widgets/widget-user-visits.php:155) & [`assets/css/admin.css`](assets/css/admin.css:112) — Switched column order in User Visits widget so Author/User comes first and Post/URL comes second (`1.7fr 3fr 92px`).

## Latest: Removed FRL_PUBLIC_ACTIONS feature (2026-10-16)

Removed the `FRL_PUBLIC_ACTIONS` constant and all its usages. Previously `clear_website_transients` was executable by any logged-in user (`read`) without a nonce check; now all GET actions uniformly require `frl_has_access()` (`delete_plugins`).

**Files changed:**
- [`config/config-base.php`](config/config-base.php) — removed `FRL_PUBLIC_ACTIONS` constant.
- [`includes/helpers/functions-action-handlers.php`](includes/helpers/functions-action-handlers.php:33) — central dispatch gate simplified to `frl_has_access()` only.
- [`admin/helpers/functions-admin-action-handlers.php`](admin/helpers/functions-admin-action-handlers.php:807) — `frl_verify_plugin_action_nonce()` no longer downgrades to `read`/skips nonce; always `FRL_PLUGIN_ACCESS`.
- [`docs/PLUGIN-PERMISSIONS.md`](docs/PLUGIN-PERMISSIONS.md) — removed public-actions documentation.

## Latest Fix: CPT Base Translation restricted field not disabled (2026-10-16)

**Bug:** The "CPT Base Translation" field (`translate_cpt_slugs`, `custom` type) showed the "restricted" message for non-superadmins, but its per-CPT `<textarea>` elements were not disabled — and their values were still saved.

**Root cause:** `frl_field_callback()` computes `$disabled_attr` but only passes it to `frl_ui_render_field()` for standard fields. The `custom` branch calls the callback with no arguments, and `frl_render_rewrite_multilingual_cpts()` rendered each textarea without a `disabled` attribute. Additionally, the per-CPT runtime options (`translate_cpt_slugs_{cpt_slug}`) were built without a `restricted` flag, so the save guard in `_get_submitted_values()` did not skip them.

**Fix:**
- [`admin/components/class-settings-fields.php`](admin/components/class-settings-fields.php:399) — pass `$disabled_attr` into the custom callback.
- [`admin/helpers/functions-admin-ui.php`](admin/helpers/functions-admin-ui.php:315) — `frl_render_rewrite_multilingual_cpts( $disabled_attr = '' )` now emits `disabled="disabled"` on each textarea.
- [`includes/helpers/functions-options.php`](includes/helpers/functions-options.php:557) — runtime per-CPT fields now carry `'restricted' => true`, so the save guard blocks them for non-superadmins.

**Deploy note:** field definitions are cached in the `adminui` group (`runtime_translate_cpt_slugs`); flush plugin caches once after deploy.

## Latest: Docs Viewer — Admin interface documentation browser (2026-10-14)

New Documentation tab on the plugin settings page: 15 `docs/*.md` files are now browsable via AJAX within the admin UI. Server-side Parsedown Extra rendering, cached in `staticdata` (persistent heavy group, `WEEK_IN_SECONDS` TTL) keyed by `filemtime`. No extra permission gating — page access (`FRL_PLUGIN_ACCESS` = `delete_plugins`) equals docs access. Uses the existing Prism.js + `frl_content_loaded` event for code highlighting. Link rewriting: doc→doc `.md` links become in-viewer navigation (`data-frl-doc`); source-file links become plain `<code>` text.

**Files created:**
- [`admin/components/class-display-docs.php`](admin/components/class-display-docs.php) — discovery (`frl_docs_get_documents()`), rendering (`frl_docs_render_document()`), tab shell callback, AJAX handler `frl_post_ajax_docs_content()` (auto-discovered via `frl_autodiscover_admin_actions()`)
- [`assets/lib/parsedown/Parsedown.php` + `ParsedownExtra.php`](assets/lib/parsedown/) — vendored MIT Markdown renderer (lazy-required only on cache miss)
- [`assets/js/admin-docs.js`](assets/js/admin-docs.js) — AJAX fetch + `frl_content_loaded` event trigger for Prism
- [`assets/css/admin-docs.css`](assets/css/admin-docs.css) — reader typography (tables, code blocks, index sidebar)

**Files changed:**
- [`admin/ui/ui-admin-settings.php`](admin/ui/ui-admin-settings.php) — `require_once class-display-docs.php`, `frl_tab_register_tab('docs', …)` at `POSITION_LAST`, `add_action('frl_docs_content', …)`
- [`admin/ui/ui-asset-loader.php`](admin/ui/ui-asset-loader.php) — registered `docs-css`/`docs-js` assets, localized `frlDocsData`
- [`docs/ADMIN-UI.md`](docs/ADMIN-UI.md) — directory tree, asset tables, localized scripts updated
- [`memory-bank/progress.md`](memory-bank/progress.md) — new Docs Viewer row in Admin Subsystems

**Plan:** [`plans/ADMIN-DOCS-VIEWER.md`](plans/ADMIN-DOCS-VIEWER.md)

## Prior: Rewriter — Lang-prefixed CPT exclusion + cache invalidation fix (2026-09-16)

**Bug:** `/ru/about/rati-iese-abashmadze/` returned 404. The Taxonomy Base Removal catch-all rule hijacked the URL before the team-member CPT's native rewrite rule could match.

**Root cause:** [`compute_exclusion_patterns()`](core/rewriter/class-rewriter-path-utils.php:297-318) added CPT rewrite slugs (e.g., `about`) to the catch-all exclusion list but NOT their lang-prefixed variants (`ru/about`, `ar/about`, `zh/about`). The catch-all's negative lookahead grouped exclusions by language prefix — `ru/about` was missing from the `ru/` group, so `/ru/about/...` URLs were captured.

**Fix 1 — Exclusion patterns:** CPT rewrite slugs now get lang-prefixed variants, matching the pattern already used for pages.

**Fix 2 — Cache invalidation:** `clear_rewriter_caches()` was hooked only to `update_option_permalink_structure`, which WordPress fires only when the option value actually changes. A no-op Save on Settings → Permalinks flushes rewrite rules (via `$wp_rewrite->flush_rules()`) but never triggers the cache clear, leaving stale exclusion patterns in Redis. New `invalidate_rewriter_caches()` method (cache-only, no recursive flush) hooked to `flush_rewrite_rules` action. Redundant `update_option_permalink_structure`, `update_option_category_base`, and `update_option_tag_base` hooks removed — `flush_rewrite_rules` always fires after those options are updated on the permalink page.

**Files changed:**
- [`core/rewriter/class-rewriter-path-utils.php`](core/rewriter/class-rewriter-path-utils.php:297-318) — lang-prefixed CPT slugs
- [`core/rewriter/class-rewriter.php`](core/rewriter/class-rewriter.php:397-454) — new `invalidate_rewriter_caches()`, refactored `clear_rewriter_caches()`, new `flush_rewrite_rules` hook, removed 3 redundant hooks

## Schema Module Refactor (2026-09-13)

Complete rewrite of the JSON-LD schema subsystem. Removed SASWP dependency entirely — all schemas now generated from local definition files via a unified orchestrator.

**Architecture:** [`public/schema/`](public/schema/)
- `class-schema-orchestrator.php` — single output point, context → build → enrich → output
- `class-context.php` — request context detection (global, singular, archive, etc.)
- `class-builder.php` — recursive schema builder from definition arrays
- `class-resolver.php` — translation pass (FRL_SCHEMA_TRANSLATE_KEYS)
- `class-enricher.php` — term + Person property builders from WP data
- `definitions/` — one file per Schema.org type (Organization.php, Article.php, etc.)
- `mappings/` — dynamic WP-data-to-schema mappings (terms.php, persons.php)

**Key changes:**
- `schema_generator` option → `schema_enabled` (master toggle)
- `schema_organization_*` options → `schema_org_*` (brevity)
- `schema_org_name`/`schema_org_url` removed — auto-retrieved from site
- `schema_org_description` removed — uses `get_bloginfo('description')`
- `schema_contact_url` removed — auto-resolved via `frl_get_contact_page_url()`
- Individual `schema_social_*` → single `schema_org_sameas` textlist
- All hardcoded values (address, areaServed, founder, knowsAbout, etc.) → configurable options
- `_if` toggle system: each definition gated on its option (e.g., `schema_organization`, `schema_article`)
- `@source` system for textlist-based arrays (sameAs, availableLanguage, knowsAbout)
- `frl_schema_strip_empty()` drops sub-objects with only `@type` (empty contactPoint, address, etc.)
- Performance: memoized placeholders, fast-path for non-`{{` strings, static option maps, skip translation when no keys configured

**Legacy preserved** in `public/schema-legacy/` for reference during cutover.

## Current Focus

Post-save performance audit (2026-08, remediation applied 2026-08-30): full report + change log in `plans/PERFORMANCE-AUDIT-post-save.md` §7. APPLIED: (1) language-mismatch fix — the 4 unversioned key families (`post_*_translations`, `post_*_schema`, `langswitcher_*`, `featured_img_*`) are now version-suffixed `_v{frl_get_post_cache_version()}` at their writers (`class-translation-service.php`, `generator.php`, `shortcodes.php`, `performance.php`); `frl_clear_post_cache()` no longer key-purges them (the admin-side purge could only reach the default-language copy — `generate_key()` language prefix) — the `_frl_post_version` bump invalidates all 4 languages; `frl_clear_tracked_meta_cache()` retained. php -l + phpstan clean. DEPLOY: flush plugin caches once after deploy (key shapes changed). (2) Dead assets `admin-bulk-resave.js` + `admin-avatar.js` deleted (+ `docs/ADMIN-UI.md` rows); `admin.js` KEPT intentionally (maintainer: will be used later — do not flag as dead). (3) Permalinks-writer audit COMPLETE → `edited_term` group purge RETAINED (not replaced): `pattern_*`/`cf_terms_*`/`post_{id}_{lang}` keys are not enumerable from a term ID; group clears are already deduped per request and hit `wp_cache_flush_group()` (no DB scan) when the object cache is functional. (4) RETRACTED finding: `ui-asset-loader.php` assets (Prism/CodeMirror/asset loader) ARE already gated — the file is only required via `frl_load_plugin_ui()` behind `frl_is_plugin_context()`; never loaded on edit screens. Plugin save path now ~10–14 SQL queries. Verified negatives: no plugin JS auto-fires admin-ajax on edit screens; no synchronous CDN/external purge anywhere; Heartbeat only throttled (60/120 s); ACF module inert on post saves.

ACPT root cause (identified via Query Monitor + Network waterfall): ACPT's `checkIsVisibleAction` admin-ajax endpoint — fired unconditionally by `ACPTConditionalRules.min.js` even with zero conditional rules — runs an N+1 `MetaRepository::getMetaFieldById()` per field per call (~30-60 queries × 4+ calls per save, 2.4-6.0s each), and all other save requests queue behind these. Workaround added to [`thirdparty.php`](../modules/thirdparty/thirdparty.php): `frl_thirdparty_dequeue_acpt_conditional_rules()` dequeues `ACPTConditionalRules`/`-run` on block-editor screens, hooked to `wp_print_scripts` (NOT `admin_enqueue_scripts` — ACPT enqueues during metabox generation, which runs later). Guarded by `frl_is_thirdparty_plugin_active('advanced-custom-post-type/advanced-custom-post-type.php')`. CAVEAT in docblock: if ACPT conditional visibility rules are ever added, remove the dequeue.

Lead-retrieval audit of `wsform` + `call_to_actions` (2026): fixed Cloudflare IP-detection gap, `pbp` sync-webhook blocking, dedup-key collision risk. CTA extraction from wsform module into standalone `call_to_actions` module. Implementation complete. Plan rewritten as as-built document in [`plans/EXTRACTION-call-to-actions.md`](plans/EXTRACTION-call-to-actions.md). Post-extraction hardening: webhook deduplication fix + PHP 8+ defensive type checks applied to wsform and webhook helpers. Added Polylang translation support for CTA template/subject messages.

## Latest Fix: Phone sanitizer — explicit-international pattern gate (2026-08-31)

`+76464968946` passed the length-only '+' path, was rejected by respond.io, and the whole lead was dropped. [`frl_phone_number_sanitize()`](../modules/wsform/phone/phone-validation.php) now gates explicit '+'/'00'/'011' input via `frl_phone_intl_pattern_valid()`: the national part must match the union of the patterns of all configs sharing the resolved country code (RU+KZ for 7; mobile+landline for 27); codes with no patterned config keep length-only. Accepted policy: patterns are mobile-only, so explicit '+' landlines now blank (lead survives via 'Phone Raw'). Bare path: all same-code configs are tried before declaring malformed — fixes bare Kazakh false negatives. Test: `tests/phone-pattern-gate-test.php` (runs the real module files; `phone-sanitizer-audit.php` is an inline snapshot that no longer reflects live behavior). Blanked numbers send an email-only admin notification (`frl_wsf_notify_phone_blanked()` in `webhooks-wsform.php`, per-request dedup, includes the submitter's `Email` from the payload), gated behind the new `wsform_phone_invalid_notify` option (default off).

## Latest Fix: Rewriter — Taxonomy Base Removal 404 on `/%category%/%postname%/`

- **Bug**: `remove_tax_base = category` with permalink `/%category%/%postname%/` caused 404 on post URLs like `/gospel/gospel-of-john/`. The catch-all rewrite rule `(.+?)/?$` at `'top'` priority intercepted the URL before WordPress's built-in permalink rules could match.
- **Fix**: Added post-resolution fallback in [`Frl_Taxonomy_Base_Removal_Feature::resolve_request()`](core/rewriter/features/class-taxonomy-base-removal-feature.php:428-450). When no term matches a multi-segment slug, attempts `get_posts()` by `name` before giving up.
- **Plan**: [`plans/TAXONOMY-BASE-REMOVAL-404-FIX.md`](plans/TAXONOMY-BASE-REMOVAL-404-FIX.md)
- **Lines changed**: +17 in one method, one file. No other files modified.

## Lead-Retrieval Audit Fixes (latest)

1. **🐛 `frl_get_client_ip()` Cloudflare gap** — [`utilities.php`](../includes/helpers/utilities.php): checked `REMOTE_ADDR` first, which is always syntactically valid, so the `HTTP_X_FORWARDED_FOR`/`HTTP_CLIENT_IP` fallback never ran behind an unfixed CDN. Reordered to `HTTP_CF_CONNECTING_IP` → `HTTP_X_FORWARDED_FOR` → `HTTP_CLIENT_IP` → `REMOTE_ADDR` last. This collapsed CTA per-IP rate limiting to a site-wide limit and mislabeled every lead's "User IP" field.
2. **`pbp` webhook was blocking the form's success response** — [`WSFORM_ALL_WEBHOOKS_CONFIG['pbp']`](../modules/wsform/config-constants-wsform.php) had `use_cron: false` (temporary testing), causing a synchronous cURL call (up to 20s) inside the same request that returns WS Form's "Thank you" response. Flipped to `true`, matching `pbs`.
3. **Bounded async webhook retry** — `frl_webhook_dispatch` cron handler ([`functions-webhook.php`](../includes/helpers/functions-webhook.php)) now retries up to `FRL_WEBHOOK_RETRY['max_attempts']` times with backoff delays from `FRL_WEBHOOK_RETRY['delays']` (both in [`config-base.php`](../config/config-base.php)) on failure. Async-only by design — sync callers are inside a live request and must not be delayed further.
4. **Dedup-key collision fix** — [`frl_wsf_submit_webhook()`](../modules/wsform/webhooks-wsform.php) dedup key now includes `md5($webhook_url)`, not just the submission ID, so a form with multiple webhook destinations for the same `form_id` won't have its second destination silently dropped as a "duplicate" of the first.
5. **🐛 `_frl_uuid` always uses UUID now** — [`frl_send_webhook_async()`](../includes/helpers/functions-webhook.php:48): changed from `$dedup_key ?? wp_generate_uuid4()` to always `wp_generate_uuid4()`. Previously, when a caller passed a deterministic `$dedup_key` (e.g., `'cta_' . md5($ip . $action_id)` from the CTA handler), that same value leaked into `_frl_uuid`, causing `wp_schedule_single_event()`'s ~10-minute dedup to reject subsequent calls after the 5-second transient expired. `_frl_uuid` is cron-internal only — never reaches the webhook payload — so there's no reason to tie it to the caller's dedup key.

## Changes Made

1. **Extracted CTA logic from wsform into `modules/call_to_actions/`** — New standalone module for WhatsApp, Telegram, and Email CTA click handling with marketing webhook dispatch.
2. **Created shared webhook utilities** — [`includes/helpers/functions-webhook.php`](includes/helpers/functions-webhook.php): `frl_send_webhook()` (sync cURL dispatch) and `frl_send_webhook_async()` (fire-and-forget via WP-Cron `frl_webhook_dispatch` action). Webhook deduplication removed — all webhooks always dispatch.
3. **Moved channel tracking to `public/`** — [`public/channel-tracking.php`](public/channel-tracking.php) with `frl_channel_tracking_init()` (guarded) and `frl_channel_tracking_enqueue()`. `CT_ATTR_*` constants live in [`config/config-channel-tracking.php`](config/config-channel-tracking.php). JS split into [`assets/js/public-channel-tracking.js`](assets/js/public-channel-tracking.js) (attribution, field population via `fieldPrefix + key`) and [`assets/js/public-cta-actions.js`](assets/js/public-cta-actions.js) (CTA click handling, unconditional `preventDefault()` for anchor-tag safety).
4. **wsform webhooks simplified** — [`modules/wsform/webhooks-wsform.php`](modules/wsform/webhooks-wsform.php): calls `frl_send_webhook_async()` / `frl_send_webhook()` directly. Old dedupe wrapper `frl_wsf_should_send_webhook()` and cron wrapper `frl_wsf_execute_webhook_submission()` removed. Old `frl_wsf_button_webhook_handler()` AJAX handler removed.
5. **🐛 Fixed module key hyphenation bug** — Module key `call-to-actions` contained hyphens which break PHP variable-name auto-discovery in [`functions-modules.php:251`](includes/helpers/functions-modules.php:251). Renamed to `call_to_actions` across all files.
6. **Phase 4: Cleaned up wsform dead code** — Removed `WS_BUTTON_ACTIONS`, `WS_BUTTON_WEBHOOK_SERVICE_META`, `WS_ATTR_*` constants. Deleted `config-constants-webhooks.php` (content merged into `config-constants-wsform.php`). `webhooks.php` renamed to `webhooks-wsform.php`. `wsform_channel_tracking` option preserved (not removed) — defaulted to `false` in env defaults, available for per-brand enablement.
7. **Renamed AJAX action `frl_button_webhook` → `frl_cta_webhook`** — updated in JS and PHP. Deploy with full cache flush.
8. **Renamed `WS_STATS_FORM_IDS` → `WSFORM_STATS_FORM_IDS`** — consistency with `WSFORM_ALL_WEBHOOKS_CONFIG`.
9. **CTA_WEBHOOK_CONFIG restructured to `{url, use_cron}` objects** — matches wsform's `WSFORM_ALL_WEBHOOKS_CONFIG` per-entry pattern. `use_cron` defaults to `false` (sync).
10. **CTA handler: per-IP rate limiting** — `CTA_WEBHOOK_RATE_LIMIT` (30) / `CTA_WEBHOOK_RATE_WINDOW` (60s) in [`config-constants-call_to_actions.php`](modules/call_to_actions/config-constants-call_to_actions.php). Payload built via declarative `CTA_WEBHOOK_FIELDS` constant with sentinel values.
11. **Removed dead `fieldMapping`** — JS now always uses `fieldPrefix + key` (e.g., `channel_source`). Matches `data-name` attributes on WS Form hidden fields.
12. **Removed dead `config-options-call_to_actions.php`** — initially removed; later restored because the file provides `cta_webhook` and `cta_use_cron` admin toggles. Only the module on/off toggle moved to env config (`config-defaults.php` / `config-environment.php`, auto-created by `frl_modules_load_options_defaults()`).
13. **🐛 Fixed webhook deduplication bug** — [`frl_send_webhook_async()`](includes/helpers/functions-webhook.php): optional `$dedup_key` param (defaults to `wp_generate_uuid4()`) stored as sibling cron arg `_frl_uuid` to defeat WP-Cron's 10-minute event dedup. UUID never reaches the webhook payload. Callers omit `$dedup_key` (auto-UUID) — wsform's `error_validation_actions` guard already prevents webhooks on failed submissions, and double-click is a JS-level concern (WS Form disables the submit button).
14. **🛡️ PHP 8+ defensive type guards in wsform** — [`frl_wsf_submit_webhook()`](modules/wsform/webhooks-wsform.php): `is_array()` check before `count()` on `error_validation_actions`. [`frl_wsf_spam_filter_submission()`](modules/wsform/webhooks-wsform.php): array guard before `[]` append; `is_scalar()` guard before `(string)` cast. [`frl_wsf_translate_fields()`](modules/wsform/wsform.php): `isset()` guard before deep object traversal on `$form->meta->action->groups[0]->rows`.
15. **🌐 CTA template translation via Polylang** — [`call_to_actions.php`](modules/call_to_actions/call_to_actions.php): `frl_get_translation()` called on `template` and `subject` in the `frl_channel_tracking_cta_actions` filter. Strings auto-register via `Frl_Translation_Service::queue_string_registration()` on first use, cached in `translations` group. Falls back to original English if Polylang inactive or no translation exists. `{reference_id}` placeholder passes through translation as literal text.
16. **🐛 CTA template newlines stripped by Polylang translation** — [`call_to_actions.php:52-54`](modules/call_to_actions/call_to_actions.php): `\r\n` in translated templates was stripped by Polylang's `pll_translate_string()`. Fixed by running `str_replace('{br}', "\r\n", ...)` **after** `frl_get_translation()`. RU translator adds `{br}` in their translation where line breaks are needed; existing translations stay valid (only the query string is unchanged).
17. **🔇 WEBHOOK DEDUP notifications silenced** — [`functions-webhook.php`](includes/helpers/functions-webhook.php): removed the `frl_log()` calls from the dedup branches of both `frl_send_webhook()` and `frl_send_webhook_async()`. A duplicate dispatch inside the lock window is an expected condition (double-click / re-fire), but each one wrote an `error_log()` line that nginx surfaced as `[error] FastCGI sent in stderr` and could also email the admin (default `$force_email = true`). Both branches still return success — now silently.

## Latest Fix: Schema — Person builder nested-object resolution (2026-09-12)

[`frl_schema_builder_build_person_from_ref()`](public/schema/properties/builders.php:249) treated all arrays as list-of-meta-keys (`sameAs`-style), losing nested object structure for associative definitions like `hasCredential`. Fix: the `is_array($source)` branch now checks [`array_is_list()`](public/schema/properties/builders.php:267) — list arrays (numeric keys, e.g. `sameAs`) keep existing behavior; associative arrays (string keys, e.g. `hasCredential`) delegate to new [`frl_schema_builder_build_person_nested()`](public/schema/properties/builders.php:338) which recurses with the same `post_*`/meta resolution rules but without hardcoded `@type => Person`. `@type` values in nested defs pass through literally. Zero regression: `sameAs` is a proper PHP list (numeric keys 0,1,2,3) — `array_is_list() === true` → identical code path as before. PHP 8.5 runtime confirmed.

## Prior: Schema — custom-field {{placeholder}} resolution in SASWP property injection (2026-09-12)

[`frl_schema_get_placeholders()`](includes/helpers/functions-schema.php:98) only maps 12 fixed tokens. Custom-field `{{...}}` tokens (e.g., `{{team-settings_team-education}}`, `{{team-settings_team-whatsapp}}`) were not in the map and survived as literal strings into JSON-LD output. Fix: new [`frl_schema_resolve_remaining_placeholders()`](includes/helpers/functions-schema.php:313) walks props after known-placeholder resolution, catches any remaining `{{field_name}}` patterns via `preg_replace_callback`, and resolves them through `get_post_meta()`. Called in [`frl_thirdparty_sanitize_schemas()`](modules/thirdparty/thirdparty.php:265) right after `frl_schema_resolve_post_placeholders()`. Also added generic [`frl_schema_replace_placeholders_callback()`](includes/helpers/functions-schema.php:330) for reusable `{{...}}` → callback resolution. No perf impact: the regex only fires on strings still containing `{{` after the main pass (zero for all known tokens). Properties are cached per-language.

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
