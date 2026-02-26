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
            "SELECT ta.*, e.user_id, e.roles, e.track_id, u.display_name, u.user_email, t.track_name
             FROM {$wpdb->prefix}hl_teaching_assignment ta
             LEFT JOIN {$wpdb->prefix}hl_enrollment e ON ta.enrollment_id = e.enrollment_id
             LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
             LEFT JOIN {$wpdb->prefix}hl_track t ON e.track_id = t.track_id
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
        $track_id = $wpdb->get_var($wpdb->prepare(
            "SELECT track_id FROM {$wpdb->prefix}hl_enrollment WHERE enrollment_id = %d",
            $data['enrollment_id']
        ));
        if ($track_id) {
            do_action('hl_core_teaching_assignment_changed', (int) $track_id);
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

        // Get before data for audit and track_id for hook
        $before = $wpdb->get_row($wpdb->prepare(
            "SELECT ta.*, e.track_id FROM {$wpdb->prefix}hl_teaching_assignment ta
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
            if (!empty($before['track_id'])) {
                do_action('hl_core_teaching_assignment_changed', (int) $before['track_id']);
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
    public function get_children_in_classroom( $classroom_id, $include_removed = false ) {
        global $wpdb;

        $status_clause = $include_removed ? '' : "AND cc.status = 'active'";

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT ch.*, cc.assigned_at, cc.status AS roster_status,
                    cc.added_by_enrollment_id, cc.added_at,
                    cc.removed_by_enrollment_id, cc.removed_at,
                    cc.removal_reason, cc.removal_note
             FROM {$wpdb->prefix}hl_child_classroom_current cc
             JOIN {$wpdb->prefix}hl_child ch ON cc.child_id = ch.child_id
             WHERE cc.classroom_id = %d {$status_clause}
             ORDER BY ch.last_name ASC, ch.first_name ASC",
            $classroom_id
        ) );
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

    // =========================================================================
    // Teacher Roster Management
    // =========================================================================

    /**
     * Teacher soft-removes a child from classroom.
     *
     * @param int    $classroom_id
     * @param int    $child_id
     * @param int    $enrollment_id  Teacher's enrollment_id.
     * @param string $reason         left_school|moved_classroom|other
     * @param string $note           Optional note.
     * @return true|WP_Error
     */
    public function teacher_remove_child( $classroom_id, $child_id, $enrollment_id, $reason, $note = '' ) {
        global $wpdb;

        // Verify the enrollment has a teaching assignment for this classroom.
        $has_assignment = $wpdb->get_var( $wpdb->prepare(
            "SELECT assignment_id FROM {$wpdb->prefix}hl_teaching_assignment
             WHERE enrollment_id = %d AND classroom_id = %d",
            $enrollment_id,
            $classroom_id
        ) );

        if ( ! $has_assignment ) {
            return new WP_Error( 'no_assignment', __( 'You are not assigned to teach this classroom.', 'hl-core' ) );
        }

        $valid_reasons = array( 'left_school', 'moved_classroom', 'other' );
        if ( ! in_array( $reason, $valid_reasons, true ) ) {
            return new WP_Error( 'invalid_reason', __( 'Invalid removal reason.', 'hl-core' ) );
        }

        $now = current_time( 'mysql' );
        $today = current_time( 'Y-m-d' );

        // Update current assignment to removed.
        $updated = $wpdb->update(
            $wpdb->prefix . 'hl_child_classroom_current',
            array(
                'status'                   => 'teacher_removed',
                'removed_by_enrollment_id' => absint( $enrollment_id ),
                'removed_at'               => $now,
                'removal_reason'           => $reason,
                'removal_note'             => sanitize_textarea_field( $note ),
            ),
            array(
                'child_id'     => absint( $child_id ),
                'classroom_id' => absint( $classroom_id ),
                'status'       => 'active',
            )
        );

        if ( $updated === false || $updated === 0 ) {
            return new WP_Error( 'not_found', __( 'Child is not active in this classroom.', 'hl-core' ) );
        }

        // Close history row.
        $wpdb->update(
            $wpdb->prefix . 'hl_child_classroom_history',
            array( 'end_date' => $today, 'reason' => 'Teacher removed: ' . $reason ),
            array( 'child_id' => $child_id, 'classroom_id' => $classroom_id, 'end_date' => null )
        );

        HL_Audit_Service::log( 'child_classroom.teacher_removed', array(
            'entity_type' => 'child_classroom',
            'entity_id'   => $child_id,
            'after_data'  => array(
                'classroom_id' => $classroom_id,
                'removed_by'   => $enrollment_id,
                'reason'       => $reason,
                'note'         => $note,
            ),
        ) );

        return true;
    }

    /**
     * Teacher adds a child to their classroom.
     *
     * @param int   $classroom_id
     * @param int   $enrollment_id Teacher's enrollment_id.
     * @param array $data          Keys: first_name, last_name, dob, gender.
     * @return int|WP_Error child_id on success.
     */
    public function teacher_add_child( $classroom_id, $enrollment_id, $data ) {
        global $wpdb;

        // Verify the enrollment has a teaching assignment for this classroom.
        $has_assignment = $wpdb->get_var( $wpdb->prepare(
            "SELECT assignment_id FROM {$wpdb->prefix}hl_teaching_assignment
             WHERE enrollment_id = %d AND classroom_id = %d",
            $enrollment_id,
            $classroom_id
        ) );

        if ( ! $has_assignment ) {
            return new WP_Error( 'no_assignment', __( 'You are not assigned to teach this classroom.', 'hl-core' ) );
        }

        $first_name = sanitize_text_field( $data['first_name'] ?? '' );
        $last_name  = sanitize_text_field( $data['last_name'] ?? '' );
        $dob        = sanitize_text_field( $data['dob'] ?? '' );
        $gender     = sanitize_text_field( $data['gender'] ?? '' );

        if ( empty( $first_name ) || empty( $last_name ) || empty( $dob ) ) {
            return new WP_Error( 'missing_fields', __( 'First name, last name, and date of birth are required.', 'hl-core' ) );
        }

        // Get school_id from classroom.
        $school_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT school_id FROM {$wpdb->prefix}hl_classroom WHERE classroom_id = %d",
            $classroom_id
        ) );

        if ( ! $school_id ) {
            return new WP_Error( 'invalid_classroom', __( 'Classroom not found.', 'hl-core' ) );
        }

        // Duplicate detection: match on (first_name, last_name, dob, school_id).
        $existing_child = $wpdb->get_row( $wpdb->prepare(
            "SELECT ch.child_id
             FROM {$wpdb->prefix}hl_child ch
             WHERE ch.first_name = %s AND ch.last_name = %s AND ch.dob = %s AND ch.school_id = %d",
            $first_name,
            $last_name,
            $dob,
            $school_id
        ) );

        $child_id = null;
        $now      = current_time( 'mysql' );

        if ( $existing_child ) {
            $child_id = (int) $existing_child->child_id;

            // Check if child is already active in this classroom.
            $active_here = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}hl_child_classroom_current
                 WHERE child_id = %d AND classroom_id = %d AND status = 'active'",
                $child_id,
                $classroom_id
            ) );

            if ( $active_here ) {
                return new WP_Error( 'already_exists', __( 'This child is already in your classroom.', 'hl-core' ) );
            }

            // If child was previously removed from this classroom, reactivate.
            $removed_here = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}hl_child_classroom_current
                 WHERE child_id = %d AND classroom_id = %d AND status = 'teacher_removed'",
                $child_id,
                $classroom_id
            ) );

            if ( $removed_here ) {
                $wpdb->update(
                    $wpdb->prefix . 'hl_child_classroom_current',
                    array(
                        'status'                   => 'active',
                        'removed_by_enrollment_id' => null,
                        'removed_at'               => null,
                        'removal_reason'           => null,
                        'removal_note'             => null,
                        'added_by_enrollment_id'   => absint( $enrollment_id ),
                        'added_at'                 => $now,
                        'assigned_at'              => $now,
                    ),
                    array( 'child_id' => $child_id, 'classroom_id' => $classroom_id )
                );
            } else {
                // Child exists elsewhere — delete old current and create new.
                $wpdb->delete(
                    $wpdb->prefix . 'hl_child_classroom_current',
                    array( 'child_id' => $child_id )
                );

                $wpdb->insert( $wpdb->prefix . 'hl_child_classroom_current', array(
                    'child_id'                 => $child_id,
                    'classroom_id'             => absint( $classroom_id ),
                    'assigned_at'              => $now,
                    'status'                   => 'active',
                    'added_by_enrollment_id'   => absint( $enrollment_id ),
                    'added_at'                 => $now,
                ) );
            }
        } else {
            // Create new child record.
            $fingerprint = HL_Normalization::child_fingerprint( $first_name, $last_name, $dob );
            $metadata    = ! empty( $gender ) ? wp_json_encode( array( 'gender' => $gender ) ) : null;

            $wpdb->insert( $wpdb->prefix . 'hl_child', array(
                'child_uuid'        => HL_DB_Utils::generate_uuid(),
                'school_id'         => absint( $school_id ),
                'first_name'        => $first_name,
                'last_name'         => $last_name,
                'dob'               => $dob,
                'child_fingerprint' => $fingerprint,
                'metadata'          => $metadata,
            ) );
            $child_id = $wpdb->insert_id;

            if ( ! $child_id ) {
                return new WP_Error( 'insert_failed', __( 'Failed to create child record.', 'hl-core' ) );
            }

            // Create classroom assignment.
            $wpdb->insert( $wpdb->prefix . 'hl_child_classroom_current', array(
                'child_id'                 => $child_id,
                'classroom_id'             => absint( $classroom_id ),
                'assigned_at'              => $now,
                'status'                   => 'active',
                'added_by_enrollment_id'   => absint( $enrollment_id ),
                'added_at'                 => $now,
            ) );
        }

        // Insert history row.
        $wpdb->insert( $wpdb->prefix . 'hl_child_classroom_history', array(
            'child_id'     => $child_id,
            'classroom_id' => absint( $classroom_id ),
            'start_date'   => current_time( 'Y-m-d' ),
            'reason'       => 'Teacher added',
        ) );

        // Auto-create snapshot for all tracks this classroom is linked to.
        $track_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT e.track_id
             FROM {$wpdb->prefix}hl_teaching_assignment ta
             JOIN {$wpdb->prefix}hl_enrollment e ON ta.enrollment_id = e.enrollment_id
             WHERE ta.classroom_id = %d AND e.status = 'active'",
            $classroom_id
        ) );

        foreach ( $track_ids as $track_id ) {
            HL_Child_Snapshot_Service::ensure_snapshot( $child_id, (int) $track_id, $dob );
        }

        HL_Audit_Service::log( 'child_classroom.teacher_added', array(
            'entity_type' => 'child',
            'entity_id'   => $child_id,
            'after_data'  => array(
                'classroom_id' => $classroom_id,
                'added_by'     => $enrollment_id,
                'first_name'   => $first_name,
                'last_name'    => $last_name,
                'dob'          => $dob,
            ),
        ) );

        return $child_id;
    }

    /**
     * Get removed children in a classroom.
     *
     * @param int $classroom_id
     * @return array
     */
    public function get_removed_children_in_classroom( $classroom_id ) {
        global $wpdb;

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT ch.*, cc.removed_at, cc.removal_reason, cc.removal_note,
                    cc.removed_by_enrollment_id,
                    u.display_name AS removed_by_name
             FROM {$wpdb->prefix}hl_child_classroom_current cc
             JOIN {$wpdb->prefix}hl_child ch ON cc.child_id = ch.child_id
             LEFT JOIN {$wpdb->prefix}hl_enrollment e ON cc.removed_by_enrollment_id = e.enrollment_id
             LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
             WHERE cc.classroom_id = %d AND cc.status = 'teacher_removed'
             ORDER BY cc.removed_at DESC",
            $classroom_id
        ) );
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
