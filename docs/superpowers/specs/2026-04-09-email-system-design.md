# Email System Design Spec

**Date:** 2026-04-09
**Status:** Draft
**Scope:** HL Core email system — automation engine, block-based builder, manual sends

---

## 1. Overview & Goals

HL Core email system with three subsystems:

1. **Email Automation Engine** — trigger-based workflows that send emails automatically when events occur or on scheduled cron intervals.
2. **Email Builder** — block-based visual editor for non-technical users to create and customize email templates.
3. **Manual Sends** — one-off emails within cycles, with recipient filtering and scheduling.

All three subsystems share a common foundation: queue table, rate limits, block renderer, and merge tag registry. This replaces existing hardcoded email features and supports 25+ email scenarios from client requirements.

### Goals

- **Self-service email creation** — admins build and modify templates without developer involvement.
- **Automated trigger-based sending** — emails fire on WordPress hooks or cron schedules with condition evaluation.
- **Cross-client compatibility** — renders correctly in Gmail, Outlook, Yahoo, and Apple Mail.
- **Spam prevention guardrails** — Email Health panel warns about spam trigger words, ALL CAPS, excessive punctuation, and SVG uploads.
- **Rate limiting for safety** — per-user hourly/daily/weekly limits prevent accidental floods.
- **Audit trail for all sends** — every email logged with status, attempts, and context snapshot.

---

## 2. Architecture

### Three Subsystems, Shared Foundation

```
+-----------------------------------------------+
|              Shared Foundation                  |
|  hl_email_queue | hl_email_rate_limit           |
|  HL_Email_Block_Renderer                        |
|  HL_Email_Merge_Tag_Registry                    |
+-----------------------------------------------+
        |                |                |
   Automation        Builder        Manual Sends
   Engine            (Phase 3)       (Phase 5)
   (Phase 2)
```

- **Email Automation Engine** (build first): triggers -> conditions -> recipients -> queue -> send
- **Email Builder** (build second): block-based editor, Sortable.js, no build step
- **Manual Sends** (build third): cycle-scoped, filter + checkboxes, Send Now or Schedule

### Shared Components

| Component | Description |
|-----------|-------------|
| `hl_email_queue` | Central queue table for all outbound emails |
| `hl_email_rate_limit` | Per-user send rate tracking |
| `HL_Email_Block_Renderer` | Converts block JSON to table-based HTML emails |
| `HL_Email_Merge_Tag_Registry` | Registry of all available merge tags with resolvers |

### Admin Menu

New **"Emails"** submenu under Housman LMS with four tabs:

| Tab | Purpose |
|-----|---------|
| Automated Workflows | Create and manage trigger-based email workflows |
| Email Templates | Build and preview email templates |
| Send Log | View all queued/sent/failed emails with actions |
| Settings | Rate limits, queue health, migration tools |

Manual sends live inside each **Cycle -> Emails** tab (not in the global Emails menu).

### End-to-End Flow

```
Event (hook or cron)
  -> HL_Email_Automation_Service::handle_trigger()
  -> Load active workflows matching trigger_key
  -> Evaluate conditions (JSON) against context
  -> Resolve recipients (tokens)
  -> Render template with merge tags via HL_Email_Block_Renderer
  -> Check rate limits
  -> Insert into hl_email_queue (body_html rendered at insertion time, NOT send time)
  -> Queue processor (5-min cron) picks up pending rows, calls wp_mail()
```

**Critical design decision:** `body_html` is rendered and stored at queue-insertion time, not at send time. This ensures the email content reflects the state of data when the event occurred, not when the cron happens to run.

**Exception — deferred tags:** Time-sensitive tokens like `{{password_reset_url}}` are stored as literal placeholders in `body_html` at insertion time. The queue processor resolves them immediately before calling `wp_mail()` to ensure freshness (e.g., a reset key generated seconds before delivery, not hours earlier). **Deferred tag resolution uses the `recipient_user_id` column directly from the queue row -- NOT from `context_data` JSON. This is a hard contract.** See Section 8 for the deferred tag designation and Section 6 for `context_data` requirements.

---

## 3. Email Builder

### Layout

Two-panel editor with optional right sidebar:

```
+------------------+----------------------------+------------------+
|  Block Palette   |     Canvas (600px)         |  Email Health    |
|  + Block         |                            |  + Merge Tags    |
|    Settings      |  [branded header]          |                  |
|                  |  [block 1]                 |  Traffic Light:  |
|  - text          |  [block 2]                 |  green/yellow/   |
|  - image         |  [block 3]                 |  red             |
|  - button        |  [branded footer]          |                  |
|  - divider       |                            |  Warnings list   |
|  - spacer        |                            |                  |
|  - columns       |                            |                  |
+------------------+----------------------------+------------------+
```

### Block Types (6)

| Block | Description | Options |
|-------|-------------|---------|
| **text** | Contenteditable div with mini-toolbar | Bold, italic, link, merge tag dropdown. Alignment and font size deferred to v2. |
| **image** | WP Media Library picker | Alt text, width, optional link. SVGs blocked. |
| **button** | CTA button with VML fallback for Outlook | Label, URL (free text or WP links), bg color, text color |
| **divider** | Horizontal rule | Color, thickness (1-4px) |
| **spacer** | Vertical space | Height slider (8-80px) |
| **columns** | Two-column layout | 50/50 or 60/40 split, each column holds sub-blocks |

#### Button URL Options

Buttons support free-text URLs plus these WordPress link tags:

- `{{password_reset_url}}` — **deferred tag**: stored as literal in `body_html`, resolved by the queue processor at send time via `get_password_reset_key()`. Expires in 24 hours.
- `{{login_url}}` — `wp_login_url()`
- `{{dashboard_url}}` — page with `[hl_dashboard]` shortcode
- `{{program_page_url}}` — page with `[hl_program_page]` + `?enrollment_id=X`

### Tech Stack

- **Sortable.js** (CDN, no build step) for drag/drop block reordering
- Block data stored as JSON array in `hl_email_template.blocks_json`
- Rendered to table-based HTML server-side by `HL_Email_Block_Renderer`

### Autosave

- Debounced 3-second AJAX save to `wp_options` draft key: `hl_email_draft_{user_id}_{template_id}`. Uses `update_option(..., false)` so `autoload=no` (prevents loading all drafts on every page load).
- `localStorage` backup as fallback
- "Restore unsaved changes?" banner on page load if draft exists
- **Cleanup:** On template deletion, delete associated draft options via `$wpdb->query($wpdb->prepare('DELETE FROM {$wpdb->options} WHERE option_name LIKE %s', 'hl_email_draft_%_' . $template_id))`. Daily cron (`hl_email_cron_daily`) deletes drafts older than 30 days. **Performance note:** The `LIKE` scan on `wp_options` is a full table scan. Acceptable at this site's scale (~100 users, few admins editing templates). If `wp_options` grows significantly, consider moving draft storage to a dedicated `hl_email_draft` table in a future version.

### Preview As

1. Admin types a name in search field
2. AJAX returns matching enrollments: "Akia Davis -- ELCPB Cycle 2"
3. AJAX preview handler returns a nonce-protected preview URL (not raw HTML). Iframe `src` pointed at this endpoint, which returns the fully rendered HTML document.
4. Toggle buttons: **Desktop** (600px) / **Mobile** (375px) / **Dark mode**

### Email Health Panel (Spam Prevention)

Real-time checks as the admin edits:

| Check | Severity | Rule |
|-------|----------|------|
| SVG upload attempted | **Error** (blocks save) | SVG files are not allowed in emails |
| Spam trigger words in subject | Warning | Wordlist: free, urgent, act now, limited, winner, congratulations, etc. |
| ALL CAPS words > 2 | Warning | More than two fully capitalized words |
| Exclamation marks > 1 | Warning | More than one exclamation mark in subject |
| Subject length > 70 chars | Warning | Subject too long for mobile preview |
| Subject length < 15 chars | Warning | Subject too short, may look like spam |
| Low text-to-image ratio | Warning | Email is mostly images with little text |

**Traffic light indicator:** Green (no issues), Yellow (warnings present), Red (errors present). Warnings do not block saving or sending. Errors do.

---

## 4. Automation Engine

### Trigger Types

#### Hook-Based (Immediate)

These fire when a WordPress action hook runs:

| Hook | Email Scenario |
|------|---------------|
| `user_register` | Invitation |
| `hl_enrollment_created` | Invitation or enrollment notification |
| `hl_pathway_assigned` | Pre-assessment reminder |
| `hl_learndash_course_completed` | Course completion notification |
| `hl_pathway_completed` | Pathway completion notification |
| `hl_coaching_session_created` | Session confirmation |
| `hl_coaching_session_status_changed` | Attended/missed/cancelled/rescheduled |
| `hl_rp_session_created` | Reflective Practice session created |
| `hl_rp_session_status_changed` | RP session status change |
| `hl_classroom_visit_submitted` | Self-reflection prompt to teacher |
| `hl_teacher_assessment_submitted` | Assessment notification |
| `hl_child_assessment_submitted` | Assessment notification |
| `hl_coach_assigned` | Meet your coach |

#### Cron-Based

Polled on schedule by the automation service:

**Daily checks:**

| Cron Key | Description |
|----------|-------------|
| `cv_window_7d` | Classroom Visit window opens in 7 days |
| `cv_overdue_1d` | Classroom Visit overdue by 1 day |
| `rp_window_7d` | Reflective Practice window opens in 7 days |
| `coaching_window_7d` | Coaching window opens in 7 days, no session scheduled |
| `coaching_session_5d` | Coaching session in 5 days |
| `coaching_pre_end` | Pre-cycle-end, no coaching session scheduled |
| `action_plan_24h` | Action plan overdue by 24 hours |
| `session_notes_24h` | Session notes overdue by 24 hours |
| `low_engagement_14d` | 14 days since last login |
| `client_success` | Client success touchpoint |

**Hourly checks:**

| Cron Key | Description |
|----------|-------------|
| `session_24h` | Coaching session in 24 hours |
| `session_1h` | Coaching session in 1 hour |

### Conditions

JSON array on each workflow. All conditions are ANDed (no OR logic in v1).

```json
[
  {"field": "cycle.cycle_type", "op": "eq", "value": "program"},
  {"field": "enrollment.role", "op": "in", "value": ["mentor", "teacher"]}
]
```

#### Operators

| Operator | Description |
|----------|-------------|
| `eq` | Equals |
| `neq` | Not equals |
| `in` | Value is in array |
| `not_in` | Value is not in array |
| `gt` | Greater than |
| `lt` | Less than |
| `is_null` | Value is null or empty |
| `not_null` | Value is not null and not empty |

#### Field Prefixes

Fields are resolved from a pre-populated context array. **The evaluator reads only from the passed context array — no DB lookups.** The automation service batch-loads all context data before calling `evaluate()`.

| Prefix | Source |
|--------|--------|
| `user.*` | wp_users + usermeta |
| `cycle.*` | hl_cycle |
| `enrollment.*` | hl_enrollment |
| `pathway.*` | hl_pathway |
| `session.*` | hl_coaching_session |
| `component.*` | hl_component |
| `visit.*` | hl_classroom_visit |

### Recipients

Tokens define who receives the email. Stored as JSON:

```json
{
  "primary": ["triggering_user"],
  "cc": ["assigned_coach"]
}
```

#### Available Tokens

| Token | Resolves To |
|-------|-------------|
| `triggering_user` | The user who triggered the event |
| `assigned_coach` | Coach assigned via `hl_coach_assignment` |
| `school_director` | School director from org unit hierarchy |
| `cc_teacher` | Teacher being observed (for CV emails) |
| `role:X` | All users with WordPress role X who are enrolled in the triggering cycle (`JOIN hl_enrollment WHERE cycle_id = context.cycle_id`). Not a global role query. |
| `static:email@example.com` | Literal email address |

**Recipient fan-out:** A single workflow CAN resolve to multiple recipients. For example, a workflow with `recipients: {"primary": ["role:coach"]}` on trigger `hl_enrollment_created` will enqueue one email per coach enrolled in that cycle — not just one email total. Each resolved recipient produces a separate queue row with its own `recipient_user_id`, merge tag resolution, and dedup token. The queue processor sends them independently.

### Send Windows

Optional per workflow. Constrains when emails are actually delivered.

| Field | Type | Description |
|-------|------|-------------|
| `send_window_start` | `time` | Earliest send time (America/New_York) |
| `send_window_end` | `time` | Latest send time (America/New_York) |
| `send_window_days` | `varchar(50)` | Comma-separated days: `mon,tue,wed,thu,fri` |

All times stored in **America/New_York** timezone. Converted to UTC at comparison time. If a trigger fires outside the window, the email is queued for the next window opening.

**DST validation:** After converting window times to UTC, validate `window_start_utc < window_end_utc`. If not (DST spring-forward gap makes the window invalid), log a warning and skip the window constraint for that execution (send immediately rather than hold indefinitely).

### Rate Limiting

Per-user limits (configurable via Settings tab):

| Window | Default Limit |
|--------|--------------|
| Hourly | 5 |
| Daily | 20 |
| Weekly | 50 |

Over-limit emails receive status `rate_limited` (not dropped). Visible in Send Log. Admin can release them manually.

**Window computation:** Uses floor-aligned time buckets. Hourly `window_start` = floor to top of hour (e.g., 14:00:00). Daily = floor to midnight (UTC). Weekly = floor to Monday midnight (UTC). Insert: `INSERT INTO hl_email_rate_limit (user_id, window_key, window_start, send_count) VALUES (%d, %s, %s, 1) ON DUPLICATE KEY UPDATE send_count = send_count + 1`. Check: `SELECT send_count WHERE user_id=%d AND window_key=%s AND window_start=%s`. **Known tradeoff:** a burst at an hour boundary can send up to 2x the hourly limit across 2 buckets. Acceptable for a safety net, not a hard guarantee.

### Failure Handling

- **3 retry attempts** with exponential backoff
- After 3 failed attempts, status set to `failed`
- **"Retry Failed"** button in Settings tab retries all failed emails
- Each attempt logged with timestamp

### Scheduling

| Type | Behavior |
|------|----------|
| Immediate | `delay_minutes = 0`, processed at next 5-min cron run |
| Delayed | `delay_minutes > 0`, `scheduled_at = NOW() + delay` |
| Manual scheduling | Admin picks date/time, stored as `scheduled_at` |

All `scheduled_at` values stored in **UTC**.

---

## 5. Manual Sends

Manual sends are cycle-scoped and live in the **Cycle -> Emails** tab. This is a universal feature available for all cycle types (not just control groups).

### Steps

1. **Select template** — dropdown of active templates or "Write custom"
2. **Select recipients** — filter by role/pathway/school + checkboxes for individual selection
3. **Send Now** or **Schedule For** (date/time picker, America/New_York)

### Dedup Protection

Same user + template + cycle within 24 hours = "Already sent" badge. Admin can override and send anyway.

**Manual send dedup token:** `md5('manual_' + template_id + '_' + user_id + '_' + cycle_id + '_' + wp_date('Y-m-d', time(), new DateTimeZone('America/New_York')))`. The 24-hour dedup window is calendar-day-based (America/New_York), not rolling. **Important:** Use `wp_date()` with explicit `America/New_York` timezone — never `date('Y-m-d')` which uses server timezone (UTC on AWS). Admin override bypasses dedup by setting `dedup_token` to NULL.

---

## 6. Database Schema

Schema revision **33 -> 34**. Four new tables.

### hl_email_template

```sql
CREATE TABLE {$wpdb->prefix}hl_email_template (
    template_id   bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    template_key  varchar(100)        NOT NULL,
    name          varchar(255)        NOT NULL,
    subject       varchar(500)        NOT NULL DEFAULT '',
    blocks_json   longtext            NOT NULL,
    category      varchar(50)         NOT NULL DEFAULT 'manual',
    merge_tags    text                NULL     COMMENT 'JSON array of tag keys (informational)',
    status        varchar(20)         NOT NULL DEFAULT 'draft',
    created_by    bigint(20) unsigned NOT NULL DEFAULT 0,
    created_at    datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY  (template_id),
    UNIQUE KEY   template_key (template_key),
    KEY          status (status),
    KEY          category (category)
) $charset_collate;
```

| Column | Notes |
|--------|-------|
| `template_key` | Unique slug (e.g., `invitation_new_account`). Used in workflow references. |
| `blocks_json` | JSON array of block objects. Rendered by `HL_Email_Block_Renderer`. |
| `category` | `invitation`, `fyi`, `reminder`, `follow_up`, `manual`. Varchar for future flexibility. |
| `status` | `draft`, `active`, `archived` |
| `merge_tags` | Informational JSON array of tag keys used in this template (for UI display). |

### hl_email_workflow

```sql
CREATE TABLE {$wpdb->prefix}hl_email_workflow (
    workflow_id       bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    name              varchar(255)        NOT NULL,
    trigger_key       varchar(100)        NOT NULL,
    conditions        longtext            NOT NULL,
    recipients        longtext            NOT NULL,
    template_id       bigint(20) unsigned NULL,
    delay_minutes     int(11)             NOT NULL DEFAULT 0,
    send_window_start time                NULL     COMMENT 'America/New_York time',
    send_window_end   time                NULL     COMMENT 'America/New_York time',
    send_window_days  varchar(50)         NULL     COMMENT 'Comma-separated: mon,tue,wed,thu,fri',
    status            varchar(20)         NOT NULL DEFAULT 'draft',
    created_at        datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY  (workflow_id),
    KEY          trigger_key (trigger_key),
    KEY          status (status),
    KEY          template_id (template_id)
) $charset_collate;
```

| Column | Notes |
|--------|-------|
| `trigger_key` | Hook name (e.g., `hl_enrollment_created`) or cron key (e.g., `cron:cv_window_7d`) |
| `conditions` | JSON array of condition objects. All ANDed. Default `[]` enforced in PHP, not SQL (dbDelta does not support DEFAULT on longtext). |
| `recipients` | JSON object with `primary` and `cc` arrays of tokens. Default `[]` enforced in PHP, not SQL (dbDelta limitation). |
| `delay_minutes` | 0 = immediate (next cron run). > 0 = delayed. |
| `send_window_*` | Optional delivery window in America/New_York. |
| `status` | `draft`, `active`, `paused` |

### hl_email_queue

```sql
CREATE TABLE {$wpdb->prefix}hl_email_queue (
    queue_id          bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    workflow_id       bigint(20) unsigned NULL     COMMENT 'NULL for manual sends',
    template_id       bigint(20) unsigned NULL,
    recipient_user_id bigint(20) unsigned NULL,
    recipient_email   varchar(255)        NOT NULL,
    subject           varchar(500)        NOT NULL,
    body_html         longtext            NOT NULL COMMENT 'Fully rendered at queue-insertion time',
    context_data      longtext            NULL     COMMENT 'JSON snapshot for debugging',
    dedup_token       varchar(64)         NULL     COMMENT 'md5 hash for duplicate prevention',
    scheduled_at      datetime            NOT NULL COMMENT 'UTC',
    sent_at           datetime            NULL,
    attempts          tinyint(3) unsigned NOT NULL DEFAULT 0,
    status            varchar(20)         NOT NULL DEFAULT 'pending',
    claim_token       varchar(36)         NULL     COMMENT 'UUID set during claim to prevent double-processing',
    failed_reason     varchar(255)        NULL     COMMENT 'Error message from wp_mail or rate limiter',
    sent_by           bigint(20) unsigned NULL     COMMENT 'NULL=automated, user ID=manual',
    created_at        datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY  (queue_id),
    KEY          status_scheduled (status, scheduled_at),
    KEY          recipient_user_id (recipient_user_id),
    KEY          workflow_id (workflow_id),
    KEY          dedup_token (dedup_token)
) $charset_collate;
```

| Column | Notes |
|--------|-------|
| `workflow_id` | NULL for manual sends, set for automated workflows. |
| `body_html` | **Fully rendered at queue-insertion time**, not send time. |
| `context_data` | JSON snapshot of merge tag context for debugging only. Deferred tag resolution (e.g., `{{password_reset_url}}`) uses `recipient_user_id` from the queue row, NOT from `context_data`. **Required shape:** `{"trigger_key": string, "cycle_id": int, "enrollment_id": int|null, "entity_id": int|null, "entity_type": string|null}`. Optional fields for debugging: `user_id`, `workflow_id`, `template_key`, `resolved_merge_tags` (key-value snapshot). The automation service and manual send handler must both produce this shape. |
| `dedup_token` | `md5(trigger_key + workflow_id + user_id + cycle_id + date_bucket)` for duplicate prevention (cron-based triggers). Includes `workflow_id` and `cycle_id` so different workflows for the same trigger don't block each other, and cross-cycle triggers are independent. For hook-based (immediate) triggers, the dedup token includes the triggering entity ID instead of date_bucket: `md5(trigger_key + '_' + workflow_id + '_' + user_id + '_' + entity_id)`. Where `entity_id` is the primary key of the triggering entity (e.g., `session_id` for `coaching_session_created`, `enrollment_id` for `enrollment_created`, `instance_id` for `assessment_submitted`). This ensures two different sessions booked on the same day are not deduped against each other. When `dedup_token` is NULL, the dedup check is skipped entirely -- the row is inserted unconditionally. This is the mechanism for admin overrides on manual sends. |
| `scheduled_at` | UTC datetime. Queue processor only picks up rows where `scheduled_at <= NOW()`. |
| `status` | `pending`, `sending`, `sent`, `failed`, `cancelled`, `rate_limited` |
| `claim_token` | UUID set by `wp_generate_uuid4()` during batch claim. Prevents double-processing across concurrent cron executions. Reset to NULL on stuck-row recovery. |
| `failed_reason` | Error message from `wp_mail()` or rate limiter. "Retry Failed" in Settings shows `failed_reason` so admins can distinguish transient (SMTP timeout) from permanent (invalid email) failures before retrying. |
| `sent_by` | NULL = automated, user ID = manual send. |

**The `sending` intermediate status** prevents double-sends from concurrent cron executions via a UUID-based atomic claim pattern: Generate a UUID (`wp_generate_uuid4()`), then `UPDATE hl_email_queue SET status='sending', claim_token='<UUID>' WHERE status='pending' AND scheduled_at <= NOW() LIMIT 50`, then `SELECT * FROM hl_email_queue WHERE claim_token='<UUID>'`. The UUID guarantees that no two cron processes claim the same rows, even if they run concurrently. Stuck rows in `sending` status for >10 minutes are reset to `pending` (with `claim_token` set to NULL) without incrementing `attempts` (attempts are only incremented on actual `wp_mail()` failure).

### hl_email_rate_limit

```sql
CREATE TABLE {$wpdb->prefix}hl_email_rate_limit (
    rl_id        bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    user_id      bigint(20) unsigned NOT NULL,
    window_key   varchar(20)         NOT NULL,
    window_start datetime            NOT NULL COMMENT 'UTC',
    send_count   smallint(5) unsigned NOT NULL DEFAULT 1,
    PRIMARY KEY  (rl_id),
    UNIQUE KEY   user_window (user_id, window_key, window_start)
) $charset_collate;
```

| Column | Notes |
|--------|-------|
| `window_key` | `hourly`, `daily`, `weekly` |
| `window_start` | Start of the current window in UTC |
| `send_count` | Incremented via `INSERT ON DUPLICATE KEY UPDATE send_count = send_count + 1` |

### Usermeta

| Key | Set By | Purpose |
|-----|--------|---------|
| `hl_account_activated` | `wp_login` hook (set to `'1'` on first login) | Condition evaluator: distinguish new vs existing users |
| `last_login` | `wp_login` hook (updated every login) | Low-engagement cron check (14 days no login) |

---

## 7. Email Rendering

### HL_Email_Block_Renderer

Singleton service. Renders a block JSON array to a complete HTML email document.

### Document Structure

```
+------------------------------------------+
|  [branded header: dark navy #1A2B47]     |
|  [logo centered]                          |
+------------------------------------------+
|  [white content card]                     |
|    [block 1]                              |
|    [block 2]                              |
|    [block 3]                              |
+------------------------------------------+
|  [branded footer]                         |
+------------------------------------------+
```

- Table-based layout, `max-width: 600px`
- Dark mode: `@media (prefers-color-scheme: dark)` in `<head>` style + MSO conditionals for Outlook. **Best-effort only:** Gmail strips `<head>` styles, so dark mode only works in Apple Mail, iOS Mail, and Outlook 2019+. Builder dark mode toggle labeled "Apple Mail / Outlook preview only."
- Mobile: `@media (max-width: 600px)` stacks columns to single column
- Outlook: VML `roundrect` for buttons, MSO XML for pixel density

### Font Stack

```
-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif
```

### Block Rendering

Each block type renders to table-based HTML with inline CSS. No external stylesheets (email client compatibility).

### Merge Tag Substitution

1. `esc_html()` on all resolved values
2. `preg_replace` to strip any unresolved `{{tags}}` from final output
3. Unresolved tags logged to `HL_Audit_Service`

### Logo URL

Consolidated to a single class constant: `HL_Email_Block_Renderer::LOGO_URL`

---

## 8. Merge Tag Registry

### HL_Email_Merge_Tag_Registry

Singleton service. All tags registered in constructor with label, resolver callback, and category. Used by:

- Builder UI (tag hints in contenteditable toolbar)
- Workflow editor (tag reference)
- Block renderer (substitution at render time)

### Recipient Tags

| Tag | Resolves To |
|-----|-------------|
| `{{recipient_first_name}}` | First word of `wp_users.display_name` |
| `{{recipient_full_name}}` | `wp_users.display_name` |
| `{{recipient_email}}` | `wp_users.user_email` |

### Cycle / Program Tags

| Tag | Resolves To |
|-----|-------------|
| `{{cycle_name}}` | `hl_cycle.cycle_name` |
| `{{partnership_name}}` | `hl_partnership.partnership_name` via cycle |
| `{{school_name}}` | `hl_orgunit.name` via enrollment |
| `{{school_district}}` | Parent orgunit name |

### Enrollment / Pathway Tags

| Tag | Resolves To |
|-----|-------------|
| `{{pathway_name}}` | `hl_pathway.pathway_name` via enrollment |
| `{{enrollment_role}}` | First role from enrollment, `ucfirst` |

### Coaching Tags

| Tag | Resolves To |
|-----|-------------|
| `{{coach_first_name}}` | Coach first name via `hl_coach_assignment` |
| `{{coach_full_name}}` | Coach display name via `hl_coach_assignment` |
| `{{coach_email}}` | Coach email via `hl_coach_assignment` |
| `{{session_date}}` | Formatted in America/New_York: "Monday, April 14, 2026 at 2:00 PM ET" |
| `{{zoom_link}}` | `hl_coaching_session.meeting_url` |
| `{{old_session_date}}` | Previous session date (from context, for reschedule emails) |
| `{{new_session_date}}` | New session date (from context, for reschedule emails) |
| `{{cancelled_by_name}}` | Name of user who cancelled (from context) |
| `{{mentor_full_name}}` | Enrolled user display_name (for coach-recipient emails) |

### Assessment Tags

| Tag | Resolves To |
|-----|-------------|
| `{{assessment_type}}` | "Pre-Assessment" or "Post-Assessment" |

### Course Tags

| Tag | Resolves To |
|-----|-------------|
| `{{course_title}}` | LearnDash post title |

### URL Tags

| Tag | Resolves To |
|-----|-------------|
| `{{login_url}}` | `wp_login_url()` |
| `{{dashboard_url}}` | Page with `[hl_dashboard]` shortcode |
| `{{program_page_url}}` | Page with `[hl_program_page]` + `?enrollment_id=X` |
| `{{password_reset_url}}` | **Deferred tag** — stored as literal `{{password_reset_url}}` in `body_html`. Resolved by the queue processor immediately before `wp_mail()` via `get_password_reset_key()`. Expires in 24 hours. Requires `recipient_user_id` on the queue row. |
| `{{coaching_schedule_url}}` | Component page for next unscheduled coaching component |
| `{{cv_form_url}}` | Component page URL for classroom visit form |
| `{{rp_session_url}}` | Component page URL for reflective practice session |

### URL Tag Caching

URL tags that are truly global (`login_url`, `dashboard_url`) cache their resolved value in a static variable within the registry resolver. Resolved once per process, reused across all emails in the same batch.

Per-enrollment URL tags (`program_page_url`, `coaching_schedule_url`, `cv_form_url`, `rp_session_url`) are resolved per-recipient from context and must NOT be cached across a batch.

### Tag Resolution Failures

- Unresolved tags are replaced with an empty string
- Failure is logged to `HL_Audit_Service` with tag name and context

---

## 9. Trigger Mapping

Complete mapping of all 25+ email scenarios.

### Invitations

| ID | Name | Trigger | Conditions | Recipients | Template Key |
|----|------|---------|------------|------------|-------------|
| INV-1A | New Account Activation | `hl_enrollment_created` | `user.account_activated` is_null, `cycle.is_control_group` eq false | `triggering_user` | `invitation_new_account` |
| INV-1B | New Account (Control Group) | `hl_enrollment_created` | `user.account_activated` is_null, `cycle.is_control_group` eq true | `triggering_user` | `invitation_new_account_control` |

### FYI

| ID | Name | Trigger | Conditions | Recipients | Template Key |
|----|------|---------|------------|------------|-------------|
| FYI-1A | Pathway Enrollment | `hl_enrollment_created` | `user.account_activated` eq "1", `cycle.is_control_group` eq false | `triggering_user` | `fyi_pathway_enrolled` |
| FYI-1B | Pathway Enrollment (Control) | `hl_enrollment_created` | `user.account_activated` eq "1", `cycle.is_control_group` eq true | `triggering_user` | `fyi_pathway_enrolled_control` |
| FYI-2 | Course Completion | `hl_learndash_course_completed` | none | `triggering_user` | `fyi_course_completed` |
| FYI-3 | Pathway Completion | `hl_pathway_completed` | none | `triggering_user` | `fyi_pathway_completed` |

### Reminders

| ID | Name | Trigger | Conditions | Recipients | Template Key |
|----|------|---------|------------|------------|-------------|
| REM-1 | Pre-Assessment | `hl_pathway_assigned` | `enrollment.role` in ["mentor"] | `triggering_user` | `reminder_pre_assessment` |
| REM-2 | CV Window Opening | `cron:cv_window_7d` | none | `triggering_user` | `reminder_cv_window` |
| REM-3 | CV Submitted -> Teacher | `hl_classroom_visit_submitted` | `visit.role` eq "observer" | `cc_teacher` | `reminder_cv_self_reflection` |
| REM-4 | CV Overdue | `cron:cv_overdue_1d` | none | `triggering_user` | `reminder_cv_overdue` |
| REM-5 | RP Window Opening | `cron:rp_window_7d` | none | `triggering_user` | `reminder_rp_window` |
| REM-6 | Coaching Schedule | `cron:coaching_window_7d` | none | `triggering_user` | `reminder_coaching_schedule` |
| REM-7 | Pre-Cycle-End No Session | `cron:coaching_pre_end` | none | `triggering_user` | `reminder_coaching_pre_end` |
| REM-8A | Session Booked (Mentor) | `hl_coaching_session_created` | none | `triggering_user` | `reminder_session_booked_mentor` |
| REM-8B | Session Booked (Coach) | `hl_coaching_session_created` | none | `assigned_coach` | `reminder_session_booked_coach` |
| REM-9 | Session -5 days | `cron:coaching_session_5d` | none | `triggering_user` | `reminder_session_5days` |
| REM-10 | Session -24hrs | `cron:session_24h` | none | `triggering_user` | `reminder_session_24hours` |
| REM-11 | Session -1hr | `cron:session_1h` | none | `triggering_user` | `reminder_session_1hour` |
| REM-12 | Action Plan Overdue | `cron:action_plan_24h` | none | `triggering_user` | `reminder_action_plan` |
| REM-13 | Session Notes Overdue | `cron:session_notes_24h` | none | `assigned_coach` | `reminder_session_notes` |
| REM-14A | No-Show (Mentor) | `hl_coaching_session_status_changed` | `session.new_status` eq "missed" | `triggering_user` | `reminder_noshow_mentor` |
| REM-14B | No-Show (CC Director) | `hl_coaching_session_status_changed` | `session.new_status` eq "missed" | `school_director` | `reminder_noshow_director` |
| REM-15 | Client Success Touchpoint | `cron:client_success` | none | `role:school_leader` | `reminder_client_success` |

### Follow-ups

| ID | Name | Trigger | Conditions | Recipients | Template Key |
|----|------|---------|------------|------------|-------------|
| FLW-1 | Low Engagement (14d) | `cron:low_engagement_14d` | `cycle.status` eq "active" | `triggering_user` | `followup_low_engagement` |

---

## 10. Admin UI

### Menu: Emails (submenu under Housman LMS)

Four tabs: **Automated Workflows** | **Email Templates** | **Send Log** | **Settings**

### Automated Workflows Tab

**List Table:**

| Column | Content |
|--------|---------|
| Name | Workflow name |
| Trigger | Trigger key (human-readable) |
| Template | Template name |
| Status | Badge: draft (gray), active (green), paused (yellow) |
| Updated | Last updated date |

**Row actions:** Edit, Duplicate, Activate/Pause, Delete

**Workflow Editor Form:**

- **Name** (text input)
- **Status** (dropdown: draft, active, paused)
- **Trigger** (dropdown, grouped by Hook-Based / Cron-Based)
- **Conditions** (dynamic row builder: field dropdown + operator dropdown + value input, add/remove rows)
- **Recipients** (token selector with primary and cc sections)
- **Template** (dropdown of active templates)
- **Delay** (number input, minutes)
- **Send Window** (optional toggle with time pickers + day checkboxes)
  - Note displayed: "All times are America/New_York (ET)."

**Password reset URL warning:** If the selected template contains `{{password_reset_url}}` and `delay_minutes > 0` or a send window is set, show warning: "Password reset links expire in 24 hours. Remove the delay or send window to avoid expired links."

### Email Templates Tab

**Status filter pills** at top: All | Draft | Active | Archived

**List Table:**

| Column | Content |
|--------|---------|
| Name | Template name |
| Subject | Email subject line |
| Category | Category badge |
| Status | Status badge |
| Updated | Last updated date |

**Row actions:** Edit, Duplicate, Archive, Preview

**Template Editor:** Opens the two-panel builder described in Section 3.

### Send Log Tab

**Filters:** Date range picker, status dropdown, template dropdown, search input.

**List Table:**

| Column | Content |
|--------|---------|
| ID | Queue ID |
| Recipient | User name + email |
| Subject | Email subject |
| Workflow | Workflow name (or "Manual") |
| Status | Status badge with color coding |
| Scheduled At | UTC converted to America/New_York |
| Sent At | UTC converted to America/New_York |
| Attempts | Number of attempts |

**Row actions** (vary by status):

| Status | Actions |
|--------|---------|
| `pending` | Cancel, View HTML |
| `sending` | View HTML |
| `sent` | View HTML, Resend |
| `failed` | Retry, View HTML, Cancel |
| `cancelled` | View HTML |
| `rate_limited` | Release, View HTML, Cancel |

**Release action behavior:** Sets `status='pending'` and advances `scheduled_at` to `NOW()` (UTC). Does NOT bypass the rate limit check — by the time an admin intervenes, the rate limit window will have rolled over naturally. If the rate limit is still active, the email will be re-rate-limited on the next processor run.

**Bulk actions:** Retry All Failed, Cancel All Pending

### Settings Tab

- **Rate limits** — hour/day/week number inputs with save button
- **Queue health** — counts by status (pending, sending, sent, failed, cancelled, rate_limited) + "Process Queue Now" button
- **Migration** — "Migrate Legacy Templates" button, "Create Default Workflow Drafts" button
- **Retry Failed** — button to retry all failed emails

### Cycle -> Emails Tab (Manual Sends)

Universal (not just control group). Located inside each Cycle's admin page.

**Workflow:**
1. Template select dropdown (active templates + "Write custom" option)
2. Recipient filter bar (role, pathway, school) + AJAX-filtered checkbox table
3. Send Now / Schedule For (date/time picker, America/New_York)

**Send history table** below showing both new `hl_email_queue` entries and legacy `hl_cycle_email_log` entries in a unified view.

---

## 11. Migration Plan

### Coexistence Model

Old code keeps working. New workflows start as draft. Admin activates them one at a time after verification.

### Migration Steps

| Step | Action | Details |
|------|--------|---------|
| M-1 | Schema rev 34 | `dbDelta` creates 4 tables. Cron events registered via plugin activation hook (`register_activation_hook`), **not** in `maybe_upgrade()`. An `init`-time check via `wp_next_scheduled()` ensures crons are re-registered if missing (handles manual DB upgrades, lost cron entries, etc.). |
| M-2a | Login hook | `wp_login` hook sets `hl_account_activated` (first login only) and `last_login` (every login) usermeta. |
| M-2b | Backfill existing users | One-time migration: sets `hl_account_activated='1'` for all users who have existing enrollments in `hl_enrollment`. All queries use `$wpdb->usermeta`, `$wpdb->prefix . 'hl_enrollment'`, etc. Never hardcode the table prefix. Prevents invitation emails from firing for already-active users when workflows are first activated. Idempotent (checks completion flag in `wp_options`). |
| M-3 | Template migration | Admin-triggered migration of 6 coaching templates from `wp_options` to `hl_email_template` rows. |
| M-4 | Workflow drafts | Admin creates invitation workflow drafts using the workflow editor. |
| M-5 | Gradual activation | Admin builds and activates workflows one at a time, verifying each. |
| M-6 | Decommission old code | Old cycle email send logic decommissioned after invitation workflows verified. |
| M-7 | Cleanup | `HL_Admin_Email_Templates` class and `wp_option` entries removed in future cleanup. |

---

## 12. Security

### Authentication & Authorization

- All admin AJAX handlers: `check_ajax_referer()` + `current_user_can('manage_hl_core')`
- Nonces on all AJAX actions
- Capability checks on all menu pages and handlers

### Input Sanitization

| Input Type | Function |
|------------|----------|
| Text fields | `sanitize_text_field()` |
| HTML content | `wp_kses_post()` |
| URLs | `esc_url_raw()` |
| Block JSON | `json_decode()` + per-field sanitization |
| Email addresses | `sanitize_email()` |

### Output Escaping

- `esc_html()` for text output
- `esc_attr()` for HTML attributes
- `esc_url()` for URLs

### Additional Protections

- **SVG blocking:** JavaScript check on upload + server-side validation
- **Rate limiting:** Defense-in-depth against accidental email floods
- **Password reset URL expiry warning:** Workflow editor warns if delay > 0 and template uses `{{password_reset_url}}`
- **Email header injection prevention:** `sanitize_email()` + `sanitize_text_field()` on all `wp_mail()` inputs
- **View HTML modal:** Sandboxed iframe (`sandbox` attribute) for viewing sent email HTML
- **SQL injection prevention:** All queries use `$wpdb->prepare()`

---

## 13. Known Gaps & Future Work

### Must Address Before First Production Activation

| ID | Gap | Details |
|----|-----|---------|
| G-1 | `wp_login` hook | Must implement `hl_account_activated` and `last_login` usermeta hooks. Prerequisite for condition evaluator (new vs existing user). |
| G-2 | Component window columns | Verify `available_from`/`available_to` columns exist on `hl_component`. Prerequisite for cron triggers REM-2, REM-4, REM-5. |
| G-3 | Duplicate workflow documentation | Same trigger with different recipients requires separate workflows. Must document this pattern for admins. |
| G-4 | ~~Password reset URL expiry~~ | Resolved: `{{password_reset_url}}` is now a deferred tag resolved at send time. Workflow editor warning already specified in Section 10 as defense-in-depth. |

### Future Work (Out of Scope for v1)

- Unsubscribe management (CAN-SPAM compliance)
- Email open/click tracking
- External delivery service integration (SES, SendGrid)
- A/B testing for subject lines and content
- Webhook-based triggers (external systems)
- Condition OR logic (v1 is AND only). **Upgrade path:** v2 adds a `logic` field to `hl_email_workflow` (`"and"` or `"or"`) defaulting to `"and"`. The evaluator wraps the existing AND loop in a conditional. No schema change to the `conditions` JSON column itself — the structure `[{field, op, value}, ...]` stays flat. Do NOT attempt to implement OR by creating duplicate workflows — this leads to maintenance burden and divergent template versions.
- Rich text TinyMCE in block editor (v1 uses contenteditable with mini-toolbar)
- Session number merge tag (e.g., "Coaching Session 3 of 6")
