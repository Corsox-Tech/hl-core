<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Email Recipient Resolver
 *
 * Resolves recipient tokens from a workflow's recipients JSON into
 * concrete email/user_id/type tuples. A single workflow CAN fan out
 * to multiple recipients (one queue row per resolved recipient).
 *
 * Tokens: triggering_user, assigned_coach, assigned_mentor, school_director,
 * observed_teacher (alias: cc_teacher), session_mentor, session_coach, observer,
 * mentor_team_teachers, role:X, static:email.
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
    // Helpers
    // =========================================================================

    /**
     * Whether the Rev 37 role scrub has completed and FIND_IN_SET against
     * `hl_enrollment.roles` is safe to use. Centralised so every resolver
     * method gates on the same flag, and so the `class_exists` defensive
     * check has a single removal point (Track 3 Task 32 final cleanup).
     *
     * @return bool
     */
    private function scrub_done() {
        return HL_Roles::scrub_is_complete();
    }

    /**
     * If the last $wpdb call produced an error, emit an audit row labelled
     * with the method+query so operators can see which DB call failed.
     *
     * Rate-limited per-method via a 5-minute transient so a sustained DB
     * outage doesn't flood hl_audit_log — one row per method per 5 minutes
     * is enough signal while bounding write volume.
     *
     * ALWAYS resets $wpdb->last_error to '' after inspection so a single
     * error isn't attributed to subsequent queries in the same request.
     *
     * Caller contract: call immediately after every $wpdb->get_var /
     * get_row / get_results in a resolver method. Does not change any
     * return value — the fail-closed contract (DB error → empty recipient
     * set) stays load-bearing.
     *
     * @param string $method_label Descriptive label like "assigned_mentor:enrollment_lookup".
     * @return void
     */
    private function log_db_error_if_any( $method_label ) {
        global $wpdb;
        if ( empty( $wpdb->last_error ) ) {
            return;
        }
        $error_snippet = substr( (string) $wpdb->last_error, 0, 200 );
        $wpdb->last_error = ''; // prevent attribution to subsequent queries
        if ( ! class_exists( 'HL_Audit_Service' ) ) {
            return;
        }
        $lock_key = 'hl_resolver_db_error_' . substr( sha1( (string) $method_label ), 0, 16 );
        if ( get_transient( $lock_key ) ) {
            return;
        }
        HL_Audit_Service::log( 'email_resolver_db_error', array(
            'entity_type' => 'email_workflow',
            'reason'      => $method_label . ': ' . $error_snippet,
        ) );
        set_transient( $lock_key, 1, 5 * MINUTE_IN_SECONDS );
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

            case 'assigned_mentor':
                return $this->resolve_assigned_mentor( $context );

            case 'observed_teacher':
                return $this->resolve_observed_teacher( $context );

            case 'session_mentor':
                return $this->resolve_session_mentor( $context );

            case 'session_coach':
                return $this->resolve_session_coach( $context );

            case 'observer':
                return $this->resolve_observer( $context );

            case 'mentor_team_teachers':
                return $this->resolve_mentor_team_teachers( $context );

            case 'cc_teacher':
                // Legacy alias — A.6.11 deprecation telemetry.
                // Scheduled for removal after 90 days of zero hits.
                if ( class_exists( 'HL_Audit_Service' ) ) {
                    HL_Audit_Service::log( 'email_token_alias_hit', array(
                        'entity_type' => 'email_workflow',
                        'reason'      => 'cc_teacher -> observed_teacher',
                    ) );
                }
                return $this->resolve_observed_teacher( $context );

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
        $this->log_db_error_if_any( 'assigned_coach:lookup' );

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
        $this->log_db_error_if_any( 'school_director:school_lookup' );

        if ( ! $school_id ) {
            return array();
        }

        // Find enrollment with school_leader role in the same school + cycle.
        //
        // Post-scrub (Rev 37): roles is normalised CSV so FIND_IN_SET is exact.
        // Pre-scrub: LIKE narrows the candidate set; HL_Roles::has_role() then
        // post-filters in PHP to eliminate substring false positives. Today
        // the literal is school_leader which has no substring victims, but
        // the pattern must exist before anyone adds a role with collision
        // potential (e.g. 'leader' vs 'school_leader').
        if ( $this->scrub_done() ) {
            $director_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT e.user_id FROM {$wpdb->prefix}hl_enrollment e
                 WHERE e.school_id = %d AND e.cycle_id = %d AND e.status IN ('active','warning')
                   AND FIND_IN_SET('school_leader', e.roles) > 0
                 LIMIT 1",
                $school_id, $cycle_id
            ) );
            $this->log_db_error_if_any( 'school_director:director_lookup' );
        } else {
            // Pre-scrub fallback: narrow via LIKE, then exact-match via HL_Roles.
            $candidates = $wpdb->get_results( $wpdb->prepare(
                "SELECT e.user_id, e.roles FROM {$wpdb->prefix}hl_enrollment e
                 WHERE e.school_id = %d AND e.cycle_id = %d AND e.status IN ('active','warning')
                   AND e.roles LIKE %s
                 LIMIT 50",
                $school_id, $cycle_id,
                '%school_leader%'
            ) );
            $this->log_db_error_if_any( 'school_director:director_lookup' );
            $director_id = null;
            foreach ( (array) $candidates as $row ) {
                if ( HL_Roles::has_role( $row->roles, 'school_leader' ) ) {
                    $director_id = (int) $row->user_id;
                    break;
                }
            }
        }

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
     *
     * Previously `resolve_cc_teacher()`; renamed for v2 clarity. Reads both
     * the legacy `cc_teacher_user_id` and the new `observed_teacher_user_id`
     * context keys so existing callers keep working during the rename window.
     */
    private function resolve_observed_teacher( array $context ) {
        $teacher_user_id = $context['observed_teacher_user_id'] ?? $context['cc_teacher_user_id'] ?? null;
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
     * Resolve the mentor on a coaching session (entity-specific, NOT "user's mentor").
     *
     * Chris Love's spreadsheet consistently routes coaching session emails to
     * "the mentor on THIS session" — not to the triggering_user (who can be
     * the coach booking on behalf of the teacher). We prefer an explicit
     * `mentor_user_id` key if set, otherwise fall back to the canonical
     * `user_id` set by `load_coaching_session_context()` (which is the mentor).
     *
     * @param array $context
     * @return array Array of { email, user_id } (0 or 1 row).
     */
    private function resolve_session_mentor( array $context ) {
        $mentor_id = 0;
        if ( ! empty( $context['mentor_user_id'] ) ) {
            $mentor_id = (int) $context['mentor_user_id'];
        } elseif ( ( $context['entity_type'] ?? '' ) === 'coaching_session' && ! empty( $context['user_id'] ) ) {
            $mentor_id = (int) $context['user_id'];
        }
        if ( $mentor_id <= 0 ) {
            return array();
        }
        $user = get_userdata( $mentor_id );
        if ( ! $user || empty( $user->user_email ) ) {
            return array();
        }
        return array( array( 'email' => $user->user_email, 'user_id' => $mentor_id ) );
    }

    /**
     * Resolve the coach on a coaching session (entity-specific).
     *
     * Reads `$context['coach_user_id']` populated by
     * `load_coaching_session_context()`. Unlike `assigned_coach`, which looks
     * up the coach via `hl_coach_assignment` for the triggering user, this
     * token returns the coach recorded on the session row itself — robust
     * when the session predates an assignment change or when no assignment
     * exists (e.g. staff-booked session).
     *
     * @param array $context
     * @return array Array of { email, user_id } (0 or 1 row).
     */
    private function resolve_session_coach( array $context ) {
        $coach_id = isset( $context['coach_user_id'] ) ? (int) $context['coach_user_id'] : 0;
        if ( $coach_id <= 0 ) {
            return array();
        }
        $user = get_userdata( $coach_id );
        if ( ! $user || empty( $user->user_email ) ) {
            return array();
        }
        return array( array( 'email' => $user->user_email, 'user_id' => $coach_id ) );
    }

    /**
     * Resolve the observer on a classroom visit (the leader who does the
     * observation). Uses `$context['leader_user_id']` populated by
     * `load_classroom_visit_context()`.
     *
     * Chris Love's spreadsheet routes classroom-visit reminders and overdue
     * notices to the observer, not the triggering_user — those two can
     * differ under cron dispatch when `triggering_user` is resolved from
     * the cycle-scoped query rather than the per-visit participant set.
     *
     * @param array $context
     * @return array Array of { email, user_id } (0 or 1 row).
     */
    private function resolve_observer( array $context ) {
        $leader_id = isset( $context['leader_user_id'] ) ? (int) $context['leader_user_id'] : 0;
        if ( $leader_id <= 0 ) {
            return array();
        }
        $user = get_userdata( $leader_id );
        if ( ! $user || empty( $user->user_email ) ) {
            return array();
        }
        return array( array( 'email' => $user->user_email, 'user_id' => $leader_id ) );
    }

    /**
     * Resolve all teachers on the triggering mentor's team(s) within the
     * current cycle. Returns N recipients (zero or more).
     *
     * Chris Love's spreadsheet routes the RP Session Window Opens (7d) email
     * to the triggering mentor (primary) AND the teachers on that mentor's
     * team (CC). Fan-out to every teacher in the cycle would flood unrelated
     * people — the body copy ("your Begin to ECSEL Reflective Practice
     * Session window", "Mentors and Teachers should be looking to book")
     * implies a known small group.
     *
     * Required context:
     *   - user_id  — the triggering mentor's WP user ID
     *   - cycle_id — the cycle the mentor is active in
     *
     * Role filtering is done in PHP via HL_Roles::has_role() (NEVER raw
     * FIND_IN_SET or LIKE '%teacher%' — see memory reference
     * reference_hl_enrollment_roles_json.md and Track 3 Task 1).
     *
     * @param array $context
     * @return array Array of { email, user_id } — zero or more rows.
     */
    private function resolve_mentor_team_teachers( array $context ) {
        global $wpdb;
        $user_id  = $context['user_id']  ?? null;
        $cycle_id = $context['cycle_id'] ?? null;

        if ( ! $user_id || ! $cycle_id ) {
            // Rate-limited telemetry — a misconfigured workflow could retry
            // thousands of times per day. One row per 5 minutes is enough.
            $lock_key = 'hl_resolver_missing_ctx_mentor_team_teachers';
            if ( ! get_transient( $lock_key ) && class_exists( 'HL_Audit_Service' ) ) {
                HL_Audit_Service::log( 'email_resolver_missing_context', array(
                    'reason' => 'mentor_team_teachers requires user_id + cycle_id',
                ) );
                set_transient( $lock_key, 1, 5 * MINUTE_IN_SECONDS );
            }
            return array();
        }

        // Step 1: find the mentor's enrollment in the cycle.
        $mentor_enrollment_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT enrollment_id FROM {$wpdb->prefix}hl_enrollment
             WHERE user_id = %d AND cycle_id = %d AND status IN ('active','warning')
             LIMIT 1",
            $user_id, $cycle_id
        ) );
        $this->log_db_error_if_any( 'mentor_team_teachers:mentor_enrollment_lookup' );
        if ( ! $mentor_enrollment_id ) {
            return array();
        }

        // Step 2: join mentor → team → other members in the same cycle.
        // We intentionally do NOT role-filter in SQL (avoids FIND_IN_SET /
        // LIKE '%teacher%' bugs on legacy JSON rows). Post-filter in PHP
        // via HL_Roles::has_role() so both CSV and legacy JSON stores work.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT e.user_id, u.user_email, e.roles
             FROM {$wpdb->prefix}hl_team_membership mentor_tm
             INNER JOIN {$wpdb->prefix}hl_team t
                 ON t.team_id = mentor_tm.team_id
                AND t.cycle_id = %d
             INNER JOIN {$wpdb->prefix}hl_team_membership teacher_tm
                 ON teacher_tm.team_id = mentor_tm.team_id
                AND teacher_tm.enrollment_id <> mentor_tm.enrollment_id
             INNER JOIN {$wpdb->prefix}hl_enrollment e
                 ON e.enrollment_id = teacher_tm.enrollment_id
                AND e.status IN ('active','warning')
             INNER JOIN {$wpdb->users} u
                 ON u.ID = e.user_id
             WHERE mentor_tm.enrollment_id = %d
             ORDER BY e.user_id ASC",
            $cycle_id, $mentor_enrollment_id
        ) );
        $this->log_db_error_if_any( 'mentor_team_teachers:team_join' );

        $results = array();
        $seen    = array();
        foreach ( (array) $rows as $row ) {
            if ( empty( $row->user_email ) ) {
                continue;
            }
            // Canonical role match — exact, handles CSV + legacy JSON.
            if ( ! class_exists( 'HL_Roles' ) || ! HL_Roles::has_role( $row->roles, 'teacher' ) ) {
                continue;
            }
            $uid = (int) $row->user_id;
            if ( isset( $seen[ $uid ] ) ) {
                continue; // Dedup when a user appears in multiple teams.
            }
            $seen[ $uid ] = true;
            $results[] = array( 'email' => $row->user_email, 'user_id' => $uid );
        }

        return $results;
    }

    /**
     * Resolve the mentor of the triggering user within the current cycle.
     *
     * `hl_team_membership.membership_type` distinguishes mentors from members
     * within a team. Given the triggering user's enrollment, find their team
     * and return the mentor enrollment's user. Requires `user_id` and
     * `cycle_id` in context; audit-logs missing context and returns empty.
     *
     * @param array $context Context array.
     * @return array Array of { email, user_id } (0 or 1 row).
     */
    private function resolve_assigned_mentor( array $context ) {
        global $wpdb;
        $user_id  = $context['user_id']  ?? null;
        $cycle_id = $context['cycle_id'] ?? null;

        if ( ! $user_id || ! $cycle_id ) {
            // Rate-limit the telemetry: a misconfigured cron workflow could
            // hit this path thousands of times per day. One audit row per
            // 5 minutes is enough signal for operators.
            $lock_key = 'hl_resolver_missing_ctx_assigned_mentor';
            if ( ! get_transient( $lock_key ) && class_exists( 'HL_Audit_Service' ) ) {
                HL_Audit_Service::log( 'email_resolver_missing_context', array(
                    'reason' => 'assigned_mentor requires user_id + cycle_id',
                ) );
                set_transient( $lock_key, 1, 5 * MINUTE_IN_SECONDS );
            }
            return array();
        }

        // Find the user's enrollment in this cycle.
        $enrollment_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT enrollment_id FROM {$wpdb->prefix}hl_enrollment
             WHERE user_id = %d AND cycle_id = %d AND status IN ('active','warning')
             LIMIT 1",
            $user_id, $cycle_id
        ) );
        $this->log_db_error_if_any( 'assigned_mentor:enrollment_lookup' );
        if ( ! $enrollment_id ) {
            return array();
        }

        // Find the mentor enrollment in the same team, scoped to this cycle.
        // Exclude the triggering user from being their own mentor (happens
        // when the triggering user IS the team mentor — we don't want to
        // email someone about their own action).
        $mentor_enrollment_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT mentor_tm.enrollment_id
             FROM {$wpdb->prefix}hl_team_membership user_tm
             INNER JOIN {$wpdb->prefix}hl_team_membership mentor_tm
                 ON user_tm.team_id = mentor_tm.team_id
                AND mentor_tm.membership_type = 'mentor'
                AND mentor_tm.enrollment_id <> user_tm.enrollment_id
             INNER JOIN {$wpdb->prefix}hl_team t
                 ON t.team_id = user_tm.team_id
                AND t.cycle_id = %d
             WHERE user_tm.enrollment_id = %d
             ORDER BY mentor_tm.team_id ASC, mentor_tm.enrollment_id ASC
             LIMIT 1",
            $cycle_id, $enrollment_id
        ) );
        $this->log_db_error_if_any( 'assigned_mentor:mentor_join' );
        if ( ! $mentor_enrollment_id ) {
            return array();
        }

        $mentor_user_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT user_id FROM {$wpdb->prefix}hl_enrollment WHERE enrollment_id = %d LIMIT 1",
            $mentor_enrollment_id
        ) );
        $this->log_db_error_if_any( 'assigned_mentor:user_lookup' );
        if ( ! $mentor_user_id ) {
            return array();
        }

        $user = get_userdata( (int) $mentor_user_id );
        if ( ! $user ) {
            return array();
        }

        return array( array( 'email' => $user->user_email, 'user_id' => (int) $mentor_user_id ) );
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

        // Normalise the role query and reject poison.
        $role = strtolower( trim( sanitize_text_field( $role ) ) );
        if ( $role === '' || strpos( $role, ',' ) !== false ) {
            // Rate-limit the audit write — a misconfigured workflow can retry
            // the same bad value every cron tick. One row per distinct value
            // per 5 minutes is enough signal for operators.
            if ( class_exists( 'HL_Audit_Service' ) ) {
                $lock_key = 'hl_resolver_rejected_role_' . substr( sha1( (string) $role ), 0, 16 );
                if ( ! get_transient( $lock_key ) ) {
                    HL_Audit_Service::log( 'email_resolver_rejected_role', array(
                        'reason' => 'invalid_role_value',
                        'role'   => $role,
                    ) );
                    set_transient( $lock_key, 1, 5 * MINUTE_IN_SECONDS );
                }
            }
            return array();
        }

        // Post-scrub: FIND_IN_SET is exact. Pre-scrub: LIKE narrows, has_role
        // post-filters to eliminate substring false positives (e.g.
        // role:leader must NOT return school_leader users).
        if ( $this->scrub_done() ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT DISTINCT e.user_id, u.user_email, e.roles
                 FROM {$wpdb->prefix}hl_enrollment e
                 JOIN {$wpdb->users} u ON u.ID = e.user_id
                 WHERE e.cycle_id = %d AND e.status IN ('active','warning')
                   AND FIND_IN_SET(%s, e.roles) > 0",
                $cycle_id, $role
            ) );
            $this->log_db_error_if_any( 'role:user_query' );
        } else {
            // Pre-scrub: JOIN wp_users to avoid N+1, LIKE narrows, then PHP filter.
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT DISTINCT e.user_id, u.user_email, e.roles
                 FROM {$wpdb->prefix}hl_enrollment e
                 JOIN {$wpdb->users} u ON u.ID = e.user_id
                 WHERE e.cycle_id = %d AND e.status IN ('active','warning')
                   AND e.roles LIKE %s",
                $cycle_id,
                '%' . $wpdb->esc_like( $role ) . '%'
            ) );
            $this->log_db_error_if_any( 'role:user_query' );
        }

        $results = array();
        foreach ( (array) $rows as $row ) {
            if ( empty( $row->user_email ) ) {
                continue;
            }
            // Post-filter — works for both branches but only strictly necessary pre-scrub.
            if ( class_exists( 'HL_Roles' ) && ! HL_Roles::has_role( $row->roles, $role ) ) {
                continue;
            }
            $results[] = array( 'email' => $row->user_email, 'user_id' => (int) $row->user_id );
        }

        return $results;
    }
}
