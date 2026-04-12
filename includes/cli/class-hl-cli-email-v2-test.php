<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WP-CLI verification suite for Email System v2 Track 3.
 *
 * Usage:
 *   wp hl-core email-v2-test                # run everything
 *   wp hl-core email-v2-test --only=roles   # single group
 *
 * Groups: roles, schema, cron, drafts, resolver, deliverability, audit.
 *
 * @package HL_Core
 */
class HL_CLI_Email_V2_Test {

    /** @var int */
    private $pass = 0;
    /** @var int */
    private $fail = 0;

    public static function register() {
        if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) return;
        WP_CLI::add_command( 'hl-core email-v2-test', array( new self(), 'run' ) );
    }

    /**
     * ## OPTIONS
     *
     * [--only=<group>]
     * : Limit to one group: roles|schema|cron|drafts|resolver|deliverability|audit
     */
    public function run( $args, $assoc_args ) {
        $only = isset( $assoc_args['only'] ) ? $assoc_args['only'] : null;
        $groups = array(
            'roles'          => 'test_roles',
            'schema'         => 'test_schema',
            'cron'           => 'test_cron',
            'drafts'         => 'test_drafts',
            'resolver'       => 'test_resolver',
            'deliverability' => 'test_deliverability',
            'audit'          => 'test_audit',
        );

        foreach ( $groups as $key => $method ) {
            if ( $only && $only !== $key ) continue;
            if ( method_exists( $this, $method ) ) {
                WP_CLI::log( "\n=== {$key} ===" );
                $this->{$method}();
            }
        }

        WP_CLI::log( "\n---- Summary: {$this->pass} passed, {$this->fail} failed ----" );
        if ( $this->fail > 0 ) WP_CLI::halt( 1 );
    }

    private function assert_true( $cond, $label ) {
        if ( $cond ) {
            $this->pass++;
            WP_CLI::log( "  [PASS] {$label}" );
        } else {
            $this->fail++;
            WP_CLI::log( WP_CLI::colorize( "  %R[FAIL]%n {$label}" ) );
        }
    }

    private function assert_equals( $expected, $actual, $label ) {
        $this->assert_true( $expected === $actual, "{$label} (expected " . var_export( $expected, true ) . ", got " . var_export( $actual, true ) . ")" );
    }

    // ---- Test: roles ----
    private function test_roles() {
        // JSON-format parse
        $this->assert_true(
            HL_Roles::has_role( '["teacher","mentor"]', 'teacher' ),
            'has_role() matches teacher inside JSON'
        );
        $this->assert_true(
            ! HL_Roles::has_role( '["school_leader"]', 'leader' ),
            'has_role() does NOT false-match "leader" inside "school_leader"'
        );
        // CSV-format parse
        $this->assert_true(
            HL_Roles::has_role( 'teacher,mentor', 'mentor' ),
            'has_role() matches mentor inside CSV'
        );
        $this->assert_true(
            ! HL_Roles::has_role( 'school_leader,coach', 'leader' ),
            'has_role() does NOT false-match "leader" inside CSV school_leader'
        );
        // sanitize_roles output (sorted alphabetically)
        $this->assert_equals(
            'coach,mentor,teacher',
            HL_Roles::sanitize_roles( array( 'Teacher', ' MENTOR ', 'coach', 'teacher' ) ),
            'sanitize_roles() lowercases, trims, dedupes, sorts consistently'
        );
        // reject poison
        $this->assert_equals(
            'teacher',
            HL_Roles::sanitize_roles( array( 'teacher', 'mentor,coach' ) ),
            'sanitize_roles() rejects role containing comma'
        );
        // parse_stored edge cases (the load-bearing entry point)
        $this->assert_equals(
            array(),
            HL_Roles::parse_stored( null ),
            'parse_stored() returns [] for null'
        );
        $this->assert_equals(
            array(),
            HL_Roles::parse_stored( '' ),
            'parse_stored() returns [] for empty string'
        );
        $this->assert_equals(
            array(),
            HL_Roles::parse_stored( '{"role":"teacher"}' ),
            'parse_stored() rejects JSON object (not an array)'
        );
        $this->assert_equals(
            array( 'teacher' ),
            HL_Roles::parse_stored( '[1, 2, "teacher"]' ),
            'parse_stored() drops non-string JSON array members'
        );
        // scrub_is_complete() — the gating flag for Rev 37 FIND_IN_SET rollout
        delete_option( HL_Roles::OPTION_SCRUB_DONE );
        $this->assert_equals(
            false,
            HL_Roles::scrub_is_complete(),
            'scrub_is_complete() is false when option missing'
        );
        update_option( HL_Roles::OPTION_SCRUB_DONE, '1', false );
        $this->assert_equals(
            true,
            HL_Roles::scrub_is_complete(),
            'scrub_is_complete() is true after option set to "1"'
        );
        // clean up so we don't leave test state on the server
        delete_option( HL_Roles::OPTION_SCRUB_DONE );
    }

    // ---- Test: schema ----
    private function test_schema() {
        global $wpdb;

        $component_table = $wpdb->prefix . 'hl_component';
        $coaching_table  = $wpdb->prefix . 'hl_coaching_session';
        $audit_table     = $wpdb->prefix . 'hl_audit_log';

        // 1. hl_component.available_from column exists AND is DATE type.
        //    DATA_TYPE check guards against a future migration accidentally
        //    creating the column as VARCHAR or similar.
        $col_from_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s
             LIMIT 1",
            $component_table, 'available_from'
        ), ARRAY_A );
        $this->assert_true(
            is_array( $col_from_row )
                && $col_from_row['COLUMN_NAME'] === 'available_from'
                && strtolower( $col_from_row['DATA_TYPE'] ) === 'date',
            'hl_component.available_from column exists with DATE type'
        );

        // 2. hl_component.available_to column exists AND is DATE type.
        $col_to_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s
             LIMIT 1",
            $component_table, 'available_to'
        ), ARRAY_A );
        $this->assert_true(
            is_array( $col_to_row )
                && $col_to_row['COLUMN_NAME'] === 'available_to'
                && strtolower( $col_to_row['DATA_TYPE'] ) === 'date',
            'hl_component.available_to column exists with DATE type'
        );

        // 3. hl_core_schema_revision >= 35.
        $rev = (int) get_option( 'hl_core_schema_revision', 0 );
        $this->assert_true(
            $rev >= 35,
            "hl_core_schema_revision >= 35 (actual: {$rev})"
        );

        // 4. hl_component has composite index type_pathway.
        $idx_type_pathway = $wpdb->get_var( $wpdb->prepare(
            "SELECT INDEX_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s
             LIMIT 1",
            $component_table, 'type_pathway'
        ) );
        $this->assert_true(
            $idx_type_pathway === 'type_pathway',
            'hl_component index type_pathway exists'
        );

        // 5. hl_audit_log has composite index entity_action_time — this is the
        //    foundation-polish addition that keeps HL_Audit_Service::get_last_event()
        //    off the filesort path when Force Resend history loads.
        $idx_entity_action_time = $wpdb->get_var( $wpdb->prepare(
            "SELECT INDEX_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s
             LIMIT 1",
            $audit_table, 'entity_action_time'
        ) );
        $this->assert_true(
            $idx_entity_action_time === 'entity_action_time',
            'hl_audit_log index entity_action_time exists'
        );

        // 6. hl_coaching_session has composite index component_mentor_status —
        //    supports the Task 17 coaching_pre_end query path.
        $idx_component_mentor_status = $wpdb->get_var( $wpdb->prepare(
            "SELECT INDEX_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s
             LIMIT 1",
            $coaching_table, 'component_mentor_status'
        ) );
        $this->assert_true(
            $idx_component_mentor_status === 'component_mentor_status',
            'hl_coaching_session index component_mentor_status exists'
        );
    }
    private function test_cron() {
        $svc = HL_Email_Automation_Service::instance();
        global $wpdb;
        $cycle = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}hl_cycle WHERE status='active' LIMIT 1" );
        if ( ! $cycle ) {
            WP_CLI::log( '  [SKIP] No active cycle — cron smoke test skipped' );
            return;
        }

        $reflection = new ReflectionClass( $svc );
        $method = $reflection->getMethod( 'get_cron_trigger_users' );
        $method->setAccessible( true );

        $triggers = array(
            'cron:cv_window_7d',
            'cron:cv_overdue_1d',
            'cron:rp_window_7d',
            'cron:coaching_window_7d',
            'cron:coaching_pre_end',
        );
        foreach ( $triggers as $t ) {
            try {
                $out = $method->invoke( $svc, $t, $cycle );
                $this->assert_true( is_array( $out ), $t . ' returned an array' );
            } catch ( \Throwable $e ) {
                $this->assert_true( false, $t . ' threw: ' . $e->getMessage() );
            }
        }
    }
    private function test_drafts() {
        global $wpdb;

        // Seed a fake draft option with autoload='yes' so the Rev 36 migration
        // has something to flip. Unique suffix prevents concurrent test runs
        // (or a prior aborted run) from colliding on the same option_name.
        $suffix      = wp_generate_password( 12, false );
        $option_name = 'hl_email_draft_rev36_test_' . $suffix;

        // Direct INSERT (not add_option) so we can force autoload='yes'
        // regardless of what the production draft-save path currently does.
        $wpdb->insert(
            $wpdb->options,
            array(
                'option_name'  => $option_name,
                'option_value' => 'rev36_test_payload',
                'autoload'     => 'yes',
            ),
            array( '%s', '%s', '%s' )
        );
        wp_cache_delete( $option_name, 'options' );
        wp_cache_delete( 'alloptions', 'options' );

        // Invoke the private migration method directly via Closure::bind,
        // mirroring the test_resolver pattern. This avoids having to reset
        // hl_core_schema_revision and re-run maybe_upgrade() (which would
        // touch every other migration in the ladder).
        $invoke_migration = \Closure::bind(
            function () {
                return HL_Installer::migrate_email_drafts_autoload_off();
            },
            null,
            HL_Installer::class
        );
        $migration_ok = $invoke_migration();

        $this->assert_true(
            $migration_ok === true,
            'migrate_email_drafts_autoload_off() returns true'
        );

        // Verify the seeded row's autoload column is now 'no'.
        $autoload_after = $wpdb->get_var( $wpdb->prepare(
            "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            $option_name
        ) );
        $this->assert_equals(
            'no',
            $autoload_after,
            'seeded hl_email_draft_* row flipped to autoload=no'
        );

        // Schema revision must be at or above 36 after Rev 36 ships.
        $rev = (int) get_option( 'hl_core_schema_revision', 0 );
        $this->assert_true(
            $rev >= 36,
            "hl_core_schema_revision >= 36 (actual: {$rev})"
        );

        // Cleanup: drop the seeded option so we don't pollute wp_options.
        delete_option( $option_name );

        // =====================================================================
        // Task 10: cleanup_stale_drafts() sweeper assertions.
        // =====================================================================

        // Invoke the private instance method directly via Closure::bind.
        // Binding to the singleton instance ($newThis) because
        // cleanup_stale_drafts() is a non-static method. We avoid calling
        // run_daily_checks() because that fires all 10 cron workflows and
        // is far heavier than we need for this unit check.
        $invoke_cleanup = \Closure::bind(
            function () {
                $this->cleanup_stale_drafts();
            },
            HL_Email_Automation_Service::instance(),
            HL_Email_Automation_Service::class
        );

        // Seed envelopes as JSON STRINGS (matching what Task 8's
        // ajax_autosave writes via wp_json_encode → update_option).
        // Passing an ARRAY to update_option would PHP-serialize it, and the
        // sweeper's json_decode would see a corrupt envelope — breaking the
        // test. Always mirror the production write path here.

        // ----- Assertion 1: stale draft (> 30 days) is deleted. -------------
        $stale_suffix = wp_generate_password( 6, false );
        $stale_name   = 'hl_email_draft_stale_' . $stale_suffix;
        $stale_env    = wp_json_encode( array(
            'created_at' => gmdate( 'c', time() - 45 * DAY_IN_SECONDS ),
            'updated_at' => gmdate( 'c', time() - 40 * DAY_IN_SECONDS ),
            'payload'    => '{"subject":"old"}',
        ) );
        update_option( $stale_name, $stale_env, 'no' );
        wp_cache_delete( $stale_name, 'options' );
        wp_cache_delete( 'alloptions', 'options' );

        // ----- Assertion 2 setup: fresh draft survives. ---------------------
        $fresh_suffix = wp_generate_password( 6, false );
        $fresh_name   = 'hl_email_draft_fresh_' . $fresh_suffix;
        $fresh_env    = wp_json_encode( array(
            'created_at' => gmdate( 'c' ),
            'updated_at' => gmdate( 'c' ),
            'payload'    => '{"subject":"new"}',
        ) );
        update_option( $fresh_name, $fresh_env, 'no' );
        wp_cache_delete( $fresh_name, 'options' );
        wp_cache_delete( 'alloptions', 'options' );

        // ----- Assertion 3 setup: corrupt envelope is preserved. ------------
        $corrupt_suffix = wp_generate_password( 6, false );
        $corrupt_name   = 'hl_email_draft_corrupt_' . $corrupt_suffix;
        // Direct INSERT so we can store a raw non-JSON value without
        // update_option serializing it behind our back.
        $wpdb->insert(
            $wpdb->options,
            array(
                'option_name'  => $corrupt_name,
                'option_value' => 'THIS IS NOT JSON',
                'autoload'     => 'no',
            ),
            array( '%s', '%s', '%s' )
        );
        wp_cache_delete( $corrupt_name, 'options' );
        wp_cache_delete( 'alloptions', 'options' );

        // Single sweep covers all three seeded rows.
        $invoke_cleanup();

        // Flush caches so get_option reads the post-sweep DB state.
        wp_cache_delete( $stale_name, 'options' );
        wp_cache_delete( $fresh_name, 'options' );
        wp_cache_delete( $corrupt_name, 'options' );
        wp_cache_delete( 'alloptions', 'options' );

        // Assertion 1: stale draft was deleted.
        $this->assert_true(
            get_option( $stale_name, null ) === null,
            'cleanup_stale_drafts() deletes draft with updated_at > 30 days old'
        );

        // Assertion 2: fresh draft survived the sweep.
        $fresh_after_raw = get_option( $fresh_name, null );
        $fresh_after     = is_string( $fresh_after_raw ) ? json_decode( $fresh_after_raw, true ) : null;
        $this->assert_true(
            is_array( $fresh_after )
                && isset( $fresh_after['payload'] )
                && false !== strpos( (string) $fresh_after['payload'], '"subject":"new"' ),
            'cleanup_stale_drafts() preserves draft with fresh updated_at'
        );

        // Assertion 3: corrupt envelope was NOT deleted — still present in DB.
        $corrupt_raw = $wpdb->get_var( $wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            $corrupt_name
        ) );
        $this->assert_equals(
            'THIS IS NOT JSON',
            $corrupt_raw,
            'cleanup_stale_drafts() skips corrupt envelope (row preserved)'
        );

        // Cleanup: drop the seeded rows and the direct-inserted corrupt row.
        delete_option( $stale_name );
        delete_option( $fresh_name );
        $wpdb->delete( $wpdb->options, array( 'option_name' => $corrupt_name ), array( '%s' ) );
        wp_cache_delete( $corrupt_name, 'options' );
        wp_cache_delete( 'alloptions', 'options' );
    }
    private function test_resolver() {
        $ev = HL_Email_Condition_Evaluator::instance();

        // JSON-stored roles
        $ctx_json = array( 'enrollment' => array( 'roles' => '["teacher","mentor"]' ) );

        $this->assert_true(
            $ev->evaluate(
                array( array( 'field' => 'enrollment.roles', 'op' => 'eq', 'value' => 'teacher' ) ),
                $ctx_json
            ),
            'Condition: enrollment.roles eq teacher (JSON stored) passes'
        );
        $this->assert_true(
            ! $ev->evaluate(
                array( array( 'field' => 'enrollment.roles', 'op' => 'eq', 'value' => 'leader' ) ),
                array( 'enrollment' => array( 'roles' => '["school_leader"]' ) )
            ),
            'Condition: enrollment.roles eq leader (JSON stored) does NOT false-match school_leader'
        );

        // CSV-stored roles
        $ctx_csv = array( 'enrollment' => array( 'roles' => 'school_leader,coach' ) );

        $this->assert_true(
            $ev->evaluate(
                array( array( 'field' => 'enrollment.roles', 'op' => 'in', 'value' => array( 'coach', 'mentor' ) ) ),
                $ctx_csv
            ),
            'Condition: enrollment.roles in [coach,mentor] (CSV stored) matches coach'
        );
        $this->assert_true(
            ! $ev->evaluate(
                array( array( 'field' => 'enrollment.roles', 'op' => 'eq', 'value' => 'leader' ) ),
                $ctx_csv
            ),
            'Condition: enrollment.roles eq leader (CSV stored) does NOT false-match school_leader'
        );

        // Boundary: not_in with a matching role must return false
        $this->assert_true(
            ! $ev->evaluate(
                array( array( 'field' => 'enrollment.roles', 'op' => 'not_in', 'value' => array( 'teacher' ) ) ),
                $ctx_json
            ),
            'Condition: enrollment.roles not_in [teacher] (JSON stored with teacher) correctly returns false'
        );

        // Boundary: is_null on empty stored roles
        $this->assert_true(
            $ev->evaluate(
                array( array( 'field' => 'enrollment.roles', 'op' => 'is_null', 'value' => null ) ),
                array( 'enrollment' => array( 'roles' => '' ) )
            ),
            'Condition: enrollment.roles is_null on empty string passes'
        );
        // Boundary: is_null on actual PHP null (matches DB NULL)
        $this->assert_true(
            $ev->evaluate(
                array( array( 'field' => 'enrollment.roles', 'op' => 'is_null', 'value' => null ) ),
                array( 'enrollment' => array( 'roles' => null ) )
            ),
            'Condition: enrollment.roles is_null on PHP null passes'
        );
        // Boundary: neq positive — role that is NOT present should return true
        $this->assert_true(
            $ev->evaluate(
                array( array( 'field' => 'enrollment.roles', 'op' => 'neq', 'value' => 'principal' ) ),
                $ctx_json
            ),
            'Condition: enrollment.roles neq principal (absent) passes'
        );
        // Boundary: not_null on populated roles
        $this->assert_true(
            $ev->evaluate(
                array( array( 'field' => 'enrollment.roles', 'op' => 'not_null', 'value' => null ) ),
                $ctx_json
            ),
            'Condition: enrollment.roles not_null on populated roles passes'
        );
        // Boundary: unsupported op (gt) on roles field is rejected (returns false)
        $this->assert_true(
            ! $ev->evaluate(
                array( array( 'field' => 'enrollment.roles', 'op' => 'gt', 'value' => 5 ) ),
                $ctx_json
            ),
            'Condition: enrollment.roles gt (unsupported op) correctly rejected'
        );
        // Boundary: `in` with zero matches must return false
        $this->assert_true(
            ! $ev->evaluate(
                array( array( 'field' => 'enrollment.roles', 'op' => 'in', 'value' => array( 'principal', 'parent' ) ) ),
                $ctx_json
            ),
            'Condition: enrollment.roles in [principal,parent] (no overlap) correctly returns false'
        );
        // Boundary: `neq` negative — role present means neq returns false (symmetry with eq)
        $this->assert_true(
            ! $ev->evaluate(
                array( array( 'field' => 'enrollment.roles', 'op' => 'neq', 'value' => 'teacher' ) ),
                $ctx_json
            ),
            'Condition: enrollment.roles neq teacher (present) correctly returns false'
        );
        // Boundary: case-insensitive match — workflow author submits "Teacher" with capital T
        $this->assert_true(
            $ev->evaluate(
                array( array( 'field' => 'enrollment.roles', 'op' => 'eq', 'value' => 'Teacher' ) ),
                $ctx_json
            ),
            'Condition: enrollment.roles eq "Teacher" (mixed case) matches lowercase stored value'
        );
        // Typo guard: enrollment.Roles (capital R) must fail-closed AND emit a typo audit row.
        // Delete the typo transient first so the audit call isn't suppressed by rate limit.
        delete_transient( 'hl_condition_typo_' . sha1( 'enrollment.Roles' ) );
        global $wpdb;
        $typo_snapshot_log_id = (int) $wpdb->get_var(
            "SELECT COALESCE(MAX(log_id), 0) FROM {$wpdb->prefix}hl_audit_log"
        );
        $this->assert_true(
            ! $ev->evaluate(
                array( array( 'field' => 'enrollment.Roles', 'op' => 'eq', 'value' => 'teacher' ) ),
                $ctx_json
            ),
            'Condition: enrollment.Roles (capital R typo) fails closed (returns false)'
        );
        $typo_audit_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}hl_audit_log
             WHERE action_type = %s AND log_id > %d",
            'email_condition_field_typo',
            $typo_snapshot_log_id
        ) );
        $this->assert_true(
            $typo_audit_count > 0,
            'Condition: enrollment.Roles typo emits email_condition_field_typo audit row'
        );
        // Cleanup: delete only our own rows + clear the transient we set.
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}hl_audit_log
             WHERE action_type = %s AND log_id > %d",
            'email_condition_field_typo',
            $typo_snapshot_log_id
        ) );
        delete_transient( 'hl_condition_typo_' . sha1( 'enrollment.Roles' ) );

        // assigned_mentor live smoke test: find a team that has BOTH a member
        // enrollment AND a distinct mentor enrollment. Assert count===1 and
        // that the returned user_id matches the team's mentor enrollment's
        // user_id (queried explicitly). If no such fixture exists, log
        // [SKIP] — do not fake a pass.
        $mentor_fixture = $wpdb->get_row(
            "SELECT m_tm.enrollment_id AS member_enrollment_id,
                    x_tm.enrollment_id AS mentor_enrollment_id,
                    t.cycle_id
             FROM {$wpdb->prefix}hl_team_membership m_tm
             INNER JOIN {$wpdb->prefix}hl_team_membership x_tm
                 ON x_tm.team_id = m_tm.team_id
                AND x_tm.membership_type = 'mentor'
                AND x_tm.enrollment_id <> m_tm.enrollment_id
             INNER JOIN {$wpdb->prefix}hl_team t
                 ON t.team_id = m_tm.team_id
             WHERE m_tm.membership_type = 'member'
             LIMIT 1"
        );
        if ( $mentor_fixture ) {
            $member_user_id  = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT user_id FROM {$wpdb->prefix}hl_enrollment WHERE enrollment_id = %d",
                $mentor_fixture->member_enrollment_id
            ) );
            $expected_mentor_user_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT user_id FROM {$wpdb->prefix}hl_enrollment WHERE enrollment_id = %d",
                $mentor_fixture->mentor_enrollment_id
            ) );
            if ( $member_user_id && $expected_mentor_user_id ) {
                $resolver = HL_Email_Recipient_Resolver::instance();
                $out      = $resolver->resolve(
                    array( 'primary' => array( 'assigned_mentor' ), 'cc' => array() ),
                    array( 'user_id' => $member_user_id, 'cycle_id' => (int) $mentor_fixture->cycle_id )
                );
                $this->assert_equals(
                    1, count( $out ),
                    'assigned_mentor returns exactly 1 recipient for a member with a distinct mentor'
                );
                if ( count( $out ) === 1 ) {
                    $this->assert_equals(
                        $expected_mentor_user_id, (int) $out[0]['user_id'],
                        'assigned_mentor returned user_id matches the team mentor enrollment'
                    );
                }
                // R2 §2: happy-path resolver must NOT emit email_resolver_db_error
                // (no DB error occurred during the query chain above).
                $dberr_snapshot_happy = (int) $wpdb->get_var(
                    "SELECT COALESCE(MAX(log_id), 0) FROM {$wpdb->prefix}hl_audit_log"
                );
                $resolver_happy = HL_Email_Recipient_Resolver::instance();
                $resolver_happy->resolve(
                    array( 'primary' => array( 'assigned_mentor' ), 'cc' => array() ),
                    array( 'user_id' => $member_user_id, 'cycle_id' => (int) $mentor_fixture->cycle_id )
                );
                $happy_err_count = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}hl_audit_log
                     WHERE action_type = %s AND log_id > %d",
                    'email_resolver_db_error',
                    $dberr_snapshot_happy
                ) );
                $this->assert_equals(
                    0, $happy_err_count,
                    'happy-path assigned_mentor resolve emits zero email_resolver_db_error rows'
                );
            } else {
                WP_CLI::log( '  [SKIP] Fixture enrollments have null user_id — assigned_mentor live check skipped' );
            }
        } else {
            WP_CLI::log( '  [SKIP] No team has both a member and a distinct mentor — assigned_mentor live check skipped' );
        }

        // Self-mentor exclusion test: if a mentor enrollment exists whose user
        // is the triggering user, resolving assigned_mentor with that user as
        // context must return []. Proves the self-join exclusion added in Task 23.
        $self_mentor_row = $wpdb->get_row(
            "SELECT tm.enrollment_id, t.cycle_id, e.user_id
             FROM {$wpdb->prefix}hl_team_membership tm
             INNER JOIN {$wpdb->prefix}hl_team t ON t.team_id = tm.team_id
             INNER JOIN {$wpdb->prefix}hl_enrollment e ON e.enrollment_id = tm.enrollment_id
             WHERE tm.membership_type = 'mentor' AND e.user_id IS NOT NULL
             LIMIT 1"
        );
        if ( $self_mentor_row && $self_mentor_row->user_id ) {
            // Additionally verify this mentor is the ONLY mentor of their team
            // (otherwise another mentor would legitimately resolve and the test
            // is invalid).
            $other_mentor_count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}hl_team_membership x_tm
                 INNER JOIN {$wpdb->prefix}hl_team_membership self_tm
                     ON self_tm.team_id = x_tm.team_id
                 WHERE self_tm.enrollment_id = %d
                   AND x_tm.membership_type = 'mentor'
                   AND x_tm.enrollment_id <> self_tm.enrollment_id",
                (int) $self_mentor_row->enrollment_id
            ) );
            if ( $other_mentor_count === 0 ) {
                $resolver_self = HL_Email_Recipient_Resolver::instance();
                $self_out      = $resolver_self->resolve(
                    array( 'primary' => array( 'assigned_mentor' ), 'cc' => array() ),
                    array(
                        'user_id'  => (int) $self_mentor_row->user_id,
                        'cycle_id' => (int) $self_mentor_row->cycle_id,
                    )
                );
                $this->assert_equals(
                    0, count( $self_out ),
                    'assigned_mentor self-mentor exclusion: mentor is not their own mentor'
                );
            } else {
                WP_CLI::log( '  [SKIP] Lone-mentor team has co-mentors — self-mentor exclusion check skipped' );
            }
        } else {
            WP_CLI::log( '  [SKIP] No mentor enrollment with a non-null user_id — self-mentor exclusion check skipped' );
        }

        // cc_teacher alias must route to observed_teacher AND emit audit.
        // Snapshot the max log_id BEFORE invoking so cleanup is bounded to
        // just our own rows (never wipe pre-existing deprecation telemetry).
        $snapshot_max_log_id = (int) $wpdb->get_var(
            "SELECT COALESCE(MAX(log_id), 0) FROM {$wpdb->prefix}hl_audit_log"
        );

        $resolver_alias = HL_Email_Recipient_Resolver::instance();
        $alias_ctx      = array( 'observed_teacher_user_id' => get_current_user_id() ?: 1 );
        $alias_out      = $resolver_alias->resolve(
            array( 'primary' => array( 'cc_teacher' ), 'cc' => array() ),
            $alias_ctx
        );
        $this->assert_true(
            is_array( $alias_out ),
            'cc_teacher alias still resolves (returns array)'
        );
        // Verify the audit event was emitted — query by log_id > snapshot to
        // avoid timezone drift between gmdate() and MySQL CURRENT_TIMESTAMP
        // (the column default is server-TZ, not UTC).
        $new_alias_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}hl_audit_log
             WHERE action_type = %s AND log_id > %d",
            'email_token_alias_hit',
            $snapshot_max_log_id
        ) );
        $this->assert_true(
            $new_alias_count > 0,
            'cc_teacher alias emits email_token_alias_hit audit row'
        );

        // Cleanup: delete ONLY the rows we just inserted (bounded by snapshot).
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}hl_audit_log
             WHERE action_type = %s AND log_id > %d",
            'email_token_alias_hit',
            $snapshot_max_log_id
        ) );

        // Task 3 regression: role:leader must NOT return school_leader users.
        // Find any existing school_leader enrollment in any cycle; if one exists,
        // resolve role:leader in that cycle and assert zero matches.
        $sl_row = $wpdb->get_row(
            "SELECT e.cycle_id FROM {$wpdb->prefix}hl_enrollment e
             WHERE e.roles LIKE '%school_leader%'
             LIMIT 1"
        );
        if ( $sl_row ) {
            // Count any enrollments in that cycle that legitimately carry 'leader' as a role slug.
            // If zero legitimate leader rows exist, role:leader MUST return zero rows
            // (proving the substring false-match is closed).
            $candidates = $wpdb->get_results( $wpdb->prepare(
                "SELECT e.roles FROM {$wpdb->prefix}hl_enrollment e
                 WHERE e.cycle_id = %d AND e.roles LIKE '%%leader%%'",
                (int) $sl_row->cycle_id
            ) );
            $legit_leader_rows = 0;
            foreach ( (array) $candidates as $c ) {
                if ( HL_Roles::has_role( $c->roles, 'leader' ) ) {
                    $legit_leader_rows++;
                }
            }
            if ( $legit_leader_rows === 0 ) {
                $resolver_r = HL_Email_Recipient_Resolver::instance();
                $leader_out = $resolver_r->resolve(
                    array( 'primary' => array( 'role:leader' ), 'cc' => array() ),
                    array( 'user_id' => 0, 'cycle_id' => (int) $sl_row->cycle_id )
                );
                $this->assert_equals(
                    0, count( $leader_out ),
                    'role:leader does NOT false-match school_leader in cycle with no legitimate leader rows'
                );
            } else {
                WP_CLI::log( '  [SKIP] Cycle has legitimate leader rows — substring false-match check inconclusive' );
            }
        } else {
            WP_CLI::log( '  [SKIP] No school_leader enrollments seeded — role:leader substring check skipped' );
        }

        // Task 4 regression: resolve_role rejects comma-poisoned role input
        // AND emits the email_resolver_rejected_role audit row. Snapshot
        // log_id before invoking so cleanup is bounded to our own rows.
        //
        // R2 note: clear the per-value rate-limit transient first so the
        // audit row fires even on repeat test runs within 5 minutes.
        $poison_value = 'teacher,mentor';
        $poison_lock  = 'hl_resolver_rejected_role_' . substr( sha1( strtolower( trim( $poison_value ) ) ), 0, 16 );
        delete_transient( $poison_lock );
        $poison_snapshot_log_id = (int) $wpdb->get_var(
            "SELECT COALESCE(MAX(log_id), 0) FROM {$wpdb->prefix}hl_audit_log"
        );
        $resolver_poison = HL_Email_Recipient_Resolver::instance();
        $poison_out      = $resolver_poison->resolve(
            array( 'primary' => array( 'role:' . $poison_value ), 'cc' => array() ),
            array( 'user_id' => 0, 'cycle_id' => 1 )
        );
        $this->assert_equals(
            0, count( $poison_out ),
            'role:teacher,mentor (poison) correctly rejected with zero results'
        );
        // Prove the rejection path actually fired (not just that results were empty)
        $poison_audit_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}hl_audit_log
             WHERE action_type = %s AND log_id > %d",
            'email_resolver_rejected_role',
            $poison_snapshot_log_id
        ) );
        $this->assert_true(
            $poison_audit_count > 0,
            'Poison rejection emits email_resolver_rejected_role audit row'
        );
        // Cleanup: delete ONLY the rows we just inserted (bounded by snapshot)
        // and clear the rate-limit transient so the next run starts fresh.
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}hl_audit_log
             WHERE action_type = %s AND log_id > %d",
            'email_resolver_rejected_role',
            $poison_snapshot_log_id
        ) );
        delete_transient( $poison_lock );

        // R2 §1: rejected_role audit is rate-limited per-value. Clear any
        // existing transient, snapshot log_id, call resolve() twice with the
        // SAME poison value, assert exactly 1 new row (not 2).
        $rl_poison_value = 'teacher,mentor,coach'; // distinct from prior poison test
        $rl_lock_key     = 'hl_resolver_rejected_role_' . substr( sha1( strtolower( trim( $rl_poison_value ) ) ), 0, 16 );
        delete_transient( $rl_lock_key );
        $rl_snapshot = (int) $wpdb->get_var(
            "SELECT COALESCE(MAX(log_id), 0) FROM {$wpdb->prefix}hl_audit_log"
        );
        $rl_resolver = HL_Email_Recipient_Resolver::instance();
        // Two back-to-back calls with the same value — second should be rate-limited.
        $rl_resolver->resolve(
            array( 'primary' => array( 'role:' . $rl_poison_value ), 'cc' => array() ),
            array( 'user_id' => 0, 'cycle_id' => 1 )
        );
        $rl_resolver->resolve(
            array( 'primary' => array( 'role:' . $rl_poison_value ), 'cc' => array() ),
            array( 'user_id' => 0, 'cycle_id' => 1 )
        );
        $rl_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}hl_audit_log
             WHERE action_type = %s AND log_id > %d",
            'email_resolver_rejected_role',
            $rl_snapshot
        ) );
        $this->assert_equals(
            1, $rl_count,
            'rejected_role audit rate-limited: 2 back-to-back poison calls produce exactly 1 audit row'
        );
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}hl_audit_log
             WHERE action_type = %s AND log_id > %d",
            'email_resolver_rejected_role',
            $rl_snapshot
        ) );
        delete_transient( $rl_lock_key );

        // R2 §2: positive forced-error path. Inject a simulated
        // $wpdb->last_error, then invoke the private log_db_error_if_any
        // helper directly via Closure::bind to exercise the production code
        // path without a test-only public wrapper. The SIMULATED marker is
        // grep-friendly so operators can distinguish test noise from real
        // failures if this ever runs against prod.
        $forced_lock = 'hl_resolver_db_error_' . substr( sha1( 'role:user_query' ), 0, 16 );
        delete_transient( $forced_lock );

        $dberr_snapshot_forced = (int) $wpdb->get_var(
            "SELECT COALESCE(MAX(log_id), 0) FROM {$wpdb->prefix}hl_audit_log"
        );

        $wpdb->last_error = 'SIMULATED_TEST_ERROR_DO_NOT_ALARM';

        $invoke_helper = \Closure::bind(
            function () {
                $this->log_db_error_if_any( 'role:user_query' );
            },
            HL_Email_Recipient_Resolver::instance(),
            HL_Email_Recipient_Resolver::class
        );
        $invoke_helper();

        $forced_err_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}hl_audit_log
             WHERE action_type = %s AND log_id > %d AND reason LIKE %s",
            'email_resolver_db_error',
            $dberr_snapshot_forced,
            '%SIMULATED_TEST_ERROR_DO_NOT_ALARM%'
        ) );
        $this->assert_equals(
            1, $forced_err_count,
            'Forced wpdb->last_error emits exactly 1 email_resolver_db_error row with SIMULATED marker'
        );

        // Verify the helper cleared $wpdb->last_error so subsequent queries
        // aren't misattributed (this is a load-bearing invariant).
        $this->assert_equals(
            '',
            (string) $wpdb->last_error,
            'log_db_error_if_any() resets $wpdb->last_error after logging'
        );

        // Cleanup: bounded DELETE + transient clear.
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}hl_audit_log
             WHERE action_type = %s AND log_id > %d",
            'email_resolver_db_error',
            $dberr_snapshot_forced
        ) );
        delete_transient( $forced_lock );
    }
    private function test_deliverability() {}
    private function test_audit() {
        $test_entity_id = 987654321; // unlikely to collide with any real entity

        // log() must not throw on any input — A.3.8 contract
        $threw = false;
        try {
            HL_Audit_Service::log( 'email_v2_test_log', array(
                'entity_type' => 'test',
                'entity_id'   => $test_entity_id,
                'reason'      => 'CLI self-check',
            ) );
        } catch ( \Throwable $e ) {
            $threw = true;
            WP_CLI::log( '  (log threw: ' . $e->getMessage() . ')' );
        }
        $this->assert_true( ! $threw, 'HL_Audit_Service::log() did not throw on valid input' );

        // get_last_event() should return the row we just inserted
        $row = HL_Audit_Service::get_last_event( $test_entity_id, 'email_v2_test_log' );
        $this->assert_true(
            is_array( $row ) && isset( $row['action_type'] ) && $row['action_type'] === 'email_v2_test_log',
            'get_last_event() returns the row just inserted'
        );

        // get_last_event() returns null when no match exists
        $none = HL_Audit_Service::get_last_event( 999999999, 'email_v2_nonexistent_action' );
        $this->assert_true(
            $none === null,
            'get_last_event() returns null for unknown entity+action'
        );

        // get_last_event() returns null for zero/falsy entity_id — guards
        // against accidentally matching every system-event row.
        $zero = HL_Audit_Service::get_last_event( 0, 'anything' );
        $this->assert_true(
            $zero === null,
            'get_last_event(0, ...) returns null (zero-entity guard)'
        );

        // Cleanup: delete the test rows we just inserted so we don't pollute the audit log
        global $wpdb;
        $wpdb->delete(
            $wpdb->prefix . 'hl_audit_log',
            array( 'action_type' => 'email_v2_test_log', 'entity_id' => $test_entity_id )
        );
    }
}
