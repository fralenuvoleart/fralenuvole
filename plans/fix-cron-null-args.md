# Fix: `count(null)` TypeError on Inline Cron Processing

## Root Cause

The `cron` option in the WordPress database contains scheduled events where `args` is `null` instead of `[]`. When inline cron runs during admin page loads, `wp-cron.php` calls `do_action_ref_array($hook, $event['args'])` with `null`, which triggers `count(null)` TypeError on PHP 8+ at `class-wp-hook.php:325`.

Your debugging conclusively proved **the main fralenuvole plugin is the cause** — not the MU plugin. However, the exact corruption mechanism (how null args get stored in the cron option) could not be pinpointed to a specific line. The plugin does not directly call `update_option('cron', ...)` or register `pre_option_cron`/`option_cron` filters.

## Fix Strategy

**Add an `option_cron` filter in the main plugin that sanitizes null args to `[]` unconditionally.**

This is the same approach already used in the MU plugin at `functions-mu-plugin.php:318-320`, but placed in the main plugin so it:
- Works regardless of MU plugin presence
- Works regardless of exclusion settings
- Does NOT require `DOING_CRON` to be defined
- Covers both standalone cron AND inline cron during page loads

Using `option_cron` (post-read) rather than `pre_option_cron` (pre-read) because:
- The value is already loaded from DB/WordPress cache — no extra DB query needed
- Doesn't conflict with the MU plugin's `pre_option_cron` filter if present
- Simpler implementation

## Implementation

### File to modify: [`includes/main.php`](includes/main.php)

**Add** a new function + filter registration in the main plugin execution file:

```php
/**
 * Sanitizes cron event args to prevent TypeError from null args.
 *
 * WordPress cron events with null args cause count(null) TypeError on PHP 8+
 * when wp-cron.php calls do_action_ref_array($hook, $event['args']).
 *
 * @param mixed $cron The cron option value.
 * @return array Sanitized cron array.
 */
function frl_sanitize_cron_args($cron)
{
    if (!is_array($cron)) {
        return is_array($cron) ? $cron : [];
    }

    foreach ($cron as $timestamp => $hooks) {
        if (!is_array($hooks)) {
            continue;
        }
        foreach ($hooks as $hook => $events) {
            if (!is_array($events)) {
                continue;
            }
            foreach ($events as $hash => $event) {
                if (!isset($event['args']) || !is_array($event['args'])) {
                    $cron[$timestamp][$hook][$hash]['args'] = [];
                }
            }
        }
    }

    return $cron;
}
add_filter('option_cron', 'frl_sanitize_cron_args', 999, 1);
```

**Why priority 999**: Ensures the sanitization runs after any other `option_cron` filters, catching any null args introduced by other filters.

### Considerations

1. **Performance**: The filter only runs when `get_option('cron')` is called, which happens once per page load (during inline cron check). The loop is O(n) over scheduled events, which is negligible.
2. **No side effects**: Only modifies null args to empty arrays — doesn't change schedules, hooks, or event keys.
3. **Compatible with MU plugin**: The MU plugin uses `pre_option_cron` (pre-read), the main plugin uses `option_cron` (post-read). They work at different stages and don't conflict.
4. **No deployment steps**: Unlike the MU plugin (which needs manual copying to `wp-content/mu-plugins/`), this fix is deployed with the main plugin update.

## Files Changed

| File | Change |
|------|--------|
| `includes/main.php` | Add `frl_sanitize_cron_args()` function + `add_filter('option_cron', ...)` |
