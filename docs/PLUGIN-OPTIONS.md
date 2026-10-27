# Plugin Options 

Guide to how this plugin stores and reads its settings. For day-to-day work  `frl_get_option()` and `frl_update_option()`. 

---

## 1. The two functions

```php
$value = frl_get_option( 'header_html' );          // read
frl_update_option( 'header_html', '<script>…</script>' ); // write
```

- Keys are written **without** the prefix. The plugin stores them in `wp_options` as `frl_header_html`, but always pass `'header_html'`.
- `frl_get_option()` returns the value already normalized to the field's type (see section 4).
- A special key `'__reset__'` is an internal command used by the cache manager to clear the in-request snapshot. Never call it from feature code.

There is also `frl_delete_option( $key, $cache_group )` to remove an option.

---

## 2. Where an option's definition lives

A "registered" option is declared in one of three places, all merged into one defaults map:

| Source | File | Use for |
|---|---|---|
| Main fields | `config/config-options.php` (`FRL_DEFAULT_FIELDS`) | Normal settings shown in the admin UI |
| Runtime options | `config/config-options.php` (`FRL_OPTIONS_RUNTIME`) | Internal values with no admin field |
| Module options | each module's `config-options-*.php` | Options owned by a disposable module |

Each definition carries: `id`, `type`, `default`, and optionally `autoload`. The merged defaults map is built **once** and cached under a single key (`options_defaults_map`); earlier versions cached a full copy per key, which this replaces.

---

## 3. How a read works (three tiers)

`frl_get_option( 'x' )` resolves in this order:

1. **Static snapshot** — a per-request array. The first read loads it; every later read in the same request is free.
2. **Persistent cache** — if the snapshot is not built yet, it is loaded from the `options` cache group (object cache or transient). On a miss it falls through to a single database query.
3. **Database** — one `LIKE 'frl_%'` scan loads and normalizes every plugin option in one go (not one query per option).

Two safety nets sit on top:

- **Stale-snapshot recovery** — if a key is missing from the snapshot, one direct `get_option()` checks the database and patches the snapshot. This covers an option created by another request after the snapshot was cached.
- **Missing-option self-seeding** — if the key truly does not exist in the database (for example a field added by a new code deploy), the plugin writes its registered default once and returns it. On a normal install or reset, most defaults are already pre-written at activation by `frl_seed_option_defaults()`, so this path mainly covers options introduced by a later version.

---

## 4. Normalization (values are not stored raw)

Every value is normalized to its declared `type` on both read and write:

- `checkbox` / `radio` → the strings `'1'` or `'0'` (never booleans).
- `textlist` → trimmed lines, empty lines removed, stored as a newline string; use `frl_textlist_to_array()` to split it.
- `text`, `textarea`, `html`, `wysiwyg`, `select`, `email`, `url`, `number`, `color`, `date`, `password`, `custom` → stored as-is.
- Formatting-only types (`section_title`, `divider`, `heading`, `description`) are never options and are filtered out.

Practical consequence: a checkbox option reads back as `'1'`/`'0'`, so test it with `frl_get_option( 'x' ) === '1'` or a truthy check, not `=== true`.

---

## 5. How a write works

`frl_update_option( $key, $value, $clear_cache = true, $autoload = null )`:

1. Removes any stale `pre_option_frl_x` filter from a previous write in the same request (this also removes a third-party filter on that exact hook; the `frl_` namespace makes that unlikely).
2. Normalizes the value and writes it with `update_option()`.
3. If `$clear_cache` is true, clears the `all_options` snapshot key so the next request re-reads. Editing `header_html`/`footer_html` also clears only the two HTML cache keys that depend on them (not the whole HTML group).
4. Adds a `pre_option_frl_x` filter returning the new value, so any native `get_option( 'frl_x' )` later in the **same request** sees the fresh value.

Note: `update_option()` returns false when the value did not actually change. So a `false` return from `frl_update_option()` means "not written", not necessarily "failed".

---

## 6. Autoload — opt-in, not default

Plugin options default to `autoload = 'no'`. This matters because WordPress loads every autoloaded option on every request; since the plugin reads its options through its own cache (not `get_option()`), autoloading them would only bloat that payload.

Keep `autoload = 'yes'` **only** for an option read via native `get_option()` on a hot path. Today these are the subsystem-disable gate flags — read at bootstrap so the first gate check does not trigger the full options load: `disable_plugin`, `disable_cache`, `disable_environment`, `disable_rewriter`, `disable_translator`.

Because `update_option()` does not change the autoload flag when the value is unchanged, an activation step (`frl_migrate_option_autoload()`) refreshes existing rows with a direct column update. Re-activate the plugin after deploy so this runs.

---

## 7. Transients and falsey caching

Two related helpers exist for short-lived values:

- `frl_get_transient()` / `frl_set_transient()` / `frl_delete_transient()` — plugin-prefixed transients with a per-request static cache. Assumes the plugin is the only writer of `frl_*` transients.
- The cache manager can now store `false`/`null` as real cached values. Object-cache reads use the `$found` flag (with a safe fallback for drop-ins that do not implement it); transient reads wrap falsey values in an internal sentinel. `false` is no longer mistaken for a miss.

---

## 8. Cache groups relevant to options

| Group | Holds | TTL |
|---|---|---|
| `options` | the option snapshot, defaults map, translated-option caches | 1 day (actively invalidated; TTL is only a safety net for out-of-band DB edits) |
| `adminui` | field/tab/module definitions for the settings screen | 1 day |

Invalidation is event-driven: `frl_update_option()` clears the snapshot, and the `updated_option` hook clears translated-option caches across all languages in a single batched call.

---

## 9. Adding a new option 

1. Add a field to `FRL_DEFAULT_FIELDS` (admin UI) or `FRL_OPTIONS_RUNTIME` (internal) with `id`, `type`, and `default`.
2. Leave `autoload` off (defaults to `no`) unless the value is read by native `get_option()` on every request.
3. Read it with `frl_get_option( 'your_key' )`; write with `frl_update_option( 'your_key', $value )`.
4. If it is a `textlist`, split with `frl_textlist_to_array()`.
5. After deploying a new option, flush the plugin caches once (or re-activate) so the defaults map and snapshot refresh.

---

## 10. Gotchas worth knowing

- Do not add a blog-id to option cache keys. The plugin is single-site by design: one configuration shared across a network, differentiated per environment, not per site.
- `frl_get_option( $key, true )` (bypass) skips the **persistent** cache and returns the request's database snapshot; it does not re-query the database a second time within one request.
- Reading many options at once: use `frl_get_plugin_options( array( 'a', 'b' ) )` or `'all'` rather than looping `frl_get_option()`.
- Internal one-time state (a "has this run?" flag) must use a plain `_frl_`-prefixed option, never a cache group — cache groups are wiped by a normal "Clear Cache" and would silently re-trigger the work.
