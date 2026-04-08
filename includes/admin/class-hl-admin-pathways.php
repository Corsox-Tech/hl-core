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
        add_action('wp_ajax_hl_reorder_components', array($this, 'ajax_reorder_components'));
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
     * Build a redirect URL back to the cycle editor when in cycle context.
     *
     * @param int    $cycle_id Cycle ID.
     * @param string $tab       Tab slug (e.g. 'pathways').
     * @param array  $extra     Additional query args.
     * @return string Admin URL.
     */
    private function get_cycle_redirect($cycle_id, $tab = 'pathways', $extra = array()) {
        $args = array_merge(array(
            'page'   => 'hl-cycles',
            'action' => 'edit',
            'id'     => $cycle_id,
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

        // Routing type (auto-assignment rule)
        $routing_type_raw = isset($_POST['routing_type']) ? sanitize_text_field($_POST['routing_type']) : '';
        $valid_types = HL_Pathway_Routing_Service::get_valid_routing_types();
        $routing_type = array_key_exists($routing_type_raw, $valid_types) ? $routing_type_raw : null;

        // Uniqueness check: ensure no other pathway in this cycle has this routing_type.
        if ($routing_type !== null) {
            $cycle_id_check = absint($_POST['cycle_id']);
            $conflict = $wpdb->get_var($wpdb->prepare(
                "SELECT pathway_id FROM {$wpdb->prefix}hl_pathway
                 WHERE routing_type = %s AND cycle_id = %d AND pathway_id != %d",
                $routing_type, $cycle_id_check, $pathway_id
            ));
            if ($conflict) {
                $conflict_name = $wpdb->get_var($wpdb->prepare(
                    "SELECT pathway_name FROM {$wpdb->prefix}hl_pathway WHERE pathway_id = %d",
                    $conflict
                ));
                set_transient('hl_pathway_error', sprintf(
                    __('This cycle already has a pathway assigned as "%s" (%s). Each auto-assignment rule can only be used once per cycle.', 'hl-core'),
                    $valid_types[$routing_type], $conflict_name
                ), 60);
                $cycle_context = isset($_POST['_hl_cycle_context']) ? absint($_POST['_hl_cycle_context']) : 0;
                if ($cycle_context) {
                    wp_redirect($this->get_cycle_redirect($cycle_context, 'pathways', array('sub' => $pathway_id > 0 ? 'edit&pathway_id=' . $pathway_id : 'add', 'message' => 'routing_type_conflict')));
                } else {
                    wp_redirect(admin_url('admin.php?page=hl-pathways&action=' . ($pathway_id > 0 ? 'edit&id=' . $pathway_id : 'add') . '&message=routing_type_conflict'));
                }
                exit;
            }
        }

        $data = array(
            'pathway_name'        => sanitize_text_field($_POST['pathway_name']),
            'pathway_code'        => sanitize_text_field($_POST['pathway_code']),
            'cycle_id'            => absint($_POST['cycle_id']),
            'target_roles'        => wp_json_encode($target_roles),
            'description'         => $description,
            'objectives'          => $objectives,
            'syllabus_url'        => $syllabus_url,
            'featured_image_id'   => $featured_img_id ? $featured_img_id : null,
            'avg_completion_time' => $avg_time,
            'expiration_date'     => $expiration_date,
            'routing_type'        => $routing_type,
        );

        if (empty($data['pathway_code'])) {
            $data['pathway_code'] = HL_Normalization::generate_code($data['pathway_name']);
        }

        $cycle_context = isset($_POST['_hl_cycle_context']) ? absint($_POST['_hl_cycle_context']) : 0;

        if ($pathway_id > 0) {
            $wpdb->update($wpdb->prefix . 'hl_pathway', $data, array('pathway_id' => $pathway_id));
            if (!empty($wpdb->last_error)) {
                set_transient('hl_pathway_error', __('Failed to save pathway. A database constraint was violated.', 'hl-core'), 60);
                if ($cycle_context) {
                    wp_redirect($this->get_cycle_redirect($cycle_context, 'pathways', array('sub' => 'edit&pathway_id=' . $pathway_id, 'message' => 'db_error')));
                } else {
                    wp_redirect(admin_url('admin.php?page=hl-pathways&action=edit&id=' . $pathway_id . '&message=db_error'));
                }
                exit;
            }
            if ($cycle_context) {
                $redirect = $this->get_cycle_redirect($cycle_context, 'pathways', array('sub' => 'view', 'pathway_id' => $pathway_id, 'message' => 'pathway_saved'));
            } else {
                $redirect = admin_url('admin.php?page=hl-pathways&action=view&id=' . $pathway_id . '&message=updated');
            }
        } else {
            $data['pathway_uuid'] = HL_DB_Utils::generate_uuid();
            $wpdb->insert($wpdb->prefix . 'hl_pathway', $data);
            if (!empty($wpdb->last_error)) {
                set_transient('hl_pathway_error', __('Failed to save pathway. A database constraint was violated.', 'hl-core'), 60);
                if ($cycle_context) {
                    wp_redirect($this->get_cycle_redirect($cycle_context, 'pathways', array('sub' => 'add', 'message' => 'db_error')));
                } else {
                    wp_redirect(admin_url('admin.php?page=hl-pathways&action=add&message=db_error'));
                }
                exit;
            }
            $new_id = $wpdb->insert_id;
            if ($cycle_context) {
                $redirect = $this->get_cycle_redirect($cycle_context, 'pathways', array('message' => 'pathway_saved'));
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

        // Get cycle_id from the pathway
        $cycle_id = $wpdb->get_var($wpdb->prepare(
            "SELECT cycle_id FROM {$wpdb->prefix}hl_pathway WHERE pathway_id = %d",
            $pathway_id
        ));

        $data = array(
            'pathway_id'     => $pathway_id,
            'cycle_id' => absint($cycle_id),
            'component_type' => $component_type,
            'title'          => sanitize_text_field($_POST['title']),
            'description'    => sanitize_textarea_field($_POST['description']),
            'weight'         => floatval($_POST['weight']),
            'ordering_hint'  => intval($_POST['ordering_hint']),
            'external_ref'   => $external_ref,
        );

        // Catalog ID for learndash_course components.
        if ($component_type === 'learndash_course') {
            $catalog_id = absint($_POST['catalog_id'] ?? 0);
            if ($catalog_id) {
                $data['catalog_id'] = $catalog_id;
                $cat_repo  = new HL_Course_Catalog_Repository();
                $cat_entry = $cat_repo->get_by_id($catalog_id);
                if ($cat_entry) {
                    // Auto-fill title from catalog if empty.
                    if (empty($data['title'])) {
                        $data['title'] = $cat_entry->title;
                    }
                    // Set external_ref for backward compatibility.
                    if ($cat_entry->ld_course_en) {
                        $data['external_ref'] = wp_json_encode(array('course_id' => absint($cat_entry->ld_course_en)));
                    }
                }
            }
        }

        // Complete-by date.
        $data['complete_by'] = !empty($_POST['complete_by']) ? sanitize_text_field($_POST['complete_by']) : null;

        // Scheduling window (coaching sessions only).
        if ($component_type === 'coaching_session_attendance') {
            $data['scheduling_window_start'] = !empty($_POST['scheduling_window_start']) ? sanitize_text_field($_POST['scheduling_window_start']) : null;
            $data['scheduling_window_end']   = !empty($_POST['scheduling_window_end']) ? sanitize_text_field($_POST['scheduling_window_end']) : null;
            $data['display_window_start']    = !empty($_POST['display_window_start']) ? sanitize_text_field($_POST['display_window_start']) : null;
            $data['display_window_end']      = !empty($_POST['display_window_end']) ? sanitize_text_field($_POST['display_window_end']) : null;
        }

        // Eligibility rules.
        $data['requires_classroom'] = !empty($_POST['requires_classroom']) ? 1 : 0;
        $eligible_roles_raw = isset($_POST['eligible_roles']) && is_array($_POST['eligible_roles'])
            ? array_map('sanitize_text_field', $_POST['eligible_roles'])
            : array();
        $data['eligible_roles'] = !empty($eligible_roles_raw) ? wp_json_encode(array_values($eligible_roles_raw)) : null;

        if ($component_id > 0) {
            $wpdb->update($wpdb->prefix . 'hl_component', $data, array('component_id' => $component_id));
            $target_component_id = $component_id;
        } else {
            $data['component_uuid'] = HL_DB_Utils::generate_uuid();
            $wpdb->insert($wpdb->prefix . 'hl_component', $data);
            $target_component_id = $wpdb->insert_id;

            // Sync: enroll existing pathway users in this new LD course.
            if ($target_component_id && $component_type === 'learndash_course') {
                do_action('hl_learndash_component_created', $target_component_id, $pathway_id);
            }
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

                $cycle_cycle_ctx = isset($_POST['_hl_cycle_context']) ? absint($_POST['_hl_cycle_context']) : 0;
                if ($cycle_cycle_ctx) {
                    $redirect = $this->get_cycle_redirect($cycle_cycle_ctx, 'pathways', array('sub' => 'component', 'pathway_id' => $pathway_id, 'component_id' => $target_component_id, 'prereq_error' => 'cycle'));
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
                    absint($data['cycle_id']),
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

        $cycle_context = isset($_POST['_hl_cycle_context']) ? absint($_POST['_hl_cycle_context']) : 0;
        if ($cycle_context) {
            $redirect = $this->get_cycle_redirect($cycle_context, 'pathways', array('sub' => 'view', 'pathway_id' => $pathway_id, 'message' => 'component_saved'));
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
                $teacher_instrument_id = isset($_POST['teacher_instrument_id']) ? absint($_POST['teacher_instrument_id']) : 0;
                $ref = array( 'phase' => $phase );
                if ($teacher_instrument_id) {
                    $ref['teacher_instrument_id'] = $teacher_instrument_id;
                }
                break;

            case 'child_assessment':
                $instrument_id = isset($_POST['instrument_id']) ? absint($_POST['instrument_id']) : 0;
                if ($instrument_id) {
                    $ref = array('instrument_id' => $instrument_id);
                }
                break;

            case 'learndash_course':
                // external_ref is now set from catalog_id in save_component().
                // This branch handles legacy fallback when no catalog_id is provided.
                $course_id = isset($_POST['ld_course_id']) ? absint($_POST['ld_course_id']) : 0;
                if ($course_id) {
                    $ref = array('course_id' => $course_id);
                }
                break;

            case 'coaching_session_attendance':
            case 'reflective_practice_session':
            case 'classroom_visit':
            case 'self_reflection':
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

        $cycle_context = isset($_GET['cycle_context']) ? absint($_GET['cycle_context']) : 0;
        if ($cycle_context) {
            wp_redirect($this->get_cycle_redirect($cycle_context, 'pathways', array('message' => 'pathway_deleted')));
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

        $cycle_context = isset($_GET['cycle_context']) ? absint($_GET['cycle_context']) : 0;
        if ($cycle_context) {
            wp_redirect($this->get_cycle_redirect($cycle_context, 'pathways', array('sub' => 'view', 'pathway_id' => $pathway_id, 'message' => 'component_deleted')));
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
        $target_cycle = isset($_POST['target_cycle_id']) ? absint($_POST['target_cycle_id']) : 0;
        $cycle_context = isset($_POST['_hl_cycle_context']) ? absint($_POST['_hl_cycle_context']) : 0;

        if (!$source_id || !$target_cycle) {
            wp_redirect(admin_url('admin.php?page=hl-pathways&message=clone_error'));
            exit;
        }

        $service = new HL_Pathway_Service();
        $result  = $service->clone_pathway($source_id, $target_cycle);

        if (is_wp_error($result)) {
            set_transient('hl_clone_error', $result->get_error_message(), 60);
            if ($cycle_context) {
                wp_redirect($this->get_cycle_redirect($cycle_context, 'pathways', array('message' => 'clone_error')));
            } else {
                wp_redirect(admin_url('admin.php?page=hl-pathways&action=view&id=' . $source_id . '&message=clone_error'));
            }
            exit;
        }

        if ($cycle_context) {
            wp_redirect($this->get_cycle_redirect($cycle_context, 'pathways', array('message' => 'pathway_cloned')));
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
        $cycle_context = isset($_GET['cycle_context']) ? absint($_GET['cycle_context']) : 0;
        if ($cycle_context) {
            wp_redirect($this->get_cycle_redirect($cycle_context, 'pathways', array('sub' => 'view', 'pathway_id' => $pathway_id, 'message' => $msg)));
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
        $cycle_context = isset($_POST['_hl_cycle_context']) ? absint($_POST['_hl_cycle_context']) : 0;

        $service = new HL_Pathway_Assignment_Service();
        $service->assign_pathway($enrollment_id, $pathway_id, 'explicit');

        if ($cycle_context) {
            wp_redirect($this->get_cycle_redirect($cycle_context, 'pathways', array('sub' => 'view', 'pathway_id' => $pathway_id, 'message' => 'assigned')));
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

        $cycle_context = isset($_GET['cycle_context']) ? absint($_GET['cycle_context']) : 0;
        if ($cycle_context) {
            wp_redirect($this->get_cycle_redirect($cycle_context, 'pathways', array('sub' => 'view', 'pathway_id' => $pathway_id, 'message' => 'unassigned')));
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

        $cycle_context = isset($_POST['_hl_cycle_context']) ? absint($_POST['_hl_cycle_context']) : 0;
        if ($cycle_context) {
            wp_redirect($this->get_cycle_redirect($cycle_context, 'pathways', array('sub' => 'view', 'pathway_id' => $pathway_id, 'message' => 'bulk_assigned')));
        } else {
            wp_redirect(admin_url('admin.php?page=hl-pathways&action=view&id=' . $pathway_id . '&message=bulk_assigned'));
        }
        exit;
    }

    /**
     * Handle sync role defaults for a cycle (GET).
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
        $service->sync_role_defaults($pathway->cycle_id);

        $cycle_context = isset($_GET['cycle_context']) ? absint($_GET['cycle_context']) : 0;
        if ($cycle_context) {
            wp_redirect($this->get_cycle_redirect($cycle_context, 'pathways', array('sub' => 'view', 'pathway_id' => $pathway_id, 'message' => 'synced')));
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

        $filter_cycle = isset($_GET['cycle_id']) ? absint($_GET['cycle_id']) : 0;
        $view_tab      = isset($_GET['view']) ? sanitize_text_field($_GET['view']) : 'all';

        // Build WHERE clause.
        $conditions = array();
        if ($view_tab === 'templates') {
            $conditions[] = 'pw.is_template = 1';
        }
        if ($filter_cycle) {
            $conditions[] = $wpdb->prepare('pw.cycle_id = %d', $filter_cycle);
        }
        $where = !empty($conditions) ? ' WHERE ' . implode(' AND ', $conditions) : '';

        $pathways = $wpdb->get_results(
            "SELECT pw.*, t.cycle_name,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}hl_component a WHERE a.pathway_id = pw.pathway_id) as component_count
             FROM {$wpdb->prefix}hl_pathway pw
             LEFT JOIN {$wpdb->prefix}hl_cycle t ON pw.cycle_id = t.cycle_id
             {$where}
             ORDER BY pw.pathway_name ASC"
        );

        // Cycles for filter.
        $cycles = $wpdb->get_results(
            "SELECT cycle_id, cycle_name FROM {$wpdb->prefix}hl_cycle ORDER BY cycle_name ASC"
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
                'pathway_cloned'   => __('Pathway cloned successfully.', 'hl-core'),
                'clone_error'      => __('Clone failed. Please try again.', 'hl-core'),
                'template_saved'   => __('Pathway saved as template.', 'hl-core'),
                'template_removed' => __('Pathway removed from templates.', 'hl-core'),
                'component_saved'   => __('Component saved successfully.', 'hl-core'),
                'component_deleted' => __('Component deleted successfully.', 'hl-core'),
                'deleted'           => __('Pathway and its components deleted successfully.', 'hl-core'),
            );
            if (isset($messages[$msg])) {
                $notice_type = in_array($msg, array('clone_error', 'db_error', 'routing_type_conflict'), true) ? 'notice-error' : 'notice-success';
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
            // Show routing_type warning after successful clone.
            $routing_notice = get_transient('hl_clone_routing_notice');
            if ($routing_notice) {
                echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html($routing_notice) . '</p></div>';
                delete_transient('hl_clone_routing_notice');
            }
        }

        // Show pathway error transient (e.g. routing_type conflict, DB constraint).
        $transient_error = get_transient('hl_pathway_error');
        if ($transient_error) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($transient_error) . '</p></div>';
            delete_transient('hl_pathway_error');
        }

        // Cycle breadcrumb.
        if ($filter_cycle) {
            $cycle_name = $wpdb->get_var($wpdb->prepare(
                "SELECT cycle_name FROM {$wpdb->prefix}hl_cycle WHERE cycle_id = %d", $filter_cycle
            ));
            if ($cycle_name) {
                echo '<p style="margin:0 0 5px;"><a href="' . esc_url(admin_url('admin.php?page=hl-cycles&action=edit&id=' . $filter_cycle . '&tab=pathways')) . '">&larr; ' . sprintf(esc_html__('Cycle: %s', 'hl-core'), esc_html($cycle_name)) . '</a></p>';
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
        echo '<label for="cycle_id_filter"><strong>' . esc_html__('Filter by Cycle:', 'hl-core') . '</strong> </label>';
        echo '<select name="cycle_id" id="cycle_id_filter">';
        echo '<option value="">' . esc_html__('All Cycles', 'hl-core') . '</option>';
        if ($cycles) {
            foreach ($cycles as $cycle) {
                echo '<option value="' . esc_attr($cycle->cycle_id) . '"' . selected($filter_cycle, $cycle->cycle_id, false) . '>' . esc_html($cycle->cycle_name) . '</option>';
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
        echo '<th>' . esc_html__('Cycle', 'hl-core') . '</th>';
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
            echo '<td>' . esc_html($pw->cycle_name) . '</td>';
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
     * @param array  $context Optional cycle context. Keys: 'cycle_id', 'cycle_name'.
     */
    public function render_pathway_detail($pathway, $context = array()) {
        global $wpdb;
        $in_cycle = !empty($context['cycle_id']);

        $components = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_component WHERE pathway_id = %d ORDER BY ordering_hint ASC, component_id ASC",
            $pathway->pathway_id
        ));

        $cycle = $wpdb->get_row($wpdb->prepare(
            "SELECT cycle_name FROM {$wpdb->prefix}hl_cycle WHERE cycle_id = %d",
            $pathway->cycle_id
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

        // Show pathway error transient (e.g. routing_type conflict, DB constraint).
        $transient_error = get_transient('hl_pathway_error');
        if ($transient_error) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($transient_error) . '</p></div>';
            delete_transient('hl_pathway_error');
        }

        if (!$in_cycle) {
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

        // Action buttons: Edit + Clone to Cycle + Save as Template.
        $all_cycles = $wpdb->get_results(
            "SELECT cycle_id, cycle_name FROM {$wpdb->prefix}hl_cycle ORDER BY cycle_name ASC"
        );

        echo '<div style="margin:15px 0; display:flex; gap:15px; align-items:flex-start; flex-wrap:wrap;">';

        // Edit button.
        if ($in_cycle) {
            $edit_url = admin_url('admin.php?page=hl-cycles&action=edit&id=' . $context['cycle_id'] . '&tab=pathways&sub=edit&pathway_id=' . $pathway->pathway_id);
        } else {
            $edit_url = admin_url('admin.php?page=hl-pathways&action=edit&id=' . $pathway->pathway_id);
        }
        echo '<a href="' . esc_url($edit_url) . '" class="button button-primary">' . esc_html__('Edit Pathway', 'hl-core') . '</a>';

        // Clone to Cycle form.
        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=hl-pathways&action=clone')) . '" style="display:flex; gap:8px; align-items:center; background:#f9f9f9; border:1px solid #ccd0d4; padding:8px 12px; border-radius:4px;">';
        wp_nonce_field('hl_clone_pathway', 'hl_clone_nonce');
        echo '<input type="hidden" name="source_pathway_id" value="' . esc_attr($pathway->pathway_id) . '" />';
        if ($in_cycle) {
            echo '<input type="hidden" name="_hl_cycle_context" value="' . esc_attr($context['cycle_id']) . '" />';
        }
        echo '<label for="clone_target_cycle"><strong>' . esc_html__('Clone to Cycle:', 'hl-core') . '</strong></label> ';
        echo '<select name="target_cycle_id" id="clone_target_cycle" required>';
        echo '<option value="">' . esc_html__('-- Select --', 'hl-core') . '</option>';
        foreach ($all_cycles as $c) {
            echo '<option value="' . esc_attr($c->cycle_id) . '">' . esc_html($c->cycle_name) . '</option>';
        }
        echo '</select> ';
        echo '<button type="submit" class="button button-primary" onclick="return confirm(\'' . esc_js(__('Clone this pathway and all its components to the selected cycle?', 'hl-core')) . '\');">' . esc_html__('Clone', 'hl-core') . '</button>';
        echo '</form>';

        // Save as Template / Remove Template toggle.
        $is_tmpl = !empty($pathway->is_template);
        $tmpl_base_url = admin_url('admin.php?page=hl-pathways&action=toggle_template&id=' . $pathway->pathway_id);
        if ($in_cycle) {
            $tmpl_base_url .= '&cycle_context=' . $context['cycle_id'];
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
        echo '<tr><th>' . esc_html__('Cycle', 'hl-core') . '</th><td>' . esc_html($cycle ? $cycle->cycle_name : 'N/A') . '</td></tr>';
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
        if ($in_cycle) {
            $add_component_url = admin_url('admin.php?page=hl-cycles&action=edit&id=' . $context['cycle_id'] . '&tab=pathways&sub=component&pathway_id=' . $pathway->pathway_id . '&component_action=new');
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

        // Enqueue jQuery UI Sortable for drag-and-drop reordering.
        wp_enqueue_script('jquery-ui-sortable');

        // Pre-load release dates (fixed_date drip rules) for all components in one query.
        $component_ids = wp_list_pluck($components, 'component_id');
        $release_dates = array();
        if (!empty($component_ids)) {
            $ids_csv = implode(',', array_map('intval', $component_ids));
            $drip_rows = $wpdb->get_results(
                "SELECT component_id, release_at_date FROM {$wpdb->prefix}hl_component_drip_rule WHERE component_id IN ({$ids_csv}) AND drip_type = 'fixed_date'"
            );
            foreach ($drip_rows as $dr) {
                $release_dates[$dr->component_id] = $dr->release_at_date;
            }
        }

        echo '<table class="widefat striped" id="hl-components-sortable" style="width:100%;">';
        echo '<thead><tr>';
        echo '<th style="width:32px;"></th>';
        echo '<th style="width:30px;">' . esc_html__('#', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Title', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Type', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Linked Resource', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Prerequisites', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Release Date', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Complete By', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Weight', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Actions', 'hl-core') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        $position = 0;
        foreach ($components as $act) {
            $position++;
            if ($in_cycle) {
                $edit_url = admin_url('admin.php?page=hl-cycles&action=edit&id=' . $context['cycle_id'] . '&tab=pathways&sub=component&pathway_id=' . $pathway->pathway_id . '&component_id=' . $act->component_id);
                $delete_url = wp_nonce_url(
                    admin_url('admin.php?page=hl-pathways&action=delete_component&component_id=' . $act->component_id . '&pathway_id=' . $pathway->pathway_id . '&cycle_context=' . $context['cycle_id']),
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
                'learndash_course'            => __('LearnDash Course', 'hl-core'),
                'teacher_self_assessment'     => __('Self-Assessment', 'hl-core'),
                'child_assessment'            => __('Child Assessment', 'hl-core'),
                'coaching_session_attendance' => __('Coaching Attendance', 'hl-core'),
                'reflective_practice_session' => __('Reflective Practice', 'hl-core'),
                'classroom_visit'             => __('Classroom Visit', 'hl-core'),
                'self_reflection'             => __('Self-Reflection', 'hl-core'),
            );
            $type_display = isset($type_labels[$act->component_type]) ? $type_labels[$act->component_type] : $act->component_type;

            // Format linked resource
            $linked_display = $this->format_external_ref($act->component_type, $act->external_ref);

            echo '<tr data-component-id="' . esc_attr($act->component_id) . '">';
            echo '<td class="hl-drag-handle" title="' . esc_attr__('Drag to reorder', 'hl-core') . '"><span class="dashicons dashicons-menu"></span></td>';
            echo '<td class="hl-position-number">' . esc_html($position) . '</td>';
            echo '<td><strong>' . esc_html($act->title) . '</strong></td>';
            echo '<td>' . esc_html($type_display) . '</td>';
            echo '<td>' . $linked_display . '</td>';
            echo '<td>' . $this->format_prereq_summary($act->component_id) . '</td>';

            // Release date
            $release_date = isset($release_dates[$act->component_id]) ? date('Y-m-d', strtotime($release_dates[$act->component_id])) : '—';
            echo '<td>' . esc_html($release_date) . '</td>';

            // Complete by
            $complete_by = !empty($act->complete_by) ? esc_html($act->complete_by) : '—';
            echo '<td>' . $complete_by . '</td>';

            echo '<td>' . esc_html($act->weight) . '</td>';
            echo '<td>';
            echo '<a href="' . esc_url($edit_url) . '" class="button button-small">' . esc_html__('Edit', 'hl-core') . '</a> ';
            echo '<a href="' . esc_url($delete_url) . '" class="button button-small button-link-delete" onclick="return confirm(\'' . esc_js(__('Delete this component?', 'hl-core')) . '\');">' . esc_html__('Delete', 'hl-core') . '</a>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        // Drag-and-drop reorder JS + CSS.
        $reorder_nonce = wp_create_nonce('hl_reorder_components');
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            var $tbody = $('#hl-components-sortable tbody');
            $tbody.sortable({
                handle: '.hl-drag-handle',
                placeholder: 'hl-sortable-placeholder',
                helper: function(e, tr) {
                    var $originals = tr.children();
                    var $helper = tr.clone();
                    $helper.children().each(function(index) {
                        $(this).width($originals.eq(index).outerWidth());
                    });
                    return $helper;
                },
                update: function() {
                    var order = [];
                    $tbody.find('tr').each(function(i) {
                        order.push($(this).data('component-id'));
                        $(this).find('.hl-position-number').text(i + 1);
                    });
                    $.post(ajaxurl, {
                        action: 'hl_reorder_components',
                        nonce: '<?php echo esc_js($reorder_nonce); ?>',
                        pathway_id: <?php echo (int) $pathway->pathway_id; ?>,
                        order: order
                    }).done(function(response) {
                        if (!response.success) {
                            alert(response.data && response.data.message
                                ? response.data.message
                                : '<?php echo esc_js(__('Failed to save component order.', 'hl-core')); ?>');
                            location.reload();
                        }
                    }).fail(function() {
                        alert('<?php echo esc_js(__('Failed to save component order.', 'hl-core')); ?>');
                        location.reload();
                    });
                }
            });
        });
        </script>
        <style>
            #hl-components-sortable .hl-drag-handle {
                cursor: move; text-align: center; color: #999; width: 32px;
            }
            #hl-components-sortable .hl-drag-handle:hover { color: #0073aa; }
            #hl-components-sortable .hl-drag-handle .dashicons { font-size: 18px; line-height: 1.4; }
            .hl-sortable-placeholder {
                height: 40px; background: #f0f6fc !important;
                outline: 2px dashed #0073aa; outline-offset: -2px;
            }
            #hl-components-sortable tbody tr.ui-sortable-helper {
                background: #fff; box-shadow: 0 2px 8px rgba(0,0,0,0.15);
            }
        </style>
        <?php

        // =====================================================================
        // Assigned Enrollments section
        // =====================================================================
        $pa_service  = new HL_Pathway_Assignment_Service();
        $assigned    = $pa_service->get_enrollments_for_pathway($pathway->pathway_id);
        $unassigned  = $pa_service->get_unassigned_enrollments($pathway->pathway_id, $pathway->cycle_id);

        echo '<h2 class="wp-heading-inline" style="margin-top:30px;">' . esc_html__('Assigned Enrollments', 'hl-core') . '</h2>';
        echo ' <span style="color:#666;">(' . count($assigned) . ')</span>';

        // Sync role defaults button.
        $sync_base = admin_url('admin.php?page=hl-pathways&action=sync_role_defaults&pathway_id=' . $pathway->pathway_id);
        if ($in_cycle) {
            $sync_base .= '&cycle_context=' . $context['cycle_id'];
        }
        $sync_url = wp_nonce_url($sync_base, 'hl_sync_defaults_' . $pathway->pathway_id);
        echo ' <a href="' . esc_url($sync_url) . '" class="button button-small" style="margin-left:10px;" title="' . esc_attr__('Auto-assign this pathway to enrollments whose roles match the target roles', 'hl-core') . '">' . esc_html__('Sync Role Defaults', 'hl-core') . '</a>';
        echo '<hr class="wp-header-end">';

        // Bulk assign form.
        if (!empty($unassigned)) {
            echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=hl-pathways&action=bulk_assign_pathway')) . '" style="margin-bottom:15px;">';
            wp_nonce_field('hl_bulk_assign_pathway', 'hl_bulk_assign_nonce');
            echo '<input type="hidden" name="pathway_id" value="' . esc_attr($pathway->pathway_id) . '" />';
            if ($in_cycle) {
                echo '<input type="hidden" name="_hl_cycle_context" value="' . esc_attr($context['cycle_id']) . '" />';
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
            echo '<p class="description">' . esc_html__('Hold Ctrl/Cmd to select multiple enrollments. These are active enrollments in this cycle not yet assigned to this pathway.', 'hl-core') . '</p>';
            echo '</form>';
        }

        // Single assign (quick-add one enrollment).
        if (!empty($unassigned)) {
            echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=hl-pathways&action=assign_pathway')) . '" style="display:flex; gap:8px; align-items:center; margin-bottom:15px;">';
            wp_nonce_field('hl_assign_pathway', 'hl_assign_nonce');
            echo '<input type="hidden" name="pathway_id" value="' . esc_attr($pathway->pathway_id) . '" />';
            if ($in_cycle) {
                echo '<input type="hidden" name="_hl_cycle_context" value="' . esc_attr($context['cycle_id']) . '" />';
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
                if ($in_cycle) {
                    $unassign_base .= '&cycle_context=' . $context['cycle_id'];
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
     * @param array       $context Optional cycle context. Keys: 'cycle_id', 'cycle_name'.
     */
    public function render_pathway_form($pathway = null, $context = array()) {
        $is_edit      = ($pathway !== null);
        $title        = $is_edit ? __('Edit Pathway', 'hl-core') : __('Add New Pathway', 'hl-core');
        $in_cycle    = !empty($context['cycle_id']);

        global $wpdb;
        $cycles = $wpdb->get_results(
            "SELECT cycle_id, cycle_name FROM {$wpdb->prefix}hl_cycle ORDER BY cycle_name ASC"
        );

        $current_roles = array();
        if ($is_edit && !empty($pathway->target_roles)) {
            $decoded = json_decode($pathway->target_roles, true);
            if (is_array($decoded)) {
                $current_roles = $decoded;
            }
        }

        // Suppress header/back-link when rendering inside cycle editor.
        if (!$in_cycle) {
            echo '<h1>' . esc_html($title) . '</h1>';
            echo '<a href="' . esc_url(admin_url('admin.php?page=hl-pathways')) . '">&larr; ' . esc_html__('Back to Pathways', 'hl-core') . '</a>';
        }

        // Show pathway error transient (e.g. routing_type conflict, DB constraint).
        $transient_error = get_transient('hl_pathway_error');
        if ($transient_error) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($transient_error) . '</p></div>';
            delete_transient('hl_pathway_error');
        }

        // "Start from Template" section for new pathways.
        if (!$is_edit) {
            $service   = new HL_Pathway_Service();
            $templates = $service->get_templates();
            if (!empty($templates)) {
                echo '<div style="margin:15px 0; padding:12px 16px; background:#f0f6fc; border:1px solid #c3d9ed; border-radius:4px;">';
                echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=hl-pathways&action=clone')) . '" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">';
                wp_nonce_field('hl_clone_pathway', 'hl_clone_nonce');
                if ($in_cycle) {
                    echo '<input type="hidden" name="_hl_cycle_context" value="' . esc_attr($context['cycle_id']) . '" />';
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
                if ($in_cycle) {
                    echo '<input type="hidden" name="target_cycle_id" value="' . esc_attr($context['cycle_id']) . '" />';
                } else {
                    echo '<select name="target_cycle_id" required>';
                    echo '<option value="">' . esc_html__('-- Into Cycle --', 'hl-core') . '</option>';
                    foreach ($cycles as $c) {
                        echo '<option value="' . esc_attr($c->cycle_id) . '">' . esc_html($c->cycle_name) . '</option>';
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
        if ($in_cycle) {
            echo '<input type="hidden" name="_hl_cycle_context" value="' . esc_attr($context['cycle_id']) . '" />';
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

        // Cycle
        $current_cycle = $in_cycle ? absint($context['cycle_id']) : ($is_edit ? $pathway->cycle_id : '');
        echo '<tr>';
        echo '<th scope="row"><label for="cycle_id">' . esc_html__('Cycle', 'hl-core') . '</label></th>';
        if ($in_cycle) {
            // Locked to cycle context — show read-only name + hidden input.
            echo '<td><strong>' . esc_html($context['cycle_name']) . '</strong>';
            echo '<input type="hidden" id="cycle_id" name="cycle_id" value="' . esc_attr($context['cycle_id']) . '" /></td>';
        } else {
            echo '<td><select id="cycle_id" name="cycle_id" required>';
            echo '<option value="">' . esc_html__('-- Select Cycle --', 'hl-core') . '</option>';
            if ($cycles) {
                foreach ($cycles as $cycle) {
                    echo '<option value="' . esc_attr($cycle->cycle_id) . '"' . selected($current_cycle, $cycle->cycle_id, false) . '>' . esc_html($cycle->cycle_name) . '</option>';
                }
            }
            echo '</select></td>';
        }
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

        // Auto-Assignment Rule (routing_type)
        $valid_routing_types = HL_Pathway_Routing_Service::get_valid_routing_types();
        $current_routing_type = $is_edit ? ($pathway->routing_type ?? '') : '';
        $current_cycle_for_form = $in_cycle ? absint($context['cycle_id']) : ($is_edit ? $pathway->cycle_id : 0);

        // Find which routing_types are already taken in this cycle by other pathways.
        $used_types = array();
        if ($current_cycle_for_form) {
            $used_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT routing_type, pathway_name FROM {$wpdb->prefix}hl_pathway
                 WHERE cycle_id = %d AND routing_type IS NOT NULL AND pathway_id != %d",
                $current_cycle_for_form,
                $is_edit ? $pathway->pathway_id : 0
            ), ARRAY_A);
            foreach ($used_rows as $ur) {
                $used_types[$ur['routing_type']] = $ur['pathway_name'];
            }
        }

        echo '<tr>';
        echo '<th scope="row"><label for="routing_type">' . esc_html__('Auto-Assignment Rule', 'hl-core') . '</label></th>';
        echo '<td><select id="routing_type" name="routing_type">';
        echo '<option value="">' . esc_html__('(None — manual assignment only)', 'hl-core') . '</option>';
        foreach ($valid_routing_types as $type_key => $type_label) {
            $selected = ($current_routing_type === $type_key) ? ' selected="selected"' : '';
            $is_used = isset($used_types[$type_key]);
            $disabled = $is_used ? ' disabled="disabled"' : '';
            $suffix = $is_used ? sprintf(' (already assigned to "%s")', esc_html($used_types[$type_key])) : '';
            echo '<option value="' . esc_attr($type_key) . '"' . $selected . $disabled . '>'
                 . esc_html($type_label) . esc_html($suffix)
                 . '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__('Controls which participants are automatically assigned this pathway during import. Each rule can only be used once per cycle.', 'hl-core') . '</p>';
        echo '</td>';
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
        // Built-in form types don't need an external resource — show "Built-in" instead of "Not configured".
        $builtin_types = array( 'self_reflection', 'reflective_practice_session', 'classroom_visit', 'coaching_session_attendance' );
        if ( empty( $external_ref ) && in_array( $component_type, $builtin_types, true ) ) {
            return '<span style="color:#666;">' . esc_html__( 'Built-in form', 'hl-core' ) . '</span>';
        }
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
     * @param array       $context Optional cycle context. Keys: 'cycle_id', 'cycle_name'.
     */
    public function render_component_form($pathway, $component = null, $context = array()) {
        $is_edit   = ($component !== null);
        $title     = $is_edit ? __('Edit Component', 'hl-core') : __('Add New Component', 'hl-core');
        $in_cycle = !empty($context['cycle_id']);

        // Parse existing external_ref for pre-populating dropdowns
        $ext_ref = array();
        if ($is_edit && !empty($component->external_ref)) {
            $decoded = json_decode($component->external_ref, true);
            if (is_array($decoded)) {
                $ext_ref = $decoded;
            }
        }

        if (!$in_cycle) {
            echo '<h1>' . esc_html($title) . '</h1>';
            echo '<a href="' . esc_url(admin_url('admin.php?page=hl-pathways&action=view&id=' . $pathway->pathway_id)) . '">&larr; ' . esc_html__('Back to Pathway: ', 'hl-core') . esc_html($pathway->pathway_name) . '</a>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=hl-pathways')) . '">';
        wp_nonce_field('hl_save_component', 'hl_component_nonce');
        echo '<input type="hidden" name="pathway_id" value="' . esc_attr($pathway->pathway_id) . '" />';
        if ($in_cycle) {
            echo '<input type="hidden" name="_hl_cycle_context" value="' . esc_attr($context['cycle_id']) . '" />';
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
            'child_assessment'             => __('Child Assessment', 'hl-core'),
            'coaching_session_attendance'   => __('Coaching Session Attendance', 'hl-core'),
            'reflective_practice_session'  => __('Reflective Practice Session', 'hl-core'),
            'classroom_visit'              => __('Classroom Visit', 'hl-core'),
            'self_reflection'              => __('Self-Reflection', 'hl-core'),
        );

        echo '<tr>';
        echo '<th scope="row"><label for="component_type">' . esc_html__('Component Type', 'hl-core') . '</label></th>';
        echo '<td><select id="component_type" name="component_type" required>';
        echo '<option value="">' . esc_html__('-- Select Type --', 'hl-core') . '</option>';
        foreach ($component_types as $type_value => $type_label) {
            echo '<option value="' . esc_attr($type_value) . '"' . selected($current_type, $type_value, false) . '>' . esc_html($type_label) . '</option>';
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

        // Complete By
        echo '<tr>';
        echo '<th scope="row"><label for="complete_by">' . esc_html__('Complete By', 'hl-core') . '</label></th>';
        echo '<td><input type="date" id="complete_by" name="complete_by" value="' . esc_attr($is_edit && !empty($component->complete_by) ? $component->complete_by : '') . '" />';
        echo '<p class="description">' . esc_html__('Suggested deadline for completing this component. Leave blank for no deadline.', 'hl-core') . '</p></td>';
        echo '</tr>';

        // Ordering Hint — managed via drag-and-drop on the pathway detail page.
        // For new components, auto-assign next position at end of list.
        if ($is_edit) {
            $ordering_value = $component->ordering_hint;
        } else {
            global $wpdb;
            $max_hint = $wpdb->get_var($wpdb->prepare(
                "SELECT MAX(ordering_hint) FROM {$wpdb->prefix}hl_component WHERE pathway_id = %d",
                $pathway->pathway_id
            ));
            $ordering_value = ($max_hint !== null) ? intval($max_hint) + 10 : 0;
        }
        echo '<input type="hidden" name="ordering_hint" value="' . esc_attr($ordering_value) . '" />';

        // =====================================================================
        // Conditional fields based on component type
        // =====================================================================

        // --- Phase dropdown (for teacher_self_assessment only) ---
        $current_phase = isset($ext_ref['phase']) ? $ext_ref['phase'] : 'pre';

        echo '<tr class="hl-component-field hl-field-phase" style="display:none;">';
        echo '<th scope="row"><label for="assessment_phase">' . esc_html__('Assessment Phase', 'hl-core') . '</label></th>';
        echo '<td><select id="assessment_phase" name="assessment_phase">';
        echo '<option value="pre"' . selected($current_phase, 'pre', false) . '>' . esc_html__('Pre-Assessment', 'hl-core') . '</option>';
        echo '<option value="post"' . selected($current_phase, 'post', false) . '>' . esc_html__('Post-Assessment', 'hl-core') . '</option>';
        echo '</select></td>';
        echo '</tr>';

        // --- Teacher Instrument dropdown (for teacher_self_assessment) ---
        global $wpdb;
        $teacher_instruments = $wpdb->get_results(
            "SELECT instrument_id, instrument_name, instrument_key, instrument_version
             FROM {$wpdb->prefix}hl_teacher_assessment_instrument
             WHERE status = 'active'
             ORDER BY instrument_name ASC"
        );
        $current_teacher_instrument_id = isset($ext_ref['teacher_instrument_id']) ? absint($ext_ref['teacher_instrument_id']) : 0;

        echo '<tr class="hl-component-field hl-field-teacher-instrument" style="display:none;">';
        echo '<th scope="row"><label for="teacher_instrument_id">' . esc_html__('Teacher Assessment Instrument', 'hl-core') . '</label></th>';
        echo '<td>';
        if (empty($teacher_instruments)) {
            echo '<p class="description">' . esc_html__('No teacher assessment instruments found. Create one in the Instruments admin page first.', 'hl-core') . '</p>';
            echo '<input type="hidden" name="teacher_instrument_id" value="0" />';
        } else {
            echo '<select id="teacher_instrument_id" name="teacher_instrument_id">';
            echo '<option value="0">' . esc_html__('-- Select Instrument --', 'hl-core') . '</option>';
            foreach ($teacher_instruments as $inst) {
                $label = sprintf('%s (v%s)', $inst->instrument_name, $inst->instrument_version);
                echo '<option value="' . esc_attr($inst->instrument_id) . '"' . selected($current_teacher_instrument_id, $inst->instrument_id, false) . '>'
                    . esc_html($label) . '</option>';
            }
            echo '</select>';
        }
        echo '</td>';
        echo '</tr>';

        // --- Instrument dropdown (for child_assessment) ---
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

        // --- Eligibility Rules ---
        $current_requires_classroom = $is_edit && !empty($component->requires_classroom) ? 1 : 0;
        $current_eligible_roles = array();
        if ($is_edit && !empty($component->eligible_roles)) {
            $decoded_roles = json_decode($component->eligible_roles, true);
            if (is_array($decoded_roles)) {
                $current_eligible_roles = $decoded_roles;
            }
        }
        $all_roles = array(
            'teacher'         => __('Teacher', 'hl-core'),
            'mentor'          => __('Mentor', 'hl-core'),
            'school_leader'   => __('School Leader', 'hl-core'),
            'district_leader' => __('District Leader', 'hl-core'),
        );

        echo '<tr>';
        echo '<th scope="row">' . esc_html__('Eligibility Rules', 'hl-core') . '</th>';
        echo '<td>';

        echo '<label><input type="checkbox" name="requires_classroom" value="1"' . checked($current_requires_classroom, 1, false) . ' /> ';
        echo esc_html__('Requires classroom (skip for users without a teaching assignment)', 'hl-core') . '</label>';
        echo '<br><br>';

        echo '<p class="description" style="margin-bottom:6px;">' . esc_html__('Eligible roles (leave all unchecked for "all roles"):', 'hl-core') . '</p>';
        foreach ($all_roles as $role_val => $role_label) {
            $chk = in_array($role_val, $current_eligible_roles, true) ? ' checked' : '';
            echo '<label style="margin-right:16px;"><input type="checkbox" name="eligible_roles[]" value="' . esc_attr($role_val) . '"' . $chk . ' /> ';
            echo esc_html($role_label) . '</label>';
        }
        echo '<p class="description">' . esc_html__('If no roles are checked, all enrollment roles are eligible. Both conditions must pass.', 'hl-core') . '</p>';

        echo '</td>';
        echo '</tr>';

        // --- Scheduling Window (coaching_session_attendance only) ---
        $sw_start = ($is_edit && !empty($component->scheduling_window_start)) ? $component->scheduling_window_start : '';
        $sw_end   = ($is_edit && !empty($component->scheduling_window_end)) ? $component->scheduling_window_end : '';

        echo '<tr class="hl-component-field hl-field-scheduling-window" style="display:none;">';
        echo '<th scope="row">' . esc_html__('Scheduling Window', 'hl-core') . '</th>';
        echo '<td>';
        echo '<label for="scheduling_window_start" style="margin-right:8px;">' . esc_html__('Start:', 'hl-core') . '</label>';
        echo '<input type="date" id="scheduling_window_start" name="scheduling_window_start" value="' . esc_attr($sw_start) . '" style="margin-right:16px;" />';
        echo '<label for="scheduling_window_end" style="margin-right:8px;">' . esc_html__('End:', 'hl-core') . '</label>';
        echo '<input type="date" id="scheduling_window_end" name="scheduling_window_end" value="' . esc_attr($sw_end) . '" />';
        echo '<p class="description">' . esc_html__('Functional: controls which dates appear in the coach calendar. Both are optional.', 'hl-core') . '</p>';
        echo '</td>';
        echo '</tr>';

        // --- Display Window (coaching_session_attendance only) ---
        $dw_start = ($is_edit && !empty($component->display_window_start)) ? $component->display_window_start : '';
        $dw_end   = ($is_edit && !empty($component->display_window_end)) ? $component->display_window_end : '';

        echo '<tr class="hl-component-field hl-field-scheduling-window" style="display:none;">';
        echo '<th scope="row">' . esc_html__('Display Window', 'hl-core') . '</th>';
        echo '<td>';
        echo '<label for="display_window_start" style="margin-right:8px;">' . esc_html__('Start:', 'hl-core') . '</label>';
        echo '<input type="date" id="display_window_start" name="display_window_start" value="' . esc_attr($dw_start) . '" style="margin-right:16px;" />';
        echo '<label for="display_window_end" style="margin-right:8px;">' . esc_html__('End:', 'hl-core') . '</label>';
        echo '<input type="date" id="display_window_end" name="display_window_end" value="' . esc_attr($dw_end) . '" />';
        echo '<p class="description">' . esc_html__('Cosmetic: shown to users as the recommended date range. No functional impact.', 'hl-core') . '</p>';
        echo '</td>';
        echo '</tr>';

        // --- Course Catalog selector (for learndash_course) ---
        $catalog_repo = new HL_Course_Catalog_Repository();
        $catalog_entries = $catalog_repo->get_active_for_dropdown();
        $current_catalog_id = isset($component->catalog_id) ? absint($component->catalog_id) : 0;

        echo '<tr class="hl-component-field hl-field-ld" style="display:none;">';
        echo '<th scope="row"><label for="catalog_id">' . esc_html__('Course', 'hl-core') . '</label></th>';
        echo '<td>';
        echo '<select id="catalog_id" name="catalog_id">';
        echo '<option value="">' . esc_html__('-- Select Course --', 'hl-core') . '</option>';
        foreach ($catalog_entries as $entry) {
            echo '<option value="' . esc_attr($entry->catalog_id) . '"' . selected($current_catalog_id, $entry->catalog_id, false) . '>'
                . esc_html($entry->title . ' ' . $entry->get_language_badges())
                . '</option>';
        }
        echo '</select>';
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
                'teacher_self_assessment': ['hl-field-phase', 'hl-field-teacher-instrument'],
                'child_assessment':        ['hl-field-instrument'],
                'learndash_course':        ['hl-field-ld'],
                'coaching_session_attendance':  ['hl-field-scheduling-window'],
                'reflective_practice_session':  [],
                'classroom_visit':              [],
                'self_reflection':              []
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
     * AJAX handler: reorder pathway components via drag-and-drop.
     *
     * Expects POST with 'order' (array of component IDs in new order) and 'nonce'.
     */
    public function ajax_reorder_components() {
        check_ajax_referer('hl_reorder_components', 'nonce');

        if (!current_user_can('manage_hl_core')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'hl-core')));
        }

        $pathway_id = isset($_POST['pathway_id']) ? absint($_POST['pathway_id']) : 0;
        $order      = isset($_POST['order']) ? array_map('absint', $_POST['order']) : array();

        if ($pathway_id < 1 || empty($order)) {
            wp_send_json_error(array('message' => __('Missing data.', 'hl-core')));
        }

        global $wpdb;

        // Verify all submitted component IDs belong to this pathway.
        $ids_csv = implode(',', array_map('intval', $order));
        $count   = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}hl_component WHERE pathway_id = %d AND component_id IN ({$ids_csv})",
            $pathway_id
        ));
        if ($count !== count($order)) {
            wp_send_json_error(array('message' => __('Component/pathway mismatch.', 'hl-core')));
        }

        $errors = 0;
        foreach ($order as $position => $component_id) {
            if ($component_id < 1) {
                continue;
            }
            $result = $wpdb->update(
                $wpdb->prefix . 'hl_component',
                array('ordering_hint' => $position * 10),
                array('component_id' => $component_id),
                array('%d'),
                array('%d')
            );
            if ($result === false) {
                $errors++;
            }
        }

        if ($errors > 0) {
            wp_send_json_error(array('message' => sprintf(__('%d component(s) failed to update.', 'hl-core'), $errors)));
        }

        wp_send_json_success(array('message' => __('Order updated.', 'hl-core')));
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
