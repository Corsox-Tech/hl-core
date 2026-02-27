<?php
/**
 * WP-CLI command: wp hl-core seed-lutheran
 *
 * Seeds the Lutheran Services Florida control group data from
 * includes/cli/lutheran-seed-data.php (committed companion file).
 * Use --clean to remove all Lutheran data first.
 *
 * @package HL_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HL_CLI_Seed_Lutheran {

	/** Track code used to identify Lutheran seeded data. */
	const TRACK_CODE = 'LUTHERAN_CONTROL_2026';

	/** District code. */
	const DISTRICT_CODE = 'LSF_PALM_BEACH';

	/** Cohort (container) code. */
	const COHORT_CODE = 'B2E_LSF';

	/** User meta key to tag Lutheran seed users. */
	const SEED_META_KEY = '_hl_lutheran_seed';

	/**
	 * Roster school name aliases → full school info names.
	 *
	 * The teacher/child rosters use abbreviated school names that differ from
	 * the official names in the School Info spreadsheet. This map resolves them.
	 */
	private static $school_aliases = array(
		'South Bay HS/EHS'              => 'South Bay Head Start/Early Head Start',
		'West Palm Beach'               => 'West Palm Beach Head Start/Early Head Start',
		'Jupiter HS'                    => 'Jupiter Head Start',
		'Monica Turner FCCH'            => 'Monica Turner Faimly Child Care Home',
		'Patricia Oliver FCCH'          => 'Patricia Oliver Family Child Care Home',
		"Lisa's Lil' Wonders Childcare" => "Lisa's Lil Wonders Family Child Care Home",
		"Lisa's Lil' Wonder"            => "Lisa's Lil Wonders Family Child Care Home",
		'Nichola Griffiths-Butts FCCH'  => 'Nichola Griffiths-Butts Family Child Care Home',
		'Smart Kids College FCCH'       => 'Smart Kidz College Family Child Care Home',
		"My Precious Lillie's FCCH"     => 'My Precious Lillies Family Child Care Home',
		"My Precious Lillie's HCCF"     => 'My Precious Lillies Family Child Care Home',
		// Exact matches (listed for completeness; direct lookup handles these):
		// 'Bear Necessities FCCH'      => 'Bear Necessities FCCH',
		// 'Jessica Thurmond FCCH'      => 'Jessica Thurmond FCCH',
	);

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

		// Load extracted data arrays (committed file in cli/ directory).
		$data_file = __DIR__ . '/lutheran-seed-data.php';
		if ( ! file_exists( $data_file ) ) {
			WP_CLI::error( "Data file not found: {$data_file}" );
			return;
		}
		require $data_file;

		if ( ! isset( $school_info_data ) || ! isset( $teacher_roster_data ) || ! isset( $child_roster_data ) ) {
			WP_CLI::error( 'Data file must define $school_info_data, $teacher_roster_data, and $child_roster_data arrays.' );
			return;
		}

		WP_CLI::line( '' );
		WP_CLI::line( '=== HL Core Lutheran Control Group Seeder ===' );
		WP_CLI::line( '' );

		// Step 1: District.
		$district_id = $this->seed_district();

		// Step 2: Schools.
		$school_map = $this->seed_schools( $school_info_data, $district_id );

		// Log school alias resolutions for verification.
		$this->log_school_resolutions( $school_map );

		// Step 3: Track (run).
		$track_id = $this->seed_track( $district_id );

		// Step 4: Link schools to track.
		$this->link_schools_to_track( $track_id, $school_map );

		// Step 5: Cohort (container).
		$cohort_id = $this->seed_cohort( $track_id );

		// Step 6: Classrooms.
		$classrooms = $this->seed_classrooms( $teacher_roster_data, $school_map );

		// Step 7: WP Users (Teachers).
		$users = $this->seed_users( $teacher_roster_data );

		// Step 8: Enrollments.
		$enrollments = $this->seed_enrollments( $teacher_roster_data, $users, $track_id, $school_map, $district_id );

		// Step 9: Teaching Assignments.
		$this->seed_teaching_assignments( $teacher_roster_data, $enrollments, $classrooms, $school_map );

		// Step 10: Children.
		$this->seed_children( $child_roster_data, $classrooms, $school_map );

		// Step 10b: Freeze child age groups for this track.
		$frozen = HL_Child_Snapshot_Service::freeze_age_groups( $track_id );
		WP_CLI::log( "  [10b] Frozen age group snapshots: {$frozen}" );

		// Step 11: Pathway & Activities.
		$pathway_data = $this->seed_pathway( $track_id );

		// Step 12: B2E Teacher Assessment Instruments (PRE + POST).
		$instrument_ids = $this->seed_teacher_instruments();

		// Update activity external_ref with instrument_ids.
		$this->update_activity_instrument_refs( $pathway_data, $instrument_ids );

		// Step 12b: Child Assessment Instruments.
		$children_instruments = $this->seed_children_instruments();

		// Step 13: Assessment Instances.
		$this->seed_assessment_instances( $enrollments, $track_id, $pathway_data, $instrument_ids, $classrooms, $school_map, $teacher_roster_data, $children_instruments );

		// Step 14: Pathway Assignments.
		$this->seed_pathway_assignments( $enrollments, $pathway_data['pathway_id'] );

		WP_CLI::line( '' );
		WP_CLI::success( 'Lutheran control group data seeded successfully!' );
		WP_CLI::line( '' );
		WP_CLI::line( 'Summary:' );
		WP_CLI::line( "  District:     {$district_id} (code: " . self::DISTRICT_CODE . ')' );
		WP_CLI::line( '  Schools:      ' . count( $school_map ) );
		WP_CLI::line( "  Track:        {$track_id} (code: " . self::TRACK_CODE . ')' );
		WP_CLI::line( "  Cohort:       {$cohort_id} (code: " . self::COHORT_CODE . ')' );
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
				"SELECT track_id FROM {$wpdb->prefix}hl_track WHERE track_code = %s LIMIT 1",
				self::TRACK_CODE
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

		// Find the track.
		$track_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT track_id FROM {$prefix}hl_track WHERE track_code = %s LIMIT 1",
				self::TRACK_CODE
			)
		);

		if ( $track_id ) {
			// Get enrollment IDs.
			$enrollment_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT enrollment_id FROM {$prefix}hl_enrollment WHERE track_id = %d",
					$track_id
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

				// Delete child assessment instances and child rows.
				$ca_ids = $wpdb->get_col(
					"SELECT instance_id FROM {$prefix}hl_child_assessment_instance WHERE enrollment_id IN ({$in_ids})"
				);
				if ( ! empty( $ca_ids ) ) {
					$in_ca = implode( ',', array_map( 'intval', $ca_ids ) );
					$wpdb->query( "DELETE FROM {$prefix}hl_child_assessment_childrow WHERE instance_id IN ({$in_ca})" );
				}
				$wpdb->query( "DELETE FROM {$prefix}hl_child_assessment_instance WHERE enrollment_id IN ({$in_ids})" );

				// Delete teacher assessment instances.
				$wpdb->query( "DELETE FROM {$prefix}hl_teacher_assessment_instance WHERE enrollment_id IN ({$in_ids})" );
			}

			// Delete activities and their drip rules.
			$activity_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT activity_id FROM {$prefix}hl_activity WHERE track_id = %d",
					$track_id
				)
			);
			if ( ! empty( $activity_ids ) ) {
				$in_act = implode( ',', array_map( 'intval', $activity_ids ) );
				$wpdb->query( "DELETE FROM {$prefix}hl_activity_drip_rule WHERE activity_id IN ({$in_act})" );
			}
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$prefix}hl_activity WHERE track_id = %d", $track_id ) );

			// Delete pathway.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$prefix}hl_pathway WHERE track_id = %d", $track_id ) );

			// Delete enrollments.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$prefix}hl_enrollment WHERE track_id = %d", $track_id ) );

			// Delete track-school links.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$prefix}hl_track_school WHERE track_id = %d", $track_id ) );

			// Delete child track snapshots.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$prefix}hl_child_track_snapshot WHERE track_id = %d", $track_id ) );

			// Delete track.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$prefix}hl_track WHERE track_id = %d", $track_id ) );

			// Delete audit log entries.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$prefix}hl_audit_log WHERE track_id = %d", $track_id ) );

			WP_CLI::log( "  Deleted track {$track_id} and all related records." );
		}

		// Delete cohort (container).
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$prefix}hl_cohort WHERE cohort_code = %s",
				self::COHORT_CODE
			)
		);
		WP_CLI::log( '  Deleted cohort container (' . self::COHORT_CODE . ').' );

		// Delete district and schools.
		$district_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT orgunit_id FROM {$prefix}hl_orgunit WHERE orgunit_code = %s AND orgunit_type = 'district' LIMIT 1",
				self::DISTRICT_CODE
			)
		);

		if ( $district_id ) {
			$school_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT orgunit_id FROM {$prefix}hl_orgunit WHERE parent_orgunit_id = %d AND orgunit_type = 'school'",
					$district_id
				)
			);

			if ( ! empty( $school_ids ) ) {
				$in_c = implode( ',', array_map( 'intval', $school_ids ) );

				// Delete children and classrooms for these schools.
				$cls_ids = $wpdb->get_col(
					"SELECT classroom_id FROM {$prefix}hl_classroom WHERE school_id IN ({$in_c})"
				);
				if ( ! empty( $cls_ids ) ) {
					$in_cls = implode( ',', array_map( 'intval', $cls_ids ) );
					$wpdb->query( "DELETE FROM {$prefix}hl_child_classroom_current WHERE classroom_id IN ({$in_cls})" );
					$wpdb->query( "DELETE FROM {$prefix}hl_child_classroom_history WHERE classroom_id IN ({$in_cls})" );
				}
				$wpdb->query( "DELETE FROM {$prefix}hl_child WHERE school_id IN ({$in_c})" );
				$wpdb->query( "DELETE FROM {$prefix}hl_classroom WHERE school_id IN ({$in_c})" );

				// Delete schools.
				$wpdb->query( "DELETE FROM {$prefix}hl_orgunit WHERE orgunit_id IN ({$in_c})" );
			}

			// Delete district.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$prefix}hl_orgunit WHERE orgunit_id = %d", $district_id ) );
			WP_CLI::log( '  Deleted district and ' . count( $school_ids ) . ' schools.' );
		}

		// Delete B2E teacher instrument only if no other seeder is using it.
		$other_track = $wpdb->get_var(
			"SELECT track_id FROM {$prefix}hl_track WHERE track_code IN ('DEMO-2026','ELC-PB-2026') LIMIT 1"
		);
		if ( ! $other_track ) {
			$wpdb->query(
				"DELETE FROM {$prefix}hl_teacher_assessment_instrument WHERE instrument_key IN ('b2e_self_assessment','b2e_self_assessment_pre','b2e_self_assessment_post')"
			);
			WP_CLI::log( '  Deleted B2E teacher assessment instruments.' );

			// Also delete children instruments seeded by this command.
			$wpdb->query( "DELETE FROM {$prefix}hl_instrument WHERE name LIKE 'Lutheran %'" );
			WP_CLI::log( '  Deleted Lutheran child assessment instruments.' );
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
		if ( in_array( $lower, array(
			'infants',
			'0-12 months',
			'0 -12 months',
			'0-12months',
		), true ) ) {
			return 'infant';
		}

		// Toddler.
		if ( in_array( $lower, array(
			'toddlers',
			'1 year olds',
			'1-2 year olds',
			'1-2  year olds',
			'infants/tooddlers',
			'infants/toddlers',
			'2 year olds',
			'2 year old',
			'2-3 year olds',
			'1yr-2 1/2',
		), true ) ) {
			return 'toddler';
		}

		// Preschool.
		if ( in_array( $lower, array(
			'3 year olds',
			'3year old',
			'3year olds',
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
	// School name matching (fuzzy)
	// ------------------------------------------------------------------

	/**
	 * Match a roster school name (often abbreviated) to a school_map key.
	 *
	 * Resolution order:
	 * 1. Direct match against school_map keys
	 * 2. Explicit alias lookup (covers abbreviations, typos, spelling variants)
	 * 3. Fuzzy substring match (fallback)
	 *
	 * @param string $roster_name The abbreviated name from the roster.
	 * @param array  $school_map  Keyed by full school name => school orgunit_id.
	 * @return int|null The school orgunit_id or null if not matched.
	 */
	private function match_school_name( $roster_name, $school_map ) {
		// 1. Direct match.
		if ( isset( $school_map[ $roster_name ] ) ) {
			return $school_map[ $roster_name ];
		}

		// 2. Explicit alias lookup.
		if ( isset( self::$school_aliases[ $roster_name ] ) ) {
			$canonical = self::$school_aliases[ $roster_name ];
			if ( isset( $school_map[ $canonical ] ) ) {
				return $school_map[ $canonical ];
			}
		}

		// 3. Fuzzy: check if roster name is contained in any school name or vice versa.
		foreach ( $school_map as $full_name => $id ) {
			if ( stripos( $full_name, $roster_name ) !== false ) {
				return $id;
			}
			if ( stripos( $roster_name, $full_name ) !== false ) {
				return $id;
			}
		}

		return null;
	}

	// ------------------------------------------------------------------
	// School name resolution helper
	// ------------------------------------------------------------------

	/**
	 * Resolve a roster school name to the canonical school info name.
	 *
	 * Uses the $school_aliases map. If no alias exists, the original name
	 * is returned as-is (it may already be canonical).
	 *
	 * @param string $roster_name The school name from the roster data.
	 * @return string The canonical school name.
	 */
	private function resolve_school_name( $roster_name ) {
		if ( isset( self::$school_aliases[ $roster_name ] ) ) {
			return self::$school_aliases[ $roster_name ];
		}
		return $roster_name;
	}

	/**
	 * Log all school alias resolutions that were actually used during seeding.
	 *
	 * Called once after all seed steps to show which roster names mapped to which canonical names.
	 *
	 * @param array $school_map Keyed by canonical school name => orgunit_id.
	 */
	private function log_school_resolutions( $school_map ) {
		$resolved = 0;
		foreach ( self::$school_aliases as $roster_name => $canonical ) {
			if ( isset( $school_map[ $canonical ] ) ) {
				WP_CLI::log( "    Alias resolved: \"{$roster_name}\" → \"{$canonical}\" (id={$school_map[$canonical]})" );
				$resolved++;
			}
		}
		if ( $resolved === 0 ) {
			WP_CLI::log( '    No school aliases were resolved (all names matched directly).' );
		} else {
			WP_CLI::log( "    Total alias resolutions: {$resolved}" );
		}
	}

	// ------------------------------------------------------------------
	// Slugify helper
	// ------------------------------------------------------------------

	/**
	 * Generate a code from a name (uppercase, underscored).
	 *
	 * @param string $name The school name.
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
	// Step 2: Schools
	// ------------------------------------------------------------------

	/**
	 * Create schools from $school_info_data.
	 *
	 * @param array $school_info_data Rows from extracted data. Each row: [index, name, leader, address, ...].
	 * @param int   $district_id      Parent district orgunit_id.
	 * @return array Keyed by school name => orgunit_id.
	 */
	private function seed_schools( $school_info_data, $district_id ) {
		$repo       = new HL_OrgUnit_Repository();
		$school_map = array();

		foreach ( $school_info_data as $row ) {
			$name    = isset( $row[1] ) ? trim( $row[1] ) : '';
			$address = isset( $row[3] ) ? trim( $row[3] ) : '';
			$leader  = isset( $row[2] ) ? trim( $row[2] ) : '';

			if ( empty( $name ) ) {
				continue;
			}

			$code     = $this->slugify_code( $name );
			$metadata = wp_json_encode( array(
				'address'        => $address,
				'school_leader'  => $leader,
			) );

			$school_id = $repo->create( array(
				'name'              => $name,
				'orgunit_type'      => 'school',
				'orgunit_code'      => $code,
				'parent_orgunit_id' => $district_id,
				'metadata'          => $metadata,
			) );

			$school_map[ $name ] = $school_id;
		}

		WP_CLI::log( '  [2/14] Schools: ' . count( $school_map ) . ' created' );
		return $school_map;
	}

	// ------------------------------------------------------------------
	// Step 3: Track (run)
	// ------------------------------------------------------------------

	/**
	 * Create the Lutheran control group track.
	 *
	 * @param int $district_id District orgunit_id.
	 * @return int Track ID.
	 */
	private function seed_track( $district_id ) {
		$repo = new HL_Track_Repository();

		$track_id = $repo->create( array(
			'track_name'       => 'Lutheran Control Group 2026',
			'track_code'       => self::TRACK_CODE,
			'district_id'      => $district_id,
			'status'           => 'active',
			'is_control_group' => 1,
			'start_date'       => '2026-02-15',
			'end_date'         => '2026-07-31',
		) );

		WP_CLI::log( "  [3/14] Track created: id={$track_id}, code=" . self::TRACK_CODE );
		return $track_id;
	}

	// ------------------------------------------------------------------
	// Step 4: Link schools to track
	// ------------------------------------------------------------------

	/**
	 * Insert hl_track_school records for each school.
	 *
	 * @param int   $track_id  Track ID.
	 * @param array $school_map Keyed by school name => orgunit_id.
	 */
	private function link_schools_to_track( $track_id, $school_map ) {
		global $wpdb;

		foreach ( $school_map as $school_id ) {
			$wpdb->insert( $wpdb->prefix . 'hl_track_school', array(
				'track_id' => $track_id,
				'school_id' => $school_id,
			) );
		}

		WP_CLI::log( '  [4/14] Linked ' . count( $school_map ) . ' schools to track' );
	}

	// ------------------------------------------------------------------
	// Step 5: Cohort (container)
	// ------------------------------------------------------------------

	/**
	 * Create or find the B2E_LSF cohort (container), then assign the track.
	 *
	 * @param int $track_id The Lutheran control track ID.
	 * @return int Cohort (container) ID.
	 */
	private function seed_cohort( $track_id ) {
		global $wpdb;
		$prefix = $wpdb->prefix;

		// Check if cohort (container) already exists.
		$cohort_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT cohort_id FROM {$prefix}hl_cohort WHERE cohort_code = %s LIMIT 1",
				self::COHORT_CODE
			)
		);

		if ( ! $cohort_id ) {
			$wpdb->insert( $prefix . 'hl_cohort', array(
				'cohort_uuid' => HL_DB_Utils::generate_uuid(),
				'cohort_name' => 'B2E Mastery - Lutheran Services Florida',
				'cohort_code' => self::COHORT_CODE,
				'status'      => 'active',
			) );
			$cohort_id = $wpdb->insert_id;
		}

		// Assign the control track to this cohort (container).
		$wpdb->update(
			$prefix . 'hl_track',
			array( 'cohort_id' => $cohort_id ),
			array( 'track_id' => $track_id )
		);

		WP_CLI::log( "  [5/14] Cohort (container): id={$cohort_id}, code=" . self::COHORT_CODE );
		return $cohort_id;
	}

	// ------------------------------------------------------------------
	// Step 6: Classrooms
	// ------------------------------------------------------------------

	/**
	 * Extract unique classrooms from teacher roster and create hl_classroom records.
	 *
	 * @param array $teacher_roster_data Rows from extracted data.
	 * @param array $school_map          Keyed by school name => orgunit_id.
	 * @return array Keyed by "school_name::classroom_name" => array with classroom_id, school_id, age_band.
	 */
	private function seed_classrooms( $teacher_roster_data, $school_map ) {
		$svc        = new HL_Classroom_Service();
		$classrooms = array();
		$seen       = array();

		foreach ( $teacher_roster_data as $row ) {
			$school_name    = isset( $row[0] ) ? trim( $row[0] ) : '';
			$classroom_name = isset( $row[4] ) ? trim( $row[4] ) : '';
			$age_group_raw  = isset( $row[5] ) ? trim( $row[5] ) : '';

			if ( empty( $school_name ) || empty( $classroom_name ) ) {
				continue;
			}

			// Resolve abbreviated roster name to canonical school name for consistent keys.
			$canonical_school = $this->resolve_school_name( $school_name );
			$key = $canonical_school . '::' . $classroom_name;
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;

			$school_id = $this->match_school_name( $school_name, $school_map );
			if ( ! $school_id ) {
				WP_CLI::warning( "School not matched for classroom: {$school_name} :: {$classroom_name}" );
				continue;
			}

			$age_band = $this->normalize_age_band( $age_group_raw );

			$id = $svc->create_classroom( array(
				'classroom_name' => $classroom_name,
				'school_id'      => $school_id,
				'age_band'       => $age_band,
			) );

			if ( is_wp_error( $id ) ) {
				WP_CLI::warning( "Classroom creation error ({$key}): " . $id->get_error_message() );
				continue;
			}

			$classrooms[ $key ] = array(
				'classroom_id' => $id,
				'school_id'    => $school_id,
				'school_name'  => $canonical_school,
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
			$full_name = isset( $row[1] ) ? trim( $row[1] ) : '';
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
	 * @param int   $track_id           Lutheran control track ID.
	 * @param array $school_map          Keyed by school name => orgunit_id.
	 * @param int   $district_id         District orgunit_id.
	 * @return array Keyed by row index => array with enrollment_id, user_id, school_name, classroom_name.
	 */
	private function seed_enrollments( $teacher_roster_data, $users, $track_id, $school_map, $district_id ) {
		$repo        = new HL_Enrollment_Repository();
		$enrollments = array();

		foreach ( $teacher_roster_data as $idx => $row ) {
			if ( ! isset( $users[ $idx ] ) || empty( $users[ $idx ]['user_id'] ) ) {
				continue;
			}

			$school_name    = isset( $row[0] ) ? trim( $row[0] ) : '';
			$classroom_name = isset( $row[4] ) ? trim( $row[4] ) : '';
			$school_id      = $this->match_school_name( $school_name, $school_map );

			$eid = $repo->create( array(
				'user_id'     => $users[ $idx ]['user_id'],
				'track_id'   => $track_id,
				'roles'       => array( 'teacher' ),
				'status'      => 'active',
				'school_id'   => $school_id,
				'district_id' => $district_id,
			) );

			$canonical_school = $this->resolve_school_name( $school_name );
			$enrollments[ $idx ] = array(
				'enrollment_id'  => $eid,
				'user_id'        => $users[ $idx ]['user_id'],
				'school_name'    => $canonical_school,
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
	 * @param array $classrooms          Keyed by "school_name::classroom_name".
	 * @param array $school_map          Keyed by school name => orgunit_id.
	 */
	private function seed_teaching_assignments( $teacher_roster_data, $enrollments, $classrooms, $school_map ) {
		// Suppress auto-generation of child assessment instances during seeding.
		// The seeder creates instances explicitly in step 13 with proper activity_id,
		// phase, and instrument_id values. Without this, the hook fires before
		// instruments and pathway exist, creating instances with NULL values.
		remove_all_actions( 'hl_core_teaching_assignment_changed' );

		$svc   = new HL_Classroom_Service();
		$count = 0;

		foreach ( $teacher_roster_data as $idx => $row ) {
			if ( ! isset( $enrollments[ $idx ] ) ) {
				continue;
			}

			$school_name    = isset( $row[0] ) ? trim( $row[0] ) : '';
			$classroom_name = isset( $row[4] ) ? trim( $row[4] ) : '';
			$canonical_school = $this->resolve_school_name( $school_name );
			$key            = $canonical_school . '::' . $classroom_name;

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
	 * @param array $classrooms        Keyed by "school_name::classroom_name".
	 * @param array $school_map        Keyed by school name => orgunit_id.
	 */
	private function seed_children( $child_roster_data, $classrooms, $school_map ) {
		$repo      = new HL_Child_Repository();
		$svc       = new HL_Classroom_Service();
		$total     = 0;
		$unmatched = 0;

		foreach ( $child_roster_data as $row ) {
			$school_name    = isset( $row[0] ) ? trim( $row[0] ) : '';
			$child_name     = isset( $row[1] ) ? trim( $row[1] ) : '';
			$classroom_name = isset( $row[2] ) ? trim( $row[2] ) : '';
			$age_group_raw  = isset( $row[3] ) ? trim( $row[3] ) : '';
			$dob            = isset( $row[5] ) ? trim( $row[5] ) : '';
			$gender         = isset( $row[6] ) ? trim( $row[6] ) : '';
			$ethnicity      = isset( $row[7] ) ? trim( $row[7] ) : '';
			$language       = isset( $row[8] ) ? trim( $row[8] ) : '';

			if ( empty( $child_name ) || empty( $school_name ) ) {
				continue;
			}

			// Split child name into first and last.
			$name_parts = explode( ' ', $child_name, 2 );
			$first_name = $name_parts[0];
			$last_name  = isset( $name_parts[1] ) ? $name_parts[1] : '';

			$school_id = $this->match_school_name( $school_name, $school_map );
			if ( ! $school_id ) {
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
				'school_id'  => $school_id,
				'ethnicity'  => $ethnicity,
				'metadata'   => $metadata,
			) );

			if ( $child_id ) {
				$canonical_school = $this->resolve_school_name( $school_name );
				$cr_key = $canonical_school . '::' . $classroom_name;
				if ( isset( $classrooms[ $cr_key ] ) ) {
					$svc->assign_child_to_classroom( $child_id, $classrooms[ $cr_key ]['classroom_id'], 'Lutheran seed initial assignment' );
				}
				$total++;
			}
		}

		$msg = "  [10/14] Children created: {$total}";
		if ( $unmatched > 0 ) {
			$msg .= " ({$unmatched} unmatched schools)";
		}
		WP_CLI::log( $msg );
	}

	// ------------------------------------------------------------------
	// Step 11: Pathway & Activities
	// ------------------------------------------------------------------

	/**
	 * Create the control group assessment pathway with 4 activities.
	 *
	 * @param int $track_id Lutheran control track ID.
	 * @return array Pathway data with pathway_id, activity IDs.
	 */
	private function seed_pathway( $track_id ) {
		$svc = new HL_Pathway_Service();

		$pathway_id = $svc->create_pathway( array(
			'pathway_name'  => 'Control Group Assessments',
			'pathway_code'  => 'LUTHERAN_CTRL_ASSESSMENTS',
			'track_id'     => $track_id,
			'target_roles'  => array( 'teacher' ),
			'active_status' => 1,
		) );

		// Activity 1: Teacher Self-Assessment (Pre).
		$tsa_pre_id = $svc->create_activity( array(
			'title'         => 'Teacher Self-Assessment (Pre)',
			'pathway_id'    => $pathway_id,
			'track_id'     => $track_id,
			'activity_type' => 'teacher_self_assessment',
			'weight'        => 1.0,
			'ordering_hint' => 1,
			'external_ref'  => wp_json_encode( array( 'phase' => 'pre' ) ),
		) );

		// Activity 2: Child Assessment (Pre).
		$ca_pre_id = $svc->create_activity( array(
			'title'         => 'Child Assessment (Pre)',
			'pathway_id'    => $pathway_id,
			'track_id'     => $track_id,
			'activity_type' => 'child_assessment',
			'weight'        => 1.0,
			'ordering_hint' => 2,
			'external_ref'  => wp_json_encode( array( 'phase' => 'pre' ) ),
		) );

		// Activity 3: Teacher Self-Assessment (Post).
		$tsa_post_id = $svc->create_activity( array(
			'title'         => 'Teacher Self-Assessment (Post)',
			'pathway_id'    => $pathway_id,
			'track_id'     => $track_id,
			'activity_type' => 'teacher_self_assessment',
			'weight'        => 1.0,
			'ordering_hint' => 3,
			'external_ref'  => wp_json_encode( array( 'phase' => 'post' ) ),
		) );

		// Activity 4: Child Assessment (Post).
		$ca_post_id = $svc->create_activity( array(
			'title'         => 'Child Assessment (Post)',
			'pathway_id'    => $pathway_id,
			'track_id'     => $track_id,
			'activity_type' => 'child_assessment',
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
	 * @return array ['pre' => int, 'post' => int] Instrument IDs.
	 */
	private function seed_teacher_instruments() {
		global $wpdb;
		$prefix       = $wpdb->prefix;
		$scale_labels = wp_json_encode( HL_CLI_Seed_Demo::get_b2e_instrument_scale_labels() );
		$ids          = array();

		// PRE instrument.
		$existing_pre = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT instrument_id FROM {$prefix}hl_teacher_assessment_instrument WHERE instrument_key = %s LIMIT 1",
				'b2e_self_assessment_pre'
			)
		);

		if ( $existing_pre ) {
			$ids['pre'] = (int) $existing_pre;
		} else {
			$wpdb->insert( $prefix . 'hl_teacher_assessment_instrument', array(
				'instrument_name'    => 'Teacher Self-Assessment',
				'instrument_key'     => 'b2e_self_assessment_pre',
				'instrument_version' => '1.0',
				'sections'           => wp_json_encode( HL_CLI_Seed_Demo::get_b2e_instrument_sections_pre() ),
				'scale_labels'       => $scale_labels,
				'instructions'       => HL_CLI_Seed_Demo::get_b2e_instrument_instructions_pre(),
				'status'             => 'active',
				'created_at'         => current_time( 'mysql' ),
			) );
			$ids['pre'] = $wpdb->insert_id;
		}

		// POST instrument.
		$existing_post = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT instrument_id FROM {$prefix}hl_teacher_assessment_instrument WHERE instrument_key = %s LIMIT 1",
				'b2e_self_assessment_post'
			)
		);

		if ( $existing_post ) {
			$ids['post'] = (int) $existing_post;
		} else {
			$wpdb->insert( $prefix . 'hl_teacher_assessment_instrument', array(
				'instrument_name'    => 'Teacher Self-Assessment',
				'instrument_key'     => 'b2e_self_assessment_post',
				'instrument_version' => '1.0',
				'sections'           => wp_json_encode( HL_CLI_Seed_Demo::get_b2e_instrument_sections_post() ),
				'scale_labels'       => $scale_labels,
				'instructions'       => HL_CLI_Seed_Demo::get_b2e_instrument_instructions_post(),
				'status'             => 'active',
				'created_at'         => current_time( 'mysql' ),
			) );
			$ids['post'] = $wpdb->insert_id;
		}

		WP_CLI::log( "  [12/14] B2E Teacher Assessment Instruments: PRE id={$ids['pre']}, POST id={$ids['post']}" );
		return $ids;
	}

	/**
	 * Seed child assessment instruments (infant, toddler, preschool, mixed).
	 *
	 * @return array Keyed by age band: 'infant' => instrument_id, etc.
	 */
	private function seed_children_instruments() {
		global $wpdb;
		$prefix = $wpdb->prefix;

		$types = array(
			'infant'    => array( 'name' => 'Lutheran Infant Assessment',    'type' => 'children_infant' ),
			'toddler'   => array( 'name' => 'Lutheran Toddler Assessment',   'type' => 'children_toddler' ),
			'preschool' => array( 'name' => 'Lutheran Preschool Assessment', 'type' => 'children_preschool' ),
			'mixed'     => array( 'name' => 'Lutheran Mixed-Age Assessment', 'type' => 'children_mixed' ),
		);

		// Build per-age-band questions from the B2E assessment data.
		$b2e_data = HL_CLI_Seed_Demo::get_child_assessment_questions();
		$scale    = HL_CLI_Seed_Demo::get_child_assessment_scale();
		$allowed  = array_map( 'strval', array_keys( $scale ) );

		$instruments = array();
		foreach ( $types as $band => $info ) {
			// Use the age-band-specific B2E question if available, otherwise use preschool as fallback.
			$source_band = isset( $b2e_data[ $band ] ) ? $band : 'preschool';
			$q_data      = $b2e_data[ $source_band ];

			$questions = wp_json_encode( array(
				array(
					'question_id'    => 'q1',
					'type'           => 'likert',
					'prompt_text'    => $q_data['question'],
					'required'       => true,
					'allowed_values' => $allowed,
				),
			) );
			// Skip if already exists.
			$existing = $wpdb->get_var( $wpdb->prepare(
				"SELECT instrument_id FROM {$prefix}hl_instrument WHERE instrument_type = %s LIMIT 1",
				$info['type']
			) );
			if ( $existing ) {
				$instruments[ $band ] = (int) $existing;
				continue;
			}

			$wpdb->insert( $prefix . 'hl_instrument', array(
				'instrument_uuid' => wp_generate_uuid4(),
				'name'            => $info['name'],
				'instrument_type' => $info['type'],
				'version'         => '1.0',
				'questions'       => $questions,
				'behavior_key'    => wp_json_encode( HL_CLI_Seed_Demo::get_behavior_key_for_band( $band ) ),
				'instructions'    => HL_CLI_Seed_Demo::get_default_child_assessment_instructions(),
				'effective_from'  => '2026-01-01',
			) );
			$instruments[ $band ] = $wpdb->insert_id;
		}

		WP_CLI::log( '  [12b/14] Child Assessment Instruments created: ' . implode( ', ', array_map( function( $b, $id ) {
			return "{$b}={$id}";
		}, array_keys( $instruments ), $instruments ) ) );

		return $instruments;
	}

	/**
	 * Update the TSA activity external_ref to include the instrument_ids.
	 *
	 * @param array $pathway_data   Pathway and activity IDs.
	 * @param array $instrument_ids ['pre' => int, 'post' => int].
	 */
	private function update_activity_instrument_refs( $pathway_data, $instrument_ids ) {
		$svc = new HL_Pathway_Service();

		$svc->update_activity( $pathway_data['tsa_pre_id'], array(
			'external_ref' => wp_json_encode( array(
				'phase'                 => 'pre',
				'teacher_instrument_id' => $instrument_ids['pre'],
			) ),
		) );

		$svc->update_activity( $pathway_data['tsa_post_id'], array(
			'external_ref' => wp_json_encode( array(
				'phase'                 => 'post',
				'teacher_instrument_id' => $instrument_ids['post'],
			) ),
		) );
	}

	// ------------------------------------------------------------------
	// Step 13: Assessment Instances & Activity States
	// ------------------------------------------------------------------

	/**
	 * Create teacher and child assessment instances plus activity states.
	 *
	 * @param array $enrollments           Keyed by row index.
	 * @param int   $track_id             Track ID.
	 * @param array $pathway_data          Pathway and activity IDs.
	 * @param array $instrument_ids        ['pre' => int, 'post' => int].
	 * @param array $classrooms            Keyed by "school_name::classroom_name".
	 * @param array $school_map            Keyed by school name => orgunit_id.
	 * @param array $teacher_roster_data   Teacher roster rows.
	 * @param array $children_instruments  Keyed by age band: 'infant' => id, etc.
	 */
	private function seed_assessment_instances( $enrollments, $track_id, $pathway_data, $instrument_ids, $classrooms, $school_map, $teacher_roster_data, $children_instruments = array() ) {
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
				'track_id'          => $track_id,
				'enrollment_id'      => $eid,
				'activity_id'        => $pathway_data['tsa_pre_id'],
				'phase'              => 'pre',
				'instrument_id'      => $instrument_ids['pre'],
				'instrument_version' => '1.0',
				'status'             => 'not_started',
				'created_at'         => $now,
			) );
			$tsa_count++;

			// Teacher Assessment Instance: POST.
			$wpdb->insert( $prefix . 'hl_teacher_assessment_instance', array(
				'instance_uuid'      => HL_DB_Utils::generate_uuid(),
				'track_id'          => $track_id,
				'enrollment_id'      => $eid,
				'activity_id'        => $pathway_data['tsa_post_id'],
				'phase'              => 'post',
				'instrument_id'      => $instrument_ids['post'],
				'instrument_version' => '1.0',
				'status'             => 'not_started',
				'created_at'         => $now,
			) );
			$tsa_count++;

			// Child Assessment Instances: look up teacher's classroom.
			$school_name    = $enrollment['school_name'];
			$classroom_name = $enrollment['classroom_name'];
			$cr_key         = $school_name . '::' . $classroom_name;
			$school_id      = $this->match_school_name( $school_name, $school_map );

			if ( isset( $classrooms[ $cr_key ] ) ) {
				$classroom_id = $classrooms[ $cr_key ]['classroom_id'];
				$age_band     = $classrooms[ $cr_key ]['age_band'];

				// Resolve children instrument for this age band (fallback to mixed).
				$ci_id = isset( $children_instruments[ $age_band ] ) ? $children_instruments[ $age_band ] : null;
				if ( ! $ci_id && isset( $children_instruments['mixed'] ) ) {
					$ci_id = $children_instruments['mixed'];
				}

				// Child Assessment Instance: PRE.
				$wpdb->insert( $prefix . 'hl_child_assessment_instance', array(
					'instance_uuid'       => HL_DB_Utils::generate_uuid(),
					'track_id'           => $track_id,
					'enrollment_id'       => $eid,
					'activity_id'         => $pathway_data['ca_pre_id'],
					'classroom_id'        => $classroom_id,
					'school_id'           => $school_id,
					'phase'               => 'pre',
					'instrument_age_band' => $age_band,
					'instrument_id'       => $ci_id,
					'instrument_version'  => $ci_id ? '1.0' : null,
					'status'              => 'not_started',
					'created_at'          => $now,
				) );
				$ca_count++;

				// Child Assessment Instance: POST.
				$wpdb->insert( $prefix . 'hl_child_assessment_instance', array(
					'instance_uuid'       => HL_DB_Utils::generate_uuid(),
					'track_id'           => $track_id,
					'enrollment_id'       => $eid,
					'activity_id'         => $pathway_data['ca_post_id'],
					'classroom_id'        => $classroom_id,
					'school_id'           => $school_id,
					'phase'               => 'post',
					'instrument_age_band' => $age_band,
					'instrument_id'       => $ci_id,
					'instrument_version'  => $ci_id ? '1.0' : null,
					'status'              => 'not_started',
					'created_at'          => $now,
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
