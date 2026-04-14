<?php

if (!class_exists('frl_acf_field_icon')) :

    class frl_acf_field_icon extends acf_field
    {
        public function initialize()
        {
            $this->name = 'frl_icon';
            $this->label = __('Icon Selector', 'frl');
            $this->category = 'choice';
            $this->defaults = [
                'default_value' => '',
                'allow_null'    => 0,
                'ui'            => 0,
            ];
            $this->l10n = [
                'error' => __('Error! Please select an icon', 'frl'),
            ];

            // Support for conditional logic value labeling in ACF UI
            add_filter('acf/conditional_logic/choices', [$this, 'render_field_icon_conditional_choices'], 10, 3);
        }

        public function render_field_settings($field)
        {
            // default_value
            acf_render_field_setting($field, [
                'label' => __('Default Value', 'acf'),
                'instructions' => __('Appears when creating a new post', 'acf'),
                'type' => 'text',
                'name' => 'default_value',
            ]);
        }

        public function render_field_validation_settings($field)
        {
            // allow_null
            acf_render_field_setting($field, [
                'label' => __('Allow Null', 'acf'),
                'instructions' => '',
                'name' => 'allow_null',
                'type' => 'true_false',
                'ui' => 1,
            ]);
        }

        public function render_field_presentation_settings($field)
        {
            // ui
            acf_render_field_setting($field, [
                'label' => __('Stylized UI', 'acf'),
                'instructions' => __('Use a stylized checkbox using select2', 'acf'),
                'name' => 'ui',
                'type' => 'true_false',
                'ui' => 1,
            ]);
        }

        public function render_field($field)
        {
            $field['type'] = 'select';
            $field['ajax'] = 1; // This is always an AJAX field
            $field['placeholder'] = __('Select an icon', 'frl');
            $field['choices'] = [];

            if (!empty($field['value'])) {
                $field['choices'][$field['value']] = $this->get_icon_label($field['value']);
            }

            // Ensure an empty option exists when Allow Null is enabled so Select2 can show the clear (x) control
            if (!empty($field['allow_null'])) {
                $field['choices'] = array('' => '') + $field['choices'];
            }

            // Replicate the structure of the native acf_field_select
            $select = [
                'id' => $field['id'],
                'class' => $field['class'] . ' frl-icon-select-field',
                'name' => $field['name'],
                'data-ui' => $field['ui'],
                'data-ajax' => $field['ajax'],
                'data-placeholder' => $field['placeholder'],
                'data-allow_null' => $field['allow_null'],
                'value' => $field['value'],
                'choices' => $field['choices'],
            ];

            if ($field['ui']) {
                acf_hidden_input(['name' => $field['name']]);
            }

            acf_select_input($select);
        }

        private function get_icon_label($value)
        {
            if (empty($value) || !is_string($value)) {
                return '';
            }

            $parts = explode('/', $value);
            $filename = pathinfo(end($parts), PATHINFO_FILENAME);
            $folder_parts = array_slice($parts, 0, -1);
            $label_parts = [];

            foreach ($folder_parts as $part) {
                $label_parts[] = ucwords(str_replace(['-', '_'], ' ', $part));
            }
            $label_parts[] = ucwords(str_replace(['-', '_'], ' ', $filename));

            return implode(' / ', $label_parts);
        }

        public function load_value($value, $post_id, $field)
        {
            return $value;
        }

        public function update_value($value, $post_id, $field)
        {
            return $value;
        }

        public function format_value($value, $post_id, $field)
        {
            return $value;
        }

        public function format_value_for_rest($value, $post_id, $field)
        {
            return $this->format_value($value, $post_id, $field);
        }

        public function validate_rest_value($valid, $value, $field)
        {
            if (!is_string($value)) {
                return $valid;
            }

            // Basic security check: ensure it's a relative path ending in .svg
            // and contains no directory traversal.
            if ($value !== '') {
                if (defined('FRL_ICONS_COUNTER_TOKEN') && $value === FRL_ICONS_COUNTER_TOKEN) {
                    return $valid;
                }
                if (!str_ends_with($value, '.svg') || str_contains($value, '..')) {
                    return new WP_Error('rest_invalid_param', __('Invalid icon path.', 'frl'), ['param' => $field['name']]);
                }
            }

            return $valid;
        }

        public function get_rest_schema(array $field)
        {
            $schema = [
                'type' => ['string', 'null'],
                'description' => __('An SVG icon path.', 'frl'),
            ];

            if (isset($field['default_value']) && $field['default_value'] !== '') {
                $schema['default'] = $field['default_value'];
            }

            return $schema;
        }

        public function input_admin_enqueue_scripts()
        {
            $assets = [
                'admin-acf-icons-css' => 'modules/acf/acf-icon/assets/admin-acf-icons.css',
                'admin-acf-icons-js' => 'modules/acf/acf-icon/assets/admin-acf-icons.js',
            ];
            $deps = [
                'admin-acf-icons-js' => ['jquery', 'select2', 'acf-input']
            ];
            frl_enqueue_scripts($assets, 'acf_icon_admin', $deps);

            wp_localize_script(
                FRL_PREFIX . '-admin-acf-icons',
                'FRL_ICONS_CFG',
                [
                    'restRoot' => esc_url_raw(rest_url('frl/v1/')),
                    'restIcons' => esc_url_raw(rest_url('frl/v1/icons')),
                    'nonce' => wp_create_nonce('wp_rest'),
                    /** @var list<string> $roots */
                    'roots' => (defined('FRL_ICONS_FLAGS_ROOT') && is_array(FRL_ICONS_FLAGS_ROOT)) ? array_values(array_filter(FRL_ICONS_FLAGS_ROOT, 'strlen')) : [],
                    'counter' => (defined('FRL_ICONS_COUNTER_TOKEN') ? FRL_ICONS_COUNTER_TOKEN : '')
                ]
            );

            add_action('acf/render_field/type=' . $this->name, [$this, 'render_field_preview'], 10, 1);
        }

        // Enqueue field group editor helpers (conditional logic integration)
        public function field_group_admin_enqueue_scripts()
        {
            $handle = FRL_PREFIX . '-admin-acf-icons-conditions';
            $src = FRL_DIR_URL . 'modules/acf/acf-icon/assets/admin-acf-icons-conditions.js';
            wp_enqueue_script($handle, $src, ['acf-field-group', 'jquery'], null, true);
        }

        public function render_field_preview($field)
        {
            static $base_url = null;
            if ($base_url === null) {
                $base_url = FRL_DIR_URL . FRL_ICONS_RELATIVE_PATH;
            }

            $value = is_string($field['value'] ?? '') ? $field['value'] : '';
            if ($value === false || $value === null) {
                $value = '';
            }

            $src = $value !== '' ? $base_url . $value : '';

            echo '<div class="frl-acf-icon-preview" data-base="' . esc_attr($base_url) . '"';
            if ($value !== '') {
                echo ' data-current-value="' . esc_attr($value) . '"';
            }
            echo '>';
            if ($src !== '') {
                echo '<img alt="" src="' . esc_url($src) . '" />';
            }
            echo '</div>';
        }

        // Provide label choices in conditional logic UI for this field type
        public function render_field_icon_conditional_choices($choices, $conditional_field, $rule_value)
        {
            if (!is_array($conditional_field) || ($conditional_field['type'] ?? '') !== 'frl_icon') {
                return $choices;
            }
            $value = is_string($rule_value) ? trim($rule_value) : '';
            if ($value === '') {
                return $choices;
            }
            if (class_exists('FRL_Icon_Resolver')) {
                $label = FRL_Icon_Resolver::label_from_rel($value);
                if ($label !== '') {
                    return [$value => $label];
                }
            }
            return [$value => $value];
        }
    }

endif;
