<?php
if (!defined('ABSPATH')) exit;

/**
 * Coach Assignment Service
 *
 * Manages coach-to-participant assignments at three scope levels
 * (school, team, enrollment) with "most specific wins" resolution.
 *
 * @package HL_Core
 */
class HL_Coach_Assignment_Service {

    // =========================================================================
    // Assignment CRUD
    // =========================================================================

    /**
     * Create a coach assignment.
     *
     * @param array $data Keys: coach_user_id, scope_type, scope_id, cohort_id, effective_from (optional).
     * @return int|WP_Error coach_assignment_id on success.
     */
    public function assign_coach($data) {
        global $wpdb;

        $required = array('coach_user_id', 'scope_type', 'scope_id', 'cohort_id');
        foreach ($required as $key) {
            if (empty($data[$key])) {
                return new WP_Error('missing_field', sprintf(__('Missing required field: %s', 'hl-core'), $key));
            }
        }

        $valid_scopes = array('school', 'team', 'enrollment');
        $scope_type = sanitize_text_field($data['scope_type']);
        if (!in_array($scope_type, $valid_scopes, true)) {
            return new WP_Error('invalid_scope', __('Invalid scope type.', 'hl-core'));
        }

        $effective_from = !empty($data['effective_from'])
            ? sanitize_text_field($data['effective_from'])
            : current_time('Y-m-d');

        $insert = array(
            'coach_user_id'  => absint($data['coach_user_id']),
            'scope_type'     => $scope_type,
            'scope_id'       => absint($data['scope_id']),
            'cohort_id'      => absint($data['cohort_id']),
            'effective_from' => $effective_from,
            'effective_to'   => null,
        );

        $result = $wpdb->insert($wpdb->prefix . 'hl_coach_assignment', $insert);

        if ($result === false) {
            return new WP_Error('db_error', __('Failed to create coach assignment.', 'hl-core'));
        }

        $id = $wpdb->insert_id;

        HL_Audit_Service::log('coach_assignment.created', array(
            'entity_type' => 'coach_assignment',
            'entity_id'   => $id,
            'cohort_id'   => $insert['cohort_id'],
            'after_data'  => $insert,
        ));

        return $id;
    }

    /**
     * Reassign coach: close the old assignment and create a new one.
     *
     * @param int   $old_assignment_id The assignment to close.
     * @param int   $new_coach_user_id The new coach.
     * @return int|WP_Error New assignment ID.
     */
    public function reassign_coach($old_assignment_id, $new_coach_user_id) {
        global $wpdb;

        $old = $this->get_assignment($old_assignment_id);
        if (!$old) {
            return new WP_Error('not_found', __('Assignment not found.', 'hl-core'));
        }

        $today = current_time('Y-m-d');

        // Close the old assignment.
        $wpdb->update(
            $wpdb->prefix . 'hl_coach_assignment',
            array('effective_to' => $today, 'updated_at' => current_time('mysql')),
            array('coach_assignment_id' => $old_assignment_id)
        );

        HL_Audit_Service::log('coach_assignment.closed', array(
            'entity_type' => 'coach_assignment',
            'entity_id'   => $old_assignment_id,
            'cohort_id'   => $old['cohort_id'],
            'before_data' => array('coach_user_id' => $old['coach_user_id']),
            'after_data'  => array('effective_to' => $today),
        ));

        // Create new assignment.
        return $this->assign_coach(array(
            'coach_user_id'  => absint($new_coach_user_id),
            'scope_type'     => $old['scope_type'],
            'scope_id'       => $old['scope_id'],
            'cohort_id'      => $old['cohort_id'],
            'effective_from' => $today,
        ));
    }

    /**
     * Get a single assignment by ID.
     *
     * @param int $assignment_id
     * @return array|null
     */
    public function get_assignment($assignment_id) {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT ca.*, u.display_name AS coach_name, u.user_email AS coach_email
             FROM {$wpdb->prefix}hl_coach_assignment ca
             LEFT JOIN {$wpdb->users} u ON ca.coach_user_id = u.ID
             WHERE ca.coach_assignment_id = %d",
            $assignment_id
        ), ARRAY_A) ?: null;
    }

    /**
     * Delete (hard-delete) a coach assignment.
     *
     * @param int $assignment_id
     * @return bool|WP_Error
     */
    public function delete_assignment($assignment_id) {
        global $wpdb;

        $before = $this->get_assignment($assignment_id);
        if (!$before) {
            return new WP_Error('not_found', __('Assignment not found.', 'hl-core'));
        }

        $result = $wpdb->delete(
            $wpdb->prefix . 'hl_coach_assignment',
            array('coach_assignment_id' => $assignment_id)
        );

        if ($result === false) {
            return new WP_Error('db_error', __('Failed to delete assignment.', 'hl-core'));
        }

        HL_Audit_Service::log('coach_assignment.deleted', array(
            'entity_type' => 'coach_assignment',
            'entity_id'   => $assignment_id,
            'cohort_id'   => $before['cohort_id'],
            'before_data' => $before,
        ));

        return true;
    }

    // =========================================================================
    // Resolution Logic (Most Specific Wins)
    // =========================================================================

    /**
     * Resolve the coach for a given enrollment in a cohort.
     *
     * Resolution order (most specific wins):
     *   1. enrollment-level → scope_id = enrollment_id
     *   2. team-level       → scope_id = team_id (from team_membership)
     *   3. school-level     → scope_id = school_id (from enrollment)
     *
     * "Active" = effective_from <= today AND (effective_to IS NULL OR effective_to >= today).
     *
     * @param int $enrollment_id
     * @param int $cohort_id
     * @return array|null Coach data (user_id, display_name, email, scope_type, assignment_id) or null.
     */
    public function get_coach_for_enrollment($enrollment_id, $cohort_id) {
        global $wpdb;

        $today = current_time('Y-m-d');

        // 1. Enrollment-level assignment.
        $coach = $wpdb->get_row($wpdb->prepare(
            "SELECT ca.coach_assignment_id, ca.coach_user_id, ca.scope_type,
                    u.display_name AS coach_name, u.user_email AS coach_email
             FROM {$wpdb->prefix}hl_coach_assignment ca
             LEFT JOIN {$wpdb->users} u ON ca.coach_user_id = u.ID
             WHERE ca.cohort_id = %d
               AND ca.scope_type = 'enrollment'
               AND ca.scope_id = %d
               AND ca.effective_from <= %s
               AND (ca.effective_to IS NULL OR ca.effective_to >= %s)
             ORDER BY ca.coach_assignment_id DESC
             LIMIT 1",
            $cohort_id, $enrollment_id, $today, $today
        ), ARRAY_A);

        if ($coach) {
            return $coach;
        }

        // 2. Team-level assignment.
        $team_id = $wpdb->get_var($wpdb->prepare(
            "SELECT tm.team_id FROM {$wpdb->prefix}hl_team_membership tm
             JOIN {$wpdb->prefix}hl_team t ON tm.team_id = t.team_id
             WHERE tm.enrollment_id = %d AND t.cohort_id = %d
             LIMIT 1",
            $enrollment_id, $cohort_id
        ));

        if ($team_id) {
            $coach = $wpdb->get_row($wpdb->prepare(
                "SELECT ca.coach_assignment_id, ca.coach_user_id, ca.scope_type,
                        u.display_name AS coach_name, u.user_email AS coach_email
                 FROM {$wpdb->prefix}hl_coach_assignment ca
                 LEFT JOIN {$wpdb->users} u ON ca.coach_user_id = u.ID
                 WHERE ca.cohort_id = %d
                   AND ca.scope_type = 'team'
                   AND ca.scope_id = %d
                   AND ca.effective_from <= %s
                   AND (ca.effective_to IS NULL OR ca.effective_to >= %s)
                 ORDER BY ca.coach_assignment_id DESC
                 LIMIT 1",
                $cohort_id, $team_id, $today, $today
            ), ARRAY_A);

            if ($coach) {
                return $coach;
            }
        }

        // 3. School-level assignment.
        $school_id = $wpdb->get_var($wpdb->prepare(
            "SELECT school_id FROM {$wpdb->prefix}hl_enrollment WHERE enrollment_id = %d",
            $enrollment_id
        ));

        if ($school_id) {
            $coach = $wpdb->get_row($wpdb->prepare(
                "SELECT ca.coach_assignment_id, ca.coach_user_id, ca.scope_type,
                        u.display_name AS coach_name, u.user_email AS coach_email
                 FROM {$wpdb->prefix}hl_coach_assignment ca
                 LEFT JOIN {$wpdb->users} u ON ca.coach_user_id = u.ID
                 WHERE ca.cohort_id = %d
                   AND ca.scope_type = 'school'
                   AND ca.scope_id = %d
                   AND ca.effective_from <= %s
                   AND (ca.effective_to IS NULL OR ca.effective_to >= %s)
                 ORDER BY ca.coach_assignment_id DESC
                 LIMIT 1",
                $cohort_id, $school_id, $today, $today
            ), ARRAY_A);

            if ($coach) {
                return $coach;
            }
        }

        return null;
    }

    // =========================================================================
    // Query Methods
    // =========================================================================

    /**
     * Get all current assignments for a cohort.
     *
     * @param int $cohort_id
     * @return array
     */
    public function get_assignments_by_cohort($cohort_id) {
        global $wpdb;

        $today = current_time('Y-m-d');

        return $wpdb->get_results($wpdb->prepare(
            "SELECT ca.*, u.display_name AS coach_name, u.user_email AS coach_email
             FROM {$wpdb->prefix}hl_coach_assignment ca
             LEFT JOIN {$wpdb->users} u ON ca.coach_user_id = u.ID
             WHERE ca.cohort_id = %d
               AND ca.effective_from <= %s
               AND (ca.effective_to IS NULL OR ca.effective_to >= %s)
             ORDER BY ca.scope_type ASC, ca.scope_id ASC",
            $cohort_id, $today, $today
        ), ARRAY_A) ?: array();
    }

    /**
     * Get all assignments (including historical) for a cohort.
     *
     * @param int $cohort_id
     * @return array
     */
    public function get_all_assignments_by_cohort($cohort_id) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT ca.*, u.display_name AS coach_name, u.user_email AS coach_email
             FROM {$wpdb->prefix}hl_coach_assignment ca
             LEFT JOIN {$wpdb->users} u ON ca.coach_user_id = u.ID
             WHERE ca.cohort_id = %d
             ORDER BY ca.scope_type ASC, ca.effective_from DESC",
            $cohort_id
        ), ARRAY_A) ?: array();
    }

    /**
     * Get the coach's roster — all participants assigned to a coach in a cohort.
     *
     * Finds enrollments where the coach is the resolved coach via any scope.
     *
     * @param int $coach_user_id
     * @param int $cohort_id
     * @return array Enrollment data with user info.
     */
    public function get_coach_roster($coach_user_id, $cohort_id) {
        global $wpdb;

        $today = current_time('Y-m-d');

        // Get all active assignments for this coach in this cohort.
        $assignments = $wpdb->get_results($wpdb->prepare(
            "SELECT scope_type, scope_id FROM {$wpdb->prefix}hl_coach_assignment
             WHERE coach_user_id = %d AND cohort_id = %d
               AND effective_from <= %s
               AND (effective_to IS NULL OR effective_to >= %s)",
            $coach_user_id, $cohort_id, $today, $today
        ), ARRAY_A);

        if (empty($assignments)) {
            return array();
        }

        // Collect enrollment IDs from all scopes.
        $enrollment_ids = array();

        foreach ($assignments as $a) {
            switch ($a['scope_type']) {
                case 'enrollment':
                    $enrollment_ids[] = absint($a['scope_id']);
                    break;

                case 'team':
                    $team_enrollments = $wpdb->get_col($wpdb->prepare(
                        "SELECT enrollment_id FROM {$wpdb->prefix}hl_team_membership WHERE team_id = %d",
                        $a['scope_id']
                    ));
                    $enrollment_ids = array_merge($enrollment_ids, array_map('absint', $team_enrollments));
                    break;

                case 'school':
                    $school_enrollments = $wpdb->get_col($wpdb->prepare(
                        "SELECT enrollment_id FROM {$wpdb->prefix}hl_enrollment
                         WHERE cohort_id = %d AND school_id = %d AND status = 'active'",
                        $cohort_id, $a['scope_id']
                    ));
                    $enrollment_ids = array_merge($enrollment_ids, array_map('absint', $school_enrollments));
                    break;
            }
        }

        $enrollment_ids = array_unique(array_filter($enrollment_ids));

        if (empty($enrollment_ids)) {
            return array();
        }

        $in_ids = implode(',', $enrollment_ids);

        return $wpdb->get_results(
            "SELECT e.enrollment_id, e.cohort_id, e.roles, e.school_id,
                    u.ID AS user_id, u.display_name, u.user_email
             FROM {$wpdb->prefix}hl_enrollment e
             LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
             WHERE e.enrollment_id IN ({$in_ids}) AND e.status = 'active'
             ORDER BY u.display_name ASC",
            ARRAY_A
        ) ?: array();
    }

    /**
     * Get coaching sessions for a specific participant enrollment.
     *
     * @param int $enrollment_id
     * @param int $cohort_id
     * @return array
     */
    public function get_sessions_for_enrollment($enrollment_id, $cohort_id) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT cs.*, u.display_name AS coach_name
             FROM {$wpdb->prefix}hl_coaching_session cs
             LEFT JOIN {$wpdb->users} u ON cs.coach_user_id = u.ID
             WHERE cs.mentor_enrollment_id = %d AND cs.cohort_id = %d
             ORDER BY cs.session_datetime DESC",
            $enrollment_id, $cohort_id
        ), ARRAY_A) ?: array();
    }
}
