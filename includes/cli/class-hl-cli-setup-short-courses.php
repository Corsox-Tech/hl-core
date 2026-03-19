<?php
/**
 * WP-CLI command: wp hl-core setup-short-courses
 *
 * Creates 3 standalone short-course cycles (no Partnership), each with
 * 1 pathway, 1 LearnDash component, auto-discovered enrollments from
 * LearnDash activity, pathway assignments, and completion import.
 *
 * Short courses:
 *   SC-EEW   — Educators' Emotional Well-Being  (LD 30476)
 *   SC-RP    — Reflective Practice               (LD 30399)
 *   SC-MMST  — Making the Most of Storytime      (LD 30586)
 *
 * @package HL_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HL_CLI_Setup_Short_Courses {

	/**
	 * Admin / test user IDs to skip during enrollment discovery.
	 */
	private static $skip_user_ids = array( 1, 10 );

	/**
	 * Course definitions.
	 */
	private static $courses = array(
		array(
			'cycle_name' => "Educators' Emotional Well-Being",
			'cycle_code' => 'SC-EEW',
			'ld_course'  => 30476,
		),
		array(
			'cycle_name' => 'Reflective Practice',
			'cycle_code' => 'SC-RP',
			'ld_course'  => 30399,
		),
		array(
			'cycle_name' => 'Making the Most of Storytime',
			'cycle_code' => 'SC-MMST',
			'ld_course'  => 30586,
		),
	);

	/**
	 * Register the WP-CLI command.
	 */
	public static function register() {
		WP_CLI::add_command( 'hl-core setup-short-courses', array( new self(), 'run' ) );
	}

	/**
	 * Create short-course cycles with pathways, components, enrollments, and completion.
	 *
	 * ## OPTIONS
	 *
	 * [--clean]
	 * : Remove all short-course cycles (SC-*) and their data before creating.
	 *
	 * [--dry-run]
	 * : Log all actions without writing to the database.
	 *
	 * ## EXAMPLES
	 *
	 *     wp hl-core setup-short-courses
	 *     wp hl-core setup-short-courses --dry-run
	 *     wp hl-core setup-short-courses --clean
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Named args.
	 */
	public function run( $args, $assoc_args ) {
		$clean   = isset( $assoc_args['clean'] );
		$dry_run = isset( $assoc_args['dry-run'] );

		if ( $clean ) {
			$this->clean( $dry_run );
			if ( ! $dry_run ) {
				WP_CLI::success( 'Short-course data cleaned.' );
			} else {
				WP_CLI::success( '[DRY-RUN] Clean complete — no data modified.' );
			}
			return;
		}

		WP_CLI::line( '' );
		WP_CLI::line( '=== HL Core Short Courses Setup ===' );
		if ( $dry_run ) {
			WP_CLI::line( '    (dry-run mode — no writes)' );
		}
		WP_CLI::line( '' );

		$summary = array();

		foreach ( self::$courses as $course ) {
			$result = $this->setup_course( $course, $dry_run );
			if ( $result ) {
				$summary[] = $result;
			}
		}

		WP_CLI::line( '' );
		if ( $dry_run ) {
			WP_CLI::success( '[DRY-RUN] Short courses setup preview complete — no data written.' );
		} else {
			WP_CLI::success( 'Short courses setup complete!' );
		}

		WP_CLI::line( '' );
		WP_CLI::line( 'Summary:' );
		foreach ( $summary as $s ) {
			WP_CLI::line( "  {$s['cycle_code']}: cycle_id={$s['cycle_id']}, pathway_id={$s['pathway_id']}, enrollments={$s['enrollment_count']}, complete={$s['complete']}, in_progress={$s['in_progress']}" );
		}
		WP_CLI::line( '' );
	}

	// ------------------------------------------------------------------
	// Per-course setup
	// ------------------------------------------------------------------

	/**
	 * Set up a single short course: cycle, pathway, component, enrollments, completion.
	 *
	 * @param array $course  Course definition from self::$courses.
	 * @param bool  $dry_run Whether to skip DB writes.
	 * @return array|null Summary data, or null on skip.
	 */
	private function setup_course( $course, $dry_run ) {
		$cycle_code = $course['cycle_code'];
		$cycle_name = $course['cycle_name'];
		$ld_course  = $course['ld_course'];

		WP_CLI::line( "--- {$cycle_name} ({$cycle_code}) ---" );

		// Idempotency check.
		if ( $this->cycle_exists( $cycle_code ) ) {
			WP_CLI::warning( "  Cycle {$cycle_code} already exists. Skipping. Run with --clean first to re-create." );
			return null;
		}

		// Step 1: Create cycle.
		$cycle_id = $this->create_cycle( $cycle_name, $cycle_code, $dry_run );

		// Step 2: Create pathway.
		$pathway_id = $this->create_pathway( $cycle_name, $cycle_id, $dry_run );

		// Step 3: Create component.
		$component_id = $this->create_component( $cycle_name, $pathway_id, $cycle_id, $ld_course, $dry_run );

		// Step 4: Discover enrollments from LearnDash.
		$enrollments = $this->discover_enrollments( $cycle_id, $ld_course, $dry_run );

		// Step 5: Assign pathways.
		$this->assign_pathways( $enrollments, $pathway_id, $dry_run );

		// Step 6: Import completion.
		$completion = $this->import_completion( $enrollments, $component_id, $ld_course, $dry_run );

		WP_CLI::line( '' );

		return array(
			'cycle_code'       => $cycle_code,
			'cycle_id'         => $dry_run ? '(dry-run)' : $cycle_id,
			'pathway_id'       => $dry_run ? '(dry-run)' : $pathway_id,
			'enrollment_count' => count( $enrollments ),
			'complete'         => $completion['complete'],
			'in_progress'      => $completion['in_progress'],
		);
	}

	// ------------------------------------------------------------------
	// Idempotency
	// ------------------------------------------------------------------

	/**
	 * Check if a cycle with the given code already exists.
	 *
	 * @param string $cycle_code Cycle code to check.
	 * @return bool
	 */
	private function cycle_exists( $cycle_code ) {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT cycle_id FROM {$wpdb->prefix}hl_cycle WHERE cycle_code = %s LIMIT 1",
				$cycle_code
			)
		);
	}

	// ------------------------------------------------------------------
	// Clean
	// ------------------------------------------------------------------

	/**
	 * Remove all short-course cycles (SC-*) and their related data.
	 *
	 * @param bool $dry_run Whether to skip DB writes.
	 */
	private function clean( $dry_run ) {
		global $wpdb;
		$t = $wpdb->prefix;

		// Find all SC-* cycles.
		$cycles = $wpdb->get_results(
			"SELECT cycle_id, cycle_code FROM {$t}hl_cycle WHERE cycle_code LIKE 'SC-%'",
			ARRAY_A
		);

		if ( empty( $cycles ) ) {
			WP_CLI::log( 'No short-course cycles found — nothing to clean.' );
			return;
		}

		foreach ( $cycles as $cycle ) {
			$cycle_id   = (int) $cycle['cycle_id'];
			$cycle_code = $cycle['cycle_code'];

			WP_CLI::log( "  Cleaning {$cycle_code} (cycle_id={$cycle_id})..." );

			// Get enrollment IDs for cascade delete.
			$enrollment_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT enrollment_id FROM {$t}hl_enrollment WHERE cycle_id = %d",
					$cycle_id
				)
			);

			if ( ! empty( $enrollment_ids ) ) {
				$in_ids = implode( ',', array_map( 'intval', $enrollment_ids ) );

				if ( $dry_run ) {
					WP_CLI::log( "    [DRY-RUN] Would delete component_states, pathway_assignments, enrollments for " . count( $enrollment_ids ) . " enrollments" );
				} else {
					$wpdb->query( "DELETE FROM {$t}hl_component_state WHERE enrollment_id IN ({$in_ids})" );
					$wpdb->query( "DELETE FROM {$t}hl_pathway_assignment WHERE enrollment_id IN ({$in_ids})" );
					$wpdb->query( "DELETE FROM {$t}hl_enrollment WHERE cycle_id = {$cycle_id}" );
					WP_CLI::log( "    Deleted " . count( $enrollment_ids ) . " enrollments + states + assignments." );
				}
			}

			if ( $dry_run ) {
				WP_CLI::log( "    [DRY-RUN] Would delete components, pathways, cycle for {$cycle_code}" );
			} else {
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$t}hl_component WHERE cycle_id = %d", $cycle_id ) );
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$t}hl_pathway WHERE cycle_id = %d", $cycle_id ) );
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$t}hl_cycle WHERE cycle_id = %d", $cycle_id ) );
				WP_CLI::log( "    Deleted cycle {$cycle_code} + pathways + components." );
			}
		}
	}

	// ------------------------------------------------------------------
	// Step 1: Create Cycle
	// ------------------------------------------------------------------

	/**
	 * Create a short-course cycle.
	 *
	 * @param string $cycle_name Cycle name.
	 * @param string $cycle_code Cycle code.
	 * @param bool   $dry_run    Whether to skip DB writes.
	 * @return int Cycle ID (0 in dry-run mode).
	 */
	private function create_cycle( $cycle_name, $cycle_code, $dry_run ) {
		if ( $dry_run ) {
			WP_CLI::log( "  [1] [DRY-RUN] Would create cycle: {$cycle_name} ({$cycle_code}), type=course, no partnership" );
			return 0;
		}

		$repo     = new HL_Cycle_Repository();
		$cycle_id = $repo->create( array(
			'cycle_name' => $cycle_name,
			'cycle_code' => $cycle_code,
			'cycle_type' => 'course',
			'status'     => 'active',
		) );

		WP_CLI::log( "  [1] Cycle created: id={$cycle_id}, code={$cycle_code}" );
		return $cycle_id;
	}

	// ------------------------------------------------------------------
	// Step 2: Create Pathway
	// ------------------------------------------------------------------

	/**
	 * Create the single pathway for this short course.
	 *
	 * @param string $pathway_name Pathway name (same as cycle name).
	 * @param int    $cycle_id     Cycle ID.
	 * @param bool   $dry_run      Whether to skip DB writes.
	 * @return int Pathway ID (0 in dry-run mode).
	 */
	private function create_pathway( $pathway_name, $cycle_id, $dry_run ) {
		if ( $dry_run ) {
			WP_CLI::log( "  [2] [DRY-RUN] Would create pathway: {$pathway_name}, target_roles=[teacher]" );
			return 0;
		}

		$svc        = new HL_Pathway_Service();
		$pathway_id = $svc->create_pathway( array(
			'pathway_name'  => $pathway_name,
			'cycle_id'      => $cycle_id,
			'target_roles'  => array( 'teacher' ),
			'active_status' => 1,
		) );

		WP_CLI::log( "  [2] Pathway created: id={$pathway_id}" );
		return $pathway_id;
	}

	// ------------------------------------------------------------------
	// Step 3: Create Component
	// ------------------------------------------------------------------

	/**
	 * Create the single LearnDash course component.
	 *
	 * @param string $title      Component title (same as cycle name).
	 * @param int    $pathway_id Pathway ID.
	 * @param int    $cycle_id   Cycle ID.
	 * @param int    $ld_course  LearnDash course post ID.
	 * @param bool   $dry_run    Whether to skip DB writes.
	 * @return int Component ID (0 in dry-run mode).
	 */
	private function create_component( $title, $pathway_id, $cycle_id, $ld_course, $dry_run ) {
		if ( $dry_run ) {
			WP_CLI::log( "  [3] [DRY-RUN] Would create component: {$title}, LD course={$ld_course}" );
			return 0;
		}

		$svc          = new HL_Pathway_Service();
		$component_id = $svc->create_component( array(
			'title'          => $title,
			'pathway_id'     => $pathway_id,
			'cycle_id'       => $cycle_id,
			'component_type' => 'learndash_course',
			'weight'         => 1.0,
			'ordering_hint'  => 1,
			'external_ref'   => wp_json_encode( array( 'course_id' => $ld_course ) ),
		) );

		WP_CLI::log( "  [3] Component created: id={$component_id}, LD course={$ld_course}" );
		return $component_id;
	}

	// ------------------------------------------------------------------
	// Step 4: Discover Enrollments from LearnDash
	// ------------------------------------------------------------------

	/**
	 * Find users enrolled in the LD course and create HL enrollments.
	 *
	 * @param int  $cycle_id  Cycle ID.
	 * @param int  $ld_course LearnDash course post ID.
	 * @param bool $dry_run   Whether to skip DB writes.
	 * @return array Array of enrollment records: [ [ 'enrollment_id' => int, 'user_id' => int ], ... ]
	 */
	private function discover_enrollments( $cycle_id, $ld_course, $dry_run ) {
		global $wpdb;

		$user_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT user_id FROM {$wpdb->prefix}learndash_user_activity WHERE post_id = %d AND activity_type = 'course'",
				$ld_course
			)
		);

		// Filter out admin/test accounts.
		$user_ids = array_filter( $user_ids, function ( $uid ) {
			return ! in_array( (int) $uid, self::$skip_user_ids, true );
		} );
		$user_ids = array_values( $user_ids );

		if ( empty( $user_ids ) ) {
			WP_CLI::log( "  [4] No LD enrollments found for course {$ld_course}" );
			return array();
		}

		$now         = current_time( 'mysql', true );
		$enrollments = array();

		foreach ( $user_ids as $uid ) {
			$uid = (int) $uid;

			if ( $dry_run ) {
				$enrollments[] = array(
					'enrollment_id' => 0,
					'user_id'       => $uid,
				);
				continue;
			}

			// Check for existing enrollment (idempotency).
			$existing = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT enrollment_id FROM {$wpdb->prefix}hl_enrollment WHERE cycle_id = %d AND user_id = %d LIMIT 1",
					$cycle_id,
					$uid
				)
			);

			if ( $existing ) {
				$enrollments[] = array(
					'enrollment_id' => (int) $existing,
					'user_id'       => $uid,
				);
				continue;
			}

			$wpdb->insert( $wpdb->prefix . 'hl_enrollment', array(
				'enrollment_uuid' => wp_generate_uuid4(),
				'cycle_id'        => $cycle_id,
				'user_id'         => $uid,
				'roles'           => '["teacher"]',
				'status'          => 'active',
				'enrolled_at'     => $now,
				'created_at'      => $now,
				'updated_at'      => $now,
			) );

			$enrollments[] = array(
				'enrollment_id' => (int) $wpdb->insert_id,
				'user_id'       => $uid,
			);
		}

		$label = $dry_run ? '[DRY-RUN] Would create' : 'Created';
		WP_CLI::log( "  [4] {$label} " . count( $enrollments ) . " enrollments (LD course {$ld_course})" );

		return $enrollments;
	}

	// ------------------------------------------------------------------
	// Step 5: Assign Pathways
	// ------------------------------------------------------------------

	/**
	 * Assign the single pathway to all enrollments.
	 *
	 * @param array $enrollments Array of enrollment records.
	 * @param int   $pathway_id  Pathway ID.
	 * @param bool  $dry_run     Whether to skip DB writes.
	 */
	private function assign_pathways( $enrollments, $pathway_id, $dry_run ) {
		if ( empty( $enrollments ) ) {
			WP_CLI::log( '  [5] No enrollments — skipping pathway assignment.' );
			return;
		}

		if ( $dry_run ) {
			WP_CLI::log( '  [5] [DRY-RUN] Would assign pathway to ' . count( $enrollments ) . ' enrollments' );
			return;
		}

		$assign_svc = new HL_Pathway_Assignment_Service();
		$assigned   = 0;
		$skipped    = 0;

		foreach ( $enrollments as $e ) {
			$result = $assign_svc->assign_pathway( $e['enrollment_id'], $pathway_id, 'role_default' );
			if ( is_wp_error( $result ) ) {
				$skipped++;
			} else {
				$assigned++;
			}
		}

		WP_CLI::log( "  [5] Pathway assigned: {$assigned} new, {$skipped} skipped (already assigned)" );
	}

	// ------------------------------------------------------------------
	// Step 6: Import Completion from LearnDash
	// ------------------------------------------------------------------

	/**
	 * Import course completion status from LearnDash user_activity table.
	 *
	 * @param array $enrollments  Array of enrollment records.
	 * @param int   $component_id Component ID.
	 * @param int   $ld_course    LearnDash course post ID.
	 * @param bool  $dry_run      Whether to skip DB writes.
	 * @return array Counts: [ 'complete' => int, 'in_progress' => int ]
	 */
	private function import_completion( $enrollments, $component_id, $ld_course, $dry_run ) {
		global $wpdb;

		$count_complete    = 0;
		$count_in_progress = 0;

		if ( empty( $enrollments ) ) {
			WP_CLI::log( '  [6] No enrollments — skipping completion import.' );
			return array( 'complete' => 0, 'in_progress' => 0 );
		}

		foreach ( $enrollments as $e ) {
			$activity = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT activity_status, activity_completed FROM {$wpdb->prefix}learndash_user_activity WHERE user_id = %d AND post_id = %d AND activity_type = 'course' LIMIT 1",
					$e['user_id'],
					$ld_course
				)
			);

			if ( ! $activity ) {
				continue;
			}

			if ( $dry_run ) {
				if ( (int) $activity->activity_status === 1 ) {
					$count_complete++;
				} else {
					$count_in_progress++;
				}
				continue;
			}

			if ( (int) $activity->activity_status === 1 ) {
				// Complete.
				$completed_at = $activity->activity_completed
					? gmdate( 'Y-m-d H:i:s', (int) $activity->activity_completed )
					: current_time( 'mysql', true );

				$wpdb->insert( $wpdb->prefix . 'hl_component_state', array(
					'enrollment_id'      => $e['enrollment_id'],
					'component_id'       => $component_id,
					'completion_percent'  => 100,
					'completion_status'   => 'complete',
					'completed_at'       => $completed_at,
					'last_computed_at'   => current_time( 'mysql', true ),
				) );
				$count_complete++;
			} else {
				// In progress (row exists but not complete).
				$wpdb->insert( $wpdb->prefix . 'hl_component_state', array(
					'enrollment_id'      => $e['enrollment_id'],
					'component_id'       => $component_id,
					'completion_percent'  => 50,
					'completion_status'   => 'in_progress',
					'last_computed_at'   => current_time( 'mysql', true ),
				) );
				$count_in_progress++;
			}
		}

		$label = $dry_run ? '[DRY-RUN] ' : '';
		WP_CLI::log( "  [6] {$label}Completion imported: {$count_complete} complete, {$count_in_progress} in-progress" );

		return array(
			'complete'    => $count_complete,
			'in_progress' => $count_in_progress,
		);
	}
}
