<?php
if (!defined('ABSPATH')) exit;

/**
 * Renderer for the [hl_my_programs] shortcode.
 *
 * Shows a logged-in participant a grid of program cards — one per
 * enrolled pathway — with featured image, track name, completion %,
 * status badge, and "Continue" link to the Program Page.
 *
 * @package HL_Core
 */
class HL_Frontend_My_Programs {

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

    /** @var HL_Pathway_Assignment_Service */
    private $pa_service;

    public function __construct() {
        $this->enrollment_repo = new HL_Enrollment_Repository();
        $this->cycle_repo     = new HL_Cycle_Repository();
        $this->pathway_repo    = new HL_Pathway_Repository();
        $this->component_repo  = new HL_Component_Repository();
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

            $cycle = $this->cycle_repo->get_by_id($enrollment->cycle_id);
            if (!$cycle) {
                continue;
            }

            foreach ($assigned_pathways as $pw_data) {
            $pathway = $this->pathway_repo->get_by_id($pw_data['pathway_id']);
            if (!$pathway) {
                continue;
            }

            // Compute overall completion % (same logic as my-progress).
            $components = $this->component_repo->get_by_pathway($pathway->pathway_id);
            $components = array_filter($components, function ($act) {
                return $act->visibility !== 'staff_only';
            });
            $components = array_values($components);

            $total_weight  = 0;
            $weighted_done = 0;

            foreach ($components as $component) {
                $availability = $this->rules_engine->compute_availability(
                    $enrollment->enrollment_id,
                    $component->component_id
                );

                // Skip ineligible components from percentage.
                if ($availability['availability_status'] === 'not_applicable') {
                    continue;
                }

                $state = $this->get_component_state(
                    $enrollment->enrollment_id,
                    $component->component_id
                );

                $completion_percent = $state ? (float) $state['completion_percent'] : 0;

                // For LD courses, pull live progress.
                $external_ref = $component->get_external_ref_array();
                if ($component->component_type === 'learndash_course' && !empty($external_ref['course_id'])) {
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

                $weight = max((float) $component->weight, 0);
                $total_weight  += $weight;
                $weighted_done += $weight * ($completion_percent / 100);
            }

            $overall_percent = ($total_weight > 0)
                ? round(($weighted_done / $total_weight) * 100)
                : 0;

            $cards[] = array(
                'enrollment'  => $enrollment,
                'pathway'     => $pathway,
                'cycle'       => $cycle,
                'percent'     => $overall_percent,
            );
            } // end foreach assigned_pathways
        }

        // Add per-cycle guide data (coach/mentor) to each card.
        $coach_service = new HL_Coach_Assignment_Service();
        foreach ($cards as &$card) {
            $card['guide_data'] = null;
            $card['guide_type'] = null;
            if (!empty($card['cycle']->is_control_group)) {
                continue;
            }
            $e = $card['enrollment'];
            $e_roles = json_decode($e->roles ?? '[]', true) ?: array();
            if (in_array('mentor', $e_roles, true)) {
                $card['guide_type'] = 'coach';
                $card['guide_data'] = $coach_service->get_coach_for_enrollment($e->enrollment_id, $e->cycle_id);
            } elseif (in_array('teacher', $e_roles, true)) {
                $card['guide_type'] = 'mentor';
                $card['guide_data'] = $this->resolve_mentor_for_teacher($user_id, array($e));
            }
        }
        unset($card);

        // Render.
        ?>
        <div class="hl-dashboard hl-my-programs">
            <h2><?php esc_html_e('My Programs', 'hl-core'); ?></h2>

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
        $cycle      = $card['cycle'];
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
                    <div class="hl-program-card-placeholder">&#128218;</div>
                <?php endif; ?>
            </div>
            <div class="hl-program-card-body">
                <h3 class="hl-program-card-title"><?php echo esc_html($pathway->pathway_name); ?></h3>
                <p class="hl-program-card-cycle">
                    <?php echo esc_html($cycle->cycle_name); ?>
                </p>
                <?php
                // Per-cycle guide: "My Coach" for mentors, "My Mentor" for teachers.
                $g_type = $card['guide_type'] ?? null;
                $g_data = $card['guide_data'] ?? null;
                if ($g_type && $g_data) :
                    $g_name = ($g_type === 'coach')
                        ? ($g_data['coach_name'] ?? '')
                        : ($g_data['display_name'] ?? '');
                    $g_label = ($g_type === 'coach') ? __('Coach:', 'hl-core') : __('Mentor:', 'hl-core');
                    if ($g_name) :
                ?>
                    <p class="hl-program-card-guide">
                        <strong><?php echo esc_html($g_label); ?></strong> <?php echo esc_html($g_name); ?>
                    </p>
                <?php endif; endif; ?>
                <div class="hl-program-card-progress">
                    <div class="hl-progress-bar-container">
                        <div class="hl-progress-bar <?php echo esc_attr($bar_class); ?>" style="width: <?php echo esc_attr($percent); ?>%"></div>
                    </div>
                    <span><?php echo esc_html($percent . '%'); ?></span>
                </div>
                <div class="hl-program-card-footer">
                    <span class="hl-badge <?php echo esc_attr($badge_class); ?>"><?php echo esc_html($badge_text); ?></span>
                    <a href="<?php echo esc_url($continue_url); ?>" class="hl-btn hl-btn-sm hl-btn-primary">
                        <?php
                        if ($percent >= 100) {
                            esc_html_e('View', 'hl-core');
                        } elseif ($percent > 0) {
                            esc_html_e('Continue', 'hl-core');
                        } else {
                            esc_html_e('Start', 'hl-core');
                        }
                        ?>
                    </a>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render the guide widget — "My Coach" for mentors, "My Mentor" for teachers.
     *
     * @param array|null $guide Guide person data or null.
     * @param string     $type  'coach' or 'mentor'.
     */
    private function render_guide_widget($guide, $type) {
        $is_coach_type = ($type === 'coach');
        $label         = $is_coach_type ? __('My Coach', 'hl-core') : __('My Mentor', 'hl-core');
        $no_assign_msg = $is_coach_type
            ? __('No coach assigned yet. Contact your administrator.', 'hl-core')
            : __('No mentor assigned yet. Contact your administrator.', 'hl-core');

        $person_name  = '';
        $person_email = '';
        $person_id    = 0;
        if ($guide) {
            if ($is_coach_type) {
                $person_name  = $guide['coach_name'] ?? '';
                $person_email = $guide['coach_email'] ?? '';
                $person_id    = $guide['coach_user_id'] ?? 0;
            } else {
                $person_name  = $guide['display_name'] ?? '';
                $person_email = $guide['user_email'] ?? '';
                $person_id    = $guide['user_id'] ?? 0;
            }
        }

        ?>
        <div class="hl-coach-widget">
            <?php if ($guide && $person_name) : ?>
                <div class="hl-coach-avatar"><?php echo get_avatar($person_id, 56); ?></div>
                <div class="hl-coach-widget-info">
                    <strong><?php echo esc_html($label); ?></strong>
                    <?php echo esc_html($person_name); ?> &middot;
                    <a href="mailto:<?php echo esc_attr($person_email); ?>"><?php echo esc_html($person_email); ?></a>
                </div>
                <?php if ($is_coach_type) :
                    $coaching_page_url = $this->find_shortcode_page_url('hl_my_coaching');
                    if ($coaching_page_url) : ?>
                        <a href="<?php echo esc_url($coaching_page_url); ?>" class="hl-btn hl-btn-sm hl-btn-primary">
                            <?php esc_html_e('Schedule a Session', 'hl-core'); ?>
                        </a>
                    <?php endif; ?>
                <?php endif; ?>
            <?php else : ?>
                <div class="hl-coach-avatar-placeholder">?</div>
                <div class="hl-coach-widget-info">
                    <strong><?php echo esc_html($label); ?></strong>
                    <span class="hl-text-muted"><?php echo esc_html($no_assign_msg); ?></span>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Resolve the mentor for a teacher via team membership.
     *
     * Finds the team the teacher belongs to, then looks for a member with
     * the 'mentor' role in that same team.
     *
     * @param int   $user_id     Teacher's WP user ID.
     * @param array $enrollments Teacher's active enrollments.
     * @return array|null { user_id, display_name, user_email } or null.
     */
    private function resolve_mentor_for_teacher($user_id, $enrollments) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        foreach ($enrollments as $e) {
            // Find the team this teacher belongs to.
            $team_id = $wpdb->get_var($wpdb->prepare(
                "SELECT tm.team_id FROM {$prefix}hl_team_membership tm
                 JOIN {$prefix}hl_team t ON tm.team_id = t.team_id
                 WHERE tm.enrollment_id = %d AND t.cycle_id = %d LIMIT 1",
                $e->enrollment_id, $e->cycle_id
            ));

            if (!$team_id) continue;

            // Find a mentor in the same team.
            $mentor = $wpdb->get_row($wpdb->prepare(
                "SELECT u.ID AS user_id, u.display_name, u.user_email
                 FROM {$prefix}hl_team_membership tm
                 JOIN {$prefix}hl_enrollment en ON tm.enrollment_id = en.enrollment_id
                 JOIN {$wpdb->users} u ON en.user_id = u.ID
                 WHERE tm.team_id = %d AND en.status = 'active'
                   AND en.roles LIKE %s
                 LIMIT 1",
                $team_id, '%"mentor"%'
            ), ARRAY_A);

            if ($mentor) return $mentor;
        }

        return null;
    }

    /**
     * Query the hl_component_state table.
     *
     * @param int $enrollment_id
     * @param int $component_id
     * @return array|null
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
