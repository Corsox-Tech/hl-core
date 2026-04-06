<?php if (!defined('ABSPATH')) exit;

/**
 * Admin Enrollments Page
 *
 * Full CRUD admin page for managing Enrollments.
 *
 * @package HL_Core
 */
class HL_Admin_Enrollments {

    /**
     * Singleton instance
     *
     * @var HL_Admin_Enrollments|null
     */
    private static $instance = null;

    /**
     * Get singleton instance
     *
     * @return HL_Admin_Enrollments
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
    private function __construct() {}

    /**
     * Register AJAX hooks. Called from plugin bootstrap so the endpoint
     * is available during wp_ajax requests (before any page-specific init).
     */
    public static function register_ajax_hooks() {
        add_action('wp_ajax_hl_search_users', array(self::instance(), 'ajax_search_users'));
        add_action('wp_ajax_hl_suggest_pathway', array(self::instance(), 'ajax_suggest_pathway'));
    }

    /**
     * AJAX user search — returns up to 20 users matching a query string.
     */
    public function ajax_search_users() {
        check_ajax_referer('hl_enrollment_user_search', '_nonce');
        if (!current_user_can('manage_hl_core')) {
            wp_send_json_error();
        }

        $q = isset($_GET['q']) ? sanitize_text_field($_GET['q']) : '';
        if (strlen($q) < 2) {
            wp_send_json_success(array());
        }

        $users = get_users(array(
            'search'         => '*' . $q . '*',
            'search_columns' => array('display_name', 'user_email', 'user_login'),
            'number'         => 20,
            'orderby'        => 'display_name',
            'order'          => 'ASC',
        ));

        $results = array();
        foreach ($users as $u) {
            $results[] = array(
                'id'    => $u->ID,
                'text'  => $u->display_name . ' (' . $u->user_email . ')',
            );
        }
        wp_send_json_success($results);
    }

    /**
     * AJAX: Suggest pathway based on routing service.
     */
    public function ajax_suggest_pathway() {
        check_ajax_referer('hl_suggest_pathway', 'nonce');

        if (!current_user_can('manage_hl_core')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'hl-core')));
        }

        $user_id  = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;
        $role     = isset($_POST['role']) ? sanitize_text_field($_POST['role']) : '';
        $cycle_id = isset($_POST['cycle_id']) ? absint($_POST['cycle_id']) : 0;

        if (!$role || !$cycle_id) {
            wp_send_json_error(array('message' => __('Missing required fields.', 'hl-core')));
        }

        $pathway_id = HL_Pathway_Routing_Service::resolve_pathway(
            $user_id ?: null,
            $role,
            $cycle_id
        );

        if ($pathway_id) {
            global $wpdb;
            $pathway_name = $wpdb->get_var($wpdb->prepare(
                "SELECT pathway_name FROM {$wpdb->prefix}hl_pathway WHERE pathway_id = %d",
                $pathway_id
            ));
            $source = $user_id ? 'routed' : 'default';
            wp_send_json_success(array(
                'pathway_id'   => $pathway_id,
                'pathway_name' => $pathway_name,
                'source'       => $source,
            ));
        } else {
            wp_send_json_success(array(
                'pathway_id'   => 0,
                'pathway_name' => '',
                'source'       => 'none',
            ));
        }
    }

    /**
     * Handle POST saves and GET deletes before any HTML output.
     */
    public function handle_early_actions() {
        $this->handle_actions();
        $this->handle_component_actions();

        if (isset($_GET['action']) && $_GET['action'] === 'delete') {
            $this->handle_delete();
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
                $enrollment_id = isset($_GET['id']) ? absint($_GET['id']) : 0;
                $enrollment    = $this->get_enrollment($enrollment_id);
                if ($enrollment) {
                    $this->render_form($enrollment);
                } else {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Enrollment not found.', 'hl-core') . '</p></div>';
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
     * Get a single enrollment by ID
     *
     * @param int $enrollment_id
     * @return object|null
     */
    public function get_enrollment($enrollment_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_enrollment WHERE enrollment_id = %d",
            $enrollment_id
        ));
    }

    /**
     * Handle form submissions
     */
    private function handle_actions() {
        if (!isset($_POST['hl_enrollment_nonce'])) {
            return;
        }

        if (!wp_verify_nonce($_POST['hl_enrollment_nonce'], 'hl_save_enrollment')) {
            wp_die(__('Security check failed.', 'hl-core'));
        }

        if (!current_user_can('manage_hl_core')) {
            wp_die(__('You do not have permission to perform this action.', 'hl-core'));
        }

        global $wpdb;

        $enrollment_id = isset($_POST['enrollment_id']) ? absint($_POST['enrollment_id']) : 0;

        // Process roles checkboxes into JSON array (normalize to lowercase snake_case).
        $roles = array();
        if (!empty($_POST['roles']) && is_array($_POST['roles'])) {
            foreach ($_POST['roles'] as $role) {
                $roles[] = strtolower(str_replace(' ', '_', sanitize_text_field($role)));
            }
        }

        $data = array(
            'cycle_id'    => absint($_POST['cycle_id']),
            'user_id'     => absint($_POST['user_id']),
            'roles'       => wp_json_encode($roles),
            'school_id'   => !empty($_POST['school_id']) ? absint($_POST['school_id']) : null,
            'district_id' => !empty($_POST['district_id']) ? absint($_POST['district_id']) : null,
            'status'              => sanitize_text_field($_POST['status']),
            'language_preference' => in_array($_POST['language_preference'] ?? '', array('en', 'es', 'pt'), true)
                                     ? $_POST['language_preference']
                                     : 'en',
        );

        $cycle_context = isset($_POST['_hl_cycle_context']) ? absint($_POST['_hl_cycle_context']) : 0;

        if ($enrollment_id > 0) {
            $wpdb->update(
                $wpdb->prefix . 'hl_enrollment',
                $data,
                array('enrollment_id' => $enrollment_id)
            );
            if ($cycle_context) {
                $redirect = admin_url('admin.php?page=hl-cycles&action=edit&id=' . $cycle_context . '&tab=enrollments&message=enrollment_updated');
            } else {
                $redirect = admin_url('admin.php?page=hl-enrollments&message=updated');
            }
        } else {
            $data['enrollment_uuid'] = HL_DB_Utils::generate_uuid();
            $data['enrolled_at']     = current_time('mysql');
            $wpdb->insert($wpdb->prefix . 'hl_enrollment', $data);
            $enrollment_id = $wpdb->insert_id;
            if ($cycle_context) {
                $redirect = admin_url('admin.php?page=hl-cycles&action=edit&id=' . $cycle_context . '&tab=enrollments&message=enrollment_created');
            } else {
                $redirect = admin_url('admin.php?page=hl-enrollments&message=created');
            }
        }

        // Handle pathway assignment.
        $new_pathway_id = !empty($_POST['pathway_id']) ? absint($_POST['pathway_id']) : 0;
        $old_pathway_id = !empty($_POST['_current_pathway_id']) ? absint($_POST['_current_pathway_id']) : 0;
        if ($enrollment_id && $new_pathway_id !== $old_pathway_id) {
            $pa_service = new HL_Pathway_Assignment_Service();
            if ($old_pathway_id) {
                $pa_service->unassign_pathway($enrollment_id, $old_pathway_id);
            }
            if ($new_pathway_id) {
                $pa_service->assign_pathway($enrollment_id, $new_pathway_id, 'explicit');
            }
        }

        // Auto-route pathway if admin left it blank and this is a new enrollment.
        if ($enrollment_id && !$new_pathway_id && !$old_pathway_id) {
            $user_id_for_routing = absint($_POST['user_id']);
            $first_role = !empty($roles) ? $roles[0] : '';
            if ($first_role) {
                $routed_id = HL_Pathway_Routing_Service::resolve_pathway(
                    $user_id_for_routing,
                    $first_role,
                    absint($_POST['cycle_id'])
                );
                if ($routed_id) {
                    $pa_service = isset($pa_service) ? $pa_service : new HL_Pathway_Assignment_Service();
                    $pa_service->assign_pathway($enrollment_id, $routed_id, 'role_default');
                }
            }
        }

        // Handle team membership.
        $new_team_id = !empty($_POST['team_id']) ? absint($_POST['team_id']) : 0;
        $old_team_id = !empty($_POST['_current_team_id']) ? absint($_POST['_current_team_id']) : 0;
        if ($enrollment_id && $new_team_id !== $old_team_id) {
            if ($old_team_id) {
                $wpdb->delete($wpdb->prefix . 'hl_team_membership', array(
                    'enrollment_id' => $enrollment_id,
                    'team_id'       => $old_team_id,
                ));
            }
            if ($new_team_id) {
                $wpdb->insert($wpdb->prefix . 'hl_team_membership', array(
                    'enrollment_id' => $enrollment_id,
                    'team_id'       => $new_team_id,
                ));
            }
        }

        wp_redirect($redirect);
        exit;
    }

    /**
     * Handle delete action
     */
    private function handle_delete() {
        $enrollment_id = isset($_GET['id']) ? absint($_GET['id']) : 0;

        if (!$enrollment_id) {
            return;
        }

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'hl_delete_enrollment_' . $enrollment_id)) {
            wp_die(__('Security check failed.', 'hl-core'));
        }

        if (!current_user_can('manage_hl_core')) {
            wp_die(__('You do not have permission to perform this action.', 'hl-core'));
        }

        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'hl_enrollment', array('enrollment_id' => $enrollment_id));

        $cycle_context = isset($_GET['cycle_context']) ? absint($_GET['cycle_context']) : 0;
        if ($cycle_context) {
            wp_redirect(admin_url('admin.php?page=hl-cycles&action=edit&id=' . $cycle_context . '&tab=enrollments&message=enrollment_deleted'));
        } else {
            wp_redirect(admin_url('admin.php?page=hl-enrollments&message=deleted'));
        }
        exit;
    }

    /**
     * Handle component progress override actions (reset / mark complete).
     *
     * Uses its own nonce (hl_component_progress_nonce), independent of the
     * enrollment form's hl_enrollment_nonce. Called from handle_early_actions().
     */
    private function handle_component_actions() {
        if (!isset($_POST['hl_component_action'])) {
            return;
        }

        if (!isset($_POST['hl_component_progress_nonce']) || !wp_verify_nonce($_POST['hl_component_progress_nonce'], 'hl_component_progress')) {
            wp_die(__('Security check failed.', 'hl-core'));
        }

        if (!current_user_can('manage_hl_core')) {
            wp_die(__('You do not have permission to perform this action.', 'hl-core'));
        }

        global $wpdb;

        $action        = sanitize_text_field($_POST['hl_component_action']);
        $enrollment_id = absint($_POST['hl_component_enrollment_id'] ?? 0);
        $component_id  = absint($_POST['hl_component_id'] ?? 0);

        if (!$enrollment_id || !$component_id) {
            return;
        }

        // Validate enrollment exists.
        $enrollment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_enrollment WHERE enrollment_id = %d",
            $enrollment_id
        ));
        if (!$enrollment) {
            return;
        }

        // Validate component belongs to enrollment's current pathway (via pathway_assignment join).
        $valid_component = $wpdb->get_row($wpdb->prepare(
            "SELECT c.component_id, c.title, c.component_type, c.catalog_id, c.external_ref
             FROM {$wpdb->prefix}hl_component c
             JOIN {$wpdb->prefix}hl_pathway_assignment pa ON pa.pathway_id = c.pathway_id
             WHERE c.component_id = %d AND pa.enrollment_id = %d",
            $component_id, $enrollment_id
        ));
        if (!$valid_component) {
            return;
        }

        $now           = current_time('mysql');
        $cycle_context = isset($_POST['_hl_cycle_context']) ? absint($_POST['_hl_cycle_context']) : 0;
        $ld_warning    = false;

        if ($action === 'reset_component') {
            // 1. Check for and delete exempt override.
            $exempt_override = $wpdb->get_row($wpdb->prepare(
                "SELECT override_id FROM {$wpdb->prefix}hl_component_override
                 WHERE enrollment_id = %d AND component_id = %d AND override_type = 'exempt'",
                $enrollment_id, $component_id
            ));
            if ($exempt_override) {
                $wpdb->delete($wpdb->prefix . 'hl_component_override', array('override_id' => $exempt_override->override_id));
                HL_Audit_Service::log('component_override.removed', array(
                    'entity_type' => 'component_override',
                    'entity_id'   => $exempt_override->override_id,
                    'after_data'  => array(
                        'admin_user_id' => get_current_user_id(),
                        'enrollment_id' => $enrollment_id,
                        'component_id'  => $component_id,
                        'override_type' => 'exempt',
                        'reason'        => 'Removed during component progress reset',
                    ),
                ));
            }

            // 2. Upsert component_state to not_started.
            $existing_state = $wpdb->get_row($wpdb->prepare(
                "SELECT state_id FROM {$wpdb->prefix}hl_component_state
                 WHERE enrollment_id = %d AND component_id = %d",
                $enrollment_id, $component_id
            ));

            if ($existing_state) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$wpdb->prefix}hl_component_state
                     SET completion_status = 'not_started', completion_percent = 0,
                         completed_at = NULL, last_computed_at = %s
                     WHERE state_id = %d",
                    $now, $existing_state->state_id
                ));
            } else {
                $wpdb->insert($wpdb->prefix . 'hl_component_state', array(
                    'enrollment_id'      => $enrollment_id,
                    'component_id'       => $component_id,
                    'completion_status'  => 'not_started',
                    'completion_percent' => 0,
                    'last_computed_at'   => $now,
                ));
            }

            // 3. LD sync: reset ALL language variants.
            if ($valid_component->component_type === 'learndash_course' && !empty($valid_component->catalog_id)) {
                $repo = new HL_Course_Catalog_Repository();
                $catalog_entry = $repo->get_by_id($valid_component->catalog_id);
                if ($catalog_entry) {
                    $ld = HL_LearnDash_Integration::instance();
                    $lang_ids = $catalog_entry->get_language_course_ids();
                    if (empty($lang_ids)) {
                        // Catalog entry exists but has no LD course IDs — treat as sync failure.
                        $ld_warning = true;
                    } else {
                        foreach ($lang_ids as $lang => $ld_course_id) {
                            if (!$ld->reset_course_progress($enrollment->user_id, $ld_course_id)) {
                                $ld_warning = true;
                            }
                        }
                    }
                }
            } elseif ($valid_component->component_type === 'learndash_course' && empty($valid_component->catalog_id)) {
                // Fallback: legacy component with external_ref only.
                $comp_obj = new HL_Component(array(
                    'catalog_id'     => null,
                    'component_type' => 'learndash_course',
                    'external_ref'   => $valid_component->external_ref,
                ));
                $ld_course_id = HL_Course_Catalog::resolve_ld_course_id($comp_obj, $enrollment);
                if ($ld_course_id) {
                    $ld = HL_LearnDash_Integration::instance();
                    if (!$ld->reset_course_progress($enrollment->user_id, $ld_course_id)) {
                        $ld_warning = true;
                    }
                }
            }

            // 4. Recompute rollups.
            do_action('hl_core_recompute_rollups', $enrollment_id);

            // 5. Audit log.
            HL_Audit_Service::log('component_progress.reset', array(
                'entity_type' => 'component',
                'entity_id'   => $component_id,
                'after_data'  => array(
                    'admin_user_id'   => get_current_user_id(),
                    'enrollment_id'   => $enrollment_id,
                    'component_id'    => $component_id,
                    'component_title' => $valid_component->title,
                ),
            ));

            // 6. Redirect.
            $msg = $ld_warning ? 'component_reset_ld_warning' : 'component_reset';
            $this->redirect_to_enrollment_edit($enrollment_id, $cycle_context, $msg);

        } elseif ($action === 'complete_component') {
            // 1. Upsert component_state to complete.
            $existing_state = $wpdb->get_row($wpdb->prepare(
                "SELECT state_id FROM {$wpdb->prefix}hl_component_state
                 WHERE enrollment_id = %d AND component_id = %d",
                $enrollment_id, $component_id
            ));

            $state_data = array(
                'completion_status'  => 'complete',
                'completion_percent' => 100,
                'completed_at'       => $now,
                'last_computed_at'   => $now,
            );

            if ($existing_state) {
                $wpdb->update(
                    $wpdb->prefix . 'hl_component_state',
                    $state_data,
                    array('state_id' => $existing_state->state_id)
                );
            } else {
                $state_data['enrollment_id'] = $enrollment_id;
                $state_data['component_id']  = $component_id;
                $wpdb->insert($wpdb->prefix . 'hl_component_state', $state_data);
            }

            // 2. LD sync: mark preferred language variant complete.
            if ($valid_component->component_type === 'learndash_course') {
                $comp_obj = new HL_Component(array(
                    'component_id'   => $valid_component->component_id,
                    'catalog_id'     => $valid_component->catalog_id,
                    'component_type' => 'learndash_course',
                    'external_ref'   => $valid_component->external_ref,
                ));
                $ld_course_id = HL_Course_Catalog::resolve_ld_course_id($comp_obj, $enrollment);
                if ($ld_course_id) {
                    $ld = HL_LearnDash_Integration::instance();
                    if (!$ld->mark_course_complete($enrollment->user_id, $ld_course_id)) {
                        $ld_warning = true;
                    }
                }
            }

            // 3. Recompute rollups.
            do_action('hl_core_recompute_rollups', $enrollment_id);

            // 4. Audit log.
            HL_Audit_Service::log('component_progress.manual_complete', array(
                'entity_type' => 'component',
                'entity_id'   => $component_id,
                'after_data'  => array(
                    'admin_user_id'   => get_current_user_id(),
                    'enrollment_id'   => $enrollment_id,
                    'component_id'    => $component_id,
                    'component_title' => $valid_component->title,
                ),
            ));

            // 5. Redirect.
            $msg = $ld_warning ? 'component_complete_ld_warning' : 'component_complete';
            $this->redirect_to_enrollment_edit($enrollment_id, $cycle_context, $msg);
        }
    }

    /**
     * Redirect back to enrollment edit page with a message parameter.
     *
     * @param int    $enrollment_id
     * @param int    $cycle_context  Cycle ID if embedded in Cycle Editor, 0 otherwise.
     * @param string $message        Message key for admin notice.
     */
    private function redirect_to_enrollment_edit($enrollment_id, $cycle_context, $message) {
        if ($cycle_context) {
            $redirect = admin_url('admin.php?page=hl-cycles&action=edit&id=' . $cycle_context
                . '&tab=enrollments&sub=edit&enrollment_id=' . $enrollment_id
                . '&message=' . $message);
        } else {
            $redirect = admin_url('admin.php?page=hl-enrollments&action=edit&id=' . $enrollment_id
                . '&message=' . $message);
        }
        wp_redirect($redirect);
        exit;
    }

    /**
     * Render the enrollments list table
     */
    private function render_list() {
        global $wpdb;

        // Read filters from GET.
        $f_partnership = isset($_GET['partnership_id']) ? absint($_GET['partnership_id']) : 0;
        $f_cycle       = isset($_GET['cycle_id']) ? absint($_GET['cycle_id']) : 0;
        $f_role        = isset($_GET['role']) ? sanitize_text_field($_GET['role']) : '';
        $f_school      = isset($_GET['school_id']) ? absint($_GET['school_id']) : 0;
        $f_search      = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $f_suspended   = isset( $_GET['suspended'] ) ? sanitize_text_field( $_GET['suspended'] ) : '';

        // Build WHERE clauses.
        $wheres = array();
        $params = array();

        if ($f_cycle) {
            $wheres[] = 'e.cycle_id = %d';
            $params[] = $f_cycle;
        } elseif ($f_partnership) {
            $wheres[] = 't.partnership_id = %d';
            $params[] = $f_partnership;
        }
        if ($f_role) {
            $wheres[] = 'e.roles LIKE %s';
            $params[] = '%' . $wpdb->esc_like($f_role) . '%';
        }
        if ($f_school) {
            $wheres[] = 'e.school_id = %d';
            $params[] = $f_school;
        }
        if ($f_search) {
            $like = '%' . $wpdb->esc_like($f_search) . '%';
            $wheres[] = '(u.display_name LIKE %s OR u.user_email LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }

        // Suspension filter.
        $suspend_extra_sql = '';
        if ( $f_suspended === 'only' && HL_BuddyBoss_Integration::bp_suspend_table_exists() ) {
            $wheres[] = "EXISTS (SELECT 1 FROM {$wpdb->prefix}bp_suspend WHERE item_type = 'user' AND item_id = e.user_id AND user_suspended = 1)";
        } elseif ( $f_suspended === 'exclude' ) {
            $suspend_extra_sql = HL_BuddyBoss_Integration::get_suspend_not_exists_sql( 'e.user_id' );
        }

        $where_sql = !empty($wheres) ? ' WHERE ' . implode(' AND ', $wheres) : '';
        $where_sql .= $suspend_extra_sql;

        $sql = "SELECT e.*, t.cycle_name, t.partnership_id, u.display_name, u.user_email
                FROM {$wpdb->prefix}hl_enrollment e
                LEFT JOIN {$wpdb->prefix}hl_cycle t ON e.cycle_id = t.cycle_id
                LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
                {$where_sql}
                ORDER BY e.enrolled_at DESC
                LIMIT 500";

        $enrollments = !empty($params)
            ? $wpdb->get_results($wpdb->prepare($sql, $params))
            : $wpdb->get_results($sql);
        if (!$enrollments) $enrollments = array();

        // Filter data for dropdowns.
        $partnerships = $wpdb->get_results(
            "SELECT partnership_id, partnership_name FROM {$wpdb->prefix}hl_partnership ORDER BY partnership_name ASC"
        );
        $cycles = $wpdb->get_results(
            "SELECT c.cycle_id, c.cycle_name, c.partnership_id FROM {$wpdb->prefix}hl_cycle c ORDER BY c.cycle_name ASC"
        );
        $school_rows = $wpdb->get_results(
            "SELECT orgunit_id, name FROM {$wpdb->prefix}hl_orgunit WHERE orgunit_type = 'school' ORDER BY name ASC"
        );
        $schools = array();
        if ($school_rows) {
            foreach ($school_rows as $c) {
                $schools[$c->orgunit_id] = $c->name;
            }
        }

        // All distinct roles for dropdown.
        $all_roles = array('teacher', 'mentor', 'school_leader', 'district_leader');

        // Show success messages
        if (isset($_GET['message'])) {
            $msg = sanitize_text_field($_GET['message']);
            if ($msg === 'created') {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Enrollment created successfully.', 'hl-core') . '</p></div>';
            } elseif ($msg === 'updated') {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Enrollment updated successfully.', 'hl-core') . '</p></div>';
            } elseif ($msg === 'deleted') {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Enrollment deleted successfully.', 'hl-core') . '</p></div>';
            }
        }

        // Cycle breadcrumb.
        if ($f_cycle) {
            $cycle_name = $wpdb->get_var($wpdb->prepare(
                "SELECT cycle_name FROM {$wpdb->prefix}hl_cycle WHERE cycle_id = %d", $f_cycle
            ));
            if ($cycle_name) {
                echo '<p style="margin:0 0 5px;"><a href="' . esc_url(admin_url('admin.php?page=hl-cycles&action=edit&id=' . $f_cycle . '&tab=enrollments')) . '">&larr; ' . sprintf(esc_html__('Cycle: %s', 'hl-core'), esc_html($cycle_name)) . '</a></p>';
            }
        }

        echo '<h1 class="wp-heading-inline">' . esc_html__('Enrollments', 'hl-core') . '</h1>';
        echo ' <a href="' . esc_url(admin_url('admin.php?page=hl-enrollments&action=new')) . '" class="page-title-action">' . esc_html__('Add New', 'hl-core') . '</a>';
        echo '<hr class="wp-header-end">';

        // Build cycles JS map for partnership→cycle dependency.
        $cycles_by_partnership = array();
        foreach ($cycles as $c) {
            $pid = $c->partnership_id ? (int) $c->partnership_id : 0;
            $cycles_by_partnership[$pid][] = array('id' => (int) $c->cycle_id, 'name' => $c->cycle_name);
        }

        // Filter bar.
        echo '<form method="get" style="margin-bottom:15px;">';
        echo '<input type="hidden" name="page" value="hl-enrollments" />';
        echo '<div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">';

        // Partnership.
        echo '<div><label style="display:block;font-size:11px;font-weight:600;color:#646970;margin-bottom:2px;">' . esc_html__('Partnership', 'hl-core') . '</label>';
        echo '<select name="partnership_id" id="hl-enr-f-partnership" style="min-width:160px;">';
        echo '<option value="">' . esc_html__('All Partnerships', 'hl-core') . '</option>';
        if ($partnerships) {
            foreach ($partnerships as $p) {
                echo '<option value="' . esc_attr($p->partnership_id) . '"' . selected($f_partnership, $p->partnership_id, false) . '>' . esc_html($p->partnership_name) . '</option>';
            }
        }
        echo '</select></div>';

        // Cycle.
        echo '<div><label style="display:block;font-size:11px;font-weight:600;color:#646970;margin-bottom:2px;">' . esc_html__('Cycle', 'hl-core') . '</label>';
        echo '<select name="cycle_id" id="hl-enr-f-cycle" style="min-width:180px;">';
        echo '<option value="">' . esc_html__('All Cycles', 'hl-core') . '</option>';
        if ($cycles) {
            foreach ($cycles as $cycle) {
                echo '<option value="' . esc_attr($cycle->cycle_id) . '"'
                    . ' data-partnership="' . esc_attr($cycle->partnership_id ?: 0) . '"'
                    . selected($f_cycle, $cycle->cycle_id, false) . '>'
                    . esc_html($cycle->cycle_name) . '</option>';
            }
        }
        echo '</select></div>';

        // Role.
        echo '<div><label style="display:block;font-size:11px;font-weight:600;color:#646970;margin-bottom:2px;">' . esc_html__('Role', 'hl-core') . '</label>';
        echo '<select name="role" style="min-width:130px;">';
        echo '<option value="">' . esc_html__('All Roles', 'hl-core') . '</option>';
        foreach ($all_roles as $r) {
            $label = ucwords(str_replace('_', ' ', $r));
            echo '<option value="' . esc_attr($r) . '"' . selected($f_role, $r, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></div>';

        // Suspension filter with count.
        $suspended_count = 0;
        if ( HL_BuddyBoss_Integration::bp_suspend_table_exists() ) {
            $suspended_count = (int) $wpdb->get_var(
                "SELECT COUNT(DISTINCT e.enrollment_id)
                 FROM {$wpdb->prefix}hl_enrollment e
                 INNER JOIN {$wpdb->prefix}bp_suspend s ON s.item_type = 'user' AND s.item_id = e.user_id AND s.user_suspended = 1
                 WHERE e.status = 'active'"
            );
        }
        $f_suspended = isset( $_GET['suspended'] ) ? sanitize_text_field( $_GET['suspended'] ) : '';
        echo '<div><label style="display:block;font-size:11px;font-weight:600;color:#646970;margin-bottom:2px;">' . esc_html__( 'Suspension', 'hl-core' ) . '</label>';
        echo '<select name="suspended" style="min-width:160px;">';
        echo '<option value="">' . esc_html__( 'All Users', 'hl-core' ) . '</option>';
        echo '<option value="only"' . selected( $f_suspended, 'only', false ) . '>' . sprintf( esc_html__( 'Suspended Only (%d)', 'hl-core' ), $suspended_count ) . '</option>';
        echo '<option value="exclude"' . selected( $f_suspended, 'exclude', false ) . '>' . esc_html__( 'Exclude Suspended', 'hl-core' ) . '</option>';
        echo '</select></div>';

        // School.
        echo '<div><label style="display:block;font-size:11px;font-weight:600;color:#646970;margin-bottom:2px;">' . esc_html__('School', 'hl-core') . '</label>';
        echo '<select name="school_id" style="min-width:180px;">';
        echo '<option value="">' . esc_html__('All Schools', 'hl-core') . '</option>';
        if ($school_rows) {
            foreach ($school_rows as $s) {
                echo '<option value="' . esc_attr($s->orgunit_id) . '"' . selected($f_school, $s->orgunit_id, false) . '>' . esc_html($s->name) . '</option>';
            }
        }
        echo '</select></div>';

        // Search.
        echo '<div><label style="display:block;font-size:11px;font-weight:600;color:#646970;margin-bottom:2px;">' . esc_html__('Search', 'hl-core') . '</label>';
        echo '<input type="text" name="s" value="' . esc_attr($f_search) . '" placeholder="' . esc_attr__('Name or email...', 'hl-core') . '" style="min-width:180px;" /></div>';

        echo '<div>';
        submit_button(__('Filter', 'hl-core'), 'secondary', 'submit', false);
        if ($f_partnership || $f_cycle || $f_role || $f_school || $f_search || $f_suspended) {
            echo ' <a href="' . esc_url(admin_url('admin.php?page=hl-enrollments')) . '" class="button">' . esc_html__('Clear', 'hl-core') . '</a>';
        }
        echo '</div>';

        echo '</div>';
        echo '</form>';

        // Show count.
        $count = count($enrollments);
        echo '<p style="color:#646970;font-size:13px;margin:0 0 10px;">'
            . sprintf(esc_html__('Showing %d enrollments', 'hl-core'), $count)
            . ($count >= 500 ? ' (' . esc_html__('limited to 500 — use filters to narrow', 'hl-core') . ')' : '')
            . '</p>';

        if (empty($enrollments)) {
            echo '<p>' . esc_html__('No enrollments found.', 'hl-core') . '</p>';
            return;
        }

        $can_switch = class_exists( 'BP_Core_Members_Switching' ) && current_user_can( 'edit_users' );

        echo '<table class="widefat striped">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>' . esc_html__('User Name', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Email', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Cycle', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Roles', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('School', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Status', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Enrolled At', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Actions', 'hl-core') . '</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';

        foreach ($enrollments as $enrollment) {
            $edit_url   = admin_url('admin.php?page=hl-enrollments&action=edit&id=' . $enrollment->enrollment_id);
            $delete_url = wp_nonce_url(
                admin_url('admin.php?page=hl-enrollments&action=delete&id=' . $enrollment->enrollment_id),
                'hl_delete_enrollment_' . $enrollment->enrollment_id
            );

            // Decode roles
            $roles_array = json_decode($enrollment->roles, true);
            $roles_display = is_array($roles_array) ? implode(', ', $roles_array) : '';

            // School name
            $school_name = '';
            if ($enrollment->school_id && isset($schools[$enrollment->school_id])) {
                $school_name = $schools[$enrollment->school_id];
            }

            // Status
            $status_style = ($enrollment->status === 'active')
                ? 'color:#00a32a;font-weight:600;'
                : 'color:#b32d2e;font-weight:600;';

            $suspended_badge = HL_BuddyBoss_Integration::is_user_suspended( (int) $enrollment->user_id )
                ? ' <span class="hl-status-badge suspended">' . esc_html__( 'Suspended', 'hl-core' ) . '</span>'
                : '';
            echo '<tr>';
            echo '<td><strong><a href="' . esc_url($edit_url) . '">' . esc_html($enrollment->display_name) . '</a></strong>' . $suspended_badge . '</td>';
            echo '<td>' . esc_html($enrollment->user_email) . '</td>';
            echo '<td>' . esc_html($enrollment->cycle_name) . '</td>';
            echo '<td>' . esc_html($roles_display) . '</td>';
            echo '<td>' . esc_html($school_name) . '</td>';
            echo '<td><span style="' . esc_attr($status_style) . '">' . esc_html(ucfirst($enrollment->status)) . '</span></td>';
            echo '<td>' . esc_html($enrollment->enrolled_at) . '</td>';
            echo '<td>';
            echo '<a href="' . esc_url($edit_url) . '" class="button button-small">' . esc_html__('Edit', 'hl-core') . '</a> ';
            $profile_url = $this->get_frontend_profile_url($enrollment->user_id);
            if ($profile_url) {
                echo '<a href="' . esc_url($profile_url) . '" class="button button-small" target="_blank">' . esc_html__('Profile', 'hl-core') . '</a> ';
            }
            echo '<a href="' . esc_url($delete_url) . '" class="button button-small button-link-delete" onclick="return confirm(\'' . esc_js(__('Are you sure you want to delete this enrollment?', 'hl-core')) . '\');">' . esc_html__('Delete', 'hl-core') . '</a> ';
            if ( $can_switch && $enrollment->user_id ) {
                $target_user = new WP_User( $enrollment->user_id );
                if ( $target_user->exists() ) {
                    $switch_url = BP_Core_Members_Switching::switch_to_url( $target_user );
                    echo '<a href="' . esc_url( $switch_url ) . '" title="' . esc_attr__( 'View as this user', 'hl-core' ) . '" class="button button-small">&#x21C4; ' . esc_html__( 'View As', 'hl-core' ) . '</a>';
                }
            }
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';

        // Partnership → Cycle dependency JS.
        ?>
        <script>
        (function(){
            var pSel = document.getElementById('hl-enr-f-partnership');
            var cSel = document.getElementById('hl-enr-f-cycle');
            if (!pSel || !cSel) return;

            var allOpts = Array.from(cSel.querySelectorAll('option[data-partnership]'));

            pSel.addEventListener('change', function() {
                var pid = this.value;
                cSel.value = '';
                allOpts.forEach(function(opt) {
                    opt.style.display = (!pid || opt.dataset.partnership === pid || opt.dataset.partnership === '0') ? '' : 'none';
                });
            });
        })();
        </script>
        <?php
    }

    /**
     * Render the create/edit form
     *
     * @param object|null $enrollment Enrollment row for edit, null for create.
     * @param array       $context    Optional cycle context. Keys: 'cycle_id', 'cycle_name'.
     */
    public function render_form($enrollment = null, $context = array()) {
        $is_edit  = ($enrollment !== null);

        // Component progress action notices.
        if ($is_edit && isset($_GET['message'])) {
            $cp_msg = sanitize_text_field($_GET['message']);
            $cp_notices = array(
                'component_reset'              => array('success', __('Component progress reset to Not Started.', 'hl-core')),
                'component_complete'           => array('success', __('Component marked as Complete.', 'hl-core')),
                'component_reset_ld_warning'   => array('warning', __('Component progress reset, but LearnDash course progress could not be synced. The user\'s LearnDash course page may show stale data.', 'hl-core')),
                'component_complete_ld_warning' => array('warning', __('Component marked as Complete, but LearnDash course progress could not be synced.', 'hl-core')),
            );
            if (isset($cp_notices[$cp_msg])) {
                $type = $cp_notices[$cp_msg][0] === 'warning' ? 'notice-warning' : 'notice-success';
                echo '<div class="notice ' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($cp_notices[$cp_msg][1]) . '</p></div>';
            }
        }

        $title    = $is_edit ? __('Edit Enrollment', 'hl-core') : __('Add New Enrollment', 'hl-core');
        $in_cycle = !empty($context['cycle_id']);

        global $wpdb;

        // Resolve current user for edit mode.
        $current_user_id   = $is_edit ? $enrollment->user_id : '';
        $current_user_text = '';
        if ($current_user_id) {
            $u = get_userdata($current_user_id);
            if ($u) {
                $current_user_text = $u->display_name . ' (' . $u->user_email . ')';
            }
        }
        $user_search_nonce = wp_create_nonce('hl_enrollment_user_search');

        // Get cycles
        $cycles = $wpdb->get_results(
            "SELECT cycle_id, cycle_name FROM {$wpdb->prefix}hl_cycle ORDER BY cycle_name ASC"
        );

        // Get schools
        $schools_rows = $wpdb->get_results(
            "SELECT orgunit_id, name FROM {$wpdb->prefix}hl_orgunit WHERE orgunit_type = 'school' AND status = 'active' ORDER BY name ASC"
        );

        // Get districts
        $districts = $wpdb->get_results(
            "SELECT orgunit_id, name FROM {$wpdb->prefix}hl_orgunit WHERE orgunit_type = 'district' AND status = 'active' ORDER BY name ASC"
        );

        // Get pathways (for cycle-filtered dropdown).
        $pathways = $wpdb->get_results(
            "SELECT pathway_id, pathway_name, cycle_id FROM {$wpdb->prefix}hl_pathway WHERE active_status = 1 ORDER BY pathway_name ASC"
        );

        // Get teams (for cycle-filtered dropdown).
        $teams = $wpdb->get_results(
            "SELECT team_id, team_name, cycle_id FROM {$wpdb->prefix}hl_team ORDER BY team_name ASC"
        );

        // Current pathway assignment and team membership for edit mode.
        $current_pathway_id = 0;
        $current_team_id    = 0;
        if ($is_edit) {
            $current_pathway_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT pathway_id FROM {$wpdb->prefix}hl_pathway_assignment WHERE enrollment_id = %d ORDER BY FIELD(assignment_type, 'explicit', 'role_default') LIMIT 1",
                $enrollment->enrollment_id
            ));
            $current_team_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT team_id FROM {$wpdb->prefix}hl_team_membership WHERE enrollment_id = %d LIMIT 1",
                $enrollment->enrollment_id
            ));
        }

        // Decode current roles (DB may store lowercase; normalize to Title Case for checkbox matching).
        $current_roles = array();
        if ($is_edit && !empty($enrollment->roles)) {
            $decoded = json_decode($enrollment->roles, true);
            if (is_array($decoded)) {
                $current_roles = array_map(function($r) {
                    // "teacher" → "Teacher", "school_leader" → "School Leader"
                    return ucwords(str_replace('_', ' ', $r));
                }, $decoded);
            }
        }

        if (!$in_cycle) {
            echo '<h1>' . esc_html($title) . '</h1>';
            echo '<a href="' . esc_url(admin_url('admin.php?page=hl-enrollments')) . '">&larr; ' . esc_html__('Back to Enrollments', 'hl-core') . '</a>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=hl-enrollments')) . '">';
        wp_nonce_field('hl_save_enrollment', 'hl_enrollment_nonce');
        if ($in_cycle) {
            echo '<input type="hidden" name="_hl_cycle_context" value="' . esc_attr($context['cycle_id']) . '" />';
        }

        if ($is_edit) {
            echo '<input type="hidden" name="enrollment_id" value="' . esc_attr($enrollment->enrollment_id) . '" />';
        }

        echo '<table class="form-table">';

        // User (AJAX search).
        echo '<tr>';
        echo '<th scope="row"><label for="hl-user-search">' . esc_html__('User', 'hl-core') . '</label></th>';
        echo '<td>';
        echo '<div style="position:relative;max-width:400px;">';
        echo '<input type="hidden" id="user_id" name="user_id" value="' . esc_attr($current_user_id) . '" />';
        echo '<input type="text" id="hl-user-search" autocomplete="off" class="regular-text"'
           . ' placeholder="' . esc_attr__('Type to search by name or email...', 'hl-core') . '"'
           . ' value="' . esc_attr($current_user_text) . '"'
           . ' data-nonce="' . esc_attr($user_search_nonce) . '" />';
        echo '<div id="hl-user-results" style="display:none;position:absolute;z-index:999;background:#fff;border:1px solid #ddd;border-top:0;border-radius:0 0 4px 4px;width:100%;max-height:220px;overflow-y:auto;box-shadow:0 4px 12px rgba(0,0,0,.1);"></div>';
        echo '</div>';
        echo '</td>';
        echo '</tr>';

        // Cycle
        $current_cycle = $in_cycle ? absint($context['cycle_id']) : ($is_edit ? $enrollment->cycle_id : '');
        echo '<tr>';
        echo '<th scope="row"><label for="cycle_id">' . esc_html__('Cycle', 'hl-core') . '</label></th>';
        if ($in_cycle) {
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

        // Roles (checkboxes)
        $available_roles = array('Teacher', 'Mentor', 'School Leader', 'District Leader');
        echo '<tr>';
        echo '<th scope="row">' . esc_html__('Roles', 'hl-core') . '</th>';
        echo '<td><fieldset>';
        foreach ($available_roles as $role) {
            $checked = in_array($role, $current_roles) ? ' checked="checked"' : '';
            echo '<label><input type="checkbox" name="roles[]" value="' . esc_attr($role) . '"' . $checked . ' /> ' . esc_html($role) . '</label><br />';
        }
        echo '</fieldset></td>';
        echo '</tr>';

        // Pathway (filtered by cycle).
        echo '<tr>';
        echo '<th scope="row"><label for="pathway_id">' . esc_html__('Pathway', 'hl-core') . '</label></th>';
        echo '<td><select id="pathway_id" name="pathway_id">';
        echo '<option value="">' . esc_html__('-- Select Pathway --', 'hl-core') . '</option>';
        if ($pathways) {
            foreach ($pathways as $pw) {
                echo '<option value="' . esc_attr($pw->pathway_id) . '"'
                    . ' data-cycle="' . esc_attr($pw->cycle_id) . '"'
                    . selected($current_pathway_id, $pw->pathway_id, false) . '>'
                    . esc_html($pw->pathway_name) . '</option>';
            }
        }
        echo '</select>';
        echo '<input type="hidden" name="_current_pathway_id" value="' . esc_attr($current_pathway_id) . '" />';
        echo '</td>';
        echo '</tr>';

        // Team (filtered by cycle).
        echo '<tr>';
        echo '<th scope="row"><label for="team_id">' . esc_html__('Team', 'hl-core') . '</label></th>';
        echo '<td><select id="team_id" name="team_id">';
        echo '<option value="">' . esc_html__('-- Select Team --', 'hl-core') . '</option>';
        if ($teams) {
            foreach ($teams as $tm) {
                echo '<option value="' . esc_attr($tm->team_id) . '"'
                    . ' data-cycle="' . esc_attr($tm->cycle_id) . '"'
                    . selected($current_team_id, $tm->team_id, false) . '>'
                    . esc_html($tm->team_name) . '</option>';
            }
        }
        echo '</select>';
        echo '<input type="hidden" name="_current_team_id" value="' . esc_attr($current_team_id) . '" />';
        echo '</td>';
        echo '</tr>';

        // School
        $current_school = $is_edit ? $enrollment->school_id : '';
        echo '<tr>';
        echo '<th scope="row"><label for="school_id">' . esc_html__('School', 'hl-core') . '</label></th>';
        echo '<td><select id="school_id" name="school_id">';
        echo '<option value="">' . esc_html__('-- Select School --', 'hl-core') . '</option>';
        if ($schools_rows) {
            foreach ($schools_rows as $school) {
                echo '<option value="' . esc_attr($school->orgunit_id) . '"' . selected($current_school, $school->orgunit_id, false) . '>' . esc_html($school->name) . '</option>';
            }
        }
        echo '</select></td>';
        echo '</tr>';

        // District
        $current_district = $is_edit ? $enrollment->district_id : '';
        echo '<tr>';
        echo '<th scope="row"><label for="district_id">' . esc_html__('District', 'hl-core') . '</label></th>';
        echo '<td><select id="district_id" name="district_id">';
        echo '<option value="">' . esc_html__('-- Select District --', 'hl-core') . '</option>';
        if ($districts) {
            foreach ($districts as $district) {
                echo '<option value="' . esc_attr($district->orgunit_id) . '"' . selected($current_district, $district->orgunit_id, false) . '>' . esc_html($district->name) . '</option>';
            }
        }
        echo '</select></td>';
        echo '</tr>';

        // Status
        $current_status = $is_edit ? $enrollment->status : 'active';
        echo '<tr>';
        echo '<th scope="row"><label for="status">' . esc_html__('Status', 'hl-core') . '</label></th>';
        echo '<td><select id="status" name="status">';
        echo '<option value="active"' . selected($current_status, 'active', false) . '>' . esc_html__('Active', 'hl-core') . '</option>';
        echo '<option value="inactive"' . selected($current_status, 'inactive', false) . '>' . esc_html__('Inactive', 'hl-core') . '</option>';
        echo '</select></td>';
        echo '</tr>';

        // Language Preference
        $current_language = $is_edit ? ($enrollment->language_preference ?? 'en') : 'en';
        echo '<tr>';
        echo '<th scope="row"><label for="language_preference">' . esc_html__('Language Preference', 'hl-core') . '</label></th>';
        echo '<td><select id="language_preference" name="language_preference">';
        echo '<option value="en"' . selected($current_language, 'en', false) . '>' . esc_html__('English', 'hl-core') . '</option>';
        echo '<option value="es"' . selected($current_language, 'es', false) . '>' . esc_html__('Spanish', 'hl-core') . '</option>';
        echo '<option value="pt"' . selected($current_language, 'pt', false) . '>' . esc_html__('Portuguese', 'hl-core') . '</option>';
        echo '</select></td>';
        echo '</tr>';

        echo '</table>';

        submit_button($is_edit ? __('Update Enrollment', 'hl-core') : __('Create Enrollment', 'hl-core'));

        echo '</form>';

        // =====================================================================
        // Component Progress Table (edit mode, admin only)
        // =====================================================================
        if ($is_edit && current_user_can('manage_hl_core')) {
            echo '<hr style="margin:30px 0 20px;" />';
            echo '<h2>' . esc_html__('Component Progress', 'hl-core') . '</h2>';

            if (!$current_pathway_id) {
                echo '<p class="description">' . esc_html__('No pathway assigned — assign a pathway to manage component progress.', 'hl-core') . '</p>';
            } else {
                // Load active components for the assigned pathway.
                $cp_components = $wpdb->get_results($wpdb->prepare(
                    "SELECT component_id, title, component_type, weight, catalog_id,
                            requires_classroom, eligible_roles, ordering_hint, external_ref
                     FROM {$wpdb->prefix}hl_component
                     WHERE pathway_id = %d AND status = 'active'
                     ORDER BY ordering_hint ASC, component_id ASC",
                    $current_pathway_id
                ));

                if (empty($cp_components)) {
                    echo '<p class="description">' . esc_html__('No active components in this pathway.', 'hl-core') . '</p>';
                } else {
                    // Load component states for this enrollment.
                    $cp_ids = wp_list_pluck($cp_components, 'component_id');
                    $cp_placeholders = implode(',', array_fill(0, count($cp_ids), '%d'));
                    $cp_states = $wpdb->get_results($wpdb->prepare(
                        "SELECT component_id, completion_status, completion_percent, completed_at
                         FROM {$wpdb->prefix}hl_component_state
                         WHERE enrollment_id = %d AND component_id IN ($cp_placeholders)",
                        array_merge(array($enrollment->enrollment_id), $cp_ids)
                    ));
                    $cp_state_map = array();
                    foreach ($cp_states as $s) {
                        $cp_state_map[$s->component_id] = $s;
                    }

                    // Load exempt overrides.
                    $cp_overrides = $wpdb->get_results($wpdb->prepare(
                        "SELECT component_id FROM {$wpdb->prefix}hl_component_override
                         WHERE enrollment_id = %d AND override_type = 'exempt'
                         AND component_id IN ($cp_placeholders)",
                        array_merge(array($enrollment->enrollment_id), $cp_ids)
                    ));
                    $cp_exempt_map = array();
                    foreach ($cp_overrides as $ov) {
                        $cp_exempt_map[$ov->component_id] = true;
                    }

                    // Type labels.
                    $cp_type_labels = array(
                        'learndash_course'            => __('LearnDash Course', 'hl-core'),
                        'teacher_self_assessment'     => __('Teacher Self-Assessment', 'hl-core'),
                        'child_assessment'            => __('Child Assessment', 'hl-core'),
                        'coaching_session_attendance'  => __('Coaching Attendance', 'hl-core'),
                        'classroom_visit'             => __('Classroom Visit', 'hl-core'),
                        'reflective_practice_session' => __('Reflective Practice', 'hl-core'),
                        'self_reflection'             => __('Self-Reflection', 'hl-core'),
                    );

                    $non_ld_types = array('coaching_session_attendance', 'classroom_visit', 'reflective_practice_session', 'self_reflection');
                    $has_non_ld = false;
                    $rules_engine = new HL_Rules_Engine_Service();

                    echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=hl-enrollments')) . '">';
                    wp_nonce_field('hl_component_progress', 'hl_component_progress_nonce');
                    echo '<input type="hidden" name="hl_component_enrollment_id" value="' . esc_attr($enrollment->enrollment_id) . '" />';
                    echo '<input type="hidden" name="hl_component_id" value="" />';
                    if ($in_cycle) {
                        echo '<input type="hidden" name="_hl_cycle_context" value="' . esc_attr($context['cycle_id']) . '" />';
                    }

                    echo '<table class="widefat striped" style="max-width:900px;">';
                    echo '<thead><tr>';
                    echo '<th>' . esc_html__('Component', 'hl-core') . '</th>';
                    echo '<th>' . esc_html__('Type', 'hl-core') . '</th>';
                    echo '<th>' . esc_html__('Status', 'hl-core') . '</th>';
                    echo '<th>' . esc_html__('Progress', 'hl-core') . '</th>';
                    echo '<th>' . esc_html__('Actions', 'hl-core') . '</th>';
                    echo '</tr></thead><tbody>';

                    foreach ($cp_components as $comp) {
                        $cid = $comp->component_id;

                        // Check eligibility.
                        $comp_obj = new HL_Component(array(
                            'requires_classroom' => $comp->requires_classroom,
                            'eligible_roles'     => $comp->eligible_roles,
                        ));
                        $eligible = $rules_engine->check_eligibility($enrollment->enrollment_id, $comp_obj);

                        // State.
                        $status  = 'not_started';
                        $percent = 0;
                        if (isset($cp_state_map[$cid])) {
                            $status  = $cp_state_map[$cid]->completion_status;
                            $percent = intval($cp_state_map[$cid]->completion_percent);
                        }

                        $is_exempt = isset($cp_exempt_map[$cid]);

                        // Type label.
                        $type_label = isset($cp_type_labels[$comp->component_type])
                            ? $cp_type_labels[$comp->component_type]
                            : ucwords(str_replace('_', ' ', $comp->component_type));

                        if (in_array($comp->component_type, $non_ld_types, true)) {
                            $has_non_ld = true;
                        }

                        // Status badge.
                        if (!$eligible) {
                            $badge = '<span style="background:#f0f0f1;color:#50575e;padding:2px 8px;border-radius:3px;font-size:12px;">'
                                   . esc_html__('Not Applicable', 'hl-core') . '</span>';
                        } elseif ($status === 'complete') {
                            $badge = '<span style="background:#d4edda;color:#155724;padding:2px 8px;border-radius:3px;font-size:12px;">'
                                   . esc_html__('Complete', 'hl-core') . '</span>';
                        } elseif ($status === 'in_progress') {
                            $badge = '<span style="background:#fff3cd;color:#856404;padding:2px 8px;border-radius:3px;font-size:12px;">'
                                   . esc_html__('In Progress', 'hl-core') . '</span>';
                        } else {
                            $badge = '<span style="background:#f0f0f1;color:#50575e;padding:2px 8px;border-radius:3px;font-size:12px;">'
                                   . esc_html__('Not Started', 'hl-core') . '</span>';
                        }

                        if ($is_exempt) {
                            $badge .= ' <em style="color:#996800;font-size:11px;">'
                                    . esc_html__('(Exempt override active)', 'hl-core') . '</em>';
                        }

                        echo '<tr>';
                        echo '<td><strong>' . esc_html($comp->title) . '</strong></td>';
                        echo '<td>' . esc_html($type_label) . '</td>';
                        echo '<td>' . $badge . '</td>';
                        echo '<td>' . esc_html($percent) . '%</td>';
                        echo '<td>';

                        if (!$eligible) {
                            echo '<button type="button" class="button button-small" disabled>' . esc_html__('Reset', 'hl-core') . '</button> ';
                            echo '<button type="button" class="button button-small" disabled>' . esc_html__('Mark Complete', 'hl-core') . '</button>';
                        } else {
                            $esc_title = esc_js($comp->title);

                            if ($status === 'in_progress' || $status === 'complete') {
                                echo '<button type="submit" name="hl_component_action" value="reset_component"'
                                   . ' class="button button-small"'
                                   . ' onclick="this.form.hl_component_id.value=\'' . esc_attr($cid) . '\';'
                                   . 'return confirm(\'' . sprintf(esc_js(__('Reset "%s" to Not Started for this user?', 'hl-core')), $esc_title) . '\');">'
                                   . esc_html__('Reset', 'hl-core') . '</button> ';
                            }

                            if ($status === 'not_started' || $status === 'in_progress') {
                                echo '<button type="submit" name="hl_component_action" value="complete_component"'
                                   . ' class="button button-small button-primary"'
                                   . ' onclick="this.form.hl_component_id.value=\'' . esc_attr($cid) . '\';'
                                   . 'return confirm(\'' . sprintf(esc_js(__('Mark "%s" as Complete for this user?', 'hl-core')), $esc_title) . '\');">'
                                   . esc_html__('Mark Complete', 'hl-core') . '</button>';
                            }
                        }

                        echo '</td>';
                        echo '</tr>';
                    }

                    echo '</tbody></table>';
                    echo '</form>';

                    if ($has_non_ld) {
                        echo '<p class="description" style="margin-top:8px;">'
                           . esc_html__('Note: activity-based components may recalculate when new activity occurs.', 'hl-core')
                           . '</p>';
                    }
                }
            }
        }

        // User search autocomplete JS.
        ?>
        <script>
        (function(){
            var input   = document.getElementById('hl-user-search');
            var hidden  = document.getElementById('user_id');
            var results = document.getElementById('hl-user-results');
            var nonce   = input.dataset.nonce;
            var timer   = null;

            input.addEventListener('input', function() {
                clearTimeout(timer);
                var q = this.value.trim();
                if (q.length < 2) { results.style.display = 'none'; return; }
                // Clear selection when user edits the text.
                hidden.value = '';
                timer = setTimeout(function() { doSearch(q); }, 250);
            });

            input.addEventListener('focus', function() {
                if (results.children.length > 0 && !hidden.value) {
                    results.style.display = 'block';
                }
            });

            document.addEventListener('click', function(e) {
                if (!results.contains(e.target) && e.target !== input) {
                    results.style.display = 'none';
                }
            });

            function doSearch(q) {
                fetch(ajaxurl + '?action=hl_search_users&_nonce=' + encodeURIComponent(nonce) + '&q=' + encodeURIComponent(q))
                    .then(function(r) { return r.json(); })
                    .then(function(resp) {
                        results.innerHTML = '';
                        if (!resp.success || !resp.data.length) {
                            results.innerHTML = '<div style="padding:10px 12px;color:#999;font-size:13px;"><?php echo esc_js(__('No users found', 'hl-core')); ?></div>';
                            results.style.display = 'block';
                            return;
                        }
                        resp.data.forEach(function(u) {
                            var div = document.createElement('div');
                            div.textContent = u.text;
                            div.style.cssText = 'padding:8px 12px;cursor:pointer;font-size:13px;border-bottom:1px solid #f0f0f0;';
                            div.addEventListener('mouseenter', function(){ this.style.background='#f0f6fc'; });
                            div.addEventListener('mouseleave', function(){ this.style.background=''; });
                            div.addEventListener('click', function() {
                                input.value = u.text;
                                hidden.value = u.id;
                                results.style.display = 'none';
                            });
                            results.appendChild(div);
                        });
                        results.style.display = 'block';
                    })
                    .catch(function() { results.style.display = 'none'; });
            }

            // Validate on submit: require a selected user.
            input.closest('form').addEventListener('submit', function(e) {
                if (!hidden.value) {
                    e.preventDefault();
                    input.focus();
                    input.style.borderColor = '#d63638';
                    setTimeout(function(){ input.style.borderColor = ''; }, 2000);
                    alert('<?php echo esc_js(__('Please select a user from the search results.', 'hl-core')); ?>');
                }
            });
        })();
        </script>
        <script>
        // Filter Pathway and Team dropdowns by selected Cycle.
        (function(){
            var cycleSel   = document.getElementById('cycle_id');
            var pathwaySel = document.getElementById('pathway_id');
            var teamSel    = document.getElementById('team_id');
            if (!cycleSel || !pathwaySel || !teamSel) return;

            var pwOpts   = Array.from(pathwaySel.querySelectorAll('option[data-cycle]'));
            var teamOpts = Array.from(teamSel.querySelectorAll('option[data-cycle]'));

            function filterByCycle(cycleId) {
                // Filter pathway options.
                var currentPw = pathwaySel.value;
                var pwVisible = false;
                pwOpts.forEach(function(opt) {
                    var show = (!cycleId || opt.dataset.cycle === cycleId);
                    opt.style.display = show ? '' : 'none';
                    if (opt.value === currentPw && show) pwVisible = true;
                });
                if (!pwVisible) pathwaySel.value = '';

                // Filter team options.
                var currentTm = teamSel.value;
                var tmVisible = false;
                teamOpts.forEach(function(opt) {
                    var show = (!cycleId || opt.dataset.cycle === cycleId);
                    opt.style.display = show ? '' : 'none';
                    if (opt.value === currentTm && show) tmVisible = true;
                });
                if (!tmVisible) teamSel.value = '';
            }

            cycleSel.addEventListener('change', function() {
                filterByCycle(this.value);
            });

            // Initial filter on page load.
            if (cycleSel.value) {
                filterByCycle(cycleSel.value);
            }
        })();
        </script>
        <script>
        jQuery(function($) {
            var suggestTimeout;
            var $pathwaySelect = $('#pathway_id');
            var $cycleSelect = $('#cycle_id');
            var $roleBoxes = $('input[name="roles[]"]');
            var $suggestLabel = $('<span class="hl-pathway-suggest-label" style="margin-left:8px;font-style:italic;color:#666;font-size:12px;"></span>');
            $pathwaySelect.after($suggestLabel);

            function suggestPathway() {
                clearTimeout(suggestTimeout);
                suggestTimeout = setTimeout(function() {
                    var cycleId = $cycleSelect.val();
                    var checkedRoles = [];
                    $roleBoxes.filter(':checked').each(function() { checkedRoles.push($(this).val()); });
                    var userId = $('#user_id').val() || 0;

                    if (!cycleId || !checkedRoles.length) {
                        $suggestLabel.text('');
                        return;
                    }

                    $.post(ajaxurl, {
                        action: 'hl_suggest_pathway',
                        nonce: '<?php echo wp_create_nonce("hl_suggest_pathway"); ?>',
                        user_id: userId,
                        role: checkedRoles[0],
                        cycle_id: cycleId
                    }, function(resp) {
                        if (resp.success && resp.data.pathway_id) {
                            // Only auto-select if admin hasn't already picked one
                            if (!$pathwaySelect.val() || $pathwaySelect.val() === '0' || $pathwaySelect.val() === '') {
                                $pathwaySelect.val(resp.data.pathway_id);
                            }
                            var label = resp.data.source === 'routed'
                                ? '<?php echo esc_js(__("Auto-suggested based on course history", "hl-core")); ?>'
                                : '<?php echo esc_js(__("Default for new participants", "hl-core")); ?>';
                            $suggestLabel.text(label);
                        } else {
                            $suggestLabel.text('');
                        }
                    }).fail(function() { $suggestLabel.text(''); });
                }, 300);
            }

            $cycleSelect.on('change', suggestPathway);
            $roleBoxes.on('change', suggestPathway);
            $pathwaySelect.on('change', function() { $suggestLabel.text(''); });
        });
        </script>
        <?php
    }

    private function get_frontend_profile_url($user_id) {
        static $base_url = null;
        if ($base_url === null) {
            global $wpdb;
            $page_id = $wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_type = 'page' AND post_status = 'publish'
                 AND post_content LIKE %s LIMIT 1",
                '%[' . $wpdb->esc_like('hl_user_profile') . '%'
            ));
            $base_url = $page_id ? get_permalink($page_id) : '';
        }
        return $base_url ? add_query_arg('user_id', (int) $user_id, $base_url) : '';
    }
}
