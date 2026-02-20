<?php if (!defined('ABSPATH')) exit;

/**
 * Admin OrgUnits Page
 *
 * Full CRUD admin page for managing Districts and Centers.
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

    /**
     * Render the orgunits list table
     */
    private function render_list() {
        $orgunits = $this->get_all_orgunits();

        // Build parent lookup map
        $parent_map = array();
        foreach ($orgunits as $ou) {
            $parent_map[$ou->orgunit_id] = $ou->name;
        }

        // Show success messages
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

        echo '<h1 class="wp-heading-inline">' . esc_html__('Org Units', 'hl-core') . '</h1>';
        echo ' <a href="' . esc_url(admin_url('admin.php?page=hl-orgunits&action=new')) . '" class="page-title-action">' . esc_html__('Add New', 'hl-core') . '</a>';
        echo '<hr class="wp-header-end">';

        if (empty($orgunits)) {
            echo '<p>' . esc_html__('No org units found. Create your first district or center.', 'hl-core') . '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>' . esc_html__('ID', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Name', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Code', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Type', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Parent', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Status', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Actions', 'hl-core') . '</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';

        foreach ($orgunits as $ou) {
            $edit_url   = admin_url('admin.php?page=hl-orgunits&action=edit&id=' . $ou->orgunit_id);
            $delete_url = wp_nonce_url(
                admin_url('admin.php?page=hl-orgunits&action=delete&id=' . $ou->orgunit_id),
                'hl_delete_orgunit_' . $ou->orgunit_id
            );

            // Type badge
            $type_style = ($ou->orgunit_type === 'district')
                ? 'background:#2271b1;color:#fff;padding:2px 8px;border-radius:3px;font-size:12px;'
                : 'background:#00a32a;color:#fff;padding:2px 8px;border-radius:3px;font-size:12px;';

            // Status color
            $status_style = '';
            switch ($ou->status) {
                case 'active':
                    $status_style = 'color:#00a32a;font-weight:600;';
                    break;
                case 'inactive':
                    $status_style = 'color:#b32d2e;font-weight:600;';
                    break;
                case 'archived':
                    $status_style = 'color:#8c8f94;font-weight:600;';
                    break;
            }

            $parent_name = '';
            if ($ou->parent_orgunit_id && isset($parent_map[$ou->parent_orgunit_id])) {
                $parent_name = $parent_map[$ou->parent_orgunit_id];
            }

            echo '<tr>';
            echo '<td>' . esc_html($ou->orgunit_id) . '</td>';
            echo '<td><strong><a href="' . esc_url($edit_url) . '">' . esc_html($ou->name) . '</a></strong></td>';
            echo '<td><code>' . esc_html($ou->orgunit_code) . '</code></td>';
            echo '<td><span style="' . esc_attr($type_style) . '">' . esc_html(ucfirst($ou->orgunit_type)) . '</span></td>';
            echo '<td>' . esc_html($parent_name) . '</td>';
            echo '<td><span style="' . esc_attr($status_style) . '">' . esc_html(ucfirst($ou->status)) . '</span></td>';
            echo '<td>';
            echo '<a href="' . esc_url($edit_url) . '" class="button button-small">' . esc_html__('Edit', 'hl-core') . '</a> ';
            echo '<a href="' . esc_url($delete_url) . '" class="button button-small button-link-delete" onclick="return confirm(\'' . esc_js(__('Are you sure you want to delete this org unit?', 'hl-core')) . '\');">' . esc_html__('Delete', 'hl-core') . '</a>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
    }

    /**
     * Render the create/edit form
     *
     * @param object|null $orgunit Orgunit row for edit, null for create.
     */
    private function render_form($orgunit = null) {
        $is_edit = ($orgunit !== null);
        $title   = $is_edit ? __('Edit Org Unit', 'hl-core') : __('Add New Org Unit', 'hl-core');

        // Get districts for parent dropdown
        global $wpdb;
        $districts = $wpdb->get_results(
            "SELECT orgunit_id, name FROM {$wpdb->prefix}hl_orgunit WHERE orgunit_type = 'district' ORDER BY name ASC"
        );

        echo '<h1>' . esc_html($title) . '</h1>';
        echo '<a href="' . esc_url(admin_url('admin.php?page=hl-orgunits')) . '">&larr; ' . esc_html__('Back to Org Units', 'hl-core') . '</a>';

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
        $current_type = $is_edit ? $orgunit->orgunit_type : 'district';
        echo '<tr>';
        echo '<th scope="row"><label for="orgunit_type">' . esc_html__('Type', 'hl-core') . '</label></th>';
        echo '<td><select id="orgunit_type" name="orgunit_type">';
        echo '<option value="district"' . selected($current_type, 'district', false) . '>' . esc_html__('District', 'hl-core') . '</option>';
        echo '<option value="center"' . selected($current_type, 'center', false) . '>' . esc_html__('Center', 'hl-core') . '</option>';
        echo '</select></td>';
        echo '</tr>';

        // Parent (for centers)
        $current_parent = $is_edit ? $orgunit->parent_orgunit_id : '';
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
        echo '<p class="description">' . esc_html__('Required for Centers. Select the parent District.', 'hl-core') . '</p></td>';
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
    }
}
