# Housman Learning Core Plugin — AI Library
## File: 02_DOMAIN_MODEL_ORG_STRUCTURE.md
Version: 2.0
Last Updated: 2026-02-25
Timezone: America/Bogota

---

# 0) Purpose

This document defines the **canonical domain model** (objects + relationships + constraints) for HL Core.

Rules:
- This is the system-of-record model. Do not rely on ad-hoc WP meta for core relationships.
- WordPress Users provide identity only; track participation is modeled via Enrollment.
- Org Structure persists across years; Tracks are repeatable/iterable instances within Cohorts.

---

# 1) Entity List (Canonical Objects)

HL Core manages the following entities:

## 1.1 OrgUnit
Represents either a District or a School.

- OrgUnit.type ∈ { "district", "school" }
- OrgUnit is persistent (not tied to a Track).

OrgUnit relationships:
- A School OrgUnit may have a parent District OrgUnit (optional).
- A District OrgUnit may have many child School OrgUnits.

Rationale:
- A single "OrgUnit" object with a type enables hierarchy without duplicating schemas.

---

## 1.2 Cohort
The contract/container entity — the biggest organizational entity. Groups one or more Tracks together.

Cohort fields:
- cohort_id (PK)
- cohort_uuid (primary internal)
- cohort_name
- cohort_code (globally unique; human-readable)
- description (text)
- status ∈ { "active", "archived" }

Cohort relationships:
- Cohort has many Tracks (via Track.cohort_id FK)

Purpose:
- Groups related tracks for cross-track reporting (e.g., multiple phases of the same program)
- Enables program-vs-control comparison reporting when the cohort contains both program tracks (is_control_group=false) and control tracks (is_control_group=true)
- Comparison metrics include per-section/per-item assessment means, pre-to-post change, and Cohen's d effect size

Example:
- "B2E Mastery - Lutheran Services Florida" cohort containing:
  - "Lutheran B2E Phase 1 2026" (program track, is_control_group=false)
  - "Lutheran Control Group 2026" (control track, is_control_group=true)

---

## 1.3 Track
A time-bounded implementation/run within a Cohort (formerly "Cohort").

Track relationships:
- Track.cohort_id → Cohort (FK to container)
- Track may be associated to:
  - one District OrgUnit (optional)
  - one or more School OrgUnits (required at least 1)

Track flags:
- is_control_group (boolean, default false) — When true, indicates this track is a research control group. Control tracks receive assessment-only pathways (no courses, coaching, observations). Admin UI hides Coaching and Teams tabs for control tracks. See doc 06 §6 for control group assessment workflow.

Important:
- District is optional. A single-school Track has no District association.

---

## 1.4 Enrollment
Join object: (User ↔ Track)

Enrollment relationships:
- Enrollment.user_id → WP User
- Enrollment.track_id → Track

Enrollment holds:
- track_roles (set)
- pathway assignments (see 04_COHORT_PATHWAYS_ACTIVITIES_RULES.md)
- scope bindings (school/district as applicable)
- status (active/inactive)

Key rule:
- Track Roles live on Enrollment, not WP user.

---

## 1.5 Team
Mentorship group inside a School for a specific Track.

Team relationships:
- Team.track_id → Track
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
- An Enrollment can belong to **at most one Team per Track**.
  - Implementation: enforce uniqueness of (track_id, enrollment_id) across team memberships.
- A Team can have 1–2 mentors (soft constraint; enforce at UI/service layer).

Notes:
- membership_type exists to support teams containing both mentors and members while remaining explicit.

---

## 1.7 Classroom
A classroom belonging to a School (persistent across Tracks).

Classroom relationships:
- Classroom.school_id → OrgUnit(type=school)

Classrooms exist independently of Tracks, but Tracks reference "current" classroom rosters and assignments.

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

Constraint:
- A Child has exactly one current classroom assignment at a time.

---

## 1.11 ChildClassroomHistory (Optional but Recommended)
Stores history of child movement between classrooms.

Relationships:
- ChildClassroomHistory.child_id → Child
- ChildClassroomHistory.classroom_id → Classroom

Fields:
- start_date
- end_date
- reason (optional)

---

## 1.12 Observation
Mentor-submitted form artifact.

Relationships (recommended):
- Observation.track_id → Track
- Observation.mentor_enrollment_id → Enrollment
- Observation.teacher_enrollment_id → Enrollment (optional but recommended)
- Observation.school_id → OrgUnit(type=school) (optional)
- Observation.classroom_id → Classroom (optional)

Observation supports:
- attachments (images/videos)
- flexible frequency

---

## 1.13 CoachingSession
Coach-submitted artifact linked to a mentor and optionally observations.

Relationships (recommended):
- CoachingSession.track_id → Track
- CoachingSession.coach_user_id → WP User (staff)
- CoachingSession.mentor_enrollment_id → Enrollment
- CoachingSession.related_observation_ids[] → Observation (0..n)

Fields:
- attendance_status ∈ { "attended", "missed", "unknown" }
- notes (rich text)
- attachments

---

# 2) Relationship Diagram (Text)

Cohort (container)
  └── Track [1..n]

OrgUnit(district)
  └── OrgUnit(school) [0..n]

OrgUnit(school)
  ├── Classroom [0..n]
  │     └── ChildClassroomAssignment (current)
  │           └── Child [0..n]
  └── Team [0..n] (per Track)

Track (within a Cohort; optionally is_control_group=true)
  ├── Enrollment [0..n] (User ↔ Track)
  │     ├── TeamMembership [0..1 per Track] → Team
  │     └── TeachingAssignment [0..n] → Classroom
  ├── Pathways/Activities (see doc 04/05)
  ├── Teacher Self-Assessments (see doc 06)
  ├── Child Assessments (see doc 06)
  ├── Observation [0..n]
  └── CoachingSession [0..n]

---

# 3) Constraints (Hard vs Soft)

## 3.1 Hard Constraints (Must enforce at DB or service layer)
- Enrollment is unique per (track_id, user_id)
- TeamMembership uniqueness: each enrollment_id can be in at most one team per track
- Classroom belongs to exactly one School
- Team belongs to exactly one Track and one School
- Child belongs to exactly one School
- Child has exactly one current classroom assignment

## 3.2 Soft Constraints (Enforce in UI/logic; allow override if needed)
- Team has 1–2 mentors (membership_type=mentor)
- Team has 4–7 members (typical historical pattern; not required)
- Observation may or may not be tied to a classroom/date; attachments optional but supported

---

# 4) Role Rules that Affect the Model

Track roles are stored on Enrollment as a set:
- Teacher
- Mentor
- School Leader
- District Leader

Notes:
- A user can be both School Leader and Mentor in the same Track (allowed).
- Leaders are few; manual assignment is acceptable.

Role-driven filtering requirements:
- When selecting District Leaders for a District, UI must filter to enrollments in that Track with role District Leader.
- When selecting School Leaders for a School, UI must filter to enrollments in that Track with role School Leader.
- When selecting Team Mentors, UI must filter to enrollments in that Track with role Mentor (and optionally allow leaders with Mentor role).

---

# 5) Identity Keys (Recommended)

## 5.1 OrgUnit
- orgunit_uuid (primary internal)
- orgunit_code (unique within type; human-readable; recommended)

## 5.2 Cohort (Container)
- cohort_uuid (primary internal)
- cohort_code (globally unique; human-readable)
- status

## 5.3 Track (Run)
- track_uuid (primary internal)
- track_code (globally unique; human-readable)
- status, start_date, end_date
- is_control_group (boolean)
- cohort_id (FK to Cohort container, nullable)

## 5.4 Enrollment
- enrollment_uuid (primary internal)
- unique(track_id, user_id)

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

1) Track roster:
- list all enrollments in a Track
- filter by role (Teacher/Mentor/Leader)
- filter by school

2) District/School reporting:
- list all participants under a District for a Track
- list all participants under a School for a Track

3) Mentor visibility:
- mentor enrollment → team → member enrollments (mentees) → progress summaries

4) Classroom assignments:
- list all teachers assigned to a classroom (including lead flag)
- list all classrooms assigned to a teacher

5) Child assessments generation:
- for each Track + Classroom + Teacher assignment → require one Child Assessment instance

---

# 7) Implementation Guidance (Not code)

Recommended storage:
- Use custom database tables for core entities and join tables.
- Keep WP posts/meta only for content (LearnDash courses/lessons).

Do not store core joins in ad-hoc user_meta/post_meta.

---

End of file.
