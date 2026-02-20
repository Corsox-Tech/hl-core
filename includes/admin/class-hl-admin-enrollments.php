<?php if (!defined('ABSPATH')) exit;

/**
 * Admin Enrollments Page
 *
 * Full CRUD admin page for managing Enrollments.
 *
 * @package HL_Core
 */
class HL_Admin_Enrollments {

    /**
     * Singleton instance
     *
     * @var HL_Admin_Enrollments|null
     */
    private static $instance = null;

    /**
     * Get singleton instance
     *
     * @return HL_Admin_Enrollments
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
                $enrollment_id = isset($_GET['id']) ? absint($_GET['id']) : 0;
                $enrollment    = $this->get_enrollment($enrollment_id);
                if ($enrollment) {
                    $this->render_form($enrollment);
                } else {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Enrollment not found.', 'hl-core') . '</p></div>';
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
     * Get a single enrollment by ID
     *
     * @param int $enrollment_id
     * @return object|null
     */
    private function get_enrollment($enrollment_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_enrollment WHERE enrollment_id = %d",
            $enrollment_id
        ));
    }

    /**
     * Handle form submissions
     */
    private function handle_actions() {
        if (!isset($_POST['hl_enrollment_nonce'])) {
            return;
        }

        if (!wp_verify_nonce($_POST['hl_enrollment_nonce'], 'hl_save_enrollment')) {
            wp_die(__('Security check failed.', 'hl-core'));
        }

        if (!current_user_can('manage_hl_core')) {
            wp_die(__('You do not have permission to perform this action.', 'hl-core'));
        }

        global $wpdb;

        $enrollment_id = isset($_POST['enrollment_id']) ? absint($_POST['enrollment_id']) : 0;

        // Process roles checkboxes into JSON array
        $roles = array();
        if (!empty($_POST['roles']) && is_array($_POST['roles'])) {
            foreach ($_POST['roles'] as $role) {
                $roles[] = sanitize_text_field($role);
            }
        }

        $data = array(
            'cohort_id'  => absint($_POST['cohort_id']),
            'user_id'     => absint($_POST['user_id']),
            'roles'       => wp_json_encode($roles),
            'center_id'   => !empty($_POST['center_id']) ? absint($_POST['center_id']) : null,
            'district_id' => !empty($_POST['district_id']) ? absint($_POST['district_id']) : null,
            'status'      => sanitize_text_field($_POST['status']),
        );

        if ($enrollment_id > 0) {
            $wpdb->update(
                $wpdb->prefix . 'hl_enrollment',
                $data,
                array('enrollment_id' => $enrollment_id)
            );
            $redirect = admin_url('admin.php?page=hl-enrollments&message=updated');
        } else {
            $data['enrollment_uuid'] = HL_DB_Utils::generate_uuid();
            $data['enrolled_at']     = current_time('mysql');
            $wpdb->insert($wpdb->prefix . 'hl_enrollment', $data);
            $redirect = admin_url('admin.php?page=hl-enrollments&message=created');
        }

        wp_redirect($redirect);
        exit;
    }

    /**
     * Handle delete action
     */
    private function handle_delete() {
        $enrollment_id = isset($_GET['id']) ? absint($_GET['id']) : 0;

        if (!$enrollment_id) {
            return;
        }

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'hl_delete_enrollment_' . $enrollment_id)) {
            wp_die(__('Security check failed.', 'hl-core'));
        }

        if (!current_user_can('manage_hl_core')) {
            wp_die(__('You do not have permission to perform this action.', 'hl-core'));
        }

        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'hl_enrollment', array('enrollment_id' => $enrollment_id));

        wp_redirect(admin_url('admin.php?page=hl-enrollments&message=deleted'));
        exit;
    }

    /**
     * Render the enrollments list table
     */
    private function render_list() {
        global $wpdb;

        // Filter by cohort
        $filter_cohort = isset($_GET['cohort_id']) ? absint($_GET['cohort_id']) : 0;

        $where = '';
        if ($filter_cohort) {
            $where = $wpdb->prepare(' WHERE e.cohort_id = %d', $filter_cohort);
        }

        $enrollments = $wpdb->get_results(
            "SELECT e.*, p.cohort_name, u.display_name, u.user_email
             FROM {$wpdb->prefix}hl_enrollment e
             LEFT JOIN {$wpdb->prefix}hl_cohort p ON e.cohort_id = p.cohort_id
             LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
             {$where}
             ORDER BY e.enrolled_at DESC"
        );

        // Get cohorts for filter dropdown
        $cohorts = $wpdb->get_results(
            "SELECT cohort_id, cohort_name FROM {$wpdb->prefix}hl_cohort ORDER BY cohort_name ASC"
        );

        // Get center names
        $centers = array();
        $center_rows = $wpdb->get_results(
            "SELECT orgunit_id, name FROM {$wpdb->prefix}hl_orgunit WHERE orgunit_type = 'center'"
        );
        if ($center_rows) {
            foreach ($center_rows as $c) {
                $centers[$c->orgunit_id] = $c->name;
            }
        }

        // Show success messages
        if (isset($_GET['message'])) {
            $msg = sanitize_text_field($_GET['message']);
            if ($msg === 'created') {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Enrollment created successfully.', 'hl-core') . '</p></div>';
            } elseif ($msg === 'updated') {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Enrollment updated successfully.', 'hl-core') . '</p></div>';
            } elseif ($msg === 'deleted') {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Enrollment deleted successfully.', 'hl-core') . '</p></div>';
            }
        }

        // Cohort breadcrumb.
        if ($filter_cohort) {
            global $wpdb;
            $cohort_name = $wpdb->get_var($wpdb->prepare(
                "SELECT cohort_name FROM {$wpdb->prefix}hl_cohort WHERE cohort_id = %d", $filter_cohort
            ));
            if ($cohort_name) {
                echo '<p style="margin:0 0 5px;"><a href="' . esc_url(admin_url('admin.php?page=hl-core&action=edit&id=' . $filter_cohort . '&tab=enrollments')) . '">&larr; ' . sprintf(esc_html__('Cohort: %s', 'hl-core'), esc_html($cohort_name)) . '</a></p>';
            }
        }

        echo '<h1 class="wp-heading-inline">' . esc_html__('Enrollments', 'hl-core') . '</h1>';
        echo ' <a href="' . esc_url(admin_url('admin.php?page=hl-enrollments&action=new')) . '" class="page-title-action">' . esc_html__('Add New', 'hl-core') . '</a>';
        echo '<hr class="wp-header-end">';

        // Cohort filter form
        echo '<form method="get" style="margin-bottom:15px;">';
        echo '<input type="hidden" name="page" value="hl-enrollments" />';
        echo '<label for="cohort_id_filter"><strong>' . esc_html__('Filter by Cohort:', 'hl-core') . '</strong> </label>';
        echo '<select name="cohort_id" id="cohort_id_filter">';
        echo '<option value="">' . esc_html__('All Cohorts', 'hl-core') . '</option>';
        if ($cohorts) {
            foreach ($cohorts as $cohort) {
                echo '<option value="' . esc_attr($cohort->cohort_id) . '"' . selected($filter_cohort, $cohort->cohort_id, false) . '>' . esc_html($cohort->cohort_name) . '</option>';
            }
        }
        echo '</select> ';
        submit_button(__('Filter', 'hl-core'), 'secondary', 'submit', false);
        echo '</form>';

        if (empty($enrollments)) {
            echo '<p>' . esc_html__('No enrollments found.', 'hl-core') . '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>' . esc_html__('User Name', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Email', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Cohort', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Roles', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Center', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Status', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Enrolled At', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Actions', 'hl-core') . '</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';

        foreach ($enrollments as $enrollment) {
            $edit_url   = admin_url('admin.php?page=hl-enrollments&action=edit&id=' . $enrollment->enrollment_id);
            $delete_url = wp_nonce_url(
                admin_url('admin.php?page=hl-enrollments&action=delete&id=' . $enrollment->enrollment_id),
                'hl_delete_enrollment_' . $enrollment->enrollment_id
            );

            // Decode roles
            $roles_array = json_decode($enrollment->roles, true);
            $roles_display = is_array($roles_array) ? implode(', ', $roles_array) : '';

            // Center name
            $center_name = '';
            if ($enrollment->center_id && isset($centers[$enrollment->center_id])) {
                $center_name = $centers[$enrollment->center_id];
            }

            // Status
            $status_style = ($enrollment->status === 'active')
                ? 'color:#00a32a;font-weight:600;'
                : 'color:#b32d2e;font-weight:600;';

            echo '<tr>';
            echo '<td><strong><a href="' . esc_url($edit_url) . '">' . esc_html($enrollment->display_name) . '</a></strong></td>';
            echo '<td>' . esc_html($enrollment->user_email) . '</td>';
            echo '<td>' . esc_html($enrollment->cohort_name) . '</td>';
            echo '<td>' . esc_html($roles_display) . '</td>';
            echo '<td>' . esc_html($center_name) . '</td>';
            echo '<td><span style="' . esc_attr($status_style) . '">' . esc_html(ucfirst($enrollment->status)) . '</span></td>';
            echo '<td>' . esc_html($enrollment->enrolled_at) . '</td>';
            echo '<td>';
            echo '<a href="' . esc_url($edit_url) . '" class="button button-small">' . esc_html__('Edit', 'hl-core') . '</a> ';
            echo '<a href="' . esc_url($delete_url) . '" class="button button-small button-link-delete" onclick="return confirm(\'' . esc_js(__('Are you sure you want to delete this enrollment?', 'hl-core')) . '\');">' . esc_html__('Delete', 'hl-core') . '</a>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
    }

    /**
     * Render the create/edit form
     *
     * @param object|null $enrollment Enrollment row for edit, null for create.
     */
    private function render_form($enrollment = null) {
        $is_edit = ($enrollment !== null);
        $title   = $is_edit ? __('Edit Enrollment', 'hl-core') : __('Add New Enrollment', 'hl-core');

        global $wpdb;

        // Get WP users for dropdown
        $users = get_users(array('orderby' => 'display_name', 'order' => 'ASC', 'number' => 500));

        // Get cohorts
        $cohorts = $wpdb->get_results(
            "SELECT cohort_id, cohort_name FROM {$wpdb->prefix}hl_cohort ORDER BY cohort_name ASC"
        );

        // Get centers
        $centers_rows = $wpdb->get_results(
            "SELECT orgunit_id, name FROM {$wpdb->prefix}hl_orgunit WHERE orgunit_type = 'center' AND status = 'active' ORDER BY name ASC"
        );

        // Get districts
        $districts = $wpdb->get_results(
            "SELECT orgunit_id, name FROM {$wpdb->prefix}hl_orgunit WHERE orgunit_type = 'district' AND status = 'active' ORDER BY name ASC"
        );

        // Decode current roles
        $current_roles = array();
        if ($is_edit && !empty($enrollment->roles)) {
            $decoded = json_decode($enrollment->roles, true);
            if (is_array($decoded)) {
                $current_roles = $decoded;
            }
        }

        echo '<h1>' . esc_html($title) . '</h1>';
        echo '<a href="' . esc_url(admin_url('admin.php?page=hl-enrollments')) . '">&larr; ' . esc_html__('Back to Enrollments', 'hl-core') . '</a>';

        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=hl-enrollments')) . '">';
        wp_nonce_field('hl_save_enrollment', 'hl_enrollment_nonce');

        if ($is_edit) {
            echo '<input type="hidden" name="enrollment_id" value="' . esc_attr($enrollment->enrollment_id) . '" />';
        }

        echo '<table class="form-table">';

        // User
        $current_user_id = $is_edit ? $enrollment->user_id : '';
        echo '<tr>';
        echo '<th scope="row"><label for="user_id">' . esc_html__('User', 'hl-core') . '</label></th>';
        echo '<td><select id="user_id" name="user_id" required>';
        echo '<option value="">' . esc_html__('-- Select User --', 'hl-core') . '</option>';
        foreach ($users as $user) {
            echo '<option value="' . esc_attr($user->ID) . '"' . selected($current_user_id, $user->ID, false) . '>' . esc_html($user->display_name) . ' (' . esc_html($user->user_email) . ')</option>';
        }
        echo '</select></td>';
        echo '</tr>';

        // Cohort
        $current_cohort = $is_edit ? $enrollment->cohort_id : '';
        echo '<tr>';
        echo '<th scope="row"><label for="cohort_id">' . esc_html__('Cohort', 'hl-core') . '</label></th>';
        echo '<td><select id="cohort_id" name="cohort_id" required>';
        echo '<option value="">' . esc_html__('-- Select Cohort --', 'hl-core') . '</option>';
        if ($cohorts) {
            foreach ($cohorts as $cohort) {
                echo '<option value="' . esc_attr($cohort->cohort_id) . '"' . selected($current_cohort, $cohort->cohort_id, false) . '>' . esc_html($cohort->cohort_name) . '</option>';
            }
        }
        echo '</select></td>';
        echo '</tr>';

        // Roles (checkboxes)
        $available_roles = array('Teacher', 'Mentor', 'Center Leader', 'District Leader');
        echo '<tr>';
        echo '<th scope="row">' . esc_html__('Roles', 'hl-core') . '</th>';
        echo '<td><fieldset>';
        foreach ($available_roles as $role) {
            $checked = in_array($role, $current_roles) ? ' checked="checked"' : '';
            echo '<label><input type="checkbox" name="roles[]" value="' . esc_attr($role) . '"' . $checked . ' /> ' . esc_html($role) . '</label><br />';
        }
        echo '</fieldset></td>';
        echo '</tr>';

        // Center
        $current_center = $is_edit ? $enrollment->center_id : '';
        echo '<tr>';
        echo '<th scope="row"><label for="center_id">' . esc_html__('Center', 'hl-core') . '</label></th>';
        echo '<td><select id="center_id" name="center_id">';
        echo '<option value="">' . esc_html__('-- Select Center --', 'hl-core') . '</option>';
        if ($centers_rows) {
            foreach ($centers_rows as $center) {
                echo '<option value="' . esc_attr($center->orgunit_id) . '"' . selected($current_center, $center->orgunit_id, false) . '>' . esc_html($center->name) . '</option>';
            }
        }
        echo '</select></td>';
        echo '</tr>';

        // District
        $current_district = $is_edit ? $enrollment->district_id : '';
        echo '<tr>';
        echo '<th scope="row"><label for="district_id">' . esc_html__('District', 'hl-core') . '</label></th>';
        echo '<td><select id="district_id" name="district_id">';
        echo '<option value="">' . esc_html__('-- Select District --', 'hl-core') . '</option>';
        if ($districts) {
            foreach ($districts as $district) {
                echo '<option value="' . esc_attr($district->orgunit_id) . '"' . selected($current_district, $district->orgunit_id, false) . '>' . esc_html($district->name) . '</option>';
            }
        }
        echo '</select></td>';
        echo '</tr>';

        // Status
        $current_status = $is_edit ? $enrollment->status : 'active';
        echo '<tr>';
        echo '<th scope="row"><label for="status">' . esc_html__('Status', 'hl-core') . '</label></th>';
        echo '<td><select id="status" name="status">';
        echo '<option value="active"' . selected($current_status, 'active', false) . '>' . esc_html__('Active', 'hl-core') . '</option>';
        echo '<option value="inactive"' . selected($current_status, 'inactive', false) . '>' . esc_html__('Inactive', 'hl-core') . '</option>';
        echo '</select></td>';
        echo '</tr>';

        echo '</table>';

        submit_button($is_edit ? __('Update Enrollment', 'hl-core') : __('Create Enrollment', 'hl-core'));

        echo '</form>';
    }
}
