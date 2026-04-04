# Housman Learning Core Plugin — AI Library
## File: 00_README_SCOPE.md
Version: 3.0
Last Updated: 2026-03-24
Timezone: America/Bogota

---

# 1) Purpose (What to build)

Build a **single WordPress plugin** ("HL Core") that becomes the system-of-record for Housman Learning Academy Cycle and Partnership management.

The plugin must manage:
- Organizations: optional Districts, Schools, Classrooms
- People and cycle participation: Users enrolled into a Cycle with Cycle Roles
- Teams within Schools for mentorship structure
- Cycle learning configuration: Pathways and Components with prerequisite + drip rules
- Assessments: Teacher Self-Assessment (custom PHP instrument system) and Child Assessment (custom PHP)
- Mentorship workflow: Observations (via JetFormBuilder, submitted by Mentors), Coaching Sessions (custom admin CRUD, logged by Coaches), Reflective Practice Sessions (mentor-teacher RP events with RP Notes + Action Plan forms), Self-Reflections (teacher self-reflection after each course), and Classroom Visits (leader observations of teacher classrooms)
- Imports: roster + children + classroom relationships (CSV/XLS/XLSX) with preview + validation
- Reporting: progress/completion by scope (Cycle / District / School / Team / User), export to CSV
- Audit logs: key state changes and overrides

The system is **primarily B2B**. There is **no WooCommerce**, no checkout logic, and no "buyer" tracking.

**Products managed by HL Core:** The B2E Mastery Program (2-year, 25-course professional development — full Cycle management with Pathways, Teams, Coaching, Assessments); Short Courses (standalone 2-3 hour courses — institutional purchase uses simple course-type Cycle, individual purchase uses Individual Enrollment); ECSELent Adventures Curriculum online training (same model as Short Courses). See `B2E_MASTER_REFERENCE.md` §1 for the full product catalog.

---

# 2) Critical Definitions (Canonical Meaning)

**Partnership**
A Partnership is an optional container entity that groups one or more Cycles together for organizational purposes. Cycles can exist without a Partnership (`partnership_id` is nullable).
Example: "B2E Mastery - Lutheran Services Florida".

**Cycle**
A Cycle represents the full program engagement for a district/institution. For the B2E Mastery Program, this spans the entire multi-year contract.
Example: "ELCPB B2E Mastery 2025-2027".

A Cycle contains:
- participants (users) via Enrollment
- Pathways and Components configuration (Pathways belong directly to the Cycle)
- teams, classrooms, children rosters
- progress, assessments, observation/coaching artifacts
- reports and exports for allowed roles

Cycle has a `cycle_type` field: `program` (full B2E management with Pathways, Teams, etc.) or `course` (simple institutional course access with auto-generated Pathway/Component).

**Enrollment**
Enrollment is the join between (User ↔ Cycle). Cycle roles MUST be stored on Enrollment, NOT on the WP user.
This preserves history (a user can be Teacher in one Cycle and Mentor in another).

**Cycle Roles** (assigned per Enrollment)
- Teacher
- Mentor
- School Leader
- District Leader

A user may have different roles across different Cycles.

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
- For non-LearnDash components: HL Core must track completion itself (0% or 100%).

HL Core must NOT re-implement an LMS. It configures Partnerships and reads LearnDash progress.

## 4.2 JetFormBuilder
HL Core uses JetFormBuilder for **observations only** — mentor-submitted forms about a teacher's classroom practice. JFB provides the visual form editor so Housman admins can customize observation questions without a developer.

**Teacher self-assessments** now use HL Core's custom PHP instrument system (see doc 06) — NOT JetFormBuilder. Legacy JFB support is retained for backward compatibility only.

Integration mechanism (observations):
- JFB forms include hidden fields for HL Core context (enrollment_id, component_id, cycle_id, observation_id)
- JFB fires a "Call Hook" post-submit action (`hl_core_form_submitted`)
- HL Core's hook listener updates observation status and component completion
- Observation responses are stored in JFB Form Records (not in HL Core tables)

HL Core does NOT create or modify JFB forms programmatically. Admins manage forms in JFB's native Gutenberg-based editor.

JetFormBuilder must be installed and active for observation forms to work. HL Core should display an admin notice if JFB is not active.

## 4.3 BuddyBoss
BuddyBoss messaging is enabled globally; HL Core does not restrict messaging.
HL Core may optionally add partnership-related navigation links or dashboards, but it is not responsible for messaging features.

---

# 5) Security Model (Must-follow rules)

## 5.1 Staff roles (system-level)
- Housman Admin (WordPress admin): full permissions
- Coach (Housman staff): can create users; can view assessment responses; can mark coaching sessions; can exempt components

## 5.2 Client roles (cycle-level via Enrollment)
- District Leader: can view/download district scope progress reports; can create new users ONLY within their Cycle and District scope; cannot edit/delete existing users; cannot reset passwords
- School Leader: can view/download school scope progress reports; can create new users ONLY within their Cycle and School scope; cannot edit/delete existing users; cannot reset passwords
- Mentor: can view/download team scope progress reports; cannot manage users
- Teacher: can view own progress only; cannot manage users

## 5.3 Assessment Privacy
Raw responses for:
- Teacher Self-Assessments (stored in `hl_teacher_assessment_instance.responses_json` — enforced by HL_Security)
- Child Assessments (stored in `hl_child_assessment_childrow` — enforced by HL_Security)
are visible ONLY to Housman Admins and Coaches.

Non-staff roles may see completion status only.

## 5.4 Capability Checks
Every write action must check server-side capabilities and cycle scope.
Never rely on front-end hiding alone.

---

# 6) Data Model Principles (High-level)

## 6.1 Prefer custom tables for core domain data
HL Core should not rely on scattered post_meta/user_meta as the primary database for:
- Partnerships, cycles, enrollments, team membership, classroom assignments
- Pathway graph + component states
- Child assessment responses
- Imports + audit logs

Use WP Users as the identity layer only.

## 6.2 Use JetFormBuilder for observation form response storage
For observations (the only remaining JFB-powered form type), responses are stored in JFB Form Records. Teacher self-assessments use HL Core's custom instrument system (`hl_teacher_assessment_instance.responses_json`). RP sessions, classroom visits, and self-reflections store responses in their own HL Core tables (`hl_rp_session_submission`, `hl_classroom_visit_submission`, `hl_coaching_session_submission`).

## 6.3 Keep "Org Structure" independent from "Cycle Runs"
Districts and Schools persist over time.
Cycles can repeat yearly within Partnerships.
One District/School can participate in multiple Cycles.

---

# 7) Rules Engine Principles

Components are available only if ALL applicable constraints are satisfied:
- Prerequisites satisfied
- Drip/release constraints satisfied
  - fixed calendar date and/or completion-based delay
- Not manually locked

"Most restrictive wins" = apply all gates, require all to pass.

Overrides:
- Exempt component (mark complete): Housman Admin or Coach
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
- If user exists, associate them to the Cycle and apply provided enrollment data.

Children identity:
- Some institutions do not provide stable child IDs.
- HL Core must generate internal child_uuid and provide a deterministic matching strategy with a "Needs Review" fallback.

---

# 9) Reporting Requirements (High-level)

All report viewers need:
- Component-level completion: LearnDash course %; others are 0% or 100%
- Cycle-level completion %: weighted average across assigned components (default weight=1)

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
4) 04_PATHWAYS_COMPONENTS_RULES.md
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
