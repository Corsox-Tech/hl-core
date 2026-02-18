<?php
if (!defined('ABSPATH')) exit;

class HL_Coaching_Service {

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
             WHERE cs.cohort_id = %d ORDER BY cs.created_at DESC",
            $cohort_id
        ), ARRAY_A) ?: array();
    }

    /**
     * Mark coaching attendance and update activity state if applicable
     *
     * When attendance_status is set to 'attended', finds any
     * coaching_session_attendance activities for the mentor's enrollment
     * and marks them complete.
     *
     * @param int    $session_id
     * @param string $status 'attended', 'missed', or 'unknown'
     * @return int|false Number of rows updated, or false on error
     */
    public function mark_attendance($session_id, $status) {
        global $wpdb;

        $result = $wpdb->update(
            $wpdb->prefix . 'hl_coaching_session',
            array('attendance_status' => $status),
            array('session_id' => $session_id)
        );

        if ($result === false) {
            return false;
        }

        // If marked as attended, update activity_state for coaching_session_attendance
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
     * Update coaching_session_attendance activity state for a mentor enrollment
     *
     * Checks all coaching sessions for this enrollment. If any are attended,
     * marks the coaching_session_attendance activity as complete.
     *
     * @param int $enrollment_id Mentor enrollment ID
     * @param int $cohort_id
     */
    private function update_coaching_activity_state($enrollment_id, $cohort_id) {
        global $wpdb;

        // Find coaching_session_attendance activities in this cohort
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

        // Count attended sessions
        $attended_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}hl_coaching_session
             WHERE mentor_enrollment_id = %d AND cohort_id = %d AND attendance_status = 'attended'",
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

        // Trigger rollup recomputation
        do_action('hl_core_recompute_rollups', $enrollment_id);
    }

    // =========================================================================
    // Session CRUD
    // =========================================================================

    /**
     * Get a single coaching session by ID with joined data
     *
     * @param int $session_id
     * @return array|null Session data with coach_name, mentor_name, cohort_name
     */
    public function get_session($session_id) {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT cs.*,
                    u_coach.display_name AS coach_name,
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
     * Create a coaching session
     *
     * @param array $data Keys: cohort_id, coach_user_id, mentor_enrollment_id, session_datetime, notes_richtext
     * @return int|WP_Error session_id on success, WP_Error on failure
     */
    public function create_session($data) {
        global $wpdb;

        // Validate required fields
        if (empty($data['cohort_id'])) {
            return new WP_Error('missing_cohort', __('Cohort is required.', 'hl-core'));
        }
        if (empty($data['mentor_enrollment_id'])) {
            return new WP_Error('missing_mentor', __('Mentor is required.', 'hl-core'));
        }

        $coach_user_id = !empty($data['coach_user_id']) ? absint($data['coach_user_id']) : get_current_user_id();

        $insert_data = array(
            'session_uuid'          => HL_DB_Utils::generate_uuid(),
            'cohort_id'             => absint($data['cohort_id']),
            'coach_user_id'         => $coach_user_id,
            'mentor_enrollment_id'  => absint($data['mentor_enrollment_id']),
            'attendance_status'     => 'unknown',
            'session_datetime'      => !empty($data['session_datetime']) ? sanitize_text_field($data['session_datetime']) : null,
            'notes_richtext'        => !empty($data['notes_richtext']) ? wp_kses_post($data['notes_richtext']) : null,
            'created_at'            => current_time('mysql'),
            'updated_at'            => current_time('mysql'),
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
                'session_datetime'     => $insert_data['session_datetime'],
            ),
        ));

        return $session_id;
    }

    /**
     * Update a coaching session
     *
     * @param int   $session_id
     * @param array $data Keys: session_datetime, notes_richtext, attendance_status
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function update_session($session_id, $data) {
        global $wpdb;

        // Get current session for comparison
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

        if (array_key_exists('notes_richtext', $data)) {
            $update_data['notes_richtext'] = !empty($data['notes_richtext'])
                ? wp_kses_post($data['notes_richtext'])
                : null;
        }

        $attendance_changed = false;
        if (array_key_exists('attendance_status', $data)) {
            $valid_statuses = array('attended', 'missed', 'unknown');
            $new_status = sanitize_text_field($data['attendance_status']);
            if (in_array($new_status, $valid_statuses, true)) {
                $update_data['attendance_status'] = $new_status;
                if ($new_status !== $before['attendance_status']) {
                    $attendance_changed = true;
                }
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

        // If attendance_status changed, call mark_attendance to trigger activity state and rollup updates
        if ($attendance_changed) {
            $this->mark_attendance($session_id, $update_data['attendance_status']);
        }

        HL_Audit_Service::log('coaching_session.updated', array(
            'entity_type' => 'coaching_session',
            'entity_id'   => $session_id,
            'cohort_id'   => $before['cohort_id'],
            'before_data' => array(
                'session_datetime'  => $before['session_datetime'],
                'attendance_status' => $before['attendance_status'],
            ),
            'after_data'  => $update_data,
        ));

        return true;
    }

    /**
     * Delete a coaching session
     *
     * Removes the session and all linked observations and attachments.
     *
     * @param int $session_id
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function delete_session($session_id) {
        global $wpdb;

        // Get before data for audit
        $before = $this->get_session($session_id);
        if (!$before) {
            return new WP_Error('not_found', __('Coaching session not found.', 'hl-core'));
        }

        // Delete linked observations
        $wpdb->delete(
            $wpdb->prefix . 'hl_coaching_session_observation',
            array('session_id' => $session_id)
        );

        // Delete attachments
        $wpdb->delete(
            $wpdb->prefix . 'hl_coaching_attachment',
            array('session_id' => $session_id)
        );

        // Delete session
        $result = $wpdb->delete(
            $wpdb->prefix . 'hl_coaching_session',
            array('session_id' => $session_id)
        );

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
                'session_datetime'     => $before['session_datetime'],
                'attendance_status'    => $before['attendance_status'],
            ),
        ));

        return true;
    }

    // =========================================================================
    // Observation Links
    // =========================================================================

    /**
     * Link observations to a coaching session
     *
     * @param int   $session_id
     * @param array $observation_ids Array of observation IDs to link
     * @return int Number of links created
     */
    public function link_observations($session_id, $observation_ids) {
        global $wpdb;

        $linked = 0;
        foreach ($observation_ids as $observation_id) {
            $observation_id = absint($observation_id);
            if (!$observation_id) {
                continue;
            }

            // Check if link already exists
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT link_id FROM {$wpdb->prefix}hl_coaching_session_observation
                 WHERE session_id = %d AND observation_id = %d",
                $session_id,
                $observation_id
            ));

            if ($exists) {
                continue;
            }

            $result = $wpdb->insert(
                $wpdb->prefix . 'hl_coaching_session_observation',
                array(
                    'session_id'     => $session_id,
                    'observation_id' => $observation_id,
                )
            );

            if ($result !== false) {
                $linked++;
            }
        }

        if ($linked > 0) {
            HL_Audit_Service::log('coaching_session.observations_linked', array(
                'entity_type' => 'coaching_session',
                'entity_id'   => $session_id,
                'after_data'  => array(
                    'observation_ids' => $observation_ids,
                    'linked_count'    => $linked,
                ),
            ));
        }

        return $linked;
    }

    /**
     * Unlink an observation from a coaching session
     *
     * @param int $session_id
     * @param int $observation_id
     * @return bool True on success
     */
    public function unlink_observation($session_id, $observation_id) {
        global $wpdb;

        $result = $wpdb->delete(
            $wpdb->prefix . 'hl_coaching_session_observation',
            array(
                'session_id'     => $session_id,
                'observation_id' => $observation_id,
            )
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
     * Get linked observations for a session
     *
     * @param int $session_id
     * @return array Array of observation data with mentor_name, teacher_name, status, date
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
     * Get available observations for linking
     *
     * Returns submitted observations in the cohort for this mentor
     * that are not already linked to the given session.
     *
     * @param int $session_id     Current session ID (to exclude already-linked)
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
            $cohort_id,
            $mentor_enrollment_id,
            $session_id
        ), ARRAY_A) ?: array();
    }

    // =========================================================================
    // Attachments
    // =========================================================================

    /**
     * Add an attachment to a coaching session
     *
     * @param int $session_id
     * @param int $wp_media_id WordPress media attachment ID
     * @return int|WP_Error attachment_id on success, WP_Error on failure
     */
    public function add_attachment($session_id, $wp_media_id) {
        global $wpdb;

        // Get attachment info from WordPress
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
     * Remove an attachment from a coaching session
     *
     * @param int $attachment_id
     * @return bool True on success
     */
    public function remove_attachment($attachment_id) {
        global $wpdb;

        // Get attachment info before deleting for audit
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
     * Get attachments for a coaching session
     *
     * @param int $session_id
     * @return array Array of attachment data with WP attachment URLs
     */
    public function get_attachments($session_id) {
        global $wpdb;

        $attachments = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_coaching_attachment WHERE session_id = %d ORDER BY attachment_id ASC",
            $session_id
        ), ARRAY_A) ?: array();

        // Enrich with current WP attachment data
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
    // Observation Count Helper
    // =========================================================================

    /**
     * Get the count of linked observations for a session
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
}
