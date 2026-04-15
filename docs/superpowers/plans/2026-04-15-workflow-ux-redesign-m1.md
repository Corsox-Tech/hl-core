# Workflow Builder UX Redesign — M1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the developer-style workflow form with a card-based two-panel layout, add Send Test Email, activation guardrails, coaching session status enum condition, and condition migration.

**Architecture:** M1 ships the card layout, summary preview, Send Test, and backend condition fix on the existing trigger dropdown (no cascading yet — that's M2). The JS is additive: existing condition builder and recipient picker modules are preserved, new code wraps around them. A rollback toggle (`hl_workflow_ux_version`) lets admins revert to the old form instantly.

**Tech Stack:** PHP 7.4+, jQuery, WordPress admin AJAX, `wp_mail()`, `HL_Audit_Service`

**Spec:** `docs/superpowers/specs/2026-04-15-workflow-ux-redesign-design.md`

---

## Execution Order

Tasks are reordered to maximize early visual progress. The original task numbers are preserved for reference.

1. **Task 1** — Rollback toggle + condition migration (safety net, ~30 min)
2. **Task 5a** — Card layout PHP: delegation + data loading + top bar (visible skeleton)
3. **Task 6** — CSS: two-panel layout + card styles (Chris can see the new layout)
4. **Task 7** — JS: summary panel, guardrails, send test UI, progressive disclosure
5. **Task 4** — Send Test Email backend (button works end-to-end)
6. **Task 2** — Fix component_id propagation (backend condition correctness)
7. **Task 3** — Update operator labels (quick UI text fix)
8. **Task 5b** — Card layout PHP: full cards + summary panel + advanced section
9. **Task 8** — Recipient preview with sample names
10. **Task 9** — Activation guardrail on save
11. **Task 10** — Deploy + smoke test

**Day 1.5 checkpoint:** After Tasks 1 + 5a + 6 + 7, Chris can see the new two-panel card layout with progressive disclosure and guardrails. This is the "show Chris" moment.

**MVP-5 fallback:** If Day 2 EOD has not started Task 5b, cut to MVP-5: Tasks 1, 2, 3, 4, 9. Ships all functional improvements on the existing v1 UI — no visual redesign but condition fix, Send Test, operator labels, and guardrails all work.

---

## Pre-Flight

Before starting, create a new branch:

```bash
git checkout main
git pull origin main
git checkout -b feature/workflow-ux-m1
```

Read these files to understand the current implementation:
- `includes/admin/class-hl-admin-emails.php` (the workflow form — `render_workflow_form()` at line 648)
- `assets/js/admin/email-workflow.js` (condition builder + recipient picker)
- `assets/css/admin.css` (`.hl-workflow-form` + `.hl-email-admin` sections starting ~line 1901)
- `includes/services/class-hl-email-automation-service.php` (coaching context hydration at line 565)
- `includes/services/class-hl-email-queue-processor.php` (`is_domain_allowed()` at line 425)
- `includes/admin/class-hl-admin.php` (asset enqueue at line 240)

---

### Task 1: Rollback Toggle + Condition Migration

**Files:**
- Modify: `includes/admin/class-hl-admin-emails.php` (add migration function, update `get_condition_fields()`)
- Modify: `includes/class-hl-installer.php` (call migration on activation)

This task is pure backend — no UI changes. It lays the safety foundation (rollback toggle) and fixes the coaching session condition data.

- [ ] **Step 1: Add the rollback version option**

In `includes/admin/class-hl-admin-emails.php`, add a static helper at the top of the class (after `private static $instance = null;`):

```php
/**
 * Check whether the v2 workflow UX is active.
 *
 * @return bool
 */
public static function is_v2_ux() {
    return get_option( 'hl_workflow_ux_version', 'v2' ) === 'v2';
}
```

- [ ] **Step 2: Replace `coaching.session_scheduled` with `coaching.session_status` in `get_condition_fields()`**

In `get_condition_fields()` (line 130-139), replace the `coaching.session_scheduled` entry:

```php
// OLD — remove this:
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

With:

```php
'coaching.session_status' => array(
    'label'   => 'Coaching Session Status',
    'group'   => 'Coaching',
    'type'    => 'enum',
    'options' => array(
        'not_scheduled' => 'Not Scheduled',
        'scheduled'     => 'Scheduled',
        'attended'      => 'Attended',
        'missed'        => 'Missed',
        'cancelled'     => 'Cancelled',
        'rescheduled'   => 'Rescheduled',
    ),
),
```

- [ ] **Step 3: Add the condition migration function**

Add this method to `HL_Admin_Emails`:

```php
/**
 * Migrate workflows using the old coaching.session_scheduled condition
 * to the new coaching.session_status enum.
 *
 * Called from HL_Installer::maybe_upgrade() on plugin activation.
 */
public static function migrate_coaching_session_conditions() {
    if ( get_option( 'hl_coaching_condition_migrated', false ) ) {
        return;
    }

    global $wpdb;
    $table = "{$wpdb->prefix}hl_email_workflow";

    // Guard: table may not exist on fresh installs.
    if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) ) ) {
        return;
    }

    // Pre-migration backup for rollback safety.
    $backup = "{$table}_pre_coaching_migration";
    if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $backup ) ) ) {
        $wpdb->query( "CREATE TABLE `{$backup}` AS SELECT workflow_id, conditions FROM `{$table}` WHERE conditions LIKE '%coaching.session_scheduled%'" );
    }
    $rows  = $wpdb->get_results(
        "SELECT workflow_id, conditions FROM {$table} WHERE conditions LIKE '%coaching.session_scheduled%'"
    );

    foreach ( $rows as $row ) {
        $conditions = json_decode( $row->conditions, true );
        if ( ! is_array( $conditions ) ) continue;

        $changed = false;
        foreach ( $conditions as &$cond ) {
            if ( ( $cond['field'] ?? '' ) !== 'coaching.session_scheduled' ) continue;

            $old_value = $cond['value'] ?? '';
            $cond['field'] = 'coaching.session_status';

            if ( $old_value === 'yes' ) {
                $cond['op']    = 'in';
                $cond['value'] = array( 'scheduled', 'attended' );
            } else {
                $cond['op']    = 'in';
                $cond['value'] = array( 'not_scheduled', 'cancelled', 'missed', 'rescheduled' );
            }
            $changed = true;
        }
        unset( $cond );

        if ( $changed ) {
            $wpdb->update(
                $table,
                array( 'conditions' => wp_json_encode( $conditions ) ),
                array( 'workflow_id' => $row->workflow_id ),
                array( '%s' ),
                array( '%d' )
            );

            if ( class_exists( 'HL_Audit_Service' ) ) {
                HL_Audit_Service::log( 'workflow_condition_migrated', array(
                    'entity_type' => 'email_workflow',
                    'entity_id'   => (int) $row->workflow_id,
                    'old_field'   => 'coaching.session_scheduled',
                    'new_field'   => 'coaching.session_status',
                ) );
            }
        }
    }

    update_option( 'hl_coaching_condition_migrated', true );
}
```

- [ ] **Step 4: Wire migration into installer**

In `includes/class-hl-installer.php`, inside `maybe_upgrade()` (after the last migration call), add:

```php
// Migrate coaching.session_scheduled → coaching.session_status conditions.
if ( class_exists( 'HL_Admin_Emails' ) ) {
    HL_Admin_Emails::migrate_coaching_session_conditions();
}
```

- [ ] **Step 5: Test the migration via CLI**

```bash
ssh test-server
cd /path/to/wordpress
wp eval "HL_Admin_Emails::migrate_coaching_session_conditions();"
wp db query "SELECT workflow_id, conditions FROM wp_hl_email_workflow WHERE conditions LIKE '%coaching.session_status%'" --skip-column-names
```

Expected: Any workflows with old `coaching.session_scheduled` now show `coaching.session_status` with array values.

- [ ] **Step 6: Commit**

```bash
git add includes/admin/class-hl-admin-emails.php includes/class-hl-installer.php
git commit -m "feat(email): coaching session status enum condition + migration + rollback toggle"
```

---

### Task 2: Fix `component_id` Propagation in Automation Context

**Files:**
- Modify: `includes/services/class-hl-email-automation-service.php` (lines 390, 565-577)

The coaching session status condition needs `component_id` in context. Currently, only `enrollment_id` and `cycle_id` are available during lazy hydration.

- [ ] **Step 1: Propagate `component_id` from coaching session context**

In `load_coaching_session_context()` (line 390), after loading the session row, add component_id to context. Find where the method sets context values from the session object and add:

```php
if ( ! empty( $session->component_id ) ) {
    $context['component_id'] = (int) $session->component_id;
}
```

- [ ] **Step 2: Propagate `component_id` from cron trigger context**

In the method that builds context for cron triggers (`hydrate_context` or similar), find where `entity_id` is set for component-type entities. Add:

```php
if ( ! empty( $context['entity_type'] ) && $context['entity_type'] === 'component' && ! empty( $context['entity_id'] ) ) {
    $context['component_id'] = (int) $context['entity_id'];
} elseif ( ! empty( $context['entity_type'] ) && $context['entity_type'] === 'coaching_session' && ! empty( $context['entity_id'] ) ) {
    // cron:session_upcoming returns entity_type=coaching_session, entity_id=session_id.
    // Look up component_id from the session row.
    $session_component = $wpdb->get_var( $wpdb->prepare(
        "SELECT component_id FROM {$wpdb->prefix}hl_coaching_session WHERE session_id = %d",
        $context['entity_id']
    ) );
    if ( $session_component ) {
        $context['component_id'] = (int) $session_component;
    }
}
```

This should go early in `hydrate_context()`, before the lazy coaching check block.

- [ ] **Step 3: Rewrite the coaching session status hydration (line 565-577)**

Replace the old boolean check:

```php
// A.5 — Lazy hydration: coaching.session_scheduled (only when a condition references it).
if ( ! empty( $context['_needs_coaching_check'] ) && ! empty( $context['enrollment_id'] ) && ! empty( $context['cycle_id'] ) ) {
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

With:

```php
// Lazy hydration: coaching.session_status (component-scoped enum).
if ( ! empty( $context['_needs_coaching_check'] ) && ! empty( $context['enrollment_id'] ) ) {
    $session_status = 'not_scheduled';

    if ( ! empty( $context['component_id'] ) ) {
        $status = $wpdb->get_var( $wpdb->prepare(
            "SELECT session_status FROM {$wpdb->prefix}hl_coaching_session
             WHERE mentor_enrollment_id = %d AND component_id = %d
             ORDER BY created_at DESC LIMIT 1",
            $context['enrollment_id'],
            $context['component_id']
        ) );
        if ( $status ) {
            $session_status = $status;
        }
    } elseif ( ! empty( $context['cycle_id'] ) ) {
        // Fallback for triggers without component_id (e.g., enrollment-level hooks).
        $status = $wpdb->get_var( $wpdb->prepare(
            "SELECT session_status FROM {$wpdb->prefix}hl_coaching_session
             WHERE mentor_enrollment_id = %d AND cycle_id = %d
             ORDER BY created_at DESC LIMIT 1",
            $context['enrollment_id'],
            $context['cycle_id']
        ) );
        if ( $status ) {
            $session_status = $status;
        }
    }

    $context['coaching'] = array_merge(
        $context['coaching'] ?? array(),
        array( 'session_status' => $session_status )
    );
}
```

- [ ] **Step 4: Update the lazy hydration flag detection (line 874-877)**

Find the block that detects `coaching.session_scheduled` in conditions to set `_needs_coaching_check`:

```php
if ( isset( $cond['field'] ) && strpos( $cond['field'], 'coaching.session_scheduled' ) === 0 ) {
```

Change to:

```php
if ( isset( $cond['field'] ) && strpos( $cond['field'], 'coaching.session_status' ) === 0 ) {
```

- [ ] **Step 5: Test via CLI**

```bash
ssh test-server
wp hl-core email-v2-test --only=resolver
```

Expected: All existing tests still pass. The condition evaluator uses the same generic enum logic — no evaluator changes needed.

- [ ] **Step 6: Commit**

```bash
git add includes/services/class-hl-email-automation-service.php
git commit -m "fix(email): component-scoped coaching session status condition with component_id propagation"
```

---

### Task 3: Update Operator Labels

**Files:**
- Modify: `includes/admin/class-hl-admin-emails.php` (`get_condition_operators()` at line 151)

- [ ] **Step 1: Change `in` / `not_in` labels**

In `get_condition_operators()`, find the enum operator entries and update:

```php
// OLD:
'in'      => 'is in',
'not_in'  => 'is not in',
```

```php
// NEW:
'in'      => 'is any of',
'not_in'  => 'is none of',
```

Do this for every type block that contains `in`/`not_in` operators (enum, text, role, etc.).

- [ ] **Step 2: Commit**

```bash
git add includes/admin/class-hl-admin-emails.php
git commit -m "fix(email): operator labels 'is any of' / 'is none of' for non-technical users"
```

---

### Task 4: Send Test Email Backend

**Files:**
- Modify: `includes/admin/class-hl-admin-emails.php` (add AJAX handler + constructor hook)
- Modify: `includes/services/class-hl-email-queue-processor.php` (add `send_test_email()`)
- Modify: `includes/admin/class-hl-admin.php` (add nonce to inline config)

- [ ] **Step 1: Add the AJAX action in constructor**

In `HL_Admin_Emails::__construct()` (line 22-36), add:

```php
add_action( 'wp_ajax_hl_email_send_test', array( $this, 'ajax_send_test' ) );
```

- [ ] **Step 2: Add the `ajax_send_test()` handler**

Add this method to `HL_Admin_Emails`:

```php
/**
 * AJAX: Send a test email using a real enrollment's context.
 *
 * Security: capability check, nonce, server-side domain allowlist, rate limit, audit log.
 */
public function ajax_send_test() {
    check_ajax_referer( 'hl_workflow_send_test', 'nonce' );
    if ( ! current_user_can( 'manage_hl_core' ) ) {
        wp_send_json_error( 'Unauthorized.' );
    }

    $template_id   = (int) ( $_POST['template_id'] ?? 0 );
    $enrollment_id = (int) ( $_POST['enrollment_id'] ?? 0 );
    $to_email      = sanitize_email( wp_unslash( $_POST['to_email'] ?? '' ) );

    if ( ! $template_id || ! $to_email ) {
        wp_send_json_error( 'Template and email address are required.' );
    }

    // Server-side domain allowlist.
    $processor = new HL_Email_Queue_Processor();
    if ( ! $processor->is_domain_allowed( $to_email ) ) {
        wp_send_json_error( 'Email domain not in allowlist. Allowed: @housmanlearning.com, @corsox.com, @yopmail.com' );
    }

    // Transient rate limit: 5 per admin per 10 minutes.
    $user_id   = get_current_user_id();
    $cache_key = 'hl_send_test_' . $user_id;
    $count     = (int) get_transient( $cache_key );
    if ( $count >= 5 ) {
        wp_send_json_error( 'Rate limit reached. Please wait a few minutes before sending another test.' );
    }
    set_transient( $cache_key, $count + 1, 600 );

    // Load template.
    global $wpdb;
    $template = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}hl_email_template WHERE template_id = %d",
        $template_id
    ) );
    if ( ! $template ) {
        wp_send_json_error( 'Template not found.' );
    }

    // Build context from enrollment.
    $context      = array();
    $preview_name = 'sample user';
    if ( $enrollment_id > 0 ) {
        $enrollment = $wpdb->get_row( $wpdb->prepare(
            "SELECT e.*, u.display_name, u.user_email
             FROM {$wpdb->prefix}hl_enrollment e
             LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
             WHERE e.enrollment_id = %d",
            $enrollment_id
        ) );
        if ( $enrollment ) {
            $preview_name = $enrollment->display_name ?: 'User #' . $enrollment->user_id;
            $context['user_id']       = (int) $enrollment->user_id;
            $context['enrollment_id'] = (int) $enrollment->enrollment_id;
            $context['cycle_id']      = (int) $enrollment->cycle_id;
        }
    }

    // Render blocks.
    $blocks   = json_decode( $template->blocks_json, true ) ?: array();
    $subject  = $template->subject ?: $template->name;

    // Resolve merge tags via registry, then pass resolved map to renderer.
    $resolved = array();
    if ( class_exists( 'HL_Email_Merge_Tag_Registry' ) ) {
        $registry = HL_Email_Merge_Tag_Registry::instance();
        $resolved = $registry->resolve_all( $context );
    }

    // Renderer is a singleton — never use `new`.
    $renderer  = HL_Email_Block_Renderer::instance();
    $body_html = $renderer->render( $blocks, $subject, $resolved );

    // Resolve merge tags in subject line.
    foreach ( $resolved as $tag => $val ) {
        $subject = str_replace( $tag, $val, $subject );
    }

    // Send via wp_mail.
    $headers = array( 'Content-Type: text/html; charset=UTF-8' );
    $sent    = wp_mail( $to_email, '[TEST] ' . $subject, $body_html, $headers );

    // Audit log.
    if ( class_exists( 'HL_Audit_Service' ) ) {
        HL_Audit_Service::log( 'email_test_sent', array(
            'entity_type'   => 'email_template',
            'entity_id'     => $template_id,
            'to_email'      => $to_email,
            'enrollment_id' => $enrollment_id,
            'sent'          => $sent,
            'actor_user_id' => $user_id,
        ) );
    }

    if ( $sent ) {
        wp_send_json_success( array(
            'message' => sprintf( 'Test sent to %s using %s\'s data.', $to_email, esc_html( $preview_name ) ),
        ) );
    } else {
        wp_send_json_error( 'wp_mail() failed. Check server mail configuration.' );
    }
}
```

- [ ] **Step 3: Add the send test nonce to inline config**

In `includes/admin/class-hl-admin.php` (line 261-264), add the nonce to the `nonces` array:

```php
'nonces'   => array(
    'toggleStatus'    => wp_create_nonce( 'hl_workflow_toggle_' . $workflow_id ),
    'recipientCount'  => wp_create_nonce( 'hl_workflow_recipient_count' ),
    'sendTest'        => wp_create_nonce( 'hl_workflow_send_test' ),
),
```

- [ ] **Step 4: Test via browser or CLI**

Deploy to test server. Open the workflow edit page. Use browser console:

```javascript
jQuery.post(ajaxurl, {
    action: 'hl_email_send_test',
    nonce: hlEmailWorkflowCfg.nonces.sendTest,
    template_id: 1,
    enrollment_id: 1,
    to_email: 'mateo@corsox.com'
}, function(r) { console.log(r); });
```

Expected: JSON success with "Test sent to mateo@corsox.com using [name]'s data." Check email inbox.

- [ ] **Step 5: Commit**

```bash
git add includes/admin/class-hl-admin-emails.php includes/admin/class-hl-admin.php
git commit -m "feat(email): Send Test Email AJAX endpoint with domain allowlist, rate limit, audit"
```

---

### Task 5a: Card Layout PHP — Delegation + Top Bar + Data Loading

**Files:**
- Modify: `includes/admin/class-hl-admin-emails.php` (add v2 delegation at line 648)

This task creates the v2 form skeleton: delegation method, data loading, top bar, and the two-panel wrapper. Cards 1-2 (Basics + Trigger) are included. Cards 3-5 and the summary panel are in Task 5b.

- [ ] **Step 1: Add the v2 form renderer delegation**

Add a new method `render_workflow_form_v2()`. Update `render_workflow_form()` to delegate:

```php
private function render_workflow_form( $workflow_id ) {
    if ( self::is_v2_ux() ) {
        $this->render_workflow_form_v2( $workflow_id );
        return;
    }
    // ... existing v1 code stays untouched below ...
```

- [ ] **Step 2: Build data loading + top bar + two-panel wrapper**

The `render_workflow_form_v2()` method starts with the same data loading as v1 (workflow row, templates query, conditions/recipients JSON decode). Then renders:

```php
// Top bar.
<div class="hl-wf-topbar">
    <div class="hl-wf-topbar-left">
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=hl-emails&tab=workflows' ) ); ?>">&larr; All Workflows</a>
        <span class="hl-wf-topbar-name"><?php echo esc_html( $workflow->name ?? 'New Workflow' ); ?></span>
    </div>
    <div class="hl-wf-topbar-right">
        <span class="hl-wf-status-badge hl-wf-status-<?php echo esc_attr( $workflow->status ?? 'draft' ); ?>"><?php echo esc_html( ucfirst( $workflow->status ?? 'draft' ) ); ?></span>
        <button type="submit" name="save_action" value="draft" class="hl-wf-btn hl-wf-btn-secondary">Save Draft</button>
        <button type="submit" name="save_action" value="activate" class="hl-wf-btn hl-wf-btn-activate">Activate</button>
    </div>
</div>
```

Then the two-panel wrapper with Card 1 (Basics: name input) and Card 2 (Trigger: existing `<select name="trigger_key">` wrapped in card styling, plus offset/component-type/status-filter rows).

All hidden inputs preserved: `hl_workflow_nonce`, `workflow_id`, `conditions` textarea, `recipients` textarea.

- [ ] **Step 3: Verify form submission works with just Cards 1-2**

Deploy. Create a new workflow with just name + trigger. Save. Verify the save handler processes correctly and the workflow appears in the list.

**Acceptance test:** Form submits, workflow saves to DB, redirect works.

- [ ] **Step 4: Commit**

```bash
git add includes/admin/class-hl-admin-emails.php
git commit -m "feat(email): v2 form delegation + top bar + cards 1-2 (basics + trigger)"
```

---

### Task 5b: Card Layout PHP — Full Cards + Summary Panel + Advanced

**Files:**
- Modify: `includes/admin/class-hl-admin-emails.php` (extend `render_workflow_form_v2()`)

This task adds Cards 3-5, the Advanced section, and the summary panel to the v2 form.

- [ ] **Step 1: Add Card 3 — Conditions**

Add `data-progressive="true"` attribute. Render the existing condition builder HTML (`<div class="hl-condition-builder" data-initial="...">`) inside the card body. The existing JS module initializes it.

- [ ] **Step 2: Add Card 4 — Recipients**

Add `data-progressive="true"`. Render the existing recipient picker HTML (`<div class="hl-recipient-picker" data-initial="...">`) inside the card body.

- [ ] **Step 3: Add Card 5 — Email Template**

Add `data-progressive="true"`. Template `<select name="template_id">` + preview bar showing subject line.

- [ ] **Step 4: Add Advanced collapsed section**

In the Advanced Options collapsed section, REPLACE the existing `$data['send_window_days'] = sanitize_text_field(...)` line in `handle_workflow_save()` (grep for `send_window_days` to find exact line). Replace the text input with checkboxes:

```php
<div class="hl-wf-days-row">
    <?php
    $days = array( 'mon' => 'Mon', 'tue' => 'Tue', 'wed' => 'Wed', 'thu' => 'Thu', 'fri' => 'Fri', 'sat' => 'Sat', 'sun' => 'Sun' );
    $current_days = array_map( 'trim', explode( ',', $workflow->send_window_days ?? 'mon,tue,wed,thu,fri' ) );
    foreach ( $days as $key => $label ) :
    ?>
        <label class="hl-wf-day-check">
            <input type="checkbox" name="send_window_day_<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $current_days, true ) ); ?>>
            <?php echo esc_html( $label ); ?>
        </label>
    <?php endforeach; ?>
</div>
```

In `handle_workflow_save()`, collect the checked days and join back to comma string before saving:

```php
// Collect send_window_days from individual checkboxes.
$day_keys = array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' );
$checked_days = array();
foreach ( $day_keys as $dk ) {
    if ( ! empty( $_POST[ 'send_window_day_' . $dk ] ) ) {
        $checked_days[] = $dk;
    }
}
$data['send_window_days'] = implode( ',', $checked_days );
```

- [ ] **Step 5: Add Summary Panel HTML**

In the `<div class="hl-wf-summary-panel">`, render:

```php
<!-- Onboarding message (shown when no trigger selected on new workflows) -->
<div class="hl-wf-summary-onboarding" <?php echo $workflow ? 'style="display:none;"' : ''; ?>>
    <p>Select a trigger to begin building your workflow.</p>
</div>

<!-- Summary sentence (hidden initially on new workflows) -->
<div class="hl-wf-summary-sentence"></div>

<!-- Recipient preview -->
<p class="hl-recipient-count-hint" aria-live="polite"></p>

<!-- Send Test box -->
<div class="hl-wf-send-test">
    <div class="hl-wf-send-test-label">Send Test Email</div>
    <div style="margin-bottom:8px;">
        <label class="hl-wf-form-label">Preview as:</label>
        <select name="hl_test_enrollment" class="hl-wf-form-select">
            <?php
            $active_enrollments = $wpdb->get_results(
                "SELECT e.enrollment_id, u.display_name FROM {$wpdb->prefix}hl_enrollment e
                 LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
                 WHERE e.status = 'active' ORDER BY u.display_name LIMIT 50"
            );
            foreach ( $active_enrollments as $ae ) :
            ?>
                <option value="<?php echo (int) $ae->enrollment_id; ?>"><?php echo esc_html( $ae->display_name ); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="hl-wf-send-test-row">
        <input type="email" name="hl_test_email" class="hl-wf-send-test-input" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>">
        <button type="button" class="hl-wf-send-test-btn" <?php echo empty( $workflow->template_id ) ? 'disabled' : ''; ?>>Send Test</button>
    </div>
    <div class="hl-wf-send-test-feedback"></div>
    <div class="hl-wf-send-test-hint">Allowed: @housmanlearning.com, @corsox.com, @yopmail.com</div>
</div>

<!-- Guardrails checklist -->
<div class="hl-wf-guardrails">
    <div class="hl-wf-guardrails-label">Activation Checklist</div>
    <div class="hl-wf-guardrail" data-check="trigger"><span class="hl-wf-guardrail-icon"></span> Trigger configured</div>
    <div class="hl-wf-guardrail" data-check="template"><span class="hl-wf-guardrail-icon"></span> Template selected</div>
    <div class="hl-wf-guardrail" data-check="recipients"><span class="hl-wf-guardrail-icon"></span> At least one recipient</div>
</div>

<!-- Mobile drawer toggle -->
<button type="button" class="hl-wf-drawer-toggle">Show Summary</button>
```

- [ ] **Step 6: Verify all cards render and form submission works**

Deploy and test:
1. Create a new workflow — verify only Cards 1-2 visible, summary shows onboarding message
2. Select trigger — verify Cards 3-5 appear
3. Fill all fields, save as Draft — verify all fields save correctly
4. Edit the workflow — verify all values pre-populate, all cards visible
5. Check hidden textareas (`conditions`, `recipients`) are populated by JS

**Acceptance test:** Full round-trip save + load with no data loss.

- [ ] **Step 7: Commit**

```bash
git add includes/admin/class-hl-admin-emails.php
git commit -m "feat(email): v2 cards 3-5, summary panel, send test UI, guardrails HTML, advanced section"
```

---

### Task 6: CSS — Two-Panel Layout + Card Styles

**Files:**
- Modify: `assets/css/admin.css` (add new section after existing `.hl-workflow-form` block)

- [ ] **Step 1: Add the v2 workflow styles**

Add a new CSS section after the existing email admin styles (~line 2176). Key selectors:

```css
/* ================================================================
   EMAIL WORKFLOW v2 — Two-panel card layout
   ================================================================ */

/* Top bar */
.hl-wf-topbar { ... }

/* Two-panel layout */
.hl-wf-layout { display: flex; gap: 0; min-height: calc(100vh - 100px); }
.hl-wf-form-panel { flex: 3; padding: 24px; overflow-y: auto; }
.hl-wf-summary-panel { flex: 2; max-width: 380px; min-width: 320px; background: #fff; border-left: 1px solid #E5E7EB; padding: 24px; position: sticky; top: 32px; height: calc(100vh - 100px); overflow-y: auto; }

/* Cards */
.hl-wf-card { background: #fff; border: 1px solid #E5E7EB; border-radius: 10px; padding: 20px 24px; margin-bottom: 12px; }

/* Progressive disclosure */
.hl-wf-card[data-progressive]:not(.hl-wf-revealed) { display: none; }

/* Responsive */
@media (max-width: 900px) {
    .hl-wf-layout { flex-direction: column; }
    .hl-wf-summary-panel { max-width: 100%; position: fixed; bottom: 0; left: 0; right: 0; height: auto; max-height: 50vh; border-left: none; border-top: 1px solid #E5E7EB; z-index: 100; transform: translateY(calc(100% - 48px)); transition: transform 0.3s; }
    .hl-wf-summary-panel.hl-wf-drawer-open { transform: translateY(0); }
}
```

Follow the mockup styles from `.superpowers/brainstorm/*/content/design-v2-triggers.html` for exact colors, spacing, and hover states. Include styles for:
- `.hl-wf-topbar` (dark bar with flex layout)
- `.hl-wf-card`, `.hl-wf-card-header`, `.hl-wf-card-title`, `.hl-wf-card-badge`
- `.hl-wf-summary-sentence` (indigo background)
- `.hl-wf-send-test` (yellow box)
- `.hl-wf-guardrails` (checklist)
- `.hl-wf-advanced-collapsed` (collapsed section)
- `.hl-wf-days-row` (day checkboxes)
- `.hl-wf-drawer-toggle` (mobile drawer button)

- [ ] **Step 2: Bump version for cache busting**

In `hl-core.php`, bump `HL_CORE_VERSION` (e.g., `1.2.1` → `1.2.2`).

- [ ] **Step 3: Visual verification**

Deploy and check in browser:
- Desktop (>900px): two-panel side by side, summary sticky on scroll
- Narrow (<900px): summary becomes bottom drawer
- Cards: white on gray background, hover border highlight
- Top bar: dark with proper spacing

- [ ] **Step 4: Commit**

```bash
git add assets/css/admin.css hl-core.php
git commit -m "style(email): v2 two-panel card layout CSS with responsive drawer"
```

---

### Task 7: JS — Summary Panel Sync + Guardrails + Send Test + Progressive Disclosure

**Files:**
- Modify: `assets/js/admin/email-workflow.js` (additive — wrap existing code, add new modules)

- [ ] **Step 1: Extract existing modules into named sections**

At the top of the file (after the `'use strict';` line), add section comments to delineate:
```javascript
// =====================================================================
// MODULE: Condition Builder (existing — preserved from v1)
// =====================================================================
```

And before the recipient picker:
```javascript
// =====================================================================
// MODULE: Recipient Picker (existing — preserved from v1)
// =====================================================================
```

No code changes to existing modules — just markers.

- [ ] **Step 2: Add the Summary Panel sync module**

After the recipient picker module, add:

```javascript
// =====================================================================
// MODULE: Summary Panel (v2)
// =====================================================================
var $summaryPanel = $('.hl-wf-summary-panel');
if ($summaryPanel.length) {
    function updateSummary() {
        var templateName = $('select[name="template_id"] option:selected').text() || 'select a template';
        var triggerLabel  = $('select[name="trigger_key"] option:selected').text() || 'select a trigger';
        // ... build sentence from current form values
        // Build recipient list from checked tokens.
        var primaryTokens = [];
        $('.hl-recipient-primary .hl-token-card.hl-token-checked .hl-token-label').each(function() {
            primaryTokens.push($(this).text());
        });
        var ccTokens = [];
        $('.hl-recipient-cc .hl-token-card.hl-token-checked .hl-token-label').each(function() {
            ccTokens.push($(this).text());
        });
        var recipientText = primaryTokens.length ? '<strong>' + primaryTokens.map(escHtml).join(', ') + '</strong>' : '<em class="hl-wf-placeholder">select recipients</em>';
        var ccText = ccTokens.length ? ' (CC: <strong>' + ccTokens.map(escHtml).join(', ') + '</strong>)' : '';

        // Build condition summary from condition rows.
        var condParts = [];
        $('.hl-condition-row').each(function() {
            var fieldLabel = $(this).find('.hl-condition-field option:selected').text();
            var opLabel    = $(this).find('.hl-condition-op option:selected').text();
            var valText    = $(this).find('.hl-condition-value').val() || $(this).find('.hl-pill').map(function(){ return $(this).attr('data-value'); }).get().join(', ');
            if (fieldLabel && fieldLabel !== '— Select field —') {
                condParts.push(escHtml(fieldLabel) + ' ' + escHtml(opLabel) + ' <strong>' + escHtml(valText) + '</strong>');
            }
        });
        var condText = condParts.length ? condParts.join(' AND ') : 'no conditions (matches all)';

        var sentence = 'Send <strong>"' + escHtml(templateName) + '"</strong> to ' + recipientText + ccText
            + '<br><br><strong>When:</strong> ' + escHtml(triggerLabel)
            + '<br><br><strong>Only if:</strong> ' + condText;
        $summaryPanel.find('.hl-wf-summary-sentence').html(sentence);

        // Update guardrails
        updateGuardrails();
    }

    // Listen to all form changes
    $('select[name="trigger_key"], select[name="template_id"]').on('change', updateSummary);
    // Condition/recipient changes (from existing modules)
    $('.hl-condition-builder, .hl-recipient-picker').on('change input click', debounce(updateSummary, 200));
    // Initial render
    updateSummary();
}
```

- [ ] **Step 3: Add the Guardrails module**

```javascript
function updateGuardrails() {
    var checks = {
        trigger:    !!$('select[name="trigger_key"]').val(),
        template:   !!$('select[name="template_id"]').val(),
        recipients: $('.hl-recipient-picker .hl-token-card.hl-token-checked').length > 0
                    || $('.hl-recipient-picker .hl-pill').length > 0,
    };

    // Update checklist UI
    Object.keys(checks).forEach(function(key) {
        var $item = $summaryPanel.find('.hl-wf-guardrail[data-check="' + key + '"]');
        $item.toggleClass('hl-wf-guardrail-ok', checks[key]).toggleClass('hl-wf-guardrail-warn', !checks[key]);
        $item.find('.hl-wf-guardrail-icon').html(checks[key] ? '&#10003;' : '&#10007;');
    });

    // Activate button gating
    var $activateBtn = $('.hl-wf-topbar .hl-wf-btn-activate');
    if (!checks.template) {
        $activateBtn.prop('disabled', true).attr('title', 'Select a template first');
    } else {
        $activateBtn.prop('disabled', false).removeAttr('title');
    }
}
```

- [ ] **Step 4: Add the Send Test module**

```javascript
$('.hl-wf-send-test-btn').on('click', function(e) {
    e.preventDefault();
    var $btn = $(this);
    var $feedback = $btn.closest('.hl-wf-send-test').find('.hl-wf-send-test-feedback');
    var cfg = window.hlEmailWorkflowCfg || {};

    var templateId   = $('select[name="template_id"]').val();
    var enrollmentId = $('select[name="hl_test_enrollment"]').val();
    var toEmail      = $('input[name="hl_test_email"]').val();

    if (!templateId) {
        $feedback.text('Select a template first.').css('color', '#DC2626');
        return;
    }

    $btn.prop('disabled', true).text('Sending...');
    $feedback.text('').css('color', '');

    $.post(cfg.ajaxUrl, {
        action: 'hl_email_send_test',
        nonce: cfg.nonces.sendTest,
        template_id: templateId,
        enrollment_id: enrollmentId,
        to_email: toEmail
    }).done(function(res) {
        if (res.success) {
            $feedback.text(res.data.message).css('color', '#059669');
        } else {
            $feedback.text(res.data || 'Send failed.').css('color', '#DC2626');
        }
    }).fail(function() {
        $feedback.text('Network error.').css('color', '#DC2626');
    }).always(function() {
        $btn.prop('disabled', false).text('Send Test');
    });
});
```

- [ ] **Step 5: Add progressive disclosure**

```javascript
// Reveal Cards 3-5 when trigger is selected (new workflow only).
$('select[name="trigger_key"]').on('change', function() {
    if ($(this).val()) {
        $('.hl-wf-card[data-progressive]').addClass('hl-wf-revealed');
    }
});
// On edit (workflow_id > 0), reveal immediately.
if ((window.hlEmailWorkflowCfg || {}).workflowId > 0) {
    $('.hl-wf-card[data-progressive]').addClass('hl-wf-revealed');
}
```

- [ ] **Step 6: Add top bar name sync**

```javascript
$('input[name="name"]').on('input', function() {
    var val = $(this).val() || 'New Workflow';
    $('.hl-wf-topbar-name').text(val);
});
```

- [ ] **Step 7: Add summary panel onboarding state**

```javascript
// On new workflows, show onboarding until trigger is selected.
$('select[name="trigger_key"]').on('change', function() {
    if ($(this).val()) {
        $('.hl-wf-summary-onboarding').hide();
        $('.hl-wf-summary-sentence').show();
    }
});
```

- [ ] **Step 8: Add Activate button soft-warning dialog**

```javascript
$('.hl-wf-btn-activate').on('click', function(e) {
    var warnings = [];
    if (!$('select[name="trigger_key"]').val()) warnings.push('No trigger selected');
    if (!$('select[name="template_id"]').val()) {
        e.preventDefault();
        alert('Cannot activate: please select an email template first.');
        return;
    }
    var hasRecipients = $('.hl-recipient-picker .hl-token-card.hl-token-checked').length > 0
        || $('.hl-recipient-picker .hl-pill').length > 0;
    if (!hasRecipients) warnings.push('No recipients selected');

    if (warnings.length > 0) {
        if (!confirm('Activate with warnings?\n\n- ' + warnings.join('\n- ') + '\n\nContinue anyway?')) {
            e.preventDefault();
        }
    }
});
```

- [ ] **Step 9: Add mobile drawer toggle**

```javascript
$('.hl-wf-drawer-toggle').on('click', function() {
    var $panel = $('.hl-wf-summary-panel');
    $panel.toggleClass('hl-wf-drawer-open');
    $(this).text($panel.hasClass('hl-wf-drawer-open') ? 'Hide Summary' : 'Show Summary');
});
```

- [ ] **Step 7: Test all interactions**

Deploy and verify in browser:
- Change trigger → summary updates, guardrails update, cards 3-5 appear
- Change template → summary updates
- Click Send Test → AJAX fires, feedback shows
- Change recipients → summary updates, guardrail updates
- Mobile width → drawer toggle works

- [ ] **Step 8: Commit**

```bash
git add assets/js/admin/email-workflow.js
git commit -m "feat(email): v2 summary panel sync, guardrails, send test UI, progressive disclosure"
```

---

### Task 8: Extend Recipient Count to Return Sample Names

**Files:**
- Modify: `includes/admin/class-hl-admin-emails.php` (`ajax_recipient_count()` at line 1502)

- [ ] **Step 1: Extend the response to include sample names**

At the end of `ajax_recipient_count()`, before the `wp_send_json_success` call, add a query to get up to 3 sample user display names based on the resolved user IDs. Add them to the response:

```php
// Collect up to 3 sample names from the matched user IDs.
$sample_names = array();
if ( ! empty( $matched_user_ids ) ) {
    $sample_ids = array_slice( array_keys( $matched_user_ids ), 0, 3 );
    if ( $sample_ids ) {
        $placeholders = implode( ',', array_fill( 0, count( $sample_ids ), '%d' ) );
        $names = $wpdb->get_col( $wpdb->prepare(
            "SELECT display_name FROM {$wpdb->users} WHERE ID IN ($placeholders)",
            ...$sample_ids
        ) );
        $sample_names = $names ?: array();
    }
}

wp_send_json_success( array(
    'count'   => $total_count,
    'samples' => $sample_names,
) );
```

Note: The current implementation counts recipients from multiple sources (tokens, roles, static). You'll need to track user IDs across all sources to provide samples. This requires refactoring the counting logic to collect IDs instead of just incrementing a counter. Collect `$matched_user_ids` as an associative array throughout the method.

- [ ] **Step 2: Update JS to show sample names**

In the `fetchRecipientCount()` function in `email-workflow.js`, update the `.done()` handler:

```javascript
.done(function (res) {
    if (res && res.success && typeof res.data.count === 'number') {
        var text = 'Would match ' + res.data.count + ' recipient' + (res.data.count === 1 ? '' : 's');
        if (res.data.samples && res.data.samples.length) {
            text += ': ' + res.data.samples.join(', ');
            if (res.data.count > res.data.samples.length) {
                text += ' +' + (res.data.count - res.data.samples.length) + ' more';
            }
        }
        $hint.text(text);
    }
})
```

- [ ] **Step 3: Commit**

```bash
git add includes/admin/class-hl-admin-emails.php assets/js/admin/email-workflow.js
git commit -m "feat(email): recipient preview with sample names in summary panel"
```

---

### Task 9: Activation Guardrail on Save

**Files:**
- Modify: `includes/admin/class-hl-admin-emails.php` (`handle_workflow_save()` at line 1027)

- [ ] **Step 1: Add server-side validation when activating**

In `handle_workflow_save()`, after collecting form data but before the INSERT/UPDATE, add:

```php
// Activation guardrails: block if template is missing.
if ( $data['status'] === 'active' && empty( $data['template_id'] ) ) {
    // For new workflows, save as draft first so form data isn't lost.
    if ( ! $workflow_id ) {
        $data['status'] = 'draft';
        $wpdb->insert( "{$wpdb->prefix}hl_email_workflow", $data );
        $workflow_id = (int) $wpdb->insert_id;
    }
    wp_redirect( add_query_arg( array(
        'page'        => 'hl-emails',
        'tab'         => 'workflows',
        'action'      => 'edit',
        'workflow_id'  => $workflow_id,
        'hl_notice'   => 'activation_blocked',
    ), admin_url( 'admin.php' ) ) );
    exit;
}
```

This prevents data loss for new workflows — the form data is saved as a draft before the redirect.

And render the notice in `render_workflow_form_v2()`:

```php
if ( ( $_GET['hl_notice'] ?? '' ) === 'activation_blocked' ) {
    echo '<div class="notice notice-error"><p>Cannot activate: please select an email template first.</p></div>';
}
```

- [ ] **Step 2: Commit**

```bash
git add includes/admin/class-hl-admin-emails.php
git commit -m "feat(email): server-side activation guardrail blocks save without template"
```

---

### Task 10: Deploy + Smoke Test

- [ ] **Step 1: Run existing CLI tests**

```bash
ssh test-server
wp hl-core email-v2-test
```

Expected: All 65 assertions pass.

- [ ] **Step 2: Run smoke test**

```bash
wp hl-core smoke-test
```

Expected: 0 new failures.

- [ ] **Step 3: Browser verification with Playwright**

Test on test server:
1. Open Automated Workflows tab — verify list page still works
2. Click "Add Workflow" — verify card layout renders, only Cards 1-2 visible
3. Select a trigger — verify Cards 3-5 appear
4. Fill all fields, save as Draft — verify save works
5. Edit the workflow — verify all values pre-populate
6. Send Test Email — verify email arrives
7. Try to Activate without template — verify guardrail blocks
8. Activate with template — verify status changes

- [ ] **Step 4: Update STATUS.md and README.md**

Add M1 entry to STATUS.md build queue. Update README.md with new architecture details.

- [ ] **Step 5: Commit docs**

```bash
git add STATUS.md README.md
git commit -m "docs: update STATUS.md + README.md with workflow UX M1"
```

- [ ] **Step 6: Push branch**

```bash
git push -u origin feature/workflow-ux-m1
```

---

## Per-Task Acceptance Criteria

| Task | Pass Criteria |
|---|---|
| 1 | `hl_coaching_condition_migrated` option set. No workflows contain `coaching.session_scheduled` in conditions JSON. Rollback toggle works. |
| 2 | `email-v2-test` 65/65 pass. Coaching session status resolves correctly for both cron and hook triggers. |
| 3 | Operator dropdown shows "is any of" / "is none of" in condition builder. |
| 4 | Send Test AJAX returns success for allowlisted domain, error for non-allowlisted. Rate limit kicks in after 5 sends. Audit log entry created. |
| 5a | Two-panel layout renders. Top bar shows. Cards 1-2 visible. Form submits and saves correctly. |
| 5b | Cards 3-5 render with progressive disclosure. Summary panel HTML present. Enrollment dropdown populated. Day checkboxes save correctly. |
| 6 | Cards styled correctly. Summary panel sticky on scroll. Mobile drawer works at <900px. |
| 7 | Summary sentence updates live. Guardrails update on change. Send Test button works end-to-end. Progressive disclosure works. Name syncs to top bar. Activate dialog shows soft warnings. |
| 8 | Recipient count hint shows sample names. |
| 9 | Activating without template saves as draft + shows error notice. |
| 10 | `email-v2-test` 65/65 pass. `smoke-test` 0 new failures. Playwright browser verification passes. |

## Review Summary

Plan reviewed by 4 agents:
- **UX Engineer (7/10):** 3 gaps — missing name sync, summary empty state, Activate dialog. All fixed.
- **Backend Architect (6/10):** 5 issues — singleton renderer (CRITICAL), session_upcoming entity_type, migration guard, redirect data loss, merge tag API. All fixed.
- **Sales Exec (5/10):** Task order front-loaded backend. Reordered to show visual changes by day 1.5.
- **CEO (6/10):** Task 5 was single point of failure. Split into 5a/5b. MVP-5 fallback defined.
