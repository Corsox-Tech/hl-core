<?php
if (!defined('ABSPATH')) exit;

/**
 * Admin Cohorts Page
 *
 * Manage cohorts (program-level grouping for cross-track reporting).
 * Full CRUD: list, create, edit, delete.
 *
 * @package HL_Core
 */
class HL_Admin_Cohorts {

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
        if (isset($_POST['hl_cohort_nonce'])) {
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
        if (!wp_verify_nonce($_POST['hl_cohort_nonce'], 'hl_save_cohort')) {
            wp_die(__('Security check failed.', 'hl-core'));
        }
        if (!current_user_can('manage_hl_core')) {
            wp_die(__('You do not have permission.', 'hl-core'));
        }

        global $wpdb;

        $cohort_id   = isset($_POST['cohort_id']) ? absint($_POST['cohort_id']) : 0;
        $cohort_name = sanitize_text_field($_POST['cohort_name']);
        $cohort_code = sanitize_text_field($_POST['cohort_code']);
        $description = sanitize_textarea_field($_POST['description']);
        $status      = in_array($_POST['status'], array('active', 'archived'), true) ? $_POST['status'] : 'active';

        if (empty($cohort_name)) {
            wp_redirect(admin_url('admin.php?page=hl-cohorts&action=' . ($cohort_id ? 'edit&id=' . $cohort_id : 'new') . '&message=error'));
            exit;
        }

        if (empty($cohort_code)) {
            $cohort_code = HL_Normalization::generate_code($cohort_name);
        }

        $data = array(
            'cohort_name'  => $cohort_name,
            'cohort_code'  => $cohort_code,
            'description'  => $description,
            'status'       => $status,
        );

        if ($cohort_id > 0) {
            $wpdb->update(
                $wpdb->prefix . 'hl_cohort',
                $data,
                array('cohort_id' => $cohort_id)
            );
            $message = 'updated';
        } else {
            $data['cohort_uuid'] = HL_DB_Utils::generate_uuid();
            $wpdb->insert($wpdb->prefix . 'hl_cohort', $data);
            $cohort_id = $wpdb->insert_id;
            $message   = 'created';
        }

        if (class_exists('HL_Audit_Service')) {
            HL_Audit_Service::log(
                $message === 'created' ? 'cohort_created' : 'cohort_updated',
                get_current_user_id(),
                null, null, $cohort_id,
                sprintf('Cohort "%s" %s', $cohort_name, $message)
            );
        }

        wp_redirect(admin_url('admin.php?page=hl-cohorts&message=' . $message));
        exit;
    }

    private function handle_delete() {
        $cohort_id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        if (!$cohort_id) return;

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'hl_delete_cohort_' . $cohort_id)) {
            wp_die(__('Security check failed.', 'hl-core'));
        }
        if (!current_user_can('manage_hl_core')) {
            wp_die(__('You do not have permission.', 'hl-core'));
        }

        global $wpdb;

        // Unlink any tracks from this cohort.
        $wpdb->update(
            $wpdb->prefix . 'hl_track',
            array('cohort_id' => null),
            array('cohort_id' => $cohort_id)
        );

        $wpdb->delete($wpdb->prefix . 'hl_cohort', array('cohort_id' => $cohort_id));

        if (class_exists('HL_Audit_Service')) {
            HL_Audit_Service::log(
                'cohort_deleted',
                get_current_user_id(),
                null, null, $cohort_id,
                sprintf('Cohort #%d deleted', $cohort_id)
            );
        }

        wp_redirect(admin_url('admin.php?page=hl-cohorts&message=deleted'));
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
                'created' => array('success', __('Cohort created.', 'hl-core')),
                'updated' => array('success', __('Cohort updated.', 'hl-core')),
                'deleted' => array('success', __('Cohort deleted.', 'hl-core')),
                'error'   => array('error', __('An error occurred. Please check required fields.', 'hl-core')),
            );
            $m = sanitize_text_field($_GET['message']);
            if (isset($msgs[$m])) {
                echo '<div class="notice notice-' . esc_attr($msgs[$m][0]) . ' is-dismissible"><p>' . esc_html($msgs[$m][1]) . '</p></div>';
            }
        }

        echo '<h1 class="wp-heading-inline">' . esc_html__('Cohorts', 'hl-core') . '</h1>';
        echo ' <a href="' . esc_url(admin_url('admin.php?page=hl-cohorts&action=new')) . '" class="page-title-action">' . esc_html__('Add New', 'hl-core') . '</a>';
        echo '<hr class="wp-header-end">';
        echo '<p class="description">' . esc_html__('Cohorts allow you to aggregate multiple tracks under one program for cross-track reporting.', 'hl-core') . '</p>';

        $cohorts = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}hl_cohort ORDER BY cohort_name ASC",
            ARRAY_A
        );

        // Count tracks per cohort.
        $track_counts = array();
        $counts = $wpdb->get_results(
            "SELECT cohort_id, COUNT(*) AS cnt
             FROM {$wpdb->prefix}hl_track
             WHERE cohort_id IS NOT NULL
             GROUP BY cohort_id",
            ARRAY_A
        );
        foreach ($counts ?: array() as $row) {
            $track_counts[$row['cohort_id']] = (int) $row['cnt'];
        }

        if (empty($cohorts)) {
            echo '<p>' . esc_html__('No cohorts found. Create your first cohort to start aggregating tracks.', 'hl-core') . '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('ID', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Name', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Code', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Status', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Tracks', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Description', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Actions', 'hl-core') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($cohorts as $c) {
            $edit_url   = admin_url('admin.php?page=hl-cohorts&action=edit&id=' . $c['cohort_id']);
            $delete_url = wp_nonce_url(
                admin_url('admin.php?page=hl-cohorts&action=delete&id=' . $c['cohort_id']),
                'hl_delete_cohort_' . $c['cohort_id']
            );

            $status_color = $c['status'] === 'active' ? '#00a32a' : '#8c8f94';
            $num_tracks   = isset($track_counts[$c['cohort_id']]) ? $track_counts[$c['cohort_id']] : 0;

            echo '<tr>';
            echo '<td>' . esc_html($c['cohort_id']) . '</td>';
            echo '<td><strong><a href="' . esc_url($edit_url) . '">' . esc_html($c['cohort_name']) . '</a></strong></td>';
            echo '<td><code>' . esc_html($c['cohort_code']) . '</code></td>';
            echo '<td><span style="color:' . esc_attr($status_color) . '; font-weight:600;">' . esc_html(ucfirst($c['status'])) . '</span></td>';
            echo '<td>' . esc_html($num_tracks) . '</td>';
            echo '<td>' . esc_html(wp_trim_words($c['description'] ?: '', 12, '...')) . '</td>';
            echo '<td>';
            echo '<a href="' . esc_url($edit_url) . '" class="button button-small">' . esc_html__('Edit', 'hl-core') . '</a> ';
            echo '<a href="' . esc_url($delete_url) . '" class="button button-small button-link-delete" onclick="return confirm(\'' . esc_js(__('Delete this cohort? Tracks will be unlinked but not deleted.', 'hl-core')) . '\');">' . esc_html__('Delete', 'hl-core') . '</a>';
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

        $cohort_id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        $cohort    = null;
        $is_edit   = false;

        if ($cohort_id) {
            $cohort = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}hl_cohort WHERE cohort_id = %d",
                $cohort_id
            ), ARRAY_A);
            if ($cohort) {
                $is_edit = true;
            }
        }

        echo '<h1>' . ($is_edit ? esc_html__('Edit Cohort', 'hl-core') : esc_html__('Add New Cohort', 'hl-core')) . '</h1>';
        echo '<a href="' . esc_url(admin_url('admin.php?page=hl-cohorts')) . '">&larr; ' . esc_html__('Back to Cohorts', 'hl-core') . '</a>';

        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=hl-cohorts')) . '">';
        wp_nonce_field('hl_save_cohort', 'hl_cohort_nonce');

        if ($is_edit) {
            echo '<input type="hidden" name="cohort_id" value="' . esc_attr($cohort['cohort_id']) . '" />';
        }

        echo '<table class="form-table">';

        // Name
        echo '<tr><th scope="row"><label for="cohort_name">' . esc_html__('Cohort Name', 'hl-core') . '</label></th>';
        echo '<td><input type="text" id="cohort_name" name="cohort_name" value="' . esc_attr($is_edit ? $cohort['cohort_name'] : '') . '" class="regular-text" required /></td></tr>';

        // Code
        echo '<tr><th scope="row"><label for="cohort_code">' . esc_html__('Cohort Code', 'hl-core') . '</label></th>';
        echo '<td><input type="text" id="cohort_code" name="cohort_code" value="' . esc_attr($is_edit ? $cohort['cohort_code'] : '') . '" class="regular-text" />';
        echo '<p class="description">' . esc_html__('Leave blank to auto-generate from name.', 'hl-core') . '</p></td></tr>';

        // Status
        $current_status = $is_edit ? $cohort['status'] : 'active';
        echo '<tr><th scope="row"><label for="status">' . esc_html__('Status', 'hl-core') . '</label></th>';
        echo '<td><select id="status" name="status">';
        foreach (array('active', 'archived') as $s) {
            echo '<option value="' . esc_attr($s) . '"' . selected($current_status, $s, false) . '>' . esc_html(ucfirst($s)) . '</option>';
        }
        echo '</select></td></tr>';

        // Description
        echo '<tr><th scope="row"><label for="description">' . esc_html__('Description', 'hl-core') . '</label></th>';
        echo '<td><textarea id="description" name="description" rows="4" class="large-text">' . esc_textarea($is_edit ? ($cohort['description'] ?: '') : '') . '</textarea></td></tr>';

        echo '</table>';

        submit_button($is_edit ? __('Update Cohort', 'hl-core') : __('Create Cohort', 'hl-core'));
        echo '</form>';

        // If editing, show linked tracks.
        if ($is_edit) {
            $this->render_linked_tracks($cohort['cohort_id']);
        }
    }

    /**
     * Render the tracks linked to this cohort.
     *
     * @param int $cohort_id
     */
    private function render_linked_tracks($cohort_id) {
        global $wpdb;

        $tracks = $wpdb->get_results($wpdb->prepare(
            "SELECT track_id, track_name, track_code, status, start_date
             FROM {$wpdb->prefix}hl_track
             WHERE cohort_id = %d
             ORDER BY track_name ASC",
            $cohort_id
        ), ARRAY_A);

        echo '<hr>';
        echo '<h2>' . esc_html__('Linked Tracks', 'hl-core') . ' ';
        echo '<span class="count">(' . count($tracks) . ')</span></h2>';
        echo '<p class="description">' . esc_html__('To add a track to this cohort, edit the track and select this cohort from the Cohort dropdown on the Details tab.', 'hl-core') . '</p>';

        if (empty($tracks)) {
            echo '<p>' . esc_html__('No tracks are linked to this cohort yet.', 'hl-core') . '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Track Name', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Code', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Status', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Start Date', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Actions', 'hl-core') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($tracks as $t) {
            $status_colors = array(
                'active' => '#00a32a', 'draft' => '#996800',
                'paused' => '#b32d2e', 'archived' => '#8c8f94',
            );
            $sc = isset($status_colors[$t['status']]) ? $status_colors[$t['status']] : '#666';

            echo '<tr>';
            echo '<td><strong><a href="' . esc_url(admin_url('admin.php?page=hl-tracks&action=edit&id=' . $t['track_id'])) . '">' . esc_html($t['track_name']) . '</a></strong></td>';
            echo '<td><code>' . esc_html($t['track_code']) . '</code></td>';
            echo '<td><span style="color:' . esc_attr($sc) . '; font-weight:600;">' . esc_html(ucfirst($t['status'])) . '</span></td>';
            echo '<td>' . esc_html($t['start_date']) . '</td>';
            echo '<td><a href="' . esc_url(admin_url('admin.php?page=hl-tracks&action=edit&id=' . $t['track_id'])) . '" class="button button-small">' . esc_html__('Edit', 'hl-core') . '</a></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }
}
