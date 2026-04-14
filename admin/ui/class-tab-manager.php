<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fralenuvole
 * class-tab-manager.php - Tab manager for settings UI
 */

/**
 * Frl_Tab_Manager
 *
 * Handles tab registration, ordering, and rendering.
 * This class uses a singleton pattern to ensure only one instance exists
 * throughout the application's lifecycle.
 */
class Frl_Tab_Manager
{
    /**
     * Tab registry to store both form tabs and custom tabs
     *
     * @var array
     */
    private $tab_registry = [
        'form' => [],    // Regular tabs inside the form
        'custom' => []   // Custom tabs outside the form
    ];

    // Position constants for tab ordering
    const POSITION_FIRST = 0;
    const POSITION_DEFAULT = 500;
    const POSITION_FORM = 1000;
    const POSITION_LAST = PHP_INT_MAX;

    // Singleton instance
    private static $instance = null;

    /**
     * Cache for the sorted list of all tabs within a single request.
     * @var array|null
     */
    private $sorted_tabs = null;

    /**
     * Get the singleton instance
     *
     * @return Frl_Tab_Manager The singleton instance
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor to prevent direct instantiation
     */
    private function __construct()
    {
        // Initialization if needed
    }

    /**
     * Static facade for register_tab method
     *
     * @param string $tab_id The tab ID without the 'tabs-' prefix
     * @param array $args Tab configuration arguments
     * @param string $type Either 'form' or 'custom'
     */
    public static function register_tab($tab_id, $args = [], $type = 'custom'): void
    {
        self::get_instance()->_register_tab($tab_id, $args, $type);
    }

    /**
     * Register a tab of any type in the central registry
     *
     * This unified method allows registration of both form tabs and custom tabs
     * in a consistent way. It stores all tab configuration in the central registry.
     *
     * @param string $tab_id The tab ID without the 'tabs-' prefix
     * @param array $args Tab configuration arguments
     * @param string $type Either 'form' or 'custom'
     * @return void
     */
    public function _register_tab($tab_id, $args = [], $type = 'custom')
    {
        // For custom tabs, use the existing registration method
        if ($type === 'custom') {
            $this->_register_custom_tab($tab_id, $args);
            return;
        }

        // Below code will only run for non-custom tabs (form tabs)
        $defaults = [
            'title' => '',
            'description' => '',
            'callback' => null,
            'priority' => 10,
            'title_priority' => 5,
            'position' => self::POSITION_FORM + count($this->tab_registry[$type]) // Auto position after existing form tabs
        ];

        $args = wp_parse_args($args, $defaults);

        // Apply filters to title and description
        $args['title'] = apply_filters(FRL_PREFIX . '_' . $tab_id . '_tab_title', $args['title']);
        $args['description'] = apply_filters(FRL_PREFIX . '_' . $tab_id . '_tab_description', $args['description']);

        // Store in our central registry
        $this->tab_registry[$type][$tab_id] = $args;

        // Invalidate sorted tabs cache only
        $this->sorted_tabs = null;
    }

    /**
     * Static facade for register_custom_tab method
     *
     * @param string $tab_id The ID of the tab without the 'tabs-' prefix
     * @param array $args Array of arguments for the custom tab
     */
    public static function register_custom_tab($tab_id, $args = []): void
    {
        self::get_instance()->_register_custom_tab($tab_id, $args);
    }

    /**
     * Register a custom tab with title, description, and callback
     *
     * This method provides a comprehensive way to register a custom tab including
     * its title, description, and content callback. It's a higher-level function
     * that combines tab header creation and content registration.
     *
     * @param string $tab_id The ID of the tab without the 'tabs-' prefix
     * @param array $args {
     *     Optional. Array of arguments for the custom tab.
     *     @type string $title       Tab title displayed as h2. Default empty.
     *     @type string $description Tab description displayed below title. Default empty.
     *     @type callable $callback  Function that outputs the tab content. Default null.
     *     @type int $priority       Priority for the content hook. Default 10.
     *     @type int $title_priority Priority for the title/description hook. Default 5.
     *     @type int $position       Position in the tab order. Default is at the end.
     * }
     */
    public function _register_custom_tab($tab_id, $args = [])
    {
        $defaults = [
            'title' => '',
            'description' => '',
            'callback' => null,
            'priority' => 10,
            'title_priority' => 5,
            'position' => self::POSITION_DEFAULT
        ];

        $args = wp_parse_args($args, $defaults);

        // Apply filters to title and description
        $title = apply_filters(FRL_PREFIX . '_' . $tab_id . '_tab_title', $args['title']);
        $description = apply_filters(FRL_PREFIX . '_' . $tab_id . '_tab_description', $args['description']);

        // Get the correct action hook name
        $action_hook = $this->_get_tab_action_hook($tab_id);

        // Add the header if a title is provided
        if (!empty($title)) {
            // Register title/description directly with the correct hook name
            add_action($action_hook, function () use ($title, $description) {
                echo '<h2>' . esc_html($title) . '</h2>';
                if (!empty($description)) {
                    echo '<p>' . esc_html($description) . '</p>';
                }
            }, $args['title_priority'], 0);
        }

        // Register the callback if provided
        if (!empty($args['callback'])) {
            $callback = $args['callback'];

            // HYBRID APPROACH:
            // 1. For simple string function names, register them directly
            //    (optimizes performance and ensures proper execution)
            if (is_string($callback) && function_exists($callback)) {
                add_action($action_hook, $callback, $args['priority'], 1);
            }
            // 2. For other callback types (closures, class methods, etc.) or not-yet-defined functions,
            //    use the delayed execution approach with helpful debug output
            else {
                add_action($action_hook, function () use ($callback, $tab_id) {
                    if (is_callable($callback)) {
                        call_user_func($callback);
                    } elseif (defined('WP_DEBUG') && WP_DEBUG) {
                        echo '<p class="error">Error: Callback "' . esc_html(is_string($callback) ? $callback : 'Object') . '" for tab "' . esc_html($tab_id) . '" is not callable.</p>';
                    }
                }, $args['priority'], 2);
            }
        }

        // Store in the registry system
        $this->tab_registry['custom'][$tab_id] = array_merge($args, [
            'title' => $title,
            'description' => $description
        ]);

        // Invalidate sorted tabs cache only
        $this->sorted_tabs = null;
    }

    /**
     * Generate tab navigation items from the registry
     *
     * This method generates HTML for tab navigation links based on
     * the tabs registered in the central registry.
     *
     * @return void
     */
    public function _generate_tabs_navigation()
    {
        // PERFORMANCE OPTIMIZATION: Use cached sorted tabs list
        $all_tabs = $this->_get_sorted_tabs();

        // PERFORMANCE OPTIMIZATION: Use static variable for in-request HTML caching
        static $tab_nav_html = null;

        // Check if we've already generated the HTML in this request
        if ($tab_nav_html !== null) {
            echo $tab_nav_html;
            return;
        }

        // Start output buffer to capture HTML
        ob_start();

        // Output all tabs in position-sorted order
        foreach ($all_tabs as $tab) {
            $tab_id = $tab['id'];
            $config = $tab['config'];

            // Apply filter to check if this tab/section should be displayed (due to capability)
            $is_restricted = apply_filters('frl_is_section_restricted', false, $tab_id);

            if ($is_restricted) {
                continue; // Skip rendering the <li> if restricted
            }

            // Only render if not restricted
            echo '<li class="tab-' . esc_attr($tab_id) . '"><a href="#tabs-' . esc_attr($tab_id) . '">' . esc_html($config['title']) . '</a></li>';
        }

        // Store HTML in static variable
        $tab_nav_html = ob_get_clean();
        echo $tab_nav_html;
    }

    /**
     * Static facade for render_all_custom_tabs method
     */
    public static function render_all_custom_tabs(): void
    {
        self::get_instance()->_render_all_custom_tabs();
    }

    /**
     * Render all custom tabs from the registry
     *
     * This method renders all custom tab containers based on
     * the tabs registered in the central registry.
     *
     * @return void
     */
    public function _render_all_custom_tabs()
    {
        // Get custom tabs from registry
        $custom_tabs = $this->tab_registry['custom'];

        // Sort custom tabs by position
        uasort($custom_tabs, function ($a, $b) {
            $a_pos = isset($a['position']) ? (int)$a['position'] : 0;
            $b_pos = isset($b['position']) ? (int)$b['position'] : 0;
            return $a_pos - $b_pos;
        });

        // Render each custom tab in sorted order
        foreach ($custom_tabs as $tab_id => $config) {
            // Check if the tab is restricted by capability
            $is_restricted = apply_filters('frl_is_section_restricted', false, $tab_id);
            if ($is_restricted) {
                continue; // Skip rendering this custom tab's content if restricted
            }

            // Get the correct action hook name
            $action_hook = $this->_get_tab_action_hook($tab_id);
            $this->_render_custom_tab($tab_id, $action_hook);
        }
    }

    /**
     * Static facade for render_custom_tab method
     *
     * @param string $tab_id The ID of the tab without the 'tabs-' prefix
     * @param string $action_hook The action hook to fire for populating content
     */
    public static function render_custom_tab($tab_id, $action_hook = ''): void
    {
        if (empty($action_hook)) {
            $action_hook = self::get_instance()->_get_tab_action_hook($tab_id);
        }
        self::get_instance()->_render_custom_tab($tab_id, $action_hook);
    }

    /**
     * Render a custom tab's content container
     *
     * Creates a standard container for custom tab content outside the settings form.
     * This provides a consistent structure for tabs like Dashboard and Import/Export.
     *
     * @param string $tab_id The ID of the tab without the 'tabs-' prefix
     * @param string $action_hook The action hook to fire for populating content
     * @return void
     */
    public function _render_custom_tab($tab_id, $action_hook)
    {
?>
        <div id="tabs-<?php echo esc_attr($tab_id); ?>" class="frl-section custom-tab-container">
            <?php
            // Apply a filter before the content
            echo apply_filters(FRL_PREFIX . '_before_' . $tab_id . '_content', '');

            // Do the main content action
            do_action($action_hook);

            // Apply a filter after the content
            echo apply_filters(FRL_PREFIX . '_after_' . $tab_id . '_content', '');
            ?>
        </div>
<?php
    }

    /**
     * Static facade for get_tab_action_hook method
     *
     * @param string $tab_id The ID of the tab without the 'tabs-' prefix
     * @return string The action hook name to use
     */
    public static function get_tab_action_hook($tab_id)
    {
        return self::get_instance()->_get_tab_action_hook($tab_id);
    }

    /**
     * Get the correct action hook name for a tab
     *
     * This helper method centralizes the logic for determining the
     * correct action hook name based on tab ID.
     *
     * @param string $tab_id The ID of the tab without the 'tabs-' prefix
     * @return string The action hook name to use
     */
    public function _get_tab_action_hook($tab_id)
    {
        // Use a consistent naming pattern for all tabs
        return FRL_PREFIX . '_' . $tab_id . '_content';
    }

    /**
     * Static facade for add_tab_content method
     *
     * @param string $tab_id The ID of the tab without the 'tabs-' prefix
     * @param string $content The content to add to the tab
     * @param int $priority The priority for the action hook
     */
    public static function add_tab_content($tab_id, $content, $priority = 10): void
    {
        self::get_instance()->_add_tab_content($tab_id, $content, $priority);
    }

    /**
     * Add content to any custom tab via filter
     *
     * This allows adding content to any tab using a filter approach,
     * similar to how `apply_filters` works elsewhere.
     *
     * @param string $tab_id The ID of the tab without the 'tabs-' prefix
     * @param string $content The content to add to the tab
     * @param int $priority The priority for the action hook
     */
    public function _add_tab_content($tab_id, $content, $priority = 10)
    {
        add_action(FRL_PREFIX . '_' . $tab_id . '_content', function () use ($content, $tab_id) {
            echo apply_filters(FRL_PREFIX . '_' . $tab_id . '_content_html', $content);
        }, $priority, 0);
    }

    /**
     * Get all tabs of a certain type, or all if no type is specified.
     *
     * @param string $type Optional. Type of tabs to retrieve ('form', 'custom', or null for all)
     * @return array The registered tabs
     */
    public function get_tabs($type = null)
    {
        if ($type !== null && isset($this->tab_registry[$type])) {
            return $this->tab_registry[$type];
        }
        return $this->tab_registry;
    }

    /**
     * Static facade for get_tabs method
     *
     * @param string $type Optional. Type of tabs to retrieve ('form', 'custom', or null for all)
     * @return array The registered tabs
     */
    public static function get_registered_tabs($type = null)
    {
        return self::get_instance()->get_tabs($type);
    }

    /**
     * Associate sections with a tab
     *
     * This method allows explicitly linking one or more sections to a specific tab.
     * When sections are associated with a tab, they'll only be shown when that tab is active.
     *
     * @param string $tab_id The ID of the tab to associate sections with
     * @param array $sections Array of section IDs to associate with the tab
     * @return bool
     */
    public function _associate_sections_with_tab($tab_id, array $sections)
    {
        // Make sure the tab exists in either form or custom tabs
        $tab_exists = isset($this->tab_registry['form'][$tab_id]) ||
            isset($this->tab_registry['custom'][$tab_id]);

        if (!$tab_exists) {
            return false;
        }

        // Determine which type of tab this is
        $tab_type = isset($this->tab_registry['form'][$tab_id]) ? 'form' : 'custom';

        // Add or merge the sections array
        if (!isset($this->tab_registry[$tab_type][$tab_id]['sections'])) {
            $this->tab_registry[$tab_type][$tab_id]['sections'] = $sections;
        } else {
            $this->tab_registry[$tab_type][$tab_id]['sections'] = array_unique(
                array_merge($this->tab_registry[$tab_type][$tab_id]['sections'], $sections)
            );
        }

        return true;
    }

    /**
     * Static facade for associate_sections_with_tab method
     *
     * @param string $tab_id The ID of the tab to associate sections with
     * @param array $sections Array of section IDs to associate with the tab
     * @return bool True if the association was successful, false otherwise
     */
    public static function associate_sections_with_tab($tab_id, array $sections): bool
    {
        $instance = self::get_instance();
        $instance->_associate_sections_with_tab($tab_id, $sections);
        return true; // Return true as the method completes without throwing
    }

    /**
     * Get sections associated with a tab
     *
     * @param string $tab_id The ID of the tab to get sections for
     * @return array|null Array of section IDs or null if tab doesn't exist
     */
    public function _get_tab_sections($tab_id)
    {
        // Check in form tabs
        if (isset($this->tab_registry['form'][$tab_id])) {
            return $this->tab_registry['form'][$tab_id]['sections'] ?? [];
        }

        // Check in custom tabs
        if (isset($this->tab_registry['custom'][$tab_id])) {
            return $this->tab_registry['custom'][$tab_id]['sections'] ?? [];
        }

        return null;
    }

    /**
     * Static facade for get_tab_sections method
     *
     * @param string $tab_id The ID of the tab to get sections for
     * @return array|null Array of section IDs or null if tab doesn't exist
     */
    public static function get_tab_sections($tab_id)
    {
        return self::get_instance()->_get_tab_sections($tab_id);
    }

    /**
     * Add field group to a tab
     *
     * This method allows adding a group of related fields to a tab.
     * Field groups make it easier to organize fields within a tab.
     *
     * @param string $tab_id The ID of the tab to add fields to
     * @param array $field_group {
     *     Field group configuration.
     *     @type string $id         Unique ID for the field group
     *     @type string $title      Title of the field group (optional)
     *     @type string $description Description of the field group (optional)
     *     @type array  $fields     Array of field configurations
     * }
     * @return bool True if successful, false otherwise
     */
    public function _add_field_group($tab_id, array $field_group)
    {
        // Make sure the tab exists in either form or custom tabs
        $tab_exists = isset($this->tab_registry['form'][$tab_id]) ||
            isset($this->tab_registry['custom'][$tab_id]);

        if (!$tab_exists) {
            return false;
        }

        // Validate field group
        if (!isset($field_group['id']) || !frl_is_array_not_empty($field_group, 'fields')) {
            return false;
        }

        // Determine which type of tab this is
        $tab_type = isset($this->tab_registry['form'][$tab_id]) ? 'form' : 'custom';

        // Initialize field_groups array if it doesn't exist
        if (!isset($this->tab_registry[$tab_type][$tab_id]['field_groups'])) {
            $this->tab_registry[$tab_type][$tab_id]['field_groups'] = [];
        }

        // Add the field group
        $this->tab_registry[$tab_type][$tab_id]['field_groups'][$field_group['id']] = $field_group;

        return true;
    }

    /**
     * Static facade for add_field_group method
     *
     * @param string $tab_id The ID of the tab to add fields to
     * @param array $field_group Field group configuration
     * @return bool True if successful, false otherwise
     */
    public static function add_field_group($tab_id, array $field_group)
    {
        return self::get_instance()->_add_field_group($tab_id, $field_group);
    }

    /**
     * Get field groups for a tab
     *
     * @param string $tab_id The ID of the tab to get field groups for
     * @return array|null Array of field groups or null if tab doesn't exist
     */
    public function _get_tab_field_groups($tab_id)
    {
        // Check in form tabs
        if (isset($this->tab_registry['form'][$tab_id])) {
            return $this->tab_registry['form'][$tab_id]['field_groups'] ?? [];
        }

        // Check in custom tabs
        if (isset($this->tab_registry['custom'][$tab_id])) {
            return $this->tab_registry['custom'][$tab_id]['field_groups'] ?? [];
        }

        return null;
    }

    /**
     * Static facade for get_tab_field_groups method
     *
     * @param string $tab_id The ID of the tab to get field groups for
     * @return array|null Array of field groups or null if tab doesn't exist
     */
    public static function get_tab_field_groups($tab_id)
    {
        return self::get_instance()->_get_tab_field_groups($tab_id);
    }

    /**
     * Set validation rules for a tab
     *
     * This method allows setting tab-specific validation rules for fields.
     * These rules will be applied before saving options for fields in the tab.
     *
     * @param string $tab_id The ID of the tab to set validation rules for
     * @param array $validation_rules Array of validation rules (field_id => rule)
     * @return bool True if successful, false otherwise
     */
    public function _set_tab_validation_rules($tab_id, array $validation_rules)
    {
        // Make sure the tab exists in either form or custom tabs
        $tab_exists = isset($this->tab_registry['form'][$tab_id]) ||
            isset($this->tab_registry['custom'][$tab_id]);

        if (!$tab_exists) {
            return false;
        }

        // Determine which type of tab this is
        $tab_type = isset($this->tab_registry['form'][$tab_id]) ? 'form' : 'custom';

        // Set validation rules
        $this->tab_registry[$tab_type][$tab_id]['validation_rules'] = $validation_rules;

        return true;
    }

    /**
     * Static facade for set_tab_validation_rules method
     *
     * @param string $tab_id The ID of the tab to set validation rules for
     * @param array $validation_rules Array of validation rules (field_id => rule)
     * @return bool True if successful, false otherwise
     */
    public static function set_tab_validation_rules($tab_id, array $validation_rules)
    {
        return self::get_instance()->_set_tab_validation_rules($tab_id, $validation_rules);
    }

    /**
     * Get validation rules for a tab
     *
     * @param string $tab_id The ID of the tab to get validation rules for
     * @return array|null Array of validation rules or null if tab doesn't exist
     */
    public function _get_validation_rules($tab_id)
    {
        // Check in form tabs
        if (isset($this->tab_registry['form'][$tab_id])) {
            return $this->tab_registry['form'][$tab_id]['validation_rules'] ?? [];
        }

        // Check in custom tabs
        if (isset($this->tab_registry['custom'][$tab_id])) {
            return $this->tab_registry['custom'][$tab_id]['validation_rules'] ?? [];
        }

        return null;
    }

    /**
     * Static facade for get_validation_rules method
     *
     * @param string $tab_id The ID of the tab to get validation rules for
     * @return array|null Array of validation rules or null if tab doesn't exist
     */
    public static function get_validation_rules($tab_id)
    {
        return self::get_instance()->_get_validation_rules($tab_id);
    }

    /**
     * Render field groups for a tab
     *
     * This method renders all field groups associated with a tab.
     * It uses the field callback function provided to render each field.
     *
     * @param string $tab_id The ID of the tab to render field groups for
     * @param callable $field_callback Function to render individual fields
     * @param array $args Additional arguments to pass to the field callback
     * @return void
     */
    public function _render_tab_field_groups($tab_id, $field_callback, $args = [])
    {
        if (!$field_callback) {
            return;
        }

        // Get field groups for this tab
        $field_groups = $this->_get_tab_field_groups($tab_id);

        if (empty($field_groups)) {
            return;
        }

        // Render each field group
        foreach ($field_groups as $group_id => $group) {
            // Render group header if title is provided
            if (!empty($group['title'])) {
                echo '<div class="frl-field-group" id="field-group-' . esc_attr($group_id) . '">';
                echo '<h3>' . esc_html($group['title']) . '</h3>';

                if (!empty($group['description'])) {
                    echo '<p class="description">' . esc_html($group['description']) . '</p>';
                }

                echo '<table class="form-table">';
            }

            // Render each field in the group
            foreach ($group['fields'] as $field) {
                echo '<tr valign="top">';
                echo '<th scope="row">' . esc_html($field['label'] ?? '') . '</th>';
                echo '<td>';

                // Call the field callback to render the field
                call_user_func($field_callback, array_merge($field, $args));

                echo '</td>';
                echo '</tr>';
            }

            // Close the group if we opened it
            if (!empty($group['title'])) {
                echo '</table>';
                echo '</div>';
            }
        }
    }

    /**
     * Static facade for render_tab_field_groups method
     *
     * @param string $tab_id The ID of the tab to render field groups for
     * @param callable $field_callback Function to render individual fields
     * @param array $args Additional arguments to pass to the field callback
     */
    public static function render_tab_field_groups($tab_id, $field_callback, $args = []): void
    {
        self::get_instance()->_render_tab_field_groups($tab_id, $field_callback, $args);
    }

    /**
     * Register form tabs from sections
     *
     * Automatically creates tabs based on field sections
     *
     * @param array $sections Sections extracted from fields
     * @param int $position_start Optional. Starting position value. Default is POSITION_FORM.
     * @param int $position_increment Optional. Increment step for position. Default is 1.
     */
    private function register_form_tabs_from_sections($sections, $position_start = self::POSITION_FORM, $position_increment = 1)
    {
        $position = $position_start;
        $section_names = frl_get_default_fields_sections();

        foreach ($sections as $key => $value) {
            // Determine the actual section ID regardless of input array format
            $section_id = is_int($key) ? $value : $key;

            // Check if section ID is valid before using it as an array key
            if (!isset($section_names[$section_id])) {
                continue; // Skip if the section ID doesn't exist in the names array
            }

            // Convert section ID to title
            $title = $section_names[$section_id];

            // Register tab if not already registered
            if (!isset($this->tab_registry['form'][$section_id])) {
                $this->_register_tab($section_id, [
                    'title' => $title,
                    'position' => $position // Use current position
                ], 'form');

                // Associate section with tab
                $this->_associate_sections_with_tab($section_id, [$section_id]);
            }

            $position += $position_increment; // Increment position for next iteration
        }
    }

    /**
     * Prepare sections from fields array
     *
     * This method organizes fields into sections based on their 'section' property.
     *
     * @param array $fields Array of field configurations
     * @return array Associative array of section ID => fields
     * @phpstan-ignore-next-line
     */
    private function prepare_sections_from_fields($fields)
    {
        $sections = [];

        foreach ($fields as $field) {
            if (!isset($field['section'])) {
                continue;
            }

            $section_id = $field['section'];

            if (!isset($sections[$section_id])) {
                $sections[$section_id] = [];
            }

            $sections[$section_id][] = $field;
        }

        return $sections;
    }

    /**
     * Get the current active tab index
     *
     * Determines which tab is currently active based on request parameters,
     * falling back to the first tab if none is specified.
     *
     * @return int The active tab index
     */
    public function _get_active_tab()
    {
        $active_tab = 0; // Default to first tab

        // Check if tab is specified in URL
        if (isset($_GET['tab']) && is_numeric($_GET['tab'])) {
            $active_tab = max(0, intval($_GET['tab']));
        }

        // Check if form was submitted and redirected with saved tab
        if (isset($_GET['settings-updated']) && $_GET['settings-updated'] === 'true') {
            $saved_tab = frl_get_transient('active_settings_tab');
            if ($saved_tab !== false) {
                $active_tab = $saved_tab;
                frl_delete_transient('active_settings_tab');
            }
        }

        return $active_tab;
    }

    /**
     * Check if a tab is active
     *
     * Determines if the given tab ID matches the active tab index
     *
     * @param string $tab_id The ID of the tab to check
     * @param int $active_tab The active tab index
     * @return bool True if the tab is active
     * @phpstan-ignore-next-line
     */
    private function is_tab_active($tab_id, $active_tab)
    {
        // Get all tabs in order using the cached helper
        $all_tabs = $this->_get_sorted_tabs();

        // Check if the given tab ID is at the active index
        if (isset($all_tabs[$active_tab]) && $all_tabs[$active_tab]['id'] === $tab_id) {
            return true;
        }

        return false;
    }

    /**
     * Render the content for a form tab
     *
     * This method renders the fields for a specific form tab,
     * organized by their associated sections.
     *
     * @param string $tab_id The ID of the tab to render
     * @param array $sections_data_for_all_form_tabs Array of sections with their fields
     * @param callable $field_callback Function to render individual fields
     * @param array $args Additional arguments for field rendering
     * @phpstan-ignore-next-line
     */
    private function render_form_tab_content($tab_id, $sections_data_for_all_form_tabs, $field_callback, $args = [])
    {
        $tab_specific_section_ids = $this->_get_tab_sections($tab_id);

        if (empty($tab_specific_section_ids)) {
        }

        foreach ($tab_specific_section_ids as $section_id_to_render) {
            if (!isset($sections_data_for_all_form_tabs[$section_id_to_render])) {
                continue;
            }

            $fields_for_this_section = $sections_data_for_all_form_tabs[$section_id_to_render];

            global $wp_settings_sections;
            $page_slug = FRL_NAME;
            $section_title = '';
            if (isset($wp_settings_sections[$page_slug][$section_id_to_render]['title'])) {
                $section_title = $wp_settings_sections[$page_slug][$section_id_to_render]['title'];
            } else {
                $section_title = ucwords(str_replace('-', ' ', $section_id_to_render));
            }

            echo '<div class="frl-section" id="section-' . esc_attr($section_id_to_render) . '">';
            echo '<h3>' . esc_html($section_title) . '</h3>';
            echo '<table class="form-table">';

            if (frl_is_array_not_empty($fields_for_this_section)) {
                foreach ($fields_for_this_section as $field) {
                    if (is_callable($field_callback)) {
                        echo '<tr valign="top">';
                        echo '<th scope="row">' . esc_html($field['label'] ?? 'N/A') . '</th>';
                        echo '<td>';
                        call_user_func($field_callback, $field);
                        echo '</td>';
                        echo '</tr>';
                    }
                }
            }
            echo '</table>';
            echo '</div>';
        }
    }

    /**
     * Save the active tab state for use after form submission
     *
     * This method stores the active tab in a transient so it can be
     * restored after a form submission redirects the user.
     *
     * @param int $active_tab The active tab index to save
     * @return void
     */
    public function _save_active_tab($active_tab)
    {
        frl_set_transient('active_settings_tab', intval($active_tab), HOUR_IN_SECONDS);
    }

    /**
     * Static facade for save_active_tab method
     *
     * @param int $active_tab The active tab index to save
     * @return void
     */
    public static function save_active_tab($active_tab)
    {
        self::get_instance()->_save_active_tab($active_tab);
    }

    /**
     * Static facade for _get_active_tab method
     *
     * @return int The active tab index
     */
    public static function get_active_tab()
    {
        return self::get_instance()->_get_active_tab();
    }

    /**
     * Render the tab container structure
     *
     * Creates the outer container for tabs and navigation. This centralizes
     * the tab UI framework in one place.
     *
     * @param bool $vertical Whether to use vertical tabs
     * @param string $additional_class Additional CSS classes to add
     * @param int $active_tab The active tab index
     * @return void
     */
    public function _render_tab_container_start($vertical = true, $additional_class = '', $active_tab = null)
    {
        // Get active tab if not provided
        if ($active_tab === null) {
            $active_tab = $this->_get_active_tab();
        }

        // Determine tab class based on orientation
        $tab_class = $vertical ? 'frl-tabs vertical-tabs' : 'frl-tabs';
        if (!empty($additional_class)) {
            $tab_class .= ' ' . $additional_class;
        }

        // Begin the tab container
        echo '<div id="tabs" class="wrap frl-wrap ' . esc_attr($tab_class) . '" data-active-tab="' . esc_attr($active_tab) . '">';
    }

    /**
     * Close the tab container structure
     *
     * @return void
     */
    public function _render_tab_container_end()
    {
        echo '</div>'; // Close the #tabs div
    }

    /**
     * Static facade for render_tab_container_start method
     *
     * @param bool $vertical Whether to use vertical tabs
     * @param string $additional_class Additional CSS classes to add
     * @param int $active_tab The active tab index
     * @return void
     */
    public static function render_tab_container_start($vertical = true, $additional_class = '', $active_tab = null)
    {
        self::get_instance()->_render_tab_container_start($vertical, $additional_class, $active_tab);
    }

    /**
     * Static facade for render_tab_container_end method
     *
     * @return void
     */
    public static function render_tab_container_end()
    {
        self::get_instance()->_render_tab_container_end();
    }

    /**
     * Static facade for render_tab_navigation method
     *
     * @return string HTML for the tab navigation
     */
    public static function render_tab_navigation()
    {
        // Make cache key sensitive to user access level to reflect visibility differences
        $current_user_id = get_current_user_id();
        $has_access = frl_has_access();
        $access_suffix = $has_access ? '_admin' : '_user_' . $current_user_id;
        $cache_key = 'ui_tabs' . $access_suffix;

        return frl_cache_remember('adminui', $cache_key, function () {
            // Actual generation uses output buffering
            ob_start();
            self::get_instance()->_generate_tabs_navigation(); // Call the real generation method
            return ob_get_clean();
        });
    }

    /**
     * Render tabs from sections
     *
     * This method handles automatic tab creation from sections and
     * sets up the necessary hooks for section filtering.
     *
     * @param array $sections Array of section IDs to convert to tabs
     * @param int $position_start Starting position for tabs
     */
    public function _render_tabs_from_sections($sections, $position_start = 1000): void
    {
        // Register tabs from sections using the modified private method
        $this->register_form_tabs_from_sections($sections, $position_start, 10); // Use increment 10

        // Generate tab navigation using the cached static method
        echo '<ul id="frl-tabs-nav">'; // Add the opening UL tag
        echo self::render_tab_navigation(); // Echo the cached LIs
        echo '</ul>'; // Add the closing UL tag

    }

    /**
     * Static facade for render_tabs_from_sections method
     *
     * @param array $sections Array of section IDs to convert to tabs
     * @param int $position_start Starting position for tabs
     */
    public static function render_tabs_from_sections($sections, $position_start = 1000): void
    {
        self::get_instance()->_render_tabs_from_sections($sections, $position_start);
    }

    /**
     * Hide tabs by capability check
     *
     * This method allows hiding tabs based on a capability check.
     * Tabs will be hidden from both the navigation and their content won't be rendered.
     *
     * @param array $hidden_tabs Array of tab IDs to potentially hide
     * @param bool $has_access Whether the user has access (if null, uses frl_has_access())
     * @return void
     */
    public static function hide_tabs_by_capability($hidden_tabs, $has_access = null)
    {
        // Get instance for property access
        $instance = self::get_instance();

        // If no access check provided, use frl_has_access() if available
        if ($has_access === null) {
            $has_access = frl_has_access();
        }

        // Keep Filters #2 (frl_is_section_restricted for content hiding)
        foreach ($hidden_tabs as $tab_id) {
            if (!$has_access) {
                // Filter to mark the section itself as restricted if access is denied
                add_filter('frl_is_section_restricted',
                    function ($is_restricted, $section_id_to_check) use ($tab_id) {
                        if ($section_id_to_check === $tab_id) {
                            return true; // Mark this section as restricted
                        }
                        return $is_restricted; // Pass through otherwise
                    },
                    10, // Standard priority
                    2);
            }
        }

        // Invalidate sorted tabs cache only
        $instance->sorted_tabs = null;
    }

    /**
     * Helper method to get the merged and sorted list of all tabs.
     * Caches the result within the instance for the duration of the request.
     *
     * @return array Sorted list of tab data arrays.
     */
    private function _get_sorted_tabs()
    {
        // Return cached list if available for this request
        if ($this->sorted_tabs !== null) {
            return $this->sorted_tabs;
        }

        $all_tabs = [];

        // Add custom tabs to the unified array
        foreach ($this->tab_registry['custom'] as $tab_id => $config) {
            $position = isset($config['position']) ? (int)$config['position'] : self::POSITION_DEFAULT;
            $all_tabs[] = [
                'id' => $tab_id,
                'type' => 'custom',
                'config' => $config, // Include full config
                'position' => $position
            ];
        }

        // Add form tabs to the unified array
        foreach ($this->tab_registry['form'] as $tab_id => $config) {
            $position = isset($config['position']) ? (int)$config['position'] : self::POSITION_FORM;
            $all_tabs[] = [
                'id' => $tab_id,
                'type' => 'form',
                'config' => $config, // Include full config
                'position' => $position
            ];
        }

        // Sort all tabs by position
        usort($all_tabs, function ($a, $b) {
            return $a['position'] - $b['position'];
        });

        // Cache the sorted list for this request
        $this->sorted_tabs = $all_tabs;

        return $this->sorted_tabs;
    }

    /**
     * Static facade for _get_sorted_tabs method.
     *
     * @return array Sorted list of tab data arrays.
     */
    public static function get_sorted_tabs()
    {
        return self::get_instance()->_get_sorted_tabs();
    }
}
