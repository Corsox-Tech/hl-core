<?php
/**
 * WP-CLI command: wp hl-core seed-beginnings
 *
 * Creates a complete "Beginnings School" test dataset for end-to-end testing.
 * Includes Partnership, District, 4 Schools, 6 Teams, 33 users, 3 pathways
 * with Y2-style component types, 90% Cycle 1 completion, and a Cycle 2
 * CSV roster for import testing.
 *
 * Use --clean to remove all Beginnings data first.
 *
 * @package HL_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HL_CLI_Seed_Beginnings {

	const PARTNERSHIP_CODE = 'BEGINNINGS-2025';
	const CYCLE_CODE       = 'BEGINNINGS-Y1-2025';
	const META_KEY         = '_hl_beginnings_seed';

	// Real LearnDash course IDs (shared courses).
	const TC0   = 31037;
	const TC1   = 30280;
	const TC2   = 30284;
	const TC3   = 30286;
	const TC4   = 30288;
	const MC1   = 30293;
	const MC2   = 30295;
	const TC1_S = 31332;
	const TC2_S = 31333;
	const TC3_S = 31334;
	const TC4_S = 31335;
	const MC1_S = 31387;
	const MC2_S = 31388;

	/**
	 * School definitions: key => [name, leader_email, leader_display, teams_count].
	 */
	private static function get_schools() {
		return array(
			'boston'    => array(
				'name'           => 'Beginnings Boston',
				'leader_email'   => 'boston-school-leader@yopmail.com',
				'leader_display' => 'Beth Boston-Leader',
				'leader_first'   => 'Beth',
				'leader_last'    => 'Boston-Leader',
				'teams'          => 2,
			),
			'florida'  => array(
				'name'           => 'Beginnings Florida',
				'leader_email'   => 'florida-school-leader@yopmail.com',
				'leader_display' => 'Fiona Florida-Leader',
				'leader_first'   => 'Fiona',
				'leader_last'    => 'Florida-Leader',
				'teams'          => 2,
			),
			'texas'    => array(
				'name'           => 'Beginnings Texas',
				'leader_email'   => 'texas-school-leader@yopmail.com',
				'leader_display' => 'Tina Texas-Leader',
				'leader_first'   => 'Tina',
				'leader_last'    => 'Texas-Leader',
				'teams'          => 1,
			),
			'colombia' => array(
				'name'           => 'Beginnings Colombia',
				'leader_email'   => 'colombia-school-leader@yopmail.com',
				'leader_display' => 'Carmen Colombia-Leader',
				'leader_first'   => 'Carmen',
				'leader_last'    => 'Colombia-Leader',
				'teams'          => 1,
			),
		);
	}

	/**
	 * Team definitions: [school_key, team_num, mentor_email, mentor_display, teacher_count, teachers].
	 */
	private static function get_teams() {
		return array(
			array(
				'school' => 'boston', 'num' => 1,
				'mentor_email' => 'mentor-T_01-boston@yopmail.com',
				'mentor_display' => 'Marco Mentor T01-Boston',
				'mentor_first' => 'Marco', 'mentor_last' => 'Mentor-T01-Boston',
				'teachers' => array(
					array( 'john_teacher-T_01-boston@yopmail.com',  'John',   'Teacher-T01-Boston' ),
					array( 'mary_teacher-T_01-boston@yopmail.com',  'Mary',   'Teacher-T01-Boston' ),
					array( 'steve_teacher-T_01-boston@yopmail.com', 'Steve',  'Teacher-T01-Boston' ),
					array( 'lisa_teacher-T_01-boston@yopmail.com',  'Lisa',   'Teacher-T01-Boston' ),
				),
			),
			array(
				'school' => 'boston', 'num' => 2,
				'mentor_email' => 'mentor-T_02-boston@yopmail.com',
				'mentor_display' => 'Monica Mentor T02-Boston',
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
				'mentor_display' => 'Marta Mentor T01-Florida',
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
				'mentor_display' => 'Miguel Mentor T02-Florida',
				'mentor_first' => 'Miguel', 'mentor_last' => 'Mentor-T02-Florida',
				'teachers' => array(
					array( 'tom_teacher-T_02-florida@yopmail.com',  'Tom',  'Teacher-T02-Florida' ),
					array( 'nina_teacher-T_02-florida@yopmail.com', 'Nina', 'Teacher-T02-Florida' ),
					array( 'leo_teacher-T_02-florida@yopmail.com',  'Leo',  'Teacher-T02-Florida' ),
				),
			),
			array(
				'school' => 'texas', 'num' => 1,
				'mentor_email' => 'mentor-T_01-texas@yopmail.com',
				'mentor_display' => 'Manuel Mentor T01-Texas',
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
				'mentor_display' => 'Maria Mentor T01-Colombia',
				'mentor_first' => 'Maria', 'mentor_last' => 'Mentor-T01-Colombia',
				'teachers' => array(
					array( 'ben_teacher-T_01-colombia@yopmail.com',   'Ben',   'Teacher-T01-Colombia' ),
					array( 'chloe_teacher-T_01-colombia@yopmail.com', 'Chloe', 'Teacher-T01-Colombia' ),
					array( 'zoe_teacher-T_01-colombia@yopmail.com',   'Zoe',   'Teacher-T01-Colombia' ),
				),
			),
		);
	}

	/**
	 * Classroom definitions: school_key => [[name, age_band], ...].
	 */
	private static function get_classrooms() {
		return array(
			'boston'    => array(
				array( 'name' => 'Boston - Infant Room',   'age_band' => 'infant' ),
				array( 'name' => 'Boston - Toddler Room',  'age_band' => 'toddler' ),
			),
			'florida'  => array(
				array( 'name' => 'Florida - Preschool Room', 'age_band' => 'preschool' ),
				array( 'name' => 'Florida - Pre-K Room',     'age_band' => 'pre_k' ),
			),
			'texas'    => array(
				array( 'name' => 'Texas - Toddler Room', 'age_band' => 'toddler' ),
			),
			'colombia' => array(
				array( 'name' => 'Colombia - Infant Room',    'age_band' => 'infant' ),
				array( 'name' => 'Colombia - Preschool Room', 'age_band' => 'preschool' ),
			),
		);
	}

	// ------------------------------------------------------------------
	// Registration
	// ------------------------------------------------------------------

	public static function register() {
		WP_CLI::add_command( 'hl-core seed-beginnings', array( new self(), 'run' ) );
	}

	/**
	 * Seed Beginnings School test data.
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
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Named args.
	 */
	public function run( $args, $assoc_args ) {
		$clean = isset( $assoc_args['clean'] );

		if ( $clean ) {
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

		// Step 1: Partnership
		$partnership_id = $this->seed_partnership();

		// Step 2: Org structure (district + schools)
		$org = $this->seed_orgunits();

		// Step 3: Cycle
		$cycle_id = $this->seed_cycle( $partnership_id, $org );

		// Step 4: Classrooms
		$classrooms = $this->seed_classrooms( $org['schools'] );

		// Step 5: WP Users (all roles)
		$users = $this->seed_users();

		// Step 6: Enrollments
		$enrollments = $this->seed_enrollments( $users, $cycle_id, $org );

		// Step 7: Teams
		$this->seed_teams( $cycle_id, $org['schools'], $enrollments );

		// Step 8: Pathways + Components
		$pathways = $this->seed_pathways( $cycle_id );

		// Step 9: Assign pathways to enrollments
		$this->assign_pathways( $enrollments, $pathways );

		// Step 10: Teaching assignments
		$this->seed_teaching_assignments( $enrollments, $classrooms, $org['schools'] );

		// Step 11: Children
		$this->seed_children( $classrooms, $org['schools'] );

		// Step 12: Freeze child age groups
		$frozen = HL_Child_Snapshot_Service::freeze_age_groups( $cycle_id );
		WP_CLI::log( "  [12] Frozen age group snapshots: {$frozen}" );

		// Step 13: Component states (90% completion)
		$this->seed_component_states( $enrollments, $pathways );

		// Step 14: Completion rollups
		$this->seed_rollups( $enrollments );

		// Step 15: Generate Cycle 2 CSV roster
		$csv_path = $this->generate_cycle2_csv( $users, $enrollments );

		WP_CLI::line( '' );
		WP_CLI::success( 'Beginnings data seeded successfully!' );
		WP_CLI::line( '' );
		WP_CLI::line( 'Summary:' );
		WP_CLI::line( "  Partnership: {$partnership_id}" );
		WP_CLI::line( "  Cycle:       {$cycle_id} (code: " . self::CYCLE_CODE . ')' );
		WP_CLI::line( "  District:    {$org['district_id']}" );
		WP_CLI::line( '  Schools:     ' . count( $org['schools'] ) );
		WP_CLI::line( '  Classrooms:  ' . count( $classrooms ) );
		WP_CLI::line( '  Users:       ' . $users['count'] );
		WP_CLI::line( '  Enrollments: ' . count( $enrollments['all'] ) );
		WP_CLI::line( '  Pathways:    ' . count( $pathways ) );
		if ( $csv_path ) {
			WP_CLI::line( "  Cycle 2 CSV: {$csv_path}" );
		}
		WP_CLI::line( '' );
	}

	// ------------------------------------------------------------------
	// Idempotency
	// ------------------------------------------------------------------

	private function data_exists() {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT cycle_id FROM {$wpdb->prefix}hl_cycle WHERE cycle_code = %s LIMIT 1",
			self::CYCLE_CODE
		) );
	}

	// ------------------------------------------------------------------
	// Step 1: Partnership
	// ------------------------------------------------------------------

	private function seed_partnership() {
		global $wpdb;

		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT partnership_id FROM {$wpdb->prefix}hl_partnership WHERE partnership_code = %s LIMIT 1",
			self::PARTNERSHIP_CODE
		) );
		if ( $existing ) {
			WP_CLI::log( "  [1] Partnership already exists: id={$existing}" );
			return (int) $existing;
		}

		$wpdb->insert( $wpdb->prefix . 'hl_partnership', array(
			'partnership_name' => 'Beginnings School - 2025-2026',
			'partnership_code' => self::PARTNERSHIP_CODE,
			'description'      => 'Test partnership for end-to-end form and import testing.',
			'status'           => 'active',
		) );
		$id = $wpdb->insert_id;
		WP_CLI::log( "  [1] Partnership created: id={$id}" );
		return $id;
	}

	// ------------------------------------------------------------------
	// Step 2: Org structure
	// ------------------------------------------------------------------

	private function seed_orgunits() {
		$repo = new HL_OrgUnit_Repository();

		$district_id = $repo->create( array(
			'name'         => 'Beginnings School District',
			'orgunit_type' => 'district',
		) );

		$schools = array();
		foreach ( self::get_schools() as $key => $def ) {
			$school_id = $repo->create( array(
				'name'              => $def['name'],
				'orgunit_type'      => 'school',
				'parent_orgunit_id' => $district_id,
			) );
			$schools[ $key ] = $school_id;
		}

		WP_CLI::log( "  [2] Org units: district={$district_id}, schools=" . implode( ',', $schools ) );

		return array(
			'district_id' => $district_id,
			'schools'     => $schools,
		);
	}

	// ------------------------------------------------------------------
	// Step 3: Cycle
	// ------------------------------------------------------------------

	private function seed_cycle( $partnership_id, $org ) {
		global $wpdb;
		$repo = new HL_Cycle_Repository();

		$cycle_id = $repo->create( array(
			'cycle_name'     => 'Beginnings - Cycle 1 (2025)',
			'cycle_code'     => self::CYCLE_CODE,
			'partnership_id' => $partnership_id,
			'cycle_type'     => 'program',
			'district_id'    => $org['district_id'],
			'status'         => 'active',
			'start_date'     => '2025-01-15',
			'end_date'       => '2025-09-30',
		) );

		foreach ( $org['schools'] as $school_id ) {
			$wpdb->insert( $wpdb->prefix . 'hl_cycle_school', array(
				'cycle_id'  => $cycle_id,
				'school_id' => $school_id,
			) );
		}

		WP_CLI::log( "  [3] Cycle created: id={$cycle_id}" );
		return $cycle_id;
	}

	// ------------------------------------------------------------------
	// Step 4: Classrooms
	// ------------------------------------------------------------------

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

		WP_CLI::log( '  [4] Classrooms created: ' . count( $all ) );
		return $all;
	}

	// ------------------------------------------------------------------
	// Step 5: WP Users
	// ------------------------------------------------------------------

	private function seed_users() {
		$users = array(
			'district_leader' => null,
			'school_leaders'  => array(), // keyed by school_key
			'mentors'         => array(), // keyed by team index
			'teachers'        => array(), // keyed by team index => array of user data
			'count'           => 0,
		);

		// District leader.
		$users['district_leader'] = $this->create_user(
			'district-lead-beginnings@yopmail.com',
			'Diana', 'District-Lead-Beginnings', 'subscriber'
		);
		$users['count']++;

		// School leaders.
		foreach ( self::get_schools() as $key => $def ) {
			$users['school_leaders'][ $key ] = $this->create_user(
				$def['leader_email'], $def['leader_first'], $def['leader_last'], 'subscriber'
			);
			$users['count']++;
		}

		// Mentors + Teachers per team.
		foreach ( self::get_teams() as $t_idx => $team ) {
			$users['mentors'][ $t_idx ] = $this->create_user(
				$team['mentor_email'], $team['mentor_first'], $team['mentor_last'], 'subscriber'
			);
			$users['count']++;

			$users['teachers'][ $t_idx ] = array();
			foreach ( $team['teachers'] as $t ) {
				$users['teachers'][ $t_idx ][] = $this->create_user(
					$t[0], $t[1], $t[2], 'subscriber'
				);
				$users['count']++;
			}
		}

		WP_CLI::log( "  [5] WP users created: {$users['count']}" );
		return $users;
	}

	/**
	 * Create a WP user with email as password.
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
			'user_pass'    => $email, // Password = email for easy test login.
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

	// ------------------------------------------------------------------
	// Step 6: Enrollments
	// ------------------------------------------------------------------

	private function seed_enrollments( $users, $cycle_id, $org ) {
		$repo = new HL_Enrollment_Repository();
		$enrollments = array(
			'district_leader' => null,
			'school_leaders'  => array(),
			'mentors'         => array(),
			'teachers'        => array(), // keyed by team index => array
			'all'             => array(),
		);

		$district_id = $org['district_id'];
		$schools     = $org['schools'];
		$teams_def   = self::get_teams();

		// District leader → Streamlined pathway.
		$uid = $users['district_leader']['user_id'];
		if ( $uid ) {
			$eid = $repo->create( array(
				'user_id'     => $uid,
				'cycle_id'    => $cycle_id,
				'roles'       => array( 'district_leader' ),
				'status'      => 'active',
				'district_id' => $district_id,
			) );
			$enrollments['district_leader'] = array( 'enrollment_id' => $eid, 'user_id' => $uid, 'role' => 'district_leader' );
			$enrollments['all'][] = $enrollments['district_leader'];
		}

		// School leaders → Streamlined pathway.
		foreach ( self::get_schools() as $key => $def ) {
			$uid = $users['school_leaders'][ $key ]['user_id'];
			if ( ! $uid ) continue;
			$eid = $repo->create( array(
				'user_id'     => $uid,
				'cycle_id'    => $cycle_id,
				'roles'       => array( 'school_leader' ),
				'status'      => 'active',
				'school_id'   => $schools[ $key ],
				'district_id' => $district_id,
			) );
			$enrollments['school_leaders'][ $key ] = array(
				'enrollment_id' => $eid, 'user_id' => $uid, 'role' => 'school_leader', 'school_key' => $key,
			);
			$enrollments['all'][] = $enrollments['school_leaders'][ $key ];
		}

		// Mentors + Teachers per team.
		foreach ( $teams_def as $t_idx => $team ) {
			$school_id = $schools[ $team['school'] ];

			// Mentor.
			$uid = $users['mentors'][ $t_idx ]['user_id'];
			if ( $uid ) {
				$eid = $repo->create( array(
					'user_id'     => $uid,
					'cycle_id'    => $cycle_id,
					'roles'       => array( 'mentor' ),
					'status'      => 'active',
					'school_id'   => $school_id,
					'district_id' => $district_id,
				) );
				$enrollments['mentors'][ $t_idx ] = array(
					'enrollment_id' => $eid, 'user_id' => $uid, 'role' => 'mentor',
					'school_key' => $team['school'], 'team_idx' => $t_idx,
				);
				$enrollments['all'][] = $enrollments['mentors'][ $t_idx ];
			}

			// Teachers.
			$enrollments['teachers'][ $t_idx ] = array();
			foreach ( $users['teachers'][ $t_idx ] as $t_data ) {
				$uid = $t_data['user_id'];
				if ( ! $uid ) continue;
				$eid = $repo->create( array(
					'user_id'     => $uid,
					'cycle_id'    => $cycle_id,
					'roles'       => array( 'teacher' ),
					'status'      => 'active',
					'school_id'   => $school_id,
					'district_id' => $district_id,
				) );
				$entry = array(
					'enrollment_id' => $eid, 'user_id' => $uid, 'role' => 'teacher',
					'school_key' => $team['school'], 'team_idx' => $t_idx,
					'email' => $t_data['email'], 'first_name' => $t_data['first_name'], 'last_name' => $t_data['last_name'],
				);
				$enrollments['teachers'][ $t_idx ][] = $entry;
				$enrollments['all'][] = $entry;
			}
		}

		WP_CLI::log( '  [6] Enrollments created: ' . count( $enrollments['all'] ) );
		return $enrollments;
	}

	// ------------------------------------------------------------------
	// Step 7: Teams
	// ------------------------------------------------------------------

	private function seed_teams( $cycle_id, $schools, $enrollments ) {
		$svc   = new HL_Team_Service();
		$teams = self::get_teams();
		$count = 0;

		foreach ( $teams as $t_idx => $team ) {
			$school_id = $schools[ $team['school'] ];
			$team_name = "Team " . str_pad( $team['num'], 2, '0', STR_PAD_LEFT )
						. ' - ' . ucfirst( $team['school'] ) . ' - Beginnings';

			$team_id = $svc->create_team( array(
				'team_name' => $team_name,
				'cycle_id'  => $cycle_id,
				'school_id' => $school_id,
			) );

			if ( is_wp_error( $team_id ) ) {
				WP_CLI::warning( "Team creation error: " . $team_id->get_error_message() );
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

			$count++;
		}

		WP_CLI::log( "  [7] Teams created: {$count}" );
	}

	// ------------------------------------------------------------------
	// Step 8: Pathways + Components
	// ------------------------------------------------------------------

	private function seed_pathways( $cycle_id ) {
		$svc      = new HL_Pathway_Service();
		$pathways = array();

		$pathways['teacher']     = $this->create_teacher_phase1( $svc, $cycle_id );
		$pathways['mentor']      = $this->create_mentor_phase1( $svc, $cycle_id );
		$pathways['streamlined'] = $this->create_streamlined_phase1( $svc, $cycle_id );

		WP_CLI::log( '  [8] Pathways created: ' . count( $pathways ) );
		return $pathways;
	}

	/**
	 * Shorthand for component creation.
	 */
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
			'component_id' => $component_id,
			'prereq_type'  => 'all_of',
		) );
		$group_id = $wpdb->insert_id;
		$wpdb->insert( $wpdb->prefix . 'hl_component_prereq_item', array(
			'group_id'                  => $group_id,
			'prerequisite_component_id' => $prereq_id,
		) );
	}

	// Teacher Phase 1: 17 components (same as Y2 V2)
	private function create_teacher_phase1( $svc, $cid ) {
		$pid = $svc->create_pathway( array(
			'pathway_name'  => 'B2E Teacher Phase 1',
			'cycle_id'      => $cid,
			'target_roles'  => array( 'teacher' ),
			'active_status' => 1,
		) );

		$n = 0;
		$component_ids = array();
		$component_ids[] = $tsa_pre = $this->cmp( $svc, $pid, $cid, 'Teacher Self-Assessment (Pre)',  'teacher_self_assessment', ++$n, array( 'phase' => 'pre' ) );
		$component_ids[] = $this->cmp( $svc, $pid, $cid, 'Child Assessment (Pre)',                    'child_assessment',        ++$n, array( 'phase' => 'pre' ) );
		$component_ids[] = $tc0 = $this->cmp( $svc, $pid, $cid, 'TC0: Welcome',                      'learndash_course',        ++$n, array( 'course_id' => self::TC0 ) );
		$component_ids[] = $tc1 = $this->cmp( $svc, $pid, $cid, 'TC1: Intro to begin to ECSEL',      'learndash_course',        ++$n, array( 'course_id' => self::TC1 ) );
		$component_ids[] = $this->cmp( $svc, $pid, $cid, 'Self-Reflection #1',                       'self_reflection',         ++$n, array( 'visit_number' => 1 ) );
		$component_ids[] = $this->cmp( $svc, $pid, $cid, 'Reflective Practice Session #1',            'reflective_practice_session', ++$n, array( 'session_number' => 1 ) );
		$component_ids[] = $tc2 = $this->cmp( $svc, $pid, $cid, 'TC2: Your Own Emotionality',        'learndash_course',        ++$n, array( 'course_id' => self::TC2 ) );
		$component_ids[] = $this->cmp( $svc, $pid, $cid, 'Self-Reflection #2',                       'self_reflection',         ++$n, array( 'visit_number' => 2 ) );
		$component_ids[] = $this->cmp( $svc, $pid, $cid, 'Reflective Practice Session #2',            'reflective_practice_session', ++$n, array( 'session_number' => 2 ) );
		$component_ids[] = $tc3 = $this->cmp( $svc, $pid, $cid, 'TC3: Getting to Know Emotion',      'learndash_course',        ++$n, array( 'course_id' => self::TC3 ) );
		$component_ids[] = $this->cmp( $svc, $pid, $cid, 'Self-Reflection #3',                       'self_reflection',         ++$n, array( 'visit_number' => 3 ) );
		$component_ids[] = $this->cmp( $svc, $pid, $cid, 'Reflective Practice Session #3',            'reflective_practice_session', ++$n, array( 'session_number' => 3 ) );
		$component_ids[] = $tc4 = $this->cmp( $svc, $pid, $cid, 'TC4: Emotion in the Heat of the Moment', 'learndash_course',   ++$n, array( 'course_id' => self::TC4 ) );
		$component_ids[] = $this->cmp( $svc, $pid, $cid, 'Self-Reflection #4',                       'self_reflection',         ++$n, array( 'visit_number' => 4 ) );
		$component_ids[] = $this->cmp( $svc, $pid, $cid, 'Reflective Practice Session #4',            'reflective_practice_session', ++$n, array( 'session_number' => 4 ) );
		$component_ids[] = $this->cmp( $svc, $pid, $cid, 'Child Assessment (Post)',                   'child_assessment',        ++$n, array( 'phase' => 'post' ) );
		$component_ids[] = $this->cmp( $svc, $pid, $cid, 'Teacher Self-Assessment (Post)',            'teacher_self_assessment', ++$n, array( 'phase' => 'post' ) );

		$this->add_prereq( $tc0, $tsa_pre );
		$this->add_prereq( $tc1, $tc0 );
		$this->add_prereq( $tc2, $tc1 );
		$this->add_prereq( $tc3, $tc2 );
		$this->add_prereq( $tc4, $tc3 );

		WP_CLI::log( "    Teacher Phase 1: pathway_id={$pid}, {$n} components" );
		return array( 'pathway_id' => $pid, 'component_count' => $n, 'component_ids' => $component_ids );
	}

	// Mentor Phase 1: 19 components (same as Y2 V2)
	private function create_mentor_phase1( $svc, $cid ) {
		$pid = $svc->create_pathway( array(
			'pathway_name'  => 'B2E Mentor Phase 1',
			'cycle_id'      => $cid,
			'target_roles'  => array( 'mentor' ),
			'active_status' => 1,
		) );

		$n = 0;
		$component_ids = array();
		$component_ids[] = $tsa_pre = $this->cmp( $svc, $pid, $cid, 'Teacher Self-Assessment (Pre)',               'teacher_self_assessment',     ++$n, array( 'phase' => 'pre' ) );
		$component_ids[] = $this->cmp( $svc, $pid, $cid, 'Child Assessment (Pre)',                                  'child_assessment',            ++$n, array( 'phase' => 'pre' ) );
		$component_ids[] = $tc0 = $this->cmp( $svc, $pid, $cid, 'TC0: Welcome',                                    'learndash_course',            ++$n, array( 'course_id' => self::TC0 ) );
		$component_ids[] = $tc1 = $this->cmp( $svc, $pid, $cid, 'TC1: Intro to begin to ECSEL',                    'learndash_course',            ++$n, array( 'course_id' => self::TC1 ) );
		$component_ids[] = $this->cmp( $svc, $pid, $cid, 'Coaching Session #1',                                    'coaching_session_attendance', ++$n, array( 'session_number' => 1 ) );
		$component_ids[] = $mc1 = $this->cmp( $svc, $pid, $cid, 'MC1: Introduction to Reflective Practice',        'learndash_course',            ++$n, array( 'course_id' => self::MC1 ) );
		$component_ids[] = $this->cmp( $svc, $pid, $cid, 'Reflective Practice Session #1',                         'reflective_practice_session', ++$n, array( 'session_number' => 1 ) );
		$component_ids[] = $tc2 = $this->cmp( $svc, $pid, $cid, 'TC2: Your Own Emotionality',                      'learndash_course',            ++$n, array( 'course_id' => self::TC2 ) );
		$component_ids[] = $this->cmp( $svc, $pid, $cid, 'Coaching Session #2',                                    'coaching_session_attendance', ++$n, array( 'session_number' => 2 ) );
		$component_ids[] = $this->cmp( $svc, $pid, $cid, 'Reflective Practice Session #2',                         'reflective_practice_session', ++$n, array( 'session_number' => 2 ) );
		$component_ids[] = $tc3 = $this->cmp( $svc, $pid, $cid, 'TC3: Getting to Know Emotion',                    'learndash_course',            ++$n, array( 'course_id' => self::TC3 ) );
		$component_ids[] = $this->cmp( $svc, $pid, $cid, 'Coaching Session #3',                                    'coaching_session_attendance', ++$n, array( 'session_number' => 3 ) );
		$component_ids[] = $mc2 = $this->cmp( $svc, $pid, $cid, 'MC2: A Deeper Dive into Reflective Practice',     'learndash_course',            ++$n, array( 'course_id' => self::MC2 ) );
		$component_ids[] = $this->cmp( $svc, $pid, $cid, 'Reflective Practice Session #3',                         'reflective_practice_session', ++$n, array( 'session_number' => 3 ) );
		$component_ids[] = $tc4 = $this->cmp( $svc, $pid, $cid, 'TC4: Emotion in the Heat of the Moment',          'learndash_course',            ++$n, array( 'course_id' => self::TC4 ) );
		$component_ids[] = $this->cmp( $svc, $pid, $cid, 'Coaching Session #4',                                    'coaching_session_attendance', ++$n, array( 'session_number' => 4 ) );
		$component_ids[] = $this->cmp( $svc, $pid, $cid, 'Reflective Practice Session #4',                         'reflective_practice_session', ++$n, array( 'session_number' => 4 ) );
		$component_ids[] = $this->cmp( $svc, $pid, $cid, 'Child Assessment (Post)',                                 'child_assessment',            ++$n, array( 'phase' => 'post' ) );
		$component_ids[] = $this->cmp( $svc, $pid, $cid, 'Teacher Self-Assessment (Post)',                          'teacher_self_assessment',     ++$n, array( 'phase' => 'post' ) );

		$this->add_prereq( $tc0, $tsa_pre );
		$this->add_prereq( $tc1, $tc0 );
		$this->add_prereq( $mc1, $tc1 );
		$this->add_prereq( $tc2, $mc1 );
		$this->add_prereq( $tc3, $tc2 );
		$this->add_prereq( $mc2, $tc3 );
		$this->add_prereq( $tc4, $mc2 );

		WP_CLI::log( "    Mentor Phase 1: pathway_id={$pid}, {$n} components" );
		return array( 'pathway_id' => $pid, 'component_count' => $n, 'component_ids' => $component_ids );
	}

	// Streamlined Phase 1: 11 components (same as Y2 V2)
	private function create_streamlined_phase1( $svc, $cid ) {
		$pid = $svc->create_pathway( array(
			'pathway_name'  => 'B2E Streamlined Phase 1',
			'cycle_id'      => $cid,
			'target_roles'  => array( 'school_leader' ),
			'active_status' => 1,
		) );

		$n = 0;
		$component_ids = array();
		$component_ids[] = $this->cmp( $svc, $pid, $cid, 'TC0: Welcome',                                               'learndash_course', ++$n, array( 'course_id' => self::TC0 ) );
		$component_ids[] = $this->cmp( $svc, $pid, $cid, 'TC1: Intro (Streamlined)',                                    'learndash_course', ++$n, array( 'course_id' => self::TC1_S ) );
		$component_ids[] = $this->cmp( $svc, $pid, $cid, 'MC1: Intro to Reflective Practice (Streamlined)',              'learndash_course', ++$n, array( 'course_id' => self::MC1_S ) );
		$component_ids[] = $this->cmp( $svc, $pid, $cid, 'Classroom Visit #1',                                          'classroom_visit',  ++$n, array( 'visit_number' => 1 ) );
		$component_ids[] = $this->cmp( $svc, $pid, $cid, 'TC2: Your Own Emotionality (Streamlined)',                     'learndash_course', ++$n, array( 'course_id' => self::TC2_S ) );
		$component_ids[] = $this->cmp( $svc, $pid, $cid, 'Classroom Visit #2',                                          'classroom_visit',  ++$n, array( 'visit_number' => 2 ) );
		$component_ids[] = $this->cmp( $svc, $pid, $cid, 'TC3: Getting to Know Emotion (Streamlined)',                   'learndash_course', ++$n, array( 'course_id' => self::TC3_S ) );
		$component_ids[] = $this->cmp( $svc, $pid, $cid, 'Classroom Visit #3',                                          'classroom_visit',  ++$n, array( 'visit_number' => 3 ) );
		$component_ids[] = $this->cmp( $svc, $pid, $cid, 'TC4: Emotion in the Heat of the Moment (Streamlined)',         'learndash_course', ++$n, array( 'course_id' => self::TC4_S ) );
		$component_ids[] = $this->cmp( $svc, $pid, $cid, 'MC2: Deeper Dive Reflective Practice (Streamlined)',           'learndash_course', ++$n, array( 'course_id' => self::MC2_S ) );
		$component_ids[] = $this->cmp( $svc, $pid, $cid, 'Classroom Visit #4',                                          'classroom_visit',  ++$n, array( 'visit_number' => 4 ) );

		WP_CLI::log( "    Streamlined Phase 1: pathway_id={$pid}, {$n} components" );
		return array( 'pathway_id' => $pid, 'component_count' => $n, 'component_ids' => $component_ids );
	}

	// ------------------------------------------------------------------
	// Step 9: Assign pathways
	// ------------------------------------------------------------------

	private function assign_pathways( $enrollments, $pathways ) {
		global $wpdb;
		$t     = $wpdb->prefix;
		$count = 0;

		// Teachers → Teacher Phase 1.
		foreach ( $enrollments['teachers'] as $team_teachers ) {
			foreach ( $team_teachers as $e ) {
				$wpdb->update( $t . 'hl_enrollment', array( 'assigned_pathway_id' => $pathways['teacher']['pathway_id'] ),
					array( 'enrollment_id' => $e['enrollment_id'] ) );
				$count++;
			}
		}

		// Mentors → Mentor Phase 1.
		foreach ( $enrollments['mentors'] as $e ) {
			$wpdb->update( $t . 'hl_enrollment', array( 'assigned_pathway_id' => $pathways['mentor']['pathway_id'] ),
				array( 'enrollment_id' => $e['enrollment_id'] ) );
			$count++;
		}

		// School Leaders → Streamlined Phase 1.
		foreach ( $enrollments['school_leaders'] as $e ) {
			$wpdb->update( $t . 'hl_enrollment', array( 'assigned_pathway_id' => $pathways['streamlined']['pathway_id'] ),
				array( 'enrollment_id' => $e['enrollment_id'] ) );
			$count++;
		}

		// District Leader → Streamlined Phase 1.
		if ( $enrollments['district_leader'] ) {
			$wpdb->update( $t . 'hl_enrollment', array( 'assigned_pathway_id' => $pathways['streamlined']['pathway_id'] ),
				array( 'enrollment_id' => $enrollments['district_leader']['enrollment_id'] ) );
			$count++;
		}

		WP_CLI::log( "  [9] Pathway assignments: {$count}" );
	}

	// ------------------------------------------------------------------
	// Step 10: Teaching assignments
	// ------------------------------------------------------------------

	private function seed_teaching_assignments( $enrollments, $classrooms, $schools ) {
		remove_all_actions( 'hl_core_teaching_assignment_changed' );
		$svc   = new HL_Classroom_Service();
		$count = 0;

		// Build classroom lookup by school_key.
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

		WP_CLI::log( "  [10] Teaching assignments: {$count}" );
	}

	// ------------------------------------------------------------------
	// Step 11: Children
	// ------------------------------------------------------------------

	private function seed_children( $classrooms, $schools ) {
		$repo = new HL_Child_Repository();
		$svc  = new HL_Classroom_Service();

		$first_names = array( 'Emma', 'Liam', 'Olivia', 'Noah', 'Ava', 'Elijah', 'Sophia', 'James', 'Isabella', 'Lucas' );
		$last_names  = array( 'Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller', 'Davis', 'Rodriguez', 'Martinez' );

		$total = 0;
		foreach ( $classrooms as $cr ) {
			$child_count = wp_rand( 3, 8 );
			for ( $i = 0; $i < $child_count; $i++ ) {
				$age_years = $this->get_age_for_band( $cr['age_band'] );
				$dob = gmdate( 'Y-m-d', strtotime( "-{$age_years} years -" . wp_rand( 0, 364 ) . ' days' ) );

				$child_id = $repo->create( array(
					'first_name' => $first_names[ $i % count( $first_names ) ],
					'last_name'  => $last_names[ ( $i + $total ) % count( $last_names ) ],
					'dob'        => $dob,
					'school_id'  => $cr['school_id'],
				) );

				if ( $child_id ) {
					$svc->assign_child_to_classroom( $child_id, $cr['classroom_id'], 'Beginnings seed' );
					$total++;
				}
			}
		}

		WP_CLI::log( "  [11] Children created: {$total}" );
	}

	private function get_age_for_band( $band ) {
		switch ( $band ) {
			case 'infant':    return wp_rand( 0, 1 );
			case 'toddler':   return wp_rand( 1, 3 );
			case 'preschool': return wp_rand( 3, 4 );
			case 'pre_k':     return wp_rand( 4, 5 );
			default:          return wp_rand( 2, 4 );
		}
	}

	// ------------------------------------------------------------------
	// Step 13: Component states (90% completion)
	// ------------------------------------------------------------------

	private function seed_component_states( $enrollments, $pathways ) {
		global $wpdb;
		$t     = $wpdb->prefix;
		$count = 0;

		// Determine who is "incomplete" (straggler).
		// 2 teachers, 1 mentor, 1 school leader.
		$straggler_teachers = array(); // enrollment_ids of incomplete teachers.
		$straggler_mentor   = 0;
		$straggler_leader   = 0;

		// Pick 2 teacher stragglers from different teams.
		if ( ! empty( $enrollments['teachers'][0] ) ) {
			$last = end( $enrollments['teachers'][0] );
			$straggler_teachers[] = $last['enrollment_id'];
		}
		if ( ! empty( $enrollments['teachers'][3] ) ) {
			$last = end( $enrollments['teachers'][3] );
			$straggler_teachers[] = $last['enrollment_id'];
		}

		// Pick 1 mentor straggler (Colombia team = index 5).
		if ( isset( $enrollments['mentors'][5] ) ) {
			$straggler_mentor = $enrollments['mentors'][5]['enrollment_id'];
		}

		// Pick 1 school leader straggler (Texas).
		if ( isset( $enrollments['school_leaders']['texas'] ) ) {
			$straggler_leader = $enrollments['school_leaders']['texas']['enrollment_id'];
		}

		$base_date = '2025-02-01';

		foreach ( $enrollments['all'] as $e ) {
			$eid  = $e['enrollment_id'];
			$role = $e['role'];

			// Determine pathway.
			$pw_key = 'teacher';
			if ( $role === 'mentor' ) $pw_key = 'mentor';
			if ( $role === 'school_leader' || $role === 'district_leader' ) $pw_key = 'streamlined';

			$pw = $pathways[ $pw_key ];
			$comp_ids = $pw['component_ids'];
			$total    = count( $comp_ids );

			// Determine completion cutoff.
			$is_straggler = in_array( $eid, $straggler_teachers, true )
						 || $eid === $straggler_mentor
						 || $eid === $straggler_leader;

			if ( $is_straggler ) {
				// Complete ~50-70% of components.
				$cutoff = (int) round( $total * ( wp_rand( 50, 70 ) / 100 ) );
			} else {
				$cutoff = $total; // Fully complete.
			}

			for ( $i = 0; $i < $total; $i++ ) {
				$comp_id = $comp_ids[ $i ];
				if ( $i < $cutoff ) {
					$days_offset = (int) round( ( $i / max( $total, 1 ) ) * 200 );
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

		WP_CLI::log( "  [13] Component states: {$count} (stragglers: 2 teachers, 1 mentor, 1 school leader)" );
	}

	// ------------------------------------------------------------------
	// Step 14: Completion rollups
	// ------------------------------------------------------------------

	private function seed_rollups( $enrollments ) {
		global $wpdb;
		$t     = $wpdb->prefix;
		$count = 0;

		// Need cycle_id for the rollup table.
		$cycle_id = 0;
		if ( ! empty( $enrollments['all'] ) ) {
			$first_eid = $enrollments['all'][0]['enrollment_id'];
			$cycle_id  = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT cycle_id FROM {$t}hl_enrollment WHERE enrollment_id = %d", $first_eid
			) );
		}

		foreach ( $enrollments['all'] as $e ) {
			$eid = $e['enrollment_id'];

			// Check if all components are completed.
			$total     = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$t}hl_component_state WHERE enrollment_id = %d", $eid
			) );
			$completed = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$t}hl_component_state WHERE enrollment_id = %d AND completion_status = 'complete'", $eid
			) );

			if ( $total > 0 && $completed === $total ) {
				$pct = 100.00;
				$wpdb->insert( $t . 'hl_completion_rollup', array(
					'enrollment_id'              => $eid,
					'cycle_id'                   => $cycle_id,
					'pathway_completion_percent'  => $pct,
					'cycle_completion_percent'    => $pct,
					'last_computed_at'           => current_time( 'mysql' ),
				) );
				$count++;
			}
		}

		WP_CLI::log( "  [14] Completion rollups: {$count}" );
	}

	// ------------------------------------------------------------------
	// Step 15: Generate Cycle 2 CSV
	// ------------------------------------------------------------------

	private function generate_cycle2_csv( $users, $enrollments ) {
		$teams_def = self::get_teams();
		$rows      = array();

		// District Leader (unchanged).
		$dl = $users['district_leader'];
		$rows[] = array( $dl['first_name'], $dl['last_name'], $dl['email'], 'district_leader', 'Beginnings School District', '', 'Streamlined Phase 2' );

		// School Leaders (all return).
		foreach ( self::get_schools() as $key => $def ) {
			$rows[] = array( $def['leader_first'], $def['leader_last'], $def['leader_email'], 'school_leader', $def['name'], '', 'Streamlined Phase 2' );
		}

		// Mentors: 5 of 6 return. Colombia mentor leaves.
		// Returning mentors get Mentor Phase 2 or Mentor Completion.
		$mentor_left_idx = 5; // Colombia team index (Maria Mentor T01-Colombia).
		foreach ( $teams_def as $t_idx => $team ) {
			if ( $t_idx === $mentor_left_idx ) continue;
			$m = $users['mentors'][ $t_idx ];
			$school_name = self::get_schools()[ $team['school'] ]['name'];
			$team_name   = 'Team ' . str_pad( $team['num'], 2, '0', STR_PAD_LEFT ) . ' - ' . ucfirst( $team['school'] ) . ' - Beginnings';
			$rows[] = array( $m['first_name'], $m['last_name'], $m['email'], 'mentor', $school_name, $team_name, 'Mentor Phase 2' );
		}

		// Promoted teacher: Lisa from T01-Boston becomes mentor for Colombia team.
		$promoted = $users['teachers'][0][3]; // Lisa Teacher T01-Boston.
		$rows[] = array( $promoted['first_name'], $promoted['last_name'], $promoted['email'], 'mentor', 'Beginnings Colombia', 'Team 01 - Colombia - Beginnings', 'Mentor Transition' );

		// New hire mentor for a second team somewhere.
		$rows[] = array( 'Natalia', 'NewHire-Mentor', 'new-hire-mentor-boston@yopmail.com', 'mentor', 'Beginnings Boston', 'Team 01 - Boston - Beginnings', 'Mentor Phase 1' );

		// Teachers: ~75% return (drop ~5-6). Keep all except:
		// - Lisa (promoted to mentor above)
		// - The 2 straggler teachers (didn't finish, "left")
		// - 2-3 more random drops for realism.
		$drop_emails = array(
			$promoted['email'],                                                    // Lisa promoted
			$users['teachers'][0][3]['email'] ?? '',                                // Already counted above
			$users['teachers'][3][2]['email'] ?? '',                                // Leo from T02-Florida (straggler)
			$users['teachers'][2][3]['email'] ?? '',                                // Emma from T01-Florida (random drop)
			$users['teachers'][4][3]['email'] ?? '',                                // Lily from T01-Texas (random drop)
		);
		// Remove duplicates (Lisa appears twice).
		$drop_emails = array_unique( array_filter( $drop_emails ) );

		foreach ( $teams_def as $t_idx => $team ) {
			$school_name = self::get_schools()[ $team['school'] ]['name'];
			$team_name   = 'Team ' . str_pad( $team['num'], 2, '0', STR_PAD_LEFT ) . ' - ' . ucfirst( $team['school'] ) . ' - Beginnings';

			foreach ( $users['teachers'][ $t_idx ] as $t_data ) {
				if ( in_array( $t_data['email'], $drop_emails, true ) ) continue;
				$rows[] = array( $t_data['first_name'], $t_data['last_name'], $t_data['email'], 'teacher', $school_name, $team_name, 'Teacher Phase 2' );
			}
		}

		// Write CSV.
		$data_dir = plugin_dir_path( dirname( __DIR__ ) ) . 'data';
		if ( ! is_dir( $data_dir ) ) {
			wp_mkdir_p( $data_dir );
		}
		$csv_path = $data_dir . '/beginnings-cycle-2-roster.csv';
		$fp = fopen( $csv_path, 'w' );
		if ( ! $fp ) {
			WP_CLI::warning( 'Could not write CSV to ' . $csv_path );
			return null;
		}

		fputcsv( $fp, array( 'first_name', 'last_name', 'email', 'role', 'school', 'team', 'pathway' ) );
		foreach ( $rows as $row ) {
			fputcsv( $fp, $row );
		}
		fclose( $fp );

		WP_CLI::log( "  [15] Cycle 2 CSV: {$csv_path} (" . count( $rows ) . ' rows)' );
		return $csv_path;
	}

	// ------------------------------------------------------------------
	// Clean
	// ------------------------------------------------------------------

	private function clean() {
		global $wpdb;
		$t = $wpdb->prefix;

		WP_CLI::line( 'Cleaning Beginnings data...' );

		$cycle_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT cycle_id FROM {$t}hl_cycle WHERE cycle_code = %s LIMIT 1",
			self::CYCLE_CODE
		) );

		if ( $cycle_id ) {
			$enrollment_ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT enrollment_id FROM {$t}hl_enrollment WHERE cycle_id = %d", $cycle_id
			) );

			if ( ! empty( $enrollment_ids ) ) {
				$in = implode( ',', array_map( 'intval', $enrollment_ids ) );
				$wpdb->query( "DELETE FROM {$t}hl_completion_rollup WHERE enrollment_id IN ({$in})" );
				$wpdb->query( "DELETE FROM {$t}hl_component_state WHERE enrollment_id IN ({$in})" );
				$wpdb->query( "DELETE FROM {$t}hl_component_override WHERE enrollment_id IN ({$in})" );
				$wpdb->query( "DELETE FROM {$t}hl_team_membership WHERE enrollment_id IN ({$in})" );
				$wpdb->query( "DELETE FROM {$t}hl_teaching_assignment WHERE enrollment_id IN ({$in})" );

				// Child assessment instances.
				$ca_ids = $wpdb->get_col(
					"SELECT instance_id FROM {$t}hl_child_assessment_instance WHERE enrollment_id IN ({$in})"
				);
				if ( ! empty( $ca_ids ) ) {
					$in_ca = implode( ',', array_map( 'intval', $ca_ids ) );
					$wpdb->query( "DELETE FROM {$t}hl_child_assessment_childrow WHERE instance_id IN ({$in_ca})" );
				}
				$wpdb->query( "DELETE FROM {$t}hl_child_assessment_instance WHERE enrollment_id IN ({$in})" );
				$wpdb->query( "DELETE FROM {$t}hl_teacher_assessment_instance WHERE enrollment_id IN ({$in})" );
				$wpdb->query( "DELETE FROM {$t}hl_observation WHERE cycle_id = {$cycle_id}" );
			}

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

			// Teams.
			$team_ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT team_id FROM {$t}hl_team WHERE cycle_id = %d", $cycle_id
			) );
			if ( ! empty( $team_ids ) ) {
				$in_teams = implode( ',', array_map( 'intval', $team_ids ) );
				$wpdb->query( "DELETE FROM {$t}hl_team_membership WHERE team_id IN ({$in_teams})" );
			}
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$t}hl_team WHERE cycle_id = %d", $cycle_id ) );

			// Enrollments.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$t}hl_enrollment WHERE cycle_id = %d", $cycle_id ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$t}hl_cycle_school WHERE cycle_id = %d", $cycle_id ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$t}hl_coach_assignment WHERE cycle_id = %d", $cycle_id ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$t}hl_coaching_session WHERE cycle_id = %d", $cycle_id ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$t}hl_child_cycle_snapshot WHERE cycle_id = %d", $cycle_id ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$t}hl_cycle WHERE cycle_id = %d", $cycle_id ) );

			WP_CLI::log( "  Deleted cycle {$cycle_id} and all related records." );
		}

		// WP users.
		$demo_rows = $wpdb->get_results(
			"SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = '" . self::META_KEY . "'"
		);
		if ( ! empty( $demo_rows ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			$deleted = 0;
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

		// Children for Beginnings schools.
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

		// Orgunits.
		$district_id = $wpdb->get_var(
			"SELECT orgunit_id FROM {$t}hl_orgunit WHERE name = 'Beginnings School District' AND orgunit_type = 'district' LIMIT 1"
		);
		if ( $district_id ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$t}hl_orgunit WHERE parent_orgunit_id = %d", $district_id ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$t}hl_orgunit WHERE orgunit_id = %d", $district_id ) );
			WP_CLI::log( '  Deleted org units.' );
		}

		// Partnership.
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$t}hl_partnership WHERE partnership_code = %s",
			self::PARTNERSHIP_CODE
		) );
		WP_CLI::log( '  Deleted partnership.' );
	}
}
