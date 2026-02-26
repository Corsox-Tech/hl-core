<?php
if (!defined('ABSPATH')) exit;

class HL_LearnDash_Integration {

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if (!$this->is_active()) {
            return;
        }

        add_action('learndash_course_completed', array($this, 'on_course_completed'), 10, 1);
    }

    /**
     * Check if LearnDash is active
     */
    public function is_active() {
        return defined('LEARNDASH_VERSION') || function_exists('learndash_get_course_progress');
    }

    /**
     * Get course completion percentage for a user
     */
    public function get_course_progress_percent($user_id, $course_id) {
        if (!$this->is_active()) {
            return 0;
        }

        if (function_exists('learndash_course_progress')) {
            $progress = learndash_course_progress(array(
                'user_id'   => $user_id,
                'course_id' => $course_id,
                'array'     => true,
            ));
            if (is_array($progress) && isset($progress['percentage'])) {
                return intval($progress['percentage']);
            }
        }

        return 0;
    }

    /**
     * Check if course is completed
     */
    public function is_course_completed($user_id, $course_id) {
        if (!$this->is_active()) {
            return false;
        }

        if (function_exists('learndash_course_completed')) {
            return learndash_course_completed($user_id, $course_id);
        }

        return $this->get_course_progress_percent($user_id, $course_id) >= 100;
    }

    /**
     * Handle LearnDash course completion event
     *
     * Finds all HL Core enrollments for the user, checks if any have
     * learndash_course activities matching the completed course_id,
     * marks those activities complete, and triggers rollup recomputation.
     */
    public function on_course_completed($data) {
        if (!is_array($data) || empty($data['user']) || empty($data['course'])) {
            return;
        }

        $user_id   = is_object($data['user'])   ? $data['user']->ID   : $data['user'];
        $course_id = is_object($data['course'])  ? $data['course']->ID : $data['course'];

        global $wpdb;
        $now = current_time('mysql');

        // Find all active enrollments for this user
        $enrollments = $wpdb->get_results($wpdb->prepare(
            "SELECT enrollment_id, track_id, assigned_pathway_id
             FROM {$wpdb->prefix}hl_enrollment
             WHERE user_id = %d AND status = 'active'",
            $user_id
        ));

        if (empty($enrollments)) {
            return;
        }

        $enrollment_ids = wp_list_pluck($enrollments, 'enrollment_id');

        // Find all learndash_course activities across all tracks these enrollments belong to
        $track_ids = array_unique(wp_list_pluck($enrollments, 'track_id'));
        $track_placeholders = implode(',', array_fill(0, count($track_ids), '%d'));

        $activities = $wpdb->get_results($wpdb->prepare(
            "SELECT activity_id, track_id, pathway_id, external_ref
             FROM {$wpdb->prefix}hl_activity
             WHERE activity_type = 'learndash_course'
               AND track_id IN ($track_placeholders)
               AND status = 'active'",
            $track_ids
        ));

        if (empty($activities)) {
            return;
        }

        // Filter to activities that reference this specific course_id
        $matching_activities = array();
        foreach ($activities as $activity) {
            if (empty($activity->external_ref)) {
                continue;
            }
            $ref = json_decode($activity->external_ref, true);
            if (is_array($ref) && isset($ref['course_id']) && absint($ref['course_id']) === absint($course_id)) {
                $matching_activities[] = $activity;
            }
        }

        if (empty($matching_activities)) {
            return;
        }

        // Build a lookup: track_id => enrollment_id
        $track_enrollment_map = array();
        foreach ($enrollments as $enrollment) {
            $track_enrollment_map[$enrollment->track_id] = $enrollment->enrollment_id;
        }

        // For each matching activity, mark the corresponding enrollment's activity_state as complete
        $updated_enrollment_ids = array();
        foreach ($matching_activities as $activity) {
            $enrollment_id = isset($track_enrollment_map[$activity->track_id])
                ? $track_enrollment_map[$activity->track_id]
                : 0;

            if (!$enrollment_id) {
                continue;
            }

            // Upsert activity state
            $existing_state_id = $wpdb->get_var($wpdb->prepare(
                "SELECT state_id FROM {$wpdb->prefix}hl_activity_state
                 WHERE enrollment_id = %d AND activity_id = %d",
                $enrollment_id, $activity->activity_id
            ));

            $state_data = array(
                'completion_percent' => 100,
                'completion_status'  => 'complete',
                'completed_at'       => $now,
                'last_computed_at'   => $now,
            );

            if ($existing_state_id) {
                $wpdb->update(
                    $wpdb->prefix . 'hl_activity_state',
                    $state_data,
                    array('state_id' => $existing_state_id)
                );
            } else {
                $state_data['enrollment_id'] = $enrollment_id;
                $state_data['activity_id']   = $activity->activity_id;
                $wpdb->insert($wpdb->prefix . 'hl_activity_state', $state_data);
            }

            $updated_enrollment_ids[] = $enrollment_id;

            HL_Audit_Service::log('learndash_course.completed', array(
                'entity_type' => 'activity',
                'entity_id'   => $activity->activity_id,
                'track_id'    => $activity->track_id,
                'after_data'  => array(
                    'user_id'       => $user_id,
                    'course_id'     => $course_id,
                    'enrollment_id' => $enrollment_id,
                ),
            ));
        }

        // Trigger rollup recomputation for each affected enrollment
        $updated_enrollment_ids = array_unique($updated_enrollment_ids);
        foreach ($updated_enrollment_ids as $eid) {
            do_action('hl_core_recompute_rollups', $eid);
        }

        do_action('hl_learndash_course_completed', $user_id, $course_id);
    }

    /**
     * Batch get course progress for multiple users
     */
    public function batch_get_progress($user_ids, $course_id) {
        $results = array();
        foreach ($user_ids as $user_id) {
            $results[$user_id] = $this->get_course_progress_percent($user_id, $course_id);
        }
        return $results;
    }
}
