# Housman Learning Core Plugin — AI Library
## File: 01_GLOSSARY_CANONICAL_TERMS.md
Version: 4.0
Last Updated: 2026-03-24
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

## 1.1 Partnership
**Definition**: A Partnership is an optional container entity that groups one or more Cycles together for organizational purposes.

**Clarification (March 2026):** Partnership is optional. `hl_cycle.partnership_id` is nullable — Cycles can exist without a Partnership. The control group research workflow does NOT require Partnership grouping; statistical comparison (Cohen's d) happens in Stata from CSV exports. Partnership remains useful for admin organization and optional in-app comparison reporting.

Examples:
- "B2E Mastery - Lutheran Services Florida"
- "B2E Mastery - ELC Palm Beach 2026"
- A Cycle can also exist without any Partnership.

**Partnership contains**:
- One or more Cycles (optional grouping)
- Program-level configuration and reporting aggregation (when used)
- Cross-cycle comparison reporting (program vs control) — supplementary; primary analysis uses CSV export to Stata

**Minimum required fields (conceptual)**:
- partnership_name (display name)
- partnership_code (unique human-readable identifier)
- partnership_uuid (primary internal)
- status (active/archived)
- description (optional)

**Table**: `hl_partnership`
**PK**: `partnership_id`

**DO NOT USE**:
- "CohortGroup" (deprecated; absorbed into Partnership)
- "Program Group" (deprecated; replaced by Partnership)

---

## 1.2 Cycle
**Definition**: A Cycle represents the full program engagement for a district/institution. For the B2E Mastery Program, this spans the entire multi-year contract. A Cycle contains Pathways directly. Cycle is the level at which participants are enrolled, teams are formed, and scope is defined.

Examples:
- "ELCPB B2E Mastery 2025-2027" (a program cycle spanning 2 years)
- "Sunrise School - Spring 2026" (a short course cycle)
- "Lutheran Control Group 2025-2027" (a control cycle)

**Cycle contains**:
- Cycle Participants (via Enrollment)
- Pathways and Components (Pathways belong directly to the Cycle)
- Teams, Classrooms, Children rosters (data)
- Progress state (LearnDash + HL Core)
- Assessments, Observations, Coaching Sessions (artifacts)
- Reports and exports

**Minimum required fields (conceptual)**:
- cycle_name (display name)
- cycle_code (unique human-readable identifier)
- cycle_uuid (primary internal)
- cycle_type (enum: program, course; default program)
- status (draft/active/paused/archived)
- start_date (required; can be future)
- end_date (optional)
- is_control_group (boolean; see §1.5)
- partnership_id (FK to Partnership container, **nullable** — Cycle can exist without a Partnership)

**Table**: `hl_cycle`
**PK**: `cycle_id`
**FK**: `partnership_id` → `hl_partnership` (nullable)

**DO NOT USE**:
- "Cohort" when referring to the run entity (use Cycle)
- "Program" (deprecated; replaced by Cycle)

---

## 1.3 District
**Definition**: Optional organizational parent grouping one or more Schools.
A District can exist independently of Cycles and persists across years.

Notes:
- Some Cycles have no District (single-school clients).
- District Leaders are participants in a Cycle and gain district-scope report access for that Cycle.

**DO NOT USE**:
- "School District" (use District)

---

## 1.4 School
**Definition**: A site (school) where Classrooms exist and Teachers work.
A School can optionally belong to a District.

Notes:
- School persists across years.
- Schools can participate in multiple Cycles.

**Synonyms (UI labels allowed)**:
- "School" (canonical term)

**DO NOT USE**:
- "Center" (deprecated; replaced by School)
- "Institution" (deprecated; replaced by School)

---

## 1.5 Control Group (Research Design)
**Definition**: A cycle flagged with `is_control_group = true` whose participants do NOT receive the full B2E Mastery program. Control group participants complete only assessment components (Teacher Self-Assessment Pre/Post and Child Assessment Pre/Post) for research comparison.

**Purpose**: Housman measures program impact by comparing:
- **Program cycle** participants: full curriculum, coaching, observations + assessments
- **Control cycle** participants: assessments only (no courses, coaching, observations)

The difference in pre-to-post assessment change between program and control groups indicates program effectiveness.

**UI/UX adaptations for control cycles:**
- Admin cycle list shows purple "Control" badge
- Tabbed cycle editor hides Coaching and Teams tabs
- Frontend Cycle Workspace hides Teams tab
- Assessment-only pathway (no course or coaching components)

**DO NOT USE**:
- "Comparison group" (use Control Group)

---

## 1.6 Product & Program Terms

**B2E Mastery Program**
The primary professional development product sold by Housman Learning Academy to school districts and institutions. A 2-year (minimum) program consisting of 25 LearnDash courses organized into 3 Learning Plans. This is the product HL Core was primarily built for.

**Learning Plan**
Housman's client-facing term for what HL Core calls a Pathway. There are 3 Learning Plans: Teacher, Mentor, and Leader (Streamlined). The frontend may use "Learning Plan" or "Program" as labels.

**Course Catalog**
The complete set of LearnDash courses available in the B2E Mastery Program: TC0 (welcome), TC1–TC8 (full teacher), TC1(S)–TC8(S) (streamlined teacher), MC1–MC4 (full mentor), MC1(S)–MC4(S) (streamlined mentor). Total: 25 courses.

**Pilot**
A variable-scope program engagement where a district tries a subset of the B2E program before committing to the full contract. Can range from TC1–TC3 + MC1 to a Year 1 subset.

**Individual Enrollment**
A direct user-to-LearnDash-course association managed by HL Core for individual (non-institutional) course purchases. Stored in `hl_individual_enrollment`. Supports per-person expiration dates. Used for Short Courses and ECSELent Adventures online training purchases by individuals.

**Cycle Type**
A classification on `hl_cycle` distinguishing between full program Cycles (`program`) and simple single-course institutional Cycles (`course`). Course-type Cycles auto-generate a single Pathway + Component.

---

# 2) People, Participation, and Roles

## 2.1 User
**Definition**: A WordPress user account (identity).
A User may participate in zero or more Cycles.

Important:
- WP user role/caps are NOT used to represent cycle roles like Teacher/Mentor/Leader.

---

## 2.2 Enrollment
**Definition**: Enrollment is the join between (User ↔ Cycle).
Enrollment is the canonical place to store cycle participation details.

Key rule:
- **Cycle Roles MUST be stored on Enrollment, not on the WP User**.
  This preserves history across Cycles.

Enrollment conceptually stores:
- cycle_id
- user_id
- cycle_roles (one or more roles; see below)
- pathway_assignment (Teacher/Mentor/Leader pathway selection; may be manual for leaders)
- scope bindings (school_id and/or district_id where applicable)
- status (active/inactive) within the Cycle

---

## 2.3 Cycle Roles
**Definition**: Roles assigned to an Enrollment within a Cycle.

Allowed values (canonical):
- Teacher
- Mentor
- School Leader
- District Leader

Notes:
- A User may have different Cycle Roles across different Cycles.
- Within a Cycle, some Users may hold multiple Cycle Roles (rare; allowed).
- Leaders are few and may be managed manually.

**DO NOT USE**:
- "Standard Teacher" (use Teacher)
- "Streamlined user" (use District Leader or School Leader role + a pathway assignment if needed)

---

## 2.4 Housman Staff Roles (System-level)
These are not Cycle Roles. They represent Housman internal access.

Canonical:
- Housman Admin: WordPress administrator; full system permissions
- Coach: Housman staff; elevated permissions (create users; view assessments; mark coaching sessions)

Notes:
- Staff roles exist outside any Cycle.
- Staff can act across Cycles.

---

## 2.5 Participant
**Definition**: Any User who has an Enrollment in a Cycle.
A Participant is a User in the context of a specific Cycle.

---

# 3) Mentorship Structure

## 3.1 Team
**Definition**: A mentorship group inside a School for a specific Cycle.

Team characteristics:
- Belongs to exactly one Cycle
- Belongs to exactly one School
- Contains:
  - 1-2 Mentors (selected from Participants with Cycle Role Mentor OR manually permitted)
  - multiple Teachers (selected from Participants with Cycle Role Teacher and/or Mentor if allowed)

Constraint:
- A Participant can belong to **at most one Team per Cycle**.

Notes:
- Teams do NOT determine classroom assignment. Teachers can teach multiple classrooms regardless of team.
- Control group cycles typically do NOT use Teams (since there is no mentorship).

---

# 4) Classrooms and Teaching Assignments

## 4.1 Classroom
**Definition**: A classroom belonging to a School.
Classrooms exist independently of Cycles, but Cycles can reference the current roster.

---

## 4.2 Teaching Assignment
**Definition**: The relationship between a Teacher (participant) and a Classroom (many-to-many).

Teaching Assignment includes:
- teacher (user_id, in Cycle context)
- classroom_id
- is_lead_teacher (boolean)
- effective dates (optional; recommended if tracking mid-cycle changes)

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
- Children may change classrooms mid-Cycle (assume possible).
- History retention is recommended.
- Teachers can add/remove children from their classroom via the front-end Classroom Page. Removals are soft-deletes (status='teacher_removed') with reason and note.

---

## 5.3 Frozen Age Group
**Definition**: The age band (infant, toddler, preschool, k2) assigned to a child based on their date of birth, frozen at the time the child enters a Cycle.

Key rules:
- Calculated from DOB relative to the cycle's reference date (usually current date at freeze time).
- Once frozen, the age group does NOT change even if the child ages past a boundary during the cycle.
- Ensures research consistency: PRE and POST assessments use the same instrument/question per child.
- Stored in `hl_child_cycle_snapshot.frozen_age_group`.

---

## 5.4 Child Cycle Snapshot
**Definition**: A per-child, per-cycle record that captures the child's frozen age group at the time they are associated with a cycle.

Fields: child_id, cycle_id, frozen_age_group, dob_at_freeze, age_months_at_freeze, frozen_at.
Unique constraint: one snapshot per (child_id, cycle_id).

Created automatically when:
- `freeze_age_groups()` is called during assessment instance generation
- A teacher adds a child mid-cycle (auto-snapshot via `ensure_snapshot()`)

---

# 6) Learning Configuration

## 6.1 Pathway
**Definition**: A configurable set/graph of required Components assigned to Participants. Pathways belong directly to a Cycle. A Cycle typically has 3 Pathways (Teacher, Mentor, Leader), though this is configurable. For course-type Cycles, the Pathway is auto-generated.

Properties:
- Defined per Cycle
- Usually defined per Cycle Role (Teacher, Mentor, Leaders)
- Can differ between Cycles and between schools within a Cycle if manually configured
- Control group cycles typically have a single assessment-only pathway

---

## 6.2 Component
**Definition**: A single requirement in a Pathway.

Supported Component types:
- LearnDash Course (progress percent from LearnDash)
- Teacher Self-Assessment (Custom PHP form via HL Core instrument system; completion 0/100)
- Child Assessment (Custom PHP form via HL Core instrument system; completion 0/100)
- Coaching Session Attendance (HL Core record; completion 0/100, set by Coach/Admin)
- Observation (JFB form; parallel requirement; may be excluded from pathway UI)
- Self-Reflection (Custom PHP form submitted by teachers after each course; completion 0/100)
- Reflective Practice Session (Custom PHP RP session page with role-based views and form submissions for RP Notes + Action Plan; completion 0/100)
- Classroom Visit (Custom PHP form submitted by leaders visiting a teacher's classroom; completion 0/100)

---

## 6.3 Prerequisite
**Definition**: A dependency rule between Components.
A Component may require completion of one or multiple other Components.

---

## 6.4 Drip / Release Rule
**Definition**: A time gate limiting when a Component can become available.

Allowed drip styles:
- Fixed calendar date (set by Housman Admin)
- Completion-based delay (e.g., X days after Component A completion)

Rule:
- If both prereqs and drip exist, **both must be satisfied**.
- "Most restrictive wins" = apply all gates.

---

## 6.5 Override
**Definition**: An administrative action that bypasses standard rules.

Canonical override types:
- Exempt: marks component complete without completion actions (Admin/Coach)
- Manual unlock: makes component available despite locks (Admin)

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
- The teacher completes ONE child assessment component per assessment period (pre or post)
- That single component covers ALL children in the teacher's assigned classrooms
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
- Coaches and mentors can submit structured form responses (RP Notes + Action Plan) stored in `hl_coaching_session_submission`.

Privacy:
- Coach/Admin visible by default; mentor visibility configurable but not required in v1.

---

## 7.5 Reflective Practice (RP) Session
**Definition**: A structured reflective practice session between a Mentor and a Teacher within a Cycle. RP sessions are pathway components that facilitate guided reflection on classroom practice after each course.

The RP session page provides role-based views:
- **Coach view**: session prep notes (auto-populated from pathway progress, previous action plans, recent classroom visits), editable RP Notes + Action Plan forms
- **Mentor view**: editable RP Notes + Action Plan forms for mentoring context
- **Teacher view**: read-only view of completed RP Notes and Action Plan submissions

Form responses (RP Notes and Action Plan) are stored in `hl_rp_session_submission` with instrument-based structure.

Privacy:
- RP session data is visible to Staff (Admin/Coach) and the participating Mentor and Teacher.

---

## 7.6 Classroom Visit
**Definition**: A structured classroom observation conducted by a **Leader** (School Leader or District Leader) who visits a teacher's classroom. Unlike Observations (which are mentor-submitted via JFB), Classroom Visits use a custom PHP form and are initiated by leaders.

Stored in `hl_classroom_visit`. Form responses stored in `hl_classroom_visit_submission`.

Privacy:
- Visible to Staff, the visiting leader, and optionally the visited teacher.

---

## 7.7 Self-Reflection
**Definition**: A self-reflection form completed by a **Teacher** after each course. Provides structured self-assessment of how they are applying course concepts in their classroom.

Rendered by `HL_Frontend_Self_Reflection` using a custom PHP form. Self-reflection is a pathway component (component_type = `self_reflection`).

Privacy:
- Visible to the submitting teacher and Staff.

---

# 8) Reporting and Metrics

## 8.1 Component Completion
- LearnDash Course: use LearnDash completion percentage (0-100)
- Non-course components: completion is binary (0 or 100)

---

## 8.2 Cycle Completion Percentage
**Definition**: Weighted average across all Components assigned to a Participant in a Cycle.
- Default weight=1 unless configured otherwise.
- Non-staff reports show completion only; assessment responses remain hidden.

---

## 8.3 Program vs Control Comparison
**Definition**: When a Partnership contains both program and control cycles, the reporting system can compute side-by-side comparison of assessment outcomes.

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
