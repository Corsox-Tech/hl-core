<?php
if (!defined('ABSPATH')) exit;

class HL_Classroom_Service {

    private $repository;

    public function __construct() {
        $this->repository = new HL_Classroom_Repository();
    }

    public function get_classrooms($school_id = null) {
        return $this->repository->get_all($school_id);
    }

    public function get_classroom($classroom_id) {
        return $this->repository->get_by_id($classroom_id);
    }

    public function create_classroom($data) {
        if (empty($data['classroom_name']) || empty($data['school_id'])) {
            return new WP_Error('missing_fields', __('Classroom name and school are required.', 'hl-core'));
        }
        return $this->repository->create($data);
    }

    public function update_classroom($classroom_id, $data) {
        return $this->repository->update($classroom_id, $data);
    }

    // =========================================================================
    // Teaching Assignment CRUD
    // =========================================================================

    /**
     * Get all teaching assignments for a classroom
     *
     * @param int $classroom_id
     * @return array
     */
    public function get_teaching_assignments($classroom_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT ta.*, e.user_id, e.roles, e.cohort_id, u.display_name, u.user_email, c.cohort_name
             FROM {$wpdb->prefix}hl_teaching_assignment ta
             LEFT JOIN {$wpdb->prefix}hl_enrollment e ON ta.enrollment_id = e.enrollment_id
             LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
             LEFT JOIN {$wpdb->prefix}hl_cohort c ON e.cohort_id = c.cohort_id
             WHERE ta.classroom_id = %d
             ORDER BY u.display_name ASC",
            $classroom_id
        ));
    }

    /**
     * Get all classrooms assigned to a teacher enrollment
     *
     * @param int $enrollment_id
     * @return array
     */
    public function get_classrooms_for_teacher($enrollment_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT ta.*, cr.classroom_name, cr.school_id
             FROM {$wpdb->prefix}hl_teaching_assignment ta
             LEFT JOIN {$wpdb->prefix}hl_classroom cr ON ta.classroom_id = cr.classroom_id
             WHERE ta.enrollment_id = %d
             ORDER BY cr.classroom_name ASC",
            $enrollment_id
        ));
    }

    /**
     * Create a teaching assignment
     *
     * @param array $data Keys: enrollment_id, classroom_id, is_lead_teacher, effective_start_date, effective_end_date
     * @return int|WP_Error assignment_id on success
     */
    public function create_teaching_assignment($data) {
        global $wpdb;

        if (empty($data['enrollment_id']) || empty($data['classroom_id'])) {
            return new WP_Error('missing_fields', __('Enrollment and classroom are required.', 'hl-core'));
        }

        $insert_data = array(
            'enrollment_id'        => absint($data['enrollment_id']),
            'classroom_id'         => absint($data['classroom_id']),
            'is_lead_teacher'      => !empty($data['is_lead_teacher']) ? 1 : 0,
            'effective_start_date' => !empty($data['effective_start_date']) ? sanitize_text_field($data['effective_start_date']) : null,
            'effective_end_date'   => !empty($data['effective_end_date']) ? sanitize_text_field($data['effective_end_date']) : null,
        );

        $result = $wpdb->insert($wpdb->prefix . 'hl_teaching_assignment', $insert_data);

        if ($result === false) {
            return new WP_Error('duplicate', __('This teacher is already assigned to this classroom.', 'hl-core'));
        }

        $assignment_id = $wpdb->insert_id;

        HL_Audit_Service::log('teaching_assignment.created', array(
            'entity_type' => 'teaching_assignment',
            'entity_id'   => $assignment_id,
            'after_data'  => $insert_data,
        ));

        // Trigger child assessment instance auto-generation
        $cohort_id = $wpdb->get_var($wpdb->prepare(
            "SELECT cohort_id FROM {$wpdb->prefix}hl_enrollment WHERE enrollment_id = %d",
            $data['enrollment_id']
        ));
        if ($cohort_id) {
            do_action('hl_core_teaching_assignment_changed', (int) $cohort_id);
        }

        return $assignment_id;
    }

    /**
     * Update a teaching assignment
     *
     * @param int   $assignment_id
     * @param array $data
     * @return bool
     */
    public function update_teaching_assignment($assignment_id, $data) {
        global $wpdb;

        $update = array();
        if (isset($data['is_lead_teacher'])) {
            $update['is_lead_teacher'] = !empty($data['is_lead_teacher']) ? 1 : 0;
        }
        if (array_key_exists('effective_start_date', $data)) {
            $update['effective_start_date'] = !empty($data['effective_start_date']) ? sanitize_text_field($data['effective_start_date']) : null;
        }
        if (array_key_exists('effective_end_date', $data)) {
            $update['effective_end_date'] = !empty($data['effective_end_date']) ? sanitize_text_field($data['effective_end_date']) : null;
        }

        if (empty($update)) {
            return false;
        }

        return $wpdb->update(
            $wpdb->prefix . 'hl_teaching_assignment',
            $update,
            array('assignment_id' => $assignment_id)
        ) !== false;
    }

    /**
     * Delete a teaching assignment
     *
     * @param int $assignment_id
     * @return bool
     */
    public function delete_teaching_assignment($assignment_id) {
        global $wpdb;

        // Get before data for audit and cohort_id for hook
        $before = $wpdb->get_row($wpdb->prepare(
            "SELECT ta.*, e.cohort_id FROM {$wpdb->prefix}hl_teaching_assignment ta
             JOIN {$wpdb->prefix}hl_enrollment e ON ta.enrollment_id = e.enrollment_id
             WHERE ta.assignment_id = %d",
            $assignment_id
        ), ARRAY_A);

        $result = $wpdb->delete(
            $wpdb->prefix . 'hl_teaching_assignment',
            array('assignment_id' => $assignment_id)
        );

        if ($result && $before) {
            HL_Audit_Service::log('teaching_assignment.deleted', array(
                'entity_type' => 'teaching_assignment',
                'entity_id'   => $assignment_id,
                'before_data' => $before,
            ));

            // Trigger child assessment instance auto-generation
            if (!empty($before['cohort_id'])) {
                do_action('hl_core_teaching_assignment_changed', (int) $before['cohort_id']);
            }
        }

        return (bool) $result;
    }

    // =========================================================================
    // Child Classroom Assignment CRUD
    // =========================================================================

    /**
     * Get all children currently assigned to a classroom
     *
     * @param int $classroom_id
     * @return array
     */
    public function get_children_in_classroom($classroom_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT ch.*, cc.assigned_at
             FROM {$wpdb->prefix}hl_child_classroom_current cc
             JOIN {$wpdb->prefix}hl_child ch ON cc.child_id = ch.child_id
             WHERE cc.classroom_id = %d
             ORDER BY ch.last_name ASC, ch.first_name ASC",
            $classroom_id
        ));
    }

    /**
     * Get the current classroom assignment for a child
     *
     * @param int $child_id
     * @return object|null
     */
    public function get_child_current_classroom($child_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT cc.*, cr.classroom_name, cr.school_id
             FROM {$wpdb->prefix}hl_child_classroom_current cc
             JOIN {$wpdb->prefix}hl_classroom cr ON cc.classroom_id = cr.classroom_id
             WHERE cc.child_id = %d",
            $child_id
        ));
    }

    /**
     * Assign (or reassign) a child to a classroom
     *
     * @param int    $child_id
     * @param int    $classroom_id
     * @param string $reason
     * @return true|WP_Error
     */
    public function assign_child_to_classroom($child_id, $classroom_id, $reason = '') {
        global $wpdb;

        if (empty($child_id) || empty($classroom_id)) {
            return new WP_Error('missing_fields', __('Child and classroom are required.', 'hl-core'));
        }

        $now   = current_time('mysql');
        $today = current_time('Y-m-d');

        // Check for existing current assignment
        $current = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_child_classroom_current WHERE child_id = %d",
            $child_id
        ));

        // Same classroom — no-op
        if ($current && (int) $current->classroom_id === (int) $classroom_id) {
            return true;
        }

        // If reassigning, close old history and remove current
        $previous_classroom_id = null;
        if ($current) {
            $previous_classroom_id = $current->classroom_id;

            // Close open history row
            $wpdb->update(
                $wpdb->prefix . 'hl_child_classroom_history',
                array('end_date' => $today),
                array('child_id' => $child_id, 'classroom_id' => $current->classroom_id, 'end_date' => null)
            );

            // Delete current
            $wpdb->delete(
                $wpdb->prefix . 'hl_child_classroom_current',
                array('child_id' => $child_id)
            );
        }

        // Insert new current
        $wpdb->insert($wpdb->prefix . 'hl_child_classroom_current', array(
            'child_id'     => $child_id,
            'classroom_id' => $classroom_id,
            'assigned_at'  => $now,
        ));

        // Insert new history row (open-ended)
        $wpdb->insert($wpdb->prefix . 'hl_child_classroom_history', array(
            'child_id'     => $child_id,
            'classroom_id' => $classroom_id,
            'start_date'   => $today,
            'end_date'     => null,
            'reason'       => $reason ?: ($previous_classroom_id ? 'Reassigned' : 'Initial assignment'),
        ));

        HL_Audit_Service::log('child_classroom.assigned', array(
            'entity_type' => 'child_classroom',
            'entity_id'   => $child_id,
            'after_data'  => array(
                'child_id'            => $child_id,
                'classroom_id'        => $classroom_id,
                'previous_classroom'  => $previous_classroom_id,
                'reason'              => $reason,
            ),
        ));

        return true;
    }

    /**
     * Remove a child from their current classroom
     *
     * @param int    $child_id
     * @param string $reason
     * @return true|WP_Error
     */
    public function unassign_child_from_classroom($child_id, $reason = '') {
        global $wpdb;

        $current = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_child_classroom_current WHERE child_id = %d",
            $child_id
        ));

        if (!$current) {
            return new WP_Error('not_assigned', __('Child is not currently assigned to a classroom.', 'hl-core'));
        }

        $today = current_time('Y-m-d');

        // Close history row
        $wpdb->update(
            $wpdb->prefix . 'hl_child_classroom_history',
            array('end_date' => $today),
            array('child_id' => $child_id, 'classroom_id' => $current->classroom_id, 'end_date' => null)
        );

        // Delete current
        $wpdb->delete(
            $wpdb->prefix . 'hl_child_classroom_current',
            array('child_id' => $child_id)
        );

        HL_Audit_Service::log('child_classroom.unassigned', array(
            'entity_type' => 'child_classroom',
            'entity_id'   => $child_id,
            'before_data' => array(
                'child_id'     => $child_id,
                'classroom_id' => $current->classroom_id,
            ),
            'reason' => $reason ?: 'Removed via admin',
        ));

        return true;
    }

    /**
     * Get classroom history for a child
     *
     * @param int $child_id
     * @return array
     */
    public function get_child_classroom_history($child_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT h.*, cr.classroom_name
             FROM {$wpdb->prefix}hl_child_classroom_history h
             LEFT JOIN {$wpdb->prefix}hl_classroom cr ON h.classroom_id = cr.classroom_id
             WHERE h.child_id = %d
             ORDER BY h.start_date DESC",
            $child_id
        ));
    }
}
