<?php
if (!defined('ABSPATH')) exit;

/**
 * Classroom Visit Service
 *
 * Manages classroom visits linking school leaders and teachers, plus form submissions.
 *
 * @package HL_Core
 */
class HL_Classroom_Visit_Service {

    const VALID_STATUSES = array('pending', 'completed');

    /**
     * Create a classroom visit.
     *
     * @param array $data Keys: cycle_id, leader_enrollment_id, teacher_enrollment_id, classroom_id, visit_number, visit_date, notes.
     * @return int|WP_Error classroom_visit_id on success.
     */
    public function create_visit($data) {
        global $wpdb;

        if (empty($data['cycle_id'])) {
            return new WP_Error('missing_cycle', __('Cycle is required.', 'hl-core'));
        }
        if (empty($data['leader_enrollment_id'])) {
            return new WP_Error('missing_leader', __('Leader enrollment is required.', 'hl-core'));
        }
        if (empty($data['teacher_enrollment_id'])) {
            return new WP_Error('missing_teacher', __('Teacher enrollment is required.', 'hl-core'));
        }

        $insert_data = array(
            'classroom_visit_uuid'  => wp_generate_uuid4(),
            'cycle_id'              => absint($data['cycle_id']),
            'leader_enrollment_id'  => absint($data['leader_enrollment_id']),
            'teacher_enrollment_id' => absint($data['teacher_enrollment_id']),
            'classroom_id'          => !empty($data['classroom_id']) ? absint($data['classroom_id']) : null,
            'visit_number'          => !empty($data['visit_number']) ? absint($data['visit_number']) : 1,
            'status'                => 'pending',
            'visit_date'            => !empty($data['visit_date']) ? sanitize_text_field($data['visit_date']) : null,
            'notes'                 => !empty($data['notes']) ? wp_kses_post($data['notes']) : null,
            'created_at'            => current_time('mysql'),
            'updated_at'            => current_time('mysql'),
        );

        $result = $wpdb->insert($wpdb->prefix . 'hl_classroom_visit', $insert_data);

        if ($result === false) {
            return new WP_Error('db_error', __('Failed to create classroom visit.', 'hl-core'));
        }

        $visit_id = $wpdb->insert_id;

        HL_Audit_Service::log('classroom_visit.created', array(
            'entity_type' => 'classroom_visit',
            'entity_id'   => $visit_id,
            'cycle_id'    => $insert_data['cycle_id'],
            'after_data'  => array(
                'cycle_id'              => $insert_data['cycle_id'],
                'leader_enrollment_id'  => $insert_data['leader_enrollment_id'],
                'teacher_enrollment_id' => $insert_data['teacher_enrollment_id'],
                'visit_number'          => $insert_data['visit_number'],
            ),
        ));

        return $visit_id;
    }

    /**
     * Get a single classroom visit by ID with joined names.
     *
     * @param int $classroom_visit_id
     * @return array|null
     */
    public function get_visit($classroom_visit_id) {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT cv.*,
                    u_leader.display_name AS leader_name,
                    u_teacher.display_name AS teacher_name,
                    cy.cycle_name
             FROM {$wpdb->prefix}hl_classroom_visit cv
             LEFT JOIN {$wpdb->prefix}hl_enrollment e_leader ON cv.leader_enrollment_id = e_leader.enrollment_id
             LEFT JOIN {$wpdb->users} u_leader ON e_leader.user_id = u_leader.ID
             LEFT JOIN {$wpdb->prefix}hl_enrollment e_teacher ON cv.teacher_enrollment_id = e_teacher.enrollment_id
             LEFT JOIN {$wpdb->users} u_teacher ON e_teacher.user_id = u_teacher.ID
             LEFT JOIN {$wpdb->prefix}hl_cycle cy ON cv.cycle_id = cy.cycle_id
             WHERE cv.classroom_visit_id = %d",
            $classroom_visit_id
        ), ARRAY_A);

        return $row ?: null;
    }

    /**
     * Get all classroom visits for a cycle.
     *
     * @param int $cycle_id
     * @return array
     */
    public function get_by_cycle($cycle_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT cv.*, u_leader.display_name AS leader_name, u_teacher.display_name AS teacher_name
             FROM {$wpdb->prefix}hl_classroom_visit cv
             LEFT JOIN {$wpdb->prefix}hl_enrollment e_leader ON cv.leader_enrollment_id = e_leader.enrollment_id
             LEFT JOIN {$wpdb->users} u_leader ON e_leader.user_id = u_leader.ID
             LEFT JOIN {$wpdb->prefix}hl_enrollment e_teacher ON cv.teacher_enrollment_id = e_teacher.enrollment_id
             LEFT JOIN {$wpdb->users} u_teacher ON e_teacher.user_id = u_teacher.ID
             WHERE cv.cycle_id = %d
             ORDER BY cv.visit_date DESC, cv.created_at DESC",
            $cycle_id
        ), ARRAY_A) ?: array();
    }

    /**
     * Get visits where user is leader.
     *
     * @param int $leader_enrollment_id
     * @return array
     */
    public function get_by_leader($leader_enrollment_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT cv.*, u_teacher.display_name AS teacher_name
             FROM {$wpdb->prefix}hl_classroom_visit cv
             LEFT JOIN {$wpdb->prefix}hl_enrollment e_teacher ON cv.teacher_enrollment_id = e_teacher.enrollment_id
             LEFT JOIN {$wpdb->users} u_teacher ON e_teacher.user_id = u_teacher.ID
             WHERE cv.leader_enrollment_id = %d
             ORDER BY cv.visit_date ASC",
            $leader_enrollment_id
        ), ARRAY_A) ?: array();
    }

    /**
     * Get visits where user is teacher.
     *
     * @param int $teacher_enrollment_id
     * @return array
     */
    public function get_by_teacher($teacher_enrollment_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT cv.*, u_leader.display_name AS leader_name
             FROM {$wpdb->prefix}hl_classroom_visit cv
             LEFT JOIN {$wpdb->prefix}hl_enrollment e_leader ON cv.leader_enrollment_id = e_leader.enrollment_id
             LEFT JOIN {$wpdb->users} u_leader ON e_leader.user_id = u_leader.ID
             WHERE cv.teacher_enrollment_id = %d
             ORDER BY cv.visit_date ASC",
            $teacher_enrollment_id
        ), ARRAY_A) ?: array();
    }

    /**
     * Get enrollments the leader can visit within the same cycle.
     *
     * When $eligible_roles is provided (from the component's eligible_roles field),
     * results are filtered to those roles. When $requires_classroom is true, only
     * enrollments with a teaching assignment are included; otherwise all enrollments
     * at the school matching the roles are returned.
     *
     * @param int        $leader_enrollment_id
     * @param int        $cycle_id
     * @param array|null $eligible_roles       Roles from component eligible_roles, or null for no role filter.
     * @param bool       $requires_classroom   Whether a teaching assignment is required.
     * @return array
     */
    public function get_teachers_for_leader($leader_enrollment_id, $cycle_id, $eligible_roles = null, $requires_classroom = true) {
        global $wpdb;

        $leader = $wpdb->get_row($wpdb->prepare(
            "SELECT e.user_id, e.enrollment_id
             FROM {$wpdb->prefix}hl_enrollment e
             WHERE e.enrollment_id = %d",
            $leader_enrollment_id
        ), ARRAY_A);

        if (!$leader) {
            return array();
        }

        // Scope to the leader's own school, not all cycle schools.
        $leader_school_id = $wpdb->get_var($wpdb->prepare(
            "SELECT school_id FROM {$wpdb->prefix}hl_enrollment WHERE enrollment_id = %d",
            $leader_enrollment_id
        ));

        if ($leader_school_id) {
            $schools = array((int) $leader_school_id);
        } else {
            // Fallback for staff/admin with no school_id: all cycle schools.
            $schools = $wpdb->get_col($wpdb->prepare(
                "SELECT cs.school_id
                 FROM {$wpdb->prefix}hl_cycle_school cs
                 WHERE cs.cycle_id = %d",
                $cycle_id
            ));
        }

        if (empty($schools)) {
            return array();
        }

        $placeholders = implode(',', array_fill(0, count($schools), '%d'));
        $args = array_merge(array($cycle_id), $schools);

        if ($requires_classroom) {
            $query = "SELECT DISTINCT e.enrollment_id, e.user_id, u.display_name, u.user_email
                      FROM {$wpdb->prefix}hl_enrollment e
                      JOIN {$wpdb->users} u ON e.user_id = u.ID
                      JOIN {$wpdb->prefix}hl_teaching_assignment ta ON e.enrollment_id = ta.enrollment_id
                      JOIN {$wpdb->prefix}hl_classroom cl ON ta.classroom_id = cl.classroom_id
                      WHERE e.cycle_id = %d
                        AND cl.school_id IN ($placeholders)
                        AND e.status = 'active'";
        } else {
            $query = "SELECT DISTINCT e.enrollment_id, e.user_id, u.display_name, u.user_email
                      FROM {$wpdb->prefix}hl_enrollment e
                      JOIN {$wpdb->users} u ON e.user_id = u.ID
                      WHERE e.cycle_id = %d
                        AND e.school_id IN ($placeholders)
                        AND e.status = 'active'";
        }

        // Filter by eligible roles when specified.
        if (!empty($eligible_roles)) {
            $role_clauses = array();
            foreach ($eligible_roles as $role) {
                $role_clauses[] = 'e.roles LIKE %s';
                $args[] = '%' . $wpdb->esc_like( sanitize_key($role) ) . '%';
            }
            $query .= ' AND (' . implode(' OR ', $role_clauses) . ')';
        }

        $query .= ' ORDER BY u.display_name ASC';

        return $wpdb->get_results(
            $wpdb->prepare($query, ...$args),
            ARRAY_A
        ) ?: array();
    }

    /**
     * Mark a classroom visit as completed.
     *
     * @param int $classroom_visit_id
     * @return bool|WP_Error
     */
    public function mark_completed($classroom_visit_id) {
        global $wpdb;

        $visit = $this->get_visit($classroom_visit_id);
        if (!$visit) {
            return new WP_Error('not_found', __('Classroom visit not found.', 'hl-core'));
        }

        $result = $wpdb->update(
            $wpdb->prefix . 'hl_classroom_visit',
            array(
                'status'     => 'completed',
                'updated_at' => current_time('mysql'),
            ),
            array('classroom_visit_id' => $classroom_visit_id)
        );

        if ($result === false) {
            return new WP_Error('db_error', __('Failed to update classroom visit status.', 'hl-core'));
        }

        HL_Audit_Service::log('classroom_visit.completed', array(
            'entity_type' => 'classroom_visit',
            'entity_id'   => $classroom_visit_id,
            'cycle_id'    => $visit['cycle_id'],
            'before_data' => array('status' => $visit['status']),
            'after_data'  => array('status' => 'completed'),
        ));

        return true;
    }

    /**
     * Save or submit a classroom visit form.
     * Upserts based on unique constraint (classroom_visit_id, role_in_visit).
     *
     * @param int    $classroom_visit_id
     * @param int    $user_id
     * @param int    $instrument_id
     * @param string $role             'observer' or 'self_reflector'
     * @param string $responses_json
     * @param string $status           'draft' or 'submitted'
     * @return int submission_id
     */
    public function submit_form($classroom_visit_id, $user_id, $instrument_id, $role, $responses_json, $status = 'draft') {
        global $wpdb;
        $table = $wpdb->prefix . 'hl_classroom_visit_submission';

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT submission_id FROM {$table} WHERE classroom_visit_id = %d AND role_in_visit = %s",
            $classroom_visit_id, $role
        ), ARRAY_A);

        $data = array(
            'classroom_visit_id'   => $classroom_visit_id,
            'submitted_by_user_id' => $user_id,
            'instrument_id'        => $instrument_id,
            'role_in_visit'        => $role,
            'responses_json'       => $responses_json,
            'status'               => $status,
            'updated_at'           => current_time('mysql'),
        );

        if ($status === 'submitted') {
            $data['submitted_at'] = current_time('mysql');
        }

        if ($existing) {
            $wpdb->update($table, $data, array('submission_id' => $existing['submission_id']));
            $submission_id = (int) $existing['submission_id'];
        } else {
            $data['submission_uuid'] = wp_generate_uuid4();
            $data['created_at']      = current_time('mysql');
            $wpdb->insert($table, $data);
            $submission_id = (int) $wpdb->insert_id;
        }

        if ($status === 'submitted') {
            do_action('hl_classroom_visit_submitted', $submission_id, $classroom_visit_id, $role, $user_id);
        }

        return $submission_id;
    }

    /**
     * Get all submissions for a classroom visit.
     *
     * @param int $classroom_visit_id
     * @return array
     */
    public function get_submissions($classroom_visit_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT sub.*, u.display_name AS submitted_by_name
             FROM {$wpdb->prefix}hl_classroom_visit_submission sub
             LEFT JOIN {$wpdb->users} u ON sub.submitted_by_user_id = u.ID
             WHERE sub.classroom_visit_id = %d
             ORDER BY sub.role_in_visit ASC",
            $classroom_visit_id
        ), ARRAY_A) ?: array();
    }

    /**
     * Get the most recent classroom visit for a teacher in a cycle.
     *
     * @param int $teacher_enrollment_id
     * @param int $cycle_id
     * @return array|null
     */
    public function get_most_recent_for_teacher($teacher_enrollment_id, $cycle_id) {
        global $wpdb;

        $visit = $wpdb->get_row($wpdb->prepare(
            "SELECT cv.*, u_leader.display_name AS leader_name
             FROM {$wpdb->prefix}hl_classroom_visit cv
             LEFT JOIN {$wpdb->prefix}hl_enrollment e_leader ON cv.leader_enrollment_id = e_leader.enrollment_id
             LEFT JOIN {$wpdb->users} u_leader ON e_leader.user_id = u_leader.ID
             WHERE cv.teacher_enrollment_id = %d AND cv.cycle_id = %d
             ORDER BY cv.visit_date DESC, cv.created_at DESC
             LIMIT 1",
            $teacher_enrollment_id, $cycle_id
        ), ARRAY_A);

        return $visit ?: null;
    }

    /**
     * Update component state when a classroom visit form is submitted.
     *
     * Finds the component with type 'classroom_visit' and matching
     * visit_number in external_ref, then marks it complete.
     *
     * @param int $enrollment_id
     * @param int $cycle_id
     * @param int $visit_number
     */
    public function update_component_state($enrollment_id, $cycle_id, $visit_number) {
        global $wpdb;

        $component = $wpdb->get_row($wpdb->prepare(
            "SELECT c.component_id
             FROM {$wpdb->prefix}hl_component c
             JOIN {$wpdb->prefix}hl_pathway p ON c.pathway_id = p.pathway_id
             JOIN {$wpdb->prefix}hl_pathway_assignment pa ON p.pathway_id = pa.pathway_id
             WHERE p.cycle_id = %d
               AND pa.enrollment_id = %d
               AND c.component_type = 'classroom_visit'
               AND c.status = 'active'
               AND c.external_ref LIKE %s",
            $cycle_id,
            $enrollment_id,
            '%"visit_number":' . intval($visit_number) . '%'
        ));

        if (!$component) {
            return;
        }

        // Count total teachers the leader needs to visit
        $total_teachers = count($this->get_teachers_for_leader($enrollment_id, $cycle_id));
        if ($total_teachers === 0) {
            $total_teachers = 1; // Avoid division by zero
        }

        // Count submitted visits for this leader/visit_number/cycle
        $submitted_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT cv.teacher_enrollment_id)
             FROM {$wpdb->prefix}hl_classroom_visit cv
             JOIN {$wpdb->prefix}hl_classroom_visit_submission sub
               ON cv.classroom_visit_id = sub.classroom_visit_id
             WHERE cv.leader_enrollment_id = %d
               AND cv.cycle_id = %d
               AND cv.visit_number = %d
               AND sub.role_in_visit = 'observer'
               AND sub.status = 'submitted'",
            $enrollment_id, $cycle_id, $visit_number
        ));

        $percent     = min(100, (int) floor(($submitted_count / $total_teachers) * 100));
        $is_complete = ($percent >= 100);
        $status      = $is_complete ? 'complete' : ($percent > 0 ? 'in_progress' : 'not_started');
        $now         = current_time('mysql');

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT state_id FROM {$wpdb->prefix}hl_component_state
             WHERE enrollment_id = %d AND component_id = %d",
            $enrollment_id, $component->component_id
        ));

        $state_data = array(
            'completion_percent' => $percent,
            'completion_status'  => $status,
            'completed_at'       => $is_complete ? $now : null,
            'last_computed_at'   => $now,
        );

        if ($existing) {
            $wpdb->update(
                $wpdb->prefix . 'hl_component_state',
                $state_data,
                array('state_id' => $existing)
            );
        } else {
            $state_data['enrollment_id'] = $enrollment_id;
            $state_data['component_id']  = $component->component_id;
            $wpdb->insert($wpdb->prefix . 'hl_component_state', $state_data);
        }

        do_action('hl_core_recompute_rollups', $enrollment_id);
    }
}
