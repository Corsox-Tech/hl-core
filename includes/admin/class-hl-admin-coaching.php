<?php
if (!defined('ABSPATH')) exit;

/**
 * Admin Coaching Sessions Page
 *
 * Full CRUD admin page for managing Coaching Sessions.
 * Supports: create/edit/delete sessions, link/unlink observations,
 * add/remove attachments via WP Media Library, rich-text notes,
 * and attendance marking with component state updates.
 *
 * @package HL_Core
 */
class HL_Admin_Coaching {

    /**
     * Singleton instance
     *
     * @var HL_Admin_Coaching|null
     */
    private static $instance = null;

    /**
     * Get singleton instance
     *
     * @return HL_Admin_Coaching
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
        $tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'sessions';

        if ($tab === 'assignments') {
            HL_Admin_Coach_Assignments::instance()->handle_early_actions();
            return;
        }

        if ($tab === 'coaches') {
            $this->handle_coaches_actions();
            return;
        }

        $this->handle_post_actions();

        if (isset($_GET['action']) && $_GET['action'] === 'delete') {
            $this->handle_delete();
        }
    }

    /**
     * Main render entry point — Coaching Hub with tabs.
     */
    public function render_page() {
        $tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'sessions';

        echo '<div class="wrap hl-admin-wrap">';
        $this->render_hub_tabs($tab);

        if ($tab === 'assignments') {
            HL_Admin_Coach_Assignments::instance()->render_page_content();
        } elseif ($tab === 'coaches') {
            $this->render_coaches_content();
        } else {
            $this->render_sessions_content();
        }

        echo '</div>';
    }

    /**
     * Render the Coaching Hub tab navigation.
     *
     * @param string $active_tab Currently active tab slug.
     */
    private function render_hub_tabs($active_tab) {
        $tabs = array(
            'sessions'    => __('Sessions', 'hl-core'),
            'assignments' => __('Assignments', 'hl-core'),
            'coaches'     => __('Coaches', 'hl-core'),
        );
        $base_url = admin_url('admin.php?page=hl-coaching');

        echo '<nav class="nav-tab-wrapper">';
        foreach ($tabs as $slug => $label) {
            $url   = add_query_arg('tab', $slug, $base_url);
            $class = ($slug === $active_tab) ? 'nav-tab nav-tab-active' : 'nav-tab';
            printf('<a href="%s" class="%s">%s</a>', esc_url($url), esc_attr($class), esc_html($label));
        }
        echo '</nav>';
    }

    /**
     * Render the Sessions tab content (original render logic).
     */
    private function render_sessions_content() {
        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : 'list';

        switch ($action) {
            case 'new':
                $this->render_form();
                break;

            case 'edit':
                $session_id = isset($_GET['session_id']) ? absint($_GET['session_id']) : 0;
                $service    = new HL_Coaching_Service();
                $session    = $service->get_session($session_id);
                if ($session) {
                    $this->render_form($session);
                } else {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Coaching session not found.', 'hl-core') . '</p></div>';
                    $this->render_list();
                }
                break;

            default:
                $this->render_list();
                break;
        }
    }

    // =========================================================================
    // POST Action Handlers
    // =========================================================================

    /**
     * Handle POST form submissions (create, update, link/unlink observations, attachments)
     */
    private function handle_post_actions() {
        // Handle session create/update
        if (isset($_POST['hl_coaching_session_nonce'])) {
            $this->handle_save_session();
        }

        // Handle observation link
        if (isset($_POST['hl_link_observation_nonce'])) {
            $this->handle_link_observation();
        }

        // Handle observation unlink (GET with nonce)
        if (isset($_GET['unlink_observation'])) {
            $this->handle_unlink_observation();
        }

        // Handle attachment add
        if (isset($_POST['hl_add_attachment_nonce'])) {
            $this->handle_add_attachment();
        }

        // Handle attachment remove (GET with nonce)
        if (isset($_GET['remove_attachment'])) {
            $this->handle_remove_attachment();
        }
    }

    /**
     * Handle session create/update form submission
     */
    private function handle_save_session() {
        if (!wp_verify_nonce($_POST['hl_coaching_session_nonce'], 'hl_save_coaching_session')) {
            wp_die(__('Security check failed.', 'hl-core'));
        }

        if (!current_user_can('manage_hl_core')) {
            wp_die(__('You do not have permission to perform this action.', 'hl-core'));
        }

        $service    = new HL_Coaching_Service();
        $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;

        if ($session_id > 0) {
            // Update existing session
            $data = array(
                'session_datetime' => sanitize_text_field($_POST['session_datetime']),
                'session_title'    => sanitize_text_field($_POST['session_title'] ?? ''),
                'meeting_url'      => esc_url_raw($_POST['meeting_url'] ?? ''),
                'session_status'   => sanitize_text_field($_POST['session_status'] ?? 'scheduled'),
                'notes_richtext'   => wp_kses_post($_POST['notes_richtext']),
            );

            $result = $service->update_session($session_id, $data);

            if (is_wp_error($result)) {
                wp_redirect(admin_url('admin.php?page=hl-coaching&action=edit&session_id=' . $session_id . '&message=error'));
            } else {
                wp_redirect(admin_url('admin.php?page=hl-coaching&action=edit&session_id=' . $session_id . '&message=updated'));
            }
            exit;
        } else {
            // Create new session
            $data = array(
                'cycle_id'             => absint($_POST['cycle_id']),
                'mentor_enrollment_id' => absint($_POST['mentor_enrollment_id']),
                'coach_user_id'        => absint($_POST['coach_user_id']),
                'session_title'        => sanitize_text_field($_POST['session_title'] ?? ''),
                'meeting_url'          => esc_url_raw($_POST['meeting_url'] ?? ''),
                'session_datetime'     => sanitize_text_field($_POST['session_datetime']),
                'notes_richtext'       => wp_kses_post($_POST['notes_richtext']),
            );

            $result = $service->create_session($data);

            if (is_wp_error($result)) {
                wp_redirect(admin_url('admin.php?page=hl-coaching&action=new&message=error'));
            } else {
                wp_redirect(admin_url('admin.php?page=hl-coaching&action=edit&session_id=' . $result . '&message=created'));
            }
            exit;
        }
    }

    /**
     * Handle delete action (GET with nonce)
     */
    private function handle_delete() {
        $session_id = isset($_GET['session_id']) ? absint($_GET['session_id']) : 0;

        if (!$session_id) {
            return;
        }

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'hl_delete_coaching_session_' . $session_id)) {
            wp_die(__('Security check failed.', 'hl-core'));
        }

        if (!current_user_can('manage_hl_core')) {
            wp_die(__('You do not have permission to perform this action.', 'hl-core'));
        }

        $service = new HL_Coaching_Service();
        $result  = $service->delete_session($session_id);

        if (is_wp_error($result)) {
            wp_redirect(admin_url('admin.php?page=hl-coaching&message=error'));
        } else {
            wp_redirect(admin_url('admin.php?page=hl-coaching&message=deleted'));
        }
        exit;
    }

    /**
     * Handle observation link
     */
    private function handle_link_observation() {
        if (!wp_verify_nonce($_POST['hl_link_observation_nonce'], 'hl_link_observation')) {
            wp_die(__('Security check failed.', 'hl-core'));
        }

        if (!current_user_can('manage_hl_core')) {
            wp_die(__('You do not have permission to perform this action.', 'hl-core'));
        }

        $session_id     = absint($_POST['session_id']);
        $observation_id = absint($_POST['observation_id']);

        if ($session_id && $observation_id) {
            $service = new HL_Coaching_Service();
            $service->link_observations($session_id, array($observation_id));
        }

        wp_redirect(admin_url('admin.php?page=hl-coaching&action=edit&session_id=' . $session_id . '&message=observation_linked'));
        exit;
    }

    /**
     * Handle observation unlink (GET with nonce)
     */
    private function handle_unlink_observation() {
        $session_id     = isset($_GET['session_id']) ? absint($_GET['session_id']) : 0;
        $observation_id = absint($_GET['unlink_observation']);

        if (!$session_id || !$observation_id) {
            return;
        }

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'hl_unlink_observation_' . $observation_id)) {
            wp_die(__('Security check failed.', 'hl-core'));
        }

        if (!current_user_can('manage_hl_core')) {
            wp_die(__('You do not have permission to perform this action.', 'hl-core'));
        }

        $service = new HL_Coaching_Service();
        $service->unlink_observation($session_id, $observation_id);

        wp_redirect(admin_url('admin.php?page=hl-coaching&action=edit&session_id=' . $session_id . '&message=observation_unlinked'));
        exit;
    }

    /**
     * Handle attachment add
     */
    private function handle_add_attachment() {
        if (!wp_verify_nonce($_POST['hl_add_attachment_nonce'], 'hl_add_attachment')) {
            wp_die(__('Security check failed.', 'hl-core'));
        }

        if (!current_user_can('manage_hl_core')) {
            wp_die(__('You do not have permission to perform this action.', 'hl-core'));
        }

        $session_id  = absint($_POST['session_id']);
        $wp_media_id = absint($_POST['wp_media_id']);

        if ($session_id && $wp_media_id) {
            $service = new HL_Coaching_Service();
            $service->add_attachment($session_id, $wp_media_id);
        }

        wp_redirect(admin_url('admin.php?page=hl-coaching&action=edit&session_id=' . $session_id . '&message=attachment_added'));
        exit;
    }

    /**
     * Handle attachment remove (GET with nonce)
     */
    private function handle_remove_attachment() {
        $attachment_id = absint($_GET['remove_attachment']);
        $session_id    = isset($_GET['session_id']) ? absint($_GET['session_id']) : 0;

        if (!$attachment_id || !$session_id) {
            return;
        }

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'hl_remove_attachment_' . $attachment_id)) {
            wp_die(__('Security check failed.', 'hl-core'));
        }

        if (!current_user_can('manage_hl_core')) {
            wp_die(__('You do not have permission to perform this action.', 'hl-core'));
        }

        $service = new HL_Coaching_Service();
        $service->remove_attachment($attachment_id);

        wp_redirect(admin_url('admin.php?page=hl-coaching&action=edit&session_id=' . $session_id . '&message=attachment_removed'));
        exit;
    }

    // =========================================================================
    // List View
    // =========================================================================

    /**
     * Render the coaching sessions list
     */
    private function render_list() {
        global $wpdb;

        $filter_cycle = isset($_GET['cycle_id']) ? absint($_GET['cycle_id']) : 0;

        // Get all cycles for the filter dropdown
        $cycles = $wpdb->get_results(
            "SELECT cycle_id, cycle_name FROM {$wpdb->prefix}hl_cycle ORDER BY cycle_name ASC"
        );

        // Show success/error messages
        $this->render_messages();

        // Cycle breadcrumb.
        if ($filter_cycle) {
            $cycle_name = $wpdb->get_var($wpdb->prepare(
                "SELECT cycle_name FROM {$wpdb->prefix}hl_cycle WHERE cycle_id = %d", $filter_cycle
            ));
            if ($cycle_name) {
                echo '<p style="margin:0 0 5px;"><a href="' . esc_url(admin_url('admin.php?page=hl-cycles&action=edit&id=' . $filter_cycle . '&tab=coaching')) . '">&larr; ' . sprintf(esc_html__('Cycle: %s', 'hl-core'), esc_html($cycle_name)) . '</a></p>';
            }
        }

        echo '<h1 class="wp-heading-inline">' . esc_html__('Coaching Sessions', 'hl-core') . '</h1>';
        echo ' <a href="' . esc_url(admin_url('admin.php?page=hl-coaching&action=new')) . '" class="page-title-action">' . esc_html__('Add New Session', 'hl-core') . '</a>';
        echo '<hr class="wp-header-end">';

        // Cycle filter
        echo '<form method="get" style="margin-bottom:15px;">';
        echo '<input type="hidden" name="page" value="hl-coaching" />';
        echo '<label><strong>' . esc_html__('Cycle:', 'hl-core') . '</strong> </label>';
        echo '<select name="cycle_id">';
        echo '<option value="">' . esc_html__('All Cycles', 'hl-core') . '</option>';
        if ($cycles) {
            foreach ($cycles as $cycle) {
                echo '<option value="' . esc_attr($cycle->cycle_id) . '"' . selected($filter_cycle, $cycle->cycle_id, false) . '>' . esc_html($cycle->cycle_name) . '</option>';
            }
        }
        echo '</select> ';
        submit_button(__('Filter', 'hl-core'), 'secondary', 'submit', false);
        echo '</form>';

        // Get sessions
        if ($filter_cycle) {
            $service  = new HL_Coaching_Service();
            $sessions = $service->get_by_cycle($filter_cycle);
        } else {
            // Get all sessions across cycles
            $sessions = $wpdb->get_results(
                "SELECT cs.*, u_coach.display_name as coach_name, u_mentor.display_name as mentor_name, e.user_id AS mentor_user_id, t.cycle_name
                 FROM {$wpdb->prefix}hl_coaching_session cs
                 LEFT JOIN {$wpdb->users} u_coach ON cs.coach_user_id = u_coach.ID
                 JOIN {$wpdb->prefix}hl_enrollment e ON cs.mentor_enrollment_id = e.enrollment_id
                 LEFT JOIN {$wpdb->users} u_mentor ON e.user_id = u_mentor.ID
                 LEFT JOIN {$wpdb->prefix}hl_cycle t ON cs.cycle_id = t.cycle_id
                 ORDER BY cs.created_at DESC",
                ARRAY_A
            ) ?: array();
        }

        if (empty($sessions)) {
            echo '<p>' . esc_html__('No coaching sessions found.', 'hl-core') . '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('ID', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Title', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Date/Time', 'hl-core') . '</th>';
        if (!$filter_cycle) {
            echo '<th>' . esc_html__('Cycle', 'hl-core') . '</th>';
        }
        echo '<th>' . esc_html__('Participant', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Coach', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Status', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Observations', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Actions', 'hl-core') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        $coaching_service = new HL_Coaching_Service();

        foreach ($sessions as $session) {
            $edit_url   = admin_url('admin.php?page=hl-coaching&action=edit&session_id=' . $session['session_id']);
            $delete_url = wp_nonce_url(
                admin_url('admin.php?page=hl-coaching&action=delete&session_id=' . $session['session_id']),
                'hl_delete_coaching_session_' . $session['session_id']
            );

            // Status badge
            $status_badge = HL_Coaching_Service::render_status_badge($session['session_status'] ?? 'scheduled');

            // Observation count
            $obs_count = $coaching_service->get_linked_observation_count($session['session_id']);

            // Format session datetime
            $session_date_display = !empty($session['session_datetime'])
                ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($session['session_datetime']))
                : '<em>' . esc_html__('Not set', 'hl-core') . '</em>';

            echo '<tr>';
            echo '<td>' . esc_html($session['session_id']) . '</td>';
            echo '<td>' . esc_html($session['session_title'] ?? '') . '</td>';
            echo '<td>' . $session_date_display . '</td>';
            if (!$filter_cycle) {
                echo '<td>' . esc_html(isset($session['cycle_name']) ? $session['cycle_name'] : '-') . '</td>';
            }
            $mentor_uid = isset($session['mentor_user_id']) ? (int) $session['mentor_user_id'] : 0;
            $mentor_badge = $mentor_uid && HL_BuddyBoss_Integration::is_user_suspended($mentor_uid)
                ? ' <span class="hl-status-badge suspended">' . esc_html__('Suspended', 'hl-core') . '</span>'
                : '';
            echo '<td>' . esc_html($session['mentor_name'] ?: '-') . $mentor_badge . '</td>';
            echo '<td>' . esc_html($session['coach_name'] ?: '-') . '</td>';
            echo '<td>' . $status_badge . '</td>';
            echo '<td>' . esc_html($obs_count) . '</td>';
            echo '<td>';
            echo '<a href="' . esc_url($edit_url) . '" class="button button-small">' . esc_html__('Edit', 'hl-core') . '</a> ';
            echo '<a href="' . esc_url($delete_url) . '" class="button button-small button-link-delete" onclick="return confirm(\'' . esc_js(__('Are you sure you want to delete this coaching session? This will also remove linked observations and attachments.', 'hl-core')) . '\');">' . esc_html__('Delete', 'hl-core') . '</a>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    // =========================================================================
    // Create / Edit Form
    // =========================================================================

    /**
     * Render create/edit form
     *
     * @param array|null $session Session data for edit, null for create
     */
    private function render_form($session = null) {
        global $wpdb;

        $is_edit = ($session !== null);
        $title   = $is_edit ? __('Edit Coaching Session', 'hl-core') : __('Add New Coaching Session', 'hl-core');

        // Show success/error messages
        $this->render_messages();

        echo '<h1>' . esc_html($title) . '</h1>';
        echo '<a href="' . esc_url(admin_url('admin.php?page=hl-coaching')) . '">&larr; ' . esc_html__('Back to Coaching Sessions', 'hl-core') . '</a>';

        // Enqueue WP Media for attachment uploads (only on edit)
        if ($is_edit) {
            wp_enqueue_media();
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=hl-coaching')) . '" id="hl-coaching-session-form">';
        wp_nonce_field('hl_save_coaching_session', 'hl_coaching_session_nonce');

        if ($is_edit) {
            echo '<input type="hidden" name="session_id" value="' . esc_attr($session['session_id']) . '" />';
        }

        echo '<table class="form-table">';

        // ---- Cycle ----
        if ($is_edit) {
            // Cycle is read-only on edit
            echo '<tr>';
            echo '<th scope="row">' . esc_html__('Cycle', 'hl-core') . '</th>';
            echo '<td><strong>' . esc_html($session['cycle_name']) . '</strong>';
            echo '<input type="hidden" name="cycle_id" value="' . esc_attr($session['cycle_id']) . '" />';
            echo '</td>';
            echo '</tr>';
        } else {
            $cycles = $wpdb->get_results(
                "SELECT cycle_id, cycle_name FROM {$wpdb->prefix}hl_cycle ORDER BY cycle_name ASC"
            );
            echo '<tr>';
            echo '<th scope="row"><label for="cycle_id">' . esc_html__('Cycle', 'hl-core') . '</label></th>';
            echo '<td><select id="cycle_id" name="cycle_id" required>';
            echo '<option value="">' . esc_html__('-- Select Cycle --', 'hl-core') . '</option>';
            if ($cycles) {
                foreach ($cycles as $cycle) {
                    echo '<option value="' . esc_attr($cycle->cycle_id) . '">' . esc_html($cycle->cycle_name) . '</option>';
                }
            }
            echo '</select>';
            echo '<p class="description">' . esc_html__('Select a cycle first, then choose a mentor from that cycle.', 'hl-core') . '</p>';
            echo '</td>';
            echo '</tr>';
        }

        // ---- Mentor ----
        if ($is_edit) {
            // Mentor is read-only on edit
            echo '<tr>';
            echo '<th scope="row">' . esc_html__('Mentor', 'hl-core') . '</th>';
            echo '<td><strong>' . esc_html($session['mentor_name']) . '</strong>';
            echo '<input type="hidden" name="mentor_enrollment_id" value="' . esc_attr($session['mentor_enrollment_id']) . '" />';
            echo '</td>';
            echo '</tr>';
        } else {
            // Will be populated by JavaScript when cycle is selected
            echo '<tr>';
            echo '<th scope="row"><label for="mentor_enrollment_id">' . esc_html__('Mentor', 'hl-core') . '</label></th>';
            echo '<td><select id="mentor_enrollment_id" name="mentor_enrollment_id" required>';
            echo '<option value="">' . esc_html__('-- Select Cycle First --', 'hl-core') . '</option>';
            echo '</select></td>';
            echo '</tr>';
        }

        // ---- Coach ----
        $coaches = $this->get_staff_users();
        $current_coach = $is_edit ? $session['coach_user_id'] : get_current_user_id();

        echo '<tr>';
        echo '<th scope="row"><label for="coach_user_id">' . esc_html__('Coach', 'hl-core') . '</label></th>';
        echo '<td><select id="coach_user_id" name="coach_user_id" required>';
        if ($coaches) {
            foreach ($coaches as $coach) {
                echo '<option value="' . esc_attr($coach->ID) . '"' . selected($current_coach, $coach->ID, false) . '>' . esc_html($coach->display_name) . '</option>';
            }
        }
        echo '</select></td>';
        echo '</tr>';

        // ---- Session Title ----
        echo '<tr>';
        echo '<th scope="row"><label for="session_title">' . esc_html__('Session Title', 'hl-core') . '</label></th>';
        echo '<td><input type="text" id="session_title" name="session_title" value="' . esc_attr($is_edit ? ($session['session_title'] ?? '') : '') . '" class="regular-text" />';
        echo '<p class="description">' . esc_html__('Optional title for this session (e.g., "Week 3 Check-in").', 'hl-core') . '</p>';
        echo '</td>';
        echo '</tr>';

        // ---- Session Date/Time ----
        $session_datetime = '';
        if ($is_edit && !empty($session['session_datetime'])) {
            // Convert to datetime-local format
            $session_datetime = date('Y-m-d\TH:i', strtotime($session['session_datetime']));
        }

        echo '<tr>';
        echo '<th scope="row"><label for="session_datetime">' . esc_html__('Session Date/Time', 'hl-core') . '</label></th>';
        echo '<td><input type="datetime-local" id="session_datetime" name="session_datetime" value="' . esc_attr($session_datetime) . '" class="regular-text" /></td>';
        echo '</tr>';

        // ---- Meeting URL ----
        echo '<tr>';
        echo '<th scope="row"><label for="meeting_url">' . esc_html__('Meeting URL', 'hl-core') . '</label></th>';
        echo '<td><input type="url" id="meeting_url" name="meeting_url" value="' . esc_attr($is_edit ? ($session['meeting_url'] ?? '') : '') . '" class="regular-text" placeholder="https://" />';
        echo '<p class="description">' . esc_html__('Optional link to the virtual meeting (Zoom, Teams, etc.).', 'hl-core') . '</p>';
        echo '</td>';
        echo '</tr>';

        // ---- Session Status ----
        $current_status = $is_edit ? ($session['session_status'] ?? 'scheduled') : 'scheduled';
        $is_terminal = in_array($current_status, HL_Coaching_Service::TERMINAL_STATUSES, true);

        echo '<tr>';
        echo '<th scope="row"><label for="session_status">' . esc_html__('Session Status', 'hl-core') . '</label></th>';
        echo '<td>';

        if ($is_terminal) {
            // Terminal status — show read-only badge
            echo HL_Coaching_Service::render_status_badge($current_status);
            echo '<input type="hidden" name="session_status" value="' . esc_attr($current_status) . '" />';
            echo '<p class="description">' . esc_html__('This session has a terminal status and cannot be changed.', 'hl-core') . '</p>';
        } else {
            $status_options = array(
                'scheduled' => __('Scheduled', 'hl-core'),
                'attended'  => __('Attended', 'hl-core'),
                'missed'    => __('Missed', 'hl-core'),
                'cancelled' => __('Cancelled', 'hl-core'),
            );

            echo '<select id="session_status" name="session_status">';
            foreach ($status_options as $value => $label) {
                echo '<option value="' . esc_attr($value) . '"' . selected($current_status, $value, false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select>';
        }

        echo '</td>';
        echo '</tr>';

        // ---- Notes (Rich Text) ----
        echo '<tr>';
        echo '<th scope="row"><label for="notes_richtext">' . esc_html__('Notes', 'hl-core') . '</label></th>';
        echo '<td>';

        $notes_content = $is_edit ? ($session['notes_richtext'] ?: '') : '';
        wp_editor($notes_content, 'notes_richtext', array(
            'textarea_name' => 'notes_richtext',
            'media_buttons' => false,
            'textarea_rows' => 8,
            'teeny'         => true,
            'quicktags'     => true,
        ));

        echo '</td>';
        echo '</tr>';

        echo '</table>';

        submit_button($is_edit ? __('Update Session', 'hl-core') : __('Create Session', 'hl-core'));
        echo '</form>';

        // ---- Edit-only sections: Linked Observations & Attachments ----
        if ($is_edit) {
            $this->render_linked_observations_section($session);
            $this->render_attachments_section($session);
        }

        // Render JavaScript for cycle-dependent mentor dropdown (create only)
        if (!$is_edit) {
            $this->render_mentor_dropdown_js();
        }

        // Render JavaScript for WP Media attachment picker (edit only)
        if ($is_edit) {
            $this->render_media_picker_js($session['session_id']);
        }
    }

    // =========================================================================
    // Linked Observations Section
    // =========================================================================

    /**
     * Render the linked observations section on the edit form
     *
     * @param array $session Session data
     */
    private function render_linked_observations_section($session) {
        $service = new HL_Coaching_Service();

        echo '<hr />';
        echo '<h2>' . esc_html__('Linked Observations', 'hl-core') . '</h2>';
        echo '<p class="description">' . esc_html__('Observations discussed during this coaching session.', 'hl-core') . '</p>';

        // Currently linked observations
        $linked = $service->get_linked_observations($session['session_id']);

        if (!empty($linked)) {
            echo '<table class="widefat striped" style="margin-bottom:15px;">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__('ID', 'hl-core') . '</th>';
            echo '<th>' . esc_html__('Teacher', 'hl-core') . '</th>';
            echo '<th>' . esc_html__('Date', 'hl-core') . '</th>';
            echo '<th>' . esc_html__('Status', 'hl-core') . '</th>';
            echo '<th>' . esc_html__('Actions', 'hl-core') . '</th>';
            echo '</tr></thead>';
            echo '<tbody>';

            foreach ($linked as $obs) {
                $unlink_url = wp_nonce_url(
                    admin_url('admin.php?page=hl-coaching&action=edit&session_id=' . $session['session_id'] . '&unlink_observation=' . $obs['observation_id']),
                    'hl_unlink_observation_' . $obs['observation_id']
                );

                $obs_date = !empty($obs['submitted_at'])
                    ? date_i18n(get_option('date_format'), strtotime($obs['submitted_at']))
                    : date_i18n(get_option('date_format'), strtotime($obs['created_at']));

                $status_badge = ($obs['status'] === 'submitted')
                    ? '<span class="hl-status-badge active">' . esc_html__('Submitted', 'hl-core') . '</span>'
                    : '<span class="hl-status-badge draft">' . esc_html__('Draft', 'hl-core') . '</span>';

                echo '<tr>';
                echo '<td>' . esc_html($obs['observation_id']) . '</td>';
                echo '<td>' . esc_html($obs['teacher_name'] ?: '-') . '</td>';
                echo '<td>' . esc_html($obs_date) . '</td>';
                echo '<td>' . $status_badge . '</td>';
                echo '<td><a href="' . esc_url($unlink_url) . '" class="button button-small button-link-delete" onclick="return confirm(\'' . esc_js(__('Unlink this observation from the session?', 'hl-core')) . '\');">' . esc_html__('Unlink', 'hl-core') . '</a></td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        } else {
            echo '<p style="margin-bottom:15px;"><em>' . esc_html__('No observations linked to this session.', 'hl-core') . '</em></p>';
        }

        // Link observation form
        $available = $service->get_available_observations(
            $session['session_id'],
            $session['cycle_id'],
            $session['mentor_enrollment_id']
        );

        if (!empty($available)) {
            echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=hl-coaching')) . '" style="display:flex; align-items:center; gap:10px;">';
            wp_nonce_field('hl_link_observation', 'hl_link_observation_nonce');
            echo '<input type="hidden" name="session_id" value="' . esc_attr($session['session_id']) . '" />';

            echo '<select name="observation_id" required>';
            echo '<option value="">' . esc_html__('-- Select Observation --', 'hl-core') . '</option>';
            foreach ($available as $obs) {
                $obs_label = sprintf(
                    '#%d - %s (%s)',
                    $obs['observation_id'],
                    $obs['teacher_name'] ?: __('Unknown Teacher', 'hl-core'),
                    !empty($obs['submitted_at'])
                        ? date_i18n(get_option('date_format'), strtotime($obs['submitted_at']))
                        : __('No date', 'hl-core')
                );
                echo '<option value="' . esc_attr($obs['observation_id']) . '">' . esc_html($obs_label) . '</option>';
            }
            echo '</select>';

            echo '<button type="submit" class="button">' . esc_html__('Link Observation', 'hl-core') . '</button>';
            echo '</form>';
        } else {
            echo '<p class="description">' . esc_html__('No additional submitted observations available for this mentor in this cycle.', 'hl-core') . '</p>';
        }
    }

    // =========================================================================
    // Attachments Section
    // =========================================================================

    /**
     * Render the attachments section on the edit form
     *
     * @param array $session Session data
     */
    private function render_attachments_section($session) {
        $service = new HL_Coaching_Service();

        echo '<hr />';
        echo '<h2>' . esc_html__('Attachments', 'hl-core') . '</h2>';

        // Current attachments
        $attachments = $service->get_attachments($session['session_id']);

        if (!empty($attachments)) {
            echo '<table class="widefat striped" style="margin-bottom:15px;">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__('File', 'hl-core') . '</th>';
            echo '<th>' . esc_html__('Type', 'hl-core') . '</th>';
            echo '<th>' . esc_html__('Actions', 'hl-core') . '</th>';
            echo '</tr></thead>';
            echo '<tbody>';

            foreach ($attachments as $att) {
                $remove_url = wp_nonce_url(
                    admin_url('admin.php?page=hl-coaching&action=edit&session_id=' . $session['session_id'] . '&remove_attachment=' . $att['attachment_id']),
                    'hl_remove_attachment_' . $att['attachment_id']
                );

                echo '<tr>';
                echo '<td><a href="' . esc_url($att['current_url']) . '" target="_blank">' . esc_html($att['filename']) . '</a></td>';
                echo '<td>' . esc_html($att['mime_type'] ?: '-') . '</td>';
                echo '<td><a href="' . esc_url($remove_url) . '" class="button button-small button-link-delete" onclick="return confirm(\'' . esc_js(__('Remove this attachment?', 'hl-core')) . '\');">' . esc_html__('Remove', 'hl-core') . '</a></td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        } else {
            echo '<p><em>' . esc_html__('No attachments.', 'hl-core') . '</em></p>';
        }

        // Add attachment button (opens WP Media Library via JS)
        echo '<button type="button" id="hl-add-attachment-btn" class="button">' . esc_html__('Add Attachment', 'hl-core') . '</button>';

        // Hidden form for attachment submission
        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=hl-coaching')) . '" id="hl-attachment-form" style="display:none;">';
        wp_nonce_field('hl_add_attachment', 'hl_add_attachment_nonce');
        echo '<input type="hidden" name="session_id" value="' . esc_attr($session['session_id']) . '" />';
        echo '<input type="hidden" name="wp_media_id" id="hl-wp-media-id" value="" />';
        echo '</form>';
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    /**
     * Get users with manage_hl_core capability (staff/coaches)
     *
     * @return array Array of WP_User objects
     */
    private function get_staff_users() {
        // Get administrators and coaches
        $admins = get_users(array(
            'role'    => 'administrator',
            'orderby' => 'display_name',
            'order'   => 'ASC',
        ));

        $coaches = get_users(array(
            'role'    => 'hl_coach',
            'orderby' => 'display_name',
            'order'   => 'ASC',
        ));

        // Merge and deduplicate
        $users     = array_merge($admins, $coaches);
        $seen_ids  = array();
        $unique    = array();

        foreach ($users as $user) {
            if (!in_array($user->ID, $seen_ids, true)) {
                $seen_ids[] = $user->ID;
                $unique[]   = $user;
            }
        }

        return $unique;
    }

    /**
     * Render an attendance status badge
     *
     * @param string $status 'attended', 'missed', or 'unknown'
     * @return string HTML badge
     */
    private function render_attendance_badge($status) {
        switch ($status) {
            case 'attended':
                return '<span style="display:inline-block;padding:3px 10px;border-radius:3px;font-size:12px;font-weight:600;background:#d4edda;color:#155724;">' . esc_html__('Attended', 'hl-core') . '</span>';
            case 'missed':
                return '<span style="display:inline-block;padding:3px 10px;border-radius:3px;font-size:12px;font-weight:600;background:#f8d7da;color:#721c24;">' . esc_html__('Missed', 'hl-core') . '</span>';
            default:
                return '<span style="display:inline-block;padding:3px 10px;border-radius:3px;font-size:12px;font-weight:600;background:#e2e3e5;color:#383d41;">' . esc_html__('Unknown', 'hl-core') . '</span>';
        }
    }

    /**
     * Render success/error messages based on $_GET['message']
     */
    private function render_messages() {
        if (!isset($_GET['message'])) {
            return;
        }

        $msg = sanitize_text_field($_GET['message']);

        $messages = array(
            'created'              => array('success', __('Coaching session created successfully.', 'hl-core')),
            'updated'              => array('success', __('Coaching session updated successfully.', 'hl-core')),
            'error'                => array('error',   __('An error occurred. Please try again.', 'hl-core')),
            'observation_linked'   => array('success', __('Observation linked successfully.', 'hl-core')),
            'observation_unlinked' => array('success', __('Observation unlinked successfully.', 'hl-core')),
            'attachment_added'     => array('success', __('Attachment added successfully.', 'hl-core')),
            'attachment_removed'   => array('success', __('Attachment removed successfully.', 'hl-core')),
            'deleted'              => array('success', __('Coaching session deleted successfully.', 'hl-core')),
        );

        if (isset($messages[$msg])) {
            echo '<div class="notice notice-' . esc_attr($messages[$msg][0]) . ' is-dismissible"><p>' . esc_html($messages[$msg][1]) . '</p></div>';
        }
    }

    // =========================================================================
    // JavaScript
    // =========================================================================

    /**
     * Render JavaScript for cycle-dependent mentor dropdown on the create form
     *
     * When the cycle is changed, fetches enrolled mentors via an inline data
     * approach (pre-loads all cycle enrollment data as JSON) to avoid AJAX dependencies.
     */
    private function render_mentor_dropdown_js() {
        global $wpdb;

        // Pre-load all cycle enrollments grouped by cycle_id
        // Only load users with Mentor role (roles JSON contains "Mentor")
        $all_enrollments = $wpdb->get_results(
            "SELECT e.enrollment_id, e.cycle_id, e.roles, u.display_name, u.user_email
             FROM {$wpdb->prefix}hl_enrollment e
             LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
             WHERE e.status = 'active'
             ORDER BY u.display_name ASC"
        );

        $cycle_mentors = array();
        foreach ($all_enrollments as $e) {
            $roles = json_decode($e->roles, true);
            if (!is_array($roles) || !in_array('Mentor', $roles)) {
                continue;
            }
            if (!isset($cycle_mentors[$e->cycle_id])) {
                $cycle_mentors[$e->cycle_id] = array();
            }
            $cycle_mentors[$e->cycle_id][] = array(
                'enrollment_id' => $e->enrollment_id,
                'display_name'  => $e->display_name,
                'user_email'    => $e->user_email,
            );
        }

        $json_data = wp_json_encode($cycle_mentors);

        ?>
        <script type="text/javascript">
        (function() {
            var cycleMentors = <?php echo $json_data; ?>;
            var cycleSelect  = document.getElementById('cycle_id');
            var mentorSelect  = document.getElementById('mentor_enrollment_id');

            if (!cycleSelect || !mentorSelect) return;

            cycleSelect.addEventListener('change', function() {
                var cycleId = this.value;
                mentorSelect.innerHTML = '';

                if (!cycleId || !cycleMentors[cycleId]) {
                    var opt = document.createElement('option');
                    opt.value = '';
                    opt.textContent = cycleId
                        ? '<?php echo esc_js(__('No mentors found in this cycle', 'hl-core')); ?>'
                        : '<?php echo esc_js(__('-- Select Cycle First --', 'hl-core')); ?>';
                    mentorSelect.appendChild(opt);
                    return;
                }

                var defaultOpt = document.createElement('option');
                defaultOpt.value = '';
                defaultOpt.textContent = '<?php echo esc_js(__('-- Select Mentor --', 'hl-core')); ?>';
                mentorSelect.appendChild(defaultOpt);

                var mentors = cycleMentors[cycleId];
                for (var i = 0; i < mentors.length; i++) {
                    var opt = document.createElement('option');
                    opt.value = mentors[i].enrollment_id;
                    opt.textContent = mentors[i].display_name + ' (' + mentors[i].user_email + ')';
                    mentorSelect.appendChild(opt);
                }
            });
        })();
        </script>
        <?php
    }

    /**
     * Render JavaScript for WP Media Library attachment picker
     *
     * @param int $session_id
     */
    private function render_media_picker_js($session_id) {
        ?>
        <script type="text/javascript">
        (function($) {
            if (typeof $ === 'undefined') return;

            var addBtn = document.getElementById('hl-add-attachment-btn');
            if (!addBtn) return;

            var mediaFrame;

            addBtn.addEventListener('click', function(e) {
                e.preventDefault();

                if (mediaFrame) {
                    mediaFrame.open();
                    return;
                }

                mediaFrame = wp.media({
                    title: '<?php echo esc_js(__('Select Attachment', 'hl-core')); ?>',
                    button: {
                        text: '<?php echo esc_js(__('Add Attachment', 'hl-core')); ?>'
                    },
                    multiple: false
                });

                mediaFrame.on('select', function() {
                    var attachment = mediaFrame.state().get('selection').first().toJSON();
                    var mediaIdInput = document.getElementById('hl-wp-media-id');
                    var form = document.getElementById('hl-attachment-form');

                    if (mediaIdInput && form) {
                        mediaIdInput.value = attachment.id;
                        form.submit();
                    }
                });

                mediaFrame.open();
            });
        })(typeof jQuery !== 'undefined' ? jQuery : undefined);
        </script>
        <?php
    }

    // =========================================================================
    // Coaches Tab
    // =========================================================================

    /**
     * Handle POST/GET actions for the Coaches tab (redirect-before-output).
     */
    private function handle_coaches_actions() {
        // Add coach
        if (isset($_POST['hl_add_coach']) && wp_verify_nonce($_POST['_wpnonce'], 'hl_add_coach')) {
            if (!current_user_can('manage_hl_core')) {
                wp_die(__('You do not have permission to perform this action.', 'hl-core'));
            }

            $email = sanitize_email($_POST['coach_email'] ?? '');
            $name  = sanitize_text_field($_POST['coach_name'] ?? '');

            if ($email) {
                $existing = get_user_by('email', $email);
                if ($existing) {
                    $existing->add_role('coach');
                } else {
                    $user_id = wp_insert_user(array(
                        'user_login'   => $email,
                        'user_email'   => $email,
                        'display_name' => $name ? $name : $email,
                        'role'         => 'coach',
                        'user_pass'    => wp_generate_password(),
                    ));
                    if (!is_wp_error($user_id)) {
                        wp_new_user_notification($user_id, null, 'user');
                    }
                }
            }

            wp_redirect(admin_url('admin.php?page=hl-coaching&tab=coaches&message=coach_added'));
            exit;
        }

        // Remove coach role
        if (isset($_GET['action']) && $_GET['action'] === 'remove_coach' && isset($_GET['user_id'])) {
            if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'hl_remove_coach')) {
                wp_die(__('Security check failed.', 'hl-core'));
            }
            if (!current_user_can('manage_hl_core')) {
                wp_die(__('You do not have permission to perform this action.', 'hl-core'));
            }

            $user = get_user_by('id', absint($_GET['user_id']));
            if ($user) {
                $user->remove_role('coach');
            }

            wp_redirect(admin_url('admin.php?page=hl-coaching&tab=coaches&message=coach_removed'));
            exit;
        }
    }

    /**
     * Render the Coaches tab content: list + add form.
     */
    private function render_coaches_content() {
        $this->render_coaches_messages();

        $coaches = get_users(array('role' => 'coach'));

        echo '<h2>' . esc_html__('Coaches', 'hl-core') . '</h2>';

        // --- Coaches table ---
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Name', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Email', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Actions', 'hl-core') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        if (empty($coaches)) {
            echo '<tr><td colspan="3">' . esc_html__('No coaches found.', 'hl-core') . '</td></tr>';
        } else {
            foreach ($coaches as $coach) {
                $remove_url = wp_nonce_url(
                    admin_url('admin.php?page=hl-coaching&tab=coaches&action=remove_coach&user_id=' . $coach->ID),
                    'hl_remove_coach'
                );
                echo '<tr>';
                echo '<td>' . esc_html($coach->display_name) . '</td>';
                echo '<td>' . esc_html($coach->user_email) . '</td>';
                echo '<td><a href="' . esc_url($remove_url) . '" class="button button-small" onclick="return confirm(\'' . esc_js(__('Remove Coach role from this user?', 'hl-core')) . '\')">' . esc_html__('Remove Coach', 'hl-core') . '</a></td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table>';

        // --- Add Coach form ---
        echo '<h3 style="margin-top:20px;">' . esc_html__('Add Coach', 'hl-core') . '</h3>';
        echo '<form method="post">';
        wp_nonce_field('hl_add_coach');
        echo '<table class="form-table">';
        echo '<tr>';
        echo '<th scope="row"><label for="coach_name">' . esc_html__('Name', 'hl-core') . '</label></th>';
        echo '<td><input type="text" id="coach_name" name="coach_name" class="regular-text" /></td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th scope="row"><label for="coach_email">' . esc_html__('Email', 'hl-core') . '</label></th>';
        echo '<td><input type="email" id="coach_email" name="coach_email" class="regular-text" required /></td>';
        echo '</tr>';
        echo '</table>';
        echo '<p class="submit"><input type="submit" name="hl_add_coach" class="button button-primary" value="' . esc_attr__('Add Coach', 'hl-core') . '" /></p>';
        echo '</form>';
        echo '<p class="description">' . esc_html__('If the email matches an existing user, the Coach role will be added. Otherwise a new user will be created and notified.', 'hl-core') . '</p>';
    }

    /**
     * Render success/error messages for the Coaches tab.
     */
    private function render_coaches_messages() {
        $message = isset($_GET['message']) ? sanitize_text_field($_GET['message']) : '';
        if ($message === 'coach_added') {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Coach added successfully.', 'hl-core') . '</p></div>';
        } elseif ($message === 'coach_removed') {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Coach role removed.', 'hl-core') . '</p></div>';
        }
    }
}
