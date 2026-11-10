# Invariant tests

Dependency-free tests for the plugin logic that broke in the 5.8.8 → 5.8.9
review. They run on plain PHP, with WordPress functions stubbed: no database,
no WordPress load, no network.

## Run

```sh
php tests/invariants/run.php          # full output
php tests/invariants/run.php --quiet  # one line per file (used by the hook)
```

## What each file guards

| File | Invariant |
|---|---|
| `rewriter-invalidation-test.php` | The registered-slug hash is compared once per request; a steady request performs no option writes; a changed registry writes the hash and bumps the version once. |
| `flush-accounting-test.php` | One flush per requested change, never on frontend, never in the same request that changed the settings; an init-time flush survives a request that exits before `wp_loaded`; activation schedules one flush event. |
| `cache-key-contract-test.php` | Language groups prefix the key with the language, other groups ignore it, and array keys are hashed so `a/b` and `ab` stay distinct. |
| `meta-clear-test.php` | Tracked translated meta is cleared across every active language, issues no deletes when nothing was tracked, and a deleted language purges the whole group. |
| `rewriter-routing-test.php` | Production URL shapes (fixture in `fixtures/live-rewriter.php`): single-CPT bases per language, `/feed/` 404 with comments disabled, taxonomy base removal, no hijack of core/Polylang URLs, post-vs-category under `/blog/`, team-member-vs-page under `/about/`. Goal: no 404 on a valid URL. |

The routing fixture mirrors `pbservices.ge`: languages en/ru/ar/zh, `en` hidden;
`/blog/%postname%/`; category base removal; `service` bases en/services,
ru/uslugi, ar/offers (no zh); comments disabled; posts page `blog`.

Each test fails with `NOT OK - <description>` and the expected/actual values,
and the runner exits non-zero.

## Pre-commit hook

```sh
tests/invariants/install-pre-commit.sh
```

Installs `.git/hooks/pre-commit`: runs the invariant tests, then `php -l` on
staged PHP files. An existing non-Fralenuvole hook is backed up first.

## Staging smoke check

`tests/smoke-urls.sh` checks status, redirect count, canonical, and leftover
`{{...}}` delimiters for a URL list. See `tests/smoke-urls.example.txt`.

```sh
tests/smoke-urls.sh https://stg-pbservicesge-staging.kinsta.cloud/ tests/smoke-urls.txt
```
