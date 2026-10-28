# Core - Rewriter

Feature-based URL rewriting system. Solves the "URL Hijacking" problem — catch-all rules (CPT/taxonomy base removal) must not intercept URLs belonging to higher-priority features or WordPress pages.

**Core constraint:** ANY change to one feature must not alter the runtime behavior of any other feature.

## Architecture

```
Frl_Rewriter (Facade, final, singleton)
  └── Frl_Rewriter_Coordinator (singleton)
        ├── create_all_features()
        │     ├── FRL_REWRITER_FEATURES → instantiate + self_register()
        │     └── FRL_REWRITER_MULTILINGUAL_CPT → Archive + Single per CPT
        ├── do_action('frl_rewriter_register_features', $this)  ← module extension point
        └── usort() by priority
              │
              ▼
        Frl_Rewriter_Feature_Base (abstract)
          ├── Frl_CPT_Archive_Base_Translation_Feature  (priority 15)
          ├── Frl_CPT_Single_Base_Translation_Feature   (priority 25)
          ├── Frl_Taxonomy_Base_Removal_Feature         (priority 35)
          └── Frl_CPT_Base_Removal_Feature              (priority 40)
```

**Key files:**

| File | Role |
|------|------|
| `core/rewriter/class-rewriter.php` | Facade — URL transformation, cache invalidation, `warm_cache_for_posts()` |
| `core/rewriter/class-rewriter-coordinator.php` | Feature lifecycle, priority sort, validation, config hash |
| `core/rewriter/class-rewriter-path-utils.php` | URL parsing, exclusion patterns, transient caching |
| `core/rewriter/interface-feature.php` | Contract: `Frl_Rewriter_Feature_Interface` |
| `core/rewriter/interface-rewriter.php` | Contract: `Frl_Rewriter_Interface` |
| `core/rewriter/trait-cache-key-generator.php` | CRC32-based cache key generation |
| `core/rewriter/features/abstract-base-feature.php` | Base class with final `register()`, `get_priority()` |
| `core/rewriter/features/class-cpt-archive-base-translation-feature.php` | Priority 15 |
| `core/rewriter/features/class-cpt-single-base-translation-feature.php` | Priority 25 |
| `core/rewriter/features/class-taxonomy-base-removal-feature.php` | Priority 35 |
| `core/rewriter/features/class-cpt-base-removal-feature.php` | Priority 40 |
| `core/rewriter/class-rewriter-config-validator.php` | Admin-only pattern conflict detection |
| `core/rewriter/cli/class-rewriter-cli.php` | WP-CLI commands |
| `config/config-rewriter.php` | Feature registry, priorities, CPT list, constants |

## Configuration

`config/config-rewriter.php`:

```php
FRL_REWRITER_PRIORITIES = [
    'Frl_CPT_Archive_Base_Translation_Feature' => 15,
    'Frl_CPT_Single_Base_Translation_Feature'  => 25,
    'Frl_Taxonomy_Base_Removal_Feature'        => 35,
    'Frl_CPT_Base_Removal_Feature'             => 40,
];
FRL_REWRITER_FEATURES = [
    Frl_Taxonomy_Base_Removal_Feature::class,
    Frl_CPT_Base_Removal_Feature::class,
];
FRL_REWRITER_MULTILINGUAL_CPT = ['service'];
FRL_REWRITER_USE_FAST_CONFLICT  = true;   // prefix-based admin conflict detection
FRL_REWRITER_PAGE_TOPLEVEL_CAP  = 500;    // max top-level pages in exclusion patterns
FRL_REWRITER_LOG_DUPLICATES     = false;  // suppress duplicate pattern log noise
```

CPT translation features (Archive + Single) are instantiated per CPT in `FRL_REWRITER_MULTILINGUAL_CPT` — they are NOT listed in `FRL_REWRITER_FEATURES`.

### Admin Options

| Option | Used by | Format |
|--------|---------|--------|
| `rewriter_translate_cpt_slugs_{cpt}` | CPT Archive & Single Translation | `lang\|slug` per line |
| `rewriter_remove_tax_base` | Taxonomy Base Removal | one slug per line |
| `rewriter_remove_cpt_base` | CPT Base Removal | one slug per line |
| `rewriter_translate_post_base` | Exclusion patterns + taxonomy URL parsing | `lang\|base` per line |
| `disable_rewriter` | `frl_rewriter_is_loaded()` | boolean |

## Bootstrap & Hook Timeline

```
fralenuvole.php → frl_load_core_components()
  → frl_rewriter_init()  [if !disable_rewriter]
       → Frl_Rewriter::init()  [singleton]
            → Frl_Rewriter_Coordinator::init()  [singleton]
                 → create_all_features()  [instantiate + self_register]
                 → do_action('frl_rewriter_register_features')  [module extension]
                 → usort() by priority
            → register_hooks()
                 → add_filter('post_type_link', filter_post_link, 10, 2)
                 → add_filter('term_link', filter_term_link, 10, 3)
                 → register_cache_invalidation_hooks()  [deferred to wp_loaded]
```

| Hook | Priority | Action |
|------|----------|--------|
| `plugins_loaded` | 5 | Coordinator created, features instantiated, modules loaded |
| `plugins_loaded` | 7 | `frl_rewriter_register_features` fires + features sorted |
| `init` | 15 | `feature->register()` for every feature (adds rewrite rules) |
| `init` | 100+priority | Individual feature `add_rules()` and `filter_request()` |
| `wp_loaded` | 10 | Cache invalidation hooks wired |
| `post_type_link` | 10 | `filter_post_link()` → `transform_url()` |
| `term_link` | 10 | `filter_term_link()` → `transform_url()` |

## URL Transformation Pipeline

`Frl_Rewriter::transform_url(string $url, $obj): string` (private):

1. **Object validity guard** — return `$url` if not an object
2. **REST API guard** — return `$url` (never transform for REST, placed before cache check)
3. **Preview guard** — return `$url` for preview requests
4. **Fast-path cache lookup** — `frl_cache_get('permalinks', $cache_key)` → return on hit
5. **Re-entrancy guard** — `static $processing_urls[]` prevents infinite loops during ACF relationship queries
6. **Get enabled features** — `$coordinator->get_enabled_features()` (only on cache miss)
7. **Dispatcher cache** — `static $feature_match_cache[$signature]` maps object type → applicable features (LRU, 1024 entry cap, evicts oldest 10% when full)
8. **`frl_cache_remember('permalinks', ...)`** — apply features in priority order, store result

Cache key generated by `Frl_Rewriter_Cache_Key_Trait::generate_cache_key()` using CRC32.

**Incoming requests:** first-match-wins (rules registered in priority order).
**Outgoing URLs:** composition — ALL applicable features transform the URL in sequence.

## Feature Base Class

`Frl_Rewriter_Feature_Base` implements `Frl_Rewriter_Feature_Interface`:

- `get_priority()` — **final**, reads from `FRL_REWRITER_PRIORITIES`, fallback 99
- `register()` — **final**, hooks `add_rules()` at `init:100+priority`, `filter_request()` at same priority, catch-all at `100+priority+50`
- `register_additional_hooks()` — override point for feature-specific hooks (config loaders, canonical redirects)
- `applies_to($obj)` — default `false` (override for URL transformation)
- `transform($url, $obj)` — default returns `$url` unchanged
- `get_catch_all_query_var()` — default `''`
- `uses_catch_all()` — true if `get_catch_all_query_var()` is non-empty

Abstract methods: `is_enabled()`, `generate_rules()`, `applies_to_request()`, `resolve_request()`, `get_name()`.

## Cache Invalidation

### `invalidate_rewriter_caches()` (cache-only)

Hooked to `flush_rewrite_rules`. Clears the `rewriter` cache group (cascades to `permalinks` via `FRL_CACHE_DEPENDENCIES`) + deletes `frl_rewriter_excl_patterns` transient. Has `frl_is_already_running` guard.

### `clear_rewriter_caches()` (cache + hard flush)

Called by the single `updated_option` hook (any `frl_rewriter_*` option change) and Polylang language hooks. Calls `invalidate_rewriter_caches()` then `flush_rewrite_rules(true)`. Guard prevents duplicate work when `flush_rewrite_rules` hook re-fires `invalidate_rewriter_caches()`.

### `force_rules_refresh()`

Invalidates coordinator's in-memory config hash, calls `frl_flush_rewrite_rules()`, then `validate_all_features()`.

### Registered Hooks (deferred to `wp_loaded`)

| Hook | Calls |
|------|-------|
| `flush_rewrite_rules` | `invalidate_rewriter_caches()` |
| `updated_option` (name starts with `frl_rewriter_`) | `maybe_clear_rewriter_caches_on_option_change()` → `clear_rewriter_caches()` |
| `pll_add_language` | `clear_rewriter_caches()` |
| `pll_update_language` | `clear_rewriter_caches()` |
| `pll_update_default_lang` | `clear_rewriter_caches()` |
| `post_updated` (page: slug/parent/status change) | Bump exclusion version + schedule debounced rewrite flush |
| `wp_trash_post`, `untrash_post`, `before_delete_post` (page) | Bump exclusion version + schedule debounced rewrite flush |
| `created_term`, `edited_term` | Bump exclusion version (no flush) |
| `deleted_term` | Non-language: bump version; `language` taxonomy: full `clear_rewriter_caches()` |

## Exclusion Pattern System

`Frl_Rewriter_Path_Utils::compute_exclusion_patterns()` aggregates:

1. Post-base prefixes from `rewriter_translate_post_base` (e.g., `blog`, `en/blog`)
2. CPT archive translated prefixes via `frl_rewriter_url_prefixes` filter
3. All public CPT rewrite slugs (with language-prefixed variants)
4. All public taxonomy rewrite bases
5. Top-level published page slugs — fetched across **all** languages via `get_pages( array( 'lang' => '' ) )` (Polylang's own escape hatch), so the list is identical regardless of which context (frontend/cron/admin) computes it. Capped at `FRL_REWRITER_PAGE_TOPLEVEL_CAP` (500) scaled by active-language count, hard ceiling 1000.

**Two-tier caching:**
- **Object cache active:** keyed by an autoloaded `_frl_rewriter_excl_version` counter, bumped only by events that can change page/language slugs. Ordinary post edits no longer force a recompute (the old `posts:last_changed` key did).
- **No object cache:** stored in the `frl_rewriter_excl_patterns` transient (1 hour TTL), deleted on the same events.

**Routing uses the flushed `rewrite_rules` snapshot**, so bumping the version alone does not change routing. Page lifecycle events (`post_updated` with a routing-relevant slug/parent/status change, plus trash/untrash/delete of pages) bump the version **and** schedule the debounced `frl_schedule_rewrite_flush()` (fires within the next cron run). Term events bump the version only; language deletion still runs the full `clear_rewriter_caches()`.

## Module Extension

External modules register features at `plugins_loaded/7`:

```php
add_action('frl_rewriter_register_features', function ($coordinator) {
    $coordinator->add_feature(new My_Custom_Rewrite_Feature());
});
```

Default priority is 99 for unlisted feature classes. Features must extend `Frl_Rewriter_Feature_Base`.

## WP-CLI

```bash
wp frl rewriter status   # Dump active features and rules
wp frl rewriter flush    # force_rules_refresh()
```

## Debugging

```php
Frl_Rewriter::clear_rewriter_caches();    // Cache clear + hard flush
Frl_Rewriter::force_rules_refresh();      // Full refresh + config hash reset + validation
Frl_Rewriter::init()->warm_cache_for_posts($posts);  // Pre-warm permalink cache
frl_rewriter_is_loaded();                 // Check if subsystem is active
Frl_Rewriter::get_multilingual_cpts();    // Get CPTs with translation config