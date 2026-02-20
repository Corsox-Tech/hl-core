# CLAUDE.md — HL Core Plugin Development Guide

## Project Overview
This is the Housman Learning Academy (HLA) WordPress site. The primary development target is the **hl-core** custom plugin located at `wp-content/plugins/hl-core/`.

## Critical Paths
- **Plugin root:** `wp-content/plugins/hl-core/`
- **Documentation (11 spec files):** `wp-content/plugins/hl-core/docs/` — These are the canonical specifications for all features, domain models, business rules, and architecture decisions. Read the relevant doc file(s) before building any feature.
- **README.md:** `wp-content/plugins/hl-core/README.md` — This is the living status tracker for the entire plugin. It documents what's built, what's pending, architecture, and design decisions.
- **LearnDash plugin:** `wp-content/plugins/sfwd-lms/` — Reference for hooks, functions, and integration points.
- **Private data files:** `wp-content/plugins/hl-core/data/` — Contains real program Excel files (Center_Info.xlsx, Teacher_Roster.xlsx, Child_Roster.xlsx). Gitignored — never commit to repo.

## Documentation Files (in docs/)
| File | Covers |
|------|--------|
| 00_README_SCOPE.md | Project scope and high-level requirements |
| 01_GLOSSARY_CANONICAL_TERMS.md.md | Terminology definitions (use these exact terms) |
| 02_DOMAIN_MODEL_ORG_STRUCTURE.md.md | Org units, cohorts, enrollments, teams |
| 03_ROLES_PERMISSIONS_REPORT_VISIBILITY.md.md | Roles, capabilities, access control |
| 04_COHORT_PATHWAYS_ACTIVITIES_RULES.md.md | Pathways, activities, prerequisite rules |
| 05_UNLOCKING_LOGIC_PREREQS_DRIP_OVERRIDES.md.md | Unlock logic, drip rules, overrides |
| 06_ASSESSMENTS_CHILDREN_TEACHER_OBSERVATION_COACHING.md.md | Assessment instruments, observations, coaching |
| 07_IMPORTS_ROSTERS_IDENTITIES_MATCHING.md.md | CSV import pipeline, identity matching |
| 08_REPORTING_METRICS_VIEWS_EXPORTS.md.md | Reporting, metrics, export formats |
| 09_PLUGIN_ARCHITECTURE_CONSTRAINTS_ACCEPTANCE_TESTS.md.md | Architecture, constraints, acceptance criteria |
| 10_FRONTEND_PAGES_NAVIGATION_UX.md | Front-end pages, sidebar navigation (§16), listing page specs (§17), coach assignment model (§13-15) |

## Mandatory Workflow Rules

### 1. Always read README.md first
At the start of every session, read `wp-content/plugins/hl-core/README.md` to understand what's already built and what's pending. This is your primary context source.

### 2. How to continue between sessions (IMPORTANT — do NOT skip this)
When the user says "continue", "pick up where we left off", "keep going", or starts a new session:

**DO NOT start coding immediately.** Instead, follow this checklist:

1. **Read README.md** — check the Build Queue for completed `[x]`, in-progress `[~]`, and unchecked `[ ]` items
2. **Report status to the user:**
   - What was last completed (most recent `[x]` items)
   - If there's an in-progress task `[~]`, explain exactly where it was left off and what remains
   - What the next unchecked `[ ]` task(s) are in the queue
3. **Ask the user what to do** — Ask: "Should I continue with [specific task], or would you like to work on something else?"
4. **Wait for the user's confirmation** before writing any code

Example response format:
> "I've read the current status. Here's where we are:
>
> **Last completed:** 11.9 (Sidebar Menu Rebuild) and 11.10 (Create WordPress Pages) ✅
>
> **All 11 phases are complete.** Remaining items are marked Future/Lower Priority.
>
> Should I work on one of the lower-priority items, or do you have something else in mind?"

### 3. Always update README.md after making changes
After completing any feature, fix, or refactoring:
- Update the "What's Implemented" section if you built something new
- Check off completed items in the Build Queue with `[x]`
- Mark partially completed items with `[~]` and add a note explaining what's done and what remains
- Update the "Architecture" file tree if you added new files/directories
- Keep the format consistent with what's already there

### 4. Before suggesting "Clear Context" or when the conversation is getting long
If you're about to suggest clearing context, or if the session has been going a while:
- **STOP and update README.md FIRST** before the context is cleared
- Check off all completed Build Queue items with `[x]`
- Mark any in-progress items with `[~]` and write a clear note about exactly what's done and what's left
- Update "What's Implemented" with anything new

### 5. Read relevant docs before building features
Don't build from memory or assumptions. Before implementing any feature, read the specific doc file(s) listed next to each build queue item.

### 6. Terminology
The project uses "Cohort" (not "Program") internally. The front-end shows "Program" to participants (see doc 10 §1 label mapping). All code, table names, and documentation use "Cohort" terminology.

## WordPress Roles vs Cohort Roles (Critical Architecture Decision)

HL Core creates ONE custom WP role: **Coach** (with `read`, `manage_hl_core`, `hl_view_cohorts`, `hl_view_enrollments`). Administrators get `manage_hl_core` capability added.

**ALL cohort-specific roles** (teacher, mentor, center_leader, district_leader) are stored in the `hl_enrollment.roles` JSON column — NOT as WordPress roles. This is by design:

| Who | WP Role | HL Core Identification |
|---|---|---|
| Teachers, Mentors, Leaders | `subscriber` (or any basic WP role) | `hl_enrollment.roles` JSON per cohort |
| Housman Coaches | `coach` (custom WP role) | `manage_hl_core` capability |
| Housman Admins | `administrator` | `manage_hl_core` capability |

**Why:** A user can be a teacher in one cohort and a mentor in another. WP roles are global; enrollment roles are per-cohort. The BuddyBoss sidebar, front-end pages, and scope filtering all use enrollment roles from `hl_enrollment`, not WP roles.

Some legacy WP roles exist (`school_district`, `teacher`, `mentor`, `school_leader`) from old Elementor pages — HL Core does NOT use these.

## Coach Assignment Architecture

Coaches are assigned at three scope levels with "most specific wins" resolution:

1. **Center level** — coach is default for all enrollments at that center
2. **Team level** — overrides center assignment for team members
3. **Enrollment level** — overrides everything for a specific participant

Stored in `hl_coach_assignment` table with `effective_from`/`effective_to` dates. Reassignment closes the old record and creates a new one — full history preserved. Coaching sessions retain the original `coach_user_id` (frozen at time of session).

See doc 10 §13-14 for full spec.

## Scope Service (HL_Scope_Service)

Shared static helper used by ALL listing pages for role-based data filtering:
- Admin → sees all (no restriction)
- Coach → filtered by `hl_coach_assignment` cohort_ids + own enrollments
- District Leader → expands to all centers in their district
- Center Leader → filtered to their center(s)
- Mentor → filtered to their team(s)
- Teacher → filtered to their own enrollment/assignments

Static cache per user_id per request. Convenience helpers: `can_view_cohort()`, `can_view_center()`, `has_role()`, `filter_by_ids()`.

## BuddyBoss Sidebar Navigation

The sidebar menu is rendered programmatically by `HL_BuddyBoss_Integration` — NOT via WordPress Menus admin. The BuddyPanel menu in Appearance → Menus should be empty (or contain only non-HL items like Dashboard/Profile).

**Multi-hook injection strategy:**
1. `buddyboss_theme_after_bb_profile_menu` — profile dropdown
2. `wp_nav_menu_items` filter on `buddypanel-loggedin` location — left sidebar
3. `wp_footer` JS fallback — covers empty BuddyPanel or missing hooks

**11 menu items with role-based visibility (doc 10 §16):**
- Personal (require enrollment): My Programs, My Coaching, My Team (mentor), My Cohort (leader/mentor)
- Directories: Cohorts, Institutions (staff/leader), Classrooms (staff/leader/teacher), Learners (staff/leader/mentor)
- Staff tools: Pathways (staff only), Coaching Hub (staff/mentor), Reports (staff/leader)

Staff WITHOUT enrollment see only directory/management pages. Staff WITH enrollment see both.

## Code Conventions
- **PHP 7.4+** with WordPress coding standards
- **Class prefix:** `HL_` (e.g., `HL_Cohort_Service`)
- **Table prefix:** `hl_` (e.g., `hl_cohort`, `hl_enrollment`)
- **Singleton pattern** for the main plugin class
- **Repository pattern** for all database access (no direct queries in services)
- **Service layer** for all business logic
- **Custom capabilities** for authorization (`manage_hl_core`)
- **Audit logging** via `HL_Audit_Service` for significant state changes
- Leave `// TODO:` comments for incomplete features with a brief description

## Forms Architecture: Hybrid Model (Critical Design Decision)

HL Core uses a **hybrid approach** for forms and data collection:

### JetFormBuilder handles: form design, rendering, field types, validation, and response storage
- **Teacher Self-Assessment** (pre and post) — static questionnaire, same for every teacher
- **Observations** — static questionnaire filled by a mentor about a teacher
- **Any future static questionnaire forms** that admins need to create/edit without a developer

### HL Core handles: custom dynamic forms built in PHP
- **Children Assessment** — dynamic per-child matrix generated from classroom roster + instrument definition. Rendered from `hl_instrument.questions` JSON. This CANNOT use a form builder.
- **Coaching Sessions** — admin CRUD workflow (attendance, notes, observation links, attachments). Not a questionnaire.

### Why this split
- Housman LMS Admins must be able to create, edit, and redesign questionnaire forms themselves (add/remove/change questions, field types, layout) without needing a developer
- JetFormBuilder provides a full visual form editor, 24+ field types, conditional logic, Elementor integration, and post-submit actions — rebuilding this in custom PHP would be enormous wasted effort
- Children Assessments are inherently dynamic (one row per child in the classroom roster, determined at runtime) so no form builder can handle them
- Coaching Sessions are admin-side CRUD, not user-facing questionnaires

### How JetFormBuilder forms connect to HL Core activities
1. **Admin creates a form in JetFormBuilder** — designs the questionnaire with any field types, layout, conditional logic they want
2. **Admin configures a post-submit action** on the form: "Call Hook" with hook name `hl_core_form_submitted`
3. **Admin adds hidden fields** to the form: `hl_enrollment_id`, `hl_activity_id`, `hl_cohort_id` (these get pre-populated by HL Core when rendering)
4. **Admin creates an Activity** in a Pathway — selects activity_type and picks the JFB form from a dropdown → stored in `hl_activity.external_ref` JSON
5. **Participant views their pathway** — clicks the activity to open it
6. **HL Core renders the JFB form** on the page with hidden fields pre-populated from enrollment context
7. **Participant submits** → JFB handles validation and stores responses → fires hook → HL Core updates activity_state to complete and triggers rollup

### Key principle: HL Core is the orchestration layer
HL Core does NOT need to know what's inside a JFB form. It only tracks: which form is linked to which activity, whether the activity is complete, and contextual metadata for observations/assessment instances.

## Git & Deployment

### Repository
- **GitHub:** `https://github.com/Corsox-Tech/hl-core.git`
- **Branch:** `main` (single-branch workflow)
- Private repo — never commit data files or credentials

### Local Development
- **Environment:** Local by Flywheel
- **WordPress root:** `C:\Users\MateoGonzalez\Local Sites\housman-learning-academy\app\public\`
- **Plugin path:** `wp-content/plugins/hl-core/`
- **WP-CLI available** via Local's shell

### Deployment to Staging
- **Staging URL:** `https://staging.academy.housmanlearning.com`
- **Hostinger hosting** with SSH access
- **Auto-deployment:** GitHub webhook triggers pull on push to main
- **Staging plugin path:** `~/domains/staging.academy.housmanlearning.com/public_html/wp-content/plugins/hl-core`
- **After deploying new schema changes:** Re-seed demo data on staging:
  ```bash
  cd ~/domains/staging.academy.housmanlearning.com/public_html
  wp hl-core seed-demo --clean
  wp hl-core seed-demo
  ```
- **After adding new shortcode pages:** Create them on staging:
  ```bash
  wp hl-core create-pages
  ```

### .gitignore
```
node_modules/
.DS_Store
*.log
/vendor/
/data/       # Private Excel files — never commit
```

## WP-CLI Commands
- `wp hl-core seed-demo [--clean]` — Generic demo data (2 centers, 15 enrollments, code: DEMO-2026)
- `wp hl-core seed-palm-beach [--clean]` — Real ELC Palm Beach data (12 centers, 47 teachers, 286 children, code: ELC-PB-2026)
- `wp hl-core create-pages [--force] [--status=draft]` — Creates all 24 shortcode WordPress pages

## Architecture Summary
```
/hl-core/
  hl-core.php                    # Plugin bootstrap (singleton)
  README.md                      # Living status doc (ALWAYS UPDATE)
  CLAUDE.md                      # This file — dev guide for AI assistants
  /data/                         # Private data files (gitignored)
  /docs/                         # Spec documents (10 files, read-only reference)
  /includes/
    class-hl-installer.php       # DB schema (32 tables) + activation
    /domain/                     # Entity models (8 classes)
    /domain/repositories/        # CRUD repositories (8 classes)
    /cli/                        # WP-CLI commands (seed-demo, seed-palm-beach, create-pages)
    /services/                   # Business logic (13+ services incl. HL_Scope_Service)
    /security/                   # Capabilities + authorization
    /integrations/               # LearnDash + JetFormBuilder + BuddyBoss (3 classes)
    /admin/                      # WP admin pages (14+ controllers)
    /frontend/                   # Shortcode renderers (25 pages + instrument renderer)
    /api/                        # REST API routes
    /utils/                      # DB, date, normalization helpers
  /assets/
    /css/                        # admin.css, admin-import-wizard.css, frontend.css
    /js/                         # admin-import-wizard.js, frontend.js
```

## Environment
- WordPress running locally via Local by Flywheel
- JetFormBuilder plugin must be installed and active
- LearnDash plugin must be installed and active
- BuddyBoss theme + platform (optional, for sidebar navigation)
- Database: MySQL (managed by Local)
- PHP 7.4+
- Run Claude Code from the plugin directory: `wp-content/plugins/hl-core/`

## Plugin Dependencies
- **WordPress 6.0+** (required)
- **PHP 7.4+** (required)
- **JetFormBuilder** (required — teacher self-assessment and observation forms)
- **LearnDash** (required — course progress tracking)
- **BuddyBoss Theme + Platform** (optional — sidebar navigation, profile links; gracefully degrades if not installed)

## Current Status (as of Feb 2026)
**Phases 1-11 complete.** 25 shortcode pages, 14 admin pages, 32 DB tables, 13+ services. All core functionality operational.

**Remaining (all Future/Lower Priority):**
- MS365 Calendar Integration (requires Azure AD infrastructure)
- BuddyBoss Profile Tab (out of scope for v1)
- Scope-based user creation for client leaders
- Import templates (downloadable CSV)
