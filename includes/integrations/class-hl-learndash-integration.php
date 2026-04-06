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
     * Handle LearnDash course completion event.
     *
     * Catalog-first: looks up the completed LD course ID in hl_course_catalog,
     * then finds components by catalog_id FK. Falls back to external_ref matching
     * when the catalog path finds nothing AND hl_catalog_migration_complete is not set.
     *
     * Completion is language-agnostic — completing any language variant of a catalog
     * entry marks the component complete. Re-completing (e.g., Spanish after English)
     * is a no-op per spec: "nothing changes" once the component is already complete.
     */
    public function on_course_completed($data) {
        if (!is_array($data) || empty($data['user']) || empty($data['course'])) {
            return;
        }

        $user_id   = is_object($data['user'])   ? $data['user']->ID   : $data['user'];
        $course_id = is_object($data['course'])  ? $data['course']->ID : $data['course'];

        global $wpdb;
        $now = current_time('mysql');

        // Find all active enrollments for this user.
        $enrollments = $wpdb->get_results($wpdb->prepare(
            "SELECT enrollment_id, cycle_id, assigned_pathway_id
             FROM {$wpdb->prefix}hl_enrollment
             WHERE user_id = %d AND status = 'active'",
            $user_id
        ));

        if (empty($enrollments)) {
            return;
        }

        $cycle_ids          = array_unique(wp_list_pluck($enrollments, 'cycle_id'));
        $cycle_placeholders = implode(',', array_fill(0, count($cycle_ids), '%d'));

        // Build cycle_id → enrollment_id map for the upsert step.
        $cycle_enrollment_map = array();
        foreach ($enrollments as $enrollment) {
            $cycle_enrollment_map[$enrollment->cycle_id] = $enrollment->enrollment_id;
        }

        $matching_components = array();

        // --- Catalog path (preferred) ---
        $repo = new HL_Course_Catalog_Repository();
        if ($repo->table_exists()) {
            $catalog_entry = $repo->find_by_ld_course_id($course_id);
            if ($catalog_entry) {
                $components = $wpdb->get_results($wpdb->prepare(
                    "SELECT component_id, cycle_id, pathway_id
                     FROM {$wpdb->prefix}hl_component
                     WHERE catalog_id = %d
                       AND component_type = 'learndash_course'
                       AND status = 'active'
                       AND cycle_id IN ($cycle_placeholders)",
                    array_merge(array($catalog_entry->catalog_id), $cycle_ids)
                ));
                if (!empty($components)) {
                    $matching_components = $components;
                }
            }
        }

        // --- Fallback path (only when catalog matched nothing AND migration not complete) ---
        // Do NOT set hl_catalog_migration_complete until every active component
        // has its catalog_id backfilled — premature flag-setting drops completions.
        if (empty($matching_components) && !get_option('hl_catalog_migration_complete', false)) {
            $components = $wpdb->get_results($wpdb->prepare(
                "SELECT component_id, cycle_id, pathway_id, external_ref
                 FROM {$wpdb->prefix}hl_component
                 WHERE component_type = 'learndash_course'
                   AND cycle_id IN ($cycle_placeholders)
                   AND status = 'active'",
                $cycle_ids
            ));
            foreach (($components ?: array()) as $component) {
                if (empty($component->external_ref)) {
                    continue;
                }
                $ref = json_decode($component->external_ref, true);
                if (is_array($ref) && isset($ref['course_id']) && absint($ref['course_id']) === absint($course_id)) {
                    $matching_components[] = $component;
                }
            }
            if (!empty($matching_components)) {
                error_log(sprintf(
                    '[HL LD] Fallback path matched course %d — catalog migration may be incomplete',
                    $course_id
                ));
            }
        }

        if (empty($matching_components)) {
            return;
        }

        // Upsert component_state for each matched component.
        $updated_enrollment_ids = array();
        foreach ($matching_components as $component) {
            $enrollment_id = isset($cycle_enrollment_map[$component->cycle_id])
                ? $cycle_enrollment_map[$component->cycle_id]
                : 0;

            if (!$enrollment_id) {
                continue;
            }

            // Check existing state — skip if already complete (spec: "nothing changes").
            $existing_state = $wpdb->get_row($wpdb->prepare(
                "SELECT state_id, completion_status FROM {$wpdb->prefix}hl_component_state
                 WHERE enrollment_id = %d AND component_id = %d",
                $enrollment_id, $component->component_id
            ));

            if ($existing_state && $existing_state->completion_status === 'complete') {
                continue;
            }

            $state_data = array(
                'completion_percent' => 100,
                'completion_status'  => 'complete',
                'completed_at'       => $now,
                'last_computed_at'   => $now,
            );

            if ($existing_state) {
                $wpdb->update(
                    $wpdb->prefix . 'hl_component_state',
                    $state_data,
                    array('state_id' => $existing_state->state_id)
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

        // Trigger rollup recomputation for each affected enrollment.
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

    /**
     * Reset a user's LearnDash course progress.
     *
     * Clears usermeta/quiz history via LD API, then also resets the
     * wp_learndash_user_activity row which learndash_delete_course_progress
     * does NOT clear.
     *
     * @param int $user_id
     * @param int $course_id LD course post ID.
     * @return bool True on success, false if LD functions unavailable.
     */
    public function reset_course_progress($user_id, $course_id) {
        if (!function_exists('learndash_delete_course_progress')) {
            error_log(sprintf('[HL Core] reset_course_progress: learndash_delete_course_progress not available (user=%d, course=%d)', $user_id, $course_id));
            return false;
        }

        // Argument order is ($course_id, $user_id) — confirmed in sfwd-lms source.
        learndash_delete_course_progress($course_id, $user_id);

        // Also reset wp_learndash_user_activity row (not cleared by the above).
        // Pass identifying keys directly — do NOT cast the activity object to array
        // because learndash_get_user_activity may return an LDLMS_Model_Activity
        // object whose (array) cast produces mangled property keys.
        if (function_exists('learndash_update_user_activity')) {
            learndash_update_user_activity(array(
                'user_id'            => $user_id,
                'course_id'          => $course_id,
                'post_id'            => $course_id,
                'activity_type'      => 'course',
                'activity_status'    => false,
                'activity_completed' => 0,
                'activity_updated'   => time(),
            ));
        }

        return true;
    }

    /**
     * Mark a LearnDash course as complete for a user.
     *
     * Uses learndash_update_user_activity() directly — does NOT use
     * learndash_process_mark_complete() (takes step ID, not course ID)
     * and does NOT iterate steps (would re-fire learndash_course_completed
     * hook causing duplicate downstream actions).
     *
     * @param int $user_id
     * @param int $course_id LD course post ID.
     * @return bool True on success, false if LD functions unavailable.
     */
    public function mark_course_complete($user_id, $course_id) {
        if (!function_exists('learndash_update_user_activity')) {
            error_log(sprintf('[HL Core] mark_course_complete: learndash_update_user_activity not available (user=%d, course=%d)', $user_id, $course_id));
            return false;
        }

        learndash_update_user_activity(array(
            'user_id'            => $user_id,
            'course_id'          => $course_id,
            'post_id'            => $course_id,
            'activity_type'      => 'course',
            'activity_status'    => true,
            'activity_completed' => time(),
            'activity_updated'   => time(),
            'activity_started'   => time(),
        ));

        return true;
    }
}
