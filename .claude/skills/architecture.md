---
name: architecture
description: Domain model, roles, coach assignment, partnerships, cycle types, scope service, forms, control groups, cross-pathway events, component types, instruments, form renderer pattern, guided tours, directory tree
---

# HL Core Domain Architecture Reference

## Documentation Files (in plugin docs/)
| File | Covers |
|------|--------|
| 00_README_SCOPE.md | Project scope and high-level requirements |
| 01_GLOSSARY_CANONICAL_TERMS.md | Terminology definitions including Partnership, Cycle, Control Group |
| 02_DOMAIN_MODEL_ORG_STRUCTURE.md | Org units, partnerships, enrollments, teams |
| 03_ROLES_PERMISSIONS_REPORT_VISIBILITY.md | Roles, capabilities, access control |
| 04_PATHWAYS_COMPONENTS_RULES.md | Pathways, components, prerequisite rules, component eligibility references |
| 05_UNLOCKING_LOGIC_PREREQS_DRIP_OVERRIDES.md | Unlock logic, drip rules, overrides |
| 06_ASSESSMENTS_CHILDREN_TEACHER_OBSERVATION_COACHING.md | Assessment instruments (custom PHP for teacher + children, JFB for observations only), coaching |
| 07_IMPORTS_ROSTERS_IDENTITIES_MATCHING.md | CSV import pipeline, identity matching |
| 08_REPORTING_METRICS_VIEWS_EXPORTS.md | Reporting, metrics, export formats, program vs control comparison |
| 09_PLUGIN_ARCHITECTURE_CONSTRAINTS_ACCEPTANCE_TESTS.md | Architecture, constraints, acceptance criteria |
| 10_FRONTEND_PAGES_NAVIGATION_UX.md | Front-end pages, sidebar navigation (§16), listing page specs (§17), coach assignment model (§13-15) |
| B2E_MASTER_REFERENCE.md | **Authoritative** — Product catalog, revised architecture (Partnership, Cycle Types, Individual Enrollments), control group clarification |
| *(archived)* DOC_UPDATE_INSTRUCTIONS.md | Moved to `docs/archive/`. File-by-file instructions for updating docs to match B2E Master Reference. |

## Terminology (Post-Rename V3)

Code, DB, and UI all use the same terms now — no remapping layer needed. `HL_Label_Remap` has been removed from code.

## WordPress Roles vs Cycle Roles (Critical Architecture Decision)

HL Core creates ONE custom WP role: **Coach** (with `read`, `manage_hl_core`, `hl_view_enrollments`). Administrators get `manage_hl_core` capability added.

**ALL cycle-specific roles** (teacher, mentor, school_leader, district_leader) are stored in the `hl_enrollment.roles` JSON column — NOT as WordPress roles. This is by design:

| Who | WP Role | HL Core Identification |
|---|---|---|
| Teachers, Mentors, Leaders | `subscriber` (or any basic WP role) | `hl_enrollment.roles` JSON per cycle |
| Housman Coaches | `coach` (custom WP role) | `manage_hl_core` capability |
| Housman Admins | `administrator` | `manage_hl_core` capability |

**Why:** A user can be a teacher in one cycle and a mentor in another. WP roles are global; enrollment roles are per-cycle. The BuddyBoss sidebar, front-end pages, and scope filtering all use enrollment roles from `hl_enrollment`, not WP roles.

Some legacy WP roles exist (`school_district`, `teacher`, `mentor`, `school_leader`) from old Elementor pages — HL Core does NOT use these.

## Coach Assignment Architecture

Coaches are assigned at three scope levels with "most specific wins" resolution:

1. **School level** — coach is default for all enrollments at that school
2. **Team level** — overrides school assignment for team members
3. **Enrollment level** — overrides everything for a specific participant

Stored in `hl_coach_assignment` table with `effective_from`/`effective_to` dates. Reassignment closes the old record and creates a new one — full history preserved. Coaching sessions retain the original `coach_user_id` (frozen at time of session).

See doc 10 §13-14 for full spec.

## Cycle Entity

A Cycle is a time-bounded yearly run within a Partnership. It is the primary operational entity — enrollments, teams, pathways, components, and assessments all belong to a Cycle.

**Key properties:**
- Stored in `hl_cycle` table (cycle_id, cycle_uuid, partnership_id, cycle_name, cycle_code, cycle_type, district_id, is_control_group, status, start_date, end_date)
- Partnership is the parent container: `hl_cycle.partnership_id` → `hl_partnership`
- Pathways belong to Cycle: `hl_pathway.cycle_id` → `hl_cycle`
- Enrollment stays at Cycle level
- Teams also stay at Cycle level

## Cycle Types

`hl_cycle.cycle_type` distinguishes complexity levels:

| Type | Usage | Complexity |
|------|-------|-----------|
| `program` | B2E Mastery and similar multi-year programs | Full: Pathways, Teams, Coaching, Assessments |
| `course` | Institutional short course purchases | Minimal: auto-created single Pathway + Component |

Course-type Cycles hide: Teams tab, Coaching tab, Assessment tabs, Pathway editor.

## Course Catalog (Multilingual Course Mapping)

`hl_course_catalog` maps logical courses (e.g., TC1, MC3) to their EN/ES/PT LearnDash course variants. One catalog entry = one component = one completion state, regardless of which language the user completes.

**Key classes:** `HL_Course_Catalog` (domain model, `resolve_ld_course_id()` static helper), `HL_Course_Catalog_Repository` (CRUD + reverse lookup + duplicate detection), `HL_Admin_Course_Catalog` (admin CRUD page with AJAX LD course search).

**Integration points:**
- **Routing:** `HL_Pathway_Routing_Service::$stages` uses `catalog_codes` (not course IDs). Stage completion checks any language variant.
- **LD completion:** `on_course_completed()` does catalog-first lookup (by LD course ID → catalog_id → components), with `external_ref` fallback gated behind `hl_catalog_migration_complete` option.
- **Frontend:** `HL_Course_Catalog::resolve_ld_course_id($component, $enrollment)` resolves language-specific course link based on `$enrollment->language_preference`. Used in 7 call sites across 4 frontend files.
- **Import:** Optional `language` CSV column sets `hl_enrollment.language_preference`.
- **Reporting:** Component detail view uses catalog title (canonical English name).
- **Pathway admin:** Component form shows catalog dropdown with language badges (`[EN] [ES]`).

**Schema:** `catalog_code` (UNIQUE, stable key), `ld_course_en`/`ld_course_es`/`ld_course_pt` (UNIQUE, nullable — MySQL ignores NULLs in UNIQUE), `status` (active/archived). Schema revision 40.

## Individual Enrollments (PLANNED — Phase 33 in Build Queue)

For individual (non-institutional) course purchases. Stored in `hl_individual_enrollment` (user_id, course_id, enrolled_at, expires_at, status).

- Admin pages: Course List + Course Detail (under HL Core menu)
- Frontend: "My Courses" section on Dashboard for active individual enrollments
- Expiration enforcement: blocks access when expires_at < now
- Not related to Cycles — this is a separate, simpler enrollment path for standalone course access

## Scope Service (HL_Scope_Service)

Shared static helper used by ALL listing pages for role-based data filtering:
- Admin → sees all (no restriction)
- Coach → filtered by `hl_coach_assignment` cycle_ids + own enrollments
- District Leader → expands to all schools in their district
- School Leader → filtered to their school(s)
- Mentor → filtered to their team(s)
- Teacher → filtered to their own enrollment/assignments

Static cache per user_id per request. Convenience helpers: `can_view_cycle()`, `can_view_school()`, `has_role()`, `filter_by_ids()`.

## Sidebar Navigation

The sidebar menu is rendered programmatically by `HL_BuddyBoss_Integration::build_menu_items()` — NOT via WordPress Menus admin. 16 menu items with role-based visibility. Staff WITHOUT enrollment see only directory/management pages. Staff WITH enrollment see both. Coach-only users see coach tools but not staff tools. See code for full menu item list.

## Forms Architecture

HL Core uses **custom PHP** for all forms except legacy observations:
- **Teacher Self-Assessment** — Custom instrument system (`hl_teacher_assessment_instrument` JSON definitions). PRE = single-column, POST = dual-column retrospective.
- **Child Assessment** — Dynamic per-child matrix from roster + instrument. Grouped by `frozen_age_group`. AJAX draft auto-save.
- **Coaching Sessions** — Admin CRUD workflow.
- **Cross-pathway forms** — RP Notes, Action Plan, Self-Reflection, Classroom Visit (5 renderers, see below).
- **JFB (legacy, pending removal)** — Observations only. System checks `teacher_instrument_id` first (custom), falls back to `jfb_form_id`.

## Cross-Pathway Events

Cross-pathway events are activities that span multiple participants across a cycle, loosely coupled to the component/pathway system via `component_type` dispatch on the Component Page.

### 3 Event Types

| Event Type | component_type ENUM | Participants |
|---|---|---|
| **Reflective Practice Session** | `reflective_practice_session` | Mentor + Teacher |
| **Classroom Visit** | `classroom_visit` | Leader + Teacher |
| **Self-Reflection** | `self_reflection` | Teacher (solo) |

Events are loosely coupled to components: Component Page dispatches to the appropriate renderer based on `component_type`. Form submissions store `responses_json` with `instrument_id` reference. 5 DB tables (`hl_rp_session`, `hl_rp_session_submission`, `hl_classroom_visit`, `hl_classroom_visit_submission`, `hl_coaching_session_submission`). 3 services (`HL_RP_Session_Service`, `HL_Classroom_Visit_Service`, `HL_Session_Prep_Service`).

## Component Types (ENUM)

The `hl_component.component_type` ENUM defines all possible component types:

| Type | Usage | Frontend Renderer |
|---|---|---|
| `learndash_course` | LearnDash course completion | Redirects to LD course |
| `teacher_self_assessment` | Teacher self-assessment (PRE/POST) | `HL_Frontend_Teacher_Assessment` |
| `child_assessment` | Child assessment per classroom | `HL_Frontend_Child_Assessment` |
| `coaching_session_attendance` | Coaching session (managed by coach) | Managed-by-coach notice |
| `observation` | Mentor observation | `HL_Frontend_Observations` |
| `reflective_practice_session` | RP session (mentor+teacher forms) | `HL_Frontend_RP_Session` (role-based: mentor→RP Notes, teacher→Action Plan) |
| `classroom_visit` | Leader classroom visit | `HL_Frontend_Classroom_Visit` |
| `self_reflection` | Teacher self-reflection | `HL_Frontend_Self_Reflection` |

**Additional `hl_component` columns (beyond type/title/weight):**
- `complete_by` (date, nullable) — suggested completion date (not enforced, displayed on frontend)
- `requires_classroom` (tinyint, default 0) — eligibility: requires teaching assignment
- `eligible_roles` (text, JSON array or NULL) — eligibility: restricts to specific enrollment roles

## Component Eligibility Rules (Implemented)

Two columns on `hl_component` enable per-component eligibility filtering:
- **`requires_classroom`** (tinyint, default 0) — component requires the enrollee to have at least one `hl_teaching_assignment` record.
- **`eligible_roles`** (text, JSON array or NULL) — component is only for enrollees whose `hl_enrollment.roles` JSON contains at least one of the listed roles. NULL = all roles eligible.

`HL_Rules_Engine_Service::check_eligibility()` checks both conditions (AND logic). Ineligible components return `availability_status = 'not_applicable'`, are excluded from weighted completion averages (`compute_rollups()`), and show "Not Applicable" with grayed styling on all frontend pages (Program Page, My Progress, My Programs, User Profile, Team Progress, Dashboard).

Admin UI: "Eligibility Rules" section in the component form with checkbox for requires_classroom and checkboxes for eligible_roles.

## Cross-Pathway Instruments

6 instruments in `hl_teacher_assessment_instrument` (seeded by `setup-elcpb-y2-v2`): `coaching_rp_notes`, `mentoring_rp_notes`, `coaching_action_plan`, `mentoring_action_plan`, `classroom_visit_form`, `self_reflection_form`. Each contains `sections_json` rendered by 5 frontend renderers (`HL_Frontend_RP_Notes`, `HL_Frontend_Action_Plan`, `HL_Frontend_Self_Reflection`, `HL_Frontend_Classroom_Visit`, `HL_Frontend_RP_Session`). All custom PHP — no JFB.

## Control Group Research Design

### Purpose
Housman measures program impact by comparing:
- **Program cycles**: full B2E Mastery curriculum (courses, coaching, observations + assessments)
- **Control cycles**: assessment-only (Teacher Self-Assessment Pre/Post + Child Assessment Pre/Post)

### How it works
1. Create a **Partnership** (container, e.g., "B2E Mastery - Lutheran Services Florida")
2. Add the **program cycle** to the partnership
3. Create a **control cycle** (`is_control_group = true`) in the same partnership
4. Control cycle gets an assessment-only pathway (4 components: TSA Pre, CA Pre, TSA Post, CA Post)
5. POST components are time-gated via drip rules
6. **Comparison reports** appear in Admin Reports when selecting the Partnership filter

### UI/UX adaptations for control cycles:
- Purple "Control" badge in admin cycle list and editor
- Coaching and Teams tabs auto-hidden in admin and frontend
- Assessment-only pathway (no course or coaching components)

## Guided Tours System

In-app guided tours built on Driver.js (MIT, 1.4.0). 3 DB tables (`hl_tour`, `hl_tour_step`, `hl_tour_seen`). Trigger types: `first_login` (auto once), `page_visit` (auto on first visit), `manual_only` (topbar "?" dropdown). Role-based targeting, multi-page flows, interactive steps.

**Key classes:** `HL_Tour_Repository`, `HL_Tour_Service`, `HL_Admin_Tours` (Settings > Tours tab with List/Editor/Styles subtabs).
**Frontend:** `hl-tour.js` (Driver.js wrapper, multi-page state via localStorage), `hl-element-picker.js` (visual selector picker with "View as Role"), topbar "?" button with dropdown.
**Global styles:** `wp_options` key `hl_tour_styles` — tooltip colors, font sizes, button colors.

## Email Automation System (v2)

Block-based email template builder + workflow automation engine. Three admin pages: **Email Templates** (block editor), **Email Workflows** (condition/action rules), **Email Builder** (visual block composer).

**Key services:** `HL_Email_Automation_Service` (orchestrator, queue dispatch), `HL_Email_Condition_Evaluator` (evaluates trigger conditions against enrollment/coaching state), `HL_Email_Merge_Tag_Registry` (tag resolution for template rendering), `HL_Email_Queue_Processor` (wp-cron batch sender), `HL_Email_Block_Renderer` (block JSON to HTML), `HL_Email_Rate_Limit_Service` (per-recipient throttling), `HL_Email_Recipient_Resolver` (scope-aware recipient targeting).

**Condition fields include:** `enrollment.status`, `enrollment.role`, `coaching.session_status` (enum: `scheduled`, `completed`, `cancelled`, `no_show` -- replaced legacy `coaching.session_scheduled` boolean), `component.completion`, and more. Full list in `HL_Email_Condition_Evaluator`.

**Workflow UX v2:** `render_workflow_form_v2()` in `HL_Admin_Emails` provides a card-based layout (CSS prefix `.hl-wf-*`) with drag-and-drop steps. Toggled via `hl_workflow_ux_version` wp_option (`v1` = legacy table form, `v2` = card layout). Rollback toggle in workflow editor header.

**Send Test Email:** `ajax_send_test` endpoint (in `HL_Admin_Emails` and `HL_Admin_Email_Templates`) renders a template with mock merge tags and sends to the current admin user for preview.

**Frontend JS:** `assets/js/admin/email-builder.js` (template block editor), `assets/js/admin/email-workflow.js` (v2 workflow card interactions).

**CSS conventions:** Workflow v2 card layout uses `.hl-wf-*` prefix (e.g., `.hl-wf-card`, `.hl-wf-step`, `.hl-wf-header`). Styles are in `admin.css`.

## Architecture Summary
```
/hl-core/
  hl-core.php                    # Plugin bootstrap (singleton)
  README.md                      # Living status doc (ALWAYS UPDATE)
  /data/                         # Private data files (gitignored)
    /Assessments/                # B2E assessment source documents (.docx)
    /Lutheran - Control Group/   # Lutheran spreadsheets (.xlsx)
  /docs/                         # Spec documents (11 files, read-only reference)
  /includes/
    class-hl-installer.php       # DB schema (revision 40) + activation + migrations
    /domain/                     # Entity models (11 classes: OrgUnit, Partnership, Cycle, Enrollment, Team, Classroom, Child, Pathway, Component, Course_Catalog, Teacher_Assessment_Instrument)
    /domain/repositories/        # CRUD repositories (11 classes incl. HL_Tour_Repository, HL_Course_Catalog_Repository)
    /cli/                        # WP-CLI commands (23 commands incl. setup-elcpb-y2-v2, setup-ea, setup-short-courses, smoke-test, diagnose-nav, seed-beginnings, migrate-routing-types, email-v2-test, test-email-renderer, sync-tickets, translate-content) + data files
    /services/                   # Business logic (36 services incl. HL_Tour_Service, HL_Pathway_Routing_Service, HL_Scheduling_Service, HL_Scheduling_Email_Service, HL_Coach_Dashboard_Service, HL_Scope_Service, HL_Ticket_Service, HL_Email_Automation_Service, HL_Email_Condition_Evaluator, HL_Email_Merge_Tag_Registry, HL_Email_Queue_Processor, HL_Email_Block_Renderer, HL_Email_Rate_Limit_Service, HL_Email_Recipient_Resolver)
    /security/                   # Capabilities + authorization
    /integrations/               # LearnDash + BuddyBoss + Microsoft Graph + Zoom (4 classes)
    /admin/                      # WP admin pages (24 controllers incl. HL_Admin_Tours, HL_Admin_Course_Catalog, Coaching Hub with Coaches tab, HL_Admin_Email_Templates, HL_Admin_Email_Builder, HL_Admin_Emails (workflows + send-test), Scheduling Settings)
    /frontend/                   # 34 shortcode page renderers (incl. User Profile, 5 coach pages) + 5 form renderers (RP Notes, Action Plan, Self-Reflection, Classroom Visit, RP Session) + schedule session renderer + instrument/teacher-assessment renderers
    /api/                        # REST API routes
    /utils/                      # DB, date, normalization, age group helpers + label remap (legacy)
  /assets/
    /css/                        # admin.css, admin-import-wizard.css, admin-teacher-editor.css, frontend.css, frontend-docs.css + vendor/driver.css
    /js/                         # admin-import-wizard.js, admin-teacher-editor.js, frontend.js, frontend-docs.js, hl-tour.js, hl-tour-admin.js, hl-element-picker.js, hl-auth.js + vendor/driver.js
      /admin/                    # email-builder.js (template block editor), email-workflow.js (v2 workflow card UI)
```
