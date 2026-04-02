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

        // Phase 23: Child assessment restructure — snapshot table + column additions.
        self::migrate_child_assessment_restructure();

        // Phase 32: Add Phase entity (hl_phase) + track_type + pathway.phase_id.
        self::migrate_add_phase_entity();

        // Rename V2 Phase A1: Rename track → partnership across all tables.
        self::migrate_track_to_partnership();

        // Rename V2 Phase B1: Rename activity → component across all tables.
        self::migrate_activity_to_component();

        // Rename V2 Phase C1: Rename phase → cycle across all tables.
        self::migrate_phase_to_cycle();

        // Rename V3: Corrective rename — swap cohort↔partnership, delete Phase entity.
        self::migrate_v3_grand_rename();

        // Rev 24: Add scheduling integration columns to hl_coaching_session.
        self::migrate_coaching_scheduling_columns();

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
        $current_revision = 28;

        if ( (int) $stored < $current_revision ) {
            self::create_tables();

            // Rev 16: Add 'k2' to hl_classroom.age_band ENUM (dbDelta can't modify ENUMs).
            if ( (int) $stored < 16 ) {
                self::migrate_classroom_age_band_k2();
            }

            // Rev 17: Add track_type ENUM to hl_track (dbDelta can't add ENUMs reliably).
            if ( (int) $stored < 17 ) {
                self::migrate_track_type_enum();
            }

            // Rev 22: Add new component types for cross-pathway events.
            if ( (int) $stored < 22 ) {
                self::migrate_add_event_component_types();
            }

            // Rev 24: Add scheduling integration columns to hl_coaching_session.
            if ( (int) $stored < 24 ) {
                self::migrate_coaching_scheduling_columns();
            }

            // Rev 26: Remove deprecated 'observation' component type (replaced by 'classroom_visit').
            if ( (int) $stored < 26 ) {
                self::migrate_remove_observation_component_type();
            }

            // Rev 27: Add routing_type column to hl_pathway for auto-assignment routing.
            if ( (int) $stored < 27 ) {
                self::migrate_pathway_add_routing_type();
            }

            // Rev 28: Add cycle_id to hl_classroom.
            if ( (int) $stored < 28 ) {
                self::migrate_classroom_add_cycle_id();
            }

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
     * Stores structured JSON responses for custom instrument-based assessments.
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

        if ( ! $column_exists( 'activity_id' ) && ! $column_exists( 'component_id' ) ) {
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

        if ( ! $column_exists( 'activity_id' ) && ! $column_exists( 'component_id' ) ) {
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
     * Phase 23: Child assessment restructure migration.
     *
     * a. CREATE TABLE hl_child_track_snapshot (handled by dbDelta in get_schema).
     * b. ALTER hl_child_classroom_current — add status, removed_by, removed_at, removal_reason, removal_note, added_by, added_at columns.
     * c. ALTER hl_child_assessment_childrow — add status, skip_reason, frozen_age_group, instrument_id columns.
     * d. ALTER hl_child_assessment_instance — make instrument_id nullable (already nullable in schema).
     */
    private static function migrate_child_assessment_restructure() {
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

        // ─── b. ALTER hl_child_classroom_current ──────────────────────────
        $ccc_table = "{$prefix}hl_child_classroom_current";
        if ( $table_exists( $ccc_table ) ) {
            if ( ! $column_exists( $ccc_table, 'status' ) ) {
                $wpdb->query( "ALTER TABLE `{$ccc_table}` ADD COLUMN `status` ENUM('active','teacher_removed') NOT NULL DEFAULT 'active'" );
                $wpdb->query( "ALTER TABLE `{$ccc_table}` ADD INDEX `status` (`status`)" );
            }
            if ( ! $column_exists( $ccc_table, 'removed_by_enrollment_id' ) ) {
                $wpdb->query( "ALTER TABLE `{$ccc_table}` ADD COLUMN `removed_by_enrollment_id` bigint(20) unsigned NULL" );
            }
            if ( ! $column_exists( $ccc_table, 'removed_at' ) ) {
                $wpdb->query( "ALTER TABLE `{$ccc_table}` ADD COLUMN `removed_at` datetime NULL" );
            }
            if ( ! $column_exists( $ccc_table, 'removal_reason' ) ) {
                $wpdb->query( "ALTER TABLE `{$ccc_table}` ADD COLUMN `removal_reason` ENUM('left_school','moved_classroom','other') NULL" );
            }
            if ( ! $column_exists( $ccc_table, 'removal_note' ) ) {
                $wpdb->query( "ALTER TABLE `{$ccc_table}` ADD COLUMN `removal_note` text NULL" );
            }
            if ( ! $column_exists( $ccc_table, 'added_by_enrollment_id' ) ) {
                $wpdb->query( "ALTER TABLE `{$ccc_table}` ADD COLUMN `added_by_enrollment_id` bigint(20) unsigned NULL" );
            }
            if ( ! $column_exists( $ccc_table, 'added_at' ) ) {
                $wpdb->query( "ALTER TABLE `{$ccc_table}` ADD COLUMN `added_at` datetime NULL" );
            }
        }

        // ─── c. ALTER hl_child_assessment_childrow ────────────────────────
        $childrow_table = "{$prefix}hl_child_assessment_childrow";
        if ( $table_exists( $childrow_table ) ) {
            if ( ! $column_exists( $childrow_table, 'status' ) ) {
                $wpdb->query( "ALTER TABLE `{$childrow_table}` ADD COLUMN `status` ENUM('active','skipped','not_in_classroom','stale_at_submit') NOT NULL DEFAULT 'active'" );
            }
            if ( ! $column_exists( $childrow_table, 'skip_reason' ) ) {
                $wpdb->query( "ALTER TABLE `{$childrow_table}` ADD COLUMN `skip_reason` varchar(255) NULL" );
            }
            if ( ! $column_exists( $childrow_table, 'frozen_age_group' ) ) {
                $wpdb->query( "ALTER TABLE `{$childrow_table}` ADD COLUMN `frozen_age_group` varchar(20) NULL" );
            }
            if ( ! $column_exists( $childrow_table, 'instrument_id' ) ) {
                $wpdb->query( "ALTER TABLE `{$childrow_table}` ADD COLUMN `instrument_id` bigint(20) unsigned NULL" );
            }
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
        
        // Partnership table (program-level container — groups Cycles)
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_partnership (
            partnership_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            partnership_uuid char(36) NOT NULL,
            partnership_name varchar(255) NOT NULL,
            partnership_code varchar(100) NOT NULL,
            description text NULL,
            status enum('active','archived') NOT NULL DEFAULT 'active',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (partnership_id),
            UNIQUE KEY partnership_uuid (partnership_uuid),
            UNIQUE KEY partnership_code (partnership_code),
            KEY status (status)
        ) $charset_collate;";

        // Cycle table (a time-bounded run within a Partnership)
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_cycle (
            cycle_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            cycle_uuid char(36) NOT NULL,
            cycle_code varchar(100) NOT NULL,
            cycle_name varchar(255) NOT NULL,
            partnership_id bigint(20) unsigned NULL,
            district_id bigint(20) unsigned NULL,
            is_control_group tinyint(1) NOT NULL DEFAULT 0,
            cycle_type varchar(20) NOT NULL DEFAULT 'program',
            status enum('draft','active','paused','archived') DEFAULT 'draft',
            start_date date NOT NULL,
            end_date date NULL,
            timezone varchar(50) DEFAULT 'America/Bogota',
            settings longtext NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (cycle_id),
            UNIQUE KEY cycle_uuid (cycle_uuid),
            UNIQUE KEY cycle_code (cycle_code),
            KEY partnership_id (partnership_id),
            KEY district_id (district_id),
            KEY status (status),
            KEY start_date (start_date)
        ) $charset_collate;";

        // Cycle-School association
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_cycle_school (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            cycle_id bigint(20) unsigned NOT NULL,
            school_id bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY cycle_school (cycle_id, school_id),
            KEY cycle_id (cycle_id),
            KEY school_id (school_id)
        ) $charset_collate;";
        
        // Enrollment table
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_enrollment (
            enrollment_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            enrollment_uuid char(36) NOT NULL,
            cycle_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            roles text NOT NULL COMMENT 'JSON array of cycle roles',
            assigned_pathway_id bigint(20) unsigned NULL,
            school_id bigint(20) unsigned NULL,
            district_id bigint(20) unsigned NULL,
            status enum('active','inactive') DEFAULT 'active',
            enrolled_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (enrollment_id),
            UNIQUE KEY enrollment_uuid (enrollment_uuid),
            UNIQUE KEY cycle_user (cycle_id, user_id),
            KEY cycle_id (cycle_id),
            KEY user_id (user_id),
            KEY school_id (school_id),
            KEY district_id (district_id),
            KEY status (status)
        ) $charset_collate;";
        
        // Team table
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_team (
            team_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            team_uuid char(36) NOT NULL,
            cycle_id bigint(20) unsigned NOT NULL,
            school_id bigint(20) unsigned NOT NULL,
            team_name varchar(255) NOT NULL,
            status enum('active','inactive') DEFAULT 'active',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (team_id),
            UNIQUE KEY team_uuid (team_uuid),
            KEY cycle_id (cycle_id),
            KEY school_id (school_id),
            KEY status (status)
        ) $charset_collate;";
        
        // Classroom table
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_classroom (
            classroom_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            classroom_uuid char(36) NOT NULL,
            school_id bigint(20) unsigned NOT NULL,
            cycle_id bigint(20) unsigned NOT NULL DEFAULT 0,
            classroom_name varchar(255) NOT NULL,
            age_band enum('infant','toddler','preschool','k2','mixed') NULL,
            status enum('active','inactive') DEFAULT 'active',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (classroom_id),
            UNIQUE KEY classroom_uuid (classroom_uuid),
            KEY school_id (school_id),
            KEY cycle_id (cycle_id),
            KEY status (status),
            UNIQUE KEY school_classroom_cycle (school_id, classroom_name, cycle_id)
        ) $charset_collate;";
        
        // Audit log table
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_audit_log (
            log_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            log_uuid char(36) NOT NULL,
            actor_user_id bigint(20) unsigned NOT NULL,
            cycle_id bigint(20) unsigned NULL,
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
            KEY cycle_id (cycle_id),
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
            status enum('active','teacher_removed') NOT NULL DEFAULT 'active',
            removed_by_enrollment_id bigint(20) unsigned NULL,
            removed_at datetime NULL,
            removal_reason enum('left_school','moved_classroom','other') NULL,
            removal_note text NULL,
            added_by_enrollment_id bigint(20) unsigned NULL,
            added_at datetime NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY child_id (child_id),
            KEY classroom_id (classroom_id),
            KEY status (status)
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

        // Child Cycle Snapshot table (frozen age group per child per cycle)
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_child_cycle_snapshot (
            snapshot_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            child_id bigint(20) unsigned NOT NULL,
            cycle_id bigint(20) unsigned NOT NULL,
            frozen_age_group varchar(20) NOT NULL,
            dob_at_freeze date NULL,
            age_months_at_freeze int NULL,
            frozen_at datetime NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (snapshot_id),
            UNIQUE KEY child_cycle (child_id, cycle_id),
            KEY cycle_id (cycle_id)
        ) $charset_collate;";

        // Pathway table
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_pathway (
            pathway_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            pathway_uuid char(36) NOT NULL,
            cycle_id bigint(20) unsigned NOT NULL,
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
            routing_type varchar(50) DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (pathway_id),
            UNIQUE KEY pathway_uuid (pathway_uuid),
            UNIQUE KEY cycle_pathway_code (cycle_id, pathway_code),
            UNIQUE KEY unique_routing_per_cycle (cycle_id, routing_type),
            KEY cycle_id (cycle_id)
        ) $charset_collate;";

        // Component table
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_component (
            component_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            component_uuid char(36) NOT NULL,
            cycle_id bigint(20) unsigned NOT NULL,
            pathway_id bigint(20) unsigned NOT NULL,
            component_type enum('learndash_course','teacher_self_assessment','child_assessment','coaching_session_attendance','reflective_practice_session','classroom_visit','self_reflection') NOT NULL,
            title varchar(255) NOT NULL,
            description text NULL,
            ordering_hint int NOT NULL DEFAULT 0,
            weight decimal(5,2) NOT NULL DEFAULT 1.00,
            external_ref longtext NULL COMMENT 'JSON - course_id/instrument_id etc',
            complete_by date DEFAULT NULL COMMENT 'Suggested completion date (not enforced)',
            visibility enum('all','staff_only') NOT NULL DEFAULT 'all',
            requires_classroom tinyint(1) NOT NULL DEFAULT 0,
            eligible_roles text NULL,
            status enum('active','removed') NOT NULL DEFAULT 'active',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (component_id),
            UNIQUE KEY component_uuid (component_uuid),
            KEY cycle_id (cycle_id),
            KEY pathway_id (pathway_id),
            KEY component_type (component_type)
        ) $charset_collate;";

        // Component Prerequisite Group table
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_component_prereq_group (
            group_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            component_id bigint(20) unsigned NOT NULL,
            prereq_type enum('all_of','any_of','n_of_m') NOT NULL DEFAULT 'all_of',
            n_required int NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (group_id),
            KEY component_id (component_id)
        ) $charset_collate;";

        // Component Prerequisite Item table
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_component_prereq_item (
            item_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            group_id bigint(20) unsigned NOT NULL,
            prerequisite_component_id bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (item_id),
            KEY group_id (group_id),
            KEY prerequisite_component_id (prerequisite_component_id)
        ) $charset_collate;";

        // Component Drip Rule table
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_component_drip_rule (
            rule_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            component_id bigint(20) unsigned NOT NULL,
            drip_type enum('fixed_date','after_completion_delay') NOT NULL,
            release_at_date datetime NULL,
            base_component_id bigint(20) unsigned NULL,
            delay_days int NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (rule_id),
            KEY component_id (component_id)
        ) $charset_collate;";

        // Component Override table
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_component_override (
            override_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            override_uuid char(36) NOT NULL,
            enrollment_id bigint(20) unsigned NOT NULL,
            component_id bigint(20) unsigned NOT NULL,
            override_type enum('exempt','manual_unlock','grace_unlock') NOT NULL,
            applied_by_user_id bigint(20) unsigned NOT NULL,
            reason text NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (override_id),
            UNIQUE KEY override_uuid (override_uuid),
            KEY enrollment_id (enrollment_id),
            KEY component_id (component_id)
        ) $charset_collate;";

        // Component State table
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_component_state (
            state_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            enrollment_id bigint(20) unsigned NOT NULL,
            component_id bigint(20) unsigned NOT NULL,
            completion_percent int NOT NULL DEFAULT 0,
            completion_status enum('not_started','in_progress','complete') NOT NULL DEFAULT 'not_started',
            completed_at datetime NULL,
            evidence_ref text NULL,
            last_computed_at datetime NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (state_id),
            UNIQUE KEY enrollment_component (enrollment_id, component_id),
            KEY enrollment_id (enrollment_id),
            KEY component_id (component_id)
        ) $charset_collate;";

        // Completion Rollup table
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_completion_rollup (
            rollup_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            enrollment_id bigint(20) unsigned NOT NULL,
            cycle_id bigint(20) unsigned NOT NULL,
            pathway_completion_percent decimal(5,2) NOT NULL DEFAULT 0.00,
            cycle_completion_percent decimal(5,2) NOT NULL DEFAULT 0.00,
            last_computed_at datetime NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (rollup_id),
            UNIQUE KEY enrollment_id (enrollment_id),
            KEY cycle_id (cycle_id)
        ) $charset_collate;";

        // Instrument table (children assessment instruments only)
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_instrument (
            instrument_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            instrument_uuid char(36) NOT NULL,
            name varchar(255) NOT NULL,
            instrument_type varchar(50) NOT NULL,
            version varchar(20) NOT NULL DEFAULT '1.0',
            questions longtext NOT NULL COMMENT 'JSON array of question objects',
            instructions longtext NULL,
            behavior_key longtext NULL COMMENT 'JSON: [{label, frequency, description}, ...]',
            styles_json longtext NULL COMMENT 'JSON: admin-customizable display styles (font sizes, colors)',
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
            cycle_id bigint(20) unsigned NOT NULL,
            enrollment_id bigint(20) unsigned NOT NULL,
            component_id bigint(20) unsigned NULL,
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
            UNIQUE KEY cycle_enrollment_phase (cycle_id, enrollment_id, phase),
            KEY cycle_id (cycle_id),
            KEY enrollment_id (enrollment_id),
            KEY component_id (component_id),
            KEY instrument_id (instrument_id)
        ) $charset_collate;";

        // DEPRECATED: Teacher Assessment Response table
        // Retained so dbDelta does not drop existing data. HL Core no longer writes to this table
        // — responses are stored in responses_json on hl_teacher_assessment_instance.
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
            cycle_id bigint(20) unsigned NOT NULL,
            enrollment_id bigint(20) unsigned NOT NULL,
            component_id bigint(20) unsigned NULL,
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
            UNIQUE KEY cycle_enrollment_classroom_phase (cycle_id, enrollment_id, classroom_id, phase),
            KEY enrollment_id (enrollment_id),
            KEY component_id (component_id),
            KEY classroom_id (classroom_id),
            KEY school_id (school_id)
        ) $charset_collate;";

        // Child Assessment Child Row table
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_child_assessment_childrow (
            row_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            instance_id bigint(20) unsigned NOT NULL,
            child_id bigint(20) unsigned NOT NULL,
            answers_json longtext NULL,
            status enum('active','skipped','not_in_classroom','stale_at_submit') NOT NULL DEFAULT 'active',
            skip_reason varchar(255) NULL,
            frozen_age_group varchar(20) NULL,
            instrument_id bigint(20) unsigned NULL,
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
            cycle_id bigint(20) unsigned NOT NULL,
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
            KEY cycle_id (cycle_id),
            KEY mentor_enrollment_id (mentor_enrollment_id),
            KEY teacher_enrollment_id (teacher_enrollment_id),
            KEY school_id (school_id),
            KEY classroom_id (classroom_id)
        ) $charset_collate;";

        // DEPRECATED: Observation Response table
        // Retained so dbDelta does not drop existing data. HL Core no longer writes to this table
        // — observations use classroom_visit component type with native PHP forms.
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
            cycle_id bigint(20) unsigned NOT NULL,
            coach_user_id bigint(20) unsigned NOT NULL,
            mentor_enrollment_id bigint(20) unsigned NOT NULL,
            session_number tinyint unsigned NULL,
            session_title varchar(255) NULL,
            meeting_url varchar(500) NULL,
            session_status enum('scheduled','attended','missed','cancelled','rescheduled') NOT NULL DEFAULT 'scheduled',
            attendance_status enum('attended','missed','unknown') NOT NULL DEFAULT 'unknown',
            session_datetime datetime NULL,
            notes_richtext longtext NULL,
            cancelled_at datetime NULL,
            rescheduled_from_session_id bigint(20) unsigned NULL,
            component_id bigint(20) unsigned NULL COMMENT 'Links to hl_component for specific coaching component',
            zoom_meeting_id bigint(20) unsigned NULL COMMENT 'Zoom meeting ID for API update/delete',
            outlook_event_id varchar(255) NULL COMMENT 'Microsoft Graph calendar event ID',
            booked_by_user_id bigint(20) unsigned NULL COMMENT 'User who created the booking',
            mentor_timezone varchar(100) NULL COMMENT 'IANA timezone at booking time',
            coach_timezone varchar(100) NULL COMMENT 'IANA timezone at booking time',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (session_id),
            UNIQUE KEY session_uuid (session_uuid),
            KEY cycle_id (cycle_id),
            KEY coach_user_id (coach_user_id),
            KEY mentor_enrollment_id (mentor_enrollment_id),
            KEY component_id (component_id),
            KEY booked_by_user_id (booked_by_user_id)
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
            cycle_id bigint(20) unsigned NULL,
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
            KEY cycle_id (cycle_id),
            KEY import_type (import_type),
            KEY status (status)
        ) $charset_collate;";

        // Coach Assignment table
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_coach_assignment (
            coach_assignment_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            coach_user_id bigint(20) unsigned NOT NULL,
            scope_type enum('school','team','enrollment') NOT NULL,
            scope_id bigint(20) unsigned NOT NULL,
            cycle_id bigint(20) unsigned NOT NULL,
            effective_from date NOT NULL,
            effective_to date NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (coach_assignment_id),
            KEY cycle_scope (cycle_id, scope_type, scope_id),
            KEY coach_user_id (coach_user_id),
            KEY cycle_coach (cycle_id, coach_user_id)
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
            instructions longtext DEFAULT NULL,
            styles_json longtext DEFAULT NULL COMMENT 'JSON: admin-customizable display styles (font sizes, colors)',
            status varchar(20) NOT NULL DEFAULT 'active',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (instrument_id),
            KEY idx_key_version (instrument_key, instrument_version)
        ) $charset_collate;";

        // Cycle email log — per-user duplicate prevention for invitation emails
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_cycle_email_log (
            log_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            cycle_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            email_type varchar(20) NOT NULL,
            recipient_email varchar(255) NOT NULL,
            sent_at datetime NOT NULL,
            sent_by bigint(20) unsigned NOT NULL,
            PRIMARY KEY (log_id),
            UNIQUE KEY unique_send (cycle_id, user_id),
            KEY cycle_id (cycle_id)
        ) $charset_collate;";

        // RP Session table (Reflective Practice sessions linking mentor + teacher)
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_rp_session (
            rp_session_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            rp_session_uuid char(36) NOT NULL,
            cycle_id bigint(20) unsigned NOT NULL,
            mentor_enrollment_id bigint(20) unsigned NOT NULL,
            teacher_enrollment_id bigint(20) unsigned NOT NULL,
            session_number tinyint unsigned NOT NULL DEFAULT 1,
            status varchar(20) NOT NULL DEFAULT 'pending',
            session_date datetime DEFAULT NULL,
            notes text DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (rp_session_id),
            UNIQUE KEY rp_session_uuid (rp_session_uuid),
            KEY idx_cycle (cycle_id),
            KEY idx_mentor (mentor_enrollment_id),
            KEY idx_teacher (teacher_enrollment_id)
        ) $charset_collate;";

        // RP Session Submission table (form responses for RP sessions)
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_rp_session_submission (
            submission_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            submission_uuid char(36) NOT NULL,
            rp_session_id bigint(20) unsigned NOT NULL,
            submitted_by_user_id bigint(20) unsigned NOT NULL,
            instrument_id bigint(20) unsigned NOT NULL,
            role_in_session varchar(20) NOT NULL,
            responses_json longtext DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'draft',
            submitted_at datetime DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (submission_id),
            UNIQUE KEY submission_uuid (submission_uuid),
            UNIQUE KEY uq_session_role (rp_session_id, role_in_session),
            KEY idx_rp_session (rp_session_id),
            KEY idx_user (submitted_by_user_id)
        ) $charset_collate;";

        // Classroom Visit table (leader observes teacher's classroom)
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_classroom_visit (
            classroom_visit_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            classroom_visit_uuid char(36) NOT NULL,
            cycle_id bigint(20) unsigned NOT NULL,
            leader_enrollment_id bigint(20) unsigned NOT NULL,
            teacher_enrollment_id bigint(20) unsigned NOT NULL,
            classroom_id bigint(20) unsigned DEFAULT NULL,
            visit_number tinyint unsigned NOT NULL DEFAULT 1,
            status varchar(20) NOT NULL DEFAULT 'pending',
            visit_date datetime DEFAULT NULL,
            notes text DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (classroom_visit_id),
            UNIQUE KEY classroom_visit_uuid (classroom_visit_uuid),
            KEY idx_cycle (cycle_id),
            KEY idx_leader (leader_enrollment_id),
            KEY idx_teacher (teacher_enrollment_id)
        ) $charset_collate;";

        // Classroom Visit Submission table (form responses for classroom visits)
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_classroom_visit_submission (
            submission_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            submission_uuid char(36) NOT NULL,
            classroom_visit_id bigint(20) unsigned NOT NULL,
            submitted_by_user_id bigint(20) unsigned NOT NULL,
            instrument_id bigint(20) unsigned NOT NULL,
            role_in_visit varchar(20) NOT NULL,
            responses_json longtext DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'draft',
            submitted_at datetime DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (submission_id),
            UNIQUE KEY submission_uuid (submission_uuid),
            UNIQUE KEY uq_visit_role (classroom_visit_id, role_in_visit),
            KEY idx_visit (classroom_visit_id),
            KEY idx_user (submitted_by_user_id)
        ) $charset_collate;";

        // Coaching Session Submission table (form responses for coaching sessions)
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_coaching_session_submission (
            submission_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            submission_uuid char(36) NOT NULL,
            session_id bigint(20) unsigned NOT NULL,
            submitted_by_user_id bigint(20) unsigned NOT NULL,
            instrument_id bigint(20) unsigned NOT NULL,
            role_in_session varchar(20) NOT NULL,
            responses_json longtext DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'draft',
            submitted_at datetime DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (submission_id),
            UNIQUE KEY submission_uuid (submission_uuid),
            UNIQUE KEY uq_session_role (session_id, role_in_session),
            KEY idx_session (session_id),
            KEY idx_user (submitted_by_user_id)
        ) $charset_collate;";

        // Coach availability (recurring weekly schedule).
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_coach_availability (
            availability_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            coach_user_id bigint(20) unsigned NOT NULL,
            day_of_week tinyint(1) unsigned NOT NULL COMMENT '0=Sun, 6=Sat',
            start_time time NOT NULL,
            end_time time NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (availability_id),
            KEY coach_user_id (coach_user_id),
            KEY coach_day (coach_user_id, day_of_week)
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

    /**
     * Rev 16: Add 'k2' value to hl_classroom.age_band ENUM.
     */
    private static function migrate_classroom_age_band_k2() {
        global $wpdb;
        $table = "{$wpdb->prefix}hl_classroom";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
            $wpdb->query( "ALTER TABLE `{$table}` MODIFY COLUMN `age_band` enum('infant','toddler','preschool','k2','mixed') NULL" );
        }
    }

    /**
     * Rev 17: Add track_type column to hl_track.
     *
     * dbDelta cannot reliably add ENUM/varchar with DEFAULT on existing tables,
     * so we use a direct ALTER TABLE guarded by column-exists check.
     */
    private static function migrate_track_type_enum() {
        global $wpdb;
        $table = "{$wpdb->prefix}hl_track";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        $col = $wpdb->get_row( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'track_type'",
            $table
        ) );

        if ( empty( $col ) ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `track_type` varchar(20) NOT NULL DEFAULT 'program' AFTER `is_control_group`" );
        }
    }

    /**
     * Rev 22: Add new component types for cross-pathway events.
     * Extends the component_type ENUM on hl_component.
     */
    private static function migrate_add_event_component_types() {
        global $wpdb;
        $table = $wpdb->prefix . 'hl_component';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        $wpdb->query( "ALTER TABLE `{$table}` MODIFY COLUMN component_type
            ENUM('learndash_course','teacher_self_assessment','child_assessment',
                 'coaching_session_attendance','observation',
                 'reflective_practice_session','classroom_visit','self_reflection')
            NOT NULL DEFAULT 'learndash_course'" );
    }

    /**
     * Rev 24: Add scheduling integration columns to hl_coaching_session.
     * Idempotent — safe to run multiple times.
     */
    private static function migrate_coaching_scheduling_columns() {
        global $wpdb;
        $table = "{$wpdb->prefix}hl_coaching_session";

        $table_exists = $wpdb->get_var( $wpdb->prepare(
            'SHOW TABLES LIKE %s', $table
        ) ) === $table;
        if ( ! $table_exists ) {
            return;
        }

        $column_exists = function ( $column ) use ( $wpdb, $table ) {
            return ! empty( $wpdb->get_row( $wpdb->prepare(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                $table, $column
            ) ) );
        };

        $columns = array(
            'component_id'      => "ADD COLUMN component_id bigint(20) unsigned NULL COMMENT 'Links to hl_component' AFTER rescheduled_from_session_id",
            'zoom_meeting_id'   => "ADD COLUMN zoom_meeting_id bigint(20) unsigned NULL COMMENT 'Zoom meeting ID' AFTER component_id",
            'outlook_event_id'  => "ADD COLUMN outlook_event_id varchar(255) NULL COMMENT 'Graph event ID' AFTER zoom_meeting_id",
            'booked_by_user_id' => "ADD COLUMN booked_by_user_id bigint(20) unsigned NULL COMMENT 'Booking creator' AFTER outlook_event_id",
            'mentor_timezone'   => "ADD COLUMN mentor_timezone varchar(100) NULL COMMENT 'IANA timezone' AFTER booked_by_user_id",
            'coach_timezone'    => "ADD COLUMN coach_timezone varchar(100) NULL COMMENT 'IANA timezone' AFTER mentor_timezone",
        );

        foreach ( $columns as $col => $alter ) {
            if ( ! $column_exists( $col ) ) {
                $wpdb->query( "ALTER TABLE `{$table}` {$alter}" );
            }
        }

        // Add indexes if missing.
        $index_exists = function ( $index_name ) use ( $wpdb, $table ) {
            return ! empty( $wpdb->get_row( $wpdb->prepare(
                "SELECT INDEX_NAME FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s",
                $table, $index_name
            ) ) );
        };

        if ( ! $index_exists( 'component_id' ) ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD KEY component_id (component_id)" );
        }
        if ( ! $index_exists( 'booked_by_user_id' ) ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD KEY booked_by_user_id (booked_by_user_id)" );
        }
    }

    /**
     * Rev 26: Remove deprecated 'observation' component type.
     * Replaced by 'classroom_visit' which uses native PHP forms.
     */
    private static function migrate_remove_observation_component_type() {
        global $wpdb;
        $table = $wpdb->prefix . 'hl_component';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        // Convert any existing 'observation' rows to 'classroom_visit'
        $wpdb->update(
            $table,
            array( 'component_type' => 'classroom_visit' ),
            array( 'component_type' => 'observation' )
        );

        // Remove 'observation' from the ENUM
        $wpdb->query( "ALTER TABLE `{$table}` MODIFY COLUMN component_type
            ENUM('learndash_course','teacher_self_assessment','child_assessment',
                 'coaching_session_attendance',
                 'reflective_practice_session','classroom_visit','self_reflection')
            NOT NULL" );
    }

    /**
     * Rev 27: Add routing_type column to hl_pathway for auto-assignment routing.
     *
     * routing_type is a VARCHAR(50) that identifies what a pathway represents
     * (e.g., 'teacher_phase_1') independently of its code or name.
     * UNIQUE(cycle_id, routing_type) allows NULL — multiple non-routable pathways
     * coexist per cycle (MySQL InnoDB treats NULL != NULL in UNIQUE indexes).
     */
    private static function migrate_pathway_add_routing_type() {
        global $wpdb;
        $table = "{$wpdb->prefix}hl_pathway";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        // Add column if missing.
        $col_exists = $wpdb->get_row( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
            $table, 'routing_type'
        ) );
        if ( empty( $col_exists ) ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN routing_type varchar(50) DEFAULT NULL AFTER active_status" );
        }

        // Add UNIQUE index if missing.
        $idx_exists = $wpdb->get_row( $wpdb->prepare(
            "SELECT INDEX_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s",
            $table, 'unique_routing_per_cycle'
        ) );
        if ( empty( $idx_exists ) ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD UNIQUE KEY unique_routing_per_cycle (cycle_id, routing_type)" );
        }
    }

    /**
     * Rev 28: Add cycle_id to hl_classroom.
     *
     * 1. Add cycle_id column (DEFAULT 0).
     * 2. Drop old school_classroom UNIQUE, add school_classroom_cycle UNIQUE.
     * 3. Backfill cycle_id from teaching assignments.
     */
    private static function migrate_classroom_add_cycle_id() {
        global $wpdb;
        $table = "{$wpdb->prefix}hl_classroom";

        // Add column if missing.
        $col_exists = $wpdb->get_var( "SHOW COLUMNS FROM `{$table}` LIKE 'cycle_id'" );
        if ( ! $col_exists ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN cycle_id bigint(20) unsigned NOT NULL DEFAULT 0 AFTER school_id" );
        }

        // Drop old UNIQUE and add new one with cycle_id (if old index still exists).
        $old_idx = $wpdb->get_row( $wpdb->prepare(
            "SELECT INDEX_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s",
            $table, 'school_classroom'
        ) );
        if ( $old_idx ) {
            $wpdb->query( "ALTER TABLE `{$table}` DROP INDEX school_classroom" );
        }

        $new_idx = $wpdb->get_row( $wpdb->prepare(
            "SELECT INDEX_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s",
            $table, 'school_classroom_cycle'
        ) );
        if ( ! $new_idx ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD UNIQUE KEY school_classroom_cycle (school_id, classroom_name, cycle_id)" );
        }

        // Add cycle_id index if missing.
        $cycle_idx = $wpdb->get_row( $wpdb->prepare(
            "SELECT INDEX_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s",
            $table, 'cycle_id'
        ) );
        if ( ! $cycle_idx ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD KEY cycle_id (cycle_id)" );
        }

        // Backfill cycle_id from teaching assignments.
        $prefix = $wpdb->prefix;
        $wpdb->query( "
            UPDATE {$table} c
            JOIN (
                SELECT ta.classroom_id, MAX(e.cycle_id) AS cycle_id
                FROM {$prefix}hl_teaching_assignment ta
                JOIN {$prefix}hl_enrollment e ON ta.enrollment_id = e.enrollment_id
                GROUP BY ta.classroom_id
            ) sub ON c.classroom_id = sub.classroom_id
            SET c.cycle_id = sub.cycle_id
            WHERE c.cycle_id = 0
        " );
    }

    /**
     * Phase 32: Add Phase entity.
     *
     * 1. Add phase_id column to hl_pathway (if missing).
     * 2. Auto-create a default Phase for each existing Track that has no Phases yet.
     * 3. Populate phase_id on existing pathways.
     *
     * The hl_phase table itself is created by dbDelta (in get_schema).
     * The track_type column is added by migrate_track_type_enum (Rev 17).
     */
    private static function migrate_add_phase_entity() {
        global $wpdb;

        $pathway_table = "{$wpdb->prefix}hl_pathway";
        $phase_table   = "{$wpdb->prefix}hl_phase";
        $track_table   = "{$wpdb->prefix}hl_track";

        // Helper: check if a column exists.
        $column_exists = function ( $table, $column ) use ( $wpdb ) {
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                $table,
                $column
            ) );
            return ! empty( $row );
        };

        // Guard: skip entirely if V3 grand rename deleted the Phase entity.
        // After V3, hl_phase doesn't exist and hl_cycle is the yearly run (no cycle_number).
        $cycle_table = "{$wpdb->prefix}hl_cycle";
        $phase_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $phase_table ) ) === $phase_table;
        $cycle_has_number = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $cycle_table ) ) === $cycle_table
            && $column_exists( $cycle_table, 'cycle_number' );
        if ( ! $phase_exists && ! $cycle_has_number ) {
            return;
        }

        // 1. Add phase_id to hl_pathway if missing.
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pathway_table ) ) === $pathway_table ) {
            if ( ! $column_exists( $pathway_table, 'phase_id' ) && ! $column_exists( $pathway_table, 'cycle_id' ) ) {
                $wpdb->query( "ALTER TABLE `{$pathway_table}` ADD COLUMN `phase_id` bigint(20) unsigned NULL AFTER `track_id`" );
                $wpdb->query( "ALTER TABLE `{$pathway_table}` ADD KEY `phase_id` (`phase_id`)" );
            }
        }

        // 2. Auto-create default Phase per existing Track (only if hl_phase table exists).
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $phase_table ) ) !== $phase_table ) {
            return; // Table not yet created — dbDelta will handle it, then next activation will populate.
        }

        $tracks = $wpdb->get_results(
            "SELECT t.track_id, t.track_name, t.start_date, t.end_date, t.status
             FROM `{$track_table}` t
             LEFT JOIN `{$phase_table}` ph ON t.track_id = ph.track_id
             WHERE ph.phase_id IS NULL"
        );

        if ( ! empty( $tracks ) ) {
            foreach ( $tracks as $track ) {
                // Map track status to phase status.
                $phase_status = 'draft';
                if ( in_array( $track->status, array( 'active', 'paused' ), true ) ) {
                    $phase_status = 'active';
                } elseif ( $track->status === 'archived' ) {
                    $phase_status = 'completed';
                }

                $wpdb->insert( $phase_table, array(
                    'phase_uuid'   => HL_DB_Utils::generate_uuid(),
                    'track_id'     => $track->track_id,
                    'phase_name'   => 'Phase 1',
                    'phase_number' => 1,
                    'start_date'   => $track->start_date,
                    'end_date'     => $track->end_date,
                    'status'       => $phase_status,
                ) );
            }
        }

        // 3. Populate phase_id on existing pathways that are still NULL.
        if ( $column_exists( $pathway_table, 'phase_id' ) ) {
            $wpdb->query(
                "UPDATE `{$pathway_table}` p
                 JOIN `{$phase_table}` ph ON p.track_id = ph.track_id
                 SET p.phase_id = ph.phase_id
                 WHERE p.phase_id IS NULL"
            );
        }
    }

    /**
     * Rename V2 Phase A1: Rename track → partnership across all tables.
     *
     * 1. RENAME TABLE hl_track → hl_partnership + rename PK/columns
     * 2. RENAME TABLE hl_track_school → hl_partnership_school + rename FK
     * 3. RENAME TABLE hl_child_track_snapshot → hl_child_partnership_snapshot + rename FK
     * 4. Rename track_id → partnership_id in all dependent tables
     * 5. Rename track_completion_percent → partnership_completion_percent in hl_completion_rollup
     *
     * All operations are idempotent — safe to run multiple times.
     */
    private static function migrate_track_to_partnership() {
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

        // ─── 1. RENAME TABLE hl_track → hl_partnership ──────────────────
        $old_track = "{$prefix}hl_track";
        $new_partnership = "{$prefix}hl_partnership";

        if ( $table_exists( $old_track ) && ! $table_exists( $new_partnership ) ) {
            $wpdb->query( "RENAME TABLE `{$old_track}` TO `{$new_partnership}`" );
        }

        // Rename columns inside hl_partnership.
        if ( $table_exists( $new_partnership ) ) {
            if ( $column_exists( $new_partnership, 'track_id' ) ) {
                $wpdb->query( "ALTER TABLE `{$new_partnership}` CHANGE `track_id` `partnership_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT" );
            }
            if ( $column_exists( $new_partnership, 'track_uuid' ) ) {
                $wpdb->query( "ALTER TABLE `{$new_partnership}` CHANGE `track_uuid` `partnership_uuid` char(36) NOT NULL" );
            }
            if ( $column_exists( $new_partnership, 'track_name' ) ) {
                $wpdb->query( "ALTER TABLE `{$new_partnership}` CHANGE `track_name` `partnership_name` varchar(255) NOT NULL" );
            }
            if ( $column_exists( $new_partnership, 'track_code' ) ) {
                $wpdb->query( "ALTER TABLE `{$new_partnership}` CHANGE `track_code` `partnership_code` varchar(100) NOT NULL" );
            }
            if ( $column_exists( $new_partnership, 'track_type' ) ) {
                $wpdb->query( "ALTER TABLE `{$new_partnership}` CHANGE `track_type` `partnership_type` varchar(20) NOT NULL DEFAULT 'program'" );
            }
            // Drop old indexes — dbDelta will recreate with correct names.
            if ( $index_exists( $new_partnership, 'track_uuid' ) ) {
                $wpdb->query( "ALTER TABLE `{$new_partnership}` DROP INDEX `track_uuid`" );
            }
            if ( $index_exists( $new_partnership, 'track_code' ) ) {
                $wpdb->query( "ALTER TABLE `{$new_partnership}` DROP INDEX `track_code`" );
            }
        }

        // ─── 2. RENAME TABLE hl_track_school → hl_partnership_school ────
        $old_ts = "{$prefix}hl_track_school";
        $new_ps = "{$prefix}hl_partnership_school";

        if ( $table_exists( $old_ts ) && ! $table_exists( $new_ps ) ) {
            $wpdb->query( "RENAME TABLE `{$old_ts}` TO `{$new_ps}`" );
        }

        if ( $table_exists( $new_ps ) && $column_exists( $new_ps, 'track_id' ) ) {
            $wpdb->query( "ALTER TABLE `{$new_ps}` CHANGE `track_id` `partnership_id` bigint(20) unsigned NOT NULL" );
            // Drop old indexes — dbDelta will recreate.
            if ( $index_exists( $new_ps, 'track_school' ) ) {
                $wpdb->query( "ALTER TABLE `{$new_ps}` DROP INDEX `track_school`" );
            }
            if ( $index_exists( $new_ps, 'track_id' ) ) {
                $wpdb->query( "ALTER TABLE `{$new_ps}` DROP INDEX `track_id`" );
            }
        }

        // ─── 3. RENAME TABLE hl_child_track_snapshot → hl_child_partnership_snapshot ─
        $old_snap = "{$prefix}hl_child_track_snapshot";
        $new_snap = "{$prefix}hl_child_partnership_snapshot";

        if ( $table_exists( $old_snap ) && ! $table_exists( $new_snap ) ) {
            $wpdb->query( "RENAME TABLE `{$old_snap}` TO `{$new_snap}`" );
        }

        if ( $table_exists( $new_snap ) && $column_exists( $new_snap, 'track_id' ) ) {
            $wpdb->query( "ALTER TABLE `{$new_snap}` CHANGE `track_id` `partnership_id` bigint(20) unsigned NOT NULL" );
            // Drop old indexes — dbDelta will recreate.
            if ( $index_exists( $new_snap, 'child_track' ) ) {
                $wpdb->query( "ALTER TABLE `{$new_snap}` DROP INDEX `child_track`" );
            }
            if ( $index_exists( $new_snap, 'track_id' ) ) {
                $wpdb->query( "ALTER TABLE `{$new_snap}` DROP INDEX `track_id`" );
            }
        }

        // ─── 3b. RENAME TABLE hl_track_email_log → hl_partnership_email_log ─
        $old_email_log = "{$prefix}hl_track_email_log";
        $new_email_log = "{$prefix}hl_partnership_email_log";
        if ( $table_exists( $old_email_log ) && ! $table_exists( $new_email_log ) ) {
            $wpdb->query( "RENAME TABLE `{$old_email_log}` TO `{$new_email_log}`" );
        }

        // ─── 4. Rename track_id → partnership_id in all dependent tables ─
        $tables_with_track_id = array(
            "{$prefix}hl_enrollment"                   => 'NOT NULL',
            "{$prefix}hl_team"                         => 'NOT NULL',
            "{$prefix}hl_phase"                        => 'NOT NULL',
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
            "{$prefix}hl_partnership_email_log"           => 'NOT NULL',
        );

        foreach ( $tables_with_track_id as $table => $nullable ) {
            if ( $table_exists( $table ) && $column_exists( $table, 'track_id' ) ) {
                $wpdb->query( "ALTER TABLE `{$table}` CHANGE `track_id` `partnership_id` bigint(20) unsigned {$nullable}" );
                // Drop old indexes — dbDelta will recreate.
                if ( $index_exists( $table, 'track_id' ) ) {
                    $wpdb->query( "ALTER TABLE `{$table}` DROP INDEX `track_id`" );
                }
            }
        }

        // Drop old composite unique/index keys that reference track_*.
        $enrollment_table = "{$prefix}hl_enrollment";
        if ( $table_exists( $enrollment_table ) && $index_exists( $enrollment_table, 'track_user' ) ) {
            $wpdb->query( "ALTER TABLE `{$enrollment_table}` DROP INDEX `track_user`" );
        }

        $pathway_table = "{$prefix}hl_pathway";
        if ( $table_exists( $pathway_table ) && $index_exists( $pathway_table, 'track_pathway_code' ) ) {
            $wpdb->query( "ALTER TABLE `{$pathway_table}` DROP INDEX `track_pathway_code`" );
        }

        $tsa_table = "{$prefix}hl_teacher_assessment_instance";
        if ( $table_exists( $tsa_table ) && $index_exists( $tsa_table, 'track_enrollment_phase' ) ) {
            $wpdb->query( "ALTER TABLE `{$tsa_table}` DROP INDEX `track_enrollment_phase`" );
        }

        $cai_table = "{$prefix}hl_child_assessment_instance";
        if ( $table_exists( $cai_table ) && $index_exists( $cai_table, 'track_enrollment_classroom_phase' ) ) {
            $wpdb->query( "ALTER TABLE `{$cai_table}` DROP INDEX `track_enrollment_classroom_phase`" );
        }

        $ca_table = "{$prefix}hl_coach_assignment";
        if ( $table_exists( $ca_table ) && $index_exists( $ca_table, 'track_scope' ) ) {
            $wpdb->query( "ALTER TABLE `{$ca_table}` DROP INDEX `track_scope`" );
        }
        if ( $table_exists( $ca_table ) && $index_exists( $ca_table, 'track_coach' ) ) {
            $wpdb->query( "ALTER TABLE `{$ca_table}` DROP INDEX `track_coach`" );
        }

        $phase_table = "{$prefix}hl_phase";
        if ( $table_exists( $phase_table ) && $index_exists( $phase_table, 'track_phase_number' ) ) {
            $wpdb->query( "ALTER TABLE `{$phase_table}` DROP INDEX `track_phase_number`" );
        }

        $email_log_table = "{$prefix}hl_partnership_email_log";
        if ( $table_exists( $email_log_table ) && $index_exists( $email_log_table, 'unique_send' ) ) {
            $wpdb->query( "ALTER TABLE `{$email_log_table}` DROP INDEX `unique_send`" );
        }

        // ─── 5. Rename track_completion_percent → partnership_completion_percent ─
        $rollup_table = "{$prefix}hl_completion_rollup";
        if ( $table_exists( $rollup_table ) && $column_exists( $rollup_table, 'track_completion_percent' ) ) {
            $wpdb->query( "ALTER TABLE `{$rollup_table}` CHANGE `track_completion_percent` `partnership_completion_percent` decimal(5,2) NOT NULL DEFAULT 0.00" );
        }
    }

    /**
     * Rename V2 Phase B1: Rename activity → component across all tables.
     *
     * 1. RENAME TABLE hl_activity → hl_component + rename PK/columns
     * 2. RENAME TABLE hl_activity_state → hl_component_state + rename FK
     * 3. RENAME TABLE hl_activity_prereq_group → hl_component_prereq_group + rename FK
     * 4. RENAME TABLE hl_activity_prereq_item → hl_component_prereq_item + rename FKs
     * 5. RENAME TABLE hl_activity_drip_rule → hl_component_drip_rule + rename FKs
     * 6. RENAME TABLE hl_activity_override → hl_component_override + rename FK
     * 7. Rename activity_id → component_id in dependent tables
     *
     * All operations are idempotent — safe to run multiple times.
     */
    private static function migrate_activity_to_component() {
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

        // ─── 1. RENAME TABLE hl_activity → hl_component ──────────────────
        $old_activity = "{$prefix}hl_activity";
        $new_component = "{$prefix}hl_component";

        if ( $table_exists( $old_activity ) && ! $table_exists( $new_component ) ) {
            $wpdb->query( "RENAME TABLE `{$old_activity}` TO `{$new_component}`" );
        }

        // Rename columns inside hl_component.
        if ( $table_exists( $new_component ) ) {
            if ( $column_exists( $new_component, 'activity_id' ) ) {
                $wpdb->query( "ALTER TABLE `{$new_component}` CHANGE `activity_id` `component_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT" );
            }
            if ( $column_exists( $new_component, 'activity_uuid' ) ) {
                $wpdb->query( "ALTER TABLE `{$new_component}` CHANGE `activity_uuid` `component_uuid` char(36) NOT NULL" );
            }
            if ( $column_exists( $new_component, 'activity_type' ) ) {
                $wpdb->query( "ALTER TABLE `{$new_component}` CHANGE `activity_type` `component_type` enum('learndash_course','teacher_self_assessment','child_assessment','coaching_session_attendance','observation','reflective_practice_session','classroom_visit','self_reflection') NOT NULL" );
            }
            // Drop old indexes — dbDelta will recreate with correct names.
            if ( $index_exists( $new_component, 'activity_uuid' ) ) {
                $wpdb->query( "ALTER TABLE `{$new_component}` DROP INDEX `activity_uuid`" );
            }
            if ( $index_exists( $new_component, 'activity_type' ) ) {
                $wpdb->query( "ALTER TABLE `{$new_component}` DROP INDEX `activity_type`" );
            }
        }

        // ─── 2. RENAME TABLE hl_activity_state → hl_component_state ──────
        $old_state = "{$prefix}hl_activity_state";
        $new_state = "{$prefix}hl_component_state";

        if ( $table_exists( $old_state ) && ! $table_exists( $new_state ) ) {
            $wpdb->query( "RENAME TABLE `{$old_state}` TO `{$new_state}`" );
        }

        if ( $table_exists( $new_state ) && $column_exists( $new_state, 'activity_id' ) ) {
            $wpdb->query( "ALTER TABLE `{$new_state}` CHANGE `activity_id` `component_id` bigint(20) unsigned NOT NULL" );
            // Drop old indexes — dbDelta will recreate.
            if ( $index_exists( $new_state, 'enrollment_activity' ) ) {
                $wpdb->query( "ALTER TABLE `{$new_state}` DROP INDEX `enrollment_activity`" );
            }
            if ( $index_exists( $new_state, 'activity_id' ) ) {
                $wpdb->query( "ALTER TABLE `{$new_state}` DROP INDEX `activity_id`" );
            }
        }

        // ─── 3. RENAME TABLE hl_activity_prereq_group → hl_component_prereq_group ─
        $old_pg = "{$prefix}hl_activity_prereq_group";
        $new_pg = "{$prefix}hl_component_prereq_group";

        if ( $table_exists( $old_pg ) && ! $table_exists( $new_pg ) ) {
            $wpdb->query( "RENAME TABLE `{$old_pg}` TO `{$new_pg}`" );
        }

        if ( $table_exists( $new_pg ) && $column_exists( $new_pg, 'activity_id' ) ) {
            $wpdb->query( "ALTER TABLE `{$new_pg}` CHANGE `activity_id` `component_id` bigint(20) unsigned NOT NULL" );
            if ( $index_exists( $new_pg, 'activity_id' ) ) {
                $wpdb->query( "ALTER TABLE `{$new_pg}` DROP INDEX `activity_id`" );
            }
        }

        // ─── 4. RENAME TABLE hl_activity_prereq_item → hl_component_prereq_item ─
        $old_pi = "{$prefix}hl_activity_prereq_item";
        $new_pi = "{$prefix}hl_component_prereq_item";

        if ( $table_exists( $old_pi ) && ! $table_exists( $new_pi ) ) {
            $wpdb->query( "RENAME TABLE `{$old_pi}` TO `{$new_pi}`" );
        }

        if ( $table_exists( $new_pi ) && $column_exists( $new_pi, 'prerequisite_activity_id' ) ) {
            $wpdb->query( "ALTER TABLE `{$new_pi}` CHANGE `prerequisite_activity_id` `prerequisite_component_id` bigint(20) unsigned NOT NULL" );
            if ( $index_exists( $new_pi, 'prerequisite_activity_id' ) ) {
                $wpdb->query( "ALTER TABLE `{$new_pi}` DROP INDEX `prerequisite_activity_id`" );
            }
        }

        // ─── 5. RENAME TABLE hl_activity_drip_rule → hl_component_drip_rule ─
        $old_dr = "{$prefix}hl_activity_drip_rule";
        $new_dr = "{$prefix}hl_component_drip_rule";

        if ( $table_exists( $old_dr ) && ! $table_exists( $new_dr ) ) {
            $wpdb->query( "RENAME TABLE `{$old_dr}` TO `{$new_dr}`" );
        }

        if ( $table_exists( $new_dr ) ) {
            if ( $column_exists( $new_dr, 'activity_id' ) ) {
                $wpdb->query( "ALTER TABLE `{$new_dr}` CHANGE `activity_id` `component_id` bigint(20) unsigned NOT NULL" );
                if ( $index_exists( $new_dr, 'activity_id' ) ) {
                    $wpdb->query( "ALTER TABLE `{$new_dr}` DROP INDEX `activity_id`" );
                }
            }
            if ( $column_exists( $new_dr, 'base_activity_id' ) ) {
                $wpdb->query( "ALTER TABLE `{$new_dr}` CHANGE `base_activity_id` `base_component_id` bigint(20) unsigned NULL" );
            }
        }

        // ─── 6. RENAME TABLE hl_activity_override → hl_component_override ─
        $old_ov = "{$prefix}hl_activity_override";
        $new_ov = "{$prefix}hl_component_override";

        if ( $table_exists( $old_ov ) && ! $table_exists( $new_ov ) ) {
            $wpdb->query( "RENAME TABLE `{$old_ov}` TO `{$new_ov}`" );
        }

        if ( $table_exists( $new_ov ) && $column_exists( $new_ov, 'activity_id' ) ) {
            $wpdb->query( "ALTER TABLE `{$new_ov}` CHANGE `activity_id` `component_id` bigint(20) unsigned NOT NULL" );
            if ( $index_exists( $new_ov, 'activity_id' ) ) {
                $wpdb->query( "ALTER TABLE `{$new_ov}` DROP INDEX `activity_id`" );
            }
        }

        // ─── 7. Rename activity_id → component_id in dependent tables ────
        $dependent_tables = array(
            "{$prefix}hl_teacher_assessment_instance" => 'NULL',
            "{$prefix}hl_child_assessment_instance"   => 'NULL',
            "{$prefix}hl_observation"                  => 'NULL',
        );

        foreach ( $dependent_tables as $table => $nullable ) {
            if ( $table_exists( $table ) && $column_exists( $table, 'activity_id' ) ) {
                $wpdb->query( "ALTER TABLE `{$table}` CHANGE `activity_id` `component_id` bigint(20) unsigned {$nullable}" );
                if ( $index_exists( $table, 'activity_id' ) ) {
                    $wpdb->query( "ALTER TABLE `{$table}` DROP INDEX `activity_id`" );
                }
            }
        }
    }

    /**
     * Rename V2 Phase C1: Rename phase → cycle (the Phase entity table only).
     *
     * 1. RENAME TABLE hl_phase → hl_cycle + rename PK/columns
     * 2. Rename phase_id → cycle_id in hl_pathway
     * 3. Rename phase_id → cycle_id in hl_partnership_email_log
     *
     * IMPORTANT: The "phase" column in assessment tables (pre/post enum) is a
     * DIFFERENT concept and is NOT renamed here.
     *
     * All operations are idempotent — safe to run multiple times.
     */
    private static function migrate_phase_to_cycle() {
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

        // ─── 1. RENAME TABLE hl_phase → hl_cycle ────────────────────────
        $old_phase = "{$prefix}hl_phase";
        $new_cycle = "{$prefix}hl_cycle";

        if ( $table_exists( $old_phase ) && ! $table_exists( $new_cycle ) ) {
            $wpdb->query( "RENAME TABLE `{$old_phase}` TO `{$new_cycle}`" );
        }

        // Rename columns inside hl_cycle.
        if ( $table_exists( $new_cycle ) ) {
            if ( $column_exists( $new_cycle, 'phase_id' ) ) {
                $wpdb->query( "ALTER TABLE `{$new_cycle}` CHANGE `phase_id` `cycle_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT" );
            }
            if ( $column_exists( $new_cycle, 'phase_uuid' ) ) {
                $wpdb->query( "ALTER TABLE `{$new_cycle}` CHANGE `phase_uuid` `cycle_uuid` char(36) NOT NULL" );
            }
            if ( $column_exists( $new_cycle, 'phase_name' ) ) {
                $wpdb->query( "ALTER TABLE `{$new_cycle}` CHANGE `phase_name` `cycle_name` varchar(255) NOT NULL" );
            }
            if ( $column_exists( $new_cycle, 'phase_number' ) ) {
                $wpdb->query( "ALTER TABLE `{$new_cycle}` CHANGE `phase_number` `cycle_number` int NOT NULL DEFAULT 1" );
            }
            // Drop old indexes — dbDelta will recreate with correct names.
            if ( $index_exists( $new_cycle, 'phase_uuid' ) ) {
                $wpdb->query( "ALTER TABLE `{$new_cycle}` DROP INDEX `phase_uuid`" );
            }
            if ( $index_exists( $new_cycle, 'partnership_phase_number' ) ) {
                $wpdb->query( "ALTER TABLE `{$new_cycle}` DROP INDEX `partnership_phase_number`" );
            }
        }

        // ─── 2. Rename phase_id → cycle_id in hl_pathway ────────────────
        $pathway_table = "{$prefix}hl_pathway";
        if ( $table_exists( $pathway_table ) && $column_exists( $pathway_table, 'phase_id' ) ) {
            $wpdb->query( "ALTER TABLE `{$pathway_table}` CHANGE `phase_id` `cycle_id` bigint(20) unsigned NULL" );
            if ( $index_exists( $pathway_table, 'phase_id' ) ) {
                $wpdb->query( "ALTER TABLE `{$pathway_table}` DROP INDEX `phase_id`" );
            }
        }

        // ─── 3. Rename phase_id → cycle_id in hl_partnership_email_log ──
        $email_log_table = "{$prefix}hl_partnership_email_log";
        if ( $table_exists( $email_log_table ) && $column_exists( $email_log_table, 'phase_id' ) ) {
            $wpdb->query( "ALTER TABLE `{$email_log_table}` CHANGE `phase_id` `cycle_id` bigint(20) unsigned NOT NULL" );
            if ( $index_exists( $email_log_table, 'phase_id' ) ) {
                $wpdb->query( "ALTER TABLE `{$email_log_table}` DROP INDEX `phase_id`" );
            }
            // Drop the unique key that references phase_id — dbDelta will recreate with cycle_id.
            if ( $index_exists( $email_log_table, 'unique_send' ) ) {
                $wpdb->query( "ALTER TABLE `{$email_log_table}` DROP INDEX `unique_send`" );
            }
        }
    }

    /**
     * Rename V3: Corrective grand rename — swap cohort↔partnership, delete Phase entity.
     *
     * V2 mapped entities wrong: code's "Partnership" is actually the yearly run (should be "Cycle"),
     * and code's "Cohort" is the big container (should be "Partnership").
     *
     * V3 corrects this:
     *   hl_cohort      → hl_partnership  (the big container)
     *   hl_partnership  → hl_cycle        (the yearly run)
     *   hl_cycle (Phase entity) → DELETED
     *
     * Uses temp-table pattern to avoid name collisions during the swap.
     * All operations are idempotent — safe to run multiple times.
     */
    private static function migrate_v3_grand_rename() {
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

        // ─── Step 0: Delete old Cycle (Phase) entity ─────────────────────
        // Guard: only if hl_cycle has cycle_number column (signature of Phase entity).
        $cycle_table = "{$prefix}hl_cycle";
        $has_cycle_number = $table_exists( $cycle_table ) && $column_exists( $cycle_table, 'cycle_number' );

        if ( $has_cycle_number ) {
            // Drop cycle_id from hl_pathway (redundant Phase FK).
            $pathway_table = "{$prefix}hl_pathway";
            if ( $table_exists( $pathway_table ) && $column_exists( $pathway_table, 'cycle_id' ) ) {
                if ( $index_exists( $pathway_table, 'cycle_id' ) ) {
                    $wpdb->query( "ALTER TABLE `{$pathway_table}` DROP INDEX `cycle_id`" );
                }
                $wpdb->query( "ALTER TABLE `{$pathway_table}` DROP COLUMN `cycle_id`" );
            }

            // Drop cycle_id from hl_partnership_email_log (redundant Phase FK).
            $email_log = "{$prefix}hl_partnership_email_log";
            if ( $table_exists( $email_log ) && $column_exists( $email_log, 'cycle_id' ) ) {
                if ( $index_exists( $email_log, 'unique_send' ) ) {
                    $wpdb->query( "ALTER TABLE `{$email_log}` DROP INDEX `unique_send`" );
                }
                if ( $index_exists( $email_log, 'cycle_id' ) ) {
                    $wpdb->query( "ALTER TABLE `{$email_log}` DROP INDEX `cycle_id`" );
                }
                $wpdb->query( "ALTER TABLE `{$email_log}` DROP COLUMN `cycle_id`" );
            }

            // Rename old hl_cycle → hl_cycle_v3_old for safety.
            $old_archive = "{$prefix}hl_cycle_v3_old";
            if ( ! $table_exists( $old_archive ) ) {
                $wpdb->query( "RENAME TABLE `{$cycle_table}` TO `{$old_archive}`" );
            }
        }

        // ─── Step 1: Park hl_partnership → temp name ─────────────────────
        $partnership = "{$prefix}hl_partnership";
        $temp_cycle  = "{$prefix}hl_cycle_v3_temp";

        if ( $table_exists( $partnership ) && ! $table_exists( $temp_cycle ) ) {
            // Only park if this is the yearly-run table (has start_date), not the container.
            if ( $column_exists( $partnership, 'start_date' ) ) {
                $wpdb->query( "RENAME TABLE `{$partnership}` TO `{$temp_cycle}`" );
            }
        }

        // Park subsidiary tables.
        $ps      = "{$prefix}hl_partnership_school";
        $temp_cs = "{$prefix}hl_cycle_school_v3_temp";
        if ( $table_exists( $ps ) && ! $table_exists( $temp_cs ) ) {
            $wpdb->query( "RENAME TABLE `{$ps}` TO `{$temp_cs}`" );
        }

        $snap      = "{$prefix}hl_child_partnership_snapshot";
        $temp_snap = "{$prefix}hl_child_cycle_snapshot_v3_temp";
        if ( $table_exists( $snap ) && ! $table_exists( $temp_snap ) ) {
            $wpdb->query( "RENAME TABLE `{$snap}` TO `{$temp_snap}`" );
        }

        $email_log  = "{$prefix}hl_partnership_email_log";
        $temp_email = "{$prefix}hl_cycle_email_log_v3_temp";
        if ( $table_exists( $email_log ) && ! $table_exists( $temp_email ) ) {
            $wpdb->query( "RENAME TABLE `{$email_log}` TO `{$temp_email}`" );
        }

        // ─── Step 2: Promote hl_cohort → hl_partnership ─────────────────
        $cohort = "{$prefix}hl_cohort";

        if ( $table_exists( $cohort ) && ! $table_exists( $partnership ) ) {
            $wpdb->query( "RENAME TABLE `{$cohort}` TO `{$partnership}`" );
        }

        // Rename columns inside the promoted hl_partnership (container).
        if ( $table_exists( $partnership ) && $column_exists( $partnership, 'cohort_id' ) ) {
            $wpdb->query( "ALTER TABLE `{$partnership}` CHANGE `cohort_id` `partnership_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT" );
            if ( $column_exists( $partnership, 'cohort_uuid' ) ) {
                $wpdb->query( "ALTER TABLE `{$partnership}` CHANGE `cohort_uuid` `partnership_uuid` char(36) NOT NULL" );
            }
            if ( $column_exists( $partnership, 'cohort_name' ) ) {
                $wpdb->query( "ALTER TABLE `{$partnership}` CHANGE `cohort_name` `partnership_name` varchar(255) NOT NULL" );
            }
            if ( $column_exists( $partnership, 'cohort_code' ) ) {
                $wpdb->query( "ALTER TABLE `{$partnership}` CHANGE `cohort_code` `partnership_code` varchar(100) NOT NULL" );
            }
            // Drop old indexes — dbDelta will recreate with correct names.
            if ( $index_exists( $partnership, 'cohort_uuid' ) ) {
                $wpdb->query( "ALTER TABLE `{$partnership}` DROP INDEX `cohort_uuid`" );
            }
            if ( $index_exists( $partnership, 'cohort_code' ) ) {
                $wpdb->query( "ALTER TABLE `{$partnership}` DROP INDEX `cohort_code`" );
            }
        }

        // ─── Step 3: Land temp → hl_cycle ────────────────────────────────
        $new_cycle = "{$prefix}hl_cycle";

        if ( $table_exists( $temp_cycle ) && ! $table_exists( $new_cycle ) ) {
            $wpdb->query( "RENAME TABLE `{$temp_cycle}` TO `{$new_cycle}`" );
        }

        // Rename columns inside hl_cycle (the yearly run).
        if ( $table_exists( $new_cycle ) ) {
            if ( $column_exists( $new_cycle, 'partnership_id' ) && ! $column_exists( $new_cycle, 'cycle_id' ) ) {
                $wpdb->query( "ALTER TABLE `{$new_cycle}` CHANGE `partnership_id` `cycle_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT" );
            }
            if ( $column_exists( $new_cycle, 'partnership_uuid' ) ) {
                $wpdb->query( "ALTER TABLE `{$new_cycle}` CHANGE `partnership_uuid` `cycle_uuid` char(36) NOT NULL" );
            }
            if ( $column_exists( $new_cycle, 'partnership_name' ) ) {
                $wpdb->query( "ALTER TABLE `{$new_cycle}` CHANGE `partnership_name` `cycle_name` varchar(255) NOT NULL" );
            }
            if ( $column_exists( $new_cycle, 'partnership_code' ) ) {
                $wpdb->query( "ALTER TABLE `{$new_cycle}` CHANGE `partnership_code` `cycle_code` varchar(100) NOT NULL" );
            }
            if ( $column_exists( $new_cycle, 'partnership_type' ) ) {
                $wpdb->query( "ALTER TABLE `{$new_cycle}` CHANGE `partnership_type` `cycle_type` varchar(20) NOT NULL DEFAULT 'program'" );
            }
            // cohort_id → partnership_id (FK to the new container).
            if ( $column_exists( $new_cycle, 'cohort_id' ) ) {
                $wpdb->query( "ALTER TABLE `{$new_cycle}` CHANGE `cohort_id` `partnership_id` bigint(20) unsigned NULL" );
                if ( $index_exists( $new_cycle, 'cohort_id' ) ) {
                    $wpdb->query( "ALTER TABLE `{$new_cycle}` DROP INDEX `cohort_id`" );
                }
            }
            // Drop old indexes — dbDelta will recreate with correct names.
            if ( $index_exists( $new_cycle, 'partnership_uuid' ) ) {
                $wpdb->query( "ALTER TABLE `{$new_cycle}` DROP INDEX `partnership_uuid`" );
            }
            if ( $index_exists( $new_cycle, 'partnership_code' ) ) {
                $wpdb->query( "ALTER TABLE `{$new_cycle}` DROP INDEX `partnership_code`" );
            }
        }

        // ─── Step 4: Land subsidiary temp tables ─────────────────────────

        // hl_cycle_school
        $new_cs = "{$prefix}hl_cycle_school";
        if ( $table_exists( $temp_cs ) && ! $table_exists( $new_cs ) ) {
            $wpdb->query( "RENAME TABLE `{$temp_cs}` TO `{$new_cs}`" );
        }
        if ( $table_exists( $new_cs ) && $column_exists( $new_cs, 'partnership_id' ) ) {
            $wpdb->query( "ALTER TABLE `{$new_cs}` CHANGE `partnership_id` `cycle_id` bigint(20) unsigned NOT NULL" );
            if ( $index_exists( $new_cs, 'partnership_school' ) ) {
                $wpdb->query( "ALTER TABLE `{$new_cs}` DROP INDEX `partnership_school`" );
            }
            if ( $index_exists( $new_cs, 'partnership_id' ) ) {
                $wpdb->query( "ALTER TABLE `{$new_cs}` DROP INDEX `partnership_id`" );
            }
        }

        // hl_child_cycle_snapshot
        $new_snap = "{$prefix}hl_child_cycle_snapshot";
        if ( $table_exists( $temp_snap ) && ! $table_exists( $new_snap ) ) {
            $wpdb->query( "RENAME TABLE `{$temp_snap}` TO `{$new_snap}`" );
        }
        if ( $table_exists( $new_snap ) && $column_exists( $new_snap, 'partnership_id' ) ) {
            $wpdb->query( "ALTER TABLE `{$new_snap}` CHANGE `partnership_id` `cycle_id` bigint(20) unsigned NOT NULL" );
            if ( $index_exists( $new_snap, 'child_partnership' ) ) {
                $wpdb->query( "ALTER TABLE `{$new_snap}` DROP INDEX `child_partnership`" );
            }
            if ( $index_exists( $new_snap, 'partnership_id' ) ) {
                $wpdb->query( "ALTER TABLE `{$new_snap}` DROP INDEX `partnership_id`" );
            }
        }

        // hl_cycle_email_log
        $new_email = "{$prefix}hl_cycle_email_log";
        if ( $table_exists( $temp_email ) && ! $table_exists( $new_email ) ) {
            $wpdb->query( "RENAME TABLE `{$temp_email}` TO `{$new_email}`" );
        }
        if ( $table_exists( $new_email ) && $column_exists( $new_email, 'partnership_id' ) ) {
            $wpdb->query( "ALTER TABLE `{$new_email}` CHANGE `partnership_id` `cycle_id` bigint(20) unsigned NOT NULL" );
            if ( $index_exists( $new_email, 'partnership_id' ) ) {
                $wpdb->query( "ALTER TABLE `{$new_email}` DROP INDEX `partnership_id`" );
            }
        }

        // ─── Step 5: Rename FK columns in all dependent tables ───────────
        $tables_with_partnership_id = array(
            "{$prefix}hl_enrollment"                   => 'NOT NULL',
            "{$prefix}hl_team"                         => 'NOT NULL',
            "{$prefix}hl_pathway"                      => 'NOT NULL',
            "{$prefix}hl_component"                    => 'NOT NULL',
            "{$prefix}hl_completion_rollup"             => 'NOT NULL',
            "{$prefix}hl_teacher_assessment_instance"   => 'NOT NULL',
            "{$prefix}hl_child_assessment_instance"     => 'NOT NULL',
            "{$prefix}hl_observation"                   => 'NOT NULL',
            "{$prefix}hl_coaching_session"              => 'NOT NULL',
            "{$prefix}hl_coach_assignment"              => 'NOT NULL',
            "{$prefix}hl_import_run"                    => 'NULL',
            "{$prefix}hl_audit_log"                     => 'NULL',
        );

        foreach ( $tables_with_partnership_id as $table => $nullable ) {
            if ( $table_exists( $table ) && $column_exists( $table, 'partnership_id' ) ) {
                $wpdb->query( "ALTER TABLE `{$table}` CHANGE `partnership_id` `cycle_id` bigint(20) unsigned {$nullable}" );
                if ( $index_exists( $table, 'partnership_id' ) ) {
                    $wpdb->query( "ALTER TABLE `{$table}` DROP INDEX `partnership_id`" );
                }
            }
        }

        // Rename partnership_completion_percent → cycle_completion_percent.
        $rollup = "{$prefix}hl_completion_rollup";
        if ( $table_exists( $rollup ) && $column_exists( $rollup, 'partnership_completion_percent' ) ) {
            $wpdb->query( "ALTER TABLE `{$rollup}` CHANGE `partnership_completion_percent` `cycle_completion_percent` decimal(5,2) NOT NULL DEFAULT 0.00" );
        }

        // ─── Step 6: Drop old composite indexes ─────────────────────────
        $enrollment = "{$prefix}hl_enrollment";
        if ( $table_exists( $enrollment ) && $index_exists( $enrollment, 'partnership_user' ) ) {
            $wpdb->query( "ALTER TABLE `{$enrollment}` DROP INDEX `partnership_user`" );
        }

        $tai = "{$prefix}hl_teacher_assessment_instance";
        if ( $table_exists( $tai ) && $index_exists( $tai, 'partnership_enrollment_phase' ) ) {
            $wpdb->query( "ALTER TABLE `{$tai}` DROP INDEX `partnership_enrollment_phase`" );
        }

        $cai = "{$prefix}hl_child_assessment_instance";
        if ( $table_exists( $cai ) && $index_exists( $cai, 'partnership_enrollment_classroom_phase' ) ) {
            $wpdb->query( "ALTER TABLE `{$cai}` DROP INDEX `partnership_enrollment_classroom_phase`" );
        }

        $ca = "{$prefix}hl_coach_assignment";
        if ( $table_exists( $ca ) && $index_exists( $ca, 'partnership_scope' ) ) {
            $wpdb->query( "ALTER TABLE `{$ca}` DROP INDEX `partnership_scope`" );
        }
        if ( $table_exists( $ca ) && $index_exists( $ca, 'partnership_coach' ) ) {
            $wpdb->query( "ALTER TABLE `{$ca}` DROP INDEX `partnership_coach`" );
        }

        // Safety: drop stale index if it somehow ended up on the new cycle table.
        $new_cycle = "{$prefix}hl_cycle";
        if ( $table_exists( $new_cycle ) && $index_exists( $new_cycle, 'partnership_cycle_number' ) ) {
            $wpdb->query( "ALTER TABLE `{$new_cycle}` DROP INDEX `partnership_cycle_number`" );
        }

        // Drop old pathway composite key.
        $pathway = "{$prefix}hl_pathway";
        if ( $table_exists( $pathway ) && $index_exists( $pathway, 'partnership_pathway_code' ) ) {
            $wpdb->query( "ALTER TABLE `{$pathway}` DROP INDEX `partnership_pathway_code`" );
        }
    }
}
