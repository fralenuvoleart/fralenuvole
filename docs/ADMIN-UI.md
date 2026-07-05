# Admin Interface Developer Reference

---

## Directory Structure

```
admin/
├── admin.php                          ← Core hooks, menu, dashboard widgets
├── components/
│   ├── class-dashboard.php            ← Dashboard orchestrator
│   ├── class-display-cache.php        ← Cache statistics display
│   ├── class-display-debug.php        ← Debug configuration display
│   ├── class-display-environment.php  ← Environment information display
│   ├── class-display-log.php          ← Log manager (AJAX + UI)
│   ├── class-import-export.php        ← Import/Export UI
│   ├── class-settings-fields.php      ← Settings form handling
│   └── class-tag-validator.php        ← HTML tag validator
├── helpers/
│   ├── functions-admin-class-helpers.php    ← Core facade functions
│   ├── functions-admin-class-helpers-ui.php ← UI facade functions (frl_tab_*, frl_ui_*)
│   ├── functions-admin.php            ← Batch updates, notices, normalization
│   ├── functions-admin-action-handlers.php  ← Admin-post action handlers
│   ├── functions-admin-import-export.php    ← Import/export logic
│   └── functions-admin-ui.php         ← UI helper functions
├── ui/
│   ├── ui-asset-loader.php            ← CSS/JS registration and loading
│   ├── class-dashboard-renderer.php   ← Dashboard widget renderer
│   ├── class-tab-manager.php          ← Tab management facade
│   ├── class-tab-registry.php         ← Tab registration and ordering
│   ├── class-tab-renderer.php         ← Tab HTML rendering
│   └── class-ui-renderer.php          ← UI components (tables, widgets, status)
├── widgets/
│   ├── widget-administrator.php       ← Admin dashboard widget
│   ├── widget-custom-html.php         ← Custom HTML widgets (1-3)
│   ├── widget-editor.php              ← Editor dashboard widget
│   ├── widget-last-posts.php          ← Recent posts widget
│   └── widget-user-visits.php         ← User visits widget
```

---

## Loading Flow

### 1. Admin Initialization

```
admin/admin.php (loaded via plugin bootstrap)
  → frl_admin_plugins_loaded() [plugins_loaded/10]
    → frl_load_plugin_ui()
      → frl_is_plugin_context() [CHECK: ?page=fralenuvole or frl_ action]
        → require admin/ui/ui-admin-settings.php
```

### 2. Settings Page Loading

```
admin/ui/ui-admin-settings.php
  → Load helpers (functions-admin-ui.php, functions-admin-class-helpers-ui.php)
  → Load UI components (ui-asset-loader.php, class-tab-*.php, class-ui-renderer.php)
  → Load display components (class-dashboard.php, class-display-*.php)
  → Load import/export helpers
  → Preload caches: frl_cache_preload_groups(['staticdata', 'adminui'])
  → Register dashboard tab
  → Register content hooks
```

### 3. Asset Loading

```
ui-asset-loader.php
  → admin_enqueue_scripts (-999): jQuery UI, admin CSS/JS
  → admin_head (1): Critical inline CSS (tab visibility)
  → admin_footer (999): Prism.js, CodeMirror (deferred)
  → wp_print_footer_scripts (1000): Prism init script
```

---

## Key Classes

### Frl_Tab_Manager (Facade)

**File:** `admin/ui/class-tab-manager.php`

```php
// Register a tab
Frl_Tab_Manager::register_tab('my-tab', ['title' => 'My Tab', 'position' => 500]);

// Hide tabs by capability
Frl_Tab_Manager::hide_tabs_by_capability(['developer'], $has_access);

// Get active tab (0-based index)
$active = Frl_Tab_Manager::get_active_tab();

// Render tab container
Frl_Tab_Manager::render_tab_container_start(true, '', $active_tab);
// ... tab content ...
Frl_Tab_Manager::render_tab_container_end();
```

**Delegates to:**
- `Frl_Tab_Registry` - Registration, ordering, sections, fields
- `Frl_Tab_Renderer` - HTML output

### Frl_UI_Renderer

**File:** `admin/ui/class-ui-renderer.php`

```php
// Render widget
frl_ui_render_widget('my-widget', $content, 'Title', 'extra-class', HOUR_IN_SECONDS);

// Render table (div-based)
frl_ui_render_table('my-table', $rows, 'my-class', HOUR_IN_SECONDS, $bypass_cache);

// Render table row
frl_ui_render_table_row('Label', 'Value', $is_header, 'row-class');

// Render status row
frl_ui_render_status_row('Label', $status, $text, 'row-class');

// Render multi-column row
frl_ui_render_multi_column_row(['Col1', 'Col2', 'Col3'], $is_header, 'row-class');

// Render code block
frl_ui_render_code_block($code, 'php', $id, $initially_hidden, $in_table_row);

// Render toggle button
frl_ui_render_toggle_button('Show Code', $target_id, 'button-small');
```

### Frl_Dashboard_Renderer

**File:** `admin/ui/class-dashboard-renderer.php`

```php
// Render dashboard widget (with caching)
Frl_Dashboard_Renderer::render_widget([
    'key' => 'my-widget',
    'title' => 'My Widget',
    'render_callback' => 'my_render_function',
    'render_file' => '/path/to/widget.php',  // Optional lazy-load
    'refresh_button' => true,                 // Optional refresh button
    'cache_ttl' => 15 * MINUTE_IN_SECONDS,   // Optional custom TTL
]);
```

---

## Facade Functions

### Tab Functions (`functions-admin-class-helpers-ui.php`)

| Function | Description |
|----------|-------------|
| `frl_tab_register_tab($id, $args)` | Register a tab |
| `frl_tab_hide_tabs_by_capability($tabs, $has_access)` | Hide tabs by capability |
| `frl_tab_get_active_tab()` | Get active tab index |
| `frl_tab_save_active_tab($index)` | Save active tab to transient |
| `frl_tab_get_sorted_tabs()` | Get all tabs sorted by position |
| `frl_tab_get_validation_rules($tab_id)` | Get validation rules for tab |
| `frl_tab_render_tab_container_start($vertical, $class, $active)` | Render container start |
| `frl_tab_render_tabs_from_sections($sections)` | Render tabs from sections |
| `frl_tab_render_all_custom_tabs()` | Render all custom tabs |
| `frl_tab_render_tab_container_end()` | Render container end |

### UI Functions (`functions-admin-class-helpers-ui.php`)

| Function | Description |
|----------|-------------|
| `frl_ui_render_widget($id, $content, $title, $class, $ttl, $bypass_cache, $group)` | Render cached widget |
| `frl_ui_render_table($id, $content, $class, $ttl, $bypass_cache, $group)` | Render cached table |
| `frl_ui_render_table_row($name, $value, $is_header, $class)` | Render table row |
| `frl_ui_render_metadata_row($label, $value, $secondary, $break, $class)` | Render metadata row |
| `frl_ui_render_status_row($label, $status, $text, $class)` | Render status row |
| `frl_ui_render_multi_column_header($columns, $class)` | Render multi-column header |
| `frl_ui_render_multi_column_row($columns, $is_header, $class)` | Render multi-column row |
| `frl_ui_render_status_dot($status, $text, $inside)` | Render status dot |
| `frl_ui_render_status_dot_boolean($value, $text, $inside)` | Render boolean status dot |
| `frl_ui_render_metadata_field($label, $value, $class)` | Render metadata field |
| `frl_ui_render_items_list($items, $label, $class)` | Render items list |
| `frl_ui_render_code_block($code, $lang, $id, $hidden, $in_row)` | Render code block |
| `frl_ui_render_toggle_button($text, $target, $class)` | Render toggle button |
| `frl_ui_render_validation_message($msg, $type)` | Render validation message |
| `frl_ui_render_validation_messages($validation, $id, $hidden, $in_row)` | Render validation messages |
| `frl_ui_render_flex_layout($items, $columns, $class)` | Render flexbox layout |
| `frl_ui_render_plugin_settings_header()` | Render settings page header |
| `frl_ui_render_field($field, $value)` | Render form field |
| `frl_ui_render_formatting_field($field, $type)` | Render formatting field |

---

## Dashboard Widgets

### Registration (`admin/admin.php` → `frl_custom_dashboard_widgets()`)

Widgets are registered via the `wp_dashboard_setup` hook. Configuration:

```php
$widgets = [
    'editor' => [
        'title' => __('Editor Panel'),
        'cap' => 'edit_posts',
        'render_file' => FRL_DIR_PATH . 'admin/widgets/widget-editor.php',
        'render_callback' => 'frl_render_editor_widget',
    ],
    'administrator' => [
        'title' => __('Admin Panel'),
        'cap' => 'manage_options',
        'render_file' => FRL_DIR_PATH . 'admin/widgets/widget-administrator.php',
        'render_callback' => 'frl_render_administrator_widget',
    ],
    'last_posts' => [
        'title' => __('Last updates'),
        'cap' => 'edit_posts',
        'render_file' => FRL_DIR_PATH . 'admin/widgets/widget-last-posts.php',
        'render_callback' => 'frl_render_last_posts_widget',
        'refresh_button' => true,
    ],
    // ... more widgets
];

// Filter to add custom widgets
$widgets = apply_filters('frl_add_dashboard_widgets', $widgets);
```

### Custom Widget Filter

```php
add_filter('frl_add_dashboard_widgets', function($widgets) {
    $widgets['my_widget'] = [
        'title' => 'My Widget',
        'cap' => 'manage_options',
        'render_callback' => 'my_render_function',
        'cache_ttl' => 30 * MINUTE_IN_SECONDS,
    ];
    return $widgets;
});
```

---

## Admin Links Filter

The administrator widget links are filterable:

```php
add_filter('frl_admin_dashboard_links', function($links) {
    $links['My Section'] = [
        ['url' => 'https://example.com', 'text' => 'My Link'],
    ];
    return $links;
});
```

---

## Asset Loading

### JavaScript Files

| File | Purpose | Loading |
|------|---------|---------|
| `admin-ui.js` | Core UI (tabs, toggles) | Footer, deferred |
| `admin-import-export.js` | Import/export AJAX | Footer, deferred |
| `admin-tag-validator.js` | Tag validation AJAX | Footer, deferred |
| `admin-log-manager.js` | Log viewer AJAX | Footer, deferred |
| `admin-bulk-resave.js` | Bulk post re-save AJAX | Footer, deferred |
| `admin-avatar.js` | Custom avatar upload | Footer, deferred |
| `prism.min.js` | Syntax highlighting | Footer, deferred |
| `prism-markup.min.js` | HTML syntax | Footer, deferred |

### CSS Files

| File | Purpose |
|------|---------|
| `admin.css` | Base admin styles |
| `admin-ui.css` | Tab UI, widgets |
| `admin-dashboard.css` | Dashboard widgets |
| `admin-log-manager.css` | Log viewer styles |
| `prism.min.css` | Syntax highlighting theme |

### Localized Scripts

```javascript
// frlTagValidator
{ adminUrl, pluginPage, nonce }

// logManagerData
{ ajaxUrl, nonce }

// frlImportExport
{ ajaxUrl, exportUrl, translationsExportUrl, importNonce, translationNonce, strings }
```

---

## Action Handlers

### Admin-Post Actions

Actions are auto-discovered by `frl_autodiscover_admin_actions()`. Functions prefixed with `frl_post_` are automatically registered:

```php
// Auto-registered as admin_post_frl_post_clear_cache
function frl_post_clear_cache() { ... }

// Auto-registered as wp_ajax_frl_post_ajax_import_settings
function frl_post_ajax_import_settings() { ... }
```

### Dashboard Widget Actions

Widget refresh buttons submit to `frl_post_action_dashboard_widgets`:

```php
// Nonce pattern: "dashboard_widget_{type}_{group}_{key}"
frl_verify_dynamic_nonce(
    'frl_dashboard_widget_{type}_{group}_{key}',
    ['type' => $action_type, 'group' => $widget_group, 'key' => $widget_key]
);
```

---

## Caching Strategy

| Component | Cache Group | Cache Key | TTL |
|-----------|-------------|-----------|-----|
| Tab navigation | `adminui` | `ui_tabs_{access_suffix}` | Persistent |
| Widget HTML | `admin` | `widget_{id}` | 15 min |
| Widget table | `adminui` | `ui_table_{id}` | 1 hour |
| Widget content | `adminui` | `ui_widget_{id}` | 1 hour |
| Settings sections | `adminui` | `settings_sections` | Persistent |
| Menu order | `adminui` | `menuorder_uid{uid}_{hash}` | Persistent |
| Transients list | `staticdata` | `all_transients` | Persistent |

**Note:** Tables inside cached widgets use `$bypass_cache = true` to avoid double caching.

---

## Hook Reference

### Tab Content Hooks

```php
// Before/after tab content
do_action('frl_{tab_id}_content');
apply_filters('frl_before_{tab_id}_content', '');
apply_filters('frl_after_{tab_id}_content', '');

// Section content (inside form tabs)
do_action('frl_before_section_{section_id}_content', $section);
do_action('frl_after_section_{section_id}_content', $section);
```

### Dashboard Widget Hooks

```php
// Add custom widgets
add_filter('frl_add_dashboard_widgets', function($widgets) { ... });
```

### Settings Hooks

```php
// Before/after settings form
apply_filters('frl_before_settings_sections', '');
apply_filters(FRL_PREFIX . '_after_settings_content', '');

// After settings save
do_action('frl_settings_updated', $updates);
```

---

