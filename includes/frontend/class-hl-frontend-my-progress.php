<?php
if (!defined('ABSPATH')) exit;

/**
 * Renderer for the [hl_my_progress] shortcode.
 *
 * Shows a logged-in participant their learning progress across
 * one or more track enrollments, including per-component status,
 * LearnDash course progress, and overall pathway completion.
 *
 * @package HL_Core
 */
class HL_Frontend_My_Progress {

    /** @var HL_Enrollment_Repository */
    private $enrollment_repo;

    /** @var HL_Cycle_Repository */
    private $cycle_repo;

    /** @var HL_Pathway_Repository */
    private $pathway_repo;

    /** @var HL_Component_Repository */
    private $component_repo;

    /** @var HL_Rules_Engine_Service */
    private $rules_engine;

    /** @var HL_LearnDash_Integration */
    private $learndash;

    /** @var HL_JFB_Integration */
    private $jfb;

    /**
     * Component type display labels.
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
        $this->cycle_repo   = new HL_Cycle_Repository();
        $this->pathway_repo   = new HL_Pathway_Repository();
        $this->component_repo = new HL_Component_Repository();
        $this->rules_engine   = new HL_Rules_Engine_Service();
        $this->learndash      = HL_LearnDash_Integration::instance();
        $this->jfb            = HL_JFB_Integration::instance();
    }

    /**
     * Render the My Progress shortcode.
     *
     * @param array $atts Shortcode attributes. Optional key: cycle_id.
     * @return string HTML output.
     */
    public function render($atts) {
        ob_start();

        $user_id = get_current_user_id();

        // ── Check for inline JFB form display ─────────────────────────
        $open_component_id = isset($_GET['hl_open_component']) ? absint($_GET['hl_open_component']) : 0;
        if ($open_component_id > 0 && $user_id) {
            $this->maybe_render_inline_form($open_component_id, $user_id, $atts);
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
        if (!empty($atts['cycle_id'])) {
            $filters['cycle_id'] = absint($atts['cycle_id']);
        }

        $all_enrollments = $this->enrollment_repo->get_all(array('status' => 'active'));

        // The repository's get_all does not filter by user_id directly,
        // so we filter in PHP to keep the repository generic.
        $enrollments = array_filter($all_enrollments, function ($enrollment) use ($user_id, $atts) {
            if ((int) $enrollment->user_id !== $user_id) {
                return false;
            }
            if (!empty($atts['cycle_id']) && (int) $enrollment->cycle_id !== absint($atts['cycle_id'])) {
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
        $track_blocks = array();
        foreach ($enrollments as $enrollment) {
            $cycle = $this->cycle_repo->get_by_id($enrollment->cycle_id);
            if (!$cycle) {
                continue;
            }

            $pathway    = null;
            $components = array();
            $component_data = array();
            $overall_percent = 0;

            if (!empty($enrollment->assigned_pathway_id)) {
                $pathway    = $this->pathway_repo->get_by_id($enrollment->assigned_pathway_id);
                $components = $pathway ? $this->component_repo->get_by_pathway($pathway->pathway_id) : array();

                // Filter out staff-only components.
                $components = array_filter($components, function ($act) {
                    return $act->visibility !== 'staff_only';
                });
                $components = array_values($components);

                // Gather per-component data.
                $total_weight    = 0;
                $weighted_done   = 0;

                foreach ($components as $component) {
                    $availability = $this->rules_engine->compute_availability(
                        $enrollment->enrollment_id,
                        $component->component_id
                    );

                    // Ineligible components: add to display but skip weight.
                    if ($availability['availability_status'] === 'not_applicable') {
                        $component_data[] = array(
                            'component'           => $component,
                            'availability'        => $availability,
                            'completion_percent'  => 0,
                            'completion_status'   => 'not_applicable',
                            'completed_at'        => null,
                            'course_id'           => null,
                            'course_url'          => '',
                            'enrollment'          => $enrollment,
                        );
                        continue;
                    }

                    $state = $this->get_component_state(
                        $enrollment->enrollment_id,
                        $component->component_id
                    );

                    $completion_percent = $state ? (float) $state['completion_percent'] : 0;
                    $completion_status  = $state ? $state['completion_status'] : 'not_started';
                    $completed_at       = $state ? $state['completed_at'] : null;

                    // For LearnDash course components, pull live progress from LD.
                    $external_ref = $component->get_external_ref_array();
                    $course_id    = null;
                    $course_url   = '';

                    if ($component->component_type === 'learndash_course' && !empty($external_ref['course_id'])) {
                        $course_id = absint($external_ref['course_id']);
                        $course_url = get_permalink($course_id);

                        // Use LD live percentage for non-completed components.
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

                    $weight = max((float) $component->weight, 0);
                    $total_weight  += $weight;
                    $weighted_done += $weight * ($completion_percent / 100);

                    $component_data[] = array(
                        'component'           => $component,
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

            $track_blocks[] = array(
                'enrollment'      => $enrollment,
                'cycle'           => $cycle,
                'pathway'         => $pathway,
                'components'      => $component_data,
                'overall_percent' => $overall_percent,
                'role_label'      => $role_label,
            );
        }

        if (empty($track_blocks)) {
            $this->render_empty_state();
            return ob_get_clean();
        }

        // ── Render HTML ─────────────────────────────────────────────────
        ?>
        <div class="hl-dashboard hl-my-progress">
        <?php if (count($track_blocks) > 1) : ?>
            <div class="hl-track-tabs">
            <?php foreach ($track_blocks as $idx => $block) : ?>
                <button class="hl-tab<?php echo $idx === 0 ? ' active' : ''; ?>"
                        data-cycle="<?php echo esc_attr($block['cycle']->cycle_id); ?>">
                    <?php echo esc_html($block['cycle']->cycle_code . ' - ' . $this->format_year($block['cycle']->start_date)); ?>
                </button>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php foreach ($track_blocks as $block) :
            $cycle      = $block['cycle'];
            $pathway    = $block['pathway'];
            $enrollment = $block['enrollment'];
            $percent    = $block['overall_percent'];
        ?>
            <div class="hl-cycle-block" data-cycle-id="<?php echo esc_attr($cycle->cycle_id); ?>">
                <?php // ── Header ──────────────────────────────────────── ?>
                <div class="hl-progress-header">
                    <div class="hl-progress-header-info">
                        <h2 class="hl-cycle-title"><?php echo esc_html($cycle->cycle_code . ' - ' . $this->format_year($cycle->start_date)); ?></h2>
                        <div class="hl-track-meta">
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
                <?php elseif (!empty($block['components'])) : ?>
                    <?php // ── Component list ─────────────────────────── ?>
                    <div class="hl-component-list">
                        <h3 class="hl-section-title"><?php esc_html_e('Learning Components', 'hl-core'); ?></h3>
                        <?php foreach ($block['components'] as $ad) :
                            $this->render_component_card($ad);
                        endforeach; ?>
                    </div>
                <?php else : ?>
                    <div class="hl-notice hl-notice-info">
                        <?php esc_html_e('No learning components have been added to this pathway yet.', 'hl-core'); ?>
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
     * Render a single component card.
     *
     * @param array $ad Component data array with keys: component, availability,
     *                  completion_percent, completion_status, completed_at,
     *                  course_id, course_url, enrollment (optional).
     */
    private function render_component_card($ad) {
        $component          = $ad['component'];
        $availability       = $ad['availability'];
        $completion_percent = (int) $ad['completion_percent'];
        $completion_status  = $ad['completion_status'];
        $completed_at       = $ad['completed_at'];
        $course_url         = $ad['course_url'];
        $avail_status       = $availability['availability_status'];

        // Determine CSS modifier and icon.
        switch ($avail_status) {
            case 'completed':
                $card_class = 'hl-component-completed';
                $bar_class  = 'hl-progress-complete';
                break;
            case 'locked':
                $card_class = 'hl-component-locked';
                $bar_class  = 'hl-progress-locked';
                break;
            case 'not_applicable':
                $card_class = 'hl-component-not-applicable';
                $bar_class  = '';
                break;
            default: // available
                $card_class = 'hl-component-available';
                $bar_class  = ($completion_percent > 0) ? 'hl-progress-active' : '';
                break;
        }

        $type_label = isset(self::$type_labels[$component->component_type])
            ? self::$type_labels[$component->component_type]
            : ucwords(str_replace('_', ' ', $component->component_type));

        // Build the action link/notice for available non-LearnDash components.
        $action_html = '';
        if ($avail_status === 'available') {
            $action_html = $this->get_component_action_html($component, $ad);
        }
        ?>
        <div class="hl-component-card <?php echo esc_attr($card_class); ?>">
            <div class="hl-component-status-icon">
                <?php if ($avail_status === 'completed') : ?>
                    <span class="hl-icon-check">&#10003;</span>
                <?php elseif ($avail_status === 'not_applicable') : ?>
                    <span class="hl-icon-na">&#8212;</span>
                <?php elseif ($avail_status === 'locked') : ?>
                    <span class="hl-icon-lock">&#128274;</span>
                <?php else : ?>
                    <span class="hl-icon-progress">&#9654;</span>
                <?php endif; ?>
            </div>
            <div class="hl-component-content">
                <h4 class="hl-component-title">
                    <?php if ($avail_status === 'available' && !empty($course_url)) : ?>
                        <a href="<?php echo esc_url($course_url); ?>"><?php echo esc_html($component->title); ?></a>
                    <?php else : ?>
                        <?php echo esc_html($component->title); ?>
                    <?php endif; ?>
                </h4>
                <div class="hl-component-meta">
                    <span class="hl-component-type"><?php echo esc_html($type_label); ?></span>
                    <?php if ($avail_status === 'completed' && $completed_at) : ?>
                        <span class="hl-component-date"><?php
                            /* translators: %s: formatted completion date */
                            printf(esc_html__('Completed %s', 'hl-core'), esc_html($this->format_date($completed_at)));
                        ?></span>
                    <?php elseif ($avail_status === 'not_applicable') : ?>
                        <span class="hl-component-progress-text"><?php esc_html_e('Not applicable to your role', 'hl-core'); ?></span>
                    <?php elseif ($avail_status === 'locked') : ?>
                        <span class="hl-component-lock-reason"><?php echo esc_html($this->get_lock_reason_text($availability)); ?></span>
                    <?php else : ?>
                        <span class="hl-component-progress-text"><?php
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
                    <div class="hl-component-action">
                        <?php echo $action_html; // Already escaped in get_component_action_html(). ?>
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
     * Build the action link/button HTML for an available component card.
     *
     * Returns:
     * - teacher_self_assessment: "Open Form" link (URL-parameter-based)
     * - observation: notice to visit the Observations page
     * - child_assessment: notice to visit the Child Assessment page
     * - coaching_session_attendance: notice that this is managed by the coach
     * - learndash_course: empty (the title is already a link to the course)
     *
     * @param HL_Component $component The component domain object.
     * @param array        $ad        The component data array (includes enrollment).
     * @return string Escaped HTML.
     */
    private function get_component_action_html($component, $ad) {
        $type = $component->component_type;

        // LearnDash courses already have a title link — no extra action needed.
        if ($type === 'learndash_course') {
            return '';
        }

        // ── teacher_self_assessment: custom instrument or JFB form ──────
        if ($type === 'teacher_self_assessment') {
            $external_ref = $component->get_external_ref_array();

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
                    $enrollment_id_val = isset($ad['enrollment_id']) ? (int) $ad['enrollment_id'] : 0;
                    $instance_id = $enrollment_id_val ? $wpdb->get_var($wpdb->prepare(
                        "SELECT instance_id FROM {$wpdb->prefix}hl_teacher_assessment_instance
                         WHERE enrollment_id = %d AND cycle_id = %d AND phase = %s",
                        $enrollment_id_val,
                        $component->cycle_id ?? 0,
                        $phase
                    )) : null;

                    if ($instance_id) {
                        $tsa_page_url = add_query_arg('instance_id', $instance_id, $tsa_page_url);
                    }

                    return '<a href="' . esc_url($tsa_page_url) . '" class="hl-btn hl-btn-sm hl-btn-primary">'
                        . esc_html__('Open Assessment', 'hl-core')
                        . '</a>';
                }
                return '<span class="hl-component-notice">'
                    . esc_html__('Visit the Self-Assessment page to complete this component.', 'hl-core')
                    . '</span>';
            }

            // Legacy JFB-powered fallback
            $form_id = isset($external_ref['form_id']) ? absint($external_ref['form_id']) : 0;

            if (!$form_id) {
                return '<span class="hl-component-notice">'
                    . esc_html__('No form has been configured for this component.', 'hl-core')
                    . '</span>';
            }

            $open_url = add_query_arg(
                'hl_open_component',
                $component->component_id,
                remove_query_arg('hl_open_component')
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

            return '<span class="hl-component-notice">'
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

            return '<span class="hl-component-notice">'
                . esc_html__('Visit the Child Assessment page to complete assessments.', 'hl-core')
                . '</span>';
        }

        // ── coaching_session_attendance: admin-managed ────────────────────
        if ($type === 'coaching_session_attendance') {
            return '<span class="hl-component-notice">'
                . esc_html__('Managed by your coach.', 'hl-core')
                . '</span>';
        }

        return '';
    }

    /**
     * Render a JFB form inline for a teacher_self_assessment component.
     *
     * Validates:
     * - The component exists and is teacher_self_assessment type
     * - The current user has an active enrollment that includes this component
     * - The component is available (not locked or already completed)
     * - A valid JFB form_id is configured in external_ref
     *
     * Outputs the form wrapped in a "Back to Progress" navigation header.
     *
     * @param int   $component_id The component to open.
     * @param int   $user_id      Current user ID.
     * @param array $atts         Shortcode attributes (for cycle_id filtering).
     */
    private function maybe_render_inline_form($component_id, $user_id, $atts) {
        // Load the component.
        $component = $this->component_repo->get_by_id($component_id);

        if (!$component) {
            return; // Falls through to normal view.
        }

        // Only teacher_self_assessment components can be rendered inline.
        if ($component->component_type !== 'teacher_self_assessment') {
            return;
        }

        // Check that a JFB form is configured.
        $external_ref = $component->get_external_ref_array();
        $form_id      = isset($external_ref['form_id']) ? absint($external_ref['form_id']) : 0;

        if (!$form_id) {
            return;
        }

        // Find an active enrollment for this user that includes this component's pathway.
        $all_enrollments = $this->enrollment_repo->get_all(array('status' => 'active'));
        $enrollment      = null;

        foreach ($all_enrollments as $e) {
            if ((int) $e->user_id !== $user_id) {
                continue;
            }
            if (!empty($atts['cycle_id']) && (int) $e->cycle_id !== absint($atts['cycle_id'])) {
                continue;
            }
            if ((int) $e->cycle_id === (int) $component->cycle_id) {
                $enrollment = $e;
                break;
            }
        }

        if (!$enrollment) {
            return; // User is not enrolled in the track for this component.
        }

        // Verify the component is available (not locked, not completed).
        $availability = $this->rules_engine->compute_availability(
            $enrollment->enrollment_id,
            $component->component_id
        );

        if ($availability['availability_status'] !== 'available') {
            // Component is locked or already completed — don't show form.
            ?>
            <div class="hl-dashboard hl-my-progress">
                <div class="hl-inline-form-wrapper">
                    <a href="<?php echo esc_url(remove_query_arg('hl_open_component')); ?>" class="hl-back-link">
                        &larr; <?php esc_html_e('Back to Progress', 'hl-core'); ?>
                    </a>
                    <div class="hl-notice hl-notice-info">
                        <?php if ($availability['availability_status'] === 'completed') : ?>
                            <?php esc_html_e('This component has already been completed.', 'hl-core'); ?>
                        <?php else : ?>
                            <?php esc_html_e('This component is currently locked.', 'hl-core'); ?>
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
            'hl_component_id'  => $component->component_id,
                  'hl_cycle_id'     => $enrollment->cycle_id,
        );

        // Render the inline form view.
        ?>
        <div class="hl-dashboard hl-my-progress">
            <div class="hl-inline-form-wrapper">
                <a href="<?php echo esc_url(remove_query_arg('hl_open_component')); ?>" class="hl-back-link">
                    &larr; <?php esc_html_e('Back to Progress', 'hl-core'); ?>
                </a>

                <h2 class="hl-inline-form-title"><?php echo esc_html($component->title); ?></h2>

                <?php if (!empty($component->description)) : ?>
                    <p class="hl-inline-form-description"><?php echo esc_html($component->description); ?></p>
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
                <p><?php esc_html_e('You are not currently enrolled in any active tracks. If you believe this is an error, please contact your Program Manager.', 'hl-core'); ?></p>
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
            /* translators: %s: date when the component becomes available */
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
     * Query the hl_component_state table for a single enrollment + component.
     *
     * @param int $enrollment_id
     * @param int $component_id
     * @return array|null Row data or null.
     */
    private function get_component_state($enrollment_id, $component_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'hl_component_state';

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE enrollment_id = %d AND component_id = %d",
                $enrollment_id,
                $component_id
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
