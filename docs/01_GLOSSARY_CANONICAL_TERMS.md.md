# Housman Learning Core Plugin — AI Library
## File: 01_GLOSSARY_CANONICAL_TERMS.md
Version: 2.0
Last Updated: 2026-02-25
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
**Definition**: A Cohort is the contract/container entity — the biggest organizational entity. It groups one or more Tracks together for a client or program wave.

Examples:
- "B2E Mastery - Lutheran Services Florida"
- "B2E Mastery - ELC Palm Beach 2026"

**Cohort contains**:
- One or more Tracks (time-bounded runs)
- Program-level configuration and reporting aggregation
- Cross-track comparison reporting (program vs control)

**Minimum required fields (conceptual)**:
- cohort_name (display name)
- cohort_code (unique human-readable identifier)
- cohort_uuid (primary internal)
- status (active/archived)
- description (optional)

**Table**: `hl_cohort`
**PK**: `cohort_id`

**DO NOT USE**:
- "CohortGroup" (deprecated; absorbed into Cohort)
- "Program Group" (deprecated; replaced by Cohort)

---

## 1.2 Track
**Definition**: A Track is a time-bounded run/implementation within a Cohort, containing participants, configuration, learning requirements, and reporting.

Examples:
- "ELCPB - 2026" (a program track within its Cohort)
- "Sunrise School - Spring 2026"
- "Lutheran Control Group 2026" (a control track within a Cohort)

**Track contains**:
- Track Participants (via Enrollment)
- Pathways and Activities (configuration)
- Teams, Classrooms, Children rosters (data)
- Progress state (LearnDash + HL Core)
- Assessments, Observations, Coaching Sessions (artifacts)
- Reports and exports

**Minimum required fields (conceptual)**:
- track_name (display name)
- track_code (unique human-readable identifier)
- track_uuid (primary internal)
- status (draft/active/paused/archived)
- start_date (required; can be future)
- end_date (optional)
- is_control_group (boolean; see §1.5)
- cohort_id (FK to Cohort container)

**Table**: `hl_track`
**PK**: `track_id`
**FK**: `cohort_id` → `hl_cohort`

**DO NOT USE**:
- "Cohort" when referring to the run entity (use Track)
- "Program" (deprecated; replaced by Track)

---

## 1.3 District
**Definition**: Optional organizational parent grouping one or more Schools.
A District can exist independently of Tracks and persists across years.

Notes:
- Some Tracks have no District (single-school clients).
- District Leaders are participants in a Track and gain district-scope report access for that Track.

**DO NOT USE**:
- "School District" (use District)

---

## 1.4 School
**Definition**: A site (school) where Classrooms exist and Teachers work.
A School can optionally belong to a District.

Notes:
- School persists across years.
- Schools can participate in multiple Tracks.

**Synonyms (UI labels allowed)**:
- "School" (canonical term)

**DO NOT USE**:
- "Center" (deprecated; replaced by School)
- "Institution" (deprecated; replaced by School)

---

## 1.5 Control Group (Research Design)
**Definition**: A track flagged with `is_control_group = true` whose participants do NOT receive the full B2E Mastery program. Control group participants complete only assessment activities (Teacher Self-Assessment Pre/Post and Child Assessment Pre/Post) for research comparison.

**Purpose**: Housman measures program impact by comparing:
- **Program track** participants: full curriculum, coaching, observations + assessments
- **Control track** participants: assessments only (no courses, coaching, observations)

The difference in pre-to-post assessment change between program and control groups indicates program effectiveness.

**UI/UX adaptations for control tracks:**
- Admin track list shows purple "Control" badge
- Tabbed track editor hides Coaching and Teams tabs
- Frontend Track Workspace hides Teams tab
- Assessment-only pathway (no course or coaching activities)

**DO NOT USE**:
- "Comparison group" (use Control Group)

---

# 2) People, Participation, and Roles

## 2.1 User
**Definition**: A WordPress user account (identity).
A User may participate in zero or more Tracks.

Important:
- WP user role/caps are NOT used to represent track roles like Teacher/Mentor/Leader.

---

## 2.2 Enrollment
**Definition**: Enrollment is the join between (User ↔ Track).
Enrollment is the canonical place to store track participation details.

Key rule:
- **Track Roles MUST be stored on Enrollment, not on the WP User**.
  This preserves history across Tracks.

Enrollment conceptually stores:
- track_id
- user_id
- track_roles (one or more roles; see below)
- pathway_assignment (Teacher/Mentor/Leader pathway selection; may be manual for leaders)
- scope bindings (school_id and/or district_id where applicable)
- status (active/inactive) within the Track

---

## 2.3 Track Roles
**Definition**: Roles assigned to an Enrollment within a Track.

Allowed values (canonical):
- Teacher
- Mentor
- School Leader
- District Leader

Notes:
- A User may have different Track Roles across different Tracks.
- Within a Track, some Users may hold multiple Track Roles (rare; allowed).
- Leaders are few and may be managed manually.

**DO NOT USE**:
- "Standard Teacher" (use Teacher)
- "Streamlined user" (use District Leader or School Leader role + a pathway assignment if needed)

---

## 2.4 Housman Staff Roles (System-level)
These are not Track Roles. They represent Housman internal access.

Canonical:
- Housman Admin: WordPress administrator; full system permissions
- Coach: Housman staff; elevated permissions (create users; view assessments; mark coaching sessions)

Notes:
- Staff roles exist outside any Track.
- Staff can act across Tracks.

---

## 2.5 Participant
**Definition**: Any User who has an Enrollment in a Track.
A Participant is a User in the context of a specific Track.

---

# 3) Mentorship Structure

## 3.1 Team
**Definition**: A mentorship group inside a School for a specific Track.

Team characteristics:
- Belongs to exactly one Track
- Belongs to exactly one School
- Contains:
  - 1-2 Mentors (selected from Participants with Track Role Mentor OR manually permitted)
  - multiple Teachers (selected from Participants with Track Role Teacher and/or Mentor if allowed)

Constraint:
- A Participant can belong to **at most one Team per Track**.

Notes:
- Teams do NOT determine classroom assignment. Teachers can teach multiple classrooms regardless of team.
- Control group tracks typically do NOT use Teams (since there is no mentorship).

---

# 4) Classrooms and Teaching Assignments

## 4.1 Classroom
**Definition**: A classroom belonging to a School.
Classrooms exist independently of Tracks, but Tracks can reference the current roster.

---

## 4.2 Teaching Assignment
**Definition**: The relationship between a Teacher (participant) and a Classroom (many-to-many).

Teaching Assignment includes:
- teacher (user_id, in Track context)
- classroom_id
- is_lead_teacher (boolean)
- effective dates (optional; recommended if tracking mid-track changes)

Rules:
- A Teacher may teach in multiple Classrooms.
- A Classroom may have multiple Teachers.
- Lead Teacher is required for Classroom display and admin clarity, but does not affect assessment requirements.

---

# 5) Children and Classroom Membership

## 5.1 Child
**Definition**: A child record belonging to a School, assigned to a Classroom.

Important constraints:
- Child data may have weak identifiers from clients.
- HL Core must generate an internal immutable child_uuid.

---

## 5.2 Child Classroom Assignment
**Definition**: Current classroom placement for a Child.

Notes:
- Children may change classrooms mid-Track (assume possible).
- History retention is recommended.

---

# 6) Learning Configuration

## 6.1 Pathway
**Definition**: A configurable set/graph of required Activities assigned to Participants in a Track.

Properties:
- Defined per Track
- Usually defined per Track Role (Teacher, Mentor, Leaders)
- Can differ between Tracks and between schools within a Track if manually configured
- Control group tracks typically have a single assessment-only pathway

---

## 6.2 Activity
**Definition**: A single requirement in a Pathway.

Supported Activity types (v1):
- LearnDash Course (progress percent from LearnDash)
- Teacher Self-Assessment (Custom PHP form via HL Core instrument system; completion 0/100)
- Child Assessment (Custom PHP form via HL Core instrument system; completion 0/100)
- Coaching Session Attendance (HL Core record; completion 0/100, set by Coach/Admin)
- Observation (JFB form; parallel requirement; may be excluded from pathway UI)

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
**Definition**: A required assessment completed by each Teacher participant, rendered by HL Core's custom Teacher Assessment system (not JetFormBuilder).
- PRE version: single-column ratings for all 3 sections (Practices, Wellbeing, Self-Regulation)
- POST version: Section 1 has dual-column retrospective ("Before Program" + "Now"), Sections 2-3 are single-column

The instrument definition (sections, items, scales) is stored as structured JSON in `hl_teacher_assessment_instrument`. Responses are stored as JSON in `hl_teacher_assessment_instance.responses_json`.

Privacy:
- Responses visible only to Housman Admin and Coach.
- Completion visible per reporting permissions.

---

## 7.2 Child Assessment
**Definition**: Classroom-based assessment completed by each Teacher for their assigned children, rendered by HL Core's custom instrument system.

Key rules:
- The teacher completes ONE child assessment activity per assessment period (pre or post)
- That single activity covers ALL children in the teacher's assigned classrooms
- The form lists each child with their age band and the age-appropriate question + rating scale
- Children no longer in the classroom can be marked "No longer enrolled" (skipped)

There are 4 age-band variants (Infant, Toddler, Preschool/Pre-K, K-2nd Grade), each with a single question on a 5-point scale (Never -> Almost Always) with example behaviors per scale point.

Privacy:
- Responses visible only to Housman Admin and Coach.
- Completion visible per reporting permissions.

---

## 7.3 Observation
**Definition**: A form submitted by a Mentor about a Teacher's classroom practice.
- Frequency is flexible.
- Attachments allowed (images/videos).
- Used as preparation material for Coaching Sessions.
- **Rendered via JetFormBuilder** (the only remaining JFB-powered assessment type).

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
- LearnDash Course: use LearnDash completion percentage (0-100)
- Non-course activities: completion is binary (0 or 100)

---

## 8.2 Track Completion Percentage
**Definition**: Weighted average across all Activities assigned to a Participant in a Track.
- Default weight=1 unless configured otherwise.
- Non-staff reports show completion only; assessment responses remain hidden.

---

## 8.3 Program vs Control Comparison
**Definition**: When a Cohort contains both program and control tracks, the reporting system can compute side-by-side comparison of assessment outcomes.

Metrics:
- Per-section and per-item mean scores for PRE and POST phases
- Pre-to-post change (delta) for each group
- Cohen's d effect size (difference in change between groups, normalized by pooled standard deviation)

This comparison uses `responses_json` from `hl_teacher_assessment_instance` and child assessment data.

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
