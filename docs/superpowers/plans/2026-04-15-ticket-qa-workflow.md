# Ticket QA Workflow + GitHub Sync Removal — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a QA testing gate (`ready_for_test` / `test_failed`) to the Feature Tracker, let ticket creators approve or reject fixes, and remove the GitHub Issues sync feature entirely.

**Architecture:** Two new DB enum values, one new service method with optimistic locking, one new AJAX endpoint, frontend approve/reject buttons via event delegation, and a full teardown of the GitHub sync code + schema column + docs.

**Tech Stack:** PHP 7.4 / WordPress 6.0+ / jQuery / MySQL / WP-CLI (`gh` CLI for GitHub cleanup)

**Spec:** `docs/superpowers/specs/2026-04-15-ticket-qa-workflow-design.md`

---

## File Map

| File | Action | Responsibility |
|------|--------|---------------|
| `includes/class-hl-installer.php` | Modify | Rev 41 migration: expand enum, drop `github_issue_id`. Update CREATE TABLE. Bump `$current_revision`. |
| `includes/services/class-hl-ticket-service.php` | Modify | Update `VALID_STATUSES`. Add `CREATOR_LOCKED_STATUSES`. Update `can_edit()`. Add `creator_review_ticket()`. |
| `includes/frontend/class-hl-frontend-feature-tracker.php` | Modify | Register AJAX endpoint. Add handler. Add `data-current-user-id`. Add status dropdown options. |
| `assets/js/frontend.js` | Modify | Update `statusLabels`. Add `currentUserId`. Add approve/reject handlers with event delegation. |
| `assets/css/frontend.css` | Modify | Add pill colors for `ready_for_test` and `test_failed`. Add `.hlft-review-panel` styles. |
| `hl-core.php` | Modify | Remove `require_once` and `register()` for sync-tickets CLI. |
| `includes/cli/class-hl-cli-sync-tickets.php` | Delete | Entire file. |
| `docs/superpowers/specs/2026-04-13-ticket-github-sync-design.md` | Delete | Sync spec. |
| `docs/superpowers/plans/2026-04-13-ticket-github-sync.md` | Delete | Sync plan. |
| `README.md` | Modify | Remove sync CLI docs, add QA workflow docs. |
| `STATUS.md` | Modify | Update build queue. |

---

### Task 1: Schema Migration — Expand Status Enum + Drop `github_issue_id`

**Files:**
- Modify: `includes/class-hl-installer.php:152-153` (bump revision)
- Modify: `includes/class-hl-installer.php:265-266` (add rev 41 block after rev 40)
- Modify: `includes/class-hl-installer.php:2072` (update CREATE TABLE enum)
- Modify: `includes/class-hl-installer.php:2080` (remove `github_issue_id` from CREATE TABLE)
- Add migration method after line ~3826

- [ ] **Step 1: Update CREATE TABLE body — expand status enum**

In `includes/class-hl-installer.php`, find line 2072:
```php
            status enum('draft','open','in_review','in_progress','resolved','closed') NOT NULL DEFAULT 'open',
```
Replace with:
```php
            status enum('draft','open','in_review','in_progress','ready_for_test','test_failed','resolved','closed') NOT NULL DEFAULT 'open',
```

- [ ] **Step 2: Remove `github_issue_id` from CREATE TABLE body**

In `includes/class-hl-installer.php`, delete line 2080:
```php
            github_issue_id bigint(20) unsigned NULL DEFAULT NULL,
```

- [ ] **Step 3: Add rev 41 migration block to `maybe_upgrade()`**

In `includes/class-hl-installer.php`, after the rev 40 block (after line 266), add:

```php
            // Rev 41: QA workflow — expand ticket status enum + drop github_issue_id.
            if ( (int) $stored < 41 ) {
                $ok = self::migrate_ticket_qa_workflow();
                if ( ! $ok ) {
                    return; // Bail — next plugins_loaded retries.
                }
            }
```

- [ ] **Step 4: Bump `$current_revision`**

In `includes/class-hl-installer.php`, line 153, change:
```php
        $current_revision = 40;
```
to:
```php
        $current_revision = 41;
```

- [ ] **Step 5: Add the migration method**

In `includes/class-hl-installer.php`, after the `migrate_ticket_add_github_issue_id()` method (after line ~3826), add:

```php
    /**
     * Rev 41: QA Workflow — expand status enum, drop github_issue_id column.
     *
     * Idempotent:
     *   - MODIFY COLUMN is safe to re-run (enum expansion is additive).
     *   - DROP COLUMN is guarded by SHOW COLUMNS check.
     *
     * @return bool True on success, false on failure.
     */
    private static function migrate_ticket_qa_workflow() {
        global $wpdb;
        $table = $wpdb->prefix . 'hl_ticket';

        // 1. Expand status enum.
        $res = $wpdb->query( "ALTER TABLE `{$table}` MODIFY COLUMN `status`
            enum('draft','open','in_review','in_progress','ready_for_test','test_failed','resolved','closed')
            NOT NULL DEFAULT 'open'
            CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" );

        if ( $res === false ) {
            error_log( '[HL_INSTALLER] Rev 41 failed on status enum expansion: ' . $wpdb->last_error );
            return false;
        }

        // 2. Drop github_issue_id (idempotent guard).
        $col = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}` LIKE 'github_issue_id'" );
        if ( ! empty( $col ) ) {
            $res = $wpdb->query( "ALTER TABLE `{$table}` DROP COLUMN `github_issue_id`" );
            if ( $res === false ) {
                error_log( '[HL_INSTALLER] Rev 41 failed on DROP github_issue_id: ' . $wpdb->last_error );
                return false;
            }
        }

        return true;
    }
```

- [ ] **Step 6: Commit**

```bash
git add includes/class-hl-installer.php
git commit -m "feat(ticket): rev 41 — expand status enum + drop github_issue_id"
```

---

### Task 2: Service Layer — Update Constants + `can_edit()`

**Files:**
- Modify: `includes/services/class-hl-ticket-service.php:29-36` (constants)
- Modify: `includes/services/class-hl-ticket-service.php:109-131` (`can_edit()`)

- [ ] **Step 1: Update `VALID_STATUSES`**

In `class-hl-ticket-service.php`, line 30, replace:
```php
    const VALID_STATUSES = array( 'draft', 'open', 'in_review', 'in_progress', 'resolved', 'closed' );
```
with:
```php
    const VALID_STATUSES = array( 'draft', 'open', 'in_review', 'in_progress', 'ready_for_test', 'test_failed', 'resolved', 'closed' );
```

- [ ] **Step 2: Add `CREATOR_LOCKED_STATUSES` constant**

After line 36 (`const EDIT_WINDOW_SECONDS = 7200;`), add:

```php

    /**
     * Statuses where the creator cannot edit ticket content.
     *
     * - ready_for_test: fix deployed, awaiting creator verification. Editing would change what was fixed.
     * - resolved / closed: terminal.
     *
     * Note: test_failed intentionally excluded — creator may add reproduction details.
     *
     * @var string[]
     */
    const CREATOR_LOCKED_STATUSES = array( 'ready_for_test', 'resolved', 'closed' );
```

- [ ] **Step 3: Update `can_edit()` to use `CREATOR_LOCKED_STATUSES`**

In `class-hl-ticket-service.php`, replace lines 119-121:
```php
        if ( in_array( $ticket['status'], self::TERMINAL_STATUSES, true ) ) {
            return false;
        }
```
with:
```php
        if ( in_array( $ticket['status'], self::CREATOR_LOCKED_STATUSES, true ) ) {
            return false;
        }
```

- [ ] **Step 4: Commit**

```bash
git add includes/services/class-hl-ticket-service.php
git commit -m "feat(ticket): update status constants + lock editing at ready_for_test"
```

---

### Task 3: Service Layer — Add `creator_review_ticket()` Method

**Files:**
- Modify: `includes/services/class-hl-ticket-service.php` (add method after `change_status()`, before line 716 `// ─── Comments ───`)

- [ ] **Step 1: Add the `creator_review_ticket()` method**

In `class-hl-ticket-service.php`, before the `// ─── Comments ───` section marker (line 716), add:

```php

    /**
     * Creator approve/reject a ticket in ready_for_test status.
     *
     * Uses optimistic locking via WHERE clause to prevent TOCTOU races.
     *
     * @param string $uuid          Ticket UUID.
     * @param string $review_action 'approve' or 'reject'.
     * @param string $comment       Required for reject; ignored for approve.
     * @return array|WP_Error Updated ticket or error.
     */
    public function creator_review_ticket( $uuid, $review_action, $comment = '' ) {
        global $wpdb;

        $ticket = $this->get_ticket_raw( $uuid );
        if ( ! $ticket ) {
            return new WP_Error( 'not_found', __( 'Ticket not found.', 'hl-core' ) );
        }

        // Auth: only the ticket creator can review.
        $current_user_id = get_current_user_id();
        if ( (int) $ticket['creator_user_id'] !== $current_user_id ) {
            return new WP_Error( 'forbidden', __( 'Only the ticket creator can approve or reject.', 'hl-core' ) );
        }

        // Status gate: must be ready_for_test.
        if ( $ticket['status'] !== 'ready_for_test' ) {
            return new WP_Error(
                'invalid_action',
                __( 'This ticket is no longer awaiting review. It may have been updated by an admin. Please refresh to see the current status.', 'hl-core' )
            );
        }

        // Validate action.
        if ( ! in_array( $review_action, array( 'approve', 'reject' ), true ) ) {
            return new WP_Error( 'invalid_action', __( 'Invalid review action.', 'hl-core' ) );
        }

        $now = current_time( 'mysql' );

        if ( $review_action === 'approve' ) {
            // Optimistic lock: WHERE includes status = 'ready_for_test'.
            $rows = $wpdb->update(
                $wpdb->prefix . 'hl_ticket',
                array(
                    'status'      => 'resolved',
                    'resolved_at' => $now,
                    'updated_at'  => $now,
                ),
                array(
                    'ticket_uuid' => $uuid,
                    'status'      => 'ready_for_test',
                )
            );
        } else {
            // Reject: validate comment.
            $comment = trim( $comment );
            if ( empty( $comment ) ) {
                return new WP_Error(
                    'comment_required',
                    __( 'Please describe what failed so the developer can investigate.', 'hl-core' )
                );
            }

            // Raw query to guarantee resolved_at = NULL (not empty string).
            $rows = $wpdb->query( $wpdb->prepare(
                "UPDATE `{$wpdb->prefix}hl_ticket`
                 SET status = %s, updated_at = %s, resolved_at = NULL
                 WHERE ticket_uuid = %s AND status = 'ready_for_test'",
                'test_failed',
                $now,
                $uuid
            ) );

            // Post rejection comment (add_comment sanitizes internally — do NOT double-sanitize).
            if ( $rows > 0 ) {
                $comment_result = $this->add_comment( $uuid, $comment );
                if ( is_wp_error( $comment_result ) ) {
                    error_log( '[HL_TICKET] Failed to add rejection comment for ticket ' . $uuid . ': ' . $comment_result->get_error_message() );
                }
            }
        }

        // Optimistic lock check.
        if ( $rows === 0 ) {
            return new WP_Error(
                'status_changed',
                __( 'This ticket was updated by someone else. Please refresh.', 'hl-core' )
            );
        }

        HL_Audit_Service::log( 'ticket_creator_review', array(
            'entity_type' => 'ticket',
            'entity_id'   => $ticket['ticket_id'],
            'before_data' => array( 'status' => 'ready_for_test' ),
            'after_data'  => array( 'status' => ( $review_action === 'approve' ) ? 'resolved' : 'test_failed', 'review_action' => $review_action ),
        ) );

        return $this->get_ticket( $uuid );
    }
```

- [ ] **Step 2: Commit**

```bash
git add includes/services/class-hl-ticket-service.php
git commit -m "feat(ticket): add creator_review_ticket() with optimistic locking"
```

---

### Task 4: Frontend PHP — AJAX Endpoint + UI Changes

**Files:**
- Modify: `includes/frontend/class-hl-frontend-feature-tracker.php:26-36` (AJAX registration)
- Modify: `includes/frontend/class-hl-frontend-feature-tracker.php:66-69` (data attribute)
- Modify: `includes/frontend/class-hl-frontend-feature-tracker.php:88-96` (filter dropdown)
- Modify: `includes/frontend/class-hl-frontend-feature-tracker.php:156-168` (admin dropdown)
- Add AJAX handler method after `ajax_ticket_status()` (~line 402)

- [ ] **Step 1: Register the new AJAX action**

In `class-hl-frontend-feature-tracker.php`, after line 33 (`add_action( 'wp_ajax_hl_ticket_status', ... );`), add:

```php
        add_action( 'wp_ajax_hl_ticket_creator_review', array( $this, 'ajax_ticket_creator_review' ) );
```

- [ ] **Step 2: Add `data-current-user-id` attribute to wrapper div**

In `class-hl-frontend-feature-tracker.php`, line 66-69, replace:
```php
        <div class="hlft-wrapper"
             data-nonce="<?php echo esc_attr( $nonce ); ?>"
             data-is-admin="<?php echo $is_admin ? '1' : '0'; ?>"
             data-user-department="<?php echo esc_attr( $current_user_dept ); ?>">
```
with:
```php
        <div class="hlft-wrapper"
             data-nonce="<?php echo esc_attr( $nonce ); ?>"
             data-is-admin="<?php echo $is_admin ? '1' : '0'; ?>"
             data-current-user-id="<?php echo esc_attr( get_current_user_id() ); ?>"
             data-user-department="<?php echo esc_attr( $current_user_dept ); ?>">
```

- [ ] **Step 3: Add new statuses to the filter dropdown**

In `class-hl-frontend-feature-tracker.php`, after line 92 (`<option value="in_progress">...`), add:

```php
                        <option value="ready_for_test"><?php esc_html_e( 'Ready for Review', 'hl-core' ); ?></option>
                        <option value="test_failed"><?php esc_html_e( 'Needs Revision', 'hl-core' ); ?></option>
```

- [ ] **Step 4: Add new statuses to the admin status dropdown**

In `class-hl-frontend-feature-tracker.php`, after line 162 (`<option value="in_progress">...`), add:

```php
                                    <option value="ready_for_test"><?php esc_html_e( 'Ready for Review', 'hl-core' ); ?></option>
                                    <option value="test_failed"><?php esc_html_e( 'Needs Revision', 'hl-core' ); ?></option>
```

- [ ] **Step 5: Add the AJAX handler method**

In `class-hl-frontend-feature-tracker.php`, after the `ajax_ticket_status()` method (after line 402), add:

```php

    public function ajax_ticket_creator_review() {
        $this->verify_ajax();

        $uuid          = sanitize_text_field( $_POST['ticket_uuid'] ?? '' );
        $review_action = sanitize_text_field( $_POST['review_action'] ?? '' );
        $comment       = isset( $_POST['comment'] ) ? wp_unslash( $_POST['comment'] ) : '';

        $result = HL_Ticket_Service::instance()->creator_review_ticket( $uuid, $review_action, $comment );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
        wp_send_json_success( $result );
    }
```

- [ ] **Step 6: Commit**

```bash
git add includes/frontend/class-hl-frontend-feature-tracker.php
git commit -m "feat(ticket): AJAX endpoint + UI dropdown options for QA workflow"
```

---

### Task 5: Frontend JS — Status Labels + Approve/Reject Handlers

**Files:**
- Modify: `assets/js/frontend.js:205` (statusLabels)
- Modify: `assets/js/frontend.js:175-176` (add currentUserId)
- Modify: `assets/js/frontend.js:337-341` (inject buttons in openDetail)
- Add event handlers after line 730 (after edit button handler)

- [ ] **Step 1: Update `statusLabels` map**

In `assets/js/frontend.js`, line 205, replace:
```javascript
            var statusLabels = { open: 'Open', in_review: 'In Review', in_progress: 'In Progress', resolved: 'Resolved', closed: 'Closed' };
```
with:
```javascript
            var statusLabels = { draft: 'Draft', open: 'Open', in_review: 'In Review', in_progress: 'In Progress', ready_for_test: 'Ready for Review', test_failed: 'Needs Revision', resolved: 'Resolved', closed: 'Closed' };
```

- [ ] **Step 2: Read `currentUserId` from data attribute**

In `assets/js/frontend.js`, after line 176 (`var isAdmin = ...;`), add:

```javascript
            var currentUserId = parseInt($wrap.data('current-user-id'), 10) || 0;
```

- [ ] **Step 3: Inject approve/reject buttons in `openDetail()`**

In `assets/js/frontend.js`, after line 341 (`}`), which closes the `if (t.can_edit)` block, add:

```javascript

                    // Creator review buttons (non-admin creator, ready_for_test only)
                    if (!isAdmin && parseInt(t.creator_user_id, 10) === currentUserId && t.status === 'ready_for_test') {
                        $actions.append(
                            '<div class="hlft-review-panel">' +
                                '<button type="button" class="hl-btn hl-btn-small hl-btn-success" id="hlft-approve-btn">Approve</button>' +
                                '<button type="button" class="hl-btn hl-btn-small hl-btn-danger" id="hlft-reject-btn">Reject</button>' +
                            '</div>' +
                            '<div class="hlft-reject-form" id="hlft-reject-form" style="display:none;">' +
                                '<textarea id="hlft-reject-comment" class="hlft-reject-textarea" rows="3" placeholder="Describe what\'s still not working..."></textarea>' +
                                '<button type="button" class="hl-btn hl-btn-small hl-btn-danger" id="hlft-reject-submit-btn">Submit</button>' +
                            '</div>'
                        );
                    }
```

- [ ] **Step 4: Add approve click handler (delegated)**

In `assets/js/frontend.js`, after line 730 (after the `#hlft-edit-btn` handler), add:

```javascript

            // Creator approve (delegated — buttons injected dynamically)
            $(document).on('click', '#hlft-approve-btn', function() {
                var $btn = $(this);
                $btn.prop('disabled', true).text('Approving...');
                $('#hlft-reject-btn').prop('disabled', true);

                ajax('hl_ticket_creator_review', {
                    ticket_uuid: currentUuid,
                    review_action: 'approve'
                }, function(t) {
                    showToast('Ticket approved');
                    openDetail(currentUuid);
                    loadTickets();
                }, function() {
                    $btn.prop('disabled', false).text('Approve');
                    $('#hlft-reject-btn').prop('disabled', false);
                    openDetail(currentUuid);
                });
            });

            // Creator reject — show textarea
            $(document).on('click', '#hlft-reject-btn', function() {
                $('#hlft-reject-form').slideDown(200);
                $('#hlft-reject-comment').focus();
            });

            // Creator reject — submit
            $(document).on('click', '#hlft-reject-submit-btn', function() {
                var comment = $.trim($('#hlft-reject-comment').val());
                if (!comment) {
                    showToast('Please describe what failed.', true);
                    return;
                }

                var $btn = $(this);
                $btn.prop('disabled', true).text('Submitting...');
                $('#hlft-approve-btn').prop('disabled', true);
                $('#hlft-reject-btn').prop('disabled', true);

                ajax('hl_ticket_creator_review', {
                    ticket_uuid: currentUuid,
                    review_action: 'reject',
                    comment: comment
                }, function(t) {
                    showToast('Ticket marked as needs revision');
                    openDetail(currentUuid);
                    loadTickets();
                }, function() {
                    $btn.prop('disabled', false).text('Submit');
                    $('#hlft-approve-btn').prop('disabled', false);
                    $('#hlft-reject-btn').prop('disabled', false);
                    openDetail(currentUuid);
                });
            });
```

- [ ] **Step 5: Commit**

```bash
git add assets/js/frontend.js
git commit -m "feat(ticket): approve/reject UI with event delegation + loading states"
```

---

### Task 6: CSS — Status Pill Colors + Review Panel Styles

**Files:**
- Modify: `assets/css/frontend.css` (after `.hlft-status-pill--closed` block, ~line 10371)

- [ ] **Step 1: Add pill colors for new statuses**

In `assets/css/frontend.css`, after the `.hlft-status-pill--draft` block (~line 10371), add:

```css
.hlft-status-pill--ready_for_test { background: #0d9488; color: #ffffff; }
.hlft-status-pill--test_failed { background: #ea580c; color: #ffffff; }
```

- [ ] **Step 2: Add review panel styles**

In `assets/css/frontend.css`, after the new pill styles, add:

```css

/* QA Review Panel */
.hlft-review-panel {
    display: flex;
    gap: 8px;
    margin-top: 12px;
}
.hlft-reject-form {
    margin-top: 8px;
}
.hlft-reject-textarea {
    width: 100%;
    padding: 10px;
    border: 1px solid var(--hl-border);
    border-radius: var(--hl-radius-xs);
    font-family: inherit;
    font-size: 14px;
    resize: vertical;
    margin-bottom: 8px;
}
.hlft-reject-textarea:focus {
    outline: none;
    border-color: var(--hl-interactive);
    box-shadow: 0 0 0 3px rgba(var(--hl-interactive-rgb, 99,102,241), 0.15);
}
```

- [ ] **Step 3: Commit**

```bash
git add assets/css/frontend.css
git commit -m "feat(ticket): pill colors + review panel styles for QA workflow"
```

---

### Task 7: GitHub Sync Removal — Code + Docs

**Files:**
- Delete: `includes/cli/class-hl-cli-sync-tickets.php`
- Modify: `hl-core.php:265` (remove require)
- Modify: `hl-core.php:387` (remove register)
- Delete: `docs/superpowers/specs/2026-04-13-ticket-github-sync-design.md`
- Delete: `docs/superpowers/plans/2026-04-13-ticket-github-sync.md`

- [ ] **Step 1: Remove the CLI class require**

In `hl-core.php`, delete line 265:
```php
            require_once HL_CORE_INCLUDES_DIR . 'cli/class-hl-cli-sync-tickets.php';
```

- [ ] **Step 2: Remove the CLI register call**

In `hl-core.php`, delete line 387:
```php
            HL_CLI_Sync_Tickets::register();
```

- [ ] **Step 3: Delete the sync class file**

```bash
rm includes/cli/class-hl-cli-sync-tickets.php
```

- [ ] **Step 4: Delete the sync spec and plan docs**

```bash
rm docs/superpowers/specs/2026-04-13-ticket-github-sync-design.md
rm docs/superpowers/plans/2026-04-13-ticket-github-sync.md
```

- [ ] **Step 5: Commit**

```bash
git add -A hl-core.php includes/cli/class-hl-cli-sync-tickets.php docs/superpowers/specs/2026-04-13-ticket-github-sync-design.md docs/superpowers/plans/2026-04-13-ticket-github-sync.md
git commit -m "chore(ticket): remove GitHub Issues sync — code, CLI, docs"
```

---

### Task 8: GitHub Issues Cleanup

This task runs `gh` CLI commands locally to close and delete all synced issues on `Corsox-Tech/hl-core`.

- [ ] **Step 1: List all issues**

```bash
gh issue list --repo Corsox-Tech/hl-core --state all --json number,title,state --limit 100
```

Review the output — all issues created by the sync (their body contains "Ticket ID: #N") should be closed and deleted.

- [ ] **Step 2: Close and delete each synced issue**

For each issue number from step 1:
```bash
gh issue close <number> --repo Corsox-Tech/hl-core
gh issue delete <number> --repo Corsox-Tech/hl-core --yes
```

- [ ] **Step 3: Verify no issues remain**

```bash
gh issue list --repo Corsox-Tech/hl-core --state all --json number,title
```

Expected: empty list (or only non-sync issues if any exist).

---

### Task 9: Update Reference Docs — README.md + STATUS.md

**Files:**
- Modify: `README.md` (~line 227, remove sync CLI docs, add QA workflow)
- Modify: `STATUS.md` (~line 317-319, update GitHub sync section)

- [ ] **Step 1: Remove sync CLI docs from README.md**

In `README.md`, find and delete the line referencing `sync-tickets-to-github`:
```
- **`wp hl-core sync-tickets-to-github [--dry-run]`** — One-way sync from Feature Tracker...
```

- [ ] **Step 2: Update STATUS.md — mark GitHub sync as removed**

In `STATUS.md`, find the GitHub sync section (~line 317-319) and update it to indicate removal:
```markdown
> **REMOVED (2026-04-15)** — GitHub Issues sync removed. Feature Tracker is the sole source of truth.
- [x] ~~**Schema rev 38** — `github_issue_id` column on `hl_ticket`.~~ (dropped in rev 41)
- [x] ~~**CLI command** — `wp hl-core sync-tickets-to-github`.~~ (deleted)
```

- [ ] **Step 3: Add QA workflow to STATUS.md build queue**

Add a new section to STATUS.md build queue:
```markdown
### Ticket QA Workflow (rev 41)
> **Spec:** `docs/superpowers/specs/2026-04-15-ticket-qa-workflow-design.md`
- [x] **Schema rev 41** — `ready_for_test` + `test_failed` enum values. Drop `github_issue_id`.
- [x] **Service method** — `creator_review_ticket()` with optimistic locking.
- [x] **AJAX endpoint** — `hl_ticket_creator_review` for approve/reject.
- [x] **Frontend UI** — Approve/Reject buttons, status pills, filter options.
- [x] **GitHub sync removal** — CLI class, docs, GitHub Issues deleted.
```

- [ ] **Step 4: Commit**

```bash
git add README.md STATUS.md
git commit -m "docs: update README + STATUS for QA workflow + GitHub sync removal"
```

---

### Task 10: Memory + Architecture Cleanup

**Files:**
- Delete: `memory/reference_github_ticket_sync.md` (Claude memory)
- Modify: `memory/project_feature_tracker_2026_04.md` (Claude memory)
- Modify: `memory/MEMORY.md` (Claude memory index)
- Modify: `.claude/skills/architecture.md` (remove sync-tickets reference)

- [ ] **Step 1: Delete GitHub sync reference memory**

Delete the file at the memory path:
`C:\Users\MateoGonzalez\.claude\projects\C--Users-MateoGonzalez-Dev-Projects-Mateo-housman-learning-academy-app-public-wp-content-plugins-hl-core\memory\reference_github_ticket_sync.md`

- [ ] **Step 2: Rewrite Feature Tracker project memory**

Rewrite `project_feature_tracker_2026_04.md` to remove all GitHub sync references. Replace with:

```markdown
---
name: Feature Tracker — QA workflow
description: Internal ticket system with QA gate. No GitHub sync. "Tickets" = Feature Tracker.
type: project
---
Feature Tracker is live on production. No external sync — the tracker is the sole source of truth.

**Why:** Coaches/admins submit tickets on the WordPress site. Admin (Mateo) resolves them. QA workflow added 2026-04-15: ready_for_test → creator approves or rejects.

**How to apply:**
- When user says "tickets", "issues", or "feature tracker" they mean this system
- Key files: `HL_Ticket_Service` (service), `HL_Frontend_Feature_Tracker` (frontend)
- 3 DB tables: `hl_ticket`, `hl_ticket_comment`, `hl_ticket_attachment`
- QA statuses: `ready_for_test` (UI: "Ready for Review"), `test_failed` (UI: "Needs Revision")
- Creator can approve/reject from `ready_for_test` only. Rejection requires comment.
```

- [ ] **Step 3: Update MEMORY.md index**

Remove the line:
```
- [reference_github_ticket_sync.md](reference_github_ticket_sync.md) — How to sync new tickets from prod to GitHub Issues.
```

Update the Feature Tracker line to:
```
- [project_feature_tracker_2026_04.md](project_feature_tracker_2026_04.md) — Feature Tracker with QA workflow. No GitHub sync.
```

- [ ] **Step 4: Remove sync-tickets from architecture.md**

In `.claude/skills/architecture.md`, find and remove the `sync-tickets` reference from the CLI command listing.

- [ ] **Step 5: Commit code changes only (memory files are outside repo)**

```bash
git add .claude/skills/architecture.md
git commit -m "docs: remove sync-tickets from architecture reference"
```

---

### Task 11: Smoke Test on Test Server

Deploy to the test server and verify the full workflow.

- [ ] **Step 1: Deploy to test server**

Follow the standard SSH deploy process (see `.claude/skills/deploy.md`).

- [ ] **Step 2: Verify migration ran**

SSH into the test server and check:
```bash
wp db query "SHOW COLUMNS FROM wp_hl_ticket LIKE 'status'"
```
Expected: enum includes `ready_for_test` and `test_failed`.

```bash
wp db query "SHOW COLUMNS FROM wp_hl_ticket LIKE 'github_issue_id'"
```
Expected: empty result (column dropped).

- [ ] **Step 3: Verify schema revision**

```bash
wp option get hl_core_schema_revision
```
Expected: `41`

- [ ] **Step 4: Test the QA workflow in browser**

Using Playwright or manual browser testing:

1. As admin: create a ticket, move it to `in_progress`, then `ready_for_test` via dropdown
2. As a non-admin creator: open the ticket detail, verify Approve and Reject buttons appear
3. Click "Reject" — verify textarea appears, submit with a comment
4. Verify ticket status changes to "Needs Revision" in the list
5. As admin: move ticket back to `ready_for_test`
6. As creator: click "Approve" — verify ticket moves to "Resolved"
7. Verify filter dropdown shows "Ready for Review" and "Needs Revision" options
8. Verify status pills render with correct colors

- [ ] **Step 5: Verify GitHub sync CLI is gone**

```bash
wp hl-core sync-tickets-to-github 2>&1
```
Expected: error — command not found.
