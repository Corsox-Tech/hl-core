<?php
/**
 * WP-CLI command: wp hl-core setup-ea
 *
 * Creates the ECSELent Adventures partnership, cycle, pathways, components,
 * enrollments (from LD group 35859), pathway assignments (from LD materials
 * groups), and imports LearnDash course completion data.
 *
 * @package HL_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HL_CLI_Setup_EA {

	/** Partnership code. */
	const PARTNERSHIP_CODE = 'EA-2025';

	/** Cycle code. */
	const CYCLE_CODE = 'EA-TRAINING-2025';

	// ------------------------------------------------------------------
	// LearnDash IDs
	// ------------------------------------------------------------------

	/** Training group (enrollment source). */
	const LD_GROUP_TRAINING = 35859;

	/** Preschool/Pre-K materials group. */
	const LD_GROUP_PREK = 37870;

	/** K-2 materials group. */
	const LD_GROUP_K2 = 37872;

	/** Syllabus course for Preschool/Pre-K pathway. */
	const LD_COURSE_SYLLABUS_PREK = 36066;

	/** Syllabus course for K-2 pathway. */
	const LD_COURSE_SYLLABUS_K2 = 30756;

	/** Component course IDs. */
	const COURSE_INTRO         = 35858;
	const COURSE_IMPLEMENTING  = 35867;
	const COURSE_BEYOND        = 35875;

	/** User IDs to skip (admin/test accounts). */
	const SKIP_USER_IDS = array( 1, 10 );

	/** @var bool */
	private $dry_run = false;

	/**
	 * Register the WP-CLI command.
	 */
	public static function register() {
		WP_CLI::add_command( 'hl-core setup-ea', array( new self(), 'run' ) );
	}

	/**
	 * Create ECSELent Adventures partnership, cycle, pathways, enrollments, and import completions.
	 *
	 * ## OPTIONS
	 *
	 * [--clean]
	 * : Remove all EA data before creating.
	 *
	 * [--dry-run]
	 * : Log everything that would be done but do not write to the database.
	 *
	 * ## EXAMPLES
	 *
	 *     wp hl-core setup-ea
	 *     wp hl-core setup-ea --dry-run
	 *     wp hl-core setup-ea --clean
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Named args.
	 */
	public function run( $args, $assoc_args ) {
		$clean         = isset( $assoc_args['clean'] );
		$this->dry_run = isset( $assoc_args['dry-run'] );

		if ( $clean ) {
			$this->clean();
			WP_CLI::success( 'ECSELent Adventures data cleaned.' );
			return;
		}

		if ( $this->cycle_exists() ) {
			WP_CLI::warning( 'EA cycle already exists (code: ' . self::CYCLE_CODE . '). Run with --clean first to re-create.' );
			return;
		}

		if ( $this->dry_run ) {
			WP_CLI::line( '' );
			WP_CLI::line( '*** DRY RUN — no data will be written ***' );
		}

		WP_CLI::line( '' );
		WP_CLI::line( '=== HL Core ECSELent Adventures Setup ===' );
		WP_CLI::line( '' );

		// Step 1: Create partnership.
		$partnership_id = $this->create_partnership();

		// Step 2: Create cycle.
		$cycle_id = $this->create_cycle( $partnership_id );

		// Step 3: Create pathways + components.
		$pathways = $this->create_pathways( $cycle_id );

		// Step 4: Discover and create enrollments.
		$enrollments = $this->create_enrollments( $cycle_id );

		// Step 5: Assign pathways based on materials groups.
		$this->assign_pathways( $enrollments, $pathways );

		// Step 6: Import LD completion data.
		$this->import_completions( $enrollments, $pathways );

		WP_CLI::line( '' );
		WP_CLI::success( 'ECSELent Adventures setup complete!' . ( $this->dry_run ? ' (dry run)' : '' ) );
		WP_CLI::line( '' );
		WP_CLI::line( 'Summary:' );
		WP_CLI::line( "  Partnership: {$partnership_id} (code: " . self::PARTNERSHIP_CODE . ')' );
		WP_CLI::line( "  Cycle:       {$cycle_id} (code: " . self::CYCLE_CODE . ')' );
		WP_CLI::line( "  Pathways:    " . count( $pathways ) );
		foreach ( $pathways as $name => $info ) {
			WP_CLI::line( "    {$name}: pathway_id={$info['pathway_id']}, {$info['component_count']} components" );
		}
		WP_CLI::line( "  Enrollments: " . count( $enrollments ) );
		WP_CLI::line( '' );
	}

	// ------------------------------------------------------------------
	// Idempotency
	// ------------------------------------------------------------------

	private function cycle_exists() {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT cycle_id FROM {$wpdb->prefix}hl_cycle WHERE cycle_code = %s LIMIT 1",
				self::CYCLE_CODE
			)
		);
	}

	// ------------------------------------------------------------------
	// Clean
	// ------------------------------------------------------------------

	private function clean() {
		global $wpdb;
		$t = $wpdb->prefix;

		$cycle_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT cycle_id FROM {$t}hl_cycle WHERE cycle_code = %s LIMIT 1",
				self::CYCLE_CODE
			)
		);

		$partnership_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT partnership_id FROM {$t}hl_partnership WHERE partnership_code = %s LIMIT 1",
				self::PARTNERSHIP_CODE
			)
		);

		if ( ! $cycle_id && ! $partnership_id ) {
			WP_CLI::log( 'No EA data found — nothing to clean.' );
			return;
		}

		if ( $cycle_id ) {
			// Get enrollment IDs for this cycle.
			$enrollment_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT enrollment_id FROM {$t}hl_enrollment WHERE cycle_id = %d",
					$cycle_id
				)
			);

			if ( ! empty( $enrollment_ids ) ) {
				$id_placeholders = implode( ',', array_fill( 0, count( $enrollment_ids ), '%d' ) );

				// Remove component_states for these enrollments.
				$wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$t}hl_component_state WHERE enrollment_id IN ({$id_placeholders})",
						$enrollment_ids
					)
				);
				WP_CLI::log( '  Cleaned component_states.' );

				// Remove pathway_assignments for these enrollments.
				$wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$t}hl_pathway_assignment WHERE enrollment_id IN ({$id_placeholders})",
						$enrollment_ids
					)
				);
				WP_CLI::log( '  Cleaned pathway_assignments.' );
			}

			// Remove enrollments.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$t}hl_enrollment WHERE cycle_id = %d", $cycle_id ) );
			WP_CLI::log( '  Cleaned enrollments.' );

			// Remove components.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$t}hl_component WHERE cycle_id = %d", $cycle_id ) );
			WP_CLI::log( '  Cleaned components.' );

			// Remove pathways.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$t}hl_pathway WHERE cycle_id = %d", $cycle_id ) );
			WP_CLI::log( '  Cleaned pathways.' );

			// Remove cycle_school links.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$t}hl_cycle_school WHERE cycle_id = %d", $cycle_id ) );
			WP_CLI::log( '  Cleaned cycle_school links.' );

			// Remove cycle.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$t}hl_cycle WHERE cycle_id = %d", $cycle_id ) );
			WP_CLI::log( "  Cleaned cycle (id={$cycle_id})." );
		}

		if ( $partnership_id ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$t}hl_partnership WHERE partnership_id = %d", $partnership_id ) );
			WP_CLI::log( "  Cleaned partnership (id={$partnership_id})." );
		}
	}

	// ------------------------------------------------------------------
	// Step 1: Create Partnership
	// ------------------------------------------------------------------

	private function create_partnership() {
		global $wpdb;

		// Check if partnership already exists (might remain after partial clean).
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

		$partnership_id = 0;
		if ( ! $this->dry_run ) {
			$repo           = new HL_Partnership_Repository();
			$partnership_id = $repo->create( array(
				'partnership_name' => 'ECSELent Adventures',
				'partnership_code' => self::PARTNERSHIP_CODE,
				'status'           => 'active',
			) );
		}

		WP_CLI::log( "  [1] Partnership " . ( $this->dry_run ? 'would be created' : "created: id={$partnership_id}" ) . " (code: " . self::PARTNERSHIP_CODE . ')' );
		return $partnership_id;
	}

	// ------------------------------------------------------------------
	// Step 2: Create Cycle
	// ------------------------------------------------------------------

	private function create_cycle( $partnership_id ) {
		$cycle_id = 0;
		if ( ! $this->dry_run ) {
			$repo     = new HL_Cycle_Repository();
			$cycle_id = $repo->create( array(
				'cycle_name'     => 'ECSELent Adventures Training',
				'cycle_code'     => self::CYCLE_CODE,
				'partnership_id' => $partnership_id,
				'cycle_type'     => 'program',
				'status'         => 'active',
			) );
		}

		WP_CLI::log( "  [2] Cycle " . ( $this->dry_run ? 'would be created' : "created: id={$cycle_id}" ) . " (code: " . self::CYCLE_CODE . ')' );
		return $cycle_id;
	}

	// ------------------------------------------------------------------
	// Step 3: Create Pathways + Components
	// ------------------------------------------------------------------

	private function create_pathways( $cycle_id ) {
		$svc      = new HL_Pathway_Service();
		$pathways = array();

		$pathways['Preschool/Pre-K'] = $this->create_prek_pathway( $svc, $cycle_id );
		$pathways['K-2']             = $this->create_k2_pathway( $svc, $cycle_id );

		WP_CLI::log( '  [3] All 2 pathways created.' . ( $this->dry_run ? ' (dry run)' : '' ) );
		return $pathways;
	}

	private function create_prek_pathway( $svc, $cycle_id ) {
		$syllabus_url = '';
		if ( ! $this->dry_run ) {
			$syllabus_url = get_permalink( self::LD_COURSE_SYLLABUS_PREK ) ?: '';
		}

		$pid = 0;
		if ( ! $this->dry_run ) {
			$pid = $svc->create_pathway( array(
				'pathway_name'  => 'ECSELent Adventures - Preschool/Pre-K',
				'cycle_id'      => $cycle_id,
				'target_roles'  => array( 'teacher' ),
				'syllabus_url'  => $syllabus_url,
				'active_status' => 1,
			) );
		}

		$n          = 0;
		$components = array();

		$components['intro']         = $this->cmp( $svc, $pid, $cycle_id, 'Intro to ECSELent Adventures',         ++$n, self::COURSE_INTRO );
		$components['implementing']  = $this->cmp( $svc, $pid, $cycle_id, 'Implementing ECSELent Adventures',      ++$n, self::COURSE_IMPLEMENTING );
		$components['beyond']        = $this->cmp( $svc, $pid, $cycle_id, 'ECSELent Adventures and Beyond!',       ++$n, self::COURSE_BEYOND );

		WP_CLI::log( "    Preschool/Pre-K: pathway_id={$pid}, {$n} components" . ( $this->dry_run ? ' (dry run)' : '' ) );
		return array(
			'pathway_id'      => $pid,
			'component_count' => $n,
			'components'      => $components,
		);
	}

	private function create_k2_pathway( $svc, $cycle_id ) {
		$syllabus_url = '';
		if ( ! $this->dry_run ) {
			$syllabus_url = get_permalink( self::LD_COURSE_SYLLABUS_K2 ) ?: '';
		}

		$pid = 0;
		if ( ! $this->dry_run ) {
			$pid = $svc->create_pathway( array(
				'pathway_name'  => 'ECSELent Adventures - K-2',
				'cycle_id'      => $cycle_id,
				'target_roles'  => array( 'teacher' ),
				'syllabus_url'  => $syllabus_url,
				'active_status' => 1,
			) );
		}

		$n          = 0;
		$components = array();

		$components['intro']         = $this->cmp( $svc, $pid, $cycle_id, 'Intro to ECSELent Adventures',         ++$n, self::COURSE_INTRO );
		$components['implementing']  = $this->cmp( $svc, $pid, $cycle_id, 'Implementing ECSELent Adventures',      ++$n, self::COURSE_IMPLEMENTING );
		$components['beyond']        = $this->cmp( $svc, $pid, $cycle_id, 'ECSELent Adventures and Beyond!',       ++$n, self::COURSE_BEYOND );

		WP_CLI::log( "    K-2: pathway_id={$pid}, {$n} components" . ( $this->dry_run ? ' (dry run)' : '' ) );
		return array(
			'pathway_id'      => $pid,
			'component_count' => $n,
			'components'      => $components,
		);
	}

	/**
	 * Helper: create a component (shorthand).
	 *
	 * @param HL_Pathway_Service $svc
	 * @param int                $pathway_id
	 * @param int                $cycle_id
	 * @param string             $title
	 * @param int                $order
	 * @param int                $course_id LD course ID.
	 * @return int Component ID (0 in dry-run).
	 */
	private function cmp( $svc, $pathway_id, $cycle_id, $title, $order, $course_id ) {
		if ( $this->dry_run ) {
			return 0;
		}

		return $svc->create_component( array(
			'title'          => $title,
			'pathway_id'     => $pathway_id,
			'cycle_id'       => $cycle_id,
			'component_type' => 'learndash_course',
			'weight'         => 1.0,
			'ordering_hint'  => $order,
			'external_ref'   => wp_json_encode( array( 'course_id' => $course_id ) ),
		) );
	}

	// ------------------------------------------------------------------
	// Step 4: Enrollment Discovery
	// ------------------------------------------------------------------

	private function create_enrollments( $cycle_id ) {
		if ( ! function_exists( 'learndash_get_groups_user_ids' ) ) {
			WP_CLI::error( 'LearnDash is not active — cannot discover group members.' );
			return array();
		}

		$user_ids = learndash_get_groups_user_ids( self::LD_GROUP_TRAINING );
		$user_ids = array_map( 'intval', $user_ids );

		// Remove skipped IDs.
		$user_ids = array_diff( $user_ids, self::SKIP_USER_IDS );
		$user_ids = array_values( $user_ids );

		WP_CLI::log( "  [4] Found " . count( $user_ids ) . " users in LD group " . self::LD_GROUP_TRAINING . " (after skipping admin/test)." );

		$enrollment_repo = new HL_Enrollment_Repository();
		$enrollments     = array();
		$created         = 0;

		foreach ( $user_ids as $uid ) {
			if ( ! $this->dry_run ) {
				$eid = $enrollment_repo->create( array(
					'cycle_id' => $cycle_id,
					'user_id'  => $uid,
					'roles'    => array( 'teacher' ),
					'status'   => 'active',
				) );
			} else {
				$eid = 0;
			}

			$enrollments[] = array(
				'enrollment_id' => $eid,
				'user_id'       => $uid,
			);
			$created++;
		}

		WP_CLI::log( "  [4] Enrollments: {$created} " . ( $this->dry_run ? 'would be created' : 'created' ) . '.' );
		return $enrollments;
	}

	// ------------------------------------------------------------------
	// Step 5: Pathway Assignment
	// ------------------------------------------------------------------

	private function assign_pathways( $enrollments, $pathways ) {
		if ( ! function_exists( 'learndash_get_groups_user_ids' ) ) {
			WP_CLI::warning( 'LearnDash not active — skipping pathway assignment.' );
			return;
		}

		$prek_user_ids = array_map( 'intval', learndash_get_groups_user_ids( self::LD_GROUP_PREK ) );
		$k2_user_ids   = array_map( 'intval', learndash_get_groups_user_ids( self::LD_GROUP_K2 ) );

		$assign_svc    = new HL_Pathway_Assignment_Service();
		$prek_pathway  = $pathways['Preschool/Pre-K']['pathway_id'];
		$k2_pathway    = $pathways['K-2']['pathway_id'];

		$assigned_prek = 0;
		$assigned_k2   = 0;
		$no_group      = 0;

		foreach ( $enrollments as $e ) {
			$uid = (int) $e['user_id'];
			$eid = (int) $e['enrollment_id'];

			$in_prek = in_array( $uid, $prek_user_ids, true );
			$in_k2   = in_array( $uid, $k2_user_ids, true );

			if ( $in_prek ) {
				if ( ! $this->dry_run ) {
					$assign_svc->assign_pathway( $eid, $prek_pathway, 'explicit' );
				}
				$assigned_prek++;
			}

			if ( $in_k2 ) {
				if ( ! $this->dry_run ) {
					$assign_svc->assign_pathway( $eid, $k2_pathway, 'explicit' );
				}
				$assigned_k2++;
			}

			if ( ! $in_prek && ! $in_k2 ) {
				$user = get_userdata( $uid );
				$name = $user ? $user->display_name : "UID {$uid}";
				WP_CLI::warning( "User {$name} (ID {$uid}) enrolled but no materials group assignment." );
				$no_group++;
			}
		}

		$verb = $this->dry_run ? 'would be assigned' : 'assigned';
		WP_CLI::log( "  [5] Pathway assignments: Preschool/Pre-K={$assigned_prek} {$verb}, K-2={$assigned_k2} {$verb}, no group={$no_group}." );
	}

	// ------------------------------------------------------------------
	// Step 6: Completion Import
	// ------------------------------------------------------------------

	private function import_completions( $enrollments, $pathways ) {
		global $wpdb;

		$course_ids = array( self::COURSE_INTRO, self::COURSE_IMPLEMENTING, self::COURSE_BEYOND );

		// Build a map of course_id → component_id for each pathway.
		$component_maps = array();
		foreach ( $pathways as $pw_name => $pw ) {
			$component_maps[ $pw_name ] = array();
			foreach ( $pw['components'] as $key => $cid ) {
				$course_map = array(
					'intro'        => self::COURSE_INTRO,
					'implementing' => self::COURSE_IMPLEMENTING,
					'beyond'       => self::COURSE_BEYOND,
				);
				if ( isset( $course_map[ $key ] ) ) {
					$component_maps[ $pw_name ][ $course_map[ $key ] ] = $cid;
				}
			}
		}

		// Build a quick user → enrollment + pathway lookup.
		$prek_user_ids = array();
		$k2_user_ids   = array();
		if ( function_exists( 'learndash_get_groups_user_ids' ) ) {
			$prek_user_ids = array_map( 'intval', learndash_get_groups_user_ids( self::LD_GROUP_PREK ) );
			$k2_user_ids   = array_map( 'intval', learndash_get_groups_user_ids( self::LD_GROUP_K2 ) );
		}

		$count_complete    = 0;
		$count_in_progress = 0;

		foreach ( $enrollments as $e ) {
			$uid = (int) $e['user_id'];
			$eid = (int) $e['enrollment_id'];

			// Determine which pathways this user is in.
			$user_pathways = array();
			if ( in_array( $uid, $prek_user_ids, true ) ) {
				$user_pathways[] = 'Preschool/Pre-K';
			}
			if ( in_array( $uid, $k2_user_ids, true ) ) {
				$user_pathways[] = 'K-2';
			}

			if ( empty( $user_pathways ) ) {
				continue; // No pathway assignment, skip completion import.
			}

			foreach ( $course_ids as $course_id ) {
				// Query LearnDash user activity.
				$activity = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT activity_status, activity_completed
						 FROM {$wpdb->prefix}learndash_user_activity
						 WHERE user_id = %d AND post_id = %d AND activity_type = 'course'
						 LIMIT 1",
						$uid,
						$course_id
					)
				);

				if ( ! $activity ) {
					continue; // Not started — skip.
				}

				// Insert a component_state for each pathway the user belongs to.
				foreach ( $user_pathways as $pw_name ) {
					if ( ! isset( $component_maps[ $pw_name ][ $course_id ] ) ) {
						continue;
					}
					$component_id = $component_maps[ $pw_name ][ $course_id ];

					if ( (int) $activity->activity_status === 1 ) {
						// Complete.
						$completed_at = $activity->activity_completed
							? gmdate( 'Y-m-d H:i:s', (int) $activity->activity_completed )
							: current_time( 'mysql', true );

						if ( ! $this->dry_run ) {
							$wpdb->insert( $wpdb->prefix . 'hl_component_state', array(
								'enrollment_id'      => $eid,
								'component_id'       => $component_id,
								'completion_percent'  => 100,
								'completion_status'   => 'complete',
								'completed_at'        => $completed_at,
								'last_computed_at'    => current_time( 'mysql', true ),
							) );
						}
						$count_complete++;
					} else {
						// In progress.
						if ( ! $this->dry_run ) {
							$wpdb->insert( $wpdb->prefix . 'hl_component_state', array(
								'enrollment_id'      => $eid,
								'component_id'       => $component_id,
								'completion_percent'  => 50,
								'completion_status'   => 'in_progress',
								'last_computed_at'    => current_time( 'mysql', true ),
							) );
						}
						$count_in_progress++;
					}
				}
			}
		}

		$verb = $this->dry_run ? 'would be imported' : 'imported';
		WP_CLI::log( "  [6] LD completion {$verb}: {$count_complete} complete, {$count_in_progress} in-progress." );
	}
}
