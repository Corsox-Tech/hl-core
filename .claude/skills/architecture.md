---
name: architecture
description: Domain model, roles, coach assignment, phases, track types, scope service, forms, control groups, directory tree
---

# HL Core Domain Architecture Reference

## Documentation Files (in plugin docs/)
| File | Covers |
|------|--------|
| 00_README_SCOPE.md | Project scope and high-level requirements |
| 01_GLOSSARY_CANONICAL_TERMS.md.md | Terminology definitions including Cohort, Track, Control Group |
| 02_DOMAIN_MODEL_ORG_STRUCTURE.md.md | Org units, cohorts, enrollments, teams |
| 03_ROLES_PERMISSIONS_REPORT_VISIBILITY.md.md | Roles, capabilities, access control |
| 04_COHORT_PATHWAYS_ACTIVITIES_RULES.md.md | Pathways, activities, prerequisite rules |
| 05_UNLOCKING_LOGIC_PREREQS_DRIP_OVERRIDES.md.md | Unlock logic, drip rules, overrides |
| 06_ASSESSMENTS_CHILDREN_TEACHER_OBSERVATION_COACHING.md.md | Assessment instruments (custom PHP for teacher + children, JFB for observations only), coaching |
| 07_IMPORTS_ROSTERS_IDENTITIES_MATCHING.md.md | CSV import pipeline, identity matching |
| 08_REPORTING_METRICS_VIEWS_EXPORTS.md.md | Reporting, metrics, export formats, program vs control comparison |
| 09_PLUGIN_ARCHITECTURE_CONSTRAINTS_ACCEPTANCE_TESTS.md.md | Architecture, constraints, acceptance criteria |
| 10_FRONTEND_PAGES_NAVIGATION_UX.md | Front-end pages, sidebar navigation (§16), listing page specs (§17), coach assignment model (§13-15) |
| B2E_MASTER_REFERENCE.md | **Authoritative** — Product catalog, revised architecture (Phase, Track Types, Individual Enrollments), control group clarification |
| DOC_UPDATE_INSTRUCTIONS.md | File-by-file instructions for updating all docs to match B2E Master Reference |

## UI Label Remapping (CRITICAL — Read Before Touching Any Labels)

**The UI shows different terms than the code/DB uses.** This is intentional — the client requested client-friendly labels while we keep internal terms stable for potential future changes.

| Code / DB / Variables | UI Display (Frontend + Admin) | Why |
|---|---|---|
| `Track`, `track_id`, `hl_track` | **Partnership** | Client prefers "Partnership" as the program container term |
| `Activity`, `activity_id`, `hl_activity` | **Component** | Avoids confusion with learning activities within courses |

**How it works:** `HL_Label_Remap` (`includes/utils/class-hl-label-remap.php`) hooks into WordPress `gettext` and `ngettext` filters. It intercepts ALL `__()`, `esc_html__()`, `esc_html_e()`, and `_n()` calls in the `hl-core` text domain and swaps display strings at render time.

**Rules for developers:**
1. **NEVER rename PHP variables, class names, DB columns, or shortcode tags** to match the display labels. `$track_id` stays `$track_id`, NOT `$partnership_id`.
2. **In PHP i18n strings**, keep using the INTERNAL terms: `__('Track', 'hl-core')` — the remap filter will display "Partnership" automatically.
3. **Admin menu labels** in `class-hl-admin.php` are direct edits (not filtered) — these say "Partnerships" and "Pathways & Components" directly.
4. **To revert all labels instantly:** set `const ENABLED = false;` in `class-hl-label-remap.php`. One line, zero risk.
5. **To change a label:** edit the `$map` array in `class-hl-label-remap.php` + update admin menu in `class-hl-admin.php`.

## WordPress Roles vs Track Roles (Critical Architecture Decision)

HL Core creates ONE custom WP role: **Coach** (with `read`, `manage_hl_core`, `hl_view_tracks`, `hl_view_enrollments`). Administrators get `manage_hl_core` capability added.

**ALL track-specific roles** (teacher, mentor, school_leader, district_leader) are stored in the `hl_enrollment.roles` JSON column — NOT as WordPress roles. This is by design:

| Who | WP Role | HL Core Identification |
|---|---|---|
| Teachers, Mentors, Leaders | `subscriber` (or any basic WP role) | `hl_enrollment.roles` JSON per track |
| Housman Coaches | `coach` (custom WP role) | `manage_hl_core` capability |
| Housman Admins | `administrator` | `manage_hl_core` capability |

**Why:** A user can be a teacher in one track and a mentor in another. WP roles are global; enrollment roles are per-track. The BuddyBoss sidebar, front-end pages, and scope filtering all use enrollment roles from `hl_enrollment`, not WP roles.

Some legacy WP roles exist (`school_district`, `teacher`, `mentor`, `school_leader`) from old Elementor pages — HL Core does NOT use these.

## Coach Assignment Architecture

Coaches are assigned at three scope levels with "most specific wins" resolution:

1. **School level** — coach is default for all enrollments at that school
2. **Team level** — overrides school assignment for team members
3. **Enrollment level** — overrides everything for a specific participant

Stored in `hl_coach_assignment` table with `effective_from`/`effective_to` dates. Reassignment closes the old record and creates a new one — full history preserved. Coaching sessions retain the original `coach_user_id` (frozen at time of session).

See doc 10 §13-14 for full spec.

## Phase Entity (NEW — B2E Master Reference)

A Phase is a time-bounded period within a Track that groups Pathways. For B2E Mastery: Phase 1 = Year 1 courses, Phase 2 = Year 2 courses.

**Key points:**
- Stored in `hl_phase` table (phase_id, track_id, phase_name, phase_number, start/end dates, status)
- Pathways belong to Phase (not directly to Track): `hl_pathway.phase_id` → `hl_phase`
- Program-type Tracks: admin creates Phases manually
- Course-type Tracks: system auto-creates one Phase + one Pathway + one Activity
- Enrollment stays at Track level — Phase is structural grouping only
- Teams also stay at Track level (can span Phases)

## Track Types (NEW — B2E Master Reference)

`hl_track.track_type` distinguishes complexity levels:

| Type | Usage | Complexity |
|------|-------|-----------|
| `program` | B2E Mastery and similar multi-phase programs | Full: Phases, Pathways, Teams, Coaching, Assessments |
| `course` | Institutional short course purchases | Minimal: auto-created single Phase + Pathway + Activity |

Course-type Tracks hide: Phase management, Teams tab, Coaching tab, Assessment tabs, Pathway editor.

## Individual Enrollments (NEW — B2E Master Reference)

For individual (non-institutional) course purchases. Stored in `hl_individual_enrollment` (user_id, course_id, enrolled_at, expires_at, status).

- Admin pages: Course List + Course Detail (under HL Core menu)
- Frontend: "My Courses" section on Dashboard for active individual enrollments
- Expiration enforcement: blocks access when expires_at < now
- Not related to Tracks — this is a separate, simpler enrollment path for standalone course access

## Scope Service (HL_Scope_Service)

Shared static helper used by ALL listing pages for role-based data filtering:
- Admin → sees all (no restriction)
- Coach → filtered by `hl_coach_assignment` track_ids + own enrollments
- District Leader → expands to all schools in their district
- School Leader → filtered to their school(s)
- Mentor → filtered to their team(s)
- Teacher → filtered to their own enrollment/assignments

Static cache per user_id per request. Convenience helpers: `can_view_track()`, `can_view_school()`, `has_role()`, `filter_by_ids()`.

## BuddyBoss Sidebar Navigation

The sidebar menu is rendered programmatically by `HL_BuddyBoss_Integration` — NOT via WordPress Menus admin. The BuddyPanel menu in Appearance → Menus should be empty (or contain only non-HL items like Dashboard/Profile).

**Multi-hook injection strategy:**
1. `buddyboss_theme_after_bb_profile_menu` — profile dropdown
2. `wp_nav_menu_items` filter on `buddypanel-loggedin` location — left sidebar
3. `wp_footer` JS fallback — covers empty BuddyPanel or missing hooks

**11 menu items with role-based visibility (doc 10 §16):**
- Personal (require enrollment): My Programs, My Coaching, My Team (mentor), My Track (leader/mentor)
- Directories: Tracks, Institutions (staff/leader), Classrooms (staff/leader/teacher), Learners (staff/leader/mentor)
- Staff tools: Pathways (staff only), Coaching Hub (staff/mentor), Reports (staff/leader)

Staff WITHOUT enrollment see only directory/management pages. Staff WITH enrollment see both.

## Forms Architecture: Custom PHP + JFB for Observations Only

HL Core uses a **primarily custom PHP approach** for forms and data collection:

### Custom PHP handles:
- **Teacher Self-Assessment** (pre and post) — Custom instrument system with structured JSON definitions in `hl_teacher_assessment_instrument`, response storage in `hl_teacher_assessment_instance.responses_json`. Custom renderer supports PRE (single-column) and POST (dual-column retrospective with PRE responses shown alongside new ratings).
- **Child Assessment** — Dynamic per-child matrix generated from classroom roster + instrument definition. Children grouped by frozen_age_group (from `hl_child_track_snapshot`) with per-age-group instruments. Rendered by `HL_Instrument_Renderer` with age-group sections, transposed Likert matrices, per-child skip controls, and AJAX draft auto-save. Responses stored in `hl_child_assessment_childrow` with frozen_age_group, instrument_id, and status per child.
- **Coaching Sessions** — Admin CRUD workflow (attendance, notes, observation links, attachments).

### JetFormBuilder handles:
- **Observations ONLY** — Mentor-submitted observation forms. JFB provides the visual form editor so Housman admins can customize observation questions without a developer.

### Why teacher assessments moved from JFB to custom PHP:
- POST version requires unique dual-column retrospective format that JFB cannot support
- Structured `responses_json` needed for research export and control group comparison (Cohen's d)
- Pre/post logic must integrate tightly with the activity system and drip rules
- The B2E instrument is standardized (no admin customization needed)

### Legacy JFB support:
Teacher self-assessment activities can still reference JFB forms via `external_ref.form_id` for backward compatibility. The system checks for `teacher_instrument_id` first (custom) and falls back to `jfb_form_id` (legacy).

### How JFB observations connect to HL Core:
1. **Admin creates a form in JetFormBuilder** with hidden fields: `hl_enrollment_id`, `hl_activity_id`, `hl_track_id`, `hl_observation_id`
2. **Admin adds "Call Hook" post-submit action** with hook name `hl_core_form_submitted`
3. **HL Core renders the JFB form** on the observations page with hidden fields pre-populated
4. **On submit:** JFB fires hook → HL Core updates observation status → updates activity_state → triggers rollup

## Control Group Research Design

### Purpose
Housman measures program impact by comparing:
- **Program tracks**: full B2E Mastery curriculum (courses, coaching, observations + assessments)
- **Control tracks**: assessment-only (Teacher Self-Assessment Pre/Post + Child Assessment Pre/Post)

### How it works
1. Create a **Cohort** (container, e.g., "B2E Mastery - Lutheran Services Florida")
2. Add the **program track** to the cohort
3. Create a **control track** (`is_control_group = true`) in the same cohort
4. Control track gets an assessment-only pathway (4 activities: TSA Pre, CA Pre, TSA Post, CA Post)
5. POST activities are time-gated via drip rules
6. **Comparison reports** appear in Admin Reports when selecting the Cohort filter

### UI/UX adaptations for control tracks:
- Purple "Control" badge in admin track list and editor
- Coaching and Teams tabs auto-hidden in admin and frontend
- Assessment-only pathway (no course or coaching activities)

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
    /admin/                      # WP admin pages (15+ controllers incl. Cohorts, Tracks, Instruments)
    /frontend/                   # Shortcode renderers (26+ pages + instrument renderer + teacher assessment renderer)
    /api/                        # REST API routes
    /utils/                      # DB, date, normalization helpers
  /assets/
    /css/                        # admin.css, admin-import-wizard.css, frontend.css (with CSS custom properties design system)
    /js/                         # admin-import-wizard.js, frontend.js
```
