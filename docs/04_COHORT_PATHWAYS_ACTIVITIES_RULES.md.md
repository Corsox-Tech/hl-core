# Housman Learning Core Plugin — AI Library
## File: 04_COHORT_PATHWAYS_ACTIVITIES_RULES.md
Version: 2.0
Last Updated: 2026-02-25
Timezone: America/Bogota

---

# 0) Purpose

This document specifies how HL Core represents and configures:
- Pathways (per Track)
- Activities (per Pathway)
- Activity completion rules
- Role-to-pathway assignment rules
- How "parallel requirements" (Observations, Coaching) are handled

Rules:
- Tracks must be configurable per client and may differ across Tracks.
- Leaders are few; manual configuration is acceptable.
- HL Core reads LearnDash course progress but does not re-implement an LMS.

---

# 1) Configuration Overview

## 1.1 Track Configuration Layers
HL Core configuration for learning requirements exists at the Track level:

1) Track
2) Pathways within the Track
3) Activities within each Pathway
4) Unlock rules (Prereqs + Drip) applied to Activities (see doc 05)

A Track can have multiple Pathways. Typically:
- Teacher Pathway
- Mentor Pathway
- (Optional) Leader Pathway (Streamlined) — manual use only

---

# 2) Pathway

## 2.1 Definition
**Pathway** is a configurable set/graph of required Activities assigned to Participants in a Track.

A Pathway is defined by:
- pathway_id (internal)
- track_id
- pathway_name (e.g., "Teacher Pathway - Phase 1")
- pathway_code (unique within Track)
- target_roles (optional convenience metadata; not a permission system)
- active_status

## 2.2 Pathway Assignment
Participants receive Pathways via Enrollment.

Default v1 assignment rules:
- If Enrollment has Track Role Teacher → assign configured Teacher Pathway (default)
- If Enrollment has Track Role Mentor → assign configured Mentor Pathway (default)
- Leaders: manual assignment by Housman Admin (and/or Coach if permitted)

Manual overrides allowed:
- Admin can set a participant's assigned Pathway explicitly.

Important:
- A participant can hold multiple Track Roles, but v1 assumes **one primary assigned Pathway**.
- If a leader is also a mentor, Admin may assign Mentor Pathway as primary.

---

# 3) Activity

## 3.1 Definition
An **Activity** is a single requirement in a Pathway.

Activity has:
- activity_id (internal)
- track_id
- pathway_id
- activity_type (enumeration; below)
- title (display)
- description (optional)
- ordering_hint (optional; UI ordering only; does not define prerequisite logic)
- completion_rule (depends on type)
- visibility (who sees it; usually enrolled participants; some artifacts may be staff-only)
- weight (integer or float; default=1; used for completion % aggregation)
- external_ref (JSON; stores type-specific configuration — see each type below)

## 3.2 Supported Activity Types (v1)

### 3.2.1 LearnDash Course Activity
- activity_type = "learndash_course"
- external_ref: `{"course_id": <LearnDash post ID>}`

Completion:
- percent_complete = LearnDash course progress percent (0..100)
- completed = (LearnDash marks course completed OR percent==100)

Notes:
- HL Core reads LearnDash data; it does not manage LearnDash course structure.

---

### 3.2.2 Teacher Self-Assessment Activity (JetFormBuilder-powered)
- activity_type = "teacher_self_assessment"
- external_ref: `{"form_plugin": "jetformbuilder", "form_id": <JFB form ID>, "phase": "pre"|"post"}`

The form itself is created and managed in JetFormBuilder by Housman LMS Admins. HL Core links to it and tracks completion.

Admin workflow:
1. Admin creates a self-assessment form in JetFormBuilder (any field types, layout, conditional logic)
2. Admin adds a "Call Hook" post-submit action with hook name `hl_core_form_submitted`
3. Admin adds hidden fields to the form: `hl_enrollment_id`, `hl_activity_id`, `hl_track_id`
4. Admin creates an Activity in the Pathway, selects activity_type = "teacher_self_assessment", picks the JFB form from a dropdown, and selects phase (pre or post)

Completion:
- 0% until the linked JFB form is submitted for this enrollment
- 100% after submission (JFB fires hook → HL Core marks instance as submitted)

Privacy:
- Responses live in JFB Form Records. Only WP admin users (Housman Admin, Coach) can view them.
- Non-staff participants see only completion status (0/100) and submitted timestamps.

Instance tracking:
- HL Core maintains `hl_teacher_assessment_instance` for each (track, enrollment, phase) to track status and submitted_at.
- The instance stores a reference to the JFB submission ID for audit/export purposes.

If both PRE and POST are separate activities:
- each is independent 0/100.

---

### 3.2.3 Child Assessment Activity (Custom PHP — NOT JetFormBuilder)
- activity_type = "child_assessment"
- external_ref: `{"instrument_id": <HL instrument ID>}`

This activity type uses a custom PHP form because it is inherently dynamic: the form renders one row per child in the teacher's assigned classroom, using questions from the HL Core instrument definition.

Important assignment rule:
- Child assessment instances are generated per (Track, Classroom, Teacher assignment).
- This Activity represents the requirement category, while completion is computed from required instances.
- Completion for a teacher is 100% only when all required classroom instances are submitted.

Completion:
- 0% if any required classroom assessment instance is incomplete
- 100% when all required classroom assessment instances are complete

Privacy:
- Responses stored in `hl_child_assessment_childrow` (answers_json per child)
- Visible only to Housman Admin/Coach
- Completion visible per reporting permissions.

See doc 06 for full details on instance generation rules and the child assessment object model.

---

### 3.2.4 Coaching Session Attendance Activity
- activity_type = "coaching_session_attendance"
Completion:
- 0% until a Coach marks attendance/complete for the required session(s)
- 100% after completion

Notes:
- Coaching sessions are typically for Mentors.
- This activity can represent:
  - one required session, or
  - N required sessions (configurable)
- See doc 06 for coaching session records and linking to observations.

---

### 3.2.5 Observation Requirement (JetFormBuilder-powered, Parallel)
Observations are required artifacts for mentorship workflow.

The observation form itself is created and managed in JetFormBuilder. HL Core tracks the observation record (who observed whom, which classroom) and links it to coaching sessions.

Two valid v1 approaches:

**Approach A (Recommended)**: Treat observation requirements as a "parallel requirement"
- Not listed as a pathway activity visible to participants
- Still tracked for completion and reporting (Mentor + Staff dashboards)
- Mentor submits observation via JFB form; HL Core creates `hl_observation` record and listens for the JFB hook to mark it submitted

**Approach B**: Model as an Activity
- activity_type = "observation"
- external_ref: `{"form_plugin": "jetformbuilder", "form_id": <JFB form ID>, "required_count": N}`
- completion based on "N observations submitted"
- visibility can be restricted to Mentors + Staff

Default v1 choice:
- Approach A (parallel requirement) unless Admin explicitly wants it shown in Pathway.

---

# 4) Completion Model

## 4.1 Activity Completion Output Format (for reporting)
Every activity must produce:
- completion_percent (0..100)
- completion_status ∈ { "not_started", "in_progress", "complete" }
- completed_at (timestamp when it reached 100%, if applicable)

Rules:
- LearnDash Course: use LearnDash percent
- JFB-powered activities (teacher self-assessment, observations): 0 or 100 (binary, based on form submission)
- Child Assessment: computed 0/100 across required classroom instances
- Coaching attendance: 0 or 100

## 4.2 Track/Pathway Completion Percent
For a participant in a Track:
- pathway_completion_percent = weighted average of assigned Activities (default weight=1)
- track_completion_percent = same as pathway_completion_percent for the participant's assigned Pathway(s)

If multiple pathways are ever assigned in the future:
- track_completion_percent = weighted average across all assigned pathways (optional v2)

---

# 5) Leader Handling (Manual by Design)

Leaders (District Leader, School Leader) are few and can be configured manually.

Leader learning requirements:
- Leaders may be assigned a Leader Pathway (streamlined) OR no pathway.
- Leader reporting access is granted by Track Role and scope (doc 03).
- If a leader is also mentoring a team, Admin can assign Mentor Pathway.

Leader configuration UX should support:
- manual assignment of Track Roles (leader roles)
- manual pathway assignment and overrides

---

# 6) Activity Assignment Rules (Role → Pathway defaults)

Defaults per Track configuration:
- Teacher role → default Teacher Pathway
- Mentor role → default Mentor Pathway
- Leaders → manual (optional leader pathway)

Admin must be able to override per Enrollment.

---

# 7) Relationship to Unlocking Logic (Doc 05)

Activities may be gated by:
- prerequisites (graph dependencies)
- drip rules (fixed date and/or completion-based delay)

HL Core must keep unlock logic independent of ordering_hint.

---

# 8) Data Needed by the AI Implementer (No code)

To implement this cleanly, the plugin must support:
- CRUD for Pathways (per Track)
- CRUD for Activities (per Pathway)
- Mapping LearnDash course_id into LearnDash Course Activities
- Mapping JetFormBuilder form_id into JFB-powered Activities (teacher self-assessment, observations)
- Mapping HL Core instrument_id into Child Assessment Activities
- JFB form dropdown in Activity admin UI (queries available JFB forms)
- Computation of completion outputs for each activity type
- Aggregation into pathway/track completion percentages
- Observation parallel requirement tracking (Mentor/Staff dashboards)
- Coaching session records linked to mentors and observations

---

End of file.
