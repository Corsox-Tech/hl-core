<?php
if (!defined('ABSPATH')) exit;

class HL_Assessment_Service {

    // =========================================================================
    // Teacher Self-Assessment Queries
    // =========================================================================

    /**
     * Get teacher self-assessment instances for an enrollment
     */
    public function get_teacher_assessments($enrollment_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_teacher_assessment_instance WHERE enrollment_id = %d ORDER BY phase ASC",
            $enrollment_id
        ), ARRAY_A) ?: array();
    }

    /**
     * Get teacher self-assessment instances by track with user info
     */
    public function get_teacher_assessments_by_track($track_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT tai.*, e.user_id, u.display_name, u.user_email
             FROM {$wpdb->prefix}hl_teacher_assessment_instance tai
             JOIN {$wpdb->prefix}hl_enrollment e ON tai.enrollment_id = e.enrollment_id
             LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
             WHERE tai.track_id = %d ORDER BY tai.phase ASC, u.display_name ASC",
            $track_id
        ), ARRAY_A) ?: array();
    }

    /**
     * Get a single teacher assessment instance by ID
     */
    public function get_teacher_assessment($instance_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT tai.*, u.display_name, u.user_email, e.user_id, e.roles, t.track_name
             FROM {$wpdb->prefix}hl_teacher_assessment_instance tai
             JOIN {$wpdb->prefix}hl_enrollment e ON tai.enrollment_id = e.enrollment_id
             LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
             LEFT JOIN {$wpdb->prefix}hl_track t ON tai.track_id = t.track_id
             WHERE tai.instance_id = %d",
            $instance_id
        ), ARRAY_A);
    }

    /**
     * Get responses for a teacher assessment instance
     */
    public function get_teacher_assessment_responses($instance_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_teacher_assessment_response
             WHERE instance_id = %d ORDER BY question_id ASC",
            $instance_id
        ), ARRAY_A) ?: array();
    }

    /**
     * Create a teacher assessment instance
     */
    public function create_teacher_assessment_instance($data) {
        global $wpdb;

        if (empty($data['track_id']) || empty($data['enrollment_id']) || empty($data['phase'])) {
            return new WP_Error('missing_fields', __('Track, enrollment, and phase are required.', 'hl-core'));
        }

        $insert_data = array(
            'instance_uuid'      => HL_DB_Utils::generate_uuid(),
            'track_id'          => absint($data['track_id']),
            'enrollment_id'      => absint($data['enrollment_id']),
            'activity_id'        => !empty($data['activity_id']) ? absint($data['activity_id']) : null,
            'phase'              => sanitize_text_field($data['phase']),
            'instrument_id'      => !empty($data['instrument_id']) ? absint($data['instrument_id']) : null,
            'instrument_version' => !empty($data['instrument_version']) ? sanitize_text_field($data['instrument_version']) : null,
            'status'             => 'not_started',
        );

        $result = $wpdb->insert($wpdb->prefix . 'hl_teacher_assessment_instance', $insert_data);

        if ($result === false) {
            return new WP_Error('duplicate', __('A teacher assessment instance already exists for this enrollment and phase.', 'hl-core'));
        }

        $instance_id = $wpdb->insert_id;

        HL_Audit_Service::log('teacher_assessment.created', array(
            'entity_type' => 'teacher_assessment_instance',
            'entity_id'   => $instance_id,
            'track_id'   => $data['track_id'],
            'after_data'  => $insert_data,
        ));

        return $instance_id;
    }

    /**
     * Submit teacher assessment responses
     *
     * @param int   $instance_id
     * @param array $responses Array of ['question_id' => value]
     * @return true|WP_Error
     */
    public function submit_teacher_assessment($instance_id, $responses) {
        global $wpdb;

        $instance = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_teacher_assessment_instance WHERE instance_id = %d",
            $instance_id
        ), ARRAY_A);

        if (!$instance) {
            return new WP_Error('not_found', __('Assessment instance not found.', 'hl-core'));
        }

        if ($instance['status'] === 'submitted') {
            return new WP_Error('already_submitted', __('This assessment has already been submitted.', 'hl-core'));
        }

        // Save responses (upsert pattern)
        foreach ($responses as $question_id => $value) {
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT response_id FROM {$wpdb->prefix}hl_teacher_assessment_response
                 WHERE instance_id = %d AND question_id = %s",
                $instance_id, $question_id
            ));

            if ($existing) {
                $wpdb->update(
                    $wpdb->prefix . 'hl_teacher_assessment_response',
                    array('value' => is_array($value) ? wp_json_encode($value) : $value),
                    array('response_id' => $existing)
                );
            } else {
                $wpdb->insert($wpdb->prefix . 'hl_teacher_assessment_response', array(
                    'instance_id' => $instance_id,
                    'question_id' => sanitize_text_field($question_id),
                    'value'       => is_array($value) ? wp_json_encode($value) : $value,
                ));
            }
        }

        // Mark as submitted
        $now = current_time('mysql');
        $wpdb->update(
            $wpdb->prefix . 'hl_teacher_assessment_instance',
            array('status' => 'submitted', 'submitted_at' => $now),
            array('instance_id' => $instance_id)
        );

        HL_Audit_Service::log('teacher_assessment.submitted', array(
            'entity_type' => 'teacher_assessment_instance',
            'entity_id'   => $instance_id,
            'track_id'   => $instance['track_id'],
        ));

        return true;
    }

    /**
     * Check if all teacher assessments are complete for an enrollment in a track
     */
    public function is_teacher_assessment_complete($enrollment_id, $track_id) {
        global $wpdb;
        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}hl_teacher_assessment_instance WHERE enrollment_id = %d AND track_id = %d",
            $enrollment_id, $track_id
        ));
        if ($total === 0) return true;

        $submitted = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}hl_teacher_assessment_instance WHERE enrollment_id = %d AND track_id = %d AND status = 'submitted'",
            $enrollment_id, $track_id
        ));

        return $submitted >= $total;
    }

    // =========================================================================
    // Teacher Assessment Instrument Queries
    // =========================================================================

    /**
     * Get a teacher assessment instrument by ID.
     *
     * @param int $instrument_id
     * @return HL_Teacher_Assessment_Instrument|null
     */
    public function get_teacher_instrument( $instrument_id ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_teacher_assessment_instrument WHERE instrument_id = %d",
            $instrument_id
        ), ARRAY_A );
        return $row ? new HL_Teacher_Assessment_Instrument( $row ) : null;
    }

    /**
     * Get a teacher assessment instrument by key (latest version).
     *
     * @param string      $key
     * @param string|null $version Specific version, or null for latest.
     * @return HL_Teacher_Assessment_Instrument|null
     */
    public function get_teacher_instrument_by_key( $key, $version = null ) {
        global $wpdb;
        if ( $version ) {
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}hl_teacher_assessment_instrument WHERE instrument_key = %s AND instrument_version = %s LIMIT 1",
                $key, $version
            ), ARRAY_A );
        } else {
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}hl_teacher_assessment_instrument WHERE instrument_key = %s ORDER BY instrument_id DESC LIMIT 1",
                $key
            ), ARRAY_A );
        }
        return $row ? new HL_Teacher_Assessment_Instrument( $row ) : null;
    }

    /**
     * Get all teacher assessment instruments.
     *
     * @return array
     */
    public function get_all_teacher_instruments() {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}hl_teacher_assessment_instrument ORDER BY instrument_name ASC",
            ARRAY_A
        ) ?: array();
    }

    /**
     * Save teacher assessment responses (draft or submit).
     *
     * Stores structured JSON in the responses_json column on the instance row.
     *
     * @param int   $instance_id
     * @param array $responses    Structured responses array (section_key => item_key => value).
     * @param bool  $is_draft     True for draft save, false for final submit.
     * @return true|WP_Error
     */
    public function save_teacher_assessment_responses( $instance_id, $responses, $is_draft = true ) {
        global $wpdb;

        $instance = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_teacher_assessment_instance WHERE instance_id = %d",
            $instance_id
        ), ARRAY_A );

        if ( ! $instance ) {
            return new WP_Error( 'not_found', __( 'Assessment instance not found.', 'hl-core' ) );
        }

        if ( $instance['status'] === 'submitted' ) {
            return new WP_Error( 'already_submitted', __( 'This assessment has already been submitted.', 'hl-core' ) );
        }

        $update_data = array(
            'responses_json' => wp_json_encode( $responses ),
            'status'         => $is_draft ? 'in_progress' : 'submitted',
        );

        if ( ! $is_draft ) {
            $update_data['submitted_at'] = current_time( 'mysql' );
        }

        $wpdb->update(
            $wpdb->prefix . 'hl_teacher_assessment_instance',
            $update_data,
            array( 'instance_id' => $instance_id )
        );

        $action_label = $is_draft ? 'teacher_assessment.draft_saved' : 'teacher_assessment.submitted';
        HL_Audit_Service::log( $action_label, array(
            'entity_type' => 'teacher_assessment_instance',
            'entity_id'   => $instance_id,
            'track_id'   => $instance['track_id'],
        ) );

        if ( ! $is_draft ) {
            $this->update_teacher_assessment_activity_state( $instance );
        }

        return true;
    }

    /**
     * Get PRE responses for pre-filling POST "Before" column.
     *
     * @param int    $enrollment_id
     * @param int    $track_id
     * @return array Decoded responses array, or empty array.
     */
    public function get_pre_responses_for_post( $enrollment_id, $track_id ) {
        global $wpdb;

        $json = $wpdb->get_var( $wpdb->prepare(
            "SELECT responses_json FROM {$wpdb->prefix}hl_teacher_assessment_instance
             WHERE enrollment_id = %d AND track_id = %d AND phase = 'pre' AND status = 'submitted'
             LIMIT 1",
            $enrollment_id, $track_id
        ) );

        if ( $json ) {
            $decoded = json_decode( $json, true );
            return is_array( $decoded ) ? $decoded : array();
        }

        return array();
    }

    /**
     * Update the teacher_self_assessment activity state for an enrollment.
     *
     * Finds matching activities by teacher_instrument_id + phase in external_ref,
     * and marks the activity state as complete.
     *
     * @param array $instance Instance row as associative array.
     */
    private function update_teacher_assessment_activity_state( $instance ) {
        global $wpdb;

        $enrollment_id = absint( $instance['enrollment_id'] );
        $track_id     = absint( $instance['track_id'] );
        $phase         = $instance['phase'];

        // Find teacher_self_assessment activities in this track
        $activities = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.activity_id, a.external_ref FROM {$wpdb->prefix}hl_activity a
             JOIN {$wpdb->prefix}hl_pathway p ON a.pathway_id = p.pathway_id
             WHERE p.track_id = %d
               AND a.activity_type = 'teacher_self_assessment'
               AND a.status = 'active'",
            $track_id
        ) );

        if ( empty( $activities ) ) {
            return;
        }

        $now = current_time( 'mysql' );

        foreach ( $activities as $activity ) {
            $ref = json_decode( $activity->external_ref, true );
            if ( ! is_array( $ref ) ) {
                continue;
            }

            // Match by teacher_instrument_id and phase
            if ( empty( $ref['teacher_instrument_id'] ) ) {
                continue;
            }
            if ( isset( $ref['phase'] ) && $ref['phase'] !== $phase ) {
                continue;
            }

            $existing = $wpdb->get_var( $wpdb->prepare(
                "SELECT state_id FROM {$wpdb->prefix}hl_activity_state
                 WHERE enrollment_id = %d AND activity_id = %d",
                $enrollment_id, $activity->activity_id
            ) );

            $state_data = array(
                'completion_percent' => 100,
                'completion_status'  => 'complete',
                'completed_at'       => $now,
                'last_computed_at'   => $now,
            );

            if ( $existing ) {
                $wpdb->update(
                    $wpdb->prefix . 'hl_activity_state',
                    $state_data,
                    array( 'state_id' => $existing )
                );
            } else {
                $state_data['enrollment_id'] = $enrollment_id;
                $state_data['activity_id']   = $activity->activity_id;
                $wpdb->insert( $wpdb->prefix . 'hl_activity_state', $state_data );
            }
        }

        // Trigger rollup recomputation
        do_action( 'hl_core_recompute_rollups', $enrollment_id );
    }

    // =========================================================================
    // Child Assessment Queries
    // =========================================================================

    /**
     * Get child assessment instances for an enrollment
     */
    public function get_child_assessments($enrollment_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_child_assessment_instance WHERE enrollment_id = %d",
            $enrollment_id
        ), ARRAY_A) ?: array();
    }

    /**
     * Get child assessment instances by track with joined data
     */
    public function get_child_assessments_by_track($track_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT cai.*, e.user_id, u.display_name, u.user_email, cr.classroom_name, o.name AS school_name
             FROM {$wpdb->prefix}hl_child_assessment_instance cai
             JOIN {$wpdb->prefix}hl_enrollment e ON cai.enrollment_id = e.enrollment_id
             LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
             LEFT JOIN {$wpdb->prefix}hl_classroom cr ON cai.classroom_id = cr.classroom_id
             LEFT JOIN {$wpdb->prefix}hl_orgunit o ON cai.school_id = o.orgunit_id
             WHERE cai.track_id = %d
             ORDER BY cai.phase ASC, u.display_name ASC, cr.classroom_name ASC",
            $track_id
        ), ARRAY_A) ?: array();
    }

    /**
     * Get a single child assessment instance by ID
     */
    public function get_child_assessment($instance_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT cai.*, u.display_name, u.user_email, e.user_id, t.track_name,
                    cr.classroom_name, o.name AS school_name
             FROM {$wpdb->prefix}hl_child_assessment_instance cai
             JOIN {$wpdb->prefix}hl_enrollment e ON cai.enrollment_id = e.enrollment_id
             LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
             LEFT JOIN {$wpdb->prefix}hl_track t ON cai.track_id = t.track_id
             LEFT JOIN {$wpdb->prefix}hl_classroom cr ON cai.classroom_id = cr.classroom_id
             LEFT JOIN {$wpdb->prefix}hl_orgunit o ON cai.school_id = o.orgunit_id
             WHERE cai.instance_id = %d",
            $instance_id
        ), ARRAY_A);
    }

    /**
     * Get child rows for a child assessment instance
     */
    public function get_child_assessment_childrows($instance_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT cr.*, ch.first_name, ch.last_name, ch.child_display_code, ch.dob
             FROM {$wpdb->prefix}hl_child_assessment_childrow cr
             JOIN {$wpdb->prefix}hl_child ch ON cr.child_id = ch.child_id
             WHERE cr.instance_id = %d
             ORDER BY ch.last_name ASC, ch.first_name ASC",
            $instance_id
        ), ARRAY_A) ?: array();
    }

    /**
     * Check if all child assessments are complete for an enrollment.
     *
     * @param int         $enrollment_id
     * @param int         $track_id
     * @param string|null $phase Optional phase filter ('pre' or 'post').
     */
    public function is_child_assessment_complete( $enrollment_id, $track_id, $phase = null ) {
        global $wpdb;

        $where = $wpdb->prepare(
            "enrollment_id = %d AND track_id = %d",
            $enrollment_id, $track_id
        );
        if ( $phase ) {
            $where .= $wpdb->prepare( " AND phase = %s", $phase );
        }

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}hl_child_assessment_instance WHERE {$where}"
        );
        if ( $total === 0 ) {
            return true;
        }

        $submitted = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}hl_child_assessment_instance WHERE {$where} AND status = 'submitted'"
        );

        return $submitted >= $total;
    }

    // =========================================================================
    // Child Assessment Submission
    // =========================================================================

    /**
     * Save child assessment child rows (draft or submit)
     *
     * @param int    $instance_id
     * @param array  $childrows  Array of ['child_id' => int, 'answers_json' => array]
     * @param string $action     'draft' or 'submit'
     * @return true|WP_Error
     */
    public function save_child_assessment($instance_id, $childrows, $action = 'draft') {
        global $wpdb;

        $instance = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_child_assessment_instance WHERE instance_id = %d",
            $instance_id
        ), ARRAY_A);

        if (!$instance) {
            return new WP_Error('not_found', __('child assessment instance not found.', 'hl-core'));
        }

        if ($instance['status'] === 'submitted') {
            return new WP_Error('already_submitted', __('This assessment has already been submitted.', 'hl-core'));
        }

        // Upsert child rows
        foreach ($childrows as $row) {
            $child_id = absint($row['child_id']);
            $answers  = is_array($row['answers_json']) ? wp_json_encode($row['answers_json']) : $row['answers_json'];

            $update_data = array('answers_json' => $answers);
            $insert_data = array(
                'instance_id'  => $instance_id,
                'child_id'     => $child_id,
                'answers_json' => $answers,
            );

            // Phase 23: add frozen_age_group, instrument_id, status if provided.
            if ( isset( $row['frozen_age_group'] ) && $row['frozen_age_group'] ) {
                $update_data['frozen_age_group'] = sanitize_text_field( $row['frozen_age_group'] );
                $insert_data['frozen_age_group'] = $update_data['frozen_age_group'];
            }
            if ( isset( $row['instrument_id'] ) && $row['instrument_id'] ) {
                $update_data['instrument_id'] = absint( $row['instrument_id'] );
                $insert_data['instrument_id'] = $update_data['instrument_id'];
            }
            if ( isset( $row['status'] ) && $row['status'] ) {
                $update_data['status'] = sanitize_text_field( $row['status'] );
                $insert_data['status'] = $update_data['status'];
            }
            if ( isset( $row['skip_reason'] ) && $row['skip_reason'] ) {
                $update_data['skip_reason'] = sanitize_text_field( $row['skip_reason'] );
                $insert_data['skip_reason'] = $update_data['skip_reason'];
            }

            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT row_id FROM {$wpdb->prefix}hl_child_assessment_childrow
                 WHERE instance_id = %d AND child_id = %d",
                $instance_id, $child_id
            ));

            if ($existing) {
                $wpdb->update(
                    $wpdb->prefix . 'hl_child_assessment_childrow',
                    $update_data,
                    array('row_id' => $existing)
                );
            } else {
                $wpdb->insert($wpdb->prefix . 'hl_child_assessment_childrow', $insert_data);
            }
        }

        // Update instance status
        $new_status = ($action === 'submit') ? 'submitted' : 'in_progress';
        $update = array('status' => $new_status);
        if ($action === 'submit') {
            $update['submitted_at'] = current_time('mysql');
        }

        $wpdb->update(
            $wpdb->prefix . 'hl_child_assessment_instance',
            $update,
            array('instance_id' => $instance_id)
        );

        if ($action === 'submit') {
            HL_Audit_Service::log('child_assessment.submitted', array(
                'entity_type' => 'child_assessment_instance',
                'entity_id'   => $instance_id,
                'track_id'   => $instance['track_id'],
            ));

            // Update activity state for this phase
            $this->update_child_assessment_activity_state( $instance );
        }

        return true;
    }

    /**
     * Update the child_assessment activity state for an enrollment.
     *
     * Phase-aware: matches activities by external_ref.phase so that Pre and
     * Post child assessments complete independently.
     *
     * @param array $instance Instance row as associative array.
     */
    private function update_child_assessment_activity_state( $instance ) {
        global $wpdb;

        $enrollment_id = absint( $instance['enrollment_id'] );
        $track_id      = absint( $instance['track_id'] );
        $phase         = $instance['phase'];

        // Find child_assessment activities in this track
        $activities = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.activity_id, a.external_ref FROM {$wpdb->prefix}hl_activity a
             JOIN {$wpdb->prefix}hl_pathway p ON a.pathway_id = p.pathway_id
             WHERE p.track_id = %d
               AND a.activity_type = 'child_assessment'
               AND a.status = 'active'",
            $track_id
        ) );

        if ( empty( $activities ) ) {
            return;
        }

        $now = current_time( 'mysql' );

        foreach ( $activities as $activity ) {
            $ref = json_decode( $activity->external_ref, true );
            if ( ! is_array( $ref ) ) {
                continue;
            }

            // Only update the activity whose phase matches this instance
            if ( isset( $ref['phase'] ) && $ref['phase'] !== $phase ) {
                continue;
            }

            $is_complete = $this->is_child_assessment_complete( $enrollment_id, $track_id, $phase );
            $percent     = $is_complete ? 100 : 0;
            $status      = $is_complete ? 'complete' : 'not_started';

            $existing = $wpdb->get_var( $wpdb->prepare(
                "SELECT state_id FROM {$wpdb->prefix}hl_activity_state
                 WHERE enrollment_id = %d AND activity_id = %d",
                $enrollment_id, $activity->activity_id
            ) );

            $state_data = array(
                'completion_percent' => $percent,
                'completion_status'  => $status,
                'completed_at'       => $is_complete ? $now : null,
                'last_computed_at'   => $now,
            );

            if ( $existing ) {
                $wpdb->update(
                    $wpdb->prefix . 'hl_activity_state',
                    $state_data,
                    array( 'state_id' => $existing )
                );
            } else {
                $state_data['enrollment_id'] = $enrollment_id;
                $state_data['activity_id']   = $activity->activity_id;
                $wpdb->insert( $wpdb->prefix . 'hl_activity_state', $state_data );
            }
        }

        // Trigger rollup recomputation
        do_action( 'hl_core_recompute_rollups', $enrollment_id );
    }

    /**
     * Save child assessment responses using the responses_json approach.
     *
     * Used for control group assessments where one instance covers all
     * children across the teacher's classrooms. Stores per-child responses
     * as JSON on the instance rather than in hl_child_assessment_childrow.
     *
     * @param int   $instance_id
     * @param array $responses   Array: { "children": { child_id: { value, age_band, ... }, ... } }
     * @param bool  $is_draft    True = draft save, false = final submit.
     * @return true|WP_Error
     */
    public function save_child_assessment_responses( $instance_id, $responses, $is_draft = true ) {
        global $wpdb;

        $instance = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_child_assessment_instance WHERE instance_id = %d",
            $instance_id
        ), ARRAY_A );

        if ( ! $instance ) {
            return new WP_Error( 'not_found', __( 'child assessment instance not found.', 'hl-core' ) );
        }

        if ( $instance['status'] === 'submitted' ) {
            return new WP_Error( 'already_submitted', __( 'This assessment has already been submitted.', 'hl-core' ) );
        }

        $update_data = array(
            'responses_json' => wp_json_encode( $responses ),
            'status'         => $is_draft ? 'in_progress' : 'submitted',
        );

        if ( ! $is_draft ) {
            $update_data['submitted_at'] = current_time( 'mysql' );
        }

        $wpdb->update(
            $wpdb->prefix . 'hl_child_assessment_instance',
            $update_data,
            array( 'instance_id' => $instance_id )
        );

        $action_label = $is_draft ? 'child_assessment.draft_saved' : 'child_assessment.submitted';
        HL_Audit_Service::log( $action_label, array(
            'entity_type' => 'child_assessment_instance',
            'entity_id'   => $instance_id,
            'track_id'   => $instance['track_id'],
        ) );

        if ( ! $is_draft ) {
            $this->update_child_assessment_activity_state( $instance );
        }

        return true;
    }

    /**
     * Get a child assessment instance by activity_id and enrollment.
     *
     * @param int $activity_id
     * @param int $enrollment_id
     * @return array|null
     */
    public function get_child_assessment_by_activity( $activity_id, $enrollment_id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT cai.*, u.display_name, u.user_email, e.user_id, t.track_name
             FROM {$wpdb->prefix}hl_child_assessment_instance cai
             JOIN {$wpdb->prefix}hl_enrollment e ON cai.enrollment_id = e.enrollment_id
             LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
             LEFT JOIN {$wpdb->prefix}hl_track t ON cai.track_id = t.track_id
             WHERE cai.activity_id = %d AND cai.enrollment_id = %d
             LIMIT 1",
            $activity_id, $enrollment_id
        ), ARRAY_A );
    }

    // =========================================================================
    // Child Assessment Instance Generation
    // =========================================================================

    /**
     * Generate child assessment instances for a track.
     *
     * Canonical rule: For each track, for each classroom with a teaching assignment,
     * for each teacher enrollment assigned to that classroom, ensure one instance exists.
     *
     * @param int $track_id
     * @return array ['created' => int, 'existing' => int, 'errors' => array]
     */
    public function generate_child_assessment_instances($track_id) {
        global $wpdb;

        // Freeze age groups for all children in this track before generating instances.
        HL_Child_Snapshot_Service::freeze_age_groups( $track_id );

        $result = array('created' => 0, 'existing' => 0, 'errors' => array());

        // Get all teaching assignments for this track (join through enrollment)
        $assignments = $wpdb->get_results($wpdb->prepare(
            "SELECT ta.assignment_id, ta.enrollment_id, ta.classroom_id,
                    cr.school_id, cr.age_band, cr.classroom_name
             FROM {$wpdb->prefix}hl_teaching_assignment ta
             JOIN {$wpdb->prefix}hl_enrollment e ON ta.enrollment_id = e.enrollment_id
             JOIN {$wpdb->prefix}hl_classroom cr ON ta.classroom_id = cr.classroom_id
             WHERE e.track_id = %d AND e.status = 'active'",
            $track_id
        ));

        if (empty($assignments)) {
            return $result;
        }

        foreach ($assignments as $ta) {
            // Check if instance already exists
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT instance_id FROM {$wpdb->prefix}hl_child_assessment_instance
                 WHERE track_id = %d AND enrollment_id = %d AND classroom_id = %d",
                $track_id, $ta->enrollment_id, $ta->classroom_id
            ));

            if ($existing) {
                $result['existing']++;
                continue;
            }

            // Determine age band from classroom
            $age_band = $ta->age_band;
            if (empty($age_band)) {
                $age_band = null;
            }

            // Find matching instrument: try exact type, then any children_* instrument.
            // Mixed classrooms skip exact-type lookup (renderer handles per-child instruments).
            $instrument = null;
            if ($age_band) {
                $try_types = array();
                if ($age_band !== 'mixed') {
                    $try_types[] = 'children_' . $age_band;
                }
                foreach ($try_types as $instrument_type) {
                    $instrument = $wpdb->get_row($wpdb->prepare(
                        "SELECT instrument_id, version FROM {$wpdb->prefix}hl_instrument
                         WHERE instrument_type = %s
                         AND (effective_to IS NULL OR effective_to >= CURDATE())
                         ORDER BY effective_from DESC LIMIT 1",
                        $instrument_type
                    ));
                    if ($instrument) break;
                }
                // Final fallback: any active children instrument.
                if (!$instrument) {
                    $instrument = $wpdb->get_row(
                        "SELECT instrument_id, version FROM {$wpdb->prefix}hl_instrument
                         WHERE instrument_type LIKE 'children_%'
                         AND (effective_to IS NULL OR effective_to >= CURDATE())
                         ORDER BY effective_from DESC LIMIT 1"
                    );
                }
            }

            $insert_data = array(
                'instance_uuid'      => HL_DB_Utils::generate_uuid(),
                'track_id'          => absint($track_id),
                'enrollment_id'      => absint($ta->enrollment_id),
                'classroom_id'       => absint($ta->classroom_id),
                'school_id'          => absint($ta->school_id),
                'instrument_age_band' => $age_band,
                'instrument_id'      => $instrument ? $instrument->instrument_id : null,
                'instrument_version' => $instrument ? $instrument->version : null,
                'status'             => 'not_started',
            );

            $insert_result = $wpdb->insert($wpdb->prefix . 'hl_child_assessment_instance', $insert_data);

            if ($insert_result === false) {
                $result['errors'][] = sprintf(
                    __('Failed to create instance for enrollment %d in classroom %s.', 'hl-core'),
                    $ta->enrollment_id,
                    $ta->classroom_name
                );
            } else {
                $result['created']++;
            }
        }

        if ($result['created'] > 0) {
            HL_Audit_Service::log('child_assessment.instances_generated', array(
                'entity_type' => 'child_assessment_instance',
                'track_id'   => $track_id,
                'after_data'  => array(
                    'created'  => $result['created'],
                    'existing' => $result['existing'],
                ),
            ));
        }

        return $result;
    }

    // =========================================================================
    // CSV Export
    // =========================================================================

    /**
     * Export teacher assessment data as CSV
     *
     * @param int $track_id
     * @return string CSV content
     */
    public function export_teacher_assessments_csv($track_id) {
        global $wpdb;

        $instances = $this->get_teacher_assessments_by_track($track_id);

        $track = $wpdb->get_var($wpdb->prepare(
            "SELECT track_name FROM {$wpdb->prefix}hl_track WHERE track_id = %d",
            $track_id
        ));

        $output = fopen('php://temp', 'r+');

        // Collect all unique question IDs across all instances
        $all_question_ids = array();
        $instance_responses = array();
        foreach ($instances as $inst) {
            $responses = $this->get_teacher_assessment_responses($inst['instance_id']);
            $instance_responses[$inst['instance_id']] = $responses;
            foreach ($responses as $r) {
                if (!in_array($r['question_id'], $all_question_ids)) {
                    $all_question_ids[] = $r['question_id'];
                }
            }
        }
        sort($all_question_ids);

        // Header row
        $header = array('Instance ID', 'Teacher Name', 'Email', 'Phase', 'Status', 'Submitted At');
        foreach ($all_question_ids as $qid) {
            $header[] = $qid;
        }
        fputcsv($output, $header);

        // Data rows
        foreach ($instances as $inst) {
            $row = array(
                $inst['instance_id'],
                $inst['display_name'],
                $inst['user_email'],
                $inst['phase'],
                $inst['status'],
                $inst['submitted_at'] ?: '',
            );

            // Map responses by question_id
            $resp_map = array();
            if (isset($instance_responses[$inst['instance_id']])) {
                foreach ($instance_responses[$inst['instance_id']] as $r) {
                    $resp_map[$r['question_id']] = $r['value'];
                }
            }

            foreach ($all_question_ids as $qid) {
                $row[] = isset($resp_map[$qid]) ? $resp_map[$qid] : '';
            }

            fputcsv($output, $row);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    /**
     * Export teacher assessment response-level data as CSV.
     *
     * Reads responses_json from each instance and flattens into one column
     * per instrument item (e.g., P1, P2, …, W1-W4, SR1-SR4).
     *
     * @param int $track_id
     * @return string CSV content
     */
    public function export_teacher_assessment_responses_csv( $track_id ) {
        $instances = $this->get_teacher_assessments_by_track( $track_id );

        // Collect unique instrument_ids and load their sections.
        $instrument_ids = array();
        foreach ( $instances as $inst ) {
            if ( ! empty( $inst['instrument_id'] ) && ! in_array( (int) $inst['instrument_id'], $instrument_ids, true ) ) {
                $instrument_ids[] = (int) $inst['instrument_id'];
            }
        }

        // Build flat column list: [ ['header' => 'Practices: P1', 'section_key' => 'practices', 'item_key' => 'P1'], … ]
        $columns = array();
        foreach ( $instrument_ids as $iid ) {
            $instrument = $this->get_teacher_instrument( $iid );
            if ( ! $instrument ) {
                continue;
            }
            foreach ( $instrument->get_sections() as $section ) {
                $section_key   = isset( $section['section_key'] ) ? $section['section_key'] : '';
                $section_title = isset( $section['title'] ) ? $section['title'] : $section_key;
                // Strip "Assessment N/N — " prefix if present for cleaner headers.
                $section_title = preg_replace( '/^Assessment\s+\d+\/\d+\s*[—–-]\s*/u', '', $section_title );
                $items = isset( $section['items'] ) ? $section['items'] : array();
                foreach ( $items as $item ) {
                    $item_key = isset( $item['key'] ) ? $item['key'] : '';
                    // Avoid duplicate columns (if multiple instruments share keys).
                    $col_id = $section_key . '::' . $item_key;
                    $existing_ids = array_map( function ( $c ) { return $c['col_id']; }, $columns );
                    if ( in_array( $col_id, $existing_ids, true ) ) {
                        continue;
                    }
                    $columns[] = array(
                        'col_id'      => $col_id,
                        'header'      => $section_title . ': ' . $item_key,
                        'section_key' => $section_key,
                        'item_key'    => $item_key,
                    );
                }
            }
        }

        $output = fopen( 'php://temp', 'r+' );

        // Header row.
        $header = array( 'Teacher Name', 'Email', 'Phase', 'Status', 'Submitted At' );
        foreach ( $columns as $col ) {
            $header[] = $col['header'];
        }
        fputcsv( $output, $header );

        // Data rows.
        foreach ( $instances as $inst ) {
            // Skip instances with no data.
            if ( $inst['status'] === 'not_started' && empty( $inst['responses_json'] ) ) {
                continue;
            }

            $responses = array();
            if ( ! empty( $inst['responses_json'] ) ) {
                $decoded = json_decode( $inst['responses_json'], true );
                if ( is_array( $decoded ) ) {
                    $responses = $decoded;
                }
            }

            $row = array(
                $inst['display_name'],
                $inst['user_email'],
                $inst['phase'],
                $inst['status'],
                $inst['submitted_at'] ?: '',
            );

            foreach ( $columns as $col ) {
                $val = '';
                if ( isset( $responses[ $col['section_key'] ][ $col['item_key'] ] ) ) {
                    $val = $responses[ $col['section_key'] ][ $col['item_key'] ];
                }
                $row[] = is_array( $val ) ? wp_json_encode( $val ) : $val;
            }

            fputcsv( $output, $row );
        }

        rewind( $output );
        $csv = stream_get_contents( $output );
        fclose( $output );

        return $csv;
    }

    /**
     * Export child assessment data as CSV
     *
     * @param int $track_id
     * @return string CSV content
     */
    public function export_child_assessments_csv($track_id) {
        global $wpdb;

        $instances = $this->get_child_assessments_by_track($track_id);

        // Collect all unique question IDs from answers_json across all child rows
        $all_question_ids = array();
        $instance_childrows = array();
        foreach ($instances as $inst) {
            $childrows = $this->get_child_assessment_childrows($inst['instance_id']);
            $instance_childrows[$inst['instance_id']] = $childrows;
            foreach ($childrows as $cr) {
                $answers = json_decode($cr['answers_json'], true);
                if (is_array($answers)) {
                    foreach (array_keys($answers) as $qid) {
                        if (!in_array($qid, $all_question_ids)) {
                            $all_question_ids[] = $qid;
                        }
                    }
                }
            }
        }
        sort($all_question_ids);

        // Build instrument name lookup for childrow instrument_ids
        $instrument_names = array();
        $instrument_ids_seen = array();
        foreach ($instance_childrows as $crs) {
            foreach ($crs as $cr) {
                if (!empty($cr['instrument_id']) && !in_array((int) $cr['instrument_id'], $instrument_ids_seen)) {
                    $instrument_ids_seen[] = (int) $cr['instrument_id'];
                }
            }
        }
        if (!empty($instrument_ids_seen)) {
            $ids_in = implode(',', $instrument_ids_seen);
            $instr_rows = $wpdb->get_results(
                "SELECT instrument_id, name FROM {$wpdb->prefix}hl_instrument WHERE instrument_id IN ({$ids_in})"
            );
            foreach ($instr_rows as $ir) {
                $instrument_names[(int) $ir->instrument_id] = $ir->name;
            }
        }

        $output = fopen('php://temp', 'r+');

        // Header
        $header = array('Instance ID', 'Teacher Name', 'Classroom', 'School', 'Age Band', 'Instance Status', 'Child Name', 'Child Code', 'DOB', 'Frozen Age Group', 'Child Status', 'Instrument');
        foreach ($all_question_ids as $qid) {
            $header[] = $qid;
        }
        fputcsv($output, $header);

        // Data rows — one row per child per instance
        foreach ($instances as $inst) {
            $childrows = isset($instance_childrows[$inst['instance_id']]) ? $instance_childrows[$inst['instance_id']] : array();

            if (empty($childrows)) {
                // Write instance row with no child data
                $row = array(
                    $inst['instance_id'],
                    $inst['display_name'],
                    $inst['classroom_name'],
                    $inst['school_name'],
                    $inst['instrument_age_band'] ?: 'N/A',
                    $inst['status'],
                    '', '', '', '', '', '',
                );
                foreach ($all_question_ids as $qid) {
                    $row[] = '';
                }
                fputcsv($output, $row);
                continue;
            }

            foreach ($childrows as $cr) {
                $answers = json_decode($cr['answers_json'], true) ?: array();
                $cr_frozen   = isset($cr['frozen_age_group']) ? $cr['frozen_age_group'] : '';
                $cr_status   = isset($cr['status']) ? $cr['status'] : 'active';
                $cr_instr_id = isset($cr['instrument_id']) ? (int) $cr['instrument_id'] : 0;
                $cr_instr_name = $cr_instr_id && isset($instrument_names[$cr_instr_id]) ? $instrument_names[$cr_instr_id] : '';

                $row = array(
                    $inst['instance_id'],
                    $inst['display_name'],
                    $inst['classroom_name'],
                    $inst['school_name'],
                    $inst['instrument_age_band'] ?: 'N/A',
                    $inst['status'],
                    trim($cr['first_name'] . ' ' . $cr['last_name']),
                    $cr['child_display_code'] ?: '',
                    $cr['dob'] ?: '',
                    $cr_frozen,
                    $cr_status,
                    $cr_instr_name,
                );

                foreach ($all_question_ids as $qid) {
                    $val = isset($answers[$qid]) ? $answers[$qid] : '';
                    $row[] = is_array($val) ? wp_json_encode($val) : $val;
                }

                fputcsv($output, $row);
            }
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    /**
     * Export child assessment response-level data as CSV.
     *
     * One row per child per instance. Skipped children are excluded.
     * Includes teacher email and submitted_at.
     *
     * @param int $track_id
     * @return string CSV content
     */
    public function export_child_assessment_responses_csv( $track_id ) {
        global $wpdb;

        $instances = $this->get_child_assessments_by_track( $track_id );

        // Collect all unique question IDs and childrows.
        $all_question_ids     = array();
        $instance_childrows   = array();
        foreach ( $instances as $inst ) {
            $childrows = $this->get_child_assessment_childrows( $inst['instance_id'] );
            $instance_childrows[ $inst['instance_id'] ] = $childrows;
            foreach ( $childrows as $cr ) {
                $answers = json_decode( $cr['answers_json'], true );
                if ( is_array( $answers ) ) {
                    foreach ( array_keys( $answers ) as $qid ) {
                        if ( ! in_array( $qid, $all_question_ids, true ) ) {
                            $all_question_ids[] = $qid;
                        }
                    }
                }
            }
        }
        sort( $all_question_ids );

        // Build instrument name lookup.
        $instrument_names   = array();
        $instrument_ids_seen = array();
        foreach ( $instance_childrows as $crs ) {
            foreach ( $crs as $cr ) {
                if ( ! empty( $cr['instrument_id'] ) && ! in_array( (int) $cr['instrument_id'], $instrument_ids_seen, true ) ) {
                    $instrument_ids_seen[] = (int) $cr['instrument_id'];
                }
            }
        }
        if ( ! empty( $instrument_ids_seen ) ) {
            $ids_in     = implode( ',', $instrument_ids_seen );
            $instr_rows = $wpdb->get_results(
                "SELECT instrument_id, name FROM {$wpdb->prefix}hl_instrument WHERE instrument_id IN ({$ids_in})"
            );
            foreach ( $instr_rows as $ir ) {
                $instrument_names[ (int) $ir->instrument_id ] = $ir->name;
            }
        }

        $output = fopen( 'php://temp', 'r+' );

        // Header.
        $header = array(
            'Teacher Name', 'Email', 'Classroom', 'School', 'Phase', 'Status', 'Submitted At',
            'Child Name', 'DOB', 'Frozen Age Group', 'Instrument',
        );
        foreach ( $all_question_ids as $qid ) {
            $header[] = $qid;
        }
        fputcsv( $output, $header );

        // Data rows — one row per active/assessed child per instance.
        foreach ( $instances as $inst ) {
            $childrows = isset( $instance_childrows[ $inst['instance_id'] ] ) ? $instance_childrows[ $inst['instance_id'] ] : array();

            foreach ( $childrows as $cr ) {
                $cr_status = isset( $cr['status'] ) ? $cr['status'] : 'active';

                // Skip children that were skipped by the teacher.
                if ( $cr_status === 'skipped' ) {
                    continue;
                }

                $answers       = json_decode( $cr['answers_json'], true ) ?: array();
                $cr_frozen     = isset( $cr['frozen_age_group'] ) ? $cr['frozen_age_group'] : '';
                $cr_instr_id   = isset( $cr['instrument_id'] ) ? (int) $cr['instrument_id'] : 0;
                $cr_instr_name = $cr_instr_id && isset( $instrument_names[ $cr_instr_id ] ) ? $instrument_names[ $cr_instr_id ] : '';

                $row = array(
                    $inst['display_name'],
                    $inst['user_email'],
                    $inst['classroom_name'],
                    $inst['school_name'],
                    isset( $inst['phase'] ) ? $inst['phase'] : '',
                    $inst['status'],
                    $inst['submitted_at'] ?: '',
                    trim( $cr['first_name'] . ' ' . $cr['last_name'] ),
                    $cr['dob'] ?: '',
                    $cr_frozen,
                    $cr_instr_name,
                );

                foreach ( $all_question_ids as $qid ) {
                    $val = isset( $answers[ $qid ] ) ? $answers[ $qid ] : '';
                    $row[] = is_array( $val ) ? wp_json_encode( $val ) : $val;
                }

                fputcsv( $output, $row );
            }
        }

        rewind( $output );
        $csv = stream_get_contents( $output );
        fclose( $output );

        return $csv;
    }
}
