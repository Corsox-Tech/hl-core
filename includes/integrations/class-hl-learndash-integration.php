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
     * learndash_course components matching the completed course_id,
     * marks those components complete, and triggers rollup recomputation.
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
            "SELECT enrollment_id, cycle_id, assigned_pathway_id
             FROM {$wpdb->prefix}hl_enrollment
             WHERE user_id = %d AND status = 'active'",
            $user_id
        ));

        if (empty($enrollments)) {
            return;
        }

        $enrollment_ids = wp_list_pluck($enrollments, 'enrollment_id');

        // Find all learndash_course components across all tracks these enrollments belong to
        $cycle_ids = array_unique(wp_list_pluck($enrollments, 'cycle_id'));
        $cycle_placeholders = implode(',', array_fill(0, count($cycle_ids), '%d'));

        $components = $wpdb->get_results($wpdb->prepare(
            "SELECT component_id, cycle_id, pathway_id, external_ref
             FROM {$wpdb->prefix}hl_component
             WHERE component_type = 'learndash_course'
               AND cycle_id IN ($cycle_placeholders)
               AND status = 'active'",
            $cycle_ids
        ));

        if (empty($components)) {
            return;
        }

        // Filter to components that reference this specific course_id
        $matching_components = array();
        foreach ($components as $component) {
            if (empty($component->external_ref)) {
                continue;
            }
            $ref = json_decode($component->external_ref, true);
            if (is_array($ref) && isset($ref['course_id']) && absint($ref['course_id']) === absint($course_id)) {
                $matching_components[] = $component;
            }
        }

        if (empty($matching_components)) {
            return;
        }

        // Build a lookup: cycle_id => enrollment_id
        $cycle_enrollment_map = array();
        foreach ($enrollments as $enrollment) {
            $cycle_enrollment_map[$enrollment->cycle_id] = $enrollment->enrollment_id;
        }

        // For each matching component, mark the corresponding enrollment's component_state as complete
        $updated_enrollment_ids = array();
        foreach ($matching_components as $component) {
            $enrollment_id = isset($cycle_enrollment_map[$component->cycle_id])
                ? $cycle_enrollment_map[$component->cycle_id]
                : 0;

            if (!$enrollment_id) {
                continue;
            }

            // Upsert component state
            $existing_state_id = $wpdb->get_var($wpdb->prepare(
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

            if ($existing_state_id) {
                $wpdb->update(
                    $wpdb->prefix . 'hl_component_state',
                    $state_data,
                    array('state_id' => $existing_state_id)
                );
            } else {
                $state_data['enrollment_id'] = $enrollment_id;
                $state_data['component_id']  = $component->component_id;
                $wpdb->insert($wpdb->prefix . 'hl_component_state', $state_data);
            }

            $updated_enrollment_ids[] = $enrollment_id;

            HL_Audit_Service::log('learndash_course.completed', array(
                'entity_type' => 'component',
                'entity_id'   => $component->component_id,
                'cycle_id'    => $component->cycle_id,
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
