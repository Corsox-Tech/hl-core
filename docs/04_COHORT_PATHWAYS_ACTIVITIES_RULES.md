# Housman Learning Core Plugin — AI Library
## File: 04_COHORT_PATHWAYS_ACTIVITIES_RULES.md
Version: 4.0
Last Updated: 2026-03-24
Timezone: America/Bogota

---

> **Note:** This file was originally named for Cohorts/Activities (pre-V3 terms). Content uses current terminology: Partnership/Cycle, Component/Component Type.

# 0) Purpose

This document specifies how HL Core represents and configures:
- Pathways (per Cycle)
- Components (per Pathway)
- Component completion rules
- Role-to-pathway assignment rules
- How "parallel requirements" (Observations, Coaching) are handled

Rules:
- Cycles must be configurable per client and may differ across Cycles.
- Leaders are few; manual configuration is acceptable.
- HL Core reads LearnDash course progress but does not re-implement an LMS.

---

# 1) Configuration Overview

## 1.1 Cycle Configuration Layers
HL Core configuration for learning requirements exists at the Cycle level:

1) Cycle
2) Pathways within the Cycle
3) Components within each Pathway
4) Unlock rules (Prereqs + Drip) applied to Components (see doc 05)

A Cycle typically has 3 Pathways:
- Teacher Pathway
- Mentor Pathway
- (Optional) Leader Pathway (Streamlined) — manual use only

For course-type Cycles (`cycle_type = 'course'`), the Pathway/Component structure is auto-generated.

---

# 2) Pathway

## 2.1 Definition
**Pathway** is a configurable set/graph of required Components assigned to Participants. Pathways belong directly to a Cycle.

A Pathway is defined by:
- pathway_id (internal)
- cycle_id (FK → hl_cycle)
- pathway_name (e.g., "Teacher Pathway - Year 1")
- pathway_code (unique within Cycle)
- target_roles (optional convenience metadata; not a permission system)
- active_status

## 2.2 Pathway Assignment
Participants receive Pathways via Enrollment.

Default v1 assignment rules:
- If Enrollment has Cycle Role Teacher → assign configured Teacher Pathway (default)
- If Enrollment has Cycle Role Mentor → assign configured Mentor Pathway (default)
- Leaders: manual assignment by Housman Admin (and/or Coach if permitted)

Manual overrides allowed:
- Admin can set a participant's assigned Pathway explicitly.

Important:
- A participant can hold multiple Cycle Roles, but v1 assumes **one primary assigned Pathway**.
- If a leader is also a mentor, Admin may assign Mentor Pathway as primary.

---

# 3) Component

## 3.1 Definition
A **Component** is a single requirement in a Pathway.

Component has:
- component_id (internal)
- cycle_id
- pathway_id
- component_type (enumeration; below)
- title (display)
- description (optional)
- ordering_hint (optional; UI ordering only; does not define prerequisite logic)
- visibility (who sees it; usually enrolled participants; some artifacts may be staff-only)
- weight (integer or float; default=1; used for completion % aggregation)
- external_ref (JSON; stores type-specific configuration — see each type below)
- complete_by (date, nullable; suggested completion date — displayed on frontend, not enforced)
- requires_classroom (tinyint, default 0; eligibility: requires teaching assignment — see doc 05 §1.3)
- eligible_roles (text, JSON array or NULL; eligibility: restricts to specific enrollment roles — see doc 05 §1.3)

Note: The DB column is `component_type` and `component_state`.

## 3.2 Supported Component Types (v1)

### 3.2.1 LearnDash Course Component
- component_type = "learndash_course"
- external_ref: `{"course_id": <LearnDash post ID>}`

Completion:
- percent_complete = LearnDash course progress percent (0..100)
- completed = (LearnDash marks course completed OR percent==100)

Notes:
- HL Core reads LearnDash data; it does not manage LearnDash course structure.

---

### 3.2.2 Teacher Self-Assessment Component (Custom PHP Instrument System)
- component_type = "teacher_self_assessment"
- external_ref: `{"teacher_instrument_id": <HL instrument ID>, "phase": "pre"|"post"}`
- Legacy fallback: `{"form_plugin": "jetformbuilder", "form_id": <JFB form ID>, "phase": "pre"|"post"}`

Note: The "phase" field in external_ref refers to the pre/post assessment phase enum, not a deleted entity.

Rendered by HL Core's custom `HL_Teacher_Assessment_Renderer` using structured instrument definitions from `hl_teacher_assessment_instrument`. Responses are stored as structured JSON in `hl_teacher_assessment_instance.responses_json`.

- PRE mode: single-column Likert/scale ratings
- POST mode: Section 1 has dual-column retrospective ("Prior Assessment Cycle" + "Past Two Weeks"); Sections 2-3 are single-column

Admin workflow:
1. Admin creates/edits the instrument in HL Core's Instruments admin page (visual editor for sections, items, scales, instructions, display styles)
2. Admin creates a Component in the Pathway, selects component_type = "teacher_self_assessment", picks the instrument, and selects phase (pre or post)

Completion:
- 0% until instance.status = submitted
- 100% when instance.status = submitted

Privacy:
- Responses stored in `hl_teacher_assessment_instance.responses_json`. Only Housman Admin and Coach can view them.
- Non-staff participants see only completion status (0/100) and submitted timestamps.

Instance tracking:
- HL Core maintains `hl_teacher_assessment_instance` for each (cycle, enrollment, phase) to track status, responses_json, and submitted_at.

PRE and POST are separate components, each independent 0/100.

**Legacy JFB support:** Components can still reference JFB forms via `external_ref.form_id` for backward compatibility. The system checks for `teacher_instrument_id` first (custom) and falls back to `jfb_form_id` (legacy). JFB integration is legacy and pending removal.

---

### 3.2.3 Child Assessment Component (Custom PHP — NOT JetFormBuilder)
- component_type = "child_assessment"
- external_ref: `{"instrument_id": <HL instrument ID>}`

This component type uses a custom PHP form because it is inherently dynamic: the form renders one row per child in the teacher's assigned classroom, using questions from the HL Core instrument definition.

Important assignment rule:
- Child assessment instances are generated per (Cycle, Classroom, Teacher assignment).
- This Component represents the requirement category, while completion is computed from required instances.
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

### 3.2.4 Coaching Session Attendance Component
- component_type = "coaching_session_attendance"
Completion:
- 0% until a Coach marks attendance/complete for the required session(s)
- 100% after completion

Notes:
- Coaching sessions are typically for Mentors.
- This component can represent:
  - one required session, or
  - N required sessions (configurable)
- See doc 06 for coaching session records and linking to observations.

---

### 3.2.5 Observation Requirement (JetFormBuilder-powered, Parallel)
Observations are required artifacts for mentorship workflow.

The observation form itself is created and managed in JetFormBuilder (legacy — pending removal). HL Core tracks the observation record (who observed whom, which classroom) and links it to coaching sessions.

Two valid v1 approaches:

**Approach A (Recommended)**: Treat observation requirements as a "parallel requirement"
- Not listed as a pathway component visible to participants
- Still tracked for completion and reporting (Mentor + Staff dashboards)
- Mentor submits observation via JFB form; HL Core creates `hl_observation` record and listens for the JFB hook to mark it submitted

**Approach B**: Model as a Component
- component_type = "observation"
- external_ref: `{"form_plugin": "jetformbuilder", "form_id": <JFB form ID>, "required_count": N}`
- completion based on "N observations submitted"
- visibility can be restricted to Mentors + Staff

Default v1 choice:
- Approach A (parallel requirement) unless Admin explicitly wants it shown in Pathway.

---

### 3.2.6 Self-Reflection Component
- component_type = "self_reflection"
- external_ref: `{"visit_number": N}` — identifies which self-reflection in the sequence

A structured self-reflection form completed by **Teachers** after each course. Uses a custom PHP form rendered by `HL_Frontend_Self_Reflection`.

Completion:
- 0% until form is submitted
- 100% when submitted

Notes:
- Interleaved with courses in Teacher and Mentor pathways (e.g., Self-Reflection #1 follows TC1)
- Not present in Leader/Streamlined pathways
- Uses the `self_reflection_form` instrument for form structure

---

### 3.2.7 Reflective Practice Session Component
- component_type = "reflective_practice_session"
- external_ref: `{"session_number": N}` — identifies which RP session in the sequence

A structured reflective practice session between a Mentor and a Teacher. The RP session page (`HL_Frontend_RP_Session`) provides role-based views with auto-populated session prep data and editable RP Notes + Action Plan forms.

Form submissions (RP Notes and Action Plan) are stored in `hl_rp_session_submission` with instrument references (`coaching_rp_notes`, `mentoring_rp_notes`, `coaching_action_plan`, `mentoring_action_plan`).

Completion:
- 0% until required form submissions are complete
- 100% when all required submissions for the session are submitted

Notes:
- Present in all pathway types: Teacher pathways (paired with Self-Reflection), Mentor pathways (after mentor courses), Leader/Streamlined pathways (not present — leaders have Classroom Visits instead)
- RP sessions link a `mentor_enrollment_id` and `teacher_enrollment_id` in `hl_rp_session`

---

### 3.2.8 Classroom Visit Component
- component_type = "classroom_visit"
- external_ref: `{"visit_number": N}` — identifies which visit in the sequence

A structured classroom observation conducted by a **Leader** (School Leader or District Leader) visiting a teacher's classroom. Uses a custom PHP form rendered by `HL_Frontend_Classroom_Visit`.

Unlike Observations (mentor-submitted via JFB), Classroom Visits are leader-initiated and use the `classroom_visit_form` instrument.

Stored in `hl_classroom_visit` (visit record) and `hl_classroom_visit_submission` (form responses).

Completion:
- 0% until the visit form is submitted
- 100% when submitted

Notes:
- Present only in Leader/Streamlined pathways (replaces Observations/RP Sessions)
- The `hl_classroom_visit` table tracks `leader_enrollment_id`, `teacher_enrollment_id`, school, classroom, and visit status

---

# 4) Completion Model

## 4.1 Component Completion Output Format (for reporting)
Every component must produce:
- completion_percent (0..100)
- completion_status ∈ { "not_started", "in_progress", "complete" }
- completed_at (timestamp when it reached 100%, if applicable)

Rules:
- LearnDash Course: use LearnDash percent
- Teacher self-assessment: 0 or 100 (binary, based on form submission)
- Child Assessment: computed 0/100 across required classroom instances
- Coaching attendance: 0 or 100
- Observations: 0 or 100
- Self-Reflection: 0 or 100 (binary, based on form submission)
- Reflective Practice Session: 0 or 100 (binary, based on required form submissions)
- Classroom Visit: 0 or 100 (binary, based on form submission)

## 4.2 Cycle/Pathway Completion Percent
For a participant in a Cycle:
- pathway_completion_percent = weighted average of **eligible** Components (default weight=1)
  - Ineligible components (from `requires_classroom` or `eligible_roles` checks) are excluded from the weighted average. See doc 05 §1.3 for eligibility rules.
- cycle_completion_percent = same as pathway_completion_percent for the participant's assigned Pathway(s)

If multiple pathways are ever assigned in the future:
- cycle_completion_percent = weighted average across all assigned pathways (optional v2)

---

# 5) Leader Handling (Manual by Design)

Leaders (District Leader, School Leader) are few and can be configured manually.

Leader learning requirements:
- Leaders may be assigned a Leader Pathway (streamlined) OR no pathway.
- Leader reporting access is granted by Cycle Role and scope (doc 03).
- If a leader is also mentoring a team, Admin can assign Mentor Pathway.

Leader configuration UX should support:
- manual assignment of Cycle Roles (leader roles)
- manual pathway assignment and overrides

---

# 6) Component Assignment Rules (Role → Pathway defaults)

Defaults per Cycle configuration:
- Teacher role → default Teacher Pathway
- Mentor role → default Mentor Pathway
- Leaders → manual (optional leader pathway)

Admin must be able to override per Enrollment.

---

# 7) Relationship to Unlocking Logic (Doc 05)

Components may be gated by:
- prerequisites (graph dependencies)
- drip rules (fixed date and/or completion-based delay)

HL Core must keep unlock logic independent of ordering_hint.

---

# 8) Data Needed by the AI Implementer (No code)

To implement this cleanly, the plugin must support:
- CRUD for Pathways (per Cycle)
- CRUD for Components (per Pathway)
- Mapping LearnDash course_id into LearnDash Course Components
- Mapping HL Core instrument_id into Teacher Self-Assessment Components
- Mapping HL Core instrument_id into Child Assessment Components
- Computation of completion outputs for each component type
- Aggregation into pathway/cycle completion percentages
- Observation parallel requirement tracking (Mentor/Staff dashboards)
- Coaching session records linked to mentors and observations

---

End of file.
