# Suspended User Handling — Design Spec

**Date:** 2026-04-06
**Status:** Approved
**Author:** Claude (with user review)

## Problem Statement

BuddyBoss allows admins to suspend users. Currently, HL Core has no awareness of suspension status — suspended users appear in all frontend listings (Learners, Team Page, My Team, Coach Mentors, Reports) and have no visual indicator in admin pages. 8 of 12 currently suspended users have active HL enrollments (all teachers) and show up alongside active participants.

## Solution Overview

A single static helper method checks BuddyBoss suspension status. Frontend queries exclude suspended users via a NOT EXISTS subquery. Admin pages show suspended users with a red "Suspended" badge and an optional filter pill on the Enrollments page. Enrollments remain active — suspension only affects visibility, not data.

## 1. Central Helper

### `HL_BuddyBoss_Integration::is_user_suspended( $user_id )`

Static method with per-request cache.

```php
public static function is_user_suspended( $user_id ) {
    static $cache = array();
    $user_id = absint( $user_id );
    if ( isset( $cache[ $user_id ] ) ) {
        return $cache[ $user_id ];
    }
    global $wpdb;
    $suspended = (bool) $wpdb->get_var( $wpdb->prepare(
        "SELECT 1 FROM {$wpdb->prefix}bp_suspend
         WHERE item_type = 'user' AND item_id = %d AND user_suspended = 1
         LIMIT 1",
        $user_id
    ) );
    $cache[ $user_id ] = $suspended;
    return $suspended;
}
```

### SQL snippet for bulk queries

For queries that join `wp_users` or `hl_enrollment`, add this WHERE clause to exclude suspended users:

```sql
AND NOT EXISTS (
    SELECT 1 FROM {$wpdb->prefix}bp_suspend
    WHERE item_type = 'user' AND item_id = e.user_id AND user_suspended = 1
)
```

The `bp_suspend` table has indexes on `item_id` and `user_suspended`, so this subquery is efficient. For the ~12 suspended users on a ~1300-user site, the NOT EXISTS check is negligible.

### Graceful degradation

If the `bp_suspend` table doesn't exist (BuddyBoss deactivated), the helper returns `false` (user not suspended) and the SQL snippet is omitted. Check table existence once per request via a static flag.

```php
private static $bp_suspend_exists = null;

public static function bp_suspend_table_exists() {
    if ( self::$bp_suspend_exists === null ) {
        global $wpdb;
        self::$bp_suspend_exists = ( $wpdb->get_var(
            $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->prefix . 'bp_suspend' )
        ) !== null );
    }
    return self::$bp_suspend_exists;
}
```

All callers check `bp_suspend_table_exists()` before adding the NOT EXISTS clause. If the table doesn't exist, no filtering happens.

## 2. Frontend — Hide Suspended Users

Add the NOT EXISTS subquery to these frontend page queries:

| Page | File | Query location | Join column |
|------|------|----------------|-------------|
| Learners listing | `class-hl-frontend-learners.php` | Main enrollment query | `e.user_id` |
| Team Page (members) | `class-hl-frontend-team-page.php` | Members list query | `e.user_id` |
| My Team | `class-hl-frontend-my-team.php` | Team members query | `e.user_id` |
| Coach Mentors | `class-hl-frontend-coach-mentors.php` | Mentor roster query | `e.user_id` |
| Reports (completion) | `class-hl-frontend-reports-hub.php` or reporting service | Report detail rows | `e.user_id` |
| User Profile | `class-hl-frontend-user-profile.php` | Profile access check | `$user_id` param |

### User Profile special handling

When a user navigates to `/user-profile/?user_id=X` where X is suspended:
- Show a notice: "This user is not available."
- Do not render profile tabs or data.
- Check via `HL_BuddyBoss_Integration::is_user_suspended()` before any rendering.
- Exception: users with `manage_hl_core` can still view suspended profiles (they need to manage the data).

### Team Repository

The `HL_Team_Repository::get_members()` method is used by the Team Page. Add the NOT EXISTS clause there so all consumers benefit.

## 3. Admin — Badge + Filter

### Red "Suspended" badge

When rendering user names in admin tables, check `is_user_suspended()` and append:

```html
<span class="hl-status-badge suspended">Suspended</span>
```

CSS:
```css
.hl-status-badge.suspended {
    background: #dc3545;
    color: #fff;
}
```

This follows the existing `.hl-status-badge` pattern used for enrollment status, coaching status, and org unit status.

### Admin pages that show the badge

| Page | File | Where badge appears |
|------|------|---------------------|
| Enrollments list | `class-hl-admin-enrollments.php` | Next to user display_name in the table |
| Assessment Hub | `class-hl-admin-assessment-hub.php` | Next to user name in assessment instance lists |
| Coaching sessions | `class-hl-admin-coaching.php` | Next to mentor/teacher names |

### Enrollments filter pill

Add a "Suspended" filter pill to the Enrollments list page (alongside the existing cycle/partnership/role/school filters):

- Shows count: "Suspended (8)"
- Default state: all users shown (suspended + non-suspended), badges visible
- Active state: filters to show ONLY enrollments for suspended users
- Uses the same filter pill pattern as existing status/cycle filters

## 4. Files Modified

| File | Change |
|------|--------|
| `includes/integrations/class-hl-buddyboss-integration.php` | Add `is_user_suspended()`, `bp_suspend_table_exists()`, `get_suspend_sql()` static helpers |
| `includes/frontend/class-hl-frontend-learners.php` | Add NOT EXISTS to enrollment query |
| `includes/frontend/class-hl-frontend-team-page.php` | Add NOT EXISTS to members query |
| `includes/frontend/class-hl-frontend-my-team.php` | Add NOT EXISTS to team members query |
| `includes/frontend/class-hl-frontend-coach-mentors.php` | Add NOT EXISTS to mentor query |
| `includes/frontend/class-hl-frontend-user-profile.php` | Add suspended check before rendering |
| `includes/domain/repositories/class-hl-team-repository.php` | Add NOT EXISTS to `get_members()` |
| `includes/services/class-hl-reporting-service.php` | Add NOT EXISTS to report detail queries |
| `includes/admin/class-hl-admin-enrollments.php` | Add badge rendering + "Suspended" filter pill |
| `includes/admin/class-hl-admin-assessment-hub.php` | Add badge next to user names |
| `includes/admin/class-hl-admin-coaching.php` | Add badge next to user names |
| `assets/css/frontend.css` | Add `.hl-status-badge.suspended` style |

### No new files, no new tables, no new services.

## 5. What This Design Does NOT Do

- **Does not deactivate enrollments** — enrollment status remains `active`. Suspension only affects visibility.
- **Does not remove team memberships or pathway assignments** — data stays intact for when/if the user is unsuspended.
- **Does not listen for BB suspension events** — checks on read, not on write. If BB unsuspends a user, they reappear immediately on next page load.
- **Does not affect WP-CLI or CSV exports** — admin-only tools show all data regardless.
- **Does not affect admin user counts or dashboard stats** — suspended users still count in enrollment totals.
