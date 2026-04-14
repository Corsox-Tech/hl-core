# Email V2 — Post-Demo Feedback Fixes (v2 — Revised after code review)

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

> **Follow-up note:** 17 SQL queries use `IN ('active','warning')` — if `warning` becomes a valid status later, re-add it to this dropdown.

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

> **Client compatibility note:** This fix applies to the admin preview iframe and email clients that support `<style>` blocks (Apple Mail, iOS Mail). Gmail/Outlook.com strip `<style>` blocks entirely, but they also don't apply dark mode, so text stays readable on their white backgrounds.

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

- [ ] **Step 1: Add visual feedback + clipboard fallback + double-click guard**

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

    // Double-click guard — prevent re-entry while "Copied!" is showing.
    if ($el.hasClass('hl-eb-tag-copied')) return;

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

// Clipboard fallback using deprecated document.execCommand('copy').
// Required for: older Safari, non-HTTPS contexts, iframe sandboxes.
// TODO: Remove when Clipboard API coverage reaches 100% of target browsers.
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

In `assets/css/admin.css`, add after the `.hl-eb-tag-item:hover` styles (after line 1777):

```css
.hl-eb-tag-item.hl-eb-tag-copied {
    background: var(--hl-a-accent, #2ECC71);
    color: #fff;
    border-color: var(--hl-a-accent, #2ECC71);
}
```

> **CSS variable note:** The admin design system uses `--hl-a-*` namespace (defined in `assets/css/admin.css:10-48`). There is no `--hl-a-success` token; use `--hl-a-accent` (#2ECC71) which is the green used throughout admin for positive states.

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
- Modify: `includes/services/class-hl-email-queue-processor.php` (find the `wp_mail()` call at line 215)
- Create: test assertion in `bin/test-email-v2-track3.php`

Add a temporary safety gate that only allows emails to `@housmanlearning.com`, `@corsox.com`, and `@yopmail.com`. This is a pre-send check, not a queue-insertion check — emails still get queued and marked as sent, but `wp_mail()` is only called for allowed domains.

- [ ] **Step 1: Add the domain allowlist check before wp_mail()**

In `includes/services/class-hl-email-queue-processor.php`, add immediately before the `wp_mail()` call at line 215 (inside `process_single()`):

```php
// Temporary safety gate — restrict sends to internal domains during testing.
// Remove this gate once email system is fully validated.
$allowed_domains = array( 'housmanlearning.com', 'corsox.com', 'yopmail.com' );
$recipient_domain = substr( strrchr( $row->recipient_email, '@' ), 1 );
if ( ! in_array( strtolower( $recipient_domain ), $allowed_domains, true ) ) {
    $this->log_blocked_send( $row->queue_id, $row->recipient_email, 'domain_not_allowed' );
    // Mark as blocked to prevent retry loops, but don't actually send.
    $wpdb->update(
        $table,
        array( 'status' => 'blocked', 'sent_at' => gmdate( 'Y-m-d H:i:s' ) ),
        array( 'queue_id' => $row->queue_id ),
        array( '%s', '%s' ),
        array( '%d' )
    );
    return;
}
```

> **Column name note:** The correct column is `sent_at` (not `processed_at`). Verified at `class-hl-email-queue-processor.php:222` where the success path uses `'sent_at' => gmdate( 'Y-m-d H:i:s' )`.

> **Flow note:** `process_single()` handles one row at a time and returns void — use `return`, not `continue` (we are not in the `foreach` loop here).

- [ ] **Step 2: Add the log_blocked_send helper method**

Add to the queue processor class:

```php
/**
 * Log a blocked send event to the audit log.
 *
 * @param int    $queue_id Queue row ID.
 * @param string $to       Recipient email.
 * @param string $reason   Block reason.
 */
private function log_blocked_send( $queue_id, $to, $reason ) {
    if ( class_exists( 'HL_Audit_Service' ) ) {
        HL_Audit_Service::log( 'email_send_blocked', array(
            'entity_type' => 'email_queue',
            'entity_id'   => $queue_id,
            'email'       => $to,
            'reason'      => $reason,
        ) );
    }
}
```

> **Audit log format note:** The codebase uses `HL_Audit_Service::log( $event_name, $data_array )` — a 2-argument format. See `class-hl-email-queue-processor.php:235` and `class-hl-email-automation-service.php:972` for existing examples.

- [ ] **Step 3: Verify 'blocked' is a valid status value**

The `hl_email_queue.status` column is `varchar(20)` (verified at `class-hl-installer.php:2182`), NOT an ENUM. No ALTER TABLE needed — `'blocked'` will be accepted as-is.

- [ ] **Step 4: Add `is_domain_allowed()` public method for testability**

Extract the inline check into a public method:

```php
/**
 * Check if an email domain is on the temporary allowlist.
 *
 * @param string $email Email address.
 * @return bool True if the domain is allowed.
 */
public function is_domain_allowed( $email ) {
    $allowed_domains = array( 'housmanlearning.com', 'corsox.com', 'yopmail.com' );
    $domain = substr( strrchr( $email, '@' ), 1 );
    return in_array( strtolower( $domain ), $allowed_domains, true );
}
```

Update the inline check in Step 1 to call this method instead of duplicating logic:

```php
if ( ! $this->is_domain_allowed( $row->recipient_email ) ) {
```

- [ ] **Step 5: Add test assertion**

Add to the email-v2-test deliverability group:

```php
// Domain allowlist blocks external addresses
$processor = HL_Email_Queue_Processor::instance();
$allowed   = $processor->is_domain_allowed( 'test@housmanlearning.com' );
$blocked   = $processor->is_domain_allowed( 'user@gmail.com' );
$this->assert_true( $allowed === true, 'housmanlearning.com is allowed' );
$this->assert_true( $blocked === false, 'gmail.com is blocked' );
```

> **Singleton note:** Use `HL_Email_Queue_Processor::instance()` not `new HL_Email_Queue_Processor()`. The constructor is private (verified at `class-hl-email-queue-processor.php:39`).

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
- Modify: `includes/services/class-hl-email-automation-service.php:711-717,764-769`

Add date bounds to the active-cycle query so emails don't fire for cycles that haven't started or have already ended.

- [ ] **Step 1: Update run_daily_checks() cycle query**

In `includes/services/class-hl-email-automation-service.php`, replace lines 711-717. The key issue: `$today` does NOT exist at line 716 — it is defined later inside individual switch cases. Create a NEW variable before the cycle query.

```php
// OLD (lines 711-717):
public function run_daily_checks() {
    global $wpdb;

    // Load all active cycles.
    $cycles = $wpdb->get_results(
        "SELECT * FROM {$wpdb->prefix}hl_cycle WHERE status = 'active'"
    );

// NEW:
public function run_daily_checks() {
    global $wpdb;

    // Date-gate: only load cycles whose date range brackets today.
    $today_daily = wp_date( 'Y-m-d' );
    $cycles = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}hl_cycle
         WHERE status = 'active'
           AND start_date <= %s
           AND (end_date IS NULL OR end_date >= %s)",
        $today_daily,
        $today_daily
    ) );
```

- [ ] **Step 2: Update run_hourly_checks() cycle query**

Same issue in `run_hourly_checks()`: `$today` does not exist at line 767. Create a new variable.

```php
// OLD (lines 764-769):
public function run_hourly_checks() {
    global $wpdb;

    $cycles = $wpdb->get_results(
        "SELECT * FROM {$wpdb->prefix}hl_cycle WHERE status = 'active'"
    );

// NEW:
public function run_hourly_checks() {
    global $wpdb;

    $today_hourly = wp_date( 'Y-m-d' );
    $cycles = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}hl_cycle
         WHERE status = 'active'
           AND start_date <= %s
           AND (end_date IS NULL OR end_date >= %s)",
        $today_hourly,
        $today_hourly
    ) );
```

- [ ] **Step 3: Verify via direct query on test server**

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
- Check: `includes/cli/class-hl-cli-email-v2-test.php:165-193` (references `available_from`)
- Test: `wp hl-core email-v2-test --only=cron`

**Date anchor mapping (hardcoded per component type):**

| Component Type | "Opens/Upcoming" Anchor | "Overdue/Past" Anchor |
|---|---|---|
| `coaching_session_attendance` | `display_window_start` | `display_window_end` |
| All others | `complete_by` | `complete_by` |

- [ ] **Step 1: Define the date anchor helper method**

Add to `class-hl-email-automation-service.php`, above `get_cron_trigger_users()` (before line 938):

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

- [ ] **Step 6: Update the `has_component_window_column()` guard**

The `has_component_window_column()` guard at line 949 checks for `available_from`. After rewiring, the triggers that referenced it now use `complete_by` and `display_window_start`, which are core columns. Update the guard:

```php
// OLD (lines 942-956):
$needs_window_col = in_array( $trigger_key, array(
    'cron:cv_window_7d',
    'cron:cv_overdue_1d',
    'cron:rp_window_7d',
    'cron:coaching_window_7d',
), true );

if ( $needs_window_col && ! self::has_component_window_column() ) {
    static $warned = false;
    if ( ! $warned ) {
        $warned = true;
        error_log( '[HL_EMAIL_V2] cron trigger ' . $trigger_key . ' skipped — hl_component.available_from column missing. Run HL_Installer::maybe_upgrade().' );
    }
    return array();
}

// NEW — Remove this entire block. The triggers now use complete_by and
// display_window_start, both of which are core columns that always exist.
// The guard was only needed for the Rev 35 available_from/available_to columns.
```

- [ ] **Step 7: Remove Submission Window UI from component form**

In `includes/admin/class-hl-admin-pathways.php`, remove or comment out lines 1913-1925 (the entire `<tr>` block for "Submission Window" including both date inputs).

- [ ] **Step 8: Remove save handler for available_from/available_to**

In the same file, remove lines 402-414 (the `$raw_af`/`$raw_at` parsing and `$data['available_from']`/`$data['available_to']` assignment). Keep the column in the DB schema for now — data migration to drop columns comes later.

- [ ] **Step 9: Update CLI test harness**

In `includes/cli/class-hl-cli-email-v2-test.php`, update lines 165-193 (`test_schema()` method). The `available_from`/`available_to` column assertions should remain but become informational — they verify the Rev 35 columns still exist (we're keeping them in the DB for now, just not using them in triggers):

```php
// Change assertion messages to clarify the columns are legacy but still present:
// 'hl_component.available_from column exists with DATE type (legacy — triggers now use complete_by)'
```

Also update lines 254-258 in `test_cron()` — the old trigger keys still work (they're not removed yet, Task 7 will alias them):

```php
$triggers = array(
    'cron:cv_window_7d',
    'cron:cv_overdue_1d',
    'cron:rp_window_7d',
    'cron:coaching_window_7d',
    'cron:coaching_pre_end',
);
```

No change needed here until Task 7 renames the keys.

- [ ] **Step 10: Run tests**

Run: `wp hl-core email-v2-test`
Expected: All existing tests pass.

- [ ] **Step 11: Verify on test server with South Haven data**

```bash
wp db query "SELECT c.component_id, c.title, c.component_type, c.complete_by, c.display_window_start FROM wp_hl_component c JOIN wp_hl_pathway p ON c.pathway_id = p.pathway_id WHERE p.cycle_id = 5 AND c.complete_by BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)"
```
Expected: Block 2 components with `complete_by = 2026-04-21` should appear (if run within 7 days of April 21).

- [ ] **Step 12: Commit**

```bash
git add includes/services/class-hl-email-automation-service.php includes/admin/class-hl-admin-pathways.php includes/cli/class-hl-cli-email-v2-test.php
git commit -m "refactor(email): rewire triggers to complete_by/display_window_start, remove Submission Window UI (A.4+A.6)"
```

---

### Task 7: Configurable trigger offset with minutes/hours/days (A.3)

**Files:**
- Modify: `includes/class-hl-installer.php` (add columns + migration, assign Rev 39)
- Modify: `includes/services/class-hl-email-automation-service.php` (parameterize SQL)
- Modify: `includes/admin/class-hl-admin-emails.php` (add offset UI, refactor trigger keys)
- Modify: `assets/js/admin/email-workflow.js` (offset input visibility)
- Modify: `includes/cli/class-hl-cli-email-v2-test.php` (update trigger keys)
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

**Pre-migration safety:** Pause all active cron workflows before deploying this migration. After migration completes and new code is live, reactivate. This prevents dedup tokens from firing under both old and new keys during the rollover window.

- [ ] **Step 1: Add `trigger_offset_minutes` and `component_type_filter` columns + complete_by index (Rev 39)**

In `includes/class-hl-installer.php`, add ONE combined migration method (following the Rev 35 pattern with per-column SHOW COLUMNS guards):

```php
/**
 * Rev 39: Email v2 — configurable trigger offset + component type filter.
 * Adds trigger_offset_minutes + component_type_filter to hl_email_workflow,
 * and idx_complete_by to hl_component for efficient date-range queries.
 */
private static function migrate_workflow_trigger_offset() {
    global $wpdb;

    $workflow_table  = $wpdb->prefix . 'hl_email_workflow';
    $component_table = $wpdb->prefix . 'hl_component';

    $column_exists = function ( $table_name, $column_name ) use ( $wpdb ) {
        return ! empty( $wpdb->get_var( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s
             LIMIT 1",
            $table_name, $column_name
        ) ) );
    };

    $index_exists = function ( $table_name, $index_name ) use ( $wpdb ) {
        return ! empty( $wpdb->get_var( $wpdb->prepare(
            "SELECT INDEX_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s
             LIMIT 1",
            $table_name, $index_name
        ) ) );
    };

    // Add trigger_offset_minutes column.
    if ( ! $column_exists( $workflow_table, 'trigger_offset_minutes' ) ) {
        $wpdb->query( "ALTER TABLE `{$workflow_table}` ADD COLUMN trigger_offset_minutes int DEFAULT NULL COMMENT 'Configurable offset for cron triggers (in minutes)' AFTER delay_minutes" );
    }

    // Add component_type_filter column.
    if ( ! $column_exists( $workflow_table, 'component_type_filter' ) ) {
        $wpdb->query( "ALTER TABLE `{$workflow_table}` ADD COLUMN component_type_filter varchar(100) DEFAULT NULL COMMENT 'Component type filter for cron triggers' AFTER trigger_offset_minutes" );
    }

    // Add index on complete_by for date-range queries.
    if ( ! $index_exists( $component_table, 'idx_complete_by' ) ) {
        $wpdb->query( "ALTER TABLE `{$component_table}` ADD INDEX idx_complete_by (complete_by)" );
    }

    // Migrate old trigger keys to new generic keys with default offsets.
    $mappings = array(
        'cron:cv_window_7d'        => array( 'cron:component_upcoming', 10080, 'classroom_visit' ),
        'cron:rp_window_7d'        => array( 'cron:component_upcoming', 10080, 'reflective_practice_session' ),
        'cron:coaching_window_7d'  => array( 'cron:component_upcoming', 10080, 'coaching_session_attendance' ),
        'cron:cv_overdue_1d'       => array( 'cron:component_overdue',  1440,  'classroom_visit' ),
        'cron:coaching_session_5d' => array( 'cron:session_upcoming',   7200,  null ),
        'cron:session_24h'         => array( 'cron:session_upcoming',   1440,  null ),
        'cron:session_1h'          => array( 'cron:session_upcoming',   60,    null ),
    );

    foreach ( $mappings as $old_key => $new ) {
        $wpdb->update(
            $workflow_table,
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

Register in `maybe_upgrade()` and bump `$current_revision` to 39:

```php
// At the top of maybe_upgrade():
$current_revision = 39;

// Add before update_option:
// Rev 39: Email v2 — configurable trigger offset + component type filter.
if ( (int) $stored < 39 ) {
    self::migrate_workflow_trigger_offset();
}
```

Also add `trigger_offset_minutes` and `component_type_filter` to the `hl_email_workflow` CREATE TABLE in `get_schema()` (after `delay_minutes` at line 2156):

```sql
trigger_offset_minutes int DEFAULT NULL COMMENT 'Configurable offset for cron triggers (in minutes)',
component_type_filter varchar(100) DEFAULT NULL COMMENT 'Component type filter for cron triggers',
```

- [ ] **Step 2: Update trigger key options in admin form**

In `includes/admin/class-hl-admin-emails.php`, update the cron trigger optgroup (lines 681-694). Replace the old specific triggers with the new generic ones:

```php
// OLD (lines 681-694):
$cron_triggers = array(
    'cron:cv_window_7d'         => 'CV Window Opens (7d)',
    'cron:cv_overdue_1d'        => 'CV Overdue (1d)',
    'cron:rp_window_7d'         => 'RP Window Opens (7d)',
    'cron:coaching_window_7d'   => 'Coaching Window (7d)',
    'cron:coaching_session_5d'  => 'Session in 5 Days',
    'cron:coaching_pre_end'     => 'Pre-Cycle-End No Session',
    'cron:action_plan_24h'      => 'Action Plan Overdue (24h)',
    'cron:session_notes_24h'    => 'Session Notes Overdue (24h)',
    'cron:low_engagement_14d'   => 'Low Engagement (14d)',
    'cron:client_success'       => 'Client Success Touchpoint',
    'cron:session_24h'          => 'Session in 24 Hours',
    'cron:session_1h'           => 'Session in 1 Hour',
);

// NEW:
$cron_triggers = array(
    'cron:component_upcoming'  => 'Component Due Soon',
    'cron:component_overdue'   => 'Component Overdue',
    'cron:session_upcoming'    => 'Coaching Session Upcoming',
    'cron:coaching_pre_end'    => 'Pre-Cycle-End No Session',
    'cron:action_plan_24h'     => 'Action Plan Overdue (24h)',
    'cron:session_notes_24h'   => 'Session Notes Overdue (24h)',
    'cron:low_engagement_14d'  => 'Low Engagement (14d)',
    'cron:client_success'      => 'Client Success Touchpoint',
);
```

- [ ] **Step 3: Add offset input UI to workflow form (NO direction dropdown)**

In the workflow form (after the trigger `<select>`, around line 700), add an offset input. The "Before/After" direction dropdown is NOT needed — semantics are baked into the trigger type: "upcoming" = before due date, "overdue" = after due date.

```php
<?php
// Compute current offset value + unit for display.
$offset_raw   = (int) ( $workflow->trigger_offset_minutes ?? 0 );
$offset_value = 0;
$offset_unit  = 'days';
if ( $offset_raw > 0 ) {
    if ( $offset_raw % 1440 === 0 ) {
        $offset_value = $offset_raw / 1440;
        $offset_unit  = 'days';
    } elseif ( $offset_raw % 60 === 0 ) {
        $offset_value = $offset_raw / 60;
        $offset_unit  = 'hours';
    } else {
        $offset_value = $offset_raw;
        $offset_unit  = 'minutes';
    }
}
?>
<tr class="hl-wf-offset-row" style="display:none;">
    <th scope="row"><?php esc_html_e( 'Offset', 'hl-core' ); ?></th>
    <td>
        <input type="number" name="trigger_offset_value" min="1" max="9999" value="<?php echo esc_attr( $offset_value ); ?>" style="width:80px;">
        <select name="trigger_offset_unit">
            <option value="minutes" <?php selected( $offset_unit, 'minutes' ); ?>>Minutes</option>
            <option value="hours" <?php selected( $offset_unit, 'hours' ); ?>>Hours</option>
            <option value="days" <?php selected( $offset_unit, 'days' ); ?>>Days</option>
        </select>
        <p class="description">How far before the anchor date (for "upcoming") or after (for "overdue") to trigger this workflow.</p>
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
            <option value="learndash_course" <?php selected( $workflow->component_type_filter ?? '', 'learndash_course' ); ?>>Course</option>
            <option value="coaching_session_attendance" <?php selected( $workflow->component_type_filter ?? '', 'coaching_session_attendance' ); ?>>Coaching Session</option>
            <option value="classroom_visit" <?php selected( $workflow->component_type_filter ?? '', 'classroom_visit' ); ?>>Classroom Visit</option>
            <option value="reflective_practice_session" <?php selected( $workflow->component_type_filter ?? '', 'reflective_practice_session' ); ?>>Reflective Practice</option>
            <option value="self_reflection" <?php selected( $workflow->component_type_filter ?? '', 'self_reflection' ); ?>>Self-Reflection</option>
            <option value="teacher_self_assessment" <?php selected( $workflow->component_type_filter ?? '', 'teacher_self_assessment' ); ?>>Teacher Assessment</option>
            <option value="child_assessment" <?php selected( $workflow->component_type_filter ?? '', 'child_assessment' ); ?>>Child Assessment</option>
        </select>
    </td>
</tr>
```

- [ ] **Step 4: Add JS to show/hide offset fields**

In `assets/js/admin/email-workflow.js`, merge into the EXISTING trigger change handler at line 382. The current handler is:

```javascript
// EXISTING (line 382-386):
$triggerSelect.on('change', function () {
    applyTriggerVisibility($wrap, $(this).val());
    serializeRecipients($wrap, $textarea);
    scheduleRecipientCount($wrap);
});
```

Replace with:

```javascript
$triggerSelect.on('change', function () {
    var val = $(this).val();
    applyTriggerVisibility($wrap, val);
    serializeRecipients($wrap, $textarea);
    scheduleRecipientCount($wrap);

    // Task 7: show/hide offset and component type fields.
    var offsetTriggers = ['cron:component_upcoming', 'cron:component_overdue', 'cron:session_upcoming'];
    var componentTypeTriggers = ['cron:component_upcoming', 'cron:component_overdue'];
    $('.hl-wf-offset-row').toggle(offsetTriggers.indexOf(val) !== -1);
    $('.hl-wf-component-type-row').toggle(componentTypeTriggers.indexOf(val) !== -1);

    // Task 8: show/hide status sub-filter.
    var statusFilterTriggers = ['hl_coaching_session_status_changed', 'hl_rp_session_status_changed'];
    $('.hl-wf-status-filter-row').toggle(statusFilterTriggers.indexOf(val) !== -1);
}).trigger('change');
```

> **Important:** Add `.trigger('change')` at the end so the visibility state initializes on page load.

- [ ] **Step 5: Update save handler to persist offset**

In `handle_workflow_save()` (around line 955), add after the existing field processing:

```php
$offset_value = absint( $_POST['trigger_offset_value'] ?? 0 );
$offset_unit  = sanitize_text_field( $_POST['trigger_offset_unit'] ?? 'days' );

$multiplier = array( 'minutes' => 1, 'hours' => 60, 'days' => 1440 );
$mult       = $multiplier[ $offset_unit ] ?? 1440;
$data['trigger_offset_minutes'] = $offset_value > 0 ? $offset_value * $mult : null;

$data['component_type_filter'] = sanitize_text_field( $_POST['component_type_filter'] ?? '' ) ?: null;
```

- [ ] **Step 6: Update $valid_triggers array**

In `handle_workflow_save()`, replace lines 958-969 with the updated array that includes both new keys and old keys as fallthrough aliases:

```php
// Validate trigger_key against allowed list.
$valid_triggers = array(
    'user_register', 'hl_enrollment_created', 'hl_pathway_assigned',
    'hl_learndash_course_completed', 'hl_pathway_completed',
    'hl_coaching_session_created', 'hl_coaching_session_status_changed',
    'hl_rp_session_created', 'hl_rp_session_status_changed',
    'hl_classroom_visit_submitted', 'hl_teacher_assessment_submitted',
    'hl_child_assessment_submitted', 'hl_coach_assigned',
    // New generic keys (v2):
    'cron:component_upcoming', 'cron:component_overdue', 'cron:session_upcoming',
    // Retained non-offset keys:
    'cron:coaching_pre_end',
    'cron:action_plan_24h', 'cron:session_notes_24h', 'cron:low_engagement_14d',
    'cron:client_success',
    // Legacy aliases — kept for backward compat until next release:
    'cron:cv_window_7d', 'cron:cv_overdue_1d', 'cron:rp_window_7d',
    'cron:coaching_window_7d', 'cron:coaching_session_5d',
    'cron:session_24h', 'cron:session_1h',
);
if ( ! in_array( $data['trigger_key'], $valid_triggers, true ) ) {
    wp_redirect( admin_url( 'admin.php?page=hl-emails&tab=workflows&hl_notice=invalid_trigger' ) );
    exit;
}
```

- [ ] **Step 7: Update $daily_triggers array in run_daily_checks()**

In `run_daily_checks()`, replace lines 723-734 with the updated array that includes both new and old keys as fallthrough:

```php
$daily_triggers = array(
    // New generic keys:
    'cron:component_upcoming',
    'cron:component_overdue',
    // Note: cron:session_upcoming is intentionally NOT here — it runs hourly
    // because session reminders (e.g., 1h before) need sub-day precision.
    // See $hourly_triggers in run_hourly_checks().
    // Retained non-offset keys:
    'cron:coaching_pre_end',
    'cron:action_plan_24h',
    'cron:session_notes_24h',
    'cron:low_engagement_14d',
    'cron:client_success',
    // Legacy aliases (kept until next release for in-flight workflows):
    'cron:cv_window_7d',
    'cron:cv_overdue_1d',
    'cron:rp_window_7d',
    'cron:coaching_window_7d',
    'cron:coaching_session_5d',
);
```

- [ ] **Step 8: Update $hourly_triggers array in run_hourly_checks()**

In `run_hourly_checks()`, replace lines 774-777:

```php
$hourly_triggers = array(
    'cron:session_upcoming',
    // Legacy aliases (kept until next release):
    'cron:session_24h',
    'cron:session_1h',
);
```

- [ ] **Step 9: Add SQL column whitelist to get_cron_trigger_users()**

For the new generic triggers, the date column comes from workflow config. Add a whitelist to prevent SQL injection:

```php
// Inside the new cron:component_upcoming and cron:component_overdue cases,
// immediately after $col = $anchor['column']:
$allowed_cols = array( 'complete_by', 'display_window_start', 'display_window_end' );
if ( ! in_array( $col, $allowed_cols, true ) ) {
    return array();
}
```

- [ ] **Step 10: Pass $workflow to get_cron_trigger_users()**

Change the method signature from:

```php
// OLD (line 938):
private function get_cron_trigger_users( $trigger_key, $cycle ) {

// NEW:
private function get_cron_trigger_users( $trigger_key, $cycle, $workflow = null ) {
```

Update the call site in `run_cron_workflow()` at line 843:

```php
// OLD (line 843):
$users = $this->get_cron_trigger_users( $trigger_key, $cycle );

// NEW:
$users = $this->get_cron_trigger_users( $trigger_key, $cycle, $workflow );
```

Add null guard at the top of the new generic cases:

```php
case 'cron:component_upcoming':
    if ( ! $workflow ) {
        return array();
    }
    // ... rest of case
```

- [ ] **Step 11: Refactor get_cron_trigger_users() — add new generic cases with fallthrough aliases**

Add new cases with old keys as fallthrough aliases. Keep the old per-type NOT EXISTS subqueries as a helper method:

```php
/**
 * NOT EXISTS subquery for component-type-specific completion check.
 *
 * Used by cron:component_upcoming and cron:component_overdue to exclude
 * users who have already completed the component.
 *
 * @param string $component_type Component type slug.
 * @param object $wpdb           Global wpdb instance.
 * @return string SQL NOT EXISTS clause (empty string if no check applicable).
 */
private function component_completion_subquery( $component_type, $wpdb ) {
    switch ( $component_type ) {
        case 'classroom_visit':
            return "AND NOT EXISTS (
                SELECT 1
                FROM {$wpdb->prefix}hl_classroom_visit cv
                LEFT JOIN {$wpdb->prefix}hl_classroom_visit_submission cvs
                    ON cvs.classroom_visit_id = cv.classroom_visit_id
                   AND cvs.status = 'submitted'
                WHERE cv.cycle_id = en.cycle_id
                  AND (cv.leader_enrollment_id = en.enrollment_id OR cv.teacher_enrollment_id = en.enrollment_id)
                  AND cvs.submission_id IS NOT NULL
            )";

        case 'reflective_practice_session':
            return "AND NOT EXISTS (
                SELECT 1
                FROM {$wpdb->prefix}hl_rp_session rps
                LEFT JOIN {$wpdb->prefix}hl_rp_session_submission rpss
                    ON rpss.rp_session_id = rps.rp_session_id
                   AND rpss.status = 'submitted'
                WHERE rps.cycle_id = en.cycle_id
                  AND rpss.submitted_by_user_id = en.user_id
                  AND rpss.submission_id IS NOT NULL
            )";

        case 'coaching_session_attendance':
            return "AND NOT EXISTS (
                SELECT 1
                FROM {$wpdb->prefix}hl_coaching_session cs_check
                WHERE cs_check.component_id = c.component_id
                  AND cs_check.mentor_enrollment_id = en.enrollment_id
                  AND cs_check.session_status IN ('scheduled','attended')
            )";

        // Intentionally no completion check for: learndash_course, self_reflection,
        // teacher_self_assessment, child_assessment.
        //
        // Rationale: these are NOT one-time events. "Your course is due soon" is valid
        // even if the user has started (but not completed) the course. Self-reflections
        // and assessments may have multiple submissions or partial states that don't
        // map cleanly to "completed." Only the three event-based types above (CV, RP,
        // coaching) are true one-time submissions where a "you still need to do this"
        // reminder after completion would be incorrect.
        //
        // If a completion guard is needed for these types later, add the subquery here
        // with the appropriate table joins.
        default:
            return '';
    }
}
```

Now add the new generic cases inside the `switch` in `get_cron_trigger_users()`:

```php
case 'cron:cv_window_7d':    /* fallthrough — legacy alias */
case 'cron:rp_window_7d':    /* fallthrough — legacy alias */
case 'cron:coaching_window_7d': /* fallthrough — legacy alias */
case 'cron:component_upcoming':
    if ( ! $workflow ) {
        return array();
    }
    $offset_minutes = (int) ( $workflow->trigger_offset_minutes ?? 10080 ); // default 7 days
    $offset_seconds = $offset_minutes * 60;
    $range_start    = wp_date( 'Y-m-d', time() );
    $range_end      = wp_date( 'Y-m-d', time() + $offset_seconds );

    $comp_type      = $workflow->component_type_filter ?? '';
    $anchor         = $this->get_date_anchor( 'upcoming', $comp_type );
    $col            = $anchor['column'];

    // SQL column whitelist — prevent injection.
    $allowed_cols = array( 'complete_by', 'display_window_start', 'display_window_end' );
    if ( ! in_array( $col, $allowed_cols, true ) ) {
        return array();
    }

    $type_clause = '';
    $type_params = array();
    if ( $comp_type !== '' ) {
        $type_clause = 'AND c.component_type = %s';
        $type_params = array( $comp_type );
    }

    // Component-type-specific completion check.
    $completion_clause = $this->component_completion_subquery( $comp_type, $wpdb );

    $sql = "SELECT DISTINCT en.user_id,
                    en.enrollment_id AS enrollment_id,
                    c.component_id AS entity_id,
                    'component' AS entity_type
            FROM {$wpdb->prefix}hl_component c
            INNER JOIN {$wpdb->prefix}hl_pathway p ON p.pathway_id = c.pathway_id
            INNER JOIN {$wpdb->prefix}hl_pathway_assignment pa ON pa.pathway_id = p.pathway_id
            INNER JOIN {$wpdb->prefix}hl_enrollment en
                ON en.enrollment_id = pa.enrollment_id
               AND en.status IN ('active','warning')
            WHERE c.cycle_id = %d
              AND c.{$col} IS NOT NULL
              AND c.{$col} BETWEEN %s AND %s
              {$type_clause}
              {$completion_clause}
            LIMIT 5000";

    $params = array_merge( array( $cycle_id, $range_start, $range_end ), $type_params );
    $rows   = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
    if ( is_array( $rows ) && count( $rows ) >= 5000 && class_exists( 'HL_Audit_Service' ) ) {
        HL_Audit_Service::log( 'email_cron_safety_cap_hit', array(
            'entity_type' => 'email_workflow',
            'reason'      => 'cron:component_upcoming returned 5000 rows — may be truncated.',
        ) );
    }
    return is_array( $rows ) ? $rows : array();

case 'cron:cv_overdue_1d': /* fallthrough — legacy alias */
case 'cron:component_overdue':
    if ( ! $workflow ) {
        return array();
    }
    $offset_minutes = (int) ( $workflow->trigger_offset_minutes ?? 1440 ); // default 1 day
    $offset_seconds = $offset_minutes * 60;
    $check_date     = wp_date( 'Y-m-d', time() - $offset_seconds );

    $comp_type      = $workflow->component_type_filter ?? '';
    $anchor         = $this->get_date_anchor( 'overdue', $comp_type );
    $col            = $anchor['column'];

    $allowed_cols = array( 'complete_by', 'display_window_start', 'display_window_end' );
    if ( ! in_array( $col, $allowed_cols, true ) ) {
        return array();
    }

    $type_clause = '';
    $type_params = array();
    if ( $comp_type !== '' ) {
        $type_clause = 'AND c.component_type = %s';
        $type_params = array( $comp_type );
    }

    $completion_clause = $this->component_completion_subquery( $comp_type, $wpdb );

    $sql = "SELECT DISTINCT en.user_id,
                    en.enrollment_id AS enrollment_id,
                    c.component_id AS entity_id,
                    'component' AS entity_type
            FROM {$wpdb->prefix}hl_component c
            INNER JOIN {$wpdb->prefix}hl_pathway p ON p.pathway_id = c.pathway_id
            INNER JOIN {$wpdb->prefix}hl_pathway_assignment pa ON pa.pathway_id = p.pathway_id
            INNER JOIN {$wpdb->prefix}hl_enrollment en
                ON en.enrollment_id = pa.enrollment_id
               AND en.status IN ('active','warning')
            WHERE c.cycle_id = %d
              AND c.{$col} IS NOT NULL
              AND c.{$col} = %s
              {$type_clause}
              {$completion_clause}
            LIMIT 5000";

    $params = array_merge( array( $cycle_id, $check_date ), $type_params );
    $rows   = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
    if ( is_array( $rows ) && count( $rows ) >= 5000 && class_exists( 'HL_Audit_Service' ) ) {
        HL_Audit_Service::log( 'email_cron_safety_cap_hit', array(
            'entity_type' => 'email_workflow',
            'reason'      => 'cron:component_overdue returned 5000 rows — may be truncated.',
        ) );
    }
    return is_array( $rows ) ? $rows : array();

case 'cron:coaching_session_5d': /* fallthrough — legacy alias */
case 'cron:session_24h':         /* fallthrough — legacy alias */
case 'cron:session_1h':          /* fallthrough — legacy alias */
case 'cron:session_upcoming':
    if ( ! $workflow ) {
        return array();
    }
    $offset_minutes = (int) ( $workflow->trigger_offset_minutes ?? 1440 ); // default 24h
    // Note: session_datetime is stored in site timezone (WordPress "Timezone"
    // setting). Use current_time() to get "now" in the same timezone so the
    // BETWEEN comparison is apples-to-apples. Do NOT use gmdate()/time() here.
    $now            = current_time( 'mysql' );

    // Scale fuzz window proportionally: 10% of offset, clamped 5min-30min.
    $fuzz_seconds   = min( 1800, max( 300, $offset_minutes * 60 * 0.1 ) );
    $target_time    = strtotime( $now ) + ( $offset_minutes * 60 );
    $window_start   = wp_date( 'Y-m-d H:i:s', $target_time - $fuzz_seconds );
    $window_end     = wp_date( 'Y-m-d H:i:s', $target_time + $fuzz_seconds );

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT DISTINCT e.user_id, e.enrollment_id,
                cs.session_id AS entity_id, 'coaching_session' AS entity_type
         FROM {$wpdb->prefix}hl_coaching_session cs
         JOIN {$wpdb->prefix}hl_enrollment e ON e.enrollment_id = cs.mentor_enrollment_id
         JOIN {$wpdb->users} u ON u.ID = e.user_id
         WHERE cs.cycle_id = %d
           AND cs.session_status = 'scheduled'
           AND cs.session_datetime BETWEEN %s AND %s
           AND e.status IN ('active','warning')
         LIMIT 5000",
        $cycle_id, $window_start, $window_end
    ), ARRAY_A );
    if ( is_array( $rows ) && count( $rows ) >= 5000 && class_exists( 'HL_Audit_Service' ) ) {
        HL_Audit_Service::log( 'email_cron_safety_cap_hit', array(
            'entity_type' => 'email_workflow',
            'reason'      => 'cron:session_upcoming returned 5000 rows — may be truncated.',
        ) );
    }
    return is_array( $rows ) ? $rows : array();
```

> **Migration safety:** Old trigger keys (`cron:cv_window_7d`, etc.) are kept as `case` fallthrough aliases. Remove old aliases only in a subsequent release after confirming no workflows use them.

- [ ] **Step 12: Update get_recipient_tokens() trigger arrays**

In `get_recipient_tokens()` (line 216), update the `assigned_coach` token's `triggers` array (lines 237-247) to include the new keys:

```php
'assigned_coach' => array(
    'label'       => "User's Coach",
    'description' => 'Coach assigned to this user via hl_coach_assignment.',
    'triggers'    => array(
        'hl_enrollment_created',
        'hl_pathway_assigned',
        'hl_coaching_session_created',
        'hl_coaching_session_status_changed',
        'hl_rp_session_created',
        'hl_rp_session_status_changed',
        'hl_classroom_visit_submitted',
        'hl_teacher_assessment_submitted',
        'hl_child_assessment_submitted',
        'hl_pathway_completed',
        // New generic keys:
        'cron:component_upcoming',
        'cron:component_overdue',
        'cron:session_upcoming',
        // Retained non-offset keys:
        'cron:coaching_pre_end',
        'cron:action_plan_24h',
        'cron:session_notes_24h',
        'cron:low_engagement_14d',
        // Legacy aliases (kept until old workflows are fully migrated):
        'cron:cv_window_7d',
        'cron:cv_overdue_1d',
        'cron:rp_window_7d',
        'cron:coaching_window_7d',
        'cron:coaching_session_5d',
        'cron:session_24h',
        'cron:session_1h',
    ),
),
```

- [ ] **Step 13: Update CLI test harness**

In `includes/cli/class-hl-cli-email-v2-test.php`, update the `test_cron()` method (lines 254-268):

```php
// OLD (lines 254-258):
$triggers = array(
    'cron:cv_window_7d',
    'cron:cv_overdue_1d',
    'cron:rp_window_7d',
    'cron:coaching_window_7d',
    'cron:coaching_pre_end',
);

// NEW:
$triggers = array(
    'cron:component_upcoming',
    'cron:component_overdue',
    'cron:session_upcoming',
    'cron:coaching_pre_end',
);
```

Also update the reflection call — `get_cron_trigger_users` now takes 3 args. Create a mock workflow object for the new triggers:

```php
// Build a mock workflow object for triggers that need it.
$mock_workflow = (object) array(
    'trigger_offset_minutes' => 10080,
    'component_type_filter'  => null,
);

foreach ( $triggers as $t ) {
    try {
        $out = $method->invoke( $svc, $t, $cycle, $mock_workflow );
        $this->assert_true( is_array( $out ), $t . ' returned an array' );
    } catch ( \Throwable $e ) {
        $this->assert_true( false, $t . ' threw: ' . $e->getMessage() );
    }
}
```

Also update `test_schema()` — add an assertion for the new columns:

```php
// After existing schema assertions, add:
// trigger_offset_minutes column exists.
$toff_col = $wpdb->get_var( $wpdb->prepare(
    "SELECT COLUMN_NAME FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s
     LIMIT 1",
    $wpdb->prefix . 'hl_email_workflow', 'trigger_offset_minutes'
) );
$this->assert_true( ! empty( $toff_col ), 'hl_email_workflow.trigger_offset_minutes column exists' );

// component_type_filter column exists.
$ctf_col = $wpdb->get_var( $wpdb->prepare(
    "SELECT COLUMN_NAME FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s
     LIMIT 1",
    $wpdb->prefix . 'hl_email_workflow', 'component_type_filter'
) );
$this->assert_true( ! empty( $ctf_col ), 'hl_email_workflow.component_type_filter column exists' );

// idx_complete_by index exists on hl_component.
$idx_cb = $wpdb->get_var( $wpdb->prepare(
    "SELECT INDEX_NAME FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s
     LIMIT 1",
    $wpdb->prefix . 'hl_component', 'idx_complete_by'
) );
$this->assert_true( ! empty( $idx_cb ), 'hl_component.idx_complete_by index exists' );
```

- [ ] **Step 14: Run full test suite**

Run: `wp hl-core email-v2-test`
Expected: All tests pass.

- [ ] **Step 15: Commit**

```bash
git add includes/class-hl-installer.php includes/services/class-hl-email-automation-service.php includes/admin/class-hl-admin-emails.php assets/js/admin/email-workflow.js includes/cli/class-hl-cli-email-v2-test.php
git commit -m "feat(email): configurable trigger offset (minutes/hours/days) + component type filter + complete_by index (A.3+A.4, Rev 39)"
```

---

### Task 8: UI sub-filter for coaching/RP status triggers (A.1)

**Files:**
- Modify: `includes/admin/class-hl-admin-emails.php` (add sub-filter dropdown)
- Modify: `assets/js/admin/email-workflow.js` (handled in Task 7 Step 4 — already merged)
- Modify: `includes/services/class-hl-email-automation-service.php:300-312` (no change needed — context already has `session.new_status`)

When "Coaching Session Status Changed" is selected as trigger, show a secondary dropdown: "Any", "Booked", "Attended", "Cancelled", "Missed", "Rescheduled". The selection auto-generates a condition `session.new_status eq <value>` in the workflow's conditions JSON.

- [ ] **Step 1: Add the sub-filter dropdown HTML**

In `includes/admin/class-hl-admin-emails.php`, after the trigger `<select>` (around line 700), add:

```php
<?php
// Pre-select status sub-filter from existing conditions.
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
?>
<tr class="hl-wf-status-filter-row" style="display:none;">
    <th scope="row"><?php esc_html_e( 'Status Filter', 'hl-core' ); ?></th>
    <td>
        <select name="trigger_status_filter" id="wf-trigger-status-filter" aria-label="Filter by session status">
            <option value="" <?php selected( $trigger_status_val, '' ); ?>>Any Status Change</option>
            <option value="scheduled" <?php selected( $trigger_status_val, 'scheduled' ); ?>>Session Booked</option>
            <option value="attended" <?php selected( $trigger_status_val, 'attended' ); ?>>Session Attended</option>
            <option value="cancelled" <?php selected( $trigger_status_val, 'cancelled' ); ?>>Session Cancelled</option>
            <option value="missed" <?php selected( $trigger_status_val, 'missed' ); ?>>Session Missed</option>
            <option value="rescheduled" <?php selected( $trigger_status_val, 'rescheduled' ); ?>>Session Rescheduled</option>
        </select>
    </td>
</tr>
```

> **Note:** The JS visibility toggle for `.hl-wf-status-filter-row` is already handled in Task 7, Step 4 — it was merged into the existing `$triggerSelect.on('change')` handler at line 382.

- [ ] **Step 2: Update save handler to inject condition from sub-filter**

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

- [ ] **Step 3: Commit**

```bash
git add includes/admin/class-hl-admin-emails.php assets/js/admin/email-workflow.js
git commit -m "feat(email): UI sub-filter for coaching/RP status triggers (A.1)"
```

---

### Task 9: "Coaching session not yet scheduled" condition (A.5)

**Files:**
- Modify: `includes/admin/class-hl-admin-emails.php:66-133` (add to `get_condition_fields()`)
- Modify: `includes/services/class-hl-email-automation-service.php:473` (add to context hydration)
- No change needed in condition evaluator — generic operator handles it.

Currently this logic is embedded in the `cron:coaching_window_7d` SQL. We need it as a reusable condition so workflow authors can attach it to any trigger.

- [ ] **Step 1: Add `coaching.session_scheduled` to condition fields**

In `get_condition_fields()` (line 66), add a new field after the User group (after line 133):

```php
// Coaching group.
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

- [ ] **Step 2: Hydrate `coaching.session_scheduled` lazily in context**

Make the DB query lazy: only run when conditions actually reference `coaching.session_scheduled`. Check conditions before the user loop, set a flag, then query only when the flag is set. This avoids N+1 queries when the condition is not used.

In `hydrate_context()` (line 473), after the enrollment context is loaded (after line 514 where `load_enrollment_context` is called), add:

```php
// Lazy coaching session check — only query when a workflow condition references it.
// The 'needs_coaching_check' flag is set by run_cron_workflow() when conditions
// contain 'coaching.session_scheduled'. For hook-based triggers it's always
// computed since conditions are per-workflow and we can't pre-check cheaply.
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

> **Performance note:** In the cron path, `hydrate_context()` runs once per user per workflow. The query is a simple indexed lookup (`mentor_enrollment_id + cycle_id`). For the current scale (<200 enrollments per cycle), this adds negligible overhead. If scale grows past 1000 enrollments per cycle, consider pre-loading all session counts into a map before the user loop.

- [ ] **Step 3: Commit**

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

Add near the top of the IIFE, after the `var` declarations (after line 24):

```javascript
function buildMergeTagSelect(targetInput) {
    var $sel = $('<select class="hl-eb-merge-tag-url-select" aria-label="Insert merge tag into URL"><option value="">Insert tag...</option></select>');
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
        targetInput.trigger('change');
    });
    return $sel;
}
```

> **Event fix:** Use `.trigger('change')` not `.trigger('input')` because the button URL handler listens for `'change'` (verified at `email-builder.js:309`: `$url.on('change', function () { ... })`).

- [ ] **Step 2: Add merge tag select to primary button blocks**

In the `case 'button':` block (around line 307), after the `$url` input is created and its `change` handler is bound, add:

```javascript
// EXISTING (around line 310):
$url.on('change',   function () { pushUndo(); blocks[index].url   = $(this).val(); markDirty(); });
$wrap.append($label).append($url);

// CHANGE TO:
$url.on('change',   function () { pushUndo(); blocks[index].url   = $(this).val(); markDirty(); });
var $urlTagSelect = buildMergeTagSelect($url);
$wrap.append($label).append($url).append($urlTagSelect);
```

- [ ] **Step 3: Add merge tag select to nested button blocks**

In the nested content rendering (around line 559-564), after the nested button URL input `$u`:

```javascript
// EXISTING (around line 563-564):
$u.on('change',   function () { pushUndo(); shim.set('url',   $(this).val()); markDirty(); });
$content.append($lbl).append($u);

// CHANGE TO:
$u.on('change',   function () { pushUndo(); shim.set('url',   $(this).val()); markDirty(); });
var $uTagSel = buildMergeTagSelect($u);
$content.append($lbl).append($u).append($uTagSel);
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

No changes needed from code review for this task.

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
- Modify: `includes/admin/class-hl-admin-email-builder.php`

The draft saves correctly to the DB but the user doesn't realize the page loads the published version and requires a manual "Restore" click.

- [ ] **Step 1: Auto-restore draft on page load with timestamp comparison**

Replace the banner click handler (lines 40-56) with auto-restore logic that checks draft freshness:

```javascript
// Auto-restore draft if one exists AND is newer than the saved template.
if (config.draftData) {
    try {
        var draft = typeof config.draftData === 'string' ? JSON.parse(config.draftData) : config.draftData;
        var draftTime    = config.draftUpdatedAt ? new Date(config.draftUpdatedAt).getTime() : 0;
        var templateTime = config.templateUpdatedAt ? new Date(config.templateUpdatedAt).getTime() : 0;

        if (draft.blocks && draft.blocks.length > 0) {
            if (draftTime > templateTime) {
                // Draft is newer than the saved template — auto-restore.
                blocks = draft.blocks;
                if (draft.subject) $('#hl-eb-subject').val(draft.subject);
                if (draft.name) $('#hl-eb-name').val(draft.name);
                renderAllBlocks();
                $('#hl-draft-banner').show().find('.hl-draft-banner-text')
                    .text('Draft restored. Click Save to publish your changes.');
            } else {
                // Template was saved after the draft — show warning, don't auto-restore.
                $('#hl-draft-banner').show().find('.hl-draft-banner-text')
                    .text('A draft exists but the template was updated since. Click Restore to load the draft, or Discard to remove it.');
                // Re-add the manual restore handler.
                $('#hl-restore-draft').show().on('click', function () {
                    blocks = draft.blocks;
                    if (draft.subject) $('#hl-eb-subject').val(draft.subject);
                    if (draft.name) $('#hl-eb-name').val(draft.name);
                    renderAllBlocks();
                    $('#hl-draft-banner').hide();
                });
            }
        }
    } catch (e) {
        // Invalid draft data — ignore silently
    }
}

$('#hl-discard-draft').on('click', function () {
    // Discard draft via AJAX then reload.
    $.post(config.ajaxUrl, {
        action: 'hl_email_template_discard_draft',
        template_id: config.templateId || 'new',
        nonce: config.nonce
    });
    location.reload();
});
```

> **Nonce fix:** Use `nonce` not `_wpnonce` to match the existing pattern used throughout the builder (verified at `email-builder.js:824,932,992,1132,1160`).

- [ ] **Step 1b: Add `.hl-draft-banner-text` span to banner PHP**

The JS references `.hl-draft-banner-text` (via `$('#hl-draft-banner').find('.hl-draft-banner-text')`) but the PHP banner HTML (lines 96-102 of `class-hl-admin-email-builder.php`) has no such element — it's just raw text inside `<p>`. Wrap the text in a span so the JS `.find()` selector works:

```php
// OLD (lines 96-102):
<div class="notice notice-warning hl-email-draft-banner" id="hl-draft-banner">
    <p>
        <?php esc_html_e( 'An unsaved draft was found. ', 'hl-core' ); ?>
        <button type="button" class="button button-small" id="hl-restore-draft"><?php esc_html_e( 'Restore', 'hl-core' ); ?></button>
        <button type="button" class="button button-small" id="hl-discard-draft"><?php esc_html_e( 'Discard', 'hl-core' ); ?></button>
    </p>
</div>

// NEW:
<div class="notice notice-warning hl-email-draft-banner" id="hl-draft-banner">
    <p>
        <span class="hl-draft-banner-text"><?php esc_html_e( 'An unsaved draft was found. ', 'hl-core' ); ?></span>
        <button type="button" class="button button-small" id="hl-restore-draft"><?php esc_html_e( 'Restore', 'hl-core' ); ?></button>
        <button type="button" class="button button-small" id="hl-discard-draft"><?php esc_html_e( 'Discard', 'hl-core' ); ?></button>
    </p>
</div>
```

> **Why this is needed:** The auto-restore JS uses `.find('.hl-draft-banner-text').text(...)` to update the banner message dynamically (e.g., "Draft restored. Click Save to publish your changes."). Without the span, `.find()` returns an empty jQuery set and the message is never shown.

- [ ] **Step 1c: Hide Restore button after auto-restore**

In the auto-restore branch of Step 1 (the `if (draftTime > templateTime)` block), add after `renderAllBlocks()`:

```javascript
// After auto-restore, hide the Restore button — user doesn't need it.
$('#hl-restore-draft').hide();
```

The full auto-restore block becomes:

```javascript
if (draftTime > templateTime) {
    // Draft is newer than the saved template — auto-restore.
    blocks = draft.blocks;
    if (draft.subject) $('#hl-eb-subject').val(draft.subject);
    if (draft.name) $('#hl-eb-name').val(draft.name);
    renderAllBlocks();
    $('#hl-restore-draft').hide();
    $('#hl-draft-banner').show().find('.hl-draft-banner-text')
        .text('Draft restored. Click Save to publish your changes.');
}
```

- [ ] **Step 2: Add discard-draft AJAX handler**

In `includes/admin/class-hl-admin-email-builder.php`, add the AJAX action registration in the constructor (after line 26):

```php
add_action( 'wp_ajax_hl_email_template_discard_draft', array( $this, 'ajax_discard_draft' ) );
```

Add the handler method:

```php
/**
 * Discard a builder draft.
 */
public function ajax_discard_draft() {
    check_ajax_referer( 'hl_email_builder', 'nonce' );
    if ( ! current_user_can( 'manage_hl_core' ) ) {
        wp_send_json_error();
    }
    $template_id = sanitize_text_field( $_POST['template_id'] ?? 'new' );
    delete_option( 'hl_email_draft_' . get_current_user_id() . '_' . $template_id );
    wp_send_json_success();
}
```

- [ ] **Step 3: Update "Draft saved" message**

In `doAutosave()` success callback (line 1001):

```javascript
// OLD (line 1001):
$('#hl-eb-autosave-status').text('Draft saved ' + res.data.saved_at).fadeIn();

// NEW:
$('#hl-eb-autosave-status').text('Draft auto-saved (click Save to publish) ' + res.data.saved_at).fadeIn();
```

> **Selector fix:** The correct selector is `$('#hl-eb-autosave-status')` (verified at `email-builder.js:945,1001`), not `$('#hl-eb-status')`. `$('#hl-eb-status')` is the template status dropdown (line 938).

- [ ] **Step 4: Extract draft timestamp from envelope + pass both timestamps to JS config**

In `includes/admin/class-hl-admin-email-builder.php`, inside the draft envelope unwrapping block (lines 64-76), extract `$draft_updated_at` from the envelope BEFORE discarding it. Add after line 71 (`$draft_data = ...`):

```php
// OLD (lines 64-76):
$draft_key  = 'hl_email_draft_' . get_current_user_id() . '_' . ( $template_id ?: 'new' );
$draft_raw  = get_option( $draft_key, null );
$draft_data = null;
if ( is_string( $draft_raw ) && $draft_raw !== '' ) {
    $decoded = json_decode( $draft_raw, true );
    if ( is_array( $decoded ) && array_key_exists( 'payload', $decoded ) ) {
        // Envelope format.
        $draft_data = is_string( $decoded['payload'] ) ? $decoded['payload'] : '';
    } else {
        // Legacy raw-payload draft (pre-Task 8).
        $draft_data = $draft_raw;
    }
}

// NEW:
$draft_key  = 'hl_email_draft_' . get_current_user_id() . '_' . ( $template_id ?: 'new' );
$draft_raw  = get_option( $draft_key, null );
$draft_data       = null;
$draft_updated_at = null;
if ( is_string( $draft_raw ) && $draft_raw !== '' ) {
    $decoded = json_decode( $draft_raw, true );
    if ( is_array( $decoded ) && array_key_exists( 'payload', $decoded ) ) {
        // Envelope format — extract updated_at before unwrapping.
        $draft_updated_at = $decoded['updated_at'] ?? null;
        $draft_data       = is_string( $decoded['payload'] ) ? $decoded['payload'] : '';
    } else {
        // Legacy raw-payload draft (pre-Task 8).
        $draft_data = $draft_raw;
    }
}
```

Then in the `window.hlEmailBuilder` config object (around line 279), add both timestamps:

```php
// OLD:
draftData: <?php echo wp_json_encode( $draft_data ); ?>,

// NEW:
draftData: <?php echo wp_json_encode( $draft_data ); ?>,
draftUpdatedAt: <?php echo wp_json_encode( $draft_updated_at ); ?>,
templateUpdatedAt: <?php echo wp_json_encode( $template ? $template->updated_at : null ); ?>,
```

> **Why `draftUpdatedAt` is separate from `draftData`:** The envelope is unwrapped in PHP — `draftData` contains only the inner payload string. The `updated_at` timestamp lives on the envelope, not the payload, so it must be extracted before unwrapping and passed as a separate config field. Using `draft.updated_at` in JS would always be `undefined` because the parsed payload has no `updated_at` key.

- [ ] **Step 5: Commit**

```bash
git add assets/js/admin/email-builder.js includes/admin/class-hl-admin-email-builder.php
git commit -m "fix(email): auto-restore draft on page load, improve save messaging"
```

---

## Phase 4: Admin UX

### Task 13: Workflow folders/groups (A.7)

**Files:**
- Modify: `includes/class-hl-installer.php` (add `folder` column, Rev 40)
- Modify: `includes/admin/class-hl-admin-emails.php:485-548` (folder filter + grouped list)
- Modify: `assets/css/admin.css` (folder header styles)

- [ ] **Step 1: Add `folder` column to `hl_email_workflow` (Rev 40)**

Add migration method:

```php
/**
 * Rev 40: Email v2 — workflow folders for organization.
 */
private static function migrate_workflow_add_folder_col() {
    global $wpdb;
    $table = $wpdb->prefix . 'hl_email_workflow';
    $col   = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'folder'" );
    if ( empty( $col ) ) {
        $wpdb->query( "ALTER TABLE {$table} ADD COLUMN folder varchar(100) DEFAULT NULL COMMENT 'Workflow group/folder for organization' AFTER name" );
    }
}
```

Register in `maybe_upgrade()` and bump `$current_revision` to 40:

```php
$current_revision = 40;

// Rev 40: Workflow folders.
if ( (int) $stored < 40 ) {
    self::migrate_workflow_add_folder_col();
}
```

Also add `folder` to the `hl_email_workflow` CREATE TABLE in `get_schema()` (after `name`):

```sql
folder varchar(100) DEFAULT NULL COMMENT 'Workflow group/folder for organization',
```

- [ ] **Step 2: Add folder input to workflow form**

After the Name field:

```php
<?php
$existing_folders = $wpdb->get_col( "SELECT DISTINCT folder FROM {$wpdb->prefix}hl_email_workflow WHERE folder IS NOT NULL AND folder != '' ORDER BY folder" );
?>
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
    echo '<a href="' . esc_url( remove_query_arg( 'folder' ) ) . '" class="hl-pill' . ( $folder_filter === '' ? ' active' : '' ) . '" ' . ( $folder_filter === '' ? 'aria-current="true"' : '' ) . '>All Folders</a>';
    foreach ( $folders as $f ) :
        echo '<a href="' . esc_url( add_query_arg( 'folder', $f ) ) . '" class="hl-pill' . ( $folder_filter === $f ? ' active' : '' ) . '" ' . ( $folder_filter === $f ? 'aria-current="true"' : '' ) . '>' . esc_html( $f ) . '</a>';
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
    border-bottom: 2px solid var(--hl-a-primary, #1A2B47);
}
```

> **CSS variable fix:** Use `var(--hl-a-primary, #1A2B47)` not `var(--hl-primary, #1A2B47)`. The admin design system uses `--hl-a-*` namespace (verified at `assets/css/admin.css:11`).

- [ ] **Step 8: Commit**

```bash
git add includes/class-hl-installer.php includes/admin/class-hl-admin-emails.php assets/css/admin.css
git commit -m "feat(email): workflow folders/groups for organization (A.7, Rev 40)"
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
- [ ] Browser test: draft auto-restores on page refresh (when draft is newer than template)
- [ ] Browser test: draft shows warning banner (when template is newer than draft)
- [ ] Verify domain allowlist blocks external addresses
- [ ] Verify cycle date-gating excludes future/archived cycles
- [ ] Verify sender address is academy@housmanlearning.com
- [ ] Browser test: enrollment status dropdown shows Active/Inactive only
- [ ] Update STATUS.md + README.md
- [ ] Commit docs
