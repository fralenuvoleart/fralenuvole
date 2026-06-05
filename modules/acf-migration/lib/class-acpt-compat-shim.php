<?php
/**
 * ACPT Compatibility Shim
 *
 * Backward-compatibility layer that intercepts get_post_meta() calls
 * for migrated repeater fields and reconstructs the ACPT columnar
 * array format from SCF row-indexed rows.
 *
 * This allows third-party plugins to continue reading repeater data
 * via get_post_meta() while they are updated to use get_field().
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
 * Intercepts get_post_meta() for registered repeater fields and
 * returns ACPT-format columnar array data.
 */
class Frl_Acpt_Compat_Shim {

    /**
     * Repeater field names that need ACPT-format compatibility.
     *
     * @var array<string>
     */
    private array $repeater_fields = [];

    /**
     * Sub-field configurations per repeater.
     *
     * Keyed by repeater name, value is {sub_name: {key, type}}.
     *
     * @var array<string, array<string, array{key: string, type: string}>>
     */
    private array $repeater_sub_fields = [];

    /**
     * Whether the shim is currently active.
     *
     * @var bool
     */
    private bool $active = false;

    /**
     * Per-request cache of rebuilt ACPT data.
     * Key: "{$post_id}|{$meta_key}"
     *
     * @var array<string, array|null>
     */
    private array $cache = [];

    /**
     * Recursion guard: prevents infinite loops when rebuild_acpt_format()
     * calls get_post_meta() which triggers filter_post_meta() again.
     *
     * @var bool
     */
    private bool $rebuilding = false;

    // ─── Constructor ────────────────────────────────────────────

    /**
     * @param array $repeater_configs From Frl_Scf_Importer::get_repeater_configs().
     */
    public function __construct(array $repeater_configs = []) {
        if (!empty($repeater_configs)) {
            $this->configure($repeater_configs);
        }
    }

    /**
     * Configure (or reconfigure) the shim with repeater definitions.
     *
     * @param array $repeater_configs
     * @return void
     */
    public function configure(array $repeater_configs): void {
        $this->repeater_fields     = [];
        $this->repeater_sub_fields = [];

        foreach ($repeater_configs as $name => $config) {
            $this->repeater_fields[] = $name;
            $this->repeater_sub_fields[$name] = $config['sub_fields'] ?? [];
        }
    }

    // ─── Enable / Disable ───────────────────────────────────────

    /**
     * Enable the shim.
     *
     * Hooks into get_post_metadata at priority 100 (late — runs after
     * SCF/ACF has fully initialized).
     *
     * @return void
     */
    public function enable(): void {
        if ($this->active) {
            return;
        }

        add_filter('get_post_metadata', [$this, 'filter_post_meta'], 100, 4);
        $this->active = true;
    }

    /**
     * Disable the shim.
     *
     * @return void
     */
    public function disable(): void {
        if (!$this->active) {
            return;
        }

        remove_filter('get_post_metadata', [$this, 'filter_post_meta'], 100);
        $this->active = false;
        $this->cache   = []; // Clear cache on disable
    }

    /**
     * Check if the shim is active.
     *
     * @return bool
     */
    public function is_active(): bool {
        return $this->active;
    }

    // ─── Filter callback ────────────────────────────────────────

    /**
     * Intercepts get_post_meta() for registered repeater fields.
     *
     * @param mixed  $value    The current value (null = not yet intercepted).
     * @param int    $post_id  The post ID.
     * @param string $meta_key The meta key being requested.
     * @param bool   $single   Whether a single value is requested.
     * @return mixed Original value, or reconstructed ACPT array.
     */
    public function filter_post_meta($value, $post_id, $meta_key, $single) {
        // Bypass during admin, REST API, or cron requests.
        // The shim is a frontend compatibility layer only.
        if (is_admin() || (defined('REST_REQUEST') && REST_REQUEST) || wp_doing_cron()) {
            return $value;
        }

        // Recursion guard: if we're already rebuilding, pass through
        // to avoid infinite loop when rebuild_acpt_format() calls get_post_meta()
        if ($this->rebuilding) {
            return $value;
        }

        // Fast-fail: not a repeater we manage
        if (!in_array($meta_key, $this->repeater_fields, true)) {
            return $value;
        }

        // Only intercept single-value requests (the ACPT "blob" query)
        if (!$single) {
            return $value;
        }

        // Check cache first
        $cache_key = "{$post_id}|{$meta_key}";
        if (array_key_exists($cache_key, $this->cache)) {
            return $this->cache[$cache_key];
        }

        // Reconstruct ACPT columnar format
        $rebuilt = $this->rebuild_acpt_format((int) $post_id, $meta_key);
        $this->cache[$cache_key] = $rebuilt;

        return $rebuilt;
    }

    // ─── Core reconstruction ────────────────────────────────────

    /**
     * Reads SCF row-indexed meta rows and builds the ACPT columnar array.
     *
     * SCF storage:                         ACPT output:
     *   repeater = 2 (count)               {
     *   _repeater = field_XXX                question: [
     *   repeater_0_question = "Q1"             {original_name, type, value: "Q1"},
     *   _repeater_0_question = key             {original_name, type, value: "Q2"},
     *   repeater_0_answer = "A1"             ],
     *   _repeater_0_answer = key              answer: [
     *   repeater_1_question = "Q2"             {original_name, type, value: "A1"},
     *   _repeater_1_answer = "A2"              {original_name, type, value: "A2"},
     *                                         ]
     *                                       }
     *
     * @param int    $post_id  The post ID.
     * @param string $meta_key The repeater meta_key.
     * @return array The ACPT-format columnar array.
     */
    private function rebuild_acpt_format(int $post_id, string $meta_key): array {
        // Set recursion guard BEFORE any get_post_meta() calls
        $this->rebuilding = true;

        try {
            $row_count = (int) get_post_meta($post_id, $meta_key, true);

            if ($row_count <= 0) {
                return [];
            }

            $sub_fields = $this->repeater_sub_fields[$meta_key] ?? [];
            if (empty($sub_fields)) {
                return [];
            }

            $result = [];

            // Initialize columns
            foreach ($sub_fields as $name => $config) {
                $result[$name] = [];
            }

            // Read each row's sub-field values from SCF rows
            for ($i = 0; $i < $row_count; $i++) {
                foreach ($sub_fields as $name => $config) {
                    $row_key = "{$meta_key}_{$i}_{$name}";
                    $value   = get_post_meta($post_id, $row_key, true);

                    $result[$name][$i] = [
                        'original_name' => $name,
                        'type'          => $this->map_ufj_to_acpt_type($config['type'] ?? 'text'),
                        'value'         => (string) $value,
                    ];
                }
            }

            return $result;
        } finally {
            // Always reset the guard, even if an exception occurs
            $this->rebuilding = false;
        }
    }

    // ─── Helpers ────────────────────────────────────────────────

    /**
     * Maps UFJ type names back to ACPT type display names.
     *
     * @param string $ufj_type
     * @return string
     */
    private function map_ufj_to_acpt_type(string $ufj_type): string {
        // Use the parser's reverse map if available
        if (class_exists('Frl_Acpt_Parser')) {
            return Frl_Acpt_Parser::TYPE_REVERSE_MAP[$ufj_type] ?? 'Text';
        }

        return match($ufj_type) {
            'text'     => 'Text',
            'textarea' => 'Textarea',
            'wysiwyg'  => 'Editor',
            'number'   => 'Number',
            'email'    => 'Email',
            'url'      => 'Url',
            'select'   => 'Select',
            'radio'    => 'Radio',
            'checkbox' => 'Checkbox',
            default    => 'Text',
        };
    }
}
