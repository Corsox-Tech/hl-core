<?php
if (!defined('ABSPATH')) exit;

/**
 * Renderer for the [hl_my_programs] shortcode.
 *
 * Shows a logged-in participant a grid of program cards — one per
 * enrolled pathway — with featured image, cohort name, completion %,
 * status badge, and "Continue" link to the Program Page.
 *
 * @package HL_Core
 */
class HL_Frontend_My_Programs {

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

    /** @var HL_Pathway_Assignment_Service */
    private $pa_service;

    public function __construct() {
        $this->enrollment_repo = new HL_Enrollment_Repository();
        $this->cohort_repo     = new HL_Cohort_Repository();
        $this->pathway_repo    = new HL_Pathway_Repository();
        $this->activity_repo   = new HL_Activity_Repository();
        $this->rules_engine    = new HL_Rules_Engine_Service();
        $this->learndash       = HL_LearnDash_Integration::instance();
        $this->pa_service      = new HL_Pathway_Assignment_Service();
    }

    /**
     * Render the My Programs shortcode.
     *
     * @param array $atts Shortcode attributes.
     * @return string HTML output.
     */
    public function render($atts) {
        ob_start();

        $user_id = get_current_user_id();

        // Fetch active enrollments for this user.
        $all_enrollments = $this->enrollment_repo->get_all(array('status' => 'active'));
        $enrollments = array_filter($all_enrollments, function ($enrollment) use ($user_id) {
            return (int) $enrollment->user_id === $user_id;
        });
        $enrollments = array_values($enrollments);

        // Build program cards data.
        $cards = array();
        foreach ($enrollments as $enrollment) {
            // Get pathways via assignment service (explicit first, then role-based fallback).
            $assigned_pathways = $this->pa_service->get_pathways_for_enrollment($enrollment->enrollment_id);

            // If no assignments from service, fall back to legacy assigned_pathway_id.
            if (empty($assigned_pathways) && !empty($enrollment->assigned_pathway_id)) {
                $legacy_pw = $this->pathway_repo->get_by_id($enrollment->assigned_pathway_id);
                if ($legacy_pw) {
                    $assigned_pathways = array(array(
                        'pathway_id'   => $legacy_pw->pathway_id,
                        'pathway_name' => $legacy_pw->pathway_name,
                        'assignment_type' => 'role_default',
                    ));
                }
            }

            if (empty($assigned_pathways)) {
                continue;
            }

            $cohort = $this->cohort_repo->get_by_id($enrollment->cohort_id);
            if (!$cohort) {
                continue;
            }

            foreach ($assigned_pathways as $pw_data) {
            $pathway = $this->pathway_repo->get_by_id($pw_data['pathway_id']);
            if (!$pathway) {
                continue;
            }

            // Compute overall completion % (same logic as my-progress).
            $activities = $this->activity_repo->get_by_pathway($pathway->pathway_id);
            $activities = array_filter($activities, function ($act) {
                return $act->visibility !== 'staff_only';
            });
            $activities = array_values($activities);

            $total_weight  = 0;
            $weighted_done = 0;

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

                // For LD courses, pull live progress.
                $external_ref = $activity->get_external_ref_array();
                if ($activity->activity_type === 'learndash_course' && !empty($external_ref['course_id'])) {
                    if ($availability['availability_status'] !== 'completed') {
                        $ld_percent = $this->learndash->get_course_progress_percent($user_id, absint($external_ref['course_id']));
                        if ($ld_percent > $completion_percent) {
                            $completion_percent = $ld_percent;
                        }
                    }
                }

                if ($availability['availability_status'] === 'completed') {
                    $completion_percent = 100;
                }

                $weight = max((float) $activity->weight, 0);
                $total_weight  += $weight;
                $weighted_done += $weight * ($completion_percent / 100);
            }

            $overall_percent = ($total_weight > 0)
                ? round(($weighted_done / $total_weight) * 100)
                : 0;

            $cards[] = array(
                'enrollment' => $enrollment,
                'pathway'    => $pathway,
                'cohort'     => $cohort,
                'percent'    => $overall_percent,
            );
            } // end foreach assigned_pathways
        }

        // Resolve coach for the first enrollment (shows one coach card).
        $coach = null;
        if (!empty($enrollments)) {
            $coach_service = new HL_Coach_Assignment_Service();
            foreach ($enrollments as $e) {
                $coach = $coach_service->get_coach_for_enrollment($e->enrollment_id, $e->cohort_id);
                if ($coach) break;
            }
        }

        // Render.
        ?>
        <div class="hl-dashboard hl-my-programs">
            <h2><?php esc_html_e('My Programs', 'hl-core'); ?></h2>

            <?php $this->render_coach_widget($coach); ?>

            <?php if (empty($cards)) : ?>
                <div class="hl-empty-state">
                    <h3><?php esc_html_e('No Programs Yet', 'hl-core'); ?></h3>
                    <p><?php esc_html_e('You are not currently assigned to any programs. If you believe this is an error, please contact your administrator.', 'hl-core'); ?></p>
                </div>
            <?php else : ?>
                <div class="hl-programs-grid">
                    <?php foreach ($cards as $card) :
                        $this->render_program_card($card);
                    endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php

        return ob_get_clean();
    }

    /**
     * Render a single program card.
     *
     * @param array $card Card data array.
     */
    private function render_program_card($card) {
        $pathway    = $card['pathway'];
        $cohort     = $card['cohort'];
        $enrollment = $card['enrollment'];
        $percent    = (int) $card['percent'];

        // Status badge.
        if ($percent === 0) {
            $badge_class = 'hl-badge-not-started';
            $badge_text  = __('Not Started', 'hl-core');
        } elseif ($percent >= 100) {
            $badge_class = 'hl-badge-completed';
            $badge_text  = __('Completed', 'hl-core');
        } else {
            $badge_class = 'hl-badge-in-progress';
            $badge_text  = __('In Progress', 'hl-core');
        }

        // Progress bar class.
        if ($percent >= 100) {
            $bar_class = 'hl-progress-complete';
        } elseif ($percent > 0) {
            $bar_class = 'hl-progress-active';
        } else {
            $bar_class = '';
        }

        // Card link to Program Page.
        $program_page_url = apply_filters('hl_core_program_page_url', '');
        if (empty($program_page_url)) {
            // Try to find page with [hl_program_page] shortcode.
            $program_page_url = $this->find_shortcode_page_url('hl_program_page');
        }
        $continue_url = add_query_arg(array(
            'id'         => $pathway->pathway_id,
            'enrollment' => $enrollment->enrollment_id,
        ), $program_page_url);

        // Featured image.
        $image_id = !empty($pathway->featured_image_id) ? absint($pathway->featured_image_id) : 0;
        ?>
        <div class="hl-program-card">
            <div class="hl-program-card-image">
                <?php if ($image_id) : ?>
                    <?php echo wp_get_attachment_image($image_id, 'medium_large', false, array('loading' => 'lazy')); ?>
                <?php else : ?>
                    <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:#9e9e9e;font-size:48px;">&#128218;</div>
                <?php endif; ?>
            </div>
            <div class="hl-program-card-body">
                <h3 class="hl-program-card-title"><?php echo esc_html($pathway->pathway_name); ?></h3>
                <p class="hl-program-card-cohort"><?php echo esc_html($cohort->cohort_name); ?></p>
                <div class="hl-program-card-progress">
                    <div class="hl-progress-bar-container">
                        <div class="hl-progress-bar <?php echo esc_attr($bar_class); ?>" style="width: <?php echo esc_attr($percent); ?>%"></div>
                    </div>
                    <span><?php echo esc_html($percent . '%'); ?></span>
                </div>
                <div class="hl-program-card-footer">
                    <span class="hl-badge <?php echo esc_attr($badge_class); ?>"><?php echo esc_html($badge_text); ?></span>
                    <a href="<?php echo esc_url($continue_url); ?>" class="hl-btn hl-btn-sm hl-btn-primary">
                        <?php echo $percent > 0 ? esc_html__('Continue', 'hl-core') : esc_html__('Start', 'hl-core'); ?>
                    </a>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render the "My Coach" widget.
     *
     * @param array|null $coach Coach data from CoachAssignmentService or null.
     */
    private function render_coach_widget($coach) {
        $coaching_page_url = $this->find_shortcode_page_url('hl_my_coaching');

        ?>
        <div class="hl-coach-widget" style="display:flex;align-items:center;gap:16px;padding:16px;background:#f8f9fa;border-radius:8px;margin-bottom:24px;">
            <?php if ($coach) : ?>
                <div><?php echo get_avatar($coach['coach_user_id'], 56, '', '', array('style' => 'border-radius:50%;')); ?></div>
                <div style="flex:1;">
                    <strong><?php esc_html_e('My Coach', 'hl-core'); ?></strong><br>
                    <?php echo esc_html($coach['coach_name']); ?> &middot;
                    <a href="mailto:<?php echo esc_attr($coach['coach_email']); ?>"><?php echo esc_html($coach['coach_email']); ?></a>
                </div>
                <?php if ($coaching_page_url) : ?>
                    <a href="<?php echo esc_url($coaching_page_url); ?>" class="hl-btn hl-btn-sm hl-btn-primary">
                        <?php esc_html_e('Schedule a Session', 'hl-core'); ?>
                    </a>
                <?php endif; ?>
            <?php else : ?>
                <div style="width:56px;height:56px;border-radius:50%;background:#dee2e6;display:flex;align-items:center;justify-content:center;color:#6c757d;font-size:20px;">?</div>
                <div>
                    <strong><?php esc_html_e('My Coach', 'hl-core'); ?></strong><br>
                    <span style="color:#6c757d;"><?php esc_html_e('No coach assigned yet. Contact your administrator.', 'hl-core'); ?></span>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Query the hl_activity_state table.
     *
     * @param int $enrollment_id
     * @param int $activity_id
     * @return array|null
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
     * Find the URL of a page containing a given shortcode.
     *
     * @param string $shortcode Shortcode tag (without brackets).
     * @return string URL or empty string.
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
