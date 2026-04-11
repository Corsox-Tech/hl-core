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

    // Stubs — filled by later tasks.
    private function test_schema() {}
    private function test_cron() {}
    private function test_drafts() {}
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
    }
    private function test_deliverability() {}
    private function test_audit() {}
}
