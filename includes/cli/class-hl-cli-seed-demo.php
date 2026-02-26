<?php
/**
 * WP-CLI command: wp hl-core seed-demo
 *
 * Creates a realistic demo dataset covering all HL Core entities.
 * Use --clean to remove all demo data first.
 *
 * @package HL_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HL_CLI_Seed_Demo {

    /** Demo track code used to identify seeded data. */
    const DEMO_TRACK_CODE = 'DEMO-2026';

    /** Email domain pattern for demo users. */
    const DEMO_EMAIL_DOMAIN = 'example.com';

    /** User meta key to tag demo users. */
    const DEMO_META_KEY = '_hl_demo_seed';

    /**
     * Register the WP-CLI command.
     */
    public static function register() {
        WP_CLI::add_command( 'hl-core seed-demo', array( new self(), 'run' ) );
    }

    /**
     * Seed demo data for HL Core.
     *
     * ## OPTIONS
     *
     * [--clean]
     * : Remove all demo data before seeding.
     *
     * ## EXAMPLES
     *
     *     wp hl-core seed-demo
     *     wp hl-core seed-demo --clean
     *
     * @param array $args       Positional args.
     * @param array $assoc_args Named args.
     */
    public function run( $args, $assoc_args ) {
        $clean = isset( $assoc_args['clean'] );

        if ( $clean ) {
            $this->clean();
            WP_CLI::success( 'Demo data cleaned.' );
            // If only --clean, stop here. The user can re-run without --clean to reseed.
            return;
        }

        // Check if demo data already exists.
        if ( $this->demo_exists() ) {
            WP_CLI::warning( 'Demo data already exists. Run with --clean first to reseed.' );
            return;
        }

        WP_CLI::line( '' );
        WP_CLI::line( '=== HL Core Demo Seeder ===' );
        WP_CLI::line( '' );

        // Step 1: Org Structure
        list( $district_id, $school_a_id, $school_b_id ) = $this->seed_orgunits();

        // Step 2: Track
        $track_id = $this->seed_track( $district_id, $school_a_id, $school_b_id );

        // Step 3: Classrooms
        $classrooms = $this->seed_classrooms( $school_a_id, $school_b_id );

        // Step 4: Instruments
        $instruments = $this->seed_instruments();

        // Step 5: WP Users
        $users = $this->seed_users();

        // Step 6: Enrollments
        $enrollments = $this->seed_enrollments( $users, $track_id, $school_a_id, $school_b_id, $district_id );

        // Step 7: Teams
        $teams = $this->seed_teams( $track_id, $school_a_id, $school_b_id, $enrollments );

        // Step 8: Teaching Assignments
        $this->seed_teaching_assignments( $enrollments, $classrooms );

        // Step 9: Children
        $this->seed_children( $classrooms, $school_a_id, $school_b_id );

        // Step 10: Pathways & Activities
        $pathways = $this->seed_pathways( $track_id, $instruments );

        // Step 11: Update Enrollment assigned_pathway_id
        $this->assign_pathways( $enrollments, $pathways );

        // Step 12: Prereq Rule
        $this->seed_prereq_rules( $pathways );

        // Step 13: Drip Rule
        $this->seed_drip_rules( $pathways );

        // Step 14: Activity States
        $this->seed_activity_states( $enrollments, $pathways );

        // Step 15: Completion Rollups
        $this->seed_rollups( $enrollments );

        // Step 16: Coach Assignments
        $this->seed_coach_assignments( $track_id, $school_a_id, $school_b_id, $teams, $users );

        // Step 17: Coaching Sessions
        $this->seed_coaching_sessions( $track_id, $enrollments, $users );

        WP_CLI::line( '' );
        WP_CLI::success( 'Demo data seeded successfully!' );
        WP_CLI::line( '' );
        WP_CLI::line( 'Summary:' );
        WP_CLI::line( "  Track:        {$track_id} (code: " . self::DEMO_TRACK_CODE . ')' );
        WP_CLI::line( "  District:     {$district_id}" );
        WP_CLI::line( "  Schools:      {$school_a_id}, {$school_b_id}" );
        WP_CLI::line( '  Classrooms:   ' . count( $classrooms ) );
        WP_CLI::line( '  Instruments:  ' . count( $instruments ) );
        WP_CLI::line( '  Users:        ' . count( $users ) );
        WP_CLI::line( '  Enrollments:  ' . count( $enrollments['all'] ) );
        WP_CLI::line( '' );
    }

    // ------------------------------------------------------------------
    // Idempotency helpers
    // ------------------------------------------------------------------

    /**
     * Check if demo data already exists.
     *
     * @return bool
     */
    private function demo_exists() {
        global $wpdb;
        $row = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT track_id FROM {$wpdb->prefix}hl_track WHERE track_code = %s LIMIT 1",
                self::DEMO_TRACK_CODE
            )
        );
        return ! empty( $row );
    }

    /**
     * Remove all demo data.
     */
    private function clean() {
        global $wpdb;

        WP_CLI::line( 'Cleaning demo data...' );

        // 1. Find demo track.
        $track_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT track_id FROM {$wpdb->prefix}hl_track WHERE track_code = %s LIMIT 1",
                self::DEMO_TRACK_CODE
            )
        );

        if ( $track_id ) {
            // Delete dependent data in reverse dependency order.

            // Activity states & rollups via enrollments.
            $enrollment_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT enrollment_id FROM {$wpdb->prefix}hl_enrollment WHERE track_id = %d",
                    $track_id
                )
            );

            if ( ! empty( $enrollment_ids ) ) {
                $in_ids = implode( ',', array_map( 'intval', $enrollment_ids ) );

                $wpdb->query( "DELETE FROM {$wpdb->prefix}hl_completion_rollup WHERE enrollment_id IN ({$in_ids})" );
                $wpdb->query( "DELETE FROM {$wpdb->prefix}hl_activity_state WHERE enrollment_id IN ({$in_ids})" );
                $wpdb->query( "DELETE FROM {$wpdb->prefix}hl_activity_override WHERE enrollment_id IN ({$in_ids})" );
                $wpdb->query( "DELETE FROM {$wpdb->prefix}hl_team_membership WHERE enrollment_id IN ({$in_ids})" );
                $wpdb->query( "DELETE FROM {$wpdb->prefix}hl_teaching_assignment WHERE enrollment_id IN ({$in_ids})" );

                // child assessment instances.
                $ca_ids = $wpdb->get_col(
                    "SELECT instance_id FROM {$wpdb->prefix}hl_child_assessment_instance WHERE enrollment_id IN ({$in_ids})"
                );
                if ( ! empty( $ca_ids ) ) {
                    $in_ca = implode( ',', array_map( 'intval', $ca_ids ) );
                    $wpdb->query( "DELETE FROM {$wpdb->prefix}hl_child_assessment_childrow WHERE instance_id IN ({$in_ca})" );
                }
                $wpdb->query( "DELETE FROM {$wpdb->prefix}hl_child_assessment_instance WHERE enrollment_id IN ({$in_ids})" );

                // Teacher assessment instances.
                $wpdb->query( "DELETE FROM {$wpdb->prefix}hl_teacher_assessment_instance WHERE enrollment_id IN ({$in_ids})" );

                // Observations.
                $wpdb->query( "DELETE FROM {$wpdb->prefix}hl_observation WHERE track_id = {$track_id}" );
            }

            // Activities and related rules.
            $activity_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT activity_id FROM {$wpdb->prefix}hl_activity WHERE track_id = %d",
                    $track_id
                )
            );
            if ( ! empty( $activity_ids ) ) {
                $in_act = implode( ',', array_map( 'intval', $activity_ids ) );

                $group_ids = $wpdb->get_col(
                    "SELECT group_id FROM {$wpdb->prefix}hl_activity_prereq_group WHERE activity_id IN ({$in_act})"
                );
                if ( ! empty( $group_ids ) ) {
                    $in_grp = implode( ',', array_map( 'intval', $group_ids ) );
                    $wpdb->query( "DELETE FROM {$wpdb->prefix}hl_activity_prereq_item WHERE group_id IN ({$in_grp})" );
                }
                $wpdb->query( "DELETE FROM {$wpdb->prefix}hl_activity_prereq_group WHERE activity_id IN ({$in_act})" );
                $wpdb->query( "DELETE FROM {$wpdb->prefix}hl_activity_drip_rule WHERE activity_id IN ({$in_act})" );
            }
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}hl_activity WHERE track_id = %d", $track_id ) );

            // Pathways.
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}hl_pathway WHERE track_id = %d", $track_id ) );

            // Teams.
            $team_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT team_id FROM {$wpdb->prefix}hl_team WHERE track_id = %d",
                    $track_id
                )
            );
            if ( ! empty( $team_ids ) ) {
                $in_teams = implode( ',', array_map( 'intval', $team_ids ) );
                $wpdb->query( "DELETE FROM {$wpdb->prefix}hl_team_membership WHERE team_id IN ({$in_teams})" );
            }
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}hl_team WHERE track_id = %d", $track_id ) );

            // Enrollments.
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}hl_enrollment WHERE track_id = %d", $track_id ) );

            // Track-school links.
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}hl_track_school WHERE track_id = %d", $track_id ) );

            // Coach assignments.
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}hl_coach_assignment WHERE track_id = %d", $track_id ) );

            // Coaching sessions.
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}hl_coaching_session WHERE track_id = %d", $track_id ) );

            // Track.
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}hl_track WHERE track_id = %d", $track_id ) );

            WP_CLI::log( "  Deleted track {$track_id} and all related records." );
        }

        // 2. Find and delete demo users.
        $demo_user_ids = $wpdb->get_col(
            "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = '" . self::DEMO_META_KEY . "' AND meta_value = '1'"
        );

        if ( ! empty( $demo_user_ids ) ) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            foreach ( $demo_user_ids as $uid ) {
                wp_delete_user( (int) $uid );
            }
            WP_CLI::log( '  Deleted ' . count( $demo_user_ids ) . ' demo users.' );
        }

        // 3. Delete demo instruments (identified by name prefix).
        $wpdb->query( "DELETE FROM {$wpdb->prefix}hl_instrument WHERE name LIKE 'Demo %'" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}hl_teacher_assessment_instrument WHERE instrument_key = 'b2e_self_assessment'" );
        WP_CLI::log( '  Deleted demo instruments.' );

        // 4. Delete children for demo schools.
        // Find demo school IDs by name.
        $demo_school_ids = $wpdb->get_col(
            "SELECT orgunit_id FROM {$wpdb->prefix}hl_orgunit WHERE name LIKE 'Demo School%'"
        );
        if ( ! empty( $demo_school_ids ) ) {
            $in_schools = implode( ',', array_map( 'intval', $demo_school_ids ) );

            // Get classroom IDs.
            $demo_classroom_ids = $wpdb->get_col(
                "SELECT classroom_id FROM {$wpdb->prefix}hl_classroom WHERE school_id IN ({$in_schools})"
            );
            if ( ! empty( $demo_classroom_ids ) ) {
                $in_cls = implode( ',', array_map( 'intval', $demo_classroom_ids ) );
                $wpdb->query( "DELETE FROM {$wpdb->prefix}hl_child_classroom_current WHERE classroom_id IN ({$in_cls})" );
                $wpdb->query( "DELETE FROM {$wpdb->prefix}hl_child_classroom_history WHERE classroom_id IN ({$in_cls})" );
            }
            $wpdb->query( "DELETE FROM {$wpdb->prefix}hl_child WHERE school_id IN ({$in_schools})" );
            $wpdb->query( "DELETE FROM {$wpdb->prefix}hl_classroom WHERE school_id IN ({$in_schools})" );
            WP_CLI::log( '  Deleted demo children and classrooms.' );
        }

        // 5. Delete demo orgunits.
        $wpdb->query( "DELETE FROM {$wpdb->prefix}hl_orgunit WHERE name LIKE 'Demo %'" );
        WP_CLI::log( '  Deleted demo org units.' );

        // 6. Clean up audit log entries for the demo track.
        if ( $track_id ) {
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}hl_audit_log WHERE track_id = %d", $track_id ) );
            WP_CLI::log( '  Deleted demo audit log entries.' );
        }
    }

    // ------------------------------------------------------------------
    // Step 1: Org Structure
    // ------------------------------------------------------------------

    /**
     * Seed org units: 1 district, 2 schools.
     *
     * @return array [district_id, school_a_id, school_b_id]
     */
    private function seed_orgunits() {
        $repo = new HL_OrgUnit_Repository();

        $district_id = $repo->create( array(
            'name'        => 'Demo District',
            'orgunit_type' => 'district',
        ) );

        $school_a_id = $repo->create( array(
            'name'             => 'Demo School A',
            'orgunit_type'     => 'school',
            'parent_orgunit_id' => $district_id,
        ) );

        $school_b_id = $repo->create( array(
            'name'             => 'Demo School B',
            'orgunit_type'     => 'school',
            'parent_orgunit_id' => $district_id,
        ) );

        WP_CLI::log( "  [1/17] Org units created: district={$district_id}, schools={$school_a_id},{$school_b_id}" );

        return array( $district_id, $school_a_id, $school_b_id );
    }

    // ------------------------------------------------------------------
    // Step 2: Track
    // ------------------------------------------------------------------

    /**
     * Seed track and link to schools.
     *
     * @param int $district_id  District orgunit ID.
     * @param int $school_a_id  School A orgunit ID.
     * @param int $school_b_id  School B orgunit ID.
     * @return int Track ID.
     */
    private function seed_track( $district_id, $school_a_id, $school_b_id ) {
        global $wpdb;

        $repo = new HL_Track_Repository();

        $track_id = $repo->create( array(
            'track_name' => 'Demo Track 2026',
            'track_code' => self::DEMO_TRACK_CODE,
            'district_id' => $district_id,
            'status'      => 'active',
            'start_date'  => '2026-01-01',
            'end_date'    => '2026-12-31',
        ) );

        // Link track to both schools.
        $wpdb->insert( $wpdb->prefix . 'hl_track_school', array(
            'track_id' => $track_id,
            'school_id' => $school_a_id,
        ) );
        $wpdb->insert( $wpdb->prefix . 'hl_track_school', array(
            'track_id' => $track_id,
            'school_id' => $school_b_id,
        ) );

        WP_CLI::log( "  [2/17] Track created: id={$track_id}, code=" . self::DEMO_TRACK_CODE );

        return $track_id;
    }

    // ------------------------------------------------------------------
    // Step 3: Classrooms
    // ------------------------------------------------------------------

    /**
     * Seed 4 classrooms: 2 per school.
     *
     * @param int $school_a_id School A.
     * @param int $school_b_id School B.
     * @return array Keyed classroom data.
     */
    private function seed_classrooms( $school_a_id, $school_b_id ) {
        $svc = new HL_Classroom_Service();

        $classrooms = array();

        $defs = array(
            array( 'classroom_name' => 'Infant Room',    'school_id' => $school_a_id, 'age_band' => 'infant' ),
            array( 'classroom_name' => 'Toddler Room',   'school_id' => $school_a_id, 'age_band' => 'toddler' ),
            array( 'classroom_name' => 'Preschool Room',  'school_id' => $school_b_id, 'age_band' => 'preschool' ),
            array( 'classroom_name' => 'Mixed Age Room',  'school_id' => $school_b_id, 'age_band' => 'mixed' ),
        );

        foreach ( $defs as $def ) {
            $id = $svc->create_classroom( $def );
            if ( is_wp_error( $id ) ) {
                WP_CLI::warning( 'Classroom creation error: ' . $id->get_error_message() );
                continue;
            }
            $classrooms[] = array_merge( $def, array( 'classroom_id' => $id ) );
        }

        WP_CLI::log( '  [3/17] Classrooms created: ' . count( $classrooms ) );

        return $classrooms;
    }

    // ------------------------------------------------------------------
    // Step 4: Instruments
    // ------------------------------------------------------------------

    /**
     * Seed child assessment instruments.
     *
     * @return array Keyed by age band: 'infant' => id, etc.
     */
    private function seed_instruments() {
        global $wpdb;

        $types = array(
            'infant'    => array(
                'name'  => 'Demo Infant Assessment',
                'type'  => 'children_infant',
            ),
            'toddler'  => array(
                'name'  => 'Demo Toddler Assessment',
                'type'  => 'children_toddler',
            ),
            'preschool' => array(
                'name'  => 'Demo Preschool Assessment',
                'type'  => 'children_preschool',
            ),
        );

        $sample_questions = wp_json_encode( array(
            array(
                'question_id' => 'q1',
                'type'        => 'likert',
                'prompt_text' => 'Child demonstrates age-appropriate social skills',
                'required'    => true,
                'allowed_values' => array( '1', '2', '3', '4', '5' ),
            ),
            array(
                'question_id' => 'q2',
                'type'        => 'text',
                'prompt_text' => 'Describe the child\'s language development',
                'required'    => true,
            ),
            array(
                'question_id' => 'q3',
                'type'        => 'number',
                'prompt_text' => 'Number of peer interactions observed (15 min sample)',
                'required'    => false,
            ),
            array(
                'question_id' => 'q4',
                'type'        => 'single_select',
                'prompt_text' => 'Primary learning style observed',
                'required'    => true,
                'allowed_values' => array( 'Visual', 'Auditory', 'Kinesthetic', 'Mixed' ),
            ),
        ) );

        $instruments = array();
        foreach ( $types as $band => $info ) {
            $wpdb->insert( $wpdb->prefix . 'hl_instrument', array(
                'instrument_uuid' => wp_generate_uuid4(),
                'name'            => $info['name'],
                'instrument_type' => $info['type'],
                'version'         => '1.0',
                'questions'       => $sample_questions,
                'effective_from'  => '2026-01-01',
            ) );
            $instruments[ $band ] = $wpdb->insert_id;
        }

        // B2E Teacher Self-Assessment instrument — exact items from assessment docs.
        $b2e_sections = wp_json_encode( self::get_b2e_instrument_sections() );
        $b2e_scale_labels = wp_json_encode( self::get_b2e_instrument_scale_labels() );

        $wpdb->insert( $wpdb->prefix . 'hl_teacher_assessment_instrument', array(
            'instrument_name'    => 'B2E Teacher Self-Assessment',
            'instrument_key'     => 'b2e_self_assessment',
            'instrument_version' => '1.0',
            'sections'           => $b2e_sections,
            'scale_labels'       => $b2e_scale_labels,
            'status'             => 'active',
            'created_at'         => current_time( 'mysql' ),
        ) );
        $instruments['teacher_b2e'] = $wpdb->insert_id;

        WP_CLI::log( '  [4/17] Instruments created: ' . count( $instruments ) );

        return $instruments;
    }

    // ------------------------------------------------------------------
    // Step 5: WP Users
    // ------------------------------------------------------------------

    /**
     * Seed WP users.
     *
     * @return array Keyed arrays of user_ids.
     */
    private function seed_users() {
        $users = array(
            'teachers'        => array(),
            'mentors'         => array(),
            'school_leaders'  => array(),
            'district_leader' => null,
            'coach'           => null,
        );

        // 10 teachers.
        for ( $i = 1; $i <= 10; $i++ ) {
            $num   = str_pad( $i, 2, '0', STR_PAD_LEFT );
            $email = "demo-teacher-{$num}@" . self::DEMO_EMAIL_DOMAIN;
            $uid   = $this->create_demo_user( $email, "Demo Teacher {$num}", 'subscriber' );
            $users['teachers'][] = $uid;
        }

        // 2 mentors.
        for ( $i = 1; $i <= 2; $i++ ) {
            $email = "demo-mentor-{$i}@" . self::DEMO_EMAIL_DOMAIN;
            $uid   = $this->create_demo_user( $email, "Demo Mentor {$i}", 'subscriber' );
            $users['mentors'][] = $uid;
        }

        // 2 school leaders.
        for ( $i = 1; $i <= 2; $i++ ) {
            $email = "demo-schoolleader-{$i}@" . self::DEMO_EMAIL_DOMAIN;
            $uid   = $this->create_demo_user( $email, "Demo School Leader {$i}", 'subscriber' );
            $users['school_leaders'][] = $uid;
        }

        // 1 district leader.
        $email = 'demo-districtleader@' . self::DEMO_EMAIL_DOMAIN;
        $users['district_leader'] = $this->create_demo_user( $email, 'Demo District Leader', 'subscriber' );

        // 1 coach (not enrolled).
        $email = 'demo-coach@' . self::DEMO_EMAIL_DOMAIN;
        $users['coach'] = $this->create_demo_user( $email, 'Demo Coach', 'coach' );

        $total = count( $users['teachers'] ) + count( $users['mentors'] )
               + count( $users['school_leaders'] ) + 2; // +district_leader +coach
        WP_CLI::log( "  [5/17] WP users created: {$total}" );

        return $users;
    }

    /**
     * Create a single demo WP user.
     *
     * @param string $email    Email address.
     * @param string $display  Display name.
     * @param string $role     WP role.
     * @return int User ID.
     */
    private function create_demo_user( $email, $display, $role ) {
        $parts    = explode( '@', $email );
        $username = $parts[0];

        $user_id = wp_insert_user( array(
            'user_login'   => $username,
            'user_email'   => $email,
            'user_pass'    => wp_generate_password( 24 ),
            'display_name' => $display,
            'first_name'   => explode( ' ', $display )[1] ?? $display,
            'last_name'    => implode( ' ', array_slice( explode( ' ', $display ), 2 ) ),
            'role'         => $role,
        ) );

        if ( is_wp_error( $user_id ) ) {
            WP_CLI::warning( "Could not create user {$email}: " . $user_id->get_error_message() );
            return 0;
        }

        update_user_meta( $user_id, self::DEMO_META_KEY, '1' );

        return $user_id;
    }

    // ------------------------------------------------------------------
    // Step 6: Enrollments
    // ------------------------------------------------------------------

    /**
     * Seed enrollments for all users except coach.
     *
     * @param array $users        User arrays.
     * @param int   $track_id    Track ID.
     * @param int   $school_a_id  School A.
     * @param int   $school_b_id  School B.
     * @param int   $district_id  District ID.
     * @return array Enrollment data.
     */
    private function seed_enrollments( $users, $track_id, $school_a_id, $school_b_id, $district_id ) {
        $repo = new HL_Enrollment_Repository();

        $enrollments = array(
            'teachers_a'     => array(), // Teachers at School A (indices 0-4).
            'teachers_b'     => array(), // Teachers at School B (indices 5-9).
            'mentors'        => array(),
            'school_leaders' => array(),
            'district_leader' => null,
            'all'            => array(),
        );

        // Teachers: first 5 to School A, next 5 to School B.
        foreach ( $users['teachers'] as $idx => $uid ) {
            $school = ( $idx < 5 ) ? $school_a_id : $school_b_id;
            $eid    = $repo->create( array(
                'user_id'   => $uid,
                'track_id' => $track_id,
                'roles'     => array( 'teacher' ),
                'status'    => 'active',
                'school_id' => $school,
                'district_id' => $district_id,
            ) );

            if ( $idx < 5 ) {
                $enrollments['teachers_a'][] = array( 'enrollment_id' => $eid, 'user_id' => $uid, 'school_id' => $school );
            } else {
                $enrollments['teachers_b'][] = array( 'enrollment_id' => $eid, 'user_id' => $uid, 'school_id' => $school );
            }
            $enrollments['all'][] = array( 'enrollment_id' => $eid, 'user_id' => $uid, 'role' => 'teacher' );
        }

        // Mentors: 1 per school.
        foreach ( $users['mentors'] as $idx => $uid ) {
            $school = ( $idx === 0 ) ? $school_a_id : $school_b_id;
            $eid    = $repo->create( array(
                'user_id'   => $uid,
                'track_id' => $track_id,
                'roles'     => array( 'mentor' ),
                'status'    => 'active',
                'school_id' => $school,
                'district_id' => $district_id,
            ) );
            $enrollments['mentors'][] = array( 'enrollment_id' => $eid, 'user_id' => $uid, 'school_id' => $school );
            $enrollments['all'][]     = array( 'enrollment_id' => $eid, 'user_id' => $uid, 'role' => 'mentor' );
        }

        // School leaders: 1 per school.
        foreach ( $users['school_leaders'] as $idx => $uid ) {
            $school = ( $idx === 0 ) ? $school_a_id : $school_b_id;
            $eid    = $repo->create( array(
                'user_id'   => $uid,
                'track_id' => $track_id,
                'roles'     => array( 'school_leader' ),
                'status'    => 'active',
                'school_id' => $school,
                'district_id' => $district_id,
            ) );
            $enrollments['school_leaders'][] = array( 'enrollment_id' => $eid, 'user_id' => $uid, 'school_id' => $school );
            $enrollments['all'][]            = array( 'enrollment_id' => $eid, 'user_id' => $uid, 'role' => 'school_leader' );
        }

        // District leader.
        $uid = $users['district_leader'];
        $eid = $repo->create( array(
            'user_id'   => $uid,
            'track_id' => $track_id,
            'roles'     => array( 'district_leader' ),
            'status'    => 'active',
            'district_id' => $district_id,
        ) );
        $enrollments['district_leader'] = array( 'enrollment_id' => $eid, 'user_id' => $uid );
        $enrollments['all'][]           = array( 'enrollment_id' => $eid, 'user_id' => $uid, 'role' => 'district_leader' );

        WP_CLI::log( '  [6/17] Enrollments created: ' . count( $enrollments['all'] ) );

        return $enrollments;
    }

    // ------------------------------------------------------------------
    // Step 7: Teams
    // ------------------------------------------------------------------

    /**
     * Seed teams with mentor + teacher members.
     *
     * @param int   $track_id    Track ID.
     * @param int   $school_a_id  School A.
     * @param int   $school_b_id  School B.
     * @param array $enrollments  Enrollment data.
     * @return array Team IDs: ['team_a' => id, 'team_b' => id].
     */
    private function seed_teams( $track_id, $school_a_id, $school_b_id, $enrollments ) {
        $svc = new HL_Team_Service();

        $team_ids = array( 'team_a' => 0, 'team_b' => 0 );

        // Team A at School A.
        $team_a = $svc->create_team( array(
            'team_name' => 'Demo Team A',
            'track_id' => $track_id,
            'school_id' => $school_a_id,
        ) );

        if ( ! is_wp_error( $team_a ) ) {
            $team_ids['team_a'] = $team_a;
            // Mentor 1.
            $svc->add_member( $team_a, $enrollments['mentors'][0]['enrollment_id'], 'mentor' );
            // 5 teachers from School A.
            foreach ( $enrollments['teachers_a'] as $t ) {
                $svc->add_member( $team_a, $t['enrollment_id'], 'member' );
            }
        }

        // Team B at School B.
        $team_b = $svc->create_team( array(
            'team_name' => 'Demo Team B',
            'track_id' => $track_id,
            'school_id' => $school_b_id,
        ) );

        if ( ! is_wp_error( $team_b ) ) {
            $team_ids['team_b'] = $team_b;
            // Mentor 2.
            $svc->add_member( $team_b, $enrollments['mentors'][1]['enrollment_id'], 'mentor' );
            // 5 teachers from School B.
            foreach ( $enrollments['teachers_b'] as $t ) {
                $svc->add_member( $team_b, $t['enrollment_id'], 'member' );
            }
        }

        $member_count = count( $enrollments['teachers_a'] ) + count( $enrollments['teachers_b'] ) + 2;
        WP_CLI::log( "  [7/17] Teams created: 2 (with {$member_count} memberships)" );

        return $team_ids;
    }

    // ------------------------------------------------------------------
    // Step 8: Teaching Assignments
    // ------------------------------------------------------------------

    /**
     * Seed teaching assignments: each teacher gets 1 classroom.
     *
     * @param array $enrollments Enrollment data.
     * @param array $classrooms  Classroom data.
     */
    private function seed_teaching_assignments( $enrollments, $classrooms ) {
        // Suppress auto-generation of child assessment instances during seeding.
        remove_all_actions( 'hl_core_teaching_assignment_changed' );

        $svc   = new HL_Classroom_Service();
        $count = 0;

        // School A teachers (5) split across 2 classrooms (indices 0,1).
        foreach ( $enrollments['teachers_a'] as $idx => $t ) {
            $cr_idx   = ( $idx < 3 ) ? 0 : 1; // 3 in first classroom, 2 in second.
            $is_lead  = ( $idx === 0 || $idx === 3 ) ? 1 : 0;
            $result   = $svc->create_teaching_assignment( array(
                'enrollment_id'  => $t['enrollment_id'],
                'classroom_id'   => $classrooms[ $cr_idx ]['classroom_id'],
                'is_lead_teacher' => $is_lead,
            ) );
            if ( ! is_wp_error( $result ) ) {
                $count++;
            }
        }

        // School B teachers (5) split across 2 classrooms (indices 2,3).
        foreach ( $enrollments['teachers_b'] as $idx => $t ) {
            $cr_idx   = ( $idx < 3 ) ? 2 : 3;
            $is_lead  = ( $idx === 0 || $idx === 3 ) ? 1 : 0;
            $result   = $svc->create_teaching_assignment( array(
                'enrollment_id'  => $t['enrollment_id'],
                'classroom_id'   => $classrooms[ $cr_idx ]['classroom_id'],
                'is_lead_teacher' => $is_lead,
            ) );
            if ( ! is_wp_error( $result ) ) {
                $count++;
            }
        }

        WP_CLI::log( "  [8/17] Teaching assignments created: {$count}" );
    }

    // ------------------------------------------------------------------
    // Step 9: Children
    // ------------------------------------------------------------------

    /**
     * Seed children: 6-7 per classroom.
     *
     * @param array $classrooms  Classroom data.
     * @param int   $school_a_id School A.
     * @param int   $school_b_id School B.
     */
    private function seed_children( $classrooms, $school_a_id, $school_b_id ) {
        $repo = new HL_Child_Repository();
        $svc  = new HL_Classroom_Service();

        $first_names = array( 'Emma', 'Liam', 'Olivia', 'Noah', 'Ava', 'Elijah', 'Sophia', 'James' );
        $last_names  = array( 'Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller', 'Davis' );

        $total = 0;
        foreach ( $classrooms as $cr ) {
            $school_id = $cr['school_id'];
            $child_count = ( $cr['age_band'] === 'infant' ) ? 5 : 7; // Fewer infants.

            for ( $i = 0; $i < $child_count; $i++ ) {
                // Generate age-appropriate DOB.
                $age_years = $this->get_age_for_band( $cr['age_band'] );
                $dob = gmdate( 'Y-m-d', strtotime( "-{$age_years} years -" . wp_rand( 0, 364 ) . ' days' ) );

                $child_id = $repo->create( array(
                    'first_name' => $first_names[ $i % count( $first_names ) ],
                    'last_name'  => $last_names[ ( $i + $total ) % count( $last_names ) ],
                    'dob'        => $dob,
                    'school_id'  => $school_id,
                ) );

                if ( $child_id ) {
                    $svc->assign_child_to_classroom( $child_id, $cr['classroom_id'], 'Demo seed initial assignment' );
                    $total++;
                }
            }
        }

        WP_CLI::log( "  [9/17] Children created and assigned: {$total}" );
    }

    /**
     * Get a reasonable age in years for the given age band.
     *
     * @param string $band Age band.
     * @return int Age in years.
     */
    private function get_age_for_band( $band ) {
        switch ( $band ) {
            case 'infant':
                return wp_rand( 0, 1 );
            case 'toddler':
                return wp_rand( 1, 2 );
            case 'preschool':
                return wp_rand( 3, 5 );
            case 'mixed':
            default:
                return wp_rand( 1, 4 );
        }
    }

    // ------------------------------------------------------------------
    // Step 10: Pathways & Activities
    // ------------------------------------------------------------------

    /**
     * Seed pathways and activities.
     *
     * @param int   $track_id   Track ID.
     * @param array $instruments Instrument IDs keyed by age band.
     * @return array Pathway data with activity IDs.
     */
    private function seed_pathways( $track_id, $instruments ) {
        $svc = new HL_Pathway_Service();

        // --- Teacher Pathway ---
        $tp_id = $svc->create_pathway( array(
            'pathway_name' => 'Teacher Pathway',
            'track_id'    => $track_id,
            'target_roles' => array( 'teacher' ),
            'active_status' => 1,
        ) );

        $teacher_activities = array();

        // 1. LearnDash Course.
        $a_id = $svc->create_activity( array(
            'title'         => 'Foundations of Early Learning',
            'pathway_id'    => $tp_id,
            'track_id'     => $track_id,
            'activity_type' => 'learndash_course',
            'weight'        => 2.0,
            'ordering_hint' => 1,
            'external_ref'  => wp_json_encode( array( 'course_id' => 99901 ) ),
        ) );
        $teacher_activities['ld_course'] = $a_id;

        // 2. Pre Self-Assessment (custom instrument).
        $a_id = $svc->create_activity( array(
            'title'         => 'Pre Self-Assessment',
            'pathway_id'    => $tp_id,
            'track_id'     => $track_id,
            'activity_type' => 'teacher_self_assessment',
            'weight'        => 1.0,
            'ordering_hint' => 2,
            'external_ref'  => wp_json_encode( array(
                'teacher_instrument_id' => $instruments['teacher_b2e'],
                'phase'                 => 'pre',
            ) ),
        ) );
        $teacher_activities['pre_self'] = $a_id;

        // 3. Post Self-Assessment (custom instrument).
        $a_id = $svc->create_activity( array(
            'title'         => 'Post Self-Assessment',
            'pathway_id'    => $tp_id,
            'track_id'     => $track_id,
            'activity_type' => 'teacher_self_assessment',
            'weight'        => 1.0,
            'ordering_hint' => 3,
            'external_ref'  => wp_json_encode( array(
                'teacher_instrument_id' => $instruments['teacher_b2e'],
                'phase'                 => 'post',
            ) ),
        ) );
        $teacher_activities['post_self'] = $a_id;

        // 4. Child Assessment — use the infant instrument as the primary link.
        $a_id = $svc->create_activity( array(
            'title'         => 'Child Assessment',
            'pathway_id'    => $tp_id,
            'track_id'     => $track_id,
            'activity_type' => 'child_assessment',
            'weight'        => 2.0,
            'ordering_hint' => 4,
            'external_ref'  => wp_json_encode( array( 'instrument_id' => $instruments['infant'] ) ),
        ) );
        $teacher_activities['children'] = $a_id;

        // 5. Coaching Attendance.
        $a_id = $svc->create_activity( array(
            'title'         => 'Coaching Attendance',
            'pathway_id'    => $tp_id,
            'track_id'     => $track_id,
            'activity_type' => 'coaching_session_attendance',
            'weight'        => 1.0,
            'ordering_hint' => 5,
            'external_ref'  => wp_json_encode( (object) array() ),
        ) );
        $teacher_activities['coaching'] = $a_id;

        // --- Mentor Pathway ---
        $mp_id = $svc->create_pathway( array(
            'pathway_name' => 'Mentor Pathway',
            'track_id'    => $track_id,
            'target_roles' => array( 'mentor' ),
            'active_status' => 1,
        ) );

        $mentor_activities = array();

        // 1. LearnDash Course.
        $a_id = $svc->create_activity( array(
            'title'         => 'Mentor Training Course',
            'pathway_id'    => $mp_id,
            'track_id'     => $track_id,
            'activity_type' => 'learndash_course',
            'weight'        => 2.0,
            'ordering_hint' => 1,
            'external_ref'  => wp_json_encode( array( 'course_id' => 99902 ) ),
        ) );
        $mentor_activities['ld_course'] = $a_id;

        // 2. Observation.
        $a_id = $svc->create_activity( array(
            'title'         => 'Teacher Observations',
            'pathway_id'    => $mp_id,
            'track_id'     => $track_id,
            'activity_type' => 'observation',
            'weight'        => 1.0,
            'ordering_hint' => 2,
            'external_ref'  => wp_json_encode( array(
                'form_plugin'    => 'jetformbuilder',
                'form_id'        => 99903,
                'required_count' => 2,
            ) ),
        ) );
        $mentor_activities['observation'] = $a_id;

        $t_act_count = count( $teacher_activities );
        $m_act_count = count( $mentor_activities );
        WP_CLI::log( "  [10/17] Pathways created: 2 (teacher={$t_act_count} activities, mentor={$m_act_count} activities)" );

        return array(
            'teacher_pathway_id'  => $tp_id,
            'mentor_pathway_id'   => $mp_id,
            'teacher_activities'  => $teacher_activities,
            'mentor_activities'   => $mentor_activities,
        );
    }

    // ------------------------------------------------------------------
    // Step 11: Assign Pathways to Enrollments
    // ------------------------------------------------------------------

    /**
     * Update enrollment records with the correct pathway.
     *
     * @param array $enrollments Enrollment data.
     * @param array $pathways    Pathway data.
     */
    private function assign_pathways( $enrollments, $pathways ) {
        $repo = new HL_Enrollment_Repository();

        $count = 0;
        foreach ( $enrollments['all'] as $e ) {
            $pathway_id = null;
            if ( $e['role'] === 'teacher' ) {
                $pathway_id = $pathways['teacher_pathway_id'];
            } elseif ( $e['role'] === 'mentor' ) {
                $pathway_id = $pathways['mentor_pathway_id'];
            }
            // School leaders and district leaders don't have pathways.
            if ( $pathway_id ) {
                $repo->update( $e['enrollment_id'], array(
                    'assigned_pathway_id' => $pathway_id,
                ) );
                $count++;
            }
        }

        WP_CLI::log( "  [11/17] Pathway assignments updated: {$count}" );
    }

    // ------------------------------------------------------------------
    // Step 12: Prereq Rule
    // ------------------------------------------------------------------

    /**
     * Seed prerequisite rules: post_self requires pre_self.
     *
     * @param array $pathways Pathway data.
     */
    private function seed_prereq_rules( $pathways ) {
        global $wpdb;

        $ta        = $pathways['teacher_activities'];
        $post_self = $ta['post_self'];
        $pre_self  = $ta['pre_self'];
        $ld_course = $ta['ld_course'];
        $children  = $ta['children'];
        $coaching  = $ta['coaching'];

        // 1. ALL_OF: Post Self-Assessment requires Pre Self-Assessment.
        $wpdb->insert( $wpdb->prefix . 'hl_activity_prereq_group', array(
            'activity_id' => $post_self,
            'prereq_type' => 'all_of',
        ) );
        $group_id = $wpdb->insert_id;

        $wpdb->insert( $wpdb->prefix . 'hl_activity_prereq_item', array(
            'group_id'                 => $group_id,
            'prerequisite_activity_id' => $pre_self,
        ) );

        // 2. ANY_OF: Child Assessment requires either LD course OR Pre Self-Assessment.
        $wpdb->insert( $wpdb->prefix . 'hl_activity_prereq_group', array(
            'activity_id' => $children,
            'prereq_type' => 'any_of',
        ) );
        $group_id = $wpdb->insert_id;

        $wpdb->insert( $wpdb->prefix . 'hl_activity_prereq_item', array(
            'group_id'                 => $group_id,
            'prerequisite_activity_id' => $ld_course,
        ) );
        $wpdb->insert( $wpdb->prefix . 'hl_activity_prereq_item', array(
            'group_id'                 => $group_id,
            'prerequisite_activity_id' => $pre_self,
        ) );

        // 3. N_OF_M: Coaching Attendance requires 2 of 3 (LD course, Pre Self, Children).
        $wpdb->insert( $wpdb->prefix . 'hl_activity_prereq_group', array(
            'activity_id' => $coaching,
            'prereq_type' => 'n_of_m',
            'n_required'  => 2,
        ) );
        $group_id = $wpdb->insert_id;

        $wpdb->insert( $wpdb->prefix . 'hl_activity_prereq_item', array(
            'group_id'                 => $group_id,
            'prerequisite_activity_id' => $ld_course,
        ) );
        $wpdb->insert( $wpdb->prefix . 'hl_activity_prereq_item', array(
            'group_id'                 => $group_id,
            'prerequisite_activity_id' => $pre_self,
        ) );
        $wpdb->insert( $wpdb->prefix . 'hl_activity_prereq_item', array(
            'group_id'                 => $group_id,
            'prerequisite_activity_id' => $children,
        ) );

        WP_CLI::log( '  [12/17] Prereq rules created: ALL_OF (Post Self <- Pre Self), ANY_OF (Children <- LD|Pre), N_OF_M (Coaching <- 2 of 3)' );
    }

    // ------------------------------------------------------------------
    // Step 13: Drip Rule
    // ------------------------------------------------------------------

    /**
     * Seed drip rules: post_self has a fixed date release 30 days ago.
     *
     * @param array $pathways Pathway data.
     */
    private function seed_drip_rules( $pathways ) {
        global $wpdb;

        $post_self = $pathways['teacher_activities']['post_self'];

        $wpdb->insert( $wpdb->prefix . 'hl_activity_drip_rule', array(
            'activity_id'     => $post_self,
            'drip_type'       => 'fixed_date',
            'release_at_date' => gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) ),
        ) );

        WP_CLI::log( '  [13/17] Drip rule created: Post Self-Assessment released 30 days ago' );
    }

    // ------------------------------------------------------------------
    // Step 14: Activity States (partial completion)
    // ------------------------------------------------------------------

    /**
     * Seed activity states for partial completion demonstration.
     *
     * @param array $enrollments Enrollment data.
     * @param array $pathways    Pathway data.
     */
    private function seed_activity_states( $enrollments, $pathways ) {
        global $wpdb;

        $ta   = $pathways['teacher_activities'];
        $ma   = $pathways['mentor_activities'];
        $now  = current_time( 'mysql', true );
        $count = 0;

        // Helper to insert an activity state.
        $insert_state = function( $enrollment_id, $activity_id, $percent, $status, $completed_at = null ) use ( $wpdb, $now, &$count ) {
            $wpdb->insert( $wpdb->prefix . 'hl_activity_state', array(
                'enrollment_id'     => $enrollment_id,
                'activity_id'       => $activity_id,
                'completion_percent' => $percent,
                'completion_status' => $status,
                'completed_at'      => $completed_at,
                'last_computed_at'  => $now,
            ) );
            $count++;
        };

        // Teachers 1-2 (index 0,1 in teachers_a): fully done with LD course + pre + post self.
        for ( $i = 0; $i < 2; $i++ ) {
            $eid = $enrollments['teachers_a'][ $i ]['enrollment_id'];
            $insert_state( $eid, $ta['ld_course'],  100, 'complete', $now );
            $insert_state( $eid, $ta['pre_self'],   100, 'complete', $now );
            $insert_state( $eid, $ta['post_self'],  100, 'complete', $now );
        }

        // Teachers 3-4 (index 2,3 in teachers_a): LD course + pre self done, post unlocked but not done.
        for ( $i = 2; $i < 4; $i++ ) {
            $eid = $enrollments['teachers_a'][ $i ]['enrollment_id'];
            $insert_state( $eid, $ta['ld_course'], 100, 'complete', $now );
            $insert_state( $eid, $ta['pre_self'],  100, 'complete', $now );
        }

        // Teacher 5 (index 4 in teachers_a): LD course 50%.
        $eid = $enrollments['teachers_a'][4]['enrollment_id'];
        $insert_state( $eid, $ta['ld_course'], 50, 'in_progress' );

        // Mentor 1: LD course complete.
        $eid = $enrollments['mentors'][0]['enrollment_id'];
        $insert_state( $eid, $ma['ld_course'], 100, 'complete', $now );

        WP_CLI::log( "  [14/17] Activity states created: {$count}" );
    }

    // ------------------------------------------------------------------
    // Step 15: Completion Rollups
    // ------------------------------------------------------------------

    /**
     * Compute rollups for enrollments that have activity states.
     *
     * @param array $enrollments Enrollment data.
     */
    private function seed_rollups( $enrollments ) {
        $reporting = HL_Reporting_Service::instance();
        $count     = 0;
        $errors    = 0;

        foreach ( $enrollments['all'] as $e ) {
            $result = $reporting->compute_rollups( $e['enrollment_id'] );
            if ( is_wp_error( $result ) ) {
                $errors++;
            } else {
                $count++;
            }
        }

        WP_CLI::log( "  [15/17] Completion rollups computed: {$count}" . ( $errors ? " ({$errors} errors)" : '' ) );
    }

    // ------------------------------------------------------------------
    // Step 16: Coach Assignments
    // ------------------------------------------------------------------

    /**
     * Seed coach assignments: school-level for both schools, team-level for Team A.
     *
     * @param int   $track_id   Track ID.
     * @param int   $school_a_id School A.
     * @param int   $school_b_id School B.
     * @param array $teams       Team IDs.
     * @param array $users       User data.
     */
    private function seed_coach_assignments( $track_id, $school_a_id, $school_b_id, $teams, $users ) {
        $service = new HL_Coach_Assignment_Service();
        $count   = 0;

        $coach_user_id = $users['coach'];
        if ( ! $coach_user_id ) {
            WP_CLI::warning( 'No coach user found, skipping coach assignments.' );
            return;
        }

        // School-level assignment for School A.
        $result = $service->assign_coach( array(
            'coach_user_id'  => $coach_user_id,
            'scope_type'     => 'school',
            'scope_id'       => $school_a_id,
            'track_id'      => $track_id,
            'effective_from' => '2026-01-01',
        ) );
        if ( ! is_wp_error( $result ) ) {
            $count++;
        }

        // School-level assignment for School B.
        $result = $service->assign_coach( array(
            'coach_user_id'  => $coach_user_id,
            'scope_type'     => 'school',
            'scope_id'       => $school_b_id,
            'track_id'      => $track_id,
            'effective_from' => '2026-01-01',
        ) );
        if ( ! is_wp_error( $result ) ) {
            $count++;
        }

        // Team-level assignment for Team A (overrides school default).
        if ( ! empty( $teams['team_a'] ) ) {
            $result = $service->assign_coach( array(
                'coach_user_id'  => $coach_user_id,
                'scope_type'     => 'team',
                'scope_id'       => $teams['team_a'],
                'track_id'      => $track_id,
                'effective_from' => '2026-01-15',
            ) );
            if ( ! is_wp_error( $result ) ) {
                $count++;
            }
        }

        WP_CLI::log( "  [16/17] Coach assignments created: {$count}" );
    }

    // ------------------------------------------------------------------
    // Step 17: Coaching Sessions
    // ------------------------------------------------------------------

    /**
     * Seed coaching sessions with the expanded schema.
     *
     * Creates sessions for the first 4 teachers:
     * - Teacher 1: attended session (past)
     * - Teacher 2: scheduled session (upcoming)
     * - Teacher 3: missed session (past)
     * - Teacher 4: cancelled + rescheduled session
     *
     * @param int   $track_id   Track ID.
     * @param array $enrollments Enrollment data.
     * @param array $users       User data.
     */
    private function seed_coaching_sessions( $track_id, $enrollments, $users ) {
        $service = new HL_Coaching_Service();
        $count   = 0;

        $coach_user_id = $users['coach'];
        if ( ! $coach_user_id ) {
            WP_CLI::warning( 'No coach user found, skipping coaching sessions.' );
            return;
        }

        // Teacher 1: attended session (past).
        if ( isset( $enrollments['teachers_a'][0] ) ) {
            $eid    = $enrollments['teachers_a'][0]['enrollment_id'];
            $result = $service->create_session( array(
                'track_id'            => $track_id,
                'mentor_enrollment_id' => $eid,
                'coach_user_id'        => $coach_user_id,
                'session_title'        => 'Coaching Session 1',
                'meeting_url'          => 'https://zoom.us/j/demo-1',
                'session_datetime'     => gmdate( 'Y-m-d H:i:s', strtotime( '-7 days 10:00' ) ),
            ) );
            if ( ! is_wp_error( $result ) ) {
                $service->transition_status( $result, 'attended' );
                $count++;
            }
        }

        // Teacher 2: scheduled session (upcoming).
        if ( isset( $enrollments['teachers_a'][1] ) ) {
            $eid    = $enrollments['teachers_a'][1]['enrollment_id'];
            $result = $service->create_session( array(
                'track_id'            => $track_id,
                'mentor_enrollment_id' => $eid,
                'coach_user_id'        => $coach_user_id,
                'session_title'        => 'Coaching Session 1',
                'meeting_url'          => 'https://zoom.us/j/demo-2',
                'session_datetime'     => gmdate( 'Y-m-d H:i:s', strtotime( '+7 days 14:00' ) ),
            ) );
            if ( ! is_wp_error( $result ) ) {
                $count++;
            }
        }

        // Teacher 3: missed session (past).
        if ( isset( $enrollments['teachers_a'][2] ) ) {
            $eid    = $enrollments['teachers_a'][2]['enrollment_id'];
            $result = $service->create_session( array(
                'track_id'            => $track_id,
                'mentor_enrollment_id' => $eid,
                'coach_user_id'        => $coach_user_id,
                'session_title'        => 'Coaching Session 1',
                'session_datetime'     => gmdate( 'Y-m-d H:i:s', strtotime( '-3 days 09:00' ) ),
            ) );
            if ( ! is_wp_error( $result ) ) {
                $service->transition_status( $result, 'missed' );
                $count++;
            }
        }

        // Teacher 4: cancelled session + rescheduled new one.
        if ( isset( $enrollments['teachers_a'][3] ) ) {
            $eid = $enrollments['teachers_a'][3]['enrollment_id'];

            // Original session (will be rescheduled).
            $orig = $service->create_session( array(
                'track_id'            => $track_id,
                'mentor_enrollment_id' => $eid,
                'coach_user_id'        => $coach_user_id,
                'session_title'        => 'Coaching Session 1',
                'session_datetime'     => gmdate( 'Y-m-d H:i:s', strtotime( '-2 days 11:00' ) ),
            ) );

            if ( ! is_wp_error( $orig ) ) {
                $count++;
                // Reschedule to a future date.
                $new_session = $service->reschedule_session(
                    $orig,
                    gmdate( 'Y-m-d H:i:s', strtotime( '+5 days 11:00' ) ),
                    'https://zoom.us/j/demo-4'
                );
                if ( ! is_wp_error( $new_session ) ) {
                    $count++;
                }
            }
        }

        // Teacher 5 from School B: upcoming session.
        if ( isset( $enrollments['teachers_b'][0] ) ) {
            $eid    = $enrollments['teachers_b'][0]['enrollment_id'];
            $result = $service->create_session( array(
                'track_id'            => $track_id,
                'mentor_enrollment_id' => $eid,
                'coach_user_id'        => $coach_user_id,
                'session_title'        => 'Coaching Session 1',
                'meeting_url'          => 'https://teams.microsoft.com/demo-5',
                'session_datetime'     => gmdate( 'Y-m-d H:i:s', strtotime( '+10 days 15:00' ) ),
            ) );
            if ( ! is_wp_error( $result ) ) {
                $count++;
            }
        }

        WP_CLI::log( "  [17/17] Coaching sessions created: {$count}" );
    }

    // ==========================================================================
    // B2E Teacher Self-Assessment Instrument Definition
    // ==========================================================================

    /**
     * Get the B2E Teacher Self-Assessment instrument sections.
     *
     * Exact item text from:
     *   B2E Teacher Self Assessment (Pre) 20260203.docx
     *   B2E Teacher Self Assessment (Post) 20260203.docx
     *
     * Shared by all seeders to ensure consistent instrument data.
     *
     * @return array
     */
    public static function get_b2e_instrument_sections() {
        return array(
            // Assessment 1/3 — Instructional Practices Self-Rating
            // PRE: single column. POST: dual column (Prior Assessment Cycle + Past Two Weeks).
            // 5-point scale: Almost Never(0), Rarely(1), Sometimes(2), Often(3), Almost Always(4)
            array(
                'section_key' => 'practices',
                'title'       => 'Assessment 1/3 — Instructional Practices Self-Rating',
                'description' => 'Please think about your typical practice on hard days (e.g., when children are dysregulated, transitions are difficult, or you feel stressed or overwhelmed). For each statement, rate how you have been typically responding over the past two weeks. There are no right or wrong answers. This assessment is for reflection and growth, not evaluation.',
                'type'        => 'likert',
                'scale_key'   => 'practices_5',
                'items'       => array(
                    array( 'key' => 'P1',  'text' => 'When I begin to feel stressed or frustrated, I notice early signs in my body or emotions (e.g., tension, raised voice, rushing).' ),
                    array( 'key' => 'P2',  'text' => 'When I notice myself becoming dysregulated, I use strategies (pause, breath, self-talk, stepping back) to stay calm before responding to children.' ),
                    array( 'key' => 'P3',  'text' => 'During emotionally charged moments, I speak to children in a calm tone and use controlled body language, even when I feel upset inside.' ),
                    array( 'key' => 'P4',  'text' => 'When a child is upset, I acknowledge or name the child\'s feelings before redirecting or problem-solving.' ),
                    array( 'key' => 'P5',  'text' => 'I actively support children to calm their bodies and emotions (e.g., breathing together, offering sensory tools, sitting close).' ),
                    array( 'key' => 'P6',  'text' => 'My responses to children\'s strong emotions or behaviors usually help the child calm, understand expectations, or re-engage positively.' ),
                    array( 'key' => 'P7',  'text' => 'I model empathy, flexible thinking, and respectful communication when challenges arise (e.g., narrating my thinking, naming feelings).' ),
                    array( 'key' => 'P8',  'text' => 'I ask developmentally appropriate questions that help children reflect on their feelings, actions, and choices.' ),
                    array( 'key' => 'P9',  'text' => 'I treat emotional situations (big feelings, mistakes, conflict) as opportunities to teach social and emotional skills.' ),
                    array( 'key' => 'P10', 'text' => 'When conflicts arise, I guide children to express needs, listen to others, and generate solutions instead of solving the problem for them.' ),
                    array( 'key' => 'P11', 'text' => 'When children\'s emotions or behaviors escalate, I feel able to slow the situation down and respond intentionally rather than reactively.' ),
                    array( 'key' => 'P12', 'text' => 'I give clear, developmentally appropriate instructions and adjust my support when children seem confused or overwhelmed.' ),
                    array( 'key' => 'P13', 'text' => 'I adjust expectations, strategies, or the environment to meet individual and group social, emotional, behavioral, and learning needs.' ),
                    array( 'key' => 'P14', 'text' => 'I maintain consistent routines and transitions that help children feel safe and know what to expect, even on busy or stressful days.' ),
                    array( 'key' => 'P15', 'text' => 'I regularly create opportunities for children to notice and share how they feel (e.g., morning check-ins, emotion charts, group discussions).' ),
                    array( 'key' => 'P16', 'text' => 'I intentionally integrate emotional intelligence skills into play, exploration, stories, and daily learning activities.' ),
                    array( 'key' => 'P17', 'text' => 'I feel able to respectfully discuss concerns or challenges with families, coworkers, or supervisors.' ),
                    array( 'key' => 'P18', 'text' => 'When I am unsure or struggling, I seek guidance or support from a trusted colleague or supervisor.' ),
                    array( 'key' => 'P19', 'text' => 'After challenging moments, I reflect on what happened and consider what I might try differently next time.' ),
                    array( 'key' => 'P20', 'text' => 'I feel confident in my abilities as an educator, generally satisfied with my work, and connected to its purpose--even when the job feels hard.' ),
                ),
            ),

            // Assessment 2/3 — Work Environment (Stress/Coping/Support/Satisfaction)
            // 0-10 scale with per-item anchor labels.
            array(
                'section_key' => 'wellbeing',
                'title'       => 'Assessment 2/3 — Work Environment',
                'description' => 'Thinking about the past two weeks, answer the following questions rating each item on a scale of 0 "not at all" to 10 "Very".',
                'type'        => 'scale',
                'scale_key'   => 'scale_0_10',
                'items'       => array(
                    array( 'key' => 'W1', 'text' => 'How stressful is your job?',                                        'left_anchor' => 'Not at all Stressful', 'right_anchor' => 'Very Stressful' ),
                    array( 'key' => 'W2', 'text' => 'How well are you coping with the stress of your job right now?',     'left_anchor' => 'Not Coping at all',    'right_anchor' => 'Coping Very Well' ),
                    array( 'key' => 'W3', 'text' => 'How supported do you feel in your job?',                             'left_anchor' => 'Not at all Supported', 'right_anchor' => 'Very Supported' ),
                    array( 'key' => 'W4', 'text' => 'How satisfied are you in your job?',                                 'left_anchor' => 'Not at all Satisfied', 'right_anchor' => 'Very Satisfied' ),
                ),
            ),

            // Assessment 3/3 — Emotion Regulation (Self-Report)
            // 7-point scale: Strongly Disagree(0) to Strongly Agree(6)
            array(
                'section_key' => 'self_regulation',
                'title'       => 'Assessment 3/3 — Emotional Self-Regulation',
                'description' => 'Mark the extent to which you agree or disagree with each of the statements.',
                'type'        => 'likert',
                'scale_key'   => 'likert_7',
                'items'       => array(
                    array( 'key' => 'SR1', 'text' => 'I am able to control my temper so that I can handle difficulties rationally.' ),
                    array( 'key' => 'SR2', 'text' => 'I am quite capable of controlling my own emotions.' ),
                    array( 'key' => 'SR3', 'text' => 'I can always calm down quickly when I am very angry.' ),
                    array( 'key' => 'SR4', 'text' => 'I have good control of my emotions.' ),
                ),
            ),
        );
    }

    /**
     * Get the B2E Teacher Self-Assessment scale labels.
     *
     * @return array
     */
    public static function get_b2e_instrument_scale_labels() {
        return array(
            'practices_5' => array( 'Almost Never', 'Rarely', 'Sometimes', 'Often', 'Almost Always' ),
            'likert_7'    => array( 'Strongly Disagree', 'Disagree', 'Slightly Disagree', 'Neither Agree nor Disagree', 'Slightly Agree', 'Agree', 'Strongly Agree' ),
            'scale_0_10'  => array( 'low' => '0', 'high' => '10' ),
        );
    }

    // ==========================================================================
    // Child Assessment Question Definitions (from 2026 B2E Child Assessment.docx)
    // ==========================================================================

    /**
     * Get the child assessment scale labels (shared across all age bands).
     * Values: Never(0), Rarely(1), Sometimes(2), Usually(3), Almost Always(4)
     *
     * @return array
     */
    public static function get_child_assessment_scale() {
        return array(
            0 => 'Never',
            1 => 'Rarely',
            2 => 'Sometimes',
            3 => 'Usually',
            4 => 'Almost Always',
        );
    }

    /**
     * Get the child assessment question and example behaviors per age band.
     *
     * Each age band has ONE question and example behaviors per scale point.
     *
     * @return array Keyed by age band.
     */
    public static function get_child_assessment_questions() {
        return array(
            'infant' => array(
                'question' => 'In the last month, how often did the infant notice and respond to their own feelings and those of others through things like facial expressions, body language, and social interactions?',
                'examples' => array(
                    0 => 'Never notices or responds when other children or caregivers are upset',
                    1 => 'Stops to look at another crying infant but rarely responds with concern before going back to what they were doing',
                    2 => 'Sometimes mirrors the emotions of others by smiling back at caregivers or looking concerned in response to other infants who are crying',
                    3 => 'Usually mirrors the emotions of caregivers and responds when other infants are upset by reaching arms in their direction',
                    4 => 'Almost always mirrors the emotions of other children and caregivers and attempts to comfort them by reaching out their arms or babbling/cooing',
                ),
            ),
            'toddler' => array(
                'question' => 'In the last month, how often did the toddler express their own feelings using body language or words, respond to other people\'s feelings, and interact with others socially?',
                'examples' => array(
                    0 => 'Never expresses their feelings with body language or words or responds to the feelings of others and stays quiet or expressionless instead',
                    1 => 'Rarely expresses their feelings with body language or words and hits or throws prolonged temper tantrums instead. Rarely responds to other children who are upset',
                    2 => 'Sometimes expresses their feelings with body language or words but throws temper tantrums and needs help from caregivers to calm down. Sometimes show concern if another child cries',
                    3 => 'Usually expresses their feelings with body language or words and recovers from temper tantrums with caregiver support. Notices when others are upset and tries to comfort them',
                    4 => 'Almost always expresses their feelings with body language or words, recovers quickly from temper tantrums with caregiver support, tries to comfort others, and actively joins in play',
                ),
            ),
            'preschool' => array(
                'question' => 'In the last month, how often did the child show that they understood, expressed, and managed their own emotions successfully, cares about other people\'s feelings, and tried to solve problems?',
                'examples' => array(
                    0 => 'Never uses words instead of actions (hitting) to express their feelings or calms down even with caregiver support. Never seems to pick up on or show concern for other people\'s feelings',
                    1 => 'Rarely uses words instead of actions to express their feelings or calms down without a lot of caregiver support. Rarely shows concern when friends are upset without guidance',
                    2 => 'Uses words to express their feelings and sometimes shares what caused them. Sometimes needs a lot of caregiver support to calm down, help others feel better, and solve social problems',
                    3 => 'Usually shares what caused their feelings, manages heightened emotions, notices what others are feeling, and tries to help them feel better or solve the problem with caregiver support',
                    4 => 'Almost always shares what caused their feelings, calms down with caregiver guidance, notices what others are feeling and tries to help them feel better or solve the problem with support',
                ),
            ),
            'k2' => array(
                'question' => 'In the last month, how often did the child show that they could manage their emotions, show empathy for others\' feelings, and solve social problems?',
                'examples' => array(
                    0 => 'Never talks about what they are feeling or finds strategies (deep breaths, physical tools) to calm down independently. Never considers other children\'s feelings and needs help with solving social problems',
                    1 => 'Rarely finds strategies to calm down independently and needs a caregiver to offer them choices. Rarely considers other children\'s feelings and needs help with solving social problems',
                    2 => 'Tries to calm down independently but sometimes needs help with finding strategies. Sometimes considers other children\'s feelings and compromises to solving social problems with guidance',
                    3 => 'Usually manages heightened emotions successfully using a variety of strategies. Considers other children\'s feelings and usually compromises to solve social problems',
                    4 => 'Almost always manages heightened emotions successfully using a variety of strategies, considers other children\'s feelings, and works with others to compromise and solve social problems',
                ),
            ),
        );
    }
}
