<?php
if (!defined('ABSPATH')) exit;

/**
 * Reflective Practice Session Service
 *
 * Manages RP sessions linking mentors and teachers, plus form submissions.
 *
 * @package HL_Core
 */
class HL_RP_Session_Service {

    const VALID_STATUSES    = array('pending', 'scheduled', 'attended', 'missed', 'cancelled');
    const TERMINAL_STATUSES = array('attended', 'missed', 'cancelled');

    /**
     * Create an RP session.
     *
     * @param array $data Keys: cycle_id, mentor_enrollment_id, teacher_enrollment_id, session_number, session_date, notes.
     * @return int|WP_Error rp_session_id on success.
     */
    public function create_session($data) {
        global $wpdb;

        if (empty($data['cycle_id'])) {
            return new WP_Error('missing_cycle', __('Cycle is required.', 'hl-core'));
        }
        if (empty($data['mentor_enrollment_id'])) {
            return new WP_Error('missing_mentor', __('Mentor enrollment is required.', 'hl-core'));
        }
        if (empty($data['teacher_enrollment_id'])) {
            return new WP_Error('missing_teacher', __('Teacher enrollment is required.', 'hl-core'));
        }

        $insert_data = array(
            'rp_session_uuid'      => wp_generate_uuid4(),
            'cycle_id'             => absint($data['cycle_id']),
            'mentor_enrollment_id' => absint($data['mentor_enrollment_id']),
            'teacher_enrollment_id' => absint($data['teacher_enrollment_id']),
            'session_number'       => !empty($data['session_number']) ? absint($data['session_number']) : 1,
            'status'               => 'pending',
            'session_date'         => !empty($data['session_date']) ? sanitize_text_field($data['session_date']) : null,
            'notes'                => !empty($data['notes']) ? wp_kses_post($data['notes']) : null,
            'created_at'           => current_time('mysql'),
            'updated_at'           => current_time('mysql'),
        );

        $result = $wpdb->insert($wpdb->prefix . 'hl_rp_session', $insert_data);

        if ($result === false) {
            return new WP_Error('db_error', __('Failed to create RP session.', 'hl-core'));
        }

        $rp_session_id = $wpdb->insert_id;

        HL_Audit_Service::log('rp_session.created', array(
            'entity_type' => 'rp_session',
            'entity_id'   => $rp_session_id,
            'cycle_id'    => $insert_data['cycle_id'],
            'after_data'  => array(
                'cycle_id'              => $insert_data['cycle_id'],
                'mentor_enrollment_id'  => $insert_data['mentor_enrollment_id'],
                'teacher_enrollment_id' => $insert_data['teacher_enrollment_id'],
                'session_number'        => $insert_data['session_number'],
            ),
        ));

        return $rp_session_id;
    }

    /**
     * Get a single RP session by ID with joined names.
     *
     * @param int $rp_session_id
     * @return array|null
     */
    public function get_session($rp_session_id) {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT rps.*,
                    u_mentor.display_name AS mentor_name,
                    u_teacher.display_name AS teacher_name,
                    cy.cycle_name
             FROM {$wpdb->prefix}hl_rp_session rps
             LEFT JOIN {$wpdb->prefix}hl_enrollment e_mentor ON rps.mentor_enrollment_id = e_mentor.enrollment_id
             LEFT JOIN {$wpdb->users} u_mentor ON e_mentor.user_id = u_mentor.ID
             LEFT JOIN {$wpdb->prefix}hl_enrollment e_teacher ON rps.teacher_enrollment_id = e_teacher.enrollment_id
             LEFT JOIN {$wpdb->users} u_teacher ON e_teacher.user_id = u_teacher.ID
             LEFT JOIN {$wpdb->prefix}hl_cycle cy ON rps.cycle_id = cy.cycle_id
             WHERE rps.rp_session_id = %d",
            $rp_session_id
        ), ARRAY_A);

        return $row ?: null;
    }

    /**
     * Get all RP sessions for a cycle.
     *
     * @param int $cycle_id
     * @return array
     */
    public function get_by_cycle($cycle_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT rps.*, u_mentor.display_name AS mentor_name, u_teacher.display_name AS teacher_name
             FROM {$wpdb->prefix}hl_rp_session rps
             LEFT JOIN {$wpdb->prefix}hl_enrollment e_mentor ON rps.mentor_enrollment_id = e_mentor.enrollment_id
             LEFT JOIN {$wpdb->users} u_mentor ON e_mentor.user_id = u_mentor.ID
             LEFT JOIN {$wpdb->prefix}hl_enrollment e_teacher ON rps.teacher_enrollment_id = e_teacher.enrollment_id
             LEFT JOIN {$wpdb->users} u_teacher ON e_teacher.user_id = u_teacher.ID
             WHERE rps.cycle_id = %d
             ORDER BY rps.session_date DESC, rps.created_at DESC",
            $cycle_id
        ), ARRAY_A) ?: array();
    }

    /**
     * Get sessions where user is mentor.
     *
     * @param int $mentor_enrollment_id
     * @return array
     */
    public function get_by_mentor($mentor_enrollment_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT rps.*, u_teacher.display_name AS teacher_name
             FROM {$wpdb->prefix}hl_rp_session rps
             LEFT JOIN {$wpdb->prefix}hl_enrollment e_teacher ON rps.teacher_enrollment_id = e_teacher.enrollment_id
             LEFT JOIN {$wpdb->users} u_teacher ON e_teacher.user_id = u_teacher.ID
             WHERE rps.mentor_enrollment_id = %d
             ORDER BY rps.session_date ASC",
            $mentor_enrollment_id
        ), ARRAY_A) ?: array();
    }

    /**
     * Get sessions where user is teacher.
     *
     * @param int $teacher_enrollment_id
     * @return array
     */
    public function get_by_teacher($teacher_enrollment_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT rps.*, u_mentor.display_name AS mentor_name
             FROM {$wpdb->prefix}hl_rp_session rps
             LEFT JOIN {$wpdb->prefix}hl_enrollment e_mentor ON rps.mentor_enrollment_id = e_mentor.enrollment_id
             LEFT JOIN {$wpdb->users} u_mentor ON e_mentor.user_id = u_mentor.ID
             WHERE rps.teacher_enrollment_id = %d
             ORDER BY rps.session_date ASC",
            $teacher_enrollment_id
        ), ARRAY_A) ?: array();
    }

    /**
     * Get teachers in the mentor's team(s).
     *
     * Join path: enrollment → hl_team_membership (find teams where enrollment is mentor)
     * → return non-mentor team members.
     *
     * @param int $mentor_enrollment_id
     * @return array
     */
    public function get_teachers_for_mentor($mentor_enrollment_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT e.enrollment_id, e.user_id, u.display_name, u.user_email
             FROM {$wpdb->prefix}hl_team_membership tm_mentor
             JOIN {$wpdb->prefix}hl_team_membership tm_teacher
                 ON tm_mentor.team_id = tm_teacher.team_id
                 AND tm_teacher.enrollment_id != %d
             JOIN {$wpdb->prefix}hl_enrollment e ON tm_teacher.enrollment_id = e.enrollment_id
             JOIN {$wpdb->users} u ON e.user_id = u.ID
             WHERE tm_mentor.enrollment_id = %d
               AND e.status = 'active'
             ORDER BY u.display_name ASC",
            $mentor_enrollment_id, $mentor_enrollment_id
        ), ARRAY_A) ?: array();
    }

    /**
     * Transition session status with validation.
     *
     * @param int    $rp_session_id
     * @param string $new_status
     * @return bool|WP_Error
     */
    public function transition_status($rp_session_id, $new_status) {
        global $wpdb;

        if (!in_array($new_status, self::VALID_STATUSES, true)) {
            return new WP_Error('invalid_status', __('Invalid session status.', 'hl-core'));
        }

        $session = $this->get_session($rp_session_id);
        if (!$session) {
            return new WP_Error('not_found', __('RP session not found.', 'hl-core'));
        }

        $current = $session['status'];

        if (in_array($current, self::TERMINAL_STATUSES, true)) {
            return new WP_Error('terminal_status', sprintf(
                __('Cannot change status from "%s" — it is a terminal state.', 'hl-core'),
                $current
            ));
        }

        $result = $wpdb->update(
            $wpdb->prefix . 'hl_rp_session',
            array(
                'status'     => $new_status,
                'updated_at' => current_time('mysql'),
            ),
            array('rp_session_id' => $rp_session_id)
        );

        if ($result === false) {
            return new WP_Error('db_error', __('Failed to update RP session status.', 'hl-core'));
        }

        HL_Audit_Service::log('rp_session.status_changed', array(
            'entity_type' => 'rp_session',
            'entity_id'   => $rp_session_id,
            'cycle_id'    => $session['cycle_id'],
            'before_data' => array('status' => $current),
            'after_data'  => array('status' => $new_status),
        ));

        return true;
    }

    /**
     * Save or submit an RP session form.
     * Upserts based on unique constraint (rp_session_id, role_in_session).
     *
     * @param int    $rp_session_id
     * @param int    $user_id
     * @param int    $instrument_id
     * @param string $role          'supervisor' or 'supervisee'
     * @param string $responses_json
     * @param string $status        'draft' or 'submitted'
     * @return int submission_id
     */
    public function submit_form($rp_session_id, $user_id, $instrument_id, $role, $responses_json, $status = 'draft') {
        global $wpdb;
        $table = $wpdb->prefix . 'hl_rp_session_submission';

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT submission_id FROM {$table} WHERE rp_session_id = %d AND role_in_session = %s",
            $rp_session_id, $role
        ), ARRAY_A);

        $data = array(
            'rp_session_id'        => $rp_session_id,
            'submitted_by_user_id' => $user_id,
            'instrument_id'        => $instrument_id,
            'role_in_session'      => $role,
            'responses_json'       => $responses_json,
            'status'               => $status,
            'updated_at'           => current_time('mysql'),
        );

        if ($status === 'submitted') {
            $data['submitted_at'] = current_time('mysql');
        }

        if ($existing) {
            $wpdb->update($table, $data, array('submission_id' => $existing['submission_id']));
            return (int) $existing['submission_id'];
        }

        $data['submission_uuid'] = wp_generate_uuid4();
        $data['created_at']      = current_time('mysql');
        $wpdb->insert($table, $data);
        return (int) $wpdb->insert_id;
    }

    /**
     * Get all submissions for an RP session.
     *
     * @param int $rp_session_id
     * @return array
     */
    public function get_submissions($rp_session_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT sub.*, u.display_name AS submitted_by_name
             FROM {$wpdb->prefix}hl_rp_session_submission sub
             LEFT JOIN {$wpdb->users} u ON sub.submitted_by_user_id = u.ID
             WHERE sub.rp_session_id = %d
             ORDER BY sub.role_in_session ASC",
            $rp_session_id
        ), ARRAY_A) ?: array();
    }

    /**
     * Get previous action plan submissions for a teacher in a cycle.
     *
     * @param int $teacher_enrollment_id
     * @param int $cycle_id
     * @return array
     */
    public function get_previous_action_plans($teacher_enrollment_id, $cycle_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT sub.responses_json, sub.submitted_at, rps.session_date, rps.session_number
             FROM {$wpdb->prefix}hl_rp_session_submission sub
             JOIN {$wpdb->prefix}hl_rp_session rps ON sub.rp_session_id = rps.rp_session_id
             WHERE rps.teacher_enrollment_id = %d AND rps.cycle_id = %d
               AND sub.role_in_session = 'supervisee' AND sub.status = 'submitted'
             ORDER BY sub.submitted_at DESC",
            $teacher_enrollment_id, $cycle_id
        ), ARRAY_A) ?: array();
    }

    /**
     * Update component state when an RP session form is submitted.
     *
     * Finds the component with type 'reflective_practice_session' and matching
     * session_number in external_ref, then marks it complete.
     *
     * @param int $enrollment_id
     * @param int $cycle_id
     * @param int $session_number
     */
    public function update_component_state($enrollment_id, $cycle_id, $session_number) {
        global $wpdb;

        // Find matching component in this enrollment's assigned pathway
        $component = $wpdb->get_row($wpdb->prepare(
            "SELECT c.component_id
             FROM {$wpdb->prefix}hl_component c
             JOIN {$wpdb->prefix}hl_pathway p ON c.pathway_id = p.pathway_id
             JOIN {$wpdb->prefix}hl_pathway_assignment pa ON p.pathway_id = pa.pathway_id
             WHERE p.cycle_id = %d
               AND pa.enrollment_id = %d
               AND c.component_type = 'reflective_practice_session'
               AND c.status = 'active'
               AND c.external_ref LIKE %s",
            $cycle_id,
            $enrollment_id,
            '%"session_number":' . intval($session_number) . '%'
        ));

        if (!$component) {
            return;
        }

        $now = current_time('mysql');

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT state_id FROM {$wpdb->prefix}hl_component_state
             WHERE enrollment_id = %d AND component_id = %d",
            $enrollment_id, $component->component_id
        ));

        $state_data = array(
            'completion_percent' => 100,
            'completion_status'  => 'complete',
            'completed_at'       => $now,
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
