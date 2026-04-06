# Suspended User Handling — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Hide BuddyBoss-suspended users from all HL frontend pages, and show a red "Suspended" badge + filter pill in admin enrollment/assessment/coaching pages.

**Architecture:** One central helper (`HL_BuddyBoss_Integration::is_user_suspended()` + `get_suspend_not_exists_sql()`) provides both single-user checks and bulk SQL snippets. Frontend pages add a NOT EXISTS subquery to exclude suspended users. Admin pages add a badge after display_name rendering and a filter pill on the enrollments list.

**Tech Stack:** PHP 7.4+, WordPress, BuddyBoss Platform (wp_bp_suspend table).

**Spec:** `docs/superpowers/specs/2026-04-06-suspended-users-design.md`

---

## File Map

| File | Action | Change |
|------|--------|--------|
| `includes/integrations/class-hl-buddyboss-integration.php` | Modify | Add 3 static helper methods before closing `}` (~line 898) |
| `includes/frontend/class-hl-frontend-learners.php` | Modify | Add NOT EXISTS to WHERE clause (~line 192) |
| `includes/domain/repositories/class-hl-team-repository.php` | Modify | Add NOT EXISTS to `get_members()` query (~line 98) |
| `includes/services/class-hl-coach-assignment-service.php` | Modify | Add NOT EXISTS to `get_coach_roster()` query (~line 389). This is the delegation point for Coach Mentors (spec says `class-hl-frontend-coach-mentors.php` but that class calls `HL_Coach_Dashboard_Service::get_mentors_for_coach()` which calls `get_coach_roster()`) |
| `includes/services/class-hl-reporting-service.php` | Modify | Add NOT EXISTS to participant report query (~line 398) |
| `includes/services/class-hl-coaching-service.php` | Modify | Add `e.user_id AS mentor_user_id` to `get_by_cycle()` SELECT clause |
| `includes/frontend/class-hl-frontend-user-profile.php` | Modify | Add suspended check before profile render (~line 234) |
| `includes/admin/class-hl-admin-enrollments.php` | Modify | Add badge to display_name (~line 550), add filter dropdown with count (~line 471), add query filter |
| `includes/admin/class-hl-admin-assessments.php` | Modify | Add badge to display_name (~lines 399, 572) |
| `includes/admin/class-hl-admin-coaching.php` | Modify | Add badge to mentor names (~line 470), add `e.user_id AS mentor_user_id` to fallback query (~line 411) |
| `assets/css/admin.css` | Modify | Add `.hl-status-badge.suspended` style (~line 530) |

---

## Task 1: Central Helper Methods

**Files:**
- Modify: `includes/integrations/class-hl-buddyboss-integration.php:898` (before closing `}`)

- [ ] **Step 1: Add three static methods to `HL_BuddyBoss_Integration`**

Find the closing `}` of the class (around line 898). Add the following methods before it:

```php
    // =========================================================================
    // BuddyBoss Suspension Helpers
    // =========================================================================

    /** @var bool|null Whether wp_bp_suspend table exists (cached per request). */
    private static $bp_suspend_exists = null;

    /**
     * Check if the wp_bp_suspend table exists (BuddyBoss active).
     * Cached per request.
     */
    public static function bp_suspend_table_exists() {
        if ( self::$bp_suspend_exists === null ) {
            global $wpdb;
            self::$bp_suspend_exists = ( $wpdb->get_var(
                $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'bp_suspend' )
            ) !== null );
        }
        return self::$bp_suspend_exists;
    }

    /**
     * Check if a specific user is suspended in BuddyBoss.
     *
     * @param int $user_id WordPress user ID.
     * @return bool True if suspended.
     */
    public static function is_user_suspended( $user_id ) {
        static $cache = array();
        $user_id = absint( $user_id );
        if ( ! $user_id || ! self::bp_suspend_table_exists() ) {
            return false;
        }
        if ( isset( $cache[ $user_id ] ) ) {
            return $cache[ $user_id ];
        }
        global $wpdb;
        $cache[ $user_id ] = (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$wpdb->prefix}bp_suspend
             WHERE item_type = 'user' AND item_id = %d AND user_suspended = 1
             LIMIT 1",
            $user_id
        ) );
        return $cache[ $user_id ];
    }

    /**
     * Get a NOT EXISTS SQL snippet to exclude suspended users from queries.
     *
     * Usage: append to WHERE clause. The $user_id_column should reference
     * the column containing the WordPress user ID in the outer query.
     *
     * @param string $user_id_column SQL column reference, e.g. 'e.user_id'.
     * @return string SQL snippet (empty string if bp_suspend table absent).
     */
    public static function get_suspend_not_exists_sql( $user_id_column = 'e.user_id' ) {
        if ( ! self::bp_suspend_table_exists() ) {
            return '';
        }
        global $wpdb;
        return " AND NOT EXISTS (
            SELECT 1 FROM {$wpdb->prefix}bp_suspend
            WHERE item_type = 'user' AND item_id = {$user_id_column} AND user_suspended = 1
        )";
    }
```

- [ ] **Step 2: Commit**

```bash
git add includes/integrations/class-hl-buddyboss-integration.php
git commit -m "feat(suspended-users): add central BB suspension helpers to HL_BuddyBoss_Integration"
```

---

## Task 2: Frontend — Learners Listing

**Files:**
- Modify: `includes/frontend/class-hl-frontend-learners.php:192`

- [ ] **Step 1: Add suspension filter to the WHERE clause**

In the `get_learners()` method, find the line where the WHERE conditions array is initialized (around line 192):

```php
$where  = array( "e.status = 'active'" );
```

Add the suspension filter immediately after:

```php
$where  = array( "e.status = 'active'" );
// Exclude BB-suspended users from frontend listing.
$suspend_sql = HL_BuddyBoss_Integration::get_suspend_not_exists_sql( 'e.user_id' );
```

Then find the line where `$where_clause` is built (around line 232):

```php
$where_clause = ' WHERE ' . implode( ' AND ', $where );
```

Append the suspension SQL:

```php
$where_clause = ' WHERE ' . implode( ' AND ', $where ) . $suspend_sql;
```

- [ ] **Step 2: Commit**

```bash
git add includes/frontend/class-hl-frontend-learners.php
git commit -m "feat(suspended-users): exclude suspended users from Learners listing"
```

---

## Task 3: Frontend — Team Repository (Team Page members)

**Files:**
- Modify: `includes/domain/repositories/class-hl-team-repository.php:91-101`

- [ ] **Step 1: Add suspension filter to `get_members()`**

The current query (lines 91-101):

```php
public function get_members($team_id) {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare(
        "SELECT tm.*, e.user_id, e.roles, u.display_name, u.user_email
         FROM {$this->membership_table()} tm
         JOIN {$wpdb->prefix}hl_enrollment e ON tm.enrollment_id = e.enrollment_id
         LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
         WHERE tm.team_id = %d ORDER BY tm.membership_type ASC",
        $team_id
    ), ARRAY_A) ?: array();
}
```

Replace with (note: build SQL string first, THEN prepare — `$suspend_sql` must not go through `prepare()`):

```php
public function get_members($team_id) {
    global $wpdb;
    $suspend_sql = HL_BuddyBoss_Integration::get_suspend_not_exists_sql( 'e.user_id' );
    $sql = "SELECT tm.*, e.user_id, e.roles, u.display_name, u.user_email
            FROM {$this->membership_table()} tm
            JOIN {$wpdb->prefix}hl_enrollment e ON tm.enrollment_id = e.enrollment_id
            LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
            WHERE tm.team_id = %d {$suspend_sql} ORDER BY tm.membership_type ASC";
    return $wpdb->get_results( $wpdb->prepare( $sql, $team_id ), ARRAY_A ) ?: array();
}
```

- [ ] **Step 2: Commit**

```bash
git add includes/domain/repositories/class-hl-team-repository.php
git commit -m "feat(suspended-users): exclude suspended users from team members list"
```

---

## Task 4: Frontend — Coach Roster (Coach Mentors)

**Files:**
- Modify: `includes/services/class-hl-coach-assignment-service.php:384-392`

- [ ] **Step 1: Add suspension filter to `get_coach_roster()` final query**

The current query (lines 384-392):

```php
        return $wpdb->get_results(
            "SELECT e.enrollment_id, e.cycle_id, e.roles, e.school_id,
                    u.ID AS user_id, u.display_name, u.user_email
             FROM {$wpdb->prefix}hl_enrollment e
             LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
             WHERE e.enrollment_id IN ({$in_ids}) AND e.status = 'active'
             ORDER BY u.display_name ASC",
            ARRAY_A
        ) ?: array();
```

Replace with:

```php
        $suspend_sql = HL_BuddyBoss_Integration::get_suspend_not_exists_sql( 'e.user_id' );
        return $wpdb->get_results(
            "SELECT e.enrollment_id, e.cycle_id, e.roles, e.school_id,
                    u.ID AS user_id, u.display_name, u.user_email
             FROM {$wpdb->prefix}hl_enrollment e
             LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
             WHERE e.enrollment_id IN ({$in_ids}) AND e.status = 'active' {$suspend_sql}
             ORDER BY u.display_name ASC",
            ARRAY_A
        ) ?: array();
```

- [ ] **Step 2: Commit**

```bash
git add includes/services/class-hl-coach-assignment-service.php
git commit -m "feat(suspended-users): exclude suspended users from coach mentor roster"
```

---

## Task 5: Frontend — Reporting Service

**Files:**
- Modify: `includes/services/class-hl-reporting-service.php`

- [ ] **Step 1: Add suspension filter to participant report query**

Find the `get_participant_report()` method. The WHERE clause is assembled into `$where_sql` (around lines 338-379), then used in the SELECT at line 398:

```sql
WHERE {$where_sql}
```

Find where `$where_sql` is built (it's an imploded array similar to the learners pattern). Add the suspension SQL after the WHERE clause assembly, before the final query string:

```php
$suspend_sql = HL_BuddyBoss_Integration::get_suspend_not_exists_sql( 'e.user_id' );
```

Then in the SQL string, append `{$suspend_sql}` after `WHERE {$where_sql}`:

```sql
WHERE {$where_sql} {$suspend_sql}
```

- [ ] **Step 2: Commit**

```bash
git add includes/services/class-hl-reporting-service.php
git commit -m "feat(suspended-users): exclude suspended users from completion reports"
```

---

## Task 6: Frontend — User Profile Access

**Files:**
- Modify: `includes/frontend/class-hl-frontend-user-profile.php:234`

- [ ] **Step 1: Add suspended user check before profile rendering**

Find the access control block around line 234:

```php
if (!$this->can_view_profile($current_user_id, $target_user_id, $enrollments)) {
    echo '<div class="hl-notice hl-notice-error">'
        . esc_html__('You do not have permission to view this profile.', 'hl-core')
        . '</div>';
    return ob_get_clean();
}
```

Add a suspended check BEFORE this block:

```php
// Block access to suspended user profiles (admins can still view).
if ( $target_user_id !== $current_user_id
     && ! current_user_can( 'manage_hl_core' )
     && HL_BuddyBoss_Integration::is_user_suspended( $target_user_id ) ) {
    echo '<div class="hl-notice hl-notice-warning">'
        . esc_html__( 'This user is not available.', 'hl-core' )
        . '</div>';
    return ob_get_clean();
}
```

- [ ] **Step 2: Commit**

```bash
git add includes/frontend/class-hl-frontend-user-profile.php
git commit -m "feat(suspended-users): block access to suspended user profiles for non-admins"
```

---

## Task 7: Admin — CSS Badge

**Files:**
- Modify: `assets/css/admin.css:530` (after the last `.hl-status-badge.*` rule)

- [ ] **Step 1: Add `.hl-status-badge.suspended` CSS**

Find the last `.hl-status-badge` modifier (around line 530, `.inactive`). Add after it:

```css
.hl-status-badge.suspended { background: #FEE2E2; color: #dc2626; }
.hl-status-badge.suspended::before { background: #dc2626; }
```

- [ ] **Step 2: Commit**

```bash
git add assets/css/admin.css
git commit -m "feat(suspended-users): add .hl-status-badge.suspended CSS"
```

---

## Task 8: Admin — Enrollment List Badge + Filter

**Files:**
- Modify: `includes/admin/class-hl-admin-enrollments.php` (lines 471, 550)

- [ ] **Step 1: Add "Suspended" filter pill after Role dropdown**

Find the Role dropdown section (around line 471, after the closing `</select></div>` for the Role filter). Add a new filter:

```php
// Count suspended enrollments for filter label.
$suspended_count = 0;
if ( HL_BuddyBoss_Integration::bp_suspend_table_exists() ) {
    $suspended_count = (int) $wpdb->get_var(
        "SELECT COUNT(DISTINCT e.enrollment_id)
         FROM {$wpdb->prefix}hl_enrollment e
         INNER JOIN {$wpdb->prefix}bp_suspend s ON s.item_type = 'user' AND s.item_id = e.user_id AND s.user_suspended = 1
         WHERE e.status = 'active'"
    );
}
$f_suspended = isset( $_GET['suspended'] ) ? sanitize_text_field( $_GET['suspended'] ) : '';
echo '<div><label style="display:block;font-size:11px;font-weight:600;color:#646970;margin-bottom:2px;">' . esc_html__( 'Suspension', 'hl-core' ) . '</label>';
echo '<select name="suspended" style="min-width:160px;">';
echo '<option value="">' . esc_html__( 'All Users', 'hl-core' ) . '</option>';
echo '<option value="only"' . selected( $f_suspended, 'only', false ) . '>' . sprintf( esc_html__( 'Suspended Only (%d)', 'hl-core' ), $suspended_count ) . '</option>';
echo '<option value="exclude"' . selected( $f_suspended, 'exclude', false ) . '>' . esc_html__( 'Exclude Suspended', 'hl-core' ) . '</option>';
echo '</select></div>';
```

- [ ] **Step 2: Add suspension SQL to the enrollment list query**

Find where the WHERE clause is built for the enrollment list (around lines 340-365). Add after the existing filters:

Find where the WHERE clause is assembled (the `$wheres` array or similar). Add the suspension filter alongside existing filters. Then find where `$where_sql` is finalized and append for the exclude case:

```php
// Suspension filter.
$f_suspended = isset( $_GET['suspended'] ) ? sanitize_text_field( $_GET['suspended'] ) : '';
$suspend_extra_sql = '';
if ( $f_suspended === 'only' && HL_BuddyBoss_Integration::bp_suspend_table_exists() ) {
    $where_parts[] = "EXISTS (SELECT 1 FROM {$wpdb->prefix}bp_suspend WHERE item_type = 'user' AND item_id = e.user_id AND user_suspended = 1)";
} elseif ( $f_suspended === 'exclude' ) {
    $suspend_extra_sql = HL_BuddyBoss_Integration::get_suspend_not_exists_sql( 'e.user_id' );
}
```

Then where `$where_sql` is built (e.g., `$where_sql = !empty($wheres) ? ' WHERE ' . implode(' AND ', $wheres) : '';`), append:

```php
$where_sql .= $suspend_extra_sql;
```

This works because `get_suspend_not_exists_sql()` returns `" AND NOT EXISTS (...)"` with a leading AND, or empty string if BB table absent.

- [ ] **Step 3: Add "Suspended" badge next to display_name**

Find where `display_name` is rendered (around line 550):

```php
echo '<td><strong><a href="' . esc_url($edit_url) . '">' . esc_html($enrollment->display_name) . '</a></strong></td>';
```

Replace with:

```php
$suspended_badge = HL_BuddyBoss_Integration::is_user_suspended( (int) $enrollment->user_id )
    ? ' <span class="hl-status-badge suspended">' . esc_html__( 'Suspended', 'hl-core' ) . '</span>'
    : '';
echo '<td><strong><a href="' . esc_url($edit_url) . '">' . esc_html($enrollment->display_name) . '</a></strong>' . $suspended_badge . '</td>';
```

- [ ] **Step 4: Commit**

```bash
git add includes/admin/class-hl-admin-enrollments.php
git commit -m "feat(suspended-users): add Suspended badge + filter to admin enrollment list"
```

---

## Task 9: Admin — Assessments Badge

**Files:**
- Modify: `includes/admin/class-hl-admin-assessments.php` (lines 399, 572)

- [ ] **Step 1: Add badge to teacher assessment instance list**

Find line 399:

```php
echo '<td><a href="' . esc_url($user_edit_url) . '">' . esc_html($inst['display_name']) . '</a></td>';
```

Replace with:

```php
$suspended_badge = HL_BuddyBoss_Integration::is_user_suspended( (int) $inst['user_id'] )
    ? ' <span class="hl-status-badge suspended">' . esc_html__( 'Suspended', 'hl-core' ) . '</span>'
    : '';
echo '<td><a href="' . esc_url($user_edit_url) . '">' . esc_html($inst['display_name']) . '</a>' . $suspended_badge . '</td>';
```

- [ ] **Step 2: Add badge to child assessment instance list**

Find line 572 (same pattern):

```php
echo '<td><a href="' . esc_url($user_edit_url) . '">' . esc_html($inst['display_name']) . '</a></td>';
```

Replace with the same badge pattern (using `$inst['user_id']`).

- [ ] **Step 3: Commit**

```bash
git add includes/admin/class-hl-admin-assessments.php
git commit -m "feat(suspended-users): add Suspended badge to admin assessment lists"
```

---

## Task 10: Admin — Coaching Badge

**Files:**
- Modify: `includes/admin/class-hl-admin-coaching.php` (lines 470-471)

- [ ] **Step 1: Add badge to mentor and coach names in coaching session table**

Find lines 470-471:

```php
echo '<td>' . esc_html($session['mentor_name'] ?: '-') . '</td>';
echo '<td>' . esc_html($session['coach_name'] ?: '-') . '</td>';
```

For the mentor line, the user_id comes from the enrollment. Check if the query result includes a `mentor_user_id` or if it can be derived. Read the query at lines 411-417 to check available columns. If `e.user_id` is available as a column (it joins enrollment for the mentor), add the badge. If only display_name is available and no user_id, check `$session` array keys.

**First**, add `e.user_id AS mentor_user_id` to the coaching session query. Find the SELECT clause at lines 411-417:

```sql
SELECT cs.*, u_coach.display_name as coach_name, u_mentor.display_name as mentor_name, t.cycle_name
```

Change to:

```sql
SELECT cs.*, u_coach.display_name as coach_name, u_mentor.display_name as mentor_name, e.user_id AS mentor_user_id, t.cycle_name
```

Also check `includes/services/class-hl-coaching-service.php` — its `get_by_cycle()` method has a similar query. Add `e.user_id AS mentor_user_id` there too.

**Then**, update the mentor name rendering at line 470:

```php
$mentor_uid = isset($session['mentor_user_id']) ? (int) $session['mentor_user_id'] : 0;
$mentor_badge = $mentor_uid && HL_BuddyBoss_Integration::is_user_suspended($mentor_uid)
    ? ' <span class="hl-status-badge suspended">' . esc_html__('Suspended', 'hl-core') . '</span>'
    : '';
echo '<td>' . esc_html($session['mentor_name'] ?: '-') . $mentor_badge . '</td>';
```

- [ ] **Step 2: Commit**

```bash
git add includes/admin/class-hl-admin-coaching.php
git commit -m "feat(suspended-users): add Suspended badge to admin coaching sessions"
```

---

## Task 11: Deploy + Docs

- [ ] **Step 1: Update STATUS.md**

Add to the Build Queue:

```markdown
### Suspended User Handling (April 2026)
> **Spec:** `docs/superpowers/specs/2026-04-06-suspended-users-design.md` | **Plan:** `docs/superpowers/plans/2026-04-06-suspended-users.md`
- [x] **Central helper** — `HL_BuddyBoss_Integration::is_user_suspended()` + `get_suspend_not_exists_sql()` with per-request cache and graceful degradation.
- [x] **Frontend filtering** — Suspended users hidden from Learners, Team Page, Coach Mentors, Reports. User Profile blocked for non-admins.
- [x] **Admin badges** — Red "Suspended" badge on Enrollments, Assessments, Coaching pages.
- [x] **Admin filter** — Suspension filter dropdown on Enrollments list (All / Suspended Only / Exclude Suspended).
- [ ] **Deployed to test** — Pending.
```

- [ ] **Step 2: Update README.md**

- [ ] **Step 3: Commit docs**

- [ ] **Step 4: Deploy to test + production**

- [ ] **Step 5: Verify on production**

Run on production to confirm the 8 suspended-enrolled users are hidden from frontend:
```bash
ssh -p 65002 u665917738@145.223.76.150 "cd /home/u665917738/domains/academy.housmanlearning.com/public_html && wp eval '
    \$ids = [308, 312, 346, 329, 297, 347, 322, 350];
    foreach (\$ids as \$id) {
        \$s = HL_BuddyBoss_Integration::is_user_suspended(\$id);
        echo \"User \$id: \" . (\$s ? \"SUSPENDED\" : \"not suspended\") . \"\\n\";
    }
'"
```

Expected: All 8 show "SUSPENDED".

---

## Verification Checklist

- [ ] `HL_BuddyBoss_Integration::is_user_suspended()` returns true for known suspended user IDs (308, 312, etc.)
- [ ] `get_suspend_not_exists_sql()` returns empty string when `bp_suspend` table doesn't exist (graceful degradation)
- [ ] Learners page no longer shows suspended users (check user count before/after)
- [ ] Team Page members list excludes suspended users
- [ ] Coach Mentors page excludes suspended mentors
- [ ] Reports exclude suspended users from completion data
- [ ] User Profile of suspended user shows "This user is not available" for non-admins
- [ ] User Profile of suspended user is viewable by admins (manage_hl_core)
- [ ] Admin Enrollments shows red "Suspended" badge next to suspended users' names
- [ ] Admin Enrollments "Suspension" filter works: All / Suspended Only / Exclude Suspended
- [ ] Admin Assessments shows "Suspended" badge
- [ ] Admin Coaching shows "Suspended" badge on mentor names
- [ ] No errors when BuddyBoss is deactivated (graceful degradation)
