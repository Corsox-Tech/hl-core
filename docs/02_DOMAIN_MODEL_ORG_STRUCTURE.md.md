# Housman Learning Core Plugin — AI Library
## File: 02_DOMAIN_MODEL_ORG_STRUCTURE.md
Version: 1.1
Last Updated: 2026-02-24
Timezone: America/Bogota

---

# 0) Purpose

This document defines the **canonical domain model** (objects + relationships + constraints) for HL Core.

Rules:
- This is the system-of-record model. Do not rely on ad-hoc WP meta for core relationships.
- WordPress Users provide identity only; cohort participation is modeled via Enrollment.
- Org Structure persists across years; Cohorts are repeatable/iterable instances.

---

# 1) Entity List (Canonical Objects)

HL Core manages the following entities:

## 1.1 OrgUnit
Represents either a District or a School.

- OrgUnit.type ∈ { "district", "school" }
- OrgUnit is persistent (not tied to a Cohort).

OrgUnit relationships:
- A School OrgUnit may have a parent District OrgUnit (optional).
- A District OrgUnit may have many child School OrgUnits.

Rationale:
- A single "OrgUnit" object with a type enables hierarchy without duplicating schemas.

---

## 1.2 Cohort
A time-bounded implementation/run (formerly "cohort/cohort instance").

Cohort relationships:
- Cohort may be associated to:
  - one District OrgUnit (optional)
  - one or more School OrgUnits (required at least 1)
  - one Cohort Group (optional; see §1.13)

Cohort flags:
- is_control_group (boolean, default false) — When true, indicates this cohort is a research control group. Control cohorts receive assessment-only pathways (no courses, coaching, observations). Admin UI hides Coaching and Teams tabs for control cohorts. See doc 06 §6 for control group assessment workflow.

Important:
- District is optional. A single-school Cohort has no District association.

---

## 1.3 Enrollment
Join object: (User ↔ Cohort)

Enrollment relationships:
- Enrollment.user_id → WP User
- Enrollment.cohort_id → Cohort

Enrollment holds:
- cohort_roles (set)
- pathway assignments (see 04_COHORT_PATHWAYS_ACTIVITIES_RULES.md)
- scope bindings (school/district as applicable)
- status (active/inactive)

Key rule:
- Cohort Roles live on Enrollment, not WP user.

---

## 1.4 Team
Mentorship group inside a School for a specific Cohort.

Team relationships:
- Team.cohort_id → Cohort
- Team.school_id → OrgUnit(type=school)

Team membership is represented by TeamMembership (below).

---

## 1.5 TeamMembership
Join object: (Enrollment ↔ Team)

TeamMembership relationships:
- TeamMembership.team_id → Team
- TeamMembership.enrollment_id → Enrollment

TeamMembership fields:
- membership_type ∈ { "mentor", "member" }
  - mentor = team mentor(s)
  - member = teacher participants assigned as mentees

Constraints:
- An Enrollment can belong to **at most one Team per Cohort**.
  - Implementation: enforce uniqueness of (cohort_id, enrollment_id) across team memberships.
- A Team can have 1–2 mentors (soft constraint; enforce at UI/service layer).

Notes:
- membership_type exists to support teams containing both mentors and members while remaining explicit.

---

## 1.6 Classroom
A classroom belonging to a School (persistent across Cohorts).

Classroom relationships:
- Classroom.school_id → OrgUnit(type=school)

Classrooms exist independently of Cohorts, but Cohorts reference "current" classroom rosters and assignments.

---

## 1.7 TeachingAssignment
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

## 1.8 Child
Child record belonging to a School.

Child relationships:
- Child.school_id → OrgUnit(type=school)

Identity:
- HL Core generates immutable child_uuid.
- External identifiers may be missing/unreliable.

---

## 1.9 ChildClassroomAssignment (Current)
Represents current placement of a Child in a Classroom.

Relationships:
- ChildClassroomAssignment.child_id → Child
- ChildClassroomAssignment.classroom_id → Classroom

Constraint:
- A Child has exactly one current classroom assignment at a time.

---

## 1.10 ChildClassroomHistory (Optional but Recommended)
Stores history of child movement between classrooms.

Relationships:
- ChildClassroomHistory.child_id → Child
- ChildClassroomHistory.classroom_id → Classroom

Fields:
- start_date
- end_date
- reason (optional)

---

## 1.11 Observation
Mentor-submitted form artifact.

Relationships (recommended):
- Observation.cohort_id → Cohort
- Observation.mentor_enrollment_id → Enrollment
- Observation.teacher_enrollment_id → Enrollment (optional but recommended)
- Observation.school_id → OrgUnit(type=school) (optional)
- Observation.classroom_id → Classroom (optional)

Observation supports:
- attachments (images/videos)
- flexible frequency

---

## 1.12 CoachingSession
Coach-submitted artifact linked to a mentor and optionally observations.

Relationships (recommended):
- CoachingSession.cohort_id → Cohort
- CoachingSession.coach_user_id → WP User (staff)
- CoachingSession.mentor_enrollment_id → Enrollment
- CoachingSession.related_observation_ids[] → Observation (0..n)

Fields:
- attendance_status ∈ { "attended", "missed", "unknown" }
- notes (rich text)
- attachments

---

## 1.13 CohortGroup
A container that groups related Cohorts together for program-level reporting and research comparison.

CohortGroup fields:
- group_id (PK)
- group_uuid
- group_name
- group_code (unique)
- description (text)
- status ∈ { "active", "archived" }

CohortGroup relationships:
- CohortGroup has many Cohorts (via Cohort.cohort_group_id FK)
- A Cohort may belong to at most one CohortGroup

Purpose:
- Groups related cohorts for cross-cohort reporting (e.g., multiple phases of the same program)
- Enables program-vs-control comparison reporting when the group contains both program cohorts (is_control_group=false) and control cohorts (is_control_group=true)
- Comparison metrics include per-section/per-item assessment means, pre-to-post change, and Cohen's d effect size

Example:
- "B2E Mastery - Lutheran Services Florida" group containing:
  - "Lutheran B2E Phase 1 2026" (program cohort, is_control_group=false)
  - "Lutheran Control Group 2026" (control cohort, is_control_group=true)

---

# 2) Relationship Diagram (Text)

CohortGroup (optional)
  └── Cohort [1..n]

OrgUnit(district)
  └── OrgUnit(school) [0..n]

OrgUnit(school)
  ├── Classroom [0..n]
  │     └── ChildClassroomAssignment (current)
  │           └── Child [0..n]
  └── Team [0..n] (per Cohort)

Cohort (optionally in a CohortGroup; optionally is_control_group=true)
  ├── Enrollment [0..n] (User ↔ Cohort)
  │     ├── TeamMembership [0..1 per Cohort] → Team
  │     └── TeachingAssignment [0..n] → Classroom
  ├── Pathways/Activities (see doc 04/05)
  ├── Teacher Self-Assessments (see doc 06)
  ├── Children Assessments (see doc 06)
  ├── Observation [0..n]
  └── CoachingSession [0..n]

---

# 3) Constraints (Hard vs Soft)

## 3.1 Hard Constraints (Must enforce at DB or service layer)
- Enrollment is unique per (cohort_id, user_id)
- TeamMembership uniqueness: each enrollment_id can be in at most one team per cohort
- Classroom belongs to exactly one School
- Team belongs to exactly one Cohort and one School
- Child belongs to exactly one School
- Child has exactly one current classroom assignment

## 3.2 Soft Constraints (Enforce in UI/logic; allow override if needed)
- Team has 1–2 mentors (membership_type=mentor)
- Team has 4–7 members (typical historical pattern; not required)
- Observation may or may not be tied to a classroom/date; attachments optional but supported

---

# 4) Role Rules that Affect the Model

Cohort roles are stored on Enrollment as a set:
- Teacher
- Mentor
- School Leader
- District Leader

Notes:
- A user can be both School Leader and Mentor in the same Cohort (allowed).
- Leaders are few; manual assignment is acceptable.

Role-driven filtering requirements:
- When selecting District Leaders for a District, UI must filter to enrollments in that Cohort with role District Leader.
- When selecting School Leaders for a School, UI must filter to enrollments in that Cohort with role School Leader.
- When selecting Team Mentors, UI must filter to enrollments in that Cohort with role Mentor (and optionally allow leaders with Mentor role).

---

# 5) Identity Keys (Recommended)

## 5.1 OrgUnit
- orgunit_uuid (primary internal)
- orgunit_code (unique within type; human-readable; recommended)

## 5.2 Cohort
- cohort_uuid (primary internal)
- cohort_code (globally unique; human-readable)
- status, start_date, end_date
- is_control_group (boolean)
- cohort_group_id (FK, nullable)

## 5.2b CohortGroup
- group_uuid (primary internal)
- group_code (globally unique; human-readable)
- status

## 5.3 Enrollment
- enrollment_uuid (primary internal)
- unique(cohort_id, user_id)

## 5.4 Classroom
- classroom_uuid (primary internal)
- classroom_name
- unique(school_id, classroom_name) recommended for matching

## 5.5 Child
- child_uuid (primary internal)
- child_fingerprint (computed; used for import matching with "Needs Review" fallback)

---

# 6) Query Patterns (Design Requirements)

HL Core must support efficient queries for:

1) Cohort roster:
- list all enrollments in a Cohort
- filter by role (Teacher/Mentor/Leader)
- filter by school

2) District/School reporting:
- list all participants under a District for a Cohort
- list all participants under a School for a Cohort

3) Mentor visibility:
- mentor enrollment → team → member enrollments (mentees) → progress summaries

4) Classroom assignments:
- list all teachers assigned to a classroom (including lead flag)
- list all classrooms assigned to a teacher

5) Children assessments generation:
- for each Cohort + Classroom + Teacher assignment → require one Children Assessment instance

---

# 7) Implementation Guidance (Not code)

Recommended storage:
- Use custom database tables for core entities and join tables.
- Keep WP posts/meta only for content (LearnDash courses/lessons).

Do not store core joins in ad-hoc user_meta/post_meta.

---

End of file.
