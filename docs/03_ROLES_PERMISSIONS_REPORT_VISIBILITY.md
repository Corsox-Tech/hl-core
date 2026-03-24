# Housman Learning Core Plugin — AI Library
## File: 03_ROLES_PERMISSIONS_REPORT_VISIBILITY.md
Version: 3.0
Last Updated: 2026-03-17
Timezone: America/Bogota

---

# 0) Purpose

This document defines the **authorization model** for HL Core:
- Roles (system-level and cycle-level)
- Scopes (district / school / team / self)
- Exactly what each role can read/write
- Report visibility rules
- Assessment privacy rules

Rules:
- All permission checks MUST be enforced server-side.
- Never rely on UI-only hiding.
- Cycle participation and roles are stored on Enrollment (User ↔ Cycle).

---

# 1) Role Types

## 1.1 System Roles (Global / WP-level)
These reflect Housman internal staff access. They are not tied to a Cycle.

### Housman Admin
- WordPress admin
- Full permissions across all HL Core data

### Coach
- Housman staff (not necessarily WP admin)
- Elevated permissions across Cycles, within staff rules defined below

---

## 1.2 Cycle Roles (Enrollment-level)
These are roles assigned per Enrollment within a Cycle.

Allowed Cycle Roles:
- Teacher
- Mentor
- School Leader
- District Leader

Notes:
- A User can have different roles in different Cycles.
- A User may hold multiple Cycle Roles in the same Cycle (rare; allowed).
- Leaders are few and manual assignment is acceptable.

---

# 2) Scopes (Where permissions apply)

Scopes are evaluated relative to a specific Cycle.

## 2.1 Self Scope
- Data for the current user's own Enrollment only.

## 2.2 Team Scope
- Data for Enrollments belonging to the same Team in the Cycle.
- Mentor visibility is based on TeamMembership.

## 2.3 School Scope
- Data for Enrollments assigned to a specific School in the Cycle.
- School Leaders see School scope reports.

## 2.4 District Scope
- Data for all Schools under a District (OrgUnit hierarchy) within the Cycle.
- District Leaders see District scope reports.

## 2.5 Staff Scope
- All Cycles and all scopes.

---

# 3) Core Security Rules (Non-negotiable)

## 3.1 Enrollment-based authorization
- If a user is not enrolled in a Cycle, they have no Cycle access unless they are Staff.

## 3.2 Assessment response privacy
Raw responses for:
- Teacher Self-Assessment
- Child Assessment
are visible ONLY to:
- Housman Admin
- Coach

Non-staff roles may see only:
- completion status (0/100) and timestamps
- not the answer-level data

## 3.3 User management restrictions for client roles
Client leaders have limited user creation abilities:
- District Leaders can create users within their Cycle + District scope only
- School Leaders can create users within their Cycle + School scope only
Client leaders cannot:
- edit existing users
- delete or deactivate users
- reset passwords

---

# 4) Capabilities (Atomic Actions)

Use these canonical capability names in code and docs.

## 4.1 WordPress Capabilities (Registered in Code)

HL Core registers these 8 WP capabilities:

- `manage_hl_core` — full admin access (granted to administrators)
- `hl_view_partnerships` — view Partnership list / detail
- `hl_edit_partnerships` — create / edit Partnerships
- `hl_view_enrollments` — view Enrollment data
- `hl_edit_enrollments` — create / edit Enrollments
- `hl_view_assessments` — view assessment completion status
- `hl_view_assessment_responses` — view raw assessment response data (staff only)
- `hl_edit_assessments` — manage assessment instruments and configuration

## 4.2 Role-Based Access via HL_Scope_Service

Most access control is **not** done via WP capabilities. Instead, `HL_Scope_Service` detects the user's role from `hl_enrollment.roles` JSON and applies scope-based filtering (self / team / school / district / staff). The WP capabilities above gate admin-page access; the scope service gates data visibility within those pages.

---

# 5) Permissions Matrix (Role × Capability)

Legend:
- allowed
- not allowed
- allowed with scope limits (see Scope Rules section)

## 5.1 Staff Roles (Global)

### Housman Admin
- All capabilities allowed

### Coach (Housman staff)
Allowed:
- cycle.view
- orgunit.view
- enrollment.view
- enrollment.create (create WP users + enroll)
- assessment.view_completion
- assessment.view_responses
- assessment.export_responses
- observation.view
- coaching.manage
- coaching.mark_attendance
- overrides.apply (exempt components only)

Not allowed by default (may be enabled if desired):
- cycle.create / edit / archive
- pathway.manage / component.manage / unlock_rules.manage
- manual unlock overrides (if reserved for Admin only)

Note:
- If implementation needs simplicity, Coaches may be granted configuration permissions,
  but v1 assumes Admin controls configuration.

---

## 5.2 Cycle Roles (Enrollment-level)

### Teacher
Allowed:
- cycle.view (only cycles where enrolled)
- reports.view (self scope only)
- assessment.submit (teacher self-assessment; child assessment; if assigned)
- assessment.view_completion (self completion only)
- observation.view (optional; not required v1)
Not allowed:
- reports.export
- assessment.view_responses
- any management permissions

Scope:
- Self only.

---

### Mentor
Allowed:
- cycle.view (enrolled cycles only)
- reports.view (team scope)
- reports.export (team scope) [optional; enable if desired]
- observation.submit (mentor forms)
- observation.view (for their own submissions; staff view for all)
- assessment.view_completion (team scope completion only; no responses)
Not allowed:
- assessment.view_responses
- user management
- cycle configuration

Scope:
- Team.

---

### School Leader
Allowed:
- cycle.view (enrolled cycles only)
- reports.view (school scope)
- reports.export (school scope)
- users.create (create-only; within cycle + school scope)
- enrollment.create (enroll newly created users into same cycle + school)
Not allowed:
- users.edit / deactivate / reset_password
- assessment.view_responses
- cycle/pathway configuration

Scope:
- School.

---

### District Leader
Allowed:
- cycle.view (enrolled cycles only)
- reports.view (district scope)
- reports.export (district scope)
- users.create (create-only; within cycle + district scope)
- enrollment.create (enroll newly created users into same cycle + district/school mapping)
Not allowed:
- users.edit / deactivate / reset_password
- assessment.view_responses
- cycle/pathway configuration

Scope:
- District.

---

# 6) Scope Enforcement Rules (Must implement)

## 6.1 Cycle access
Non-staff users can only access a Cycle if they have an Enrollment for that Cycle.

## 6.2 District scope resolution
District Leader can view/report enrollments where:
- enrollment.cycle_id matches AND
- enrollment.school_id is a child school of the leader's district OR
- enrollment has district_id matching (implementation dependent)

## 6.3 School scope resolution
School Leader can view/report enrollments where:
- enrollment.cycle_id matches AND
- enrollment.school_id matches their school(s)

## 6.4 Team scope resolution
Mentor can view/report enrollments where:
- enrollment.cycle_id matches AND
- enrollment is in the same team as the mentor (TeamMembership join)

## 6.5 Self scope resolution
Teacher can view/report only their own enrollment record.

---

# 7) Assessment Visibility (Detailed)

## 7.1 Teacher Self-Assessment
- Teacher can submit their own
- Non-staff can see completion only (complete/not complete, timestamps)
- Staff can see responses and export

## 7.2 Child Assessment
- Required per (Cycle, Classroom, Teacher assignment)
- Teacher can submit for assigned classrooms
- Non-staff can see completion only
- Staff can see responses and export

---

# 8) "Create User" Workflow Permissions (Client leaders)

Client leader user creation is restricted.

## 8.1 District Leader create-only
District Leaders may:
- create a WP user with email + name (minimum)
- immediately enroll that user in the same Cycle
- assign that enrollment to an allowed school within their district

District Leaders may NOT:
- edit existing users
- deactivate users
- reset passwords
- create users outside their Cycle or outside their district scope

## 8.2 School Leader create-only
School Leaders may:
- create a WP user with email + name (minimum)
- immediately enroll that user in the same Cycle
- assign that enrollment to their school

School Leaders may NOT:
- edit existing users
- deactivate users
- reset passwords
- create users outside their Cycle or outside their school scope

Implementation note:
- If a user email already exists, leaders should not modify the existing user.
  They may request staff assistance or optionally "invite/enroll existing user" if permitted.

---

# 9) Audit Logging Requirements (Authorization-related)

Log these events with:
- actor_user_id
- timestamp
- cycle_id (if applicable)
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
