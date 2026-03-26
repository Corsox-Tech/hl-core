<?php
/**
 * WP-CLI command: wp hl-core seed-beginnings
 *
 * Creates complete Beginnings School test dataset with two cycles:
 * - Cycle 1 (closed): ~70% full completion, historical child assessments
 * - Cycle 2 (active): fresh roster with turnover, pending child assessments
 *
 * Use --clean to remove all Beginnings data first.
 *
 * @package HL_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HL_CLI_Seed_Beginnings {

    // ── Constants ────────────────────────────────────────────────────
    const PARTNERSHIP_CODE = 'BEGINNINGS-2025';
    const CYCLE_1_CODE     = 'BEGINNINGS-Y1-2025';
    const CYCLE_2_CODE     = 'BEGINNINGS-Y2-2026';
    const META_KEY         = '_hl_beginnings_seed';

    // Real LearnDash course IDs — Phase 1.
    const TC0   = 31037;
    const TC1   = 30280;
    const TC2   = 30284;
    const TC3   = 30286;
    const TC4   = 30288;
    const MC1   = 30293;
    const MC2   = 30295;

    // Phase 2.
    const TC5   = 39724;
    const TC6   = 39726;
    const TC7   = 39728;
    const TC8   = 39730;
    const MC3   = 39732;
    const MC4   = 39734;

    // Streamlined versions.
    const TC1_S = 31332;
    const TC2_S = 31333;
    const TC3_S = 31334;
    const TC4_S = 31335;
    const TC5_S = 31336;
    const TC6_S = 31337;
    const TC7_S = 31338;
    const TC8_S = 31339;
    const MC1_S = 31387;
    const MC2_S = 31388;
    const MC3_S = 31389;
    const MC4_S = 31390;

    // ── Static Data ─────────────────────────────────────────────────

    private static function get_schools() {
        return array(
            'boston'    => array(
                'name'           => 'Beginnings Boston',
                'leader_email'   => 'boston-school-leader@yopmail.com',
                'leader_first'   => 'Beth',
                'leader_last'    => 'Boston-Leader',
                'teams'          => 2,
            ),
            'florida'  => array(
                'name'           => 'Beginnings Florida',
                'leader_email'   => 'florida-school-leader@yopmail.com',
                'leader_first'   => 'Fiona',
                'leader_last'    => 'Florida-Leader',
                'teams'          => 2,
            ),
            'texas'    => array(
                'name'           => 'Beginnings Texas',
                'leader_email'   => 'texas-school-leader@yopmail.com',
                'leader_first'   => 'Tina',
                'leader_last'    => 'Texas-Leader',
                'teams'          => 1,
            ),
            'colombia' => array(
                'name'           => 'Beginnings Colombia',
                'leader_email'   => 'colombia-school-leader@yopmail.com',
                'leader_first'   => 'Carmen',
                'leader_last'    => 'Colombia-Leader',
                'teams'          => 1,
            ),
        );
    }

    private static function get_classrooms() {
        return array(
            'boston'    => array(
                array( 'name' => 'Boston - Infant Room',     'age_band' => 'infant' ),
                array( 'name' => 'Boston - Toddler Room',    'age_band' => 'toddler' ),
            ),
            'florida'  => array(
                array( 'name' => 'Florida - Preschool Room', 'age_band' => 'preschool' ),
                array( 'name' => 'Florida - Pre-K Room',     'age_band' => 'pre_k' ),
            ),
            'texas'    => array(
                array( 'name' => 'Texas - Toddler Room',    'age_band' => 'toddler' ),
                array( 'name' => 'Texas - Preschool Room',  'age_band' => 'preschool' ),
            ),
            'colombia' => array(
                array( 'name' => 'Colombia - Infant Room',    'age_band' => 'infant' ),
                array( 'name' => 'Colombia - Preschool Room', 'age_band' => 'preschool' ),
            ),
        );
    }

    private static function get_teams() {
        return array(
            array(
                'school' => 'boston', 'num' => 1,
                'mentor_email' => 'mentor-T_01-boston@yopmail.com',
                'mentor_first' => 'Marco', 'mentor_last' => 'Mentor-T01-Boston',
                'teachers' => array(
                    array( 'john_teacher-T_01-boston@yopmail.com',  'John',  'Teacher-T01-Boston' ),
                    array( 'mary_teacher-T_01-boston@yopmail.com',  'Mary',  'Teacher-T01-Boston' ),
                    array( 'steve_teacher-T_01-boston@yopmail.com', 'Steve', 'Teacher-T01-Boston' ),
                    array( 'lisa_teacher-T_01-boston@yopmail.com',  'Lisa',  'Teacher-T01-Boston' ),
                ),
            ),
            array(
                'school' => 'boston', 'num' => 2,
                'mentor_email' => 'mentor-T_02-boston@yopmail.com',
                'mentor_first' => 'Monica', 'mentor_last' => 'Mentor-T02-Boston',
                'teachers' => array(
                    array( 'carlos_teacher-T_02-boston@yopmail.com', 'Carlos', 'Teacher-T02-Boston' ),
                    array( 'ana_teacher-T_02-boston@yopmail.com',    'Ana',    'Teacher-T02-Boston' ),
                    array( 'mike_teacher-T_02-boston@yopmail.com',   'Mike',   'Teacher-T02-Boston' ),
                    array( 'sarah_teacher-T_02-boston@yopmail.com',  'Sarah',  'Teacher-T02-Boston' ),
                ),
            ),
            array(
                'school' => 'florida', 'num' => 1,
                'mentor_email' => 'mentor-T_01-florida@yopmail.com',
                'mentor_first' => 'Marta', 'mentor_last' => 'Mentor-T01-Florida',
                'teachers' => array(
                    array( 'david_teacher-T_01-florida@yopmail.com',  'David',  'Teacher-T01-Florida' ),
                    array( 'rachel_teacher-T_01-florida@yopmail.com', 'Rachel', 'Teacher-T01-Florida' ),
                    array( 'james_teacher-T_01-florida@yopmail.com',  'James',  'Teacher-T01-Florida' ),
                    array( 'emma_teacher-T_01-florida@yopmail.com',   'Emma',   'Teacher-T01-Florida' ),
                ),
            ),
            array(
                'school' => 'florida', 'num' => 2,
                'mentor_email' => 'mentor-T_02-florida@yopmail.com',
                'mentor_first' => 'Miguel', 'mentor_last' => 'Mentor-T02-Florida',
                'teachers' => array(
                    array( 'tom_teacher-T_02-florida@yopmail.com',   'Tom',   'Teacher-T02-Florida' ),
                    array( 'nina_teacher-T_02-florida@yopmail.com',  'Nina',  'Teacher-T02-Florida' ),
                    array( 'leo_teacher-T_02-florida@yopmail.com',   'Leo',   'Teacher-T02-Florida' ),
                    array( 'grace_teacher-T_02-florida@yopmail.com', 'Grace', 'Teacher-T02-Florida' ),
                ),
            ),
            array(
                'school' => 'texas', 'num' => 1,
                'mentor_email' => 'mentor-T_01-texas@yopmail.com',
                'mentor_first' => 'Manuel', 'mentor_last' => 'Mentor-T01-Texas',
                'teachers' => array(
                    array( 'ryan_teacher-T_01-texas@yopmail.com', 'Ryan', 'Teacher-T01-Texas' ),
                    array( 'mia_teacher-T_01-texas@yopmail.com',  'Mia',  'Teacher-T01-Texas' ),
                    array( 'jake_teacher-T_01-texas@yopmail.com', 'Jake', 'Teacher-T01-Texas' ),
                    array( 'lily_teacher-T_01-texas@yopmail.com', 'Lily', 'Teacher-T01-Texas' ),
                ),
            ),
            array(
                'school' => 'colombia', 'num' => 1,
                'mentor_email' => 'mentor-T_01-colombia@yopmail.com',
                'mentor_first' => 'Maria', 'mentor_last' => 'Mentor-T01-Colombia',
                'teachers' => array(
                    array( 'ben_teacher-T_01-colombia@yopmail.com',   'Ben',   'Teacher-T01-Colombia' ),
                    array( 'chloe_teacher-T_01-colombia@yopmail.com', 'Chloe', 'Teacher-T01-Colombia' ),
                    array( 'zoe_teacher-T_01-colombia@yopmail.com',   'Zoe',   'Teacher-T01-Colombia' ),
                    array( 'diego_teacher-T_01-colombia@yopmail.com', 'Diego', 'Teacher-T01-Colombia' ),
                ),
            ),
        );
    }

    /**
     * Emails of people who leave after Cycle 1 (not enrolled in Cycle 2).
     * Lisa is also here as teacher — she re-enrolls as mentor.
     */
    private static function get_cycle2_departures() {
        return array(
            'lisa_teacher-T_01-boston@yopmail.com',   // promoted to mentor
            'emma_teacher-T_01-florida@yopmail.com',  // left
            'leo_teacher-T_02-florida@yopmail.com',   // left
            'lily_teacher-T_01-texas@yopmail.com',    // left
            'mentor-T_01-colombia@yopmail.com',       // Maria left
        );
    }

    /**
     * Cycle 1 stragglers (~30% of enrollments, partial completion).
     */
    private static function get_cycle1_stragglers() {
        return array(
            // Teachers (7):
            'lisa_teacher-T_01-boston@yopmail.com',
            'sarah_teacher-T_02-boston@yopmail.com',
            'emma_teacher-T_01-florida@yopmail.com',
            'leo_teacher-T_02-florida@yopmail.com',
            'grace_teacher-T_02-florida@yopmail.com',
            'lily_teacher-T_01-texas@yopmail.com',
            'diego_teacher-T_01-colombia@yopmail.com',
            // Mentors (2):
            'mentor-T_01-colombia@yopmail.com',   // Maria
            'mentor-T_02-florida@yopmail.com',    // Miguel
            // School leaders (1):
            'texas-school-leader@yopmail.com',    // Tina
        );
    }

    /**
     * Child name arrays (deterministic).
     */
    private static function get_child_first_names() {
        return array( 'Emma', 'Liam', 'Olivia', 'Noah', 'Ava', 'Elijah', 'Sophia', 'James', 'Isabella', 'Lucas' );
    }

    private static function get_child_last_names() {
        return array( 'Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller', 'Davis', 'Rodriguez', 'Martinez' );
    }

    // ── Registration ────────────────────────────────────────────────

    public static function register() {
        WP_CLI::add_command( 'hl-core seed-beginnings', array( new self(), 'run' ) );
    }

    /**
     * Seed Beginnings School test data (Cycle 1 + Cycle 2).
     *
     * ## OPTIONS
     *
     * [--clean]
     * : Remove all Beginnings data before seeding.
     *
     * ## EXAMPLES
     *
     *     wp hl-core seed-beginnings
     *     wp hl-core seed-beginnings --clean
     */
    public function run( $args, $assoc_args ) {
        if ( isset( $assoc_args['clean'] ) ) {
            $this->clean();
            WP_CLI::success( 'Beginnings data cleaned.' );
            return;
        }

        if ( $this->data_exists() ) {
            WP_CLI::warning( 'Beginnings data already exists. Run with --clean first to reseed.' );
            return;
        }

        WP_CLI::line( '' );
        WP_CLI::line( '=== HL Core Beginnings School Seeder ===' );
        WP_CLI::line( '' );

        // Phase A: Shared infrastructure.
        $partnership_id = $this->seed_partnership();
        $org            = $this->seed_orgunits();
        $classrooms     = $this->seed_classrooms( $org['schools'] );
        $this->seed_children( $classrooms );
        $users          = $this->seed_users();
        $instruments    = $this->seed_child_instruments();

        // Phase B: Cycle 1 (closed).
        WP_CLI::line( '' );
        WP_CLI::line( '--- Cycle 1 (closed) ---' );
        $c1 = $this->seed_cycle_1( $partnership_id, $org, $users, $classrooms, $instruments );

        // Phase C: Cycle 2 (active).
        WP_CLI::line( '' );
        WP_CLI::line( '--- Cycle 2 (active) ---' );
        $c2 = $this->seed_cycle_2( $partnership_id, $org, $users, $classrooms, $instruments );

        // Phase D: Coach assignment.
        $this->seed_coach_assignment( $c2['cycle_id'], $org['schools']['boston'] );

        // Summary.
        $this->print_summary( $partnership_id, $org, $classrooms, $users, $c1, $c2 );
    }

    // ── Shared Infrastructure (Phase A) ─────────────────────────────

    /**
     * Check if Beginnings data already exists.
     */
    private function data_exists() {
        global $wpdb;
        $t = $wpdb->prefix;
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT cycle_id FROM {$t}hl_cycle WHERE cycle_code IN (%s, %s) LIMIT 1",
            self::CYCLE_1_CODE,
            self::CYCLE_2_CODE
        ) );
    }

    /**
     * Find or create Partnership.
     */
    private function seed_partnership() {
        global $wpdb;
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT partnership_id FROM {$wpdb->prefix}hl_partnership WHERE partnership_code = %s LIMIT 1",
            self::PARTNERSHIP_CODE
        ) );
        if ( $existing ) {
            WP_CLI::log( "  [A1] Partnership exists: id={$existing}" );
            return (int) $existing;
        }
        $wpdb->insert( $wpdb->prefix . 'hl_partnership', array(
            'partnership_name' => 'Beginnings School - 2025-2026',
            'partnership_code' => self::PARTNERSHIP_CODE,
            'description'      => 'Test partnership for end-to-end demo and testing.',
            'status'           => 'active',
        ) );
        $id = $wpdb->insert_id;
        WP_CLI::log( "  [A1] Partnership created: id={$id}" );
        return $id;
    }

    /**
     * Create district + 4 schools via HL_OrgUnit_Repository.
     */
    private function seed_orgunits() {
        $repo = new HL_OrgUnit_Repository();
        $district_id = $repo->create( array(
            'name'         => 'Beginnings School District',
            'orgunit_type' => 'district',
        ) );

        $schools = array();
        foreach ( self::get_schools() as $key => $def ) {
            $schools[ $key ] = $repo->create( array(
                'name'              => $def['name'],
                'orgunit_type'      => 'school',
                'parent_orgunit_id' => $district_id,
            ) );
        }
        WP_CLI::log( '  [A2] Org units: district=' . $district_id . ', schools=' . implode( ',', $schools ) );
        return array( 'district_id' => $district_id, 'schools' => $schools );
    }

    /**
     * Create 2 classrooms per school, 8 total.
     */
    private function seed_classrooms( $schools ) {
        $svc = new HL_Classroom_Service();
        $all = array();
        foreach ( self::get_classrooms() as $school_key => $defs ) {
            $school_id = $schools[ $school_key ];
            foreach ( $defs as $def ) {
                $id = $svc->create_classroom( array(
                    'classroom_name' => $def['name'],
                    'school_id'      => $school_id,
                    'age_band'       => $def['age_band'],
                ) );
                if ( ! is_wp_error( $id ) ) {
                    $all[] = array(
                        'classroom_id' => $id,
                        'school_key'   => $school_key,
                        'school_id'    => $school_id,
                        'age_band'     => $def['age_band'],
                    );
                }
            }
        }
        WP_CLI::log( '  [A3] Classrooms created: ' . count( $all ) );
        return $all;
    }

    /**
     * Create 5 children per classroom, 40 total (deterministic).
     */
    private function seed_children( $classrooms ) {
        $repo  = new HL_Child_Repository();
        $svc   = new HL_Classroom_Service();
        $fns   = self::get_child_first_names();
        $lns   = self::get_child_last_names();
        $total = 0;

        foreach ( $classrooms as $c_idx => $cr ) {
            for ( $i = 0; $i < 5; $i++ ) {
                $first = $fns[ ( $c_idx * 5 + $i ) % 10 ];
                $last  = $lns[ ( $c_idx * 5 + $i + 3 ) % 10 ];
                $age   = $this->get_age_midpoint( $cr['age_band'] );
                // Deterministic DOB: offset each child by (i * 73) days for spread.
                $dob   = gmdate( 'Y-m-d', strtotime( "-{$age} years -" . ( $i * 73 ) . ' days' ) );

                $child_id = $repo->create( array(
                    'first_name' => $first,
                    'last_name'  => $last,
                    'dob'        => $dob,
                    'school_id'  => $cr['school_id'],
                ) );
                if ( $child_id ) {
                    $svc->assign_child_to_classroom( $child_id, $cr['classroom_id'], 'Beginnings seed' );
                    $total++;
                }
            }
        }
        WP_CLI::log( "  [A4] Children created: {$total}" );
        return $total;
    }

    /**
     * Get age midpoint for a given age band.
     */
    private function get_age_midpoint( $band ) {
        switch ( $band ) {
            case 'infant':    return 1;
            case 'toddler':   return 2;
            case 'preschool': return 3;
            case 'pre_k':     return 5;
            default:          return 3;
        }
    }

    /**
     * Create all 36 WP users (district leader, school leaders, mentors, teachers, Natalia).
     */
    private function seed_users() {
        $users = array(
            'district_leader' => null,
            'school_leaders'  => array(),
            'mentors'         => array(),
            'teachers'        => array(),
            'by_email'        => array(), // email => user data
            'count'           => 0,
        );

        // District leader.
        $dl = $this->create_user( 'district-lead-beginnings@yopmail.com', 'Diana', 'District-Lead-Beginnings', 'subscriber' );
        $users['district_leader'] = $dl;
        $users['by_email'][ $dl['email'] ] = $dl;
        $users['count']++;

        // School leaders.
        foreach ( self::get_schools() as $key => $def ) {
            $u = $this->create_user( $def['leader_email'], $def['leader_first'], $def['leader_last'], 'subscriber' );
            $users['school_leaders'][ $key ] = $u;
            $users['by_email'][ $u['email'] ] = $u;
            $users['count']++;
        }

        // Mentors + Teachers per team.
        foreach ( self::get_teams() as $t_idx => $team ) {
            $m = $this->create_user( $team['mentor_email'], $team['mentor_first'], $team['mentor_last'], 'subscriber' );
            $users['mentors'][ $t_idx ] = $m;
            $users['by_email'][ $m['email'] ] = $m;
            $users['count']++;

            $users['teachers'][ $t_idx ] = array();
            foreach ( $team['teachers'] as $t ) {
                $u = $this->create_user( $t[0], $t[1], $t[2], 'subscriber' );
                $users['teachers'][ $t_idx ][] = $u;
                $users['by_email'][ $u['email'] ] = $u;
                $users['count']++;
            }
        }

        // Natalia — Cycle 2 only mentor.
        $nat = $this->create_user( 'new-hire-mentor-boston@yopmail.com', 'Natalia', 'NewHire-Mentor', 'subscriber' );
        $users['natalia'] = $nat;
        $users['by_email'][ $nat['email'] ] = $nat;
        $users['count']++;

        WP_CLI::log( "  [A5] WP users created: {$users['count']}" );
        return $users;
    }

    /**
     * Create or find a WP user. Password = email for test accounts.
     */
    private function create_user( $email, $first_name, $last_name, $role ) {
        $existing = get_user_by( 'email', $email );
        if ( $existing ) {
            update_user_meta( $existing->ID, self::META_KEY, 'found' );
            return array( 'user_id' => $existing->ID, 'email' => $email, 'first_name' => $first_name, 'last_name' => $last_name );
        }
        $username = explode( '@', $email )[0];
        $user_id  = wp_insert_user( array(
            'user_login'   => $username,
            'user_email'   => $email,
            'user_pass'    => $email,
            'display_name' => "{$first_name} {$last_name}",
            'first_name'   => $first_name,
            'last_name'    => $last_name,
            'role'         => $role,
        ) );
        if ( is_wp_error( $user_id ) ) {
            WP_CLI::warning( "Could not create user {$email}: " . $user_id->get_error_message() );
            return array( 'user_id' => 0, 'email' => $email, 'first_name' => $first_name, 'last_name' => $last_name );
        }
        update_user_meta( $user_id, self::META_KEY, 'created' );
        return array( 'user_id' => $user_id, 'email' => $email, 'first_name' => $first_name, 'last_name' => $last_name );
    }

    /**
     * Create 4 child assessment instruments (infant, toddler, preschool, pre_k).
     */
    private function seed_child_instruments() {
        global $wpdb;
        $t = $wpdb->prefix;

        $age_bands   = array( 'infant', 'toddler', 'preschool', 'pre_k' );
        $instruments = array();

        foreach ( $age_bands as $ab ) {
            $label = str_replace( '_', '-', ucfirst( $ab ) );
            if ( $ab === 'pre_k' ) $label = 'Pre-K';
            $name = "Beginnings {$label} Assessment";

            $existing = $wpdb->get_var( $wpdb->prepare(
                "SELECT instrument_id FROM {$t}hl_instrument WHERE name = %s", $name
            ) );
            if ( $existing ) {
                $instruments[ $ab ] = (int) $existing;
                WP_CLI::log( "  [A6] Instrument exists: {$name} (ID {$existing})" );
                continue;
            }

            $questions = array( array(
                'key'            => 'q1',
                'prompt'         => 'Rate this child\'s social-emotional development on a scale of 1-5.',
                'type'           => 'likert',
                'allowed_values' => array( '1', '2', '3', '4', '5' ),
                'required'       => true,
            ) );

            $wpdb->insert( $t . 'hl_instrument', array(
                'instrument_uuid' => wp_generate_uuid4(),
                'name'            => $name,
                'instrument_type' => "children_{$ab}",
                'version'         => '1.0',
                'questions'       => wp_json_encode( $questions ),
            ) );
            $instruments[ $ab ] = (int) $wpdb->insert_id;
            WP_CLI::log( "  [A6] Instrument created: {$name} (ID {$instruments[$ab]})" );
        }

        return $instruments;
    }

    // ── Cycle 1 (Phase B) ───────────────────────────────────────────

    /**
     * Seed Cycle 1 (closed) — enrollments, teams, pathways, component states, child assessments.
     */
    private function seed_cycle_1( $partnership_id, $org, $users, $classrooms, $instruments ) {
        global $wpdb;
        $t = $wpdb->prefix;

        // Create cycle (closed).
        $repo     = new HL_Cycle_Repository();
        $cycle_id = $repo->create( array(
            'cycle_name'     => 'Beginnings - Cycle 1 (2025)',
            'cycle_code'     => self::CYCLE_1_CODE,
            'partnership_id' => $partnership_id,
            'cycle_type'     => 'program',
            'district_id'    => $org['district_id'],
            'status'         => 'closed',
            'start_date'     => '2025-01-15',
            'end_date'       => '2025-09-30',
        ) );
        foreach ( $org['schools'] as $school_id ) {
            $wpdb->insert( $t . 'hl_cycle_school', array(
                'cycle_id' => $cycle_id, 'school_id' => $school_id,
            ) );
        }
        WP_CLI::log( "  [B1] Cycle 1 created: id={$cycle_id}, status=closed" );

        // Enrollments (all 35 Cycle 1 users — does NOT include Natalia).
        $enrollments = $this->seed_enrollments_c1( $cycle_id, $org, $users );

        // Teams.
        $this->seed_teams( $cycle_id, $org['schools'], $enrollments, self::get_teams() );

        // Pathways (Phase 1 only).
        $pathways = $this->seed_pathways_c1( $cycle_id );

        // Assign pathways.
        $this->assign_pathways_c1( $enrollments, $pathways );

        // Teaching assignments.
        $this->seed_teaching_assignments( $enrollments, $classrooms, $org['schools'] );

        // Freeze child age groups.
        $frozen = HL_Child_Snapshot_Service::freeze_age_groups( $cycle_id );
        WP_CLI::log( "  [B7] Frozen age group snapshots: {$frozen}" );

        // Component states (~70% full completion).
        // TODO: Implement in Task 4 — seed_component_states_c1().

        // Completion rollups.
        // TODO: Implement in Task 4 — seed_rollups().

        // Child assessment instances (historical, submitted).
        // TODO: Implement in Task 4 — seed_child_assessments_c1().

        return array( 'cycle_id' => $cycle_id, 'enrollments' => $enrollments, 'pathways' => $pathways );
    }

    /**
     * Enroll all 35 Cycle 1 users (district leader, 4 school leaders, 6 mentors, 24 teachers).
     */
    private function seed_enrollments_c1( $cycle_id, $org, $users ) {
        $repo        = new HL_Enrollment_Repository();
        $enrollments = array(
            'district_leader' => null,
            'school_leaders'  => array(),
            'mentors'         => array(),
            'teachers'        => array(),
            'all'             => array(),
            'by_email'        => array(),
        );
        $district_id = $org['district_id'];
        $schools     = $org['schools'];
        $teams_def   = self::get_teams();

        // District leader.
        $uid = $users['district_leader']['user_id'];
        if ( $uid ) {
            $eid = $repo->create( array(
                'user_id' => $uid, 'cycle_id' => $cycle_id,
                'roles' => array( 'district_leader' ), 'status' => 'active',
                'district_id' => $district_id,
            ) );
            $e = array( 'enrollment_id' => $eid, 'user_id' => $uid, 'role' => 'district_leader',
                         'email' => $users['district_leader']['email'] );
            $enrollments['district_leader'] = $e;
            $enrollments['all'][]           = $e;
            $enrollments['by_email'][ $e['email'] ] = $e;
        }

        // School leaders.
        foreach ( self::get_schools() as $key => $def ) {
            $uid = $users['school_leaders'][ $key ]['user_id'];
            if ( ! $uid ) continue;
            $eid = $repo->create( array(
                'user_id' => $uid, 'cycle_id' => $cycle_id,
                'roles' => array( 'school_leader' ), 'status' => 'active',
                'school_id' => $schools[ $key ], 'district_id' => $district_id,
            ) );
            $e = array( 'enrollment_id' => $eid, 'user_id' => $uid, 'role' => 'school_leader',
                         'school_key' => $key, 'email' => $def['leader_email'] );
            $enrollments['school_leaders'][ $key ] = $e;
            $enrollments['all'][]                  = $e;
            $enrollments['by_email'][ $e['email'] ] = $e;
        }

        // Mentors + Teachers.
        foreach ( $teams_def as $t_idx => $team ) {
            $school_id = $schools[ $team['school'] ];

            $uid = $users['mentors'][ $t_idx ]['user_id'];
            if ( $uid ) {
                $eid = $repo->create( array(
                    'user_id' => $uid, 'cycle_id' => $cycle_id,
                    'roles' => array( 'mentor' ), 'status' => 'active',
                    'school_id' => $school_id, 'district_id' => $district_id,
                ) );
                $e = array( 'enrollment_id' => $eid, 'user_id' => $uid, 'role' => 'mentor',
                             'school_key' => $team['school'], 'team_idx' => $t_idx,
                             'email' => $team['mentor_email'] );
                $enrollments['mentors'][ $t_idx ] = $e;
                $enrollments['all'][]             = $e;
                $enrollments['by_email'][ $e['email'] ] = $e;
            }

            $enrollments['teachers'][ $t_idx ] = array();
            foreach ( $users['teachers'][ $t_idx ] as $t_data ) {
                $uid = $t_data['user_id'];
                if ( ! $uid ) continue;
                $eid = $repo->create( array(
                    'user_id' => $uid, 'cycle_id' => $cycle_id,
                    'roles' => array( 'teacher' ), 'status' => 'active',
                    'school_id' => $school_id, 'district_id' => $district_id,
                ) );
                $e = array( 'enrollment_id' => $eid, 'user_id' => $uid, 'role' => 'teacher',
                             'school_key' => $team['school'], 'team_idx' => $t_idx,
                             'email' => $t_data['email'] );
                $enrollments['teachers'][ $t_idx ][] = $e;
                $enrollments['all'][]                = $e;
                $enrollments['by_email'][ $e['email'] ] = $e;
            }
        }

        WP_CLI::log( '  [B2] Cycle 1 enrollments: ' . count( $enrollments['all'] ) );
        return $enrollments;
    }

    /**
     * Create teams and add mentor + teacher members. Shared for both cycles.
     */
    private function seed_teams( $cycle_id, $schools, $enrollments, $teams_def ) {
        $svc   = new HL_Team_Service();
        $count = 0;

        foreach ( $teams_def as $t_idx => $team ) {
            $school_id = $schools[ $team['school'] ];
            $team_name = 'Team ' . str_pad( $team['num'], 2, '0', STR_PAD_LEFT )
                       . ' - ' . ucfirst( $team['school'] ) . ' - Beginnings';

            $team_id = $svc->create_team( array(
                'team_name' => $team_name,
                'cycle_id'  => $cycle_id,
                'school_id' => $school_id,
            ) );
            if ( is_wp_error( $team_id ) ) {
                WP_CLI::warning( 'Team error: ' . $team_id->get_error_message() );
                continue;
            }

            // Add mentor.
            if ( isset( $enrollments['mentors'][ $t_idx ] ) ) {
                $svc->add_member( $team_id, $enrollments['mentors'][ $t_idx ]['enrollment_id'], 'mentor' );
            }

            // Add teachers.
            if ( isset( $enrollments['teachers'][ $t_idx ] ) ) {
                foreach ( $enrollments['teachers'][ $t_idx ] as $t ) {
                    $svc->add_member( $team_id, $t['enrollment_id'], 'member' );
                }
            }

            // Add extra members (e.g., Natalia on T01-Boston in Cycle 2).
            if ( isset( $enrollments['extra_team_members'][ $t_idx ] ) ) {
                foreach ( $enrollments['extra_team_members'][ $t_idx ] as $extra ) {
                    $svc->add_member( $team_id, $extra['enrollment_id'], 'member' );
                }
            }

            $count++;
        }

        WP_CLI::log( "  Teams created: {$count}" );
    }

    /**
     * Create Phase 1 pathways for Cycle 1 (Teacher, Mentor, Streamlined).
     */
    private function seed_pathways_c1( $cycle_id ) {
        $svc      = new HL_Pathway_Service();
        $pathways = array();

        $pathways['teacher']     = $this->create_teacher_phase1( $svc, $cycle_id );
        $pathways['mentor']      = $this->create_mentor_phase1( $svc, $cycle_id );
        $pathways['streamlined'] = $this->create_streamlined_phase1( $svc, $cycle_id );

        WP_CLI::log( '  [B4] Cycle 1 pathways: ' . count( $pathways ) );
        return $pathways;
    }

    /**
     * Assign pathways to enrollments for Cycle 1.
     * Teachers → Teacher Phase 1, Mentors → Mentor Phase 1, Leaders → Streamlined Phase 1.
     */
    private function assign_pathways_c1( $enrollments, $pathways ) {
        global $wpdb;
        $t     = $wpdb->prefix;
        $count = 0;

        foreach ( $enrollments['teachers'] as $team_teachers ) {
            foreach ( $team_teachers as $e ) {
                $wpdb->update( $t . 'hl_enrollment',
                    array( 'assigned_pathway_id' => $pathways['teacher']['pathway_id'] ),
                    array( 'enrollment_id' => $e['enrollment_id'] ) );
                $count++;
            }
        }
        foreach ( $enrollments['mentors'] as $e ) {
            $wpdb->update( $t . 'hl_enrollment',
                array( 'assigned_pathway_id' => $pathways['mentor']['pathway_id'] ),
                array( 'enrollment_id' => $e['enrollment_id'] ) );
            $count++;
        }
        foreach ( $enrollments['school_leaders'] as $e ) {
            $wpdb->update( $t . 'hl_enrollment',
                array( 'assigned_pathway_id' => $pathways['streamlined']['pathway_id'] ),
                array( 'enrollment_id' => $e['enrollment_id'] ) );
            $count++;
        }
        if ( $enrollments['district_leader'] ) {
            $wpdb->update( $t . 'hl_enrollment',
                array( 'assigned_pathway_id' => $pathways['streamlined']['pathway_id'] ),
                array( 'enrollment_id' => $enrollments['district_leader']['enrollment_id'] ) );
            $count++;
        }
        WP_CLI::log( "  [B5] Pathway assignments: {$count}" );
    }

    /**
     * Round-robin teachers to classrooms within their school. Shared for both cycles.
     */
    private function seed_teaching_assignments( $enrollments, $classrooms, $schools ) {
        remove_all_actions( 'hl_core_teaching_assignment_changed' );
        $svc   = new HL_Classroom_Service();
        $count = 0;

        $cr_by_school = array();
        foreach ( $classrooms as $cr ) {
            $cr_by_school[ $cr['school_key'] ][] = $cr;
        }

        $teams_def = self::get_teams();
        foreach ( $teams_def as $t_idx => $team ) {
            if ( empty( $enrollments['teachers'][ $t_idx ] ) ) continue;
            $school_crs = isset( $cr_by_school[ $team['school'] ] ) ? $cr_by_school[ $team['school'] ] : array();
            if ( empty( $school_crs ) ) continue;

            foreach ( $enrollments['teachers'][ $t_idx ] as $idx => $t ) {
                $cr = $school_crs[ $idx % count( $school_crs ) ];
                $result = $svc->create_teaching_assignment( array(
                    'enrollment_id'   => $t['enrollment_id'],
                    'classroom_id'    => $cr['classroom_id'],
                    'is_lead_teacher' => ( $idx === 0 ) ? 1 : 0,
                ) );
                if ( ! is_wp_error( $result ) ) {
                    $count++;
                }
            }
        }

        WP_CLI::log( "  Teaching assignments: {$count}" );
    }

    // ── Pathway Builders ──────────────────────────────────────────────

    /**
     * Shorthand: create one pathway component and return its ID.
     */
    private function cmp( $svc, $pathway_id, $cycle_id, $title, $type, $order, $ext_ref = array() ) {
        return $svc->create_component( array(
            'title'          => $title,
            'pathway_id'     => $pathway_id,
            'cycle_id'       => $cycle_id,
            'component_type' => $type,
            'weight'         => 1.0,
            'ordering_hint'  => $order,
            'external_ref'   => wp_json_encode( $ext_ref ?: (object) array() ),
        ) );
    }

    /**
     * Create a prerequisite link between two components.
     */
    private function add_prereq( $component_id, $prerequisite_component_id ) {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'hl_component_prereq_group', array(
            'component_id' => $component_id,
            'prereq_type'  => 'all_of',
        ) );
        $group_id = $wpdb->insert_id;
        $wpdb->insert( $wpdb->prefix . 'hl_component_prereq_item', array(
            'group_id'                  => $group_id,
            'prerequisite_component_id' => $prerequisite_component_id,
        ) );
    }

    /**
     * Teacher Phase 1 (17 components): TSA Pre, CA Pre, TC0, TC1, SR#1,
     * RP#1, TC2, SR#2, RP#2, TC3, SR#3, RP#3, TC4, SR#4, RP#4, CA Post, TSA Post
     */
    private function create_teacher_phase1( $svc, $cycle_id ) {
        $pid = $svc->create_pathway( array(
            'pathway_name'  => 'B2E Teacher Phase 1',
            'cycle_id'      => $cycle_id,
            'target_roles'  => array( 'teacher' ),
            'active_status' => 1,
        ) );

        $ids = array();
        $n = 0;
        $ids[] = $tsa_pre = $this->cmp( $svc, $pid, $cycle_id, 'Teacher Self-Assessment (Pre)',  'teacher_self_assessment', ++$n, array( 'phase' => 'pre' ) );
        $ids[] = $ca_pre  = $this->cmp( $svc, $pid, $cycle_id, 'Child Assessment (Pre)',          'child_assessment',        ++$n, array( 'phase' => 'pre' ) );
        $ids[] = $tc0     = $this->cmp( $svc, $pid, $cycle_id, 'TC0: Welcome',                   'learndash_course',        ++$n, array( 'course_id' => self::TC0 ) );
        $ids[] = $tc1     = $this->cmp( $svc, $pid, $cycle_id, 'TC1: Intro to begin to ECSEL',   'learndash_course',        ++$n, array( 'course_id' => self::TC1 ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Self-Reflection #1',                        'self_reflection',         ++$n, array( 'visit_number' => 1 ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Reflective Practice Session #1',             'reflective_practice_session', ++$n, array( 'session_number' => 1 ) );
        $ids[] = $tc2     = $this->cmp( $svc, $pid, $cycle_id, 'TC2: Your Own Emotionality',     'learndash_course',        ++$n, array( 'course_id' => self::TC2 ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Self-Reflection #2',                        'self_reflection',         ++$n, array( 'visit_number' => 2 ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Reflective Practice Session #2',             'reflective_practice_session', ++$n, array( 'session_number' => 2 ) );
        $ids[] = $tc3     = $this->cmp( $svc, $pid, $cycle_id, 'TC3: Getting to Know Emotion',   'learndash_course',        ++$n, array( 'course_id' => self::TC3 ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Self-Reflection #3',                        'self_reflection',         ++$n, array( 'visit_number' => 3 ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Reflective Practice Session #3',             'reflective_practice_session', ++$n, array( 'session_number' => 3 ) );
        $ids[] = $tc4     = $this->cmp( $svc, $pid, $cycle_id, 'TC4: Emotion in the Heat of the Moment', 'learndash_course', ++$n, array( 'course_id' => self::TC4 ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Self-Reflection #4',                        'self_reflection',         ++$n, array( 'visit_number' => 4 ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Reflective Practice Session #4',             'reflective_practice_session', ++$n, array( 'session_number' => 4 ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Child Assessment (Post)',                    'child_assessment',        ++$n, array( 'phase' => 'post' ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Teacher Self-Assessment (Post)',             'teacher_self_assessment', ++$n, array( 'phase' => 'post' ) );

        // Prerequisites: course chain TC0->TC1->TC2->TC3->TC4, first course blocked by TSA Pre.
        $this->add_prereq( $tc0, $tsa_pre );
        $this->add_prereq( $tc1, $tc0 );
        $this->add_prereq( $tc2, $tc1 );
        $this->add_prereq( $tc3, $tc2 );
        $this->add_prereq( $tc4, $tc3 );

        WP_CLI::log( "    Teacher Phase 1: pathway_id={$pid}, {$n} components" );
        return array( 'pathway_id' => $pid, 'component_count' => $n, 'component_ids' => $ids );
    }

    /**
     * Mentor Phase 1 (19 components): TSA Pre, CA Pre, TC0, TC1, Coaching#1,
     * MC1, RP#1, TC2, Coaching#2, RP#2, TC3, Coaching#3, MC2, RP#3,
     * TC4, Coaching#4, RP#4, CA Post, TSA Post
     */
    private function create_mentor_phase1( $svc, $cycle_id ) {
        $pid = $svc->create_pathway( array(
            'pathway_name'  => 'B2E Mentor Phase 1',
            'cycle_id'      => $cycle_id,
            'target_roles'  => array( 'mentor' ),
            'active_status' => 1,
        ) );

        $ids = array();
        $n = 0;
        $ids[] = $tsa_pre = $this->cmp( $svc, $pid, $cycle_id, 'Teacher Self-Assessment (Pre)',               'teacher_self_assessment',      ++$n, array( 'phase' => 'pre' ) );
        $ids[] = $ca_pre  = $this->cmp( $svc, $pid, $cycle_id, 'Child Assessment (Pre)',                       'child_assessment',             ++$n, array( 'phase' => 'pre' ) );
        $ids[] = $tc0     = $this->cmp( $svc, $pid, $cycle_id, 'TC0: Welcome',                                'learndash_course',             ++$n, array( 'course_id' => self::TC0 ) );
        $ids[] = $tc1     = $this->cmp( $svc, $pid, $cycle_id, 'TC1: Intro to begin to ECSEL',                'learndash_course',             ++$n, array( 'course_id' => self::TC1 ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Coaching Session #1',                                    'coaching_session_attendance',  ++$n, array( 'session_number' => 1 ) );
        $ids[] = $mc1     = $this->cmp( $svc, $pid, $cycle_id, 'MC1: Introduction to Reflective Practice',    'learndash_course',             ++$n, array( 'course_id' => self::MC1 ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Reflective Practice Session #1',                          'reflective_practice_session', ++$n, array( 'session_number' => 1 ) );
        $ids[] = $tc2     = $this->cmp( $svc, $pid, $cycle_id, 'TC2: Your Own Emotionality',                  'learndash_course',             ++$n, array( 'course_id' => self::TC2 ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Coaching Session #2',                                    'coaching_session_attendance',  ++$n, array( 'session_number' => 2 ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Reflective Practice Session #2',                          'reflective_practice_session', ++$n, array( 'session_number' => 2 ) );
        $ids[] = $tc3     = $this->cmp( $svc, $pid, $cycle_id, 'TC3: Getting to Know Emotion',                'learndash_course',             ++$n, array( 'course_id' => self::TC3 ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Coaching Session #3',                                    'coaching_session_attendance',  ++$n, array( 'session_number' => 3 ) );
        $ids[] = $mc2     = $this->cmp( $svc, $pid, $cycle_id, 'MC2: A Deeper Dive into Reflective Practice', 'learndash_course',             ++$n, array( 'course_id' => self::MC2 ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Reflective Practice Session #3',                          'reflective_practice_session', ++$n, array( 'session_number' => 3 ) );
        $ids[] = $tc4     = $this->cmp( $svc, $pid, $cycle_id, 'TC4: Emotion in the Heat of the Moment',      'learndash_course',             ++$n, array( 'course_id' => self::TC4 ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Coaching Session #4',                                    'coaching_session_attendance',  ++$n, array( 'session_number' => 4 ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Reflective Practice Session #4',                          'reflective_practice_session', ++$n, array( 'session_number' => 4 ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Child Assessment (Post)',                                 'child_assessment',             ++$n, array( 'phase' => 'post' ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Teacher Self-Assessment (Post)',                          'teacher_self_assessment',      ++$n, array( 'phase' => 'post' ) );

        // Prerequisites: course chains.
        $this->add_prereq( $tc0, $tsa_pre );
        $this->add_prereq( $tc1, $tc0 );
        $this->add_prereq( $mc1, $tc1 );
        $this->add_prereq( $tc2, $mc1 );
        $this->add_prereq( $tc3, $tc2 );
        $this->add_prereq( $mc2, $tc3 );
        $this->add_prereq( $tc4, $mc2 );

        WP_CLI::log( "    Mentor Phase 1: pathway_id={$pid}, {$n} components" );
        return array( 'pathway_id' => $pid, 'component_count' => $n, 'component_ids' => $ids );
    }

    /**
     * Streamlined Phase 1 (11 components): TC0, TC1(S), MC1(S), CV#1,
     * TC2(S), CV#2, TC3(S), CV#3, TC4(S), MC2(S), CV#4
     * No prerequisites for Streamlined pathways.
     */
    private function create_streamlined_phase1( $svc, $cycle_id ) {
        $pid = $svc->create_pathway( array(
            'pathway_name'  => 'B2E Streamlined Phase 1',
            'cycle_id'      => $cycle_id,
            'target_roles'  => array( 'school_leader' ),
            'active_status' => 1,
        ) );

        $ids = array();
        $n = 0;
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'TC0: Welcome',                                                    'learndash_course',   ++$n, array( 'course_id' => self::TC0 ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'TC1: Intro (Streamlined)',                                         'learndash_course',   ++$n, array( 'course_id' => self::TC1_S ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'MC1: Intro to Reflective Practice (Streamlined)',                   'learndash_course',   ++$n, array( 'course_id' => self::MC1_S ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Classroom Visit #1',                                               'classroom_visit',    ++$n, array( 'visit_number' => 1 ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'TC2: Your Own Emotionality (Streamlined)',                          'learndash_course',   ++$n, array( 'course_id' => self::TC2_S ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Classroom Visit #2',                                               'classroom_visit',    ++$n, array( 'visit_number' => 2 ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'TC3: Getting to Know Emotion (Streamlined)',                        'learndash_course',   ++$n, array( 'course_id' => self::TC3_S ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Classroom Visit #3',                                               'classroom_visit',    ++$n, array( 'visit_number' => 3 ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'TC4: Emotion in the Heat of the Moment (Streamlined)',              'learndash_course',   ++$n, array( 'course_id' => self::TC4_S ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'MC2: Deeper Dive Reflective Practice (Streamlined)',                'learndash_course',   ++$n, array( 'course_id' => self::MC2_S ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Classroom Visit #4',                                               'classroom_visit',    ++$n, array( 'visit_number' => 4 ) );

        // No prerequisites for Streamlined pathways.

        WP_CLI::log( "    Streamlined Phase 1: pathway_id={$pid}, {$n} components" );
        return array( 'pathway_id' => $pid, 'component_count' => $n, 'component_ids' => $ids );
    }

    // ── Cycle 2 — Stubs (Phase C) ──────────────────────────────────

    /**
     * Seed Cycle 2 (active) — enrollments with turnover, 8 pathways, pending child assessments.
     * TODO: Implement in Task 5.
     */
    private function seed_cycle_2( $partnership_id, $org, $users, $classrooms, $instruments ) {
        WP_CLI::log( '  TODO: seed_cycle_2 not yet implemented.' );
        return array( 'cycle_id' => 0, 'enrollments' => array( 'all' => array() ), 'pathways' => array() );
    }

    // ── Coach — Stub (Phase D) ─────────────────────────────────────

    /**
     * Assign mgonzalez as coach for Boston school in Cycle 2.
     * TODO: Implement in Task 6.
     */
    private function seed_coach_assignment( $cycle_id, $boston_school_id ) {
        WP_CLI::log( '  TODO: seed_coach_assignment not yet implemented.' );
    }

    // ── Summary — Stub ─────────────────────────────────────────────

    /**
     * Print summary of seeded data.
     * TODO: Implement in Task 6.
     */
    private function print_summary( $partnership_id, $org, $classrooms, $users, $c1, $c2 ) {
        WP_CLI::line( '' );
        WP_CLI::success( 'Beginnings data seeded (partial — stub methods pending).' );
    }

    // ── Clean — Stub ───────────────────────────────────────────────

    /**
     * Remove all Beginnings data (both cycles).
     * TODO: Implement in Task 7.
     */
    private function clean() {
        WP_CLI::log( '  TODO: clean not yet implemented.' );
    }
}
