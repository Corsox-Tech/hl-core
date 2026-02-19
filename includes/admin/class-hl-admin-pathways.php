<?php if (!defined('ABSPATH')) exit;

/**
 * Admin Pathways & Activities Page
 *
 * Full CRUD admin page for managing Pathways and their Activities.
 *
 * @package HL_Core
 */
class HL_Admin_Pathways {

    /**
     * Singleton instance
     *
     * @var HL_Admin_Pathways|null
     */
    private static $instance = null;

    /**
     * Get singleton instance
     *
     * @return HL_Admin_Pathways
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
     * Main render entry point
     */
    public function render_page() {
        $this->handle_actions();

        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : 'list';

        echo '<div class="wrap">';

        switch ($action) {
            case 'new':
                $this->render_pathway_form();
                break;

            case 'edit':
                $pathway_id = isset($_GET['id']) ? absint($_GET['id']) : 0;
                $pathway    = $this->get_pathway($pathway_id);
                if ($pathway) {
                    $this->render_pathway_form($pathway);
                } else {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Pathway not found.', 'hl-core') . '</p></div>';
                    $this->render_list();
                }
                break;

            case 'delete':
                $this->handle_delete_pathway();
                $this->render_list();
                break;

            case 'view':
                $pathway_id = isset($_GET['id']) ? absint($_GET['id']) : 0;
                $pathway    = $this->get_pathway($pathway_id);
                if ($pathway) {
                    $this->render_pathway_detail($pathway);
                } else {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Pathway not found.', 'hl-core') . '</p></div>';
                    $this->render_list();
                }
                break;

            case 'new_activity':
                $pathway_id = isset($_GET['pathway_id']) ? absint($_GET['pathway_id']) : 0;
                $pathway    = $this->get_pathway($pathway_id);
                if ($pathway) {
                    $this->render_activity_form($pathway);
                } else {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Pathway not found.', 'hl-core') . '</p></div>';
                    $this->render_list();
                }
                break;

            case 'edit_activity':
                $activity_id = isset($_GET['activity_id']) ? absint($_GET['activity_id']) : 0;
                $activity    = $this->get_activity($activity_id);
                if ($activity) {
                    $pathway = $this->get_pathway($activity->pathway_id);
                    $this->render_activity_form($pathway, $activity);
                } else {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Activity not found.', 'hl-core') . '</p></div>';
                    $this->render_list();
                }
                break;

            case 'delete_activity':
                $this->handle_delete_activity();
                break;

            default:
                $this->render_list();
                break;
        }

        echo '</div>';
    }

    /**
     * Get pathway by ID
     *
     * @param int $pathway_id
     * @return object|null
     */
    private function get_pathway($pathway_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_pathway WHERE pathway_id = %d",
            $pathway_id
        ));
    }

    /**
     * Get activity by ID
     *
     * @param int $activity_id
     * @return object|null
     */
    private function get_activity($activity_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_activity WHERE activity_id = %d",
            $activity_id
        ));
    }

    /**
     * Handle form submissions
     */
    private function handle_actions() {
        // Handle pathway save
        if (isset($_POST['hl_pathway_nonce']) && wp_verify_nonce($_POST['hl_pathway_nonce'], 'hl_save_pathway')) {
            if (!current_user_can('manage_hl_core')) {
                wp_die(__('You do not have permission to perform this action.', 'hl-core'));
            }
            $this->save_pathway();
            return;
        }

        // Handle activity save
        if (isset($_POST['hl_activity_nonce']) && wp_verify_nonce($_POST['hl_activity_nonce'], 'hl_save_activity')) {
            if (!current_user_can('manage_hl_core')) {
                wp_die(__('You do not have permission to perform this action.', 'hl-core'));
            }
            $this->save_activity();
            return;
        }
    }

    /**
     * Save pathway data
     */
    private function save_pathway() {
        global $wpdb;

        $pathway_id = isset($_POST['pathway_id']) ? absint($_POST['pathway_id']) : 0;

        // Process target_roles
        $target_roles = array();
        if (!empty($_POST['target_roles']) && is_array($_POST['target_roles'])) {
            foreach ($_POST['target_roles'] as $role) {
                $target_roles[] = sanitize_text_field($role);
            }
        }

        // Sanitize new pathway fields
        $description     = isset($_POST['description']) ? wp_kses_post($_POST['description']) : '';
        $objectives      = isset($_POST['objectives']) ? wp_kses_post($_POST['objectives']) : '';
        $syllabus_url    = isset($_POST['syllabus_url']) ? esc_url_raw($_POST['syllabus_url']) : '';
        $featured_img_id = isset($_POST['featured_image_id']) ? absint($_POST['featured_image_id']) : 0;
        $avg_time        = isset($_POST['avg_completion_time']) ? sanitize_text_field($_POST['avg_completion_time']) : '';
        $expiration_raw  = isset($_POST['expiration_date']) ? sanitize_text_field($_POST['expiration_date']) : '';
        $expiration_date = !empty($expiration_raw) ? $expiration_raw : null;

        $data = array(
            'pathway_name'        => sanitize_text_field($_POST['pathway_name']),
            'pathway_code'        => sanitize_text_field($_POST['pathway_code']),
            'cohort_id'           => absint($_POST['cohort_id']),
            'target_roles'        => wp_json_encode($target_roles),
            'description'         => $description,
            'objectives'          => $objectives,
            'syllabus_url'        => $syllabus_url,
            'featured_image_id'   => $featured_img_id ? $featured_img_id : null,
            'avg_completion_time' => $avg_time,
            'expiration_date'     => $expiration_date,
        );

        if (empty($data['pathway_code'])) {
            $data['pathway_code'] = HL_Normalization::generate_code($data['pathway_name']);
        }

        if ($pathway_id > 0) {
            $wpdb->update($wpdb->prefix . 'hl_pathway', $data, array('pathway_id' => $pathway_id));
            $redirect = admin_url('admin.php?page=hl-pathways&action=view&id=' . $pathway_id . '&message=updated');
        } else {
            $data['pathway_uuid'] = HL_DB_Utils::generate_uuid();
            $wpdb->insert($wpdb->prefix . 'hl_pathway', $data);
            $new_id   = $wpdb->insert_id;
            $redirect = admin_url('admin.php?page=hl-pathways&action=view&id=' . $new_id . '&message=created');
        }

        wp_redirect($redirect);
        exit;
    }

    /**
     * Save activity data
     */
    private function save_activity() {
        global $wpdb;

        $activity_id   = isset($_POST['activity_id']) ? absint($_POST['activity_id']) : 0;
        $pathway_id    = absint($_POST['pathway_id']);
        $activity_type = sanitize_text_field($_POST['activity_type']);

        // Build external_ref JSON from type-specific dropdowns
        $external_ref = $this->build_external_ref($activity_type);

        // Get cohort_id from the pathway
        $cohort_id = $wpdb->get_var($wpdb->prepare(
            "SELECT cohort_id FROM {$wpdb->prefix}hl_pathway WHERE pathway_id = %d",
            $pathway_id
        ));

        $data = array(
            'pathway_id'    => $pathway_id,
            'cohort_id'     => absint($cohort_id),
            'activity_type' => $activity_type,
            'title'         => sanitize_text_field($_POST['title']),
            'description'   => sanitize_textarea_field($_POST['description']),
            'weight'        => floatval($_POST['weight']),
            'ordering_hint' => intval($_POST['ordering_hint']),
            'external_ref'  => $external_ref,
        );

        if ($activity_id > 0) {
            $wpdb->update($wpdb->prefix . 'hl_activity', $data, array('activity_id' => $activity_id));
            $target_activity_id = $activity_id;
        } else {
            $data['activity_uuid'] = HL_DB_Utils::generate_uuid();
            $wpdb->insert($wpdb->prefix . 'hl_activity', $data);
            $target_activity_id = $wpdb->insert_id;
        }

        // Save prerequisite groups (only when editing — new activities redirect first, then edit to add prereqs).
        if ($activity_id > 0 && isset($_POST['prereq_groups']) && is_array($_POST['prereq_groups'])) {
            $prereq_groups = $_POST['prereq_groups'];

            // Collect all proposed prereq activity IDs for cycle detection.
            $all_proposed_ids = array();
            foreach ($prereq_groups as $grp) {
                if (!empty($grp['activity_ids']) && is_array($grp['activity_ids'])) {
                    foreach ($grp['activity_ids'] as $aid) {
                        $all_proposed_ids[] = absint($aid);
                    }
                }
            }

            // Cycle detection.
            $rules_engine = new HL_Rules_Engine_Service();
            $cycle_check  = $rules_engine->validate_no_cycles($pathway_id, $target_activity_id, $all_proposed_ids);

            if (!$cycle_check['valid']) {
                // Cycle detected — store error and redirect back.
                $cycle_names = $this->resolve_activity_names($cycle_check['cycle']);
                $msg = sprintf(
                    __('Circular dependency detected: %s. Prerequisites were not saved.', 'hl-core'),
                    implode(' -> ', $cycle_names)
                );
                set_transient('hl_prereq_cycle_error_' . $target_activity_id, $msg, 60);

                $redirect = admin_url('admin.php?page=hl-pathways&action=edit_activity&activity_id=' . $target_activity_id . '&prereq_error=cycle');
                wp_redirect($redirect);
                exit;
            }

            // Valid — delete old groups/items and insert new ones.
            $old_group_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT group_id FROM {$wpdb->prefix}hl_activity_prereq_group WHERE activity_id = %d",
                $target_activity_id
            ));
            if (!empty($old_group_ids)) {
                $in_ids = implode(',', array_map('intval', $old_group_ids));
                $wpdb->query("DELETE FROM {$wpdb->prefix}hl_activity_prereq_item WHERE group_id IN ({$in_ids})");
                $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$wpdb->prefix}hl_activity_prereq_group WHERE activity_id = %d",
                    $target_activity_id
                ));
            }

            foreach ($prereq_groups as $grp) {
                $grp_type    = isset($grp['prereq_type']) ? sanitize_text_field($grp['prereq_type']) : 'all_of';
                $grp_n       = ($grp_type === 'n_of_m' && isset($grp['n_required'])) ? absint($grp['n_required']) : null;
                $grp_act_ids = (!empty($grp['activity_ids']) && is_array($grp['activity_ids'])) ? $grp['activity_ids'] : array();

                if (empty($grp_act_ids)) {
                    continue;
                }

                $wpdb->insert($wpdb->prefix . 'hl_activity_prereq_group', array(
                    'activity_id' => $target_activity_id,
                    'prereq_type' => $grp_type,
                    'n_required'  => $grp_n,
                ));
                $new_group_id = $wpdb->insert_id;

                foreach ($grp_act_ids as $prereq_aid) {
                    $wpdb->insert($wpdb->prefix . 'hl_activity_prereq_item', array(
                        'group_id'                 => $new_group_id,
                        'prerequisite_activity_id' => absint($prereq_aid),
                    ));
                }
            }

            // Audit log.
            if (class_exists('HL_Audit_Service')) {
                HL_Audit_Service::log(
                    'prereq_updated',
                    get_current_user_id(),
                    absint($data['cohort_id']),
                    null,
                    $target_activity_id,
                    sprintf('Prerequisites updated for activity %d', $target_activity_id)
                );
            }
        } elseif ($activity_id > 0 && !isset($_POST['prereq_groups'])) {
            // No prereq_groups key at all means the section was rendered but all groups removed.
            $old_group_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT group_id FROM {$wpdb->prefix}hl_activity_prereq_group WHERE activity_id = %d",
                $target_activity_id
            ));
            if (!empty($old_group_ids)) {
                $in_ids = implode(',', array_map('intval', $old_group_ids));
                $wpdb->query("DELETE FROM {$wpdb->prefix}hl_activity_prereq_item WHERE group_id IN ({$in_ids})");
                $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$wpdb->prefix}hl_activity_prereq_group WHERE activity_id = %d",
                    $target_activity_id
                ));
            }
        }

        $redirect = admin_url('admin.php?page=hl-pathways&action=view&id=' . $pathway_id . '&message=activity_saved');
        wp_redirect($redirect);
        exit;
    }

    /**
     * Build external_ref JSON from POST data based on activity type
     *
     * @param string $activity_type
     * @return string|null JSON string or null
     */
    private function build_external_ref($activity_type) {
        $ref = array();

        switch ($activity_type) {
            case 'teacher_self_assessment':
                $form_id = isset($_POST['jfb_form_id']) ? absint($_POST['jfb_form_id']) : 0;
                $phase   = isset($_POST['assessment_phase']) ? sanitize_text_field($_POST['assessment_phase']) : 'pre';
                if ($form_id) {
                    $ref = array(
                        'form_plugin' => 'jetformbuilder',
                        'form_id'     => $form_id,
                        'phase'       => $phase,
                    );
                }
                break;

            case 'observation':
                $form_id = isset($_POST['jfb_form_id']) ? absint($_POST['jfb_form_id']) : 0;
                if ($form_id) {
                    $ref = array(
                        'form_plugin' => 'jetformbuilder',
                        'form_id'     => $form_id,
                    );
                }
                if (!empty($_POST['required_count'])) {
                    $ref['required_count'] = absint($_POST['required_count']);
                }
                break;

            case 'children_assessment':
                $instrument_id = isset($_POST['instrument_id']) ? absint($_POST['instrument_id']) : 0;
                if ($instrument_id) {
                    $ref = array('instrument_id' => $instrument_id);
                }
                break;

            case 'learndash_course':
                $course_id = isset($_POST['ld_course_id']) ? absint($_POST['ld_course_id']) : 0;
                if ($course_id) {
                    $ref = array('course_id' => $course_id);
                }
                break;

            case 'coaching_session_attendance':
                // No external ref needed
                break;
        }

        return !empty($ref) ? wp_json_encode($ref) : null;
    }

    /**
     * Handle pathway delete
     */
    private function handle_delete_pathway() {
        $pathway_id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        if (!$pathway_id) {
            return;
        }

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'hl_delete_pathway_' . $pathway_id)) {
            wp_die(__('Security check failed.', 'hl-core'));
        }

        if (!current_user_can('manage_hl_core')) {
            wp_die(__('You do not have permission to perform this action.', 'hl-core'));
        }

        global $wpdb;
        // Delete activities first
        $wpdb->delete($wpdb->prefix . 'hl_activity', array('pathway_id' => $pathway_id));
        $wpdb->delete($wpdb->prefix . 'hl_pathway', array('pathway_id' => $pathway_id));

        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Pathway and its activities deleted successfully.', 'hl-core') . '</p></div>';
    }

    /**
     * Handle activity delete
     */
    private function handle_delete_activity() {
        $activity_id = isset($_GET['activity_id']) ? absint($_GET['activity_id']) : 0;
        $pathway_id  = isset($_GET['pathway_id']) ? absint($_GET['pathway_id']) : 0;

        if (!$activity_id || !$pathway_id) {
            $this->render_list();
            return;
        }

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'hl_delete_activity_' . $activity_id)) {
            wp_die(__('Security check failed.', 'hl-core'));
        }

        if (!current_user_can('manage_hl_core')) {
            wp_die(__('You do not have permission to perform this action.', 'hl-core'));
        }

        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'hl_activity', array('activity_id' => $activity_id));

        wp_redirect(admin_url('admin.php?page=hl-pathways&action=view&id=' . $pathway_id . '&message=activity_deleted'));
        exit;
    }

    /**
     * Render the pathways list
     */
    private function render_list() {
        global $wpdb;

        $filter_cohort = isset($_GET['cohort_id']) ? absint($_GET['cohort_id']) : 0;

        $where = '';
        if ($filter_cohort) {
            $where = $wpdb->prepare(' WHERE pw.cohort_id = %d', $filter_cohort);
        }

        $pathways = $wpdb->get_results(
            "SELECT pw.*, p.cohort_name,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}hl_activity a WHERE a.pathway_id = pw.pathway_id) as activity_count
             FROM {$wpdb->prefix}hl_pathway pw
             LEFT JOIN {$wpdb->prefix}hl_cohort p ON pw.cohort_id = p.cohort_id
             {$where}
             ORDER BY pw.pathway_name ASC"
        );

        // Cohorts for filter
        $cohorts = $wpdb->get_results(
            "SELECT cohort_id, cohort_name FROM {$wpdb->prefix}hl_cohort ORDER BY cohort_name ASC"
        );

        // Messages
        if (isset($_GET['message'])) {
            $msg = sanitize_text_field($_GET['message']);
            $messages = array(
                'created'          => __('Pathway created successfully.', 'hl-core'),
                'updated'          => __('Pathway updated successfully.', 'hl-core'),
                'activity_saved'   => __('Activity saved successfully.', 'hl-core'),
                'activity_deleted' => __('Activity deleted successfully.', 'hl-core'),
            );
            if (isset($messages[$msg])) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($messages[$msg]) . '</p></div>';
            }
        }

        echo '<h1 class="wp-heading-inline">' . esc_html__('Pathways & Activities', 'hl-core') . '</h1>';
        echo ' <a href="' . esc_url(admin_url('admin.php?page=hl-pathways&action=new')) . '" class="page-title-action">' . esc_html__('Add Pathway', 'hl-core') . '</a>';
        echo '<hr class="wp-header-end">';

        // Filter
        echo '<form method="get" style="margin-bottom:15px;">';
        echo '<input type="hidden" name="page" value="hl-pathways" />';
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

        if (empty($pathways)) {
            echo '<p>' . esc_html__('No pathways found.', 'hl-core') . '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('ID', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Name', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Code', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Cohort', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Target Roles', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Activities', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Actions', 'hl-core') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($pathways as $pw) {
            $view_url   = admin_url('admin.php?page=hl-pathways&action=view&id=' . $pw->pathway_id);
            $edit_url   = admin_url('admin.php?page=hl-pathways&action=edit&id=' . $pw->pathway_id);
            $delete_url = wp_nonce_url(
                admin_url('admin.php?page=hl-pathways&action=delete&id=' . $pw->pathway_id),
                'hl_delete_pathway_' . $pw->pathway_id
            );

            $roles_arr = json_decode($pw->target_roles, true);
            $roles_display = is_array($roles_arr) ? implode(', ', $roles_arr) : '';

            echo '<tr>';
            echo '<td>' . esc_html($pw->pathway_id) . '</td>';
            echo '<td><strong><a href="' . esc_url($view_url) . '">' . esc_html($pw->pathway_name) . '</a></strong></td>';
            echo '<td><code>' . esc_html($pw->pathway_code) . '</code></td>';
            echo '<td>' . esc_html($pw->cohort_name) . '</td>';
            echo '<td>' . esc_html($roles_display) . '</td>';
            echo '<td>' . esc_html($pw->activity_count) . '</td>';
            echo '<td>';
            echo '<a href="' . esc_url($view_url) . '" class="button button-small">' . esc_html__('View', 'hl-core') . '</a> ';
            echo '<a href="' . esc_url($edit_url) . '" class="button button-small">' . esc_html__('Edit', 'hl-core') . '</a> ';
            echo '<a href="' . esc_url($delete_url) . '" class="button button-small button-link-delete" onclick="return confirm(\'' . esc_js(__('Delete this pathway and all its activities?', 'hl-core')) . '\');">' . esc_html__('Delete', 'hl-core') . '</a>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * Render pathway detail with activities list
     *
     * @param object $pathway
     */
    private function render_pathway_detail($pathway) {
        global $wpdb;

        $activities = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_activity WHERE pathway_id = %d ORDER BY ordering_hint ASC, activity_id ASC",
            $pathway->pathway_id
        ));

        $cohort = $wpdb->get_row($wpdb->prepare(
            "SELECT cohort_name FROM {$wpdb->prefix}hl_cohort WHERE cohort_id = %d",
            $pathway->cohort_id
        ));

        $roles_arr = json_decode($pathway->target_roles, true);
        $roles_display = is_array($roles_arr) ? implode(', ', $roles_arr) : '';

        // Messages
        if (isset($_GET['message'])) {
            $msg = sanitize_text_field($_GET['message']);
            $messages = array(
                'created'          => __('Pathway created successfully.', 'hl-core'),
                'updated'          => __('Pathway updated successfully.', 'hl-core'),
                'activity_saved'   => __('Activity saved successfully.', 'hl-core'),
                'activity_deleted' => __('Activity deleted successfully.', 'hl-core'),
            );
            if (isset($messages[$msg])) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($messages[$msg]) . '</p></div>';
            }
        }

        echo '<h1>' . esc_html($pathway->pathway_name) . '</h1>';
        echo '<a href="' . esc_url(admin_url('admin.php?page=hl-pathways')) . '">&larr; ' . esc_html__('Back to Pathways', 'hl-core') . '</a>';

        echo '<table class="form-table">';
        echo '<tr><th>' . esc_html__('Code', 'hl-core') . '</th><td><code>' . esc_html($pathway->pathway_code) . '</code></td></tr>';
        echo '<tr><th>' . esc_html__('Cohort', 'hl-core') . '</th><td>' . esc_html($cohort ? $cohort->cohort_name : 'N/A') . '</td></tr>';
        echo '<tr><th>' . esc_html__('Target Roles', 'hl-core') . '</th><td>' . esc_html($roles_display) . '</td></tr>';

        if (!empty($pathway->description)) {
            echo '<tr><th>' . esc_html__('Description', 'hl-core') . '</th><td>' . wp_kses_post($pathway->description) . '</td></tr>';
        }
        if (!empty($pathway->objectives)) {
            echo '<tr><th>' . esc_html__('Objectives', 'hl-core') . '</th><td>' . wp_kses_post($pathway->objectives) . '</td></tr>';
        }
        if (!empty($pathway->syllabus_url)) {
            echo '<tr><th>' . esc_html__('Syllabus URL', 'hl-core') . '</th><td><a href="' . esc_url($pathway->syllabus_url) . '" target="_blank">' . esc_html($pathway->syllabus_url) . '</a></td></tr>';
        }
        if (!empty($pathway->featured_image_id)) {
            echo '<tr><th>' . esc_html__('Featured Image', 'hl-core') . '</th><td>' . wp_get_attachment_image(absint($pathway->featured_image_id), 'medium') . '</td></tr>';
        }
        if (!empty($pathway->avg_completion_time)) {
            echo '<tr><th>' . esc_html__('Avg Completion Time', 'hl-core') . '</th><td>' . esc_html($pathway->avg_completion_time) . '</td></tr>';
        }
        if (!empty($pathway->expiration_date)) {
            echo '<tr><th>' . esc_html__('Expiration Date', 'hl-core') . '</th><td>' . esc_html(date_i18n(get_option('date_format', 'M j, Y'), strtotime($pathway->expiration_date))) . '</td></tr>';
        }

        echo '</table>';

        // Activities section
        echo '<h2 class="wp-heading-inline">' . esc_html__('Activities', 'hl-core') . '</h2>';
        echo ' <a href="' . esc_url(admin_url('admin.php?page=hl-pathways&action=new_activity&pathway_id=' . $pathway->pathway_id)) . '" class="page-title-action">' . esc_html__('Add Activity', 'hl-core') . '</a>';
        echo '<hr class="wp-header-end">';

        if (empty($activities)) {
            echo '<p>' . esc_html__('No activities in this pathway yet.', 'hl-core') . '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Order', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Title', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Type', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Linked Resource', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Prerequisites', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Weight', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Actions', 'hl-core') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($activities as $act) {
            $edit_url   = admin_url('admin.php?page=hl-pathways&action=edit_activity&activity_id=' . $act->activity_id);
            $delete_url = wp_nonce_url(
                admin_url('admin.php?page=hl-pathways&action=delete_activity&activity_id=' . $act->activity_id . '&pathway_id=' . $pathway->pathway_id),
                'hl_delete_activity_' . $act->activity_id
            );

            // Format type label
            $type_labels = array(
                'learndash_course'           => __('LearnDash Course', 'hl-core'),
                'teacher_self_assessment'    => __('Self-Assessment (JFB)', 'hl-core'),
                'children_assessment'        => __('Children Assessment', 'hl-core'),
                'coaching_session_attendance' => __('Coaching Attendance', 'hl-core'),
                'observation'                => __('Observation (JFB)', 'hl-core'),
            );
            $type_display = isset($type_labels[$act->activity_type]) ? $type_labels[$act->activity_type] : $act->activity_type;

            // Format linked resource
            $linked_display = $this->format_external_ref($act->activity_type, $act->external_ref);

            echo '<tr>';
            echo '<td>' . esc_html($act->ordering_hint) . '</td>';
            echo '<td><strong>' . esc_html($act->title) . '</strong></td>';
            echo '<td>' . esc_html($type_display) . '</td>';
            echo '<td>' . $linked_display . '</td>';
            echo '<td>' . $this->format_prereq_summary($act->activity_id) . '</td>';
            echo '<td>' . esc_html($act->weight) . '</td>';
            echo '<td>';
            echo '<a href="' . esc_url($edit_url) . '" class="button button-small">' . esc_html__('Edit', 'hl-core') . '</a> ';
            echo '<a href="' . esc_url($delete_url) . '" class="button button-small button-link-delete" onclick="return confirm(\'' . esc_js(__('Delete this activity?', 'hl-core')) . '\');">' . esc_html__('Delete', 'hl-core') . '</a>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * Render the pathway create/edit form
     *
     * @param object|null $pathway
     */
    private function render_pathway_form($pathway = null) {
        $is_edit = ($pathway !== null);
        $title   = $is_edit ? __('Edit Pathway', 'hl-core') : __('Add New Pathway', 'hl-core');

        global $wpdb;
        $cohorts = $wpdb->get_results(
            "SELECT cohort_id, cohort_name FROM {$wpdb->prefix}hl_cohort ORDER BY cohort_name ASC"
        );

        $current_roles = array();
        if ($is_edit && !empty($pathway->target_roles)) {
            $decoded = json_decode($pathway->target_roles, true);
            if (is_array($decoded)) {
                $current_roles = $decoded;
            }
        }

        echo '<h1>' . esc_html($title) . '</h1>';
        echo '<a href="' . esc_url(admin_url('admin.php?page=hl-pathways')) . '">&larr; ' . esc_html__('Back to Pathways', 'hl-core') . '</a>';

        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=hl-pathways')) . '">';
        wp_nonce_field('hl_save_pathway', 'hl_pathway_nonce');

        if ($is_edit) {
            echo '<input type="hidden" name="pathway_id" value="' . esc_attr($pathway->pathway_id) . '" />';
        }

        echo '<table class="form-table">';

        // Pathway Name
        echo '<tr>';
        echo '<th scope="row"><label for="pathway_name">' . esc_html__('Pathway Name', 'hl-core') . '</label></th>';
        echo '<td><input type="text" id="pathway_name" name="pathway_name" value="' . esc_attr($is_edit ? $pathway->pathway_name : '') . '" class="regular-text" required /></td>';
        echo '</tr>';

        // Code
        echo '<tr>';
        echo '<th scope="row"><label for="pathway_code">' . esc_html__('Code', 'hl-core') . '</label></th>';
        echo '<td><input type="text" id="pathway_code" name="pathway_code" value="' . esc_attr($is_edit ? $pathway->pathway_code : '') . '" class="regular-text" />';
        echo '<p class="description">' . esc_html__('Leave blank to auto-generate.', 'hl-core') . '</p></td>';
        echo '</tr>';

        // Cohort
        $current_cohort = $is_edit ? $pathway->cohort_id : '';
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

        // Target Roles
        $available_roles = array('Teacher', 'Mentor', 'Center Leader', 'District Leader');
        echo '<tr>';
        echo '<th scope="row">' . esc_html__('Target Roles', 'hl-core') . '</th>';
        echo '<td><fieldset>';
        foreach ($available_roles as $role) {
            $checked = in_array($role, $current_roles) ? ' checked="checked"' : '';
            echo '<label><input type="checkbox" name="target_roles[]" value="' . esc_attr($role) . '"' . $checked . ' /> ' . esc_html($role) . '</label><br />';
        }
        echo '</fieldset></td>';
        echo '</tr>';

        // Description (rich text)
        echo '<tr>';
        echo '<th scope="row"><label for="pathway_description">' . esc_html__('Description', 'hl-core') . '</label></th>';
        echo '<td>';
        wp_editor(
            $is_edit ? ($pathway->description ?? '') : '',
            'pathway_description',
            array(
                'textarea_name' => 'description',
                'textarea_rows' => 6,
                'media_buttons' => false,
                'teeny'         => true,
            )
        );
        echo '</td>';
        echo '</tr>';

        // Objectives (rich text)
        echo '<tr>';
        echo '<th scope="row"><label for="pathway_objectives">' . esc_html__('Objectives', 'hl-core') . '</label></th>';
        echo '<td>';
        wp_editor(
            $is_edit ? ($pathway->objectives ?? '') : '',
            'pathway_objectives',
            array(
                'textarea_name' => 'objectives',
                'textarea_rows' => 6,
                'media_buttons' => false,
                'teeny'         => true,
            )
        );
        echo '</td>';
        echo '</tr>';

        // Syllabus URL
        echo '<tr>';
        echo '<th scope="row"><label for="syllabus_url">' . esc_html__('Syllabus URL', 'hl-core') . '</label></th>';
        echo '<td><input type="url" id="syllabus_url" name="syllabus_url" value="' . esc_attr($is_edit ? ($pathway->syllabus_url ?? '') : '') . '" class="regular-text" />';
        echo '<p class="description">' . esc_html__('Link to an external syllabus document (PDF, etc.).', 'hl-core') . '</p></td>';
        echo '</tr>';

        // Featured Image (WP Media uploader)
        $featured_image_id = $is_edit ? absint($pathway->featured_image_id ?? 0) : 0;
        $image_preview = $featured_image_id ? wp_get_attachment_image($featured_image_id, 'medium') : '';

        wp_enqueue_media();

        echo '<tr>';
        echo '<th scope="row"><label>' . esc_html__('Featured Image', 'hl-core') . '</label></th>';
        echo '<td>';
        echo '<div id="hl-featured-image-preview" style="margin-bottom:10px;">' . $image_preview . '</div>';
        echo '<input type="hidden" id="featured_image_id" name="featured_image_id" value="' . esc_attr($featured_image_id) . '" />';
        echo '<button type="button" id="hl-select-image" class="button">' . esc_html__('Select Image', 'hl-core') . '</button> ';
        echo '<button type="button" id="hl-remove-image" class="button"' . ($featured_image_id ? '' : ' style="display:none;"') . '>' . esc_html__('Remove Image', 'hl-core') . '</button>';
        echo '</td>';
        echo '</tr>';

        // Avg Completion Time
        echo '<tr>';
        echo '<th scope="row"><label for="avg_completion_time">' . esc_html__('Avg Completion Time', 'hl-core') . '</label></th>';
        echo '<td><input type="text" id="avg_completion_time" name="avg_completion_time" value="' . esc_attr($is_edit ? ($pathway->avg_completion_time ?? '') : '') . '" class="regular-text" />';
        echo '<p class="description">' . esc_html__('e.g., "4 hours 30 minutes"', 'hl-core') . '</p></td>';
        echo '</tr>';

        // Expiration Date
        echo '<tr>';
        echo '<th scope="row"><label for="expiration_date">' . esc_html__('Expiration Date', 'hl-core') . '</label></th>';
        echo '<td><input type="date" id="expiration_date" name="expiration_date" value="' . esc_attr($is_edit ? ($pathway->expiration_date ?? '') : '') . '" />';
        echo '<p class="description">' . esc_html__('Optional. After this date the program is marked as expired.', 'hl-core') . '</p></td>';
        echo '</tr>';

        echo '</table>';
        submit_button($is_edit ? __('Update Pathway', 'hl-core') : __('Create Pathway', 'hl-core'));
        echo '</form>';

        // Media uploader JavaScript
        ?>
        <script type="text/javascript">
        (function($) {
            var frame;
            $('#hl-select-image').on('click', function(e) {
                e.preventDefault();
                if (frame) { frame.open(); return; }
                frame = wp.media({
                    title: '<?php echo esc_js(__('Select Featured Image', 'hl-core')); ?>',
                    button: { text: '<?php echo esc_js(__('Use this image', 'hl-core')); ?>' },
                    multiple: false
                });
                frame.on('select', function() {
                    var attachment = frame.state().get('selection').first().toJSON();
                    $('#featured_image_id').val(attachment.id);
                    var img = attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url;
                    $('#hl-featured-image-preview').html('<img src="' + img + '" style="max-width:300px;height:auto;" />');
                    $('#hl-remove-image').show();
                });
                frame.open();
            });
            $('#hl-remove-image').on('click', function(e) {
                e.preventDefault();
                $('#featured_image_id').val('0');
                $('#hl-featured-image-preview').html('');
                $(this).hide();
            });
        })(jQuery);
        </script>
        <?php
    }

    /**
     * Format external_ref for display in the activity list
     *
     * @param string      $activity_type
     * @param string|null $external_ref JSON string
     * @return string HTML
     */
    private function format_external_ref($activity_type, $external_ref) {
        if (empty($external_ref)) {
            return '<span style="color:#999;">' . esc_html__('Not configured', 'hl-core') . '</span>';
        }

        $ref = json_decode($external_ref, true);
        if (!is_array($ref)) {
            return '<span style="color:#999;">' . esc_html__('Invalid', 'hl-core') . '</span>';
        }

        switch ($activity_type) {
            case 'teacher_self_assessment':
            case 'observation':
                $form_id = isset($ref['form_id']) ? absint($ref['form_id']) : 0;
                $label = '';
                if ($form_id) {
                    $form_title = get_the_title($form_id);
                    $label = $form_title ? $form_title : sprintf(__('Form #%d', 'hl-core'), $form_id);
                }
                if ($activity_type === 'teacher_self_assessment' && isset($ref['phase'])) {
                    $label .= ' (' . esc_html(ucfirst($ref['phase'])) . ')';
                }
                if ($activity_type === 'observation' && !empty($ref['required_count'])) {
                    $label .= sprintf(' (%dx)', absint($ref['required_count']));
                }
                return esc_html($label);

            case 'children_assessment':
                $instrument_id = isset($ref['instrument_id']) ? absint($ref['instrument_id']) : 0;
                if ($instrument_id) {
                    global $wpdb;
                    $inst = $wpdb->get_row($wpdb->prepare(
                        "SELECT name, instrument_type, version FROM {$wpdb->prefix}hl_instrument WHERE instrument_id = %d",
                        $instrument_id
                    ));
                    if ($inst) {
                        return esc_html(sprintf('%s (v%s)', $inst->name, $inst->version));
                    }
                    return esc_html(sprintf(__('Instrument #%d', 'hl-core'), $instrument_id));
                }
                return '<span style="color:#999;">' . esc_html__('No instrument', 'hl-core') . '</span>';

            case 'learndash_course':
                $course_id = isset($ref['course_id']) ? absint($ref['course_id']) : 0;
                if ($course_id) {
                    $course_title = get_the_title($course_id);
                    return esc_html($course_title ? $course_title : sprintf(__('Course #%d', 'hl-core'), $course_id));
                }
                return '<span style="color:#999;">' . esc_html__('No course', 'hl-core') . '</span>';

            default:
                return '<span style="color:#999;">—</span>';
        }
    }

    /**
     * Render the activity create/edit form
     *
     * @param object      $pathway
     * @param object|null $activity
     */
    private function render_activity_form($pathway, $activity = null) {
        $is_edit = ($activity !== null);
        $title   = $is_edit ? __('Edit Activity', 'hl-core') : __('Add New Activity', 'hl-core');

        // Parse existing external_ref for pre-populating dropdowns
        $ext_ref = array();
        if ($is_edit && !empty($activity->external_ref)) {
            $decoded = json_decode($activity->external_ref, true);
            if (is_array($decoded)) {
                $ext_ref = $decoded;
            }
        }

        echo '<h1>' . esc_html($title) . '</h1>';
        echo '<a href="' . esc_url(admin_url('admin.php?page=hl-pathways&action=view&id=' . $pathway->pathway_id)) . '">&larr; ' . esc_html__('Back to Pathway: ', 'hl-core') . esc_html($pathway->pathway_name) . '</a>';

        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=hl-pathways')) . '">';
        wp_nonce_field('hl_save_activity', 'hl_activity_nonce');
        echo '<input type="hidden" name="pathway_id" value="' . esc_attr($pathway->pathway_id) . '" />';

        if ($is_edit) {
            echo '<input type="hidden" name="activity_id" value="' . esc_attr($activity->activity_id) . '" />';
        }

        echo '<table class="form-table">';

        // Activity Type (matches DB enum)
        $current_type = $is_edit ? $activity->activity_type : '';
        $activity_types = array(
            'learndash_course'             => __('LearnDash Course', 'hl-core'),
            'teacher_self_assessment'      => __('Teacher Self-Assessment (JFB)', 'hl-core'),
            'children_assessment'          => __('Children Assessment', 'hl-core'),
            'coaching_session_attendance'   => __('Coaching Session Attendance', 'hl-core'),
            'observation'                  => __('Observation (JFB)', 'hl-core'),
        );

        $jfb_active = HL_JFB_Integration::instance()->is_active();

        echo '<tr>';
        echo '<th scope="row"><label for="activity_type">' . esc_html__('Activity Type', 'hl-core') . '</label></th>';
        echo '<td><select id="activity_type" name="activity_type" required>';
        echo '<option value="">' . esc_html__('-- Select Type --', 'hl-core') . '</option>';
        foreach ($activity_types as $type_value => $type_label) {
            $disabled = '';
            // Disable JFB types if JFB is not active
            if (!$jfb_active && in_array($type_value, array('teacher_self_assessment', 'observation'), true)) {
                $disabled = ' disabled="disabled"';
                $type_label .= ' ' . __('(JFB not active)', 'hl-core');
            }
            echo '<option value="' . esc_attr($type_value) . '"' . selected($current_type, $type_value, false) . $disabled . '>' . esc_html($type_label) . '</option>';
        }
        echo '</select></td>';
        echo '</tr>';

        // Title
        echo '<tr>';
        echo '<th scope="row"><label for="title">' . esc_html__('Title', 'hl-core') . '</label></th>';
        echo '<td><input type="text" id="title" name="title" value="' . esc_attr($is_edit ? $activity->title : '') . '" class="regular-text" required /></td>';
        echo '</tr>';

        // Description
        echo '<tr>';
        echo '<th scope="row"><label for="description">' . esc_html__('Description', 'hl-core') . '</label></th>';
        echo '<td><textarea id="description" name="description" rows="5" class="large-text">' . esc_textarea($is_edit ? $activity->description : '') . '</textarea></td>';
        echo '</tr>';

        // Weight
        echo '<tr>';
        echo '<th scope="row"><label for="weight">' . esc_html__('Weight', 'hl-core') . '</label></th>';
        echo '<td><input type="number" id="weight" name="weight" value="' . esc_attr($is_edit ? $activity->weight : '1.0') . '" step="0.1" min="0" class="small-text" /></td>';
        echo '</tr>';

        // Ordering Hint
        echo '<tr>';
        echo '<th scope="row"><label for="ordering_hint">' . esc_html__('Ordering Hint', 'hl-core') . '</label></th>';
        echo '<td><input type="number" id="ordering_hint" name="ordering_hint" value="' . esc_attr($is_edit ? $activity->ordering_hint : '0') . '" class="small-text" />';
        echo '<p class="description">' . esc_html__('Lower values appear first.', 'hl-core') . '</p></td>';
        echo '</tr>';

        // =====================================================================
        // Conditional fields based on activity type
        // =====================================================================

        // --- JFB Form Dropdown (for teacher_self_assessment and observation) ---
        $jfb_forms = HL_JFB_Integration::instance()->get_available_forms();
        $current_form_id = isset($ext_ref['form_id']) ? absint($ext_ref['form_id']) : 0;

        echo '<tr class="hl-activity-field hl-field-jfb" style="display:none;">';
        echo '<th scope="row"><label for="jfb_form_id">' . esc_html__('JetFormBuilder Form', 'hl-core') . '</label></th>';
        echo '<td>';
        if (empty($jfb_forms)) {
            echo '<p class="description">' . esc_html__('No JetFormBuilder forms found. Create a form in JetFormBuilder first.', 'hl-core') . '</p>';
            echo '<input type="hidden" name="jfb_form_id" value="0" />';
        } else {
            echo '<select id="jfb_form_id" name="jfb_form_id">';
            echo '<option value="0">' . esc_html__('-- Select Form --', 'hl-core') . '</option>';
            foreach ($jfb_forms as $form) {
                echo '<option value="' . esc_attr($form['id']) . '"' . selected($current_form_id, $form['id'], false) . '>'
                    . esc_html($form['title']) . ' (ID: ' . esc_html($form['id']) . ')'
                    . '</option>';
            }
            echo '</select>';
            echo '<p class="description">' . esc_html__('The JFB form must have hidden fields (hl_enrollment_id, hl_activity_id, hl_cohort_id) and a "Call Hook" post-submit action with hook name: hl_core_form_submitted', 'hl-core') . '</p>';
        }
        echo '</td>';
        echo '</tr>';

        // --- Phase dropdown (for teacher_self_assessment only) ---
        $current_phase = isset($ext_ref['phase']) ? $ext_ref['phase'] : 'pre';

        echo '<tr class="hl-activity-field hl-field-phase" style="display:none;">';
        echo '<th scope="row"><label for="assessment_phase">' . esc_html__('Assessment Phase', 'hl-core') . '</label></th>';
        echo '<td><select id="assessment_phase" name="assessment_phase">';
        echo '<option value="pre"' . selected($current_phase, 'pre', false) . '>' . esc_html__('Pre-Assessment', 'hl-core') . '</option>';
        echo '<option value="post"' . selected($current_phase, 'post', false) . '>' . esc_html__('Post-Assessment', 'hl-core') . '</option>';
        echo '</select></td>';
        echo '</tr>';

        // --- Required count (for observation only) ---
        $current_required_count = isset($ext_ref['required_count']) ? absint($ext_ref['required_count']) : '';

        echo '<tr class="hl-activity-field hl-field-obs-count" style="display:none;">';
        echo '<th scope="row"><label for="required_count">' . esc_html__('Required Observation Count', 'hl-core') . '</label></th>';
        echo '<td><input type="number" id="required_count" name="required_count" value="' . esc_attr($current_required_count) . '" min="1" class="small-text" />';
        echo '<p class="description">' . esc_html__('Number of observations required for completion. Leave blank if not modeled as an activity.', 'hl-core') . '</p></td>';
        echo '</tr>';

        // --- Instrument dropdown (for children_assessment) ---
        global $wpdb;
        $instruments = $wpdb->get_results(
            "SELECT instrument_id, name, instrument_type, version
             FROM {$wpdb->prefix}hl_instrument
             WHERE instrument_type IN ('children_infant','children_toddler','children_preschool')
             ORDER BY name ASC"
        );
        $current_instrument_id = isset($ext_ref['instrument_id']) ? absint($ext_ref['instrument_id']) : 0;

        echo '<tr class="hl-activity-field hl-field-instrument" style="display:none;">';
        echo '<th scope="row"><label for="instrument_id">' . esc_html__('Children Assessment Instrument', 'hl-core') . '</label></th>';
        echo '<td>';
        if (empty($instruments)) {
            echo '<p class="description">' . esc_html__('No children assessment instruments found. Create one in the Instruments admin page first.', 'hl-core') . '</p>';
            echo '<input type="hidden" name="instrument_id" value="0" />';
        } else {
            echo '<select id="instrument_id" name="instrument_id">';
            echo '<option value="0">' . esc_html__('-- Select Instrument --', 'hl-core') . '</option>';
            foreach ($instruments as $inst) {
                $label = sprintf('%s (%s v%s)', $inst->name, str_replace('children_', '', $inst->instrument_type), $inst->version);
                echo '<option value="' . esc_attr($inst->instrument_id) . '"' . selected($current_instrument_id, $inst->instrument_id, false) . '>'
                    . esc_html($label) . '</option>';
            }
            echo '</select>';
        }
        echo '</td>';
        echo '</tr>';

        // --- LearnDash Course selector (for learndash_course) ---
        $ld_courses = array();
        if (post_type_exists('sfwd-courses')) {
            $ld_courses = get_posts(array(
                'post_type'      => 'sfwd-courses',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'orderby'        => 'title',
                'order'          => 'ASC',
            ));
        }
        $current_course_id = isset($ext_ref['course_id']) ? absint($ext_ref['course_id']) : 0;

        echo '<tr class="hl-activity-field hl-field-ld" style="display:none;">';
        echo '<th scope="row"><label for="ld_course_id">' . esc_html__('LearnDash Course', 'hl-core') . '</label></th>';
        echo '<td>';
        if (empty($ld_courses)) {
            echo '<input type="number" id="ld_course_id" name="ld_course_id" value="' . esc_attr($current_course_id) . '" class="small-text" />';
            echo '<p class="description">' . esc_html__('Enter the LearnDash course post ID. LearnDash is not active, so courses cannot be listed.', 'hl-core') . '</p>';
        } else {
            echo '<select id="ld_course_id" name="ld_course_id">';
            echo '<option value="0">' . esc_html__('-- Select Course --', 'hl-core') . '</option>';
            foreach ($ld_courses as $course) {
                echo '<option value="' . esc_attr($course->ID) . '"' . selected($current_course_id, $course->ID, false) . '>'
                    . esc_html($course->post_title) . ' (ID: ' . esc_html($course->ID) . ')'
                    . '</option>';
            }
            echo '</select>';
        }
        echo '</td>';
        echo '</tr>';

        echo '</table>';

        // =====================================================================
        // Prerequisites section (only for edit mode — activity must exist first)
        // =====================================================================
        if ($is_edit) {
            // Show cycle error if redirected back with one.
            if (isset($_GET['prereq_error']) && $_GET['prereq_error'] === 'cycle') {
                $cycle_msg = get_transient('hl_prereq_cycle_error_' . $activity->activity_id);
                delete_transient('hl_prereq_cycle_error_' . $activity->activity_id);
                echo '<div class="notice notice-error"><p><strong>' . esc_html__('Prerequisite Error:', 'hl-core') . '</strong> ';
                if ($cycle_msg) {
                    echo esc_html($cycle_msg);
                } else {
                    echo esc_html__('A circular dependency was detected. Prerequisites were not saved.', 'hl-core');
                }
                echo '</p></div>';
            }

            echo '<h2>' . esc_html__('Prerequisites', 'hl-core') . '</h2>';
            echo '<p class="description">' . esc_html__('Define which activities must be completed before this one becomes available. Each group is an independent requirement (AND across groups).', 'hl-core') . '</p>';

            // Load other activities in this pathway (exclude current).
            $other_activities = $wpdb->get_results($wpdb->prepare(
                "SELECT activity_id, title, ordering_hint FROM {$wpdb->prefix}hl_activity WHERE pathway_id = %d AND activity_id != %d ORDER BY ordering_hint ASC, activity_id ASC",
                $pathway->pathway_id,
                $activity->activity_id
            ));

            $existing_groups = $this->get_prereq_groups($activity->activity_id);

            echo '<div id="hl-prereq-groups">';
            if (!empty($existing_groups)) {
                foreach ($existing_groups as $idx => $grp) {
                    $this->render_prereq_group_row($idx, $grp, $other_activities);
                }
            }
            echo '</div>';

            echo '<button type="button" id="hl-add-prereq-group" class="button">' . esc_html__('+ Add Prerequisite Group', 'hl-core') . '</button>';

            // Hidden template for JS cloning.
            echo '<script type="text/template" id="hl-prereq-group-template">';
            ob_start();
            $this->render_prereq_group_row('__INDEX__', array(), $other_activities);
            echo ob_get_clean();
            echo '</script>';
        }

        submit_button($is_edit ? __('Update Activity', 'hl-core') : __('Create Activity', 'hl-core'));
        echo '</form>';

        // JavaScript to toggle conditional fields
        $this->render_activity_form_js($current_type);

        // JavaScript for prereq group management
        if ($is_edit) {
            $next_index = !empty($existing_groups) ? count($existing_groups) : 0;
            ?>
            <script type="text/javascript">
            (function($) {
                var nextIndex = <?php echo (int) $next_index; ?>;
                var template = document.getElementById('hl-prereq-group-template');

                // Add group
                $('#hl-add-prereq-group').on('click', function() {
                    var html = template.innerHTML.replace(/__INDEX__/g, nextIndex);
                    $('#hl-prereq-groups').append(html);
                    nextIndex++;
                    bindGroupEvents();
                });

                // Remove group
                function bindGroupEvents() {
                    $('.hl-remove-prereq-group').off('click').on('click', function() {
                        $(this).closest('.hl-prereq-group').remove();
                    });
                    // Toggle n_required visibility
                    $('.hl-prereq-type-select').off('change').on('change', function() {
                        var wrap = $(this).closest('.hl-prereq-group').find('.hl-n-required-wrap');
                        if ($(this).val() === 'n_of_m') {
                            wrap.show();
                        } else {
                            wrap.hide();
                        }
                    });
                }

                bindGroupEvents();
            })(jQuery);
            </script>
            <?php
        }
    }

    /**
     * Output JavaScript for toggling activity type conditional fields
     *
     * @param string $initial_type The current activity type (for initial state)
     */
    private function render_activity_form_js($initial_type = '') {
        ?>
        <script type="text/javascript">
        (function() {
            var typeSelect = document.getElementById('activity_type');
            if (!typeSelect) return;

            var fieldMap = {
                'teacher_self_assessment': ['hl-field-jfb', 'hl-field-phase'],
                'observation':             ['hl-field-jfb', 'hl-field-obs-count'],
                'children_assessment':     ['hl-field-instrument'],
                'learndash_course':        ['hl-field-ld'],
                'coaching_session_attendance': []
            };

            function toggleFields() {
                var type = typeSelect.value;

                // Hide all conditional fields
                var allFields = document.querySelectorAll('.hl-activity-field');
                for (var i = 0; i < allFields.length; i++) {
                    allFields[i].style.display = 'none';
                }

                // Show fields for selected type
                var show = fieldMap[type] || [];
                for (var j = 0; j < show.length; j++) {
                    var els = document.querySelectorAll('.' + show[j]);
                    for (var k = 0; k < els.length; k++) {
                        els[k].style.display = '';
                    }
                }
            }

            typeSelect.addEventListener('change', toggleFields);

            // Set initial state
            toggleFields();
        })();
        </script>
        <?php
    }

    /**
     * Get prerequisite groups with nested items for a given activity.
     *
     * @param int $activity_id
     * @return array Groups with items.
     */
    private function get_prereq_groups($activity_id) {
        global $wpdb;

        $groups = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_activity_prereq_group WHERE activity_id = %d ORDER BY group_id ASC",
            $activity_id
        ), ARRAY_A);

        if (empty($groups)) {
            return array();
        }

        foreach ($groups as &$group) {
            $group['items'] = $wpdb->get_col($wpdb->prepare(
                "SELECT prerequisite_activity_id FROM {$wpdb->prefix}hl_activity_prereq_item WHERE group_id = %d",
                $group['group_id']
            ));
        }
        unset($group);

        return $groups;
    }

    /**
     * Render a single prereq group row for the activity form.
     *
     * @param int    $index            Row index.
     * @param array  $group            Group data (or empty for template).
     * @param array  $other_activities Activities available as prereqs.
     */
    private function render_prereq_group_row($index, $group, $other_activities) {
        $prereq_type = isset($group['prereq_type']) ? $group['prereq_type'] : 'all_of';
        $n_required  = isset($group['n_required']) ? (int) $group['n_required'] : 2;
        $selected_ids = isset($group['items']) ? array_map('intval', $group['items']) : array();

        echo '<div class="hl-prereq-group" data-index="' . esc_attr($index) . '" style="border:1px solid #ccd0d4;padding:10px;margin-bottom:10px;background:#f9f9f9;">';

        // Type selector
        echo '<label><strong>' . esc_html__('Type:', 'hl-core') . '</strong> ';
        echo '<select name="prereq_groups[' . esc_attr($index) . '][prereq_type]" class="hl-prereq-type-select">';
        echo '<option value="all_of"' . selected($prereq_type, 'all_of', false) . '>' . esc_html__('All of', 'hl-core') . '</option>';
        echo '<option value="any_of"' . selected($prereq_type, 'any_of', false) . '>' . esc_html__('Any of', 'hl-core') . '</option>';
        echo '<option value="n_of_m"' . selected($prereq_type, 'n_of_m', false) . '>' . esc_html__('N of M', 'hl-core') . '</option>';
        echo '</select></label> ';

        // N required (visible only for n_of_m)
        $n_style = ($prereq_type === 'n_of_m') ? '' : 'display:none;';
        echo '<span class="hl-n-required-wrap" style="' . esc_attr($n_style) . '">';
        echo '<label>' . esc_html__('N required:', 'hl-core') . ' ';
        echo '<input type="number" name="prereq_groups[' . esc_attr($index) . '][n_required]" value="' . esc_attr($n_required) . '" min="1" class="small-text" />';
        echo '</label></span> ';

        // Remove button
        echo '<button type="button" class="button button-small hl-remove-prereq-group" style="float:right;color:#a00;">' . esc_html__('Remove Group', 'hl-core') . '</button>';

        // Activity multi-select
        echo '<div style="clear:both;margin-top:8px;">';
        echo '<label><strong>' . esc_html__('Activities:', 'hl-core') . '</strong></label><br/>';
        echo '<select name="prereq_groups[' . esc_attr($index) . '][activity_ids][]" multiple="multiple" style="min-width:300px;min-height:80px;">';
        foreach ($other_activities as $act) {
            $sel = in_array((int) $act->activity_id, $selected_ids, true) ? ' selected="selected"' : '';
            echo '<option value="' . esc_attr($act->activity_id) . '"' . $sel . '>'
                . esc_html($act->title) . ' (#' . esc_html($act->activity_id) . ')'
                . '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__('Hold Ctrl/Cmd to select multiple.', 'hl-core') . '</p>';
        echo '</div>';

        echo '</div>';
    }

    /**
     * Format a human-readable prereq summary for the activity list.
     *
     * @param int $activity_id
     * @return string HTML-safe summary.
     */
    private function format_prereq_summary($activity_id) {
        $groups = $this->get_prereq_groups($activity_id);
        if (empty($groups)) {
            return '<span style="color:#999;">&mdash;</span>';
        }

        $parts = array();
        foreach ($groups as $group) {
            if (empty($group['items'])) {
                continue;
            }
            $names = $this->resolve_activity_names($group['items']);
            $type  = $group['prereq_type'];

            switch ($type) {
                case 'any_of':
                    $parts[] = sprintf(
                        __('Any of: %s', 'hl-core'),
                        esc_html(implode(', ', $names))
                    );
                    break;
                case 'n_of_m':
                    $n = isset($group['n_required']) ? (int) $group['n_required'] : 0;
                    $parts[] = sprintf(
                        __('%d of: %s', 'hl-core'),
                        $n,
                        esc_html(implode(', ', $names))
                    );
                    break;
                case 'all_of':
                default:
                    $parts[] = sprintf(
                        __('All of: %s', 'hl-core'),
                        esc_html(implode(', ', $names))
                    );
                    break;
            }
        }

        return !empty($parts) ? implode('<br>', $parts) : '<span style="color:#999;">&mdash;</span>';
    }

    /**
     * Resolve activity IDs to their titles.
     *
     * @param int[] $activity_ids
     * @return string[]
     */
    private function resolve_activity_names($activity_ids) {
        if (empty($activity_ids)) {
            return array();
        }
        global $wpdb;
        $ids = implode(',', array_map('intval', $activity_ids));
        $rows = $wpdb->get_results(
            "SELECT activity_id, title FROM {$wpdb->prefix}hl_activity WHERE activity_id IN ({$ids})",
            ARRAY_A
        );
        $map = array();
        foreach ($rows as $r) {
            $map[(int) $r['activity_id']] = $r['title'];
        }
        $names = array();
        foreach ($activity_ids as $aid) {
            $names[] = isset($map[(int) $aid]) ? $map[(int) $aid] : ('#' . $aid);
        }
        return $names;
    }
}
