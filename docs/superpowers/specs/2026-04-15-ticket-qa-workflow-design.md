# Ticket QA Workflow + GitHub Sync Removal

**Date:** 2026-04-15
**Branch:** `feature/workflow-ux-m1`
**Status:** Design approved

## Summary

Add a QA testing gate to the Feature Tracker ticket lifecycle. Two new statuses (`ready_for_test`, `test_failed`) give ticket creators the ability to approve or reject fixes — turning the ticket system from a one-way submission tool into a closed-loop feedback system. Simultaneously, remove the GitHub Issues sync feature (code, schema, docs, and existing GitHub issues) as it added maintenance overhead with no practical payoff.

## Motivation

- **No feedback loop today.** Admin resolves a ticket, but the creator has no way to confirm the fix actually works or report that it doesn't. If a deployed fix fails in production, there's no mechanism to bounce it back.
- **Priority signal for failures.** A `test_failed` ticket represents something deployed to production that's broken — higher priority than a regular `in_progress` item. A dedicated status makes these immediately visible in the ticket list.
- **GitHub sync is overkill.** The Feature Tracker is the source of truth. Mirroring tickets to GitHub Issues added a manual sync step (gh CLI not on prod) and a schema column (`github_issue_id`) for a feature that provided no value beyond what the tracker itself offers.

---

## Section 1: Data Layer

### 1.1 DB Enum Expansion

Add two values to the `status` enum on `hl_ticket`:

```sql
ALTER TABLE {prefix}hl_ticket
  MODIFY COLUMN status enum('draft','open','in_review','in_progress','ready_for_test','test_failed','resolved','closed')
  NOT NULL DEFAULT 'open';
```

The `CREATE TABLE` body in `HL_Installer` is updated to match.

### 1.2 Drop `github_issue_id` Column

```sql
ALTER TABLE {prefix}hl_ticket DROP COLUMN github_issue_id;
```

The `CREATE TABLE` body removes the column. The rev 38 migration function (`migrate_ticket_add_github_issue_id`) is deleted.

### 1.3 PHP Constants

In `HL_Ticket_Service`:

```php
const VALID_STATUSES = array(
    'draft', 'open', 'in_review', 'in_progress',
    'ready_for_test', 'test_failed',
    'resolved', 'closed',
);

const TERMINAL_STATUSES = array( 'resolved', 'closed' );
// ready_for_test and test_failed are NOT terminal.
// ready_for_test allows creator approve/reject actions.
// test_failed allows creator commenting + admin status changes.
```

### 1.4 Schema Revision

Single new migration at the next schema rev. Combines the enum expansion and column drop into one revision. The migration:

1. Expands the `status` enum (adds `ready_for_test`, `test_failed`).
2. Drops the `github_issue_id` column (if it exists — guard with `SHOW COLUMNS`).

---

## Section 2: Backend Logic

### 2.1 New Method: `creator_review_ticket( $uuid, $action, $comment )`

A new public method on `HL_Ticket_Service`, separate from the admin-only `change_status()`.

**Parameters:**
- `$uuid` — ticket UUID
- `$action` — `'approve'` or `'reject'`
- `$comment` — required when `$action === 'reject'`; ignored on approve

**Logic:**

1. **Auth check:** Verify the current user is the ticket's `creator_user_id`. Not an admin check — specifically the person who submitted the ticket. Return `WP_Error('forbidden')` if not the creator.
2. **Status gate:** Verify the ticket's current status is `ready_for_test`. Return `WP_Error('invalid_action')` if not. This is the ONLY status from which creators can act.
3. **Action: approve**
   - Set status → `resolved`
   - Set `resolved_at` → `current_time('mysql')`
4. **Action: reject**
   - Validate `$comment` is non-empty (trimmed). Return `WP_Error('comment_required')` if empty.
   - Set status → `test_failed`
   - Auto-post `$comment` as a ticket comment via `add_comment()`, attributed to the current user. The comment functions as a normal comment visible in the thread — no special "system" flag needed.
5. **Audit log:** Log `ticket_creator_review` with `entity_type: ticket`, `entity_id`, `before_data: { status: 'ready_for_test' }`, `after_data: { status: <new>, action: <action> }`.
6. **Return:** The updated ticket array (same as `change_status()` return format).

### 2.2 New AJAX Endpoint

New AJAX action `hl_ticket_creator_review` registered in `HL_Frontend_Feature_Tracker`:

```php
public function ajax_ticket_creator_review() {
    $this->verify_ajax();
    $uuid    = sanitize_text_field( $_POST['ticket_uuid'] );
    $action  = sanitize_text_field( $_POST['action'] );      // 'approve' or 'reject'
    $comment = isset( $_POST['comment'] ) ? sanitize_textarea_field( $_POST['comment'] ) : '';

    $result = HL_Ticket_Service::instance()->creator_review_ticket( $uuid, $action, $comment );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( $result->get_error_message() );
    }
    wp_send_json_success( $result );
}
```

### 2.3 Admin `change_status()` — No Changes

Admin retains full status control. The existing `change_status()` method already validates against `VALID_STATUSES`, so adding the two new values to that array is sufficient. No new transition restrictions for admin.

---

## Section 3: Frontend UI

### 3.1 Creator Approve/Reject Buttons

When ALL of these conditions are true:
- Current user is NOT admin (admin uses the dropdown instead)
- Current user IS the ticket creator (`ticket.creator_user_id === current_user_id`)
- Ticket status is `ready_for_test`

> **Edge case:** If admin is also the ticket creator, they use the admin dropdown — no approve/reject buttons shown. This avoids redundant UI.

...render an action panel in the `#hlft-detail-actions` area:

**Approve button:** Green styled button labeled "Approve". On click:
- AJAX call to `hl_ticket_creator_review` with `action: 'approve'`
- On success: update status pill to `resolved`, show toast "Ticket approved", refresh table

**Test Failed button:** Red/orange styled button labeled "Test Failed". On click:
- Expand an inline textarea below the button (placeholder: "Describe what failed...")
- Show a "Submit" button next to the textarea
- On submit: validate textarea is non-empty (client-side), AJAX call with `action: 'reject'` and `comment`
- On success: update status pill to `test_failed`, append the comment to the comment list, show toast "Ticket marked as test failed", refresh table

**No modal.** Inline expansion keeps the interaction lightweight.

### 3.2 Admin Status Dropdown

Add two new `<option>` elements to the existing `#hlft-status-select`:

```html
<option value="ready_for_test">Ready for Test</option>
<option value="test_failed">Test Failed</option>
```

Inserted between `In Progress` and `Resolved` in the dropdown order.

### 3.3 Status Filter Dropdown

Add both new statuses to `#hlft-filter-status`:

```html
<option value="ready_for_test">Ready for Test</option>
<option value="test_failed">Test Failed</option>
```

The default filter (empty value = "Open") behavior: the backend `get_tickets()` currently excludes `closed` by default. It should also show `ready_for_test` and `test_failed` tickets in the default view (they're active tickets that need attention).

### 3.4 JS Status Labels

```javascript
var statusLabels = {
    open: 'Open',
    in_review: 'In Review',
    in_progress: 'In Progress',
    ready_for_test: 'Ready for Test',
    test_failed: 'Test Failed',
    resolved: 'Resolved',
    closed: 'Closed'
};
```

### 3.5 Status Pill CSS

Two new pill variants:

- `.hlft-status-pill--ready_for_test` — teal/blue background. Signals "awaiting action from the creator."
- `.hlft-status-pill--test_failed` — orange/red background. Signals "failed, needs developer attention."

### 3.6 Creator User ID in Frontend

The JS needs to know if the current user is the ticket creator. The ticket detail AJAX response already includes `creator_user_id`. The current user's ID can be passed via `wp_localize_script()` (e.g., `hlftData.currentUserId`). Compare the two client-side to decide whether to show approve/reject buttons.

---

## Section 4: GitHub Sync Removal

### 4.1 Code Deletion

| Action | File |
|--------|------|
| DELETE | `includes/cli/class-hl-cli-sync-tickets.php` |
| REMOVE | `hl-core.php` line 265: `require_once` for sync-tickets |
| REMOVE | `hl-core.php` line 387: `HL_CLI_Sync_Tickets::register()` |
| REMOVE | `includes/class-hl-installer.php`: `github_issue_id` from CREATE TABLE body |
| REMOVE | `includes/class-hl-installer.php`: rev 38 migration case + `migrate_ticket_add_github_issue_id()` method |

### 4.2 Documentation Deletion

| Action | File |
|--------|------|
| DELETE | `docs/superpowers/specs/2026-04-13-ticket-github-sync-design.md` |
| DELETE | `docs/superpowers/plans/2026-04-13-ticket-github-sync.md` |
| UPDATE | `README.md` — remove `sync-tickets-to-github` CLI command entry |
| UPDATE | `STATUS.md` — mark GitHub sync section as removed/superseded |

### 4.3 Memory Cleanup

| Action | File |
|--------|------|
| DELETE | `memory/reference_github_ticket_sync.md` |
| REWRITE | `memory/project_feature_tracker_2026_04.md` — remove all GitHub sync references |
| UPDATE | `memory/MEMORY.md` — remove sync index entry |

### 4.4 GitHub Issues Cleanup

Close and delete ALL issues on `Corsox-Tech/hl-core` that were created by the ticket sync. These can be identified by their body format (contains "Ticket ID: #N") or by listing all open issues. Run locally via `gh` CLI:

```bash
# List all open issues
gh issue list --repo Corsox-Tech/hl-core --state all --json number,title

# Close + delete each synced issue
gh issue close <number> --repo Corsox-Tech/hl-core
gh issue delete <number> --repo Corsox-Tech/hl-core --yes
```

---

## Lifecycle Diagram

```
                    ┌─────────────────────────────────────────────────┐
                    │                                                 │
  create_ticket()   │   ADMIN transitions (change_status)             │
       │            │   ═══════════════════════════════                │
       ▼            │                                                 │
     draft ──publish──► open ──► in_review ──► in_progress ──┐        │
                    │                                        │        │
                    │                                        ▼        │
                    │                                  ready_for_test  │
                    │                                   │         │    │
                    │                     CREATOR       │         │    │
                    │                    ════════        │         │    │
                    │                                   │         │    │
                    │                          approve   │   reject│   │
                    │                          (no comment)  (comment  │
                    │                                   │   required)  │
                    │                                   ▼         ▼    │
                    │                              resolved  test_failed
                    │                                   │         │    │
                    │                                   ▼         │    │
                    │                                closed        │    │
                    │                                              │    │
                    │           admin fixes, moves back ───────────┘    │
                    └─────────────────────────────────────────────────┘

  CREATOR actions:   ONLY from ready_for_test → approve OR reject
  ADMIN actions:     Any status → any status (except → draft)
```

---

## Files Changed (Implementation Scope)

| File | Change |
|------|--------|
| `includes/class-hl-installer.php` | New migration: expand enum, drop `github_issue_id`. Update CREATE TABLE. |
| `includes/services/class-hl-ticket-service.php` | Update constants. Add `creator_review_ticket()`. |
| `includes/frontend/class-hl-frontend-feature-tracker.php` | Add AJAX endpoint. Add approve/reject UI. Add new status options. Pass `currentUserId` via localize. |
| `assets/js/frontend.js` | Add `statusLabels` entries. Add approve/reject click handlers. Add inline reject textarea logic. |
| `assets/css/frontend.css` | Add pill colors for `ready_for_test` and `test_failed`. Style approve/reject buttons. |
| `hl-core.php` | Remove sync-tickets require + register. |
| `includes/cli/class-hl-cli-sync-tickets.php` | DELETE entire file. |
| `docs/superpowers/specs/2026-04-13-ticket-github-sync-design.md` | DELETE. |
| `docs/superpowers/plans/2026-04-13-ticket-github-sync.md` | DELETE. |
| `README.md` | Remove sync CLI docs. Add QA workflow docs. |
| `STATUS.md` | Update build queue. |
