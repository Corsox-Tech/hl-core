<?php
if (!defined('ABSPATH')) exit;

/**
 * Renderer for the [hl_activity_page] shortcode.
 *
 * Renders a single activity for the logged-in participant.
 * Depending on activity type:
 * - JFB-powered (teacher_self_assessment, observation): embeds JFB form
 * - children_assessment: links to [hl_children_assessment] page
 * - learndash_course: redirects to the LD course permalink
 * - coaching_session_attendance: shows managed-by-coach notice
 *
 * Locked activities show a lock message. Completed activities show a summary.
 *
 * URL parameters: ?id={activity_id}&enrollment={enrollment_id}
 *
 * @package HL_Core
 */
class HL_Frontend_Activity_Page {

    /** @var HL_Activity_Repository */
    private $activity_repo;

    /** @var HL_Enrollment_Repository */
    private $enrollment_repo;

    /** @var HL_Pathway_Repository */
    private $pathway_repo;

    /** @var HL_Rules_Engine_Service */
    private $rules_engine;

    /** @var HL_JFB_Integration */
    private $jfb;

    /**
     * Activity type display labels.
     */
    private static $type_labels = array(
        'learndash_course'             => 'Course',
        'teacher_self_assessment'      => 'Self-Assessment',
        'children_assessment'          => 'Children Assessment',
        'coaching_session_attendance'  => 'Coaching Session',
        'observation'                  => 'Observation',
    );

    public function __construct() {
        $this->activity_repo   = new HL_Activity_Repository();
        $this->enrollment_repo = new HL_Enrollment_Repository();
        $this->pathway_repo    = new HL_Pathway_Repository();
        $this->rules_engine    = new HL_Rules_Engine_Service();
        $this->jfb             = HL_JFB_Integration::instance();
    }

    /**
     * Render the Activity Page shortcode.
     *
     * @param array $atts Shortcode attributes.
     * @return string HTML output.
     */
    public function render($atts) {
        ob_start();

        $user_id       = get_current_user_id();
        $activity_id   = isset($_GET['id']) ? absint($_GET['id']) : 0;
        $enrollment_id = isset($_GET['enrollment']) ? absint($_GET['enrollment']) : 0;

        // Validate parameters.
        if (!$activity_id || !$enrollment_id) {
            echo '<div class="hl-dashboard hl-activity-page">';
            echo '<div class="hl-notice hl-notice-error">' . esc_html__('Invalid activity link.', 'hl-core') . '</div>';
            echo '</div>';
            return ob_get_clean();
        }

        // Load activity.
        $activity = $this->activity_repo->get_by_id($activity_id);
        if (!$activity) {
            echo '<div class="hl-dashboard hl-activity-page">';
            echo '<div class="hl-notice hl-notice-error">' . esc_html__('Activity not found.', 'hl-core') . '</div>';
            echo '</div>';
            return ob_get_clean();
        }

        // Load and validate enrollment.
        $enrollment = $this->enrollment_repo->get_by_id($enrollment_id);
        if (!$enrollment || (int) $enrollment->user_id !== $user_id) {
            echo '<div class="hl-dashboard hl-activity-page">';
            echo '<div class="hl-notice hl-notice-error">' . esc_html__('You do not have access to this activity.', 'hl-core') . '</div>';
            echo '</div>';
            return ob_get_clean();
        }

        // For LearnDash courses, redirect directly.
        if ($activity->activity_type === 'learndash_course') {
            $external_ref = $activity->get_external_ref_array();
            $course_id    = isset($external_ref['course_id']) ? absint($external_ref['course_id']) : 0;
            if ($course_id) {
                $course_url = get_permalink($course_id);
                if ($course_url) {
                    wp_redirect($course_url);
                    exit;
                }
            }
        }

        // Load pathway for breadcrumb.
        $pathway = $this->pathway_repo->get_by_id($activity->pathway_id);

        // Build Program Page back link.
        $program_page_url = '';
        if ($pathway) {
            $base = apply_filters('hl_core_program_page_url', '');
            if (empty($base)) {
                $base = $this->find_shortcode_page_url('hl_program_page');
            }
            if (!empty($base)) {
                $program_page_url = add_query_arg(array(
                    'id'         => $pathway->pathway_id,
                    'enrollment' => $enrollment->enrollment_id,
                ), $base);
            }
        }

        // Check availability.
        $availability = $this->rules_engine->compute_availability(
            $enrollment->enrollment_id,
            $activity->activity_id
        );
        $avail_status = $availability['availability_status'];

        $type_label = isset(self::$type_labels[$activity->activity_type])
            ? self::$type_labels[$activity->activity_type]
            : ucwords(str_replace('_', ' ', $activity->activity_type));

        ?>
        <div class="hl-dashboard hl-activity-page">

            <?php if (!empty($program_page_url)) : ?>
                <a href="<?php echo esc_url($program_page_url); ?>" class="hl-back-link">&larr; <?php
                    if ($pathway) {
                        printf(esc_html__('Back to %s', 'hl-core'), esc_html($pathway->pathway_name));
                    } else {
                        esc_html_e('Back to Program', 'hl-core');
                    }
                ?></a>
            <?php endif; ?>

            <h1 class="hl-cohort-title"><?php echo esc_html($activity->title); ?></h1>
            <p class="hl-activity-type-badge"><?php echo esc_html($type_label); ?></p>

            <?php if (!empty($activity->description)) : ?>
                <p class="hl-inline-form-description"><?php echo esc_html($activity->description); ?></p>
            <?php endif; ?>

            <?php
            // Flash messages (e.g. after assessment submission redirect).
            if (!empty($_GET['message'])) {
                $msg_key = sanitize_text_field($_GET['message']);
                if ($msg_key === 'submitted') {
                    echo '<div class="hl-notice hl-notice-success"><p>' . esc_html__('Assessment submitted successfully.', 'hl-core') . '</p></div>';
                } elseif ($msg_key === 'saved') {
                    echo '<div class="hl-notice hl-notice-success"><p>' . esc_html__('Draft saved successfully.', 'hl-core') . '</p></div>';
                }
            }

            // Route to per-type rendering.
            if ($avail_status === 'locked') {
                $this->render_locked_view($availability);
            } elseif ($avail_status === 'completed') {
                $this->render_completed_view($activity);
            } else {
                $this->render_available_view($activity, $enrollment);
            }
            ?>
        </div>
        <?php

        return ob_get_clean();
    }

    /**
     * Render the locked activity view.
     */
    private function render_locked_view($availability) {
        ?>
        <div class="hl-activity-locked-view">
            <div class="hl-lock-icon">&#128274;</div>
            <h3><?php esc_html_e('This Activity is Locked', 'hl-core'); ?></h3>
            <p>
                <?php echo esc_html($this->get_lock_reason_text($availability)); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Render the completed activity view.
     */
    private function render_completed_view($activity) {
        ?>
        <div class="hl-notice hl-notice-info">
            <strong>&#10003; <?php esc_html_e('This activity has been completed.', 'hl-core'); ?></strong>
        </div>
        <?php
    }

    /**
     * Render the available activity based on type.
     */
    private function render_available_view($activity, $enrollment) {
        $type = $activity->activity_type;

        // Teacher self-assessment: custom instrument takes priority over JFB.
        if ($type === 'teacher_self_assessment') {
            $external_ref = $activity->get_external_ref_array();
            if (!empty($external_ref['teacher_instrument_id'])) {
                $this->render_teacher_instrument_redirect($activity, $enrollment, $external_ref);
                return;
            }
            // Legacy JFB-powered fallback
            $this->render_jfb_form($activity, $enrollment);
            return;
        }

        // JFB-powered: observation.
        if ($type === 'observation') {
            $this->render_jfb_form($activity, $enrollment);
            return;
        }

        // Children Assessment: link to dedicated page.
        if ($type === 'children_assessment') {
            $assessment_url = apply_filters('hl_core_children_assessment_page_url', '');
            if (empty($assessment_url)) {
                $assessment_url = $this->find_shortcode_page_url('hl_children_assessment');
            }

            if (!empty($assessment_url)) {
                echo '<div class="hl-empty-state">';
                echo '<p>' . esc_html__('This activity uses the Children Assessment form.', 'hl-core') . '</p>';
                echo '<a href="' . esc_url($assessment_url) . '" class="hl-btn hl-btn-primary">' . esc_html__('Go to Children Assessment', 'hl-core') . '</a>';
                echo '</div>';
            } else {
                echo '<div class="hl-notice hl-notice-info">' . esc_html__('Please visit the Children Assessment page to complete this activity.', 'hl-core') . '</div>';
            }
            return;
        }

        // Coaching session attendance.
        if ($type === 'coaching_session_attendance') {
            echo '<div class="hl-notice hl-notice-info">' . esc_html__('This activity is managed by your coach. Attendance will be recorded during your coaching session.', 'hl-core') . '</div>';
            return;
        }

        // Fallback.
        echo '<div class="hl-notice hl-notice-info">' . esc_html__('This activity type is not yet supported for inline display.', 'hl-core') . '</div>';
    }

    /**
     * Render a JFB form for self-assessment or observation activities.
     */
    private function render_jfb_form($activity, $enrollment) {
        $external_ref = $activity->get_external_ref_array();
        $form_id      = isset($external_ref['form_id']) ? absint($external_ref['form_id']) : 0;

        if (!$form_id) {
            echo '<div class="hl-notice hl-notice-error">' . esc_html__('No form has been configured for this activity.', 'hl-core') . '</div>';
            return;
        }

        $hidden_fields = array(
            'hl_enrollment_id' => $enrollment->enrollment_id,
            'hl_activity_id'   => $activity->activity_id,
            'hl_cohort_id'     => $enrollment->cohort_id,
        );

        ?>
        <div class="hl-jfb-form-container">
            <?php
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_form() returns JFB shortcode output
            echo $this->jfb->render_form($form_id, $hidden_fields);
            ?>
        </div>
        <?php
    }

    /**
     * Render the Teacher Self-Assessment form inline on the activity page.
     *
     * Ensures an instance exists for this enrollment + instrument + phase, then
     * renders the assessment form directly (no redirect to a separate page).
     */
    private function render_teacher_instrument_redirect($activity, $enrollment, $external_ref) {
        $instrument_id = absint($external_ref['teacher_instrument_id']);
        $phase         = isset($external_ref['phase']) ? sanitize_text_field($external_ref['phase']) : 'pre';

        $assessment_service = new HL_Assessment_Service();

        // Find or create the instance.
        global $wpdb;
        $instance_id = $wpdb->get_var($wpdb->prepare(
            "SELECT instance_id FROM {$wpdb->prefix}hl_teacher_assessment_instance
             WHERE enrollment_id = %d AND cohort_id = %d AND phase = %s",
            $enrollment->enrollment_id,
            $enrollment->cohort_id,
            $phase
        ));

        if (!$instance_id) {
            $instrument = $assessment_service->get_teacher_instrument($instrument_id);
            $result = $assessment_service->create_teacher_assessment_instance(array(
                'cohort_id'          => $enrollment->cohort_id,
                'enrollment_id'      => $enrollment->enrollment_id,
                'phase'              => $phase,
                'instrument_id'      => $instrument_id,
                'instrument_version' => $instrument ? $instrument->instrument_version : null,
            ));

            if (is_wp_error($result)) {
                echo '<div class="hl-notice hl-notice-error">' . esc_html($result->get_error_message()) . '</div>';
                return;
            }
            $instance_id = $result;
        }

        // Load instance with joined data.
        $instance = $assessment_service->get_teacher_assessment($instance_id);
        if (!$instance || empty($instance['instrument_id'])) {
            echo '<div class="hl-notice hl-notice-error">' . esc_html__('Could not load assessment instance.', 'hl-core') . '</div>';
            return;
        }

        // Load the instrument.
        $instrument = $assessment_service->get_teacher_instrument(absint($instance['instrument_id']));
        if (!$instrument) {
            echo '<div class="hl-notice hl-notice-error">' . esc_html__('Assessment instrument could not be loaded.', 'hl-core') . '</div>';
            return;
        }

        // Decode existing responses.
        $existing_responses = array();
        if (!empty($instance['responses_json'])) {
            $decoded = json_decode($instance['responses_json'], true);
            $existing_responses = is_array($decoded) ? $decoded : array();
        }

        // For POST phase, get PRE responses for "Before" column.
        $pre_responses = array();
        if ($phase === 'post') {
            $pre_responses = $assessment_service->get_pre_responses_for_post(
                absint($instance['enrollment_id']),
                absint($instance['cohort_id'])
            );
        }

        // Handle form submission.
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['hl_tsa_instance_id'])) {
            $posted_instance_id = absint($_POST['hl_tsa_instance_id']);
            if ($posted_instance_id === (int) $instance_id) {
                if (!isset($_POST['hl_teacher_assessment_nonce'])
                    || !wp_verify_nonce($_POST['hl_teacher_assessment_nonce'], 'hl_save_teacher_assessment')) {
                    echo '<div class="hl-notice hl-notice-error"><p>' . esc_html__('Security check failed. Please try again.', 'hl-core') . '</p></div>';
                } else {
                    $action_type = !empty($_POST['hl_tsa_action']) ? sanitize_text_field($_POST['hl_tsa_action']) : 'draft';
                    $is_draft    = ($action_type !== 'submit');

                    $raw_resp  = isset($_POST['resp']) && is_array($_POST['resp']) ? $_POST['resp'] : array();
                    $sanitized = $this->sanitize_responses($raw_resp);

                    $save_result = $assessment_service->save_teacher_assessment_responses(
                        $instance_id,
                        $sanitized,
                        $is_draft
                    );

                    if (is_wp_error($save_result)) {
                        echo '<div class="hl-notice hl-notice-error"><p>' . esc_html($save_result->get_error_message()) . '</p></div>';
                    } else {
                        // Redirect to avoid double-submit.
                        $redirect_url = add_query_arg(array(
                            'id'         => $activity->activity_id,
                            'enrollment' => $enrollment->enrollment_id,
                            'message'    => $is_draft ? 'saved' : 'submitted',
                        ));
                        echo '<script>window.location.href = ' . wp_json_encode($redirect_url) . ';</script>';
                        return;
                    }
                }
            }
        }

        // Render form or read-only view.
        $is_submitted = ($instance['status'] === 'submitted');

        $renderer = new HL_Teacher_Assessment_Renderer(
            $instrument,
            (object) $instance,
            $phase,
            $existing_responses,
            $pre_responses,
            $is_submitted
        );
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderer returns safe HTML
        echo $renderer->render();
    }

    /**
     * Recursively sanitize the responses array.
     *
     * Expected structure: [ section_key => [ item_key => value_or_array ] ]
     *
     * @param array $raw Raw POST data.
     * @return array Sanitized responses.
     */
    private function sanitize_responses($raw) {
        $clean = array();
        foreach ($raw as $section_key => $items) {
            $section_key = sanitize_text_field($section_key);
            if (!is_array($items)) {
                continue;
            }
            $clean[$section_key] = array();
            foreach ($items as $item_key => $value) {
                $item_key = sanitize_text_field($item_key);
                if (is_array($value)) {
                    $clean[$section_key][$item_key] = array_map('sanitize_text_field', $value);
                } else {
                    $clean[$section_key][$item_key] = sanitize_text_field($value);
                }
            }
        }
        return $clean;
    }

    /**
     * Build a human-readable lock reason string with type-specific messages.
     */
    private function get_lock_reason_text($availability) {
        $reason = $availability['locked_reason'];

        if ($reason === 'prereq') {
            $blockers    = isset($availability['blockers']) ? $availability['blockers'] : array();
            $prereq_type = isset($availability['prereq_type']) ? $availability['prereq_type'] : 'all_of';
            $n_required  = isset($availability['n_required']) ? (int) $availability['n_required'] : 0;

            if (!empty($blockers)) {
                $names = $this->resolve_blocker_names($blockers);
                $display = (count($names) > 3)
                    ? implode(', ', array_slice($names, 0, 3)) . sprintf(' +%d more', count($names) - 3)
                    : implode(', ', $names);

                switch ($prereq_type) {
                    case 'any_of':
                        return sprintf(__('Complete at least one of: %s', 'hl-core'), $display);
                    case 'n_of_m':
                        return sprintf(__('Complete %d of: %s', 'hl-core'), $n_required, $display);
                    case 'all_of':
                    default:
                        return sprintf(__('Complete prerequisites: %s', 'hl-core'), $display);
                }
            }

            return __('This activity requires its prerequisites to be completed first.', 'hl-core');
        }

        if ($reason === 'drip' && !empty($availability['next_available_at'])) {
            return sprintf(
                __('This activity will be available on %s.', 'hl-core'),
                $this->format_date($availability['next_available_at'])
            );
        }

        if ($reason === 'drip') {
            return __('This activity is not yet available.', 'hl-core');
        }

        return __('This activity is currently locked.', 'hl-core');
    }

    /**
     * Resolve blocker activity IDs to titles.
     *
     * @param int[] $blocker_ids
     * @return string[]
     */
    private function resolve_blocker_names($blocker_ids) {
        if (empty($blocker_ids)) {
            return array();
        }
        global $wpdb;
        $ids = implode(',', array_map('intval', $blocker_ids));
        $rows = $wpdb->get_results(
            "SELECT activity_id, title FROM {$wpdb->prefix}hl_activity WHERE activity_id IN ({$ids})",
            ARRAY_A
        );
        $map = array();
        foreach ($rows as $r) {
            $map[(int) $r['activity_id']] = $r['title'];
        }
        $names = array();
        foreach ($blocker_ids as $aid) {
            $names[] = isset($map[(int) $aid]) ? $map[(int) $aid] : ('#' . $aid);
        }
        return $names;
    }

    /**
     * Format a date/datetime string for display.
     */
    private function format_date($date_string) {
        if (empty($date_string)) {
            return '';
        }
        $timestamp = strtotime($date_string);
        if ($timestamp === false) {
            return $date_string;
        }
        return date_i18n(get_option('date_format', 'M j, Y'), $timestamp);
    }

    /**
     * Find the URL of a page containing a given shortcode.
     */
    private function find_shortcode_page_url($shortcode) {
        global $wpdb;
        $page_id = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE %s LIMIT 1",
            '%[' . $wpdb->esc_like($shortcode) . '%'
        ));
        return $page_id ? get_permalink($page_id) : '';
    }
}
