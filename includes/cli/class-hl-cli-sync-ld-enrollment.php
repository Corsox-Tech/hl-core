<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * CLI command to sync LearnDash course enrollment for a cycle.
 *
 * Covers two cases:
 * 1. Enrollments with explicit pathway assignments → sync those pathways' LD courses.
 * 2. Enrollments WITHOUT assignments → resolve pathways by role fallback, sync those.
 *
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
     * Sync LearnDash course enrollment for a cycle.
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

        // Pre-load all learndash_course components for this cycle, indexed by pathway.
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

        // Pre-load pathways with target_roles for role-based fallback.
        $pathways = $wpdb->get_results( $wpdb->prepare(
            "SELECT pathway_id, pathway_name, target_roles FROM {$wpdb->prefix}hl_pathway
             WHERE cycle_id = %d AND active_status = 1",
            $cycle_id
        ) );

        $pathway_names = array();
        foreach ( $pathways as $pw ) {
            $pathway_names[ $pw->pathway_id ] = $pw->pathway_name;
        }

        // Get ALL active enrollments for this cycle.
        $enrollments = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.enrollment_id, e.user_id, e.roles, e.language_preference, u.display_name
             FROM {$wpdb->prefix}hl_enrollment e
             LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
             WHERE e.cycle_id = %d AND e.status = 'active'",
            $cycle_id
        ) );

        if ( empty( $enrollments ) ) {
            WP_CLI::success( 'No active enrollments found for this cycle.' );
            return;
        }

        // Get explicit pathway assignments.
        $assignments = $wpdb->get_results( $wpdb->prepare(
            "SELECT enrollment_id, pathway_id FROM {$wpdb->prefix}hl_pathway_assignment
             WHERE enrollment_id IN (SELECT enrollment_id FROM {$wpdb->prefix}hl_enrollment WHERE cycle_id = %d AND status = 'active')",
            $cycle_id
        ) );

        $assigned_pathways = array();
        foreach ( $assignments as $a ) {
            $assigned_pathways[ $a->enrollment_id ][] = $a->pathway_id;
        }

        WP_CLI::log( sprintf( 'Found %d active enrollment(s), %d with explicit pathway assignments.', count( $enrollments ), count( $assigned_pathways ) ) );

        $enrolled = 0;
        $skipped  = 0;
        $errors   = 0;

        foreach ( $enrollments as $e ) {
            // Determine which pathways apply to this enrollment.
            if ( ! empty( $assigned_pathways[ $e->enrollment_id ] ) ) {
                $pw_ids = $assigned_pathways[ $e->enrollment_id ];
                $source = 'explicit';
            } else {
                // Role-based fallback.
                $roles = HL_Roles::parse_stored( $e->roles );
                if ( empty( $roles ) ) {
                    continue;
                }
                $pw_ids = array();
                foreach ( $pathways as $pw ) {
                    $target_roles = json_decode( $pw->target_roles, true );
                    if ( ! is_array( $target_roles ) ) continue;
                    $target_lower = array_map( 'strtolower', $target_roles );
                    foreach ( $roles as $role ) {
                        if ( in_array( strtolower( $role ), $target_lower, true ) ) {
                            $pw_ids[] = $pw->pathway_id;
                            break;
                        }
                    }
                }
                $source = 'role_fallback';
            }

            if ( empty( $pw_ids ) ) {
                continue;
            }

            foreach ( $pw_ids as $pw_id ) {
                if ( empty( $components_by_pathway[ $pw_id ] ) ) {
                    continue;
                }

                foreach ( $components_by_pathway[ $pw_id ] as $comp ) {
                    $comp_obj = new HL_Component( (array) $comp );
                    $ld_course_id = HL_Course_Catalog::resolve_ld_course_id( $comp_obj, $e );

                    if ( ! $ld_course_id ) {
                        WP_CLI::warning( sprintf(
                            'No LD course ID for component "%s" (ID %d) — skipping user %s.',
                            $comp->title, $comp->component_id, $e->display_name
                        ) );
                        $errors++;
                        continue;
                    }

                    // Check existing access.
                    if ( function_exists( 'sfwd_lms_has_access' ) && sfwd_lms_has_access( $ld_course_id, $e->user_id ) ) {
                        $skipped++;
                        continue;
                    }

                    if ( $dry_run ) {
                        WP_CLI::log( sprintf(
                            '  Would enroll %s (user %d) in LD course %d (%s) via pathway "%s" [%s]',
                            $e->display_name, $e->user_id, $ld_course_id, $comp->title,
                            isset( $pathway_names[ $pw_id ] ) ? $pathway_names[ $pw_id ] : "#{$pw_id}",
                            $source
                        ) );
                    } else {
                        ld_update_course_access( $e->user_id, $ld_course_id );
                    }
                    $enrolled++;
                }
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
