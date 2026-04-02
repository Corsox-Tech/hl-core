# Import Module Redesign — Design Spec

**Date:** 2026-04-01
**Status:** Draft
**Scope:** Redesign the HL Core import system: move from Settings to Cycle Editor, collapse 4 import types to 2 (Participants + Children), auto-create classrooms/teams/teaching assignments/coach assignments from Participant import.

---

## Problem

The current import module (Settings → Import) has 4 separate CSV upload types (Participants, Children, Classrooms, Teaching Assignments) that must be run in sequence. It requires the admin to pre-create classrooms before teaching assignments, select the correct cycle from a global dropdown (error-prone), and doesn't handle teams, mentor assignments, or coach assignments at all. Real-world rosters from clients (e.g., ELCPB Year 2) include all this data in a single spreadsheet.

## Solution

Redesign the import as a **Cycle Editor tab** with **2 import types**:
1. **Participants** — creates enrollments, auto-creates classrooms + teaching assignments + teams + team membership + coach assignments, assigns pathways
2. **Children** — creates child records with required classroom assignment

The cycle context is implicit (no cycle dropdown), and all entity validation is scoped to the cycle's Partnership.

---

## Architecture

### Location

**Import tab inside Cycle Editor** — accessed via `wp-admin/admin.php?page=hl-cycles&action=edit&id={cycle_id}&tab=import`

The old Settings → Import tab is **removed** and replaced with a notice pointing admins to import via their Cycle.

### Files

| File | Purpose | Action |
|------|---------|--------|
| `includes/admin/class-hl-admin-imports.php` | Main admin UI | **Rewrite** — move from Settings tab to Cycle Editor tab |
| `includes/services/class-hl-import-service.php` | Monolithic import logic (1,800 lines) | **Replace** with thin orchestrator |
| `includes/services/class-hl-import-participant-handler.php` | Participant validation + commit | **New** |
| `includes/services/class-hl-import-children-handler.php` | Children validation + commit | **New** |
| `assets/js/admin-import-wizard.js` | Wizard UI controller | **Update** — new fields, helpers, cycle context |
| `assets/css/admin-import-wizard.css` | Wizard styling | **Update** — minor |
| `includes/admin/class-hl-admin-cycles.php` | Cycle Editor | **Update** — add Import tab |

### Integration

- `HL_Import_Service` becomes a thin orchestrator: CSV parsing, shared utilities, delegates to handlers
- Handlers use existing services: `HL_Team_Service`, `HL_Pathway_Assignment_Service`, `HL_Coach_Assignment_Service`, `HL_Classroom_Service`, `HL_Audit_Service`
- `hl_import_run` table unchanged — stores run history per cycle
- AJAX endpoints unchanged in structure, updated in logic

---

## Import Type 1: Participants

### Required Columns

| Column | Required | Description |
|--------|----------|-------------|
| `email` | **Yes** | WordPress user email. Creates user if doesn't exist. |
| `role` | **Yes** | B2E Role: Teacher, Mentor, School Leader, District Leader |
| `school` | **Yes*** | School name. Must match existing school linked to this cycle's Partnership. *Optional for District Leaders (they may not have a school). |

### Optional Columns

| Column | Description |
|--------|-------------|
| `first_name` | User first name. Warning if missing (user created without). |
| `last_name` | User last name. Warning if missing. |
| `classroom` | Semicolon-separated classroom names (e.g., `Room A; Room B`). Auto-creates classrooms at the participant's school if they don't exist. Auto-creates teaching assignment for each. |
| `age_group` | Classroom age band: infant, toddler, preschool, k2, mixed. Applied to auto-created classrooms. If participant has multiple classrooms (semicolon-separated), age_group applies to ALL of them. If different classrooms need different age bands, import them in separate rows with the same email (dedup will UPDATE the enrollment, CREATE new classroom + assignment). |
| `is_primary_teacher` | Y/N. Sets `is_lead_teacher` flag on teaching assignment. Default: N. |
| `team` | Team name (e.g., `Team 1`). Auto-creates team at school+cycle if doesn't exist. Assigns participant as `member` (Teachers) or `mentor` (Mentors). |
| `assigned_mentor` | Mentor's email. Validates mentor exists in same team. Used for documentation/validation — the team membership_type already captures the relationship. |
| `assigned_coach` | Coach's email. Creates `hl_coach_assignment` with `scope_type=enrollment` for Mentors. Validates coach user exists in WordPress. |
| `pathway` | Pathway name or code. Must match a pathway in this cycle. Creates explicit assignment via `HL_Pathway_Assignment_Service`. |

### Validation Rules

1. **Email** — required, valid format, no duplicates within file
2. **Role** — must be one of: Teacher, Mentor, School Leader, District Leader (synonym mapping preserved from current system)
3. **School** — must exist in `hl_orgunit` (type=school) AND be linked to this cycle's Partnership via `hl_cycle_school` or the Partnership's associated cycles. If role is District Leader and school doesn't match, flag as warning (not error) with message "District Leaders typically don't belong to a school"
4. **Classroom** — if provided, validated against school. Auto-created if doesn't exist. Split by semicolon.
5. **Age Group** — if provided, must be one of: infant, toddler, preschool, k2, mixed
6. **Team** — if provided, auto-created at school+cycle if doesn't exist. Enforce: 1 team per enrollment per cycle (if participant already in a team in this cycle, flag as error). Max 2 mentors per team (soft constraint, flag as warning).
7. **Assigned Mentor** — if provided, validate email exists in file or in system as an active enrollment with Mentor role in this cycle
8. **Assigned Coach** — if provided, validate email exists as a WordPress user
9. **Pathway** — if provided, must match `pathway_name` or `pathway_code` in this cycle. If not provided, `sync_role_defaults` runs after commit.

### Processing Order

The commit must process rows in dependency order:
1. **Create WordPress users** (if email doesn't exist) — all roles
2. **Create enrollments** — all roles
3. **Create classrooms** — from classroom column values
4. **Create teaching assignments** — link enrollments to classrooms
5. **Create teams** — from team column values (deduplicated by school+team_name)
6. **Create team memberships** — Mentors as `membership_type=mentor`, Teachers as `membership_type=member`
7. **Create coach assignments** — from assigned_coach column (`scope_type=enrollment`, `scope_id=mentor_enrollment_id`)
8. **Assign pathways** — explicit from CSV or `sync_role_defaults` for unassigned

### Status Assignment

| Status | Condition |
|--------|-----------|
| `CREATE` | Email not in system, or user exists but not enrolled in this cycle |
| `UPDATE` | User enrolled in cycle but role/school/team differs |
| `SKIP` | User enrolled with identical data |
| `ERROR` | Missing required field, school not found, invalid role |
| `WARNING` | Missing first/last name, District Leader with unrecognized school |

### Helper Dropdowns (Shown in UI)

Before file upload, the import page displays reference helpers scoped to this cycle:
- **Roles:** Teacher, Mentor, School Leader, District Leader
- **Schools:** List of all schools linked to this cycle's Partnership (name + code)
- **Pathways:** List of all active pathways in this cycle (name + code + target_roles)
- **Existing Teams:** List of teams already created in this cycle (name + school)

---

## Import Type 2: Children

### Required Columns

| Column | Required | Description |
|--------|----------|-------------|
| `first_name` | **Yes** | Child first name |
| `last_name` | **Yes** | Child last name |
| `classroom` | **Yes** | Classroom name. Must match existing classroom (created via Participant import or admin UI). Validated against cycle's schools. |

### Optional Columns

| Column | Description |
|--------|-------------|
| `school` | School name. If omitted, inferred from classroom (if classroom name is unique across schools). If ambiguous, error. |
| `date_of_birth` | DOB for age-band calculations and fingerprint matching. |
| `internal_child_id` | External system ID. Used in fingerprint for dedup. |
| `ethnicity` | Demographic data. |

### Validation Rules

1. **first_name + last_name** — at least one required
2. **classroom** — must match an existing classroom in a school linked to this cycle's Partnership
3. **school** — if provided, classroom must exist at that school. If omitted, classroom name must be unambiguous across Partnership schools (error if same name exists at multiple schools).
4. **Deduplication** — uses existing fingerprint matching (`school_id + DOB + internal_child_id + names`). Match → UPDATE, no match → CREATE.

### Processing Order

1. **Resolve school** from classroom (or explicit school column)
2. **Compute fingerprint** for dedup matching
3. **Create/update child records**
4. **Create classroom assignments** (`hl_child_classroom_current`)

---

## Shared Behavior

### UI Flow (3-Step Wizard — Preserved)

**Step 1: Upload**
- Import type selector: Participants or Children
- Helper section showing valid values (roles, schools, pathways, teams) — scoped to this cycle
- File upload (CSV, max 5,000 rows, max 2MB)
- Column hints for selected type

**Step 2: Preview**
- Summary cards: CREATE / UPDATE / SKIP / WARNING / ERROR counts
- Sortable table with per-row status, proposed action, validation messages
- Row-level checkboxes for selection (ERROR rows non-selectable)
- Bulk actions: Select All CREATE, Select All UPDATE, Deselect All
- **Warnings are selectable** but highlighted — admin decides whether to proceed
- Unmapped column warnings

**Step 3: Results**
- Final counts: created, updated, skipped, errors
- Error table with row details
- Download error report CSV
- "New Import" button

### All-or-Nothing Commit

Per user requirement: if ANY selected row fails during commit, the entire transaction rolls back. No partial imports. The error report shows what failed and why, so the admin can fix the CSV and re-upload.

This differs from the current system which continues processing on individual row errors. The new behavior uses `$wpdb->query('START TRANSACTION')` and `ROLLBACK` on any failure.

### Partnership-Scoped Validation

All entity lookups are scoped to the cycle's Partnership:
- Schools: must be linked to the Partnership (via `hl_cycle_school` for any cycle in the Partnership, or direct Partnership→Cycle→School chain)
- Pathways: must belong to this cycle
- Teams: must belong to this cycle
- Classrooms: must belong to a school in this Partnership

### Audit Logging

All creates/updates logged via `HL_Audit_Service::log()` with:
- Action type (e.g., `import_participant_created`, `import_team_created`)
- Actor (current admin user)
- Cycle ID
- Before/after data for updates
- Import run UUID for traceability

### Error Report CSV

Generated on commit failure or when errors exist in preview. Columns:
- Row Number, Email/Name, Status, Messages, Suggested Fix

---

## What's Removed

| Old Feature | Disposition |
|-------------|-------------|
| Classrooms import type | **Removed** — classrooms auto-created from Participant import |
| Teaching Assignments import type | **Removed** — auto-created from Participant import |
| Settings → Import tab | **Removed** — replaced with Cycle Editor → Import tab |
| Global cycle dropdown | **Removed** — cycle is implicit from Cycle Editor context |

---

## What's NOT in Scope

- AI-powered import (future enhancement — preprocessing layer)
- Cross-cycle pathway auto-assignment rules engine
- Classroom Visit Observer assignment from import
- Float teacher detection/flagging (handled downstream by `requires_classroom` eligibility)
- Import templates (downloadable CSV) — future, listed in STATUS.md

---

## Data Flow Diagram

```
Admin → Cycle Editor → Import Tab
         ↓
    Upload CSV → PHP parses → HL_Import_Service (orchestrator)
         ↓
    HL_Import_Participant_Handler::validate()
         ↓
    Lookup: schools (Partnership-scoped), pathways, teams, classrooms, users
         ↓
    Return preview_data JSON → JS renders preview table
         ↓
    Admin selects rows → Commit AJAX
         ↓
    HL_Import_Participant_Handler::commit() [TRANSACTION]
         ↓
    1. Create WP users
    2. Create enrollments
    3. Create classrooms (auto)
    4. Create teaching assignments (auto)
    5. Create teams (auto)
    6. Create team memberships
    7. Create coach assignments
    8. Assign pathways (explicit or sync_role_defaults)
         ↓
    All succeed → COMMIT + audit log + results summary
    Any fail → ROLLBACK + error report
```

---

## Existing Services Used

| Service | Used For |
|---------|----------|
| `HL_Team_Service::create_team()` | Auto-create teams |
| `HL_Team_Service::add_member()` | Team membership (mentor/member type) |
| `HL_Pathway_Assignment_Service::assign_pathway()` | Explicit pathway assignment |
| `HL_Pathway_Assignment_Service::sync_role_defaults()` | Role-based pathway fallback |
| `HL_Coach_Assignment_Service` | Coach→mentor enrollment assignments |
| `HL_Classroom_Service::create_classroom()` | Auto-create classrooms |
| `HL_Classroom_Service::create_teaching_assignment()` | Link teacher→classroom |
| `HL_Audit_Service::log()` | Audit trail |
| `HL_Enrollment_Repository` | Enrollment CRUD |
| `HL_OrgUnit_Repository` | School/District lookups |
| `HL_Child_Repository` | Child CRUD + fingerprint matching |
| `HL_Classroom_Repository` | Classroom lookups |

---

## Validation Against Real ELCPB Y2 Roster

Tested design against the actual 55-row ELCPB Year 2 roster (`B2E_FL_ELCPBC_2026_Master-Roster-for-LMS.xlsx`):

| Roster Scenario | Import Handling |
|-----------------|-----------------|
| 3 District Leaders with "ELC Palm Beach" as School | Warning: "ELC Palm Beach" not found as school in Partnership. Admin leaves school blank or fixes. District Leaders can have null school. |
| 10 Mentors with classrooms + teams | CREATE enrollment (role=Mentor), auto-create classroom + teaching assignment, auto-create team, membership_type=mentor |
| Sheena Willis (Mentor, no classroom, Asst. Director) | CREATE enrollment (role=Mentor), no classroom/teaching assignment (column blank), team assignment works normally |
| 4 Float teachers (no classroom, Role="Float teacher") | CREATE enrollment (role=Teacher), no classroom (column blank). Eligibility system handles component rules downstream. |
| 30 Teachers with classrooms, mentors, teams | CREATE enrollment, auto-create classrooms, teaching assignments, team membership_type=member |
| Coach assignments (3 coaches across teams) | assigned_coach column with coach email. Creates hl_coach_assignment scope_type=enrollment for each mentor. |
| "New Mentor" flag (Y = was previously a teacher) | Not imported directly. Pathway column (if provided) handles routing to Mentor Transition. Otherwise admin assigns pathway post-import. |
| LMS Pathway column (empty in real data) | Optional. Falls back to sync_role_defaults after commit. |
| Wee Care primary teacher data corruption | Preview shows WARNING for rows where is_primary_teacher value isn't Y/N. Admin fixes and re-uploads. |
| Two teachers sharing same classroom (e.g., "Infant" at ABC) | Both get teaching assignments to the same classroom. Classroom created once, second assignment links to existing. |

All 55 rows accounted for. No scenario breaks the design.
