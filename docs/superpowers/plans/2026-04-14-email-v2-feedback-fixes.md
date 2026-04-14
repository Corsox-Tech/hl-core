# Email V2 — Post-Demo Feedback Fixes

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Address all 13 feedback items from the 2026-04-13 client demo, fixing bugs, removing redundant fields, refactoring the trigger engine for configurable offsets, and adding builder UX improvements.

**Architecture:** Four phases with clear dependencies. Phase 1 (quick fixes) is fully parallelizable. Phase 2 (trigger engine refactor) is sequential — each task builds on the previous. Phases 3-4 (builder enhancements, admin UX) are independent of each other but should run after Phase 1.

**Tech Stack:** PHP 7.4+, WordPress 6.0+, jQuery, WP-CLI test harness (`wp hl-core email-v2-test`), MariaDB.

**Source docs:**
- Feedback: `docs/2026-04-13-LMS-Email-Module-Feedback.md`
- Design spec: `docs/superpowers/specs/2026-04-10-email-system-v2-design.md`

**Test server:** South Haven Y2 (cycle_id=5) has dates populated for trigger testing. Block 2 components have `complete_by = 2026-04-21` and coaching `display_window_start = 2026-04-14` — within 7-day trigger window.

---

## Phase 1: Foundation & Quick Fixes (parallelizable)

### Task 1: Fix enrollment status condition dropdown (A.2)

**Files:**
- Modify: `includes/admin/class-hl-admin-emails.php:88-99`
- Test: CLI — `wp hl-core email-v2-test --only=conditions`

The condition dropdown shows 5 enrollment statuses (`active, warning, withdrawn, completed, expired`) but the DB enum is `('active','inactive')`. Four values are phantom, `inactive` is missing.

- [ ] **Step 1: Fix the options array**

In `includes/admin/class-hl-admin-emails.php`, replace lines 88-99:

```php
// OLD (lines 88-99):
'enrollment.status' => array(
    'label'   => 'Enrollment Status',
    'group'   => 'Enrollment',
    'type'    => 'enum',
    'options' => array(
        'active'    => 'Active',
        'warning'   => 'Warning',
        'withdrawn' => 'Withdrawn',
        'completed' => 'Completed',
        'expired'   => 'Expired',
    ),
),

// NEW:
'enrollment.status' => array(
    'label'   => 'Enrollment Status',
    'group'   => 'Enrollment',
    'type'    => 'enum',
    'options' => array(
        'active'   => 'Active',
        'inactive' => 'Inactive',
    ),
),
```

- [ ] **Step 2: Verify — grep for stale references**

Run: `grep -rn "'warning'" includes/admin/class-hl-admin-emails.php`
Expected: 0 matches.

- [ ] **Step 3: Commit**

```bash
git add includes/admin/class-hl-admin-emails.php
git commit -m "fix(email): enrollment status condition dropdown matches DB enum (active/inactive)"
```

---

### Task 2: Fix dark mode text color bug (B.1 / G.6)

**Files:**
- Modify: `includes/services/class-hl-email-block-renderer.php:225`
- Modify: `includes/services/class-hl-email-block-renderer.php:23` (DARK_MODE_CSS constant)

The `<span>` inside `.hl-email-text` has inline `color:#374151` but no CSS class. The dark-mode CSS targets `.hl-email-text` (the `<td>`) with `!important`, but the inner `<span>` keeps its hardcoded dark gray, creating gray-on-dark-blue (#374151 on #16213e).

- [ ] **Step 1: Add class to the text span**

In `includes/services/class-hl-email-block-renderer.php`, replace line 225:

```php
// OLD (line 225):
$open_span  = '<span style="font-size:' . $size . 'px;line-height:1.6;color:#374151;">';

// NEW:
$open_span  = '<span class="hl-email-text-span" style="font-size:' . $size . 'px;line-height:1.6;color:#374151;">';
```

- [ ] **Step 2: Extend the dark-mode CSS to target the span**

In `includes/services/class-hl-email-block-renderer.php`, update the `DARK_MODE_CSS` constant at line 23:

```php
// OLD (line 23):
const DARK_MODE_CSS = '@media (prefers-color-scheme:dark){body,.hl-email-body{background-color:#1a1a2e!important}.hl-email-card{background-color:#16213e!important}.hl-email-text{color:#e0e0e0!important}.hl-email-footer{background-color:#0f0f23!important}.hl-email-footer-text{color:#9CA3AF!important}}';

// NEW:
const DARK_MODE_CSS = '@media (prefers-color-scheme:dark){body,.hl-email-body{background-color:#1a1a2e!important}.hl-email-card{background-color:#16213e!important}.hl-email-text,.hl-email-text-span{color:#e0e0e0!important}.hl-email-footer{background-color:#0f0f23!important}.hl-email-footer-text{color:#9CA3AF!important}}';
```

- [ ] **Step 3: Add light-mode meta to preview for belt-and-suspenders**

In `includes/admin/class-hl-admin-email-builder.php`, after line 619 (where DARK_MODE_CSS is stripped for non-dark preview), add a `color-scheme: light only` meta:

```php
// After line 619 ($html = str_replace(...)):
$html = preg_replace(
    '#<head([^>]*)>#i',
    '<head$1><meta name="color-scheme" content="light only">',
    $html, 1
);
```

- [ ] **Step 4: Verify — test the block renderer output**

Run: `wp hl-core email-v2-test --only=renderer`
Expected: 18/18 PASS (existing tests should not break).

- [ ] **Step 5: Commit**

```bash
git add includes/services/class-hl-email-block-renderer.php includes/admin/class-hl-admin-email-builder.php
git commit -m "fix(email): dark mode text color — add span class + light-mode meta in preview"
```

---

### Task 3: Merge tag click-to-copy feedback (new finding)

**Files:**
- Modify: `assets/js/admin/email-builder.js:775-780`

The sidebar merge tag items have a click-to-copy handler but zero visual feedback. Users think nothing happens. Also no clipboard API fallback.

- [ ] **Step 1: Add visual feedback + clipboard fallback**

In `assets/js/admin/email-builder.js`, replace lines 775-780:

```javascript
// OLD (lines 775-780):
$(document).on('click', '.hl-eb-tag-item', function () {
    var tag = '{{' + $(this).data('tag') + '}}';
    if (navigator.clipboard) {
        navigator.clipboard.writeText(tag);
    }
});

// NEW:
$(document).on('click', '.hl-eb-tag-item', function () {
    var $el  = $(this);
    var tag  = '{{' + $el.data('tag') + '}}';
    var orig = $el.text();

    function showCopied() {
        $el.text('Copied!').addClass('hl-eb-tag-copied');
        setTimeout(function () {
            $el.text(orig).removeClass('hl-eb-tag-copied');
        }, 1200);
    }

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(tag).then(showCopied).catch(function () {
            fallbackCopy(tag);
            showCopied();
        });
    } else {
        fallbackCopy(tag);
        showCopied();
    }
});

function fallbackCopy(text) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    document.execCommand('copy');
    document.body.removeChild(ta);
}
```

- [ ] **Step 2: Add CSS for the copied state**

In `assets/css/admin.css`, add after the `.hl-eb-tag-item` hover styles (around line 1777):

```css
.hl-eb-tag-item.hl-eb-tag-copied {
    background: var(--hl-success, #10b981);
    color: #fff;
    border-color: var(--hl-success, #10b981);
}
```

- [ ] **Step 3: Bump version for cache busting**

In `hl-core.php`, find the current plugin version constant and increment the patch version. Also update `assets/css/admin.css` enqueue version if it uses a separate version string.

- [ ] **Step 4: Commit**

```bash
git add assets/js/admin/email-builder.js assets/css/admin.css
git commit -m "fix(email): merge tag click-to-copy feedback — Copied! flash + clipboard fallback"
```

---

### Task 4: Email domain allowlist safety gate (C.1)

**Files:**
- Modify: `includes/services/class-hl-email-queue-processor.php` (find the `wp_mail()` call)
- Create: test assertion in `bin/test-email-v2-track3.php`

Add a temporary safety gate that only allows emails to `@housmanlearning.com`, `@corsox.com`, and `@yopmail.com`. This is a pre-send check, not a queue-insertion check — emails still get queued and marked as sent, but `wp_mail()` is only called for allowed domains.

- [ ] **Step 1: Find the wp_mail() call in the queue processor**

Read `includes/services/class-hl-email-queue-processor.php` and find where `wp_mail()` is called. This is the single choke point for all email delivery.

- [ ] **Step 2: Add the domain allowlist check before wp_mail()**

Add immediately before the `wp_mail()` call:

```php
// Temporary safety gate — restrict sends to internal domains during testing.
// Remove this gate once email system is fully validated.
$allowed_domains = array( 'housmanlearning.com', 'corsox.com', 'yopmail.com' );
$recipient_domain = substr( strrchr( $to, '@' ), 1 );
if ( ! in_array( strtolower( $recipient_domain ), $allowed_domains, true ) ) {
    $this->log_blocked_send( $queue_row->queue_id, $to, 'domain_not_allowed' );
    // Mark as sent to prevent retry loops, but don't actually send.
    $wpdb->update(
        $wpdb->prefix . 'hl_email_queue',
        array( 'status' => 'blocked', 'processed_at' => current_time( 'mysql' ) ),
        array( 'queue_id' => $queue_row->queue_id )
    );
    continue;
}
```

- [ ] **Step 3: Add the log_blocked_send helper method**

Add to the queue processor class:

```php
private function log_blocked_send( $queue_id, $to, $reason ) {
    if ( class_exists( 'HL_Audit_Service' ) ) {
        HL_Audit_Service::log(
            'email_send_blocked',
            'hl_email_queue',
            $queue_id,
            array( 'to' => $to, 'reason' => $reason )
        );
    }
}
```

- [ ] **Step 4: Add 'blocked' to the valid status values**

Check if the `hl_email_queue` table's `status` column accepts 'blocked'. If it's an ENUM, it may need ALTER. If it's VARCHAR, no change needed. Read `includes/class-hl-installer.php` and find the `hl_email_queue` schema to verify.

- [ ] **Step 5: Add test assertion**

Add to the email-v2-test deliverability group:

```php
// Domain allowlist blocks external addresses
$processor = new HL_Email_Queue_Processor();
$allowed   = $processor->is_domain_allowed( 'test@housmanlearning.com' );
$blocked   = $processor->is_domain_allowed( 'user@gmail.com' );
$this->assert( $allowed === true, 'housmanlearning.com is allowed' );
$this->assert( $blocked === false, 'gmail.com is blocked' );
```

Make `is_domain_allowed()` a public method extracted from the inline check for testability.

- [ ] **Step 6: Run tests**

Run: `wp hl-core email-v2-test --only=deliverability`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add includes/services/class-hl-email-queue-processor.php bin/test-email-v2-track3.php
git commit -m "feat(email): domain allowlist safety gate — restrict sends to internal domains during testing"
```

---

### Task 5: Cycle date-gating on cron queries

**Files:**
- Modify: `includes/services/class-hl-email-automation-service.php:716,768`

Add date bounds to the active-cycle query so emails don't fire for cycles that haven't started or have already ended.

- [ ] **Step 1: Update run_daily_checks() cycle query**

In `includes/services/class-hl-email-automation-service.php`, replace line 716:

```php
// OLD (line 716):
"SELECT * FROM {$wpdb->prefix}hl_cycle WHERE status = 'active'"

// NEW:
$wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}hl_cycle WHERE status = 'active' AND start_date <= %s AND (end_date IS NULL OR end_date >= %s)",
    $today,
    $today
)
```

Note: `$today` is already defined at line 720 as `wp_date('Y-m-d')`. Move the `$today` assignment ABOVE the cycle query (before line 716).

- [ ] **Step 2: Update run_hourly_checks() cycle query**

In the same file, replace line 768:

```php
// OLD (line 768):
"SELECT * FROM {$wpdb->prefix}hl_cycle WHERE status = 'active'"

// NEW:
$today_h = wp_date( 'Y-m-d' );
$wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}hl_cycle WHERE status = 'active' AND start_date <= %s AND (end_date IS NULL OR end_date >= %s)",
    $today_h,
    $today_h
)
```

- [ ] **Step 3: Add test assertion**

Add to the email-v2-test cron group:

```php
// Cycle date-gating: future cycle should be excluded
// (Verify by checking that B2E_PATHWAY_TEMPLATES cycle_id=9 with start_date=2026-04-01 and status='draft' is not returned)
// This is a structural test — the SQL itself gates the data.
```

Since this is a SQL-level change, verify via direct query on test server:
```bash
wp db query "SELECT cycle_id, cycle_code FROM wp_hl_cycle WHERE status = 'active' AND start_date <= CURDATE() AND (end_date IS NULL OR end_date >= CURDATE())"
```
Expected: Only cycles whose dates bracket today (should exclude archived/future cycles).

- [ ] **Step 4: Commit**

```bash
git add includes/services/class-hl-email-automation-service.php
git commit -m "fix(email): add date-gating to cron cycle queries — skip future/ended cycles"
```

---

## Phase 2: Trigger Engine Refactor (sequential)

### Task 6: Remove `available_from`/`available_to`, rewire triggers (A.4 + A.6)

**Files:**
- Modify: `includes/services/class-hl-email-automation-service.php:1040-1041,1103-1104,1137-1138,1171-1172`
- Modify: `includes/admin/class-hl-admin-pathways.php:1913-1925,402-414`
- Modify: `includes/class-hl-installer.php:1496-1497` (schema comments only — keep columns for now, remove later)
- Test: `wp hl-core email-v2-test --only=cron`

**Date anchor mapping (hardcoded per component type):**

| Component Type | "Opens/Upcoming" Anchor | "Overdue/Past" Anchor |
|---|---|---|
| `coaching_session_attendance` | `display_window_start` | `display_window_end` |
| All others | `complete_by` | `complete_by` |

- [ ] **Step 1: Define the date anchor helper method**

Add to `class-hl-email-automation-service.php`, above `get_cron_trigger_users()`:

```php
/**
 * Get the date column to use as trigger anchor for a given trigger type.
 *
 * @param string $trigger_type 'upcoming' or 'overdue'
 * @param string $component_type The component type (e.g., 'coaching_session_attendance')
 * @return array [ 'column' => string, 'table_alias' => string ]
 */
private function get_date_anchor( $trigger_type, $component_type = '' ) {
    if ( $component_type === 'coaching_session_attendance' ) {
        return $trigger_type === 'overdue'
            ? array( 'column' => 'display_window_end', 'table_alias' => 'c' )
            : array( 'column' => 'display_window_start', 'table_alias' => 'c' );
    }
    return array( 'column' => 'complete_by', 'table_alias' => 'c' );
}
```

- [ ] **Step 2: Rewire `cron:coaching_window_7d` — use `display_window_start`**

In `get_cron_trigger_users()`, replace lines 1040-1041 inside the `cron:coaching_window_7d` case:

```php
// OLD (lines 1040-1041):
AND c.available_from IS NOT NULL
AND c.available_from BETWEEN %s AND %s

// NEW:
AND c.display_window_start IS NOT NULL
AND c.display_window_start BETWEEN %s AND %s
```

- [ ] **Step 3: Rewire `cron:cv_window_7d` — use `complete_by`**

Replace lines 1103-1104:

```php
// OLD:
AND c.available_from IS NOT NULL
AND c.available_from BETWEEN %s AND %s

// NEW:
AND c.complete_by IS NOT NULL
AND c.complete_by BETWEEN %s AND %s
```

- [ ] **Step 4: Rewire `cron:cv_overdue_1d` — use `complete_by`**

Replace lines 1137-1138:

```php
// OLD:
AND c.available_to IS NOT NULL
AND c.available_to = %s

// NEW:
AND c.complete_by IS NOT NULL
AND c.complete_by = %s
```

- [ ] **Step 5: Rewire `cron:rp_window_7d` — use `complete_by`**

Replace lines 1171-1172:

```php
// OLD:
AND c.available_from IS NOT NULL
AND c.available_from BETWEEN %s AND %s

// NEW:
AND c.complete_by IS NOT NULL
AND c.complete_by BETWEEN %s AND %s
```

- [ ] **Step 6: Remove Submission Window UI from component form**

In `includes/admin/class-hl-admin-pathways.php`, remove or comment out lines 1913-1925 (the entire `<tr>` block for "Submission Window" including both date inputs).

- [ ] **Step 7: Remove save handler for available_from/available_to**

In the same file, remove lines 402-414 (the `$raw_af`/`$raw_at` parsing and `$data['available_from']`/`$data['available_to']` assignment). Keep the column in the DB schema for now — data migration to drop columns comes later.

- [ ] **Step 8: Run tests**

Run: `wp hl-core email-v2-test`
Expected: All existing tests pass. The cron tests may need adjustment if they reference `available_from`.

- [ ] **Step 9: Verify on test server with South Haven data**

```bash
wp db query "SELECT c.component_id, c.title, c.component_type, c.complete_by, c.display_window_start FROM wp_hl_component c JOIN wp_hl_pathway p ON c.pathway_id = p.pathway_id WHERE p.cycle_id = 5 AND c.complete_by BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)"
```
Expected: Block 2 components with `complete_by = 2026-04-21` should appear (if run within 7 days of April 21).

- [ ] **Step 10: Commit**

```bash
git add includes/services/class-hl-email-automation-service.php includes/admin/class-hl-admin-pathways.php
git commit -m "refactor(email): rewire triggers to complete_by/display_window_start, remove Submission Window UI (A.4+A.6)"
```

---

### Task 7: Configurable trigger offset with minutes/hours/days (A.3)

**Files:**
- Modify: `includes/class-hl-installer.php` (add column to `hl_email_workflow`)
- Modify: `includes/services/class-hl-email-automation-service.php` (parameterize SQL)
- Modify: `includes/admin/class-hl-admin-emails.php` (add offset UI, refactor trigger keys)
- Modify: `assets/js/admin/email-workflow.js` (offset input visibility)
- Test: `wp hl-core email-v2-test --only=cron`

This is the largest task. The trigger keys change from specific offsets (e.g., `cron:cv_window_7d`) to generic types (e.g., `cron:component_upcoming`), and the offset value is stored per-workflow.

**New trigger key scheme:**

| Old Key | New Key | Default Offset | Anchor |
|---|---|---|---|
| `cron:coaching_window_7d` | `cron:component_upcoming` | 10080 min (7d) | `display_window_start` (coaching) or `complete_by` (others) |
| `cron:cv_window_7d` | `cron:component_upcoming` | 10080 min (7d) | `complete_by` |
| `cron:rp_window_7d` | `cron:component_upcoming` | 10080 min (7d) | `complete_by` |
| `cron:cv_overdue_1d` | `cron:component_overdue` | 1440 min (1d) | `complete_by` |
| `cron:coaching_session_5d` | `cron:session_upcoming` | 7200 min (5d) | `session_datetime` |
| `cron:session_24h` | `cron:session_upcoming` | 1440 min (24h) | `session_datetime` |
| `cron:session_1h` | `cron:session_upcoming` | 60 min (1h) | `session_datetime` |

Triggers that don't use a date offset (`cron:coaching_pre_end`, `cron:action_plan_24h`, `cron:session_notes_24h`, `cron:low_engagement_14d`, `cron:client_success`) keep their existing keys and hardcoded logic.

- [ ] **Step 1: Add `trigger_offset_minutes` column to `hl_email_workflow`**

In `includes/class-hl-installer.php`, add to the `hl_email_workflow` CREATE TABLE (after `delay_minutes` around line 2156):

```sql
trigger_offset_minutes int DEFAULT NULL COMMENT 'Configurable offset for cron triggers (in minutes)',
```

Add a schema migration method (new revision):

```php
private function migrate_workflow_add_offset_col() {
    global $wpdb;
    $table = $wpdb->prefix . 'hl_email_workflow';
    $col   = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'trigger_offset_minutes'" );
    if ( empty( $col ) ) {
        $wpdb->query( "ALTER TABLE {$table} ADD COLUMN trigger_offset_minutes int DEFAULT NULL COMMENT 'Configurable offset for cron triggers (in minutes)' AFTER delay_minutes" );
    }
}
```

Register in `run_migrations()` at the next revision number.

- [ ] **Step 2: Add `component_type_filter` column to `hl_email_workflow`**

This column lets a workflow target specific component types (e.g., only `classroom_visit` or only `coaching_session_attendance`). Without it, `cron:component_upcoming` would match ALL component types.

```sql
component_type_filter varchar(100) DEFAULT NULL COMMENT 'Component type filter for cron triggers',
```

Same migration pattern as Step 1.

- [ ] **Step 3: Update trigger key options in admin form**

In `includes/admin/class-hl-admin-emails.php`, update the cron trigger optgroup (lines 681-694). Replace the old specific triggers with the new generic ones:

```php
// Cron-Based (Scheduled)
'cron:component_upcoming'  => 'Component Due Soon',
'cron:component_overdue'   => 'Component Overdue',
'cron:session_upcoming'    => 'Coaching Session Upcoming',
'cron:coaching_pre_end'    => 'Pre-Cycle-End No Session',
'cron:action_plan_24h'     => 'Action Plan Overdue (24h)',
'cron:session_notes_24h'   => 'Session Notes Overdue (24h)',
'cron:low_engagement_14d'  => 'Low Engagement (14d)',
'cron:client_success'      => 'Client Success Touchpoint',
```

- [ ] **Step 4: Add offset input UI to workflow form**

In the workflow form (after the trigger dropdown), add an offset input that shows conditionally for offset-based triggers:

```php
<tr class="hl-wf-offset-row" style="display:none;">
    <th scope="row"><?php esc_html_e( 'Offset', 'hl-core' ); ?></th>
    <td>
        <input type="number" name="trigger_offset_value" min="1" max="9999" value="<?php echo esc_attr( $offset_value ); ?>" style="width:80px;">
        <select name="trigger_offset_unit">
            <option value="minutes" <?php selected( $offset_unit, 'minutes' ); ?>>Minutes</option>
            <option value="hours" <?php selected( $offset_unit, 'hours' ); ?>>Hours</option>
            <option value="days" <?php selected( $offset_unit, 'days' ); ?>>Days</option>
        </select>
        <select name="trigger_offset_direction">
            <option value="before" <?php selected( $offset_dir, 'before' ); ?>>Before</option>
            <option value="after" <?php selected( $offset_dir, 'after' ); ?>>After</option>
        </select>
        <p class="description">How far before/after the anchor date to trigger this workflow.</p>
    </td>
</tr>
```

Add a component type filter dropdown that shows for `cron:component_upcoming` and `cron:component_overdue`:

```php
<tr class="hl-wf-component-type-row" style="display:none;">
    <th scope="row"><?php esc_html_e( 'Component Type', 'hl-core' ); ?></th>
    <td>
        <select name="component_type_filter">
            <option value="">All Component Types</option>
            <option value="learndash_course">Course</option>
            <option value="coaching_session_attendance">Coaching Session</option>
            <option value="classroom_visit">Classroom Visit</option>
            <option value="reflective_practice_session">Reflective Practice</option>
            <option value="self_reflection">Self-Reflection</option>
            <option value="teacher_self_assessment">Teacher Assessment</option>
            <option value="child_assessment">Child Assessment</option>
        </select>
    </td>
</tr>
```

- [ ] **Step 5: Add JS to show/hide offset fields**

In `assets/js/admin/email-workflow.js`, add a change handler for the trigger dropdown:

```javascript
var offsetTriggers = ['cron:component_upcoming', 'cron:component_overdue', 'cron:session_upcoming'];
var componentTypeTriggers = ['cron:component_upcoming', 'cron:component_overdue'];

$('select[name="trigger_key"]').on('change', function () {
    var val = $(this).val();
    $('.hl-wf-offset-row').toggle(offsetTriggers.indexOf(val) !== -1);
    $('.hl-wf-component-type-row').toggle(componentTypeTriggers.indexOf(val) !== -1);
}).trigger('change');
```

- [ ] **Step 6: Update save handler to persist offset**

In `handle_workflow_save()` (around line 900), add after the existing field processing:

```php
$offset_value = absint( $_POST['trigger_offset_value'] ?? 0 );
$offset_unit  = sanitize_text_field( $_POST['trigger_offset_unit'] ?? 'days' );
$offset_dir   = sanitize_text_field( $_POST['trigger_offset_direction'] ?? 'before' );

$multiplier = array( 'minutes' => 1, 'hours' => 60, 'days' => 1440 );
$mult       = $multiplier[ $offset_unit ] ?? 1440;
$data['trigger_offset_minutes'] = $offset_value > 0 ? $offset_value * $mult : null;

$data['component_type_filter'] = sanitize_text_field( $_POST['component_type_filter'] ?? '' ) ?: null;
```

Also update the `$valid_triggers` array in the validation block to include the new trigger keys and remove the old ones.

- [ ] **Step 7: Refactor `get_cron_trigger_users()` for `cron:component_upcoming`**

Replace the separate `cron:coaching_window_7d`, `cron:cv_window_7d`, and `cron:rp_window_7d` cases with a single `cron:component_upcoming` case. The offset comes from the workflow's `trigger_offset_minutes` column, and the date anchor is determined by `get_date_anchor()`.

```php
case 'cron:component_upcoming':
    $offset_minutes = (int) ( $workflow->trigger_offset_minutes ?? 10080 ); // default 7 days
    $offset_seconds = $offset_minutes * 60;
    $range_start    = wp_date( 'Y-m-d', time() );
    $range_end      = wp_date( 'Y-m-d', time() + $offset_seconds );

    $comp_type      = $workflow->component_type_filter ?? '';
    $anchor         = $this->get_date_anchor( 'upcoming', $comp_type );
    $col            = $anchor['column'];

    $type_clause = '';
    $type_params = array();
    if ( $comp_type !== '' ) {
        $type_clause = 'AND c.component_type = %s';
        $type_params = array( $comp_type );
    }

    $sql = "SELECT DISTINCT e.user_id, u.user_email
            FROM {$wpdb->prefix}hl_enrollment e
            JOIN {$wpdb->users} u ON u.ID = e.user_id
            JOIN {$wpdb->prefix}hl_pathway_assignment pa ON pa.enrollment_id = e.enrollment_id
            JOIN {$wpdb->prefix}hl_component c ON c.pathway_id = pa.pathway_id
            WHERE e.cycle_id = %d
              AND e.status IN ('active','warning')
              AND c.{$col} IS NOT NULL
              AND c.{$col} BETWEEN %s AND %s
              {$type_clause}";

    $params = array_merge( array( $cycle->cycle_id, $range_start, $range_end ), $type_params );
    $rows   = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
    break;
```

- [ ] **Step 8: Refactor `cron:component_overdue` similarly**

```php
case 'cron:component_overdue':
    $offset_minutes = (int) ( $workflow->trigger_offset_minutes ?? 1440 ); // default 1 day
    $offset_seconds = $offset_minutes * 60;
    $check_date     = wp_date( 'Y-m-d', time() - $offset_seconds );

    $comp_type      = $workflow->component_type_filter ?? '';
    $anchor         = $this->get_date_anchor( 'overdue', $comp_type );
    $col            = $anchor['column'];

    $type_clause = '';
    $type_params = array();
    if ( $comp_type !== '' ) {
        $type_clause = 'AND c.component_type = %s';
        $type_params = array( $comp_type );
    }

    $sql = "SELECT DISTINCT e.user_id, u.user_email
            FROM {$wpdb->prefix}hl_enrollment e
            JOIN {$wpdb->users} u ON u.ID = e.user_id
            JOIN {$wpdb->prefix}hl_pathway_assignment pa ON pa.enrollment_id = e.enrollment_id
            JOIN {$wpdb->prefix}hl_component c ON c.pathway_id = pa.pathway_id
            WHERE e.cycle_id = %d
              AND e.status IN ('active','warning')
              AND c.{$col} IS NOT NULL
              AND c.{$col} = %s
              {$type_clause}";

    $params = array_merge( array( $cycle->cycle_id, $check_date ), $type_params );
    $rows   = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
    break;
```

- [ ] **Step 9: Refactor `cron:session_upcoming` to use configurable offset**

Merge `cron:coaching_session_5d`, `cron:session_24h`, and `cron:session_1h` into one `cron:session_upcoming` case:

```php
case 'cron:session_upcoming':
    $offset_minutes = (int) ( $workflow->trigger_offset_minutes ?? 1440 ); // default 24h
    $now            = current_time( 'mysql' );
    $window_start   = wp_date( 'Y-m-d H:i:s', strtotime( $now ) + ( $offset_minutes * 60 ) - 1800 );
    $window_end     = wp_date( 'Y-m-d H:i:s', strtotime( $now ) + ( $offset_minutes * 60 ) + 1800 );

    $sql = "SELECT DISTINCT e.user_id, u.user_email
            FROM {$wpdb->prefix}hl_coaching_session cs
            JOIN {$wpdb->prefix}hl_enrollment e ON e.enrollment_id = cs.mentor_enrollment_id
            JOIN {$wpdb->users} u ON u.ID = e.user_id
            WHERE cs.cycle_id = %d
              AND cs.session_status = 'scheduled'
              AND cs.session_datetime BETWEEN %s AND %s
              AND e.status IN ('active','warning')";

    $rows = $wpdb->get_results( $wpdb->prepare( $sql, $cycle->cycle_id, $window_start, $window_end ) );
    break;
```

- [ ] **Step 10: Migrate existing workflows — update trigger keys**

Add a migration that converts old trigger keys to new ones and sets default offsets:

```php
private function migrate_workflow_trigger_keys() {
    global $wpdb;
    $table = $wpdb->prefix . 'hl_email_workflow';

    $mappings = array(
        'cron:cv_window_7d'        => array( 'cron:component_upcoming', 10080, 'classroom_visit' ),
        'cron:rp_window_7d'        => array( 'cron:component_upcoming', 10080, 'reflective_practice_session' ),
        'cron:coaching_window_7d'  => array( 'cron:component_upcoming', 10080, 'coaching_session_attendance' ),
        'cron:cv_overdue_1d'       => array( 'cron:component_overdue', 1440, 'classroom_visit' ),
        'cron:coaching_session_5d' => array( 'cron:session_upcoming', 7200, null ),
        'cron:session_24h'         => array( 'cron:session_upcoming', 1440, null ),
        'cron:session_1h'          => array( 'cron:session_upcoming', 60, null ),
    );

    foreach ( $mappings as $old_key => $new ) {
        $wpdb->update(
            $table,
            array(
                'trigger_key'            => $new[0],
                'trigger_offset_minutes' => $new[1],
                'component_type_filter'  => $new[2],
            ),
            array( 'trigger_key' => $old_key )
        );
    }
}
```

- [ ] **Step 11: Pass workflow object to `get_cron_trigger_users()`**

Currently the method signature is `get_cron_trigger_users( $trigger_key, $cycle )`. Change to:

```php
private function get_cron_trigger_users( $trigger_key, $cycle, $workflow = null ) {
```

Update the call site in `run_cron_workflow()` (around line 842) to pass `$workflow`.

- [ ] **Step 12: Run full test suite**

Run: `wp hl-core email-v2-test`
Expected: All tests pass. Some cron tests may need updating if they reference old trigger keys.

- [ ] **Step 13: Commit**

```bash
git add includes/class-hl-installer.php includes/services/class-hl-email-automation-service.php includes/admin/class-hl-admin-emails.php assets/js/admin/email-workflow.js
git commit -m "feat(email): configurable trigger offset (minutes/hours/days) + component type filter (A.3+A.4)"
```

---

### Task 8: UI sub-filter for coaching/RP status triggers (A.1)

**Files:**
- Modify: `includes/admin/class-hl-admin-emails.php` (add sub-filter dropdown)
- Modify: `assets/js/admin/email-workflow.js` (show/hide sub-filter, auto-generate condition)
- Modify: `includes/services/class-hl-email-automation-service.php:300-312` (no change needed — context already has `session.new_status`)

When "Coaching Session Status Changed" is selected as trigger, show a secondary dropdown: "Any", "Booked", "Attended", "Cancelled", "Missed", "Rescheduled". The selection auto-generates a condition `session.new_status eq <value>` in the workflow's conditions JSON.

- [ ] **Step 1: Add the sub-filter dropdown HTML**

In `includes/admin/class-hl-admin-emails.php`, after the trigger `<select>` (around line 700), add:

```php
<tr class="hl-wf-status-filter-row" style="display:none;">
    <th scope="row"><?php esc_html_e( 'Status Filter', 'hl-core' ); ?></th>
    <td>
        <select name="trigger_status_filter">
            <option value="">Any Status Change</option>
            <option value="scheduled">Session Booked</option>
            <option value="attended">Session Attended</option>
            <option value="cancelled">Session Cancelled</option>
            <option value="missed">Session Missed</option>
            <option value="rescheduled">Session Rescheduled</option>
        </select>
    </td>
</tr>
```

- [ ] **Step 2: Add JS visibility toggle**

In `assets/js/admin/email-workflow.js`, extend the trigger change handler:

```javascript
var statusFilterTriggers = ['hl_coaching_session_status_changed', 'hl_rp_session_status_changed'];

$('select[name="trigger_key"]').on('change', function () {
    var val = $(this).val();
    $('.hl-wf-status-filter-row').toggle(statusFilterTriggers.indexOf(val) !== -1);
    // existing offset/component-type toggles from Task 7...
}).trigger('change');
```

- [ ] **Step 3: Update save handler to inject condition from sub-filter**

In `handle_workflow_save()`, after conditions are parsed from JSON:

```php
$status_filter = sanitize_text_field( $_POST['trigger_status_filter'] ?? '' );
if ( $status_filter !== '' && in_array( $data['trigger_key'], array( 'hl_coaching_session_status_changed', 'hl_rp_session_status_changed' ), true ) ) {
    // Remove any existing session.new_status condition (to avoid duplicates)
    $conditions = array_filter( $conditions, function ( $c ) {
        return ( $c['field'] ?? '' ) !== 'session.new_status';
    } );
    // Add the sub-filter as a condition
    $conditions[] = array(
        'field' => 'session.new_status',
        'op'    => 'eq',
        'value' => $status_filter,
    );
    $data['conditions'] = wp_json_encode( array_values( $conditions ) );
}
```

- [ ] **Step 4: Pre-select the sub-filter on edit load**

When rendering the form for an existing workflow, extract the `session.new_status` condition value and pre-select it:

```php
$trigger_status_val = '';
if ( $workflow ) {
    $conds = json_decode( $workflow->conditions, true ) ?: array();
    foreach ( $conds as $c ) {
        if ( ( $c['field'] ?? '' ) === 'session.new_status' && ( $c['op'] ?? '' ) === 'eq' ) {
            $trigger_status_val = $c['value'] ?? '';
            break;
        }
    }
}
```

Use `$trigger_status_val` in the `<option selected>` checks.

- [ ] **Step 5: Commit**

```bash
git add includes/admin/class-hl-admin-emails.php assets/js/admin/email-workflow.js
git commit -m "feat(email): UI sub-filter for coaching/RP status triggers (A.1)"
```

---

### Task 9: "Coaching session not yet scheduled" condition (A.5)

**Files:**
- Modify: `includes/admin/class-hl-admin-emails.php:66-133` (add to `get_condition_fields()`)
- Modify: `includes/services/class-hl-email-condition-evaluator.php:70` (add special-case evaluation)
- Modify: `includes/services/class-hl-email-automation-service.php:473` (add to context hydration)

Currently this logic is embedded in the `cron:coaching_window_7d` SQL. We need it as a reusable condition so workflow authors can attach it to any trigger.

- [ ] **Step 1: Add `coaching.session_scheduled` to condition fields**

In `get_condition_fields()` (line 66), add a new field:

```php
'coaching.session_scheduled' => array(
    'label'   => 'Coaching Session Scheduled',
    'group'   => 'Coaching',
    'type'    => 'enum',
    'options' => array(
        'yes' => 'Yes — session exists',
        'no'  => 'No — no session scheduled',
    ),
),
```

- [ ] **Step 2: Hydrate `coaching.session_scheduled` in context**

In `hydrate_context()` (line 473), after the enrollment context is loaded, add:

```php
// Check if the user has a scheduled coaching session in this cycle
if ( ! empty( $context['enrollment_id'] ) && ! empty( $context['cycle_id'] ) ) {
    $has_session = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}hl_coaching_session
         WHERE mentor_enrollment_id = %d AND cycle_id = %d
           AND session_status IN ('scheduled', 'attended')",
        $context['enrollment_id'],
        $context['cycle_id']
    ) );
    $context['coaching'] = array_merge(
        $context['coaching'] ?? array(),
        array( 'session_scheduled' => $has_session > 0 ? 'yes' : 'no' )
    );
}
```

- [ ] **Step 3: No change needed in condition evaluator**

The evaluator already supports dot-path resolution (`coaching.session_scheduled`) and `eq`/`neq` operators. The value 'yes'/'no' will match the enum options. No special-case logic needed — the generic operator switch handles it.

- [ ] **Step 4: Commit**

```bash
git add includes/admin/class-hl-admin-emails.php includes/services/class-hl-email-automation-service.php
git commit -m "feat(email): coaching.session_scheduled condition field (A.5)"
```

---

## Phase 3: Builder Enhancements (parallelizable)

### Task 10: Merge tag dropdown for button URL fields (B.3)

**Files:**
- Modify: `assets/js/admin/email-builder.js:305-322,559-564`

- [ ] **Step 1: Create a reusable merge tag select builder function**

Add near the top of the `$(function() {` block:

```javascript
function buildMergeTagSelect(targetInput) {
    var $sel = $('<select class="hl-eb-merge-tag-url-select"><option value="">Insert tag...</option></select>');
    $.each(config.mergeTagsGrouped || {}, function (group, tags) {
        var $og = $('<optgroup label="' + escHtml(group) + '">');
        $.each(tags, function (key, label) {
            $og.append('<option value="{{' + key + '}}">' + escHtml(label) + '</option>');
        });
        $sel.append($og);
    });
    $sel.on('change', function () {
        var tag = $(this).val();
        if (!tag) return;
        var input = targetInput[0];
        var start = input.selectionStart || input.value.length;
        var val   = input.value;
        input.value = val.slice(0, start) + tag + val.slice(start);
        input.selectionStart = input.selectionEnd = start + tag.length;
        $(this).val('');
        targetInput.trigger('input');
    });
    return $sel;
}
```

- [ ] **Step 2: Add merge tag select to primary button blocks**

In the `case 'button':` block (around line 307), after the URL input is created:

```javascript
// After: var $url = $('<input type="text" placeholder="URL or {{merge_tag}}" ...>');
var $urlTagSelect = buildMergeTagSelect($url);
// Append $urlTagSelect next to $url in the button block's toolbar/row
```

- [ ] **Step 3: Add merge tag select to nested button blocks**

In `renderNestedContent()` (around line 561), after the nested button URL input:

```javascript
// After: var $u = $('<input type="text" placeholder="URL" ...>');
var $uTagSel = buildMergeTagSelect($u);
// Append $uTagSel next to $u
```

- [ ] **Step 4: Add CSS for the URL merge tag select**

In `assets/css/admin.css`:

```css
.hl-eb-merge-tag-url-select {
    font-size: 12px;
    padding: 2px 4px;
    margin-left: 6px;
    max-width: 160px;
    vertical-align: middle;
}
```

- [ ] **Step 5: Commit**

```bash
git add assets/js/admin/email-builder.js assets/css/admin.css
git commit -m "feat(email): merge tag dropdown for button URL fields (B.3)"
```

---

### Task 11: Expand preview context (B.2)

**Files:**
- Modify: `includes/admin/class-hl-admin-email-builder.php:531-598`

The preview populates `cycle_name`, `coach_name`, `coach_email`, and coaching session data, but NOT `partnership_name`, `school_name`, `school_district`, `pathway_name`, `coaching_schedule_url`, or `mentor_name`.

- [ ] **Step 1: Add partnership_name to preview context**

In `ajax_preview_render()`, after the cycle is loaded (around line 556), add:

```php
// Partnership name
if ( ! empty( $cycle->partnership_id ) ) {
    $partnership = $wpdb->get_row( $wpdb->prepare(
        "SELECT partnership_name FROM {$wpdb->prefix}hl_partnership WHERE partnership_id = %d",
        $cycle->partnership_id
    ) );
    $context['partnership_name'] = $partnership->partnership_name ?? '';
}
```

- [ ] **Step 2: Add school_name + school_district**

After enrollment is loaded (around line 542):

```php
// School
if ( ! empty( $enrollment->school_id ) ) {
    $school = $wpdb->get_row( $wpdb->prepare(
        "SELECT o.name, p.name AS parent_name
         FROM {$wpdb->prefix}hl_orgunit o
         LEFT JOIN {$wpdb->prefix}hl_orgunit p ON p.orgunit_id = o.parent_id
         WHERE o.orgunit_id = %d",
        $enrollment->school_id
    ) );
    $context['school_name']     = $school->name ?? '';
    $context['school_district'] = $school->parent_name ?? '';
}
```

- [ ] **Step 3: Add pathway_name**

```php
// Pathway
$pathway = $wpdb->get_row( $wpdb->prepare(
    "SELECT p.pathway_name FROM {$wpdb->prefix}hl_pathway_assignment pa
     JOIN {$wpdb->prefix}hl_pathway p ON p.pathway_id = pa.pathway_id
     WHERE pa.enrollment_id = %d LIMIT 1",
    $enrollment->enrollment_id
) );
$context['pathway_name'] = $pathway->pathway_name ?? '';
```

- [ ] **Step 4: Add coaching_schedule_url and mentor_name**

```php
// Coaching schedule URL
$context['coaching_schedule_url'] = home_url( '/schedule-session/' );

// Mentor name (the recipient IS the mentor in most coaching workflows)
$context['mentor_name'] = $context['recipient_name'] ?? '';
```

- [ ] **Step 5: Fix old_session_date and cancelled_by_name when real session exists**

Currently these are only set in the fallback (no-session) path. Add them after line 588 (real session path):

```php
// Always set reschedule/cancel sample data for preview completeness
$context['old_session_date']  = $context['session_date'] ?? 'June 15, 2026 at 2:00 PM';
$context['cancelled_by_name'] = $context['cancelled_by_name'] ?? 'Sample User';
```

- [ ] **Step 6: Commit**

```bash
git add includes/admin/class-hl-admin-email-builder.php
git commit -m "fix(email): populate all merge tags in preview context (B.2)"
```

---

### Task 12: Draft save UX improvement

**Files:**
- Modify: `assets/js/admin/email-builder.js:40-56,988-1004`

The draft saves correctly to the DB but the user doesn't realize the page loads the published version and requires a manual "Restore" click.

- [ ] **Step 1: Auto-restore draft on page load**

Replace the banner click handler (lines 40-56) with auto-restore logic:

```javascript
// Auto-restore draft if one exists
if (config.draftData) {
    try {
        var draft = typeof config.draftData === 'string' ? JSON.parse(config.draftData) : config.draftData;
        if (draft.blocks && draft.blocks.length > 0) {
            blocks = draft.blocks;
            if (draft.subject) $('#hl-eb-subject').val(draft.subject);
            if (draft.name) $('#hl-eb-name').val(draft.name);
            renderAllBlocks();
            $('#hl-draft-banner').show().find('.hl-draft-banner-text')
                .text('Draft restored. Click Save to publish your changes.');
        }
    } catch (e) {
        // Invalid draft data — ignore silently
    }
}

$('#hl-discard-draft').on('click', function () {
    // Reload page without draft
    $.post(config.ajaxUrl, {
        action: 'hl_email_template_discard_draft',
        template_id: config.templateId || 'new',
        _wpnonce: config.nonce
    });
    location.reload();
});
```

- [ ] **Step 2: Add discard-draft AJAX handler**

In `includes/admin/class-hl-admin-email-builder.php`, add:

```php
public function ajax_discard_draft() {
    check_ajax_referer( 'hl_email_builder', '_wpnonce' );
    if ( ! current_user_can( 'manage_hl_core' ) ) wp_send_json_error();
    $template_id = sanitize_text_field( $_POST['template_id'] ?? 'new' );
    delete_option( 'hl_email_draft_' . get_current_user_id() . '_' . $template_id );
    wp_send_json_success();
}
```

Register the AJAX action in the constructor or init method.

- [ ] **Step 3: Update "Draft saved" message**

In `doAutosave()` success callback (around line 1001):

```javascript
// OLD:
$('#hl-eb-status').text('Draft saved');

// NEW:
$('#hl-eb-status').text('Draft auto-saved (click Save to publish)');
```

- [ ] **Step 4: Commit**

```bash
git add assets/js/admin/email-builder.js includes/admin/class-hl-admin-email-builder.php
git commit -m "fix(email): auto-restore draft on page load, improve save messaging"
```

---

## Phase 4: Admin UX

### Task 13: Workflow folders/groups (A.7)

**Files:**
- Modify: `includes/class-hl-installer.php` (add `folder` column)
- Modify: `includes/admin/class-hl-admin-emails.php:485-548` (folder filter + grouped list)
- Modify: `assets/js/admin/email-workflow.js` (folder input)

- [ ] **Step 1: Add `folder` column to `hl_email_workflow`**

In the schema (line 2149) and as a migration:

```sql
folder varchar(100) DEFAULT NULL COMMENT 'Workflow group/folder for organization',
```

Migration:

```php
private function migrate_workflow_add_folder_col() {
    global $wpdb;
    $table = $wpdb->prefix . 'hl_email_workflow';
    $col   = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'folder'" );
    if ( empty( $col ) ) {
        $wpdb->query( "ALTER TABLE {$table} ADD COLUMN folder varchar(100) DEFAULT NULL COMMENT 'Workflow group/folder for organization' AFTER name" );
    }
}
```

- [ ] **Step 2: Add folder input to workflow form**

After the Name field:

```php
<tr>
    <th scope="row"><label for="wf-folder"><?php esc_html_e( 'Folder', 'hl-core' ); ?></label></th>
    <td>
        <input type="text" id="wf-folder" name="folder" value="<?php echo esc_attr( $workflow->folder ?? '' ); ?>"
               list="hl-wf-folders" placeholder="e.g. Coaching Notifications" style="width:300px;">
        <datalist id="hl-wf-folders">
            <?php foreach ( $existing_folders as $f ) : ?>
                <option value="<?php echo esc_attr( $f ); ?>">
            <?php endforeach; ?>
        </datalist>
        <p class="description">Group related workflows together. Type a new name or pick an existing folder.</p>
    </td>
</tr>
```

Query existing folders before rendering:

```php
$existing_folders = $wpdb->get_col( "SELECT DISTINCT folder FROM {$wpdb->prefix}hl_email_workflow WHERE folder IS NOT NULL AND folder != '' ORDER BY folder" );
```

- [ ] **Step 3: Save folder in `handle_workflow_save()`**

```php
$data['folder'] = sanitize_text_field( $_POST['folder'] ?? '' ) ?: null;
```

- [ ] **Step 4: Add folder filter pills to list page**

Before the workflow table (around line 485), after the status filters:

```php
// Folder filter
$folders = $wpdb->get_col( "SELECT DISTINCT folder FROM {$wpdb->prefix}hl_email_workflow WHERE folder IS NOT NULL AND folder != '' ORDER BY folder" );
if ( ! empty( $folders ) ) :
    $folder_filter = sanitize_text_field( $_GET['folder'] ?? '' );
    echo '<div class="hl-filter-pills" style="margin-bottom:10px;">';
    echo '<a href="' . esc_url( remove_query_arg( 'folder' ) ) . '" class="hl-pill' . ( $folder_filter === '' ? ' active' : '' ) . '">All Folders</a>';
    foreach ( $folders as $f ) :
        echo '<a href="' . esc_url( add_query_arg( 'folder', $f ) ) . '" class="hl-pill' . ( $folder_filter === $f ? ' active' : '' ) . '">' . esc_html( $f ) . '</a>';
    endforeach;
    echo '</div>';
endif;
```

- [ ] **Step 5: Filter the workflow query by folder**

Update the workflow list query to include a folder WHERE clause when `$folder_filter` is set:

```php
$where_clauses[] = $folder_filter !== '' ? $wpdb->prepare( 'AND folder = %s', $folder_filter ) : '';
```

- [ ] **Step 6: Group workflows by folder in the list**

When no folder filter is active, add folder header rows to visually group workflows:

```php
$current_folder = null;
foreach ( $workflows as $wf ) :
    $wf_folder = $wf->folder ?: 'Uncategorized';
    if ( $wf_folder !== $current_folder ) :
        $current_folder = $wf_folder;
        echo '<tr class="hl-wf-folder-header"><td colspan="6"><strong>' . esc_html( $current_folder ) . '</strong></td></tr>';
    endif;
    // ... existing row rendering ...
endforeach;
```

Add ORDER BY `folder ASC, name ASC` to the query.

- [ ] **Step 7: Add CSS for folder headers**

```css
.hl-wf-folder-header td {
    background: #f0f0f1;
    padding: 8px 12px;
    font-size: 13px;
    border-bottom: 2px solid var(--hl-primary, #1A2B47);
}
```

- [ ] **Step 8: Commit**

```bash
git add includes/class-hl-installer.php includes/admin/class-hl-admin-emails.php assets/css/admin.css
git commit -m "feat(email): workflow folders/groups for organization (A.7)"
```

---

## Post-Implementation Checklist

- [ ] Run full test suite: `wp hl-core email-v2-test` — all assertions pass
- [ ] Run smoke test: `wp hl-core smoke-test` — no new failures
- [ ] Deploy to test server
- [ ] Browser test: workflow form renders new fields (offset, component type filter, status sub-filter, folder)
- [ ] Browser test: email builder merge tags show "Copied!" on click
- [ ] Browser test: button URL has merge tag dropdown
- [ ] Browser test: dark mode preview shows readable text
- [ ] Browser test: draft auto-restores on page refresh
- [ ] Update STATUS.md + README.md
- [ ] Commit docs
