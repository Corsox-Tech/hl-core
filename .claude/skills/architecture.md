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

Code, DB, and UI all use the same terms now — no remapping layer needed. `HL_Label_Remap` is legacy — pending removal.

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

**Schema:** `catalog_code` (UNIQUE, stable key), `ld_course_en`/`ld_course_es`/`ld_course_pt` (UNIQUE, nullable — MySQL ignores NULLs in UNIQUE), `status` (active/archived). Schema revision 30.

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

## BuddyBoss Sidebar Navigation

The sidebar menu is rendered programmatically by `HL_BuddyBoss_Integration` — NOT via WordPress Menus admin. The BuddyPanel menu in Appearance → Menus should be empty (or contain only non-HL items like Dashboard/Profile).

**Multi-hook injection strategy:**
1. `buddyboss_theme_after_bb_profile_menu` — profile dropdown
2. `wp_nav_menu_items` filter on `buddypanel-loggedin` location — left sidebar
3. `wp_footer` JS fallback — covers empty BuddyPanel or missing hooks

**16 menu items with role-based visibility (from `build_menu_items()` in code):**
- Personal (require enrollment): My Profile (all enrolled + staff + coach), My Programs (enrolled teacher/mentor/leader/staff), My Coaching (mentor, non-control), My Team (mentor or teacher)
- Leader: My School (leader, non-staff)
- Directories: Cycles (staff), Classrooms (staff/leader/teacher/mentor), Learners (staff)
- Staff tools: Coaching Hub (staff), Reports (staff/leader)
- Coach tools: Coaching Home (coach WP role), My Mentors (coach), My Availability (coach), Coach Reports (coach)
- Documentation (manage_options only)

Staff WITHOUT enrollment see only directory/management pages. Staff WITH enrollment see both. Pathways item is currently disabled (show_condition = false). Coach-only users see coach tools but not staff tools.

## Forms Architecture: Custom PHP + JFB for Observations Only

HL Core uses a **primarily custom PHP approach** for forms and data collection:

### Custom PHP handles:
- **Teacher Self-Assessment** (pre and post) — Custom instrument system with structured JSON definitions in `hl_teacher_assessment_instrument`, response storage in `hl_teacher_assessment_instance.responses_json`. Custom renderer supports PRE (single-column) and POST (dual-column retrospective with PRE responses shown alongside new ratings).
- **Child Assessment** — Dynamic per-child matrix generated from classroom roster + instrument definition. Children grouped by frozen_age_group (from `hl_child_cycle_snapshot`) with per-age-group instruments. Rendered by `HL_Instrument_Renderer` with age-group sections, transposed Likert matrices, per-child skip controls, and AJAX draft auto-save. Responses stored in `hl_child_assessment_childrow` with frozen_age_group, instrument_id, and status per child.
- **Coaching Sessions** — Admin CRUD workflow (attendance, notes, observation links, attachments).

### JetFormBuilder handles (Legacy — pending removal):
- **Observations ONLY** — Mentor-submitted observation forms. JFB provides the visual form editor so Housman admins can customize observation questions without a developer.

### Why teacher assessments moved from JFB to custom PHP:
- POST version requires unique dual-column retrospective format that JFB cannot support
- Structured `responses_json` needed for research export and control group comparison (Cohen's d)
- Pre/post logic must integrate tightly with the component system and drip rules
- The B2E instrument is standardized (no admin customization needed)

### Legacy JFB support:
Teacher self-assessment components can still reference JFB forms via `external_ref.form_id` for backward compatibility. The system checks for `teacher_instrument_id` first (custom) and falls back to `jfb_form_id` (legacy).

### How JFB observations connect to HL Core (Legacy — pending removal):
1. **Admin creates a form in JetFormBuilder** with hidden fields: `hl_enrollment_id`, `hl_component_id`, `hl_cycle_id`, `hl_observation_id`
2. **Admin adds "Call Hook" post-submit action** with hook name `hl_core_form_submitted`
3. **HL Core renders the JFB form** on the observations page with hidden fields pre-populated
4. **On submit:** JFB fires hook → HL Core updates observation status → updates activity_state → triggers rollup

## Cross-Pathway Events

Cross-pathway events are activities that span multiple participants across a cycle, loosely coupled to the component/pathway system via `component_type` dispatch on the Component Page.

### 3 Event Types

| Event Type | component_type ENUM | Participants | DB Tables |
|---|---|---|---|
| **Reflective Practice Session** | `reflective_practice_session` | Mentor + Teacher | `hl_rp_session`, `hl_rp_session_submission` |
| **Classroom Visit** | `classroom_visit` | Leader + Teacher | `hl_classroom_visit`, `hl_classroom_visit_submission` |
| **Self-Reflection** | `self_reflection` | Teacher (solo) | Reuses `hl_classroom_visit` / `hl_classroom_visit_submission` |

A shared **`hl_coaching_session_submission`** table stores form responses for coaching sessions (Action Plan forms submitted during coaching).

### Loose Coupling Model

Events are **not tightly bound** to a single component. The flow:
1. Pathway contains a component with `component_type = 'reflective_practice_session'` (or `classroom_visit`, `self_reflection`)
2. Component Page dispatches to the appropriate renderer class based on `component_type`
3. Renderer creates or finds the event entity (RP Session, Classroom Visit) and renders the form
4. Form submission stores responses in the event's submission table with `instrument_id` reference
5. Component state updates to `completed` after successful submission

### 5 New DB Tables

- **`hl_rp_session`** — Links mentor + teacher enrollments within a cycle. Tracks session number, status, date, notes.
- **`hl_rp_session_submission`** — Form responses per RP session. Keyed by `(rp_session_id, role_in_session)`. Stores `instrument_id` + `responses_json`.
- **`hl_classroom_visit`** — Links leader + teacher enrollments within a cycle. Tracks visit number, status, date, optional classroom_id.
- **`hl_classroom_visit_submission`** — Form responses per visit. Keyed by `(classroom_visit_id, role_in_visit)`. Stores `instrument_id` + `responses_json`.
- **`hl_coaching_session_submission`** — Form responses per coaching session. Keyed by `(session_id, role_in_session)`. Stores `instrument_id` + `responses_json`.

### 3 New Services

- **`HL_RP_Session_Service`** — RP session entity CRUD, form submission with upsert, component state updates, previous action plan queries
- **`HL_Classroom_Visit_Service`** — Classroom visit entity CRUD, form submission with upsert, component state updates
- **`HL_Session_Prep_Service`** — Auto-populated data helper: pathway progress, previous action plans, recent visit/self-reflection data. Used by RP Notes forms to pre-populate session prep sections.

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

6 instruments stored in `hl_teacher_assessment_instrument` with structured `sections_json`:

| Key | Name | Used By |
|---|---|---|
| `coaching_rp_notes` | Coaching RP Notes | Mentor in coaching RP sessions |
| `mentoring_rp_notes` | Mentoring RP Notes | Mentor in mentoring RP sessions |
| `coaching_action_plan` | Coaching Action Plan | Teacher in coaching RP sessions |
| `mentoring_action_plan` | Mentoring Action Plan | Teacher in mentoring RP sessions |
| `classroom_visit_form` | Classroom Visit Form | Leader during classroom visits |
| `self_reflection_form` | Self-Reflection Form | Teacher for self-reflection |

Instruments are seeded by the `setup-elcpb-y2-v2` CLI command. Each contains structured sections with items, rendered by the form renderers using the instrument's `sections_json` definition.

## Form Renderer Pattern (Cross-Pathway Forms)

All cross-pathway forms follow the same custom PHP + inline CSS pattern:

### Design Pattern
1. **Hero header** — Gradient background with form title, participant names, session/visit number, date
2. **Flat domain layout** — One description per domain section, no accordions
3. **Pill indicator checkboxes** — Styled radio/checkbox inputs with CSS-only pill appearance
4. **Inline CSS** — All styles embedded in the renderer output (no external stylesheet dependency)
5. **Instrument-driven** — Form fields generated from `sections_json` in `hl_teacher_assessment_instrument`

### 5 Frontend Renderers
- **`HL_Frontend_RP_Notes`** — Mentor's RP Notes form (session prep + domain ratings + notes)
- **`HL_Frontend_Action_Plan`** — Teacher's Action Plan form (goals + strategies + timeline)
- **`HL_Frontend_Self_Reflection`** — Teacher's Self-Reflection form (domain self-ratings + notes)
- **`HL_Frontend_Classroom_Visit`** — Leader's Classroom Visit form (domain observations + notes)
- **`HL_Frontend_RP_Session`** — Page controller that dispatches to RP Notes (mentor) or Action Plan (teacher) based on the current user's enrollment role

### No JFB
These forms are 100% custom PHP. They do NOT use JetFormBuilder. This follows the same rationale as teacher self-assessments: structured `responses_json` storage, tight integration with the component system, and modern UI requirements that JFB cannot satisfy.

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

In-app guided tours built on Driver.js (MIT, 1.4.0). Replaces the limited "Simple Tour Guide" third-party plugin. Tours support unlimited steps, multi-page flows, role-based targeting, interactive steps (click-to-advance), and a visual element picker for non-technical admins.

### 3 DB Tables
- **`hl_tour`** — Tour definitions (title, slug, trigger_type, target_roles, start_page_url, status, sort_order, hide_on_mobile)
- **`hl_tour_step`** — Steps within a tour (title, description, page_url, target_selector, position, step_type)
- **`hl_tour_seen`** — Per-user completion tracking (user_id, tour_id, seen_at). UNIQUE on (user_id, tour_id).

### Trigger Types
| Type | Behavior |
|------|----------|
| `first_login` | Auto-triggers once on any page load when user matches target_roles and has no `hl_tour_seen` record |
| `page_visit` | Auto-triggers on first visit to `trigger_page_url` for matching roles |
| `manual_only` | Only available via the topbar "?" dropdown — never auto-triggers |

### Key Classes
- **`HL_Tour_Repository`** (`includes/domain/repositories/`) — CRUD for tours, steps, seen records
- **`HL_Tour_Service`** (`includes/services/`) — Tour resolution for page + user + role, seen checks, skip logic
- **`HL_Admin_Tours`** (`includes/admin/`) — Admin UI: Tours List, Tour Editor, Tour Styles subtabs under Settings > Tours tab

### Frontend
- **`hl-tour.js`** — Frontend tour controller wrapping Driver.js. Handles auto-triggers, multi-page navigation via localStorage + `?hl_active_tour=` URL param, step skipping for missing DOM elements, and auto-generated final step pointing to the "?" button.
- **Topbar "?" button** (`#hl-tour-trigger`) — Positioned in the HL topbar (left of user avatar). Dropdown lists available tours for the current page. On mobile renders as a bottom sheet.
- **`hl-element-picker.js`** — Injected into an iframe via `?hl_picker=1` query param. Lets admins visually click elements to select CSS selectors. Supports "View as Role" dropdown for previewing role-specific elements.
- **`hl-tour-admin.js`** — Admin-side: step drag-and-drop reordering, element picker modal, color pickers for global styles.

### Asset Files
- `assets/js/vendor/driver.js` — Driver.js 1.4.0 (bundled locally, MIT)
- `assets/css/vendor/driver.css` — Driver.js base styles
- `assets/css/frontend.css` — GUIDED TOURS sections (topbar button, dropdown, bottom sheet mobile styles)

### Global Styling
Stored in `wp_options` as `hl_tour_styles` (serialized array). Admins configure tooltip colors, font sizes, button colors, and progress bar color via Settings > Tours > Styles subtab. Defaults map to HL design tokens.

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
    class-hl-installer.php       # DB schema (48 tables, revision 30) + activation + migrations
    /domain/                     # Entity models (11 classes: OrgUnit, Partnership, Cycle, Enrollment, Team, Classroom, Child, Pathway, Component, Course_Catalog, Teacher_Assessment_Instrument)
    /domain/repositories/        # CRUD repositories (11 classes incl. HL_Tour_Repository, HL_Course_Catalog_Repository)
    /cli/                        # WP-CLI commands (17 commands incl. setup-elcpb-y2-v2, setup-ea, setup-short-courses, smoke-test, diagnose-nav, seed-beginnings, migrate-routing-types) + data files
    /services/                   # Business logic (24 services incl. HL_Tour_Service, HL_Pathway_Routing_Service, HL_Scheduling_Service, HL_Scheduling_Email_Service, HL_Coach_Dashboard_Service, HL_Scope_Service)
    /security/                   # Capabilities + authorization
    /integrations/               # LearnDash + BuddyBoss + Microsoft Graph + Zoom (5 classes, JFB legacy)
    /admin/                      # WP admin pages (20 controllers incl. HL_Admin_Tours, HL_Admin_Course_Catalog, Coaching Hub with Coaches tab, Email Templates, Scheduling Settings)
    /frontend/                   # 34 shortcode page renderers (incl. User Profile, 5 coach pages) + 5 form renderers (RP Notes, Action Plan, Self-Reflection, Classroom Visit, RP Session) + schedule session renderer + instrument/teacher-assessment renderers
    /api/                        # REST API routes
    /utils/                      # DB, date, normalization, age group helpers + label remap (legacy)
  /assets/
    /css/                        # admin.css, admin-import-wizard.css, admin-teacher-editor.css, frontend.css, frontend-docs.css + vendor/driver.css
    /js/                         # admin-import-wizard.js, admin-teacher-editor.js, frontend.js, frontend-docs.js, hl-tour.js, hl-tour-admin.js, hl-element-picker.js + vendor/driver.js
```
