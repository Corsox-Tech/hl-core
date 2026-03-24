<?php
/**
 * WP-CLI command: wp hl-core seed-beginnings-y2
 *
 * Populates Cycle 2 for the Beginnings School partnership using the
 * Cycle 2 roster CSV. Creates pathways, enrollments, teams, pathway
 * assignments, and teaching assignments.
 *
 * Assumes Cycle 1 was seeded by seed-beginnings and Cycle 2 was
 * created manually in the admin UI.
 *
 * @package HL_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HL_CLI_Seed_Beginnings_Y2 {

	const PARTNERSHIP_CODE = 'BEGINNINGS-2025';
	const Y1_CYCLE_CODE   = 'BEGINNINGS-Y1-2025';
	const META_KEY         = '_hl_beginnings_seed';

	// Real LearnDash course IDs.
	const TC0   = 31037;
	const TC1   = 30280;
	const TC2   = 30284;
	const TC3   = 30286;
	const TC4   = 30288;
	const TC5   = 39724;
	const TC6   = 39726;
	const TC7   = 39728;
	const TC8   = 39730;
	const MC1   = 30293;
	const MC2   = 30295;
	const MC3   = 39732;
	const MC4   = 39734;
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

	public static function register() {
		WP_CLI::add_command( 'hl-core seed-beginnings-y2', array( new self(), 'run' ) );
	}

	/**
	 * Populate Cycle 2 for Beginnings School.
	 *
	 * ## EXAMPLES
	 *
	 *     wp hl-core seed-beginnings-y2
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Named args.
	 */
	public function run( $args, $assoc_args ) {
		global $wpdb;
		$t = $wpdb->prefix;

		WP_CLI::line( '' );
		WP_CLI::line( '=== Beginnings Cycle 2 Setup ===' );
		WP_CLI::line( '' );

		// 1. Find Cycle 2 (the one the user created manually).
		$y1 = $wpdb->get_row( $wpdb->prepare(
			"SELECT cycle_id, district_id, partnership_id FROM {$t}hl_cycle WHERE cycle_code = %s",
			self::Y1_CYCLE_CODE
		) );
		if ( ! $y1 ) {
			WP_CLI::error( 'Cycle 1 not found. Run seed-beginnings first.' );
			return;
		}

		$cycle2 = $wpdb->get_row( $wpdb->prepare(
			"SELECT cycle_id, cycle_name FROM {$t}hl_cycle
			 WHERE partnership_id = %d AND cycle_id != %d
			 ORDER BY cycle_id DESC LIMIT 1",
			$y1->partnership_id, $y1->cycle_id
		) );
		if ( ! $cycle2 ) {
			WP_CLI::error( 'Cycle 2 not found. Create it from the Partnership page first.' );
			return;
		}

		$cycle_id    = (int) $cycle2->cycle_id;
		$district_id = (int) $y1->district_id;
		WP_CLI::log( "  [1] Found Cycle 2: id={$cycle_id} ({$cycle2->cycle_name})" );

		// Check if already populated.
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$t}hl_enrollment WHERE cycle_id = %d", $cycle_id
		) );
		if ( $existing > 0 ) {
			WP_CLI::error( "Cycle 2 already has {$existing} enrollments. Clean it first or use a fresh cycle." );
			return;
		}

		// 2. Link schools to Cycle 2.
		$school_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT school_id FROM {$t}hl_cycle_school WHERE cycle_id = %d", $y1->cycle_id
		) );
		foreach ( $school_ids as $sid ) {
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$t}hl_cycle_school WHERE cycle_id = %d AND school_id = %d",
				$cycle_id, $sid
			) );
			if ( ! $exists ) {
				$wpdb->insert( $t . 'hl_cycle_school', array( 'cycle_id' => $cycle_id, 'school_id' => (int) $sid ) );
			}
		}
		WP_CLI::log( '  [2] Schools linked: ' . count( $school_ids ) );

		// 3. Build school name → ID map.
		$school_map = array();
		foreach ( $school_ids as $sid ) {
			$name = $wpdb->get_var( $wpdb->prepare(
				"SELECT name FROM {$t}hl_orgunit WHERE orgunit_id = %d", $sid
			) );
			if ( $name ) {
				$school_map[ $name ] = (int) $sid;
			}
		}
		// Also map district name.
		$district_name = $wpdb->get_var( $wpdb->prepare(
			"SELECT name FROM {$t}hl_orgunit WHERE orgunit_id = %d", $district_id
		) );

		// 4. Create pathways.
		$pathways = $this->create_pathways( $cycle_id );
		WP_CLI::log( '  [4] Pathways created: ' . count( $pathways ) );

		// 5. Read CSV and process each row.
		$csv_path = plugin_dir_path( dirname( __DIR__ ) ) . 'data/beginnings-cycle-2-roster.csv';
		if ( ! file_exists( $csv_path ) ) {
			WP_CLI::error( "CSV not found at {$csv_path}. Run seed-beginnings first." );
			return;
		}

		$fp   = fopen( $csv_path, 'r' );
		$header = fgetcsv( $fp );
		$rows   = array();
		while ( ( $row = fgetcsv( $fp ) ) !== false ) {
			$rows[] = array_combine( $header, $row );
		}
		fclose( $fp );
		WP_CLI::log( '  [5] CSV loaded: ' . count( $rows ) . ' rows' );

		// 6. Create new hire user (Natalia — not in Cycle 1).
		$new_hire_email = 'new-hire-mentor-boston@yopmail.com';
		$new_hire_user  = get_user_by( 'email', $new_hire_email );
		if ( ! $new_hire_user ) {
			$uid = wp_insert_user( array(
				'user_login'   => 'new-hire-mentor-boston',
				'user_email'   => $new_hire_email,
				'user_pass'    => $new_hire_email,
				'display_name' => 'Natalia NewHire-Mentor',
				'first_name'   => 'Natalia',
				'last_name'    => 'NewHire-Mentor',
				'role'         => 'subscriber',
			) );
			if ( ! is_wp_error( $uid ) ) {
				update_user_meta( $uid, self::META_KEY, 'created' );
				WP_CLI::log( "  [6] New hire created: Natalia (user_id={$uid})" );
			}
		} else {
			WP_CLI::log( "  [6] New hire already exists: user_id={$new_hire_user->ID}" );
		}

		// 7. Create enrollments from CSV.
		$repo         = new HL_Enrollment_Repository();
		$enrollments  = array();
		$enroll_count = 0;

		foreach ( $rows as $row ) {
			$user = get_user_by( 'email', $row['email'] );
			if ( ! $user ) {
				WP_CLI::warning( "User not found: {$row['email']} — skipping." );
				continue;
			}

			$role      = $row['role'];
			$school_id = isset( $school_map[ $row['school'] ] ) ? $school_map[ $row['school'] ] : null;

			$eid = $repo->create( array(
				'user_id'     => $user->ID,
				'cycle_id'    => $cycle_id,
				'roles'       => array( $role ),
				'status'      => 'active',
				'school_id'   => $school_id,
				'district_id' => $district_id,
			) );

			$enrollments[] = array(
				'enrollment_id' => $eid,
				'user_id'       => $user->ID,
				'email'         => $row['email'],
				'role'          => $role,
				'school'        => $row['school'],
				'team'          => $row['team'],
				'pathway'       => $row['pathway'],
				'school_id'     => $school_id,
			);
			$enroll_count++;
		}
		WP_CLI::log( "  [7] Enrollments created: {$enroll_count}" );

		// 8. Create teams and assign members.
		$svc       = new HL_Team_Service();
		$team_map  = array(); // team_name => team_id
		$team_count = 0;

		foreach ( $enrollments as $e ) {
			if ( empty( $e['team'] ) ) continue;
			$team_name = $e['team'];

			if ( ! isset( $team_map[ $team_name ] ) ) {
				$team_id = $svc->create_team( array(
					'team_name' => $team_name,
					'cycle_id'  => $cycle_id,
					'school_id' => $e['school_id'],
				) );
				if ( is_wp_error( $team_id ) ) {
					WP_CLI::warning( "Team create failed: {$team_name}" );
					continue;
				}
				$team_map[ $team_name ] = $team_id;
				$team_count++;
			}

			$member_role = ( $e['role'] === 'mentor' ) ? 'mentor' : 'member';
			$svc->add_member( $team_map[ $team_name ], $e['enrollment_id'], $member_role );
		}
		WP_CLI::log( "  [8] Teams created: {$team_count}" );

		// 9. Assign pathways (both assigned_pathway_id AND hl_pathway_assignment row).
		$pathway_name_map = array();
		foreach ( $pathways as $name => $info ) {
			$pathway_name_map[ $name ] = $info['pathway_id'];
		}

		$assigned = 0;
		$admin_id = get_current_user_id() ?: 1;
		foreach ( $enrollments as $e ) {
			$pw_name = $e['pathway'];
			if ( isset( $pathway_name_map[ $pw_name ] ) ) {
				$pw_id = $pathway_name_map[ $pw_name ];

				// Set on enrollment row (legacy field).
				$wpdb->update(
					$t . 'hl_enrollment',
					array( 'assigned_pathway_id' => $pw_id ),
					array( 'enrollment_id' => $e['enrollment_id'] )
				);

				// Insert into pathway_assignment table (authoritative source).
				$wpdb->insert( $t . 'hl_pathway_assignment', array(
					'enrollment_id'      => $e['enrollment_id'],
					'pathway_id'         => $pw_id,
					'assigned_by_user_id' => $admin_id,
					'assignment_type'    => 'explicit',
				) );

				$assigned++;
			} else {
				WP_CLI::warning( "Pathway not found: {$pw_name} for {$e['email']}" );
			}
		}
		WP_CLI::log( "  [9] Pathway assignments: {$assigned}" );

		// 10. Teaching assignments (reuse classrooms from Cycle 1).
		remove_all_actions( 'hl_core_teaching_assignment_changed' );
		$cr_svc   = new HL_Classroom_Service();
		$ta_count = 0;

		// Build school_id => classrooms map.
		$classrooms_by_school = array();
		foreach ( $school_ids as $sid ) {
			$crs = $wpdb->get_results( $wpdb->prepare(
				"SELECT classroom_id FROM {$t}hl_classroom WHERE school_id = %d",
				$sid
			), ARRAY_A );
			$classrooms_by_school[ (int) $sid ] = wp_list_pluck( $crs, 'classroom_id' );
		}

		foreach ( $enrollments as $idx => $e ) {
			if ( $e['role'] !== 'teacher' ) continue;
			if ( ! $e['school_id'] || empty( $classrooms_by_school[ $e['school_id'] ] ) ) continue;

			$crs = $classrooms_by_school[ $e['school_id'] ];
			$cr_id = $crs[ $idx % count( $crs ) ];

			$result = $cr_svc->create_teaching_assignment( array(
				'enrollment_id'   => $e['enrollment_id'],
				'classroom_id'    => $cr_id,
				'is_lead_teacher' => 0,
			) );
			if ( ! is_wp_error( $result ) ) {
				$ta_count++;
			}
		}
		WP_CLI::log( "  [10] Teaching assignments: {$ta_count}" );

		// 11. Freeze child age groups for Cycle 2.
		$frozen = HL_Child_Snapshot_Service::freeze_age_groups( $cycle_id );
		WP_CLI::log( "  [11] Child age snapshots frozen: {$frozen}" );

		WP_CLI::line( '' );
		WP_CLI::success( 'Beginnings Cycle 2 populated!' );
		WP_CLI::line( '' );
		WP_CLI::line( 'Summary:' );
		WP_CLI::line( "  Cycle:       {$cycle_id}" );
		WP_CLI::line( "  Pathways:    " . count( $pathways ) );
		WP_CLI::line( "  Enrollments: {$enroll_count}" );
		WP_CLI::line( "  Teams:       {$team_count}" );
		WP_CLI::line( "  Assignments: {$ta_count}" );
		WP_CLI::line( "  Snapshots:   {$frozen}" );
		WP_CLI::line( '' );
	}

	// ------------------------------------------------------------------
	// Pathways
	// ------------------------------------------------------------------

	private function create_pathways( $cid ) {
		$svc = new HL_Pathway_Service();
		$pw  = array();

		$pw['Teacher Phase 2']     = $this->create_teacher_phase2( $svc, $cid );
		$pw['Mentor Phase 1']      = $this->create_mentor_phase1( $svc, $cid );
		$pw['Mentor Phase 2']      = $this->create_mentor_phase2( $svc, $cid );
		$pw['Mentor Transition']   = $this->create_mentor_transition( $svc, $cid );
		$pw['Streamlined Phase 2'] = $this->create_streamlined_phase2( $svc, $cid );

		return $pw;
	}

	private function cmp( $svc, $pid, $cid, $title, $type, $order, $ext = array() ) {
		return $svc->create_component( array(
			'title'          => $title,
			'pathway_id'     => $pid,
			'cycle_id'       => $cid,
			'component_type' => $type,
			'weight'         => 1.0,
			'ordering_hint'  => $order,
			'external_ref'   => wp_json_encode( $ext ?: (object) array() ),
		) );
	}

	private function add_prereq( $component_id, $prereq_id ) {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'hl_component_prereq_group', array(
			'component_id' => $component_id, 'prereq_type' => 'all_of',
		) );
		$gid = $wpdb->insert_id;
		$wpdb->insert( $wpdb->prefix . 'hl_component_prereq_item', array(
			'group_id' => $gid, 'prerequisite_component_id' => $prereq_id,
		) );
	}

	// Teacher Phase 2: 16 components
	private function create_teacher_phase2( $svc, $cid ) {
		$pid = $svc->create_pathway( array( 'pathway_name' => 'B2E Teacher Phase 2', 'cycle_id' => $cid, 'target_roles' => array( 'teacher' ), 'active_status' => 1 ) );
		$n = 0;
		$tsa = $this->cmp( $svc, $pid, $cid, 'Teacher Self-Assessment (Pre)', 'teacher_self_assessment', ++$n, array( 'phase' => 'pre' ) );
		$this->cmp( $svc, $pid, $cid, 'Child Assessment (Pre)', 'child_assessment', ++$n, array( 'phase' => 'pre' ) );
		$tc5 = $this->cmp( $svc, $pid, $cid, 'TC5: Connecting Emotion and Early Learning', 'learndash_course', ++$n, array( 'course_id' => self::TC5 ) );
		$this->cmp( $svc, $pid, $cid, 'Self-Reflection #1', 'self_reflection', ++$n, array( 'visit_number' => 1 ) );
		$this->cmp( $svc, $pid, $cid, 'Reflective Practice Session #1', 'reflective_practice_session', ++$n, array( 'session_number' => 1 ) );
		$tc6 = $this->cmp( $svc, $pid, $cid, 'TC6: Empathy, Acceptance & Prosocial Behaviors', 'learndash_course', ++$n, array( 'course_id' => self::TC6 ) );
		$this->cmp( $svc, $pid, $cid, 'Self-Reflection #2', 'self_reflection', ++$n, array( 'visit_number' => 2 ) );
		$this->cmp( $svc, $pid, $cid, 'Reflective Practice Session #2', 'reflective_practice_session', ++$n, array( 'session_number' => 2 ) );
		$tc7 = $this->cmp( $svc, $pid, $cid, 'TC7: begin to ECSEL Tools & Trauma-Informed', 'learndash_course', ++$n, array( 'course_id' => self::TC7 ) );
		$this->cmp( $svc, $pid, $cid, 'Self-Reflection #3', 'self_reflection', ++$n, array( 'visit_number' => 3 ) );
		$this->cmp( $svc, $pid, $cid, 'Reflective Practice Session #3', 'reflective_practice_session', ++$n, array( 'session_number' => 3 ) );
		$tc8 = $this->cmp( $svc, $pid, $cid, 'TC8: ECSEL in the Everyday Classroom', 'learndash_course', ++$n, array( 'course_id' => self::TC8 ) );
		$this->cmp( $svc, $pid, $cid, 'Self-Reflection #4', 'self_reflection', ++$n, array( 'visit_number' => 4 ) );
		$this->cmp( $svc, $pid, $cid, 'Reflective Practice Session #4', 'reflective_practice_session', ++$n, array( 'session_number' => 4 ) );
		$this->cmp( $svc, $pid, $cid, 'Child Assessment (Post)', 'child_assessment', ++$n, array( 'phase' => 'post' ) );
		$this->cmp( $svc, $pid, $cid, 'Teacher Self-Assessment (Post)', 'teacher_self_assessment', ++$n, array( 'phase' => 'post' ) );
		$this->add_prereq( $tc5, $tsa ); $this->add_prereq( $tc6, $tc5 ); $this->add_prereq( $tc7, $tc6 ); $this->add_prereq( $tc8, $tc7 );
		WP_CLI::log( "    Teacher Phase 2: pathway_id={$pid}, {$n} components" );
		return array( 'pathway_id' => $pid, 'component_count' => $n );
	}

	// Mentor Phase 1: 19 components (for new hire Natalia)
	private function create_mentor_phase1( $svc, $cid ) {
		$pid = $svc->create_pathway( array( 'pathway_name' => 'B2E Mentor Phase 1', 'cycle_id' => $cid, 'target_roles' => array( 'mentor' ), 'active_status' => 1 ) );
		$n = 0;
		$tsa = $this->cmp( $svc, $pid, $cid, 'Teacher Self-Assessment (Pre)', 'teacher_self_assessment', ++$n, array( 'phase' => 'pre' ) );
		$this->cmp( $svc, $pid, $cid, 'Child Assessment (Pre)', 'child_assessment', ++$n, array( 'phase' => 'pre' ) );
		$tc0 = $this->cmp( $svc, $pid, $cid, 'TC0: Welcome', 'learndash_course', ++$n, array( 'course_id' => self::TC0 ) );
		$tc1 = $this->cmp( $svc, $pid, $cid, 'TC1: Intro to begin to ECSEL', 'learndash_course', ++$n, array( 'course_id' => self::TC1 ) );
		$this->cmp( $svc, $pid, $cid, 'Coaching Session #1', 'coaching_session_attendance', ++$n, array( 'session_number' => 1 ) );
		$mc1 = $this->cmp( $svc, $pid, $cid, 'MC1: Introduction to Reflective Practice', 'learndash_course', ++$n, array( 'course_id' => self::MC1 ) );
		$this->cmp( $svc, $pid, $cid, 'Reflective Practice Session #1', 'reflective_practice_session', ++$n, array( 'session_number' => 1 ) );
		$tc2 = $this->cmp( $svc, $pid, $cid, 'TC2: Your Own Emotionality', 'learndash_course', ++$n, array( 'course_id' => self::TC2 ) );
		$this->cmp( $svc, $pid, $cid, 'Coaching Session #2', 'coaching_session_attendance', ++$n, array( 'session_number' => 2 ) );
		$this->cmp( $svc, $pid, $cid, 'Reflective Practice Session #2', 'reflective_practice_session', ++$n, array( 'session_number' => 2 ) );
		$tc3 = $this->cmp( $svc, $pid, $cid, 'TC3: Getting to Know Emotion', 'learndash_course', ++$n, array( 'course_id' => self::TC3 ) );
		$this->cmp( $svc, $pid, $cid, 'Coaching Session #3', 'coaching_session_attendance', ++$n, array( 'session_number' => 3 ) );
		$mc2 = $this->cmp( $svc, $pid, $cid, 'MC2: A Deeper Dive into Reflective Practice', 'learndash_course', ++$n, array( 'course_id' => self::MC2 ) );
		$this->cmp( $svc, $pid, $cid, 'Reflective Practice Session #3', 'reflective_practice_session', ++$n, array( 'session_number' => 3 ) );
		$tc4 = $this->cmp( $svc, $pid, $cid, 'TC4: Emotion in the Heat of the Moment', 'learndash_course', ++$n, array( 'course_id' => self::TC4 ) );
		$this->cmp( $svc, $pid, $cid, 'Coaching Session #4', 'coaching_session_attendance', ++$n, array( 'session_number' => 4 ) );
		$this->cmp( $svc, $pid, $cid, 'Reflective Practice Session #4', 'reflective_practice_session', ++$n, array( 'session_number' => 4 ) );
		$this->cmp( $svc, $pid, $cid, 'Child Assessment (Post)', 'child_assessment', ++$n, array( 'phase' => 'post' ) );
		$this->cmp( $svc, $pid, $cid, 'Teacher Self-Assessment (Post)', 'teacher_self_assessment', ++$n, array( 'phase' => 'post' ) );
		$this->add_prereq( $tc0, $tsa ); $this->add_prereq( $tc1, $tc0 ); $this->add_prereq( $mc1, $tc1 );
		$this->add_prereq( $tc2, $mc1 ); $this->add_prereq( $tc3, $tc2 ); $this->add_prereq( $mc2, $tc3 ); $this->add_prereq( $tc4, $mc2 );
		WP_CLI::log( "    Mentor Phase 1: pathway_id={$pid}, {$n} components" );
		return array( 'pathway_id' => $pid, 'component_count' => $n );
	}

	// Mentor Phase 2: 18 components
	private function create_mentor_phase2( $svc, $cid ) {
		$pid = $svc->create_pathway( array( 'pathway_name' => 'B2E Mentor Phase 2', 'cycle_id' => $cid, 'target_roles' => array( 'mentor' ), 'active_status' => 1 ) );
		$n = 0;
		$tsa = $this->cmp( $svc, $pid, $cid, 'Teacher Self-Assessment (Pre)', 'teacher_self_assessment', ++$n, array( 'phase' => 'pre' ) );
		$this->cmp( $svc, $pid, $cid, 'Child Assessment (Pre)', 'child_assessment', ++$n, array( 'phase' => 'pre' ) );
		$tc5 = $this->cmp( $svc, $pid, $cid, 'TC5: Connecting Emotion and Early Learning', 'learndash_course', ++$n, array( 'course_id' => self::TC5 ) );
		$this->cmp( $svc, $pid, $cid, 'Coaching Session #1', 'coaching_session_attendance', ++$n, array( 'session_number' => 1 ) );
		$mc3 = $this->cmp( $svc, $pid, $cid, 'MC3: Extending RP to Communication with Co-Workers', 'learndash_course', ++$n, array( 'course_id' => self::MC3 ) );
		$this->cmp( $svc, $pid, $cid, 'Reflective Practice Session #1', 'reflective_practice_session', ++$n, array( 'session_number' => 1 ) );
		$tc6 = $this->cmp( $svc, $pid, $cid, 'TC6: Empathy, Acceptance & Prosocial Behaviors', 'learndash_course', ++$n, array( 'course_id' => self::TC6 ) );
		$this->cmp( $svc, $pid, $cid, 'Coaching Session #2', 'coaching_session_attendance', ++$n, array( 'session_number' => 2 ) );
		$this->cmp( $svc, $pid, $cid, 'Reflective Practice Session #2', 'reflective_practice_session', ++$n, array( 'session_number' => 2 ) );
		$tc7 = $this->cmp( $svc, $pid, $cid, 'TC7: begin to ECSEL Tools & Trauma-Informed', 'learndash_course', ++$n, array( 'course_id' => self::TC7 ) );
		$this->cmp( $svc, $pid, $cid, 'Coaching Session #3', 'coaching_session_attendance', ++$n, array( 'session_number' => 3 ) );
		$mc4 = $this->cmp( $svc, $pid, $cid, 'MC4: Extending RP to Communication with Families', 'learndash_course', ++$n, array( 'course_id' => self::MC4 ) );
		$this->cmp( $svc, $pid, $cid, 'Reflective Practice Session #3', 'reflective_practice_session', ++$n, array( 'session_number' => 3 ) );
		$tc8 = $this->cmp( $svc, $pid, $cid, 'TC8: ECSEL in the Everyday Classroom', 'learndash_course', ++$n, array( 'course_id' => self::TC8 ) );
		$this->cmp( $svc, $pid, $cid, 'Coaching Session #4', 'coaching_session_attendance', ++$n, array( 'session_number' => 4 ) );
		$this->cmp( $svc, $pid, $cid, 'Reflective Practice Session #4', 'reflective_practice_session', ++$n, array( 'session_number' => 4 ) );
		$this->cmp( $svc, $pid, $cid, 'Child Assessment (Post)', 'child_assessment', ++$n, array( 'phase' => 'post' ) );
		$this->cmp( $svc, $pid, $cid, 'Teacher Self-Assessment (Post)', 'teacher_self_assessment', ++$n, array( 'phase' => 'post' ) );
		$this->add_prereq( $tc5, $tsa ); $this->add_prereq( $mc3, $tc5 ); $this->add_prereq( $tc6, $mc3 );
		$this->add_prereq( $tc7, $tc6 ); $this->add_prereq( $mc4, $tc7 ); $this->add_prereq( $tc8, $mc4 );
		WP_CLI::log( "    Mentor Phase 2: pathway_id={$pid}, {$n} components" );
		return array( 'pathway_id' => $pid, 'component_count' => $n );
	}

	// Mentor Transition: 18 components (for Lisa — promoted teacher)
	private function create_mentor_transition( $svc, $cid ) {
		$pid = $svc->create_pathway( array( 'pathway_name' => 'B2E Mentor Transition', 'cycle_id' => $cid, 'target_roles' => array( 'mentor' ), 'active_status' => 1 ) );
		$n = 0;
		$tsa = $this->cmp( $svc, $pid, $cid, 'Teacher Self-Assessment (Pre)', 'teacher_self_assessment', ++$n, array( 'phase' => 'pre' ) );
		$this->cmp( $svc, $pid, $cid, 'Child Assessment (Pre)', 'child_assessment', ++$n, array( 'phase' => 'pre' ) );
		$tc5 = $this->cmp( $svc, $pid, $cid, 'TC5: Connecting Emotion and Early Learning', 'learndash_course', ++$n, array( 'course_id' => self::TC5 ) );
		$this->cmp( $svc, $pid, $cid, 'Coaching Session #1', 'coaching_session_attendance', ++$n, array( 'session_number' => 1 ) );
		$mc1 = $this->cmp( $svc, $pid, $cid, 'MC1: Introduction to Reflective Practice', 'learndash_course', ++$n, array( 'course_id' => self::MC1 ) );
		$this->cmp( $svc, $pid, $cid, 'Reflective Practice Session #1', 'reflective_practice_session', ++$n, array( 'session_number' => 1 ) );
		$tc6 = $this->cmp( $svc, $pid, $cid, 'TC6: Empathy, Acceptance & Prosocial Behaviors', 'learndash_course', ++$n, array( 'course_id' => self::TC6 ) );
		$this->cmp( $svc, $pid, $cid, 'Coaching Session #2', 'coaching_session_attendance', ++$n, array( 'session_number' => 2 ) );
		$this->cmp( $svc, $pid, $cid, 'Reflective Practice Session #2', 'reflective_practice_session', ++$n, array( 'session_number' => 2 ) );
		$tc7 = $this->cmp( $svc, $pid, $cid, 'TC7: begin to ECSEL Tools & Trauma-Informed', 'learndash_course', ++$n, array( 'course_id' => self::TC7 ) );
		$this->cmp( $svc, $pid, $cid, 'Coaching Session #3', 'coaching_session_attendance', ++$n, array( 'session_number' => 3 ) );
		$mc2 = $this->cmp( $svc, $pid, $cid, 'MC2: A Deeper Dive into Reflective Practice', 'learndash_course', ++$n, array( 'course_id' => self::MC2 ) );
		$this->cmp( $svc, $pid, $cid, 'Reflective Practice Session #3', 'reflective_practice_session', ++$n, array( 'session_number' => 3 ) );
		$tc8 = $this->cmp( $svc, $pid, $cid, 'TC8: ECSEL in the Everyday Classroom', 'learndash_course', ++$n, array( 'course_id' => self::TC8 ) );
		$this->cmp( $svc, $pid, $cid, 'Coaching Session #4', 'coaching_session_attendance', ++$n, array( 'session_number' => 4 ) );
		$this->cmp( $svc, $pid, $cid, 'Reflective Practice Session #4', 'reflective_practice_session', ++$n, array( 'session_number' => 4 ) );
		$this->cmp( $svc, $pid, $cid, 'Child Assessment (Post)', 'child_assessment', ++$n, array( 'phase' => 'post' ) );
		$this->cmp( $svc, $pid, $cid, 'Teacher Self-Assessment (Post)', 'teacher_self_assessment', ++$n, array( 'phase' => 'post' ) );
		$this->add_prereq( $tc5, $tsa ); $this->add_prereq( $mc1, $tc5 ); $this->add_prereq( $tc6, $mc1 );
		$this->add_prereq( $tc7, $tc6 ); $this->add_prereq( $mc2, $tc7 ); $this->add_prereq( $tc8, $mc2 );
		WP_CLI::log( "    Mentor Transition: pathway_id={$pid}, {$n} components" );
		return array( 'pathway_id' => $pid, 'component_count' => $n );
	}

	// Mentor Completion: 4 components
	private function create_mentor_completion( $svc, $cid ) {
		$pid = $svc->create_pathway( array( 'pathway_name' => 'B2E Mentor Completion', 'cycle_id' => $cid, 'target_roles' => array( 'mentor' ), 'active_status' => 1 ) );
		$n = 0;
		$tsa = $this->cmp( $svc, $pid, $cid, 'Teacher Self-Assessment (Pre)', 'teacher_self_assessment', ++$n, array( 'phase' => 'pre' ) );
		$mc3 = $this->cmp( $svc, $pid, $cid, 'MC3: Extending RP to Communication with Co-Workers', 'learndash_course', ++$n, array( 'course_id' => self::MC3 ) );
		$mc4 = $this->cmp( $svc, $pid, $cid, 'MC4: Extending RP to Communication with Families', 'learndash_course', ++$n, array( 'course_id' => self::MC4 ) );
		$this->cmp( $svc, $pid, $cid, 'Teacher Self-Assessment (Post)', 'teacher_self_assessment', ++$n, array( 'phase' => 'post' ) );
		$this->add_prereq( $mc3, $tsa ); $this->add_prereq( $mc4, $mc3 );
		WP_CLI::log( "    Mentor Completion: pathway_id={$pid}, {$n} components" );
		return array( 'pathway_id' => $pid, 'component_count' => $n );
	}

	// Streamlined Phase 2: 10 components
	private function create_streamlined_phase2( $svc, $cid ) {
		$pid = $svc->create_pathway( array( 'pathway_name' => 'B2E Streamlined Phase 2', 'cycle_id' => $cid, 'target_roles' => array( 'school_leader' ), 'active_status' => 1 ) );
		$n = 0;
		$this->cmp( $svc, $pid, $cid, 'TC5: Connecting Emotion and Early Learning (Streamlined)', 'learndash_course', ++$n, array( 'course_id' => self::TC5_S ) );
		$this->cmp( $svc, $pid, $cid, 'MC3: Extending RP to Co-Workers (Streamlined)', 'learndash_course', ++$n, array( 'course_id' => self::MC3_S ) );
		$this->cmp( $svc, $pid, $cid, 'Classroom Visit #1', 'classroom_visit', ++$n, array( 'visit_number' => 1 ) );
		$this->cmp( $svc, $pid, $cid, 'TC6: Empathy, Inclusivity & Prosocial Behaviors (Streamlined)', 'learndash_course', ++$n, array( 'course_id' => self::TC6_S ) );
		$this->cmp( $svc, $pid, $cid, 'Classroom Visit #2', 'classroom_visit', ++$n, array( 'visit_number' => 2 ) );
		$this->cmp( $svc, $pid, $cid, 'TC7: begin to ECSEL Tools & Trauma-Informed (Streamlined)', 'learndash_course', ++$n, array( 'course_id' => self::TC7_S ) );
		$this->cmp( $svc, $pid, $cid, 'Classroom Visit #3', 'classroom_visit', ++$n, array( 'visit_number' => 3 ) );
		$this->cmp( $svc, $pid, $cid, 'TC8: ECSEL in the Everyday Classroom (Streamlined)', 'learndash_course', ++$n, array( 'course_id' => self::TC8_S ) );
		$this->cmp( $svc, $pid, $cid, 'MC4: Extending RP to Families (Streamlined)', 'learndash_course', ++$n, array( 'course_id' => self::MC4_S ) );
		$this->cmp( $svc, $pid, $cid, 'Classroom Visit #4', 'classroom_visit', ++$n, array( 'visit_number' => 4 ) );
		WP_CLI::log( "    Streamlined Phase 2: pathway_id={$pid}, {$n} components" );
		return array( 'pathway_id' => $pid, 'component_count' => $n );
	}
}
