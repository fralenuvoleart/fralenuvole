<?php
/**
 * Migration Validator
 *
 * Post-migration verification: compares get_post_meta() values
 * against get_field() values to confirm data integrity.
 *
 * Standalone class — requires WordPress but zero fralenuvole deps.
 *
 * @package Fralenuvole
 * @since  5.9.0
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Validates migration integrity by sampling posts and comparing values.
 */
class Frl_Migration_Validator {

    /**
     * UFJ field group data.
     *
     * @var array
     */
    private array $ufj_groups;

    /**
     * Repeater configurations for deep validation.
     *
     * @var array<string, array>
     */
    private array $repeater_configs;

    /**
     * Number of posts to sample per field group.
     *
     * @var int
     */
    private int $sample_size;

    /**
     * Accumulated report.
     *
     * @var array
     */
    private array $report = [
        'total_checks'  => 0,
        'passed'        => 0,
        'failed'        => 0,
        'skipped'       => 0,
        'failures'      => [],
    ];

    // ─── Constructor ────────────────────────────────────────────

    /**
     * @param array $ufj_groups       The UFJ group definitions.
     * @param array $repeater_configs Repeater configurations from the Importer.
     * @param int   $sample_size      How many posts to sample per field group.
     */
    public function __construct(array $ufj_groups, array $repeater_configs = [], int $sample_size = 20) {
        $this->ufj_groups       = $ufj_groups;
        $this->repeater_configs = $repeater_configs;
        $this->sample_size      = max(1, $sample_size);
    }

    // ─── Public API ─────────────────────────────────────────────

    /**
     * Run all validations.
     *
     * @return array{passed: bool, report: array}
     */
    public function validate(): array {
        $this->validate_all_fields();
        $this->validate_repeaters();
        $this->validate_options_pages();

        return [
            'passed' => $this->report['failed'] === 0,
            'report' => $this->report,
        ];
    }

    /**
     * Get the raw report.
     *
     * @return array
     */
    public function get_report(): array {
        return $this->report;
    }

    // ─── Simple field validation ────────────────────────────────

    /**
     * Validate simple (non-repeater) fields across all groups.
     */
    private function validate_all_fields(): void {
        foreach ($this->ufj_groups as $group) {
            foreach (($group['boxes'] ?? []) as $box) {
                foreach (($box['fields'] ?? []) as $field) {
                    $type = $field['type'] ?? '';

                    // Skip repeaters — validated separately
                    if ($type === 'repeater') {
                        continue;
                    }

                    $this->validate_simple_field($field, $group);
                }
            }
        }
    }

    /**
     * Validate a single simple field.
     *
     * @param array $field UFJ field data.
     * @param array $group UFJ group data (for location rules).
     */
    private function validate_simple_field(array $field, array $group): void {
        $field_name = $field['name'] ?? '';
        if ($field_name === '') {
            return;
        }

        $post_ids = $this->get_sample_post_ids($group, $field_name);
        if (empty($post_ids)) {
            $this->report['skipped']++;
            return;
        }

        foreach ($post_ids as $post_id) {
            $this->report['total_checks']++;

            $meta_value  = get_post_meta($post_id, $field_name, true);
            $field_value = function_exists('get_field')
                ? get_field($field_name, $post_id, true)
                : $meta_value;

            if ($this->values_match($meta_value, $field_value, $field['type'] ?? 'text')) {
                $this->report['passed']++;
            } else {
                $this->report['failed']++;
                $this->report['failures'][] = [
                    'field_name' => $field_name,
                    'post_id'    => $post_id,
                    'meta_value' => $this->truncate_value($meta_value),
                    'acf_value'  => $this->truncate_value($field_value),
                    'type'       => 'simple',
                ];
            }
        }
    }

    // ─── Repeater validation ────────────────────────────────────

    /**
     * Validate repeater field data integrity.
     */
    private function validate_repeaters(): void {
        foreach ($this->repeater_configs as $repeater_name => $config) {
            $this->validate_single_repeater($repeater_name, $config);
        }
    }

    /**
     * Validate a single repeater across its posts.
     *
     * @param string $repeater_name
     * @param array  $config
     */
    private function validate_single_repeater(string $repeater_name, array $config): void {
        global $wpdb;

        // Find posts that have SCF row-indexed data
        $post_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
            $wpdb->esc_like("{$repeater_name}_0_") . '%'
        ));

        if (empty($post_ids)) {
            $this->report['skipped']++;
            return;
        }

        // Sample
        $sample = array_slice($post_ids, 0, $this->sample_size);
        $sub_fields = $config['sub_fields'] ?? [];

        foreach ($sample as $post_id) {
            $this->report['total_checks']++;

            $row_count = (int) get_post_meta($post_id, $repeater_name, true);
            $valid     = true;

            for ($i = 0; $i < min($row_count, 5); $i++) { // Check first 5 rows
                foreach ($sub_fields as $sub_name => $sub_config) {
                    $row_key = "{$repeater_name}_{$i}_{$sub_name}";
                    $value   = get_post_meta($post_id, $row_key, true);

                    // Value should exist for populated rows
                    if ($i < $row_count && $value === '' && $value !== '0') {
                        $valid = false;
                        break 2;
                    }
                }
            }

            if ($valid) {
                $this->report['passed']++;
            } else {
                $this->report['failed']++;
                $this->report['failures'][] = [
                    'field'    => $repeater_name,
                    'post_id'  => $post_id,
                    'meta'     => "row_count={$row_count}",
                    'type'     => 'repeater',
                ];
            }
        }
    }

    // ─── Options page validation ────────────────────────────────

    /**
     * Validate options page field values.
     */
    private function validate_options_pages(): void {
        foreach ($this->ufj_groups as $group) {
            $location = $group['location'] ?? [];

            // Check if this group is assigned to an options page
            $is_options = false;
            foreach ($location as $rule) {
                if (($rule['param'] ?? '') === 'options_page') {
                    $is_options = true;
                    break;
                }
            }

            if (!$is_options) {
                continue;
            }

            foreach (($group['boxes'] ?? []) as $box) {
                foreach (($box['fields'] ?? []) as $field) {
                    $name = $field['name'] ?? '';
                    if ($name === '' || ($field['type'] ?? '') === 'repeater') {
                        continue;
                    }

                    $this->report['total_checks']++;
                    $option_name = "options_{$name}";
                    $option_value = get_option($option_name, null);

                    if ($option_value === null) {
                        $this->report['skipped']++;
                    } else {
                        $this->report['passed']++;
                    }
                }
            }
        }
    }

    // ─── Helpers ────────────────────────────────────────────────

    /**
     * Get sample post IDs for a field.
     *
     * @param array  $group      UFJ group data.
     * @param string $field_name Meta key.
     * @return array<int>
     */
    private function get_sample_post_ids(array $group, string $field_name): array {
        global $wpdb;

        $post_type = $this->get_post_type_from_location($group);
        $where     = $post_type
            ? $wpdb->prepare(" AND p.post_type = %s", $post_type)
            : '';

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT pm.post_id FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = %s{$where}
             ORDER BY pm.meta_id DESC
             LIMIT %d",
            $field_name,
            $this->sample_size
        ));

        return array_map('intval', $ids ?? []);
    }

    /**
     * Extract post_type from UFJ location rules.
     *
     * @param array $group
     * @return string|null
     */
    private function get_post_type_from_location(array $group): ?string {
        foreach (($group['location'] ?? []) as $rule) {
            if (($rule['param'] ?? '') === 'post_type') {
                return $rule['value'] ?? null;
            }
        }
        return null;
    }

    /**
     * Compare two values for equality, considering type differences.
     *
     * @param mixed  $a   get_post_meta() value.
     * @param mixed  $b   get_field() value.
     * @param string $type UFJ field type.
     * @return bool
     */
    private function values_match($a, $b, string $type): bool {
        // Both null/empty/false → match
        if (empty($a) && empty($b)) {
            return true;
        }

        // Direct match
        if ($a === $b) {
            return true;
        }

        // String comparisons (ignore trailing whitespace differences)
        if (is_string($a) && is_string($b) && trim($a) === trim($b)) {
            return true;
        }

        // Array comparisons (both are serialized differently but equivalent)
        if (is_array($a) && is_array($b) && serialize($a) === serialize($b)) {
            return true;
        }

        return false;
    }

    /**
     * Truncate a value for display in failure reports.
     *
     * @param mixed $value
     * @return string
     */
    private function truncate_value($value): string {
        if (is_null($value)) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value)) {
            $serialized = serialize($value);
            if (strlen($serialized) > 200) {
                return substr($serialized, 0, 197) . '...';
            }
            return $serialized;
        }
        $str = (string) $value;
        if (strlen($str) > 200) {
            return substr($str, 0, 197) . '...';
        }
        return $str;
    }
}
