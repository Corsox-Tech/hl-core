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

    /**
     * Create GitHub Issues for unsynced non-draft tickets.
     *
     * @param bool $dry_run
     * @return array [ int $created, int $errors ]
     */
    private function sync_create( $dry_run ) {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}hl_ticket
             WHERE status != 'draft' AND github_issue_id IS NULL
             ORDER BY created_at ASC"
        );

        $created = 0;
        $errors  = 0;

        foreach ( $rows as $ticket ) {
            $labels = $this->build_labels( $ticket );
            $label_str = implode( ', ', $labels );

            if ( $dry_run ) {
                WP_CLI::line( sprintf(
                    'CREATE: #%d "%s" → %s',
                    $ticket->ticket_id,
                    $ticket->title,
                    $label_str
                ) );
                $created++;
                continue;
            }

            $body  = $this->build_issue_body( $ticket );
            $title = $ticket->title;

            // Build gh command.
            $cmd = sprintf(
                'gh issue create --repo %s --title %s --body %s',
                escapeshellarg( self::REPO ),
                escapeshellarg( $title ),
                escapeshellarg( $body )
            );
            foreach ( $labels as $label ) {
                $cmd .= ' --label ' . escapeshellarg( $label );
            }

            exec( $cmd . ' 2>&1', $output, $code );
            $result = implode( "\n", $output );
            $output = array(); // reset for next iteration

            if ( $code !== 0 ) {
                WP_CLI::line( sprintf(
                    'ERROR:  #%d "%s" — %s',
                    $ticket->ticket_id,
                    $ticket->title,
                    $result
                ) );
                $errors++;
                continue;
            }

            // Parse issue number from URL: https://github.com/Corsox-Tech/hl-core/issues/42
            $issue_number = $this->parse_issue_number( $result );
            if ( ! $issue_number ) {
                WP_CLI::line( sprintf(
                    'ERROR:  #%d "%s" — could not parse issue number from: %s',
                    $ticket->ticket_id,
                    $ticket->title,
                    $result
                ) );
                $errors++;
                continue;
            }

            // Store the GitHub issue number on the ticket.
            $wpdb->update(
                $wpdb->prefix . 'hl_ticket',
                array( 'github_issue_id' => $issue_number ),
                array( 'ticket_id' => $ticket->ticket_id ),
                array( '%d' ),
                array( '%d' )
            );

            WP_CLI::line( sprintf(
                'CREATE: #%d "%s" → GitHub #%d (%s)',
                $ticket->ticket_id,
                $ticket->title,
                $issue_number,
                $label_str
            ) );
            $created++;
        }

        return array( $created, $errors );
    }

    /**
     * Build the GitHub Issue body markdown for a ticket.
     */
    private function build_issue_body( $ticket ) {
        $type_display     = ucwords( str_replace( '_', ' ', $ticket->type ) );
        $priority_display = ucfirst( $ticket->priority );
        $category_display = ucwords( str_replace( '_', ' ', $ticket->category ) );

        $creator = get_userdata( $ticket->creator_user_id );
        $creator_name = $creator ? $creator->display_name : 'Unknown (#' . $ticket->creator_user_id . ')';

        $date = substr( $ticket->created_at, 0, 10 ); // Y-m-d

        $body  = sprintf(
            "> **Type:** %s | **Priority:** %s | **Category:** %s\n",
            $type_display, $priority_display, $category_display
        );
        $body .= sprintf(
            "> **Submitted by:** %s — %s\n",
            $creator_name, $date
        );
        $body .= sprintf(
            "> **Ticket ID:** #%d (%s)\n",
            $ticket->ticket_id, $ticket->ticket_uuid
        );
        $body .= "\n---\n\n";
        $body .= $ticket->description;

        return $body;
    }

    /**
     * Build the array of GitHub labels for a ticket.
     */
    private function build_labels( $ticket ) {
        $labels = array();

        if ( isset( self::$type_labels[ $ticket->type ] ) ) {
            $labels[] = self::$type_labels[ $ticket->type ];
        }
        if ( isset( self::$priority_labels[ $ticket->priority ] ) ) {
            $labels[] = self::$priority_labels[ $ticket->priority ];
        }
        if ( isset( self::$category_labels[ $ticket->category ] ) ) {
            $labels[] = self::$category_labels[ $ticket->category ];
        }

        return $labels;
    }

    /**
     * Parse the GitHub issue number from a gh CLI output URL.
     *
     * gh issue create returns: https://github.com/Owner/Repo/issues/42
     *
     * @param string $output
     * @return int|null
     */
    private function parse_issue_number( $output ) {
        if ( preg_match( '/\/issues\/(\d+)/', $output, $matches ) ) {
            return (int) $matches[1];
        }
        return null;
    }
}
