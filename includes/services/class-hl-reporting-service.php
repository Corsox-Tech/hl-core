<?php
if (!defined('ABSPATH')) exit;

class HL_Reporting_Service {

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Listen for rollup recomputation requests from JFB, LearnDash, coaching, overrides
        add_action('hl_core_recompute_rollups', array($this, 'compute_rollups'), 10, 1);
    }

    // =========================================================================
    // Completion Rollup Engine
    // =========================================================================

    /**
     * Compute and cache completion rollups for an enrollment
     *
     * Calculates pathway_completion_percent as the weighted average of all
     * activity states for the enrollment's assigned pathway. Writes the
     * result to hl_completion_rollup.
     *
     * @param int $enrollment_id
     * @return array|WP_Error Rollup data or error
     */
    public function compute_rollups($enrollment_id) {
        global $wpdb;

        $enrollment_id = absint($enrollment_id);
        if (!$enrollment_id) {
            return new WP_Error('invalid_enrollment', __('Invalid enrollment ID.', 'hl-core'));
        }

        // Get enrollment details
        $enrollment = $wpdb->get_row($wpdb->prepare(
            "SELECT enrollment_id, cohort_id, assigned_pathway_id, user_id
             FROM {$wpdb->prefix}hl_enrollment
             WHERE enrollment_id = %d",
            $enrollment_id
        ));

        if (!$enrollment) {
            return new WP_Error('not_found', __('Enrollment not found.', 'hl-core'));
        }

        if (empty($enrollment->assigned_pathway_id)) {
            // No pathway assigned — nothing to compute
            return array(
                'enrollment_id'             => $enrollment_id,
                'cohort_id'                 => $enrollment->cohort_id,
                'pathway_completion_percent' => 0.0,
                'cohort_completion_percent'  => 0.0,
            );
        }

        // Get all active activities for this pathway
        $activities = $wpdb->get_results($wpdb->prepare(
            "SELECT activity_id, activity_type, weight, external_ref
             FROM {$wpdb->prefix}hl_activity
             WHERE pathway_id = %d AND status = 'active'",
            $enrollment->assigned_pathway_id
        ));

        if (empty($activities)) {
            $this->upsert_rollup($enrollment_id, $enrollment->cohort_id, 0.0, 0.0);
            return array(
                'enrollment_id'             => $enrollment_id,
                'cohort_id'                 => $enrollment->cohort_id,
                'pathway_completion_percent' => 0.0,
                'cohort_completion_percent'  => 0.0,
            );
        }

        // Get all activity states for this enrollment (keyed by activity_id)
        $activity_ids = wp_list_pluck($activities, 'activity_id');
        $id_placeholders = implode(',', array_fill(0, count($activity_ids), '%d'));

        $states = $wpdb->get_results($wpdb->prepare(
            "SELECT activity_id, completion_percent, completion_status
             FROM {$wpdb->prefix}hl_activity_state
             WHERE enrollment_id = %d AND activity_id IN ($id_placeholders)",
            array_merge(array($enrollment_id), $activity_ids)
        ));

        $state_map = array();
        foreach ($states as $state) {
            $state_map[$state->activity_id] = $state;
        }

        // Compute weighted average
        $total_weight = 0.0;
        $weighted_sum = 0.0;

        foreach ($activities as $activity) {
            $weight = floatval($activity->weight);
            if ($weight <= 0) {
                $weight = 1.0;
            }
            $total_weight += $weight;

            $percent = 0;
            if (isset($state_map[$activity->activity_id])) {
                $percent = intval($state_map[$activity->activity_id]->completion_percent);
            } else {
                // No state record — try to compute for LearnDash courses (live progress)
                $percent = $this->get_live_activity_percent($activity, $enrollment);
            }

            $weighted_sum += $weight * $percent;
        }

        $pathway_percent = ($total_weight > 0) ? round($weighted_sum / $total_weight, 2) : 0.0;

        // v1: cohort_completion_percent = pathway_completion_percent (single pathway)
        $cohort_percent = $pathway_percent;

        // Upsert rollup
        $this->upsert_rollup($enrollment_id, $enrollment->cohort_id, $pathway_percent, $cohort_percent);

        return array(
            'enrollment_id'             => $enrollment_id,
            'cohort_id'                 => $enrollment->cohort_id,
            'pathway_completion_percent' => $pathway_percent,
            'cohort_completion_percent'  => $cohort_percent,
        );
    }

    /**
     * Get live activity completion percent for activities without a cached state
     *
     * For LearnDash courses, queries LearnDash directly for current progress.
     * For other types, returns 0.
     *
     * @param object $activity   Activity row
     * @param object $enrollment Enrollment row
     * @return int Completion percent 0-100
     */
    private function get_live_activity_percent($activity, $enrollment) {
        if ($activity->activity_type === 'learndash_course' && !empty($activity->external_ref)) {
            $ref = json_decode($activity->external_ref, true);
            if (is_array($ref) && !empty($ref['course_id'])) {
                $ld = HL_LearnDash_Integration::instance();
                if ($ld->is_active()) {
                    return $ld->get_course_progress_percent($enrollment->user_id, $ref['course_id']);
                }
            }
        }

        return 0;
    }

    /**
     * Upsert a completion rollup record
     *
     * @param int   $enrollment_id
     * @param int   $cohort_id
     * @param float $pathway_percent
     * @param float $cohort_percent
     */
    private function upsert_rollup($enrollment_id, $cohort_id, $pathway_percent, $cohort_percent) {
        global $wpdb;

        $now = current_time('mysql');

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT rollup_id FROM {$wpdb->prefix}hl_completion_rollup WHERE enrollment_id = %d",
            $enrollment_id
        ));

        $data = array(
            'cohort_id'                  => absint($cohort_id),
            'pathway_completion_percent' => $pathway_percent,
            'cohort_completion_percent'  => $cohort_percent,
            'last_computed_at'           => $now,
        );

        if ($existing) {
            $wpdb->update(
                $wpdb->prefix . 'hl_completion_rollup',
                $data,
                array('rollup_id' => $existing)
            );
        } else {
            $data['enrollment_id'] = $enrollment_id;
            $wpdb->insert($wpdb->prefix . 'hl_completion_rollup', $data);
        }
    }

    /**
     * Recompute rollups for all active enrollments in a cohort
     *
     * @param int $cohort_id
     * @return array Summary ['updated' => int, 'errors' => int]
     */
    public function recompute_cohort_rollups($cohort_id) {
        global $wpdb;

        $enrollments = $wpdb->get_col($wpdb->prepare(
            "SELECT enrollment_id FROM {$wpdb->prefix}hl_enrollment
             WHERE cohort_id = %d AND status = 'active'",
            $cohort_id
        ));

        $updated = 0;
        $errors  = 0;

        foreach ($enrollments as $enrollment_id) {
            $result = $this->compute_rollups($enrollment_id);
            if (is_wp_error($result)) {
                $errors++;
            } else {
                $updated++;
            }
        }

        return array('updated' => $updated, 'errors' => $errors);
    }

    // =========================================================================
    // Queries (existing + enhanced)
    // =========================================================================

    /**
     * Get enrollment completion percentage
     *
     * @param int $enrollment_id
     * @return float
     */
    public function get_enrollment_completion($enrollment_id) {
        global $wpdb;
        $rollup = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_completion_rollup WHERE enrollment_id = %d",
            $enrollment_id
        ), ARRAY_A);

        if ($rollup) {
            return floatval($rollup['cohort_completion_percent']);
        }

        // Compute on-the-fly if no cached rollup exists
        $result = $this->compute_rollups($enrollment_id);
        if (is_wp_error($result)) {
            return 0.0;
        }

        return floatval($result['cohort_completion_percent']);
    }

    /**
     * Get cohort summary metrics
     *
     * @param int $cohort_id
     * @return array
     */
    public function get_cohort_summary($cohort_id) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        $total_enrollments = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$prefix}hl_enrollment WHERE cohort_id = %d AND status = 'active'",
            $cohort_id
        ));

        $avg_completion = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT AVG(cr.cohort_completion_percent) FROM {$prefix}hl_completion_rollup cr
             JOIN {$prefix}hl_enrollment e ON cr.enrollment_id = e.enrollment_id
             WHERE e.cohort_id = %d AND e.status = 'active'",
            $cohort_id
        ));

        return array(
            'total_enrollments'      => $total_enrollments,
            'avg_completion_percent'  => round($avg_completion, 2),
        );
    }

    /**
     * Get activity-level completion detail for an enrollment
     *
     * @param int $enrollment_id
     * @return array
     */
    public function get_activity_states($enrollment_id) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT s.*, a.title, a.activity_type, a.weight, a.ordering_hint, a.external_ref
             FROM {$wpdb->prefix}hl_activity_state s
             JOIN {$wpdb->prefix}hl_activity a ON s.activity_id = a.activity_id
             WHERE s.enrollment_id = %d
             ORDER BY a.ordering_hint ASC, a.activity_id ASC",
            $enrollment_id
        ), ARRAY_A) ?: array();
    }

    // =========================================================================
    // Scope-Filtered Queries (5.3)
    // =========================================================================

    /**
     * Get participant completion data with scope-based filtering
     *
     * Returns an array of enrollment rows with user info and completion data.
     * Filters by scope: cohort, school, district, team.
     *
     * @param array $filters Keys: cohort_id (required), school_id, district_id, team_id, role, status
     * @return array Array of rows with: enrollment_id, user_id, display_name, user_email, roles,
     *               school_name, team_name, cohort_completion_percent, pathway_completion_percent
     */
    public function get_participant_report( $filters ) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        $cohort_id = isset( $filters['cohort_id'] ) ? absint( $filters['cohort_id'] ) : 0;
        if ( ! $cohort_id ) {
            return array();
        }

        $where   = array( 'e.cohort_id = %d' );
        $params  = array( $cohort_id );

        // Status filter — default to active
        $status = isset( $filters['status'] ) ? sanitize_text_field( $filters['status'] ) : 'active';
        if ( in_array( $status, array( 'active', 'inactive' ), true ) ) {
            $where[]  = 'e.status = %s';
            $params[] = $status;
        }

        // School filter — use team.school_id via team_membership
        $school_id = isset( $filters['school_id'] ) ? absint( $filters['school_id'] ) : 0;
        if ( $school_id ) {
            $where[]  = 't.school_id = %d';
            $params[] = $school_id;
        }

        // District filter — schools whose parent orgunit is the district
        $district_id = isset( $filters['district_id'] ) ? absint( $filters['district_id'] ) : 0;
        if ( $district_id ) {
            $where[]  = 'school_ou.parent_orgunit_id = %d';
            $params[] = $district_id;
        }

        // Team filter
        $team_id = isset( $filters['team_id'] ) ? absint( $filters['team_id'] ) : 0;
        if ( $team_id ) {
            $where[]  = 'tm.team_id = %d';
            $params[] = $team_id;
        }

        // Role filter — JSON LIKE search on enrollment.roles
        $role = isset( $filters['role'] ) ? sanitize_text_field( $filters['role'] ) : '';
        if ( $role !== '' ) {
            $where[]  = 'e.roles LIKE %s';
            $params[] = '%"' . $wpdb->esc_like( $role ) . '"%';
        }

        $where_sql = implode( ' AND ', $where );

        $sql = "SELECT
                    e.enrollment_id,
                    e.user_id,
                    u.display_name,
                    u.user_email,
                    e.roles,
                    COALESCE( school_ou.name, '' ) AS school_name,
                    COALESCE( t.team_name, '' ) AS team_name,
                    COALESCE( cr.cohort_completion_percent, 0 ) AS cohort_completion_percent,
                    COALESCE( cr.pathway_completion_percent, 0 ) AS pathway_completion_percent
                FROM {$prefix}hl_enrollment e
                LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
                LEFT JOIN {$prefix}hl_completion_rollup cr ON e.enrollment_id = cr.enrollment_id
                LEFT JOIN {$prefix}hl_team_membership tm ON e.enrollment_id = tm.enrollment_id
                LEFT JOIN {$prefix}hl_team t ON tm.team_id = t.team_id AND t.cohort_id = e.cohort_id
                LEFT JOIN {$prefix}hl_orgunit school_ou ON t.school_id = school_ou.orgunit_id
                WHERE {$where_sql}
                GROUP BY e.enrollment_id
                ORDER BY u.display_name ASC";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) ?: array();
    }

    /**
     * Get activity completion detail for multiple enrollments
     *
     * Returns per-enrollment, per-activity completion data for a cohort.
     *
     * @param int   $cohort_id
     * @param array $enrollment_ids Optional; if empty, gets all active enrollments
     * @return array Keyed by enrollment_id => array of activity states
     */
    public function get_cohort_activity_detail( $cohort_id, $enrollment_ids = array() ) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        $cohort_id = absint( $cohort_id );
        if ( ! $cohort_id ) {
            return array();
        }

        // Get pathway(s) for this cohort
        $pathway_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT pathway_id FROM {$prefix}hl_pathway WHERE cohort_id = %d AND active_status = 1",
            $cohort_id
        ) );

        if ( empty( $pathway_ids ) ) {
            return array();
        }

        // Get all active activities for these pathways
        $pathway_placeholders = implode( ',', array_fill( 0, count( $pathway_ids ), '%d' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $activities = $wpdb->get_results( $wpdb->prepare(
            "SELECT activity_id, pathway_id, title, activity_type, weight, ordering_hint, external_ref
             FROM {$prefix}hl_activity
             WHERE pathway_id IN ({$pathway_placeholders}) AND status = 'active'
             ORDER BY ordering_hint ASC, activity_id ASC",
            $pathway_ids
        ), ARRAY_A );

        if ( empty( $activities ) ) {
            return array();
        }

        // Resolve enrollment IDs if not provided
        if ( empty( $enrollment_ids ) ) {
            $enrollment_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT enrollment_id FROM {$prefix}hl_enrollment
                 WHERE cohort_id = %d AND status = 'active'",
                $cohort_id
            ) );
        }

        if ( empty( $enrollment_ids ) ) {
            return array();
        }

        $enrollment_ids = array_map( 'absint', $enrollment_ids );
        $enrollment_placeholders = implode( ',', array_fill( 0, count( $enrollment_ids ), '%d' ) );

        $activity_ids = wp_list_pluck( $activities, 'activity_id' );
        $activity_placeholders = implode( ',', array_fill( 0, count( $activity_ids ), '%d' ) );

        // Get all activity states for these enrollments and activities
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $states = $wpdb->get_results( $wpdb->prepare(
            "SELECT enrollment_id, activity_id, completion_percent, completion_status, completed_at
             FROM {$prefix}hl_activity_state
             WHERE enrollment_id IN ({$enrollment_placeholders})
             AND activity_id IN ({$activity_placeholders})",
            array_merge( $enrollment_ids, $activity_ids )
        ), ARRAY_A );

        // Build state map keyed by enrollment_id => activity_id
        $state_map = array();
        foreach ( $states as $state ) {
            $eid = $state['enrollment_id'];
            $aid = $state['activity_id'];
            $state_map[ $eid ][ $aid ] = $state;
        }

        // Build result keyed by enrollment_id => array of activity data
        $result = array();
        foreach ( $enrollment_ids as $eid ) {
            $result[ $eid ] = array();
            foreach ( $activities as $activity ) {
                $aid = $activity['activity_id'];
                $state = isset( $state_map[ $eid ][ $aid ] ) ? $state_map[ $eid ][ $aid ] : null;
                $result[ $eid ][ $aid ] = array(
                    'activity_id'        => $aid,
                    'title'              => $activity['title'],
                    'activity_type'      => $activity['activity_type'],
                    'weight'             => $activity['weight'],
                    'ordering_hint'      => $activity['ordering_hint'],
                    'completion_percent' => $state ? intval( $state['completion_percent'] ) : 0,
                    'completion_status'  => $state ? $state['completion_status'] : 'not_started',
                    'completed_at'       => $state ? $state['completed_at'] : null,
                );
            }
        }

        return $result;
    }

    /**
     * Get completion summary grouped by school for a cohort
     *
     * @param int      $cohort_id
     * @param int|null $district_id Optional district filter
     * @return array Array of: school_id, school_name, participant_count, avg_completion_percent
     */
    public function get_school_summary( $cohort_id, $district_id = null ) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        $cohort_id = absint( $cohort_id );
        if ( ! $cohort_id ) {
            return array();
        }

        $where   = array( 'e.cohort_id = %d', "e.status = 'active'" );
        $params  = array( $cohort_id );

        if ( $district_id ) {
            $district_id = absint( $district_id );
            $where[]     = 'school_ou.parent_orgunit_id = %d';
            $params[]    = $district_id;
        }

        $where_sql = implode( ' AND ', $where );

        $sql = "SELECT
                    t.school_id,
                    school_ou.name AS school_name,
                    COUNT( DISTINCT e.enrollment_id ) AS participant_count,
                    ROUND( AVG( COALESCE( cr.cohort_completion_percent, 0 ) ), 2 ) AS avg_completion_percent
                FROM {$prefix}hl_enrollment e
                INNER JOIN {$prefix}hl_team_membership tm ON e.enrollment_id = tm.enrollment_id
                INNER JOIN {$prefix}hl_team t ON tm.team_id = t.team_id AND t.cohort_id = e.cohort_id
                INNER JOIN {$prefix}hl_orgunit school_ou ON t.school_id = school_ou.orgunit_id
                LEFT JOIN {$prefix}hl_completion_rollup cr ON e.enrollment_id = cr.enrollment_id
                WHERE {$where_sql}
                GROUP BY t.school_id, school_ou.name
                ORDER BY school_ou.name ASC";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) ?: array();
    }

    /**
     * Get completion summary grouped by team for a cohort
     *
     * @param int      $cohort_id
     * @param int|null $school_id Optional school filter
     * @return array Array of: team_id, team_name, school_name, member_count, avg_completion_percent
     */
    public function get_team_summary( $cohort_id, $school_id = null ) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        $cohort_id = absint( $cohort_id );
        if ( ! $cohort_id ) {
            return array();
        }

        $where   = array( 'e.cohort_id = %d', "e.status = 'active'" );
        $params  = array( $cohort_id );

        if ( $school_id ) {
            $school_id = absint( $school_id );
            $where[]   = 't.school_id = %d';
            $params[]  = $school_id;
        }

        $where_sql = implode( ' AND ', $where );

        $sql = "SELECT
                    t.team_id,
                    t.team_name,
                    COALESCE( school_ou.name, '' ) AS school_name,
                    COUNT( DISTINCT e.enrollment_id ) AS member_count,
                    ROUND( AVG( COALESCE( cr.cohort_completion_percent, 0 ) ), 2 ) AS avg_completion_percent
                FROM {$prefix}hl_enrollment e
                INNER JOIN {$prefix}hl_team_membership tm ON e.enrollment_id = tm.enrollment_id
                INNER JOIN {$prefix}hl_team t ON tm.team_id = t.team_id AND t.cohort_id = e.cohort_id
                LEFT JOIN {$prefix}hl_orgunit school_ou ON t.school_id = school_ou.orgunit_id
                LEFT JOIN {$prefix}hl_completion_rollup cr ON e.enrollment_id = cr.enrollment_id
                WHERE {$where_sql}
                GROUP BY t.team_id, t.team_name, school_ou.name
                ORDER BY school_ou.name ASC, t.team_name ASC";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) ?: array();
    }

    /**
     * Get ordered activity definitions for a cohort's active pathway(s)
     *
     * Helper used by CSV export to build per-activity columns.
     *
     * @param int $cohort_id
     * @return array Array of activity rows with: activity_id, title, activity_type, weight, ordering_hint
     */
    public function get_cohort_activities( $cohort_id ) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        $cohort_id = absint( $cohort_id );
        if ( ! $cohort_id ) {
            return array();
        }

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT a.activity_id, a.title, a.activity_type, a.weight, a.ordering_hint
             FROM {$prefix}hl_activity a
             INNER JOIN {$prefix}hl_pathway p ON a.pathway_id = p.pathway_id
             WHERE p.cohort_id = %d AND p.active_status = 1 AND a.status = 'active'
             ORDER BY a.ordering_hint ASC, a.activity_id ASC",
            $cohort_id
        ), ARRAY_A ) ?: array();
    }

    // =========================================================================
    // Cohort Group (Cross-Cohort) Queries
    // =========================================================================

    /**
     * Get group-level summary: one row per cohort in the group.
     *
     * @param int $group_id Cohort group ID.
     * @return array Array of: cohort_id, cohort_name, cohort_code, status, participant_count, avg_completion_percent
     */
    public function get_group_summary( $group_id ) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        $group_id = absint( $group_id );
        if ( ! $group_id ) {
            return array();
        }

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT
                c.cohort_id,
                c.cohort_name,
                c.cohort_code,
                c.status,
                COUNT( DISTINCT e.enrollment_id ) AS participant_count,
                ROUND( AVG( COALESCE( cr.cohort_completion_percent, 0 ) ), 2 ) AS avg_completion_percent
             FROM {$prefix}hl_cohort c
             LEFT JOIN {$prefix}hl_enrollment e ON c.cohort_id = e.cohort_id AND e.status = 'active'
             LEFT JOIN {$prefix}hl_completion_rollup cr ON e.enrollment_id = cr.enrollment_id
             WHERE c.cohort_group_id = %d
             GROUP BY c.cohort_id, c.cohort_name, c.cohort_code, c.status
             ORDER BY c.cohort_name ASC",
            $group_id
        ), ARRAY_A ) ?: array();
    }

    /**
     * Get aggregate metrics across all cohorts in a group.
     *
     * @param int $group_id
     * @return array Keys: total_cohorts, total_participants, avg_completion_percent
     */
    public function get_group_aggregate( $group_id ) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        $group_id = absint( $group_id );
        if ( ! $group_id ) {
            return array( 'total_cohorts' => 0, 'total_participants' => 0, 'avg_completion_percent' => 0 );
        }

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                COUNT( DISTINCT c.cohort_id ) AS total_cohorts,
                COUNT( DISTINCT e.enrollment_id ) AS total_participants,
                ROUND( AVG( COALESCE( cr.cohort_completion_percent, 0 ) ), 2 ) AS avg_completion_percent
             FROM {$prefix}hl_cohort c
             LEFT JOIN {$prefix}hl_enrollment e ON c.cohort_id = e.cohort_id AND e.status = 'active'
             LEFT JOIN {$prefix}hl_completion_rollup cr ON e.enrollment_id = cr.enrollment_id
             WHERE c.cohort_group_id = %d",
            $group_id
        ), ARRAY_A );

        return $row ?: array( 'total_cohorts' => 0, 'total_participants' => 0, 'avg_completion_percent' => 0 );
    }

    /**
     * Export group summary as CSV.
     *
     * @param int $group_id
     * @return string CSV content
     */
    public function export_group_summary_csv( $group_id ) {
        $rows = $this->get_group_summary( $group_id );

        $handle = fopen( 'php://temp', 'r+' );
        if ( $handle === false ) {
            return '';
        }

        fputcsv( $handle, array( 'Cohort Name', 'Code', 'Status', 'Participants', 'Avg Completion %' ) );

        foreach ( $rows as $row ) {
            fputcsv( $handle, array(
                $row['cohort_name'],
                $row['cohort_code'],
                $row['status'],
                $row['participant_count'],
                $row['avg_completion_percent'],
            ) );
        }

        rewind( $handle );
        $csv = stream_get_contents( $handle );
        fclose( $handle );

        return $csv;
    }

    // =========================================================================
    // CSV Export Methods (5.2)
    // =========================================================================

    /**
     * Export participant completion report as CSV
     *
     * @param array $filters        Same filters as get_participant_report()
     * @param bool  $include_activities Whether to include per-activity columns
     * @return string CSV content
     */
    public function export_completion_csv( $filters, $include_activities = true ) {
        $participants = $this->get_participant_report( $filters );
        $cohort_id   = isset( $filters['cohort_id'] ) ? absint( $filters['cohort_id'] ) : 0;

        // Resolve cohort name and code for the export header context
        $activities     = array();
        $activity_detail = array();

        if ( $include_activities && $cohort_id ) {
            $activities = $this->get_cohort_activities( $cohort_id );

            if ( ! empty( $participants ) && ! empty( $activities ) ) {
                $enrollment_ids = wp_list_pluck( $participants, 'enrollment_id' );
                $activity_detail = $this->get_cohort_activity_detail( $cohort_id, $enrollment_ids );
            }
        }

        // Build CSV
        $handle = fopen( 'php://temp', 'r+' );
        if ( $handle === false ) {
            return '';
        }

        // Header row
        $header = array( 'Name', 'Email', 'Roles', 'School', 'Team', 'Cohort Completion %', 'Pathway Completion %' );
        if ( $include_activities ) {
            foreach ( $activities as $activity ) {
                $header[] = $activity['title'] . ' (%)';
            }
        }
        fputcsv( $handle, $header );

        // Data rows
        foreach ( $participants as $row ) {
            $roles_raw = json_decode( $row['roles'], true );
            $roles_str = is_array( $roles_raw ) ? implode( ', ', $roles_raw ) : $row['roles'];

            $line = array(
                $row['display_name'],
                $row['user_email'],
                $roles_str,
                $row['school_name'],
                $row['team_name'],
                $row['cohort_completion_percent'],
                $row['pathway_completion_percent'],
            );

            if ( $include_activities ) {
                $eid = $row['enrollment_id'];
                foreach ( $activities as $activity ) {
                    $aid = $activity['activity_id'];
                    if ( isset( $activity_detail[ $eid ][ $aid ] ) ) {
                        $line[] = $activity_detail[ $eid ][ $aid ]['completion_percent'];
                    } else {
                        $line[] = 0;
                    }
                }
            }

            fputcsv( $handle, $line );
        }

        rewind( $handle );
        $csv = stream_get_contents( $handle );
        fclose( $handle );

        return $csv;
    }

    /**
     * Export school summary as CSV
     *
     * @param int      $cohort_id
     * @param int|null $district_id
     * @return string CSV content
     */
    public function export_school_summary_csv( $cohort_id, $district_id = null ) {
        $rows = $this->get_school_summary( $cohort_id, $district_id );

        $handle = fopen( 'php://temp', 'r+' );
        if ( $handle === false ) {
            return '';
        }

        fputcsv( $handle, array( 'School Name', 'Participant Count', 'Avg Completion %' ) );

        foreach ( $rows as $row ) {
            fputcsv( $handle, array(
                $row['school_name'],
                $row['participant_count'],
                $row['avg_completion_percent'],
            ) );
        }

        rewind( $handle );
        $csv = stream_get_contents( $handle );
        fclose( $handle );

        return $csv;
    }

    /**
     * Export team summary as CSV
     *
     * @param int      $cohort_id
     * @param int|null $school_id
     * @return string CSV content
     */
    public function export_team_summary_csv( $cohort_id, $school_id = null ) {
        $rows = $this->get_team_summary( $cohort_id, $school_id );

        $handle = fopen( 'php://temp', 'r+' );
        if ( $handle === false ) {
            return '';
        }

        fputcsv( $handle, array( 'Team Name', 'School Name', 'Member Count', 'Avg Completion %' ) );

        foreach ( $rows as $row ) {
            fputcsv( $handle, array(
                $row['team_name'],
                $row['school_name'],
                $row['member_count'],
                $row['avg_completion_percent'],
            ) );
        }

        rewind( $handle );
        $csv = stream_get_contents( $handle );
        fclose( $handle );

        return $csv;
    }

    // =========================================================================
    // Program vs Control Group Comparison (Phase 20)
    // =========================================================================

    /**
     * Get assessment comparison data for program vs control cohorts in a group.
     *
     * Returns per-section, per-item average scores for program cohorts (is_control_group=0)
     * vs control cohorts (is_control_group=1) within the same cohort group.
     *
     * @param int $group_id Cohort group ID.
     * @return array|null Comparison data or null if group has no control cohorts.
     */
    public function get_group_assessment_comparison( $group_id ) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        $group_id = absint( $group_id );
        if ( ! $group_id ) {
            return null;
        }

        // Get all cohorts in the group.
        $cohorts = $wpdb->get_results( $wpdb->prepare(
            "SELECT cohort_id, cohort_name, is_control_group
             FROM {$prefix}hl_cohort
             WHERE cohort_group_id = %d",
            $group_id
        ), ARRAY_A );

        if ( empty( $cohorts ) ) {
            return null;
        }

        $program_cohort_ids = array();
        $control_cohort_ids = array();
        $program_names      = array();
        $control_names      = array();

        foreach ( $cohorts as $c ) {
            if ( (int) $c['is_control_group'] === 1 ) {
                $control_cohort_ids[] = (int) $c['cohort_id'];
                $control_names[]      = $c['cohort_name'];
            } else {
                $program_cohort_ids[] = (int) $c['cohort_id'];
                $program_names[]      = $c['cohort_name'];
            }
        }

        // Need both program and control cohorts for comparison.
        if ( empty( $program_cohort_ids ) || empty( $control_cohort_ids ) ) {
            return null;
        }

        // Get the instrument used (first active instrument found).
        $instrument = $wpdb->get_row(
            "SELECT * FROM {$prefix}hl_teacher_assessment_instrument WHERE status = 'active' ORDER BY instrument_id ASC LIMIT 1",
            ARRAY_A
        );

        $sections_def = array();
        if ( $instrument && ! empty( $instrument['sections'] ) ) {
            $sections_def = json_decode( $instrument['sections'], true );
            if ( ! is_array( $sections_def ) ) {
                $sections_def = array();
            }
        }

        // Aggregate data for each group of cohorts.
        $program_data = $this->aggregate_assessment_responses( $program_cohort_ids, $sections_def );
        $control_data = $this->aggregate_assessment_responses( $control_cohort_ids, $sections_def );

        return array(
            'program' => array(
                'cohort_names'      => $program_names,
                'participant_count' => $program_data['participant_count'],
                'sections'          => $program_data['sections'],
            ),
            'control' => array(
                'cohort_names'      => $control_names,
                'participant_count' => $control_data['participant_count'],
                'sections'          => $control_data['sections'],
            ),
            'instrument' => $instrument,
        );
    }

    /**
     * Aggregate teacher assessment responses across multiple cohorts.
     *
     * @param array $cohort_ids   Array of cohort IDs to aggregate.
     * @param array $sections_def Instrument section definitions.
     * @return array Keys: participant_count, sections.
     */
    private function aggregate_assessment_responses( $cohort_ids, $sections_def ) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        if ( empty( $cohort_ids ) ) {
            return array( 'participant_count' => 0, 'sections' => array() );
        }

        $placeholders = implode( ',', array_fill( 0, count( $cohort_ids ), '%d' ) );

        // Get all submitted teacher assessment instances with responses_json.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $instances = $wpdb->get_results( $wpdb->prepare(
            "SELECT tai.instance_id, tai.phase, tai.responses_json, tai.instrument_id, tai.enrollment_id
             FROM {$prefix}hl_teacher_assessment_instance tai
             WHERE tai.cohort_id IN ({$placeholders})
             AND tai.status = 'submitted'
             AND tai.responses_json IS NOT NULL",
            $cohort_ids
        ), ARRAY_A );

        // Count unique enrollments (participants).
        $unique_enrollments = array();
        foreach ( $instances as $inst ) {
            $unique_enrollments[ $inst['enrollment_id'] ] = true;
        }
        $participant_count = count( $unique_enrollments );

        // Collect values: phase => section_key => item_key => [values].
        $collected = array( 'pre' => array(), 'post' => array() );

        foreach ( $instances as $inst ) {
            $phase = $inst['phase'];
            if ( ! in_array( $phase, array( 'pre', 'post' ), true ) ) {
                continue;
            }

            $responses = json_decode( $inst['responses_json'], true );
            if ( ! is_array( $responses ) ) {
                continue;
            }

            foreach ( $responses as $section_key => $items ) {
                if ( ! is_array( $items ) ) {
                    continue;
                }
                foreach ( $items as $item_key => $value ) {
                    if ( is_numeric( $value ) ) {
                        $collected[ $phase ][ $section_key ][ $item_key ][] = floatval( $value );
                    }
                }
            }
        }

        // Build section results keyed by section_key.
        $sections = array();

        foreach ( $sections_def as $sec ) {
            $section_key = isset( $sec['section_key'] ) ? $sec['section_key'] : '';
            if ( $section_key === '' ) {
                continue;
            }

            $title = isset( $sec['title'] ) ? $sec['title'] : $section_key;
            $items = isset( $sec['items'] ) ? $sec['items'] : array();

            $pre_items  = array();
            $post_items = array();

            foreach ( $items as $item ) {
                $item_key = isset( $item['key'] ) ? $item['key'] : '';
                if ( $item_key === '' ) {
                    continue;
                }

                // Pre phase.
                if ( isset( $collected['pre'][ $section_key ][ $item_key ] ) ) {
                    $vals = $collected['pre'][ $section_key ][ $item_key ];
                    $pre_items[ $item_key ] = array(
                        'mean' => round( array_sum( $vals ) / count( $vals ), 2 ),
                        'n'    => count( $vals ),
                        'sd'   => $this->compute_sd( $vals ),
                    );
                } else {
                    $pre_items[ $item_key ] = array( 'mean' => null, 'n' => 0, 'sd' => 0 );
                }

                // Post phase.
                if ( isset( $collected['post'][ $section_key ][ $item_key ] ) ) {
                    $vals = $collected['post'][ $section_key ][ $item_key ];
                    $post_items[ $item_key ] = array(
                        'mean' => round( array_sum( $vals ) / count( $vals ), 2 ),
                        'n'    => count( $vals ),
                        'sd'   => $this->compute_sd( $vals ),
                    );
                } else {
                    $post_items[ $item_key ] = array( 'mean' => null, 'n' => 0, 'sd' => 0 );
                }
            }

            $sections[ $section_key ] = array(
                'title' => $title,
                'items' => $items,
                'pre'   => $pre_items,
                'post'  => $post_items,
            );
        }

        return array(
            'participant_count' => $participant_count,
            'sections'          => $sections,
        );
    }

    /**
     * Compute sample standard deviation.
     *
     * @param array $values Numeric values.
     * @return float
     */
    private function compute_sd( $values ) {
        $n = count( $values );
        if ( $n < 2 ) {
            return 0.0;
        }
        $mean     = array_sum( $values ) / $n;
        $sum_sq   = 0.0;
        foreach ( $values as $v ) {
            $sum_sq += ( $v - $mean ) * ( $v - $mean );
        }
        return round( sqrt( $sum_sq / ( $n - 1 ) ), 4 );
    }

    /**
     * Export group comparison data as CSV.
     *
     * Flattens comparison data into one row per item per section with
     * program and control pre/post means, change values, and Cohen's d effect size.
     *
     * @param int $group_id Cohort group ID.
     * @return string CSV content or empty string.
     */
    public function export_group_comparison_csv( $group_id ) {
        $comparison = $this->get_group_assessment_comparison( $group_id );
        if ( ! $comparison ) {
            return '';
        }

        $handle = fopen( 'php://temp', 'r+' );
        if ( $handle === false ) {
            return '';
        }

        fputcsv( $handle, array(
            'Section',
            'Item',
            'Program Pre Mean',
            'Program Pre N',
            'Program Post Mean',
            'Program Post N',
            'Program Change',
            'Control Pre Mean',
            'Control Pre N',
            'Control Post Mean',
            'Control Post N',
            'Control Change',
            'Effect Size (Cohen\'s d)',
        ) );

        $program_sections = $comparison['program']['sections'];
        $control_sections = $comparison['control']['sections'];

        foreach ( $program_sections as $section_key => $section ) {
            $title = $section['title'];
            $items = isset( $section['items'] ) ? $section['items'] : array();

            foreach ( $items as $item ) {
                $item_key  = isset( $item['key'] ) ? $item['key'] : '';
                $item_text = isset( $item['text'] ) ? $item['text'] : $item_key;

                $p_pre  = isset( $section['pre'][ $item_key ] )  ? $section['pre'][ $item_key ]  : array( 'mean' => null, 'n' => 0, 'sd' => 0 );
                $p_post = isset( $section['post'][ $item_key ] ) ? $section['post'][ $item_key ] : array( 'mean' => null, 'n' => 0, 'sd' => 0 );

                $c_pre  = array( 'mean' => null, 'n' => 0, 'sd' => 0 );
                $c_post = array( 'mean' => null, 'n' => 0, 'sd' => 0 );
                if ( isset( $control_sections[ $section_key ] ) ) {
                    $c_sec  = $control_sections[ $section_key ];
                    $c_pre  = isset( $c_sec['pre'][ $item_key ] )  ? $c_sec['pre'][ $item_key ]  : $c_pre;
                    $c_post = isset( $c_sec['post'][ $item_key ] ) ? $c_sec['post'][ $item_key ] : $c_post;
                }

                // Compute change values.
                $p_change = ( $p_pre['mean'] !== null && $p_post['mean'] !== null )
                    ? round( $p_post['mean'] - $p_pre['mean'], 2 ) : '';
                $c_change = ( $c_pre['mean'] !== null && $c_post['mean'] !== null )
                    ? round( $c_post['mean'] - $c_pre['mean'], 2 ) : '';

                // Cohen's d = (program_change - control_change) / pooled_sd.
                $cohens_d = '';
                if ( $p_change !== '' && $c_change !== '' ) {
                    $pooled_sd = $this->compute_pooled_sd(
                        $p_pre['sd'], $p_pre['n'], $p_post['sd'], $p_post['n'],
                        $c_pre['sd'], $c_pre['n'], $c_post['sd'], $c_post['n']
                    );
                    if ( $pooled_sd > 0 ) {
                        $cohens_d = round( ( $p_change - $c_change ) / $pooled_sd, 3 );
                    }
                }

                fputcsv( $handle, array(
                    $title,
                    $item_text,
                    $p_pre['mean'] !== null ? $p_pre['mean'] : '',
                    $p_pre['n'],
                    $p_post['mean'] !== null ? $p_post['mean'] : '',
                    $p_post['n'],
                    $p_change,
                    $c_pre['mean'] !== null ? $c_pre['mean'] : '',
                    $c_pre['n'],
                    $c_post['mean'] !== null ? $c_post['mean'] : '',
                    $c_post['n'],
                    $c_change,
                    $cohens_d,
                ) );
            }
        }

        rewind( $handle );
        $csv = stream_get_contents( $handle );
        fclose( $handle );

        return $csv;
    }

    /**
     * Compute pooled standard deviation for Cohen's d across pre/post program/control.
     *
     * Uses the average of all four group SDs weighted by their sample sizes.
     *
     * @return float Pooled SD.
     */
    private function compute_pooled_sd( $sd1, $n1, $sd2, $n2, $sd3, $n3, $sd4, $n4 ) {
        $total_n = $n1 + $n2 + $n3 + $n4;
        if ( $total_n < 2 ) {
            return 0.0;
        }

        // Pooled variance: weighted sum of variances.
        $sum_sq = 0.0;
        $sum_df = 0;
        foreach ( array( array( $sd1, $n1 ), array( $sd2, $n2 ), array( $sd3, $n3 ), array( $sd4, $n4 ) ) as $pair ) {
            $sd = $pair[0];
            $n  = $pair[1];
            if ( $n > 1 ) {
                $sum_sq += ( $n - 1 ) * $sd * $sd;
                $sum_df += ( $n - 1 );
            }
        }

        if ( $sum_df < 1 ) {
            return 0.0;
        }

        return sqrt( $sum_sq / $sum_df );
    }
}
