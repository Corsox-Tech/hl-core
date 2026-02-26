# Housman Learning Core Plugin — AI Library
## File: 07_IMPORTS_ROSTERS_IDENTITIES_MATCHING.md
Version: 2.0
Last Updated: 2026-02-25
Timezone: America/Bogota

---

# 0) Purpose

This document specifies HL Core import requirements:
- Supported file types: CSV, XLS, XLSX
- Import flows: preview → validate → row selection → commit → results/errors
- Identity matching rules (Users, Children, OrgUnits, Classrooms)
- Relationship imports (Track enrollment, classroom assignments, teams)
- Handling ambiguous/weak identifiers with "Needs Review"

Rules:
- Imports must be safe (no silent destructive operations).
- Imports must be repeatable (idempotent behavior) where possible.
- All commits must be auditable.

---

# 1) Supported Import Types (v1)

HL Core must support at minimum:

## 1.1 Participants Import (Users + Track Enrollments)
Imports people into a Track and assigns Track Roles + scope bindings.

Primary identity:
- Users matched by email (unique).

## 1.2 Children Import (Children + Classroom placement)
Imports children for a School and assigns each child to a Classroom (current placement).

Primary identity:
- Children may not have reliable external IDs. HL Core must generate child_uuid and match using fingerprint + review.

## 1.3 Classroom Import (Classrooms within a School)
Imports classroom names for a School.

Primary identity:
- Name + School scope.

## 1.4 Teaching Assignments Import (Teacher ↔ Classroom)
Imports teacher assignments to classrooms (many-to-many), including lead teacher flag.

Primary identity:
- teacher email + classroom name + school scope (+ track scope for enrollment linkage)

## 1.5 Team Setup Import (Optional v1)
If implemented:
- Create teams and assign mentors/members within a Track + School.

Primary identity:
- team name + school + track, and participant emails.

Note:
- If too complex, v1 may skip team import and provide UI-based team builder.

---

# 2) File Template Philosophy

HL Core should provide downloadable templates for each import type.
Templates should include:
- required columns
- optional columns
- data formats and examples
- a "Notes" sheet for XLSX templates (human guidance)

Imports must support:
- CSV with headers
- XLS/XLSX with first sheet as data (by default)
- ability to choose sheet name (nice-to-have)

---

# 3) Import Workflow (Preview → Commit)

## 3.1 Step 1: Upload
User selects:
- Track (required for participant-related imports)
- School (required for classroom/child imports; optional if can be inferred)
- Import type (participants, children, classrooms, teaching assignments, teams)

## 3.2 Step 2: Parse
System parses rows and normalizes values:
- trim whitespace
- normalize email casing
- normalize dates (DOB)
- map known synonyms (e.g., "Lead" → is_lead_teacher)

## 3.3 Step 3: Validate + Match (Preview Table)
Each row must be assigned a Preview Status:

PreviewStatus ∈
- CREATE
- UPDATE
- SKIP
- NEEDS_REVIEW
- ERROR

Each row must include:
- matched_entity (if found)
- validation_messages[] (warnings/errors)
- proposed_actions[] (create user, enroll user, assign classroom, etc.)

## 3.4 Step 4: User Selection Controls
Preview UI must allow:
- row-level checkbox to include/exclude row from commit
- bulk actions:
  - select all CREATE
  - select all UPDATE
  - select none
  - select all NEEDS_REVIEW (usually off by default)
- row-level action overrides:
  - mark as SKIP
  - choose among duplicate matches (for NEEDS_REVIEW)
  - edit certain parsed fields in-place (optional; staff only)

Leaders (client users) may only run imports if permitted (default: no).
Staff (Admin/Coach) can run all imports.

## 3.5 Step 5: Commit
On commit:
- perform operations in a transaction where possible
- write audit logs
- output a results summary:
  - created_count
  - updated_count
  - skipped_count
  - needs_review_count (should be 0 at commit time unless "force commit" is enabled)
  - error_count

## 3.6 Step 6: Downloadable Error Report
Generate a CSV report containing:
- row number
- original row values
- status
- validation messages
- remediation suggestions

---

# 4) Identity Matching Rules

## 4.1 Users (Participants)
Primary key:
- email (case-insensitive)

Rules:
- If user exists by email:
  - DO NOT modify WP user profile unless staff explicitly allows it
  - Enroll user into Track (if not enrolled)
  - Apply Track Role(s) and scope bindings from the import row
- If user does not exist:
  - Create WP user (minimum: email, first name, last name)
  - Enroll user into Track

Special case:
- If a District/School Leader (client role) is allowed to create users via UI, that is separate from imports.
- v1 assumes imports are staff-run (recommended).

---

## 4.2 OrgUnits (District / School)
v1 matching (when importing track participants):
- If District name is provided:
  - match District OrgUnit by exact normalized name OR orgunit_code if present
- Schools:
  - match School OrgUnit by exact normalized name within District scope (if District exists)
  - if no District, match globally by name OR require staff to pick the school explicitly

Recommendation:
- Use orgunit_code when available; name matching is fragile.

---

## 4.3 Classrooms
Primary match:
- classroom_name within school_id

Rules:
- If classroom exists under school:
  - UPDATE (if allowed) or just link
- If not:
  - CREATE classroom under school (optional in certain imports)
  - recommend: allow classroom auto-create during children or teaching assignment import

Uniqueness recommendation:
- unique(school_id, normalized_classroom_name)

---

## 4.4 Children (Weak Identity)
Problem:
- Institutions may not provide stable child IDs.
- Provided fields may include DOB, (sometimes) internal ID, classroom name, ethnicity, etc.
- Classroom may change, so cannot be part of a stable identity.

HL Core must implement:
- internal child_uuid (immutable primary key)
- fingerprint matching to detect likely duplicates
- NEEDS_REVIEW when ambiguous

### 4.4.1 Child Fingerprint (Recommended v1)
Compute a fingerprint string using best available fields:

Preferred fingerprint inputs (in order):
1) DOB (required if no stable ID exists)
2) Provided internal ID (if present, but not fully trusted)
3) Optional: initials or name fields (if present)
4) School scope (always include school_id)

Example canonical fingerprint formula (conceptual):
fingerprint = hash(
  school_id +
  dob_yyyy_mm_dd +
  normalize(internal_child_id_if_present) +
  normalize(first_name_if_present) +
  normalize(last_name_if_present)
)

Rules:
- Do NOT include classroom in fingerprint.
- If DOB is missing and no stable ID exists → NEEDS_REVIEW or ERROR (configurable).
- If fingerprint collision occurs (multiple matches) → NEEDS_REVIEW.

### 4.4.2 Matching Outcomes
For each imported child row:
- If exactly 1 child matches fingerprint → UPDATE (or LINK)
- If 0 matches → CREATE
- If >1 matches → NEEDS_REVIEW (user must pick or create new)

### 4.4.3 Human-Readable Child Identifier
Because clients may not supply names, HL Core must generate a readable identifier:
- child_display_code (e.g., "C-{SCHOOL_CODE}-{SHORT_UUID}")
This is for UI and exports, not matching.

---

# 5) Relationship Imports

## 5.1 Participant Enrollment in Track
Participants import must support columns:
- email (required)
- first_name (optional but recommended)
- last_name (optional but recommended)
- track_roles (required; one or multiple)
- district_name or district_code (optional)
- school_name or school_code (required unless staff selects school at import run-time)

Enrollment rules:
- Create Enrollment if missing
- Update Enrollment roles/scope if present (staff only)

---

## 5.2 Teaching Assignments (Teacher ↔ Classroom)
Teaching assignment rows must include:
- teacher_email (required)
- school_name or school_code (required unless selected at import run-time)
- classroom_name (required)
- is_lead_teacher (optional; default false)

Rules:
- Ensure teacher is enrolled in the Track (if import is track-scoped)
- Create or update TeachingAssignment
- Multiple classrooms per teacher supported

Important:
- Child assessment requirements depend on these assignments.

---

## 5.3 Child Classroom Placement
Children import must include:
- school_name/school_code (required unless selected at import run-time)
- classroom_name (required)
- DOB (required unless stable child id exists)
- internal_child_id (optional)
- ethnicity (optional)
- any additional provided fields (optional)

Rules:
- Ensure classroom exists (auto-create if configured)
- Create/update child record
- Create/update current ChildClassroomAssignment to the specified classroom
- If child moves classrooms:
  - update current assignment
  - optionally record ChildClassroomHistory

---

## 5.4 Team Setup Import (if enabled)
Columns:
- track_code or track selection (required)
- school_name/school_code
- team_name
- mentor_email_1
- mentor_email_2 (optional)
- member_emails (comma-separated) OR multiple member_email columns

Rules:
- Ensure all emails exist and are enrolled in the Track
- Enforce 1 team per enrollment per Track
- Flag conflicts as NEEDS_REVIEW

---

# 6) Handling "Needs Review"

NEEDS_REVIEW must occur when:
- multiple child fingerprint matches
- missing required fields but might be resolvable
- classroom name is unknown and auto-create is disabled
- teacher email exists but enrollment scope conflicts
- team membership violates "one team per track" constraint

NEEDS_REVIEW rows must not commit unless:
- user resolves ambiguity OR
- staff uses an explicit "force commit" option (discouraged)

---

# 7) Importing When Tracks Are Draft/Paused

Tracks have status:
- draft / active / paused / archived

Rules:
- Imports into draft/paused Tracks are allowed for staff.
- For non-staff (if ever allowed), restrict to active only.
- Imports into archived Tracks are disallowed (read-only).

---

# 8) Audit Logging (Imports)

For each import run, record:
- import_run_id
- actor_user_id
- track_id (if applicable)
- import_type
- file_name
- timestamp
- counts summary
- a reference to stored preview/commit artifacts (optional)

For each committed entity action, log:
- created/updated entity ids
- before/after for key fields (where feasible)

---

# 9) Recommended Templates (Columns)

## 9.1 Participants Import Template (minimum)
Required:
- email
- track_role(s)
- school_name (or school_code)

Optional:
- first_name
- last_name
- district_name (or district_code)
- gender (optional metadata)
- notes (ignored by importer unless mapped)

## 9.2 Children Import Template (minimum)
Required:
- school_name (or school_code)
- classroom_name
- dob (YYYY-MM-DD)

Optional:
- internal_child_id
- ethnicity
- first_name / last_name (if available)

## 9.3 Classrooms Import Template (minimum)
Required:
- school_name (or school_code)
- classroom_name

## 9.4 Teaching Assignments Import Template (minimum)
Required:
- teacher_email
- school_name (or school_code)
- classroom_name

Optional:
- is_lead_teacher (true/false)

---

End of file.
