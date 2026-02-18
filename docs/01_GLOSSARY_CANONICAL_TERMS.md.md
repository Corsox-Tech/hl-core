# Housman Learning Core Plugin — AI Library
## File: 01_GLOSSARY_CANONICAL_TERMS.md
Version: 1.0
Last Updated: 2026-02-13
Timezone: America/Bogota

---

# 0) How to Use This Glossary

This file defines **canonical terms** used by the HL Core plugin and all other library documents.

Rules:
1) **Do not invent new terms** for existing concepts. If a new concept is needed, add it here first.
2) Use the **exact capitalization** and spelling shown below for canonical terms.
3) If UI labels differ from canonical terms, UI labels must map to canonical terms explicitly.

---

# 1) Canonical Top-Level Terms

## 1.1 Cohort
**Definition**: A Cohort is a time-bounded run/implementation for a client, containing participants, configuration, learning requirements, and reporting.
Examples:
- "ELCPB - 2026"
- "Sunrise Center - Spring 2026"

**Cohort contains**:
- Cohort Participants (via Enrollment)
- Pathways and Activities (configuration)
- Teams, Classrooms, Children rosters (data)
- Progress state (LearnDash + HL Core)
- Assessments, Observations, Coaching Sessions (artifacts)
- Reports and exports

**Minimum required fields (conceptual)**:
- cohort_name (display name)
- cohort_code (unique human-readable identifier)
- status (draft/active/paused/archived)
- start_date (required; can be future)
- end_date (optional)

**DO NOT USE**:
- "Program" (deprecated; replaced by Cohort)

---

## 1.2 District
**Definition**: Optional organizational parent grouping one or more Centers.  
A District can exist independently of Cohorts and persists across years.

Notes:
- Some Cohorts have no District (single-center clients).
- District Leaders are participants in a Cohort and gain district-scope report access for that Cohort.

**DO NOT USE**:
- "School District" (use District)

---

## 1.3 Center
**Definition**: A site (center/school) where Classrooms exist and Teachers work.  
A Center can optionally belong to a District.

Notes:
- Center persists across years.
- Centers can participate in multiple Cohorts.

**Synonyms (UI labels allowed)**:
- "School" (allowed as a UI label only; canonical term remains Center)

**DO NOT USE**:
- "Institution" (deprecated; replaced by Center)

---

# 2) People, Participation, and Roles

## 2.1 User
**Definition**: A WordPress user account (identity).  
A User may participate in zero or more Cohorts.

Important:
- WP user role/caps are NOT used to represent cohort roles like Teacher/Mentor/Leader.

---

## 2.2 Enrollment
**Definition**: Enrollment is the join between (User ↔ Cohort).
Enrollment is the canonical place to store cohort participation details.

Key rule:
- **Cohort Roles MUST be stored on Enrollment, not on the WP User**.
  This preserves history across Cohorts.

Enrollment conceptually stores:
- cohort_id
- user_id
- cohort_roles (one or more roles; see below)
- pathway_assignment (Teacher/Mentor/Leader pathway selection; may be manual for leaders)
- scope bindings (center_id and/or district_id where applicable)
- status (active/inactive) within the Cohort

---

## 2.3 Cohort Roles
**Definition**: Roles assigned to an Enrollment within a Cohort.

Allowed values (canonical):
- Teacher
- Mentor
- Center Leader
- District Leader

Notes:
- A User may have different Cohort Roles across different Cohorts.
- Within a Cohort, some Users may hold multiple Cohort Roles (rare; allowed).
- Leaders are few and may be managed manually.

**DO NOT USE**:
- "Standard Teacher" (use Teacher)
- "Streamlined user" (use District Leader or Center Leader role + a pathway assignment if needed)

---

## 2.4 Housman Staff Roles (System-level)
These are not Cohort Roles. They represent Housman internal access.

Canonical:
- Housman Admin: WordPress administrator; full system permissions
- Coach: Housman staff; elevated permissions (create users; view assessments; mark coaching sessions)

Notes:
- Staff roles exist outside any Cohort.
- Staff can act across Cohorts.

---

## 2.5 Participant
**Definition**: Any User who has an Enrollment in a Cohort.
A Participant is a User in the context of a specific Cohort.

---

# 3) Mentorship Structure

## 3.1 Team
**Definition**: A mentorship group inside a Center for a specific Cohort.

Team characteristics:
- Belongs to exactly one Cohort
- Belongs to exactly one Center
- Contains:
  - 1-2 Mentors (selected from Participants with Cohort Role Mentor OR manually permitted)
  - multiple Teachers (selected from Participants with Cohort Role Teacher and/or Mentor if allowed)

Constraint:
- A Participant can belong to **at most one Team per Cohort**.

Notes:
- Teams do NOT determine classroom assignment. Teachers can teach multiple classrooms regardless of team.

---

# 4) Classrooms and Teaching Assignments

## 4.1 Classroom
**Definition**: A classroom belonging to a Center.  
Classrooms exist independently of Cohorts, but Cohorts can reference the current roster.

---

## 4.2 Teaching Assignment
**Definition**: The relationship between a Teacher (participant) and a Classroom (many-to-many).

Teaching Assignment includes:
- teacher (user_id, in Cohort context)
- classroom_id
- is_lead_teacher (boolean)
- effective dates (optional; recommended if tracking mid-cohort changes)

Rules:
- A Teacher may teach in multiple Classrooms.
- A Classroom may have multiple Teachers.
- Lead Teacher is required for Classroom display and admin clarity, but does not affect assessment requirements.

---

# 5) Children and Classroom Membership

## 5.1 Child
**Definition**: A child record belonging to a Center, assigned to a Classroom.

Important constraints:
- Child data may have weak identifiers from clients.
- HL Core must generate an internal immutable child_uuid.

---

## 5.2 Child Classroom Assignment
**Definition**: Current classroom placement for a Child.

Notes:
- Children may change classrooms mid-Cohort (assume possible).
- History retention is recommended.

---

# 6) Learning Configuration

## 6.1 Pathway
**Definition**: A configurable set/graph of required Activities assigned to Participants in a Cohort.

Properties:
- Defined per Cohort
- Usually defined per Cohort Role (Teacher, Mentor, Leaders)
- Can differ between Cohorts and between centers within a Cohort if manually configured

---

## 6.2 Activity
**Definition**: A single requirement in a Pathway.

Supported Activity types (v1):
- LearnDash Course (progress percent from LearnDash)
- Teacher Self-Assessment (HL Core form; completion 0/100)
- Children Assessment (HL Core form; completion 0/100)
- Coaching Session Attendance (HL Core record; completion 0/100, set by Coach/Admin)
- Observation (HL Core form; parallel requirement; may be excluded from pathway UI)

---

## 6.3 Prerequisite
**Definition**: A dependency rule between Activities.
An Activity may require completion of one or multiple other Activities.

---

## 6.4 Drip / Release Rule
**Definition**: A time gate limiting when an Activity can become available.

Allowed drip styles:
- Fixed calendar date (set by Housman Admin)
- Completion-based delay (e.g., X days after Activity A completion)

Rule:
- If both prereqs and drip exist, **both must be satisfied**.
- "Most restrictive wins" = apply all gates.

---

## 6.5 Override
**Definition**: An administrative action that bypasses standard rules.

Canonical override types:
- Exempt: marks activity complete without completion actions (Admin/Coach)
- Manual unlock: makes activity available despite locks (Admin)

Optional (nice-to-have):
- Grace unlock: availability without prereqs (discouraged; only if implemented cleanly)

---

# 7) Assessments and Related Artifacts

## 7.1 Teacher Self-Assessment
**Definition**: A required assessment completed by each Teacher participant.
- Pre and Post are the same questionnaire.
- Store responses; no scoring required.

Privacy:
- Responses visible only to Housman Admin and Coach.
- Completion visible per reporting permissions.

---

## 7.2 Children Assessment
**Definition**: Classroom-based assessment completed by each Teacher for each Classroom they teach in.

Key rules:
- Required per (Cohort, Classroom, Teacher).
- If a Teacher teaches 2 classrooms, they must complete 2 children assessments.
- Instruments by age band:
  - Infant
  - Toddler
  - Preschool

Privacy:
- Responses visible only to Housman Admin and Coach.
- Completion visible per reporting permissions.

---

## 7.3 Observation
**Definition**: A form submitted by a Mentor about a Teacher’s classroom practice.
- Frequency is flexible.
- Attachments allowed (images/videos).
- Used as preparation material for Coaching Sessions.

Privacy:
- Default staff-visible; mentor/teacher visibility is configurable but not required in v1.

---

## 7.4 Coaching Session
**Definition**: A session logged by a Coach for a Mentor.
- Includes attendance (complete/incomplete) + notes + attachments.
- Links to one or more Observations for context/prep.

Privacy:
- Coach/Admin visible by default; mentor visibility configurable but not required in v1.

---

# 8) Reporting and Metrics

## 8.1 Activity Completion
- LearnDash Course: use LearnDash completion percentage (0–100)
- Non-course activities: completion is binary (0 or 100)

---

## 8.2 Cohort Completion Percentage
**Definition**: Weighted average across all Activities assigned to a Participant in a Cohort.
- Default weight=1 unless configured otherwise.
- Non-staff reports show completion only; assessment responses remain hidden.

---

# 9) Identity and Matching (Imports)

## 9.1 User Identity
Users are matched by email (unique).

---

## 9.2 Child Identity
Child identity may lack stable external IDs.
HL Core must:
- generate child_uuid
- compute a deterministic "fingerprint" for matching
- support "Needs Review" for ambiguous matches in import preview

---

End of file.
