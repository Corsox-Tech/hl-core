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

    /** Demo cohort code used to identify seeded data. */
    const DEMO_COHORT_CODE = 'DEMO-2026';

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
        list( $district_id, $center_a_id, $center_b_id ) = $this->seed_orgunits();

        // Step 2: Cohort
        $cohort_id = $this->seed_cohort( $district_id, $center_a_id, $center_b_id );

        // Step 3: Classrooms
        $classrooms = $this->seed_classrooms( $center_a_id, $center_b_id );

        // Step 4: Instruments
        $instruments = $this->seed_instruments();

        // Step 5: WP Users
        $users = $this->seed_users();

        // Step 6: Enrollments
        $enrollments = $this->seed_enrollments( $users, $cohort_id, $center_a_id, $center_b_id, $district_id );

        // Step 7: Teams
        $this->seed_teams( $cohort_id, $center_a_id, $center_b_id, $enrollments );

        // Step 8: Teaching Assignments
        $this->seed_teaching_assignments( $enrollments, $classrooms );

        // Step 9: Children
        $this->seed_children( $classrooms, $center_a_id, $center_b_id );

        // Step 10: Pathways & Activities
        $pathways = $this->seed_pathways( $cohort_id, $instruments );

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

        WP_CLI::line( '' );
        WP_CLI::success( 'Demo data seeded successfully!' );
        WP_CLI::line( '' );
        WP_CLI::line( 'Summary:' );
        WP_CLI::line( "  Cohort:       {$cohort_id} (code: " . self::DEMO_COHORT_CODE . ')' );
        WP_CLI::line( "  District:     {$district_id}" );
        WP_CLI::line( "  Centers:      {$center_a_id}, {$center_b_id}" );
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
                "SELECT cohort_id FROM {$wpdb->prefix}hl_cohort WHERE cohort_code = %s LIMIT 1",
                self::DEMO_COHORT_CODE
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

        // 1. Find demo cohort.
        $cohort_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT cohort_id FROM {$wpdb->prefix}hl_cohort WHERE cohort_code = %s LIMIT 1",
                self::DEMO_COHORT_CODE
            )
        );

        if ( $cohort_id ) {
            // Delete dependent data in reverse dependency order.

            // Activity states & rollups via enrollments.
            $enrollment_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT enrollment_id FROM {$wpdb->prefix}hl_enrollment WHERE cohort_id = %d",
                    $cohort_id
                )
            );

            if ( ! empty( $enrollment_ids ) ) {
                $in_ids = implode( ',', array_map( 'intval', $enrollment_ids ) );

                $wpdb->query( "DELETE FROM {$wpdb->prefix}hl_completion_rollup WHERE enrollment_id IN ({$in_ids})" );
                $wpdb->query( "DELETE FROM {$wpdb->prefix}hl_activity_state WHERE enrollment_id IN ({$in_ids})" );
                $wpdb->query( "DELETE FROM {$wpdb->prefix}hl_activity_override WHERE enrollment_id IN ({$in_ids})" );
                $wpdb->query( "DELETE FROM {$wpdb->prefix}hl_team_membership WHERE enrollment_id IN ({$in_ids})" );
                $wpdb->query( "DELETE FROM {$wpdb->prefix}hl_teaching_assignment WHERE enrollment_id IN ({$in_ids})" );

                // Children assessment instances.
                $ca_ids = $wpdb->get_col(
                    "SELECT instance_id FROM {$wpdb->prefix}hl_children_assessment_instance WHERE enrollment_id IN ({$in_ids})"
                );
                if ( ! empty( $ca_ids ) ) {
                    $in_ca = implode( ',', array_map( 'intval', $ca_ids ) );
                    $wpdb->query( "DELETE FROM {$wpdb->prefix}hl_children_assessment_childrow WHERE instance_id IN ({$in_ca})" );
                }
                $wpdb->query( "DELETE FROM {$wpdb->prefix}hl_children_assessment_instance WHERE enrollment_id IN ({$in_ids})" );

                // Teacher assessment instances.
                $wpdb->query( "DELETE FROM {$wpdb->prefix}hl_teacher_assessment_instance WHERE enrollment_id IN ({$in_ids})" );

                // Observations.
                $wpdb->query( "DELETE FROM {$wpdb->prefix}hl_observation WHERE cohort_id = {$cohort_id}" );
            }

            // Activities and related rules.
            $activity_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT activity_id FROM {$wpdb->prefix}hl_activity WHERE cohort_id = %d",
                    $cohort_id
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
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}hl_activity WHERE cohort_id = %d", $cohort_id ) );

            // Pathways.
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}hl_pathway WHERE cohort_id = %d", $cohort_id ) );

            // Teams.
            $team_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT team_id FROM {$wpdb->prefix}hl_team WHERE cohort_id = %d",
                    $cohort_id
                )
            );
            if ( ! empty( $team_ids ) ) {
                $in_teams = implode( ',', array_map( 'intval', $team_ids ) );
                $wpdb->query( "DELETE FROM {$wpdb->prefix}hl_team_membership WHERE team_id IN ({$in_teams})" );
            }
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}hl_team WHERE cohort_id = %d", $cohort_id ) );

            // Enrollments.
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}hl_enrollment WHERE cohort_id = %d", $cohort_id ) );

            // Cohort-center links.
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}hl_cohort_center WHERE cohort_id = %d", $cohort_id ) );

            // Coaching sessions.
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}hl_coaching_session WHERE cohort_id = %d", $cohort_id ) );

            // Cohort.
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}hl_cohort WHERE cohort_id = %d", $cohort_id ) );

            WP_CLI::log( "  Deleted cohort {$cohort_id} and all related records." );
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
        WP_CLI::log( '  Deleted demo instruments.' );

        // 4. Delete children for demo centers.
        // Find demo center IDs by name.
        $demo_center_ids = $wpdb->get_col(
            "SELECT orgunit_id FROM {$wpdb->prefix}hl_orgunit WHERE name LIKE 'Demo Center%'"
        );
        if ( ! empty( $demo_center_ids ) ) {
            $in_centers = implode( ',', array_map( 'intval', $demo_center_ids ) );

            // Get classroom IDs.
            $demo_classroom_ids = $wpdb->get_col(
                "SELECT classroom_id FROM {$wpdb->prefix}hl_classroom WHERE center_id IN ({$in_centers})"
            );
            if ( ! empty( $demo_classroom_ids ) ) {
                $in_cls = implode( ',', array_map( 'intval', $demo_classroom_ids ) );
                $wpdb->query( "DELETE FROM {$wpdb->prefix}hl_child_classroom_current WHERE classroom_id IN ({$in_cls})" );
                $wpdb->query( "DELETE FROM {$wpdb->prefix}hl_child_classroom_history WHERE classroom_id IN ({$in_cls})" );
            }
            $wpdb->query( "DELETE FROM {$wpdb->prefix}hl_child WHERE center_id IN ({$in_centers})" );
            $wpdb->query( "DELETE FROM {$wpdb->prefix}hl_classroom WHERE center_id IN ({$in_centers})" );
            WP_CLI::log( '  Deleted demo children and classrooms.' );
        }

        // 5. Delete demo orgunits.
        $wpdb->query( "DELETE FROM {$wpdb->prefix}hl_orgunit WHERE name LIKE 'Demo %'" );
        WP_CLI::log( '  Deleted demo org units.' );

        // 6. Clean up audit log entries for the demo cohort.
        if ( $cohort_id ) {
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}hl_audit_log WHERE cohort_id = %d", $cohort_id ) );
            WP_CLI::log( '  Deleted demo audit log entries.' );
        }
    }

    // ------------------------------------------------------------------
    // Step 1: Org Structure
    // ------------------------------------------------------------------

    /**
     * Seed org units: 1 district, 2 centers.
     *
     * @return array [district_id, center_a_id, center_b_id]
     */
    private function seed_orgunits() {
        $repo = new HL_OrgUnit_Repository();

        $district_id = $repo->create( array(
            'name'        => 'Demo District',
            'orgunit_type' => 'district',
        ) );

        $center_a_id = $repo->create( array(
            'name'             => 'Demo Center A',
            'orgunit_type'     => 'center',
            'parent_orgunit_id' => $district_id,
        ) );

        $center_b_id = $repo->create( array(
            'name'             => 'Demo Center B',
            'orgunit_type'     => 'center',
            'parent_orgunit_id' => $district_id,
        ) );

        WP_CLI::log( "  [1/15] Org units created: district={$district_id}, centers={$center_a_id},{$center_b_id}" );

        return array( $district_id, $center_a_id, $center_b_id );
    }

    // ------------------------------------------------------------------
    // Step 2: Cohort
    // ------------------------------------------------------------------

    /**
     * Seed cohort and link to centers.
     *
     * @param int $district_id  District orgunit ID.
     * @param int $center_a_id  Center A orgunit ID.
     * @param int $center_b_id  Center B orgunit ID.
     * @return int Cohort ID.
     */
    private function seed_cohort( $district_id, $center_a_id, $center_b_id ) {
        global $wpdb;

        $repo = new HL_Cohort_Repository();

        $cohort_id = $repo->create( array(
            'cohort_name' => 'Demo Cohort 2026',
            'cohort_code' => self::DEMO_COHORT_CODE,
            'district_id' => $district_id,
            'status'      => 'active',
            'start_date'  => '2026-01-01',
            'end_date'    => '2026-12-31',
        ) );

        // Link cohort to both centers.
        $wpdb->insert( $wpdb->prefix . 'hl_cohort_center', array(
            'cohort_id' => $cohort_id,
            'center_id' => $center_a_id,
        ) );
        $wpdb->insert( $wpdb->prefix . 'hl_cohort_center', array(
            'cohort_id' => $cohort_id,
            'center_id' => $center_b_id,
        ) );

        WP_CLI::log( "  [2/15] Cohort created: id={$cohort_id}, code=" . self::DEMO_COHORT_CODE );

        return $cohort_id;
    }

    // ------------------------------------------------------------------
    // Step 3: Classrooms
    // ------------------------------------------------------------------

    /**
     * Seed 4 classrooms: 2 per center.
     *
     * @param int $center_a_id Center A.
     * @param int $center_b_id Center B.
     * @return array Keyed classroom data.
     */
    private function seed_classrooms( $center_a_id, $center_b_id ) {
        $svc = new HL_Classroom_Service();

        $classrooms = array();

        $defs = array(
            array( 'classroom_name' => 'Infant Room',    'center_id' => $center_a_id, 'age_band' => 'infant' ),
            array( 'classroom_name' => 'Toddler Room',   'center_id' => $center_a_id, 'age_band' => 'toddler' ),
            array( 'classroom_name' => 'Preschool Room',  'center_id' => $center_b_id, 'age_band' => 'preschool' ),
            array( 'classroom_name' => 'Mixed Age Room',  'center_id' => $center_b_id, 'age_band' => 'mixed' ),
        );

        foreach ( $defs as $def ) {
            $id = $svc->create_classroom( $def );
            if ( is_wp_error( $id ) ) {
                WP_CLI::warning( 'Classroom creation error: ' . $id->get_error_message() );
                continue;
            }
            $classrooms[] = array_merge( $def, array( 'classroom_id' => $id ) );
        }

        WP_CLI::log( '  [3/15] Classrooms created: ' . count( $classrooms ) );

        return $classrooms;
    }

    // ------------------------------------------------------------------
    // Step 4: Instruments
    // ------------------------------------------------------------------

    /**
     * Seed children assessment instruments.
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

        WP_CLI::log( '  [4/15] Instruments created: ' . count( $instruments ) );

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
            'center_leaders'  => array(),
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

        // 2 center leaders.
        for ( $i = 1; $i <= 2; $i++ ) {
            $email = "demo-centerleader-{$i}@" . self::DEMO_EMAIL_DOMAIN;
            $uid   = $this->create_demo_user( $email, "Demo Center Leader {$i}", 'subscriber' );
            $users['center_leaders'][] = $uid;
        }

        // 1 district leader.
        $email = 'demo-districtleader@' . self::DEMO_EMAIL_DOMAIN;
        $users['district_leader'] = $this->create_demo_user( $email, 'Demo District Leader', 'subscriber' );

        // 1 coach (not enrolled).
        $email = 'demo-coach@' . self::DEMO_EMAIL_DOMAIN;
        $users['coach'] = $this->create_demo_user( $email, 'Demo Coach', 'coach' );

        $total = count( $users['teachers'] ) + count( $users['mentors'] )
               + count( $users['center_leaders'] ) + 2; // +district_leader +coach
        WP_CLI::log( "  [5/15] WP users created: {$total}" );

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
     * @param int   $cohort_id    Cohort ID.
     * @param int   $center_a_id  Center A.
     * @param int   $center_b_id  Center B.
     * @param int   $district_id  District ID.
     * @return array Enrollment data.
     */
    private function seed_enrollments( $users, $cohort_id, $center_a_id, $center_b_id, $district_id ) {
        $repo = new HL_Enrollment_Repository();

        $enrollments = array(
            'teachers_a'     => array(), // Teachers at Center A (indices 0-4).
            'teachers_b'     => array(), // Teachers at Center B (indices 5-9).
            'mentors'        => array(),
            'center_leaders' => array(),
            'district_leader' => null,
            'all'            => array(),
        );

        // Teachers: first 5 to Center A, next 5 to Center B.
        foreach ( $users['teachers'] as $idx => $uid ) {
            $center = ( $idx < 5 ) ? $center_a_id : $center_b_id;
            $eid    = $repo->create( array(
                'user_id'   => $uid,
                'cohort_id' => $cohort_id,
                'roles'     => array( 'teacher' ),
                'status'    => 'active',
                'center_id' => $center,
                'district_id' => $district_id,
            ) );

            if ( $idx < 5 ) {
                $enrollments['teachers_a'][] = array( 'enrollment_id' => $eid, 'user_id' => $uid, 'center_id' => $center );
            } else {
                $enrollments['teachers_b'][] = array( 'enrollment_id' => $eid, 'user_id' => $uid, 'center_id' => $center );
            }
            $enrollments['all'][] = array( 'enrollment_id' => $eid, 'user_id' => $uid, 'role' => 'teacher' );
        }

        // Mentors: 1 per center.
        foreach ( $users['mentors'] as $idx => $uid ) {
            $center = ( $idx === 0 ) ? $center_a_id : $center_b_id;
            $eid    = $repo->create( array(
                'user_id'   => $uid,
                'cohort_id' => $cohort_id,
                'roles'     => array( 'mentor' ),
                'status'    => 'active',
                'center_id' => $center,
                'district_id' => $district_id,
            ) );
            $enrollments['mentors'][] = array( 'enrollment_id' => $eid, 'user_id' => $uid, 'center_id' => $center );
            $enrollments['all'][]     = array( 'enrollment_id' => $eid, 'user_id' => $uid, 'role' => 'mentor' );
        }

        // Center leaders: 1 per center.
        foreach ( $users['center_leaders'] as $idx => $uid ) {
            $center = ( $idx === 0 ) ? $center_a_id : $center_b_id;
            $eid    = $repo->create( array(
                'user_id'   => $uid,
                'cohort_id' => $cohort_id,
                'roles'     => array( 'center_leader' ),
                'status'    => 'active',
                'center_id' => $center,
                'district_id' => $district_id,
            ) );
            $enrollments['center_leaders'][] = array( 'enrollment_id' => $eid, 'user_id' => $uid, 'center_id' => $center );
            $enrollments['all'][]            = array( 'enrollment_id' => $eid, 'user_id' => $uid, 'role' => 'center_leader' );
        }

        // District leader.
        $uid = $users['district_leader'];
        $eid = $repo->create( array(
            'user_id'   => $uid,
            'cohort_id' => $cohort_id,
            'roles'     => array( 'district_leader' ),
            'status'    => 'active',
            'district_id' => $district_id,
        ) );
        $enrollments['district_leader'] = array( 'enrollment_id' => $eid, 'user_id' => $uid );
        $enrollments['all'][]           = array( 'enrollment_id' => $eid, 'user_id' => $uid, 'role' => 'district_leader' );

        WP_CLI::log( '  [6/15] Enrollments created: ' . count( $enrollments['all'] ) );

        return $enrollments;
    }

    // ------------------------------------------------------------------
    // Step 7: Teams
    // ------------------------------------------------------------------

    /**
     * Seed teams with mentor + teacher members.
     *
     * @param int   $cohort_id    Cohort.
     * @param int   $center_a_id  Center A.
     * @param int   $center_b_id  Center B.
     * @param array $enrollments  Enrollment data.
     */
    private function seed_teams( $cohort_id, $center_a_id, $center_b_id, $enrollments ) {
        $svc = new HL_Team_Service();

        // Team A at Center A.
        $team_a = $svc->create_team( array(
            'team_name' => 'Demo Team A',
            'cohort_id' => $cohort_id,
            'center_id' => $center_a_id,
        ) );

        if ( ! is_wp_error( $team_a ) ) {
            // Mentor 1.
            $svc->add_member( $team_a, $enrollments['mentors'][0]['enrollment_id'], 'mentor' );
            // 5 teachers from Center A.
            foreach ( $enrollments['teachers_a'] as $t ) {
                $svc->add_member( $team_a, $t['enrollment_id'], 'member' );
            }
        }

        // Team B at Center B.
        $team_b = $svc->create_team( array(
            'team_name' => 'Demo Team B',
            'cohort_id' => $cohort_id,
            'center_id' => $center_b_id,
        ) );

        if ( ! is_wp_error( $team_b ) ) {
            // Mentor 2.
            $svc->add_member( $team_b, $enrollments['mentors'][1]['enrollment_id'], 'mentor' );
            // 5 teachers from Center B.
            foreach ( $enrollments['teachers_b'] as $t ) {
                $svc->add_member( $team_b, $t['enrollment_id'], 'member' );
            }
        }

        $member_count = count( $enrollments['teachers_a'] ) + count( $enrollments['teachers_b'] ) + 2;
        WP_CLI::log( "  [7/15] Teams created: 2 (with {$member_count} memberships)" );
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
        $svc   = new HL_Classroom_Service();
        $count = 0;

        // Center A teachers (5) split across 2 classrooms (indices 0,1).
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

        // Center B teachers (5) split across 2 classrooms (indices 2,3).
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

        WP_CLI::log( "  [8/15] Teaching assignments created: {$count}" );
    }

    // ------------------------------------------------------------------
    // Step 9: Children
    // ------------------------------------------------------------------

    /**
     * Seed children: 6-7 per classroom.
     *
     * @param array $classrooms  Classroom data.
     * @param int   $center_a_id Center A.
     * @param int   $center_b_id Center B.
     */
    private function seed_children( $classrooms, $center_a_id, $center_b_id ) {
        $repo = new HL_Child_Repository();
        $svc  = new HL_Classroom_Service();

        $first_names = array( 'Emma', 'Liam', 'Olivia', 'Noah', 'Ava', 'Elijah', 'Sophia', 'James' );
        $last_names  = array( 'Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller', 'Davis' );

        $total = 0;
        foreach ( $classrooms as $cr ) {
            $center_id = $cr['center_id'];
            $child_count = ( $cr['age_band'] === 'infant' ) ? 5 : 7; // Fewer infants.

            for ( $i = 0; $i < $child_count; $i++ ) {
                // Generate age-appropriate DOB.
                $age_years = $this->get_age_for_band( $cr['age_band'] );
                $dob = gmdate( 'Y-m-d', strtotime( "-{$age_years} years -" . wp_rand( 0, 364 ) . ' days' ) );

                $child_id = $repo->create( array(
                    'first_name' => $first_names[ $i % count( $first_names ) ],
                    'last_name'  => $last_names[ ( $i + $total ) % count( $last_names ) ],
                    'dob'        => $dob,
                    'center_id'  => $center_id,
                ) );

                if ( $child_id ) {
                    $svc->assign_child_to_classroom( $child_id, $cr['classroom_id'], 'Demo seed initial assignment' );
                    $total++;
                }
            }
        }

        WP_CLI::log( "  [9/15] Children created and assigned: {$total}" );
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
     * @param int   $cohort_id   Cohort.
     * @param array $instruments Instrument IDs keyed by age band.
     * @return array Pathway data with activity IDs.
     */
    private function seed_pathways( $cohort_id, $instruments ) {
        $svc = new HL_Pathway_Service();

        // --- Teacher Pathway ---
        $tp_id = $svc->create_pathway( array(
            'pathway_name' => 'Teacher Pathway',
            'cohort_id'    => $cohort_id,
            'target_roles' => array( 'teacher' ),
            'active_status' => 1,
        ) );

        $teacher_activities = array();

        // 1. LearnDash Course.
        $a_id = $svc->create_activity( array(
            'title'         => 'Foundations of Early Learning',
            'pathway_id'    => $tp_id,
            'cohort_id'     => $cohort_id,
            'activity_type' => 'learndash_course',
            'weight'        => 2.0,
            'ordering_hint' => 1,
            'external_ref'  => wp_json_encode( array( 'course_id' => 99901 ) ),
        ) );
        $teacher_activities['ld_course'] = $a_id;

        // 2. Pre Self-Assessment.
        $a_id = $svc->create_activity( array(
            'title'         => 'Pre Self-Assessment',
            'pathway_id'    => $tp_id,
            'cohort_id'     => $cohort_id,
            'activity_type' => 'teacher_self_assessment',
            'weight'        => 1.0,
            'ordering_hint' => 2,
            'external_ref'  => wp_json_encode( array(
                'form_plugin' => 'jetformbuilder',
                'form_id'     => 99901,
                'phase'       => 'pre',
            ) ),
        ) );
        $teacher_activities['pre_self'] = $a_id;

        // 3. Post Self-Assessment.
        $a_id = $svc->create_activity( array(
            'title'         => 'Post Self-Assessment',
            'pathway_id'    => $tp_id,
            'cohort_id'     => $cohort_id,
            'activity_type' => 'teacher_self_assessment',
            'weight'        => 1.0,
            'ordering_hint' => 3,
            'external_ref'  => wp_json_encode( array(
                'form_plugin' => 'jetformbuilder',
                'form_id'     => 99902,
                'phase'       => 'post',
            ) ),
        ) );
        $teacher_activities['post_self'] = $a_id;

        // 4. Children Assessment — use the infant instrument as the primary link.
        $a_id = $svc->create_activity( array(
            'title'         => 'Children Assessment',
            'pathway_id'    => $tp_id,
            'cohort_id'     => $cohort_id,
            'activity_type' => 'children_assessment',
            'weight'        => 2.0,
            'ordering_hint' => 4,
            'external_ref'  => wp_json_encode( array( 'instrument_id' => $instruments['infant'] ) ),
        ) );
        $teacher_activities['children'] = $a_id;

        // 5. Coaching Attendance.
        $a_id = $svc->create_activity( array(
            'title'         => 'Coaching Attendance',
            'pathway_id'    => $tp_id,
            'cohort_id'     => $cohort_id,
            'activity_type' => 'coaching_session_attendance',
            'weight'        => 1.0,
            'ordering_hint' => 5,
            'external_ref'  => wp_json_encode( (object) array() ),
        ) );
        $teacher_activities['coaching'] = $a_id;

        // --- Mentor Pathway ---
        $mp_id = $svc->create_pathway( array(
            'pathway_name' => 'Mentor Pathway',
            'cohort_id'    => $cohort_id,
            'target_roles' => array( 'mentor' ),
            'active_status' => 1,
        ) );

        $mentor_activities = array();

        // 1. LearnDash Course.
        $a_id = $svc->create_activity( array(
            'title'         => 'Mentor Training Course',
            'pathway_id'    => $mp_id,
            'cohort_id'     => $cohort_id,
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
            'cohort_id'     => $cohort_id,
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
        WP_CLI::log( "  [10/15] Pathways created: 2 (teacher={$t_act_count} activities, mentor={$m_act_count} activities)" );

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
            // Center leaders and district leaders don't have pathways.
            if ( $pathway_id ) {
                $repo->update( $e['enrollment_id'], array(
                    'assigned_pathway_id' => $pathway_id,
                ) );
                $count++;
            }
        }

        WP_CLI::log( "  [11/15] Pathway assignments updated: {$count}" );
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

        $post_self = $pathways['teacher_activities']['post_self'];
        $pre_self  = $pathways['teacher_activities']['pre_self'];

        $wpdb->insert( $wpdb->prefix . 'hl_activity_prereq_group', array(
            'activity_id' => $post_self,
            'prereq_type' => 'all_of',
        ) );
        $group_id = $wpdb->insert_id;

        $wpdb->insert( $wpdb->prefix . 'hl_activity_prereq_item', array(
            'group_id'                 => $group_id,
            'prerequisite_activity_id' => $pre_self,
        ) );

        WP_CLI::log( '  [12/15] Prereq rule created: Post Self-Assessment requires Pre Self-Assessment' );
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

        WP_CLI::log( '  [13/15] Drip rule created: Post Self-Assessment released 30 days ago' );
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

        WP_CLI::log( "  [14/15] Activity states created: {$count}" );
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

        WP_CLI::log( "  [15/15] Completion rollups computed: {$count}" . ( $errors ? " ({$errors} errors)" : '' ) );
    }
}
