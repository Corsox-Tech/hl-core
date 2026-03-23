# Design Spec: New Forms, Cross-Pathway Events & Coaching Hub

**Date:** 2026-03-23
**Status:** Approved
**Scope:** ELCPB Cycle 2 launch — 3 new form types, cross-pathway event entities, Coaching Hub enhancements

---

## 1. Overview

Housman Learning's B2E Mastery program requires three types of cross-pathway events, each involving two participants who fill separate forms. This spec defines the database schema, instruments, services, frontend renderers, admin pages, and CLI rebuild needed to support them.

### Scope (Priority Order)

1. **ELCPB Cycle 2 launch** — rebuild Y2 pathways with correct component types
2. **3 New Forms** — 6 instruments across 3 event types (full implementation with auto-populated fields)
3. **Coaching Hub enhancements** — admin Coaches tab, frontend scheduling calendar
4. **Pathway auto-assignment** — milestone tracking for future cycles (deferred to separate spec)

### Out of Scope

- Short Courses / ECSELent Adventures restructuring
- Individual Enrollment system
- MS365 Calendar Integration (real calendar sync)

---

## 2. Cross-Pathway Event Model

Three physical event types, each appearing in two pathways with loose coupling (shared reference ID, independent component state):

### Event 1: Coaching Session (Coach <-> Mentor)

- **Existing entity:** `hl_coaching_session` (extended with submission support)
- **Mentor Pathway component type:** `coaching_session_attendance` (existing, enhanced)
- **Coach side:** No pathway — Coach is HL staff, accesses via admin or Coaching Hub
- **Forms:** Mentor fills Action Plan & Results; Coach fills RP Notes
- **Completion:** Component marks complete when session status = `attended` (existing behavior preserved)

### Event 2: Reflective Practice Session (Mentor <-> Teacher)

- **New entity:** `hl_rp_session`
- **Mentor Pathway component type:** `reflective_practice_session` (new)
- **Teacher Pathway component type:** `reflective_practice_session` (same type, role-aware rendering)
- **Forms:** Mentor fills RP Notes; Teacher fills Action Plan & Results
- **Completion:** Each side's component state updates independently when their form is submitted

### Event 3: Classroom Visit (School Leader <-> Teacher)

- **New entity:** `hl_classroom_visit`
- **Streamlined Pathway component type:** `classroom_visit` (new)
- **Teacher Pathway component type:** `self_reflection` (new)
- **Forms:** Leader fills Classroom Visit Form; Teacher fills Self-Reflection Form
- **Completion:** Each side's component state updates independently when their form is submitted

### Loose Coupling Rule

- Both sides of a shared event reference the same entity (RP session ID or classroom visit ID)
- Each side has its own `hl_component_state` entry that tracks independently
- No automatic cross-pathway state propagation
- Reports and admin views can correlate via shared entity ID

---

## 3. Database Schema

**Convention:** All columns use `bigint(20) unsigned` for IDs/FKs to match existing codebase. Tables use `$charset_collate` from `$wpdb->get_charset_collate()` (not hardcoded charset). All DDL goes in `HL_Installer::get_schema()` and is processed by `dbDelta()`.

### 3.1 New Table: `hl_rp_session`

```sql
CREATE TABLE {$prefix}hl_rp_session (
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
) {$charset_collate};
```

**Status values:** `pending` (created, no date set), `scheduled` (date set), `attended`, `missed`, `cancelled`. No `rescheduled` status — RP sessions are simpler meetings that don't need reschedule chain tracking.

### 3.2 New Table: `hl_rp_session_submission`

```sql
CREATE TABLE {$prefix}hl_rp_session_submission (
    submission_id        bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    submission_uuid      char(36) NOT NULL,
    rp_session_id        bigint(20) unsigned NOT NULL,
    submitted_by_user_id bigint(20) unsigned NOT NULL,
    instrument_id        bigint(20) unsigned NOT NULL,
    role_in_session      varchar(20) NOT NULL,
    responses_json       longtext DEFAULT NULL,
    status               varchar(20) NOT NULL DEFAULT 'draft',
    submitted_at         datetime DEFAULT NULL,
    created_at           datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (submission_id),
    UNIQUE KEY submission_uuid (submission_uuid),
    UNIQUE KEY uq_session_role (rp_session_id, role_in_session),
    KEY idx_rp_session (rp_session_id),
    KEY idx_user (submitted_by_user_id)
) {$charset_collate};
```

**`role_in_session`:** `supervisor` (Mentor fills RP Notes) or `supervisee` (Teacher fills Action Plan). Unique constraint on `(rp_session_id, role_in_session)` prevents duplicate submissions per role.

### 3.3 New Table: `hl_classroom_visit`

```sql
CREATE TABLE {$prefix}hl_classroom_visit (
    classroom_visit_id   bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    classroom_visit_uuid char(36) NOT NULL,
    cycle_id             bigint(20) unsigned NOT NULL,
    leader_enrollment_id bigint(20) unsigned NOT NULL,
    teacher_enrollment_id bigint(20) unsigned NOT NULL,
    classroom_id         bigint(20) unsigned DEFAULT NULL,
    visit_number         tinyint unsigned NOT NULL DEFAULT 1,
    status               varchar(20) NOT NULL DEFAULT 'pending',
    visit_date           datetime DEFAULT NULL,
    notes                text DEFAULT NULL,
    created_at           datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (classroom_visit_id),
    UNIQUE KEY classroom_visit_uuid (classroom_visit_uuid),
    KEY idx_cycle (cycle_id),
    KEY idx_leader (leader_enrollment_id),
    KEY idx_teacher (teacher_enrollment_id)
) {$charset_collate};
```

**Status lifecycle:** `pending` (component exists, leader hasn't visited yet) → `completed` (leader submitted the form). Visits are recorded retroactively — leaders visit the classroom, then file the form. No scheduling step needed.

### 3.4 New Table: `hl_classroom_visit_submission`

```sql
CREATE TABLE {$prefix}hl_classroom_visit_submission (
    submission_id        bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    submission_uuid      char(36) NOT NULL,
    classroom_visit_id   bigint(20) unsigned NOT NULL,
    submitted_by_user_id bigint(20) unsigned NOT NULL,
    instrument_id        bigint(20) unsigned NOT NULL,
    role_in_visit        varchar(20) NOT NULL,
    responses_json       longtext DEFAULT NULL,
    status               varchar(20) NOT NULL DEFAULT 'draft',
    submitted_at         datetime DEFAULT NULL,
    created_at           datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (submission_id),
    UNIQUE KEY submission_uuid (submission_uuid),
    UNIQUE KEY uq_visit_role (classroom_visit_id, role_in_visit),
    KEY idx_visit (classroom_visit_id),
    KEY idx_user (submitted_by_user_id)
) {$charset_collate};
```

**`role_in_visit`:** `observer` (School Leader fills Classroom Visit Form) or `self_reflector` (Teacher fills Self-Reflection). Unique constraint prevents duplicate submissions per role.

### 3.5 New Table: `hl_coaching_session_submission`

```sql
CREATE TABLE {$prefix}hl_coaching_session_submission (
    submission_id        bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    submission_uuid      char(36) NOT NULL,
    session_id           bigint(20) unsigned NOT NULL,
    submitted_by_user_id bigint(20) unsigned NOT NULL,
    instrument_id        bigint(20) unsigned NOT NULL,
    role_in_session      varchar(20) NOT NULL,
    responses_json       longtext DEFAULT NULL,
    status               varchar(20) NOT NULL DEFAULT 'draft',
    submitted_at         datetime DEFAULT NULL,
    created_at           datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (submission_id),
    UNIQUE KEY submission_uuid (submission_uuid),
    UNIQUE KEY uq_session_role (session_id, role_in_session),
    KEY idx_session (session_id),
    KEY idx_user (submitted_by_user_id)
) {$charset_collate};
```

### 3.6 Schema Migration: Component Type ENUM Extension

The `hl_component.component_type` column must be extended to include 3 new values. Since `dbDelta()` cannot modify ENUM columns, this requires an explicit ALTER TABLE migration.

**Implementation:** Add a new migration function `migrate_add_event_component_types()` called from `maybe_upgrade()`. Bump `$current_revision` from 21 to 22.

```php
private function migrate_add_event_component_types() {
    global $wpdb;
    $table = $wpdb->prefix . 'hl_component';
    $wpdb->query("ALTER TABLE {$table} MODIFY component_type
        ENUM('learndash_course','teacher_self_assessment','child_assessment',
             'coaching_session_attendance','observation',
             'reflective_practice_session','classroom_visit','self_reflection')
        NOT NULL DEFAULT 'learndash_course'");
}
```

Full ENUM after migration: `learndash_course`, `teacher_self_assessment`, `child_assessment`, `coaching_session_attendance`, `observation`, `reflective_practice_session`, `classroom_visit`, `self_reflection`.

---

## 4. Instruments (6 Total)

### Instrument Storage

All 6 instruments are stored in the existing `hl_teacher_assessment_instrument` table, which has a flexible `sections` JSON column capable of describing arbitrary form structures. The `instrument_key` column distinguishes these from existing teacher self-assessment instruments.

Custom PHP renderers handle the complex rendering (auto-populated sections, conditional dropdowns, side-by-side views). The instrument row provides the base field definitions (domains, skills, indicator lists) — the configurable parts. Auto-populated sections are rendered by custom code, not from the instrument JSON.

**Instrument keys:** `coaching_rp_notes`, `mentoring_rp_notes`, `coaching_action_plan`, `mentoring_action_plan`, `classroom_visit_form`, `self_reflection_form`.

### Component-to-Session Matching

Components store `{"session_number": N}` or `{"visit_number": N}` in their `external_ref` JSON. When a form is submitted for session/visit number N, the service finds the component with matching `external_ref` number and updates its `hl_component_state`. This is the same pattern used by existing component types (e.g., `{"course_id": 123}`).

### 4.1 Instrument 1: Coaching RP Notes

- **Key:** `coaching_rp_notes`
- **Context:** Coach fills about Mentor during Coaching Session
- **Storage:** `hl_coaching_session_submission` with `role_in_session = 'supervisor'`
- **Visibility:** Coach only (supervisor-only form)

**Sections:**
1. **Session Information** (auto-populated, read-only)
   - Coach Name, Mentor Name, Date, Session #, Mentor's Current Course/Cycle
2. **Personal Notes** (rich text, supervisor-only — never shown to supervisee)
3. **Session Prep Notes** (auto-populated, read-only)
   - Mentor's pathway progress (components completed/total)
   - Previous Action Plan submissions (scrollable list of past coaching action plans, newest first)
   - Recent classroom visit data from mentor's most recent RP teacher
4. **Classroom Visit & Self-Reflection Review** (auto-populated, read-only)
   - Table of responses from the most recent classroom visit for the mentor's latest RP session teacher
5. **RP Session Notes** (editable fields)
   - Successes (rich text)
   - Challenges / Areas of Growth (rich text)
   - Supports Needed (rich text)
   - Next Steps (rich text)
   - Next Session Date (date picker)
6. **RP Steps Guide** (inline expandable accordion, non-editable)
   - Description, Feelings, Evaluation, Analysis, Conclusion, Action Plan conversation prompts

### 4.2 Instrument 2: Mentoring RP Notes

- **Key:** `mentoring_rp_notes`
- **Context:** Mentor fills about Teacher during RP Session
- **Storage:** `hl_rp_session_submission` with `role_in_session = 'supervisor'`
- **Visibility:** Mentor only (supervisor-only form)

**Sections:** Same structure as Coaching RP Notes, with label changes:
- "Coach Name" → "Mentor Name"
- "Mentor Name" → "Teacher Name"
- "Mentor's Current Course" → "Teacher's Current Course"
- Session Prep pulls teacher's progress and previous mentoring action plans
- Classroom Visit Review pulls the most recent classroom visit for this specific teacher

### 4.3 Instrument 3: Coaching Action Plan & Results

- **Key:** `coaching_action_plan`
- **Context:** Mentor fills, Coach supervises, during Coaching Session
- **Storage:** `hl_coaching_session_submission` with `role_in_session = 'supervisee'`
- **Visibility:** Both Coach and Mentor can view/edit

**Sections:**
1. **Planning**
   - Domain (single select dropdown — 6 domains, same for all roles for now)
   - Skills/Strategy (multi-select, conditional on selected domain — JS-driven)
   - Describe HOW you will practice (narrative textarea)
   - WHAT behaviors will you track (narrative textarea)
2. **Results**
   - How has your practice gone? (narrative textarea)
   - Degree of success (Likert scale 1-5: Not at all Successful → Extremely Successful)
   - Observations of impact on students (narrative textarea)
   - What you learned (narrative textarea)
   - What you're still wondering (narrative textarea)

### 4.4 Instrument 4: Mentoring Action Plan & Results

- **Key:** `mentoring_action_plan`
- **Context:** Teacher fills, Mentor supervises, during RP Session
- **Storage:** `hl_rp_session_submission` with `role_in_session = 'supervisee'`
- **Visibility:** Both Mentor and Teacher can view/edit

**Sections:** Identical structure to Coaching Action Plan & Results. Same 6 domains and skills.

### 4.5 Instrument 5: Classroom Visit Form

- **Key:** `classroom_visit_form`
- **Context:** School Leader fills after observing Teacher's class
- **Storage:** `hl_classroom_visit_submission` with `role_in_visit = 'observer'`
- **Visibility:** Leader who submitted + staff

**Sections:**
1. **Header** (auto-populated)
   - Center/School, Teacher Name, Date, Classroom Visitor Name, Age Group
2. **Context** (checkboxes, multi-select)
   - Free Play, Formal Group Activities, Transition, Routine
3. **Domain/Indicator Assessment** (6 domains, each with multiple indicators)
   - Each indicator: Yes/No toggle + required Description textarea if Yes
   - Domains:
     1. Emotional Climate & Teacher Presence (6 indicators)
     2. ECSEL Language & Emotional Communication
     3. Co-Regulation & Emotional Support
     4. Social Skills, Empathy & Inclusion
     5. Use of Developmentally-Appropriate ECSEL Tools
     6. Integration into Daily Learning

### 4.6 Instrument 6: Self-Reflection Form

- **Key:** `self_reflection_form`
- **Context:** Teacher reflects on own classroom practice
- **Storage:** `hl_classroom_visit_submission` with `role_in_visit = 'self_reflector'`
- **Visibility:** Teacher who submitted + mentor (for RP session prep) + staff

Mentor visibility rationale: The mentor supervises the teacher and needs to see self-reflections during RP session prep (Section 3 of the RP Notes form pulls this data). Access is informational only.

**Sections:** Same structure as Classroom Visit Form with self-assessment framing:
- Indicator labels change from "Teacher demonstrated..." to "I demonstrated..."
- Header auto-populates with teacher's own info

### Domain & Skills Reference (All Instruments)

Used by Action Plan instruments (3 & 4) for domain dropdown and conditional skills multi-select. Stored in the instrument's `sections` JSON for future admin configurability.

| Domain | Skills/Strategies |
|--------|-------------------|
| Emotional Climate & Teacher Presence | Demonstrate calm, emotionally regulated presence; Model attentive, engaged, and supportive behavior |
| ECSEL Language & Emotional Communication | Consistently use emotion language to label/validate feelings; Use Causal Talk (CT) to connect emotions, behavior, experiences |
| Co-Regulation & Emotional Support | Use Causal Talk in Emotional Experience (CTEE) for heightened emotions; Guide children toward regulation before problem-solving |
| Social Skills, Empathy & Inclusion | Model/encourage empathy, cooperation, respect; Classroom interactions reflect inclusion and respect; Guide children through conflict resolution steps |
| Use of Developmentally-Appropriate ECSEL Tools | ECSEL tools visible, accessible, intentionally placed; Use tools appropriately for emotion knowledge/conflict resolution |
| Integration into Daily Learning | Embed tools, language, strategies in play/routines/learning; Use emotional moments as learning opportunities |

---

## 5. Services

### 5.1 HL_RP_Session_Service (New)

**File:** `includes/services/class-hl-rp-session-service.php`

**Methods:**
- `create_session($data)` — create RP session linking mentor + teacher enrollments
- `get_session($rp_session_id)` — single session with joined names
- `get_by_cycle($cycle_id)` — all RP sessions for a cycle
- `get_by_mentor($mentor_enrollment_id)` — sessions where user is mentor
- `get_by_teacher($teacher_enrollment_id)` — sessions where user is teacher
- `get_teachers_for_mentor($mentor_enrollment_id)` — team members (via `hl_team_membership` join: find teams where user is mentor, return non-mentor members)
- `transition_status($rp_session_id, $new_status)` — status lifecycle
- `submit_form($rp_session_id, $user_id, $instrument_id, $role, $responses_json)` — save/submit form (upserts on unique constraint)
- `get_submissions($rp_session_id)` — all submissions for a session
- `get_previous_action_plans($teacher_enrollment_id, $cycle_id)` — ordered list (newest first) of past action plan submissions for scrollable list display
- `update_component_state($enrollment_id, $cycle_id, $session_number)` — finds component with `external_ref.session_number` matching, marks `hl_component_state` complete

### 5.2 HL_Classroom_Visit_Service (New)

**File:** `includes/services/class-hl-classroom-visit-service.php`

**Methods:**
- `create_visit($data)` — create classroom visit linking leader + teacher enrollments
- `get_visit($classroom_visit_id)` — single visit with joined names
- `get_by_cycle($cycle_id)` — all visits for a cycle
- `get_by_leader($leader_enrollment_id)` — visits where user is leader
- `get_by_teacher($teacher_enrollment_id)` — visits where user is teacher
- `get_teachers_for_leader($leader_enrollment_id, $cycle_id)` — Join path: leader enrollment → `hl_enrollment.user_id` → `hl_enrollment` rows with same `cycle_id` → filter by `hl_cycle_school` to match leader's school(s) via `hl_enrollment` school scope. Returns teacher enrollments in the leader's school(s) within the same cycle.
- `mark_completed($classroom_visit_id)` — set status to `completed`
- `submit_form($classroom_visit_id, $user_id, $instrument_id, $role, $responses_json)` — save/submit form (upserts on unique constraint)
- `get_submissions($classroom_visit_id)` — all submissions for a visit
- `get_most_recent_for_teacher($teacher_enrollment_id, $cycle_id)` — latest visit data for session prep auto-population
- `update_component_state($enrollment_id, $cycle_id, $visit_number)` — finds component with `external_ref.visit_number` matching, marks `hl_component_state` complete

### 5.3 HL_Session_Prep_Service (New)

**File:** `includes/services/class-hl-session-prep-service.php`

Shared helper for auto-populated sections in RP Notes forms (both coaching and mentoring contexts). Uses eager-loading JOINs to avoid N+1 queries.

**Methods:**
- `get_supervisee_progress($enrollment_id, $cycle_id)` — pathway components completed/total, current course name (single JOIN query across `hl_component` + `hl_component_state`)
- `get_previous_action_plans($enrollment_id, $cycle_id, $context)` — ordered list of past action plan submissions for scrollable list. Context = 'coaching' (queries `hl_coaching_session_submission`) or 'mentoring' (queries `hl_rp_session_submission`).
- `get_classroom_visit_review($teacher_enrollment_id, $cycle_id)` — most recent classroom visit submissions (observer + self-reflector) for the prep section. Single query JOINing `hl_classroom_visit` + `hl_classroom_visit_submission`.
- `get_classroom_visit_for_mentor_context($mentor_enrollment_id, $cycle_id)` — Single query: JOIN `hl_rp_session` (most recent by session_date) → get teacher_enrollment_id → JOIN `hl_classroom_visit` + submissions for that teacher. Returns combined data in one round-trip.

### 5.4 HL_Coaching_Service (Modified)

**File:** `includes/services/class-hl-coaching-service.php`

**New methods added:**
- `submit_form($session_id, $user_id, $instrument_id, $role, $responses_json)` — save/submit coaching form (Action Plan or RP Notes)
- `get_submissions($session_id)` — all form submissions for a coaching session
- `get_previous_coaching_action_plans($mentor_enrollment_id, $cycle_id)` — past action plans for session prep

**Modified methods:**
- `update_coaching_component_state()` — existing behavior preserved (marks complete on attended status)

---

## 6. Frontend Changes

### 6.1 Component Page Renderers

**File:** `includes/frontend/class-hl-frontend-component-page.php`

New renderer branches for component types. Renderer classes placed directly in `includes/frontend/` to match existing codebase pattern (one class per shortcode/renderer in that directory).

#### `reflective_practice_session` renderer
- **Teacher view:** Shows list of RP sessions with their mentor. Each row: Mentor name, session #, status, date. Click opens Action Plan & Results form. If form already submitted, shows read-only summary.
- **Mentor view:** Shows list of RP sessions per teacher in their team. Each teacher row expandable to show session # and status. Click opens RP Notes form with auto-populated prep sections + Action Plan visible alongside (side-by-side on desktop, stacked on mobile). Draft save supported.

#### `classroom_visit` renderer
- **Leader view:** Shows list of teachers in their school(s) with pending/completed visits. Each row: Teacher name, classroom, visit #, status. Click opens Classroom Visit Form with auto-populated header.

#### `self_reflection` renderer
- **Teacher view:** Shows list of self-reflections (one per visit_number). Each row: visit #, status, date. Click opens Self-Reflection Form. If a corresponding classroom visit exists by a leader, shows "Your leader has visited your classroom" indicator (informational only, no state coupling).

### 6.2 My Coaching Page Enhancement

**File:** `includes/frontend/class-hl-frontend-my-coaching.php`

**Changes:**
- Schedule form enhanced with a simple calendar date picker widget (month view, click a date to select)
- When clicking an existing coaching session, show the Action Plan & Results form inline (for mentors)
- Past sessions with status='attended' show submitted forms as read-only

### 6.3 Coaching Hub Frontend Enhancement

**File:** `includes/frontend/class-hl-frontend-coaching-hub.php`

**Changes:**
- New "Coaches" section: grid of coach cards (avatar, name, email)
- Calendar-style view toggle for sessions (simple month grid showing session dots per date)

---

## 7. Admin Changes

### 7.1 Coaching Hub Admin — Coaches Tab

**File:** `includes/admin/class-hl-admin-coaching.php`

**New tab: "Coaches"**
- List all WP users with Coach role: name, email, assigned cycles/schools count
- "Add Coach" button → form: name, email, creates WP user with Coach role (or assigns Coach role to existing user)
- Edit coach: view/edit user details, see coach assignments summary
- Remove coach role (doesn't delete user, just removes Coach role)

### 7.2 Cycle Editor Coaching Tab Enhancement

**File:** `includes/admin/class-hl-admin-cycles.php`

**Coaching tab additions:**
- Existing: coaching sessions + assignments
- New: RP Sessions subtab (list of RP sessions in this cycle with mentor/teacher/status)
- New: Classroom Visits subtab (list of visits in this cycle with leader/teacher/status)

---

## 8. CLI Rebuild: ELCPB Year 2

**Command:** `wp hl-core setup-elcpb-y2 [--clean]`

**Rebuild process:**
1. `--clean` removes existing Y2 cycle, pathways, components (existing behavior)
2. Creates Cycle `ELCPB-Y2-2026` linked to Partnership `ELCPB-B2E-2025`
3. Creates all 8 pathways with components matching the Final B2E-ELCPB New Pathways Excel exactly

### Pathway Components (from Excel)

**Teacher Phase 1** (17 visible components — CO rows excluded per "Shown=N"):
TSA Pre, CA Pre, TC0, TC1, SR#1, RP#1, TC2, SR#2, RP#2, TC3, SR#3, RP#3, TC4, SR#4, RP#4, CA Post, TSA Post

**Teacher Phase 2** (16 visible components):
TSA Pre, CA Pre, TC5, SR#1, RP#1, TC6, SR#2, RP#2, TC7, SR#3, RP#3, TC8, SR#4, RP#4, CA Post, TSA Post

**Mentor Phase 1** (19 components):
TSA Pre, CA Pre, TC0, TC1, Coaching#1, MC1, RP#1, TC2, Coaching#2, RP#2, TC3, Coaching#3, MC2, RP#3, TC4, Coaching#4, RP#4, CA Post, TSA Post

**Mentor Phase 2** (18 components):
TSA Pre, CA Pre, TC5, Coaching#1, MC3, RP#1, TC6, Coaching#2, RP#2, TC7, Coaching#3, MC4, RP#3, TC8, Coaching#4, RP#4, CA Post, TSA Post

**Mentor Transition** (18 components):
TSA Pre, CA Pre, TC5, Coaching#1, MC1, RP#1, TC6, Coaching#2, RP#2, TC7, Coaching#3, MC2, RP#3, TC8, Coaching#4, RP#4, CA Post, TSA Post

**Mentor Completion** (4 components):
TSA Pre, MC3, MC4, TSA Post

**Streamlined Phase 1** (11 components):
TC0, TC1(S), MC1(S), CV#1, TC2(S), CV#2, TC3(S), CV#3, TC4(S), MC2(S), CV#4

**Streamlined Phase 2** (10 components):
TC5(S), MC3(S), CV#1, TC6(S), CV#2, TC7(S), CV#3, TC8(S), MC4(S), CV#4

### Component Type Mapping

| Excel Item | Component Type | `external_ref` | Notes |
|-----------|---------------|----------------|-------|
| TC0-TC8, MC1-MC4 | `learndash_course` | `{"course_id": N}` | Links to LD course ID |
| TC0(S)-TC8(S), MC1(S)-MC4(S) | `learndash_course` | `{"course_id": N}` | Links to streamlined LD course ID |
| Teacher Self-Assessment Pre/Post | `teacher_self_assessment` | `{"teacher_instrument_id": N, "phase": "pre/post"}` | Existing type |
| Child Assessment Pre/Post | `child_assessment` | `{"phase": "pre/post"}` | Existing type |
| Coaching Session #N | `coaching_session_attendance` | `{"session_number": N}` | Existing type, enhanced |
| Reflective Practice Session #N | `reflective_practice_session` | `{"session_number": N}` | New type |
| Self-Reflection #N | `self_reflection` | `{"visit_number": N}` | New type |
| Classroom Visit #N | `classroom_visit` | `{"visit_number": N}` | New type |
| Classroom Observation (Teacher, Shown=N) | *Not created as component* | — | Leader's responsibility only |

### Prerequisite Rules (from Excel)

- **Course chains:** Courses blocked by previous course only (e.g., TC2 requires TC1)
- **First course in each pathway:** Blocked by TSA Pre (except Streamlined which has no prereqs)
- **All SR/RP/Coaching/CV:** No prerequisite (release-date gated only)
- **TSA Post / CA Post:** No prerequisite (changed per client request)
- **Streamlined pathways:** ALL items have no prerequisites

### Hidden Components

The Excel column "Shown in Pathway?" = N for Classroom Observations in Teacher pathways. These rows are NOT created as components in the Teacher pathway. The Classroom Observation is only the leader's responsibility and appears as a `classroom_visit` component in the Streamlined pathway.

---

## 9. Instrument Seeding

The CLI rebuild seeds the 6 new instruments into `hl_teacher_assessment_instrument` with their form structure:

1. **coaching_rp_notes** — Coaching RP Notes instrument
2. **mentoring_rp_notes** — Mentoring RP Notes instrument
3. **coaching_action_plan** — Coaching Action Plan & Results instrument
4. **mentoring_action_plan** — Mentoring Action Plan & Results instrument
5. **classroom_visit_form** — Classroom Visit Form instrument
6. **self_reflection_form** — Self-Reflection Form instrument

Each instrument stores its structure in the `sections` JSON column (matching the existing pattern — note: the column is named `sections`, not `sections_json`). Custom renderers read the base field definitions from this JSON but handle the complex rendering (auto-populated sections, conditional dropdowns) in PHP.

The domain/skills mapping is stored as part of the Action Plan instruments' `sections` JSON, making it admin-configurable later.

---

## 10. Coaching Hub — Coaches Management

### Admin Side

**New tab in Coaching Hub admin page: "Coaches"**

- **List view:** Table of all Coach-role WP users
  - Columns: Name, Email, Assigned Cycles, Assigned Schools/Teams, Status
  - Actions: Edit, Remove Coach Role
- **Add Coach:** Form with name, email fields
  - If email matches existing WP user → assigns Coach role
  - If new email → creates WP user with Coach role, sends password reset email
- **Edit Coach:** View assignments, update user info

### Frontend Side

**Enhanced `[hl_coaching_hub]` shortcode:**

- **Coaches section:** Card grid showing all coaches (avatar, name, email, assignment count)
- **Sessions section:** Existing session table + optional calendar view toggle
  - Calendar: simple month grid, each day shows colored dots for sessions (green=attended, blue=scheduled, red=missed, gray=cancelled)

**Enhanced `[hl_my_coaching]` shortcode:**

- **Schedule form:** Simple calendar date picker widget (month view grid)
  - Click a day to select date
  - Time picker dropdown (30-min increments)
  - Auto-fills coach name from CoachAssignmentService
  - Auto-suggests session title from next coaching component
  - Demo coach: Lauren Orf (lorf@housmanlearning.com) — seeded by CLI as placeholder
- **Session detail:** When viewing a past attended session, shows submitted forms (Action Plan + RP Notes if accessible)

---

## 11. File Inventory

### New Files

| File | Purpose |
|------|---------|
| `includes/services/class-hl-rp-session-service.php` | RP Session CRUD + submissions |
| `includes/services/class-hl-classroom-visit-service.php` | Classroom Visit CRUD + submissions |
| `includes/services/class-hl-session-prep-service.php` | Auto-populated prep data helper |
| `includes/frontend/class-hl-frontend-rp-session.php` | Frontend RP session form renderer |
| `includes/frontend/class-hl-frontend-classroom-visit.php` | Frontend classroom visit form renderer |
| `includes/frontend/class-hl-frontend-self-reflection.php` | Frontend self-reflection form renderer |
| `includes/frontend/class-hl-frontend-action-plan.php` | Frontend action plan form renderer |
| `includes/frontend/class-hl-frontend-rp-notes.php` | Frontend RP notes form renderer |
| `includes/cli/class-hl-cli-setup-elcpb-y2-v2.php` | Rebuilt Y2 setup CLI |

### Modified Files

| File | Changes |
|------|---------|
| `includes/class-hl-installer.php` | 5 new tables in `get_schema()`, revision bump to 22, `migrate_add_event_component_types()` in `maybe_upgrade()` |
| `includes/services/class-hl-coaching-service.php` | Add `submit_form()`, `get_submissions()`, `get_previous_coaching_action_plans()` methods |
| `includes/frontend/class-hl-frontend-component-page.php` | New renderer branches for 3 component types |
| `includes/frontend/class-hl-frontend-my-coaching.php` | Calendar widget, inline Action Plan form |
| `includes/frontend/class-hl-frontend-coaching-hub.php` | Coaches section, calendar view |
| `includes/admin/class-hl-admin-coaching.php` | Coaches tab |
| `includes/admin/class-hl-admin-cycles.php` | RP Sessions + Classroom Visits subtabs |
| `hl-core.php` | Register new services, load new files |

---

## 12. Session Execution Plan

### Session Breakdown for Implementation

**Session 1: Database & Core Infrastructure** (Fast Mode)
- Create 5 new DB tables in `get_schema()`
- Add migration for component_type ENUM extension in `maybe_upgrade()`
- Create HL_RP_Session_Service
- Create HL_Classroom_Visit_Service
- Create HL_Session_Prep_Service
- Extend HL_Coaching_Service with submission methods
- Register all new services in hl-core.php

**Session 2: Instruments & Form Renderers** (Fast Mode, can run in parallel with Session 3)
- Seed 6 new instruments in `hl_teacher_assessment_instrument` with `sections` JSON
- Build Action Plan renderer (shared by coaching + mentoring contexts)
- Build RP Notes renderer (shared by coaching + mentoring contexts)
- Build Classroom Visit Form renderer
- Build Self-Reflection Form renderer
- All renderers: auto-populated fields, draft save, submit, read-only view

**Session 3: Frontend Pages** (Fast Mode, can run in parallel with Session 2)
- Component Page: new renderer branches for 3 new component types
- My Coaching: calendar widget, inline Action Plan form
- Coaching Hub frontend: Coaches section, calendar view

**Session 4: Admin & CLI** (Fast Mode)
- Coaching Hub admin: Coaches tab (list, add, edit, remove)
- Cycle Editor: RP Sessions + Classroom Visits subtabs
- Rebuild setup-elcpb-y2 CLI with correct pathway structure
- Seed demo coach Lauren Orf

**Session 5: Integration & Testing** (Normal Mode)
- Verify all component types render correctly in Program Page
- Test cross-pathway event correlation
- Verify auto-populated fields pull correct data
- Test draft save / submit lifecycle
- Verify completion state updates

### Parallel Execution

Sessions 2 and 3 can run simultaneously in separate terminals — they're independent (renderers vs page controllers). Session 1 must complete first (provides DB + services). Session 4 depends on Sessions 2-3. Session 5 depends on all.

```
Session 1 (DB + Services)
    |
    ├── Session 2 (Instruments + Renderers) ──┐
    │                                          ├── Session 4 (Admin + CLI)
    └── Session 3 (Frontend Pages) ───────────┘          |
                                                    Session 5 (Integration)
```

### Fast Mode Recommendations

| Session | Fast Mode? | Reason |
|---------|-----------|--------|
| 1 | Yes | DB schema + services are high-volume boilerplate |
| 2 | Yes | Form renderers are substantial PHP/HTML |
| 3 | Yes | Frontend page modifications are substantial |
| 4 | Yes | Admin pages + CLI are high-volume |
| 5 | No | Testing/integration needs careful verification |
