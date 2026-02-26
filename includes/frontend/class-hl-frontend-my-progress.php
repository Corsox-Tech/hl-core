<?php
if (!defined('ABSPATH')) exit;

/**
 * Renderer for the [hl_my_progress] shortcode.
 *
 * Shows a logged-in participant their learning progress across
 * one or more cohort enrollments, including per-activity status,
 * LearnDash course progress, and overall pathway completion.
 *
 * @package HL_Core
 */
class HL_Frontend_My_Progress {

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

    /** @var HL_JFB_Integration */
    private $jfb;

    /**
     * Activity type display labels.
     */
    private static $type_labels = array(
        'learndash_course'             => 'LearnDash Course',
        'teacher_self_assessment'      => 'Self-Assessment',
        'child_assessment'          => 'Child Assessment',
        'coaching_session_attendance'  => 'Coaching Session',
        'observation'                  => 'Observation',
    );

    public function __construct() {
        $this->enrollment_repo = new HL_Enrollment_Repository();
        $this->cohort_repo   = new HL_Cohort_Repository();
        $this->pathway_repo   = new HL_Pathway_Repository();
        $this->activity_repo  = new HL_Activity_Repository();
        $this->rules_engine   = new HL_Rules_Engine_Service();
        $this->learndash      = HL_LearnDash_Integration::instance();
        $this->jfb            = HL_JFB_Integration::instance();
    }

    /**
     * Render the My Progress shortcode.
     *
     * @param array $atts Shortcode attributes. Optional key: cohort_id.
     * @return string HTML output.
     */
    public function render($atts) {
        ob_start();

        $user_id = get_current_user_id();

        // ── Check for inline JFB form display ─────────────────────────
        $open_activity_id = isset($_GET['hl_open_activity']) ? absint($_GET['hl_open_activity']) : 0;
        if ($open_activity_id > 0 && $user_id) {
            $this->maybe_render_inline_form($open_activity_id, $user_id, $atts);
            $form_output = ob_get_clean();
            if (!empty($form_output)) {
                return $form_output;
            }
            // If render returned nothing (invalid state), fall through to normal view.
            ob_start();
        }

        // ── Fetch active enrollments for this user ──────────────────────
        $filters = array(
            'user_id' => $user_id,
            'status'  => 'active',
        );
        if (!empty($atts['cohort_id'])) {
            $filters['cohort_id'] = absint($atts['cohort_id']);
        }

        $all_enrollments = $this->enrollment_repo->get_all(array('status' => 'active'));

        // The repository's get_all does not filter by user_id directly,
        // so we filter in PHP to keep the repository generic.
        $enrollments = array_filter($all_enrollments, function ($enrollment) use ($user_id, $atts) {
            if ((int) $enrollment->user_id !== $user_id) {
                return false;
            }
            if (!empty($atts['cohort_id']) && (int) $enrollment->cohort_id !== absint($atts['cohort_id'])) {
                return false;
            }
            return true;
        });
        $enrollments = array_values($enrollments);

        // ── Empty state ─────────────────────────────────────────────────
        if (empty($enrollments)) {
            $this->render_empty_state();
            return ob_get_clean();
        }

        // ── Build per-enrollment data ───────────────────────────────────
        $cohort_blocks = array();
        foreach ($enrollments as $enrollment) {
            $cohort = $this->cohort_repo->get_by_id($enrollment->cohort_id);
            if (!$cohort) {
                continue;
            }

            $pathway    = null;
            $activities = array();
            $activity_data = array();
            $overall_percent = 0;

            if (!empty($enrollment->assigned_pathway_id)) {
                $pathway    = $this->pathway_repo->get_by_id($enrollment->assigned_pathway_id);
                $activities = $pathway ? $this->activity_repo->get_by_pathway($pathway->pathway_id) : array();

                // Filter out staff-only activities.
                $activities = array_filter($activities, function ($act) {
                    return $act->visibility !== 'staff_only';
                });
                $activities = array_values($activities);

                // Gather per-activity data.
                $total_weight    = 0;
                $weighted_done   = 0;

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

                    // For LearnDash course activities, pull live progress from LD.
                    $external_ref = $activity->get_external_ref_array();
                    $course_id    = null;
                    $course_url   = '';

                    if ($activity->activity_type === 'learndash_course' && !empty($external_ref['course_id'])) {
                        $course_id = absint($external_ref['course_id']);
                        $course_url = get_permalink($course_id);

                        // Use LD live percentage for non-completed activities.
                        if ($availability['availability_status'] !== 'completed') {
                            $ld_percent = $this->learndash->get_course_progress_percent($user_id, $course_id);
                            if ($ld_percent > $completion_percent) {
                                $completion_percent = $ld_percent;
                            }
                        }
                    }

                    // Override completion_percent for completed availability.
                    if ($availability['availability_status'] === 'completed') {
                        $completion_percent = 100;
                        $completion_status  = 'complete';
                    }

                    $weight = max((float) $activity->weight, 0);
                    $total_weight  += $weight;
                    $weighted_done += $weight * ($completion_percent / 100);

                    $activity_data[] = array(
                        'activity'            => $activity,
                        'availability'        => $availability,
                        'completion_percent'  => $completion_percent,
                        'completion_status'   => $completion_status,
                        'completed_at'        => $completed_at,
                        'course_id'           => $course_id,
                        'course_url'          => $course_url,
                        'enrollment'          => $enrollment,
                    );
                }

                $overall_percent = ($total_weight > 0)
                    ? round(($weighted_done / $total_weight) * 100)
                    : 0;
            }

            $roles_array = $enrollment->get_roles_array();
            $role_label  = !empty($roles_array)
                ? implode(', ', array_map('ucfirst', $roles_array))
                : __('Participant', 'hl-core');

            $cohort_blocks[] = array(
                'enrollment'      => $enrollment,
                'cohort'          => $cohort,
                'pathway'         => $pathway,
                'activities'      => $activity_data,
                'overall_percent' => $overall_percent,
                'role_label'      => $role_label,
            );
        }

        if (empty($cohort_blocks)) {
            $this->render_empty_state();
            return ob_get_clean();
        }

        // ── Render HTML ─────────────────────────────────────────────────
        ?>
        <div class="hl-dashboard hl-my-progress">
        <?php if (count($cohort_blocks) > 1) : ?>
            <div class="hl-cohort-tabs">
            <?php foreach ($cohort_blocks as $idx => $block) : ?>
                <button class="hl-tab<?php echo $idx === 0 ? ' active' : ''; ?>"
                        data-cohort="<?php echo esc_attr($block['cohort']->cohort_id); ?>">
                    <?php echo esc_html($block['cohort']->cohort_code . ' - ' . $this->format_year($block['cohort']->start_date)); ?>
                </button>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php foreach ($cohort_blocks as $block) :
            $cohort     = $block['cohort'];
            $pathway    = $block['pathway'];
            $enrollment = $block['enrollment'];
            $percent    = $block['overall_percent'];
        ?>
            <div class="hl-cohort-block" data-cohort-id="<?php echo esc_attr($cohort->cohort_id); ?>">
                <?php // ── Header ──────────────────────────────────────── ?>
                <div class="hl-progress-header">
                    <div class="hl-progress-header-info">
                        <h2 class="hl-cohort-title"><?php echo esc_html($cohort->cohort_code . ' - ' . $this->format_year($cohort->start_date)); ?></h2>
                        <div class="hl-cohort-meta">
                            <span class="hl-meta-item"><strong><?php esc_html_e('Role:', 'hl-core'); ?></strong> <?php echo esc_html($block['role_label']); ?></span>
                            <span class="hl-meta-item"><strong><?php esc_html_e('Pathway:', 'hl-core'); ?></strong> <?php echo $pathway ? esc_html($pathway->pathway_name) : esc_html__('Unassigned', 'hl-core'); ?></span>
                            <span class="hl-meta-item"><strong><?php esc_html_e('Status:', 'hl-core'); ?></strong> <span class="hl-badge hl-badge-<?php echo esc_attr($enrollment->status); ?>"><?php echo esc_html(ucfirst($enrollment->status)); ?></span></span>
                        </div>
                    </div>
                    <?php $this->render_progress_ring($percent); ?>
                </div>

                <?php if (!$pathway || empty($enrollment->assigned_pathway_id)) : ?>
                    <div class="hl-notice hl-notice-info">
                        <?php esc_html_e('No pathway has been assigned to your enrollment yet.', 'hl-core'); ?>
                    </div>
                <?php elseif (!empty($block['activities'])) : ?>
                    <?php // ── Activity list ─────────────────────────── ?>
                    <div class="hl-activity-list">
                        <h3 class="hl-section-title"><?php esc_html_e('Learning Activities', 'hl-core'); ?></h3>
                        <?php foreach ($block['activities'] as $ad) :
                            $this->render_activity_card($ad);
                        endforeach; ?>
                    </div>
                <?php else : ?>
                    <div class="hl-notice hl-notice-info">
                        <?php esc_html_e('No learning activities have been added to this pathway yet.', 'hl-core'); ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div>
        <?php

        return ob_get_clean();
    }

    // ─── Private helpers ────────────────────────────────────────────────

    /**
     * Render the SVG progress ring.
     *
     * @param int $percent 0-100
     */
    private function render_progress_ring($percent) {
        $percent       = max(0, min(100, (int) $percent));
        $circumference = 2 * M_PI * 52; // ~326.73
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
     * Render a single activity card.
     *
     * @param array $ad Activity data array with keys: activity, availability,
     *                  completion_percent, completion_status, completed_at,
     *                  course_id, course_url, enrollment (optional).
     */
    private function render_activity_card($ad) {
        $activity           = $ad['activity'];
        $availability       = $ad['availability'];
        $completion_percent = (int) $ad['completion_percent'];
        $completion_status  = $ad['completion_status'];
        $completed_at       = $ad['completed_at'];
        $course_url         = $ad['course_url'];
        $avail_status       = $availability['availability_status'];

        // Determine CSS modifier and icon.
        switch ($avail_status) {
            case 'completed':
                $card_class = 'hl-activity-completed';
                $bar_class  = 'hl-progress-complete';
                break;
            case 'locked':
                $card_class = 'hl-activity-locked';
                $bar_class  = 'hl-progress-locked';
                break;
            default: // available
                $card_class = 'hl-activity-available';
                $bar_class  = ($completion_percent > 0) ? 'hl-progress-active' : '';
                break;
        }

        $type_label = isset(self::$type_labels[$activity->activity_type])
            ? self::$type_labels[$activity->activity_type]
            : ucwords(str_replace('_', ' ', $activity->activity_type));

        // Build the action link/notice for available non-LearnDash activities.
        $action_html = '';
        if ($avail_status === 'available') {
            $action_html = $this->get_activity_action_html($activity, $ad);
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
                <h4 class="hl-activity-title">
                    <?php if ($avail_status === 'available' && !empty($course_url)) : ?>
                        <a href="<?php echo esc_url($course_url); ?>"><?php echo esc_html($activity->title); ?></a>
                    <?php else : ?>
                        <?php echo esc_html($activity->title); ?>
                    <?php endif; ?>
                </h4>
                <div class="hl-activity-meta">
                    <span class="hl-activity-type"><?php echo esc_html($type_label); ?></span>
                    <?php if ($avail_status === 'completed' && $completed_at) : ?>
                        <span class="hl-activity-date"><?php
                            /* translators: %s: formatted completion date */
                            printf(esc_html__('Completed %s', 'hl-core'), esc_html($this->format_date($completed_at)));
                        ?></span>
                    <?php elseif ($avail_status === 'locked') : ?>
                        <span class="hl-activity-lock-reason"><?php echo esc_html($this->get_lock_reason_text($availability)); ?></span>
                    <?php else : ?>
                        <span class="hl-activity-progress-text"><?php
                            if ($completion_percent > 0) {
                                /* translators: %d: percentage complete */
                                printf(esc_html__('%d%% complete', 'hl-core'), $completion_percent);
                            } else {
                                esc_html_e('Not started', 'hl-core');
                            }
                        ?></span>
                    <?php endif; ?>
                </div>
                <?php if (!empty($action_html)) : ?>
                    <div class="hl-activity-action">
                        <?php echo $action_html; // Already escaped in get_activity_action_html(). ?>
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
     * Build the action link/button HTML for an available activity card.
     *
     * Returns:
     * - teacher_self_assessment: "Open Form" link (URL-parameter-based)
     * - observation: notice to visit the Observations page
     * - child_assessment: notice to visit the Child Assessment page
     * - coaching_session_attendance: notice that this is managed by the coach
     * - learndash_course: empty (the title is already a link to the course)
     *
     * @param HL_Activity  $activity The activity domain object.
     * @param array        $ad       The activity data array (includes enrollment).
     * @return string Escaped HTML.
     */
    private function get_activity_action_html($activity, $ad) {
        $type = $activity->activity_type;

        // LearnDash courses already have a title link — no extra action needed.
        if ($type === 'learndash_course') {
            return '';
        }

        // ── teacher_self_assessment: custom instrument or JFB form ──────
        if ($type === 'teacher_self_assessment') {
            $external_ref = $activity->get_external_ref_array();

            // Custom instrument-based assessment
            if (!empty($external_ref['teacher_instrument_id'])) {
                $tsa_page_url = apply_filters('hl_core_teacher_assessment_page_url', '');
                if (empty($tsa_page_url)) {
                    $tsa_page_url = $this->find_page_url_by_shortcode('hl_teacher_assessment');
                }
                if (!empty($tsa_page_url)) {
                    // Find instance for this enrollment + phase
                    global $wpdb;
                    $phase = isset($external_ref['phase']) ? $external_ref['phase'] : 'pre';
                    $instance_id = $wpdb->get_var($wpdb->prepare(
                        "SELECT instance_id FROM {$wpdb->prefix}hl_teacher_assessment_instance
                         WHERE enrollment_id = %d AND cohort_id = %d AND phase = %s",
                        $ad['enrollment_id'],
                        $activity->cohort_id ?? 0,
                        $phase
                    ));

                    if ($instance_id) {
                        $tsa_page_url = add_query_arg('instance_id', $instance_id, $tsa_page_url);
                    }

                    return '<a href="' . esc_url($tsa_page_url) . '" class="hl-btn hl-btn-sm hl-btn-primary">'
                        . esc_html__('Open Assessment', 'hl-core')
                        . '</a>';
                }
                return '<span class="hl-activity-notice">'
                    . esc_html__('Visit the Self-Assessment page to complete this activity.', 'hl-core')
                    . '</span>';
            }

            // Legacy JFB-powered fallback
            $form_id = isset($external_ref['form_id']) ? absint($external_ref['form_id']) : 0;

            if (!$form_id) {
                return '<span class="hl-activity-notice">'
                    . esc_html__('No form has been configured for this activity.', 'hl-core')
                    . '</span>';
            }

            $open_url = add_query_arg(
                'hl_open_activity',
                $activity->activity_id,
                remove_query_arg('hl_open_activity')
            );

            return '<a href="' . esc_url($open_url) . '" class="hl-btn hl-btn-sm hl-btn-primary">'
                . esc_html__('Open Form', 'hl-core')
                . '</a>';
        }

        // ── observation: direct to the [hl_observations] page ───────────
        if ($type === 'observation') {
            /**
             * Filter the URL of the page containing the [hl_observations] shortcode.
             *
             * @param string $url Default empty. Themes/configs can provide the page URL.
             */
            $observations_url = apply_filters('hl_core_observations_page_url', '');

            if (!empty($observations_url)) {
                return '<a href="' . esc_url($observations_url) . '" class="hl-btn hl-btn-sm hl-btn-secondary">'
                    . esc_html__('Go to Observations', 'hl-core')
                    . '</a>';
            }

            return '<span class="hl-activity-notice">'
                . esc_html__('Visit the Observations page to submit observations.', 'hl-core')
                . '</span>';
        }

        // ── child_assessment: direct to the [hl_child_assessment] page ─
        if ($type === 'child_assessment') {
            /**
             * Filter the URL of the page containing the [hl_child_assessment] shortcode.
             *
             * @param string $url Default empty.
             */
            $assessment_url = apply_filters('hl_core_child_assessment_page_url', '');

            if (!empty($assessment_url)) {
                return '<a href="' . esc_url($assessment_url) . '" class="hl-btn hl-btn-sm hl-btn-secondary">'
                    . esc_html__('Go to Child Assessment', 'hl-core')
                    . '</a>';
            }

            return '<span class="hl-activity-notice">'
                . esc_html__('Visit the Child Assessment page to complete assessments.', 'hl-core')
                . '</span>';
        }

        // ── coaching_session_attendance: admin-managed ────────────────────
        if ($type === 'coaching_session_attendance') {
            return '<span class="hl-activity-notice">'
                . esc_html__('Managed by your coach.', 'hl-core')
                . '</span>';
        }

        return '';
    }

    /**
     * Render a JFB form inline for a teacher_self_assessment activity.
     *
     * Validates:
     * - The activity exists and is teacher_self_assessment type
     * - The current user has an active enrollment that includes this activity
     * - The activity is available (not locked or already completed)
     * - A valid JFB form_id is configured in external_ref
     *
     * Outputs the form wrapped in a "Back to Progress" navigation header.
     *
     * @param int   $activity_id The activity to open.
     * @param int   $user_id     Current user ID.
     * @param array $atts        Shortcode attributes (for cohort_id filtering).
     */
    private function maybe_render_inline_form($activity_id, $user_id, $atts) {
        // Load the activity.
        $activity = $this->activity_repo->get_by_id($activity_id);

        if (!$activity) {
            return; // Falls through to normal view.
        }

        // Only teacher_self_assessment activities can be rendered inline.
        if ($activity->activity_type !== 'teacher_self_assessment') {
            return;
        }

        // Check that a JFB form is configured.
        $external_ref = $activity->get_external_ref_array();
        $form_id      = isset($external_ref['form_id']) ? absint($external_ref['form_id']) : 0;

        if (!$form_id) {
            return;
        }

        // Find an active enrollment for this user that includes this activity's pathway.
        $all_enrollments = $this->enrollment_repo->get_all(array('status' => 'active'));
        $enrollment      = null;

        foreach ($all_enrollments as $e) {
            if ((int) $e->user_id !== $user_id) {
                continue;
            }
            if (!empty($atts['cohort_id']) && (int) $e->cohort_id !== absint($atts['cohort_id'])) {
                continue;
            }
            if ((int) $e->cohort_id === (int) $activity->cohort_id) {
                $enrollment = $e;
                break;
            }
        }

        if (!$enrollment) {
            return; // User is not enrolled in the cohort for this activity.
        }

        // Verify the activity is available (not locked, not completed).
        $availability = $this->rules_engine->compute_availability(
            $enrollment->enrollment_id,
            $activity->activity_id
        );

        if ($availability['availability_status'] !== 'available') {
            // Activity is locked or already completed — don't show form.
            ?>
            <div class="hl-dashboard hl-my-progress">
                <div class="hl-inline-form-wrapper">
                    <a href="<?php echo esc_url(remove_query_arg('hl_open_activity')); ?>" class="hl-back-link">
                        &larr; <?php esc_html_e('Back to Progress', 'hl-core'); ?>
                    </a>
                    <div class="hl-notice hl-notice-info">
                        <?php if ($availability['availability_status'] === 'completed') : ?>
                            <?php esc_html_e('This activity has already been completed.', 'hl-core'); ?>
                        <?php else : ?>
                            <?php esc_html_e('This activity is currently locked.', 'hl-core'); ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php
            return;
        }

        // Build the hidden fields for the JFB form.
        $hidden_fields = array(
            'hl_enrollment_id' => $enrollment->enrollment_id,
            'hl_activity_id'   => $activity->activity_id,
            'hl_cohort_id'     => $enrollment->cohort_id,
        );

        // Render the inline form view.
        ?>
        <div class="hl-dashboard hl-my-progress">
            <div class="hl-inline-form-wrapper">
                <a href="<?php echo esc_url(remove_query_arg('hl_open_activity')); ?>" class="hl-back-link">
                    &larr; <?php esc_html_e('Back to Progress', 'hl-core'); ?>
                </a>

                <h2 class="hl-inline-form-title"><?php echo esc_html($activity->title); ?></h2>

                <?php if (!empty($activity->description)) : ?>
                    <p class="hl-inline-form-description"><?php echo esc_html($activity->description); ?></p>
                <?php endif; ?>

                <div class="hl-jfb-form-container">
                    <?php
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_form() returns JFB shortcode output
                    echo $this->jfb->render_form($form_id, $hidden_fields);
                    ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render a friendly empty state when the user has no active enrollments.
     */
    private function render_empty_state() {
        ?>
        <div class="hl-dashboard hl-my-progress">
            <div class="hl-empty-state">
                <h3><?php esc_html_e('No Active Enrollments', 'hl-core'); ?></h3>
                <p><?php esc_html_e('You are not currently enrolled in any active cohorts. If you believe this is an error, please contact your cohort administrator.', 'hl-core'); ?></p>
            </div>
        </div>
        <?php
    }

    /**
     * Build a human-readable lock reason string.
     *
     * @param array $availability The availability array from the rules engine.
     * @return string
     */
    private function get_lock_reason_text($availability) {
        $reason = $availability['locked_reason'];

        if ($reason === 'prereq') {
            return __('Locked: Complete prerequisites first', 'hl-core');
        }

        if ($reason === 'drip' && !empty($availability['next_available_at'])) {
            /* translators: %s: date when the activity becomes available */
            return sprintf(
                __('Available on %s', 'hl-core'),
                $this->format_date($availability['next_available_at'])
            );
        }

        if ($reason === 'drip') {
            return __('Locked: Not yet available', 'hl-core');
        }

        return __('Locked', 'hl-core');
    }

    /**
     * Query the hl_activity_state table for a single enrollment + activity.
     *
     * @param int $enrollment_id
     * @param int $activity_id
     * @return array|null Row data or null.
     */
    private function get_activity_state($enrollment_id, $activity_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'hl_activity_state';

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE enrollment_id = %d AND activity_id = %d",
                $enrollment_id,
                $activity_id
            ),
            ARRAY_A
        );
    }

    /**
     * Format a date/datetime string for display.
     *
     * Uses the WordPress site date format setting.
     *
     * @param string $date_string MySQL date or datetime string.
     * @return string Formatted date.
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
     * Extract a 4-digit year from a date string, with fallback.
     *
     * @param string|null $date_string
     * @return string Year or current year if unparseable.
     */
    private function format_year($date_string) {
        if (empty($date_string)) {
            return date_i18n('Y');
        }
        $timestamp = strtotime($date_string);
        if ($timestamp === false) {
            return date_i18n('Y');
        }
        return date_i18n('Y', $timestamp);
    }

    /**
     * Find a page URL by its shortcode content.
     *
     * @param string $shortcode Shortcode tag (without brackets).
     * @return string Page permalink or empty string.
     */
    private function find_page_url_by_shortcode($shortcode) {
        global $wpdb;
        $page_id = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'page' AND post_status = 'publish'
               AND post_content LIKE %s
             LIMIT 1",
            '%[' . $wpdb->esc_like($shortcode) . ']%'
        ));
        return $page_id ? get_permalink($page_id) : '';
    }
}
