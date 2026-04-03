<?php
if (!defined('ABSPATH')) exit;

/**
 * Admin Tours Management
 *
 * Handles all three Tours subtabs in the Settings hub:
 * Tours List, Tour Editor, and Tour Styles.
 *
 * @package HL_Core
 */
class HL_Admin_Tours {

    private static $instance = null;
    private $repo;
    private $notices = array();

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->repo = new HL_Tour_Repository();
    }

    /**
     * Add an admin notice to display after redirect.
     */
    private function add_notice($type, $message) {
        $this->notices[] = array('type' => $type, 'message' => $message);
    }

    /**
     * Display queued admin notices.
     */
    private function render_notices() {
        // Check for query-string notices (after redirect).
        if (isset($_GET['hl_notice'])) {
            $notice_map = array(
                'tour_saved'      => array('success', __('Tour saved.', 'hl-core')),
                'tour_created'    => array('success', __('Tour created.', 'hl-core')),
                'tour_archived'   => array('success', __('Tour archived.', 'hl-core')),
                'tour_duplicated' => array('success', __('Tour duplicated.', 'hl-core')),
                'styles_saved'    => array('success', __('Tour styles saved.', 'hl-core')),
                'styles_reset'    => array('success', __('Tour styles reset to defaults.', 'hl-core')),
                'activation_error' => array('error', __('Cannot activate a tour with no steps.', 'hl-core')),
            );
            $key = sanitize_text_field($_GET['hl_notice']);
            if (isset($notice_map[$key])) {
                $this->notices[] = array('type' => $notice_map[$key][0], 'message' => $notice_map[$key][1]);
            }
        }

        foreach ($this->notices as $notice) {
            printf(
                '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
                esc_attr($notice['type']),
                esc_html($notice['message'])
            );
        }
    }

    // =========================================================================
    // Save Handler
    // =========================================================================

    /**
     * Process POST form submissions. Called from handle_early_actions().
     */
    public function handle_save() {
        if (!current_user_can('manage_hl_core')) {
            return;
        }
        if (!wp_verify_nonce($_POST['hl_tour_admin_nonce'], 'hl_tour_admin')) {
            wp_die(__('Security check failed.', 'hl-core'));
        }

        $action = sanitize_text_field($_POST['hl_tour_action'] ?? '');

        switch ($action) {
            case 'save_tour':
                $this->handle_save_tour();
                break;

            case 'archive_tour':
                $tour_id = absint($_POST['tour_id'] ?? 0);
                if ($tour_id) {
                    $this->repo->update_tour($tour_id, array('status' => 'archived'));
                }
                wp_safe_redirect(add_query_arg(array(
                    'page'      => 'hl-settings',
                    'tab'       => 'tours',
                    'hl_notice' => 'tour_archived',
                ), admin_url('admin.php')));
                exit;

            case 'duplicate_tour':
                $tour_id = absint($_POST['tour_id'] ?? 0);
                if ($tour_id) {
                    $this->repo->duplicate_tour($tour_id);
                }
                wp_safe_redirect(add_query_arg(array(
                    'page'      => 'hl-settings',
                    'tab'       => 'tours',
                    'hl_notice' => 'tour_duplicated',
                ), admin_url('admin.php')));
                exit;

            case 'save_styles':
                HL_Tour_Service::instance()->save_global_styles($_POST);
                wp_safe_redirect(add_query_arg(array(
                    'page'      => 'hl-settings',
                    'tab'       => 'tours',
                    'subtab'    => 'styles',
                    'hl_notice' => 'styles_saved',
                ), admin_url('admin.php')));
                exit;

            case 'reset_styles':
                delete_option('hl_tour_styles');
                wp_safe_redirect(add_query_arg(array(
                    'page'      => 'hl-settings',
                    'tab'       => 'tours',
                    'subtab'    => 'styles',
                    'hl_notice' => 'styles_reset',
                ), admin_url('admin.php')));
                exit;
        }
    }

    /**
     * Handle create/update tour + steps.
     */
    private function handle_save_tour() {
        $tour_id = absint($_POST['tour_id'] ?? 0);

        $tour_data = array(
            'title'            => sanitize_text_field($_POST['tour_title'] ?? ''),
            'slug'             => sanitize_title($_POST['tour_slug'] ?? ''),
            'trigger_type'     => sanitize_text_field($_POST['tour_trigger_type'] ?? 'manual_only'),
            'trigger_page_url' => esc_url_raw($_POST['tour_trigger_page_url'] ?? ''),
            'start_page_url'   => esc_url_raw($_POST['tour_start_page_url'] ?? ''),
            'status'           => sanitize_text_field($_POST['tour_status'] ?? 'draft'),
            'hide_on_mobile'   => isset($_POST['tour_hide_on_mobile']) ? 1 : 0,
            'sort_order'       => absint($_POST['tour_sort_order'] ?? 0),
        );

        // Target roles — empty array means all roles (store as NULL).
        $target_roles = isset($_POST['tour_target_roles']) && is_array($_POST['tour_target_roles'])
            ? array_map('sanitize_text_field', $_POST['tour_target_roles'])
            : array();
        $tour_data['target_roles'] = !empty($target_roles) ? $target_roles : null;

        // Validate activation.
        if ($tour_data['status'] === 'active') {
            // For new tours, we can't validate step count yet — check after steps are saved.
            // For existing tours, validate now.
            if ($tour_id) {
                $validation = HL_Tour_Service::instance()->validate_tour_can_activate($tour_id);
                if (is_wp_error($validation)) {
                    // Check if steps are being submitted — if so, we can still validate after save.
                    $has_submitted_steps = !empty($_POST['step_title']) && is_array($_POST['step_title']);
                    $submitted_step_count = $has_submitted_steps ? count(array_filter($_POST['step_title'])) : 0;
                    if ($submitted_step_count === 0) {
                        $tour_data['status'] = 'draft';
                        wp_safe_redirect(add_query_arg(array(
                            'page'      => 'hl-settings',
                            'tab'       => 'tours',
                            'subtab'    => 'editor',
                            'tour_id'   => $tour_id,
                            'hl_notice' => 'activation_error',
                        ), admin_url('admin.php')));
                        exit;
                    }
                }
            }
        }

        // Create or update tour.
        if ($tour_id) {
            $this->repo->update_tour($tour_id, $tour_data);
        } else {
            $tour_id = $this->repo->create_tour($tour_data);
        }

        // Save steps.
        $step_ids          = isset($_POST['step_id']) && is_array($_POST['step_id']) ? $_POST['step_id'] : array();
        $step_titles       = isset($_POST['step_title']) && is_array($_POST['step_title']) ? $_POST['step_title'] : array();
        $step_descriptions = isset($_POST['step_description']) && is_array($_POST['step_description']) ? $_POST['step_description'] : array();
        $step_page_urls    = isset($_POST['step_page_url']) && is_array($_POST['step_page_url']) ? $_POST['step_page_url'] : array();
        $step_selectors    = isset($_POST['step_target_selector']) && is_array($_POST['step_target_selector']) ? $_POST['step_target_selector'] : array();
        $step_positions    = isset($_POST['step_position']) && is_array($_POST['step_position']) ? $_POST['step_position'] : array();
        $step_types        = isset($_POST['step_type']) && is_array($_POST['step_type']) ? $_POST['step_type'] : array();

        // Track which existing step IDs are still in the form.
        $existing_step_ids = array();
        $existing_steps    = $this->repo->get_steps($tour_id);
        foreach ($existing_steps as $es) {
            $existing_step_ids[] = (int) $es['step_id'];
        }

        $submitted_ids = array();
        foreach ($step_ids as $i => $sid) {
            $sid = absint($sid);
            $title = sanitize_text_field($step_titles[$i] ?? '');
            if (empty($title)) {
                continue; // Skip blank steps.
            }

            $step_data = array(
                'tour_id'         => $tour_id,
                'step_order'      => $i,
                'title'           => $title,
                'description'     => wp_kses_post($step_descriptions[$i] ?? ''),
                'page_url'        => esc_url_raw($step_page_urls[$i] ?? ''),
                'target_selector' => sanitize_text_field($step_selectors[$i] ?? ''),
                'position'        => sanitize_text_field($step_positions[$i] ?? 'auto'),
                'step_type'       => sanitize_text_field($step_types[$i] ?? 'informational'),
            );

            if ($sid && in_array($sid, $existing_step_ids, true)) {
                $this->repo->update_step($sid, $step_data);
                $submitted_ids[] = $sid;
            } else {
                $new_id = $this->repo->create_step($step_data);
                $submitted_ids[] = $new_id;
            }
        }

        // Delete removed steps.
        foreach ($existing_step_ids as $old_id) {
            if (!in_array($old_id, $submitted_ids, true)) {
                $this->repo->delete_step($old_id);
            }
        }

        // Post-save activation validation for new tours.
        if ($tour_data['status'] === 'active') {
            $validation = HL_Tour_Service::instance()->validate_tour_can_activate($tour_id);
            if (is_wp_error($validation)) {
                $this->repo->update_tour($tour_id, array('status' => 'draft'));
                wp_safe_redirect(add_query_arg(array(
                    'page'      => 'hl-settings',
                    'tab'       => 'tours',
                    'subtab'    => 'editor',
                    'tour_id'   => $tour_id,
                    'hl_notice' => 'activation_error',
                ), admin_url('admin.php')));
                exit;
            }
        }

        $notice = $tour_id ? 'tour_saved' : 'tour_created';
        wp_safe_redirect(add_query_arg(array(
            'page'      => 'hl-settings',
            'tab'       => 'tours',
            'subtab'    => 'editor',
            'tour_id'   => $tour_id,
            'hl_notice' => $notice,
        ), admin_url('admin.php')));
        exit;
    }

    // =========================================================================
    // Render — Main Dispatch
    // =========================================================================

    /**
     * Render the active subtab. Called from HL_Admin_Settings::render_page().
     */
    public function render_page_content() {
        $subtab = sanitize_text_field($_GET['subtab'] ?? 'list');

        $this->render_notices();
        $this->render_subtabs($subtab);

        switch ($subtab) {
            case 'editor':
                $this->render_editor();
                break;
            case 'styles':
                $this->render_styles();
                break;
            default:
                $this->render_list();
                break;
        }
    }

    /**
     * Render subtab navigation.
     */
    private function render_subtabs($active) {
        $subtabs = array(
            'list'   => __('Tours List', 'hl-core'),
            'editor' => __('Tour Editor', 'hl-core'),
            'styles' => __('Tour Styles', 'hl-core'),
        );
        $base_url = admin_url('admin.php?page=hl-settings&tab=tours');

        echo '<ul class="subsubsub" style="float:none;margin-bottom:16px;">';
        $items = array();
        foreach ($subtabs as $slug => $label) {
            $url   = add_query_arg('subtab', $slug, $base_url);
            $class = ($slug === $active) ? 'current' : '';
            $items[] = sprintf('<li><a href="%s" class="%s">%s</a></li>', esc_url($url), esc_attr($class), esc_html($label));
        }
        echo implode(' | ', $items);
        echo '</ul><div class="clear"></div>';
    }

    // =========================================================================
    // Render — Tours List
    // =========================================================================

    private function render_list() {
        $status_filter = sanitize_text_field($_GET['status'] ?? '');
        $filters = array();
        if ($status_filter) {
            $filters['status'] = $status_filter;
        }

        $tours = $this->repo->get_all_tours($filters);

        // Count by status for filter pills.
        $all_tours    = $this->repo->get_all_tours();
        $count_all    = count($all_tours);
        $count_active = 0;
        $count_draft  = 0;
        $count_archived = 0;
        foreach ($all_tours as $t) {
            switch ($t['status']) {
                case 'active':   $count_active++; break;
                case 'draft':    $count_draft++; break;
                case 'archived': $count_archived++; break;
            }
        }

        $base_url   = admin_url('admin.php?page=hl-settings&tab=tours');
        $editor_url = add_query_arg('subtab', 'editor', $base_url);
        ?>

        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
            <ul class="subsubsub" style="float:none;margin:0;">
                <li><a href="<?php echo esc_url($base_url); ?>" class="<?php echo $status_filter === '' ? 'current' : ''; ?>"><?php esc_html_e('All', 'hl-core'); ?> <span class="count">(<?php echo $count_all; ?>)</span></a> | </li>
                <li><a href="<?php echo esc_url(add_query_arg('status', 'active', $base_url)); ?>" class="<?php echo $status_filter === 'active' ? 'current' : ''; ?>"><?php esc_html_e('Active', 'hl-core'); ?> <span class="count">(<?php echo $count_active; ?>)</span></a> | </li>
                <li><a href="<?php echo esc_url(add_query_arg('status', 'draft', $base_url)); ?>" class="<?php echo $status_filter === 'draft' ? 'current' : ''; ?>"><?php esc_html_e('Draft', 'hl-core'); ?> <span class="count">(<?php echo $count_draft; ?>)</span></a> | </li>
                <li><a href="<?php echo esc_url(add_query_arg('status', 'archived', $base_url)); ?>" class="<?php echo $status_filter === 'archived' ? 'current' : ''; ?>"><?php esc_html_e('Archived', 'hl-core'); ?> <span class="count">(<?php echo $count_archived; ?>)</span></a></li>
            </ul>
            <a href="<?php echo esc_url($editor_url); ?>" class="button button-primary"><?php esc_html_e('Add Tour', 'hl-core'); ?></a>
        </div>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width:30%;"><?php esc_html_e('Title', 'hl-core'); ?></th>
                    <th><?php esc_html_e('Trigger Type', 'hl-core'); ?></th>
                    <th><?php esc_html_e('Target Roles', 'hl-core'); ?></th>
                    <th><?php esc_html_e('Status', 'hl-core'); ?></th>
                    <th><?php esc_html_e('Steps', 'hl-core'); ?></th>
                    <th><?php esc_html_e('Sort Order', 'hl-core'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($tours)) : ?>
                    <tr><td colspan="6"><?php esc_html_e('No tours found.', 'hl-core'); ?></td></tr>
                <?php else : ?>
                    <?php foreach ($tours as $tour) :
                        $edit_url = add_query_arg(array('subtab' => 'editor', 'tour_id' => $tour['tour_id']), $base_url);
                        $roles    = $tour['target_roles'] ? HL_DB_Utils::json_decode($tour['target_roles']) : null;
                        $roles_display = is_array($roles) && !empty($roles) ? implode(', ', $roles) : __('All', 'hl-core');

                        $trigger_labels = array(
                            'first_login' => __('First Login', 'hl-core'),
                            'page_visit'  => __('Page Visit', 'hl-core'),
                            'manual_only' => __('Manual Only', 'hl-core'),
                        );
                        $trigger_label = $trigger_labels[$tour['trigger_type']] ?? $tour['trigger_type'];

                        $status_classes = array(
                            'active'   => 'hl-badge hl-badge--active',
                            'draft'    => 'hl-badge hl-badge--draft',
                            'archived' => 'hl-badge hl-badge--archived',
                        );
                        $badge_class = $status_classes[$tour['status']] ?? 'hl-badge';
                    ?>
                        <tr>
                            <td>
                                <strong><a href="<?php echo esc_url($edit_url); ?>"><?php echo esc_html($tour['title']); ?></a></strong>
                                <div class="row-actions">
                                    <span class="edit"><a href="<?php echo esc_url($edit_url); ?>"><?php esc_html_e('Edit', 'hl-core'); ?></a> | </span>
                                    <span class="duplicate">
                                        <form method="post" style="display:inline;">
                                            <?php wp_nonce_field('hl_tour_admin', 'hl_tour_admin_nonce'); ?>
                                            <input type="hidden" name="hl_tour_action" value="duplicate_tour">
                                            <input type="hidden" name="tour_id" value="<?php echo absint($tour['tour_id']); ?>">
                                            <button type="submit" class="button-link"><?php esc_html_e('Duplicate', 'hl-core'); ?></button>
                                        </form>
                                         | </span>
                                    <?php if ($tour['status'] !== 'archived') : ?>
                                    <span class="archive">
                                        <form method="post" style="display:inline;">
                                            <?php wp_nonce_field('hl_tour_admin', 'hl_tour_admin_nonce'); ?>
                                            <input type="hidden" name="hl_tour_action" value="archive_tour">
                                            <input type="hidden" name="tour_id" value="<?php echo absint($tour['tour_id']); ?>">
                                            <button type="submit" class="button-link" onclick="return confirm('<?php esc_attr_e('Archive this tour?', 'hl-core'); ?>');"><?php esc_html_e('Archive', 'hl-core'); ?></button>
                                        </form>
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td><span class="hl-badge hl-badge--info"><?php echo esc_html($trigger_label); ?></span></td>
                            <td><?php echo esc_html($roles_display); ?></td>
                            <td><span class="<?php echo esc_attr($badge_class); ?>"><?php echo esc_html(ucfirst($tour['status'])); ?></span></td>
                            <td><?php echo absint($tour['step_count']); ?></td>
                            <td><?php echo absint($tour['sort_order']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    // =========================================================================
    // Render — Tour Editor (placeholder — filled in Task 2A.2)
    // =========================================================================

    private function render_editor() {
        $tour_id = absint($_GET['tour_id'] ?? 0);
        $tour    = $tour_id ? $this->repo->get_tour($tour_id) : null;
        $steps   = $tour_id ? $this->repo->get_steps($tour_id) : array();
        $is_new  = !$tour;

        $title           = $tour['title'] ?? '';
        $slug            = $tour['slug'] ?? '';
        $status          = $tour['status'] ?? 'draft';
        $trigger_type    = $tour['trigger_type'] ?? 'manual_only';
        $trigger_page_url = $tour['trigger_page_url'] ?? '';
        $start_page_url  = $tour['start_page_url'] ?? '';
        $hide_on_mobile  = (int) ($tour['hide_on_mobile'] ?? 0);
        $sort_order      = (int) ($tour['sort_order'] ?? 0);
        $target_roles    = $tour && $tour['target_roles'] ? HL_DB_Utils::json_decode($tour['target_roles']) : array();
        if (!is_array($target_roles)) {
            $target_roles = array();
        }

        $all_roles = array(
            'teacher'         => __('Teacher', 'hl-core'),
            'mentor'          => __('Mentor', 'hl-core'),
            'school_leader'   => __('School Leader', 'hl-core'),
            'district_leader' => __('District Leader', 'hl-core'),
            'coach'           => __('Coach', 'hl-core'),
        );
        ?>
        <h2><?php echo $is_new ? esc_html__('Create Tour', 'hl-core') : esc_html__('Edit Tour', 'hl-core'); ?></h2>

        <form method="post" id="hl-tour-editor-form">
            <?php wp_nonce_field('hl_tour_admin', 'hl_tour_admin_nonce'); ?>
            <input type="hidden" name="hl_tour_action" value="save_tour">
            <input type="hidden" name="tour_id" value="<?php echo absint($tour_id); ?>">

            <!-- Tour Settings -->
            <div class="hl-settings-card" style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:24px;margin-bottom:24px;">
                <h3 style="margin-top:0;font-size:16px;color:#1e3a5f;">
                    <span class="dashicons dashicons-admin-tools" style="margin-right:6px;color:#4a90d9;"></span>
                    <?php esc_html_e('Tour Settings', 'hl-core'); ?>
                </h3>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="tour_title"><?php esc_html_e('Title', 'hl-core'); ?></label></th>
                        <td><input type="text" id="tour_title" name="tour_title" value="<?php echo esc_attr($title); ?>" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tour_slug"><?php esc_html_e('Slug', 'hl-core'); ?></label></th>
                        <td>
                            <input type="text" id="tour_slug" name="tour_slug" value="<?php echo esc_attr($slug); ?>" class="regular-text" pattern="[a-z0-9\-]+" title="<?php esc_attr_e('Lowercase letters, numbers, and hyphens only', 'hl-core'); ?>">
                            <p class="description"><?php esc_html_e('Auto-generated from title. URL-safe identifier.', 'hl-core'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tour_status"><?php esc_html_e('Status', 'hl-core'); ?></label></th>
                        <td>
                            <select id="tour_status" name="tour_status">
                                <option value="draft" <?php selected($status, 'draft'); ?>><?php esc_html_e('Draft', 'hl-core'); ?></option>
                                <option value="active" <?php selected($status, 'active'); ?>><?php esc_html_e('Active', 'hl-core'); ?></option>
                                <option value="archived" <?php selected($status, 'archived'); ?>><?php esc_html_e('Archived', 'hl-core'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tour_trigger_type"><?php esc_html_e('Trigger Type', 'hl-core'); ?></label></th>
                        <td>
                            <select id="tour_trigger_type" name="tour_trigger_type">
                                <option value="first_login" <?php selected($trigger_type, 'first_login'); ?>><?php esc_html_e('First Login', 'hl-core'); ?></option>
                                <option value="page_visit" <?php selected($trigger_type, 'page_visit'); ?>><?php esc_html_e('Page Visit', 'hl-core'); ?></option>
                                <option value="manual_only" <?php selected($trigger_type, 'manual_only'); ?>><?php esc_html_e('Manual Only', 'hl-core'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr class="hl-tour-trigger-page-url-row" <?php echo $trigger_type !== 'page_visit' ? 'style="display:none;"' : ''; ?>>
                        <th scope="row"><label for="tour_trigger_page_url"><?php esc_html_e('Trigger Page URL', 'hl-core'); ?></label></th>
                        <td>
                            <input type="text" id="tour_trigger_page_url" name="tour_trigger_page_url" value="<?php echo esc_attr($trigger_page_url); ?>" class="regular-text" placeholder="/my-program/">
                            <p class="description"><?php esc_html_e('Page path where this tour auto-triggers (for page_visit type).', 'hl-core'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Target Roles', 'hl-core'); ?></th>
                        <td>
                            <?php foreach ($all_roles as $role_key => $role_label) : ?>
                                <label style="display:inline-block;margin-right:16px;margin-bottom:8px;">
                                    <input type="checkbox" name="tour_target_roles[]" value="<?php echo esc_attr($role_key); ?>" <?php checked(in_array($role_key, $target_roles, true)); ?>>
                                    <?php echo esc_html($role_label); ?>
                                </label>
                            <?php endforeach; ?>
                            <p class="description"><?php esc_html_e('Leave all unchecked to target all roles.', 'hl-core'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tour_start_page_url"><?php esc_html_e('Start Page URL', 'hl-core'); ?></label></th>
                        <td>
                            <input type="text" id="tour_start_page_url" name="tour_start_page_url" value="<?php echo esc_attr($start_page_url); ?>" class="regular-text" required placeholder="/dashboard/">
                            <p class="description"><?php esc_html_e('Page where the tour begins and users are redirected on exit.', 'hl-core'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tour_sort_order"><?php esc_html_e('Sort Order', 'hl-core'); ?></label></th>
                        <td><input type="number" id="tour_sort_order" name="tour_sort_order" value="<?php echo absint($sort_order); ?>" class="small-text" min="0"></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Options', 'hl-core'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="tour_hide_on_mobile" value="1" <?php checked($hide_on_mobile, 1); ?>>
                                <?php esc_html_e('Hide on mobile (viewports below 640px)', 'hl-core'); ?>
                            </label>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Steps Section -->
            <div class="hl-settings-card" style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:24px;margin-bottom:24px;">
                <h3 style="margin-top:0;font-size:16px;color:#1e3a5f;">
                    <span class="dashicons dashicons-editor-ol" style="margin-right:6px;color:#4a90d9;"></span>
                    <?php esc_html_e('Steps', 'hl-core'); ?>
                </h3>

                <div id="hl-tour-steps" class="hl-tour-steps-sortable">
                    <?php if (!empty($steps)) : ?>
                        <?php foreach ($steps as $i => $step) : ?>
                            <?php $this->render_step_card($step, $i); ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <p style="margin-top:16px;">
                    <button type="button" id="hl-tour-add-step" class="button">
                        <span class="dashicons dashicons-plus-alt2" style="vertical-align:middle;margin-right:4px;"></span>
                        <?php esc_html_e('Add Step', 'hl-core'); ?>
                    </button>
                </p>
            </div>

            <?php submit_button($is_new ? __('Create Tour', 'hl-core') : __('Save Tour', 'hl-core')); ?>
        </form>

        <!-- Step template for JS cloning (hidden) -->
        <div id="hl-tour-step-template" class="hl-tour-step-card" style="display:none;">
            <?php $this->render_step_card(null, '__INDEX__'); ?>
        </div>
        <?php
    }

    /**
     * Render a single step card (used in editor and as JS template).
     *
     * @param array|null $step Step data or null for template.
     * @param int|string $index Step index or placeholder.
     */
    private function render_step_card($step, $index) {
        $step_id   = $step['step_id'] ?? 0;
        $title     = $step['title'] ?? '';
        $desc      = $step['description'] ?? '';
        $page_url  = $step['page_url'] ?? '';
        $selector  = $step['target_selector'] ?? '';
        $position  = $step['position'] ?? 'auto';
        $step_type = $step['step_type'] ?? 'informational';
        $num       = is_numeric($index) ? $index + 1 : '';

        $positions = array('top', 'bottom', 'left', 'right', 'auto');
        ?>
        <div class="hl-tour-step-card" data-step-id="<?php echo absint($step_id); ?>">
            <div class="hl-tour-step-header">
                <span class="hl-tour-step-handle dashicons dashicons-menu"></span>
                <span class="hl-tour-step-number"><?php echo esc_html($num); ?></span>
                <span class="hl-tour-step-preview"><?php echo esc_html($title ?: __('New Step', 'hl-core')); ?></span>
                <button type="button" class="hl-tour-remove-step" title="<?php esc_attr_e('Remove Step', 'hl-core'); ?>">
                    <span class="dashicons dashicons-trash"></span>
                </button>
            </div>
            <div class="hl-tour-step-body">
                <input type="hidden" name="step_id[]" value="<?php echo absint($step_id); ?>">

                <p>
                    <label><strong><?php esc_html_e('Title', 'hl-core'); ?></strong></label><br>
                    <input type="text" name="step_title[]" value="<?php echo esc_attr($title); ?>" class="regular-text" placeholder="<?php esc_attr_e('Step title', 'hl-core'); ?>">
                </p>

                <p>
                    <label><strong><?php esc_html_e('Description', 'hl-core'); ?></strong></label><br>
                    <textarea name="step_description[]" rows="3" class="large-text" placeholder="<?php esc_attr_e('Step description (HTML allowed)', 'hl-core'); ?>"><?php echo esc_textarea($desc); ?></textarea>
                </p>

                <p>
                    <label><strong><?php esc_html_e('Page URL', 'hl-core'); ?></strong></label><br>
                    <input type="text" name="step_page_url[]" value="<?php echo esc_attr($page_url); ?>" class="regular-text" placeholder="<?php esc_attr_e('Leave blank = same page as previous step', 'hl-core'); ?>">
                </p>

                <p>
                    <label><strong><?php esc_html_e('Target Element', 'hl-core'); ?></strong></label><br>
                    <span class="hl-tour-selector-display">
                        <input type="text" name="step_target_selector[]" value="<?php echo esc_attr($selector); ?>" class="regular-text hl-tour-selector-manual" placeholder="<?php esc_attr_e('CSS selector (e.g., .hl-progress-summary)', 'hl-core'); ?>">
                    </span>
                    <button type="button" class="button button-small hl-tour-pick-element" style="margin-left:4px;">
                        <span class="dashicons dashicons-admin-customizer" style="vertical-align:middle;font-size:14px;"></span>
                        <?php esc_html_e('Pick Element', 'hl-core'); ?>
                    </button>
                    <p class="description"><?php esc_html_e('Leave blank for a centered modal with no element highlight.', 'hl-core'); ?></p>
                </p>

                <p>
                    <label><strong><?php esc_html_e('Position', 'hl-core'); ?></strong></label><br>
                    <span class="hl-tour-position-pills">
                        <?php foreach ($positions as $pos) : ?>
                            <span class="hl-tour-position-pill<?php echo $pos === $position ? ' active' : ''; ?>" data-value="<?php echo esc_attr($pos); ?>">
                                <?php echo esc_html(ucfirst($pos)); ?>
                            </span>
                        <?php endforeach; ?>
                        <input type="hidden" name="step_position[]" value="<?php echo esc_attr($position); ?>">
                    </span>
                </p>

                <p>
                    <label><strong><?php esc_html_e('Step Type', 'hl-core'); ?></strong></label><br>
                    <span class="hl-tour-type-toggle">
                        <span class="<?php echo $step_type === 'informational' ? 'active' : ''; ?>" data-value="informational"><?php esc_html_e('Informational', 'hl-core'); ?></span>
                        <span class="<?php echo $step_type === 'interactive' ? 'active' : ''; ?>" data-value="interactive"><?php esc_html_e('Interactive', 'hl-core'); ?></span>
                        <input type="hidden" name="step_type[]" value="<?php echo esc_attr($step_type); ?>">
                    </span>
                    <span class="description" style="display:block;margin-top:4px;">
                        <?php esc_html_e('Informational = Next button. Interactive = user clicks the highlighted element to advance.', 'hl-core'); ?>
                    </span>
                </p>
            </div>
        </div>
        <?php
    }

    // =========================================================================
    // Render — Tour Styles
    // =========================================================================

    private function render_styles() {
        $styles = HL_Tour_Service::instance()->get_global_styles();
        ?>
        <form method="post">
            <?php wp_nonce_field('hl_tour_admin', 'hl_tour_admin_nonce'); ?>
            <input type="hidden" name="hl_tour_action" value="save_styles">

            <div class="hl-settings-card" style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:24px;margin-bottom:24px;">
                <h3 style="margin-top:0;font-size:16px;color:#1e3a5f;">
                    <span class="dashicons dashicons-art" style="margin-right:6px;color:#4a90d9;"></span>
                    <?php esc_html_e('Tour Appearance', 'hl-core'); ?>
                </h3>
                <p class="description"><?php esc_html_e('These settings apply to all tours globally.', 'hl-core'); ?></p>

                <table class="form-table">
                    <tr>
                        <th scope="row"><label><?php esc_html_e('Tooltip Background', 'hl-core'); ?></label></th>
                        <td><input type="text" name="tooltip_bg" value="<?php echo esc_attr($styles['tooltip_bg']); ?>" class="hl-color-picker"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label><?php esc_html_e('Title Color', 'hl-core'); ?></label></th>
                        <td><input type="text" name="title_color" value="<?php echo esc_attr($styles['title_color']); ?>" class="hl-color-picker"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label><?php esc_html_e('Title Font Size (px)', 'hl-core'); ?></label></th>
                        <td><input type="number" name="title_font_size" value="<?php echo absint($styles['title_font_size']); ?>" min="10" max="32" class="small-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label><?php esc_html_e('Description Color', 'hl-core'); ?></label></th>
                        <td><input type="text" name="desc_color" value="<?php echo esc_attr($styles['desc_color']); ?>" class="hl-color-picker"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label><?php esc_html_e('Description Font Size (px)', 'hl-core'); ?></label></th>
                        <td><input type="number" name="desc_font_size" value="<?php echo absint($styles['desc_font_size']); ?>" min="10" max="24" class="small-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label><?php esc_html_e('Button Background', 'hl-core'); ?></label></th>
                        <td><input type="text" name="btn_bg" value="<?php echo esc_attr($styles['btn_bg']); ?>" class="hl-color-picker"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label><?php esc_html_e('Button Text Color', 'hl-core'); ?></label></th>
                        <td><input type="text" name="btn_text_color" value="<?php echo esc_attr($styles['btn_text_color']); ?>" class="hl-color-picker"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label><?php esc_html_e('Progress Bar Color', 'hl-core'); ?></label></th>
                        <td><input type="text" name="progress_color" value="<?php echo esc_attr($styles['progress_color']); ?>" class="hl-color-picker"></td>
                    </tr>
                </table>

                <p>
                    <?php submit_button(__('Save Styles', 'hl-core'), 'primary', 'submit', false); ?>
                    <button type="submit" name="hl_tour_action" value="reset_styles" class="button" style="margin-left:8px;" onclick="return confirm('<?php esc_attr_e('Reset all tour styles to defaults?', 'hl-core'); ?>');">
                        <?php esc_html_e('Reset to Defaults', 'hl-core'); ?>
                    </button>
                </p>
            </div>
        </form>

        <!-- Live Preview -->
        <div class="hl-settings-card" style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:24px;">
            <h3 style="margin-top:0;font-size:16px;color:#1e3a5f;">
                <span class="dashicons dashicons-visibility" style="margin-right:6px;color:#4a90d9;"></span>
                <?php esc_html_e('Preview', 'hl-core'); ?>
            </h3>
            <div class="hl-tour-style-preview" id="hl-tour-style-preview">
                <div class="hl-tour-preview-tooltip" style="background:<?php echo esc_attr($styles['tooltip_bg']); ?>;max-width:340px;border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,0.12);padding:20px;">
                    <div style="color:<?php echo esc_attr($styles['title_color']); ?>;font-size:<?php echo absint($styles['title_font_size']); ?>px;font-weight:600;margin-bottom:8px;">
                        <?php esc_html_e('Sample Step Title', 'hl-core'); ?>
                    </div>
                    <div style="color:<?php echo esc_attr($styles['desc_color']); ?>;font-size:<?php echo absint($styles['desc_font_size']); ?>px;line-height:1.5;margin-bottom:16px;">
                        <?php esc_html_e('This is a preview of how your tour tooltips will look with the current style settings.', 'hl-core'); ?>
                    </div>
                    <div style="display:flex;gap:8px;justify-content:flex-end;">
                        <button type="button" style="background:<?php echo esc_attr($styles['btn_bg']); ?>;color:<?php echo esc_attr($styles['btn_text_color']); ?>;border:none;padding:6px 16px;border-radius:4px;font-size:13px;cursor:default;">
                            <?php esc_html_e('Next', 'hl-core'); ?>
                        </button>
                    </div>
                    <div style="margin-top:12px;height:4px;background:#e5e7eb;border-radius:2px;overflow:hidden;">
                        <div style="width:40%;height:100%;background:<?php echo esc_attr($styles['progress_color']); ?>;border-radius:2px;"></div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
