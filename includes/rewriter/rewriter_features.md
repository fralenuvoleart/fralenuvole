# FRL Rewriter System — Architecture Reference

## Design Goal

ANY change to the code of one feature must not alter the runtime behaviour of any other feature.

### How independence is achieved

- **Self-registration** — features call `self_register()` which pushes themselves onto the coordinator's list; no central dependency list exists
- **Isolated configuration** — each feature reads only its own options (via `frl_get_option()`)
- **Separate rule generation** — each feature calls `add_rewrite_rule()` independently in `register()`
- **Unique catch-all query vars** — `frl_tax_base_path` vs `frl_cpt_base_path` prevent collisions
- **No direct class-to-class coupling** — features never call methods or access properties of other features
- **Filter-based coordination** — the one intentional inter-feature dependency is loose: `Frl_CPT_Archive_Base_Translation_Feature` publishes its translated URL prefixes via the `frl_rewriter_url_prefixes` filter; `Frl_Taxonomy_Base_Removal_Feature` (via `Frl_Rewriter_Path_Utils::compute_exclusion_patterns()`) consumes it to build correct catch-all exclusion lists. Absence of the CPT archive feature degrades gracefully (incomplete exclusions, no errors).

---

## Active Features

Features are created in `Frl_Rewriter_Coordinator::create_all_features()`, sorted by priority, and registered on `init:15`.

| Priority | Class | Config key | Notes |
|----------|-------|------------|-------|
| **15** | `Frl_CPT_Archive_Base_Translation_Feature` | `translate_cpt_slugs_{cpt}` | One instance per CPT in `FRL_REWRITER_MULTILINGUAL_CPT` |
| **25** | `Frl_CPT_Single_Base_Translation_Feature` | `translate_cpt_slugs_{cpt}` | One instance per CPT in `FRL_REWRITER_MULTILINGUAL_CPT` |
| **35** | `Frl_Taxonomy_Base_Removal_Feature` | `remove_tax_base` | Catch-all using `frl_tax_base_path` |
| **40** | `Frl_CPT_Base_Removal_Feature` | `remove_cpt_base` | Catch-all using `frl_cpt_base_path` |

> **Note — `translate_post_base`**: this option is NOT handled by a dedicated feature. It is consumed by `Frl_Rewriter_Path_Utils::get_post_base_mappings()` to build exclusion patterns for catch-all rules and to strip the post-base prefix during taxonomy URL resolution. Cache invalidation for `translate_post_base` is wired in `register_cache_invalidation_hooks()`.

---

## Feature Registration Config (`config/config-rewriter.php`)

```php
// Execution order (lower = higher priority)
FRL_REWRITER_PRIORITIES = [
    'Frl_CPT_Archive_Base_Translation_Feature' => 15,
    'Frl_CPT_Single_Base_Translation_Feature'  => 25,
    'Frl_Taxonomy_Base_Removal_Feature'        => 35,
    'Frl_CPT_Base_Removal_Feature'             => 40,
];

// CPTs that get per-language slug translation
FRL_REWRITER_MULTILINGUAL_CPT = ['service'];

// Features registered via FRL_REWRITER_FEATURES (CPT translation features are separate)
FRL_REWRITER_FEATURES = [
    Frl_Taxonomy_Base_Removal_Feature::class,
    Frl_CPT_Base_Removal_Feature::class,
];

FRL_REWRITER_USE_FAST_CONFLICT  = true;   // prefix-based admin conflict detection
FRL_REWRITER_PAGE_TOPLEVEL_CAP  = 500;    // max top-level pages in exclusion patterns
FRL_REWRITER_LOG_DUPLICATES     = false;  // suppress duplicate pattern log noise
```

---

## Configuration Options

| Option | Used by | Format | Example |
|--------|---------|--------|---------|
| `translate_cpt_slugs_{cpt}` | CPT Archive & Single Translation | `lang\|slug` per line | `en\|services\nit\|servizi` |
| `remove_tax_base` | Taxonomy Base Removal | one slug per line | `category\ntag` |
| `remove_cpt_base` | CPT Base Removal | one slug per line | `service\nnews` |
| `translate_post_base` | Exclusion patterns + taxonomy URL parsing | `lang\|base` per line | `en\|blog\nit\|blog` |
| `disable_rewriter` | `frl_rewriter_is_loaded()` | boolean | disables entire subsystem |

---

## URL Pattern Examples

### CPT with multilingual slugs (`FRL_REWRITER_MULTILINGUAL_CPT`)

```
translate_cpt_slugs_service: "en|services\nit|servizi"

CPT archive:  /en/services/    →  /it/servizi/
CPT single:   /en/services/web-design/  →  /it/servizi/web-design/
```

### CPT base removal

```
remove_cpt_base: "service\nnews"

Before: /service/my-service/     After: /my-service/
Before: /news/breaking-story/    After: /breaking-story/
```

### Taxonomy base removal

```
remove_tax_base: "category"

Before: /category/tech/    After: /tech/
```

### Combined (translation + taxonomy removal)

```
translate_cpt_slugs_service: "en|services\nit|servizi"
remove_tax_base: "category"
translate_post_base: "en|blog\nit|blog"

CPT archive:  /en/services/       taxonomy: /tech/
Exclusion patterns prevent /tech/ from being hijacked by catch-all for /services/.
```

### Feature priority / conflict resolution

```
translate_cpt_slugs_product: "en|products\nit|prodotti"
remove_cpt_base: "product"

Result: Translation (priority 15) wins.
CPT Base Removal catch-all is excluded from intercepting /en/products/* URLs
via the frl_rewriter_url_prefixes filter mechanism.
```

---

## System Architecture

### Bootstrap flow

```
fralenuvole.php
  └─ frl_rewriter_init()                    (functions-class-helpers.php)
       ├─ frl_rewriter_is_loaded()          checks disable_rewriter option + class existence
       └─ Frl_Rewriter::init()              singleton constructor
            └─ Frl_Rewriter_Coordinator::init()
                 ├─ create_all_features()   instantiate + self_register() each feature
                 │    ├─ FRL_REWRITER_FEATURES (Taxonomy + CPT Base Removal)
                 │    └─ FRL_REWRITER_MULTILINGUAL_CPT (Archive + Single per CPT)
                 ├─ do_action('frl_rewriter_register_features')  extension point
                 └─ usort() by priority
            └─ register_hooks()
                 ├─ add_filter('post_type_link', filter_post_link)
                 ├─ add_filter('term_link', filter_term_link)
                 └─ register_cache_invalidation_hooks()   (deferred to wp_loaded)
```

### WordPress hook timeline

| Hook | Priority | Action |
|------|----------|--------|
| `plugins_loaded` | — | Coordinator created, features instantiated |
| `init` | 15 | `feature->register()` for every feature (adds rewrite rules) |
| `init` | 20+ | Config option loaders (CPT / taxonomy registration) |
| `wp_loaded` | 10 | Cache invalidation hooks wired to `update_option_*` actions |
| `post_type_link` | 10 | `filter_post_link()` → `transform_url()` |
| `term_link` | 10 | `filter_term_link()` → `transform_url()` |

---

## URL Transformation Pipeline (`transform_url`)

```
transform_url($url, $object)
  │
  ├─ 1. Object validity guard       (return $url if not object)
  ├─ 2. REST API guard              (return $url — never cache/transform for REST)
  ├─ 3. Fast-path cache lookup      frl_cache_get('permalinks', $cache_key)  → return on hit
  ├─ 4. Re-entrancy guard           static $processing_urls[]  (prevents ACF recursion)
  ├─ 5. get_enabled_features()      array_filter — only reached on cache miss
  ├─ 6. Dispatcher cache            static $feature_match_cache[$signature]
  │       maps object type → applicable features (memory guard at 1 024 entries)
  └─ 7. frl_cache_remember()        apply features in priority order, store result
```

**Incoming requests**: first-match-wins (rewrite rules registered in priority order).
**Outgoing URLs**: composition — ALL applicable features transform the URL in sequence.

---

## Caching Architecture

### Cache groups (WordPress object cache, per-request)

| Group | Contents | Cleared by |
|-------|----------|------------|
| `permalinks` | Transformed URL results (keyed by URL + object id) | `clear_rewriter_caches()` |
| `rewriter` | Config mappings, validation result, multilingual CPT list, exclusion patterns (with ext object cache) | `clear_rewriter_caches()` |
| `options` | `frl_get_option()` results | `clear_rewriter_caches()` |

### DB transient (cross-request, no external object cache)

| Key (`frl_` prefix added by helper) | Contents | TTL | Cleared by |
|--------------------------------------|----------|-----|------------|
| `frl_rewriter_excl_patterns` | Computed exclusion patterns (slugs, taxonomy bases, CPT bases, page slugs) | 1 hour | `clear_rewriter_caches()` via `frl_delete_transient(EXCLUSION_PATTERNS_TRANSIENT)` |

When an external object cache (Memcached, Redis) is active, the transient path is bypassed and results are keyed on `posts:last_changed` instead.

### Cache invalidation hooks (registered on `wp_loaded`)

- `update_option_permalink_structure`
- `update_option_category_base`
- `update_option_tag_base`
- `update_option_remove_cpt_base`
- `update_option_remove_tax_base`
- `update_option_translate_post_base`
- `update_option_translate_cpt_slugs_{cpt}` (one per CPT in `FRL_REWRITER_MULTILINGUAL_CPT`)

All of the above call `Frl_Rewriter::clear_rewriter_caches()`.

### Full cache flush (`clear_rewriter_caches`)

```php
Frl_Rewriter::clear_rewriter_caches();
// 1. frl_cache_clear('permalinks')
// 2. frl_cache_clear('rewriter')
// 3. frl_cache_clear('options')
// 4. frl_delete_transient(EXCLUSION_PATTERNS_TRANSIENT)
// 5. flush_rewrite_rules(false)
```

`force_rules_refresh()` additionally calls `coordinator->invalidate_config_hash()` before delegating to `clear_rewriter_caches()`.

---

## Exclusion Pattern System

Shared by `Frl_Taxonomy_Base_Removal_Feature` and `Frl_CPT_Base_Removal_Feature` to prevent their catch-all rules from hijacking URLs that belong to higher-priority features.

`Frl_Rewriter_Path_Utils::generate_standard_exclusion_patterns()` aggregates:

1. Post-base prefixes from `translate_post_base` (e.g. `blog`, `en/blog`)
2. CPT archive translated prefixes via `frl_rewriter_url_prefixes` filter (e.g. `services`, `en/services`)
3. All public CPT rewrite slugs
4. All public taxonomy rewrite bases
5. Top-level published page slugs (capped at `FRL_REWRITER_PAGE_TOPLEVEL_CAP = 500`)

---

## Admin: Config Validator (`class-rewriter-config-validator.php`)

Loaded only when `is_admin()`. Provides:
- Pattern conflict detection between feature rule sets
- Fast prefix-based check when `FRL_REWRITER_USE_FAST_CONFLICT = true`
- Validation result cached in `rewriter` group keyed by config hash

Config hash (`md5`) is computed lazily on first access (after `init:20` so option-dependent features are fully loaded) and incorporates:
- Feature names, enabled state, and priority
- Values of `remove_cpt_base`, `remove_tax_base`, `translate_post_base`, and all `translate_cpt_slugs_*` options

---

## WP-CLI Diagnostics

```bash
wp frl rewriter status        # dump active features and their rules
wp frl rewriter flush         # force_rules_refresh()
```

Implemented in `cli/class-rewriter-cli.php`, registered via the plugin's CLI loader.

---

## Debugging

```php
// Dump URL parsing context
$debug = Frl_Rewriter_Path_Utils::get_debug_info();

// Clear all rewriter caches (object cache groups + transient + flush WP rules)
Frl_Rewriter::clear_rewriter_caches();

// Full refresh including config-hash reset
Frl_Rewriter::force_rules_refresh();

// Check if rewriter subsystem is active
frl_rewriter_is_loaded(); // returns bool

// Pre-warm permalink cache for a set of ACF relationship posts
Frl_Rewriter::init()->warm_cache_for_posts($posts);
```

### Common issues

| Symptom | Cause | Fix |
|---------|-------|-----|
| 404 on translated/rewritten URLs | WP rewrite rules stale | Settings → Permalinks (save), or `force_rules_refresh()` |
| Wrong language in URL | Option not saved, or cache stale | Save option; cache clears automatically via `update_option_*` hook |
| Catch-all hijacking a CPT archive | New CPT added without flushing | Save Permalinks; `clear_rewriter_caches()` regenerates exclusions |
| REST API returns transformed URLs | REST guard not first in pipeline | Already fixed: REST guard runs before cache check in `transform_url()` |

---

## System Files

### Core

| File | Responsibility |
|------|---------------|
| `class-rewriter.php` | Facade, URL transformation, cache invalidation hooks, `warm_cache_for_posts()` |
| `class-rewriter-coordinator.php` | Feature lifecycle, priority sort, validation, `force_refresh()` |
| `class-rewriter-path-utils.php` | URL parsing, exclusion patterns, transient caching, shared utilities |
| `trait-cache-key-generator.php` | CRC32-based cache key generation for `transform_url()` |
| `interface-rewriter.php` | Contract for `Frl_Rewriter` |
| `interface-feature.php` | Contract for every feature |
| `class-rewriter-config-validator.php` | Admin-only pattern conflict detection |
| `cli/class-rewriter-cli.php` | WP-CLI diagnostic and flush commands |

### Features (`features/`)

| File | Priority |
|------|----------|
| `abstract-base-feature.php` | — base class |
| `class-cpt-archive-base-translation-feature.php` | 15 |
| `class-cpt-single-base-translation-feature.php` | 25 |
| `class-taxonomy-base-removal-feature.php` | 35 |
| `class-cpt-base-removal-feature.php` | 40 |

### Helpers (`includes/helpers/functions-class-helpers.php`)

```php
frl_rewriter_is_loaded(): bool   // static-cached check (disable_rewriter option + class_exists)
frl_rewriter_init(): void        // guarded init, called from fralenuvole.php
```
