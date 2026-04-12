<?php
if (!defined('ABSPATH')) exit;

/**
 * Scope Service — shared helper for role-based scope filtering.
 *
 * Every listing page calls HL_Scope_Service::get_scope() to determine
 * what data the current user is allowed to see. Results are statically
 * cached per user_id per request.
 *
 * Scope levels:
 *   admin  (manage_options)       — sees ALL data, no filtering
 *   coach  (manage_hl_core only)  — filtered by hl_coach_assignment
 *   district_leader               — filtered by district from enrollment
 *   school_leader                 — filtered by school from enrollment
 *   mentor                        — filtered by team membership
 *   teacher                       — filtered by own enrollment/assignments
 *
 * @package HL_Core
 */
class HL_Scope_Service {

    /** @var array Static cache keyed by user_id */
    private static $cache = array();

    /**
     * Get the scope context for a user.
     *
     * @param int|null $user_id Defaults to current user.
     * @return array {
     *     @type int      $user_id
     *     @type bool     $is_admin       manage_options
     *     @type bool     $is_staff       manage_hl_core
     *     @type bool     $is_coach       staff but not admin
     *     @type int[]    $cycle_ids     Tracks visible (empty = all for admin)
     *     @type int[]    $school_ids     Schools visible
     *     @type int[]    $district_ids   Districts visible
     *     @type int[]    $team_ids       Teams visible
     *     @type int[]    $enrollment_ids Enrollments visible
     *     @type string[] $hl_roles       Union of all enrollment roles
     * }
     */
    public static function get_scope( $user_id = null ) {
        if ( ! $user_id ) {
            $user_id = get_current_user_id();
        }
        if ( isset( self::$cache[ $user_id ] ) ) {
            return self::$cache[ $user_id ];
        }

        $scope = self::compute_scope( $user_id );
        self::$cache[ $user_id ] = $scope;
        return $scope;
    }

    /**
     * Clear the static cache (useful in unit tests or after role changes).
     */
    public static function clear_cache() {
        self::$cache = array();
    }

    // =========================================================================
    // Convenience helpers
    // =========================================================================

    /**
     * Check if the current user can view a specific cycle.
     */
    public static function can_view_cycle( $cycle_id, $user_id = null ) {
        $scope = self::get_scope( $user_id );
        if ( $scope['is_admin'] ) {
            return true;
        }
        return in_array( (int) $cycle_id, $scope['cycle_ids'], true );
    }

    /**
     * Check if the current user can view a specific school.
     */
    public static function can_view_school( $school_id, $user_id = null ) {
        $scope = self::get_scope( $user_id );
        if ( $scope['is_admin'] ) {
            return true;
        }
        return in_array( (int) $school_id, $scope['school_ids'], true );
    }

    /**
     * Check if user has a specific HL enrollment role.
     */
    public static function has_role( $role, $user_id = null ) {
        $scope = self::get_scope( $user_id );
        return in_array( $role, $scope['hl_roles'], true );
    }

    /**
     * Filter items by an ID key against the user's allowed IDs.
     *
     * When $allowed is empty AND user is admin, returns all items (no filter).
     * When $allowed is empty AND user is NOT admin, returns empty array.
     *
     * @param array  $items   Array of objects or assoc arrays.
     * @param string $key     Property/key name (e.g. 'cycle_id').
     * @param int[]  $allowed Allowed IDs from scope.
     * @param bool   $is_admin Whether user is admin (empty = no filter).
     * @return array Filtered items (re-indexed).
     */
    public static function filter_by_ids( $items, $key, $allowed, $is_admin = false ) {
        if ( $is_admin ) {
            return $items;
        }
        if ( empty( $allowed ) ) {
            return array();
        }
        return array_values( array_filter( $items, function ( $item ) use ( $key, $allowed ) {
            $val = is_object( $item ) ? ( $item->$key ?? null ) : ( $item[ $key ] ?? null );
            return in_array( (int) $val, $allowed, true );
        } ) );
    }

    // =========================================================================
    // Internal computation
    // =========================================================================

    private static function compute_scope( $user_id ) {
        $is_admin = user_can( $user_id, 'manage_options' );
        $user_data = get_userdata( $user_id );
        $is_coach_role = $user_data && in_array( 'coach', (array) $user_data->roles, true );
        // Coaches are staff-level even if the WP role is missing the capability.
        $is_staff = user_can( $user_id, 'manage_hl_core' ) || $is_coach_role;

        $scope = array(
            'user_id'        => (int) $user_id,
            'is_admin'       => $is_admin,
            'is_staff'       => $is_staff,
            'is_coach'       => $is_coach_role || ( $is_staff && ! $is_admin ),
            'cycle_ids'     => array(),
            'school_ids'     => array(),
            'district_ids'   => array(),
            'team_ids'       => array(),
            'enrollment_ids' => array(),
            'hl_roles'       => array(),
        );

        // Admin: empty arrays = no restriction.
        if ( $is_admin ) {
            return $scope;
        }

        // Coach: scope by coach_assignment + own enrollments.
        if ( $is_staff ) {
            return self::compute_coach_scope( $user_id, $scope );
        }

        // Enrolled user: scope by enrollment roles.
        return self::compute_enrollment_scope( $user_id, $scope );
    }

    /**
     * Coach scope: active hl_coach_assignment rows expanded to full
     * active partnerships + any personal enrollments.
     */
    private static function compute_coach_scope( $user_id, $scope ) {
        global $wpdb;
        $today  = current_time( 'Y-m-d' );
        $prefix = $wpdb->prefix;

        // Active coach assignments.
        $assignments = $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT cycle_id, scope_type, scope_id
             FROM {$prefix}hl_coach_assignment
             WHERE coach_user_id = %d
               AND effective_from <= %s
               AND (effective_to IS NULL OR effective_to >= %s)",
            $user_id, $today, $today
        ), ARRAY_A );

        $assigned_cycle_ids = array();
        $school_ids = array();

        foreach ( $assignments as $a ) {
            $assigned_cycle_ids[] = (int) $a['cycle_id'];

            switch ( $a['scope_type'] ) {
                case 'school':
                    $school_ids[] = (int) $a['scope_id'];
                    break;
                case 'team':
                    $cid = $wpdb->get_var( $wpdb->prepare(
                        "SELECT school_id FROM {$prefix}hl_team WHERE team_id = %d",
                        $a['scope_id']
                    ) );
                    if ( $cid ) {
                        $school_ids[] = (int) $cid;
                    }
                    break;
                case 'enrollment':
                    $cid = $wpdb->get_var( $wpdb->prepare(
                        "SELECT school_id FROM {$prefix}hl_enrollment WHERE enrollment_id = %d",
                        $a['scope_id']
                    ) );
                    if ( $cid ) {
                        $school_ids[] = (int) $cid;
                    }
                    break;
            }
        }

        // Expand assigned cycles to ALL cycles in the same active partnerships.
        // Coaches see everyone across their partnerships, not just their direct assignees.
        $cycle_ids = self::expand_cycles_to_active_partnerships( $assigned_cycle_ids );

        // Expand school_ids to include all schools with enrollments in expanded cycles.
        if ( ! empty( $cycle_ids ) ) {
            $c_in = implode( ',', $cycle_ids );
            $partner_school_ids = $wpdb->get_col(
                "SELECT DISTINCT school_id FROM {$prefix}hl_enrollment
                 WHERE cycle_id IN ({$c_in}) AND status = 'active' AND school_id IS NOT NULL"
            );
            $school_ids = array_merge( $school_ids, array_map( 'intval', $partner_school_ids ) );
        }

        // Also include any personal enrollments.
        $own = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.enrollment_id, e.cycle_id, e.school_id, e.district_id, e.roles
             FROM {$prefix}hl_enrollment e
             JOIN {$prefix}hl_cycle c ON e.cycle_id = c.cycle_id
             WHERE e.user_id = %d AND e.status = 'active' AND c.status != 'archived'",
            $user_id
        ), ARRAY_A );

        $hl_roles = array();
        foreach ( $own as $e ) {
            $scope['enrollment_ids'][] = (int) $e['enrollment_id'];
            $cycle_ids[] = (int) $e['cycle_id'];
            if ( $e['school_id'] ) {
                $school_ids[] = (int) $e['school_id'];
            }
            $roles    = HL_Roles::parse_stored( $e['roles'] );
            $hl_roles = array_merge( $hl_roles, $roles );
        }

        // Resolve districts from schools.
        $school_ids = array_values( array_unique( array_filter( $school_ids ) ) );
        if ( ! empty( $school_ids ) ) {
            $in           = implode( ',', $school_ids );
            $district_ids = $wpdb->get_col(
                "SELECT DISTINCT parent_orgunit_id FROM {$prefix}hl_orgunit
                 WHERE orgunit_id IN ({$in}) AND parent_orgunit_id IS NOT NULL"
            );
            $scope['district_ids'] = array_map( 'intval', $district_ids );
        }

        $scope['cycle_ids']     = array_values( array_unique( $cycle_ids ) );
        $scope['school_ids']     = $school_ids;
        $scope['enrollment_ids'] = array_values( array_unique( $scope['enrollment_ids'] ) );
        $scope['hl_roles']       = array_values( array_unique( $hl_roles ) );

        return $scope;
    }

    /**
     * Enrollment scope: roles determine what the user can see.
     *
     * district_leader → all schools in their district(s)
     * school_leader   → their school(s)
     * mentor          → their team(s)
     * teacher         → own enrollment only
     */
    private static function compute_enrollment_scope( $user_id, $scope ) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        $enrollments = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.enrollment_id, e.cycle_id, e.school_id, e.district_id, e.roles
             FROM {$prefix}hl_enrollment e
             JOIN {$prefix}hl_cycle c ON e.cycle_id = c.cycle_id
             WHERE e.user_id = %d AND e.status = 'active' AND c.status != 'archived'",
            $user_id
        ), ARRAY_A );

        if ( empty( $enrollments ) ) {
            return $scope;
        }

        $hl_roles       = array();
        $cycle_ids     = array();
        $school_ids     = array();
        $district_ids   = array();
        $enrollment_ids = array();

        foreach ( $enrollments as $e ) {
            $enrollment_ids[] = (int) $e['enrollment_id'];
            $cycle_ids[]     = (int) $e['cycle_id'];
            if ( $e['school_id'] ) {
                $school_ids[] = (int) $e['school_id'];
            }

            $roles    = HL_Roles::parse_stored( $e['roles'] );
            $hl_roles = array_merge( $hl_roles, $roles );

            // District leaders see their district scope.
            if ( in_array( 'district_leader', $roles, true ) ) {
                $did = $e['district_id'] ?: null;
                if ( ! $did && $e['school_id'] ) {
                    $did = $wpdb->get_var( $wpdb->prepare(
                        "SELECT parent_orgunit_id FROM {$prefix}hl_orgunit WHERE orgunit_id = %d",
                        $e['school_id']
                    ) );
                }
                if ( $did ) {
                    $district_ids[] = (int) $did;
                }
            }
        }

        // Expand districts → schools.
        $district_ids = array_values( array_unique( array_filter( $district_ids ) ) );
        if ( ! empty( $district_ids ) ) {
            $d_in             = implode( ',', $district_ids );
            $district_schools = $wpdb->get_col(
                "SELECT orgunit_id FROM {$prefix}hl_orgunit
                 WHERE parent_orgunit_id IN ({$d_in}) AND orgunit_type = 'school'"
            );
            $school_ids = array_merge( $school_ids, array_map( 'intval', $district_schools ) );
        }

        // Team memberships.
        $team_ids = array();
        if ( ! empty( $enrollment_ids ) ) {
            $e_in     = implode( ',', $enrollment_ids );
            $team_ids = array_map( 'intval', $wpdb->get_col(
                "SELECT DISTINCT team_id FROM {$prefix}hl_team_membership
                 WHERE enrollment_id IN ({$e_in})"
            ) );
        }

        $scope['hl_roles']       = array_values( array_unique( $hl_roles ) );
        $scope['cycle_ids']     = array_values( array_unique( $cycle_ids ) );
        $scope['school_ids']     = array_values( array_unique( $school_ids ) );
        $scope['district_ids']   = $district_ids;
        $scope['team_ids']       = array_values( array_unique( $team_ids ) );
        $scope['enrollment_ids'] = array_values( array_unique( $enrollment_ids ) );

        return $scope;
    }

    /**
     * Expand a set of cycle IDs to include all cycles in the same active partnerships.
     *
     * Used by coach scope: if a coach is assigned to Cycle A in Partnership X,
     * they can see all cycles in Partnership X (as long as it's active).
     *
     * @param int[] $cycle_ids Directly assigned cycle IDs.
     * @return int[] Expanded cycle IDs (includes originals).
     */
    private static function expand_cycles_to_active_partnerships( $cycle_ids ) {
        if ( empty( $cycle_ids ) ) {
            return array();
        }

        global $wpdb;
        $prefix = $wpdb->prefix;
        $in = implode( ',', array_map( 'intval', $cycle_ids ) );

        // Get active partnership IDs for the assigned cycles.
        $partnership_ids = $wpdb->get_col(
            "SELECT DISTINCT p.partnership_id
             FROM {$prefix}hl_partnership p
             JOIN {$prefix}hl_cycle c ON c.partnership_id = p.partnership_id
             WHERE c.cycle_id IN ({$in}) AND p.status = 'active'"
        );

        if ( empty( $partnership_ids ) ) {
            return array_values( array_unique( $cycle_ids ) );
        }

        // Get ALL cycles in those active partnerships.
        $p_in = implode( ',', array_map( 'intval', $partnership_ids ) );
        $all = $wpdb->get_col(
            "SELECT cycle_id FROM {$prefix}hl_cycle WHERE partnership_id IN ({$p_in})"
        );

        return array_values( array_unique( array_map( 'intval', array_merge( $cycle_ids, $all ) ) ) );
    }
}
