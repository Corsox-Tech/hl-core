<?php
if (!defined('ABSPATH')) exit;

/**
 * Observation Service
 *
 * Business logic for mentor observations: querying, creating, and
 * retrieving observable teachers from team memberships.
 *
 * Observations are JFB-powered: HL Core handles the context/orchestration
 * (who observed whom, in which classroom/track) while JetFormBuilder
 * handles the form design, rendering, and response storage.
 *
 * @package HL_Core
 */
class HL_Observation_Service {

    // =========================================================================
    // Single Observation (with joined data)
    // =========================================================================

    /**
     * Get a single observation by ID with joined data.
     *
     * Joins enrollments, users, classrooms, and tracks to provide
     * mentor_name, teacher_name, classroom_name, and track_name.
     *
     * @param int $observation_id
     * @return array|null Observation row with joined fields, or null if not found.
     */
    public function get_observation( $observation_id ) {
        global $wpdb;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT o.*,
                    t.track_name,
                    cr.classroom_name,
                    mentor_u.display_name AS mentor_name,
                    mentor_u.user_email   AS mentor_email,
                    mentor_e.user_id      AS mentor_user_id,
                    mentor_e.track_id    AS mentor_track_id,
                    teacher_u.display_name AS teacher_name,
                    teacher_u.user_email   AS teacher_email,
                    teacher_e.user_id      AS teacher_user_id
             FROM {$wpdb->prefix}hl_observation o
             JOIN {$wpdb->prefix}hl_enrollment mentor_e ON o.mentor_enrollment_id = mentor_e.enrollment_id
             LEFT JOIN {$wpdb->users} mentor_u ON mentor_e.user_id = mentor_u.ID
             LEFT JOIN {$wpdb->prefix}hl_enrollment teacher_e ON o.teacher_enrollment_id = teacher_e.enrollment_id
             LEFT JOIN {$wpdb->users} teacher_u ON teacher_e.user_id = teacher_u.ID
             LEFT JOIN {$wpdb->prefix}hl_track t ON o.track_id = t.track_id
             LEFT JOIN {$wpdb->prefix}hl_classroom cr ON o.classroom_id = cr.classroom_id
             WHERE o.observation_id = %d",
            $observation_id
        ), ARRAY_A );

        return $row ?: null;
    }

    // =========================================================================
    // List Queries (with joined data)
    // =========================================================================

    /**
     * Get observations by track with full joined data.
     *
     * Enhanced version that includes teacher name, mentor name, and
     * classroom name for display in list views.
     *
     * @param int $track_id
     * @return array Array of observation rows (ARRAY_A).
     */
    public function get_by_track( $track_id ) {
        global $wpdb;

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT o.*,
                    mentor_u.display_name AS mentor_name,
                    teacher_u.display_name AS teacher_name,
                    cr.classroom_name,
                    t.track_name
             FROM {$wpdb->prefix}hl_observation o
             JOIN {$wpdb->prefix}hl_enrollment mentor_e ON o.mentor_enrollment_id = mentor_e.enrollment_id
             LEFT JOIN {$wpdb->users} mentor_u ON mentor_e.user_id = mentor_u.ID
             LEFT JOIN {$wpdb->prefix}hl_enrollment teacher_e ON o.teacher_enrollment_id = teacher_e.enrollment_id
             LEFT JOIN {$wpdb->users} teacher_u ON teacher_e.user_id = teacher_u.ID
             LEFT JOIN {$wpdb->prefix}hl_track t ON o.track_id = t.track_id
             LEFT JOIN {$wpdb->prefix}hl_classroom cr ON o.classroom_id = cr.classroom_id
             WHERE o.track_id = %d
             ORDER BY o.created_at DESC",
            $track_id
        ), ARRAY_A ) ?: array();
    }

    /**
     * Get observations by mentor enrollment with joined data.
     *
     * Returns observations created by a specific mentor, enriched with
     * teacher name, classroom name, and track name.
     *
     * @param int $mentor_enrollment_id
     * @return array Array of observation rows (ARRAY_A).
     */
    public function get_by_mentor( $mentor_enrollment_id ) {
        global $wpdb;

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT o.*,
                    teacher_u.display_name AS teacher_name,
                    cr.classroom_name,
                    t.track_name
             FROM {$wpdb->prefix}hl_observation o
             LEFT JOIN {$wpdb->prefix}hl_enrollment teacher_e ON o.teacher_enrollment_id = teacher_e.enrollment_id
             LEFT JOIN {$wpdb->users} teacher_u ON teacher_e.user_id = teacher_u.ID
             LEFT JOIN {$wpdb->prefix}hl_track t ON o.track_id = t.track_id
             LEFT JOIN {$wpdb->prefix}hl_classroom cr ON o.classroom_id = cr.classroom_id
             WHERE o.mentor_enrollment_id = %d
             ORDER BY o.created_at DESC",
            $mentor_enrollment_id
        ), ARRAY_A ) ?: array();
    }

    /**
     * Get all observations for a given mentor user across all tracks.
     *
     * Finds all mentor enrollments for the user and returns observations
     * from all of them, ordered by most recent first.
     *
     * @param int $user_id WordPress user ID.
     * @return array Array of observation rows (ARRAY_A).
     */
    public function get_by_mentor_user( $user_id ) {
        global $wpdb;

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT o.*,
                    teacher_u.display_name AS teacher_name,
                    cr.classroom_name,
                    t.track_name,
                    mentor_e.enrollment_id AS mentor_enrollment_id
             FROM {$wpdb->prefix}hl_observation o
             JOIN {$wpdb->prefix}hl_enrollment mentor_e ON o.mentor_enrollment_id = mentor_e.enrollment_id
             LEFT JOIN {$wpdb->prefix}hl_enrollment teacher_e ON o.teacher_enrollment_id = teacher_e.enrollment_id
             LEFT JOIN {$wpdb->users} teacher_u ON teacher_e.user_id = teacher_u.ID
             LEFT JOIN {$wpdb->prefix}hl_track t ON o.track_id = t.track_id
             LEFT JOIN {$wpdb->prefix}hl_classroom cr ON o.classroom_id = cr.classroom_id
             WHERE mentor_e.user_id = %d AND mentor_e.status = 'active'
             ORDER BY o.created_at DESC",
            $user_id
        ), ARRAY_A ) ?: array();
    }

    // =========================================================================
    // Create Observation
    // =========================================================================

    /**
     * Create a new observation record (draft status).
     *
     * Called when a mentor starts a new observation. Validates required
     * fields, generates a UUID, inserts with status='draft', and logs
     * the action to the audit trail.
     *
     * @param array $data Keys: track_id (required), mentor_enrollment_id (required),
     *                     teacher_enrollment_id (required), classroom_id (optional),
     *                     school_id (optional).
     * @return int|WP_Error observation_id on success, WP_Error on failure.
     */
    public function create_observation( $data ) {
        global $wpdb;

        // Validate required fields
        if ( empty( $data['track_id'] ) ) {
            return new WP_Error( 'missing_track', __( 'Track is required.', 'hl-core' ) );
        }
        if ( empty( $data['mentor_enrollment_id'] ) ) {
            return new WP_Error( 'missing_mentor', __( 'Mentor enrollment is required.', 'hl-core' ) );
        }
        if ( empty( $data['teacher_enrollment_id'] ) ) {
            return new WP_Error( 'missing_teacher', __( 'Teacher selection is required.', 'hl-core' ) );
        }

        // Verify the mentor enrollment exists and belongs to the track
        $mentor_enrollment = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_enrollment WHERE enrollment_id = %d AND status = 'active'",
            absint( $data['mentor_enrollment_id'] )
        ) );

        if ( ! $mentor_enrollment ) {
            return new WP_Error( 'invalid_mentor', __( 'Invalid mentor enrollment.', 'hl-core' ) );
        }

        if ( (int) $mentor_enrollment->track_id !== absint( $data['track_id'] ) ) {
            return new WP_Error( 'track_mismatch', __( 'Mentor enrollment does not belong to the specified track.', 'hl-core' ) );
        }

        // Verify the teacher enrollment exists
        $teacher_enrollment = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_enrollment WHERE enrollment_id = %d AND status = 'active'",
            absint( $data['teacher_enrollment_id'] )
        ) );

        if ( ! $teacher_enrollment ) {
            return new WP_Error( 'invalid_teacher', __( 'Invalid teacher enrollment.', 'hl-core' ) );
        }

        // Build the insert data
        $insert_data = array(
            'observation_uuid'      => HL_DB_Utils::generate_uuid(),
            'track_id'             => absint( $data['track_id'] ),
            'mentor_enrollment_id'  => absint( $data['mentor_enrollment_id'] ),
            'teacher_enrollment_id' => absint( $data['teacher_enrollment_id'] ),
            'classroom_id'          => ! empty( $data['classroom_id'] ) ? absint( $data['classroom_id'] ) : null,
            'school_id'             => ! empty( $data['school_id'] ) ? absint( $data['school_id'] ) : null,
            'status'                => 'draft',
        );

        $result = $wpdb->insert( $wpdb->prefix . 'hl_observation', $insert_data );

        if ( $result === false ) {
            return new WP_Error( 'db_error', __( 'Failed to create observation record.', 'hl-core' ) );
        }

        $observation_id = $wpdb->insert_id;

        // Audit log
        HL_Audit_Service::log( 'observation.created', array(
            'entity_type' => 'observation',
            'entity_id'   => $observation_id,
            'track_id'   => absint( $data['track_id'] ),
            'after_data'  => $insert_data,
        ) );

        return $observation_id;
    }

    // =========================================================================
    // Observable Teachers (Team Members)
    // =========================================================================

    /**
     * Get team members that the mentor can observe.
     *
     * Finds teams the mentor belongs to (as a mentor), then returns all
     * non-mentor members from those teams. These are the teachers the
     * mentor is responsible for observing.
     *
     * @param int $mentor_enrollment_id Mentor's enrollment ID.
     * @return array Array of associative arrays with keys: enrollment_id,
     *               user_id, display_name, user_email, team_id, team_name.
     */
    public function get_observable_teachers( $mentor_enrollment_id ) {
        global $wpdb;

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT
                    member_tm.enrollment_id,
                    member_e.user_id,
                    u.display_name,
                    u.user_email,
                    t.team_id,
                    t.team_name
             FROM {$wpdb->prefix}hl_team_membership mentor_tm
             JOIN {$wpdb->prefix}hl_team t ON mentor_tm.team_id = t.team_id
             JOIN {$wpdb->prefix}hl_team_membership member_tm ON t.team_id = member_tm.team_id
             JOIN {$wpdb->prefix}hl_enrollment member_e ON member_tm.enrollment_id = member_e.enrollment_id
             LEFT JOIN {$wpdb->users} u ON member_e.user_id = u.ID
             WHERE mentor_tm.enrollment_id = %d
               AND mentor_tm.membership_type = 'mentor'
               AND member_tm.membership_type != 'mentor'
               AND member_e.status = 'active'
             ORDER BY u.display_name ASC",
            $mentor_enrollment_id
        ), ARRAY_A ) ?: array();
    }

    /**
     * Get the classrooms a teacher is assigned to within a specific track.
     *
     * This is used when creating an observation so the mentor can optionally
     * select which classroom the observation takes place in.
     *
     * @param int $teacher_enrollment_id Teacher's enrollment ID.
     * @return array Array of classroom objects with classroom_id, classroom_name, school_id.
     */
    public function get_teacher_classrooms( $teacher_enrollment_id ) {
        global $wpdb;

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT ta.classroom_id, cr.classroom_name, cr.school_id
             FROM {$wpdb->prefix}hl_teaching_assignment ta
             JOIN {$wpdb->prefix}hl_classroom cr ON ta.classroom_id = cr.classroom_id
             WHERE ta.enrollment_id = %d
             ORDER BY cr.classroom_name ASC",
            $teacher_enrollment_id
        ), ARRAY_A ) ?: array();
    }

    // =========================================================================
    // Mentor Enrollment Helpers
    // =========================================================================

    /**
     * Get all active mentor enrollments for a user.
     *
     * Returns enrollment rows where the user has a 'Mentor' role and
     * the enrollment is active, with track name included.
     *
     * @param int $user_id WordPress user ID.
     * @return array Array of enrollment rows (ARRAY_A) with track_name.
     */
    public function get_mentor_enrollments( $user_id ) {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.*, t.track_name
             FROM {$wpdb->prefix}hl_enrollment e
             JOIN {$wpdb->prefix}hl_track t ON e.track_id = t.track_id
             WHERE e.user_id = %d AND e.status = 'active'
             ORDER BY t.track_name ASC",
            $user_id
        ), ARRAY_A ) ?: array();

        // Filter to only those with the Mentor role
        return array_filter( $rows, function ( $row ) {
            $roles = HL_DB_Utils::json_decode( $row['roles'] );
            return is_array( $roles ) && in_array( 'Mentor', $roles, true );
        } );
    }

    /**
     * Check if a user owns a specific mentor enrollment.
     *
     * @param int $user_id       WordPress user ID.
     * @param int $enrollment_id Enrollment ID to verify.
     * @return bool True if the enrollment belongs to the user.
     */
    public function user_owns_enrollment( $user_id, $enrollment_id ) {
        global $wpdb;

        $owner_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT user_id FROM {$wpdb->prefix}hl_enrollment WHERE enrollment_id = %d",
            $enrollment_id
        ) );

        return $owner_id !== null && (int) $owner_id === (int) $user_id;
    }

    // =========================================================================
    // Activity Lookup
    // =========================================================================

    /**
     * Find the observation activity for a given track.
     *
     * Queries for an active observation-type activity in the track's
     * pathway. Used to pre-populate the hl_activity_id hidden field
     * when rendering the JFB form.
     *
     * @param int $track_id
     * @return array|null Activity row or null if none found.
     */
    public function get_observation_activity( $track_id ) {
        global $wpdb;

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT a.*
             FROM {$wpdb->prefix}hl_activity a
             JOIN {$wpdb->prefix}hl_pathway p ON a.pathway_id = p.pathway_id
             WHERE p.track_id = %d AND a.activity_type = 'observation' AND a.status = 'active'
             LIMIT 1",
            $track_id
        ), ARRAY_A );
    }

    /**
     * Get the JFB form ID from the observation activity's external_ref.
     *
     * @param int $track_id
     * @return int|null JFB form ID, or null if not configured.
     */
    public function get_observation_form_id( $track_id ) {
        $activity = $this->get_observation_activity( $track_id );

        if ( ! $activity || empty( $activity['external_ref'] ) ) {
            return null;
        }

        $ref = HL_DB_Utils::json_decode( $activity['external_ref'] );

        if ( ! empty( $ref['form_id'] ) ) {
            return absint( $ref['form_id'] );
        }

        return null;
    }
}
