<?php
/**
 * WP-CLI command: wp hl-core provision-lutheran
 *
 * Production-safe provisioning for Lutheran Services Florida control group.
 * Finds existing WP users by email — NEVER creates or deletes users.
 * Idempotent: safe to run multiple times. No --clean flag.
 *
 * @package HL_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HL_CLI_Provision_Lutheran {

	/** Partnership code. */
	const PARTNERSHIP_CODE = 'LUTHERAN_CONTROL_2026';

	/** District code. */
	const DISTRICT_CODE = 'LSF_PALM_BEACH';

	/** Cohort (container) code. */
	const COHORT_CODE = 'B2E_LSF';

	/** @var bool Dry-run mode — log what would happen without writing. */
	private $dry_run = false;

	/** @var array Counters: entity_type => ['found' => int, 'created' => int]. */
	private $counters = array();

	/** @var array Users not found by email: ['name' => string, 'email' => string]. */
	private $missing_users = array();

	/**
	 * School name aliases (roster abbreviations → canonical school info names).
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
	);

	/**
	 * Register the WP-CLI command.
	 */
	public static function register() {
		WP_CLI::add_command( 'hl-core provision-lutheran', array( new self(), 'run' ) );
	}

	// ------------------------------------------------------------------
	// Main entry point
	// ------------------------------------------------------------------

	/**
	 * Provision Lutheran Services Florida control group data.
	 *
	 * Finds existing WP users by email — never creates or deletes users.
	 * Creates HL Core entities (district, schools, partnership, enrollments, etc.)
	 * only if they don't already exist. Idempotent and production-safe.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Show what would happen without writing to the database.
	 *
	 * ## EXAMPLES
	 *
	 *     wp hl-core provision-lutheran --dry-run
	 *     wp hl-core provision-lutheran
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Named args.
	 */
	public function run( $args, $assoc_args ) {
		$this->dry_run = isset( $assoc_args['dry-run'] );

		// Load extracted data arrays.
		$data_file = __DIR__ . '/lutheran-seed-data.php';
		if ( ! file_exists( $data_file ) ) {
			WP_CLI::error( "Data file not found: {$data_file}" );
			return;
		}
		require $data_file;

		if ( ! isset( $school_info_data ) || ! isset( $teacher_roster_data ) || ! isset( $child_roster_data ) ) {
			WP_CLI::error( 'Data file must define $school_info_data, $teacher_roster_data, and $child_roster_data.' );
			return;
		}

		$mode = $this->dry_run ? 'DRY RUN' : 'LIVE';
		WP_CLI::line( '' );
		WP_CLI::line( "=== HL Core Lutheran Provisioning ({$mode}) ===" );
		WP_CLI::line( '' );

		// Step 1: District.
		$district_id = $this->provision_district();

		// Step 2: Schools.
		$school_map = $this->provision_schools( $school_info_data, $district_id );

		// Step 3: Partnership.
		$partnership_id = $this->provision_partnership( $district_id );

		// Step 4: Phase.
		$phase_id = $this->provision_phase( $partnership_id );

		// Step 5: Partnership-School links.
		$this->provision_partnership_schools( $partnership_id, $school_map );

		// Step 6: Cohort container.
		$cohort_id = $this->provision_cohort( $partnership_id );

		// Step 7: Classrooms.
		$classrooms = $this->provision_classrooms( $teacher_roster_data, $school_map );

		// Step 8: WP Users (lookup only — no creation).
		$users = $this->lookup_users( $teacher_roster_data );

		// Step 9: Enrollments.
		$enrollments = $this->provision_enrollments( $teacher_roster_data, $users, $partnership_id, $school_map, $district_id );

		// Step 10: Teaching Assignments.
		$this->provision_teaching_assignments( $teacher_roster_data, $enrollments, $classrooms, $school_map );

		// Step 11: Children.
		$this->provision_children( $child_roster_data, $classrooms, $school_map );

		// Step 12: Freeze age groups.
		if ( $partnership_id && ! $this->dry_run ) {
			$frozen = HL_Child_Snapshot_Service::freeze_age_groups( $partnership_id );
			WP_CLI::log( "  [12] Frozen age group snapshots: {$frozen}" );
		} else {
			WP_CLI::log( '  [12] Freeze age groups: ' . ( $this->dry_run ? 'SKIP (dry run)' : 'SKIP (no partnership)' ) );
		}

		// Step 13: Pathway + 4 Components.
		$pathway_data = $this->provision_pathway( $partnership_id );

		// Step 14: Drip Rules.
		$this->provision_drip_rules( $pathway_data );

		// Step 15: Instruments.
		$instrument_ids     = $this->provision_teacher_instruments();
		$child_instruments  = $this->provision_child_instruments();

		// Update component external_ref with instrument IDs.
		$this->update_component_instrument_refs( $pathway_data, $instrument_ids );

		// Step 16: Assessment Instances.
		$this->provision_assessment_instances( $enrollments, $partnership_id, $pathway_data, $instrument_ids, $classrooms, $school_map, $teacher_roster_data, $child_instruments );

		// Step 17: Component States.
		$this->provision_component_states( $enrollments, $pathway_data );

		// Step 18: Pathway Assignments.
		$this->provision_pathway_assignments( $enrollments, $pathway_data );

		// Summary.
		$this->print_summary();
	}

	// ------------------------------------------------------------------
	// find_or_create helper
	// ------------------------------------------------------------------

	/**
	 * Generic find-or-create. Finds an entity using $finder; if not found, creates via $creator.
	 *
	 * In dry-run mode, would-create entities return null.
	 *
	 * @param string   $entity_type Label for counters (e.g., 'District', 'Schools').
	 * @param callable $finder      Returns existing ID/value or null.
	 * @param callable $creator     Returns new ID/value. Only called if finder returns null and not dry-run.
	 * @return mixed The found or created ID, or null in dry-run when would create.
	 */
	private function find_or_create( $entity_type, $finder, $creator ) {
		if ( ! isset( $this->counters[ $entity_type ] ) ) {
			$this->counters[ $entity_type ] = array( 'found' => 0, 'created' => 0 );
		}

		$existing = $finder();
		if ( $existing !== null && $existing !== false && $existing !== 0 ) {
			$this->counters[ $entity_type ]['found']++;
			return $existing;
		}

		if ( $this->dry_run ) {
			$this->counters[ $entity_type ]['created']++;
			return null;
		}

		$new_id = $creator();
		$this->counters[ $entity_type ]['created']++;
		return $new_id;
	}

	// ------------------------------------------------------------------
	// Step 1: District
	// ------------------------------------------------------------------

	private function provision_district() {
		global $wpdb;
		$prefix = $wpdb->prefix;

		$id = $this->find_or_create(
			'District',
			function () use ( $wpdb, $prefix ) {
				return $wpdb->get_var( $wpdb->prepare(
					"SELECT orgunit_id FROM {$prefix}hl_orgunit WHERE orgunit_code = %s AND orgunit_type = 'district' LIMIT 1",
					self::DISTRICT_CODE
				) );
			},
			function () {
				$repo = new HL_OrgUnit_Repository();
				return $repo->create( array(
					'name'         => 'Lutheran Services Florida - Palm Beach',
					'orgunit_type' => 'district',
					'orgunit_code' => self::DISTRICT_CODE,
				) );
			}
		);

		$status = $id ? 'FOUND' : ( $this->dry_run ? 'WOULD CREATE' : 'CREATED' );
		WP_CLI::log( "  [1] District: {$status}" . ( $id ? " (id={$id})" : '' ) );
		return $id;
	}

	// ------------------------------------------------------------------
	// Step 2: Schools
	// ------------------------------------------------------------------

	private function provision_schools( $school_info_data, $district_id ) {
		global $wpdb;
		$prefix    = $wpdb->prefix;
		$school_map = array();

		foreach ( $school_info_data as $row ) {
			$name = isset( $row[1] ) ? trim( $row[1] ) : '';
			if ( empty( $name ) ) {
				continue;
			}

			$code     = $this->slugify_code( $name );
			$address  = isset( $row[3] ) ? trim( $row[3] ) : '';
			$leader   = isset( $row[2] ) ? trim( $row[2] ) : '';

			$id = $this->find_or_create(
				'Schools',
				function () use ( $wpdb, $prefix, $code ) {
					return $wpdb->get_var( $wpdb->prepare(
						"SELECT orgunit_id FROM {$prefix}hl_orgunit WHERE orgunit_code = %s AND orgunit_type = 'school' LIMIT 1",
						$code
					) );
				},
				function () use ( $name, $code, $district_id, $address, $leader ) {
					$repo = new HL_OrgUnit_Repository();
					return $repo->create( array(
						'name'              => $name,
						'orgunit_type'      => 'school',
						'orgunit_code'      => $code,
						'parent_orgunit_id' => $district_id,
						'metadata'          => wp_json_encode( array(
							'address'       => $address,
							'school_leader' => $leader,
						) ),
					) );
				}
			);

			if ( $id ) {
				$school_map[ $name ] = $id;
			}
		}

		$c = $this->counters['Schools'];
		WP_CLI::log( "  [2] Schools: {$c['found']} found, {$c['created']} " . ( $this->dry_run ? 'would create' : 'created' ) );
		return $school_map;
	}

	// ------------------------------------------------------------------
	// Step 3: Partnership
	// ------------------------------------------------------------------

	private function provision_partnership( $district_id ) {
		global $wpdb;
		$prefix = $wpdb->prefix;

		$id = $this->find_or_create(
			'Partnership',
			function () use ( $wpdb, $prefix ) {
				return $wpdb->get_var( $wpdb->prepare(
					"SELECT partnership_id FROM {$prefix}hl_partnership WHERE partnership_code = %s LIMIT 1",
					self::PARTNERSHIP_CODE
				) );
			},
			function () use ( $district_id ) {
				$repo = new HL_Partnership_Repository();
				return $repo->create( array(
					'partnership_name'       => 'Lutheran Control Group 2026',
					'partnership_code'       => self::PARTNERSHIP_CODE,
					'district_id'      => $district_id,
					'status'           => 'active',
					'is_control_group' => 1,
					'start_date'       => '2026-02-15',
					'end_date'         => '2026-07-31',
				) );
			}
		);

		$status = $this->counters['Partnership']['found'] > 0 ? 'FOUND' : ( $this->dry_run ? 'WOULD CREATE' : 'CREATED' );
		WP_CLI::log( "  [3] Partnership: {$status}" . ( $id ? " (id={$id})" : '' ) );
		return $id;
	}

	// ------------------------------------------------------------------
	// Step 4: Phase
	// ------------------------------------------------------------------

	private function provision_phase( $partnership_id ) {
		global $wpdb;
		$prefix = $wpdb->prefix;

		if ( ! $partnership_id ) {
			WP_CLI::log( '  [4] Phase: SKIP (no partnership in dry run)' );
			return null;
		}

		$id = $this->find_or_create(
			'Phase',
			function () use ( $wpdb, $prefix, $partnership_id ) {
				return $wpdb->get_var( $wpdb->prepare(
					"SELECT phase_id FROM {$prefix}hl_phase WHERE partnership_id = %d AND phase_number = 1 LIMIT 1",
					$partnership_id
				) );
			},
			function () use ( $partnership_id ) {
				$svc = new HL_Phase_Service();
				return $svc->create_phase( array(
					'partnership_id'     => $partnership_id,
					'phase_name'   => 'Phase 1',
					'phase_number' => 1,
					'start_date'   => '2026-02-15',
					'end_date'     => '2026-07-31',
					'status'       => 'active',
				) );
			}
		);

		$status = $this->counters['Phase']['found'] > 0 ? 'FOUND' : ( $this->dry_run ? 'WOULD CREATE' : 'CREATED' );
		WP_CLI::log( "  [4] Phase: {$status}" . ( $id ? " (id={$id})" : '' ) );
		return $id;
	}

	// ------------------------------------------------------------------
	// Step 5: Partnership-School links
	// ------------------------------------------------------------------

	private function provision_partnership_schools( $partnership_id, $school_map ) {
		global $wpdb;
		$prefix = $wpdb->prefix;

		if ( ! $partnership_id ) {
			WP_CLI::log( '  [5] Partnership-School links: SKIP (no partnership in dry run)' );
			return;
		}

		$linked = 0;
		$found  = 0;

		foreach ( $school_map as $school_id ) {
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$prefix}hl_partnership_school WHERE partnership_id = %d AND school_id = %d",
				$partnership_id, $school_id
			) );

			if ( $exists ) {
				$found++;
				continue;
			}

			if ( ! $this->dry_run ) {
				$wpdb->insert( $prefix . 'hl_partnership_school', array(
					'partnership_id'  => $partnership_id,
					'school_id' => $school_id,
				) );
			}
			$linked++;
		}

		$this->counters['Partnership-School Links'] = array( 'found' => $found, 'created' => $linked );
		WP_CLI::log( "  [5] Partnership-School links: {$found} found, {$linked} " . ( $this->dry_run ? 'would create' : 'created' ) );
	}

	// ------------------------------------------------------------------
	// Step 6: Cohort container
	// ------------------------------------------------------------------

	private function provision_cohort( $partnership_id ) {
		global $wpdb;
		$prefix = $wpdb->prefix;

		$id = $this->find_or_create(
			'Cohort',
			function () use ( $wpdb, $prefix ) {
				return $wpdb->get_var( $wpdb->prepare(
					"SELECT cohort_id FROM {$prefix}hl_cohort WHERE cohort_code = %s LIMIT 1",
					self::COHORT_CODE
				) );
			},
			function () use ( $wpdb, $prefix ) {
				$wpdb->insert( $prefix . 'hl_cohort', array(
					'cohort_uuid' => HL_DB_Utils::generate_uuid(),
					'cohort_name' => 'B2E Mastery - Lutheran Services Florida',
					'cohort_code' => self::COHORT_CODE,
					'status'      => 'active',
				) );
				return $wpdb->insert_id;
			}
		);

		// Assign partnership to cohort.
		if ( $partnership_id && $id && ! $this->dry_run ) {
			$wpdb->update(
				$prefix . 'hl_partnership',
				array( 'cohort_id' => $id ),
				array( 'partnership_id' => $partnership_id )
			);
		}

		$status = $this->counters['Cohort']['found'] > 0 ? 'FOUND' : ( $this->dry_run ? 'WOULD CREATE' : 'CREATED' );
		WP_CLI::log( "  [6] Cohort: {$status}" . ( $id ? " (id={$id})" : '' ) );
		return $id;
	}

	// ------------------------------------------------------------------
	// Step 7: Classrooms
	// ------------------------------------------------------------------

	private function provision_classrooms( $teacher_roster_data, $school_map ) {
		global $wpdb;
		$prefix     = $wpdb->prefix;
		$classrooms = array();
		$seen       = array();

		foreach ( $teacher_roster_data as $row ) {
			$school_name    = isset( $row[0] ) ? trim( $row[0] ) : '';
			$classroom_name = isset( $row[4] ) ? trim( $row[4] ) : '';
			$age_group_raw  = isset( $row[5] ) ? trim( $row[5] ) : '';

			if ( empty( $school_name ) || empty( $classroom_name ) ) {
				continue;
			}

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

			$id = $this->find_or_create(
				'Classrooms',
				function () use ( $wpdb, $prefix, $school_id, $classroom_name ) {
					return $wpdb->get_var( $wpdb->prepare(
						"SELECT classroom_id FROM {$prefix}hl_classroom WHERE school_id = %d AND classroom_name = %s LIMIT 1",
						$school_id, $classroom_name
					) );
				},
				function () use ( $classroom_name, $school_id, $age_band ) {
					$svc = new HL_Classroom_Service();
					$id  = $svc->create_classroom( array(
						'classroom_name' => $classroom_name,
						'school_id'      => $school_id,
						'age_band'       => $age_band,
					) );
					return is_wp_error( $id ) ? null : $id;
				}
			);

			if ( $id ) {
				$classrooms[ $key ] = array(
					'classroom_id' => $id,
					'school_id'    => $school_id,
					'school_name'  => $canonical_school,
					'name'         => $classroom_name,
					'age_band'     => $age_band,
				);
			}
		}

		$c = $this->counters['Classrooms'] ?? array( 'found' => 0, 'created' => 0 );
		WP_CLI::log( "  [7] Classrooms: {$c['found']} found, {$c['created']} " . ( $this->dry_run ? 'would create' : 'created' ) );
		return $classrooms;
	}

	// ------------------------------------------------------------------
	// Step 8: WP User Lookup (NO creation)
	// ------------------------------------------------------------------

	private function lookup_users( $teacher_roster_data ) {
		$users     = array();
		$found     = 0;
		$not_found = 0;

		foreach ( $teacher_roster_data as $idx => $row ) {
			$full_name = isset( $row[1] ) ? trim( $row[1] ) : '';
			$email     = isset( $row[3] ) ? trim( $row[3] ) : '';

			if ( empty( $email ) || empty( $full_name ) ) {
				continue;
			}

			$wp_user = get_user_by( 'email', $email );

			if ( $wp_user ) {
				$users[ $idx ] = array(
					'user_id'   => $wp_user->ID,
					'email'     => $email,
					'full_name' => $full_name,
				);
				$found++;
			} else {
				$this->missing_users[] = array(
					'name'  => $full_name,
					'email' => $email,
				);
				$not_found++;
			}
		}

		$this->counters['Users'] = array( 'found' => $found, 'created' => $not_found );
		$total = $found + $not_found;
		WP_CLI::log( "  [8] Users: {$found}/{$total} found" . ( $not_found > 0 ? " ({$not_found} NOT FOUND)" : '' ) );
		return $users;
	}

	// ------------------------------------------------------------------
	// Step 9: Enrollments
	// ------------------------------------------------------------------

	private function provision_enrollments( $teacher_roster_data, $users, $partnership_id, $school_map, $district_id ) {
		$repo        = new HL_Enrollment_Repository();
		$enrollments = array();

		if ( ! $partnership_id ) {
			WP_CLI::log( '  [9] Enrollments: SKIP (no partnership in dry run)' );
			return $enrollments;
		}

		foreach ( $teacher_roster_data as $idx => $row ) {
			if ( ! isset( $users[ $idx ] ) || empty( $users[ $idx ]['user_id'] ) ) {
				continue;
			}

			$user_id        = $users[ $idx ]['user_id'];
			$school_name    = isset( $row[0] ) ? trim( $row[0] ) : '';
			$classroom_name = isset( $row[4] ) ? trim( $row[4] ) : '';
			$school_id      = $this->match_school_name( $school_name, $school_map );

			$eid = $this->find_or_create(
				'Enrollments',
				function () use ( $repo, $partnership_id, $user_id ) {
					$existing = $repo->get_by_partnership_and_user( $partnership_id, $user_id );
					return $existing ? $existing->enrollment_id : null;
				},
				function () use ( $repo, $user_id, $partnership_id, $school_id, $district_id ) {
					return $repo->create( array(
						'user_id'     => $user_id,
						'partnership_id'    => $partnership_id,
						'roles'       => array( 'teacher' ),
						'status'      => 'active',
						'school_id'   => $school_id,
						'district_id' => $district_id,
					) );
				}
			);

			if ( $eid ) {
				$canonical_school = $this->resolve_school_name( $school_name );
				$enrollments[ $idx ] = array(
					'enrollment_id'  => $eid,
					'user_id'        => $user_id,
					'school_name'    => $canonical_school,
					'classroom_name' => $classroom_name,
				);
			}
		}

		$c = $this->counters['Enrollments'] ?? array( 'found' => 0, 'created' => 0 );
		WP_CLI::log( "  [9] Enrollments: {$c['found']} found, {$c['created']} " . ( $this->dry_run ? 'would create' : 'created' ) );
		return $enrollments;
	}

	// ------------------------------------------------------------------
	// Step 10: Teaching Assignments
	// ------------------------------------------------------------------

	private function provision_teaching_assignments( $teacher_roster_data, $enrollments, $classrooms, $school_map ) {
		global $wpdb;
		$prefix = $wpdb->prefix;

		// Suppress auto-generation of child assessment instances during provisioning.
		remove_all_actions( 'hl_core_teaching_assignment_changed' );

		$found   = 0;
		$created = 0;

		foreach ( $teacher_roster_data as $idx => $row ) {
			if ( ! isset( $enrollments[ $idx ] ) ) {
				continue;
			}

			$school_name      = isset( $row[0] ) ? trim( $row[0] ) : '';
			$classroom_name   = isset( $row[4] ) ? trim( $row[4] ) : '';
			$canonical_school = $this->resolve_school_name( $school_name );
			$key              = $canonical_school . '::' . $classroom_name;

			if ( ! isset( $classrooms[ $key ] ) ) {
				continue;
			}

			$eid          = $enrollments[ $idx ]['enrollment_id'];
			$classroom_id = $classrooms[ $key ]['classroom_id'];

			// Check if assignment already exists.
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT assignment_id FROM {$prefix}hl_teaching_assignment WHERE enrollment_id = %d AND classroom_id = %d LIMIT 1",
				$eid, $classroom_id
			) );

			if ( $exists ) {
				$found++;
				continue;
			}

			if ( ! $this->dry_run ) {
				$svc = new HL_Classroom_Service();
				$result = $svc->create_teaching_assignment( array(
					'enrollment_id'   => $eid,
					'classroom_id'    => $classroom_id,
					'is_lead_teacher' => 1,
				) );
				if ( ! is_wp_error( $result ) ) {
					$created++;
				}
			} else {
				$created++;
			}
		}

		$this->counters['Teaching Assignments'] = array( 'found' => $found, 'created' => $created );
		WP_CLI::log( "  [10] Teaching Assignments: {$found} found, {$created} " . ( $this->dry_run ? 'would create' : 'created' ) );
	}

	// ------------------------------------------------------------------
	// Step 11: Children
	// ------------------------------------------------------------------

	private function provision_children( $child_roster_data, $classrooms, $school_map ) {
		global $wpdb;
		$prefix    = $wpdb->prefix;
		$found     = 0;
		$created   = 0;
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

			$name_parts = explode( ' ', $child_name, 2 );
			$first_name = $name_parts[0];
			$last_name  = isset( $name_parts[1] ) ? $name_parts[1] : '';

			$school_id = $this->match_school_name( $school_name, $school_map );
			if ( ! $school_id ) {
				$unmatched++;
				continue;
			}

			$age_band = $this->normalize_age_band( $age_group_raw );

			// Find existing child by first_name + last_name + dob + school_id.
			$existing_child = $wpdb->get_var( $wpdb->prepare(
				"SELECT child_id FROM {$prefix}hl_child WHERE first_name = %s AND last_name = %s AND dob = %s AND school_id = %d LIMIT 1",
				$first_name, $last_name, $dob, $school_id
			) );

			if ( $existing_child ) {
				$found++;
				continue;
			}

			if ( $this->dry_run ) {
				$created++;
				continue;
			}

			$metadata = wp_json_encode( array(
				'gender'          => $gender,
				'ethnicity'       => $ethnicity,
				'native_language' => $language,
				'age_band'        => $age_band,
			) );

			$repo     = new HL_Child_Repository();
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
					$svc = new HL_Classroom_Service();
					$svc->assign_child_to_classroom( $child_id, $classrooms[ $cr_key ]['classroom_id'], 'Lutheran provision initial assignment' );
				}
				$created++;
			}
		}

		$this->counters['Children'] = array( 'found' => $found, 'created' => $created );
		$msg = "  [11] Children: {$found} found, {$created} " . ( $this->dry_run ? 'would create' : 'created' );
		if ( $unmatched > 0 ) {
			$msg .= " ({$unmatched} unmatched schools)";
		}
		WP_CLI::log( $msg );
	}

	// ------------------------------------------------------------------
	// Step 13: Pathway + Components
	// ------------------------------------------------------------------

	private function provision_pathway( $partnership_id ) {
		global $wpdb;
		$prefix = $wpdb->prefix;

		if ( ! $partnership_id ) {
			WP_CLI::log( '  [13] Pathway + Components: SKIP (no partnership in dry run)' );
			return array(
				'pathway_id'  => null,
				'tsa_pre_id'  => null,
				'ca_pre_id'   => null,
				'tsa_post_id' => null,
				'ca_post_id'  => null,
			);
		}

		// Find or create pathway.
		$pathway_id = $this->find_or_create(
			'Pathway',
			function () use ( $wpdb, $prefix ) {
				return $wpdb->get_var( $wpdb->prepare(
					"SELECT pathway_id FROM {$prefix}hl_pathway WHERE pathway_code = %s LIMIT 1",
					'LUTHERAN_CTRL_ASSESSMENTS'
				) );
			},
			function () use ( $partnership_id ) {
				$svc = new HL_Pathway_Service();
				return $svc->create_pathway( array(
					'pathway_name'  => 'Control Group Assessments',
					'pathway_code'  => 'LUTHERAN_CTRL_ASSESSMENTS',
					'partnership_id'      => $partnership_id,
					'target_roles'  => array( 'teacher' ),
					'active_status' => 1,
				) );
			}
		);

		if ( ! $pathway_id ) {
			WP_CLI::log( '  [13] Pathway: WOULD CREATE (dry run)' );
			return array(
				'pathway_id'  => null,
				'tsa_pre_id'  => null,
				'ca_pre_id'   => null,
				'tsa_post_id' => null,
				'ca_post_id'  => null,
			);
		}

		// Find or create each component.
		$components = array(
			'tsa_pre_id'  => array( 'title' => 'Teacher Self-Assessment (Pre)',  'type' => 'teacher_self_assessment', 'order' => 1, 'phase' => 'pre' ),
			'ca_pre_id'   => array( 'title' => 'Child Assessment (Pre)',         'type' => 'child_assessment',        'order' => 2, 'phase' => 'pre' ),
			'tsa_post_id' => array( 'title' => 'Teacher Self-Assessment (Post)', 'type' => 'teacher_self_assessment', 'order' => 3, 'phase' => 'post' ),
			'ca_post_id'  => array( 'title' => 'Child Assessment (Post)',        'type' => 'child_assessment',        'order' => 4, 'phase' => 'post' ),
		);

		$result = array( 'pathway_id' => $pathway_id );

		foreach ( $components as $key => $act ) {
			$result[ $key ] = $this->find_or_create(
				'Components',
				function () use ( $wpdb, $prefix, $pathway_id, $act ) {
					return $wpdb->get_var( $wpdb->prepare(
						"SELECT component_id FROM {$prefix}hl_component WHERE pathway_id = %d AND title = %s LIMIT 1",
						$pathway_id, $act['title']
					) );
				},
				function () use ( $partnership_id, $pathway_id, $act ) {
					$svc = new HL_Pathway_Service();
					return $svc->create_component( array(
						'title'         => $act['title'],
						'pathway_id'    => $pathway_id,
						'partnership_id'      => $partnership_id,
						'component_type' => $act['type'],
						'weight'        => 1.0,
						'ordering_hint' => $act['order'],
						'external_ref'  => wp_json_encode( array( 'phase' => $act['phase'] ) ),
					) );
				}
			);
		}

		$c = $this->counters['Components'] ?? array( 'found' => 0, 'created' => 0 );
		$pw_status = $this->counters['Pathway']['found'] > 0 ? 'FOUND' : 'CREATED';
		WP_CLI::log( "  [13] Pathway: {$pw_status} (id={$pathway_id}), Components: {$c['found']} found, {$c['created']} " . ( $this->dry_run ? 'would create' : 'created' ) );
		return $result;
	}

	// ------------------------------------------------------------------
	// Step 14: Drip Rules
	// ------------------------------------------------------------------

	private function provision_drip_rules( $pathway_data ) {
		global $wpdb;
		$prefix = $wpdb->prefix;

		$post_components = array(
			'tsa_post_id' => $pathway_data['tsa_post_id'],
			'ca_post_id'  => $pathway_data['ca_post_id'],
		);

		$found   = 0;
		$created = 0;

		foreach ( $post_components as $aid ) {
			if ( ! $aid ) {
				continue;
			}

			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT rule_id FROM {$prefix}hl_component_drip_rule WHERE component_id = %d LIMIT 1",
				$aid
			) );

			if ( $exists ) {
				$found++;
				continue;
			}

			if ( ! $this->dry_run ) {
				$wpdb->insert( $prefix . 'hl_component_drip_rule', array(
					'component_id'     => $aid,
					'drip_type'       => 'fixed_date',
					'release_at_date' => '2026-05-05 00:00:00',
				) );
			}
			$created++;
		}

		$this->counters['Drip Rules'] = array( 'found' => $found, 'created' => $created );
		WP_CLI::log( "  [14] Drip Rules: {$found} found, {$created} " . ( $this->dry_run ? 'would create' : 'created' ) );
	}

	// ------------------------------------------------------------------
	// Step 15: Instruments
	// ------------------------------------------------------------------

	private function provision_teacher_instruments() {
		global $wpdb;
		$prefix       = $wpdb->prefix;
		$scale_labels = wp_json_encode( HL_CLI_Seed_Demo::get_b2e_instrument_scale_labels() );
		$ids          = array();

		// PRE instrument.
		$ids['pre'] = $this->find_or_create(
			'Teacher Instruments',
			function () use ( $wpdb, $prefix ) {
				return $wpdb->get_var( $wpdb->prepare(
					"SELECT instrument_id FROM {$prefix}hl_teacher_assessment_instrument WHERE instrument_key = %s LIMIT 1",
					'b2e_self_assessment_pre'
				) );
			},
			function () use ( $wpdb, $prefix, $scale_labels ) {
				$wpdb->insert( $prefix . 'hl_teacher_assessment_instrument', array(
					'instrument_name'    => 'Teacher Self-Assessment',
					'instrument_key'     => 'b2e_self_assessment_pre',
					'instrument_version' => '1.0',
					'sections'           => wp_json_encode( HL_CLI_Seed_Demo::get_b2e_instrument_sections_pre() ),
					'scale_labels'       => $scale_labels,
					'instructions'       => HL_CLI_Seed_Demo::get_b2e_instrument_instructions_pre(),
					'styles_json'        => HL_CLI_Seed_Demo::get_b2e_instrument_styles_json_pre(),
					'status'             => 'active',
					'created_at'         => current_time( 'mysql' ),
				) );
				return $wpdb->insert_id;
			}
		);

		// POST instrument.
		$ids['post'] = $this->find_or_create(
			'Teacher Instruments',
			function () use ( $wpdb, $prefix ) {
				return $wpdb->get_var( $wpdb->prepare(
					"SELECT instrument_id FROM {$prefix}hl_teacher_assessment_instrument WHERE instrument_key = %s LIMIT 1",
					'b2e_self_assessment_post'
				) );
			},
			function () use ( $wpdb, $prefix, $scale_labels ) {
				$wpdb->insert( $prefix . 'hl_teacher_assessment_instrument', array(
					'instrument_name'    => 'Teacher Self-Assessment',
					'instrument_key'     => 'b2e_self_assessment_post',
					'instrument_version' => '1.0',
					'sections'           => wp_json_encode( HL_CLI_Seed_Demo::get_b2e_instrument_sections_post() ),
					'scale_labels'       => $scale_labels,
					'instructions'       => HL_CLI_Seed_Demo::get_b2e_instrument_instructions_post(),
					'styles_json'        => HL_CLI_Seed_Demo::get_b2e_instrument_styles_json_post(),
					'status'             => 'active',
					'created_at'         => current_time( 'mysql' ),
				) );
				return $wpdb->insert_id;
			}
		);

		$c = $this->counters['Teacher Instruments'];
		WP_CLI::log( "  [15a] Teacher Instruments: {$c['found']} found, {$c['created']} " . ( $this->dry_run ? 'would create' : 'created' ) );
		return $ids;
	}

	private function provision_child_instruments() {
		global $wpdb;
		$prefix = $wpdb->prefix;

		$types = array(
			'infant'    => array( 'name' => 'Lutheran Infant Assessment',    'type' => 'children_infant' ),
			'toddler'   => array( 'name' => 'Lutheran Toddler Assessment',   'type' => 'children_toddler' ),
			'preschool' => array( 'name' => 'Lutheran Preschool Assessment', 'type' => 'children_preschool' ),
			'k2'        => array( 'name' => 'Lutheran K-2 Assessment',       'type' => 'children_k2' ),
		);

		$b2e_data = HL_CLI_Seed_Demo::get_child_assessment_questions();
		$scale    = HL_CLI_Seed_Demo::get_child_assessment_scale();
		$allowed  = array_map( 'strval', array_keys( $scale ) );

		$instruments = array();
		foreach ( $types as $band => $info ) {
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

			$instruments[ $band ] = $this->find_or_create(
				'Child Instruments',
				function () use ( $wpdb, $prefix, $info ) {
					return $wpdb->get_var( $wpdb->prepare(
						"SELECT instrument_id FROM {$prefix}hl_instrument WHERE name = %s LIMIT 1",
						$info['name']
					) );
				},
				function () use ( $wpdb, $prefix, $info, $questions, $band ) {
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
					return $wpdb->insert_id;
				}
			);
		}

		$c = $this->counters['Child Instruments'] ?? array( 'found' => 0, 'created' => 0 );
		WP_CLI::log( "  [15b] Child Instruments: {$c['found']} found, {$c['created']} " . ( $this->dry_run ? 'would create' : 'created' ) );
		return $instruments;
	}

	/**
	 * Update TSA component external_ref with instrument_ids.
	 */
	private function update_component_instrument_refs( $pathway_data, $instrument_ids ) {
		if ( $this->dry_run || ! $pathway_data['tsa_pre_id'] || ! isset( $instrument_ids['pre'] ) || ! $instrument_ids['pre'] ) {
			return;
		}

		$svc = new HL_Pathway_Service();

		$svc->update_component( $pathway_data['tsa_pre_id'], array(
			'external_ref' => wp_json_encode( array(
				'phase'                 => 'pre',
				'teacher_instrument_id' => $instrument_ids['pre'],
			) ),
		) );

		if ( $pathway_data['tsa_post_id'] && isset( $instrument_ids['post'] ) && $instrument_ids['post'] ) {
			$svc->update_component( $pathway_data['tsa_post_id'], array(
				'external_ref' => wp_json_encode( array(
					'phase'                 => 'post',
					'teacher_instrument_id' => $instrument_ids['post'],
				) ),
			) );
		}
	}

	// ------------------------------------------------------------------
	// Step 16: Assessment Instances
	// ------------------------------------------------------------------

	private function provision_assessment_instances( $enrollments, $partnership_id, $pathway_data, $instrument_ids, $classrooms, $school_map, $teacher_roster_data, $child_instruments ) {
		global $wpdb;
		$prefix = $wpdb->prefix;

		if ( ! $partnership_id || ! $pathway_data['tsa_pre_id'] ) {
			WP_CLI::log( '  [16] Assessment Instances: SKIP (dependencies not available)' );
			return;
		}

		$now       = current_time( 'mysql' );
		$tsa_found = 0;
		$tsa_new   = 0;
		$ca_found  = 0;
		$ca_new    = 0;

		foreach ( $enrollments as $idx => $enrollment ) {
			$eid = $enrollment['enrollment_id'];

			// Teacher Assessment Instances: PRE + POST.
			foreach ( array( 'pre' => $pathway_data['tsa_pre_id'], 'post' => $pathway_data['tsa_post_id'] ) as $phase => $component_id ) {
				if ( ! $component_id ) {
					continue;
				}

				$exists = $wpdb->get_var( $wpdb->prepare(
					"SELECT instance_id FROM {$prefix}hl_teacher_assessment_instance WHERE enrollment_id = %d AND component_id = %d AND phase = %s LIMIT 1",
					$eid, $component_id, $phase
				) );

				if ( $exists ) {
					$tsa_found++;
					continue;
				}

				if ( ! $this->dry_run ) {
					$inst_id = isset( $instrument_ids[ $phase ] ) ? $instrument_ids[ $phase ] : null;
					$wpdb->insert( $prefix . 'hl_teacher_assessment_instance', array(
						'instance_uuid'      => HL_DB_Utils::generate_uuid(),
						'partnership_id'           => $partnership_id,
						'enrollment_id'      => $eid,
						'component_id'        => $component_id,
						'phase'              => $phase,
						'instrument_id'      => $inst_id,
						'instrument_version' => '1.0',
						'status'             => 'not_started',
						'created_at'         => $now,
					) );
				}
				$tsa_new++;
			}

			// Child Assessment Instances: PRE + POST.
			$school_name    = $enrollment['school_name'];
			$classroom_name = $enrollment['classroom_name'];
			$cr_key         = $school_name . '::' . $classroom_name;
			$school_id      = $this->match_school_name( $school_name, $school_map );

			if ( isset( $classrooms[ $cr_key ] ) ) {
				$classroom_id = $classrooms[ $cr_key ]['classroom_id'];
				$age_band     = $classrooms[ $cr_key ]['age_band'];

				$ci_id = isset( $child_instruments[ $age_band ] ) ? $child_instruments[ $age_band ] : null;
				if ( ! $ci_id ) {
					$ci_id = isset( $child_instruments['preschool'] ) ? $child_instruments['preschool'] : ( ! empty( $child_instruments ) ? reset( $child_instruments ) : null );
				}

				foreach ( array( 'pre' => $pathway_data['ca_pre_id'], 'post' => $pathway_data['ca_post_id'] ) as $phase => $component_id ) {
					if ( ! $component_id ) {
						continue;
					}

					$exists = $wpdb->get_var( $wpdb->prepare(
						"SELECT instance_id FROM {$prefix}hl_child_assessment_instance WHERE enrollment_id = %d AND component_id = %d AND phase = %s LIMIT 1",
						$eid, $component_id, $phase
					) );

					if ( $exists ) {
						$ca_found++;
						continue;
					}

					if ( ! $this->dry_run ) {
						$wpdb->insert( $prefix . 'hl_child_assessment_instance', array(
							'instance_uuid'       => HL_DB_Utils::generate_uuid(),
							'partnership_id'            => $partnership_id,
							'enrollment_id'       => $eid,
							'component_id'         => $component_id,
							'classroom_id'        => $classroom_id,
							'school_id'           => $school_id,
							'phase'               => $phase,
							'instrument_age_band' => $age_band,
							'instrument_id'       => $ci_id,
							'instrument_version'  => $ci_id ? '1.0' : null,
							'status'              => 'not_started',
							'created_at'          => $now,
						) );
					}
					$ca_new++;
				}
			}
		}

		$this->counters['TSA Instances'] = array( 'found' => $tsa_found, 'created' => $tsa_new );
		$this->counters['CA Instances']  = array( 'found' => $ca_found,  'created' => $ca_new );
		$verb = $this->dry_run ? 'would create' : 'created';
		WP_CLI::log( "  [16] Assessment Instances: TSA {$tsa_found} found/{$tsa_new} {$verb}, CA {$ca_found} found/{$ca_new} {$verb}" );
	}

	// ------------------------------------------------------------------
	// Step 17: Component States
	// ------------------------------------------------------------------

	private function provision_component_states( $enrollments, $pathway_data ) {
		global $wpdb;
		$prefix = $wpdb->prefix;

		if ( ! $pathway_data['tsa_pre_id'] ) {
			WP_CLI::log( '  [17] Component States: SKIP (no components)' );
			return;
		}

		$now     = current_time( 'mysql' );
		$found   = 0;
		$created = 0;

		$component_statuses = array(
			'tsa_pre_id'  => 'not_started',
			'ca_pre_id'   => 'not_started',
			'tsa_post_id' => 'locked',
			'ca_post_id'  => 'locked',
		);

		foreach ( $enrollments as $enrollment ) {
			$eid = $enrollment['enrollment_id'];

			foreach ( $component_statuses as $key => $status ) {
				$aid = $pathway_data[ $key ];
				if ( ! $aid ) {
					continue;
				}

				$exists = $wpdb->get_var( $wpdb->prepare(
					"SELECT state_id FROM {$prefix}hl_component_state WHERE enrollment_id = %d AND component_id = %d LIMIT 1",
					$eid, $aid
				) );

				if ( $exists ) {
					$found++;
					continue;
				}

				if ( ! $this->dry_run ) {
					$wpdb->insert( $prefix . 'hl_component_state', array(
						'enrollment_id'      => $eid,
						'component_id'        => $aid,
						'completion_percent' => 0,
						'completion_status'  => $status,
						'last_computed_at'   => $now,
					) );
				}
				$created++;
			}
		}

		$this->counters['Component States'] = array( 'found' => $found, 'created' => $created );
		WP_CLI::log( "  [17] Component States: {$found} found, {$created} " . ( $this->dry_run ? 'would create' : 'created' ) );
	}

	// ------------------------------------------------------------------
	// Step 18: Pathway Assignments
	// ------------------------------------------------------------------

	private function provision_pathway_assignments( $enrollments, $pathway_data ) {
		global $wpdb;
		$prefix = $wpdb->prefix;

		$pathway_id = $pathway_data['pathway_id'];
		if ( ! $pathway_id ) {
			WP_CLI::log( '  [18] Pathway Assignments: SKIP (no pathway)' );
			return;
		}

		$found   = 0;
		$created = 0;

		foreach ( $enrollments as $enrollment ) {
			$eid = $enrollment['enrollment_id'];

			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT assignment_id FROM {$prefix}hl_pathway_assignment WHERE enrollment_id = %d AND pathway_id = %d LIMIT 1",
				$eid, $pathway_id
			) );

			if ( $exists ) {
				$found++;
				continue;
			}

			if ( ! $this->dry_run ) {
				$wpdb->insert( $prefix . 'hl_pathway_assignment', array(
					'enrollment_id'       => $eid,
					'pathway_id'          => $pathway_id,
					'assigned_by_user_id' => 0,
					'assignment_type'     => 'role_default',
				) );

				// Also update assigned_pathway_id on enrollment.
				$enrollment_repo = new HL_Enrollment_Repository();
				$enrollment_repo->update( $eid, array(
					'assigned_pathway_id' => $pathway_id,
				) );
			}
			$created++;
		}

		$this->counters['Pathway Assignments'] = array( 'found' => $found, 'created' => $created );
		WP_CLI::log( "  [18] Pathway Assignments: {$found} found, {$created} " . ( $this->dry_run ? 'would create' : 'created' ) );
	}

	// ------------------------------------------------------------------
	// Summary output
	// ------------------------------------------------------------------

	private function print_summary() {
		WP_CLI::line( '' );
		WP_CLI::line( '=== Summary ===' );
		WP_CLI::line( sprintf( '%-24s %7s %7s', 'Entity', 'Found', $this->dry_run ? 'Would' : 'Created' ) );
		WP_CLI::line( str_repeat( '-', 42 ) );

		foreach ( $this->counters as $entity => $counts ) {
			if ( $entity === 'Users' ) {
				// Special display: found/total for users.
				$total = $counts['found'] + $counts['created'];
				WP_CLI::line( sprintf( '%-24s %4d/%-3d %7s', $entity, $counts['found'], $total, '-' ) );
			} else {
				WP_CLI::line( sprintf( '%-24s %7d %7d', $entity, $counts['found'], $counts['created'] ) );
			}
		}

		if ( ! empty( $this->missing_users ) ) {
			WP_CLI::line( '' );
			WP_CLI::warning( 'Users NOT FOUND (' . count( $this->missing_users ) . '):' );
			foreach ( $this->missing_users as $u ) {
				WP_CLI::line( "  - {$u['name']} ({$u['email']})" );
			}
		}

		WP_CLI::line( '' );
		if ( $this->dry_run ) {
			WP_CLI::success( 'Dry run complete. No data was written.' );
		} else {
			WP_CLI::success( 'Lutheran provisioning complete!' );
		}
	}

	// ------------------------------------------------------------------
	// Helper methods (copied from seeder — small, stable utilities)
	// ------------------------------------------------------------------

	/**
	 * Generate a code from a name (uppercase, underscored).
	 */
	private function slugify_code( $name ) {
		$code = strtoupper( sanitize_title( $name ) );
		$code = str_replace( '-', '_', $code );
		return $code;
	}

	/**
	 * Normalize a messy age group string to a canonical age_band value.
	 */
	private function normalize_age_band( $raw ) {
		$lower = strtolower( trim( $raw ) );

		if ( in_array( $lower, array( 'infants', '0-12 months', '0 -12 months', '0-12months' ), true ) ) {
			return 'infant';
		}
		if ( in_array( $lower, array( 'toddlers', '1 year olds', '1-2 year olds', '1-2  year olds', 'infants/tooddlers', 'infants/toddlers', '2 year olds', '2 year old', '2-3 year olds', '1yr-2 1/2' ), true ) ) {
			return 'toddler';
		}
		if ( in_array( $lower, array( '3 year olds', '3year old', '3year olds', 'preschool', 'vpk', '3 and 4 year olds', 'vpk/3 year olds' ), true ) ) {
			return 'preschool';
		}
		if ( in_array( $lower, array( 'k-2', 'k2', 'k-2nd', 'k-2nd grade', 'kindergarten', 'kindergarten-2nd', 'k-2nd graders', '5 year olds', '5-7 year olds' ), true ) ) {
			return 'k2';
		}
		if ( in_array( $lower, array( 'mixed', '0 to 3', '0-3', 'birth-3', 'birth to 3', '0 to 4' ), true ) ) {
			return 'mixed';
		}

		return 'mixed';
	}

	/**
	 * Resolve a roster school name to the canonical school info name.
	 */
	private function resolve_school_name( $roster_name ) {
		if ( isset( self::$school_aliases[ $roster_name ] ) ) {
			return self::$school_aliases[ $roster_name ];
		}
		return $roster_name;
	}

	/**
	 * Match a roster school name to a school_map key (direct → alias → fuzzy).
	 */
	private function match_school_name( $roster_name, $school_map ) {
		if ( isset( $school_map[ $roster_name ] ) ) {
			return $school_map[ $roster_name ];
		}

		if ( isset( self::$school_aliases[ $roster_name ] ) ) {
			$canonical = self::$school_aliases[ $roster_name ];
			if ( isset( $school_map[ $canonical ] ) ) {
				return $school_map[ $canonical ];
			}
		}

		foreach ( $school_map as $full_name => $id ) {
			if ( stripos( $full_name, $roster_name ) !== false || stripos( $roster_name, $full_name ) !== false ) {
				return $id;
			}
		}

		return null;
	}
}
