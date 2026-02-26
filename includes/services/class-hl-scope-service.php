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
     *     @type int[]    $track_ids     Tracks visible (empty = all for admin)
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
     * Check if the current user can view a specific track.
     */
    public static function can_view_track( $track_id, $user_id = null ) {
        $scope = self::get_scope( $user_id );
        if ( $scope['is_admin'] ) {
            return true;
        }
        return in_array( (int) $track_id, $scope['track_ids'], true );
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
     * @param string $key     Property/key name (e.g. 'track_id').
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
        $is_staff = user_can( $user_id, 'manage_hl_core' );

        $scope = array(
            'user_id'        => (int) $user_id,
            'is_admin'       => $is_admin,
            'is_staff'       => $is_staff,
            'is_coach'       => $is_staff && ! $is_admin,
            'track_ids'     => array(),
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
     * Coach scope: active hl_coach_assignment rows + any personal enrollments.
     */
    private static function compute_coach_scope( $user_id, $scope ) {
        global $wpdb;
        $today  = current_time( 'Y-m-d' );
        $prefix = $wpdb->prefix;

        // Active coach assignments.
        $assignments = $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT track_id, scope_type, scope_id
             FROM {$prefix}hl_coach_assignment
             WHERE coach_user_id = %d
               AND effective_from <= %s
               AND (effective_to IS NULL OR effective_to >= %s)",
            $user_id, $today, $today
        ), ARRAY_A );

        $track_ids = array();
        $school_ids = array();

        foreach ( $assignments as $a ) {
            $track_ids[] = (int) $a['track_id'];

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

        // Also include any personal enrollments.
        $own = $wpdb->get_results( $wpdb->prepare(
            "SELECT enrollment_id, track_id, school_id, district_id, roles
             FROM {$prefix}hl_enrollment
             WHERE user_id = %d AND status = 'active'",
            $user_id
        ), ARRAY_A );

        $hl_roles = array();
        foreach ( $own as $e ) {
            $scope['enrollment_ids'][] = (int) $e['enrollment_id'];
            $track_ids[] = (int) $e['track_id'];
            if ( $e['school_id'] ) {
                $school_ids[] = (int) $e['school_id'];
            }
            $roles    = json_decode( $e['roles'], true ) ?: array();
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

        $scope['track_ids']     = array_values( array_unique( $track_ids ) );
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
            "SELECT enrollment_id, track_id, school_id, district_id, roles
             FROM {$prefix}hl_enrollment
             WHERE user_id = %d AND status = 'active'",
            $user_id
        ), ARRAY_A );

        if ( empty( $enrollments ) ) {
            return $scope;
        }

        $hl_roles       = array();
        $track_ids     = array();
        $school_ids     = array();
        $district_ids   = array();
        $enrollment_ids = array();

        foreach ( $enrollments as $e ) {
            $enrollment_ids[] = (int) $e['enrollment_id'];
            $track_ids[]     = (int) $e['track_id'];
            if ( $e['school_id'] ) {
                $school_ids[] = (int) $e['school_id'];
            }

            $roles    = json_decode( $e['roles'], true ) ?: array();
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
        $scope['track_ids']     = array_values( array_unique( $track_ids ) );
        $scope['school_ids']     = array_values( array_unique( $school_ids ) );
        $scope['district_ids']   = $district_ids;
        $scope['team_ids']       = array_values( array_unique( $team_ids ) );
        $scope['enrollment_ids'] = array_values( array_unique( $enrollment_ids ) );

        return $scope;
    }
}
