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
     * Filters by scope: cohort, center, district, team.
     *
     * @param array $filters Keys: cohort_id (required), center_id, district_id, team_id, role, status
     * @return array Array of rows with: enrollment_id, user_id, display_name, user_email, roles,
     *               center_name, team_name, cohort_completion_percent, pathway_completion_percent
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

        // Center filter — use team.center_id via team_membership
        $center_id = isset( $filters['center_id'] ) ? absint( $filters['center_id'] ) : 0;
        if ( $center_id ) {
            $where[]  = 't.center_id = %d';
            $params[] = $center_id;
        }

        // District filter — centers whose parent orgunit is the district
        $district_id = isset( $filters['district_id'] ) ? absint( $filters['district_id'] ) : 0;
        if ( $district_id ) {
            $where[]  = 'center_ou.parent_orgunit_id = %d';
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
                    COALESCE( center_ou.name, '' ) AS center_name,
                    COALESCE( t.team_name, '' ) AS team_name,
                    COALESCE( cr.cohort_completion_percent, 0 ) AS cohort_completion_percent,
                    COALESCE( cr.pathway_completion_percent, 0 ) AS pathway_completion_percent
                FROM {$prefix}hl_enrollment e
                LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
                LEFT JOIN {$prefix}hl_completion_rollup cr ON e.enrollment_id = cr.enrollment_id
                LEFT JOIN {$prefix}hl_team_membership tm ON e.enrollment_id = tm.enrollment_id
                LEFT JOIN {$prefix}hl_team t ON tm.team_id = t.team_id AND t.cohort_id = e.cohort_id
                LEFT JOIN {$prefix}hl_orgunit center_ou ON t.center_id = center_ou.orgunit_id
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
     * Get completion summary grouped by center for a cohort
     *
     * @param int      $cohort_id
     * @param int|null $district_id Optional district filter
     * @return array Array of: center_id, center_name, participant_count, avg_completion_percent
     */
    public function get_center_summary( $cohort_id, $district_id = null ) {
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
            $where[]     = 'center_ou.parent_orgunit_id = %d';
            $params[]    = $district_id;
        }

        $where_sql = implode( ' AND ', $where );

        $sql = "SELECT
                    t.center_id,
                    center_ou.name AS center_name,
                    COUNT( DISTINCT e.enrollment_id ) AS participant_count,
                    ROUND( AVG( COALESCE( cr.cohort_completion_percent, 0 ) ), 2 ) AS avg_completion_percent
                FROM {$prefix}hl_enrollment e
                INNER JOIN {$prefix}hl_team_membership tm ON e.enrollment_id = tm.enrollment_id
                INNER JOIN {$prefix}hl_team t ON tm.team_id = t.team_id AND t.cohort_id = e.cohort_id
                INNER JOIN {$prefix}hl_orgunit center_ou ON t.center_id = center_ou.orgunit_id
                LEFT JOIN {$prefix}hl_completion_rollup cr ON e.enrollment_id = cr.enrollment_id
                WHERE {$where_sql}
                GROUP BY t.center_id, center_ou.name
                ORDER BY center_ou.name ASC";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) ?: array();
    }

    /**
     * Get completion summary grouped by team for a cohort
     *
     * @param int      $cohort_id
     * @param int|null $center_id Optional center filter
     * @return array Array of: team_id, team_name, center_name, member_count, avg_completion_percent
     */
    public function get_team_summary( $cohort_id, $center_id = null ) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        $cohort_id = absint( $cohort_id );
        if ( ! $cohort_id ) {
            return array();
        }

        $where   = array( 'e.cohort_id = %d', "e.status = 'active'" );
        $params  = array( $cohort_id );

        if ( $center_id ) {
            $center_id = absint( $center_id );
            $where[]   = 't.center_id = %d';
            $params[]  = $center_id;
        }

        $where_sql = implode( ' AND ', $where );

        $sql = "SELECT
                    t.team_id,
                    t.team_name,
                    COALESCE( center_ou.name, '' ) AS center_name,
                    COUNT( DISTINCT e.enrollment_id ) AS member_count,
                    ROUND( AVG( COALESCE( cr.cohort_completion_percent, 0 ) ), 2 ) AS avg_completion_percent
                FROM {$prefix}hl_enrollment e
                INNER JOIN {$prefix}hl_team_membership tm ON e.enrollment_id = tm.enrollment_id
                INNER JOIN {$prefix}hl_team t ON tm.team_id = t.team_id AND t.cohort_id = e.cohort_id
                LEFT JOIN {$prefix}hl_orgunit center_ou ON t.center_id = center_ou.orgunit_id
                LEFT JOIN {$prefix}hl_completion_rollup cr ON e.enrollment_id = cr.enrollment_id
                WHERE {$where_sql}
                GROUP BY t.team_id, t.team_name, center_ou.name
                ORDER BY center_ou.name ASC, t.team_name ASC";

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
        $header = array( 'Name', 'Email', 'Roles', 'Center', 'Team', 'Cohort Completion %', 'Pathway Completion %' );
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
                $row['center_name'],
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
     * Export center summary as CSV
     *
     * @param int      $cohort_id
     * @param int|null $district_id
     * @return string CSV content
     */
    public function export_center_summary_csv( $cohort_id, $district_id = null ) {
        $rows = $this->get_center_summary( $cohort_id, $district_id );

        $handle = fopen( 'php://temp', 'r+' );
        if ( $handle === false ) {
            return '';
        }

        fputcsv( $handle, array( 'Center Name', 'Participant Count', 'Avg Completion %' ) );

        foreach ( $rows as $row ) {
            fputcsv( $handle, array(
                $row['center_name'],
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
     * @param int|null $center_id
     * @return string CSV content
     */
    public function export_team_summary_csv( $cohort_id, $center_id = null ) {
        $rows = $this->get_team_summary( $cohort_id, $center_id );

        $handle = fopen( 'php://temp', 'r+' );
        if ( $handle === false ) {
            return '';
        }

        fputcsv( $handle, array( 'Team Name', 'Center Name', 'Member Count', 'Avg Completion %' ) );

        foreach ( $rows as $row ) {
            fputcsv( $handle, array(
                $row['team_name'],
                $row['center_name'],
                $row['member_count'],
                $row['avg_completion_percent'],
            ) );
        }

        rewind( $handle );
        $csv = stream_get_contents( $handle );
        fclose( $handle );

        return $csv;
    }
}
