# Housman Learning Core Plugin — AI Library
## File: 06_ASSESSMENTS_CHILDREN_TEACHER_OBSERVATION_COACHING.md
Version: 1.1
Last Updated: 2026-02-17
Timezone: America/Bogota

---

# 0) Purpose

This document specifies HL Core data structures and rules for:
- Teacher Self-Assessments (pre/post) — **JetFormBuilder-powered**
- Children Assessments (Infant/Toddler/Preschool; per teacher-classroom) — **Custom PHP**
- Observations (mentor-submitted) — **JetFormBuilder-powered**
- Coaching Sessions (coach-submitted; linked to observations) — **Custom PHP admin CRUD**

## 0.1 Hybrid Forms Architecture

HL Core uses a hybrid approach to forms:

**JetFormBuilder handles** the form design, rendering, field types, validation, and response storage for:
- Teacher Self-Assessments
- Observations
- Any future static questionnaire forms

**HL Core handles** custom dynamic forms in PHP for:
- Children Assessments (dynamic per-child matrix from classroom roster)
- Coaching Sessions (admin CRUD workflow, not a questionnaire)

**Rationale:** Housman LMS Admins must be able to create, edit, and redesign questionnaire forms (add/remove/change questions, field types, layout) without needing a developer. JetFormBuilder provides a full visual form editor for this. Children Assessments cannot use a form builder because their structure is determined at runtime by the classroom roster.

**HL Core's role for JFB-powered forms:** HL Core is the orchestration layer. It does NOT need to know what questions are inside a JFB form. It tracks: which form is linked to which activity, whether the activity is complete, and contextual records (who submitted, for which cohort/enrollment).

---

# 1) Privacy and Visibility (Non-negotiable)

## 1.1 Assessment response privacy
Raw responses for:
- Teacher Self-Assessments (stored in JFB Form Records)
- Children Assessments (stored in hl_children_assessment_childrow)
must be visible ONLY to:
- Housman Admin
- Coach

Non-staff roles may see only:
- completion status (0/100) and submitted timestamps
- not the response content

For JFB-powered forms: privacy is naturally enforced because JFB Form Records are only accessible in WP admin, and teachers/mentors do not have WP admin access. HL Core exposes only completion status to non-staff front-end views.

## 1.2 Observations and Coaching privacy (v1 defaults)
Observations and Coaching Sessions are primarily staff workflow artifacts.

Default v1 visibility:
- Housman Admin: full access
- Coach: full access
- Mentor: can view their own observations; can view their own coaching session attendance status
- Teachers/Leaders: no access to observation/coaching notes unless explicitly enabled later

---

# 2) JetFormBuilder Integration (Common Pattern)

## 2.1 How JFB forms connect to HL Core

All JFB-powered form types (teacher self-assessment, observations) follow the same integration pattern:

### Form setup (done once by admin in JetFormBuilder):
1. Create the form in JFB with desired fields, layout, and conditional logic
2. Add hidden fields to the form: `hl_enrollment_id`, `hl_activity_id`, `hl_cohort_id` (and `hl_observation_id` for observations)
3. Add a "Call Hook" post-submit action with hook name: `hl_core_form_submitted`
4. Optionally add "Save Form Record" action so responses are stored in JFB Form Records for staff review

### Activity linking (done by admin in HL Core):
1. Create an Activity in a Pathway
2. Select the activity_type (e.g., `teacher_self_assessment`)
3. Pick the JFB form from a dropdown (HL Core queries available JFB forms)
4. The form_id is stored in `hl_activity.external_ref` as `{"form_plugin": "jetformbuilder", "form_id": 123, ...}`

### Front-end rendering (automatic):
1. Participant views their pathway and clicks an activity
2. HL Core determines the linked JFB form_id from `external_ref`
3. HL Core renders the JFB form on the page (via JFB shortcode or block)
4. HL Core pre-populates the hidden fields with the participant's enrollment context

### Submission handling (automatic):
1. Participant fills out and submits the form
2. JFB handles validation and stores responses
3. JFB fires the `hl_core_form_submitted` hook
4. HL Core's hook listener:
   - Reads `hl_enrollment_id`, `hl_activity_id`, `hl_cohort_id` from the submission
   - Updates the relevant instance record (e.g., `hl_teacher_assessment_instance`) to status = "submitted"
   - Updates `hl_activity_state` for that enrollment + activity to completion_percent = 100
   - Triggers completion rollup recomputation
   - Logs the submission in the audit log

### Hook listener implementation:
```php
add_action('jet-form-builder/custom-action/hl_core_form_submitted', function($request, $action_handler) {
    $enrollment_id = intval($request['hl_enrollment_id']);
    $activity_id   = intval($request['hl_activity_id']);
    $cohort_id     = intval($request['hl_cohort_id']);

    // Determine activity type and update the appropriate instance
    // Update hl_activity_state to complete
    // Recompute completion rollups
    // Audit log: assessment_submitted
}, 10, 2);
```

## 2.2 JFB Form Requirements for HL Core Integration

Any JFB form linked to an HL Core activity MUST have:
- Hidden field: `hl_enrollment_id` (required)
- Hidden field: `hl_activity_id` (required)
- Hidden field: `hl_cohort_id` (required)
- Post-submit action: "Call Hook" with hook name `hl_core_form_submitted`

Additional hidden fields per form type:
- Observations: `hl_observation_id` (to link submission to the observation record)

HL Core should validate that required hidden fields are present when saving an Activity linked to a JFB form.

---

# 3) Teacher Self-Assessment (JetFormBuilder-powered)

## 3.1 Purpose
Each Teacher participant completes:
- one PRE self-assessment before training
- one POST self-assessment after training

PRE and POST typically use the same JFB form (or admin can create separate forms).

## 3.2 Admin Self-Service
Housman LMS Admins can:
- Create and edit the self-assessment form in JetFormBuilder at any time
- Add, remove, or change questions without developer involvement
- Use any JFB field types (Likert scales via radio/select, text, number, multi-select, etc.)
- Adjust layout, conditional visibility, validation rules
- Create entirely new assessment forms and link them to activities

## 3.3 Object Model

### 3.3.1 TeacherSelfAssessmentInstance
HL Core maintains an instance record for completion tracking. This does NOT store responses (JFB does that).

Fields:
- instance_id
- cohort_id
- enrollment_id (teacher enrollment)
- phase ∈ { "pre", "post" }
- jfb_form_id (the JetFormBuilder form used)
- jfb_record_id (JFB Form Record ID after submission, nullable)
- status ∈ { "not_started", "in_progress", "submitted" }
- submitted_at (nullable)
- created_at, updated_at

Constraints:
- unique(cohort_id, enrollment_id, phase)

### 3.3.2 Response Storage
Responses are stored in JFB Form Records, NOT in HL Core tables.
- Staff view responses via JFB's Form Records admin page
- HL Core can optionally query JFB records by jfb_record_id for export/reporting integration

## 3.4 Completion Rule
Completion percent for the Teacher Self-Assessment Activity:
- 0% if instance.status != submitted
- 100% when instance.status == submitted

If both PRE and POST are separate activities:
- each is independent 0/100.

---

# 4) Children Assessment (Custom PHP — NOT JetFormBuilder)

## 4.1 Purpose
Children assessments are classroom-based and must be completed by **each teacher** assigned to that classroom.

Key requirement:
- Required per (Cohort, Classroom, Teacher enrollment).

If a teacher teaches 2 classrooms, they must complete 2 separate children assessments.

**Why custom PHP:** The form dynamically renders one row per child in the classroom roster. The number of children, their names, and which instrument applies are all determined at runtime. No form builder can handle this.

## 4.2 Instruments by Age Band
There are 3 children assessment instruments stored in `hl_instrument`:
- Infant
- Toddler
- Preschool

These are the ONLY instrument types that live in `hl_instrument`. Teacher self-assessment and observation instruments are JFB forms.

Instrument selection rule:
- Each Child is categorized into an age band.
- The classroom's children roster determines which instrument(s) apply.

Implementation note (recommended v1 behavior):
- Determine a classroom's instrument_age_band using the children currently assigned to the classroom.
- If a classroom has mixed age bands (rare), treat as "Needs Review" and require staff selection.

## 4.3 Instrument Definition (hl_instrument)

The `hl_instrument` table stores the question set for children assessments:
- instrument_id
- name
- instrument_type ∈ { "children_infant", "children_toddler", "children_preschool" }
- version (integer or semver string)
- questions (JSON array of question objects)
- effective_from / effective_to (optional)

Each question in the JSON array must have:
- question_id (immutable stable key)
- question_type (likert, single_select, multi_select, text, number, etc.)
- prompt_text
- allowed_values (for structured questions)
- required (boolean)

Important:
- Question order/text may evolve; answers must remain tied to question_id and instrument version.

## 4.4 Admin Management of Children Assessment Instruments
Housman LMS Admins manage children assessment instruments via the Instruments admin page in HL Core.
This page must support:
- Creating new instruments (name, type, version)
- Adding/editing/removing questions (question_id, type, prompt, allowed values)
- Versioning: creating a new version of an existing instrument without affecting submitted responses

## 4.5 Object Model

### 4.5.1 ChildrenAssessmentInstance
Represents one required submission by one teacher for one classroom in one cohort.

Fields:
- instance_id
- cohort_id
- enrollment_id (teacher enrollment)
- classroom_id
- center_id (redundant but helpful for queries)
- instrument_age_band ∈ { "infant", "toddler", "preschool" }
- instrument_id
- instrument_version
- status ∈ { "not_started", "in_progress", "submitted" }
- submitted_at (nullable)
- created_at, updated_at

Constraints:
- unique(cohort_id, enrollment_id, classroom_id)

### 4.5.2 ChildrenAssessmentChildRow (Recommended storage)
Stores answers for one child within a single instance.

Fields:
- row_id
- instance_id
- child_id
- answers_json (JSON object mapping question_id → answer value)
- created_at, updated_at

Constraints:
- unique(instance_id, child_id)

Privacy:
- only staff can read answers_json content.

## 4.6 Instance Generation Rule (Critical)

ChildrenAssessmentInstances must be generated (or ensured) when any of these change:
- A Cohort is activated OR children assessments are enabled for the Cohort
- TeachingAssignment changes for a classroom (teacher added/removed)
- Child roster changes (child added/removed/moved) (optional impact; see below)
- Teacher replacement mid-Cohort

Canonical rule:
For each Cohort P:
  For each Classroom C included in Cohort P:
    For each Teacher enrollment E assigned to Classroom C (TeachingAssignment):
      Ensure one ChildrenAssessmentInstance exists for (P, E, C).

Child roster changes:
- If a child is moved into a classroom after an instance is submitted:
  - v1 default: do not reopen submission automatically; staff can decide if a new instance is needed.
- If a child moves before submission:
  - the instance should reflect current classroom roster at submission time.

## 4.7 Custom Form Rendering (HL_Instrument_Renderer)
HL Core renders the children assessment form in custom PHP:
- Read the instrument's `questions` JSON
- Query the classroom roster (children currently assigned)
- Render a matrix/table: one row per child, columns for each question
- Support save-as-draft (status = "in_progress") and final submit (status = "submitted")
- On submit: save answers to `hl_children_assessment_childrow`, update instance status, update activity_state, recompute rollups

## 4.8 Completion Rule
Completion percent for the Children Assessment Activity for a teacher enrollment:
- Determine all required ChildrenAssessmentInstances for that teacher in the Cohort.
- If any required instance.status != submitted → activity completion = 0%
- If all required instances.status == submitted → activity completion = 100%

---

# 5) Observations (JetFormBuilder-powered, Mentor-submitted)

## 5.1 Purpose
Mentors submit observations about teachers' classroom practice. Frequency is flexible.

Observations are used by Coaches to prepare for Coaching Sessions.

Observations may include image/video attachments (via JFB file upload field or WP Media).

## 5.2 Admin Self-Service
Housman LMS Admins can:
- Create and edit the observation form in JetFormBuilder
- Add, remove, or change questions/prompts without developer involvement
- Add file upload fields for photo/video evidence

## 5.3 Object Model

### 5.3.1 ObservationRecord (HL Core — context/orchestration)
HL Core maintains an observation record for context tracking. This does NOT store form responses (JFB does that).

Fields:
- observation_id
- cohort_id
- mentor_enrollment_id
- teacher_enrollment_id (optional but recommended)
- center_id (optional)
- classroom_id (optional)
- jfb_form_id (the JFB observation form used)
- jfb_record_id (JFB Form Record ID after submission, nullable)
- status ∈ { "draft", "submitted" }
- submitted_at (nullable)
- created_at, updated_at

### 5.3.2 ObservationAttachment (optional, for non-JFB attachments)
If attachments are handled via JFB file upload fields, this table may not be needed.
If HL Core manages attachments separately (e.g., WP Media uploads outside the form):

Fields:
- attachment_id
- observation_id
- wp_media_id OR file_url
- mime_type
- created_at

### 5.3.3 Response Storage
Responses are stored in JFB Form Records, NOT in HL Core tables.

## 5.4 Mentor Observation Workflow
1. Mentor navigates to their observations page (front-end shortcode or dashboard)
2. Mentor clicks "New Observation"
3. Mentor selects the teacher they are observing (dropdown populated from their team members)
4. Optionally selects classroom
5. HL Core creates an `hl_observation` record with context (mentor, teacher, classroom, cohort)
6. HL Core renders the JFB observation form with hidden fields pre-populated: `hl_enrollment_id`, `hl_cohort_id`, `hl_activity_id` (if modeled as activity), `hl_observation_id`
7. Mentor fills out the form and submits
8. JFB fires `hl_core_form_submitted` hook
9. HL Core updates the observation record to status = "submitted", stores jfb_record_id
10. If observation is modeled as a pathway activity, HL Core updates activity_state

Privacy default:
- Staff and submitting mentor may view; others no.

---

# 6) Coaching Sessions (Custom PHP Admin CRUD — NOT JetFormBuilder)

## 6.1 Purpose
Coaches run coaching sessions with mentors, after observations.
Coaching sessions need:
- attendance marking
- coach notes
- attachments
- links to one or more Observations being discussed

This is an admin-side CRUD workflow, not a user-facing questionnaire.

## 6.2 Object Model

### 6.2.1 CoachingSession
Fields:
- session_id
- cohort_id
- coach_user_id (WP User; staff)
- mentor_enrollment_id
- attendance_status ∈ { "attended", "missed", "unknown" }
- session_datetime (optional; recommended)
- notes_richtext (optional)
- created_at, updated_at

### 6.2.2 CoachingSessionObservationLink
Many-to-many join (session ↔ observation)

Fields:
- link_id
- session_id
- observation_id

Constraint:
- unique(session_id, observation_id)

### 6.2.3 CoachingSessionAttachment
Fields:
- attachment_id
- session_id
- wp_media_id OR file_url
- mime_type
- created_at

Privacy default:
- Staff view only (coach/admin). Mentor may view attendance only.

## 6.3 Completion Rule (Coaching Activity)
If coaching sessions are modeled as an Activity requirement:
- completion is binary: 0% or 100%
- set to 100% when coach marks attendance_status="attended" for the required session(s)

If multiple sessions required (configurable):
- completion becomes 100% only when required count is met

---

# 7) Staff UI Requirements (No code)

HL Core must provide staff workflows for:
- viewing assessment responses:
  - Teacher self-assessment: via JFB Form Records admin page (JFB handles this natively)
  - Children assessment: via HL Core Assessments admin page (custom, queries hl_children_assessment_childrow)
- exporting responses:
  - Teacher self-assessment: via JFB Form Records export or custom HL Core export that queries JFB data
  - Children assessment: CSV export from HL Core
- reviewing observations: staff can view observation records and access JFB Form Records for response details
- creating coaching sessions linked to observations
- marking coaching attendance + notes

Non-staff workflows:
- teachers can submit their self-assessment (JFB form rendered by HL Core with context)
- teachers can submit their children assessment instances (custom PHP form)
- mentors can submit observations (JFB form rendered by HL Core with context)

---

# 8) Audit Log Requirements (Assessment-related)

Log:
- assessment instance submitted (who, when, which cohort/classroom, JFB record ID if applicable)
- staff view of children assessment responses (who accessed, when)
- exports generated (who exported, filters used)
- observation submitted (who, when, JFB record ID)
- coaching session created/updated (who, when)
- coaching attendance marked (who, when)

Note: For JFB-powered forms, audit logging of the submission event is handled by HL Core's hook listener. JFB's own Form Records provide an additional audit trail of the actual form data.

---

End of file.
