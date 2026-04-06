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

        echo '<div class="wrap hl-admin-wrap">';

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

            $cycle_id = absint($_POST['cycle_id']);
            $service = new HL_Assessment_Service();
            $result = $service->generate_child_assessment_instances($cycle_id);

            $msg = 'generated';
            if (!empty($result['errors'])) {
                $msg = 'generate_errors';
            } elseif ($result['created'] === 0 && $result['existing'] > 0) {
                $msg = 'generate_none';
            }

            $current_page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : 'hl-assessment-hub';
            if ($current_page === 'hl-assessment-hub') {
                $redirect = admin_url('admin.php?page=hl-assessment-hub&section=child-assessments&cycle_id=' . $cycle_id . '&message=' . $msg . '&created=' . $result['created'] . '&existing=' . $result['existing']);
            } else {
                $redirect = admin_url('admin.php?page=hl-assessments&cycle_id=' . $cycle_id . '&tab=children&message=' . $msg . '&created=' . $result['created'] . '&existing=' . $result['existing']);
            }
            wp_redirect($redirect);
            exit;
        }
    }

    /**
     * Handle CSV export (runs on admin_init before headers sent)
     */
    public function handle_csv_export() {
        $page = isset($_GET['page']) ? $_GET['page'] : '';
        if ($page !== 'hl-assessments' && $page !== 'hl-assessment-hub') {
            return;
        }
        if (!isset($_GET['export'])) {
            return;
        }
        if (!current_user_can('manage_hl_core')) {
            return;
        }

        $export_type = sanitize_text_field($_GET['export']);
        $cycle_id   = isset($_GET['cycle_id']) ? absint($_GET['cycle_id']) : 0;

        if (!$cycle_id) {
            return;
        }

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'hl_export_' . $export_type . '_' . $cycle_id)) {
            return;
        }

        $service = new HL_Assessment_Service();

        global $wpdb;
        $cycle_name = $wpdb->get_var($wpdb->prepare(
            "SELECT cycle_name FROM {$wpdb->prefix}hl_cycle WHERE cycle_id = %d",
            $cycle_id
        ));
        $safe_name = sanitize_file_name($cycle_name ?: 'cycle-' . $cycle_id);

        if ($export_type === 'teacher') {
            $csv = $service->export_teacher_assessments_csv($cycle_id);
            $filename = 'teacher-assessments-' . $safe_name . '-' . date('Y-m-d') . '.csv';
        } elseif ($export_type === 'teacher_responses') {
            $csv = $service->export_teacher_assessment_responses_csv($cycle_id);
            $filename = 'teacher-assessment-responses-' . $safe_name . '-' . date('Y-m-d') . '.csv';
        } elseif ($export_type === 'children') {
            $csv = $service->export_child_assessments_csv($cycle_id);
            $filename = 'child-assessments-' . $safe_name . '-' . date('Y-m-d') . '.csv';
        } elseif ($export_type === 'children_responses') {
            $csv = $service->export_child_assessment_responses_csv($cycle_id);
            $filename = 'child-assessment-responses-' . $safe_name . '-' . date('Y-m-d') . '.csv';
        } else {
            return;
        }

        HL_Audit_Service::log('assessment.exported', array(
            'entity_type' => $export_type . '_assessment',
            'cycle_id'    => $cycle_id,
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
    // Public Hub Entry Points (called by HL_Admin_Assessment_Hub)
    // =========================================================================

    /**
     * Render teacher assessments section (for Assessment Hub sidebar).
     */
    public function render_teacher_section() {
        $this->render_messages();
        $this->render_assessment_section('teacher');
    }

    /**
     * Render child assessments section (for Assessment Hub sidebar).
     */
    public function render_child_section() {
        $this->render_messages();
        $this->render_assessment_section('children');
    }

    /**
     * Render teacher assessment detail view (public, for hub).
     */
    public function render_teacher_detail_page($instance_id) {
        $this->render_teacher_assessment_detail($instance_id);
    }

    /**
     * Render child assessment detail view (public, for hub).
     */
    public function render_child_detail_page($instance_id) {
        $this->render_child_assessment_detail($instance_id);
    }

    // =========================================================================
    // Main List View (Tabbed — standalone page)
    // =========================================================================

    private function render_list() {
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'teacher';

        // Messages
        $this->render_messages();

        echo '<h1>' . esc_html__('Assessments', 'hl-core') . '</h1>';

        // Tab navigation (standalone only — hub uses sidebar)
        $selected_cycle = isset($_GET['cycle_id']) ? absint($_GET['cycle_id']) : 0;
        $teacher_url  = admin_url('admin.php?page=hl-assessments&cycle_id=' . $selected_cycle . '&tab=teacher');
        $children_url = admin_url('admin.php?page=hl-assessments&cycle_id=' . $selected_cycle . '&tab=children');

        echo '<nav class="nav-tab-wrapper">';
        echo '<a href="' . esc_url($teacher_url) . '" class="nav-tab' . ($active_tab === 'teacher' ? ' nav-tab-active' : '') . '">' . esc_html__('Teacher Self-Assessments', 'hl-core') . '</a>';
        echo '<a href="' . esc_url($children_url) . '" class="nav-tab' . ($active_tab === 'children' ? ' nav-tab-active' : '') . '">' . esc_html__('Child Assessments', 'hl-core') . '</a>';
        echo '</nav>';

        $this->render_assessment_section($active_tab);
    }

    /**
     * Render assessment section with cycle selector and data table.
     *
     * @param string $tab 'teacher' or 'children'
     */
    private function render_assessment_section($tab) {
        $service = new HL_Assessment_Service();

        // Cycle filter
        global $wpdb;
        $cycles = $wpdb->get_results(
            "SELECT cycle_id, cycle_name, status FROM {$wpdb->prefix}hl_cycle ORDER BY cycle_name ASC"
        );
        $selected_cycle = isset($_GET['cycle_id']) ? absint($_GET['cycle_id']) : 0;

        // Auto-select most recent active cycle when no explicit selection
        if (!$selected_cycle && $cycles) {
            foreach ($cycles as $t) {
                if ($t->status === 'active') {
                    $selected_cycle = (int) $t->cycle_id;
                }
            }
            if (!$selected_cycle) {
                $last = end($cycles);
                $selected_cycle = (int) $last->cycle_id;
            }
        }

        $page_param = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : 'hl-assessment-hub';
        $section_param = isset($_GET['section']) ? sanitize_text_field($_GET['section']) : '';

        // Cycle selector
        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="' . esc_attr($page_param) . '" />';
        if ($section_param) {
            echo '<input type="hidden" name="section" value="' . esc_attr($section_param) . '" />';
        }
        if ($page_param === 'hl-assessments') {
            echo '<input type="hidden" name="tab" value="' . esc_attr($tab) . '" />';
        }
        echo '<label><strong>' . esc_html__('Cycle:', 'hl-core') . '</strong> </label>';
        echo '<select name="cycle_id">';
        echo '<option value="">' . esc_html__('-- Select Cycle --', 'hl-core') . '</option>';
        if ($cycles) {
            foreach ($cycles as $cycle_obj) {
                $label = $cycle_obj->cycle_name;
                if ($cycle_obj->status !== 'active') {
                    $label .= ' (' . ucfirst($cycle_obj->status) . ')';
                }
                echo '<option value="' . esc_attr($cycle_obj->cycle_id) . '"' . selected($selected_cycle, $cycle_obj->cycle_id, false) . '>' . esc_html($label) . '</option>';
            }
        }
        echo '</select> ';
        submit_button(__('View', 'hl-core'), 'secondary', 'submit', false);
        echo '</form>';

        if (!$selected_cycle) {
            echo '<p>' . esc_html__('Select a cycle to view assessments.', 'hl-core') . '</p>';
            return;
        }

        if ($tab === 'children') {
            $this->render_children_tab($selected_cycle, $service);
        } else {
            $this->render_teacher_tab($selected_cycle, $service);
        }
    }

    // =========================================================================
    // Teacher Self-Assessment Tab
    // =========================================================================

    private function render_teacher_tab($cycle_id, $service) {
        $instances = $service->get_teacher_assessments_by_cycle($cycle_id);

        // Export buttons
        $export_completion_url = wp_nonce_url(
            admin_url('admin.php?page=hl-assessments&cycle_id=' . $cycle_id . '&export=teacher'),
            'hl_export_teacher_' . $cycle_id
        );
        $export_responses_url = wp_nonce_url(
            admin_url('admin.php?page=hl-assessments&cycle_id=' . $cycle_id . '&export=teacher_responses'),
            'hl_export_teacher_responses_' . $cycle_id
        );
        echo '<div class="hl-top-bar">';
        echo '<a href="' . esc_url($export_completion_url) . '" class="button">' . esc_html__('Export Completion CSV', 'hl-core') . '</a>';
        echo '<a href="' . esc_url($export_responses_url) . '" class="button">' . esc_html__('Export Responses CSV', 'hl-core') . '</a>';
        echo '</div>';

        if (empty($instances)) {
            echo '<p>' . esc_html__('No teacher assessment instances found for this cycle.', 'hl-core') . '</p>';
            return;
        }

        // Summary cards
        $total     = count($instances);
        $submitted = count(array_filter($instances, function($i) { return $i['status'] === 'submitted'; }));
        $pending   = $total - $submitted;

        echo '<div class="hl-metrics-row">';
        echo '<div class="hl-metric-card">';
        echo '<div class="metric-value" id="hl-tsa-stat-total">' . esc_html($total) . '</div>';
        echo '<div class="metric-label">' . esc_html__('Total Instances', 'hl-core') . '</div></div>';
        echo '<div class="hl-metric-card">';
        echo '<div class="metric-value" id="hl-tsa-stat-submitted">' . esc_html($submitted) . '</div>';
        echo '<div class="metric-label">' . esc_html__('Submitted', 'hl-core') . '</div></div>';
        echo '<div class="hl-metric-card">';
        echo '<div class="metric-value" id="hl-tsa-stat-pending">' . esc_html($pending) . '</div>';
        echo '<div class="metric-label">' . esc_html__('Pending', 'hl-core') . '</div></div>';
        echo '</div>';

        // Collect filter options from data.
        $schools  = array();
        $phases   = array();
        $statuses = array();
        foreach ($instances as $inst) {
            $s = $inst['school_name'] ?? '';
            if ($s && !in_array($s, $schools, true)) $schools[] = $s;
            if (!in_array($inst['phase'], $phases, true)) $phases[] = $inst['phase'];
            if (!in_array($inst['status'], $statuses, true)) $statuses[] = $inst['status'];
        }
        sort($schools);
        sort($statuses);

        // Filter bar.
        echo '<div style="display:flex;gap:12px;align-items:center;margin-bottom:16px;flex-wrap:wrap;">';
        echo '<select id="hl-tsa-filter-phase" style="min-width:120px;">';
        echo '<option value="">' . esc_html__('All Phases', 'hl-core') . '</option>';
        foreach ($phases as $p) {
            echo '<option value="' . esc_attr($p) . '">' . esc_html(strtoupper($p)) . '</option>';
        }
        echo '</select>';

        echo '<select id="hl-tsa-filter-school" style="min-width:180px;">';
        echo '<option value="">' . esc_html__('All Schools', 'hl-core') . '</option>';
        foreach ($schools as $s) {
            echo '<option value="' . esc_attr($s) . '">' . esc_html($s) . '</option>';
        }
        echo '</select>';

        echo '<select id="hl-tsa-filter-status" style="min-width:140px;">';
        echo '<option value="">' . esc_html__('All Statuses', 'hl-core') . '</option>';
        foreach ($statuses as $st) {
            echo '<option value="' . esc_attr($st) . '">' . esc_html(ucfirst(str_replace('_', ' ', $st))) . '</option>';
        }
        echo '</select>';

        echo '<span id="hl-tsa-filter-count" style="color:#646970;font-size:13px;"></span>';
        echo '</div>';

        // Table
        $can_switch = class_exists('BP_Core_Members_Switching') && current_user_can('edit_users');
        $col_count = $can_switch ? 9 : 8;

        echo '<table class="widefat striped" id="hl-tsa-table">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('ID', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Teacher', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Email', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('School', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Phase', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Status', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Submitted At', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Actions', 'hl-core') . '</th>';
        if ($can_switch) {
            echo '<th></th>';
        }
        echo '</tr></thead><tbody>';

        foreach ($instances as $inst) {
            $view_url = admin_url('admin.php?page=hl-assessments&action=view_teacher&instance_id=' . $inst['instance_id'] . '&cycle_id=' . $cycle_id);
            $user_edit_url = admin_url('user-edit.php?user_id=' . $inst['user_id']);

            echo '<tr data-phase="' . esc_attr($inst['phase']) . '" data-school="' . esc_attr($inst['school_name'] ?? '') . '" data-status="' . esc_attr($inst['status']) . '">';
            echo '<td>' . esc_html($inst['instance_id']) . '</td>';
            $suspended_badge = HL_BuddyBoss_Integration::is_user_suspended( (int) $inst['user_id'] )
                ? ' <span class="hl-status-badge suspended">' . esc_html__( 'Suspended', 'hl-core' ) . '</span>'
                : '';
            echo '<td><a href="' . esc_url($user_edit_url) . '">' . esc_html($inst['display_name']) . '</a>' . $suspended_badge . '</td>';
            echo '<td>' . esc_html($inst['user_email']) . '</td>';
            echo '<td>' . esc_html($inst['school_name'] ?? '-') . '</td>';
            echo '<td><span style="text-transform:uppercase;font-weight:600;">' . esc_html($inst['phase']) . '</span></td>';
            echo '<td>' . $this->render_status_badge($inst['status']) . '</td>';
            echo '<td>' . esc_html($inst['submitted_at'] ?: '-') . '</td>';
            echo '<td><a href="' . esc_url($view_url) . '" class="button button-small">' . esc_html__('View', 'hl-core') . '</a></td>';
            if ($can_switch) {
                $target_user = new WP_User($inst['user_id']);
                $switch_url = BP_Core_Members_Switching::switch_to_url($target_user);
                echo '<td><a href="' . esc_url($switch_url) . '" title="' . esc_attr__('View as this user', 'hl-core') . '" class="button button-small">&#x21C4; ' . esc_html__('View As', 'hl-core') . '</a></td>';
            }
            echo '</tr>';
        }

        echo '</tbody></table>';

        // Client-side filter JS.
        ?>
        <script>
        (function(){
            var fPhase  = document.getElementById('hl-tsa-filter-phase');
            var fSchool = document.getElementById('hl-tsa-filter-school');
            var fStatus = document.getElementById('hl-tsa-filter-status');
            var counter = document.getElementById('hl-tsa-filter-count');
            var rows    = document.querySelectorAll('#hl-tsa-table tbody tr[data-phase]');
            var total   = rows.length;

            var elTotal = document.getElementById('hl-tsa-stat-total');
            var elSub   = document.getElementById('hl-tsa-stat-submitted');
            var elPend  = document.getElementById('hl-tsa-stat-pending');

            function applyFilters() {
                var vp = fPhase.value, vs = fSchool.value, vst = fStatus.value;
                var visible = 0, submitted = 0;
                rows.forEach(function(row) {
                    var show = true;
                    if (vp  && row.dataset.phase  !== vp)  show = false;
                    if (vs  && row.dataset.school !== vs)  show = false;
                    if (vst && row.dataset.status !== vst) show = false;
                    row.style.display = show ? '' : 'none';
                    if (show) {
                        visible++;
                        if (row.dataset.status === 'submitted') submitted++;
                    }
                });
                elTotal.textContent = visible;
                elSub.textContent = submitted;
                elPend.textContent = visible - submitted;
                counter.textContent = (vp || vs || vst)
                    ? '<?php echo esc_js(__('Showing', 'hl-core')); ?> ' + visible + ' / ' + total
                    : '';
            }

            fPhase.addEventListener('change', applyFilters);
            fSchool.addEventListener('change', applyFilters);
            fStatus.addEventListener('change', applyFilters);
        })();
        </script>
        <?php
    }

    // =========================================================================
    // Child Assessment Tab
    // =========================================================================

    private function render_children_tab($cycle_id, $service) {
        $instances = $service->get_child_assessments_by_cycle($cycle_id);

        // Action buttons row
        echo '<div class="hl-top-bar" style="justify-content:flex-start;">';

        // Generate instances button
        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=hl-assessments&cycle_id=' . $cycle_id . '&tab=children')) . '" style="display:inline;">';
        wp_nonce_field('hl_generate_children_instances', 'hl_generate_children_nonce');
        echo '<input type="hidden" name="cycle_id" value="' . esc_attr($cycle_id) . '" />';
        submit_button(
            __('Generate Instances from Teaching Assignments', 'hl-core'),
            'secondary',
            'submit',
            false,
            array('onclick' => 'return confirm("' . esc_js(__('This will create child assessment instances for all teaching assignments in this cycle. Continue?', 'hl-core')) . '");')
        );
        echo '</form>';

        // Export buttons
        $export_completion_url = wp_nonce_url(
            admin_url('admin.php?page=hl-assessments&cycle_id=' . $cycle_id . '&export=children'),
            'hl_export_children_' . $cycle_id
        );
        $export_responses_url = wp_nonce_url(
            admin_url('admin.php?page=hl-assessments&cycle_id=' . $cycle_id . '&export=children_responses'),
            'hl_export_children_responses_' . $cycle_id
        );
        echo '<a href="' . esc_url($export_completion_url) . '" class="button">' . esc_html__('Export Completion CSV', 'hl-core') . '</a>';
        echo '<a href="' . esc_url($export_responses_url) . '" class="button">' . esc_html__('Export Responses CSV', 'hl-core') . '</a>';

        echo '</div>';

        if (empty($instances)) {
            echo '<p>' . esc_html__('No child assessment instances found for this cycle. Use "Generate Instances" to create them from teaching assignments.', 'hl-core') . '</p>';
            return;
        }

        // Summary cards
        $total     = count($instances);
        $submitted = count(array_filter($instances, function($i) { return $i['status'] === 'submitted'; }));
        $in_progress = count(array_filter($instances, function($i) { return $i['status'] === 'in_progress'; }));
        $not_started = $total - $submitted - $in_progress;

        echo '<div class="hl-metrics-row">';
        echo '<div class="hl-metric-card">';
        echo '<div class="metric-value">' . esc_html($total) . '</div>';
        echo '<div class="metric-label">' . esc_html__('Total Instances', 'hl-core') . '</div></div>';
        echo '<div class="hl-metric-card">';
        echo '<div class="metric-value">' . esc_html($submitted) . '</div>';
        echo '<div class="metric-label">' . esc_html__('Submitted', 'hl-core') . '</div></div>';
        echo '<div class="hl-metric-card">';
        echo '<div class="metric-value">' . esc_html($in_progress) . '</div>';
        echo '<div class="metric-label">' . esc_html__('In Progress', 'hl-core') . '</div></div>';
        echo '<div class="hl-metric-card">';
        echo '<div class="metric-value">' . esc_html($not_started) . '</div>';
        echo '<div class="metric-label">' . esc_html__('Not Started', 'hl-core') . '</div></div>';
        echo '</div>';

        // Table
        $can_switch = class_exists('BP_Core_Members_Switching') && current_user_can('edit_users');
        $col_count = $can_switch ? 11 : 10;

        $phase_counts = array();
        foreach ($instances as $inst) {
            $p = isset($inst['phase']) ? $inst['phase'] : '';
            $phase_counts[$p] = isset($phase_counts[$p]) ? $phase_counts[$p] + 1 : 1;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('ID', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Teacher', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Phase', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Classroom', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('School', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Age Band', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Instrument', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Status', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Submitted At', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Actions', 'hl-core') . '</th>';
        if ($can_switch) {
            echo '<th></th>';
        }
        echo '</tr></thead><tbody>';

        $current_phase = null;
        foreach ($instances as $inst) {
            $inst_phase = isset($inst['phase']) ? $inst['phase'] : '';

            // Phase section header
            if ($inst_phase !== $current_phase) {
                $current_phase = $inst_phase;
                $phase_label = strtoupper($current_phase) === 'POST' ? __('POST-Assessment', 'hl-core') : __('PRE-Assessment', 'hl-core');
                $phase_cnt = isset($phase_counts[$current_phase]) ? $phase_counts[$current_phase] : 0;
                echo '<tr><td colspan="' . $col_count . '" style="background:#f0f6fc;font-weight:700;padding:10px 12px;font-size:13px;border-left:4px solid #2271b1;">'
                    . esc_html($phase_label) . ' <span style="color:#646970;font-weight:400;">(' . $phase_cnt . ')</span></td></tr>';
            }

            $view_url = admin_url('admin.php?page=hl-assessments&action=view_children&instance_id=' . $inst['instance_id'] . '&cycle_id=' . $cycle_id);
            $user_edit_url = admin_url('user-edit.php?user_id=' . $inst['user_id']);

            $age_band_display = $inst['instrument_age_band'] ? ucfirst($inst['instrument_age_band']) : '<em style="color:#b32d2e;">' . esc_html__('Needs Review', 'hl-core') . '</em>';
            $instrument_display = $inst['instrument_id'] ? esc_html($inst['instrument_id'] . ' (v' . $inst['instrument_version'] . ')') : '-';

            $suspended_badge = HL_BuddyBoss_Integration::is_user_suspended( (int) $inst['user_id'] )
                ? ' <span class="hl-status-badge suspended">' . esc_html__( 'Suspended', 'hl-core' ) . '</span>'
                : '';
            echo '<tr>';
            echo '<td>' . esc_html($inst['instance_id']) . '</td>';
            echo '<td><a href="' . esc_url($user_edit_url) . '">' . esc_html($inst['display_name']) . '</a>' . $suspended_badge . '</td>';
            echo '<td><span style="text-transform:uppercase;font-weight:600;">' . esc_html($inst_phase) . '</span></td>';
            echo '<td>' . esc_html($inst['classroom_name']) . '</td>';
            echo '<td>' . esc_html($inst['school_name']) . '</td>';
            echo '<td>' . $age_band_display . '</td>';
            echo '<td>' . $instrument_display . '</td>';
            echo '<td>' . $this->render_status_badge($inst['status']) . '</td>';
            echo '<td>' . esc_html($inst['submitted_at'] ?: '-') . '</td>';
            echo '<td><a href="' . esc_url($view_url) . '" class="button button-small">' . esc_html__('View', 'hl-core') . '</a></td>';
            if ($can_switch) {
                $target_user = new WP_User($inst['user_id']);
                $switch_url = BP_Core_Members_Switching::switch_to_url($target_user);
                echo '<td><a href="' . esc_url($switch_url) . '" title="' . esc_attr__('View as this user', 'hl-core') . '" class="button button-small">&#x21C4; ' . esc_html__('View As', 'hl-core') . '</a></td>';
            }
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

        $cycle_id = $instance['cycle_id'];
        $back_url = admin_url('admin.php?page=hl-assessment-hub&section=teacher-assessments&cycle_id=' . $cycle_id);

        echo '<h2>' . esc_html__('Teacher Self-Assessment Detail', 'hl-core') . '</h2>';
        echo '<a href="' . esc_url($back_url) . '" style="margin-bottom:16px;display:inline-block;">&larr; ' . esc_html__('Back to Assessments', 'hl-core') . '</a>';

        // Instance info card
        echo '<div class="hl-form-section" style="margin-bottom:16px;">';
        echo '<div class="hl-detail-grid">';

        echo '<div class="hl-detail-item"><span class="hl-detail-label">' . esc_html__('Instance', 'hl-core') . '</span><span class="hl-detail-value">#' . esc_html($instance['instance_id']) . '</span></div>';
        echo '<div class="hl-detail-item"><span class="hl-detail-label">' . esc_html__('Cycle', 'hl-core') . '</span><span class="hl-detail-value">' . esc_html($instance['cycle_name']) . '</span></div>';
        echo '<div class="hl-detail-item"><span class="hl-detail-label">' . esc_html__('Teacher', 'hl-core') . '</span><span class="hl-detail-value">' . esc_html($instance['display_name']) . '</span></div>';
        echo '<div class="hl-detail-item"><span class="hl-detail-label">' . esc_html__('Email', 'hl-core') . '</span><span class="hl-detail-value">' . esc_html($instance['user_email']) . '</span></div>';
        echo '<div class="hl-detail-item"><span class="hl-detail-label">' . esc_html__('Cycle', 'hl-core') . '</span><span class="hl-detail-value"><strong>' . esc_html(strtoupper($instance['phase'])) . '</strong></span></div>';
        echo '<div class="hl-detail-item"><span class="hl-detail-label">' . esc_html__('Status', 'hl-core') . '</span><span class="hl-detail-value">' . $this->render_status_badge($instance['status']) . '</span></div>';
        echo '<div class="hl-detail-item"><span class="hl-detail-label">' . esc_html__('Submitted', 'hl-core') . '</span><span class="hl-detail-value">' . esc_html($instance['submitted_at'] ?: '-') . '</span></div>';
        echo '<div class="hl-detail-item"><span class="hl-detail-label">' . esc_html__('Created', 'hl-core') . '</span><span class="hl-detail-value">' . esc_html($instance['created_at']) . '</span></div>';

        echo '</div></div>';

        // Responses from responses_json
        $responses_json = !empty($instance['responses_json']) ? json_decode($instance['responses_json'], true) : null;

        echo '<h3>' . esc_html__('Responses', 'hl-core') . '</h3>';

        if (empty($responses_json)) {
            echo '<p>' . esc_html__('No responses recorded yet.', 'hl-core') . '</p>';
            if ($instance['status'] !== 'submitted') {
                echo '<p class="description">' . esc_html__('Responses will appear when the teacher submits.', 'hl-core') . '</p>';
            }
            return;
        }

        // Load instrument to get section/question labels
        $instrument_labels = $this->get_instrument_labels($instance);

        foreach ($responses_json as $section_key => $questions) {
            if (!is_array($questions)) {
                continue;
            }

            $section_title = isset($instrument_labels['sections'][$section_key])
                ? $instrument_labels['sections'][$section_key]
                : ucfirst(str_replace('_', ' ', $section_key));

            echo '<div class="hl-form-section" style="margin-bottom:12px;">';
            echo '<h3 class="hl-form-section-title">' . esc_html($section_title) . '</h3>';
            echo '<table class="widefat striped">';
            echo '<thead><tr>';
            echo '<th style="width:120px;">' . esc_html__('Question', 'hl-core') . '</th>';
            echo '<th style="width:80px;">' . esc_html__('Rating', 'hl-core') . '</th>';
            echo '<th>' . esc_html__('Label', 'hl-core') . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($questions as $q_id => $value) {
                $q_label = isset($instrument_labels['questions'][$section_key][$q_id])
                    ? $instrument_labels['questions'][$section_key][$q_id]
                    : '';

                echo '<tr>';
                echo '<td><code>' . esc_html($q_id) . '</code></td>';
                echo '<td><strong>' . esc_html($value) . '</strong></td>';
                echo '<td>' . esc_html($q_label) . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
            echo '</div>';
        }
    }

    /**
     * Load instrument section/question labels for a teacher assessment instance.
     */
    private function get_instrument_labels($instance) {
        $labels = array('sections' => array(), 'questions' => array());
        $instrument_id = !empty($instance['instrument_id']) ? absint($instance['instrument_id']) : 0;
        if (!$instrument_id) {
            return $labels;
        }

        global $wpdb;
        $instrument = $wpdb->get_row($wpdb->prepare(
            "SELECT sections FROM {$wpdb->prefix}hl_teacher_assessment_instrument WHERE instrument_id = %d",
            $instrument_id
        ));
        if (!$instrument || empty($instrument->sections)) {
            return $labels;
        }

        $sections = json_decode($instrument->sections, true);
        if (!is_array($sections)) {
            return $labels;
        }

        foreach ($sections as $sec) {
            $key = $sec['section_key'];
            $labels['sections'][$key] = $sec['title'];
            $labels['questions'][$key] = array();

            if (!empty($sec['questions']) && is_array($sec['questions'])) {
                foreach ($sec['questions'] as $q) {
                    $qid = isset($q['key']) ? $q['key'] : (isset($q['id']) ? $q['id'] : '');
                    if ($qid) $labels['questions'][$key][$qid] = $q['text'];
                }
            }
            if (!empty($sec['items']) && is_array($sec['items'])) {
                foreach ($sec['items'] as $item) {
                    $qid = isset($item['key']) ? $item['key'] : (isset($item['id']) ? $item['id'] : '');
                    if ($qid) $labels['questions'][$key][$qid] = $item['text'];
                }
            }
        }

        return $labels;
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

        $cycle_id = $instance['cycle_id'];
        $back_url = admin_url('admin.php?page=hl-assessment-hub&section=child-assessments&cycle_id=' . $cycle_id);

        echo '<h2>' . esc_html__('Child Assessment Detail', 'hl-core') . '</h2>';
        echo '<a href="' . esc_url($back_url) . '" style="margin-bottom:16px;display:inline-block;">&larr; ' . esc_html__('Back to Assessments', 'hl-core') . '</a>';

        // Instance info card
        echo '<div class="hl-form-section" style="margin-bottom:16px;">';
        echo '<div class="hl-detail-grid">';

        echo '<div class="hl-detail-item"><span class="hl-detail-label">' . esc_html__('Instance', 'hl-core') . '</span><span class="hl-detail-value">#' . esc_html($instance['instance_id']) . '</span></div>';
        echo '<div class="hl-detail-item"><span class="hl-detail-label">' . esc_html__('Cycle', 'hl-core') . '</span><span class="hl-detail-value">' . esc_html($instance['cycle_name']) . '</span></div>';
        echo '<div class="hl-detail-item"><span class="hl-detail-label">' . esc_html__('Teacher', 'hl-core') . '</span><span class="hl-detail-value">' . esc_html($instance['display_name']) . '</span></div>';
        echo '<div class="hl-detail-item"><span class="hl-detail-label">' . esc_html__('Email', 'hl-core') . '</span><span class="hl-detail-value">' . esc_html($instance['user_email']) . '</span></div>';
        echo '<div class="hl-detail-item"><span class="hl-detail-label">' . esc_html__('Classroom', 'hl-core') . '</span><span class="hl-detail-value">' . esc_html($instance['classroom_name']) . '</span></div>';
        echo '<div class="hl-detail-item"><span class="hl-detail-label">' . esc_html__('School', 'hl-core') . '</span><span class="hl-detail-value">' . esc_html($instance['school_name']) . '</span></div>';
        echo '<div class="hl-detail-item"><span class="hl-detail-label">' . esc_html__('Age Band', 'hl-core') . '</span><span class="hl-detail-value">' . esc_html($instance['instrument_age_band'] ? ucfirst($instance['instrument_age_band']) : 'Needs Review') . '</span></div>';
        echo '<div class="hl-detail-item"><span class="hl-detail-label">' . esc_html__('Status', 'hl-core') . '</span><span class="hl-detail-value">' . $this->render_status_badge($instance['status']) . '</span></div>';
        echo '<div class="hl-detail-item"><span class="hl-detail-label">' . esc_html__('Submitted', 'hl-core') . '</span><span class="hl-detail-value">' . esc_html($instance['submitted_at'] ?: '-') . '</span></div>';
        echo '<div class="hl-detail-item"><span class="hl-detail-label">' . esc_html__('Created', 'hl-core') . '</span><span class="hl-detail-value">' . esc_html($instance['created_at']) . '</span></div>';

        echo '</div></div>';

        // Child rows
        $childrows = $service->get_child_assessment_childrows($instance_id);

        echo '<h3 style="margin-top:20px;">' . esc_html__('Child Responses', 'hl-core') . '</h3>';

        if (empty($childrows)) {
            echo '<div class="hl-form-section"><p>' . esc_html__('No child responses recorded yet.', 'hl-core') . '</p>';

            // Show current classroom roster for reference
            $classroom_service = new HL_Classroom_Service();
            $children = $classroom_service->get_children_in_classroom($instance['classroom_id']);

            if (!empty($children)) {
                echo '<h4>' . esc_html__('Current Classroom Roster', 'hl-core') . '</h4>';
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
            echo '</div>';
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
        foreach ($groups as $ag => $rows) {
            if (!isset($rendered_groups[$ag])) {
                $rendered_groups[$ag] = $rows;
            }
        }
        if (!empty($ungrouped)) {
            $rendered_groups['_ungrouped'] = $ungrouped;
        }

        foreach ($rendered_groups as $ag => $group_rows) {
            if ($ag === '_ungrouped') {
                $group_label = __('Ungrouped', 'hl-core');
            } else {
                $group_label = class_exists('HL_Age_Group_Helper') ? HL_Age_Group_Helper::get_label($ag) : ucfirst($ag);
            }

            echo '<div class="hl-form-section" style="margin-bottom:12px;">';
            echo '<h3 class="hl-form-section-title">'
                . esc_html($group_label) . ' <span style="font-weight:400;">(' . count($group_rows) . ')</span></h3>';

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
                    $name_html .= ' <span class="hl-type-badge">' . esc_html($ag_label) . '</span>';
                }
                echo '<td>' . $name_html . '</td>';
                echo '<td>' . esc_html($cr['child_display_code'] ?: '-') . '</td>';
                echo '<td>' . esc_html($cr['dob'] ?: '-') . '</td>';
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
        $css_map = array(
            'active'           => 'hl-status hl-status-active',
            'skipped'          => 'hl-status hl-status-draft',
            'stale_at_submit'  => 'hl-status hl-status-paused',
            'not_in_classroom' => 'hl-status hl-status-archived',
        );

        $labels = array(
            'active'           => __('Active', 'hl-core'),
            'skipped'          => __('Skipped', 'hl-core'),
            'stale_at_submit'  => __('Stale at Submit', 'hl-core'),
            'not_in_classroom' => __('Not in Classroom', 'hl-core'),
        );

        $class = isset($css_map[$status]) ? $css_map[$status] : 'hl-status hl-status-draft';
        $label = isset($labels[$status]) ? $labels[$status] : ucfirst(str_replace('_', ' ', $status));

        return '<span class="' . esc_attr($class) . '">' . esc_html($label) . '</span>';
    }

    /**
     * Render status badge HTML
     */
    private function render_status_badge($status) {
        $css_map = array(
            'not_started' => 'hl-status hl-status-draft',
            'in_progress' => 'hl-status hl-status-progress',
            'submitted'   => 'hl-status hl-status-complete',
        );

        $labels = array(
            'not_started' => __('Not Started', 'hl-core'),
            'in_progress' => __('In Progress', 'hl-core'),
            'submitted'   => __('Submitted', 'hl-core'),
        );

        $class = isset($css_map[$status]) ? $css_map[$status] : 'hl-status hl-status-draft';
        $label = isset($labels[$status]) ? $labels[$status] : ucfirst($status);

        return '<span class="' . esc_attr($class) . '">' . esc_html($label) . '</span>';
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
