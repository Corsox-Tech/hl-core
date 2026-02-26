<?php
if (!defined('ABSPATH')) exit;

/**
 * JetFormBuilder Integration Service
 *
 * Handles:
 * - Hook listener for JFB form submissions (hl_core_form_submitted)
 * - Front-end form rendering helper with pre-populated hidden fields
 * - JFB active check and admin notice
 * - Available forms query for admin dropdowns
 *
 * @package HL_Core
 */
class HL_JFB_Integration {

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Always register admin notice check
        add_action('admin_notices', array($this, 'maybe_show_inactive_notice'));

        if (!$this->is_active()) {
            return;
        }

        // Register the hook listener for JFB custom action
        add_action(
            'jet-form-builder/custom-action/hl_core_form_submitted',
            array($this, 'handle_form_submitted'),
            10,
            2
        );
    }

    // =========================================================================
    // JFB Active Check
    // =========================================================================

    /**
     * Check if JetFormBuilder is active
     *
     * @return bool
     */
    public function is_active() {
        return defined('JET_FORM_BUILDER_VERSION') || post_type_exists('jet-form-builder');
    }

    /**
     * Show admin notice if JetFormBuilder is not active
     */
    public function maybe_show_inactive_notice() {
        if ($this->is_active()) {
            return;
        }

        // Only show on HL Core admin pages
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'hl-') === false) {
            return;
        }

        echo '<div class="notice notice-warning is-dismissible">';
        echo '<p><strong>' . esc_html__('HL Core:', 'hl-core') . '</strong> ';
        echo esc_html__('JetFormBuilder is not active. Teacher self-assessment and observation forms require JetFormBuilder to be installed and activated.', 'hl-core');
        echo '</p></div>';
    }

    // =========================================================================
    // Form Submission Hook Listener
    // =========================================================================

    /**
     * Handle JFB form submission via the hl_core_form_submitted custom action
     *
     * Reads hidden fields from the submission, determines activity type,
     * updates instance records, marks activity complete, and logs audit.
     *
     * @param array  $request        Form field values
     * @param object $action_handler JFB action handler
     */
    public function handle_form_submitted($request, $action_handler) {
        // Extract required hidden fields
        $enrollment_id = isset($request['hl_enrollment_id']) ? absint($request['hl_enrollment_id']) : 0;
        $activity_id   = isset($request['hl_activity_id'])   ? absint($request['hl_activity_id'])   : 0;
        $track_id      = isset($request['hl_track_id'])      ? absint($request['hl_track_id'])      : 0;

        if (!$enrollment_id || !$activity_id || !$track_id) {
            // Missing required context — cannot process
            return;
        }

        global $wpdb;

        // Load the activity to determine its type
        $activity = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_activity WHERE activity_id = %d",
            $activity_id
        ));

        if (!$activity) {
            return;
        }

        // Try to get the JFB record ID if available
        $jfb_record_id = null;
        if (function_exists('jet_fb_context')) {
            $context = jet_fb_context();
            if (method_exists($context, 'get_value')) {
                $jfb_record_id = $context->get_value('_jfb_record_id');
            }
        }

        $now = current_time('mysql');

        switch ($activity->activity_type) {
            case 'teacher_self_assessment':
                $this->handle_teacher_assessment_submission(
                    $enrollment_id, $activity_id, $track_id, $activity, $jfb_record_id, $now
                );
                break;

            case 'observation':
                $observation_id = isset($request['hl_observation_id']) ? absint($request['hl_observation_id']) : 0;
                $this->handle_observation_submission(
                    $enrollment_id, $activity_id, $track_id, $observation_id, $activity, $jfb_record_id, $now
                );
                break;
        }

        // Update hl_activity_state to complete
        $this->mark_activity_complete($enrollment_id, $activity_id, $now);

        // Trigger completion rollup recomputation
        do_action('hl_core_recompute_rollups', $enrollment_id);

        // Audit log
        HL_Audit_Service::log('jfb_form.submitted', array(
            'entity_type' => 'activity',
            'entity_id'   => $activity_id,
            'track_id'    => $track_id,
            'after_data'  => array(
                'enrollment_id' => $enrollment_id,
                'activity_type' => $activity->activity_type,
                'jfb_record_id' => $jfb_record_id,
            ),
        ));
    }

    /**
     * Handle teacher self-assessment JFB submission
     */
    private function handle_teacher_assessment_submission($enrollment_id, $activity_id, $track_id, $activity, $jfb_record_id, $now) {
        global $wpdb;

        // Determine phase from external_ref
        $phase = 'pre';
        if (!empty($activity->external_ref)) {
            $ref = json_decode($activity->external_ref, true);
            if (isset($ref['phase'])) {
                $phase = sanitize_text_field($ref['phase']);
            }
        }

        // Determine jfb_form_id from external_ref
        $jfb_form_id = null;
        if (!empty($activity->external_ref)) {
            $ref = json_decode($activity->external_ref, true);
            if (isset($ref['form_id'])) {
                $jfb_form_id = absint($ref['form_id']);
            }
        }

        // Find or create the teacher assessment instance
        $instance = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_teacher_assessment_instance
             WHERE track_id = %d AND enrollment_id = %d AND phase = %s",
            $track_id, $enrollment_id, $phase
        ));

        if ($instance) {
            // Update existing instance to submitted
            $wpdb->update(
                $wpdb->prefix . 'hl_teacher_assessment_instance',
                array(
                    'status'        => 'submitted',
                    'submitted_at'  => $now,
                    'jfb_form_id'   => $jfb_form_id,
                    'jfb_record_id' => $jfb_record_id,
                ),
                array('instance_id' => $instance->instance_id)
            );
        } else {
            // Create a new instance and mark as submitted
            $wpdb->insert($wpdb->prefix . 'hl_teacher_assessment_instance', array(
                'instance_uuid' => HL_DB_Utils::generate_uuid(),
                'track_id'      => $track_id,
                'enrollment_id' => $enrollment_id,
                'phase'         => $phase,
                'jfb_form_id'   => $jfb_form_id,
                'jfb_record_id' => $jfb_record_id,
                'status'        => 'submitted',
                'submitted_at'  => $now,
            ));
        }
    }

    /**
     * Handle observation JFB submission
     */
    private function handle_observation_submission($enrollment_id, $activity_id, $track_id, $observation_id, $activity, $jfb_record_id, $now) {
        global $wpdb;

        if (!$observation_id) {
            return;
        }

        // Determine jfb_form_id from external_ref
        $jfb_form_id = null;
        if (!empty($activity->external_ref)) {
            $ref = json_decode($activity->external_ref, true);
            if (isset($ref['form_id'])) {
                $jfb_form_id = absint($ref['form_id']);
            }
        }

        // Update the observation record
        $wpdb->update(
            $wpdb->prefix . 'hl_observation',
            array(
                'status'        => 'submitted',
                'submitted_at'  => $now,
                'jfb_form_id'   => $jfb_form_id,
                'jfb_record_id' => $jfb_record_id,
            ),
            array('observation_id' => $observation_id)
        );
    }

    /**
     * Mark an activity as complete in hl_activity_state
     *
     * @param int    $enrollment_id
     * @param int    $activity_id
     * @param string $now MySQL datetime
     */
    private function mark_activity_complete($enrollment_id, $activity_id, $now) {
        global $wpdb;

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT state_id FROM {$wpdb->prefix}hl_activity_state
             WHERE enrollment_id = %d AND activity_id = %d",
            $enrollment_id, $activity_id
        ));

        $state_data = array(
            'completion_percent' => 100,
            'completion_status'  => 'complete',
            'completed_at'       => $now,
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
            $state_data['activity_id']   = $activity_id;
            $wpdb->insert($wpdb->prefix . 'hl_activity_state', $state_data);
        }
    }

    // =========================================================================
    // Front-End Form Rendering
    // =========================================================================

    /**
     * Render a JFB form with pre-populated hidden fields
     *
     * @param int   $form_id       JetFormBuilder form post ID
     * @param array $hidden_fields Key-value pairs to inject as hidden field defaults
     * @return string HTML output
     */
    public function render_form($form_id, $hidden_fields = array()) {
        if (!$this->is_active()) {
            return '<div class="hl-notice hl-notice-warning">'
                . esc_html__('This form requires JetFormBuilder to be active.', 'hl-core')
                . '</div>';
        }

        $form_id = absint($form_id);
        if (!$form_id) {
            return '';
        }

        // Build query args for the shortcode to pre-populate hidden fields
        // JFB supports field_value attribute or default values via URL params
        $query_args = array();
        foreach ($hidden_fields as $field_name => $value) {
            $query_args[sanitize_key($field_name)] = $value;
        }

        // Add hidden field values as URL parameters so JFB can pick them up
        if (!empty($query_args)) {
            foreach ($query_args as $key => $value) {
                $_REQUEST[$key] = $value;
            }
        }

        // Render via JFB shortcode
        $shortcode = sprintf('[jet_fb_form form_id="%d"]', $form_id);

        return do_shortcode($shortcode);
    }

    // =========================================================================
    // Admin Helpers
    // =========================================================================

    /**
     * Get available JetFormBuilder forms for admin dropdowns
     *
     * @return array Array of ['id' => int, 'title' => string]
     */
    public function get_available_forms() {
        if (!$this->is_active()) {
            return array();
        }

        $forms = get_posts(array(
            'post_type'      => 'jet-form-builder',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ));

        $result = array();
        foreach ($forms as $form) {
            $result[] = array(
                'id'    => $form->ID,
                'title' => $form->post_title,
            );
        }

        return $result;
    }
}
