<?php if (!defined('ABSPATH')) exit;

/**
 * Admin OrgUnits Page — Hierarchical District → School View
 *
 * Displays districts as collapsible sections with nested school tables.
 * Edit forms show related data (schools for districts, cohorts/classrooms/staff for schools).
 *
 * @package HL_Core
 */
class HL_Admin_OrgUnits {

    /**
     * Singleton instance
     *
     * @var HL_Admin_OrgUnits|null
     */
    private static $instance = null;

    /**
     * Get singleton instance
     *
     * @return HL_Admin_OrgUnits
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
        // No hooks needed; rendering is called from HL_Admin menu callbacks.
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
                $orgunit_id = isset($_GET['id']) ? absint($_GET['id']) : 0;
                $orgunit    = $this->get_orgunit($orgunit_id);
                if ($orgunit) {
                    $this->render_form($orgunit);
                } else {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Org Unit not found.', 'hl-core') . '</p></div>';
                    $this->render_list();
                }
                break;

            default:
                $this->render_list();
                break;
        }

        echo '</div>';
    }

    // -------------------------------------------------------------------------
    // Data query methods
    // -------------------------------------------------------------------------

    /**
     * Get a single orgunit by ID
     *
     * @param int $orgunit_id
     * @return object|null
     */
    private function get_orgunit($orgunit_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_orgunit WHERE orgunit_id = %d",
            $orgunit_id
        ));
    }

    /**
     * Get all orgunits
     *
     * @return array
     */
    private function get_all_orgunits() {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}hl_orgunit ORDER BY orgunit_type ASC, name ASC"
        );
    }

    /**
     * Get all districts ordered by name
     *
     * @return array
     */
    private function get_districts() {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}hl_orgunit WHERE orgunit_type = 'district' ORDER BY name ASC"
        );
    }

    /**
     * Get all schools grouped by parent_orgunit_id
     *
     * @return array Keyed by parent_orgunit_id (0 for unassigned)
     */
    private function get_schools_grouped() {
        global $wpdb;
        $schools = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}hl_orgunit WHERE orgunit_type = 'school' ORDER BY name ASC"
        );

        $grouped = array();
        foreach ($schools as $school) {
            $parent = $school->parent_orgunit_id ? (int) $school->parent_orgunit_id : 0;
            if (!isset($grouped[$parent])) {
                $grouped[$parent] = array();
            }
            $grouped[$parent][] = $school;
        }

        return $grouped;
    }

    /**
     * Get classroom counts per school
     *
     * @return array school_id => count
     */
    private function get_classroom_counts() {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT school_id, COUNT(*) as cnt FROM {$wpdb->prefix}hl_classroom GROUP BY school_id"
        );

        $counts = array();
        foreach ($rows as $row) {
            $counts[(int) $row->school_id] = (int) $row->cnt;
        }
        return $counts;
    }

    /**
     * Get active cohort counts per school
     *
     * @return array school_id => count
     */
    private function get_active_cohort_counts_per_school() {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT cc.school_id, COUNT(DISTINCT cc.cohort_id) as cnt
             FROM {$wpdb->prefix}hl_cohort_school cc
             JOIN {$wpdb->prefix}hl_cohort c ON c.cohort_id = cc.cohort_id AND c.status = 'active'
             GROUP BY cc.school_id"
        );

        $counts = array();
        foreach ($rows as $row) {
            $counts[(int) $row->school_id] = (int) $row->cnt;
        }
        return $counts;
    }

    /**
     * Get school leader display names per school
     *
     * @return array school_id => [names]
     */
    private function get_school_leader_names() {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT e.school_id, u.display_name
             FROM {$wpdb->prefix}hl_enrollment e
             JOIN {$wpdb->users} u ON u.ID = e.user_id
             WHERE e.roles LIKE '%school_leader%'
               AND e.status = 'active'
               AND e.school_id IS NOT NULL
             ORDER BY u.display_name ASC"
        );

        $leaders = array();
        foreach ($rows as $row) {
            $cid = (int) $row->school_id;
            if (!isset($leaders[$cid])) {
                $leaders[$cid] = array();
            }
            if (!in_array($row->display_name, $leaders[$cid], true)) {
                $leaders[$cid][] = $row->display_name;
            }
        }
        return $leaders;
    }

    // -------------------------------------------------------------------------
    // Action handlers (unchanged)
    // -------------------------------------------------------------------------

    /**
     * Handle form submissions
     */
    private function handle_actions() {
        if (!isset($_POST['hl_orgunit_nonce'])) {
            return;
        }

        if (!wp_verify_nonce($_POST['hl_orgunit_nonce'], 'hl_save_orgunit')) {
            wp_die(__('Security check failed.', 'hl-core'));
        }

        if (!current_user_can('manage_hl_core')) {
            wp_die(__('You do not have permission to perform this action.', 'hl-core'));
        }

        global $wpdb;

        $orgunit_id = isset($_POST['orgunit_id']) ? absint($_POST['orgunit_id']) : 0;

        $data = array(
            'name'             => sanitize_text_field($_POST['name']),
            'orgunit_code'     => sanitize_text_field($_POST['orgunit_code']),
            'orgunit_type'     => sanitize_text_field($_POST['orgunit_type']),
            'parent_orgunit_id'=> !empty($_POST['parent_orgunit_id']) ? absint($_POST['parent_orgunit_id']) : null,
            'status'           => sanitize_text_field($_POST['status']),
        );

        if (empty($data['orgunit_code'])) {
            $data['orgunit_code'] = HL_Normalization::generate_code($data['name']);
        }

        if ($orgunit_id > 0) {
            $wpdb->update(
                $wpdb->prefix . 'hl_orgunit',
                $data,
                array('orgunit_id' => $orgunit_id)
            );
            $redirect = admin_url('admin.php?page=hl-orgunits&message=updated');
        } else {
            $data['orgunit_uuid'] = HL_DB_Utils::generate_uuid();
            $wpdb->insert($wpdb->prefix . 'hl_orgunit', $data);
            $redirect = admin_url('admin.php?page=hl-orgunits&message=created');
        }

        wp_redirect($redirect);
        exit;
    }

    /**
     * Handle delete action
     */
    private function handle_delete() {
        $orgunit_id = isset($_GET['id']) ? absint($_GET['id']) : 0;

        if (!$orgunit_id) {
            return;
        }

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'hl_delete_orgunit_' . $orgunit_id)) {
            wp_die(__('Security check failed.', 'hl-core'));
        }

        if (!current_user_can('manage_hl_core')) {
            wp_die(__('You do not have permission to perform this action.', 'hl-core'));
        }

        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'hl_orgunit', array('orgunit_id' => $orgunit_id));

        wp_redirect(admin_url('admin.php?page=hl-orgunits&message=deleted'));
        exit;
    }

    // -------------------------------------------------------------------------
    // List view — hierarchical
    // -------------------------------------------------------------------------

    /**
     * Render the hierarchical org units list
     */
    private function render_list() {
        // Batch-load all data
        $districts        = $this->get_districts();
        $schools_grouped  = $this->get_schools_grouped();
        $classroom_counts = $this->get_classroom_counts();
        $cohort_counts    = $this->get_active_cohort_counts_per_school();
        $leader_names     = $this->get_school_leader_names();

        $stats = array(
            'classrooms' => $classroom_counts,
            'cohorts'    => $cohort_counts,
            'leaders'    => $leader_names,
        );

        // Success messages
        if (isset($_GET['message'])) {
            $msg = sanitize_text_field($_GET['message']);
            if ($msg === 'created') {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Org Unit created successfully.', 'hl-core') . '</p></div>';
            } elseif ($msg === 'updated') {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Org Unit updated successfully.', 'hl-core') . '</p></div>';
            } elseif ($msg === 'deleted') {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Org Unit deleted successfully.', 'hl-core') . '</p></div>';
            }
        }

        // Header
        echo '<h1 class="wp-heading-inline">' . esc_html__('Org Units', 'hl-core') . '</h1>';
        $add_district_url = admin_url('admin.php?page=hl-orgunits&action=new&type=district');
        $add_school_url   = admin_url('admin.php?page=hl-orgunits&action=new&type=school');
        echo ' <a href="' . esc_url($add_district_url) . '" class="page-title-action">' . esc_html__('Add District', 'hl-core') . '</a>';
        echo ' <a href="' . esc_url($add_school_url) . '" class="page-title-action">' . esc_html__('Add School', 'hl-core') . '</a>';
        echo '<hr class="wp-header-end">';

        if (empty($districts) && empty($schools_grouped)) {
            echo '<p>' . esc_html__('No org units found. Create your first district or school.', 'hl-core') . '</p>';
            return;
        }

        // Render each district section
        foreach ($districts as $district) {
            $district_schools = isset($schools_grouped[(int) $district->orgunit_id])
                ? $schools_grouped[(int) $district->orgunit_id]
                : array();
            $this->render_district_section($district, $district_schools, $stats);
        }

        // Render unassigned schools
        if (!empty($schools_grouped[0])) {
            $this->render_unassigned_section($schools_grouped[0], $stats);
        }

        $this->render_list_styles();
        $this->render_collapsible_js();
    }

    /**
     * Render a single district as a collapsible section
     *
     * @param object $district
     * @param array  $schools
     * @param array  $stats
     */
    private function render_district_section($district, $schools, $stats) {
        $did = (int) $district->orgunit_id;
        $edit_url   = admin_url('admin.php?page=hl-orgunits&action=edit&id=' . $did);
        $delete_url = wp_nonce_url(
            admin_url('admin.php?page=hl-orgunits&action=delete&id=' . $did),
            'hl_delete_orgunit_' . $did
        );
        $add_school_url = admin_url('admin.php?page=hl-orgunits&action=new&type=school&parent_id=' . $did);

        $school_count = count($schools);

        // Sum cohort counts from child schools
        $district_cohort_count = 0;
        foreach ($schools as $c) {
            $cid = (int) $c->orgunit_id;
            $district_cohort_count += isset($stats['cohorts'][$cid]) ? $stats['cohorts'][$cid] : 0;
        }

        $meta_parts = array();
        $meta_parts[] = sprintf(_n('%d School', '%d Schools', $school_count, 'hl-core'), $school_count);
        $meta_parts[] = sprintf(_n('%d Active Cohort', '%d Active Cohorts', $district_cohort_count, 'hl-core'), $district_cohort_count);

        // Status
        $status_class = 'active';
        if ($district->status !== 'active') {
            $status_class = $district->status;
        }

        echo '<div class="hl-district-section">';

        // Header
        echo '<div class="hl-district-header" data-district-id="' . esc_attr($did) . '">';
        echo '<span class="hl-collapse-arrow dashicons dashicons-arrow-down-alt2"></span>';
        echo '<strong><a href="' . esc_url($edit_url) . '">' . esc_html($district->name) . '</a></strong>';
        echo ' <code style="margin-left:6px;">' . esc_html($district->orgunit_code) . '</code>';
        if ($district->status !== 'active') {
            echo ' <span class="hl-status-badge ' . esc_attr($status_class) . '">' . esc_html(ucfirst($district->status)) . '</span>';
        }
        echo '<span class="hl-district-meta">' . esc_html(implode(' &middot; ', $meta_parts)) . '</span>';
        echo '<span class="hl-district-actions">';
        echo '<a href="' . esc_url($edit_url) . '" class="button button-small">' . esc_html__('Edit', 'hl-core') . '</a> ';
        echo '<a href="' . esc_url($add_school_url) . '" class="button button-small">' . esc_html__('Add School', 'hl-core') . '</a> ';
        echo '<a href="' . esc_url($delete_url) . '" class="button button-small button-link-delete" onclick="return confirm(\'' . esc_js(__('Are you sure you want to delete this district? Its child schools will become unassigned.', 'hl-core')) . '\');">' . esc_html__('Delete', 'hl-core') . '</a>';
        echo '</span>';
        echo '</div>';

        // Body
        echo '<div class="hl-district-body" id="hl-district-body-' . esc_attr($did) . '">';

        if (empty($schools)) {
            echo '<p class="hl-no-schools">' . esc_html__('No schools in this district.', 'hl-core') . '</p>';
        } else {
            $this->render_school_table($schools, $stats);
        }

        echo '</div>'; // .hl-district-body
        echo '</div>'; // .hl-district-section
    }

    /**
     * Render the unassigned schools section
     *
     * @param array $schools
     * @param array $stats
     */
    private function render_unassigned_section($schools, $stats) {
        echo '<div class="hl-district-section hl-unassigned-section">';
        echo '<div class="hl-district-header hl-unassigned-header">';
        echo '<strong style="color:#8c8f94;">' . esc_html__('Unassigned Schools', 'hl-core') . '</strong>';
        echo '<span class="hl-district-meta" style="color:#8c8f94;">' . sprintf(_n('%d School', '%d Schools', count($schools), 'hl-core'), count($schools)) . '</span>';
        echo '</div>';

        echo '<div class="hl-district-body">';
        $this->render_school_table($schools, $stats);
        echo '</div>';
        echo '</div>';
    }

    /**
     * Render a table of schools
     *
     * @param array $schools
     * @param array $stats
     */
    private function render_school_table($schools, $stats) {
        echo '<table class="widefat striped hl-schools-table">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Name', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Code', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Leader(s)', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Classrooms', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Active Cohorts', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Status', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Actions', 'hl-core') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($schools as $school) {
            $cid = (int) $school->orgunit_id;
            $edit_url   = admin_url('admin.php?page=hl-orgunits&action=edit&id=' . $cid);
            $delete_url = wp_nonce_url(
                admin_url('admin.php?page=hl-orgunits&action=delete&id=' . $cid),
                'hl_delete_orgunit_' . $cid
            );

            $leaders     = isset($stats['leaders'][$cid]) ? $stats['leaders'][$cid] : array();
            $classrooms  = isset($stats['classrooms'][$cid]) ? $stats['classrooms'][$cid] : 0;
            $cohorts     = isset($stats['cohorts'][$cid]) ? $stats['cohorts'][$cid] : 0;

            $status_class = $school->status;

            echo '<tr>';
            echo '<td><strong><a href="' . esc_url($edit_url) . '">' . esc_html($school->name) . '</a></strong></td>';
            echo '<td><code>' . esc_html($school->orgunit_code) . '</code></td>';
            echo '<td>' . (!empty($leaders) ? esc_html(implode(', ', $leaders)) : '<span style="color:#8c8f94;">&mdash;</span>') . '</td>';
            echo '<td>' . esc_html($classrooms) . '</td>';
            echo '<td>' . esc_html($cohorts) . '</td>';
            echo '<td><span class="hl-status-badge ' . esc_attr($status_class) . '">' . esc_html(ucfirst($school->status)) . '</span></td>';
            echo '<td>';
            echo '<a href="' . esc_url($edit_url) . '" class="button button-small">' . esc_html__('Edit', 'hl-core') . '</a> ';
            echo '<a href="' . esc_url($delete_url) . '" class="button button-small button-link-delete" onclick="return confirm(\'' . esc_js(__('Are you sure you want to delete this school?', 'hl-core')) . '\');">' . esc_html__('Delete', 'hl-core') . '</a>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * Inline styles for hierarchy view
     */
    private function render_list_styles() {
        ?>
        <style>
            .hl-district-section {
                margin-bottom: 2px;
                border: 1px solid #ccd0d4;
                border-radius: 4px;
                background: #fff;
            }
            .hl-district-header {
                display: flex;
                align-items: center;
                gap: 8px;
                padding: 12px 16px;
                cursor: pointer;
                user-select: none;
                background: #f6f7f7;
                border-bottom: 1px solid #ccd0d4;
                flex-wrap: wrap;
            }
            .hl-district-header:hover {
                background: #f0f0f1;
            }
            .hl-unassigned-header {
                cursor: default;
                background: #fafafa;
                border-bottom: 1px solid #ddd;
            }
            .hl-unassigned-header:hover {
                background: #fafafa;
            }
            .hl-collapse-arrow {
                transition: transform 0.2s ease;
                color: #8c8f94;
                font-size: 16px;
                width: 16px;
                height: 16px;
                flex-shrink: 0;
            }
            .hl-collapse-arrow.collapsed {
                transform: rotate(-90deg);
            }
            .hl-district-meta {
                margin-left: auto;
                font-size: 13px;
                color: #646970;
                white-space: nowrap;
            }
            .hl-district-actions {
                display: flex;
                gap: 4px;
                margin-left: 12px;
                flex-shrink: 0;
            }
            .hl-district-body {
                padding: 0;
            }
            .hl-district-body.collapsed {
                display: none;
            }
            .hl-no-schools {
                padding: 16px;
                color: #8c8f94;
                font-style: italic;
                margin: 0;
            }
            .hl-schools-table {
                border: none;
                border-radius: 0;
                margin: 0;
            }
            .hl-schools-table thead th {
                border-top: none;
            }
            .hl-district-section .hl-status-badge {
                font-size: 11px;
                padding: 2px 6px;
            }
            /* Edit form extras */
            .hl-edit-extras {
                margin-top: 30px;
                padding-top: 20px;
                border-top: 1px solid #ccd0d4;
            }
            .hl-edit-extras h3 {
                margin-top: 24px;
                margin-bottom: 10px;
            }
            .hl-edit-extras h3:first-child {
                margin-top: 0;
            }
        </style>
        <?php
    }

    /**
     * Inline JS for collapsible district sections
     */
    private function render_collapsible_js() {
        ?>
        <script>
        (function() {
            document.querySelectorAll('.hl-district-header[data-district-id]').forEach(function(header) {
                header.addEventListener('click', function(e) {
                    // Don't toggle when clicking links or buttons
                    if (e.target.closest('a, button')) {
                        return;
                    }
                    var did = header.getAttribute('data-district-id');
                    var body = document.getElementById('hl-district-body-' + did);
                    var arrow = header.querySelector('.hl-collapse-arrow');
                    if (body) {
                        body.classList.toggle('collapsed');
                    }
                    if (arrow) {
                        arrow.classList.toggle('collapsed');
                    }
                });
            });
        })();
        </script>
        <?php
    }

    // -------------------------------------------------------------------------
    // Form view — enhanced
    // -------------------------------------------------------------------------

    /**
     * Render the create/edit form
     *
     * @param object|null $orgunit Orgunit row for edit, null for create.
     */
    private function render_form($orgunit = null) {
        $is_edit = ($orgunit !== null);

        // Pre-fill from GET params (for "Add School to this District" links)
        $prefill_type      = isset($_GET['type']) ? sanitize_text_field($_GET['type']) : '';
        $prefill_parent_id = isset($_GET['parent_id']) ? absint($_GET['parent_id']) : 0;

        // Dynamic title
        if ($is_edit) {
            $type_label = ($orgunit->orgunit_type === 'district') ? __('District', 'hl-core') : __('School', 'hl-core');
            $title = sprintf(__('Edit %s: %s', 'hl-core'), $type_label, $orgunit->name);
        } else {
            if ($prefill_type === 'district') {
                $title = __('Add New District', 'hl-core');
            } elseif ($prefill_type === 'school') {
                $title = __('Add New School', 'hl-core');
            } else {
                $title = __('Add New Org Unit', 'hl-core');
            }
        }

        // Get districts for parent dropdown
        global $wpdb;
        $districts = $wpdb->get_results(
            "SELECT orgunit_id, name FROM {$wpdb->prefix}hl_orgunit WHERE orgunit_type = 'district' ORDER BY name ASC"
        );

        echo '<h1>' . esc_html($title) . '</h1>';

        // Contextual breadcrumb
        if ($is_edit && $orgunit->orgunit_type === 'school' && $orgunit->parent_orgunit_id) {
            $parent = $this->get_orgunit($orgunit->parent_orgunit_id);
            if ($parent) {
                $parent_edit_url = admin_url('admin.php?page=hl-orgunits&action=edit&id=' . $parent->orgunit_id);
                echo '<a href="' . esc_url($parent_edit_url) . '">&larr; ' . sprintf(esc_html__('Back to %s', 'hl-core'), esc_html($parent->name)) . '</a>';
            } else {
                echo '<a href="' . esc_url(admin_url('admin.php?page=hl-orgunits')) . '">&larr; ' . esc_html__('Back to Org Units', 'hl-core') . '</a>';
            }
        } else {
            echo '<a href="' . esc_url(admin_url('admin.php?page=hl-orgunits')) . '">&larr; ' . esc_html__('Back to Org Units', 'hl-core') . '</a>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=hl-orgunits')) . '">';
        wp_nonce_field('hl_save_orgunit', 'hl_orgunit_nonce');

        if ($is_edit) {
            echo '<input type="hidden" name="orgunit_id" value="' . esc_attr($orgunit->orgunit_id) . '" />';
        }

        echo '<table class="form-table">';

        // Name
        echo '<tr>';
        echo '<th scope="row"><label for="name">' . esc_html__('Name', 'hl-core') . '</label></th>';
        echo '<td><input type="text" id="name" name="name" value="' . esc_attr($is_edit ? $orgunit->name : '') . '" class="regular-text" required /></td>';
        echo '</tr>';

        // Code
        echo '<tr>';
        echo '<th scope="row"><label for="orgunit_code">' . esc_html__('Code', 'hl-core') . '</label></th>';
        echo '<td><input type="text" id="orgunit_code" name="orgunit_code" value="' . esc_attr($is_edit ? $orgunit->orgunit_code : '') . '" class="regular-text" />';
        echo '<p class="description">' . esc_html__('Leave blank to auto-generate from name.', 'hl-core') . '</p></td>';
        echo '</tr>';

        // Type
        $current_type = $is_edit ? $orgunit->orgunit_type : ($prefill_type ?: 'district');
        echo '<tr>';
        echo '<th scope="row"><label for="orgunit_type">' . esc_html__('Type', 'hl-core') . '</label></th>';
        echo '<td><select id="orgunit_type" name="orgunit_type">';
        echo '<option value="district"' . selected($current_type, 'district', false) . '>' . esc_html__('District', 'hl-core') . '</option>';
        echo '<option value="school"' . selected($current_type, 'school', false) . '>' . esc_html__('School', 'hl-core') . '</option>';
        echo '</select></td>';
        echo '</tr>';

        // Parent (for schools)
        $current_parent = $is_edit ? $orgunit->parent_orgunit_id : ($prefill_parent_id ?: '');
        echo '<tr>';
        echo '<th scope="row"><label for="parent_orgunit_id">' . esc_html__('Parent District', 'hl-core') . '</label></th>';
        echo '<td><select id="parent_orgunit_id" name="parent_orgunit_id">';
        echo '<option value="">' . esc_html__('-- None (Top Level) --', 'hl-core') . '</option>';
        if ($districts) {
            foreach ($districts as $district) {
                // Skip self when editing
                if ($is_edit && $district->orgunit_id == $orgunit->orgunit_id) {
                    continue;
                }
                echo '<option value="' . esc_attr($district->orgunit_id) . '"' . selected($current_parent, $district->orgunit_id, false) . '>' . esc_html($district->name) . '</option>';
            }
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__('Required for Schools. Select the parent District.', 'hl-core') . '</p></td>';
        echo '</tr>';

        // Status
        $current_status = $is_edit ? $orgunit->status : 'active';
        echo '<tr>';
        echo '<th scope="row"><label for="status">' . esc_html__('Status', 'hl-core') . '</label></th>';
        echo '<td><select id="status" name="status">';
        foreach (array('active', 'inactive', 'archived') as $status) {
            echo '<option value="' . esc_attr($status) . '"' . selected($current_status, $status, false) . '>' . esc_html(ucfirst($status)) . '</option>';
        }
        echo '</select></td>';
        echo '</tr>';

        echo '</table>';

        submit_button($is_edit ? __('Update Org Unit', 'hl-core') : __('Create Org Unit', 'hl-core'));

        echo '</form>';

        // Related data sections for edit mode
        if ($is_edit) {
            echo '<div class="hl-edit-extras">';
            if ($orgunit->orgunit_type === 'district') {
                $this->render_district_edit_extras($orgunit);
            } elseif ($orgunit->orgunit_type === 'school') {
                $this->render_school_edit_extras($orgunit);
            }
            echo '</div>';
        }
    }

    // -------------------------------------------------------------------------
    // Edit form extras
    // -------------------------------------------------------------------------

    /**
     * Render related data for a district being edited: child schools table
     *
     * @param object $orgunit
     */
    private function render_district_edit_extras($orgunit) {
        global $wpdb;
        $did = (int) $orgunit->orgunit_id;

        $schools = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_orgunit WHERE orgunit_type = 'school' AND parent_orgunit_id = %d ORDER BY name ASC",
            $did
        ));

        $add_school_url = admin_url('admin.php?page=hl-orgunits&action=new&type=school&parent_id=' . $did);

        echo '<h3>' . esc_html__('Schools in this District', 'hl-core') . '</h3>';

        if (empty($schools)) {
            echo '<p>' . esc_html__('No schools assigned to this district yet.', 'hl-core') . '</p>';
        } else {
            echo '<table class="widefat striped">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__('Name', 'hl-core') . '</th>';
            echo '<th>' . esc_html__('Code', 'hl-core') . '</th>';
            echo '<th>' . esc_html__('Status', 'hl-core') . '</th>';
            echo '<th>' . esc_html__('Actions', 'hl-core') . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($schools as $school) {
                $edit_url   = admin_url('admin.php?page=hl-orgunits&action=edit&id=' . $school->orgunit_id);
                $delete_url = wp_nonce_url(
                    admin_url('admin.php?page=hl-orgunits&action=delete&id=' . $school->orgunit_id),
                    'hl_delete_orgunit_' . $school->orgunit_id
                );

                echo '<tr>';
                echo '<td><strong><a href="' . esc_url($edit_url) . '">' . esc_html($school->name) . '</a></strong></td>';
                echo '<td><code>' . esc_html($school->orgunit_code) . '</code></td>';
                echo '<td><span class="hl-status-badge ' . esc_attr($school->status) . '">' . esc_html(ucfirst($school->status)) . '</span></td>';
                echo '<td>';
                echo '<a href="' . esc_url($edit_url) . '" class="button button-small">' . esc_html__('Edit', 'hl-core') . '</a> ';
                echo '<a href="' . esc_url($delete_url) . '" class="button button-small button-link-delete" onclick="return confirm(\'' . esc_js(__('Are you sure?', 'hl-core')) . '\');">' . esc_html__('Delete', 'hl-core') . '</a>';
                echo '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }

        echo '<p><a href="' . esc_url($add_school_url) . '" class="button">' . esc_html__('Add School to this District', 'hl-core') . '</a></p>';
    }

    /**
     * Render related data for a school being edited: cohorts, classrooms, staff
     *
     * @param object $orgunit
     */
    private function render_school_edit_extras($orgunit) {
        global $wpdb;
        $cid = (int) $orgunit->orgunit_id;

        // --- Linked Cohorts ---
        echo '<h3>' . esc_html__('Linked Cohorts', 'hl-core') . '</h3>';

        $cohorts = $wpdb->get_results($wpdb->prepare(
            "SELECT c.cohort_id, c.cohort_name, c.status, c.start_date
             FROM {$wpdb->prefix}hl_cohort_school cc
             JOIN {$wpdb->prefix}hl_cohort c ON c.cohort_id = cc.cohort_id
             WHERE cc.school_id = %d
             ORDER BY c.start_date DESC",
            $cid
        ));

        if (empty($cohorts)) {
            echo '<p>' . esc_html__('No cohorts linked to this school.', 'hl-core') . '</p>';
        } else {
            echo '<table class="widefat striped">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__('Cohort', 'hl-core') . '</th>';
            echo '<th>' . esc_html__('Status', 'hl-core') . '</th>';
            echo '<th>' . esc_html__('Start Date', 'hl-core') . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($cohorts as $cohort) {
                $cohort_url = admin_url('admin.php?page=hl-cohorts&action=edit&id=' . $cohort->cohort_id);
                echo '<tr>';
                echo '<td><a href="' . esc_url($cohort_url) . '">' . esc_html($cohort->cohort_name) . '</a></td>';
                echo '<td><span class="hl-status-badge ' . esc_attr($cohort->status) . '">' . esc_html(ucfirst($cohort->status)) . '</span></td>';
                echo '<td>' . esc_html($cohort->start_date) . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }

        // --- Classrooms ---
        echo '<h3>' . esc_html__('Classrooms', 'hl-core') . '</h3>';

        $classrooms = $wpdb->get_results($wpdb->prepare(
            "SELECT cl.classroom_id, cl.classroom_name, cl.age_band,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}hl_child_classroom_current ccc WHERE ccc.classroom_id = cl.classroom_id) as child_count
             FROM {$wpdb->prefix}hl_classroom cl
             WHERE cl.school_id = %d
             ORDER BY cl.classroom_name ASC",
            $cid
        ));

        if (empty($classrooms)) {
            echo '<p>' . esc_html__('No classrooms at this school.', 'hl-core') . '</p>';
        } else {
            echo '<table class="widefat striped">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__('Classroom', 'hl-core') . '</th>';
            echo '<th>' . esc_html__('Age Band', 'hl-core') . '</th>';
            echo '<th>' . esc_html__('Children', 'hl-core') . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($classrooms as $room) {
                $room_url = admin_url('admin.php?page=hl-classrooms&action=edit&id=' . $room->classroom_id);
                echo '<tr>';
                echo '<td><a href="' . esc_url($room_url) . '">' . esc_html($room->classroom_name) . '</a></td>';
                echo '<td>' . ($room->age_band ? esc_html(ucfirst($room->age_band)) : '<span style="color:#8c8f94;">&mdash;</span>') . '</td>';
                echo '<td>' . esc_html($room->child_count) . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }

        // --- Staff / Leaders ---
        echo '<h3>' . esc_html__('Staff &amp; Leaders', 'hl-core') . '</h3>';

        $staff = $wpdb->get_results($wpdb->prepare(
            "SELECT e.enrollment_id, e.roles, u.display_name, u.user_email, c.cohort_name
             FROM {$wpdb->prefix}hl_enrollment e
             JOIN {$wpdb->users} u ON u.ID = e.user_id
             JOIN {$wpdb->prefix}hl_cohort c ON c.cohort_id = e.cohort_id
             WHERE e.school_id = %d AND e.status = 'active'
             ORDER BY u.display_name ASC",
            $cid
        ));

        if (empty($staff)) {
            echo '<p>' . esc_html__('No staff enrolled at this school.', 'hl-core') . '</p>';
        } else {
            echo '<table class="widefat striped">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__('Name', 'hl-core') . '</th>';
            echo '<th>' . esc_html__('Email', 'hl-core') . '</th>';
            echo '<th>' . esc_html__('Roles', 'hl-core') . '</th>';
            echo '<th>' . esc_html__('Cohort', 'hl-core') . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($staff as $person) {
                $roles_arr = json_decode($person->roles, true);
                $roles_display = '';
                if (is_array($roles_arr)) {
                    $role_tags = array();
                    foreach ($roles_arr as $role) {
                        $role_tags[] = '<span class="hl-role-tag">' . esc_html(ucfirst(str_replace('_', ' ', $role))) . '</span>';
                    }
                    $roles_display = implode(' ', $role_tags);
                }

                $enrollment_url = admin_url('admin.php?page=hl-enrollments&action=edit&id=' . $person->enrollment_id);

                echo '<tr>';
                echo '<td><a href="' . esc_url($enrollment_url) . '">' . esc_html($person->display_name) . '</a></td>';
                echo '<td>' . esc_html($person->user_email) . '</td>';
                echo '<td>' . $roles_display . '</td>';
                echo '<td>' . esc_html($person->cohort_name) . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }
    }
}
