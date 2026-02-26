<?php if (!defined('ABSPATH')) exit;

/**
 * Admin Imports Page
 *
 * AJAX-based import wizard supporting multiple import types:
 * participants, children, classrooms, and teaching assignments.
 *
 * @package HL_Core
 */
class HL_Admin_Imports {

    /** @var HL_Admin_Imports|null */
    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_ajax_hl_import_upload', array($this, 'ajax_upload'));
        add_action('wp_ajax_hl_import_commit', array($this, 'ajax_commit'));
        add_action('wp_ajax_hl_import_error_report', array($this, 'ajax_error_report'));
    }

    /**
     * Render the imports admin page
     */
    public function render_page() {
        global $wpdb;

        // Get non-archived tracks for dropdown
        $tracks = $wpdb->get_results(
            "SELECT track_id, track_name FROM {$wpdb->prefix}hl_track WHERE status != 'archived' ORDER BY track_name ASC"
        );

        $import_service = new HL_Import_Service();
        $runs = $import_service->get_runs();

        ?>
        <div class="wrap hl-admin-wrap hl-import-wizard-wrap">
            <h1><?php esc_html_e('Imports', 'hl-core'); ?></h1>

            <!-- Step Indicator -->
            <div class="hl-import-steps">
                <div class="hl-import-step active" data-step="1">
                    <span class="step-number">1</span>
                    <span class="step-label"><?php esc_html_e('Upload', 'hl-core'); ?></span>
                </div>
                <div class="hl-import-step-divider"></div>
                <div class="hl-import-step" data-step="2">
                    <span class="step-number">2</span>
                    <span class="step-label"><?php esc_html_e('Preview & Select', 'hl-core'); ?></span>
                </div>
                <div class="hl-import-step-divider"></div>
                <div class="hl-import-step" data-step="3">
                    <span class="step-number">3</span>
                    <span class="step-label"><?php esc_html_e('Results', 'hl-core'); ?></span>
                </div>
            </div>

            <!-- Spinner Overlay -->
            <div class="hl-import-spinner" style="display:none;">
                <div class="hl-import-spinner-inner">
                    <span class="spinner is-active"></span>
                    <p class="hl-import-spinner-msg"><?php esc_html_e('Processing...', 'hl-core'); ?></p>
                </div>
            </div>

            <!-- Notice Area -->
            <div class="hl-import-notices"></div>

            <!-- Step 1: Upload -->
            <div class="hl-import-panel" id="hl-import-step-1">
                <h2><?php esc_html_e('Upload CSV', 'hl-core'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="hl-import-track"><?php esc_html_e('Track', 'hl-core'); ?></label></th>
                        <td>
                            <select id="hl-import-track" required>
                                <option value=""><?php esc_html_e('-- Select Track --', 'hl-core'); ?></option>
                                <?php if ($tracks) : foreach ($tracks as $coh) : ?>
                                    <option value="<?php echo esc_attr($coh->track_id); ?>"><?php echo esc_html($coh->track_name); ?></option>
                                <?php endforeach; endif; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hl-import-type"><?php esc_html_e('Import Type', 'hl-core'); ?></label></th>
                        <td>
                            <select id="hl-import-type" required>
                                <option value="participants"><?php esc_html_e('Participants', 'hl-core'); ?></option>
                                <option value="children"><?php esc_html_e('Children', 'hl-core'); ?></option>
                                <option value="classrooms"><?php esc_html_e('Classrooms', 'hl-core'); ?></option>
                                <option value="teaching_assignments"><?php esc_html_e('Teaching Assignments', 'hl-core'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hl-import-file"><?php esc_html_e('CSV File', 'hl-core'); ?></label></th>
                        <td>
                            <input type="file" id="hl-import-file" accept=".csv" required />
                            <p class="description"><?php esc_html_e('Max 2MB. Maximum 5,000 rows.', 'hl-core'); ?></p>
                        </td>
                    </tr>
                </table>
                <p>
                    <button type="button" class="button button-primary" id="hl-import-upload-btn">
                        <?php esc_html_e('Upload & Preview', 'hl-core'); ?>
                    </button>
                </p>
                <div id="hl-import-column-hints">
                    <p class="description" data-type="participants">
                        <?php esc_html_e('Required columns: email, track_roles, school_name (or school_code). Optional: first_name, last_name, district_name, district_code.', 'hl-core'); ?>
                    </p>
                    <p class="description" data-type="children" style="display:none;">
                        <?php esc_html_e('Required columns: first_name (and/or last_name), school_name (or school_code). Optional: date_of_birth, child_identifier, classroom_name.', 'hl-core'); ?>
                    </p>
                    <p class="description" data-type="classrooms" style="display:none;">
                        <?php esc_html_e('Required columns: classroom_name, school_name (or school_code). Optional: age_band (infant/toddler/preschool/mixed).', 'hl-core'); ?>
                    </p>
                    <p class="description" data-type="teaching_assignments" style="display:none;">
                        <?php esc_html_e('Required columns: email, classroom_name, school_name (or school_code). Optional: is_lead_teacher (yes/no).', 'hl-core'); ?>
                    </p>
                </div>
            </div>

            <!-- Step 2: Preview & Select -->
            <div class="hl-import-panel" id="hl-import-step-2" style="display:none;">
                <h2><?php esc_html_e('Preview & Select Rows', 'hl-core'); ?></h2>
                <div class="hl-import-summary" id="hl-import-summary"></div>
                <div class="hl-import-bulk-bar">
                    <label><input type="checkbox" id="hl-import-select-all" /> <?php esc_html_e('Select All', 'hl-core'); ?></label>
                    <span class="hl-import-bulk-actions">
                        <button type="button" class="button button-small" data-bulk="create"><?php esc_html_e('Select All CREATE', 'hl-core'); ?></button>
                        <button type="button" class="button button-small" data-bulk="update"><?php esc_html_e('Select All UPDATE', 'hl-core'); ?></button>
                        <button type="button" class="button button-small" data-bulk="none"><?php esc_html_e('Deselect All', 'hl-core'); ?></button>
                    </span>
                    <span class="hl-import-selection-count" id="hl-import-selection-count"></span>
                </div>
                <div class="hl-import-table-wrap">
                    <table class="widefat striped" id="hl-import-preview-table">
                        <thead><tr></tr></thead>
                        <tbody></tbody>
                    </table>
                </div>
                <p class="hl-import-actions">
                    <button type="button" class="button button-primary" id="hl-import-commit-btn">
                        <?php esc_html_e('Commit Import', 'hl-core'); ?>
                    </button>
                    <button type="button" class="button" id="hl-import-cancel-btn">
                        <?php esc_html_e('Cancel', 'hl-core'); ?>
                    </button>
                </p>
            </div>

            <!-- Step 3: Results -->
            <div class="hl-import-panel" id="hl-import-step-3" style="display:none;">
                <h2><?php esc_html_e('Import Results', 'hl-core'); ?></h2>
                <div class="hl-import-results" id="hl-import-results"></div>
                <div class="hl-import-error-list" id="hl-import-error-list"></div>
                <p class="hl-import-actions">
                    <button type="button" class="button" id="hl-import-download-errors-btn" style="display:none;">
                        <?php esc_html_e('Download Error Report', 'hl-core'); ?>
                    </button>
                    <button type="button" class="button button-primary" id="hl-import-new-btn">
                        <?php esc_html_e('New Import', 'hl-core'); ?>
                    </button>
                </p>
            </div>

            <!-- Import History -->
            <hr />
            <h2><?php esc_html_e('Import History', 'hl-core'); ?></h2>
            <?php $this->render_history_table($runs); ?>
        </div>
        <?php
    }

    /**
     * Render import history table
     */
    private function render_history_table($runs) {
        if (empty($runs)) {
            echo '<p>' . esc_html__('No import runs yet.', 'hl-core') . '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('ID', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Date', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Track', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('File', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Type', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Status', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Actor', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Summary', 'hl-core') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($runs as $run) {
            $status_class = '';
            switch ($run['status']) {
                case 'committed': $status_class = 'active'; break;
                case 'preview':   $status_class = 'draft'; break;
                case 'failed':    $status_class = 'archived'; break;
            }

            $summary_text = '';
            if (!empty($run['results_summary'])) {
                $s = is_string($run['results_summary']) ? json_decode($run['results_summary'], true) : $run['results_summary'];
                if (is_array($s)) {
                    $summary_text = sprintf(
                        '%d created, %d updated, %d skipped, %d errors',
                        isset($s['created_count']) ? $s['created_count'] : 0,
                        isset($s['updated_count']) ? $s['updated_count'] : 0,
                        isset($s['skipped_count']) ? $s['skipped_count'] : 0,
                        isset($s['error_count']) ? $s['error_count'] : 0
                    );
                }
            }

            echo '<tr>';
            echo '<td>' . esc_html($run['run_id']) . '</td>';
            echo '<td>' . esc_html($run['created_at']) . '</td>';
            echo '<td>' . esc_html($run['track_name'] ? $run['track_name'] : '-') . '</td>';
            echo '<td>' . esc_html($run['file_name']) . '</td>';
            echo '<td>' . esc_html($run['import_type']) . '</td>';
            echo '<td><span class="hl-status-badge ' . esc_attr($status_class) . '">' . esc_html($run['status']) . '</span></td>';
            echo '<td>' . esc_html($run['actor_name'] ? $run['actor_name'] : '-') . '</td>';
            echo '<td>' . esc_html($summary_text) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    // =========================================================================
    // AJAX Handlers
    // =========================================================================

    /**
     * AJAX: Upload and parse CSV, create preview
     */
    public function ajax_upload() {
        check_ajax_referer('hl_import_upload', 'nonce');

        if (!current_user_can('manage_hl_core')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'hl-core')));
        }

        $track_id = isset($_POST['track_id']) ? absint($_POST['track_id']) : 0;
        if (!$track_id) {
            wp_send_json_error(array('message' => __('Please select a track.', 'hl-core')));
        }

        $import_type = isset($_POST['import_type']) ? sanitize_text_field($_POST['import_type']) : 'participants';
        $valid_types = array('participants', 'children', 'classrooms', 'teaching_assignments');
        if (!in_array($import_type, $valid_types)) {
            wp_send_json_error(array('message' => __('Invalid import type.', 'hl-core')));
        }

        // Verify track is not archived
        global $wpdb;
        $track_status = $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}hl_track WHERE track_id = %d",
            $track_id
        ));
        if ($track_status === 'archived') {
            wp_send_json_error(array('message' => __('Cannot import into an archived track.', 'hl-core')));
        }
        if (!$track_status) {
            wp_send_json_error(array('message' => __('Track not found.', 'hl-core')));
        }

        // Validate file upload
        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(array('message' => __('No file uploaded or upload error.', 'hl-core')));
        }

        $file = $_FILES['file'];

        // Check file size
        if ($file['size'] > HL_Import_Service::MAX_FILE_SIZE) {
            wp_send_json_error(array('message' => __('File exceeds the 2MB size limit.', 'hl-core')));
        }

        // Check extension
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            wp_send_json_error(array('message' => __('Only CSV files are supported.', 'hl-core')));
        }

        // Check MIME type
        $allowed_mimes = array('text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel');
        if (!in_array($file['type'], $allowed_mimes)) {
            wp_send_json_error(array('message' => __('Invalid file type. Please upload a CSV file.', 'hl-core')));
        }

        $import_service = new HL_Import_Service();

        // Parse CSV
        $parsed = $import_service->parse_csv($file['tmp_name']);
        if (is_wp_error($parsed)) {
            wp_send_json_error(array('message' => $parsed->get_error_message()));
        }

        // Validate rows based on import type
        switch ($import_type) {
            case 'children':
                $preview_rows = $import_service->validate_children_rows($parsed['rows'], $track_id);
                break;
            case 'classrooms':
                $preview_rows = $import_service->validate_classroom_rows($parsed['rows'], $track_id);
                break;
            case 'teaching_assignments':
                $preview_rows = $import_service->validate_teaching_assignment_rows($parsed['rows'], $track_id);
                break;
            case 'participants':
            default:
                $preview_rows = $import_service->validate_participant_rows($parsed['rows'], $track_id);
                break;
        }

        // Create import run
        $run_id = $import_service->create_run($track_id, $import_type, $file['name']);
        if (!$run_id) {
            wp_send_json_error(array('message' => __('Failed to create import run record.', 'hl-core')));
        }

        // Save preview
        $import_service->save_preview($run_id, $preview_rows);

        // Delete temp file
        @unlink($file['tmp_name']);

        // Build summary counts
        $counts = array('CREATE' => 0, 'UPDATE' => 0, 'SKIP' => 0, 'NEEDS_REVIEW' => 0, 'ERROR' => 0);
        foreach ($preview_rows as $row) {
            if (isset($counts[$row['status']])) {
                $counts[$row['status']]++;
            }
        }

        // Prepare rows for JS based on import type
        $js_rows = $this->prepare_js_rows($preview_rows, $import_type);

        wp_send_json_success(array(
            'run_id'      => $run_id,
            'import_type' => $import_type,
            'counts'      => $counts,
            'unmapped'    => $parsed['unmapped'],
            'rows'        => $js_rows,
            'total_rows'  => count($js_rows),
        ));
    }

    /**
     * Prepare preview rows for JS depending on import type
     *
     * @param array  $preview_rows
     * @param string $import_type
     * @return array
     */
    private function prepare_js_rows($preview_rows, $import_type) {
        $js_rows = array();

        foreach ($preview_rows as $row) {
            $base = array(
                'row_index'           => $row['row_index'],
                'status'              => $row['status'],
                'selected'            => $row['selected'],
                'validation_messages' => $row['validation_messages'],
                'proposed_actions'    => $row['proposed_actions'],
            );

            switch ($import_type) {
                case 'children':
                    $base['parsed_first_name']   = isset($row['parsed_first_name']) ? $row['parsed_first_name'] : '';
                    $base['parsed_last_name']    = isset($row['parsed_last_name']) ? $row['parsed_last_name'] : '';
                    $base['parsed_dob']          = isset($row['parsed_dob']) ? $row['parsed_dob'] : '';
                    $base['parsed_child_identifier'] = isset($row['parsed_child_identifier']) ? $row['parsed_child_identifier'] : '';
                    $base['parsed_classroom_name']   = isset($row['parsed_classroom_name']) ? $row['parsed_classroom_name'] : '';
                    $base['raw_school'] = isset($row['raw_data']['school_name'])
                        ? $row['raw_data']['school_name']
                        : (isset($row['raw_data']['school_code']) ? $row['raw_data']['school_code'] : '');
                    break;

                case 'classrooms':
                    $base['parsed_classroom_name'] = isset($row['parsed_classroom_name']) ? $row['parsed_classroom_name'] : '';
                    $base['parsed_age_band']       = isset($row['parsed_age_band']) ? $row['parsed_age_band'] : '';
                    $base['raw_school'] = isset($row['raw_data']['school_name'])
                        ? $row['raw_data']['school_name']
                        : (isset($row['raw_data']['school_code']) ? $row['raw_data']['school_code'] : '');
                    break;

                case 'teaching_assignments':
                    $base['parsed_email']          = isset($row['parsed_email']) ? $row['parsed_email'] : '';
                    $base['parsed_classroom_name'] = isset($row['parsed_classroom_name']) ? $row['parsed_classroom_name'] : '';
                    $base['parsed_is_lead']        = isset($row['parsed_is_lead']) ? $row['parsed_is_lead'] : false;
                    $base['raw_school'] = isset($row['raw_data']['school_name'])
                        ? $row['raw_data']['school_name']
                        : (isset($row['raw_data']['school_code']) ? $row['raw_data']['school_code'] : '');
                    break;

                case 'participants':
                default:
                    $base['parsed_email']      = isset($row['parsed_email']) ? $row['parsed_email'] : '';
                    $base['parsed_first_name'] = isset($row['parsed_first_name']) ? $row['parsed_first_name'] : '';
                    $base['parsed_last_name']  = isset($row['parsed_last_name']) ? $row['parsed_last_name'] : '';
                    $base['parsed_roles']      = isset($row['parsed_roles']) ? $row['parsed_roles'] : array();
                    $base['raw_school'] = isset($row['raw_data']['school_name'])
                        ? $row['raw_data']['school_name']
                        : (isset($row['raw_data']['school_code']) ? $row['raw_data']['school_code'] : '');
                    $base['raw_district'] = isset($row['raw_data']['district_name'])
                        ? $row['raw_data']['district_name']
                        : (isset($row['raw_data']['district_code']) ? $row['raw_data']['district_code'] : '');
                    break;
            }

            $js_rows[] = $base;
        }

        return $js_rows;
    }

    /**
     * AJAX: Commit selected rows
     */
    public function ajax_commit() {
        check_ajax_referer('hl_import_commit', 'nonce');

        if (!current_user_can('manage_hl_core')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'hl-core')));
        }

        $run_id = isset($_POST['run_id']) ? absint($_POST['run_id']) : 0;
        if (!$run_id) {
            wp_send_json_error(array('message' => __('Missing import run ID.', 'hl-core')));
        }

        // Verify run ownership and get import_type
        global $wpdb;
        $run_row = $wpdb->get_row($wpdb->prepare(
            "SELECT actor_user_id, import_type FROM {$wpdb->prefix}hl_import_run WHERE run_id = %d AND status = 'preview'",
            $run_id
        ));
        if (!$run_row) {
            wp_send_json_error(array('message' => __('Import run not found or already committed.', 'hl-core')));
        }
        if ((int) $run_row->actor_user_id !== get_current_user_id() && !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You can only commit your own import runs.', 'hl-core')));
        }

        $selected_raw = isset($_POST['selected_rows']) ? $_POST['selected_rows'] : array();
        $selected = array_map('absint', (array) $selected_raw);

        $import_service = new HL_Import_Service();

        // Route to the correct commit method based on import_type
        switch ($run_row->import_type) {
            case 'children':
                $results = $import_service->commit_children_import($run_id, $selected);
                break;
            case 'classrooms':
                $results = $import_service->commit_classroom_import($run_id, $selected);
                break;
            case 'teaching_assignments':
                $results = $import_service->commit_teaching_assignment_import($run_id, $selected);
                break;
            case 'participants':
            default:
                $results = $import_service->commit_import($run_id, $selected);
                break;
        }

        wp_send_json_success($results);
    }

    /**
     * AJAX: Generate and return error report URL
     */
    public function ajax_error_report() {
        check_ajax_referer('hl_import_error_report', 'nonce');

        if (!current_user_can('manage_hl_core')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'hl-core')));
        }

        $run_id = isset($_POST['run_id']) ? absint($_POST['run_id']) : 0;
        if (!$run_id) {
            wp_send_json_error(array('message' => __('Missing import run ID.', 'hl-core')));
        }

        $import_service = new HL_Import_Service();
        $result = $import_service->generate_error_report($run_id);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success(array('url' => $result));
    }
}
