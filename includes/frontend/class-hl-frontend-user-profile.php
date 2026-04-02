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

    /** @var HL_Component_Repository */
    private $component_repo;

    /** @var HL_Reporting_Service */
    private $reporting_service;

    public function __construct() {
        $this->enrollment_repo   = new HL_Enrollment_Repository();
        $this->orgunit_repo      = new HL_OrgUnit_Repository();
        $this->classroom_service = new HL_Classroom_Service();
        $this->pathway_service   = new HL_Pathway_Assignment_Service();
        $this->coach_service     = new HL_Coach_Assignment_Service();
        $this->component_repo    = new HL_Component_Repository();
        $this->reporting_service = HL_Reporting_Service::instance();
    }

    /**
     * Handle POST actions (hooked to template_redirect).
     */
    public static function handle_post_actions() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !is_user_logged_in()) {
            return;
        }

        $action = isset($_POST['hlup_action']) ? sanitize_text_field($_POST['hlup_action']) : '';
        if (empty($action)) {
            return;
        }

        $enrollment_id = absint($_POST['hlup_enrollment_id'] ?? 0);
        $user_id       = absint($_POST['hlup_user_id'] ?? 0);

        if (!$enrollment_id && !$user_id) {
            return;
        }

        // Allow users to edit their own profile; require manage_hl_core for everything else.
        $is_own_profile = ($action === 'update_profile' && $user_id === get_current_user_id());
        if (!$is_own_profile && !current_user_can('manage_hl_core')) {
            return;
        }

        $nonce_action = 'hlup_manage_' . ($enrollment_id ?: $user_id);
        if (!wp_verify_nonce($_POST['_hlup_nonce'] ?? '', $nonce_action)) {
            return;
        }

        $enrollment_repo = new HL_Enrollment_Repository();

        switch ($action) {
            case 'update_profile':
                $display_name = sanitize_text_field($_POST['hlup_display_name'] ?? '');
                $email        = sanitize_email($_POST['hlup_email'] ?? '');

                if ($display_name && $user_id) {
                    wp_update_user(array('ID' => $user_id, 'display_name' => $display_name));
                }
                if ($email && $user_id && is_email($email)) {
                    wp_update_user(array('ID' => $user_id, 'user_email' => $email));
                }

                HL_Audit_Service::log('profile.updated', array(
                    'entity_type' => 'user',
                    'entity_id'   => $user_id,
                    'after_data'  => array('display_name' => $display_name, 'email' => $email),
                ));
                break;

            case 'update_enrollment':
                $enrollment = $enrollment_repo->get_by_id($enrollment_id);
                if (!$enrollment) break;

                $before = $enrollment->to_array();
                $updates = array();

                $new_status = sanitize_text_field($_POST['hlup_status'] ?? '');
                if ($new_status && in_array($new_status, array('active', 'inactive'), true)) {
                    $updates['status'] = $new_status;
                }

                $new_school = absint($_POST['hlup_school_id'] ?? 0);
                if ($new_school > 0) {
                    $updates['school_id'] = $new_school;
                    // Update district_id from school's parent.
                    $orgunit_repo = new HL_OrgUnit_Repository();
                    $school = $orgunit_repo->get_by_id($new_school);
                    if ($school && $school->parent_orgunit_id) {
                        $updates['district_id'] = (int) $school->parent_orgunit_id;
                    }
                }

                $new_roles = isset($_POST['hlup_roles']) ? array_map('sanitize_text_field', (array) $_POST['hlup_roles']) : null;
                $valid_roles = array('teacher', 'mentor', 'school_leader', 'district_leader');
                if ($new_roles !== null) {
                    $updates['roles'] = array_values(array_intersect($new_roles, $valid_roles));
                }

                if (!empty($updates)) {
                    $enrollment_repo->update($enrollment_id, $updates);
                    HL_Audit_Service::log('enrollment.updated', array(
                        'entity_type' => 'enrollment',
                        'entity_id'   => $enrollment_id,
                        'cycle_id'    => $enrollment->cycle_id,
                        'before_data' => $before,
                        'after_data'  => $updates,
                    ));
                }
                break;

            case 'assign_pathway':
                $pathway_id = absint($_POST['hlup_pathway_id'] ?? 0);
                if ($pathway_id && $enrollment_id) {
                    $pa_service = new HL_Pathway_Assignment_Service();
                    $pa_service->assign_pathway($enrollment_id, $pathway_id, 'explicit');
                    HL_Audit_Service::log('pathway.assigned', array(
                        'entity_type' => 'enrollment',
                        'entity_id'   => $enrollment_id,
                        'after_data'  => array('pathway_id' => $pathway_id),
                    ));
                }
                break;

            case 'unassign_pathway':
                $pathway_id = absint($_POST['hlup_pathway_id'] ?? 0);
                if ($pathway_id && $enrollment_id) {
                    $pa_service = new HL_Pathway_Assignment_Service();
                    $pa_service->unassign_pathway($enrollment_id, $pathway_id);
                    HL_Audit_Service::log('pathway.unassigned', array(
                        'entity_type' => 'enrollment',
                        'entity_id'   => $enrollment_id,
                        'after_data'  => array('pathway_id' => $pathway_id),
                    ));
                }
                break;

            case 'send_password_reset':
                if ($user_id) {
                    $user = get_userdata($user_id);
                    if ($user) {
                        retrieve_password($user->user_login);
                        HL_Audit_Service::log('user.password_reset_sent', array(
                            'entity_type' => 'user',
                            'entity_id'   => $user_id,
                        ));
                    }
                }
                break;

            case 'deactivate_enrollment':
                if ($enrollment_id) {
                    $enrollment = $enrollment_repo->get_by_id($enrollment_id);
                    if ($enrollment) {
                        $enrollment_repo->update($enrollment_id, array('status' => 'inactive'));
                        HL_Audit_Service::log('enrollment.deactivated', array(
                            'entity_type' => 'enrollment',
                            'entity_id'   => $enrollment_id,
                            'cycle_id'    => $enrollment->cycle_id,
                        ));
                    }
                }
                break;
        }

        // Redirect back to prevent form resubmission.
        $redirect = add_query_arg('hlup_updated', '1', remove_query_arg(array('_hlup_nonce', 'hlup_updated')));
        wp_safe_redirect($redirect);
        exit;
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

        // ── View As / Return support ────────────────────────────────
        $is_coach = in_array('coach', (array) wp_get_current_user()->roles, true);
        $can_switch = class_exists('BP_Core_Members_Switching')
                      && ($is_admin || $is_coach)
                      && !$is_own_profile
                      && !user_can($target_user_id, 'manage_hl_core'); // Don't allow switching to admins.
        $switch_url = '';
        if ($can_switch) {
            $switch_url = BP_Core_Members_Switching::switch_to_url($target_user);
        }

        // Detect if current session is switched (return-to-original).
        $old_user        = null;
        $profile_switch_back_url = '';
        if (class_exists('BP_Core_Members_Switching')) {
            $old_user = BP_Core_Members_Switching::get_old_user();
            if ($old_user) {
                $profile_switch_back_url = BP_Core_Members_Switching::switch_back_url($old_user);
            }
        } elseif (function_exists('user_switching_get_old_user')) {
            $old_user = user_switching_get_old_user();
            if ($old_user) {
                $profile_switch_back_url = user_switching_get_switchback_url();
            }
        }

        ?>
        <div class="hlup-wrapper">

            <?php if ($old_user && $profile_switch_back_url) : ?>
                <?php $switch_back_url = $profile_switch_back_url; ?>
                <?php if ($switch_back_url) : ?>
                <div class="hlup-switch-banner">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 14l-4-4 4-4"/><path d="M5 10h11a4 4 0 1 1 0 8h-1"/></svg>
                    <?php echo esc_html(sprintf(
                        __('You are viewing as %s.', 'hl-core'),
                        wp_get_current_user()->display_name
                    )); ?>
                    <a href="<?php echo esc_url($switch_back_url); ?>" class="hlup-switch-back-link">
                        <?php echo esc_html(sprintf(
                            __('Return to %s', 'hl-core'),
                            $old_user->display_name
                        )); ?>
                    </a>
                </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php $this->render_breadcrumbs($target_user, $overview, $is_own_profile); ?>

            <?php $this->render_hero($target_user, $overview, $is_own_profile, $switch_url); ?>

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
                        $this->render_progress_tab($target_user, $active_enrollment, $enrollments, $overview);
                        break;
                    case 'coaching':
                        $this->render_coaching_tab($target_user, $active_enrollment, $is_admin);
                        break;
                    case 'assessments':
                        $this->render_assessments_tab($target_user, $active_enrollment, $is_admin);
                        break;
                    case 'rp':
                        $this->render_rp_tab($target_user, $active_enrollment);
                        break;
                    case 'manage':
                        $this->render_manage_tab($target_user, $active_enrollment);
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
        // Check BEFORE the enrollment guard — coaches may have no HL enrollment.
        if (in_array('coach', (array) wp_get_current_user()->roles, true)) {
            if ($this->is_coach_of_target($viewer_id, $target_enrollment_ids)) {
                return true;
            }
        }

        // Get viewer's enrollments.
        $viewer_enrollments = $this->enrollment_repo->get_by_user_id($viewer_id, 'active');
        if (empty($viewer_enrollments)) {
            return false;
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

        // Use the same roster logic as the coach assignment service:
        // check enrollment, school, and team scope types.
        $svc = new HL_Coach_Assignment_Service();

        // Get target enrollments' cycle IDs.
        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($target_enrollment_ids), '%d'));
        $cycle_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT cycle_id FROM {$wpdb->prefix}hl_enrollment
             WHERE enrollment_id IN ($placeholders)",
            array_map('intval', $target_enrollment_ids)
        ));

        // For each cycle, get the coach's roster and check if target is in it.
        foreach ($cycle_ids as $cid) {
            $roster = $svc->get_coach_roster($coach_user_id, (int) $cid);
            $roster_ids = array_map(function ($r) { return (int) $r['enrollment_id']; }, $roster);
            if (array_intersect($target_enrollment_ids, $roster_ids)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if any target school belongs to the viewer's district.
     */
    private function target_in_district($district_id, $target_school_ids) {
        if (empty($target_school_ids)) {
            return false;
        }

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($target_school_ids), '%d'));

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}hl_orgunit
             WHERE orgunit_id IN ($placeholders)
               AND parent_orgunit_id = %d",
            array_merge(array_map('intval', $target_school_ids), array($district_id))
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
        $placeholders = implode(',', array_fill(0, count($target_enrollment_ids), '%d'));

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$wpdb->prefix}hl_team_membership tm1
             JOIN {$wpdb->prefix}hl_team_membership tm2 ON tm1.team_id = tm2.team_id
             WHERE tm1.enrollment_id = %d
               AND tm2.enrollment_id IN ($placeholders)",
            array_merge(array($viewer_enrollment_id), array_map('intval', $target_enrollment_ids))
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
    // Rendering — Breadcrumbs
    // =====================================================================

    private function render_breadcrumbs($user, $overview, $is_own_profile) {
        if ($is_own_profile) {
            return; // No breadcrumbs for own profile.
        }

        $crumbs = array();

        // Dashboard as root.
        $dashboard_url = $this->find_shortcode_page_url('hl_dashboard');
        if ($dashboard_url) {
            $crumbs[] = '<a href="' . esc_url($dashboard_url) . '">' . esc_html__('Dashboard', 'hl-core') . '</a>';
        }

        // If viewer came from My School, link back.
        $school_url = $this->find_shortcode_page_url('hl_my_cycle');
        if ($school_url) {
            $crumbs[] = '<a href="' . esc_url($school_url) . '">' . esc_html__('My School', 'hl-core') . '</a>';
        }

        // Current page — user name.
        $crumbs[] = '<span class="hlup-breadcrumb-current">' . esc_html($user->display_name) . '</span>';

        if (count($crumbs) > 1) {
            echo '<nav class="hlup-breadcrumbs">' . implode(' <span class="hlup-breadcrumb-sep">/</span> ', $crumbs) . '</nav>';
        }
    }

    // =====================================================================
    // Rendering — Hero
    // =====================================================================

    private function render_hero($user, $overview, $is_own_profile, $switch_url = '') {
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
                        <?php if ($switch_url) : ?>
                            <a href="<?php echo esc_url($switch_url); ?>" class="hlup-view-as-btn" title="<?php esc_attr_e('Switch to this user\'s session to see the platform from their perspective', 'hl-core'); ?>">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                <?php esc_html_e('View As', 'hl-core'); ?>
                            </a>
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
        $placeholders = implode(',', array_fill(0, count($cycle_ids), '%d'));
        $cycles = $wpdb->get_results($wpdb->prepare(
            "SELECT cycle_id, cycle_name FROM {$wpdb->prefix}hl_cycle WHERE cycle_id IN ($placeholders)",
            array_values($cycle_ids)
        ), OBJECT_K);

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
            $is_own = (get_current_user_id() === (int) $user->ID);
            $show_success = isset($_GET['hlup_updated']);
            ?>
            <div class="hlup-empty-state">
                <?php if ($show_success) : ?>
                    <div class="hlup-success-banner">
                        <?php esc_html_e('Profile updated successfully.', 'hl-core'); ?>
                    </div>
                <?php endif; ?>
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                <h3><?php echo esc_html($user->display_name); ?></h3>
                <p class="hlup-empty-email"><?php echo esc_html($user->user_email); ?></p>
                <?php if ($is_own) : ?>
                    <form method="post" class="hlup-quick-edit-form">
                        <?php wp_nonce_field('hlup_manage_' . $user->ID, '_hlup_nonce'); ?>
                        <input type="hidden" name="hlup_action" value="update_profile">
                        <input type="hidden" name="hlup_user_id" value="<?php echo esc_attr($user->ID); ?>">
                        <input type="hidden" name="hlup_enrollment_id" value="0">
                        <div class="hl-form-group">
                            <label><?php esc_html_e('Display Name', 'hl-core'); ?></label>
                            <input type="text" name="hlup_display_name" value="<?php echo esc_attr($user->display_name); ?>">
                        </div>
                        <div class="hl-form-group">
                            <label><?php esc_html_e('Email', 'hl-core'); ?></label>
                            <input type="email" name="hlup_email" value="<?php echo esc_attr($user->user_email); ?>">
                        </div>
                        <button type="submit" class="hl-btn hl-btn-primary">
                            <?php esc_html_e('Save Changes', 'hl-core'); ?>
                        </button>
                    </form>
                <?php endif; ?>
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
    // Rendering — Progress Tab
    // =====================================================================

    /**
     * Render the Progress tab: pathway cards with component-by-component completion.
     *
     * @param WP_User            $user
     * @param HL_Enrollment|null $enrollment  Currently selected enrollment.
     * @param HL_Enrollment[]    $all_enrollments
     * @param array              $overview  Data already loaded by load_overview_data().
     */
    private function render_progress_tab($user, $enrollment, $all_enrollments, $overview) {
        if (!$enrollment) {
            ?>
            <div class="hlup-empty-state">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/><line x1="6" y1="20" x2="6" y2="16"/></svg>
                <h3><?php esc_html_e('No Progress Data', 'hl-core'); ?></h3>
                <p><?php esc_html_e('This user has no active program enrollment.', 'hl-core'); ?></p>
            </div>
            <?php
            return;
        }

        // Load pathways assigned to this enrollment.
        $pathways = $this->pathway_service->get_pathways_for_enrollment($enrollment->enrollment_id);

        // Load all component states for this enrollment (keyed by component_id).
        $states_raw = $this->reporting_service->get_component_states($enrollment->enrollment_id);
        $state_map  = array();
        foreach ($states_raw as $s) {
            $state_map[(int) $s['component_id']] = $s;
        }

        // Reuse data already loaded for the hero (load_overview_data runs on every tab).
        $cycle_name  = $overview['cycle_name'];
        $overall_pct = $overview['completion'];

        // LearnDash helper — resolve course progress for learndash_course components.
        $ld_user_id = (int) $user->ID;

        ?>
        <!-- Enrollment summary bar -->
        <div class="hlup-prog-summary">
            <div class="hlup-prog-summary-info">
                <h3 class="hlup-prog-summary-title"><?php echo esc_html($cycle_name ?: __('Program', 'hl-core')); ?></h3>
                <div class="hlup-prog-summary-meta">
                    <?php
                    $roles = $enrollment->get_roles_array();
                    if (!empty($roles)) :
                        $role_labels = array_map(function($r) { return ucfirst(str_replace('_', ' ', $r)); }, $roles);
                    ?>
                        <span class="hlup-prog-meta-item"><?php echo esc_html(implode(', ', $role_labels)); ?></span>
                    <?php endif; ?>
                    <?php if ($enrollment->enrolled_at) : ?>
                        <span class="hlup-prog-meta-item">
                            <?php printf(esc_html__('Enrolled %s', 'hl-core'), esc_html(date_i18n('M j, Y', strtotime($enrollment->enrolled_at)))); ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="hlup-prog-summary-pct">
                <div class="hlup-prog-overall-ring" data-pct="<?php echo esc_attr($overall_pct); ?>">
                    <svg viewBox="0 0 36 36">
                        <path class="hlup-ring-bg" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"/>
                        <path class="hlup-ring-fill" stroke-dasharray="<?php echo esc_attr($overall_pct); ?>, 100" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"/>
                    </svg>
                    <span class="hlup-ring-text"><?php echo esc_html($overall_pct); ?>%</span>
                </div>
            </div>
        </div>

        <?php if (empty($pathways)) : ?>
            <div class="hlup-empty-state hlup-empty-state--spaced">
                <p><?php esc_html_e('No learning plan assigned yet.', 'hl-core'); ?></p>
            </div>
        <?php else : ?>

            <?php foreach ($pathways as $pw) :
                $pw_id   = (int) $pw['pathway_id'];
                $pw_name = $pw['pathway_name'];

                // Load components for this pathway.
                $components = $this->component_repo->get_by_pathway($pw_id);
                $components = array_filter($components, function($c) {
                    return $c->visibility !== 'staff_only';
                });
                $components = array_values($components);

                // Compute pathway-level stats.
                $total_weight  = 0;
                $weighted_done = 0;
                $completed     = 0;
                $total         = 0;

                $rules_engine = new HL_Rules_Engine_Service();
                $comp_data = array();
                foreach ($components as $component) {
                    // Eligibility check.
                    if (!$rules_engine->check_eligibility($enrollment->enrollment_id, $component)) {
                        $comp_data[] = array(
                            'title'  => $component->title,
                            'type'   => $component->component_type,
                            'pct'    => 0,
                            'status' => 'not_applicable',
                        );
                        continue;
                    }

                    $total++;
                    $cid   = (int) $component->component_id;
                    $state = isset($state_map[$cid]) ? $state_map[$cid] : null;

                    $pct    = $state ? (int) $state['completion_percent'] : 0;
                    $status = $state ? $state['completion_status'] : 'not_started';

                    // LearnDash live progress override.
                    if ($component->component_type === 'learndash_course') {
                        $ext = $component->get_external_ref_array();
                        if (!empty($ext['course_id'])) {
                            $ld_pct = $this->get_learndash_progress($ld_user_id, (int) $ext['course_id']);
                            if ($ld_pct > $pct) {
                                $pct = $ld_pct;
                            }
                        }
                    }

                    if ($status === 'complete' || $pct >= 100) {
                        $pct    = 100;
                        $status = 'complete';
                        $completed++;
                    }

                    $weight = max((float) $component->weight, 0);
                    $total_weight  += $weight;
                    $weighted_done += $weight * ($pct / 100);

                    $comp_data[] = array(
                        'title'  => $component->title,
                        'type'   => $component->component_type,
                        'pct'    => $pct,
                        'status' => $status,
                    );
                }

                $pw_pct = ($total_weight > 0) ? round(($weighted_done / $total_weight) * 100) : 0;
            ?>
                <div class="hlup-pathway-card">
                    <div class="hlup-pathway-header">
                        <div class="hlup-pathway-info">
                            <h4 class="hlup-pathway-name"><?php echo esc_html($pw_name); ?></h4>
                            <span class="hlup-pathway-counts">
                                <?php printf(esc_html__('%d of %d completed', 'hl-core'), $completed, $total); ?>
                            </span>
                        </div>
                        <div class="hlup-pathway-pct-group">
                            <div class="hlup-pathway-bar">
                                <div class="hlup-pathway-bar-fill <?php echo $pw_pct >= 100 ? 'complete' : ''; ?>"
                                     style="width:<?php echo esc_attr($pw_pct); ?>%"></div>
                            </div>
                            <span class="hlup-pathway-pct-text"><?php echo esc_html($pw_pct); ?>%</span>
                        </div>
                    </div>

                    <?php if (!empty($comp_data)) : ?>
                        <div class="hlup-component-list">
                            <?php foreach ($comp_data as $i => $cd) :
                                $status_class = 'not-started';
                                $status_label = __('Not Started', 'hl-core');
                                $status_icon  = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg>';

                                if ($cd['status'] === 'not_applicable') {
                                    $status_class = 'not-applicable';
                                    $status_label = __('N/A', 'hl-core');
                                    $status_icon  = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>';
                                } elseif ($cd['status'] === 'complete') {
                                    $status_class = 'complete';
                                    $status_label = __('Complete', 'hl-core');
                                    $status_icon  = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>';
                                } elseif ($cd['pct'] > 0) {
                                    $status_class = 'in-progress';
                                    $status_label = __('In Progress', 'hl-core');
                                    $status_icon  = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>';
                                }

                                $type_label = $this->get_component_type_label($cd['type']);
                            ?>
                                <div class="hlup-component-row">
                                    <div class="hlup-component-index"><?php echo esc_html($i + 1); ?></div>
                                    <div class="hlup-component-main">
                                        <div class="hlup-component-title"><?php echo esc_html($cd['title']); ?></div>
                                        <span class="hlup-component-type"><?php echo esc_html($type_label); ?></span>
                                    </div>
                                    <div class="hlup-component-status hlup-status-<?php echo esc_attr($status_class); ?>">
                                        <?php echo $status_icon; ?>
                                        <span class="hlup-component-status-label"><?php echo esc_html($status_label); ?></span>
                                    </div>
                                    <div class="hlup-component-pct">
                                        <div class="hlup-component-bar">
                                            <div class="hlup-component-bar-fill <?php echo $cd['pct'] >= 100 ? 'complete' : ''; ?>"
                                                 style="width:<?php echo esc_attr($cd['pct']); ?>%"></div>
                                        </div>
                                        <span class="hlup-component-pct-text"><?php echo esc_html($cd['pct']); ?>%</span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

        <?php endif; ?>
        <?php
    }

    /**
     * Get a human-readable label for a component type.
     */
    private function get_component_type_label($type) {
        $labels = array(
            'learndash_course'            => __('Course', 'hl-core'),
            'coaching_session_attendance' => __('Coaching', 'hl-core'),
            'teacher_self_assessment'     => __('Self-Assessment', 'hl-core'),
            'child_assessment'            => __('Child Assessment', 'hl-core'),
            'classroom_visit'             => __('Classroom Visit', 'hl-core'),
            'self_reflection'             => __('Self-Reflection', 'hl-core'),
            'reflective_practice_session' => __('RP Session', 'hl-core'),
        );
        return isset($labels[$type]) ? $labels[$type] : ucwords(str_replace('_', ' ', $type));
    }

    /**
     * Get LearnDash course progress for a user.
     *
     * @return int Percent (0-100).
     */
    private function get_learndash_progress($user_id, $course_id) {
        if (!function_exists('learndash_course_progress')) {
            return 0;
        }
        $progress = learndash_course_progress(array(
            'user_id'   => $user_id,
            'course_id' => $course_id,
            'array'     => true,
        ));
        return isset($progress['percentage']) ? (int) $progress['percentage'] : 0;
    }

    // =====================================================================
    // Rendering — Coaching Tab
    // =====================================================================

    /**
     * Render the Coaching tab: sessions list, action plans, schedule link.
     *
     * @param WP_User            $user
     * @param HL_Enrollment|null $enrollment
     * @param bool               $is_admin
     */
    private function render_coaching_tab($user, $enrollment, $is_admin) {
        if (!$enrollment) {
            $this->render_placeholder_tab(__('Coaching', 'hl-core'), __('No enrollment found.', 'hl-core'));
            return;
        }

        $coaching_service = new HL_Coaching_Service();
        $cycle_id         = (int) $enrollment->cycle_id;
        $enrollment_id    = (int) $enrollment->enrollment_id;

        // All sessions for this enrollment.
        $sessions = $coaching_service->get_sessions_for_participant($enrollment_id, $cycle_id);

        // Action plan submissions.
        $action_plans = $coaching_service->get_previous_coaching_action_plans($enrollment_id, $cycle_id);

        // Schedule Next Session — only for coaches and admins viewing someone else's profile.
        $next_component = null;
        $schedule_url   = '';
        $is_viewer_coach = in_array('coach', (array) wp_get_current_user()->roles, true);
        if ($is_admin || $is_viewer_coach) {
            $next_component = $this->get_next_coaching_component($enrollment_id);
            if ($next_component) {
                $comp_page_url = $this->find_shortcode_page_url('hl_component_page');
                if ($comp_page_url) {
                    $schedule_url = add_query_arg(array(
                        'id'         => $next_component['component_id'],
                        'enrollment' => $enrollment_id,
                    ), $comp_page_url);
                }
            }
        }

        // Split sessions into upcoming and past.
        $now = current_time('U');
        $upcoming = array();
        $past     = array();
        foreach ($sessions as $s) {
            $dt = !empty($s['session_datetime']) ? strtotime($s['session_datetime']) : 0;
            if ($dt && $dt >= $now && $s['session_status'] === 'scheduled') {
                $upcoming[] = $s;
            } else {
                $past[] = $s;
            }
        }
        // Past sessions: most recent first.
        $past = array_reverse($past);

        ?>
        <!-- Schedule button -->
        <?php if ($schedule_url) : ?>
            <div class="hlup-coach-schedule-bar">
                <a href="<?php echo esc_url($schedule_url); ?>" class="hlup-coach-schedule-btn">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    <?php printf(esc_html__('Schedule Next: %s', 'hl-core'), esc_html($next_component['title'])); ?>
                </a>
            </div>
        <?php elseif (($is_admin || $is_viewer_coach) && !empty($sessions) && !$next_component) : ?>
            <div class="hlup-coach-all-scheduled">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                <?php esc_html_e('All coaching sessions are scheduled or completed.', 'hl-core'); ?>
            </div>
        <?php endif; ?>

        <?php if (empty($sessions)) : ?>
            <div class="hlup-empty-state">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                <h3><?php esc_html_e('No Coaching Sessions', 'hl-core'); ?></h3>
                <p><?php esc_html_e('No coaching sessions have been scheduled for this enrollment yet.', 'hl-core'); ?></p>
            </div>
        <?php else : ?>

            <!-- Upcoming Sessions -->
            <?php if (!empty($upcoming)) : ?>
                <div class="hlup-coach-section">
                    <h4 class="hlup-coach-section-title">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        <?php esc_html_e('Upcoming', 'hl-core'); ?>
                        <span class="hlup-coach-count"><?php echo esc_html(count($upcoming)); ?></span>
                    </h4>
                    <?php foreach ($upcoming as $s) : ?>
                        <?php $this->render_session_card($s, true); ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Past Sessions -->
            <?php if (!empty($past)) : ?>
                <div class="hlup-coach-section">
                    <h4 class="hlup-coach-section-title">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>
                        <?php esc_html_e('Past Sessions', 'hl-core'); ?>
                        <span class="hlup-coach-count"><?php echo esc_html(count($past)); ?></span>
                    </h4>
                    <?php foreach ($past as $s) : ?>
                        <?php $this->render_session_card($s, false); ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        <?php endif; ?>

        <!-- Action Plans -->
        <?php if (!empty($action_plans)) : ?>
            <div class="hlup-coach-section hlup-coach-section--spaced">
                <h4 class="hlup-coach-section-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                    <?php esc_html_e('Action Plans', 'hl-core'); ?>
                    <span class="hlup-coach-count"><?php echo esc_html(count($action_plans)); ?></span>
                </h4>
                <?php foreach ($action_plans as $ap) :
                    $responses = json_decode($ap['responses_json'], true);
                    $domain    = isset($responses['domain']) ? $responses['domain'] : '';
                    $skills    = isset($responses['skills_to_practice']) ? $responses['skills_to_practice'] : '';
                    $ap_date   = !empty($ap['submitted_at']) ? date_i18n('M j, Y', strtotime($ap['submitted_at'])) : '';
                    $session_title = !empty($ap['session_title']) ? $ap['session_title'] : __('Coaching Session', 'hl-core');
                ?>
                    <div class="hlup-action-plan-card">
                        <div class="hlup-ap-header">
                            <span class="hlup-ap-session"><?php echo esc_html($session_title); ?></span>
                            <?php if ($ap_date) : ?>
                                <span class="hlup-ap-date"><?php echo esc_html($ap_date); ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ($domain) : ?>
                            <div class="hlup-ap-field">
                                <span class="hlup-ap-label"><?php esc_html_e('Domain', 'hl-core'); ?></span>
                                <span class="hlup-ap-value"><?php echo esc_html(ucwords(str_replace('_', ' ', $domain))); ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if ($skills) : ?>
                            <div class="hlup-ap-field">
                                <span class="hlup-ap-label"><?php esc_html_e('Skills to Practice', 'hl-core'); ?></span>
                                <span class="hlup-ap-value"><?php echo esc_html(is_array($skills) ? implode(', ', $skills) : $skills); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php
    }

    /**
     * Render a single coaching session card.
     *
     * @param array $session
     * @param bool  $is_upcoming
     */
    private function render_session_card($session, $is_upcoming) {
        $dt     = !empty($session['session_datetime']) ? strtotime($session['session_datetime']) : 0;
        $date   = $dt ? date_i18n('M j, Y', $dt) : "\xe2\x80\x94";
        $time   = $dt ? date_i18n('g:i A', $dt) : '';
        $title  = !empty($session['session_title']) ? $session['session_title'] : __('Coaching Session', 'hl-core');
        $status = $session['session_status'] ?? 'scheduled';
        $coach  = $session['coach_name'] ?? "\xe2\x80\x94";
        $url    = $session['meeting_url'] ?? '';
        ?>
        <div class="hlup-session-card <?php echo $is_upcoming ? 'upcoming' : ''; ?>">
            <div class="hlup-session-main">
                <div class="hlup-session-title"><?php echo esc_html($title); ?></div>
                <div class="hlup-session-meta">
                    <span class="hlup-session-date">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        <?php echo esc_html($date); ?>
                        <?php if ($time) : ?>
                            <span class="hlup-session-time"><?php echo esc_html($time); ?></span>
                        <?php endif; ?>
                    </span>
                    <span class="hlup-session-coach">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        <?php echo esc_html($coach); ?>
                    </span>
                </div>
            </div>
            <div class="hlup-session-actions">
                <?php echo HL_Coaching_Service::render_status_badge($status); ?>
                <?php if ($url && $is_upcoming) : ?>
                    <a href="<?php echo esc_url($url); ?>" class="hlup-session-join" target="_blank" rel="noopener noreferrer">
                        <?php esc_html_e('Join', 'hl-core'); ?>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Find the next unscheduled coaching component for an enrollment.
     */
    private function get_next_coaching_component($enrollment_id) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        // Get first assigned pathway.
        $pathway_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT pa.pathway_id FROM {$prefix}hl_pathway_assignment pa
             WHERE pa.enrollment_id = %d ORDER BY pa.created_at DESC LIMIT 1",
            $enrollment_id
        ));
        if (!$pathway_id) {
            return null;
        }

        // All coaching components for this pathway.
        $components = $wpdb->get_results($wpdb->prepare(
            "SELECT component_id, title FROM {$prefix}hl_component
             WHERE pathway_id = %d AND component_type = 'coaching_session_attendance' AND status = 'active'
             ORDER BY ordering_hint ASC",
            $pathway_id
        ), ARRAY_A);
        if (empty($components)) {
            return null;
        }

        // Component IDs that already have a scheduled or attended session.
        $comp_ids     = array_map(function($c) { return (int) $c['component_id']; }, $components);
        $placeholders = implode(',', array_fill(0, count($comp_ids), '%d'));
        $params       = array_merge($comp_ids, array($enrollment_id));

        $scheduled_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT component_id FROM {$prefix}hl_coaching_session
             WHERE component_id IN ($placeholders)
               AND mentor_enrollment_id = %d
               AND session_status IN ('scheduled', 'attended')",
            ...$params
        ));
        $scheduled_set = array_map('intval', $scheduled_ids);

        foreach ($components as $comp) {
            if (!in_array((int) $comp['component_id'], $scheduled_set, true)) {
                return $comp;
            }
        }
        return null;
    }

    // =====================================================================
    // Rendering — Assessments Tab
    // =====================================================================

    /**
     * Render the Assessments tab: TSA and CA status with optional inline responses.
     *
     * Response data is gated behind manage_hl_core (staff/coach) until teacher
     * consent is obtained. All viewers with tab access see completion status.
     *
     * @param WP_User            $user
     * @param HL_Enrollment|null $enrollment
     * @param bool               $is_admin
     */
    private function render_assessments_tab($user, $enrollment, $is_admin) {
        if (!$enrollment) {
            $this->render_placeholder_tab(__('Assessments', 'hl-core'), __('No enrollment found.', 'hl-core'));
            return;
        }

        $assessment_service = new HL_Assessment_Service();
        $enrollment_id = (int) $enrollment->enrollment_id;

        // Can this viewer see response data? Staff/coach only until consent.
        $can_see_responses = current_user_can('manage_hl_core');

        // TSA instances.
        $tsa_instances = $assessment_service->get_teacher_assessments($enrollment_id);

        // CA instances (with classroom + children count).
        $ca_instances = $this->load_ca_instances_with_details($enrollment_id);

        // Summary counts.
        $tsa_submitted = count(array_filter($tsa_instances, function($i) { return $i['status'] === 'submitted'; }));
        $ca_submitted  = count(array_filter($ca_instances, function($i) { return $i['status'] === 'submitted'; }));

        ?>
        <!-- Summary stats -->
        <div class="hlup-assess-stats">
            <div class="hlup-assess-stat-card">
                <div class="hlup-assess-stat-num"><?php echo esc_html(count($tsa_instances)); ?></div>
                <div class="hlup-assess-stat-label"><?php esc_html_e('Teacher Assessments', 'hl-core'); ?></div>
                <div class="hlup-assess-stat-sub"><?php printf(esc_html__('%d submitted', 'hl-core'), $tsa_submitted); ?></div>
            </div>
            <div class="hlup-assess-stat-card">
                <div class="hlup-assess-stat-num"><?php echo esc_html(count($ca_instances)); ?></div>
                <div class="hlup-assess-stat-label"><?php esc_html_e('Child Assessments', 'hl-core'); ?></div>
                <div class="hlup-assess-stat-sub"><?php printf(esc_html__('%d submitted', 'hl-core'), $ca_submitted); ?></div>
            </div>
        </div>

        <!-- TSA Section -->
        <?php if (!empty($tsa_instances)) : ?>
            <div class="hlup-assess-section">
                <h4 class="hlup-assess-section-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                    <?php esc_html_e('Teacher Self-Assessments', 'hl-core'); ?>
                </h4>
                <?php foreach ($tsa_instances as $idx => $tsa) :
                    $phase  = strtoupper($tsa['phase'] ?? 'PRE');
                    $status = $tsa['status'] ?? 'not_started';
                    $date   = !empty($tsa['submitted_at']) ? date_i18n('M j, Y', strtotime($tsa['submitted_at'])) : '';
                    $has_responses = ($status === 'submitted' || $status === 'draft') && !empty($tsa['responses_json']);
                    $panel_id = 'hlup-tsa-resp-' . $idx;
                ?>
                    <div class="hlup-assess-card">
                        <div class="hlup-assess-card-main">
                            <div class="hlup-assess-card-info">
                                <span class="hlup-assess-phase <?php echo esc_attr(strtolower($phase)); ?>"><?php echo esc_html($phase); ?></span>
                                <span class="hlup-assess-card-title"><?php esc_html_e('Teacher Self-Assessment', 'hl-core'); ?></span>
                            </div>
                            <div class="hlup-assess-card-right">
                                <?php if ($date) : ?>
                                    <span class="hlup-assess-date"><?php echo esc_html($date); ?></span>
                                <?php endif; ?>
                                <?php echo $this->render_assess_badge($status); ?>
                                <?php if ($can_see_responses && $has_responses) : ?>
                                    <button type="button" class="hlup-assess-toggle" data-target="<?php echo esc_attr($panel_id); ?>">
                                        <?php esc_html_e('View', 'hl-core'); ?>
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if ($can_see_responses && $has_responses) : ?>
                            <div id="<?php echo esc_attr($panel_id); ?>" class="hlup-assess-responses" style="display:none;">
                                <?php $this->render_tsa_responses_inline($tsa, $assessment_service); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- CA Section -->
        <?php if (!empty($ca_instances)) : ?>
            <div class="hlup-assess-section">
                <h4 class="hlup-assess-section-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    <?php esc_html_e('Child Assessments', 'hl-core'); ?>
                </h4>
                <?php foreach ($ca_instances as $ca) :
                    $phase    = strtoupper($ca['phase'] ?? 'PRE');
                    $status   = $ca['status'] ?? 'not_started';
                    $date     = !empty($ca['submitted_at']) ? date_i18n('M j, Y', strtotime($ca['submitted_at'])) : '';
                    $classroom = $ca['classroom_name'] ?? "\xe2\x80\x94";
                    $age_band  = $ca['instrument_age_band'] ?? '';
                    $children  = (int) ($ca['children_assessed'] ?? 0);
                ?>
                    <div class="hlup-assess-card">
                        <div class="hlup-assess-card-main">
                            <div class="hlup-assess-card-info">
                                <span class="hlup-assess-phase <?php echo esc_attr(strtolower($phase)); ?>"><?php echo esc_html($phase); ?></span>
                                <span class="hlup-assess-card-title"><?php echo esc_html($classroom); ?></span>
                                <?php if ($age_band) : ?>
                                    <span class="hlup-assess-age-band"><?php echo esc_html(ucwords(str_replace('_', ' ', $age_band))); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="hlup-assess-card-right">
                                <?php if ($children > 0) : ?>
                                    <span class="hlup-assess-children">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                                        <?php printf(esc_html__('%d children', 'hl-core'), $children); ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($date) : ?>
                                    <span class="hlup-assess-date"><?php echo esc_html($date); ?></span>
                                <?php endif; ?>
                                <?php echo $this->render_assess_badge($status); ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (empty($tsa_instances) && empty($ca_instances)) : ?>
            <div class="hlup-empty-state">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                <h3><?php esc_html_e('No Assessments', 'hl-core'); ?></h3>
                <p><?php esc_html_e('No assessment instances found for this enrollment.', 'hl-core'); ?></p>
            </div>
        <?php endif; ?>

        <!-- Toggle script for View buttons -->
        <script>
        (function(){
            document.querySelectorAll('.hlup-assess-toggle').forEach(function(btn){
                btn.addEventListener('click', function(){
                    var panel = document.getElementById(btn.dataset.target);
                    if (!panel) return;
                    var open = panel.style.display !== 'none';
                    panel.style.display = open ? 'none' : 'block';
                    btn.classList.toggle('open', !open);
                });
            });
        })();
        </script>
        <?php
    }

    /**
     * Render inline TSA responses using HL_Teacher_Assessment_Renderer in read-only mode.
     */
    private function render_tsa_responses_inline($tsa, $assessment_service) {
        $instrument = $assessment_service->get_teacher_instrument($tsa['instrument_id'] ?? 0);
        if (!$instrument) {
            echo '<p class="hlup-field-empty">' . esc_html__('Instrument not found.', 'hl-core') . '</p>';
            return;
        }

        $responses = !empty($tsa['responses_json']) ? json_decode($tsa['responses_json'], true) : array();

        // For POST phase, load PRE responses for comparison column.
        $pre_responses = array();
        if (($tsa['phase'] ?? '') === 'post') {
            $pre_responses = $assessment_service->get_pre_responses_for_post(
                $tsa['enrollment_id'],
                $tsa['cycle_id']
            );
        }

        $renderer = new HL_Teacher_Assessment_Renderer(
            $instrument,
            $tsa,
            $tsa['phase'] ?? 'pre',
            $responses,
            $pre_responses,
            true  // read_only
        );

        echo $renderer->render();
    }

    /**
     * Load child assessment instances with classroom name and children count.
     */
    private function load_ca_instances_with_details($enrollment_id) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT cai.*, cr.classroom_name,
                    (SELECT COUNT(*) FROM {$prefix}hl_child_assessment_childrow
                     WHERE instance_id = cai.instance_id AND status = 'active') AS children_assessed
             FROM {$prefix}hl_child_assessment_instance cai
             LEFT JOIN {$prefix}hl_classroom cr ON cai.classroom_id = cr.classroom_id
             WHERE cai.enrollment_id = %d
             ORDER BY cai.phase ASC, cr.classroom_name ASC",
            $enrollment_id
        ), ARRAY_A) ?: array();
    }

    /**
     * Render an assessment status badge.
     */
    private function render_assess_badge($status) {
        $map = array(
            'submitted'   => array('hlup-badge-submitted', __('Submitted', 'hl-core')),
            'draft'       => array('hlup-badge-draft', __('Draft', 'hl-core')),
            'in_progress' => array('hlup-badge-draft', __('In Progress', 'hl-core')),
            'not_started' => array('hlup-badge-pending', __('Not Started', 'hl-core')),
        );
        $badge = isset($map[$status]) ? $map[$status] : array('hlup-badge-pending', ucwords(str_replace('_', ' ', $status)));
        return '<span class="hlup-assess-badge ' . esc_attr($badge[0]) . '">' . esc_html($badge[1]) . '</span>';
    }

    // =====================================================================
    // Rendering — RP & Observations Tab
    // =====================================================================

    /**
     * Render the RP & Observations tab: RP sessions, classroom visits, self-reflections.
     *
     * @param WP_User            $user
     * @param HL_Enrollment|null $enrollment
     */
    private function render_rp_tab($user, $enrollment) {
        if (!$enrollment) {
            $this->render_placeholder_tab(__('RP & Observations', 'hl-core'), __('No enrollment found.', 'hl-core'));
            return;
        }

        $enrollment_id = (int) $enrollment->enrollment_id;
        $roles         = $enrollment->get_roles_array();

        $rp_service = new HL_RP_Session_Service();
        $cv_service = new HL_Classroom_Visit_Service();

        // RP Sessions — query by role (mentor sees their sessions, teacher sees theirs).
        $rp_sessions = array();
        if (in_array('mentor', $roles, true)) {
            $rp_sessions = array_merge($rp_sessions, $rp_service->get_by_mentor($enrollment_id));
        }
        if (in_array('teacher', $roles, true)) {
            $teacher_sessions = $rp_service->get_by_teacher($enrollment_id);
            // Avoid duplicates if user is both mentor and teacher.
            $existing_ids = array_column($rp_sessions, 'rp_session_id');
            foreach ($teacher_sessions as $ts) {
                if (!in_array($ts['rp_session_id'], $existing_ids, true)) {
                    $rp_sessions[] = $ts;
                }
            }
        }
        // Sort by session_date descending.
        usort($rp_sessions, function($a, $b) {
            return strcmp($b['session_date'] ?? '', $a['session_date'] ?? '');
        });

        // Classroom Visits — where this user is the teacher being visited.
        $classroom_visits = array();
        if (in_array('teacher', $roles, true)) {
            $classroom_visits = $cv_service->get_by_teacher($enrollment_id);
            // Reverse to show most recent first.
            $classroom_visits = array_reverse($classroom_visits);
        }

        // Self-Reflections — submissions by this user with role = 'self_reflector'.
        $self_reflections = $this->get_self_reflections($user->ID, $enrollment_id);

        $has_any = !empty($rp_sessions) || !empty($classroom_visits) || !empty($self_reflections);

        if (!$has_any) {
            ?>
            <div class="hlup-empty-state">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                <h3><?php esc_html_e('No RP & Observations', 'hl-core'); ?></h3>
                <p><?php esc_html_e('No reflective practice sessions, classroom visits, or self-reflections found for this enrollment.', 'hl-core'); ?></p>
            </div>
            <?php
            return;
        }

        // ── RP Sessions ──
        if (!empty($rp_sessions)) : ?>
            <div class="hlup-rp-section">
                <h4 class="hlup-rp-section-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                    <?php esc_html_e('RP Sessions', 'hl-core'); ?>
                    <span class="hlup-rp-count"><?php echo esc_html(count($rp_sessions)); ?></span>
                </h4>
                <?php foreach ($rp_sessions as $rp) :
                    $date    = !empty($rp['session_date']) ? date_i18n('M j, Y', strtotime($rp['session_date'])) : "\xe2\x80\x94";
                    $number  = (int) ($rp['session_number'] ?? 0);
                    $status  = $rp['status'] ?? 'pending';
                    $partner = '';
                    if (in_array('mentor', $roles, true) && !empty($rp['teacher_name'])) {
                        $partner = $rp['teacher_name'];
                    } elseif (!empty($rp['mentor_name'])) {
                        $partner = $rp['mentor_name'];
                    }
                ?>
                    <div class="hlup-rp-card">
                        <div class="hlup-rp-card-left">
                            <span class="hlup-rp-number"><?php printf(esc_html__('Session %d', 'hl-core'), $number); ?></span>
                            <div class="hlup-rp-card-meta">
                                <span class="hlup-rp-date">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                    <?php echo esc_html($date); ?>
                                </span>
                                <?php if ($partner) : ?>
                                    <span class="hlup-rp-partner">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                        <?php echo esc_html($partner); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php echo $this->render_rp_status_badge($status); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif;

        // ── Classroom Visits ──
        if (!empty($classroom_visits)) : ?>
            <div class="hlup-rp-section">
                <h4 class="hlup-rp-section-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                    <?php esc_html_e('Classroom Visits', 'hl-core'); ?>
                    <span class="hlup-rp-count"><?php echo esc_html(count($classroom_visits)); ?></span>
                </h4>
                <?php foreach ($classroom_visits as $cv) :
                    $date     = !empty($cv['visit_date']) ? date_i18n('M j, Y', strtotime($cv['visit_date'])) : "\xe2\x80\x94";
                    $number   = (int) ($cv['visit_number'] ?? 0);
                    $status   = $cv['status'] ?? 'pending';
                    $observer = $cv['leader_name'] ?? '';
                ?>
                    <div class="hlup-rp-card">
                        <div class="hlup-rp-card-left">
                            <span class="hlup-rp-number"><?php printf(esc_html__('Visit %d', 'hl-core'), $number); ?></span>
                            <div class="hlup-rp-card-meta">
                                <span class="hlup-rp-date">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                    <?php echo esc_html($date); ?>
                                </span>
                                <?php if ($observer) : ?>
                                    <span class="hlup-rp-partner">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                        <?php echo esc_html($observer); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php echo $this->render_rp_status_badge($status); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif;

        // ── Self-Reflections ──
        if (!empty($self_reflections)) : ?>
            <div class="hlup-rp-section">
                <h4 class="hlup-rp-section-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                    <?php esc_html_e('Self-Reflections', 'hl-core'); ?>
                    <span class="hlup-rp-count"><?php echo esc_html(count($self_reflections)); ?></span>
                </h4>
                <?php foreach ($self_reflections as $sr) :
                    $date   = !empty($sr['submitted_at']) ? date_i18n('M j, Y', strtotime($sr['submitted_at'])) : "\xe2\x80\x94";
                    $status = $sr['status'] ?? 'draft';
                ?>
                    <div class="hlup-rp-card">
                        <div class="hlup-rp-card-left">
                            <span class="hlup-rp-number"><?php esc_html_e('Self-Reflection', 'hl-core'); ?></span>
                            <div class="hlup-rp-card-meta">
                                <span class="hlup-rp-date">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                    <?php echo esc_html($date); ?>
                                </span>
                            </div>
                        </div>
                        <?php echo $this->render_assess_badge($status); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif;
    }

    /**
     * Get self-reflection submissions scoped to an enrollment.
     *
     * Joins through hl_classroom_visit to scope by teacher_enrollment_id
     * (consistent with how RP sessions and classroom visits are scoped).
     * The user_id filter stays as a safety belt.
     */
    private function get_self_reflections($user_id, $enrollment_id) {
        global $wpdb;
        $prefix = $wpdb->prefix;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT sub.submission_id, sub.status, sub.submitted_at, sub.updated_at
             FROM {$prefix}hl_classroom_visit_submission sub
             JOIN {$prefix}hl_classroom_visit cv ON sub.classroom_visit_id = cv.classroom_visit_id
             WHERE cv.teacher_enrollment_id = %d
               AND sub.submitted_by_user_id = %d
               AND sub.role_in_visit = 'self_reflector'
             ORDER BY sub.submitted_at DESC",
            $enrollment_id,
            $user_id
        ), ARRAY_A) ?: array();
    }

    /**
     * Render an RP/visit status badge.
     */
    private function render_rp_status_badge($status) {
        $map = array(
            'completed' => array('hlup-badge-submitted', __('Completed', 'hl-core')),
            'scheduled' => array('hlup-badge-draft', __('Scheduled', 'hl-core')),
            'pending'   => array('hlup-badge-pending', __('Pending', 'hl-core')),
            'cancelled' => array('hlup-badge-pending', __('Cancelled', 'hl-core')),
        );
        $badge = isset($map[$status]) ? $map[$status] : array('hlup-badge-pending', ucwords(str_replace('_', ' ', $status)));
        return '<span class="hlup-assess-badge ' . esc_attr($badge[0]) . '">' . esc_html($badge[1]) . '</span>';
    }

    // =====================================================================
    // Rendering — Manage Tab (Admin Only)
    // =====================================================================

    /**
     * Render the Manage tab — admin-only actions for this user/enrollment.
     *
     * @param WP_User            $user
     * @param HL_Enrollment|null $enrollment
     */
    private function render_manage_tab($user, $enrollment) {
        $user_id       = (int) $user->ID;
        $enrollment_id = $enrollment ? (int) $enrollment->enrollment_id : 0;
        $nonce_value   = wp_create_nonce('hlup_manage_' . ($enrollment_id ?: $user_id));

        // Flash message from POST redirect.
        $success = isset($_GET['hlup_updated']) ? 1 : 0;

        // Load schools for dropdown.
        $schools = $this->orgunit_repo->get_schools();

        // Load current pathway assignments.
        $assigned_pathways = array();
        $available_pathways = array();
        if ($enrollment) {
            $assigned_pathways = $this->pathway_service->get_pathways_for_enrollment($enrollment_id);

            // All pathways in this cycle.
            global $wpdb;
            $all_pathways = $wpdb->get_results($wpdb->prepare(
                "SELECT pathway_id, pathway_name FROM {$wpdb->prefix}hl_pathway
                 WHERE cycle_id = %d AND active_status = 1 ORDER BY pathway_name ASC",
                $enrollment->cycle_id
            ), ARRAY_A) ?: array();

            $assigned_ids = array_map(function($p) { return (int) $p['pathway_id']; }, $assigned_pathways);
            $available_pathways = array_filter($all_pathways, function($p) use ($assigned_ids) {
                return !in_array((int) $p['pathway_id'], $assigned_ids, true);
            });
        }

        $current_roles = $enrollment ? $enrollment->get_roles_array() : array();
        $all_roles = array('teacher', 'mentor', 'school_leader', 'district_leader');

        ?>
        <?php if ($success) : ?>
            <div class="hlup-manage-flash">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                <?php esc_html_e('Changes saved successfully.', 'hl-core'); ?>
            </div>
        <?php endif; ?>

        <!-- Profile Info -->
        <div class="hlup-manage-section">
            <h4 class="hlup-manage-section-title"><?php esc_html_e('Profile Information', 'hl-core'); ?></h4>
            <form method="post" class="hlup-manage-form">
                <input type="hidden" name="hlup_action" value="update_profile">
                <input type="hidden" name="hlup_user_id" value="<?php echo esc_attr($user_id); ?>">
                <input type="hidden" name="hlup_enrollment_id" value="<?php echo esc_attr($enrollment_id); ?>">
                <input type="hidden" name="_hlup_nonce" value="<?php echo esc_attr($nonce_value); ?>">

                <div class="hlup-manage-field">
                    <label for="hlup_display_name"><?php esc_html_e('Display Name', 'hl-core'); ?></label>
                    <input type="text" id="hlup_display_name" name="hlup_display_name"
                           value="<?php echo esc_attr($user->display_name); ?>">
                </div>
                <div class="hlup-manage-field">
                    <label for="hlup_email"><?php esc_html_e('Email Address', 'hl-core'); ?></label>
                    <input type="email" id="hlup_email" name="hlup_email"
                           value="<?php echo esc_attr($user->user_email); ?>">
                </div>
                <button type="submit" class="hlup-manage-btn primary"><?php esc_html_e('Save Profile', 'hl-core'); ?></button>
            </form>
        </div>

        <!-- Enrollment Management -->
        <?php if ($enrollment) : ?>
        <div class="hlup-manage-section">
            <h4 class="hlup-manage-section-title"><?php esc_html_e('Enrollment Settings', 'hl-core'); ?></h4>
            <form method="post" class="hlup-manage-form">
                <input type="hidden" name="hlup_action" value="update_enrollment">
                <input type="hidden" name="hlup_user_id" value="<?php echo esc_attr($user_id); ?>">
                <input type="hidden" name="hlup_enrollment_id" value="<?php echo esc_attr($enrollment_id); ?>">
                <input type="hidden" name="_hlup_nonce" value="<?php echo esc_attr($nonce_value); ?>">

                <div class="hlup-manage-field">
                    <label for="hlup_status"><?php esc_html_e('Status', 'hl-core'); ?></label>
                    <select id="hlup_status" name="hlup_status">
                        <option value="active" <?php selected($enrollment->status, 'active'); ?>><?php esc_html_e('Active', 'hl-core'); ?></option>
                        <option value="inactive" <?php selected($enrollment->status, 'inactive'); ?>><?php esc_html_e('Inactive', 'hl-core'); ?></option>
                    </select>
                </div>
                <div class="hlup-manage-field">
                    <label for="hlup_school_id"><?php esc_html_e('School', 'hl-core'); ?></label>
                    <select id="hlup_school_id" name="hlup_school_id">
                        <option value=""><?php esc_html_e('— Select School —', 'hl-core'); ?></option>
                        <?php foreach ($schools as $school) : ?>
                            <option value="<?php echo esc_attr($school->orgunit_id); ?>"
                                <?php selected((int) $enrollment->school_id, (int) $school->orgunit_id); ?>>
                                <?php echo esc_html($school->name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="hlup-manage-field">
                    <label><?php esc_html_e('Roles', 'hl-core'); ?></label>
                    <div class="hlup-manage-checkboxes">
                        <?php foreach ($all_roles as $role) : ?>
                            <label class="hlup-manage-checkbox">
                                <input type="checkbox" name="hlup_roles[]" value="<?php echo esc_attr($role); ?>"
                                    <?php checked(in_array($role, $current_roles, true)); ?>>
                                <?php echo esc_html(ucwords(str_replace('_', ' ', $role))); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <button type="submit" class="hlup-manage-btn primary"><?php esc_html_e('Save Enrollment', 'hl-core'); ?></button>
            </form>
        </div>

        <!-- Pathway Assignments -->
        <div class="hlup-manage-section">
            <h4 class="hlup-manage-section-title"><?php esc_html_e('Learning Plan Assignments', 'hl-core'); ?></h4>

            <?php if (!empty($assigned_pathways)) : ?>
                <div class="hlup-manage-pathway-list">
                    <?php foreach ($assigned_pathways as $pw) : ?>
                        <div class="hlup-manage-pathway-row">
                            <span class="hlup-manage-pathway-name"><?php echo esc_html($pw['pathway_name']); ?></span>
                            <form method="post" class="hlup-manage-inline-form">
                                <input type="hidden" name="hlup_action" value="unassign_pathway">
                                <input type="hidden" name="hlup_enrollment_id" value="<?php echo esc_attr($enrollment_id); ?>">
                                <input type="hidden" name="hlup_user_id" value="<?php echo esc_attr($user_id); ?>">
                                <input type="hidden" name="hlup_pathway_id" value="<?php echo esc_attr($pw['pathway_id']); ?>">
                                <input type="hidden" name="_hlup_nonce" value="<?php echo esc_attr($nonce_value); ?>">
                                <button type="submit" class="hlup-manage-btn danger-sm"
                                        onclick="return confirm('<?php echo esc_js(__('Remove this learning plan?', 'hl-core')); ?>');">
                                    <?php esc_html_e('Remove', 'hl-core'); ?>
                                </button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else : ?>
                <p class="hlup-field-empty"><?php esc_html_e('No learning plans assigned.', 'hl-core'); ?></p>
            <?php endif; ?>

            <?php if (!empty($available_pathways)) : ?>
                <form method="post" class="hlup-manage-form hlup-manage-assign-form">
                    <input type="hidden" name="hlup_action" value="assign_pathway">
                    <input type="hidden" name="hlup_enrollment_id" value="<?php echo esc_attr($enrollment_id); ?>">
                    <input type="hidden" name="hlup_user_id" value="<?php echo esc_attr($user_id); ?>">
                    <input type="hidden" name="_hlup_nonce" value="<?php echo esc_attr($nonce_value); ?>">
                    <div class="hlup-manage-assign-row">
                        <select name="hlup_pathway_id">
                            <option value=""><?php esc_html_e('— Add Learning Plan —', 'hl-core'); ?></option>
                            <?php foreach ($available_pathways as $pw) : ?>
                                <option value="<?php echo esc_attr($pw['pathway_id']); ?>"><?php echo esc_html($pw['pathway_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="hlup-manage-btn primary"><?php esc_html_e('Assign', 'hl-core'); ?></button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Quick Actions -->
        <div class="hlup-manage-section">
            <h4 class="hlup-manage-section-title"><?php esc_html_e('Quick Actions', 'hl-core'); ?></h4>
            <div class="hlup-manage-actions">
                <form method="post" class="hlup-manage-inline-form">
                    <input type="hidden" name="hlup_action" value="send_password_reset">
                    <input type="hidden" name="hlup_user_id" value="<?php echo esc_attr($user_id); ?>">
                    <input type="hidden" name="hlup_enrollment_id" value="<?php echo esc_attr($enrollment_id); ?>">
                    <input type="hidden" name="_hlup_nonce" value="<?php echo esc_attr($nonce_value); ?>">
                    <button type="submit" class="hlup-manage-btn secondary">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        <?php esc_html_e('Send Password Reset Email', 'hl-core'); ?>
                    </button>
                </form>
            </div>
        </div>

        <!-- Danger Zone -->
        <?php if ($enrollment && $enrollment->status === 'active') : ?>
        <div class="hlup-manage-section hlup-manage-danger">
            <h4 class="hlup-manage-section-title"><?php esc_html_e('Danger Zone', 'hl-core'); ?></h4>
            <p class="hlup-manage-danger-text"><?php esc_html_e('Deactivating an enrollment will hide this user from reports and prevent access to program content.', 'hl-core'); ?></p>
            <form method="post" class="hlup-manage-inline-form">
                <input type="hidden" name="hlup_action" value="deactivate_enrollment">
                <input type="hidden" name="hlup_enrollment_id" value="<?php echo esc_attr($enrollment_id); ?>">
                <input type="hidden" name="hlup_user_id" value="<?php echo esc_attr($user_id); ?>">
                <input type="hidden" name="_hlup_nonce" value="<?php echo esc_attr($nonce_value); ?>">
                <button type="submit" class="hlup-manage-btn danger"
                        onclick="return confirm('<?php echo esc_js(__('Are you sure you want to deactivate this enrollment? This can be reversed by setting the status back to Active.', 'hl-core')); ?>');">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                    <?php esc_html_e('Deactivate Enrollment', 'hl-core'); ?>
                </button>
            </form>
        </div>
        <?php endif; ?>
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
