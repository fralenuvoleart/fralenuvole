# Schema Generator Module — Implementation Plan (v3 — Final)

## Overview

Add a config-driven, extensible schema generator subsystem to `public/schema/` that creates complete JSON-LD `@type` blocks from post data (ACF, ACPT, taxonomies, etc.). The existing static property injection system is reorganized into `properties/`, the new generation system lives in `generator/`. Data files are split into `data/properties/` and `data/generators/`. Both subsystems are gated by separate master toggles in `config-options.php`.

## Master Toggles

Two new options added to `config/config-options.php` under the existing "Schema Settings" section (after `schema_founder_name`):

| Option | Default | Purpose |
|--------|---------|---------|
| `schema_properties` | `1` | Master kill switch for the properties injection subsystem (Organization, address, etc.). When `0`, all static property loading is skipped — cascades to SASWP enrichment too. |
| `schema_generator` | `1` | Master kill switch for the dynamic schema generator (HowTo, FAQ, etc.). When `0`, no generated schemas are output. |

Both are `checked` type, `restricted => true`, `sanitize_callback => 'absint'`.

### Guard Locations

| Toggle | Guards |
|--------|--------|
| `schema_properties` | `frl_get_schema_properties()` in `properties/resolver.php`, `frl_get_schema_term_map()` and `frl_get_schema_person_map()` in `properties/builders.php` |
| `schema_generator` | `frl_output_generated_schemas()` in `generator/schema-generator.php` |

Note: `thirdparty_schema_properties` in the thirdparty module already gates SASWP injection independently. With `schema_properties=0`, SASWP enrichment returns empty arrays naturally.

## Final Directory Structure

```
public/schema/
├── schema.php                          → entry point (UPDATED require paths)
├── properties/                         → STATIC PROPERTY INJECTION (REORGANIZED)
│   ├── resolver.php                    → was schema-resolver.php + schema_properties guard
│   └── builders.php                    → was schema-builders.php + schema_properties guard
├── generator/                          → DYNAMIC SCHEMA GENERATION (NEW)
│   └── schema-generator.php            → registry + wp_head output + HowTo generator
└── data/
    ├── properties/                     → was data/
    │   ├── default-schema.php
    │   ├── default-schema-terms.php
    │   └── default-schema-person.php
    └── generators/                     → NEW
        └── default-schema-generators.php
```

## Init Flow

```
schema.php loads properties/resolver.php + properties/builders.php + generator/schema-generator.php
  → generator hooks wp_head at p10
  → on wp_head:
    → master toggle: frl_get_option('schema_generator') — bail if 0
    → is_singular(), !is_admin(), !frl_is_rest_api_request(), !is_preview()
    → frl_is_already_running() re-entrancy guard
    → frl_get_generated_schemas($post_id)
      → cached via frl_cache_remember('html', key, ...)
      → builds generator registry from data file + frl_schema_generators filter
      → iterates generators, try/catch each, collecting non-null schema arrays
      → outputs <script id="frl-schema" type="application/ld+json">...</script>
```

## Best Practices Applied

| Practice | Where |
|----------|-------|
| Master toggles | `schema_properties` + `schema_generator` options (default 1) |
| Re-entrancy guard | `frl_output_generated_schemas()` via `frl_is_already_running()` |
| Persistent caching | `frl_get_generated_schemas()` via `frl_cache_remember('html', ...)` |
| Request-level static cache | `frl_get_schema_generators()` data file parse |
| Admin/REST/preview guard | `is_admin()`, `frl_is_rest_api_request()`, `is_preview()` |
| Debug logging | `WP_DEBUG`-gated `frl_log()` calls |
| Exception safety | `try/catch (\Throwable)` around each generator callable |
| Plugin helpers | `frl_get_post_meta()`, `frl_cache_remember()`, `frl_is_already_running()`, `frl_get_option()` |
| Filter extensibility | `frl_schema_generators` filter (follows `frl_schema_term_map` pattern) |
| Brand-override support | `frl_get_schema_data_file()` updated for new path |
| `frl_` prefix | All new functions prefixed |

## Files Changed

| # | Action | File | Description |
|---|--------|------|-------------|
| 1 | MKDIR | `public/schema/properties/` | New directory |
| 2 | MKDIR | `public/schema/generator/` | New directory |
| 3 | MKDIR | `public/schema/data/properties/` | New directory |
| 4 | MKDIR | `public/schema/data/generators/` | New directory |
| 5 | MOVE | `schema-resolver.php` → `properties/resolver.php` | + schema_properties guard |
| 6 | MOVE | `schema-builders.php` → `properties/builders.php` | + schema_properties guard |
| 7 | MOVE | `data/default-schema.php` → `data/properties/` | Content unchanged |
| 8 | MOVE | `data/default-schema-terms.php` → `data/properties/` | Content unchanged |
| 9 | MOVE | `data/default-schema-person.php` → `data/properties/` | Content unchanged |
| 10 | EDIT | `properties/resolver.php` | Update path + add guard |
| 11 | EDIT | `config/config-options.php` | Add `schema_properties` + `schema_generator` options |
| 12 | EDIT | `public/schema/schema.php` | Update require paths + add generator require |
| 13 | CREATE | `generator/schema-generator.php` | Full generator system |
| 14 | CREATE | `data/generators/default-schema-generators.php` | Config data |

## Step-by-Step Implementation

### Step 1: Create directories

```bash
mkdir -p public/schema/properties
mkdir -p public/schema/generator
mkdir -p public/schema/data/properties
mkdir -p public/schema/data/generators
```

### Step 2: Move files

```bash
mv public/schema/schema-resolver.php public/schema/properties/resolver.php
mv public/schema/schema-builders.php public/schema/properties/builders.php
mv public/schema/data/default-schema.php public/schema/data/properties/default-schema.php
mv public/schema/data/default-schema-terms.php public/schema/data/properties/default-schema-terms.php
mv public/schema/data/default-schema-person.php public/schema/data/properties/default-schema-person.php
```

### Step 3: Edit `properties/resolver.php` — update path + add guard

In `frl_get_schema_data_file()`, change:
```php
$file = FRL_DIR_PATH . 'public/schema/data/' . $default_filename;
```
To:
```php
$file = FRL_DIR_PATH . 'public/schema/data/properties/' . $default_filename;
```

Also update the brand-override path (same function, lines 33-34).

In `frl_get_schema_properties()`, add at the top:
```php
if (!frl_get_option('schema_properties')) {
    return [];
}
```

### Step 4: Edit `properties/builders.php` — add guards

At the top of `frl_get_schema_term_map()`:
```php
if (!frl_get_option('schema_properties')) {
    return [];
}
```

At the top of `frl_get_schema_person_map()`:
```php
if (!frl_get_option('schema_properties')) {
    return [];
}
```

### Step 5: Edit `config/config-options.php` — add toggles

After `schema_founder_name` (approx line 323), before the `)` that closes the fields array, insert:

```php
'schema_properties' => array(
    'label'       => 'Enable Schema Properties',
    'description' => 'Master toggle for the properties injection subsystem (Organization, address, etc.). When off, all static property injection is skipped.',
    'type'        => 'checkbox',
    'default'     => 1,
    'sanitize_callback' => 'absint',
    'restricted'  => true,
),
'schema_generator' => array(
    'label'       => 'Enable Schema Generator',
    'description' => 'Master toggle for the dynamic schema generator (HowTo, FAQ, etc. from ACF/ACPT data). When off, no generated schemas are output.',
    'type'        => 'checkbox',
    'default'     => 1,
    'sanitize_callback' => 'absint',
    'restricted'  => true,
),
```

### Step 6: Edit `schema.php` — update require paths

Replace:
```php
require_once FRL_DIR_PATH . 'public/schema/schema-resolver.php';
require_once FRL_DIR_PATH . 'public/schema/schema-builders.php';
```
With:
```php
require_once FRL_DIR_PATH . 'public/schema/properties/resolver.php';
require_once FRL_DIR_PATH . 'public/schema/properties/builders.php';
require_once FRL_DIR_PATH . 'public/schema/generator/schema-generator.php';
```

### Step 7: Create `generator/schema-generator.php`

Full file content:

```php
<?php
/**
 * Schema Generator
 *
 * Config-driven system that generates complete JSON-LD @type blocks
 * from post data (ACF, ACPT, taxonomies, etc.).
 * Generators are registered via data file + frl_schema_generators filter.
 *
 * Master toggle: frl_get_option('schema_generator') — defaults to 1 (enabled).
 * When 0, all generator output is suppressed.
 *
 * @package Fralenuvole
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_head', 'frl_output_generated_schemas', 10, 0);

/**
 * Output all generated schema blocks as a single <script> tag.
 *
 * Gated behind:
 * - Master toggle: frl_get_option('schema_generator')
 * - is_singular(), !is_admin(), !frl_is_rest_api_request(), !is_preview()
 * - frl_is_already_running() re-entrancy guard
 */
function frl_output_generated_schemas(): void
{
    if (!frl_get_option('schema_generator')) {
        return;
    }

    if (!is_singular()) {
        return;
    }

    if (is_admin() || frl_is_rest_api_request() || is_preview()) {
        return;
    }

    if (function_exists('frl_is_already_running') && frl_is_already_running(__FUNCTION__)) {
        return;
    }

    $post_id = get_the_ID();
    if (!$post_id) {
        return;
    }

    $schemas = frl_get_generated_schemas($post_id);
    if (empty($schemas)) {
        return;
    }

    $output = count($schemas) === 1 ? $schemas[0] : ['@graph' => $schemas];
    $output['@context'] = 'https://schema.org';

    echo "\n" . '<script id="frl-schema" type="application/ld+json">' . "\n"
        . wp_json_encode($output, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        . "\n" . '</script>' . "\n";
}

/**
 * Get all generated schema blocks for a post, cached per-post.
 *
 * @param int $post_id Post ID.
 * @return array Array of schema arrays (each with @type).
 */
function frl_get_generated_schemas(int $post_id): array
{
    $cache_key = "generated_schemas_{$post_id}";

    return frl_cache_remember('html', $cache_key, function () use ($post_id) {
        $schemas = [];
        $generators = frl_get_schema_generators($post_id);

        foreach ($generators as $config) {
            $generator_fn = $config['generator'] ?? '';
            if (!is_string($generator_fn) || !is_callable($generator_fn)) {
                if (defined('WP_DEBUG') && WP_DEBUG && function_exists('frl_log')) {
                    frl_log('Schema generator not callable: {fn}', ['fn' => is_string($generator_fn) ? $generator_fn : 'non-string']);
                }
                continue;
            }

            try {
                $schema = call_user_func($generator_fn, $post_id, $config);
            } catch (\Throwable $e) {
                if (defined('WP_DEBUG') && WP_DEBUG && function_exists('frl_log')) {
                    frl_log('Schema generator threw: {msg}', ['msg' => $e->getMessage()]);
                }
                continue;
            }

            if (is_array($schema) && !empty($schema)) {
                $schemas[] = $schema;
            }
        }

        return $schemas;
    });
}

/**
 * Build the generator registry for a given post.
 *
 * Loads from data file (filtered by post type), then applies
 * the frl_schema_generators filter for extensibility.
 *
 * Uses request-level static cache for the data file include.
 *
 * @param int $post_id Post ID.
 * @return array List of generator config arrays.
 */
function frl_get_schema_generators(int $post_id): array
{
    $post_type = get_post_type($post_id);
    if (!$post_type) {
        return [];
    }

    // Request-level static cache — data file parsed once per request
    static $raw_map = null;
    if ($raw_map === null) {
        $file = FRL_DIR_PATH . 'public/schema/data/generators/default-schema-generators.php';
        $raw_map = file_exists($file) ? include $file : [];
        if (!is_array($raw_map)) {
            $raw_map = [];
        }
    }

    $configs = $raw_map[$post_type] ?? [];

    /**
     * Filter the schema generator configs for a post.
     *
     * @param array  $configs   Generator config arrays.
     * @param int    $post_id   Post ID.
     * @param string $post_type Post type.
     */
    return apply_filters('frl_schema_generators', $configs, $post_id, $post_type);
}

// ─── Built-in Generators ─────────────────────────────────────────

/**
 * Generate a HowTo schema from ACF or ACPT repeater data.
 *
 * @param int   $post_id Post ID.
 * @param array $config  Generator config from data file.
 * @return array|null HowTo schema array, or null if no steps.
 */
function frl_schema_generate_howto(int $post_id, array $config): ?array
{
    $source    = $config['source'] ?? 'acf';
    $repeater  = $config['repeater'] ?? '';
    $step_name = $config['step_name'] ?? 'title';
    $step_text = $config['step_text'] ?? 'text';
    $schema_name_raw = $config['schema_name'] ?? '';

    if (empty($repeater)) {
        return null;
    }

    // Resolve schema name
    $schema_name = ($schema_name_raw === '{{post_title}}')
        ? get_the_title($post_id)
        : $schema_name_raw;

    // Delegate to source-specific fetcher
    $steps = ($source === 'acpt')
        ? frl_schema_get_howto_steps_acpt($post_id, $repeater, $step_name, $step_text)
        : frl_schema_get_howto_steps_acf($post_id, $repeater, $step_name, $step_text);

    if (empty($steps)) {
        return null;
    }

    return [
        '@type' => 'HowTo',
        'name'  => $schema_name,
        'step'  => $steps,
    ];
}

/**
 * Read HowTo steps from an ACF repeater.
 *
 * Uses have_rows()/the_row()/get_sub_field() — the standard ACF repeater API.
 * Returns [] if ACF is not available or the repeater has no rows.
 *
 * @param int    $post_id    Post ID.
 * @param string $repeater   Repeater field name.
 * @param string $name_field Sub-field key for step name.
 * @param string $text_field Sub-field key for step text.
 * @return array List of HowToStep arrays.
 */
function frl_schema_get_howto_steps_acf(int $post_id, string $repeater, string $name_field, string $text_field): array
{
    if (!function_exists('have_rows')) {
        return [];
    }

    $steps = [];
    if (have_rows($repeater, $post_id)) {
        while (have_rows($repeater, $post_id)) {
            the_row();
            $name = get_sub_field($name_field);
            $text = get_sub_field($text_field);
            if (!empty($name) && !empty($text)) {
                $steps[] = [
                    '@type' => 'HowToStep',
                    'name'  => is_string($name) ? $name : '',
                    'text'  => is_string($text) ? $text : '',
                ];
            }
        }
    }

    return $steps;
}

/**
 * Read HowTo steps from an ACPT repeater.
 *
 * ACPT stores repeaters as columnar serialized arrays:
 *   meta_key = 'howto_steps' → ['title' => [...], 'answer' => [...]]
 *
 * Uses frl_get_post_meta() which handles both ACPT raw meta and ACF fallback.
 *
 * @param int    $post_id    Post ID.
 * @param string $repeater   Repeater meta key.
 * @param string $name_field Column key for step name.
 * @param string $text_field Column key for step text.
 * @return array List of HowToStep arrays.
 */
function frl_schema_get_howto_steps_acpt(int $post_id, string $repeater, string $name_field, string $text_field): array
{
    $data = frl_get_post_meta($post_id, $repeater, true);
    if (!is_array($data)) {
        return [];
    }

    $names = $data[$name_field] ?? [];
    $texts = $data[$text_field] ?? [];
    if (!is_array($names) || !is_array($texts)) {
        return [];
    }

    $steps = [];
    $count = min(count($names), count($texts));
    for ($i = 0; $i < $count; $i++) {
        $name = $names[$i] ?? '';
        $text = $texts[$i] ?? '';
        if ($name !== '' && $text !== '') {
            $steps[] = [
                '@type' => 'HowToStep',
                'name'  => (string) $name,
                'text'  => (string) $text,
            ];
        }
    }

    return $steps;
}
```

### Step 8: Create `data/generators/default-schema-generators.php`

```php
<?php
/**
 * Default Schema Generators Data
 *
 * Pure data file — no function calls.
 * Maps post types to generator configurations.
 *
 * Generator config keys:
 *   generator   — callable fn(int $post_id, array $config): ?array
 *   source      — data source: 'acf' or 'acpt'
 *   repeater    — repeater field name
 *   step_name   — sub-field for step name/title
 *   step_text   — sub-field for step description/text
 *   schema_name — placeholder or string for schema name
 *                 '{{post_title}}' → get_the_title($post_id)
 *                 plain string → used as-is
 *
 * @package Fralenuvole
 */

if (!defined('ABSPATH')) {
    exit;
}

return [
    'post' => [
        [
            'generator'   => 'frl_schema_generate_howto',
            'source'      => 'acf',              // 'acf' or 'acpt'
            'repeater'    => 'howto_steps',       // ACF/ACPT repeater field name
            'step_name'   => 'title',             // sub-field: step name
            'step_text'   => 'answer',            // sub-field: step description
            'schema_name' => '{{post_title}}',    // placeholder → post title
        ],
    ],
];
```

### Step 9: Verify thirdparty.php — no changes needed

`modules/thirdparty/thirdparty.php` calls only functions (`frl_get_schema_properties`, etc.) — zero file path deps. Its `thirdparty_schema_properties` gate is independent of the new `schema_properties` master toggle.

## Regression Checklist

| # | Check | Method |
|---|-------|--------|
| 1 | `frl_get_schema_properties()` resolves correctly | Traced all 3 call sites in thirdparty.php — function calls only |
| 2 | `frl_get_schema_data_file()` finds data files | Updated path to `data/properties/` |
| 3 | `frl_get_schema_term_map()` works | Path resolved by updated function |
| 4 | `frl_get_schema_person_map()` works | Path resolved by updated function |
| 5 | All builder functions unchanged | Builders call resolver functions, not files |
| 6 | SASWP hooks unaffected | `thirdparty.php` has zero file path deps |
| 7 | Tag validator finds `id="frl-schema"` | New output uses same element ID |
| 8 | PHP parse errors | All files syntax-validated |
| 9 | Generators don't run on admin/REST/preview | Guarded in `frl_output_generated_schemas()` |
| 10 | Re-entrancy safe | `frl_is_already_running()` guard |
| 11 | `schema_properties=0` kills all property injection | Guard in resolver.php + builders.php |
| 12 | `schema_generator=0` kills all generation output | Guard in schema-generator.php |
| 13 | `thirdparty_schema_properties` still gates SASWP | Unchanged — independent of master toggles |

## ACPT ↔ ACF Swap

Change `'source' => 'acf'` to `'source' => 'acpt'` in the data file. The generator routes to the correct fetcher. Zero code changes.

## Extending with New Generators

Add to `default-schema-generators.php`:

```php
'my_cpt' => [
    [
        'generator' => 'my_custom_generator_fn',
        /* config keys */
    ],
],
```

Or via filter:

```php
add_filter('frl_schema_generators', function ($configs, $post_id, $post_type) {
    if ($post_type === 'my_cpt') {
        $configs[] = ['generator' => 'my_custom_generator_fn', /* ... */];
    }
    return $configs;
}, 10, 3);
```
