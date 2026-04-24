<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fralenuvole Tab Renderer
 *
 * Handles HTML rendering of tab navigation and containers.
 * This is an internal helper class used by Frl_Tab_Manager.
 *
 * @internal Not intended for direct use - use Frl_Tab_Manager facade
 */
class Frl_Tab_Renderer
{
    /**
     * Generate tab navigation HTML
     *
     * @param array $all_tabs Sorted tabs from registry
     * @return string
     */
    public function generate_navigation(array $all_tabs)
    {
        static $tab_nav_html = null;

        if ($tab_nav_html !== null) {
            return $tab_nav_html;
        }

        ob_start();

        foreach ($all_tabs as $tab) {
            $tab_id = $tab['id'];
            $config = $tab['config'];

            $is_restricted = apply_filters('frl_is_section_restricted', false, $tab_id);

            if ($is_restricted) {
                continue;
            }

            echo '<li class="tab-' . esc_attr($tab_id) . '"><a href="#tabs-' . esc_attr($tab_id) . '">' . esc_html($config['title']) . '</a></li>';
        }

        $tab_nav_html = ob_get_clean();
        return $tab_nav_html;
    }

    /**
     * Render all custom tabs
     *
     * @param array $custom_tabs
     * @return void
     */
    public function render_all_custom(array $custom_tabs)
    {
        uasort($custom_tabs, function ($a, $b) {
            $a_pos = isset($a['position']) ? (int)$a['position'] : 0;
            $b_pos = isset($b['position']) ? (int)$b['position'] : 0;
            return $a_pos - $b_pos;
        });

        foreach ($custom_tabs as $tab_id => $config) {
            $is_restricted = apply_filters('frl_is_section_restricted', false, $tab_id);
            if ($is_restricted) {
                continue;
            }

            $action_hook = FRL_PREFIX . '_' . $tab_id . '_content';
            $this->render_custom_tab($tab_id, $action_hook);
        }
    }

    /**
     * Render a custom tab container
     *
     * @param string $tab_id
     * @param string $action_hook
     * @return void
     */
    public function render_custom_tab($tab_id, $action_hook)
    {
?>
        <div id="tabs-<?php echo esc_attr($tab_id); ?>" class="frl-section custom-tab-container">
            <?php
            echo apply_filters(FRL_PREFIX . '_before_' . $tab_id . '_content', '');
            do_action($action_hook);
            echo apply_filters(FRL_PREFIX . '_after_' . $tab_id . '_content', '');
            ?>
        </div>
<?php
    }

    /**
     * Render tab container start
     *
     * @param bool $vertical
     * @param string $additional_class
     * @param int $active_tab
     * @return void
     */
    public function render_container_start($vertical = true, $additional_class = '', $active_tab = null)
    {
        $tab_class = $vertical ? 'frl-tabs vertical-tabs' : 'frl-tabs';
        if (!empty($additional_class)) {
            $tab_class .= ' ' . $additional_class;
        }

        echo '<div id="tabs" class="wrap frl-wrap ' . esc_attr($tab_class) . '" data-active-tab="' . esc_attr((string) $active_tab) . '">';
    }

    /**
     * Render tab container end
     *
     * @return void
     */
    public function render_container_end()
    {
        echo '</div>';
    }

    /**
     * Render tabs from sections
     *
     * @param array $sections
     * @param int $position_start
     * @param callable $register_callback
     * @param callable $get_navigation
     * @return void
     */
    public function render_tabs_from_sections($sections, $position_start, $register_callback, $get_navigation)
    {
        // Register tabs via callback
        $position = $position_start;
        $section_names = frl_get_default_fields_sections();

        foreach ($sections as $key => $value) {
            $section_id = is_int($key) ? $value : $key;

            if (!isset($section_names[$section_id])) {
                continue;
            }

            $title = $section_names[$section_id];

            $register_callback($section_id, [
                'title' => $title,
                'position' => $position
            ], 'form');

            $position += 10;
        }

        // Generate navigation
        echo '<ul id="frl-tabs-nav">';
        echo $get_navigation();
        echo '</ul>';
    }

    /**
     * Render field groups for a tab
     *
     * @param array $field_groups
     * @param callable $field_callback
     * @param array $args
     * @return void
     */
    public function render_field_groups($field_groups, $field_callback, $args = [])
    {
        if (!$field_callback || empty($field_groups)) {
            return;
        }

        foreach ($field_groups as $group_id => $group) {
            if (!empty($group['title'])) {
                echo '<div class="frl-field-group" id="field-group-' . esc_attr($group_id) . '">';
                echo '<h3>' . esc_html($group['title']) . '</h3>';

                if (!empty($group['description'])) {
                    echo '<p class="description">' . esc_html($group['description']) . '</p>';
                }

                echo '<table class="form-table">';
            }

            foreach ($group['fields'] as $field) {
                echo '<tr valign="top">';
                echo '<th scope="row">' . esc_html($field['label'] ?? '') . '</th>';
                echo '<td>';
                call_user_func($field_callback, array_merge($field, $args));
                echo '</td>';
                echo '</tr>';
            }

            if (!empty($group['title'])) {
                echo '</table>';
                echo '</div>';
            }
        }
    }

}
