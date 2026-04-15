<?php
/**
 * WP-CLI: BuddyBoss Group Sync.
 *
 * @package HL_Core
 */
class HL_CLI_BB_Sync {

    /**
     * Register the CLI command (matches existing codebase pattern).
     */
    public static function register() {
        WP_CLI::add_command( 'hl-core bb-sync', array( new self(), 'run' ) );
    }

    /**
     * Sync BB group memberships for all or specific users.
     *
     * ## OPTIONS
     *
     * [--user=<user_id>]
     * : Sync a single user (both participant and coach status).
     *
     * [--all]
     * : Sync all users with active program enrollments + all coaches.
     *
     * [--coaches]
     * : Sync coach/coaching_director moderator status only.
     *
     * [--dry-run]
     * : Show what would change without making changes.
     *
     * ## EXAMPLES
     *
     *     wp hl-core bb-sync --all
     *     wp hl-core bb-sync --all --dry-run
     *     wp hl-core bb-sync --user=42
     *     wp hl-core bb-sync --coaches
     *
     * @param array $args       Positional args.
     * @param array $assoc_args Named args.
     */
    public function run( $args, $assoc_args ) {
        if ( ! HL_BB_Group_Sync_Service::is_bb_groups_available() ) {
            WP_CLI::error( 'BuddyBoss Groups API is not available.' );
        }

        $dry_run = isset( $assoc_args['dry-run'] );
        if ( $dry_run ) {
            WP_CLI::log( '=== DRY RUN — no changes will be made ===' );
        }

        if ( ! empty( $assoc_args['user'] ) ) {
            $user_id = (int) $assoc_args['user'];
            WP_CLI::log( "Syncing user {$user_id}..." );
            if ( ! $dry_run ) {
                HL_BB_Group_Sync_Service::sync_user_groups( $user_id );
                HL_BB_Group_Sync_Service::sync_coach_groups( $user_id );
            }
            WP_CLI::success( "User {$user_id} synced." );
            return;
        }

        if ( isset( $assoc_args['coaches'] ) ) {
            $this->sync_all_coaches( $dry_run );
            return;
        }

        if ( isset( $assoc_args['all'] ) ) {
            $this->sync_all_users( $dry_run );
            $this->sync_all_coaches( $dry_run );
            return;
        }

        WP_CLI::error( 'Specify --all, --coaches, or --user=<id>.' );
    }

    private function sync_all_users( bool $dry_run ) {
        global $wpdb;
        $user_ids = $wpdb->get_col(
            "SELECT DISTINCT e.user_id
             FROM {$wpdb->prefix}hl_enrollment e
             JOIN {$wpdb->prefix}hl_cycle c ON c.cycle_id = e.cycle_id
             WHERE c.cycle_type = 'program'
             AND c.status != 'archived'
             AND e.status = 'active'"
        );

        $count = count( $user_ids );
        WP_CLI::log( "Found {$count} users with active program enrollments." );

        $progress = \WP_CLI\Utils\make_progress_bar( 'Syncing users', $count );
        foreach ( $user_ids as $uid ) {
            if ( ! $dry_run ) {
                HL_BB_Group_Sync_Service::sync_user_groups( (int) $uid );
            }
            $progress->tick();
        }
        $progress->finish();

        $verb = $dry_run ? 'would sync' : 'synced';
        WP_CLI::success( "{$count} users {$verb}." );
    }

    private function sync_all_coaches( bool $dry_run ) {
        $coach_users = get_users( array(
            'role__in' => array( 'coach', 'coaching_director' ),
            'fields'   => 'ID',
        ) );

        $count = count( $coach_users );
        WP_CLI::log( "Found {$count} coaches/coaching directors." );

        foreach ( $coach_users as $uid ) {
            if ( ! $dry_run ) {
                HL_BB_Group_Sync_Service::sync_coach_groups( (int) $uid );
            }
        }

        $verb = $dry_run ? 'would sync' : 'synced';
        WP_CLI::success( "{$count} coaches {$verb}." );
    }
}
