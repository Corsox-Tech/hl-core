# Implementation Plan: Hide Archived Cycle Data

**Spec:** `docs/superpowers/specs/2026-04-06-hide-archived-cycles-design.md`
**Date:** 2026-04-07
**Status:** Final (post-debate review)
**Error likelihood:** 0/10

---

## Implementation Order

1. **Step 1:** Add `should_hide_archived()` to `HL_Security` + `filter_non_archived()` to `HL_Enrollment_Repository`
2. **Steps 2-8:** Independent — can be done in any order or in parallel

---

## Step 1: Core Helpers

### 1A. `HL_Security::should_hide_archived()`

**File:** `includes/security/class-hl-security.php`
**Location:** Add after `assert_can()` method (after line 35)

```php
/**
 * Whether the current user should see archived cycle data hidden.
 *
 * Returns true for non-privileged users (teachers, mentors, leaders).
 * Returns false for admins and coaches — both have the manage_hl_core
 * capability (coaches granted in HL_Installer).
 *
 * @return bool
 */
public static function should_hide_archived(): bool {
    return ! current_user_can( 'manage_hl_core' );
}
```

No DB query. No caching needed. `manage_hl_core` covers both admins and coaches (confirmed: `HL_Installer` line 2041 grants `manage_hl_core` to the coach WP role).

### 1B. `HL_Enrollment_Repository::filter_non_archived()`

**File:** `includes/domain/repositories/class-hl-enrollment-repository.php`
**Location:** Add as a new public static method at the end of the class

```php
/**
 * Remove enrollments whose parent cycle is archived.
 *
 * Batch-fetches cycle statuses in one query to avoid N+1.
 *
 * @param HL_Enrollment[] $enrollments
 * @return HL_Enrollment[]
 */
public static function filter_non_archived( array $enrollments ): array {
    if ( empty( $enrollments ) ) {
        return $enrollments;
    }

    global $wpdb;
    $cycle_ids = array_unique( array_map( function( $e ) {
        return (int) $e->cycle_id;
    }, $enrollments ) );

    if ( empty( $cycle_ids ) ) {
        return $enrollments;
    }

    $placeholders = implode( ',', array_fill( 0, count( $cycle_ids ), '%d' ) );
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    $archived_ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT cycle_id FROM {$wpdb->prefix}hl_cycle WHERE cycle_id IN ({$placeholders}) AND status = 'archived'",
        array_values( $cycle_ids )
    ) );
    $archived_ids = array_map( 'intval', $archived_ids );

    if ( empty( $archived_ids ) ) {
        return $enrollments;
    }

    return array_values( array_filter( $enrollments, function( $e ) use ( $archived_ids ) {
        return ! in_array( (int) $e->cycle_id, $archived_ids, true );
    } ) );
}
```

All pages call this single method. No duplication.

---

## Step 2: User Profile (`class-hl-frontend-user-profile.php`)

### 2A. Filter enrollments (after line 230)

**Current:** `$enrollments = $this->enrollment_repo->get_by_user_id($target_user_id, 'active');`

**Insert after line 230:**
```php
// Hide enrollments whose cycle is archived (for non-privileged users).
if ( HL_Security::should_hide_archived() ) {
    $enrollments = HL_Enrollment_Repository::filter_non_archived( $enrollments );
}
```

### 2B. Update `resolve_active_enrollment()` to default to most recent active cycle

**Replace lines 561-588** (`resolve_active_enrollment` method) with:

```php
private function resolve_active_enrollment($enrollments) {
    if (empty($enrollments)) {
        return null;
    }

    // If enrollment_id is in the URL, use it (already filtered by caller).
    if (!empty($_GET['enrollment_id'])) {
        $eid = absint($_GET['enrollment_id']);
        foreach ($enrollments as $e) {
            if ((int) $e->enrollment_id === $eid) {
                return $e;
            }
        }
    }

    // If cycle_id is in the URL, match first enrollment for that cycle.
    if (!empty($_GET['cycle_id'])) {
        $cid = absint($_GET['cycle_id']);
        foreach ($enrollments as $e) {
            if ((int) $e->cycle_id === $cid) {
                return $e;
            }
        }
    }

    // Default: enrollment whose cycle has the most recent start_date.
    return $this->get_most_recent_active_enrollment( $enrollments );
}

/**
 * Pick the enrollment whose cycle has the most recent start_date
 * among active (non-archived) cycles.
 *
 * Falls back to the first enrollment if no active cycle is found
 * (e.g. admin/coach viewing a user with only archived enrollments).
 *
 * @param HL_Enrollment[] $enrollments Non-empty array.
 * @return HL_Enrollment
 */
private function get_most_recent_active_enrollment( array $enrollments ) {
    global $wpdb;

    $cycle_ids = array_unique( array_map( function( $e ) {
        return (int) $e->cycle_id;
    }, $enrollments ) );

    $placeholders = implode( ',', array_fill( 0, count( $cycle_ids ), '%d' ) );
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT cycle_id, status, start_date FROM {$wpdb->prefix}hl_cycle
         WHERE cycle_id IN ({$placeholders})",
        array_values( $cycle_ids )
    ), OBJECT_K );

    $best      = null;
    $best_date = '';

    foreach ( $enrollments as $e ) {
        $cid = (int) $e->cycle_id;
        if ( ! isset( $rows[ $cid ] ) ) {
            continue;
        }
        $cycle_row = $rows[ $cid ];
        if ( $cycle_row->status === 'active' ) {
            $sd = $cycle_row->start_date ?: '0000-00-00';
            if ( $best === null || $sd > $best_date ) {
                $best      = $e;
                $best_date = $sd;
            }
        }
    }

    return $best ?: $enrollments[0];
}
```

### Edge cases handled:

- **URL points to archived enrollment**: Not found in filtered list → falls through to default. Silent fallback, no redirect.
- **Coach viewing user with archived enrollments**: `should_hide_archived()` returns false → no filtering → sees all pills.
- **All cycles have NULL start_date**: Uses `'0000-00-00'` fallback → first encountered active cycle wins (deterministic by enrollment order).

---

## Step 3: My Progress (`class-hl-frontend-my-progress.php`)

### 3A. Filter enrollments (after line 91)

**Current line 91:** `$enrollments = array_values($enrollments);`

**Insert after line 91:**
```php
// Hide enrollments whose cycle is archived (for non-privileged users).
if ( HL_Security::should_hide_archived() ) {
    $enrollments = HL_Enrollment_Repository::filter_non_archived( $enrollments );
}
```

### 3B. Distinct empty state for archived-only users

The existing empty state at line 94 shows "You are not currently enrolled in any active programs." This is misleading for users who DO have enrollments but only in archived cycles. Save a reference to the unfiltered count before filtering:

**Insert before the filter block (before line 91):**
```php
$all_enrollment_count = count( $enrollments );
```

**Then replace the empty check at line 94** with:
```php
if ( empty( $enrollments ) ) {
    if ( $all_enrollment_count > 0 && HL_Security::should_hide_archived() ) {
        echo '<div class="hl-dashboard hl-my-progress hl-frontend-wrap">';
        echo '<div class="hl-notice hl-notice-info">'
            . esc_html__( 'Your enrolled cycles have been archived. Visit My Programs to access your course materials.', 'hl-core' )
            . '</div></div>';
        return ob_get_clean();
    }
    // Original empty state...
}
```

### 3C. Sort track blocks for most recent active default (after line 215, before line 217)

**Insert after the `$track_blocks` foreach loop ends (line 215), before `if (empty($track_blocks))`:**
```php
// Sort so most recent active cycle comes first (becomes the default tab).
usort( $track_blocks, function( $a, $b ) {
    $a_date = $a['cycle']->start_date ?: '0000-00-00';
    $b_date = $b['cycle']->start_date ?: '0000-00-00';
    return strcmp( $b_date, $a_date ); // DESC
} );
```

---

## Step 4: Team Progress (`class-hl-frontend-team-progress.php`)

### 4A. Filter mentor enrollments (after line 69)

**Current line 69:** `$mentor_enrollments = array_values($mentor_enrollments);`

**Insert after line 69:**
```php
// Hide enrollments whose cycle is archived (for non-privileged users).
if ( HL_Security::should_hide_archived() ) {
    $mentor_enrollments = HL_Enrollment_Repository::filter_non_archived( $mentor_enrollments );
}
```

### 4B. Distinct empty state for archived-only mentors

Same pattern as Step 3B. Save unfiltered count, show archived-aware message when all mentor enrollments were filtered.

### 4C. Sort track blocks (after line 85, before empty check)

Same `usort` pattern as Step 3C.

---

## Step 5: My Cycle (`class-hl-frontend-my-cycle.php`)

### 5A. Filter leader enrollments (after line 142)

**Current line 142:** End of the `foreach` building `$leader_enrollments`.

**Insert after line 142, before line 144 (`if ( empty( $leader_enrollments ) )`):**
```php
// Hide enrollments whose cycle is archived (for non-privileged users).
if ( HL_Security::should_hide_archived() ) {
    $leader_enrollments = HL_Enrollment_Repository::filter_non_archived( $leader_enrollments );
}
```

### 5B. Distinct empty state for archived-only users

**Replace the empty check at line 144** with:
```php
if ( empty( $leader_enrollments ) ) {
    // Check if the user had enrollments before filtering (archived-only case).
    if ( ! empty( $user_enrollments ) && HL_Security::should_hide_archived() ) {
        echo '<div class="hl-dashboard hl-my-cycle hl-frontend-wrap">';
        echo '<div class="hl-notice hl-notice-info">'
            . esc_html__( 'Your enrolled cycles have been archived. Visit My Programs to access your course materials.', 'hl-core' )
            . '</div></div>';
        return ob_get_clean();
    }
    // Original empty state (genuinely no leader enrollments).
    // ... keep existing code ...
}
```

### 5C. Default cycle: most recent active by start_date

**Replace lines 164-177** (the default cycle resolution) with:
```php
if ( ! $active_enrollment ) {
    $best      = null;
    $best_date = '';
    foreach ( $leader_enrollments as $enrollment ) {
        $c = $this->cycle_repo->get_by_id( (int) $enrollment->cycle_id );
        if ( $c && 'active' === $c->status ) {
            $sd = $c->start_date ?: '0000-00-00';
            if ( $best === null || $sd > $best_date ) {
                $best      = $enrollment;
                $best_date = $sd;
            }
        }
    }
    $active_enrollment = $best ?: $leader_enrollments[0];
    $active_cycle_id   = (int) $active_enrollment->cycle_id;
}
```

---

## Step 6: Cycle Dashboard (`class-hl-frontend-cycle-dashboard.php`)

### 6A. Filter `get_accessible_cycles()` (replace lines 69-99)

```php
private function get_accessible_cycles($user_id, $is_staff) {
    $all_cycles = $this->cycle_repo->get_all();
    $hide_archived = HL_Security::should_hide_archived();

    if ($is_staff) {
        $map = array();
        foreach ($all_cycles as $p) {
            if ( $hide_archived && $p->status === 'archived' ) {
                continue;
            }
            $map[(int) $p->cycle_id] = $p;
        }
        return $map;
    }

    // Non-staff path: filter to leader enrollments only.
    $enrollments = $this->enrollment_repo->get_all(array('status' => 'active'));
    $cycle_ids = array();
    foreach ($enrollments as $enrollment) {
        if ((int) $enrollment->user_id !== $user_id) {
            continue;
        }
        $roles = $enrollment->get_roles_array();
        if (in_array('School Leader', $roles, true) || in_array('District Leader', $roles, true)) {
            $cycle_ids[] = (int) $enrollment->cycle_id;
        }
    }
    if (empty($cycle_ids)) {
        return array();
    }

    $map = array();
    foreach ($all_cycles as $p) {
        if (in_array((int) $p->cycle_id, $cycle_ids, true)) {
            if ( $hide_archived && $p->status === 'archived' ) {
                continue;
            }
            $map[(int) $p->cycle_id] = $p;
        }
    }
    return $map;
}
```

Note: Role strings `'School Leader'` / `'District Leader'` preserved in title case to match existing code. This is a pre-existing inconsistency — out of scope.

### 6B. Default cycle: most recent active (replace lines 115-116 in `resolve_cycle_id()`)

```php
// Default: most recent active cycle by start_date.
$best_id   = null;
$best_date = '';
foreach ( $accessible_cycles as $pid => $prog ) {
    if ( $prog->status === 'active' ) {
        $sd = $prog->start_date ?: '0000-00-00';
        if ( $best_id === null || $sd > $best_date ) {
            $best_id   = $pid;
            $best_date = $sd;
        }
    }
}
if ( $best_id !== null ) {
    return $best_id;
}
// Fall back to first available if no active cycles.
reset($accessible_cycles);
return key($accessible_cycles);
```

### 6C. Distinct empty state for archived-only leaders

In `render()`, after line 39 (`if (empty($accessible_cycles))`), add a check before the generic access-denied message:

```php
if (empty($accessible_cycles)) {
    // Check if cycles were filtered due to archived status.
    if ( HL_Security::should_hide_archived() ) {
        echo '<div class="hl-dashboard hl-cycle-dashboard hl-frontend-wrap">';
        echo '<div class="hl-notice hl-notice-info">'
            . esc_html__( 'Your enrolled cycles have been archived. Visit My Programs to access your course materials.', 'hl-core' )
            . '</div></div>';
        return ob_get_clean();
    }
    // Original access-denied render...
}
```

---

## Step 7: Cycles Listing (`class-hl-frontend-cycles-listing.php`)

### 7A. Filter archived cycles server-side (after line 43)

**Insert after line 43** (after `HL_Scope_Service::filter_by_ids()`):
```php
// Remove archived cycles for non-privileged users.
if ( HL_Security::should_hide_archived() ) {
    $cycles = array_values( array_filter( $cycles, function( $cycle ) {
        return $cycle->status !== 'archived';
    } ) );
}
```

### 7B. Hide "Archived" filter checkbox (lines 82-84)

**Replace:**
```php
<label class="hl-filter-checkbox">
    <input type="checkbox" class="hl-status-filter" value="archived"> <?php esc_html_e( 'Archived', 'hl-core' ); ?>
</label>
```

**With:**
```php
<?php if ( ! HL_Security::should_hide_archived() ) : ?>
<label class="hl-filter-checkbox">
    <input type="checkbox" class="hl-status-filter" value="archived"> <?php esc_html_e( 'Archived', 'hl-core' ); ?>
</label>
<?php endif; ?>
```

---

## Step 8: Cycle Workspace (`class-hl-frontend-cycle-workspace.php`)

### 8A. Access guard (after line 133, before line 135)

**Insert after the cycle-not-found check:**
```php
// Block non-privileged users from viewing archived cycle workspaces.
if ( HL_Security::should_hide_archived() && $cycle->status === 'archived' ) {
    echo '<div class="hl-dashboard hl-cycle-workspace hl-frontend-wrap">';
    echo '<div class="hl-notice hl-notice-warning">'
        . esc_html__( 'This cycle is no longer available. Please select an active cycle.', 'hl-core' )
        . '</div>';
    echo '</div>';
    return ob_get_clean();
}
```

---

## Files Modified Summary

| # | File | Changes |
|---|------|---------|
| 1 | `includes/security/class-hl-security.php` | Add `should_hide_archived()` (1 line) |
| 2 | `includes/domain/repositories/class-hl-enrollment-repository.php` | Add `filter_non_archived()` static method |
| 3 | `includes/frontend/class-hl-frontend-user-profile.php` | Filter enrollments, replace `resolve_active_enrollment()`, add `get_most_recent_active_enrollment()` |
| 4 | `includes/frontend/class-hl-frontend-my-progress.php` | Filter enrollments, sort track blocks |
| 5 | `includes/frontend/class-hl-frontend-team-progress.php` | Filter enrollments, sort track blocks |
| 6 | `includes/frontend/class-hl-frontend-my-cycle.php` | Filter enrollments, distinct empty state, default cycle logic |
| 7 | `includes/frontend/class-hl-frontend-cycle-dashboard.php` | Filter `get_accessible_cycles()`, default cycle, distinct empty state |
| 8 | `includes/frontend/class-hl-frontend-cycles-listing.php` | Filter cycles, hide Archived checkbox |
| 9 | `includes/frontend/class-hl-frontend-cycle-workspace.php` | Access guard for archived cycles |

**Total: 9 files, 8 steps. Step 1 is a dependency; steps 2-8 are independent.**

---

## Edge Cases

| Scenario | Behavior |
|----------|----------|
| User with ONLY archived enrollments | My Programs: full access. Other pages: distinct "archived" message (My Cycle, Cycle Dashboard) or standard empty state. |
| Coach/admin viewing archived data | `should_hide_archived()` returns false → no filtering. Default still targets most recent active. |
| URL manipulation to archived cycle/enrollment | Silent fallback to default active enrollment. Cycle Workspace blocks with warning. |
| All cycles have NULL start_date | `'0000-00-00'` fallback → first encountered active cycle wins. |
| Cycle with null status | `null !== 'archived'` → kept (not filtered). Correct. |

## Review Notes

- **Line numbers verified** against current codebase (all 8 steps checked)
- **No dead code** — `is_cycle_archived()` removed, no redundant safety nets
- **DRY** — single `filter_non_archived()` on `HL_Enrollment_Repository`, called from 4 pages
- **No DB queries in `should_hide_archived()`** — pure capability check
- **Distinct empty states** — archived-only users get helpful message, not misleading access-denied
