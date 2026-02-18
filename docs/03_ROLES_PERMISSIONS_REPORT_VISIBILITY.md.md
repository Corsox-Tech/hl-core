# Housman Learning Core Plugin — AI Library
## File: 03_ROLES_PERMISSIONS_REPORT_VISIBILITY.md
Version: 1.0
Last Updated: 2026-02-13
Timezone: America/Bogota

---

# 0) Purpose

This document defines the **authorization model** for HL Core:
- Roles (system-level and cohort-level)
- Scopes (district / center / team / self)
- Exactly what each role can read/write
- Report visibility rules
- Assessment privacy rules

Rules:
- All permission checks MUST be enforced server-side.
- Never rely on UI-only hiding.
- Cohort participation and roles are stored on Enrollment (User ↔ Cohort).

---

# 1) Role Types

## 1.1 System Roles (Global / WP-level)
These reflect Housman internal staff access. They are not tied to a Cohort.

### Housman Admin
- WordPress admin
- Full permissions across all HL Core data

### Coach
- Housman staff (not necessarily WP admin)
- Elevated permissions across Cohorts, within staff rules defined below

---

## 1.2 Cohort Roles (Enrollment-level)
These are roles assigned per Enrollment within a Cohort.

Allowed Cohort Roles:
- Teacher
- Mentor
- Center Leader
- District Leader

Notes:
- A User can have different roles in different Cohorts.
- A User may hold multiple Cohort Roles in the same Cohort (rare; allowed).
- Leaders are few and manual assignment is acceptable.

---

# 2) Scopes (Where permissions apply)

Scopes are evaluated relative to a specific Cohort.

## 2.1 Self Scope
- Data for the current user's own Enrollment only.

## 2.2 Team Scope
- Data for Enrollments belonging to the same Team in the Cohort.
- Mentor visibility is based on TeamMembership.

## 2.3 Center Scope
- Data for Enrollments assigned to a specific Center in the Cohort.
- Center Leaders see Center scope reports.

## 2.4 District Scope
- Data for all Centers under a District (OrgUnit hierarchy) within the Cohort.
- District Leaders see District scope reports.

## 2.5 Staff Scope
- All Cohorts and all scopes.

---

# 3) Core Security Rules (Non-negotiable)

## 3.1 Enrollment-based authorization
- If a user is not enrolled in a Cohort, they have no Cohort access unless they are Staff.

## 3.2 Assessment response privacy
Raw responses for:
- Teacher Self-Assessment
- Children Assessment
are visible ONLY to:
- Housman Admin
- Coach

Non-staff roles may see only:
- completion status (0/100) and timestamps
- not the answer-level data

## 3.3 User management restrictions for client roles
Client leaders have limited user creation abilities:
- District Leaders can create users within their Cohort + District scope only
- Center Leaders can create users within their Cohort + Center scope only
Client leaders cannot:
- edit existing users
- delete or deactivate users
- reset passwords

---

# 4) Capabilities (Atomic Actions)

Use these canonical capability names in code and docs.

## 4.1 Cohort & Configuration
- cohort.view
- cohort.create
- cohort.edit
- cohort.archive
- cohort.manage_settings
- pathway.manage
- activity.manage
- unlock_rules.manage
- overrides.apply

## 4.2 Org Structure (District/Center/Classroom)
- orgunit.view
- orgunit.create
- orgunit.edit
- orgunit.archive
- classroom.manage
- team.manage

## 4.3 Enrollment & Participation
- enrollment.view
- enrollment.create
- enrollment.edit_roles
- enrollment.deactivate
- teammembership.manage
- teachingassignment.manage

## 4.4 Assessments & Artifacts
- assessment.view_completion
- assessment.view_responses
- assessment.export_responses
- assessment.submit (participant submitting their own)
- observation.submit
- observation.view
- coaching.manage
- coaching.mark_attendance

## 4.5 Imports
- import.run
- import.preview
- import.commit
- import.download_errors

## 4.6 Reporting
- reports.view
- reports.export

## 4.7 User Management (WP Users)
- users.create
- users.reset_password
- users.edit (staff only)
- users.deactivate (staff only)

---

# 5) Permissions Matrix (Role × Capability)

Legend:
- ✅ allowed
- ❌ not allowed
- ⚠️ allowed with scope limits (see Scope Rules section)

## 5.1 Staff Roles (Global)

### Housman Admin
- All capabilities ✅

### Coach (Housman staff)
✅ Allowed:
- cohort.view
- orgunit.view
- enrollment.view
- enrollment.create (create WP users + enroll)
- assessment.view_completion
- assessment.view_responses
- assessment.export_responses
- observation.view
- coaching.manage
- coaching.mark_attendance
- overrides.apply (exempt activities only)

❌ Not allowed by default (may be enabled if desired):
- cohort.create / edit / archive
- pathway.manage / activity.manage / unlock_rules.manage
- manual unlock overrides (if reserved for Admin only)

Note:
- If implementation needs simplicity, Coaches may be granted configuration permissions,
  but v1 assumes Admin controls configuration.

---

## 5.2 Cohort Roles (Enrollment-level)

### Teacher
✅ Allowed:
- cohort.view (only cohorts where enrolled)
- reports.view (self scope only)
- assessment.submit (teacher self-assessment; children assessment; if assigned)
- assessment.view_completion (self completion only)
- observation.view (optional; not required v1)
❌ Not allowed:
- reports.export
- assessment.view_responses
- any management permissions

Scope:
- Self only.

---

### Mentor
✅ Allowed:
- cohort.view (enrolled cohorts only)
- reports.view (team scope)
- reports.export (team scope) [optional; enable if desired]
- observation.submit (mentor forms)
- observation.view (for their own submissions; staff view for all)
- assessment.view_completion (team scope completion only; no responses)
❌ Not allowed:
- assessment.view_responses
- user management
- cohort configuration

Scope:
- Team.

---

### Center Leader
✅ Allowed:
- cohort.view (enrolled cohorts only)
- reports.view (center scope)
- reports.export (center scope)
- users.create ⚠️ (create-only; within cohort + center scope)
- enrollment.create ⚠️ (enroll newly created users into same cohort + center)
❌ Not allowed:
- users.edit / deactivate / reset_password
- assessment.view_responses
- cohort/pathway configuration

Scope:
- Center.

---

### District Leader
✅ Allowed:
- cohort.view (enrolled cohorts only)
- reports.view (district scope)
- reports.export (district scope)
- users.create ⚠️ (create-only; within cohort + district scope)
- enrollment.create ⚠️ (enroll newly created users into same cohort + district/center mapping)
❌ Not allowed:
- users.edit / deactivate / reset_password
- assessment.view_responses
- cohort/pathway configuration

Scope:
- District.

---

# 6) Scope Enforcement Rules (Must implement)

## 6.1 Cohort access
Non-staff users can only access a Cohort if they have an Enrollment for that Cohort.

## 6.2 District scope resolution
District Leader can view/report enrollments where:
- enrollment.cohort_id matches AND
- enrollment.center_id is a child center of the leader's district OR
- enrollment has district_id matching (implementation dependent)

## 6.3 Center scope resolution
Center Leader can view/report enrollments where:
- enrollment.cohort_id matches AND
- enrollment.center_id matches their center(s)

## 6.4 Team scope resolution
Mentor can view/report enrollments where:
- enrollment.cohort_id matches AND
- enrollment is in the same team as the mentor (TeamMembership join)

## 6.5 Self scope resolution
Teacher can view/report only their own enrollment record.

---

# 7) Assessment Visibility (Detailed)

## 7.1 Teacher Self-Assessment
- Teacher can submit their own
- Non-staff can see completion only (complete/not complete, timestamps)
- Staff can see responses and export

## 7.2 Children Assessment
- Required per (Cohort, Classroom, Teacher assignment)
- Teacher can submit for assigned classrooms
- Non-staff can see completion only
- Staff can see responses and export

---

# 8) "Create User" Workflow Permissions (Client leaders)

Client leader user creation is restricted.

## 8.1 District Leader create-only
District Leaders may:
- create a WP user with email + name (minimum)
- immediately enroll that user in the same Cohort
- assign that enrollment to an allowed center within their district

District Leaders may NOT:
- edit existing users
- deactivate users
- reset passwords
- create users outside their Cohort or outside their district scope

## 8.2 Center Leader create-only
Center Leaders may:
- create a WP user with email + name (minimum)
- immediately enroll that user in the same Cohort
- assign that enrollment to their center

Center Leaders may NOT:
- edit existing users
- deactivate users
- reset passwords
- create users outside their Cohort or outside their center scope

Implementation note:
- If a user email already exists, leaders should not modify the existing user.
  They may request staff assistance or optionally "invite/enroll existing user" if permitted.

---

# 9) Audit Logging Requirements (Authorization-related)

Log these events with:
- actor_user_id
- timestamp
- cohort_id (if applicable)
- affected entity IDs
- before/after (where possible)

Minimum events:
- user created (by whom, from which role)
- enrollment created
- enrollment role changes
- any override applied (exempt/unlock)
- assessment response viewed/exported (staff-only access logging)
- import committed

---

End of file.
