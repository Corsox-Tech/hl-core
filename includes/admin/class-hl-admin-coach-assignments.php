<?php
if (!defined('ABSPATH')) exit;

/**
 * Admin Coach Assignments Page
 *
 * Manage coach assignments at center, team, and enrollment levels.
 * Shows current assignments with scope labels and allows create/reassign/delete.
 *
 * @package HL_Core
 */
class HL_Admin_Coach_Assignments {

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Handle POST saves and GET deletes before any HTML output.
     */
    public function handle_early_actions() {
        $this->handle_post_actions();

        if (isset($_GET['action']) && $_GET['action'] === 'delete') {
            $this->handle_delete();
        }
    }

    /**
     * Main render entry point.
     */
    public function render_page() {
        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : 'list';

        echo '<div class="wrap">';

        switch ($action) {
            case 'new':
                $this->render_form();
                break;

            default:
                $this->render_list();
                break;
        }

        echo '</div>';
    }

    // =========================================================================
    // POST Handling
    // =========================================================================

    private function handle_post_actions() {
        if (isset($_POST['hl_coach_assignment_nonce'])) {
            $this->handle_save();
        }
    }

    private function handle_save() {
        if (!wp_verify_nonce($_POST['hl_coach_assignment_nonce'], 'hl_save_coach_assignment')) {
            wp_die(__('Security check failed.', 'hl-core'));
        }
        if (!current_user_can('manage_hl_core')) {
            wp_die(__('You do not have permission.', 'hl-core'));
        }

        $service = new HL_Coach_Assignment_Service();

        $result = $service->assign_coach(array(
            'coach_user_id' => absint($_POST['coach_user_id']),
            'scope_type'    => sanitize_text_field($_POST['scope_type']),
            'scope_id'      => absint($_POST['scope_id']),
            'cohort_id'     => absint($_POST['cohort_id']),
            'effective_from' => sanitize_text_field($_POST['effective_from']),
        ));

        if (is_wp_error($result)) {
            wp_redirect(admin_url('admin.php?page=hl-coach-assignments&action=new&message=error'));
        } else {
            wp_redirect(admin_url('admin.php?page=hl-coach-assignments&message=created'));
        }
        exit;
    }

    private function handle_delete() {
        $id = isset($_GET['assignment_id']) ? absint($_GET['assignment_id']) : 0;
        if (!$id) return;

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'hl_delete_coach_assignment_' . $id)) {
            wp_die(__('Security check failed.', 'hl-core'));
        }
        if (!current_user_can('manage_hl_core')) {
            wp_die(__('You do not have permission.', 'hl-core'));
        }

        $service = new HL_Coach_Assignment_Service();
        $result  = $service->delete_assignment($id);

        if (is_wp_error($result)) {
            wp_redirect(admin_url('admin.php?page=hl-coach-assignments&message=error'));
        } else {
            wp_redirect(admin_url('admin.php?page=hl-coach-assignments&message=deleted'));
        }
        exit;
    }

    // =========================================================================
    // List View
    // =========================================================================

    private function render_list() {
        global $wpdb;

        $filter_cohort = isset($_GET['cohort_id']) ? absint($_GET['cohort_id']) : 0;
        $cohorts = $wpdb->get_results("SELECT cohort_id, cohort_name FROM {$wpdb->prefix}hl_cohort ORDER BY cohort_name ASC");

        // Messages
        if (isset($_GET['message'])) {
            $msgs = array(
                'created' => array('success', __('Coach assignment created.', 'hl-core')),
                'deleted' => array('success', __('Assignment deleted.', 'hl-core')),
                'error'   => array('error', __('An error occurred.', 'hl-core')),
            );
            $m = sanitize_text_field($_GET['message']);
            if (isset($msgs[$m])) {
                echo '<div class="notice notice-' . esc_attr($msgs[$m][0]) . ' is-dismissible"><p>' . esc_html($msgs[$m][1]) . '</p></div>';
            }
        }

        // Cohort breadcrumb.
        if ($filter_cohort) {
            $cohort_name = $wpdb->get_var($wpdb->prepare(
                "SELECT cohort_name FROM {$wpdb->prefix}hl_cohort WHERE cohort_id = %d", $filter_cohort
            ));
            if ($cohort_name) {
                echo '<p style="margin:0 0 5px;"><a href="' . esc_url(admin_url('admin.php?page=hl-core&action=edit&id=' . $filter_cohort . '&tab=coaching')) . '">&larr; ' . sprintf(esc_html__('Cohort: %s', 'hl-core'), esc_html($cohort_name)) . '</a></p>';
            }
        }

        echo '<h1 class="wp-heading-inline">' . esc_html__('Coach Assignments', 'hl-core') . '</h1>';
        echo ' <a href="' . esc_url(admin_url('admin.php?page=hl-coach-assignments&action=new')) . '" class="page-title-action">' . esc_html__('Add Assignment', 'hl-core') . '</a>';
        echo '<hr class="wp-header-end">';

        // Cohort filter
        echo '<form method="get" style="margin-bottom:15px;">';
        echo '<input type="hidden" name="page" value="hl-coach-assignments" />';
        echo '<label><strong>' . esc_html__('Cohort:', 'hl-core') . '</strong> </label>';
        echo '<select name="cohort_id">';
        echo '<option value="">' . esc_html__('All Cohorts', 'hl-core') . '</option>';
        foreach ($cohorts as $c) {
            echo '<option value="' . esc_attr($c->cohort_id) . '"' . selected($filter_cohort, $c->cohort_id, false) . '>' . esc_html($c->cohort_name) . '</option>';
        }
        echo '</select> ';
        submit_button(__('Filter', 'hl-core'), 'secondary', 'submit', false);
        echo '</form>';

        // Get assignments
        $service = new HL_Coach_Assignment_Service();
        if ($filter_cohort) {
            $assignments = $service->get_all_assignments_by_cohort($filter_cohort);
        } else {
            $assignments = $wpdb->get_results(
                "SELECT ca.*, u.display_name AS coach_name, u.user_email AS coach_email, c.cohort_name
                 FROM {$wpdb->prefix}hl_coach_assignment ca
                 LEFT JOIN {$wpdb->users} u ON ca.coach_user_id = u.ID
                 LEFT JOIN {$wpdb->prefix}hl_cohort c ON ca.cohort_id = c.cohort_id
                 ORDER BY ca.cohort_id ASC, ca.scope_type ASC, ca.effective_from DESC",
                ARRAY_A
            ) ?: array();
        }

        if (empty($assignments)) {
            echo '<p>' . esc_html__('No coach assignments found.', 'hl-core') . '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('ID', 'hl-core') . '</th>';
        if (!$filter_cohort) {
            echo '<th>' . esc_html__('Cohort', 'hl-core') . '</th>';
        }
        echo '<th>' . esc_html__('Coach', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Scope', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Scope Name', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('From', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('To', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Status', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Actions', 'hl-core') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        $today = current_time('Y-m-d');

        foreach ($assignments as $a) {
            $is_active = ($a['effective_from'] <= $today)
                      && (empty($a['effective_to']) || $a['effective_to'] >= $today);

            $scope_name = $this->resolve_scope_name($a['scope_type'], $a['scope_id']);

            $delete_url = wp_nonce_url(
                admin_url('admin.php?page=hl-coach-assignments&action=delete&assignment_id=' . $a['coach_assignment_id']),
                'hl_delete_coach_assignment_' . $a['coach_assignment_id']
            );

            $status_badge = $is_active
                ? '<span style="display:inline-block;padding:3px 10px;border-radius:3px;font-size:12px;font-weight:600;background:#d4edda;color:#155724;">' . esc_html__('Active', 'hl-core') . '</span>'
                : '<span style="display:inline-block;padding:3px 10px;border-radius:3px;font-size:12px;font-weight:600;background:#e2e3e5;color:#383d41;">' . esc_html__('Ended', 'hl-core') . '</span>';

            echo '<tr>';
            echo '<td>' . esc_html($a['coach_assignment_id']) . '</td>';
            if (!$filter_cohort) {
                echo '<td>' . esc_html($a['cohort_name'] ?? '-') . '</td>';
            }
            echo '<td>' . esc_html($a['coach_name'] ?? '-') . '</td>';
            echo '<td><code>' . esc_html($a['scope_type']) . '</code></td>';
            echo '<td>' . esc_html($scope_name) . '</td>';
            echo '<td>' . esc_html($a['effective_from']) . '</td>';
            echo '<td>' . esc_html($a['effective_to'] ?: '—') . '</td>';
            echo '<td>' . $status_badge . '</td>';
            echo '<td>';
            echo '<a href="' . esc_url($delete_url) . '" class="button button-small button-link-delete" onclick="return confirm(\'' . esc_js(__('Delete this assignment?', 'hl-core')) . '\');">' . esc_html__('Delete', 'hl-core') . '</a>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    // =========================================================================
    // Create Form
    // =========================================================================

    private function render_form() {
        global $wpdb;

        echo '<h1>' . esc_html__('Add Coach Assignment', 'hl-core') . '</h1>';
        echo '<a href="' . esc_url(admin_url('admin.php?page=hl-coach-assignments')) . '">&larr; ' . esc_html__('Back to Assignments', 'hl-core') . '</a>';

        $cohorts = $wpdb->get_results("SELECT cohort_id, cohort_name FROM {$wpdb->prefix}hl_cohort ORDER BY cohort_name ASC");
        $staff   = $this->get_staff_users();

        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=hl-coach-assignments')) . '">';
        wp_nonce_field('hl_save_coach_assignment', 'hl_coach_assignment_nonce');

        echo '<table class="form-table">';

        // Cohort
        echo '<tr><th scope="row"><label for="cohort_id">' . esc_html__('Cohort', 'hl-core') . '</label></th>';
        echo '<td><select id="cohort_id" name="cohort_id" required>';
        echo '<option value="">' . esc_html__('-- Select Cohort --', 'hl-core') . '</option>';
        foreach ($cohorts as $c) {
            echo '<option value="' . esc_attr($c->cohort_id) . '">' . esc_html($c->cohort_name) . '</option>';
        }
        echo '</select></td></tr>';

        // Coach
        echo '<tr><th scope="row"><label for="coach_user_id">' . esc_html__('Coach', 'hl-core') . '</label></th>';
        echo '<td><select id="coach_user_id" name="coach_user_id" required>';
        echo '<option value="">' . esc_html__('-- Select Coach --', 'hl-core') . '</option>';
        foreach ($staff as $u) {
            echo '<option value="' . esc_attr($u->ID) . '">' . esc_html($u->display_name) . ' (' . esc_html($u->user_email) . ')</option>';
        }
        echo '</select></td></tr>';

        // Scope type
        echo '<tr><th scope="row"><label for="scope_type">' . esc_html__('Scope Level', 'hl-core') . '</label></th>';
        echo '<td><select id="scope_type" name="scope_type" required>';
        echo '<option value="center">' . esc_html__('Center (default for all participants at a center)', 'hl-core') . '</option>';
        echo '<option value="team">' . esc_html__('Team (overrides center default)', 'hl-core') . '</option>';
        echo '<option value="enrollment">' . esc_html__('Enrollment (override for one participant)', 'hl-core') . '</option>';
        echo '</select></td></tr>';

        // Scope ID
        echo '<tr><th scope="row"><label for="scope_id">' . esc_html__('Scope ID', 'hl-core') . '</label></th>';
        echo '<td><input type="number" id="scope_id" name="scope_id" required min="1" class="small-text" />';
        echo '<p class="description">' . esc_html__('Enter the Center ID, Team ID, or Enrollment ID depending on the scope level selected above.', 'hl-core') . '</p>';
        echo '</td></tr>';

        // Effective from
        echo '<tr><th scope="row"><label for="effective_from">' . esc_html__('Effective From', 'hl-core') . '</label></th>';
        echo '<td><input type="date" id="effective_from" name="effective_from" required value="' . esc_attr(current_time('Y-m-d')) . '" /></td></tr>';

        echo '</table>';

        submit_button(__('Create Assignment', 'hl-core'));
        echo '</form>';
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Resolve human-readable name for a scope.
     *
     * @param string $scope_type
     * @param int    $scope_id
     * @return string
     */
    private function resolve_scope_name($scope_type, $scope_id) {
        global $wpdb;

        switch ($scope_type) {
            case 'center':
                $name = $wpdb->get_var($wpdb->prepare(
                    "SELECT name FROM {$wpdb->prefix}hl_orgunit WHERE orgunit_id = %d",
                    $scope_id
                ));
                return $name ?: "Center #{$scope_id}";

            case 'team':
                $name = $wpdb->get_var($wpdb->prepare(
                    "SELECT team_name FROM {$wpdb->prefix}hl_team WHERE team_id = %d",
                    $scope_id
                ));
                return $name ?: "Team #{$scope_id}";

            case 'enrollment':
                $row = $wpdb->get_row($wpdb->prepare(
                    "SELECT u.display_name FROM {$wpdb->prefix}hl_enrollment e
                     LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
                     WHERE e.enrollment_id = %d",
                    $scope_id
                ));
                return $row ? $row->display_name : "Enrollment #{$scope_id}";

            default:
                return "#{$scope_id}";
        }
    }

    /**
     * Get users with manage_hl_core capability.
     *
     * @return array
     */
    private function get_staff_users() {
        $admins = get_users(array('role' => 'administrator', 'orderby' => 'display_name', 'order' => 'ASC'));
        $coaches = get_users(array('role' => 'coach', 'orderby' => 'display_name', 'order' => 'ASC'));

        $users = array_merge($admins, $coaches);
        $seen  = array();
        $unique = array();

        foreach ($users as $u) {
            if (!in_array($u->ID, $seen, true)) {
                $seen[]   = $u->ID;
                $unique[] = $u;
            }
        }

        return $unique;
    }
}
