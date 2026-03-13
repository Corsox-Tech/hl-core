<?php if (!defined('ABSPATH')) exit;

/**
 * Admin Partnerships Page
 *
 * Full CRUD admin page with tabbed editor for Partnerships.
 *
 * @package HL_Core
 */
class HL_Admin_Partnerships {

    /** @var HL_Admin_Partnerships|null */
    private static $instance = null;

    /** @var HL_Partnership_Repository */
    private $repo;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->repo = new HL_Partnership_Repository();
        add_action('wp_ajax_hl_send_partnership_emails', array($this, 'ajax_send_partnership_emails'));
        add_action('wp_ajax_hl_reset_email_log', array($this, 'ajax_reset_email_log'));
    }

    /**
     * Handle POST saves and GET deletes before any HTML output.
     */
    public function handle_early_actions() {
        $this->handle_actions();

        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : '';

        if ($action === 'delete') {
            $this->handle_delete();
        }

        if ($action === 'link_school') {
            $this->handle_link_school();
        }

        if ($action === 'unlink_school') {
            $this->handle_unlink_school();
        }

        if ($action === 'delete_phase') {
            $this->handle_delete_phase();
        }

        // Phase save (POST).
        if (isset($_POST['hl_phase_nonce'])) {
            $this->handle_save_phase();
        }
    }

    /**
     * Main render entry point
     */
    public function render_page() {
        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : 'list';

        echo '<div class="wrap hl-admin-wrap">';

        switch ($action) {
            case 'new':
                $this->render_form();
                break;

            case 'edit':
                $partnership_id = isset($_GET['id']) ? absint($_GET['id']) : 0;
                $partnership = $this->repo->get_by_id($partnership_id);
                if ($partnership) {
                    $this->render_tabbed_editor($partnership);
                } else {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Partnership not found.', 'hl-core') . '</p></div>';
                    $this->render_list();
                }
                break;

            default:
                $this->render_list();
                break;
        }

        echo '</div>';
    }

    // =========================================================================
    // Action Handlers
    // =========================================================================

    private function handle_actions() {
        if (!isset($_POST['hl_partnership_nonce'])) {
            return;
        }

        if (!wp_verify_nonce($_POST['hl_partnership_nonce'], 'hl_save_partnership')) {
            wp_die(__('Security check failed.', 'hl-core'));
        }

        if (!current_user_can('manage_hl_core')) {
            wp_die(__('You do not have permission to perform this action.', 'hl-core'));
        }

        // Proactively ensure partnership_id column exists BEFORE any save attempt.
        // If the column was missed by dbDelta or a migration, this adds it now so
        // the INSERT/UPDATE below won't fail silently.
        $this->ensure_cohort_column();

        $partnership_id = isset($_POST['partnership_id']) ? absint($_POST['partnership_id']) : 0;

        $data = array(
            'partnership_name'       => sanitize_text_field($_POST['partnership_name']),
            'partnership_code'       => sanitize_text_field($_POST['partnership_code']),
            'status'           => sanitize_text_field($_POST['status']),
            'start_date'       => sanitize_text_field($_POST['start_date']),
            'end_date'         => sanitize_text_field($_POST['end_date']),
            'timezone'         => sanitize_text_field($_POST['timezone']),
            'district_id'      => !empty($_POST['district_id']) ? absint($_POST['district_id']) : null,
            'cohort_id'        => !empty($_POST['cohort_id']) ? absint($_POST['cohort_id']) : null,
            'is_control_group' => !empty($_POST['is_control_group']) ? 1 : 0,
            'partnership_type'       => isset($_POST['partnership_type']) && in_array($_POST['partnership_type'], array('program', 'course'), true) ? $_POST['partnership_type'] : 'program',
        );

        if (empty($data['end_date'])) {
            $data['end_date'] = null;
        }

        if ($partnership_id > 0) {
            $updated = $this->repo->update($partnership_id, $data);

            if (!$updated || $updated->cohort_id === null && $data['cohort_id'] !== null) {
                error_log('[HL Core] Cohort assignment save may have failed for ID ' . $partnership_id
                    . ': expected=' . ($data['cohort_id'] ?? 'NULL')
                    . ' got=' . ($updated ? ($updated->cohort_id ?? 'NULL') : 'NO_RESULT'));
            }

            $redirect = admin_url('admin.php?page=hl-partnerships&action=edit&id=' . $partnership_id . '&tab=details&message=updated');
        } else {
            $data['partnership_uuid'] = HL_DB_Utils::generate_uuid();
            if (empty($data['partnership_code'])) {
                $data['partnership_code'] = HL_Normalization::generate_code($data['partnership_name']);
            }
            $new_id = $this->repo->create($data);
            $redirect = admin_url('admin.php?page=hl-partnerships&action=edit&id=' . $new_id . '&message=created');
        }

        wp_redirect($redirect);
        exit;
    }

    /**
     * Ensure the cohort_id column exists in the hl_partnership table.
     *
     * Self-healing: if the migration failed or dbDelta didn't add the column,
     * this creates it on the fly so the save can succeed.
     */
    private function ensure_cohort_column() {
        global $wpdb;
        $table = $wpdb->prefix . 'hl_partnership';

        $has_col = $wpdb->get_var( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
            $table,
            'cohort_id'
        ) );

        if ( empty( $has_col ) ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `cohort_id` bigint(20) unsigned DEFAULT NULL" );

            // Verify it was actually added.
            $verify = $wpdb->get_var( $wpdb->prepare(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                $table,
                'cohort_id'
            ) );

            if ( ! empty( $verify ) ) {
                $wpdb->query( "ALTER TABLE `{$table}` ADD INDEX `cohort_id` (`cohort_id`)" );
                error_log( '[HL Core] Self-healed: added missing cohort_id column to ' . $table );
            } else {
                error_log( '[HL Core] CRITICAL: Failed to add cohort_id column. Last error: ' . $wpdb->last_error );
            }
        }
    }

    private function handle_delete() {
        $partnership_id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        if (!$partnership_id) return;

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'hl_delete_partnership_' . $partnership_id)) {
            wp_die(__('Security check failed.', 'hl-core'));
        }
        if (!current_user_can('manage_hl_core')) {
            wp_die(__('You do not have permission to perform this action.', 'hl-core'));
        }

        $this->repo->delete($partnership_id);
        wp_redirect(admin_url('admin.php?page=hl-partnerships&message=deleted'));
        exit;
    }

    private function handle_link_school() {
        if (!isset($_POST['hl_link_school_nonce']) || !wp_verify_nonce($_POST['hl_link_school_nonce'], 'hl_link_school')) {
            wp_die(__('Security check failed.', 'hl-core'));
        }
        if (!current_user_can('manage_hl_core')) {
            wp_die(__('You do not have permission to perform this action.', 'hl-core'));
        }

        global $wpdb;
        $partnership_id = absint($_POST['partnership_id']);
        $school_id = absint($_POST['school_id']);

        if ($partnership_id && $school_id) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}hl_partnership_school WHERE partnership_id = %d AND school_id = %d",
                $partnership_id, $school_id
            ));
            if (!$exists) {
                $wpdb->insert($wpdb->prefix . 'hl_partnership_school', array(
                    'partnership_id' => $partnership_id,
                    'school_id' => $school_id,
                ));
            }
        }

        wp_redirect(admin_url('admin.php?page=hl-partnerships&action=edit&id=' . $partnership_id . '&tab=schools&message=school_linked'));
        exit;
    }

    private function handle_unlink_school() {
        $partnership_id = isset($_GET['partnership_id']) ? absint($_GET['partnership_id']) : 0;
        $school_id = isset($_GET['school_id']) ? absint($_GET['school_id']) : 0;

        if (!$partnership_id || !$school_id) return;

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'hl_unlink_school_' . $school_id)) {
            wp_die(__('Security check failed.', 'hl-core'));
        }
        if (!current_user_can('manage_hl_core')) {
            wp_die(__('You do not have permission to perform this action.', 'hl-core'));
        }

        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'hl_partnership_school', array(
            'partnership_id' => $partnership_id,
            'school_id' => $school_id,
        ));

        wp_redirect(admin_url('admin.php?page=hl-partnerships&action=edit&id=' . $partnership_id . '&tab=schools&message=school_unlinked'));
        exit;
    }

    // =========================================================================
    // Partnership List
    // =========================================================================

    private function render_list() {
        $partnerships = $this->repo->get_all();

        if (isset($_GET['message'])) {
            $msg = sanitize_text_field($_GET['message']);
            $messages = array(
                'created' => __('Partnership created successfully.', 'hl-core'),
                'updated' => __('Partnership updated successfully.', 'hl-core'),
                'deleted' => __('Partnership deleted successfully.', 'hl-core'),
            );
            if (isset($messages[$msg])) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($messages[$msg]) . '</p></div>';
            }
        }

        echo '<h1 class="wp-heading-inline">' . esc_html__('Partnerships', 'hl-core') . '</h1>';
        echo ' <a href="' . esc_url(admin_url('admin.php?page=hl-partnerships&action=new')) . '" class="page-title-action">' . esc_html__('Add New', 'hl-core') . '</a>';
        echo '<hr class="wp-header-end">';

        global $wpdb;
        $school_counts = array();
        $counts = $wpdb->get_results(
            "SELECT partnership_id, COUNT(*) as cnt FROM {$wpdb->prefix}hl_partnership_school GROUP BY partnership_id",
            ARRAY_A
        );
        if ($counts) {
            foreach ($counts as $row) {
                $school_counts[$row['partnership_id']] = $row['cnt'];
            }
        }

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

        if (empty($partnerships)) {
            echo '<p>' . esc_html__('No partnerships found. Create your first partnership to get started.', 'hl-core') . '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('ID', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Name', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Status', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Start Date', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('District', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Schools', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Actions', 'hl-core') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($partnerships as $partnership) {
            $edit_url   = admin_url('admin.php?page=hl-partnerships&action=edit&id=' . $partnership->partnership_id);
            $delete_url = wp_nonce_url(
                admin_url('admin.php?page=hl-partnerships&action=delete&id=' . $partnership->partnership_id),
                'hl_delete_partnership_' . $partnership->partnership_id
            );

            $district_name = ($partnership->district_id && isset($districts[$partnership->district_id])) ? $districts[$partnership->district_id] : '';
            $school_count  = isset($school_counts[$partnership->partnership_id]) ? $school_counts[$partnership->partnership_id] : 0;

            echo '<tr>';
            echo '<td>' . esc_html($partnership->partnership_id) . '</td>';
            echo '<td><strong><a href="' . esc_url($edit_url) . '">' . esc_html($partnership->partnership_name) . '</a></strong>';
            if ($partnership->is_control_group) {
                echo ' <span class="hl-status hl-status-control">Control</span>';
            }
            $tt = isset($partnership->partnership_type) ? $partnership->partnership_type : 'program';
            if ($tt === 'course') {
                echo ' <span class="hl-type-badge course">Course</span>';
            }
            echo '</td>';
            echo '<td><span class="hl-status hl-status-' . esc_attr($partnership->status) . '">' . esc_html(ucfirst($partnership->status)) . '</span></td>';
            echo '<td>' . esc_html($partnership->start_date) . '</td>';
            echo '<td>' . esc_html($district_name) . '</td>';
            echo '<td>' . esc_html($school_count) . '</td>';
            echo '<td>';
            echo '<a href="' . esc_url($edit_url) . '" class="button button-small">' . esc_html__('Edit', 'hl-core') . '</a> ';
            echo '<a href="' . esc_url($delete_url) . '" class="button button-small button-link-delete" onclick="return confirm(\'' . esc_js(__('Are you sure you want to delete this partnership?', 'hl-core')) . '\');">' . esc_html__('Delete', 'hl-core') . '</a>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    // =========================================================================
    // New Partnership Form (no tabs)
    // =========================================================================

    private function render_form() {
        global $wpdb;
        $districts = $wpdb->get_results(
            "SELECT orgunit_id, name FROM {$wpdb->prefix}hl_orgunit WHERE orgunit_type = 'district' AND status = 'active' ORDER BY name ASC",
            ARRAY_A
        );

        echo '<h1>' . esc_html__('Add New Partnership', 'hl-core') . '</h1>';
        echo '<a href="' . esc_url(admin_url('admin.php?page=hl-partnerships')) . '">&larr; ' . esc_html__('Back to Partnerships', 'hl-core') . '</a>';

        $this->render_details_form(null, $districts);
    }

    // =========================================================================
    // Tabbed Editor (edit mode)
    // =========================================================================

    private function render_tabbed_editor($partnership) {
        global $wpdb;

        $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'details';
        $sub         = isset($_GET['sub']) ? sanitize_text_field($_GET['sub']) : '';
        $partnership_id   = $partnership->partnership_id;
        $base_url    = admin_url('admin.php?page=hl-partnerships&action=edit&id=' . $partnership_id);

        // Messages.
        if (isset($_GET['message'])) {
            $msg = sanitize_text_field($_GET['message']);
            $messages = array(
                'created'            => __('Partnership created successfully.', 'hl-core'),
                'updated'            => __('Partnership updated successfully.', 'hl-core'),
                'school_linked'      => __('School linked to partnership.', 'hl-core'),
                'school_unlinked'    => __('School unlinked from partnership.', 'hl-core'),
                'pathway_saved'      => __('Pathway saved successfully.', 'hl-core'),
                'pathway_deleted'    => __('Pathway deleted successfully.', 'hl-core'),
                'pathway_cloned'     => __('Pathway cloned successfully.', 'hl-core'),
                'activity_saved'     => __('Activity saved successfully.', 'hl-core'),
                'activity_deleted'   => __('Activity deleted successfully.', 'hl-core'),
                'team_created'       => __('Team created successfully.', 'hl-core'),
                'team_updated'       => __('Team updated successfully.', 'hl-core'),
                'team_deleted'       => __('Team deleted successfully.', 'hl-core'),
                'enrollment_created' => __('Enrollment created successfully.', 'hl-core'),
                'enrollment_updated' => __('Enrollment updated successfully.', 'hl-core'),
                'enrollment_deleted' => __('Enrollment deleted successfully.', 'hl-core'),
                'assigned'           => __('Pathway assigned to enrollment.', 'hl-core'),
                'unassigned'         => __('Pathway unassigned from enrollment.', 'hl-core'),
                'bulk_assigned'      => __('Pathway assigned to selected enrollments.', 'hl-core'),
                'synced'             => __('Role-based default assignments synced.', 'hl-core'),
                'template_saved'     => __('Pathway saved as template.', 'hl-core'),
                'template_removed'   => __('Pathway removed from templates.', 'hl-core'),
                'clone_error'        => __('Clone failed. Please try again.', 'hl-core'),
                'phase_saved'        => __('Phase saved successfully.', 'hl-core'),
                'phase_deleted'      => __('Phase deleted successfully.', 'hl-core'),
                'phase_delete_error' => __('Cannot delete phase: it still has linked pathways.', 'hl-core'),
            );
            if (isset($messages[$msg])) {
                $notice_type = in_array($msg, array('clone_error', 'phase_delete_error'), true) ? 'notice-error' : 'notice-success';
                echo '<div class="notice ' . $notice_type . ' is-dismissible"><p>' . esc_html($messages[$msg]) . '</p></div>';
            }
        }

        // Header.
        $status_colors = array(
            'active' => '#00a32a', 'draft' => '#996800',
            'paused' => '#b32d2e', 'archived' => '#8c8f94',
        );
        $sc = isset($status_colors[$partnership->status]) ? $status_colors[$partnership->status] : '#666';

        echo '<h1>' . esc_html($partnership->partnership_name) . ' ';
        echo '<span style="color:' . esc_attr($sc) . '; font-size:14px; font-weight:600; vertical-align:middle;">' . esc_html(ucfirst($partnership->status)) . '</span>';
        if ($partnership->is_control_group) {
            echo ' <span class="hl-status-badge" style="background:#9b59b6;color:#fff;font-size:12px;">Control Group</span>';
        }
        echo '</h1>';
        echo '<a href="' . esc_url(admin_url('admin.php?page=hl-partnerships')) . '">&larr; ' . esc_html__('Back to Partnerships', 'hl-core') . '</a>';

        // Tabs.
        $tabs = array(
            'details'     => __('Details', 'hl-core'),
            'schools'     => __('Schools', 'hl-core'),
            'phases'      => __('Phases', 'hl-core'),
            'pathways'    => __('Pathways', 'hl-core'),
            'teams'       => __('Teams', 'hl-core'),
            'enrollments' => __('Enrollments', 'hl-core'),
            'coaching'    => __('Coaching', 'hl-core'),
            'classrooms'  => __('Classrooms', 'hl-core'),
            'assessments' => __('Assessments', 'hl-core'),
            'emails'      => __('Emails', 'hl-core'),
        );

        // Control group partnerships don't use coaching or teams.
        if ($partnership->is_control_group) {
            unset($tabs['coaching'], $tabs['teams']);
        }

        // Emails tab only for control group partnerships.
        if (!$partnership->is_control_group) {
            unset($tabs['emails']);
        }

        // Course-type partnerships hide phases tab (auto-managed).
        $partnership_type = isset($partnership->partnership_type) ? $partnership->partnership_type : 'program';
        if ($partnership_type === 'course') {
            unset($tabs['phases'], $tabs['teams'], $tabs['coaching']);
        }

        echo '<nav class="nav-tab-wrapper" style="margin-top:15px;">';
        foreach ($tabs as $slug => $label) {
            $class = ($current_tab === $slug) ? 'nav-tab nav-tab-active' : 'nav-tab';
            $url   = $base_url . '&tab=' . $slug;
            echo '<a href="' . esc_url($url) . '" class="' . esc_attr($class) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';

        echo '<div class="hl-tab-content" style="margin-top:15px;">';

        switch ($current_tab) {
            case 'schools':
                $this->render_tab_schools($partnership);
                break;
            case 'phases':
                $this->render_tab_phases($partnership, $sub);
                break;
            case 'pathways':
                $this->render_tab_pathways($partnership, $sub);
                break;
            case 'teams':
                $this->render_tab_teams($partnership, $sub);
                break;
            case 'enrollments':
                $this->render_tab_enrollments($partnership, $sub);
                break;
            case 'coaching':
                $this->render_tab_coaching($partnership);
                break;
            case 'classrooms':
                $this->render_tab_classrooms($partnership);
                break;
            case 'assessments':
                $this->render_tab_assessments($partnership);
                break;
            case 'emails':
                $this->render_tab_emails($partnership);
                break;
            case 'details':
            default:
                $districts = $wpdb->get_results(
                    "SELECT orgunit_id, name FROM {$wpdb->prefix}hl_orgunit WHERE orgunit_type = 'district' AND status = 'active' ORDER BY name ASC",
                    ARRAY_A
                );
                $this->render_details_form($partnership, $districts);
                break;
        }

        echo '</div>';
    }

    /**
     * Build the cohort context array passed to standalone class render methods.
     *
     * @param object $partnership
     * @return array
     */
    private function get_partnership_context($partnership) {
        return array(
            'partnership_id'   => $partnership->partnership_id,
            'partnership_name' => $partnership->partnership_name,
        );
    }

    /**
     * Render a breadcrumb trail within a tab sub-view.
     *
     * @param object $partnership     Partnership object.
     * @param string $tab        Current tab slug (e.g. 'pathways').
     * @param string $tab_label  Tab display label (e.g. 'Pathways').
     * @param array  $crumbs     Additional crumb items as [ ['label' => ..., 'url' => ...], ... ].
     *                           The last item is rendered as plain text (current page).
     */
    private function render_breadcrumb($partnership, $tab, $tab_label, $crumbs = array()) {
        $base_url = admin_url('admin.php?page=hl-partnerships&action=edit&id=' . $partnership->partnership_id . '&tab=' . $tab);

        echo '<nav class="hl-breadcrumb" style="margin-bottom:12px; font-size:13px;">';
        echo '<a href="' . esc_url($base_url) . '">' . esc_html($tab_label) . '</a>';

        foreach ($crumbs as $i => $crumb) {
            echo ' &rsaquo; ';
            $is_last = ($i === count($crumbs) - 1);
            if ($is_last || empty($crumb['url'])) {
                echo '<strong>' . esc_html($crumb['label']) . '</strong>';
            } else {
                echo '<a href="' . esc_url($crumb['url']) . '">' . esc_html($crumb['label']) . '</a>';
            }
        }

        echo '</nav>';
    }

    // =========================================================================
    // Tab: Details (shared form for new and edit)
    // =========================================================================

    private function render_details_form($partnership, $districts) {
        $is_edit = ($partnership !== null);

        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=hl-partnerships')) . '">';
        wp_nonce_field('hl_save_partnership', 'hl_partnership_nonce');

        if ($is_edit) {
            echo '<input type="hidden" name="partnership_id" value="' . esc_attr($partnership->partnership_id) . '" />';
        }

        // Compact card-based form layout
        $current_status   = $is_edit ? $partnership->status : 'draft';
        $current_tz       = $is_edit ? $partnership->timezone : 'America/Bogota';
        $current_district = $is_edit ? $partnership->district_id : '';
        $current_type     = $is_edit && isset($partnership->partnership_type) ? $partnership->partnership_type : 'program';
        $is_control       = $is_edit ? (int) $partnership->is_control_group : 0;

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

        echo '<div class="hl-compact-form">';

        // Section 1: General
        echo '<div class="hl-form-section">';
        echo '<h3 class="hl-form-section-title">' . esc_html__('General', 'hl-core') . '</h3>';
        echo '<div class="hl-form-grid">';

        // Partnership Name (full width)
        echo '<div class="hl-field hl-field-full">';
        echo '<label for="partnership_name">' . esc_html__('Partnership Name', 'hl-core') . '</label>';
        echo '<input type="text" id="partnership_name" name="partnership_name" value="' . esc_attr($is_edit ? $partnership->partnership_name : '') . '" required />';
        echo '</div>';

        // Partnership Code (half)
        echo '<div class="hl-field">';
        echo '<label for="partnership_code">' . esc_html__('Partnership Code', 'hl-core') . '</label>';
        echo '<input type="text" id="partnership_code" name="partnership_code" value="' . esc_attr($is_edit ? $partnership->partnership_code : '') . '" />';
        echo '<p class="description">' . esc_html__('Auto-generated if blank.', 'hl-core') . '</p>';
        echo '</div>';

        // Status (half)
        echo '<div class="hl-field">';
        echo '<label for="status">' . esc_html__('Status', 'hl-core') . '</label>';
        echo '<select id="status" name="status">';
        foreach (array('draft', 'active', 'paused', 'archived') as $s) {
            echo '<option value="' . esc_attr($s) . '"' . selected($current_status, $s, false) . '>' . esc_html(ucfirst($s)) . '</option>';
        }
        echo '</select>';
        echo '</div>';

        // Start Date (half)
        echo '<div class="hl-field">';
        echo '<label for="start_date">' . esc_html__('Start Date', 'hl-core') . '</label>';
        echo '<input type="date" id="start_date" name="start_date" value="' . esc_attr($is_edit ? $partnership->start_date : '') . '" required />';
        echo '</div>';

        // End Date (half)
        echo '<div class="hl-field">';
        echo '<label for="end_date">' . esc_html__('End Date', 'hl-core') . '</label>';
        echo '<input type="date" id="end_date" name="end_date" value="' . esc_attr($is_edit && $partnership->end_date ? $partnership->end_date : '') . '" />';
        echo '<p class="description">' . esc_html__('Optional. Leave blank for open-ended.', 'hl-core') . '</p>';
        echo '</div>';

        echo '</div>'; // .hl-form-grid
        echo '</div>'; // .hl-form-section

        // Section 2: Configuration
        echo '<div class="hl-form-section">';
        echo '<h3 class="hl-form-section-title">' . esc_html__('Configuration', 'hl-core') . '</h3>';
        echo '<div class="hl-form-grid">';

        // Timezone (half)
        echo '<div class="hl-field">';
        echo '<label for="timezone">' . esc_html__('Timezone', 'hl-core') . '</label>';
        echo '<select id="timezone" name="timezone">';
        foreach ($timezones as $tz_val => $tz_lbl) {
            echo '<option value="' . esc_attr($tz_val) . '"' . selected($current_tz, $tz_val, false) . '>' . esc_html($tz_lbl) . '</option>';
        }
        echo '</select>';
        echo '</div>';

        // District (half)
        echo '<div class="hl-field">';
        echo '<label for="district_id">' . esc_html__('District', 'hl-core') . '</label>';
        echo '<select id="district_id" name="district_id">';
        echo '<option value="">' . esc_html__('-- Select District --', 'hl-core') . '</option>';
        if ($districts) {
            foreach ($districts as $d) {
                echo '<option value="' . esc_attr($d['orgunit_id']) . '"' . selected($current_district, $d['orgunit_id'], false) . '>' . esc_html($d['name']) . '</option>';
            }
        }
        echo '</select>';
        echo '</div>';

        // Partnership Type (full width)
        echo '<div class="hl-field hl-field-full">';
        echo '<label for="partnership_type">' . esc_html__('Partnership Type', 'hl-core') . '</label>';
        echo '<select id="partnership_type" name="partnership_type">';
        echo '<option value="program"' . selected($current_type, 'program', false) . '>' . esc_html__('Program (Phases, Pathways, Teams, Coaching, Assessments)', 'hl-core') . '</option>';
        echo '<option value="course"' . selected($current_type, 'course', false) . '>' . esc_html__('Course (auto-created single Phase + Pathway)', 'hl-core') . '</option>';
        echo '</select>';
        echo '</div>';

        // Control Group (full width)
        echo '<div class="hl-field hl-field-full">';
        echo '<label class="hl-checkbox-label"><input type="checkbox" name="is_control_group" value="1" ' . checked($is_control, 1, false) . '> ';
        echo esc_html__('Control/comparison group (assessment-only, no coaching or teams)', 'hl-core') . '</label>';
        echo '</div>';

        echo '</div>'; // .hl-form-grid
        echo '</div>'; // .hl-form-section

        echo '</div>'; // .hl-compact-form
        submit_button($is_edit ? __('Update Partnership', 'hl-core') : __('Create Partnership', 'hl-core'));
        echo '</form>';
    }

    // =========================================================================
    // Tab: Schools
    // =========================================================================

    private function render_tab_schools($partnership) {
        global $wpdb;
        $partnership_id = $partnership->partnership_id;

        // Linked schools.
        $linked = $wpdb->get_results($wpdb->prepare(
            "SELECT cc.id AS link_id, cc.school_id, o.name AS school_name, o.parent_orgunit_id,
                    p.name AS district_name
             FROM {$wpdb->prefix}hl_partnership_school cc
             JOIN {$wpdb->prefix}hl_orgunit o ON cc.school_id = o.orgunit_id
             LEFT JOIN {$wpdb->prefix}hl_orgunit p ON o.parent_orgunit_id = p.orgunit_id
             WHERE cc.partnership_id = %d
             ORDER BY o.name ASC",
            $partnership_id
        ));

        // Get leader names per school (enrolled as school_leader).
        $leader_names = array();
        if ($linked) {
            $school_ids = wp_list_pluck($linked, 'school_id');
            if (!empty($school_ids)) {
                $in_ids  = implode(',', array_map('intval', $school_ids));
                $leaders = $wpdb->get_results(
                    "SELECT e.school_id, u.display_name
                     FROM {$wpdb->prefix}hl_enrollment e
                     JOIN {$wpdb->users} u ON e.user_id = u.ID
                     WHERE e.school_id IN ({$in_ids}) AND e.partnership_id = " . intval($partnership_id) . "
                       AND e.roles LIKE '%school_leader%' AND e.status = 'active'",
                    ARRAY_A
                );
                foreach ($leaders as $l) {
                    $leader_names[$l['school_id']][] = $l['display_name'];
                }
            }
        }

        // Available schools (not yet linked).
        $available = $wpdb->get_results($wpdb->prepare(
            "SELECT o.orgunit_id, o.name
             FROM {$wpdb->prefix}hl_orgunit o
             WHERE o.orgunit_type = 'school' AND o.status = 'active'
               AND o.orgunit_id NOT IN (
                   SELECT school_id FROM {$wpdb->prefix}hl_partnership_school WHERE partnership_id = %d
               )
             ORDER BY o.name ASC",
            $partnership_id
        ));

        // Link School form.
        if (!empty($available)) {
            echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=hl-partnerships&action=link_school')) . '" style="margin-bottom:15px; display:flex; gap:8px; align-items:center;">';
            wp_nonce_field('hl_link_school', 'hl_link_school_nonce');
            echo '<input type="hidden" name="partnership_id" value="' . esc_attr($partnership_id) . '" />';
            echo '<select name="school_id" required>';
            echo '<option value="">' . esc_html__('-- Select School --', 'hl-core') . '</option>';
            foreach ($available as $c) {
                echo '<option value="' . esc_attr($c->orgunit_id) . '">' . esc_html($c->name) . '</option>';
            }
            echo '</select>';
            echo '<button type="submit" class="button button-primary">' . esc_html__('Link School', 'hl-core') . '</button>';
            echo '</form>';
        }

        if (empty($linked)) {
            echo '<p>' . esc_html__('No schools linked to this partnership yet.', 'hl-core') . '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('School Name', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('District', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Leaders', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Actions', 'hl-core') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($linked as $row) {
            $unlink_url = wp_nonce_url(
                admin_url('admin.php?page=hl-partnerships&action=unlink_school&partnership_id=' . $partnership_id . '&school_id=' . $row->school_id),
                'hl_unlink_school_' . $row->school_id
            );
            $leaders = isset($leader_names[$row->school_id]) ? implode(', ', $leader_names[$row->school_id]) : '-';

            echo '<tr>';
            echo '<td><strong>' . esc_html($row->school_name) . '</strong></td>';
            echo '<td>' . esc_html($row->district_name ?: '-') . '</td>';
            echo '<td>' . esc_html($leaders) . '</td>';
            echo '<td><a href="' . esc_url($unlink_url) . '" class="button button-small button-link-delete" onclick="return confirm(\'' . esc_js(__('Unlink this school?', 'hl-core')) . '\');">' . esc_html__('Unlink', 'hl-core') . '</a></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    // =========================================================================
    // Tab: Phases
    // =========================================================================

    /**
     * Handle Phase save (create or update).
     */
    private function handle_save_phase() {
        if (!wp_verify_nonce($_POST['hl_phase_nonce'], 'hl_save_phase')) {
            wp_die(__('Security check failed.', 'hl-core'));
        }

        if (!current_user_can('manage_hl_core')) {
            wp_die(__('You do not have permission to perform this action.', 'hl-core'));
        }

        $partnership_id = absint($_POST['partnership_id']);
        $phase_id = isset($_POST['phase_id']) ? absint($_POST['phase_id']) : 0;

        $data = array(
            'phase_name'   => sanitize_text_field($_POST['phase_name']),
            'phase_number' => absint($_POST['phase_number']),
            'start_date'   => sanitize_text_field($_POST['start_date']) ?: null,
            'end_date'     => sanitize_text_field($_POST['end_date']) ?: null,
            'status'       => in_array($_POST['status'], array('draft', 'active', 'completed'), true) ? $_POST['status'] : 'draft',
        );

        $phase_svc = new HL_Phase_Service();

        if ($phase_id > 0) {
            $phase_svc->update_phase($phase_id, $data);
        } else {
            $data['partnership_id'] = $partnership_id;
            $phase_svc->create_phase($data);
        }

        wp_redirect(admin_url('admin.php?page=hl-partnerships&action=edit&id=' . $partnership_id . '&tab=phases&message=phase_saved'));
        exit;
    }

    /**
     * Handle Phase delete (GET action).
     */
    private function handle_delete_phase() {
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'hl_delete_phase')) {
            wp_die(__('Security check failed.', 'hl-core'));
        }

        if (!current_user_can('manage_hl_core')) {
            wp_die(__('You do not have permission to perform this action.', 'hl-core'));
        }

        $phase_id = isset($_GET['phase_id']) ? absint($_GET['phase_id']) : 0;
        $partnership_id = isset($_GET['id']) ? absint($_GET['id']) : 0;

        $phase_svc = new HL_Phase_Service();
        $result = $phase_svc->delete_phase($phase_id);

        if (is_wp_error($result)) {
            wp_redirect(admin_url('admin.php?page=hl-partnerships&action=edit&id=' . $partnership_id . '&tab=phases&message=phase_delete_error'));
        } else {
            wp_redirect(admin_url('admin.php?page=hl-partnerships&action=edit&id=' . $partnership_id . '&tab=phases&message=phase_deleted'));
        }
        exit;
    }

    /**
     * Render the Phases tab.
     */
    private function render_tab_phases($partnership, $sub = '') {
        $partnership_id = $partnership->partnership_id;
        $base_url = admin_url('admin.php?page=hl-partnerships&action=edit&id=' . $partnership_id . '&tab=phases');

        switch ($sub) {
            case 'new':
                $this->render_breadcrumb($partnership, 'phases', __('Phases', 'hl-core'), array(
                    array('label' => __('New Phase', 'hl-core')),
                ));
                $this->render_phase_form($partnership, null);
                break;

            case 'edit':
                $phase_id = isset($_GET['phase_id']) ? absint($_GET['phase_id']) : 0;
                $phase_repo = new HL_Phase_Repository();
                $phase = $phase_repo->get_by_id($phase_id);
                if ($phase) {
                    $this->render_breadcrumb($partnership, 'phases', __('Phases', 'hl-core'), array(
                        array('label' => esc_html($phase->phase_name)),
                    ));
                    $this->render_phase_form($partnership, $phase);
                } else {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Phase not found.', 'hl-core') . '</p></div>';
                    $this->render_phases_list($partnership);
                }
                break;

            default:
                $this->render_phases_list($partnership);
                break;
        }
    }

    /**
     * Render the list of Phases for a partnership.
     */
    private function render_phases_list($partnership) {
        $partnership_id = $partnership->partnership_id;
        $base_url = admin_url('admin.php?page=hl-partnerships&action=edit&id=' . $partnership_id . '&tab=phases');
        $phase_repo = new HL_Phase_Repository();
        $phases = $phase_repo->get_by_partnership($partnership_id);

        echo '<a href="' . esc_url($base_url . '&sub=new') . '" class="page-title-action">' . esc_html__('Add Phase', 'hl-core') . '</a>';
        echo '<br style="clear:both;" />';

        if (empty($phases)) {
            echo '<p>' . esc_html__('No phases defined for this partnership yet.', 'hl-core') . '</p>';
            return;
        }

        $status_colors = array(
            'draft' => '#996800', 'active' => '#00a32a', 'completed' => '#8c8f94',
        );

        echo '<table class="widefat striped" style="margin-top:10px;">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('#', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Name', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Status', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Start Date', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('End Date', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Pathways', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Actions', 'hl-core') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($phases as $phase) {
            $pathway_count = $phase_repo->count_pathways($phase->phase_id);
            $sc = isset($status_colors[$phase->status]) ? $status_colors[$phase->status] : '#666';
            $edit_url = $base_url . '&sub=edit&phase_id=' . $phase->phase_id;
            $delete_url = wp_nonce_url(
                admin_url('admin.php?page=hl-partnerships&action=delete_phase&id=' . $partnership_id . '&phase_id=' . $phase->phase_id),
                'hl_delete_phase'
            );

            echo '<tr>';
            echo '<td>' . esc_html($phase->phase_number) . '</td>';
            echo '<td><a href="' . esc_url($edit_url) . '"><strong>' . esc_html($phase->phase_name) . '</strong></a></td>';
            echo '<td><span style="color:' . esc_attr($sc) . '; font-weight:600;">' . esc_html(ucfirst($phase->status)) . '</span></td>';
            echo '<td>' . esc_html($phase->start_date ?: '—') . '</td>';
            echo '<td>' . esc_html($phase->end_date ?: '—') . '</td>';
            echo '<td>' . esc_html($pathway_count) . '</td>';
            echo '<td>';
            echo '<a href="' . esc_url($edit_url) . '">' . esc_html__('Edit', 'hl-core') . '</a>';
            if ($pathway_count === 0) {
                echo ' | <a href="' . esc_url($delete_url) . '" onclick="return confirm(\'' . esc_js(__('Delete this phase?', 'hl-core')) . '\');" style="color:#b32d2e;">' . esc_html__('Delete', 'hl-core') . '</a>';
            }
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * Render the Phase create/edit form.
     */
    private function render_phase_form($partnership, $phase) {
        $is_edit = ($phase !== null);
        $partnership_id = $partnership->partnership_id;

        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=hl-partnerships')) . '">';
        wp_nonce_field('hl_save_phase', 'hl_phase_nonce');
        echo '<input type="hidden" name="partnership_id" value="' . esc_attr($partnership_id) . '" />';
        if ($is_edit) {
            echo '<input type="hidden" name="phase_id" value="' . esc_attr($phase->phase_id) . '" />';
        }

        echo '<table class="form-table">';

        // Phase Name
        echo '<tr><th scope="row"><label for="phase_name">' . esc_html__('Phase Name', 'hl-core') . '</label></th>';
        echo '<td><input type="text" id="phase_name" name="phase_name" value="' . esc_attr($is_edit ? $phase->phase_name : '') . '" class="regular-text" required /></td></tr>';

        // Phase Number
        echo '<tr><th scope="row"><label for="phase_number">' . esc_html__('Phase Number', 'hl-core') . '</label></th>';
        echo '<td><input type="number" id="phase_number" name="phase_number" value="' . esc_attr($is_edit ? $phase->phase_number : '') . '" min="1" class="small-text" required />';
        echo '<p class="description">' . esc_html__('Determines ordering. Must be unique within this partnership.', 'hl-core') . '</p></td></tr>';

        // Status
        $current_status = $is_edit ? $phase->status : 'draft';
        echo '<tr><th scope="row"><label for="status">' . esc_html__('Status', 'hl-core') . '</label></th>';
        echo '<td><select id="status" name="status">';
        foreach (array('draft', 'active', 'completed') as $s) {
            echo '<option value="' . esc_attr($s) . '"' . selected($current_status, $s, false) . '>' . esc_html(ucfirst($s)) . '</option>';
        }
        echo '</select></td></tr>';

        // Start Date
        echo '<tr><th scope="row"><label for="start_date">' . esc_html__('Start Date', 'hl-core') . '</label></th>';
        echo '<td><input type="date" id="start_date" name="start_date" value="' . esc_attr($is_edit && $phase->start_date ? $phase->start_date : '') . '" /></td></tr>';

        // End Date
        echo '<tr><th scope="row"><label for="end_date">' . esc_html__('End Date', 'hl-core') . '</label></th>';
        echo '<td><input type="date" id="end_date" name="end_date" value="' . esc_attr($is_edit && $phase->end_date ? $phase->end_date : '') . '" /></td></tr>';

        echo '</table>';

        submit_button($is_edit ? __('Update Phase', 'hl-core') : __('Create Phase', 'hl-core'));
        echo '</form>';
    }

    // =========================================================================
    // Tab: Pathways
    // =========================================================================

    private function render_tab_pathways($partnership, $sub = '') {
        $pathways_admin = HL_Admin_Pathways::instance();
        $context        = $this->get_partnership_context($partnership);
        $partnership_id      = $partnership->partnership_id;
        $base_url       = admin_url('admin.php?page=hl-partnerships&action=edit&id=' . $partnership_id . '&tab=pathways');

        switch ($sub) {
            case 'new':
                $this->render_breadcrumb($partnership, 'pathways', __('Pathways', 'hl-core'), array(
                    array('label' => __('New Pathway', 'hl-core')),
                ));
                $pathways_admin->render_pathway_form(null, $context);
                return;

            case 'edit':
                $pathway_id = isset($_GET['pathway_id']) ? absint($_GET['pathway_id']) : 0;
                $pathway    = $pathways_admin->get_pathway($pathway_id);
                if (!$pathway || absint($pathway->partnership_id) !== absint($partnership_id)) {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Pathway not found in this partnership.', 'hl-core') . '</p></div>';
                    break; // fall through to list
                }
                $this->render_breadcrumb($partnership, 'pathways', __('Pathways', 'hl-core'), array(
                    array('label' => $pathway->pathway_name, 'url' => $base_url . '&sub=view&pathway_id=' . $pathway_id),
                    array('label' => __('Edit', 'hl-core')),
                ));
                $pathways_admin->render_pathway_form($pathway, $context);
                return;

            case 'view':
                $pathway_id = isset($_GET['pathway_id']) ? absint($_GET['pathway_id']) : 0;
                $pathway    = $pathways_admin->get_pathway($pathway_id);
                if (!$pathway || absint($pathway->partnership_id) !== absint($partnership_id)) {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Pathway not found in this partnership.', 'hl-core') . '</p></div>';
                    break;
                }
                $this->render_breadcrumb($partnership, 'pathways', __('Pathways', 'hl-core'), array(
                    array('label' => $pathway->pathway_name),
                ));
                $pathways_admin->render_pathway_detail($pathway, $context);
                return;

            case 'activity':
                $pathway_id = isset($_GET['pathway_id']) ? absint($_GET['pathway_id']) : 0;
                $pathway    = $pathways_admin->get_pathway($pathway_id);
                if (!$pathway || absint($pathway->partnership_id) !== absint($partnership_id)) {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Pathway not found in this partnership.', 'hl-core') . '</p></div>';
                    break;
                }

                $activity_id     = isset($_GET['activity_id']) ? absint($_GET['activity_id']) : 0;
                $activity_action = isset($_GET['activity_action']) ? sanitize_text_field($_GET['activity_action']) : '';

                if ($activity_id) {
                    $activity = $pathways_admin->get_activity($activity_id);
                    if (!$activity || absint($activity->pathway_id) !== $pathway_id) {
                        echo '<div class="notice notice-error"><p>' . esc_html__('Activity not found.', 'hl-core') . '</p></div>';
                        break;
                    }
                    $this->render_breadcrumb($partnership, 'pathways', __('Pathways', 'hl-core'), array(
                        array('label' => $pathway->pathway_name, 'url' => $base_url . '&sub=view&pathway_id=' . $pathway_id),
                        array('label' => $activity->title . ' — ' . __('Edit', 'hl-core')),
                    ));
                    $pathways_admin->render_activity_form($pathway, $activity, $context);
                } else {
                    // New activity
                    $this->render_breadcrumb($partnership, 'pathways', __('Pathways', 'hl-core'), array(
                        array('label' => $pathway->pathway_name, 'url' => $base_url . '&sub=view&pathway_id=' . $pathway_id),
                        array('label' => __('New Activity', 'hl-core')),
                    ));
                    $pathways_admin->render_activity_form($pathway, null, $context);
                }
                return;
        }

        // Default: show pathways list
        $this->render_tab_pathways_list($partnership);
    }

    /**
     * Pathways list table (default sub-view within Pathways tab).
     */
    private function render_tab_pathways_list($partnership) {
        global $wpdb;
        $partnership_id = $partnership->partnership_id;
        $base_url  = admin_url('admin.php?page=hl-partnerships&action=edit&id=' . $partnership_id . '&tab=pathways');

        $pathways = $wpdb->get_results($wpdb->prepare(
            "SELECT pw.*,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}hl_activity a WHERE a.pathway_id = pw.pathway_id) as activity_count,
                    ph.phase_name
             FROM {$wpdb->prefix}hl_pathway pw
             LEFT JOIN {$wpdb->prefix}hl_phase ph ON pw.phase_id = ph.phase_id
             WHERE pw.partnership_id = %d
             ORDER BY ph.phase_number ASC, pw.pathway_name ASC",
            $partnership_id
        ));

        // Action buttons.
        echo '<div style="margin-bottom:15px; display:flex; gap:8px; flex-wrap:wrap;">';
        echo '<a href="' . esc_url($base_url . '&sub=new') . '" class="button button-primary">' . esc_html__('New Pathway', 'hl-core') . '</a>';

        // Clone from Template.
        $service   = new HL_Pathway_Service();
        $templates = $service->get_templates();
        if (!empty($templates)) {
            echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=hl-pathways&action=clone')) . '" style="display:flex; gap:6px; align-items:center;">';
            wp_nonce_field('hl_clone_pathway', 'hl_clone_nonce');
            echo '<input type="hidden" name="target_partnership_id" value="' . esc_attr($partnership_id) . '" />';
            echo '<input type="hidden" name="_hl_partnership_context" value="' . esc_attr($partnership_id) . '" />';
            echo '<select name="source_pathway_id" required>';
            echo '<option value="">' . esc_html__('-- Clone from Template --', 'hl-core') . '</option>';
            foreach ($templates as $t) {
                echo '<option value="' . esc_attr($t->pathway_id) . '">' . esc_html($t->pathway_name) . '</option>';
            }
            echo '</select>';
            echo '<button type="submit" class="button">' . esc_html__('Clone', 'hl-core') . '</button>';
            echo '</form>';
        }

        echo ' <a href="' . esc_url(admin_url('admin.php?page=hl-pathways&partnership_id=' . $partnership_id)) . '" class="button" title="' . esc_attr__('View all pathways for this partnership on the standalone page', 'hl-core') . '">' . esc_html__('Full Page View', 'hl-core') . '</a>';

        echo '</div>';

        if (empty($pathways)) {
            echo '<p>' . esc_html__('No pathways in this partnership yet.', 'hl-core') . '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Name', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Phase', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Target Roles', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Activities', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Avg Time', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Actions', 'hl-core') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($pathways as $pw) {
            $roles = json_decode($pw->target_roles, true);
            $roles_str = is_array($roles) ? implode(', ', $roles) : '';

            $view_url   = $base_url . '&sub=view&pathway_id=' . $pw->pathway_id;
            $edit_url   = $base_url . '&sub=edit&pathway_id=' . $pw->pathway_id;
            $delete_url = wp_nonce_url(
                admin_url('admin.php?page=hl-pathways&action=delete&id=' . $pw->pathway_id . '&partnership_context=' . $partnership_id),
                'hl_delete_pathway_' . $pw->pathway_id
            );

            echo '<tr>';
            echo '<td><strong><a href="' . esc_url($view_url) . '">' . esc_html($pw->pathway_name) . '</a></strong>';
            if (!empty($pw->is_template)) {
                echo ' <span class="hl-status-badge active" style="font-size:10px;">' . esc_html__('Template', 'hl-core') . '</span>';
            }
            echo '</td>';
            echo '<td>' . esc_html($pw->phase_name ?: '-') . '</td>';
            echo '<td>' . esc_html($roles_str) . '</td>';
            echo '<td>' . esc_html($pw->activity_count) . '</td>';
            echo '<td>' . esc_html($pw->avg_completion_time ?: '-') . '</td>';
            echo '<td>';
            echo '<a href="' . esc_url($view_url) . '" class="button button-small">' . esc_html__('View', 'hl-core') . '</a> ';
            echo '<a href="' . esc_url($edit_url) . '" class="button button-small">' . esc_html__('Edit', 'hl-core') . '</a> ';
            echo '<a href="' . esc_url($delete_url) . '" class="button button-small button-link-delete" onclick="return confirm(\'' . esc_js(__('Delete this pathway and all its activities?', 'hl-core')) . '\');">' . esc_html__('Delete', 'hl-core') . '</a>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    // =========================================================================
    // Tab: Teams
    // =========================================================================

    private function render_tab_teams($partnership, $sub = '') {
        $teams_admin = HL_Admin_Teams::instance();
        $context     = $this->get_partnership_context($partnership);
        $partnership_id   = $partnership->partnership_id;
        $base_url    = admin_url('admin.php?page=hl-partnerships&action=edit&id=' . $partnership_id . '&tab=teams');

        switch ($sub) {
            case 'new':
                $this->render_breadcrumb($partnership, 'teams', __('Teams', 'hl-core'), array(
                    array('label' => __('New Team', 'hl-core')),
                ));
                $teams_admin->render_form(null, $context);
                return;

            case 'edit':
                $team_id = isset($_GET['team_id']) ? absint($_GET['team_id']) : 0;
                $team    = $teams_admin->get_team($team_id);
                if (!$team || absint($team->partnership_id) !== $partnership_id) {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Team not found in this partnership.', 'hl-core') . '</p></div>';
                    break;
                }
                $this->render_breadcrumb($partnership, 'teams', __('Teams', 'hl-core'), array(
                    array('label' => $team->team_name . ' — ' . __('Edit', 'hl-core')),
                ));
                $teams_admin->render_form($team, $context);
                return;

            case 'view':
                $team_id = isset($_GET['team_id']) ? absint($_GET['team_id']) : 0;
                $team    = $teams_admin->get_team($team_id);
                if (!$team || absint($team->partnership_id) !== $partnership_id) {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Team not found in this partnership.', 'hl-core') . '</p></div>';
                    break;
                }
                $this->render_breadcrumb($partnership, 'teams', __('Teams', 'hl-core'), array(
                    array('label' => $team->team_name),
                ));
                $teams_admin->render_team_detail($team, $context);
                return;
        }

        // Default: show teams list
        $this->render_tab_teams_list($partnership);
    }

    /**
     * Teams list table (default sub-view within Teams tab).
     */
    private function render_tab_teams_list($partnership) {
        global $wpdb;
        $partnership_id = $partnership->partnership_id;
        $base_url  = admin_url('admin.php?page=hl-partnerships&action=edit&id=' . $partnership_id . '&tab=teams');

        $teams = $wpdb->get_results($wpdb->prepare(
            "SELECT t.*, o.name AS school_name,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}hl_team_membership tm WHERE tm.team_id = t.team_id) as member_count
             FROM {$wpdb->prefix}hl_team t
             LEFT JOIN {$wpdb->prefix}hl_orgunit o ON t.school_id = o.orgunit_id
             WHERE t.partnership_id = %d
             ORDER BY t.team_name ASC",
            $partnership_id
        ));

        // Get mentor names per team.
        $mentor_names = array();
        if ($teams) {
            $team_ids = wp_list_pluck($teams, 'team_id');
            if (!empty($team_ids)) {
                $in_ids  = implode(',', array_map('intval', $team_ids));
                $mentors = $wpdb->get_results(
                    "SELECT tm.team_id, u.display_name
                     FROM {$wpdb->prefix}hl_team_membership tm
                     JOIN {$wpdb->prefix}hl_enrollment e ON tm.enrollment_id = e.enrollment_id
                     JOIN {$wpdb->users} u ON e.user_id = u.ID
                     WHERE tm.team_id IN ({$in_ids}) AND tm.membership_type = 'mentor'",
                    ARRAY_A
                );
                foreach ($mentors as $m) {
                    $mentor_names[$m['team_id']][] = $m['display_name'];
                }
            }
        }

        echo '<div style="margin-bottom:15px; display:flex; gap:8px;">';
        echo '<a href="' . esc_url($base_url . '&sub=new') . '" class="button button-primary">' . esc_html__('Create Team', 'hl-core') . '</a>';
        echo ' <a href="' . esc_url(admin_url('admin.php?page=hl-teams&partnership_id=' . $partnership_id)) . '" class="button" title="' . esc_attr__('View all teams for this partnership on the standalone page', 'hl-core') . '">' . esc_html__('Full Page View', 'hl-core') . '</a>';
        echo '</div>';

        if (empty($teams)) {
            echo '<p>' . esc_html__('No teams in this partnership yet.', 'hl-core') . '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Team Name', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('School', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Mentors', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Members', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Status', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Actions', 'hl-core') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($teams as $t) {
            $mentors_str = isset($mentor_names[$t->team_id]) ? implode(', ', $mentor_names[$t->team_id]) : '-';
            $view_url    = $base_url . '&sub=view&team_id=' . $t->team_id;
            $edit_url    = $base_url . '&sub=edit&team_id=' . $t->team_id;
            $delete_url  = wp_nonce_url(
                admin_url('admin.php?page=hl-teams&action=delete&id=' . $t->team_id . '&partnership_context=' . $partnership_id),
                'hl_delete_team_' . $t->team_id
            );

            $status_style = ($t->status === 'active')
                ? 'color:#00a32a;font-weight:600;'
                : 'color:#b32d2e;font-weight:600;';

            echo '<tr>';
            echo '<td><strong><a href="' . esc_url($view_url) . '">' . esc_html($t->team_name) . '</a></strong></td>';
            echo '<td>' . esc_html($t->school_name ?: '-') . '</td>';
            echo '<td>' . esc_html($mentors_str) . '</td>';
            echo '<td>' . esc_html($t->member_count) . '</td>';
            echo '<td><span style="' . esc_attr($status_style) . '">' . esc_html(ucfirst($t->status)) . '</span></td>';
            echo '<td>';
            echo '<a href="' . esc_url($view_url) . '" class="button button-small">' . esc_html__('View', 'hl-core') . '</a> ';
            echo '<a href="' . esc_url($edit_url) . '" class="button button-small">' . esc_html__('Edit', 'hl-core') . '</a> ';
            echo '<a href="' . esc_url($delete_url) . '" class="button button-small button-link-delete" onclick="return confirm(\'' . esc_js(__('Are you sure?', 'hl-core')) . '\');">' . esc_html__('Delete', 'hl-core') . '</a>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    // =========================================================================
    // Tab: Enrollments
    // =========================================================================

    private function render_tab_enrollments($partnership, $sub = '') {
        $enrollments_admin = HL_Admin_Enrollments::instance();
        $context           = $this->get_partnership_context($partnership);
        $partnership_id         = $partnership->partnership_id;
        $base_url          = admin_url('admin.php?page=hl-partnerships&action=edit&id=' . $partnership_id . '&tab=enrollments');

        switch ($sub) {
            case 'new':
                $this->render_breadcrumb($partnership, 'enrollments', __('Enrollments', 'hl-core'), array(
                    array('label' => __('Enroll User', 'hl-core')),
                ));
                $enrollments_admin->render_form(null, $context);
                return;

            case 'edit':
                $enrollment_id = isset($_GET['enrollment_id']) ? absint($_GET['enrollment_id']) : 0;
                $enrollment    = $enrollments_admin->get_enrollment($enrollment_id);
                if (!$enrollment || absint($enrollment->partnership_id) !== $partnership_id) {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Enrollment not found in this partnership.', 'hl-core') . '</p></div>';
                    break;
                }
                $this->render_breadcrumb($partnership, 'enrollments', __('Enrollments', 'hl-core'), array(
                    array('label' => __('Edit Enrollment', 'hl-core')),
                ));
                $enrollments_admin->render_form($enrollment, $context);
                return;
        }

        // Default: show enrollments list
        $this->render_tab_enrollments_list($partnership);
    }

    /**
     * Enrollments list table (default sub-view within Enrollments tab).
     */
    private function render_tab_enrollments_list($partnership) {
        global $wpdb;
        $partnership_id = $partnership->partnership_id;
        $base_url  = admin_url('admin.php?page=hl-partnerships&action=edit&id=' . $partnership_id . '&tab=enrollments');

        $page_num = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        $per_page = 25;
        $offset   = ($page_num - 1) * $per_page;

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}hl_enrollment WHERE partnership_id = %d",
            $partnership_id
        ));

        $enrollments = $wpdb->get_results($wpdb->prepare(
            "SELECT e.*, u.display_name, u.user_email
             FROM {$wpdb->prefix}hl_enrollment e
             LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
             WHERE e.partnership_id = %d
             ORDER BY u.display_name ASC
             LIMIT %d OFFSET %d",
            $partnership_id, $per_page, $offset
        ));

        // Get school names.
        $schools = array();
        $school_rows = $wpdb->get_results(
            "SELECT orgunit_id, name FROM {$wpdb->prefix}hl_orgunit WHERE orgunit_type = 'school'",
            ARRAY_A
        );
        foreach ($school_rows as $r) {
            $schools[$r['orgunit_id']] = $r['name'];
        }

        // Get completion data.
        $rollups = array();
        $rollup_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT enrollment_id, partnership_completion_percent
             FROM {$wpdb->prefix}hl_completion_rollup
             WHERE partnership_id = %d",
            $partnership_id
        ), ARRAY_A);
        foreach ($rollup_rows as $r) {
            $rollups[$r['enrollment_id']] = (float) $r['partnership_completion_percent'];
        }

        // Get team names.
        $team_map = array();
        $tm_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT tm.enrollment_id, t.team_name
             FROM {$wpdb->prefix}hl_team_membership tm
             JOIN {$wpdb->prefix}hl_team t ON tm.team_id = t.team_id
             WHERE t.partnership_id = %d",
            $partnership_id
        ), ARRAY_A);
        foreach ($tm_rows as $r) {
            $team_map[$r['enrollment_id']] = $r['team_name'];
        }

        echo '<div style="margin-bottom:15px; display:flex; gap:8px; align-items:center;">';
        echo '<a href="' . esc_url($base_url . '&sub=new') . '" class="button button-primary">' . esc_html__('Enroll User', 'hl-core') . '</a>';
        echo ' <a href="' . esc_url(admin_url('admin.php?page=hl-enrollments&partnership_id=' . $partnership_id)) . '" class="button" title="' . esc_attr__('View all enrollments for this partnership on the standalone page', 'hl-core') . '">' . esc_html__('Full Page View', 'hl-core') . '</a>';
        echo ' <span style="color:#666;">' . sprintf(esc_html__('%d enrollments total', 'hl-core'), $total) . '</span>';
        echo '</div>';

        if (empty($enrollments)) {
            echo '<p>' . esc_html__('No enrollments in this partnership yet.', 'hl-core') . '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Name', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Email', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Roles', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Pathway', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Team', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('School', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Completion', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Actions', 'hl-core') . '</th>';
        echo '</tr></thead><tbody>';

        // Collect pathway names per enrollment.
        $pathway_names_map = array();
        $enrollment_ids_list = wp_list_pluck($enrollments, 'enrollment_id');
        if (!empty($enrollment_ids_list)) {
            $pa_in = implode(',', array_map('intval', $enrollment_ids_list));
            $pa_rows = $wpdb->get_results(
                "SELECT pa.enrollment_id, p.pathway_name, pa.assignment_type
                 FROM {$wpdb->prefix}hl_pathway_assignment pa
                 JOIN {$wpdb->prefix}hl_pathway p ON pa.pathway_id = p.pathway_id
                 WHERE pa.enrollment_id IN ({$pa_in})
                 ORDER BY pa.assignment_type ASC",
                ARRAY_A
            );
            foreach ($pa_rows as $par) {
                $pathway_names_map[$par['enrollment_id']][] = $par['pathway_name'];
            }
        }

        foreach ($enrollments as $e) {
            $roles = json_decode($e->roles, true);
            $roles_str = is_array($roles) ? implode(', ', $roles) : '';
            $school_name = ($e->school_id && isset($schools[$e->school_id])) ? $schools[$e->school_id] : '-';
            $completion  = isset($rollups[$e->enrollment_id]) ? $rollups[$e->enrollment_id] : 0;
            $team_name   = isset($team_map[$e->enrollment_id]) ? $team_map[$e->enrollment_id] : '-';
            $pw_names    = isset($pathway_names_map[$e->enrollment_id]) ? implode(', ', $pathway_names_map[$e->enrollment_id]) : '-';

            $edit_url   = $base_url . '&sub=edit&enrollment_id=' . $e->enrollment_id;
            $delete_url = wp_nonce_url(
                admin_url('admin.php?page=hl-enrollments&action=delete&id=' . $e->enrollment_id . '&partnership_context=' . $partnership_id),
                'hl_delete_enrollment_' . $e->enrollment_id
            );

            echo '<tr>';
            echo '<td><strong><a href="' . esc_url($edit_url) . '">' . esc_html($e->display_name) . '</a></strong></td>';
            echo '<td>' . esc_html($e->user_email) . '</td>';
            echo '<td>' . esc_html($roles_str) . '</td>';
            echo '<td>' . esc_html($pw_names) . '</td>';
            echo '<td>' . esc_html($team_name) . '</td>';
            echo '<td>' . esc_html($school_name) . '</td>';
            echo '<td>';
            echo '<div style="background:#e0e0e0; border-radius:4px; height:18px; width:100px; display:inline-block; vertical-align:middle;">';
            echo '<div style="background:#00a32a; border-radius:4px; height:18px; width:' . esc_attr(min(100, $completion)) . 'px;"></div>';
            echo '</div> ';
            echo '<span style="font-size:12px;">' . esc_html(number_format($completion, 0)) . '%</span>';
            echo '</td>';
            echo '<td>';
            echo '<a href="' . esc_url($edit_url) . '" class="button button-small">' . esc_html__('Edit', 'hl-core') . '</a> ';
            echo '<a href="' . esc_url($delete_url) . '" class="button button-small button-link-delete" onclick="return confirm(\'' . esc_js(__('Are you sure you want to delete this enrollment?', 'hl-core')) . '\');">' . esc_html__('Delete', 'hl-core') . '</a>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        // Pagination.
        $total_pages = ceil($total / $per_page);
        if ($total_pages > 1) {
            echo '<div class="tablenav bottom"><div class="tablenav-pages">';
            for ($p = 1; $p <= $total_pages; $p++) {
                if ($p === $page_num) {
                    echo '<span class="tablenav-pages-navspan button disabled">' . esc_html($p) . '</span> ';
                } else {
                    echo '<a class="button" href="' . esc_url($base_url . '&paged=' . $p) . '">' . esc_html($p) . '</a> ';
                }
            }
            echo '</div></div>';
        }
    }

    // =========================================================================
    // Tab: Coaching
    // =========================================================================

    private function render_tab_coaching($partnership) {
        global $wpdb;
        $partnership_id = $partnership->partnership_id;

        // Coach assignments.
        $assignments = $wpdb->get_results($wpdb->prepare(
            "SELECT ca.*, u.display_name AS coach_name
             FROM {$wpdb->prefix}hl_coach_assignment ca
             LEFT JOIN {$wpdb->users} u ON ca.coach_user_id = u.ID
             WHERE ca.partnership_id = %d
             ORDER BY ca.effective_from DESC",
            $partnership_id
        ));

        // Coaching sessions (latest 20).
        $sessions = $wpdb->get_results($wpdb->prepare(
            "SELECT cs.*, u_coach.display_name as coach_name, u_mentor.display_name as mentor_name
             FROM {$wpdb->prefix}hl_coaching_session cs
             LEFT JOIN {$wpdb->users} u_coach ON cs.coach_user_id = u_coach.ID
             JOIN {$wpdb->prefix}hl_enrollment e ON cs.mentor_enrollment_id = e.enrollment_id
             LEFT JOIN {$wpdb->users} u_mentor ON e.user_id = u_mentor.ID
             WHERE cs.partnership_id = %d
             ORDER BY cs.session_datetime DESC
             LIMIT 20",
            $partnership_id
        ));

        echo '<div style="margin-bottom:15px; display:flex; gap:8px;">';
        echo '<a href="' . esc_url(admin_url('admin.php?page=hl-coaching&tab=assignments&partnership_id=' . $partnership_id)) . '" class="button button-primary">' . esc_html__('Manage Coach Assignments', 'hl-core') . '</a>';
        echo '<a href="' . esc_url(admin_url('admin.php?page=hl-coaching&partnership_id=' . $partnership_id)) . '" class="button">' . esc_html__('All Coaching Sessions', 'hl-core') . '</a>';
        echo '</div>';

        // Assignments table.
        echo '<h3>' . esc_html__('Coach Assignments', 'hl-core') . '</h3>';
        if (empty($assignments)) {
            echo '<p>' . esc_html__('No coach assignments for this partnership.', 'hl-core') . '</p>';
        } else {
            echo '<table class="widefat striped">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__('Coach', 'hl-core') . '</th>';
            echo '<th>' . esc_html__('Scope', 'hl-core') . '</th>';
            echo '<th>' . esc_html__('From', 'hl-core') . '</th>';
            echo '<th>' . esc_html__('To', 'hl-core') . '</th>';
            echo '<th>' . esc_html__('Status', 'hl-core') . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($assignments as $a) {
                $today  = current_time('Y-m-d');
                $active = ($a->effective_from <= $today && (empty($a->effective_to) || $a->effective_to >= $today));

                echo '<tr>';
                echo '<td><strong>' . esc_html($a->coach_name) . '</strong></td>';
                echo '<td><code>' . esc_html($a->scope_type) . '</code> #' . esc_html($a->scope_id) . '</td>';
                echo '<td>' . esc_html($a->effective_from) . '</td>';
                echo '<td>' . esc_html($a->effective_to ?: '-') . '</td>';
                echo '<td>';
                if ($active) {
                    echo '<span class="hl-status-badge active">' . esc_html__('Active', 'hl-core') . '</span>';
                } else {
                    echo '<span class="hl-status-badge inactive">' . esc_html__('Ended', 'hl-core') . '</span>';
                }
                echo '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }

        // Recent sessions.
        echo '<h3 style="margin-top:20px;">' . esc_html__('Recent Coaching Sessions', 'hl-core') . '</h3>';
        if (empty($sessions)) {
            echo '<p>' . esc_html__('No coaching sessions for this partnership.', 'hl-core') . '</p>';
        } else {
            echo '<table class="widefat striped">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__('Title', 'hl-core') . '</th>';
            echo '<th>' . esc_html__('Participant', 'hl-core') . '</th>';
            echo '<th>' . esc_html__('Coach', 'hl-core') . '</th>';
            echo '<th>' . esc_html__('Date/Time', 'hl-core') . '</th>';
            echo '<th>' . esc_html__('Status', 'hl-core') . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($sessions as $s) {
                $dt = $s->session_datetime ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($s->session_datetime)) : '-';

                echo '<tr>';
                echo '<td><a href="' . esc_url(admin_url('admin.php?page=hl-coaching&action=edit&id=' . $s->session_id)) . '">' . esc_html($s->session_title ?: '#' . $s->session_id) . '</a></td>';
                echo '<td>' . esc_html($s->mentor_name) . '</td>';
                echo '<td>' . esc_html($s->coach_name) . '</td>';
                echo '<td>' . esc_html($dt) . '</td>';
                echo '<td>';
                if (class_exists('HL_Coaching_Service')) {
                    echo HL_Coaching_Service::render_status_badge($s->session_status);
                } else {
                    echo esc_html(ucfirst($s->session_status));
                }
                echo '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }
    }

    // =========================================================================
    // Tab: Classrooms
    // =========================================================================

    private function render_tab_classrooms($partnership) {
        global $wpdb;
        $partnership_id = $partnership->partnership_id;

        // Get schools linked to this partnership.
        $school_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT school_id FROM {$wpdb->prefix}hl_partnership_school WHERE partnership_id = %d",
            $partnership_id
        ));

        if (empty($school_ids)) {
            echo '<p>' . esc_html__('No schools linked to this partnership. Link schools in the Schools tab first.', 'hl-core') . '</p>';
            return;
        }

        $in_ids = implode(',', array_map('intval', $school_ids));

        $classrooms = $wpdb->get_results(
            "SELECT c.*, o.name AS school_name
             FROM {$wpdb->prefix}hl_classroom c
             LEFT JOIN {$wpdb->prefix}hl_orgunit o ON c.school_id = o.orgunit_id
             WHERE c.school_id IN ({$in_ids}) AND c.status = 'active'
             ORDER BY o.name ASC, c.classroom_name ASC"
        );

        // Child counts per classroom.
        $child_counts = array();
        $cc_rows = $wpdb->get_results(
            "SELECT classroom_id, COUNT(*) as cnt
             FROM {$wpdb->prefix}hl_child_classroom_current
             WHERE classroom_id IN (
                 SELECT classroom_id FROM {$wpdb->prefix}hl_classroom WHERE school_id IN ({$in_ids})
             )
             GROUP BY classroom_id",
            ARRAY_A
        );
        foreach ($cc_rows as $r) {
            $child_counts[$r['classroom_id']] = $r['cnt'];
        }

        // Teacher names per classroom (from teaching assignments in this partnership).
        $teacher_names = array();
        $ta_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ta.classroom_id, u.display_name
             FROM {$wpdb->prefix}hl_teaching_assignment ta
             JOIN {$wpdb->prefix}hl_enrollment e ON ta.enrollment_id = e.enrollment_id
             JOIN {$wpdb->users} u ON e.user_id = u.ID
             WHERE e.partnership_id = %d",
            $partnership_id
        ), ARRAY_A);
        foreach ($ta_rows as $r) {
            $teacher_names[$r['classroom_id']][] = $r['display_name'];
        }

        echo '<div style="margin-bottom:15px;">';
        echo '<a href="' . esc_url(admin_url('admin.php?page=hl-classrooms')) . '" class="button button-primary">' . esc_html__('Manage Classrooms', 'hl-core') . '</a>';
        echo '</div>';

        if (empty($classrooms)) {
            echo '<p>' . esc_html__('No classrooms found at linked schools.', 'hl-core') . '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Classroom', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('School', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Age Band', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Children', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Teachers', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Actions', 'hl-core') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($classrooms as $c) {
            $children = isset($child_counts[$c->classroom_id]) ? $child_counts[$c->classroom_id] : 0;
            $teachers = isset($teacher_names[$c->classroom_id]) ? implode(', ', $teacher_names[$c->classroom_id]) : '-';

            echo '<tr>';
            echo '<td><strong>' . esc_html($c->classroom_name) . '</strong></td>';
            echo '<td>' . esc_html($c->school_name) . '</td>';
            echo '<td>' . esc_html($c->age_band ? ucfirst($c->age_band) : '-') . '</td>';
            echo '<td>' . esc_html($children) . '</td>';
            echo '<td>' . esc_html($teachers) . '</td>';
            echo '<td><a href="' . esc_url(admin_url('admin.php?page=hl-classrooms&action=view&id=' . $c->classroom_id)) . '" class="button button-small">' . esc_html__('View', 'hl-core') . '</a></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    // =========================================================================
    // Tab: Assessments
    // =========================================================================

    private function render_tab_assessments($partnership) {
        $partnership_id = absint($partnership->partnership_id);
        $service  = new HL_Assessment_Service();
        $sub_tab  = isset($_GET['assess_tab']) ? sanitize_text_field($_GET['assess_tab']) : 'teacher';
        $base_url = admin_url('admin.php?page=hl-partnerships&action=edit&id=' . $partnership_id . '&tab=assessments');

        // Sub-tab navigation
        echo '<div style="margin-bottom:16px; display:flex; gap:6px;">';
        echo '<a href="' . esc_url($base_url . '&assess_tab=teacher') . '" class="button' . ($sub_tab === 'teacher' ? ' button-primary' : '') . '">'
            . esc_html__('Teacher Self-Assessments', 'hl-core') . '</a>';
        echo '<a href="' . esc_url($base_url . '&assess_tab=children') . '" class="button' . ($sub_tab === 'children' ? ' button-primary' : '') . '">'
            . esc_html__('Child Assessments', 'hl-core') . '</a>';
        echo '</div>';

        if ($sub_tab === 'children') {
            $this->render_assessments_children($partnership_id, $service);
        } else {
            $this->render_assessments_teacher($partnership_id, $service);
        }
    }

    /**
     * Teacher Self-Assessment sub-tab within the partnership Assessments tab.
     */
    private function render_assessments_teacher($partnership_id, $service) {
        $instances = $service->get_teacher_assessments_by_partnership($partnership_id);

        // Export buttons
        $export_completion_url = wp_nonce_url(
            admin_url('admin.php?page=hl-assessments&partnership_id=' . $partnership_id . '&export=teacher'),
            'hl_export_teacher_' . $partnership_id
        );
        $export_responses_url = wp_nonce_url(
            admin_url('admin.php?page=hl-assessments&partnership_id=' . $partnership_id . '&export=teacher_responses'),
            'hl_export_teacher_responses_' . $partnership_id
        );
        echo '<div style="margin-bottom:12px; display:flex; gap:6px;">';
        echo '<a href="' . esc_url($export_completion_url) . '" class="button button-small">' . esc_html__('Export Completion CSV', 'hl-core') . '</a>';
        echo '<a href="' . esc_url($export_responses_url) . '" class="button button-small">' . esc_html__('Export Responses CSV', 'hl-core') . '</a>';
        echo '</div>';

        if (empty($instances)) {
            echo '<p>' . esc_html__('No teacher assessment instances found for this partnership.', 'hl-core') . '</p>';
            return;
        }

        // Summary cards
        $total     = count($instances);
        $submitted = count(array_filter($instances, function($i) { return $i['status'] === 'submitted'; }));
        $pending   = $total - $submitted;

        echo '<div class="hl-metrics-row">';
        echo '<div class="hl-metric-card"><div class="metric-value">' . esc_html($total) . '</div><div class="metric-label">' . esc_html__('Total', 'hl-core') . '</div></div>';
        echo '<div class="hl-metric-card"><div class="metric-value">' . esc_html($submitted) . '</div><div class="metric-label">' . esc_html__('Submitted', 'hl-core') . '</div></div>';
        echo '<div class="hl-metric-card"><div class="metric-value">' . esc_html($pending) . '</div><div class="metric-label">' . esc_html__('Pending', 'hl-core') . '</div></div>';
        echo '</div>';

        // Table
        $phase_counts = array();
        foreach ($instances as $inst) {
            $p = $inst['phase'];
            $phase_counts[$p] = isset($phase_counts[$p]) ? $phase_counts[$p] + 1 : 1;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Teacher', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Phase', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Status', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Submitted', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Actions', 'hl-core') . '</th>';
        echo '</tr></thead><tbody>';

        $current_phase = '';
        foreach ($instances as $inst) {
            if ($inst['phase'] !== $current_phase) {
                $current_phase = $inst['phase'];
                $phase_label = strtoupper($current_phase) === 'POST' ? __('POST-Assessment', 'hl-core') : __('PRE-Assessment', 'hl-core');
                $phase_cnt = isset($phase_counts[$current_phase]) ? $phase_counts[$current_phase] : 0;
                echo '<tr><td colspan="5" style="background:#f0f6fc;font-weight:700;padding:10px 12px;font-size:13px;border-left:4px solid #2271b1;">'
                    . esc_html($phase_label) . ' <span style="color:#646970;font-weight:400;">(' . $phase_cnt . ')</span></td></tr>';
            }

            $view_url = admin_url('admin.php?page=hl-assessment-hub&section=teacher-assessments&action=view_teacher&instance_id=' . $inst['instance_id'] . '&partnership_id=' . $partnership_id);

            echo '<tr>';
            echo '<td><strong>' . esc_html($inst['display_name']) . '</strong></td>';
            echo '<td><span style="text-transform:uppercase;font-weight:600;">' . esc_html($inst['phase']) . '</span></td>';
            echo '<td>' . $this->render_assessment_status($inst['status']) . '</td>';
            echo '<td>' . esc_html($inst['submitted_at'] ?: '-') . '</td>';
            echo '<td><a href="' . esc_url($view_url) . '" class="button button-small">' . esc_html__('View', 'hl-core') . '</a></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * Child Assessment sub-tab within the partnership Assessments tab.
     */
    private function render_assessments_children($partnership_id, $service) {
        $instances = $service->get_child_assessments_by_partnership($partnership_id);

        // Action buttons
        echo '<div style="margin-bottom:12px; display:flex; gap:6px; flex-wrap:wrap;">';

        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=hl-assessment-hub&section=child-assessments&partnership_id=' . $partnership_id)) . '" style="display:inline;">';
        wp_nonce_field('hl_generate_children_instances', 'hl_generate_children_nonce');
        echo '<input type="hidden" name="partnership_id" value="' . esc_attr($partnership_id) . '" />';
        submit_button(
            __('Generate Instances', 'hl-core'),
            'secondary small',
            'submit',
            false,
            array('onclick' => 'return confirm("' . esc_js(__('Create child assessment instances for all teaching assignments in this partnership?', 'hl-core')) . '");')
        );
        echo '</form>';

        $export_completion_url = wp_nonce_url(
            admin_url('admin.php?page=hl-assessments&partnership_id=' . $partnership_id . '&export=children'),
            'hl_export_children_' . $partnership_id
        );
        $export_responses_url = wp_nonce_url(
            admin_url('admin.php?page=hl-assessments&partnership_id=' . $partnership_id . '&export=children_responses'),
            'hl_export_children_responses_' . $partnership_id
        );
        echo '<a href="' . esc_url($export_completion_url) . '" class="button button-small">' . esc_html__('Export Completion CSV', 'hl-core') . '</a>';
        echo '<a href="' . esc_url($export_responses_url) . '" class="button button-small">' . esc_html__('Export Responses CSV', 'hl-core') . '</a>';
        echo '</div>';

        if (empty($instances)) {
            echo '<p>' . esc_html__('No child assessment instances found. Use "Generate Instances" to create them.', 'hl-core') . '</p>';
            return;
        }

        // Summary cards
        $total       = count($instances);
        $submitted   = count(array_filter($instances, function($i) { return $i['status'] === 'submitted'; }));
        $in_progress = count(array_filter($instances, function($i) { return $i['status'] === 'in_progress'; }));
        $not_started = $total - $submitted - $in_progress;

        echo '<div class="hl-metrics-row">';
        echo '<div class="hl-metric-card"><div class="metric-value">' . esc_html($total) . '</div><div class="metric-label">' . esc_html__('Total', 'hl-core') . '</div></div>';
        echo '<div class="hl-metric-card"><div class="metric-value">' . esc_html($submitted) . '</div><div class="metric-label">' . esc_html__('Submitted', 'hl-core') . '</div></div>';
        echo '<div class="hl-metric-card"><div class="metric-value">' . esc_html($in_progress) . '</div><div class="metric-label">' . esc_html__('In Progress', 'hl-core') . '</div></div>';
        echo '<div class="hl-metric-card"><div class="metric-value">' . esc_html($not_started) . '</div><div class="metric-label">' . esc_html__('Not Started', 'hl-core') . '</div></div>';
        echo '</div>';

        // Table
        $phase_counts = array();
        foreach ($instances as $inst) {
            $p = isset($inst['phase']) ? $inst['phase'] : '';
            $phase_counts[$p] = isset($phase_counts[$p]) ? $phase_counts[$p] + 1 : 1;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Teacher', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Phase', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Classroom', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Age Band', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Status', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Submitted', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Actions', 'hl-core') . '</th>';
        echo '</tr></thead><tbody>';

        $current_phase = null;
        foreach ($instances as $inst) {
            $inst_phase = isset($inst['phase']) ? $inst['phase'] : '';

            if ($inst_phase !== $current_phase) {
                $current_phase = $inst_phase;
                $phase_label = strtoupper($current_phase) === 'POST' ? __('POST-Assessment', 'hl-core') : __('PRE-Assessment', 'hl-core');
                $phase_cnt = isset($phase_counts[$current_phase]) ? $phase_counts[$current_phase] : 0;
                echo '<tr><td colspan="7" style="background:#f0f6fc;font-weight:700;padding:10px 12px;font-size:13px;border-left:4px solid #2271b1;">'
                    . esc_html($phase_label) . ' <span style="color:#646970;font-weight:400;">(' . $phase_cnt . ')</span></td></tr>';
            }

            $view_url = admin_url('admin.php?page=hl-assessment-hub&section=child-assessments&action=view_children&instance_id=' . $inst['instance_id'] . '&partnership_id=' . $partnership_id);

            echo '<tr>';
            echo '<td><strong>' . esc_html($inst['display_name']) . '</strong></td>';
            echo '<td><span style="text-transform:uppercase;font-weight:600;">' . esc_html($inst_phase) . '</span></td>';
            echo '<td>' . esc_html($inst['classroom_name']) . '</td>';
            echo '<td>' . esc_html($inst['instrument_age_band'] ? ucfirst($inst['instrument_age_band']) : '-') . '</td>';
            echo '<td>' . $this->render_assessment_status($inst['status']) . '</td>';
            echo '<td>' . esc_html($inst['submitted_at'] ?: '-') . '</td>';
            echo '<td><a href="' . esc_url($view_url) . '" class="button button-small">' . esc_html__('View', 'hl-core') . '</a></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * Render a status badge for assessment instances.
     */
    private function render_assessment_status($status) {
        $css_map = array(
            'not_started' => 'hl-status hl-status-draft',
            'in_progress' => 'hl-status hl-status-progress',
            'submitted'   => 'hl-status hl-status-complete',
        );
        $labels = array(
            'not_started' => __('Not Started', 'hl-core'),
            'in_progress' => __('In Progress', 'hl-core'),
            'submitted'   => __('Submitted', 'hl-core'),
        );
        $class = isset($css_map[$status]) ? $css_map[$status] : 'hl-status hl-status-draft';
        $label = isset($labels[$status]) ? $labels[$status] : ucfirst($status);
        return '<span class="' . esc_attr($class) . '">' . esc_html($label) . '</span>';
    }

    // =========================================================================
    // Emails Tab (Control Group Partnerships)
    // =========================================================================

    /**
     * Render the Emails tab: shows recipient list and Send button.
     *
     * "Existing" users = registered before 2026-01-01 (had accounts before HL Core).
     * "New" users = registered on/after 2026-01-01 (accounts created by the seeder).
     */
    private function render_tab_emails($partnership) {
        global $wpdb;

        $partnership_id = absint($partnership->partnership_id);

        // Get phases for this partnership
        $phases = $wpdb->get_results($wpdb->prepare(
            "SELECT phase_id, phase_name, phase_number
             FROM {$wpdb->prefix}hl_phase
             WHERE partnership_id = %d
             ORDER BY phase_number",
            $partnership_id
        ), ARRAY_A);

        if (empty($phases)) {
            echo '<div class="notice notice-warning" style="margin:0;"><p>'
                . esc_html__('No phases found for this partnership. Create a phase first before sending emails.', 'hl-core')
                . '</p></div>';
            return;
        }

        $selected_phase_id = absint($phases[0]['phase_id']);
        $nonce = wp_create_nonce('hl_send_partnership_emails');
        $reset_nonce = wp_create_nonce('hl_reset_email_log');

        // Phase selector
        echo '<div class="hl-form-section" style="margin-bottom:16px;">';
        echo '<label for="hl-email-phase-select" style="font-weight:600;margin-right:8px;">' . esc_html__('Select Phase:', 'hl-core') . '</label>';
        echo '<select id="hl-email-phase-select" style="min-width:300px;">';
        foreach ($phases as $phase) {
            echo '<option value="' . esc_attr($phase['phase_id']) . '">'
                . esc_html('Phase ' . $phase['phase_number'] . ': ' . $phase['phase_name'])
                . '</option>';
        }
        echo '</select>';
        echo '</div>';

        // Container for AJAX-loaded recipient tables
        echo '<div id="hl-email-recipients-container">';
        $this->render_email_recipients($partnership_id, $selected_phase_id);
        echo '</div>';

        // Inline JS
        ?>
        <script>
        (function(){
            var partnershipId = <?php echo (int) $partnership_id; ?>;
            var nonce = '<?php echo esc_js($nonce); ?>';
            var resetNonce = '<?php echo esc_js($reset_nonce); ?>';
            var ajaxUrl = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
            var container = document.getElementById('hl-email-recipients-container');
            var phaseSelect = document.getElementById('hl-email-phase-select');

            // Phase change → reload recipients
            phaseSelect.addEventListener('change', function() {
                container.innerHTML = '<p><span class="dashicons dashicons-update" style="animation:rotation 1s linear infinite;"></span> Loading...</p>';
                var xhr = new XMLHttpRequest();
                xhr.open('POST', ajaxUrl);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onload = function() {
                    try {
                        var resp = JSON.parse(xhr.responseText);
                        if (resp.success) {
                            container.innerHTML = resp.data.html;
                            bindEmailEvents();
                        } else {
                            container.innerHTML = '<div class="notice notice-error"><p>' + (resp.data || 'Error') + '</p></div>';
                        }
                    } catch(e) {
                        container.innerHTML = '<div class="notice notice-error"><p>Unexpected error.</p></div>';
                    }
                };
                xhr.send('action=hl_send_partnership_emails&sub_action=load_recipients&partnership_id=' + partnershipId + '&phase_id=' + phaseSelect.value + '&_wpnonce=' + nonce);
            });

            function getSelectedUserIds() {
                var checked = container.querySelectorAll('.hl-email-cb:checked');
                var ids = [];
                checked.forEach(function(cb) { ids.push(cb.value); });
                return ids;
            }

            function updateSendButton() {
                var btn = container.querySelector('#hl-send-selected-btn');
                if (!btn) return;
                var ids = getSelectedUserIds();
                btn.textContent = '<?php echo esc_js(__('Send to Selected', 'hl-core')); ?> (' + ids.length + ')';
                btn.disabled = ids.length === 0;
            }

            function bindEmailEvents() {
                // Checkboxes
                container.querySelectorAll('.hl-email-cb').forEach(function(cb) {
                    cb.addEventListener('change', updateSendButton);
                });

                // Select All
                container.querySelectorAll('.hl-email-select-all').forEach(function(sa) {
                    sa.addEventListener('change', function() {
                        var table = sa.closest('table');
                        table.querySelectorAll('.hl-email-cb:not(:disabled)').forEach(function(cb) {
                            cb.checked = sa.checked;
                        });
                        updateSendButton();
                    });
                });

                // Send button
                var sendBtn = container.querySelector('#hl-send-selected-btn');
                if (sendBtn) {
                    sendBtn.addEventListener('click', function() {
                        var ids = getSelectedUserIds();
                        if (ids.length === 0) return;
                        if (!confirm('<?php echo esc_js(__('Send invitation emails to the selected recipients?', 'hl-core')); ?>')) return;

                        sendBtn.disabled = true;
                        sendBtn.innerHTML = '<span class="dashicons dashicons-update" style="margin-top:3px;margin-right:4px;animation:rotation 1s linear infinite;"></span> <?php echo esc_js(__('Sending...', 'hl-core')); ?>';

                        var xhr = new XMLHttpRequest();
                        xhr.open('POST', ajaxUrl);
                        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                        xhr.onload = function() {
                            try {
                                var resp = JSON.parse(xhr.responseText);
                                if (resp.success) {
                                    // Reload the recipients to show updated statuses
                                    var xhr2 = new XMLHttpRequest();
                                    xhr2.open('POST', ajaxUrl);
                                    xhr2.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                                    xhr2.onload = function() {
                                        try {
                                            var resp2 = JSON.parse(xhr2.responseText);
                                            if (resp2.success) {
                                                container.innerHTML = '<div class="notice notice-success" style="margin:0 0 16px;"><p>' + resp.data.message + '</p></div>' + resp2.data.html;
                                                bindEmailEvents();
                                            }
                                        } catch(e) {}
                                    };
                                    xhr2.send('action=hl_send_partnership_emails&sub_action=load_recipients&partnership_id=' + partnershipId + '&phase_id=' + phaseSelect.value + '&_wpnonce=' + nonce);
                                } else {
                                    sendBtn.disabled = false;
                                    sendBtn.textContent = '<?php echo esc_js(__('Send to Selected', 'hl-core')); ?> (' + ids.length + ')';
                                    alert(resp.data || 'Error sending emails.');
                                }
                            } catch(e) {
                                sendBtn.disabled = false;
                                alert('Unexpected error.');
                            }
                        };
                        xhr.send('action=hl_send_partnership_emails&sub_action=send&partnership_id=' + partnershipId + '&phase_id=' + phaseSelect.value + '&user_ids=' + ids.join(',') + '&_wpnonce=' + nonce);
                    });
                }

                // Reset buttons
                container.querySelectorAll('.hl-email-reset-btn').forEach(function(btn) {
                    btn.addEventListener('click', function(e) {
                        e.preventDefault();
                        var userId = btn.dataset.userId;
                        if (!confirm('<?php echo esc_js(__('Reset this user\'s sent status? They can be re-sent an email.', 'hl-core')); ?>')) return;

                        btn.style.opacity = '0.5';
                        var xhr = new XMLHttpRequest();
                        xhr.open('POST', ajaxUrl);
                        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                        xhr.onload = function() {
                            try {
                                var resp = JSON.parse(xhr.responseText);
                                if (resp.success) {
                                    // Reload recipients
                                    var xhr2 = new XMLHttpRequest();
                                    xhr2.open('POST', ajaxUrl);
                                    xhr2.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                                    xhr2.onload = function() {
                                        try {
                                            var resp2 = JSON.parse(xhr2.responseText);
                                            if (resp2.success) {
                                                container.innerHTML = resp2.data.html;
                                                bindEmailEvents();
                                            }
                                        } catch(e) {}
                                    };
                                    xhr2.send('action=hl_send_partnership_emails&sub_action=load_recipients&partnership_id=' + partnershipId + '&phase_id=' + phaseSelect.value + '&_wpnonce=' + nonce);
                                } else {
                                    btn.style.opacity = '1';
                                    alert(resp.data || 'Error resetting.');
                                }
                            } catch(e) {
                                btn.style.opacity = '1';
                            }
                        };
                        xhr.send('action=hl_reset_email_log&partnership_id=' + partnershipId + '&phase_id=' + phaseSelect.value + '&user_id=' + userId + '&_wpnonce=' + resetNonce);
                    });
                });

                updateSendButton();
            }

            // Bind events for initial server-rendered content
            bindEmailEvents();
        })();
        </script>
        <style>@keyframes rotation{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}</style>
        <?php
    }

    /**
     * Render the email recipients HTML for a specific partnership + phase.
     * Used both on initial page load and via AJAX reload.
     */
    private function render_email_recipients($partnership_id, $phase_id) {
        global $wpdb;

        $enrollments = $wpdb->get_results($wpdb->prepare(
            "SELECT e.enrollment_id, e.user_id, u.user_email, u.display_name, u.user_login,
                    u.user_registered, o.name AS school_name,
                    el.sent_at, el.email_type AS log_email_type
             FROM {$wpdb->prefix}hl_enrollment e
             JOIN {$wpdb->users} u ON e.user_id = u.ID
             LEFT JOIN {$wpdb->prefix}hl_orgunit o ON e.school_id = o.orgunit_id
             LEFT JOIN {$wpdb->prefix}hl_track_email_log el
                 ON el.partnership_id = e.partnership_id AND el.phase_id = %d AND el.user_id = e.user_id
             WHERE e.partnership_id = %d AND e.status = 'active'
             ORDER BY u.display_name",
            $phase_id, $partnership_id
        ), ARRAY_A);

        $cutoff = '2026-01-01 00:00:00';
        $existing = array();
        $new_users = array();
        foreach ($enrollments as $enr) {
            if ($enr['user_registered'] < $cutoff) {
                $existing[] = $enr;
            } else {
                $new_users[] = $enr;
            }
        }

        $this->render_email_table(
            __('Existing Users', 'hl-core'),
            __('Receives: "Log In to Your Account" email', 'hl-core'),
            $existing
        );
        $this->render_email_table(
            __('New Users', 'hl-core'),
            __('Receives: "Accept Invitation & Set Password" email', 'hl-core'),
            $new_users
        );

        // Send button
        $unsent = 0;
        foreach ($enrollments as $enr) {
            if (empty($enr['sent_at'])) $unsent++;
        }
        echo '<div style="margin-top:20px;">';
        echo '<button id="hl-send-selected-btn" class="button button-primary" disabled>'
            . esc_html__('Send to Selected', 'hl-core') . ' (0)'
            . '</button>';
        echo '<span style="margin-left:12px;color:#6B7280;font-size:13px;">'
            . sprintf(esc_html__('%d of %d not yet sent', 'hl-core'), $unsent, count($enrollments))
            . '</span>';
        echo '</div>';
    }

    /**
     * Render a single email recipient table (existing or new users).
     */
    private function render_email_table($title, $description, $rows) {
        $sent_count = 0;
        foreach ($rows as $r) {
            if (!empty($r['sent_at'])) $sent_count++;
        }

        echo '<div class="hl-form-section" style="margin-bottom:16px;">';
        echo '<h3 class="hl-form-section-title">'
            . esc_html($title)
            . ' <span style="font-weight:400;">(' . count($rows) . ')</span>'
            . ' &mdash; <span style="font-weight:400;font-size:11px;">' . esc_html($description) . '</span>'
            . '</h3>';

        if (empty($rows)) {
            echo '<p>' . esc_html__('None.', 'hl-core') . '</p></div>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th style="width:30px;"><input type="checkbox" class="hl-email-select-all" /></th>';
        echo '<th>' . esc_html__('Name', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Email', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('School', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Status', 'hl-core') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($rows as $enr) {
            $is_sent = !empty($enr['sent_at']);
            echo '<tr>';

            // Checkbox
            echo '<td>';
            if ($is_sent) {
                echo '<input type="checkbox" disabled />';
            } else {
                echo '<input type="checkbox" class="hl-email-cb" value="' . esc_attr($enr['user_id']) . '" />';
            }
            echo '</td>';

            // Name, Email, School
            echo '<td>' . esc_html($enr['display_name']) . '</td>';
            echo '<td>' . esc_html($enr['user_email']) . '</td>';
            echo '<td>' . esc_html($enr['school_name'] ?: '-') . '</td>';

            // Status
            echo '<td>';
            if ($is_sent) {
                $sent_date = wp_date('M j, Y', strtotime($enr['sent_at']));
                echo '<span style="color:#00a32a;">&#10003; ' . esc_html__('Sent', 'hl-core') . '</span>'
                    . ' <span style="color:#6B7280;font-size:12px;">&middot; ' . esc_html($sent_date) . '</span>'
                    . ' <a href="#" class="hl-email-reset-btn" data-user-id="' . esc_attr($enr['user_id']) . '" title="' . esc_attr__('Reset — allow re-sending', 'hl-core') . '" style="margin-left:4px;text-decoration:none;color:#d63638;">&#8634;</a>';
            } else {
                echo '<span style="color:#6B7280;">&mdash; ' . esc_html__('Not Sent', 'hl-core') . '</span>';
            }
            echo '</td>';

            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    /**
     * AJAX handler: send emails or load recipients.
     */
    public function ajax_send_partnership_emails() {
        check_ajax_referer('hl_send_partnership_emails');

        if (!current_user_can('manage_hl_core')) {
            wp_send_json_error(__('Permission denied.', 'hl-core'));
        }

        $sub_action = isset($_POST['sub_action']) ? sanitize_text_field($_POST['sub_action']) : 'send';
        $partnership_id = isset($_POST['partnership_id']) ? absint($_POST['partnership_id']) : 0;
        $phase_id = isset($_POST['phase_id']) ? absint($_POST['phase_id']) : 0;

        if (!$partnership_id || !$phase_id) {
            wp_send_json_error(__('Invalid partnership or phase.', 'hl-core'));
        }

        global $wpdb;

        // Validate phase belongs to partnership
        $phase = $wpdb->get_row($wpdb->prepare(
            "SELECT phase_id, phase_name FROM {$wpdb->prefix}hl_phase WHERE phase_id = %d AND partnership_id = %d",
            $phase_id, $partnership_id
        ));
        if (!$phase) {
            wp_send_json_error(__('Phase not found for this partnership.', 'hl-core'));
        }

        // Load recipients sub-action
        if ($sub_action === 'load_recipients') {
            ob_start();
            $this->render_email_recipients($partnership_id, $phase_id);
            $html = ob_get_clean();
            wp_send_json_success(array('html' => $html));
        }

        // Send sub-action
        $user_ids_raw = isset($_POST['user_ids']) ? sanitize_text_field($_POST['user_ids']) : '';
        $user_ids = array_filter(array_map('absint', explode(',', $user_ids_raw)));

        if (empty($user_ids)) {
            wp_send_json_error(__('No recipients selected.', 'hl-core'));
        }

        // Validate users are enrolled in this partnership
        $placeholders = implode(',', array_fill(0, count($user_ids), '%d'));
        $query_args = array_merge(array($partnership_id), $user_ids);
        $enrolled_users = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT e.user_id, u.user_email, u.display_name, u.user_login,
                        u.user_registered, o.name AS school_name
                 FROM {$wpdb->prefix}hl_enrollment e
                 JOIN {$wpdb->users} u ON e.user_id = u.ID
                 LEFT JOIN {$wpdb->prefix}hl_orgunit o ON e.school_id = o.orgunit_id
                 WHERE e.partnership_id = %d AND e.status = 'active' AND e.user_id IN ($placeholders)",
                $query_args
            ),
            ARRAY_A
        );

        if (empty($enrolled_users)) {
            wp_send_json_error(__('No valid enrolled users found.', 'hl-core'));
        }

        $cutoff = '2026-01-01 00:00:00';
        $sent_count = 0;
        $skipped = 0;
        $errors = array();
        $current_user_id = get_current_user_id();
        $now = current_time('mysql');

        foreach ($enrolled_users as $enr) {
            // Check if already sent (belt-and-suspenders)
            $already_sent = $wpdb->get_var($wpdb->prepare(
                "SELECT log_id FROM {$wpdb->prefix}hl_track_email_log
                 WHERE partnership_id = %d AND phase_id = %d AND user_id = %d",
                $partnership_id, $phase_id, $enr['user_id']
            ));
            if ($already_sent) {
                $skipped++;
                continue;
            }

            $is_new = ($enr['user_registered'] >= $cutoff);
            $first_name = explode(' ', trim($enr['display_name']))[0];
            $email_type = $is_new ? 'new' : 'existing';

            if ($is_new) {
                $reset_key = get_password_reset_key(get_user_by('id', $enr['user_id']));
                if (is_wp_error($reset_key)) {
                    $errors[] = $enr['user_email'] . ': ' . $reset_key->get_error_message();
                    continue;
                }
                $subject = __('Welcome to Housman Learning Academy — Set Your Password', 'hl-core');
                $body = $this->build_email_new($first_name, $enr['user_email'], $enr['user_login'], $enr['school_name'], $reset_key);
            } else {
                $subject = __('Housman Learning Academy — Your Assessment is Ready', 'hl-core');
                $body = $this->build_email_existing($first_name);
            }

            $headers = array('Content-Type: text/html; charset=UTF-8');
            $sent = wp_mail($enr['user_email'], $subject, $body, $headers);

            if ($sent) {
                // INSERT IGNORE for hard DB-level dedup
                $wpdb->query($wpdb->prepare(
                    "INSERT IGNORE INTO {$wpdb->prefix}hl_track_email_log
                     (partnership_id, phase_id, user_id, email_type, recipient_email, sent_at, sent_by)
                     VALUES (%d, %d, %d, %s, %s, %s, %d)",
                    $partnership_id, $phase_id, $enr['user_id'], $email_type,
                    $enr['user_email'], $now, $current_user_id
                ));
                $sent_count++;
            } else {
                $errors[] = $enr['user_email'] . ': wp_mail failed';
            }
        }

        $message = sprintf(__('%d emails sent successfully.', 'hl-core'), $sent_count);
        if ($skipped > 0) {
            $message .= ' ' . sprintf(__('%d skipped (already sent).', 'hl-core'), $skipped);
        }
        if (!empty($errors)) {
            $message .= ' ' . sprintf(__('Errors: %s', 'hl-core'), implode('; ', $errors));
        }

        wp_send_json_success(array('message' => $message, 'sent' => $sent_count, 'skipped' => $skipped));
    }

    /**
     * AJAX handler: reset a user's email log entry to allow re-sending.
     */
    public function ajax_reset_email_log() {
        check_ajax_referer('hl_reset_email_log');

        if (!current_user_can('manage_hl_core')) {
            wp_send_json_error(__('Permission denied.', 'hl-core'));
        }

        $partnership_id = isset($_POST['partnership_id']) ? absint($_POST['partnership_id']) : 0;
        $phase_id = isset($_POST['phase_id']) ? absint($_POST['phase_id']) : 0;
        $user_id  = isset($_POST['user_id'])  ? absint($_POST['user_id'])  : 0;

        if (!$partnership_id || !$phase_id || !$user_id) {
            wp_send_json_error(__('Missing parameters.', 'hl-core'));
        }

        global $wpdb;
        $wpdb->delete(
            $wpdb->prefix . 'hl_track_email_log',
            array('partnership_id' => $partnership_id, 'phase_id' => $phase_id, 'user_id' => $user_id),
            array('%d', '%d', '%d')
        );

        wp_send_json_success(array('message' => __('Reset successful.', 'hl-core')));
    }

    /**
     * Build HTML email for existing users (log in).
     */
    private function build_email_existing($first_name) {
        $logo_url  = 'https://academy.housmanlearning.com/wp-content/uploads/2024/09/Housman-Learning-Logo-Horizontal-Color.svg';
        $login_url = 'https://academy.housmanlearning.com/wp-login.php';
        $reset_url = 'https://academy.housmanlearning.com/wp-login.php?action=lostpassword';

        return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:0;background:#F4F5F7;">
<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="max-width:600px;margin:0 auto;">
<tr><td style="background:#1A2B47;padding:32px 40px;text-align:center;border-radius:12px 12px 0 0;">
<img src="' . esc_url($logo_url) . '" alt="Housman Learning" width="200" style="display:inline-block;max-width:200px;" />
</td></tr>
<tr><td style="background:#FFFFFF;padding:40px;">
<p style="margin:0 0 24px;font-size:18px;font-weight:600;color:#1A2B47;">Hello ' . esc_html($first_name) . ',</p>
<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#374151;">You have been enrolled in a research study through <strong>Housman Learning Academy</strong> as part of the Lutheran Services Florida partnership.</p>
<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#374151;">Your account is ready and your assessment activities are waiting for you. Please log in to get started with your <strong>Teacher Self-Assessment (Pre)</strong>.</p>
<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:32px 0;"><tr><td align="center">
<a href="' . esc_url($login_url) . '" style="display:inline-block;background:#2ECC71;color:#FFFFFF;font-size:16px;font-weight:600;text-decoration:none;padding:14px 40px;border-radius:8px;">Log In to Your Account</a>
</td></tr></table>
<div style="background:#F4F5F7;border-radius:8px;padding:20px 24px;margin:24px 0 0;">
<p style="margin:0 0 12px;font-size:14px;font-weight:600;color:#1A2B47;">What to expect:</p>
<table role="presentation" cellpadding="0" cellspacing="0" width="100%">
<tr><td style="padding:4px 0;font-size:14px;line-height:1.5;color:#374151;"><span style="color:#2ECC71;font-weight:bold;margin-right:8px;">1.</span> Complete the <strong>Teacher Self-Assessment (Pre)</strong></td></tr>
<tr><td style="padding:4px 0;font-size:14px;line-height:1.5;color:#374151;"><span style="color:#2ECC71;font-weight:bold;margin-right:8px;">2.</span> Complete the <strong>Child Assessment (Pre)</strong> for your classroom</td></tr>
<tr><td style="padding:4px 0;font-size:14px;line-height:1.5;color:#374151;"><span style="color:#2ECC71;font-weight:bold;margin-right:8px;">3.</span> Post assessments will be available later in the program</td></tr>
</table></div>
<p style="margin:24px 0 0;font-size:13px;line-height:1.5;color:#6B7280;">If you have trouble logging in, please use the <a href="' . esc_url($reset_url) . '" style="color:#2C7BE5;text-decoration:none;">password reset</a> option or contact your program coordinator.</p>
</td></tr>
<tr><td style="background:#F4F5F7;padding:24px 40px;text-align:center;border-top:1px solid #E5E7EB;border-radius:0 0 12px 12px;">
<p style="margin:0 0 8px;font-size:13px;color:#6B7280;">Housman Learning Academy</p>
<p style="margin:0;font-size:12px;color:#9CA3AF;">This email was sent because you are enrolled in a research partnership.<br>Please do not reply to this email.</p>
</td></tr>
</table></body></html>';
    }

    /**
     * Build HTML email for new users (set password invitation).
     */
    private function build_email_new($first_name, $email, $user_login, $school_name, $reset_key) {
        $logo_url  = 'https://academy.housmanlearning.com/wp-content/uploads/2024/09/Housman-Learning-Logo-Horizontal-Color.svg';
        $invite_url = 'https://academy.housmanlearning.com/wp-login.php?action=rp&key=' . rawurlencode($reset_key) . '&login=' . rawurlencode($user_login);
        $reset_url  = 'https://academy.housmanlearning.com/wp-login.php?action=lostpassword';

        return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:0;background:#F4F5F7;">
<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="max-width:600px;margin:0 auto;">
<tr><td style="background:#1A2B47;padding:32px 40px;text-align:center;border-radius:12px 12px 0 0;">
<img src="' . esc_url($logo_url) . '" alt="Housman Learning" width="200" style="display:inline-block;max-width:200px;" />
</td></tr>
<tr><td style="background:#FFFFFF;padding:40px;">
<p style="margin:0 0 24px;font-size:18px;font-weight:600;color:#1A2B47;">Hello ' . esc_html($first_name) . ',</p>
<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#374151;">You have been invited to participate in a research study through <strong>Housman Learning Academy</strong> in partnership with <strong>Lutheran Services Florida</strong>.</p>
<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#374151;">An account has been created for you. To get started, please click the button below to set your password and access your assessments.</p>
<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:32px 0;"><tr><td align="center">
<a href="' . esc_url($invite_url) . '" style="display:inline-block;background:#2ECC71;color:#FFFFFF;font-size:16px;font-weight:600;text-decoration:none;padding:14px 40px;border-radius:8px;">Accept Invitation &amp; Set Password</a>
</td></tr></table>
<div style="background:#DBEAFE;border-radius:8px;padding:20px 24px;margin:0 0 24px;border-left:4px solid #2C7BE5;">
<p style="margin:0 0 8px;font-size:14px;font-weight:600;color:#1A2B47;">Your account details:</p>
<table role="presentation" cellpadding="0" cellspacing="0">
<tr><td style="padding:2px 12px 2px 0;font-size:14px;color:#6B7280;">Email:</td><td style="padding:2px 0;font-size:14px;font-weight:600;color:#374151;">' . esc_html($email) . '</td></tr>
<tr><td style="padding:2px 12px 2px 0;font-size:14px;color:#6B7280;">School:</td><td style="padding:2px 0;font-size:14px;font-weight:600;color:#374151;">' . esc_html($school_name ?: '-') . '</td></tr>
</table></div>
<div style="background:#F4F5F7;border-radius:8px;padding:20px 24px;margin:0;">
<p style="margin:0 0 12px;font-size:14px;font-weight:600;color:#1A2B47;">What to expect:</p>
<table role="presentation" cellpadding="0" cellspacing="0" width="100%">
<tr><td style="padding:4px 0;font-size:14px;line-height:1.5;color:#374151;"><span style="color:#2ECC71;font-weight:bold;margin-right:8px;">1.</span> Set your password using the button above</td></tr>
<tr><td style="padding:4px 0;font-size:14px;line-height:1.5;color:#374151;"><span style="color:#2ECC71;font-weight:bold;margin-right:8px;">2.</span> Complete the <strong>Teacher Self-Assessment (Pre)</strong></td></tr>
<tr><td style="padding:4px 0;font-size:14px;line-height:1.5;color:#374151;"><span style="color:#2ECC71;font-weight:bold;margin-right:8px;">3.</span> Complete the <strong>Child Assessment (Pre)</strong> for your classroom</td></tr>
<tr><td style="padding:4px 0;font-size:14px;line-height:1.5;color:#374151;"><span style="color:#2ECC71;font-weight:bold;margin-right:8px;">4.</span> Post assessments will be available later in the program</td></tr>
</table></div>
<p style="margin:24px 0 0;font-size:13px;line-height:1.5;color:#6B7280;">This invitation link expires in <strong>7 days</strong>. If the link has expired, you can request a new one at the <a href="' . esc_url($reset_url) . '" style="color:#2C7BE5;text-decoration:none;">password reset page</a>.</p>
</td></tr>
<tr><td style="background:#F4F5F7;padding:24px 40px;text-align:center;border-top:1px solid #E5E7EB;border-radius:0 0 12px 12px;">
<p style="margin:0 0 8px;font-size:13px;color:#6B7280;">Housman Learning Academy</p>
<p style="margin:0;font-size:12px;color:#9CA3AF;">This email was sent because you were invited to participate in a research partnership.<br>Please do not reply to this email.</p>
</td></tr>
</table></body></html>';
    }
}
