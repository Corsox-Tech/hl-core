<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Diagnostic CLI command: check enrollment data and navigation issues per cycle.
 *
 * Usage: wp hl-core diagnose-nav [--cycle_id=<id>] [--user_id=<id>]
 */
class HL_CLI_Diagnose_Nav {

    public static function register() {
        if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) return;
        WP_CLI::add_command( 'hl-core diagnose-nav', array( new self(), 'run' ) );
    }

    public function run( $args, $assoc_args ) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        $cycle_id = isset( $assoc_args['cycle_id'] ) ? absint( $assoc_args['cycle_id'] ) : 0;
        $user_id  = isset( $assoc_args['user_id'] ) ? absint( $assoc_args['user_id'] ) : 0;

        // ── List all cycles ──
        if ( ! $cycle_id ) {
            WP_CLI::log( "\n=== ALL CYCLES ===" );
            $cycles = $wpdb->get_results( "SELECT cycle_id, cycle_name, cycle_type, is_control_group, status FROM {$prefix}hl_cycle ORDER BY cycle_id" );
            foreach ( $cycles as $c ) {
                $ctrl = $c->is_control_group ? ' [CONTROL]' : '';
                $enroll_count = $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$prefix}hl_enrollment WHERE cycle_id = %d AND status = 'active'",
                    $c->cycle_id
                ) );
                WP_CLI::log( sprintf( '  Cycle %d: %s (%s%s) — %d active enrollments',
                    $c->cycle_id, $c->cycle_name, $c->status, $ctrl, $enroll_count
                ) );
            }
            WP_CLI::log( "\nRun with --cycle_id=<id> for detailed diagnosis." );
            return;
        }

        // ── Cycle detail ──
        $cycle = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$prefix}hl_cycle WHERE cycle_id = %d", $cycle_id
        ) );
        if ( ! $cycle ) {
            WP_CLI::error( "Cycle {$cycle_id} not found." );
        }

        WP_CLI::log( "\n=== CYCLE: {$cycle->cycle_name} (ID: {$cycle_id}) ===" );
        WP_CLI::log( "  Type: {$cycle->cycle_type}" );
        WP_CLI::log( "  Control group: " . ( $cycle->is_control_group ? 'YES' : 'no' ) );
        WP_CLI::log( "  Status: {$cycle->status}" );

        // ── All enrollments in this cycle ──
        $enrollments = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.enrollment_id, e.user_id, u.display_name, u.user_email,
                    e.roles, e.school_id, e.district_id, e.status,
                    e.assigned_pathway_id,
                    ou.name AS school_name
             FROM {$prefix}hl_enrollment e
             LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
             LEFT JOIN {$prefix}hl_orgunit ou ON e.school_id = ou.orgunit_id
             WHERE e.cycle_id = %d
             ORDER BY e.status DESC, u.display_name ASC",
            $cycle_id
        ) );

        WP_CLI::log( "\n=== ENROLLMENTS (" . count( $enrollments ) . " total) ===" );
        WP_CLI::log( sprintf( '  %-5s %-7s %-25s %-30s %-10s %-8s %-30s',
            'E_ID', 'U_ID', 'Name', 'Roles', 'SchoolID', 'Status', 'School Name'
        ) );
        WP_CLI::log( str_repeat( '-', 120 ) );

        $missing_school = 0;
        $roles_seen     = array();
        foreach ( $enrollments as $e ) {
            $roles_arr = HL_Roles::parse_stored( $e->roles );
            $roles_str = implode( ', ', $roles_arr );
            foreach ( $roles_arr as $r ) {
                $roles_seen[ $r ] = ( $roles_seen[ $r ] ?? 0 ) + 1;
            }

            $school_flag = '';
            if ( empty( $e->school_id ) ) {
                $missing_school++;
                $school_flag = ' ⚠ MISSING';
            }

            WP_CLI::log( sprintf( '  %-5d %-7d %-25s %-30s %-8s%s %-8s %-30s',
                $e->enrollment_id,
                $e->user_id,
                substr( $e->display_name ?: '(no name)', 0, 25 ),
                substr( $roles_str, 0, 30 ),
                $e->school_id ?: 'NULL',
                $school_flag,
                $e->status,
                substr( $e->school_name ?: '', 0, 30 )
            ) );
        }

        // ── Summary ──
        WP_CLI::log( "\n=== SUMMARY ===" );
        WP_CLI::log( "  Roles distribution:" );
        foreach ( $roles_seen as $role => $count ) {
            WP_CLI::log( "    {$role}: {$count}" );
        }
        if ( $missing_school > 0 ) {
            WP_CLI::warning( "{$missing_school} enrollment(s) have NO school_id set!" );
            WP_CLI::log( "  → This means School Leaders won't see these people in the Staff tab." );
            WP_CLI::log( "  → Fix: UPDATE {$prefix}hl_enrollment SET school_id = <correct_id> WHERE cycle_id = {$cycle_id} AND school_id IS NULL;" );
        }

        // ── Schools referenced ──
        $schools = $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT e.school_id, ou.name
             FROM {$prefix}hl_enrollment e
             LEFT JOIN {$prefix}hl_orgunit ou ON e.school_id = ou.orgunit_id
             WHERE e.cycle_id = %d AND e.school_id IS NOT NULL
             ORDER BY ou.name",
            $cycle_id
        ) );

        WP_CLI::log( "\n=== SCHOOLS IN THIS CYCLE ===" );
        if ( empty( $schools ) ) {
            WP_CLI::warning( "No enrollments have school_id set!" );
        } else {
            foreach ( $schools as $s ) {
                $count = $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$prefix}hl_enrollment WHERE cycle_id = %d AND school_id = %d AND status = 'active'",
                    $cycle_id, $s->school_id
                ) );
                WP_CLI::log( "  School ID {$s->school_id}: {$s->name} — {$count} active enrollments" );
            }
        }

        // ── Team memberships ──
        $teams = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.team_id, t.team_name, t.school_id, COUNT(tm.enrollment_id) AS member_count
             FROM {$prefix}hl_team t
             LEFT JOIN {$prefix}hl_team_membership tm ON t.team_id = tm.team_id
             WHERE t.cycle_id = %d
             GROUP BY t.team_id
             ORDER BY t.team_name",
            $cycle_id
        ) );

        WP_CLI::log( "\n=== TEAMS (" . count( $teams ) . ") ===" );
        if ( empty( $teams ) ) {
            WP_CLI::log( "  (none — expected for control group cycles)" );
        } else {
            foreach ( $teams as $t ) {
                WP_CLI::log( "  Team {$t->team_id}: {$t->team_name} (school_id: {$t->school_id}) — {$t->member_count} members" );
            }
        }

        // ── Pathway assignments ──
        $pathways = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.pathway_id, p.pathway_name, p.target_roles, COUNT(pa.enrollment_id) AS assigned_count
             FROM {$prefix}hl_pathway p
             LEFT JOIN {$prefix}hl_pathway_assignment pa ON p.pathway_id = pa.pathway_id
             WHERE p.cycle_id = %d
             GROUP BY p.pathway_id
             ORDER BY p.sort_order",
            $cycle_id
        ) );

        WP_CLI::log( "\n=== PATHWAYS ===" );
        if ( empty( $pathways ) ) {
            WP_CLI::log( "  (none)" );
        } else {
            foreach ( $pathways as $p ) {
                WP_CLI::log( "  Pathway {$p->pathway_id}: {$p->pathway_name} (targets: {$p->target_roles}) — {$p->assigned_count} assigned" );
            }
        }

        // ── Per-user diagnosis ──
        if ( $user_id ) {
            WP_CLI::log( "\n=== USER {$user_id} DIAGNOSIS ===" );
            $user = get_user_by( 'id', $user_id );
            if ( ! $user ) {
                WP_CLI::error( "User {$user_id} not found." );
            }
            WP_CLI::log( "  Name: {$user->display_name}" );
            WP_CLI::log( "  Email: {$user->user_email}" );
            WP_CLI::log( "  WP Roles: " . implode( ', ', $user->roles ) );
            WP_CLI::log( "  Can manage_hl_core: " . ( user_can( $user_id, 'manage_hl_core' ) ? 'YES' : 'no' ) );
            WP_CLI::log( "  Can manage_options: " . ( user_can( $user_id, 'manage_options' ) ? 'YES' : 'no' ) );

            $user_enrollments = $wpdb->get_results( $wpdb->prepare(
                "SELECT e.*, ou.name AS school_name
                 FROM {$prefix}hl_enrollment e
                 LEFT JOIN {$prefix}hl_orgunit ou ON e.school_id = ou.orgunit_id
                 WHERE e.user_id = %d AND e.status = 'active'",
                $user_id
            ) );

            WP_CLI::log( "  Active enrollments: " . count( $user_enrollments ) );
            foreach ( $user_enrollments as $ue ) {
                $c = $wpdb->get_row( $wpdb->prepare( "SELECT cycle_name, is_control_group FROM {$prefix}hl_cycle WHERE cycle_id = %d", $ue->cycle_id ) );
                WP_CLI::log( sprintf( '    Cycle %d (%s%s): roles=%s, school_id=%s (%s)',
                    $ue->cycle_id,
                    $c ? $c->cycle_name : '?',
                    $c && $c->is_control_group ? ', CONTROL' : '',
                    $ue->roles,
                    $ue->school_id ?: 'NULL',
                    $ue->school_name ?: 'no school'
                ) );
            }

            // What would this user see on My Cycle Staff tab?
            if ( $cycle_id ) {
                $ue_in_cycle = null;
                foreach ( $user_enrollments as $ue ) {
                    if ( (int) $ue->cycle_id === $cycle_id ) {
                        $ue_in_cycle = $ue;
                        break;
                    }
                }

                if ( $ue_in_cycle ) {
                    $roles = HL_Roles::parse_stored( $ue_in_cycle->roles );
                    $is_leader = in_array( 'school_leader', $roles, true ) || in_array( 'district_leader', $roles, true );

                    WP_CLI::log( "\n  Scope for My Cycle page:" );
                    if ( $is_leader && $ue_in_cycle->school_id ) {
                        WP_CLI::log( "    Type: school (school_id: {$ue_in_cycle->school_id})" );
                        // How many enrollments match this school filter?
                        $match_count = $wpdb->get_var( $wpdb->prepare(
                            "SELECT COUNT(*) FROM {$prefix}hl_enrollment WHERE cycle_id = %d AND status = 'active' AND school_id = %d",
                            $cycle_id, $ue_in_cycle->school_id
                        ) );
                        WP_CLI::log( "    Enrollments matching school filter (e.school_id): {$match_count}" );

                        // Also check via team
                        $team_match = $wpdb->get_var( $wpdb->prepare(
                            "SELECT COUNT(DISTINCT e.enrollment_id)
                             FROM {$prefix}hl_enrollment e
                             JOIN {$prefix}hl_team_membership tm ON e.enrollment_id = tm.enrollment_id
                             JOIN {$prefix}hl_team t ON tm.team_id = t.team_id AND t.cycle_id = e.cycle_id
                             WHERE e.cycle_id = %d AND e.status = 'active' AND t.school_id = %d",
                            $cycle_id, $ue_in_cycle->school_id
                        ) );
                        WP_CLI::log( "    Enrollments matching via team.school_id: {$team_match}" );

                        if ( $match_count == 0 && $team_match == 0 ) {
                            WP_CLI::warning( "Staff tab will show EMPTY for this user!" );
                            WP_CLI::log( "    → Teacher enrollments likely missing school_id." );
                        }
                    } elseif ( $is_leader && ! $ue_in_cycle->school_id ) {
                        WP_CLI::warning( "Leader enrollment has NO school_id! Scope defaults to 'all'." );
                    } else {
                        WP_CLI::log( "    Not a leader in this cycle." );
                    }
                } else {
                    WP_CLI::log( "  User has no enrollment in cycle {$cycle_id}." );
                }
            }
        }

        WP_CLI::success( 'Diagnosis complete.' );
    }
}

HL_CLI_Diagnose_Nav::register();
