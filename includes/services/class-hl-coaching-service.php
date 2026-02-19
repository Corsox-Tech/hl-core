<?php
if (!defined('ABSPATH')) exit;

/**
 * Coaching Service
 *
 * Manages coaching sessions with the expanded schema: session_title,
 * meeting_url, session_status (scheduled/attended/missed/cancelled/rescheduled),
 * reschedule/cancel flows, plus observation links and attachments.
 *
 * @package HL_Core
 */
class HL_Coaching_Service {

    /** Valid session status values. */
    const VALID_SESSION_STATUSES = array('scheduled', 'attended', 'missed', 'cancelled', 'rescheduled');

    /** Terminal statuses that cannot be changed. */
    const TERMINAL_STATUSES = array('attended', 'missed', 'cancelled', 'rescheduled');

    /**
     * Get coaching sessions by cohort
     */
    public function get_by_cohort($cohort_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT cs.*, u_coach.display_name as coach_name, u_mentor.display_name as mentor_name
             FROM {$wpdb->prefix}hl_coaching_session cs
             LEFT JOIN {$wpdb->users} u_coach ON cs.coach_user_id = u_coach.ID
             JOIN {$wpdb->prefix}hl_enrollment e ON cs.mentor_enrollment_id = e.enrollment_id
             LEFT JOIN {$wpdb->users} u_mentor ON e.user_id = u_mentor.ID
             WHERE cs.cohort_id = %d ORDER BY cs.session_datetime DESC, cs.created_at DESC",
            $cohort_id
        ), ARRAY_A) ?: array();
    }

    /**
     * Get sessions for a participant enrollment.
     *
     * @param int $enrollment_id
     * @param int $cohort_id
     * @return array
     */
    public function get_sessions_for_participant($enrollment_id, $cohort_id) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT cs.*, u_coach.display_name AS coach_name
             FROM {$wpdb->prefix}hl_coaching_session cs
             LEFT JOIN {$wpdb->users} u_coach ON cs.coach_user_id = u_coach.ID
             WHERE cs.mentor_enrollment_id = %d AND cs.cohort_id = %d
             ORDER BY cs.session_datetime ASC",
            $enrollment_id, $cohort_id
        ), ARRAY_A) ?: array();
    }

    /**
     * Get upcoming sessions (scheduled, session_datetime >= now) for a participant.
     *
     * @param int $enrollment_id
     * @param int $cohort_id
     * @return array
     */
    public function get_upcoming_sessions($enrollment_id, $cohort_id) {
        global $wpdb;
        $now = current_time('mysql');

        return $wpdb->get_results($wpdb->prepare(
            "SELECT cs.*, u_coach.display_name AS coach_name
             FROM {$wpdb->prefix}hl_coaching_session cs
             LEFT JOIN {$wpdb->users} u_coach ON cs.coach_user_id = u_coach.ID
             WHERE cs.mentor_enrollment_id = %d
               AND cs.cohort_id = %d
               AND cs.session_status = 'scheduled'
               AND cs.session_datetime >= %s
             ORDER BY cs.session_datetime ASC",
            $enrollment_id, $cohort_id, $now
        ), ARRAY_A) ?: array();
    }

    /**
     * Get past sessions (datetime < now OR terminal status) for a participant.
     *
     * @param int $enrollment_id
     * @param int $cohort_id
     * @return array
     */
    public function get_past_sessions($enrollment_id, $cohort_id) {
        global $wpdb;
        $now = current_time('mysql');

        return $wpdb->get_results($wpdb->prepare(
            "SELECT cs.*, u_coach.display_name AS coach_name
             FROM {$wpdb->prefix}hl_coaching_session cs
             LEFT JOIN {$wpdb->users} u_coach ON cs.coach_user_id = u_coach.ID
             WHERE cs.mentor_enrollment_id = %d
               AND cs.cohort_id = %d
               AND (cs.session_status IN ('attended','missed','cancelled','rescheduled')
                    OR (cs.session_datetime < %s AND cs.session_status = 'scheduled'))
             ORDER BY cs.session_datetime DESC",
            $enrollment_id, $cohort_id, $now
        ), ARRAY_A) ?: array();
    }

    // =========================================================================
    // Session Status Transitions
    // =========================================================================

    /**
     * Transition session status with validation.
     *
     * @param int    $session_id
     * @param string $new_status
     * @return bool|WP_Error
     */
    public function transition_status($session_id, $new_status) {
        global $wpdb;

        if (!in_array($new_status, self::VALID_SESSION_STATUSES, true)) {
            return new WP_Error('invalid_status', __('Invalid session status.', 'hl-core'));
        }

        $session = $this->get_session($session_id);
        if (!$session) {
            return new WP_Error('not_found', __('Session not found.', 'hl-core'));
        }

        $current = $session['session_status'] ?? 'scheduled';

        // Only scheduled sessions can transition.
        if (in_array($current, self::TERMINAL_STATUSES, true)) {
            return new WP_Error('terminal_status', sprintf(
                __('Cannot change status from "%s" — it is a terminal state.', 'hl-core'),
                $current
            ));
        }

        $update = array(
            'session_status' => $new_status,
            'updated_at'     => current_time('mysql'),
        );

        // Sync legacy attendance_status for backward compat.
        $attendance_map = array(
            'attended'  => 'attended',
            'missed'    => 'missed',
            'scheduled' => 'unknown',
        );
        if (isset($attendance_map[$new_status])) {
            $update['attendance_status'] = $attendance_map[$new_status];
        }

        if ($new_status === 'cancelled') {
            $update['cancelled_at'] = current_time('mysql');
        }

        $result = $wpdb->update(
            $wpdb->prefix . 'hl_coaching_session',
            $update,
            array('session_id' => $session_id)
        );

        if ($result === false) {
            return new WP_Error('db_error', __('Failed to update session status.', 'hl-core'));
        }

        // If attended, trigger activity state updates.
        if ($new_status === 'attended') {
            $this->update_coaching_activity_state(
                $session['mentor_enrollment_id'],
                $session['cohort_id']
            );
        }

        HL_Audit_Service::log('coaching_session.status_changed', array(
            'entity_type' => 'coaching_session',
            'entity_id'   => $session_id,
            'cohort_id'   => $session['cohort_id'],
            'before_data' => array('session_status' => $current),
            'after_data'  => array('session_status' => $new_status),
        ));

        return true;
    }

    /**
     * Cancel a session.
     *
     * @param int $session_id
     * @return bool|WP_Error
     */
    public function cancel_session($session_id) {
        return $this->transition_status($session_id, 'cancelled');
    }

    /**
     * Reschedule a session: mark old as 'rescheduled', create new session.
     *
     * @param int    $session_id       Original session.
     * @param string $new_datetime     New datetime string.
     * @param string $new_meeting_url  Optional meeting URL.
     * @return int|WP_Error New session ID.
     */
    public function reschedule_session($session_id, $new_datetime, $new_meeting_url = null) {
        $session = $this->get_session($session_id);
        if (!$session) {
            return new WP_Error('not_found', __('Session not found.', 'hl-core'));
        }

        $current = $session['session_status'] ?? 'scheduled';
        if (in_array($current, self::TERMINAL_STATUSES, true)) {
            return new WP_Error('terminal_status', __('Cannot reschedule a session that is not scheduled.', 'hl-core'));
        }

        // Mark old session as rescheduled.
        $transition = $this->transition_status($session_id, 'rescheduled');
        if (is_wp_error($transition)) {
            return $transition;
        }

        // Create new session linked to old one.
        $new_data = array(
            'cohort_id'                    => $session['cohort_id'],
            'coach_user_id'                => $session['coach_user_id'],
            'mentor_enrollment_id'         => $session['mentor_enrollment_id'],
            'session_title'                => $session['session_title'],
            'meeting_url'                  => $new_meeting_url ?: ($session['meeting_url'] ?? null),
            'session_datetime'             => $new_datetime,
            'rescheduled_from_session_id'  => $session_id,
        );

        return $this->create_session($new_data);
    }

    /**
     * Check if cancellation is allowed for a cohort.
     *
     * @param int $cohort_id
     * @return bool
     */
    public function is_cancellation_allowed($cohort_id) {
        global $wpdb;

        $settings_json = $wpdb->get_var($wpdb->prepare(
            "SELECT settings FROM {$wpdb->prefix}hl_cohort WHERE cohort_id = %d",
            $cohort_id
        ));

        if (empty($settings_json)) {
            return true; // Default: cancellation allowed.
        }

        $settings = json_decode($settings_json, true);
        if (!is_array($settings)) {
            return true;
        }

        return isset($settings['coaching_allow_cancellation'])
            ? (bool) $settings['coaching_allow_cancellation']
            : true;
    }

    // =========================================================================
    // Legacy Attendance (backward compat)
    // =========================================================================

    /**
     * Mark coaching attendance and update activity state if applicable.
     *
     * Kept for backward compatibility. New code should use transition_status().
     *
     * @param int    $session_id
     * @param string $status 'attended', 'missed', or 'unknown'
     * @return int|false
     */
    public function mark_attendance($session_id, $status) {
        global $wpdb;

        $update = array('attendance_status' => $status);

        // Sync to session_status.
        $status_map = array(
            'attended' => 'attended',
            'missed'   => 'missed',
            'unknown'  => 'scheduled',
        );
        if (isset($status_map[$status])) {
            $update['session_status'] = $status_map[$status];
        }

        $result = $wpdb->update(
            $wpdb->prefix . 'hl_coaching_session',
            $update,
            array('session_id' => $session_id)
        );

        if ($result === false) {
            return false;
        }

        if ($status === 'attended') {
            $session = $wpdb->get_row($wpdb->prepare(
                "SELECT cohort_id, mentor_enrollment_id FROM {$wpdb->prefix}hl_coaching_session WHERE session_id = %d",
                $session_id
            ));

            if ($session) {
                $this->update_coaching_activity_state($session->mentor_enrollment_id, $session->cohort_id);
            }
        }

        HL_Audit_Service::log('coaching_session.attendance_marked', array(
            'entity_type' => 'coaching_session',
            'entity_id'   => $session_id,
            'after_data'  => array('attendance_status' => $status),
        ));

        return $result;
    }

    /**
     * Update coaching_session_attendance activity state for a participant enrollment.
     *
     * @param int $enrollment_id
     * @param int $cohort_id
     */
    private function update_coaching_activity_state($enrollment_id, $cohort_id) {
        global $wpdb;

        $activities = $wpdb->get_results($wpdb->prepare(
            "SELECT a.activity_id FROM {$wpdb->prefix}hl_activity a
             JOIN {$wpdb->prefix}hl_pathway p ON a.pathway_id = p.pathway_id
             WHERE p.cohort_id = %d
               AND a.activity_type = 'coaching_session_attendance'
               AND a.status = 'active'",
            $cohort_id
        ));

        if (empty($activities)) {
            return;
        }

        // Count attended sessions (using both columns for safety).
        $attended_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}hl_coaching_session
             WHERE mentor_enrollment_id = %d AND cohort_id = %d
               AND (session_status = 'attended' OR attendance_status = 'attended')",
            $enrollment_id, $cohort_id
        ));

        $now = current_time('mysql');

        foreach ($activities as $activity) {
            $percent = ($attended_count > 0) ? 100 : 0;
            $status  = ($attended_count > 0) ? 'complete' : 'not_started';

            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT state_id FROM {$wpdb->prefix}hl_activity_state
                 WHERE enrollment_id = %d AND activity_id = %d",
                $enrollment_id, $activity->activity_id
            ));

            $state_data = array(
                'completion_percent' => $percent,
                'completion_status'  => $status,
                'completed_at'       => ($percent === 100) ? $now : null,
                'last_computed_at'   => $now,
            );

            if ($existing) {
                $wpdb->update(
                    $wpdb->prefix . 'hl_activity_state',
                    $state_data,
                    array('state_id' => $existing)
                );
            } else {
                $state_data['enrollment_id'] = $enrollment_id;
                $state_data['activity_id']   = $activity->activity_id;
                $wpdb->insert($wpdb->prefix . 'hl_activity_state', $state_data);
            }
        }

        do_action('hl_core_recompute_rollups', $enrollment_id);
    }

    // =========================================================================
    // Session CRUD
    // =========================================================================

    /**
     * Get a single coaching session by ID with joined data.
     *
     * @param int $session_id
     * @return array|null
     */
    public function get_session($session_id) {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT cs.*,
                    u_coach.display_name AS coach_name,
                    u_coach.user_email AS coach_email,
                    u_mentor.display_name AS mentor_name,
                    c.cohort_name
             FROM {$wpdb->prefix}hl_coaching_session cs
             LEFT JOIN {$wpdb->users} u_coach ON cs.coach_user_id = u_coach.ID
             LEFT JOIN {$wpdb->prefix}hl_enrollment e ON cs.mentor_enrollment_id = e.enrollment_id
             LEFT JOIN {$wpdb->users} u_mentor ON e.user_id = u_mentor.ID
             LEFT JOIN {$wpdb->prefix}hl_cohort c ON cs.cohort_id = c.cohort_id
             WHERE cs.session_id = %d",
            $session_id
        ), ARRAY_A);

        return $row ?: null;
    }

    /**
     * Create a coaching session.
     *
     * @param array $data Keys: cohort_id, coach_user_id, mentor_enrollment_id,
     *                     session_datetime, session_title, meeting_url, notes_richtext,
     *                     rescheduled_from_session_id.
     * @return int|WP_Error session_id on success.
     */
    public function create_session($data) {
        global $wpdb;

        if (empty($data['cohort_id'])) {
            return new WP_Error('missing_cohort', __('Cohort is required.', 'hl-core'));
        }
        if (empty($data['mentor_enrollment_id'])) {
            return new WP_Error('missing_mentor', __('Participant is required.', 'hl-core'));
        }

        $coach_user_id = !empty($data['coach_user_id']) ? absint($data['coach_user_id']) : get_current_user_id();

        $insert_data = array(
            'session_uuid'                => HL_DB_Utils::generate_uuid(),
            'cohort_id'                   => absint($data['cohort_id']),
            'coach_user_id'               => $coach_user_id,
            'mentor_enrollment_id'        => absint($data['mentor_enrollment_id']),
            'session_title'               => !empty($data['session_title']) ? sanitize_text_field($data['session_title']) : null,
            'meeting_url'                 => !empty($data['meeting_url']) ? esc_url_raw($data['meeting_url']) : null,
            'session_status'              => 'scheduled',
            'attendance_status'           => 'unknown',
            'session_datetime'            => !empty($data['session_datetime']) ? sanitize_text_field($data['session_datetime']) : null,
            'notes_richtext'              => !empty($data['notes_richtext']) ? wp_kses_post($data['notes_richtext']) : null,
            'rescheduled_from_session_id' => !empty($data['rescheduled_from_session_id']) ? absint($data['rescheduled_from_session_id']) : null,
            'created_at'                  => current_time('mysql'),
            'updated_at'                  => current_time('mysql'),
        );

        $result = $wpdb->insert($wpdb->prefix . 'hl_coaching_session', $insert_data);

        if ($result === false) {
            return new WP_Error('db_error', __('Failed to create coaching session.', 'hl-core'));
        }

        $session_id = $wpdb->insert_id;

        HL_Audit_Service::log('coaching_session.created', array(
            'entity_type' => 'coaching_session',
            'entity_id'   => $session_id,
            'cohort_id'   => $insert_data['cohort_id'],
            'after_data'  => array(
                'cohort_id'            => $insert_data['cohort_id'],
                'coach_user_id'        => $insert_data['coach_user_id'],
                'mentor_enrollment_id' => $insert_data['mentor_enrollment_id'],
                'session_title'        => $insert_data['session_title'],
                'session_datetime'     => $insert_data['session_datetime'],
            ),
        ));

        return $session_id;
    }

    /**
     * Update a coaching session.
     *
     * @param int   $session_id
     * @param array $data Keys: session_datetime, notes_richtext, session_status,
     *                     session_title, meeting_url, attendance_status.
     * @return bool|WP_Error
     */
    public function update_session($session_id, $data) {
        global $wpdb;

        $before = $this->get_session($session_id);
        if (!$before) {
            return new WP_Error('not_found', __('Coaching session not found.', 'hl-core'));
        }

        $update_data = array(
            'updated_at' => current_time('mysql'),
        );

        if (array_key_exists('session_datetime', $data)) {
            $update_data['session_datetime'] = !empty($data['session_datetime'])
                ? sanitize_text_field($data['session_datetime'])
                : null;
        }

        if (array_key_exists('session_title', $data)) {
            $update_data['session_title'] = !empty($data['session_title'])
                ? sanitize_text_field($data['session_title'])
                : null;
        }

        if (array_key_exists('meeting_url', $data)) {
            $update_data['meeting_url'] = !empty($data['meeting_url'])
                ? esc_url_raw($data['meeting_url'])
                : null;
        }

        if (array_key_exists('notes_richtext', $data)) {
            $update_data['notes_richtext'] = !empty($data['notes_richtext'])
                ? wp_kses_post($data['notes_richtext'])
                : null;
        }

        // Handle session_status change via transition_status for validation.
        $status_changed = false;
        if (array_key_exists('session_status', $data)) {
            $new_status = sanitize_text_field($data['session_status']);
            $current    = $before['session_status'] ?? 'scheduled';

            if ($new_status !== $current && in_array($new_status, self::VALID_SESSION_STATUSES, true)) {
                $update_data['session_status'] = $new_status;
                $status_changed = true;

                // Sync legacy field.
                $att_map = array('attended' => 'attended', 'missed' => 'missed', 'scheduled' => 'unknown');
                if (isset($att_map[$new_status])) {
                    $update_data['attendance_status'] = $att_map[$new_status];
                }

                if ($new_status === 'cancelled') {
                    $update_data['cancelled_at'] = current_time('mysql');
                }
            }
        }

        // Legacy attendance_status handling (backward compat).
        $attendance_changed = false;
        if (array_key_exists('attendance_status', $data) && !$status_changed) {
            $valid = array('attended', 'missed', 'unknown');
            $new_att = sanitize_text_field($data['attendance_status']);
            if (in_array($new_att, $valid, true)) {
                $update_data['attendance_status'] = $new_att;
                if ($new_att !== ($before['attendance_status'] ?? '')) {
                    $attendance_changed = true;
                }
                // Sync session_status.
                $ss_map = array('attended' => 'attended', 'missed' => 'missed', 'unknown' => 'scheduled');
                $update_data['session_status'] = $ss_map[$new_att];
            }
        }

        $result = $wpdb->update(
            $wpdb->prefix . 'hl_coaching_session',
            $update_data,
            array('session_id' => $session_id)
        );

        if ($result === false) {
            return new WP_Error('db_error', __('Failed to update coaching session.', 'hl-core'));
        }

        // Trigger activity state updates if session became attended.
        $became_attended = ($status_changed && ($update_data['session_status'] ?? '') === 'attended')
                        || ($attendance_changed && ($update_data['attendance_status'] ?? '') === 'attended');

        if ($became_attended) {
            $this->update_coaching_activity_state($before['mentor_enrollment_id'], $before['cohort_id']);
        }

        HL_Audit_Service::log('coaching_session.updated', array(
            'entity_type' => 'coaching_session',
            'entity_id'   => $session_id,
            'cohort_id'   => $before['cohort_id'],
            'before_data' => array(
                'session_datetime' => $before['session_datetime'],
                'session_status'   => $before['session_status'] ?? 'scheduled',
                'session_title'    => $before['session_title'] ?? null,
            ),
            'after_data'  => $update_data,
        ));

        return true;
    }

    /**
     * Delete a coaching session.
     *
     * @param int $session_id
     * @return bool|WP_Error
     */
    public function delete_session($session_id) {
        global $wpdb;

        $before = $this->get_session($session_id);
        if (!$before) {
            return new WP_Error('not_found', __('Coaching session not found.', 'hl-core'));
        }

        $wpdb->delete($wpdb->prefix . 'hl_coaching_session_observation', array('session_id' => $session_id));
        $wpdb->delete($wpdb->prefix . 'hl_coaching_attachment', array('session_id' => $session_id));

        $result = $wpdb->delete($wpdb->prefix . 'hl_coaching_session', array('session_id' => $session_id));

        if ($result === false) {
            return new WP_Error('db_error', __('Failed to delete coaching session.', 'hl-core'));
        }

        HL_Audit_Service::log('coaching_session.deleted', array(
            'entity_type' => 'coaching_session',
            'entity_id'   => $session_id,
            'cohort_id'   => $before['cohort_id'],
            'before_data' => array(
                'cohort_id'            => $before['cohort_id'],
                'coach_user_id'        => $before['coach_user_id'],
                'mentor_enrollment_id' => $before['mentor_enrollment_id'],
                'session_status'       => $before['session_status'] ?? 'scheduled',
                'session_datetime'     => $before['session_datetime'],
            ),
        ));

        return true;
    }

    // =========================================================================
    // Observation Links
    // =========================================================================

    /**
     * Link observations to a coaching session.
     *
     * @param int   $session_id
     * @param array $observation_ids
     * @return int Number of links created.
     */
    public function link_observations($session_id, $observation_ids) {
        global $wpdb;

        $linked = 0;
        foreach ($observation_ids as $observation_id) {
            $observation_id = absint($observation_id);
            if (!$observation_id) {
                continue;
            }

            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT link_id FROM {$wpdb->prefix}hl_coaching_session_observation
                 WHERE session_id = %d AND observation_id = %d",
                $session_id, $observation_id
            ));

            if ($exists) {
                continue;
            }

            $result = $wpdb->insert(
                $wpdb->prefix . 'hl_coaching_session_observation',
                array('session_id' => $session_id, 'observation_id' => $observation_id)
            );

            if ($result !== false) {
                $linked++;
            }
        }

        if ($linked > 0) {
            HL_Audit_Service::log('coaching_session.observations_linked', array(
                'entity_type' => 'coaching_session',
                'entity_id'   => $session_id,
                'after_data'  => array('observation_ids' => $observation_ids, 'linked_count' => $linked),
            ));
        }

        return $linked;
    }

    /**
     * Unlink an observation from a coaching session.
     *
     * @param int $session_id
     * @param int $observation_id
     * @return bool
     */
    public function unlink_observation($session_id, $observation_id) {
        global $wpdb;

        $result = $wpdb->delete(
            $wpdb->prefix . 'hl_coaching_session_observation',
            array('session_id' => $session_id, 'observation_id' => $observation_id)
        );

        if ($result) {
            HL_Audit_Service::log('coaching_session.observation_unlinked', array(
                'entity_type' => 'coaching_session',
                'entity_id'   => $session_id,
                'after_data'  => array('observation_id' => $observation_id),
            ));
        }

        return ($result !== false);
    }

    /**
     * Get linked observations for a session.
     *
     * @param int $session_id
     * @return array
     */
    public function get_linked_observations($session_id) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT o.observation_id, o.status, o.submitted_at, o.created_at,
                    u_mentor.display_name AS mentor_name,
                    u_teacher.display_name AS teacher_name,
                    cso.link_id
             FROM {$wpdb->prefix}hl_coaching_session_observation cso
             JOIN {$wpdb->prefix}hl_observation o ON cso.observation_id = o.observation_id
             LEFT JOIN {$wpdb->prefix}hl_enrollment e_mentor ON o.mentor_enrollment_id = e_mentor.enrollment_id
             LEFT JOIN {$wpdb->users} u_mentor ON e_mentor.user_id = u_mentor.ID
             LEFT JOIN {$wpdb->prefix}hl_enrollment e_teacher ON o.teacher_enrollment_id = e_teacher.enrollment_id
             LEFT JOIN {$wpdb->users} u_teacher ON e_teacher.user_id = u_teacher.ID
             WHERE cso.session_id = %d
             ORDER BY o.created_at DESC",
            $session_id
        ), ARRAY_A) ?: array();
    }

    /**
     * Get available observations for linking.
     *
     * @param int $session_id
     * @param int $cohort_id
     * @param int $mentor_enrollment_id
     * @return array
     */
    public function get_available_observations($session_id, $cohort_id, $mentor_enrollment_id) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT o.observation_id, o.status, o.submitted_at, o.created_at,
                    u_teacher.display_name AS teacher_name
             FROM {$wpdb->prefix}hl_observation o
             LEFT JOIN {$wpdb->prefix}hl_enrollment e_teacher ON o.teacher_enrollment_id = e_teacher.enrollment_id
             LEFT JOIN {$wpdb->users} u_teacher ON e_teacher.user_id = u_teacher.ID
             WHERE o.cohort_id = %d
               AND o.mentor_enrollment_id = %d
               AND o.status = 'submitted'
               AND o.observation_id NOT IN (
                   SELECT cso.observation_id
                   FROM {$wpdb->prefix}hl_coaching_session_observation cso
                   WHERE cso.session_id = %d
               )
             ORDER BY o.submitted_at DESC",
            $cohort_id, $mentor_enrollment_id, $session_id
        ), ARRAY_A) ?: array();
    }

    // =========================================================================
    // Attachments
    // =========================================================================

    /**
     * Add an attachment to a coaching session.
     *
     * @param int $session_id
     * @param int $wp_media_id
     * @return int|WP_Error
     */
    public function add_attachment($session_id, $wp_media_id) {
        global $wpdb;

        $attachment_url  = wp_get_attachment_url($wp_media_id);
        $attachment_mime = get_post_mime_type($wp_media_id);

        if (!$attachment_url) {
            return new WP_Error('invalid_media', __('Invalid media attachment.', 'hl-core'));
        }

        $result = $wpdb->insert(
            $wpdb->prefix . 'hl_coaching_attachment',
            array(
                'session_id'  => absint($session_id),
                'wp_media_id' => absint($wp_media_id),
                'file_url'    => $attachment_url,
                'mime_type'   => $attachment_mime ? sanitize_text_field($attachment_mime) : null,
            )
        );

        if ($result === false) {
            return new WP_Error('db_error', __('Failed to add attachment.', 'hl-core'));
        }

        $attachment_id = $wpdb->insert_id;

        HL_Audit_Service::log('coaching_session.attachment_added', array(
            'entity_type' => 'coaching_session',
            'entity_id'   => $session_id,
            'after_data'  => array(
                'attachment_id' => $attachment_id,
                'wp_media_id'   => $wp_media_id,
                'file_url'      => $attachment_url,
            ),
        ));

        return $attachment_id;
    }

    /**
     * Remove an attachment.
     *
     * @param int $attachment_id
     * @return bool
     */
    public function remove_attachment($attachment_id) {
        global $wpdb;

        $attachment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_coaching_attachment WHERE attachment_id = %d",
            $attachment_id
        ), ARRAY_A);

        $result = $wpdb->delete(
            $wpdb->prefix . 'hl_coaching_attachment',
            array('attachment_id' => $attachment_id)
        );

        if ($result && $attachment) {
            HL_Audit_Service::log('coaching_session.attachment_removed', array(
                'entity_type' => 'coaching_session',
                'entity_id'   => $attachment['session_id'],
                'before_data' => array(
                    'attachment_id' => $attachment_id,
                    'wp_media_id'   => $attachment['wp_media_id'],
                    'file_url'      => $attachment['file_url'],
                ),
            ));
        }

        return ($result !== false);
    }

    /**
     * Get attachments for a coaching session.
     *
     * @param int $session_id
     * @return array
     */
    public function get_attachments($session_id) {
        global $wpdb;

        $attachments = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_coaching_attachment WHERE session_id = %d ORDER BY attachment_id ASC",
            $session_id
        ), ARRAY_A) ?: array();

        foreach ($attachments as &$att) {
            $att['current_url'] = $att['file_url'];
            if (!empty($att['wp_media_id'])) {
                $current_url = wp_get_attachment_url($att['wp_media_id']);
                if ($current_url) {
                    $att['current_url'] = $current_url;
                }
                $att['filename'] = basename(get_attached_file($att['wp_media_id']) ?: $att['file_url']);
            } else {
                $att['filename'] = basename($att['file_url']);
            }
        }
        unset($att);

        return $attachments;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Get the count of linked observations for a session.
     *
     * @param int $session_id
     * @return int
     */
    public function get_linked_observation_count($session_id) {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}hl_coaching_session_observation WHERE session_id = %d",
            $session_id
        ));
    }

    /**
     * Render a session status badge (HTML).
     *
     * @param string $status
     * @return string
     */
    public static function render_status_badge($status) {
        $badges = array(
            'scheduled'    => array('#cce5ff', '#004085', __('Scheduled', 'hl-core')),
            'attended'     => array('#d4edda', '#155724', __('Attended', 'hl-core')),
            'missed'       => array('#f8d7da', '#721c24', __('Missed', 'hl-core')),
            'cancelled'    => array('#e2e3e5', '#383d41', __('Cancelled', 'hl-core')),
            'rescheduled'  => array('#fff3cd', '#856404', __('Rescheduled', 'hl-core')),
        );

        $badge = isset($badges[$status]) ? $badges[$status] : array('#e2e3e5', '#383d41', esc_html($status));

        return sprintf(
            '<span style="display:inline-block;padding:3px 10px;border-radius:3px;font-size:12px;font-weight:600;background:%s;color:%s;">%s</span>',
            $badge[0], $badge[1], esc_html($badge[2])
        );
    }
}
