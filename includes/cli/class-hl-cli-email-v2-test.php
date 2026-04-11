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
    private function test_resolver() {}
    private function test_deliverability() {}
    private function test_audit() {}
}
