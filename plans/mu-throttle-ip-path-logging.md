# MU Plugin Throttle — IP/Path + Aggregated Logging Plan (v2)

## User Clarifications Incorporated

1. **Throttle dimensions are independent (OR logic):** UA, IP, and Path are separate arrays — if ANY matches → throttle.
2. **New constants:** `FRL_MU_THROTTLE_IP` and `FRL_MU_THROTTLE_PATH` (arrays, empty = disabled).
3. **24-hour log retention** (not 1-hour).
4. **Logging display in plugin admin dashboard** (not WordPress dashboard), following `admin/components/` pattern.
5. **Top 5 table** with three column groups (By UA | # | By Path | # | By IP | #), each group visible only if its corresponding const is non-empty.

---

## Current State

[`frl_maybe_throttle_user_agent()`](includes/mu/functions-mu.php:31-69):
- Checks only [`FRL_MU_THROTTLE_USER_AGENT`](config/config-mu.php:23)
- Throttle key: `bot_throttle_` + `md5($ip)` — IP-only granularity
- Uses `frl_get_transient`/`frl_set_transient` for counters
- On limit exceeded: HTTP 429 + `exit()`

Admin dashboard is rendered by [`Frl_Admin_Dashboard::render()`](admin/components/class-dashboard.php:37) which composes widgets from display classes using [`frl_ui_render_widget()`](admin/helpers/functions-admin-class-helpers-ui.php:448).

---

## Feature 1: Multi-Dimensional Throttling (OR Logic)

### New Constants in [`config/config-mu.php`](config/config-mu.php)

```php
/**
 * IP substrings to throttle. When non-empty, any request from an IP
 * matching any entry (via stripos against the resolved client IP) is throttled.
 * Empty array = disabled.
 * @var string[]
 */
const FRL_MU_THROTTLE_IP = [];

/**
 * Request path substrings to throttle. When non-empty, any request to a path
 * containing any entry (via stripos against REQUEST_URI) is throttled.
 * Empty array = disabled.
 * @var string[]
 */
const FRL_MU_THROTTLE_PATH = [];
```

### Modified `frl_maybe_throttle_user_agent()` Logic

```
Current flow:
  1. empty($_SERVER['HTTP_USER_AGENT']) → return
  2. foreach FRL_MU_THROTTLE_USER_AGENT → stripos → $is_throttled_bot
  3. if !$is_throttled_bot → return
  4. Resolve IP
  5. Check transient counter → 429 or increment

New flow:
  1. empty($_SERVER['HTTP_USER_AGENT']) → return (preserved)
  2. foreach FRL_MU_THROTTLE_USER_AGENT → stripos → $matched_ua (capture matched pattern)
  3. foreach FRL_MU_THROTTLE_IP → stripos → $matched_ip = true
  4. foreach FRL_MU_THROTTLE_PATH → stripos against REQUEST_URI → $matched_path = true
  5. if !($matched_ua || $matched_ip || $matched_path) → return
  6. Resolve IP (same as current)
  7. Check transient counter → 429 or increment
  8. On 429: log aggregated counters, then exit
```

Each dimension's array is independently checked. When empty, the `foreach` loops zero times — zero overhead.

Transient key remains `bot_throttle_` + `md5($ip)`. The IP-only key is sufficient because the OR logic means any matching request is throttled the same way regardless of which dimension triggered it.

---

## Feature 2: Aggregated Throttle Logging (24h Retention)

### Data Structure

Three map transients, each keyed by dimension, storing `{ dimension_value => count }`:

| Transient Key | Structure | Example |
|---|---|---|
| `throttle_log_ua_map` | `{"ChatGPT-User": 47, "Claude-Web": 12}` | UA pattern → block count |
| `throttle_log_ip_map` | `{"1.2.3.4": 30, "5.6.7.8": 15}` | IP address → block count |
| `throttle_log_path_map` | `{"/wp-json/": 25, "/blog/": 10}` | Request path → block count |

### Logging Logic (Inside 429 Block)

```php
// Before http_response_code() + exit()
$blocked_ua = $matched_ua_pattern; // The specific UA substring that matched
$blocked_ip = $ip;
$blocked_path = $_SERVER['REQUEST_URI'] ?? '/';

// Increment per-dimension maps (only for non-empty const arrays)
if (!empty(FRL_MU_THROTTLE_USER_AGENT)) {
    frl_throttle_increment_map('throttle_log_ua_map', $blocked_ua);
}
if (!empty(FRL_MU_THROTTLE_IP)) {
    frl_throttle_increment_map('throttle_log_ip_map', $blocked_ip);
}
if (!empty(FRL_MU_THROTTLE_PATH)) {
    frl_throttle_increment_map('throttle_log_path_map', $blocked_path);
}
```

### Helper Function (in [`includes/mu/functions-mu.php`](includes/mu/functions-mu.php))

```php
function frl_throttle_increment_map(string $transient_key, string $dimension_value): void
{
    $map = frl_get_transient($transient_key);
    if (!is_array($map)) {
        $map = [];
    }
    
    // Increment or initialize
    $map[$dimension_value] = ($map[$dimension_value] ?? 0) + 1;
    
    // Cap at 200 entries to prevent unbounded growth; keep top entries by count
    if (count($map) > 200) {
        arsort($map, SORT_NUMERIC);
        $map = array_slice($map, 0, 100, true);
    }
    
    frl_set_transient($transient_key, $map, DAY_IN_SECONDS);
}
```

### Design Properties

- **Only fires on 429 blocks** — under-limit matching requests produce zero log overhead
- **Capped at 200 entries per map** — prevents unbounded growth from DDoS with spoofed IPs
- **24-hour auto-expiry** — no cron cleanup needed
- **Static caching:** `frl_get_transient`/`frl_set_transient` use per-request static cache — within a single request, repeated calls are zero-cost

---

## Feature 3: Admin Dashboard Display (Top 5 Table)

### Integration Point

The plugin admin dashboard is rendered by [`Frl_Admin_Dashboard::render()`](admin/components/class-dashboard.php:37). We add a new widget after the existing "Plugins Exclusions" widget.

### New Display Component

**File:** `admin/components/class-display-throttle.php` (new, follows pattern of [`class-display-cache.php`](admin/components/class-display-cache.php) and [`class-display-environment.php`](admin/components/class-display-environment.php))

```php
class Frl_Throttle_Display
{
    public function render(): string
    {
        $columns = [];
        
        // Only render dimensions whose const arrays are non-empty
        if (!empty(FRL_MU_THROTTLE_USER_AGENT)) {
            $columns[] = $this->render_top5_column('UA', 'throttle_log_ua_map');
        }
        if (!empty(FRL_MU_THROTTLE_PATH)) {
            $columns[] = $this->render_top5_column('Path', 'throttle_log_path_map');
        }
        if (!empty(FRL_MU_THROTTLE_IP)) {
            $columns[] = $this->render_top5_column('IP', 'throttle_log_ip_map');
        }
        
        if (empty($columns)) {
            return ''; // Nothing configured → nothing to display
        }
        
        // Build the full table
        $content = $this->render_multi_column_table($columns);
        
        return frl_ui_render_widget(
            'throttle-log',
            $content,
            'Bot Throttle — Blocked Requests (24h)',
            'throttle-log-widget'
        );
    }
    
    private function render_top5_column(string $label, string $transient_key): array
    {
        $map = frl_get_transient($transient_key);
        if (!is_array($map) || empty($map)) {
            return ['label' => $label, 'rows' => [['—', '0']]];
        }
        
        arsort($map, SORT_NUMERIC);
        $top5 = array_slice($map, 0, 5, true);
        
        $rows = [];
        foreach ($top5 as $key => $count) {
            $rows[] = [esc_html($key), (int) $count];
        }
        
        return ['label' => $label, 'rows' => $rows];
    }
    
    private function render_multi_column_table(array $columns): string
    {
        // Build a horizontal table: header row with column labels, then data rows
        // Uses existing frl_ui_render_table / frl_ui_render_table_row helpers
        // Each column group: "By UA" | "#" | "By Path" | "#" | "By IP" | "#"
        
        // Header row
        $header_cells = [];
        foreach ($columns as $col) {
            $header_cells[] = 'By ' . $col['label'];
            $header_cells[] = '#';
        }
        
        // Data rows (max 5)
        $max_rows = max(array_map(fn($c) => count($c['rows']), $columns));
        $data_rows = '';
        for ($i = 0; $i < $max_rows; $i++) {
            $cells = [];
            foreach ($columns as $col) {
                $cells[] = $col['rows'][$i][0] ?? '';
                $cells[] = isset($col['rows'][$i][1]) ? (string) $col['rows'][$i][1] : '';
            }
            $data_rows .= frl_ui_render_multi_column_row($cells);
        }
        
        return frl_ui_render_multi_column_header($header_cells, 'throttle-log-header')
             . $data_rows;
    }
}
```

### Registration in Dashboard

In [`Frl_Admin_Dashboard::render()`](admin/components/class-dashboard.php:37), after the plugins-exclusions widget (line 85):

```php
// Add throttle log widget (only if at least one dimension is configured)
if (!empty(FRL_MU_THROTTLE_USER_AGENT) || !empty(FRL_MU_THROTTLE_IP) || !empty(FRL_MU_THROTTLE_PATH)) {
    $throttle_display = new Frl_Throttle_Display();
    $widget_content .= $throttle_display->render();
}
```

### Helper in [`functions-admin-class-helpers-ui.php`](admin/helpers/functions-admin-class-helpers-ui.php)

Add a wrapper function following the existing pattern:

```php
function frl_throttle_display_render()
{
    if (!frl_class_exists('Frl_Throttle_Display', __FUNCTION__)) {
        return '';
    }
    $throttle = new Frl_Throttle_Display();
    return $throttle->render();
}
```

### UI File Loading

In [`admin/ui/ui-admin-settings.php`](admin/ui/ui-admin-settings.php), add the new component require:

```php
require_once(FRL_DIR_PATH . 'admin/components/class-display-throttle.php');
```

---

## Performance Analysis: 500 Requests in 30 Minutes

### Scenario Parameters
- 500 total bot requests in 30 minutes
- `FRL_MU_THROTTLE_LIMIT = 10`, `FRL_MU_THROTTLE_PERIOD = 60`
- 3 UA patterns, 50 unique IPs, 10 unique paths

### Case A: Evenly Distributed (No Blocks)
500 requests / 50 IPs = 10 requests per IP over 30 min = 0.33 req/min/IP. Well under 10/60s limit.

| Phase | Overhead vs Current |
|-------|-------------------|
| UA/IP/Path check | +2 `foreach` loops (IP + Path arrays). If arrays have entries, ~2-6 extra `stripos` calls. Negligible (~microseconds). |
| Transient read (counter) | Unchanged — same `frl_get_transient` call |
| Transient write (counter) | Unchanged — same `frl_set_transient` call |
| Log writes | **Zero** — no blocks occurred |

**Net impact: ~0.001ms per request. Negligible.**

### Case B: Bursty — 50 IPs, 20 Requests Each in 60s (500 Blocks)
Each IP: 10 pass, 10 blocked = 500 blocks total across 500 PHP processes.

| Phase | Per-Block Overhead |
|-------|-------------------|
| UA/IP/Path check | Same as Case A (~microseconds) |
| Transient counter check | Unchanged |
| 3 map transient reads | ~0.3-1.5ms (object cache) or ~3-15ms (DB) |
| 3 map transient writes | ~0.3-1.5ms (object cache) or ~3-15ms (DB) |
| **Total per block** | **~0.6-3ms (cached) or ~6-30ms (DB)** |

**Key:** The process is already terminating (`exit()`). This latency is invisible to legitimate users and only affects the blocked bot.

### Case C: Non-Matching Traffic (99.9%+ of All Requests)
- Early return at `empty($_SERVER['HTTP_USER_AGENT'])` — zero change
- If UA present but doesn't match: 3 `foreach` loops. If IP/Path arrays are empty (common), loops iterate zero times. **Zero overhead vs current.**

### Storage Analysis

| Data | Size Estimate | Auto-Cleanup |
|------|-------------|-------------|
| Per-IP throttle counter (`bot_throttle_*`) | ~50 transients, ~100 bytes each = ~5KB | TTL = 60s |
| UA log map | ~3 entries, ~200 bytes | TTL = 24h |
| IP log map | ~50 entries, ~2KB | TTL = 24h |
| Path log map | ~10 entries, ~500 bytes | TTL = 24h |
| **Total persistent storage** | **< 10KB** | Self-cleaning |

No DB table. No cron job. No log rotation. Storage grows proportionally to unique blocked IPs/paths/UAs, capped at 200 entries per map.

---

## Implementation Checklist

### Step 1: Add new constants
- **File:** [`config/config-mu.php`](config/config-mu.php)
- Add `FRL_MU_THROTTLE_IP = []`
- Add `FRL_MU_THROTTLE_PATH = []`

### Step 2: Refactor `frl_maybe_throttle_user_agent()` — multi-dimension check
- **File:** [`includes/mu/functions-mu.php`](includes/mu/functions-mu.php)
- Add `foreach` loops for `FRL_MU_THROTTLE_IP` and `FRL_MU_THROTTLE_PATH`
- Capture `$matched_ua_pattern` (the specific UA substring that matched) for logging
- Change `$is_throttled_bot` → `$should_throttle = $matched_ua || $matched_ip || $matched_path`
- Rename function to `frl_maybe_throttle()` (optional, keeps `frl_maybe_throttle_user_agent()` as alias for backward compat)

### Step 3: Add `frl_throttle_increment_map()` helper
- **File:** [`includes/mu/functions-mu.php`](includes/mu/functions-mu.php)
- New function handling map read/increment/cap/write with 24h TTL

### Step 4: Add aggregated logging inside 429 block
- **File:** [`includes/mu/functions-mu.php`](includes/mu/functions-mu.php)
- Before `http_response_code()`, call `frl_throttle_increment_map()` for each non-empty dimension
- Use the matched UA pattern, resolved IP, and REQUEST_URI

### Step 5: Create throttle display component
- **File:** `admin/components/class-display-throttle.php` (new)
- `Frl_Throttle_Display` class with `render()`, `render_top5_column()`, `render_multi_column_table()`
- Uses existing `frl_ui_render_widget()`, `frl_ui_render_table_row()`, `frl_ui_render_multi_column_header()`, `frl_ui_render_multi_column_row()`

### Step 6: Load the component and add helper
- **File:** [`admin/ui/ui-admin-settings.php`](admin/ui/ui-admin-settings.php)
- Add `require_once` for the new component
- **File:** [`admin/helpers/functions-admin-class-helpers-ui.php`](admin/helpers/functions-admin-class-helpers-ui.php)
- Add `frl_throttle_display_render()` wrapper function

### Step 7: Wire widget into dashboard
- **File:** [`admin/components/class-dashboard.php`](admin/components/class-dashboard.php)
- In `render()`, after the plugins-exclusions widget, add the throttle widget (guarded: only if at least one throttle dimension is non-empty)

### Step 8: Update constant references in `mu.php`
- **File:** [`includes/mu/mu.php`](includes/mu/mu.php)
- Verify `frl_maybe_throttle_user_agent()` call works with renamed/refactored function

---

## Files Summary

| File | Change |
|------|--------|
| [`config/config-mu.php`](config/config-mu.php) | +2 constants: `FRL_MU_THROTTLE_IP`, `FRL_MU_THROTTLE_PATH` |
| [`includes/mu/functions-mu.php`](includes/mu/functions-mu.php) | Refactor throttle func + new helper + logging |
| [`includes/mu/mu.php`](includes/mu/mu.php) | Verify function name consistency |
| `admin/components/class-display-throttle.php` | **New** — display component |
| [`admin/ui/ui-admin-settings.php`](admin/ui/ui-admin-settings.php) | Add require_once |
| [`admin/helpers/functions-admin-class-helpers-ui.php`](admin/helpers/functions-admin-class-helpers-ui.php) | Add wrapper function |
| [`admin/components/class-dashboard.php`](admin/components/class-dashboard.php) | Wire widget into render() |
