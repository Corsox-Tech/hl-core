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
        // BP_Groups_Member::delete() signature is ( $group_id, $user_id ) — group first!
        return (bool) BP_Groups_Member::delete( $group_id, $user_id );
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
                    if ( groups_is_user_mod( $user_id, $gid )
                      || groups_is_user_admin( $user_id, $gid ) ) {
                        if ( ! self::demote_from_mod( $user_id, $gid ) ) {
                            continue; // Demotion failed — don't log false success
                        }
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
                'per_page'   => 9999,
                'exclude_admins_mods' => false,
            ) );
            if ( ! empty( $members['members'] ) ) {
                foreach ( $members['members'] as $m ) {
                    $user_ids[] = $m->user_id;
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
