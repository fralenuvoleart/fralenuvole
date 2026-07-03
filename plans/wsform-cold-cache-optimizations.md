# WSForm Cold-Cache Micro-Optimizations

## Patch 1: Batch form name queries (N+1 → 1 query)

**File:** `modules/wsform/stats/wsform-submissions.php`  
**Lines 75-81**

### Current:
```php
$form_ids = array_unique(wp_list_pluck($results, 'form_id'));
$form_names = [];

foreach ($form_ids as $form_id) {
    $form_names[$form_id] = frl_wsf_get_form_name($form_id);
}
```

### Replace with:
```php
$form_ids = array_unique(wp_list_pluck($results, 'form_id'));
$form_names = [];

if (!empty($form_ids)) {
    $placeholders = implode(',', array_fill(0, count($form_ids), '%d'));
    $form_rows = $wpdb->get_results($wpdb->prepare(
        "SELECT ID, post_title FROM {$wpdb->posts} WHERE ID IN ($placeholders) AND post_type = 'wsf-form'",
        ...array_map('intval', $form_ids)
    ));
    foreach ($form_rows as $row) {
        $form_names[(int)$row->ID] = $row->post_title;
    }
    // Fill in any missing IDs that weren't in posts (shouldn't happen, but safe)
    foreach ($form_ids as $form_id) {
        if (!isset($form_names[$form_id])) {
            $form_names[$form_id] = 'Form #' . $form_id;
        }
    }
}
```

**Note:** `frl_wsf_get_form_name()` is still used elsewhere (e.g. `wsform-widget.php`), so it stays.

---

## Patch 2: Static cache for WSForm table existence check

**File:** `modules/wsform/stats/wsform-submissions.php`  
**Lines 40-45**

### Current (inside the `frl_cache_remember` callback):
```php
$submissions_table = $wpdb->prefix . 'wsf_submit';

$table_exists = $wpdb->get_var(
    $wpdb->prepare("SHOW TABLES LIKE %s", $submissions_table)
) === $submissions_table;
```

### Replace with (move static check OUTSIDE the callback):
After `global $wpdb;` on line 21, add:
```php
static $wsf_table_exists = null;
if ($wsf_table_exists === null) {
    $submissions_table = $wpdb->prefix . 'wsf_submit';
    $wsf_table_exists = $wpdb->get_var(
        $wpdb->prepare("SHOW TABLES LIKE %s", $submissions_table)
    ) === $submissions_table;
}
```

Then inside the callback (line 34), replace the table-existence block with just:
```php
if ($wsf_table_exists) {
```

And remove lines 40-45 entirely from inside the callback.

**Note:** `$stats_form_ids` guard remains — the `$wsf_table_exists` check goes BEFORE the `frl_cache_remember()` call. Actually better: put the static inside the callback since it's the callback body that uses it, but guard it with `use ($wpdb)` which is already passed. Simplest approach: add `static $table_exists = null;` as the FIRST line inside the callback body (before the date calculation), check it, `SHOW TABLES LIKE` only on first miss. This keeps all the logic co-located and the `use` clause unchanged.

### Implementation (co-located inside callback):
Before line 35 (the date calculation), insert:
```php
static $wsf_table_exists = null;
```

Replace lines 40-45 with:
```php
if ($wsf_table_exists === null) {
    $submissions_table = $wpdb->prefix . 'wsf_submit';
    $wsf_table_exists = $wpdb->get_var(
        $wpdb->prepare("SHOW TABLES LIKE %s", $submissions_table)
    ) === $submissions_table;
}

if ($wsf_table_exists) {
```
