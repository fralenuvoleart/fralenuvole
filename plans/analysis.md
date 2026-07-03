Now let me look at the translator adapters (Polylang adapter specifically) and the functions-access-control.php to check for any additional hooks related to save_post, profile_update, etc. Also check the functions-modules.php to see module loading.

Let me also check the environment config to understand the get_domain_config flow, which involves the Frl_Environment_Config class.

I now have enough information to write a comprehensive report. Let me compile my findings.

Key findings from analyzing the codebase:

MAJOR FINDINGS
1. HARD CACHE FLUSH ON EVERY save_post (with heavy operations)
File: core/cache/cache-cleanup.php:16-17, config/config-cache-operations.php:80-98, config/config-cache.php:84-123

When a post is saved, frl_clear_post_cache() is called. Inside it:

frl_cache_clear('postdata', ...) - clears specific post cache entries (reasonable)
frl_clear_tracked_meta_cache('post', $post_id) - clears tracked meta (reasonable)
frl_cache_clear('shortcodes', ...) - clears specific shortcode cache entries (reasonable)
BUT then it calls frl_cache_clear('postdata', ...) multiple times for image sizes, triggering multiple cache operations
Meanwhile, the rewriter independently hooks into save_post at line 497-504 of class-rewriter.php to delete a transient. This is fine, but it adds another DB operation.

2. CACHE DEPENDENCY CASCADE ON options GROUP CLEAR
File: config/config-cache.php:84-123

When options group is cleared, it cascades to: theme, html, environment, admin, adminui, rewriter → permalinks. This is a cascading clear of 7 cache groups every time an option changes or when clear_options operation is triggered.

The clear_options operation is the lowest-impact one used by environment enforcement when only options change - but it still cascades through all those groups.

3. TRANSLATION CACHE CLEAR TRIGGERS METAFIELDS CLEAR
File: config/config-cache.php:101-103, core/cache/cache-cleanup.php:189-197

When translations are saved (pll_save_strings_translations), frl_clear_translation_cache() is called which:

Updates translation_version option
Clears translations cache group which cascades to metafields
This means every string translation save clears ALL metafields translations for the entire site.

4. frl_clear_post_cache CLEARS ALL IMAGE SIZE VARIANTS
File: core/cache/cache-cleanup.php:42-109

For every post save, it iterates through ALL image sizes (thumbnail, medium, large, full) and calls frl_cache_clear for each one. That's potentially 4+ cache clear calls for featured image variants alone.

5. RE-WRITER: MISSING REWRITE RULES TRIGGERS FLUSH ON COLD CACHE
File: core/rewriter/class-rewriter.php:522-533

On wp_loaded, the rewriter checks if get_option('rewrite_rules') === false. When the transient (rewrite_flush_cooldown) is cold (not set), it performs a full rewrite flush (frl_flush_rewrite_rules()). This happens:

After any cache flush that clears transients
After object cache flush
This is a heavy operation that rebuilds ALL rewrite rules
THIS IS THE BIGGEST ISSUE FOR COLD CACHE. When frl_flush_rewrite_rules() is called, it does:

delete_option('rewrite_rules')
frl_cache_clear('options') which cascades to rewriter→permalinks
flush_rewrite_rules(true) which regenerates ALL rewrite rules
Notifies third-party cache plugins
6. OPTIONS PRELOAD ON EVERY REQUEST
File: config/config-cache.php:125-140, core/cache/class-cache-manager.php:81-97

The cache manager's auto_preload() preloads several cache groups on every request:

Frontend: options, rewriter, environment, theme, versions, html
Backend: options, environment, theme, versions, admin
When cache is cold (after flush), each of these preloads triggers get_multi(null) which:

For transient fallback: queries the DB with LIKE on wp_options to load ALL entries for each group
For object cache: still sets up group tracking
This is necessary for performance once cached, but on cold cache ALL of these hit the database simultaneously.

7. frl_get_option AUTO-SAVES MISSING OPTIONS TO DB
File: includes/helpers/functions-options.php:29-93, 768-797

When an option key is not found in cache, frl_handle_missing_option_key() queries the DB with get_option(). If still not found, frl_set_missing_option_default() calls frl_update_option() to SAVE the default value to the database. This means:

On cold cache, every missing option triggers an INSERT into wp_options
If multiple requests come in simultaneously, this causes DB write contention
8. frl_cache_clear('options', 'all_options', false) — PARTIAL INVALIDATION
File: includes/helpers/functions-options.php:129

When options are updated, only the all_options key is cleared (without dependency cascade). This is actually GOOD behavior (avoids cascading). However, the 'false' dependency parameter means dependent groups like adminui are NOT cleared, which could result in stale cached data if UI depends on this option.

9. get_provider_details() — EXPENSIVE OBJECT CACHE DETECTION
File: core/cache/class-cache-manager.php:195-368

The get_provider_details() function is called on every cache operation to determine if object cache is functional. On cold cache, it:

Reads object-cache.php file content (up to 2KB)
Checks for multiple plugin names via _is_plugin_globally_active()
Calls set_transient() to cache the result for 1 week
On cold cache, the plugin detection function _is_plugin_globally_active() calls frl_is_thirdparty_plugin_active() which has its own caching layer.

10. frl_trace_logged_user_visits() — DB WRITES ON EVERY PAGE
File: includes/shared/logged-user.php:477-563

This function is called on wp_footer and admin_footer for every logged-in user. On every page load:

Checks a transient dedup key in visits cache group
If not already tracked (cold cache), fetches user meta
Iterates through stored visits to check for duplicates
Writes updated visits back to user meta
This is particularly heavy on cold cache because user meta must be fetched from DB.

11. SHUTDOWN TRACKING QUEUES
File: core/translator/class-translation-service.php:604-654, core/translator/field-translator.php:410-464

The translation service queues string registration on shutdown. The field translator queues meta key tracking on shutdown. Both run on every page load when translations are hit.

On cold cache, the string registration queue can be large because every {{token}} in blocks triggers queue_string_registration(). On shutdown, Polylang's pll_set_current_language() is called to switch context, then strings are registered one-by-one. This involves Polylang API calls that can be expensive.

12. purge_all() — RUNS CALLBACKS, MAY LOG OUT USERS
File: core/cache/class-cache-manager.php:868-935

The purge_all() method wraps operations in with_auth_preservation(), which:

Reads auth cookie from request
Calls wp_set_auth_cookie() and wp_set_current_user() to restore state
During reset_plugin action, this runs twice (lines 482 and 497 of functions-admin-action-handlers.php)
13. frl_get_plugin_options_db() — DIRECT DB QUERY ON COLD CACHE
File: includes/helpers/functions-options.php:267-329

When the all_options key is not in cache, frl_cache_remember('options', 'all_options', ...) calls frl_get_plugin_options_db(). This runs:

SELECT option_name, option_value FROM wp_options WHERE option_name LIKE 'frl_%'
On sites with many plugin options, this can be a slow query. Then it iterates through all results, calling maybe_unserialize() on each, and normalizes by type.

14. frl_load_config_options_defaults() — CONFIG PARSING ON COLD CACHE
File: includes/helpers/functions-options.php:410-444

Loads and processes FRL_DEFAULT_FIELDS constant (a large nested array of field definitions). This runs when option_default_{key} is not in cache for any key. The processing involves iterating through all sections, all fields.

15. ENVIRONMENT CONFIG LOADING ON EVERY REQUEST
File: core/environment/class-environment-config.php (referenced) and core/environment/class-environment-monitor.php

When environment manager is active, Frl_Environment_Monitor::check_urls() runs on every admin page load. This involves comparing stored state against current URL state, which may involve DB queries for option values.

SUMMARY OF THE BIGGEST PERFORMANCE ISSUES
Issue 1: Rewrite rules rebuild on cold cache

Location: core/rewriter/class-rewriter.php:522-533
Impact: CRITICAL. Every time cache is flushed, the first page load triggers a FULL rewrite rules rebuild through flush_rewrite_rules(), clear_rewriter_caches(), and third-party notifications. This is by far the most expensive single operation.
Issue 2: Cascading cache dependency clears

Location: config/config-cache.php:84-123, config/config-cache-operations.php
Impact: HIGH. Clearing options cascades through 6 other groups. Clearing translations clears metafields. Posts saves trigger multiple cache operations.
Issue 3: Bulk preloading on cold cache

Location: core/cache/class-cache-manager.php:81-97, config/config-cache.php:125-140
Impact: HIGH. On cold cache, 6+ separate groups are preloaded, each triggering get_multi(null) which does a full DB scan for the group's transients.
Issue 4: save_post excessive cache clearing

Location: core/cache/cache-cleanup.php:42-109
Impact: MEDIUM-HIGH. Each post save clears cached entries for all 4 image sizes plus their mobile variants.
Issue 5: Missing option DB writes

Location: includes/helpers/functions-options.php:768-797
Impact: MEDIUM. On cold cache, every missing option triggers a DB INSERT.
Issue 6: Translation string registration queue

Location: core/translator/class-translation-service.php:604-654
Impact: MEDIUM. On cold cache (after translation version bump), many strings queue for registration on shutdown.
Issue 7: Object cache provider detection

Location: core/cache/class-cache-manager.php:195-368
Impact: LOW-MEDIUM. File I/O + plugin detection on first cache access.
Issue 8: User visit tracking writes

Location: includes/shared/logged-user.php:477-563
Impact: LOW-MEDIUM. User meta fetch + write on every page load when dedup transient is cold.
I should now write this up as a comprehensive report.

Now I have a thorough understanding of the codebase. Let me compile the findings into a comprehensive report.

PERFORMANCE ANALYSIS REPORT: Cold Cache Bottlenecks in Fralenuvole Plugin
Overview
After a complete object cache flush, the plugin's performance degrades significantly due to multiple interconnected issues. Below are the findings ranked by severity.

CRITICAL
1. Rewrite Rules Rebuild Triggered on Cold Cache
core/rewriter/class-rewriter.php:522-533

On wp_loaded, the rewriter checks if get_option('rewrite_rules') returns false. When the transient rewrite_flush_cooldown is cold (which it is after any cache flush), the plugin performs a full rewrite flush:

if (get_option('rewrite_rules') === false && !frl_get_transient('rewrite_flush_cooldown')) {
    frl_set_transient('rewrite_flush_cooldown', true, 60);
    frl_flush_rewrite_rules();
}
frl_flush_rewrite_rules() triggers clear_rewriter_caches() which:

Calls frl_cache_clear('options') → cascades through 7 cache groups via FRL_CACHE_DEPENDENCIES (options → theme, html, environment, admin, adminui, rewriter → permalinks)
Flushes WordPress rewrite rules (flush_rewrite_rules(true))
Notifies third-party cache plugins (LiteSpeed, Breeze, WP Rocket)
Impact: The very first page request after any cache flush forces a full rewrite rules rebuild — one of WordPress's most expensive operations — and cascades into clearing most plugin caches. This creates a compounding effect: the flush itself invalidates other caches, triggering additional cold-cache costs.

HIGH
2. Cascading Cache Dependency Graph
config/config-cache.php:84-123, config/config-cache-operations.php:100-113

The dependency graph creates chain reactions on seemingly targeted clears:

Trigger	Cascades
options clear	theme → html → environment → admin → adminui → rewriter → permalinks
rewriter clear	permalinks
translations clear	metafields
environment clear	adminui → admin
staticdata clear	adminui
When save_post fires or an option is updated, clear_options operation runs, cascading through 6 dependent groups. This means a single post save that triggers options invalidation actually clears caches for theme, html, environment, admin, adminui, rewriter, and permalinks.

config/config-cache-operations.php:100-107 shows even the supposedly targeted clear_options helper cascades:

'args' => [ 'options' ],
'note' => 'Clear options group with FRL_CACHE_DEPENDENCIES cascade: options → theme, html, environment, admin, adminui, rewriter → permalinks',
3. Bulk Cache Group Preloading on Every Request
core/cache/class-cache-manager.php:64-97, config/config-cache.php:125-140

Frl_Cache_Manager::init() calls auto_preload(), which preloads 6 groups on frontend and 5 groups on backend via get_multi(group, null):

const FRL_CACHE_PRELOAD_FRONTEND_GROUPS = [
    'options', 'rewriter', 'environment', 'theme', 'versions', 'html',
];
const FRL_CACHE_PRELOAD_BACKEND_GROUPS = [
    'options', 'environment', 'theme', 'versions', 'admin',
];
On cold cache, each group's get_multi(null) hits the wp_options table with a LIKE '_transient_frl_cache_GROUP_%' query — that's 5-6 separate table scans on every cold-cache request before any logic runs. get_multi() also injects all loaded rows into WordPress's internal option cache (wp_cache_add_multiple), adding memory pressure.

4. save_post Cache Clear Loops All Image Sizes
core/cache/cache-cleanup.php:42-109

When a post is saved, frl_clear_post_cache() clears cached data for every registered image size even though only one is used:

$common_sizes = ['thumbnail', 'medium', 'large', 'full'];
foreach ($common_sizes as $size) {
    if ($size !== $image_size) {
        $alt_key = frl_generate_cache_key('featured_img', (string)$post_id, $size, $ext);
        frl_cache_clear('postdata', $alt_key);
    }
}
Plus mobile hero variants: if image_preload_hero_mobile is enabled, it loops through full + the configured mobile size. For a typical site, this means 4-6 separate frl_cache_clear() calls for image-related keys alone, each calling clear_group_with_dependencies().

Additionally, the rewriter independently hooks into save_post at class-rewriter.php:497-504 to delete the exclusion patterns transient — another DB operation on every content edit.

MEDIUM
5. Translation Version Bump Injects Into All Cache Keys
core/cache/cache-cleanup.php:189-197

When Polylang translations are saved (pll_save_strings_translations), frl_clear_translation_cache() bumps the translation version, then clears the entire translations cache group:

function frl_clear_translation_cache() {
    frl_update_option('translation_version', time());
    frl_cache_clear('translations'); // cascades to metafields
}
This means every single string translation on the frontend will miss cache on the next request. The translation_version is embedded in cache keys for metadata, block translations, and option translations (field-translator.php:576, class-translation-service.php:235,980). After a save, ALL of these must be re-generated.

6. Missing Option Auto-Save on Cold Cache
includes/helpers/functions-options.php:768-797

When a plugin option key is absent from cache AND DB, frl_set_missing_option_default() calls frl_update_option() to INSERT a default value into the database:

frl_update_option($key, $default_value, false, $autoload);
On cold cache after a reset_plugin, this executes for every single option (potentially dozens). After frl_delete_plugin() deletes all frl_* options from DB, the reset_plugin handler at functions-admin-action-handlers.php:440-470 restores defaults, but any key not restored triggers this fallback on the first request. Worse, each frl_update_option() with clear_cache=true clears options/all_options — though in this flow clear_cache is set to false, so the impact is limited to the DB write + pre_option_* filter registration.

7. Environment Config Loaded on Admin Init
core/environment/class-environment-manager.php:32-70, core/environment/class-environment-monitor.php

Frl_Environment_Manager::init() runs on every admin request (via init hook from fralenuvole.php:127). On init it:

Calls Frl_Environment_Monitor::check_urls() — compares stored state with current URL
Calls Frl_Environment_Monitor::setup_plugin_options_tracking()
Reads the domain config through Frl_Environment_Config::get_domain_config()
On cold cache (environment group is empty), check_environment_state() (line 103) must fetch state from the DB option directly, and then compute the current state from scratch.

8. Shutdown: String Registration Queue + Meta Tracking Queue
core/translator/class-translation-service.php:604-654, core/translator/field-translator.php:410-464

Two separate shutdown hooks process accumulated queues:

String registration queue: Every {{token}} in translated blocks adds to the queue. On shutdown, Polylang's pll_set_current_language() is called to switch to source language, then register_translation() is called per string, involving Polylang's pll_register_string() API call.
Meta tracking queue: Every translated meta field adds to the queue. On shutdown, the meta key list is written back to cache via frl_cache_set('metafields', ...).
On cold cache (especially after a translation version bump), both queues are large because every block and meta field triggers a cache miss, populating both queues.

9. User Visit Tracking on Every Page
includes/shared/logged-user.php:477-563

frl_trace_logged_user_visits() runs on wp_footer and admin_footer for every logged-in user. When the dedup transient is cold:

Fetches user meta (frl_get_user_meta())
Iterates through stored visits checking for duplicates
Updates user meta with new visit
This is a write operation on every page load for logged-in users when the 5-minute dedup transient (visit_dedup_{uid}_{hash}) expires.

LOW-MEDIUM
10. Object Cache Provider Detection — File I/O
core/cache/class-cache-manager.php:195-368

get_provider_details() reads up to 2KB from wp-content/object-cache.php on first call when the result is not in transient cache. The result is cached for 1 week via set_transient(), but after a cache flush (which clears transients), the next cache access triggers this file read plus plugin detection checks.

11. frl_active_languages Filter on Every Call
core/translator/class-translation-service.php:211

get_active_languages() applies the frl_active_languages filter on every call:

return apply_filters('frl_active_languages', $this->active_languages_cache);
This filter is called in cache-cleanup.php:133 when clearing option caches, potentially triggering downstream hooks on every option save.

ROOT CAUSE SUMMARY
The core architectural problem is a compound cold-cache amplification loop:

Cache Flush
  → Rewrite rules check on next request (class-rewriter.php:522)
    → flush_rewrite_rules() + clear_rewriter_caches()
      → frl_cache_clear('options') + dependency cascade (7 groups)
        → All preloaded groups miss cache again
          → 5-6 DB queries for preload
            → 50+ options re-fetched from DB
              → Translation service re-generates all keys
                → Shutdown queues fill up (strings + meta tracking)
Every link in this chain multiplies the cold-cache penalty. The rewrite_rules missing check at class-rewriter.php:529-533 is the primary amplification trigger — it converts a routine cache flush into a full cache rebuild cascade.