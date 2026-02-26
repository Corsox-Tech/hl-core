<?php
/**
 * Database installation and schema management
 *
 * @package HL_Core
 */

if (!defined('ABSPATH')) {
    exit;
}

class HL_Installer {
    
    /**
     * Plugin activation
     */
    public static function activate() {
        self::create_tables();
        self::create_capabilities();
        self::set_default_options();
        
        // Flush rewrite rules
        flush_rewrite_rules();
        
        // Log activation
        do_action('hl_core_activated');
    }
    
    /**
     * Plugin deactivation
     */
    public static function deactivate() {
        // Flush rewrite rules
        flush_rewrite_rules();
        
        do_action('hl_core_deactivated');
    }
    
    /**
     * Run schema migrations then create/update tables.
     *
     * Called from activate() on activation, and also from
     * maybe_upgrade() on every page load when versions differ.
     */
    public static function create_tables() {
        global $wpdb;

        // Migrate legacy "program" naming to "cohort" if needed.
        self::migrate_program_to_cohort();

        // Add new pathway fields (description, objectives, etc.).
        self::migrate_pathway_add_fields();

        // Expand coaching session schema (session_title, meeting_url, session_status, etc.).
        self::migrate_coaching_session_expansion();

        // Add is_template column to hl_pathway.
        self::migrate_pathway_add_template();

        // Add cohort_group_id column to hl_cohort.
        self::migrate_cohort_add_group_id();

        // Add responses_json column to hl_teacher_assessment_instance.
        self::migrate_teacher_assessment_add_responses_json();

        // Add is_control_group column to hl_cohort.
        self::migrate_cohort_add_control_group();

        // Add activity_id and started_at columns to hl_teacher_assessment_instance.
        self::migrate_teacher_assessment_add_activity_id();

        // Add activity_id, phase, responses_json columns to hl_children_assessment_instance.
        self::migrate_children_assessment_add_fields();

        // Fix unique key on children_assessment_instance (include phase) and instrument_type enum→varchar.
        self::migrate_children_assessment_fix_keys();

        // Phase 22A: Rename center → school across all tables.
        self::migrate_center_to_school();

        // Phase 22B: Rename children_assessment → child_assessment tables + activity_type.
        self::migrate_children_to_child_assessment();

        // Phase 22C: Restructure cohort hierarchy — cohort→track, cohort_group→cohort.
        self::migrate_cohort_to_track();

        $charset_collate = $wpdb->get_charset_collate();
        $tables = self::get_schema();

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        foreach ($tables as $table_sql) {
            dbDelta($table_sql);
        }

        // Update schema version
        update_option('hl_core_db_version', HL_CORE_VERSION);
    }

    /**
     * Check if the schema needs an upgrade and run it.
     *
     * Hooked to plugins_loaded so that even without deactivate/reactivate
     * the migration runs when code is updated.
     */
    public static function maybe_upgrade() {
        $stored = get_option( 'hl_core_schema_revision', 0 );
        // Bump this number whenever a new migration is added.
        $current_revision = 12;

        if ( (int) $stored < $current_revision ) {
            self::create_tables();
            update_option( 'hl_core_schema_revision', $current_revision );
        }
    }

    /**
     * Migrate legacy "program" table/column names to "cohort".
     *
     * dbDelta cannot rename tables or columns, so this runs raw ALTER/RENAME
     * statements. Each statement is guarded by a check so it is safe to
     * run multiple times (idempotent).
     */
    private static function migrate_program_to_cohort() {
        global $wpdb;

        $prefix = $wpdb->prefix;

        // Helper: check if a table exists.
        $table_exists = function ( $name ) use ( $wpdb ) {
            return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ) === $name;
        };

        // Helper: check if a column exists on a table.
        $column_exists = function ( $table, $column ) use ( $wpdb ) {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                    $table,
                    $column
                )
            );
            return ! empty( $row );
        };

        // 1. Rename wp_hl_program → wp_hl_cohort (if old exists and new does not).
        $old_cohort = "{$prefix}hl_program";
        $new_cohort = "{$prefix}hl_cohort";

        if ( $table_exists( $old_cohort ) && ! $table_exists( $new_cohort ) ) {
            $wpdb->query( "RENAME TABLE `{$old_cohort}` TO `{$new_cohort}`" );
            // Rename columns inside the newly-renamed table.
            $wpdb->query( "ALTER TABLE `{$new_cohort}` CHANGE `program_id` `cohort_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT" );
            $wpdb->query( "ALTER TABLE `{$new_cohort}` CHANGE `program_uuid` `cohort_uuid` char(36) NOT NULL" );
            $wpdb->query( "ALTER TABLE `{$new_cohort}` CHANGE `program_code` `cohort_code` varchar(100) NOT NULL" );
            $wpdb->query( "ALTER TABLE `{$new_cohort}` CHANGE `program_name` `cohort_name` varchar(255) NOT NULL" );
            // Rename indexes — drop old, dbDelta will recreate with correct names.
            $wpdb->query( "ALTER TABLE `{$new_cohort}` DROP INDEX IF EXISTS `program_uuid`" );
            $wpdb->query( "ALTER TABLE `{$new_cohort}` DROP INDEX IF EXISTS `program_code`" );
        }

        // 2. Rename wp_hl_program_center → wp_hl_cohort_center.
        $old_cc = "{$prefix}hl_program_center";
        $new_cc = "{$prefix}hl_cohort_center";

        if ( $table_exists( $old_cc ) && ! $table_exists( $new_cc ) ) {
            $wpdb->query( "RENAME TABLE `{$old_cc}` TO `{$new_cc}`" );
            if ( $column_exists( $new_cc, 'program_id' ) ) {
                $wpdb->query( "ALTER TABLE `{$new_cc}` CHANGE `program_id` `cohort_id` bigint(20) unsigned NOT NULL" );
            }
            $wpdb->query( "ALTER TABLE `{$new_cc}` DROP INDEX IF EXISTS `program_center`" );
            $wpdb->query( "ALTER TABLE `{$new_cc}` DROP INDEX IF EXISTS `program_id`" );
        }

        // 3. Rename program_id → cohort_id on all other tables.
        // Value is the NULL constraint to preserve for each table.
        $tables_with_program_id = array(
            "{$prefix}hl_enrollment"                   => 'NOT NULL',
            "{$prefix}hl_pathway"                      => 'NOT NULL',
            "{$prefix}hl_activity"                     => 'NOT NULL',
            "{$prefix}hl_team"                         => 'NOT NULL',
            "{$prefix}hl_teacher_assessment_instance"  => 'NOT NULL',
            "{$prefix}hl_children_assessment_instance" => 'NOT NULL',
            "{$prefix}hl_observation"                  => 'NOT NULL',
            "{$prefix}hl_coaching_session"             => 'NOT NULL',
            "{$prefix}hl_import_run"                   => 'NULL',
            "{$prefix}hl_audit_log"                    => 'NULL',
        );

        foreach ( $tables_with_program_id as $table => $nullable ) {
            if ( $table_exists( $table ) && $column_exists( $table, 'program_id' ) ) {
                $wpdb->query( "ALTER TABLE `{$table}` CHANGE `program_id` `cohort_id` bigint(20) unsigned {$nullable}" );
                // Drop old index if it exists (named after program_id).
                $wpdb->query( "ALTER TABLE `{$table}` DROP INDEX IF EXISTS `program_id`" );
            }
        }

        // 4. Rename program_completion_percent → cohort_completion_percent on hl_completion_rollup.
        $rollup_table = "{$prefix}hl_completion_rollup";
        if ( $table_exists( $rollup_table ) && $column_exists( $rollup_table, 'program_id' ) ) {
            $wpdb->query( "ALTER TABLE `{$rollup_table}` CHANGE `program_id` `cohort_id` bigint(20) unsigned NOT NULL" );
            $wpdb->query( "ALTER TABLE `{$rollup_table}` DROP INDEX IF EXISTS `program_id`" );
        }
        if ( $table_exists( $rollup_table ) && $column_exists( $rollup_table, 'program_completion_percent' ) ) {
            $wpdb->query( "ALTER TABLE `{$rollup_table}` CHANGE `program_completion_percent` `cohort_completion_percent` decimal(5,2) NOT NULL DEFAULT 0.00" );
        }

        // 5. Rename unique key on enrollment: program_user → cohort_user.
        // dbDelta will recreate the correct unique key, so just drop the old one.
        $enrollment_table = "{$prefix}hl_enrollment";
        if ( $table_exists( $enrollment_table ) ) {
            // Check if old index exists.
            $old_idx = $wpdb->get_var( $wpdb->prepare(
                "SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = 'program_user' LIMIT 1",
                $enrollment_table
            ) );
            if ( $old_idx ) {
                $wpdb->query( "ALTER TABLE `{$enrollment_table}` DROP INDEX `program_user`" );
            }
        }

        // 6. Same for teacher_assessment_instance unique key.
        $tai_table = "{$prefix}hl_teacher_assessment_instance";
        if ( $table_exists( $tai_table ) ) {
            $old_idx = $wpdb->get_var( $wpdb->prepare(
                "SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = 'program_enrollment_phase' LIMIT 1",
                $tai_table
            ) );
            if ( $old_idx ) {
                $wpdb->query( "ALTER TABLE `{$tai_table}` DROP INDEX `program_enrollment_phase`" );
            }
        }

        // 7. Same for children_assessment_instance unique key.
        $cai_table = "{$prefix}hl_children_assessment_instance";
        if ( $table_exists( $cai_table ) ) {
            $old_idx = $wpdb->get_var( $wpdb->prepare(
                "SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = 'program_enrollment_classroom' LIMIT 1",
                $cai_table
            ) );
            if ( $old_idx ) {
                $wpdb->query( "ALTER TABLE `{$cai_table}` DROP INDEX `program_enrollment_classroom`" );
            }
        }
    }
    
    /**
     * Add new columns to hl_pathway for front-end participant experience.
     *
     * Columns: description, objectives, syllabus_url, featured_image_id,
     * avg_completion_time, expiration_date.
     *
     * Each column is added only if it does not already exist (idempotent).
     */
    private static function migrate_pathway_add_fields() {
        global $wpdb;

        $prefix = $wpdb->prefix;
        $table  = "{$prefix}hl_pathway";

        // Helper: check if a table exists.
        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
        if ( ! $table_exists ) {
            return; // Table will be created by dbDelta with the new columns.
        }

        // Helper: check if a column exists on a table.
        $column_exists = function ( $column ) use ( $wpdb, $table ) {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                    $table,
                    $column
                )
            );
            return ! empty( $row );
        };

        if ( ! $column_exists( 'description' ) ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN description longtext NULL AFTER pathway_code" );
        }
        if ( ! $column_exists( 'objectives' ) ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN objectives longtext NULL AFTER description" );
        }
        if ( ! $column_exists( 'syllabus_url' ) ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN syllabus_url varchar(500) NULL AFTER objectives" );
        }
        if ( ! $column_exists( 'featured_image_id' ) ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN featured_image_id bigint(20) unsigned NULL AFTER syllabus_url" );
        }
        if ( ! $column_exists( 'avg_completion_time' ) ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN avg_completion_time varchar(100) NULL AFTER featured_image_id" );
        }
        if ( ! $column_exists( 'expiration_date' ) ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN expiration_date date NULL AFTER avg_completion_time" );
        }
    }

    /**
     * Add new columns to hl_coaching_session for the expanded coaching model.
     *
     * New columns: session_title, meeting_url, session_status, cancelled_at,
     * rescheduled_from_session_id.
     *
     * Migrates existing attendance_status values to session_status:
     *   'attended' → 'attended', 'missed' → 'missed', 'unknown' → 'scheduled'.
     *
     * Each column is added only if it does not already exist (idempotent).
     */
    private static function migrate_coaching_session_expansion() {
        global $wpdb;

        $table = "{$wpdb->prefix}hl_coaching_session";

        // Check table exists.
        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
        if ( ! $table_exists ) {
            return; // Will be created by dbDelta with new columns.
        }

        // Helper: check if a column exists.
        $column_exists = function ( $column ) use ( $wpdb, $table ) {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                    $table,
                    $column
                )
            );
            return ! empty( $row );
        };

        if ( ! $column_exists( 'session_title' ) ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN session_title varchar(255) NULL AFTER mentor_enrollment_id" );
        }

        if ( ! $column_exists( 'meeting_url' ) ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN meeting_url varchar(500) NULL AFTER session_title" );
        }

        if ( ! $column_exists( 'session_status' ) ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN session_status enum('scheduled','attended','missed','cancelled','rescheduled') NOT NULL DEFAULT 'scheduled' AFTER meeting_url" );

            // Migrate existing attendance_status values.
            $wpdb->query( "UPDATE `{$table}` SET session_status = 'attended' WHERE attendance_status = 'attended'" );
            $wpdb->query( "UPDATE `{$table}` SET session_status = 'missed' WHERE attendance_status = 'missed'" );
            $wpdb->query( "UPDATE `{$table}` SET session_status = 'scheduled' WHERE attendance_status = 'unknown'" );
        }

        if ( ! $column_exists( 'cancelled_at' ) ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN cancelled_at datetime NULL AFTER notes_richtext" );
        }

        if ( ! $column_exists( 'rescheduled_from_session_id' ) ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN rescheduled_from_session_id bigint(20) unsigned NULL AFTER cancelled_at" );
        }
    }

    /**
     * Add is_template column to hl_pathway for template/clone feature.
     */
    private static function migrate_pathway_add_template() {
        global $wpdb;

        $table = "{$wpdb->prefix}hl_pathway";

        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
        if ( ! $table_exists ) {
            return;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                $table,
                'is_template'
            )
        );
        if ( empty( $row ) ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN is_template tinyint(1) NOT NULL DEFAULT 0 AFTER expiration_date" );
        }
    }

    /**
     * Add cohort_group_id column to hl_cohort for program-level grouping.
     */
    private static function migrate_cohort_add_group_id() {
        global $wpdb;

        $table = "{$wpdb->prefix}hl_cohort";

        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
        if ( ! $table_exists ) {
            return;
        }

        $col_check = function () use ( $wpdb, $table ) {
            return $wpdb->get_var( $wpdb->prepare(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                $table,
                'cohort_group_id'
            ) );
        };

        if ( empty( $col_check() ) ) {
            // Try with AFTER clause first, then without if it fails.
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `cohort_group_id` bigint(20) unsigned DEFAULT NULL AFTER `district_id`" );

            if ( ! empty( $wpdb->last_error ) ) {
                // Retry without AFTER clause — some MySQL versions on shared hosting reject it.
                $wpdb->last_error = '';
                $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `cohort_group_id` bigint(20) unsigned DEFAULT NULL" );
            }

            // Verify the column was actually added.
            if ( ! empty( $col_check() ) ) {
                $wpdb->query( "ALTER TABLE `{$table}` ADD INDEX `cohort_group_id` (`cohort_group_id`)" );
            } else {
                error_log( '[HL Core] CRITICAL: Failed to add cohort_group_id column to ' . $table . '. Last error: ' . $wpdb->last_error );
            }
        }
    }

    /**
     * Migration: Add responses_json column to hl_teacher_assessment_instance.
     *
     * Stores structured JSON responses for custom instrument-based assessments
     * (as opposed to JFB-powered assessments which store responses in JFB Form Records).
     */
    private static function migrate_teacher_assessment_add_responses_json() {
        global $wpdb;

        $table = "{$wpdb->prefix}hl_teacher_assessment_instance";

        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
        if ( ! $table_exists ) {
            return;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                $table,
                'responses_json'
            )
        );
        if ( empty( $row ) ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN responses_json longtext DEFAULT NULL AFTER jfb_record_id" );
        }
    }

    /**
     * Add is_control_group column to hl_cohort.
     */
    private static function migrate_cohort_add_control_group() {
        global $wpdb;

        $table = "{$wpdb->prefix}hl_cohort";

        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
        if ( ! $table_exists ) {
            return;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                $table,
                'is_control_group'
            )
        );
        if ( empty( $row ) ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN is_control_group tinyint(1) NOT NULL DEFAULT 0 AFTER cohort_group_id" );
        }
    }

    /**
     * Add activity_id, phase, responses_json to hl_children_assessment_instance.
     */
    private static function migrate_children_assessment_add_fields() {
        global $wpdb;

        $table = "{$wpdb->prefix}hl_children_assessment_instance";

        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
        if ( ! $table_exists ) {
            return;
        }

        $column_exists = function ( $col ) use ( $wpdb, $table ) {
            return ! empty( $wpdb->get_row( $wpdb->prepare(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                $table, $col
            ) ) );
        };

        if ( ! $column_exists( 'activity_id' ) ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN activity_id bigint(20) unsigned NULL AFTER enrollment_id" );
        }

        if ( ! $column_exists( 'phase' ) ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN phase enum('pre','post') NULL AFTER school_id" );
        }

        if ( ! $column_exists( 'responses_json' ) ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN responses_json longtext DEFAULT NULL AFTER instrument_version" );
        }

        if ( ! $column_exists( 'started_at' ) ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN started_at datetime NULL AFTER status" );
        }

        // Make classroom_id nullable (new approach: one instance per teacher per phase)
        $wpdb->query( "ALTER TABLE `{$table}` MODIFY COLUMN classroom_id bigint(20) unsigned NULL" );

        // Change instrument_age_band from enum to varchar to support 'k2' etc.
        $wpdb->query( "ALTER TABLE `{$table}` MODIFY COLUMN instrument_age_band varchar(20) NULL" );
    }

    /**
     * Fix the unique key on hl_children_assessment_instance to include phase
     * (so both PRE and POST instances can exist per enrollment+classroom).
     * Also change hl_instrument.instrument_type from enum to varchar(50).
     */
    private static function migrate_children_assessment_fix_keys() {
        global $wpdb;

        // 1. Fix unique key on children_assessment_instance.
        $cai_table = "{$wpdb->prefix}hl_children_assessment_instance";
        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $cai_table ) ) === $cai_table;

        if ( $table_exists ) {
            // Drop old unique key (without phase) if it exists.
            $old_key = $wpdb->get_var( $wpdb->prepare(
                "SELECT INDEX_NAME FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = 'cohort_enrollment_classroom'
                 LIMIT 1",
                $cai_table
            ) );
            if ( $old_key ) {
                $wpdb->query( "ALTER TABLE `{$cai_table}` DROP INDEX `cohort_enrollment_classroom`" );
            }

            // Add new unique key (with phase) if it doesn't exist.
            $new_key = $wpdb->get_var( $wpdb->prepare(
                "SELECT INDEX_NAME FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = 'cohort_enrollment_classroom_phase'
                 LIMIT 1",
                $cai_table
            ) );
            if ( ! $new_key ) {
                $wpdb->query( "ALTER TABLE `{$cai_table}` ADD UNIQUE KEY `cohort_enrollment_classroom_phase` (`cohort_id`, `enrollment_id`, `classroom_id`, `phase`)" );
            }
        }

        // 2. Change instrument_type from enum to varchar(50).
        $inst_table = "{$wpdb->prefix}hl_instrument";
        $inst_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $inst_table ) ) === $inst_table;

        if ( $inst_exists ) {
            $col_type = $wpdb->get_var( $wpdb->prepare(
                "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'instrument_type'",
                $inst_table
            ) );
            if ( $col_type && strpos( $col_type, 'enum' ) !== false ) {
                $wpdb->query( "ALTER TABLE `{$inst_table}` MODIFY COLUMN `instrument_type` varchar(50) NOT NULL" );
            }
        }
    }

    /**
     * Add activity_id and started_at columns to hl_teacher_assessment_instance.
     */
    private static function migrate_teacher_assessment_add_activity_id() {
        global $wpdb;

        $table = "{$wpdb->prefix}hl_teacher_assessment_instance";

        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
        if ( ! $table_exists ) {
            return;
        }

        $column_exists = function ( $col ) use ( $wpdb, $table ) {
            return ! empty( $wpdb->get_row( $wpdb->prepare(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                $table, $col
            ) ) );
        };

        if ( ! $column_exists( 'activity_id' ) ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN activity_id bigint(20) unsigned NULL AFTER enrollment_id" );
        }

        if ( ! $column_exists( 'started_at' ) ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN started_at datetime NULL AFTER status" );
        }
    }

    /**
     * Phase 22A: Rename "center" to "school" across all tables.
     *
     * 1. Rename table hl_cohort_center → hl_cohort_school
     * 2. Rename center_id → school_id in: hl_cohort_school, hl_enrollment,
     *    hl_team, hl_classroom, hl_child, hl_observation, hl_children_assessment_instance
     * 3. Update hl_orgunit.orgunit_type enum: 'center' → 'school'
     * 4. Update hl_coach_assignment.scope_type enum: 'center' → 'school'
     *
     * All operations are idempotent — safe to run multiple times.
     */
    private static function migrate_center_to_school() {
        global $wpdb;

        $prefix = $wpdb->prefix;

        // Helper: check if a table exists.
        $table_exists = function ( $name ) use ( $wpdb ) {
            return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ) === $name;
        };

        // Helper: check if a column exists on a table.
        $column_exists = function ( $table, $column ) use ( $wpdb ) {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                    $table,
                    $column
                )
            );
            return ! empty( $row );
        };

        // Helper: check if an index exists on a table.
        $index_exists = function ( $table, $index_name ) use ( $wpdb ) {
            return ! empty( $wpdb->get_var( $wpdb->prepare(
                "SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s LIMIT 1",
                $table,
                $index_name
            ) ) );
        };

        // ─── 1. Rename table hl_cohort_center → hl_cohort_school ────────
        $old_table = "{$prefix}hl_cohort_center";
        $new_table = "{$prefix}hl_cohort_school";

        if ( $table_exists( $old_table ) && ! $table_exists( $new_table ) ) {
            $wpdb->query( "RENAME TABLE `{$old_table}` TO `{$new_table}`" );
        }

        // Rename center_id → school_id inside hl_cohort_school.
        if ( $table_exists( $new_table ) && $column_exists( $new_table, 'center_id' ) ) {
            $wpdb->query( "ALTER TABLE `{$new_table}` CHANGE `center_id` `school_id` bigint(20) unsigned NOT NULL" );
            // Drop old indexes — dbDelta will recreate with correct names.
            if ( $index_exists( $new_table, 'cohort_center' ) ) {
                $wpdb->query( "ALTER TABLE `{$new_table}` DROP INDEX `cohort_center`" );
            }
            if ( $index_exists( $new_table, 'center_id' ) ) {
                $wpdb->query( "ALTER TABLE `{$new_table}` DROP INDEX `center_id`" );
            }
        }

        // ─── 2. Rename center_id → school_id in other tables ────────────
        $tables_with_center_id = array(
            "{$prefix}hl_enrollment"                   => 'NULL',
            "{$prefix}hl_team"                         => 'NOT NULL',
            "{$prefix}hl_classroom"                    => 'NOT NULL',
            "{$prefix}hl_child"                        => 'NOT NULL',
            "{$prefix}hl_observation"                  => 'NULL',
            "{$prefix}hl_children_assessment_instance" => 'NULL',
        );

        foreach ( $tables_with_center_id as $table => $nullable ) {
            if ( $table_exists( $table ) && $column_exists( $table, 'center_id' ) ) {
                $wpdb->query( "ALTER TABLE `{$table}` CHANGE `center_id` `school_id` bigint(20) unsigned {$nullable}" );
                // Drop old index — dbDelta will recreate.
                if ( $index_exists( $table, 'center_id' ) ) {
                    $wpdb->query( "ALTER TABLE `{$table}` DROP INDEX `center_id`" );
                }
            }
        }

        // Drop old composite unique key center_classroom on hl_classroom — dbDelta will recreate as school_classroom.
        $classroom_table = "{$prefix}hl_classroom";
        if ( $table_exists( $classroom_table ) && $index_exists( $classroom_table, 'center_classroom' ) ) {
            $wpdb->query( "ALTER TABLE `{$classroom_table}` DROP INDEX `center_classroom`" );
        }

        // ─── 3. Update hl_orgunit.orgunit_type enum: 'center' → 'school' ─
        $orgunit_table = "{$prefix}hl_orgunit";
        if ( $table_exists( $orgunit_table ) ) {
            // First update data, then alter the enum.
            $wpdb->query( "UPDATE `{$orgunit_table}` SET orgunit_type = 'school' WHERE orgunit_type = 'center'" );
            // Alter enum to include 'school' and remove 'center'.
            $wpdb->query( "ALTER TABLE `{$orgunit_table}` MODIFY COLUMN orgunit_type enum('district','school') NOT NULL" );
        }

        // ─── 4. Update hl_coach_assignment.scope_type: 'center' → 'school' ─
        $ca_table = "{$prefix}hl_coach_assignment";
        if ( $table_exists( $ca_table ) && $column_exists( $ca_table, 'scope_type' ) ) {
            // Temporarily expand enum to include both values.
            $wpdb->query( "ALTER TABLE `{$ca_table}` MODIFY COLUMN scope_type enum('center','school','team','enrollment') NOT NULL" );
            // Update data.
            $wpdb->query( "UPDATE `{$ca_table}` SET scope_type = 'school' WHERE scope_type = 'center'" );
            // Shrink enum to final values.
            $wpdb->query( "ALTER TABLE `{$ca_table}` MODIFY COLUMN scope_type enum('school','team','enrollment') NOT NULL" );
        }
    }

    /**
     * Phase 22B: Rename "children_assessment" to "child_assessment".
     *
     * 1. Rename table hl_children_assessment_instance → hl_child_assessment_instance
     * 2. Rename table hl_children_assessment_childrow → hl_child_assessment_childrow
     * 3. Update hl_activity.activity_type: 'children_assessment' → 'child_assessment'
     *
     * All operations are idempotent — safe to run multiple times.
     */
    private static function migrate_children_to_child_assessment() {
        global $wpdb;

        $prefix = $wpdb->prefix;

        // Helper: check if a table exists.
        $table_exists = function ( $name ) use ( $wpdb ) {
            return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ) === $name;
        };

        // ─── 1. Rename hl_children_assessment_instance → hl_child_assessment_instance ─
        $old_instance = "{$prefix}hl_children_assessment_instance";
        $new_instance = "{$prefix}hl_child_assessment_instance";

        if ( $table_exists( $old_instance ) && ! $table_exists( $new_instance ) ) {
            $wpdb->query( "RENAME TABLE `{$old_instance}` TO `{$new_instance}`" );
        }

        // ─── 2. Rename hl_children_assessment_childrow → hl_child_assessment_childrow ─
        $old_childrow = "{$prefix}hl_children_assessment_childrow";
        $new_childrow = "{$prefix}hl_child_assessment_childrow";

        if ( $table_exists( $old_childrow ) && ! $table_exists( $new_childrow ) ) {
            $wpdb->query( "RENAME TABLE `{$old_childrow}` TO `{$new_childrow}`" );
        }

        // ─── 3. Update activity_type: 'children_assessment' → 'child_assessment' ─
        $activity_table = "{$prefix}hl_activity";
        if ( $table_exists( $activity_table ) ) {
            // Temporarily expand enum to include both values.
            $wpdb->query( "ALTER TABLE `{$activity_table}` MODIFY COLUMN activity_type enum('learndash_course','teacher_self_assessment','children_assessment','child_assessment','coaching_session_attendance','observation') NOT NULL" );
            // Update data.
            $wpdb->query( "UPDATE `{$activity_table}` SET activity_type = 'child_assessment' WHERE activity_type = 'children_assessment'" );
            // Shrink enum to final values.
            $wpdb->query( "ALTER TABLE `{$activity_table}` MODIFY COLUMN activity_type enum('learndash_course','teacher_self_assessment','child_assessment','coaching_session_attendance','observation') NOT NULL" );
        }
    }

    /**
     * Phase 22C: Restructure cohort hierarchy.
     *
     * Old `hl_cohort` (the run) → becomes `hl_track`
     * Old `hl_cohort_group` (the container) → becomes `hl_cohort`
     *
     * STEP 1: Rename hl_cohort → hl_track (run table)
     * STEP 2: Rename hl_cohort_group → hl_cohort (container table)
     * STEP 3: Rename hl_cohort_school → hl_track_school (join table)
     * STEP 4: Rename cohort_id → track_id in all dependent tables
     *
     * All operations are idempotent — safe to run multiple times.
     */
    private static function migrate_cohort_to_track() {
        global $wpdb;

        $prefix = $wpdb->prefix;

        // Helper: check if a table exists.
        $table_exists = function ( $name ) use ( $wpdb ) {
            return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ) === $name;
        };

        // Helper: check if a column exists on a table.
        $column_exists = function ( $table, $column ) use ( $wpdb ) {
            return ! empty( $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                    $table,
                    $column
                )
            ) );
        };

        // Helper: check if an index exists on a table.
        $index_exists = function ( $table, $index_name ) use ( $wpdb ) {
            return ! empty( $wpdb->get_var( $wpdb->prepare(
                "SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s LIMIT 1",
                $table,
                $index_name
            ) ) );
        };

        // ─── STEP 1: Rename hl_cohort → hl_track ─────────────────────────
        $old_cohort  = "{$prefix}hl_cohort";
        $new_track   = "{$prefix}hl_track";

        if ( $table_exists( $old_cohort ) && ! $table_exists( $new_track ) ) {
            $wpdb->query( "RENAME TABLE `{$old_cohort}` TO `{$new_track}`" );
        }

        // Rename columns inside hl_track.
        if ( $table_exists( $new_track ) ) {
            if ( $column_exists( $new_track, 'cohort_id' ) ) {
                $wpdb->query( "ALTER TABLE `{$new_track}` CHANGE `cohort_id` `track_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT" );
            }
            if ( $column_exists( $new_track, 'cohort_uuid' ) ) {
                $wpdb->query( "ALTER TABLE `{$new_track}` CHANGE `cohort_uuid` `track_uuid` char(36) NOT NULL" );
            }
            if ( $column_exists( $new_track, 'cohort_code' ) ) {
                $wpdb->query( "ALTER TABLE `{$new_track}` CHANGE `cohort_code` `track_code` varchar(100) NOT NULL" );
            }
            if ( $column_exists( $new_track, 'cohort_name' ) ) {
                $wpdb->query( "ALTER TABLE `{$new_track}` CHANGE `cohort_name` `track_name` varchar(255) NOT NULL" );
            }
            // cohort_group_id → cohort_id (FK to the new container table)
            if ( $column_exists( $new_track, 'cohort_group_id' ) ) {
                $wpdb->query( "ALTER TABLE `{$new_track}` CHANGE `cohort_group_id` `cohort_id` bigint(20) unsigned NULL" );
                // Drop old index — dbDelta will recreate.
                if ( $index_exists( $new_track, 'cohort_group_id' ) ) {
                    $wpdb->query( "ALTER TABLE `{$new_track}` DROP INDEX `cohort_group_id`" );
                }
            }
            // Drop old indexes that reference cohort_* names — dbDelta recreates.
            if ( $index_exists( $new_track, 'cohort_uuid' ) ) {
                $wpdb->query( "ALTER TABLE `{$new_track}` DROP INDEX `cohort_uuid`" );
            }
            if ( $index_exists( $new_track, 'cohort_code' ) ) {
                $wpdb->query( "ALTER TABLE `{$new_track}` DROP INDEX `cohort_code`" );
            }
        }

        // ─── STEP 2: Rename hl_cohort_group → hl_cohort ──────────────────
        $old_group = "{$prefix}hl_cohort_group";
        $new_cohort = "{$prefix}hl_cohort";

        if ( $table_exists( $old_group ) && ! $table_exists( $new_cohort ) ) {
            $wpdb->query( "RENAME TABLE `{$old_group}` TO `{$new_cohort}`" );
        }

        // Rename columns inside hl_cohort (the new container).
        if ( $table_exists( $new_cohort ) ) {
            if ( $column_exists( $new_cohort, 'group_id' ) ) {
                $wpdb->query( "ALTER TABLE `{$new_cohort}` CHANGE `group_id` `cohort_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT" );
            }
            if ( $column_exists( $new_cohort, 'group_uuid' ) ) {
                $wpdb->query( "ALTER TABLE `{$new_cohort}` CHANGE `group_uuid` `cohort_uuid` char(36) NOT NULL" );
            }
            if ( $column_exists( $new_cohort, 'group_name' ) ) {
                $wpdb->query( "ALTER TABLE `{$new_cohort}` CHANGE `group_name` `cohort_name` varchar(255) NOT NULL" );
            }
            if ( $column_exists( $new_cohort, 'group_code' ) ) {
                $wpdb->query( "ALTER TABLE `{$new_cohort}` CHANGE `group_code` `cohort_code` varchar(100) NOT NULL" );
            }
            // Drop old indexes — dbDelta will recreate.
            if ( $index_exists( $new_cohort, 'group_uuid' ) ) {
                $wpdb->query( "ALTER TABLE `{$new_cohort}` DROP INDEX `group_uuid`" );
            }
            if ( $index_exists( $new_cohort, 'group_code' ) ) {
                $wpdb->query( "ALTER TABLE `{$new_cohort}` DROP INDEX `group_code`" );
            }
        }

        // ─── STEP 3: Rename hl_cohort_school → hl_track_school ───────────
        $old_cs = "{$prefix}hl_cohort_school";
        $new_ts = "{$prefix}hl_track_school";

        if ( $table_exists( $old_cs ) && ! $table_exists( $new_ts ) ) {
            $wpdb->query( "RENAME TABLE `{$old_cs}` TO `{$new_ts}`" );
        }

        // Rename cohort_id → track_id inside hl_track_school.
        if ( $table_exists( $new_ts ) && $column_exists( $new_ts, 'cohort_id' ) ) {
            $wpdb->query( "ALTER TABLE `{$new_ts}` CHANGE `cohort_id` `track_id` bigint(20) unsigned NOT NULL" );
            // Drop old indexes — dbDelta will recreate.
            if ( $index_exists( $new_ts, 'cohort_school' ) ) {
                $wpdb->query( "ALTER TABLE `{$new_ts}` DROP INDEX `cohort_school`" );
            }
            if ( $index_exists( $new_ts, 'cohort_id' ) ) {
                $wpdb->query( "ALTER TABLE `{$new_ts}` DROP INDEX `cohort_id`" );
            }
        }

        // ─── STEP 4: Rename cohort_id → track_id in all dependent tables ─
        $tables_with_cohort_id = array(
            "{$prefix}hl_enrollment"                   => 'NOT NULL',
            "{$prefix}hl_team"                         => 'NOT NULL',
            "{$prefix}hl_pathway"                      => 'NOT NULL',
            "{$prefix}hl_activity"                     => 'NOT NULL',
            "{$prefix}hl_completion_rollup"             => 'NOT NULL',
            "{$prefix}hl_teacher_assessment_instance"   => 'NOT NULL',
            "{$prefix}hl_child_assessment_instance"     => 'NOT NULL',
            "{$prefix}hl_observation"                   => 'NOT NULL',
            "{$prefix}hl_coaching_session"              => 'NOT NULL',
            "{$prefix}hl_coach_assignment"              => 'NOT NULL',
            "{$prefix}hl_import_run"                    => 'NULL',
            "{$prefix}hl_audit_log"                     => 'NULL',
        );

        foreach ( $tables_with_cohort_id as $table => $nullable ) {
            if ( $table_exists( $table ) && $column_exists( $table, 'cohort_id' ) ) {
                $wpdb->query( "ALTER TABLE `{$table}` CHANGE `cohort_id` `track_id` bigint(20) unsigned {$nullable}" );
                // Drop old indexes — dbDelta will recreate.
                if ( $index_exists( $table, 'cohort_id' ) ) {
                    $wpdb->query( "ALTER TABLE `{$table}` DROP INDEX `cohort_id`" );
                }
            }
        }

        // Drop old composite unique keys that reference cohort_*.
        $enrollment_table = "{$prefix}hl_enrollment";
        if ( $table_exists( $enrollment_table ) && $index_exists( $enrollment_table, 'cohort_user' ) ) {
            $wpdb->query( "ALTER TABLE `{$enrollment_table}` DROP INDEX `cohort_user`" );
        }

        $pathway_table = "{$prefix}hl_pathway";
        if ( $table_exists( $pathway_table ) && $index_exists( $pathway_table, 'cohort_pathway_code' ) ) {
            $wpdb->query( "ALTER TABLE `{$pathway_table}` DROP INDEX `cohort_pathway_code`" );
        }

        $tsa_table = "{$prefix}hl_teacher_assessment_instance";
        if ( $table_exists( $tsa_table ) && $index_exists( $tsa_table, 'cohort_enrollment_phase' ) ) {
            $wpdb->query( "ALTER TABLE `{$tsa_table}` DROP INDEX `cohort_enrollment_phase`" );
        }

        $cai_table = "{$prefix}hl_child_assessment_instance";
        if ( $table_exists( $cai_table ) && $index_exists( $cai_table, 'cohort_enrollment_classroom_phase' ) ) {
            $wpdb->query( "ALTER TABLE `{$cai_table}` DROP INDEX `cohort_enrollment_classroom_phase`" );
        }

        $ca_table = "{$prefix}hl_coach_assignment";
        if ( $table_exists( $ca_table ) && $index_exists( $ca_table, 'cohort_scope' ) ) {
            $wpdb->query( "ALTER TABLE `{$ca_table}` DROP INDEX `cohort_scope`" );
        }
        if ( $table_exists( $ca_table ) && $index_exists( $ca_table, 'cohort_coach' ) ) {
            $wpdb->query( "ALTER TABLE `{$ca_table}` DROP INDEX `cohort_coach`" );
        }

        // Also rename cohort_completion_percent → track_completion_percent in completion_rollup.
        $rollup_table = "{$prefix}hl_completion_rollup";
        if ( $table_exists( $rollup_table ) && $column_exists( $rollup_table, 'cohort_completion_percent' ) ) {
            $wpdb->query( "ALTER TABLE `{$rollup_table}` CHANGE `cohort_completion_percent` `track_completion_percent` decimal(5,2) NOT NULL DEFAULT 0.00" );
        }
    }

    /**
     * Get database schema
     */
    private static function get_schema() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $tables = array();
        
        // OrgUnit table
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_orgunit (
            orgunit_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            orgunit_uuid char(36) NOT NULL,
            orgunit_code varchar(100) NOT NULL,
            orgunit_type enum('district','school') NOT NULL,
            parent_orgunit_id bigint(20) unsigned NULL,
            name varchar(255) NOT NULL,
            status enum('active','inactive','archived') DEFAULT 'active',
            metadata longtext NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (orgunit_id),
            UNIQUE KEY orgunit_uuid (orgunit_uuid),
            UNIQUE KEY orgunit_code (orgunit_code),
            KEY orgunit_type (orgunit_type),
            KEY parent_orgunit_id (parent_orgunit_id),
            KEY status (status)
        ) $charset_collate;";
        
        // Track table (a time-bounded run within a Cohort)
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_track (
            track_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            track_uuid char(36) NOT NULL,
            track_code varchar(100) NOT NULL,
            track_name varchar(255) NOT NULL,
            district_id bigint(20) unsigned NULL,
            cohort_id bigint(20) unsigned NULL,
            is_control_group tinyint(1) NOT NULL DEFAULT 0,
            status enum('draft','active','paused','archived') DEFAULT 'draft',
            start_date date NOT NULL,
            end_date date NULL,
            timezone varchar(50) DEFAULT 'America/Bogota',
            settings longtext NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (track_id),
            UNIQUE KEY track_uuid (track_uuid),
            UNIQUE KEY track_code (track_code),
            KEY district_id (district_id),
            KEY cohort_id (cohort_id),
            KEY status (status),
            KEY start_date (start_date)
        ) $charset_collate;";
        
        // Track-School association
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_track_school (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            track_id bigint(20) unsigned NOT NULL,
            school_id bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY track_school (track_id, school_id),
            KEY track_id (track_id),
            KEY school_id (school_id)
        ) $charset_collate;";
        
        // Enrollment table
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_enrollment (
            enrollment_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            enrollment_uuid char(36) NOT NULL,
            track_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            roles text NOT NULL COMMENT 'JSON array of track roles',
            assigned_pathway_id bigint(20) unsigned NULL,
            school_id bigint(20) unsigned NULL,
            district_id bigint(20) unsigned NULL,
            status enum('active','inactive') DEFAULT 'active',
            enrolled_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (enrollment_id),
            UNIQUE KEY enrollment_uuid (enrollment_uuid),
            UNIQUE KEY track_user (track_id, user_id),
            KEY track_id (track_id),
            KEY user_id (user_id),
            KEY school_id (school_id),
            KEY district_id (district_id),
            KEY status (status)
        ) $charset_collate;";
        
        // Team table
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_team (
            team_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            team_uuid char(36) NOT NULL,
            track_id bigint(20) unsigned NOT NULL,
            school_id bigint(20) unsigned NOT NULL,
            team_name varchar(255) NOT NULL,
            status enum('active','inactive') DEFAULT 'active',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (team_id),
            UNIQUE KEY team_uuid (team_uuid),
            KEY track_id (track_id),
            KEY school_id (school_id),
            KEY status (status)
        ) $charset_collate;";
        
        // Classroom table
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_classroom (
            classroom_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            classroom_uuid char(36) NOT NULL,
            school_id bigint(20) unsigned NOT NULL,
            classroom_name varchar(255) NOT NULL,
            age_band enum('infant','toddler','preschool','mixed') NULL,
            status enum('active','inactive') DEFAULT 'active',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (classroom_id),
            UNIQUE KEY classroom_uuid (classroom_uuid),
            KEY school_id (school_id),
            KEY status (status),
            UNIQUE KEY school_classroom (school_id, classroom_name)
        ) $charset_collate;";
        
        // Audit log table
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_audit_log (
            log_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            log_uuid char(36) NOT NULL,
            actor_user_id bigint(20) unsigned NOT NULL,
            track_id bigint(20) unsigned NULL,
            action_type varchar(100) NOT NULL,
            entity_type varchar(100) NULL,
            entity_id bigint(20) unsigned NULL,
            before_data longtext NULL COMMENT 'JSON',
            after_data longtext NULL COMMENT 'JSON',
            reason text NULL,
            ip_address varchar(45) NULL,
            user_agent varchar(500) NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (log_id),
            UNIQUE KEY log_uuid (log_uuid),
            KEY actor_user_id (actor_user_id),
            KEY track_id (track_id),
            KEY action_type (action_type),
            KEY entity_type (entity_type),
            KEY entity_id (entity_id),
            KEY created_at (created_at)
        ) $charset_collate;";

        // Team Membership table
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_team_membership (
            team_id bigint(20) unsigned NOT NULL,
            enrollment_id bigint(20) unsigned NOT NULL,
            membership_type enum('mentor','member') NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY team_enrollment (team_id, enrollment_id),
            KEY team_id (team_id),
            KEY enrollment_id (enrollment_id)
        ) $charset_collate;";

        // Teaching Assignment table
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_teaching_assignment (
            assignment_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            enrollment_id bigint(20) unsigned NOT NULL,
            classroom_id bigint(20) unsigned NOT NULL,
            is_lead_teacher tinyint(1) NOT NULL DEFAULT 0,
            effective_start_date date NULL,
            effective_end_date date NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (assignment_id),
            UNIQUE KEY enrollment_classroom (enrollment_id, classroom_id),
            KEY enrollment_id (enrollment_id),
            KEY classroom_id (classroom_id)
        ) $charset_collate;";

        // Child table
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_child (
            child_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            child_uuid char(36) NOT NULL,
            school_id bigint(20) unsigned NOT NULL,
            first_name varchar(100) NULL,
            last_name varchar(100) NULL,
            dob date NULL,
            internal_child_id varchar(100) NULL,
            ethnicity varchar(100) NULL,
            child_fingerprint varchar(64) NULL,
            child_display_code varchar(50) NULL,
            metadata longtext NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (child_id),
            UNIQUE KEY child_uuid (child_uuid),
            KEY school_id (school_id),
            KEY child_fingerprint (child_fingerprint)
        ) $charset_collate;";

        // Child Classroom Current table
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_child_classroom_current (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            child_id bigint(20) unsigned NOT NULL,
            classroom_id bigint(20) unsigned NOT NULL,
            assigned_at datetime NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY child_id (child_id),
            KEY classroom_id (classroom_id)
        ) $charset_collate;";

        // Child Classroom History table
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_child_classroom_history (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            child_id bigint(20) unsigned NOT NULL,
            classroom_id bigint(20) unsigned NOT NULL,
            start_date date NOT NULL,
            end_date date NULL,
            reason varchar(255) NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY child_id (child_id),
            KEY classroom_id (classroom_id)
        ) $charset_collate;";

        // Pathway table
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_pathway (
            pathway_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            pathway_uuid char(36) NOT NULL,
            track_id bigint(20) unsigned NOT NULL,
            pathway_name varchar(255) NOT NULL,
            pathway_code varchar(100) NOT NULL,
            description longtext NULL,
            objectives longtext NULL,
            syllabus_url varchar(500) NULL,
            featured_image_id bigint(20) unsigned NULL,
            avg_completion_time varchar(100) NULL,
            expiration_date date NULL,
            is_template tinyint(1) NOT NULL DEFAULT 0,
            target_roles text NULL COMMENT 'JSON',
            active_status tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (pathway_id),
            UNIQUE KEY pathway_uuid (pathway_uuid),
            UNIQUE KEY track_pathway_code (track_id, pathway_code),
            KEY track_id (track_id)
        ) $charset_collate;";

        // Activity table
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_activity (
            activity_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            activity_uuid char(36) NOT NULL,
            track_id bigint(20) unsigned NOT NULL,
            pathway_id bigint(20) unsigned NOT NULL,
            activity_type enum('learndash_course','teacher_self_assessment','child_assessment','coaching_session_attendance','observation') NOT NULL,
            title varchar(255) NOT NULL,
            description text NULL,
            ordering_hint int NOT NULL DEFAULT 0,
            weight decimal(5,2) NOT NULL DEFAULT 1.00,
            external_ref longtext NULL COMMENT 'JSON - course_id/instrument_id etc',
            visibility enum('all','staff_only') NOT NULL DEFAULT 'all',
            status enum('active','removed') NOT NULL DEFAULT 'active',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (activity_id),
            UNIQUE KEY activity_uuid (activity_uuid),
            KEY track_id (track_id),
            KEY pathway_id (pathway_id),
            KEY activity_type (activity_type)
        ) $charset_collate;";

        // Activity Prerequisite Group table
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_activity_prereq_group (
            group_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            activity_id bigint(20) unsigned NOT NULL,
            prereq_type enum('all_of','any_of','n_of_m') NOT NULL DEFAULT 'all_of',
            n_required int NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (group_id),
            KEY activity_id (activity_id)
        ) $charset_collate;";

        // Activity Prerequisite Item table
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_activity_prereq_item (
            item_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            group_id bigint(20) unsigned NOT NULL,
            prerequisite_activity_id bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (item_id),
            KEY group_id (group_id),
            KEY prerequisite_activity_id (prerequisite_activity_id)
        ) $charset_collate;";

        // Activity Drip Rule table
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_activity_drip_rule (
            rule_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            activity_id bigint(20) unsigned NOT NULL,
            drip_type enum('fixed_date','after_completion_delay') NOT NULL,
            release_at_date datetime NULL,
            base_activity_id bigint(20) unsigned NULL,
            delay_days int NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (rule_id),
            KEY activity_id (activity_id)
        ) $charset_collate;";

        // Activity Override table
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_activity_override (
            override_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            override_uuid char(36) NOT NULL,
            enrollment_id bigint(20) unsigned NOT NULL,
            activity_id bigint(20) unsigned NOT NULL,
            override_type enum('exempt','manual_unlock','grace_unlock') NOT NULL,
            applied_by_user_id bigint(20) unsigned NOT NULL,
            reason text NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (override_id),
            UNIQUE KEY override_uuid (override_uuid),
            KEY enrollment_id (enrollment_id),
            KEY activity_id (activity_id)
        ) $charset_collate;";

        // Activity State table
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_activity_state (
            state_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            enrollment_id bigint(20) unsigned NOT NULL,
            activity_id bigint(20) unsigned NOT NULL,
            completion_percent int NOT NULL DEFAULT 0,
            completion_status enum('not_started','in_progress','complete') NOT NULL DEFAULT 'not_started',
            completed_at datetime NULL,
            evidence_ref text NULL,
            last_computed_at datetime NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (state_id),
            UNIQUE KEY enrollment_activity (enrollment_id, activity_id),
            KEY enrollment_id (enrollment_id),
            KEY activity_id (activity_id)
        ) $charset_collate;";

        // Completion Rollup table
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_completion_rollup (
            rollup_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            enrollment_id bigint(20) unsigned NOT NULL,
            track_id bigint(20) unsigned NOT NULL,
            pathway_completion_percent decimal(5,2) NOT NULL DEFAULT 0.00,
            track_completion_percent decimal(5,2) NOT NULL DEFAULT 0.00,
            last_computed_at datetime NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (rollup_id),
            UNIQUE KEY enrollment_id (enrollment_id),
            KEY track_id (track_id)
        ) $charset_collate;";

        // Instrument table (children assessment instruments only; teacher self-assessment and observation forms are in JetFormBuilder)
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_instrument (
            instrument_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            instrument_uuid char(36) NOT NULL,
            name varchar(255) NOT NULL,
            instrument_type varchar(50) NOT NULL,
            version varchar(20) NOT NULL DEFAULT '1.0',
            questions longtext NOT NULL COMMENT 'JSON array of question objects',
            effective_from date NULL,
            effective_to date NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (instrument_id),
            UNIQUE KEY instrument_uuid (instrument_uuid)
        ) $charset_collate;";

        // Teacher Assessment Instance table
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_teacher_assessment_instance (
            instance_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            instance_uuid char(36) NOT NULL,
            track_id bigint(20) unsigned NOT NULL,
            enrollment_id bigint(20) unsigned NOT NULL,
            activity_id bigint(20) unsigned NULL,
            phase enum('pre','post') NOT NULL,
            instrument_id bigint(20) unsigned NULL,
            instrument_version varchar(20) NULL,
            jfb_form_id bigint(20) unsigned NULL,
            jfb_record_id bigint(20) unsigned NULL,
            responses_json longtext DEFAULT NULL,
            status enum('not_started','in_progress','submitted') NOT NULL DEFAULT 'not_started',
            started_at datetime NULL,
            submitted_at datetime NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (instance_id),
            UNIQUE KEY instance_uuid (instance_uuid),
            UNIQUE KEY track_enrollment_phase (track_id, enrollment_id, phase),
            KEY track_id (track_id),
            KEY enrollment_id (enrollment_id),
            KEY activity_id (activity_id),
            KEY instrument_id (instrument_id)
        ) $charset_collate;";

        // DEPRECATED: Teacher Assessment Response table
        // Responses are now stored in JetFormBuilder Form Records.
        // This table definition is retained so dbDelta does not drop existing data,
        // but HL Core no longer writes to it for JFB-powered form types.
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_teacher_assessment_response (
            response_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            instance_id bigint(20) unsigned NOT NULL,
            question_id varchar(100) NOT NULL,
            value longtext NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (response_id),
            KEY instance_id (instance_id),
            KEY question_id (question_id)
        ) $charset_collate;";

        // Child Assessment Instance table
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_child_assessment_instance (
            instance_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            instance_uuid char(36) NOT NULL,
            track_id bigint(20) unsigned NOT NULL,
            enrollment_id bigint(20) unsigned NOT NULL,
            activity_id bigint(20) unsigned NULL,
            classroom_id bigint(20) unsigned NULL,
            school_id bigint(20) unsigned NULL,
            phase enum('pre','post') NULL,
            instrument_age_band varchar(20) NULL,
            instrument_id bigint(20) unsigned NULL,
            instrument_version varchar(20) NULL,
            responses_json longtext DEFAULT NULL,
            status enum('not_started','in_progress','submitted') NOT NULL DEFAULT 'not_started',
            started_at datetime NULL,
            submitted_at datetime NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (instance_id),
            UNIQUE KEY instance_uuid (instance_uuid),
            UNIQUE KEY track_enrollment_classroom_phase (track_id, enrollment_id, classroom_id, phase),
            KEY enrollment_id (enrollment_id),
            KEY activity_id (activity_id),
            KEY classroom_id (classroom_id),
            KEY school_id (school_id)
        ) $charset_collate;";

        // Child Assessment Child Row table
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_child_assessment_childrow (
            row_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            instance_id bigint(20) unsigned NOT NULL,
            child_id bigint(20) unsigned NOT NULL,
            answers_json longtext NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (row_id),
            UNIQUE KEY instance_child (instance_id, child_id),
            KEY instance_id (instance_id),
            KEY child_id (child_id)
        ) $charset_collate;";

        // Observation table
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_observation (
            observation_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            observation_uuid char(36) NOT NULL,
            track_id bigint(20) unsigned NOT NULL,
            mentor_enrollment_id bigint(20) unsigned NOT NULL,
            teacher_enrollment_id bigint(20) unsigned NULL,
            school_id bigint(20) unsigned NULL,
            classroom_id bigint(20) unsigned NULL,
            instrument_id bigint(20) unsigned NULL,
            instrument_version varchar(20) NULL,
            jfb_form_id bigint(20) unsigned NULL,
            jfb_record_id bigint(20) unsigned NULL,
            status enum('draft','submitted') NOT NULL DEFAULT 'draft',
            submitted_at datetime NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (observation_id),
            UNIQUE KEY observation_uuid (observation_uuid),
            KEY track_id (track_id),
            KEY mentor_enrollment_id (mentor_enrollment_id),
            KEY teacher_enrollment_id (teacher_enrollment_id),
            KEY school_id (school_id),
            KEY classroom_id (classroom_id)
        ) $charset_collate;";

        // DEPRECATED: Observation Response table
        // Responses are now stored in JetFormBuilder Form Records.
        // This table definition is retained so dbDelta does not drop existing data,
        // but HL Core no longer writes to it for JFB-powered form types.
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_observation_response (
            response_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            observation_id bigint(20) unsigned NOT NULL,
            question_id varchar(100) NOT NULL,
            value longtext NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (response_id),
            KEY observation_id (observation_id),
            KEY question_id (question_id)
        ) $charset_collate;";

        // Observation Attachment table
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_observation_attachment (
            attachment_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            observation_id bigint(20) unsigned NOT NULL,
            wp_media_id bigint(20) unsigned NULL,
            file_url varchar(500) NULL,
            mime_type varchar(100) NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (attachment_id),
            KEY observation_id (observation_id)
        ) $charset_collate;";

        // Coaching Session table
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_coaching_session (
            session_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            session_uuid char(36) NOT NULL,
            track_id bigint(20) unsigned NOT NULL,
            coach_user_id bigint(20) unsigned NOT NULL,
            mentor_enrollment_id bigint(20) unsigned NOT NULL,
            session_title varchar(255) NULL,
            meeting_url varchar(500) NULL,
            session_status enum('scheduled','attended','missed','cancelled','rescheduled') NOT NULL DEFAULT 'scheduled',
            attendance_status enum('attended','missed','unknown') NOT NULL DEFAULT 'unknown',
            session_datetime datetime NULL,
            notes_richtext longtext NULL,
            cancelled_at datetime NULL,
            rescheduled_from_session_id bigint(20) unsigned NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (session_id),
            UNIQUE KEY session_uuid (session_uuid),
            KEY track_id (track_id),
            KEY coach_user_id (coach_user_id),
            KEY mentor_enrollment_id (mentor_enrollment_id)
        ) $charset_collate;";

        // Coaching Session Observation link table
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_coaching_session_observation (
            link_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            session_id bigint(20) unsigned NOT NULL,
            observation_id bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (link_id),
            UNIQUE KEY session_observation (session_id, observation_id),
            KEY session_id (session_id),
            KEY observation_id (observation_id)
        ) $charset_collate;";

        // Coaching Attachment table
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_coaching_attachment (
            attachment_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            session_id bigint(20) unsigned NOT NULL,
            wp_media_id bigint(20) unsigned NULL,
            file_url varchar(500) NULL,
            mime_type varchar(100) NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (attachment_id),
            KEY session_id (session_id)
        ) $charset_collate;";

        // Import Run table
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_import_run (
            run_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            run_uuid char(36) NOT NULL,
            actor_user_id bigint(20) unsigned NOT NULL,
            track_id bigint(20) unsigned NULL,
            import_type varchar(50) NOT NULL,
            file_name varchar(255) NOT NULL,
            status enum('preview','committed','failed') NOT NULL DEFAULT 'preview',
            preview_data longtext NULL COMMENT 'JSON',
            results_summary longtext NULL COMMENT 'JSON',
            error_report_url varchar(500) NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (run_id),
            UNIQUE KEY run_uuid (run_uuid),
            KEY actor_user_id (actor_user_id),
            KEY track_id (track_id),
            KEY import_type (import_type),
            KEY status (status)
        ) $charset_collate;";

        // Coach Assignment table
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_coach_assignment (
            coach_assignment_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            coach_user_id bigint(20) unsigned NOT NULL,
            scope_type enum('school','team','enrollment') NOT NULL,
            scope_id bigint(20) unsigned NOT NULL,
            track_id bigint(20) unsigned NOT NULL,
            effective_from date NOT NULL,
            effective_to date NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (coach_assignment_id),
            KEY track_scope (track_id, scope_type, scope_id),
            KEY coach_user_id (coach_user_id),
            KEY track_coach (track_id, coach_user_id)
        ) $charset_collate;";

        // Pathway Assignment table (explicit pathway-to-enrollment assignments)
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_pathway_assignment (
            assignment_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            enrollment_id bigint(20) unsigned NOT NULL,
            pathway_id bigint(20) unsigned NOT NULL,
            assigned_by_user_id bigint(20) unsigned NOT NULL,
            assignment_type enum('role_default','explicit') NOT NULL DEFAULT 'explicit',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (assignment_id),
            UNIQUE KEY enrollment_pathway (enrollment_id, pathway_id),
            KEY enrollment_id (enrollment_id),
            KEY pathway_id (pathway_id),
            KEY assignment_type (assignment_type)
        ) $charset_collate;";

        // Teacher Assessment Instrument table (custom self-assessment instruments)
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_teacher_assessment_instrument (
            instrument_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            instrument_name varchar(200) NOT NULL,
            instrument_version varchar(20) NOT NULL DEFAULT '1.0',
            instrument_key varchar(50) NOT NULL,
            sections longtext NOT NULL,
            scale_labels longtext DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (instrument_id),
            KEY idx_key_version (instrument_key, instrument_version)
        ) $charset_collate;";

        // Cohort table (program-level container — groups tracks for cross-track reporting)
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_cohort (
            cohort_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            cohort_uuid char(36) NOT NULL,
            cohort_name varchar(255) NOT NULL,
            cohort_code varchar(100) NOT NULL,
            description text NULL,
            status enum('active','archived') NOT NULL DEFAULT 'active',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (cohort_id),
            UNIQUE KEY cohort_uuid (cohort_uuid),
            UNIQUE KEY cohort_code (cohort_code),
            KEY status (status)
        ) $charset_collate;";

        return $tables;
    }

    /**
     * Create custom capabilities
     */
    private static function create_capabilities() {
        // Get administrator role
        $admin_role = get_role('administrator');
        
        if ($admin_role) {
            // Add basic HL Core capabilities to administrator
            $admin_role->add_cap('manage_hl_core');
            $admin_role->add_cap('hl_view_cohorts');
            $admin_role->add_cap('hl_edit_cohorts');
            $admin_role->add_cap('hl_view_enrollments');
            $admin_role->add_cap('hl_edit_enrollments');
        }
        
        // Create Coach role if it doesn't exist
        if (!get_role('coach')) {
            add_role('coach', __('Coach', 'hl-core'), array(
                'read' => true,
                'manage_hl_core' => true,
                'hl_view_cohorts' => true,
                'hl_view_enrollments' => true,
            ));
        }
    }
    
    /**
     * Set default options
     */
    private static function set_default_options() {
        add_option('hl_core_version', HL_CORE_VERSION);
        add_option('hl_core_installed_at', current_time('mysql'));
    }
}
