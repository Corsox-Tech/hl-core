<?php if (!defined('ABSPATH')) exit;

/**
 * Admin Reporting Dashboard
 *
 * Full reporting dashboard with scope-based filtering (cohort, school, district, team),
 * summary metrics, participant completion table, activity drill-down, and CSV export.
 *
 * @package HL_Core
 */
class HL_Admin_Reporting {

    /**
     * Singleton instance
     *
     * @var HL_Admin_Reporting|null
     */
    private static $instance = null;

    /**
     * Get singleton instance
     *
     * @return HL_Admin_Reporting
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
     * Handle CSV exports and recompute actions before any HTML output.
     */
    public function handle_early_actions() {
        if (!current_user_can('manage_hl_core')) {
            return;
        }

        $filters = $this->get_filters();

        // Handle CSV exports before any HTML output
        if (!empty($_GET['export'])) {
            $this->handle_csv_export(sanitize_text_field($_GET['export']), $filters);
            // handle_csv_export calls exit on success; if we reach here, it failed
        }

        // Handle recompute rollups action
        if (isset($_GET['action']) && $_GET['action'] === 'recompute') {
            $this->handle_recompute($filters);
            // handle_recompute calls wp_redirect + exit
        }
    }

    /**
     * Main render entry point
     */
    public function render_page() {
        if (!current_user_can('manage_hl_core')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'hl-core'));
        }

        // Gather filters from GET parameters
        $filters = $this->get_filters();

        // Check if this is an activity detail drill-down
        $enrollment_id = isset($_GET['enrollment_id']) ? absint($_GET['enrollment_id']) : 0;

        echo '<div class="wrap hl-admin-wrap">';

        if ($enrollment_id) {
            $this->render_enrollment_detail($enrollment_id, $filters);
        } else {
            $this->render_dashboard($filters);
        }

        echo '</div>';
    }

    // =========================================================================
    // Filter Handling
    // =========================================================================

    /**
     * Extract and sanitize filter parameters from $_GET
     *
     * @return array
     */
    private function get_filters() {
        return array(
            'cohort_id'   => isset($_GET['cohort_id'])   ? absint($_GET['cohort_id'])   : 0,
            'school_id'   => isset($_GET['school_id'])   ? absint($_GET['school_id'])   : 0,
            'district_id' => isset($_GET['district_id']) ? absint($_GET['district_id']) : 0,
            'team_id'     => isset($_GET['team_id'])     ? absint($_GET['team_id'])     : 0,
            'role'        => isset($_GET['role'])         ? sanitize_text_field($_GET['role']) : '',
            'group_id'    => isset($_GET['group_id'])    ? absint($_GET['group_id'])    : 0,
        );
    }

    /**
     * Build a URL for the reporting page with given parameters merged
     *
     * @param array $args Additional query args to merge
     * @return string
     */
    private function page_url($args = array()) {
        $base = admin_url('admin.php?page=hl-reporting');
        return add_query_arg($args, $base);
    }

    // =========================================================================
    // CSV Export
    // =========================================================================

    /**
     * Handle CSV export requests
     *
     * Must be called before any HTML output. Sends CSV headers and content, then exits.
     *
     * @param string $export_type The export type identifier
     * @param array  $filters     Current filters
     */
    private function handle_csv_export($export_type, $filters) {
        if (!current_user_can('manage_hl_core')) {
            wp_die(esc_html__('You do not have permission to export data.', 'hl-core'));
        }

        $cohort_id = $filters['cohort_id'];

        // Group summary export uses group_id, not cohort_id.
        if ($export_type === 'group_summary_csv') {
            $group_id = isset($_GET['group_id']) ? absint($_GET['group_id']) : 0;
            if ($group_id) {
                $reporting = HL_Reporting_Service::instance();
                $csv      = $reporting->export_group_summary_csv($group_id);
                $filename = 'group-summary-' . $group_id . '-' . gmdate('Y-m-d') . '.csv';
                if (!empty($csv)) {
                    header('Content-Type: text/csv; charset=utf-8');
                    header('Content-Disposition: attachment; filename="' . $filename . '"');
                    header('Cache-Control: no-cache, no-store, must-revalidate');
                    header('Pragma: no-cache');
                    header('Expires: 0');
                    echo $csv;
                    exit;
                }
            }
            return;
        }

        // Comparison CSV export uses group_id, not cohort_id.
        if ($export_type === 'comparison_csv') {
            $group_id = isset($_GET['group_id']) ? absint($_GET['group_id']) : 0;
            if ($group_id) {
                $reporting = HL_Reporting_Service::instance();
                $csv      = $reporting->export_group_comparison_csv($group_id);
                $filename = 'program-vs-control-comparison-' . $group_id . '-' . gmdate('Y-m-d') . '.csv';
                if (!empty($csv)) {
                    header('Content-Type: text/csv; charset=utf-8');
                    header('Content-Disposition: attachment; filename="' . $filename . '"');
                    header('Cache-Control: no-cache, no-store, must-revalidate');
                    header('Pragma: no-cache');
                    header('Expires: 0');
                    echo $csv;
                    exit;
                }
            }
            return;
        }

        if (!$cohort_id && in_array($export_type, array('completion_csv', 'school_summary_csv', 'team_summary_csv', 'teacher_assessment_csv', 'child_assessment_csv'), true)) {
            // Cohort is required for all exports; fall through to render page with error
            return;
        }

        $reporting = HL_Reporting_Service::instance();
        $csv       = '';
        $filename  = '';

        switch ($export_type) {
            case 'completion_csv':
                $csv      = $reporting->export_completion_csv($filters, true);
                $filename = 'completion-report-cohort-' . $cohort_id . '-' . gmdate('Y-m-d') . '.csv';
                break;

            case 'school_summary_csv':
                $csv      = $reporting->export_school_summary_csv($cohort_id, $filters['district_id']);
                $filename = 'school-summary-cohort-' . $cohort_id . '-' . gmdate('Y-m-d') . '.csv';
                break;

            case 'team_summary_csv':
                $csv      = $reporting->export_team_summary_csv($cohort_id, $filters['school_id']);
                $filename = 'team-summary-cohort-' . $cohort_id . '-' . gmdate('Y-m-d') . '.csv';
                break;

            case 'teacher_assessment_csv':
                $assessment_service = new HL_Assessment_Service();
                $csv      = $assessment_service->export_teacher_assessments_csv($cohort_id);
                $filename = 'teacher-assessments-cohort-' . $cohort_id . '-' . gmdate('Y-m-d') . '.csv';
                break;

            case 'child_assessment_csv':
                $assessment_service = new HL_Assessment_Service();
                $csv      = $assessment_service->export_child_assessments_csv($cohort_id);
                $filename = 'child-assessments-cohort-' . $cohort_id . '-' . gmdate('Y-m-d') . '.csv';
                break;

            default:
                return; // Unknown export type, fall through to normal render
        }

        if (empty($csv)) {
            return; // No data, fall through
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        echo $csv;
        exit;
    }

    // =========================================================================
    // Recompute Rollups
    // =========================================================================

    /**
     * Handle the recompute rollups action
     *
     * @param array $filters Current filters
     */
    private function handle_recompute($filters) {
        $cohort_id = $filters['cohort_id'];

        if (!$cohort_id) {
            wp_die(esc_html__('Cohort is required for recomputing rollups.', 'hl-core'));
        }

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'hl_recompute_rollups_' . $cohort_id)) {
            wp_die(esc_html__('Security check failed.', 'hl-core'));
        }

        $reporting = HL_Reporting_Service::instance();
        $result    = $reporting->recompute_cohort_rollups($cohort_id);

        $redirect_url = $this->page_url(array(
            'cohort_id' => $cohort_id,
            'message'   => 'recomputed',
            'updated'   => $result['updated'],
            'errors'    => $result['errors'],
        ));

        wp_redirect($redirect_url);
        exit;
    }

    // =========================================================================
    // Dashboard Render
    // =========================================================================

    /**
     * Render the main reporting dashboard
     *
     * @param array $filters
     */
    private function render_dashboard($filters) {
        echo '<h1 class="wp-heading-inline">' . esc_html__('Reports', 'hl-core') . '</h1>';
        echo '<hr class="wp-header-end">';

        // Show success messages
        $this->render_messages();

        // Filters bar
        $this->render_filters_bar($filters);

        $cohort_id = $filters['cohort_id'];

        if (!$cohort_id) {
            echo '<div class="hl-empty-state">';
            echo '<p>' . esc_html__('Please select a cohort to view the reporting dashboard.', 'hl-core') . '</p>';
            echo '</div>';
            return;
        }

        $reporting = HL_Reporting_Service::instance();

        // Summary cards
        $this->render_summary_cards($reporting, $filters);

        // Summary tables (school or team level)
        $this->render_summary_tables($reporting, $filters);

        // Participant table
        $this->render_participant_table($reporting, $filters);

        // Assessment exports (staff only)
        $this->render_assessment_exports($filters);

        // Group comparison (when group_id filter is active)
        $this->render_group_comparison($reporting, $filters);
    }

    /**
     * Render admin notices / success messages
     */
    private function render_messages() {
        if (!isset($_GET['message'])) {
            return;
        }

        $message = sanitize_text_field($_GET['message']);

        if ($message === 'recomputed') {
            $updated = isset($_GET['updated']) ? absint($_GET['updated']) : 0;
            $errors  = isset($_GET['errors'])  ? absint($_GET['errors'])  : 0;

            echo '<div class="notice notice-success is-dismissible"><p>';
            echo esc_html(sprintf(
                __('Rollups recomputed. %d enrollments updated, %d errors.', 'hl-core'),
                $updated,
                $errors
            ));
            echo '</p></div>';
        }
    }

    // =========================================================================
    // Filters Bar
    // =========================================================================

    /**
     * Render the filters bar
     *
     * @param array $filters
     */
    private function render_filters_bar($filters) {
        $cohort_repo  = new HL_Cohort_Repository();
        $orgunit_repo = new HL_OrgUnit_Repository();
        $team_service = new HL_Team_Service();

        $cohorts   = $cohort_repo->get_all();
        $districts = $orgunit_repo->get_districts();
        $schools   = $orgunit_repo->get_schools();

        $teams = array();
        if ($filters['cohort_id']) {
            $team_filters = array('cohort_id' => $filters['cohort_id']);
            if ($filters['school_id']) {
                $team_filters['school_id'] = $filters['school_id'];
            }
            $teams = $team_service->get_teams($team_filters);
        }

        $roles = array('Teacher', 'Mentor', 'School Leader', 'District Leader');

        // Fetch cohort groups for the group filter.
        global $wpdb;
        $cohort_groups = $wpdb->get_results(
            "SELECT group_id, group_name FROM {$wpdb->prefix}hl_cohort_group WHERE status = 'active' ORDER BY group_name ASC",
            ARRAY_A
        ) ?: array();

        echo '<div class="hl-filters-bar" style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 15px 20px; margin-bottom: 20px;">';
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" style="display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end;">';
        echo '<input type="hidden" name="page" value="hl-reporting" />';

        // Cohort Group dropdown (for comparison reporting)
        echo '<div style="flex: 1; min-width: 160px;">';
        echo '<label for="group_id" style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px; color: #1e1e1e;">' . esc_html__('Cohort Group', 'hl-core') . '</label>';
        echo '<select name="group_id" id="group_id" style="width: 100%;">';
        echo '<option value="">' . esc_html__('-- No Group --', 'hl-core') . '</option>';
        foreach ($cohort_groups as $cg) {
            echo '<option value="' . esc_attr($cg['group_id']) . '"' . selected($filters['group_id'], $cg['group_id'], false) . '>' . esc_html($cg['group_name']) . '</option>';
        }
        echo '</select>';
        echo '</div>';

        // Cohort dropdown (required)
        echo '<div style="flex: 1; min-width: 160px;">';
        echo '<label for="cohort_id" style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px; color: #1e1e1e;">' . esc_html__('Cohort', 'hl-core') . ' <span style="color: #d63638;">*</span></label>';
        echo '<select name="cohort_id" id="cohort_id" style="width: 100%;">';
        echo '<option value="">' . esc_html__('-- Select Cohort --', 'hl-core') . '</option>';
        foreach ($cohorts as $cohort) {
            echo '<option value="' . esc_attr($cohort->cohort_id) . '"' . selected($filters['cohort_id'], $cohort->cohort_id, false) . '>';
            echo esc_html($cohort->cohort_name);
            if ($cohort->cohort_code) {
                echo ' (' . esc_html($cohort->cohort_code) . ')';
            }
            echo '</option>';
        }
        echo '</select>';
        echo '</div>';

        // District dropdown (optional)
        echo '<div style="flex: 1; min-width: 140px;">';
        echo '<label for="district_id" style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px; color: #1e1e1e;">' . esc_html__('District', 'hl-core') . '</label>';
        echo '<select name="district_id" id="district_id" style="width: 100%;">';
        echo '<option value="">' . esc_html__('All Districts', 'hl-core') . '</option>';
        foreach ($districts as $district) {
            echo '<option value="' . esc_attr($district->orgunit_id) . '"' . selected($filters['district_id'], $district->orgunit_id, false) . '>' . esc_html($district->name) . '</option>';
        }
        echo '</select>';
        echo '</div>';

        // School dropdown (optional)
        echo '<div style="flex: 1; min-width: 140px;">';
        echo '<label for="school_id" style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px; color: #1e1e1e;">' . esc_html__('School', 'hl-core') . '</label>';
        echo '<select name="school_id" id="school_id" style="width: 100%;">';
        echo '<option value="">' . esc_html__('All Schools', 'hl-core') . '</option>';
        foreach ($schools as $school) {
            echo '<option value="' . esc_attr($school->orgunit_id) . '"' . selected($filters['school_id'], $school->orgunit_id, false) . '>' . esc_html($school->name) . '</option>';
        }
        echo '</select>';
        echo '</div>';

        // Team dropdown (optional, only when cohort selected)
        echo '<div style="flex: 1; min-width: 140px;">';
        echo '<label for="team_id" style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px; color: #1e1e1e;">' . esc_html__('Team', 'hl-core') . '</label>';
        echo '<select name="team_id" id="team_id" style="width: 100%;">';
        echo '<option value="">' . esc_html__('All Teams', 'hl-core') . '</option>';
        foreach ($teams as $team) {
            echo '<option value="' . esc_attr($team->team_id) . '"' . selected($filters['team_id'], $team->team_id, false) . '>' . esc_html($team->team_name) . '</option>';
        }
        echo '</select>';
        echo '</div>';

        // Role dropdown (optional)
        echo '<div style="flex: 1; min-width: 120px;">';
        echo '<label for="role" style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px; color: #1e1e1e;">' . esc_html__('Role', 'hl-core') . '</label>';
        echo '<select name="role" id="role" style="width: 100%;">';
        echo '<option value="">' . esc_html__('All Roles', 'hl-core') . '</option>';
        foreach ($roles as $role) {
            echo '<option value="' . esc_attr($role) . '"' . selected($filters['role'], $role, false) . '>' . esc_html($role) . '</option>';
        }
        echo '</select>';
        echo '</div>';

        // Buttons
        echo '<div style="display: flex; gap: 6px; align-items: flex-end;">';

        // Filter button
        echo '<button type="submit" class="button button-primary">' . esc_html__('Filter', 'hl-core') . '</button>';

        echo '</div>';
        echo '</form>';

        // Action buttons (separate from filter form)
        if ($filters['cohort_id']) {
            echo '<div style="display: flex; gap: 8px; margin-top: 10px; padding-top: 10px; border-top: 1px solid #eee;">';

            // Export CSV button
            $export_url = $this->page_url(array_merge($filters, array('export' => 'completion_csv')));
            echo '<a href="' . esc_url($export_url) . '" class="button">';
            echo esc_html__('Export CSV', 'hl-core');
            echo '</a>';

            // Recompute Rollups button (admin only)
            if (current_user_can('manage_options')) {
                $recompute_url = wp_nonce_url(
                    $this->page_url(array(
                        'cohort_id' => $filters['cohort_id'],
                        'action'    => 'recompute',
                    )),
                    'hl_recompute_rollups_' . $filters['cohort_id']
                );
                echo '<a href="' . esc_url($recompute_url) . '" class="button" onclick="return confirm(\'' . esc_js(__('Recompute all rollups for this cohort? This may take a moment.', 'hl-core')) . '\');">';
                echo esc_html__('Recompute Rollups', 'hl-core');
                echo '</a>';
            }

            echo '</div>';
        }

        echo '</div>';
    }

    // =========================================================================
    // Summary Cards
    // =========================================================================

    /**
     * Render summary metric cards
     *
     * @param HL_Reporting_Service $reporting
     * @param array                $filters
     */
    private function render_summary_cards($reporting, $filters) {
        $cohort_id = $filters['cohort_id'];
        $summary   = $reporting->get_cohort_summary($cohort_id);

        // Get school count from filtered data
        $school_count = $this->get_school_count($filters);
        $team_count   = $this->get_team_count($filters);

        echo '<div class="hl-metrics-row">';

        // Total Active Enrollments
        echo '<div class="hl-metric-card">';
        echo '<div class="metric-value">' . esc_html(number_format_i18n($summary['total_enrollments'])) . '</div>';
        echo '<div class="metric-label">' . esc_html__('Active Enrollments', 'hl-core') . '</div>';
        echo '</div>';

        // Average Completion %
        echo '<div class="hl-metric-card">';
        echo '<div class="metric-value">' . esc_html(number_format($summary['avg_completion_percent'], 1)) . '%</div>';
        echo '<div class="metric-label">' . esc_html__('Avg Completion', 'hl-core') . '</div>';
        echo '</div>';

        // Schools
        echo '<div class="hl-metric-card">';
        echo '<div class="metric-value">' . esc_html(number_format_i18n($school_count)) . '</div>';
        echo '<div class="metric-label">' . esc_html__('Schools', 'hl-core') . '</div>';
        echo '</div>';

        // Teams
        echo '<div class="hl-metric-card">';
        echo '<div class="metric-value">' . esc_html(number_format_i18n($team_count)) . '</div>';
        echo '<div class="metric-label">' . esc_html__('Teams', 'hl-core') . '</div>';
        echo '</div>';

        echo '</div>';
    }

    /**
     * Get count of schools for the current filter scope
     *
     * @param array $filters
     * @return int
     */
    private function get_school_count($filters) {
        global $wpdb;

        if ($filters['school_id']) {
            return 1;
        }

        // Count schools linked to this cohort
        $sql = "SELECT COUNT(DISTINCT cc.school_id)
                FROM {$wpdb->prefix}hl_cohort_school cc";

        $where  = array('cc.cohort_id = %d');
        $values = array($filters['cohort_id']);

        if ($filters['district_id']) {
            $sql .= " JOIN {$wpdb->prefix}hl_orgunit o ON cc.school_id = o.orgunit_id";
            $where[]  = 'o.parent_orgunit_id = %d';
            $values[] = $filters['district_id'];
        }

        $sql .= ' WHERE ' . implode(' AND ', $where);

        return (int) $wpdb->get_var($wpdb->prepare($sql, $values));
    }

    /**
     * Get count of teams for the current filter scope
     *
     * @param array $filters
     * @return int
     */
    private function get_team_count($filters) {
        global $wpdb;

        $sql    = "SELECT COUNT(*) FROM {$wpdb->prefix}hl_team";
        $where  = array('cohort_id = %d');
        $values = array($filters['cohort_id']);

        if ($filters['school_id']) {
            $where[]  = 'school_id = %d';
            $values[] = $filters['school_id'];
        }

        if ($filters['team_id']) {
            $where[]  = 'team_id = %d';
            $values[] = $filters['team_id'];
            return 1;
        }

        $sql .= ' WHERE ' . implode(' AND ', $where);

        return (int) $wpdb->get_var($wpdb->prepare($sql, $values));
    }

    // =========================================================================
    // Summary Tables (School / Team)
    // =========================================================================

    /**
     * Render scope-level summary tables
     *
     * Shows school summary when no specific school is selected;
     * shows team summary when a school is selected or team filter is active.
     *
     * @param HL_Reporting_Service $reporting
     * @param array                $filters
     */
    private function render_summary_tables($reporting, $filters) {
        $cohort_id = $filters['cohort_id'];

        if (!$filters['school_id'] && !$filters['team_id']) {
            // Show school summary
            $this->render_school_summary_table($reporting, $filters);
        } else {
            // Show team summary
            $this->render_team_summary_table($reporting, $filters);
        }
    }

    /**
     * Render school summary table
     *
     * @param HL_Reporting_Service $reporting
     * @param array                $filters
     */
    private function render_school_summary_table($reporting, $filters) {
        $cohort_id = $filters['cohort_id'];
        $schools   = $reporting->get_school_summary($cohort_id, $filters['district_id']);

        echo '<div style="margin-bottom: 20px;">';
        echo '<h2 style="display: inline-block; margin-right: 10px;">' . esc_html__('School Summary', 'hl-core') . '</h2>';

        // School summary CSV export
        $export_url = $this->page_url(array_merge($filters, array('export' => 'school_summary_csv')));
        echo '<a href="' . esc_url($export_url) . '" class="button button-small" style="vertical-align: middle;">' . esc_html__('Export CSV', 'hl-core') . '</a>';
        echo '</div>';

        if (empty($schools)) {
            echo '<p>' . esc_html__('No school data available for this cohort.', 'hl-core') . '</p>';
            return;
        }

        echo '<table class="widefat striped" style="margin-bottom: 30px;">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('School Name', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Participants', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Avg Completion %', 'hl-core') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($schools as $school) {
            $school_name      = isset($school['school_name']) ? $school['school_name'] : __('Unknown', 'hl-core');
            $participant_count = isset($school['participant_count']) ? $school['participant_count'] : 0;
            $avg_percent       = isset($school['avg_completion_percent']) ? floatval($school['avg_completion_percent']) : 0;

            // Link to filter by this school
            $school_url = $this->page_url(array(
                'cohort_id' => $filters['cohort_id'],
                'school_id' => isset($school['school_id']) ? $school['school_id'] : 0,
            ));

            echo '<tr>';
            echo '<td><a href="' . esc_url($school_url) . '">' . esc_html($school_name) . '</a></td>';
            echo '<td>' . esc_html($participant_count) . '</td>';
            echo '<td>' . $this->render_progress_bar($avg_percent) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * Render team summary table
     *
     * @param HL_Reporting_Service $reporting
     * @param array                $filters
     */
    private function render_team_summary_table($reporting, $filters) {
        $cohort_id = $filters['cohort_id'];
        $school_id = $filters['school_id'];
        $teams     = $reporting->get_team_summary($cohort_id, $school_id);

        echo '<div style="margin-bottom: 20px;">';
        echo '<h2 style="display: inline-block; margin-right: 10px;">' . esc_html__('Team Summary', 'hl-core') . '</h2>';

        // Team summary CSV export
        $export_url = $this->page_url(array_merge($filters, array('export' => 'team_summary_csv')));
        echo '<a href="' . esc_url($export_url) . '" class="button button-small" style="vertical-align: middle;">' . esc_html__('Export CSV', 'hl-core') . '</a>';
        echo '</div>';

        if (empty($teams)) {
            echo '<p>' . esc_html__('No team data available for the selected scope.', 'hl-core') . '</p>';
            return;
        }

        echo '<table class="widefat striped" style="margin-bottom: 30px;">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Team Name', 'hl-core') . '</th>';
        if (!$school_id) {
            echo '<th>' . esc_html__('School', 'hl-core') . '</th>';
        }
        echo '<th>' . esc_html__('Members', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Avg Completion %', 'hl-core') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($teams as $team) {
            $team_name    = isset($team['team_name']) ? $team['team_name'] : __('Unknown', 'hl-core');
            $school_name  = isset($team['school_name']) ? $team['school_name'] : '';
            $member_count = isset($team['member_count']) ? $team['member_count'] : 0;
            $avg_percent  = isset($team['avg_completion_percent']) ? floatval($team['avg_completion_percent']) : 0;

            // Link to filter by this team
            $team_url = $this->page_url(array(
                'cohort_id' => $filters['cohort_id'],
                'school_id' => $filters['school_id'],
                'team_id'   => isset($team['team_id']) ? $team['team_id'] : 0,
            ));

            echo '<tr>';
            echo '<td><a href="' . esc_url($team_url) . '">' . esc_html($team_name) . '</a></td>';
            if (!$school_id) {
                echo '<td>' . esc_html($school_name) . '</td>';
            }
            echo '<td>' . esc_html($member_count) . '</td>';
            echo '<td>' . $this->render_progress_bar($avg_percent) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    // =========================================================================
    // Participant Table
    // =========================================================================

    /**
     * Render the participant completion table
     *
     * @param HL_Reporting_Service $reporting
     * @param array                $filters
     */
    private function render_participant_table($reporting, $filters) {
        $participants = $reporting->get_participant_report($filters);

        echo '<h2>' . esc_html__('Participants', 'hl-core') . '</h2>';

        if (empty($participants)) {
            echo '<p>' . esc_html__('No participants found for the selected filters.', 'hl-core') . '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Name', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Email', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Role(s)', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('School', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Team', 'hl-core') . '</th>';
        echo '<th style="min-width: 180px;">' . esc_html__('Completion %', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Actions', 'hl-core') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($participants as $p) {
            $display_name = isset($p['display_name']) ? $p['display_name'] : '';
            $user_email   = isset($p['user_email'])   ? $p['user_email']   : '';
            $roles_raw    = isset($p['roles'])         ? $p['roles']        : '';
            $school_name  = isset($p['school_name'])   ? $p['school_name'] : '';
            $team_name    = isset($p['team_name'])     ? $p['team_name']   : '';
            $completion   = isset($p['cohort_completion_percent']) ? floatval($p['cohort_completion_percent']) : 0;
            $enrollment_id = isset($p['enrollment_id']) ? absint($p['enrollment_id']) : 0;

            // Decode roles (stored as JSON array)
            $roles_display = $this->format_roles($roles_raw);

            // Build detail URL
            $detail_url = $this->page_url(array_merge($filters, array('enrollment_id' => $enrollment_id)));

            echo '<tr>';
            echo '<td><strong>' . esc_html($display_name) . '</strong></td>';
            echo '<td>' . esc_html($user_email) . '</td>';
            echo '<td>' . $roles_display . '</td>';
            echo '<td>' . esc_html($school_name) . '</td>';
            echo '<td>' . esc_html($team_name) . '</td>';
            echo '<td>' . $this->render_progress_bar($completion) . '</td>';
            echo '<td><a href="' . esc_url($detail_url) . '" class="button button-small">' . esc_html__('View Detail', 'hl-core') . '</a></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    // =========================================================================
    // Enrollment Detail (Drill-Down)
    // =========================================================================

    /**
     * Render the activity detail drill-down for a specific enrollment
     *
     * @param int   $enrollment_id
     * @param array $filters
     */
    private function render_enrollment_detail($enrollment_id, $filters) {
        global $wpdb;

        // Get enrollment info
        $enrollment = $wpdb->get_row($wpdb->prepare(
            "SELECT e.*, u.display_name, u.user_email, c.cohort_name, c.cohort_code
             FROM {$wpdb->prefix}hl_enrollment e
             LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
             LEFT JOIN {$wpdb->prefix}hl_cohort c ON e.cohort_id = c.cohort_id
             WHERE e.enrollment_id = %d",
            $enrollment_id
        ), ARRAY_A);

        if (!$enrollment) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Enrollment not found.', 'hl-core') . '</p></div>';
            return;
        }

        // Back link
        $back_url = $this->page_url($filters);
        echo '<p><a href="' . esc_url($back_url) . '">&larr; ' . esc_html__('Back to Report', 'hl-core') . '</a></p>';

        echo '<h1>' . esc_html__('Participant Detail', 'hl-core') . '</h1>';

        // Enrollment header
        $reporting  = HL_Reporting_Service::instance();
        $completion = $reporting->get_enrollment_completion($enrollment_id);
        $roles_display = $this->format_roles(isset($enrollment['roles']) ? $enrollment['roles'] : '');

        echo '<div style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px; margin-bottom: 20px;">';
        echo '<table class="form-table" style="margin: 0;">';

        echo '<tr><th>' . esc_html__('Name', 'hl-core') . '</th>';
        echo '<td><strong>' . esc_html($enrollment['display_name']) . '</strong></td></tr>';

        echo '<tr><th>' . esc_html__('Email', 'hl-core') . '</th>';
        echo '<td>' . esc_html($enrollment['user_email']) . '</td></tr>';

        echo '<tr><th>' . esc_html__('Cohort', 'hl-core') . '</th>';
        echo '<td>' . esc_html($enrollment['cohort_name']);
        if (!empty($enrollment['cohort_code'])) {
            echo ' <code>' . esc_html($enrollment['cohort_code']) . '</code>';
        }
        echo '</td></tr>';

        echo '<tr><th>' . esc_html__('Role(s)', 'hl-core') . '</th>';
        echo '<td>' . $roles_display . '</td></tr>';

        echo '<tr><th>' . esc_html__('Overall Completion', 'hl-core') . '</th>';
        echo '<td>' . $this->render_progress_bar($completion) . '</td></tr>';

        echo '</table>';
        echo '</div>';

        // Activity table
        $activities = $reporting->get_activity_states($enrollment_id);

        echo '<h2>' . esc_html__('Activities', 'hl-core') . '</h2>';

        if (empty($activities)) {
            echo '<p>' . esc_html__('No activity data available for this enrollment.', 'hl-core') . '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Title', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Type', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Weight', 'hl-core') . '</th>';
        echo '<th style="min-width: 180px;">' . esc_html__('Completion %', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Status', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Completed At', 'hl-core') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($activities as $activity) {
            $title     = isset($activity['title'])             ? $activity['title']             : __('Untitled', 'hl-core');
            $type      = isset($activity['activity_type'])     ? $activity['activity_type']     : '';
            $weight    = isset($activity['weight'])            ? floatval($activity['weight'])  : 1;
            $percent   = isset($activity['completion_percent']) ? floatval($activity['completion_percent']) : 0;
            $status    = isset($activity['completion_status']) ? $activity['completion_status'] : 'not_started';
            $completed = isset($activity['completed_at'])      ? $activity['completed_at']     : '';

            // Format activity type for display
            $type_display = $this->format_activity_type($type);

            // Status badge
            $status_badge = $this->render_status_badge($status);

            echo '<tr>';
            echo '<td><strong>' . esc_html($title) . '</strong></td>';
            echo '<td>' . esc_html($type_display) . '</td>';
            echo '<td>' . esc_html(number_format($weight, 1)) . '</td>';
            echo '<td>' . $this->render_progress_bar($percent) . '</td>';
            echo '<td>' . $status_badge . '</td>';
            echo '<td>' . esc_html($completed ? $completed : '---') . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    // =========================================================================
    // Assessment Exports Section
    // =========================================================================

    /**
     * Render the assessment exports section (staff only)
     *
     * @param array $filters
     */
    private function render_assessment_exports($filters) {
        if (!$filters['cohort_id']) {
            return;
        }

        // Only staff can see assessment response exports
        if (!current_user_can('manage_hl_core')) {
            return;
        }

        echo '<div style="background: #f9f9f9; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px; margin-top: 30px;">';
        echo '<h3 style="margin-top: 0;">' . esc_html__('Assessment Exports (Staff Only)', 'hl-core') . '</h3>';
        echo '<p class="description" style="margin-bottom: 15px;">' . esc_html__('Export detailed assessment response data. These exports include question-level responses and are restricted to staff users.', 'hl-core') . '</p>';

        echo '<div style="display: flex; gap: 10px; flex-wrap: wrap;">';

        // Teacher Self-Assessment export
        $teacher_export_url = $this->page_url(array_merge($filters, array('export' => 'teacher_assessment_csv')));
        echo '<a href="' . esc_url($teacher_export_url) . '" class="button">';
        echo esc_html__('Export Teacher Self-Assessment Responses', 'hl-core');
        echo '</a>';

        // Child Assessment export
        $children_export_url = $this->page_url(array_merge($filters, array('export' => 'child_assessment_csv')));
        echo '<a href="' . esc_url($children_export_url) . '" class="button">';
        echo esc_html__('Export Child Assessment Responses', 'hl-core');
        echo '</a>';

        echo '</div>';
        echo '</div>';
    }

    // =========================================================================
    // Group Comparison (Program vs Control)
    // =========================================================================

    /**
     * Render the program vs control group comparison section.
     *
     * Only appears when a cohort group is selected that contains both
     * program (is_control_group=0) and control (is_control_group=1) cohorts.
     *
     * @param HL_Reporting_Service $reporting
     * @param array                $filters
     */
    private function render_group_comparison($reporting, $filters) {
        $group_id = isset($filters['group_id']) ? absint($filters['group_id']) : 0;
        if (!$group_id) {
            return;
        }

        $comparison = $reporting->get_group_assessment_comparison($group_id);
        if (!$comparison) {
            return;
        }

        // Get group name.
        global $wpdb;
        $group_name = $wpdb->get_var($wpdb->prepare(
            "SELECT group_name FROM {$wpdb->prefix}hl_cohort_group WHERE group_id = %d",
            $group_id
        ));

        echo '<div style="margin-top: 30px; border-top: 2px solid #9b59b6; padding-top: 20px;">';
        echo '<h2 style="color: #9b59b6;">' . esc_html(sprintf(
            __('Program vs Control Comparison — %s', 'hl-core'),
            $group_name ?: __('Group', 'hl-core')
        )) . '</h2>';

        // Info cards.
        echo '<div class="hl-metrics-row" style="margin-bottom: 20px;">';

        // Program cohorts.
        echo '<div class="hl-metric-card">';
        echo '<div class="metric-value">' . esc_html(count($comparison['program']['cohort_names'])) . '</div>';
        echo '<div class="metric-label">' . esc_html__('Program Cohorts', 'hl-core') . '</div>';
        echo '<div style="font-size:11px;color:#666;margin-top:4px;">' . esc_html(implode(', ', $comparison['program']['cohort_names'])) . '</div>';
        echo '</div>';

        echo '<div class="hl-metric-card">';
        echo '<div class="metric-value">' . esc_html($comparison['program']['participant_count']) . '</div>';
        echo '<div class="metric-label">' . esc_html__('Program Participants', 'hl-core') . '</div>';
        echo '</div>';

        // Control cohorts.
        echo '<div class="hl-metric-card" style="border-left: 3px solid #9b59b6;">';
        echo '<div class="metric-value">' . esc_html(count($comparison['control']['cohort_names'])) . '</div>';
        echo '<div class="metric-label">' . esc_html__('Control Cohorts', 'hl-core') . '</div>';
        echo '<div style="font-size:11px;color:#666;margin-top:4px;">' . esc_html(implode(', ', $comparison['control']['cohort_names'])) . '</div>';
        echo '</div>';

        echo '<div class="hl-metric-card" style="border-left: 3px solid #9b59b6;">';
        echo '<div class="metric-value">' . esc_html($comparison['control']['participant_count']) . '</div>';
        echo '<div class="metric-label">' . esc_html__('Control Participants', 'hl-core') . '</div>';
        echo '</div>';

        echo '</div>';

        // Per-section comparison tables.
        $program_sections = $comparison['program']['sections'];
        $control_sections = $comparison['control']['sections'];

        foreach ($program_sections as $section_key => $section) {
            $title = $section['title'];
            $items = isset($section['items']) ? $section['items'] : array();

            echo '<h3>' . esc_html($title) . '</h3>';
            echo '<table class="widefat striped" style="margin-bottom: 20px;">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__('Item', 'hl-core') . '</th>';
            echo '<th style="text-align:center;">' . esc_html__('Program Pre', 'hl-core') . '</th>';
            echo '<th style="text-align:center;">' . esc_html__('Program Post', 'hl-core') . '</th>';
            echo '<th style="text-align:center;">' . esc_html__('Program Change', 'hl-core') . '</th>';
            echo '<th style="text-align:center;">' . esc_html__('Control Pre', 'hl-core') . '</th>';
            echo '<th style="text-align:center;">' . esc_html__('Control Post', 'hl-core') . '</th>';
            echo '<th style="text-align:center;">' . esc_html__('Control Change', 'hl-core') . '</th>';
            echo '</tr></thead>';
            echo '<tbody>';

            $c_sec = isset($control_sections[$section_key]) ? $control_sections[$section_key] : null;

            foreach ($items as $item) {
                $item_key  = isset($item['key']) ? $item['key'] : '';
                $item_text = isset($item['text']) ? $item['text'] : $item_key;

                $p_pre  = isset($section['pre'][$item_key])  ? $section['pre'][$item_key]  : array('mean' => null, 'n' => 0);
                $p_post = isset($section['post'][$item_key]) ? $section['post'][$item_key] : array('mean' => null, 'n' => 0);

                $c_pre  = ($c_sec && isset($c_sec['pre'][$item_key]))  ? $c_sec['pre'][$item_key]  : array('mean' => null, 'n' => 0);
                $c_post = ($c_sec && isset($c_sec['post'][$item_key])) ? $c_sec['post'][$item_key] : array('mean' => null, 'n' => 0);

                $p_change = ($p_pre['mean'] !== null && $p_post['mean'] !== null) ? round($p_post['mean'] - $p_pre['mean'], 2) : null;
                $c_change = ($c_pre['mean'] !== null && $c_post['mean'] !== null) ? round($c_post['mean'] - $c_pre['mean'], 2) : null;

                echo '<tr>';
                echo '<td>' . esc_html($item_text) . '</td>';
                echo '<td style="text-align:center;">' . $this->format_mean($p_pre) . '</td>';
                echo '<td style="text-align:center;">' . $this->format_mean($p_post) . '</td>';
                echo '<td style="text-align:center;">' . $this->format_change($p_change) . '</td>';
                echo '<td style="text-align:center;">' . $this->format_mean($c_pre) . '</td>';
                echo '<td style="text-align:center;">' . $this->format_mean($c_post) . '</td>';
                echo '<td style="text-align:center;">' . $this->format_change($c_change) . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }

        // Export button.
        $export_url = $this->page_url(array('export' => 'comparison_csv', 'group_id' => $group_id));
        echo '<a href="' . esc_url($export_url) . '" class="button">';
        echo esc_html__('Export Comparison CSV', 'hl-core');
        echo '</a>';

        echo '</div>';
    }

    /**
     * Format a mean value with sample size for display.
     *
     * @param array $data Array with 'mean' and 'n' keys.
     * @return string HTML.
     */
    private function format_mean($data) {
        if (!isset($data['mean']) || $data['mean'] === null) {
            return '<span style="color:#999;">---</span>';
        }
        $n = isset($data['n']) ? (int) $data['n'] : 0;
        return esc_html(number_format($data['mean'], 2)) . ' <span style="font-size:11px;color:#888;">(n=' . esc_html($n) . ')</span>';
    }

    /**
     * Format a change value with color coding.
     *
     * @param float|null $change Change value.
     * @return string HTML.
     */
    private function format_change($change) {
        if ($change === null) {
            return '<span style="color:#999;">---</span>';
        }
        $color = '#666';
        $prefix = '';
        if ($change > 0) {
            $color = '#00a32a';
            $prefix = '+';
        } elseif ($change < 0) {
            $color = '#d63638';
        }
        return '<strong style="color:' . esc_attr($color) . ';">' . esc_html($prefix . number_format($change, 2)) . '</strong>';
    }

    // =========================================================================
    // Helper: Progress Bar
    // =========================================================================

    /**
     * Render an inline progress bar with percentage text
     *
     * @param float $percent 0-100
     * @return string HTML string (already escaped internally)
     */
    private function render_progress_bar($percent) {
        $percent = max(0, min(100, floatval($percent)));

        // Color: green for 100, blue for 50-99, orange for 1-49, gray for 0
        if ($percent >= 100) {
            $color = '#00a32a';
        } elseif ($percent >= 50) {
            $color = '#2271b1';
        } elseif ($percent > 0) {
            $color = '#dba617';
        } else {
            $color = '#ccd0d4';
        }

        $formatted = number_format($percent, 1);

        $html  = '<div style="display: flex; align-items: center; gap: 8px;">';
        $html .= '<div class="hl-progress-bar-container" style="flex: 1; background: #f0f0f0; border-radius: 3px; height: 18px; overflow: hidden; min-width: 80px;">';
        $html .= '<div class="hl-progress-bar" style="width: ' . esc_attr($formatted) . '%; background: ' . esc_attr($color) . '; height: 100%; border-radius: 3px; transition: width 0.3s;"></div>';
        $html .= '</div>';
        $html .= '<span style="font-size: 13px; font-weight: 600; min-width: 48px; text-align: right;">' . esc_html($formatted) . '%</span>';
        $html .= '</div>';

        return $html;
    }

    // =========================================================================
    // Helper: Format Roles
    // =========================================================================

    /**
     * Decode and format roles JSON for display
     *
     * @param string $roles_json JSON-encoded roles array, or comma-separated string
     * @return string HTML with role tags
     */
    private function format_roles($roles_json) {
        if (empty($roles_json)) {
            return '<span style="color: #999;">---</span>';
        }

        $roles = json_decode($roles_json, true);
        if (!is_array($roles)) {
            // Fallback: treat as plain string
            $roles = array_map('trim', explode(',', $roles_json));
        }

        $output = '';
        foreach ($roles as $role) {
            $role = trim($role);
            if ($role !== '') {
                $output .= '<span class="hl-role-tag">' . esc_html($role) . '</span> ';
            }
        }

        return $output ?: '<span style="color: #999;">---</span>';
    }

    // =========================================================================
    // Helper: Format Activity Type
    // =========================================================================

    /**
     * Format activity type enum value for display
     *
     * @param string $type
     * @return string
     */
    private function format_activity_type($type) {
        $labels = array(
            'learndash_course'             => __('LearnDash Course', 'hl-core'),
            'teacher_self_assessment'      => __('Teacher Self-Assessment', 'hl-core'),
            'child_assessment'          => __('Child Assessment', 'hl-core'),
            'coaching_session_attendance'  => __('Coaching Attendance', 'hl-core'),
            'observation'                  => __('Observation', 'hl-core'),
        );

        return isset($labels[$type]) ? $labels[$type] : ucwords(str_replace('_', ' ', $type));
    }

    // =========================================================================
    // Helper: Status Badge
    // =========================================================================

    /**
     * Render a status badge
     *
     * @param string $status
     * @return string HTML
     */
    private function render_status_badge($status) {
        $class = 'hl-status-badge ';

        switch ($status) {
            case 'complete':
                $class .= 'active';
                $label  = __('Complete', 'hl-core');
                break;
            case 'in_progress':
                $class .= 'paused';
                $label  = __('In Progress', 'hl-core');
                break;
            case 'not_started':
            default:
                $class .= 'draft';
                $label  = __('Not Started', 'hl-core');
                break;
        }

        return '<span class="' . esc_attr($class) . '">' . esc_html($label) . '</span>';
    }
}
