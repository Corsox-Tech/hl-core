# BB Group Sync — Design Spec (v2)

> **Ticket:** #11 — Automation Request: B2E Group Creation & Group Assignment
> **Date:** 2026-04-14 (revised 2026-04-15)
> **Status:** Implemented
> **Schema Revision:** 40

## Problem

For B2E Mastery partnerships, BuddyBoss group memberships are managed manually. When enrollments are created or modified, admins must manually add/remove users from the correct BuddyBoss groups. This creates operational overhead and risk of stale memberships.

## Solution

A new `HL_BB_Group_Sync_Service` that automatically syncs BuddyBoss group memberships when enrollments change or when coach/coaching_director WP roles are assigned/removed.

## Group Structure

Three types of BuddyBoss groups, all pre-existing on the site:

| Group | Scope | Mapping Storage | Member Type |
|-------|-------|-----------------|-------------|
| Global Community | Sitewide (one group) | Plugin setting: `hl_bb_global_community_group_id` | All B2E participants as members; coaches as moderators |
| Global Mentor | Sitewide (one group, subgroup of Global Community) | Plugin setting: `hl_bb_global_mentor_group_id` | Mentors as members; coaches as moderators |
| School Group | Per school | `bb_group_id` column on `hl_orgunit` | School's enrolled users as members; coaches as moderators |

## Scope

- Only enrollments in cycles where `cycle_type = program` trigger sync.
- `cycle_type = course` and archived cycles are excluded.
- Control group cycles with `cycle_type = program` ARE included (they're still B2E).

## Data Model Changes

### Schema Rev 40: `hl_orgunit` — new column

Uses ALTER TABLE (not dbDelta) in a revision-guarded block, matching the pattern used in Rev 24, 27, 28, 35:

```php
// In HL_Installer::maybe_upgrade(), after Rev 39 block:
if ( (int) $stored < 40 ) {
    self::migrate_orgunit_add_bb_group_id();
}
```

```php
private static function migrate_orgunit_add_bb_group_id() {
    global $wpdb;
    $table = $wpdb->prefix . 'hl_orgunit';
    $col_exists = $wpdb->get_var(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
         AND TABLE_NAME = '{$table}'
         AND COLUMN_NAME = 'bb_group_id'"
    );
    if ( ! $col_exists ) {
        $wpdb->query( "ALTER TABLE {$table} ADD COLUMN bb_group_id BIGINT UNSIGNED NULL" );
    }
}
```

### Plugin Settings (wp_options)

- `hl_bb_global_community_group_id` (int) — BuddyBoss group ID for Global Community
- `hl_bb_global_mentor_group_id` (int) — BuddyBoss group ID for Global Mentor

### `coaching_director` WP Role Registration

The `coaching_director` role does not exist in the codebase. Must register in `HL_Installer::create_roles()`:

```php
if ( ! get_role( 'coaching_director' ) ) {
    add_role( 'coaching_director', 'Coaching Director', array(
        'read'           => true,
        'manage_hl_core' => true,
    ));
}
```

## Service: `HL_BB_Group_Sync_Service`

File: `includes/services/class-hl-bb-group-sync-service.php`

### BB Availability Guard (centralized)

Single check replaces 6+ scattered `function_exists()` calls:

```php
public static function is_bb_groups_available(): bool {
    return class_exists( 'BP_Groups_Member' )
        && function_exists( 'groups_get_groups' );
}
```

### `sync_user_groups( int $user_id ): void`

Full recompute of a participant's group memberships:

1. Guard: `if ( ! self::is_bb_groups_available() ) return;`
2. If bulk mode active, defer: add `$user_id` to deferred set and return
3. Query all active enrollments for `$user_id` where:
   - Cycle `cycle_type = program`
   - Cycle `status != archived`
   - Enrollment `status = active`
4. From those enrollments, derive:
   - `$has_any_enrollment` — boolean, qualifies for Global Community
   - `$school_bb_group_ids` — set of `bb_group_id` values from each enrollment's `school_id` → `hl_orgunit.bb_group_id` (skip nulls)
   - `$is_mentor` — boolean, true if any enrollment has `mentor` role (via `HL_Roles::has_role()`)
5. Build the "should be in" set:
   - If `$has_any_enrollment`: add Global Community group ID (if configured and > 0)
   - If `$is_mentor`: add Global Mentor group ID (if configured and > 0)
   - Add all `$school_bb_group_ids`
6. Build the managed set via `get_managed_group_ids()` — we only touch groups in this set
7. Fetch user's current BB group memberships filtered to managed groups only
8. Diff:
   - **Add** to groups in "should be in" but not currently a member — use `BP_Groups_Member` directly
   - **Remove** from groups currently a member but not in "should be in" AND in the managed set
9. Log each add/remove via `HL_Audit_Service`

Important: the "remove" step only touches groups that are within our managed set. We never remove a user from an unrelated BB group.

### `sync_coach_groups( int $user_id ): void`

Sync moderator status for coaches:

1. Guard: `if ( ! self::is_bb_groups_available() ) return;`
2. Check if user has `coach` or `coaching_director` WP role
3. Build the "all managed groups" set via `get_managed_group_ids()`
4. If user IS a coach/coaching_director:
   - For each managed group: ensure user is a member first (via `add_member()`), then promote to moderator (via `promote_to_mod()`)
   - Skip if already moderator
5. If user is NOT a coach (role was removed):
   - Demote from moderator in all managed groups (via `demote_from_mod()`)
   - **Then call `sync_user_groups( $user_id )`** to re-add as regular member if qualifying enrollments exist, or remove entirely if none
6. Log via `HL_Audit_Service`

### `sync_users_in_group( int $bb_group_id ): void`

Resync all users who should be in a specific BB group. Used when a school's `bb_group_id` changes:

1. Guard: `if ( ! self::is_bb_groups_available() ) return;`
2. Query all users with active enrollments at the school(s) mapped to this `bb_group_id`
3. For each user, call `sync_user_groups( $user_id )`

### Helper: `get_managed_group_ids(): array`

Returns the full set of BB group IDs we manage. **Cached per request** via static property:

```php
private static $managed_group_ids_cache = null;

public static function get_managed_group_ids(): array {
    if ( self::$managed_group_ids_cache !== null ) {
        return self::$managed_group_ids_cache;
    }

    $ids = array();
    $global_community = (int) get_option( 'hl_bb_global_community_group_id', 0 );
    $global_mentor    = (int) get_option( 'hl_bb_global_mentor_group_id', 0 );
    if ( $global_community > 0 ) $ids[] = $global_community;
    if ( $global_mentor > 0 )    $ids[] = $global_mentor;

    global $wpdb;
    $school_ids = $wpdb->get_col(
        "SELECT DISTINCT bb_group_id FROM {$wpdb->prefix}hl_orgunit
         WHERE bb_group_id IS NOT NULL AND bb_group_id > 0"
    );
    foreach ( $school_ids as $sid ) {
        $ids[] = (int) $sid;
    }

    self::$managed_group_ids_cache = array_unique( $ids );
    return self::$managed_group_ids_cache;
}

public static function invalidate_cache(): void {
    self::$managed_group_ids_cache = null;
}
```

### Helper: `get_bb_groups_dropdown(): array`

Returns all BB groups for admin dropdowns, with correct pagination and hidden group inclusion:

```php
public static function get_bb_groups_dropdown(): array {
    if ( ! self::is_bb_groups_available() ) {
        return array();
    }
    $results = groups_get_groups( array(
        'per_page'    => 0,       // All groups (default is 20)
        'show_hidden' => true,    // Include private/hidden groups
        'orderby'     => 'name',
        'order'       => 'ASC',
    ) );
    $options = array();
    if ( ! empty( $results['groups'] ) ) {
        foreach ( $results['groups'] as $group ) {
            $options[ $group->id ] = sprintf( '%s (ID: %d)', $group->name, $group->id );
        }
    }
    return $options;
}
```

### Membership Operations — BP_Groups_Member

All membership operations use `BP_Groups_Member` directly instead of `groups_join_group()`, because `groups_join_group()` respects group privacy settings and silently fails for private/hidden groups.

```php
private static function add_member( int $user_id, int $group_id ): bool {
    $group = groups_get_group( $group_id );
    if ( empty( $group->id ) ) {
        // Group deleted — log warning, don't crash
        HL_Audit_Service::log( 'bb_group_sync.warning', array(
            'entity_type' => 'bb_group',
            'entity_id'   => $group_id,
            'reason'      => "BB group {$group_id} not found",
        ));
        return false;
    }
    if ( groups_is_user_member( $user_id, $group_id ) ) {
        return true; // Already a member
    }
    $member                = new BP_Groups_Member();
    $member->group_id      = $group_id;
    $member->user_id       = $user_id;
    $member->is_confirmed  = 1;
    $member->date_modified = bp_core_current_time();
    $member->inviter_id    = 0;
    return (bool) $member->save();
}

private static function remove_member( int $user_id, int $group_id ): bool {
    if ( ! groups_is_user_member( $user_id, $group_id ) ) {
        return true; // Not a member, nothing to do
    }
    return (bool) groups_leave_group( $group_id, $user_id );
}

private static function promote_to_mod( int $user_id, int $group_id ): bool {
    if ( ! groups_is_user_member( $user_id, $group_id ) ) {
        if ( ! self::add_member( $user_id, $group_id ) ) {
            return false; // Can't add, can't promote
        }
    }
    if ( groups_is_user_mod( $user_id, $group_id )
      || groups_is_user_admin( $user_id, $group_id ) ) {
        return true; // Already mod/admin
    }
    return (bool) groups_promote_member( $user_id, $group_id, 'mod' );
}

private static function demote_from_mod( int $user_id, int $group_id ): bool {
    if ( ! groups_is_user_mod( $user_id, $group_id ) ) {
        return true; // Not a mod
    }
    return (bool) groups_demote_member( $user_id, $group_id );
}
```

### Batch/Defer for Bulk Imports

Prevents N full recomputes during bulk imports:

```php
private static $deferred_user_ids = null;

public static function begin_bulk(): void {
    self::$deferred_user_ids = array();
}

public static function end_bulk(): void {
    if ( self::$deferred_user_ids === null ) return;
    $user_ids = array_unique( self::$deferred_user_ids );
    self::$deferred_user_ids = null;
    foreach ( $user_ids as $uid ) {
        self::sync_user_groups( $uid );
    }
}
```

When bulk mode is active, `sync_user_groups()` adds `$user_id` to the deferred set instead of syncing immediately. The import handler wraps its batch:

```php
// In HL_Import_Participant_Handler::commit_import()
HL_BB_Group_Sync_Service::begin_bulk();
try {
    // ... existing import loop ...
} finally {
    HL_BB_Group_Sync_Service::end_bulk();
}
```

## Hooks & Triggers

### Prerequisite: Admin Enrollment Handlers — Route Through Service

**Critical:** `HL_Admin_Enrollments::handle_actions()` and `handle_delete()` currently use `$wpdb->update()` and `$wpdb->delete()` directly, bypassing the service layer. These are the most common admin paths. They must be routed through `HL_Enrollment_Service` so hooks fire consistently:

```php
// handle_actions() — updates:
$service = new HL_Enrollment_Service();
$service->update_enrollment( $enrollment_id, $data );

// handle_delete():
$service = new HL_Enrollment_Service();
$service->delete_enrollment( $enrollment_id );
```

### Enrollment Service Layer Changes

**`delete_enrollment()` — add hook with null guard:**

```php
public function delete_enrollment( $enrollment_id ) {
    $enrollment = $this->repository->get_by_id( $enrollment_id );
    if ( ! $enrollment ) {
        return false;
    }
    $user_id = (int) $enrollment->user_id;
    $result  = $this->repository->delete( $enrollment_id );
    if ( $result !== false && $user_id > 0 ) {
        do_action( 'hl_enrollment_deleted', $enrollment_id, $user_id );
    }
    return $result;
}
```

**`update_enrollment()` — guard hook on success only:**

```php
public function update_enrollment( $enrollment_id, $data ) {
    $result = $this->repository->update( $enrollment_id, $data );
    if ( $result !== false ) {
        do_action( 'hl_enrollment_updated', $enrollment_id, $data );
    }
    return $result;
}
```

### Enrollment Hook Registration

| Hook | Priority | Callback | Accepted Args |
|------|----------|----------|---------------|
| `hl_enrollment_created` | 25 | `on_enrollment_changed( $enrollment_id, $data )` | 2 |
| `hl_enrollment_updated` | 25 | `on_enrollment_changed( $enrollment_id, $data )` | 2 |
| `hl_enrollment_deleted` | 25 | `on_enrollment_deleted( $enrollment_id, $user_id )` | 2 |

**`on_enrollment_changed()`** — loads enrollment to get `user_id`. Does NOT bail on `cycle_type` — `sync_user_groups()` handles filtering internally. Bailing here creates a blind spot when an enrollment moves from a `program` cycle to a `course` cycle (user would remain in old groups).

```php
public function on_enrollment_changed( $enrollment_id, $data ) {
    if ( ! self::is_bb_groups_available() ) return;
    $enrollment = ( new HL_Enrollment_Repository() )->get_by_id( $enrollment_id );
    if ( ! $enrollment ) return;
    self::sync_user_groups( (int) $enrollment->user_id );
}
```

**`on_enrollment_deleted()`** — receives `$user_id` directly since enrollment row is gone:

```php
public function on_enrollment_deleted( $enrollment_id, $user_id ) {
    if ( ! self::is_bb_groups_available() ) return;
    if ( (int) $user_id <= 0 ) return;
    self::sync_user_groups( (int) $user_id );
}
```

### WP Role Change Hooks

| Hook | Callback | Accepted Args |
|------|----------|---------------|
| `set_user_role` | `on_role_changed( $user_id, $new_role, $old_roles )` | **3** |
| `add_user_role` | `on_role_added( $user_id, $role )` | **2** |
| `remove_user_role` | `on_role_removed( $user_id, $role )` | **2** |

**`on_role_changed()`** must check BOTH `$new_role` AND `$old_roles`. When changing FROM coach TO subscriber, `$new_role = 'subscriber'` — checking only `$new_role` misses the demotion:

```php
public function on_role_changed( $user_id, $new_role, $old_roles ) {
    if ( ! self::is_bb_groups_available() ) return;
    $coach_roles = array( 'coach', 'coaching_director' );
    $was_coach   = ! empty( array_intersect( $coach_roles, (array) $old_roles ) );
    $is_coach    = in_array( $new_role, $coach_roles, true );
    if ( $was_coach || $is_coach ) {
        self::sync_coach_groups( (int) $user_id );
    }
}
```

**`on_role_added()` / `on_role_removed()`** — straightforward check:

```php
public function on_role_added( $user_id, $role ) {
    if ( ! self::is_bb_groups_available() ) return;
    if ( in_array( $role, array( 'coach', 'coaching_director' ), true ) ) {
        self::sync_coach_groups( (int) $user_id );
    }
}

public function on_role_removed( $user_id, $role ) {
    if ( ! self::is_bb_groups_available() ) return;
    if ( in_array( $role, array( 'coach', 'coaching_director' ), true ) ) {
        self::sync_coach_groups( (int) $user_id );
    }
}
```

Note: `set_user_role` fires when `WP_User::set_role()` replaces all roles. `remove_user_role` fires when `WP_User::remove_role()` removes a single role. Both patterns are handled.

### Guard Rails

- **BuddyBoss not active:** All methods call `self::is_bb_groups_available()`. Single centralized check.
- **Settings not configured:** If Global Community or Global Mentor group IDs are empty/zero, skip those groups. Log a warning via `HL_Audit_Service` on first occurrence per request.
- **School has no `bb_group_id`:** Skip that school's group silently.
- **BB group deleted:** `add_member()` checks group existence first. Returns false and logs a warning. Never crashes.
- **Hook callbacks wrapped in try/catch:** BP failures must never propagate to the caller.
- **Concurrency:** Full-recompute is idempotent — two concurrent syncs converge to the same state. WP-CLI resync provides repair for any edge-case drift.

## Audit Log Format

Follows existing codebase conventions:

```php
// Member added:
HL_Audit_Service::log( 'bb_group_sync.member_added', array(
    'entity_type' => 'user',
    'entity_id'   => $user_id,
    'after_data'  => array( 'bb_group_id' => $group_id, 'role' => 'member' ),
));

// Member removed:
HL_Audit_Service::log( 'bb_group_sync.member_removed', array(
    'entity_type' => 'user',
    'entity_id'   => $user_id,
    'before_data' => array( 'bb_group_id' => $group_id, 'role' => 'member' ),
));

// Moderator promoted:
HL_Audit_Service::log( 'bb_group_sync.mod_promoted', array(
    'entity_type' => 'user',
    'entity_id'   => $user_id,
    'after_data'  => array( 'bb_group_id' => $group_id, 'role' => 'mod' ),
));

// Moderator demoted:
HL_Audit_Service::log( 'bb_group_sync.mod_demoted', array(
    'entity_type' => 'user',
    'entity_id'   => $user_id,
    'before_data' => array( 'bb_group_id' => $group_id, 'role' => 'mod' ),
    'after_data'  => array( 'bb_group_id' => $group_id, 'role' => 'member' ),
));

// Warning (e.g., deleted group):
HL_Audit_Service::log( 'bb_group_sync.warning', array(
    'entity_type' => 'bb_group',
    'entity_id'   => $group_id,
    'reason'      => 'BB group not found — may have been deleted',
));
```

## Admin UI

### Settings Page — New Tab: "BuddyBoss Groups"

Added as a new tab on `HL_Admin_Settings` (alongside Scheduling, Email Templates, Tours, etc.).

If BuddyBoss is not active, show a notice: "BuddyBoss Platform must be active to configure group sync."

Otherwise, two dropdowns populated via `HL_BB_Group_Sync_Service::get_bb_groups_dropdown()`:

- **Global Community Group** → saves `hl_bb_global_community_group_id`
- **Global Mentor Group** → saves `hl_bb_global_mentor_group_id`

Each dropdown shows "Group Name (ID: N)". Includes "— None (disabled) —" blank option.

**Help text:** "These settings control automatic BuddyBoss group membership. When configured, users are automatically added/removed from groups based on their B2E enrollments. Coaches and Coaching Directors are added as group moderators."

**Validation:** Warn (not block) if both dropdowns point to the same group ID. Nonce + `manage_hl_core` capability check on save. Call `HL_BB_Group_Sync_Service::invalidate_cache()` after saving.

### OrgUnit Edit Form — "BuddyBoss Group" Field

Added to `HL_Admin_OrgUnits` edit form, rendered server-side only when `orgunit_type = school`:

- **BuddyBoss Group** dropdown → saves `bb_group_id` on the `hl_orgunit` row
- Includes "— None —" blank option
- Dropdown entries show "Group Name (ID: N)"

**BB inactive:** Show disabled field with notice "BuddyBoss Group mapping is unavailable (BuddyBoss not active)".

**Stale group warning:** If stored `bb_group_id` is non-null but not found in dropdown options, show: "Warning: The saved group (ID: {id}) was not found in BuddyBoss. It may have been deleted."

**Duplicate warning:** If selected `bb_group_id` is already assigned to another orgunit, show warning on save: "Warning: This BuddyBoss group is already assigned to [Other School Name]."

**Save handler change:** Add `bb_group_id` to the `$data` array in `handle_actions()`:

```php
'bb_group_id' => ! empty( $_POST['bb_group_id'] ) ? absint( $_POST['bb_group_id'] ) : null,
```

**Group reassignment handling:** When `bb_group_id` changes on an existing orgunit, detect the change and resync:

```php
// Read old value before save
$old_bb_group_id = (int) $old_orgunit->bb_group_id;
// ... save ...
$new_bb_group_id = (int) ( $data['bb_group_id'] ?? 0 );
if ( $old_bb_group_id !== $new_bb_group_id ) {
    HL_BB_Group_Sync_Service::invalidate_cache();
    if ( $old_bb_group_id > 0 ) {
        HL_BB_Group_Sync_Service::sync_users_in_group( $old_bb_group_id );
    }
    if ( $new_bb_group_id > 0 ) {
        HL_BB_Group_Sync_Service::sync_users_in_group( $new_bb_group_id );
    }
}
```

## WP-CLI Command: `wp hl bb-sync`

File: `includes/cli/class-hl-cli-bb-sync.php`

Essential for initial deployment backfill and drift repair.

```
wp hl bb-sync --all            # Sync all users + coaches
wp hl bb-sync --all --dry-run  # Preview what would change
wp hl bb-sync --user=42        # Sync a single user
wp hl bb-sync --coaches        # Sync coach moderator status only
```

- `--all` iterates all users with active program enrollments + all coaches
- `--dry-run` logs what would change without applying
- `--user=<id>` syncs both participant and coach status for one user
- `--coaches` syncs only coach/coaching_director moderator assignments

Register in `hl-core.php` alongside existing CLI commands.

## Edge Cases

| Scenario | Behavior |
|----------|----------|
| User enrolled in 2 schools across cycles | Added to both school groups |
| One of two enrollments deactivated | Stays in school group if other enrollment qualifies |
| User is both coach and participant | Coach sync makes them moderator; if coach role removed, `sync_coach_groups()` demotes then calls `sync_user_groups()` to re-add as regular member |
| Mentor role added mid-cycle | `hl_enrollment_updated` fires → recompute adds to Global Mentor |
| Mentor role removed mid-cycle | Recompute removes from Global Mentor (if no other mentor enrollment) |
| All B2E enrollments deactivated | Removed from all managed groups |
| Enrollment moved from program to course cycle | `on_enrollment_changed()` does NOT bail on cycle_type; recompute removes from groups |
| Stored `bb_group_id` points to deleted BB group | `add_member()` checks existence, logs warning, no crash |
| BuddyBoss plugin deactivated | All hooks no-op via `is_bb_groups_available()` |
| School `bb_group_id` changed from A to B | OrgUnit save detects change, resyncs users in both groups A and B |
| Admin edits enrollment via WP admin | Routed through `HL_Enrollment_Service` — hooks fire correctly |
| Admin deletes enrollment via WP admin | Routed through `HL_Enrollment_Service::delete_enrollment()` — hook fires |
| Bulk import of 200 enrollments | `begin_bulk()` / `end_bulk()` defers; each unique user synced once at end |
| Concurrent sync calls for same user | Idempotent recompute converges; CLI resync repairs any drift |
| Same BB group for Community and Mentor settings | Admin warning on save; save proceeds, behavior documented as unsupported |
| Coach role changed via `set_role()` (replaces all roles) | `on_role_changed()` checks `$old_roles` array — demotion detected |

## Files Changed / Created

| File | Action |
|------|--------|
| `includes/services/class-hl-bb-group-sync-service.php` | **Create** — service with sync logic, hooks, guards, bulk defer, BP_Groups_Member ops |
| `includes/services/class-hl-enrollment-service.php` | **Modify** — add `hl_enrollment_deleted` action, guard `hl_enrollment_updated` on `$result !== false` |
| `includes/admin/class-hl-admin-enrollments.php` | **Modify** — route update/delete through `HL_Enrollment_Service` instead of direct `$wpdb` |
| `includes/admin/class-hl-admin-settings.php` | **Modify** — add `bb_groups` tab to tab list, early action handler, render switch |
| `includes/admin/class-hl-admin-bb-groups-settings.php` | **Create** — settings page content for BB Groups tab |
| `includes/admin/class-hl-admin-orgunits.php` | **Modify** — add `bb_group_id` to `$data` array, change detection + resync, dropdown field |
| `includes/domain/class-hl-orgunit.php` | **Modify** — add `bb_group_id` property |
| `includes/cli/class-hl-cli-bb-sync.php` | **Create** — WP-CLI resync command |
| `hl-core.php` | **Modify** — instantiate service, register hooks, register CLI command |
| `includes/class-hl-installer.php` | **Modify** — Rev 40 migration, register `coaching_director` role |
| `includes/services/class-hl-import-participant-handler.php` | **Modify** — wrap import loop in `begin_bulk()` / `end_bulk()` |

## Deployment Checklist

1. Deploy code to test server
2. Run `wp hl bb-sync --all --dry-run` to preview changes
3. Configure settings: set Global Community and Global Mentor group IDs in Settings → BuddyBoss Groups
4. Map school orgunits to their BB groups via Org Units → Edit School → BuddyBoss Group
5. Run `wp hl bb-sync --all` to backfill all existing enrollments
6. Run `wp hl bb-sync --coaches` to set up coach moderator status
7. Verify via BuddyBoss admin: spot-check group memberships
8. Monitor audit log for `bb_group_sync.*` entries
