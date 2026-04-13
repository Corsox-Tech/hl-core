# Feature Tracker → GitHub Issues Sync — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** One-way sync Feature Tracker tickets to GitHub Issues via a WP-CLI command, with dry-run support and bidirectional status sync (close/reopen).

**Architecture:** Single new CLI class (`HL_CLI_Sync_Tickets`) handles all sync logic. Schema rev 38 adds `github_issue_id` column to `hl_ticket`. The command shells out to `gh` CLI for all GitHub API calls.

**Tech Stack:** PHP 7.4+, WordPress WP-CLI, `gh` CLI (GitHub CLI)

**Spec:** `docs/superpowers/specs/2026-04-13-ticket-github-sync-design.md`

---

### Task 1: Schema — add `github_issue_id` column

**Files:**
- Modify: `includes/class-hl-installer.php:2044-2065` (CREATE TABLE body)
- Modify: `includes/class-hl-installer.php:150-253` (maybe_upgrade migration ladder)

- [ ] **Step 1: Add `github_issue_id` to the CREATE TABLE body**

In `includes/class-hl-installer.php`, find the `hl_ticket` CREATE TABLE block (line ~2058). Add the new column after `context_user_id`:

```php
            context_user_id bigint(20) unsigned NULL DEFAULT NULL,
            github_issue_id bigint(20) unsigned NULL DEFAULT NULL,
            PRIMARY KEY (ticket_id),
```

- [ ] **Step 2: Add the Rev 38 migration block**

In `maybe_upgrade()`, after the Rev 37 block (line ~251) and before the `update_option` call (line ~253), add:

```php
            // Rev 38: Add github_issue_id column to hl_ticket for GitHub sync.
            if ( (int) $stored < 38 ) {
                self::migrate_ticket_add_github_issue_id();
            }
```

- [ ] **Step 3: Bump `$current_revision` to 38**

Change line ~153:

```php
        $current_revision = 38;
```

- [ ] **Step 4: Add the migration method**

Add a new private static method at the end of the class (after the last migration method):

```php
    /**
     * Rev 38: Add github_issue_id column to hl_ticket for GitHub Issues sync.
     */
    private static function migrate_ticket_add_github_issue_id() {
        global $wpdb;
        $table = $wpdb->prefix . 'hl_ticket';

        // Guard: skip if column already exists.
        $col = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}` LIKE 'github_issue_id'" );
        if ( ! empty( $col ) ) {
            return;
        }

        $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `github_issue_id` bigint(20) unsigned NULL DEFAULT NULL AFTER `context_user_id`" );

        if ( $wpdb->last_error ) {
            error_log( '[HL_INSTALLER] Rev 38 failed: ' . $wpdb->last_error );
        }
    }
```

- [ ] **Step 5: Commit**

```bash
git add includes/class-hl-installer.php
git commit -m "feat: schema rev 38 — add github_issue_id column to hl_ticket"
```

---

### Task 2: CLI command — scaffold and `gh` preflight checks

**Files:**
- Create: `includes/cli/class-hl-cli-sync-tickets.php`
- Modify: `hl-core.php:261-262` (require + register)

- [ ] **Step 1: Create the CLI command file with preflight checks**

Create `includes/cli/class-hl-cli-sync-tickets.php`:

```php
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
```

- [ ] **Step 2: Register the command in `hl-core.php`**

In `hl-core.php`, add the require line after line ~262 (after the `test-email-renderer` require):

```php
            require_once HL_CORE_INCLUDES_DIR . 'cli/class-hl-cli-sync-tickets.php';
```

Then add the register call after line ~372 (after the last `::register()` call inside the `if ( defined( 'WP_CLI' ) && WP_CLI )` block):

```php
            HL_CLI_Sync_Tickets::register();
```

- [ ] **Step 3: Commit**

```bash
git add includes/cli/class-hl-cli-sync-tickets.php hl-core.php
git commit -m "feat: scaffold sync-tickets-to-github CLI command with gh preflight"
```

---

### Task 3: CLI command — create issues for unsynced tickets

**Files:**
- Modify: `includes/cli/class-hl-cli-sync-tickets.php`

- [ ] **Step 1: Add the `sync_create()` method**

Add to `HL_CLI_Sync_Tickets`, after `check_gh_cli()`:

```php
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
```

- [ ] **Step 2: Add the helper methods `build_issue_body()`, `build_labels()`, `parse_issue_number()`**

Add to `HL_CLI_Sync_Tickets`:

```php
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
```

- [ ] **Step 3: Commit**

```bash
git add includes/cli/class-hl-cli-sync-tickets.php
git commit -m "feat: sync-tickets create — push unsynced tickets to GitHub Issues"
```

---

### Task 4: CLI command — close and reopen synced issues

**Files:**
- Modify: `includes/cli/class-hl-cli-sync-tickets.php`

- [ ] **Step 1: Add the `sync_close()` method**

Add to `HL_CLI_Sync_Tickets`:

```php
    /**
     * Close GitHub Issues for resolved/closed tickets.
     *
     * @param bool $dry_run
     * @return array [ int $closed, int $errors ]
     */
    private function sync_close( $dry_run ) {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT ticket_id, title, github_issue_id, status
             FROM {$wpdb->prefix}hl_ticket
             WHERE github_issue_id IS NOT NULL AND status IN ('resolved', 'closed')"
        );

        $closed = 0;
        $errors = 0;

        foreach ( $rows as $ticket ) {
            if ( $dry_run ) {
                WP_CLI::line( sprintf(
                    'CLOSE:  #%d (GitHub #%d) — %s',
                    $ticket->ticket_id,
                    $ticket->github_issue_id,
                    $ticket->status
                ) );
                $closed++;
                continue;
            }

            $cmd = sprintf(
                'gh issue close %d --repo %s 2>&1',
                $ticket->github_issue_id,
                escapeshellarg( self::REPO )
            );

            exec( $cmd, $output, $code );
            $result = implode( "\n", $output );
            $output = array();

            // code 0 = closed, or already closed (gh doesn't error on double-close)
            if ( $code !== 0 && strpos( $result, 'already closed' ) === false ) {
                WP_CLI::line( sprintf(
                    'ERROR:  #%d (GitHub #%d) — %s',
                    $ticket->ticket_id,
                    $ticket->github_issue_id,
                    $result
                ) );
                $errors++;
                continue;
            }

            WP_CLI::line( sprintf(
                'CLOSE:  #%d (GitHub #%d) — %s',
                $ticket->ticket_id,
                $ticket->github_issue_id,
                $ticket->status
            ) );
            $closed++;
        }

        return array( $closed, $errors );
    }
```

- [ ] **Step 2: Add the `sync_reopen()` method**

Add to `HL_CLI_Sync_Tickets`:

```php
    /**
     * Reopen GitHub Issues for tickets that returned to active status.
     *
     * Checks the GitHub issue state first to avoid unnecessary API calls.
     *
     * @param bool $dry_run
     * @return array [ int $reopened, int $errors ]
     */
    private function sync_reopen( $dry_run ) {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT ticket_id, title, github_issue_id, status
             FROM {$wpdb->prefix}hl_ticket
             WHERE github_issue_id IS NOT NULL
               AND status NOT IN ('resolved', 'closed', 'draft')"
        );

        $reopened = 0;
        $errors   = 0;

        foreach ( $rows as $ticket ) {
            // Check if the GitHub issue is actually closed before reopening.
            $state_cmd = sprintf(
                'gh issue view %d --repo %s --json state --jq .state 2>&1',
                $ticket->github_issue_id,
                escapeshellarg( self::REPO )
            );

            exec( $state_cmd, $output, $code );
            $state = strtoupper( trim( implode( '', $output ) ) );
            $output = array();

            if ( $code !== 0 ) {
                WP_CLI::line( sprintf(
                    'ERROR:  #%d (GitHub #%d) — could not read issue state',
                    $ticket->ticket_id,
                    $ticket->github_issue_id
                ) );
                $errors++;
                continue;
            }

            // If the issue is open, nothing to do.
            if ( $state === 'OPEN' ) {
                continue;
            }

            if ( $dry_run ) {
                WP_CLI::line( sprintf(
                    'REOPEN: #%d (GitHub #%d) — reopened to %s',
                    $ticket->ticket_id,
                    $ticket->github_issue_id,
                    $ticket->status
                ) );
                $reopened++;
                continue;
            }

            $cmd = sprintf(
                'gh issue reopen %d --repo %s 2>&1',
                $ticket->github_issue_id,
                escapeshellarg( self::REPO )
            );

            exec( $cmd, $output, $code );
            $result = implode( "\n", $output );
            $output = array();

            if ( $code !== 0 ) {
                WP_CLI::line( sprintf(
                    'ERROR:  #%d (GitHub #%d) — %s',
                    $ticket->ticket_id,
                    $ticket->github_issue_id,
                    $result
                ) );
                $errors++;
                continue;
            }

            WP_CLI::line( sprintf(
                'REOPEN: #%d (GitHub #%d) — reopened to %s',
                $ticket->ticket_id,
                $ticket->github_issue_id,
                $ticket->status
            ) );
            $reopened++;
        }

        return array( $reopened, $errors );
    }
```

- [ ] **Step 3: Commit**

```bash
git add includes/cli/class-hl-cli-sync-tickets.php
git commit -m "feat: sync-tickets close/reopen — bidirectional status sync with GitHub"
```

---

### Task 5: Verify and update docs

**Files:**
- Modify: `STATUS.md` (build queue)
- Modify: `README.md` (What's Implemented — CLI commands section)

- [ ] **Step 1: Run the command with `--dry-run` to verify it works**

```bash
wp hl-core sync-tickets-to-github --dry-run
```

Expected: lists all non-draft tickets with CREATE/CLOSE/REOPEN actions and a summary line.

- [ ] **Step 2: Run without `--dry-run` to perform the actual sync**

```bash
wp hl-core sync-tickets-to-github
```

Expected: creates GitHub Issues, prints summary with 0 errors. Verify on GitHub that issues appear with correct labels and body.

- [ ] **Step 3: Update STATUS.md**

Add a new section to the build queue:

```markdown
### Feature Tracker → GitHub Sync (April 2026)
> **Spec:** `docs/superpowers/specs/2026-04-13-ticket-github-sync-design.md` | **Plan:** `docs/superpowers/plans/2026-04-13-ticket-github-sync.md`
- [x] **Schema rev 38** — `github_issue_id` column on `hl_ticket`.
- [x] **CLI command** — `wp hl-core sync-tickets-to-github [--dry-run]`. Creates issues, closes resolved, reopens active. Uses `gh` CLI.
- [x] **Deployed to test** — (update after deploy)
```

- [ ] **Step 4: Update README.md**

Add `sync-tickets-to-github` to the CLI commands section in README.md.

- [ ] **Step 5: Commit**

```bash
git add STATUS.md README.md
git commit -m "docs: add ticket-github-sync to STATUS.md and README.md"
```
