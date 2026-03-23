# Forms, Events & Coaching Hub — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build 3 cross-pathway event types (Coaching Session forms, Reflective Practice Sessions, Classroom Visits) with 6 instruments, Coaching Hub enhancements, and rebuild ELCPB Y2 pathways.

**Architecture:** Explicit entity tables per event type (hl_rp_session, hl_classroom_visit, extends hl_coaching_session), submission tables for form responses, instruments stored in hl_teacher_assessment_instrument, loose coupling across pathways via shared entity IDs.

**Tech Stack:** PHP 7.4+, WordPress 6.0+, LearnDash, MySQL (wpdb), BuddyBoss Theme

**Spec:** `docs/superpowers/specs/2026-03-23-forms-events-coaching-design.md`

---

## IMPORTANT: Pattern Corrections (Read Before Executing)

These corrections override specific code examples in the tasks below. Read them first.

### C1: Table DDL uses `$tables[]` array pattern, NOT `$sql .=`
The existing `get_schema()` returns an array: `$tables = array(); $tables[] = "CREATE TABLE ...";` and `create_tables()` iterates with `foreach ($tables as $table_sql) { dbDelta($table_sql); }`. **All 5 new table DDL blocks must use `$tables[] =` not `$sql .=`**. Add them before the `return $tables;` statement.

### C2: `update_component_state()` must use check-then-insert/update, NOT `$wpdb->replace()`
The `hl_component_state` table has a `last_computed_at` column that is `NOT NULL` with no default. Use the existing pattern from `HL_Coaching_Service::update_coaching_component_state()` (check-then-insert/update pattern, include `last_computed_at => current_time('mysql')`).

### C3: Sessions 2 and 3 are functionally dependent
Although placeholder files prevent fatal errors, Session 3 calls renderer methods with parameters that the placeholders don't accept. **Both sessions must complete before any frontend testing.** Session 3 agents should note that renderer implementations from Session 2 must exist before the dispatchers will produce real output. If Session 3 finishes first, the code will be correct structurally but produce empty output until Session 2 completes.

### C4: `do_action('hl_core_recompute_rollups')` takes 1 argument only
All existing listeners register with `10, 1` (one argument). Use: `do_action('hl_core_recompute_rollups', $enrollment_id);` — do NOT pass `$cycle_id` as a second argument.

### C5: AJAX handlers must be registered
Any form that uses draft save via AJAX needs `wp_ajax_` hooks registered. Add these in `hl-core.php`'s `init()` method or in each renderer class constructor. Example:
```php
add_action('wp_ajax_hl_save_draft', array($this, 'handle_ajax_save_draft'));
```

### C6: Use `$component->get_external_ref_array()` instead of `json_decode($component->external_ref, true)`
The component object has a built-in helper method. Use it for consistency.

### C7: `$enrollment` may be object or array depending on source
Repository `get_by_id()` returns objects. Service methods return `ARRAY_A` (arrays). Check the return type of whatever method loads the enrollment and access properties accordingly (`$enrollment->roles` vs `$enrollment['roles']`).

### C8: CLI require_once must go inside the existing `WP_CLI` guard
In `load_dependencies()`, CLI files are loaded inside `if ( defined( 'WP_CLI' ) && WP_CLI )`. Place the new CLI require there, not in the general services section.

### C9: Instrument seeding ownership
Session 2 (Task 2.1) creates the instrument definitions and a reusable seeder method. Session 4 (Task 4.3) calls that method from the CLI. The seeder code lives in the CLI file (`class-hl-cli-setup-elcpb-y2-v2.php`) since that's where it runs.

### C10: Use `date('t', mktime(0,0,0,$month,1,$year))` instead of `cal_days_in_month()`
Avoids dependency on PHP calendar extension.

---

## Terminal / Session Guide

You need **5 Claude Code sessions** (terminals). Here's when to open each:

```
TIMELINE:
─────────────────────────────────────────────────────────
1. Open Terminal 1 → Session 1 (Fast Mode ON)
   Wait for Session 1 to complete...

2. Open Terminal 2 → Session 2 (Fast Mode ON)  ┐
   Open Terminal 3 → Session 3 (Fast Mode ON)  ┘ IN PARALLEL
   Wait for Sessions 2 AND 3 to complete...

3. Open Terminal 4 → Session 4 (Fast Mode ON)
   Wait for Session 4 to complete...

4. Open Terminal 5 → Session 5 (Fast Mode OFF — normal)
   Final integration & verification
─────────────────────────────────────────────────────────
```

### What to paste into each terminal

**Terminal 1 prompt:**
> Enable Fast Mode. Read the plan at `docs/superpowers/plans/2026-03-23-forms-events-coaching-plan.md` and execute **Session 1** tasks only. Read the spec at `docs/superpowers/specs/2026-03-23-forms-events-coaching-design.md` for full context. Follow existing codebase patterns exactly. Commit after each task.

**Terminal 2 prompt:**
> Enable Fast Mode. Read the plan at `docs/superpowers/plans/2026-03-23-forms-events-coaching-plan.md` and execute **Session 2** tasks only. Read the spec for full context. Session 1 is already complete. Follow existing codebase patterns exactly. Commit after each task.

**Terminal 3 prompt:**
> Enable Fast Mode. Read the plan at `docs/superpowers/plans/2026-03-23-forms-events-coaching-plan.md` and execute **Session 3** tasks only. Read the spec for full context. Session 1 is already complete. Follow existing codebase patterns exactly. Commit after each task.

**Terminal 4 prompt:**
> Enable Fast Mode. Read the plan at `docs/superpowers/plans/2026-03-23-forms-events-coaching-plan.md` and execute **Session 4** tasks only. Read the spec for full context. Sessions 1-3 are complete. Follow existing codebase patterns exactly. Commit after each task.

**Terminal 5 prompt:**
> Read the plan at `docs/superpowers/plans/2026-03-23-forms-events-coaching-plan.md` and execute **Session 5** tasks only. Read the spec for full context. All prior sessions are complete. Deploy to test server and verify.

---

## File Map

### New Files (9)

| File | Session | Purpose |
|------|---------|---------|
| `includes/services/class-hl-rp-session-service.php` | 1 | RP Session entity CRUD + form submissions |
| `includes/services/class-hl-classroom-visit-service.php` | 1 | Classroom Visit entity CRUD + form submissions |
| `includes/services/class-hl-session-prep-service.php` | 1 | Auto-populated data helper for RP Notes forms |
| `includes/frontend/class-hl-frontend-rp-session.php` | 2 | RP Session form renderer (RP Notes + Action Plan) |
| `includes/frontend/class-hl-frontend-classroom-visit.php` | 2 | Classroom Visit form renderer |
| `includes/frontend/class-hl-frontend-self-reflection.php` | 2 | Self-Reflection form renderer |
| `includes/frontend/class-hl-frontend-action-plan.php` | 2 | Action Plan & Results form renderer |
| `includes/frontend/class-hl-frontend-rp-notes.php` | 2 | RP Notes form renderer |
| `includes/cli/class-hl-cli-setup-elcpb-y2-v2.php` | 4 | Rebuilt ELCPB Y2 CLI command |

### Modified Files (8)

| File | Session | Changes |
|------|---------|---------|
| `includes/class-hl-installer.php` | 1 | 5 new tables + ENUM migration |
| `includes/services/class-hl-coaching-service.php` | 1 | 3 new methods for form submissions |
| `hl-core.php` | 1 | Register new files + services |
| `includes/frontend/class-hl-frontend-component-page.php` | 3 | 3 new component type renderer branches |
| `includes/frontend/class-hl-frontend-my-coaching.php` | 3 | Calendar widget + inline Action Plan |
| `includes/frontend/class-hl-frontend-coaching-hub.php` | 3 | Coaches section + calendar view |
| `includes/admin/class-hl-admin-coaching.php` | 4 | Coaches tab |
| `includes/admin/class-hl-admin-cycles.php` | 4 | RP Sessions + Classroom Visits subtabs |

---

## SESSION 1: Database & Core Infrastructure

**Mode:** Fast Mode ON
**Estimated tasks:** 7
**Dependencies:** None (first session)

### Task 1.1: Add 5 New Database Tables to Installer

**Files:**
- Modify: `includes/class-hl-installer.php`

**Context:** Read the existing `get_schema()` method to see the table DDL pattern. All tables use `bigint(20) unsigned` for IDs, `$charset_collate` at the end. Tables are defined as strings concatenated into `$sql`. Also read the `maybe_upgrade()` method to see the revision pattern.

- [ ] **Step 1: Read the installer file**

Read `includes/class-hl-installer.php` fully. Note:
- Where `get_schema()` starts/ends
- The `$current_revision` value in `maybe_upgrade()`
- How existing tables are structured (copy the pattern exactly)

- [ ] **Step 2: Add 5 new table DDL blocks to `get_schema()`**

Add after the last existing table DDL (likely `hl_audit_log`). Use the exact SQL from the spec (Section 3.1-3.5). Replace `{$prefix}` with `{$wpdb->prefix}` and `{$charset_collate}` with `$charset_collate`. Each table block follows this pattern:

```php
$sql .= "CREATE TABLE {$wpdb->prefix}hl_rp_session (
    rp_session_id        bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    rp_session_uuid      char(36) NOT NULL,
    cycle_id             bigint(20) unsigned NOT NULL,
    mentor_enrollment_id bigint(20) unsigned NOT NULL,
    teacher_enrollment_id bigint(20) unsigned NOT NULL,
    session_number       tinyint unsigned NOT NULL DEFAULT 1,
    status               varchar(20) NOT NULL DEFAULT 'pending',
    session_date         datetime DEFAULT NULL,
    notes                text DEFAULT NULL,
    created_at           datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (rp_session_id),
    UNIQUE KEY rp_session_uuid (rp_session_uuid),
    KEY idx_cycle (cycle_id),
    KEY idx_mentor (mentor_enrollment_id),
    KEY idx_teacher (teacher_enrollment_id)
) $charset_collate;\n\n";
```

Repeat for all 5 tables: `hl_rp_session`, `hl_rp_session_submission`, `hl_classroom_visit`, `hl_classroom_visit_submission`, `hl_coaching_session_submission`.

- [ ] **Step 3: Add ENUM migration in `maybe_upgrade()`**

Bump `$current_revision` from 21 to 22. Add a new conditional block:

```php
if ( (int) $stored < 22 ) {
    self::migrate_add_event_component_types();
}
```

Add the private migration method:

```php
private static function migrate_add_event_component_types() {
    global $wpdb;
    $table = $wpdb->prefix . 'hl_component';
    $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
    if ( ! $table_exists ) {
        return;
    }
    $wpdb->query( "ALTER TABLE `{$table}` MODIFY COLUMN component_type
        ENUM('learndash_course','teacher_self_assessment','child_assessment',
             'coaching_session_attendance','observation',
             'reflective_practice_session','classroom_visit','self_reflection')
        NOT NULL DEFAULT 'learndash_course'" );
}
```

- [ ] **Step 4: Commit**

```bash
git add includes/class-hl-installer.php
git commit -m "feat: add 5 new DB tables for RP sessions, classroom visits, and coaching submissions"
```

---

### Task 1.2: Create HL_RP_Session_Service

**Files:**
- Create: `includes/services/class-hl-rp-session-service.php`

**Context:** Read `includes/services/class-hl-coaching-service.php` as the reference pattern. The new service follows the same structure: no constructor, stateless methods, `global $wpdb`, returns `WP_Error` on failure, `ARRAY_A` results.

- [ ] **Step 1: Create the service file**

Create `includes/services/class-hl-rp-session-service.php` with:

```php
<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class HL_RP_Session_Service {

    const VALID_STATUSES   = array( 'pending', 'scheduled', 'attended', 'missed', 'cancelled' );
    const TERMINAL_STATUSES = array( 'attended', 'missed', 'cancelled' );
```

Implement all methods from spec Section 5.1:
- `create_session($data)` — INSERT into `hl_rp_session`, generate UUID via `wp_generate_uuid4()`, validate mentor + teacher enrollment IDs exist, audit log
- `get_session($rp_session_id)` — SELECT with JOINs to get mentor/teacher display_names via enrollment→user
- `get_by_cycle($cycle_id)` — SELECT all for cycle with joined names
- `get_by_mentor($mentor_enrollment_id)` — SELECT where mentor matches
- `get_by_teacher($teacher_enrollment_id)` — SELECT where teacher matches
- `get_teachers_for_mentor($mentor_enrollment_id)` — JOIN `hl_team_membership` to find teams where enrollment is mentor, return non-mentor team members
- `transition_status($rp_session_id, $new_status)` — validate status, check terminal, UPDATE
- `submit_form($rp_session_id, $user_id, $instrument_id, $role, $responses_json)` — UPSERT into `hl_rp_session_submission` (INSERT ... ON DUPLICATE KEY UPDATE pattern using `$wpdb->query` with prepare), generate UUID for new rows
- `get_submissions($rp_session_id)` — SELECT from submission table
- `get_previous_action_plans($teacher_enrollment_id, $cycle_id)` — SELECT from `hl_rp_session_submission` JOIN `hl_rp_session` WHERE teacher_enrollment_id matches and role = 'supervisee', ORDER BY submitted_at DESC
- `update_component_state($enrollment_id, $cycle_id, $session_number)` — Find component with type `reflective_practice_session` and matching `external_ref` JSON `session_number`, INSERT/UPDATE `hl_component_state` with completion_percent=100

For the `update_component_state` method, read how the existing `update_coaching_component_state()` in `class-hl-coaching-service.php` works and follow the same pattern. The key difference: match by `external_ref` JSON containing `session_number`.

```php
public function update_component_state( $enrollment_id, $cycle_id, $session_number ) {
    global $wpdb;

    // Find the component in this cycle's pathways
    $component = $wpdb->get_row( $wpdb->prepare(
        "SELECT c.component_id, c.pathway_id
         FROM {$wpdb->prefix}hl_component c
         JOIN {$wpdb->prefix}hl_pathway p ON c.pathway_id = p.pathway_id
         JOIN {$wpdb->prefix}hl_pathway_assignment pa ON p.pathway_id = pa.pathway_id
         WHERE p.cycle_id = %d
           AND pa.enrollment_id = %d
           AND c.component_type = 'reflective_practice_session'
           AND c.status = 'active'
           AND c.external_ref LIKE %s",
        $cycle_id,
        $enrollment_id,
        '%"session_number":' . intval( $session_number ) . '%'
    ), ARRAY_A );

    if ( ! $component ) {
        return;
    }

    $wpdb->replace(
        $wpdb->prefix . 'hl_component_state',
        array(
            'component_id'      => $component['component_id'],
            'enrollment_id'     => $enrollment_id,
            'completion_percent' => 100,
            'completion_status' => 'complete',
            'completed_at'      => current_time( 'mysql' ),
            'updated_at'        => current_time( 'mysql' ),
        ),
        array( '%d', '%d', '%d', '%s', '%s', '%s' )
    );

    do_action( 'hl_core_recompute_rollups', $enrollment_id, $cycle_id );
}
```

- [ ] **Step 2: Commit**

```bash
git add includes/services/class-hl-rp-session-service.php
git commit -m "feat: add HL_RP_Session_Service with full CRUD and form submissions"
```

---

### Task 1.3: Create HL_Classroom_Visit_Service

**Files:**
- Create: `includes/services/class-hl-classroom-visit-service.php`

**Context:** Same pattern as Task 1.2, but for classroom visits. Simpler status lifecycle (pending→completed only).

- [ ] **Step 1: Create the service file**

Create `includes/services/class-hl-classroom-visit-service.php`. Implement all methods from spec Section 5.2.

Key method: `get_teachers_for_leader()` — the join path:

```php
public function get_teachers_for_leader( $leader_enrollment_id, $cycle_id ) {
    global $wpdb;

    // Get the leader's user_id and school scope
    $leader = $wpdb->get_row( $wpdb->prepare(
        "SELECT e.user_id, e.enrollment_id
         FROM {$wpdb->prefix}hl_enrollment e
         WHERE e.enrollment_id = %d",
        $leader_enrollment_id
    ), ARRAY_A );

    if ( ! $leader ) {
        return array();
    }

    // Find schools linked to this cycle
    $schools = $wpdb->get_col( $wpdb->prepare(
        "SELECT cs.school_id
         FROM {$wpdb->prefix}hl_cycle_school cs
         WHERE cs.cycle_id = %d",
        $cycle_id
    ) );

    if ( empty( $schools ) ) {
        return array();
    }

    // Get teacher enrollments in those schools
    // Teachers have teaching assignments in classrooms belonging to those schools
    $placeholders = implode( ',', array_fill( 0, count( $schools ), '%d' ) );
    $args = array_merge( array( $cycle_id ), $schools );

    return $wpdb->get_results( $wpdb->prepare(
        "SELECT DISTINCT e.enrollment_id, e.user_id, u.display_name, u.user_email
         FROM {$wpdb->prefix}hl_enrollment e
         JOIN {$wpdb->users} u ON e.user_id = u.ID
         JOIN {$wpdb->prefix}hl_teaching_assignment ta ON e.enrollment_id = ta.enrollment_id
         JOIN {$wpdb->prefix}hl_classroom cl ON ta.classroom_id = cl.classroom_id
         WHERE e.cycle_id = %d
           AND cl.school_id IN ($placeholders)
           AND e.status = 'active'
         ORDER BY u.display_name ASC",
        ...$args
    ), ARRAY_A ) ?: array();
}
```

`update_component_state` follows the same pattern as Task 1.2 but uses `classroom_visit` type and `visit_number` in `external_ref`.

- [ ] **Step 2: Commit**

```bash
git add includes/services/class-hl-classroom-visit-service.php
git commit -m "feat: add HL_Classroom_Visit_Service with full CRUD and form submissions"
```

---

### Task 1.4: Create HL_Session_Prep_Service

**Files:**
- Create: `includes/services/class-hl-session-prep-service.php`

**Context:** This is a read-only helper service. All methods are SELECT queries that return data for auto-populated form sections. Uses eager-loading JOINs.

- [ ] **Step 1: Create the service file**

Implement all 4 methods from spec Section 5.3:

```php
<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class HL_Session_Prep_Service {

    /**
     * Get supervisee's pathway progress.
     */
    public function get_supervisee_progress( $enrollment_id, $cycle_id ) {
        global $wpdb;
        // Single query: count completed vs total components for this enrollment's assigned pathway
        $result = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                COUNT(c.component_id) as total_components,
                SUM(CASE WHEN cs.completion_status = 'complete' THEN 1 ELSE 0 END) as completed_components,
                (SELECT c2.title FROM {$wpdb->prefix}hl_component c2
                 JOIN {$wpdb->prefix}hl_component_state cs2 ON c2.component_id = cs2.component_id AND cs2.enrollment_id = %d
                 WHERE c2.pathway_id = p.pathway_id AND c2.component_type = 'learndash_course' AND c2.status = 'active'
                 ORDER BY cs2.updated_at DESC LIMIT 1) as current_course
             FROM {$wpdb->prefix}hl_component c
             JOIN {$wpdb->prefix}hl_pathway p ON c.pathway_id = p.pathway_id
             JOIN {$wpdb->prefix}hl_pathway_assignment pa ON p.pathway_id = pa.pathway_id AND pa.enrollment_id = %d
             LEFT JOIN {$wpdb->prefix}hl_component_state cs ON c.component_id = cs.component_id AND cs.enrollment_id = %d
             WHERE p.cycle_id = %d AND c.status = 'active'",
            $enrollment_id, $enrollment_id, $enrollment_id, $cycle_id
        ), ARRAY_A );

        return $result ?: array( 'total_components' => 0, 'completed_components' => 0, 'current_course' => null );
    }

    /**
     * Get previous action plan submissions for scrollable list.
     * @param string $context 'coaching' or 'mentoring'
     */
    public function get_previous_action_plans( $enrollment_id, $cycle_id, $context = 'mentoring' ) {
        global $wpdb;

        if ( $context === 'coaching' ) {
            // Query hl_coaching_session_submission via hl_coaching_session
            return $wpdb->get_results( $wpdb->prepare(
                "SELECT sub.responses_json, sub.submitted_at, cs.session_datetime, cs.session_title
                 FROM {$wpdb->prefix}hl_coaching_session_submission sub
                 JOIN {$wpdb->prefix}hl_coaching_session cs ON sub.session_id = cs.session_id
                 WHERE cs.mentor_enrollment_id = %d AND cs.cycle_id = %d
                   AND sub.role_in_session = 'supervisee' AND sub.status = 'submitted'
                 ORDER BY sub.submitted_at DESC",
                $enrollment_id, $cycle_id
            ), ARRAY_A ) ?: array();
        }

        // Default: mentoring context — query hl_rp_session_submission
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT sub.responses_json, sub.submitted_at, rps.session_date, rps.session_number
             FROM {$wpdb->prefix}hl_rp_session_submission sub
             JOIN {$wpdb->prefix}hl_rp_session rps ON sub.rp_session_id = rps.rp_session_id
             WHERE rps.teacher_enrollment_id = %d AND rps.cycle_id = %d
               AND sub.role_in_session = 'supervisee' AND sub.status = 'submitted'
             ORDER BY sub.submitted_at DESC",
            $enrollment_id, $cycle_id
        ), ARRAY_A ) ?: array();
    }

    // ... get_classroom_visit_review() and get_classroom_visit_for_mentor_context() ...
}
```

- [ ] **Step 2: Commit**

```bash
git add includes/services/class-hl-session-prep-service.php
git commit -m "feat: add HL_Session_Prep_Service for auto-populated form sections"
```

---

### Task 1.5: Extend HL_Coaching_Service with Submission Methods

**Files:**
- Modify: `includes/services/class-hl-coaching-service.php`

**Context:** Read the existing file. Add 3 new methods at the end of the class (before the closing `}`).

- [ ] **Step 1: Add `submit_form()` method**

```php
/**
 * Save or submit a coaching session form (Action Plan or RP Notes).
 * Upserts based on unique constraint (session_id, role_in_session).
 */
public function submit_form( $session_id, $user_id, $instrument_id, $role, $responses_json, $status = 'draft' ) {
    global $wpdb;
    $table = $wpdb->prefix . 'hl_coaching_session_submission';

    $existing = $wpdb->get_row( $wpdb->prepare(
        "SELECT submission_id FROM {$table} WHERE session_id = %d AND role_in_session = %s",
        $session_id, $role
    ), ARRAY_A );

    $data = array(
        'session_id'           => $session_id,
        'submitted_by_user_id' => $user_id,
        'instrument_id'        => $instrument_id,
        'role_in_session'      => $role,
        'responses_json'       => $responses_json,
        'status'               => $status,
        'updated_at'           => current_time( 'mysql' ),
    );

    if ( $status === 'submitted' ) {
        $data['submitted_at'] = current_time( 'mysql' );
    }

    if ( $existing ) {
        $wpdb->update( $table, $data, array( 'submission_id' => $existing['submission_id'] ) );
        return (int) $existing['submission_id'];
    }

    $data['submission_uuid'] = wp_generate_uuid4();
    $data['created_at']      = current_time( 'mysql' );
    $wpdb->insert( $table, $data );
    return (int) $wpdb->insert_id;
}
```

- [ ] **Step 2: Add `get_submissions()` method**

```php
public function get_submissions( $session_id ) {
    global $wpdb;
    return $wpdb->get_results( $wpdb->prepare(
        "SELECT sub.*, u.display_name as submitted_by_name
         FROM {$wpdb->prefix}hl_coaching_session_submission sub
         LEFT JOIN {$wpdb->users} u ON sub.submitted_by_user_id = u.ID
         WHERE sub.session_id = %d ORDER BY sub.role_in_session ASC",
        $session_id
    ), ARRAY_A ) ?: array();
}
```

- [ ] **Step 3: Add `get_previous_coaching_action_plans()` method**

```php
public function get_previous_coaching_action_plans( $mentor_enrollment_id, $cycle_id ) {
    global $wpdb;
    return $wpdb->get_results( $wpdb->prepare(
        "SELECT sub.responses_json, sub.submitted_at, cs.session_title, cs.session_datetime
         FROM {$wpdb->prefix}hl_coaching_session_submission sub
         JOIN {$wpdb->prefix}hl_coaching_session cs ON sub.session_id = cs.session_id
         WHERE cs.mentor_enrollment_id = %d AND cs.cycle_id = %d
           AND sub.role_in_session = 'supervisee' AND sub.status = 'submitted'
         ORDER BY sub.submitted_at DESC",
        $mentor_enrollment_id, $cycle_id
    ), ARRAY_A ) ?: array();
}
```

- [ ] **Step 4: Commit**

```bash
git add includes/services/class-hl-coaching-service.php
git commit -m "feat: extend HL_Coaching_Service with form submission methods"
```

---

### Task 1.6: Register New Files in hl-core.php

**Files:**
- Modify: `hl-core.php`

**Context:** Read the file to find where services are loaded (inside `load_dependencies()`) and where CLI commands are registered (inside `init()`).

- [ ] **Step 1: Add require_once lines in `load_dependencies()`**

In the services section (after existing service requires), add:

```php
require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-rp-session-service.php';
require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-classroom-visit-service.php';
require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-session-prep-service.php';
```

In the frontend section (after existing frontend requires), add:

```php
require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-rp-session.php';
require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-classroom-visit.php';
require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-self-reflection.php';
require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-action-plan.php';
require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-rp-notes.php';
```

In the CLI section (inside the `WP_CLI` check), add:

```php
require_once HL_CORE_INCLUDES_DIR . 'cli/class-hl-cli-setup-elcpb-y2-v2.php';
```

**NOTE:** The frontend files don't exist yet (created in Session 2). That's OK — PHP won't error because these are `require_once` at plugin load, and the files will exist before the plugin runs in production. If you want to be safe, create empty placeholder files:

```php
<?php
// Placeholder — implemented in Session 2
if ( ! defined( 'ABSPATH' ) ) exit;
```

- [ ] **Step 2: Register CLI command in `init()` method**

In the WP_CLI command registration block within `init()`:

```php
HL_CLI_Setup_ELCPB_Y2_V2::register();
```

- [ ] **Step 3: Commit**

```bash
git add hl-core.php
git commit -m "feat: register new services, frontend renderers, and CLI in plugin bootstrap"
```

---

### Task 1.7: Create Frontend Placeholder Files

**Files:**
- Create: 5 placeholder files in `includes/frontend/`

**Context:** Sessions 2 and 3 will implement these. We create placeholders now so `hl-core.php` doesn't fatal on `require_once`.

- [ ] **Step 1: Create 5 placeholder files**

For each of these files, create with minimal content:
- `includes/frontend/class-hl-frontend-rp-session.php`
- `includes/frontend/class-hl-frontend-classroom-visit.php`
- `includes/frontend/class-hl-frontend-self-reflection.php`
- `includes/frontend/class-hl-frontend-action-plan.php`
- `includes/frontend/class-hl-frontend-rp-notes.php`

Each file:
```php
<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// TODO: Implemented in Session 2
class HL_Frontend_RP_Session {
    public function render() { return ''; }
}
```

Use the correct class name for each file (e.g., `HL_Frontend_Classroom_Visit`, `HL_Frontend_Self_Reflection`, `HL_Frontend_Action_Plan`, `HL_Frontend_RP_Notes`).

- [ ] **Step 2: Create CLI placeholder**

Create `includes/cli/class-hl-cli-setup-elcpb-y2-v2.php`:

```php
<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// TODO: Implemented in Session 4
class HL_CLI_Setup_ELCPB_Y2_V2 {
    public static function register() {
        if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) return;
        WP_CLI::add_command( 'hl-core setup-elcpb-y2-v2', array( new self(), 'run' ) );
    }
    public function run( $args, $assoc_args ) {
        WP_CLI::warning( 'Not yet implemented. Coming in Session 4.' );
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add includes/frontend/class-hl-frontend-rp-session.php includes/frontend/class-hl-frontend-classroom-visit.php includes/frontend/class-hl-frontend-self-reflection.php includes/frontend/class-hl-frontend-action-plan.php includes/frontend/class-hl-frontend-rp-notes.php includes/cli/class-hl-cli-setup-elcpb-y2-v2.php
git commit -m "feat: add placeholder files for frontend renderers and CLI (Sessions 2-4)"
```

---

## SESSION 2: Instruments & Form Renderers

**Mode:** Fast Mode ON
**Estimated tasks:** 6
**Dependencies:** Session 1 must be complete
**Can run in parallel with:** Session 3

### Task 2.1: Seed 6 New Instruments

**Files:**
- Create or modify: Instrument seeding can go in the Y2 CLI (Session 4) or as a standalone method. For now, create a helper method in `HL_RP_Session_Service` or a standalone seeder.

**Context:** Read the existing `hl_teacher_assessment_instrument` table schema and how instruments are created (look at `class-hl-cli-seed-demo.php` or `class-hl-cli-provision-lutheran.php` for instrument insert patterns).

- [ ] **Step 1: Read how existing instruments are seeded**

Search for `hl_teacher_assessment_instrument` INSERT patterns in CLI files.

- [ ] **Step 2: Create instrument definitions**

Create a helper class or add to the Y2 CLI. Each instrument needs: `instrument_key`, `title`, `description`, `sections` (JSON), `status = 'active'`, `version = 1`.

The `sections` JSON for Action Plan instruments should contain the domain/skills mapping:

```php
$action_plan_sections = json_encode( array(
    array(
        'key'   => 'planning',
        'title' => 'Planning',
        'fields' => array(
            array( 'key' => 'domain', 'type' => 'select', 'label' => 'Domain', 'options' => array(
                'emotional_climate'    => 'Emotional Climate & Teacher Presence',
                'ecsel_language'       => 'ECSEL Language & Emotional Communication',
                'co_regulation'        => 'Co-Regulation & Emotional Support',
                'social_skills'        => 'Social Skills, Empathy & Inclusion',
                'ecsel_tools'          => 'Use of Developmentally-Appropriate ECSEL Tools',
                'daily_integration'    => 'Integration into Daily Learning',
            )),
            array( 'key' => 'skills', 'type' => 'multiselect', 'label' => 'Skills/Strategy', 'conditional_on' => 'domain', 'options_by_domain' => array(
                'emotional_climate' => array(
                    'Demonstrate calm, emotionally regulated presence',
                    'Model attentive, engaged, and supportive behavior',
                ),
                'ecsel_language' => array(
                    'Consistently use emotion language to label/validate feelings',
                    'Use Causal Talk (CT) to connect emotions, behavior, experiences',
                ),
                'co_regulation' => array(
                    'Use Causal Talk in Emotional Experience (CTEE) for heightened emotions',
                    'Guide children toward regulation before problem-solving',
                ),
                'social_skills' => array(
                    'Model/encourage empathy, cooperation, respect',
                    'Classroom interactions reflect inclusion and respect',
                    'Guide children through conflict resolution steps',
                ),
                'ecsel_tools' => array(
                    'ECSEL tools visible, accessible, intentionally placed',
                    'Use tools appropriately for emotion knowledge/conflict resolution',
                ),
                'daily_integration' => array(
                    'Embed tools, language, strategies in play/routines/learning',
                    'Use emotional moments as learning opportunities',
                ),
            )),
            array( 'key' => 'how', 'type' => 'textarea', 'label' => 'Describe HOW you will practice the skill(s)' ),
            array( 'key' => 'what', 'type' => 'textarea', 'label' => 'WHAT behaviors will you track to know this is effective?' ),
        ),
    ),
    array(
        'key'   => 'results',
        'title' => 'Results',
        'fields' => array(
            array( 'key' => 'practice_reflection', 'type' => 'textarea', 'label' => 'From your perspective, how has your practice gone?' ),
            array( 'key' => 'success_degree', 'type' => 'likert', 'label' => 'Degree of success', 'scale' => array( 1 => 'Not at all Successful', 2 => 'Slightly Successful', 3 => 'Moderately Successful', 4 => 'Very Successful', 5 => 'Extremely Successful' ) ),
            array( 'key' => 'impact_observations', 'type' => 'textarea', 'label' => 'Observations of impact on students' ),
            array( 'key' => 'what_learned', 'type' => 'textarea', 'label' => 'What you learned' ),
            array( 'key' => 'still_wondering', 'type' => 'textarea', 'label' => 'What you\'re still wondering' ),
        ),
    ),
) );
```

Create similar JSON structures for RP Notes (5 editable sections + guide accordion) and Classroom Visit (6 domain blocks with indicator arrays).

- [ ] **Step 3: Insert 6 instruments into DB**

Use `$wpdb->insert()` for each, checking if `instrument_key` already exists first (idempotent).

- [ ] **Step 4: Commit**

```bash
git commit -m "feat: seed 6 new instruments for RP notes, action plans, classroom visits, and self-reflection"
```

---

### Task 2.2: Build Action Plan Renderer

**Files:**
- Modify: `includes/frontend/class-hl-frontend-action-plan.php` (replace placeholder)

**Context:** This renderer is used by BOTH coaching (Mentor fills) and mentoring (Teacher fills) contexts. It reads the instrument's `sections` JSON for domain/skills options and renders the form.

- [ ] **Step 1: Implement the full renderer class**

```php
class HL_Frontend_Action_Plan {
    public function render( $context, $session_entity, $enrollment, $instrument, $existing_submission = null ) {
        ob_start();
        // Read instrument sections JSON for domain/skills
        $sections = json_decode( $instrument->sections, true );
        $responses = $existing_submission ? json_decode( $existing_submission['responses_json'], true ) : array();
        $is_readonly = ( $existing_submission && $existing_submission['status'] === 'submitted' );
        // Render form with domain dropdown, conditional skills, textareas, likert
        // Include JS for conditional skills (show/hide based on domain selection)
        // Draft save via AJAX, submit via POST with nonce
        ?>
        <div class="hl-form hl-action-plan-form">
            <!-- Form HTML here -->
        </div>
        <?php
        return ob_get_clean();
    }
}
```

Key implementation details:
- Domain dropdown triggers JS to show/hide skills multi-select options
- Draft save sends AJAX POST to admin-ajax.php with action `hl_save_action_plan_draft`
- Final submit sends POST with nonce verification
- Read-only mode renders all fields as static text
- Use `esc_html()`, `esc_attr()`, `wp_nonce_field()` for security

- [ ] **Step 2: Commit**

```bash
git add includes/frontend/class-hl-frontend-action-plan.php
git commit -m "feat: implement Action Plan & Results form renderer with conditional domains/skills"
```

---

### Task 2.3: Build RP Notes Renderer

**Files:**
- Modify: `includes/frontend/class-hl-frontend-rp-notes.php` (replace placeholder)

**Context:** Most complex renderer. Has auto-populated sections that pull data from HL_Session_Prep_Service, plus editable sections for session notes. Used by both coaching (Coach fills) and mentoring (Mentor fills) contexts.

- [ ] **Step 1: Implement the full renderer class**

Key sections to render:
1. **Session Info** — auto-populated from the session entity (read-only div)
2. **Personal Notes** — wp_editor or textarea (supervisor-only)
3. **Session Prep** — call `$prep_service->get_supervisee_progress()` and `$prep_service->get_previous_action_plans()`, render as read-only cards/list
4. **Classroom Visit Review** — call `$prep_service->get_classroom_visit_review()`, render as read-only table
5. **RP Session Notes** — 5 rich text fields + date picker
6. **RP Steps Guide** — expandable accordion with static guide content

Use WordPress `wp_editor()` for rich text fields (with minimal toolbar config):
```php
wp_editor( $value, $editor_id, array(
    'media_buttons' => false,
    'textarea_rows' => 4,
    'teeny'         => true,
    'quicktags'     => false,
) );
```

- [ ] **Step 2: Commit**

```bash
git add includes/frontend/class-hl-frontend-rp-notes.php
git commit -m "feat: implement RP Notes form renderer with auto-populated prep sections"
```

---

### Task 2.4: Build Classroom Visit Form Renderer

**Files:**
- Modify: `includes/frontend/class-hl-frontend-classroom-visit.php` (replace placeholder)

**Context:** Renders the domain/indicator checklist form. 6 domains, each with Yes/No indicators and conditional description textareas.

- [ ] **Step 1: Implement the renderer**

Structure:
- Auto-populated header (school, teacher, date, visitor, age group)
- Context checkboxes (Free Play, Formal Group, Transition, Routine)
- For each domain: collapsible section with indicators
- Each indicator: Yes/No toggle button pair + description textarea (required if Yes, hidden if No)
- JS to show/hide description based on Yes/No toggle
- Draft save + submit

The domain/indicator data comes from the instrument's `sections` JSON.

- [ ] **Step 2: Commit**

```bash
git add includes/frontend/class-hl-frontend-classroom-visit.php
git commit -m "feat: implement Classroom Visit form renderer with domain/indicator checklist"
```

---

### Task 2.5: Build Self-Reflection Form Renderer

**Files:**
- Modify: `includes/frontend/class-hl-frontend-self-reflection.php` (replace placeholder)

**Context:** Nearly identical to Classroom Visit renderer but with self-assessment framing ("I demonstrated..." instead of "Teacher demonstrated...").

- [ ] **Step 1: Implement the renderer**

Reuse the same rendering logic as Classroom Visit but:
- Change indicator labels to first-person
- Auto-populate header with current user's info (not a separate teacher)
- Same draft/submit pattern

Consider extracting shared rendering logic into a private method or trait to avoid duplication with Task 2.4.

- [ ] **Step 2: Commit**

```bash
git add includes/frontend/class-hl-frontend-self-reflection.php
git commit -m "feat: implement Self-Reflection form renderer with self-assessment framing"
```

---

### Task 2.6: Build RP Session Frontend Renderer

**Files:**
- Modify: `includes/frontend/class-hl-frontend-rp-session.php` (replace placeholder)

**Context:** This is the page controller that renders when a user clicks an RP Session component. It decides whether to show the Mentor view (RP Notes) or Teacher view (Action Plan) based on the user's role.

- [ ] **Step 1: Implement the renderer**

```php
class HL_Frontend_RP_Session {
    public function render( $component, $enrollment, $cycle_id ) {
        $user_id = get_current_user_id();
        $rp_service = new HL_RP_Session_Service();
        $external_ref = json_decode( $component->external_ref, true );
        $session_number = $external_ref['session_number'] ?? 1;

        // Determine role: is user the mentor or teacher?
        $enrollment_roles = json_decode( $enrollment->roles, true ) ?: array();
        $is_mentor = in_array( 'mentor', $enrollment_roles, true );

        if ( $is_mentor ) {
            return $this->render_mentor_view( $enrollment, $cycle_id, $session_number, $rp_service );
        }
        return $this->render_teacher_view( $enrollment, $cycle_id, $session_number, $rp_service );
    }

    private function render_mentor_view( $enrollment, $cycle_id, $session_number, $rp_service ) {
        // Show list of teachers in team
        // Each teacher row: name, session status, "Open RP Session" link
        // When a specific teacher+session is selected, render RP Notes form (side-by-side with Action Plan if available)
    }

    private function render_teacher_view( $enrollment, $cycle_id, $session_number, $rp_service ) {
        // Show the Action Plan form for this session
        // If already submitted, show read-only
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add includes/frontend/class-hl-frontend-rp-session.php
git commit -m "feat: implement RP Session page controller with role-based mentor/teacher views"
```

---

## SESSION 3: Frontend Page Modifications

**Mode:** Fast Mode ON
**Estimated tasks:** 3
**Dependencies:** Session 1 must be complete
**Can run in parallel with:** Session 2

### Task 3.1: Add Component Type Renderers to Component Page

**Files:**
- Modify: `includes/frontend/class-hl-frontend-component-page.php`

**Context:** Read the existing file. Find the `render_available_view()` method (or equivalent dispatcher). Add 3 new `if` blocks for the new component types.

- [ ] **Step 1: Read the existing component page file**

Find the dispatcher method that checks `$component->component_type` and routes to different renderers.

- [ ] **Step 2: Add type labels**

In the `$type_labels` array, add:

```php
'reflective_practice_session' => 'Reflective Practice Session',
'classroom_visit'             => 'Classroom Visit',
'self_reflection'             => 'Self-Reflection',
```

- [ ] **Step 3: Add renderer branches**

In the dispatcher method, add before the fallback:

```php
if ( $type === 'reflective_practice_session' ) {
    $renderer = new HL_Frontend_RP_Session();
    echo $renderer->render( $component, $enrollment, $cycle_id );
    return;
}

if ( $type === 'classroom_visit' ) {
    $renderer = new HL_Frontend_Classroom_Visit();
    echo $renderer->render( $component, $enrollment, $cycle_id );
    return;
}

if ( $type === 'self_reflection' ) {
    $renderer = new HL_Frontend_Self_Reflection();
    echo $renderer->render( $component, $enrollment, $cycle_id );
    return;
}
```

- [ ] **Step 4: Commit**

```bash
git add includes/frontend/class-hl-frontend-component-page.php
git commit -m "feat: add component type dispatchers for RP sessions, classroom visits, and self-reflection"
```

---

### Task 3.2: Enhance My Coaching Page

**Files:**
- Modify: `includes/frontend/class-hl-frontend-my-coaching.php`

**Context:** Read the existing file. The schedule form currently uses basic date/time inputs. Enhance it with a calendar widget and add inline Action Plan display.

- [ ] **Step 1: Read the existing file**

Understand the current render flow: coach card, enrollment switcher, upcoming sessions, past sessions, schedule form.

- [ ] **Step 2: Add calendar date picker widget**

Replace the date input in the schedule form with a simple PHP-rendered month calendar grid:

```php
private function render_calendar_picker( $selected_date = '' ) {
    $month = $selected_date ? date( 'n', strtotime( $selected_date ) ) : date( 'n' );
    $year  = $selected_date ? date( 'Y', strtotime( $selected_date ) ) : date( 'Y' );
    $days_in_month = cal_days_in_month( CAL_GREGORIAN, $month, $year );
    $first_day     = date( 'w', mktime( 0, 0, 0, $month, 1, $year ) );

    ?>
    <div class="hl-calendar-picker" data-month="<?php echo $month; ?>" data-year="<?php echo $year; ?>">
        <div class="hl-calendar-header">
            <span class="hl-calendar-title"><?php echo date( 'F Y', mktime( 0, 0, 0, $month, 1, $year ) ); ?></span>
        </div>
        <div class="hl-calendar-grid">
            <div class="hl-calendar-dow">Sun</div>
            <div class="hl-calendar-dow">Mon</div>
            <!-- ...all 7 days... -->
            <?php for ( $i = 0; $i < $first_day; $i++ ) : ?>
                <div class="hl-calendar-day hl-calendar-empty"></div>
            <?php endfor; ?>
            <?php for ( $d = 1; $d <= $days_in_month; $d++ ) : ?>
                <div class="hl-calendar-day" data-date="<?php echo esc_attr( sprintf( '%04d-%02d-%02d', $year, $month, $d ) ); ?>"
                     onclick="selectDate(this)">
                    <?php echo $d; ?>
                </div>
            <?php endfor; ?>
        </div>
        <input type="hidden" name="session_date" id="hl-selected-date" value="<?php echo esc_attr( $selected_date ); ?>">
    </div>
    <?php
}
```

Add inline JS for `selectDate()` that highlights the day and sets the hidden input value.

- [ ] **Step 3: Add inline Action Plan display for attended sessions**

When rendering past sessions with status='attended', check for submissions and show the Action Plan form in read-only mode:

```php
$coaching_service = new HL_Coaching_Service();
$submissions = $coaching_service->get_submissions( $session['session_id'] );
if ( ! empty( $submissions ) ) {
    $action_plan_renderer = new HL_Frontend_Action_Plan();
    // Find the supervisee submission
    foreach ( $submissions as $sub ) {
        if ( $sub['role_in_session'] === 'supervisee' ) {
            echo $action_plan_renderer->render( 'coaching', $session, $enrollment, $instrument, $sub );
        }
    }
}
```

- [ ] **Step 4: Add CSS for calendar widget**

Add CSS either inline or in the existing HL Core stylesheet. Simple grid layout:

```css
.hl-calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 2px; }
.hl-calendar-day { padding: 8px; text-align: center; cursor: pointer; border-radius: 4px; }
.hl-calendar-day:hover { background: #e8f4fd; }
.hl-calendar-day.selected { background: #0073aa; color: #fff; }
```

- [ ] **Step 5: Commit**

```bash
git add includes/frontend/class-hl-frontend-my-coaching.php
git commit -m "feat: enhance My Coaching page with calendar widget and inline Action Plan display"
```

---

### Task 3.3: Enhance Coaching Hub Frontend

**Files:**
- Modify: `includes/frontend/class-hl-frontend-coaching-hub.php`

**Context:** Read the existing file. Add a coaches card grid section and an optional calendar view toggle.

- [ ] **Step 1: Read the existing file**

Understand the current render: session table with filters.

- [ ] **Step 2: Add Coaches section**

Before the sessions table, render a coaches card grid:

```php
private function render_coaches_section() {
    $coaches = get_users( array( 'role' => 'coach' ) );
    if ( empty( $coaches ) ) {
        return;
    }
    ?>
    <div class="hl-section hl-coaches-section">
        <h3><?php esc_html_e( 'Coaches', 'hl-core' ); ?></h3>
        <div class="hl-card-grid">
            <?php foreach ( $coaches as $coach ) : ?>
                <div class="hl-card hl-coach-card">
                    <div class="hl-coach-avatar"><?php echo get_avatar( $coach->ID, 64 ); ?></div>
                    <div class="hl-coach-info">
                        <strong><?php echo esc_html( $coach->display_name ); ?></strong>
                        <span><?php echo esc_html( $coach->user_email ); ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}
```

- [ ] **Step 3: Add calendar view toggle**

Add a toggle button above the sessions table. When "Calendar" is active, render a month grid with session dots:

```php
private function render_calendar_view( $sessions ) {
    // Group sessions by date
    $by_date = array();
    foreach ( $sessions as $s ) {
        $date = substr( $s['session_datetime'] ?? '', 0, 10 );
        if ( $date ) {
            $by_date[ $date ][] = $s;
        }
    }
    // Render month grid with colored dots
    // green=attended, blue=scheduled, red=missed, gray=cancelled
}
```

- [ ] **Step 4: Commit**

```bash
git add includes/frontend/class-hl-frontend-coaching-hub.php
git commit -m "feat: enhance Coaching Hub with coaches grid and calendar view toggle"
```

---

## SESSION 4: Admin Pages & CLI Rebuild

**Mode:** Fast Mode ON
**Estimated tasks:** 4
**Dependencies:** Sessions 1, 2, and 3 must be complete

### Task 4.1: Add Coaches Tab to Admin Coaching Hub

**Files:**
- Modify: `includes/admin/class-hl-admin-coaching.php`

**Context:** Read the existing file. It uses tab navigation (Sessions + Assignments). Add a third tab "Coaches".

- [ ] **Step 1: Read the existing file**

Note the tab rendering pattern and the `handle_early_actions()` / `render_page()` dispatch.

- [ ] **Step 2: Add "Coaches" to tab array**

In `render_hub_tabs()` or equivalent:

```php
$tabs = array(
    'sessions'    => __( 'Sessions', 'hl-core' ),
    'assignments' => __( 'Assignments', 'hl-core' ),
    'coaches'     => __( 'Coaches', 'hl-core' ),
);
```

- [ ] **Step 3: Add coaches tab rendering**

In `render_page()`, add the coaches branch:

```php
if ( $tab === 'coaches' ) {
    $this->render_coaches_content();
    return;
}
```

Implement `render_coaches_content()`:
- List view: WP_List_Table style table of Coach-role users
- Add Coach form: name, email fields
- Handle POST to create/assign Coach role
- Handle delete (remove Coach role, not delete user)

```php
private function render_coaches_content() {
    // Handle add/remove actions
    if ( isset( $_POST['hl_add_coach'] ) && wp_verify_nonce( $_POST['_wpnonce'], 'hl_add_coach' ) ) {
        $email = sanitize_email( $_POST['coach_email'] ?? '' );
        $name  = sanitize_text_field( $_POST['coach_name'] ?? '' );

        if ( $email ) {
            $existing = get_user_by( 'email', $email );
            if ( $existing ) {
                $existing->add_role( 'coach' );
            } else {
                $user_id = wp_insert_user( array(
                    'user_login'   => $email,
                    'user_email'   => $email,
                    'display_name' => $name ?: $email,
                    'role'         => 'coach',
                    'user_pass'    => wp_generate_password(),
                ) );
                if ( ! is_wp_error( $user_id ) ) {
                    wp_new_user_notification( $user_id, null, 'user' );
                }
            }
        }
    }

    $coaches = get_users( array( 'role' => 'coach' ) );
    // Render table and add form...
}
```

- [ ] **Step 4: Add `handle_early_actions()` for coaches tab**

Handle POST saves and GET deletes with redirect-before-output pattern (matching existing admin pattern).

- [ ] **Step 5: Commit**

```bash
git add includes/admin/class-hl-admin-coaching.php
git commit -m "feat: add Coaches tab to admin Coaching Hub with list, add, and remove"
```

---

### Task 4.2: Add RP Sessions & Classroom Visits to Cycle Editor

**Files:**
- Modify: `includes/admin/class-hl-admin-cycles.php`

**Context:** Read the existing file. The cycle editor has a tabbed interface. The Coaching tab shows sessions + assignments. Add RP Sessions and Classroom Visits as subtabs within the Coaching tab.

- [ ] **Step 1: Read the existing Coaching tab rendering**

Find where the Coaching tab content is rendered in the cycle editor.

- [ ] **Step 2: Add subtab navigation within Coaching tab**

```php
$coaching_subtab = isset( $_GET['coaching_sub'] ) ? sanitize_text_field( $_GET['coaching_sub'] ) : 'sessions';
$subtabs = array(
    'sessions'          => __( 'Coaching Sessions', 'hl-core' ),
    'assignments'       => __( 'Assignments', 'hl-core' ),
    'rp_sessions'       => __( 'RP Sessions', 'hl-core' ),
    'classroom_visits'  => __( 'Classroom Visits', 'hl-core' ),
);
```

- [ ] **Step 3: Implement RP Sessions subtab**

Query `hl_rp_session` for this cycle and render a table:

```php
private function render_rp_sessions_subtab( $cycle_id ) {
    $rp_service = new HL_RP_Session_Service();
    $sessions = $rp_service->get_by_cycle( $cycle_id );
    // Render table: Mentor, Teacher, Session #, Status, Date
}
```

- [ ] **Step 4: Implement Classroom Visits subtab**

Same pattern with `HL_Classroom_Visit_Service::get_by_cycle()`.

- [ ] **Step 5: Commit**

```bash
git add includes/admin/class-hl-admin-cycles.php
git commit -m "feat: add RP Sessions and Classroom Visits subtabs to cycle editor Coaching tab"
```

---

### Task 4.3: Rebuild ELCPB Y2 CLI Command

**Files:**
- Modify: `includes/cli/class-hl-cli-setup-elcpb-y2-v2.php` (replace placeholder)

**Context:** Read the existing `class-hl-cli-setup-elcpb-y2.php` for the pattern. The new version creates the correct pathway structure from the Excel. Also read the spec Section 8 for exact pathway compositions.

- [ ] **Step 1: Read existing Y2 setup CLI for patterns**

Note: class constants for LD course IDs, `resolve_context()`, `create_cycle()`, pathway creation loop.

- [ ] **Step 2: Define all 8 pathways with correct components**

Use class constants for all LearnDash course IDs (copy from existing CLI). Define pathway structures as arrays:

```php
private function get_pathway_definitions() {
    return array(
        'B2E Teacher - Phase 1' => array(
            'target_roles'  => array( 'teacher' ),
            'components'    => array(
                array( 'title' => 'Teacher Self-Assessment (Pre)', 'type' => 'teacher_self_assessment', 'external_ref' => '{"teacher_instrument_id":1,"phase":"pre"}' ),
                array( 'title' => 'Child Assessment (Pre)', 'type' => 'child_assessment', 'external_ref' => '{"phase":"pre"}' ),
                array( 'title' => 'B2E TC0 (Welcome Course)', 'type' => 'learndash_course', 'external_ref' => '{"course_id":' . self::TC0 . '}', 'prereq_ref' => 0 ),
                array( 'title' => 'B2E TC1', 'type' => 'learndash_course', 'external_ref' => '{"course_id":' . self::TC1 . '}', 'prereq_ref' => 2 ),
                array( 'title' => 'Self-Reflection #1', 'type' => 'self_reflection', 'external_ref' => '{"visit_number":1}' ),
                array( 'title' => 'Reflective Practice Session #1', 'type' => 'reflective_practice_session', 'external_ref' => '{"session_number":1}' ),
                // ... continue for all components
            ),
        ),
        // ... all 8 pathways
    );
}
```

- [ ] **Step 3: Implement prerequisite creation**

For course chains, create prerequisite groups linking each course to its predecessor. All non-course components (SR, RP, Coaching, CV) have NO prerequisites.

Follow the existing pattern in the current Y2 CLI for creating `hl_component_prereq_group` and `hl_component_prereq_item` rows.

- [ ] **Step 4: Seed demo coach Lauren Orf**

At the end of the `run()` method:

```php
$this->seed_demo_coach();

private function seed_demo_coach() {
    $email = 'lorf@housmanlearning.com';
    $existing = get_user_by( 'email', $email );
    if ( $existing ) {
        $existing->add_role( 'coach' );
        WP_CLI::line( "  Coach: Lauren Orf (existing user, Coach role assigned)" );
        return;
    }
    $user_id = wp_insert_user( array(
        'user_login'   => 'lorf',
        'user_email'   => $email,
        'first_name'   => 'Lauren',
        'last_name'    => 'Orf',
        'display_name' => 'Lauren Orf',
        'role'         => 'coach',
        'user_pass'    => wp_generate_password(),
    ) );
    if ( ! is_wp_error( $user_id ) ) {
        WP_CLI::line( "  Coach: Lauren Orf created (user_id={$user_id})" );
    }
}
```

- [ ] **Step 5: Seed the 6 instruments if not already present**

Call the instrument seeding logic from Task 2.1 (either inline or via a shared method).

- [ ] **Step 6: Test with `--clean` flag**

The `--clean` flag should remove the Y2 cycle and all dependent data (pathways, components, prereqs). Follow the existing clean pattern.

- [ ] **Step 7: Commit**

```bash
git add includes/cli/class-hl-cli-setup-elcpb-y2-v2.php
git commit -m "feat: rebuild ELCPB Y2 CLI with correct pathways, new component types, and demo coach"
```

---

### Task 4.4: Update STATUS.md and README.md

**Files:**
- Modify: `STATUS.md`
- Modify: `README.md`

- [ ] **Step 1: Update STATUS.md**

Mark relevant build queue items and add new entries for the completed work.

- [ ] **Step 2: Update README.md**

Update "What's Implemented" section with:
- New DB tables (5)
- New component types (3)
- New services (3)
- New instruments (6)
- New frontend renderers (5)
- Enhanced Coaching Hub
- Rebuilt ELCPB Y2 CLI

- [ ] **Step 3: Commit**

```bash
git add STATUS.md README.md
git commit -m "docs: update STATUS.md and README.md with new forms, events, and coaching features"
```

---

## SESSION 5: Integration & Verification

**Mode:** Normal (Fast Mode OFF)
**Estimated tasks:** 5
**Dependencies:** All prior sessions complete

### Task 5.1: Deploy to Test Server

**Files:** None (deployment)

**Context:** Read `.claude/skills/deploy.md` for SSH and deployment instructions.

- [ ] **Step 1: Push to GitHub**

```bash
git push origin main
```

- [ ] **Step 2: Deploy to test server**

Follow the deployment instructions in `.claude/skills/deploy.md`. SSH to test server, pull latest, run `wp hl-core setup-elcpb-y2-v2 --clean` then `wp hl-core setup-elcpb-y2-v2`.

- [ ] **Step 3: Verify DB tables created**

```bash
wp db query "SHOW TABLES LIKE 'wp_hl_rp%';"
wp db query "SHOW TABLES LIKE 'wp_hl_classroom_visit%';"
wp db query "SHOW TABLES LIKE 'wp_hl_coaching_session_sub%';"
```

- [ ] **Step 4: Verify component types**

```bash
wp db query "SELECT DISTINCT component_type FROM wp_hl_component;"
```

Should show the 3 new types: `reflective_practice_session`, `classroom_visit`, `self_reflection`.

---

### Task 5.2: Verify ELCPB Y2 Pathways

- [ ] **Step 1: Check pathway counts**

```bash
wp db query "SELECT p.title, COUNT(c.component_id) as components FROM wp_hl_pathway p LEFT JOIN wp_hl_component c ON p.pathway_id = c.pathway_id WHERE p.cycle_id = (SELECT cycle_id FROM wp_hl_cycle WHERE cycle_code = 'ELCPB-Y2-2026') GROUP BY p.pathway_id;"
```

Expected: 8 pathways with component counts matching the spec (17, 16, 19, 18, 18, 4, 11, 10).

- [ ] **Step 2: Verify instruments seeded**

```bash
wp db query "SELECT instrument_key, title FROM wp_hl_teacher_assessment_instrument WHERE instrument_key LIKE 'coaching_%' OR instrument_key LIKE 'mentoring_%' OR instrument_key LIKE 'classroom_%' OR instrument_key LIKE 'self_%';"
```

Expected: 6 instruments.

- [ ] **Step 3: Verify demo coach**

```bash
wp user get lorf --fields=ID,display_name,roles
```

---

### Task 5.3: Verify Frontend Rendering

- [ ] **Step 1: Log in as a test teacher and navigate to Program Page**

Check that Self-Reflection and RP Session components appear in the pathway with correct labels and icons.

- [ ] **Step 2: Click on a Self-Reflection component**

Verify the Self-Reflection form renders with domain/indicator checkboxes.

- [ ] **Step 3: Click on an RP Session component**

Verify the Action Plan form renders for teachers.

- [ ] **Step 4: Log in as a test mentor**

Verify the RP Session component shows the teacher list view with RP Notes form.

- [ ] **Step 5: Log in as a test school leader**

Verify Classroom Visit components appear in the Streamlined pathway and render the form.

---

### Task 5.4: Verify Coaching Hub

- [ ] **Step 1: Check admin Coaching Hub → Coaches tab**

Verify Lauren Orf appears in the coaches list.

- [ ] **Step 2: Test adding a new coach**

Use the Add Coach form to add a test coach.

- [ ] **Step 3: Check frontend Coaching Hub**

Verify coaches grid appears.

- [ ] **Step 4: Check My Coaching page**

Verify calendar date picker renders for scheduling.

---

### Task 5.5: Verify Draft Save & Submit Flow

- [ ] **Step 1: Open an Action Plan form**

Fill some fields, click "Save Draft". Verify the draft persists (reload page, data is still there).

- [ ] **Step 2: Submit the form**

Click Submit. Verify status changes to "submitted" and form becomes read-only.

- [ ] **Step 3: Check component state**

```bash
wp db query "SELECT * FROM wp_hl_component_state WHERE completion_status = 'complete' ORDER BY completed_at DESC LIMIT 5;"
```

Verify the corresponding component was marked complete.
