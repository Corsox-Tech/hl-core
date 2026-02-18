# Housman Learning Core Plugin — AI Library
## File: 09_PLUGIN_ARCHITECTURE_CONSTRAINTS_ACCEPTANCE_TESTS.md
Version: 1.1
Last Updated: 2026-02-17
Timezone: America/Bogota

---

# 0) Purpose

This document provides architectural guardrails so an AI coding agent (Claude Code) can:
- implement HL Core as a maintainable WordPress plugin
- choose sane storage patterns (custom tables vs WP meta)
- integrate with LearnDash, JetFormBuilder, and BuddyBoss correctly
- enforce security and privacy rules consistently
- validate work using acceptance tests

Rules:
- Prefer a clean, modular plugin architecture.
- Enforce server-side authorization checks on all reads/writes that expose scoped data.
- Store core domain data in custom tables (recommended).
- Do not depend on legacy JetEngine/Automator CPT/meta setup.
- Use JetFormBuilder for static questionnaire forms (teacher self-assessment, observations); use custom PHP for dynamic forms (children assessment) and admin CRUD (coaching sessions).

---

# 1) Plugin Structure (Recommended Modules)

The plugin should be organized into modules/services. Suggested folder map:

/hl-core/
  /includes/
    /domain/            (entity models + repositories)
    /services/          (business logic)
    /integrations/      (LearnDash, JetFormBuilder hook listener)
    /admin/             (WP admin pages, controllers)
    /api/               (REST routes/controllers)
    /security/          (capabilities, scope checks)
    /imports/           (parsers, validators, preview/commit pipeline)
    /reporting/         (rollups, exporters)
    /audit/             (audit logger)
    /utils/             (helpers, dates, normalization)
  /assets/              (admin JS/CSS)
  hl-core.php           (plugin bootstrap)

Core services to implement:
- CohortService
- OrgService (OrgUnit, District/Center hierarchy)
- EnrollmentService (roles, scope binding, status)
- TeamService (teams + teammembership enforcement)
- ClassroomService (classrooms, teaching assignments, children)
- PathwayService (pathways, activities)
- RulesEngineService (prereq + drip + availability)
- AssessmentService (children assessments — custom forms; teacher assessment instance tracking for JFB)
- ObservationService (observation record management — JFB handles the form)
- CoachingService (custom admin CRUD)
- JFBIntegrationService (hook listener for JFB form submissions, form embedding helper)
- ImportService (preview/commit)
- ReportingService (completion rollups + exports)
- AuditService (write-only logger)

---

# 2) Storage Requirements (Custom Tables Recommended)

## 2.1 Why custom tables
HL Core has:
- many-to-many relationships (teams, teaching assignments)
- high-volume assessment data (child-level rows for children assessments)
- reporting needs requiring fast scoped queries
Using post_meta/user_meta would become brittle and slow.

## 2.2 Minimum tables (conceptual)
This is the minimum set required by the domain model:

Org + Cohort:
- hl_orgunit
- hl_cohort
- hl_cohort_center (cohort ↔ center)

Participation:
- hl_enrollment
- hl_enrollment_roles (if multi-role per enrollment)
  OR hl_enrollment.role_mask (bitmask) (acceptable if implemented carefully)

Teams:
- hl_team
- hl_teammembership

Classrooms + children:
- hl_classroom
- hl_teachingassignment
- hl_child
- hl_child_classroom_current
- hl_child_classroom_history (optional)

Learning config:
- hl_pathway
- hl_activity
- hl_activity_prereq_group
- hl_activity_prereq_item
- hl_activity_drip_rule
- hl_activity_override

State/rollups:
- hl_activity_state (per enrollment/activity computed or cached)
- hl_completion_rollup (per enrollment overall percent, cached)

Instruments (Children Assessment only):
- hl_instrument (stores question definitions for children assessment instruments: infant/toddler/preschool)

Assessment orchestration:
- hl_teacher_assessment_instance (tracks pre/post status per teacher — does NOT store responses; JFB stores those)
- hl_children_assessment_instance (tracks required instances per teacher/classroom)
- hl_children_assessment_childrow (stores per-child answers — this IS the response storage for children assessments)

Observations + coaching:
- hl_observation (tracks who observed whom, status — does NOT store form responses; JFB stores those)
- hl_observation_attachment (optional; only if managing attachments outside JFB)
- hl_coaching_session
- hl_coaching_session_observation
- hl_coaching_attachment

Imports + audit:
- hl_import_run
- hl_import_row_preview (optional, can store serialized preview)
- hl_audit_log

Tables NOT needed (JFB handles response storage):
- ~~hl_teacher_assessment_response~~ (JFB Form Records stores teacher self-assessment answers)
- ~~hl_observation_response~~ (JFB Form Records stores observation form answers)

Notes:
- All tables should include created_at, updated_at.
- Use foreign keys conceptually (WP DB may not enforce; enforce in app layer).
- If these removed tables already exist in the DB, they can remain (no harm) but should not be written to by HL Core for JFB-powered form types.

---

# 3) WordPress Integration Strategy

## 3.1 Users
- Use WP users for identity.
- Do NOT store cohort roles as WP roles.
- Staff roles:
  - Housman Admin = WP admin (existing)
  - Coach = WP role or capability set (recommended: custom WP role "coach")

## 3.2 LearnDash
- HL Core must read course progress and completion %.
- HL Core stores LearnDash activity references by course_id.
- HL Core should avoid per-row slow calls; batch progress reads where possible.

## 3.3 JetFormBuilder (NEW)
- JetFormBuilder is used for static questionnaire forms: teacher self-assessments and observations.
- JFB must be installed and active for these features to work.
- HL Core integrates via JFB's "Call Hook" post-submit action mechanism.
- HL Core provides:
  - A hook listener (`jet-form-builder/custom-action/hl_core_form_submitted`) that processes submissions
  - A form embedding helper that renders JFB forms on front-end pages with pre-populated hidden fields
  - An admin UI dropdown for linking JFB forms to Activities (queries available JFB forms)
- HL Core does NOT modify or create JFB forms programmatically. Admins manage forms in JFB's native editor.
- Response data lives in JFB Form Records. HL Core may query these for export/reporting but does not duplicate storage.
- HL Core should check that JetFormBuilder is active and show an admin notice if it's not.

## 3.4 BuddyBoss
- HL Core does not implement messaging.
- HL Core may provide links to dashboards within BuddyBoss profile navigation (optional).
- HL Core should not restrict BuddyBoss messaging.

---

# 4) Admin UI (Minimum Required Pages)

Minimum WP Admin pages:

1) Cohorts
- list/create/edit Cohort (status, start date, end date)
- attach centers/district (if applicable)
- cohort settings (timezone)

2) Org Units
- manage districts/centers hierarchy
- manage classrooms per center (optional; can be imports-driven)

3) Enrollments
- view participants in a cohort
- assign cohort roles
- manual pathway assignment overrides (esp leaders)

4) Pathways & Activities
- create/edit pathways per cohort
- create/edit activities per pathway (type, ref, weight)
- for JFB-powered types: dropdown to select a JetFormBuilder form
- for children assessment type: dropdown to select an hl_instrument
- for LearnDash course type: dropdown/field to select a LearnDash course
- configure prereqs and drip rules

5) Teams
- create teams per center within cohort
- assign mentors and members
- enforce 1 team per enrollment per cohort

6) Imports
- run import wizard (participants, classrooms, children, teaching assignments)
- preview table with CREATE/UPDATE/SKIP/NEEDS_REVIEW/ERROR
- commit and download error report

7) Instruments (Children Assessment only)
- create/edit children assessment instruments (infant/toddler/preschool)
- manage questions (question_id, type, prompt, allowed values)
- version management

8) Assessments (Staff-only viewers)
- children assessment viewer: list instances, view per-child answers, export CSV
- teacher self-assessment: link to JFB Form Records for response viewing (or embed JFB's viewer)

9) Reporting
- staff cohort dashboards + filters
- scoped views for district/center/team if accessed by those roles

10) Audit Logs
- searchable audit log by cohort/user/action

---

# 5) Front-End UX (Minimum)

HL Core should provide at least:
- Participant Progress page (self) — shows pathway with activities, click to open JFB forms or custom forms
- Mentor Team Progress page (team scope) — includes "New Observation" flow (select teacher → JFB form)
- Children Assessment form page — custom PHP form with per-child matrix
- Center/District leader report pages (scoped)

Implementation options:
- WP shortcodes OR BuddyBoss profile tabs OR custom pages with routing.
Choose simplest: shortcodes + capability checks.

---

# 6) Security Requirements (Must implement)

## 6.1 Capability mapping
Implement canonical capabilities from doc 03.
All controllers (admin and REST) must call a security layer:

Security::assert_can($capability, $context)

Context includes:
- cohort_id
- enrollment_id (if relevant)
- requested scope (district/center/team/self)

## 6.2 Assessment privacy enforcement
For children assessments: any endpoint that returns answers_json content must require staff role.
For JFB-powered forms: responses are in JFB Form Records (WP admin only). HL Core front-end views must not expose response content — only completion status.
Non-staff can only see completion status (binary + timestamps).

## 6.3 Client leader create-only enforcement
District/Center leaders can create users only within scope and only within their Cohort.
They cannot edit existing users or reset passwords.

## 6.4 Audit sensitive access
Log:
- staff viewing/exporting children assessment responses
- overrides applied
- imports committed
- JFB form submissions (via hook listener)

---

# 7) Rules Engine Implementation Notes

- Store prerequisite rules as grouped sets (ALL_OF groups) rather than only edges.
- Store drip rules per activity (fixed date, completion delay).
- Provide a function to compute:
  - availability_status (locked/available/completed)
  - locked_reason (prereq/drip/manual_lock)
  - blockers
  - next_available_at (if drip)
- Cache computed results if needed for performance.

---

# 8) Reporting Implementation Notes

- Represent activity completion in a consistent output model:
  - percent 0..100
  - status
  - completed_at
- Compute pathway/cohort completion % using weights.
- Prefer precomputing rollups (hl_completion_rollup) and updating on events:
  - LearnDash course completion update hooks (if available)
  - JFB form submissions (via hl_core_form_submitted hook)
  - children assessment submissions
  - coaching attendance marked
  - overrides applied
  - pathway changes (trigger recompute)

---

# 9) Acceptance Tests (Plain English)

These are the required behaviors. The implementation must pass them.

## 9.1 Enrollment & Roles
1) If a user is enrolled in Cohort A as Teacher and in Cohort B as Mentor, reports for Cohort A show Teacher, and reports for Cohort B show Mentor.
2) A non-enrolled user cannot view any Cohort data (unless staff).

## 9.2 Team Constraints
3) In a Cohort, a participant cannot be assigned to two different Teams. Attempt must be blocked with an error.
4) A Team can have up to 2 mentors; adding a 3rd mentor must be blocked or require explicit override.

## 9.3 Teaching Assignments & Children Assessments
5) If a teacher is assigned to two classrooms in the same Cohort, two separate children assessment instances are required.
6) Children assessment completion for a teacher is 100% only when all required classroom instances are submitted.

## 9.4 Unlocking Logic
7) If prerequisites are incomplete, an activity stays locked even if the drip date has passed.
8) If prerequisites are complete but drip date is in the future, activity stays locked until the drip date.
9) If both fixed date and completion-delay drip rules exist, the activity becomes available only after BOTH are satisfied.

## 9.5 Overrides
10) Coach can exempt an activity, causing it to show 100% complete for that participant.
11) Only Admin can manual-unlock an activity (if implemented).
12) Overrides must be recorded in audit logs.

## 9.6 Reporting Visibility
13) Teacher can only view their own progress.
14) Mentor can view only their Team's participants.
15) Center Leader can view only their Center's participants.
16) District Leader can view only their District's participants.
17) Staff can view all.

## 9.7 Assessment Privacy
18) Non-staff roles cannot view teacher self-assessment answers (JFB Form Records are admin-only; HL Core front-end shows completion only).
19) Non-staff roles cannot view children assessment answers (only completion).
20) Staff view/export of children assessment responses must be logged.

## 9.8 Imports
21) User import matched by email: existing WP user is enrolled rather than duplicated.
22) Children import with ambiguous matches results in NEEDS_REVIEW; commit cannot proceed until resolved (unless staff forces).
23) Import preview supports row selection (checkbox) and bulk actions; commit respects selections.
24) After commit, a downloadable error report is available.

## 9.9 Coaching/Observation Linking
25) Coach can create a Coaching Session linked to one or more Observations.
26) Coaching attendance can be marked and reflected as 0/100 completion for the coaching attendance activity (when used).

## 9.10 JetFormBuilder Integration
27) When a teacher submits a self-assessment via JFB, the corresponding activity in their pathway shows 100% complete within the same page load or on next refresh.
28) When a mentor submits an observation via JFB, the observation record in HL Core is marked as "submitted".
29) If JetFormBuilder is deactivated, HL Core shows an admin notice and JFB-powered activities display a "form unavailable" message instead of breaking.

---

# 10) Explicit Implementation "Do Not Assume"

- Do not assume WooCommerce.
- Do not assume JetEngine CPT/meta schema.
- Do not assume SCORM standalone.
- Do not expose assessment responses to non-staff.
- Do not implement messaging (BuddyBoss provides it).
- Do not treat WP user roles as cohort roles.
- Do not build custom form rendering for teacher self-assessments or observations (JetFormBuilder handles those).
- Do not store teacher self-assessment or observation responses in HL Core tables (JFB Form Records handles that).
- DO build custom form rendering for children assessments (JFB cannot handle the dynamic per-child matrix).

---

End of file.
