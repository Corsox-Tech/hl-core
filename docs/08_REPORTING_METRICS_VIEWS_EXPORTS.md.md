# Housman Learning Core Plugin — AI Library
## File: 08_REPORTING_METRICS_VIEWS_EXPORTS.md
Version: 1.1
Last Updated: 2026-02-24
Timezone: America/Bogota

---

# 0) Purpose

This document specifies:
- What reports exist
- Who can view what (scope)
- How completion is calculated for every activity type
- How cohort-level completion % is computed
- Export requirements (CSV minimum)
- Rules for hiding assessment responses (staff-only)

Rules:
- Reporting must be based on Enrollment (User ↔ Cohort).
- Non-staff roles must never see assessment responses for Teacher/Child assessments.
- LearnDash course completion % is read from LearnDash; other activities are binary 0/100.

---

# 1) Canonical Reporting Outputs

HL Core must provide at minimum:

1) **Cohort Dashboard (Staff)**
2) **District Report (District Leader + Staff)**
3) **School Report (School Leader + Staff)**
4) **Team Report (Mentor + Staff)**
5) **Participant Report (Teacher/Mentor self + Staff)**
6) **Cohort Group Summary (Staff + District Leader)** — Cross-cohort aggregation within a Cohort Group
7) **Program vs Control Comparison (Staff)** — Side-by-side assessment outcome comparison when a Cohort Group contains both program and control cohorts

Each report must support:
- On-screen table view
- CSV export (minimum)

---

# 2) Scope and Access Rules (Summary)

Reference: 03_ROLES_PERMISSIONS_REPORT_VISIBILITY.md

- Housman Admin: all cohorts, all scopes
- Coach: all cohorts, all scopes (as staff)
- District Leader: district scope within enrolled cohort(s)
- School Leader: school scope within enrolled cohort(s)
- Mentor: team scope within enrolled cohort(s)
- Teacher: self scope within enrolled cohort(s)

Non-negotiable:
- Teacher Self-Assessment responses and Child Assessment responses visible ONLY to staff.
- Non-staff reports show completion status only.

---

# 3) Completion Metrics

## 3.1 Activity Completion Output (per Enrollment, per Activity)
Every activity must yield:

- completion_percent: integer 0..100
- completion_status: enum { "not_started", "in_progress", "complete" }
- completed_at: nullable timestamp (when reached 100)
- evidence_ref: optional pointer (e.g., LearnDash course_id, assessment instance_id)

---

## 3.2 Completion by Activity Type

### 3.2.1 LearnDash Course Activity
- Source of truth: LearnDash
- completion_percent = LearnDash progress percent (0..100)
- completion_status:
  - not_started if percent = 0 and no progress
  - in_progress if 0 < percent < 100
  - complete if percent = 100 OR LearnDash completion flag
- completed_at = LearnDash completed timestamp if available

Display:
- Show course title
- Show percent complete
- Show completed date if complete

---

### 3.2.2 Teacher Self-Assessment Activity (PRE or POST)
- completion_percent = 0 if required instance not submitted
- completion_percent = 100 if submitted
- completion_status:
  - not_started (no saved progress)
  - in_progress (draft saved; optional)
  - complete (submitted)

Display for non-staff:
- Show only: "Complete" / "Not complete" (+ submitted date if complete)
- Do NOT show answers or question-level data

Display for staff:
- Staff can open response viewer and export

---

### 3.2.3 Child Assessment Activity
Child assessments are required per (Cohort, Classroom, Teacher).

For a given teacher Enrollment:
- Determine required instances = all ChildrenAssessmentInstances for that teacher in the Cohort
  (computed from TeachingAssignments)

Completion:
- completion_percent = 100 only if ALL required instances are submitted
- otherwise completion_percent = 0

Display:
- Show a rollup status for the activity: Complete / Incomplete
- Optionally show counts:
  - "Submitted X of Y classroom assessments"

Non-staff visibility:
- completion only, no responses

Staff visibility:
- may open an instance to view/export responses

---

### 3.2.4 Coaching Session Attendance Activity
Completion is binary.

If the activity represents a required count of sessions:
- completion_percent = 100 if attended_count >= required_count
- else 0

Default v1:
- required_count configurable per Cohort / Pathway
- attendance is marked by Coach or Admin only

Non-staff visibility:
- mentors can see completion status (optional)
- leaders/teachers: not required

---

### 3.2.5 Observation Requirement (Parallel)
If observations are configured as a parallel requirement (recommended v1):
- they appear on Mentor dashboards (and staff dashboards)
- they can still contribute to an overall "Mentor Requirements" area

If observations are modeled as an activity:
- completion becomes binary based on a required count N:
  - completion_percent = 100 if submitted_observations >= N
  - else 0

In either case:
- observations should be available in reporting for staff
- mentors may see their own

---

# 4) Cohort Completion Percentage

## 4.1 Definition
For an Enrollment’s assigned Pathway:
- pathway_completion_percent = weighted average across all activities in that pathway

Formula:
- For each activity i:
  - weight_i (default 1)
  - percent_i (0..100)
- completion_percent = sum(weight_i * percent_i) / sum(weight_i)

Rules:
- If no activities assigned, completion_percent = 0 by default (or N/A if preferred)
- Binary activities still participate (0 or 100)

## 4.2 Cohort Completion % (Overall)
Default v1:
- cohort_completion_percent = pathway_completion_percent (primary assigned pathway)

If future version supports multiple pathways per enrollment:
- cohort_completion_percent = weighted average across all assigned pathways.

---

# 5) Report Views (Minimum Tables)

## 5.1 Cohort Dashboard (Staff)
Audience:
- Housman Admin, Coach

Must include:
- filters:
  - Cohort
  - District (if present)
  - School
  - Team
  - Role (Teacher/Mentor/Leader)
  - Status (active/inactive enrollments)
- metrics:
  - participant count
  - average cohort completion %
  - activity completion distribution (optional)
- table:
  - participant name
  - email
  - role(s)
  - school
  - team
  - cohort completion %
  - key activity columns (configurable)

Exports:
- CSV for the table with the applied filters

Staff-only expansions:
- open assessment responses
- export assessment responses (separate export)

---

## 5.2 District Report (District Leader + Staff)
Audience:
- District Leader, Staff

Scope:
- All schools in the district for the Cohort

Must include:
- school list with:
  - number of participants
  - average completion %
  - key activity completion rates (optional)
- participant table (optional detail view):
  - same columns as staff but limited to district scope

Exports:
- CSV of participant table and/or school summary

---

## 5.3 School Report (School Leader + Staff)
Audience:
- School Leader, Staff

Scope:
- school only

Must include:
- team summary (if teams exist)
- participant table:
  - name, email, role(s), team, completion %, activity columns

Exports:
- CSV

---

## 5.4 Team Report (Mentor + Staff)
Audience:
- Mentor, Staff

Scope:
- team members only (mentees), plus optionally mentor’s own enrollment row

Must include:
- mentee table:
  - name, email
  - completion %
  - activity completion status/percent for each activity
  - "blocked" indicators (optional: locked by prereq/drip)
- mentor-specific parallel requirements:
  - observation tasks/requirements
  - coaching attendance (if applicable)

Exports:
- CSV (optional for mentors; required for staff)

---

## 5.5 Participant Report (Self)
Audience:
- Teacher/Mentor viewing their own progress

Must include:
- pathway completion %
- list of activities with:
  - status (locked/available/complete)
  - completion percent
  - reason if locked (prereq/drip)
  - link to activity (LearnDash course link or assessment form)
- no assessment responses visible after submission (unless staff)

---

## 5.6 Cohort Group Summary (Staff + District Leader)
Audience:
- Housman Admin, Coach, District Leader (for groups in their scope)

Scope:
- All cohorts within the selected Cohort Group

Must include:
- Group filter dropdown in reporting UI
- Summary metrics: total participants across cohorts, average completion per cohort
- Per-cohort row: name, code, status, participant count, avg completion %
- Aggregate metrics: overall participant count, weighted avg completion

Exports:
- CSV with per-cohort summary rows

---

## 5.7 Program vs Control Comparison (Staff)
Audience:
- Housman Admin, Coach only

Availability:
- Only appears when a selected Cohort Group contains BOTH program cohorts (is_control_group=false) AND control cohorts (is_control_group=true)

Purpose:
- Measures program effectiveness by comparing pre-to-post assessment change between program and control groups

Must include:
- Info cards: program cohort name + participant count, control cohort name + participant count
- Per-section comparison table (for teacher self-assessment):
  - Section name
  - Program: PRE mean, POST mean, change
  - Control: PRE mean, POST mean, change
  - Cohen's d effect size
- Per-item comparison table (expandable per section):
  - Item text
  - Program: PRE mean, POST mean, change
  - Control: PRE mean, POST mean, change
  - Cohen's d
- Color coding: positive change in green, negative in red, neutral in gray

Data source:
- Aggregates `responses_json` from `hl_teacher_assessment_instance` for all submitted instances
- Groups by cohort.is_control_group and phase (pre/post)
- Computes mean, standard deviation, and pooled SD per section and per item

Cohen's d calculation:
- d = (program_change - control_change) / pooled_sd
- pooled_sd = sqrt(((n_program - 1) * sd_program^2 + (n_control - 1) * sd_control^2) / (n_program + n_control - 2))

Exports:
- CSV with per-item rows: section, item_id, item_text, program_pre_mean, program_post_mean, program_change, control_pre_mean, control_post_mean, control_change, cohens_d

---

# 6) Locked/Blocked Indicators (UX Requirements)

Reports should optionally show:
- "Locked (Prerequisite)" with missing prerequisites list
- "Locked (Drip)" with next_available_at date/time if computable

Mentors and teachers benefit from knowing why something is locked.

---

# 7) Exports

## 7.1 Minimum Export Format
CSV.

Exports must include:
- cohort_code
- cohort_name
- district/school/team identifiers (where applicable)
- user name + email
- role(s)
- cohort completion %
- activity completion columns:
  - LearnDash courses as percent
  - other activities as 0 or 100

## 7.2 Assessment Response Exports (Staff-only)
Separate export endpoints for:
- Teacher Self-Assessments (pre/post)
- Child Assessments (infant/toddler/preschool)
- Program vs Control Comparison (when Cohort Group filter active; includes Cohen's d)

These exports may include:
- question-level responses
- child-level rows for child assessment

Non-staff must not access these exports.

---

# 8) Performance Considerations (Non-functional)

Reporting must be performant for typical B2B sizes:
- district with multiple schools
- schools with multiple teams
- teachers with multiple classrooms
- child assessments with many children per classroom

Recommendations:
- precompute or cache completion rollups per enrollment
- avoid per-row LearnDash calls in large tables; batch read progress where possible

---

End of file.
