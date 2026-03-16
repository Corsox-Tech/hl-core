---
name: architecture
description: Domain model, roles, coach assignment, partnerships, cycle types, scope service, forms, control groups, directory tree
---

# HL Core Domain Architecture Reference

## Documentation Files (in plugin docs/)
| File | Covers |
|------|--------|
| 00_README_SCOPE.md | Project scope and high-level requirements |
| 01_GLOSSARY_CANONICAL_TERMS.md | Terminology definitions including Partnership, Cycle, Control Group |
| 02_DOMAIN_MODEL_ORG_STRUCTURE.md | Org units, partnerships, enrollments, teams |
| 03_ROLES_PERMISSIONS_REPORT_VISIBILITY.md | Roles, capabilities, access control |
| 04_COHORT_PATHWAYS_ACTIVITIES_RULES.md | Pathways, components, prerequisite rules |
| 05_UNLOCKING_LOGIC_PREREQS_DRIP_OVERRIDES.md | Unlock logic, drip rules, overrides |
| 06_ASSESSMENTS_CHILDREN_TEACHER_OBSERVATION_COACHING.md | Assessment instruments (custom PHP for teacher + children, JFB for observations only), coaching |
| 07_IMPORTS_ROSTERS_IDENTITIES_MATCHING.md | CSV import pipeline, identity matching |
| 08_REPORTING_METRICS_VIEWS_EXPORTS.md | Reporting, metrics, export formats, program vs control comparison |
| 09_PLUGIN_ARCHITECTURE_CONSTRAINTS_ACCEPTANCE_TESTS.md | Architecture, constraints, acceptance criteria |
| 10_FRONTEND_PAGES_NAVIGATION_UX.md | Front-end pages, sidebar navigation (§16), listing page specs (§17), coach assignment model (§13-15) |
| B2E_MASTER_REFERENCE.md | **Authoritative** — Product catalog, revised architecture (Partnership, Cycle Types, Individual Enrollments), control group clarification |
| DOC_UPDATE_INSTRUCTIONS.md | File-by-file instructions for updating all docs to match B2E Master Reference. **PENDING** — these updates have NOT been applied to docs 00-10 yet |

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

## Individual Enrollments (NEW — B2E Master Reference)

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

**11 menu items with role-based visibility (doc 10 §16):**
- Personal (require enrollment): My Programs, My Coaching, My Team (mentor), My Cycle (leader/mentor)
- Directories: Cycles, Institutions (staff/leader), Classrooms (staff/leader/teacher), Learners (staff/leader/mentor)
- Staff tools: Pathways (staff only), Coaching Hub (staff/mentor), Reports (staff/leader)

Staff WITHOUT enrollment see only directory/management pages. Staff WITH enrollment see both.

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
    class-hl-installer.php       # DB schema (37+ tables) + activation + migrations
    /domain/                     # Entity models (9+ classes incl. Teacher_Assessment_Instrument)
    /domain/repositories/        # CRUD repositories (8 classes)
    /cli/                        # WP-CLI commands (seed-demo, seed-lutheran, seed-palm-beach, nuke, create-pages)
    /services/                   # Business logic (14+ services incl. HL_Scope_Service, HL_Pathway_Assignment_Service)
    /security/                   # Capabilities + authorization
    /integrations/               # LearnDash + JetFormBuilder + BuddyBoss (3 classes)
    /admin/                      # WP admin pages (15+ controllers incl. Partnerships, Cycles, Instruments)
    /frontend/                   # Shortcode renderers (26+ pages + instrument renderer + teacher assessment renderer)
    /api/                        # REST API routes
    /utils/                      # DB, date, normalization helpers
  /assets/
    /css/                        # admin.css, admin-import-wizard.css, frontend.css (with CSS custom properties design system)
    /js/                         # admin-import-wizard.js, frontend.js
```
