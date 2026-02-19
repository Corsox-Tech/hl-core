<?php
if (!defined('ABSPATH')) exit;

/**
 * Renderer for the [hl_program_page] shortcode.
 *
 * Shows a single program (pathway) detail page with:
 * - Hero image, name, description
 * - Details panel (avg time, expiration, status)
 * - Objectives, syllabus link
 * - Activity cards with per-activity status and actions
 *
 * URL parameters: ?id={pathway_id}&enrollment={enrollment_id}
 *
 * @package HL_Core
 */
class HL_Frontend_Program_Page {

    /** @var HL_Enrollment_Repository */
    private $enrollment_repo;

    /** @var HL_Cohort_Repository */
    private $cohort_repo;

    /** @var HL_Pathway_Repository */
    private $pathway_repo;

    /** @var HL_Activity_Repository */
    private $activity_repo;

    /** @var HL_Rules_Engine_Service */
    private $rules_engine;

    /** @var HL_LearnDash_Integration */
    private $learndash;

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
        $this->enrollment_repo = new HL_Enrollment_Repository();
        $this->cohort_repo     = new HL_Cohort_Repository();
        $this->pathway_repo    = new HL_Pathway_Repository();
        $this->activity_repo   = new HL_Activity_Repository();
        $this->rules_engine    = new HL_Rules_Engine_Service();
        $this->learndash       = HL_LearnDash_Integration::instance();
    }

    /**
     * Render the Program Page shortcode.
     *
     * @param array $atts Shortcode attributes.
     * @return string HTML output.
     */
    public function render($atts) {
        ob_start();

        $user_id       = get_current_user_id();
        $pathway_id    = isset($_GET['id']) ? absint($_GET['id']) : 0;
        $enrollment_id = isset($_GET['enrollment']) ? absint($_GET['enrollment']) : 0;

        // Validate parameters.
        if (!$pathway_id || !$enrollment_id) {
            echo '<div class="hl-dashboard hl-program-page">';
            echo '<div class="hl-notice hl-notice-error">' . esc_html__('Invalid program link. Please go back to My Programs.', 'hl-core') . '</div>';
            echo '</div>';
            return ob_get_clean();
        }

        // Load pathway.
        $pathway = $this->pathway_repo->get_by_id($pathway_id);
        if (!$pathway) {
            echo '<div class="hl-dashboard hl-program-page">';
            echo '<div class="hl-notice hl-notice-error">' . esc_html__('Program not found.', 'hl-core') . '</div>';
            echo '</div>';
            return ob_get_clean();
        }

        // Load and validate enrollment.
        $enrollment = $this->enrollment_repo->get_by_id($enrollment_id);
        if (!$enrollment || (int) $enrollment->user_id !== $user_id) {
            echo '<div class="hl-dashboard hl-program-page">';
            echo '<div class="hl-notice hl-notice-error">' . esc_html__('You do not have access to this program.', 'hl-core') . '</div>';
            echo '</div>';
            return ob_get_clean();
        }

        // Verify enrollment pathway matches.
        if ((int) $enrollment->assigned_pathway_id !== (int) $pathway->pathway_id) {
            echo '<div class="hl-dashboard hl-program-page">';
            echo '<div class="hl-notice hl-notice-error">' . esc_html__('This program is not assigned to your enrollment.', 'hl-core') . '</div>';
            echo '</div>';
            return ob_get_clean();
        }

        $cohort = $this->cohort_repo->get_by_id($enrollment->cohort_id);

        // Load activities and compute per-activity data.
        $activities = $this->activity_repo->get_by_pathway($pathway->pathway_id);
        $activities = array_filter($activities, function ($act) {
            return $act->visibility !== 'staff_only';
        });
        $activities = array_values($activities);

        $total_weight  = 0;
        $weighted_done = 0;
        $activity_data = array();

        foreach ($activities as $activity) {
            $availability = $this->rules_engine->compute_availability(
                $enrollment->enrollment_id,
                $activity->activity_id
            );

            $state = $this->get_activity_state(
                $enrollment->enrollment_id,
                $activity->activity_id
            );

            $completion_percent = $state ? (float) $state['completion_percent'] : 0;
            $completion_status  = $state ? $state['completion_status'] : 'not_started';
            $completed_at       = $state ? $state['completed_at'] : null;

            $external_ref = $activity->get_external_ref_array();
            $course_url   = '';

            if ($activity->activity_type === 'learndash_course' && !empty($external_ref['course_id'])) {
                $course_id  = absint($external_ref['course_id']);
                $course_url = get_permalink($course_id);

                if ($availability['availability_status'] !== 'completed') {
                    $ld_percent = $this->learndash->get_course_progress_percent($user_id, $course_id);
                    if ($ld_percent > $completion_percent) {
                        $completion_percent = $ld_percent;
                    }
                }
            }

            if ($availability['availability_status'] === 'completed') {
                $completion_percent = 100;
                $completion_status  = 'complete';
            }

            $weight = max((float) $activity->weight, 0);
            $total_weight  += $weight;
            $weighted_done += $weight * ($completion_percent / 100);

            $activity_data[] = array(
                'activity'           => $activity,
                'availability'       => $availability,
                'completion_percent' => $completion_percent,
                'completion_status'  => $completion_status,
                'completed_at'       => $completed_at,
                'course_url'         => $course_url,
            );
        }

        $overall_percent = ($total_weight > 0)
            ? round(($weighted_done / $total_weight) * 100)
            : 0;

        // Determine program status.
        $program_status       = 'Active';
        $program_status_class = 'hl-badge-active';

        if (!empty($pathway->expiration_date) && strtotime($pathway->expiration_date) < time()) {
            $program_status       = __('Expired', 'hl-core');
            $program_status_class = 'hl-badge-archived';
        } elseif ($overall_percent >= 100) {
            $program_status       = __('Completed', 'hl-core');
            $program_status_class = 'hl-badge-completed';
        } elseif ($cohort && $cohort->status === 'paused') {
            $program_status       = __('Paused', 'hl-core');
            $program_status_class = 'hl-badge-paused';
        }

        // Breadcrumb URL.
        $my_programs_url = apply_filters('hl_core_my_programs_page_url', '');
        if (empty($my_programs_url)) {
            $my_programs_url = $this->find_shortcode_page_url('hl_my_programs');
        }

        // Featured image.
        $image_id = !empty($pathway->featured_image_id) ? absint($pathway->featured_image_id) : 0;

        // Render.
        ?>
        <div class="hl-dashboard hl-program-page">

            <?php if (!empty($my_programs_url)) : ?>
                <a href="<?php echo esc_url($my_programs_url); ?>" class="hl-back-link">&larr; <?php esc_html_e('Back to My Programs', 'hl-core'); ?></a>
            <?php endif; ?>

            <!-- Header -->
            <div class="hl-program-header">
                <?php if ($image_id) : ?>
                    <div class="hl-program-hero-image">
                        <?php echo wp_get_attachment_image($image_id, 'large', false, array('loading' => 'lazy')); ?>
                    </div>
                <?php endif; ?>
                <div class="hl-program-header-info">
                    <h1 class="hl-cohort-title"><?php echo esc_html($pathway->pathway_name); ?></h1>
                    <p class="hl-program-card-cohort"><?php echo esc_html($cohort ? $cohort->cohort_name : ''); ?></p>
                    <?php if (!empty($pathway->description)) : ?>
                        <div class="hl-inline-form-description"><?php echo wp_kses_post($pathway->description); ?></div>
                    <?php endif; ?>
                </div>
                <?php $this->render_progress_ring($overall_percent); ?>
            </div>

            <!-- Details Panel -->
            <div class="hl-program-details">
                <?php if (!empty($pathway->avg_completion_time)) : ?>
                    <div class="hl-program-detail-item"><strong><?php esc_html_e('Avg Time:', 'hl-core'); ?></strong> <?php echo esc_html($pathway->avg_completion_time); ?></div>
                <?php endif; ?>
                <?php if (!empty($pathway->expiration_date)) : ?>
                    <div class="hl-program-detail-item"><strong><?php esc_html_e('Expires:', 'hl-core'); ?></strong> <?php echo esc_html($this->format_date($pathway->expiration_date)); ?></div>
                <?php endif; ?>
                <div class="hl-program-detail-item"><strong><?php esc_html_e('Status:', 'hl-core'); ?></strong> <span class="hl-badge <?php echo esc_attr($program_status_class); ?>"><?php echo esc_html($program_status); ?></span></div>
                <div class="hl-program-detail-item"><strong><?php esc_html_e('Activities:', 'hl-core'); ?></strong> <?php echo esc_html(count($activity_data)); ?></div>
            </div>

            <!-- Objectives -->
            <?php if (!empty($pathway->objectives)) : ?>
                <div class="hl-program-objectives">
                    <h3><?php esc_html_e('Program Objectives', 'hl-core'); ?></h3>
                    <?php echo wp_kses_post($pathway->objectives); ?>
                </div>
            <?php endif; ?>

            <!-- Syllabus link -->
            <?php if (!empty($pathway->syllabus_url)) : ?>
                <div class="hl-activity-action">
                    <a href="<?php echo esc_url($pathway->syllabus_url); ?>" target="_blank" class="hl-btn hl-btn-secondary"><?php esc_html_e('View Syllabus', 'hl-core'); ?></a>
                </div>
            <?php endif; ?>

            <!-- Activities Section -->
            <?php if (!empty($activity_data)) : ?>
                <div class="hl-activity-list">
                    <h3 class="hl-section-title"><?php esc_html_e('Program Steps', 'hl-core'); ?></h3>
                    <?php foreach ($activity_data as $ad) :
                        $this->render_activity_card($ad, $pathway, $enrollment);
                    endforeach; ?>
                </div>
            <?php else : ?>
                <div class="hl-notice hl-notice-info">
                    <?php esc_html_e('No learning activities have been added to this program yet.', 'hl-core'); ?>
                </div>
            <?php endif; ?>
        </div>
        <?php

        return ob_get_clean();
    }

    /**
     * Render a single activity card with actions.
     */
    private function render_activity_card($ad, $pathway, $enrollment) {
        $activity           = $ad['activity'];
        $availability       = $ad['availability'];
        $completion_percent = (int) $ad['completion_percent'];
        $completed_at       = $ad['completed_at'];
        $course_url         = $ad['course_url'];
        $avail_status       = $availability['availability_status'];

        // CSS classes.
        switch ($avail_status) {
            case 'completed':
                $card_class = 'hl-activity-completed';
                $bar_class  = 'hl-progress-complete';
                break;
            case 'locked':
                $card_class = 'hl-activity-locked';
                $bar_class  = 'hl-progress-locked';
                break;
            default:
                $card_class = 'hl-activity-available';
                $bar_class  = ($completion_percent > 0) ? 'hl-progress-active' : '';
                break;
        }

        $type_label = isset(self::$type_labels[$activity->activity_type])
            ? self::$type_labels[$activity->activity_type]
            : ucwords(str_replace('_', ' ', $activity->activity_type));

        // Action button/link.
        $action_html = '';
        if ($avail_status === 'available') {
            $action_html = $this->get_action_html($activity, $enrollment, $pathway);
        }
        ?>
        <div class="hl-activity-card <?php echo esc_attr($card_class); ?>">
            <div class="hl-activity-status-icon">
                <?php if ($avail_status === 'completed') : ?>
                    <span class="hl-icon-check">&#10003;</span>
                <?php elseif ($avail_status === 'locked') : ?>
                    <span class="hl-icon-lock">&#128274;</span>
                <?php else : ?>
                    <span class="hl-icon-progress">&#9654;</span>
                <?php endif; ?>
            </div>
            <div class="hl-activity-content">
                <h4 class="hl-activity-title"><?php echo esc_html($activity->title); ?></h4>
                <div class="hl-activity-meta">
                    <span class="hl-activity-type"><?php echo esc_html($type_label); ?></span>
                    <?php if ($avail_status === 'completed' && $completed_at) : ?>
                        <span class="hl-activity-date"><?php
                            printf(esc_html__('Completed %s', 'hl-core'), esc_html($this->format_date($completed_at)));
                        ?></span>
                    <?php elseif ($avail_status === 'locked') : ?>
                        <span class="hl-activity-lock-reason"><?php echo esc_html($this->get_lock_reason_text($availability)); ?></span>
                    <?php else : ?>
                        <span class="hl-activity-progress-text"><?php
                            if ($completion_percent > 0) {
                                printf(esc_html__('%d%% complete', 'hl-core'), $completion_percent);
                            } else {
                                esc_html_e('Not started', 'hl-core');
                            }
                        ?></span>
                    <?php endif; ?>
                </div>
                <?php if (!empty($action_html)) : ?>
                    <div class="hl-activity-action">
                        <?php echo $action_html; ?>
                    </div>
                <?php endif; ?>
                <div class="hl-progress-bar-container">
                    <div class="hl-progress-bar <?php echo esc_attr($bar_class); ?>" style="width: <?php echo esc_attr($completion_percent); ?>%"></div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Build the action HTML for an available activity.
     *
     * @return string Escaped HTML.
     */
    private function get_action_html($activity, $enrollment, $pathway) {
        $type = $activity->activity_type;

        // LearnDash course: direct link.
        if ($type === 'learndash_course') {
            $external_ref = $activity->get_external_ref_array();
            $course_id    = isset($external_ref['course_id']) ? absint($external_ref['course_id']) : 0;
            if ($course_id) {
                $url = get_permalink($course_id);
                if ($url) {
                    return '<a href="' . esc_url($url) . '" class="hl-btn hl-btn-sm hl-btn-primary">'
                        . esc_html__('Start Course', 'hl-core') . '</a>';
                }
            }
            return '';
        }

        // JFB-powered activities (self-assessment, observation): link to Activity Page.
        if (in_array($type, array('teacher_self_assessment', 'observation'), true)) {
            $activity_page_url = $this->get_activity_page_url($activity->activity_id, $enrollment->enrollment_id);
            if (!empty($activity_page_url)) {
                return '<a href="' . esc_url($activity_page_url) . '" class="hl-btn hl-btn-sm hl-btn-primary">'
                    . esc_html__('Start', 'hl-core') . '</a>';
            }
            return '';
        }

        // Children assessment: link to Activity Page.
        if ($type === 'children_assessment') {
            $activity_page_url = $this->get_activity_page_url($activity->activity_id, $enrollment->enrollment_id);
            if (!empty($activity_page_url)) {
                return '<a href="' . esc_url($activity_page_url) . '" class="hl-btn hl-btn-sm hl-btn-primary">'
                    . esc_html__('Start', 'hl-core') . '</a>';
            }
            return '';
        }

        // Coaching session: managed by coach.
        if ($type === 'coaching_session_attendance') {
            return '<span class="hl-activity-notice">' . esc_html__('Managed by your coach.', 'hl-core') . '</span>';
        }

        return '';
    }

    /**
     * Build the URL for the Activity Page.
     *
     * @param int $activity_id
     * @param int $enrollment_id
     * @return string
     */
    private function get_activity_page_url($activity_id, $enrollment_id) {
        $base = apply_filters('hl_core_activity_page_url', '');
        if (empty($base)) {
            $base = $this->find_shortcode_page_url('hl_activity_page');
        }
        if (empty($base)) {
            return '';
        }
        return add_query_arg(array(
            'id'         => $activity_id,
            'enrollment' => $enrollment_id,
        ), $base);
    }

    /**
     * Render the SVG progress ring.
     */
    private function render_progress_ring($percent) {
        $percent       = max(0, min(100, (int) $percent));
        $circumference = 2 * M_PI * 52;
        $offset        = $circumference * (1 - $percent / 100);
        ?>
        <div class="hl-progress-ring-container">
            <div class="hl-progress-ring" data-percent="<?php echo esc_attr($percent); ?>">
                <svg viewBox="0 0 120 120">
                    <circle class="hl-ring-bg" cx="60" cy="60" r="52" />
                    <circle class="hl-ring-fill" cx="60" cy="60" r="52"
                            stroke-dasharray="<?php echo esc_attr(round($circumference, 2)); ?>"
                            stroke-dashoffset="<?php echo esc_attr(round($offset, 2)); ?>" />
                </svg>
                <div class="hl-ring-text">
                    <span class="hl-ring-percent"><?php echo esc_html($percent . '%'); ?></span>
                    <span class="hl-ring-label"><?php esc_html_e('Complete', 'hl-core'); ?></span>
                </div>
            </div>
        </div>
        <?php
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

            return __('Complete prerequisites first', 'hl-core');
        }

        if ($reason === 'drip' && !empty($availability['next_available_at'])) {
            return sprintf(
                __('Available on %s', 'hl-core'),
                $this->format_date($availability['next_available_at'])
            );
        }

        if ($reason === 'drip') {
            return __('Not yet available', 'hl-core');
        }

        return __('Locked', 'hl-core');
    }

    /**
     * Resolve blocker activity IDs to titles (limit to 3 names + "+N more").
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
     * Query the hl_activity_state table.
     */
    private function get_activity_state($enrollment_id, $activity_id) {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}hl_activity_state WHERE enrollment_id = %d AND activity_id = %d",
                $enrollment_id,
                $activity_id
            ),
            ARRAY_A
        );
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
