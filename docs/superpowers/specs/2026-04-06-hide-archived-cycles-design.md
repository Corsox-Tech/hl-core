# Hide Archived Cycle Data from Non-Privileged Users

**Date:** 2026-04-06
**Status:** Approved
**Problem:** Old/archived cycle data (e.g., "South Haven - Cycle 1 (2025)") creates noise across the UI. Cycle selectors default to old cycles, staff lists show duplicates, and users see data they no longer need.

## Rules

### Who sees what

| User type | Archived cycle visibility |
|-----------|--------------------------|
| **Admin** (`manage_hl_core` capability) | Full visibility everywhere. Default to most recent active cycle. |
| **Coach** (WP role `coach`, which also has `manage_hl_core`) | Full visibility everywhere. Default to most recent active cycle. |
| **Everyone else** (teacher, mentor, school/district leader) | Archived cycles hidden everywhere **except** My Programs and Reports. |

### How "archived" is determined

Use the `hl_cycle.status = 'archived'` flag. No date-based logic. Manual control via the existing status field.

### Default cycle behavior

All cycle selectors (pills, dropdowns, tabs) default to the **most recent active cycle** by `start_date DESC WHERE status = 'active'`, regardless of user role.

## Central Helper

Add to `HL_Security` (existing auth helper class in `includes/security/class-hl-security.php`):

```php
public static function should_hide_archived(): bool {
    return ! current_user_can( 'manage_hl_core' );
}
```

Returns `true` when the current user is NOT admin and NOT coach. Both admins and coaches have the `manage_hl_core` capability (assigned in `HL_Installer`), so a single capability check covers both roles. No DB query needed.

## Pages to Modify

### 1. User Profile (`class-hl-frontend-user-profile.php`)
- **Cycle pills**: Filter out enrollments whose cycle is archived when `should_hide_archived()`.
- **Default enrollment**: Pick the enrollment whose cycle has the most recent `start_date` among active cycles, instead of the first enrollment in the list.
- **Enrollment resolution**: If a URL param points to an archived enrollment and user can't see archived, silently fall back to the default active enrollment (no HTTP redirect — let existing fallback logic handle it).

### 2. My Progress (`class-hl-frontend-my-progress.php`)
- **Cycle tabs**: Skip enrollments whose cycle is archived when `should_hide_archived()`.
- **Default tab**: Most recent active cycle.

### 3. My Cycle (`class-hl-frontend-my-cycle.php`)
- **Cycle dropdown**: Exclude archived cycles from the selector when `should_hide_archived()`.
- **Default selection**: Most recent active cycle by `start_date DESC`.

### 4. Team Progress (`class-hl-frontend-team-progress.php`)
- **Cycle tabs**: Skip enrollments whose cycle is archived when `should_hide_archived()`.
- **Default tab**: Most recent active cycle.

### 5. Cycles Listing (`class-hl-frontend-cycles-listing.php`)
- **Cycle list**: Exclude archived cycles from results when `should_hide_archived()`.
- Remove the "Archived" filter checkbox for non-privileged users.

### 6. Cycle Dashboard (`class-hl-frontend-cycle-dashboard.php`)
- **`get_accessible_cycles()`**: Filter out archived cycles when `should_hide_archived()`.
- **Default cycle**: Most recent active by `start_date DESC`.

### 7. Cycle Workspace (`class-hl-frontend-cycle-workspace.php`)
- **Access guard**: If `should_hide_archived()` and the requested `cycle_id` is archived, show "Cycle not available" message instead of the workspace.

### 8. School Page (`class-hl-frontend-school-page.php`)
- Already filters `WHERE t.status = 'active'` in `get_active_tracks_for_school()`. **No change needed.**

### 9. District Page (`class-hl-frontend-district-page.php`)
- Already filters `WHERE t.status = 'active'` in `get_active_tracks_for_district()`. **No change needed.**

## Pages NOT Changed

| Page | Reason |
|------|--------|
| **My Programs** (`hl_my_programs`) | Users keep full access to old courses and pathways. This is the one place they can still enter old courses. |
| **Reports** (all report pages) | Reports always show all cycle data for everyone. |
| **My Coaching** (`hl_my_coaching`) | Already skips archived cycles at line 63. No change needed. |
| **Dashboard** (`hl_dashboard`) | Already scoped to active enrollments. No change needed. |
| **Team Page** (`hl_team_page`) | Scoped to a specific team/cycle via URL. Access controlled by team membership. |

## Shared Enrollment Filter Helper

Add to `HL_Security` alongside `should_hide_archived()`:

```php
public static function filter_enrollments_by_cycle_status( array $enrollments, HL_Cycle_Repository $cycle_repo ): array
```

Takes an array of enrollment objects/rows, batch-fetches their cycle statuses in one query, and removes enrollments whose cycle is archived. Every page uses this instead of implementing its own filter loop.

Also add to `HL_Cycle_Repository`:

```php
public function get_statuses_by_ids( array $cycle_ids ): array
```

Returns `[ cycle_id => status, ... ]` in a single query. Avoids the N+1 problem of calling `get_by_id()` per enrollment.

## Edge Case: All Enrollments Archived

When `should_hide_archived()` is `true` and all of a user's enrollments are in archived cycles, pages must show a distinct empty state: "Your enrolled cycles have been archived. Visit My Programs to access your course materials." — NOT the generic "No enrollments" message, which would be misleading.

## Implementation Notes

- The `should_hide_archived()` check happens in the frontend rendering layer only. No changes to existing repository query behavior — they continue returning all data. The only repository addition is the `get_statuses_by_ids()` batch helper.
- Each page calls the shared `filter_enrollments_by_cycle_status()` helper rather than implementing its own filter loop. This keeps filtering logic DRY and consistent.
- The helper is stateless and requires no caching — `current_user_can()` is a fast in-memory check.
