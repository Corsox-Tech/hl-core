<?php if (!defined('ABSPATH')) exit;

/**
 * Admin Assessments Page
 *
 * Staff-only viewer for Teacher Self-Assessments and Child Assessments.
 * Supports listing, detail viewing, instance generation, and CSV export.
 *
 * @package HL_Core
 */
class HL_Admin_Assessments {

    /** @var HL_Admin_Assessments|null */
    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_init', array($this, 'handle_csv_export'));
    }

    /**
     * Handle POST actions before any HTML output.
     */
    public function handle_early_actions() {
        $this->handle_actions();
    }

    /**
     * Main render entry point
     */
    public function render_page() {
        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : 'list';

        echo '<div class="wrap">';

        switch ($action) {
            case 'view_teacher':
                $instance_id = isset($_GET['instance_id']) ? absint($_GET['instance_id']) : 0;
                $this->render_teacher_assessment_detail($instance_id);
                break;

            case 'view_children':
                $instance_id = isset($_GET['instance_id']) ? absint($_GET['instance_id']) : 0;
                $this->render_child_assessment_detail($instance_id);
                break;

            default:
                $this->render_list();
                break;
        }

        echo '</div>';
    }

    // =========================================================================
    // Action Handlers
    // =========================================================================

    /**
     * Handle POST actions (generate instances)
     */
    private function handle_actions() {
        // Generate child assessment instances
        if (isset($_POST['hl_generate_children_nonce'])) {
            if (!wp_verify_nonce($_POST['hl_generate_children_nonce'], 'hl_generate_children_instances')) {
                wp_die(__('Security check failed.', 'hl-core'));
            }
            if (!current_user_can('manage_hl_core')) {
                wp_die(__('You do not have permission to perform this action.', 'hl-core'));
            }

            $track_id = absint($_POST['track_id']);
            $service = new HL_Assessment_Service();
            $result = $service->generate_child_assessment_instances($track_id);

            $msg = 'generated';
            if (!empty($result['errors'])) {
                $msg = 'generate_errors';
            } elseif ($result['created'] === 0 && $result['existing'] > 0) {
                $msg = 'generate_none';
            }

            wp_redirect(admin_url('admin.php?page=hl-assessments&track_id=' . $track_id . '&tab=children&message=' . $msg . '&created=' . $result['created'] . '&existing=' . $result['existing']));
            exit;
        }
    }

    /**
     * Handle CSV export (runs on admin_init before headers sent)
     */
    public function handle_csv_export() {
        if (!isset($_GET['page']) || $_GET['page'] !== 'hl-assessments') {
            return;
        }
        if (!isset($_GET['export'])) {
            return;
        }
        if (!current_user_can('manage_hl_core')) {
            return;
        }

        $export_type = sanitize_text_field($_GET['export']);
        $track_id   = isset($_GET['track_id']) ? absint($_GET['track_id']) : 0;

        if (!$track_id) {
            return;
        }

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'hl_export_' . $export_type . '_' . $track_id)) {
            return;
        }

        $service = new HL_Assessment_Service();

        global $wpdb;
        $track_name = $wpdb->get_var($wpdb->prepare(
            "SELECT track_name FROM {$wpdb->prefix}hl_track WHERE track_id = %d",
            $track_id
        ));
        $safe_name = sanitize_file_name($track_name ?: 'track-' . $track_id);

        if ($export_type === 'teacher') {
            $csv = $service->export_teacher_assessments_csv($track_id);
            $filename = 'teacher-assessments-' . $safe_name . '-' . date('Y-m-d') . '.csv';
        } elseif ($export_type === 'children') {
            $csv = $service->export_child_assessments_csv($track_id);
            $filename = 'child-assessments-' . $safe_name . '-' . date('Y-m-d') . '.csv';
        } else {
            return;
        }

        HL_Audit_Service::log('assessment.exported', array(
            'entity_type' => $export_type . '_assessment',
            'track_id'    => $track_id,
            'after_data'  => array('export_type' => $export_type),
        ));

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        echo $csv;
        exit;
    }

    // =========================================================================
    // Main List View (Tabbed)
    // =========================================================================

    private function render_list() {
        $service = new HL_Assessment_Service();

        // Track filter
        global $wpdb;
        $tracks = $wpdb->get_results(
            "SELECT track_id, track_name, status FROM {$wpdb->prefix}hl_track ORDER BY track_name ASC"
        );
        $selected_track = isset($_GET['track_id']) ? absint($_GET['track_id']) : 0;
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'teacher';

        // Messages
        $this->render_messages();

        echo '<h1>' . esc_html__('Assessments', 'hl-core') . '</h1>';

        // Track selector
        echo '<form method="get" style="margin-bottom:15px;">';
        echo '<input type="hidden" name="page" value="hl-assessments" />';
        echo '<input type="hidden" name="tab" value="' . esc_attr($active_tab) . '" />';
        echo '<label><strong>' . esc_html__('Track:', 'hl-core') . '</strong> </label>';
        echo '<select name="track_id">';
        echo '<option value="">' . esc_html__('-- Select Track --', 'hl-core') . '</option>';
        if ($tracks) {
            foreach ($tracks as $track_obj) {
                $label = $track_obj->track_name;
                if ($track_obj->status !== 'active') {
                    $label .= ' (' . ucfirst($track_obj->status) . ')';
                }
                echo '<option value="' . esc_attr($track_obj->track_id) . '"' . selected($selected_track, $track_obj->track_id, false) . '>' . esc_html($label) . '</option>';
            }
        }
        echo '</select> ';
        submit_button(__('View', 'hl-core'), 'secondary', 'submit', false);
        echo '</form>';

        if (!$selected_track) {
            echo '<p>' . esc_html__('Select a track to view assessments.', 'hl-core') . '</p>';
            return;
        }

        // Tab navigation
        $teacher_url  = admin_url('admin.php?page=hl-assessments&track_id=' . $selected_track . '&tab=teacher');
        $children_url = admin_url('admin.php?page=hl-assessments&track_id=' . $selected_track . '&tab=children');

        echo '<h2 class="nav-tab-wrapper">';
        echo '<a href="' . esc_url($teacher_url) . '" class="nav-tab' . ($active_tab === 'teacher' ? ' nav-tab-active' : '') . '">' . esc_html__('Teacher Self-Assessments', 'hl-core') . '</a>';
        echo '<a href="' . esc_url($children_url) . '" class="nav-tab' . ($active_tab === 'children' ? ' nav-tab-active' : '') . '">' . esc_html__('Child Assessments', 'hl-core') . '</a>';
        echo '</h2>';

        if ($active_tab === 'children') {
            $this->render_children_tab($selected_track, $service);
        } else {
            $this->render_teacher_tab($selected_track, $service);
        }
    }

    // =========================================================================
    // Teacher Self-Assessment Tab
    // =========================================================================

    private function render_teacher_tab($track_id, $service) {
        $instances = $service->get_teacher_assessments_by_track($track_id);

        // Export button
        $export_url = wp_nonce_url(
            admin_url('admin.php?page=hl-assessments&track_id=' . $track_id . '&export=teacher'),
            'hl_export_teacher_' . $track_id
        );
        echo '<p><a href="' . esc_url($export_url) . '" class="button">' . esc_html__('Export Teacher Assessments CSV', 'hl-core') . '</a></p>';

        if (empty($instances)) {
            echo '<p>' . esc_html__('No teacher assessment instances found for this track.', 'hl-core') . '</p>';
            return;
        }

        // Summary cards
        $total     = count($instances);
        $submitted = count(array_filter($instances, function($i) { return $i['status'] === 'submitted'; }));
        $pending   = $total - $submitted;

        echo '<div class="hl-metric-cards" style="display:flex;gap:15px;margin-bottom:20px;">';
        echo '<div class="hl-metric-card" style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:12px 20px;min-width:120px;">';
        echo '<div style="font-size:24px;font-weight:700;color:#2271b1;">' . esc_html($total) . '</div>';
        echo '<div style="color:#646970;">' . esc_html__('Total Instances', 'hl-core') . '</div></div>';
        echo '<div class="hl-metric-card" style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:12px 20px;min-width:120px;">';
        echo '<div style="font-size:24px;font-weight:700;color:#00a32a;">' . esc_html($submitted) . '</div>';
        echo '<div style="color:#646970;">' . esc_html__('Submitted', 'hl-core') . '</div></div>';
        echo '<div class="hl-metric-card" style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:12px 20px;min-width:120px;">';
        echo '<div style="font-size:24px;font-weight:700;color:#dba617;">' . esc_html($pending) . '</div>';
        echo '<div style="color:#646970;">' . esc_html__('Pending', 'hl-core') . '</div></div>';
        echo '</div>';

        // Table
        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('ID', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Teacher', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Email', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Phase', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Status', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Submitted At', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Actions', 'hl-core') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($instances as $inst) {
            $view_url = admin_url('admin.php?page=hl-assessments&action=view_teacher&instance_id=' . $inst['instance_id'] . '&track_id=' . $track_id);

            echo '<tr>';
            echo '<td>' . esc_html($inst['instance_id']) . '</td>';
            echo '<td>' . esc_html($inst['display_name']) . '</td>';
            echo '<td>' . esc_html($inst['user_email']) . '</td>';
            echo '<td><span style="text-transform:uppercase;font-weight:600;">' . esc_html($inst['phase']) . '</span></td>';
            echo '<td>' . $this->render_status_badge($inst['status']) . '</td>';
            echo '<td>' . esc_html($inst['submitted_at'] ?: '-') . '</td>';
            echo '<td><a href="' . esc_url($view_url) . '" class="button button-small">' . esc_html__('View', 'hl-core') . '</a></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    // =========================================================================
    // Child Assessment Tab
    // =========================================================================

    private function render_children_tab($track_id, $service) {
        $instances = $service->get_child_assessments_by_track($track_id);

        // Action buttons row
        echo '<div style="display:flex;gap:10px;margin-bottom:15px;align-items:center;">';

        // Generate instances button
        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=hl-assessments&track_id=' . $track_id . '&tab=children')) . '" style="display:inline;">';
        wp_nonce_field('hl_generate_children_instances', 'hl_generate_children_nonce');
        echo '<input type="hidden" name="track_id" value="' . esc_attr($track_id) . '" />';
        submit_button(
            __('Generate Instances from Teaching Assignments', 'hl-core'),
            'secondary',
            'submit',
            false,
            array('onclick' => 'return confirm("' . esc_js(__('This will create child assessment instances for all teaching assignments in this track. Continue?', 'hl-core')) . '");')
        );
        echo '</form>';

        // Export button
        $export_url = wp_nonce_url(
            admin_url('admin.php?page=hl-assessments&track_id=' . $track_id . '&export=children'),
            'hl_export_children_' . $track_id
        );
        echo '<a href="' . esc_url($export_url) . '" class="button">' . esc_html__('Export Child Assessments CSV', 'hl-core') . '</a>';

        echo '</div>';

        if (empty($instances)) {
            echo '<p>' . esc_html__('No child assessment instances found for this track. Use "Generate Instances" to create them from teaching assignments.', 'hl-core') . '</p>';
            return;
        }

        // Summary cards
        $total     = count($instances);
        $submitted = count(array_filter($instances, function($i) { return $i['status'] === 'submitted'; }));
        $in_progress = count(array_filter($instances, function($i) { return $i['status'] === 'in_progress'; }));
        $not_started = $total - $submitted - $in_progress;

        echo '<div class="hl-metric-cards" style="display:flex;gap:15px;margin-bottom:20px;">';
        echo '<div class="hl-metric-card" style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:12px 20px;min-width:120px;">';
        echo '<div style="font-size:24px;font-weight:700;color:#2271b1;">' . esc_html($total) . '</div>';
        echo '<div style="color:#646970;">' . esc_html__('Total Instances', 'hl-core') . '</div></div>';
        echo '<div class="hl-metric-card" style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:12px 20px;min-width:120px;">';
        echo '<div style="font-size:24px;font-weight:700;color:#00a32a;">' . esc_html($submitted) . '</div>';
        echo '<div style="color:#646970;">' . esc_html__('Submitted', 'hl-core') . '</div></div>';
        echo '<div class="hl-metric-card" style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:12px 20px;min-width:120px;">';
        echo '<div style="font-size:24px;font-weight:700;color:#2271b1;">' . esc_html($in_progress) . '</div>';
        echo '<div style="color:#646970;">' . esc_html__('In Progress', 'hl-core') . '</div></div>';
        echo '<div class="hl-metric-card" style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:12px 20px;min-width:120px;">';
        echo '<div style="font-size:24px;font-weight:700;color:#dba617;">' . esc_html($not_started) . '</div>';
        echo '<div style="color:#646970;">' . esc_html__('Not Started', 'hl-core') . '</div></div>';
        echo '</div>';

        // Table
        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('ID', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Teacher', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Classroom', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('School', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Age Band', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Instrument', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Status', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Submitted At', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Actions', 'hl-core') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($instances as $inst) {
            $view_url = admin_url('admin.php?page=hl-assessments&action=view_children&instance_id=' . $inst['instance_id'] . '&track_id=' . $track_id);

            $age_band_display = $inst['instrument_age_band'] ? ucfirst($inst['instrument_age_band']) : '<em style="color:#b32d2e;">' . esc_html__('Needs Review', 'hl-core') . '</em>';
            $instrument_display = $inst['instrument_id'] ? esc_html($inst['instrument_id'] . ' (v' . $inst['instrument_version'] . ')') : '-';

            echo '<tr>';
            echo '<td>' . esc_html($inst['instance_id']) . '</td>';
            echo '<td>' . esc_html($inst['display_name']) . '</td>';
            echo '<td>' . esc_html($inst['classroom_name']) . '</td>';
            echo '<td>' . esc_html($inst['school_name']) . '</td>';
            echo '<td>' . $age_band_display . '</td>';
            echo '<td>' . $instrument_display . '</td>';
            echo '<td>' . $this->render_status_badge($inst['status']) . '</td>';
            echo '<td>' . esc_html($inst['submitted_at'] ?: '-') . '</td>';
            echo '<td><a href="' . esc_url($view_url) . '" class="button button-small">' . esc_html__('View', 'hl-core') . '</a></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    // =========================================================================
    // Teacher Assessment Detail View
    // =========================================================================

    private function render_teacher_assessment_detail($instance_id) {
        $service  = new HL_Assessment_Service();
        $instance = $service->get_teacher_assessment($instance_id);

        if (!$instance) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Assessment instance not found.', 'hl-core') . '</p></div>';
            return;
        }

        $track_id = $instance['track_id'];
        $back_url  = admin_url('admin.php?page=hl-assessments&track_id=' . $track_id . '&tab=teacher');

        echo '<h1>' . esc_html__('Teacher Self-Assessment Detail', 'hl-core') . '</h1>';
        echo '<a href="' . esc_url($back_url) . '">&larr; ' . esc_html__('Back to Assessments', 'hl-core') . '</a>';

        // Instance info
        echo '<table class="form-table">';
        echo '<tr><th>' . esc_html__('Instance ID', 'hl-core') . '</th><td>' . esc_html($instance['instance_id']) . '</td></tr>';
        echo '<tr><th>' . esc_html__('Track', 'hl-core') . '</th><td>' . esc_html($instance['track_name']) . '</td></tr>';
        echo '<tr><th>' . esc_html__('Teacher', 'hl-core') . '</th><td>' . esc_html($instance['display_name']) . ' (' . esc_html($instance['user_email']) . ')</td></tr>';
        echo '<tr><th>' . esc_html__('Phase', 'hl-core') . '</th><td><strong>' . esc_html(strtoupper($instance['phase'])) . '</strong></td></tr>';
        echo '<tr><th>' . esc_html__('Status', 'hl-core') . '</th><td>' . $this->render_status_badge($instance['status']) . '</td></tr>';
        echo '<tr><th>' . esc_html__('Submitted At', 'hl-core') . '</th><td>' . esc_html($instance['submitted_at'] ?: 'Not yet submitted') . '</td></tr>';
        echo '<tr><th>' . esc_html__('Created At', 'hl-core') . '</th><td>' . esc_html($instance['created_at']) . '</td></tr>';
        echo '</table>';

        // Responses (only visible to staff)
        $responses = $service->get_teacher_assessment_responses($instance_id);

        echo '<hr />';
        echo '<h2>' . esc_html__('Responses', 'hl-core') . '</h2>';

        if (empty($responses)) {
            echo '<p>' . esc_html__('No responses recorded yet.', 'hl-core') . '</p>';

            if ($instance['status'] !== 'submitted') {
                echo '<p class="description">';
                if ($instance['instrument_id']) {
                    echo esc_html__('Responses will be recorded when the teacher submits the assessment.', 'hl-core');
                } else {
                    echo esc_html__('This instance may use JetFormBuilder for form rendering. Check JFB Form Records for responses.', 'hl-core');
                }
                echo '</p>';
            }
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th style="width:200px;">' . esc_html__('Question ID', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Response', 'hl-core') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($responses as $resp) {
            echo '<tr>';
            echo '<td><code>' . esc_html($resp['question_id']) . '</code></td>';
            echo '<td>' . esc_html($resp['value']) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    // =========================================================================
    // Child Assessment Detail View
    // =========================================================================

    private function render_child_assessment_detail($instance_id) {
        $service  = new HL_Assessment_Service();
        $instance = $service->get_child_assessment($instance_id);

        if (!$instance) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Assessment instance not found.', 'hl-core') . '</p></div>';
            return;
        }

        $track_id = $instance['track_id'];
        $back_url  = admin_url('admin.php?page=hl-assessments&track_id=' . $track_id . '&tab=children');

        echo '<h1>' . esc_html__('Child Assessment Detail', 'hl-core') . '</h1>';
        echo '<a href="' . esc_url($back_url) . '">&larr; ' . esc_html__('Back to Assessments', 'hl-core') . '</a>';

        // Instance info
        echo '<table class="form-table">';
        echo '<tr><th>' . esc_html__('Instance ID', 'hl-core') . '</th><td>' . esc_html($instance['instance_id']) . '</td></tr>';
        echo '<tr><th>' . esc_html__('Track', 'hl-core') . '</th><td>' . esc_html($instance['track_name']) . '</td></tr>';
        echo '<tr><th>' . esc_html__('Teacher', 'hl-core') . '</th><td>' . esc_html($instance['display_name']) . ' (' . esc_html($instance['user_email']) . ')</td></tr>';
        echo '<tr><th>' . esc_html__('Classroom', 'hl-core') . '</th><td>' . esc_html($instance['classroom_name']) . '</td></tr>';
        echo '<tr><th>' . esc_html__('School', 'hl-core') . '</th><td>' . esc_html($instance['school_name']) . '</td></tr>';
        echo '<tr><th>' . esc_html__('Age Band', 'hl-core') . '</th><td>' . esc_html($instance['instrument_age_band'] ? ucfirst($instance['instrument_age_band']) : 'Needs Review') . '</td></tr>';
        echo '<tr><th>' . esc_html__('Status', 'hl-core') . '</th><td>' . $this->render_status_badge($instance['status']) . '</td></tr>';
        echo '<tr><th>' . esc_html__('Submitted At', 'hl-core') . '</th><td>' . esc_html($instance['submitted_at'] ?: 'Not yet submitted') . '</td></tr>';
        echo '<tr><th>' . esc_html__('Created At', 'hl-core') . '</th><td>' . esc_html($instance['created_at']) . '</td></tr>';
        echo '</table>';

        // Child rows
        $childrows = $service->get_child_assessment_childrows($instance_id);

        echo '<hr />';
        echo '<h2>' . esc_html__('Child Responses', 'hl-core') . '</h2>';

        if (empty($childrows)) {
            echo '<p>' . esc_html__('No child responses recorded yet.', 'hl-core') . '</p>';

            // Show current classroom roster for reference
            $classroom_service = new HL_Classroom_Service();
            $children = $classroom_service->get_children_in_classroom($instance['classroom_id']);

            if (!empty($children)) {
                echo '<h3>' . esc_html__('Current Classroom Roster', 'hl-core') . '</h3>';
                echo '<table class="widefat striped">';
                echo '<thead><tr>';
                echo '<th>' . esc_html__('Display Code', 'hl-core') . '</th>';
                echo '<th>' . esc_html__('Name', 'hl-core') . '</th>';
                echo '<th>' . esc_html__('DOB', 'hl-core') . '</th>';
                echo '</tr></thead><tbody>';

                foreach ($children as $child) {
                    echo '<tr>';
                    echo '<td>' . esc_html($child->child_display_code ?: '-') . '</td>';
                    echo '<td>' . esc_html(trim($child->first_name . ' ' . $child->last_name)) . '</td>';
                    echo '<td>' . esc_html($child->dob ?: '-') . '</td>';
                    echo '</tr>';
                }

                echo '</tbody></table>';
            }
            return;
        }

        // Group childrows by frozen_age_group
        $groups = array();
        $ungrouped = array();
        foreach ($childrows as $cr) {
            $ag = isset($cr['frozen_age_group']) && $cr['frozen_age_group'] ? $cr['frozen_age_group'] : '';
            if ($ag) {
                $groups[$ag][] = $cr;
            } else {
                $ungrouped[] = $cr;
            }
        }

        // Render each age group section
        $age_group_order = array('infant', 'toddler', 'preschool', 'k2');
        $rendered_groups = array();
        foreach ($age_group_order as $ag) {
            if (isset($groups[$ag])) {
                $rendered_groups[$ag] = $groups[$ag];
            }
        }
        // Add any unknown groups
        foreach ($groups as $ag => $rows) {
            if (!isset($rendered_groups[$ag])) {
                $rendered_groups[$ag] = $rows;
            }
        }
        // Add ungrouped at end
        if (!empty($ungrouped)) {
            $rendered_groups['_ungrouped'] = $ungrouped;
        }

        foreach ($rendered_groups as $ag => $group_rows) {
            if ($ag === '_ungrouped') {
                $group_label = __('Ungrouped', 'hl-core');
            } else {
                $group_label = class_exists('HL_Age_Group_Helper') ? HL_Age_Group_Helper::get_label($ag) : ucfirst($ag);
            }

            echo '<h3 style="margin-top:20px;padding:8px 12px;background:#f0f0f1;border-left:4px solid #2271b1;border-radius:2px;">'
                . esc_html($group_label) . ' <span style="color:#646970;font-weight:400;">(' . count($group_rows) . ')</span></h3>';

            // Collect question IDs for this group
            $group_question_ids = array();
            foreach ($group_rows as $cr) {
                $answers = json_decode($cr['answers_json'], true);
                if (is_array($answers)) {
                    foreach (array_keys($answers) as $qid) {
                        if (!in_array($qid, $group_question_ids)) {
                            $group_question_ids[] = $qid;
                        }
                    }
                }
            }
            sort($group_question_ids);

            echo '<div style="overflow-x:auto;">';
            echo '<table class="widefat striped">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__('Child', 'hl-core') . '</th>';
            echo '<th>' . esc_html__('Code', 'hl-core') . '</th>';
            echo '<th>' . esc_html__('DOB', 'hl-core') . '</th>';
            echo '<th>' . esc_html__('Status', 'hl-core') . '</th>';
            foreach ($group_question_ids as $qid) {
                echo '<th><code>' . esc_html($qid) . '</code></th>';
            }
            echo '</tr></thead><tbody>';

            foreach ($group_rows as $cr) {
                $answers = json_decode($cr['answers_json'], true) ?: array();
                $cr_status = isset($cr['status']) ? $cr['status'] : 'active';

                echo '<tr>';

                // Name with age group badge
                $name_html = esc_html(trim($cr['first_name'] . ' ' . $cr['last_name']));
                if (!empty($cr['frozen_age_group'])) {
                    $ag_label = class_exists('HL_Age_Group_Helper') ? HL_Age_Group_Helper::get_label($cr['frozen_age_group']) : ucfirst($cr['frozen_age_group']);
                    $name_html .= ' <span style="display:inline-block;background:#e7f5ff;color:#1971c2;padding:1px 5px;border-radius:3px;font-size:10px;">'
                        . esc_html($ag_label) . '</span>';
                }
                echo '<td>' . $name_html . '</td>';
                echo '<td>' . esc_html($cr['child_display_code'] ?: '-') . '</td>';
                echo '<td>' . esc_html($cr['dob'] ?: '-') . '</td>';

                // Status badge
                echo '<td>' . $this->render_childrow_status_badge($cr_status) . '</td>';

                foreach ($group_question_ids as $qid) {
                    $val = isset($answers[$qid]) ? $answers[$qid] : '';
                    if (is_array($val)) {
                        $val = implode(', ', $val);
                    }
                    echo '<td>' . esc_html($val) . '</td>';
                }

                echo '</tr>';
            }

            echo '</tbody></table>';
            echo '</div>';
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Render childrow status badge HTML
     */
    private function render_childrow_status_badge($status) {
        $config = array(
            'active'           => array('background:#e6ffed;color:#00a32a;', __('Active', 'hl-core')),
            'skipped'          => array('background:#f0f0f1;color:#646970;', __('Skipped', 'hl-core')),
            'stale_at_submit'  => array('background:#fff3cd;color:#856404;', __('Stale at Submit', 'hl-core')),
            'not_in_classroom' => array('background:#fde8e8;color:#b32d2e;', __('Not in Classroom', 'hl-core')),
        );

        $style = isset($config[$status]) ? $config[$status][0] : 'background:#f0f0f1;color:#646970;';
        $label = isset($config[$status]) ? $config[$status][1] : ucfirst(str_replace('_', ' ', $status));

        return '<span style="display:inline-block;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;' . esc_attr($style) . '">'
            . esc_html($label) . '</span>';
    }

    /**
     * Render status badge HTML
     */
    private function render_status_badge($status) {
        $colors = array(
            'not_started' => 'color:#646970;',
            'in_progress' => 'color:#2271b1;font-weight:600;',
            'submitted'   => 'color:#00a32a;font-weight:600;',
        );

        $labels = array(
            'not_started' => __('Not Started', 'hl-core'),
            'in_progress' => __('In Progress', 'hl-core'),
            'submitted'   => __('Submitted', 'hl-core'),
        );

        $style = isset($colors[$status]) ? $colors[$status] : '';
        $label = isset($labels[$status]) ? $labels[$status] : ucfirst($status);

        return '<span style="' . esc_attr($style) . '">' . esc_html($label) . '</span>';
    }

    /**
     * Render action result messages
     */
    private function render_messages() {
        if (!isset($_GET['message'])) {
            return;
        }

        $msg = sanitize_text_field($_GET['message']);
        $created  = isset($_GET['created']) ? absint($_GET['created']) : 0;
        $existing = isset($_GET['existing']) ? absint($_GET['existing']) : 0;

        switch ($msg) {
            case 'generated':
                echo '<div class="notice notice-success is-dismissible"><p>';
                echo esc_html(sprintf(
                    __('child assessment instances generated: %d created, %d already existed.', 'hl-core'),
                    $created,
                    $existing
                ));
                echo '</p></div>';
                break;

            case 'generate_none':
                echo '<div class="notice notice-info is-dismissible"><p>';
                echo esc_html(sprintf(
                    __('All %d child assessment instances already exist. No new instances created.', 'hl-core'),
                    $existing
                ));
                echo '</p></div>';
                break;

            case 'generate_errors':
                echo '<div class="notice notice-warning is-dismissible"><p>';
                echo esc_html(sprintf(
                    __('Generation completed with errors: %d created, %d existing. Check the audit log for details.', 'hl-core'),
                    $created,
                    $existing
                ));
                echo '</p></div>';
                break;
        }
    }
}
