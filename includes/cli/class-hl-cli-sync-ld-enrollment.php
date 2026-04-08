<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * CLI command to sync LearnDash course enrollment for all pathway assignments
 * in a given cycle.
 *
 * For each active enrollment with a pathway assignment, ensures the user has
 * LD course access for every learndash_course component in their pathway.
 * Safe to run multiple times — already-enrolled users are skipped.
 *
 * Usage:
 *   wp hl-core sync-ld-enrollment --cycle_id=5
 *   wp hl-core sync-ld-enrollment --cycle_id=5 --dry-run
 */
class HL_CLI_Sync_LD_Enrollment {

    public static function register() {
        if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) return;
        WP_CLI::add_command( 'hl-core sync-ld-enrollment', array( new self(), 'run' ) );
    }

    /**
     * Sync LearnDash course enrollment for a cycle's pathway assignments.
     *
     * ## OPTIONS
     *
     * --cycle_id=<id>
     * : The cycle ID to sync.
     *
     * [--dry-run]
     * : Preview what would be enrolled without making changes.
     *
     * ## EXAMPLES
     *
     *     wp hl-core sync-ld-enrollment --cycle_id=5
     *     wp hl-core sync-ld-enrollment --cycle_id=5 --dry-run
     */
    public function run( $args, $assoc_args ) {
        $cycle_id = absint( $assoc_args['cycle_id'] ?? 0 );
        $dry_run  = isset( $assoc_args['dry-run'] );

        if ( ! $cycle_id ) {
            WP_CLI::error( '--cycle_id is required.' );
        }

        if ( ! function_exists( 'ld_update_course_access' ) ) {
            WP_CLI::error( 'LearnDash is not active — ld_update_course_access() not available.' );
        }

        global $wpdb;

        // Verify cycle exists.
        $cycle_name = $wpdb->get_var( $wpdb->prepare(
            "SELECT cycle_name FROM {$wpdb->prefix}hl_cycle WHERE cycle_id = %d",
            $cycle_id
        ) );

        if ( ! $cycle_name ) {
            WP_CLI::error( sprintf( 'Cycle %d not found.', $cycle_id ) );
        }

        WP_CLI::log( sprintf( '%sSyncing LD enrollment for cycle "%s" (ID %d)...', $dry_run ? '[DRY RUN] ' : '', $cycle_name, $cycle_id ) );

        // Get all active enrollments with pathway assignments.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT pa.pathway_id, p.pathway_name, e.enrollment_id, e.user_id, e.language_preference, u.display_name
             FROM {$wpdb->prefix}hl_pathway_assignment pa
             JOIN {$wpdb->prefix}hl_enrollment e ON pa.enrollment_id = e.enrollment_id
             JOIN {$wpdb->prefix}hl_pathway p ON pa.pathway_id = p.pathway_id
             LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
             WHERE e.cycle_id = %d AND e.status = 'active'",
            $cycle_id
        ) );

        if ( empty( $rows ) ) {
            WP_CLI::success( 'No active pathway assignments found for this cycle.' );
            return;
        }

        WP_CLI::log( sprintf( 'Found %d pathway assignment(s).', count( $rows ) ) );

        // Pre-load all learndash_course components for this cycle.
        $components = $wpdb->get_results( $wpdb->prepare(
            "SELECT component_id, pathway_id, catalog_id, external_ref, title
             FROM {$wpdb->prefix}hl_component
             WHERE cycle_id = %d AND component_type = 'learndash_course' AND status = 'active'",
            $cycle_id
        ) );

        $components_by_pathway = array();
        foreach ( $components as $comp ) {
            $components_by_pathway[ $comp->pathway_id ][] = $comp;
        }

        if ( empty( $components_by_pathway ) ) {
            WP_CLI::success( 'No LearnDash course components found for this cycle.' );
            return;
        }

        $enrolled = 0;
        $skipped  = 0;
        $errors   = 0;

        foreach ( $rows as $row ) {
            $pw_id = $row->pathway_id;
            if ( empty( $components_by_pathway[ $pw_id ] ) ) {
                continue;
            }

            foreach ( $components_by_pathway[ $pw_id ] as $comp ) {
                $comp_obj = new HL_Component( (array) $comp );
                $ld_course_id = HL_Course_Catalog::resolve_ld_course_id( $comp_obj, $row );

                if ( ! $ld_course_id ) {
                    WP_CLI::warning( sprintf(
                        'No LD course ID for component "%s" (ID %d) — skipping user %s.',
                        $comp->title, $comp->component_id, $row->display_name
                    ) );
                    $errors++;
                    continue;
                }

                // Check existing access.
                if ( function_exists( 'sfwd_lms_has_access' ) && sfwd_lms_has_access( $ld_course_id, $row->user_id ) ) {
                    $skipped++;
                    continue;
                }

                if ( $dry_run ) {
                    WP_CLI::log( sprintf(
                        '  Would enroll %s (user %d) in LD course %d (%s) via pathway "%s"',
                        $row->display_name, $row->user_id, $ld_course_id, $comp->title, $row->pathway_name
                    ) );
                } else {
                    ld_update_course_access( $row->user_id, $ld_course_id );
                }
                $enrolled++;
            }
        }

        WP_CLI::success( sprintf(
            '%sComplete. Enrolled: %d, Already had access: %d, Errors: %d.',
            $dry_run ? '[DRY RUN] ' : '',
            $enrolled, $skipped, $errors
        ) );
    }
}

HL_CLI_Sync_LD_Enrollment::register();
