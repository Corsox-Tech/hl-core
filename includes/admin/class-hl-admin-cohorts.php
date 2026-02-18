<?php if (!defined('ABSPATH')) exit;

/**
 * Admin Cohorts Page
 *
 * Full CRUD admin page for managing Cohorts.
 *
 * @package HL_Core
 */
class HL_Admin_Cohorts {

    /**
     * Singleton instance
     *
     * @var HL_Admin_Cohorts|null
     */
    private static $instance = null;

    /**
     * Repository instance
     *
     * @var HL_Cohort_Repository
     */
    private $repo;

    /**
     * Get singleton instance
     *
     * @return HL_Admin_Cohorts
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
        $this->repo = new HL_Cohort_Repository();
    }

    /**
     * Main render entry point
     */
    public function render_page() {
        // Process form submissions first
        $this->handle_actions();

        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : 'list';

        echo '<div class="wrap">';

        switch ($action) {
            case 'new':
                $this->render_form();
                break;

            case 'edit':
                $cohort_id = isset($_GET['id']) ? absint($_GET['id']) : 0;
                $cohort = $this->repo->get_by_id($cohort_id);
                if ($cohort) {
                    $this->render_form($cohort);
                } else {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Cohort not found.', 'hl-core') . '</p></div>';
                    $this->render_list();
                }
                break;

            case 'delete':
                $this->handle_delete();
                $this->render_list();
                break;

            default:
                $this->render_list();
                break;
        }

        echo '</div>';
    }

    /**
     * Handle form submissions
     */
    private function handle_actions() {
        if (!isset($_POST['hl_cohort_nonce'])) {
            return;
        }

        if (!wp_verify_nonce($_POST['hl_cohort_nonce'], 'hl_save_cohort')) {
            wp_die(__('Security check failed.', 'hl-core'));
        }

        if (!current_user_can('manage_hl_core')) {
            wp_die(__('You do not have permission to perform this action.', 'hl-core'));
        }

        $cohort_id = isset($_POST['cohort_id']) ? absint($_POST['cohort_id']) : 0;

        $data = array(
            'cohort_name' => sanitize_text_field($_POST['cohort_name']),
            'cohort_code' => sanitize_text_field($_POST['cohort_code']),
            'status'       => sanitize_text_field($_POST['status']),
            'start_date'   => sanitize_text_field($_POST['start_date']),
            'end_date'     => sanitize_text_field($_POST['end_date']),
            'timezone'     => sanitize_text_field($_POST['timezone']),
            'district_id'  => !empty($_POST['district_id']) ? absint($_POST['district_id']) : null,
        );

        if (empty($data['end_date'])) {
            $data['end_date'] = null;
        }

        if ($cohort_id > 0) {
            // Update
            $this->repo->update($cohort_id, $data);
            $redirect = admin_url('admin.php?page=hl-core&message=updated');
        } else {
            // Create
            $data['cohort_uuid'] = HL_DB_Utils::generate_uuid();
            if (empty($data['cohort_code'])) {
                $data['cohort_code'] = HL_Normalization::generate_code($data['cohort_name']);
            }
            $this->repo->create($data);
            $redirect = admin_url('admin.php?page=hl-core&message=created');
        }

        wp_redirect($redirect);
        exit;
    }

    /**
     * Handle delete action
     */
    private function handle_delete() {
        $cohort_id = isset($_GET['id']) ? absint($_GET['id']) : 0;

        if (!$cohort_id) {
            return;
        }

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'hl_delete_cohort_' . $cohort_id)) {
            wp_die(__('Security check failed.', 'hl-core'));
        }

        if (!current_user_can('manage_hl_core')) {
            wp_die(__('You do not have permission to perform this action.', 'hl-core'));
        }

        $this->repo->delete($cohort_id);

        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Cohort deleted successfully.', 'hl-core') . '</p></div>';
    }

    /**
     * Render the cohorts list table
     */
    private function render_list() {
        $cohorts = $this->repo->get_all();

        // Show success messages
        if (isset($_GET['message'])) {
            $msg = sanitize_text_field($_GET['message']);
            if ($msg === 'created') {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Cohort created successfully.', 'hl-core') . '</p></div>';
            } elseif ($msg === 'updated') {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Cohort updated successfully.', 'hl-core') . '</p></div>';
            }
        }

        echo '<h1 class="wp-heading-inline">' . esc_html__('Cohorts', 'hl-core') . '</h1>';
        echo ' <a href="' . esc_url(admin_url('admin.php?page=hl-core&action=new')) . '" class="page-title-action">' . esc_html__('Add New', 'hl-core') . '</a>';
        echo '<hr class="wp-header-end">';

        // Get center counts per cohort
        global $wpdb;
        $center_counts = array();
        $counts = $wpdb->get_results(
            "SELECT cohort_id, COUNT(*) as cnt FROM {$wpdb->prefix}hl_cohort_center GROUP BY cohort_id",
            ARRAY_A
        );
        if ($counts) {
            foreach ($counts as $row) {
                $center_counts[$row['cohort_id']] = $row['cnt'];
            }
        }

        // Get district names
        $districts = array();
        $district_rows = $wpdb->get_results(
            "SELECT orgunit_id, name FROM {$wpdb->prefix}hl_orgunit WHERE orgunit_type = 'district'",
            ARRAY_A
        );
        if ($district_rows) {
            foreach ($district_rows as $row) {
                $districts[$row['orgunit_id']] = $row['name'];
            }
        }

        if (empty($cohorts)) {
            echo '<p>' . esc_html__('No cohorts found. Create your first cohort to get started.', 'hl-core') . '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>' . esc_html__('ID', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Name', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Code', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Status', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Start Date', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('District', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Centers', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Actions', 'hl-core') . '</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';

        foreach ($cohorts as $cohort) {
            $edit_url   = admin_url('admin.php?page=hl-core&action=edit&id=' . $cohort->cohort_id);
            $delete_url = wp_nonce_url(
                admin_url('admin.php?page=hl-core&action=delete&id=' . $cohort->cohort_id),
                'hl_delete_cohort_' . $cohort->cohort_id
            );

            $status_class = '';
            switch ($cohort->status) {
                case 'active':
                    $status_class = 'color: #00a32a;';
                    break;
                case 'draft':
                    $status_class = 'color: #996800;';
                    break;
                case 'paused':
                    $status_class = 'color: #b32d2e;';
                    break;
                case 'archived':
                    $status_class = 'color: #8c8f94;';
                    break;
            }

            $district_name = '';
            if ($cohort->district_id && isset($districts[$cohort->district_id])) {
                $district_name = $districts[$cohort->district_id];
            }

            $center_count = isset($center_counts[$cohort->cohort_id]) ? $center_counts[$cohort->cohort_id] : 0;

            echo '<tr>';
            echo '<td>' . esc_html($cohort->cohort_id) . '</td>';
            echo '<td><strong><a href="' . esc_url($edit_url) . '">' . esc_html($cohort->cohort_name) . '</a></strong></td>';
            echo '<td><code>' . esc_html($cohort->cohort_code) . '</code></td>';
            echo '<td><span style="' . esc_attr($status_class) . ' font-weight:600;">' . esc_html(ucfirst($cohort->status)) . '</span></td>';
            echo '<td>' . esc_html($cohort->start_date) . '</td>';
            echo '<td>' . esc_html($district_name) . '</td>';
            echo '<td>' . esc_html($center_count) . '</td>';
            echo '<td>';
            echo '<a href="' . esc_url($edit_url) . '" class="button button-small">' . esc_html__('Edit', 'hl-core') . '</a> ';
            echo '<a href="' . esc_url($delete_url) . '" class="button button-small button-link-delete" onclick="return confirm(\'' . esc_js(__('Are you sure you want to delete this cohort?', 'hl-core')) . '\');">' . esc_html__('Delete', 'hl-core') . '</a>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
    }

    /**
     * Render the create/edit form
     *
     * @param HL_Cohort|null $cohort Cohort object for edit, null for create.
     */
    private function render_form($cohort = null) {
        $is_edit = ($cohort !== null);
        $title   = $is_edit ? __('Edit Cohort', 'hl-core') : __('Add New Cohort', 'hl-core');

        // Get districts for dropdown
        global $wpdb;
        $districts = $wpdb->get_results(
            "SELECT orgunit_id, name FROM {$wpdb->prefix}hl_orgunit WHERE orgunit_type = 'district' AND status = 'active' ORDER BY name ASC",
            ARRAY_A
        );

        echo '<h1>' . esc_html($title) . '</h1>';
        echo '<a href="' . esc_url(admin_url('admin.php?page=hl-core')) . '">&larr; ' . esc_html__('Back to Cohorts', 'hl-core') . '</a>';

        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=hl-core')) . '">';
        wp_nonce_field('hl_save_cohort', 'hl_cohort_nonce');

        if ($is_edit) {
            echo '<input type="hidden" name="cohort_id" value="' . esc_attr($cohort->cohort_id) . '" />';
        }

        echo '<table class="form-table">';

        // Cohort Name
        echo '<tr>';
        echo '<th scope="row"><label for="cohort_name">' . esc_html__('Cohort Name', 'hl-core') . '</label></th>';
        echo '<td><input type="text" id="cohort_name" name="cohort_name" value="' . esc_attr($is_edit ? $cohort->cohort_name : '') . '" class="regular-text" required /></td>';
        echo '</tr>';

        // Cohort Code
        echo '<tr>';
        echo '<th scope="row"><label for="cohort_code">' . esc_html__('Cohort Code', 'hl-core') . '</label></th>';
        echo '<td><input type="text" id="cohort_code" name="cohort_code" value="' . esc_attr($is_edit ? $cohort->cohort_code : '') . '" class="regular-text" />';
        echo '<p class="description">' . esc_html__('Leave blank to auto-generate from name.', 'hl-core') . '</p></td>';
        echo '</tr>';

        // Status
        $current_status = $is_edit ? $cohort->status : 'draft';
        echo '<tr>';
        echo '<th scope="row"><label for="status">' . esc_html__('Status', 'hl-core') . '</label></th>';
        echo '<td><select id="status" name="status">';
        foreach (array('draft', 'active', 'paused', 'archived') as $status) {
            echo '<option value="' . esc_attr($status) . '"' . selected($current_status, $status, false) . '>' . esc_html(ucfirst($status)) . '</option>';
        }
        echo '</select></td>';
        echo '</tr>';

        // Start Date
        echo '<tr>';
        echo '<th scope="row"><label for="start_date">' . esc_html__('Start Date', 'hl-core') . '</label></th>';
        echo '<td><input type="date" id="start_date" name="start_date" value="' . esc_attr($is_edit ? $cohort->start_date : '') . '" required /></td>';
        echo '</tr>';

        // End Date
        echo '<tr>';
        echo '<th scope="row"><label for="end_date">' . esc_html__('End Date', 'hl-core') . '</label></th>';
        echo '<td><input type="date" id="end_date" name="end_date" value="' . esc_attr($is_edit && $cohort->end_date ? $cohort->end_date : '') . '" />';
        echo '<p class="description">' . esc_html__('Optional. Leave blank for open-ended cohorts.', 'hl-core') . '</p></td>';
        echo '</tr>';

        // Timezone
        $current_tz = $is_edit ? $cohort->timezone : 'America/Bogota';
        echo '<tr>';
        echo '<th scope="row"><label for="timezone">' . esc_html__('Timezone', 'hl-core') . '</label></th>';
        echo '<td><select id="timezone" name="timezone">';
        $timezones = array(
            'America/Bogota'      => 'America/Bogota (COT)',
            'America/New_York'    => 'America/New_York (EST)',
            'America/Chicago'     => 'America/Chicago (CST)',
            'America/Denver'      => 'America/Denver (MST)',
            'America/Los_Angeles' => 'America/Los_Angeles (PST)',
            'America/Lima'        => 'America/Lima (PET)',
            'America/Mexico_City' => 'America/Mexico_City (CST)',
            'UTC'                 => 'UTC',
        );
        foreach ($timezones as $tz_value => $tz_label) {
            echo '<option value="' . esc_attr($tz_value) . '"' . selected($current_tz, $tz_value, false) . '>' . esc_html($tz_label) . '</option>';
        }
        echo '</select></td>';
        echo '</tr>';

        // District
        $current_district = $is_edit ? $cohort->district_id : '';
        echo '<tr>';
        echo '<th scope="row"><label for="district_id">' . esc_html__('District', 'hl-core') . '</label></th>';
        echo '<td><select id="district_id" name="district_id">';
        echo '<option value="">' . esc_html__('-- Select District --', 'hl-core') . '</option>';
        if ($districts) {
            foreach ($districts as $district) {
                echo '<option value="' . esc_attr($district['orgunit_id']) . '"' . selected($current_district, $district['orgunit_id'], false) . '>' . esc_html($district['name']) . '</option>';
            }
        }
        echo '</select></td>';
        echo '</tr>';

        echo '</table>';

        submit_button($is_edit ? __('Update Cohort', 'hl-core') : __('Create Cohort', 'hl-core'));

        echo '</form>';
    }
}
