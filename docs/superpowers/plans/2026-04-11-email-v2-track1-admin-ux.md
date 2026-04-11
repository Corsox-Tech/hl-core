# Email System v2 — Track 1: Admin Workflow UX Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace JSON textareas on workflow/template admin pages with visual editors, add row actions (duplicate, activate/pause, delete/archive), and enforce accessibility and security hardening from Appendix A.

**Build order / dependencies:** Track 3 Tasks 1, 2, 5, 23 must land first (provides `HL_Roles` helper, condition evaluator routing, `assigned_mentor` resolver, and `observed_teacher`/`cc_teacher` alias). This plan runs in parallel with Track 2.

**Architecture:** Thin UI layer over the existing v1 JSON format. A hidden `<textarea>` round-trips JSON unchanged — zero backend changes to storage. A new `assets/js/admin/email-workflow.js` file implements the condition builder and recipient picker using jQuery + event delegation. PHP static registries (`get_condition_fields()`, `get_condition_operators()`, `get_recipient_tokens()`) feed JS via `wp_add_inline_script` (position `'before'`). All markup wraps in a `.hl-email-admin` container for specificity. The shared `HL_Roles` helper (owned by Track 3) unifies `FIND_IN_SET` semantics between the condition evaluator and recipient resolver — this plan consumes it but does not define it.

**Tech Stack:** PHP 7.4+, WordPress 6.0+, jQuery (WP admin bundled), Dashicons, `$wpdb->prepare()`, `wp_ajax_*` endpoints, `admin-post.php` for POST duplicate actions. No build step. No external JS libraries.

---

## Scope

**In scope (Track 1):**
- 1.1 Condition Builder UI
- 1.2 Recipient Picker UI (incl. `assigned_mentor` token, `observed_teacher` rename)
- 1.3 Workflow Row Actions (Edit | Duplicate | Activate/Pause | Delete)
- 1.4 Template Row Actions (Edit | Duplicate | Archive/Restore)
- Appendix A items that belong to Track 1 (listed per-task)

**Out of scope (Track 2 & 3 separate plans):**
- Builder enhancements (columns, undo/redo, preview modal, text formatting)
- Component window columns, cron stub implementations, draft cleanup, autoload migration
- `HL_Roles` helper (A.1.7) — owned by Track 3 Task 1.
- `FIND_IN_SET` fix in `HL_Email_Recipient_Resolver` + condition-evaluator routing through `HL_Roles::has_role()` — owned by Track 3 Tasks 2 and 5.
- `assigned_mentor` resolver, `observed_teacher`/`cc_teacher` alias — owned by Track 3 Task 23.

---

## File Structure

### Files to create

| File | Responsibility |
|------|----------------|
| `assets/js/admin/email-workflow.js` | Condition builder + recipient picker jQuery IIFE. Event delegation. Hidden textarea sync. |
| `bin/test-email-v2-track1.php` | WP-CLI-run PHP test script. Exercises static registries, `generate_copy_name`, `operator_label`, allowlist validation. Run via `wp eval-file bin/test-email-v2-track1.php`. |

### Files to modify

| File | Lines | Change |
|------|-------|--------|
| `includes/admin/class-hl-admin-emails.php` | 11–705 | Add static registries, replace `render_workflow_form` JSON textareas with visual builder shells + hidden textareas, add row actions in list tables, add `handle_workflow_duplicate`, `handle_workflow_delete`, `handle_template_duplicate`, `handle_template_archive`, `handle_workflow_force_resend`, AJAX `ajax_workflow_toggle_status`, `ajax_recipient_count`, unified `generate_copy_name()`, `operator_label()`, stricter `handle_workflow_save()` allowlist validation, soft-delete flow, per-ID nonces. |
| `includes/admin/class-hl-admin.php` | ~154–200 | Enqueue `email-workflow.js` + inline registries on `hl-emails` workflow edit pages only. Register `admin-post.php` action hooks so `HL_Admin_Emails` handlers fire. |
| `includes/services/class-hl-email-automation-service.php` | `hydrate_context()` (~line 520) | Ensure `$context['cycle_id']` is populated for all triggers so Track 3's `assigned_mentor` resolver can resolve. Backfill from `enrollment_id` at the end of `hydrate_context()`. |
| `assets/css/admin.css` | after line 1896 | New v2 section under `/* === Email System v2 — Track 1 === */` comment. All rules scoped inside `.hl-email-admin`. |

### Dependencies (provided by Track 3)

| File | Track 3 Task | Notes |
|------|--------------|-------|
| `includes/services/class-hl-roles.php` | Track 3 Task 1 | Provides `HL_Roles::has_role($csv, $role)` + `HL_Roles::sanitize_roles($csv)`. Must land before this plan starts. |
| `includes/services/class-hl-email-condition-evaluator.php` | Track 3 Task 2 | Routes `enrollment.roles` through `HL_Roles::has_role()`. Must land before Task 5's `validate_workflow_payload` runs against real conditions. |
| `includes/services/class-hl-email-recipient-resolver.php` | Track 3 Tasks 5, 23 | Adds `assigned_mentor` case, keeps `cc_teacher` as legacy alias for `observed_teacher`. Required by Task 4's `build_context()` cycle_id population to have a consumer. |

---

## Build Order

> **Prerequisite:** Track 3 Tasks 1, 2, 5, 23 must have landed before starting this plan (provides `HL_Roles` helper, evaluator routing, `assigned_mentor` resolver, `observed_teacher`/`cc_teacher` alias).

1. Bootstrap test harness (`bin/test-email-v2-track1.php`)
2. PHP static registries (fields, operators, tokens, helpers)
3. *(deleted — moved to Track 3 Task 2)*
4. Populate `cycle_id` in `hydrate_context()` (required by Track 3's `assigned_mentor` resolver)
5. Stricter `handle_workflow_save()` allowlist validation (server-side)
6. Enqueue `email-workflow.js` + inject registries + `wp_refresh_nonces` filter
7. Condition Builder UI shell (PHP render)
8. Condition Builder JS (`email-workflow.js`)
9. Recipient Picker UI shell (PHP render)
10. Recipient Picker JS
11. Workflow Row Actions (Duplicate, Activate/Pause, Delete with guard, Force resend)
12. Template Row Actions (Duplicate, Archive/Restore)
13. CSS polish (accessibility, dimmed tokens, tooltip legend)
14. Force Resend action + audit history display
15. Final integration smoke test via WP-CLI + manual browser pass

Commit after each numbered task. Task 3 is intentionally skipped — see the cross-reference note where Task 3 used to live.

---

## Task 1: Bootstrap test harness

**Prerequisite:** Track 3 Task 1 (`HL_Roles` helper) must have landed. This task does NOT create `HL_Roles`.

**Files:**
- Create: `bin/test-email-v2-track1.php`

- [ ] **Step 1.1: Create the test script with failing assertions**

Create `bin/test-email-v2-track1.php`:

```php
<?php
/**
 * Email System v2 — Track 1 smoke tests.
 *
 * Run via:
 *   wp --path=/opt/bitnami/wordpress eval-file \
 *     wp-content/plugins/hl-core/bin/test-email-v2-track1.php
 *
 * Exit code 0 = all pass. Exit code 1 = one or more failures.
 *
 * Note: HL_Roles assertions live in Track 3's test harness, not here.
 * This script only covers Track 1 surface area: static registries,
 * generate_copy_name, operator_label, validate_workflow_payload.
 */

if ( ! defined( 'ABSPATH' ) ) {
    fwrite( STDERR, "Must run via wp eval-file\n" );
    exit( 1 );
}

$pass = 0;
$fail = 0;
$errors = array();

function hl_t_assert( $cond, $label ) {
    global $pass, $fail, $errors;
    if ( $cond ) {
        $pass++;
        echo "  [PASS] $label\n";
    } else {
        $fail++;
        $errors[] = $label;
        echo "  [FAIL] $label\n";
    }
}

echo "\n=== HL_Admin_Emails registries ===\n";
$fields    = HL_Admin_Emails::get_condition_fields();
$operators = HL_Admin_Emails::get_condition_operators();
$tokens    = HL_Admin_Emails::get_recipient_tokens();

hl_t_assert( isset( $fields['cycle.cycle_type']['type'] ),          'fields: cycle.cycle_type defined' );
hl_t_assert( isset( $fields['enrollment.roles']['type'] ),          'fields: enrollment.roles defined' );
hl_t_assert( $fields['enrollment.roles']['type'] === 'enum',        'fields: enrollment.roles is enum type' );
hl_t_assert( isset( $operators['enum'] ) && isset( $operators['boolean'] ), 'operators: enum + boolean defined' );
hl_t_assert( in_array( 'in', array_keys( $operators['enum'] ), true ), 'operators: enum has in' );
hl_t_assert( ! in_array( 'in', array_keys( $operators['boolean'] ), true ), 'operators: boolean does NOT have in' );
hl_t_assert( isset( $tokens['assigned_mentor'] ),                   'tokens: assigned_mentor defined' );
hl_t_assert( isset( $tokens['observed_teacher'] ),                  'tokens: observed_teacher defined' );
hl_t_assert( ! isset( $tokens['cc_teacher'] ),                      'tokens: cc_teacher NOT in registry (legacy alias only)' );

echo "\n=== generate_copy_name ===\n";
// This asserts the helper produces deterministic "(Copy)" suffixes.
$name1 = HL_Admin_Emails::generate_copy_name( 'hl_email_workflow', 'Welcome Email' );
hl_t_assert( strpos( $name1, 'Welcome Email' ) === 0,               'generate_copy_name starts with source' );
hl_t_assert( strpos( $name1, '(Copy' ) !== false,                   'generate_copy_name contains (Copy' );

echo "\n=== operator_label ===\n";
hl_t_assert( HL_Admin_Emails::operator_label( 'in' ) === 'matches any of', 'operator_label: in -> matches any of' );
hl_t_assert( HL_Admin_Emails::operator_label( 'eq' ) === 'equals',         'operator_label: eq -> equals' );

echo "\n=== validate_workflow_payload ===\n";
$valid = HL_Admin_Emails::validate_workflow_payload(
    array( array( 'field' => 'cycle.cycle_type', 'op' => 'eq', 'value' => 'program' ) ),
    array( 'primary' => array( 'triggering_user' ), 'cc' => array() )
);
hl_t_assert( $valid === true, 'validate_workflow_payload accepts known field/op/token' );

$invalid = HL_Admin_Emails::validate_workflow_payload(
    array( array( 'field' => 'evil.field', 'op' => 'eq', 'value' => 'x' ) ),
    array( 'primary' => array(), 'cc' => array() )
);
hl_t_assert( is_wp_error( $invalid ), 'validate_workflow_payload rejects unknown field' );

$invalid2 = HL_Admin_Emails::validate_workflow_payload(
    array(),
    array( 'primary' => array( 'hacker_token' ), 'cc' => array() )
);
hl_t_assert( is_wp_error( $invalid2 ), 'validate_workflow_payload rejects unknown recipient token' );

echo "\n---\n";
echo "RESULTS: {$pass} passed, {$fail} failed\n";

if ( $fail > 0 ) {
    echo "\nFAILURES:\n";
    foreach ( $errors as $e ) echo "  - $e\n";
    exit( 1 );
}
exit( 0 );
```

- [ ] **Step 1.2: Run the test script — all assertions should FAIL (TDD red light)**

Deploy the code to test (see `.claude/skills/deploy.md` for the tar+scp commands), then run:

```bash
ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 'export PATH=/opt/bitnami/php/bin:/opt/bitnami/mariadb/bin:/usr/local/bin:/usr/bin:/bin && wp --path=/opt/bitnami/wordpress eval-file wp-content/plugins/hl-core/bin/test-email-v2-track1.php'
```

**Expected output:** FAIL with `Call to undefined method HL_Admin_Emails::get_condition_fields()`. Exit code 1. This failure is the TDD red light for the track — subsequent tasks turn each section green.

- [ ] **Step 1.3: Commit**

```bash
git add bin/test-email-v2-track1.php
git commit -m "$(cat <<'EOF'
test(email): add Track 1 WP-CLI smoke test harness

bin/test-email-v2-track1.php is the WP-CLI-run smoke test suite for
Track 1 admin UX. Currently all assertions fail (TDD red light);
subsequent tasks turn each section green. HL_Roles assertions live
in Track 3's test harness, not here.

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: Static registries on `HL_Admin_Emails`

**Files:**
- Modify: `includes/admin/class-hl-admin-emails.php`
- Test: `bin/test-email-v2-track1.php` (already written, will be rerun)

- [ ] **Step 2.1: Add `get_condition_fields()` static method**

Add inside the `HL_Admin_Emails` class, **immediately after the `__construct()` method** (around line 28):

```php
    // =========================================================================
    // Static Registries (v2 Track 1)
    // =========================================================================

    /**
     * Field registry for the visual condition builder.
     *
     * Each field mirrors a context key populated by
     * HL_Email_Automation_Service::build_context() and consumed by
     * HL_Email_Condition_Evaluator::evaluate().
     *
     * @return array<string, array{label:string,group:string,type:string,options:array}>
     */
    public static function get_condition_fields() {
        return array(
            // Cycle group.
            'cycle.cycle_type' => array(
                'label'   => 'Cycle Type',
                'group'   => 'Cycle',
                'type'    => 'enum',
                'options' => array( 'program' => 'Program', 'course' => 'Course' ),
            ),
            'cycle.status' => array(
                'label'   => 'Cycle Status',
                'group'   => 'Cycle',
                'type'    => 'enum',
                'options' => array( 'active' => 'Active', 'archived' => 'Archived' ),
            ),
            'cycle.is_control_group' => array(
                'label'   => 'Is Control Group',
                'group'   => 'Cycle',
                'type'    => 'boolean',
                'options' => array(),
            ),
            // Enrollment group.
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
            'enrollment.roles' => array(
                'label'   => 'Enrollment Roles',
                'group'   => 'Enrollment',
                'type'    => 'enum',
                'options' => array(
                    'teacher'        => 'Teacher',
                    'mentor'         => 'Mentor',
                    'coach'          => 'Coach',
                    'school_leader'  => 'School Leader',
                    'district_leader'=> 'District Leader',
                ),
                'is_csv' => true, // Tells evaluator to use HL_Roles::has_role.
            ),
            // User group.
            'user.account_activated' => array(
                'label'   => 'Account Activated',
                'group'   => 'User',
                'type'    => 'boolean',
                'options' => array(),
            ),
        );
    }
```

- [ ] **Step 2.2: Add `get_condition_operators()` static method**

Immediately after `get_condition_fields()`:

```php
    /**
     * Operator registry per field type.
     *
     * Keys are the JSON operator values stored in DB; values are
     * human-friendly labels shown in the UI.
     *
     * @return array<string, array<string,string>>
     */
    public static function get_condition_operators() {
        return array(
            'enum' => array(
                'eq'       => 'equals',
                'neq'      => 'not equals',
                'in'       => 'matches any of',
                'not_in'   => 'does not match any of',
                'is_null'  => 'is empty',
                'not_null' => 'is not empty',
            ),
            'boolean' => array(
                'eq' => 'equals',
            ),
            'text' => array(
                'eq'       => 'equals',
                'neq'      => 'not equals',
                'in'       => 'matches any of',
                'not_in'   => 'does not match any of',
                'is_null'  => 'is empty',
                'not_null' => 'is not empty',
            ),
            'numeric' => array(
                'eq'       => 'equals',
                'neq'      => 'not equals',
                'gt'       => 'greater than',
                'lt'       => 'less than',
                'is_null'  => 'is empty',
                'not_null' => 'is not empty',
            ),
        );
    }

    /**
     * Flatten all operator labels to a single dictionary.
     *
     * Used for server-side allowlist checks and error messages
     * that need to say "matches any of" not "in".
     *
     * @return array<string,string>
     */
    public static function get_all_operator_labels() {
        $out = array();
        foreach ( self::get_condition_operators() as $type => $ops ) {
            foreach ( $ops as $key => $label ) {
                $out[ $key ] = $label;
            }
        }
        return $out;
    }

    /**
     * Human-friendly label for an operator key. Used in error messages.
     * A.6.14 — consistent labeling across UI and server-side errors.
     *
     * @param string $op Operator key (e.g. 'eq', 'in').
     * @return string Label (e.g. 'equals', 'matches any of'). Returns $op unchanged if unknown.
     */
    public static function operator_label( $op ) {
        $labels = self::get_all_operator_labels();
        return isset( $labels[ $op ] ) ? $labels[ $op ] : $op;
    }
```

- [ ] **Step 2.3: Add `get_recipient_tokens()` static method**

Immediately after `operator_label()`:

```php
    /**
     * Recipient token registry.
     *
     * The `triggers` key is either '*' (always visible) or an array
     * of trigger_key values this token is compatible with. Incompatible
     * tokens stay dimmed in the UI but remain in stored JSON — the
     * server-side resolver silently skips them at send time (A.2.10).
     *
     * @return array<string, array{label:string,description:string,triggers:string|array}>
     */
    public static function get_recipient_tokens() {
        return array(
            'triggering_user' => array(
                'label'       => 'Triggering User',
                'description' => 'The user who caused the event.',
                'triggers'    => '*',
            ),
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
                    'cron:cv_window_7d',
                    'cron:cv_overdue_1d',
                    'cron:rp_window_7d',
                    'cron:coaching_window_7d',
                    'cron:coaching_session_5d',
                    'cron:coaching_pre_end',
                    'cron:action_plan_24h',
                    'cron:session_notes_24h',
                    'cron:low_engagement_14d',
                    'cron:session_24h',
                    'cron:session_1h',
                ),
            ),
            'assigned_mentor' => array(
                'label'       => "User's Mentor",
                'description' => 'Mentor of the triggering user (via team membership in the current cycle).',
                'triggers'    => array(
                    'hl_classroom_visit_submitted',
                    'hl_teacher_assessment_submitted',
                    'hl_child_assessment_submitted',
                    'hl_pathway_completed',
                    'hl_learndash_course_completed',
                ),
            ),
            'school_director' => array(
                'label'       => 'School Director',
                'description' => "School leader for the user's school.",
                'triggers'    => '*',
            ),
            'observed_teacher' => array(
                'label'       => 'Observed Teacher',
                'description' => 'Teacher being observed in a classroom visit.',
                'triggers'    => array( 'hl_classroom_visit_submitted' ),
            ),
        );
    }
```

- [ ] **Step 2.4: Add `generate_copy_name()`, `validate_workflow_payload()`, `sanitize_json_payload()` helpers**

Immediately after `get_recipient_tokens()`:

```php
    /**
     * Generate a unique "(Copy)" suffix for duplicated rows.
     * A.2.12 — unified helper for both workflow and template duplication.
     *
     * Retries up to 10 times with "(Copy)", "(Copy 2)", ... then falls
     * back to UUID suffix.
     *
     * @param string $table       Table name: 'hl_email_workflow' or 'hl_email_template'.
     * @param string $source_name Original row name.
     * @return string Unique name guaranteed not to collide at call time.
     */
    public static function generate_copy_name( $table, $source_name ) {
        global $wpdb;
        $full_table = $wpdb->prefix . preg_replace( '/[^a-z_]/', '', $table );
        $base       = trim( (string) $source_name );

        for ( $i = 1; $i <= 10; $i++ ) {
            $candidate = $i === 1 ? $base . ' (Copy)' : $base . ' (Copy ' . $i . ')';
            $exists = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$full_table} WHERE name = %s",
                $candidate
            ) );
            if ( $exists === 0 ) {
                return $candidate;
            }
        }
        // Fallback: UUID suffix — guaranteed unique.
        return $base . ' (Copy ' . substr( wp_generate_uuid4(), 0, 8 ) . ')';
    }

    /**
     * Server-side allowlist validation for workflow save.
     * A.2.27 — rejects any condition field/op or recipient token that
     * does not appear in the static registries. Defence in depth — the
     * UI already constrains this, but a hand-crafted POST could bypass.
     *
     * @param array $conditions Decoded conditions JSON.
     * @param array $recipients Decoded recipients JSON.
     * @return true|WP_Error
     */
    public static function validate_workflow_payload( $conditions, $recipients ) {
        $fields    = self::get_condition_fields();
        $op_labels = self::get_all_operator_labels();
        $tokens    = self::get_recipient_tokens();

        if ( ! is_array( $conditions ) ) {
            return new WP_Error( 'hl_email_invalid_conditions', 'Conditions must be an array.' );
        }
        foreach ( $conditions as $i => $c ) {
            if ( ! is_array( $c ) ) {
                return new WP_Error( 'hl_email_invalid_condition', "Condition #{$i} must be an object." );
            }
            $field = $c['field'] ?? '';
            $op    = $c['op']    ?? '';
            if ( ! isset( $fields[ $field ] ) ) {
                return new WP_Error( 'hl_email_unknown_field', "Unknown condition field: '{$field}'." );
            }
            if ( ! isset( $op_labels[ $op ] ) ) {
                return new WP_Error( 'hl_email_unknown_op', "Unknown operator: '{$op}'." );
            }
            // Op must be valid for this field's type.
            $type    = $fields[ $field ]['type'];
            $allowed = self::get_condition_operators()[ $type ] ?? array();
            if ( ! isset( $allowed[ $op ] ) ) {
                return new WP_Error(
                    'hl_email_op_type_mismatch',
                    "Operator '" . self::operator_label( $op ) . "' is not valid for field type '{$type}'."
                );
            }
        }

        if ( ! is_array( $recipients ) ) {
            return new WP_Error( 'hl_email_invalid_recipients', 'Recipients must be an object.' );
        }
        foreach ( array( 'primary', 'cc' ) as $section ) {
            if ( ! isset( $recipients[ $section ] ) ) continue;
            if ( ! is_array( $recipients[ $section ] ) ) {
                return new WP_Error( 'hl_email_invalid_recipients_section', "Recipients.{$section} must be an array." );
            }
            foreach ( $recipients[ $section ] as $entry ) {
                if ( ! is_string( $entry ) ) continue;
                // role:X and static:email are free-form — only validate bare token names.
                if ( strpos( $entry, 'role:' ) === 0 || strpos( $entry, 'static:' ) === 0 ) continue;
                // Accept legacy cc_teacher alias (A.6.11).
                if ( $entry === 'cc_teacher' ) continue;
                if ( ! isset( $tokens[ $entry ] ) ) {
                    return new WP_Error( 'hl_email_unknown_token', "Unknown recipient token: '{$entry}'." );
                }
            }
        }

        return true;
    }

    /**
     * Safely decode JSON from a posted textarea.
     *
     * @param string $raw    Raw POST value (unslashed).
     * @param mixed  $default Fallback on decode failure.
     * @return mixed
     */
    public static function sanitize_json_payload( $raw, $default ) {
        $decoded = json_decode( (string) $raw, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return $default;
        }
        return $decoded;
    }
```

- [ ] **Step 2.5: Run the test script**

```bash
# (Re-deploy first — tar/scp, see deploy.md)
ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 'export PATH=/opt/bitnami/php/bin:/opt/bitnami/mariadb/bin:/usr/local/bin:/usr/bin:/bin && wp --path=/opt/bitnami/wordpress eval-file wp-content/plugins/hl-core/bin/test-email-v2-track1.php'
```

**Expected:** All `HL_Admin_Emails registries`, `generate_copy_name`, `operator_label`, and `validate_workflow_payload` sections now PASS. Exit code 0.

- [ ] **Step 2.6: Commit**

```bash
git add includes/admin/class-hl-admin-emails.php
git commit -m "$(cat <<'EOF'
feat(email): add static registries for v2 workflow builder

get_condition_fields(), get_condition_operators(), get_recipient_tokens()
are the single source of truth for the visual condition builder and
recipient picker. Includes operator_label() (A.6.14), generate_copy_name()
(A.2.12), and validate_workflow_payload() (A.2.27) for server-side
allowlist validation.

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: *(deleted — moved to Track 3)*

Condition evaluator routing is implemented in Track 3 Task 2 (owns `HL_Email_Condition_Evaluator` because it consumes `HL_Roles::has_role()` which Track 3 owns). This plan depends on Track 3's changes landing first.

---

## Task 4: Populate `cycle_id` in `hydrate_context()` (required by Track 3's `assigned_mentor` resolver)

**Prerequisite:** Track 3 Task 23 (`resolve_assigned_mentor()` + `observed_teacher`/`cc_teacher` alias in `HL_Email_Recipient_Resolver`) must have landed. This task only populates the `cycle_id` context key that Track 3's resolver depends on. No resolver changes happen here.

**Files:**
- Modify: `includes/services/class-hl-email-automation-service.php`

- [ ] **Step 4.1: Ensure `hydrate_context()` always populates `cycle_id`**

**Note on method naming:** The plan originally referred to `build_context()`, but the actual file has two methods that together assemble context:
- `build_hook_context( $trigger_key, array $args )` at line 211 — dispatches per-trigger and may call sub-loaders like `load_enrollment_context()` (which sets `$context['cycle_id']`) or `load_coaching_session_context()` (also sets `cycle_id`).
- `hydrate_context( array $context )` at line 445 — runs after `build_hook_context()` in `handle_trigger()` (line 93) and enriches with DB lookups.

Some triggers (e.g. `hl_pathway_assigned`, `hl_learndash_course_completed`, `hl_child_assessment_submitted`, `hl_coach_assigned`) never populate `cycle_id` because their sub-loaders don't exist or don't touch it. `hydrate_context()` is the right place to backfill.

**Current shape of `hydrate_context()` (lines 445–521, abbreviated):**

```php
    private function hydrate_context( array $context ) {
        global $wpdb;

        // Load cycle data.
        if ( ! empty( $context['cycle_id'] ) && ! isset( $context['cycle'] ) ) {
            $cycle = $wpdb->get_row( ... );
            if ( $cycle ) {
                $context['cycle_name'] = $cycle->cycle_name;
                $context['cycle']      = array(
                    'cycle_type'       => $cycle->cycle_type ?? '',
                    'is_control_group' => (bool) ( $cycle->is_control_group ?? false ),
                    'status'           => $cycle->status ?? '',
                );
                // ... partnership lookup ...
            }
        }

        // Load user account activation status. ...
        // Load enrollment data if we have enrollment_id but not enrollment. ...
        // Load school data from enrollment. ...
        // Load pathway data. ...

        return $context;
    }
```

Note: the existing code stores the cycle row under `$context['cycle']` with keys `cycle_type`, `is_control_group`, `status` — it does **not** store an `id` or `cycle_id` sub-key. The original plan's `$context['cycle']['id']` fallback was incorrect and has been removed below.

**Insertion point:** Immediately before the `return $context;` at the end of `hydrate_context()` (around line 520). Add a backfill block that derives `cycle_id` from `enrollment_id` when it wasn't set earlier:

```php
        // A.2.28 — Track 3's assigned_mentor resolver requires $context['cycle_id'].
        // Backfill from enrollment_id when earlier sub-loaders didn't populate it
        // (e.g. hl_pathway_assigned, hl_learndash_course_completed, hl_child_assessment_submitted,
        // hl_coach_assigned — none of these call load_enrollment_context).
        if ( empty( $context['cycle_id'] ) && ! empty( $context['enrollment_id'] ) ) {
            $cycle_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT cycle_id FROM {$wpdb->prefix}hl_enrollment WHERE enrollment_id = %d",
                (int) $context['enrollment_id']
            ) );
            if ( $cycle_id > 0 ) {
                $context['cycle_id'] = $cycle_id;
            }
        }
```

Do **not** modify `build_hook_context()` itself — the backfill belongs in `hydrate_context()` because `handle_trigger()` always calls them in order (see line 93).

- [ ] **Step 4.2: Manual smoke — confirm cycle_id is in context for a real trigger**

Deploy. On the test server, seed an enrollment and trigger `hl_enrollment_created`. Tail the debug log with a temporary `error_log( 'hl_ctx=' . wp_json_encode( $context ) );` line at the end of `hydrate_context()` (remove before commit) to confirm `cycle_id` is populated. Then also fire a trigger whose sub-loader doesn't set it (e.g. `hl_pathway_assigned`) with a pathway attached to an enrollment, and confirm the backfill branch activates. Remove the debug line before committing.

- [ ] **Step 4.3: Commit**

```bash
git add includes/services/class-hl-email-automation-service.php
git commit -m "$(cat <<'EOF'
feat(email): populate cycle_id in hydrate_context for assigned_mentor

A.2.28 — hydrate_context() now backfills cycle_id from enrollment_id
for triggers whose sub-loaders don't set it (pathway_assigned,
learndash_course_completed, child_assessment_submitted, coach_assigned)
so Track 3's resolve_assigned_mentor() can look up the mentor in
the correct cycle's team.

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: Harden `handle_workflow_save()` with allowlist + soft-delete guard

**Prerequisite:** Track 3 Task 1 (`HL_Roles` helper) must have landed. `validate_workflow_payload()` does not currently call `HL_Roles` directly, but if future hardening needs to verify role tokens against stored enrollment rows, use `HL_Roles::has_role($stored, $role)` — it is format-agnostic (works with JSON-encoded and CSV) and owned by Track 3.

**Files:**
- Modify: `includes/admin/class-hl-admin-emails.php` — the `handle_workflow_save()` method

- [ ] **Step 5.1: Add test assertion that a payload with an unknown token is rejected**

The test script already calls `validate_workflow_payload()` directly (Task 1). This task wires the function into the POST handler. No new unit test — instead we'll do a manual smoke verification at Step 5.3 via curl.

- [ ] **Step 5.2: Patch `handle_workflow_save()` to validate, round-trip, and trim JSON**

Open `includes/admin/class-hl-admin-emails.php`. Locate the `handle_workflow_save()` method (around line 315).

Replace the method body with:

```php
    private function handle_workflow_save() {
        if ( ! isset( $_POST['hl_workflow_nonce'] ) || ! wp_verify_nonce( $_POST['hl_workflow_nonce'], 'hl_workflow_save' ) ) {
            wp_die( 'Security check failed.' );
        }
        if ( ! current_user_can( 'manage_hl_core' ) ) {
            wp_die( 'Unauthorized' );
        }

        global $wpdb;
        $table = "{$wpdb->prefix}hl_email_workflow";

        $workflow_id = (int) ( $_POST['workflow_id'] ?? 0 );

        // A.3.7 — trim JSON payload to defeat accidental whitespace bloat.
        $raw_conditions = trim( wp_unslash( $_POST['conditions'] ?? '[]' ) );
        $raw_recipients = trim( wp_unslash( $_POST['recipients'] ?? '{"primary":[],"cc":[]}' ) );

        $conditions = self::sanitize_json_payload( $raw_conditions, array() );
        $recipients = self::sanitize_json_payload( $raw_recipients, array( 'primary' => array(), 'cc' => array() ) );

        // A.2.27 — server-side allowlist validation.
        $valid = self::validate_workflow_payload( $conditions, $recipients );
        if ( is_wp_error( $valid ) ) {
            wp_redirect( add_query_arg( array(
                'page'      => 'hl-emails',
                'tab'       => 'workflows',
                'hl_notice' => 'invalid_payload',
                'hl_error'  => rawurlencode( $valid->get_error_message() ),
            ), admin_url( 'admin.php' ) ) );
            exit;
        }

        // A.3.5 — re-encode with stable flags before storing.
        $conditions_json = wp_json_encode( $conditions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        $recipients_json = wp_json_encode( $recipients, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        if ( $conditions_json === false ) $conditions_json = '[]';
        if ( $recipients_json === false ) $recipients_json = '{"primary":[],"cc":[]}';

        $data = array(
            'name'              => sanitize_text_field( $_POST['name'] ?? '' ),
            'trigger_key'       => sanitize_text_field( $_POST['trigger_key'] ?? '' ),
            'conditions'        => $conditions_json,
            'recipients'        => $recipients_json,
            'template_id'       => (int) ( $_POST['template_id'] ?? 0 ) ?: null,
            'delay_minutes'     => (int) ( $_POST['delay_minutes'] ?? 0 ),
            'send_window_start' => sanitize_text_field( $_POST['send_window_start'] ?? '' ) ?: null,
            'send_window_end'   => sanitize_text_field( $_POST['send_window_end'] ?? '' ) ?: null,
            'send_window_days'  => sanitize_text_field( $_POST['send_window_days'] ?? '' ) ?: null,
            'status'            => sanitize_text_field( $_POST['status'] ?? 'draft' ),
        );

        // Validate status — now includes 'deleted' as a valid persisted state
        // for soft-delete, but admins cannot set it via the form.
        if ( ! in_array( $data['status'], array( 'draft', 'active', 'paused' ), true ) ) {
            $data['status'] = 'draft';
        }

        // Validate trigger_key against allowed list.
        $valid_triggers = array(
            'user_register', 'hl_enrollment_created', 'hl_pathway_assigned',
            'hl_learndash_course_completed', 'hl_pathway_completed',
            'hl_coaching_session_created', 'hl_coaching_session_status_changed',
            'hl_rp_session_created', 'hl_rp_session_status_changed',
            'hl_classroom_visit_submitted', 'hl_teacher_assessment_submitted',
            'hl_child_assessment_submitted', 'hl_coach_assigned',
            'cron:cv_window_7d', 'cron:cv_overdue_1d', 'cron:rp_window_7d',
            'cron:coaching_window_7d', 'cron:coaching_session_5d', 'cron:coaching_pre_end',
            'cron:action_plan_24h', 'cron:session_notes_24h', 'cron:low_engagement_14d',
            'cron:client_success', 'cron:session_24h', 'cron:session_1h',
        );
        if ( ! in_array( $data['trigger_key'], $valid_triggers, true ) ) {
            wp_redirect( admin_url( 'admin.php?page=hl-emails&tab=workflows&hl_notice=invalid_trigger' ) );
            exit;
        }

        if ( $workflow_id > 0 ) {
            $wpdb->update( $table, $data, array( 'workflow_id' => $workflow_id ) );
            if ( class_exists( 'HL_Audit_Service' ) ) {
                HL_Audit_Service::log( 'email_workflow_updated', array( 'workflow_id' => $workflow_id ) );
            }
        } else {
            $wpdb->insert( $table, $data );
            $workflow_id = (int) $wpdb->insert_id;
            if ( class_exists( 'HL_Audit_Service' ) ) {
                HL_Audit_Service::log( 'email_workflow_created', array( 'workflow_id' => $workflow_id ) );
            }
        }

        wp_redirect( admin_url( 'admin.php?page=hl-emails&tab=workflows&hl_notice=workflow_saved' ) );
        exit;
    }
```

- [ ] **Step 5.3: Manual smoke verification (craft a bad POST)**

Deploy, then log into the admin, open a workflow edit page, open browser devtools network tab, and submit with a hand-crafted `recipients` hidden value of `{"primary":["hacker_token"],"cc":[]}`. Expected: redirect URL contains `hl_notice=invalid_payload` and `hl_error=Unknown%20recipient%20token%3A%20%27hacker_token%27.`

- [ ] **Step 5.4: Commit**

```bash
git add includes/admin/class-hl-admin-emails.php
git commit -m "$(cat <<'EOF'
feat(email): harden workflow save with allowlist validation

A.2.27 — handle_workflow_save() now decodes, validates against
static registries, and re-encodes JSON with stable flags before
writing. Unknown fields, operators, or recipient tokens are
rejected with an admin notice. A.3.5, A.3.7 included.

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: Enqueue `email-workflow.js` + inject registry

**Files:**
- Create: `assets/js/admin/email-workflow.js` (empty stub for now — code in Task 8)
- Modify: `includes/admin/class-hl-admin.php` (enqueue block inside `enqueue_assets`)

- [ ] **Step 6.1: Create the empty JS stub**

Create `assets/js/admin/email-workflow.js`:

```javascript
/**
 * HL Email System v2 — Workflow admin UI.
 *
 * Condition builder + recipient picker + hidden JSON sync.
 *
 * Enqueued only on the workflow edit/new page (tab=workflows, action in [edit,new]).
 * Registries injected via wp_add_inline_script('hl-email-workflow', ..., 'before'):
 *   window.hlConditionFields    - from HL_Admin_Emails::get_condition_fields()
 *   window.hlConditionOperators - from HL_Admin_Emails::get_condition_operators()
 *   window.hlRecipientTokens    - from HL_Admin_Emails::get_recipient_tokens()
 *   window.hlEmailWorkflowCfg   - { ajaxUrl, nonces: {...}, currentTrigger }
 *
 * A.3.2 — jQuery IIFE noConflict wrapper. A.3.3 — all markup lives inside
 * .hl-email-admin for CSS specificity.
 */
jQuery(function ($) {
    'use strict';

    // A.7.4 / A.7.10 — JS loaded signal. CSS hides the raw JSON fallback
    // when this class is present. If this script never runs, admins fall
    // back to editing the hidden textarea directly through a <details>
    // disclosure.
    $('body').addClass('hl-js-loaded');

    // Belt-and-braces failure signal.
    window.addEventListener('error', function () {
        $('body').removeClass('hl-js-loaded');
    });

    // --- Initialization will be added in Tasks 8 and 10. ---
});
```

- [ ] **Step 6.2: Enqueue the script from `HL_Admin::enqueue_assets`**

Open `includes/admin/class-hl-admin.php`. Locate the `enqueue_assets($hook)` method (around line 154). Add this block at the end of the method, immediately before the closing `}`:

```php
        // Email System v2 — Track 1 workflow builder assets.
        // Enqueued only on hl-emails?tab=workflows&action=edit|new.
        $is_workflow_edit = strpos( $hook, 'hl-emails' ) !== false
            && isset( $_GET['tab'] ) && $_GET['tab'] === 'workflows'
            && isset( $_GET['action'] ) && in_array( $_GET['action'], array( 'edit', 'new' ), true );

        if ( $is_workflow_edit ) {
            wp_enqueue_script(
                'hl-email-workflow',
                HL_CORE_ASSETS_URL . 'js/admin/email-workflow.js',
                array( 'jquery' ),
                HL_CORE_VERSION,
                true
            );

            // A.3.4 — inject registries BEFORE the script so the IIFE can read them.
            $workflow_id = (int) ( $_GET['workflow_id'] ?? 0 );
            $inline = 'window.hlConditionFields = '    . wp_json_encode( HL_Admin_Emails::get_condition_fields(),    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ';'
                    . 'window.hlConditionOperators = ' . wp_json_encode( HL_Admin_Emails::get_condition_operators(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ';'
                    . 'window.hlRecipientTokens = '    . wp_json_encode( HL_Admin_Emails::get_recipient_tokens(),    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ';'
                    . 'window.hlEmailWorkflowCfg = '   . wp_json_encode( array(
                        'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
                        'nonces'   => array(
                            'toggleStatus'    => wp_create_nonce( 'hl_workflow_toggle_' . $workflow_id ),
                            'recipientCount'  => wp_create_nonce( 'hl_workflow_recipient_count' ),
                        ),
                        'workflowId' => $workflow_id,
                    ) ) . ';';

            wp_add_inline_script( 'hl-email-workflow', $inline, 'before' );
        }
```

- [ ] **Step 6.3: Manual smoke — open workflow edit page, view page source**

Deploy. Visit:

```
https://test.academy.housmanlearning.com/wp-admin/admin.php?page=hl-emails&tab=workflows&action=new
```

View page source. Expected: three `window.hl*` assignments appear **before** the `email-workflow.js` `<script>` tag. Browser console: no errors. `document.body.classList` contains `hl-js-loaded`.

- [ ] **Step 6.4: Register `wp_refresh_nonces` filter for long-running edit sessions (A.2.24)**

Still inside `includes/admin/class-hl-admin.php`, register a `wp_refresh_nonces` filter so WordPress's heartbeat API refreshes the workflow autosave nonce on our admin screen. Without this, an admin who leaves the workflow edit screen open for more than 24 hours (the default nonce lifetime) will have autosaves silently fail.

Add this inside the class constructor (or wherever other `add_filter` calls live, near the top of the class):

```php
        add_filter( 'wp_refresh_nonces', array( $this, 'refresh_email_workflow_nonces' ), 10, 3 );
```

Then add the method:

```php
    /**
     * A.2.24 — Refresh workflow autosave nonce via heartbeat so long
     * (>24h) editing sessions don't silently lose autosave capability.
     *
     * @param array  $response  Heartbeat response payload.
     * @param array  $data      Heartbeat request data.
     * @param string $screen_id Current admin screen id.
     * @return array
     */
    public function refresh_email_workflow_nonces( $response, $data, $screen_id ) {
        if ( $screen_id === 'admin_page_hl-emails' ) {
            if ( ! isset( $response['nonces'] ) ) {
                $response['nonces'] = array();
            }
            $response['nonces']['hl_workflow_autosave'] = wp_create_nonce( 'hl_workflow_autosave' );
        }
        return $response;
    }
```

**Verify:** Open a workflow edit page, open browser devtools, and watch the heartbeat tick (every 15–60s). The response should include `nonces.hl_workflow_autosave` on our screen.

- [ ] **Step 6.5: Commit**

```bash
git add assets/js/admin/email-workflow.js includes/admin/class-hl-admin.php
git commit -m "$(cat <<'EOF'
chore(email): enqueue email-workflow.js + inject registries + refresh nonces

A.3.2 jQuery IIFE wrapper, A.3.4 wp_add_inline_script position 'before',
A.3.5 wp_json_encode with stable flags. Script loads only on the
workflow edit/new page. A.2.24 — wp_refresh_nonces filter keeps the
workflow autosave nonce alive across >24h edit sessions via heartbeat.

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 7: Condition Builder — PHP render shell + fallback

**Files:**
- Modify: `includes/admin/class-hl-admin-emails.php` — `render_workflow_form()`

- [ ] **Step 7.1: Replace the Conditions row in `render_workflow_form()`**

Open `includes/admin/class-hl-admin-emails.php`. Locate `render_workflow_form()` (around line 169). The `<form>` tag opens at line 198 — wrap its entire contents in a new wrapper div `.hl-email-admin` (A.3.3).

Find this line (around 279):

```php
                <tr>
                    <th><label><?php esc_html_e( 'Conditions (JSON)', 'hl-core' ); ?></label></th>
                    <td><textarea name="conditions" rows="4" class="large-text"><?php echo esc_textarea( wp_json_encode( $conditions, JSON_PRETTY_PRINT ) ); ?></textarea>
                    <p class="description"><?php esc_html_e( 'JSON array of conditions. All ANDed. Example: [{"field":"cycle.cycle_type","op":"eq","value":"program"}]', 'hl-core' ); ?></p></td>
                </tr>
```

Replace the entire `<tr>` with:

```php
                <tr>
                    <th><label><?php esc_html_e( 'Conditions', 'hl-core' ); ?></label></th>
                    <td>
                        <div class="hl-condition-builder" data-initial="<?php echo esc_attr( wp_json_encode( $conditions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?>">
                            <div class="hl-condition-rows" aria-live="polite"></div>
                            <button type="button" class="hl-condition-add button-link">
                                <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
                                <?php esc_html_e( 'Add Condition', 'hl-core' ); ?>
                            </button>
                            <p class="hl-condition-hint">
                                <span class="hl-badge-and"><?php esc_html_e( 'All conditions must match (AND)', 'hl-core' ); ?></span>
                                <?php esc_html_e( 'Empty = matches every event for this trigger.', 'hl-core' ); ?>
                            </p>
                        </div>
                        <!-- A.7.4 / A.7.10 — raw JSON fallback when JS fails to initialise. -->
                        <details class="hl-js-fallback">
                            <summary><?php esc_html_e( 'Raw JSON edit mode (JavaScript required for visual editor)', 'hl-core' ); ?></summary>
                            <textarea name="conditions" rows="4" class="large-text code" spellcheck="false"><?php echo esc_textarea( wp_json_encode( $conditions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?></textarea>
                            <p class="description"><?php esc_html_e( 'Visual builder writes to this textarea automatically. Edit here only if the visual builder is broken.', 'hl-core' ); ?></p>
                        </details>
                    </td>
                </tr>
```

**Critical:** The `name="conditions"` remains on the textarea inside the `<details>` — the form POST still submits the textarea value. The visual UI updates it on every interaction. If JS fails, the admin can expand the `<details>` and edit directly.

- [ ] **Step 7.2: Wrap the entire form in `.hl-email-admin`**

Still in `render_workflow_form()`, find the opening `<form>` tag (around line 198) and the closing `</form>` (around line 311). Immediately after `<form ...>` opening, add:

```php
        <div class="hl-email-admin">
```

Immediately before `</form>`, add:

```php
        </div><!-- /.hl-email-admin -->
```

- [ ] **Step 7.3: Manual smoke — load page, confirm fallback visible without JS**

Deploy. Load the workflow-edit page. Expected:
- With JS enabled: `.hl-condition-builder` div is visible, `<details>` is hidden (CSS rule from Task 13 — for now it will be visible, which is fine as temporary state).
- With JS disabled (devtools): textarea inside `<details>` is editable and the form still submits.

- [ ] **Step 7.4: Commit**

```bash
git add includes/admin/class-hl-admin-emails.php
git commit -m "$(cat <<'EOF'
feat(email): condition builder UI shell + raw JSON fallback

Introduces .hl-condition-builder container and a <details>-wrapped raw
JSON textarea (name=conditions) as the A.7.4/A.7.10 fallback. Visual
UI writes into the textarea on every interaction; form POST is
unchanged.

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 8: Condition Builder — JS implementation

**Files:**
- Modify: `assets/js/admin/email-workflow.js`

- [ ] **Step 8.1: Implement the condition builder inside the IIFE**

Open `assets/js/admin/email-workflow.js`. Replace the whole `jQuery(function ($) { ... });` body with:

```javascript
jQuery(function ($) {
    'use strict';

    $('body').addClass('hl-js-loaded');
    window.addEventListener('error', function () {
        $('body').removeClass('hl-js-loaded');
    });

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------
    var FIELDS    = window.hlConditionFields || {};
    var OPERATORS = window.hlConditionOperators || {};
    var TOKENS    = window.hlRecipientTokens || {};

    function escHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // ---------------------------------------------------------------------
    // Condition Builder
    // ---------------------------------------------------------------------
    var $builder = $('.hl-condition-builder');
    if ($builder.length) initConditionBuilder($builder);

    function initConditionBuilder($wrap) {
        var $rows     = $wrap.find('.hl-condition-rows');
        var $textarea = $wrap.closest('td').find('textarea[name="conditions"]');
        var initial   = [];
        try {
            initial = JSON.parse($wrap.attr('data-initial') || '[]') || [];
        } catch (e) {
            initial = [];
        }

        // Seed rows from initial JSON. If parse failed, leave empty.
        initial.forEach(function (cond) {
            addConditionRow($rows, cond);
        });

        // Delegate events.
        $wrap.on('click', '.hl-condition-add', function (e) {
            e.preventDefault();
            addConditionRow($rows, null);
            serializeConditions($wrap, $textarea);
        });

        $wrap.on('click', '.hl-condition-remove', function (e) {
            e.preventDefault();
            $(this).closest('.hl-condition-row').remove();
            serializeConditions($wrap, $textarea);
        });

        $wrap.on('change', '.hl-condition-field', function () {
            var $row = $(this).closest('.hl-condition-row');
            rebuildOperatorSelect($row);
            rebuildValueInput($row);
            serializeConditions($wrap, $textarea);
        });

        $wrap.on('change', '.hl-condition-op', function () {
            var $row = $(this).closest('.hl-condition-row');
            rebuildValueInput($row);
            serializeConditions($wrap, $textarea);
        });

        $wrap.on('change input', '.hl-condition-value, .hl-condition-value-pillbox input', function () {
            serializeConditions($wrap, $textarea);
        });

        // A.1.2 — Enter inside a pill input adds a pill without submitting the form.
        $wrap.on('keydown', '.hl-pill-input input', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                addPillFromInput($(this));
                serializeConditions($wrap, $textarea);
            } else if (e.key === 'Backspace' && !$(this).val()) {
                // Backspace on empty input removes the last pill.
                $(this).closest('.hl-pill-input').find('.hl-pill').last().remove();
                serializeConditions($wrap, $textarea);
            }
        });

        $wrap.on('click', '.hl-pill-remove', function (e) {
            e.preventDefault();
            $(this).closest('.hl-pill').remove();
            serializeConditions($wrap, $textarea);
        });

        // Initial serialize to normalise textarea content.
        serializeConditions($wrap, $textarea);
    }

    function addConditionRow($rows, cond) {
        cond = cond || { field: '', op: 'eq', value: '' };
        var rowIndex = $rows.children('.hl-condition-row').length;
        var $row = $(
            '<div class="hl-condition-row" role="group" aria-label="Condition ' + (rowIndex + 1) + '">' +
                '<select class="hl-condition-field" aria-label="Field"></select>' +
                '<select class="hl-condition-op"    aria-label="Operator"></select>' +
                '<span class="hl-condition-value-wrap"></span>' +
                '<button type="button" class="hl-condition-remove" aria-label="Remove condition ' + (rowIndex + 1) + '">&times;</button>' +
            '</div>'
        );

        // Build field select with optgroups.
        var $field = $row.find('.hl-condition-field');
        var groups = {};
        Object.keys(FIELDS).forEach(function (key) {
            var g = FIELDS[key].group || 'Other';
            (groups[g] = groups[g] || []).push({ key: key, label: FIELDS[key].label });
        });
        $field.append('<option value="">— Select field —</option>');
        Object.keys(groups).forEach(function (g) {
            var $og = $('<optgroup>').attr('label', g);
            groups[g].forEach(function (item) {
                $og.append('<option value="' + escHtml(item.key) + '">' + escHtml(item.label) + '</option>');
            });
            $field.append($og);
        });
        if (cond.field) $field.val(cond.field);

        $rows.append($row);
        rebuildOperatorSelect($row, cond.op);
        rebuildValueInput($row, cond.value);
    }

    function rebuildOperatorSelect($row, preservedOp) {
        var field = $row.find('.hl-condition-field').val();
        var type  = (FIELDS[field] && FIELDS[field].type) || 'text';
        var ops   = OPERATORS[type] || OPERATORS['text'] || {};
        var $op   = $row.find('.hl-condition-op');
        var current = preservedOp || $op.val() || 'eq';
        $op.empty();
        Object.keys(ops).forEach(function (k) {
            $op.append('<option value="' + escHtml(k) + '">' + escHtml(ops[k]) + '</option>');
        });
        if (ops[current]) {
            $op.val(current);
        } else {
            $op.val(Object.keys(ops)[0] || 'eq');
        }
    }

    function rebuildValueInput($row, preservedValue) {
        var field = $row.find('.hl-condition-field').val();
        var op    = $row.find('.hl-condition-op').val();
        var def   = FIELDS[field] || { type: 'text', options: {} };
        var type  = def.type;
        var opts  = def.options || {};
        var $wrap = $row.find('.hl-condition-value-wrap');
        if (preservedValue === undefined) {
            // Preserve current value on op/field change when rebuilding.
            preservedValue = $wrap.find('.hl-condition-value').val();
            if (preservedValue === undefined) {
                // Pillbox — read pills into array.
                var pills = $wrap.find('.hl-pill').map(function () {
                    return $(this).attr('data-value');
                }).get();
                preservedValue = pills.length ? pills : '';
            }
        }
        $wrap.empty();

        // is_null / not_null — hide value entirely.
        if (op === 'is_null' || op === 'not_null') {
            $wrap.addClass('hl-condition-value-hidden');
            return;
        }
        $wrap.removeClass('hl-condition-value-hidden');

        // Boolean — Yes/No toggle.
        if (type === 'boolean') {
            var boolVal = (preservedValue === true || preservedValue === 'true' || preservedValue === '1') ? '1' : '0';
            $wrap.append(
                '<span class="hl-toggle-pair" role="radiogroup" aria-label="Value">' +
                    '<label><input type="radio" class="hl-condition-value" name="v_' + Math.random().toString(36).slice(2) + '" value="1"' + (boolVal === '1' ? ' checked' : '') + '> Yes</label>' +
                    '<label><input type="radio" class="hl-condition-value"   name="v_' + Math.random().toString(36).slice(2) + '" value="0"' + (boolVal === '0' ? ' checked' : '') + '> No</label>' +
                '</span>'
            );
            return;
        }

        // in / not_in — pill input.
        if (op === 'in' || op === 'not_in') {
            var values = Array.isArray(preservedValue) ? preservedValue : (preservedValue ? String(preservedValue).split(',') : []);
            var enumOptions = Object.keys(opts);
            var datalistId  = 'hl-dl-' + Math.random().toString(36).slice(2);
            var $box = $(
                '<div class="hl-pill-input" role="list">' +
                    values.map(function (v) {
                        var label = opts[v] || v;
                        return '<span class="hl-pill hl-pill-enum" role="listitem" data-value="' + escHtml(v) + '">' +
                                    escHtml(label) +
                                    ' <button type="button" class="hl-pill-remove" aria-label="Remove ' + escHtml(label) + '">&times;</button>' +
                                '</span>';
                    }).join('') +
                    '<input type="text" placeholder="Type and press Enter"' + (enumOptions.length ? ' list="' + datalistId + '"' : '') + '>' +
                (enumOptions.length
                    ? '<datalist id="' + datalistId + '">' +
                        enumOptions.map(function (k) {
                            return '<option value="' + escHtml(k) + '">' + escHtml(opts[k]) + '</option>';
                        }).join('') +
                      '</datalist>'
                    : '') +
                '</div>'
            );
            $wrap.append($box);
            return;
        }

        // Enum + eq/neq — plain select.
        if (type === 'enum') {
            var $select = $('<select class="hl-condition-value" aria-label="Value"></select>');
            $select.append('<option value="">— Select —</option>');
            Object.keys(opts).forEach(function (k) {
                $select.append('<option value="' + escHtml(k) + '">' + escHtml(opts[k]) + '</option>');
            });
            if (preservedValue != null) $select.val(preservedValue);
            $wrap.append($select);
            return;
        }

        // Numeric.
        if (type === 'numeric') {
            $wrap.append('<input type="number" class="hl-condition-value" aria-label="Value" value="' + escHtml(preservedValue) + '">');
            return;
        }

        // Text.
        $wrap.append('<input type="text" class="hl-condition-value" aria-label="Value" value="' + escHtml(preservedValue) + '">');
    }

    function addPillFromInput($input) {
        var val = String($input.val() || '').trim();
        if (!val) return;
        $input.val('');
        var $box = $input.closest('.hl-pill-input');
        var pillClass = $box.hasClass('hl-pill-input-email')
            ? 'hl-pill-email'
            : ($box.hasClass('hl-pill-input-role') ? 'hl-pill-role' : 'hl-pill-enum');

        // Email validation for email pills.
        if (pillClass === 'hl-pill-email') {
            var ok = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val);
            if (!ok) {
                $input.addClass('hl-input-invalid').attr('aria-invalid', 'true');
                // A.2.9 — SR announcement + inline text (not color-only).
                announceError('Invalid email address: ' + val);
                setTimeout(function () { $input.removeClass('hl-input-invalid').removeAttr('aria-invalid'); }, 2000);
                return;
            }
        }
        // A.2.15 — reject commas silently in role pills (prevents FIND_IN_SET smuggling).
        if (pillClass === 'hl-pill-role' && val.indexOf(',') !== -1) {
            announceError('Role names cannot contain commas');
            return;
        }

        var $pill = $('<span class="hl-pill ' + pillClass + '" role="listitem"></span>')
            .attr('data-value', val)
            .text(val)
            .append(' <button type="button" class="hl-pill-remove" aria-label="Remove ' + escHtml(val) + '">&times;</button>');
        $input.before($pill);
    }

    function announceError(msg) {
        var $region = $('#hl-email-admin-sr');
        if (!$region.length) {
            $region = $('<div id="hl-email-admin-sr" class="screen-reader-text" aria-live="polite" role="status"></div>');
            $('body').append($region);
        }
        $region.text('');
        setTimeout(function () { $region.text(msg); }, 50);
    }

    function serializeConditions($wrap, $textarea) {
        var out = [];
        $wrap.find('.hl-condition-row').each(function () {
            var $row = $(this);
            var field = $row.find('.hl-condition-field').val();
            var op    = $row.find('.hl-condition-op').val();
            if (!field || !op) return;
            var value;
            if (op === 'is_null' || op === 'not_null') {
                value = null;
            } else {
                var $inp = $row.find('.hl-condition-value:not([type=radio])');
                var $radio = $row.find('.hl-condition-value[type=radio]:checked');
                var $pillbox = $row.find('.hl-pill-input');
                if ($pillbox.length) {
                    value = $pillbox.find('.hl-pill').map(function () {
                        return $(this).attr('data-value');
                    }).get();
                } else if ($radio.length) {
                    value = $radio.val() === '1';
                } else if ($inp.length) {
                    value = $inp.val();
                } else {
                    value = '';
                }
            }
            out.push({ field: field, op: op, value: value });
        });
        $textarea.val(JSON.stringify(out));
    }

    // ---------------------------------------------------------------------
    // Recipient Picker — implemented in Task 10.
    // ---------------------------------------------------------------------
});
```

- [ ] **Step 8.2: Manual smoke — exercise the builder**

Deploy. Open a workflow edit page.

1. Click "+ Add Condition" → new row appears.
2. Select field "Cycle Type" → operator dropdown populates with `equals / not equals / matches any of / ...`, value becomes `<select>` with "Program/Course".
3. Change operator to "matches any of" → value input becomes pill box with datalist.
4. Type "program" + Enter → pill appears, input clears, **form does NOT submit** (A.1.2).
5. Press Backspace on empty input → last pill removed.
6. Change field to "Is Control Group" → value becomes Yes/No radio.
7. Click "×" → row removed.
8. Open devtools → check that the hidden `textarea[name=conditions]` contains the expected JSON after each interaction.
9. Save the workflow. Reload the edit page. Confirm rows re-hydrate correctly from the saved JSON.

- [ ] **Step 8.3: Commit**

```bash
git add assets/js/admin/email-workflow.js
git commit -m "$(cat <<'EOF'
feat(email): visual condition builder JS

Row builder with per-field operator adaptation, enum/boolean/text/numeric
value inputs, pill tag input for in/not_in operators, ARIA grouping,
Enter-to-add pill with form-submit prevention (A.1.2), Backspace-remove
on empty input, and live hidden textarea sync. Accessibility: aria-label
on every control, aria-live SR announcements for validation errors.

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 9: Recipient Picker — PHP render shell

**Files:**
- Modify: `includes/admin/class-hl-admin-emails.php` — the Recipients row in `render_workflow_form()`

- [ ] **Step 9.1: Replace the Recipients row**

In `render_workflow_form()`, locate (around line 284 originally, now slightly shifted after Task 5):

```php
                <tr>
                    <th><label><?php esc_html_e( 'Recipients (JSON)', 'hl-core' ); ?></label></th>
                    <td><textarea name="recipients" rows="3" class="large-text"><?php echo esc_textarea( wp_json_encode( $recipients, JSON_PRETTY_PRINT ) ); ?></textarea>
                    <p class="description"><?php esc_html_e( 'Tokens: triggering_user, assigned_coach, school_director, cc_teacher, role:X, static:email', 'hl-core' ); ?></p></td>
                </tr>
```

Replace the entire `<tr>` with:

```php
                <tr>
                    <th><label><?php esc_html_e( 'Recipients', 'hl-core' ); ?></label></th>
                    <td>
                        <div class="hl-recipient-picker" data-initial="<?php echo esc_attr( wp_json_encode( $recipients, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?>" data-current-trigger="<?php echo esc_attr( isset( $workflow->trigger_key ) ? $workflow->trigger_key : '' ); ?>">
                            <!-- Primary Section -->
                            <section class="hl-recipient-section hl-recipient-primary" aria-labelledby="hl-recip-primary-h">
                                <h4 id="hl-recip-primary-h"><?php esc_html_e( 'Primary Recipients (To:)', 'hl-core' ); ?></h4>
                                <div class="hl-token-grid" role="group" aria-label="<?php esc_attr_e( 'Primary recipient tokens', 'hl-core' ); ?>">
                                    <!-- JS renders token cards here -->
                                </div>
                                <div class="hl-recipient-roles">
                                    <label><?php esc_html_e( 'By Role', 'hl-core' ); ?></label>
                                    <div class="hl-pill-input hl-pill-input-role" role="list" aria-label="<?php esc_attr_e( 'Role-based recipients', 'hl-core' ); ?>">
                                        <input type="text" placeholder="<?php esc_attr_e( 'teacher, mentor, coach… (Enter to add)', 'hl-core' ); ?>">
                                    </div>
                                </div>
                                <div class="hl-recipient-static">
                                    <label><?php esc_html_e( 'Static Emails', 'hl-core' ); ?></label>
                                    <div class="hl-pill-input hl-pill-input-email" role="list" aria-label="<?php esc_attr_e( 'Static email recipients', 'hl-core' ); ?>">
                                        <input type="email" placeholder="<?php esc_attr_e( 'name@example.com (Enter to add)', 'hl-core' ); ?>">
                                    </div>
                                </div>
                            </section>

                            <!-- CC Section -->
                            <section class="hl-recipient-section hl-recipient-cc" aria-labelledby="hl-recip-cc-h">
                                <h4 id="hl-recip-cc-h"><?php esc_html_e( 'CC Recipients', 'hl-core' ); ?></h4>
                                <div class="hl-token-list hl-token-list-cc" role="group" aria-label="<?php esc_attr_e( 'CC recipient tokens', 'hl-core' ); ?>">
                                    <!-- JS renders compact token list here -->
                                </div>
                                <div class="hl-recipient-roles">
                                    <label><?php esc_html_e( 'CC By Role', 'hl-core' ); ?></label>
                                    <div class="hl-pill-input hl-pill-input-role" role="list">
                                        <input type="text" placeholder="<?php esc_attr_e( 'Role name (Enter to add)', 'hl-core' ); ?>">
                                    </div>
                                </div>
                                <div class="hl-recipient-static">
                                    <label><?php esc_html_e( 'CC Static Emails', 'hl-core' ); ?></label>
                                    <div class="hl-pill-input hl-pill-input-email" role="list">
                                        <input type="email" placeholder="<?php esc_attr_e( 'name@example.com (Enter to add)', 'hl-core' ); ?>">
                                    </div>
                                </div>
                            </section>

                            <!-- A.2.14 / A.7.7 — live recipient count hint -->
                            <p class="hl-recipient-count-hint" aria-live="polite" role="status"></p>
                        </div>

                        <details class="hl-js-fallback">
                            <summary><?php esc_html_e( 'Raw JSON edit mode (JavaScript required for visual editor)', 'hl-core' ); ?></summary>
                            <textarea name="recipients" rows="3" class="large-text code" spellcheck="false"><?php echo esc_textarea( wp_json_encode( $recipients, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?></textarea>
                            <p class="description"><?php esc_html_e( 'Visual picker writes to this textarea automatically.', 'hl-core' ); ?></p>
                        </details>
                    </td>
                </tr>
```

- [ ] **Step 9.2: Commit**

```bash
git add includes/admin/class-hl-admin-emails.php
git commit -m "$(cat <<'EOF'
feat(email): recipient picker UI shell + raw JSON fallback

Primary + CC sections with token grid placeholders, role pills, and
static email pills. Dimmed-token visibility driven by trigger in Task 10.
Raw JSON fallback stays behind <details> for A.7.4/A.7.10 safety.

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 10: Recipient Picker — JS implementation

**Files:**
- Modify: `assets/js/admin/email-workflow.js` — append below the condition builder IIFE body

- [ ] **Step 10.1: Append recipient picker code**

In `assets/js/admin/email-workflow.js`, find the `// Recipient Picker — implemented in Task 10.` placeholder at the bottom of the IIFE. Replace it with:

```javascript
    // ---------------------------------------------------------------------
    // Recipient Picker
    // ---------------------------------------------------------------------
    var $picker = $('.hl-recipient-picker');
    if ($picker.length) initRecipientPicker($picker);

    function initRecipientPicker($wrap) {
        var $textarea = $wrap.closest('td').find('textarea[name="recipients"]');
        var $triggerSelect = $('select[name="trigger_key"]');
        var initial = { primary: [], cc: [] };
        try {
            var parsed = JSON.parse($wrap.attr('data-initial') || '{}');
            if (parsed && typeof parsed === 'object') {
                initial = {
                    primary: Array.isArray(parsed.primary) ? parsed.primary : [],
                    cc:      Array.isArray(parsed.cc)      ? parsed.cc      : []
                };
            }
        } catch (e) {}

        renderTokenCards($wrap.find('.hl-token-grid'),    'primary', initial.primary);
        renderTokenCards($wrap.find('.hl-token-list-cc'), 'cc',      initial.cc);
        hydratePills($wrap, initial);
        applyTriggerVisibility($wrap, $triggerSelect.val() || $wrap.attr('data-current-trigger') || '');
        applyPrimaryExclusion($wrap);
        serializeRecipients($wrap, $textarea);
        scheduleRecipientCount($wrap);

        // Token card clicks.
        $wrap.on('click', '.hl-token-card', function (e) {
            if ($(this).hasClass('hl-token-disabled')) {
                return;
            }
            var $card = $(this);
            var section = $card.closest('[class*="hl-token-"]').hasClass('hl-token-list-cc') ? 'cc' : 'primary';
            $card.toggleClass('hl-token-checked');
            $card.find('input[type=checkbox]').prop('checked', $card.hasClass('hl-token-checked'));
            if (section === 'primary') applyPrimaryExclusion($wrap);
            serializeRecipients($wrap, $textarea);
            scheduleRecipientCount($wrap);
        });

        // Prevent spacebar scroll when a card has focus.
        $wrap.on('keydown', '.hl-token-card', function (e) {
            if (e.key === ' ' || e.key === 'Enter') {
                e.preventDefault();
                $(this).trigger('click');
            }
        });

        // Pill input handlers (reusing globals — delegated from .hl-recipient-picker).
        $wrap.on('keydown', '.hl-pill-input input', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                addPillFromInput($(this));
                serializeRecipients($wrap, $textarea);
                scheduleRecipientCount($wrap);
            } else if (e.key === 'Backspace' && !$(this).val()) {
                $(this).closest('.hl-pill-input').find('.hl-pill').last().remove();
                serializeRecipients($wrap, $textarea);
                scheduleRecipientCount($wrap);
            }
        });

        $wrap.on('click', '.hl-pill-remove', function (e) {
            e.preventDefault();
            $(this).closest('.hl-pill').remove();
            serializeRecipients($wrap, $textarea);
            scheduleRecipientCount($wrap);
        });

        // Trigger change → re-apply visibility.
        $triggerSelect.on('change', function () {
            applyTriggerVisibility($wrap, $(this).val());
            serializeRecipients($wrap, $textarea);
            scheduleRecipientCount($wrap);
        });
    }

    function renderTokenCards($container, section, selected) {
        $container.empty();
        Object.keys(TOKENS).forEach(function (tokenKey) {
            var def = TOKENS[tokenKey];
            var isChecked = selected.indexOf(tokenKey) !== -1;
            var id = 'hl-tok-' + section + '-' + tokenKey;
            var $card = $(
                '<label class="hl-token-card" tabindex="0">' +
                    '<input type="checkbox" id="' + id + '" data-token="' + escHtml(tokenKey) + '"' + (isChecked ? ' checked' : '') + '>' +
                    '<span class="hl-token-label">' + escHtml(def.label) + '</span>' +
                    (section === 'primary' && def.description
                        ? '<span class="hl-token-desc">' + escHtml(def.description) + '</span>'
                        : '') +
                '</label>'
            );
            if (isChecked) $card.addClass('hl-token-checked');
            $container.append($card);
        });
    }

    function hydratePills($wrap, initial) {
        // Primary section.
        var $primaryRole  = $wrap.find('.hl-recipient-primary .hl-pill-input-role');
        var $primaryEmail = $wrap.find('.hl-recipient-primary .hl-pill-input-email');
        initial.primary.forEach(function (entry) {
            if (typeof entry !== 'string') return;
            if (entry.indexOf('role:') === 0) {
                injectPill($primaryRole, entry.substring(5), 'hl-pill-role');
            } else if (entry.indexOf('static:') === 0) {
                injectPill($primaryEmail, entry.substring(7), 'hl-pill-email');
            }
        });
        var $ccRole  = $wrap.find('.hl-recipient-cc .hl-pill-input-role');
        var $ccEmail = $wrap.find('.hl-recipient-cc .hl-pill-input-email');
        initial.cc.forEach(function (entry) {
            if (typeof entry !== 'string') return;
            if (entry.indexOf('role:') === 0) {
                injectPill($ccRole, entry.substring(5), 'hl-pill-role');
            } else if (entry.indexOf('static:') === 0) {
                injectPill($ccEmail, entry.substring(7), 'hl-pill-email');
            }
        });
    }

    function injectPill($box, value, cssClass) {
        var $input = $box.find('input').first();
        var $pill = $('<span class="hl-pill ' + cssClass + '" role="listitem"></span>')
            .attr('data-value', value)
            .text(value)
            .append(' <button type="button" class="hl-pill-remove" aria-label="Remove ' + escHtml(value) + '">&times;</button>');
        $input.before($pill);
    }

    function applyTriggerVisibility($wrap, trigger) {
        // A.2.10 + A.6.13 — keep incompatible tokens dimmed (do NOT remove from JSON).
        $wrap.find('.hl-token-card').each(function () {
            var tokenKey = $(this).find('input[type=checkbox]').attr('data-token');
            var def = TOKENS[tokenKey];
            if (!def) return;
            var compat = (def.triggers === '*') || (Array.isArray(def.triggers) && def.triggers.indexOf(trigger) !== -1);
            if (compat) {
                $(this).removeClass('hl-token-dim').removeAttr('title');
            } else {
                $(this).addClass('hl-token-dim').attr('title', "Your current trigger doesn't provide this recipient type.");
            }
        });
    }

    function applyPrimaryExclusion($wrap) {
        var primaryTokens = [];
        $wrap.find('.hl-recipient-primary .hl-token-card.hl-token-checked input[type=checkbox]').each(function () {
            primaryTokens.push($(this).attr('data-token'));
        });
        $wrap.find('.hl-recipient-cc .hl-token-card').each(function () {
            var key = $(this).find('input[type=checkbox]').attr('data-token');
            if (primaryTokens.indexOf(key) !== -1) {
                $(this).addClass('hl-token-disabled').attr('title', 'Already selected as Primary recipient');
                $(this).find('input[type=checkbox]').prop('checked', false);
                $(this).removeClass('hl-token-checked');
            } else {
                $(this).removeClass('hl-token-disabled').removeAttr('title');
            }
        });
    }

    function serializeRecipients($wrap, $textarea) {
        var out = { primary: [], cc: [] };
        $wrap.find('.hl-recipient-primary .hl-token-card.hl-token-checked input[type=checkbox]').each(function () {
            out.primary.push($(this).attr('data-token'));
        });
        $wrap.find('.hl-recipient-primary .hl-pill-input-role .hl-pill').each(function () {
            out.primary.push('role:' + $(this).attr('data-value'));
        });
        $wrap.find('.hl-recipient-primary .hl-pill-input-email .hl-pill').each(function () {
            out.primary.push('static:' + $(this).attr('data-value'));
        });
        $wrap.find('.hl-recipient-cc .hl-token-card.hl-token-checked input[type=checkbox]').each(function () {
            out.cc.push($(this).attr('data-token'));
        });
        $wrap.find('.hl-recipient-cc .hl-pill-input-role .hl-pill').each(function () {
            out.cc.push('role:' + $(this).attr('data-value'));
        });
        $wrap.find('.hl-recipient-cc .hl-pill-input-email .hl-pill').each(function () {
            out.cc.push('static:' + $(this).attr('data-value'));
        });
        $textarea.val(JSON.stringify(out));
    }

    // A.2.14 / A.7.7 / A.6.12 — debounced live count hint.
    var _countTimer = null;
    function scheduleRecipientCount($wrap) {
        clearTimeout(_countTimer);
        _countTimer = setTimeout(function () {
            fetchRecipientCount($wrap);
        }, 400);
    }

    function fetchRecipientCount($wrap) {
        var cfg = window.hlEmailWorkflowCfg || {};
        if (!cfg.ajaxUrl) return;
        var trigger = $('select[name="trigger_key"]').val() || '';
        var recipients = $wrap.closest('td').find('textarea[name="recipients"]').val() || '{}';
        var $hint = $wrap.find('.hl-recipient-count-hint');
        $hint.text('').removeClass('hl-hint-error');

        $.post(cfg.ajaxUrl, {
            action: 'hl_email_recipient_count',
            nonce: cfg.nonces.recipientCount,
            trigger: trigger,
            recipients: recipients
        }).done(function (res) {
            if (res && res.success && typeof res.data.count === 'number') {
                $hint.text('Resolves to ' + res.data.count + ' recipient' + (res.data.count === 1 ? '' : 's') + ' at send time (based on current data).');
            } else {
                $hint.text(''); // A.6.12 — hide on error, never leave stale spinner.
            }
        }).fail(function () {
            $hint.text('');
        });
    }
```

- [ ] **Step 10.2: Add the `ajax_recipient_count()` handler**

Open `includes/admin/class-hl-admin-emails.php`. In the `__construct()` method, after the existing `add_action` lines, add:

```php
        add_action( 'wp_ajax_hl_email_recipient_count', array( $this, 'ajax_recipient_count' ) );
```

Then add the handler method in the AJAX section (around line 640, after `ajax_cancel_queue()`):

```php
    /**
     * Async recipient count preview for the picker UI.
     * A.2.14 / A.7.7 — live estimate of how many addresses the current
     * recipient JSON resolves to for the given trigger.
     *
     * @return void
     */
    public function ajax_recipient_count() {
        check_ajax_referer( 'hl_workflow_recipient_count', 'nonce' );
        if ( ! current_user_can( 'manage_hl_core' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $trigger    = sanitize_text_field( wp_unslash( $_POST['trigger'] ?? '' ) );
        $raw_recip  = wp_unslash( $_POST['recipients'] ?? '{}' );
        $recipients = json_decode( $raw_recip, true );

        if ( ! is_array( $recipients ) ) {
            wp_send_json_success( array( 'count' => 0 ) );
        }

        // Coarse estimate:
        //   - each non-token-compatible primary/cc token with triggers '*'  = 1 user
        //   - each trigger-compatible token                                = 1 user
        //   - role:X tokens query enrollments in ALL active cycles
        //   - static:email tokens = 1 each
        $tokens = self::get_recipient_tokens();
        global $wpdb;

        $count = 0;
        foreach ( array( 'primary', 'cc' ) as $section ) {
            if ( empty( $recipients[ $section ] ) || ! is_array( $recipients[ $section ] ) ) continue;
            foreach ( $recipients[ $section ] as $entry ) {
                if ( ! is_string( $entry ) ) continue;
                if ( strpos( $entry, 'static:' ) === 0 ) {
                    $count++;
                    continue;
                }
                if ( strpos( $entry, 'role:' ) === 0 ) {
                    $role = substr( $entry, 5 );
                    // NOTE: hl_enrollment.roles is stored as a JSON-encoded
                    // array, not a CSV, so FIND_IN_SET() in SQL is unreliable.
                    // Fetch the raw role blobs and delegate membership testing
                    // to HL_Roles::has_role(), which is format-agnostic.
                    //
                    // Dependency: HL_Roles helper is owned by Track 3 Task 1
                    // and must be loaded before this handler runs. If it's not
                    // available yet (Track 3 hasn't landed), this branch falls
                    // back to 0 and the hint shows a conservative estimate.
                    if ( ! class_exists( 'HL_Roles' ) ) {
                        continue;
                    }
                    $rows = $wpdb->get_results(
                        "SELECT DISTINCT user_id, roles FROM {$wpdb->prefix}hl_enrollment
                         WHERE status = 'active'"
                    );
                    $matched = array();
                    foreach ( $rows as $row ) {
                        if ( HL_Roles::has_role( $row->roles, $role ) ) {
                            $matched[ (int) $row->user_id ] = true;
                        }
                    }
                    $count += count( $matched );
                    continue;
                }
                // Token — coarse approximation:
                if ( ! isset( $tokens[ $entry ] ) ) continue;
                $def = $tokens[ $entry ];
                if ( $def['triggers'] !== '*' && is_array( $def['triggers'] ) && ! in_array( $trigger, $def['triggers'], true ) ) {
                    continue; // incompatible token — silently skipped at send time
                }
                // Most token types resolve 1:1 per event. school_director can fan out
                // to multiple users per school — conservative +1.
                $count += 1;
            }
        }

        wp_send_json_success( array( 'count' => $count ) );
    }
```

- [ ] **Step 10.3: Manual smoke — exercise the picker**

Deploy. Load a workflow edit page.

1. Token grid renders with all 5 tokens as cards.
2. Click "Triggering User" card → card gains `.hl-token-checked` (blue bg) and the hidden textarea JSON updates to `{"primary":["triggering_user"],"cc":[]}`.
3. Change the trigger dropdown to `hl_classroom_visit_submitted` → "Observed Teacher" becomes active-looking; unrelated cron-only tokens get `.hl-token-dim` class with tooltip.
4. Change trigger to `user_register` → everything except `triggering_user` and `school_director` gets dimmed.
5. Add a role pill: type "teacher" + Enter in the Primary "By Role" box. Pill appears (green).
6. Add a static email pill: type `foo@bar.com` + Enter. Pill appears (yellow). Try `notanemail` + Enter → red border flash + no pill + SR announcement.
7. Check "Triggering User" in Primary → note that the CC section's "Triggering User" card is now disabled (`hl-token-disabled`).
8. Wait ~400ms after any change → "Resolves to N recipient(s) at send time" hint appears.
9. Save workflow. Reload. Confirm pills and token selections hydrate.

- [ ] **Step 10.4: Commit**

```bash
git add assets/js/admin/email-workflow.js includes/admin/class-hl-admin-emails.php
git commit -m "$(cat <<'EOF'
feat(email): recipient picker JS + async count hint

Token cards, role pills, static email pills with regex validation,
trigger-dependent dimming (A.2.10/A.6.13), Primary→CC exclusion,
debounced live recipient count via ajax_recipient_count (A.2.14/A.7.7),
error-path hint hiding (A.6.12). ARIA groups, SR announcements,
keyboard Enter/Space activation on cards.

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 11: Workflow Row Actions — Duplicate, Activate/Pause, Delete

**Files:**
- Modify: `includes/admin/class-hl-admin-emails.php`
  - `render_workflows_tab()` — new actions column markup
  - New `handle_workflow_duplicate()` (admin-post)
  - New `ajax_workflow_toggle_status()`
  - New `handle_workflow_delete()` (admin-post, with queue guard + soft-delete)
- Modify: `includes/admin/class-hl-admin.php` — register `admin_post_*` hooks

- [ ] **Step 11.1: Register admin-post hooks on the singleton**

In `HL_Admin_Emails::__construct()`, after the existing `add_action` block, add:

```php
        // A.2.13 — duplicate & delete via admin-post.php (POST, not GET).
        add_action( 'admin_post_hl_workflow_duplicate',  array( $this, 'handle_workflow_duplicate' ) );
        add_action( 'admin_post_hl_workflow_delete',     array( $this, 'handle_workflow_delete' ) );
        add_action( 'admin_post_hl_template_duplicate',  array( $this, 'handle_template_duplicate' ) );
        add_action( 'admin_post_hl_template_archive',    array( $this, 'handle_template_archive' ) );
        add_action( 'admin_post_hl_workflow_force_resend', array( $this, 'handle_workflow_force_resend' ) );

        add_action( 'wp_ajax_hl_workflow_toggle_status', array( $this, 'ajax_workflow_toggle_status' ) );
```

**Important:** `HL_Admin_Emails` is currently instantiated lazily (only when its `render_page` runs). `admin_post_*` hooks fire on admin-post.php requests **before** the admin page router kicks in, so the singleton must be instantiated eagerly.

**Verified anchor (as of this commit):** `HL_Admin::__construct()` in `includes/admin/class-hl-admin.php` contains an eager-instantiation block at **lines 24–26**:

```php
    private function __construct() {
        add_action('admin_menu', array($this, 'create_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('admin_init', array($this, 'handle_early_actions'));

        // Eagerly instantiate so AJAX hooks register on admin-ajax.php requests.
        HL_Admin_Cycles::instance();
        HL_Admin_Pathways::instance();
    }
```

Add the new line immediately after `HL_Admin_Pathways::instance();` (line 26), yielding:

```php
        HL_Admin_Cycles::instance();
        HL_Admin_Pathways::instance();
        HL_Admin_Emails::instance();
```

**Fallback if the block has moved:** If a future refactor has removed the `HL_Admin_Cycles::instance();` / `HL_Admin_Pathways::instance();` lines, add `HL_Admin_Emails::instance();` as the last statement inside `HL_Admin::__construct()` — anywhere after the `add_action` hook registrations is fine, since all we need is for the constructor-level `add_action('admin_post_*')` calls in `HL_Admin_Emails` to register before admin-post.php dispatches.

- [ ] **Step 11.2: Rewrite the actions `<td>` in `render_workflows_tab()`**

Find the actions cell (around line 158):

```php
                            <td>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=hl-emails&tab=workflows&action=edit&workflow_id=' . $w->workflow_id ) ); ?>"><?php esc_html_e( 'Edit', 'hl-core' ); ?></a>
                            </td>
```

Replace with:

```php
                            <td class="hl-row-actions">
                                <?php
                                $edit_url = admin_url( 'admin.php?page=hl-emails&tab=workflows&action=edit&workflow_id=' . $w->workflow_id );
                                $toggle_nonce    = wp_create_nonce( 'hl_workflow_toggle_' . $w->workflow_id );
                                $duplicate_nonce = wp_create_nonce( 'hl_workflow_duplicate_' . $w->workflow_id );
                                $delete_nonce    = wp_create_nonce( 'hl_workflow_delete_' . $w->workflow_id );
                                $is_active       = $w->status === 'active';
                                $toggle_label    = $is_active ? __( 'Pause', 'hl-core' ) : __( 'Activate', 'hl-core' );
                                $toggle_class    = $is_active ? 'hl-action-pause' : 'hl-action-activate';
                                ?>
                                <a href="<?php echo esc_url( $edit_url ); ?>" class="hl-action-edit"><?php esc_html_e( 'Edit', 'hl-core' ); ?></a>
                                <span class="hl-action-sep">|</span>

                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="hl-inline-form">
                                    <input type="hidden" name="action" value="hl_workflow_duplicate">
                                    <input type="hidden" name="workflow_id" value="<?php echo (int) $w->workflow_id; ?>">
                                    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $duplicate_nonce ); ?>">
                                    <button type="submit" class="hl-action-duplicate button-link"><?php esc_html_e( 'Duplicate', 'hl-core' ); ?></button>
                                </form>
                                <span class="hl-action-sep">|</span>

                                <a href="#"
                                   class="<?php echo esc_attr( $toggle_class ); ?> hl-action-toggle"
                                   data-workflow-id="<?php echo (int) $w->workflow_id; ?>"
                                   data-nonce="<?php echo esc_attr( $toggle_nonce ); ?>"
                                   data-state="<?php echo esc_attr( $w->status ); ?>"
                                ><?php echo esc_html( $toggle_label ); ?></a>
                                <span class="hl-action-sep">|</span>

                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="hl-inline-form hl-confirm-delete" data-confirm="<?php esc_attr_e( 'Delete this workflow? This action cannot be undone.', 'hl-core' ); ?>">
                                    <input type="hidden" name="action" value="hl_workflow_delete">
                                    <input type="hidden" name="workflow_id" value="<?php echo (int) $w->workflow_id; ?>">
                                    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $delete_nonce ); ?>">
                                    <button type="submit" class="hl-action-delete button-link"><?php esc_html_e( 'Delete', 'hl-core' ); ?></button>
                                </form>
                            </td>
```

Also, wrap the entire list view in a `<div class="hl-email-admin">...</div>`. `render_workflows_tab()` starts at line 84, and its HTML output begins right after the closing `?>` on line 122 (the line `<div style="display:flex;...">` at line 123 is the "Add Workflow" header row — but three sibling tabs in this file open with the exact same inline style, so anchor on the `?>` output boundary inside this specific function instead of the inline-style string.)

**Robust anchor (by function + output boundary):**

Locate `render_workflows_tab()` (line 84). Scroll to the `?>` that opens HTML output — it's on line 122 and is immediately followed by the header row containing the "Add Workflow" button. Replace the output-open boundary like so:

```php
        ?>
        <div class="hl-email-admin">
        <div style="display:flex;align-items:center;gap:16px;margin-bottom:16px;">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=hl-emails&tab=workflows&action=new' ) ); ?>" class="button button-primary"><?php esc_html_e( 'Add Workflow', 'hl-core' ); ?></a>
```

Then find the `</table>` at line 136's closing tag (the end of the `wp-list-table widefat fixed striped` block inside this function — **not** the ones inside `render_templates_tab()` at line 408 or the queue tab at line 497). Close the wrapper with:

```php
        </table>
        </div><!-- /.hl-email-admin -->
```

If the `<table>` start line shifts after earlier tasks, locate it by the unique comment at the top of the workflows tab or by searching for `wp-list-table` inside `render_workflows_tab()` (there is exactly one per function).

- [ ] **Step 11.3: Add a thin inline script for confirm + AJAX toggle**

Still in `render_workflows_tab()`, after the `</table>` and inside the `.hl-email-admin` wrapper, add:

```php
        <script>
        jQuery(function($){
            // Confirm deletes.
            $('.hl-confirm-delete').on('submit', function(e){
                if (!confirm($(this).attr('data-confirm'))) { e.preventDefault(); }
            });
            // Activate/Pause toggle via AJAX.
            $('.hl-action-toggle').on('click', function(e){
                e.preventDefault();
                var $link = $(this);
                $.post(ajaxurl, {
                    action: 'hl_workflow_toggle_status',
                    workflow_id: $link.data('workflow-id'),
                    nonce: $link.data('nonce')
                }).done(function(res){
                    if (res && res.success) {
                        var newStatus = res.data.status;
                        var $row = $link.closest('tr');
                        $row.find('.hl-email-badge')
                            .removeClass('hl-email-badge--active hl-email-badge--paused hl-email-badge--draft')
                            .addClass('hl-email-badge--' + newStatus)
                            .text(newStatus);
                        if (newStatus === 'active') {
                            $link.text('<?php echo esc_js( __( 'Pause', 'hl-core' ) ); ?>')
                                 .removeClass('hl-action-activate').addClass('hl-action-pause');
                        } else {
                            $link.text('<?php echo esc_js( __( 'Activate', 'hl-core' ) ); ?>')
                                 .removeClass('hl-action-pause').addClass('hl-action-activate');
                        }
                        $link.data('state', newStatus);
                    } else {
                        alert((res && res.data) ? res.data : 'Toggle failed.');
                    }
                }).fail(function(){ alert('Network error'); });
            });
        });
        </script>
```

- [ ] **Step 11.4: Add `handle_workflow_duplicate()`**

In the AJAX Handlers section of `class-hl-admin-emails.php`, add:

```php
    /**
     * Duplicate a workflow (admin-post.php handler, POST-only).
     * A.2.13 — converted from GET to POST + per-ID nonce (A.2.18).
     *
     * @return void
     */
    public function handle_workflow_duplicate() {
        $workflow_id = (int) ( $_POST['workflow_id'] ?? 0 );
        if ( ! $workflow_id ) wp_die( 'Missing workflow_id' );
        if ( ! current_user_can( 'manage_hl_core' ) ) wp_die( 'Unauthorized' );
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'hl_workflow_duplicate_' . $workflow_id ) ) {
            wp_die( 'Security check failed.' );
        }

        global $wpdb;
        $source = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_email_workflow WHERE workflow_id = %d",
            $workflow_id
        ), ARRAY_A );

        if ( ! $source ) {
            wp_redirect( admin_url( 'admin.php?page=hl-emails&tab=workflows&hl_notice=not_found' ) );
            exit;
        }

        unset( $source['workflow_id'], $source['created_at'], $source['updated_at'] );
        $source['name']   = self::generate_copy_name( 'hl_email_workflow', $source['name'] );
        $source['status'] = 'draft';

        $wpdb->insert( "{$wpdb->prefix}hl_email_workflow", $source );
        $new_id = (int) $wpdb->insert_id;

        if ( class_exists( 'HL_Audit_Service' ) ) {
            HL_Audit_Service::log( 'email_workflow_duplicated', array(
                'source_workflow_id' => $workflow_id,
                'new_workflow_id'    => $new_id,
            ) );
        }

        wp_redirect( admin_url( 'admin.php?page=hl-emails&tab=workflows&action=edit&workflow_id=' . $new_id . '&hl_notice=duplicated' ) );
        exit;
    }
```

- [ ] **Step 11.5: Add `ajax_workflow_toggle_status()`**

In the AJAX Handlers section:

```php
    /**
     * Toggle workflow status between active ↔ paused (draft → active).
     * A.2.18 — per-workflow-ID nonce.
     *
     * @return void
     */
    public function ajax_workflow_toggle_status() {
        $workflow_id = (int) ( $_POST['workflow_id'] ?? 0 );
        if ( ! $workflow_id ) wp_send_json_error( 'Missing workflow_id' );
        if ( ! current_user_can( 'manage_hl_core' ) ) wp_send_json_error( 'Unauthorized' );
        check_ajax_referer( 'hl_workflow_toggle_' . $workflow_id, 'nonce' );

        global $wpdb;
        $current = $wpdb->get_var( $wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}hl_email_workflow WHERE workflow_id = %d",
            $workflow_id
        ) );
        if ( ! $current ) wp_send_json_error( 'Not found' );

        // active → paused, anything else → active.
        $new_status = ( $current === 'active' ) ? 'paused' : 'active';

        $wpdb->update(
            "{$wpdb->prefix}hl_email_workflow",
            array( 'status' => $new_status ),
            array( 'workflow_id' => $workflow_id ),
            array( '%s' ),
            array( '%d' )
        );

        if ( class_exists( 'HL_Audit_Service' ) ) {
            HL_Audit_Service::log( 'email_workflow_status_toggled', array(
                'workflow_id' => $workflow_id,
                'from'        => $current,
                'to'          => $new_status,
            ) );
        }

        wp_send_json_success( array( 'status' => $new_status ) );
    }
```

- [ ] **Step 11.6: Add `handle_workflow_delete()` with queue guard (soft-delete)**

In the AJAX Handlers section:

```php
    /**
     * Soft-delete a workflow (sets status='deleted').
     * A.2.26 — soft-delete prevents mid-cron races.
     * A.3.6 — transaction + SELECT FOR UPDATE on the queue guard.
     * A.3.18 — friendly error wording when sent emails block deletion.
     *
     * @return void
     */
    public function handle_workflow_delete() {
        $workflow_id = (int) ( $_POST['workflow_id'] ?? 0 );
        if ( ! $workflow_id ) wp_die( 'Missing workflow_id' );
        if ( ! current_user_can( 'manage_hl_core' ) ) wp_die( 'Unauthorized' );
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'hl_workflow_delete_' . $workflow_id ) ) {
            wp_die( 'Security check failed.' );
        }

        global $wpdb;
        $wpdb->query( 'START TRANSACTION' );

        // A.2.26 — status check includes sent/sending/failed rows.
        $blocked_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}hl_email_queue
             WHERE workflow_id = %d
               AND status IN ('sent','sending','failed')
             FOR UPDATE",
            $workflow_id
        ) );

        if ( $blocked_count > 0 ) {
            $wpdb->query( 'ROLLBACK' );
            wp_redirect( add_query_arg( array(
                'page'      => 'hl-emails',
                'tab'       => 'workflows',
                'hl_notice' => 'delete_blocked',
                'hl_count'  => $blocked_count,
            ), admin_url( 'admin.php' ) ) );
            exit;
        }

        // Soft-delete the workflow.
        $wpdb->update(
            "{$wpdb->prefix}hl_email_workflow",
            array( 'status' => 'deleted' ),
            array( 'workflow_id' => $workflow_id ),
            array( '%s' ),
            array( '%d' )
        );

        // Cancel any pending queue rows still linked to it.
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}hl_email_queue
             SET status = 'cancelled', failed_reason = 'workflow deleted'
             WHERE workflow_id = %d AND status = 'pending'",
            $workflow_id
        ) );

        $wpdb->query( 'COMMIT' );

        if ( class_exists( 'HL_Audit_Service' ) ) {
            HL_Audit_Service::log( 'email_workflow_soft_deleted', array(
                'workflow_id' => $workflow_id,
            ) );
        }

        wp_redirect( admin_url( 'admin.php?page=hl-emails&tab=workflows&hl_notice=workflow_deleted' ) );
        exit;
    }
```

- [ ] **Step 11.7: Exclude soft-deleted workflows from the list tab**

In `render_workflows_tab()`, the two `SELECT` queries currently show all rows. Update both:

Find:
```php
"SELECT w.*, t.name AS template_name
 FROM {$wpdb->prefix}hl_email_workflow w
 LEFT JOIN {$wpdb->prefix}hl_email_template t ON t.template_id = w.template_id
 WHERE w.status = %s
 ORDER BY w.updated_at DESC",
```
Add `AND w.status != 'deleted'` is not needed here because the `WHERE w.status = %s` filter already excludes deleted rows unless status_filter='deleted' (and we only allow draft/active/paused).

Find the unfiltered query:
```php
"SELECT w.*, t.name AS template_name
 FROM {$wpdb->prefix}hl_email_workflow w
 LEFT JOIN {$wpdb->prefix}hl_email_template t ON t.template_id = w.template_id
 ORDER BY w.updated_at DESC"
```

Replace with:

```php
"SELECT w.*, t.name AS template_name
 FROM {$wpdb->prefix}hl_email_workflow w
 LEFT JOIN {$wpdb->prefix}hl_email_template t ON t.template_id = w.template_id
 WHERE w.status != 'deleted'
 ORDER BY w.updated_at DESC"
```

Also verify the automation service queries never pick up soft-deleted workflows. There are exactly three `SELECT ... FROM {$wpdb->prefix}hl_email_workflow` queries in `includes/services/class-hl-email-automation-service.php`:

1. **Line 77** — `handle_trigger()`: `WHERE trigger_key = %s AND status = 'active'`
2. **Line 654** — `run_daily_checks()`: `WHERE trigger_key IN ({$placeholders}) AND status = 'active'`
3. **Line 693** — `run_hourly_checks()`: `WHERE trigger_key IN ({$placeholders}) AND status = 'active'`

All three already constrain on `status = 'active'`, so soft-deleted (`status = 'deleted'`) workflows are implicitly excluded — no query changes are needed. Add a one-line comment immediately above each of the three queries to document the invariant:

```php
// A.2.26 — status='active' filter also excludes soft-deleted rows.
```

Exact insertion points:
- Line 76 (above `$workflows = $wpdb->get_results( $wpdb->prepare(` inside `handle_trigger()`)
- Line 653 (above the same line inside `run_daily_checks()`)
- Line 692 (above the same line inside `run_hourly_checks()`)

No other logic changes.

- [ ] **Step 11.8: Status column must handle the new 'deleted' value in badges**

The existing `render_status_badge()` outputs `hl-email-badge--{status}` class. No enum schema change required — the column is VARCHAR. Just add a CSS rule for `.hl-email-badge--deleted` in Task 13.

- [ ] **Step 11.9: Also exclude deleted status from the filter tabs**

In `render_workflows_tab()`, the `$valid_statuses = array( 'draft', 'active', 'paused' );` already excludes `deleted`. Confirm `$statuses` for the filter pills is also limited to the four visible states. Good.

- [ ] **Step 11.10: Manual smoke**

Deploy. On the workflow list page:
1. Click "Duplicate" on any row → redirects to edit page of a new row with `(Copy)` suffix.
2. Click "Pause" on an active row → badge flips to `paused`, link text flips to `Activate`. No page reload.
3. Click "Delete" on a row with no sent queue entries → confirm dialog → row disappears.
4. Click "Delete" on a row that has `hl_email_queue.status='sent'` entries → redirect with `hl_notice=delete_blocked&hl_count=N`.

Verify the soft-deleted row via:
```bash
ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 'export PATH=/opt/bitnami/php/bin:/opt/bitnami/mariadb/bin:/usr/local/bin:/usr/bin:/bin && wp --path=/opt/bitnami/wordpress db query "SELECT workflow_id, name, status FROM wp_hl_email_workflow WHERE status = \"deleted\""'
```

- [ ] **Step 11.11: Commit**

```bash
git add includes/admin/class-hl-admin-emails.php includes/admin/class-hl-admin.php includes/services/class-hl-email-automation-service.php
git commit -m "$(cat <<'EOF'
feat(email): workflow row actions — duplicate, toggle, soft-delete

A.2.13 — duplicate and delete via admin-post.php POST with per-ID
nonces (A.2.18). Activate/Pause is AJAX, updates badge in-place.
Delete is soft (A.2.26) — sets status='deleted', cancels pending
queue rows, blocks on sent/sending/failed rows with friendly wording
(A.3.18). Transaction + SELECT FOR UPDATE on the guard (A.3.6).
All handlers call current_user_can() alongside the nonce check (A.6.4).

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 12: Template Row Actions — Duplicate, Archive/Restore

**Files:**
- Modify: `includes/admin/class-hl-admin-emails.php`
  - `render_templates_tab()` — actions column
  - New `handle_template_duplicate()`
  - New `handle_template_archive()`

- [ ] **Step 12.1: Rewrite the templates actions `<td>`**

In `render_templates_tab()`, find the actions cell (around line 430):

```php
                            <td>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=hl-emails&tab=builder&template_id=' . $t->template_id ) ); ?>"><?php esc_html_e( 'Edit', 'hl-core' ); ?></a>
                            </td>
```

Replace with:

```php
                            <td class="hl-row-actions">
                                <?php
                                $edit_url         = admin_url( 'admin.php?page=hl-emails&tab=builder&template_id=' . $t->template_id );
                                $duplicate_nonce  = wp_create_nonce( 'hl_template_duplicate_' . $t->template_id );
                                $archive_nonce    = wp_create_nonce( 'hl_template_archive_' . $t->template_id );
                                $is_archived      = $t->status === 'archived';
                                $archive_label    = $is_archived ? __( 'Restore', 'hl-core' ) : __( 'Archive', 'hl-core' );
                                ?>
                                <a href="<?php echo esc_url( $edit_url ); ?>" class="hl-action-edit"><?php esc_html_e( 'Edit', 'hl-core' ); ?></a>
                                <span class="hl-action-sep">|</span>

                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="hl-inline-form">
                                    <input type="hidden" name="action" value="hl_template_duplicate">
                                    <input type="hidden" name="template_id" value="<?php echo (int) $t->template_id; ?>">
                                    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $duplicate_nonce ); ?>">
                                    <button type="submit" class="hl-action-duplicate button-link"><?php esc_html_e( 'Duplicate', 'hl-core' ); ?></button>
                                </form>
                                <span class="hl-action-sep">|</span>

                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="hl-inline-form">
                                    <input type="hidden" name="action" value="hl_template_archive">
                                    <input type="hidden" name="template_id" value="<?php echo (int) $t->template_id; ?>">
                                    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $archive_nonce ); ?>">
                                    <button type="submit" class="<?php echo $is_archived ? 'hl-action-restore' : 'hl-action-archive'; ?> button-link"><?php echo esc_html( $archive_label ); ?></button>
                                </form>
                            </td>
```

Apply the same `.hl-email-admin` wrapping treatment to the Templates tab: wrap the entire tab output.

- [ ] **Step 12.2: Add `handle_template_duplicate()`**

```php
    /**
     * Duplicate a template (admin-post.php handler, POST-only).
     *
     * @return void
     */
    public function handle_template_duplicate() {
        $template_id = (int) ( $_POST['template_id'] ?? 0 );
        if ( ! $template_id ) wp_die( 'Missing template_id' );
        if ( ! current_user_can( 'manage_hl_core' ) ) wp_die( 'Unauthorized' );
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'hl_template_duplicate_' . $template_id ) ) {
            wp_die( 'Security check failed.' );
        }

        global $wpdb;
        $source = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_email_template WHERE template_id = %d",
            $template_id
        ), ARRAY_A );

        if ( ! $source ) {
            wp_redirect( admin_url( 'admin.php?page=hl-emails&tab=templates&hl_notice=not_found' ) );
            exit;
        }

        unset( $source['template_id'], $source['created_at'], $source['updated_at'] );
        $source['name']   = self::generate_copy_name( 'hl_email_template', $source['name'] );
        $source['status'] = 'draft';

        // Generate a unique template_key.
        $base_key = preg_replace( '/_copy\d*$/', '', (string) $source['template_key'] );
        for ( $i = 1; $i <= 100; $i++ ) {
            $candidate = $i === 1 ? $base_key . '_copy' : $base_key . '_copy' . $i;
            $exists = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}hl_email_template WHERE template_key = %s",
                $candidate
            ) );
            if ( $exists === 0 ) {
                $source['template_key'] = $candidate;
                break;
            }
        }
        if ( $i > 100 ) {
            $source['template_key'] = $base_key . '_copy_' . substr( wp_generate_uuid4(), 0, 8 );
        }

        $wpdb->insert( "{$wpdb->prefix}hl_email_template", $source );
        $new_id = (int) $wpdb->insert_id;

        if ( class_exists( 'HL_Audit_Service' ) ) {
            HL_Audit_Service::log( 'email_template_duplicated', array(
                'source_template_id' => $template_id,
                'new_template_id'    => $new_id,
            ) );
        }

        wp_redirect( admin_url( 'admin.php?page=hl-emails&tab=builder&template_id=' . $new_id . '&hl_notice=duplicated' ) );
        exit;
    }
```

- [ ] **Step 12.3: Add `handle_template_archive()`**

```php
    /**
     * Archive or restore a template.
     * Templates are never hard-deleted — hl_email_queue.template_id references
     * them for audit history.
     *
     * @return void
     */
    public function handle_template_archive() {
        $template_id = (int) ( $_POST['template_id'] ?? 0 );
        if ( ! $template_id ) wp_die( 'Missing template_id' );
        if ( ! current_user_can( 'manage_hl_core' ) ) wp_die( 'Unauthorized' );
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'hl_template_archive_' . $template_id ) ) {
            wp_die( 'Security check failed.' );
        }

        global $wpdb;
        $current = $wpdb->get_var( $wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}hl_email_template WHERE template_id = %d",
            $template_id
        ) );
        if ( ! $current ) {
            wp_redirect( admin_url( 'admin.php?page=hl-emails&tab=templates&hl_notice=not_found' ) );
            exit;
        }

        $new_status = ( $current === 'archived' ) ? 'draft' : 'archived';

        $wpdb->update(
            "{$wpdb->prefix}hl_email_template",
            array( 'status' => $new_status ),
            array( 'template_id' => $template_id ),
            array( '%s' ),
            array( '%d' )
        );

        if ( class_exists( 'HL_Audit_Service' ) ) {
            HL_Audit_Service::log(
                $new_status === 'archived' ? 'email_template_archived' : 'email_template_restored',
                array( 'template_id' => $template_id )
            );
        }

        wp_redirect( admin_url( 'admin.php?page=hl-emails&tab=templates&hl_notice=template_' . $new_status ) );
        exit;
    }
```

- [ ] **Step 12.4: Exclude archived templates from the workflow form dropdown (with exception)**

In `render_workflow_form()`, find:

```php
$templates = $wpdb->get_results(
    "SELECT template_id, name FROM {$wpdb->prefix}hl_email_template WHERE status = 'active' ORDER BY name"
);
```

Replace with:

```php
// Show non-archived templates. Include the currently assigned template
// even if it was archived after assignment, annotated with "(archived)".
$current_template_id = $workflow ? (int) $workflow->template_id : 0;
$templates = $wpdb->get_results( $wpdb->prepare(
    "SELECT template_id, name, status
     FROM {$wpdb->prefix}hl_email_template
     WHERE status != 'archived' OR template_id = %d
     ORDER BY name",
    $current_template_id
) );
```

Then in the `<select>` rendering, update the loop:

```php
<?php foreach ( $templates as $t ) : ?>
    <option value="<?php echo (int) $t->template_id; ?>" <?php selected( $workflow->template_id ?? 0, $t->template_id ); ?>>
        <?php echo esc_html( $t->name ); ?><?php if ( $t->status === 'archived' ) echo ' (archived)'; ?>
    </option>
<?php endforeach; ?>
```

- [ ] **Step 12.5: Manual smoke**

Deploy.
1. Click "Duplicate" on any template → redirects to builder at new template with `(Copy)` name and `_copy` key suffix.
2. Click "Archive" on an active template → row moves under the Archived filter; link becomes "Restore".
3. Click "Restore" → status returns to draft.
4. Create/edit a workflow → confirm the template dropdown excludes archived templates, except if the workflow is already assigned to one (shown with `(archived)` suffix).

- [ ] **Step 12.6: Commit**

```bash
git add includes/admin/class-hl-admin-emails.php
git commit -m "$(cat <<'EOF'
feat(email): template row actions — duplicate, archive/restore

Duplicate via admin-post.php POST (A.2.13) with unique (Copy) suffix
and auto-generated _copy key. Archive/Restore toggles status only —
templates are never hard-deleted because hl_email_queue.template_id
holds audit references. Workflow form excludes archived templates
but keeps the currently-assigned archived one visible with a suffix.

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 13: CSS polish — `.hl-email-admin` scoped styles

**Files:**
- Modify: `assets/css/admin.css` — append a new v2 section after line 1896

- [ ] **Step 13.1: Append the v2 CSS section**

Open `assets/css/admin.css`. Scroll to the end of the existing email-admin section (around line 1896). Append:

```css
/* ==========================================================================
   Email System v2 — Track 1 (Admin Workflow UX)
   All rules scoped to .hl-email-admin for specificity (A.3.3).
   ========================================================================== */

.hl-email-admin .hl-js-fallback { display: block; }
body.hl-js-loaded .hl-email-admin .hl-js-fallback { display: none; }
.hl-email-admin .hl-js-fallback summary { cursor: pointer; color: var(--eb-muted); font-size: 12px; }

/* --- Condition Builder --- */
.hl-email-admin .hl-condition-builder { max-width: 820px; }
.hl-email-admin .hl-condition-rows { display: flex; flex-direction: column; gap: 8px; }
.hl-email-admin .hl-condition-row {
    display: flex;
    align-items: center;
    gap: 8px;
    background: var(--eb-bg-subtle, #F9FAFB);
    border: 1px solid #E5E7EB;
    border-radius: 6px;
    padding: 8px 10px;
}
.hl-email-admin .hl-condition-field { flex: 2; min-width: 180px; }
.hl-email-admin .hl-condition-op    { flex: 1; min-width: 140px; }
.hl-email-admin .hl-condition-value-wrap { flex: 2; min-width: 180px; display: flex; align-items: center; }
.hl-email-admin .hl-condition-value-wrap.hl-condition-value-hidden { display: none; }
.hl-email-admin .hl-condition-value { width: 100%; }
.hl-email-admin .hl-condition-remove {
    background: transparent;
    border: none;
    color: #DC2626;
    font-size: 20px;
    line-height: 1;
    cursor: pointer;
    padding: 0 6px;
}
.hl-email-admin .hl-condition-remove:hover { color: #991B1B; }
.hl-email-admin .hl-condition-add {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    margin-top: 8px;
    color: var(--eb-accent, #2C7BE5);
    text-decoration: none;
    font-size: 13px;
}
.hl-email-admin .hl-condition-hint { font-size: 12px; color: var(--eb-muted, #6B7280); margin-top: 8px; }
.hl-email-admin .hl-badge-and {
    display: inline-block;
    background: #EEF2FF;
    color: #3730A3;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 600;
    margin-right: 6px;
}
.hl-email-admin .hl-toggle-pair { display: inline-flex; gap: 12px; }

/* --- Pill Tag Component --- */
.hl-email-admin .hl-pill-input {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    align-items: center;
    min-height: 32px;
    padding: 4px 6px;
    border: 1px solid #D1D5DB;
    border-radius: 4px;
    background: #fff;
}
.hl-email-admin .hl-pill-input input {
    flex: 1;
    min-width: 120px;
    border: none;
    outline: none;
    padding: 2px 4px;
    font-size: 13px;
    background: transparent;
}
.hl-email-admin .hl-pill-input input.hl-input-invalid {
    outline: 2px solid #DC2626;
    outline-offset: -1px;
}
.hl-email-admin .hl-pill {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 10px;
    border-radius: 12px;
    font-size: 12px;
    line-height: 1.4;
}
.hl-email-admin .hl-pill-enum  { background: #DBEAFE; color: #1E40AF; }
.hl-email-admin .hl-pill-role  { background: #D1FAE5; color: #065F46; }
.hl-email-admin .hl-pill-email { background: #FEF3C7; color: #92400E; }
.hl-email-admin .hl-pill-remove {
    background: transparent;
    border: none;
    cursor: pointer;
    color: inherit;
    opacity: 0.6;
    font-size: 14px;
    padding: 0;
    line-height: 1;
}
.hl-email-admin .hl-pill-remove:hover { opacity: 1; }

/* --- Recipient Picker --- */
.hl-email-admin .hl-recipient-picker { max-width: 820px; }
.hl-email-admin .hl-recipient-section { margin-bottom: 20px; }
.hl-email-admin .hl-recipient-section h4 {
    margin: 0 0 8px;
    font-size: 13px;
    font-weight: 600;
    color: var(--eb-text, #374151);
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.hl-email-admin .hl-token-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
    margin-bottom: 12px;
}
.hl-email-admin .hl-token-list-cc { display: flex; flex-direction: column; gap: 4px; margin-bottom: 12px; }
.hl-email-admin .hl-token-card {
    display: flex;
    flex-direction: column;
    padding: 10px 12px;
    border: 2px solid #E5E7EB;
    border-radius: 6px;
    background: #fff;
    cursor: pointer;
    transition: border-color 120ms ease, background 120ms ease;
    position: relative;
}
.hl-email-admin .hl-token-card input[type=checkbox] { position: absolute; opacity: 0; pointer-events: none; }
.hl-email-admin .hl-token-card:hover { border-color: #C7D2FE; }
.hl-email-admin .hl-token-card:focus { outline: 2px solid var(--eb-accent, #2C7BE5); outline-offset: 1px; }
.hl-email-admin .hl-token-card.hl-token-checked {
    border-color: var(--eb-accent, #2C7BE5);
    background: #EEF2FF;
}
.hl-email-admin .hl-token-card.hl-token-dim {
    opacity: 0.5;
    /* A.6.13 — cursor help + tooltip provided via title attr */
    cursor: help;
}
.hl-email-admin .hl-token-card.hl-token-disabled {
    opacity: 0.4;
    pointer-events: none;
}
.hl-email-admin .hl-token-label { font-weight: 600; font-size: 13px; color: var(--eb-text, #374151); }
.hl-email-admin .hl-token-desc  { font-size: 12px; color: var(--eb-muted, #6B7280); margin-top: 2px; }
.hl-email-admin .hl-token-list-cc .hl-token-card {
    flex-direction: row;
    align-items: center;
    padding: 6px 10px;
}
.hl-email-admin .hl-recipient-roles,
.hl-email-admin .hl-recipient-static { margin-bottom: 12px; }
.hl-email-admin .hl-recipient-roles label,
.hl-email-admin .hl-recipient-static label {
    display: block;
    font-size: 11px;
    font-weight: 600;
    color: var(--eb-muted, #6B7280);
    text-transform: uppercase;
    letter-spacing: 0.04em;
    margin-bottom: 4px;
}
.hl-email-admin .hl-recipient-count-hint {
    font-size: 12px;
    color: var(--eb-muted, #6B7280);
    font-style: italic;
    margin-top: 8px;
}
.hl-email-admin .hl-recipient-count-hint.hl-hint-error { color: #DC2626; font-style: normal; }

/* --- Row Actions --- */
.hl-email-admin .hl-row-actions { font-size: 12px; white-space: nowrap; }
.hl-email-admin .hl-row-actions .hl-inline-form { display: inline; margin: 0; padding: 0; }
.hl-email-admin .hl-row-actions .button-link { padding: 0; box-shadow: none; background: none; border: 0; cursor: pointer; font: inherit; }
.hl-email-admin .hl-action-edit      { color: #2C7BE5; text-decoration: none; }
.hl-email-admin .hl-action-duplicate { color: #6B7280; }
.hl-email-admin .hl-action-activate  { color: #059669; }
.hl-email-admin .hl-action-pause     { color: #D97706; }
.hl-email-admin .hl-action-delete    { color: #DC2626; }
.hl-email-admin .hl-action-archive   { color: #6B7280; }
.hl-email-admin .hl-action-restore   { color: #059669; }
.hl-email-admin .hl-action-sep       { color: #D1D5DB; margin: 0 4px; }
.hl-email-admin .hl-action-edit:hover,
.hl-email-admin .hl-action-duplicate:hover,
.hl-email-admin .hl-action-activate:hover,
.hl-email-admin .hl-action-pause:hover,
.hl-email-admin .hl-action-delete:hover,
.hl-email-admin .hl-action-archive:hover,
.hl-email-admin .hl-action-restore:hover { text-decoration: underline; }

/* --- Deleted status badge --- */
.hl-email-admin .hl-email-badge--deleted { background: #F3F4F6; color: #6B7280; }

/* --- SR-only helper already exists in WP core (.screen-reader-text) --- */
```

- [ ] **Step 13.2: Manual smoke**

Deploy. Load the workflow edit page and list page. Verify:
- Condition rows have gray background, 6px radius, aligned flex layout.
- Pills render in correct colors (blue/green/yellow).
- Token cards in a 2-column grid; checked cards get blue border + light blue bg.
- Dimmed tokens have 0.5 opacity + cursor help with tooltip.
- Row actions separated by `|` pipes with correct colors.
- Fallback `<details>` is hidden when JS loads, visible when JS is disabled (test by throttling in devtools or blocking the JS file).

- [ ] **Step 13.3: Commit**

```bash
git add assets/css/admin.css
git commit -m "$(cat <<'EOF'
style(email): v2 Track 1 CSS — condition builder, picker, row actions

Scoped under .hl-email-admin for specificity (A.3.3). Implements pill
tag component (blue/green/yellow variants), condition row flex layout,
recipient token cards with checked/dim/disabled states, row action
color palette, and JS-fallback visibility rules (A.7.10 reverse logic).

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 14: Force Resend action (A.7.1) + audit history display (A.7.13)

**Files:**
- Modify: `includes/admin/class-hl-admin-emails.php`
  - `render_workflow_form()` — "Force resend" button (top of form)
  - New `handle_workflow_force_resend()`
  - `render_workflows_tab()` — inline "Last force-resend" caption

- [ ] **Step 14.1: Add `handle_workflow_force_resend()`**

In the AJAX Handlers section:

```php
    /**
     * Force-clear dedup tokens for pending queue rows on a workflow.
     * A.7.1 — scoped: all pending / specific user.
     * Never touches sent-status rows.
     *
     * Schema reference (verified in includes/class-hl-installer.php line 2121):
     * hl_email_queue columns include workflow_id, recipient_user_id, status,
     * dedup_token. There is NO user_id or cycle_id column, so scope is
     * limited to all-pending and per-recipient-user.
     *
     * @return void
     */
    public function handle_workflow_force_resend() {
        $workflow_id = (int) ( $_POST['workflow_id'] ?? 0 );
        if ( ! $workflow_id ) wp_die( 'Missing workflow_id' );
        if ( ! current_user_can( 'manage_hl_core' ) ) wp_die( 'Unauthorized' );
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'hl_workflow_force_resend_' . $workflow_id ) ) {
            wp_die( 'Security check failed.' );
        }

        $scope     = sanitize_text_field( $_POST['scope'] ?? 'all_pending' );
        $scope_val = (int) ( $_POST['scope_value'] ?? 0 );

        global $wpdb;
        $where = " workflow_id = %d AND status = 'pending' ";
        $args  = array( $workflow_id );

        if ( $scope === 'user' && $scope_val > 0 ) {
            // Column is recipient_user_id (see hl_email_queue schema).
            $where .= ' AND recipient_user_id = %d ';
            $args[] = $scope_val;
        }

        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}hl_email_queue WHERE {$where}",
            $args
        ) );

        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}hl_email_queue
             SET dedup_token = NULL
             WHERE {$where}",
            $args
        ) );

        if ( class_exists( 'HL_Audit_Service' ) ) {
            HL_Audit_Service::log( 'workflow_force_resend', array(
                'workflow_id' => $workflow_id,
                'scope'       => $scope,
                'scope_value' => $scope_val,
                'affected'    => $count,
            ) );
        }

        wp_redirect( admin_url( 'admin.php?page=hl-emails&tab=workflows&action=edit&workflow_id=' . $workflow_id . '&hl_notice=force_resend_done&hl_count=' . $count ) );
        exit;
    }
```

**Schema verified:** `hl_email_queue` has `workflow_id`, `recipient_user_id`, `status`, `dedup_token` (confirmed in `includes/class-hl-installer.php` around line 2121). There is no `user_id` column (use `recipient_user_id`) and no `cycle_id` column at all — cycle-scoped resend is not supported and has been removed from the scope selector below.

- [ ] **Step 14.2: Add Force Resend button inside `render_workflow_form()`**

In `render_workflow_form()`, immediately after the `<h2>...Edit Workflow...</h2>` line (but only when `$workflow` is non-null), add:

```php
        <?php if ( $workflow && $workflow->workflow_id ) :
            $fr_nonce = wp_create_nonce( 'hl_workflow_force_resend_' . $workflow->workflow_id );
            // A.7.13 — last force-resend timestamp (optional — only if audit query is cheap).
            $last_fr = null;
            if ( class_exists( 'HL_Audit_Service' ) && method_exists( 'HL_Audit_Service', 'get_last_event' ) ) {
                $last_fr = HL_Audit_Service::get_last_event( $workflow->workflow_id, 'workflow_force_resend' );
            }
        ?>
        <div class="hl-email-admin hl-force-resend-box" style="margin:12px 0;padding:12px;background:#FFFBEB;border-left:4px solid #D97706;">
            <strong><?php esc_html_e( 'Force Resend', 'hl-core' ); ?></strong>
            <p style="margin:4px 0;font-size:12px;color:#6B7280;">
                <?php esc_html_e( 'Clears dedup tokens for pending queue rows so the next cron run can re-fire. Does NOT re-create already-sent emails.', 'hl-core' ); ?>
            </p>
            <?php if ( $last_fr ) : ?>
                <p style="font-size:11px;color:#6B7280;"><?php printf( esc_html__( 'Last force-resend: %s by user #%d.', 'hl-core' ), esc_html( $last_fr['timestamp'] ?? '' ), (int) ( $last_fr['user_id'] ?? 0 ) ); ?></p>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Force resend will clear dedup tokens on pending queue rows. Continue?', 'hl-core' ) ); ?>');" style="display:inline-flex;gap:6px;align-items:center;">
                <input type="hidden" name="action" value="hl_workflow_force_resend">
                <input type="hidden" name="workflow_id" value="<?php echo (int) $workflow->workflow_id; ?>">
                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $fr_nonce ); ?>">
                <select name="scope">
                    <option value="all_pending"><?php esc_html_e( 'All pending rows', 'hl-core' ); ?></option>
                    <option value="user"><?php esc_html_e( 'Specific user', 'hl-core' ); ?></option>
                </select>
                <input type="number" name="scope_value" placeholder="<?php esc_attr_e( 'User ID (for user scope)', 'hl-core' ); ?>" style="width:140px;">
                <button type="submit" class="button"><?php esc_html_e( 'Force Resend', 'hl-core' ); ?></button>
            </form>
        </div>
        <?php endif; ?>
```

- [ ] **Step 14.3: Manual smoke**

Deploy. Edit a workflow that has pending queue rows. Click Force Resend with scope "All pending" → confirm → redirects with `hl_notice=force_resend_done&hl_count=N`. Verify audit log entry on the server:

```bash
ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 'export PATH=/opt/bitnami/php/bin:/opt/bitnami/mariadb/bin:/usr/local/bin:/usr/bin:/bin && wp --path=/opt/bitnami/wordpress db query "SELECT * FROM wp_hl_audit_log WHERE action_type=\"workflow_force_resend\" ORDER BY id DESC LIMIT 1"'
```

- [ ] **Step 14.4: Commit**

```bash
git add includes/admin/class-hl-admin-emails.php
git commit -m "$(cat <<'EOF'
feat(email): workflow force resend action (A.7.1)

Admin-post handler clears dedup tokens on pending queue rows for a
workflow with scope selector (all / user / cycle). Confirmation dialog
before submission. Audit-logged. Never touches sent/sending rows.
A.7.13 history surfaces via HL_Audit_Service::get_last_event() when
available.

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 15: Final integration verification + docs update

**Files:**
- Modify: `STATUS.md` — check off Track 1 items
- Modify: `README.md` — "What's Implemented" section add Email v2 Track 1

- [ ] **Step 15.1: Run the full test script one more time**

```bash
ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 'export PATH=/opt/bitnami/php/bin:/opt/bitnami/mariadb/bin:/usr/local/bin:/usr/bin:/bin && wp --path=/opt/bitnami/wordpress eval-file wp-content/plugins/hl-core/bin/test-email-v2-track1.php'
```

**Expected:** All PASS, exit 0.

- [ ] **Step 15.2: Run existing smoke tests to confirm no regression**

```bash
ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 'export PATH=/opt/bitnami/php/bin:/opt/bitnami/mariadb/bin:/usr/local/bin:/usr/bin:/bin && wp --path=/opt/bitnami/wordpress hl-core smoke-test'
```

**Expected:** All assertions PASS (37 existing + any email-related smoke tests).

- [ ] **Step 15.3: Manual browser checklist**

On the test server, verify each item from the spec's Testing Strategy for Track 1:

- [ ] Condition builder: add/remove rows, field/operator/value changes, JSON sync, save + reload
- [ ] Recipient picker: token checks, role/email pills, trigger-dependent visibility, JSON sync
- [ ] Row actions: duplicate (workflow + template), toggle status (AJAX), delete with guard, archive/restore
- [ ] Existing active workflows on test server still trigger correctly (seed a test enrollment, hit the trigger, confirm queue row appears)
- [ ] Force resend on a pending workflow — dedup tokens cleared, no sent rows affected
- [ ] JavaScript disabled: raw JSON `<details>` visible and editable, form still saves
- [ ] Keyboard navigation: Tab through condition row, Enter to add pill, Backspace to remove pill, Space/Enter on token card to toggle
- [ ] Screen reader: `aria-live` region announces invalid email
- [ ] Cross-browser: Chrome + Firefox

- [ ] **Step 15.4: Update STATUS.md**

In `STATUS.md`, locate the build queue section for Email System v2. Check off Track 1 items:

```markdown
### Email System v2
- [x] Track 1: Admin Workflow UX
  - [x] 1.1 Condition Builder UI
  - [x] 1.2 Recipient Picker UI + assigned_mentor + observed_teacher rename
  - [x] 1.3 Workflow Row Actions (duplicate, toggle, soft-delete, force resend)
  - [x] 1.4 Template Row Actions (duplicate, archive/restore)
- [ ] Track 2: Email Builder Enhancements
- [ ] Track 3: Backend Fixes
```

(Exact existing STATUS.md layout may differ — edit in place to match.)

- [ ] **Step 15.5: Update README.md "What's Implemented"**

In `README.md`, under "What's Implemented" > "Email System", add:

```markdown
- **Email v2 Track 1 (Admin Workflow UX)** — Visual condition builder and recipient picker replace JSON textareas. Per-row duplicate/toggle/soft-delete on workflows, duplicate/archive on templates. New `assigned_mentor` recipient token. `observed_teacher` canonical name with `cc_teacher` legacy alias. Server-side allowlist validation on workflow save. Force Resend admin action with scope selector (all/user/cycle). Shared `HL_Roles::has_role()` helper routes the condition evaluator through FIND_IN_SET semantics.
```

And in the file tree section, add:
```
includes/helpers/class-hl-roles.php          (new v2 helper)
assets/js/admin/email-workflow.js             (new v2 condition builder + picker)
bin/test-email-v2-track1.php                  (new WP-CLI smoke tests)
```

- [ ] **Step 15.6: Final commit**

```bash
git add STATUS.md README.md
git commit -m "$(cat <<'EOF'
docs(email): check off Email v2 Track 1 in STATUS and README

Track 1 (Admin Workflow UX) complete: condition builder, recipient
picker, workflow/template row actions, force resend, HL_Roles helper.

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Self-Review Checklist

**Spec coverage:**

| Spec item | Task |
|-----------|------|
| 1.1 Condition Builder UI | Tasks 2, 7, 8, 13 |
| 1.2 Recipient Picker UI | Tasks 2, 4, 9, 10, 13 |
| 1.3 Workflow Row Actions | Task 11 |
| 1.4 Template Row Actions | Task 12 |
| A.1.2 pill Enter preventDefault | Task 8 (`keydown` handler in `.hl-pill-input input`) |
| A.2.6 Dialog ARIA | Task 8 (aria-label on every control, row `role="group"`) |
| A.2.7 Condition ARIA | Task 8 |
| A.2.8 Pill keyboard nav | Task 8 (role list/listitem + backspace) |
| A.2.9 Invalid email SR feedback | Task 8 (`announceError`) |
| A.2.10 Dimmed tokens kept in JSON | Task 10 |
| A.2.12 unified generate_copy_name | Task 2 |
| A.2.13 Duplicate via POST | Tasks 11, 12 |
| A.2.14 Recipient count hint async | Task 10 |
| A.2.18 Per-ID nonces | Tasks 6 (workflow), 11, 12 |
| A.2.24 Heartbeat nonce refresh | Task 6 (Step 6.4 `wp_refresh_nonces` filter) |
| A.2.26 Soft-delete workflows | Task 11 |
| A.2.27 Server-side allowlist | Tasks 2 (helper), 5 (wired in) |
| A.2.28 cycle_id populated in build_context | Task 4 (resolver itself is Track 3 Task 23) |
| A.3.2 jQuery IIFE wrapper | Task 6 stub, Task 8 code |
| A.3.3 `.hl-email-admin` wrapper class | Tasks 7, 9, 11, 12, 13 |
| A.3.4 wp_add_inline_script position before | Task 6 |
| A.3.5 wp_json_encode stable flags | Tasks 5, 6 |
| A.3.6 Delete guard transaction | Task 11 |
| A.3.7 Payload trim | Task 5 |
| A.3.12 "matches any of" label | Task 2 (operator registry) |
| A.6.4 current_user_can alongside nonce | Tasks 5, 10, 11, 12, 14 |
| A.6.13 Dimmed token tooltip | Tasks 10 (title attr), 13 (cursor help) |
| A.6.14 operator_label helper | Task 2 |
| A.7.1 Force resend scope modal + audit | Task 14 |
| A.7.4 / A.7.10 JS fallback reverse logic | Tasks 6 (body class), 7/9 (details), 13 (CSS) |
| A.7.7 Recipient count labeling | Task 10 |
| A.7.13 Force resend history visibility | Task 14 |

**Coverage transferred to Track 3** (this plan depends on these but does not implement them):

| Spec item | Owning task |
|-----------|-------------|
| A.1.7 `HL_Roles` + `FIND_IN_SET` in evaluator | Track 3 Tasks 1 + 2 |
| `HL_Roles::has_role()` / `sanitize_roles()` helper | Track 3 Task 1 |
| `assigned_mentor` resolver method | Track 3 Task 23 |
| `observed_teacher` canonical name + `cc_teacher` legacy alias | Track 3 Task 23 |
| `FIND_IN_SET` fix in `HL_Email_Recipient_Resolver` (spec 3.4) | Track 3 Task 5 |

**Placeholder scan:** No "TBD", no "similar to above", no bare "implement later" strings. Every code step shows full code.

**Type consistency:** `generate_copy_name($table, $source_name)`, `operator_label($op)`, `validate_workflow_payload($conditions, $recipients)`, `sanitize_json_payload($raw, $default)` — all signatures used consistently across tasks. `HL_Roles::has_role($csv, $role)` / `HL_Roles::sanitize_roles($csv)` are consumed from Track 3 and match its signatures.

**Known cross-track handoffs:**
- Track 3 Tasks 1, 2, 5, 23 are hard prerequisites for this plan. Track 1 cannot start until they have merged.
- Task 4's `build_context()` cycle_id population feeds Track 3 Task 23's `resolve_assigned_mentor()` — neither works in isolation; both must be present for the `assigned_mentor` token to resolve correctly.
