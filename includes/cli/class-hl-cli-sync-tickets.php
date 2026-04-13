<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * CLI command to sync Feature Tracker tickets to GitHub Issues.
 *
 * One-way sync: creates GitHub Issues for unsynced tickets,
 * closes issues for resolved/closed tickets, reopens issues
 * for tickets that return to active status.
 *
 * Requires the `gh` CLI to be installed and authenticated.
 *
 * Usage:
 *   wp hl-core sync-tickets-to-github
 *   wp hl-core sync-tickets-to-github --dry-run
 */
class HL_CLI_Sync_Tickets {

    const REPO = 'Corsox-Tech/hl-core';

    /**
     * Label maps — ticket enum values to GitHub label names.
     */
    private static $type_labels = array(
        'bug'             => 'bug',
        'improvement'     => 'enhancement',
        'feature_request' => 'feature-request',
    );

    private static $priority_labels = array(
        'low'      => 'priority:low',
        'medium'   => 'priority:medium',
        'high'     => 'priority:high',
        'critical' => 'priority:critical',
    );

    private static $category_labels = array(
        'course_content'    => 'cat:course-content',
        'platform_issue'    => 'cat:platform-issue',
        'account_access'    => 'cat:account-access',
        'forms_assessments' => 'cat:forms-assessments',
        'reports_data'      => 'cat:reports-data',
        'other'             => 'cat:other',
    );

    public static function register() {
        if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) return;
        WP_CLI::add_command( 'hl-core sync-tickets-to-github', array( new self(), 'run' ) );
    }

    /**
     * Sync Feature Tracker tickets to GitHub Issues.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Preview what would be created/closed/reopened without making changes.
     *
     * ## EXAMPLES
     *
     *     wp hl-core sync-tickets-to-github
     *     wp hl-core sync-tickets-to-github --dry-run
     */
    public function run( $args, $assoc_args ) {
        $dry_run = isset( $assoc_args['dry-run'] );

        if ( $dry_run ) {
            WP_CLI::line( '[DRY RUN]' );
        }

        $this->check_gh_cli();

        $created  = 0;
        $closed   = 0;
        $reopened = 0;
        $errors   = 0;

        // Phase 1: Create issues for unsynced tickets.
        list( $c, $e ) = $this->sync_create( $dry_run );
        $created = $c;
        $errors += $e;

        // Phase 2: Close issues for resolved/closed tickets.
        list( $cl, $e ) = $this->sync_close( $dry_run );
        $closed  = $cl;
        $errors += $e;

        // Phase 3: Reopen issues for tickets that returned to active.
        list( $r, $e ) = $this->sync_reopen( $dry_run );
        $reopened = $r;
        $errors  += $e;

        WP_CLI::line( '---' );
        WP_CLI::line( sprintf(
            'Summary: %d created, %d closed, %d reopened, %d errors',
            $created, $closed, $reopened, $errors
        ) );

        if ( $errors > 0 ) {
            WP_CLI::warning( "$errors sync errors occurred. See output above." );
        } else {
            WP_CLI::success( 'Sync complete.' );
        }
    }

    /**
     * Verify gh CLI is installed and authenticated. Hard error if not.
     */
    private function check_gh_cli() {
        // Check gh exists.
        exec( 'gh --version 2>&1', $output, $code );
        if ( $code !== 0 ) {
            WP_CLI::error( 'gh CLI not found. Install: https://cli.github.com' );
        }

        // Check gh is authenticated.
        exec( 'gh auth status 2>&1', $output, $code );
        if ( $code !== 0 ) {
            WP_CLI::error( 'gh CLI not authenticated. Run: gh auth login' );
        }
    }
}
