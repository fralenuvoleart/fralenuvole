<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fralenuvole
 * class-ui-renderer.php - UI Rendering Utility Class
 *
 * This class consolidates UI rendering functionality for widgets, tables, and
 * other UI elements across the admin interface. It ensures compatibility with
 * existing JavaScript functionality while providing a unified approach to rendering.
 */

/**
 * Available Public Static Methods:
 *
 * Core Component Rendering:
 * - render_widget
 * - render_flex_layout
 * - render_page_header
 * - render_section_header
 * - render_header_with_action
 *
 * Table Rendering:
 * - render_table
 * - render_table_row
 * - render_status_row
 * - render_metadata_row
 * - render_multi_column_row
 * - render_multi_column_header
 *
 * Status & Visual Indicators:
 * - render_status_dot
 * - render_status_dot_boolean
 * - render_metadata_field
 * - render_items_list
 *
 * Code Display:
 * - render_code_block
 *
 * Interactive Elements:
 * - render_toggle_button
 *
 * Validation & Messaging:
 * - render_validation_message
 * - render_validation_messages
 *
 * Form Elements:
 * - render_settings_section
 * - render_form
 * - render_field
 */

/**
 * UI Renderer Class
 *
 * Provides static methods for rendering various UI elements consistently across the plugin.
 * Maintains compatibility with existing widget DOM structures, IDs, and class names
 * to ensure JavaScript functionality continues to work.
 */
class Frl_UI_Renderer
{
    // Add property to track IDs used during a single request
    private static $rendered_ids_this_request = [];

    // Core Component Rendering
    // --------------------------------------------------

    /**
     * Renders the main plugin settings page header, including its standard actions.
     *
     * @return string HTML for the plugin settings page header.
     */
    public static function render_plugin_settings_header()
    {
        return frl_cache_remember(
            'adminui',
            'header',
            function () {
                // Fetch identity and presentational data internally
                $logo_url = FRL_DIR_URL . 'assets/images/fralenuvole.svg';
                $logo_alt = frl_name();
                $page_title = frl_name('Plugin');

                $page_description = '';
                if (function_exists('get_plugin_data')) {
                    $plugin_data = get_plugin_data(FRL_DIR_PATH . FRL_PLUGIN_FILE, false);
                    $page_description = isset($plugin_data['Description']) ? $plugin_data['Description'] : '';
                }

                // Generate standard side content (e.g., buttons) internally
                $side_content_html = '';
                $side_content_html = frl_render_action_button('clear_dashboard', 'button-small', 'Refresh Dashboard');

                $output = '<div class="frl-header">';
                $output .= '<div class="frl-header-logo">';
                $output .= '<img src="' . esc_url($logo_url) . '" alt="' . esc_attr($logo_alt) . '" />';
                $output .= '</div>';
                $output .= '<div class="frl-header-text">';
                $output .= '<h1> ' . esc_html($page_title) . '</h1>';
                $output .= '<p class="frl-header-description">' . esc_html($page_description) . '</p>';
                $output .= '</div>';
                $output .= '<div class="frl-header-side">';
                $output .= $side_content_html;
                $output .= '</div>';
                $output .= '</div>';

                return $output;
            },
            WEEK_IN_SECONDS
        );
    }

    /**
     * Renders a generic widget with title and content
     */
    public static function render_widget(
        $id,
        $content,
        $title = '',
        $class = '',
        $ttl = HOUR_IN_SECONDS,
        $bypass_cache = false, // Add bypass cache flag
        $group = 'adminui' // Add cache group
    ) {
        // Sanitize the ID first using the helper method
        $sanitized_id = self::_sanitize_table_id($id);

        if ($bypass_cache) {
            // Bypass cache: Generate HTML directly
            $widget_class = 'frl-widget ' . $sanitized_id;
            if (!empty($class) && $class !== $sanitized_id) {
                $widget_class .= ' ' . $class;
            }

            $widget_html = "<div id='{$sanitized_id}' class='{$widget_class}'>";
            if (!empty($title)) {
                $widget_html .= "<h3 class='frl-widget-title'>" . $title . "</h3>";
            }
            $widget_html .= "<div class='frl-widget-content'>{$content}</div>";
            $widget_html .= "</div>";
        } else {
            // Use cache: Remember or retrieve the generated HTML
            $cache_key = 'ui_widget_' . $sanitized_id;
            $widget_html = frl_cache_remember(
                $group,
                $cache_key,
                function () use ($sanitized_id, $content, $title, $class) {
                    // Construct the full widget HTML inside the cache callback
                    $widget_class = 'frl-widget ' . $sanitized_id;
                    if (!empty($class) && $class !== $sanitized_id) {
                        $widget_class .= ' ' . $class;
                    }

                    $output = "<div id='{$sanitized_id}' class='{$widget_class}'>";
                    if (!empty($title)) {
                        $output .= "<h3 class='frl-widget-title'>" . $title . "</h3>";
                    }
                    $output .= "<div class='frl-widget-content'>{$content}</div>";
                    $output .= "</div>"; // Close frl-widget div
                    return $output;
                },
                $ttl
            ); // Use the passed or default TTL
        }

        // Return the generated or cached HTML
        return $widget_html;
    }

    /**
     * Renders a flexible multi-column layout
     *
     * @param array $content_items Array of content strings for each column
     * @param int $columns Number of columns (default 2) - adds frl-columns-N class
     * @param string $class Additional CSS class for the container
     * @return string HTML for the multi-column layout
     */
    public static function render_flex_layout($content_items, $columns = 2, $class = '')
    {
        // Build container classes
        $container_class = 'frl-flex-container frl-columns-' . intval($columns);
        if (!empty($class)) {
            $container_class .= ' ' . $class;
        }

        // Start container
        $output = '<div class="' . esc_attr($container_class) . '">';

        // Add each content item as a flex column
        foreach ((array)$content_items as $content) {
            $output .= '<div class="frl-flex-item">' . $content . '</div>';
        }

        $output .= '</div>';

        return $output;
    }

    /**
     * Renders a settings page header with title and description
     */
    public static function render_page_header($title, $description = '')
    {
        $output = "<div class='frl-header'>";
        $output .= "<h1>" . esc_html($title) . "</h1>";

        if (!empty($description)) {
            $output .= "<p class='frl-header-description'>" . esc_html($description) . "</p>";
        }

        return $output . "</div>";
    }

    /**
     * Renders a simple header with title and action button
     *
     * @param string $title The header title
     * @param string $button HTML for an action button or other control (not escaped)
     * @param string $class Additional CSS class for the header
     * @return string HTML for the simple header with button
     */
    public static function render_header_with_action($title, $button, $class = '')
    {
        $class_attr = !empty($class) ? ' ' . esc_attr($class) : '';

        return "<div class='frl-simple-header{$class_attr}'>
            <div class='frl-simple-header-content'>
                <span class='frl-simple-header-title'>" . $title . "</span>
                <span class='frl-simple-header-action'>{$button}</span>
            </div>
        </div>";
    }

    // Table Rendering
    // --------------------------------------------------

    /**
     * Sanitizes and validates an ID intended for table/widget rendering.
     * Also checks for duplicate usage within the same request and triggers a warning.
     *
     * @param mixed $id The proposed ID.
     * @return string A sanitized, safe ID string. Returns a fallback ID if input is invalid.
     */
    private static function _sanitize_table_id($id)
    {
        $original_id = is_scalar($id) ? (string) $id : gettype($id); // Get original for logging

        if (!is_string($id) || empty(trim($id))) {
            // Handle invalid type or empty ID
            trigger_error(
                'Frl_UI_Renderer render_table/widget() called with invalid or empty ID. Original value type: ' . gettype($id),
                E_USER_WARNING
            );
            // Return a somewhat unique but clearly invalid fallback ID
            return 'invalid_id_' . substr(md5(uniqid()), 0, 8);
        }

        // Sanitize the valid string ID using sanitize_key
        $sanitized_id = sanitize_key($id);

        if ($sanitized_id !== $id) {
            // Warn if sanitization changed the ID, indicating potentially unsafe characters were used
            trigger_error(
                'Frl_UI_Renderer render_table/widget() called with an ID containing invalid characters. Original: "' . $original_id . '", Sanitized: "' . $sanitized_id . '"',
                E_USER_WARNING
            );
            // Use the sanitized version
        }

        // --- Duplicate ID Check for this request ---
        if (isset(self::$rendered_ids_this_request[$sanitized_id])) {
            // Duplicate detected within this request lifecycle
            trigger_error(
                'Duplicate ID detected during render: "' . $sanitized_id . ". Ensure unique IDs are provided for distinct elements on the same page.",
                E_USER_WARNING
            );
            // We still return the ID, allowing the duplicate rendering but warning the developer
        } else {
            // Record this ID as used for this request
            self::$rendered_ids_this_request[$sanitized_id] = true;
        }
        // --- End Duplicate Check ---

        return $sanitized_id; // Return the clean, sanitized ID
    }

    /**
     * Renders a table with provided content
     */
    public static function render_table(
        $id,             // Now required
        $content,         // Required
        $class = '',     // Optional
        $ttl = HOUR_IN_SECONDS, // Optional TTL
        $bypass_cache = false, // Optional bypass cache
        $group = 'adminui' // Optional cache group
    ) {
        // Sanitize the ID first using the helper method
        $sanitized_id = self::_sanitize_table_id($id);

        if ($bypass_cache) {
            // Bypass cache: Generate HTML directly
            $table_class = 'frl-table widget-table';
            if (!empty($class)) {
                $table_class .= ' ' . $class;
            }
            $id_attr = ' id="' . esc_attr($sanitized_id) . '"';
            $tag = 'div'; // Assuming div-based tables
            $table_html = "<{$tag} class='{$table_class}'{$id_attr}>{$content}</{$tag}>";
        } else {
            // Use cache: Remember or retrieve the generated HTML
            $cache_key = 'ui_table_' . $sanitized_id;
            // Pass the HTML generation logic as a closure directly to the cache function
            $table_html = frl_cache_remember(
                $group,
                $cache_key,
                function () use ($sanitized_id, $content, $class) {
                    // Construct the full table HTML inside the cache callback
                    $table_class = 'frl-table widget-table';
                    if (!empty($class)) {
                        $table_class .= ' ' . $class;
                    }
                    $id_attr = ' id="' . esc_attr($sanitized_id) . '"';
                    $tag = 'div'; // Assuming div-based tables
                    $output = "<{$tag} class='{$table_class}'{$id_attr}>{$content}</{$tag}>";
                    return $output;
                },
                $ttl
            );
        }

        // Return the generated or cached HTML
        return $table_html;
    }

    /**
     * Render a table row using divs with name and value
     */
    public static function render_table_row(
        $name,
        $value = '',
        $is_header = false,
        $row_class = ''
    ) {
        $class = $is_header ? 'widget-table-header' : '';
        if (!empty($row_class)) {
            $class .= ' ' . $row_class;
        }

        return "<div class='widget-table-row {$class}'>
                <div class='widget-table-cell-name'>" . $name . "</div>
                <div class='widget-table-cell-value'>{$value}</div>
            </div>";
    }

    /**
     * Renders a section header title
     *
     * @param string $title The section title
     * @param string $class Additional CSS class for the row
     * @return string HTML for the section header row
     */
    public static function render_section_header($title, $class = '')
    {
        $section_class = 'section-header';
        if (!empty($class)) {
            $section_class .= ' ' . $class;
        }

        return "<div class='frl-{$section_class}'>
                <h3>" . $title . "</h3>
            </div>";
    }

    /**
     * Renders a status row with a colored status indicator using divs
     *
     * @param string $label Row label
     * @param bool|string $status Boolean status or status class ('enabled', 'disabled', 'warning')
     * @param string $text Optional text to display with the status dot
     * @param string $class Additional CSS class for the row
     * @return string HTML for the status row
     */
    public static function render_status_row($label, $status, $text = null, $class = '')
    {
        // Convert boolean status to string status class
        $status_class = is_bool($status) ? ($status ? 'enabled' : 'disabled') : $status;

        // Use default text if none provided
        $status_text = $text ?? ($status_class === 'enabled' ? 'enabled' : 'disabled');

        // Create status dot
        $value = self::render_status_dot($status_class, $status_text, true);

        return self::render_table_row($label, $value, false, $class);
    }

    /**
     * Renders a metadata row with label and formatted value using divs
     *
     * @param string $label Row label
     * @param string $value Main value
     * @param string $secondary_value Optional secondary value (e.g. timestamp)
     * @param bool $add_line_break Whether to add a line break between value and secondary value
     * @param string $class Additional CSS class for the row
     * @return string HTML for the metadata row
     */
    public static function render_metadata_row($label, $value, $secondary_value = '', $add_line_break = true, $class = '')
    {
        // Format value with optional secondary value
        $formatted_value = $value;
        if (!empty($secondary_value)) {
            $formatted_value .= $add_line_break ? '<br>(' . $secondary_value . ')' : ' (' . $secondary_value . ')';
        }

        return self::render_table_row($label, $formatted_value, false, $class);
    }

    /**
     * Renders a div-based table row with multiple columns
     *
     * @param array $columns Array of column values (first column is typically escaped for safety)
     * @param bool $is_header Whether this is a header row
     * @param string $row_class Additional CSS class for the row
     * @return string HTML for the multi-column row
     */
    public static function render_multi_column_row($columns, $is_header = false, $row_class = '')
    {
        if (!frl_is_array_not_empty($columns)) {
            return '';
        }

        $class = $is_header ? 'widget-table-header' : '';
        if (!empty($row_class)) {
            $class .= ' ' . $row_class;
        }

        $output = "<div class='widget-table-row {$class}'>";
        foreach ($columns as $i => $content) {
            // First column gets the 'name' class, others get numbered value classes
            $cell_class = ($i === 0) ? 'widget-table-cell-name' : "widget-table-cell-value widget-table-cell-value-{$i}";

            $cell_content = $content;

            // Directly output cell content without KSES sanitization
            $output .= "<div class='{$cell_class}'>{$cell_content}</div>";
        }
        $output .= "</div>";
        return $output;
    }

    /**
     * Renders a multi-column header row for div-based tables
     *
     * @param array $columns Array of column headers
     * @param string $class Additional CSS class for the row
     * @return string HTML for the multi-column header row
     */
    public static function render_multi_column_header($columns, $class = '')
    {
        $row_class = 'section-header';
        if (!empty($class)) {
            $row_class .= ' ' . $class;
        }

        return self::render_multi_column_row($columns, true, $row_class);
    }

    // Status & Visual Indicators
    // --------------------------------------------------

    /**
     * Generate a status dot HTML with optional text
     */
    public static function render_status_dot($status, $text = '', $text_inside = false)
    {
        $status_class = is_bool($status) ? ($status ? 'enabled' : 'disabled') : $status;

        if ($text_inside) {
            return '<span class="status-dot ' . $status_class . '">' . $text . '</span>';
        }

        $output = '<span class="status-dot ' . $status_class . '"></span>';
        if (!empty($text)) $output .= ' ' . esc_html($text);
        return $output;
    }

    /**
     * Creates a status dot for boolean values with standard formatting
     *
     * @param mixed $value Boolean value to represent (true/false, 1/0, '1'/'0')
     * @param string|null $custom_text Optional custom text to display (defaults to enabled/disabled)
     * @param bool $text_inside Whether to show text inside the dot
     * @return string HTML for the status dot
     */
    public static function render_status_dot_boolean($value, $custom_text = null, $text_inside = true)
    {
        // Convert value to status class
        $status_class = ($value === true || $value === '1' || $value === 1) ? 'enabled' : 'disabled';

        // Use status class as text if no custom text provided
        $text = $custom_text ?? $status_class;

        // Create the status dot using the existing method
        return self::render_status_dot($status_class, $text, $text_inside);
    }

    /**
     * Renders a small metadata field with a label and value
     */
    public static function render_metadata_field($label, $value, $class = '')
    {
        if (empty($value)) return '';

        $class_attr = !empty($class) ? esc_attr($class) : 'metadata-field';

        return '<div class="' . $class_attr . '">
            <div class="metadata-content">' . esc_html($label) . ': ' . esc_html($value) . '</div>
        </div>';
    }

    /**
     * Renders a list of items with an optional label
     */
    public static function render_items_list($items, $label = '', $class = '')
    {
        if (empty($items)) return '';

        $class_attr = !empty($class) ? esc_attr($class) : 'items-list';

        $html = '<div class="' . $class_attr . '"><div class="items-content">';
        if (!empty($label)) {
            $html .= '<span>' . esc_html($label) . ':</span><br>';
        }

        foreach ((array)$items as $item) {
            $html .= esc_html($item) . '<br>';
        }

        return $html . '</div></div>';
    }

    // Code Display
    // --------------------------------------------------

    /**
     * Renders a code block with syntax highlighting
     *
     * @param mixed $code String or array of code to display
     * @param string $language Language for syntax highlighting
     * @param string $id Optional ID for the container
     * @param bool $initially_hidden Whether to start with code hidden
     * @param bool $in_table_row Whether to wrap in table row structure
     * @return string HTML with formatted code
     */
    public static function render_code_block($code, $language = 'js', $id = '', $initially_hidden = true, $in_table_row = false)
    {
        // Process array inputs consistently
        if (is_array($code)) {
            if (isset($code['examples'])) {
                if (isset($code['language'])) $language = $code['language'];
                $code = $code['examples'];
            }
            $code = implode("\n\n", $code);
        }

        if (empty($code)) return '';

        // Always set ID if empty and we need it for a toggle
        if (empty($id) && ($in_table_row || !$initially_hidden)) {
            $id = 'code-example-' . md5(microtime() . rand(1000, 9999));
        }

        $id_attr = !empty($id) ? ' id="' . esc_attr($id) . '"' : '';
        $display_style = $initially_hidden ? ' style="display:none;"' : '';

        $code_html = "<div class='widget-code'>
            <pre><code class='language-{$language}'>" . htmlspecialchars($code) . "</code></pre>
        </div>";

        // Standard code block (not in table row)
        if (!$in_table_row) {
            return "<div{$id_attr} class='widget-code'{$display_style}>
                <pre><code class='language-{$language}'>" . htmlspecialchars($code) . "</code></pre>
            </div>";
        }

        // For table row structure, we need to wrap differently
        $content_html = '<div id="' . esc_attr($id) . '" class="tag-example-content"' . $display_style . '>' . $code_html . '</div>';

        return '<div class="widget-table-row widget-table-example-row">
            <div class="widget-table-cell-example">' . $content_html . '</div>
        </div>';
    }

    // Interactive Elements
    // --------------------------------------------------

    /**
     * Renders a toggle button that shows/hides content
     */
    public static function render_toggle_button($button_text, $target_id, $button_class = '')
    {
        $class = 'button toggle-example';
        if (!empty($button_class)) $class .= ' ' . $button_class;

        return sprintf(
            '<button type="button" class="%1$s" data-target="%2$s">%3$s</button>',
            esc_attr($class),
            esc_attr($target_id),
            esc_html($button_text)
        );
    }

    // Validation & Messaging
    // --------------------------------------------------

    /**
     * Renders a validation message with appropriate styling
     */
    public static function render_validation_message($message, $type = 'info')
    {
        $class_map = [
            'error' => 'frl-error-message',
            'warning' => 'frl-warning-message',
            'info' => 'frl-info-message'
        ];

        $class = isset($class_map[$type]) ? $class_map[$type] : $class_map['info'];

        return '<div class="' . $class . '">' . esc_html($message) . '</div>';
    }

    /**
     * Render validation messages (errors and warnings)
     */
    public static function render_validation_messages($validation, $id = '', $initially_hidden = false, $in_table_row = false)
    {
        if (empty($validation['messages'])) return '';

        $status = isset($validation['status']) ? $validation['status'] : 'info';
        $list_class = 'validation-warnings';
        $heading = 'Messages:';

        if ($status === 'error') {
            $list_class = 'validation-errors';
            $heading = 'Errors:';
        } elseif ($status === 'warning') {
            $list_class = 'validation-warnings';
            $heading = 'Warnings:';
        }

        // Create validation messages HTML
        $content = '<div class="validation-messages">';
        $content .= '<h4>' . $heading . '</h4>';
        $content .= '<ul class="' . $list_class . '">';

        foreach ($validation['messages'] as $message) {
            $content .= '<li>' . esc_html($message) . '</li>';
        }

        $content .= '</ul></div>';

        // Handle display style and ID
        $id_attr = !empty($id) ? ' id="' . esc_attr($id) . '"' : '';
        $display_style = $initially_hidden ? ' style="display:none;"' : '';

        // For table row structure, we need to wrap differently
        if ($in_table_row) {
            return '<div class="widget-table-row validation-messages">
                <div' . $id_attr . ' class="validation-content"' . $display_style . '>' . $content . '</div>
            </div>';
        }

        // Standard format (not in table row)
        return '<div' . $id_attr . ' class="validation-messages"' . $display_style . '>' . $content . '</div>';
    }

    // Form Elements
    // --------------------------------------------------

    /**
     * Renders a settings form section with consistent styling
     */
    public static function render_settings_section($section_id, $title, $content, $class = '')
    {
        // Removed caching - generate structure directly
        $section_class = 'frl-settings-section';
        if (!empty($class)) {
            $section_class .= ' ' . $class;
        }

        // Build the opening tag
        $output = "<div id='tabs-" . esc_attr($section_id) . "' class='" . esc_attr($section_class) . "'>";

        // Add the title if present
        if (!empty($title)) {
            $output .= "<h2>" . esc_html($title) . "</h2>";
        }

        // Allow action hooks to insert content before the section
        ob_start();
        do_action(
            FRL_PREFIX . "_before_section_{$section_id}_content",
            ['id' => $section_id, 'title' => $title]
        );
        $before_content = ob_get_clean();

        // Allow action hooks to insert content after the section
        ob_start();
        do_action(
            FRL_PREFIX . "_after_section_{$section_id}_content",
            ['id' => $section_id, 'title' => $title]
        );
        $after_content = ob_get_clean();

        // Append the dynamic content and hooks
        $output .= $before_content . $content . $after_content;

        // Close the div
        $output .= "</div>";

        return $output;
    }

    /**
     * Renders a form with properly set up action URL and fields
     */
    public static function render_form($action, $content, $nonce_action = '', $nonce_name = '_wpnonce', $hidden_fields = [])
    {
        $output = "<form method='POST' action='" . esc_url(admin_url('admin-post.php')) . "'>";
        $output .= "<input type='hidden' name='action' value='" . esc_attr($action) . "'>";

        if (!empty($nonce_action)) {
            $output .= wp_nonce_field($nonce_action, $nonce_name, true, false);
        }

        foreach ($hidden_fields as $name => $value) {
            $output .= "<input type='hidden' name='" . esc_attr($name) . "' value='" . esc_attr($value) . "'>";
        }

        $output .= $content;

        if (!str_contains($content, 'type="submit"') && !str_contains($content, "type='submit'")) {
            $output .= get_submit_button();
        }

        $output .= "</form>";
        return $output;
    }

    /**
     * Renders a field for settings forms
     */
    public static function render_field($field, $value = '')
    {
        if (!isset($field['type'], $field['id'])) {
            return '';
        }

        $field_id = $field['id'];
        $placeholder = $field['placeholder'] ?? '';
        $output = '';

        $disabled = $field['disabled'] ?? '';
        $restricted_class = $field['restricted_class'] ?? '';

        // New parameters for description and restriction handling, expected to be in $field array
        $description_html = $field['description_html'] ?? '';
        $is_restricted_field = !empty($field['is_restricted_field']);
        $current_user_can_edit = !empty($field['current_user_can_edit']);
        $original_field_key = $field['original_field_key'] ?? '';

        switch ($field['type']) {
            case 'checkbox':
                $output = sprintf(
                    '<input name="%1$s" id="%1$s" type="%2$s" value="1" %3$s %4$s class="%5$s"/>',
                    esc_attr($field_id),
                    esc_attr($field['type']),
                    checked(1, $value, false),
                    $disabled,
                    esc_attr($restricted_class)
                );
                break;

            case 'number':
                $min = isset($field['min']) ? 'min="' . esc_attr($field['min']) . '"' : '';
                $max = isset($field['max']) ? 'max="' . esc_attr($field['max']) . '"' : '';
                $step = isset($field['step']) ? 'step="' . esc_attr($field['step']) . '"' : '';
                $size = $field['size'] ?? 5;
                $output = sprintf(
                    '<input name="%1$s" id="%1$s" type="%2$s" value="%3$s" class="regular-number %9$s" size="%4$s" placeholder="%5$s" %6$s %7$s %8$s/>',
                    esc_attr($field_id),
                    esc_attr($field['type']),
                    esc_attr($value),
                    esc_attr($size),
                    esc_attr($placeholder),
                    $min,
                    $max,
                    $step,
                    esc_attr($restricted_class)
                );
                break;

            case 'radio':
            case 'select':
                if (!isset($field['options'])) break;

                if ($field['type'] === 'select') {
                    $output = sprintf(
                        '<select name="%1$s" id="%1$s" class="regular-text %3$s" %2$s>',
                        esc_attr($field_id),
                        $disabled,
                        esc_attr($restricted_class)
                    );
                }

                foreach ((array) $field['options'] as $option_key => $option_value) {
                    if ($field['type'] === 'select') {
                        $output .= sprintf(
                            '<option value="%1$s" %2$s>%3$s</option>',
                            esc_attr($option_key),
                            selected($option_key, $value, false),
                            esc_html($option_value)
                        );
                    } else { // Radio
                        $output .= sprintf(
                            '<input name="%1$s" id="%1$s_%2$s" type="%3$s" value="%4$s" %5$s %6$s class="%7$s"/> %8$s<br>',
                            esc_attr($field_id),
                            esc_attr($option_key),
                            esc_attr($field['type']),
                            esc_attr($option_key),
                            checked($option_key, $value, false),
                            $disabled,
                            esc_attr($restricted_class),
                            esc_html($option_value)
                        );
                    }
                }

                if ($field['type'] === 'select') $output .= '</select>';
                break;

            case 'textlist':
            case 'textarea':
                $rows = $field['rows'] ?? 5;
                $output = sprintf(
                    '<textarea name="%1$s" id="%1$s" rows="%2$s" cols="55" placeholder="%4$s" class="%5$s" %6$s>%3$s</textarea>',
                    esc_attr($field_id),
                    esc_attr($rows),
                    esc_textarea($value),
                    esc_attr($placeholder),
                    esc_attr($restricted_class),
                    $disabled
                );
                break;

            case 'html':
                $rows = $field['rows'] ?? 15;
                $output = sprintf(
                    '<textarea name="%1$s" id="%1$s" rows="%2$s" cols="55" class="large-text code %3$s" %4$s>%5$s</textarea>',
                    esc_attr($field_id),
                    esc_attr($rows),
                    esc_attr($restricted_class),
                    $disabled,
                    $value
                );
                break;

            case 'info':
                $output = '<div class="frl-info-value ' . esc_attr($restricted_class) . '">' . $value . '</div>';
                break;
            case 'custom':
                $output = '';
                break;

            default:
                $size = $field['size'] ?? 40;
                $output = sprintf(
                    '<input name="%1$s" id="%1$s" type="%2$s" value="%3$s" class="regular-text %6$s" size="%4$s" placeholder="%5$s" %7$s/>',
                    esc_attr($field_id),
                    esc_attr($field['type']),
                    esc_attr($value),
                    esc_attr($size),
                    esc_attr($placeholder),
                    esc_attr($restricted_class),
                    $disabled
                );
        }

        // Append description if provided
        if (!empty($description_html)) {
            $output .= sprintf('<p class="description">%s</p>', wp_kses_post($description_html));
        }

        // Append restriction message and hidden input if field is restricted and user cannot edit
        if ($is_restricted_field && !$current_user_can_edit) {
            $output .= '<p class="frl-restricted-message">' . esc_html__('Critical field restricted to plugin admin', FRL_PREFIX) . '</p>';
            if (!empty($original_field_key)) { // Ensure key is present before creating hidden input
                $output .= '<input type="hidden" name="' . esc_attr(frl_prefix('field_restricted')) . '[]" value="' . esc_attr($original_field_key) . '" />';
            }
        }

        return $output;
    }

    // Formatting Field Renderers
    // --------------------------------------------------

    /**
     * Renders a divider (<hr>)
     *
     * @param array $field Field configuration containing optional 'classes' and 'id'.
     * @return string HTML for the divider.
     */
    public static function render_divider($field)
    {
        $classes = isset($field['classes']) ? esc_attr($field['classes']) : '';
        $id_attr = isset($field['id']) ? ' id="' . esc_attr($field['id']) . '"' : '';
        return sprintf('<hr class="format-divider %s"%s />', $classes, $id_attr);
    }

    /**
     * Renders a heading (h1-h6)
     *
     * @param array $field Field configuration containing 'label', optional 'level' (default 3), 'classes', 'id'.
     * @return string HTML for the heading.
     */
    public static function render_heading($field)
    {
        $level = isset($field['level']) ? intval($field['level']) : 3;
        $level = max(1, min(6, $level)); // Ensure level is between 1-6
        $classes = isset($field['classes']) ? esc_attr($field['classes']) : '';
        $id_attr = isset($field['id']) ? ' id="' . esc_attr($field['id']) . '"' : '';
        $label = isset($field['label']) ? wp_kses_post($field['label']) : '';

        return sprintf(
            '<h%1$d class="format-heading %2$s"%3$s>%4$s</h%1$d>',
            $level,
            $classes,
            $id_attr,
            $label
        );
    }

    /**
     * Renders a section title (typically a div)
     *
     * @param array $field Field configuration containing 'description', optional 'classes', 'id'.
     * @return string HTML for the section title.
     */
    public static function render_section_title($field)
    {
        $classes = isset($field['classes']) ? esc_attr($field['classes']) : '';
        $id_attr = isset($field['id']) ? ' id="' . esc_attr($field['id']) . '"' : '';
        $description = isset($field['description']) ? wp_kses_post($field['description']) : '';

        return sprintf(
            '<div class="format-section-title %s %s" %s>%s</div>',
            $classes,
            $field['id'],
            $id_attr,
            $description
        );
    }

    /**
     * Renders a description block (typically a div)
     *
     * @param array $field Field configuration containing 'description', optional 'classes', 'id'.
     * @return string HTML for the description.
     */
    public static function render_description($field)
    {
        $classes = isset($field['classes']) ? esc_attr($field['classes']) : '';
        $id_attr = isset($field['id']) ? ' id="' . esc_attr($field['id']) . '"' : '';
        $description = isset($field['description']) ? wp_kses_post($field['description']) : '';

        return sprintf(
            '<div class="format-description %s"%s>%s</div>',
            $classes,
            $id_attr,
            $description
        );
    }

    // Vertical Bar Chart Element Rendering
    // --------------------------------------------------

    /**
     * Renders a container with multiple vertical CSS bars for a single chart cell (e.g., one day).
     *
     * @param array  $counts_for_cell Associative array for this cell [group_key => count].
     * @param array  $all_group_keys  Ordered list of all group keys (e.g., languages, services) to potentially display.
     * @param int    $max_count       The overall maximum count for scaling (0-100%).
     * @param array  $color_map       Associative array mapping group key to hex color.
     * @param string $primary_label   Primary label for the title (e.g., Date string).
     * @param bool   $use_log_scale   Whether to apply logarithmic scaling to bar heights.
     * @return string HTML for the bars container and individual bars for one cell.
     */
    public static function render_grouped_bars_cell(
        array $counts_for_cell,
        array $all_group_keys,
        int $max_count,
        array $color_map,
        string $primary_label = '',
        bool $use_log_scale = true
    ) {
        $cell_html = '<div class="frl-daily-bars-container">';
        $max_count = max(1, $max_count); // Ensure max is at least 1

        foreach ($all_group_keys as $key) {
            $count = $counts_for_cell[$key] ?? 0;
            $key_label = esc_attr($key); // Use the key itself as the label

            // Calculate fill percentage
            if ($use_log_scale) {
                $fill_percentage = frl_wsf_calculate_log_percentage($count, $max_count); // Use log scale function
            } else {
                $fill_percentage = ($count / $max_count) * 100; // Linear scale
                $fill_percentage = max(0.0, min(100.0, $fill_percentage)); // Clamp
            }

            $color = $color_map[$key] ?? '#cccccc'; // Default color if not mapped

            // Bar div with group key class, color, data, and height
            $bar_div = sprintf(
                '<div class="frl-css-grouped-bar group-%s" title="%s - %s: %d" style="background-color: %s; height: %f%%;"></div>',
                sanitize_html_class('key-' . $key), // Create a CSS-safe class from the key
                $key_label, // Group key label
                esc_attr($primary_label), // Primary label (e.g., date)
                $count,
                esc_attr($color),
                $fill_percentage
            );
            $cell_html .= $bar_div;
        }
        $cell_html .= '</div>'; // Close container
        return $cell_html;
    }
}
