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

	/** LD team post ID → team definition mapping. */
	const LD_TEAMS = array(
		33113 => array( 'name' => 'Team 1 - ABC Playschool',  'school_ld' => 32817, 'mentor_uid' => 287 ),
		33114 => array( 'name' => 'Team 1 - Bright IDEAS',    'school_ld' => 32819, 'mentor_uid' => 288 ),
		33115 => array( 'name' => 'Team 1 - Kings Kids',      'school_ld' => 32821, 'mentor_uid' => 282 ),
		33116 => array( 'name' => 'Team 1 - Life Span',       'school_ld' => 32823, 'mentor_uid' => 283 ),
		33117 => array( 'name' => 'Team 2 - Life Span',       'school_ld' => 32823, 'mentor_uid' => 284 ),
		33118 => array( 'name' => 'Team 1 - Stepping Stones', 'school_ld' => 32825, 'mentor_uid' => 286 ),
		33119 => array( 'name' => 'Team 1 - WeeCare',         'school_ld' => 32827, 'mentor_uid' => 289 ),
		33120 => array( 'name' => 'Team 2 - WeeCare',         'school_ld' => 32827, 'mentor_uid' => 285 ),
	);

	/**
	 * LD classroom post ID → classroom definition mapping.
	 * Each: name, LD institution (school) ID, age_band.
	 */
	const LD_CLASSROOMS = array(
		// ABC Playschool (32817).
		32836 => array( 'name' => 'ABC Playschool - Infant',       'school_ld' => 32817, 'age_band' => 'infant' ),
		32842 => array( 'name' => 'ABC Playschool - Toddler',      'school_ld' => 32817, 'age_band' => 'toddler' ),
		34232 => array( 'name' => 'ABC Playschool - 2 year Old',   'school_ld' => 32817, 'age_band' => 'toddler' ),
		35150 => array( 'name' => 'ABC PlaySchool - Preschool',    'school_ld' => 32817, 'age_band' => 'preschool' ),
		// Bright IDEAS (32819).
		32838 => array( 'name' => 'Bright IDEAS - Infant',         'school_ld' => 32819, 'age_band' => 'infant' ),
		32839 => array( 'name' => 'Bright IDEAS - Toddler A',      'school_ld' => 32819, 'age_band' => 'toddler' ),
		32840 => array( 'name' => 'Bright IDEAS - Toddler B',      'school_ld' => 32819, 'age_band' => 'toddler' ),
		34234 => array( 'name' => "Bright IDEAS - 3's/4's",        'school_ld' => 32819, 'age_band' => 'preschool' ),
		// King's Kids (32821).
		32841 => array( 'name' => "King's Kids - Infant",          'school_ld' => 32821, 'age_band' => 'infant' ),
		32837 => array( 'name' => "King's Kids - Toddler",        'school_ld' => 32821, 'age_band' => 'toddler' ),
		32843 => array( 'name' => 'Kings Kid - Twos',              'school_ld' => 32821, 'age_band' => 'toddler' ),
		34237 => array( 'name' => "Kings Kids - Three's",          'school_ld' => 32821, 'age_band' => 'preschool' ),
		34238 => array( 'name' => 'Kings Kids - VPK',              'school_ld' => 32821, 'age_band' => 'preschool' ),
		// Life Span (32823).
		32844 => array( 'name' => 'Life Span - Infant',            'school_ld' => 32823, 'age_band' => 'infant' ),
		32852 => array( 'name' => 'Life Span - Toddler B',        'school_ld' => 32823, 'age_band' => 'toddler' ),
		32846 => array( 'name' => 'Life Span - Toddler C',        'school_ld' => 32823, 'age_band' => 'toddler' ),
		32847 => array( 'name' => 'Life Span - Toddler D',        'school_ld' => 32823, 'age_band' => 'toddler' ),
		34239 => array( 'name' => "Life Span - 3's",               'school_ld' => 32823, 'age_band' => 'preschool' ),
		// Stepping Stones (32825).
		32848 => array( 'name' => 'Stepping Stones - Infant/Toddler', 'school_ld' => 32825, 'age_band' => 'infant' ),
		32849 => array( 'name' => 'Stepping Stones - Two A',       'school_ld' => 32825, 'age_band' => 'toddler' ),
		32850 => array( 'name' => 'Stepping Stones - Two B',       'school_ld' => 32825, 'age_band' => 'toddler' ),
		34240 => array( 'name' => 'Stepping Stones - 4 and 5',     'school_ld' => 32825, 'age_band' => 'preschool' ),
		// WeeCare (32827).
		32851 => array( 'name' => 'WeeCare - Infant',              'school_ld' => 32827, 'age_band' => 'infant' ),
		32845 => array( 'name' => 'WeeCare - Toddler B',          'school_ld' => 32827, 'age_band' => 'toddler' ),
		32854 => array( 'name' => 'WeeCare - Young Twos',          'school_ld' => 32827, 'age_band' => 'toddler' ),
		32853 => array( 'name' => 'WeeCare - Twos',                'school_ld' => 32827, 'age_band' => 'toddler' ),
		34241 => array( 'name' => 'WeeCare - Preschool',           'school_ld' => 32827, 'age_band' => 'preschool' ),
	);

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

		// Step 8: Create teams and memberships from LD data.
		$this->create_teams( $cycle_id, $orgunits, $enrollments );

		// Step 9: Create classrooms.
		$this->create_classrooms( $orgunits );

		// Step 10: Import LearnDash completion data.
		$this->import_completion_data( $enrollments, $pathways );

		// Step 11: Create TSA and reflection placeholder states.
		$this->create_placeholder_states( $enrollments, $pathways );

		// Step 12: Import Teacher Self-Assessment responses.
		$this->import_tsa_data( $cycle_id, $enrollments, $pathways );

		// Step 13: Compute completion rollups.
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

			// Check production data override first, then fall back to LD group detection.
			$wp_user = get_user_by( 'id', $user_id );
			$override_role = $wp_user ? $this->get_prod_role_override( $wp_user->user_email ) : null;
			$role = $override_role ? $override_role : $this->determine_role( $user_id );
			if ( ! $role ) {
				WP_CLI::warning( "  User {$user_id} not in any recognized LD group — skipping." );
				$skipped++;
				continue;
			}
			if ( $override_role ) {
				WP_CLI::log( "    Role override applied for {$wp_user->user_email}: {$override_role}" );
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
	 * Get corrected role from production data snapshot.
	 * Returns null if email not found (fall back to LD detection).
	 */
	private function get_prod_role_override( $user_email ) {
		static $overrides = null;
		if ( $overrides === null ) {
			$json_path = __DIR__ . '/data/elcpbc-y1-enrollments.json';
			if ( file_exists( $json_path ) ) {
				$data = json_decode( file_get_contents( $json_path ), true );
				$overrides = array();
				if ( is_array( $data ) ) {
					foreach ( $data as $row ) {
						$roles = json_decode( $row['roles'], true );
						$role  = is_array( $roles ) && ! empty( $roles ) ? $roles[0] : null;
						if ( $role ) {
							$overrides[ strtolower( trim( $row['user_email'] ) ) ] = $role;
						}
					}
				}
			} else {
				$overrides = array();
			}
		}
		return isset( $overrides[ strtolower( trim( $user_email ) ) ] ) ? $overrides[ strtolower( trim( $user_email ) ) ] : null;
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
	// Step 8: Teams
	// ------------------------------------------------------------------

	private function create_teams( $cycle_id, $orgunits, $enrollments ) {
		global $wpdb;
		$svc        = new HL_Team_Service();
		$team_count = 0;
		$mem_count  = 0;

		// Build user_id → enrollment_id lookup from all enrollments.
		$uid_to_eid = array();
		foreach ( $enrollments['all'] as $e ) {
			$uid_to_eid[ $e['user_id'] ] = $e['enrollment_id'];
		}

		foreach ( self::LD_TEAMS as $ld_team_id => $def ) {
			$school_id = isset( $orgunits['schools'][ $def['school_ld'] ] ) ? $orgunits['schools'][ $def['school_ld'] ] : null;
			if ( ! $school_id ) {
				WP_CLI::warning( "  Team '{$def['name']}': school LD {$def['school_ld']} not found — skipping." );
				continue;
			}

			$team_id = $svc->create_team( array(
				'team_name' => $def['name'],
				'cycle_id'  => $cycle_id,
				'school_id' => $school_id,
			) );

			if ( is_wp_error( $team_id ) ) {
				WP_CLI::warning( "  Team '{$def['name']}': " . $team_id->get_error_message() );
				continue;
			}
			$team_count++;

			// Add mentor as team member.
			$mentor_uid = $def['mentor_uid'];
			if ( isset( $uid_to_eid[ $mentor_uid ] ) ) {
				$result = $svc->add_member( $team_id, $uid_to_eid[ $mentor_uid ], 'mentor' );
				if ( ! is_wp_error( $result ) ) {
					$mem_count++;
				}
			}

			// Find all users assigned to this LD team.
			$team_user_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'team' AND meta_value = %s",
					(string) $ld_team_id
				)
			);

			foreach ( $team_user_ids as $uid ) {
				$uid = (int) $uid;
				if ( $uid === $mentor_uid ) {
					continue; // Already added as mentor.
				}
				if ( ! isset( $uid_to_eid[ $uid ] ) ) {
					continue; // Not enrolled (school leader or skipped user).
				}
				$result = $svc->add_member( $team_id, $uid_to_eid[ $uid ], 'member' );
				if ( ! is_wp_error( $result ) ) {
					$mem_count++;
				}
			}
		}

		WP_CLI::log( "  [8] Teams created: {$team_count} (with {$mem_count} memberships)" );
	}

	// ------------------------------------------------------------------
	// Step 9: Classrooms
	// ------------------------------------------------------------------

	private function create_classrooms( $orgunits ) {
		$svc   = new HL_Classroom_Service();
		$count = 0;

		foreach ( self::LD_CLASSROOMS as $ld_classroom_id => $def ) {
			$school_id = isset( $orgunits['schools'][ $def['school_ld'] ] ) ? $orgunits['schools'][ $def['school_ld'] ] : null;
			if ( ! $school_id ) {
				WP_CLI::warning( "  Classroom '{$def['name']}': school LD {$def['school_ld']} not found — skipping." );
				continue;
			}

			$id = $svc->create_classroom( array(
				'classroom_name' => $def['name'],
				'school_id'      => $school_id,
				'age_band'       => $def['age_band'],
			) );

			if ( is_wp_error( $id ) ) {
				WP_CLI::warning( "  Classroom '{$def['name']}': " . $id->get_error_message() );
				continue;
			}
			$count++;
		}

		WP_CLI::log( "  [9] Classrooms created: {$count}" );
	}

	// ------------------------------------------------------------------
	// Production completion snapshot loader
	// ------------------------------------------------------------------

	/**
	 * Load corrected completion data from production snapshot.
	 *
	 * The JSON files contain prod enrollment IDs which differ from test IDs,
	 * so we join via user email to create a portable lookup.
	 *
	 * @return array Keyed by "email_lower|component_title" => state data.
	 */
	private function load_prod_completion_data() {
		static $data = null;
		if ( $data !== null ) {
			return $data;
		}
		$data = array();

		$enroll_path = __DIR__ . '/data/elcpbc-y1-enrollments.json';
		$comp_path   = __DIR__ . '/data/elcpbc-y1-completion.json';
		if ( ! file_exists( $enroll_path ) || ! file_exists( $comp_path ) ) {
			return $data;
		}

		$enrollments = json_decode( file_get_contents( $enroll_path ), true );
		$completions = json_decode( file_get_contents( $comp_path ), true );
		if ( ! is_array( $enrollments ) || ! is_array( $completions ) ) {
			return $data;
		}

		// Build enrollment_id → email map.
		$eid_to_email = array();
		foreach ( $enrollments as $e ) {
			$eid_to_email[ $e['enrollment_id'] ] = strtolower( trim( $e['user_email'] ) );
		}

		// Build email|title → state map.
		foreach ( $completions as $row ) {
			$email = isset( $eid_to_email[ $row['enrollment_id'] ] ) ? $eid_to_email[ $row['enrollment_id'] ] : null;
			if ( ! $email ) {
				continue;
			}
			$key = $email . '|' . $row['component_title'];
			$data[ $key ] = $row;
		}

		return $data;
	}

	// ------------------------------------------------------------------
	// Step 10: LearnDash completion data
	// ------------------------------------------------------------------

	private function import_completion_data( $enrollments, $pathways ) {
		global $wpdb;
		$count_complete    = 0;
		$count_in_progress = 0;
		$count_from_prod   = 0;

		// Load corrected production completion data (keyed by email|title).
		$prod_completion = $this->load_prod_completion_data();

		foreach ( $enrollments['all'] as $e ) {
			$role = $e['role'];
			if ( ! isset( $pathways[ $role ] ) ) {
				continue;
			}

			$components = $pathways[ $role ]['components'];

			// Resolve user email once per enrollment for prod snapshot lookup.
			$user_data  = get_userdata( $e['user_id'] );
			$user_email = $user_data ? strtolower( trim( $user_data->user_email ) ) : '';

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

				// Look up component title for prod snapshot matching.
				$component_title = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT title FROM {$wpdb->prefix}hl_component WHERE component_id = %d",
						$component_id
					)
				);

				// Check production snapshot first (corrected data).
				$prod_key   = $user_email . '|' . $component_title;
				$prod_state = ( $user_email && $component_title && isset( $prod_completion[ $prod_key ] ) )
					? $prod_completion[ $prod_key ]
					: null;

				if ( $prod_state ) {
					// Use production-corrected data.
					$pct    = (int) $prod_state['completion_percent'];
					$status = $prod_state['completion_status'];

					// Skip not_started records — no state row needed.
					if ( $pct === 0 && $status === 'not_started' ) {
						continue;
					}

					$insert = array(
						'enrollment_id'     => $e['enrollment_id'],
						'component_id'      => $component_id,
						'completion_percent' => $pct,
						'completion_status'  => $status,
						'last_computed_at'   => current_time( 'mysql', true ),
					);
					if ( ! empty( $prod_state['completed_at'] ) ) {
						$insert['completed_at'] = $prod_state['completed_at'];
					}
					$wpdb->insert( $wpdb->prefix . 'hl_component_state', $insert );

					if ( $pct === 100 ) {
						$count_complete++;
					} elseif ( $pct > 0 ) {
						$count_in_progress++;
					}
					$count_from_prod++;
				} else {
					// Fall back to LearnDash activity query.
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
		}

		WP_CLI::log( "  [10] LD completion imported: {$count_complete} complete, {$count_in_progress} in-progress ({$count_from_prod} from prod snapshot)" );
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

		WP_CLI::log( "  [11] Placeholder states created: {$count}" );
	}

	// ------------------------------------------------------------------
	// Step 12: Teacher Self-Assessment data
	// ------------------------------------------------------------------

	private function import_tsa_data( $cycle_id, $enrollments, $pathways ) {
		global $wpdb;
		$now            = current_time( 'mysql', true );
		$instance_count = 0;
		$state_updated  = 0;

		// Build user_id → enrollment lookup.
		$uid_to_enrollment = array();
		foreach ( $enrollments['all'] as $e ) {
			$uid_to_enrollment[ $e['user_id'] ] = $e;
		}

		$tsa_data = $this->get_tsa_data();

		foreach ( $tsa_data as $entry ) {
			$uid   = $entry['user_id'];
			$phase = $entry['phase'];

			if ( ! isset( $uid_to_enrollment[ $uid ] ) ) {
				continue; // User not in this import (e.g., test user 1409).
			}

			$e    = $uid_to_enrollment[ $uid ];
			$role = $e['role'];

			if ( ! isset( $pathways[ $role ] ) ) {
				continue;
			}

			$components    = $pathways[ $role ]['components'];
			$component_key = ( $phase === 'pre' ) ? 'tsa_pre' : 'tsa_post';

			if ( ! isset( $components[ $component_key ] ) ) {
				continue;
			}

			$component_id = $components[ $component_key ];

			// Create teacher assessment instance.
			$wpdb->insert( $wpdb->prefix . 'hl_teacher_assessment_instance', array(
				'instance_uuid'  => HL_DB_Utils::generate_uuid(),
				'cycle_id'       => $cycle_id,
				'enrollment_id'  => $e['enrollment_id'],
				'component_id'   => $component_id,
				'phase'          => $phase,
				'responses_json' => wp_json_encode( $entry['responses'] ),
				'status'         => 'submitted',
				'submitted_at'   => $entry['date'],
				'created_at'     => $entry['date'],
			) );
			$instance_count++;

			// Update the component_state from not_started → complete.
			$updated = $wpdb->update(
				$wpdb->prefix . 'hl_component_state',
				array(
					'completion_percent' => 100,
					'completion_status'  => 'complete',
					'completed_at'      => $entry['date'],
					'last_computed_at'  => $now,
				),
				array(
					'enrollment_id' => $e['enrollment_id'],
					'component_id'  => $component_id,
				)
			);
			if ( $updated ) {
				$state_updated++;
			}
		}

		WP_CLI::log( "  [12] TSA data imported: {$instance_count} instances, {$state_updated} states updated to complete" );
	}

	/**
	 * Get hardcoded TSA response data extracted from Excel.
	 * Source: data/Old - Assessment Entries Records/2025-10-10 - B2E Teacher Self-Assessment Report.xlsx
	 * Scale: 1=Strongly Disagree, 2=Disagree, 3=Slightly Disagree, 4=Neither, 5=Slightly Agree, 6=Agree, 7=Strongly Agree
	 */
	private function get_tsa_data() {
		return array(
			array( 'user_id' => 289, 'phase' => 'pre', 'date' => '2025-02-25 15:23:00', 'responses' => array( 'q1' => 1, 'q2' => 1, 'q3' => 1, 'q4' => 3, 'q5' => 3, 'q6' => 1, 'q7' => 1, 'q8' => 3, 'q9' => 1, 'q10' => 1, 'q11' => 5, 'q12' => 1, 'q13' => 5, 'q14' => 5, 'q15' => 3, 'q16' => 5 ) ),
			array( 'user_id' => 287, 'phase' => 'pre', 'date' => '2025-02-27 10:35:00', 'responses' => array( 'q1' => 7, 'q2' => 7, 'q3' => 7, 'q4' => 7, 'q5' => 6, 'q6' => 7, 'q7' => 7, 'q8' => 7, 'q9' => 6, 'q10' => 5, 'q11' => 7, 'q12' => 7, 'q13' => 7, 'q14' => 7, 'q15' => 7, 'q16' => 7 ) ),
			array( 'user_id' => 290, 'phase' => 'pre', 'date' => '2025-03-01 07:23:00', 'responses' => array( 'q1' => 7, 'q2' => 7, 'q3' => 7, 'q4' => 7, 'q5' => 6, 'q6' => 6, 'q7' => 4, 'q8' => 6, 'q9' => 6, 'q10' => 7, 'q11' => 7, 'q12' => 7, 'q13' => 5, 'q14' => 6, 'q15' => 5, 'q16' => 6 ) ),
			array( 'user_id' => 292, 'phase' => 'pre', 'date' => '2025-03-01 13:36:00', 'responses' => array( 'q1' => 6, 'q2' => 6, 'q3' => 5, 'q4' => 5, 'q5' => 6, 'q6' => 6, 'q7' => 6, 'q8' => 6, 'q9' => 5, 'q10' => 5, 'q11' => 5, 'q12' => 6, 'q13' => 6, 'q14' => 6, 'q15' => 6, 'q16' => 6 ) ),
			array( 'user_id' => 302, 'phase' => 'pre', 'date' => '2025-03-01 13:47:00', 'responses' => array( 'q1' => 7, 'q2' => 6, 'q3' => 7, 'q4' => 6, 'q5' => 6, 'q6' => 6, 'q7' => 6, 'q8' => 6, 'q9' => 6, 'q10' => 6, 'q11' => 6, 'q12' => 7, 'q13' => 7, 'q14' => 7, 'q15' => 7, 'q16' => 7 ) ),
			array( 'user_id' => 309, 'phase' => 'pre', 'date' => '2025-03-01 13:51:00', 'responses' => array( 'q1' => 7, 'q2' => 7, 'q3' => 7, 'q4' => 7, 'q5' => 7, 'q6' => 7, 'q7' => 7, 'q8' => 7, 'q9' => 7, 'q10' => 7, 'q11' => 7, 'q12' => 7, 'q13' => 6, 'q14' => 7, 'q15' => 7, 'q16' => 7 ) ),
			array( 'user_id' => 304, 'phase' => 'pre', 'date' => '2025-03-01 13:52:00', 'responses' => array( 'q1' => 7, 'q2' => 7, 'q3' => 5, 'q4' => 6, 'q5' => 3, 'q6' => 6, 'q7' => 6, 'q8' => 6, 'q9' => 5, 'q10' => 6, 'q11' => 6, 'q12' => 6, 'q13' => 6, 'q14' => 7, 'q15' => 7, 'q16' => 7 ) ),
			array( 'user_id' => 284, 'phase' => 'pre', 'date' => '2025-03-01 13:59:00', 'responses' => array( 'q1' => 6, 'q2' => 6, 'q3' => 6, 'q4' => 6, 'q5' => 5, 'q6' => 7, 'q7' => 7, 'q8' => 4, 'q9' => 6, 'q10' => 6, 'q11' => 7, 'q12' => 6, 'q13' => 6, 'q14' => 6, 'q15' => 5, 'q16' => 6 ) ),
			array( 'user_id' => 286, 'phase' => 'pre', 'date' => '2025-03-01 14:00:00', 'responses' => array( 'q1' => 7, 'q2' => 7, 'q3' => 7, 'q4' => 7, 'q5' => 5, 'q6' => 6, 'q7' => 7, 'q8' => 7, 'q9' => 7, 'q10' => 7, 'q11' => 7, 'q12' => 7, 'q13' => 7, 'q14' => 7, 'q15' => 6, 'q16' => 7 ) ),
			array( 'user_id' => 330, 'phase' => 'pre', 'date' => '2025-03-01 14:04:00', 'responses' => array( 'q1' => 5, 'q2' => 6, 'q3' => 6, 'q4' => 6, 'q5' => 4, 'q6' => 6, 'q7' => 6, 'q8' => 7, 'q9' => 7, 'q10' => 7, 'q11' => 7, 'q12' => 7, 'q13' => 6, 'q14' => 6, 'q15' => 6, 'q16' => 6 ) ),
			array( 'user_id' => 288, 'phase' => 'pre', 'date' => '2025-03-01 14:07:00', 'responses' => array( 'q1' => 7, 'q2' => 7, 'q3' => 7, 'q4' => 7, 'q5' => 7, 'q6' => 7, 'q7' => 7, 'q8' => 7, 'q9' => 7, 'q10' => 7, 'q11' => 7, 'q12' => 7, 'q13' => 7, 'q14' => 7, 'q15' => 7, 'q16' => 7 ) ),
			array( 'user_id' => 343, 'phase' => 'pre', 'date' => '2025-03-01 14:08:00', 'responses' => array( 'q1' => 7, 'q2' => 7, 'q3' => 7, 'q4' => 7, 'q5' => 7, 'q6' => 7, 'q7' => 7, 'q8' => 7, 'q9' => 7, 'q10' => 7, 'q11' => 7, 'q12' => 7, 'q13' => 7, 'q14' => 7, 'q15' => 7, 'q16' => 7 ) ),
			array( 'user_id' => 303, 'phase' => 'pre', 'date' => '2025-03-01 14:22:00', 'responses' => array( 'q1' => 6, 'q2' => 6, 'q3' => 6, 'q4' => 6, 'q5' => 6, 'q6' => 7, 'q7' => 7, 'q8' => 7, 'q9' => 7, 'q10' => 7, 'q11' => 7, 'q12' => 7, 'q13' => 7, 'q14' => 6, 'q15' => 6, 'q16' => 7 ) ),
			array( 'user_id' => 300, 'phase' => 'pre', 'date' => '2025-03-01 17:58:00', 'responses' => array( 'q1' => 5, 'q2' => 5, 'q3' => 5, 'q4' => 5, 'q5' => 5, 'q6' => 5, 'q7' => 5, 'q8' => 5, 'q9' => 5, 'q10' => 5, 'q11' => 5, 'q12' => 5, 'q13' => 5, 'q14' => 5, 'q15' => 5, 'q16' => 5 ) ),
			array( 'user_id' => 340, 'phase' => 'pre', 'date' => '2025-03-01 22:56:00', 'responses' => array( 'q1' => 7, 'q2' => 7, 'q3' => 7, 'q4' => 7, 'q5' => 6, 'q6' => 6, 'q7' => 1, 'q8' => 6, 'q9' => 6, 'q10' => 7, 'q11' => 7, 'q12' => 7, 'q13' => 7, 'q14' => 7, 'q15' => 6, 'q16' => 7 ) ),
			array( 'user_id' => 285, 'phase' => 'pre', 'date' => '2025-03-03 10:31:00', 'responses' => array( 'q1' => 6, 'q2' => 7, 'q3' => 6, 'q4' => 7, 'q5' => 6, 'q6' => 6, 'q7' => 7, 'q8' => 6, 'q9' => 7, 'q10' => 5, 'q11' => 6, 'q12' => 7, 'q13' => 6, 'q14' => 6, 'q15' => 6, 'q16' => 6 ) ),
			array( 'user_id' => 328, 'phase' => 'pre', 'date' => '2025-03-03 14:34:00', 'responses' => array( 'q1' => 1, 'q2' => 1, 'q3' => 1, 'q4' => 6, 'q5' => 6, 'q6' => 6, 'q7' => 1, 'q8' => 1, 'q9' => 6, 'q10' => 6, 'q11' => 5, 'q12' => 6, 'q13' => 6, 'q14' => 6, 'q15' => 7, 'q16' => 6 ) ),
			array( 'user_id' => 295, 'phase' => 'pre', 'date' => '2025-03-03 16:39:00', 'responses' => array( 'q1' => 7, 'q2' => 7, 'q3' => 7, 'q4' => 7, 'q5' => 7, 'q6' => 7, 'q7' => 7, 'q8' => 7, 'q9' => 7, 'q10' => 7, 'q11' => 7, 'q12' => 7, 'q13' => 7, 'q14' => 7, 'q15' => 7, 'q16' => 7 ) ),
			array( 'user_id' => 307, 'phase' => 'pre', 'date' => '2025-03-04 11:15:00', 'responses' => array( 'q1' => 6, 'q2' => 6, 'q3' => 6, 'q4' => 6, 'q5' => 6, 'q6' => 6, 'q7' => 6, 'q8' => 6, 'q9' => 6, 'q10' => 6, 'q11' => 6, 'q12' => 6, 'q13' => 6, 'q14' => 6, 'q15' => 6, 'q16' => 6 ) ),
			array( 'user_id' => 347, 'phase' => 'pre', 'date' => '2025-03-04 11:28:00', 'responses' => array( 'q1' => 7, 'q2' => 7, 'q3' => 7, 'q4' => 6, 'q5' => 7, 'q6' => 7, 'q7' => 7, 'q8' => 7, 'q9' => 6, 'q10' => 7, 'q11' => 7, 'q12' => 7, 'q13' => 6, 'q14' => 6, 'q15' => 5, 'q16' => 5 ) ),
			array( 'user_id' => 323, 'phase' => 'pre', 'date' => '2025-03-04 14:25:00', 'responses' => array( 'q1' => 6, 'q2' => 6, 'q3' => 6, 'q4' => 6, 'q5' => 4, 'q6' => 4, 'q7' => 6, 'q8' => 4, 'q9' => 6, 'q10' => 7, 'q11' => 6, 'q12' => 7, 'q13' => 6, 'q14' => 6, 'q15' => 6, 'q16' => 6 ) ),
			array( 'user_id' => 321, 'phase' => 'pre', 'date' => '2025-03-04 16:32:00', 'responses' => array( 'q1' => 6, 'q2' => 6, 'q3' => 6, 'q4' => 6, 'q5' => 6, 'q6' => 6, 'q7' => 6, 'q8' => 6, 'q9' => 6, 'q10' => 6, 'q11' => 6, 'q12' => 6, 'q13' => 6, 'q14' => 6, 'q15' => 6, 'q16' => 6 ) ),
			array( 'user_id' => 315, 'phase' => 'pre', 'date' => '2025-03-04 23:07:00', 'responses' => array( 'q1' => 7, 'q2' => 7, 'q3' => 7, 'q4' => 7, 'q5' => 7, 'q6' => 7, 'q7' => 7, 'q8' => 7, 'q9' => 7, 'q10' => 7, 'q11' => 6, 'q12' => 7, 'q13' => 7, 'q14' => 7, 'q15' => 7, 'q16' => 7 ) ),
			array( 'user_id' => 311, 'phase' => 'pre', 'date' => '2025-03-05 21:57:00', 'responses' => array( 'q1' => 7, 'q2' => 7, 'q3' => 7, 'q4' => 7, 'q5' => 7, 'q6' => 7, 'q7' => 7, 'q8' => 7, 'q9' => 7, 'q10' => 7, 'q11' => 7, 'q12' => 7, 'q13' => 7, 'q14' => 7, 'q15' => 7, 'q16' => 7 ) ),
			array( 'user_id' => 279, 'phase' => 'pre', 'date' => '2025-03-07 09:52:00', 'responses' => array( 'q1' => 7, 'q2' => 7, 'q3' => 7, 'q4' => 7, 'q5' => 6, 'q6' => 6, 'q7' => 6, 'q8' => 6, 'q9' => 5, 'q10' => 6, 'q11' => 6, 'q12' => 6, 'q13' => 6, 'q14' => 6, 'q15' => 6, 'q16' => 6 ) ),
			array( 'user_id' => 316, 'phase' => 'pre', 'date' => '2025-03-07 10:15:00', 'responses' => array( 'q1' => 5, 'q2' => 5, 'q3' => 5, 'q4' => 5, 'q5' => 5, 'q6' => 5, 'q7' => 5, 'q8' => 5, 'q9' => 5, 'q10' => 5, 'q11' => 5, 'q12' => 5, 'q13' => 5, 'q14' => 5, 'q15' => 5, 'q16' => 5 ) ),
			array( 'user_id' => 317, 'phase' => 'pre', 'date' => '2025-03-07 13:37:00', 'responses' => array( 'q1' => 7, 'q2' => 7, 'q3' => 7, 'q4' => 7, 'q5' => 7, 'q6' => 7, 'q7' => 7, 'q8' => 7, 'q9' => 7, 'q10' => 7, 'q11' => 7, 'q12' => 7, 'q13' => 7, 'q14' => 7, 'q15' => 7, 'q16' => 7 ) ),
			array( 'user_id' => 322, 'phase' => 'pre', 'date' => '2025-03-07 15:21:00', 'responses' => array( 'q1' => 6, 'q2' => 6, 'q3' => 6, 'q4' => 6, 'q5' => 6, 'q6' => 6, 'q7' => 6, 'q8' => 6, 'q9' => 7, 'q10' => 6, 'q11' => 6, 'q12' => 7, 'q13' => 6, 'q14' => 6, 'q15' => 6, 'q16' => 6 ) ),
			array( 'user_id' => 296, 'phase' => 'pre', 'date' => '2025-03-07 21:47:00', 'responses' => array( 'q1' => 6, 'q2' => 6, 'q3' => 6, 'q4' => 6, 'q5' => 6, 'q6' => 6, 'q7' => 7, 'q8' => 6, 'q9' => 7, 'q10' => 6, 'q11' => 6, 'q12' => 6, 'q13' => 6, 'q14' => 5, 'q15' => 5, 'q16' => 5 ) ),
			array( 'user_id' => 310, 'phase' => 'pre', 'date' => '2025-03-09 22:39:00', 'responses' => array( 'q1' => 7, 'q2' => 6, 'q3' => 6, 'q4' => 7, 'q5' => 6, 'q6' => 4, 'q7' => 7, 'q8' => 6, 'q9' => 7, 'q10' => 6, 'q11' => 6, 'q12' => 7, 'q13' => 6, 'q14' => 6, 'q15' => 5, 'q16' => 5 ) ),
			array( 'user_id' => 339, 'phase' => 'pre', 'date' => '2025-03-11 14:17:00', 'responses' => array( 'q1' => 7, 'q2' => 7, 'q3' => 7, 'q4' => 7, 'q5' => 6, 'q6' => 7, 'q7' => 7, 'q8' => 7, 'q9' => 7, 'q10' => 7, 'q11' => 7, 'q12' => 7, 'q13' => 7, 'q14' => 7, 'q15' => 7, 'q16' => 7 ) ),
			array( 'user_id' => 348, 'phase' => 'pre', 'date' => '2025-03-11 14:18:00', 'responses' => array( 'q1' => 6, 'q2' => 6, 'q3' => 6, 'q4' => 6, 'q5' => 6, 'q6' => 6, 'q7' => 6, 'q8' => 6, 'q9' => 6, 'q10' => 6, 'q11' => 6, 'q12' => 6, 'q13' => 6, 'q14' => 6, 'q15' => 6, 'q16' => 6 ) ),
			array( 'user_id' => 299, 'phase' => 'pre', 'date' => '2025-03-12 11:54:00', 'responses' => array( 'q1' => 7, 'q2' => 7, 'q3' => 7, 'q4' => 7, 'q5' => 7, 'q6' => 7, 'q7' => 7, 'q8' => 7, 'q9' => 7, 'q10' => 7, 'q11' => 7, 'q12' => 7, 'q13' => 7, 'q14' => 7, 'q15' => 7, 'q16' => 7 ) ),
			array( 'user_id' => 301, 'phase' => 'pre', 'date' => '2025-03-12 12:14:00', 'responses' => array( 'q1' => 7, 'q2' => 6, 'q3' => 6, 'q4' => 6, 'q5' => 5, 'q6' => 6, 'q7' => 6, 'q8' => 6, 'q9' => 7, 'q10' => 7, 'q11' => 7, 'q12' => 7, 'q13' => 6, 'q14' => 6, 'q15' => 6, 'q16' => 6 ) ),
			array( 'user_id' => 318, 'phase' => 'pre', 'date' => '2025-03-13 15:31:00', 'responses' => array( 'q1' => 7, 'q2' => 7, 'q3' => 7, 'q4' => 7, 'q5' => 4, 'q6' => 7, 'q7' => 7, 'q8' => 7, 'q9' => 7, 'q10' => 7, 'q11' => 1, 'q12' => 7, 'q13' => 7, 'q14' => 7, 'q15' => 7, 'q16' => 7 ) ),
			array( 'user_id' => 344, 'phase' => 'pre', 'date' => '2025-03-13 15:46:00', 'responses' => array( 'q1' => 6, 'q2' => 6, 'q3' => 6, 'q4' => 6, 'q5' => 2, 'q6' => 6, 'q7' => 6, 'q8' => 6, 'q9' => 6, 'q10' => 6, 'q11' => 6, 'q12' => 6, 'q13' => 6, 'q14' => 6, 'q15' => 6, 'q16' => 6 ) ),
			array( 'user_id' => 305, 'phase' => 'pre', 'date' => '2025-03-13 21:40:00', 'responses' => array( 'q1' => 1, 'q2' => 7, 'q3' => 7, 'q4' => 7, 'q5' => 7, 'q6' => 7, 'q7' => 7, 'q8' => 7, 'q9' => 7, 'q10' => 7, 'q11' => 7, 'q12' => 7, 'q13' => 7, 'q14' => 7, 'q15' => 7, 'q16' => 7 ) ),
			array( 'user_id' => 345, 'phase' => 'pre', 'date' => '2025-03-14 11:24:00', 'responses' => array( 'q1' => 7, 'q2' => 6, 'q3' => 6, 'q4' => 7, 'q5' => 6, 'q6' => 5, 'q7' => 5, 'q8' => 5, 'q9' => 7, 'q10' => 7, 'q11' => 7, 'q12' => 7, 'q13' => 5, 'q14' => 6, 'q15' => 5, 'q16' => 6 ) ),
			array( 'user_id' => 283, 'phase' => 'pre', 'date' => '2025-03-14 11:30:00', 'responses' => array( 'q1' => 7, 'q2' => 6, 'q3' => 6, 'q4' => 6, 'q5' => 6, 'q6' => 7, 'q7' => 7, 'q8' => 6, 'q9' => 7, 'q10' => 7, 'q11' => 7, 'q12' => 7, 'q13' => 6, 'q14' => 6, 'q15' => 5, 'q16' => 6 ) ),
			array( 'user_id' => 316, 'phase' => 'post', 'date' => '2025-03-14 13:04:55', 'responses' => array( 'q1' => 5, 'q2' => 5, 'q3' => 5, 'q4' => 5, 'q5' => 5, 'q6' => 5, 'q7' => 6, 'q8' => 6, 'q9' => 4, 'q10' => 4, 'q11' => 5, 'q12' => 5, 'q13' => 5, 'q14' => 5, 'q15' => 5, 'q16' => 5 ) ),
			array( 'user_id' => 324, 'phase' => 'pre', 'date' => '2025-03-14 14:53:00', 'responses' => array( 'q1' => 7, 'q2' => 7, 'q3' => 1, 'q4' => 7, 'q5' => 4, 'q6' => 6, 'q7' => 7, 'q8' => 7, 'q9' => 7, 'q10' => 7, 'q11' => 7, 'q12' => 7, 'q13' => 7, 'q14' => 7, 'q15' => 7, 'q16' => 7 ) ),
			array( 'user_id' => 349, 'phase' => 'pre', 'date' => '2025-03-17 08:41:00', 'responses' => array( 'q1' => 6, 'q2' => 6, 'q3' => 6, 'q4' => 6, 'q5' => 7, 'q6' => 7, 'q7' => 6, 'q8' => 6, 'q9' => 7, 'q10' => 7, 'q11' => 7, 'q12' => 7, 'q13' => 7, 'q14' => 7, 'q15' => 7, 'q16' => 3 ) ),
			array( 'user_id' => 320, 'phase' => 'pre', 'date' => '2025-03-17 15:26:00', 'responses' => array( 'q1' => 7, 'q2' => 7, 'q3' => 7, 'q4' => 7, 'q5' => 7, 'q6' => 7, 'q7' => 7, 'q8' => 7, 'q9' => 7, 'q10' => 7, 'q11' => 7, 'q12' => 7, 'q13' => 7, 'q14' => 7, 'q15' => 7, 'q16' => 7 ) ),
			array( 'user_id' => 319, 'phase' => 'pre', 'date' => '2025-03-17 15:40:00', 'responses' => array( 'q1' => 7, 'q2' => 6, 'q3' => 6, 'q4' => 7, 'q5' => 6, 'q6' => 6, 'q7' => 5, 'q8' => 6, 'q9' => 5, 'q10' => 6, 'q11' => 6, 'q12' => 4, 'q13' => 6, 'q14' => 6, 'q15' => 5, 'q16' => 5 ) ),
			array( 'user_id' => 325, 'phase' => 'pre', 'date' => '2025-03-18 12:40:00', 'responses' => array( 'q1' => 7, 'q2' => 7, 'q3' => 7, 'q4' => 7, 'q5' => 7, 'q6' => 7, 'q7' => 6, 'q8' => 7, 'q9' => 6, 'q10' => 6, 'q11' => 6, 'q12' => 6, 'q13' => 7, 'q14' => 7, 'q15' => 7, 'q16' => 7 ) ),
			array( 'user_id' => 313, 'phase' => 'pre', 'date' => '2025-03-18 13:21:00', 'responses' => array( 'q1' => 7, 'q2' => 7, 'q3' => 7, 'q4' => 7, 'q5' => 7, 'q6' => 7, 'q7' => 7, 'q8' => 7, 'q9' => 7, 'q10' => 7, 'q11' => 7, 'q12' => 7, 'q13' => 7, 'q14' => 7, 'q15' => 7, 'q16' => 7 ) ),
			array( 'user_id' => 314, 'phase' => 'pre', 'date' => '2025-03-18 15:21:00', 'responses' => array( 'q1' => 7, 'q2' => 7, 'q3' => 7, 'q4' => 7, 'q5' => 7, 'q6' => 7, 'q7' => 7, 'q8' => 7, 'q9' => 6, 'q10' => 6, 'q11' => 6, 'q12' => 6, 'q13' => 6, 'q14' => 6, 'q15' => 6, 'q16' => 6 ) ),
			array( 'user_id' => 282, 'phase' => 'pre', 'date' => '2025-03-20 10:27:00', 'responses' => array( 'q1' => 7, 'q2' => 6, 'q3' => 7, 'q4' => 7, 'q5' => 6, 'q6' => 6, 'q7' => 7, 'q8' => 6, 'q9' => 6, 'q10' => 6, 'q11' => 7, 'q12' => 7, 'q13' => 6, 'q14' => 7, 'q15' => 6, 'q16' => 6 ) ),
			array( 'user_id' => 315, 'phase' => 'post', 'date' => '2025-03-26 07:54:42', 'responses' => array( 'q1' => 7, 'q2' => 7, 'q3' => 7, 'q4' => 7, 'q5' => 7, 'q6' => 7, 'q7' => 7, 'q8' => 7, 'q9' => 7, 'q10' => 7, 'q11' => 7, 'q12' => 7, 'q13' => 7, 'q14' => 7, 'q15' => 7, 'q16' => 7 ) ),
			array( 'user_id' => 345, 'phase' => 'post', 'date' => '2025-03-27 10:27:21', 'responses' => array( 'q1' => 7, 'q2' => 6, 'q3' => 7, 'q4' => 7, 'q5' => 7, 'q6' => 7, 'q7' => 7, 'q8' => 7, 'q9' => 7, 'q10' => 7, 'q11' => 7, 'q12' => 6, 'q13' => 7, 'q14' => 7, 'q15' => 7, 'q16' => 7 ) ),
			array( 'user_id' => 327, 'phase' => 'pre', 'date' => '2025-04-01 13:05:00', 'responses' => array( 'q1' => 7, 'q2' => 6, 'q3' => 7, 'q4' => 7, 'q5' => 7, 'q6' => 4, 'q7' => 7, 'q8' => 3, 'q9' => 5, 'q10' => 5, 'q11' => 7, 'q12' => 7, 'q13' => 4, 'q14' => 4, 'q15' => 2, 'q16' => 2 ) ),
			array( 'user_id' => 321, 'phase' => 'post', 'date' => '2025-04-01 13:14:00', 'responses' => array( 'q1' => 5, 'q2' => 5, 'q3' => 5, 'q4' => 5, 'q5' => 5, 'q6' => 5, 'q7' => 5, 'q8' => 5, 'q9' => 6, 'q10' => 6, 'q11' => 6, 'q12' => 6, 'q13' => 6, 'q14' => 6, 'q15' => 6, 'q16' => 6 ) ),
			array( 'user_id' => 326, 'phase' => 'pre', 'date' => '2025-04-02 13:16:00', 'responses' => array( 'q1' => 6, 'q2' => 6, 'q3' => 6, 'q4' => 6, 'q5' => 6, 'q6' => 6, 'q7' => 6, 'q8' => 6, 'q9' => 7, 'q10' => 7, 'q11' => 7, 'q12' => 7, 'q13' => 7, 'q14' => 7, 'q15' => 7, 'q16' => 7 ) ),
			array( 'user_id' => 350, 'phase' => 'pre', 'date' => '2025-04-03 13:39:00', 'responses' => array( 'q1' => 7, 'q2' => 6, 'q3' => 6, 'q4' => 6, 'q5' => 5, 'q6' => 5, 'q7' => 5, 'q8' => 5, 'q9' => 5, 'q10' => 5, 'q11' => 6, 'q12' => 6, 'q13' => 6, 'q14' => 6, 'q15' => 6, 'q16' => 6 ) ),
			array( 'user_id' => 311, 'phase' => 'post', 'date' => '2025-04-04 09:13:20', 'responses' => array( 'q1' => 7, 'q2' => 7, 'q3' => 7, 'q4' => 7, 'q5' => 7, 'q6' => 7, 'q7' => 7, 'q8' => 7, 'q9' => 7, 'q10' => 7, 'q11' => 7, 'q12' => 7, 'q13' => 7, 'q14' => 7, 'q15' => 7, 'q16' => 7 ) ),
			array( 'user_id' => 317, 'phase' => 'post', 'date' => '2025-04-07 08:39:27', 'responses' => array( 'q1' => 7, 'q2' => 7, 'q3' => 7, 'q4' => 7, 'q5' => 7, 'q6' => 7, 'q7' => 7, 'q8' => 7, 'q9' => 7, 'q10' => 7, 'q11' => 7, 'q12' => 7, 'q13' => 7, 'q14' => 7, 'q15' => 7, 'q16' => 7 ) ),
			array( 'user_id' => 306, 'phase' => 'pre', 'date' => '2025-04-07 18:25:00', 'responses' => array( 'q1' => 7, 'q2' => 7, 'q3' => 6, 'q4' => 7, 'q5' => 6, 'q6' => 7, 'q7' => 7, 'q8' => 7, 'q9' => 6, 'q10' => 6, 'q11' => 7, 'q12' => 7, 'q13' => 7, 'q14' => 7, 'q15' => 7, 'q16' => 7 ) ),
			array( 'user_id' => 283, 'phase' => 'post', 'date' => '2025-04-08 11:17:52', 'responses' => array( 'q1' => 7, 'q2' => 7, 'q3' => 6, 'q4' => 6, 'q5' => 6, 'q6' => 7, 'q7' => 7, 'q8' => 7, 'q9' => 7, 'q10' => 6, 'q11' => 6, 'q12' => 7, 'q13' => 6, 'q14' => 7, 'q15' => 6, 'q16' => 6 ) ),
			array( 'user_id' => 284, 'phase' => 'post', 'date' => '2025-04-08 11:46:00', 'responses' => array( 'q1' => 6, 'q2' => 6, 'q3' => 6, 'q4' => 6, 'q5' => 6, 'q6' => 6, 'q7' => 6, 'q8' => 6, 'q9' => 6, 'q10' => 6, 'q11' => 6, 'q12' => 6, 'q13' => 6, 'q14' => 6, 'q15' => 6, 'q16' => 6 ) ),
			array( 'user_id' => 1408, 'phase' => 'pre', 'date' => '2025-04-11 09:30:00', 'responses' => array( 'q1' => 6, 'q2' => 6, 'q3' => 6, 'q4' => 6, 'q5' => 6, 'q6' => 6, 'q7' => 6, 'q8' => 6, 'q9' => 6, 'q10' => 6, 'q11' => 6, 'q12' => 6, 'q13' => 7, 'q14' => 6, 'q15' => 6, 'q16' => 6 ) ),
			array( 'user_id' => 341, 'phase' => 'pre', 'date' => '2025-04-11 13:00:00', 'responses' => array( 'q1' => 7, 'q2' => 6, 'q3' => 6, 'q4' => 6, 'q5' => 5, 'q6' => 6, 'q7' => 7, 'q8' => 6, 'q9' => 6, 'q10' => 6, 'q11' => 6, 'q12' => 6, 'q13' => 6, 'q14' => 6, 'q15' => 6, 'q16' => 6 ) ),
			array( 'user_id' => 286, 'phase' => 'post', 'date' => '2025-04-15 13:39:00', 'responses' => array( 'q1' => 6, 'q2' => 7, 'q3' => 5, 'q4' => 7, 'q5' => 7, 'q6' => 7, 'q7' => 7, 'q8' => 6, 'q9' => 7, 'q10' => 6, 'q11' => 6, 'q12' => 7, 'q13' => 7, 'q14' => 7, 'q15' => 7, 'q16' => 7 ) ),
			array( 'user_id' => 324, 'phase' => 'post', 'date' => '2025-04-15 14:47:07', 'responses' => array( 'q1' => 7, 'q2' => 7, 'q3' => 4, 'q4' => 6, 'q5' => 4, 'q6' => 5, 'q7' => 7, 'q8' => 7, 'q9' => 7, 'q10' => 7, 'q11' => 7, 'q12' => 7, 'q13' => 7, 'q14' => 7, 'q15' => 7, 'q16' => 7 ) ),
			array( 'user_id' => 342, 'phase' => 'pre', 'date' => '2025-04-16 15:16:00', 'responses' => array( 'q1' => 6, 'q2' => 6, 'q3' => 6, 'q4' => 6, 'q5' => 5, 'q6' => 6, 'q7' => 6, 'q8' => 6, 'q9' => 7, 'q10' => 6, 'q11' => 7, 'q12' => 7, 'q13' => 7, 'q14' => 7, 'q15' => 6, 'q16' => 6 ) ),
			array( 'user_id' => 341, 'phase' => 'post', 'date' => '2025-04-16 23:40:51', 'responses' => array( 'q1' => 6, 'q2' => 7, 'q3' => 7, 'q4' => 5, 'q5' => 5, 'q6' => 7, 'q7' => 7, 'q8' => 7, 'q9' => 3, 'q10' => 7, 'q11' => 7, 'q12' => 5, 'q13' => 6, 'q14' => 5, 'q15' => 6, 'q16' => 6 ) ),
			array( 'user_id' => 328, 'phase' => 'post', 'date' => '2025-04-17 12:53:21', 'responses' => array( 'q1' => 1, 'q2' => 3, 'q3' => 1, 'q4' => 4, 'q5' => 6, 'q6' => 6, 'q7' => 2, 'q8' => 2, 'q9' => 7, 'q10' => 4, 'q11' => 5, 'q12' => 6, 'q13' => 6, 'q14' => 7, 'q15' => 7, 'q16' => 6 ) ),
			array( 'user_id' => 327, 'phase' => 'post', 'date' => '2025-04-17 13:37:30', 'responses' => array( 'q1' => 6, 'q2' => 6, 'q3' => 6, 'q4' => 7, 'q5' => 7, 'q6' => 3, 'q7' => 6, 'q8' => 2, 'q9' => 4, 'q10' => 4, 'q11' => 7, 'q12' => 7, 'q13' => 4, 'q14' => 6, 'q15' => 1, 'q16' => 2 ) ),
			array( 'user_id' => 326, 'phase' => 'post', 'date' => '2025-04-17 13:39:23', 'responses' => array( 'q1' => 7, 'q2' => 6, 'q3' => 6, 'q4' => 6, 'q5' => 7, 'q6' => 6, 'q7' => 5, 'q8' => 7, 'q9' => 7, 'q10' => 7, 'q11' => 7, 'q12' => 7, 'q13' => 6, 'q14' => 7, 'q15' => 7, 'q16' => 7 ) ),
			array( 'user_id' => 300, 'phase' => 'post', 'date' => '2025-04-18 02:11:24', 'responses' => array( 'q1' => 5, 'q2' => 5, 'q3' => 5, 'q4' => 5, 'q5' => 5, 'q6' => 5, 'q7' => 5, 'q8' => 5, 'q9' => 6, 'q10' => 6, 'q11' => 6, 'q12' => 6, 'q13' => 6, 'q14' => 6, 'q15' => 6, 'q16' => 6 ) ),
			array( 'user_id' => 1408, 'phase' => 'post', 'date' => '2025-04-22 15:14:55', 'responses' => array( 'q1' => 7, 'q2' => 7, 'q3' => 6, 'q4' => 7, 'q5' => 6, 'q6' => 6, 'q7' => 6, 'q8' => 6, 'q9' => 6, 'q10' => 5, 'q11' => 6, 'q12' => 6, 'q13' => 6, 'q14' => 6, 'q15' => 6, 'q16' => 7 ) ),
			array( 'user_id' => 301, 'phase' => 'post', 'date' => '2025-04-23 08:06:00', 'responses' => array( 'q1' => 7, 'q2' => 6, 'q3' => 6, 'q4' => 6, 'q5' => 6, 'q6' => 7, 'q7' => 7, 'q8' => 7, 'q9' => 7, 'q10' => 7, 'q11' => 7, 'q12' => 7, 'q13' => 6, 'q14' => 6, 'q15' => 6, 'q16' => 6 ) ),
			array( 'user_id' => 1409, 'phase' => 'pre', 'date' => '2025-04-24 10:26:00', 'responses' => array( 'q1' => 1, 'q2' => 1, 'q3' => 1, 'q4' => 1, 'q5' => 1, 'q6' => 1, 'q7' => 1, 'q8' => 1, 'q9' => 1, 'q10' => 1, 'q11' => 1, 'q12' => 1, 'q13' => 1, 'q14' => 1, 'q15' => 1, 'q16' => 1 ) ),
			array( 'user_id' => 1409, 'phase' => 'pre', 'date' => '2025-04-24 10:36:00', 'responses' => array( 'q1' => 1, 'q2' => 1, 'q3' => 1, 'q4' => 1, 'q5' => 1, 'q6' => 1, 'q7' => 1, 'q8' => 1, 'q9' => 1, 'q10' => 1, 'q11' => 1, 'q12' => 1, 'q13' => 1, 'q14' => 1, 'q15' => 1, 'q16' => 1 ) ),
			array( 'user_id' => 349, 'phase' => 'post', 'date' => '2025-04-24 12:13:10', 'responses' => array( 'q1' => 6, 'q2' => 6, 'q3' => 6, 'q4' => 6, 'q5' => 6, 'q6' => 6, 'q7' => 6, 'q8' => 7, 'q9' => 7, 'q10' => 7, 'q11' => 7, 'q12' => 6, 'q13' => 6, 'q14' => 6, 'q15' => 6, 'q16' => 6 ) ),
			array( 'user_id' => 307, 'phase' => 'post', 'date' => '2025-04-25 13:10:19', 'responses' => array( 'q1' => 7, 'q2' => 6, 'q3' => 6, 'q4' => 6, 'q5' => 7, 'q6' => 6, 'q7' => 5, 'q8' => 7, 'q9' => 7, 'q10' => 7, 'q11' => 7, 'q12' => 7, 'q13' => 6, 'q14' => 7, 'q15' => 7, 'q16' => 7 ) ),
			array( 'user_id' => 298, 'phase' => 'pre', 'date' => '2025-04-29 14:51:00', 'responses' => array( 'q1' => 7, 'q2' => 7, 'q3' => 7, 'q4' => 7, 'q5' => 7, 'q6' => 7, 'q7' => 6, 'q8' => 7, 'q9' => 7, 'q10' => 7, 'q11' => 7, 'q12' => 7, 'q13' => 7, 'q14' => 6, 'q15' => 7, 'q16' => 6 ) ),
			array( 'user_id' => 306, 'phase' => 'post', 'date' => '2025-04-29 20:24:26', 'responses' => array( 'q1' => 7, 'q2' => 7, 'q3' => 7, 'q4' => 7, 'q5' => 7, 'q6' => 7, 'q7' => 7, 'q8' => 7, 'q9' => 5, 'q10' => 6, 'q11' => 6, 'q12' => 7, 'q13' => 7, 'q14' => 7, 'q15' => 7, 'q16' => 7 ) ),
			array( 'user_id' => 294, 'phase' => 'pre', 'date' => '2025-04-30 13:21:00', 'responses' => array( 'q1' => 6, 'q2' => 7, 'q3' => 7, 'q4' => 7, 'q5' => 7, 'q6' => 7, 'q7' => 7, 'q8' => 7, 'q9' => 6, 'q10' => 6, 'q11' => 6, 'q12' => 7, 'q13' => 7, 'q14' => 7, 'q15' => 7, 'q16' => 7 ) ),
			array( 'user_id' => 303, 'phase' => 'post', 'date' => '2025-04-30 22:02:59', 'responses' => array( 'q1' => 7, 'q2' => 6, 'q3' => 6, 'q4' => 6, 'q5' => 6, 'q6' => 7, 'q7' => 7, 'q8' => 7, 'q9' => 7, 'q10' => 7, 'q11' => 7, 'q12' => 7, 'q13' => 7, 'q14' => 6, 'q15' => 6, 'q16' => 7 ) ),
			array( 'user_id' => 358, 'phase' => 'pre', 'date' => '2025-05-01 13:17:00', 'responses' => array( 'q1' => 6, 'q2' => 6, 'q3' => 6, 'q4' => 6, 'q5' => 6, 'q6' => 6, 'q7' => 6, 'q8' => 6, 'q9' => 6, 'q10' => 6, 'q11' => 6, 'q12' => 6, 'q13' => 6, 'q14' => 6, 'q15' => 6, 'q16' => 6 ) ),
			array( 'user_id' => 313, 'phase' => 'post', 'date' => '2025-05-01 20:40:19', 'responses' => array( 'q1' => 7, 'q2' => 7, 'q3' => 7, 'q4' => 7, 'q5' => 6, 'q6' => 6, 'q7' => 7, 'q8' => 7, 'q9' => 7, 'q10' => 7, 'q11' => 7, 'q12' => 7, 'q13' => 7, 'q14' => 7, 'q15' => 7, 'q16' => 7 ) ),
			array( 'user_id' => 320, 'phase' => 'post', 'date' => '2025-05-05 02:03:36', 'responses' => array( 'q1' => 7, 'q2' => 7, 'q3' => 7, 'q4' => 7, 'q5' => 7, 'q6' => 7, 'q7' => 7, 'q8' => 7, 'q9' => 7, 'q10' => 7, 'q11' => 7, 'q12' => 7, 'q13' => 7, 'q14' => 7, 'q15' => 7, 'q16' => 7 ) ),
			array( 'user_id' => 342, 'phase' => 'post', 'date' => '2025-05-06 11:07:30', 'responses' => array( 'q1' => 6, 'q2' => 6, 'q3' => 6, 'q4' => 7, 'q5' => 4, 'q6' => 4, 'q7' => 6, 'q8' => 6, 'q9' => 6, 'q10' => 7, 'q11' => 6, 'q12' => 6, 'q13' => 7, 'q14' => 7, 'q15' => 4, 'q16' => 6 ) ),
			array( 'user_id' => 280, 'phase' => 'pre', 'date' => '2025-05-08 09:36:00', 'responses' => array( 'q1' => 7, 'q2' => 6, 'q3' => 6, 'q4' => 6, 'q5' => 5, 'q6' => 6, 'q7' => 6, 'q8' => 6, 'q9' => 6, 'q10' => 7, 'q11' => 6, 'q12' => 6, 'q13' => 6, 'q14' => 6, 'q15' => 6, 'q16' => 6 ) ),
			array( 'user_id' => 309, 'phase' => 'post', 'date' => '2025-05-08 13:28:57', 'responses' => array( 'q1' => 7, 'q2' => 7, 'q3' => 7, 'q4' => 7, 'q5' => 7, 'q6' => 7, 'q7' => 7, 'q8' => 7, 'q9' => 7, 'q10' => 7, 'q11' => 7, 'q12' => 7, 'q13' => 7, 'q14' => 7, 'q15' => 7, 'q16' => 7 ) ),
			array( 'user_id' => 287, 'phase' => 'post', 'date' => '2025-05-09 18:14:00', 'responses' => array( 'q1' => 6, 'q2' => 6, 'q3' => 6, 'q4' => 6, 'q5' => 6, 'q6' => 6, 'q7' => 6, 'q8' => 6, 'q9' => 6, 'q10' => 6, 'q11' => 6, 'q12' => 6, 'q13' => 6, 'q14' => 6, 'q15' => 7, 'q16' => 7 ) ),
			array( 'user_id' => 288, 'phase' => 'post', 'date' => '2025-05-12 16:16:00', 'responses' => array( 'q1' => 6, 'q2' => 6, 'q3' => 6, 'q4' => 6, 'q5' => 5, 'q6' => 5, 'q7' => 6, 'q8' => 6, 'q9' => 6, 'q10' => 6, 'q11' => 6, 'q12' => 6, 'q13' => 6, 'q14' => 6, 'q15' => 6, 'q16' => 6 ) ),
			array( 'user_id' => 330, 'phase' => 'post', 'date' => '2025-05-14 08:48:00', 'responses' => array( 'q1' => 6, 'q2' => 7, 'q3' => 6, 'q4' => 7, 'q5' => 5, 'q6' => 6, 'q7' => 7, 'q8' => 7, 'q9' => 7, 'q10' => 7, 'q11' => 7, 'q12' => 7, 'q13' => 7, 'q14' => 7, 'q15' => 7, 'q16' => 7 ) ),
			array( 'user_id' => 339, 'phase' => 'post', 'date' => '2025-05-14 13:10:19', 'responses' => array( 'q1' => 6, 'q2' => 6, 'q3' => 6, 'q4' => 6, 'q5' => 6, 'q6' => 7, 'q7' => 7, 'q8' => 7, 'q9' => 7, 'q10' => 7, 'q11' => 7, 'q12' => 7, 'q13' => 7, 'q14' => 7, 'q15' => 7, 'q16' => 7 ) ),
			array( 'user_id' => 319, 'phase' => 'post', 'date' => '2025-05-30 13:22:18', 'responses' => array( 'q1' => 7, 'q2' => 7, 'q3' => 6, 'q4' => 7, 'q5' => 6, 'q6' => 7, 'q7' => 5, 'q8' => 7, 'q9' => 6, 'q10' => 6, 'q11' => 6, 'q12' => 5, 'q13' => 6, 'q14' => 6, 'q15' => 2, 'q16' => 5 ) ),
			array( 'user_id' => 323, 'phase' => 'post', 'date' => '2025-05-30 21:00:37', 'responses' => array( 'q1' => 6, 'q2' => 6, 'q3' => 6, 'q4' => 6, 'q5' => 4, 'q6' => 4, 'q7' => 6, 'q8' => 4, 'q9' => 6, 'q10' => 7, 'q11' => 6, 'q12' => 7, 'q13' => 6, 'q14' => 6, 'q15' => 6, 'q16' => 6 ) ),
			array( 'user_id' => 340, 'phase' => 'post', 'date' => '2025-06-02 15:42:12', 'responses' => array( 'q1' => 7, 'q2' => 7, 'q3' => 7, 'q4' => 7, 'q5' => 6, 'q6' => 6, 'q7' => 2, 'q8' => 4, 'q9' => 2, 'q10' => 7, 'q11' => 7, 'q12' => 7, 'q13' => 7, 'q14' => 7, 'q15' => 6, 'q16' => 7 ) ),
			array( 'user_id' => 343, 'phase' => 'post', 'date' => '2025-06-04 15:22:36', 'responses' => array( 'q1' => 7, 'q2' => 7, 'q3' => 7, 'q4' => 7, 'q5' => 7, 'q6' => 7, 'q7' => 7, 'q8' => 7, 'q9' => 7, 'q10' => 7, 'q11' => 7, 'q12' => 7, 'q13' => 7, 'q14' => 7, 'q15' => 7, 'q16' => 7 ) ),
			array( 'user_id' => 285, 'phase' => 'post', 'date' => '2025-06-08 15:15:54', 'responses' => array( 'q1' => 6, 'q2' => 7, 'q3' => 6, 'q4' => 7, 'q5' => 6, 'q6' => 6, 'q7' => 7, 'q8' => 6, 'q9' => 7, 'q10' => 5, 'q11' => 6, 'q12' => 7, 'q13' => 6, 'q14' => 6, 'q15' => 6, 'q16' => 6 ) ),
			array( 'user_id' => 310, 'phase' => 'post', 'date' => '2025-06-08 16:09:45', 'responses' => array( 'q1' => 6, 'q2' => 4, 'q3' => 6, 'q4' => 7, 'q5' => 6, 'q6' => 4, 'q7' => 6, 'q8' => 7, 'q9' => 7, 'q10' => 6, 'q11' => 6, 'q12' => 6, 'q13' => 6, 'q14' => 6, 'q15' => 6, 'q16' => 6 ) ),
			array( 'user_id' => 289, 'phase' => 'post', 'date' => '2025-06-24 15:33:52', 'responses' => array( 'q1' => 5, 'q2' => 7, 'q3' => 6, 'q4' => 6, 'q5' => 5, 'q6' => 5, 'q7' => 6, 'q8' => 6, 'q9' => 1, 'q10' => 1, 'q11' => 6, 'q12' => 1, 'q13' => 5, 'q14' => 5, 'q15' => 5, 'q16' => 5 ) ),
			array( 'user_id' => 314, 'phase' => 'post', 'date' => '2025-06-26 11:52:00', 'responses' => array( 'q1' => 4, 'q2' => 7, 'q3' => 7, 'q4' => 7, 'q5' => 7, 'q6' => 7, 'q7' => 7, 'q8' => 7, 'q9' => 6, 'q10' => 7, 'q11' => 6, 'q12' => 7, 'q13' => 7, 'q14' => 7, 'q15' => 6, 'q16' => 7 ) ),
			array( 'user_id' => 302, 'phase' => 'post', 'date' => '2025-07-09 09:32:00', 'responses' => array( 'q1' => 7, 'q2' => 6, 'q3' => 6, 'q4' => 6, 'q5' => 7, 'q6' => 6, 'q7' => 6, 'q8' => 7, 'q9' => 6, 'q10' => 6, 'q11' => 7, 'q12' => 6, 'q13' => 6, 'q14' => 6, 'q15' => 6, 'q16' => 6 ) ),
			array( 'user_id' => 304, 'phase' => 'post', 'date' => '2025-07-11 13:48:00', 'responses' => array( 'q1' => 6, 'q2' => 6, 'q3' => 6, 'q4' => 6, 'q5' => 6, 'q6' => 6, 'q7' => 6, 'q8' => 6, 'q9' => 6, 'q10' => 6, 'q11' => 6, 'q12' => 6, 'q13' => 6, 'q14' => 6, 'q15' => 6, 'q16' => 6 ) ),
			array( 'user_id' => 318, 'phase' => 'post', 'date' => '2025-07-22 15:29:00', 'responses' => array( 'q1' => 5, 'q2' => 5, 'q3' => 5, 'q4' => 5, 'q5' => 5, 'q6' => 5, 'q7' => 5, 'q8' => 5, 'q9' => 5, 'q10' => 5, 'q11' => 5, 'q12' => 5, 'q13' => 5, 'q14' => 5, 'q15' => 5, 'q16' => 5 ) ),
			array( 'user_id' => 296, 'phase' => 'post', 'date' => '2025-07-23 20:32:00', 'responses' => array( 'q1' => 7, 'q2' => 7, 'q3' => 7, 'q4' => 7, 'q5' => 7, 'q6' => 7, 'q7' => 7, 'q8' => 7, 'q9' => 6, 'q10' => 6, 'q11' => 6, 'q12' => 6, 'q13' => 7, 'q14' => 6, 'q15' => 6, 'q16' => 6 ) ),
			array( 'user_id' => 344, 'phase' => 'post', 'date' => '2025-07-24 15:03:00', 'responses' => array( 'q1' => 6, 'q2' => 6, 'q3' => 6, 'q4' => 6, 'q5' => 6, 'q6' => 6, 'q7' => 6, 'q8' => 6, 'q9' => 6, 'q10' => 6, 'q11' => 6, 'q12' => 6, 'q13' => 6, 'q14' => 6, 'q15' => 6, 'q16' => 6 ) ),
			array( 'user_id' => 348, 'phase' => 'post', 'date' => '2025-07-24 16:35:00', 'responses' => array( 'q1' => 7, 'q2' => 6, 'q3' => 7, 'q4' => 7, 'q5' => 7, 'q6' => 7, 'q7' => 7, 'q8' => 7, 'q9' => 7, 'q10' => 7, 'q11' => 7, 'q12' => 7, 'q13' => 7, 'q14' => 7, 'q15' => 7, 'q16' => 7 ) ),
			array( 'user_id' => 325, 'phase' => 'post', 'date' => '2025-07-25 18:40:00', 'responses' => array( 'q1' => 7, 'q2' => 7, 'q3' => 7, 'q4' => 7, 'q5' => 7, 'q6' => 7, 'q7' => 7, 'q8' => 7, 'q9' => 7, 'q10' => 7, 'q11' => 7, 'q12' => 7, 'q13' => 7, 'q14' => 7, 'q15' => 7, 'q16' => 7 ) ),
			array( 'user_id' => 299, 'phase' => 'post', 'date' => '2025-07-29 11:39:00', 'responses' => array( 'q1' => 6, 'q2' => 6, 'q3' => 6, 'q4' => 6, 'q5' => 6, 'q6' => 6, 'q7' => 6, 'q8' => 6, 'q9' => 6, 'q10' => 6, 'q11' => 6, 'q12' => 6, 'q13' => 6, 'q14' => 6, 'q15' => 6, 'q16' => 6 ) ),
			array( 'user_id' => 282, 'phase' => 'post', 'date' => '2025-07-29 17:55:00', 'responses' => array( 'q1' => 7, 'q2' => 7, 'q3' => 7, 'q4' => 7, 'q5' => 7, 'q6' => 7, 'q7' => 7, 'q8' => 7, 'q9' => 7, 'q10' => 7, 'q11' => 7, 'q12' => 7, 'q13' => 7, 'q14' => 7, 'q15' => 7, 'q16' => 7 ) ),
		);
	}

	// ------------------------------------------------------------------
	// Step 13: Completion rollups
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

		WP_CLI::log( "  [13] Completion rollups computed: {$count}" . ( $errors ? " ({$errors} errors)" : '' ) );
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

		// Delete classrooms for ELCPB schools.
		$district_id = $wpdb->get_var(
			"SELECT orgunit_id FROM {$wpdb->prefix}hl_orgunit WHERE name = 'ELC Palm Beach County' AND orgunit_type = 'district' LIMIT 1"
		);
		if ( $district_id ) {
			$school_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT orgunit_id FROM {$wpdb->prefix}hl_orgunit WHERE parent_orgunit_id = %d AND orgunit_type = 'school'",
					$district_id
				)
			);
			if ( ! empty( $school_ids ) ) {
				$in_schools = implode( ',', array_map( 'intval', $school_ids ) );
				$wpdb->query( "DELETE FROM {$wpdb->prefix}hl_classroom WHERE school_id IN ({$in_schools})" );
				WP_CLI::log( '  Deleted ELCPB classrooms.' );
			}

			// Delete org units.
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
