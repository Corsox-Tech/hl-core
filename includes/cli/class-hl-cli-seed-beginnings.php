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
        $tsa_ids        = $this->lookup_tsa_instrument_ids();

        // Phase B: Cycle 1 (closed).
        WP_CLI::line( '' );
        WP_CLI::line( '--- Cycle 1 (closed) ---' );
        $c1 = $this->seed_cycle_1( $partnership_id, $org, $users, $classrooms, $instruments, $tsa_ids );

        // Phase C: Cycle 2 (active).
        WP_CLI::line( '' );
        WP_CLI::line( '--- Cycle 2 (active) ---' );
        $c2 = $this->seed_cycle_2( $partnership_id, $org, $users, $classrooms, $instruments, $tsa_ids );

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

    /**
     * Look up teacher self-assessment instrument IDs by key.
     * Returns array( 'pre' => id, 'post' => id ).
     */
    private function lookup_tsa_instrument_ids() {
        global $wpdb;
        $t = $wpdb->prefix;

        $pre_id = (int) $wpdb->get_var(
            "SELECT instrument_id FROM {$t}hl_teacher_assessment_instrument WHERE instrument_key = 'b2e_self_assessment_pre' LIMIT 1"
        );
        $post_id = (int) $wpdb->get_var(
            "SELECT instrument_id FROM {$t}hl_teacher_assessment_instrument WHERE instrument_key = 'b2e_self_assessment_post' LIMIT 1"
        );

        if ( ! $pre_id || ! $post_id ) {
            WP_CLI::warning( 'TSA instruments not found (b2e_self_assessment_pre/post). Teacher Self-Assessment forms will show "No instrument assigned".' );
        } else {
            WP_CLI::log( "  [A7] TSA instruments: pre={$pre_id}, post={$post_id}" );
        }

        return array( 'pre' => $pre_id, 'post' => $post_id );
    }

    // ── Cycle 1 (Phase B) ───────────────────────────────────────────

    /**
     * Seed Cycle 1 (closed) — enrollments, teams, pathways, component states, child assessments.
     */
    private function seed_cycle_1( $partnership_id, $org, $users, $classrooms, $instruments, $tsa_ids ) {
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
        $pathways = $this->seed_pathways_c1( $cycle_id, $tsa_ids );

        // Assign pathways.
        $this->assign_pathways_c1( $enrollments, $pathways );

        // Teaching assignments.
        $this->seed_teaching_assignments( $enrollments, $classrooms, $org['schools'] );

        // Freeze child age groups.
        $frozen = HL_Child_Snapshot_Service::freeze_age_groups( $cycle_id );
        WP_CLI::log( "  [B7] Frozen age group snapshots: {$frozen}" );

        // Component states (~70% full completion).
        $this->seed_component_states_c1( $enrollments, $pathways );

        // Completion rollups.
        $this->seed_rollups( $enrollments, $cycle_id );

        // Child assessment instances (historical, submitted).
        $this->seed_child_assessments_c1( $cycle_id, $enrollments, $classrooms, $instruments );

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
    private function seed_pathways_c1( $cycle_id, $tsa_ids ) {
        $svc      = new HL_Pathway_Service();
        $pathways = array();

        $pathways['teacher']     = $this->create_teacher_phase1( $svc, $cycle_id, $tsa_ids );
        $pathways['mentor']      = $this->create_mentor_phase1( $svc, $cycle_id, $tsa_ids );
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
                $this->assign_pathway( $e['enrollment_id'], $pathways['teacher']['pathway_id'] );
                $count++;
            }
        }
        foreach ( $enrollments['mentors'] as $e ) {
            $this->assign_pathway( $e['enrollment_id'], $pathways['mentor']['pathway_id'] );
            $count++;
        }
        foreach ( $enrollments['school_leaders'] as $e ) {
            $this->assign_pathway( $e['enrollment_id'], $pathways['streamlined']['pathway_id'] );
            $count++;
        }
        if ( $enrollments['district_leader'] ) {
            $this->assign_pathway( $enrollments['district_leader']['enrollment_id'], $pathways['streamlined']['pathway_id'] );
            $count++;
        }
        WP_CLI::log( "  [B5] Pathway assignments: {$count}" );
    }

    /**
     * Assign a single pathway to an enrollment: updates legacy column AND inserts into hl_pathway_assignment.
     */
    private function assign_pathway( $enrollment_id, $pathway_id ) {
        global $wpdb;
        $t = $wpdb->prefix;

        $wpdb->update( $t . 'hl_enrollment',
            array( 'assigned_pathway_id' => $pathway_id ),
            array( 'enrollment_id' => $enrollment_id ) );

        $wpdb->insert( $t . 'hl_pathway_assignment', array(
            'enrollment_id'      => $enrollment_id,
            'pathway_id'         => $pathway_id,
            'assigned_by_user_id' => get_current_user_id() ?: 1,
            'assignment_type'    => 'explicit',
        ) );
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
    private function create_teacher_phase1( $svc, $cycle_id, $tsa_ids = array() ) {
        $pid = $svc->create_pathway( array(
            'pathway_name'  => 'B2E Teacher Phase 1',
            'cycle_id'      => $cycle_id,
            'target_roles'  => array( 'teacher' ),
            'active_status' => 1,
            'routing_type'  => 'teacher_phase_1',
        ) );

        $ids = array();
        $n = 0;
        $ids[] = $tsa_pre = $this->cmp( $svc, $pid, $cycle_id, 'Teacher Self-Assessment (Pre)',  'teacher_self_assessment', ++$n, array( 'phase' => 'pre', 'teacher_instrument_id' => isset( $tsa_ids['pre'] ) ? $tsa_ids['pre'] : 0 ) );
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
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Teacher Self-Assessment (Post)',             'teacher_self_assessment', ++$n, array( 'phase' => 'post', 'teacher_instrument_id' => isset( $tsa_ids['post'] ) ? $tsa_ids['post'] : 0 ) );

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
    private function create_mentor_phase1( $svc, $cycle_id, $tsa_ids = array() ) {
        $pid = $svc->create_pathway( array(
            'pathway_name'  => 'B2E Mentor Phase 1',
            'cycle_id'      => $cycle_id,
            'target_roles'  => array( 'mentor' ),
            'active_status' => 1,
            'routing_type'  => 'mentor_phase_1',
        ) );

        $ids = array();
        $n = 0;
        $ids[] = $tsa_pre = $this->cmp( $svc, $pid, $cycle_id, 'Teacher Self-Assessment (Pre)',               'teacher_self_assessment',      ++$n, array( 'phase' => 'pre', 'teacher_instrument_id' => isset( $tsa_ids['pre'] ) ? $tsa_ids['pre'] : 0 ) );
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
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Teacher Self-Assessment (Post)',                          'teacher_self_assessment',      ++$n, array( 'phase' => 'post', 'teacher_instrument_id' => isset( $tsa_ids['post'] ) ? $tsa_ids['post'] : 0 ) );

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
            'target_roles'  => array( 'school_leader', 'district_leader' ),
            'active_status' => 1,
            'routing_type'  => 'streamlined_phase_1',
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

    // ── Cycle 1 — Component States, Rollups, Child Assessments ────

    /**
     * Create component state rows for all Cycle 1 enrollments.
     * Non-stragglers: all components complete. Stragglers: 50-70% complete.
     */
    private function seed_component_states_c1( $enrollments, $pathways ) {
        global $wpdb;
        $t          = $wpdb->prefix;
        $count      = 0;
        $stragglers = self::get_cycle1_stragglers();
        $base_date  = '2025-02-01';

        foreach ( $enrollments['all'] as $e_idx => $e ) {
            $eid  = $e['enrollment_id'];
            $role = $e['role'];

            $pw_key = 'teacher';
            if ( $role === 'mentor' ) $pw_key = 'mentor';
            if ( in_array( $role, array( 'school_leader', 'district_leader' ), true ) ) $pw_key = 'streamlined';

            $pw        = $pathways[ $pw_key ];
            $comp_ids  = $pw['component_ids'];
            $total     = count( $comp_ids );
            $email     = isset( $e['email'] ) ? $e['email'] : '';
            $is_strag  = in_array( $email, $stragglers, true );

            // Deterministic cutoff for stragglers: 50-70% based on position in enrollment list.
            $cutoff = $total;
            if ( $is_strag ) {
                $pct    = 50 + ( ( $e_idx % 3 ) * 10 ); // 50%, 60%, or 70%
                $cutoff = (int) round( $total * $pct / 100 );
            }

            for ( $i = 0; $i < $total; $i++ ) {
                $comp_id = $comp_ids[ $i ];
                if ( $i < $cutoff ) {
                    $days_offset  = (int) round( ( $i / max( $total, 1 ) ) * 200 );
                    $completed_at = gmdate( 'Y-m-d H:i:s', strtotime( $base_date . " +{$days_offset} days" ) );
                    $wpdb->insert( $t . 'hl_component_state', array(
                        'enrollment_id'     => $eid,
                        'component_id'      => $comp_id,
                        'completion_status'  => 'complete',
                        'completion_percent' => 100,
                        'completed_at'      => $completed_at,
                        'last_computed_at'  => $completed_at,
                    ) );
                } else {
                    $wpdb->insert( $t . 'hl_component_state', array(
                        'enrollment_id'     => $eid,
                        'component_id'      => $comp_id,
                        'completion_status'  => 'not_started',
                        'completion_percent' => 0,
                        'last_computed_at'  => current_time( 'mysql' ),
                    ) );
                }
                $count++;
            }
        }

        $strag_count = count( array_filter( $enrollments['all'], function( $e ) use ( $stragglers ) {
            return in_array( $e['email'] ?? '', $stragglers, true );
        } ) );
        WP_CLI::log( "  [B8] Component states: {$count} (stragglers: {$strag_count})" );
    }

    /**
     * Create completion rollup rows for fully completed enrollments.
     */
    private function seed_rollups( $enrollments, $cycle_id ) {
        global $wpdb;
        $t     = $wpdb->prefix;
        $count = 0;

        foreach ( $enrollments['all'] as $e ) {
            $eid       = $e['enrollment_id'];
            $total     = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$t}hl_component_state WHERE enrollment_id = %d", $eid ) );
            $completed = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$t}hl_component_state WHERE enrollment_id = %d AND completion_status = 'complete'", $eid ) );

            if ( $total > 0 && $completed === $total ) {
                $wpdb->insert( $t . 'hl_completion_rollup', array(
                    'enrollment_id'              => $eid,
                    'cycle_id'                   => $cycle_id,
                    'pathway_completion_percent'  => 100.00,
                    'cycle_completion_percent'    => 100.00,
                    'last_computed_at'           => current_time( 'mysql' ),
                ) );
                $count++;
            }
        }

        WP_CLI::log( "  Completion rollups: {$count}" );
    }

    /**
     * Create Pre + Post child assessment instances for every teacher with a teaching assignment.
     */
    private function seed_child_assessments_c1( $cycle_id, $enrollments, $classrooms, $instruments ) {
        global $wpdb;
        $t = $wpdb->prefix;

        // Build classroom lookup: classroom_id => classroom data + child_ids.
        $cr_lookup = array();
        foreach ( $classrooms as $cr ) {
            $cr_lookup[ $cr['classroom_id'] ] = $cr;
            $cr_lookup[ $cr['classroom_id'] ]['child_ids'] = $wpdb->get_col( $wpdb->prepare(
                "SELECT child_id FROM {$t}hl_child_classroom_current WHERE classroom_id = %d",
                $cr['classroom_id']
            ) );
        }

        // Get teaching assignments for Cycle 1 enrollments.
        $all_eids = wp_list_pluck( $enrollments['all'], 'enrollment_id' );
        if ( empty( $all_eids ) ) return;

        $in = implode( ',', array_map( 'intval', $all_eids ) );
        $assignments = $wpdb->get_results(
            "SELECT enrollment_id, classroom_id FROM {$t}hl_teaching_assignment WHERE enrollment_id IN ({$in})"
        );

        $instances = 0;
        $childrows = 0;

        foreach ( $assignments as $asgn ) {
            $eid = (int) $asgn->enrollment_id;
            $cid = (int) $asgn->classroom_id;
            $cr  = isset( $cr_lookup[ $cid ] ) ? $cr_lookup[ $cid ] : null;
            if ( ! $cr ) continue;

            $instrument_id = isset( $instruments[ $cr['age_band'] ] ) ? $instruments[ $cr['age_band'] ] : null;

            foreach ( array( 'pre', 'post' ) as $phase ) {
                $date = $phase === 'pre' ? '2025-02-15' : '2025-08-20';

                $wpdb->insert( $t . 'hl_child_assessment_instance', array(
                    'instance_uuid'       => wp_generate_uuid4(),
                    'cycle_id'            => $cycle_id,
                    'enrollment_id'       => $eid,
                    'classroom_id'        => $cid,
                    'school_id'           => $cr['school_id'],
                    'phase'               => $phase,
                    'instrument_age_band' => $cr['age_band'],
                    'instrument_id'       => $instrument_id,
                    'status'              => 'submitted',
                    'submitted_at'        => $date,
                    'created_at'          => $date,
                ) );
                $instance_id = (int) $wpdb->insert_id;
                $instances++;

                // Childrows with deterministic scores.
                foreach ( $cr['child_ids'] as $c_idx => $child_id ) {
                    $score = ( ( $c_idx + $eid ) % 5 ) + 1; // 1-5 deterministic
                    $wpdb->insert( $t . 'hl_child_assessment_childrow', array(
                        'instance_id'      => $instance_id,
                        'child_id'         => (int) $child_id,
                        'frozen_age_group' => $cr['age_band'],
                        'instrument_id'    => $instrument_id,
                        'answers_json'     => wp_json_encode( array( 'q1' => (string) $score ) ),
                        'status'           => 'active',
                    ) );
                    $childrows++;
                }
            }
        }

        WP_CLI::log( "  [B9] Child assessment instances: {$instances}, childrows: {$childrows}" );
    }

    // ── Cycle 2 (Phase C) ──────────────────────────────────────────

    /**
     * Seed Cycle 2 (active) — enrollments with turnover, 8 pathways, pending child assessments.
     */
    private function seed_cycle_2( $partnership_id, $org, $users, $classrooms, $instruments, $tsa_ids ) {
        global $wpdb;
        $t = $wpdb->prefix;

        // Create cycle (active).
        $repo     = new HL_Cycle_Repository();
        $cycle_id = $repo->create( array(
            'cycle_name'     => 'Beginnings - Cycle 2 (2026)',
            'cycle_code'     => self::CYCLE_2_CODE,
            'partnership_id' => $partnership_id,
            'cycle_type'     => 'program',
            'district_id'    => $org['district_id'],
            'status'         => 'active',
            'start_date'     => '2026-01-15',
            'end_date'       => '2026-09-30',
        ) );
        foreach ( $org['schools'] as $school_id ) {
            $wpdb->insert( $t . 'hl_cycle_school', array(
                'cycle_id' => $cycle_id, 'school_id' => $school_id,
            ) );
        }
        WP_CLI::log( "  [C1] Cycle 2 created: id={$cycle_id}, status=active" );

        // Enrollments (with roster changes).
        $enrollments = $this->seed_enrollments_c2( $cycle_id, $org, $users );

        // Teams (same 6 teams, different composition).
        $this->seed_teams_c2( $cycle_id, $org['schools'], $enrollments );

        // Pathways (8 total).
        $pathways = $this->seed_pathways_c2( $cycle_id, $tsa_ids );

        // Assign pathways.
        $this->assign_pathways_c2( $enrollments, $pathways );

        // Teaching assignments (for Cycle 2 teachers).
        $this->seed_teaching_assignments( $enrollments, $classrooms, $org['schools'] );

        // Freeze child age groups for Cycle 2.
        $frozen = HL_Child_Snapshot_Service::freeze_age_groups( $cycle_id );
        WP_CLI::log( "  [C7] Frozen age group snapshots: {$frozen}" );

        // Child assessment instances (pending, Pre only).
        $this->seed_child_assessments_c2( $cycle_id, $enrollments, $classrooms, $instruments );

        return array( 'cycle_id' => $cycle_id, 'enrollments' => $enrollments, 'pathways' => $pathways );
    }

    /**
     * Enroll Cycle 2 users with roster turnover.
     * Key changes: Maria leaves, Lisa promoted to mentor (Colombia), Natalia new mentor (Boston).
     */
    private function seed_enrollments_c2( $cycle_id, $org, $users ) {
        $repo        = new HL_Enrollment_Repository();
        $enrollments = array(
            'district_leader'     => null,
            'school_leaders'      => array(),
            'mentors'             => array(),  // keyed by team_idx
            'teachers'            => array(),  // keyed by team_idx
            'lisa_mentor'         => null,     // Lisa promoted
            'natalia_mentor'      => null,     // Natalia new hire
            'extra_team_members'  => array(),  // for seed_teams() — Natalia on T01-Boston
            'all'                 => array(),
            'by_email'            => array(),
        );
        $departures  = self::get_cycle2_departures();
        $district_id = $org['district_id'];
        $schools     = $org['schools'];
        $teams_def   = self::get_teams();

        // District leader → Streamlined Phase 2.
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

        // School leaders → Streamlined Phase 2 (all return).
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

        // Returning mentors (skip Maria = index 5, Colombia).
        foreach ( $teams_def as $t_idx => $team ) {
            if ( in_array( $team['mentor_email'], $departures, true ) ) {
                continue; // Maria leaves
            }
            $uid = $users['mentors'][ $t_idx ]['user_id'];
            if ( ! $uid ) continue;
            $school_id = $schools[ $team['school'] ];
            $eid = $repo->create( array(
                'user_id' => $uid, 'cycle_id' => $cycle_id,
                'roles' => array( 'mentor' ), 'status' => 'active',
                'school_id' => $school_id, 'district_id' => $district_id,
            ) );
            $e = array( 'enrollment_id' => $eid, 'user_id' => $uid, 'role' => 'mentor',
                         'school_key' => $team['school'], 'team_idx' => $t_idx,
                         'email' => $team['mentor_email'], 'pathway_key' => 'mentor_p2' );
            $enrollments['mentors'][ $t_idx ] = $e;
            $enrollments['all'][]             = $e;
            $enrollments['by_email'][ $e['email'] ] = $e;
        }

        // Lisa — promoted teacher → mentor for Colombia.
        $lisa = $users['by_email']['lisa_teacher-T_01-boston@yopmail.com'];
        if ( $lisa['user_id'] ) {
            $eid = $repo->create( array(
                'user_id' => $lisa['user_id'], 'cycle_id' => $cycle_id,
                'roles' => array( 'mentor' ), 'status' => 'active',
                'school_id' => $schools['colombia'], 'district_id' => $district_id,
            ) );
            $e = array( 'enrollment_id' => $eid, 'user_id' => $lisa['user_id'], 'role' => 'mentor',
                         'school_key' => 'colombia', 'team_idx' => 5,
                         'email' => $lisa['email'], 'pathway_key' => 'mentor_transition' );
            $enrollments['lisa_mentor']    = $e;
            $enrollments['mentors'][5]     = $e; // Colombia team index
            $enrollments['all'][]          = $e;
            $enrollments['by_email'][ $e['email'] ] = $e;
        }

        // Natalia — new mentor at Boston (shadows on T01-Boston).
        $nat = $users['natalia'];
        if ( $nat['user_id'] ) {
            $eid = $repo->create( array(
                'user_id' => $nat['user_id'], 'cycle_id' => $cycle_id,
                'roles' => array( 'mentor' ), 'status' => 'active',
                'school_id' => $schools['boston'], 'district_id' => $district_id,
            ) );
            $e = array( 'enrollment_id' => $eid, 'user_id' => $nat['user_id'], 'role' => 'mentor',
                         'school_key' => 'boston', 'email' => $nat['email'],
                         'pathway_key' => 'mentor_p1' );
            $enrollments['natalia_mentor'] = $e;
            $enrollments['all'][]          = $e;
            $enrollments['by_email'][ $e['email'] ] = $e;
            // She joins T01-Boston as a team member (not mentor role).
            $enrollments['extra_team_members'][0] = array( $e ); // team index 0 = T01-Boston
        }

        // Returning teachers (skip departures).
        foreach ( $teams_def as $t_idx => $team ) {
            $school_id = $schools[ $team['school'] ];
            $enrollments['teachers'][ $t_idx ] = array();

            foreach ( $users['teachers'][ $t_idx ] as $t_data ) {
                if ( in_array( $t_data['email'], $departures, true ) ) {
                    continue; // Departed
                }
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

        WP_CLI::log( '  [C2] Cycle 2 enrollments: ' . count( $enrollments['all'] ) );
        return $enrollments;
    }

    /**
     * Create teams for Cycle 2 — same 6 teams, different composition.
     */
    private function seed_teams_c2( $cycle_id, $schools, $enrollments ) {
        // Reuse shared seed_teams() — the enrollments structure already reflects C2 roster.
        $this->seed_teams( $cycle_id, $schools, $enrollments, self::get_teams() );
    }

    /**
     * Create all 8 pathways for Cycle 2 (Phase 1 + Phase 2 variants).
     */
    private function seed_pathways_c2( $cycle_id, $tsa_ids ) {
        $svc      = new HL_Pathway_Service();
        $pathways = array();

        // Only create pathways that have enrollees in Cycle 2.
        // Teacher Phase 1 and Streamlined Phase 1 are omitted — nobody is assigned to them.
        $pathways['teacher_p2']          = $this->create_teacher_phase2( $svc, $cycle_id, $tsa_ids );
        $pathways['mentor_p1']           = $this->create_mentor_phase1( $svc, $cycle_id, $tsa_ids );
        $pathways['mentor_p2']           = $this->create_mentor_phase2( $svc, $cycle_id, $tsa_ids );
        $pathways['mentor_transition']   = $this->create_mentor_transition( $svc, $cycle_id, $tsa_ids );
        $pathways['mentor_completion']   = $this->create_mentor_completion( $svc, $cycle_id, $tsa_ids );
        $pathways['streamlined_p2']      = $this->create_streamlined_phase2( $svc, $cycle_id );

        WP_CLI::log( '  [C4] Cycle 2 pathways: ' . count( $pathways ) );
        return $pathways;
    }

    /**
     * Assign pathways to Cycle 2 enrollments.
     * Teachers → Teacher Phase 2, returning mentors → per pathway_key, Natalia → Mentor Phase 1,
     * Leaders → Streamlined Phase 2.
     */
    private function assign_pathways_c2( $enrollments, $pathways ) {
        $count = 0;

        // Returning teachers → Teacher Phase 2.
        foreach ( $enrollments['teachers'] as $team_teachers ) {
            foreach ( $team_teachers as $e ) {
                $this->assign_pathway( $e['enrollment_id'], $pathways['teacher_p2']['pathway_id'] );
                $count++;
            }
        }

        // Returning mentors → Mentor Phase 2 (or Mentor Transition for Lisa).
        foreach ( $enrollments['mentors'] as $e ) {
            $pw_key = isset( $e['pathway_key'] ) ? $e['pathway_key'] : 'mentor_p2';
            $this->assign_pathway( $e['enrollment_id'], $pathways[ $pw_key ]['pathway_id'] );
            $count++;
        }

        // Natalia → Mentor Phase 1.
        if ( $enrollments['natalia_mentor'] ) {
            $this->assign_pathway( $enrollments['natalia_mentor']['enrollment_id'], $pathways['mentor_p1']['pathway_id'] );
            $count++;
        }

        // School Leaders + District Leader → Streamlined Phase 2.
        foreach ( $enrollments['school_leaders'] as $e ) {
            $this->assign_pathway( $e['enrollment_id'], $pathways['streamlined_p2']['pathway_id'] );
            $count++;
        }
        if ( $enrollments['district_leader'] ) {
            $this->assign_pathway( $enrollments['district_leader']['enrollment_id'], $pathways['streamlined_p2']['pathway_id'] );
            $count++;
        }

        WP_CLI::log( "  [C5] Pathway assignments: {$count}" );
    }

    /**
     * Create Pre-only child assessment instances for Cycle 2 (pending, no answers).
     */
    private function seed_child_assessments_c2( $cycle_id, $enrollments, $classrooms, $instruments ) {
        global $wpdb;
        $t = $wpdb->prefix;

        // Build classroom lookup with child IDs.
        $cr_lookup = array();
        foreach ( $classrooms as $cr ) {
            $cr_lookup[ $cr['classroom_id'] ] = $cr;
            $cr_lookup[ $cr['classroom_id'] ]['child_ids'] = $wpdb->get_col( $wpdb->prepare(
                "SELECT child_id FROM {$t}hl_child_classroom_current WHERE classroom_id = %d",
                $cr['classroom_id']
            ) );
        }

        // Get Cycle 2 teaching assignments.
        $all_eids = wp_list_pluck( $enrollments['all'], 'enrollment_id' );
        if ( empty( $all_eids ) ) return;

        $in = implode( ',', array_map( 'intval', $all_eids ) );
        $assignments = $wpdb->get_results(
            "SELECT enrollment_id, classroom_id FROM {$t}hl_teaching_assignment WHERE enrollment_id IN ({$in})"
        );

        $instances = 0;
        $childrows = 0;

        foreach ( $assignments as $asgn ) {
            $eid = (int) $asgn->enrollment_id;
            $cid = (int) $asgn->classroom_id;
            $cr  = isset( $cr_lookup[ $cid ] ) ? $cr_lookup[ $cid ] : null;
            if ( ! $cr ) continue;

            $instrument_id = isset( $instruments[ $cr['age_band'] ] ) ? $instruments[ $cr['age_band'] ] : null;

            // Pre only, pending.
            $wpdb->insert( $t . 'hl_child_assessment_instance', array(
                'instance_uuid'       => wp_generate_uuid4(),
                'cycle_id'            => $cycle_id,
                'enrollment_id'       => $eid,
                'classroom_id'        => $cid,
                'school_id'           => $cr['school_id'],
                'phase'               => 'pre',
                'instrument_age_band' => $cr['age_band'],
                'instrument_id'       => $instrument_id,
                'status'              => 'not_started',
                'created_at'          => current_time( 'mysql' ),
            ) );
            $instance_id = (int) $wpdb->insert_id;
            $instances++;

            // Childrows with no answers.
            foreach ( $cr['child_ids'] as $child_id ) {
                $wpdb->insert( $t . 'hl_child_assessment_childrow', array(
                    'instance_id'      => $instance_id,
                    'child_id'         => (int) $child_id,
                    'frozen_age_group' => $cr['age_band'],
                    'instrument_id'    => $instrument_id,
                    'answers_json'     => null,
                    'status'           => 'active',
                ) );
                $childrows++;
            }
        }

        WP_CLI::log( "  [C8] Child assessment instances (pending): {$instances}, childrows: {$childrows}" );
    }

    // ── Phase 2 Pathway Builders ─────────────────────────────────────

    /**
     * Teacher Phase 2 (16 components): TSA Pre, CA Pre, TC5, SR#1, RP#1,
     * TC6, SR#2, RP#2, TC7, SR#3, RP#3, TC8, SR#4, RP#4, CA Post, TSA Post
     */
    private function create_teacher_phase2( $svc, $cycle_id, $tsa_ids = array() ) {
        $pid = $svc->create_pathway( array(
            'pathway_name'  => 'B2E Teacher Phase 2',
            'cycle_id'      => $cycle_id,
            'target_roles'  => array( 'teacher' ),
            'active_status' => 1,
            'routing_type'  => 'teacher_phase_2',
        ) );

        $ids = array();
        $n = 0;
        $ids[] = $tsa_pre = $this->cmp( $svc, $pid, $cycle_id, 'Teacher Self-Assessment (Pre)',  'teacher_self_assessment', ++$n, array( 'phase' => 'pre', 'teacher_instrument_id' => isset( $tsa_ids['pre'] ) ? $tsa_ids['pre'] : 0 ) );
        $ids[] = $ca_pre  = $this->cmp( $svc, $pid, $cycle_id, 'Child Assessment (Pre)',          'child_assessment',        ++$n, array( 'phase' => 'pre' ) );
        $ids[] = $tc5     = $this->cmp( $svc, $pid, $cycle_id, 'TC5: Connecting Emotion and Early Learning', 'learndash_course', ++$n, array( 'course_id' => self::TC5 ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Self-Reflection #1',                        'self_reflection',         ++$n, array( 'visit_number' => 1 ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Reflective Practice Session #1',             'reflective_practice_session', ++$n, array( 'session_number' => 1 ) );
        $ids[] = $tc6     = $this->cmp( $svc, $pid, $cycle_id, 'TC6: Empathy, Acceptance & Prosocial Behaviors', 'learndash_course', ++$n, array( 'course_id' => self::TC6 ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Self-Reflection #2',                        'self_reflection',         ++$n, array( 'visit_number' => 2 ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Reflective Practice Session #2',             'reflective_practice_session', ++$n, array( 'session_number' => 2 ) );
        $ids[] = $tc7     = $this->cmp( $svc, $pid, $cycle_id, 'TC7: begin to ECSEL Tools & Trauma-Informed', 'learndash_course', ++$n, array( 'course_id' => self::TC7 ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Self-Reflection #3',                        'self_reflection',         ++$n, array( 'visit_number' => 3 ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Reflective Practice Session #3',             'reflective_practice_session', ++$n, array( 'session_number' => 3 ) );
        $ids[] = $tc8     = $this->cmp( $svc, $pid, $cycle_id, 'TC8: ECSEL in the Everyday Classroom', 'learndash_course', ++$n, array( 'course_id' => self::TC8 ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Self-Reflection #4',                        'self_reflection',         ++$n, array( 'visit_number' => 4 ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Reflective Practice Session #4',             'reflective_practice_session', ++$n, array( 'session_number' => 4 ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Child Assessment (Post)',                    'child_assessment',        ++$n, array( 'phase' => 'post' ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Teacher Self-Assessment (Post)',             'teacher_self_assessment', ++$n, array( 'phase' => 'post', 'teacher_instrument_id' => isset( $tsa_ids['post'] ) ? $tsa_ids['post'] : 0 ) );

        // Prerequisites: course chain TC5→TC6→TC7→TC8, first course blocked by TSA Pre.
        $this->add_prereq( $tc5, $tsa_pre );
        $this->add_prereq( $tc6, $tc5 );
        $this->add_prereq( $tc7, $tc6 );
        $this->add_prereq( $tc8, $tc7 );

        WP_CLI::log( "    Teacher Phase 2: pathway_id={$pid}, {$n} components" );
        return array( 'pathway_id' => $pid, 'component_count' => $n, 'component_ids' => $ids );
    }

    /**
     * Mentor Phase 2 (18 components): TSA Pre, CA Pre, TC5, Coaching#1,
     * MC3, RP#1, TC6, Coaching#2, RP#2, TC7, Coaching#3, MC4, RP#3,
     * TC8, Coaching#4, RP#4, CA Post, TSA Post
     */
    private function create_mentor_phase2( $svc, $cycle_id, $tsa_ids = array() ) {
        $pid = $svc->create_pathway( array(
            'pathway_name'  => 'B2E Mentor Phase 2',
            'cycle_id'      => $cycle_id,
            'target_roles'  => array( 'mentor' ),
            'active_status' => 1,
            'routing_type'  => 'mentor_phase_2',
        ) );

        $ids = array();
        $n = 0;
        $ids[] = $tsa_pre = $this->cmp( $svc, $pid, $cycle_id, 'Teacher Self-Assessment (Pre)',                       'teacher_self_assessment',      ++$n, array( 'phase' => 'pre', 'teacher_instrument_id' => isset( $tsa_ids['pre'] ) ? $tsa_ids['pre'] : 0 ) );
        $ids[] = $ca_pre  = $this->cmp( $svc, $pid, $cycle_id, 'Child Assessment (Pre)',                               'child_assessment',             ++$n, array( 'phase' => 'pre' ) );
        $ids[] = $tc5     = $this->cmp( $svc, $pid, $cycle_id, 'TC5: Connecting Emotion and Early Learning',           'learndash_course',             ++$n, array( 'course_id' => self::TC5 ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Coaching Session #1',                                            'coaching_session_attendance',  ++$n, array( 'session_number' => 1 ) );
        $ids[] = $mc3     = $this->cmp( $svc, $pid, $cycle_id, 'MC3: Extending RP to Communication with Co-Workers',  'learndash_course',             ++$n, array( 'course_id' => self::MC3 ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Reflective Practice Session #1',                                  'reflective_practice_session', ++$n, array( 'session_number' => 1 ) );
        $ids[] = $tc6     = $this->cmp( $svc, $pid, $cycle_id, 'TC6: Empathy, Acceptance & Prosocial Behaviors',      'learndash_course',             ++$n, array( 'course_id' => self::TC6 ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Coaching Session #2',                                            'coaching_session_attendance',  ++$n, array( 'session_number' => 2 ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Reflective Practice Session #2',                                  'reflective_practice_session', ++$n, array( 'session_number' => 2 ) );
        $ids[] = $tc7     = $this->cmp( $svc, $pid, $cycle_id, 'TC7: begin to ECSEL Tools & Trauma-Informed',         'learndash_course',             ++$n, array( 'course_id' => self::TC7 ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Coaching Session #3',                                            'coaching_session_attendance',  ++$n, array( 'session_number' => 3 ) );
        $ids[] = $mc4     = $this->cmp( $svc, $pid, $cycle_id, 'MC4: Extending RP to Communication with Families',    'learndash_course',             ++$n, array( 'course_id' => self::MC4 ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Reflective Practice Session #3',                                  'reflective_practice_session', ++$n, array( 'session_number' => 3 ) );
        $ids[] = $tc8     = $this->cmp( $svc, $pid, $cycle_id, 'TC8: ECSEL in the Everyday Classroom',                'learndash_course',             ++$n, array( 'course_id' => self::TC8 ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Coaching Session #4',                                            'coaching_session_attendance',  ++$n, array( 'session_number' => 4 ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Reflective Practice Session #4',                                  'reflective_practice_session', ++$n, array( 'session_number' => 4 ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Child Assessment (Post)',                                         'child_assessment',             ++$n, array( 'phase' => 'post' ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Teacher Self-Assessment (Post)',                                  'teacher_self_assessment',      ++$n, array( 'phase' => 'post', 'teacher_instrument_id' => isset( $tsa_ids['post'] ) ? $tsa_ids['post'] : 0 ) );

        // Prerequisites: course chain.
        $this->add_prereq( $tc5, $tsa_pre );
        $this->add_prereq( $mc3, $tc5 );
        $this->add_prereq( $tc6, $mc3 );
        $this->add_prereq( $tc7, $tc6 );
        $this->add_prereq( $mc4, $tc7 );
        $this->add_prereq( $tc8, $mc4 );

        WP_CLI::log( "    Mentor Phase 2: pathway_id={$pid}, {$n} components" );
        return array( 'pathway_id' => $pid, 'component_count' => $n, 'component_ids' => $ids );
    }

    /**
     * Mentor Transition (18 components): TSA Pre, CA Pre, TC5, Coaching#1,
     * MC1, RP#1, TC6, Coaching#2, RP#2, TC7, Coaching#3, MC2, RP#3,
     * TC8, Coaching#4, RP#4, CA Post, TSA Post
     * Same structure as Mentor Phase 2 but uses MC1/MC2 instead of MC3/MC4.
     */
    private function create_mentor_transition( $svc, $cycle_id, $tsa_ids = array() ) {
        $pid = $svc->create_pathway( array(
            'pathway_name'  => 'B2E Mentor Transition',
            'cycle_id'      => $cycle_id,
            'target_roles'  => array( 'mentor' ),
            'active_status' => 1,
            'routing_type'  => 'mentor_transition',
        ) );

        $ids = array();
        $n = 0;
        $ids[] = $tsa_pre = $this->cmp( $svc, $pid, $cycle_id, 'Teacher Self-Assessment (Pre)',                       'teacher_self_assessment',      ++$n, array( 'phase' => 'pre', 'teacher_instrument_id' => isset( $tsa_ids['pre'] ) ? $tsa_ids['pre'] : 0 ) );
        $ids[] = $ca_pre  = $this->cmp( $svc, $pid, $cycle_id, 'Child Assessment (Pre)',                               'child_assessment',             ++$n, array( 'phase' => 'pre' ) );
        $ids[] = $tc5     = $this->cmp( $svc, $pid, $cycle_id, 'TC5: Connecting Emotion and Early Learning',           'learndash_course',             ++$n, array( 'course_id' => self::TC5 ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Coaching Session #1',                                            'coaching_session_attendance',  ++$n, array( 'session_number' => 1 ) );
        $ids[] = $mc1     = $this->cmp( $svc, $pid, $cycle_id, 'MC1: Introduction to Reflective Practice',            'learndash_course',             ++$n, array( 'course_id' => self::MC1 ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Reflective Practice Session #1',                                  'reflective_practice_session', ++$n, array( 'session_number' => 1 ) );
        $ids[] = $tc6     = $this->cmp( $svc, $pid, $cycle_id, 'TC6: Empathy, Acceptance & Prosocial Behaviors',      'learndash_course',             ++$n, array( 'course_id' => self::TC6 ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Coaching Session #2',                                            'coaching_session_attendance',  ++$n, array( 'session_number' => 2 ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Reflective Practice Session #2',                                  'reflective_practice_session', ++$n, array( 'session_number' => 2 ) );
        $ids[] = $tc7     = $this->cmp( $svc, $pid, $cycle_id, 'TC7: begin to ECSEL Tools & Trauma-Informed',         'learndash_course',             ++$n, array( 'course_id' => self::TC7 ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Coaching Session #3',                                            'coaching_session_attendance',  ++$n, array( 'session_number' => 3 ) );
        $ids[] = $mc2     = $this->cmp( $svc, $pid, $cycle_id, 'MC2: A Deeper Dive into Reflective Practice',         'learndash_course',             ++$n, array( 'course_id' => self::MC2 ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Reflective Practice Session #3',                                  'reflective_practice_session', ++$n, array( 'session_number' => 3 ) );
        $ids[] = $tc8     = $this->cmp( $svc, $pid, $cycle_id, 'TC8: ECSEL in the Everyday Classroom',                'learndash_course',             ++$n, array( 'course_id' => self::TC8 ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Coaching Session #4',                                            'coaching_session_attendance',  ++$n, array( 'session_number' => 4 ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Reflective Practice Session #4',                                  'reflective_practice_session', ++$n, array( 'session_number' => 4 ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Child Assessment (Post)',                                         'child_assessment',             ++$n, array( 'phase' => 'post' ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Teacher Self-Assessment (Post)',                                  'teacher_self_assessment',      ++$n, array( 'phase' => 'post', 'teacher_instrument_id' => isset( $tsa_ids['post'] ) ? $tsa_ids['post'] : 0 ) );

        // Prerequisites: course chain.
        $this->add_prereq( $tc5, $tsa_pre );
        $this->add_prereq( $mc1, $tc5 );
        $this->add_prereq( $tc6, $mc1 );
        $this->add_prereq( $tc7, $tc6 );
        $this->add_prereq( $mc2, $tc7 );
        $this->add_prereq( $tc8, $mc2 );

        WP_CLI::log( "    Mentor Transition: pathway_id={$pid}, {$n} components" );
        return array( 'pathway_id' => $pid, 'component_count' => $n, 'component_ids' => $ids );
    }

    /**
     * Mentor Completion (4 components): TSA Pre, MC3, MC4, TSA Post
     */
    private function create_mentor_completion( $svc, $cycle_id, $tsa_ids = array() ) {
        $pid = $svc->create_pathway( array(
            'pathway_name'  => 'B2E Mentor Completion',
            'cycle_id'      => $cycle_id,
            'target_roles'  => array( 'mentor' ),
            'active_status' => 1,
            'routing_type'  => 'mentor_completion',
        ) );

        $ids = array();
        $n = 0;
        $ids[] = $tsa_pre = $this->cmp( $svc, $pid, $cycle_id, 'Teacher Self-Assessment (Pre)',                       'teacher_self_assessment', ++$n, array( 'phase' => 'pre', 'teacher_instrument_id' => isset( $tsa_ids['pre'] ) ? $tsa_ids['pre'] : 0 ) );
        $ids[] = $mc3     = $this->cmp( $svc, $pid, $cycle_id, 'MC3: Extending RP to Communication with Co-Workers',  'learndash_course',        ++$n, array( 'course_id' => self::MC3 ) );
        $ids[] = $mc4     = $this->cmp( $svc, $pid, $cycle_id, 'MC4: Extending RP to Communication with Families',    'learndash_course',        ++$n, array( 'course_id' => self::MC4 ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Teacher Self-Assessment (Post)',                                  'teacher_self_assessment', ++$n, array( 'phase' => 'post', 'teacher_instrument_id' => isset( $tsa_ids['post'] ) ? $tsa_ids['post'] : 0 ) );

        // Prerequisites: TSA Pre → MC3 → MC4.
        $this->add_prereq( $mc3, $tsa_pre );
        $this->add_prereq( $mc4, $mc3 );

        WP_CLI::log( "    Mentor Completion: pathway_id={$pid}, {$n} components" );
        return array( 'pathway_id' => $pid, 'component_count' => $n, 'component_ids' => $ids );
    }

    /**
     * Streamlined Phase 2 (10 components): TC5(S), MC3(S), CV#1, TC6(S),
     * CV#2, TC7(S), CV#3, TC8(S), MC4(S), CV#4
     * No prerequisites for Streamlined pathways.
     */
    private function create_streamlined_phase2( $svc, $cycle_id ) {
        $pid = $svc->create_pathway( array(
            'pathway_name'  => 'B2E Streamlined Phase 2',
            'cycle_id'      => $cycle_id,
            'target_roles'  => array( 'school_leader', 'district_leader' ),
            'active_status' => 1,
            'routing_type'  => 'streamlined_phase_2',
        ) );

        $ids = array();
        $n = 0;
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'TC5: Connecting Emotion and Early Learning (Streamlined)',        'learndash_course',   ++$n, array( 'course_id' => self::TC5_S ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'MC3: Extending RP to Co-Workers (Streamlined)',                    'learndash_course',   ++$n, array( 'course_id' => self::MC3_S ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Classroom Visit #1',                                              'classroom_visit',    ++$n, array( 'visit_number' => 1 ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'TC6: Empathy, Inclusivity & Prosocial Behaviors (Streamlined)',   'learndash_course',   ++$n, array( 'course_id' => self::TC6_S ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Classroom Visit #2',                                              'classroom_visit',    ++$n, array( 'visit_number' => 2 ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'TC7: begin to ECSEL Tools & Trauma-Informed (Streamlined)',       'learndash_course',   ++$n, array( 'course_id' => self::TC7_S ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Classroom Visit #3',                                              'classroom_visit',    ++$n, array( 'visit_number' => 3 ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'TC8: ECSEL in the Everyday Classroom (Streamlined)',              'learndash_course',   ++$n, array( 'course_id' => self::TC8_S ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'MC4: Extending RP to Families (Streamlined)',                      'learndash_course',   ++$n, array( 'course_id' => self::MC4_S ) );
        $ids[] = $this->cmp( $svc, $pid, $cycle_id, 'Classroom Visit #4',                                              'classroom_visit',    ++$n, array( 'visit_number' => 4 ) );

        // No prerequisites for Streamlined pathways.

        WP_CLI::log( "    Streamlined Phase 2: pathway_id={$pid}, {$n} components" );
        return array( 'pathway_id' => $pid, 'component_count' => $n, 'component_ids' => $ids );
    }

    // ── Coach — Stub (Phase D) ─────────────────────────────────────

    /**
     * Assign mgonzalez as coach for Boston school in Cycle 2.
     */
    private function seed_coach_assignment( $cycle_id, $boston_school_id ) {
        // Find or create mgonzalez user.
        $coach_email = 'mgonzalez@housmanlearning.com';
        $coach       = get_user_by( 'email', $coach_email );

        if ( ! $coach ) {
            $uid = wp_insert_user( array(
                'user_login'   => 'mgonzalez',
                'user_email'   => $coach_email,
                'user_pass'    => $coach_email,
                'display_name' => 'Mateo Gonzalez',
                'first_name'   => 'Mateo',
                'last_name'    => 'Gonzalez',
                'role'         => 'subscriber',
            ) );
            if ( is_wp_error( $uid ) ) {
                WP_CLI::warning( 'Could not create coach user: ' . $uid->get_error_message() );
                return;
            }
            update_user_meta( $uid, self::META_KEY, 'created' );
            $coach_user_id = $uid;
            WP_CLI::log( "  [D1] Coach user created: {$coach_email} (ID {$uid})" );
        } else {
            update_user_meta( $coach->ID, self::META_KEY, 'found' );
            $coach_user_id = $coach->ID;
            WP_CLI::log( "  [D1] Coach user exists: {$coach_email} (ID {$coach->ID})" );
        }

        // Grant manage_hl_core capability.
        $user_obj = new WP_User( $coach_user_id );
        $user_obj->add_cap( 'manage_hl_core' );

        // Assign as coach for Boston school in Cycle 2.
        $svc    = new HL_Coach_Assignment_Service();
        $result = $svc->assign_coach( array(
            'coach_user_id'  => $coach_user_id,
            'scope_type'     => 'school',
            'scope_id'       => $boston_school_id,
            'cycle_id'       => $cycle_id,
            'effective_from' => '2026-01-15',
        ) );
        if ( is_wp_error( $result ) ) {
            WP_CLI::warning( 'Coach assignment error: ' . $result->get_error_message() );
        } else {
            WP_CLI::log( '  [D1] Coach assigned: mgonzalez → Beginnings Boston (school scope)' );
        }
    }

    // ── Summary — Stub ─────────────────────────────────────────────

    /**
     * Print summary of seeded data.
     */
    private function print_summary( $partnership_id, $org, $classrooms, $users, $c1, $c2 ) {
        WP_CLI::line( '' );
        WP_CLI::success( 'Beginnings data seeded successfully!' );
        WP_CLI::line( '' );
        WP_CLI::line( 'Summary:' );
        WP_CLI::line( "  Partnership:    {$partnership_id}" );
        WP_CLI::line( "  District:       {$org['district_id']}" );
        WP_CLI::line( '  Schools:        ' . count( $org['schools'] ) );
        WP_CLI::line( '  Classrooms:     ' . count( $classrooms ) );
        WP_CLI::line( '  WP Users:       ' . $users['count'] );
        WP_CLI::line( '' );
        WP_CLI::line( "  Cycle 1 (closed): id={$c1['cycle_id']}, " . count( $c1['enrollments']['all'] ) . ' enrollments, '
                    . count( $c1['pathways'] ) . ' pathways' );
        WP_CLI::line( "  Cycle 2 (active): id={$c2['cycle_id']}, " . count( $c2['enrollments']['all'] ) . ' enrollments, '
                    . count( $c2['pathways'] ) . ' pathways' );
        WP_CLI::line( '' );
        WP_CLI::line( '=== Demo Accounts ===' );
        WP_CLI::line( '  Coach:          mgonzalez@housmanlearning.com (password: same)' );
        WP_CLI::line( '  School Leader:  boston-school-leader@yopmail.com' );
        WP_CLI::line( '  Mentor:         mentor-T_01-boston@yopmail.com' );
        WP_CLI::line( '  Teacher:        john_teacher-T_01-boston@yopmail.com' );
        WP_CLI::line( '  (Password for yopmail accounts = email address)' );
        WP_CLI::line( '' );
    }

    // ── Clean — Stub ───────────────────────────────────────────────

    /**
     * Remove all Beginnings data (both cycles).
     */
    private function clean() {
        global $wpdb;
        $t = $wpdb->prefix;

        WP_CLI::line( 'Cleaning Beginnings data...' );

        // Find both cycle IDs.
        $cycle_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT cycle_id FROM {$t}hl_cycle WHERE cycle_code IN (%s, %s)",
            self::CYCLE_1_CODE, self::CYCLE_2_CODE
        ) );

        foreach ( $cycle_ids as $cycle_id ) {
            $cycle_id = (int) $cycle_id;
            WP_CLI::log( "  Cleaning cycle {$cycle_id}..." );

            // Get enrollment IDs.
            $enrollment_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT enrollment_id FROM {$t}hl_enrollment WHERE cycle_id = %d", $cycle_id
            ) );

            if ( ! empty( $enrollment_ids ) ) {
                $in = implode( ',', array_map( 'intval', $enrollment_ids ) );

                // Enrollment-scoped tables.
                $wpdb->query( "DELETE FROM {$t}hl_completion_rollup WHERE enrollment_id IN ({$in})" );
                $wpdb->query( "DELETE FROM {$t}hl_component_state WHERE enrollment_id IN ({$in})" );
                $wpdb->query( "DELETE FROM {$t}hl_component_override WHERE enrollment_id IN ({$in})" );
                $wpdb->query( "DELETE FROM {$t}hl_team_membership WHERE enrollment_id IN ({$in})" );
                $wpdb->query( "DELETE FROM {$t}hl_teaching_assignment WHERE enrollment_id IN ({$in})" );

                // Child assessment instances + childrows.
                $ca_ids = $wpdb->get_col(
                    "SELECT instance_id FROM {$t}hl_child_assessment_instance WHERE enrollment_id IN ({$in})"
                );
                if ( ! empty( $ca_ids ) ) {
                    $in_ca = implode( ',', array_map( 'intval', $ca_ids ) );
                    $wpdb->query( "DELETE FROM {$t}hl_child_assessment_childrow WHERE instance_id IN ({$in_ca})" );
                }
                $wpdb->query( "DELETE FROM {$t}hl_child_assessment_instance WHERE enrollment_id IN ({$in})" );

                // Teacher assessment instances.
                $wpdb->query( "DELETE FROM {$t}hl_teacher_assessment_instance WHERE enrollment_id IN ({$in})" );
            }

            // Observations (cycle-scoped).
            $obs_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT observation_id FROM {$t}hl_observation WHERE cycle_id = %d", $cycle_id
            ) );
            if ( ! empty( $obs_ids ) ) {
                $in_obs = implode( ',', array_map( 'intval', $obs_ids ) );
                $wpdb->query( "DELETE FROM {$t}hl_observation_attachment WHERE observation_id IN ({$in_obs})" );
                $wpdb->query( "DELETE FROM {$t}hl_observation_response WHERE observation_id IN ({$in_obs})" );
            }
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$t}hl_observation WHERE cycle_id = %d", $cycle_id ) );

            // Coaching sessions (cycle-scoped).
            $session_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT session_id FROM {$t}hl_coaching_session WHERE cycle_id = %d", $cycle_id
            ) );
            if ( ! empty( $session_ids ) ) {
                $in_sess = implode( ',', array_map( 'intval', $session_ids ) );
                $wpdb->query( "DELETE FROM {$t}hl_coaching_session_submission WHERE session_id IN ({$in_sess})" );
                // coaching_session_observation and coaching_attachment may not exist yet — safe to attempt.
                $wpdb->query( "DELETE FROM {$t}hl_coaching_session_observation WHERE session_id IN ({$in_sess})" );
                $wpdb->query( "DELETE FROM {$t}hl_coaching_attachment WHERE session_id IN ({$in_sess})" );
            }
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$t}hl_coaching_session WHERE cycle_id = %d", $cycle_id ) );

            // RP Sessions.
            $rp_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT rp_session_id FROM {$t}hl_rp_session WHERE cycle_id = %d", $cycle_id
            ) );
            if ( ! empty( $rp_ids ) ) {
                $in_rp = implode( ',', array_map( 'intval', $rp_ids ) );
                $wpdb->query( "DELETE FROM {$t}hl_rp_session_submission WHERE rp_session_id IN ({$in_rp})" );
            }
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$t}hl_rp_session WHERE cycle_id = %d", $cycle_id ) );

            // Classroom Visits.
            $cv_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT classroom_visit_id FROM {$t}hl_classroom_visit WHERE cycle_id = %d", $cycle_id
            ) );
            if ( ! empty( $cv_ids ) ) {
                $in_cv = implode( ',', array_map( 'intval', $cv_ids ) );
                $wpdb->query( "DELETE FROM {$t}hl_classroom_visit_submission WHERE classroom_visit_id IN ({$in_cv})" );
            }
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$t}hl_classroom_visit WHERE cycle_id = %d", $cycle_id ) );

            // Components + prereqs.
            $comp_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT component_id FROM {$t}hl_component WHERE cycle_id = %d", $cycle_id
            ) );
            if ( ! empty( $comp_ids ) ) {
                $in_comp = implode( ',', array_map( 'intval', $comp_ids ) );
                $group_ids = $wpdb->get_col(
                    "SELECT group_id FROM {$t}hl_component_prereq_group WHERE component_id IN ({$in_comp})"
                );
                if ( ! empty( $group_ids ) ) {
                    $in_grp = implode( ',', array_map( 'intval', $group_ids ) );
                    $wpdb->query( "DELETE FROM {$t}hl_component_prereq_item WHERE group_id IN ({$in_grp})" );
                }
                $wpdb->query( "DELETE FROM {$t}hl_component_prereq_group WHERE component_id IN ({$in_comp})" );
                $wpdb->query( "DELETE FROM {$t}hl_component_drip_rule WHERE component_id IN ({$in_comp})" );
            }
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$t}hl_component WHERE cycle_id = %d", $cycle_id ) );
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$t}hl_pathway WHERE cycle_id = %d", $cycle_id ) );

            // Teams (also clean memberships by team_id for safety).
            $team_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT team_id FROM {$t}hl_team WHERE cycle_id = %d", $cycle_id
            ) );
            if ( ! empty( $team_ids ) ) {
                $in_teams = implode( ',', array_map( 'intval', $team_ids ) );
                $wpdb->query( "DELETE FROM {$t}hl_team_membership WHERE team_id IN ({$in_teams})" );
            }
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$t}hl_team WHERE cycle_id = %d", $cycle_id ) );

            // Enrollments + cycle-level records.
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$t}hl_enrollment WHERE cycle_id = %d", $cycle_id ) );
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$t}hl_cycle_school WHERE cycle_id = %d", $cycle_id ) );
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$t}hl_coach_assignment WHERE cycle_id = %d", $cycle_id ) );
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$t}hl_child_cycle_snapshot WHERE cycle_id = %d", $cycle_id ) );
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$t}hl_cycle WHERE cycle_id = %d", $cycle_id ) );

            WP_CLI::log( "  Deleted cycle {$cycle_id} and all related records." );
        }

        // Children + classrooms by school.
        $school_ids = $wpdb->get_col(
            "SELECT orgunit_id FROM {$t}hl_orgunit WHERE name LIKE 'Beginnings%' AND orgunit_type = 'school'"
        );
        if ( ! empty( $school_ids ) ) {
            $in_schools = implode( ',', array_map( 'intval', $school_ids ) );
            $cr_ids = $wpdb->get_col(
                "SELECT classroom_id FROM {$t}hl_classroom WHERE school_id IN ({$in_schools})"
            );
            if ( ! empty( $cr_ids ) ) {
                $in_crs = implode( ',', array_map( 'intval', $cr_ids ) );
                $wpdb->query( "DELETE FROM {$t}hl_child_classroom_current WHERE classroom_id IN ({$in_crs})" );
                $wpdb->query( "DELETE FROM {$t}hl_child_classroom_history WHERE classroom_id IN ({$in_crs})" );
            }
            $wpdb->query( "DELETE FROM {$t}hl_child WHERE school_id IN ({$in_schools})" );
            $wpdb->query( "DELETE FROM {$t}hl_classroom WHERE school_id IN ({$in_schools})" );
            WP_CLI::log( '  Deleted children and classrooms.' );
        }

        // WP users.
        $demo_rows = $wpdb->get_results(
            "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = '" . self::META_KEY . "'"
        );
        if ( ! empty( $demo_rows ) ) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            $deleted  = 0;
            $untagged = 0;
            foreach ( $demo_rows as $row ) {
                if ( $row->meta_value === 'found' ) {
                    delete_user_meta( (int) $row->user_id, self::META_KEY );
                    $untagged++;
                } else {
                    wp_delete_user( (int) $row->user_id );
                    $deleted++;
                }
            }
            WP_CLI::log( "  Deleted {$deleted} seed users, untagged {$untagged} pre-existing." );
        }

        // Org units.
        if ( ! empty( $school_ids ) ) {
            $in_schools = implode( ',', array_map( 'intval', $school_ids ) );
            // Get district (parent of these schools).
            $district_ids = $wpdb->get_col(
                "SELECT DISTINCT parent_orgunit_id FROM {$t}hl_orgunit WHERE orgunit_id IN ({$in_schools}) AND parent_orgunit_id IS NOT NULL"
            );
            $wpdb->query( "DELETE FROM {$t}hl_orgunit WHERE orgunit_id IN ({$in_schools})" );
            if ( ! empty( $district_ids ) ) {
                $wpdb->query( "DELETE FROM {$t}hl_orgunit WHERE orgunit_id IN (" . implode( ',', array_map( 'intval', $district_ids ) ) . ")" );
            }
            WP_CLI::log( '  Deleted org units.' );
        }

        // Partnership.
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$t}hl_partnership WHERE partnership_code = %s", self::PARTNERSHIP_CODE
        ) );
        WP_CLI::log( '  Deleted partnership.' );

        // Instruments.
        $wpdb->query( "DELETE FROM {$t}hl_instrument WHERE name LIKE 'Beginnings%'" );
        WP_CLI::log( '  Deleted instruments.' );
    }
}
