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

    // ── Cycle 1 — Stubs (Phase B) ──────────────────────────────────

    /**
     * Seed Cycle 1 (closed) — enrollments, teams, pathways, component states, child assessments.
     * TODO: Implement in Task 3+4.
     */
    private function seed_cycle_1( $partnership_id, $org, $users, $classrooms, $instruments ) {
        WP_CLI::log( '  TODO: seed_cycle_1 not yet implemented.' );
        return array( 'cycle_id' => 0, 'enrollments' => array( 'all' => array() ), 'pathways' => array() );
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
