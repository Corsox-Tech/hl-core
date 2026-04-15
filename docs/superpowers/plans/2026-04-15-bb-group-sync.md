# BB Group Sync Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Automatically sync BuddyBoss group memberships when B2E enrollments change or coach roles are assigned/removed.

**Architecture:** New `HL_BB_Group_Sync_Service` hooks into enrollment and WP role change events. Uses `BP_Groups_Member` directly for reliable group operations (private/hidden safe). Full recompute on every change — idempotent by design. Bulk defer for imports. WP-CLI for backfill/repair.

**Tech Stack:** PHP 7.4+, WordPress 6.0+, BuddyBoss Platform (BP_Groups_Member API), WP-CLI

**Spec:** `docs/superpowers/specs/2026-04-14-bb-group-sync-design.md`

---

### Task 1: Schema, Domain Model & Role Registration

**Files:**
- Modify: `includes/class-hl-installer.php` (lines 150-265 for `maybe_upgrade()`, lines 2218-2246 for `create_capabilities()`)
- Modify: `includes/domain/class-hl-orgunit.php` (add `bb_group_id` property)

- [x] **Step 1: Add `bb_group_id` property to `HL_OrgUnit`**

In `includes/domain/class-hl-orgunit.php`, add the property after `$metadata`:

```php
public $metadata;
public $bb_group_id;
public $created_at;
```

The constructor uses `property_exists()` to hydrate — declaring the property is all that's needed.

- [x] **Step 2: Add Rev 40 migration to installer**

In `includes/class-hl-installer.php`, change the revision constant from 39 to 40:

```php
$current_revision = 40;
```

After the Rev 39 block (`migrate_workflow_trigger_offset()`), add:

```php
// Rev 40: BB group sync — add bb_group_id to hl_orgunit
if ( (int) $stored < 40 ) {
    self::migrate_orgunit_add_bb_group_id();
}
```

Add the migration method at the end of the class (before the closing `}`):

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

- [x] **Step 3: Register `coaching_director` role**

In `includes/class-hl-installer.php`, inside `create_capabilities()` after the `coach` role block, add:

```php
// Create Coaching Director role if it doesn't exist
if ( ! get_role( 'coaching_director' ) ) {
    add_role( 'coaching_director', __( 'Coaching Director', 'hl-core' ), array(
        'read'               => true,
        'manage_hl_core'     => true,
        'hl_view_cohorts'    => true,
        'hl_view_enrollments'=> true,
    ));
} else {
    $cd_role = get_role( 'coaching_director' );
    $cd_role->add_cap( 'manage_hl_core' );
    $cd_role->add_cap( 'hl_view_cohorts' );
    $cd_role->add_cap( 'hl_view_enrollments' );
}
```

- [x] **Step 4: Commit**

```
git add includes/class-hl-installer.php includes/domain/class-hl-orgunit.php
git commit -m "feat(bb-sync): schema rev 40 — bb_group_id column + coaching_director role"
```

---

### Task 2: Enrollment Service Layer Fixes

**Files:**
- Modify: `includes/services/class-hl-enrollment-service.php` (lines 40-48)
- Modify: `includes/admin/class-hl-admin-enrollments.php` (lines 222-232 for update, lines 319-320 for delete)

- [x] **Step 1: Fix `update_enrollment()` — guard hook on success**

In `includes/services/class-hl-enrollment-service.php`, replace the `update_enrollment` method (lines 40-44):

```php
public function update_enrollment($enrollment_id, $data) {
    $result = $this->repository->update($enrollment_id, $data);
    if ($result !== false) {
        do_action('hl_enrollment_updated', $enrollment_id, $data);
    }
    return $result;
}
```

- [x] **Step 2: Add `delete_enrollment()` hook with null guard**

Replace the `delete_enrollment` method (lines 46-48):

```php
public function delete_enrollment($enrollment_id) {
    $enrollment = $this->repository->get_by_id($enrollment_id);
    if (!$enrollment) {
        return false;
    }
    $user_id = (int) $enrollment->user_id;
    $result = $this->repository->delete($enrollment_id);
    if ($result !== false && $user_id > 0) {
        do_action('hl_enrollment_deleted', $enrollment_id, $user_id);
    }
    return $result;
}
```

- [x] **Step 3: Route admin enrollment UPDATE through service**

In `includes/admin/class-hl-admin-enrollments.php`, find the update block inside `handle_actions()` (around line 222). Replace the direct `$wpdb->update()` call:

```php
// BEFORE:
// $wpdb->update($wpdb->prefix . 'hl_enrollment', $data, array('enrollment_id' => $enrollment_id));

// AFTER:
$service = new HL_Enrollment_Service();
$service->update_enrollment($enrollment_id, $data);
```

The redirect logic after remains unchanged.

- [x] **Step 4: Route admin enrollment DELETE through service**

In `includes/admin/class-hl-admin-enrollments.php`, find `handle_delete()` (around line 319). Replace the direct `$wpdb->delete()` call:

```php
// BEFORE:
// global $wpdb;
// $wpdb->delete($wpdb->prefix . 'hl_enrollment', array('enrollment_id' => $enrollment_id));

// AFTER:
$service = new HL_Enrollment_Service();
$service->delete_enrollment($enrollment_id);
```

The nonce check, capability check, and redirect logic remain unchanged.

- [x] **Step 5: Commit**

```
git add includes/services/class-hl-enrollment-service.php includes/admin/class-hl-admin-enrollments.php
git commit -m "fix(enrollments): route admin update/delete through service layer for hook consistency"
```

---

### Task 3: BB Group Sync Service — Core

**Files:**
- Create: `includes/services/class-hl-bb-group-sync-service.php`

This is the largest file. It contains: availability guard, managed group cache, dropdown helper, BP_Groups_Member operations, sync logic, hook callbacks, and batch defer.

- [x] **Step 1: Create the service class with guards and helpers**

Create `includes/services/class-hl-bb-group-sync-service.php` with all static helpers — `is_bb_groups_available()`, `get_managed_group_ids()`, `invalidate_cache()`, `get_bb_groups_dropdown()`. See spec sections "BB Availability Guard", "Helper: get_managed_group_ids()", and "Helper: get_bb_groups_dropdown()".

```php
<?php
/**
 * BuddyBoss Group Sync Service.
 *
 * Automatically syncs BB group memberships based on B2E enrollments
 * and coach/coaching_director WP roles.
 *
 * @package HL_Core
 */
class HL_BB_Group_Sync_Service {

    /** @var int[]|null Cached managed group IDs, null = not loaded */
    private static $managed_group_ids_cache = null;

    /** @var int[]|null Deferred user IDs for bulk mode, null = not in bulk */
    private static $deferred_user_ids = null;

    /**
     * Check if BuddyBoss Groups API is available.
     */
    public static function is_bb_groups_available(): bool {
        return class_exists( 'BP_Groups_Member' )
            && function_exists( 'groups_get_groups' );
    }

    /**
     * Get all BB group IDs we manage (globals + school groups).
     * Cached per request.
     */
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

    /**
     * Invalidate the managed group IDs cache.
     */
    public static function invalidate_cache(): void {
        self::$managed_group_ids_cache = null;
    }

    /**
     * Get all BB groups as dropdown options.
     * Returns [ group_id => "Name (ID: N)" ].
     */
    public static function get_bb_groups_dropdown(): array {
        if ( ! self::is_bb_groups_available() ) {
            return array();
        }
        $results = groups_get_groups( array(
            'per_page'    => 9999,  // Not 0 — some BP versions treat 0 as default (20)
            'show_hidden' => true,
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

    // --- Membership Operations (BP_Groups_Member) ---

    private static function add_member( int $user_id, int $group_id ): bool {
        $group = groups_get_group( $group_id );
        if ( empty( $group->id ) ) {
            try {
                HL_Audit_Service::log( 'bb_group_sync.warning', array(
                    'entity_type' => 'bb_group',
                    'entity_id'   => $group_id,
                    'reason'      => "BB group {$group_id} not found",
                ));
            } catch ( \Exception $e ) { /* audit failure is non-fatal */ }
            return false;
        }
        if ( groups_is_user_member( $user_id, $group_id ) ) {
            return true;
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
            return true;
        }
        // Use BP_Groups_Member::delete() directly for consistency with add_member()
        // (groups_leave_group() may trigger notifications or permission checks)
        return (bool) BP_Groups_Member::delete( $user_id, $group_id );
    }

    private static function promote_to_mod( int $user_id, int $group_id ): bool {
        if ( ! groups_is_user_member( $user_id, $group_id ) ) {
            if ( ! self::add_member( $user_id, $group_id ) ) {
                return false;
            }
        }
        if ( groups_is_user_mod( $user_id, $group_id )
          || groups_is_user_admin( $user_id, $group_id ) ) {
            return true;
        }
        return (bool) groups_promote_member( $user_id, $group_id, 'mod' );
    }

    private static function demote_from_mod( int $user_id, int $group_id ): bool {
        if ( ! groups_is_user_mod( $user_id, $group_id ) ) {
            return true;
        }
        return (bool) groups_demote_member( $user_id, $group_id );
    }

    // --- Bulk/Defer ---

    public static function begin_bulk(): void {
        self::$deferred_user_ids = array();
    }

    public static function end_bulk(): void {
        if ( self::$deferred_user_ids === null ) return;
        $user_ids = array_unique( self::$deferred_user_ids );
        self::$deferred_user_ids = null;
        foreach ( $user_ids as $uid ) {
            self::sync_user_groups( (int) $uid );
        }
    }

    // --- Core Sync Methods ---

    /**
     * Full recompute of a participant's BB group memberships.
     */
    public static function sync_user_groups( int $user_id ): void {
        if ( ! self::is_bb_groups_available() ) return;

        // Bulk mode: defer
        if ( self::$deferred_user_ids !== null ) {
            self::$deferred_user_ids[] = $user_id;
            return;
        }

        global $wpdb;

        // 1. Query active program enrollments
        $enrollments = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.roles, e.school_id, o.bb_group_id
             FROM {$wpdb->prefix}hl_enrollment e
             JOIN {$wpdb->prefix}hl_cycle c ON c.cycle_id = e.cycle_id
             LEFT JOIN {$wpdb->prefix}hl_orgunit o ON o.orgunit_id = e.school_id
             WHERE e.user_id = %d
             AND e.status = 'active'
             AND c.cycle_type = 'program'
             AND c.status != 'archived'",
            $user_id
        ) );

        // 2. Derive what the user should be in
        $should_be_in = array();
        $has_any = ! empty( $enrollments );
        $is_mentor = false;

        $global_community = (int) get_option( 'hl_bb_global_community_group_id', 0 );
        $global_mentor    = (int) get_option( 'hl_bb_global_mentor_group_id', 0 );

        if ( $has_any && $global_community > 0 ) {
            $should_be_in[] = $global_community;
        }

        foreach ( $enrollments as $e ) {
            if ( class_exists( 'HL_Roles' ) && HL_Roles::has_role( $e->roles, 'mentor' ) ) {
                $is_mentor = true;
            }
            if ( ! empty( $e->bb_group_id ) && (int) $e->bb_group_id > 0 ) {
                $should_be_in[] = (int) $e->bb_group_id;
            }
        }

        if ( $is_mentor && $global_mentor > 0 ) {
            $should_be_in[] = $global_mentor;
        }

        $should_be_in = array_unique( $should_be_in );

        // 3. Get managed set and current memberships (filtered to managed only)
        $managed = self::get_managed_group_ids();
        if ( empty( $managed ) ) return;

        $current_in = array();
        foreach ( $managed as $gid ) {
            if ( groups_is_user_member( $user_id, $gid ) ) {
                $current_in[] = $gid;
            }
        }

        // 4. Diff: add missing, remove stale
        $to_add    = array_diff( $should_be_in, $current_in );
        $to_remove = array_diff( $current_in, $should_be_in );

        foreach ( $to_add as $gid ) {
            try {
                if ( self::add_member( $user_id, $gid ) ) {
                    HL_Audit_Service::log( 'bb_group_sync.member_added', array(
                        'entity_type' => 'user',
                        'entity_id'   => $user_id,
                        'after_data'  => array( 'bb_group_id' => $gid, 'role' => 'member' ),
                    ));
                }
            } catch ( \Exception $e ) { /* non-fatal */ }
        }

        foreach ( $to_remove as $gid ) {
            try {
                if ( self::remove_member( $user_id, $gid ) ) {
                    HL_Audit_Service::log( 'bb_group_sync.member_removed', array(
                        'entity_type' => 'user',
                        'entity_id'   => $user_id,
                        'before_data' => array( 'bb_group_id' => $gid, 'role' => 'member' ),
                    ));
                }
            } catch ( \Exception $e ) { /* non-fatal */ }
        }
    }

    /**
     * Sync moderator status for coaches/coaching_directors.
     * When demoting, also calls sync_user_groups() to restore regular membership.
     */
    public static function sync_coach_groups( int $user_id ): void {
        if ( ! self::is_bb_groups_available() ) return;

        $user = get_userdata( $user_id );
        if ( ! $user ) return;

        $coach_roles = array( 'coach', 'coaching_director' );
        $is_coach    = ! empty( array_intersect( $coach_roles, (array) $user->roles ) );
        $managed     = self::get_managed_group_ids();

        if ( $is_coach ) {
            foreach ( $managed as $gid ) {
                try {
                    // Skip if already mod/admin — avoid noisy audit logs
                    if ( groups_is_user_mod( $user_id, $gid )
                      || groups_is_user_admin( $user_id, $gid ) ) {
                        continue;
                    }
                    if ( self::promote_to_mod( $user_id, $gid ) ) {
                        HL_Audit_Service::log( 'bb_group_sync.mod_promoted', array(
                            'entity_type' => 'user',
                            'entity_id'   => $user_id,
                            'after_data'  => array( 'bb_group_id' => $gid, 'role' => 'mod' ),
                        ));
                    }
                } catch ( \Exception $e ) { /* non-fatal */ }
            }
        } else {
            // Demote from all managed groups
            foreach ( $managed as $gid ) {
                try {
                    if ( groups_is_user_mod( $user_id, $gid ) ) {
                        self::demote_from_mod( $user_id, $gid );
                        HL_Audit_Service::log( 'bb_group_sync.mod_demoted', array(
                            'entity_type' => 'user',
                            'entity_id'   => $user_id,
                            'before_data' => array( 'bb_group_id' => $gid, 'role' => 'mod' ),
                            'after_data'  => array( 'bb_group_id' => $gid, 'role' => 'member' ),
                        ));
                    }
                } catch ( \Exception $e ) { /* non-fatal */ }
            }
            // Re-add as regular member if they have qualifying enrollments
            self::sync_user_groups( $user_id );
        }
    }

    /**
     * Resync all users in a specific BB group.
     * Used when a school's bb_group_id changes.
     */
    public static function sync_users_in_group( int $bb_group_id ): void {
        if ( ! self::is_bb_groups_available() ) return;

        global $wpdb;

        // Find all users enrolled at schools mapped to this group
        $user_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT e.user_id
             FROM {$wpdb->prefix}hl_enrollment e
             JOIN {$wpdb->prefix}hl_cycle c ON c.cycle_id = e.cycle_id
             JOIN {$wpdb->prefix}hl_orgunit o ON o.orgunit_id = e.school_id
             WHERE o.bb_group_id = %d
             AND e.status = 'active'
             AND c.cycle_type = 'program'
             AND c.status != 'archived'",
            $bb_group_id
        ) );

        // Also find users who are currently members of this group (for removal case)
        if ( function_exists( 'groups_get_group_members' ) ) {
            $members = groups_get_group_members( array(
                'group_id'   => $bb_group_id,
                'per_page'   => 0,
                'exclude_admins_mods' => false,
            ) );
            if ( ! empty( $members['members'] ) ) {
                foreach ( $members['members'] as $m ) {
                    $user_ids[] = $m->ID;
                }
            }
        }

        $user_ids = array_unique( array_map( 'intval', $user_ids ) );
        foreach ( $user_ids as $uid ) {
            self::sync_user_groups( $uid );
        }
    }

    // --- Hook Callbacks ---

    public static function on_enrollment_changed( $enrollment_id, $data ) {
        if ( ! self::is_bb_groups_available() ) return;
        try {
            $enrollment = ( new HL_Enrollment_Repository() )->get_by_id( $enrollment_id );
            if ( ! $enrollment ) return;
            self::sync_user_groups( (int) $enrollment->user_id );
        } catch ( \Exception $e ) { /* non-fatal */ }
    }

    public static function on_enrollment_deleted( $enrollment_id, $user_id ) {
        if ( ! self::is_bb_groups_available() ) return;
        if ( (int) $user_id <= 0 ) return;
        try {
            self::sync_user_groups( (int) $user_id );
        } catch ( \Exception $e ) { /* non-fatal */ }
    }

    public static function on_role_changed( $user_id, $new_role, $old_roles ) {
        if ( ! self::is_bb_groups_available() ) return;
        $coach_roles = array( 'coach', 'coaching_director' );
        $was_coach   = ! empty( array_intersect( $coach_roles, (array) $old_roles ) );
        $is_coach    = in_array( $new_role, $coach_roles, true );
        if ( $was_coach || $is_coach ) {
            try {
                self::sync_coach_groups( (int) $user_id );
            } catch ( \Exception $e ) { /* non-fatal */ }
        }
    }

    public static function on_role_added( $user_id, $role ) {
        if ( ! self::is_bb_groups_available() ) return;
        if ( in_array( $role, array( 'coach', 'coaching_director' ), true ) ) {
            try {
                self::sync_coach_groups( (int) $user_id );
            } catch ( \Exception $e ) { /* non-fatal */ }
        }
    }

    public static function on_role_removed( $user_id, $role ) {
        if ( ! self::is_bb_groups_available() ) return;
        if ( in_array( $role, array( 'coach', 'coaching_director' ), true ) ) {
            try {
                self::sync_coach_groups( (int) $user_id );
            } catch ( \Exception $e ) { /* non-fatal */ }
        }
    }
}
```

- [x] **Step 2: Commit**

```
git add includes/services/class-hl-bb-group-sync-service.php
git commit -m "feat(bb-sync): create HL_BB_Group_Sync_Service with full sync logic"
```

---

### Task 4: Hook Wiring in hl-core.php

**Files:**
- Modify: `hl-core.php` (lines 307-375 — init() and CLI registration)

- [x] **Step 1: Require the service file**

Add the require alongside other service includes (near line 100 where services are required):

```php
require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-bb-group-sync-service.php';
```

- [x] **Step 2: Register enrollment hooks in `init()`**

After the `HL_BuddyBoss_Integration::instance();` line (around line 330), add:

```php
// BB Group Sync hooks (enrollment events)
add_action( 'hl_enrollment_created', array( 'HL_BB_Group_Sync_Service', 'on_enrollment_changed' ), 25, 2 );
add_action( 'hl_enrollment_updated', array( 'HL_BB_Group_Sync_Service', 'on_enrollment_changed' ), 25, 2 );
add_action( 'hl_enrollment_deleted', array( 'HL_BB_Group_Sync_Service', 'on_enrollment_deleted' ), 25, 2 );

// BB Group Sync hooks (WP role changes)
add_action( 'set_user_role',    array( 'HL_BB_Group_Sync_Service', 'on_role_changed' ), 10, 3 );
add_action( 'add_user_role',    array( 'HL_BB_Group_Sync_Service', 'on_role_added' ),   10, 2 );
add_action( 'remove_user_role', array( 'HL_BB_Group_Sync_Service', 'on_role_removed' ), 10, 2 );
```

- [x] **Step 3: Require the settings class (admin only)**

Inside the `if (is_admin())` block (around line 185), add alongside other admin includes:

```php
require_once HL_CORE_INCLUDES_DIR . 'admin/class-hl-admin-bb-groups-settings.php';
```

- [x] **Step 4: Register CLI command**

In the CLI require block (around line 263), add:

```php
require_once HL_CORE_INCLUDES_DIR . 'cli/class-hl-cli-bb-sync.php';
```

In the CLI registration block (around line 374), add:

```php
HL_CLI_BB_Sync::register();
```

- [x] **Step 5: Commit**

```
git add hl-core.php
git commit -m "feat(bb-sync): wire enrollment + role hooks and CLI registration"
```

---

### Task 5: Admin Settings — BuddyBoss Groups Tab

**Files:**
- Modify: `includes/admin/class-hl-admin-settings.php` (tab list, switch cases)
- Create: `includes/admin/class-hl-admin-bb-groups-settings.php`

- [x] **Step 1: Add `bb_groups` tab to Settings page**

In `includes/admin/class-hl-admin-settings.php`:

1. In `render_tabs()` (around line 123), add to the `$tabs` array:

```php
'bb_groups' => __( 'BuddyBoss Groups', 'hl-core' ),
```

2. In `handle_early_actions()` (around line 28), add a case to the switch:

```php
case 'bb_groups':
    if ( isset( $_POST['hl_bb_groups_nonce'] ) ) {
        HL_Admin_BB_Groups_Settings::instance()->handle_save();
    }
    break;
```

3. In `render_page()` (around line 63), add a case to the switch:

```php
case 'bb_groups':
    HL_Admin_BB_Groups_Settings::instance()->render_page_content();
    break;
```

- [x] **Step 2: Create BB Groups settings page class**

Create `includes/admin/class-hl-admin-bb-groups-settings.php`:

```php
<?php
/**
 * Admin Settings — BuddyBoss Groups tab.
 *
 * @package HL_Core
 */
class HL_Admin_BB_Groups_Settings {

    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function handle_save() {
        if ( ! current_user_can( 'manage_hl_core' ) ) {
            return;
        }
        if ( ! wp_verify_nonce( $_POST['hl_bb_groups_nonce'], 'hl_save_bb_groups' ) ) {
            return;
        }

        $community_id = ! empty( $_POST['hl_bb_global_community_group_id'] )
            ? absint( $_POST['hl_bb_global_community_group_id'] ) : 0;
        $mentor_id = ! empty( $_POST['hl_bb_global_mentor_group_id'] )
            ? absint( $_POST['hl_bb_global_mentor_group_id'] ) : 0;

        update_option( 'hl_bb_global_community_group_id', $community_id );
        update_option( 'hl_bb_global_mentor_group_id', $mentor_id );

        HL_BB_Group_Sync_Service::invalidate_cache();

        // Warn if same group selected for both
        if ( $community_id > 0 && $community_id === $mentor_id ) {
            add_settings_error( 'hl_bb_groups', 'duplicate_group',
                __( 'Warning: Global Community and Global Mentor are mapped to the same group.', 'hl-core' ),
                'warning'
            );
        }

        add_settings_error( 'hl_bb_groups', 'saved',
            __( 'BuddyBoss group settings saved.', 'hl-core' ), 'success' );
    }

    public function render_page_content() {
        settings_errors( 'hl_bb_groups' );

        if ( ! HL_BB_Group_Sync_Service::is_bb_groups_available() ) {
            echo '<div class="notice notice-warning"><p>';
            esc_html_e( 'BuddyBoss Platform must be active to configure group sync.', 'hl-core' );
            echo '</p></div>';
            return;
        }

        $groups = HL_BB_Group_Sync_Service::get_bb_groups_dropdown();
        $community_id = (int) get_option( 'hl_bb_global_community_group_id', 0 );
        $mentor_id    = (int) get_option( 'hl_bb_global_mentor_group_id', 0 );

        ?>
        <form method="post">
            <?php wp_nonce_field( 'hl_save_bb_groups', 'hl_bb_groups_nonce' ); ?>

            <div class="hl-settings-card">
                <h2><?php esc_html_e( 'BuddyBoss Group Mapping', 'hl-core' ); ?></h2>
                <p class="description">
                    <?php esc_html_e( 'These settings control automatic BuddyBoss group membership. When configured, users are automatically added/removed from groups based on their B2E enrollments. Coaches and Coaching Directors are added as group moderators.', 'hl-core' ); ?>
                </p>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="hl_bb_global_community_group_id">
                                <?php esc_html_e( 'Global Community Group', 'hl-core' ); ?>
                            </label>
                        </th>
                        <td>
                            <select name="hl_bb_global_community_group_id" id="hl_bb_global_community_group_id">
                                <option value=""><?php esc_html_e( '— None (disabled) —', 'hl-core' ); ?></option>
                                <?php foreach ( $groups as $gid => $label ) : ?>
                                    <option value="<?php echo esc_attr( $gid ); ?>"
                                        <?php selected( $community_id, $gid ); ?>>
                                        <?php echo esc_html( $label ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="hl_bb_global_mentor_group_id">
                                <?php esc_html_e( 'Global Mentor Group', 'hl-core' ); ?>
                            </label>
                        </th>
                        <td>
                            <select name="hl_bb_global_mentor_group_id" id="hl_bb_global_mentor_group_id">
                                <option value=""><?php esc_html_e( '— None (disabled) —', 'hl-core' ); ?></option>
                                <?php foreach ( $groups as $gid => $label ) : ?>
                                    <option value="<?php echo esc_attr( $gid ); ?>"
                                        <?php selected( $mentor_id, $gid ); ?>>
                                        <?php echo esc_html( $label ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </table>
            </div>

            <?php submit_button( __( 'Save Settings', 'hl-core' ) ); ?>
        </form>
        <?php
    }
}
```

- [x] **Step 3: Commit**

```
git add includes/admin/class-hl-admin-settings.php includes/admin/class-hl-admin-bb-groups-settings.php hl-core.php
git commit -m "feat(bb-sync): add BuddyBoss Groups settings tab"
```

---

### Task 6: OrgUnit Admin — BB Group Dropdown + Change Detection

**Files:**
- Modify: `includes/admin/class-hl-admin-orgunits.php` (handle_actions ~line 240, form rendering)

- [x] **Step 1: Add `bb_group_id` to save handler `$data` array**

In `includes/admin/class-hl-admin-orgunits.php`, inside `handle_actions()`, add `bb_group_id` to the `$data` array (after `'status'`):

```php
'bb_group_id' => ! empty( $_POST['bb_group_id'] ) ? absint( $_POST['bb_group_id'] ) : null,
```

- [x] **Step 2: Add change detection + duplicate warning for `bb_group_id` on update**

In the update block (around line 252), replace the existing `$wpdb->update()` block with change detection:

```php
if ( $orgunit_id > 0 ) {
    // Read old bb_group_id before saving (use $wpdb to match file style)
    $old_bb_group_id = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT bb_group_id FROM {$wpdb->prefix}hl_orgunit WHERE orgunit_id = %d",
        $orgunit_id
    ) );

    $wpdb->update(
        $wpdb->prefix . 'hl_orgunit',
        $data,
        array( 'orgunit_id' => $orgunit_id )
    );

    $new_bb_group_id = (int) ( $data['bb_group_id'] ?? 0 );

    // Duplicate detection: warn if another school uses the same BB group
    if ( $new_bb_group_id > 0 ) {
        $other_name = $wpdb->get_var( $wpdb->prepare(
            "SELECT name FROM {$wpdb->prefix}hl_orgunit WHERE bb_group_id = %d AND orgunit_id != %d",
            $new_bb_group_id, $orgunit_id
        ) );
        if ( $other_name ) {
            add_settings_error( 'hl_orgunit', 'duplicate_bb_group',
                sprintf( __( 'Warning: This BuddyBoss group is already assigned to %s.', 'hl-core' ), $other_name ),
                'warning'
            );
        }
    }

    // Resync if bb_group_id changed
    if ( $old_bb_group_id !== $new_bb_group_id ) {
        HL_BB_Group_Sync_Service::invalidate_cache();
        if ( $old_bb_group_id > 0 ) {
            HL_BB_Group_Sync_Service::sync_users_in_group( $old_bb_group_id );
        }
        if ( $new_bb_group_id > 0 ) {
            HL_BB_Group_Sync_Service::sync_users_in_group( $new_bb_group_id );
        }
    }
}
```

- [x] **Step 3: Add dropdown field to the edit form**

In the `render_form()` method, insert BEFORE the `echo '</table>';` line (around line 732) — the form uses `echo` style exclusively, so match that pattern:

```php
// BuddyBoss Group field (schools only)
$is_school = ! $orgunit || $orgunit->orgunit_type === 'school';
if ( $is_school ) {
    echo '<tr>';
    echo '<th scope="row"><label for="bb_group_id">' . esc_html__( 'BuddyBoss Group', 'hl-core' ) . '</label></th>';
    echo '<td>';

    if ( HL_BB_Group_Sync_Service::is_bb_groups_available() ) {
        $bb_groups = HL_BB_Group_Sync_Service::get_bb_groups_dropdown();
        $current_bb_group_id = $orgunit ? (int) $orgunit->bb_group_id : 0;

        // Stale group warning
        if ( $current_bb_group_id > 0 && ! isset( $bb_groups[ $current_bb_group_id ] ) ) {
            echo '<div class="notice notice-warning inline" style="margin:0 0 8px;"><p>';
            echo esc_html( sprintf(
                __( 'Warning: The saved group (ID: %d) was not found in BuddyBoss. It may have been deleted.', 'hl-core' ),
                $current_bb_group_id
            ) );
            echo '</p></div>';
        }

        echo '<select name="bb_group_id" id="bb_group_id">';
        echo '<option value="">' . esc_html__( '— None —', 'hl-core' ) . '</option>';
        foreach ( $bb_groups as $gid => $label ) {
            echo '<option value="' . esc_attr( $gid ) . '"' . selected( $current_bb_group_id, $gid, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
    } else {
        echo '<p class="description">' . esc_html__( 'BuddyBoss Group mapping is unavailable (BuddyBoss not active).', 'hl-core' ) . '</p>';
    }

    echo '</td>';
    echo '</tr>';
}
```

- [x] **Step 4: Commit**

```
git add includes/admin/class-hl-admin-orgunits.php
git commit -m "feat(bb-sync): add bb_group_id dropdown to OrgUnit form with change detection"
```

---

### Task 7: WP-CLI Command

**Files:**
- Create: `includes/cli/class-hl-cli-bb-sync.php`

- [x] **Step 1: Create the CLI command class**

Create `includes/cli/class-hl-cli-bb-sync.php`:

```php
<?php
/**
 * WP-CLI: BuddyBoss Group Sync.
 *
 * @package HL_Core
 */
class HL_CLI_BB_Sync {

    /**
     * Register the CLI command (matches existing codebase pattern).
     */
    public static function register() {
        WP_CLI::add_command( 'hl-core bb-sync', array( new self(), 'run' ) );
    }

    /**
     * Sync BB group memberships for all or specific users.
     *
     * ## OPTIONS
     *
     * [--user=<user_id>]
     * : Sync a single user (both participant and coach status).
     *
     * [--all]
     * : Sync all users with active program enrollments + all coaches.
     *
     * [--coaches]
     * : Sync coach/coaching_director moderator status only.
     *
     * [--dry-run]
     * : Show what would change without making changes.
     *
     * ## EXAMPLES
     *
     *     wp hl-core bb-sync --all
     *     wp hl-core bb-sync --all --dry-run
     *     wp hl-core bb-sync --user=42
     *     wp hl-core bb-sync --coaches
     *
     * @param array $args       Positional args.
     * @param array $assoc_args Named args.
     */
    public function run( $args, $assoc_args ) {
        if ( ! HL_BB_Group_Sync_Service::is_bb_groups_available() ) {
            WP_CLI::error( 'BuddyBoss Groups API is not available.' );
        }

        $dry_run = isset( $assoc_args['dry-run'] );
        if ( $dry_run ) {
            WP_CLI::log( '=== DRY RUN — no changes will be made ===' );
        }

        if ( ! empty( $assoc_args['user'] ) ) {
            $user_id = (int) $assoc_args['user'];
            WP_CLI::log( "Syncing user {$user_id}..." );
            if ( ! $dry_run ) {
                HL_BB_Group_Sync_Service::sync_user_groups( $user_id );
                HL_BB_Group_Sync_Service::sync_coach_groups( $user_id );
            }
            WP_CLI::success( "User {$user_id} synced." );
            return;
        }

        if ( isset( $assoc_args['coaches'] ) ) {
            $this->sync_all_coaches( $dry_run );
            return;
        }

        if ( isset( $assoc_args['all'] ) ) {
            $this->sync_all_users( $dry_run );
            $this->sync_all_coaches( $dry_run );
            return;
        }

        WP_CLI::error( 'Specify --all, --coaches, or --user=<id>.' );
    }

    private function sync_all_users( bool $dry_run ) {
        global $wpdb;
        $user_ids = $wpdb->get_col(
            "SELECT DISTINCT e.user_id
             FROM {$wpdb->prefix}hl_enrollment e
             JOIN {$wpdb->prefix}hl_cycle c ON c.cycle_id = e.cycle_id
             WHERE c.cycle_type = 'program'
             AND c.status != 'archived'
             AND e.status = 'active'"
        );

        $count = count( $user_ids );
        WP_CLI::log( "Found {$count} users with active program enrollments." );

        $progress = \WP_CLI\Utils\make_progress_bar( 'Syncing users', $count );
        foreach ( $user_ids as $uid ) {
            if ( ! $dry_run ) {
                HL_BB_Group_Sync_Service::sync_user_groups( (int) $uid );
            }
            $progress->tick();
        }
        $progress->finish();

        $verb = $dry_run ? 'would sync' : 'synced';
        WP_CLI::success( "{$count} users {$verb}." );
    }

    private function sync_all_coaches( bool $dry_run ) {
        $coach_users = get_users( array(
            'role__in' => array( 'coach', 'coaching_director' ),
            'fields'   => 'ID',
        ) );

        $count = count( $coach_users );
        WP_CLI::log( "Found {$count} coaches/coaching directors." );

        foreach ( $coach_users as $uid ) {
            if ( ! $dry_run ) {
                HL_BB_Group_Sync_Service::sync_coach_groups( (int) $uid );
            }
        }

        $verb = $dry_run ? 'would sync' : 'synced';
        WP_CLI::success( "{$count} coaches {$verb}." );
    }
}
```

- [x] **Step 2: Commit**

```
git add includes/cli/class-hl-cli-bb-sync.php
git commit -m "feat(bb-sync): add WP-CLI bb-sync command for backfill and repair"
```

---

### Task 8: Import Handler — Bulk Defer + Route Updates Through Service

**Files:**
- Modify: `includes/services/class-hl-import-participant-handler.php` (around line 590-630 in `commit()`)

- [x] **Step 1: Route import UPDATE path through enrollment service**

In `includes/services/class-hl-import-participant-handler.php`, find the UPDATE branch (around line 626) where `$enrollment_repo->update()` is called directly. Replace it to go through the service so `hl_enrollment_updated` fires:

```php
// BEFORE (no hook fires):
// $enrollment_repo->update($enrollment_id, $update_data);

// AFTER (hook fires):
$enrollment_service = new HL_Enrollment_Service();
$enrollment_service->update_enrollment($enrollment_id, $update_data);
```

This ensures import updates (not just creates) trigger BB group sync.

- [x] **Step 2: Wrap the enrollment loop with bulk defer**

In the same `commit()` method, before the loop that processes enrollments (both CREATE and UPDATE), add `begin_bulk()`. After the loop, add `end_bulk()`. Use try/finally to ensure `end_bulk()` always runs:

```php
HL_BB_Group_Sync_Service::begin_bulk();
try {
    // ... existing enrollment creation/update loop ...
} finally {
    HL_BB_Group_Sync_Service::end_bulk();
}
```

The exact placement depends on the loop boundaries. The key is that `begin_bulk()` is called before any `hl_enrollment_created` or `hl_enrollment_updated` fires, and `end_bulk()` is called after all enrollments are processed.

- [x] **Step 2: Commit**

```
git add includes/services/class-hl-import-participant-handler.php
git commit -m "feat(bb-sync): route import updates through service + wrap in bulk defer"
```

---

### Task 9: Deploy to Test & Verify

- [x] **Step 1: Push to GitHub**

```
git push origin main
```

- [x] **Step 2: Deploy to test server**

Read `.claude/skills/deploy.md` for SSH commands. Deploy via git pull on test server.

- [x] **Step 3: Verify schema migration**

SSH into test server and run:

```bash
wp db query "SHOW COLUMNS FROM wp_hl_orgunit LIKE 'bb_group_id'"
```

Expected: one row showing `bb_group_id | bigint unsigned | YES | NULL`

- [x] **Step 4: Verify coaching_director role**

```bash
wp role list | grep coaching_director
```

Expected: `coaching_director` appears in the list.

- [x] **Step 5: Verify CLI command**

```bash
wp hl-core bb-sync --all --dry-run
```

Expected: shows count of users found, progress bar, "would sync" message. No errors.

- [x] **Step 6: Verify Settings tab**

Navigate to WP Admin → Housman LMS → Settings → BuddyBoss Groups. Verify:
- Two dropdown fields appear with BB group options
- Help text is visible
- Save works

- [x] **Step 7: Verify OrgUnit form**

Navigate to WP Admin → Housman LMS → Org Units → Edit a school. Verify:
- BuddyBoss Group dropdown appears
- "— None —" is the default
- Save persists the value

- [x] **Step 8: Configure and run backfill**

1. Set Global Community and Global Mentor group IDs in Settings
2. Map school orgunits to their BB groups
3. Run `wp hl-core bb-sync --all` for full backfill
4. Spot-check group memberships in BuddyBoss admin

- [x] **Step 9: Update STATUS.md and README.md**

Mark this feature complete in STATUS.md build queue. Update README.md "What's Implemented" section.

- [x] **Step 10: Commit docs**

```
git add STATUS.md README.md
git commit -m "docs: mark BB group sync complete in STATUS.md and README.md"
```
