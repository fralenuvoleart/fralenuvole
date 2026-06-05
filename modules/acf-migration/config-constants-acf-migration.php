<?php
/**
 * ACF Migration — Configuration Constants
 *
 * Module: ACPT → SCF/ACF Field Migration
 * Package: Fralenuvole
 *
 * @since 5.9.0
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

// ─── Batch processing ───────────────────────────────────────────

const ACF_MIGRATION_BATCH_SIZE = 100;
const ACF_MIGRATION_VALIDATION_SAMPLE_SIZE = 20;
const ACF_MIGRATION_DEFAULT_DRY_RUN = true;
const ACF_MIGRATION_SHIM_ENABLED_BY_DEFAULT = true;

// ─── Storage — all use frl_ prefix ─────────────────────────────

/**
 * Custom table: migration history and rollback data.
 *
 * Schema:
 *   id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
 *   migration_key VARCHAR(20)   NOT NULL  — Ymd_His identifier
 *   created_at    DATETIME      NOT NULL  — UTC timestamp
 *   status        VARCHAR(30)   NOT NULL  — in_progress|completed|completed_with_errors|rolled_back
 *   ufj_file      VARCHAR(500)  DEFAULT '' — path to UFJ file used
 *   summary       LONGTEXT      DEFAULT NULL — JSON: stats, group/field counts, repeater totals
 *   rollback_data LONGTEXT      DEFAULT NULL — JSON: group_ids[], field_ids[], meta patterns
 *   errors        LONGTEXT      DEFAULT NULL — JSON: error messages array
 */
const ACF_MIGRATION_TABLE_LOG = 'frl_acpt_migration_log';

/**
 * Custom table: ACPT repeater data backups.
 *
 * Schema:
 *   id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
 *   post_id       BIGINT UNSIGNED NOT NULL
 *   field_name    VARCHAR(255)    NOT NULL  — the repeater meta_key
 *   acpt_data     LONGTEXT        NOT NULL  — serialized ACPT columnar array
 *   created_at    DATETIME        NOT NULL  — UTC timestamp
 *   migration_key VARCHAR(20)     NOT NULL  — links to frl_acpt_migration_log
 *
 * INDEX: uk_backup (post_id, field_name) — one backup per field per post
 * INDEX: idx_migration (migration_key)
 */
const ACF_MIGRATION_TABLE_BACKUP = 'frl_acpt_backup';

/**
 * Custom table: ACPT field name → SCF field key mapping.
 *
 * Schema:
 *   id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
 *   field_name    VARCHAR(255)    NOT NULL  — UFJ/Acpt field name (meta_key)
 *   field_key     VARCHAR(30)     NOT NULL  — SCF field key (field_XXXXXXXXXXXX)
 *   group_key     VARCHAR(30)     DEFAULT '' — SCF group key if applicable
 *   is_repeater   TINYINT(1)      DEFAULT 0
 *   parent_name   VARCHAR(255)    DEFAULT '' — parent repeater name for sub-fields
 *   created_at    DATETIME        NOT NULL  — UTC timestamp
 *
 * INDEX: uk_field_name (field_name) UNIQUE — idempotent lookups
 * INDEX: idx_field_key (field_key)
 */
const ACF_MIGRATION_TABLE_FIELD_MAP = 'frl_acpt_field_map';

/**
 * WordPress option key for the shim toggle (single boolean — trivial).
 */
const ACF_MIGRATION_SHIM_OPTION = 'frl_acpt_compat_shim';

/**
 * Cache group for migration-internal caching.
 */
const ACF_MIGRATION_CACHE_GROUP = 'frl_acf_migration';

// ─── Field key generation ──────────────────────────────────────

const ACF_MIGRATION_GROUP_KEY_PREFIX = 'group_';
const ACF_MIGRATION_FIELD_KEY_PREFIX = 'field_';
const ACF_MIGRATION_KEY_ENTROPY_BYTES = 6;

// ─── Post types ─────────────────────────────────────────────────

const ACF_MIGRATION_POST_TYPE_GROUP = 'acf-field-group';
const ACF_MIGRATION_POST_TYPE_FIELD  = 'acf-field';

// ─── UFJ version ────────────────────────────────────────────────

const ACF_MIGRATION_UFJ_VERSION = '1.0';
