# Grand Rename V3 — Corrective Rename Plan

## Context

Grand Rename V2 mapped entities incorrectly:
- It renamed Track → Partnership (should have been Track → Cycle)
- It renamed Phase → Cycle (Phase should have been deleted entirely)
- It kept Cohort (should have been renamed to Partnership)

The result: the code's "Partnership" is actually the yearly program run (should be "Cycle"), and the code's "Cohort" is the big container (should be "Partnership"). The data model works correctly — enrollments, teams, etc. are attached to the right level — but everything is named wrong.

**V3 corrects this by swapping the names:**
- `hl_cohort` → `hl_partnership` (the big container)
- `hl_partnership` → `hl_cycle` (the yearly run)
- `hl_cycle` (Phase entity) → DELETED

**Safety:** Tag `pre-rename-v3` exists at commit `8185337`. Rollback: `git reset --hard pre-rename-v3`.

---

## Target State

```
hl_partnership (partnership_id, partnership_name, partnership_code, description, status)
  └── hl_cycle (cycle_id, partnership_id FK, cycle_name, cycle_code, cycle_type, ...)
        ├── hl_enrollment (cycle_id FK)
        ├── hl_team (cycle_id FK)
        ├── hl_pathway (cycle_id FK)
        ├── hl_component (cycle_id FK)
        ├── hl_cycle_school (cycle_id FK)
        ├── hl_child_cycle_snapshot (cycle_id FK)
        ├── hl_cycle_email_log (cycle_id FK)
        ├── hl_completion_rollup (cycle_id FK, cycle_completion_percent)
        ├── hl_teacher_assessment_instance (cycle_id FK)
        ├── hl_child_assessment_instance (cycle_id FK)
        ├── hl_observation (cycle_id FK)
        ├── hl_coaching_session (cycle_id FK)
        ├── hl_coach_assignment (cycle_id FK)
        ├── hl_import_run (cycle_id FK nullable)
        └── hl_audit_log (cycle_id FK nullable)
```

No `hl_cohort` table. No old `hl_cycle` (Phase) table.

---

## Phase D1: DB Migration

**File:** `includes/class-hl-installer.php`
**Commit:** `db: Phase D1 — migrate V3 grand rename (cohort↔partnership swap)`

Add `migrate_v3_grand_rename()` method. Uses temp table names to avoid collisions.

### Step 0 — Delete old Cycle (Phase) entity table
- Drop `cycle_id` column from `hl_pathway` (redundant — `partnership_id` already scopes pathways to the yearly run)
- Drop `cycle_id` column from `hl_partnership_email_log`
- Drop old `hl_cycle` table (or rename to `hl_cycle_v3_old` for safety)
- Guard: only if `hl_cycle` has `cycle_number` column (signature of Phase entity)

### Step 1 — Park `hl_partnership` → temp name
- `RENAME TABLE hl_partnership TO hl_cycle_v3_temp`
- `RENAME TABLE hl_partnership_school TO hl_cycle_school_v3_temp`
- `RENAME TABLE hl_child_partnership_snapshot TO hl_child_cycle_snapshot_v3_temp`
- `RENAME TABLE hl_partnership_email_log TO hl_cycle_email_log_v3_temp`
- Guard: `hl_partnership` exists AND temp doesn't

### Step 2 — Promote `hl_cohort` → `hl_partnership`
- `RENAME TABLE hl_cohort TO hl_partnership`
- CHANGE `cohort_id` → `partnership_id` (PK)
- CHANGE `cohort_uuid` → `partnership_uuid`
- CHANGE `cohort_name` → `partnership_name`
- CHANGE `cohort_code` → `partnership_code`
- Drop old indexes (`cohort_uuid`, `cohort_code`)
- Guard: `hl_cohort` exists AND `hl_partnership` doesn't

### Step 3 — Land temp → `hl_cycle`
- `RENAME TABLE hl_cycle_v3_temp TO hl_cycle`
- CHANGE `partnership_id` → `cycle_id` (PK)
- CHANGE `partnership_uuid` → `cycle_uuid`
- CHANGE `partnership_name` → `cycle_name`
- CHANGE `partnership_code` → `cycle_code`
- CHANGE `partnership_type` → `cycle_type`
- CHANGE `cohort_id` → `partnership_id` (FK — already has correct ID values since old cohort_id values now match the new hl_partnership.partnership_id)
- Drop old indexes (`partnership_uuid`, `partnership_code`)
- Guard: temp exists AND `hl_cycle` doesn't

### Step 4 — Land subsidiary temp tables
- `hl_cycle_school_v3_temp` → `hl_cycle_school` (CHANGE `partnership_id` → `cycle_id`)
- `hl_child_cycle_snapshot_v3_temp` → `hl_child_cycle_snapshot` (CHANGE `partnership_id` → `cycle_id`)
- `hl_cycle_email_log_v3_temp` → `hl_cycle_email_log` (CHANGE `partnership_id` → `cycle_id`)

### Step 5 — Rename FK columns in all dependent tables
All tables with `partnership_id` → `cycle_id`:

| Table | Nullable | Extra column renames |
|-------|----------|---------------------|
| `hl_enrollment` | NOT NULL | — |
| `hl_team` | NOT NULL | — |
| `hl_pathway` | NOT NULL | — |
| `hl_component` | NOT NULL | — |
| `hl_completion_rollup` | NOT NULL | `partnership_completion_percent` → `cycle_completion_percent` |
| `hl_teacher_assessment_instance` | NOT NULL | — |
| `hl_child_assessment_instance` | NOT NULL | — |
| `hl_observation` | NOT NULL | — |
| `hl_coaching_session` | NOT NULL | — |
| `hl_coach_assignment` | NOT NULL | — |
| `hl_import_run` | NULL | — |
| `hl_audit_log` | NULL | — |

### Step 6 — Drop old composite indexes
Drop indexes with `partnership_*` names (dbDelta recreates them from get_schema):
- `hl_enrollment`: `partnership_user`
- `hl_teacher_assessment_instance`: `partnership_enrollment_phase`
- `hl_child_assessment_instance`: `partnership_enrollment_classroom_phase`
- `hl_coach_assignment`: `partnership_scope`, `partnership_coach`
- `hl_cycle`: `partnership_cycle_number` (from old Phase entity migration)

### Also in D1:
- Update `get_schema()` — all CREATE TABLE statements use final names
- Bump schema revision 20 → 21
- Add `self::migrate_v3_grand_rename()` call in `create_tables()`
- Update `migrate_add_phase_entity()` guards (check for `cycle_id` column too)

---

## Phase D2: Delete old Cycle (Phase) entity PHP code

**Commit:** `refactor: Phase D2 — delete old Cycle (Phase) entity`

### Delete files:
- `includes/domain/class-hl-cycle.php`
- `includes/domain/repositories/class-hl-cycle-repository.php`
- `includes/services/class-hl-cycle-service.php`

### Remove from hl-core.php:
- 3 require_once lines (domain model, repository, service)

### Gut from class-hl-admin-partnerships.php:
- Delete `render_tab_cycles()` method
- Delete `render_cycles_list()` method
- Delete `render_cycle_form()` method
- Delete `handle_save_cycle()` method
- Delete `handle_delete_cycle()` method
- Remove `'cycles'` from tabs array
- Remove `case 'cycles':` from tab switch

### Remove from other files:
- `class-hl-admin-pathways.php`: Remove cycle dropdown from pathway form, remove `$data['cycle_id']` handling
- `class-hl-pathway-service.php`: Remove cycle auto-resolution logic (lines ~37-56 that use HL_Cycle_Repository)
- `class-hl-pathway.php`: Remove `$cycle_id` property
- `class-hl-pathway-repository.php`: Remove `cycle_id` parameter from `get_all()`, remove `get_by_cycle()` method
- `class-hl-frontend-my-programs.php`: Remove cycle name display logic (lines ~146-153)
- `class-hl-frontend-pathways-listing.php`: Remove SQL JOIN with hl_cycle, remove cycle_name display
- `class-hl-rest-api.php`: Remove `cycle_id` query param from pathways endpoint
- All 4 CLI seeders: Remove `seed_cycle()` / `provision_cycle()` methods and calls

### Verification:
```
grep -rn "HL_Cycle\|hl_cycle\|cycle_id\|cycle_name\|cycle_number" includes/ hl-core.php --include="*.php" | grep -v "class-hl-installer.php\|RENAME_V2"
```
Should return 0 hits (installer migrations are expected to reference old names).

---

## Phase D3: Rename Partnership → Cycle (the big rename)

**Commit:** `refactor: Phase D3 — partnership→cycle across all PHP`

This is the largest phase (~50+ files, ~1500 references). Same mechanical process as V2.

### File renames (git mv):
| Old path | New path |
|----------|----------|
| `includes/domain/class-hl-partnership.php` | `includes/domain/class-hl-cycle.php` |
| `includes/domain/repositories/class-hl-partnership-repository.php` | `includes/domain/repositories/class-hl-cycle-repository.php` |
| `includes/services/class-hl-partnership-service.php` | `includes/services/class-hl-cycle-service.php` |
| `includes/admin/class-hl-admin-partnerships.php` | `includes/admin/class-hl-admin-cycles.php` |
| `includes/frontend/class-hl-frontend-partnerships-listing.php` | `includes/frontend/class-hl-frontend-cycles-listing.php` |
| `includes/frontend/class-hl-frontend-my-partnership.php` | `includes/frontend/class-hl-frontend-my-cycle.php` |
| `includes/frontend/class-hl-frontend-partnership-workspace.php` | `includes/frontend/class-hl-frontend-cycle-workspace.php` |

### Class renames:
- `HL_Partnership` → `HL_Cycle`
- `HL_Partnership_Repository` → `HL_Cycle_Repository`
- `HL_Partnership_Service` → `HL_Cycle_Service`
- `HL_Admin_Partnerships` → `HL_Admin_Cycles`
- `HL_Frontend_Partnerships_Listing` → `HL_Frontend_Cycles_Listing`
- `HL_Frontend_My_Partnership` → `HL_Frontend_My_Cycle`
- `HL_Frontend_Partnership_Workspace` → `HL_Frontend_Cycle_Workspace`

### Property/column renames (all files):
- `partnership_id` → `cycle_id`
- `partnership_uuid` → `cycle_uuid`
- `partnership_name` → `cycle_name`
- `partnership_code` → `cycle_code`
- `partnership_type` → `cycle_type`
- `partnership_completion_percent` → `cycle_completion_percent`
- `$partnership` → `$cycle` (entity variable)
- `$partnerships` → `$cycles`
- `$partnership_id` → `$cycle_id`

### Table name renames in SQL:
- `hl_partnership` → `hl_cycle`
- `hl_partnership_school` → `hl_cycle_school`
- `hl_child_partnership_snapshot` → `hl_child_cycle_snapshot`
- `hl_partnership_email_log` → `hl_cycle_email_log`

### CSS classes:
- `hl-partnership-*` → `hl-cycle-*`

### URL params / form fields / nonces:
- `page=hl-partnerships` → `page=hl-cycles`
- `partnership_id` form fields → `cycle_id`
- Nonce names with `partnership` → `cycle`

### Shortcodes:
- `[hl_partnerships_listing]` → `[hl_cycles_listing]`
- `[hl_my_partnership]` → `[hl_my_cycle]`
- `[hl_partnership_workspace]` → `[hl_cycle_workspace]`

### CRITICAL EXCEPTIONS — do NOT rename:
- `cohort_id` column on the current HL_Partnership model → this becomes `partnership_id` FK (handled in D4)
- Assessment `phase` column (pre/post) — still stays as `phase`
- Migration code in class-hl-installer.php — references old names deliberately

### Files affected (heaviest):
1. `class-hl-admin-partnerships.php` → `class-hl-admin-cycles.php` (2,494 lines, 156 refs)
2. `class-hl-reporting-service.php` (83 refs)
3. `class-hl-admin-reporting.php` (44 refs)
4. `class-hl-assessment-service.php` (59 refs)
5. `class-hl-scope-service.php` (method: `can_view_partnership()` → `can_view_cycle()`)
6. All frontend page classes (~15 files)
7. All CLI seeders (~5 files)
8. BuddyBoss integration (menu items)
9. REST API
10. hl-core.php (require paths)

### Verification:
```
grep -rn "HL_Partnership\|hl_partnership\|\bpartnership_id\b\|partnership_name\|partnership_code\|partnership_type\|partnership_uuid" includes/ hl-core.php --include="*.php" | grep -v "class-hl-installer.php\|RENAME_V2\|RENAME_V3"
```
Should return 0 hits outside migration code.

---

## Phase D4: Rename Cohort → Partnership + create new domain model

**Commit:** `refactor: Phase D4 — cohort→partnership + new Partnership domain model`

### File renames:
| Old path | New path |
|----------|----------|
| `includes/services/class-hl-cohort-service.php` | `includes/services/class-hl-partnership-service.php` |
| `includes/admin/class-hl-admin-cohorts.php` | `includes/admin/class-hl-admin-partnerships.php` |

### New files to create:
- `includes/domain/class-hl-partnership.php` — simple model: `partnership_id`, `partnership_uuid`, `partnership_name`, `partnership_code`, `description`, `status`, `created_at`, `updated_at`
- `includes/domain/repositories/class-hl-partnership-repository.php` — CRUD: `get_all()`, `get_by_id()`, `create()`, `update()`, `delete()`

### Class renames:
- `HL_Cohort_Service` → `HL_Partnership_Service`
- `HL_Admin_Cohorts` → `HL_Admin_Partnerships`

### Internal renames in all affected files:
- `cohort_id` → `partnership_id`
- `cohort_uuid` → `partnership_uuid`
- `cohort_name` → `partnership_name`
- `cohort_code` → `partnership_code`
- `$cohort` → `$partnership`
- `$cohorts` → `$partnerships`
- `hl_cohort` → `hl_partnership` (SQL table)
- `page=hl-cohorts` → `page=hl-partnerships`

### Also update:
- The new `class-hl-admin-cycles.php` (from D3): the FK dropdown that assigns a cycle to a partnership now references `HL_Partnership_Repository` instead of `HL_Cohort_Service`
- `class-hl-frontend-cycles-listing.php` (from D3): cohort grouping data-attributes → partnership
- `class-hl-reporting-service.php`: cohort name lookups → partnership
- `class-hl-admin-reporting.php`: cohort filter → partnership filter
- `hl-core.php`: update require paths, update menu registration
- BuddyBoss integration: if any cohort menu items exist

### Admin menu:
- Unhide the Partnerships page (currently hidden with `add_submenu_page(null, ...)`)
- Register `hl-partnerships` as a visible submenu under HL Core
- `hl-cycles` becomes the primary operational page

### Verification:
```
grep -rn "HL_Cohort\|hl_cohort\|\bcohort_id\b\|cohort_name\|cohort_code\|cohort_uuid" includes/ hl-core.php --include="*.php" | grep -v "class-hl-installer.php\|RENAME_V2\|RENAME_V3"
```
Should return 0 hits outside migration code.

---

## Phase D5: CLI, API, integrations cleanup

**Commit:** `refactor: Phase D5 — CLI/API/integrations V3 cleanup`

### CLI seeders (4 files):
- `class-hl-cli-seed-demo.php`
- `class-hl-cli-seed-palm-beach.php`
- `class-hl-cli-seed-lutheran.php`
- `class-hl-cli-provision-lutheran.php`

Changes: Seeders currently create "cohort" records → create "partnership" records instead. Current "partnership" creation → "cycle" creation. Delete any `seed_cycle()` remnants (Phase entity creation — should already be gone from D2).

### Standalone CLI scripts:
- `provision-test-users.php`, `provision-test-teachers.php`, `send-test-emails*.php`

### REST API (`class-hl-rest-api.php`):
- Rename `/partnerships` endpoint → `/cycles`
- Add `/partnerships` endpoint for the container entity if needed
- Update field names in responses

### BuddyBoss integration:
- Menu items: "My Partnership" → "My Cycle", "Partnerships" → "Cycles"
- Or keep user-facing labels as-is if client prefers (check with user)

### JFB integration (`class-hl-jfb-integration.php`):
- Line 93: `hl_track_id` hidden field → should be `hl_cycle_id`
- Line 148: `track_id` audit log key → `cycle_id`
- Line 184: SQL `WHERE track_id = %d` → `WHERE cycle_id = %d`
- (Note: this file also has V2 bugs — `track_id` refs that weren't caught)

### Nuke command (`class-hl-cli-nuke.php`):
- Table references if any

---

## Phase D6: Docs, hl-core.php final, Label Remap, cleanup

**Commit:** `docs: Phase D6 — update all docs + cleanup for V3`

### hl-core.php:
- All require_once paths finalized
- Menu slug registrations
- Shortcode registrations
- Plugin description

### CLAUDE.md:
- Terminology section: Partnership = big container, Cycle = yearly run
- Remove any remaining Cohort references
- Remove Label Remap references (or mark for deletion)

### architecture.md (.claude/skills/):
- Full rewrite of entity hierarchy
- Update all table/class/column references
- Update admin pages descriptions
- Update directory tree

### STATUS.md:
- Update Phase 34 description (uses old terms)
- Add note about V3 completion

### README.md:
- Update all entity references
- Update file tree

### RENAME_V3_LOG.md:
- Create and append entries for each phase

### Label Remap:
- After V3, code uses "Partnership" for container and "Cycle" for yearly run
- If client wants UI to show "Partnership" and "Cycle", the remap is identity → can be deleted
- Decision: flag for removal or delete in this phase

---

## Execution Notes

### Recommended session strategy:
- Execute D1 first (DB migration) — commit and test
- Execute D2 (delete Phase entity) — commit
- Execute D3 (big rename: Partnership→Cycle) — this is the largest phase, may use parallel agents
- Execute D4 (Cohort→Partnership) — commit
- Execute D5 + D6 together — commit

### Name collision handling:
Phases are ordered so file names freed in an earlier phase are reused in a later phase:
- D2 deletes `class-hl-cycle.php` → frees the name
- D3 renames `class-hl-partnership.php` → `class-hl-cycle.php` (uses freed name)
- D3 frees `class-hl-partnership.php` name
- D4 creates new `class-hl-partnership.php` (uses freed name)

### Scope estimate:
- ~60 PHP files modified
- ~1,800 references renamed
- 4+ tables renamed, 1 table deleted, 12+ FK columns renamed
- 7 PHP files renamed, 3 deleted, 2 created
