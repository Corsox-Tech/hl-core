<?php if (!defined('ABSPATH')) exit;

/**
 * Admin Pathways & Components Page
 *
 * Full CRUD admin page for managing Pathways and their Components.
 *
 * @package HL_Core
 */
class HL_Admin_Pathways {

    /**
     * Singleton instance
     *
     * @var HL_Admin_Pathways|null
     */
    private static $instance = null;

    /**
     * Get singleton instance
     *
     * @return HL_Admin_Pathways
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
        // No hooks needed.
    }

    /**
     * Handle POST saves and GET deletes before any HTML output.
     */
    public function handle_early_actions() {
        $this->handle_actions();

        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : '';

        if ($action === 'delete') {
            $this->handle_delete_pathway();
        }

        if ($action === 'delete_component') {
            $this->handle_delete_component();
        }

        if ($action === 'clone') {
            $this->handle_clone_pathway();
        }

        if ($action === 'toggle_template') {
            $this->handle_toggle_template();
        }

        if ($action === 'assign_pathway') {
            $this->handle_assign_pathway();
        }

        if ($action === 'unassign_pathway') {
            $this->handle_unassign_pathway();
        }

        if ($action === 'bulk_assign_pathway') {
            $this->handle_bulk_assign_pathway();
        }

        if ($action === 'sync_role_defaults') {
            $this->handle_sync_role_defaults();
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
                $this->render_pathway_form();
                break;

            case 'edit':
                $pathway_id = isset($_GET['id']) ? absint($_GET['id']) : 0;
                $pathway    = $this->get_pathway($pathway_id);
                if ($pathway) {
                    $this->render_pathway_form($pathway);
                } else {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Pathway not found.', 'hl-core') . '</p></div>';
                    $this->render_list();
                }
                break;

            case 'view':
                $pathway_id = isset($_GET['id']) ? absint($_GET['id']) : 0;
                $pathway    = $this->get_pathway($pathway_id);
                if ($pathway) {
                    $this->render_pathway_detail($pathway);
                } else {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Pathway not found.', 'hl-core') . '</p></div>';
                    $this->render_list();
                }
                break;

            case 'new_component':
                $pathway_id = isset($_GET['pathway_id']) ? absint($_GET['pathway_id']) : 0;
                $pathway    = $this->get_pathway($pathway_id);
                if ($pathway) {
                    $this->render_component_form($pathway);
                } else {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Pathway not found.', 'hl-core') . '</p></div>';
                    $this->render_list();
                }
                break;

            case 'edit_component':
                $component_id = isset($_GET['component_id']) ? absint($_GET['component_id']) : 0;
                $component    = $this->get_component($component_id);
                if ($component) {
                    $pathway = $this->get_pathway($component->pathway_id);
                    $this->render_component_form($pathway, $component);
                } else {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Component not found.', 'hl-core') . '</p></div>';
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
     * Get pathway by ID
     *
     * @param int $pathway_id
     * @return object|null
     */
    public function get_pathway($pathway_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_pathway WHERE pathway_id = %d",
            $pathway_id
        ));
    }

    /**
     * Get component by ID
     *
     * @param int $component_id
     * @return object|null
     */
    public function get_component($component_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_component WHERE component_id = %d",
            $component_id
        ));
    }

    /**
     * Build a redirect URL back to the partnership editor when in partnership context.
     *
     * @param int    $partnership_id Partnership ID.
     * @param string $tab       Tab slug (e.g. 'pathways').
     * @param array  $extra     Additional query args.
     * @return string Admin URL.
     */
    private function get_partnership_redirect($partnership_id, $tab = 'pathways', $extra = array()) {
        $args = array_merge(array(
            'page'   => 'hl-core',
            'action' => 'edit',
            'id'     => $partnership_id,
            'tab'    => $tab,
        ), $extra);
        return admin_url('admin.php?' . http_build_query($args));
    }

    /**
     * Handle form submissions
     */
    private function handle_actions() {
        // Handle pathway save
        if (isset($_POST['hl_pathway_nonce']) && wp_verify_nonce($_POST['hl_pathway_nonce'], 'hl_save_pathway')) {
            if (!current_user_can('manage_hl_core')) {
                wp_die(__('You do not have permission to perform this action.', 'hl-core'));
            }
            $this->save_pathway();
            return;
        }

        // Handle component save
        if (isset($_POST['hl_component_nonce']) && wp_verify_nonce($_POST['hl_component_nonce'], 'hl_save_component')) {
            if (!current_user_can('manage_hl_core')) {
                wp_die(__('You do not have permission to perform this action.', 'hl-core'));
            }
            $this->save_component();
            return;
        }
    }

    /**
     * Save pathway data
     */
    private function save_pathway() {
        global $wpdb;

        $pathway_id = isset($_POST['pathway_id']) ? absint($_POST['pathway_id']) : 0;

        // Process target_roles
        $target_roles = array();
        if (!empty($_POST['target_roles']) && is_array($_POST['target_roles'])) {
            foreach ($_POST['target_roles'] as $role) {
                $target_roles[] = sanitize_text_field($role);
            }
        }

        // Sanitize new pathway fields
        $description     = isset($_POST['description']) ? wp_kses_post($_POST['description']) : '';
        $objectives      = isset($_POST['objectives']) ? wp_kses_post($_POST['objectives']) : '';
        $syllabus_url    = isset($_POST['syllabus_url']) ? esc_url_raw($_POST['syllabus_url']) : '';
        $featured_img_id = isset($_POST['featured_image_id']) ? absint($_POST['featured_image_id']) : 0;
        $avg_time        = isset($_POST['avg_completion_time']) ? sanitize_text_field($_POST['avg_completion_time']) : '';
        $expiration_raw  = isset($_POST['expiration_date']) ? sanitize_text_field($_POST['expiration_date']) : '';
        $expiration_date = !empty($expiration_raw) ? $expiration_raw : null;

        $data = array(
            'pathway_name'        => sanitize_text_field($_POST['pathway_name']),
            'pathway_code'        => sanitize_text_field($_POST['pathway_code']),
            'partnership_id'            => absint($_POST['partnership_id']),
            'cycle_id'            => !empty($_POST['cycle_id']) ? absint($_POST['cycle_id']) : null,
            'target_roles'        => wp_json_encode($target_roles),
            'description'         => $description,
            'objectives'          => $objectives,
            'syllabus_url'        => $syllabus_url,
            'featured_image_id'   => $featured_img_id ? $featured_img_id : null,
            'avg_completion_time' => $avg_time,
            'expiration_date'     => $expiration_date,
        );

        if (empty($data['pathway_code'])) {
            $data['pathway_code'] = HL_Normalization::generate_code($data['pathway_name']);
        }

        $partnership_context = isset($_POST['_hl_partnership_context']) ? absint($_POST['_hl_partnership_context']) : 0;

        if ($pathway_id > 0) {
            $wpdb->update($wpdb->prefix . 'hl_pathway', $data, array('pathway_id' => $pathway_id));
            if ($partnership_context) {
                $redirect = $this->get_partnership_redirect($partnership_context, 'pathways', array('sub' => 'view', 'pathway_id' => $pathway_id, 'message' => 'pathway_saved'));
            } else {
                $redirect = admin_url('admin.php?page=hl-pathways&action=view&id=' . $pathway_id . '&message=updated');
            }
        } else {
            $data['pathway_uuid'] = HL_DB_Utils::generate_uuid();
            $wpdb->insert($wpdb->prefix . 'hl_pathway', $data);
            $new_id = $wpdb->insert_id;
            if ($partnership_context) {
                $redirect = $this->get_partnership_redirect($partnership_context, 'pathways', array('message' => 'pathway_saved'));
            } else {
                $redirect = admin_url('admin.php?page=hl-pathways&action=view&id=' . $new_id . '&message=created');
            }
        }

        wp_redirect($redirect);
        exit;
    }

    /**
     * Save component data
     */
    private function save_component() {
        global $wpdb;

        $component_id   = isset($_POST['component_id']) ? absint($_POST['component_id']) : 0;
        $pathway_id    = absint($_POST['pathway_id']);
        $component_type = sanitize_text_field($_POST['component_type']);

        // Build external_ref JSON from type-specific dropdowns
        $external_ref = $this->build_external_ref($component_type);

        // Get partnership_id from the pathway
        $partnership_id = $wpdb->get_var($wpdb->prepare(
            "SELECT partnership_id FROM {$wpdb->prefix}hl_pathway WHERE pathway_id = %d",
            $pathway_id
        ));

        $data = array(
            'pathway_id'     => $pathway_id,
            'partnership_id' => absint($partnership_id),
            'component_type' => $component_type,
            'title'          => sanitize_text_field($_POST['title']),
            'description'    => sanitize_textarea_field($_POST['description']),
            'weight'         => floatval($_POST['weight']),
            'ordering_hint'  => intval($_POST['ordering_hint']),
            'external_ref'   => $external_ref,
        );

        if ($component_id > 0) {
            $wpdb->update($wpdb->prefix . 'hl_component', $data, array('component_id' => $component_id));
            $target_component_id = $component_id;
        } else {
            $data['component_uuid'] = HL_DB_Utils::generate_uuid();
            $wpdb->insert($wpdb->prefix . 'hl_component', $data);
            $target_component_id = $wpdb->insert_id;
        }

        // Save prerequisite groups (only when editing — new components redirect first, then edit to add prereqs).
        if ($component_id > 0 && isset($_POST['prereq_groups']) && is_array($_POST['prereq_groups'])) {
            $prereq_groups = $_POST['prereq_groups'];

            // Collect all proposed prereq component IDs for cycle detection.
            $all_proposed_ids = array();
            foreach ($prereq_groups as $grp) {
                if (!empty($grp['component_ids']) && is_array($grp['component_ids'])) {
                    foreach ($grp['component_ids'] as $aid) {
                        $all_proposed_ids[] = absint($aid);
                    }
                }
            }

            // Cycle detection.
            $rules_engine = new HL_Rules_Engine_Service();
            $cycle_check  = $rules_engine->validate_no_cycles($pathway_id, $target_component_id, $all_proposed_ids);

            if (!$cycle_check['valid']) {
                // Cycle detected — store error and redirect back.
                $cycle_names = $this->resolve_component_names($cycle_check['cycle']);
                $msg = sprintf(
                    __('Circular dependency detected: %s. Prerequisites were not saved.', 'hl-core'),
                    implode(' -> ', $cycle_names)
                );
                set_transient('hl_prereq_cycle_error_' . $target_component_id, $msg, 60);

                $cycle_partnership_ctx = isset($_POST['_hl_partnership_context']) ? absint($_POST['_hl_partnership_context']) : 0;
                if ($cycle_partnership_ctx) {
                    $redirect = $this->get_partnership_redirect($cycle_partnership_ctx, 'pathways', array('sub' => 'component', 'pathway_id' => $pathway_id, 'component_id' => $target_component_id, 'prereq_error' => 'cycle'));
                } else {
                    $redirect = admin_url('admin.php?page=hl-pathways&action=edit_component&component_id=' . $target_component_id . '&prereq_error=cycle');
                }
                wp_redirect($redirect);
                exit;
            }

            // Valid — delete old groups/items and insert new ones.
            $old_group_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT group_id FROM {$wpdb->prefix}hl_component_prereq_group WHERE component_id = %d",
                $target_component_id
            ));
            if (!empty($old_group_ids)) {
                $in_ids = implode(',', array_map('intval', $old_group_ids));
                $wpdb->query("DELETE FROM {$wpdb->prefix}hl_component_prereq_item WHERE group_id IN ({$in_ids})");
                $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$wpdb->prefix}hl_component_prereq_group WHERE component_id = %d",
                    $target_component_id
                ));
            }

            foreach ($prereq_groups as $grp) {
                $grp_type    = isset($grp['prereq_type']) ? sanitize_text_field($grp['prereq_type']) : 'all_of';
                $grp_n       = ($grp_type === 'n_of_m' && isset($grp['n_required'])) ? absint($grp['n_required']) : null;
                $grp_act_ids = (!empty($grp['component_ids']) && is_array($grp['component_ids'])) ? $grp['component_ids'] : array();

                if (empty($grp_act_ids)) {
                    continue;
                }

                $wpdb->insert($wpdb->prefix . 'hl_component_prereq_group', array(
                    'component_id' => $target_component_id,
                    'prereq_type'  => $grp_type,
                    'n_required'   => $grp_n,
                ));
                $new_group_id = $wpdb->insert_id;

                foreach ($grp_act_ids as $prereq_aid) {
                    $wpdb->insert($wpdb->prefix . 'hl_component_prereq_item', array(
                        'group_id'                  => $new_group_id,
                        'prerequisite_component_id' => absint($prereq_aid),
                    ));
                }
            }

            // Audit log.
            if (class_exists('HL_Audit_Service')) {
                HL_Audit_Service::log(
                    'prereq_updated',
                    get_current_user_id(),
                    absint($data['partnership_id']),
                    null,
                    $target_component_id,
                    sprintf('Prerequisites updated for component %d', $target_component_id)
                );
            }
        } elseif ($component_id > 0 && !isset($_POST['prereq_groups'])) {
            // No prereq_groups key at all means the section was rendered but all groups removed.
            $old_group_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT group_id FROM {$wpdb->prefix}hl_component_prereq_group WHERE component_id = %d",
                $target_component_id
            ));
            if (!empty($old_group_ids)) {
                $in_ids = implode(',', array_map('intval', $old_group_ids));
                $wpdb->query("DELETE FROM {$wpdb->prefix}hl_component_prereq_item WHERE group_id IN ({$in_ids})");
                $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$wpdb->prefix}hl_component_prereq_group WHERE component_id = %d",
                    $target_component_id
                ));
            }
        }

        // Save drip rules (only on edit — component must exist)
        if ($component_id > 0) {
            // Delete existing drip rules
            $wpdb->delete($wpdb->prefix . 'hl_component_drip_rule', array('component_id' => $target_component_id));

            // Fixed date drip rule
            $drip_fixed_date = !empty($_POST['drip_fixed_date']) ? sanitize_text_field($_POST['drip_fixed_date']) : '';
            if ($drip_fixed_date) {
                $wpdb->insert($wpdb->prefix . 'hl_component_drip_rule', array(
                    'component_id'    => $target_component_id,
                    'drip_type'       => 'fixed_date',
                    'release_at_date' => $drip_fixed_date . ' 00:00:00',
                ));
            }

            // After-completion delay drip rule
            $drip_base_component_id = isset($_POST['drip_base_component_id']) ? absint($_POST['drip_base_component_id']) : 0;
            $drip_delay_days        = isset($_POST['drip_delay_days']) ? absint($_POST['drip_delay_days']) : 0;
            if ($drip_base_component_id && $drip_delay_days) {
                $wpdb->insert($wpdb->prefix . 'hl_component_drip_rule', array(
                    'component_id'      => $target_component_id,
                    'drip_type'         => 'after_completion_delay',
                    'base_component_id' => $drip_base_component_id,
                    'delay_days'        => $drip_delay_days,
                ));
            }
        }

        $partnership_context = isset($_POST['_hl_partnership_context']) ? absint($_POST['_hl_partnership_context']) : 0;
        if ($partnership_context) {
            $redirect = $this->get_partnership_redirect($partnership_context, 'pathways', array('sub' => 'view', 'pathway_id' => $pathway_id, 'message' => 'component_saved'));
        } else {
            $redirect = admin_url('admin.php?page=hl-pathways&action=view&id=' . $pathway_id . '&message=component_saved');
        }
        wp_redirect($redirect);
        exit;
    }

    /**
     * Build external_ref JSON from POST data based on component type
     *
     * @param string $component_type
     * @return string|null JSON string or null
     */
    private function build_external_ref($component_type) {
        $ref = array();

        switch ($component_type) {
            case 'teacher_self_assessment':
                $phase = isset($_POST['assessment_phase']) ? sanitize_text_field($_POST['assessment_phase']) : 'pre';
                $ref = array( 'phase' => $phase );
                break;

            case 'observation':
                $form_id = isset($_POST['jfb_form_id']) ? absint($_POST['jfb_form_id']) : 0;
                if ($form_id) {
                    $ref = array(
                        'form_plugin' => 'jetformbuilder',
                        'form_id'     => $form_id,
                    );
                }
                if (!empty($_POST['required_count'])) {
                    $ref['required_count'] = absint($_POST['required_count']);
                }
                break;

            case 'child_assessment':
                $instrument_id = isset($_POST['instrument_id']) ? absint($_POST['instrument_id']) : 0;
                if ($instrument_id) {
                    $ref = array('instrument_id' => $instrument_id);
                }
                break;

            case 'learndash_course':
                $course_id = isset($_POST['ld_course_id']) ? absint($_POST['ld_course_id']) : 0;
                if ($course_id) {
                    $ref = array('course_id' => $course_id);
                }
                break;

            case 'coaching_session_attendance':
                // No external ref needed
                break;
        }

        return !empty($ref) ? wp_json_encode($ref) : null;
    }

    /**
     * Handle pathway delete
     */
    private function handle_delete_pathway() {
        $pathway_id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        if (!$pathway_id) {
            return;
        }

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'hl_delete_pathway_' . $pathway_id)) {
            wp_die(__('Security check failed.', 'hl-core'));
        }

        if (!current_user_can('manage_hl_core')) {
            wp_die(__('You do not have permission to perform this action.', 'hl-core'));
        }

        global $wpdb;
        // Delete components first
        $wpdb->delete($wpdb->prefix . 'hl_component', array('pathway_id' => $pathway_id));
        $wpdb->delete($wpdb->prefix . 'hl_pathway', array('pathway_id' => $pathway_id));

        $partnership_context = isset($_GET['partnership_context']) ? absint($_GET['partnership_context']) : 0;
        if ($partnership_context) {
            wp_redirect($this->get_partnership_redirect($partnership_context, 'pathways', array('message' => 'pathway_deleted')));
        } else {
            wp_redirect(admin_url('admin.php?page=hl-pathways&message=deleted'));
        }
        exit;
    }

    /**
     * Handle component delete
     */
    private function handle_delete_component() {
        $component_id = isset($_GET['component_id']) ? absint($_GET['component_id']) : 0;
        $pathway_id   = isset($_GET['pathway_id']) ? absint($_GET['pathway_id']) : 0;

        if (!$component_id || !$pathway_id) {
            $this->render_list();
            return;
        }

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'hl_delete_component_' . $component_id)) {
            wp_die(__('Security check failed.', 'hl-core'));
        }

        if (!current_user_can('manage_hl_core')) {
            wp_die(__('You do not have permission to perform this action.', 'hl-core'));
        }

        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'hl_component', array('component_id' => $component_id));

        $partnership_context = isset($_GET['partnership_context']) ? absint($_GET['partnership_context']) : 0;
        if ($partnership_context) {
            wp_redirect($this->get_partnership_redirect($partnership_context, 'pathways', array('sub' => 'view', 'pathway_id' => $pathway_id, 'message' => 'component_deleted')));
        } else {
            wp_redirect(admin_url('admin.php?page=hl-pathways&action=view&id=' . $pathway_id . '&message=component_deleted'));
        }
        exit;
    }

    /**
     * Handle clone pathway action (POST from clone form).
     */
    private function handle_clone_pathway() {
        if (!isset($_POST['hl_clone_nonce']) || !wp_verify_nonce($_POST['hl_clone_nonce'], 'hl_clone_pathway')) {
            wp_die(__('Security check failed.', 'hl-core'));
        }

        if (!current_user_can('manage_hl_core')) {
            wp_die(__('You do not have permission to perform this action.', 'hl-core'));
        }

        $source_id     = isset($_POST['source_pathway_id']) ? absint($_POST['source_pathway_id']) : 0;
        $target_partnership = isset($_POST['target_partnership_id']) ? absint($_POST['target_partnership_id']) : 0;
        $partnership_context = isset($_POST['_hl_partnership_context']) ? absint($_POST['_hl_partnership_context']) : 0;

        if (!$source_id || !$target_partnership) {
            wp_redirect(admin_url('admin.php?page=hl-pathways&message=clone_error'));
            exit;
        }

        $service = new HL_Pathway_Service();
        $result  = $service->clone_pathway($source_id, $target_partnership);

        if (is_wp_error($result)) {
            set_transient('hl_clone_error', $result->get_error_message(), 60);
            if ($partnership_context) {
                wp_redirect($this->get_partnership_redirect($partnership_context, 'pathways', array('message' => 'clone_error')));
            } else {
                wp_redirect(admin_url('admin.php?page=hl-pathways&action=view&id=' . $source_id . '&message=clone_error'));
            }
            exit;
        }

        if ($partnership_context) {
            wp_redirect($this->get_partnership_redirect($partnership_context, 'pathways', array('message' => 'pathway_cloned')));
        } else {
            wp_redirect(admin_url('admin.php?page=hl-pathways&action=edit&id=' . $result . '&message=cloned'));
        }
        exit;
    }

    /**
     * Handle toggle template status (GET action).
     */
    private function handle_toggle_template() {
        $pathway_id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        if (!$pathway_id) {
            return;
        }

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'hl_toggle_template_' . $pathway_id)) {
            wp_die(__('Security check failed.', 'hl-core'));
        }

        if (!current_user_can('manage_hl_core')) {
            wp_die(__('You do not have permission to perform this action.', 'hl-core'));
        }

        $pathway = $this->get_pathway($pathway_id);
        if (!$pathway) {
            wp_redirect(admin_url('admin.php?page=hl-pathways'));
            exit;
        }

        $service = new HL_Pathway_Service();
        $new_val = empty($pathway->is_template) ? true : false;
        $service->set_template($pathway_id, $new_val);

        $msg = $new_val ? 'template_saved' : 'template_removed';
        $partnership_context = isset($_GET['partnership_context']) ? absint($_GET['partnership_context']) : 0;
        if ($partnership_context) {
            wp_redirect($this->get_partnership_redirect($partnership_context, 'pathways', array('sub' => 'view', 'pathway_id' => $pathway_id, 'message' => $msg)));
        } else {
            wp_redirect(admin_url('admin.php?page=hl-pathways&action=view&id=' . $pathway_id . '&message=' . $msg));
        }
        exit;
    }

    /**
     * Handle single pathway assignment (POST).
     */
    private function handle_assign_pathway() {
        if (!isset($_POST['hl_assign_nonce']) || !wp_verify_nonce($_POST['hl_assign_nonce'], 'hl_assign_pathway')) {
            wp_die(__('Security check failed.', 'hl-core'));
        }
        if (!current_user_can('manage_hl_core')) {
            wp_die(__('You do not have permission.', 'hl-core'));
        }

        $pathway_id     = absint($_POST['pathway_id']);
        $enrollment_id  = absint($_POST['enrollment_id']);
        $partnership_context = isset($_POST['_hl_partnership_context']) ? absint($_POST['_hl_partnership_context']) : 0;

        $service = new HL_Pathway_Assignment_Service();
        $service->assign_pathway($enrollment_id, $pathway_id, 'explicit');

        if ($partnership_context) {
            wp_redirect($this->get_partnership_redirect($partnership_context, 'pathways', array('sub' => 'view', 'pathway_id' => $pathway_id, 'message' => 'assigned')));
        } else {
            wp_redirect(admin_url('admin.php?page=hl-pathways&action=view&id=' . $pathway_id . '&message=assigned'));
        }
        exit;
    }

    /**
     * Handle pathway unassignment (GET).
     */
    private function handle_unassign_pathway() {
        $pathway_id    = isset($_GET['pathway_id']) ? absint($_GET['pathway_id']) : 0;
        $enrollment_id = isset($_GET['enrollment_id']) ? absint($_GET['enrollment_id']) : 0;

        if (!$pathway_id || !$enrollment_id) return;

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'hl_unassign_pathway_' . $enrollment_id)) {
            wp_die(__('Security check failed.', 'hl-core'));
        }
        if (!current_user_can('manage_hl_core')) {
            wp_die(__('You do not have permission.', 'hl-core'));
        }

        $service = new HL_Pathway_Assignment_Service();
        $service->unassign_pathway($enrollment_id, $pathway_id);

        $partnership_context = isset($_GET['partnership_context']) ? absint($_GET['partnership_context']) : 0;
        if ($partnership_context) {
            wp_redirect($this->get_partnership_redirect($partnership_context, 'pathways', array('sub' => 'view', 'pathway_id' => $pathway_id, 'message' => 'unassigned')));
        } else {
            wp_redirect(admin_url('admin.php?page=hl-pathways&action=view&id=' . $pathway_id . '&message=unassigned'));
        }
        exit;
    }

    /**
     * Handle bulk pathway assignment (POST).
     */
    private function handle_bulk_assign_pathway() {
        if (!isset($_POST['hl_bulk_assign_nonce']) || !wp_verify_nonce($_POST['hl_bulk_assign_nonce'], 'hl_bulk_assign_pathway')) {
            wp_die(__('Security check failed.', 'hl-core'));
        }
        if (!current_user_can('manage_hl_core')) {
            wp_die(__('You do not have permission.', 'hl-core'));
        }

        $pathway_id     = absint($_POST['pathway_id']);
        $enrollment_ids = isset($_POST['enrollment_ids']) && is_array($_POST['enrollment_ids'])
            ? array_map('absint', $_POST['enrollment_ids'])
            : array();

        if (!empty($enrollment_ids)) {
            $service = new HL_Pathway_Assignment_Service();
            $service->bulk_assign($pathway_id, $enrollment_ids, 'explicit');
        }

        $partnership_context = isset($_POST['_hl_partnership_context']) ? absint($_POST['_hl_partnership_context']) : 0;
        if ($partnership_context) {
            wp_redirect($this->get_partnership_redirect($partnership_context, 'pathways', array('sub' => 'view', 'pathway_id' => $pathway_id, 'message' => 'bulk_assigned')));
        } else {
            wp_redirect(admin_url('admin.php?page=hl-pathways&action=view&id=' . $pathway_id . '&message=bulk_assigned'));
        }
        exit;
    }

    /**
     * Handle sync role defaults for a partnership (GET).
     */
    private function handle_sync_role_defaults() {
        $pathway_id = isset($_GET['pathway_id']) ? absint($_GET['pathway_id']) : 0;
        if (!$pathway_id) return;

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'hl_sync_defaults_' . $pathway_id)) {
            wp_die(__('Security check failed.', 'hl-core'));
        }
        if (!current_user_can('manage_hl_core')) {
            wp_die(__('You do not have permission.', 'hl-core'));
        }

        $pathway = $this->get_pathway($pathway_id);
        if (!$pathway) {
            wp_redirect(admin_url('admin.php?page=hl-pathways'));
            exit;
        }

        $service = new HL_Pathway_Assignment_Service();
        $service->sync_role_defaults($pathway->partnership_id);

        $partnership_context = isset($_GET['partnership_context']) ? absint($_GET['partnership_context']) : 0;
        if ($partnership_context) {
            wp_redirect($this->get_partnership_redirect($partnership_context, 'pathways', array('sub' => 'view', 'pathway_id' => $pathway_id, 'message' => 'synced')));
        } else {
            wp_redirect(admin_url('admin.php?page=hl-pathways&action=view&id=' . $pathway_id . '&message=synced'));
        }
        exit;
    }

    /**
     * Render the pathways list
     */
    private function render_list() {
        global $wpdb;

        $filter_partnership = isset($_GET['partnership_id']) ? absint($_GET['partnership_id']) : 0;
        $view_tab      = isset($_GET['view']) ? sanitize_text_field($_GET['view']) : 'all';

        // Build WHERE clause.
        $conditions = array();
        if ($view_tab === 'templates') {
            $conditions[] = 'pw.is_template = 1';
        }
        if ($filter_partnership) {
            $conditions[] = $wpdb->prepare('pw.partnership_id = %d', $filter_partnership);
        }
        $where = !empty($conditions) ? ' WHERE ' . implode(' AND ', $conditions) : '';

        $pathways = $wpdb->get_results(
            "SELECT pw.*, t.partnership_name,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}hl_component a WHERE a.pathway_id = pw.pathway_id) as component_count
             FROM {$wpdb->prefix}hl_pathway pw
             LEFT JOIN {$wpdb->prefix}hl_partnership t ON pw.partnership_id = t.partnership_id
             {$where}
             ORDER BY pw.pathway_name ASC"
        );

        // Partnerships for filter.
        $partnerships = $wpdb->get_results(
            "SELECT partnership_id, partnership_name FROM {$wpdb->prefix}hl_partnership ORDER BY partnership_name ASC"
        );

        // Template count for tab badge.
        $template_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}hl_pathway WHERE is_template = 1"
        );

        // Messages.
        if (isset($_GET['message'])) {
            $msg = sanitize_text_field($_GET['message']);
            $messages = array(
                'created'          => __('Pathway created successfully.', 'hl-core'),
                'updated'          => __('Pathway updated successfully.', 'hl-core'),
                'cloned'           => __('Pathway cloned successfully. You are now editing the copy.', 'hl-core'),
                'clone_error'      => __('Clone failed. Please try again.', 'hl-core'),
                'template_saved'   => __('Pathway saved as template.', 'hl-core'),
                'template_removed' => __('Pathway removed from templates.', 'hl-core'),
                'component_saved'   => __('Component saved successfully.', 'hl-core'),
                'component_deleted' => __('Component deleted successfully.', 'hl-core'),
                'deleted'           => __('Pathway and its components deleted successfully.', 'hl-core'),
            );
            if (isset($messages[$msg])) {
                $notice_type = ($msg === 'clone_error') ? 'notice-error' : 'notice-success';
                echo '<div class="notice ' . $notice_type . ' is-dismissible"><p>' . esc_html($messages[$msg]) . '</p></div>';
            }
            // Show transient error detail for clone.
            if ($msg === 'clone_error') {
                $err = get_transient('hl_clone_error');
                delete_transient('hl_clone_error');
                if ($err) {
                    echo '<div class="notice notice-error"><p>' . esc_html($err) . '</p></div>';
                }
            }
        }

        // Partnership breadcrumb.
        if ($filter_partnership) {
            $partnership_name = $wpdb->get_var($wpdb->prepare(
                "SELECT partnership_name FROM {$wpdb->prefix}hl_partnership WHERE partnership_id = %d", $filter_partnership
            ));
            if ($partnership_name) {
                echo '<p style="margin:0 0 5px;"><a href="' . esc_url(admin_url('admin.php?page=hl-partnerships&action=edit&id=' . $filter_partnership . '&tab=pathways')) . '">&larr; ' . sprintf(esc_html__('Partnership: %s', 'hl-core'), esc_html($partnership_name)) . '</a></p>';
            }
        }

        echo '<h1 class="wp-heading-inline">' . esc_html__('Pathways & Components', 'hl-core') . '</h1>';
        echo ' <a href="' . esc_url(admin_url('admin.php?page=hl-pathways&action=new')) . '" class="page-title-action">' . esc_html__('Add Pathway', 'hl-core') . '</a>';
        echo '<hr class="wp-header-end">';

        // Tabs: All | Templates.
        $all_url  = admin_url('admin.php?page=hl-pathways');
        $tmpl_url = admin_url('admin.php?page=hl-pathways&view=templates');
        echo '<ul class="subsubsub">';
        echo '<li><a href="' . esc_url($all_url) . '"' . ($view_tab !== 'templates' ? ' class="current"' : '') . '>' . esc_html__('All', 'hl-core') . '</a> | </li>';
        echo '<li><a href="' . esc_url($tmpl_url) . '"' . ($view_tab === 'templates' ? ' class="current"' : '') . '>' . esc_html__('Templates', 'hl-core') . ' <span class="count">(' . esc_html($template_count) . ')</span></a></li>';
        echo '</ul>';
        echo '<div class="clear"></div>';

        // Filter.
        echo '<form method="get" style="margin-bottom:15px;">';
        echo '<input type="hidden" name="page" value="hl-pathways" />';
        if ($view_tab === 'templates') {
            echo '<input type="hidden" name="view" value="templates" />';
        }
        echo '<label for="partnership_id_filter"><strong>' . esc_html__('Filter by Partnership:', 'hl-core') . '</strong> </label>';
        echo '<select name="partnership_id" id="partnership_id_filter">';
        echo '<option value="">' . esc_html__('All Partnerships', 'hl-core') . '</option>';
        if ($partnerships) {
            foreach ($partnerships as $partnership) {
                echo '<option value="' . esc_attr($partnership->partnership_id) . '"' . selected($filter_partnership, $partnership->partnership_id, false) . '>' . esc_html($partnership->partnership_name) . '</option>';
            }
        }
        echo '</select> ';
        submit_button(__('Filter', 'hl-core'), 'secondary', 'submit', false);
        echo '</form>';

        if (empty($pathways)) {
            $empty_msg = ($view_tab === 'templates')
                ? __('No template pathways found. Save a pathway as a template to use it here.', 'hl-core')
                : __('No pathways found.', 'hl-core');
            echo '<p>' . esc_html($empty_msg) . '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('ID', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Name', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Code', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Partnership', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Target Roles', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Components', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Actions', 'hl-core') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($pathways as $pw) {
            $view_url   = admin_url('admin.php?page=hl-pathways&action=view&id=' . $pw->pathway_id);
            $edit_url   = admin_url('admin.php?page=hl-pathways&action=edit&id=' . $pw->pathway_id);
            $delete_url = wp_nonce_url(
                admin_url('admin.php?page=hl-pathways&action=delete&id=' . $pw->pathway_id),
                'hl_delete_pathway_' . $pw->pathway_id
            );

            $roles_arr = json_decode($pw->target_roles, true);
            $roles_display = is_array($roles_arr) ? implode(', ', $roles_arr) : '';

            $name_display = esc_html($pw->pathway_name);
            if (!empty($pw->is_template)) {
                $name_display .= ' <span class="hl-status-badge active" style="font-size:10px;">' . esc_html__('Template', 'hl-core') . '</span>';
            }

            echo '<tr>';
            echo '<td>' . esc_html($pw->pathway_id) . '</td>';
            echo '<td><strong><a href="' . esc_url($view_url) . '">' . $name_display . '</a></strong></td>';
            echo '<td><code>' . esc_html($pw->pathway_code) . '</code></td>';
            echo '<td>' . esc_html($pw->partnership_name) . '</td>';
            echo '<td>' . esc_html($roles_display) . '</td>';
            echo '<td>' . esc_html($pw->component_count) . '</td>';
            echo '<td>';
            echo '<a href="' . esc_url($view_url) . '" class="button button-small">' . esc_html__('View', 'hl-core') . '</a> ';
            echo '<a href="' . esc_url($edit_url) . '" class="button button-small">' . esc_html__('Edit', 'hl-core') . '</a> ';
            echo '<a href="' . esc_url($delete_url) . '" class="button button-small button-link-delete" onclick="return confirm(\'' . esc_js(__('Delete this pathway and all its components?', 'hl-core')) . '\');">' . esc_html__('Delete', 'hl-core') . '</a>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * Render pathway detail with components list
     *
     * @param object $pathway
     * @param array  $context Optional partnership context. Keys: 'partnership_id', 'partnership_name'.
     */
    public function render_pathway_detail($pathway, $context = array()) {
        global $wpdb;
        $in_partnership = !empty($context['partnership_id']);

        $components = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_component WHERE pathway_id = %d ORDER BY ordering_hint ASC, component_id ASC",
            $pathway->pathway_id
        ));

        $partnership = $wpdb->get_row($wpdb->prepare(
            "SELECT partnership_name FROM {$wpdb->prefix}hl_partnership WHERE partnership_id = %d",
            $pathway->partnership_id
        ));

        $roles_arr = json_decode($pathway->target_roles, true);
        $roles_display = is_array($roles_arr) ? implode(', ', $roles_arr) : '';

        // Messages
        if (isset($_GET['message'])) {
            $msg = sanitize_text_field($_GET['message']);
            $messages = array(
                'created'          => __('Pathway created successfully.', 'hl-core'),
                'updated'          => __('Pathway updated successfully.', 'hl-core'),
                'cloned'           => __('Pathway cloned successfully.', 'hl-core'),
                'template_saved'   => __('Pathway saved as template.', 'hl-core'),
                'template_removed' => __('Pathway removed from templates.', 'hl-core'),
                'component_saved'   => __('Component saved successfully.', 'hl-core'),
                'component_deleted' => __('Component deleted successfully.', 'hl-core'),
                'assigned'          => __('Pathway assigned to enrollment.', 'hl-core'),
                'unassigned'       => __('Pathway unassigned from enrollment.', 'hl-core'),
                'bulk_assigned'    => __('Pathway assigned to selected enrollments.', 'hl-core'),
                'synced'           => __('Role-based default assignments synced.', 'hl-core'),
            );
            if (isset($messages[$msg])) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($messages[$msg]) . '</p></div>';
            }
        }

        if (!$in_partnership) {
            echo '<h1>' . esc_html($pathway->pathway_name);
            if (!empty($pathway->is_template)) {
                echo ' <span class="hl-status-badge active" style="font-size:12px; vertical-align:middle;">' . esc_html__('Template', 'hl-core') . '</span>';
            }
            echo '</h1>';
            echo '<a href="' . esc_url(admin_url('admin.php?page=hl-pathways')) . '">&larr; ' . esc_html__('Back to Pathways', 'hl-core') . '</a>';
        } else {
            echo '<h2>' . esc_html($pathway->pathway_name);
            if (!empty($pathway->is_template)) {
                echo ' <span class="hl-status-badge active" style="font-size:12px; vertical-align:middle;">' . esc_html__('Template', 'hl-core') . '</span>';
            }
            echo '</h2>';
        }

        // Action buttons: Edit + Clone to Partnership + Save as Template.
        $all_partnerships = $wpdb->get_results(
            "SELECT partnership_id, partnership_name FROM {$wpdb->prefix}hl_partnership ORDER BY partnership_name ASC"
        );

        echo '<div style="margin:15px 0; display:flex; gap:15px; align-items:flex-start; flex-wrap:wrap;">';

        // Edit button.
        if ($in_partnership) {
            $edit_url = admin_url('admin.php?page=hl-partnerships&action=edit&id=' . $context['partnership_id'] . '&tab=pathways&sub=edit&pathway_id=' . $pathway->pathway_id);
        } else {
            $edit_url = admin_url('admin.php?page=hl-pathways&action=edit&id=' . $pathway->pathway_id);
        }
        echo '<a href="' . esc_url($edit_url) . '" class="button button-primary">' . esc_html__('Edit Pathway', 'hl-core') . '</a>';

        // Clone to Partnership form.
        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=hl-pathways&action=clone')) . '" style="display:flex; gap:8px; align-items:center; background:#f9f9f9; border:1px solid #ccd0d4; padding:8px 12px; border-radius:4px;">';
        wp_nonce_field('hl_clone_pathway', 'hl_clone_nonce');
        echo '<input type="hidden" name="source_pathway_id" value="' . esc_attr($pathway->pathway_id) . '" />';
        if ($in_partnership) {
            echo '<input type="hidden" name="_hl_partnership_context" value="' . esc_attr($context['partnership_id']) . '" />';
        }
        echo '<label for="clone_target_partnership"><strong>' . esc_html__('Clone to Partnership:', 'hl-core') . '</strong></label> ';
        echo '<select name="target_partnership_id" id="clone_target_partnership" required>';
        echo '<option value="">' . esc_html__('-- Select --', 'hl-core') . '</option>';
        foreach ($all_partnerships as $c) {
            echo '<option value="' . esc_attr($c->partnership_id) . '">' . esc_html($c->partnership_name) . '</option>';
        }
        echo '</select> ';
        echo '<button type="submit" class="button button-primary" onclick="return confirm(\'' . esc_js(__('Clone this pathway and all its components to the selected partnership?', 'hl-core')) . '\');">' . esc_html__('Clone', 'hl-core') . '</button>';
        echo '</form>';

        // Save as Template / Remove Template toggle.
        $is_tmpl = !empty($pathway->is_template);
        $tmpl_base_url = admin_url('admin.php?page=hl-pathways&action=toggle_template&id=' . $pathway->pathway_id);
        if ($in_partnership) {
            $tmpl_base_url .= '&partnership_context=' . $context['partnership_id'];
        }
        $tmpl_url = wp_nonce_url($tmpl_base_url, 'hl_toggle_template_' . $pathway->pathway_id);
        if ($is_tmpl) {
            echo '<a href="' . esc_url($tmpl_url) . '" class="button">' . esc_html__('Remove from Templates', 'hl-core') . '</a>';
        } else {
            echo '<a href="' . esc_url($tmpl_url) . '" class="button">' . esc_html__('Save as Template', 'hl-core') . '</a>';
        }

        echo '</div>';

        echo '<table class="form-table">';
        echo '<tr><th>' . esc_html__('Code', 'hl-core') . '</th><td><code>' . esc_html($pathway->pathway_code) . '</code></td></tr>';
        echo '<tr><th>' . esc_html__('Partnership', 'hl-core') . '</th><td>' . esc_html($partnership ? $partnership->partnership_name : 'N/A') . '</td></tr>';
        echo '<tr><th>' . esc_html__('Target Roles', 'hl-core') . '</th><td>' . esc_html($roles_display) . '</td></tr>';

        if (!empty($pathway->description)) {
            echo '<tr><th>' . esc_html__('Description', 'hl-core') . '</th><td>' . wp_kses_post($pathway->description) . '</td></tr>';
        }
        if (!empty($pathway->objectives)) {
            echo '<tr><th>' . esc_html__('Objectives', 'hl-core') . '</th><td>' . wp_kses_post($pathway->objectives) . '</td></tr>';
        }
        if (!empty($pathway->syllabus_url)) {
            echo '<tr><th>' . esc_html__('Syllabus URL', 'hl-core') . '</th><td><a href="' . esc_url($pathway->syllabus_url) . '" target="_blank">' . esc_html($pathway->syllabus_url) . '</a></td></tr>';
        }
        if (!empty($pathway->featured_image_id)) {
            echo '<tr><th>' . esc_html__('Featured Image', 'hl-core') . '</th><td>' . wp_get_attachment_image(absint($pathway->featured_image_id), 'medium') . '</td></tr>';
        }
        if (!empty($pathway->avg_completion_time)) {
            echo '<tr><th>' . esc_html__('Avg Completion Time', 'hl-core') . '</th><td>' . esc_html($pathway->avg_completion_time) . '</td></tr>';
        }
        if (!empty($pathway->expiration_date)) {
            echo '<tr><th>' . esc_html__('Expiration Date', 'hl-core') . '</th><td>' . esc_html(date_i18n(get_option('date_format', 'M j, Y'), strtotime($pathway->expiration_date))) . '</td></tr>';
        }

        echo '</table>';

        // Components section
        if ($in_partnership) {
            $add_component_url = admin_url('admin.php?page=hl-partnerships&action=edit&id=' . $context['partnership_id'] . '&tab=pathways&sub=component&pathway_id=' . $pathway->pathway_id . '&component_action=new');
        } else {
            $add_component_url = admin_url('admin.php?page=hl-pathways&action=new_component&pathway_id=' . $pathway->pathway_id);
        }
        echo '<h2 class="wp-heading-inline">' . esc_html__('Components', 'hl-core') . '</h2>';
        echo ' <a href="' . esc_url($add_component_url) . '" class="page-title-action">' . esc_html__('Add Component', 'hl-core') . '</a>';
        echo '<hr class="wp-header-end">';

        if (empty($components)) {
            echo '<p>' . esc_html__('No components in this pathway yet.', 'hl-core') . '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Order', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Title', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Type', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Linked Resource', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Prerequisites', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Weight', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Actions', 'hl-core') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($components as $act) {
            if ($in_partnership) {
                $edit_url = admin_url('admin.php?page=hl-partnerships&action=edit&id=' . $context['partnership_id'] . '&tab=pathways&sub=component&pathway_id=' . $pathway->pathway_id . '&component_id=' . $act->component_id);
                $delete_url = wp_nonce_url(
                    admin_url('admin.php?page=hl-pathways&action=delete_component&component_id=' . $act->component_id . '&pathway_id=' . $pathway->pathway_id . '&partnership_context=' . $context['partnership_id']),
                    'hl_delete_component_' . $act->component_id
                );
            } else {
                $edit_url = admin_url('admin.php?page=hl-pathways&action=edit_component&component_id=' . $act->component_id);
                $delete_url = wp_nonce_url(
                    admin_url('admin.php?page=hl-pathways&action=delete_component&component_id=' . $act->component_id . '&pathway_id=' . $pathway->pathway_id),
                    'hl_delete_component_' . $act->component_id
                );
            }

            // Format type label
            $type_labels = array(
                'learndash_course'           => __('LearnDash Course', 'hl-core'),
                'teacher_self_assessment'    => __('Self-Assessment', 'hl-core'),
                'child_assessment'        => __('Child Assessment', 'hl-core'),
                'coaching_session_attendance' => __('Coaching Attendance', 'hl-core'),
                'observation'                => __('Observation', 'hl-core'),
            );
            $type_display = isset($type_labels[$act->component_type]) ? $type_labels[$act->component_type] : $act->component_type;

            // Format linked resource
            $linked_display = $this->format_external_ref($act->component_type, $act->external_ref);

            echo '<tr>';
            echo '<td>' . esc_html($act->ordering_hint) . '</td>';
            echo '<td><strong>' . esc_html($act->title) . '</strong></td>';
            echo '<td>' . esc_html($type_display) . '</td>';
            echo '<td>' . $linked_display . '</td>';
            echo '<td>' . $this->format_prereq_summary($act->component_id) . '</td>';
            echo '<td>' . esc_html($act->weight) . '</td>';
            echo '<td>';
            echo '<a href="' . esc_url($edit_url) . '" class="button button-small">' . esc_html__('Edit', 'hl-core') . '</a> ';
            echo '<a href="' . esc_url($delete_url) . '" class="button button-small button-link-delete" onclick="return confirm(\'' . esc_js(__('Delete this component?', 'hl-core')) . '\');">' . esc_html__('Delete', 'hl-core') . '</a>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        // =====================================================================
        // Assigned Enrollments section
        // =====================================================================
        $pa_service  = new HL_Pathway_Assignment_Service();
        $assigned    = $pa_service->get_enrollments_for_pathway($pathway->pathway_id);
        $unassigned  = $pa_service->get_unassigned_enrollments($pathway->pathway_id, $pathway->partnership_id);

        echo '<h2 class="wp-heading-inline" style="margin-top:30px;">' . esc_html__('Assigned Enrollments', 'hl-core') . '</h2>';
        echo ' <span style="color:#666;">(' . count($assigned) . ')</span>';

        // Sync role defaults button.
        $sync_base = admin_url('admin.php?page=hl-pathways&action=sync_role_defaults&pathway_id=' . $pathway->pathway_id);
        if ($in_partnership) {
            $sync_base .= '&partnership_context=' . $context['partnership_id'];
        }
        $sync_url = wp_nonce_url($sync_base, 'hl_sync_defaults_' . $pathway->pathway_id);
        echo ' <a href="' . esc_url($sync_url) . '" class="button button-small" style="margin-left:10px;" title="' . esc_attr__('Auto-assign this pathway to enrollments whose roles match the target roles', 'hl-core') . '">' . esc_html__('Sync Role Defaults', 'hl-core') . '</a>';
        echo '<hr class="wp-header-end">';

        // Bulk assign form.
        if (!empty($unassigned)) {
            echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=hl-pathways&action=bulk_assign_pathway')) . '" style="margin-bottom:15px;">';
            wp_nonce_field('hl_bulk_assign_pathway', 'hl_bulk_assign_nonce');
            echo '<input type="hidden" name="pathway_id" value="' . esc_attr($pathway->pathway_id) . '" />';
            if ($in_partnership) {
                echo '<input type="hidden" name="_hl_partnership_context" value="' . esc_attr($context['partnership_id']) . '" />';
            }
            echo '<div style="display:flex; gap:8px; align-items:flex-start; flex-wrap:wrap;">';
            echo '<select name="enrollment_ids[]" multiple="multiple" style="min-width:300px; min-height:80px;">';
            foreach ($unassigned as $ue) {
                $roles = json_decode($ue['roles'], true);
                $roles_str = is_array($roles) ? implode(', ', $roles) : '';
                $label = $ue['display_name'] . ' (' . $ue['user_email'] . ')';
                if ($roles_str) {
                    $label .= ' — ' . $roles_str;
                }
                echo '<option value="' . esc_attr($ue['enrollment_id']) . '">' . esc_html($label) . '</option>';
            }
            echo '</select>';
            echo '<button type="submit" class="button button-primary">' . esc_html__('Assign Selected', 'hl-core') . '</button>';
            echo '</div>';
            echo '<p class="description">' . esc_html__('Hold Ctrl/Cmd to select multiple enrollments. These are active enrollments in this partnership not yet assigned to this pathway.', 'hl-core') . '</p>';
            echo '</form>';
        }

        // Single assign (quick-add one enrollment).
        if (!empty($unassigned)) {
            echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=hl-pathways&action=assign_pathway')) . '" style="display:flex; gap:8px; align-items:center; margin-bottom:15px;">';
            wp_nonce_field('hl_assign_pathway', 'hl_assign_nonce');
            echo '<input type="hidden" name="pathway_id" value="' . esc_attr($pathway->pathway_id) . '" />';
            if ($in_partnership) {
                echo '<input type="hidden" name="_hl_partnership_context" value="' . esc_attr($context['partnership_id']) . '" />';
            }
            echo '<select name="enrollment_id" required>';
            echo '<option value="">' . esc_html__('-- Quick Assign --', 'hl-core') . '</option>';
            foreach ($unassigned as $ue) {
                echo '<option value="' . esc_attr($ue['enrollment_id']) . '">' . esc_html($ue['display_name'] . ' (' . $ue['user_email'] . ')') . '</option>';
            }
            echo '</select>';
            echo '<button type="submit" class="button">' . esc_html__('Assign', 'hl-core') . '</button>';
            echo '</form>';
        }

        if (empty($assigned)) {
            echo '<p>' . esc_html__('No enrollments explicitly assigned to this pathway yet. Use "Sync Role Defaults" to auto-assign based on target roles, or assign manually above.', 'hl-core') . '</p>';
        } else {
            echo '<table class="widefat striped">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__('Name', 'hl-core') . '</th>';
            echo '<th>' . esc_html__('Email', 'hl-core') . '</th>';
            echo '<th>' . esc_html__('Roles', 'hl-core') . '</th>';
            echo '<th>' . esc_html__('Type', 'hl-core') . '</th>';
            echo '<th>' . esc_html__('Actions', 'hl-core') . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($assigned as $a) {
                $roles = json_decode($a['roles'], true);
                $roles_str = is_array($roles) ? implode(', ', $roles) : '';

                $unassign_base = admin_url('admin.php?page=hl-pathways&action=unassign_pathway&pathway_id=' . $pathway->pathway_id . '&enrollment_id=' . $a['enrollment_id']);
                if ($in_partnership) {
                    $unassign_base .= '&partnership_context=' . $context['partnership_id'];
                }
                $unassign_url = wp_nonce_url($unassign_base, 'hl_unassign_pathway_' . $a['enrollment_id']);

                $type_badge = ($a['assignment_type'] === 'explicit')
                    ? '<span style="display:inline-block;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;background:#d4edda;color:#155724;">Explicit</span>'
                    : '<span style="display:inline-block;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;background:#e2e3e5;color:#383d41;">Role Default</span>';

                echo '<tr>';
                echo '<td><strong>' . esc_html($a['display_name']) . '</strong></td>';
                echo '<td>' . esc_html($a['user_email']) . '</td>';
                echo '<td>' . esc_html($roles_str) . '</td>';
                echo '<td>' . $type_badge . '</td>';
                echo '<td>';
                echo '<a href="' . esc_url($unassign_url) . '" class="button button-small button-link-delete" onclick="return confirm(\'' . esc_js(__('Remove this assignment?', 'hl-core')) . '\');">' . esc_html__('Remove', 'hl-core') . '</a>';
                echo '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }
    }

    /**
     * Render the pathway create/edit form
     *
     * @param object|null $pathway
     * @param array       $context Optional partnership context. Keys: 'partnership_id', 'partnership_name'.
     */
    public function render_pathway_form($pathway = null, $context = array()) {
        $is_edit      = ($pathway !== null);
        $title        = $is_edit ? __('Edit Pathway', 'hl-core') : __('Add New Pathway', 'hl-core');
        $in_partnership    = !empty($context['partnership_id']);

        global $wpdb;
        $partnerships = $wpdb->get_results(
            "SELECT partnership_id, partnership_name FROM {$wpdb->prefix}hl_partnership ORDER BY partnership_name ASC"
        );

        $current_roles = array();
        if ($is_edit && !empty($pathway->target_roles)) {
            $decoded = json_decode($pathway->target_roles, true);
            if (is_array($decoded)) {
                $current_roles = $decoded;
            }
        }

        // Suppress header/back-link when rendering inside partnership editor.
        if (!$in_partnership) {
            echo '<h1>' . esc_html($title) . '</h1>';
            echo '<a href="' . esc_url(admin_url('admin.php?page=hl-pathways')) . '">&larr; ' . esc_html__('Back to Pathways', 'hl-core') . '</a>';
        }

        // "Start from Template" section for new pathways.
        if (!$is_edit) {
            $service   = new HL_Pathway_Service();
            $templates = $service->get_templates();
            if (!empty($templates)) {
                echo '<div style="margin:15px 0; padding:12px 16px; background:#f0f6fc; border:1px solid #c3d9ed; border-radius:4px;">';
                echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=hl-pathways&action=clone')) . '" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">';
                wp_nonce_field('hl_clone_pathway', 'hl_clone_nonce');
                if ($in_partnership) {
                    echo '<input type="hidden" name="_hl_partnership_context" value="' . esc_attr($context['partnership_id']) . '" />';
                }
                echo '<strong>' . esc_html__('Start from Template:', 'hl-core') . '</strong> ';
                echo '<select name="source_pathway_id" required>';
                echo '<option value="">' . esc_html__('-- Select Template --', 'hl-core') . '</option>';
                foreach ($templates as $tmpl) {
                    $roles_arr = $tmpl->get_target_roles_array();
                    $roles_str = is_array($roles_arr) ? implode(', ', $roles_arr) : '';
                    $label     = $tmpl->pathway_name;
                    if ($roles_str) {
                        $label .= ' (' . $roles_str . ')';
                    }
                    echo '<option value="' . esc_attr($tmpl->pathway_id) . '">' . esc_html($label) . '</option>';
                }
                echo '</select> ';
                if ($in_partnership) {
                    echo '<input type="hidden" name="target_partnership_id" value="' . esc_attr($context['partnership_id']) . '" />';
                } else {
                    echo '<select name="target_partnership_id" required>';
                    echo '<option value="">' . esc_html__('-- Into Partnership --', 'hl-core') . '</option>';
                    foreach ($partnerships as $c) {
                        echo '<option value="' . esc_attr($c->partnership_id) . '">' . esc_html($c->partnership_name) . '</option>';
                    }
                    echo '</select> ';
                }
                echo '<button type="submit" class="button button-primary">' . esc_html__('Clone Template', 'hl-core') . '</button>';
                echo '</form>';
                echo '</div>';

                echo '<p style="color:#666; margin-bottom:15px;">' . esc_html__('Or create a blank pathway below:', 'hl-core') . '</p>';
            }
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=hl-pathways')) . '">';
        wp_nonce_field('hl_save_pathway', 'hl_pathway_nonce');
        if ($in_partnership) {
            echo '<input type="hidden" name="_hl_partnership_context" value="' . esc_attr($context['partnership_id']) . '" />';
        }

        if ($is_edit) {
            echo '<input type="hidden" name="pathway_id" value="' . esc_attr($pathway->pathway_id) . '" />';
        }

        echo '<table class="form-table">';

        // Pathway Name
        echo '<tr>';
        echo '<th scope="row"><label for="pathway_name">' . esc_html__('Pathway Name', 'hl-core') . '</label></th>';
        echo '<td><input type="text" id="pathway_name" name="pathway_name" value="' . esc_attr($is_edit ? $pathway->pathway_name : '') . '" class="regular-text" required /></td>';
        echo '</tr>';

        // Code
        echo '<tr>';
        echo '<th scope="row"><label for="pathway_code">' . esc_html__('Code', 'hl-core') . '</label></th>';
        echo '<td><input type="text" id="pathway_code" name="pathway_code" value="' . esc_attr($is_edit ? $pathway->pathway_code : '') . '" class="regular-text" />';
        echo '<p class="description">' . esc_html__('Leave blank to auto-generate.', 'hl-core') . '</p></td>';
        echo '</tr>';

        // Partnership
        $current_partnership = $in_partnership ? absint($context['partnership_id']) : ($is_edit ? $pathway->partnership_id : '');
        echo '<tr>';
        echo '<th scope="row"><label for="partnership_id">' . esc_html__('Partnership', 'hl-core') . '</label></th>';
        if ($in_partnership) {
            // Locked to partnership context — show read-only name + hidden input.
            echo '<td><strong>' . esc_html($context['partnership_name']) . '</strong>';
            echo '<input type="hidden" id="partnership_id" name="partnership_id" value="' . esc_attr($context['partnership_id']) . '" /></td>';
        } else {
            echo '<td><select id="partnership_id" name="partnership_id" required>';
            echo '<option value="">' . esc_html__('-- Select Partnership --', 'hl-core') . '</option>';
            if ($partnerships) {
                foreach ($partnerships as $partnership) {
                    echo '<option value="' . esc_attr($partnership->partnership_id) . '"' . selected($current_partnership, $partnership->partnership_id, false) . '>' . esc_html($partnership->partnership_name) . '</option>';
                }
            }
            echo '</select></td>';
        }
        echo '</tr>';

        // Cycle (dropdown populated from the selected partnership's cycles)
        $cycle_repo = new HL_Cycle_Repository();
        $current_cycle = $is_edit && isset($pathway->cycle_id) ? $pathway->cycle_id : '';
        $cycle_options = array();
        if ($current_partnership) {
            $cycle_options = $cycle_repo->get_by_partnership(absint($current_partnership));
        }
        echo '<tr>';
        echo '<th scope="row"><label for="cycle_id">' . esc_html__('Cycle', 'hl-core') . '</label></th>';
        echo '<td><select id="cycle_id" name="cycle_id">';
        echo '<option value="">' . esc_html__('-- Default Cycle --', 'hl-core') . '</option>';
        foreach ($cycle_options as $cy) {
            echo '<option value="' . esc_attr($cy->cycle_id) . '"' . selected($current_cycle, $cy->cycle_id, false) . '>' . esc_html($cy->cycle_name) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__('Which cycle this pathway belongs to. Leave as default for single-cycle partnerships.', 'hl-core') . '</p></td>';
        echo '</tr>';

        // Target Roles
        $available_roles = array('Teacher', 'Mentor', 'School Leader', 'District Leader');
        echo '<tr>';
        echo '<th scope="row">' . esc_html__('Target Roles', 'hl-core') . '</th>';
        echo '<td><fieldset>';
        foreach ($available_roles as $role) {
            $checked = in_array($role, $current_roles) ? ' checked="checked"' : '';
            echo '<label><input type="checkbox" name="target_roles[]" value="' . esc_attr($role) . '"' . $checked . ' /> ' . esc_html($role) . '</label><br />';
        }
        echo '</fieldset></td>';
        echo '</tr>';

        // Description (rich text)
        echo '<tr>';
        echo '<th scope="row"><label for="pathway_description">' . esc_html__('Description', 'hl-core') . '</label></th>';
        echo '<td>';
        wp_editor(
            $is_edit ? ($pathway->description ?? '') : '',
            'pathway_description',
            array(
                'textarea_name' => 'description',
                'textarea_rows' => 6,
                'media_buttons' => false,
                'teeny'         => true,
            )
        );
        echo '</td>';
        echo '</tr>';

        // Objectives (rich text)
        echo '<tr>';
        echo '<th scope="row"><label for="pathway_objectives">' . esc_html__('Objectives', 'hl-core') . '</label></th>';
        echo '<td>';
        wp_editor(
            $is_edit ? ($pathway->objectives ?? '') : '',
            'pathway_objectives',
            array(
                'textarea_name' => 'objectives',
                'textarea_rows' => 6,
                'media_buttons' => false,
                'teeny'         => true,
            )
        );
        echo '</td>';
        echo '</tr>';

        // Syllabus URL
        echo '<tr>';
        echo '<th scope="row"><label for="syllabus_url">' . esc_html__('Syllabus URL', 'hl-core') . '</label></th>';
        echo '<td><input type="url" id="syllabus_url" name="syllabus_url" value="' . esc_attr($is_edit ? ($pathway->syllabus_url ?? '') : '') . '" class="regular-text" />';
        echo '<p class="description">' . esc_html__('Link to an external syllabus document (PDF, etc.).', 'hl-core') . '</p></td>';
        echo '</tr>';

        // Featured Image (WP Media uploader)
        $featured_image_id = $is_edit ? absint($pathway->featured_image_id ?? 0) : 0;
        $image_preview = $featured_image_id ? wp_get_attachment_image($featured_image_id, 'medium') : '';

        wp_enqueue_media();

        echo '<tr>';
        echo '<th scope="row"><label>' . esc_html__('Featured Image', 'hl-core') . '</label></th>';
        echo '<td>';
        echo '<div id="hl-featured-image-preview" style="margin-bottom:10px;">' . $image_preview . '</div>';
        echo '<input type="hidden" id="featured_image_id" name="featured_image_id" value="' . esc_attr($featured_image_id) . '" />';
        echo '<button type="button" id="hl-select-image" class="button">' . esc_html__('Select Image', 'hl-core') . '</button> ';
        echo '<button type="button" id="hl-remove-image" class="button"' . ($featured_image_id ? '' : ' style="display:none;"') . '>' . esc_html__('Remove Image', 'hl-core') . '</button>';
        echo '</td>';
        echo '</tr>';

        // Avg Completion Time
        echo '<tr>';
        echo '<th scope="row"><label for="avg_completion_time">' . esc_html__('Avg Completion Time', 'hl-core') . '</label></th>';
        echo '<td><input type="text" id="avg_completion_time" name="avg_completion_time" value="' . esc_attr($is_edit ? ($pathway->avg_completion_time ?? '') : '') . '" class="regular-text" />';
        echo '<p class="description">' . esc_html__('e.g., "4 hours 30 minutes"', 'hl-core') . '</p></td>';
        echo '</tr>';

        // Expiration Date
        echo '<tr>';
        echo '<th scope="row"><label for="expiration_date">' . esc_html__('Expiration Date', 'hl-core') . '</label></th>';
        echo '<td><input type="date" id="expiration_date" name="expiration_date" value="' . esc_attr($is_edit ? ($pathway->expiration_date ?? '') : '') . '" />';
        echo '<p class="description">' . esc_html__('Optional. After this date the program is marked as expired.', 'hl-core') . '</p></td>';
        echo '</tr>';

        echo '</table>';
        submit_button($is_edit ? __('Update Pathway', 'hl-core') : __('Create Pathway', 'hl-core'));
        echo '</form>';

        // Media uploader JavaScript
        ?>
        <script type="text/javascript">
        (function($) {
            var frame;
            $('#hl-select-image').on('click', function(e) {
                e.preventDefault();
                if (frame) { frame.open(); return; }
                frame = wp.media({
                    title: '<?php echo esc_js(__('Select Featured Image', 'hl-core')); ?>',
                    button: { text: '<?php echo esc_js(__('Use this image', 'hl-core')); ?>' },
                    multiple: false
                });
                frame.on('select', function() {
                    var attachment = frame.state().get('selection').first().toJSON();
                    $('#featured_image_id').val(attachment.id);
                    var img = attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url;
                    $('#hl-featured-image-preview').html('<img src="' + img + '" style="max-width:300px;height:auto;" />');
                    $('#hl-remove-image').show();
                });
                frame.open();
            });
            $('#hl-remove-image').on('click', function(e) {
                e.preventDefault();
                $('#featured_image_id').val('0');
                $('#hl-featured-image-preview').html('');
                $(this).hide();
            });
        })(jQuery);
        </script>
        <?php
    }

    /**
     * Format external_ref for display in the component list
     *
     * @param string      $component_type
     * @param string|null $external_ref JSON string
     * @return string HTML
     */
    public function format_external_ref($component_type, $external_ref) {
        if (empty($external_ref)) {
            return '<span style="color:#999;">' . esc_html__('Not configured', 'hl-core') . '</span>';
        }

        $ref = json_decode($external_ref, true);
        if (!is_array($ref)) {
            return '<span style="color:#999;">' . esc_html__('Invalid', 'hl-core') . '</span>';
        }

        switch ($component_type) {
            case 'teacher_self_assessment':
                $label = isset($ref['phase']) ? ucfirst($ref['phase']) : '';
                return esc_html($label);

            case 'observation':
                $form_id = isset($ref['form_id']) ? absint($ref['form_id']) : 0;
                $label = '';
                if ($form_id) {
                    $form_title = get_the_title($form_id);
                    $label = $form_title ? $form_title : sprintf(__('Form #%d', 'hl-core'), $form_id);
                }
                if (!empty($ref['required_count'])) {
                    $label .= sprintf(' (%dx)', absint($ref['required_count']));
                }
                return esc_html($label);

            case 'child_assessment':
                $instrument_id = isset($ref['instrument_id']) ? absint($ref['instrument_id']) : 0;
                if ($instrument_id) {
                    global $wpdb;
                    $inst = $wpdb->get_row($wpdb->prepare(
                        "SELECT name, instrument_type, version FROM {$wpdb->prefix}hl_instrument WHERE instrument_id = %d",
                        $instrument_id
                    ));
                    if ($inst) {
                        return esc_html(sprintf('%s (v%s)', $inst->name, $inst->version));
                    }
                    return esc_html(sprintf(__('Instrument #%d', 'hl-core'), $instrument_id));
                }
                return '<span style="color:#999;">' . esc_html__('No instrument', 'hl-core') . '</span>';

            case 'learndash_course':
                $course_id = isset($ref['course_id']) ? absint($ref['course_id']) : 0;
                if ($course_id) {
                    $course_title = get_the_title($course_id);
                    return esc_html($course_title ? $course_title : sprintf(__('Course #%d', 'hl-core'), $course_id));
                }
                return '<span style="color:#999;">' . esc_html__('No course', 'hl-core') . '</span>';

            default:
                return '<span style="color:#999;">—</span>';
        }
    }

    /**
     * Render the component create/edit form
     *
     * @param object      $pathway
     * @param object|null $component
     * @param array       $context Optional partnership context. Keys: 'partnership_id', 'partnership_name'.
     */
    public function render_component_form($pathway, $component = null, $context = array()) {
        $is_edit   = ($component !== null);
        $title     = $is_edit ? __('Edit Component', 'hl-core') : __('Add New Component', 'hl-core');
        $in_partnership = !empty($context['partnership_id']);

        // Parse existing external_ref for pre-populating dropdowns
        $ext_ref = array();
        if ($is_edit && !empty($component->external_ref)) {
            $decoded = json_decode($component->external_ref, true);
            if (is_array($decoded)) {
                $ext_ref = $decoded;
            }
        }

        if (!$in_partnership) {
            echo '<h1>' . esc_html($title) . '</h1>';
            echo '<a href="' . esc_url(admin_url('admin.php?page=hl-pathways&action=view&id=' . $pathway->pathway_id)) . '">&larr; ' . esc_html__('Back to Pathway: ', 'hl-core') . esc_html($pathway->pathway_name) . '</a>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=hl-pathways')) . '">';
        wp_nonce_field('hl_save_component', 'hl_component_nonce');
        echo '<input type="hidden" name="pathway_id" value="' . esc_attr($pathway->pathway_id) . '" />';
        if ($in_partnership) {
            echo '<input type="hidden" name="_hl_partnership_context" value="' . esc_attr($context['partnership_id']) . '" />';
        }

        if ($is_edit) {
            echo '<input type="hidden" name="component_id" value="' . esc_attr($component->component_id) . '" />';
        }

        echo '<table class="form-table">';

        // Component Type (matches DB enum)
        $current_type = $is_edit ? $component->component_type : '';
        $component_types = array(
            'learndash_course'             => __('LearnDash Course', 'hl-core'),
            'teacher_self_assessment'      => __('Teacher Self-Assessment', 'hl-core'),
            'child_assessment'          => __('Child Assessment', 'hl-core'),
            'coaching_session_attendance'   => __('Coaching Session Attendance', 'hl-core'),
            'observation'                  => __('Observation', 'hl-core'),
        );

        $jfb_active = HL_JFB_Integration::instance()->is_active();

        echo '<tr>';
        echo '<th scope="row"><label for="component_type">' . esc_html__('Component Type', 'hl-core') . '</label></th>';
        echo '<td><select id="component_type" name="component_type" required>';
        echo '<option value="">' . esc_html__('-- Select Type --', 'hl-core') . '</option>';
        foreach ($component_types as $type_value => $type_label) {
            $disabled = '';
            // Disable observation type if JFB is not active (observations still use JFB forms)
            if (!$jfb_active && $type_value === 'observation') {
                $disabled = ' disabled="disabled"';
                $type_label .= ' ' . __('(requires JetFormBuilder)', 'hl-core');
            }
            echo '<option value="' . esc_attr($type_value) . '"' . selected($current_type, $type_value, false) . $disabled . '>' . esc_html($type_label) . '</option>';
        }
        echo '</select></td>';
        echo '</tr>';

        // Title
        echo '<tr>';
        echo '<th scope="row"><label for="title">' . esc_html__('Title', 'hl-core') . '</label></th>';
        echo '<td><input type="text" id="title" name="title" value="' . esc_attr($is_edit ? $component->title : '') . '" class="regular-text" required /></td>';
        echo '</tr>';

        // Description
        echo '<tr>';
        echo '<th scope="row"><label for="description">' . esc_html__('Description', 'hl-core') . '</label></th>';
        echo '<td><textarea id="description" name="description" rows="5" class="large-text">' . esc_textarea($is_edit ? $component->description : '') . '</textarea></td>';
        echo '</tr>';

        // Weight
        echo '<tr>';
        echo '<th scope="row"><label for="weight">' . esc_html__('Weight', 'hl-core') . '</label></th>';
        echo '<td><input type="number" id="weight" name="weight" value="' . esc_attr($is_edit ? $component->weight : '1.0') . '" step="0.1" min="0" class="small-text" /></td>';
        echo '</tr>';

        // Ordering Hint
        echo '<tr>';
        echo '<th scope="row"><label for="ordering_hint">' . esc_html__('Ordering Hint', 'hl-core') . '</label></th>';
        echo '<td><input type="number" id="ordering_hint" name="ordering_hint" value="' . esc_attr($is_edit ? $component->ordering_hint : '0') . '" class="small-text" />';
        echo '<p class="description">' . esc_html__('Lower values appear first.', 'hl-core') . '</p></td>';
        echo '</tr>';

        // =====================================================================
        // Conditional fields based on component type
        // =====================================================================

        // --- JFB Form Dropdown (for observation only) ---
        $jfb_forms = HL_JFB_Integration::instance()->get_available_forms();
        $current_form_id = isset($ext_ref['form_id']) ? absint($ext_ref['form_id']) : 0;

        echo '<tr class="hl-component-field hl-field-jfb" style="display:none;">';
        echo '<th scope="row"><label for="jfb_form_id">' . esc_html__('Observation Form', 'hl-core') . '</label></th>';
        echo '<td>';
        if (empty($jfb_forms)) {
            echo '<p class="description">' . esc_html__('No JetFormBuilder forms found. Create a form in JetFormBuilder first.', 'hl-core') . '</p>';
            echo '<input type="hidden" name="jfb_form_id" value="0" />';
        } else {
            echo '<select id="jfb_form_id" name="jfb_form_id">';
            echo '<option value="0">' . esc_html__('-- Select Form --', 'hl-core') . '</option>';
            foreach ($jfb_forms as $form) {
                echo '<option value="' . esc_attr($form['id']) . '"' . selected($current_form_id, $form['id'], false) . '>'
                    . esc_html($form['title']) . ' (ID: ' . esc_html($form['id']) . ')'
                    . '</option>';
            }
            echo '</select>';
            echo '<p class="description">' . esc_html__('Select the JetFormBuilder observation form. It must include hidden fields (hl_enrollment_id, hl_component_id, hl_partnership_id) and a "Call Hook" post-submit action with hook name: hl_core_form_submitted', 'hl-core') . '</p>';
        }
        echo '</td>';
        echo '</tr>';

        // --- Phase dropdown (for teacher_self_assessment only) ---
        $current_phase = isset($ext_ref['phase']) ? $ext_ref['phase'] : 'pre';

        echo '<tr class="hl-component-field hl-field-phase" style="display:none;">';
        echo '<th scope="row"><label for="assessment_phase">' . esc_html__('Assessment Phase', 'hl-core') . '</label></th>';
        echo '<td><select id="assessment_phase" name="assessment_phase">';
        echo '<option value="pre"' . selected($current_phase, 'pre', false) . '>' . esc_html__('Pre-Assessment', 'hl-core') . '</option>';
        echo '<option value="post"' . selected($current_phase, 'post', false) . '>' . esc_html__('Post-Assessment', 'hl-core') . '</option>';
        echo '</select></td>';
        echo '</tr>';

        // --- Required count (for observation only) ---
        $current_required_count = isset($ext_ref['required_count']) ? absint($ext_ref['required_count']) : '';

        echo '<tr class="hl-component-field hl-field-obs-count" style="display:none;">';
        echo '<th scope="row"><label for="required_count">' . esc_html__('Required Observation Count', 'hl-core') . '</label></th>';
        echo '<td><input type="number" id="required_count" name="required_count" value="' . esc_attr($current_required_count) . '" min="1" class="small-text" />';
        echo '<p class="description">' . esc_html__('Number of observations required for completion. Leave blank if not modeled as a component.', 'hl-core') . '</p></td>';
        echo '</tr>';

        // --- Instrument dropdown (for child_assessment) ---
        global $wpdb;
        $instruments = $wpdb->get_results(
            "SELECT instrument_id, name, instrument_type, version
             FROM {$wpdb->prefix}hl_instrument
             WHERE instrument_type LIKE 'children_%'
             ORDER BY name ASC"
        );
        $current_instrument_id = isset($ext_ref['instrument_id']) ? absint($ext_ref['instrument_id']) : 0;

        echo '<tr class="hl-component-field hl-field-instrument" style="display:none;">';
        echo '<th scope="row"><label for="instrument_id">' . esc_html__('Child Assessment Instrument', 'hl-core') . '</label></th>';
        echo '<td>';
        if (empty($instruments)) {
            echo '<p class="description">' . esc_html__('No child assessment instruments found. Create one in the Instruments admin page first.', 'hl-core') . '</p>';
            echo '<input type="hidden" name="instrument_id" value="0" />';
        } else {
            echo '<select id="instrument_id" name="instrument_id">';
            echo '<option value="0">' . esc_html__('-- Select Instrument --', 'hl-core') . '</option>';
            foreach ($instruments as $inst) {
                $label = sprintf('%s (%s v%s)', $inst->name, str_replace('children_', '', $inst->instrument_type), $inst->version);
                echo '<option value="' . esc_attr($inst->instrument_id) . '"' . selected($current_instrument_id, $inst->instrument_id, false) . '>'
                    . esc_html($label) . '</option>';
            }
            echo '</select>';
        }
        echo '</td>';
        echo '</tr>';

        // --- LearnDash Course selector (for learndash_course) ---
        $ld_courses = array();
        if (post_type_exists('sfwd-courses')) {
            $ld_courses = get_posts(array(
                'post_type'      => 'sfwd-courses',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'orderby'        => 'title',
                'order'          => 'ASC',
            ));
        }
        $current_course_id = isset($ext_ref['course_id']) ? absint($ext_ref['course_id']) : 0;

        echo '<tr class="hl-component-field hl-field-ld" style="display:none;">';
        echo '<th scope="row"><label for="ld_course_id">' . esc_html__('LearnDash Course', 'hl-core') . '</label></th>';
        echo '<td>';
        if (empty($ld_courses)) {
            echo '<input type="number" id="ld_course_id" name="ld_course_id" value="' . esc_attr($current_course_id) . '" class="small-text" />';
            echo '<p class="description">' . esc_html__('Enter the LearnDash course post ID. LearnDash is not active, so courses cannot be listed.', 'hl-core') . '</p>';
        } else {
            echo '<select id="ld_course_id" name="ld_course_id">';
            echo '<option value="0">' . esc_html__('-- Select Course --', 'hl-core') . '</option>';
            foreach ($ld_courses as $course) {
                echo '<option value="' . esc_attr($course->ID) . '"' . selected($current_course_id, $course->ID, false) . '>'
                    . esc_html($course->post_title) . ' (ID: ' . esc_html($course->ID) . ')'
                    . '</option>';
            }
            echo '</select>';
        }
        echo '</td>';
        echo '</tr>';

        echo '</table>';

        // =====================================================================
        // Prerequisites section (only for edit mode — component must exist first)
        // =====================================================================
        if ($is_edit) {
            // Show cycle error if redirected back with one.
            if (isset($_GET['prereq_error']) && $_GET['prereq_error'] === 'cycle') {
                $cycle_msg = get_transient('hl_prereq_cycle_error_' . $component->component_id);
                delete_transient('hl_prereq_cycle_error_' . $component->component_id);
                echo '<div class="notice notice-error"><p><strong>' . esc_html__('Prerequisite Error:', 'hl-core') . '</strong> ';
                if ($cycle_msg) {
                    echo esc_html($cycle_msg);
                } else {
                    echo esc_html__('A circular dependency was detected. Prerequisites were not saved.', 'hl-core');
                }
                echo '</p></div>';
            }

            echo '<h2>' . esc_html__('Prerequisites', 'hl-core') . '</h2>';
            echo '<p class="description">' . esc_html__('Define which components must be completed before this one becomes available. Each group is an independent requirement (AND across groups).', 'hl-core') . '</p>';

            // Load other components in this pathway (exclude current).
            $other_components = $wpdb->get_results($wpdb->prepare(
                "SELECT component_id, title, ordering_hint FROM {$wpdb->prefix}hl_component WHERE pathway_id = %d AND component_id != %d ORDER BY ordering_hint ASC, component_id ASC",
                $pathway->pathway_id,
                $component->component_id
            ));

            $existing_groups = $this->get_prereq_groups($component->component_id);

            echo '<div id="hl-prereq-groups">';
            if (!empty($existing_groups)) {
                foreach ($existing_groups as $idx => $grp) {
                    $this->render_prereq_group_row($idx, $grp, $other_components);
                }
            }
            echo '</div>';

            echo '<button type="button" id="hl-add-prereq-group" class="button">' . esc_html__('+ Add Prerequisite Group', 'hl-core') . '</button>';

            // Hidden template for JS cloning.
            echo '<script type="text/template" id="hl-prereq-group-template">';
            ob_start();
            $this->render_prereq_group_row('__INDEX__', array(), $other_components);
            echo ob_get_clean();
            echo '</script>';
        }

        // =====================================================================
        // Release Schedule (Drip Rules) — edit mode only
        // =====================================================================
        if ($is_edit) {
            // Load existing drip rules
            $drip_rules = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}hl_component_drip_rule WHERE component_id = %d",
                $component->component_id
            ), ARRAY_A);

            $drip_fixed_date     = '';
            $drip_base_component = 0;
            $drip_delay_days     = '';

            if ($drip_rules) {
                foreach ($drip_rules as $rule) {
                    if ($rule['drip_type'] === 'fixed_date' && !empty($rule['release_at_date'])) {
                        $drip_fixed_date = date('Y-m-d', strtotime($rule['release_at_date']));
                    } elseif ($rule['drip_type'] === 'after_completion_delay') {
                        $drip_base_component = absint($rule['base_component_id']);
                        $drip_delay_days     = absint($rule['delay_days']);
                    }
                }
            }

            // Load components for the delay dropdown
            $drip_components = $wpdb->get_results($wpdb->prepare(
                "SELECT component_id, title, ordering_hint FROM {$wpdb->prefix}hl_component WHERE pathway_id = %d AND component_id != %d ORDER BY ordering_hint ASC, component_id ASC",
                $pathway->pathway_id,
                $component->component_id
            ));

            echo '<h2>' . esc_html__('Release Schedule', 'hl-core') . '</h2>';
            echo '<p class="description">' . esc_html__('Optional. Define when this component becomes available. If both rules are set, all must be satisfied (most restrictive wins).', 'hl-core') . '</p>';

            echo '<table class="form-table">';

            // Fixed Date
            echo '<tr>';
            echo '<th scope="row"><label for="drip_fixed_date">' . esc_html__('Fixed Release Date', 'hl-core') . '</label></th>';
            echo '<td><input type="date" id="drip_fixed_date" name="drip_fixed_date" value="' . esc_attr($drip_fixed_date) . '" />';
            echo '<p class="description">' . esc_html__('Component will not be available until this date. Leave blank for no date restriction.', 'hl-core') . '</p></td>';
            echo '</tr>';

            // After-completion delay
            echo '<tr>';
            echo '<th scope="row"><label for="drip_base_component_id">' . esc_html__('Delay After Component', 'hl-core') . '</label></th>';
            echo '<td>';
            echo '<select id="drip_base_component_id" name="drip_base_component_id">';
            echo '<option value="0">' . esc_html__('-- None --', 'hl-core') . '</option>';
            foreach ($drip_components as $dact) {
                echo '<option value="' . esc_attr($dact->component_id) . '"' . selected($drip_base_component, $dact->component_id, false) . '>'
                    . esc_html($dact->title) . ' (#' . esc_html($dact->component_id) . ')'
                    . '</option>';
            }
            echo '</select>';
            echo ' + <input type="number" id="drip_delay_days" name="drip_delay_days" value="' . esc_attr($drip_delay_days) . '" min="0" class="small-text" /> ';
            echo esc_html__('days', 'hl-core');
            echo '<p class="description">' . esc_html__('Component becomes available N days after the selected component is completed.', 'hl-core') . '</p>';
            echo '</td>';
            echo '</tr>';

            echo '</table>';
        }

        submit_button($is_edit ? __('Update Component', 'hl-core') : __('Create Component', 'hl-core'));
        echo '</form>';

        // JavaScript to toggle conditional fields
        $this->render_component_form_js($current_type);

        // JavaScript for prereq group management
        if ($is_edit) {
            $next_index = !empty($existing_groups) ? count($existing_groups) : 0;
            ?>
            <script type="text/javascript">
            (function($) {
                var nextIndex = <?php echo (int) $next_index; ?>;
                var template = document.getElementById('hl-prereq-group-template');

                // Add group
                $('#hl-add-prereq-group').on('click', function() {
                    var html = template.innerHTML.replace(/__INDEX__/g, nextIndex);
                    $('#hl-prereq-groups').append(html);
                    nextIndex++;
                    bindGroupEvents();
                });

                // Remove group
                function bindGroupEvents() {
                    $('.hl-remove-prereq-group').off('click').on('click', function() {
                        $(this).closest('.hl-prereq-group').remove();
                    });
                    // Toggle n_required visibility
                    $('.hl-prereq-type-select').off('change').on('change', function() {
                        var wrap = $(this).closest('.hl-prereq-group').find('.hl-n-required-wrap');
                        if ($(this).val() === 'n_of_m') {
                            wrap.show();
                        } else {
                            wrap.hide();
                        }
                    });
                }

                bindGroupEvents();
            })(jQuery);
            </script>
            <?php
        }
    }

    /**
     * Output JavaScript for toggling component type conditional fields
     *
     * @param string $initial_type The current component type (for initial state)
     */
    public function render_component_form_js($initial_type = '') {
        ?>
        <script type="text/javascript">
        (function() {
            var typeSelect = document.getElementById('component_type');
            if (!typeSelect) return;

            var fieldMap = {
                'teacher_self_assessment': ['hl-field-phase'],
                'observation':             ['hl-field-jfb', 'hl-field-obs-count'],
                'child_assessment':     ['hl-field-instrument'],
                'learndash_course':        ['hl-field-ld'],
                'coaching_session_attendance': []
            };

            function toggleFields() {
                var type = typeSelect.value;

                // Hide all conditional fields
                var allFields = document.querySelectorAll('.hl-component-field');
                for (var i = 0; i < allFields.length; i++) {
                    allFields[i].style.display = 'none';
                }

                // Show fields for selected type
                var show = fieldMap[type] || [];
                for (var j = 0; j < show.length; j++) {
                    var els = document.querySelectorAll('.' + show[j]);
                    for (var k = 0; k < els.length; k++) {
                        els[k].style.display = '';
                    }
                }
            }

            typeSelect.addEventListener('change', toggleFields);

            // Set initial state
            toggleFields();
        })();
        </script>
        <?php
    }

    /**
     * Get prerequisite groups with nested items for a given component.
     *
     * @param int $component_id
     * @return array Groups with items.
     */
    private function get_prereq_groups($component_id) {
        global $wpdb;

        $groups = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_component_prereq_group WHERE component_id = %d ORDER BY group_id ASC",
            $component_id
        ), ARRAY_A);

        if (empty($groups)) {
            return array();
        }

        foreach ($groups as &$group) {
            $group['items'] = $wpdb->get_col($wpdb->prepare(
                "SELECT prerequisite_component_id FROM {$wpdb->prefix}hl_component_prereq_item WHERE group_id = %d",
                $group['group_id']
            ));
        }
        unset($group);

        return $groups;
    }

    /**
     * Render a single prereq group row for the component form.
     *
     * @param int    $index            Row index.
     * @param array  $group            Group data (or empty for template).
     * @param array  $other_components Components available as prereqs.
     */
    private function render_prereq_group_row($index, $group, $other_components) {
        $prereq_type = isset($group['prereq_type']) ? $group['prereq_type'] : 'all_of';
        $n_required  = isset($group['n_required']) ? (int) $group['n_required'] : 2;
        $selected_ids = isset($group['items']) ? array_map('intval', $group['items']) : array();

        echo '<div class="hl-prereq-group" data-index="' . esc_attr($index) . '" style="border:1px solid #ccd0d4;padding:10px;margin-bottom:10px;background:#f9f9f9;">';

        // Type selector
        echo '<label><strong>' . esc_html__('Type:', 'hl-core') . '</strong> ';
        echo '<select name="prereq_groups[' . esc_attr($index) . '][prereq_type]" class="hl-prereq-type-select">';
        echo '<option value="all_of"' . selected($prereq_type, 'all_of', false) . '>' . esc_html__('All of', 'hl-core') . '</option>';
        echo '<option value="any_of"' . selected($prereq_type, 'any_of', false) . '>' . esc_html__('Any of', 'hl-core') . '</option>';
        echo '<option value="n_of_m"' . selected($prereq_type, 'n_of_m', false) . '>' . esc_html__('N of M', 'hl-core') . '</option>';
        echo '</select></label> ';

        // N required (visible only for n_of_m)
        $n_style = ($prereq_type === 'n_of_m') ? '' : 'display:none;';
        echo '<span class="hl-n-required-wrap" style="' . esc_attr($n_style) . '">';
        echo '<label>' . esc_html__('N required:', 'hl-core') . ' ';
        echo '<input type="number" name="prereq_groups[' . esc_attr($index) . '][n_required]" value="' . esc_attr($n_required) . '" min="1" class="small-text" />';
        echo '</label></span> ';

        // Remove button
        echo '<button type="button" class="button button-small hl-remove-prereq-group" style="float:right;color:#a00;">' . esc_html__('Remove Group', 'hl-core') . '</button>';

        // Component multi-select
        echo '<div style="clear:both;margin-top:8px;">';
        echo '<label><strong>' . esc_html__('Components:', 'hl-core') . '</strong></label><br/>';
        echo '<select name="prereq_groups[' . esc_attr($index) . '][component_ids][]" multiple="multiple" style="min-width:300px;min-height:80px;">';
        foreach ($other_components as $act) {
            $sel = in_array((int) $act->component_id, $selected_ids, true) ? ' selected="selected"' : '';
            echo '<option value="' . esc_attr($act->component_id) . '"' . $sel . '>'
                . esc_html($act->title) . ' (#' . esc_html($act->component_id) . ')'
                . '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__('Hold Ctrl/Cmd to select multiple.', 'hl-core') . '</p>';
        echo '</div>';

        echo '</div>';
    }

    /**
     * Format a human-readable prereq summary for the component list.
     *
     * @param int $component_id
     * @return string HTML-safe summary.
     */
    public function format_prereq_summary($component_id) {
        $groups = $this->get_prereq_groups($component_id);
        if (empty($groups)) {
            return '<span style="color:#999;">&mdash;</span>';
        }

        $parts = array();
        foreach ($groups as $group) {
            if (empty($group['items'])) {
                continue;
            }
            $names = $this->resolve_component_names($group['items']);
            $type  = $group['prereq_type'];

            switch ($type) {
                case 'any_of':
                    $parts[] = sprintf(
                        __('Any of: %s', 'hl-core'),
                        esc_html(implode(', ', $names))
                    );
                    break;
                case 'n_of_m':
                    $n = isset($group['n_required']) ? (int) $group['n_required'] : 0;
                    $parts[] = sprintf(
                        __('%d of: %s', 'hl-core'),
                        $n,
                        esc_html(implode(', ', $names))
                    );
                    break;
                case 'all_of':
                default:
                    $parts[] = sprintf(
                        __('All of: %s', 'hl-core'),
                        esc_html(implode(', ', $names))
                    );
                    break;
            }
        }

        return !empty($parts) ? implode('<br>', $parts) : '<span style="color:#999;">&mdash;</span>';
    }

    /**
     * Resolve component IDs to their titles.
     *
     * @param int[] $component_ids
     * @return string[]
     */
    private function resolve_component_names($component_ids) {
        if (empty($component_ids)) {
            return array();
        }
        global $wpdb;
        $ids = implode(',', array_map('intval', $component_ids));
        $rows = $wpdb->get_results(
            "SELECT component_id, title FROM {$wpdb->prefix}hl_component WHERE component_id IN ({$ids})",
            ARRAY_A
        );
        $map = array();
        foreach ($rows as $r) {
            $map[(int) $r['component_id']] = $r['title'];
        }
        $names = array();
        foreach ($component_ids as $aid) {
            $names[] = isset($map[(int) $aid]) ? $map[(int) $aid] : ('#' . $aid);
        }
        return $names;
    }
}
