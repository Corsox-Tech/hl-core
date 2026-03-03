# Housman Learning Core Plugin — AI Library
## File: 00_README_SCOPE.md
Version: 1.1
Last Updated: 2026-02-17
Timezone: America/Bogota

---

# 1) Purpose (What to build)

Build a **single WordPress plugin** ("HL Core") that becomes the system-of-record for Housman Learning Academy Track and Cohort management.

The plugin must manage:
- Organizations: optional Districts, Schools, Classrooms
- People and track participation: Users enrolled into a Track with Track Roles
- Teams within Schools for mentorship structure
- Track learning configuration: Pathways and Activities with prerequisite + drip rules
- Assessments: Teacher Self-Assessment (via JetFormBuilder) and Child Assessment (custom PHP)
- Mentorship workflow: Observations (via JetFormBuilder, submitted by Mentors) and Coaching Sessions (custom admin CRUD, logged by Coaches)
- Imports: roster + children + classroom relationships (CSV/XLS/XLSX) with preview + validation
- Reporting: progress/completion by scope (Track / District / School / Team / User), export to CSV
- Audit logs: key state changes and overrides

The system is **primarily B2B**. There is **no WooCommerce**, no checkout logic, and no "buyer" tracking.

**Products managed by HL Core:** The B2E Mastery Program (2-year, 25-course professional development — full Track management with Phases, Pathways, Teams, Coaching, Assessments); Short Courses (standalone 2-3 hour courses — institutional purchase uses simple course-type Track, individual purchase uses Individual Enrollment); ECSELent Adventures Curriculum online training (same model as Short Courses). See `B2E_MASTER_REFERENCE.md` §1 for the full product catalog.

---

# 2) Critical Definitions (Canonical Meaning)

**Cohort**
A Cohort is an optional container entity that groups one or more Tracks together for organizational purposes. Tracks can exist without a Cohort (`cohort_id` is nullable).
Example: "B2E Mastery - Lutheran Services Florida".

**Track**
A Track represents the full program engagement for a district/institution. For the B2E Mastery Program, this spans the entire multi-year contract (all Phases).
Example: "ELCPB B2E Mastery 2025-2027".

A Track contains:
- Phases (time-bounded periods, e.g., Phase 1 = Year 1, Phase 2 = Year 2)
- participants (users) via Enrollment
- pathways + activities configuration (via Phases)
- teams, classrooms, children rosters
- progress, assessments, observation/coaching artifacts
- reports and exports for allowed roles

Track has a `track_type` field: `program` (full B2E management with Phases, Pathways, Teams, etc.) or `course` (simple institutional course access with auto-generated Phase/Pathway/Activity).

**Enrollment**
Enrollment is the join between (User ↔ Track). Track roles MUST be stored on Enrollment, NOT on the WP user.
This preserves history (a user can be Teacher in one Track and Mentor in another).

**Track Roles** (assigned per Enrollment)
- Teacher
- Mentor
- School Leader
- District Leader

A user may have different roles across different Tracks.

---

# 3) Non-Goals (Explicitly Out of Scope)

Do NOT build or assume:
- WooCommerce or any e-commerce tracking
- reliance on existing JetEngine CPT/meta schema or Uncanny Automator workflows
- direct SCORM ingestion (SCORM lives inside LearnDash Lessons; HL Core only needs to read LearnDash progress)
- a generalized social network or messaging system (BuddyBoss already provides messaging)
- custom form rendering for observations (JetFormBuilder handles those; teacher self-assessments and child assessments use HL Core's custom PHP instrument system — see doc 06)

---

# 4) Integrations (What HL Core must integrate with)

## 4.1 LearnDash
HL Core must read LearnDash course completion and (if available) completion percentage.
- For LearnDash Courses: use LearnDash as the source of truth for course completion/%.
- For non-LearnDash activities: HL Core must track completion itself (0% or 100%).

HL Core must NOT re-implement an LMS. It configures Cohorts and reads LearnDash progress.

## 4.2 JetFormBuilder
HL Core uses JetFormBuilder for **observations only** — mentor-submitted forms about a teacher's classroom practice. JFB provides the visual form editor so Housman admins can customize observation questions without a developer.

**Teacher self-assessments** now use HL Core's custom PHP instrument system (see doc 06) — NOT JetFormBuilder. Legacy JFB support is retained for backward compatibility only.

Integration mechanism (observations):
- JFB forms include hidden fields for HL Core context (enrollment_id, activity_id, track_id, observation_id)
- JFB fires a "Call Hook" post-submit action (`hl_core_form_submitted`)
- HL Core's hook listener updates observation status and activity completion
- Observation responses are stored in JFB Form Records (not in HL Core tables)

HL Core does NOT create or modify JFB forms programmatically. Admins manage forms in JFB's native Gutenberg-based editor.

JetFormBuilder must be installed and active for observation forms to work. HL Core should display an admin notice if JFB is not active.

## 4.3 BuddyBoss
BuddyBoss messaging is enabled globally; HL Core does not restrict messaging.
HL Core may optionally add cohort-related navigation links or dashboards, but it is not responsible for messaging features.

---

# 5) Security Model (Must-follow rules)

## 5.1 Staff roles (system-level)
- Housman Admin (WordPress admin): full permissions
- Coach (Housman staff): can create users; can view assessment responses; can mark coaching sessions; can exempt activities

## 5.2 Client roles (track-level via Enrollment)
- District Leader: can view/download district scope progress reports; can create new users ONLY within their Track and District scope; cannot edit/delete existing users; cannot reset passwords
- School Leader: can view/download school scope progress reports; can create new users ONLY within their Track and School scope; cannot edit/delete existing users; cannot reset passwords
- Mentor: can view/download team scope progress reports; cannot manage users
- Teacher: can view own progress only; cannot manage users

## 5.3 Assessment Privacy
Raw responses for:
- Teacher Self-Assessments (stored in JFB Form Records — admin-only access)
- Child Assessments (stored in hl_child_assessment_childrow — enforced by HL_Security)
are visible ONLY to Housman Admins and Coaches.

Non-staff roles may see completion status only.

## 5.4 Capability Checks
Every write action must check server-side capabilities and track scope.
Never rely on front-end hiding alone.

---

# 6) Data Model Principles (High-level)

## 6.1 Prefer custom tables for core domain data
HL Core should not rely on scattered post_meta/user_meta as the primary database for:
- Cohorts, tracks, enrollments, team membership, classroom assignments
- Pathway graph + activity states
- Child assessment responses
- Imports + audit logs

Use WP Users as the identity layer only.

## 6.2 Use JetFormBuilder for form response storage where applicable
For teacher self-assessments and observations, form responses are stored in JFB Form Records. HL Core stores only orchestration data (instance status, submission timestamps, JFB record references).

## 6.3 Keep "Org Structure" independent from "Track Runs"
Districts and Schools persist over time.
Tracks can repeat yearly within Cohorts.
One District/School can participate in multiple Tracks.

---

# 7) Rules Engine Principles

Activities are available only if ALL applicable constraints are satisfied:
- Prerequisites satisfied
- Drip/release constraints satisfied
  - fixed calendar date and/or completion-based delay
- Not manually locked

"Most restrictive wins" = apply all gates, require all to pass.

Overrides:
- Exempt activity (mark complete): Housman Admin or Coach
- Manual unlock: Housman Admin
- Optional "grace unlock": allowed only if implemented without complexity; not required for v1

---

# 8) Imports (Roster) Principles

Roster imports support CSV/XLS/XLSX.
Must support:
- Preview screen with validation results
- Row-level selection (checkbox)
- Bulk actions (select all create/update/skip)
- "Needs Review" state for ambiguous child identity matches
- Downloadable error report (CSV)

User identity:
- Users matched by email (unique).
- If user exists, associate them to the Track and apply provided enrollment data.

Children identity:
- Some institutions do not provide stable child IDs.
- HL Core must generate internal child_uuid and provide a deterministic matching strategy with a "Needs Review" fallback.

---

# 9) Reporting Requirements (High-level)

All report viewers need:
- Activity-level completion: LearnDash course %; others are 0% or 100%
- Track-level completion %: weighted average across assigned activities (default weight=1)

Scope-based visibility:
- District Leader: district scope
- School Leader: school scope
- Mentor: team scope
- Teacher: self scope
- Staff: all scopes

Exports:
- CSV minimum for all report types.

---

# 10) Priority of Truth (When files conflict)

If any documents conflict, follow this priority order:
0) B2E_MASTER_REFERENCE.md (authoritative when conflicts exist with any doc below)
1) 01_GLOSSARY_CANONICAL_TERMS.md
2) 02_DOMAIN_MODEL_ORG_STRUCTURE.md
3) 03_ROLES_PERMISSIONS_REPORT_VISIBILITY.md
4) 04_COHORT_PATHWAYS_ACTIVITIES_RULES.md
5) 05_UNLOCKING_LOGIC_PREREQS_DRIP_OVERRIDES.md
6) 06_ASSESSMENTS_CHILDREN_TEACHER_OBSERVATION_COACHING.md
7) 07_IMPORTS_ROSTERS_IDENTITIES_MATCHING.md
8) 08_REPORTING_METRICS_VIEWS_EXPORTS.md
9) 09_PLUGIN_ARCHITECTURE_CONSTRAINTS_ACCEPTANCE_TESTS.md

---

# 11) Open Questions (Allowed assumptions)

If something is not specified in this library, the AI should:
- default to the simplest secure behavior
- record the assumption in code comments and/or an admin-facing setting
- avoid inventing new business concepts without adding them to the Glossary

End of file.
