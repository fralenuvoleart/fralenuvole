# Plugin Admin UI

Tabbed settings page, dashboard widgets, log manager, tag validator, import/export, and docs browser.

## Loading Flow

`admin/admin.php` is loaded via plugin bootstrap when `frl_is_admin()` returns true:

```
admin/admin.php
  → frl_admin_plugins_loaded() [plugins_loaded/10]
    → frl_load_plugin_ui()
      → frl_is_plugin_context() [CHECK: ?page=fralenuvole or frl_ action]
        → require admin/ui/ui-admin-settings.php
    → frl_autodiscover_admin_actions()
```

`admin/ui/ui-admin-settings.php` loads all UI components:
- Helpers: `functions-admin-ui.php`, `functions-admin-class-helpers-ui.php`, `functions-admin-import-export.php`
- UI classes: `ui-asset-loader.php`, `class-tab-registry.php`, `class-tab-renderer.php`, `class-tab-manager.php`, `class-ui-renderer.php`
- Display components: `class-dashboard.php`, `class-display-environment.php`, `class-display-cache.php`, `class-tag-validator.php`, `class-settings-fields.php`, `class-display-log.php`, `class-display-debug.php`, `class-import-export.php`, `class-display-docs.php`
- Preloads caches: `frl_cache_preload_groups(['staticdata', 'adminui'])`
- Registers tabs: `dashboard` (POSITION_FIRST), `docs` (POSITION_LAST)
- Hides `developer` tab if user lacks `frl_has_access()`

## Key Classes

### Frl_Tab_Manager

`admin/ui/class-tab-manager.php` — singleton facade delegating to `Frl_Tab_Registry` (registration, ordering, sections, fields) and `Frl_Tab_Renderer` (HTML output).

Position constants: `POSITION_FIRST = 0`, `POSITION_DEFAULT = 500`, `POSITION_FORM = 1000`, `POSITION_LAST = PHP_INT_MAX`.

```php
Frl_Tab_Manager::register_tab('my-tab', ['title' => 'My Tab', 'position' => 500]);
Frl_Tab_Manager::hide_tabs_by_capability(['developer'], frl_has_access());
Frl_Tab_Manager::get_active_tab();
Frl_Tab_Manager::render_tab_container_start(true, '', $active_tab);
Frl_Tab_Manager::render_tab_container_end();
```

### Frl_UI_Renderer

`admin/ui/class-ui-renderer.php` — reusable UI components:

```php
frl_ui_render_widget('my-widget', $content, 'Title', 'extra-class', HOUR_IN_SECONDS);
frl_ui_render_table('my-table', $rows, 'my-class', HOUR_IN_SECONDS, $bypass_cache);
frl_ui_render_table_row('Label', 'Value', $is_header, 'row-class');
frl_ui_render_status_row('Label', $status, $text, 'row-class');
frl_ui_render_multi_column_row(['Col1', 'Col2', 'Col3'], $is_header, 'row-class');
frl_ui_render_code_block($code, 'php', $id, $initially_hidden, $in_table_row);
frl_ui_render_toggle_button('Show Code', $target_id, 'button-small');
```

### Frl_Dashboard_Renderer

`admin/ui/class-dashboard-renderer.php`:

```php
Frl_Dashboard_Renderer::render_widget([
    'key' => 'my-widget',
    'title' => 'My Widget',
    'render_callback' => 'my_render_function',
    'render_file' => '/path/to/widget.php',  // Optional lazy-load
    'refresh_button' => true,
    'cache_ttl' => 15 * MINUTE_IN_SECONDS,   // Default: 15 min
    'cache_key_suffix' => 'dynamic_suffix',  // Optional
]);
```

Widgets cached in `admin` cache group. Refresh buttons submit to `admin-post.php` with dynamic nonce pattern `dashboard_widget_{type}_{group}_{key}`.

## Dashboard Widgets

Registered via `wp_dashboard_setup` hook in `admin/admin.php`. Configuration:

```php
$widgets = [
    'editor' => [
        'title' => 'Editor Panel',
        'cap' => 'edit_posts',
        'render_file' => FRL_DIR_PATH . 'admin/widgets/widget-editor.php',
        'render_callback' => 'frl_render_editor_widget',
    ],
    'administrator' => [
        'title' => 'Admin Panel',
        'cap' => FRL_PLUGIN_ACCESS,
        'render_file' => FRL_DIR_PATH . 'admin/widgets/widget-administrator.php',
        'render_callback' => 'frl_render_administrator_widget',
    ],
];
$widgets = apply_filters('frl_add_dashboard_widgets', $widgets);
```

## Extension Points

### Filters

```php
add_filter('frl_add_dashboard_widgets', function($widgets) { ... });
add_filter('frl_admin_dashboard_links', function($links) { ... });
```

### Tab Content Hooks

```php
do_action('frl_{tab_id}_content');
apply_filters('frl_before_{tab_id}_content', '');
apply_filters('frl_after_{tab_id}_content', '');
do_action('frl_before_section_{section_id}_content', $section);
do_action('frl_after_section_{section_id}_content', $section);
```

### Settings Hooks

```php
apply_filters('frl_before_settings_sections', '');
apply_filters(FRL_PREFIX . '_after_settings_content', '');
do_action('frl_settings_updated', $updates);
```

## Asset Files

All in `assets/` (not under `admin/`):

| File | Purpose |
|------|---------|
| `assets/js/admin-ui.js` | Core UI (tabs, toggles) |
| `assets/js/admin-import-export.js` | Import/export AJAX |
| `assets/js/admin-tag-validator.js` | Tag validation AJAX |
| `assets/js/admin-log-manager.js` | Log viewer AJAX |
| `assets/js/admin-docs.js` | Docs viewer AJAX |
| `assets/lib/prism/prism.min.js` | Syntax highlighting |
| `assets/css/admin.css` | Base admin styles |
| `assets/css/admin-ui.css` | Tab UI, widgets |
| `assets/css/admin-dashboard.css` | Dashboard widgets |
| `assets/css/admin-log-manager.css` | Log viewer styles |
| `assets/css/admin-docs.css` | Docs tab typography |
| `assets/lib/prism/prism.min.css` | Syntax highlighting theme |
| `assets/lib/parsedown/Parsedown.php` | Markdown → HTML (docs tab) |