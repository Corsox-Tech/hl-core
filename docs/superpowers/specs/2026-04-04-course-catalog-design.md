# Course Catalog Module — Design Spec

**Date:** 2026-04-04
**Status:** Approved (Rev 3 — post peer review rounds 1 & 2)
**Author:** Claude (with user review)

## Problem Statement

HL Core's B2E Mastery Program has courses in English and Spanish (Portuguese coming soon). Spanish courses are separate LearnDash posts with different IDs from their English equivalents. The current system has no knowledge of language equivalence:

- The pathway routing service hardcodes English-only course IDs for stage completion checks
- Components point to a single LD course via `external_ref` JSON
- A user who completes the Spanish version of TC1 gets no credit for TC1 completion in HL Core
- Reports show duplicate rows (one per language variant)
- Pathways would need to be duplicated per language without this feature

**Real-world impact:** Olga Acosta Rios (user 321) completed all Spanish B2E courses but the routing service couldn't detect her completion because it only checks English course IDs. She was left without a pathway assignment.

## Solution Overview

Introduce a **Course Catalog** — a central registry of "logical courses" that maps one canonical entry to its language-specific LearnDash course variants. All HL Core subsystems (routing, LD integration, components, reporting, frontend) reference catalog entries instead of raw LD course IDs.

**Core principle:** One pathway, one set of components, one completion state per component — regardless of which language variant the user completes.

## 1. New Table: `hl_course_catalog`

```sql
CREATE TABLE {prefix}hl_course_catalog (
    catalog_id      bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    catalog_uuid    char(36) NOT NULL,
    catalog_code    varchar(50) NOT NULL COMMENT 'Stable lookup key e.g. TC1, MC3, TC1_S',
    title           varchar(255) NOT NULL COMMENT 'Always English course name',
    ld_course_en    bigint(20) unsigned NULL COMMENT 'English LD course post ID',
    ld_course_es    bigint(20) unsigned NULL COMMENT 'Spanish LD course post ID',
    ld_course_pt    bigint(20) unsigned NULL COMMENT 'Portuguese LD course post ID',
    status          enum('active','archived') NOT NULL DEFAULT 'active',
    created_at      datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (catalog_id),
    UNIQUE KEY catalog_uuid (catalog_uuid),
    UNIQUE KEY catalog_code (catalog_code),
    UNIQUE KEY ld_course_en (ld_course_en),
    UNIQUE KEY ld_course_es (ld_course_es),
    UNIQUE KEY ld_course_pt (ld_course_pt),
    KEY status (status)
) $charset_collate;
```

### Key design decisions

- **`catalog_code`**: A stable, human-readable key (e.g., `'TC1'`, `'MC3'`, `'TC1_S'`). Used by the routing service `$stages` array instead of auto-increment `catalog_id`. This avoids ID-mismatch issues between environments.
- **`title`**: Always the English course name. English is the canonical language.
- **`ld_course_en`**: Required when creating an entry (enforced in PHP AND as a practical requirement — the spec rule: "There will not be courses in other languages but not in English"). Nullable at DB level to allow staged migration.
- **`ld_course_es`, `ld_course_pt`**: Nullable — not all courses have translations.
- **UNIQUE constraints on `ld_course_*` columns**: A LD course ID can belong to at most ONE catalog entry. MySQL UNIQUE indexes ignore NULL, so multiple NULLs don't conflict. This prevents duplicate mappings at the DB level AND serves as indexes for the reverse-lookup query in `on_course_completed()`.
- **`status = 'archived'`**: Soft-deletes entries. Archived entries still participate in completion tracking (not filtered in `on_course_completed()` or routing). Archiving only hides entries from the admin "Add Component" catalog dropdown.
- **Column-per-language trade-off**: This is a deliberate choice for simplicity with 3 known languages. Adding a 4th language requires an ALTER TABLE + code updates. If language count grows beyond 3, migrate to a junction table `hl_course_catalog_variant(catalog_id, lang_code, ld_course_id)`.

## 2. Schema Changes to Existing Tables

### `hl_component` — add `catalog_id` column

```sql
ALTER TABLE {prefix}hl_component
    ADD COLUMN catalog_id bigint(20) unsigned NULL AFTER external_ref,
    ADD KEY catalog_id (catalog_id);
```

- For `learndash_course` type components: `catalog_id` is set, pointing to the catalog entry.
- For non-LD components (assessments, coaching, etc.): `catalog_id` stays NULL. `external_ref` continues to work as-is for these types.
- `external_ref` is kept for backward compatibility during migration. For new `learndash_course` components, `catalog_id` is the source of truth.
- When creating/editing a `learndash_course` component in a pathway, the admin selects a catalog entry from a dropdown instead of entering a raw course ID.

### `hl_enrollment` — add `language_preference` column

```sql
ALTER TABLE {prefix}hl_enrollment
    ADD COLUMN language_preference varchar(5) NOT NULL DEFAULT 'en' AFTER status;
```

- Valid values: `'en'`, `'es'`, `'pt'`.
- Set during import (new optional CSV column `language`, defaults to `'en'`).
- Editable via the admin enrollment edit form.
- Used to resolve which LD course link to display to the user on frontend pages.
- Can be changed at any time without affecting existing completion data.
- Completion tracking is language-agnostic — this field only affects display.

## 3. Routing Service Changes

File: `includes/services/class-hl-pathway-routing-service.php`

### Current state

The `$stages` array hardcodes English-only LD course IDs:

```php
'C' => array(
    'label'      => 'Teacher Stage 1',
    'course_ids' => array(30280, 30284, 30286, 30288),
),
```

### New state

`$stages` references **catalog codes** (not auto-increment IDs). Full new `$stages` array:

```php
private static $stages = array(
    'A' => array(
        'label'         => 'Mentor Stage 1',
        'catalog_codes' => array('MC1', 'MC2'),
    ),
    'B' => array(
        'label'         => 'Mentor Stage 2',
        'catalog_codes' => array('MC3', 'MC4'),
    ),
    'C' => array(
        'label'         => 'Teacher Stage 1',
        'catalog_codes' => array('TC1', 'TC2', 'TC3', 'TC4'),
    ),
    'D' => array(
        'label'         => 'Teacher Stage 2',
        'catalog_codes' => array('TC5', 'TC6', 'TC7', 'TC8'),
    ),
    'E' => array(
        'label'         => 'Streamlined Stage 1',
        'catalog_codes' => array('TC0', 'TC1_S', 'TC2_S', 'TC3_S', 'TC4_S', 'MC1_S', 'MC2_S'),
    ),
);
```

`get_completed_stages()` changes to:

1. Load all catalog entries into a static cache (one query per request, ~25 rows).
2. For each stage, resolve `catalog_codes` to catalog entries via the cache. If any code is not found, log `[HL Routing] catalog_code 'XX' not found in catalog` and treat that entry as incomplete.
3. For each catalog entry, collect all non-null language course IDs (`ld_course_en`, `ld_course_es`, `ld_course_pt`).
4. Check if the user has completed **ANY language variant** of that catalog entry in LearnDash.
5. A stage is complete when ALL catalog entries in the stage have at least one variant completed.

**Health check:** `is_catalog_ready()` verifies (a) catalog table is not empty, (b) all catalog codes referenced in `$stages` exist in the table. Surfaced on the admin dashboard and available via WP-CLI.

**Guard for empty catalog:** If the catalog table is empty (fresh install, failed migration), log `error_log('[HL Routing] Course catalog is empty — stage completion cannot be evaluated')` and return empty stages. The routing service exposes `is_catalog_ready()` for admin dashboard health checks.

### New method: `is_catalog_entry_completed()`

```php
public static function is_catalog_entry_completed($user_id, $catalog_entry)
```

Accepts a catalog row object. Checks `ld_course_en`, `ld_course_es`, `ld_course_pt` (skipping NULLs). Returns `true` if ANY variant is completed in LearnDash.

The existing `is_course_completed($user_id, $course_id)` remains for backward compatibility.

### Mixed-language completion

A user who completes Spanish TC1, English TC2, Spanish TC3, English TC4 has completed ALL four catalog entries for Stage C. The stage is marked complete. This works automatically because each catalog entry is checked independently.

## 4. LearnDash Integration Changes

File: `includes/integrations/class-hl-learndash-integration.php`

### `on_course_completed()` changes

Current behavior (line 117-126): Matches the completed `$course_id` against `external_ref.course_id` in components.

New behavior — **catalog path first, fallback second, mutually exclusive:**

1. When `learndash_course_completed` fires for LD course ID X:
2. **Guard:** If the catalog table does not exist (plugin just activated, `dbDelta` hasn't run), skip directly to fallback path. Check via `$wpdb->get_var("SHOW TABLES LIKE '{prefix}hl_course_catalog'")` (cached per-request).
3. **Catalog path**: Query `SELECT catalog_id FROM hl_course_catalog WHERE ld_course_en = X OR ld_course_es = X OR ld_course_pt = X LIMIT 1` (uses the UNIQUE indexes).
4. If a catalog entry is found, query: `SELECT component_id, cycle_id, pathway_id FROM hl_component WHERE catalog_id = {catalog_id} AND component_type = 'learndash_course' AND status = 'active' AND cycle_id IN ({user's active enrollment cycle_ids})`
5. For each matching component, upsert `hl_component_state`.
6. **If catalog path matched one or more components: STOP. Do not run fallback.**
7. **Fallback path** (only if catalog path matched ZERO components): Fall back to the existing `external_ref.course_id` matching for backward compatibility during migration.
8. Trigger rollup recomputation for all affected enrollments.

**Fallback removal:** After migration is verified complete (all `learndash_course` components have non-null `catalog_id`), set `wp_option('hl_catalog_migration_complete', true)`. When this flag is set, skip the fallback path entirely. Document this in the migration verification checklist.

**Key behavior:** If a user completes Spanish TC1, the component "TC1: Intro to ECSEL" is marked complete. If they later complete English TC1, nothing changes — the component is already complete. Completion is language-agnostic at the component state level.

## 5. Import Module Changes

File: `includes/services/class-hl-import-participant-handler.php`

### New CSV column: `language`

- Optional column, defaults to `'en'` if absent.
- Valid values: `en`, `es`, `pt`.
- Sets `language_preference` on the enrollment record.
- Validation: warn if unrecognized value (not a blocking error).
- The `language` column does NOT affect routing. Routing is based on LD completion history, which is language-agnostic via the catalog.

### Re-import behavior

If re-importing a user who already has an enrollment and the CSV `language` value differs from the current `language_preference`:
- Add `language_preference` to the UPDATE diff-check alongside role and school.
- Show as a proposed change in the preview: "Language: en -> es".
- Allow overwrite since language preference is non-destructive (completion is language-agnostic).

### Pathway routing during import

No change needed to the routing logic itself. The routing service determines pathway by role + completed stages. The catalog-aware `get_completed_stages()` makes this language-aware automatically.

## 6. Admin UI: Course Catalog Page

File: `includes/admin/class-hl-admin-course-catalog.php` (new)

### Architecture

- **Singleton** instance pattern (matches `HL_Admin_Partnerships`, `HL_Admin_Pathways`).
- **`handle_early_actions()`** method for POST processing before render.
- **Nonce verification**: `hl_course_catalog_nonce` on all form submissions.
- **Capability check**: `manage_hl_core` required for all actions.
- **Audit logging**: `HL_Audit_Service::log('course_catalog.created', ...)`, `.updated`, `.archived` on all mutations.

### Menu placement

New sub-menu item under HL Core: **"Course Catalog"**.

### List view

- Table columns: Title | Code | EN Course | ES Course | PT Course | Status
- Course columns display the LD course post title (fetched from `wp_posts`) with the post ID in parentheses.
- Empty language slots show "—".
- Row actions: Edit | Archive.
- "Add New Course" button at top.
- Filter by status (active/archived).

### Add/Edit form

- **Title** — text field, required. Auto-fills from the English course title if left blank.
- **Code** — text field, required. Short slug (e.g., `TC1`, `MC3`, `TC1_S`). Must be unique.
- **English Course** — searchable AJAX dropdown of LD courses (`post_type = sfwd-courses`), required.
- **Spanish Course** — same dropdown, optional.
- **Portuguese Course** — same dropdown, optional.
- **Status** — active/archived toggle.

### Validation

- A LD course ID can only belong to ONE catalog entry. Show error if duplicate detected.
- English course is always required.
- Code is required, unique, and must match `^[A-Z0-9_]+$`.
- Title is required and must be non-empty.

### Archive protection

When archiving, count active components referencing the `catalog_id`. If > 0, show a confirmation warning: "This catalog entry is referenced by X active components. Are you sure?" Allow override, log in audit.

### AJAX endpoint

`wp_ajax_hl_search_ld_courses` — searches `wp_posts` where `post_type = 'sfwd-courses'` and `post_title LIKE %search%`. Returns `[{id, title}]`. Used by the searchable dropdowns. Minimum 2 characters, max 20 results. Returns `{success: false, message: '...'}` on error. Uses the existing vanilla JS AJAX search pattern from `class-hl-admin-enrollments.php` (debounced fetch, dropdown results div) — do NOT introduce Select2 or other dependencies.

## 7. Reporting Impact

### Current behavior

Reports query components and show `component.title`.

### New behavior

- For components with `catalog_id IS NOT NULL`: reports display `hl_course_catalog.title` (always the English name). Catalog titles are live references (current value), not snapshotted at completion time. This is intentional — catalog titles are canonical and should not diverge.
- For components with `catalog_id IS NULL` (assessments, coaching, etc.): reports use `component.title` as before.
- One catalog entry = one row in reports, regardless of how many language variants exist.
- A user who completed Spanish TC1 shows the same "TC1: Intro to begin to ECSEL — Complete" row as someone who completed English TC1.

### Future enhancement (not v1)

A "Language completed in" indicator column showing which variant the user actually took. Not required for initial release.

## 8. Pathway Component Creation Workflow

### New workflow for `learndash_course` type

1. Admin sees a searchable dropdown of **active** catalog entries (archived entries hidden).
2. Dropdown displays language badges: `"TC1: Intro to begin to ECSEL [EN] [ES]"` vs `"MC4: Extending RP to Families [EN]"`.
3. Selecting a catalog entry sets `catalog_id` on the component.
4. The component's `title` auto-fills from the catalog entry's title.
5. The `external_ref` field is hidden for `learndash_course` type — `catalog_id` is the source of truth.
6. For non-LD component types, the form remains unchanged.

## 9. Frontend Course Link & Progress Resolution

### Affected frontend files

- `includes/frontend/class-hl-frontend-program-page.php` — pathway view with course links + progress bars
- `includes/frontend/class-hl-frontend-my-progress.php` — user progress dashboard
- `includes/frontend/class-hl-frontend-component-page.php` — individual component view (includes `wp_redirect` to course URL)

All currently use `$component->get_external_ref_array()['course_id']` then `get_permalink($course_id)`. Approximately ~11 call sites across the three files.

### New helper: `resolve_ld_course_id($component, $enrollment)`

Centralized resolution function (can live on `HL_Course_Catalog` domain model or as a static helper):

1. If `$component->catalog_id` is set, load the catalog entry.
2. Read `$enrollment->language_preference`.
3. Return the course ID for that language, with fallback to `ld_course_en` if the preferred variant is NULL.
4. If `catalog_id` is NULL, fall back to `$component->get_external_ref_array()['course_id']`.

### Course link resolution

Replace `get_permalink($course_id)` calls with `get_permalink(resolve_ld_course_id($component, $enrollment))`.

### Live progress resolution

Replace `get_course_progress_percent($user_id, $course_id)` calls with the resolved language-specific course ID. A Spanish user sees progress for the Spanish course; an English user sees English course progress.

## 10. Migration & Backfill Strategy

All migration runs as a single schema revision in `HL_Installer::maybe_upgrade()`.

### Step 1: Create `hl_course_catalog` table

Standard `dbDelta()` in the table creation array.

### Step 2: Seed catalog entries

Each entry is seeded using `INSERT IGNORE` keyed on `catalog_code` (UNIQUE). This is per-entry idempotent — re-running adds missing entries without affecting existing ones. Safe if admin has manually added entries.

Insert with **explicit `catalog_code`** values (the stable keys used by routing):

**Mastery courses (13 entries):**

| catalog_code | title | ld_course_en | ld_course_es |
|---|---|---|---|
| TC0 | TC0: Welcome to begin to ECSEL | 31037 | 31039 |
| TC1 | TC1: Intro to begin to ECSEL | 30280 | 30304 |
| TC2 | TC2: Your Own Emotionality | 30284 | 30307 |
| TC3 | TC3: Getting to Know Emotion | 30286 | 30309 |
| TC4 | TC4: Emotion in the Heat of the Moment | 30288 | 30312 |
| TC5 | TC5: Connecting Emotion and Early Learning | 39724 | 39736 |
| TC6 | TC6: Empathy, Acceptance & Prosocial Behaviors | 39726 | 39738 |
| TC7 | TC7: begin to ECSEL Tools | 39728 | 39740 |
| TC8 | TC8: ECSEL in the Everyday Classroom | 39730 | 39742 |
| MC1 | MC1: Introduction to Reflective Practice | 30293 | 30364 |
| MC2 | MC2: A Deeper Dive into Reflective Practice | 30295 | 31537 |
| MC3 | MC3: Extending RP to Co-Workers | 39732 | 39254 |
| MC4 | MC4: Extending RP to Families | 39734 | 39488 |

**Streamlined courses (12 entries):** *(Need to confirm Spanish equivalents with Yuyan — seed EN only for now, ES populated later via admin UI.)*

| catalog_code | title | ld_course_en | ld_course_es |
|---|---|---|---|
| TC1_S | TC1: Intro to begin to ECSEL (Streamlined) | 31332 | NULL |
| TC2_S | TC2: Your Own Emotionality (Streamlined) | 31333 | NULL |
| TC3_S | TC3: Getting to Know Emotion (Streamlined) | 31334 | NULL |
| TC4_S | TC4: Emotion in the Heat of the Moment (Streamlined) | 31335 | NULL |
| TC5_S | TC5: Connecting Emotion and Early Learning (Streamlined) | 31336 | NULL |
| TC6_S | TC6: Empathy, Acceptance & Prosocial Behaviors (Streamlined) | 31337 | NULL |
| TC7_S | TC7: begin to ECSEL Tools (Streamlined) | 31338 | NULL |
| TC8_S | TC8: ECSEL in the Everyday Classroom (Streamlined) | 31339 | NULL |
| MC1_S | MC1: Introduction to Reflective Practice (Streamlined) | 31387 | NULL |
| MC2_S | MC2: A Deeper Dive into Reflective Practice (Streamlined) | 31388 | NULL |
| MC3_S | MC3: Extending RP to Co-Workers (Streamlined) | 31389 | NULL |
| MC4_S | MC4: Extending RP to Families (Streamlined) | 31390 | NULL |

All `ld_course_pt` columns are NULL (Portuguese courses don't exist yet).

### Step 3: Add `catalog_id` column to `hl_component`

New migration method `migrate_add_component_catalog_id()` guarded by schema revision (rev 29+). Check column existence via `SHOW COLUMNS FROM hl_component LIKE 'catalog_id'` before running `ALTER TABLE ... ADD COLUMN`. Follows existing pattern from `migrate_coaching_scheduling_columns()` etc. Do NOT use `ADD COLUMN IF NOT EXISTS` (MySQL 5.7 incompatible).

### Step 4: Backfill existing components

For each `learndash_course` component **where `catalog_id IS NULL`** (idempotent):

1. Parse `external_ref` JSON to get `course_id`.
2. Match against catalog entries: `SELECT catalog_id FROM hl_course_catalog WHERE ld_course_en = %d OR ld_course_es = %d`.
3. If matched, `UPDATE hl_component SET catalog_id = %d WHERE component_id = %d AND catalog_id IS NULL`.
4. If no match found, log warning: `[HL Migration] Component {id} has course_id {X} not found in catalog`.

### Step 5: Add `language_preference` column to `hl_enrollment`

New migration method `migrate_add_enrollment_language_preference()`. Same pattern as Step 3: check column existence first, then `ALTER TABLE ... ADD COLUMN`. Defaults to `'en'`.

### Step 6: Backfill `language_preference`

For enrollments whose users are in Spanish LearnDash groups (groups 33639, 33667, and the Spanish Phase I/II groups), set `language_preference = 'es'` WHERE `language_preference = 'en'` (idempotent). All others stay `'en'`.

This is a best-effort heuristic. Admins can correct individual enrollments later.

### Step 7: Post-migration verification

After migration, run a verification query (via WP-CLI or admin health check):

```sql
SELECT COUNT(*) FROM hl_component 
WHERE component_type = 'learndash_course' AND catalog_id IS NULL AND status = 'active'
```

If result = 0, set `update_option('hl_catalog_migration_complete', true)` to disable the `external_ref` fallback in `on_course_completed()`.

### Step 8: Update routing service `$stages`

Change from raw course IDs to `catalog_codes`. This is a code change (not a DB migration) that MUST ship in the same commit/deploy as Steps 1-7.

### Admin UI warning: changing LD course IDs

If an admin edits a catalog entry and changes an `ld_course_*` value that had existing completions, the edit form should display a warning: "Changing this course ID may affect routing for users who completed the previous course. Existing component completions are preserved." This is non-blocking — the admin can proceed.

## 11. New Files

| File | Purpose |
|---|---|
| `includes/admin/class-hl-admin-course-catalog.php` | Admin page (list + form + AJAX) |
| `includes/domain/class-hl-course-catalog.php` | Domain model |
| `includes/domain/repositories/class-hl-course-catalog-repository.php` | CRUD repository |
| `assets/js/admin-course-catalog.js` | AJAX search for LD courses |

## 12. Modified Files

| File | Changes |
|---|---|
| `includes/class-hl-installer.php` | New table, migration steps 1-7, seed data |
| `includes/class-hl-core.php` | Load new classes, register admin page |
| `includes/services/class-hl-pathway-routing-service.php` | Catalog-code-based stages, `is_catalog_entry_completed()`, `is_catalog_ready()` |
| `includes/integrations/class-hl-learndash-integration.php` | Catalog-aware `on_course_completed()` with fallback |
| `includes/services/class-hl-import-participant-handler.php` | `language` CSV column, re-import diff-check |
| `includes/admin/class-hl-admin-pathways.php` | Catalog dropdown with language badges for LD components |
| `includes/admin/class-hl-admin-enrollments.php` | `language_preference` field in edit form |
| `includes/admin/class-hl-admin-reporting.php` | Use catalog title for LD components, `component.title` for others |
| `includes/domain/class-hl-component.php` | Add `catalog_id` property |
| `includes/frontend/class-hl-frontend-program-page.php` | Catalog-based course link + progress resolution |
| `includes/frontend/class-hl-frontend-my-progress.php` | Catalog-based progress resolution |
| `includes/frontend/class-hl-frontend-component-page.php` | Catalog-based course redirect resolution |

## 13. Out of Scope (Not v1)

- "Language completed in" column in reports
- User-facing language selection UI (e.g., "Choose your language" step before starting a pathway)
- Automatic LD course enrollment based on language preference (users are already enrolled in LD courses via groups)
- WPML integration — WPML handles site UI translation independently; Course Catalog handles course-level language mapping
- Portuguese course creation in LearnDash (courses don't exist yet)
- Certificate generation (will use catalog data when built later)
- Migration from column-per-language to junction table (only needed if 4+ languages added)
