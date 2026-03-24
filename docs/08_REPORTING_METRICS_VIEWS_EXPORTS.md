# Housman Learning Core Plugin — AI Library
## File: 08_REPORTING_METRICS_VIEWS_EXPORTS.md
Version: 3.0
Last Updated: 2026-03-24
Timezone: America/Bogota

---

# 0) Purpose

This document specifies:
- What reports exist
- Who can view what (scope)
- How completion is calculated for every component type
- How cycle-level completion % is computed
- Export requirements (CSV minimum)
- Rules for hiding assessment responses (staff-only)

Rules:
- Reporting must be based on Enrollment (User ↔ Cycle).
- Non-staff roles must never see assessment responses for Teacher/Child assessments.
- LearnDash course completion % is read from LearnDash; other components are binary 0/100.

---

# 1) Canonical Reporting Outputs

HL Core must provide at minimum:

1) **Cycle Dashboard (Staff)**
2) **District Report (District Leader + Staff)**
3) **School Report (School Leader + Staff)**
4) **Team Report (Mentor + Staff)**
5) **Participant Report (Teacher/Mentor self + Staff)**
6) **Partnership Summary (Staff + District Leader)** — Cross-cycle aggregation within a Partnership (container)
7) **Program vs Control Comparison (Staff)** — Side-by-side assessment outcome comparison when a Partnership contains both program and control cycles
8) **Program Progress Matrix (Staff + Leaders)** — Course-by-course completion grid for all participants in a Cycle. Rows = participants, Columns = all B2E courses (TC0–TC8, MC1–MC4). Values: completed, X% (in progress), empty (not started), - (not applicable for role/pathway). Filters: School, Team, Role. Export: CSV. See B2E_MASTER_REFERENCE.md §8.1.

Each report must support:
- On-screen table view
- CSV export (minimum)

---

# 2) Scope and Access Rules (Summary)

Reference: 03_ROLES_PERMISSIONS_REPORT_VISIBILITY.md

- Housman Admin: all cycles, all scopes
- Coach: all cycles, all scopes (as staff)
- District Leader: district scope within enrolled cycle(s)
- School Leader: school scope within enrolled cycle(s)
- Mentor: team scope within enrolled cycle(s)
- Teacher: self scope within enrolled cycle(s)

Non-negotiable:
- Teacher Self-Assessment responses and Child Assessment responses visible ONLY to staff.
- Non-staff reports show completion status only.

---

## 2.1 Cycle-Level Reporting

All reports operate at the Cycle level. Pathways belong directly to Cycles (no Phase entity). Reports filter by Cycle, and within a Cycle by pathway, school, team, or role.

Cycle filtering applies to: Cycle Dashboard, District Report, School Report, Team Report, Participant Report, and the Program Progress Matrix.

---

# 3) Completion Metrics

## 3.1 Component Completion Output (per Enrollment, per Component)
Every component must yield:

- completion_percent: integer 0..100
- completion_status: enum { "not_started", "in_progress", "complete" }
- completed_at: nullable timestamp (when reached 100)
- evidence_ref: optional pointer (e.g., LearnDash course_id, assessment instance_id)

---

## 3.2 Completion by Component Type

### 3.2.1 LearnDash Course Component
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

### 3.2.2 Teacher Self-Assessment Component (PRE or POST)
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

### 3.2.3 Child Assessment Component
Child assessments are required per (Cycle, Classroom, Teacher).

For a given teacher Enrollment:
- Determine required instances = all ChildAssessmentInstances for that teacher in the Cycle
  (computed from TeachingAssignments)

Completion:
- completion_percent = 100 only if ALL required instances are submitted
- otherwise completion_percent = 0

Display:
- Show a rollup status for the component: Complete / Incomplete
- Optionally show counts:
  - "Submitted X of Y classroom assessments"

Non-staff visibility:
- completion only, no responses

Staff visibility:
- may open an instance to view/export responses

---

### 3.2.4 Coaching Session Attendance Component
Completion is binary.

If the component represents a required count of sessions:
- completion_percent = 100 if attended_count >= required_count
- else 0

Default v1:
- required_count configurable per Cycle / Pathway
- attendance is marked by Coach or Admin only

Non-staff visibility:
- mentors can see completion status (optional)
- leaders/teachers: not required

---

### 3.2.5 Observation Requirement (Parallel)
If observations are configured as a parallel requirement (recommended v1):
- they appear on Mentor dashboards (and staff dashboards)
- they can still contribute to an overall "Mentor Requirements" area

If observations are modeled as a component:
- completion becomes binary based on a required count N:
  - completion_percent = 100 if submitted_observations >= N
  - else 0

In either case:
- observations should be available in reporting for staff
- mentors may see their own

---

### 3.2.6 Self-Reflection Component
Completion is binary.

- completion_percent = 0 if form not submitted
- completion_percent = 100 when form submitted

Display:
- Show "Complete" / "Not complete" + submission date
- Self-reflection form responses visible to teacher and staff

---

### 3.2.7 Reflective Practice Session Component
Completion is binary.

- completion_percent = 0 until the RP session is marked completed (required form submissions done)
- completion_percent = 100 when session completed

Display:
- Show session status (Pending / In Progress / Completed)
- For mentors/teachers: link to RP session page for their role-based view

---

### 3.2.8 Classroom Visit Component
Completion is binary.

- completion_percent = 0 if visit form not submitted
- completion_percent = 100 when visit form submitted

Display:
- Show "Complete" / "Not complete" + visit date
- For leaders: link to classroom visit form
- Classroom visit responses visible to the visiting leader and staff

---

# 4) Cycle Completion Percentage

## 4.1 Definition
For an Enrollment's assigned Pathway:
- pathway_completion_percent = weighted average across all components in that pathway

Formula:
- For each component i:
  - weight_i (default 1)
  - percent_i (0..100)
- completion_percent = sum(weight_i * percent_i) / sum(weight_i)

Rules:
- If no components assigned, completion_percent = 0 by default (or N/A if preferred)
- Binary components still participate (0 or 100)

## 4.2 Cycle Completion % (Overall)
Default v1:
- cycle_completion_percent = pathway_completion_percent (primary assigned pathway)

If future version supports multiple pathways per enrollment:
- cycle_completion_percent = weighted average across all assigned pathways.

---

# 5) Report Views (Minimum Tables)

## 5.1 Cycle Dashboard (Staff)
Audience:
- Housman Admin, Coach

Must include:
- filters:
  - Cycle
  - District (if present)
  - School
  - Team
  - Role (Teacher/Mentor/Leader)
  - Status (active/inactive enrollments)
- metrics:
  - participant count
  - average cycle completion %
  - component completion distribution (optional)
- table:
  - participant name
  - email
  - role(s)
  - school
  - team
  - cycle completion %
  - key component columns (configurable)

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
- All schools in the district for the Cycle

Must include:
- school list with:
  - number of participants
  - average completion %
  - key component completion rates (optional)
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
  - name, email, role(s), team, completion %, component columns

Exports:
- CSV

---

## 5.4 Team Report (Mentor + Staff)
Audience:
- Mentor, Staff

Scope:
- team members only (mentees), plus optionally mentor's own enrollment row

Must include:
- mentee table:
  - name, email
  - completion %
  - component completion status/percent for each component
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
- list of components with:
  - status (locked/available/complete)
  - completion percent
  - reason if locked (prereq/drip)
  - link to component (LearnDash course link or assessment form)
- no assessment responses visible after submission (unless staff)

---

## 5.6 Partnership Summary (Staff + District Leader)
Audience:
- Housman Admin, Coach, District Leader (for partnerships in their scope)

Scope:
- All cycles within the selected Partnership (container)

Must include:
- Partnership filter dropdown in reporting UI
- Summary metrics: total participants across cycles, average completion per cycle
- Per-cycle row: name, code, status, participant count, avg completion %
- Aggregate metrics: overall participant count, weighted avg completion

Exports:
- CSV with per-cycle summary rows

---

## 5.7 Program vs Control Comparison (Staff)
Audience:
- Housman Admin, Coach only

Availability:
- Only appears when a selected Partnership contains BOTH program cycles (is_control_group=false) AND control cycles (is_control_group=true)

Purpose:
- Measures program effectiveness by comparing pre-to-post assessment change between program and control groups

Must include:
- Info cards: program cycle name + participant count, control cycle name + participant count
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
- Groups by cycle.is_control_group and phase (pre/post)
- Computes mean, standard deviation, and pooled SD per section and per item

Cohen's d calculation:
- d = (program_change - control_change) / pooled_sd
- pooled_sd = sqrt(((n_program - 1) * sd_program^2 + (n_control - 1) * sd_control^2) / (n_program + n_control - 2))

Exports:
- CSV with per-item rows: section, item_id, item_text, program_pre_mean, program_post_mean, program_change, control_pre_mean, control_post_mean, control_change, cohens_d

**Primary analysis workflow:** The critical path for research comparison is CSV export → Stata. The in-app comparison report is a supplementary convenience view. The control Cycle does NOT need to be in the same Partnership as the program Cycle for the CSV export workflow to function — the control group exists as an independent research asset.

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
- cycle_code
- cycle_name
- district/school/team identifiers (where applicable)
- user name + email
- role(s)
- cycle completion %
- component completion columns:
  - LearnDash courses as percent
  - other components as 0 or 100

## 7.2 Assessment Response Exports (Staff-only)
Separate export endpoints for:
- Teacher Self-Assessments (pre/post)
- Child Assessments (infant/toddler/preschool/k2)
- Program vs Control Comparison (when Partnership filter active; includes Cohen's d)

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
