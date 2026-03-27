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
    private function __construct() {
        add_action('wp_ajax_hl_search_users', array($this, 'ajax_search_users'));
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

        // Process roles checkboxes into JSON array
        $roles = array();
        if (!empty($_POST['roles']) && is_array($_POST['roles'])) {
            foreach ($_POST['roles'] as $role) {
                $roles[] = sanitize_text_field($role);
            }
        }

        $data = array(
            'cycle_id'    => absint($_POST['cycle_id']),
            'user_id'     => absint($_POST['user_id']),
            'roles'       => wp_json_encode($roles),
            'school_id'   => !empty($_POST['school_id']) ? absint($_POST['school_id']) : null,
            'district_id' => !empty($_POST['district_id']) ? absint($_POST['district_id']) : null,
            'status'      => sanitize_text_field($_POST['status']),
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
            if ($cycle_context) {
                $redirect = admin_url('admin.php?page=hl-cycles&action=edit&id=' . $cycle_context . '&tab=enrollments&message=enrollment_created');
            } else {
                $redirect = admin_url('admin.php?page=hl-enrollments&message=created');
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
     * Render the enrollments list table
     */
    private function render_list() {
        global $wpdb;

        // Filter by cycle
        $filter_cycle = isset($_GET['cycle_id']) ? absint($_GET['cycle_id']) : 0;

        $where = '';
        if ($filter_cycle) {
            $where = $wpdb->prepare(' WHERE e.cycle_id = %d', $filter_cycle);
        }

        $enrollments = $wpdb->get_results(
            "SELECT e.*, t.cycle_name, u.display_name, u.user_email
             FROM {$wpdb->prefix}hl_enrollment e
             LEFT JOIN {$wpdb->prefix}hl_cycle t ON e.cycle_id = t.cycle_id
             LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
             {$where}
             ORDER BY e.enrolled_at DESC"
        );

        // Get cycles for filter dropdown
        $cycles = $wpdb->get_results(
            "SELECT cycle_id, cycle_name FROM {$wpdb->prefix}hl_cycle ORDER BY cycle_name ASC"
        );

        // Get school names
        $schools = array();
        $school_rows = $wpdb->get_results(
            "SELECT orgunit_id, name FROM {$wpdb->prefix}hl_orgunit WHERE orgunit_type = 'school'"
        );
        if ($school_rows) {
            foreach ($school_rows as $c) {
                $schools[$c->orgunit_id] = $c->name;
            }
        }

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
        if ($filter_cycle) {
            global $wpdb;
            $cycle_name = $wpdb->get_var($wpdb->prepare(
                "SELECT cycle_name FROM {$wpdb->prefix}hl_cycle WHERE cycle_id = %d", $filter_cycle
            ));
            if ($cycle_name) {
                echo '<p style="margin:0 0 5px;"><a href="' . esc_url(admin_url('admin.php?page=hl-cycles&action=edit&id=' . $filter_cycle . '&tab=enrollments')) . '">&larr; ' . sprintf(esc_html__('Cycle: %s', 'hl-core'), esc_html($cycle_name)) . '</a></p>';
            }
        }

        echo '<h1 class="wp-heading-inline">' . esc_html__('Enrollments', 'hl-core') . '</h1>';
        echo ' <a href="' . esc_url(admin_url('admin.php?page=hl-enrollments&action=new')) . '" class="page-title-action">' . esc_html__('Add New', 'hl-core') . '</a>';
        echo '<hr class="wp-header-end">';

        // Cycle filter form
        echo '<form method="get" style="margin-bottom:15px;">';
        echo '<input type="hidden" name="page" value="hl-enrollments" />';
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

            echo '<tr>';
            echo '<td><strong><a href="' . esc_url($edit_url) . '">' . esc_html($enrollment->display_name) . '</a></strong></td>';
            echo '<td>' . esc_html($enrollment->user_email) . '</td>';
            echo '<td>' . esc_html($enrollment->cycle_name) . '</td>';
            echo '<td>' . esc_html($roles_display) . '</td>';
            echo '<td>' . esc_html($school_name) . '</td>';
            echo '<td><span style="' . esc_attr($status_style) . '">' . esc_html(ucfirst($enrollment->status)) . '</span></td>';
            echo '<td>' . esc_html($enrollment->enrolled_at) . '</td>';
            echo '<td>';
            echo '<a href="' . esc_url($edit_url) . '" class="button button-small">' . esc_html__('Edit', 'hl-core') . '</a> ';
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
    }

    /**
     * Render the create/edit form
     *
     * @param object|null $enrollment Enrollment row for edit, null for create.
     * @param array       $context    Optional cycle context. Keys: 'cycle_id', 'cycle_name'.
     */
    public function render_form($enrollment = null, $context = array()) {
        $is_edit  = ($enrollment !== null);
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

        // Decode current roles
        $current_roles = array();
        if ($is_edit && !empty($enrollment->roles)) {
            $decoded = json_decode($enrollment->roles, true);
            if (is_array($decoded)) {
                $current_roles = $decoded;
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

        echo '</table>';

        submit_button($is_edit ? __('Update Enrollment', 'hl-core') : __('Create Enrollment', 'hl-core'));

        echo '</form>';

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
        <?php
    }
}
