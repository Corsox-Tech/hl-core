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
        $is_edit        = false;

        if ($partnership_id) {
            $partnership = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}hl_partnership WHERE partnership_id = %d",
                $partnership_id
            ), ARRAY_A);
            if ($partnership) {
                $is_edit = true;
            }
        }

        $this->render_partnership_styles();

        if ($is_edit) {
            $this->render_edit_form($partnership);
        } else {
            $this->render_new_form();
        }
    }

    /**
     * Inline styles for the modern Partnership admin page.
     */
    private function render_partnership_styles() {
        static $done = false;
        if ($done) return;
        $done = true;
        ?>
        <style>
        /* Page wrapper — override WP admin defaults */
        .hlp-wrap{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif}
        .hlp-back{display:inline-flex;align-items:center;gap:4px;font-size:13px;color:#2271b1;text-decoration:none;margin-bottom:16px}
        .hlp-back:hover{color:#135e96}

        /* Title bar */
        .hlp-title-bar{display:flex;align-items:center;gap:12px;margin-bottom:20px;flex-wrap:wrap}
        .hlp-title{font-size:22px;font-weight:600;color:#1d2327;margin:0;line-height:1.3}
        .hlp-badge{display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:600;padding:4px 12px;border-radius:20px;line-height:1}
        .hlp-badge-active{background:#d1fae5;color:#065f46}
        .hlp-badge-archived{background:#f1f5f9;color:#64748b}
        .hlp-badge-draft{background:#fef3c7;color:#92400e}
        .hlp-badge-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0}
        .hlp-badge-active .hlp-badge-dot{background:#059669}
        .hlp-badge-archived .hlp-badge-dot{background:#94a3b8}
        .hlp-badge-draft .hlp-badge-dot{background:#d97706}

        /* Cards */
        .hlp-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px 24px;margin-bottom:20px}
        .hlp-card-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px}
        .hlp-card-title{font-size:14px;font-weight:600;color:#1e293b;text-transform:uppercase;letter-spacing:.5px;margin:0}

        /* Form grid */
        .hlp-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
        .hlp-grid-3{display:grid;grid-template-columns:1fr 1fr auto;gap:16px;align-items:end}
        .hlp-full{grid-column:1/-1}
        .hlp-field label{display:block;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px}
        .hlp-field input[type="text"],
        .hlp-field textarea,
        .hlp-field select{width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;color:#1e293b;background:#fff;font-family:inherit;transition:border-color .15s,box-shadow .15s;box-sizing:border-box}
        .hlp-field input:focus,
        .hlp-field textarea:focus,
        .hlp-field select:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.1)}
        .hlp-field textarea{resize:vertical;min-height:52px}
        .hlp-field select{appearance:auto}
        .hlp-field .description{font-size:12px;color:#94a3b8;margin-top:4px}
        .hlp-actions{display:flex;gap:10px;margin-top:4px;padding-top:16px;border-top:1px solid #f1f5f9}
        .hlp-actions .button{margin:0!important}

        /* Cycle cards */
        .hlp-cycle-list{display:flex;flex-direction:column;gap:10px}
        .hlp-cycle-row{display:flex;align-items:center;gap:16px;padding:14px 18px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;transition:border-color .15s,box-shadow .15s}
        .hlp-cycle-row:hover{border-color:#cbd5e1;box-shadow:0 1px 4px rgba(0,0,0,.04)}
        .hlp-cycle-name{font-size:14px;font-weight:600;color:#1e293b;flex:1;min-width:0}
        .hlp-cycle-name a{color:#1e293b;text-decoration:none}
        .hlp-cycle-name a:hover{color:#2563eb}
        .hlp-cycle-code{font-family:"SF Mono",Monaco,Consolas,monospace;font-size:12px;color:#64748b;background:#e2e8f0;padding:3px 8px;border-radius:6px;white-space:nowrap}
        .hlp-cycle-date{font-size:13px;color:#64748b;white-space:nowrap}
        .hlp-cycle-action .button{border-radius:6px}
        .hlp-empty{text-align:center;padding:32px 16px;color:#94a3b8;font-size:14px}
        .hlp-btn-add{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:#2563eb;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;transition:background .15s}
        .hlp-btn-add:hover,.hlp-btn-add:focus{background:#1d4ed8;color:#fff}

        @media(max-width:782px){
            .hlp-grid,.hlp-grid-3{grid-template-columns:1fr}
            .hlp-title-bar{flex-direction:column;align-items:flex-start}
            .hlp-cycle-row{flex-wrap:wrap}
        }
        </style>
        <?php
    }

    /**
     * Render the edit form (modern card layout).
     */
    private function render_edit_form($partnership) {
        $current_status = $partnership['status'];
        $badge_class    = 'hlp-badge-' . $current_status;
        ?>
        <div class="hlp-wrap">
            <a href="<?php echo esc_url(admin_url('admin.php?page=hl-partnerships')); ?>" class="hlp-back">&larr; <?php esc_html_e('Partnerships', 'hl-core'); ?></a>

            <div class="hlp-title-bar">
                <h1 class="hlp-title"><?php echo esc_html($partnership['partnership_name']); ?></h1>
                <span class="hlp-badge <?php echo esc_attr($badge_class); ?>">
                    <span class="hlp-badge-dot"></span>
                    <?php echo esc_html(ucfirst($current_status)); ?>
                </span>
            </div>

            <!-- Partnership Details Card -->
            <div class="hlp-card">
                <div class="hlp-card-header">
                    <h2 class="hlp-card-title"><?php esc_html_e('Details', 'hl-core'); ?></h2>
                </div>
                <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=hl-partnerships')); ?>">
                    <?php wp_nonce_field('hl_save_partnership', 'hl_partnership_nonce'); ?>
                    <input type="hidden" name="partnership_id" value="<?php echo esc_attr($partnership['partnership_id']); ?>" />

                    <div class="hlp-grid-3">
                        <div class="hlp-field">
                            <label for="partnership_name"><?php esc_html_e('Partnership Name', 'hl-core'); ?></label>
                            <input type="text" id="partnership_name" name="partnership_name" value="<?php echo esc_attr($partnership['partnership_name']); ?>" required />
                        </div>
                        <div class="hlp-field">
                            <label for="partnership_code"><?php esc_html_e('Code', 'hl-core'); ?></label>
                            <input type="text" id="partnership_code" name="partnership_code" value="<?php echo esc_attr($partnership['partnership_code']); ?>" />
                        </div>
                        <div class="hlp-field">
                            <label for="status"><?php esc_html_e('Status', 'hl-core'); ?></label>
                            <select id="status" name="status">
                                <?php foreach (array('active', 'archived') as $s) : ?>
                                    <option value="<?php echo esc_attr($s); ?>" <?php selected($current_status, $s); ?>><?php echo esc_html(ucfirst($s)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="hlp-field hlp-full" style="margin-top:12px">
                        <label for="description"><?php esc_html_e('Description', 'hl-core'); ?></label>
                        <textarea id="description" name="description" rows="2"><?php echo esc_textarea($partnership['description'] ?: ''); ?></textarea>
                    </div>

                    <div class="hlp-actions">
                        <?php submit_button(__('Save Changes', 'hl-core'), 'primary', 'submit', false); ?>
                    </div>
                </form>
            </div>

            <!-- Linked Cycles Card -->
            <?php $this->render_linked_cycles($partnership['partnership_id']); ?>
        </div>
        <?php
    }

    /**
     * Render the "Add New" form (same modern card style).
     */
    private function render_new_form() {
        ?>
        <div class="hlp-wrap">
            <a href="<?php echo esc_url(admin_url('admin.php?page=hl-partnerships')); ?>" class="hlp-back">&larr; <?php esc_html_e('Partnerships', 'hl-core'); ?></a>

            <div class="hlp-title-bar">
                <h1 class="hlp-title"><?php esc_html_e('New Partnership', 'hl-core'); ?></h1>
            </div>

            <div class="hlp-card">
                <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=hl-partnerships')); ?>">
                    <?php wp_nonce_field('hl_save_partnership', 'hl_partnership_nonce'); ?>

                    <div class="hlp-grid">
                        <div class="hlp-field">
                            <label for="partnership_name"><?php esc_html_e('Partnership Name', 'hl-core'); ?></label>
                            <input type="text" id="partnership_name" name="partnership_name" value="" required />
                        </div>
                        <div class="hlp-field">
                            <label for="partnership_code"><?php esc_html_e('Code', 'hl-core'); ?></label>
                            <input type="text" id="partnership_code" name="partnership_code" value="" />
                            <p class="description"><?php esc_html_e('Leave blank to auto-generate.', 'hl-core'); ?></p>
                        </div>
                    </div>

                    <div class="hlp-grid" style="margin-top:12px">
                        <div class="hlp-field">
                            <label for="status"><?php esc_html_e('Status', 'hl-core'); ?></label>
                            <select id="status" name="status">
                                <option value="active"><?php esc_html_e('Active', 'hl-core'); ?></option>
                                <option value="archived"><?php esc_html_e('Archived', 'hl-core'); ?></option>
                            </select>
                        </div>
                        <div class="hlp-field">
                            <label for="description"><?php esc_html_e('Description', 'hl-core'); ?></label>
                            <textarea id="description" name="description" rows="2"></textarea>
                        </div>
                    </div>

                    <div class="hlp-actions">
                        <?php submit_button(__('Create Partnership', 'hl-core'), 'primary', 'submit', false); ?>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * Render linked cycles as modern card rows.
     *
     * @param int $partnership_id
     */
    private function render_linked_cycles($partnership_id) {
        global $wpdb;

        $cycles = $wpdb->get_results($wpdb->prepare(
            "SELECT cycle_id, cycle_name, cycle_code, status, start_date
             FROM {$wpdb->prefix}hl_cycle
             WHERE partnership_id = %d
             ORDER BY start_date DESC, cycle_name ASC",
            $partnership_id
        ), ARRAY_A);

        $add_url = admin_url('admin.php?page=hl-cycles&action=new&partnership_id=' . $partnership_id);
        ?>
        <div class="hlp-card">
            <div class="hlp-card-header">
                <h2 class="hlp-card-title"><?php echo esc_html__('Cycles', 'hl-core') . ' (' . count($cycles) . ')'; ?></h2>
                <a href="<?php echo esc_url($add_url); ?>" class="hlp-btn-add">+ <?php esc_html_e('Add Cycle', 'hl-core'); ?></a>
            </div>

            <?php if (empty($cycles)) : ?>
                <div class="hlp-empty"><?php esc_html_e('No cycles yet. Click "Add Cycle" to create one.', 'hl-core'); ?></div>
            <?php else : ?>
                <div class="hlp-cycle-list">
                    <?php foreach ($cycles as $c) :
                        $badge_class = 'hlp-badge-' . $c['status'];
                        $edit_url = admin_url('admin.php?page=hl-cycles&action=edit&id=' . $c['cycle_id']);
                        $date_display = $c['start_date'] ? date_i18n('M j, Y', strtotime($c['start_date'])) : '—';
                    ?>
                        <div class="hlp-cycle-row">
                            <div class="hlp-cycle-name"><a href="<?php echo esc_url($edit_url); ?>"><?php echo esc_html($c['cycle_name']); ?></a></div>
                            <span class="hlp-cycle-code"><?php echo esc_html($c['cycle_code']); ?></span>
                            <span class="hlp-badge <?php echo esc_attr($badge_class); ?>">
                                <span class="hlp-badge-dot"></span>
                                <?php echo esc_html(ucfirst($c['status'])); ?>
                            </span>
                            <span class="hlp-cycle-date"><?php echo esc_html($date_display); ?></span>
                            <span class="hlp-cycle-action"><a href="<?php echo esc_url($edit_url); ?>" class="button button-small"><?php esc_html_e('Edit', 'hl-core'); ?></a></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
