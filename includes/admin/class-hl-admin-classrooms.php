<?php if (!defined('ABSPATH')) exit;

/**
 * Admin Classrooms Page
 *
 * Full CRUD admin page for managing Classrooms, Teaching Assignments, and Child Classroom Assignments.
 *
 * @package HL_Core
 */
class HL_Admin_Classrooms {

    /** @var HL_Admin_Classrooms|null */
    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // No hooks needed.
    }

    /**
     * Handle POST saves and GET deletes before any HTML output.
     */
    public function handle_early_actions() {
        $this->handle_actions();
        $this->handle_assignment_actions();
        $this->handle_child_actions();

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
                $classroom_id = isset($_GET['id']) ? absint($_GET['id']) : 0;
                $classroom    = $this->get_classroom($classroom_id);
                if ($classroom) {
                    $this->render_form($classroom);
                } else {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Classroom not found.', 'hl-core') . '</p></div>';
                    $this->render_list();
                }
                break;

            case 'view':
                $classroom_id = isset($_GET['id']) ? absint($_GET['id']) : 0;
                $classroom    = $this->get_classroom($classroom_id);
                if ($classroom) {
                    $this->render_classroom_detail($classroom);
                } else {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Classroom not found.', 'hl-core') . '</p></div>';
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
     * Get a single classroom by ID
     */
    private function get_classroom($classroom_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_classroom WHERE classroom_id = %d",
            $classroom_id
        ));
        return $row;
    }

    // =========================================================================
    // Classroom CRUD
    // =========================================================================

    /**
     * Handle classroom save (create/update)
     */
    private function handle_actions() {
        if (!isset($_POST['hl_classroom_nonce'])) {
            return;
        }

        if (!wp_verify_nonce($_POST['hl_classroom_nonce'], 'hl_save_classroom')) {
            wp_die(__('Security check failed.', 'hl-core'));
        }

        if (!current_user_can('manage_hl_core')) {
            wp_die(__('You do not have permission to perform this action.', 'hl-core'));
        }

        $classroom_id = isset($_POST['classroom_id']) ? absint($_POST['classroom_id']) : 0;

        $data = array(
            'classroom_name' => sanitize_text_field($_POST['classroom_name']),
            'center_id'      => absint($_POST['center_id']),
            'age_band'       => sanitize_text_field($_POST['age_band']),
            'status'         => sanitize_text_field($_POST['status']),
        );

        $service = new HL_Classroom_Service();

        if ($classroom_id > 0) {
            $service->update_classroom($classroom_id, $data);
            $redirect = admin_url('admin.php?page=hl-classrooms&message=updated');
        } else {
            $result = $service->create_classroom($data);
            if (is_wp_error($result)) {
                $redirect = admin_url('admin.php?page=hl-classrooms&action=new&message=error');
            } else {
                $redirect = admin_url('admin.php?page=hl-classrooms&message=created');
            }
        }

        wp_redirect($redirect);
        exit;
    }

    /**
     * Handle classroom delete
     */
    private function handle_delete() {
        $classroom_id = isset($_GET['id']) ? absint($_GET['id']) : 0;

        if (!$classroom_id) {
            return;
        }

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'hl_delete_classroom_' . $classroom_id)) {
            wp_die(__('Security check failed.', 'hl-core'));
        }

        if (!current_user_can('manage_hl_core')) {
            wp_die(__('You do not have permission to perform this action.', 'hl-core'));
        }

        $repo = new HL_Classroom_Repository();
        $repo->delete($classroom_id);

        wp_redirect(admin_url('admin.php?page=hl-classrooms&message=deleted'));
        exit;
    }

    /**
     * Render classroom list
     */
    private function render_list() {
        global $wpdb;

        $filter_center = isset($_GET['center_id']) ? absint($_GET['center_id']) : 0;

        $where = '';
        if ($filter_center) {
            $where = $wpdb->prepare(' WHERE c.center_id = %d', $filter_center);
        }

        $classrooms = $wpdb->get_results(
            "SELECT c.*, o.name AS center_name
             FROM {$wpdb->prefix}hl_classroom c
             LEFT JOIN {$wpdb->prefix}hl_orgunit o ON c.center_id = o.orgunit_id
             {$where}
             ORDER BY c.classroom_name ASC"
        );

        $centers = $wpdb->get_results(
            "SELECT orgunit_id, name FROM {$wpdb->prefix}hl_orgunit WHERE orgunit_type = 'center' ORDER BY name ASC"
        );

        // Messages
        if (isset($_GET['message'])) {
            $msg = sanitize_text_field($_GET['message']);
            if ($msg === 'created') {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Classroom created successfully.', 'hl-core') . '</p></div>';
            } elseif ($msg === 'updated') {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Classroom updated successfully.', 'hl-core') . '</p></div>';
            } elseif ($msg === 'deleted') {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Classroom deleted successfully.', 'hl-core') . '</p></div>';
            }
        }

        echo '<h1 class="wp-heading-inline">' . esc_html__('Classrooms', 'hl-core') . '</h1>';
        echo ' <a href="' . esc_url(admin_url('admin.php?page=hl-classrooms&action=new')) . '" class="page-title-action">' . esc_html__('Add New', 'hl-core') . '</a>';
        echo '<hr class="wp-header-end">';

        // Center filter
        echo '<form method="get" style="margin-bottom:15px;">';
        echo '<input type="hidden" name="page" value="hl-classrooms" />';
        echo '<label><strong>' . esc_html__('Center:', 'hl-core') . '</strong> </label>';
        echo '<select name="center_id">';
        echo '<option value="">' . esc_html__('All', 'hl-core') . '</option>';
        if ($centers) {
            foreach ($centers as $center) {
                echo '<option value="' . esc_attr($center->orgunit_id) . '"' . selected($filter_center, $center->orgunit_id, false) . '>' . esc_html($center->name) . '</option>';
            }
        }
        echo '</select> ';
        submit_button(__('Filter', 'hl-core'), 'secondary', 'submit', false);
        echo '</form>';

        if (empty($classrooms)) {
            echo '<p>' . esc_html__('No classrooms found.', 'hl-core') . '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('ID', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Classroom Name', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Center', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Age Band', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Status', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Actions', 'hl-core') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($classrooms as $classroom) {
            $view_url   = admin_url('admin.php?page=hl-classrooms&action=view&id=' . $classroom->classroom_id);
            $edit_url   = admin_url('admin.php?page=hl-classrooms&action=edit&id=' . $classroom->classroom_id);
            $delete_url = wp_nonce_url(
                admin_url('admin.php?page=hl-classrooms&action=delete&id=' . $classroom->classroom_id),
                'hl_delete_classroom_' . $classroom->classroom_id
            );

            $status_style = ($classroom->status === 'active')
                ? 'color:#00a32a;font-weight:600;'
                : 'color:#b32d2e;font-weight:600;';

            echo '<tr>';
            echo '<td>' . esc_html($classroom->classroom_id) . '</td>';
            echo '<td><strong><a href="' . esc_url($view_url) . '">' . esc_html($classroom->classroom_name) . '</a></strong></td>';
            echo '<td>' . esc_html($classroom->center_name) . '</td>';
            echo '<td>' . esc_html($classroom->age_band ? ucfirst($classroom->age_band) : '-') . '</td>';
            echo '<td><span style="' . esc_attr($status_style) . '">' . esc_html(ucfirst($classroom->status)) . '</span></td>';
            echo '<td>';
            echo '<a href="' . esc_url($view_url) . '" class="button button-small">' . esc_html__('View', 'hl-core') . '</a> ';
            echo '<a href="' . esc_url($edit_url) . '" class="button button-small">' . esc_html__('Edit', 'hl-core') . '</a> ';
            echo '<a href="' . esc_url($delete_url) . '" class="button button-small button-link-delete" onclick="return confirm(\'' . esc_js(__('Are you sure you want to delete this classroom?', 'hl-core')) . '\');">' . esc_html__('Delete', 'hl-core') . '</a>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * Render classroom create/edit form
     */
    private function render_form($classroom = null) {
        $is_edit = ($classroom !== null);
        $title   = $is_edit ? __('Edit Classroom', 'hl-core') : __('Add New Classroom', 'hl-core');

        global $wpdb;

        $centers = $wpdb->get_results(
            "SELECT orgunit_id, name FROM {$wpdb->prefix}hl_orgunit WHERE orgunit_type = 'center' AND status = 'active' ORDER BY name ASC"
        );

        echo '<h1>' . esc_html($title) . '</h1>';
        echo '<a href="' . esc_url(admin_url('admin.php?page=hl-classrooms')) . '">&larr; ' . esc_html__('Back to Classrooms', 'hl-core') . '</a>';

        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=hl-classrooms')) . '">';
        wp_nonce_field('hl_save_classroom', 'hl_classroom_nonce');

        if ($is_edit) {
            echo '<input type="hidden" name="classroom_id" value="' . esc_attr($classroom->classroom_id) . '" />';
        }

        echo '<table class="form-table">';

        // Classroom Name
        echo '<tr>';
        echo '<th scope="row"><label for="classroom_name">' . esc_html__('Classroom Name', 'hl-core') . '</label></th>';
        echo '<td><input type="text" id="classroom_name" name="classroom_name" value="' . esc_attr($is_edit ? $classroom->classroom_name : '') . '" class="regular-text" required /></td>';
        echo '</tr>';

        // Center
        $current_center = $is_edit ? $classroom->center_id : '';
        echo '<tr>';
        echo '<th scope="row"><label for="center_id">' . esc_html__('Center', 'hl-core') . '</label></th>';
        echo '<td><select id="center_id" name="center_id" required>';
        echo '<option value="">' . esc_html__('-- Select Center --', 'hl-core') . '</option>';
        if ($centers) {
            foreach ($centers as $center) {
                echo '<option value="' . esc_attr($center->orgunit_id) . '"' . selected($current_center, $center->orgunit_id, false) . '>' . esc_html($center->name) . '</option>';
            }
        }
        echo '</select></td>';
        echo '</tr>';

        // Age Band
        $current_age_band = $is_edit ? $classroom->age_band : '';
        $age_bands = array('infant', 'toddler', 'preschool', 'mixed');
        echo '<tr>';
        echo '<th scope="row"><label for="age_band">' . esc_html__('Age Band', 'hl-core') . '</label></th>';
        echo '<td><select id="age_band" name="age_band">';
        echo '<option value="">' . esc_html__('-- Not Set --', 'hl-core') . '</option>';
        foreach ($age_bands as $band) {
            echo '<option value="' . esc_attr($band) . '"' . selected($current_age_band, $band, false) . '>' . esc_html(ucfirst($band)) . '</option>';
        }
        echo '</select></td>';
        echo '</tr>';

        // Status
        $current_status = $is_edit ? $classroom->status : 'active';
        echo '<tr>';
        echo '<th scope="row"><label for="status">' . esc_html__('Status', 'hl-core') . '</label></th>';
        echo '<td><select id="status" name="status">';
        echo '<option value="active"' . selected($current_status, 'active', false) . '>' . esc_html__('Active', 'hl-core') . '</option>';
        echo '<option value="inactive"' . selected($current_status, 'inactive', false) . '>' . esc_html__('Inactive', 'hl-core') . '</option>';
        echo '</select></td>';
        echo '</tr>';

        echo '</table>';
        submit_button($is_edit ? __('Update Classroom', 'hl-core') : __('Create Classroom', 'hl-core'));
        echo '</form>';
    }

    // =========================================================================
    // Classroom Detail View (Hub)
    // =========================================================================

    /**
     * Render classroom detail view with teaching assignments and children roster
     */
    private function render_classroom_detail($classroom) {
        global $wpdb;

        $center = $wpdb->get_row($wpdb->prepare(
            "SELECT name FROM {$wpdb->prefix}hl_orgunit WHERE orgunit_id = %d",
            $classroom->center_id
        ));

        // Messages
        if (isset($_GET['message'])) {
            $msg = sanitize_text_field($_GET['message']);
            $messages = array(
                'assignment_created' => array('success', __('Teaching assignment added.', 'hl-core')),
                'assignment_removed' => array('success', __('Teaching assignment removed.', 'hl-core')),
                'assignment_error'   => array('error', __('Failed to add teaching assignment.', 'hl-core')),
                'child_assigned'     => array('success', __('Child assigned to classroom.', 'hl-core')),
                'child_unassigned'   => array('success', __('Child removed from classroom.', 'hl-core')),
                'child_error'        => array('error', __('Failed to assign child.', 'hl-core')),
            );
            if (isset($messages[$msg])) {
                echo '<div class="notice notice-' . esc_attr($messages[$msg][0]) . ' is-dismissible"><p>' . esc_html($messages[$msg][1]) . '</p></div>';
            }
        }

        echo '<h1>' . esc_html($classroom->classroom_name) . '</h1>';
        echo '<a href="' . esc_url(admin_url('admin.php?page=hl-classrooms')) . '">&larr; ' . esc_html__('Back to Classrooms', 'hl-core') . '</a>';

        echo '<table class="form-table">';
        echo '<tr><th>' . esc_html__('Center', 'hl-core') . '</th><td>' . esc_html($center ? $center->name : 'N/A') . '</td></tr>';
        echo '<tr><th>' . esc_html__('Age Band', 'hl-core') . '</th><td>' . esc_html($classroom->age_band ? ucfirst($classroom->age_band) : 'Not set') . '</td></tr>';
        echo '<tr><th>' . esc_html__('Status', 'hl-core') . '</th><td>' . esc_html(ucfirst($classroom->status)) . '</td></tr>';
        echo '</table>';

        echo '<a href="' . esc_url(admin_url('admin.php?page=hl-classrooms&action=edit&id=' . $classroom->classroom_id)) . '" class="button">' . esc_html__('Edit Classroom', 'hl-core') . '</a>';

        $this->render_teaching_assignments_section($classroom);
        $this->render_children_roster_section($classroom);
    }

    // =========================================================================
    // Teaching Assignments Section
    // =========================================================================

    /**
     * Handle teaching assignment add/remove
     */
    private function handle_assignment_actions() {
        // Handle add (POST)
        if (isset($_POST['hl_teaching_assignment_nonce'])) {
            if (!wp_verify_nonce($_POST['hl_teaching_assignment_nonce'], 'hl_save_teaching_assignment')) {
                wp_die(__('Security check failed.', 'hl-core'));
            }
            if (!current_user_can('manage_hl_core')) {
                wp_die(__('You do not have permission to perform this action.', 'hl-core'));
            }

            $service = new HL_Classroom_Service();
            $result = $service->create_teaching_assignment(array(
                'enrollment_id'        => absint($_POST['enrollment_id']),
                'classroom_id'         => absint($_POST['classroom_id']),
                'is_lead_teacher'      => !empty($_POST['is_lead_teacher']) ? 1 : 0,
                'effective_start_date' => sanitize_text_field($_POST['effective_start_date']),
                'effective_end_date'   => sanitize_text_field($_POST['effective_end_date']),
            ));

            $classroom_id = absint($_POST['classroom_id']);
            $cohort_id    = isset($_POST['cohort_id']) ? absint($_POST['cohort_id']) : 0;
            $msg = is_wp_error($result) ? 'assignment_error' : 'assignment_created';

            $redirect = admin_url('admin.php?page=hl-classrooms&action=view&id=' . $classroom_id . '&cohort_id=' . $cohort_id . '&message=' . $msg);
            wp_redirect($redirect);
            exit;
        }

        // Handle remove (GET)
        if (isset($_GET['remove_assignment'])) {
            $assignment_id = absint($_GET['remove_assignment']);

            if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'hl_delete_teaching_assignment_' . $assignment_id)) {
                wp_die(__('Security check failed.', 'hl-core'));
            }
            if (!current_user_can('manage_hl_core')) {
                wp_die(__('You do not have permission to perform this action.', 'hl-core'));
            }

            $service = new HL_Classroom_Service();
            $service->delete_teaching_assignment($assignment_id);

            $classroom_id = isset($_GET['id']) ? absint($_GET['id']) : 0;
            $cohort_id    = isset($_GET['cohort_id']) ? absint($_GET['cohort_id']) : 0;

            wp_redirect(admin_url('admin.php?page=hl-classrooms&action=view&id=' . $classroom_id . '&cohort_id=' . $cohort_id . '&message=assignment_removed'));
            exit;
        }
    }

    /**
     * Render teaching assignments section within classroom detail
     */
    private function render_teaching_assignments_section($classroom) {
        global $wpdb;

        $service = new HL_Classroom_Service();

        echo '<hr />';
        echo '<h2>' . esc_html__('Teaching Assignments', 'hl-core') . '</h2>';

        // Cohort context selector
        $cohorts = $wpdb->get_results(
            "SELECT cohort_id, cohort_name FROM {$wpdb->prefix}hl_cohort ORDER BY cohort_name ASC"
        );
        $selected_cohort = isset($_GET['cohort_id']) ? absint($_GET['cohort_id']) : 0;

        echo '<form method="get" style="margin-bottom:10px;">';
        echo '<input type="hidden" name="page" value="hl-classrooms" />';
        echo '<input type="hidden" name="action" value="view" />';
        echo '<input type="hidden" name="id" value="' . esc_attr($classroom->classroom_id) . '" />';
        echo '<label><strong>' . esc_html__('Cohort Context:', 'hl-core') . '</strong> </label>';
        echo '<select name="cohort_id">';
        echo '<option value="">' . esc_html__('-- Select Cohort --', 'hl-core') . '</option>';
        if ($cohorts) {
            foreach ($cohorts as $cohort) {
                echo '<option value="' . esc_attr($cohort->cohort_id) . '"' . selected($selected_cohort, $cohort->cohort_id, false) . '>' . esc_html($cohort->cohort_name) . '</option>';
            }
        }
        echo '</select> ';
        submit_button(__('Select', 'hl-core'), 'secondary', 'submit', false);
        echo '</form>';

        // Current assignments table (all cohorts)
        $assignments = $service->get_teaching_assignments($classroom->classroom_id);

        if (!empty($assignments)) {
            echo '<table class="widefat striped">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__('Teacher', 'hl-core') . '</th>';
            echo '<th>' . esc_html__('Email', 'hl-core') . '</th>';
            echo '<th>' . esc_html__('Cohort', 'hl-core') . '</th>';
            echo '<th>' . esc_html__('Lead Teacher', 'hl-core') . '</th>';
            echo '<th>' . esc_html__('Start Date', 'hl-core') . '</th>';
            echo '<th>' . esc_html__('End Date', 'hl-core') . '</th>';
            echo '<th>' . esc_html__('Actions', 'hl-core') . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($assignments as $a) {
                $remove_url = wp_nonce_url(
                    admin_url('admin.php?page=hl-classrooms&action=view&id=' . $classroom->classroom_id . '&cohort_id=' . $selected_cohort . '&remove_assignment=' . $a->assignment_id),
                    'hl_delete_teaching_assignment_' . $a->assignment_id
                );

                echo '<tr>';
                echo '<td>' . esc_html($a->display_name) . '</td>';
                echo '<td>' . esc_html($a->user_email) . '</td>';
                echo '<td>' . esc_html($a->cohort_name) . '</td>';
                echo '<td>' . ($a->is_lead_teacher ? esc_html__('Yes', 'hl-core') : esc_html__('No', 'hl-core')) . '</td>';
                echo '<td>' . esc_html($a->effective_start_date ?: '-') . '</td>';
                echo '<td>' . esc_html($a->effective_end_date ?: '-') . '</td>';
                echo '<td><a href="' . esc_url($remove_url) . '" class="button button-small button-link-delete" onclick="return confirm(\'' . esc_js(__('Remove this teaching assignment?', 'hl-core')) . '\');">' . esc_html__('Remove', 'hl-core') . '</a></td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        } else {
            echo '<p>' . esc_html__('No teaching assignments yet.', 'hl-core') . '</p>';
        }

        // Add form (only when cohort selected)
        if ($selected_cohort) {
            // Get enrollments with Teacher role at this center for the selected cohort
            $all_enrollments = $wpdb->get_results($wpdb->prepare(
                "SELECT e.enrollment_id, e.roles, u.display_name, u.user_email
                 FROM {$wpdb->prefix}hl_enrollment e
                 LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
                 WHERE e.cohort_id = %d AND e.center_id = %d AND e.status = 'active'
                 ORDER BY u.display_name ASC",
                $selected_cohort,
                $classroom->center_id
            ));

            // Filter to Teacher role
            $available = array();
            $assigned_enrollment_ids = array();
            foreach ($assignments as $a) {
                $assigned_enrollment_ids[] = (int) $a->enrollment_id;
            }

            foreach ($all_enrollments as $e) {
                $roles = json_decode($e->roles, true);
                if (!is_array($roles) || !in_array('Teacher', $roles)) {
                    continue;
                }
                if (in_array((int) $e->enrollment_id, $assigned_enrollment_ids)) {
                    continue;
                }
                $available[] = $e;
            }

            if (!empty($available)) {
                echo '<h3>' . esc_html__('Add Teaching Assignment', 'hl-core') . '</h3>';
                $form_url = admin_url('admin.php?page=hl-classrooms&action=view&id=' . $classroom->classroom_id . '&cohort_id=' . $selected_cohort);
                echo '<form method="post" action="' . esc_url($form_url) . '">';
                wp_nonce_field('hl_save_teaching_assignment', 'hl_teaching_assignment_nonce');
                echo '<input type="hidden" name="classroom_id" value="' . esc_attr($classroom->classroom_id) . '" />';
                echo '<input type="hidden" name="cohort_id" value="' . esc_attr($selected_cohort) . '" />';

                echo '<table class="form-table">';

                // Teacher dropdown
                echo '<tr>';
                echo '<th scope="row"><label for="enrollment_id">' . esc_html__('Teacher', 'hl-core') . '</label></th>';
                echo '<td><select id="enrollment_id" name="enrollment_id" required>';
                echo '<option value="">' . esc_html__('-- Select Teacher --', 'hl-core') . '</option>';
                foreach ($available as $t) {
                    echo '<option value="' . esc_attr($t->enrollment_id) . '">' . esc_html($t->display_name . ' (' . $t->user_email . ')') . '</option>';
                }
                echo '</select></td>';
                echo '</tr>';

                // Lead teacher
                echo '<tr>';
                echo '<th scope="row">' . esc_html__('Lead Teacher', 'hl-core') . '</th>';
                echo '<td><label><input type="checkbox" name="is_lead_teacher" value="1" /> ' . esc_html__('Yes', 'hl-core') . '</label></td>';
                echo '</tr>';

                // Dates
                echo '<tr>';
                echo '<th scope="row"><label for="effective_start_date">' . esc_html__('Start Date', 'hl-core') . '</label></th>';
                echo '<td><input type="date" id="effective_start_date" name="effective_start_date" /></td>';
                echo '</tr>';

                echo '<tr>';
                echo '<th scope="row"><label for="effective_end_date">' . esc_html__('End Date', 'hl-core') . '</label></th>';
                echo '<td><input type="date" id="effective_end_date" name="effective_end_date" /></td>';
                echo '</tr>';

                echo '</table>';
                submit_button(__('Add Assignment', 'hl-core'), 'primary');
                echo '</form>';
            } elseif (empty($all_enrollments)) {
                echo '<p>' . esc_html__('No active enrollments found for this cohort and center.', 'hl-core') . '</p>';
            } else {
                echo '<p>' . esc_html__('All available teachers are already assigned.', 'hl-core') . '</p>';
            }
        } else {
            echo '<p class="description">' . esc_html__('Select a cohort above to add teaching assignments.', 'hl-core') . '</p>';
        }
    }

    // =========================================================================
    // Children Roster Section
    // =========================================================================

    /**
     * Handle child assign/unassign
     */
    private function handle_child_actions() {
        // Handle assign/reassign (POST)
        if (isset($_POST['hl_child_assignment_nonce'])) {
            if (!wp_verify_nonce($_POST['hl_child_assignment_nonce'], 'hl_save_child_assignment')) {
                wp_die(__('Security check failed.', 'hl-core'));
            }
            if (!current_user_can('manage_hl_core')) {
                wp_die(__('You do not have permission to perform this action.', 'hl-core'));
            }

            $service = new HL_Classroom_Service();
            $result = $service->assign_child_to_classroom(
                absint($_POST['child_id']),
                absint($_POST['classroom_id']),
                sanitize_text_field(isset($_POST['reason']) ? $_POST['reason'] : '')
            );

            $classroom_id = absint($_POST['classroom_id']);
            $cohort_id    = isset($_GET['cohort_id']) ? absint($_GET['cohort_id']) : 0;
            $msg = is_wp_error($result) ? 'child_error' : 'child_assigned';

            wp_redirect(admin_url('admin.php?page=hl-classrooms&action=view&id=' . $classroom_id . '&cohort_id=' . $cohort_id . '&message=' . $msg));
            exit;
        }

        // Handle unassign (GET)
        if (isset($_GET['unassign_child'])) {
            $child_id = absint($_GET['unassign_child']);

            if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'hl_unassign_child_' . $child_id)) {
                wp_die(__('Security check failed.', 'hl-core'));
            }
            if (!current_user_can('manage_hl_core')) {
                wp_die(__('You do not have permission to perform this action.', 'hl-core'));
            }

            $service = new HL_Classroom_Service();
            $service->unassign_child_from_classroom($child_id, 'Removed via admin');

            $classroom_id = isset($_GET['id']) ? absint($_GET['id']) : 0;
            $cohort_id    = isset($_GET['cohort_id']) ? absint($_GET['cohort_id']) : 0;

            wp_redirect(admin_url('admin.php?page=hl-classrooms&action=view&id=' . $classroom_id . '&cohort_id=' . $cohort_id . '&message=child_unassigned'));
            exit;
        }
    }

    /**
     * Render children roster section within classroom detail
     */
    private function render_children_roster_section($classroom) {
        global $wpdb;

        $service = new HL_Classroom_Service();

        echo '<hr />';
        echo '<h2>' . esc_html__('Children Roster', 'hl-core') . '</h2>';

        // Current children in this classroom
        $children = $service->get_children_in_classroom($classroom->classroom_id);

        if (!empty($children)) {
            echo '<table class="widefat striped">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__('Display Code', 'hl-core') . '</th>';
            echo '<th>' . esc_html__('Name', 'hl-core') . '</th>';
            echo '<th>' . esc_html__('DOB', 'hl-core') . '</th>';
            echo '<th>' . esc_html__('Assigned At', 'hl-core') . '</th>';
            echo '<th>' . esc_html__('Actions', 'hl-core') . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($children as $child) {
                $cohort_id_param = isset($_GET['cohort_id']) ? absint($_GET['cohort_id']) : 0;
                $unassign_url = wp_nonce_url(
                    admin_url('admin.php?page=hl-classrooms&action=view&id=' . $classroom->classroom_id . '&cohort_id=' . $cohort_id_param . '&unassign_child=' . $child->child_id),
                    'hl_unassign_child_' . $child->child_id
                );

                echo '<tr>';
                echo '<td>' . esc_html($child->child_display_code ?: '-') . '</td>';
                echo '<td>' . esc_html(trim($child->first_name . ' ' . $child->last_name)) . '</td>';
                echo '<td>' . esc_html($child->dob ?: '-') . '</td>';
                echo '<td>' . esc_html($child->assigned_at) . '</td>';
                echo '<td><a href="' . esc_url($unassign_url) . '" class="button button-small button-link-delete" onclick="return confirm(\'' . esc_js(__('Remove this child from the classroom?', 'hl-core')) . '\');">' . esc_html__('Remove', 'hl-core') . '</a></td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        } else {
            echo '<p>' . esc_html__('No children assigned to this classroom.', 'hl-core') . '</p>';
        }

        // Assign child form — children from same center
        $center_children = $wpdb->get_results($wpdb->prepare(
            "SELECT c.child_id, c.first_name, c.last_name, c.child_display_code, cc.classroom_id AS current_classroom_id
             FROM {$wpdb->prefix}hl_child c
             LEFT JOIN {$wpdb->prefix}hl_child_classroom_current cc ON c.child_id = cc.child_id
             WHERE c.center_id = %d
             ORDER BY c.last_name ASC, c.first_name ASC",
            $classroom->center_id
        ));

        // Split into unassigned and in-other-classroom
        $unassigned = array();
        $in_other   = array();
        foreach ($center_children as $c) {
            if (empty($c->current_classroom_id)) {
                $unassigned[] = $c;
            } elseif ((int) $c->current_classroom_id !== (int) $classroom->classroom_id) {
                $in_other[] = $c;
            }
        }

        if (!empty($unassigned) || !empty($in_other)) {
            echo '<h3>' . esc_html__('Assign Child to Classroom', 'hl-core') . '</h3>';
            $cohort_id_param = isset($_GET['cohort_id']) ? absint($_GET['cohort_id']) : 0;
            $form_url = admin_url('admin.php?page=hl-classrooms&action=view&id=' . $classroom->classroom_id . '&cohort_id=' . $cohort_id_param);
            echo '<form method="post" action="' . esc_url($form_url) . '" style="margin-bottom:20px;">';
            wp_nonce_field('hl_save_child_assignment', 'hl_child_assignment_nonce');
            echo '<input type="hidden" name="classroom_id" value="' . esc_attr($classroom->classroom_id) . '" />';

            echo '<select name="child_id" required style="min-width:300px;">';
            echo '<option value="">' . esc_html__('-- Select Child --', 'hl-core') . '</option>';

            if (!empty($unassigned)) {
                echo '<optgroup label="' . esc_attr__('Unassigned Children', 'hl-core') . '">';
                foreach ($unassigned as $c) {
                    $label = trim($c->last_name . ', ' . $c->first_name);
                    if ($c->child_display_code) {
                        $label .= ' (' . $c->child_display_code . ')';
                    }
                    echo '<option value="' . esc_attr($c->child_id) . '">' . esc_html($label) . '</option>';
                }
                echo '</optgroup>';
            }

            if (!empty($in_other)) {
                echo '<optgroup label="' . esc_attr__('Reassign from Other Classroom', 'hl-core') . '">';
                foreach ($in_other as $c) {
                    $label = trim($c->last_name . ', ' . $c->first_name);
                    if ($c->child_display_code) {
                        $label .= ' (' . $c->child_display_code . ')';
                    }
                    echo '<option value="' . esc_attr($c->child_id) . '">' . esc_html($label) . '</option>';
                }
                echo '</optgroup>';
            }

            echo '</select> ';
            echo '<input type="text" name="reason" placeholder="' . esc_attr__('Reason (optional)', 'hl-core') . '" class="regular-text" /> ';
            submit_button(__('Assign', 'hl-core'), 'primary', 'submit', false);
            echo '</form>';
        } else {
            echo '<p class="description">' . esc_html__('No children available to assign from this center.', 'hl-core') . '</p>';
        }
    }
}
