<?php
if (!defined('ABSPATH')) exit;

/**
 * Renderer for the [hl_user_profile] shortcode.
 *
 * Unified user profile page with role-based tabs:
 * Overview, Progress, Coaching, Assessments, RP & Observations, Manage (admin).
 *
 * Routes: ?user_id=X  or  ?enrollment_id=X  or  (no param = own profile)
 *
 * @package HL_Core
 */
class HL_Frontend_User_Profile {

    /** @var HL_Enrollment_Repository */
    private $enrollment_repo;

    /** @var HL_OrgUnit_Repository */
    private $orgunit_repo;

    /** @var HL_Classroom_Service */
    private $classroom_service;

    /** @var HL_Pathway_Assignment_Service */
    private $pathway_service;

    /** @var HL_Coach_Assignment_Service */
    private $coach_service;

    public function __construct() {
        $this->enrollment_repo  = new HL_Enrollment_Repository();
        $this->orgunit_repo     = new HL_OrgUnit_Repository();
        $this->classroom_service = new HL_Classroom_Service();
        $this->pathway_service  = new HL_Pathway_Assignment_Service();
        $this->coach_service    = new HL_Coach_Assignment_Service();
    }

    /**
     * Main render entry point.
     *
     * @param array $atts Shortcode attributes.
     * @return string
     */
    public function render($atts) {
        ob_start();

        $current_user_id = get_current_user_id();

        // ── Resolve target user ──────────────────────────────────────
        $target_user_id = $this->resolve_target_user($current_user_id);

        if (!$target_user_id) {
            echo '<div class="hl-notice hl-notice-error">'
                . esc_html__('User not found.', 'hl-core')
                . '</div>';
            return ob_get_clean();
        }

        $target_user = get_userdata($target_user_id);
        if (!$target_user) {
            echo '<div class="hl-notice hl-notice-error">'
                . esc_html__('User not found.', 'hl-core')
                . '</div>';
            return ob_get_clean();
        }

        // ── Load enrollments ─────────────────────────────────────────
        $enrollments = $this->enrollment_repo->get_by_user_id($target_user_id, 'active');
        $is_own_profile = ($current_user_id === $target_user_id);

        // ── Access control ───────────────────────────────────────────
        if (!$this->can_view_profile($current_user_id, $target_user_id, $enrollments)) {
            echo '<div class="hl-notice hl-notice-error">'
                . esc_html__('You do not have permission to view this profile.', 'hl-core')
                . '</div>';
            return ob_get_clean();
        }

        // ── Select active enrollment (cycle selector) ────────────────
        $active_enrollment = $this->resolve_active_enrollment($enrollments);

        // ── Determine visible tabs ───────────────────────────────────
        $is_admin = current_user_can('manage_hl_core');
        $tabs = $this->get_visible_tabs($current_user_id, $target_user_id, $is_admin, $is_own_profile, $enrollments);

        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'overview';
        if (!isset($tabs[$active_tab])) {
            $active_tab = 'overview';
        }

        // ── Load overview data ───────────────────────────────────────
        $overview = $this->load_overview_data($target_user, $active_enrollment, $enrollments);

        ?>
        <div class="hlup-wrapper">

            <?php $this->render_hero($target_user, $overview, $is_own_profile); ?>

            <?php if (count($enrollments) > 1) : ?>
                <?php $this->render_cycle_selector($enrollments, $active_enrollment); ?>
            <?php endif; ?>

            <?php $this->render_tabs($tabs, $active_tab); ?>

            <div class="hlup-tab-content">
                <?php
                switch ($active_tab) {
                    case 'overview':
                        $this->render_overview_tab($target_user, $overview, $active_enrollment, $is_admin);
                        break;
                    case 'progress':
                        $this->render_placeholder_tab(__('Progress', 'hl-core'), __('Pathway completion and component status will appear here.', 'hl-core'));
                        break;
                    case 'coaching':
                        $this->render_placeholder_tab(__('Coaching', 'hl-core'), __('Coaching sessions and action plans will appear here.', 'hl-core'));
                        break;
                    case 'assessments':
                        $this->render_placeholder_tab(__('Assessments', 'hl-core'), __('TSA and CA status will appear here.', 'hl-core'));
                        break;
                    case 'rp':
                        $this->render_placeholder_tab(__('RP & Observations', 'hl-core'), __('Reflective practice and classroom visits will appear here.', 'hl-core'));
                        break;
                    case 'manage':
                        $this->render_placeholder_tab(__('Manage', 'hl-core'), __('Admin management actions will appear here.', 'hl-core'));
                        break;
                }
                ?>
            </div>
        </div>
        <?php

        return ob_get_clean();
    }

    // =====================================================================
    // Resolution & Access Control
    // =====================================================================

    /**
     * Resolve the target user ID from query params.
     *
     * @param int $current_user_id
     * @return int|null
     */
    private function resolve_target_user($current_user_id) {
        if (!empty($_GET['user_id'])) {
            $uid = absint($_GET['user_id']);
            return $uid > 0 ? $uid : null;
        }

        if (!empty($_GET['enrollment_id'])) {
            $enrollment = $this->enrollment_repo->get_by_id(absint($_GET['enrollment_id']));
            return $enrollment ? (int) $enrollment->user_id : null;
        }

        // No parameter — own profile.
        return $current_user_id;
    }

    /**
     * Check if the current viewer can see this profile.
     *
     * Rules:
     *  - Own profile: always
     *  - Admin/Staff (manage_hl_core): always
     *  - Coach: assigned mentors (via hl_coach_assignment)
     *  - Mentor: team members (via hl_team_membership)
     *  - School Leader: staff in their school (via enrollment school_id)
     *
     * @param int              $viewer_id
     * @param int              $target_id
     * @param HL_Enrollment[]  $target_enrollments
     * @return bool
     */
    private function can_view_profile($viewer_id, $target_id, $target_enrollments) {
        // Own profile.
        if ($viewer_id === $target_id) {
            return true;
        }

        // Admin / Staff.
        if (current_user_can('manage_hl_core')) {
            return true;
        }

        // Get viewer's enrollments.
        $viewer_enrollments = $this->enrollment_repo->get_by_user_id($viewer_id, 'active');
        if (empty($viewer_enrollments)) {
            return false;
        }

        // Collect target's school IDs and enrollment IDs.
        $target_school_ids     = array();
        $target_enrollment_ids = array();
        foreach ($target_enrollments as $e) {
            if ($e->school_id) {
                $target_school_ids[] = (int) $e->school_id;
            }
            $target_enrollment_ids[] = (int) $e->enrollment_id;
        }

        // Coach uses WP roles (not enrollment roles) because coaches have a
        // dedicated WP role and their access is determined by hl_coach_assignment,
        // not by per-enrollment role arrays like school_leader/mentor.
        if (in_array('coach', (array) wp_get_current_user()->roles, true)) {
            if ($this->is_coach_of_target($viewer_id, $target_enrollment_ids)) {
                return true;
            }
        }

        // Remaining checks use per-enrollment HL roles.
        foreach ($viewer_enrollments as $ve) {
            $roles = $ve->get_roles_array();

            // School Leader: target in same school.
            if (in_array('school_leader', $roles, true)) {
                if ($ve->school_id && in_array((int) $ve->school_id, $target_school_ids, true)) {
                    return true;
                }
            }

            // District Leader: target in district's schools.
            if (in_array('district_leader', $roles, true)) {
                if ($ve->district_id && $this->target_in_district($ve->district_id, $target_school_ids)) {
                    return true;
                }
            }

            // Mentor: target is team member.
            if (in_array('mentor', $roles, true)) {
                if ($this->is_team_member($ve->enrollment_id, $target_enrollment_ids)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if viewer is a coach assigned to any of the target's enrollments.
     */
    private function is_coach_of_target($coach_user_id, $target_enrollment_ids) {
        if (empty($target_enrollment_ids)) {
            return false;
        }

        global $wpdb;
        $in = implode(',', array_map('intval', $target_enrollment_ids));

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}hl_coach_assignment
             WHERE coach_user_id = %d
               AND scope_type = 'enrollment'
               AND scope_id IN ({$in})",
            $coach_user_id
        ));

        return (int) $count > 0;
    }

    /**
     * Check if any target school belongs to the viewer's district.
     */
    private function target_in_district($district_id, $target_school_ids) {
        if (empty($target_school_ids)) {
            return false;
        }

        global $wpdb;
        $in = implode(',', array_map('intval', $target_school_ids));

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}hl_orgunit
             WHERE orgunit_id IN ({$in})
               AND parent_orgunit_id = %d",
            $district_id
        ));

        return (int) $count > 0;
    }

    /**
     * Check if any target enrollment is a team member of the viewer's enrollment.
     */
    private function is_team_member($viewer_enrollment_id, $target_enrollment_ids) {
        if (empty($target_enrollment_ids)) {
            return false;
        }

        global $wpdb;
        $in = implode(',', array_map('intval', $target_enrollment_ids));

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$wpdb->prefix}hl_team_membership tm1
             JOIN {$wpdb->prefix}hl_team_membership tm2 ON tm1.team_id = tm2.team_id
             WHERE tm1.enrollment_id = %d
               AND tm2.enrollment_id IN ({$in})",
            $viewer_enrollment_id
        ));

        return (int) $count > 0;
    }

    // =====================================================================
    // Enrollment Resolution
    // =====================================================================

    /**
     * Resolve which enrollment to display (from query param or most recent).
     *
     * @param HL_Enrollment[] $enrollments
     * @return HL_Enrollment|null
     */
    private function resolve_active_enrollment($enrollments) {
        if (empty($enrollments)) {
            return null;
        }

        // If enrollment_id is in the URL, use it.
        if (!empty($_GET['enrollment_id'])) {
            $eid = absint($_GET['enrollment_id']);
            foreach ($enrollments as $e) {
                if ((int) $e->enrollment_id === $eid) {
                    return $e;
                }
            }
        }

        // If cycle_id is in the URL, match first enrollment for that cycle.
        if (!empty($_GET['cycle_id'])) {
            $cid = absint($_GET['cycle_id']);
            foreach ($enrollments as $e) {
                if ((int) $e->cycle_id === $cid) {
                    return $e;
                }
            }
        }

        // Default: first (most recent) enrollment.
        return $enrollments[0];
    }

    // =====================================================================
    // Tab Visibility
    // =====================================================================

    /**
     * Determine which tabs the current viewer can see.
     *
     * @return array<string, array{label: string, icon: string}>
     */
    private function get_visible_tabs($viewer_id, $target_id, $is_admin, $is_own_profile, $target_enrollments) {
        $tabs = array();

        // Pre-compute coach-of-target once (used by coaching + RP tabs).
        $is_coach_of_target = false;
        if (in_array('coach', (array) wp_get_current_user()->roles, true)) {
            $target_eids = array_map(function($e) { return (int) $e->enrollment_id; }, $target_enrollments);
            $is_coach_of_target = $this->is_coach_of_target($viewer_id, $target_eids);
        }

        // Overview — always visible.
        $tabs['overview'] = array(
            'label' => __('Overview', 'hl-core'),
            'icon'  => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
        );

        // Progress — always visible if there are enrollments.
        if (!empty($target_enrollments)) {
            $tabs['progress'] = array(
                'label' => __('Progress', 'hl-core'),
                'icon'  => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/><line x1="6" y1="20" x2="6" y2="16"/></svg>',
            );
        }

        // Coaching — own profile, admin, or coach of this user.
        $show_coaching = $is_own_profile || $is_admin || $is_coach_of_target;
        if ($show_coaching && !empty($target_enrollments)) {
            $tabs['coaching'] = array(
                'label' => __('Coaching', 'hl-core'),
                'icon'  => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
            );
        }

        // Assessments — everyone with access sees status; admin sees full.
        if (!empty($target_enrollments)) {
            $tabs['assessments'] = array(
                'label' => __('Assessments', 'hl-core'),
                'icon'  => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>',
            );
        }

        // RP & Observations — own profile, admin, or coach.
        $show_rp = $is_own_profile || $is_admin || $is_coach_of_target;
        if ($show_rp && !empty($target_enrollments)) {
            $tabs['rp'] = array(
                'label' => __('RP & Observations', 'hl-core'),
                'icon'  => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>',
            );
        }

        // Manage — admin only.
        if ($is_admin && !$is_own_profile) {
            $tabs['manage'] = array(
                'label' => __('Manage', 'hl-core'),
                'icon'  => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>',
            );
        }

        return $tabs;
    }

    // =====================================================================
    // Data Loading
    // =====================================================================

    /**
     * Load all data needed for the overview tab.
     *
     * @param WP_User          $user
     * @param HL_Enrollment|null $enrollment
     * @param HL_Enrollment[]  $all_enrollments
     * @return array
     */
    private function load_overview_data($user, $enrollment, $all_enrollments) {
        $data = array(
            'school'       => null,
            'district'     => null,
            'cycle_name'   => null,
            'roles'        => array(),
            'classrooms'   => array(),
            'coach'        => null,
            'mentor_name'  => null,
            'team_name'    => null,
            'pathways'     => array(),
            'enrolled_at'  => null,
            'completion'   => 0,
        );

        if (!$enrollment) {
            return $data;
        }

        // School.
        if ($enrollment->school_id) {
            $data['school'] = $this->orgunit_repo->get_by_id($enrollment->school_id);
        }

        // District.
        if ($enrollment->district_id) {
            $data['district'] = $this->orgunit_repo->get_by_id($enrollment->district_id);
        } elseif ($data['school'] && !empty($data['school']->parent_orgunit_id)) {
            $data['district'] = $this->orgunit_repo->get_by_id($data['school']->parent_orgunit_id);
        }

        // Cycle name.
        global $wpdb;
        $data['cycle_name'] = $wpdb->get_var($wpdb->prepare(
            "SELECT cycle_name FROM {$wpdb->prefix}hl_cycle WHERE cycle_id = %d",
            $enrollment->cycle_id
        ));

        // Roles.
        $data['roles'] = $enrollment->get_roles_array();

        // Classrooms (for teachers).
        if (in_array('teacher', $data['roles'], true)) {
            $data['classrooms'] = $this->classroom_service->get_classrooms_for_teacher($enrollment->enrollment_id);
        }

        // Coach assignment.
        $coach = $this->coach_service->get_coach_for_enrollment($enrollment->enrollment_id, $enrollment->cycle_id);
        if ($coach) {
            $data['coach'] = $coach;
        }

        // Team / Mentor lookup.
        $team_info = $this->get_team_info($enrollment->enrollment_id);
        if ($team_info) {
            $data['team_name']   = $team_info['team_name'];
            $data['mentor_name'] = $team_info['mentor_name'];
        }

        // Pathways.
        $data['pathways'] = $this->pathway_service->get_pathways_for_enrollment($enrollment->enrollment_id);

        // Enrolled at.
        $data['enrolled_at'] = $enrollment->enrolled_at;

        // Completion rollup.
        $data['completion'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(completion_pct, 0) FROM {$wpdb->prefix}hl_completion_rollup
             WHERE enrollment_id = %d ORDER BY computed_at DESC LIMIT 1",
            $enrollment->enrollment_id
        ));

        return $data;
    }

    /**
     * Get team name and mentor name for an enrollment.
     */
    private function get_team_info($enrollment_id) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        $team = $wpdb->get_row($wpdb->prepare(
            "SELECT t.team_id, t.team_name
             FROM {$prefix}hl_team_membership tm
             JOIN {$prefix}hl_team t ON tm.team_id = t.team_id
             WHERE tm.enrollment_id = %d
             LIMIT 1",
            $enrollment_id
        ));

        if (!$team) {
            return null;
        }

        // Find the mentor in the same team.
        $mentor_name = $wpdb->get_var($wpdb->prepare(
            "SELECT u.display_name
             FROM {$prefix}hl_team_membership tm
             JOIN {$prefix}hl_enrollment e ON tm.enrollment_id = e.enrollment_id
             JOIN {$wpdb->users} u ON e.user_id = u.ID
             WHERE tm.team_id = %d
               AND e.roles LIKE %s
               AND e.status = 'active'
             LIMIT 1",
            $team->team_id,
            '%"mentor"%'
        ));

        return array(
            'team_name'   => $team->team_name,
            'mentor_name' => $mentor_name ?: null,
        );
    }

    // =====================================================================
    // Rendering — Hero
    // =====================================================================

    private function render_hero($user, $overview, $is_own_profile) {
        $display_name = $user->display_name;
        $email        = $user->user_email;
        $avatar       = get_avatar($user->ID, 96, '', $display_name, array('class' => 'hlup-avatar-img'));
        $school_name  = $overview['school'] ? $overview['school']->name : null;
        $roles        = $overview['roles'];
        $completion   = $overview['completion'];
        ?>
        <div class="hlup-hero">
            <div class="hlup-hero-left">
                <div class="hlup-hero-avatar">
                    <?php echo $avatar; ?>
                </div>
                <div class="hlup-hero-info">
                    <h1 class="hlup-hero-name"><?php echo esc_html($display_name); ?></h1>
                    <div class="hlup-hero-meta">
                        <?php if (!empty($roles)) : ?>
                            <span class="hlup-hero-roles">
                                <?php foreach ($roles as $role) : ?>
                                    <span class="hlup-role-badge"><?php echo esc_html(ucfirst($role)); ?></span>
                                <?php endforeach; ?>
                            </span>
                        <?php endif; ?>
                        <?php if ($school_name) : ?>
                            <span class="hlup-hero-school">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                                <?php echo esc_html($school_name); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php if (!empty($overview['roles'])) : ?>
                <div class="hlup-hero-right">
                    <div class="hlup-hero-completion">
                        <div class="hlup-completion-circle" data-pct="<?php echo esc_attr($completion); ?>">
                            <svg viewBox="0 0 36 36" class="hlup-completion-svg">
                                <path class="hlup-circle-bg" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"/>
                                <path class="hlup-circle-fill" stroke-dasharray="<?php echo esc_attr($completion); ?>, 100" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"/>
                            </svg>
                            <span class="hlup-completion-text"><?php echo esc_html($completion); ?>%</span>
                        </div>
                        <span class="hlup-completion-label"><?php esc_html_e('Overall Completion', 'hl-core'); ?></span>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    // =====================================================================
    // Rendering — Cycle Selector
    // =====================================================================

    private function render_cycle_selector($enrollments, $active_enrollment) {
        global $wpdb;

        // Pre-fetch cycle names.
        $cycle_ids = array_unique(array_map(function($e) { return (int) $e->cycle_id; }, $enrollments));
        $in = implode(',', $cycle_ids);
        $cycles = $wpdb->get_results(
            "SELECT cycle_id, cycle_name FROM {$wpdb->prefix}hl_cycle WHERE cycle_id IN ({$in})",
            OBJECT_K
        );

        $base_url = remove_query_arg(array('enrollment_id', 'cycle_id', 'tab'));
        if (!empty($_GET['user_id'])) {
            $base_url = add_query_arg('user_id', absint($_GET['user_id']), $base_url);
        }
        ?>
        <div class="hlup-cycle-selector">
            <span class="hlup-cycle-label"><?php esc_html_e('Cycle:', 'hl-core'); ?></span>
            <?php foreach ($enrollments as $e) :
                $cname = isset($cycles[$e->cycle_id]) ? $cycles[$e->cycle_id]->cycle_name : __('Unknown', 'hl-core');
                $is_active = $active_enrollment && (int) $e->enrollment_id === (int) $active_enrollment->enrollment_id;
                $url = add_query_arg('enrollment_id', $e->enrollment_id, $base_url);
            ?>
                <a href="<?php echo esc_url($url); ?>"
                   class="hlup-cycle-pill <?php echo $is_active ? 'active' : ''; ?>">
                    <?php echo esc_html($cname); ?>
                </a>
            <?php endforeach; ?>
        </div>
        <?php
    }

    // =====================================================================
    // Rendering — Tab Navigation
    // =====================================================================

    private function render_tabs($tabs, $active_tab) {
        $base_url = remove_query_arg('tab');
        ?>
        <div class="hlup-tabs">
            <?php foreach ($tabs as $key => $tab) :
                $url = add_query_arg('tab', $key, $base_url);
                $is_active = ($key === $active_tab);
            ?>
                <a href="<?php echo esc_url($url); ?>"
                   class="hlup-tab <?php echo $is_active ? 'active' : ''; ?>">
                    <?php echo $tab['icon']; ?>
                    <span class="hlup-tab-label"><?php echo esc_html($tab['label']); ?></span>
                </a>
            <?php endforeach; ?>
        </div>
        <?php
    }

    // =====================================================================
    // Rendering — Overview Tab
    // =====================================================================

    private function render_overview_tab($user, $overview, $enrollment, $is_admin) {
        if (!$enrollment) {
            ?>
            <div class="hlup-empty-state">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                <h3><?php echo esc_html($user->display_name); ?></h3>
                <p><?php esc_html_e('This user has no active program enrollment.', 'hl-core'); ?></p>
                <p class="hlup-empty-email"><?php echo esc_html($user->user_email); ?></p>
            </div>
            <?php
            return;
        }

        ?>
        <div class="hlup-overview-grid">

            <!-- Contact & Identity -->
            <div class="hlup-card">
                <div class="hlup-card-header">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <h3><?php esc_html_e('Contact', 'hl-core'); ?></h3>
                </div>
                <div class="hlup-card-body">
                    <div class="hlup-field">
                        <span class="hlup-field-label"><?php esc_html_e('Email', 'hl-core'); ?></span>
                        <span class="hlup-field-value"><?php echo esc_html($user->user_email); ?></span>
                    </div>
                    <?php if ($overview['district']) : ?>
                    <div class="hlup-field">
                        <span class="hlup-field-label"><?php esc_html_e('District', 'hl-core'); ?></span>
                        <span class="hlup-field-value"><?php echo esc_html($overview['district']->name); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($overview['school']) : ?>
                    <div class="hlup-field">
                        <span class="hlup-field-label"><?php esc_html_e('School', 'hl-core'); ?></span>
                        <span class="hlup-field-value"><?php echo esc_html($overview['school']->name); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($overview['enrolled_at']) : ?>
                    <div class="hlup-field">
                        <span class="hlup-field-label"><?php esc_html_e('Enrolled', 'hl-core'); ?></span>
                        <span class="hlup-field-value"><?php echo esc_html(date_i18n('M j, Y', strtotime($overview['enrolled_at']))); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Program & Pathway -->
            <div class="hlup-card">
                <div class="hlup-card-header">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                    <h3><?php esc_html_e('Program', 'hl-core'); ?></h3>
                </div>
                <div class="hlup-card-body">
                    <?php if ($overview['cycle_name']) : ?>
                    <div class="hlup-field">
                        <span class="hlup-field-label"><?php esc_html_e('Cycle', 'hl-core'); ?></span>
                        <span class="hlup-field-value"><?php echo esc_html($overview['cycle_name']); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($overview['pathways'])) : ?>
                    <div class="hlup-field">
                        <span class="hlup-field-label"><?php esc_html_e('Learning Plan', 'hl-core'); ?></span>
                        <span class="hlup-field-value">
                            <?php
                            $names = array_map(function($pw) { return $pw['pathway_name']; }, $overview['pathways']);
                            echo esc_html(implode(', ', $names));
                            ?>
                        </span>
                    </div>
                    <?php endif; ?>
                    <div class="hlup-field">
                        <span class="hlup-field-label"><?php esc_html_e('Completion', 'hl-core'); ?></span>
                        <div class="hlup-field-progress">
                            <div class="hlup-progress-track">
                                <div class="hlup-progress-fill <?php echo $overview['completion'] >= 100 ? 'complete' : ''; ?>"
                                     style="width:<?php echo esc_attr($overview['completion']); ?>%"></div>
                            </div>
                            <span class="hlup-progress-pct"><?php echo esc_html($overview['completion']); ?>%</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Team & Coaching -->
            <div class="hlup-card">
                <div class="hlup-card-header">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    <h3><?php esc_html_e('Team & Coaching', 'hl-core'); ?></h3>
                </div>
                <div class="hlup-card-body">
                    <?php if ($overview['team_name']) : ?>
                    <div class="hlup-field">
                        <span class="hlup-field-label"><?php esc_html_e('Team', 'hl-core'); ?></span>
                        <span class="hlup-field-value"><?php echo esc_html($overview['team_name']); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($overview['mentor_name']) : ?>
                    <div class="hlup-field">
                        <span class="hlup-field-label"><?php esc_html_e('Mentor', 'hl-core'); ?></span>
                        <span class="hlup-field-value"><?php echo esc_html($overview['mentor_name']); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($overview['coach']) : ?>
                    <div class="hlup-field">
                        <span class="hlup-field-label"><?php esc_html_e('Coach', 'hl-core'); ?></span>
                        <span class="hlup-field-value"><?php echo esc_html($overview['coach']->coach_name); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (empty($overview['team_name']) && empty($overview['mentor_name']) && empty($overview['coach'])) : ?>
                    <p class="hlup-field-empty"><?php esc_html_e('No team or coaching assignments yet.', 'hl-core'); ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Classrooms (for teachers) -->
            <?php if (!empty($overview['classrooms'])) : ?>
            <div class="hlup-card">
                <div class="hlup-card-header">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                    <h3><?php esc_html_e('Classrooms', 'hl-core'); ?></h3>
                </div>
                <div class="hlup-card-body">
                    <?php foreach ($overview['classrooms'] as $cr) : ?>
                    <div class="hlup-classroom-item">
                        <span class="hlup-classroom-name"><?php echo esc_html($cr->classroom_name); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

        </div>
        <?php
    }

    // =====================================================================
    // Rendering — Placeholder Tab (for future phases)
    // =====================================================================

    private function render_placeholder_tab($title, $message) {
        ?>
        <div class="hlup-placeholder-tab">
            <div class="hlup-placeholder-icon">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
            </div>
            <h3 class="hlup-placeholder-title"><?php echo esc_html($title); ?></h3>
            <p class="hlup-placeholder-text"><?php echo esc_html($message); ?></p>
        </div>
        <?php
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * Find page URL for a given shortcode.
     *
     * // TODO: Phase 7 — used for entry point wiring (linking names to profiles).
     */
    private function find_shortcode_page_url($shortcode) {
        global $wpdb;
        $page_id = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'page' AND post_status = 'publish'
             AND post_content LIKE %s LIMIT 1",
            '%[' . $wpdb->esc_like($shortcode) . '%'
        ));
        return $page_id ? get_permalink($page_id) : '';
    }
}
