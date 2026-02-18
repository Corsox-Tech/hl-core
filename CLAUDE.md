# CLAUDE.md — HL Core Plugin Development Guide

## Project Overview
This is the Housman Learning Academy (HLA) WordPress site. The primary development target is the **hl-core** custom plugin located at `wp-content/plugins/hl-core/`.

## Critical Paths
- **Plugin root:** `wp-content/plugins/hl-core/`
- **Documentation (11 spec files):** `wp-content/plugins/hl-core/docs/` — These are the canonical specifications for all features, domain models, business rules, and architecture decisions. Read the relevant doc file(s) before building any feature.
- **README.md:** `wp-content/plugins/hl-core/README.md` — This is the living status tracker for the entire plugin. It documents what's built, what's pending, architecture, and design decisions.
- **LearnDash plugin:** `wp-content/plugins/sfwd-lms/` — Reference for hooks, functions, and integration points.

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
| 10_FRONTEND_PAGES_NAVIGATION_UX.md | Front-end page hierarchy, tabs, visibility rules, navigation |

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
> **Last completed:** 1.1 (DB Schema Cleanup) and 1.2 (JFB Integration Service) ✅
>
> **In progress:** 1.3 (Activity Admin Enhancement) is partially done — the JFB form dropdown works but the instrument dropdown for children_assessment and the LearnDash course selector are not yet wired up.
>
> **Next up:** 2.1 (LearnDash Hook Body), 2.2 (Completion Rollup Engine)
>
> Should I finish 1.3 first, or would you prefer to work on something else?"

### 3. Always update README.md after making changes
After completing any feature, fix, or refactoring:
- Update the "What's Implemented" section if you built something new
- Check off completed items in the Build Queue with `[x]`
- Mark partially completed items with `[~]` and add a note explaining what's done and what remains, e.g.:
  `[~] **1.3 Activity Admin Enhancement** — DONE: JFB form dropdown. TODO: instrument dropdown for children_assessment, LearnDash course selector.`
- Update the "Architecture" file tree if you added new files/directories
- Keep the format consistent with what's already there

### 4. Before suggesting "Clear Context" or when the conversation is getting long
If you're about to suggest clearing context, or if the session has been going a while:
- **STOP and update README.md FIRST** before the context is cleared
- Check off all completed Build Queue items with `[x]`
- Mark any in-progress items with `[~]` and write a clear note about exactly what's done and what's left
- Update "What's Implemented" with anything new
- This ensures the next session (or post-clear continuation) picks up exactly where we left off with no lost work

### 5. Read relevant docs before building features
Don't build from memory or assumptions. Before implementing any feature, read the specific doc file(s) listed next to each build queue item. For example:
- Building JFB integration → Read doc 06 (section 2)
- Building assessment forms → Read doc 06
- Working on import wizard → Read doc 07
- Implementing unlock logic → Read docs 04 and 05
- Adding roles/permissions → Read doc 03

### 6. Terminology
The project uses "Cohort" (not "Program"). All code, comments, variable names, table names, and documentation must use "Cohort" terminology consistently. See doc 01 for the full glossary.

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
4. **Admin creates an Activity** in a Pathway — selects activity_type (e.g., `teacher_self_assessment`) and picks the JFB form from a dropdown → stored in `hl_activity.external_ref` JSON as `{"form_plugin": "jetformbuilder", "form_id": 123}`
5. **Participant views their pathway** — clicks the activity to open it
6. **HL Core renders the JFB form** on the page (via JFB shortcode/block) with hidden fields pre-populated from the participant's enrollment context
7. **Participant submits** → JFB handles validation and stores responses in JFB Form Records → JFB fires the `hl_core_form_submitted` hook → HL Core's listener updates the assessment instance status to "submitted", updates `hl_activity_state` to complete, and triggers completion rollup recomputation

### For Observations (JFB-powered)
Same flow as above, but with an extra step: before opening the form, the mentor selects which teacher they're observing (from their team members). HL Core creates an `hl_observation` record with context (mentor, teacher, classroom, cohort), then renders the JFB observation form with those IDs as hidden fields. On submit, HL Core links the JFB submission to the observation record.

### For Children Assessment (Custom PHP)
- Admin creates a Children Assessment instrument in `hl_instrument` (questions JSON for per-child evaluation)
- Admin links the instrument to an Activity via `hl_activity.external_ref` as `{"instrument_id": X}`
- HL Core generates `hl_children_assessment_instance` records per (cohort, classroom, teacher) when teaching assignments change
- The front-end form is rendered by a custom `HL_Instrument_Renderer` class: a matrix/table with one row per child, each row using the instrument's questions
- Responses are stored in `hl_children_assessment_childrow` (answers_json per child)
- Completion is 100% only when ALL required classroom instances are submitted

### Key principle: HL Core is the orchestration layer
HL Core does NOT need to know what's inside a JFB form. It only tracks:
- Which form is linked to which activity (`hl_activity.external_ref`)
- Whether the activity is complete (`hl_activity_state`)
- Contextual records for observations (`hl_observation`) and assessment instances (`hl_teacher_assessment_instance`)
- A reference to the JFB submission ID (optional, stored in instance record for audit/reporting)

### Response storage and privacy
- **JFB-powered forms:** Responses live in JetFormBuilder's Form Records. Staff (Housman Admin / Coach) view them through JFB's admin interface. Privacy is naturally enforced because teachers/mentors don't have WP admin access.
- **Children Assessment (custom):** Responses live in `hl_children_assessment_childrow`. Privacy enforced by HL Core's `HL_Security::assert_can()`.

### Database tables affected by hybrid model
Tables STILL NEEDED for orchestration (even with JFB handling the form):
- `hl_teacher_assessment_instance` — tracks which teacher has pre/post assessment, status (not_started/submitted), submitted_at. Needs new columns: jfb_form_id, jfb_record_id.
- `hl_observation` — tracks who observed whom, which classroom, status. Links to coaching sessions. Needs new columns: jfb_form_id, jfb_record_id.
- `hl_observation_attachment` — file attachments (could also use JFB file upload, but keeping this gives more control)

Tables NO LONGER NEEDED (JFB handles response storage):
- `hl_teacher_assessment_response` — JFB Form Records stores the actual answers. Remove from installer or keep as deprecated.
- `hl_observation_response` — JFB Form Records stores the actual answers. Remove from installer or keep as deprecated.

Tables UNCHANGED (Children Assessment is fully custom):
- `hl_instrument` — now only needed for children assessment instruments (infant/toddler/preschool)
- `hl_children_assessment_instance`
- `hl_children_assessment_childrow`

## Architecture Summary
```
/hl-core/
  hl-core.php                    # Plugin bootstrap (singleton)
  README.md                      # Living status doc (ALWAYS UPDATE)
  /docs/                         # Spec documents (10 files, read-only reference)
  /includes/
    class-hl-installer.php       # DB schema + activation
    /domain/                     # Entity models
    /domain/repositories/        # CRUD repositories
    /services/                   # Business logic (12 services)
    /security/                   # Capabilities + authorization
    /integrations/               # LearnDash + JetFormBuilder integration
    /admin/                      # WP admin pages (11 controllers)
    /frontend/                   # Shortcode renderers (3+ pages)
    /api/                        # REST API routes
    /utils/                      # DB, date, normalization helpers
  /assets/
    /css/                        # admin.css, admin-import-wizard.css, frontend.css
    /js/                         # admin-import-wizard.js, frontend.js
```

## Environment
- WordPress running locally via Local by Flywheel
- JetFormBuilder plugin must be installed and active
- Database: MySQL (managed by Local)
- PHP version: Check Local settings
- Run Claude Code from this directory (WordPress root: `app/public/`)
