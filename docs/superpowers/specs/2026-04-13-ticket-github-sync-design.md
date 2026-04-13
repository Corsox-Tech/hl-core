# Feature Tracker → GitHub Issues Sync

**Date:** 2026-04-13
**Status:** Approved

## 1. Purpose

One-way sync from the Feature Tracker (`hl_ticket`) to GitHub Issues on `Corsox-Tech/hl-core`. Coaches and admins submit tickets on the WordPress site; this CLI command mirrors them to GitHub so developers can retrieve, triage, and link them to PRs/commits. Resolved/closed tickets auto-close their GitHub Issues.

## 2. Schema Change

Schema revision bump (current +1). Single new nullable column on `hl_ticket`:

```sql
ALTER TABLE {prefix}hl_ticket
  ADD COLUMN github_issue_id bigint(20) unsigned NULL DEFAULT NULL
  AFTER context_user_id;
```

No index — queries are low-volume (one CLI run at a time). The column is also added to the `CREATE TABLE` body in `get_schema()` for fresh installs.

## 3. Sync Logic

The command performs three operations in order on each run:

### 3.1 Create

Query all non-draft tickets where `github_issue_id IS NULL`:

```sql
SELECT * FROM {prefix}hl_ticket
WHERE status != 'draft' AND github_issue_id IS NULL
ORDER BY created_at ASC
```

For each ticket:
1. Build the issue body (see §4) and label set (see §5).
2. Create a GitHub Issue via `gh issue create --repo Corsox-Tech/hl-core --title "..." --body "..." --label "..."`.
3. Parse the returned issue number.
4. `UPDATE {prefix}hl_ticket SET github_issue_id = %d WHERE ticket_id = %d`.

### 3.2 Close

Query synced tickets that are now resolved or closed:

```sql
SELECT ticket_id, github_issue_id FROM {prefix}hl_ticket
WHERE github_issue_id IS NOT NULL AND status IN ('resolved', 'closed')
```

For each, run `gh issue close <number> --repo Corsox-Tech/hl-core`. Silently skip if already closed.

### 3.3 Reopen

Query synced tickets that are active but may have been closed on GitHub (e.g., a ticket was resolved then reopened):

```sql
SELECT ticket_id, github_issue_id FROM {prefix}hl_ticket
WHERE github_issue_id IS NOT NULL AND status NOT IN ('resolved', 'closed', 'draft')
```

For each, check if the GitHub Issue is closed via `gh issue view <number> --repo Corsox-Tech/hl-core --json state`. If closed, run `gh issue reopen <number> --repo Corsox-Tech/hl-core`.

## 4. Issue Body Format

```markdown
> **Type:** Bug | **Priority:** High | **Category:** Platform Issue
> **Submitted by:** Jane Smith — 2026-04-10
> **Ticket ID:** #42 (abc12345-...)

---

[ticket description text]
```

- Creator name resolved via `get_userdata($creator_user_id)->display_name`.
- Date formatted as `Y-m-d` from `created_at`.
- UUID included for cross-reference.

## 5. Label Mapping

Labels are created automatically by `gh` if they don't exist on the repo.

### Type
| Ticket type | GitHub label |
|---|---|
| `bug` | `bug` |
| `improvement` | `enhancement` |
| `feature_request` | `feature-request` |

### Priority
| Ticket priority | GitHub label |
|---|---|
| `low` | `priority:low` |
| `medium` | `priority:medium` |
| `high` | `priority:high` |
| `critical` | `priority:critical` |

### Category
| Ticket category | GitHub label |
|---|---|
| `course_content` | `cat:course-content` |
| `platform_issue` | `cat:platform-issue` |
| `account_access` | `cat:account-access` |
| `forms_assessments` | `cat:forms-assessments` |
| `reports_data` | `cat:reports-data` |
| `other` | `cat:other` |

## 6. CLI Interface

```
wp hl-core sync-tickets-to-github [--dry-run]
```

- **No flags:** Creates new issues, closes resolved, reopens un-resolved. Prints a summary line.
- **`--dry-run`:** Prints what would happen without making any GitHub API calls or DB writes.

### Output format

```
[DRY RUN]
CREATE: #42 "Login button broken on mobile" → bug, priority:high, cat:platform-issue
CREATE: #45 "Add CSV export to reports" → feature-request, priority:medium, cat:reports-data
CLOSE:  #38 (GitHub #12) — resolved
REOPEN: #40 (GitHub #10) — reopened to in_progress
---
Summary: 2 created, 1 closed, 1 reopened, 0 errors
```

### Error output

```
ERROR:  #42 "Login button broken on mobile" — gh: authentication required (run `gh auth login`)
```

## 7. Error Handling

- **`gh` not installed:** Hard error at start — `WP_CLI::error('gh CLI not found. Install: https://cli.github.com')`.
- **`gh` not authenticated:** Hard error at start — runs `gh auth status` and checks exit code.
- **Single issue failure:** Log error, continue with remaining tickets. Report failure count in summary.
- **No partial state:** `github_issue_id` is written only after confirmed GitHub Issue creation. A failed create leaves the ticket unsynced for the next run.

## 8. File Changes

1. **`class-hl-installer.php`** — Schema rev bump. `ALTER TABLE` migration for `github_issue_id`. Column added to `CREATE TABLE` body.
2. **`includes/cli/class-hl-cli-sync-tickets.php`** — New file. The CLI command class.
3. **`hl-core.php`** — Register the CLI command in the existing WP_CLI block.

## 9. Non-Goals

- No two-way sync (GitHub → Feature Tracker).
- No comment sync — only the ticket title, description, and metadata.
- No attachment sync — images stay on WordPress.
- No cron/scheduled runs — manual CLI only.
- No label color management — `gh` picks defaults for new labels.
