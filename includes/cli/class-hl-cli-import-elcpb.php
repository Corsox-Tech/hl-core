<?php
/**
 * WP-CLI command: wp hl-core import-elcpb
 *
 * Imports ELC Palm Beach (ELCPB) Year 1 historical data from LearnDash into HL Core.
 * Reads existing WP users and LearnDash completion data — does NOT create or modify users.
 * Use --clean to remove all ELCPB import data.
 *
 * @package HL_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HL_CLI_Import_ELCPB {

	/** Partnership code for the container. */
	const PARTNERSHIP_CODE = 'ELCPB-B2E-2025';

	/** Cycle code for the yearly run. */
	const CYCLE_CODE = 'ELCPB-Y1-2025';

	/** LD group meta keys → role mapping. */
	const LD_GROUP_ROLES = array(
		'learndash_group_users_32043' => 'mentor',
		'learndash_group_users_32046' => 'teacher',
		'learndash_group_users_32750' => 'school_leader',
	);

	/** LD institution post ID → school name mapping. */
	const LD_SCHOOLS = array(
		32817 => 'ABC Playschool',
		32819 => 'Bright IDEAS',
		32821 => "King's Kids",
		32823 => 'Life Span',
		32825 => 'Stepping Stones',
		32827 => 'WeeCare',
	);

	/** User IDs to skip (no school assigned). */
	const SKIP_USER_IDS = array( 352, 1406 );

	/** LD district meta value for school_district. */
	const LD_DISTRICT_ID = '32916';

	/**
	 * Register the WP-CLI command.
	 */
	public static function register() {
		WP_CLI::add_command( 'hl-core import-elcpb', array( new self(), 'run' ) );
	}

	/**
	 * Import ELC Palm Beach Year 1 historical data from LearnDash.
	 *
	 * ## OPTIONS
	 *
	 * [--clean]
	 * : Remove all ELCPB import data before importing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp hl-core import-elcpb
	 *     wp hl-core import-elcpb --clean
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Named args.
	 */
	public function run( $args, $assoc_args ) {
		$clean = isset( $assoc_args['clean'] );

		if ( $clean ) {
			$this->clean();
			WP_CLI::success( 'ELCPB import data cleaned.' );
			return;
		}

		if ( $this->import_exists() ) {
			WP_CLI::warning( 'ELCPB data already exists. Run with --clean first to re-import.' );
			return;
		}

		WP_CLI::line( '' );
		WP_CLI::line( '=== HL Core ELCPB Year 1 Import ===' );
		WP_CLI::line( '' );

		// Step 1: Partnership (container).
		$partnership_id = $this->create_partnership();

		// Step 2: Org Structure.
		$orgunits = $this->create_orgunits();

		// Step 3: Cycle.
		$cycle_id = $this->create_cycle( $partnership_id, $orgunits );

		// Step 4: Discover users from LearnDash.
		$users = $this->discover_users( $orgunits );

		// Step 5: Create enrollments.
		$enrollments = $this->create_enrollments( $users, $cycle_id, $orgunits );

		// Step 6: Create pathways & components.
		$pathways = $this->create_pathways( $cycle_id );

		// Step 7: Assign pathways to enrollments.
		$this->assign_pathways( $enrollments, $pathways );

		// Step 8: Import LearnDash completion data.
		$this->import_completion_data( $enrollments, $pathways );

		// Step 9: Create TSA and reflection placeholder states.
		$this->create_placeholder_states( $enrollments, $pathways );

		// Step 10: Compute completion rollups.
		$this->compute_rollups( $enrollments );

		WP_CLI::line( '' );
		WP_CLI::success( 'ELCPB Year 1 import complete!' );
		WP_CLI::line( '' );
		WP_CLI::line( 'Summary:' );
		WP_CLI::line( "  Partnership: {$partnership_id} (code: " . self::PARTNERSHIP_CODE . ')' );
		WP_CLI::line( "  Cycle:       {$cycle_id} (code: " . self::CYCLE_CODE . ')' );
		WP_CLI::line( '  Schools:     ' . count( $orgunits['schools'] ) );
		WP_CLI::line( '  Enrollments: ' . count( $enrollments['all'] ) );
		WP_CLI::line( '  Pathways:    3' );
		WP_CLI::line( '' );
	}

	// ------------------------------------------------------------------
	// Idempotency
	// ------------------------------------------------------------------

	/**
	 * Check if ELCPB data already exists.
	 */
	private function import_exists() {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT cycle_id FROM {$wpdb->prefix}hl_cycle WHERE cycle_code = %s LIMIT 1",
				self::CYCLE_CODE
			)
		);
	}

	// ------------------------------------------------------------------
	// Step 1: Partnership
	// ------------------------------------------------------------------

	private function create_partnership() {
		global $wpdb;

		// Check if already exists.
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT partnership_id FROM {$wpdb->prefix}hl_partnership WHERE partnership_code = %s LIMIT 1",
				self::PARTNERSHIP_CODE
			)
		);
		if ( $existing ) {
			WP_CLI::log( "  [1] Partnership already exists: id={$existing}" );
			return (int) $existing;
		}

		$repo = new HL_Partnership_Repository();
		$id   = $repo->create( array(
			'partnership_name' => 'ELC Palm Beach B2E Mastery 2025-2026',
			'partnership_code' => self::PARTNERSHIP_CODE,
			'description'      => 'ELC Palm Beach County B2E Mastery Program — imported from LearnDash.',
			'status'           => 'active',
		) );

		WP_CLI::log( "  [1] Partnership created: id={$id}" );
		return $id;
	}

	// ------------------------------------------------------------------
	// Step 2: Org Structure
	// ------------------------------------------------------------------

	private function create_orgunits() {
		$repo = new HL_OrgUnit_Repository();

		$district_id = $repo->create( array(
			'name'         => 'ELC Palm Beach County',
			'orgunit_type' => 'district',
		) );

		$schools = array();
		foreach ( self::LD_SCHOOLS as $ld_institution_id => $name ) {
			$schools[ $ld_institution_id ] = $repo->create( array(
				'name'              => $name,
				'orgunit_type'      => 'school',
				'parent_orgunit_id' => $district_id,
			) );
		}

		WP_CLI::log( "  [2] Org units created: district={$district_id}, schools=" . count( $schools ) );
		return array(
			'district_id' => $district_id,
			'schools'     => $schools, // Keyed by LD institution post ID.
		);
	}

	// ------------------------------------------------------------------
	// Step 3: Cycle
	// ------------------------------------------------------------------

	private function create_cycle( $partnership_id, $orgunits ) {
		global $wpdb;
		$repo = new HL_Cycle_Repository();

		$cycle_id = $repo->create( array(
			'cycle_name'     => 'Year 1 (2025)',
			'cycle_code'     => self::CYCLE_CODE,
			'partnership_id' => $partnership_id,
			'cycle_type'     => 'program',
			'district_id'    => $orgunits['district_id'],
			'status'         => 'active',
			'start_date'     => '2025-01-01',
			'end_date'       => '2025-12-31',
		) );

		// Link schools to cycle.
		foreach ( $orgunits['schools'] as $school_id ) {
			$wpdb->insert( $wpdb->prefix . 'hl_cycle_school', array(
				'cycle_id'  => $cycle_id,
				'school_id' => $school_id,
			) );
		}

		WP_CLI::log( "  [3] Cycle created: id={$cycle_id}, code=" . self::CYCLE_CODE );
		return $cycle_id;
	}

	// ------------------------------------------------------------------
	// Step 4: Discover users from LearnDash
	// ------------------------------------------------------------------

	private function discover_users( $orgunits ) {
		global $wpdb;

		// Find all users in the ELC Palm Beach district (LD institution 32916).
		$user_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'school_district' AND meta_value = %s",
				self::LD_DISTRICT_ID
			)
		);

		if ( empty( $user_ids ) ) {
			WP_CLI::error( 'No users found with school_district = ' . self::LD_DISTRICT_ID );
		}

		$users = array();
		$skipped = 0;

		foreach ( $user_ids as $user_id ) {
			$user_id = (int) $user_id;

			// Skip excluded users.
			if ( in_array( $user_id, self::SKIP_USER_IDS, true ) ) {
				$skipped++;
				continue;
			}

			// Determine role from LD group membership.
			$role = $this->determine_role( $user_id );
			if ( ! $role ) {
				WP_CLI::warning( "  User {$user_id} not in any recognized LD group — skipping." );
				$skipped++;
				continue;
			}

			// Determine school from LD institution meta.
			$ld_institution = $this->get_user_ld_institution( $user_id );
			if ( ! $ld_institution || ! isset( $orgunits['schools'][ $ld_institution ] ) ) {
				WP_CLI::warning( "  User {$user_id} has unrecognized institution ({$ld_institution}) — skipping." );
				$skipped++;
				continue;
			}

			$users[] = array(
				'user_id'        => $user_id,
				'role'           => $role,
				'ld_institution' => $ld_institution,
				'school_id'      => $orgunits['schools'][ $ld_institution ],
			);
		}

		WP_CLI::log( "  [4] Users discovered: " . count( $users ) . " (skipped {$skipped})" );
		return $users;
	}

	/**
	 * Determine the HL role for a user based on LD group membership.
	 * If in BOTH mentor + teacher groups → mentor.
	 */
	private function determine_role( $user_id ) {
		$is_mentor  = (bool) get_user_meta( $user_id, 'learndash_group_users_32043', true );
		$is_teacher = (bool) get_user_meta( $user_id, 'learndash_group_users_32046', true );
		$is_leader  = (bool) get_user_meta( $user_id, 'learndash_group_users_32750', true );

		// Priority: mentor > teacher > school_leader.
		if ( $is_mentor ) {
			return 'mentor';
		}
		if ( $is_teacher ) {
			return 'teacher';
		}
		if ( $is_leader ) {
			return 'school_leader';
		}

		return null;
	}

	/**
	 * Get the user's LD institution post ID from usermeta.
	 * LearnDash stores the institution post ID in the 'school' meta key.
	 */
	private function get_user_ld_institution( $user_id ) {
		$val = get_user_meta( $user_id, 'school', true );
		return $val ? (int) $val : null;
	}

	// ------------------------------------------------------------------
	// Step 5: Enrollments
	// ------------------------------------------------------------------

	private function create_enrollments( $users, $cycle_id, $orgunits ) {
		$repo = new HL_Enrollment_Repository();
		$enrollments = array(
			'by_role' => array(
				'mentor'        => array(),
				'teacher'       => array(),
				'school_leader' => array(),
			),
			'all' => array(),
		);

		foreach ( $users as $u ) {
			$data = array(
				'user_id'     => $u['user_id'],
				'cycle_id'    => $cycle_id,
				'roles'       => array( $u['role'] ),
				'status'      => 'active',
				'school_id'   => $u['school_id'],
				'district_id' => $orgunits['district_id'],
			);

			$eid = $repo->create( $data );

			$entry = array(
				'enrollment_id' => $eid,
				'user_id'       => $u['user_id'],
				'role'          => $u['role'],
				'school_id'     => $u['school_id'],
			);

			$enrollments['by_role'][ $u['role'] ][] = $entry;
			$enrollments['all'][] = $entry;
		}

		$counts = array();
		foreach ( $enrollments['by_role'] as $role => $list ) {
			if ( ! empty( $list ) ) {
				$counts[] = $role . '=' . count( $list );
			}
		}

		WP_CLI::log( "  [5] Enrollments created: " . count( $enrollments['all'] ) . ' (' . implode( ', ', $counts ) . ')' );
		return $enrollments;
	}

	// ------------------------------------------------------------------
	// Step 6: Pathways & Components
	// ------------------------------------------------------------------

	private function create_pathways( $cycle_id ) {
		$svc = new HL_Pathway_Service();

		// --- Pathway 1: Mentor ---
		$mentor_id = $svc->create_pathway( array(
			'pathway_name'  => 'B2E Mastery – Mentor (Phase I)',
			'cycle_id'      => $cycle_id,
			'target_roles'  => array( 'mentor' ),
			'active_status' => 1,
		) );

		$mc = array();
		$mc['tsa_pre']      = $svc->create_component( array( 'title' => 'Teacher Self-Assessment (Pre)',                     'pathway_id' => $mentor_id, 'cycle_id' => $cycle_id, 'component_type' => 'teacher_self_assessment', 'weight' => 1.0, 'ordering_hint' => 1,  'external_ref' => wp_json_encode( array( 'phase' => 'pre' ) ) ) );
		$mc['tc0']          = $svc->create_component( array( 'title' => 'TC0: Welcome',                                      'pathway_id' => $mentor_id, 'cycle_id' => $cycle_id, 'component_type' => 'learndash_course',       'weight' => 1.0, 'ordering_hint' => 2,  'external_ref' => wp_json_encode( array( 'course_id' => 31037 ) ) ) );
		$mc['tc1']          = $svc->create_component( array( 'title' => 'TC1: Intro to begin to ECSEL',                      'pathway_id' => $mentor_id, 'cycle_id' => $cycle_id, 'component_type' => 'learndash_course',       'weight' => 1.0, 'ordering_hint' => 3,  'external_ref' => wp_json_encode( array( 'course_id' => 30280 ) ) ) );
		$mc['mc1']          = $svc->create_component( array( 'title' => 'MC1: Introduction to Reflective Practice',          'pathway_id' => $mentor_id, 'cycle_id' => $cycle_id, 'component_type' => 'learndash_course',       'weight' => 1.0, 'ordering_hint' => 4,  'external_ref' => wp_json_encode( array( 'course_id' => 30293 ) ) ) );
		$mc['tc2']          = $svc->create_component( array( 'title' => 'TC2: Your Own Emotionality',                        'pathway_id' => $mentor_id, 'cycle_id' => $cycle_id, 'component_type' => 'learndash_course',       'weight' => 1.0, 'ordering_hint' => 5,  'external_ref' => wp_json_encode( array( 'course_id' => 30284 ) ) ) );
		$mc['tc3']          = $svc->create_component( array( 'title' => 'TC3: Getting to Know Emotion',                      'pathway_id' => $mentor_id, 'cycle_id' => $cycle_id, 'component_type' => 'learndash_course',       'weight' => 1.0, 'ordering_hint' => 6,  'external_ref' => wp_json_encode( array( 'course_id' => 30286 ) ) ) );
		$mc['mc2']          = $svc->create_component( array( 'title' => 'MC2: A Deeper Dive into Reflective Practice',       'pathway_id' => $mentor_id, 'cycle_id' => $cycle_id, 'component_type' => 'learndash_course',       'weight' => 1.0, 'ordering_hint' => 7,  'external_ref' => wp_json_encode( array( 'course_id' => 30295 ) ) ) );
		$mc['tc4']          = $svc->create_component( array( 'title' => 'TC4: Emotion in the Heat of the Moment',            'pathway_id' => $mentor_id, 'cycle_id' => $cycle_id, 'component_type' => 'learndash_course',       'weight' => 1.0, 'ordering_hint' => 8,  'external_ref' => wp_json_encode( array( 'course_id' => 30288 ) ) ) );
		$mc['reflection']   = $svc->create_component( array( 'title' => 'End of Program Reflection – Mentor',                'pathway_id' => $mentor_id, 'cycle_id' => $cycle_id, 'component_type' => 'learndash_course',       'weight' => 1.0, 'ordering_hint' => 9,  'external_ref' => wp_json_encode( (object) array() ) ) );
		$mc['tsa_post']     = $svc->create_component( array( 'title' => 'Teacher Self-Assessment (Post)',                    'pathway_id' => $mentor_id, 'cycle_id' => $cycle_id, 'component_type' => 'teacher_self_assessment', 'weight' => 1.0, 'ordering_hint' => 10, 'external_ref' => wp_json_encode( array( 'phase' => 'post' ) ) ) );

		// --- Pathway 2: Teacher ---
		$teacher_id = $svc->create_pathway( array(
			'pathway_name'  => 'B2E Mastery – Teacher (Phase I)',
			'cycle_id'      => $cycle_id,
			'target_roles'  => array( 'teacher' ),
			'active_status' => 1,
		) );

		$tc = array();
		$tc['tsa_pre']      = $svc->create_component( array( 'title' => 'Teacher Self-Assessment (Pre)',                     'pathway_id' => $teacher_id, 'cycle_id' => $cycle_id, 'component_type' => 'teacher_self_assessment', 'weight' => 1.0, 'ordering_hint' => 1, 'external_ref' => wp_json_encode( array( 'phase' => 'pre' ) ) ) );
		$tc['tc0']          = $svc->create_component( array( 'title' => 'TC0: Welcome',                                      'pathway_id' => $teacher_id, 'cycle_id' => $cycle_id, 'component_type' => 'learndash_course',       'weight' => 1.0, 'ordering_hint' => 2, 'external_ref' => wp_json_encode( array( 'course_id' => 31037 ) ) ) );
		$tc['tc1']          = $svc->create_component( array( 'title' => 'TC1: Intro to begin to ECSEL',                      'pathway_id' => $teacher_id, 'cycle_id' => $cycle_id, 'component_type' => 'learndash_course',       'weight' => 1.0, 'ordering_hint' => 3, 'external_ref' => wp_json_encode( array( 'course_id' => 30280 ) ) ) );
		$tc['tc2']          = $svc->create_component( array( 'title' => 'TC2: Your Own Emotionality',                        'pathway_id' => $teacher_id, 'cycle_id' => $cycle_id, 'component_type' => 'learndash_course',       'weight' => 1.0, 'ordering_hint' => 4, 'external_ref' => wp_json_encode( array( 'course_id' => 30284 ) ) ) );
		$tc['tc3']          = $svc->create_component( array( 'title' => 'TC3: Getting to Know Emotion',                      'pathway_id' => $teacher_id, 'cycle_id' => $cycle_id, 'component_type' => 'learndash_course',       'weight' => 1.0, 'ordering_hint' => 5, 'external_ref' => wp_json_encode( array( 'course_id' => 30286 ) ) ) );
		$tc['tc4']          = $svc->create_component( array( 'title' => 'TC4: Emotion in the Heat of the Moment',            'pathway_id' => $teacher_id, 'cycle_id' => $cycle_id, 'component_type' => 'learndash_course',       'weight' => 1.0, 'ordering_hint' => 6, 'external_ref' => wp_json_encode( array( 'course_id' => 30288 ) ) ) );
		$tc['reflection']   = $svc->create_component( array( 'title' => 'End of Program Reflection – Teacher',               'pathway_id' => $teacher_id, 'cycle_id' => $cycle_id, 'component_type' => 'learndash_course',       'weight' => 1.0, 'ordering_hint' => 7, 'external_ref' => wp_json_encode( (object) array() ) ) );
		$tc['tsa_post']     = $svc->create_component( array( 'title' => 'Teacher Self-Assessment (Post)',                    'pathway_id' => $teacher_id, 'cycle_id' => $cycle_id, 'component_type' => 'teacher_self_assessment', 'weight' => 1.0, 'ordering_hint' => 8, 'external_ref' => wp_json_encode( array( 'phase' => 'post' ) ) ) );

		// --- Pathway 3: Streamlined (School Leaders) ---
		$leader_id = $svc->create_pathway( array(
			'pathway_name'  => 'B2E Mastery – Streamlined (Phase I)',
			'cycle_id'      => $cycle_id,
			'target_roles'  => array( 'school_leader' ),
			'active_status' => 1,
		) );

		$lc = array();
		$lc['tsa_pre']      = $svc->create_component( array( 'title' => 'Teacher Self-Assessment (Pre)',                     'pathway_id' => $leader_id, 'cycle_id' => $cycle_id, 'component_type' => 'teacher_self_assessment', 'weight' => 1.0, 'ordering_hint' => 1, 'external_ref' => wp_json_encode( array( 'phase' => 'pre' ) ) ) );
		$lc['tc0']          = $svc->create_component( array( 'title' => 'TC0: Welcome',                                      'pathway_id' => $leader_id, 'cycle_id' => $cycle_id, 'component_type' => 'learndash_course',       'weight' => 1.0, 'ordering_hint' => 2, 'external_ref' => wp_json_encode( array( 'course_id' => 31037 ) ) ) );
		$lc['tc1']          = $svc->create_component( array( 'title' => 'TC1: Intro (Streamlined)',                           'pathway_id' => $leader_id, 'cycle_id' => $cycle_id, 'component_type' => 'learndash_course',       'weight' => 1.0, 'ordering_hint' => 3, 'external_ref' => wp_json_encode( array( 'course_id' => 31332 ) ) ) );
		$lc['tc2']          = $svc->create_component( array( 'title' => 'TC2: Your Own Emotionality (Streamlined)',           'pathway_id' => $leader_id, 'cycle_id' => $cycle_id, 'component_type' => 'learndash_course',       'weight' => 1.0, 'ordering_hint' => 4, 'external_ref' => wp_json_encode( array( 'course_id' => 31333 ) ) ) );
		$lc['tc3']          = $svc->create_component( array( 'title' => 'TC3: Getting to Know Emotion (Streamlined)',         'pathway_id' => $leader_id, 'cycle_id' => $cycle_id, 'component_type' => 'learndash_course',       'weight' => 1.0, 'ordering_hint' => 5, 'external_ref' => wp_json_encode( array( 'course_id' => 31334 ) ) ) );
		$lc['tc4']          = $svc->create_component( array( 'title' => 'TC4: Emotion in the Heat of the Moment (Streamlined)', 'pathway_id' => $leader_id, 'cycle_id' => $cycle_id, 'component_type' => 'learndash_course',    'weight' => 1.0, 'ordering_hint' => 6, 'external_ref' => wp_json_encode( array( 'course_id' => 31335 ) ) ) );
		$lc['mc1']          = $svc->create_component( array( 'title' => 'MC1: Intro to Reflective Practice (Streamlined)',    'pathway_id' => $leader_id, 'cycle_id' => $cycle_id, 'component_type' => 'learndash_course',       'weight' => 1.0, 'ordering_hint' => 7, 'external_ref' => wp_json_encode( array( 'course_id' => 31387 ) ) ) );
		$lc['mc2']          = $svc->create_component( array( 'title' => 'MC2: Deeper Dive Reflective Practice (Streamlined)', 'pathway_id' => $leader_id, 'cycle_id' => $cycle_id, 'component_type' => 'learndash_course',      'weight' => 1.0, 'ordering_hint' => 8, 'external_ref' => wp_json_encode( array( 'course_id' => 31388 ) ) ) );
		$lc['tsa_post']     = $svc->create_component( array( 'title' => 'Teacher Self-Assessment (Post)',                    'pathway_id' => $leader_id, 'cycle_id' => $cycle_id, 'component_type' => 'teacher_self_assessment', 'weight' => 1.0, 'ordering_hint' => 9, 'external_ref' => wp_json_encode( array( 'phase' => 'post' ) ) ) );

		WP_CLI::log( '  [6] Pathways created: mentor=' . count( $mc ) . ' components, teacher=' . count( $tc ) . ' components, streamlined=' . count( $lc ) . ' components' );

		return array(
			'mentor'   => array( 'pathway_id' => $mentor_id,  'components' => $mc ),
			'teacher'  => array( 'pathway_id' => $teacher_id, 'components' => $tc ),
			'school_leader' => array( 'pathway_id' => $leader_id,  'components' => $lc ),
		);
	}

	// ------------------------------------------------------------------
	// Step 7: Assign pathways
	// ------------------------------------------------------------------

	private function assign_pathways( $enrollments, $pathways ) {
		$repo  = new HL_Enrollment_Repository();
		$count = 0;

		foreach ( $enrollments['all'] as $e ) {
			$role = $e['role'];
			if ( isset( $pathways[ $role ] ) ) {
				$repo->update( $e['enrollment_id'], array( 'assigned_pathway_id' => $pathways[ $role ]['pathway_id'] ) );
				$count++;
			}
		}

		WP_CLI::log( "  [7] Pathway assignments: {$count}" );
	}

	// ------------------------------------------------------------------
	// Step 8: LearnDash completion data
	// ------------------------------------------------------------------

	private function import_completion_data( $enrollments, $pathways ) {
		global $wpdb;
		$count_complete    = 0;
		$count_in_progress = 0;

		foreach ( $enrollments['all'] as $e ) {
			$role = $e['role'];
			if ( ! isset( $pathways[ $role ] ) ) {
				continue;
			}

			$components = $pathways[ $role ]['components'];

			foreach ( $components as $key => $component_id ) {
				// Skip non-LD components.
				if ( in_array( $key, array( 'tsa_pre', 'tsa_post', 'reflection' ), true ) ) {
					continue;
				}

				// Get the LD course_id from component external_ref.
				$ext_ref = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT external_ref FROM {$wpdb->prefix}hl_component WHERE component_id = %d",
						$component_id
					)
				);
				if ( ! $ext_ref ) {
					continue;
				}

				$ref_data  = json_decode( $ext_ref, true );
				$course_id = isset( $ref_data['course_id'] ) ? (int) $ref_data['course_id'] : 0;
				if ( ! $course_id ) {
					continue; // Placeholder component (e.g., End of Program Reflection with no course_id).
				}

				// Query LearnDash user activity.
				$activity = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT activity_status, activity_completed
						 FROM {$wpdb->prefix}learndash_user_activity
						 WHERE user_id = %d AND post_id = %d AND activity_type = 'course'
						 LIMIT 1",
						$e['user_id'],
						$course_id
					)
				);

				if ( ! $activity ) {
					continue; // Not started — don't create a state record.
				}

				if ( (int) $activity->activity_status === 1 ) {
					// Complete.
					$completed_at = $activity->activity_completed
						? gmdate( 'Y-m-d H:i:s', (int) $activity->activity_completed )
						: current_time( 'mysql', true );

					$wpdb->insert( $wpdb->prefix . 'hl_component_state', array(
						'enrollment_id'     => $e['enrollment_id'],
						'component_id'      => $component_id,
						'completion_percent' => 100,
						'completion_status'  => 'complete',
						'completed_at'      => $completed_at,
						'last_computed_at'  => current_time( 'mysql', true ),
					) );
					$count_complete++;
				} else {
					// In progress.
					$wpdb->insert( $wpdb->prefix . 'hl_component_state', array(
						'enrollment_id'     => $e['enrollment_id'],
						'component_id'      => $component_id,
						'completion_percent' => 50,
						'completion_status'  => 'in_progress',
						'last_computed_at'  => current_time( 'mysql', true ),
					) );
					$count_in_progress++;
				}
			}
		}

		WP_CLI::log( "  [8] LD completion imported: {$count_complete} complete, {$count_in_progress} in-progress" );
	}

	// ------------------------------------------------------------------
	// Step 9: Placeholder states (TSA Pre/Post + Reflection)
	// ------------------------------------------------------------------

	private function create_placeholder_states( $enrollments, $pathways ) {
		global $wpdb;
		$now   = current_time( 'mysql', true );
		$count = 0;

		foreach ( $enrollments['all'] as $e ) {
			$role = $e['role'];
			if ( ! isset( $pathways[ $role ] ) ) {
				continue;
			}

			$components = $pathways[ $role ]['components'];

			foreach ( array( 'tsa_pre', 'tsa_post' ) as $key ) {
				if ( isset( $components[ $key ] ) ) {
					$wpdb->insert( $wpdb->prefix . 'hl_component_state', array(
						'enrollment_id'     => $e['enrollment_id'],
						'component_id'      => $components[ $key ],
						'completion_percent' => 0,
						'completion_status'  => 'not_started',
						'last_computed_at'  => $now,
					) );
					$count++;
				}
			}

			if ( isset( $components['reflection'] ) ) {
				$wpdb->insert( $wpdb->prefix . 'hl_component_state', array(
					'enrollment_id'     => $e['enrollment_id'],
					'component_id'      => $components['reflection'],
					'completion_percent' => 0,
					'completion_status'  => 'not_started',
					'last_computed_at'  => $now,
				) );
				$count++;
			}
		}

		WP_CLI::log( "  [9] Placeholder states created: {$count}" );
	}

	// ------------------------------------------------------------------
	// Step 10: Completion rollups
	// ------------------------------------------------------------------

	private function compute_rollups( $enrollments ) {
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

		WP_CLI::log( "  [10] Completion rollups computed: {$count}" . ( $errors ? " ({$errors} errors)" : '' ) );
	}

	// ------------------------------------------------------------------
	// Clean
	// ------------------------------------------------------------------

	private function clean() {
		global $wpdb;

		WP_CLI::line( 'Cleaning ELCPB import data...' );

		$cycle_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT cycle_id FROM {$wpdb->prefix}hl_cycle WHERE cycle_code = %s LIMIT 1",
				self::CYCLE_CODE
			)
		);

		if ( $cycle_id ) {
			// Get enrollment IDs for cascade delete.
			$enrollment_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT enrollment_id FROM {$wpdb->prefix}hl_enrollment WHERE cycle_id = %d",
					$cycle_id
				)
			);

			if ( ! empty( $enrollment_ids ) ) {
				$in_ids = implode( ',', array_map( 'intval', $enrollment_ids ) );
				$wpdb->query( "DELETE FROM {$wpdb->prefix}hl_completion_rollup WHERE enrollment_id IN ({$in_ids})" );
				$wpdb->query( "DELETE FROM {$wpdb->prefix}hl_component_state WHERE enrollment_id IN ({$in_ids})" );
				$wpdb->query( "DELETE FROM {$wpdb->prefix}hl_component_override WHERE enrollment_id IN ({$in_ids})" );
				$wpdb->query( "DELETE FROM {$wpdb->prefix}hl_team_membership WHERE enrollment_id IN ({$in_ids})" );
				$wpdb->query( "DELETE FROM {$wpdb->prefix}hl_teaching_assignment WHERE enrollment_id IN ({$in_ids})" );
				$wpdb->query( "DELETE FROM {$wpdb->prefix}hl_teacher_assessment_instance WHERE enrollment_id IN ({$in_ids})" );
				$wpdb->query( "DELETE FROM {$wpdb->prefix}hl_observation WHERE cycle_id = {$cycle_id}" );
			}

			// Components (and their prereq/drip rules).
			$component_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT component_id FROM {$wpdb->prefix}hl_component WHERE cycle_id = %d",
					$cycle_id
				)
			);
			if ( ! empty( $component_ids ) ) {
				$in_comp = implode( ',', array_map( 'intval', $component_ids ) );
				$group_ids = $wpdb->get_col(
					"SELECT group_id FROM {$wpdb->prefix}hl_component_prereq_group WHERE component_id IN ({$in_comp})"
				);
				if ( ! empty( $group_ids ) ) {
					$in_grp = implode( ',', array_map( 'intval', $group_ids ) );
					$wpdb->query( "DELETE FROM {$wpdb->prefix}hl_component_prereq_item WHERE group_id IN ({$in_grp})" );
				}
				$wpdb->query( "DELETE FROM {$wpdb->prefix}hl_component_prereq_group WHERE component_id IN ({$in_comp})" );
				$wpdb->query( "DELETE FROM {$wpdb->prefix}hl_component_drip_rule WHERE component_id IN ({$in_comp})" );
			}

			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}hl_component WHERE cycle_id = %d", $cycle_id ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}hl_pathway WHERE cycle_id = %d", $cycle_id ) );

			$team_ids = $wpdb->get_col(
				$wpdb->prepare( "SELECT team_id FROM {$wpdb->prefix}hl_team WHERE cycle_id = %d", $cycle_id )
			);
			if ( ! empty( $team_ids ) ) {
				$in_teams = implode( ',', array_map( 'intval', $team_ids ) );
				$wpdb->query( "DELETE FROM {$wpdb->prefix}hl_team_membership WHERE team_id IN ({$in_teams})" );
			}
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}hl_team WHERE cycle_id = %d", $cycle_id ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}hl_enrollment WHERE cycle_id = %d", $cycle_id ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}hl_cycle_school WHERE cycle_id = %d", $cycle_id ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}hl_coach_assignment WHERE cycle_id = %d", $cycle_id ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}hl_coaching_session WHERE cycle_id = %d", $cycle_id ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}hl_child_cycle_snapshot WHERE cycle_id = %d", $cycle_id ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}hl_audit_log WHERE cycle_id = %d", $cycle_id ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}hl_cycle WHERE cycle_id = %d", $cycle_id ) );

			WP_CLI::log( "  Deleted cycle {$cycle_id} and all related records." );
		}

		// Delete org units.
		$district_id = $wpdb->get_var(
			"SELECT orgunit_id FROM {$wpdb->prefix}hl_orgunit WHERE name = 'ELC Palm Beach County' AND orgunit_type = 'district' LIMIT 1"
		);
		if ( $district_id ) {
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->prefix}hl_orgunit WHERE parent_orgunit_id = %d",
					$district_id
				)
			);
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->prefix}hl_orgunit WHERE orgunit_id = %d",
					$district_id
				)
			);
			WP_CLI::log( '  Deleted ELCPB org units.' );
		}

		// Delete partnership.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}hl_partnership WHERE partnership_code = %s",
				self::PARTNERSHIP_CODE
			)
		);
		WP_CLI::log( '  Deleted partnership (' . self::PARTNERSHIP_CODE . ').' );
	}
}
