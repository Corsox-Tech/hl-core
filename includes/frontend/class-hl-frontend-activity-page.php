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

            <h1 style="margin:0 0 8px;font-size:24px;color:#1a237e;"><?php echo esc_html($activity->title); ?></h1>
            <p class="hl-activity-type-badge"><?php echo esc_html($type_label); ?></p>

            <?php if (!empty($activity->description)) : ?>
                <p style="font-size:15px;color:#555;margin:0 0 20px;line-height:1.6;"><?php echo esc_html($activity->description); ?></p>
            <?php endif; ?>

            <?php
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
            <h3 style="color:#666;margin:0 0 8px;"><?php esc_html_e('This Activity is Locked', 'hl-core'); ?></h3>
            <p style="color:#888;font-size:15px;margin:0;">
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
        <div class="hl-notice hl-notice-info" style="text-align:center;">
            <strong>&#10003; <?php esc_html_e('This activity has been completed.', 'hl-core'); ?></strong>
        </div>
        <?php
    }

    /**
     * Render the available activity based on type.
     */
    private function render_available_view($activity, $enrollment) {
        $type = $activity->activity_type;

        // JFB-powered: teacher_self_assessment, observation.
        if (in_array($type, array('teacher_self_assessment', 'observation'), true)) {
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
                echo '<div style="text-align:center;padding:32px 0;">';
                echo '<p style="font-size:15px;color:#555;margin:0 0 16px;">' . esc_html__('This activity uses the Children Assessment form.', 'hl-core') . '</p>';
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
     * Build a human-readable lock reason string.
     */
    private function get_lock_reason_text($availability) {
        $reason = $availability['locked_reason'];

        if ($reason === 'prereq') {
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
