<?php
/**
 * WP-CLI command: wp hl-core seed-lutheran
 *
 * Seeds the Lutheran Services Florida control group data from extracted
 * spreadsheet data in data/extracted_data.php.
 * Use --clean to remove all Lutheran data first.
 *
 * @package HL_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HL_CLI_Seed_Lutheran {

	/** Cohort code used to identify Lutheran seeded data. */
	const COHORT_CODE = 'LUTHERAN_CONTROL_2026';

	/** District code. */
	const DISTRICT_CODE = 'LSF_PALM_BEACH';

	/** Cohort group code. */
	const GROUP_CODE = 'B2E_LSF';

	/** User meta key to tag Lutheran seed users. */
	const SEED_META_KEY = '_hl_lutheran_seed';

	/**
	 * Register the WP-CLI command.
	 */
	public static function register() {
		WP_CLI::add_command( 'hl-core seed-lutheran', array( new self(), 'run' ) );
	}

	/**
	 * Seed Lutheran Services Florida control group data.
	 *
	 * ## OPTIONS
	 *
	 * [--clean]
	 * : Remove all Lutheran demo data before seeding.
	 *
	 * ## EXAMPLES
	 *
	 *     wp hl-core seed-lutheran
	 *     wp hl-core seed-lutheran --clean
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Named args.
	 */
	public function run( $args, $assoc_args ) {
		$clean = isset( $assoc_args['clean'] );

		if ( $clean ) {
			$this->clean();
			WP_CLI::success( 'Lutheran control group data cleaned.' );
			return;
		}

		if ( $this->seed_exists() ) {
			WP_CLI::warning( 'Lutheran control group data already exists. Run with --clean first to reseed.' );
			return;
		}

		// Load extracted data arrays.
		$data_file = dirname( __DIR__, 2 ) . '/data/extracted_data.php';
		if ( ! file_exists( $data_file ) ) {
			WP_CLI::error( "Data file not found: {$data_file}" );
			return;
		}
		require $data_file;

		if ( ! isset( $center_info_data ) || ! isset( $teacher_roster_data ) || ! isset( $child_roster_data ) ) {
			WP_CLI::error( 'Data file must define $center_info_data, $teacher_roster_data, and $child_roster_data arrays.' );
			return;
		}

		WP_CLI::line( '' );
		WP_CLI::line( '=== HL Core Lutheran Control Group Seeder ===' );
		WP_CLI::line( '' );

		// Step 1: District.
		$district_id = $this->seed_district();

		// Step 2: Centers.
		$center_map = $this->seed_centers( $center_info_data, $district_id );

		// Step 3: Cohort.
		$cohort_id = $this->seed_cohort( $district_id );

		// Step 4: Link centers to cohort.
		$this->link_centers_to_cohort( $cohort_id, $center_map );

		// Step 5: Cohort Group.
		$group_id = $this->seed_cohort_group( $cohort_id );

		// Step 6: Classrooms.
		$classrooms = $this->seed_classrooms( $teacher_roster_data, $center_map );

		// Step 7: WP Users (Teachers).
		$users = $this->seed_users( $teacher_roster_data );

		// Step 8: Enrollments.
		$enrollments = $this->seed_enrollments( $teacher_roster_data, $users, $cohort_id, $center_map, $district_id );

		// Step 9: Teaching Assignments.
		$this->seed_teaching_assignments( $teacher_roster_data, $enrollments, $classrooms, $center_map );

		// Step 10: Children.
		$this->seed_children( $child_roster_data, $classrooms, $center_map );

		// Step 11: Pathway & Activities.
		$pathway_data = $this->seed_pathway( $cohort_id );

		// Step 12: B2E Teacher Assessment Instrument.
		$instrument_id = $this->seed_teacher_instrument();

		// Update activity external_ref with instrument_id.
		$this->update_activity_instrument_refs( $pathway_data, $instrument_id );

		// Step 13: Assessment Instances.
		$this->seed_assessment_instances( $enrollments, $cohort_id, $pathway_data, $instrument_id, $classrooms, $center_map, $teacher_roster_data );

		// Step 14: Pathway Assignments.
		$this->seed_pathway_assignments( $enrollments, $pathway_data['pathway_id'] );

		WP_CLI::line( '' );
		WP_CLI::success( 'Lutheran control group data seeded successfully!' );
		WP_CLI::line( '' );
		WP_CLI::line( 'Summary:' );
		WP_CLI::line( "  District:     {$district_id} (code: " . self::DISTRICT_CODE . ')' );
		WP_CLI::line( '  Centers:      ' . count( $center_map ) );
		WP_CLI::line( "  Cohort:       {$cohort_id} (code: " . self::COHORT_CODE . ')' );
		WP_CLI::line( "  Group:        {$group_id} (code: " . self::GROUP_CODE . ')' );
		WP_CLI::line( '  Classrooms:   ' . count( $classrooms ) );
		WP_CLI::line( '  Teachers:     ' . count( $users ) );
		WP_CLI::line( '  Enrollments:  ' . count( $enrollments ) );
		WP_CLI::line( "  Pathway:      {$pathway_data['pathway_id']}" );
		WP_CLI::line( '' );
	}

	// ------------------------------------------------------------------
	// Idempotency helpers
	// ------------------------------------------------------------------

	/**
	 * Check if Lutheran seed data already exists.
	 *
	 * @return bool
	 */
	private function seed_exists() {
		global $wpdb;
		$row = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT cohort_id FROM {$wpdb->prefix}hl_cohort WHERE cohort_code = %s LIMIT 1",
				self::COHORT_CODE
			)
		);
		return ! empty( $row );
	}

	// ------------------------------------------------------------------
	// --clean handler
	// ------------------------------------------------------------------

	/**
	 * Remove all Lutheran seed data.
	 */
	private function clean() {
		global $wpdb;
		$prefix = $wpdb->prefix;

		WP_CLI::line( 'Cleaning Lutheran control group data...' );

		// Find the cohort.
		$cohort_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT cohort_id FROM {$prefix}hl_cohort WHERE cohort_code = %s LIMIT 1",
				self::COHORT_CODE
			)
		);

		if ( $cohort_id ) {
			// Get enrollment IDs.
			$enrollment_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT enrollment_id FROM {$prefix}hl_enrollment WHERE cohort_id = %d",
					$cohort_id
				)
			);

			if ( ! empty( $enrollment_ids ) ) {
				$in_ids = implode( ',', array_map( 'intval', $enrollment_ids ) );

				// Delete activity states.
				$wpdb->query( "DELETE FROM {$prefix}hl_activity_state WHERE enrollment_id IN ({$in_ids})" );

				// Delete completion rollups.
				$wpdb->query( "DELETE FROM {$prefix}hl_completion_rollup WHERE enrollment_id IN ({$in_ids})" );

				// Delete teaching assignments.
				$wpdb->query( "DELETE FROM {$prefix}hl_teaching_assignment WHERE enrollment_id IN ({$in_ids})" );

				// Delete pathway assignments.
				$wpdb->query( "DELETE FROM {$prefix}hl_pathway_assignment WHERE enrollment_id IN ({$in_ids})" );

				// Delete children assessment instances and child rows.
				$ca_ids = $wpdb->get_col(
					"SELECT instance_id FROM {$prefix}hl_children_assessment_instance WHERE enrollment_id IN ({$in_ids})"
				);
				if ( ! empty( $ca_ids ) ) {
					$in_ca = implode( ',', array_map( 'intval', $ca_ids ) );
					$wpdb->query( "DELETE FROM {$prefix}hl_children_assessment_childrow WHERE instance_id IN ({$in_ca})" );
				}
				$wpdb->query( "DELETE FROM {$prefix}hl_children_assessment_instance WHERE enrollment_id IN ({$in_ids})" );

				// Delete teacher assessment instances.
				$wpdb->query( "DELETE FROM {$prefix}hl_teacher_assessment_instance WHERE enrollment_id IN ({$in_ids})" );
			}

			// Delete activities and their drip rules.
			$activity_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT activity_id FROM {$prefix}hl_activity WHERE cohort_id = %d",
					$cohort_id
				)
			);
			if ( ! empty( $activity_ids ) ) {
				$in_act = implode( ',', array_map( 'intval', $activity_ids ) );
				$wpdb->query( "DELETE FROM {$prefix}hl_activity_drip_rule WHERE activity_id IN ({$in_act})" );
			}
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$prefix}hl_activity WHERE cohort_id = %d", $cohort_id ) );

			// Delete pathway.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$prefix}hl_pathway WHERE cohort_id = %d", $cohort_id ) );

			// Delete enrollments.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$prefix}hl_enrollment WHERE cohort_id = %d", $cohort_id ) );

			// Delete cohort-center links.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$prefix}hl_cohort_center WHERE cohort_id = %d", $cohort_id ) );

			// Delete cohort.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$prefix}hl_cohort WHERE cohort_id = %d", $cohort_id ) );

			// Delete audit log entries.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$prefix}hl_audit_log WHERE cohort_id = %d", $cohort_id ) );

			WP_CLI::log( "  Deleted cohort {$cohort_id} and all related records." );
		}

		// Delete cohort group.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$prefix}hl_cohort_group WHERE group_code = %s",
				self::GROUP_CODE
			)
		);
		WP_CLI::log( '  Deleted cohort group (' . self::GROUP_CODE . ').' );

		// Delete district and centers.
		$district_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT orgunit_id FROM {$prefix}hl_orgunit WHERE orgunit_code = %s AND orgunit_type = 'district' LIMIT 1",
				self::DISTRICT_CODE
			)
		);

		if ( $district_id ) {
			$center_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT orgunit_id FROM {$prefix}hl_orgunit WHERE parent_orgunit_id = %d AND orgunit_type = 'center'",
					$district_id
				)
			);

			if ( ! empty( $center_ids ) ) {
				$in_c = implode( ',', array_map( 'intval', $center_ids ) );

				// Delete children and classrooms for these centers.
				$cls_ids = $wpdb->get_col(
					"SELECT classroom_id FROM {$prefix}hl_classroom WHERE center_id IN ({$in_c})"
				);
				if ( ! empty( $cls_ids ) ) {
					$in_cls = implode( ',', array_map( 'intval', $cls_ids ) );
					$wpdb->query( "DELETE FROM {$prefix}hl_child_classroom_current WHERE classroom_id IN ({$in_cls})" );
					$wpdb->query( "DELETE FROM {$prefix}hl_child_classroom_history WHERE classroom_id IN ({$in_cls})" );
				}
				$wpdb->query( "DELETE FROM {$prefix}hl_child WHERE center_id IN ({$in_c})" );
				$wpdb->query( "DELETE FROM {$prefix}hl_classroom WHERE center_id IN ({$in_c})" );

				// Delete centers.
				$wpdb->query( "DELETE FROM {$prefix}hl_orgunit WHERE orgunit_id IN ({$in_c})" );
			}

			// Delete district.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$prefix}hl_orgunit WHERE orgunit_id = %d", $district_id ) );
			WP_CLI::log( '  Deleted district and ' . count( $center_ids ) . ' centers.' );
		}

		// Delete B2E teacher instrument only if no other seeder is using it.
		$other_cohort = $wpdb->get_var(
			"SELECT cohort_id FROM {$prefix}hl_cohort WHERE cohort_code IN ('DEMO-2026','ELC-PB-2026') LIMIT 1"
		);
		if ( ! $other_cohort ) {
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$prefix}hl_teacher_assessment_instrument WHERE instrument_key = %s",
					'b2e_self_assessment'
				)
			);
			WP_CLI::log( '  Deleted B2E teacher assessment instrument.' );
		}

		// Delete WP users tagged with the Lutheran seed meta key.
		$seed_user_ids = $wpdb->get_col(
			"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = '" . self::SEED_META_KEY . "' AND meta_value = '1'"
		);
		if ( ! empty( $seed_user_ids ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			foreach ( $seed_user_ids as $uid ) {
				wp_delete_user( (int) $uid );
			}
			WP_CLI::log( '  Deleted ' . count( $seed_user_ids ) . ' Lutheran seed users.' );
		}
	}

	// ------------------------------------------------------------------
	// Age band normalization
	// ------------------------------------------------------------------

	/**
	 * Normalize a messy age group string to a canonical age_band value.
	 *
	 * @param string $raw The raw age group string from the data.
	 * @return string One of: infant, toddler, preschool, mixed.
	 */
	private function normalize_age_band( $raw ) {
		$lower = strtolower( trim( $raw ) );

		// Infant.
		if ( in_array( $lower, array( 'infants', '0-12 months' ), true ) ) {
			return 'infant';
		}

		// Toddler.
		if ( in_array( $lower, array(
			'toddlers',
			'1 year olds',
			'1-2 year olds',
			'infants/tooddlers',
			'infants/toddlers',
			'2 year olds',
			'2 year old',
		), true ) ) {
			return 'toddler';
		}

		// Preschool.
		if ( in_array( $lower, array(
			'3 year olds',
			'preschool',
			'vpk',
			'3 and 4 year olds',
			'vpk/3 year olds',
		), true ) ) {
			return 'preschool';
		}

		// Mixed.
		if ( in_array( $lower, array(
			'mixed',
			'0 to 3',
			'0-3',
			'birth-3',
			'birth to 3',
			'0 to 4',
		), true ) ) {
			return 'mixed';
		}

		// Default.
		return 'mixed';
	}

	// ------------------------------------------------------------------
	// Center name matching (fuzzy)
	// ------------------------------------------------------------------

	/**
	 * Match a roster center name (often abbreviated) to a center_map key.
	 *
	 * @param string $roster_name The abbreviated name from the roster.
	 * @param array  $center_map  Keyed by full center name => center orgunit_id.
	 * @return int|null The center orgunit_id or null if not matched.
	 */
	private function match_center_name( $roster_name, $center_map ) {
		// Direct match.
		if ( isset( $center_map[ $roster_name ] ) ) {
			return $center_map[ $roster_name ];
		}

		// Fuzzy: check if roster name is contained in any center name or vice versa.
		foreach ( $center_map as $full_name => $id ) {
			if ( stripos( $full_name, $roster_name ) !== false ) {
				return $id;
			}
			if ( stripos( $roster_name, $full_name ) !== false ) {
				return $id;
			}
		}

		// Match first word.
		$first_word = strtok( $roster_name, ' ' );
		if ( $first_word ) {
			foreach ( $center_map as $full_name => $id ) {
				if ( stripos( $full_name, $first_word ) !== false ) {
					return $id;
				}
			}
		}

		return null;
	}

	// ------------------------------------------------------------------
	// Slugify helper
	// ------------------------------------------------------------------

	/**
	 * Generate a code from a name (uppercase, underscored).
	 *
	 * @param string $name The center name.
	 * @return string A slug-style code.
	 */
	private function slugify_code( $name ) {
		$code = strtoupper( sanitize_title( $name ) );
		$code = str_replace( '-', '_', $code );
		return $code;
	}

	// ------------------------------------------------------------------
	// Step 1: District
	// ------------------------------------------------------------------

	/**
	 * Create the LSF Palm Beach district.
	 *
	 * @return int District orgunit_id.
	 */
	private function seed_district() {
		$repo = new HL_OrgUnit_Repository();

		$district_id = $repo->create( array(
			'name'         => 'Lutheran Services Florida - Palm Beach',
			'orgunit_type' => 'district',
			'orgunit_code' => self::DISTRICT_CODE,
		) );

		WP_CLI::log( "  [1/14] District created: id={$district_id}, code=" . self::DISTRICT_CODE );
		return $district_id;
	}

	// ------------------------------------------------------------------
	// Step 2: Centers
	// ------------------------------------------------------------------

	/**
	 * Create centers from $center_info_data.
	 *
	 * @param array $center_info_data Rows from extracted data. Each row: [index, name, leader, address, ...].
	 * @param int   $district_id      Parent district orgunit_id.
	 * @return array Keyed by center name => orgunit_id.
	 */
	private function seed_centers( $center_info_data, $district_id ) {
		$repo       = new HL_OrgUnit_Repository();
		$center_map = array();

		foreach ( $center_info_data as $row ) {
			$name    = isset( $row[1] ) ? trim( $row[1] ) : '';
			$address = isset( $row[3] ) ? trim( $row[3] ) : '';
			$leader  = isset( $row[2] ) ? trim( $row[2] ) : '';

			if ( empty( $name ) ) {
				continue;
			}

			$code     = $this->slugify_code( $name );
			$metadata = wp_json_encode( array(
				'address'        => $address,
				'center_leader'  => $leader,
			) );

			$center_id = $repo->create( array(
				'name'              => $name,
				'orgunit_type'      => 'center',
				'orgunit_code'      => $code,
				'parent_orgunit_id' => $district_id,
				'metadata'          => $metadata,
			) );

			$center_map[ $name ] = $center_id;
		}

		WP_CLI::log( '  [2/14] Centers: ' . count( $center_map ) . ' created' );
		return $center_map;
	}

	// ------------------------------------------------------------------
	// Step 3: Cohort
	// ------------------------------------------------------------------

	/**
	 * Create the Lutheran control group cohort.
	 *
	 * @param int $district_id District orgunit_id.
	 * @return int Cohort ID.
	 */
	private function seed_cohort( $district_id ) {
		$repo = new HL_Cohort_Repository();

		$cohort_id = $repo->create( array(
			'cohort_name'      => 'Lutheran Control Group 2026',
			'cohort_code'      => self::COHORT_CODE,
			'district_id'      => $district_id,
			'status'           => 'active',
			'is_control_group' => 1,
			'start_date'       => '2026-02-15',
			'end_date'         => '2026-07-31',
		) );

		WP_CLI::log( "  [3/14] Cohort created: id={$cohort_id}, code=" . self::COHORT_CODE );
		return $cohort_id;
	}

	// ------------------------------------------------------------------
	// Step 4: Link centers to cohort
	// ------------------------------------------------------------------

	/**
	 * Insert hl_cohort_center records for each center.
	 *
	 * @param int   $cohort_id  Cohort ID.
	 * @param array $center_map Keyed by center name => orgunit_id.
	 */
	private function link_centers_to_cohort( $cohort_id, $center_map ) {
		global $wpdb;

		foreach ( $center_map as $center_id ) {
			$wpdb->insert( $wpdb->prefix . 'hl_cohort_center', array(
				'cohort_id' => $cohort_id,
				'center_id' => $center_id,
			) );
		}

		WP_CLI::log( '  [4/14] Linked ' . count( $center_map ) . ' centers to cohort' );
	}

	// ------------------------------------------------------------------
	// Step 5: Cohort Group
	// ------------------------------------------------------------------

	/**
	 * Create or find the B2E_LSF cohort group, then assign the cohort.
	 *
	 * @param int $cohort_id The Lutheran control cohort ID.
	 * @return int Group ID.
	 */
	private function seed_cohort_group( $cohort_id ) {
		global $wpdb;
		$prefix = $wpdb->prefix;

		// Check if group already exists.
		$group_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT group_id FROM {$prefix}hl_cohort_group WHERE group_code = %s LIMIT 1",
				self::GROUP_CODE
			)
		);

		if ( ! $group_id ) {
			$wpdb->insert( $prefix . 'hl_cohort_group', array(
				'group_uuid' => HL_DB_Utils::generate_uuid(),
				'group_name' => 'B2E Mastery - Lutheran Services Florida',
				'group_code' => self::GROUP_CODE,
				'status'     => 'active',
			) );
			$group_id = $wpdb->insert_id;
		}

		// Assign the control cohort to this group.
		$wpdb->update(
			$prefix . 'hl_cohort',
			array( 'cohort_group_id' => $group_id ),
			array( 'cohort_id' => $cohort_id )
		);

		WP_CLI::log( "  [5/14] Cohort group: id={$group_id}, code=" . self::GROUP_CODE );
		return $group_id;
	}

	// ------------------------------------------------------------------
	// Step 6: Classrooms
	// ------------------------------------------------------------------

	/**
	 * Extract unique classrooms from teacher roster and create hl_classroom records.
	 *
	 * @param array $teacher_roster_data Rows from extracted data.
	 * @param array $center_map          Keyed by center name => orgunit_id.
	 * @return array Keyed by "center_name::classroom_name" => array with classroom_id, center_id, age_band.
	 */
	private function seed_classrooms( $teacher_roster_data, $center_map ) {
		$svc        = new HL_Classroom_Service();
		$classrooms = array();
		$seen       = array();

		foreach ( $teacher_roster_data as $row ) {
			$center_name    = isset( $row[1] ) ? trim( $row[1] ) : '';
			$classroom_name = isset( $row[4] ) ? trim( $row[4] ) : '';
			$age_group_raw  = isset( $row[5] ) ? trim( $row[5] ) : '';

			if ( empty( $center_name ) || empty( $classroom_name ) ) {
				continue;
			}

			$key = $center_name . '::' . $classroom_name;
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;

			$center_id = $this->match_center_name( $center_name, $center_map );
			if ( ! $center_id ) {
				WP_CLI::warning( "Center not matched for classroom: {$center_name} :: {$classroom_name}" );
				continue;
			}

			$age_band = $this->normalize_age_band( $age_group_raw );

			$id = $svc->create_classroom( array(
				'classroom_name' => $classroom_name,
				'center_id'      => $center_id,
				'age_band'       => $age_band,
			) );

			if ( is_wp_error( $id ) ) {
				WP_CLI::warning( "Classroom creation error ({$key}): " . $id->get_error_message() );
				continue;
			}

			$classrooms[ $key ] = array(
				'classroom_id' => $id,
				'center_id'    => $center_id,
				'center_name'  => $center_name,
				'name'         => $classroom_name,
				'age_band'     => $age_band,
			);
		}

		WP_CLI::log( '  [6/14] Classrooms created: ' . count( $classrooms ) );
		return $classrooms;
	}

	// ------------------------------------------------------------------
	// Step 7: WP Users (Teachers)
	// ------------------------------------------------------------------

	/**
	 * Create WP users for all teachers in the roster.
	 *
	 * @param array $teacher_roster_data Rows from extracted data.
	 * @return array Keyed by row index => array with user_id, email, full_name.
	 */
	private function seed_users( $teacher_roster_data ) {
		$users = array();

		foreach ( $teacher_roster_data as $idx => $row ) {
			$full_name = isset( $row[2] ) ? trim( $row[2] ) : '';
			$email     = isset( $row[3] ) ? trim( $row[3] ) : '';

			if ( empty( $email ) || empty( $full_name ) ) {
				continue;
			}

			// Split name into first and last.
			$parts      = explode( ' ', $full_name, 2 );
			$first_name = $parts[0];
			$last_name  = isset( $parts[1] ) ? $parts[1] : '';

			$user_id = $this->create_seed_user( $email, $first_name, $last_name );

			$users[ $idx ] = array(
				'user_id'   => $user_id,
				'email'     => $email,
				'full_name' => $full_name,
			);
		}

		WP_CLI::log( '  [7/14] WP users created: ' . count( $users ) );
		return $users;
	}

	/**
	 * Create a WP user tagged with the Lutheran seed meta key.
	 *
	 * @param string $email      User email (also used to derive username).
	 * @param string $first_name First name.
	 * @param string $last_name  Last name.
	 * @return int WP user ID.
	 */
	private function create_seed_user( $email, $first_name, $last_name ) {
		$parts    = explode( '@', $email );
		$username = sanitize_user( $parts[0], true );

		// Handle duplicate usernames.
		$base_username = $username;
		$suffix        = 1;
		while ( username_exists( $username ) ) {
			$existing = get_user_by( 'login', $username );
			if ( $existing && $existing->user_email === $email ) {
				update_user_meta( $existing->ID, self::SEED_META_KEY, '1' );
				return $existing->ID;
			}
			$username = $base_username . '-' . $suffix;
			$suffix++;
		}

		// Handle duplicate emails.
		$existing_by_email = get_user_by( 'email', $email );
		if ( $existing_by_email ) {
			update_user_meta( $existing_by_email->ID, self::SEED_META_KEY, '1' );
			return $existing_by_email->ID;
		}

		$user_id = wp_insert_user( array(
			'user_login'   => $username,
			'user_email'   => $email,
			'user_pass'    => wp_generate_password( 24 ),
			'display_name' => $first_name . ' ' . $last_name,
			'first_name'   => $first_name,
			'last_name'    => $last_name,
			'role'         => 'subscriber',
		) );

		if ( is_wp_error( $user_id ) ) {
			WP_CLI::warning( "Could not create user {$email}: " . $user_id->get_error_message() );
			return 0;
		}

		update_user_meta( $user_id, self::SEED_META_KEY, '1' );
		return $user_id;
	}

	// ------------------------------------------------------------------
	// Step 8: Enrollments
	// ------------------------------------------------------------------

	/**
	 * Create hl_enrollment records for each teacher.
	 *
	 * @param array $teacher_roster_data Rows from extracted data.
	 * @param array $users               Keyed by row index => user data.
	 * @param int   $cohort_id           Lutheran control cohort ID.
	 * @param array $center_map          Keyed by center name => orgunit_id.
	 * @param int   $district_id         District orgunit_id.
	 * @return array Keyed by row index => array with enrollment_id, user_id, center_name, classroom_name.
	 */
	private function seed_enrollments( $teacher_roster_data, $users, $cohort_id, $center_map, $district_id ) {
		$repo        = new HL_Enrollment_Repository();
		$enrollments = array();

		foreach ( $teacher_roster_data as $idx => $row ) {
			if ( ! isset( $users[ $idx ] ) || empty( $users[ $idx ]['user_id'] ) ) {
				continue;
			}

			$center_name    = isset( $row[1] ) ? trim( $row[1] ) : '';
			$classroom_name = isset( $row[4] ) ? trim( $row[4] ) : '';
			$center_id      = $this->match_center_name( $center_name, $center_map );

			$eid = $repo->create( array(
				'user_id'     => $users[ $idx ]['user_id'],
				'cohort_id'   => $cohort_id,
				'roles'       => array( 'teacher' ),
				'status'      => 'active',
				'center_id'   => $center_id,
				'district_id' => $district_id,
			) );

			$enrollments[ $idx ] = array(
				'enrollment_id'  => $eid,
				'user_id'        => $users[ $idx ]['user_id'],
				'center_name'    => $center_name,
				'classroom_name' => $classroom_name,
			);
		}

		WP_CLI::log( '  [8/14] Enrollments created: ' . count( $enrollments ) );
		return $enrollments;
	}

	// ------------------------------------------------------------------
	// Step 9: Teaching Assignments
	// ------------------------------------------------------------------

	/**
	 * Create hl_teaching_assignment records.
	 *
	 * @param array $teacher_roster_data Rows from extracted data.
	 * @param array $enrollments         Keyed by row index.
	 * @param array $classrooms          Keyed by "center_name::classroom_name".
	 * @param array $center_map          Keyed by center name => orgunit_id.
	 */
	private function seed_teaching_assignments( $teacher_roster_data, $enrollments, $classrooms, $center_map ) {
		$svc   = new HL_Classroom_Service();
		$count = 0;

		foreach ( $teacher_roster_data as $idx => $row ) {
			if ( ! isset( $enrollments[ $idx ] ) ) {
				continue;
			}

			$center_name    = isset( $row[1] ) ? trim( $row[1] ) : '';
			$classroom_name = isset( $row[4] ) ? trim( $row[4] ) : '';
			$key            = $center_name . '::' . $classroom_name;

			if ( ! isset( $classrooms[ $key ] ) ) {
				WP_CLI::warning( "Classroom not found for teaching assignment: {$key}" );
				continue;
			}

			$result = $svc->create_teaching_assignment( array(
				'enrollment_id'   => $enrollments[ $idx ]['enrollment_id'],
				'classroom_id'    => $classrooms[ $key ]['classroom_id'],
				'is_lead_teacher' => 1,
			) );

			if ( ! is_wp_error( $result ) ) {
				$count++;
			}
		}

		WP_CLI::log( "  [9/14] Teaching assignments created: {$count}" );
	}

	// ------------------------------------------------------------------
	// Step 10: Children
	// ------------------------------------------------------------------

	/**
	 * Create hl_child records from child roster data.
	 *
	 * @param array $child_roster_data Rows from extracted data.
	 * @param array $classrooms        Keyed by "center_name::classroom_name".
	 * @param array $center_map        Keyed by center name => orgunit_id.
	 */
	private function seed_children( $child_roster_data, $classrooms, $center_map ) {
		$repo      = new HL_Child_Repository();
		$svc       = new HL_Classroom_Service();
		$total     = 0;
		$unmatched = 0;

		foreach ( $child_roster_data as $row ) {
			$center_name    = isset( $row[1] ) ? trim( $row[1] ) : '';
			$classroom_name = isset( $row[2] ) ? trim( $row[2] ) : '';
			$child_name     = isset( $row[3] ) ? trim( $row[3] ) : '';
			$age_group_raw  = isset( $row[4] ) ? trim( $row[4] ) : '';
			$dob            = isset( $row[5] ) ? trim( $row[5] ) : '';
			$gender         = isset( $row[6] ) ? trim( $row[6] ) : '';
			$ethnicity      = isset( $row[7] ) ? trim( $row[7] ) : '';
			$language        = isset( $row[8] ) ? trim( $row[8] ) : '';

			if ( empty( $child_name ) || empty( $center_name ) ) {
				continue;
			}

			// Split child name into first and last.
			$name_parts = explode( ' ', $child_name, 2 );
			$first_name = $name_parts[0];
			$last_name  = isset( $name_parts[1] ) ? $name_parts[1] : '';

			$center_id = $this->match_center_name( $center_name, $center_map );
			if ( ! $center_id ) {
				$unmatched++;
				continue;
			}

			$age_band = $this->normalize_age_band( $age_group_raw );
			$metadata = wp_json_encode( array(
				'gender'          => $gender,
				'ethnicity'       => $ethnicity,
				'native_language' => $language,
				'age_band'        => $age_band,
			) );

			$child_id = $repo->create( array(
				'first_name' => $first_name,
				'last_name'  => $last_name,
				'dob'        => $dob,
				'center_id'  => $center_id,
				'ethnicity'  => $ethnicity,
				'metadata'   => $metadata,
			) );

			if ( $child_id ) {
				$cr_key = $center_name . '::' . $classroom_name;
				if ( isset( $classrooms[ $cr_key ] ) ) {
					$svc->assign_child_to_classroom( $child_id, $classrooms[ $cr_key ]['classroom_id'], 'Lutheran seed initial assignment' );
				}
				$total++;
			}
		}

		$msg = "  [10/14] Children created: {$total}";
		if ( $unmatched > 0 ) {
			$msg .= " ({$unmatched} unmatched centers)";
		}
		WP_CLI::log( $msg );
	}

	// ------------------------------------------------------------------
	// Step 11: Pathway & Activities
	// ------------------------------------------------------------------

	/**
	 * Create the control group assessment pathway with 4 activities.
	 *
	 * @param int $cohort_id Lutheran control cohort ID.
	 * @return array Pathway data with pathway_id, activity IDs.
	 */
	private function seed_pathway( $cohort_id ) {
		$svc = new HL_Pathway_Service();

		$pathway_id = $svc->create_pathway( array(
			'pathway_name'  => 'Control Group Assessments',
			'pathway_code'  => 'LUTHERAN_CTRL_ASSESSMENTS',
			'cohort_id'     => $cohort_id,
			'target_roles'  => array( 'teacher' ),
			'active_status' => 1,
		) );

		// Activity 1: Teacher Self-Assessment (Pre).
		$tsa_pre_id = $svc->create_activity( array(
			'title'         => 'Teacher Self-Assessment (Pre)',
			'pathway_id'    => $pathway_id,
			'cohort_id'     => $cohort_id,
			'activity_type' => 'teacher_self_assessment',
			'weight'        => 1.0,
			'ordering_hint' => 1,
			'external_ref'  => wp_json_encode( array( 'phase' => 'pre' ) ),
		) );

		// Activity 2: Children Assessment (Pre).
		$ca_pre_id = $svc->create_activity( array(
			'title'         => 'Children Assessment (Pre)',
			'pathway_id'    => $pathway_id,
			'cohort_id'     => $cohort_id,
			'activity_type' => 'children_assessment',
			'weight'        => 1.0,
			'ordering_hint' => 2,
			'external_ref'  => wp_json_encode( array( 'phase' => 'pre' ) ),
		) );

		// Activity 3: Teacher Self-Assessment (Post).
		$tsa_post_id = $svc->create_activity( array(
			'title'         => 'Teacher Self-Assessment (Post)',
			'pathway_id'    => $pathway_id,
			'cohort_id'     => $cohort_id,
			'activity_type' => 'teacher_self_assessment',
			'weight'        => 1.0,
			'ordering_hint' => 3,
			'external_ref'  => wp_json_encode( array( 'phase' => 'post' ) ),
		) );

		// Activity 4: Children Assessment (Post).
		$ca_post_id = $svc->create_activity( array(
			'title'         => 'Children Assessment (Post)',
			'pathway_id'    => $pathway_id,
			'cohort_id'     => $cohort_id,
			'activity_type' => 'children_assessment',
			'weight'        => 1.0,
			'ordering_hint' => 4,
			'external_ref'  => wp_json_encode( array( 'phase' => 'post' ) ),
		) );

		// Drip rules for POST activities: fixed_date = 2026-05-05.
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'hl_activity_drip_rule', array(
			'activity_id'     => $tsa_post_id,
			'drip_type'       => 'fixed_date',
			'release_at_date' => '2026-05-05 00:00:00',
		) );
		$wpdb->insert( $wpdb->prefix . 'hl_activity_drip_rule', array(
			'activity_id'     => $ca_post_id,
			'drip_type'       => 'fixed_date',
			'release_at_date' => '2026-05-05 00:00:00',
		) );

		WP_CLI::log( "  [11/14] Pathway created: id={$pathway_id}, 4 activities (2 pre + 2 post with drip rules)" );

		return array(
			'pathway_id'  => $pathway_id,
			'tsa_pre_id'  => $tsa_pre_id,
			'ca_pre_id'   => $ca_pre_id,
			'tsa_post_id' => $tsa_post_id,
			'ca_post_id'  => $ca_post_id,
		);
	}

	// ------------------------------------------------------------------
	// Step 12: B2E Teacher Assessment Instrument
	// ------------------------------------------------------------------

	/**
	 * Create or find the B2E Teacher Assessment Instrument.
	 *
	 * Uses shared definitions from HL_CLI_Seed_Demo.
	 *
	 * @return int Instrument ID.
	 */
	private function seed_teacher_instrument() {
		global $wpdb;
		$prefix = $wpdb->prefix;

		// Check if already present.
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT instrument_id FROM {$prefix}hl_teacher_assessment_instrument WHERE instrument_key = %s LIMIT 1",
				'b2e_self_assessment'
			)
		);

		if ( $existing ) {
			WP_CLI::log( "  [12/14] B2E Teacher Assessment Instrument already exists: id={$existing}" );
			return (int) $existing;
		}

		// Use shared instrument definition from HL_CLI_Seed_Demo.
		$sections     = wp_json_encode( HL_CLI_Seed_Demo::get_b2e_instrument_sections() );
		$scale_labels = wp_json_encode( HL_CLI_Seed_Demo::get_b2e_instrument_scale_labels() );

		$wpdb->insert( $prefix . 'hl_teacher_assessment_instrument', array(
			'instrument_name'    => 'B2E Teacher Self-Assessment',
			'instrument_key'     => 'b2e_self_assessment',
			'instrument_version' => '1.0',
			'sections'           => $sections,
			'scale_labels'       => $scale_labels,
			'status'             => 'active',
			'created_at'         => current_time( 'mysql' ),
		) );
		$instrument_id = $wpdb->insert_id;

		WP_CLI::log( "  [12/14] B2E Teacher Assessment Instrument created: id={$instrument_id}" );
		return $instrument_id;
	}

	/**
	 * Update the TSA activity external_ref to include the instrument_id.
	 *
	 * @param array $pathway_data  Pathway and activity IDs.
	 * @param int   $instrument_id Teacher assessment instrument ID.
	 */
	private function update_activity_instrument_refs( $pathway_data, $instrument_id ) {
		$svc = new HL_Pathway_Service();

		$svc->update_activity( $pathway_data['tsa_pre_id'], array(
			'external_ref' => wp_json_encode( array(
				'phase'                 => 'pre',
				'teacher_instrument_id' => $instrument_id,
			) ),
		) );

		$svc->update_activity( $pathway_data['tsa_post_id'], array(
			'external_ref' => wp_json_encode( array(
				'phase'                 => 'post',
				'teacher_instrument_id' => $instrument_id,
			) ),
		) );
	}

	// ------------------------------------------------------------------
	// Step 13: Assessment Instances & Activity States
	// ------------------------------------------------------------------

	/**
	 * Create teacher and children assessment instances plus activity states.
	 *
	 * @param array $enrollments         Keyed by row index.
	 * @param int   $cohort_id           Cohort ID.
	 * @param array $pathway_data        Pathway and activity IDs.
	 * @param int   $instrument_id       Teacher assessment instrument ID.
	 * @param array $classrooms          Keyed by "center_name::classroom_name".
	 * @param array $center_map          Keyed by center name => orgunit_id.
	 * @param array $teacher_roster_data Teacher roster rows.
	 */
	private function seed_assessment_instances( $enrollments, $cohort_id, $pathway_data, $instrument_id, $classrooms, $center_map, $teacher_roster_data ) {
		global $wpdb;
		$prefix       = $wpdb->prefix;
		$now          = current_time( 'mysql' );
		$tsa_count    = 0;
		$ca_count     = 0;
		$state_count  = 0;

		foreach ( $enrollments as $idx => $enrollment ) {
			$eid = $enrollment['enrollment_id'];

			// Teacher Assessment Instance: PRE.
			$wpdb->insert( $prefix . 'hl_teacher_assessment_instance', array(
				'instance_uuid'      => HL_DB_Utils::generate_uuid(),
				'cohort_id'          => $cohort_id,
				'enrollment_id'      => $eid,
				'activity_id'        => $pathway_data['tsa_pre_id'],
				'phase'              => 'pre',
				'instrument_id'      => $instrument_id,
				'instrument_version' => '1.0',
				'status'             => 'not_started',
				'created_at'         => $now,
			) );
			$tsa_count++;

			// Teacher Assessment Instance: POST.
			$wpdb->insert( $prefix . 'hl_teacher_assessment_instance', array(
				'instance_uuid'      => HL_DB_Utils::generate_uuid(),
				'cohort_id'          => $cohort_id,
				'enrollment_id'      => $eid,
				'activity_id'        => $pathway_data['tsa_post_id'],
				'phase'              => 'post',
				'instrument_id'      => $instrument_id,
				'instrument_version' => '1.0',
				'status'             => 'not_started',
				'created_at'         => $now,
			) );
			$tsa_count++;

			// Children Assessment Instances: look up teacher's classroom.
			$center_name    = $enrollment['center_name'];
			$classroom_name = $enrollment['classroom_name'];
			$cr_key         = $center_name . '::' . $classroom_name;
			$center_id      = $this->match_center_name( $center_name, $center_map );

			if ( isset( $classrooms[ $cr_key ] ) ) {
				$classroom_id = $classrooms[ $cr_key ]['classroom_id'];
				$age_band     = $classrooms[ $cr_key ]['age_band'];

				// Children Assessment Instance: PRE.
				$wpdb->insert( $prefix . 'hl_children_assessment_instance', array(
					'instance_uuid'      => HL_DB_Utils::generate_uuid(),
					'cohort_id'          => $cohort_id,
					'enrollment_id'      => $eid,
					'activity_id'        => $pathway_data['ca_pre_id'],
					'classroom_id'       => $classroom_id,
					'center_id'          => $center_id,
					'phase'              => 'pre',
					'instrument_age_band' => $age_band,
					'status'             => 'not_started',
					'created_at'         => $now,
				) );
				$ca_count++;

				// Children Assessment Instance: POST.
				$wpdb->insert( $prefix . 'hl_children_assessment_instance', array(
					'instance_uuid'      => HL_DB_Utils::generate_uuid(),
					'cohort_id'          => $cohort_id,
					'enrollment_id'      => $eid,
					'activity_id'        => $pathway_data['ca_post_id'],
					'classroom_id'       => $classroom_id,
					'center_id'          => $center_id,
					'phase'              => 'post',
					'instrument_age_band' => $age_band,
					'status'             => 'not_started',
					'created_at'         => $now,
				) );
				$ca_count++;
			}

			// Activity States for all 4 activities.
			// PRE activities: not_started.
			$wpdb->insert( $prefix . 'hl_activity_state', array(
				'enrollment_id'      => $eid,
				'activity_id'        => $pathway_data['tsa_pre_id'],
				'completion_percent' => 0,
				'completion_status'  => 'not_started',
				'last_computed_at'   => $now,
			) );
			$state_count++;

			$wpdb->insert( $prefix . 'hl_activity_state', array(
				'enrollment_id'      => $eid,
				'activity_id'        => $pathway_data['ca_pre_id'],
				'completion_percent' => 0,
				'completion_status'  => 'not_started',
				'last_computed_at'   => $now,
			) );
			$state_count++;

			// POST activities: locked.
			$wpdb->insert( $prefix . 'hl_activity_state', array(
				'enrollment_id'      => $eid,
				'activity_id'        => $pathway_data['tsa_post_id'],
				'completion_percent' => 0,
				'completion_status'  => 'locked',
				'last_computed_at'   => $now,
			) );
			$state_count++;

			$wpdb->insert( $prefix . 'hl_activity_state', array(
				'enrollment_id'      => $eid,
				'activity_id'        => $pathway_data['ca_post_id'],
				'completion_percent' => 0,
				'completion_status'  => 'locked',
				'last_computed_at'   => $now,
			) );
			$state_count++;
		}

		WP_CLI::log( "  [13/14] Assessment instances: {$tsa_count} teacher + {$ca_count} children, activity states: {$state_count}" );
	}

	// ------------------------------------------------------------------
	// Step 14: Pathway Assignments
	// ------------------------------------------------------------------

	/**
	 * Create hl_pathway_assignment records for each enrollment.
	 *
	 * @param array $enrollments Keyed by row index.
	 * @param int   $pathway_id  Pathway ID.
	 */
	private function seed_pathway_assignments( $enrollments, $pathway_id ) {
		global $wpdb;
		$prefix = $wpdb->prefix;
		$count  = 0;

		foreach ( $enrollments as $enrollment ) {
			$wpdb->insert( $prefix . 'hl_pathway_assignment', array(
				'enrollment_id'     => $enrollment['enrollment_id'],
				'pathway_id'        => $pathway_id,
				'assigned_by_user_id' => 0,
				'assignment_type'   => 'role_default',
			) );
			$count++;
		}

		// Also update assigned_pathway_id on the enrollment record.
		$enrollment_repo = new HL_Enrollment_Repository();
		foreach ( $enrollments as $enrollment ) {
			$enrollment_repo->update( $enrollment['enrollment_id'], array(
				'assigned_pathway_id' => $pathway_id,
			) );
		}

		WP_CLI::log( "  [14/14] Pathway assignments created: {$count}" );
	}
}
