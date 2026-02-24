<?php if (!defined('ABSPATH')) exit;

/**
 * Admin Cohorts Page
 *
 * Full CRUD admin page with tabbed editor for Cohorts.
 *
 * @package HL_Core
 */
class HL_Admin_Cohorts {

    /** @var HL_Admin_Cohorts|null */
    private static $instance = null;

    /** @var HL_Cohort_Repository */
    private $repo;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->repo = new HL_Cohort_Repository();
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

        if ($action === 'link_center') {
            $this->handle_link_center();
        }

        if ($action === 'unlink_center') {
            $this->handle_unlink_center();
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
                $cohort_id = isset($_GET['id']) ? absint($_GET['id']) : 0;
                $cohort = $this->repo->get_by_id($cohort_id);
                if ($cohort) {
                    $this->render_tabbed_editor($cohort);
                } else {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Cohort not found.', 'hl-core') . '</p></div>';
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
            'cohort_name'     => sanitize_text_field($_POST['cohort_name']),
            'cohort_code'     => sanitize_text_field($_POST['cohort_code']),
            'status'          => sanitize_text_field($_POST['status']),
            'start_date'      => sanitize_text_field($_POST['start_date']),
            'end_date'        => sanitize_text_field($_POST['end_date']),
            'timezone'        => sanitize_text_field($_POST['timezone']),
            'district_id'     => !empty($_POST['district_id']) ? absint($_POST['district_id']) : null,
            'cohort_group_id' => !empty($_POST['cohort_group_id']) ? absint($_POST['cohort_group_id']) : null,
            'is_control_group' => !empty($_POST['is_control_group']) ? 1 : 0,
        );

        if (empty($data['end_date'])) {
            $data['end_date'] = null;
        }

        if ($cohort_id > 0) {
            $updated = $this->repo->update($cohort_id, $data);

            // Verify cohort_group_id actually persisted. If the column is missing
            // from the DB table, the entire UPDATE silently fails.
            $expected_group = $data['cohort_group_id'];
            $actual_group   = $updated ? $updated->cohort_group_id : null;
            if ($expected_group !== null && ($actual_group === null || (int) $actual_group !== (int) $expected_group)) {
                // Column may be missing — attempt self-healing migration then retry.
                $this->ensure_cohort_group_column();
                $updated = $this->repo->update($cohort_id, $data);
                $actual_group = $updated ? $updated->cohort_group_id : null;
                if ($expected_group !== null && ($actual_group === null || (int) $actual_group !== (int) $expected_group)) {
                    error_log('[HL Core] Cohort group save failed for ID ' . $cohort_id
                        . ': expected=' . $expected_group . ' got=' . ($actual_group ?? 'NULL'));
                }
            }

            $redirect = admin_url('admin.php?page=hl-core&action=edit&id=' . $cohort_id . '&tab=details&message=updated');
        } else {
            $data['cohort_uuid'] = HL_DB_Utils::generate_uuid();
            if (empty($data['cohort_code'])) {
                $data['cohort_code'] = HL_Normalization::generate_code($data['cohort_name']);
            }
            $new_id = $this->repo->create($data);
            $redirect = admin_url('admin.php?page=hl-core&action=edit&id=' . $new_id . '&message=created');
        }

        wp_redirect($redirect);
        exit;
    }

    /**
     * Ensure the cohort_group_id column exists in the hl_cohort table.
     *
     * Self-healing: if the migration failed or dbDelta didn't add the column,
     * this creates it on the fly so the save can succeed.
     */
    private function ensure_cohort_group_column() {
        global $wpdb;
        $table = $wpdb->prefix . 'hl_cohort';

        $col = $wpdb->get_var( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
            $table,
            'cohort_group_id'
        ) );

        if ( empty( $col ) ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `cohort_group_id` bigint(20) unsigned DEFAULT NULL" );
            $wpdb->query( "ALTER TABLE `{$table}` ADD INDEX `cohort_group_id` (`cohort_group_id`)" );
            error_log( '[HL Core] Self-healed: added missing cohort_group_id column to ' . $table );
        }
    }

    private function handle_delete() {
        $cohort_id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        if (!$cohort_id) return;

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'hl_delete_cohort_' . $cohort_id)) {
            wp_die(__('Security check failed.', 'hl-core'));
        }
        if (!current_user_can('manage_hl_core')) {
            wp_die(__('You do not have permission to perform this action.', 'hl-core'));
        }

        $this->repo->delete($cohort_id);
        wp_redirect(admin_url('admin.php?page=hl-core&message=deleted'));
        exit;
    }

    private function handle_link_center() {
        if (!isset($_POST['hl_link_center_nonce']) || !wp_verify_nonce($_POST['hl_link_center_nonce'], 'hl_link_center')) {
            wp_die(__('Security check failed.', 'hl-core'));
        }
        if (!current_user_can('manage_hl_core')) {
            wp_die(__('You do not have permission to perform this action.', 'hl-core'));
        }

        global $wpdb;
        $cohort_id = absint($_POST['cohort_id']);
        $center_id = absint($_POST['center_id']);

        if ($cohort_id && $center_id) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}hl_cohort_center WHERE cohort_id = %d AND center_id = %d",
                $cohort_id, $center_id
            ));
            if (!$exists) {
                $wpdb->insert($wpdb->prefix . 'hl_cohort_center', array(
                    'cohort_id' => $cohort_id,
                    'center_id' => $center_id,
                ));
            }
        }

        wp_redirect(admin_url('admin.php?page=hl-core&action=edit&id=' . $cohort_id . '&tab=centers&message=center_linked'));
        exit;
    }

    private function handle_unlink_center() {
        $cohort_id = isset($_GET['cohort_id']) ? absint($_GET['cohort_id']) : 0;
        $center_id = isset($_GET['center_id']) ? absint($_GET['center_id']) : 0;

        if (!$cohort_id || !$center_id) return;

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'hl_unlink_center_' . $center_id)) {
            wp_die(__('Security check failed.', 'hl-core'));
        }
        if (!current_user_can('manage_hl_core')) {
            wp_die(__('You do not have permission to perform this action.', 'hl-core'));
        }

        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'hl_cohort_center', array(
            'cohort_id' => $cohort_id,
            'center_id' => $center_id,
        ));

        wp_redirect(admin_url('admin.php?page=hl-core&action=edit&id=' . $cohort_id . '&tab=centers&message=center_unlinked'));
        exit;
    }

    // =========================================================================
    // Cohort List
    // =========================================================================

    private function render_list() {
        $cohorts = $this->repo->get_all();

        if (isset($_GET['message'])) {
            $msg = sanitize_text_field($_GET['message']);
            $messages = array(
                'created' => __('Cohort created successfully.', 'hl-core'),
                'updated' => __('Cohort updated successfully.', 'hl-core'),
                'deleted' => __('Cohort deleted successfully.', 'hl-core'),
            );
            if (isset($messages[$msg])) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($messages[$msg]) . '</p></div>';
            }
        }

        echo '<h1 class="wp-heading-inline">' . esc_html__('Cohorts', 'hl-core') . '</h1>';
        echo ' <a href="' . esc_url(admin_url('admin.php?page=hl-core&action=new')) . '" class="page-title-action">' . esc_html__('Add New', 'hl-core') . '</a>';
        echo '<hr class="wp-header-end">';

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

        $group_names = array();
        $group_rows = $wpdb->get_results(
            "SELECT group_id, group_name FROM {$wpdb->prefix}hl_cohort_group",
            ARRAY_A
        );
        if ($group_rows) {
            foreach ($group_rows as $row) {
                $group_names[$row['group_id']] = $row['group_name'];
            }
        }

        if (empty($cohorts)) {
            echo '<p>' . esc_html__('No cohorts found. Create your first cohort to get started.', 'hl-core') . '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('ID', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Name', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Code', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Status', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Start Date', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('District', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Group', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Centers', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Actions', 'hl-core') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($cohorts as $cohort) {
            $edit_url   = admin_url('admin.php?page=hl-core&action=edit&id=' . $cohort->cohort_id);
            $delete_url = wp_nonce_url(
                admin_url('admin.php?page=hl-core&action=delete&id=' . $cohort->cohort_id),
                'hl_delete_cohort_' . $cohort->cohort_id
            );

            $status_colors = array(
                'active'   => '#00a32a',
                'draft'    => '#996800',
                'paused'   => '#b32d2e',
                'archived' => '#8c8f94',
            );
            $sc = isset($status_colors[$cohort->status]) ? $status_colors[$cohort->status] : '#666';

            $district_name = ($cohort->district_id && isset($districts[$cohort->district_id])) ? $districts[$cohort->district_id] : '';
            $group_name    = (!empty($cohort->cohort_group_id) && isset($group_names[$cohort->cohort_group_id])) ? $group_names[$cohort->cohort_group_id] : '';
            $center_count  = isset($center_counts[$cohort->cohort_id]) ? $center_counts[$cohort->cohort_id] : 0;

            echo '<tr>';
            echo '<td>' . esc_html($cohort->cohort_id) . '</td>';
            echo '<td><strong><a href="' . esc_url($edit_url) . '">' . esc_html($cohort->cohort_name) . '</a></strong>';
            if ($cohort->is_control_group) {
                echo ' <span class="hl-status-badge" style="background:#9b59b6;color:#fff;font-size:11px;">Control</span>';
            }
            echo '</td>';
            echo '<td><code>' . esc_html($cohort->cohort_code) . '</code></td>';
            echo '<td><span style="color:' . esc_attr($sc) . '; font-weight:600;">' . esc_html(ucfirst($cohort->status)) . '</span></td>';
            echo '<td>' . esc_html($cohort->start_date) . '</td>';
            echo '<td>' . esc_html($district_name) . '</td>';
            echo '<td>' . esc_html($group_name) . '</td>';
            echo '<td>' . esc_html($center_count) . '</td>';
            echo '<td>';
            echo '<a href="' . esc_url($edit_url) . '" class="button button-small">' . esc_html__('Edit', 'hl-core') . '</a> ';
            echo '<a href="' . esc_url($delete_url) . '" class="button button-small button-link-delete" onclick="return confirm(\'' . esc_js(__('Are you sure you want to delete this cohort?', 'hl-core')) . '\');">' . esc_html__('Delete', 'hl-core') . '</a>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    // =========================================================================
    // New Cohort Form (no tabs)
    // =========================================================================

    private function render_form() {
        global $wpdb;
        $districts = $wpdb->get_results(
            "SELECT orgunit_id, name FROM {$wpdb->prefix}hl_orgunit WHERE orgunit_type = 'district' AND status = 'active' ORDER BY name ASC",
            ARRAY_A
        );

        echo '<h1>' . esc_html__('Add New Cohort', 'hl-core') . '</h1>';
        echo '<a href="' . esc_url(admin_url('admin.php?page=hl-core')) . '">&larr; ' . esc_html__('Back to Cohorts', 'hl-core') . '</a>';

        $this->render_details_form(null, $districts);
    }

    // =========================================================================
    // Tabbed Editor (edit mode)
    // =========================================================================

    private function render_tabbed_editor($cohort) {
        global $wpdb;

        $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'details';
        $sub         = isset($_GET['sub']) ? sanitize_text_field($_GET['sub']) : '';
        $cohort_id   = $cohort->cohort_id;
        $base_url    = admin_url('admin.php?page=hl-core&action=edit&id=' . $cohort_id);

        // Messages.
        if (isset($_GET['message'])) {
            $msg = sanitize_text_field($_GET['message']);
            $messages = array(
                'created'            => __('Cohort created successfully.', 'hl-core'),
                'updated'            => __('Cohort updated successfully.', 'hl-core'),
                'center_linked'      => __('Center linked to cohort.', 'hl-core'),
                'center_unlinked'    => __('Center unlinked from cohort.', 'hl-core'),
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
            );
            if (isset($messages[$msg])) {
                $notice_type = ($msg === 'clone_error') ? 'notice-error' : 'notice-success';
                echo '<div class="notice ' . $notice_type . ' is-dismissible"><p>' . esc_html($messages[$msg]) . '</p></div>';
            }
        }

        // Header.
        $status_colors = array(
            'active' => '#00a32a', 'draft' => '#996800',
            'paused' => '#b32d2e', 'archived' => '#8c8f94',
        );
        $sc = isset($status_colors[$cohort->status]) ? $status_colors[$cohort->status] : '#666';

        echo '<h1>' . esc_html($cohort->cohort_name) . ' ';
        echo '<span style="color:' . esc_attr($sc) . '; font-size:14px; font-weight:600; vertical-align:middle;">' . esc_html(ucfirst($cohort->status)) . '</span>';
        if ($cohort->is_control_group) {
            echo ' <span class="hl-status-badge" style="background:#9b59b6;color:#fff;font-size:12px;">Control Group</span>';
        }
        echo '</h1>';
        echo '<a href="' . esc_url(admin_url('admin.php?page=hl-core')) . '">&larr; ' . esc_html__('Back to Cohorts', 'hl-core') . '</a>';

        // Tabs.
        $tabs = array(
            'details'     => __('Details', 'hl-core'),
            'centers'     => __('Centers', 'hl-core'),
            'pathways'    => __('Pathways', 'hl-core'),
            'teams'       => __('Teams', 'hl-core'),
            'enrollments' => __('Enrollments', 'hl-core'),
            'coaching'    => __('Coaching', 'hl-core'),
            'classrooms'  => __('Classrooms', 'hl-core'),
        );

        // Control group cohorts don't use coaching or teams.
        if ($cohort->is_control_group) {
            unset($tabs['coaching'], $tabs['teams']);
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
            case 'centers':
                $this->render_tab_centers($cohort);
                break;
            case 'pathways':
                $this->render_tab_pathways($cohort, $sub);
                break;
            case 'teams':
                $this->render_tab_teams($cohort, $sub);
                break;
            case 'enrollments':
                $this->render_tab_enrollments($cohort, $sub);
                break;
            case 'coaching':
                $this->render_tab_coaching($cohort);
                break;
            case 'classrooms':
                $this->render_tab_classrooms($cohort);
                break;
            case 'details':
            default:
                $districts = $wpdb->get_results(
                    "SELECT orgunit_id, name FROM {$wpdb->prefix}hl_orgunit WHERE orgunit_type = 'district' AND status = 'active' ORDER BY name ASC",
                    ARRAY_A
                );
                $this->render_details_form($cohort, $districts);
                break;
        }

        echo '</div>';
    }

    /**
     * Build the cohort context array passed to standalone class render methods.
     *
     * @param object $cohort
     * @return array
     */
    private function get_cohort_context($cohort) {
        return array(
            'cohort_id'   => $cohort->cohort_id,
            'cohort_name' => $cohort->cohort_name,
        );
    }

    /**
     * Render a breadcrumb trail within a tab sub-view.
     *
     * @param object $cohort     Cohort object.
     * @param string $tab        Current tab slug (e.g. 'pathways').
     * @param string $tab_label  Tab display label (e.g. 'Pathways').
     * @param array  $crumbs     Additional crumb items as [ ['label' => ..., 'url' => ...], ... ].
     *                           The last item is rendered as plain text (current page).
     */
    private function render_breadcrumb($cohort, $tab, $tab_label, $crumbs = array()) {
        $base_url = admin_url('admin.php?page=hl-core&action=edit&id=' . $cohort->cohort_id . '&tab=' . $tab);

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

    private function render_details_form($cohort, $districts, $cohort_groups = null) {
        $is_edit = ($cohort !== null);

        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=hl-core')) . '">';
        wp_nonce_field('hl_save_cohort', 'hl_cohort_nonce');

        if ($is_edit) {
            echo '<input type="hidden" name="cohort_id" value="' . esc_attr($cohort->cohort_id) . '" />';
        }

        echo '<table class="form-table">';

        // Name
        echo '<tr><th scope="row"><label for="cohort_name">' . esc_html__('Cohort Name', 'hl-core') . '</label></th>';
        echo '<td><input type="text" id="cohort_name" name="cohort_name" value="' . esc_attr($is_edit ? $cohort->cohort_name : '') . '" class="regular-text" required /></td></tr>';

        // Code
        echo '<tr><th scope="row"><label for="cohort_code">' . esc_html__('Cohort Code', 'hl-core') . '</label></th>';
        echo '<td><input type="text" id="cohort_code" name="cohort_code" value="' . esc_attr($is_edit ? $cohort->cohort_code : '') . '" class="regular-text" />';
        echo '<p class="description">' . esc_html__('Leave blank to auto-generate from name.', 'hl-core') . '</p></td></tr>';

        // Status
        $current_status = $is_edit ? $cohort->status : 'draft';
        echo '<tr><th scope="row"><label for="status">' . esc_html__('Status', 'hl-core') . '</label></th>';
        echo '<td><select id="status" name="status">';
        foreach (array('draft', 'active', 'paused', 'archived') as $s) {
            echo '<option value="' . esc_attr($s) . '"' . selected($current_status, $s, false) . '>' . esc_html(ucfirst($s)) . '</option>';
        }
        echo '</select></td></tr>';

        // Start Date
        echo '<tr><th scope="row"><label for="start_date">' . esc_html__('Start Date', 'hl-core') . '</label></th>';
        echo '<td><input type="date" id="start_date" name="start_date" value="' . esc_attr($is_edit ? $cohort->start_date : '') . '" required /></td></tr>';

        // End Date
        echo '<tr><th scope="row"><label for="end_date">' . esc_html__('End Date', 'hl-core') . '</label></th>';
        echo '<td><input type="date" id="end_date" name="end_date" value="' . esc_attr($is_edit && $cohort->end_date ? $cohort->end_date : '') . '" />';
        echo '<p class="description">' . esc_html__('Optional. Leave blank for open-ended cohorts.', 'hl-core') . '</p></td></tr>';

        // Timezone
        $current_tz = $is_edit ? $cohort->timezone : 'America/Bogota';
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
        echo '<tr><th scope="row"><label for="timezone">' . esc_html__('Timezone', 'hl-core') . '</label></th>';
        echo '<td><select id="timezone" name="timezone">';
        foreach ($timezones as $tz_val => $tz_lbl) {
            echo '<option value="' . esc_attr($tz_val) . '"' . selected($current_tz, $tz_val, false) . '>' . esc_html($tz_lbl) . '</option>';
        }
        echo '</select></td></tr>';

        // District
        $current_district = $is_edit ? $cohort->district_id : '';
        echo '<tr><th scope="row"><label for="district_id">' . esc_html__('District', 'hl-core') . '</label></th>';
        echo '<td><select id="district_id" name="district_id">';
        echo '<option value="">' . esc_html__('-- Select District --', 'hl-core') . '</option>';
        if ($districts) {
            foreach ($districts as $d) {
                echo '<option value="' . esc_attr($d['orgunit_id']) . '"' . selected($current_district, $d['orgunit_id'], false) . '>' . esc_html($d['name']) . '</option>';
            }
        }
        echo '</select></td></tr>';

        // Cohort Group
        if ($cohort_groups === null) {
            global $wpdb;
            $cohort_groups = $wpdb->get_results(
                "SELECT group_id, group_name FROM {$wpdb->prefix}hl_cohort_group WHERE status = 'active' ORDER BY group_name ASC",
                ARRAY_A
            ) ?: array();
        }
        $current_group = $is_edit && isset($cohort->cohort_group_id) ? $cohort->cohort_group_id : '';
        echo '<tr><th scope="row"><label for="cohort_group_id">' . esc_html__('Cohort Group', 'hl-core') . '</label></th>';
        echo '<td><select id="cohort_group_id" name="cohort_group_id">';
        echo '<option value="">' . esc_html__('-- None --', 'hl-core') . '</option>';
        foreach ($cohort_groups as $cg) {
            echo '<option value="' . esc_attr($cg['group_id']) . '"' . selected($current_group, $cg['group_id'], false) . '>' . esc_html($cg['group_name']) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__('Optional. Group this cohort with others for cross-cohort reporting.', 'hl-core') . '</p>';
        echo '</td></tr>';

        // Control Group
        $is_control = $is_edit ? (int) $cohort->is_control_group : 0;
        echo '<tr><th scope="row">' . esc_html__('Control Group', 'hl-core') . '</th>';
        echo '<td><label><input type="checkbox" name="is_control_group" value="1" ' . checked($is_control, 1, false) . '> ';
        echo esc_html__('This cohort is a control/comparison group', 'hl-core') . '</label>';
        echo '<p class="description">' . esc_html__('Control group cohorts only require assessments (no coaching, teams, or full program pathway).', 'hl-core') . '</p>';
        echo '</td></tr>';

        echo '</table>';
        submit_button($is_edit ? __('Update Cohort', 'hl-core') : __('Create Cohort', 'hl-core'));
        echo '</form>';
    }

    // =========================================================================
    // Tab: Centers
    // =========================================================================

    private function render_tab_centers($cohort) {
        global $wpdb;
        $cohort_id = $cohort->cohort_id;

        // Linked centers.
        $linked = $wpdb->get_results($wpdb->prepare(
            "SELECT cc.id AS link_id, cc.center_id, o.name AS center_name, o.parent_orgunit_id,
                    p.name AS district_name
             FROM {$wpdb->prefix}hl_cohort_center cc
             JOIN {$wpdb->prefix}hl_orgunit o ON cc.center_id = o.orgunit_id
             LEFT JOIN {$wpdb->prefix}hl_orgunit p ON o.parent_orgunit_id = p.orgunit_id
             WHERE cc.cohort_id = %d
             ORDER BY o.name ASC",
            $cohort_id
        ));

        // Get leader names per center (enrolled as center_leader).
        $leader_names = array();
        if ($linked) {
            $center_ids = wp_list_pluck($linked, 'center_id');
            if (!empty($center_ids)) {
                $in_ids  = implode(',', array_map('intval', $center_ids));
                $leaders = $wpdb->get_results(
                    "SELECT e.center_id, u.display_name
                     FROM {$wpdb->prefix}hl_enrollment e
                     JOIN {$wpdb->users} u ON e.user_id = u.ID
                     WHERE e.center_id IN ({$in_ids}) AND e.cohort_id = " . intval($cohort_id) . "
                       AND e.roles LIKE '%center_leader%' AND e.status = 'active'",
                    ARRAY_A
                );
                foreach ($leaders as $l) {
                    $leader_names[$l['center_id']][] = $l['display_name'];
                }
            }
        }

        // Available centers (not yet linked).
        $available = $wpdb->get_results($wpdb->prepare(
            "SELECT o.orgunit_id, o.name
             FROM {$wpdb->prefix}hl_orgunit o
             WHERE o.orgunit_type = 'center' AND o.status = 'active'
               AND o.orgunit_id NOT IN (
                   SELECT center_id FROM {$wpdb->prefix}hl_cohort_center WHERE cohort_id = %d
               )
             ORDER BY o.name ASC",
            $cohort_id
        ));

        // Link Center form.
        if (!empty($available)) {
            echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=hl-core&action=link_center')) . '" style="margin-bottom:15px; display:flex; gap:8px; align-items:center;">';
            wp_nonce_field('hl_link_center', 'hl_link_center_nonce');
            echo '<input type="hidden" name="cohort_id" value="' . esc_attr($cohort_id) . '" />';
            echo '<select name="center_id" required>';
            echo '<option value="">' . esc_html__('-- Select Center --', 'hl-core') . '</option>';
            foreach ($available as $c) {
                echo '<option value="' . esc_attr($c->orgunit_id) . '">' . esc_html($c->name) . '</option>';
            }
            echo '</select>';
            echo '<button type="submit" class="button button-primary">' . esc_html__('Link Center', 'hl-core') . '</button>';
            echo '</form>';
        }

        if (empty($linked)) {
            echo '<p>' . esc_html__('No centers linked to this cohort yet.', 'hl-core') . '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Center Name', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('District', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Leaders', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Actions', 'hl-core') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($linked as $row) {
            $unlink_url = wp_nonce_url(
                admin_url('admin.php?page=hl-core&action=unlink_center&cohort_id=' . $cohort_id . '&center_id=' . $row->center_id),
                'hl_unlink_center_' . $row->center_id
            );
            $leaders = isset($leader_names[$row->center_id]) ? implode(', ', $leader_names[$row->center_id]) : '-';

            echo '<tr>';
            echo '<td><strong>' . esc_html($row->center_name) . '</strong></td>';
            echo '<td>' . esc_html($row->district_name ?: '-') . '</td>';
            echo '<td>' . esc_html($leaders) . '</td>';
            echo '<td><a href="' . esc_url($unlink_url) . '" class="button button-small button-link-delete" onclick="return confirm(\'' . esc_js(__('Unlink this center?', 'hl-core')) . '\');">' . esc_html__('Unlink', 'hl-core') . '</a></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    // =========================================================================
    // Tab: Pathways
    // =========================================================================

    private function render_tab_pathways($cohort, $sub = '') {
        $pathways_admin = HL_Admin_Pathways::instance();
        $context        = $this->get_cohort_context($cohort);
        $cohort_id      = $cohort->cohort_id;
        $base_url       = admin_url('admin.php?page=hl-core&action=edit&id=' . $cohort_id . '&tab=pathways');

        switch ($sub) {
            case 'new':
                $this->render_breadcrumb($cohort, 'pathways', __('Pathways', 'hl-core'), array(
                    array('label' => __('New Pathway', 'hl-core')),
                ));
                $pathways_admin->render_pathway_form(null, $context);
                return;

            case 'edit':
                $pathway_id = isset($_GET['pathway_id']) ? absint($_GET['pathway_id']) : 0;
                $pathway    = $pathways_admin->get_pathway($pathway_id);
                if (!$pathway || absint($pathway->cohort_id) !== $cohort_id) {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Pathway not found in this cohort.', 'hl-core') . '</p></div>';
                    break; // fall through to list
                }
                $this->render_breadcrumb($cohort, 'pathways', __('Pathways', 'hl-core'), array(
                    array('label' => $pathway->pathway_name, 'url' => $base_url . '&sub=view&pathway_id=' . $pathway_id),
                    array('label' => __('Edit', 'hl-core')),
                ));
                $pathways_admin->render_pathway_form($pathway, $context);
                return;

            case 'view':
                $pathway_id = isset($_GET['pathway_id']) ? absint($_GET['pathway_id']) : 0;
                $pathway    = $pathways_admin->get_pathway($pathway_id);
                if (!$pathway || absint($pathway->cohort_id) !== $cohort_id) {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Pathway not found in this cohort.', 'hl-core') . '</p></div>';
                    break;
                }
                $this->render_breadcrumb($cohort, 'pathways', __('Pathways', 'hl-core'), array(
                    array('label' => $pathway->pathway_name),
                ));
                $pathways_admin->render_pathway_detail($pathway, $context);
                return;

            case 'activity':
                $pathway_id = isset($_GET['pathway_id']) ? absint($_GET['pathway_id']) : 0;
                $pathway    = $pathways_admin->get_pathway($pathway_id);
                if (!$pathway || absint($pathway->cohort_id) !== $cohort_id) {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Pathway not found in this cohort.', 'hl-core') . '</p></div>';
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
                    $this->render_breadcrumb($cohort, 'pathways', __('Pathways', 'hl-core'), array(
                        array('label' => $pathway->pathway_name, 'url' => $base_url . '&sub=view&pathway_id=' . $pathway_id),
                        array('label' => $activity->title . ' — ' . __('Edit', 'hl-core')),
                    ));
                    $pathways_admin->render_activity_form($pathway, $activity, $context);
                } else {
                    // New activity
                    $this->render_breadcrumb($cohort, 'pathways', __('Pathways', 'hl-core'), array(
                        array('label' => $pathway->pathway_name, 'url' => $base_url . '&sub=view&pathway_id=' . $pathway_id),
                        array('label' => __('New Activity', 'hl-core')),
                    ));
                    $pathways_admin->render_activity_form($pathway, null, $context);
                }
                return;
        }

        // Default: show pathways list
        $this->render_tab_pathways_list($cohort);
    }

    /**
     * Pathways list table (default sub-view within Pathways tab).
     */
    private function render_tab_pathways_list($cohort) {
        global $wpdb;
        $cohort_id = $cohort->cohort_id;
        $base_url  = admin_url('admin.php?page=hl-core&action=edit&id=' . $cohort_id . '&tab=pathways');

        $pathways = $wpdb->get_results($wpdb->prepare(
            "SELECT pw.*,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}hl_activity a WHERE a.pathway_id = pw.pathway_id) as activity_count
             FROM {$wpdb->prefix}hl_pathway pw
             WHERE pw.cohort_id = %d
             ORDER BY pw.pathway_name ASC",
            $cohort_id
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
            echo '<input type="hidden" name="target_cohort_id" value="' . esc_attr($cohort_id) . '" />';
            echo '<input type="hidden" name="_hl_cohort_context" value="' . esc_attr($cohort_id) . '" />';
            echo '<select name="source_pathway_id" required>';
            echo '<option value="">' . esc_html__('-- Clone from Template --', 'hl-core') . '</option>';
            foreach ($templates as $t) {
                echo '<option value="' . esc_attr($t->pathway_id) . '">' . esc_html($t->pathway_name) . '</option>';
            }
            echo '</select>';
            echo '<button type="submit" class="button">' . esc_html__('Clone', 'hl-core') . '</button>';
            echo '</form>';
        }

        echo ' <a href="' . esc_url(admin_url('admin.php?page=hl-pathways&cohort_id=' . $cohort_id)) . '" class="button" title="' . esc_attr__('View all pathways for this cohort on the standalone page', 'hl-core') . '">' . esc_html__('Full Page View', 'hl-core') . '</a>';

        echo '</div>';

        if (empty($pathways)) {
            echo '<p>' . esc_html__('No pathways in this cohort yet.', 'hl-core') . '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Name', 'hl-core') . '</th>';
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
                admin_url('admin.php?page=hl-pathways&action=delete&id=' . $pw->pathway_id . '&cohort_context=' . $cohort_id),
                'hl_delete_pathway_' . $pw->pathway_id
            );

            echo '<tr>';
            echo '<td><strong><a href="' . esc_url($view_url) . '">' . esc_html($pw->pathway_name) . '</a></strong>';
            if (!empty($pw->is_template)) {
                echo ' <span class="hl-status-badge active" style="font-size:10px;">' . esc_html__('Template', 'hl-core') . '</span>';
            }
            echo '</td>';
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

    private function render_tab_teams($cohort, $sub = '') {
        $teams_admin = HL_Admin_Teams::instance();
        $context     = $this->get_cohort_context($cohort);
        $cohort_id   = $cohort->cohort_id;
        $base_url    = admin_url('admin.php?page=hl-core&action=edit&id=' . $cohort_id . '&tab=teams');

        switch ($sub) {
            case 'new':
                $this->render_breadcrumb($cohort, 'teams', __('Teams', 'hl-core'), array(
                    array('label' => __('New Team', 'hl-core')),
                ));
                $teams_admin->render_form(null, $context);
                return;

            case 'edit':
                $team_id = isset($_GET['team_id']) ? absint($_GET['team_id']) : 0;
                $team    = $teams_admin->get_team($team_id);
                if (!$team || absint($team->cohort_id) !== $cohort_id) {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Team not found in this cohort.', 'hl-core') . '</p></div>';
                    break;
                }
                $this->render_breadcrumb($cohort, 'teams', __('Teams', 'hl-core'), array(
                    array('label' => $team->team_name . ' — ' . __('Edit', 'hl-core')),
                ));
                $teams_admin->render_form($team, $context);
                return;

            case 'view':
                $team_id = isset($_GET['team_id']) ? absint($_GET['team_id']) : 0;
                $team    = $teams_admin->get_team($team_id);
                if (!$team || absint($team->cohort_id) !== $cohort_id) {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Team not found in this cohort.', 'hl-core') . '</p></div>';
                    break;
                }
                $this->render_breadcrumb($cohort, 'teams', __('Teams', 'hl-core'), array(
                    array('label' => $team->team_name),
                ));
                $teams_admin->render_team_detail($team, $context);
                return;
        }

        // Default: show teams list
        $this->render_tab_teams_list($cohort);
    }

    /**
     * Teams list table (default sub-view within Teams tab).
     */
    private function render_tab_teams_list($cohort) {
        global $wpdb;
        $cohort_id = $cohort->cohort_id;
        $base_url  = admin_url('admin.php?page=hl-core&action=edit&id=' . $cohort_id . '&tab=teams');

        $teams = $wpdb->get_results($wpdb->prepare(
            "SELECT t.*, o.name AS center_name,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}hl_team_membership tm WHERE tm.team_id = t.team_id) as member_count
             FROM {$wpdb->prefix}hl_team t
             LEFT JOIN {$wpdb->prefix}hl_orgunit o ON t.center_id = o.orgunit_id
             WHERE t.cohort_id = %d
             ORDER BY t.team_name ASC",
            $cohort_id
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
        echo ' <a href="' . esc_url(admin_url('admin.php?page=hl-teams&cohort_id=' . $cohort_id)) . '" class="button" title="' . esc_attr__('View all teams for this cohort on the standalone page', 'hl-core') . '">' . esc_html__('Full Page View', 'hl-core') . '</a>';
        echo '</div>';

        if (empty($teams)) {
            echo '<p>' . esc_html__('No teams in this cohort yet.', 'hl-core') . '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Team Name', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Center', 'hl-core') . '</th>';
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
                admin_url('admin.php?page=hl-teams&action=delete&id=' . $t->team_id . '&cohort_context=' . $cohort_id),
                'hl_delete_team_' . $t->team_id
            );

            $status_style = ($t->status === 'active')
                ? 'color:#00a32a;font-weight:600;'
                : 'color:#b32d2e;font-weight:600;';

            echo '<tr>';
            echo '<td><strong><a href="' . esc_url($view_url) . '">' . esc_html($t->team_name) . '</a></strong></td>';
            echo '<td>' . esc_html($t->center_name ?: '-') . '</td>';
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

    private function render_tab_enrollments($cohort, $sub = '') {
        $enrollments_admin = HL_Admin_Enrollments::instance();
        $context           = $this->get_cohort_context($cohort);
        $cohort_id         = $cohort->cohort_id;
        $base_url          = admin_url('admin.php?page=hl-core&action=edit&id=' . $cohort_id . '&tab=enrollments');

        switch ($sub) {
            case 'new':
                $this->render_breadcrumb($cohort, 'enrollments', __('Enrollments', 'hl-core'), array(
                    array('label' => __('Enroll User', 'hl-core')),
                ));
                $enrollments_admin->render_form(null, $context);
                return;

            case 'edit':
                $enrollment_id = isset($_GET['enrollment_id']) ? absint($_GET['enrollment_id']) : 0;
                $enrollment    = $enrollments_admin->get_enrollment($enrollment_id);
                if (!$enrollment || absint($enrollment->cohort_id) !== $cohort_id) {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Enrollment not found in this cohort.', 'hl-core') . '</p></div>';
                    break;
                }
                $this->render_breadcrumb($cohort, 'enrollments', __('Enrollments', 'hl-core'), array(
                    array('label' => __('Edit Enrollment', 'hl-core')),
                ));
                $enrollments_admin->render_form($enrollment, $context);
                return;
        }

        // Default: show enrollments list
        $this->render_tab_enrollments_list($cohort);
    }

    /**
     * Enrollments list table (default sub-view within Enrollments tab).
     */
    private function render_tab_enrollments_list($cohort) {
        global $wpdb;
        $cohort_id = $cohort->cohort_id;
        $base_url  = admin_url('admin.php?page=hl-core&action=edit&id=' . $cohort_id . '&tab=enrollments');

        $page_num = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        $per_page = 25;
        $offset   = ($page_num - 1) * $per_page;

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}hl_enrollment WHERE cohort_id = %d",
            $cohort_id
        ));

        $enrollments = $wpdb->get_results($wpdb->prepare(
            "SELECT e.*, u.display_name, u.user_email
             FROM {$wpdb->prefix}hl_enrollment e
             LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
             WHERE e.cohort_id = %d
             ORDER BY u.display_name ASC
             LIMIT %d OFFSET %d",
            $cohort_id, $per_page, $offset
        ));

        // Get center names.
        $centers = array();
        $center_rows = $wpdb->get_results(
            "SELECT orgunit_id, name FROM {$wpdb->prefix}hl_orgunit WHERE orgunit_type = 'center'",
            ARRAY_A
        );
        foreach ($center_rows as $r) {
            $centers[$r['orgunit_id']] = $r['name'];
        }

        // Get completion data.
        $rollups = array();
        $rollup_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT enrollment_id, cohort_completion_percent
             FROM {$wpdb->prefix}hl_completion_rollup
             WHERE cohort_id = %d",
            $cohort_id
        ), ARRAY_A);
        foreach ($rollup_rows as $r) {
            $rollups[$r['enrollment_id']] = (float) $r['cohort_completion_percent'];
        }

        // Get team names.
        $team_map = array();
        $tm_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT tm.enrollment_id, t.team_name
             FROM {$wpdb->prefix}hl_team_membership tm
             JOIN {$wpdb->prefix}hl_team t ON tm.team_id = t.team_id
             WHERE t.cohort_id = %d",
            $cohort_id
        ), ARRAY_A);
        foreach ($tm_rows as $r) {
            $team_map[$r['enrollment_id']] = $r['team_name'];
        }

        echo '<div style="margin-bottom:15px; display:flex; gap:8px; align-items:center;">';
        echo '<a href="' . esc_url($base_url . '&sub=new') . '" class="button button-primary">' . esc_html__('Enroll User', 'hl-core') . '</a>';
        echo ' <a href="' . esc_url(admin_url('admin.php?page=hl-enrollments&cohort_id=' . $cohort_id)) . '" class="button" title="' . esc_attr__('View all enrollments for this cohort on the standalone page', 'hl-core') . '">' . esc_html__('Full Page View', 'hl-core') . '</a>';
        echo ' <span style="color:#666;">' . sprintf(esc_html__('%d enrollments total', 'hl-core'), $total) . '</span>';
        echo '</div>';

        if (empty($enrollments)) {
            echo '<p>' . esc_html__('No enrollments in this cohort yet.', 'hl-core') . '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Name', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Email', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Roles', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Pathway', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Team', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Center', 'hl-core') . '</th>';
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
            $center_name = ($e->center_id && isset($centers[$e->center_id])) ? $centers[$e->center_id] : '-';
            $completion  = isset($rollups[$e->enrollment_id]) ? $rollups[$e->enrollment_id] : 0;
            $team_name   = isset($team_map[$e->enrollment_id]) ? $team_map[$e->enrollment_id] : '-';
            $pw_names    = isset($pathway_names_map[$e->enrollment_id]) ? implode(', ', $pathway_names_map[$e->enrollment_id]) : '-';

            $edit_url   = $base_url . '&sub=edit&enrollment_id=' . $e->enrollment_id;
            $delete_url = wp_nonce_url(
                admin_url('admin.php?page=hl-enrollments&action=delete&id=' . $e->enrollment_id . '&cohort_context=' . $cohort_id),
                'hl_delete_enrollment_' . $e->enrollment_id
            );

            echo '<tr>';
            echo '<td><strong><a href="' . esc_url($edit_url) . '">' . esc_html($e->display_name) . '</a></strong></td>';
            echo '<td>' . esc_html($e->user_email) . '</td>';
            echo '<td>' . esc_html($roles_str) . '</td>';
            echo '<td>' . esc_html($pw_names) . '</td>';
            echo '<td>' . esc_html($team_name) . '</td>';
            echo '<td>' . esc_html($center_name) . '</td>';
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

    private function render_tab_coaching($cohort) {
        global $wpdb;
        $cohort_id = $cohort->cohort_id;

        // Coach assignments.
        $assignments = $wpdb->get_results($wpdb->prepare(
            "SELECT ca.*, u.display_name AS coach_name
             FROM {$wpdb->prefix}hl_coach_assignment ca
             LEFT JOIN {$wpdb->users} u ON ca.coach_user_id = u.ID
             WHERE ca.cohort_id = %d
             ORDER BY ca.effective_from DESC",
            $cohort_id
        ));

        // Coaching sessions (latest 20).
        $sessions = $wpdb->get_results($wpdb->prepare(
            "SELECT cs.*, u_coach.display_name as coach_name, u_mentor.display_name as mentor_name
             FROM {$wpdb->prefix}hl_coaching_session cs
             LEFT JOIN {$wpdb->users} u_coach ON cs.coach_user_id = u_coach.ID
             JOIN {$wpdb->prefix}hl_enrollment e ON cs.mentor_enrollment_id = e.enrollment_id
             LEFT JOIN {$wpdb->users} u_mentor ON e.user_id = u_mentor.ID
             WHERE cs.cohort_id = %d
             ORDER BY cs.session_datetime DESC
             LIMIT 20",
            $cohort_id
        ));

        echo '<div style="margin-bottom:15px; display:flex; gap:8px;">';
        echo '<a href="' . esc_url(admin_url('admin.php?page=hl-coach-assignments&cohort_id=' . $cohort_id)) . '" class="button button-primary">' . esc_html__('Manage Coach Assignments', 'hl-core') . '</a>';
        echo '<a href="' . esc_url(admin_url('admin.php?page=hl-coaching&cohort_id=' . $cohort_id)) . '" class="button">' . esc_html__('All Coaching Sessions', 'hl-core') . '</a>';
        echo '</div>';

        // Assignments table.
        echo '<h3>' . esc_html__('Coach Assignments', 'hl-core') . '</h3>';
        if (empty($assignments)) {
            echo '<p>' . esc_html__('No coach assignments for this cohort.', 'hl-core') . '</p>';
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
            echo '<p>' . esc_html__('No coaching sessions for this cohort.', 'hl-core') . '</p>';
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

    private function render_tab_classrooms($cohort) {
        global $wpdb;
        $cohort_id = $cohort->cohort_id;

        // Get centers linked to this cohort.
        $center_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT center_id FROM {$wpdb->prefix}hl_cohort_center WHERE cohort_id = %d",
            $cohort_id
        ));

        if (empty($center_ids)) {
            echo '<p>' . esc_html__('No centers linked to this cohort. Link centers in the Centers tab first.', 'hl-core') . '</p>';
            return;
        }

        $in_ids = implode(',', array_map('intval', $center_ids));

        $classrooms = $wpdb->get_results(
            "SELECT c.*, o.name AS center_name
             FROM {$wpdb->prefix}hl_classroom c
             LEFT JOIN {$wpdb->prefix}hl_orgunit o ON c.center_id = o.orgunit_id
             WHERE c.center_id IN ({$in_ids}) AND c.status = 'active'
             ORDER BY o.name ASC, c.classroom_name ASC"
        );

        // Child counts per classroom.
        $child_counts = array();
        $cc_rows = $wpdb->get_results(
            "SELECT classroom_id, COUNT(*) as cnt
             FROM {$wpdb->prefix}hl_child_classroom_current
             WHERE classroom_id IN (
                 SELECT classroom_id FROM {$wpdb->prefix}hl_classroom WHERE center_id IN ({$in_ids})
             )
             GROUP BY classroom_id",
            ARRAY_A
        );
        foreach ($cc_rows as $r) {
            $child_counts[$r['classroom_id']] = $r['cnt'];
        }

        // Teacher names per classroom (from teaching assignments in this cohort).
        $teacher_names = array();
        $ta_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ta.classroom_id, u.display_name
             FROM {$wpdb->prefix}hl_teaching_assignment ta
             JOIN {$wpdb->prefix}hl_enrollment e ON ta.enrollment_id = e.enrollment_id
             JOIN {$wpdb->users} u ON e.user_id = u.ID
             WHERE e.cohort_id = %d",
            $cohort_id
        ), ARRAY_A);
        foreach ($ta_rows as $r) {
            $teacher_names[$r['classroom_id']][] = $r['display_name'];
        }

        echo '<div style="margin-bottom:15px;">';
        echo '<a href="' . esc_url(admin_url('admin.php?page=hl-classrooms')) . '" class="button button-primary">' . esc_html__('Manage Classrooms', 'hl-core') . '</a>';
        echo '</div>';

        if (empty($classrooms)) {
            echo '<p>' . esc_html__('No classrooms found at linked centers.', 'hl-core') . '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Classroom', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Center', 'hl-core') . '</th>';
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
            echo '<td>' . esc_html($c->center_name) . '</td>';
            echo '<td>' . esc_html($c->age_band ? ucfirst($c->age_band) : '-') . '</td>';
            echo '<td>' . esc_html($children) . '</td>';
            echo '<td>' . esc_html($teachers) . '</td>';
            echo '<td><a href="' . esc_url(admin_url('admin.php?page=hl-classrooms&action=view&id=' . $c->classroom_id)) . '" class="button button-small">' . esc_html__('View', 'hl-core') . '</a></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }
}
