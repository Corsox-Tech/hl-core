# Housman Learning Core Plugin — AI Library
## File: 06_ASSESSMENTS_CHILDREN_TEACHER_OBSERVATION_COACHING.md
Version: 5.0
Last Updated: 2026-03-24
Timezone: America/Bogota

---

# 0) Purpose

This document specifies HL Core data structures and rules for:
- Teacher Self-Assessments (pre/post) — **Custom PHP instrument system**
- Child Assessments (per teacher, covering all assigned children) — **Custom PHP instrument system**
- Observations (mentor-submitted) — **JetFormBuilder-powered**
- Coaching Sessions (coach-submitted; linked to observations) — **Custom PHP admin CRUD + form submissions**
- Reflective Practice (RP) Sessions — **Custom PHP with role-based views and form submissions**
- Classroom Visits (leader-initiated) — **Custom PHP form**
- Self-Reflections (teacher-submitted) — **Custom PHP form**

## 0.1 Forms Architecture

HL Core uses a **primarily custom PHP approach** for assessments and event forms:

**HL Core custom PHP handles:**
- **Teacher Self-Assessments** — structured instrument definitions stored as JSON in `hl_teacher_assessment_instrument`, responses stored as JSON in `hl_teacher_assessment_instance.responses_json`. Custom renderer supports PRE (single-column) and POST (dual-column retrospective) modes.
- **Child Assessments** — dynamic per-child form generated from classroom roster + instrument definition. Rendered from `hl_instrument.questions` JSON.
- **Coaching Sessions** — admin CRUD workflow (attendance, notes, observation links, attachments) + structured form submissions (RP Notes, Action Plan) stored in `hl_coaching_session_submission`.
- **Reflective Practice Sessions** — RP session page with role-based views (coach/mentor/teacher), auto-populated session prep data, and RP Notes + Action Plan form submissions stored in `hl_rp_session_submission`.
- **Classroom Visits** — Leader-initiated classroom observation forms stored in `hl_classroom_visit` (visit record) and `hl_classroom_visit_submission` (form responses).
- **Self-Reflections** — Teacher post-course self-reflection forms rendered by `HL_Frontend_Self_Reflection`.

**JetFormBuilder handles:**
- **Observations only** — Mentor-submitted observation forms about a teacher's classroom practice. JFB provides the visual form editor so Housman admins can customize observation questions without a developer.

**Rationale for the change from JFB to custom (teacher assessments):**
- The POST version requires a unique dual-column retrospective format (PRE responses shown as disabled radios alongside new "Now" ratings) that JFB cannot natively support
- Structured response data (`responses_json`) is needed for research export, control group comparison, and Cohen's d effect size calculations
- Pre/post logic must integrate tightly with the component system and pathway drip rules
- Separate PRE and POST instrument definitions allow different item counts, instructions, and section behavior per phase
- Admin-customizable display styles (`styles_json`) allow font-size and color adjustments without developer intervention

**Legacy JFB support:** Teacher self-assessment components can still reference JFB forms via `external_ref.form_id` for backward compatibility. The system checks for `teacher_instrument_id` first (custom) and falls back to `jfb_form_id` (legacy). New deployments should use the custom instrument system exclusively.

---

# 1) Privacy and Visibility (Non-negotiable)

## 1.1 Assessment response privacy
Raw responses for:
- Teacher Self-Assessments (stored in `hl_teacher_assessment_instance.responses_json`)
- Child Assessments (stored in `hl_child_assessment_instance.responses_json` or `hl_child_assessment_childrow`)
must be visible ONLY to:
- Housman Admin
- Coach

Non-staff roles may see only:
- completion status (0/100) and submitted timestamps
- not the response content

## 1.2 Observations and Coaching privacy (v1 defaults)
Observations and Coaching Sessions are primarily staff workflow artifacts.

Default v1 visibility:
- Housman Admin: full access
- Coach: full access
- Mentor: can view their own observations; can view their own coaching session attendance status
- Teachers/Leaders: no access to observation/coaching notes unless explicitly enabled later

---

# 2) Teacher Self-Assessment (Custom PHP Instrument System)

## 2.1 Purpose
Each Teacher participant completes:
- one PRE self-assessment at the start of the program/research period
- one POST self-assessment at the end (typically time-gated via drip rule)

The B2E Teacher Self-Assessment instrument has 3 sections:

**Section 1 — Classroom Practices (20 items)**
- 5-point Likert scale: Almost Never(0) -> Almost Always(4)
- Measures classroom practices on hard days
- POST mode: dual-column retrospective — "Prior Assessment Cycle" + "Past Two Weeks"

**Section 2 — Job Wellbeing (4 items)**
- 11-point scale: 0-10 with custom anchors per item
- Measures job stress, coping, support, satisfaction

**Section 3 — Emotional Self-Regulation (4 items)**
- 7-point Likert scale: Strongly Disagree(0) -> Strongly Agree(6)
- Measures emotional self-regulation

## 2.2 Instrument Storage

### hl_teacher_assessment_instrument
Stores the instrument definition as structured JSON. **Separate instruments are created for PRE and POST phases** (e.g., `b2e_self_assessment_pre` and `b2e_self_assessment_post`) because they can have different item counts, instructions, and section behaviors (POST Section 1 uses retrospective dual-column).

- instrument_id (PK)
- instrument_name (varchar 255)
- instrument_key (varchar 100, unique) — e.g., "b2e_self_assessment_pre", "b2e_self_assessment_post"
- instrument_version (int)
- sections (longtext JSON) — structured section definitions with items, scales, instructions
- scale_labels (longtext JSON) — reusable scale definitions
- instructions (longtext NULL) — admin-editable rich text instructions displayed above the form
- styles_json (longtext NULL) — admin-customizable display styles JSON (font sizes, colors for instructions, section titles, section descriptions, items, scale labels)
- status (enum: active/archived)
- created_at, updated_at

### Sections JSON structure:
```json
[
  {
    "section_id": "practices",
    "title": "Assessment 1/3: Classroom Practices",
    "instructions_pre": "...",
    "instructions_post": "...",
    "question_type": "likert",
    "scale_key": "likert_5",
    "post_has_retrospective": true,
    "items": [
      {"item_id": "P1", "text": "I praised children for their efforts and successes."},
      ...
    ]
  },
  ...
]
```

## 2.3 Instance Tracking

### hl_teacher_assessment_instance
- instance_id (PK)
- cycle_id
- enrollment_id (teacher enrollment)
- component_id (FK to hl_component) — links to the pathway component
- instrument_id (FK to hl_teacher_assessment_instrument; NULL for legacy JFB)
- phase (enum: pre, post)
- status (enum: not_started, draft, submitted)
- responses_json (longtext) — structured responses for custom instruments
- jfb_form_id (legacy, nullable)
- jfb_record_id (legacy, nullable)
- started_at (datetime NULL)
- submitted_at (datetime NULL)
- created_at, updated_at

Constraints:
- unique(cycle_id, enrollment_id, phase) — or unique(enrollment_id, component_id)

### Response JSON format:
```json
{
  "practices": {
    "P1": {"value": 3}
  },
  "wellbeing": {
    "W1": {"value": 7}
  },
  "self_regulation": {
    "SR1": {"value": 5}
  }
}
```

POST with retrospective (Section 1 only):
```json
{
  "practices": {
    "P1": {"prior": 2, "now": 4}
  }
}
```

## 2.3.1 Display Styles (styles_json)

Both instrument tables support an optional `styles_json` column for admin-customizable display styling. The JSON structure:

```json
{
    "instructions_font_size": "16px",
    "instructions_color": "#4B5563",
    "section_title_font_size": "20px",
    "section_title_color": "#1F2937",
    "section_desc_font_size": "15px",
    "section_desc_color": "#6B7280",
    "item_font_size": "15px",
    "item_color": "#374151",
    "scale_label_font_size": "13px",
    "scale_label_color": "#9CA3AF",
    "behavior_key_font_size": "14px",
    "behavior_key_color": "#1A2B47"
}
```

All keys are optional. Empty/missing = renderer's built-in default. Some keys only apply to one instrument type (e.g., `behavior_key_*` only for child instruments, `section_title_*` / `section_desc_*` only for teacher instruments) — unused keys are ignored.

Admins configure these via a "Display Styles" panel in the instrument edit form (collapsible section with per-element font-size dropdown + color picker). Renderers read styles and emit CSS override rules at the end of their `<style>` blocks.

## 2.4 Form Rendering (HL_Teacher_Assessment_Renderer)

**PRE mode:**
- Renders each section with `instructions_pre`
- Likert sections: table with items as rows, scale options as radio columns
- Scale 0-10: horizontal radio group with anchor labels
- Save Draft + Submit buttons

**POST mode:**
- Section 1 (`post_has_retrospective: true`): dual-column table
  - "Prior Assessment Cycle" column: PRE responses shown as disabled/read-only radios
  - "Past Two Weeks" column: active radio buttons for new ratings
  - Visual separation via background colors
- Sections 2-3: same as PRE mode (single column)

**Features:**
- Save Draft (partial responses, status = 'draft')
- Submit with confirmation (status = 'submitted', read-only after)
- Validation: all items required before submit
- Mobile responsive (stack columns on small screens)
- Pre-populate from existing responses_json when resuming a draft

## 2.5 Frontend Integration

Shortcode: `[hl_teacher_assessment]` (class-hl-frontend-teacher-assessment.php)
- URL params: component_id (required)
- Logic:
  1. Get current user's enrollment
  2. Get or create teacher_assessment_instance for this component
  3. Determine phase from component's external_ref
  4. Load instrument via teacher_instrument_id
  5. Render form via HL_Teacher_Assessment_Renderer
  6. Handle POST for draft save and final submit
  7. On submit: update hl_component_state to completed, trigger rollup

## 2.6 Component Routing
When `component_type = 'teacher_self_assessment'`:
- Check `external_ref` for `teacher_instrument_id` -> route to custom form
- Fall back to `jfb_form_id` -> route to JFB form (legacy)
- Component page, my-progress, and program page all respect this routing

## 2.7 Completion Rule
- 0% if instance.status != submitted
- 100% when instance.status == submitted
- PRE and POST are separate components, each independent 0/100

---

# 3) Child Assessment (Custom PHP)

## 3.1 Purpose
Child assessments measure social-emotional development of children in a teacher's classrooms.

Key requirement:
- A teacher completes ONE child assessment component per assessment period (pre or post)
- That single component covers ALL children in the teacher's assigned classrooms
- The form lists each child with their age band and the age-appropriate question + rating scale
- Children no longer in the classroom can be marked "No longer enrolled" (skipped)

**Why custom PHP:** The form dynamically renders one row per child in the classroom roster. The number of children, their names, and which age-band question applies are all determined at runtime. No form builder can handle this.

## 3.2 Per-Child Age Group Assignment (Frozen Snapshots)

Each child's age group is determined per-child (not per-classroom) and frozen for the duration of the cycle via `hl_child_cycle_snapshot`.

**How age groups are determined:**
1. When children are associated with a Cycle (via instance generation or teacher add), the system reads each child's DOB from `hl_child`
2. Age in months is calculated relative to current date at freeze time
3. Age-to-band mapping (from `HL_Age_Group_Helper`):
   - 0-11 months → infant
   - 12-35 months → toddler
   - 36-59 months → preschool
   - 60+ months → k2
4. The resulting age group is stored as `frozen_age_group` in `hl_child_cycle_snapshot`
5. Once frozen, the age group does NOT change even if the child ages past a boundary

**Why frozen:** Research integrity requires PRE and POST assessments to use the same instrument per child. If the age group changed mid-cycle, the pre/post comparison would use different questions.

**Mixed-age classrooms:** A single classroom may contain children of different age groups. The form renders age-group sections within one form, each section using its age-group-specific instrument and question text.

## 3.3 Age Band Variants
There are 4 age-band variants, each with 1 question on a 5-point scale (Never=0, Rarely=1, Sometimes=2, Usually=3, Almost Always=4):

- **Infant**: "In the last month, how often did the infant notice and respond to their own feelings and the feelings of the people around them?"
- **Toddler**: "In the last month, how often did the toddler express their own feelings using body language and/or words, respond to the feelings of the people around them, and manage their own feelings?"
- **Preschool/Pre-K**: "In the last month, how often did the child show that they understood, expressed, and managed their feelings and responded to the feelings of others?"
- **K-2nd Grade**: "In the last month, how often did the child show that they could manage their emotions, express empathy for others, and build positive relationships?"

Each variant includes example behaviors per scale point (from the B2E Child Assessment document).

## 3.4 Instruments (hl_instrument)
The `hl_instrument` table stores child assessment instrument definitions:
- instrument_id
- name
- instrument_type — varchar(50), values: "children_infant", "children_toddler", "children_preschool", "children_k2"
- version
- questions (JSON array)
- instructions (longtext NULL) — admin-editable rich text instructions displayed above the form; blank falls back to hard-coded default
- behavior_key (longtext NULL) — JSON array of 5 behavior key rows [{label, frequency, description}]; blank falls back to hard-coded age-band defaults
- styles_json (longtext NULL) — admin-customizable display styles JSON (font sizes, colors for instructions, behavior key, items, scale labels)
- effective_from / effective_to (optional)

Note: These are the ONLY instrument types in `hl_instrument`. Teacher self-assessment instruments and event instruments (RP Notes, Action Plans, Classroom Visit form, Self-Reflection form) are in a separate table (`hl_teacher_assessment_instrument`).

### Instrument Nuke Protection
The `hl_instrument` and `hl_teacher_assessment_instrument` tables are **excluded from the nuke command by default** to preserve admin customizations (instructions, questions, behavior keys, display styles). The `--include-instruments` flag must be passed explicitly to truncate these tables. All seeders use skip-if-exists logic (check by `name` for child instruments, by `instrument_key` for teacher instruments) so nuke+reseed cycles recreate enrollments and instances pointing to existing instrument IDs.

## 3.5 Object Model

### hl_child_assessment_instance
- instance_id (PK)
- enrollment_id (teacher enrollment)
- component_id (FK to hl_component)
- phase (enum: pre, post)
- status (enum: not_started, draft, submitted)
- responses_json (longtext) — per-child responses
- started_at, submitted_at, created_at, updated_at

### Response JSON format:
```json
{
  "children": {
    "42": {"value": 3, "age_band": "infant"},
    "43": {"value": 2, "age_band": "toddler"},
    "44": {"no_longer_enrolled": true}
  }
}
```
Keys are child_id from hl_child table.

### hl_child_assessment_childrow
Per-child response rows stored in a dedicated table for structured access:
- childrow_id (PK)
- instance_id (FK)
- child_id (FK)
- answers_json (per-child answers)
- status (enum: active, skipped, not_in_classroom, stale_at_submit) — tracks child's state at submission time
- skip_reason (varchar, nullable) — reason for skip (left_school, moved_classroom)
- frozen_age_group (varchar) — copied from hl_child_cycle_snapshot at save time
- instrument_id (int, nullable) — which instrument was used for this child's age group

## 3.6 Form Rendering
The child assessment form:
- Groups children by frozen_age_group (sections: Infant, Toddler, Preschool, K-2)
- Each section has its own behavior key, question text, and Likert scale from the age-group-specific instrument
- Transposed Likert matrix: children as columns, rating levels as rows
- Per-child "Not in my classroom" checkbox with reason dropdown (skips rating)
- "Missing a child?" link at bottom with AJAX draft auto-save before navigating to Classroom Page
- Render-time reconciliation: compares current roster against draft, adds new children, hides removed children
- Submit-time validation: detects roster changes since form load, marks stale children
- Save Draft + Submit buttons
- Read-only submitted summary grouped by age group

## 3.7 Admin: Generate Child Assessment Instances
Admin can generate assessment instances for all teachers in a cycle with one click:
- Calls `freeze_age_groups($cycle_id)` first to create `hl_child_cycle_snapshot` records
- For each teacher enrollment in the cycle
- Find their classrooms (via hl_teaching_assignment)
- If classrooms have children, create child_assessment instances
- Instance instrument_id is nullable (resolved per-child from frozen_age_group at render time)
- Create corresponding hl_component_state records

This can be done via admin button or WP-CLI command.

## 3.8 Completion Rule
- 0% if instance.status != submitted
- 100% when instance.status == submitted
- PRE and POST are separate components, each independent 0/100

---

# 4) Observations (JetFormBuilder-powered, Mentor-submitted)

## 4.1 Purpose
Mentors submit observations about teachers' classroom practice. Frequency is flexible.

Observations are used by Coaches to prepare for Coaching Sessions.

**This is the only remaining JFB-powered assessment type.** Observations use JFB because:
- Housman admins need to customize observation questions without a developer
- Observations are simple single-submission forms (no dual-column, no per-child matrix)
- JFB's visual form editor is ideal for this use case

## 4.2 JFB Integration Pattern

### Form setup (done once by admin in JetFormBuilder):
1. Create the form in JFB with desired fields, layout, and conditional logic
2. Add hidden fields: `hl_enrollment_id`, `hl_component_id`, `hl_cycle_id`, `hl_observation_id`
3. Add "Call Hook" post-submit action with hook name: `hl_core_form_submitted`

### Submission handling (automatic):
1. Mentor fills out and submits the form
2. JFB fires the `hl_core_form_submitted` hook
3. HL Core's hook listener updates observation status, component_state, and triggers rollup

## 4.3 Object Model

### hl_observation
- observation_id
- cycle_id
- mentor_enrollment_id
- teacher_enrollment_id (optional)
- school_id (optional)
- classroom_id (optional)
- instrument_id bigint(20) unsigned NULL
- instrument_version varchar(20) NULL
- jfb_form_id
- jfb_record_id (nullable, set on submission)
- status in { "draft", "submitted" }
- submitted_at (nullable)
- created_at, updated_at

### hl_observation_attachment
- attachment_id
- observation_id
- wp_media_id OR file_url
- mime_type
- created_at

## 4.4 Privacy
- Staff and submitting mentor may view; others no.

---

# 5) Coaching Sessions (Custom PHP Admin CRUD)

## 5.1 Purpose
Coaches run coaching sessions with mentors, after observations.
Coaching sessions need: attendance marking, coach notes, attachments, links to observations.

This is an admin-side CRUD workflow, not a user-facing questionnaire.

## 5.2 Object Model

### hl_coaching_session
- session_id
- cycle_id
- coach_user_id (WP User; staff)
- mentor_enrollment_id
- session_number (tinyint, nullable)
- session_title
- meeting_url
- session_status in { "scheduled", "attended", "missed", "cancelled", "rescheduled" }
- attendance_status in { "attended", "missed", "unknown" } (legacy, kept for backward compat)
- session_datetime
- notes_richtext (optional)
- cancelled_at (nullable)
- rescheduled_from_session_id (nullable)
- component_id (FK → hl_component, nullable — links to specific coaching component in pathway)
- zoom_meeting_id (bigint, nullable — Zoom API meeting ID for update/delete)
- outlook_event_id (varchar, nullable — Microsoft Graph calendar event ID)
- booked_by_user_id (FK → WP User, nullable — who created the booking)
- mentor_timezone (varchar, nullable — IANA timezone at booking time)
- coach_timezone (varchar, nullable — IANA timezone at booking time)
- created_at, updated_at

### hl_coaching_session_observation (join table)
- link_id
- session_id
- observation_id
- unique(session_id, observation_id)

### hl_coaching_attachment
- attachment_id
- session_id
- wp_media_id OR file_url
- mime_type
- created_at

## 5.3 Coaching Session Submissions

Coaches and mentors can submit structured form responses during coaching sessions. Submissions are stored in a dedicated table.

### hl_coaching_session_submission
- submission_id (PK)
- submission_uuid (char 36, unique)
- session_id (FK → hl_coaching_session)
- submitted_by_user_id (FK → WP User)
- instrument_id (FK → hl_teacher_assessment_instrument — references coaching_rp_notes, mentoring_rp_notes, coaching_action_plan, or mentoring_action_plan instrument)
- role_in_session varchar(20) NOT NULL — who submitted this form ('coach', 'mentor')
- responses_json (longtext) — structured form responses
- status (enum: 'draft', 'submitted')
- submitted_at (datetime, nullable)
- created_at, updated_at

Unique constraint: `(session_id, role_in_session)` — one submission per role per session.

### Instrument references:
- `coaching_rp_notes` — RP Notes form filled by coach during coaching session
- `mentoring_rp_notes` — RP Notes form filled by mentor during RP session
- `coaching_action_plan` — Action Plan form filled by coach during coaching session
- `mentoring_action_plan` — Action Plan form filled by mentor during RP session

## 5.4 Completion Rule
- completion is binary: 0% or 100%
- set to 100% when coach marks session_status = "attended"

---

# 5.5) Reflective Practice (RP) Sessions

## 5.5.1 Purpose
RP sessions are structured reflective practice events between a **Mentor** and a **Teacher** within a Cycle. They are interleaved with courses in both Teacher and Mentor pathways. Each RP session provides guided reflection on classroom practice after a course is completed.

## 5.5.2 Object Model

### hl_rp_session
- rp_session_id (PK)
- rp_session_uuid (char 36, unique)
- cycle_id (FK → hl_cycle)
- mentor_enrollment_id (FK → hl_enrollment)
- teacher_enrollment_id (FK → hl_enrollment)
- session_number (int)
- status (varchar 20: 'pending', 'scheduled', 'attended', 'missed', 'cancelled')
- session_date (datetime, nullable)
- notes (text, nullable)
- created_at, updated_at

### hl_rp_session_submission
- submission_id (PK)
- submission_uuid (char 36, unique)
- rp_session_id (FK → hl_rp_session)
- submitted_by_user_id (FK → WP User)
- instrument_id (FK → hl_teacher_assessment_instrument)
- role_in_session varchar(20) NOT NULL — who submitted this form ('coach', 'mentor')
- responses_json (longtext) — structured form responses
- status (enum: 'draft', 'submitted')
- submitted_at (datetime, nullable)
- created_at, updated_at

Unique constraint: `(rp_session_id, role_in_session)` — one submission per role per session.

## 5.5.3 Frontend Rendering (HL_Frontend_RP_Session)

The RP session page provides **role-based views**:

**Coach view:**
- Session prep notes auto-populated by `HL_Session_Prep_Service` (pathway progress, previous action plans, recent classroom visits)
- Classroom Visit & Self-Reflection review section
- Editable RP Notes form (uses `coaching_rp_notes` instrument)
- Editable Action Plan form (uses `coaching_action_plan` instrument)

**Mentor view:**
- Editable RP Notes form (uses `mentoring_rp_notes` instrument)
- Editable Action Plan form (uses `mentoring_action_plan` instrument)

**Teacher view:**
- Read-only view of completed RP Notes and Action Plan submissions

## 5.5.4 Completion Rule
- 0% until required form submissions are complete
- 100% when the session is marked completed

---

# 5.6) Classroom Visits

## 5.6.1 Purpose
Classroom Visits are structured classroom observations conducted by **Leaders** (School Leaders or District Leaders) visiting a teacher's classroom. Unlike Observations (which are mentor-submitted via JFB), Classroom Visits use a custom PHP form and are initiated by leaders.

Classroom Visits are present only in Leader/Streamlined pathways, where they replace the Observation/RP Session components used in Teacher and Mentor pathways.

## 5.6.2 Object Model

### hl_classroom_visit
- classroom_visit_id (PK)
- classroom_visit_uuid (char 36, unique)
- cycle_id (FK → hl_cycle)
- leader_enrollment_id (FK → hl_enrollment — the leader performing the visit)
- teacher_enrollment_id (FK → hl_enrollment — the teacher being visited)
- classroom_id (FK → hl_classroom, nullable)
- visit_number (int)
- status (varchar 20: 'pending', 'completed')
- visit_date (datetime, nullable)
- notes (text, nullable)
- created_at, updated_at

### hl_classroom_visit_submission
- submission_id (PK)
- submission_uuid (char 36, unique)
- classroom_visit_id (FK → hl_classroom_visit)
- submitted_by_user_id (FK → WP User)
- instrument_id (FK → hl_teacher_assessment_instrument — references `classroom_visit_form` instrument)
- role_in_visit varchar(20) NOT NULL — who submitted this form ('leader', 'teacher')
- responses_json (longtext) — structured form responses
- status (enum: 'draft', 'submitted')
- submitted_at (datetime, nullable)
- created_at, updated_at

Unique constraint: `(classroom_visit_id, role_in_visit)` — one submission per role per visit.

## 5.6.3 Frontend Rendering (HL_Frontend_Classroom_Visit)
Custom PHP form rendered from the `classroom_visit_form` instrument. Auto-populates header fields (school, teacher name, date, visitor name, age group).

## 5.6.4 Completion Rule
- 0% until the visit form is submitted
- 100% when submitted

---

# 5.7) Self-Reflections

## 5.7.1 Purpose
Self-Reflections are structured self-reflection forms completed by **Teachers** after each course. They provide an opportunity for teachers to reflect on how they are applying course concepts in their classroom.

Self-Reflections are interleaved with courses in Teacher pathways (e.g., Self-Reflection #1 follows TC1). They are NOT present in Leader/Streamlined pathways.

## 5.7.2 Component Model
- component_type = "self_reflection"
- external_ref: `{"visit_number": N}` — identifies which self-reflection in the sequence
- Uses the `self_reflection_form` instrument for form structure

## 5.7.3 Frontend Rendering (HL_Frontend_Self_Reflection)
Custom PHP form rendered from the `self_reflection_form` instrument. Auto-populates header fields (school, teacher name, date, age group).

## 5.7.4 Completion Rule
- 0% until the self-reflection form is submitted
- 100% when submitted

---

# 5.8) Event Instruments (Seeded)

The following instruments are seeded by the `setup-elcpb-y2-v2` CLI command for the event component types:

| Instrument Key | Name | Used By |
|---|---|---|
| `coaching_rp_notes` | Coaching RP Notes | Coach during coaching sessions |
| `mentoring_rp_notes` | Mentoring RP Notes | Mentor during RP sessions |
| `coaching_action_plan` | Coaching Action Plan | Coach during coaching sessions |
| `mentoring_action_plan` | Mentoring Action Plan | Mentor during RP sessions |
| `classroom_visit_form` | Classroom Visit Form | Leader during classroom visits |
| `self_reflection_form` | Self-Reflection Form | Teacher after each course |

These instruments have structured `sections` JSON defining auto-populated headers, editable form fields, and visibility rules. They are stored in the `hl_teacher_assessment_instrument` table (not `hl_instrument`, which holds only child assessment instruments).

---

# 6) Control Group Assessment Workflow

## 6.1 Purpose

**Important clarification:** The control group is NOT tied to one specific client/program. It exists as an independent research asset that can be compared against ANY or MULTIPLE program Cycles. The statistical comparison (Cohen's d) happens in **Stata**, not in WordPress. HL Core's job is to store control group assessment data and export it as CSV. The Partnership-based comparison reports (§6.3) are a nice-to-have convenience, not the critical path for research.

Control group cycles use an assessment-only pathway. Teachers in the control group complete:
1. Teacher Self-Assessment (Pre) — available immediately
2. Child Assessment (Pre) — available immediately
3. Teacher Self-Assessment (Post) — locked until end-of-program date via drip rule
4. Child Assessment (Post) — locked until end-of-program date via drip rule

No courses, coaching, observations, or mentorship components are included.

## 6.2 Pathway Structure
The control group pathway contains exactly 4 components:
- Component 1: teacher_self_assessment, phase=pre, sort_order=1, no drip rule
- Component 2: child_assessment, phase=pre, sort_order=2, no drip rule
- Component 3: teacher_self_assessment, phase=post, sort_order=3, drip: fixed_date
- Component 4: child_assessment, phase=post, sort_order=4, drip: fixed_date

## 6.3 Comparison Reporting
When a Partnership contains both program and control cycles:
- ReportingService aggregates `responses_json` from teacher assessment instances
- Per-section, per-item mean/n/sd for both PRE and POST phases
- Cohen's d effect size = (program_change - control_change) / pooled_sd
- CSV export with per-item means, change values, and effect sizes

**Primary research workflow:** Assessment data is exported as CSV from HL Core → imported into Stata for statistical analysis (Cohen's d, etc.). The in-app comparison report is supplementary. The control Cycle does NOT need to be in the same Partnership as the program Cycle for this workflow.

---

# 7) Staff UI Requirements

HL Core provides staff workflows for:
- Viewing teacher self-assessment responses: Admin Assessments page (queries responses_json)
- Viewing child assessment responses: Admin Assessments page (queries responses_json or childrow table)
- Exporting responses: CSV export from Admin Reports and Admin Assessments pages
- Reviewing observations: staff can view observation records and access JFB Form Records
- Creating coaching sessions linked to observations
- Marking coaching attendance + notes
- Program vs Control comparison reporting (when Partnership contains both program and control cycles)

Non-staff workflows:
- Teachers submit their self-assessment (custom form at `[hl_teacher_assessment]`)
- Teachers submit their child assessment (custom form at `[hl_child_assessment]`)
- Mentors submit observations (JFB form at `[hl_observations]`)

---

# 8) Audit Log Requirements (Assessment-related)

Log:
- assessment instance submitted (who, when, which cycle/classroom)
- staff view of assessment responses (who accessed, when)
- exports generated (who exported, filters used)
- observation submitted (who, when, JFB record ID)
- coaching session created/updated (who, when)
- coaching attendance marked (who, when)

---

End of file.
