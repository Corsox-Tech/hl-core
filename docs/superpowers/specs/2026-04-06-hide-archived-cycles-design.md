# Hide Archived Cycle Data from Non-Privileged Users

**Date:** 2026-04-06
**Status:** Approved
**Problem:** Old/archived cycle data (e.g., "South Haven - Cycle 1 (2025)") creates noise across the UI. Cycle selectors default to old cycles, staff lists show duplicates, and users see data they no longer need.

## Rules

### Who sees what

| User type | Archived cycle visibility |
|-----------|--------------------------|
| **Admin** (`manage_hl_core`) | Full visibility everywhere. Default to most recent active cycle. |
| **Coach** (active enrollment with `coach` role) | Full visibility everywhere. Default to most recent active cycle. |
| **Everyone else** (teacher, mentor, school/district leader) | Archived cycles hidden everywhere **except** My Programs and Reports. |

### How "archived" is determined

Use the `hl_cycle.status = 'archived'` flag. No date-based logic. Manual control via the existing status field.

### Default cycle behavior

All cycle selectors (pills, dropdowns, tabs) default to the **most recent active cycle** by `start_date DESC WHERE status = 'active'`, regardless of user role.

## Central Helper

Add to `HL_Security` (existing auth helper class in `includes/core/class-hl-security.php`):

```php
public static function should_hide_archived(): bool
```

Returns `true` when the current user is NOT admin and NOT coach. When `true`, pages must filter out enrollments/cycles where `hl_cycle.status = 'archived'`.

Coach detection: query `hl_enrollment` for an active enrollment where `roles` JSON contains `"coach"`.

## Pages to Modify

### 1. User Profile (`class-hl-frontend-user-profile.php`)
- **Cycle pills**: Filter out enrollments whose cycle is archived when `should_hide_archived()`.
- **Default enrollment**: Pick the enrollment whose cycle has the most recent `start_date` among active cycles, instead of the first enrollment in the list.
- **Enrollment resolution**: If a URL param points to an archived enrollment and user can't see archived, redirect to the default active enrollment.

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

## Implementation Notes

- The `should_hide_archived()` check happens in the frontend rendering layer only. No changes to repositories or services — they continue returning all data. This keeps backend/reporting logic untouched.
- Each page filters in its own `render()` method or data-fetching helper using a simple `if/continue` or `array_filter` pattern after calling the helper.
- The helper is stateless — no caching needed, just checks `current_user_can()` and a single enrollment query for coach detection.
