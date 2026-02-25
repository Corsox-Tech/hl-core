# Grand Rename — Claude Code Prompts

Each prompt below is self-contained. Copy-paste it into a Claude Code session.
Run tasks in order within each phase. A4/A5/A6 can be parallel. C4/C5/C6 can be parallel.

---

## PHASE A: Center → School

### A1 — DB Migration (use Opus)

```
PHASE A1: Center → School — Database Migration

Read CLAUDE.md and README.md first. Then read docs/02_DOMAIN_MODEL_ORG_STRUCTURE.md.md for the current entity model. Also read docs/RENAME_PLAN.md for the full rename context.

We are renaming "Center" to "School" across the entire plugin. This task is the DB migration only.

In class-hl-installer.php, add a new migration method `migrate_center_to_school()` that:

1. Rename table `hl_cohort_center` → `hl_cohort_school`
2. In `hl_cohort_school`: rename column `center_id` → `school_id`
3. In `hl_orgunit`: UPDATE all rows WHERE type = 'center' SET type = 'school'
4. In `hl_classroom`: rename column `center_id` → `school_id`
5. In `hl_team`: rename column `center_id` → `school_id`
6. In `hl_child`: rename column `center_id` → `school_id`
7. In `hl_coach_assignment`: UPDATE scope_type — add 'school' value, UPDATE all rows SET scope_type = 'school' WHERE scope_type = 'center', then ALTER enum to remove 'center'
8. In `hl_observation`: rename column `center_id` → `school_id` (if exists)

Also update the `create_tables()` schema definitions so all new installs use `school` from the start:
- Table name `hl_cohort_school` (not hl_cohort_center)
- All column names use `school_id` instead of `center_id`
- OrgUnit type enum/varchar includes 'school' instead of 'center'
- coach_assignment scope_type enum includes 'school' instead of 'center'

Bump schema revision. Use RENAME TABLE for table renames and ALTER TABLE ... CHANGE for column renames. Wrap everything in the migration method with column/table existence checks so it's safe to re-run.

IMPORTANT: Do NOT touch any PHP code outside class-hl-installer.php in this task. Do NOT update README.md yet — that comes in task A7. Commit with message: "db: migrate center → school (Phase A1)"
```

### A2 — Domain Models & Repositories (use Opus)

```
PHASE A2: Center → School — Domain Models & Repositories

Read CLAUDE.md and README.md first. Read docs/RENAME_PLAN.md for context.

The DB migration from A1 renamed all center columns to school. Now update the PHP domain layer to match.

1. In ALL domain model classes in includes/domain/:
   - Rename any `center_id` property to `school_id`
   - Rename any `center_*` properties to `school_*`
   - Update to_array() and from_row() methods
   - In HL_OrgUnit: the type constant/check for 'center' becomes 'school'

2. In ALL repository classes in includes/domain/repositories/:
   - Update all SQL queries: table names (hl_cohort_center → hl_cohort_school), column names (center_id → school_id)
   - Update method names: any method with "center" in the name → "school" (e.g., get_centers_for_cohort → get_schools_for_cohort)

3. Rename any domain model or repository file that has "center" in the filename.

Do a project-wide search for "center_id" and "cohort_center" in includes/domain/ to make sure nothing is missed.

IMPORTANT: Do NOT update services, admin pages, or frontend in this task. Do NOT update README.md yet. Commit: "refactor: rename center → school in domain models & repositories (Phase A2)"
```

### A3 — Services Layer (use Opus)

```
PHASE A3: Center → School — Services Layer

Read CLAUDE.md and README.md first. Read docs/RENAME_PLAN.md for context.

Update ALL service classes in includes/services/ to use "school" instead of "center":

1. HL_Cohort_Service — any center references in queries or method names
2. HL_Enrollment_Service — center scope bindings
3. HL_Team_Service — center_id → school_id in all queries and parameters
4. HL_Classroom_Service — center_id → school_id everywhere
5. HL_Assessment_Service — any center references
6. HL_Observation_Service — center_id → school_id
7. HL_Coaching_Service — any center references
8. HL_Coach_Assignment_Service — scope_type 'center' → 'school', center_id → school_id
9. HL_Import_Service — center references in import validation, column names, header synonyms
10. HL_Reporting_Service — center_summary → school_summary, all center filters/queries, export methods
11. HL_Pathway_Assignment_Service — any center references
12. HL_Scope_Service — center_ids → school_ids, can_view_center → can_view_school, all center scope logic
13. HL_Audit_Service — any center references in log messages

For each service:
- Rename method parameters: $center_id → $school_id
- Rename method names: *_center_* → *_school_*
- Update SQL queries: column names and table names
- Update string literals in log messages and error messages

Run: grep -ri "center" includes/services/ — to make sure nothing is missed.

IMPORTANT: Do NOT update admin pages, frontend, or CLI in this task. Do NOT update README.md yet. Commit: "refactor: rename center → school in services layer (Phase A3)"
```

### A4 — Admin Pages (use Sonnet, can run parallel with A5/A6)

```
PHASE A4: Center → School — Admin Pages

Read CLAUDE.md and README.md first. Read docs/RENAME_PLAN.md for context.

Update ALL admin page classes in includes/admin/ to use "school" instead of "center":

1. class-hl-admin-cohorts.php — Centers tab → Schools tab, "Link Center" → "Link School", center_id → school_id
2. class-hl-admin-orgunits.php — "Add Center" → "Add School", type='center' → type='school', all labels
3. class-hl-admin-enrollments.php — center scope references
4. class-hl-admin-teams.php — center_id → school_id
5. class-hl-admin-classrooms.php — center references
6. class-hl-admin-coaching.php — center references
7. class-hl-admin-coach-assignments.php — scope_type 'center' → 'school'
8. class-hl-admin-reporting.php — center_summary → school_summary, center filter labels
9. class-hl-admin-assessments.php — any center references
10. class-hl-admin-import.php — center column names, import type labels, synonyms

For each file:
- Update HTML labels: "Center" → "School"
- Update form field names: center_id → school_id
- Update PHP variable names: $center_* → $school_*
- Update method calls to renamed service methods from A3
- Update nonce action names if they contain "center"

Run: grep -ri "center" includes/admin/ — to verify nothing missed.

IMPORTANT: Do NOT update frontend or CLI. Do NOT update README.md yet. Commit: "refactor: rename center → school in admin pages (Phase A4)"
```

### A5 — Frontend Shortcodes (use Sonnet, can run parallel with A4/A6)

```
PHASE A5: Center → School — Frontend Shortcodes

Read CLAUDE.md and README.md first. Read docs/RENAME_PLAN.md for context.

Update ALL frontend shortcode classes in includes/frontend/:

1. RENAME FILES:
   - class-hl-frontend-centers-listing.php → class-hl-frontend-schools-listing.php
   - class-hl-frontend-center-page.php → class-hl-frontend-school-page.php

2. RENAME CLASSES:
   - HL_Frontend_Centers_Listing → HL_Frontend_Schools_Listing
   - HL_Frontend_Center_Page → HL_Frontend_School_Page

3. RENAME SHORTCODES:
   - [hl_centers_listing] → [hl_schools_listing]
   - [hl_center_page] → [hl_school_page]

4. UPDATE ALL frontend files — "Center" → "School" in:
   - User-visible labels and headings
   - Variable names ($center_* → $school_*)
   - Method calls to renamed service methods
   - CSS classes (.hl-center-* → .hl-school-*)
   - URL parameters

5. Also update:
   - includes/integrations/class-hl-buddyboss-integration.php — menu item labels
   - hl-core.php — shortcode registration, require_once paths
   - assets/css/frontend.css — CSS class names

Run: grep -ri "center" includes/frontend/ — to verify.

IMPORTANT: Do NOT update CLI or docs. Do NOT update README.md yet. Commit: "refactor: rename center → school in frontend shortcodes (Phase A5)"
```

### A6 — CLI & REST API (use Sonnet, can run parallel with A4/A5)

```
PHASE A6: Center → School — CLI Commands & REST API

Read CLAUDE.md and README.md first. Read docs/RENAME_PLAN.md for context.

1. CLI commands (includes/cli/):
   - class-hl-cli-seed-demo.php — all center refs → school (OrgUnit type='school', variable names, messages)
   - class-hl-cli-seed-palm-beach.php — same
   - class-hl-cli-seed-lutheran.php — same
   - lutheran-seed-data.php — any center references in data arrays
   - class-hl-cli-nuke.php — any center references
   - class-hl-cli-create-pages.php — page titles: "Centers" → "Schools", shortcodes: [hl_centers_listing] → [hl_schools_listing], [hl_center_page] → [hl_school_page]

2. REST API (includes/api/):
   - /orgunits?type=center → support type=school
   - Any center references in responses

3. hl-core.php — any remaining center references in hooks/filters

Run: grep -ri "center" includes/cli/ includes/api/ — to verify.

IMPORTANT: Do NOT update docs or README.md yet. Commit: "refactor: rename center → school in CLI & API (Phase A6)"
```

### A7 — Documentation (use Sonnet)

```
PHASE A7: Center → School — Documentation Update

Read docs/RENAME_PLAN.md for full context. Phases A1-A6 are complete.

Update ALL documentation files. Replace "Center" with "School" throughout:

1. docs/01_GLOSSARY_CANONICAL_TERMS.md.md — §1.3: rename definition to "School". Move "Center" to deprecated. Remove "School" from UI synonyms (it's now canonical). Update Center Leader → School Leader in §2.3.
2. docs/02_DOMAIN_MODEL_ORG_STRUCTURE.md.md — OrgUnit type 'center' → 'school', all center_id → school_id, hl_cohort_center → hl_cohort_school, relationship diagram, constraints
3. docs/03_ROLES_PERMISSIONS_REPORT_VISIBILITY.md.md — Center Leader → School Leader, center scope → school scope
4. docs/04_COHORT_PATHWAYS_ACTIVITIES_RULES.md.md — any center references
5. docs/06_ASSESSMENTS_CHILDREN_TEACHER_OBSERVATION_COACHING.md.md — center refs
6. docs/07_IMPORTS_ROSTERS_IDENTITIES_MATCHING.md.md — import column names
7. docs/08_REPORTING_METRICS_VIEWS_EXPORTS.md.md — center_summary → school_summary
8. docs/09_PLUGIN_ARCHITECTURE_CONSTRAINTS_ACCEPTANCE_TESTS.md.md
9. docs/10_FRONTEND_PAGES_NAVIGATION_UX.md — page names, shortcodes
10. Any other docs with center references

Also update CLAUDE.md — all center references.

Finally update README.md:
- Database schema table list (hl_cohort_center → hl_cohort_school)
- All "Center" references → "School" in descriptions
- Frontend pages: Centers Listing → Schools Listing, Center Page → School Page
- Build Queue completed item descriptions
- Check off A1-A7 in Phase 22

Commit: "docs: rename Center → School across all documentation (Phase A7)"
```

---

## PHASE B: Children Assessment → Child Assessment

### B1 — DB Migration (use Opus)

```
PHASE B1: Children Assessment → Child Assessment — Database Migration

Read CLAUDE.md and README.md first. Read docs/RENAME_PLAN.md. Phase A is complete.

In class-hl-installer.php, add migration `migrate_children_to_child_assessment()`:

1. RENAME TABLE `hl_children_assessment_instance` → `hl_child_assessment_instance`
2. RENAME TABLE `hl_children_assessment_childrow` → `hl_child_assessment_childrow`
3. In `hl_activity`: UPDATE activity_type = 'child_assessment' WHERE activity_type = 'children_assessment'
4. Update activity_type column definition if it's an enum (add 'child_assessment', remove 'children_assessment')

Update `create_tables()` definitions with new table names and enum values.
Bump schema revision. Wrap in existence checks.

IMPORTANT: Only touch class-hl-installer.php. Commit: "db: migrate children → child assessment (Phase B1)"
```

### B2 — All PHP Code (use Opus)

```
PHASE B2: Children Assessment → Child Assessment — All PHP Code

Read CLAUDE.md and README.md. Read docs/RENAME_PLAN.md. B1 is complete.

Project-wide rename of "children_assessment" → "child_assessment" and "Children Assessment" → "Child Assessment" across ALL PHP files.

Systematic approach — update every directory:

1. includes/domain/ — any children_assessment references
2. includes/domain/repositories/ — table names in queries
3. includes/services/:
   - HL_Assessment_Service — rename methods (generate_children_assessment_instances → generate_child_assessment_instances, etc.)
   - HL_Reporting_Service — export methods, query refs
   - All other services
4. includes/admin/:
   - class-hl-admin-assessments.php — tab labels, methods
   - class-hl-admin-reporting.php — exports, labels
   - All other admin files
5. includes/frontend/:
   - RENAME: class-hl-frontend-children-assessment.php → class-hl-frontend-child-assessment.php
   - RENAME CLASS: HL_Frontend_Children_Assessment → HL_Frontend_Child_Assessment
   - RENAME SHORTCODE: [hl_children_assessment] → [hl_child_assessment]
   - class-hl-instrument-renderer.php
   - class-hl-frontend-program-page.php — activity type checks
   - class-hl-frontend-activity-page.php
   - class-hl-frontend-my-progress.php
   - All other frontend files
6. includes/cli/ — all seeders: activity_type, variables, messages. create-pages: page title/slug.
7. includes/integrations/ — any references
8. hl-core.php — shortcode registration, require_once
9. assets/css/frontend.css — class names
10. assets/js/frontend.js — any references

Run: grep -ri "children_assessment\|children-assessment\|Children Assessment" wp-content/plugins/hl-core/ — verify 0 hits after.

Commit: "refactor: rename children → child assessment in all PHP (Phase B2)"
```

### B3 — Documentation (use Sonnet)

```
PHASE B3: Children Assessment → Child Assessment — Documentation

Read docs/RENAME_PLAN.md. Phases A and B1-B2 complete.

Update ALL documentation:

1. docs/01_GLOSSARY_CANONICAL_TERMS.md.md — §7.2 title + all refs
2. docs/02_DOMAIN_MODEL_ORG_STRUCTURE.md.md — table names, diagram
3. docs/04_COHORT_PATHWAYS_ACTIVITIES_RULES.md.md — §3.2.3 title, activity_type, tables
4. docs/06_ASSESSMENTS_CHILDREN_TEACHER_OBSERVATION_COACHING.md.md — comprehensive update
5. docs/08_REPORTING_METRICS_VIEWS_EXPORTS.md.md — exports
6. docs/10_FRONTEND_PAGES_NAVIGATION_UX.md — page names, shortcodes
7. All other docs with "children assessment" references

Update CLAUDE.md — table names, architecture.
Update README.md — all references, check off B1-B3 in Phase 22.

Commit: "docs: rename Children → Child Assessment in documentation (Phase B3)"
```

---

## PHASE C: Cohort → Track + CohortGroup → Cohort

### C1 — DB Migration (use Opus — MOST CRITICAL TASK)

```
PHASE C1: Cohort → Track + CohortGroup → Cohort — Database Migration

Read CLAUDE.md and README.md. Read docs/RENAME_PLAN.md carefully. Phases A and B are complete.

This is the most complex migration. The hierarchy restructure:
- Old `hl_cohort` (the run) → becomes `hl_track`
- Old `hl_cohort_group` (the container) → becomes `hl_cohort`

In class-hl-installer.php, add migration `migrate_cohort_to_track()`:

STEP 1: Rename old cohort table → track
- RENAME TABLE `hl_cohort` → `hl_track`
- ALTER TABLE `hl_track` CHANGE `cohort_id` → `track_id` (PK)
- ALTER TABLE `hl_track` CHANGE `cohort_code` → `track_code`
- ALTER TABLE `hl_track` CHANGE `cohort_group_id` → `cohort_id` (FK to new container)
- Keep columns: cohort_name → track_name, is_control_group, status, start_date, end_date, etc.

STEP 2: Rename old cohort_group → cohort (the container)
- RENAME TABLE `hl_cohort_group` → `hl_cohort`
- ALTER TABLE `hl_cohort` CHANGE `group_id` → `cohort_id` (PK)
- ALTER TABLE `hl_cohort` CHANGE `group_uuid` → `cohort_uuid`
- ALTER TABLE `hl_cohort` CHANGE `group_name` → `cohort_name`
- ALTER TABLE `hl_cohort` CHANGE `group_code` → `cohort_code`

STEP 3: Rename FK columns in ALL dependent tables
(Note: after Phase A, "center" is already "school")
- `hl_track_school` (was hl_cohort_school): CHANGE `cohort_id` → `track_id` — ALSO RENAME TABLE from `hl_cohort_school` → `hl_track_school`
- `hl_enrollment`: CHANGE `cohort_id` → `track_id`
- `hl_team`: CHANGE `cohort_id` → `track_id`
- `hl_pathway`: CHANGE `cohort_id` → `track_id`
- `hl_activity`: CHANGE `cohort_id` → `track_id`
- `hl_activity_state`: CHANGE `cohort_id` → `track_id` (if column exists)
- `hl_completion_rollup`: CHANGE `cohort_id` → `track_id` (if column exists)
- `hl_teacher_assessment_instance`: CHANGE `cohort_id` → `track_id`
- `hl_child_assessment_instance`: CHANGE `cohort_id` → `track_id`
- `hl_observation`: CHANGE `cohort_id` → `track_id`
- `hl_coaching_session`: CHANGE `cohort_id` → `track_id`
- `hl_coach_assignment`: CHANGE `cohort_id` → `track_id`
- `hl_import_run`: CHANGE `cohort_id` → `track_id` (if exists)
- `hl_audit_log`: CHANGE `cohort_id` → `track_id` (if exists)

STEP 4: Update create_tables()
New installs get: hl_cohort (container), hl_track (run), hl_track_school (join), etc.

Bump schema revision. Log every step. Wrap ALL operations in existence checks.

IMPORTANT: hl_track.cohort_id is NULLABLE (tracks can exist without a parent cohort for backward compat).

Commit: "db: migrate cohort hierarchy to track/cohort (Phase C1)"
```

### C2 — Domain Models & Repositories (use Opus)

```
PHASE C2: Cohort → Track + CohortGroup → Cohort — Domain Models & Repositories

Read CLAUDE.md, README.md, docs/RENAME_PLAN.md. Phases A, B, C1 done.

1. RENAME: class-hl-cohort.php → class-hl-track.php, class HL_Cohort → HL_Track
   - Properties: cohort_id → track_id, cohort_code → track_code, cohort_name → track_name
   - ADD: cohort_id (nullable FK to container)
   - Keep: is_control_group, status, start_date, end_date
   - Update to_array() and from_row()

2. RENAME: class-hl-cohort-group.php (if exists) → class-hl-cohort.php, class HL_Cohort_Group → HL_Cohort
   - Properties: group_id → cohort_id, group_name → cohort_name, group_code → cohort_code

3. UPDATE HL_Enrollment: cohort_id → track_id
4. UPDATE ALL other models with cohort_id → track_id

5. REPOSITORIES:
   - HL_Cohort_Repository → HL_Track_Repository (queries hl_track)
   - HL_Cohort_Group_Repository → HL_Cohort_Repository (queries hl_cohort the container)
   - Update ALL other repos: cohort_id → track_id in SQL

Search includes/domain/ for "cohort" — only valid remaining uses should be the new HL_Cohort (container) and the cohort_id FK on HL_Track.

Commit: "refactor: restructure domain models for track/cohort hierarchy (Phase C2)"
```

### C3 — Services Layer (use Opus)

```
PHASE C3: Cohort → Track + CohortGroup → Cohort — Services Layer

Read CLAUDE.md, README.md, docs/RENAME_PLAN.md. Phases A, B, C1-C2 done.

The key: most business logic operates at Track level (was Cohort). Cohort level (was CohortGroup) is for grouping and aggregate reporting.

1. RENAME: HL_Cohort_Service → HL_Track_Service (all operational CRUD)
2. CREATE NEW: HL_Cohort_Service for the container entity (was cohort group service)
   - CRUD for hl_cohort
   - get_tracks_for_cohort()
3. Update ALL services: cohort_id → track_id:
   - HL_Enrollment_Service
   - HL_Team_Service
   - HL_Classroom_Service
   - HL_Assessment_Service
   - HL_Observation_Service
   - HL_Coaching_Service
   - HL_Coach_Assignment_Service
   - HL_Import_Service
   - HL_Pathway_Service
   - HL_Pathway_Assignment_Service
   - HL_Audit_Service
4. HL_Reporting_Service:
   - Track-level = what was cohort-level (per-run reports)
   - Cohort-level = what was group-level (aggregate across tracks)
   - group_summary → cohort_summary, group_comparison → cohort_comparison
5. HL_Scope_Service: cohort_ids → track_ids

After: grep "cohort" includes/services/ — "cohort" should only appear in HL_Cohort_Service (container) and the track.cohort_id FK references.

Commit: "refactor: restructure services for track/cohort hierarchy (Phase C3)"
```

### C4 — Admin Pages (use Opus, parallel with C5/C6)

```
PHASE C4: Cohort → Track + CohortGroup → Cohort — Admin Pages

Read CLAUDE.md, README.md, docs/RENAME_PLAN.md. C1-C3 done.

1. RENAME: class-hl-admin-cohorts.php → class-hl-admin-tracks.php
   - Menu label: "Tracks" (the runs)
   - cohort_id → track_id in forms/queries/URLs
   - "Cohort Group" dropdown → "Cohort" dropdown (track.cohort_id FK)
   - is_control_group stays on Track

2. RENAME: class-hl-admin-cohort-groups.php → class-hl-admin-cohorts.php
   - Menu label: "Cohorts" (the containers)
   - group_id → cohort_id
   - "Linked Cohorts" → "Tracks"

3. Update ALL other admin pages: cohort_id → track_id
   - orgunits, enrollments, pathways, teams, classrooms, instruments, coaching, coach-assignments, reporting, assessments, import

4. Admin menu in hl-core.php: update slugs and labels

grep "cohort" includes/admin/ — verify correct usage.

Commit: "refactor: restructure admin pages for track/cohort hierarchy (Phase C4)"
```

### C5 — Frontend Shortcodes (use Opus, parallel with C4/C6)

```
PHASE C5: Cohort → Track + CohortGroup → Cohort — Frontend Shortcodes

Read CLAUDE.md, README.md, docs/RENAME_PLAN.md. C1-C3 done.

1. RENAME shortcodes and files:
   - [hl_cohort_workspace] → [hl_track_workspace], file → class-hl-frontend-track-workspace.php
   - [hl_cohorts_listing] → [hl_tracks_listing], file → class-hl-frontend-tracks-listing.php
   - [hl_my_cohort] → [hl_my_track], file → class-hl-frontend-my-track.php
   - [hl_cohort_dashboard] → [hl_track_dashboard] (if exists)

2. Update ALL frontend files: cohort_id → track_id in URL params, variables, queries, labels

3. BuddyBoss integration: "My Cohort" → "My Track", "Cohorts" → "Tracks", menu items

4. CSS: .hl-cohort-* → .hl-track-*

5. hl-core.php: shortcode registrations, require_once

6. create-pages: page titles and slugs

grep "cohort" includes/frontend/ — only valid uses: cohort_id FK refs and "Cohort" label for the container.

Commit: "refactor: restructure frontend for track/cohort hierarchy (Phase C5)"
```

### C6 — CLI & REST API (use Sonnet, parallel with C4/C5)

```
PHASE C6: Cohort → Track + CohortGroup → Cohort — CLI & REST API

Read CLAUDE.md, README.md, docs/RENAME_PLAN.md. C1-C3 done.

1. CLI seeders: 
   - "Creating cohort..." → "Creating track..."
   - $cohort_id → $track_id for the run
   - $group_id → $cohort_id for the container
   - cohort_code variable names → track_code
   - All three seeders (demo, palm-beach, lutheran)
   
2. Nuke command: table references
3. Create-pages: page titles for renamed shortcodes
4. REST API: update endpoints and response fields

grep "cohort" includes/cli/ includes/api/ — verify correct usage.

Commit: "refactor: restructure CLI & API for track/cohort hierarchy (Phase C6)"
```

### C7 — Documentation (use Sonnet — FINAL TASK)

```
PHASE C7: Cohort → Track + CohortGroup → Cohort — Documentation (FINAL)

Read docs/RENAME_PLAN.md. ALL code changes (A1-A7, B1-B3, C1-C6) are complete.

This is the final task of the Grand Rename. Update ALL documentation comprehensively.

Mental model:
- **Cohort** = the contract, biggest entity (was CohortGroup)
- **Track** = specific phase/run (was Cohort)

1. docs/01_GLOSSARY_CANONICAL_TERMS.md.md — MAJOR REWRITE:
   - §1.1 Cohort: redefine as the contract/container
   - NEW §1.1b Track: time-bounded run within a Cohort
   - REMOVE §1.4 CohortGroup (absorbed into Cohort)
   - §1.5 Control Group: is_control_group on Track
   - Update all downstream references

2. docs/02_DOMAIN_MODEL_ORG_STRUCTURE.md.md — MAJOR REWRITE:
   - §1.2 Cohort = container. NEW §1.2b Track = run. Remove §1.13 CohortGroup.
   - Enrollment → Track. Pathway → Track. Team → Track.
   - Update relationship diagram, all FKs, constraints
   - Identity keys: track_uuid, track_code, cohort_uuid, cohort_code

3. docs/03 through docs/10 — update all cohort → track where it means the run

4. CLAUDE.md — FULL UPDATE:
   - §6 Terminology: Cohort = container, Track = run
   - Architecture, forms section, SSH examples

5. README.md — COMPREHENSIVE:
   - Overview, DB schema (new table names), domain models
   - Services, admin pages, frontend pages (all shortcode names)
   - CLI commands, REST API
   - Build Queue descriptions (update completed items text)
   - Key Design Decisions
   - Architecture tree
   - Check off ALL of Phase 22 (A1-A7, B1-B3, C1-C7, V1-V3)

Commit: "docs: complete Grand Rename documentation update (Phase C7)"
```

---

## Post-Rename Verification

```
VERIFICATION: Run after all C7 is committed.

1. Grep verification — ALL should return 0 results:
   grep -ri "center_id" includes/
   grep -ri "children_assessment" includes/
   grep -ri "cohort_group" includes/
   grep -ri "hl_cohort_center" includes/
   grep -ri "hl_children_assessment" includes/

   These are OK (should exist):
   grep -ri "school_id" includes/    # expected
   grep -ri "child_assessment" includes/  # expected
   grep -ri "track_id" includes/     # expected

2. If on staging with SSH access:
   cd /home/u665917738/domains/academy.housmanlearning.com/public_html/staging && wp hl-core nuke --confirm="DELETE ALL DATA"
   cd /home/u665917738/domains/academy.housmanlearning.com/public_html/staging && wp hl-core seed-demo
   # Check admin pages load, frontend renders

3. Lutheran test:
   cd /home/u665917738/domains/academy.housmanlearning.com/public_html/staging && wp hl-core seed-lutheran
   # Verify control group workflow
```
