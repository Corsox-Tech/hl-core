<?php if (!defined('ABSPATH')) exit;

/**
 * Admin Teams Page
 *
 * Full CRUD admin page for managing Teams.
 *
 * @package HL_Core
 */
class HL_Admin_Teams {

    /**
     * Singleton instance
     *
     * @var HL_Admin_Teams|null
     */
    private static $instance = null;

    /**
     * Get singleton instance
     *
     * @return HL_Admin_Teams
     */
    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        // No hooks needed.
    }

    /**
     * Handle POST saves and GET deletes before any HTML output.
     */
    public function handle_early_actions() {
        $this->handle_actions();

        if (isset($_GET['action']) && $_GET['action'] === 'delete') {
            $this->handle_delete();
        }
    }

    /**
     * Main render entry point
     */
    public function render_page() {
        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : 'list';

        echo '<div class="wrap">';

        switch ($action) {
            case 'new':
                $this->render_form();
                break;

            case 'edit':
                $team_id = isset($_GET['id']) ? absint($_GET['id']) : 0;
                $team    = $this->get_team($team_id);
                if ($team) {
                    $this->render_form($team);
                } else {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Team not found.', 'hl-core') . '</p></div>';
                    $this->render_list();
                }
                break;

            case 'view':
                $team_id = isset($_GET['id']) ? absint($_GET['id']) : 0;
                $team    = $this->get_team($team_id);
                if ($team) {
                    $this->render_team_detail($team);
                } else {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Team not found.', 'hl-core') . '</p></div>';
                    $this->render_list();
                }
                break;

            default:
                $this->render_list();
                break;
        }

        echo '</div>';
    }

    /**
     * Get a single team by ID
     *
     * @param int $team_id
     * @return object|null
     */
    public function get_team($team_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_team WHERE team_id = %d",
            $team_id
        ));
    }

    /**
     * Handle form submissions
     */
    private function handle_actions() {
        if (!isset($_POST['hl_team_nonce'])) {
            return;
        }

        if (!wp_verify_nonce($_POST['hl_team_nonce'], 'hl_save_team')) {
            wp_die(__('Security check failed.', 'hl-core'));
        }

        if (!current_user_can('manage_hl_core')) {
            wp_die(__('You do not have permission to perform this action.', 'hl-core'));
        }

        global $wpdb;

        $team_id = isset($_POST['team_id']) ? absint($_POST['team_id']) : 0;

        $data = array(
            'team_name'  => sanitize_text_field($_POST['team_name']),
            'cohort_id' => absint($_POST['cohort_id']),
            'school_id'  => absint($_POST['school_id']),
            'status'     => sanitize_text_field($_POST['status']),
        );

        $cohort_context = isset($_POST['_hl_cohort_context']) ? absint($_POST['_hl_cohort_context']) : 0;

        if ($team_id > 0) {
            $wpdb->update($wpdb->prefix . 'hl_team', $data, array('team_id' => $team_id));
            if ($cohort_context) {
                $redirect = admin_url('admin.php?page=hl-core&action=edit&id=' . $cohort_context . '&tab=teams&message=team_updated');
            } else {
                $redirect = admin_url('admin.php?page=hl-teams&message=updated');
            }
        } else {
            $data['team_uuid'] = HL_DB_Utils::generate_uuid();
            $wpdb->insert($wpdb->prefix . 'hl_team', $data);
            if ($cohort_context) {
                $redirect = admin_url('admin.php?page=hl-core&action=edit&id=' . $cohort_context . '&tab=teams&message=team_created');
            } else {
                $redirect = admin_url('admin.php?page=hl-teams&message=created');
            }
        }

        wp_redirect($redirect);
        exit;
    }

    /**
     * Handle delete action
     */
    private function handle_delete() {
        $team_id = isset($_GET['id']) ? absint($_GET['id']) : 0;

        if (!$team_id) {
            return;
        }

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'hl_delete_team_' . $team_id)) {
            wp_die(__('Security check failed.', 'hl-core'));
        }

        if (!current_user_can('manage_hl_core')) {
            wp_die(__('You do not have permission to perform this action.', 'hl-core'));
        }

        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'hl_team', array('team_id' => $team_id));

        $cohort_context = isset($_GET['cohort_context']) ? absint($_GET['cohort_context']) : 0;
        if ($cohort_context) {
            wp_redirect(admin_url('admin.php?page=hl-core&action=edit&id=' . $cohort_context . '&tab=teams&message=team_deleted'));
        } else {
            wp_redirect(admin_url('admin.php?page=hl-teams&message=deleted'));
        }
        exit;
    }

    /**
     * Render the teams list table
     */
    private function render_list() {
        global $wpdb;

        $filter_cohort = isset($_GET['cohort_id']) ? absint($_GET['cohort_id']) : 0;
        $filter_school  = isset($_GET['school_id']) ? absint($_GET['school_id']) : 0;

        $where_clauses = array();
        if ($filter_cohort) {
            $where_clauses[] = $wpdb->prepare('t.cohort_id = %d', $filter_cohort);
        }
        if ($filter_school) {
            $where_clauses[] = $wpdb->prepare('t.school_id = %d', $filter_school);
        }

        $where = '';
        if (!empty($where_clauses)) {
            $where = ' WHERE ' . implode(' AND ', $where_clauses);
        }

        $teams = $wpdb->get_results(
            "SELECT t.*, p.cohort_name, o.name AS school_name
             FROM {$wpdb->prefix}hl_team t
             LEFT JOIN {$wpdb->prefix}hl_cohort p ON t.cohort_id = p.cohort_id
             LEFT JOIN {$wpdb->prefix}hl_orgunit o ON t.school_id = o.orgunit_id
             {$where}
             ORDER BY t.team_name ASC"
        );

        // Get cohorts and schools for filters
        $cohorts = $wpdb->get_results(
            "SELECT cohort_id, cohort_name FROM {$wpdb->prefix}hl_cohort ORDER BY cohort_name ASC"
        );
        $schools = $wpdb->get_results(
            "SELECT orgunit_id, name FROM {$wpdb->prefix}hl_orgunit WHERE orgunit_type = 'school' ORDER BY name ASC"
        );

        // Messages
        if (isset($_GET['message'])) {
            $msg = sanitize_text_field($_GET['message']);
            if ($msg === 'created') {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Team created successfully.', 'hl-core') . '</p></div>';
            } elseif ($msg === 'updated') {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Team updated successfully.', 'hl-core') . '</p></div>';
            } elseif ($msg === 'deleted') {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Team deleted successfully.', 'hl-core') . '</p></div>';
            }
        }

        // Cohort breadcrumb.
        if ($filter_cohort) {
            $cohort_name = $wpdb->get_var($wpdb->prepare(
                "SELECT cohort_name FROM {$wpdb->prefix}hl_cohort WHERE cohort_id = %d", $filter_cohort
            ));
            if ($cohort_name) {
                echo '<p style="margin:0 0 5px;"><a href="' . esc_url(admin_url('admin.php?page=hl-core&action=edit&id=' . $filter_cohort . '&tab=teams')) . '">&larr; ' . sprintf(esc_html__('Cohort: %s', 'hl-core'), esc_html($cohort_name)) . '</a></p>';
            }
        }

        echo '<h1 class="wp-heading-inline">' . esc_html__('Teams', 'hl-core') . '</h1>';
        echo ' <a href="' . esc_url(admin_url('admin.php?page=hl-teams&action=new')) . '" class="page-title-action">' . esc_html__('Add New', 'hl-core') . '</a>';
        echo '<hr class="wp-header-end">';

        // Filters
        echo '<form method="get" style="margin-bottom:15px;">';
        echo '<input type="hidden" name="page" value="hl-teams" />';
        echo '<label><strong>' . esc_html__('Cohort:', 'hl-core') . '</strong> </label>';
        echo '<select name="cohort_id">';
        echo '<option value="">' . esc_html__('All', 'hl-core') . '</option>';
        if ($cohorts) {
            foreach ($cohorts as $cohort) {
                echo '<option value="' . esc_attr($cohort->cohort_id) . '"' . selected($filter_cohort, $cohort->cohort_id, false) . '>' . esc_html($cohort->cohort_name) . '</option>';
            }
        }
        echo '</select> ';

        echo '<label><strong>' . esc_html__('School:', 'hl-core') . '</strong> </label>';
        echo '<select name="school_id">';
        echo '<option value="">' . esc_html__('All', 'hl-core') . '</option>';
        if ($schools) {
            foreach ($schools as $school) {
                echo '<option value="' . esc_attr($school->orgunit_id) . '"' . selected($filter_school, $school->orgunit_id, false) . '>' . esc_html($school->name) . '</option>';
            }
        }
        echo '</select> ';
        submit_button(__('Filter', 'hl-core'), 'secondary', 'submit', false);
        echo '</form>';

        if (empty($teams)) {
            echo '<p>' . esc_html__('No teams found.', 'hl-core') . '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('ID', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Team Name', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Cohort', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('School', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Status', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Actions', 'hl-core') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($teams as $team) {
            $view_url   = admin_url('admin.php?page=hl-teams&action=view&id=' . $team->team_id);
            $edit_url   = admin_url('admin.php?page=hl-teams&action=edit&id=' . $team->team_id);
            $delete_url = wp_nonce_url(
                admin_url('admin.php?page=hl-teams&action=delete&id=' . $team->team_id),
                'hl_delete_team_' . $team->team_id
            );

            $status_style = ($team->status === 'active')
                ? 'color:#00a32a;font-weight:600;'
                : 'color:#b32d2e;font-weight:600;';

            echo '<tr>';
            echo '<td>' . esc_html($team->team_id) . '</td>';
            echo '<td><strong><a href="' . esc_url($view_url) . '">' . esc_html($team->team_name) . '</a></strong></td>';
            echo '<td>' . esc_html($team->cohort_name) . '</td>';
            echo '<td>' . esc_html($team->school_name) . '</td>';
            echo '<td><span style="' . esc_attr($status_style) . '">' . esc_html(ucfirst($team->status)) . '</span></td>';
            echo '<td>';
            echo '<a href="' . esc_url($view_url) . '" class="button button-small">' . esc_html__('View', 'hl-core') . '</a> ';
            echo '<a href="' . esc_url($edit_url) . '" class="button button-small">' . esc_html__('Edit', 'hl-core') . '</a> ';
            echo '<a href="' . esc_url($delete_url) . '" class="button button-small button-link-delete" onclick="return confirm(\'' . esc_js(__('Are you sure?', 'hl-core')) . '\');">' . esc_html__('Delete', 'hl-core') . '</a>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * Render team detail with members
     *
     * @param object $team
     * @param array  $context Optional cohort context. Keys: 'cohort_id', 'cohort_name'.
     */
    public function render_team_detail($team, $context = array()) {
        global $wpdb;
        $in_cohort = !empty($context['cohort_id']);

        // Get team members via team_member table or enrollment table
        // Attempt team_member table first; fall back to showing enrollments at this school
        $members = $wpdb->get_results($wpdb->prepare(
            "SELECT e.enrollment_id, e.roles, e.status, u.display_name, u.user_email
             FROM {$wpdb->prefix}hl_enrollment e
             LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
             WHERE e.cohort_id = %d AND e.school_id = %d AND e.status = 'active'
             ORDER BY u.display_name ASC",
            $team->cohort_id,
            $team->school_id
        ));

        $cohort = $wpdb->get_row($wpdb->prepare(
            "SELECT cohort_name FROM {$wpdb->prefix}hl_cohort WHERE cohort_id = %d",
            $team->cohort_id
        ));

        $school = $wpdb->get_row($wpdb->prepare(
            "SELECT name FROM {$wpdb->prefix}hl_orgunit WHERE orgunit_id = %d",
            $team->school_id
        ));

        if (!$in_cohort) {
            echo '<h1>' . esc_html($team->team_name) . '</h1>';
            echo '<a href="' . esc_url(admin_url('admin.php?page=hl-teams')) . '">&larr; ' . esc_html__('Back to Teams', 'hl-core') . '</a>';
        } else {
            echo '<h2>' . esc_html($team->team_name) . '</h2>';
        }

        echo '<table class="form-table">';
        echo '<tr><th>' . esc_html__('Cohort', 'hl-core') . '</th><td>' . esc_html($cohort ? $cohort->cohort_name : 'N/A') . '</td></tr>';
        echo '<tr><th>' . esc_html__('School', 'hl-core') . '</th><td>' . esc_html($school ? $school->name : 'N/A') . '</td></tr>';
        echo '<tr><th>' . esc_html__('Status', 'hl-core') . '</th><td>' . esc_html(ucfirst($team->status)) . '</td></tr>';
        echo '</table>';

        echo '<h2>' . esc_html__('Team Members', 'hl-core') . '</h2>';

        if (empty($members)) {
            echo '<p>' . esc_html__('No active enrollments found for this team\'s cohort and school.', 'hl-core') . '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Name', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Email', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Roles', 'hl-core') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($members as $member) {
            $roles_array = json_decode($member->roles, true);
            $roles_display = is_array($roles_array) ? implode(', ', $roles_array) : '';

            echo '<tr>';
            echo '<td>' . esc_html($member->display_name) . '</td>';
            echo '<td>' . esc_html($member->user_email) . '</td>';
            echo '<td>' . esc_html($roles_display) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * Render the create/edit form
     *
     * @param object|null $team
     * @param array       $context Optional cohort context. Keys: 'cohort_id', 'cohort_name'.
     */
    public function render_form($team = null, $context = array()) {
        $is_edit   = ($team !== null);
        $title     = $is_edit ? __('Edit Team', 'hl-core') : __('Add New Team', 'hl-core');
        $in_cohort = !empty($context['cohort_id']);

        global $wpdb;

        $cohorts = $wpdb->get_results(
            "SELECT cohort_id, cohort_name FROM {$wpdb->prefix}hl_cohort ORDER BY cohort_name ASC"
        );

        $schools = $wpdb->get_results(
            "SELECT orgunit_id, name FROM {$wpdb->prefix}hl_orgunit WHERE orgunit_type = 'school' AND status = 'active' ORDER BY name ASC"
        );

        if (!$in_cohort) {
            echo '<h1>' . esc_html($title) . '</h1>';
            echo '<a href="' . esc_url(admin_url('admin.php?page=hl-teams')) . '">&larr; ' . esc_html__('Back to Teams', 'hl-core') . '</a>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=hl-teams')) . '">';
        wp_nonce_field('hl_save_team', 'hl_team_nonce');
        if ($in_cohort) {
            echo '<input type="hidden" name="_hl_cohort_context" value="' . esc_attr($context['cohort_id']) . '" />';
        }

        if ($is_edit) {
            echo '<input type="hidden" name="team_id" value="' . esc_attr($team->team_id) . '" />';
        }

        echo '<table class="form-table">';

        // Team Name
        echo '<tr>';
        echo '<th scope="row"><label for="team_name">' . esc_html__('Team Name', 'hl-core') . '</label></th>';
        echo '<td><input type="text" id="team_name" name="team_name" value="' . esc_attr($is_edit ? $team->team_name : '') . '" class="regular-text" required /></td>';
        echo '</tr>';

        // Cohort
        $current_cohort = $in_cohort ? absint($context['cohort_id']) : ($is_edit ? $team->cohort_id : '');
        echo '<tr>';
        echo '<th scope="row"><label for="cohort_id">' . esc_html__('Cohort', 'hl-core') . '</label></th>';
        if ($in_cohort) {
            echo '<td><strong>' . esc_html($context['cohort_name']) . '</strong>';
            echo '<input type="hidden" id="cohort_id" name="cohort_id" value="' . esc_attr($context['cohort_id']) . '" /></td>';
        } else {
            echo '<td><select id="cohort_id" name="cohort_id" required>';
            echo '<option value="">' . esc_html__('-- Select Cohort --', 'hl-core') . '</option>';
            if ($cohorts) {
                foreach ($cohorts as $cohort) {
                    echo '<option value="' . esc_attr($cohort->cohort_id) . '"' . selected($current_cohort, $cohort->cohort_id, false) . '>' . esc_html($cohort->cohort_name) . '</option>';
                }
            }
            echo '</select></td>';
        }
        echo '</tr>';

        // School
        $current_school = $is_edit ? $team->school_id : '';
        echo '<tr>';
        echo '<th scope="row"><label for="school_id">' . esc_html__('School', 'hl-core') . '</label></th>';
        echo '<td><select id="school_id" name="school_id" required>';
        echo '<option value="">' . esc_html__('-- Select School --', 'hl-core') . '</option>';
        if ($schools) {
            foreach ($schools as $school) {
                echo '<option value="' . esc_attr($school->orgunit_id) . '"' . selected($current_school, $school->orgunit_id, false) . '>' . esc_html($school->name) . '</option>';
            }
        }
        echo '</select></td>';
        echo '</tr>';

        // Status
        $current_status = $is_edit ? $team->status : 'active';
        echo '<tr>';
        echo '<th scope="row"><label for="status">' . esc_html__('Status', 'hl-core') . '</label></th>';
        echo '<td><select id="status" name="status">';
        echo '<option value="active"' . selected($current_status, 'active', false) . '>' . esc_html__('Active', 'hl-core') . '</option>';
        echo '<option value="inactive"' . selected($current_status, 'inactive', false) . '>' . esc_html__('Inactive', 'hl-core') . '</option>';
        echo '</select></td>';
        echo '</tr>';

        echo '</table>';
        submit_button($is_edit ? __('Update Team', 'hl-core') : __('Create Team', 'hl-core'));
        echo '</form>';
    }
}
