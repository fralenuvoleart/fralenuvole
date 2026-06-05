<?php
/**
 * ACPT Migration — WP-CLI Commands
 *
 * All migration metadata stored in frl_ prefixed custom tables.
 *
 * @package Fralenuvole
 * @since  5.9.0
 */

if (!defined('ABSPATH')) { exit; }
if (!defined('WP_CLI') || !WP_CLI) { return; }

class Frl_Acpt_Migrate_Command {

    // ─── Export ─────────────────────────────────────────────────

    /**
     * Export ACPT field definitions to Universal Field JSON (UFJ).
     *
     * ## OPTIONS
     * --source=<path>  : Path to the ACPT export JSON file.
     * --output=<path>  : Where to write the UFJ output file.
     */
    public function export($args, $assoc_args) {
        $source = $assoc_args['source'] ?? '';
        $output = $assoc_args['output'] ?? '';
        if ($source === '' || $output === '') {
            WP_CLI::error('Both --source and --output are required.');
        }
        try {
            $parser = new Frl_Acpt_Parser($source);
            $parser->parse();
            if (class_exists('Frl_Ufj_Schema')) {
                $schema = new Frl_Ufj_Schema();
                $result = $schema->validate($parser->get_ufj());
                if (!$result['valid']) {
                    WP_CLI::warning('UFJ validation warnings:');
                    foreach ($result['errors'] as $err) { WP_CLI::line("  - {$err}"); }
                }
            }
            file_put_contents($output, $parser->to_json());
            $stats = $parser->get_stats();
            WP_CLI::success("UFJ exported to {$output}");
            WP_CLI::line(sprintf('  Groups: %d | Fields: %d | Repeaters: %d | Option pages: %d',
                $stats['groups'], $stats['total_fields'], $stats['total_repeaters'], $stats['option_pages']));
        } catch (\Exception $e) {
            WP_CLI::error($e->getMessage());
        }
    }

    // ─── Import ─────────────────────────────────────────────────

    /**
     * Import UFJ data into SCF/ACF.
     *
     * ## OPTIONS
     * --file=<path>  : Path to the UFJ JSON file.
     * [--dry-run]     : Preview only — no database writes.
     * [--no-shim]     : Do not enable the backward-compatibility shim after import.
     */
    public function import($args, $assoc_args) {
        $file    = $assoc_args['file'] ?? '';
        $dry_run = !empty($assoc_args['dry-run']);
        $no_shim = !empty($assoc_args['no-shim']);

        if ($file === '' || !file_exists($file)) { WP_CLI::error("UFJ file not found: {$file}"); }

        $ufj = json_decode(file_get_contents($file), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            WP_CLI::error('UFJ JSON parse error: ' . json_last_error_msg());
        }

        $schema = new Frl_Ufj_Schema();
        $result = $schema->validate($ufj);
        if (!$result['valid']) {
            WP_CLI::error_multi_line(array_merge(['UFJ validation failed:'], $result['errors']));
        }

        WP_CLI::log($dry_run ? 'DRY RUN — no database writes.' : 'Starting import...');

        // 1. Create SCF field groups + fields, write field_map
        WP_CLI::log('Creating field groups and field definitions...');
        $importer = new Frl_Scf_Importer($ufj, $dry_run);
        $import_result = $importer->run();

        if ($dry_run) {
            WP_CLI::success('Dry-run complete. Would create:');
            WP_CLI::line(sprintf('  Field groups: %d', count($import_result['groups'])));
            WP_CLI::line(sprintf('  Fields: %d', count($import_result['fields'])));
            WP_CLI::line(sprintf('  Repeaters: %d', count($import_result['repeaters'])));
            return;
        }

        $migration_key = gmdate('Ymd_His');

        WP_CLI::success(sprintf('Created %d field groups with %d fields.',
            count($import_result['groups']), count($import_result['fields'])));

        if (!empty($import_result['log_entry']['errors'])) {
            WP_CLI::warning('Field group creation errors:');
            foreach ($import_result['log_entry']['errors'] as $err) { WP_CLI::line("  - {$err}"); }
        }

        // 2. Transform repeater data
        $repeater_configs = $importer->get_repeater_configs();
        if (!empty($repeater_configs)) {
            WP_CLI::log('Transforming repeater data (columnar → row-indexed)...');
            $transformer = new Frl_Repeater_Transformer($repeater_configs, $dry_run, $migration_key);
            $trans_result = $transformer->transform_all();
            $stats = $trans_result['stats'];
            WP_CLI::success(sprintf('Transformed %d posts (%d backups, %d meta rows).',
                $stats['posts_processed'], $stats['backups_created'], $stats['rows_created']));
            if (!empty($stats['errors'])) {
                WP_CLI::warning('Repeater errors:');
                foreach ($stats['errors'] as $err) { WP_CLI::line("  - {$err}"); }
            }
        }

        // 3. Enable shim
        if (!$no_shim && !empty($repeater_configs)) {
            WP_CLI::log('Enabling backward-compatibility shim...');
            $shim = new Frl_Acpt_Compat_Shim($repeater_configs);
            $shim->enable();
            update_option(ACF_MIGRATION_SHIM_OPTION, 1);
            WP_CLI::success('Shim enabled.');
        }

        // 4. Persist log to frl_acpt_migration_log
        $this->persist_log($migration_key, $file, $import_result['log_entry'], $repeater_configs);
    }

    // ─── Validate ───────────────────────────────────────────────

    /**
     * Validate migration integrity.
     *
     * ## OPTIONS
     * --file=<path>     : Path to the UFJ JSON file used for the import.
     * [--sample=<num>]  : Posts to sample per field group. Default: 20.
     */
    public function validate($args, $assoc_args) {
        $file   = $assoc_args['file'] ?? '';
        $sample = (int) ($assoc_args['sample'] ?? ACF_MIGRATION_VALIDATION_SAMPLE_SIZE);
        if ($file === '' || !file_exists($file)) { WP_CLI::error("UFJ file not found: {$file}"); }

        $ufj     = json_decode(file_get_contents($file), true);
        $configs = $this->get_repeater_configs_from_field_map();

        $validator = new Frl_Migration_Validator($ufj['groups'] ?? [], $configs, $sample);
        $result    = $validator->validate();
        $report    = $result['report'];

        WP_CLI::log('Validation Report:');
        WP_CLI::log(sprintf('  Total: %d | Passed: %d | Failed: %d | Skipped: %d',
            $report['total_checks'], $report['passed'], $report['failed'], $report['skipped']));

        if (!empty($report['failures'])) {
            WP_CLI::warning('Failures:');
            foreach (array_slice($report['failures'], 0, 20) as $fail) {
                WP_CLI::line(sprintf('  Field: %s | Post: %s | Type: %s',
                    $fail['field_name'] ?? $fail['field'] ?? '?',
                    $fail['post_id'] ?? 'N/A',
                    $fail['type'] ?? 'unknown'));
            }
        }

        if ($result['passed']) {
            WP_CLI::success('All checks passed.');
        } else {
            WP_CLI::error("{$report['failed']} checks failed.");
        }
    }

    // ─── Rollback ───────────────────────────────────────────────

    /**
     * Rollback the most recent migration.
     *
     * Reverses SCF field groups/fields via rollback_data in frl_acpt_migration_log.
     * Restores ACPT repeater blobs from frl_acpt_backup.
     */
    public function rollback($args, $assoc_args) {
        global $wpdb;
        $table_log = $wpdb->prefix . ACF_MIGRATION_TABLE_LOG;

        $row = $wpdb->get_row(
            "SELECT * FROM `{$table_log}` ORDER BY `created_at` DESC LIMIT 1",
            ARRAY_A
        );
        if (!$row) { WP_CLI::error('No migration log entries found.'); }

        $key = $row['migration_key'];
        WP_CLI::confirm("Rollback migration '{$key}'? This will delete all created SCF field groups and meta rows.");

        $rollback = json_decode($row['rollback_data'] ?? '{}', true);

        // Delete SCF field posts (cascades postmeta)
        foreach (($rollback['field_ids'] ?? []) as $id) { wp_delete_post((int) $id, true); }
        foreach (($rollback['group_ids'] ?? []) as $id) { wp_delete_post((int) $id, true); }
        // Delete reference meta rows tracked by meta_id
        foreach (($rollback['reference_meta_ids'] ?? []) as $mid) {
            delete_metadata_by_mid('post', (int) $mid);
        }

        // Restore ACPT repeater backups from frl_acpt_backup
        $table_backup = $wpdb->prefix . ACF_MIGRATION_TABLE_BACKUP;
        $backups = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM `{$table_backup}` WHERE `migration_key` = %s", $key
        ), ARRAY_A);

        $restored = 0;
        foreach ((array) $backups as $b) {
            $data = @unserialize($b['acpt_data']);
            if (is_array($data)) {
                update_post_meta((int) $b['post_id'], $b['field_name'], $data);
                delete_post_meta((int) $b['post_id'], '_' . $b['field_name']);
                $restored++;
            }
        }
        if ($restored > 0) { WP_CLI::log("Restored {$restored} ACPT repeater blobs."); }

        // Delete backup rows for this migration
        $wpdb->query($wpdb->prepare("DELETE FROM `{$table_backup}` WHERE `migration_key` = %s", $key));

        // Delete field_map entries
        $table_map = $wpdb->prefix . ACF_MIGRATION_TABLE_FIELD_MAP;
        $wpdb->query("TRUNCATE TABLE `{$table_map}`");

        // Delete log entry
        $wpdb->query($wpdb->prepare("DELETE FROM `{$table_log}` WHERE `migration_key` = %s", $key));

        // Disable shim
        update_option(ACF_MIGRATION_SHIM_OPTION, 0);

        WP_CLI::success("Rollback complete. Migration '{$key}' reversed.");
    }

    // ─── Cleanup ────────────────────────────────────────────────

    /**
     * Clean up ACPT data (tables and postmeta artifacts).
     *
     * WARNING: This drops ACPT tables. Ensure you have a DB backup.
     */
    public function cleanup($args, $assoc_args) {
        WP_CLI::confirm('WARNING: This will DELETE ACPT database tables and postmeta artifacts. Continue?');

        global $wpdb;

        // Drop ACPT tables
        $tables = ['wp_acpt_api_key', 'wp_acpt_belong', 'wp_acpt_block', 'wp_acpt_block_control'];
        foreach ($tables as $table) {
            $full = $wpdb->prefix . str_replace('wp_', '', $table);
            if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $full))) {
                $wpdb->query("DROP TABLE IF EXISTS `{$full}`");
                WP_CLI::log("Dropped: {$full}");
            }
        }

        // Remove ACPT postmeta artifacts using KNOWN field names from frl_acpt_field_map
        $table_map = $wpdb->prefix . ACF_MIGRATION_TABLE_FIELD_MAP;
        $names = $wpdb->get_col("SELECT DISTINCT `field_name` FROM `{$table_map}` WHERE `field_name` != ''");
        $deleted = 0;

        // acpt_wpml_config_* rows
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE 'acpt_wpml_config_%'");
        $deleted += (int) $wpdb->rows_affected;

        // Per-field _id and _type rows (only for known ACPT field names)
        foreach ((array) $names as $name) {
            if ($name === '' || !preg_match('/^[a-zA-Z]/', $name)) { continue; }
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", "{$name}_id"));
            $deleted += (int) $wpdb->rows_affected;
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", "{$name}_type"));
            $deleted += (int) $wpdb->rows_affected;
        }

        WP_CLI::log("Removed {$deleted} ACPT postmeta artifact rows.");
        WP_CLI::success('Cleanup complete.');
    }

    // ─── Shim ───────────────────────────────────────────────────

    /**
     * Enable or disable the backward-compatibility shim.
     *
     * ## OPTIONS
     * <on|off>
     */
    public function shim($args, $assoc_args) {
        $action = $args[0] ?? '';
        if (!in_array($action, ['on', 'off'], true)) { WP_CLI::error("Usage: wp acpt-migrate shim <on|off>"); }

        if ($action === 'on') {
            $configs = $this->get_repeater_configs_from_field_map();
            if (empty($configs)) { WP_CLI::error('No repeater configs found in frl_acpt_field_map. Run import first.'); }
            $shim = new Frl_Acpt_Compat_Shim($configs);
            $shim->enable();
            update_option(ACF_MIGRATION_SHIM_OPTION, 1);
            WP_CLI::success('Shim enabled.');
        } else {
            update_option(ACF_MIGRATION_SHIM_OPTION, 0);
            WP_CLI::success('Shim disabled.');
        }
    }

    // ─── Helpers (custom table based) ───────────────────────────

    private function persist_log(string $migration_key, string $ufj_file, array $log_entry, array $repeater_configs): void {
        global $wpdb;
        $table = $wpdb->prefix . ACF_MIGRATION_TABLE_LOG;

        $summary = wp_json_encode([
            'groups'    => count($log_entry['group_ids'] ?? []),
            'fields'    => count($log_entry['field_ids'] ?? []),
            'repeaters' => count($repeater_configs),
            'ref_rows'  => count($log_entry['reference_meta_ids'] ?? []),
            'errors'    => count($log_entry['errors'] ?? []),
        ]);

        $rollback = wp_json_encode([
            'group_ids'          => $log_entry['group_ids'] ?? [],
            'field_ids'          => $log_entry['field_ids'] ?? [],
            'reference_meta_ids' => $log_entry['reference_meta_ids'] ?? [],
            'field_key_map'      => $log_entry['field_key_map'] ?? [],
        ]);

        $errors = wp_json_encode($log_entry['errors'] ?? []);

        $wpdb->insert($table, [
            'migration_key' => $migration_key,
            'created_at'    => gmdate('Y-m-d H:i:s'),
            'status'        => $log_entry['status'] ?? 'completed',
            'ufj_file'      => $ufj_file,
            'summary'       => $summary,
            'rollback_data' => $rollback,
            'errors'        => $errors,
        ]);

        WP_CLI::log("Migration log saved under key: {$migration_key}");
    }

    /**
     * Rebuild repeater configs from frl_acpt_field_map (for shim/validation).
     */
    private function get_repeater_configs_from_field_map(): array {
        global $wpdb;
        $table_map = $wpdb->prefix . ACF_MIGRATION_TABLE_FIELD_MAP;
        $rows = $wpdb->get_results(
            "SELECT `field_name`, `field_key` FROM `{$table_map}` WHERE `is_repeater` = 1",
            ARRAY_A
        );
        if (empty($rows)) { return []; }

        $configs = [];
        foreach ((array) $rows as $row) {
            $name = $row['field_name'];
            $subs = $wpdb->get_results($wpdb->prepare(
                "SELECT `field_name`, `field_key`, `field_type` FROM `{$table_map}` WHERE `parent_name` = %s ORDER BY id ASC",
                $name
            ), ARRAY_A);

            $sub_fields = [];
            foreach ((array) $subs as $sub) {
                $sub_name = substr($sub['field_name'], strlen($name) + 1);
                $sub_fields[$sub_name] = [
                    'key'  => $sub['field_key'],
                    'type' => $sub['field_type'] ?? 'text',
                ];
            }

            $configs[$name] = [
                'name'       => $name,
                'label'      => $name,
                'key'        => $row['field_key'],
                'sub_fields' => $sub_fields,
            ];
        }
        return $configs;
    }
}

if (class_exists('WP_CLI')) {
    WP_CLI::add_command('acpt-migrate', 'Frl_Acpt_Migrate_Command');
}
