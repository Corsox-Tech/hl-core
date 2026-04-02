<?php if (!defined('ABSPATH')) exit;

/**
 * Admin Cycles Page
 *
 * Full CRUD admin page with tabbed editor for Cycles.
 *
 * @package HL_Core
 */
class HL_Admin_Cycles {

    /** @var HL_Admin_Cycles|null */
    private static $instance = null;

    /** @var HL_Cycle_Repository */
    private $repo;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->repo = new HL_Cycle_Repository();
        add_action('wp_ajax_hl_send_cycle_emails', array($this, 'ajax_send_cycle_emails'));
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
                $cycle_id = isset($_GET['id']) ? absint($_GET['id']) : 0;
                $cycle = $this->repo->get_by_id($cycle_id);
                if ($cycle) {
                    $this->render_tabbed_editor($cycle);
                } else {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Cycle not found.', 'hl-core') . '</p></div>';
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
        if (!isset($_POST['hl_cycle_nonce'])) {
            return;
        }

        if (!wp_verify_nonce($_POST['hl_cycle_nonce'], 'hl_save_cycle')) {
            wp_die(__('Security check failed.', 'hl-core'));
        }

        if (!current_user_can('manage_hl_core')) {
            wp_die(__('You do not have permission to perform this action.', 'hl-core'));
        }

        // Proactively ensure cycle_id column exists BEFORE any save attempt.
        // If the column was missed by dbDelta or a migration, this adds it now so
        // the INSERT/UPDATE below won't fail silently.
        $this->ensure_partnership_column();

        $cycle_id = isset($_POST['cycle_id']) ? absint($_POST['cycle_id']) : 0;

        $data = array(
            'cycle_name'       => sanitize_text_field($_POST['cycle_name']),
            'cycle_code'       => sanitize_text_field($_POST['cycle_code']),
            'status'           => sanitize_text_field($_POST['status']),
            'start_date'       => sanitize_text_field($_POST['start_date']),
            'end_date'         => sanitize_text_field($_POST['end_date']),
            'timezone'         => sanitize_text_field($_POST['timezone']),
            'district_id'      => !empty($_POST['district_id']) ? absint($_POST['district_id']) : null,
            'partnership_id'        => !empty($_POST['partnership_id']) ? absint($_POST['partnership_id']) : null,
            'is_control_group' => !empty($_POST['is_control_group']) ? 1 : 0,
            'cycle_type'       => isset($_POST['cycle_type']) && in_array($_POST['cycle_type'], array('program', 'course'), true) ? $_POST['cycle_type'] : 'program',
        );

        if (empty($data['end_date'])) {
            $data['end_date'] = null;
        }

        if ($cycle_id > 0) {
            $updated = $this->repo->update($cycle_id, $data);

            if (!$updated || $updated->partnership_id === null && $data['partnership_id'] !== null) {
                error_log('[HL Core] Partnership assignment save may have failed for ID ' . $cycle_id
                    . ': expected=' . ($data['partnership_id'] ?? 'NULL')
                    . ' got=' . ($updated ? ($updated->partnership_id ?? 'NULL') : 'NO_RESULT'));
            }

            $redirect = admin_url('admin.php?page=hl-cycles&action=edit&id=' . $cycle_id . '&tab=details&message=updated');
        } else {
            $data['cycle_uuid'] = HL_DB_Utils::generate_uuid();
            if (empty($data['cycle_code'])) {
                $data['cycle_code'] = HL_Normalization::generate_code($data['cycle_name']);
            }
            $new_id = $this->repo->create($data);
            $redirect = admin_url('admin.php?page=hl-cycles&action=edit&id=' . $new_id . '&message=created');
        }

        wp_redirect($redirect);
        exit;
    }

    /**
     * Ensure the partnership_id column exists in the hl_cycle table.
     *
     * Self-healing: if the migration failed or dbDelta didn't add the column,
     * this creates it on the fly so the save can succeed.
     */
    private function ensure_partnership_column() {
        global $wpdb;
        $table = $wpdb->prefix . 'hl_cycle';

        $has_col = $wpdb->get_var( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
            $table,
            'partnership_id'
        ) );

        if ( empty( $has_col ) ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `partnership_id` bigint(20) unsigned DEFAULT NULL" );

            // Verify it was actually added.
            $verify = $wpdb->get_var( $wpdb->prepare(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                $table,
                'partnership_id'
            ) );

            if ( ! empty( $verify ) ) {
                $wpdb->query( "ALTER TABLE `{$table}` ADD INDEX `partnership_id` (`partnership_id`)" );
                error_log( '[HL Core] Self-healed: added missing partnership_id column to ' . $table );
            } else {
                error_log( '[HL Core] CRITICAL: Failed to add partnership_id column. Last error: ' . $wpdb->last_error );
            }
        }
    }

    private function handle_delete() {
        $cycle_id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        if (!$cycle_id) return;

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'hl_delete_cycle_' . $cycle_id)) {
            wp_die(__('Security check failed.', 'hl-core'));
        }
        if (!current_user_can('manage_hl_core')) {
            wp_die(__('You do not have permission to perform this action.', 'hl-core'));
        }

        $this->repo->delete($cycle_id);
        wp_redirect(admin_url('admin.php?page=hl-cycles&message=deleted'));
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
        $cycle_id = absint($_POST['cycle_id']);
        $school_id = absint($_POST['school_id']);

        if ($cycle_id && $school_id) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}hl_cycle_school WHERE cycle_id = %d AND school_id = %d",
                $cycle_id, $school_id
            ));
            if (!$exists) {
                $wpdb->insert($wpdb->prefix . 'hl_cycle_school', array(
                    'cycle_id' => $cycle_id,
                    'school_id' => $school_id,
                ));
            }
        }

        wp_redirect(admin_url('admin.php?page=hl-cycles&action=edit&id=' . $cycle_id . '&tab=schools&message=school_linked'));
        exit;
    }

    private function handle_unlink_school() {
        $cycle_id = isset($_GET['cycle_id']) ? absint($_GET['cycle_id']) : 0;
        $school_id = isset($_GET['school_id']) ? absint($_GET['school_id']) : 0;

        if (!$cycle_id || !$school_id) return;

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'hl_unlink_school_' . $school_id)) {
            wp_die(__('Security check failed.', 'hl-core'));
        }
        if (!current_user_can('manage_hl_core')) {
            wp_die(__('You do not have permission to perform this action.', 'hl-core'));
        }

        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'hl_cycle_school', array(
            'cycle_id' => $cycle_id,
            'school_id' => $school_id,
        ));

        wp_redirect(admin_url('admin.php?page=hl-cycles&action=edit&id=' . $cycle_id . '&tab=schools&message=school_unlinked'));
        exit;
    }

    // =========================================================================
    // Cycle List
    // =========================================================================

    private function render_list() {
        $cycles = $this->repo->get_all();

        if (isset($_GET['message'])) {
            $msg = sanitize_text_field($_GET['message']);
            $messages = array(
                'created' => __('Cycle created successfully.', 'hl-core'),
                'updated' => __('Cycle updated successfully.', 'hl-core'),
                'deleted' => __('Cycle deleted successfully.', 'hl-core'),
            );
            if (isset($messages[$msg])) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($messages[$msg]) . '</p></div>';
            }
        }

        echo '<h1 class="wp-heading-inline">' . esc_html__('Cycles', 'hl-core') . '</h1>';
        echo ' <a href="' . esc_url(admin_url('admin.php?page=hl-cycles&action=new')) . '" class="page-title-action">' . esc_html__('Add New', 'hl-core') . '</a>';
        echo '<hr class="wp-header-end">';

        global $wpdb;
        $school_counts = array();
        $counts = $wpdb->get_results(
            "SELECT cycle_id, COUNT(*) as cnt FROM {$wpdb->prefix}hl_cycle_school GROUP BY cycle_id",
            ARRAY_A
        );
        if ($counts) {
            foreach ($counts as $row) {
                $school_counts[$row['cycle_id']] = $row['cnt'];
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

        if (empty($cycles)) {
            echo '<p>' . esc_html__('No cycles found. Create your first cycle to get started.', 'hl-core') . '</p>';
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

        foreach ($cycles as $cycle) {
            $edit_url   = admin_url('admin.php?page=hl-cycles&action=edit&id=' . $cycle->cycle_id);
            $delete_url = wp_nonce_url(
                admin_url('admin.php?page=hl-cycles&action=delete&id=' . $cycle->cycle_id),
                'hl_delete_cycle_' . $cycle->cycle_id
            );

            $district_name = ($cycle->district_id && isset($districts[$cycle->district_id])) ? $districts[$cycle->district_id] : '';
            $school_count  = isset($school_counts[$cycle->cycle_id]) ? $school_counts[$cycle->cycle_id] : 0;

            echo '<tr>';
            echo '<td>' . esc_html($cycle->cycle_id) . '</td>';
            echo '<td><strong><a href="' . esc_url($edit_url) . '">' . esc_html($cycle->cycle_name) . '</a></strong>';
            if ($cycle->is_control_group) {
                echo ' <span class="hl-status hl-status-control">Control</span>';
            }
            $tt = isset($cycle->cycle_type) ? $cycle->cycle_type : 'program';
            if ($tt === 'course') {
                echo ' <span class="hl-type-badge course">Course</span>';
            }
            echo '</td>';
            echo '<td><span class="hl-status hl-status-' . esc_attr($cycle->status) . '">' . esc_html(ucfirst($cycle->status)) . '</span></td>';
            echo '<td>' . esc_html($cycle->start_date) . '</td>';
            echo '<td>' . esc_html($district_name) . '</td>';
            echo '<td>' . esc_html($school_count) . '</td>';
            echo '<td>';
            echo '<a href="' . esc_url($edit_url) . '" class="button button-small">' . esc_html__('Edit', 'hl-core') . '</a> ';
            echo '<a href="' . esc_url($delete_url) . '" class="button button-small button-link-delete" onclick="return confirm(\'' . esc_js(__('Are you sure you want to delete this cycle?', 'hl-core')) . '\');">' . esc_html__('Delete', 'hl-core') . '</a>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    // =========================================================================
    // New Cycle Form (no tabs)
    // =========================================================================

    private function render_form() {
        global $wpdb;
        $districts = $wpdb->get_results(
            "SELECT orgunit_id, name FROM {$wpdb->prefix}hl_orgunit WHERE orgunit_type = 'district' AND status = 'active' ORDER BY name ASC",
            ARRAY_A
        );

        echo '<h1>' . esc_html__('Add New Cycle', 'hl-core') . '</h1>';
        if ( ! empty( $_GET['partnership_id'] ) ) {
            $back_url   = admin_url( 'admin.php?page=hl-partnerships&action=edit&id=' . absint( $_GET['partnership_id'] ) );
            $back_label = __( 'Back to Partnership', 'hl-core' );
        } else {
            $back_url   = admin_url( 'admin.php?page=hl-cycles' );
            $back_label = __( 'Back to Cycles', 'hl-core' );
        }
        echo '<a href="' . esc_url( $back_url ) . '">&larr; ' . esc_html( $back_label ) . '</a>';

        $this->render_details_form(null, $districts);
    }

    // =========================================================================
    // Tabbed Editor (edit mode)
    // =========================================================================

    private function render_tabbed_editor($cycle) {
        global $wpdb;

        $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'details';
        $sub         = isset($_GET['sub']) ? sanitize_text_field($_GET['sub']) : '';
        $cycle_id   = $cycle->cycle_id;
        $base_url    = admin_url('admin.php?page=hl-cycles&action=edit&id=' . $cycle_id);

        // Messages.
        if (isset($_GET['message'])) {
            $msg = sanitize_text_field($_GET['message']);
            $messages = array(
                'created'            => __('Cycle created successfully.', 'hl-core'),
                'updated'            => __('Cycle updated successfully.', 'hl-core'),
                'school_linked'      => __('School linked to cycle.', 'hl-core'),
                'school_unlinked'    => __('School unlinked from cycle.', 'hl-core'),
                'pathway_saved'      => __('Pathway saved successfully.', 'hl-core'),
                'pathway_deleted'    => __('Pathway deleted successfully.', 'hl-core'),
                'pathway_cloned'     => __('Pathway cloned successfully.', 'hl-core'),
                'component_saved'    => __('Component saved successfully.', 'hl-core'),
                'component_deleted'  => __('Component deleted successfully.', 'hl-core'),
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
                'cycle_saved'        => __('Cycle saved successfully.', 'hl-core'),
                'cycle_deleted'      => __('Cycle deleted successfully.', 'hl-core'),
                'cycle_delete_error' => __('Cannot delete cycle: it still has linked pathways.', 'hl-core'),
            );
            if (isset($messages[$msg])) {
                $notice_type = in_array($msg, array('clone_error', 'cycle_delete_error'), true) ? 'notice-error' : 'notice-success';
                echo '<div class="notice ' . $notice_type . ' is-dismissible"><p>' . esc_html($messages[$msg]) . '</p></div>';
            }
        }

        // Header.
        $status_colors = array(
            'active' => '#00a32a', 'draft' => '#996800',
            'paused' => '#b32d2e', 'archived' => '#8c8f94',
        );
        $sc = isset($status_colors[$cycle->status]) ? $status_colors[$cycle->status] : '#666';

        echo '<h1>' . esc_html($cycle->cycle_name) . ' ';
        echo '<span style="color:' . esc_attr($sc) . '; font-size:14px; font-weight:600; vertical-align:middle;">' . esc_html(ucfirst($cycle->status)) . '</span>';
        if ($cycle->is_control_group) {
            echo ' <span class="hl-status-badge" style="background:#9b59b6;color:#fff;font-size:12px;">Control Group</span>';
        }
        echo '</h1>';
        echo '<a href="' . esc_url(admin_url('admin.php?page=hl-cycles')) . '">&larr; ' . esc_html__('Back to Cycles', 'hl-core') . '</a>';

        // Tabs.
        $tabs = array(
            'details'     => __('Details', 'hl-core'),
            'schools'     => __('Schools', 'hl-core'),
            'pathways'    => __('Pathways', 'hl-core'),
            'teams'       => __('Teams', 'hl-core'),
            'enrollments' => __('Enrollments', 'hl-core'),
            'coaching'    => __('Coaching', 'hl-core'),
            'classrooms'  => __('Classrooms', 'hl-core'),
            'assessments' => __('Assessments', 'hl-core'),
            'import'      => __('Import', 'hl-core'),
            'emails'      => __('Emails', 'hl-core'),
        );

        // Control group cycles don't use coaching or teams.
        if ($cycle->is_control_group) {
            unset($tabs['coaching'], $tabs['teams'], $tabs['import']);
        }

        // Emails tab only for control group cycles.
        if (!$cycle->is_control_group) {
            unset($tabs['emails']);
        }

        // Course-type cycles hide teams/coaching tabs.
        $cycle_type = isset($cycle->cycle_type) ? $cycle->cycle_type : 'program';
        if ($cycle_type === 'course') {
            unset($tabs['teams'], $tabs['coaching'], $tabs['import']);
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
                $this->render_tab_schools($cycle);
                break;
            case 'pathways':
                $this->render_tab_pathways($cycle, $sub);
                break;
            case 'teams':
                $this->render_tab_teams($cycle, $sub);
                break;
            case 'enrollments':
                $this->render_tab_enrollments($cycle, $sub);
                break;
            case 'coaching':
                $this->render_tab_coaching($cycle);
                break;
            case 'classrooms':
                $this->render_tab_classrooms($cycle);
                break;
            case 'assessments':
                $this->render_tab_assessments($cycle);
                break;
            case 'import':
                HL_Admin_Imports::instance()->render_cycle_import_tab($cycle);
                break;
            case 'emails':
                $this->render_tab_emails($cycle);
                break;
            case 'details':
            default:
                $districts = $wpdb->get_results(
                    "SELECT orgunit_id, name FROM {$wpdb->prefix}hl_orgunit WHERE orgunit_type = 'district' AND status = 'active' ORDER BY name ASC",
                    ARRAY_A
                );
                $this->render_details_form($cycle, $districts);
                break;
        }

        echo '</div>';
    }

    /**
     * Build the partnership context array passed to standalone class render methods.
     *
     * @param object $cycle
     * @return array
     */
    private function get_cycle_context($cycle) {
        return array(
            'cycle_id'   => $cycle->cycle_id,
            'cycle_name' => $cycle->cycle_name,
        );
    }

    /**
     * Render a breadcrumb trail within a tab sub-view.
     *
     * @param object $cycle     Cycle object.
     * @param string $tab        Current tab slug (e.g. 'pathways').
     * @param string $tab_label  Tab display label (e.g. 'Pathways').
     * @param array  $crumbs     Additional crumb items as [ ['label' => ..., 'url' => ...], ... ].
     *                           The last item is rendered as plain text (current page).
     */
    private function render_breadcrumb($cycle, $tab, $tab_label, $crumbs = array()) {
        $base_url = admin_url('admin.php?page=hl-cycles&action=edit&id=' . $cycle->cycle_id . '&tab=' . $tab);

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

    private function render_details_form($cycle, $districts) {
        global $wpdb;
        $is_edit = ($cycle !== null);

        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=hl-cycles')) . '">';
        wp_nonce_field('hl_save_cycle', 'hl_cycle_nonce');

        if ($is_edit) {
            echo '<input type="hidden" name="cycle_id" value="' . esc_attr($cycle->cycle_id) . '" />';
        }

        // Compact card-based form layout
        $current_status   = $is_edit ? $cycle->status : 'draft';
        $current_tz       = $is_edit ? $cycle->timezone : 'America/Bogota';
        $current_district = $is_edit ? $cycle->district_id : '';
        $current_type     = $is_edit && isset($cycle->cycle_type) ? $cycle->cycle_type : 'program';
        $is_control       = $is_edit ? (int) $cycle->is_control_group : 0;

        // Partnership: from cycle (edit), URL param (new from partnership page), or empty.
        $current_partnership = $is_edit && isset($cycle->partnership_id) ? $cycle->partnership_id : '';
        if (!$current_partnership && !$is_edit && isset($_GET['partnership_id'])) {
            $current_partnership = absint($_GET['partnership_id']);
        }

        // Load partnerships for dropdown.
        $partnerships = $wpdb->get_results(
            "SELECT partnership_id, partnership_name FROM {$wpdb->prefix}hl_partnership WHERE status = 'active' ORDER BY partnership_name ASC",
            ARRAY_A
        );

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

        // Cycle Name (full width)
        echo '<div class="hl-field hl-field-full">';
        echo '<label for="cycle_name">' . esc_html__('Cycle Name', 'hl-core') . '</label>';
        echo '<input type="text" id="cycle_name" name="cycle_name" value="' . esc_attr($is_edit ? $cycle->cycle_name : '') . '" required />';
        echo '</div>';

        // Cycle Code (half)
        echo '<div class="hl-field">';
        echo '<label for="cycle_code">' . esc_html__('Cycle Code', 'hl-core') . '</label>';
        echo '<input type="text" id="cycle_code" name="cycle_code" value="' . esc_attr($is_edit ? $cycle->cycle_code : '') . '" />';
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
        echo '<input type="date" id="start_date" name="start_date" value="' . esc_attr($is_edit ? $cycle->start_date : '') . '" required />';
        echo '</div>';

        // End Date (half)
        echo '<div class="hl-field">';
        echo '<label for="end_date">' . esc_html__('End Date', 'hl-core') . '</label>';
        echo '<input type="date" id="end_date" name="end_date" value="' . esc_attr($is_edit && $cycle->end_date ? $cycle->end_date : '') . '" />';
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

        // Partnership (half)
        echo '<div class="hl-field">';
        echo '<label for="partnership_id">' . esc_html__('Partnership', 'hl-core') . '</label>';
        echo '<select id="partnership_id" name="partnership_id">';
        echo '<option value="">' . esc_html__('-- None --', 'hl-core') . '</option>';
        if ($partnerships) {
            foreach ($partnerships as $p) {
                echo '<option value="' . esc_attr($p['partnership_id']) . '"' . selected($current_partnership, $p['partnership_id'], false) . '>' . esc_html($p['partnership_name']) . '</option>';
            }
        }
        echo '</select>';
        echo '</div>';

        // Cycle Type (full width)
        echo '<div class="hl-field hl-field-full">';
        echo '<label for="cycle_type">' . esc_html__('Cycle Type', 'hl-core') . '</label>';
        echo '<select id="cycle_type" name="cycle_type">';
        echo '<option value="program"' . selected($current_type, 'program', false) . '>' . esc_html__('Program (Cycles, Pathways, Teams, Coaching, Assessments)', 'hl-core') . '</option>';
        echo '<option value="course"' . selected($current_type, 'course', false) . '>' . esc_html__('Course (auto-created single Cycle + Pathway)', 'hl-core') . '</option>';
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
        submit_button($is_edit ? __('Update Cycle', 'hl-core') : __('Create Cycle', 'hl-core'));
        echo '</form>';
    }

    // =========================================================================
    // Tab: Schools
    // =========================================================================

    private function render_tab_schools($cycle) {
        global $wpdb;
        $cycle_id = $cycle->cycle_id;

        // Linked schools.
        $linked = $wpdb->get_results($wpdb->prepare(
            "SELECT cc.id AS link_id, cc.school_id, o.name AS school_name, o.parent_orgunit_id,
                    p.name AS district_name
             FROM {$wpdb->prefix}hl_cycle_school cc
             JOIN {$wpdb->prefix}hl_orgunit o ON cc.school_id = o.orgunit_id
             LEFT JOIN {$wpdb->prefix}hl_orgunit p ON o.parent_orgunit_id = p.orgunit_id
             WHERE cc.cycle_id = %d
             ORDER BY o.name ASC",
            $cycle_id
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
                     WHERE e.school_id IN ({$in_ids}) AND e.cycle_id = " . intval($cycle_id) . "
                       AND e.roles LIKE '%school_leader%' AND e.status = 'active'",
                    ARRAY_A
                );
                foreach ($leaders as $l) {
                    $leader_names[$l['school_id']][] = $l['display_name'];
                }
            }
        }

        // Available schools (not yet linked), filtered by cycle's district if set.
        $district_filter = '';
        if ( ! empty( $cycle->district_id ) ) {
            $district_filter = $wpdb->prepare( ' AND o.parent_orgunit_id = %d', $cycle->district_id );
        }
        $available = $wpdb->get_results($wpdb->prepare(
            "SELECT o.orgunit_id, o.name
             FROM {$wpdb->prefix}hl_orgunit o
             WHERE o.orgunit_type = 'school' AND o.status = 'active'
               AND o.orgunit_id NOT IN (
                   SELECT school_id FROM {$wpdb->prefix}hl_cycle_school WHERE cycle_id = %d
               )
               {$district_filter}
             ORDER BY o.name ASC",
            $cycle_id
        ));

        // Link School form.
        if (!empty($available)) {
            echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=hl-cycles&action=link_school')) . '" style="margin-bottom:15px; display:flex; gap:8px; align-items:center;">';
            wp_nonce_field('hl_link_school', 'hl_link_school_nonce');
            echo '<input type="hidden" name="cycle_id" value="' . esc_attr($cycle_id) . '" />';
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
            echo '<p>' . esc_html__('No schools linked to this cycle yet.', 'hl-core') . '</p>';
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
                admin_url('admin.php?page=hl-cycles&action=unlink_school&cycle_id=' . $cycle_id . '&school_id=' . $row->school_id),
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
    // =========================================================================
    // Tab: Pathways
    // =========================================================================

    private function render_tab_pathways($cycle, $sub = '') {
        $pathways_admin = HL_Admin_Pathways::instance();
        $context        = $this->get_cycle_context($cycle);
        $cycle_id      = $cycle->cycle_id;
        $base_url       = admin_url('admin.php?page=hl-cycles&action=edit&id=' . $cycle_id . '&tab=pathways');

        switch ($sub) {
            case 'new':
                $this->render_breadcrumb($cycle, 'pathways', __('Pathways', 'hl-core'), array(
                    array('label' => __('New Pathway', 'hl-core')),
                ));
                $pathways_admin->render_pathway_form(null, $context);
                return;

            case 'edit':
                $pathway_id = isset($_GET['pathway_id']) ? absint($_GET['pathway_id']) : 0;
                $pathway    = $pathways_admin->get_pathway($pathway_id);
                if (!$pathway || absint($pathway->cycle_id) !== absint($cycle_id)) {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Pathway not found in this cycle.', 'hl-core') . '</p></div>';
                    break; // fall through to list
                }
                $this->render_breadcrumb($cycle, 'pathways', __('Pathways', 'hl-core'), array(
                    array('label' => $pathway->pathway_name, 'url' => $base_url . '&sub=view&pathway_id=' . $pathway_id),
                    array('label' => __('Edit', 'hl-core')),
                ));
                $pathways_admin->render_pathway_form($pathway, $context);
                return;

            case 'view':
                $pathway_id = isset($_GET['pathway_id']) ? absint($_GET['pathway_id']) : 0;
                $pathway    = $pathways_admin->get_pathway($pathway_id);
                if (!$pathway || absint($pathway->cycle_id) !== absint($cycle_id)) {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Pathway not found in this cycle.', 'hl-core') . '</p></div>';
                    break;
                }
                $this->render_breadcrumb($cycle, 'pathways', __('Pathways', 'hl-core'), array(
                    array('label' => $pathway->pathway_name),
                ));
                $pathways_admin->render_pathway_detail($pathway, $context);
                return;

            case 'component':
                $pathway_id = isset($_GET['pathway_id']) ? absint($_GET['pathway_id']) : 0;
                $pathway    = $pathways_admin->get_pathway($pathway_id);
                if (!$pathway || absint($pathway->cycle_id) !== absint($cycle_id)) {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Pathway not found in this cycle.', 'hl-core') . '</p></div>';
                    break;
                }

                $component_id     = isset($_GET['component_id']) ? absint($_GET['component_id']) : 0;
                $component_action = isset($_GET['component_action']) ? sanitize_text_field($_GET['component_action']) : '';

                if ($component_id) {
                    $component = $pathways_admin->get_component($component_id);
                    if (!$component || absint($component->pathway_id) !== $pathway_id) {
                        echo '<div class="notice notice-error"><p>' . esc_html__('Component not found.', 'hl-core') . '</p></div>';
                        break;
                    }
                    $this->render_breadcrumb($cycle, 'pathways', __('Pathways', 'hl-core'), array(
                        array('label' => $pathway->pathway_name, 'url' => $base_url . '&sub=view&pathway_id=' . $pathway_id),
                        array('label' => $component->title . ' — ' . __('Edit', 'hl-core')),
                    ));
                    $pathways_admin->render_component_form($pathway, $component, $context);
                } else {
                    // New component
                    $this->render_breadcrumb($cycle, 'pathways', __('Pathways', 'hl-core'), array(
                        array('label' => $pathway->pathway_name, 'url' => $base_url . '&sub=view&pathway_id=' . $pathway_id),
                        array('label' => __('New Component', 'hl-core')),
                    ));
                    $pathways_admin->render_component_form($pathway, null, $context);
                }
                return;
        }

        // Default: show pathways list
        $this->render_tab_pathways_list($cycle);
    }

    /**
     * Pathways list table (default sub-view within Pathways tab).
     */
    private function render_tab_pathways_list($cycle) {
        global $wpdb;
        $cycle_id = $cycle->cycle_id;
        $base_url  = admin_url('admin.php?page=hl-cycles&action=edit&id=' . $cycle_id . '&tab=pathways');

        $pathways = $wpdb->get_results($wpdb->prepare(
            "SELECT pw.*,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}hl_component a WHERE a.pathway_id = pw.pathway_id) as component_count
             FROM {$wpdb->prefix}hl_pathway pw
             WHERE pw.cycle_id = %d
             ORDER BY pw.pathway_name ASC",
            $cycle_id
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
            echo '<input type="hidden" name="target_cycle_id" value="' . esc_attr($cycle_id) . '" />';
            echo '<input type="hidden" name="_hl_cycle_context" value="' . esc_attr($cycle_id) . '" />';
            echo '<select name="source_pathway_id" required>';
            echo '<option value="">' . esc_html__('-- Clone from Template --', 'hl-core') . '</option>';
            foreach ($templates as $t) {
                echo '<option value="' . esc_attr($t->pathway_id) . '">' . esc_html($t->pathway_name) . '</option>';
            }
            echo '</select>';
            echo '<button type="submit" class="button">' . esc_html__('Clone', 'hl-core') . '</button>';
            echo '</form>';
        }

        echo ' <a href="' . esc_url(admin_url('admin.php?page=hl-pathways&cycle_id=' . $cycle_id)) . '" class="button" title="' . esc_attr__('View all pathways for this cycle on the standalone page', 'hl-core') . '">' . esc_html__('Full Page View', 'hl-core') . '</a>';

        echo '</div>';

        if (empty($pathways)) {
            echo '<p>' . esc_html__('No pathways in this cycle yet.', 'hl-core') . '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Name', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Target Roles', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Components', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Avg Time', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Actions', 'hl-core') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($pathways as $pw) {
            $roles = json_decode($pw->target_roles, true);
            $roles_str = is_array($roles) ? implode(', ', $roles) : '';

            $view_url   = $base_url . '&sub=view&pathway_id=' . $pw->pathway_id;
            $edit_url   = $base_url . '&sub=edit&pathway_id=' . $pw->pathway_id;
            $delete_url = wp_nonce_url(
                admin_url('admin.php?page=hl-pathways&action=delete&id=' . $pw->pathway_id . '&cycle_context=' . $cycle_id),
                'hl_delete_pathway_' . $pw->pathway_id
            );

            echo '<tr>';
            echo '<td><strong><a href="' . esc_url($view_url) . '">' . esc_html($pw->pathway_name) . '</a></strong>';
            if (!empty($pw->is_template)) {
                echo ' <span class="hl-status-badge active" style="font-size:10px;">' . esc_html__('Template', 'hl-core') . '</span>';
            }
            echo '</td>';
            echo '<td>' . esc_html($roles_str) . '</td>';
            echo '<td>' . esc_html($pw->component_count) . '</td>';
            echo '<td>' . esc_html($pw->avg_completion_time ?: '-') . '</td>';
            echo '<td>';
            echo '<a href="' . esc_url($view_url) . '" class="button button-small">' . esc_html__('View', 'hl-core') . '</a> ';
            echo '<a href="' . esc_url($edit_url) . '" class="button button-small">' . esc_html__('Edit', 'hl-core') . '</a> ';
            echo '<a href="' . esc_url($delete_url) . '" class="button button-small button-link-delete" onclick="return confirm(\'' . esc_js(__('Delete this pathway and all its components?', 'hl-core')) . '\');">' . esc_html__('Delete', 'hl-core') . '</a>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    // =========================================================================
    // Tab: Teams
    // =========================================================================

    private function render_tab_teams($cycle, $sub = '') {
        $teams_admin = HL_Admin_Teams::instance();
        $context     = $this->get_cycle_context($cycle);
        $cycle_id   = $cycle->cycle_id;
        $base_url    = admin_url('admin.php?page=hl-cycles&action=edit&id=' . $cycle_id . '&tab=teams');

        switch ($sub) {
            case 'new':
                $this->render_breadcrumb($cycle, 'teams', __('Teams', 'hl-core'), array(
                    array('label' => __('New Team', 'hl-core')),
                ));
                $teams_admin->render_form(null, $context);
                return;

            case 'edit':
                $team_id = isset($_GET['team_id']) ? absint($_GET['team_id']) : 0;
                $team    = $teams_admin->get_team($team_id);
                if (!$team || absint($team->cycle_id) !== $cycle_id) {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Team not found in this cycle.', 'hl-core') . '</p></div>';
                    break;
                }
                $this->render_breadcrumb($cycle, 'teams', __('Teams', 'hl-core'), array(
                    array('label' => $team->team_name . ' — ' . __('Edit', 'hl-core')),
                ));
                $teams_admin->render_form($team, $context);
                return;

            case 'view':
                $team_id = isset($_GET['team_id']) ? absint($_GET['team_id']) : 0;
                $team    = $teams_admin->get_team($team_id);
                if (!$team || absint($team->cycle_id) !== $cycle_id) {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Team not found in this cycle.', 'hl-core') . '</p></div>';
                    break;
                }
                $this->render_breadcrumb($cycle, 'teams', __('Teams', 'hl-core'), array(
                    array('label' => $team->team_name),
                ));
                $teams_admin->render_team_detail($team, $context);
                return;
        }

        // Default: show teams list
        $this->render_tab_teams_list($cycle);
    }

    /**
     * Teams list table (default sub-view within Teams tab).
     */
    private function render_tab_teams_list($cycle) {
        global $wpdb;
        $cycle_id = $cycle->cycle_id;
        $base_url  = admin_url('admin.php?page=hl-cycles&action=edit&id=' . $cycle_id . '&tab=teams');

        $teams = $wpdb->get_results($wpdb->prepare(
            "SELECT t.*, o.name AS school_name,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}hl_team_membership tm WHERE tm.team_id = t.team_id) as member_count
             FROM {$wpdb->prefix}hl_team t
             LEFT JOIN {$wpdb->prefix}hl_orgunit o ON t.school_id = o.orgunit_id
             WHERE t.cycle_id = %d
             ORDER BY t.team_name ASC",
            $cycle_id
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
        echo ' <a href="' . esc_url(admin_url('admin.php?page=hl-teams&cycle_id=' . $cycle_id)) . '" class="button" title="' . esc_attr__('View all teams for this cycle on the standalone page', 'hl-core') . '">' . esc_html__('Full Page View', 'hl-core') . '</a>';
        echo '</div>';

        if (empty($teams)) {
            echo '<p>' . esc_html__('No teams in this cycle yet.', 'hl-core') . '</p>';
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
                admin_url('admin.php?page=hl-teams&action=delete&id=' . $t->team_id . '&cycle_context=' . $cycle_id),
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

    private function render_tab_enrollments($cycle, $sub = '') {
        $enrollments_admin = HL_Admin_Enrollments::instance();
        $context           = $this->get_cycle_context($cycle);
        $cycle_id         = $cycle->cycle_id;
        $base_url          = admin_url('admin.php?page=hl-cycles&action=edit&id=' . $cycle_id . '&tab=enrollments');

        switch ($sub) {
            case 'new':
                $this->render_breadcrumb($cycle, 'enrollments', __('Enrollments', 'hl-core'), array(
                    array('label' => __('Enroll User', 'hl-core')),
                ));
                $enrollments_admin->render_form(null, $context);
                return;

            case 'edit':
                $enrollment_id = isset($_GET['enrollment_id']) ? absint($_GET['enrollment_id']) : 0;
                $enrollment    = $enrollments_admin->get_enrollment($enrollment_id);
                if (!$enrollment || absint($enrollment->cycle_id) !== $cycle_id) {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Enrollment not found in this cycle.', 'hl-core') . '</p></div>';
                    break;
                }
                $this->render_breadcrumb($cycle, 'enrollments', __('Enrollments', 'hl-core'), array(
                    array('label' => __('Edit Enrollment', 'hl-core')),
                ));
                $enrollments_admin->render_form($enrollment, $context);
                return;
        }

        // Default: show enrollments list
        $this->render_tab_enrollments_list($cycle);
    }

    /**
     * Enrollments list table (default sub-view within Enrollments tab).
     */
    private function render_tab_enrollments_list($cycle) {
        global $wpdb;
        $cycle_id = $cycle->cycle_id;
        $base_url  = admin_url('admin.php?page=hl-cycles&action=edit&id=' . $cycle_id . '&tab=enrollments');

        $page_num = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        $per_page = 25;
        $offset   = ($page_num - 1) * $per_page;

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}hl_enrollment WHERE cycle_id = %d",
            $cycle_id
        ));

        $enrollments = $wpdb->get_results($wpdb->prepare(
            "SELECT e.*, u.display_name, u.user_email
             FROM {$wpdb->prefix}hl_enrollment e
             LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
             WHERE e.cycle_id = %d
             ORDER BY u.display_name ASC
             LIMIT %d OFFSET %d",
            $cycle_id, $per_page, $offset
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
            "SELECT enrollment_id, cycle_completion_percent
             FROM {$wpdb->prefix}hl_completion_rollup
             WHERE cycle_id = %d",
            $cycle_id
        ), ARRAY_A);
        foreach ($rollup_rows as $r) {
            $rollups[$r['enrollment_id']] = (float) $r['cycle_completion_percent'];
        }

        // Get team names.
        $team_map = array();
        $tm_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT tm.enrollment_id, t.team_name
             FROM {$wpdb->prefix}hl_team_membership tm
             JOIN {$wpdb->prefix}hl_team t ON tm.team_id = t.team_id
             WHERE t.cycle_id = %d",
            $cycle_id
        ), ARRAY_A);
        foreach ($tm_rows as $r) {
            $team_map[$r['enrollment_id']] = $r['team_name'];
        }

        echo '<div style="margin-bottom:15px; display:flex; gap:8px; align-items:center;">';
        echo '<a href="' . esc_url($base_url . '&sub=new') . '" class="button button-primary">' . esc_html__('Enroll User', 'hl-core') . '</a>';
        echo ' <a href="' . esc_url(admin_url('admin.php?page=hl-enrollments&cycle_id=' . $cycle_id)) . '" class="button" title="' . esc_attr__('View all enrollments for this cycle on the standalone page', 'hl-core') . '">' . esc_html__('Full Page View', 'hl-core') . '</a>';
        echo ' <span style="color:#666;">' . sprintf(esc_html__('%d enrollments total', 'hl-core'), $total) . '</span>';
        echo '</div>';

        if (empty($enrollments)) {
            echo '<p>' . esc_html__('No enrollments in this cycle yet.', 'hl-core') . '</p>';
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
                admin_url('admin.php?page=hl-enrollments&action=delete&id=' . $e->enrollment_id . '&cycle_context=' . $cycle_id),
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

    private function render_tab_coaching($cycle) {
        $cycle_id = $cycle->cycle_id;
        $coaching_subtab = isset($_GET['coaching_sub']) ? sanitize_text_field($_GET['coaching_sub']) : 'sessions';

        $subtabs = array(
            'sessions'         => __('Coaching Sessions', 'hl-core'),
            'assignments'      => __('Assignments', 'hl-core'),
            'rp_sessions'      => __('RP Sessions', 'hl-core'),
            'classroom_visits' => __('Classroom Visits', 'hl-core'),
        );

        $base_url = admin_url('admin.php?page=hl-cycles&action=edit&id=' . $cycle_id . '&tab=coaching');

        echo '<ul class="subsubsub" style="margin-bottom:15px; width:100%;">';
        $links = array();
        foreach ($subtabs as $slug => $label) {
            $url   = add_query_arg('coaching_sub', $slug, $base_url);
            $class = ($slug === $coaching_subtab) ? 'current' : '';
            $links[] = '<li><a href="' . esc_url($url) . '" class="' . esc_attr($class) . '">' . esc_html($label) . '</a></li>';
        }
        echo implode(' | ', $links);
        echo '</ul><div class="clear"></div>';

        switch ($coaching_subtab) {
            case 'assignments':
                $this->render_coaching_assignments_subtab($cycle_id);
                break;
            case 'rp_sessions':
                $this->render_rp_sessions_subtab($cycle_id);
                break;
            case 'classroom_visits':
                $this->render_classroom_visits_subtab($cycle_id);
                break;
            default:
                $this->render_coaching_sessions_subtab($cycle_id);
                break;
        }
    }

    /**
     * Coaching Sessions subtab (original coaching tab content — sessions portion).
     */
    private function render_coaching_sessions_subtab($cycle_id) {
        global $wpdb;

        echo '<div style="margin-bottom:15px; display:flex; gap:8px;">';
        echo '<a href="' . esc_url(admin_url('admin.php?page=hl-coaching&cycle_id=' . $cycle_id)) . '" class="button button-primary">' . esc_html__('All Coaching Sessions', 'hl-core') . '</a>';
        echo '</div>';

        // Coaching sessions (latest 20).
        $sessions = $wpdb->get_results($wpdb->prepare(
            "SELECT cs.*, u_coach.display_name as coach_name, u_mentor.display_name as mentor_name
             FROM {$wpdb->prefix}hl_coaching_session cs
             LEFT JOIN {$wpdb->users} u_coach ON cs.coach_user_id = u_coach.ID
             JOIN {$wpdb->prefix}hl_enrollment e ON cs.mentor_enrollment_id = e.enrollment_id
             LEFT JOIN {$wpdb->users} u_mentor ON e.user_id = u_mentor.ID
             WHERE cs.cycle_id = %d
             ORDER BY cs.session_datetime DESC
             LIMIT 20",
            $cycle_id
        ));

        echo '<h3>' . esc_html__('Recent Coaching Sessions', 'hl-core') . '</h3>';
        if (empty($sessions)) {
            echo '<p>' . esc_html__('No coaching sessions for this cycle.', 'hl-core') . '</p>';
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

    /**
     * Assignments subtab within coaching tab.
     */
    private function render_coaching_assignments_subtab($cycle_id) {
        global $wpdb;

        echo '<div style="margin-bottom:15px;">';
        echo '<a href="' . esc_url(admin_url('admin.php?page=hl-coaching&tab=assignments&cycle_id=' . $cycle_id)) . '" class="button button-primary">' . esc_html__('Manage Coach Assignments', 'hl-core') . '</a>';
        echo '</div>';

        $assignments = $wpdb->get_results($wpdb->prepare(
            "SELECT ca.*, u.display_name AS coach_name
             FROM {$wpdb->prefix}hl_coach_assignment ca
             LEFT JOIN {$wpdb->users} u ON ca.coach_user_id = u.ID
             WHERE ca.cycle_id = %d
             ORDER BY ca.effective_from DESC",
            $cycle_id
        ));

        echo '<h3>' . esc_html__('Coach Assignments', 'hl-core') . '</h3>';
        if (empty($assignments)) {
            echo '<p>' . esc_html__('No coach assignments for this cycle.', 'hl-core') . '</p>';
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
    }

    /**
     * RP Sessions subtab — list all RP sessions for this cycle.
     */
    private function render_rp_sessions_subtab($cycle_id) {
        $rp_service = new HL_RP_Session_Service();
        $sessions = $rp_service->get_by_cycle($cycle_id);

        echo '<h3>' . esc_html__('Reflective Practice Sessions', 'hl-core') . '</h3>';

        if (empty($sessions)) {
            echo '<p>' . esc_html__('No RP sessions for this cycle.', 'hl-core') . '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Mentor', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Teacher', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Session #', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Date', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Status', 'hl-core') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($sessions as $s) {
            $dt = !empty($s['session_date']) ? date_i18n(get_option('date_format'), strtotime($s['session_date'])) : '-';

            echo '<tr>';
            echo '<td>' . esc_html($s['mentor_name'] ?? '-') . '</td>';
            echo '<td>' . esc_html($s['teacher_name'] ?? '-') . '</td>';
            echo '<td>' . esc_html($s['session_number']) . '</td>';
            echo '<td>' . esc_html($dt) . '</td>';
            echo '<td><span class="hl-status-badge ' . esc_attr($s['status']) . '">' . esc_html(ucfirst($s['status'])) . '</span></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * Classroom Visits subtab — list all classroom visits for this cycle.
     */
    private function render_classroom_visits_subtab($cycle_id) {
        $cv_service = new HL_Classroom_Visit_Service();
        $visits = $cv_service->get_by_cycle($cycle_id);

        echo '<h3>' . esc_html__('Classroom Visits', 'hl-core') . '</h3>';

        if (empty($visits)) {
            echo '<p>' . esc_html__('No classroom visits for this cycle.', 'hl-core') . '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Leader', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Teacher', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Visit #', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Date', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Status', 'hl-core') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($visits as $v) {
            $dt = !empty($v['visit_date']) ? date_i18n(get_option('date_format'), strtotime($v['visit_date'])) : '-';

            echo '<tr>';
            echo '<td>' . esc_html($v['leader_name'] ?? '-') . '</td>';
            echo '<td>' . esc_html($v['teacher_name'] ?? '-') . '</td>';
            echo '<td>' . esc_html($v['visit_number']) . '</td>';
            echo '<td>' . esc_html($dt) . '</td>';
            echo '<td><span class="hl-status-badge ' . esc_attr($v['status']) . '">' . esc_html(ucfirst($v['status'])) . '</span></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    // =========================================================================
    // Tab: Classrooms
    // =========================================================================

    private function render_tab_classrooms($cycle) {
        global $wpdb;
        $cycle_id = $cycle->cycle_id;

        // Get schools linked to this cycle.
        $school_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT school_id FROM {$wpdb->prefix}hl_cycle_school WHERE cycle_id = %d",
            $cycle_id
        ));

        if (empty($school_ids)) {
            echo '<p>' . esc_html__('No schools linked to this cycle. Link schools in the Schools tab first.', 'hl-core') . '</p>';
            return;
        }

        $in_ids = implode(',', array_map('intval', $school_ids));

        $classrooms = $wpdb->get_results($wpdb->prepare(
            "SELECT c.*, o.name AS school_name
             FROM {$wpdb->prefix}hl_classroom c
             LEFT JOIN {$wpdb->prefix}hl_orgunit o ON c.school_id = o.orgunit_id
             WHERE c.school_id IN ({$in_ids}) AND c.cycle_id = %d AND c.status = 'active'
             ORDER BY o.name ASC, c.classroom_name ASC",
            $cycle_id
        ));

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

        // Teacher names per classroom (from teaching assignments in this cycle).
        $teacher_names = array();
        $ta_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ta.classroom_id, u.display_name
             FROM {$wpdb->prefix}hl_teaching_assignment ta
             JOIN {$wpdb->prefix}hl_enrollment e ON ta.enrollment_id = e.enrollment_id
             JOIN {$wpdb->users} u ON e.user_id = u.ID
             WHERE e.cycle_id = %d",
            $cycle_id
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

    private function render_tab_assessments($cycle) {
        $cycle_id = absint($cycle->cycle_id);
        $service  = new HL_Assessment_Service();
        $sub_tab  = isset($_GET['assess_tab']) ? sanitize_text_field($_GET['assess_tab']) : 'teacher';
        $base_url = admin_url('admin.php?page=hl-cycles&action=edit&id=' . $cycle_id . '&tab=assessments');

        // Sub-tab navigation
        echo '<div style="margin-bottom:16px; display:flex; gap:6px;">';
        echo '<a href="' . esc_url($base_url . '&assess_tab=teacher') . '" class="button' . ($sub_tab === 'teacher' ? ' button-primary' : '') . '">'
            . esc_html__('Teacher Self-Assessments', 'hl-core') . '</a>';
        echo '<a href="' . esc_url($base_url . '&assess_tab=children') . '" class="button' . ($sub_tab === 'children' ? ' button-primary' : '') . '">'
            . esc_html__('Child Assessments', 'hl-core') . '</a>';
        echo '</div>';

        if ($sub_tab === 'children') {
            $this->render_assessments_children($cycle_id, $service);
        } else {
            $this->render_assessments_teacher($cycle_id, $service);
        }
    }

    /**
     * Teacher Self-Assessment sub-tab within the cycle Assessments tab.
     */
    private function render_assessments_teacher($cycle_id, $service) {
        $instances = $service->get_teacher_assessments_by_cycle($cycle_id);

        // Export buttons
        $export_completion_url = wp_nonce_url(
            admin_url('admin.php?page=hl-assessments&cycle_id=' . $cycle_id . '&export=teacher'),
            'hl_export_teacher_' . $cycle_id
        );
        $export_responses_url = wp_nonce_url(
            admin_url('admin.php?page=hl-assessments&cycle_id=' . $cycle_id . '&export=teacher_responses'),
            'hl_export_teacher_responses_' . $cycle_id
        );
        echo '<div style="margin-bottom:12px; display:flex; gap:6px;">';
        echo '<a href="' . esc_url($export_completion_url) . '" class="button button-small">' . esc_html__('Export Completion CSV', 'hl-core') . '</a>';
        echo '<a href="' . esc_url($export_responses_url) . '" class="button button-small">' . esc_html__('Export Responses CSV', 'hl-core') . '</a>';
        echo '</div>';

        if (empty($instances)) {
            echo '<p>' . esc_html__('No teacher assessment instances found for this cycle.', 'hl-core') . '</p>';
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

            $view_url = admin_url('admin.php?page=hl-assessment-hub&section=teacher-assessments&action=view_teacher&instance_id=' . $inst['instance_id'] . '&cycle_id=' . $cycle_id);

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
     * Child Assessment sub-tab within the cycle Assessments tab.
     */
    private function render_assessments_children($cycle_id, $service) {
        $instances = $service->get_child_assessments_by_cycle($cycle_id);

        // Action buttons
        echo '<div style="margin-bottom:12px; display:flex; gap:6px; flex-wrap:wrap;">';

        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=hl-assessment-hub&section=child-assessments&cycle_id=' . $cycle_id)) . '" style="display:inline;">';
        wp_nonce_field('hl_generate_children_instances', 'hl_generate_children_nonce');
        echo '<input type="hidden" name="cycle_id" value="' . esc_attr($cycle_id) . '" />';
        submit_button(
            __('Generate Instances', 'hl-core'),
            'secondary small',
            'submit',
            false,
            array('onclick' => 'return confirm("' . esc_js(__('Create child assessment instances for all teaching assignments in this cycle?', 'hl-core')) . '");')
        );
        echo '</form>';

        $export_completion_url = wp_nonce_url(
            admin_url('admin.php?page=hl-assessments&cycle_id=' . $cycle_id . '&export=children'),
            'hl_export_children_' . $cycle_id
        );
        $export_responses_url = wp_nonce_url(
            admin_url('admin.php?page=hl-assessments&cycle_id=' . $cycle_id . '&export=children_responses'),
            'hl_export_children_responses_' . $cycle_id
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

            $view_url = admin_url('admin.php?page=hl-assessment-hub&section=child-assessments&action=view_children&instance_id=' . $inst['instance_id'] . '&cycle_id=' . $cycle_id);

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
    // Emails Tab (Control Group Cycles)
    // =========================================================================

    /**
     * Render the Emails tab: shows recipient list and Send button.
     *
     * "Existing" users = registered before 2026-01-01 (had accounts before HL Core).
     * "New" users = registered on/after 2026-01-01 (accounts created by the seeder).
     */
    private function render_tab_emails($cycle) {
        global $wpdb;

        $cycle_id = absint($cycle->cycle_id);

        // Get cycles for this cycle
        $cycles = $wpdb->get_results($wpdb->prepare(
            "SELECT cycle_id, cycle_name, cycle_number
             FROM {$wpdb->prefix}hl_cycle
             WHERE cycle_id = %d
             ORDER BY cycle_number",
            $cycle_id
        ), ARRAY_A);

        if (empty($cycles)) {
            echo '<div class="notice notice-warning" style="margin:0;"><p>'
                . esc_html__('No cycles found for this cycle. Create a cycle first before sending emails.', 'hl-core')
                . '</p></div>';
            return;
        }

        $selected_cycle_id = absint($cycles[0]['cycle_id']);
        $nonce = wp_create_nonce('hl_send_cycle_emails');
        $reset_nonce = wp_create_nonce('hl_reset_email_log');

        // Cycle selector
        echo '<div class="hl-form-section" style="margin-bottom:16px;">';
        echo '<label for="hl-email-cycle-select" style="font-weight:600;margin-right:8px;">' . esc_html__('Select Cycle:', 'hl-core') . '</label>';
        echo '<select id="hl-email-cycle-select" style="min-width:300px;">';
        foreach ($cycles as $cycle) {
            echo '<option value="' . esc_attr($cycle['cycle_id']) . '">'
                . esc_html('Cycle ' . $cycle['cycle_number'] . ': ' . $cycle['cycle_name'])
                . '</option>';
        }
        echo '</select>';
        echo '</div>';

        // Container for AJAX-loaded recipient tables
        echo '<div id="hl-email-recipients-container">';
        $this->render_email_recipients($cycle_id);
        echo '</div>';

        // Inline JS
        ?>
        <script>
        (function(){
            var cycleId = <?php echo (int) $cycle_id; ?>;
            var nonce = '<?php echo esc_js($nonce); ?>';
            var resetNonce = '<?php echo esc_js($reset_nonce); ?>';
            var ajaxUrl = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
            var container = document.getElementById('hl-email-recipients-container');
            var cycleSelect = document.getElementById('hl-email-cycle-select');

            // Cycle change → reload recipients
            cycleSelect.addEventListener('change', function() {
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
                xhr.send('action=hl_send_cycle_emails&sub_action=load_recipients&cycle_id=' + cycleId + '&cycle_id=' + cycleSelect.value + '&_wpnonce=' + nonce);
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
                                    xhr2.send('action=hl_send_cycle_emails&sub_action=load_recipients&cycle_id=' + cycleId + '&cycle_id=' + cycleSelect.value + '&_wpnonce=' + nonce);
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
                        xhr.send('action=hl_send_cycle_emails&sub_action=send&cycle_id=' + cycleId + '&cycle_id=' + cycleSelect.value + '&user_ids=' + ids.join(',') + '&_wpnonce=' + nonce);
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
                                    xhr2.send('action=hl_send_cycle_emails&sub_action=load_recipients&cycle_id=' + cycleId + '&cycle_id=' + cycleSelect.value + '&_wpnonce=' + nonce);
                                } else {
                                    btn.style.opacity = '1';
                                    alert(resp.data || 'Error resetting.');
                                }
                            } catch(e) {
                                btn.style.opacity = '1';
                            }
                        };
                        xhr.send('action=hl_reset_email_log&cycle_id=' + cycleId + '&cycle_id=' + cycleSelect.value + '&user_id=' + userId + '&_wpnonce=' + resetNonce);
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
     * Render the email recipients HTML for a specific cycle.
     * Used both on initial page load and via AJAX reload.
     */
    private function render_email_recipients($cycle_id) {
        global $wpdb;

        $enrollments = $wpdb->get_results($wpdb->prepare(
            "SELECT e.enrollment_id, e.user_id, u.user_email, u.display_name, u.user_login,
                    u.user_registered, o.name AS school_name,
                    el.sent_at, el.email_type AS log_email_type
             FROM {$wpdb->prefix}hl_enrollment e
             JOIN {$wpdb->users} u ON e.user_id = u.ID
             LEFT JOIN {$wpdb->prefix}hl_orgunit o ON e.school_id = o.orgunit_id
             LEFT JOIN {$wpdb->prefix}hl_cycle_email_log el
                 ON el.cycle_id = e.cycle_id AND el.user_id = e.user_id
             WHERE e.cycle_id = %d AND e.status = 'active'
             ORDER BY u.display_name",
            $cycle_id
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
    public function ajax_send_cycle_emails() {
        check_ajax_referer('hl_send_cycle_emails');

        if (!current_user_can('manage_hl_core')) {
            wp_send_json_error(__('Permission denied.', 'hl-core'));
        }

        $sub_action = isset($_POST['sub_action']) ? sanitize_text_field($_POST['sub_action']) : 'send';
        $cycle_id = isset($_POST['cycle_id']) ? absint($_POST['cycle_id']) : 0;
        $cycle_id = isset($_POST['cycle_id']) ? absint($_POST['cycle_id']) : 0;

        if (!$cycle_id || !$cycle_id) {
            wp_send_json_error(__('Invalid cycle or cycle.', 'hl-core'));
        }

        global $wpdb;

        // Validate cycle belongs to cycle
        $cycle = $wpdb->get_row($wpdb->prepare(
            "SELECT cycle_id, cycle_name FROM {$wpdb->prefix}hl_cycle WHERE cycle_id = %d AND cycle_id = %d",
            $cycle_id, $cycle_id
        ));
        if (!$cycle) {
            wp_send_json_error(__('Cycle not found for this cycle.', 'hl-core'));
        }

        // Load recipients sub-action
        if ($sub_action === 'load_recipients') {
            ob_start();
            $this->render_email_recipients($cycle_id);
            $html = ob_get_clean();
            wp_send_json_success(array('html' => $html));
        }

        // Send sub-action
        $user_ids_raw = isset($_POST['user_ids']) ? sanitize_text_field($_POST['user_ids']) : '';
        $user_ids = array_filter(array_map('absint', explode(',', $user_ids_raw)));

        if (empty($user_ids)) {
            wp_send_json_error(__('No recipients selected.', 'hl-core'));
        }

        // Validate users are enrolled in this cycle
        $placeholders = implode(',', array_fill(0, count($user_ids), '%d'));
        $query_args = array_merge(array($cycle_id), $user_ids);
        $enrolled_users = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT e.user_id, u.user_email, u.display_name, u.user_login,
                        u.user_registered, o.name AS school_name
                 FROM {$wpdb->prefix}hl_enrollment e
                 JOIN {$wpdb->users} u ON e.user_id = u.ID
                 LEFT JOIN {$wpdb->prefix}hl_orgunit o ON e.school_id = o.orgunit_id
                 WHERE e.cycle_id = %d AND e.status = 'active' AND e.user_id IN ($placeholders)",
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
                "SELECT log_id FROM {$wpdb->prefix}hl_cycle_email_log
                 WHERE cycle_id = %d AND cycle_id = %d AND user_id = %d",
                $cycle_id, $cycle_id, $enr['user_id']
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
                    "INSERT IGNORE INTO {$wpdb->prefix}hl_cycle_email_log
                     (cycle_id, cycle_id, user_id, email_type, recipient_email, sent_at, sent_by)
                     VALUES (%d, %d, %d, %s, %s, %s, %d)",
                    $cycle_id, $cycle_id, $enr['user_id'], $email_type,
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

        $cycle_id = isset($_POST['cycle_id']) ? absint($_POST['cycle_id']) : 0;
        $cycle_id = isset($_POST['cycle_id']) ? absint($_POST['cycle_id']) : 0;
        $user_id  = isset($_POST['user_id'])  ? absint($_POST['user_id'])  : 0;

        if (!$cycle_id || !$cycle_id || !$user_id) {
            wp_send_json_error(__('Missing parameters.', 'hl-core'));
        }

        global $wpdb;
        $wpdb->delete(
            $wpdb->prefix . 'hl_cycle_email_log',
            array('cycle_id' => $cycle_id, 'cycle_id' => $cycle_id, 'user_id' => $user_id),
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
<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#374151;">You have been enrolled in a research study through <strong>Housman Learning Academy</strong> as part of the Lutheran Services Florida cycle.</p>
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
<p style="margin:0;font-size:12px;color:#9CA3AF;">This email was sent because you are enrolled in a research cycle.<br>Please do not reply to this email.</p>
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
<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#374151;">You have been invited to participate in a research study through <strong>Housman Learning Academy</strong> in cycle with <strong>Lutheran Services Florida</strong>.</p>
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
<p style="margin:0;font-size:12px;color:#9CA3AF;">This email was sent because you were invited to participate in a research cycle.<br>Please do not reply to this email.</p>
</td></tr>
</table></body></html>';
    }
}
