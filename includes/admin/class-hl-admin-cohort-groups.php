<?php
if (!defined('ABSPATH')) exit;

/**
 * Admin Cohort Groups Page
 *
 * Manage cohort groups (program-level grouping for cross-cohort reporting).
 * Full CRUD: list, create, edit, delete.
 *
 * @package HL_Core
 */
class HL_Admin_Cohort_Groups {

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
        if (isset($_POST['hl_cohort_group_nonce'])) {
            $this->handle_save();
        }

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

            case 'edit':
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

    private function handle_save() {
        if (!wp_verify_nonce($_POST['hl_cohort_group_nonce'], 'hl_save_cohort_group')) {
            wp_die(__('Security check failed.', 'hl-core'));
        }
        if (!current_user_can('manage_hl_core')) {
            wp_die(__('You do not have permission.', 'hl-core'));
        }

        global $wpdb;

        $group_id    = isset($_POST['group_id']) ? absint($_POST['group_id']) : 0;
        $group_name  = sanitize_text_field($_POST['group_name']);
        $group_code  = sanitize_text_field($_POST['group_code']);
        $description = sanitize_textarea_field($_POST['description']);
        $status      = in_array($_POST['status'], array('active', 'archived'), true) ? $_POST['status'] : 'active';

        if (empty($group_name)) {
            wp_redirect(admin_url('admin.php?page=hl-cohort-groups&action=' . ($group_id ? 'edit&id=' . $group_id : 'new') . '&message=error'));
            exit;
        }

        if (empty($group_code)) {
            $group_code = HL_Normalization::generate_code($group_name);
        }

        $data = array(
            'group_name'  => $group_name,
            'group_code'  => $group_code,
            'description' => $description,
            'status'      => $status,
        );

        if ($group_id > 0) {
            $wpdb->update(
                $wpdb->prefix . 'hl_cohort_group',
                $data,
                array('group_id' => $group_id)
            );
            $message = 'updated';
        } else {
            $data['group_uuid'] = HL_DB_Utils::generate_uuid();
            $wpdb->insert($wpdb->prefix . 'hl_cohort_group', $data);
            $group_id = $wpdb->insert_id;
            $message  = 'created';
        }

        if (class_exists('HL_Audit_Service')) {
            HL_Audit_Service::log(
                $message === 'created' ? 'cohort_group_created' : 'cohort_group_updated',
                get_current_user_id(),
                null, null, $group_id,
                sprintf('Cohort group "%s" %s', $group_name, $message)
            );
        }

        wp_redirect(admin_url('admin.php?page=hl-cohort-groups&message=' . $message));
        exit;
    }

    private function handle_delete() {
        $group_id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        if (!$group_id) return;

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'hl_delete_cohort_group_' . $group_id)) {
            wp_die(__('Security check failed.', 'hl-core'));
        }
        if (!current_user_can('manage_hl_core')) {
            wp_die(__('You do not have permission.', 'hl-core'));
        }

        global $wpdb;

        // Unlink any cohorts from this group.
        $wpdb->update(
            $wpdb->prefix . 'hl_cohort',
            array('cohort_group_id' => null),
            array('cohort_group_id' => $group_id)
        );

        $wpdb->delete($wpdb->prefix . 'hl_cohort_group', array('group_id' => $group_id));

        if (class_exists('HL_Audit_Service')) {
            HL_Audit_Service::log(
                'cohort_group_deleted',
                get_current_user_id(),
                null, null, $group_id,
                sprintf('Cohort group #%d deleted', $group_id)
            );
        }

        wp_redirect(admin_url('admin.php?page=hl-cohort-groups&message=deleted'));
        exit;
    }

    // =========================================================================
    // List View
    // =========================================================================

    private function render_list() {
        global $wpdb;

        // Messages.
        if (isset($_GET['message'])) {
            $msgs = array(
                'created' => array('success', __('Cohort group created.', 'hl-core')),
                'updated' => array('success', __('Cohort group updated.', 'hl-core')),
                'deleted' => array('success', __('Cohort group deleted.', 'hl-core')),
                'error'   => array('error', __('An error occurred. Please check required fields.', 'hl-core')),
            );
            $m = sanitize_text_field($_GET['message']);
            if (isset($msgs[$m])) {
                echo '<div class="notice notice-' . esc_attr($msgs[$m][0]) . ' is-dismissible"><p>' . esc_html($msgs[$m][1]) . '</p></div>';
            }
        }

        echo '<h1 class="wp-heading-inline">' . esc_html__('Cohort Groups', 'hl-core') . '</h1>';
        echo ' <a href="' . esc_url(admin_url('admin.php?page=hl-cohort-groups&action=new')) . '" class="page-title-action">' . esc_html__('Add New', 'hl-core') . '</a>';
        echo '<hr class="wp-header-end">';
        echo '<p class="description">' . esc_html__('Cohort groups allow you to aggregate multiple cohorts under one program for cross-cohort reporting.', 'hl-core') . '</p>';

        $groups = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}hl_cohort_group ORDER BY group_name ASC",
            ARRAY_A
        );

        // Count cohorts per group.
        $cohort_counts = array();
        $counts = $wpdb->get_results(
            "SELECT cohort_group_id, COUNT(*) AS cnt
             FROM {$wpdb->prefix}hl_cohort
             WHERE cohort_group_id IS NOT NULL
             GROUP BY cohort_group_id",
            ARRAY_A
        );
        foreach ($counts ?: array() as $row) {
            $cohort_counts[$row['cohort_group_id']] = (int) $row['cnt'];
        }

        if (empty($groups)) {
            echo '<p>' . esc_html__('No cohort groups found. Create your first group to start aggregating cohorts.', 'hl-core') . '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('ID', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Name', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Code', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Status', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Cohorts', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Description', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Actions', 'hl-core') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($groups as $g) {
            $edit_url   = admin_url('admin.php?page=hl-cohort-groups&action=edit&id=' . $g['group_id']);
            $delete_url = wp_nonce_url(
                admin_url('admin.php?page=hl-cohort-groups&action=delete&id=' . $g['group_id']),
                'hl_delete_cohort_group_' . $g['group_id']
            );

            $status_color = $g['status'] === 'active' ? '#00a32a' : '#8c8f94';
            $num_cohorts  = isset($cohort_counts[$g['group_id']]) ? $cohort_counts[$g['group_id']] : 0;

            echo '<tr>';
            echo '<td>' . esc_html($g['group_id']) . '</td>';
            echo '<td><strong><a href="' . esc_url($edit_url) . '">' . esc_html($g['group_name']) . '</a></strong></td>';
            echo '<td><code>' . esc_html($g['group_code']) . '</code></td>';
            echo '<td><span style="color:' . esc_attr($status_color) . '; font-weight:600;">' . esc_html(ucfirst($g['status'])) . '</span></td>';
            echo '<td>' . esc_html($num_cohorts) . '</td>';
            echo '<td>' . esc_html(wp_trim_words($g['description'] ?: '', 12, '...')) . '</td>';
            echo '<td>';
            echo '<a href="' . esc_url($edit_url) . '" class="button button-small">' . esc_html__('Edit', 'hl-core') . '</a> ';
            echo '<a href="' . esc_url($delete_url) . '" class="button button-small button-link-delete" onclick="return confirm(\'' . esc_js(__('Delete this cohort group? Cohorts will be unlinked but not deleted.', 'hl-core')) . '\');">' . esc_html__('Delete', 'hl-core') . '</a>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    // =========================================================================
    // Create / Edit Form
    // =========================================================================

    private function render_form() {
        global $wpdb;

        $group_id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        $group    = null;
        $is_edit  = false;

        if ($group_id) {
            $group = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}hl_cohort_group WHERE group_id = %d",
                $group_id
            ), ARRAY_A);
            if ($group) {
                $is_edit = true;
            }
        }

        echo '<h1>' . ($is_edit ? esc_html__('Edit Cohort Group', 'hl-core') : esc_html__('Add New Cohort Group', 'hl-core')) . '</h1>';
        echo '<a href="' . esc_url(admin_url('admin.php?page=hl-cohort-groups')) . '">&larr; ' . esc_html__('Back to Cohort Groups', 'hl-core') . '</a>';

        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=hl-cohort-groups')) . '">';
        wp_nonce_field('hl_save_cohort_group', 'hl_cohort_group_nonce');

        if ($is_edit) {
            echo '<input type="hidden" name="group_id" value="' . esc_attr($group['group_id']) . '" />';
        }

        echo '<table class="form-table">';

        // Name
        echo '<tr><th scope="row"><label for="group_name">' . esc_html__('Group Name', 'hl-core') . '</label></th>';
        echo '<td><input type="text" id="group_name" name="group_name" value="' . esc_attr($is_edit ? $group['group_name'] : '') . '" class="regular-text" required /></td></tr>';

        // Code
        echo '<tr><th scope="row"><label for="group_code">' . esc_html__('Group Code', 'hl-core') . '</label></th>';
        echo '<td><input type="text" id="group_code" name="group_code" value="' . esc_attr($is_edit ? $group['group_code'] : '') . '" class="regular-text" />';
        echo '<p class="description">' . esc_html__('Leave blank to auto-generate from name.', 'hl-core') . '</p></td></tr>';

        // Status
        $current_status = $is_edit ? $group['status'] : 'active';
        echo '<tr><th scope="row"><label for="status">' . esc_html__('Status', 'hl-core') . '</label></th>';
        echo '<td><select id="status" name="status">';
        foreach (array('active', 'archived') as $s) {
            echo '<option value="' . esc_attr($s) . '"' . selected($current_status, $s, false) . '>' . esc_html(ucfirst($s)) . '</option>';
        }
        echo '</select></td></tr>';

        // Description
        echo '<tr><th scope="row"><label for="description">' . esc_html__('Description', 'hl-core') . '</label></th>';
        echo '<td><textarea id="description" name="description" rows="4" class="large-text">' . esc_textarea($is_edit ? ($group['description'] ?: '') : '') . '</textarea></td></tr>';

        echo '</table>';

        submit_button($is_edit ? __('Update Group', 'hl-core') : __('Create Group', 'hl-core'));
        echo '</form>';

        // If editing, show linked cohorts.
        if ($is_edit) {
            $this->render_linked_cohorts($group['group_id']);
        }
    }

    /**
     * Render the cohorts linked to this group.
     *
     * @param int $group_id
     */
    private function render_linked_cohorts($group_id) {
        global $wpdb;

        $cohorts = $wpdb->get_results($wpdb->prepare(
            "SELECT cohort_id, cohort_name, cohort_code, status, start_date
             FROM {$wpdb->prefix}hl_cohort
             WHERE cohort_group_id = %d
             ORDER BY cohort_name ASC",
            $group_id
        ), ARRAY_A);

        echo '<hr>';
        echo '<h2>' . esc_html__('Linked Cohorts', 'hl-core') . ' ';
        echo '<span class="count">(' . count($cohorts) . ')</span></h2>';
        echo '<p class="description">' . esc_html__('To add a cohort to this group, edit the cohort and select this group from the Group dropdown on the Details tab.', 'hl-core') . '</p>';

        if (empty($cohorts)) {
            echo '<p>' . esc_html__('No cohorts are linked to this group yet.', 'hl-core') . '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Cohort Name', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Code', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Status', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Start Date', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Actions', 'hl-core') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($cohorts as $c) {
            $status_colors = array(
                'active' => '#00a32a', 'draft' => '#996800',
                'paused' => '#b32d2e', 'archived' => '#8c8f94',
            );
            $sc = isset($status_colors[$c['status']]) ? $status_colors[$c['status']] : '#666';

            echo '<tr>';
            echo '<td><strong><a href="' . esc_url(admin_url('admin.php?page=hl-core&action=edit&id=' . $c['cohort_id'])) . '">' . esc_html($c['cohort_name']) . '</a></strong></td>';
            echo '<td><code>' . esc_html($c['cohort_code']) . '</code></td>';
            echo '<td><span style="color:' . esc_attr($sc) . '; font-weight:600;">' . esc_html(ucfirst($c['status'])) . '</span></td>';
            echo '<td>' . esc_html($c['start_date']) . '</td>';
            echo '<td><a href="' . esc_url(admin_url('admin.php?page=hl-core&action=edit&id=' . $c['cohort_id'])) . '" class="button button-small">' . esc_html__('Edit', 'hl-core') . '</a></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }
}
