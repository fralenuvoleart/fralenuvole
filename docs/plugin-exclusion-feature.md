# Plugin Exclusion Feature - Architectural Plan

## 1. Context

**Existing System:**
- `Frl_Environment_Plugin_Manager` handles environment-based plugin activation/deactivation
- Uses `deactivate_plugins()` / `activate_plugins()` — changes persistent state

**New Requirement:**
- Prevent plugins from **loading** without deactivating them
- Condition: **Frontend** OR **User lacks permission**
- This is a runtime per-request filter, not a state change

---

## 2. Implementation Approach

### 2.1 Hook Timing
WordPress loads plugins at `plugins_loaded` hook. To prevent plugins from loading, we must intercept earlier:

```
muplugins_loaded (100)           <-- MU loader runs here
  ↓
plugins_loaded (10)              <-- Regular plugins load, but excluded ones are filtered
  ↓
init (various priorities)
```

### 2.2 Core Mechanism: `pre_option_active_plugins` Filter
- WordPress retrieves active plugins via `get_option('active_plugins')`
- Pre-filter runs BEFORE the option is retrieved
- We can return a filtered array excluding target plugins
- Target plugins never execute any code

---

## 3. Option Keys (Global Scope)

| Key | Type | Purpose |
|-----|------|---------|
| `excluded_plugins_frontend_enabled` | checkbox | Enable frontend exclusion |
| `excluded_plugins_frontend` | textlist | Plugin paths to exclude on frontend |
| `excluded_plugins_bycap_enabled` | checkbox | Enable capability-based exclusion |
| `excluded_plugins_bycap_cap` | text | Capability required to access excluded plugins |
| `excluded_plugins_bycap` | textlist | Plugin paths to exclude based on capability |

---

## 4. Condition Logic (OR)

A plugin is excluded if **ANY** of these conditions are true:

1. **Frontend Condition:** `frl_is_valid_frontend_page_request() && excluded_plugins_frontend_enabled`
2. **Capability Condition:** `!frl_has_access(cap) && excluded_plugins_bycap_enabled`

```php
// Pseudocode
function should_exclude_plugin($plugin) {
    $frontend_exclude = frl_get_option('excluded_plugins_frontend_enabled')
        && frl_is_valid_frontend_page_request();
    
    $cap = frl_get_option('excluded_plugins_bycap_cap') ?: FRL_PLUGIN_ACCESS;
    $cap_exclude = frl_get_option('excluded_plugins_bycap_enabled')
        && !frl_has_access($cap);
    
    if (!$frontend_exclude && !$cap_exclude) {
        return false;
    }
    
    $frontend_list = frl_textlist_to_array(frl_get_option('excluded_plugins_frontend'));
    $cap_list = frl_textlist_to_array(frl_get_option('excluded_plugins_bycap'));
    
    return in_array($plugin, $frontend_list) || in_array($plugin, $cap_list);
}
```

---

## 5. Context Behavior Summary

**Confirmed User Requirements:**
- Cron: Feature does NOT apply (excluded plugins load normally)
- REST/MCP: Feature does NOT apply (must keep working)
- Admin/Editor: Feature does NOT apply (allowed context)

**Open: AJAX**
- `frl_is_valid_frontend_page_request()` returns `false` for AJAX
- Under Option A (default): AJAX is excluded, so excluded plugins load normally during AJAX requests

---

## 6. AJAX Scenarios

### Scenario 1: Frontend AJAX (contact form, infinite scroll)
```
Visitor → AJAX to /wp-admin/admin-ajax.php (no user OR lacks capability)
```
| Option | Excluded Plugins Load? |
|--------|----------------------|
| A (default) | YES - feature does not apply to AJAX |
| B | NO - feature applies to AJAX |

### Scenario 2: Admin AJAX (saving post, plugin updates)
```
User with capability → AJAX to /wp-admin/admin-ajax.php
```
| Option | Excluded Plugins Load? |
|--------|----------------------|
| A or B | YES - admin AJAX always allowed |

### Scenario 3: AJAX from Admin Page (Quick Edit)
```
Admin → AJAX to /wp-admin/admin-ajax.php
```
| Option | Excluded Plugins Load? |
|--------|----------------------|
| A or B | YES - admin AJAX always allowed |

### Scenario 4: AJAX Heartbeat
```
WordPress → heartbeat AJAX to /wp-admin/admin-ajax.php
```
| Option | Excluded Plugins Load? |
|--------|----------------------|
| A or B | YES - always allowed |

---

## 7. MU Loader Implementation

### 7.1 File Location
`assets/mu/frl-mu-plugin.php` — loaded as a Must-Use plugin

### 7.2 Implementation
```php
<?php
/**
 * Fralenuvole - Plugin Conditional Loader
 * Prevents specified plugins from loading based on context/permissions
 */

const FRL_MU_NAME = 'fralenuvole';

add_action('muplugins_loaded', function () {
    // Get exclusion settings
    $frontend_enabled = frl_get_option('excluded_plugins_frontend_enabled');
    $cap_enabled = frl_get_option('excluded_plugins_bycap_enabled');
    
    if (!$frontend_enabled && !$cap_enabled) {
        return;
    }
    
    $excluded = [];
    
    // Frontend exclusion
    if ($frontend_enabled && frl_is_valid_frontend_page_request()) {
        $frontend_list = frl_textlist_to_array(frl_get_option('excluded_plugins_frontend'));
        $excluded = array_merge($excluded, $frontend_list);
    }
    
    // Capability exclusion
    if ($cap_enabled && !frl_has_access(frl_get_option('excluded_plugins_bycap_cap') ?: FRL_PLUGIN_ACCESS)) {
        $cap_list = frl_textlist_to_array(frl_get_option('excluded_plugins_bycap'));
        $excluded = array_merge($excluded, $cap_list);
    }
    
    if (empty($excluded)) {
        return;
    }
    
    // Remove excluded plugins before regular plugins load
    add_filter('pre_option_active_plugins', function ($pre, $option) use ($excluded) {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $plugins = get_option('active_plugins', []);
        $filtered = array_filter($plugins, function ($plugin) use ($excluded) {
            return !in_array($plugin, $excluded);
        });
        $cache = $filtered;
        return $filtered;
    }, 10, 2);
}, 100);

// Load bootstrap AFTER setting up the filter
$plugin_dir = WP_PLUGIN_DIR . '/' . FRL_MU_NAME . '/';
$bootstrap_file = $plugin_dir . 'includes/bootstrap.php';
if (file_exists($bootstrap_file)) {
    require_once $bootstrap_file;
}
```

---

## 8. Admin UI

### 8.1 Location
Add to existing admin settings page

### 8.2 Field Definitions
```php
[
    'id' => 'excluded_plugins_frontend_enabled',
    'type' => 'checkbox',
    'section' => 'general',
    'default' => false,
    'label' => 'Enable frontend plugin exclusion',
    'description' => 'Exclude plugins from loading on frontend pages.'
],
[
    'id' => 'excluded_plugins_frontend',
    'type' => 'textlist',
    'section' => 'general',
    'default' => '',
    'label' => 'Frontend excluded plugins',
    'description' => 'One plugin path per line (e.g., hello Dolly/hello.php).'
],
[
    'id' => 'excluded_plugins_bycap_enabled',
    'type' => 'checkbox',
    'section' => 'general',
    'default' => false,
    'label' => 'Enable capability-based plugin exclusion',
    'description' => 'Exclude plugins from loading for users without the required capability.'
],
[
    'id' => 'excluded_plugins_bycap_cap',
    'type' => 'text',
    'section' => 'general',
    'default' => FRL_PLUGIN_ACCESS,
    'label' => 'Required capability',
    'description' => 'Capability users need to load excluded plugins.'
],
[
    'id' => 'excluded_plugins_bycap',
    'type' => 'textlist',
    'section' => 'general',
    'default' => '',
    'label' => 'Capability-excluded plugins',
    'description' => 'One plugin path per line (e.g., hello Dolly/hello.php).'
]
```

---

## 9. User Feedback

### 9.1 Dashboard Widget
- Show count of currently excluded plugins
- Show which plugins are excluded

### 9.2 Environment Display
- Show excluded plugins in environment info

---

## 10. Todo List

| # | Task | Status |
|---|------|--------|
| 1 | Add option definitions to config | Pending |
| 2 | Add admin UI fields | Pending |
| 3 | Implement MU loader in `assets/mu/frl-mu-plugin.php` | Pending |
| 4 | Add helper functions for exclusion logic | Pending |
| 5 | Add dashboard widget integration | Pending |
| 6 | Document in ARCHITECTURAL-REVIEW.md | Pending |

---

## 11. Confirmed Behavior (Option B)

| Context | Feature Applies? | Excluded Plugins Load? |
|---------|-----------------|----------------------|
| Frontend HTML page | YES | NO (excluded) |
| Frontend AJAX | YES | NO (excluded) |
| Admin/Editor | NO | YES (load normally) |
| Admin AJAX | NO | YES (load normally) |
| REST API | NO | YES (load normally) |
| MCP | NO | YES (load normally) |
| Cron | NO | YES (load normally) |

**Option B selected:** Frontend AJAX is included in the feature (blocked), consistent with simulating plugin was never active.