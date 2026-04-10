<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Email Recipient Resolver
 *
 * Resolves recipient tokens from a workflow's recipients JSON into
 * concrete email/user_id/type tuples. A single workflow CAN fan out
 * to multiple recipients (one queue row per resolved recipient).
 *
 * Tokens: triggering_user, assigned_coach, school_director,
 * cc_teacher, role:X, static:email.
 *
 * @package HL_Core
 */
class HL_Email_Recipient_Resolver {

    /** @var self|null */
    private static $instance = null;

    /** @return self */
    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Resolve a recipient config into concrete recipients.
     *
     * @param array $recipient_config { primary: [tokens], cc: [tokens] }
     * @param array $context          Populated context array.
     * @return array Array of { email, user_id, type } (type = 'to' or 'cc').
     */
    public function resolve( array $recipient_config, array $context ) {
        $results = array();
        $seen    = array(); // Dedup by email.

        $primary_tokens = $recipient_config['primary'] ?? array();
        $cc_tokens      = $recipient_config['cc']      ?? array();

        foreach ( $primary_tokens as $token ) {
            $resolved = $this->resolve_token( $token, $context );
            foreach ( $resolved as $r ) {
                $key = strtolower( $r['email'] );
                if ( ! isset( $seen[ $key ] ) ) {
                    $seen[ $key ] = true;
                    $results[]    = array(
                        'email'   => $r['email'],
                        'user_id' => $r['user_id'],
                        'type'    => 'to',
                    );
                }
            }
        }

        foreach ( $cc_tokens as $token ) {
            $resolved = $this->resolve_token( $token, $context );
            foreach ( $resolved as $r ) {
                $key = strtolower( $r['email'] );
                if ( ! isset( $seen[ $key ] ) ) {
                    $seen[ $key ] = true;
                    $results[]    = array(
                        'email'   => $r['email'],
                        'user_id' => $r['user_id'],
                        'type'    => 'cc',
                    );
                }
            }
        }

        return $results;
    }

    // =========================================================================
    // Token Resolvers
    // =========================================================================

    /**
     * Resolve a single token into one or more recipients.
     *
     * @param string $token   Token string.
     * @param array  $context Context array.
     * @return array Array of { email, user_id }.
     */
    private function resolve_token( $token, array $context ) {
        // Static email: "static:user@example.com"
        if ( strpos( $token, 'static:' ) === 0 ) {
            $email = substr( $token, 7 );
            if ( is_email( $email ) ) {
                return array( array( 'email' => $email, 'user_id' => null ) );
            }
            return array();
        }

        // Role-based: "role:coach" — all users with that role enrolled in the triggering cycle.
        if ( strpos( $token, 'role:' ) === 0 ) {
            $role = substr( $token, 5 );
            return $this->resolve_role( $role, $context );
        }

        switch ( $token ) {
            case 'triggering_user':
                return $this->resolve_triggering_user( $context );

            case 'assigned_coach':
                return $this->resolve_assigned_coach( $context );

            case 'school_director':
                return $this->resolve_school_director( $context );

            case 'cc_teacher':
                return $this->resolve_cc_teacher( $context );

            default:
                return array();
        }
    }

    /**
     * Resolve the user who triggered the event.
     */
    private function resolve_triggering_user( array $context ) {
        $user_id = $context['user_id'] ?? null;
        if ( ! $user_id ) {
            return array();
        }
        $user = get_userdata( (int) $user_id );
        if ( ! $user ) {
            return array();
        }
        return array( array( 'email' => $user->user_email, 'user_id' => (int) $user_id ) );
    }

    /**
     * Resolve the assigned coach for the triggering user in the cycle.
     */
    private function resolve_assigned_coach( array $context ) {
        global $wpdb;
        $user_id  = $context['user_id']  ?? null;
        $cycle_id = $context['cycle_id'] ?? null;

        if ( ! $user_id || ! $cycle_id ) {
            return array();
        }

        // Look for enrollment-scoped assignment first, then school/team-scoped.
        $coach_user_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT ca.coach_user_id
             FROM {$wpdb->prefix}hl_coach_assignment ca
             WHERE ca.cycle_id = %d
               AND ca.status = 'active'
               AND (
                   (ca.scope_type = 'enrollment' AND ca.scope_id = (
                       SELECT enrollment_id FROM {$wpdb->prefix}hl_enrollment
                       WHERE user_id = %d AND cycle_id = %d AND status IN ('active','warning') LIMIT 1
                   ))
                   OR (ca.scope_type = 'school' AND ca.scope_id = (
                       SELECT school_id FROM {$wpdb->prefix}hl_enrollment
                       WHERE user_id = %d AND cycle_id = %d AND status IN ('active','warning') LIMIT 1
                   ))
               )
             ORDER BY FIELD(ca.scope_type, 'enrollment', 'school', 'team') ASC
             LIMIT 1",
            $cycle_id,
            $user_id, $cycle_id,
            $user_id, $cycle_id
        ) );

        if ( ! $coach_user_id ) {
            return array();
        }

        $coach = get_userdata( (int) $coach_user_id );
        if ( ! $coach ) {
            return array();
        }

        return array( array( 'email' => $coach->user_email, 'user_id' => (int) $coach_user_id ) );
    }

    /**
     * Resolve the school director for the triggering user's school.
     */
    private function resolve_school_director( array $context ) {
        global $wpdb;
        $user_id  = $context['user_id']  ?? null;
        $cycle_id = $context['cycle_id'] ?? null;

        if ( ! $user_id || ! $cycle_id ) {
            return array();
        }

        // Get the user's school, then find the school leader.
        $school_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT school_id FROM {$wpdb->prefix}hl_enrollment
             WHERE user_id = %d AND cycle_id = %d AND status IN ('active','warning') LIMIT 1",
            $user_id, $cycle_id
        ) );

        if ( ! $school_id ) {
            return array();
        }

        // Find enrollment with school_leader role in the same school + cycle.
        $director_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT e.user_id FROM {$wpdb->prefix}hl_enrollment e
             WHERE e.school_id = %d AND e.cycle_id = %d AND e.status IN ('active','warning')
               AND e.roles LIKE %s
             LIMIT 1",
            $school_id, $cycle_id,
            '%school_leader%'
        ) );

        if ( ! $director_id ) {
            return array();
        }

        $director = get_userdata( (int) $director_id );
        if ( ! $director ) {
            return array();
        }

        return array( array( 'email' => $director->user_email, 'user_id' => (int) $director_id ) );
    }

    /**
     * Resolve the teacher being observed (for classroom visit emails).
     */
    private function resolve_cc_teacher( array $context ) {
        $teacher_user_id = $context['cc_teacher_user_id'] ?? null;
        if ( ! $teacher_user_id ) {
            return array();
        }
        $user = get_userdata( (int) $teacher_user_id );
        if ( ! $user ) {
            return array();
        }
        return array( array( 'email' => $user->user_email, 'user_id' => (int) $teacher_user_id ) );
    }

    /**
     * Resolve all users with a specific WordPress role enrolled in the cycle.
     *
     * @param string $role    WordPress role (e.g., "coach").
     * @param array  $context Context with cycle_id.
     * @return array Array of { email, user_id }.
     */
    private function resolve_role( $role, array $context ) {
        global $wpdb;
        $cycle_id = $context['cycle_id'] ?? null;
        if ( ! $cycle_id ) {
            return array();
        }

        $role = sanitize_text_field( $role );

        // Get users enrolled in this cycle with the specified role.
        // JOIN wp_users to avoid N+1 get_userdata calls.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT e.user_id, u.user_email
             FROM {$wpdb->prefix}hl_enrollment e
             JOIN {$wpdb->users} u ON u.ID = e.user_id
             WHERE e.cycle_id = %d AND e.status IN ('active','warning')
               AND e.roles LIKE %s",
            $cycle_id,
            '%' . $wpdb->esc_like( $role ) . '%'
        ) );

        $results = array();
        foreach ( $rows as $row ) {
            if ( ! empty( $row->user_email ) ) {
                $results[] = array( 'email' => $row->user_email, 'user_id' => (int) $row->user_id );
            }
        }

        return $results;
    }
}
