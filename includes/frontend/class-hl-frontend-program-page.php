<?php
if (!defined('ABSPATH')) exit;

/**
 * Renderer for the [hl_program_page] shortcode.
 *
 * Shows a single program (pathway) detail page with:
 * - Hero image, name, description
 * - Details panel (avg time, expiration, status)
 * - Objectives, syllabus link
 * - Component cards with per-component status and actions
 *
 * URL parameters: ?id={pathway_id}&enrollment={enrollment_id}
 *
 * @package HL_Core
 */
class HL_Frontend_Program_Page {

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

    /**
     * Component type display labels.
     */
    private static $type_labels = array(
        'learndash_course'             => 'Course',
        'teacher_self_assessment'      => 'Self-Assessment',
        'child_assessment'          => 'Child Assessment',
        'coaching_session_attendance'  => 'Coaching Session',
    );

    public function __construct() {
        $this->enrollment_repo = new HL_Enrollment_Repository();
        $this->cycle_repo     = new HL_Cycle_Repository();
        $this->pathway_repo    = new HL_Pathway_Repository();
        $this->component_repo  = new HL_Component_Repository();
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

        // Verify enrollment has access to this pathway (via assignment service or legacy column).
        $pa_service = new HL_Pathway_Assignment_Service();
        $has_access = $pa_service->enrollment_has_pathway($enrollment_id, $pathway_id);
        if (!$has_access) {
            // Legacy fallback: check assigned_pathway_id.
            $has_access = ((int) $enrollment->assigned_pathway_id === (int) $pathway->pathway_id);
        }
        if (!$has_access) {
            echo '<div class="hl-dashboard hl-program-page">';
            echo '<div class="hl-notice hl-notice-error">' . esc_html__('This program is not assigned to your enrollment.', 'hl-core') . '</div>';
            echo '</div>';
            return ob_get_clean();
        }

        $cycle = $this->cycle_repo->get_by_id($enrollment->cycle_id);

        // Load components and compute per-component data.
        $components = $this->component_repo->get_by_pathway($pathway->pathway_id);
        $components = array_filter($components, function ($act) {
            return $act->visibility !== 'staff_only';
        });
        $components = array_values($components);

        $total_weight  = 0;
        $weighted_done = 0;
        $component_data = array();

        // Batch-load assessment instance statuses for this enrollment.
        $assessment_statuses = $this->get_assessment_statuses($enrollment);

        foreach ($components as $component) {
            $availability = $this->rules_engine->compute_availability(
                $enrollment->enrollment_id,
                $component->component_id
            );

            // Ineligible components: add to display but skip weight.
            if ($availability['availability_status'] === 'not_applicable') {
                $component_data[] = array(
                    'component'          => $component,
                    'availability'       => $availability,
                    'completion_percent' => 0,
                    'completion_status'  => 'not_applicable',
                    'completed_at'       => null,
                    'course_url'         => '',
                    'assess_status'      => 'not_started',
                    'children_counts'    => null,
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

            $course_url = '';

            if ($component->component_type === 'learndash_course') {
                $course_id = HL_Course_Catalog::resolve_ld_course_id($component, $enrollment);
                if ($course_id) {
                    $course_url = get_permalink($course_id);

                    if ($availability['availability_status'] !== 'completed') {
                        $ld_percent = $this->learndash->get_course_progress_percent($user_id, $course_id);
                        if ($ld_percent > $completion_percent) {
                            $completion_percent = $ld_percent;
                        }
                    }
                }
            }

            if ($availability['availability_status'] === 'completed') {
                $completion_percent = 100;
                $completion_status  = 'complete';
            }

            // Resolve assessment instance status for status-aware buttons.
            $assess_status   = 'not_started';
            $children_counts = null;
            $aid = (int) $component->component_id;
            if (isset($assessment_statuses['teacher'][$aid])) {
                $assess_status = $assessment_statuses['teacher'][$aid];
            } elseif (isset($assessment_statuses['children'][$aid])) {
                $assess_status = $assessment_statuses['children'][$aid];
            }
            if (isset($assessment_statuses['children_counts'][$aid])) {
                $children_counts = $assessment_statuses['children_counts'][$aid];
            }

            $weight = max((float) $component->weight, 0);
            $total_weight  += $weight;
            $weighted_done += $weight * ($completion_percent / 100);

            $component_data[] = array(
                'component'          => $component,
                'availability'       => $availability,
                'completion_percent' => $completion_percent,
                'completion_status'  => $completion_status,
                'completed_at'       => $completed_at,
                'course_url'         => $course_url,
                'assess_status'      => $assess_status,
                'children_counts'    => $children_counts,
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
        } elseif ($cycle && $cycle->status === 'paused') {
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
        // Count component types for sidebar stats.
        $type_counts = array('courses' => 0, 'visits' => 0, 'other' => 0);
        foreach ($component_data as $cd) {
            $ct = $cd['component']->component_type;
            if ($ct === 'learndash_course') {
                $type_counts['courses']++;
            } elseif ($ct === 'classroom_visit') {
                $type_counts['visits']++;
            } else {
                $type_counts['other']++;
            }
        }
        $completed_count = 0;
        foreach ($component_data as $cd) {
            if ($cd['availability']['availability_status'] === 'completed') {
                $completed_count++;
            }
        }
        $total_components = count($component_data);

        // Pathway label for badge.
        $pathway_label = '';
        if (stripos($pathway->pathway_name, 'teacher') !== false) {
            $pathway_label = __('Teacher Learning Plan', 'hl-core');
        } elseif (stripos($pathway->pathway_name, 'mentor') !== false) {
            $pathway_label = __('Mentor Learning Plan', 'hl-core');
        } elseif (stripos($pathway->pathway_name, 'leader') !== false) {
            $pathway_label = __('Leader Learning Plan', 'hl-core');
        } else {
            $pathway_label = __('Learning Plan', 'hl-core');
        }

        // Program status label.
        $status_label = ($overall_percent >= 100) ? __('Completed', 'hl-core') : __('In Progress', 'hl-core');
        if ($overall_percent <= 0) {
            $status_label = __('Not Started', 'hl-core');
        }
        ?>
        <div class="hl-dashboard hl-program-page hl-program-page-v2">

            <?php if (!empty($my_programs_url)) : ?>
                <a href="<?php echo esc_url($my_programs_url); ?>" class="hl-back-link">&larr; <?php esc_html_e('Back to My Programs', 'hl-core'); ?></a>
            <?php endif; ?>

            <!-- Hero Banner -->
            <div class="hl-pp-hero">
                <div class="hl-pp-hero-card">
                    <div class="hl-pp-hero-text">
                        <div class="hl-pp-hero-badge"><?php echo esc_html($pathway_label); ?></div>
                        <h1 class="hl-pp-hero-title"><?php echo esc_html($this->translate_pathway_field($pathway, 'pathway_name')); ?></h1>
                        <p class="hl-pp-hero-subtitle"><?php echo esc_html($cycle ? $cycle->cycle_name : ''); ?></p>
                    </div>
                    <?php if ($image_id) : ?>
                        <div class="hl-pp-hero-image">
                            <?php echo wp_get_attachment_image($image_id, 'large', false, array('loading' => 'lazy')); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Two-Column Layout -->
            <div class="hl-pp-layout">

                <!-- Main Content -->
                <div class="hl-pp-main">

                    <?php
                    $translated_description = $this->translate_pathway_field($pathway, 'description');
                    if (!empty($translated_description)) : ?>
                        <div class="hl-pp-about"><?php echo wp_kses_post($translated_description); ?></div>
                    <?php endif; ?>

                    <!-- Expandable Sections -->
                    <?php
                    $translated_objectives = $this->translate_pathway_field($pathway, 'objectives');
                    $has_objectives = !empty($translated_objectives);
                    $has_syllabus   = !empty($pathway->syllabus_url);
                    if ($has_objectives || $has_syllabus) :
                    ?>
                        <div class="hl-pp-toggles">
                            <?php if ($has_objectives) : ?>
                                <button class="hl-pp-toggle-btn" onclick="hlTogglePanel('hl-pp-objectives', this)">&#x1F3AF; <?php esc_html_e('Objectives', 'hl-core'); ?></button>
                            <?php endif; ?>
                            <?php if ($has_syllabus) : ?>
                                <button class="hl-pp-toggle-btn" onclick="hlTogglePanel('hl-pp-syllabus', this)">&#x1F4D6; <?php esc_html_e('Resources', 'hl-core'); ?></button>
                            <?php endif; ?>
                        </div>

                        <?php if ($has_objectives) : ?>
                            <div class="hl-pp-panel" id="hl-pp-objectives">
                                <h3><?php esc_html_e('Program Objectives', 'hl-core'); ?></h3>
                                <?php echo wp_kses_post($translated_objectives); ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($has_syllabus) : ?>
                            <div class="hl-pp-panel" id="hl-pp-syllabus">
                                <h3><?php esc_html_e('Program Resources', 'hl-core'); ?></h3>
                                <p><?php esc_html_e('Access your program materials and resources.', 'hl-core'); ?></p>
                                <a href="<?php echo esc_url($pathway->syllabus_url); ?>" target="_blank" class="hl-pp-syllabus-link">&#x1F4E5; <?php esc_html_e('Access Materials', 'hl-core'); ?></a>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <!-- Program Steps -->
                    <?php if (!empty($component_data)) : ?>
                        <div class="hl-pp-section-label">
                            <?php esc_html_e('Program Steps', 'hl-core'); ?>
                            <span class="hl-pp-section-count"><?php echo esc_html($total_components); ?></span>
                        </div>
                        <?php foreach ($component_data as $ad) :
                            $this->render_component_card_v2($ad, $pathway, $enrollment);
                        endforeach; ?>
                    <?php else : ?>
                        <div class="hl-notice hl-notice-info">
                            <?php esc_html_e('No learning components have been added to this program yet.', 'hl-core'); ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Sidebar -->
                <div class="hl-pp-sidebar">

                    <!-- Progress + Stats -->
                    <div class="hl-pp-sidebar-card">
                        <div class="hl-pp-progress-combo">
                            <?php $this->render_progress_ring_v2($overall_percent, $completed_count, $total_components, $status_label); ?>
                            <div class="hl-pp-stats-row">
                                <div class="hl-pp-stat">
                                    <div class="hl-pp-stat-num"><?php echo esc_html($type_counts['courses']); ?></div>
                                    <div class="hl-pp-stat-lbl"><?php esc_html_e('Courses', 'hl-core'); ?></div>
                                </div>
                                <div class="hl-pp-stat">
                                    <div class="hl-pp-stat-num"><?php echo esc_html($type_counts['visits']); ?></div>
                                    <div class="hl-pp-stat-lbl"><?php esc_html_e('Visits', 'hl-core'); ?></div>
                                </div>
                                <div class="hl-pp-stat">
                                    <div class="hl-pp-stat-num"><?php echo esc_html($type_counts['other']); ?></div>
                                    <div class="hl-pp-stat-lbl"><?php esc_html_e('Other', 'hl-core'); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Details -->
                    <div class="hl-pp-sidebar-card hl-pp-details">
                        <?php if (!empty($pathway->avg_completion_time)) : ?>
                            <div class="hl-pp-detail-row">
                                <div class="hl-pp-detail-icon">&#x23F1;</div>
                                <div>
                                    <div class="hl-pp-detail-label"><?php esc_html_e('Avg. Completion Time', 'hl-core'); ?></div>
                                    <div class="hl-pp-detail-value"><?php echo esc_html($pathway->avg_completion_time); ?></div>
                                </div>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($pathway->expiration_date)) : ?>
                            <div class="hl-pp-detail-row">
                                <div class="hl-pp-detail-icon">&#x1F4C5;</div>
                                <div>
                                    <div class="hl-pp-detail-label"><?php esc_html_e('Learning Plan Ends', 'hl-core'); ?></div>
                                    <div class="hl-pp-detail-value"><?php echo esc_html($this->format_date($pathway->expiration_date)); ?></div>
                                </div>
                            </div>
                        <?php endif; ?>
                        <?php if ($cycle) : ?>
                            <div class="hl-pp-detail-row">
                                <div class="hl-pp-detail-icon">&#x1F504;</div>
                                <div>
                                    <div class="hl-pp-detail-label"><?php esc_html_e('Cycle', 'hl-core'); ?></div>
                                    <div class="hl-pp-detail-value"><?php echo esc_html($cycle->cycle_name); ?></div>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div class="hl-pp-detail-row">
                            <div class="hl-pp-detail-icon">&#x1F4CA;</div>
                            <div>
                                <div class="hl-pp-detail-label"><?php esc_html_e('Status', 'hl-core'); ?></div>
                                <div class="hl-pp-detail-value"><?php echo esc_html($program_status); ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Certificate -->
                    <div class="hl-pp-sidebar-card">
                        <div class="hl-pp-cert">
                            <?php if ($overall_percent >= 100) : ?>
                                <div class="hl-pp-cert-icon available">&#x1F3C6;</div>
                                <div>
                                    <div class="hl-pp-cert-title"><?php esc_html_e('Certificate', 'hl-core'); ?></div>
                                    <div class="hl-pp-cert-desc"><?php esc_html_e('Congratulations!', 'hl-core'); ?></div>
                                </div>
                                <a href="#" class="hl-pp-cert-btn"><?php esc_html_e('Download', 'hl-core'); ?></a>
                            <?php else : ?>
                                <div class="hl-pp-cert-icon">&#x1F512;</div>
                                <div>
                                    <div class="hl-pp-cert-title"><?php esc_html_e('Certificate', 'hl-core'); ?></div>
                                    <div class="hl-pp-cert-desc"><?php esc_html_e('Complete all steps to unlock', 'hl-core'); ?></div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>
            </div>
        </div>
        <?php

        return ob_get_clean();
    }

    /**
     * Render a single component card with actions.
     */
    private function render_component_card($ad, $pathway, $enrollment) {
        $component          = $ad['component'];
        $availability       = $ad['availability'];
        $completion_percent = (int) $ad['completion_percent'];
        $completed_at       = $ad['completed_at'];
        $course_url         = $ad['course_url'];
        $assess_status      = isset($ad['assess_status']) ? $ad['assess_status'] : 'not_started';
        $children_counts    = isset($ad['children_counts']) ? $ad['children_counts'] : null;
        $avail_status       = $availability['availability_status'];

        // For partial child assessment, compute completion percent from counts.
        if ($assess_status === 'partial' && $children_counts && $children_counts['total'] > 0) {
            $completion_percent = (int) round($children_counts['submitted'] / $children_counts['total'] * 100);
        }

        // CSS classes.
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
            default:
                $card_class = 'hl-component-available';
                $bar_class  = ($completion_percent > 0) ? 'hl-progress-active' : '';
                break;
        }

        $type_label = isset(self::$type_labels[$component->component_type])
            ? self::$type_labels[$component->component_type]
            : ucwords(str_replace('_', ' ', $component->component_type));

        // Action button/link.
        $action_html = '';
        if ($avail_status === 'available') {
            $action_html = $this->get_action_html($component, $enrollment, $pathway, $assess_status, $completion_percent);
        } elseif ($avail_status === 'completed') {
            // Completed assessment components: show "View Responses" link.
            $action_html = $this->get_completed_action_html($component, $enrollment);
        }

        // Date badge: "Available {date}" when locked by drip, "Complete by {date}" when available.
        $drip_badge = '';
        if ($avail_status === 'locked'
            && !empty($availability['locked_reason'])
            && $availability['locked_reason'] === 'drip'
            && !empty($availability['next_available_at'])
        ) {
            $drip_badge = '<span class="hl-drip-badge">'
                . sprintf(esc_html__('Available %s', 'hl-core'), esc_html($this->format_date($availability['next_available_at'])))
                . '</span>';
        } elseif ($avail_status === 'available' && !empty($component->complete_by)) {
            $drip_badge = '<span class="hl-complete-by-badge">'
                . sprintf(esc_html__('Complete by %s', 'hl-core'), esc_html($this->format_date($component->complete_by)))
                . '</span>';
        }
        ?>
        <div class="hl-component-card <?php echo esc_attr($card_class); ?>">
            <div class="hl-component-status-icon">
                <?php if ($avail_status === 'completed') : ?>
                    <span class="hl-icon-check">&#10003;</span>
                <?php elseif ($avail_status === 'locked') : ?>
                    <span class="hl-icon-lock">&#128274;</span>
                <?php else : ?>
                    <span class="hl-icon-progress">&#9654;</span>
                <?php endif; ?>
            </div>
            <div class="hl-component-content">
                <h4 class="hl-component-title">
                    <?php echo esc_html($component->title); ?>
                    <?php if (!empty($drip_badge)) echo $drip_badge; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </h4>
                <div class="hl-component-meta">
                    <span class="hl-component-type"><?php echo esc_html($type_label); ?></span>
                    <?php if ($avail_status === 'completed') : ?>
                        <span class="hl-badge hl-badge-completed"><?php esc_html_e('Completed', 'hl-core'); ?></span>
                        <?php if ($completed_at) : ?>
                            <span class="hl-component-date"><?php echo esc_html($this->format_date($completed_at)); ?></span>
                        <?php endif; ?>
                    <?php elseif ($avail_status === 'locked') : ?>
                        <span class="hl-component-lock-reason"><?php echo esc_html($this->get_lock_reason_text($availability)); ?></span>
                    <?php else : ?>
                        <span class="hl-component-progress-text"><?php
                            if ($assess_status === 'partial' && $children_counts) {
                                printf(esc_html__('%d/%d Completed', 'hl-core'), $children_counts['submitted'], $children_counts['total']);
                            } elseif ($assess_status === 'draft') {
                                esc_html_e('Draft saved', 'hl-core');
                            } elseif ($assess_status === 'submitted') {
                                esc_html_e('Submitted', 'hl-core');
                            } elseif ($completion_percent > 0) {
                                printf(esc_html__('%d%% complete', 'hl-core'), $completion_percent);
                            } else {
                                esc_html_e('Not started', 'hl-core');
                            }
                        ?></span>
                    <?php endif; ?>
                </div>
                <?php if (!empty($action_html)) : ?>
                    <div class="hl-component-action">
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
     * Build the action HTML for an available component.
     *
     * @param object $component
     * @param object $enrollment
     * @param object $pathway
     * @param string $assess_status Assessment instance status: not_started, draft, submitted.
     * @param int    $completion_percent Component completion percentage (0-100).
     * @return string Escaped HTML.
     */
    private function get_action_html($component, $enrollment, $pathway, $assess_status = 'not_started', $completion_percent = 0) {
        $type = $component->component_type;

        // LearnDash course: direct link with status-aware label.
        if ($type === 'learndash_course') {
            $course_id = HL_Course_Catalog::resolve_ld_course_id($component, $enrollment);
            if ($course_id) {
                $url = get_permalink($course_id);
                if ($url) {
                    if ($completion_percent >= 100) {
                        $label = __('View Course', 'hl-core');
                        $btn_class = 'hl-btn-secondary';
                    } elseif ($completion_percent > 0) {
                        $label = __('Continue Course', 'hl-core');
                        $btn_class = 'hl-btn-primary';
                    } else {
                        $label = __('Start Course', 'hl-core');
                        $btn_class = 'hl-btn-primary';
                    }
                    return '<a href="' . esc_url($url) . '" class="hl-btn hl-btn-sm ' . esc_attr($btn_class) . '">'
                        . esc_html($label) . '</a>';
                }
            }
            return '';
        }

        // Teacher self-assessment: route directly to [hl_teacher_assessment] page.
        if ($type === 'teacher_self_assessment') {
            $tsa_url = $this->find_shortcode_page_url('hl_teacher_assessment');
            if (!empty($tsa_url)) {
                $tsa_url = add_query_arg('component_id', $component->component_id, $tsa_url);
                $label = $this->get_assessment_button_label($assess_status);
                $btn_class = ($assess_status === 'submitted') ? 'hl-btn-secondary' : 'hl-btn-primary';
                return '<a href="' . esc_url($tsa_url) . '" class="hl-btn hl-btn-sm ' . esc_attr($btn_class) . '">'
                    . esc_html($label) . '</a>';
            }
            // Fallback to Component Page.
            $component_page_url = $this->get_component_page_url($component->component_id, $enrollment->enrollment_id);
            if (!empty($component_page_url)) {
                $label = $this->get_assessment_button_label($assess_status);
                $btn_class = ($assess_status === 'submitted') ? 'hl-btn-secondary' : 'hl-btn-primary';
                return '<a href="' . esc_url($component_page_url) . '" class="hl-btn hl-btn-sm ' . esc_attr($btn_class) . '">'
                    . esc_html($label) . '</a>';
            }
            return '';
        }

        // child assessment: status-aware buttons with component_id routing.
        if ($type === 'child_assessment') {
            $assessment_url = apply_filters('hl_core_child_assessment_page_url', '');
            if (empty($assessment_url)) {
                $assessment_url = $this->find_shortcode_page_url('hl_child_assessment');
            }
            if (!empty($assessment_url)) {
                $assessment_url = add_query_arg('component_id', $component->component_id, $assessment_url);
                $label = $this->get_assessment_button_label($assess_status);
                $btn_class = ($assess_status === 'submitted') ? 'hl-btn-secondary' : 'hl-btn-primary';
                return '<a href="' . esc_url($assessment_url) . '" class="hl-btn hl-btn-sm ' . esc_attr($btn_class) . '">'
                    . esc_html($label) . '</a>';
            }
            // Fallback to Component Page.
            $component_page_url = $this->get_component_page_url($component->component_id, $enrollment->enrollment_id);
            if (!empty($component_page_url)) {
                $label = $this->get_assessment_button_label($assess_status);
                $btn_class = ($assess_status === 'submitted') ? 'hl-btn-secondary' : 'hl-btn-primary';
                return '<a href="' . esc_url($component_page_url) . '" class="hl-btn hl-btn-sm ' . esc_attr($btn_class) . '">'
                    . esc_html($label) . '</a>';
            }
            return '';
        }

        // Coaching session: show session-specific info.
        if ($type === 'coaching_session_attendance') {
            return $this->get_coaching_action_html($component, $enrollment);
        }

        // New event component types: link to Component Page.
        if (in_array($type, array('reflective_practice_session', 'classroom_visit', 'self_reflection'), true)) {
            $component_page_url = $this->get_component_page_url($component->component_id, $enrollment->enrollment_id);
            if (!empty($component_page_url)) {
                $labels = array(
                    'reflective_practice_session' => __('Open Session', 'hl-core'),
                    'classroom_visit'             => __('Start Visit', 'hl-core'),
                    'self_reflection'             => __('Start Reflection', 'hl-core'),
                );
                $label = $labels[$type];
                return '<a href="' . esc_url($component_page_url) . '" class="hl-btn hl-btn-sm hl-btn-primary">'
                    . esc_html($label) . '</a>';
            }
            return '';
        }

        return '';
    }

    /**
     * Get status-aware button label for assessment components.
     *
     * @param string $assess_status Instance status: not_started, draft, submitted.
     * @return string Translated button label.
     */
    private function get_assessment_button_label($assess_status) {
        switch ($assess_status) {
            case 'submitted':
                return __('View Responses', 'hl-core');
            case 'draft':
            case 'partial':
                return __('Continue Assessment', 'hl-core');
            default:
                return __('Start Assessment', 'hl-core');
        }
    }

    /**
     * Build the URL for the Component Page.
     *
     * @param int $component_id
     * @param int $enrollment_id
     * @return string
     */
    private function get_component_page_url($component_id, $enrollment_id) {
        $base = apply_filters('hl_core_component_page_url', '');
        if (empty($base)) {
            $base = $this->find_shortcode_page_url('hl_component_page');
        }
        if (empty($base)) {
            return '';
        }
        return add_query_arg(array(
            'id'         => $component_id,
            'enrollment' => $enrollment_id,
        ), $base);
    }

    /**
     * Build coaching session action HTML for the component card.
     *
     * - Session scheduled: "Upcoming on [date]" badge + "View Session" button
     * - No session: "Schedule Session" button linking to Component Page
     * - Session missed: "Missed" badge + "Reschedule" link
     * - Session attended: "Completed on [date]" (handled by main card, this is fallback)
     *
     * @param object $component
     * @param object $enrollment
     * @return string
     */
    private function get_coaching_action_html($component, $enrollment) {
        $coaching_service = new HL_Coaching_Service();
        $component_id = $component->component_id;

        // Get sessions for THIS specific component, not all coaching sessions in the enrollment.
        $upcoming = $coaching_service->get_upcoming_sessions(
            $enrollment->enrollment_id,
            $enrollment->cycle_id,
            $component_id
        );

        if (!empty($upcoming)) {
            $session = $upcoming[0];
            $date_display = !empty($session['session_datetime'])
                ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($session['session_datetime']))
                : __('TBD', 'hl-core');

            $html = HL_Coaching_Service::render_status_badge('scheduled')
                  . ' <span>'
                  . sprintf(esc_html__('Upcoming on %s', 'hl-core'), esc_html($date_display))
                  . '</span>';

            // "View Session" button links to the Component Page (session details + Action Plan).
            $component_page_url = $this->get_component_page_url($component_id, $enrollment->enrollment_id);
            if (!empty($component_page_url)) {
                $html .= ' <a href="' . esc_url($component_page_url) . '" class="hl-btn hl-btn-sm hl-btn-primary">'
                       . esc_html__('View Session', 'hl-core')
                       . '</a>';
            }

            return $html;
        }

        // Check for recently missed session (past, status = scheduled but datetime < now).
        $past = $coaching_service->get_past_sessions(
            $enrollment->enrollment_id,
            $enrollment->cycle_id,
            $component_id
        );

        if (!empty($past)) {
            $latest = $past[0];
            $status = $latest['session_status'] ?? 'scheduled';

            if ($status === 'missed') {
                $component_page_url = $this->get_component_page_url($component_id, $enrollment->enrollment_id);
                $html = HL_Coaching_Service::render_status_badge('missed');
                if ($component_page_url) {
                    $html .= ' <a href="' . esc_url($component_page_url) . '" class="hl-btn hl-btn-sm hl-btn-secondary">'
                           . esc_html__('Reschedule', 'hl-core')
                           . '</a>';
                }
                return $html;
            }
        }

        // No session scheduled yet: link to the Component Page (scheduling UI).
        $component_page_url = $this->get_component_page_url($component_id, $enrollment->enrollment_id);
        if ($component_page_url) {
            return '<a href="' . esc_url($component_page_url) . '" class="hl-btn hl-btn-sm hl-btn-primary">'
                 . esc_html__('Schedule Session', 'hl-core')
                 . '</a>';
        }

        return '<span class="hl-component-notice">' . esc_html__('Managed by your coach.', 'hl-core') . '</span>';
    }

    /**
     * Build the "View Responses" link for completed assessment components.
     *
     * @param object $component
     * @param object $enrollment
     * @return string
     */
    private function get_completed_action_html($component, $enrollment) {
        $type = $component->component_type;

        // Completed LearnDash course: "View Course" link.
        if ($type === 'learndash_course') {
            $course_id = HL_Course_Catalog::resolve_ld_course_id($component, $enrollment);
            if ($course_id) {
                $url = get_permalink($course_id);
                if ($url) {
                    return '<a href="' . esc_url($url) . '" class="hl-btn hl-btn-sm hl-btn-secondary">'
                        . esc_html__('View Course', 'hl-core') . '</a>';
                }
            }
            return '';
        }

        if ($type === 'teacher_self_assessment') {
            $tsa_url = $this->find_shortcode_page_url('hl_teacher_assessment');
            if (!empty($tsa_url)) {
                $tsa_url = add_query_arg('component_id', $component->component_id, $tsa_url);
                return '<a href="' . esc_url($tsa_url) . '" class="hl-btn hl-btn-sm hl-btn-secondary">'
                    . esc_html__('View Responses', 'hl-core') . '</a>';
            }
        }

        if ($type === 'child_assessment') {
            $ca_url = apply_filters('hl_core_child_assessment_page_url', '');
            if (empty($ca_url)) {
                $ca_url = $this->find_shortcode_page_url('hl_child_assessment');
            }
            if (!empty($ca_url)) {
                $ca_url = add_query_arg('component_id', $component->component_id, $ca_url);
                return '<a href="' . esc_url($ca_url) . '" class="hl-btn hl-btn-sm hl-btn-secondary">'
                    . esc_html__('View Responses', 'hl-core') . '</a>';
            }
        }

        if (in_array($type, array('reflective_practice_session', 'classroom_visit', 'self_reflection'), true)) {
            $component_page_url = $this->get_component_page_url($component->component_id, $enrollment->enrollment_id);
            if (!empty($component_page_url)) {
                return '<a href="' . esc_url($component_page_url) . '" class="hl-btn hl-btn-sm hl-btn-secondary">'
                    . esc_html__('View Submission', 'hl-core') . '</a>';
            }
        }

        if ($type === 'coaching_session_attendance') {
            $component_page_url = $this->get_component_page_url($component->component_id, $enrollment->enrollment_id);
            if (!empty($component_page_url)) {
                return '<a href="' . esc_url($component_page_url) . '" class="hl-btn hl-btn-sm hl-btn-secondary">'
                    . esc_html__('View Session', 'hl-core') . '</a>';
            }
        }

        return '';
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
     * Render compact progress ring for v2 sidebar.
     */
    private function render_progress_ring_v2($percent, $completed_count, $total, $status_label) {
        $percent       = max(0, min(100, (int) $percent));
        $radius        = 22;
        $circumference = 2 * M_PI * $radius;
        $offset        = $circumference * (1 - $percent / 100);
        ?>
        <div class="hl-pp-progress-top">
            <div class="hl-pp-ring-mini">
                <svg viewBox="0 0 52 52">
                    <circle class="ring-bg" cx="26" cy="26" r="<?php echo esc_attr($radius); ?>" />
                    <circle class="ring-fill" cx="26" cy="26" r="<?php echo esc_attr($radius); ?>"
                            stroke-dasharray="<?php echo esc_attr(round($circumference, 2)); ?>"
                            stroke-dashoffset="<?php echo esc_attr(round($offset, 2)); ?>" />
                </svg>
                <div class="hl-pp-ring-text"><?php echo esc_html($percent . '%'); ?></div>
            </div>
            <div>
                <div class="hl-pp-progress-label"><?php echo esc_html($status_label); ?></div>
                <div class="hl-pp-progress-sub">
                    <?php printf(esc_html__('%d of %d steps completed', 'hl-core'), $completed_count, $total); ?>
                </div>
            </div>
        </div>
        <div class="hl-pp-bar-full">
            <div class="hl-pp-bar-fill" style="width: <?php echo esc_attr($percent); ?>%"></div>
        </div>
        <?php
    }

    /**
     * Translate a pathway field via WPML string translation.
     *
     * Registers the original string and returns the translated version
     * for the current WPML language. Falls back to the original if
     * WPML is not active or no translation exists.
     *
     * @param HL_Pathway $pathway The pathway object.
     * @param string     $field   Field name: pathway_name, description, or objectives.
     * @return string Translated value (or original).
     */
    private function translate_pathway_field($pathway, $field) {
        $value = isset($pathway->$field) ? $pathway->$field : '';
        if (empty($value)) {
            return $value;
        }

        $name = 'pathway_' . $pathway->pathway_id . '_' . $field;

        // Register string with WPML (idempotent).
        if (function_exists('icl_register_string')) {
            icl_register_string('hl-core-pathways', $name, $value);
        }

        // Return translated version.
        return apply_filters('wpml_translate_single_string', $value, 'hl-core-pathways', $name);
    }

    /**
     * Render a v2 component card with image support.
     */
    private function render_component_card_v2($ad, $pathway, $enrollment) {
        $component          = $ad['component'];
        $availability       = $ad['availability'];
        $completion_percent = (int) $ad['completion_percent'];
        $completed_at       = $ad['completed_at'];
        $course_url         = $ad['course_url'];
        $assess_status      = isset($ad['assess_status']) ? $ad['assess_status'] : 'not_started';
        $children_counts    = isset($ad['children_counts']) ? $ad['children_counts'] : null;
        $avail_status       = $availability['availability_status'];

        if ($assess_status === 'partial' && $children_counts && $children_counts['total'] > 0) {
            $completion_percent = (int) round($children_counts['submitted'] / $children_counts['total'] * 100);
        }

        $type_label = isset(self::$type_labels[$component->component_type])
            ? self::$type_labels[$component->component_type]
            : ucwords(str_replace('_', ' ', $component->component_type));

        // Is this a course with an image?
        $is_course   = ($component->component_type === 'learndash_course');
        $course_image = '';
        if ($is_course) {
            $course_id = HL_Course_Catalog::resolve_ld_course_id($component, $enrollment) ?: 0;
            if ($course_id && has_post_thumbnail($course_id)) {
                $course_image = get_the_post_thumbnail($course_id, 'medium', array('loading' => 'lazy'));
            }
            // Lesson count from LearnDash.
            if ($course_id) {
                $lessons = function_exists('learndash_get_lesson_list') ? learndash_get_lesson_list($course_id) : array();
                $lesson_count = is_array($lessons) ? count($lessons) : 0;
                if ($lesson_count > 0) {
                    $type_label .= ' · ' . sprintf(_n('%d Lesson', '%d Lessons', $lesson_count, 'hl-core'), $lesson_count);
                }
            }
        }

        // Status overlay text and class.
        $overlay_class = 'hl-pp-overlay-not-started';
        $overlay_text  = __('Not Started', 'hl-core');
        if ($avail_status === 'not_applicable') {
            $overlay_class = 'hl-pp-overlay-not-applicable';
            $overlay_text  = __('Not Applicable', 'hl-core');
        } elseif ($avail_status === 'completed') {
            $overlay_class = 'hl-pp-overlay-completed';
            $overlay_text  = __('Completed', 'hl-core');
        } elseif ($avail_status === 'locked') {
            $overlay_class = 'hl-pp-overlay-locked';
            $overlay_text  = __('Locked', 'hl-core');
        } elseif ($completion_percent >= 100) {
            $overlay_class = 'hl-pp-overlay-completed';
            $overlay_text  = __('Completed', 'hl-core');
        } elseif ($completion_percent > 0) {
            $overlay_class = 'hl-pp-overlay-in-progress';
            $overlay_text  = __('In Progress', 'hl-core');
        }

        // Progress bar fill class.
        $fill_class = 'hl-pp-fill-none';
        if ($avail_status === 'completed' || $completion_percent >= 100) {
            $fill_class = 'hl-pp-fill-complete';
        } elseif ($completion_percent > 0) {
            $fill_class = 'hl-pp-fill-active';
        }

        // Card CSS class.
        $card_class = 'hl-pp-component';
        if ($avail_status === 'not_applicable') {
            $card_class .= ' hl-pp-not-applicable';
        } elseif ($avail_status === 'locked') {
            $card_class .= ' hl-pp-locked';
        }

        // Progress text.
        $progress_text = '';
        if ($avail_status === 'completed') {
            $progress_text = esc_html__('100% Complete', 'hl-core');
        } elseif ($assess_status === 'partial' && $children_counts) {
            $progress_text = sprintf('%d/%d', $children_counts['submitted'], $children_counts['total']);
        } elseif ($assess_status === 'draft') {
            $progress_text = esc_html__('Draft', 'hl-core');
        } elseif ($completion_percent > 0) {
            $progress_text = $completion_percent . '%';
        } else {
            $progress_text = esc_html__('Not started', 'hl-core');
        }

        // Action button.
        $action_html = '';
        $btn_class   = 'hl-pp-btn hl-pp-btn-start';
        if ($avail_status === 'available') {
            $action_html = $this->get_action_html($component, $enrollment, $pathway, $assess_status, $completion_percent);
            // Replace old button classes with v2 classes.
            $action_html = str_replace(
                array('hl-btn hl-btn-sm hl-btn-primary', 'hl-btn hl-btn-sm hl-btn-secondary'),
                array('hl-pp-btn hl-pp-btn-start', 'hl-pp-btn hl-pp-btn-view'),
                $action_html
            );
            // If component has progress, use continue style.
            if ($completion_percent > 0 && $completion_percent < 100) {
                $action_html = str_replace('hl-pp-btn-start', 'hl-pp-btn-continue', $action_html);
            }
        } elseif ($avail_status === 'completed') {
            $action_html = $this->get_completed_action_html($component, $enrollment);
            $action_html = str_replace(
                array('hl-btn hl-btn-sm hl-btn-secondary', 'hl-btn hl-btn-sm hl-btn-primary'),
                array('hl-pp-btn hl-pp-btn-view', 'hl-pp-btn hl-pp-btn-view'),
                $action_html
            );
        }

        // Non-course icon map.
        $icon_map = array(
            'teacher_self_assessment'      => '&#x1F4DD;',
            'child_assessment'             => '&#x1F4DD;',
            'classroom_visit'              => '&#x1F50D;',
            'coaching_session_attendance'  => '&#x1F3AC;',
            'reflective_practice_session'  => '&#x1F4AD;',
            'self_reflection'              => '&#x1F4AD;',
        );
        $type_icon = isset($icon_map[$component->component_type]) ? $icon_map[$component->component_type] : '&#x1F4CB;';

        // Drip badge / Scheduling window badge.
        $drip_html = '';
        if ($component->component_type === 'coaching_session_attendance'
            && (!empty($component->scheduling_window_start) || !empty($component->scheduling_window_end))
        ) {
            $today = current_time('Y-m-d');
            $sw_start = $component->scheduling_window_start;
            $sw_end   = $component->scheduling_window_end;
            if ($sw_start && $sw_end) {
                if ($today > $sw_end && $avail_status !== 'completed') {
                    $drip_html = '<span class="hl-pp-drip-badge hl-pp-window-closed">'
                        . sprintf(esc_html__('Window closed (%s – %s)', 'hl-core'),
                            esc_html(date_i18n('M j', strtotime($sw_start))),
                            esc_html(date_i18n('M j, Y', strtotime($sw_end))))
                        . '</span>';
                } else {
                    $drip_html = '<span class="hl-pp-drip-badge">'
                        . sprintf(esc_html__('Schedule %s – %s', 'hl-core'),
                            esc_html(date_i18n('M j', strtotime($sw_start))),
                            esc_html(date_i18n('M j, Y', strtotime($sw_end))))
                        . '</span>';
                }
            } elseif ($sw_start) {
                $drip_html = '<span class="hl-pp-drip-badge">'
                    . sprintf(esc_html__('Available from %s', 'hl-core'), esc_html(date_i18n('M j, Y', strtotime($sw_start))))
                    . '</span>';
            } elseif ($sw_end) {
                if ($today > $sw_end && $avail_status !== 'completed') {
                    $drip_html = '<span class="hl-pp-drip-badge hl-pp-window-closed">'
                        . sprintf(esc_html__('Window closed (by %s)', 'hl-core'), esc_html(date_i18n('M j, Y', strtotime($sw_end))))
                        . '</span>';
                } else {
                    $drip_html = '<span class="hl-pp-drip-badge">'
                        . sprintf(esc_html__('Schedule by %s', 'hl-core'), esc_html(date_i18n('M j, Y', strtotime($sw_end))))
                        . '</span>';
                }
            }
        } elseif ($avail_status === 'locked'
            && !empty($availability['locked_reason'])
            && $availability['locked_reason'] === 'drip'
            && !empty($availability['next_available_at'])
        ) {
            $drip_html = '<span class="hl-pp-drip-badge">'
                . sprintf(esc_html__('Available %s', 'hl-core'), esc_html($this->format_date($availability['next_available_at'])))
                . '</span>';
        } elseif ($avail_status === 'available' && !empty($component->complete_by)) {
            $drip_html = '<span class="hl-pp-complete-by-badge">'
                . sprintf(esc_html__('Complete by %s', 'hl-core'), esc_html($this->format_date($component->complete_by)))
                . '</span>';
        }
        ?>
        <div class="<?php echo esc_attr($card_class); ?>">
            <?php if ($is_course && !empty($course_image)) : ?>
                <div class="hl-pp-component-image">
                    <?php echo $course_image; ?>
                    <div class="hl-pp-status-overlay <?php echo esc_attr($overlay_class); ?>">
                        <?php echo esc_html($overlay_text); ?>
                    </div>
                </div>
            <?php elseif (!$is_course) : ?>
                <div class="hl-pp-component-icon"><?php echo $type_icon; ?></div>
            <?php endif; ?>

            <div class="hl-pp-component-body">
                <div class="hl-pp-component-type"><?php echo wp_kses_post($type_label); ?></div>
                <h4 class="hl-pp-component-name">
                    <?php echo esc_html($component->title); ?>
                    <?php if (!empty($drip_html)) echo $drip_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_html() ?>
                </h4>
                <div class="hl-pp-component-footer">
                    <div class="hl-pp-component-meta"><?php echo esc_html($progress_text); ?></div>
                    <div class="hl-pp-component-progress">
                        <div class="hl-pp-progress-fill <?php echo esc_attr($fill_class); ?>" style="width: <?php echo esc_attr($completion_percent); ?>%"></div>
                    </div>
                    <?php if (!empty($action_html)) : ?>
                        <div class="hl-pp-component-action"><?php echo $action_html; ?></div>
                    <?php endif; ?>
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
     * Resolve blocker component IDs to titles (limit to 3 names + "+N more").
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
            "SELECT component_id, title FROM {$wpdb->prefix}hl_component WHERE component_id IN ({$ids})",
            ARRAY_A
        );
        $map = array();
        foreach ($rows as $r) {
            $map[(int) $r['component_id']] = $r['title'];
        }
        $names = array();
        foreach ($blocker_ids as $aid) {
            $names[] = isset($map[(int) $aid]) ? $map[(int) $aid] : ('#' . $aid);
        }
        return $names;
    }

    /**
     * Batch-load assessment instance statuses for all assessment components
     * linked to this enrollment, keyed by component_id.
     *
     * Returns: ['teacher' => [component_id => status], 'children' => [component_id => status]]
     *
     * @param object $enrollment
     * @return array
     */
    private function get_assessment_statuses($enrollment) {
        global $wpdb;
        $result = array('teacher' => array(), 'children' => array(), 'children_counts' => array());

        // Teacher self-assessment instances linked by component_id.
        $teacher_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT component_id, status
             FROM {$wpdb->prefix}hl_teacher_assessment_instance
             WHERE enrollment_id = %d AND cycle_id = %d AND component_id IS NOT NULL",
            $enrollment->enrollment_id,
            $enrollment->cycle_id
        ), ARRAY_A);
        foreach ($teacher_rows as $row) {
            $result['teacher'][(int) $row['component_id']] = $row['status'];
        }

        // child assessment instances linked by component_id.
        // Group by component_id to handle multiple instances per component.
        $children_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT component_id, status
             FROM {$wpdb->prefix}hl_child_assessment_instance
             WHERE enrollment_id = %d AND cycle_id = %d AND component_id IS NOT NULL",
            $enrollment->enrollment_id,
            $enrollment->cycle_id
        ), ARRAY_A);

        $children_by_component = array();
        foreach ($children_rows as $row) {
            $aid = (int) $row['component_id'];
            if (!isset($children_by_component[$aid])) {
                $children_by_component[$aid] = array();
            }
            $children_by_component[$aid][] = $row['status'];
        }

        foreach ($children_by_component as $aid => $statuses) {
            $total       = count($statuses);
            $submitted   = count(array_filter($statuses, function ($s) { return $s === 'submitted'; }));
            $in_progress = count(array_filter($statuses, function ($s) { return $s === 'in_progress'; }));

            $result['children_counts'][$aid] = array(
                'total'       => $total,
                'submitted'   => $submitted,
                'in_progress' => $in_progress,
            );

            if ($submitted >= $total) {
                $result['children'][$aid] = 'submitted';
            } elseif ($submitted > 0 || $in_progress > 0) {
                $result['children'][$aid] = 'partial';
            } else {
                $result['children'][$aid] = 'not_started';
            }
        }

        return $result;
    }

    /**
     * Query the hl_component_state table.
     */
    private function get_component_state($enrollment_id, $component_id) {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}hl_component_state WHERE enrollment_id = %d AND component_id = %d",
                $enrollment_id,
                $component_id
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
