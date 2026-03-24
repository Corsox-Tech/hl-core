<?php
if (!defined('ABSPATH')) exit;

/**
 * Admin Partnerships Page
 *
 * Manage partnerships (program-level grouping for cross-cycle reporting).
 * Full CRUD: list, create, edit, delete.
 *
 * @package HL_Core
 */
class HL_Admin_Partnerships {

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
        if (isset($_POST['hl_partnership_nonce'])) {
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

        echo '<div class="wrap hl-admin-wrap">';

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
        if (!wp_verify_nonce($_POST['hl_partnership_nonce'], 'hl_save_partnership')) {
            wp_die(__('Security check failed.', 'hl-core'));
        }
        if (!current_user_can('manage_hl_core')) {
            wp_die(__('You do not have permission.', 'hl-core'));
        }

        global $wpdb;

        $partnership_id   = isset($_POST['partnership_id']) ? absint($_POST['partnership_id']) : 0;
        $partnership_name = sanitize_text_field($_POST['partnership_name']);
        $partnership_code = sanitize_text_field($_POST['partnership_code']);
        $description = sanitize_textarea_field($_POST['description']);
        $status      = in_array($_POST['status'], array('active', 'archived'), true) ? $_POST['status'] : 'active';

        if (empty($partnership_name)) {
            wp_redirect(admin_url('admin.php?page=hl-partnerships&action=' . ($partnership_id ? 'edit&id=' . $partnership_id : 'new') . '&message=error'));
            exit;
        }

        if (empty($partnership_code)) {
            $partnership_code = HL_Normalization::generate_code($partnership_name);
        }

        $data = array(
            'partnership_name'  => $partnership_name,
            'partnership_code'  => $partnership_code,
            'description'  => $description,
            'status'       => $status,
        );

        if ($partnership_id > 0) {
            $wpdb->update(
                $wpdb->prefix . 'hl_partnership',
                $data,
                array('partnership_id' => $partnership_id)
            );
            $message = 'updated';
        } else {
            $data['partnership_uuid'] = HL_DB_Utils::generate_uuid();
            $wpdb->insert($wpdb->prefix . 'hl_partnership', $data);
            $partnership_id = $wpdb->insert_id;
            $message   = 'created';
        }

        if (class_exists('HL_Audit_Service')) {
            HL_Audit_Service::log(
                $message === 'created' ? 'partnership_created' : 'partnership_updated',
                get_current_user_id(),
                null, null, $partnership_id,
                sprintf('Partnership "%s" %s', $partnership_name, $message)
            );
        }

        wp_redirect(admin_url('admin.php?page=hl-partnerships&message=' . $message));
        exit;
    }

    private function handle_delete() {
        $partnership_id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        if (!$partnership_id) return;

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'hl_delete_partnership_' . $partnership_id)) {
            wp_die(__('Security check failed.', 'hl-core'));
        }
        if (!current_user_can('manage_hl_core')) {
            wp_die(__('You do not have permission.', 'hl-core'));
        }

        global $wpdb;

        // Unlink any cycles from this partnership.
        $wpdb->update(
            $wpdb->prefix . 'hl_cycle',
            array('partnership_id' => null),
            array('partnership_id' => $partnership_id)
        );

        $wpdb->delete($wpdb->prefix . 'hl_partnership', array('partnership_id' => $partnership_id));

        if (class_exists('HL_Audit_Service')) {
            HL_Audit_Service::log(
                'partnership_deleted',
                get_current_user_id(),
                null, null, $partnership_id,
                sprintf('Partnership #%d deleted', $partnership_id)
            );
        }

        wp_redirect(admin_url('admin.php?page=hl-partnerships&message=deleted'));
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
                'created' => array('success', __('Partnership created.', 'hl-core')),
                'updated' => array('success', __('Partnership updated.', 'hl-core')),
                'deleted' => array('success', __('Partnership deleted.', 'hl-core')),
                'error'   => array('error', __('An error occurred. Please check required fields.', 'hl-core')),
            );
            $m = sanitize_text_field($_GET['message']);
            if (isset($msgs[$m])) {
                echo '<div class="notice notice-' . esc_attr($msgs[$m][0]) . ' is-dismissible"><p>' . esc_html($msgs[$m][1]) . '</p></div>';
            }
        }

        echo '<h1 class="wp-heading-inline">' . esc_html__('Partnerships', 'hl-core') . '</h1>';
        echo ' <a href="' . esc_url(admin_url('admin.php?page=hl-partnerships&action=new')) . '" class="page-title-action">' . esc_html__('Add New', 'hl-core') . '</a>';
        echo '<hr class="wp-header-end">';
        echo '<p class="description">' . esc_html__('Partnerships allow you to aggregate multiple cycles under one program for cross-cycle reporting.', 'hl-core') . '</p>';

        $partnerships = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}hl_partnership ORDER BY partnership_name ASC",
            ARRAY_A
        );

        // Count cycles per partnership.
        $cycle_counts = array();
        $counts = $wpdb->get_results(
            "SELECT partnership_id, COUNT(*) AS cnt
             FROM {$wpdb->prefix}hl_cycle
             WHERE partnership_id IS NOT NULL
             GROUP BY partnership_id",
            ARRAY_A
        );
        foreach ($counts ?: array() as $row) {
            $cycle_counts[$row['partnership_id']] = (int) $row['cnt'];
        }

        if (empty($partnerships)) {
            echo '<p>' . esc_html__('No partnerships found. Create your first partnership to start aggregating cycles.', 'hl-core') . '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('ID', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Name', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Code', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Status', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Cycles', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Description', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Actions', 'hl-core') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($partnerships as $c) {
            $edit_url   = admin_url('admin.php?page=hl-partnerships&action=edit&id=' . $c['partnership_id']);
            $delete_url = wp_nonce_url(
                admin_url('admin.php?page=hl-partnerships&action=delete&id=' . $c['partnership_id']),
                'hl_delete_partnership_' . $c['partnership_id']
            );

            $status_color = $c['status'] === 'active' ? '#00a32a' : '#8c8f94';
            $num_cycles   = isset($cycle_counts[$c['partnership_id']]) ? $cycle_counts[$c['partnership_id']] : 0;

            echo '<tr>';
            echo '<td>' . esc_html($c['partnership_id']) . '</td>';
            echo '<td><strong><a href="' . esc_url($edit_url) . '">' . esc_html($c['partnership_name']) . '</a></strong></td>';
            echo '<td><code>' . esc_html($c['partnership_code']) . '</code></td>';
            echo '<td><span style="color:' . esc_attr($status_color) . '; font-weight:600;">' . esc_html(ucfirst($c['status'])) . '</span></td>';
            echo '<td>' . esc_html($num_cycles) . '</td>';
            echo '<td>' . esc_html(wp_trim_words($c['description'] ?: '', 12, '...')) . '</td>';
            echo '<td>';
            echo '<a href="' . esc_url($edit_url) . '" class="button button-small">' . esc_html__('Edit', 'hl-core') . '</a> ';
            echo '<a href="' . esc_url($delete_url) . '" class="button button-small button-link-delete" onclick="return confirm(\'' . esc_js(__('Delete this partnership? Cycles will be unlinked but not deleted.', 'hl-core')) . '\');">' . esc_html__('Delete', 'hl-core') . '</a>';
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

        $partnership_id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        $partnership    = null;
        $is_edit   = false;

        if ($partnership_id) {
            $partnership = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}hl_partnership WHERE partnership_id = %d",
                $partnership_id
            ), ARRAY_A);
            if ($partnership) {
                $is_edit = true;
            }
        }

        echo '<h1>' . ($is_edit ? esc_html__('Edit Partnership', 'hl-core') : esc_html__('Add New Partnership', 'hl-core')) . '</h1>';
        echo '<a href="' . esc_url(admin_url('admin.php?page=hl-partnerships')) . '">&larr; ' . esc_html__('Back to Partnerships', 'hl-core') . '</a>';

        if ($is_edit) {
            // Compact inline layout for edit mode.
            $current_status = $partnership['status'];
            $status_colors = array('active' => '#00a32a', 'archived' => '#8c8f94');
            $sc = isset($status_colors[$current_status]) ? $status_colors[$current_status] : '#666';
            ?>
            <style>
                .hl-partnership-header{display:flex;align-items:center;gap:16px;margin:12px 0 8px;flex-wrap:wrap}
                .hl-partnership-header .hl-ph-name{font-size:15px;font-weight:600;flex:1;min-width:200px}
                .hl-partnership-header .hl-ph-code{font-family:monospace;font-size:13px;background:#f0f0f1;padding:3px 8px;border-radius:4px;color:#50575e}
                .hl-partnership-header .hl-ph-status{display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:600;padding:3px 10px;border-radius:12px;background:#f6f7f7}
                .hl-partnership-header .hl-ph-status-dot{width:8px;height:8px;border-radius:50%}
                .hl-partnership-compact{display:grid;grid-template-columns:1fr 1fr auto;gap:12px;align-items:end;margin:0 0 6px}
                .hl-partnership-compact .hl-pc-field label{display:block;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#646970;margin-bottom:3px}
                .hl-partnership-compact .hl-pc-field input{width:100%;padding:5px 8px}
                .hl-partnership-compact .hl-pc-field select{padding:5px 8px}
                .hl-partnership-desc{margin:0 0 6px}
                .hl-partnership-desc label{display:block;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#646970;margin-bottom:3px}
                .hl-partnership-desc textarea{width:100%;padding:5px 8px;min-height:40px;resize:vertical}
                .hl-partnership-save-row{display:flex;align-items:center;gap:12px;margin:0 0 16px}
                .hl-partnership-save-row .button{margin:0!important}
                @media(max-width:782px){.hl-partnership-compact{grid-template-columns:1fr}}
            </style>
            <?php
            echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=hl-partnerships')) . '">';
            wp_nonce_field('hl_save_partnership', 'hl_partnership_nonce');
            echo '<input type="hidden" name="partnership_id" value="' . esc_attr($partnership['partnership_id']) . '" />';

            echo '<div class="hl-partnership-compact">';
            echo '<div class="hl-pc-field"><label for="partnership_name">' . esc_html__('Name', 'hl-core') . '</label>';
            echo '<input type="text" id="partnership_name" name="partnership_name" value="' . esc_attr($partnership['partnership_name']) . '" required /></div>';
            echo '<div class="hl-pc-field"><label for="partnership_code">' . esc_html__('Code', 'hl-core') . '</label>';
            echo '<input type="text" id="partnership_code" name="partnership_code" value="' . esc_attr($partnership['partnership_code']) . '" /></div>';
            echo '<div class="hl-pc-field"><label for="status">' . esc_html__('Status', 'hl-core') . '</label>';
            echo '<select id="status" name="status">';
            foreach (array('active', 'archived') as $s) {
                echo '<option value="' . esc_attr($s) . '"' . selected($current_status, $s, false) . '>' . esc_html(ucfirst($s)) . '</option>';
            }
            echo '</select></div>';
            echo '</div>';

            echo '<div class="hl-partnership-desc"><label for="description">' . esc_html__('Description', 'hl-core') . '</label>';
            echo '<textarea id="description" name="description" rows="2">' . esc_textarea($partnership['description'] ?: '') . '</textarea></div>';

            echo '<div class="hl-partnership-save-row">';
            submit_button(__('Update Partnership', 'hl-core'), 'primary', 'submit', false);
            echo '</div>';
            echo '</form>';

            $this->render_linked_cycles($partnership['partnership_id']);
        } else {
            // Add new — use standard form-table layout.
            echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=hl-partnerships')) . '">';
            wp_nonce_field('hl_save_partnership', 'hl_partnership_nonce');

            echo '<table class="form-table">';
            echo '<tr><th scope="row"><label for="partnership_name">' . esc_html__('Partnership Name', 'hl-core') . '</label></th>';
            echo '<td><input type="text" id="partnership_name" name="partnership_name" value="" class="regular-text" required /></td></tr>';
            echo '<tr><th scope="row"><label for="partnership_code">' . esc_html__('Partnership Code', 'hl-core') . '</label></th>';
            echo '<td><input type="text" id="partnership_code" name="partnership_code" value="" class="regular-text" />';
            echo '<p class="description">' . esc_html__('Leave blank to auto-generate from name.', 'hl-core') . '</p></td></tr>';
            echo '<tr><th scope="row"><label for="status">' . esc_html__('Status', 'hl-core') . '</label></th>';
            echo '<td><select id="status" name="status">';
            foreach (array('active', 'archived') as $s) {
                echo '<option value="' . esc_attr($s) . '">' . esc_html(ucfirst($s)) . '</option>';
            }
            echo '</select></td></tr>';
            echo '<tr><th scope="row"><label for="description">' . esc_html__('Description', 'hl-core') . '</label></th>';
            echo '<td><textarea id="description" name="description" rows="3" class="large-text"></textarea></td></tr>';
            echo '</table>';

            submit_button(__('Create Partnership', 'hl-core'));
            echo '</form>';
        }
    }

    /**
     * Render the cycles linked to this partnership.
     *
     * @param int $partnership_id
     */
    private function render_linked_cycles($partnership_id) {
        global $wpdb;

        $cycles = $wpdb->get_results($wpdb->prepare(
            "SELECT cycle_id, cycle_name, cycle_code, status, start_date
             FROM {$wpdb->prefix}hl_cycle
             WHERE partnership_id = %d
             ORDER BY cycle_name ASC",
            $partnership_id
        ), ARRAY_A);

        echo '<hr style="margin:12px 0 8px">';
        echo '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">';
        echo '<h2 style="margin:0">' . esc_html__('Linked Cycles', 'hl-core') . ' ';
        echo '<span class="count">(' . count($cycles) . ')</span></h2>';
        $add_cycle_url = admin_url('admin.php?page=hl-cycles&action=add&partnership_id=' . $partnership_id);
        echo '<a href="' . esc_url($add_cycle_url) . '" class="button button-primary">' . esc_html__('+ Add Cycle', 'hl-core') . '</a>';
        echo '</div>';

        if (empty($cycles)) {
            echo '<p>' . esc_html__('No cycles are linked to this partnership yet.', 'hl-core') . '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Cycle Name', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Code', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Status', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Start Date', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Actions', 'hl-core') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($cycles as $t) {
            $status_colors = array(
                'active' => '#00a32a', 'draft' => '#996800',
                'paused' => '#b32d2e', 'archived' => '#8c8f94',
            );
            $sc = isset($status_colors[$t['status']]) ? $status_colors[$t['status']] : '#666';

            echo '<tr>';
            echo '<td><strong><a href="' . esc_url(admin_url('admin.php?page=hl-cycles&action=edit&id=' . $t['cycle_id'])) . '">' . esc_html($t['cycle_name']) . '</a></strong></td>';
            echo '<td><code>' . esc_html($t['cycle_code']) . '</code></td>';
            echo '<td><span style="color:' . esc_attr($sc) . '; font-weight:600;">' . esc_html(ucfirst($t['status'])) . '</span></td>';
            echo '<td>' . esc_html($t['start_date']) . '</td>';
            echo '<td><a href="' . esc_url(admin_url('admin.php?page=hl-cycles&action=edit&id=' . $t['cycle_id'])) . '" class="button button-small">' . esc_html__('Edit', 'hl-core') . '</a></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }
}
