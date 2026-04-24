<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fralenuvole Tab Registry
 *
 * Handles tab registration, ordering, section/field association, and validation rules.
 * This is an internal helper class used by Frl_Tab_Manager.
 *
 * @internal Not intended for direct use - use Frl_Tab_Manager facade
 */
class Frl_Tab_Registry
{
    // Position constants - reference Frl_Tab_Manager to avoid duplication
    // Using direct values to avoid class loading dependency
    const POSITION_FIRST = 0;
    const POSITION_DEFAULT = 500;
    const POSITION_FORM = 1000;
    const POSITION_LAST = PHP_INT_MAX;

    /**
     * Tab registry storage
     *
     * @var array
     */
    private $tab_registry = [
        'form' => [],
        'custom' => []
    ];

    /**
     * Cache for sorted tabs
     *
     * @var array|null
     */
    private $sorted_tabs = null;

    /**
     * Register a tab
     *
     * @param string $tab_id
     * @param array $args
     * @param string $type
     * @return void
     */
    public function register($tab_id, $args = [], $type = 'custom')
    {
        if ($type === 'custom') {
            $this->register_custom($tab_id, $args);
            return;
        }

        $defaults = [
            'title' => '',
            'description' => '',
            'callback' => null,
            'priority' => 10,
            'title_priority' => 5,
            'position' => self::POSITION_FORM + count($this->tab_registry[$type])
        ];

        $args = wp_parse_args($args, $defaults);
        $args['title'] = apply_filters(FRL_PREFIX . '_' . $tab_id . '_tab_title', $args['title']);
        $args['description'] = apply_filters(FRL_PREFIX . '_' . $tab_id . '_tab_description', $args['description']);

        $this->tab_registry[$type][$tab_id] = $args;
        $this->sorted_tabs = null;
    }

    /**
     * Register a custom tab with hooks
     *
     * @param string $tab_id
     * @param array $args
     * @return void
     */
    public function register_custom($tab_id, $args = [])
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
        $title = apply_filters(FRL_PREFIX . '_' . $tab_id . '_tab_title', $args['title']);
        $description = apply_filters(FRL_PREFIX . '_' . $tab_id . '_tab_description', $args['description']);
        $action_hook = FRL_PREFIX . '_' . $tab_id . '_content';

        if (!empty($title)) {
            add_action($action_hook, function () use ($title, $description) {
                echo '<h2>' . esc_html($title) . '</h2>';
                if (!empty($description)) {
                    echo '<p>' . esc_html($description) . '</p>';
                }
            }, $args['title_priority'], 0);
        }

        if (!empty($args['callback'])) {
            $callback = $args['callback'];
            if (is_string($callback) && function_exists($callback)) {
                add_action($action_hook, $callback, $args['priority'], 1);
            } else {
                add_action($action_hook, function () use ($callback, $tab_id) {
                    if (is_callable($callback)) {
                        call_user_func($callback);
                    } elseif (defined('WP_DEBUG') && WP_DEBUG) {
                        echo '<p class="error">Error: Callback "' . esc_html(is_string($callback) ? $callback : 'Object') . '" for tab "' . esc_html($tab_id) . '" is not callable.</p>';
                    }
                }, $args['priority'], 2);
            }
        }

        $this->tab_registry['custom'][$tab_id] = array_merge($args, [
            'title' => $title,
            'description' => $description
        ]);
        $this->sorted_tabs = null;
    }

    /**
     * Get tabs by type
     *
     * @param string|null $type
     * @return array
     */
    public function get_tabs($type = null)
    {
        if ($type !== null && isset($this->tab_registry[$type])) {
            return $this->tab_registry[$type];
        }
        return $this->tab_registry;
    }

    /**
     * Get sorted tabs
     *
     * @return array
     */
    public function get_sorted_tabs()
    {
        if ($this->sorted_tabs !== null) {
            return $this->sorted_tabs;
        }

        $all_tabs = [];

        foreach ($this->tab_registry['custom'] as $tab_id => $config) {
            $position = isset($config['position']) ? (int)$config['position'] : self::POSITION_DEFAULT;
            $all_tabs[] = [
                'id' => $tab_id,
                'type' => 'custom',
                'config' => $config,
                'position' => $position
            ];
        }

        foreach ($this->tab_registry['form'] as $tab_id => $config) {
            $position = isset($config['position']) ? (int)$config['position'] : self::POSITION_FORM;
            $all_tabs[] = [
                'id' => $tab_id,
                'type' => 'form',
                'config' => $config,
                'position' => $position
            ];
        }

        usort($all_tabs, function ($a, $b) {
            return $a['position'] - $b['position'];
        });

        $this->sorted_tabs = $all_tabs;
        return $this->sorted_tabs;
    }

    /**
     * Associate sections with a tab
     *
     * @param string $tab_id
     * @param array $sections
     * @return bool
     */
    public function associate_sections($tab_id, array $sections)
    {
        $tab_exists = isset($this->tab_registry['form'][$tab_id]) ||
            isset($this->tab_registry['custom'][$tab_id]);

        if (!$tab_exists) {
            return false;
        }

        $tab_type = isset($this->tab_registry['form'][$tab_id]) ? 'form' : 'custom';

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
     * Get sections for a tab
     *
     * @param string $tab_id
     * @return array|null
     */
    public function get_sections($tab_id)
    {
        if (isset($this->tab_registry['form'][$tab_id])) {
            return $this->tab_registry['form'][$tab_id]['sections'] ?? [];
        }
        if (isset($this->tab_registry['custom'][$tab_id])) {
            return $this->tab_registry['custom'][$tab_id]['sections'] ?? [];
        }
        return null;
    }

    /**
     * Add field group to a tab
     *
     * @param string $tab_id
     * @param array $field_group
     * @return bool
     */
    public function add_field_group($tab_id, array $field_group)
    {
        $tab_exists = isset($this->tab_registry['form'][$tab_id]) ||
            isset($this->tab_registry['custom'][$tab_id]);

        if (!$tab_exists) {
            return false;
        }

        if (!isset($field_group['id']) || !frl_is_array_not_empty($field_group, 'fields')) {
            return false;
        }

        $tab_type = isset($this->tab_registry['form'][$tab_id]) ? 'form' : 'custom';

        if (!isset($this->tab_registry[$tab_type][$tab_id]['field_groups'])) {
            $this->tab_registry[$tab_type][$tab_id]['field_groups'] = [];
        }

        $this->tab_registry[$tab_type][$tab_id]['field_groups'][$field_group['id']] = $field_group;
        return true;
    }

    /**
     * Get field groups for a tab
     *
     * @param string $tab_id
     * @return array|null
     */
    public function get_field_groups($tab_id)
    {
        if (isset($this->tab_registry['form'][$tab_id])) {
            return $this->tab_registry['form'][$tab_id]['field_groups'] ?? [];
        }
        if (isset($this->tab_registry['custom'][$tab_id])) {
            return $this->tab_registry['custom'][$tab_id]['field_groups'] ?? [];
        }
        return null;
    }

    /**
     * Set validation rules for a tab
     *
     * @param string $tab_id
     * @param array $validation_rules
     * @return bool
     */
    public function set_validation_rules($tab_id, array $validation_rules)
    {
        $tab_exists = isset($this->tab_registry['form'][$tab_id]) ||
            isset($this->tab_registry['custom'][$tab_id]);

        if (!$tab_exists) {
            return false;
        }

        $tab_type = isset($this->tab_registry['form'][$tab_id]) ? 'form' : 'custom';
        $this->tab_registry[$tab_type][$tab_id]['validation_rules'] = $validation_rules;
        return true;
    }

    /**
     * Get validation rules for a tab
     *
     * @param string $tab_id
     * @return array|null
     */
    public function get_validation_rules($tab_id)
    {
        if (isset($this->tab_registry['form'][$tab_id])) {
            return $this->tab_registry['form'][$tab_id]['validation_rules'] ?? [];
        }
        if (isset($this->tab_registry['custom'][$tab_id])) {
            return $this->tab_registry['custom'][$tab_id]['validation_rules'] ?? [];
        }
        return null;
    }

    /**
     * Invalidate sorted tabs cache
     *
     * @return void
     */
    public function invalidate_cache()
    {
        $this->sorted_tabs = null;
    }
}
