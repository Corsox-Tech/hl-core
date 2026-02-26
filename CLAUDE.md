# CLAUDE.md — HL Core Plugin Development Guide

> **COMMIT CHECKLIST (every commit that changes functionality):**
> 1. Update README.md (build queue checkboxes + "What's Implemented" if needed)
> 2. Commit README.md alongside the code changes
> 3. A task is NOT done until README.md is updated
>
> **BEFORE ANY CONTEXT COMPACTION (non-negotiable):**
> 1. Commit all pending code changes
> 2. Update README.md with current status — check off completed items `[x]`, mark in-progress items `[~]` with a detailed note of what's done and what remains
> 3. Commit and push the README.md update
> 4. THEN compact/clear context
> This applies to: forced compaction (context limit), user-requested compaction, self-suggested compaction, and session end. README.md is the only artifact that survives compaction — it must be the source of truth.

## Project Overview
This is the Housman Learning Academy (HLA) WordPress site. The primary development target is the **hl-core** custom plugin located at `wp-content/plugins/hl-core/`.

## Critical Paths
- **Plugin root:** `wp-content/plugins/hl-core/`
- **Documentation (11 spec files):** `wp-content/plugins/hl-core/docs/` — These are the canonical specifications for all features, domain models, business rules, and architecture decisions. Read the relevant doc file(s) before building any feature.
- **README.md:** `wp-content/plugins/hl-core/README.md` — This is the living status tracker for the entire plugin. It documents what's built, what's pending, architecture, and design decisions.
- **LearnDash plugin:** `wp-content/plugins/sfwd-lms/` — Reference for hooks, functions, and integration points.
- **Private data files:** `wp-content/plugins/hl-core/data/` — Contains real program Excel files and assessment documents. Gitignored — never commit to repo.
  - `data/Assessments/` — B2E Teacher Self-Assessment (Pre/Post) and Children Assessment source documents
  - `data/Lutheran - Control Group/` — School info, teacher roster, child roster spreadsheets for Lutheran seeder

## Documentation Files (in docs/)
| File | Covers |
|------|--------|
| 00_README_SCOPE.md | Project scope and high-level requirements |
| 01_GLOSSARY_CANONICAL_TERMS.md.md | Terminology definitions including Control Group and Cohort Group |
| 02_DOMAIN_MODEL_ORG_STRUCTURE.md.md | Org units, cohorts, enrollments, teams |
| 03_ROLES_PERMISSIONS_REPORT_VISIBILITY.md.md | Roles, capabilities, access control |
| 04_COHORT_PATHWAYS_ACTIVITIES_RULES.md.md | Pathways, activities, prerequisite rules |
| 05_UNLOCKING_LOGIC_PREREQS_DRIP_OVERRIDES.md.md | Unlock logic, drip rules, overrides |
| 06_ASSESSMENTS_CHILDREN_TEACHER_OBSERVATION_COACHING.md.md | Assessment instruments (custom PHP for teacher + children, JFB for observations only), coaching |
| 07_IMPORTS_ROSTERS_IDENTITIES_MATCHING.md.md | CSV import pipeline, identity matching |
| 08_REPORTING_METRICS_VIEWS_EXPORTS.md.md | Reporting, metrics, export formats, program vs control comparison |
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
> **Last completed:** Phase 20 (Control Group Support) ✅
>
> **Next up:** Phase 21 items (Lutheran seeder, nuke command, assessment fixes)
>
> Should I continue with [specific task], or do you have something else in mind?"

### 3. ALWAYS update README.md (see top-of-file checklist)
After completing any feature, fix, or refactoring:
- Update the "What's Implemented" section if you built something new
- Check off completed items in the Build Queue with `[x]`
- Mark partially completed items with `[~]` and add a note explaining what's done and what remains
- Update the "Architecture" file tree if you added new files/directories
- Self-check before saying "done": "Did I update README.md?" If no, do it NOW.

### 4. Before context compaction (see top-of-file checklist)
Commit all code + update README.md + push BEFORE compacting. This is the survival mechanism for cross-session continuity.

### 5. Read relevant docs before building features
Don't build from memory or assumptions. Before implementing any feature, read the specific doc file(s) listed next to each build queue item.

### 6. Terminology
The project uses "Cohort" (not "Program") internally. The front-end shows "Program" to participants (see doc 10 §1 label mapping). All code, table names, and documentation use "Cohort" terminology.

## WordPress Roles vs Cohort Roles (Critical Architecture Decision)

HL Core creates ONE custom WP role: **Coach** (with `read`, `manage_hl_core`, `hl_view_cohorts`, `hl_view_enrollments`). Administrators get `manage_hl_core` capability added.

**ALL cohort-specific roles** (teacher, mentor, school_leader, district_leader) are stored in the `hl_enrollment.roles` JSON column — NOT as WordPress roles. This is by design:

| Who | WP Role | HL Core Identification |
|---|---|---|
| Teachers, Mentors, Leaders | `subscriber` (or any basic WP role) | `hl_enrollment.roles` JSON per cohort |
| Housman Coaches | `coach` (custom WP role) | `manage_hl_core` capability |
| Housman Admins | `administrator` | `manage_hl_core` capability |

**Why:** A user can be a teacher in one cohort and a mentor in another. WP roles are global; enrollment roles are per-cohort. The BuddyBoss sidebar, front-end pages, and scope filtering all use enrollment roles from `hl_enrollment`, not WP roles.

Some legacy WP roles exist (`school_district`, `teacher`, `mentor`, `school_leader`) from old Elementor pages — HL Core does NOT use these.

## Coach Assignment Architecture

Coaches are assigned at three scope levels with "most specific wins" resolution:

1. **School level** — coach is default for all enrollments at that school
2. **Team level** — overrides school assignment for team members
3. **Enrollment level** — overrides everything for a specific participant

Stored in `hl_coach_assignment` table with `effective_from`/`effective_to` dates. Reassignment closes the old record and creates a new one — full history preserved. Coaching sessions retain the original `coach_user_id` (frozen at time of session).

See doc 10 §13-14 for full spec.

## Scope Service (HL_Scope_Service)

Shared static helper used by ALL listing pages for role-based data filtering:
- Admin → sees all (no restriction)
- Coach → filtered by `hl_coach_assignment` cohort_ids + own enrollments
- District Leader → expands to all schools in their district
- School Leader → filtered to their school(s)
- Mentor → filtered to their team(s)
- Teacher → filtered to their own enrollment/assignments

Static cache per user_id per request. Convenience helpers: `can_view_cohort()`, `can_view_school()`, `has_role()`, `filter_by_ids()`.

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

## Forms Architecture: Custom PHP + JFB for Observations Only

HL Core uses a **primarily custom PHP approach** for forms and data collection:

### Custom PHP handles:
- **Teacher Self-Assessment** (pre and post) — Custom instrument system with structured JSON definitions in `hl_teacher_assessment_instrument`, response storage in `hl_teacher_assessment_instance.responses_json`. Custom renderer supports PRE (single-column) and POST (dual-column retrospective with PRE responses shown alongside new ratings).
- **Children Assessment** — Dynamic per-child matrix generated from classroom roster + instrument definition. Rendered from `hl_instrument.questions` JSON.
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
1. **Admin creates a form in JetFormBuilder** with hidden fields: `hl_enrollment_id`, `hl_activity_id`, `hl_cohort_id`, `hl_observation_id`
2. **Admin adds "Call Hook" post-submit action** with hook name `hl_core_form_submitted`
3. **HL Core renders the JFB form** on the observations page with hidden fields pre-populated
4. **On submit:** JFB fires hook → HL Core updates observation status → updates activity_state → triggers rollup

## Control Group Research Design

### Purpose
Housman measures program impact by comparing:
- **Program cohorts**: full B2E Mastery curriculum (courses, coaching, observations + assessments)
- **Control cohorts**: assessment-only (Teacher Self-Assessment Pre/Post + Children Assessment Pre/Post)

### How it works
1. Create a **Cohort Group** (e.g., "B2E Mastery - Lutheran Services Florida")
2. Add the **program cohort** to the group
3. Create a **control cohort** (`is_control_group = true`) in the same group
4. Control cohort gets an assessment-only pathway (4 activities: TSA Pre, CA Pre, TSA Post, CA Post)
5. POST activities are time-gated via drip rules
6. **Comparison reports** appear in Admin Reports when selecting the Group filter

### UI/UX adaptations for control cohorts:
- Purple "Control" badge in admin cohort list and editor
- Coaching and Teams tabs auto-hidden in admin and frontend
- Assessment-only pathway (no course or coaching activities)

## Git & Deployment

### Repository
- **GitHub:** `https://github.com/Corsox-Tech/hl-core.git`
- **Branch:** `main` (single-branch workflow)
- Private repo — never commit data files or credentials

### Local Development
- **WordPress root:** `C:\Users\MateoGonzalez\Dev Projects Mateo\housman-learning-academy\`
- **Plugin path:** `wp-content/plugins/hl-core/`
- Local files are the source of truth for editing. Claude Code edits files here.
- **Note:** The local WordPress installation exists only as a file editing workspace. Testing happens on staging.

### Deployment Workflow
1. Claude Code edits files locally (in the Dev Projects folder)
2. Claude Code commits and pushes to GitHub (`main` branch)
3. Hostinger's Git integration auto-pulls changes to the staging server
4. Claude Code runs WP-CLI commands on staging via SSH to test (seeders, migrations, etc.)
5. Manual verification on the staging site by the user

### Staging Server
- **URL:** `https://staging.academy.housmanlearning.com`
- **Hostinger hosting** with SSH access
- **Staging WordPress root:** `/home/u665917738/domains/academy.housmanlearning.com/public_html/staging/`
- **Staging plugin path:** `/home/u665917738/domains/academy.housmanlearning.com/public_html/staging/wp-content/plugins/hl-core/`
- **IMPORTANT:** Staging is a subdirectory install within the main domain, NOT a separate domain folder.

### Staging SSH Access (NON-NEGOTIABLE RULES)
Claude Code has SSH access to the staging server for running WP-CLI commands, checking logs, debugging, and testing.

**Connection:**
```bash
ssh -p 65002 u665917738@145.223.76.150
```

**Running WP-CLI commands on staging:**
```bash
ssh -p 65002 u665917738@145.223.76.150 "cd /home/u665917738/domains/academy.housmanlearning.com/public_html/staging && wp <command>"
```

**Examples:**
```bash
# Seed data
ssh -p 65002 u665917738@145.223.76.150 "cd /home/u665917738/domains/academy.housmanlearning.com/public_html/staging && wp hl-core seed-lutheran"

# Nuke and re-seed
ssh -p 65002 u665917738@145.223.76.150 "cd /home/u665917738/domains/academy.housmanlearning.com/public_html/staging && wp hl-core nuke --confirm='DELETE ALL DATA' && wp hl-core seed-lutheran"

# Check DB tables
ssh -p 65002 u665917738@145.223.76.150 "cd /home/u665917738/domains/academy.housmanlearning.com/public_html/staging && wp db query 'SELECT COUNT(*) FROM wp_hl_cohort'"

# Check plugin status
ssh -p 65002 u665917738@145.223.76.150 "cd /home/u665917738/domains/academy.housmanlearning.com/public_html/staging && wp plugin list --status=active"

# Flush caches
ssh -p 65002 u665917738@145.223.76.150 "cd /home/u665917738/domains/academy.housmanlearning.com/public_html/staging && wp cache flush"
```

**ABSOLUTE RULES — NEVER VIOLATE THESE:**
1. **ALWAYS `cd` into the staging directory first.** Every SSH command MUST start with `cd /home/u665917738/domains/academy.housmanlearning.com/public_html/staging &&` before running any `wp` command. Running `wp` from any other directory could hit production.
2. **NEVER run commands against production.** The production root is `/home/u665917738/domains/academy.housmanlearning.com/public_html/` (WITHOUT `/staging/`). If you see a path without `/staging/` in it, STOP — you are targeting production.
3. **NEVER `cd` to the production root** (`/home/u665917738/domains/academy.housmanlearning.com/public_html/`) — this is the live site.
4. **NEVER modify files directly on the server** via SSH. All code changes go through Git (edit locally → commit → push → Hostinger auto-pulls). SSH is for running commands and debugging only.
5. **If in doubt, don't run the command.** Ask the user first.

### Production Server (DO NOT TOUCH)
- **URL:** `https://academy.housmanlearning.com`
- **WordPress root:** `/home/u665917738/domains/academy.housmanlearning.com/public_html/`
- **Do NOT deploy to production without explicit approval from the user.**
- **Do NOT SSH into the production directory. EVER.**

### After deploying new schema changes (run on staging via SSH):
```bash
cd /home/u665917738/domains/academy.housmanlearning.com/public_html/staging
wp hl-core nuke --confirm="DELETE ALL DATA"
wp hl-core seed-lutheran
```

### After adding new shortcode pages:
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
- `wp hl-core seed-demo [--clean]` — Generic demo data (2 schools, 15 enrollments, code: DEMO-2026)
- `wp hl-core seed-lutheran [--clean]` — Lutheran Services Florida control group data (12 schools, 47 teachers, 286 children, assessment-only pathway, code: LUTHERAN_CONTROL_2026)
- `wp hl-core seed-palm-beach [--clean]` — ELC Palm Beach program data (12 schools, 47 teachers, 286 children, code: ELC-PB-2026)
- `wp hl-core nuke --confirm="DELETE ALL DATA"` — **DESTRUCTIVE: Deletes ALL HL Core data** (all hl_* tables truncated, seeded users removed, auto-increment reset). Safety gate: only runs if site URL contains `staging.academy.housmanlearning.com` or `.local`.
- `wp hl-core create-pages [--force] [--status=draft]` — Creates all shortcode WordPress pages

## Architecture Summary
```
/hl-core/
  hl-core.php                    # Plugin bootstrap (singleton)
  README.md                      # Living status doc (ALWAYS UPDATE)
  CLAUDE.md                      # This file — dev guide for AI assistants
  /data/                         # Private data files (gitignored)
    /Assessments/                # B2E assessment source documents (.docx)
    /Lutheran - Control Group/   # Lutheran spreadsheets (.xlsx)
  /docs/                         # Spec documents (11 files, read-only reference)
  /includes/
    class-hl-installer.php       # DB schema (35+ tables) + activation + migrations
    /domain/                     # Entity models (9+ classes incl. Teacher_Assessment_Instrument)
    /domain/repositories/        # CRUD repositories (8 classes)
    /cli/                        # WP-CLI commands (seed-demo, seed-lutheran, seed-palm-beach, nuke, create-pages)
    /services/                   # Business logic (14+ services incl. HL_Scope_Service, HL_Pathway_Assignment_Service)
    /security/                   # Capabilities + authorization
    /integrations/               # LearnDash + JetFormBuilder + BuddyBoss (3 classes)
    /admin/                      # WP admin pages (15+ controllers incl. Cohort Groups, Instruments)
    /frontend/                   # Shortcode renderers (26+ pages + instrument renderer + teacher assessment renderer)
    /api/                        # REST API routes
    /utils/                      # DB, date, normalization helpers
  /assets/
    /css/                        # admin.css, admin-import-wizard.css, frontend.css (with CSS custom properties design system)
    /js/                         # admin-import-wizard.js, frontend.js
```

## Environment
- WordPress files stored locally (Dev Projects folder) — used as editing workspace only
- Deployment: push to GitHub → auto-pull to Hostinger staging
- Testing: on staging site via SSH (WP-CLI) and browser (https://staging.academy.housmanlearning.com)
- Database: MySQL on Hostinger (staging)
- PHP 7.4+
- Run Claude Code from the plugin directory: `wp-content/plugins/hl-core/`

## Plugin Dependencies
- **WordPress 6.0+** (required)
- **PHP 7.4+** (required)
- **JetFormBuilder** (required for observation forms only; teacher assessments use custom PHP system)
- **LearnDash** (required — course progress tracking)
- **BuddyBoss Theme + Platform** (optional — sidebar navigation, profile links; gracefully degrades if not installed)

## Current Status (as of Feb 2026)
**Phases 1-20 complete.** 26+ shortcode pages, 15+ admin pages, 35+ DB tables, 14+ services. All core functionality operational including custom teacher self-assessment system and control group support.

**Active development:**
- Lutheran control group seeder (seed-lutheran command)
- Nuclear clean command (nuke)
- Children assessment system enhancements
- Frontend assessment routing for teacher pathway view

**Remaining (Future/Lower Priority):**
- MS365 Calendar Integration (requires Azure AD infrastructure)
- BuddyBoss Profile Tab (out of scope for v1)
- Scope-based user creation for client leaders
- Import templates (downloadable CSV)
- Frontend CSS redesign (modernize all 25+ shortcode pages)
