# Housman Learning Core Plugin — AI Library
## File: 06_ASSESSMENTS_CHILDREN_TEACHER_OBSERVATION_COACHING.md
Version: 2.0
Last Updated: 2026-02-24
Timezone: America/Bogota

---

# 0) Purpose

This document specifies HL Core data structures and rules for:
- Teacher Self-Assessments (pre/post) — **Custom PHP instrument system**
- Child Assessments (per teacher, covering all assigned children) — **Custom PHP instrument system**
- Observations (mentor-submitted) — **JetFormBuilder-powered**
- Coaching Sessions (coach-submitted; linked to observations) — **Custom PHP admin CRUD**

## 0.1 Forms Architecture

HL Core uses a **primarily custom PHP approach** for assessments:

**HL Core custom PHP handles:**
- **Teacher Self-Assessments** — structured instrument definitions stored as JSON in `hl_teacher_assessment_instrument`, responses stored as JSON in `hl_teacher_assessment_instance.responses_json`. Custom renderer supports PRE (single-column) and POST (dual-column retrospective) modes.
- **Child Assessments** — dynamic per-child form generated from classroom roster + instrument definition. Rendered from `hl_instrument.questions` JSON.
- **Coaching Sessions** — admin CRUD workflow (attendance, notes, observation links, attachments). Not a questionnaire.

**JetFormBuilder handles:**
- **Observations only** — Mentor-submitted observation forms about a teacher's classroom practice. JFB provides the visual form editor so Housman admins can customize observation questions without a developer.

**Rationale for the change from JFB to custom (teacher assessments):**
- The POST version requires a unique dual-column retrospective format (PRE responses shown as disabled radios alongside new "Now" ratings) that JFB cannot natively support
- Structured response data (`responses_json`) is needed for research export, control group comparison, and Cohen's d effect size calculations
- Pre/post logic must integrate tightly with the activity system and pathway drip rules
- The B2E Teacher Self-Assessment instrument is standardized and does not need admin-editable questions

**Legacy JFB support:** Teacher self-assessment activities can still reference JFB forms via `external_ref.form_id` for backward compatibility. The system checks for `teacher_instrument_id` first (custom) and falls back to `jfb_form_id` (legacy). New deployments should use the custom instrument system exclusively.

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
Stores the instrument definition as structured JSON:
- instrument_id (PK)
- instrument_name (varchar 255)
- instrument_key (varchar 100, unique) — e.g., "b2e_teacher_self_assessment"
- instrument_version (int)
- sections (longtext JSON) — structured section definitions with items, scales, instructions
- scale_labels (longtext JSON) — reusable scale definitions
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
- cohort_id
- enrollment_id (teacher enrollment)
- activity_id (FK to hl_activity) — links to the pathway activity
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
- unique(cohort_id, enrollment_id, phase) — or unique(enrollment_id, activity_id)

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
- URL params: activity_id (required)
- Logic:
  1. Get current user's enrollment
  2. Get or create teacher_assessment_instance for this activity
  3. Determine phase from activity's external_ref
  4. Load instrument via teacher_instrument_id
  5. Render form via HL_Teacher_Assessment_Renderer
  6. Handle POST for draft save and final submit
  7. On submit: update hl_activity_state to completed, trigger rollup

## 2.6 Activity Routing
When `activity_type = 'teacher_self_assessment'`:
- Check `external_ref` for `teacher_instrument_id` -> route to custom form
- Fall back to `jfb_form_id` -> route to JFB form (legacy)
- Activity page, my-progress, and program page all respect this routing

## 2.7 Completion Rule
- 0% if instance.status != submitted
- 100% when instance.status == submitted
- PRE and POST are separate activities, each independent 0/100

---

# 3) Child Assessment (Custom PHP)

## 3.1 Purpose
Child assessments measure social-emotional development of children in a teacher's classrooms.

Key requirement:
- A teacher completes ONE child assessment activity per assessment period (pre or post)
- That single activity covers ALL children in the teacher's assigned classrooms
- The form lists each child with their age band and the age-appropriate question + rating scale
- Children no longer in the classroom can be marked "No longer enrolled" (skipped)

**Why custom PHP:** The form dynamically renders one row per child in the classroom roster. The number of children, their names, and which age-band question applies are all determined at runtime. No form builder can handle this.

## 3.2 Age Band Variants
There are 4 age-band variants, each with 1 question on a 5-point scale (Never=0, Rarely=1, Sometimes=2, Usually=3, Almost Always=4):

- **Infant**: "In the last month, how often did the infant notice and respond to their own feelings and the feelings of the people around them?"
- **Toddler**: "In the last month, how often did the toddler express their own feelings using body language and/or words, respond to the feelings of the people around them, and manage their own feelings?"
- **Preschool/Pre-K**: "In the last month, how often did the child show that they understood, expressed, and managed their feelings and responded to the feelings of others?"
- **K-2nd Grade**: "In the last month, how often did the child show that they could manage their emotions, express empathy for others, and build positive relationships?"

Each variant includes example behaviors per scale point (from the B2E Child Assessment document).

## 3.3 Instruments (hl_instrument)
The `hl_instrument` table stores child assessment instrument definitions:
- instrument_id
- name
- instrument_type in { "children_infant", "children_toddler", "children_preschool" }
- version
- questions (JSON array)
- effective_from / effective_to (optional)

Note: These are the ONLY instrument types in `hl_instrument`. Teacher self-assessment instruments are in a separate table (`hl_teacher_assessment_instrument`).

## 3.4 Object Model

### hl_child_assessment_instance
- instance_id (PK)
- enrollment_id (teacher enrollment)
- activity_id (FK to hl_activity)
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

### Legacy: hl_child_assessment_childrow
Earlier implementations stored per-child rows in a separate table. The responses_json approach is preferred for new implementations as it aligns with the teacher assessment pattern and simplifies comparison reporting.

## 3.5 Form Rendering
The child assessment form:
- Groups children by classroom
- For each child: shows name, age band badge, the age-appropriate question text, radio buttons (Never -> Almost Always)
- "No longer enrolled" checkbox per child (skips rating)
- Save Draft + Submit buttons
- Pre-populate from existing responses_json
- Read-only mode for submitted assessments

## 3.6 Admin: Generate Child Assessment Instances
Admin can generate assessment instances for all teachers in a cohort with one click:
- For each teacher enrollment in the cohort
- Find their classrooms (via hl_classroom_teacher / hl_teaching_assignment)
- If classrooms have children, create pre and post child_assessment instances
- Create corresponding hl_activity_state records

This can be done via admin button or WP-CLI command.

## 3.7 Completion Rule
- 0% if instance.status != submitted
- 100% when instance.status == submitted
- PRE and POST are separate activities, each independent 0/100

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
2. Add hidden fields: `hl_enrollment_id`, `hl_activity_id`, `hl_cohort_id`, `hl_observation_id`
3. Add "Call Hook" post-submit action with hook name: `hl_core_form_submitted`

### Submission handling (automatic):
1. Mentor fills out and submits the form
2. JFB fires the `hl_core_form_submitted` hook
3. HL Core's hook listener updates observation status, activity_state, and triggers rollup

## 4.3 Object Model

### hl_observation
- observation_id
- cohort_id
- mentor_enrollment_id
- teacher_enrollment_id (optional)
- school_id (optional)
- classroom_id (optional)
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
- cohort_id
- coach_user_id (WP User; staff)
- mentor_enrollment_id
- session_title
- meeting_url
- session_datetime
- session_status in { "scheduled", "attended", "missed", "cancelled", "rescheduled" }
- notes_richtext (optional)
- cancelled_at (nullable)
- rescheduled_from_session_id (nullable)
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

## 5.3 Completion Rule
- completion is binary: 0% or 100%
- set to 100% when coach marks session_status = "attended"

---

# 6) Control Group Assessment Workflow

## 6.1 Purpose
Control group cohorts use an assessment-only pathway. Teachers in the control group complete:
1. Teacher Self-Assessment (Pre) — available immediately
2. Child Assessment (Pre) — available immediately
3. Teacher Self-Assessment (Post) — locked until end-of-program date via drip rule
4. Child Assessment (Post) — locked until end-of-program date via drip rule

No courses, coaching, observations, or mentorship activities are included.

## 6.2 Pathway Structure
The control group pathway contains exactly 4 activities:
- Activity 1: teacher_self_assessment, phase=pre, sort_order=1, no drip rule
- Activity 2: child_assessment, phase=pre, sort_order=2, no drip rule
- Activity 3: teacher_self_assessment, phase=post, sort_order=3, drip: fixed_date
- Activity 4: child_assessment, phase=post, sort_order=4, drip: fixed_date

## 6.3 Comparison Reporting
When a Cohort Group contains both program and control cohorts:
- ReportingService aggregates `responses_json` from teacher assessment instances
- Per-section, per-item mean/n/sd for both PRE and POST phases
- Cohen's d effect size = (program_change - control_change) / pooled_sd
- CSV export with per-item means, change values, and effect sizes

---

# 7) Staff UI Requirements

HL Core provides staff workflows for:
- Viewing teacher self-assessment responses: Admin Assessments page (queries responses_json)
- Viewing child assessment responses: Admin Assessments page (queries responses_json or childrow table)
- Exporting responses: CSV export from Admin Reports and Admin Assessments pages
- Reviewing observations: staff can view observation records and access JFB Form Records
- Creating coaching sessions linked to observations
- Marking coaching attendance + notes
- Program vs Control comparison reporting (when Cohort Group contains both types)

Non-staff workflows:
- Teachers submit their self-assessment (custom form at `[hl_teacher_assessment]`)
- Teachers submit their child assessment (custom form at `[hl_child_assessment]`)
- Mentors submit observations (JFB form at `[hl_observations]`)

---

# 8) Audit Log Requirements (Assessment-related)

Log:
- assessment instance submitted (who, when, which cohort/classroom)
- staff view of assessment responses (who accessed, when)
- exports generated (who exported, filters used)
- observation submitted (who, when, JFB record ID)
- coaching session created/updated (who, when)
- coaching attendance marked (who, when)

---

End of file.
