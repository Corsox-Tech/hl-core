# Housman Learning Core Plugin — AI Library
## File: 02_DOMAIN_MODEL_ORG_STRUCTURE.md
Version: 3.0
Last Updated: 2026-03-17
Timezone: America/Bogota

---

# 0) Purpose

This document defines the **canonical domain model** (objects + relationships + constraints) for HL Core.

Rules:
- This is the system-of-record model. Do not rely on ad-hoc WP meta for core relationships.
- WordPress Users provide identity only; cycle participation is modeled via Enrollment.
- Org Structure persists across years; Cycles are repeatable/iterable instances within Partnerships.

---

# 1) Entity List (Canonical Objects)

HL Core manages the following entities:

## 1.1 OrgUnit
Represents either a District or a School.

- OrgUnit.type ∈ { "district", "school" }
- OrgUnit is persistent (not tied to a Cycle).

OrgUnit relationships:
- A School OrgUnit may have a parent District OrgUnit (optional).
- A District OrgUnit may have many child School OrgUnits.

Rationale:
- A single "OrgUnit" object with a type enables hierarchy without duplicating schemas.

---

## 1.2 Partnership
An optional container entity that groups one or more Cycles together for organizational purposes.

Partnership is optional. Cycles can exist without a Partnership (`partnership_id` is nullable). Useful for visual organization or comparison reporting, but not required for any Cycle to function.

Partnership fields:
- partnership_id (PK)
- partnership_uuid (primary internal)
- partnership_name
- partnership_code (globally unique; human-readable)
- description (text)
- status ∈ { "active", "archived" }

Partnership relationships:
- Partnership has many Cycles (via Cycle.partnership_id FK, nullable)

Purpose:
- Groups related cycles for cross-cycle reporting (optional)
- Can enable program-vs-control comparison reporting when the partnership contains both program cycles (is_control_group=false) and control cycles (is_control_group=true) — but this is supplementary; primary research analysis uses CSV export to Stata
- Comparison metrics include per-section/per-item assessment means, pre-to-post change, and Cohen's d effect size

Example:
- "B2E Mastery - Lutheran Services Florida" partnership containing:
  - "ELCPB B2E Mastery 2025-2027" (program cycle, is_control_group=false)
  - "Lutheran Control Group 2025-2027" (control cycle, is_control_group=true)
- A Cycle can also exist without any Partnership.

---

## 1.3 Cycle
The full program engagement for a district/institution. Contains Pathways directly. For the B2E Mastery Program, a Cycle spans the entire multi-year contract.

Cycle relationships:
- Cycle.partnership_id → Partnership (FK to container, **nullable** — Cycle can exist without a Partnership)
- Cycle has many Pathways (via Pathway.cycle_id FK)
- Cycle may be associated to:
  - one District OrgUnit (optional)
  - one or more School OrgUnits (required at least 1)

Cycle fields:
- cycle_type ∈ { "program", "course" } (default "program")
  - `program`: full B2E management with Pathways, Teams, Coaching, Assessments
  - `course`: simple institutional course access — auto-creates one Pathway + one Component; admin UI hides Teams, Coaching, Assessment tabs
- is_control_group (boolean, default false) — When true, indicates this cycle is a research control group. Control cycles receive assessment-only pathways (no courses, coaching, observations). Admin UI hides Coaching and Teams tabs for control cycles. See doc 06 §6 for control group assessment workflow.

Important:
- District is optional. A single-school Cycle has no District association.

---

## 1.4 Enrollment
Join object: (User ↔ Cycle)

Enrollment relationships:
- Enrollment.user_id → WP User
- Enrollment.cycle_id → Cycle

Enrollment holds:
- cycle_roles (set)
- pathway assignments (see 04_CYCLE_PATHWAYS_COMPONENTS_RULES.md)
- scope bindings (school/district as applicable)
- status (active/inactive)

Key rule:
- Cycle Roles live on Enrollment, not WP user.

---

## 1.5 Team
Mentorship group inside a School for a specific Cycle.

Team relationships:
- Team.cycle_id → Cycle
- Team.school_id → OrgUnit(type=school)

Team membership is represented by TeamMembership (below).

---

## 1.6 TeamMembership
Join object: (Enrollment ↔ Team)

TeamMembership relationships:
- TeamMembership.team_id → Team
- TeamMembership.enrollment_id → Enrollment

TeamMembership fields:
- membership_type ∈ { "mentor", "member" }
  - mentor = team mentor(s)
  - member = teacher participants assigned as mentees

Constraints:
- An Enrollment can belong to **at most one Team per Cycle**.
  - Implementation: enforce uniqueness of (cycle_id, enrollment_id) across team memberships.
- A Team can have 1–2 mentors (soft constraint; enforce at UI/service layer).

Notes:
- membership_type exists to support teams containing both mentors and members while remaining explicit.

---

## 1.7 Classroom
A classroom belonging to a School (persistent across Cycles).

Classroom relationships:
- Classroom.school_id → OrgUnit(type=school)

Classrooms exist independently of Cycles, but Cycles reference "current" classroom rosters and assignments.

---

## 1.8 TeachingAssignment
Join object: (Enrollment ↔ Classroom)

TeachingAssignment relationships:
- TeachingAssignment.enrollment_id → Enrollment
- TeachingAssignment.classroom_id → Classroom

TeachingAssignment fields:
- is_lead_teacher (boolean)
- effective_start_date (optional)
- effective_end_date (optional)

Constraints:
- Many-to-many:
  - A teacher can teach multiple classrooms.
  - A classroom can have multiple teachers.
- Lead Teacher is informational for admin/UI; it does NOT alter assessment requirement rules.

---

## 1.9 Child
Child record belonging to a School.

Child relationships:
- Child.school_id → OrgUnit(type=school)

Identity:
- HL Core generates immutable child_uuid.
- External identifiers may be missing/unreliable.

---

## 1.10 ChildClassroomAssignment (Current)
Represents current placement of a Child in a Classroom.

Relationships:
- ChildClassroomAssignment.child_id → Child
- ChildClassroomAssignment.classroom_id → Classroom

Fields:
- status (enum: active, teacher_removed) — soft-delete for teacher removals
- added_by_enrollment_id (nullable) — tracks which teacher added this child
- added_at (datetime, nullable)
- removed_by_enrollment_id (nullable) — tracks which teacher removed this child
- removed_at (datetime, nullable)
- removal_reason (enum: left_school, moved_classroom, other, nullable)
- removal_note (text, nullable)

Constraint:
- A Child has exactly one current classroom assignment at a time.

---

## 1.11 ChildCycleSnapshot
Represents the frozen per-child age group at the time a child is associated with a Cycle.

Relationships:
- ChildCycleSnapshot.child_id → Child
- ChildCycleSnapshot.cycle_id → Cycle

Fields:
- frozen_age_group (enum: infant, toddler, preschool, k2)
- dob_at_freeze (date)
- age_months_at_freeze (int)
- frozen_at (datetime)

Constraint:
- Unique per (child_id, cycle_id) — one snapshot per child per cycle.

---

## 1.12 ChildClassroomHistory (Optional but Recommended)
Stores history of child movement between classrooms.

Relationships:
- ChildClassroomHistory.child_id → Child
- ChildClassroomHistory.classroom_id → Classroom

Fields:
- start_date
- end_date
- reason (optional)

---

## 1.13 Observation
Mentor-submitted form artifact.

Relationships (recommended):
- Observation.cycle_id → Cycle
- Observation.mentor_enrollment_id → Enrollment
- Observation.teacher_enrollment_id → Enrollment (optional but recommended)
- Observation.school_id → OrgUnit(type=school) (optional)
- Observation.classroom_id → Classroom (optional)

Observation supports:
- attachments (images/videos)
- flexible frequency

---

## 1.14 CoachingSession
Coach-submitted artifact linked to a mentor and optionally observations.

Relationships (recommended):
- CoachingSession.cycle_id → Cycle
- CoachingSession.coach_user_id → WP User (staff)
- CoachingSession.mentor_enrollment_id → Enrollment
- CoachingSession.related_observation_ids[] → Observation (0..n)

Fields:
- attendance_status ∈ { "attended", "missed", "unknown" }
- notes (rich text)
- attachments

---

## 1.15 IndividualEnrollment
Direct user-to-LearnDash-course association for standalone individual purchases (not institutional).

IndividualEnrollment relationships:
- IndividualEnrollment.user_id → WP User
- IndividualEnrollment.course_id → LearnDash Course (post ID)

IndividualEnrollment fields:
- id (PK)
- user_id (BIGINT UNSIGNED)
- course_id (BIGINT UNSIGNED)
- enrolled_at (DATETIME)
- expires_at (DATETIME, nullable)
- status ∈ { "active", "expired", "revoked" } (default "active")
- enrolled_by (BIGINT UNSIGNED, nullable — staff who created the enrollment)
- notes (TEXT, nullable)
- created_at, updated_at

**Table**: `hl_individual_enrollment`
**PK**: `id`
**Unique**: `(user_id, course_id)`

Notes:
- Used for Short Courses and ECSELent Adventures individual purchases
- Supports per-person expiration dates (unlike LearnDash's global expiration)
- Frontend: "My Courses" section on Dashboard shows active individual enrollments
- Not related to Cycles — this is a separate, simpler enrollment path

---

# 2) Relationship Diagram (Text)

Partnership (optional container)
  └── Cycle [1..n]

OrgUnit(district)
  └── OrgUnit(school) [0..n]

OrgUnit(school)
  ├── Classroom [0..n]
  │     └── ChildClassroomAssignment (current)
  │           └── Child [0..n]
  └── Team [0..n] (per Cycle)

Cycle (within a Partnership or standalone; cycle_type: program or course)
  ├── Pathway [1..n]
  │     └── Component [0..n] (see doc 04/05)
  ├── Enrollment [0..n] (User ↔ Cycle)
  │     ├── PathwayAssignment [1..n] → Pathway
  │     ├── TeamMembership [0..1 per Cycle] → Team
  │     └── TeachingAssignment [0..n] → Classroom
  ├── Teacher Self-Assessments (see doc 06)
  ├── Child Assessments (see doc 06)
  ├── Observation [0..n]
  └── CoachingSession [0..n]

IndividualEnrollment (User ↔ LearnDash Course, standalone, no Cycle)

---

# 3) Constraints (Hard vs Soft)

## 3.1 Hard Constraints (Must enforce at DB or service layer)
- Enrollment is unique per (cycle_id, user_id)
- TeamMembership uniqueness: each enrollment_id can be in at most one team per cycle
- Classroom belongs to exactly one School
- Team belongs to exactly one Cycle and one School
- Child belongs to exactly one School
- Child has exactly one current classroom assignment

## 3.2 Soft Constraints (Enforce in UI/logic; allow override if needed)
- Team has 1–2 mentors (membership_type=mentor)
- Team has 4–7 members (typical historical pattern; not required)
- Observation may or may not be tied to a classroom/date; attachments optional but supported

---

# 4) Role Rules that Affect the Model

Cycle roles are stored on Enrollment as a set:
- Teacher
- Mentor
- School Leader
- District Leader

Notes:
- A user can be both School Leader and Mentor in the same Cycle (allowed).
- Leaders are few; manual assignment is acceptable.

Role-driven filtering requirements:
- When selecting District Leaders for a District, UI must filter to enrollments in that Cycle with role District Leader.
- When selecting School Leaders for a School, UI must filter to enrollments in that Cycle with role School Leader.
- When selecting Team Mentors, UI must filter to enrollments in that Cycle with role Mentor (and optionally allow leaders with Mentor role).

---

# 5) Identity Keys (Recommended)

## 5.1 OrgUnit
- orgunit_uuid (primary internal)
- orgunit_code (unique within type; human-readable; recommended)

## 5.2 Partnership (Container)
- partnership_uuid (primary internal)
- partnership_code (globally unique; human-readable)
- status

## 5.3 Cycle
- cycle_uuid (primary internal)
- cycle_code (globally unique; human-readable)
- cycle_type (enum: program, course; default program)
- status, start_date, end_date
- is_control_group (boolean)
- partnership_id (FK to Partnership container, nullable)

## 5.4 Enrollment
- enrollment_uuid (primary internal)
- unique(cycle_id, user_id)

## 5.5 Classroom
- classroom_uuid (primary internal)
- classroom_name
- unique(school_id, classroom_name) recommended for matching

## 5.6 Child
- child_uuid (primary internal)
- child_fingerprint (computed; used for import matching with "Needs Review" fallback)

---

# 6) Query Patterns (Design Requirements)

HL Core must support efficient queries for:

1) Cycle roster:
- list all enrollments in a Cycle
- filter by role (Teacher/Mentor/Leader)
- filter by school

2) District/School reporting:
- list all participants under a District for a Cycle
- list all participants under a School for a Cycle

3) Mentor visibility:
- mentor enrollment → team → member enrollments (mentees) → progress summaries

4) Classroom assignments:
- list all teachers assigned to a classroom (including lead flag)
- list all classrooms assigned to a teacher

5) Child assessments generation:
- for each Cycle + Classroom + Teacher assignment → require one Child Assessment instance

---

# 7) Implementation Guidance (Not code)

Recommended storage:
- Use custom database tables for core entities and join tables.
- Keep WP posts/meta only for content (LearnDash courses/lessons).

Do not store core joins in ad-hoc user_meta/post_meta.

---

End of file.
